<?php
/**
 * admin/sessions.php — Sessions & login security (29 Oct 2026, owner's
 * management-system prompt, Phase 1: "view all active sessions, force-logout
 * any user remotely", "login history: IP, device, time, success/fail — per
 * user", "reset failed login attempts").
 *
 * Three tabs on one screen:
 *   Live     — every signed-in browser (AdminSessions) and every WhatsApp
 *              staff sign-in (WaLogin). End one, or sign a person out
 *              everywhere. The browser is signed out on its next request
 *              (Auth::admin() re-checks every 30 s); the account itself is
 *              untouched, so they may simply sign in again.
 *   History  — admin_login_events (LoginLog), filtered by person / outcome.
 *   Person   — one account: lock state + Unlock, devices + Revoke, its
 *              sessions and its last sign-ins.
 *
 * Who: staff.manage (manager, operations, superadmin). A super-admin's
 * session, device or lock may only be touched by a super-admin. Every action
 * is CSRF-checked and written to audit_logs.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/walogin.php';
$admin = admin_boot('staff.manage');
$me    = (int) ($admin['id'] ?? 0);
$isSa  = Auth::isSuperadmin();
$e     = static fn($v): string => Security::e((string) $v);
$flash = null;

$tab = (string) ($_GET['tab'] ?? 'live');
if (!in_array($tab, ['live', 'history', 'person'], true)) {
    $tab = 'live';
}
$personId = (int) ($_GET['id'] ?? $_POST['person'] ?? 0);

/** May the signed-in person act on this account? */
$mayTouch = static function (?array $acct) use ($isSa): bool {
    return $acct !== null && ($isSa || (string) ($acct['role'] ?? '') !== 'superadmin');
};
$account = static fn(int $id): ?array => $id > 0
    ? Database::fetch('SELECT id, username, full_name, role, phone, is_active, failed_logins, locked_until, last_login_at, last_login_ip
                         FROM admins WHERE id = :id', ['id' => $id])
    : null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } elseif ($act === 'end') {
        $row  = AdminSessions::get((int) ($_POST['sid'] ?? 0));
        $acct = $row !== null ? $account((int) $row['admin_id']) : null;
        if ($row === null || !empty($row['ended_at'])) {
            $flash = ['bad', 'That session has already ended.'];
        } elseif (!$mayTouch($acct)) {
            $flash = ['bad', 'Only a super-admin may end a super-admin\'s session.'];
        } elseif (AdminSessions::isCurrent($row)) {
            $flash = ['bad', 'That is this browser — use Sign out instead.'];
        } else {
            AdminSessions::end((int) $row['id'], $me, 'ended by ' . (string) ($admin['username'] ?? 'office'));
            Logger::audit('staff.session_end', 'admin', (string) $row['username'], null,
                ['session' => (int) $row['id'], 'device' => (string) $row['label'], 'ip' => (string) $row['ip']],
                'office ended one signed-in browser');
            $flash = ['ok', 'Ended. ' . $row['full_name'] . ' is signed out of that browser within 30 seconds. / ३० सेकेन्डभित्र लगआउट हुन्छ।'];
        }
    } elseif ($act === 'end_all') {
        $acct = $account($personId);
        if (!$mayTouch($acct)) {
            $flash = ['bad', $acct === null ? 'No such account.' : 'Only a super-admin may sign out a super-admin.'];
        } else {
            $keep = (int) $acct['id'] === $me ? (string) ($_SESSION[ADMIN_SESSION_KEY]['sk'] ?? '') : '';
            $n = AdminSessions::endAll((int) $acct['id'], $me, 'signed out everywhere by ' . (string) ($admin['username'] ?? 'office'), $keep);
            Logger::audit('staff.session_end_all', 'admin', (string) $acct['username'], null, ['browsers' => $n],
                'office signed the account out everywhere (browsers + WhatsApp)');
            $flash = ['ok', $acct['full_name'] . ': ' . $n . ' browser(s) and every WhatsApp sign-in ended'
                . ((int) $acct['id'] === $me ? ' (this browser kept)' : '') . '. / सबै ठाउँबाट लगआउट।'];
        }
    } elseif ($act === 'end_wa') {
        $rid = (int) ($_POST['wid'] ?? 0);
        $row = Database::fetch('SELECT w.id, w.phone, w.admin_id, a.username, a.role FROM wa_logins w
                                  LEFT JOIN admins a ON a.id = w.admin_id WHERE w.id = :id', ['id' => $rid]);
        if ($row === null) {
            $flash = ['bad', 'That WhatsApp sign-in no longer exists.'];
        } elseif (!$isSa && (string) $row['role'] === 'superadmin') {
            $flash = ['bad', 'Only a super-admin may end a super-admin\'s sign-in.'];
        } else {
            WaLogin::revoke($rid, $me);
            Logger::audit('staff.logout_whatsapp', 'admin', (string) ($row['username'] ?? $row['admin_id']), null,
                ['from' => (string) $row['phone'], 'login_id' => $rid], 'WhatsApp sign-in revoked by the office');
            $flash = ['ok', 'WhatsApp sign-in on ' . $row['phone'] . ' ended.'];
        }
    } elseif ($act === 'unlock') {
        $acct = $account($personId);
        if (!$mayTouch($acct)) {
            $flash = ['bad', $acct === null ? 'No such account.' : 'Only a super-admin may unlock a super-admin.'];
        } else {
            Database::update('admins', ['failed_logins' => 0, 'locked_until' => null], 'id = :id', ['id' => (int) $acct['id']]);
            Logger::audit('staff.unlock', 'admin', (string) $acct['username'],
                ['failed_logins' => (int) $acct['failed_logins'], 'locked_until' => $acct['locked_until']],
                ['failed_logins' => 0, 'locked_until' => null], 'office cleared failed sign-ins / lock');
            $flash = ['ok', $acct['full_name'] . ' unlocked — failed attempts reset to 0. / अनलक भयो।'];
        }
    } elseif ($act === 'revoke_device') {
        $acct = $account($personId);
        $did  = (int) ($_POST['did'] ?? 0);
        $dev  = $acct !== null ? Database::fetch('SELECT id, device_hash, label FROM admin_devices WHERE id = :d AND admin_id = :a',
            ['d' => $did, 'a' => (int) $acct['id']]) : null;
        if (!$mayTouch($acct) || $dev === null) {
            $flash = ['bad', 'That device cannot be revoked here.'];
        } else {
            LoginLog::revokeDevice((int) $acct['id'], $did);
            $n = AdminSessions::endDevice((int) $acct['id'], (string) $dev['device_hash'], $me);
            Logger::audit('staff.device_revoke', 'admin', (string) $acct['username'], null,
                ['device' => (string) $dev['label'], 'browsers_ended' => $n], 'office revoked a device');
            $flash = ['ok', 'Device "' . $dev['label'] . '" revoked' . ($n > 0 ? ' and its ' . $n . ' open session(s) ended' : '')
                . '. Its next sign-in counts as a new device.'];
        }
    }
}

/* ---------- data for the tab ---------- */
$live = $waLive = $hist = $devices = $personSessions = [];
$person = null;
if ($tab === 'live') {
    $live   = AdminSessions::active();
    $waLive = WaLogin::active(200);
} elseif ($tab === 'history') {
    $outcome = (string) ($_GET['outcome'] ?? '');
    if (!in_array($outcome, ['', 'success', 'bad_password', 'unknown_user', 'locked', 'disabled', 'logout'], true)) {
        $outcome = '';
    }
    $hist = LoginLog::history($personId, 300, $outcome);
} else {
    $person = $account($personId);
    if ($person !== null) {
        $devices        = LoginLog::devices((int) $person['id']);
        $personSessions = AdminSessions::active((int) $person['id']);
        $hist           = LoginLog::history((int) $person['id'], 40);
    }
}
$people = Database::fetchAll(
    "SELECT id, username, full_name, role, is_active FROM admins ORDER BY is_active DESC, full_name LIMIT 1000");
$locked = Database::fetchAll(
    "SELECT id, username, full_name, role, failed_logins, locked_until FROM admins
      WHERE locked_until > NOW() OR failed_logins >= 3 ORDER BY locked_until DESC LIMIT 50");

$ago = static function (?string $ts): string {
    if ($ts === null || $ts === '') {
        return '—';
    }
    $s = max(0, time() - (int) strtotime($ts));
    return $s < 60 ? 'just now' : ($s < 3600 ? intdiv($s, 60) . ' min ago' : ($s < 86400 ? intdiv($s, 3600) . ' h ago' : substr($ts, 0, 16)));
};
$outLabel = [
    'success' => ['✓ signed in', 'ss-ok'], 'bad_password' => ['✗ wrong password', 'ss-bad'],
    'unknown_user' => ['? unknown user', 'ss-bad'], 'locked' => ['🔒 locked', 'ss-bad'],
    'disabled' => ['⏻ switched off', 'ss-bad'], 'logout' => ['↩ signed out', 'ss-mut'],
];
$csrf = Security::csrfField();

admin_header('Sessions & Login Security', 'sessions');
?>
<style>
.ss-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px}
.ss-tabs a{padding:9px 14px;border-radius:999px;border:1px solid var(--line);background:var(--card);font-weight:700;font-size:13.5px;text-decoration:none;color:var(--ink)}
.ss-tabs a.on{background:var(--blue,#1d4ed8);border-color:var(--blue,#1d4ed8);color:#fff}
.ss-note{font-size:13px;color:var(--mut);margin:0 0 12px;line-height:1.5}
.ss-ok{color:var(--ok);font-weight:700}.ss-bad{color:var(--bad);font-weight:700}.ss-mut{color:var(--mut)}
.ss-here{display:inline-block;font-size:11px;font-weight:800;padding:2px 8px;border-radius:999px;background:var(--orange,#f97316);color:#fff;margin-left:4px}
.ss-role{font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;border:1px solid var(--line);background:var(--bg,#F4F6FB)}
.ss-pick{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:0 0 12px}
.ss-pick select{min-height:42px;padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);max-width:100%}
.ss-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin:0 0 12px}
.ss-facts div{border:1px solid var(--line);border-radius:12px;padding:10px 12px;background:var(--card)}
.ss-facts b{display:block;font-size:11.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.04em}
form.ss-inline{display:inline}
</style>

<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<nav class="ss-tabs">
  <a href="?tab=live" class="<?= $tab === 'live' ? 'on' : '' ?>">🟢 Live sessions</a>
  <a href="?tab=history" class="<?= $tab === 'history' ? 'on' : '' ?>">🕘 Login history</a>
  <a href="?tab=person<?= $personId > 0 ? '&id=' . $personId : '' ?>" class="<?= $tab === 'person' ? 'on' : '' ?>">👤 One person</a>
  <a href="permissions.php">🔐 Roles &amp; permissions</a>
</nav>

<?php if ($locked !== []): ?>
<div class="panel">
  <h2>🔒 Locked or failing sign-ins (<?= count($locked) ?>)</h2>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Account</th><th>Role</th><th data-type="num">Failed</th><th>Locked until</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($locked as $l): ?>
      <tr>
        <td data-label="Account"><a href="?tab=person&id=<?= (int) $l['id'] ?>"><?= $e($l['full_name'] ?: $l['username']) ?></a> <span class="ss-mut">(<?= $e($l['username']) ?>)</span></td>
        <td data-label="Role"><span class="ss-role"><?= $e($l['role']) ?></span></td>
        <td data-label="Failed" class="num"><?= (int) $l['failed_logins'] ?></td>
        <td data-label="Locked until"><?= $e($l['locked_until'] ? substr((string) $l['locked_until'], 0, 16) : '—') ?></td>
        <td data-label="">
          <?php if ($isSa || $l['role'] !== 'superadmin'): ?>
          <form method="post" class="ss-inline"><?= $csrf ?><input type="hidden" name="act" value="unlock"><input type="hidden" name="person" value="<?= (int) $l['id'] ?>">
            <button class="btn btn-sm ok">Unlock</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php if ($tab === 'live'): ?>
<div class="panel">
  <h2>Signed-in browsers (<?= count($live) ?>)</h2>
  <p class="ss-note">Everyone using the panel right now. <b>End</b> signs that one browser out within 30 seconds — the account stays
    on and the person can sign in again. To stop them coming back, switch the account off in Staff or change the password.
    · <b>End</b> थिच्दा त्यो ब्राउजर ३० सेकेन्डभित्र लगआउट हुन्छ।</p>
  <?php if ($live === []): ?>
    <p class="ss-note">No browser is signed in (sessions opened before this update appear within 30 seconds of their next click).</p>
  <?php else: ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Person</th><th>Role</th><th>Device</th><th>IP</th><th>How</th><th>Signed in</th><th>Last used</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($live as $s): $here = AdminSessions::isCurrent($s); ?>
      <tr>
        <td data-label="Person"><a href="?tab=person&id=<?= (int) $s['admin_id'] ?>"><?= $e($s['full_name'] ?: $s['username']) ?></a><?= $here ? '<span class="ss-here">this browser</span>' : '' ?></td>
        <td data-label="Role"><span class="ss-role"><?= $e($s['role']) ?></span></td>
        <td data-label="Device"><?= $e($s['label'] ?? '') ?></td>
        <td data-label="IP"><?= $e($s['ip'] ?? '') ?></td>
        <td data-label="How"><?= $e($s['via']) ?></td>
        <td data-label="Signed in" data-sort="<?= $e($s['created_at']) ?>"><?= $e(substr((string) $s['created_at'], 0, 16)) ?></td>
        <td data-label="Last used" data-sort="<?= $e($s['last_seen']) ?>"><?= $e($ago((string) $s['last_seen'])) ?></td>
        <td data-label="">
          <?php if (!$here && ($isSa || $s['role'] !== 'superadmin')): ?>
          <form method="post" class="ss-inline" onsubmit="return confirm('Sign <?= $e(addslashes((string) ($s['full_name'] ?: $s['username']))) ?> out of this browser?');">
            <?= $csrf ?><input type="hidden" name="act" value="end"><input type="hidden" name="sid" value="<?= (int) $s['id'] ?>">
            <button class="btn btn-sm danger">End</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>WhatsApp staff sign-ins (<?= count($waLive) ?>)</h2>
  <?php if ($waLive === []): ?>
    <p class="ss-note">Nobody is signed in over WhatsApp.</p>
  <?php else: ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Number</th><th>Person</th><th>Role</th><th>Since</th><th>Expires</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($waLive as $w): ?>
      <tr>
        <td data-label="Number"><?= $e($w['phone']) ?></td>
        <td data-label="Person"><?= $e(($w['full_name'] ?? '') ?: ($w['username'] ?? '#' . $w['admin_id'])) ?></td>
        <td data-label="Role"><span class="ss-role"><?= $e($w['role'] ?? '') ?></span></td>
        <td data-label="Since"><?= $e(substr((string) $w['created_at'], 0, 16)) ?></td>
        <td data-label="Expires"><?= $e(substr((string) $w['expires_at'], 0, 16)) ?></td>
        <td data-label="">
          <?php if ($isSa || ($w['role'] ?? '') !== 'superadmin'): ?>
          <form method="post" class="ss-inline" onsubmit="return confirm('End this WhatsApp sign-in?');">
            <?= $csrf ?><input type="hidden" name="act" value="end_wa"><input type="hidden" name="wid" value="<?= (int) $w['id'] ?>">
            <button class="btn btn-sm danger">End</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'history'): ?>
<div class="panel">
  <h2>Login history</h2>
  <form method="get" class="ss-pick">
    <input type="hidden" name="tab" value="history">
    <label style="font-size:12px;font-weight:700">Person<br>
      <select name="id">
        <option value="0">Everyone</option>
        <?php foreach ($people as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $personId ? 'selected' : '' ?>><?= $e(($p['full_name'] ?: $p['username']) . ' · ' . $p['role'] . ((int) $p['is_active'] === 1 ? '' : ' (off)')) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label style="font-size:12px;font-weight:700">Outcome<br>
      <select name="outcome">
        <option value="">All</option>
        <?php foreach ($outLabel as $k => $v): ?>
          <option value="<?= $e($k) ?>" <?= ($outcome ?? '') === $k ? 'selected' : '' ?>><?= $e($v[0]) ?></option>
        <?php endforeach; ?>
      </select></label>
    <button class="btn">Show</button>
  </form>
  <?php if ($hist === []): ?>
    <p class="ss-note">Nothing recorded for this filter.</p>
  <?php else: ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>When</th><th>Username typed</th><th>Account</th><th>Outcome</th><th>IP</th><th>Device</th></tr></thead>
    <tbody>
    <?php foreach ($hist as $h): $o = $outLabel[(string) $h['outcome']] ?? [(string) $h['outcome'], 'ss-mut']; ?>
      <tr>
        <td data-label="When" data-sort="<?= $e($h['created_at'] ?? '') ?>"><?= $e(substr((string) ($h['created_at'] ?? ''), 0, 16)) ?></td>
        <td data-label="Username"><?= $e($h['username']) ?></td>
        <td data-label="Account"><?php if (!empty($h['admin_id'])): ?><a href="?tab=person&id=<?= (int) $h['admin_id'] ?>"><?= $e($h['full_name'] ?? ('#' . $h['admin_id'])) ?></a><?php else: ?>—<?php endif; ?></td>
        <td data-label="Outcome"><span class="<?= $o[1] ?>"><?= $e($o[0]) ?></span></td>
        <td data-label="IP"><?= $e($h['ip_address'] ?? '') ?></td>
        <td data-label="Device"><?= $e(LoginLog::deviceLabel((string) ($h['user_agent'] ?? ''))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php else: ?>
<div class="panel">
  <form method="get" class="ss-pick">
    <input type="hidden" name="tab" value="person">
    <label style="font-size:12px;font-weight:700">Person<br>
      <select name="id" onchange="this.form.submit()">
        <option value="0">— choose —</option>
        <?php foreach ($people as $p): ?>
          <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $personId ? 'selected' : '' ?>><?= $e(($p['full_name'] ?: $p['username']) . ' · ' . $p['role'] . ((int) $p['is_active'] === 1 ? '' : ' (off)')) ?></option>
        <?php endforeach; ?>
      </select></label>
  </form>
  <?php if ($person === null): ?>
    <p class="ss-note">Pick a person to see their devices, open sessions and sign-ins.</p>
  <?php else: $can = $mayTouch($person); $isLocked = !empty($person['locked_until']) && strtotime((string) $person['locked_until']) > time(); ?>
    <h2 style="margin-top:4px"><?= $e($person['full_name'] ?: $person['username']) ?> <span class="ss-role"><?= $e($person['role']) ?></span></h2>
    <div class="ss-facts">
      <div><b>Account</b><?= (int) $person['is_active'] === 1 ? '<span class="ss-ok">On</span>' : '<span class="ss-bad">Switched off</span>' ?></div>
      <div><b>Lock</b><?= $isLocked ? '<span class="ss-bad">Locked till ' . $e(substr((string) $person['locked_until'], 11, 5)) . '</span>' : '<span class="ss-ok">Not locked</span>' ?></div>
      <div><b>Failed attempts</b><?= (int) $person['failed_logins'] ?></div>
      <div><b>Last sign-in</b><?= $e($person['last_login_at'] ? substr((string) $person['last_login_at'], 0, 16) : '—') ?><br><span class="ss-mut"><?= $e($person['last_login_ip'] ?? '') ?></span></div>
    </div>
    <?php if ($can): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin:0 0 6px">
      <?php if ($isLocked || (int) $person['failed_logins'] > 0): ?>
      <form method="post" class="ss-inline"><?= $csrf ?><input type="hidden" name="act" value="unlock"><input type="hidden" name="person" value="<?= (int) $person['id'] ?>">
        <button class="btn ok">🔓 Unlock / reset attempts</button></form>
      <?php endif; ?>
      <form method="post" class="ss-inline" onsubmit="return confirm('Sign this person out of every browser and WhatsApp?');">
        <?= $csrf ?><input type="hidden" name="act" value="end_all"><input type="hidden" name="person" value="<?= (int) $person['id'] ?>">
        <button class="btn danger">⏏ Sign out everywhere</button></form>
      <a class="btn" href="staff.php">Password / switch off → Staff</a>
    </div>
    <?php else: ?>
      <p class="ss-note">A super-admin account — only a super-admin may act on it.</p>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($person !== null): ?>
<div class="panel">
  <h2>Open sessions (<?= count($personSessions) ?>)</h2>
  <?php if ($personSessions === []): ?><p class="ss-note">Not signed in anywhere right now.</p><?php else: ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Device</th><th>IP</th><th>Signed in</th><th>Last used</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($personSessions as $s): $here = AdminSessions::isCurrent($s); ?>
      <tr>
        <td data-label="Device"><?= $e($s['label'] ?? '') ?><?= $here ? '<span class="ss-here">this browser</span>' : '' ?></td>
        <td data-label="IP"><?= $e($s['ip'] ?? '') ?></td>
        <td data-label="Signed in"><?= $e(substr((string) $s['created_at'], 0, 16)) ?></td>
        <td data-label="Last used"><?= $e($ago((string) $s['last_seen'])) ?></td>
        <td data-label=""><?php if (!$here && $mayTouch($person)): ?>
          <form method="post" class="ss-inline"><?= $csrf ?><input type="hidden" name="act" value="end"><input type="hidden" name="sid" value="<?= (int) $s['id'] ?>">
            <button class="btn btn-sm danger">End</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Devices (<?= count($devices) ?>)</h2>
  <p class="ss-note">A device is a browser that has signed in to this account. Revoke one you do not recognise — its open session ends and
    its next sign-in is treated as a new device.</p>
  <?php if ($devices === []): ?><p class="ss-note">No devices recorded.</p><?php else: ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Device</th><th>Last IP</th><th data-type="num">Sign-ins</th><th>Last seen</th><th>State</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($devices as $d): $rev = !empty($d['revoked_at']); ?>
      <tr>
        <td data-label="Device"><?= $e($d['label']) ?><?= LoginLog::isCurrentDevice((string) $d['device_hash']) ? '<span class="ss-here">this browser</span>' : '' ?></td>
        <td data-label="Last IP"><?= $e($d['last_ip'] ?? '') ?></td>
        <td data-label="Sign-ins" class="num"><?= (int) $d['sign_ins'] ?></td>
        <td data-label="Last seen"><?= $e(substr((string) $d['last_seen'], 0, 16)) ?></td>
        <td data-label="State"><?= $rev ? '<span class="ss-bad">revoked</span>' : '<span class="ss-ok">trusted</span>' ?></td>
        <td data-label=""><?php if (!$rev && $mayTouch($person)): ?>
          <form method="post" class="ss-inline" onsubmit="return confirm('Revoke this device?');"><?= $csrf ?>
            <input type="hidden" name="act" value="revoke_device"><input type="hidden" name="person" value="<?= (int) $person['id'] ?>"><input type="hidden" name="did" value="<?= (int) $d['id'] ?>">
            <button class="btn btn-sm">Revoke</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>Last sign-ins</h2>
  <?php if ($hist === []): ?><p class="ss-note">None recorded.</p><?php else: ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>When</th><th>Outcome</th><th>IP</th><th>Device</th></tr></thead>
    <tbody>
    <?php foreach ($hist as $h): $o = $outLabel[(string) $h['outcome']] ?? [(string) $h['outcome'], 'ss-mut']; ?>
      <tr>
        <td data-label="When"><?= $e(substr((string) ($h['created_at'] ?? ''), 0, 16)) ?></td>
        <td data-label="Outcome"><span class="<?= $o[1] ?>"><?= $e($o[0]) ?></span></td>
        <td data-label="IP"><?= $e($h['ip_address'] ?? '') ?></td>
        <td data-label="Device"><?= $e(LoginLog::deviceLabel((string) ($h['user_agent'] ?? ''))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php admin_footer();
