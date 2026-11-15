<?php
/**
 * admin/login-requests.php — PIN activation & recovery (15 Nov 2026).
 *
 * The office side of the code-first door (includes/codelogin.php). A person
 * whose DOB / mobile is not on file, or who cannot use the registered
 * WhatsApp, asks the office from the login screen. The office confirms who
 * they are (in person / by phone) and issues an 8-character activation code,
 * valid 24 hours, usable once, for THAT account only. The person types it on
 * the login screen and then chooses their own PIN / pattern — the office never
 * sees or sets a PIN.
 *
 * Who: staff.manage. An office account (anything but agent / counter /
 * scanner) only by a super-admin; nobody issues a code for their own account.
 * Every action is CSRF-checked and audited; only the bcrypt of a code is kept.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/codelogin.php';

$admin = admin_boot('staff.manage');
$me    = (int) ($admin['id'] ?? 0);
$isSa  = Auth::isSuperadmin();
$e     = static fn($v): string => Security::e((string) ($v ?? ''));
$flash = null;
$issued = null;   // [code, account label] shown once
$ready = CodeLogin::ready();

$mayIssue = static function (?array $acct) use ($isSa, $me): ?string {
    if ($acct === null) {
        return 'No such account.';
    }
    if ((int) $acct['id'] === $me) {
        return 'You cannot issue an activation code for your own account.';
    }
    if ((int) $acct['is_active'] !== 1) {
        return 'This account is switched off — switch it on first.';
    }
    if (!in_array((string) $acct['role'], Auth::OTP_DOOR_ROLES, true) && !$isSa) {
        return 'Only a super-admin may activate an office account.';
    }
    return null;
};
$newCode = static function (): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = '';
    for ($i = 0; $i < 8; $i++) {
        $c .= $a[random_int(0, 31)];
    }
    return $c;
};

if ($ready) {
    Database::query("UPDATE login_recovery_requests SET status = 'expired', act_hash = NULL WHERE status = 'approved' AND act_expires < NOW()");
}

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif ($act === 'approve' || $act === 'issue') {
        $rid  = (int) ($_POST['rid'] ?? 0);
        $req  = $act === 'approve' ? Database::fetch("SELECT * FROM login_recovery_requests WHERE id = :i AND status = 'open'", ['i' => $rid]) : null;
        $aid  = $act === 'approve' ? (int) ($req['admin_id'] ?? 0) : (int) ($_POST['aid'] ?? 0);
        $acct = $aid > 0 ? Database::fetch('SELECT id, username, full_name, role, is_active FROM admins WHERE id = :i', ['i' => $aid]) : null;
        if ($act === 'approve' && $req === null) {
            $flash = ['bad', 'That request is no longer open.'];
        } elseif (empty($_POST['checked'])) {
            $flash = ['bad', 'Tick "I confirmed who this person is" first.'];
        } elseif (($why = $mayIssue($acct)) !== null) {
            $flash = ['bad', $why];
        } else {
            $code = $newCode();
            $row = [
                'status' => 'approved', 'act_hash' => Security::hashPassword($code), 'act_tries' => 0,
                'act_expires' => date('Y-m-d H:i:s', time() + CodeLogin::ACT_TTL_H * 3600),
                'decided_by' => $me, 'decided_at' => date('Y-m-d H:i:s'),
            ];
            // one live code per account: older approved codes stop working
            Database::query("UPDATE login_recovery_requests SET status = 'expired', act_hash = NULL WHERE admin_id = :a AND status = 'approved'", ['a' => $aid]);
            if ($req !== null) {
                Database::update('login_recovery_requests', $row, 'id = :i', ['i' => $rid]);
            } else {
                Database::insert('login_recovery_requests', $row + [
                    'admin_id' => $aid, 'code_typed' => CodeLogin::codeFor($aid), 'kind' => QuickUnlock::status($aid)['pin'] || QuickUnlock::status($aid)['pattern'] ? 'reset' : 'setup',
                    'reason' => 'office', 'ip_address' => mb_substr(Security::clientIp(), 0, 45),
                ]);
            }
            Logger::audit('admin.login.activation_issued', 'admin', (string) $aid, null, ['request' => $rid ?: null], 'Office issued a one-time PIN activation code (24 h)');
            $issued = [substr($code, 0, 4) . '-' . substr($code, 4), CodeLogin::codeFor($aid) . ' · ' . (string) ($acct['full_name'] ?? '')];
        }
    } elseif ($act === 'details') {
        // 15 Nov 2026 (owner: "agent select garne, date of birth, mobile number — ID pass hunchha")
        $r = CodeLogin::officeSetDetails((int) ($_POST['aid'] ?? 0), (string) ($_POST['dob'] ?? ''), (string) ($_POST['mobile'] ?? ''),
            (string) ($_POST['cc'] ?? '91'), $me, $isSa);
        $flash = $r['ok']
            ? ['ok', '✅ ' . $r['name'] . ' — लगइन तयार। पहिलो पटक: आफ्नो code → मोबाइल → WhatsApp (hi / QR) → आफ्नै PIN / pattern · Login ready: code → mobile → WhatsApp hi + YES → own PIN / pattern.']
            : ['bad', (string) $r['error']];
    } elseif ($act === 'reject') {
        $n = Database::run("UPDATE login_recovery_requests SET status = 'rejected', decided_by = :m, decided_at = NOW() WHERE id = :i AND status = 'open'",
            ['m' => $me, 'i' => (int) ($_POST['rid'] ?? 0)]);
        if ($n === 1) {
            Logger::audit('admin.login.request_rejected', 'login_recovery_requests', (string) (int) $_POST['rid'], null, null, 'Office rejected a PIN activation request');
        }
        $flash = $n === 1 ? ['ok', 'Request closed.'] : ['bad', 'That request is no longer open.'];
    }
}

$open = $ready ? Database::fetchAll(
    "SELECT r.*, a.full_name, a.role, a.is_active FROM login_recovery_requests r LEFT JOIN admins a ON a.id = r.admin_id
      WHERE r.status = 'open' ORDER BY r.id DESC LIMIT 100") : [];
$recent = $ready ? Database::fetchAll(
    "SELECT r.id, r.admin_id, r.code_typed, r.kind, r.status, r.decided_at, r.act_expires, r.created_at, a.full_name
       FROM login_recovery_requests r LEFT JOIN admins a ON a.id = r.admin_id
      WHERE r.status <> 'open' ORDER BY r.id DESC LIMIT 20") : [];
$accts = Database::fetchAll('SELECT id, full_name, role, phone, is_active, pin_hash, pattern_hash FROM admins WHERE is_active = 1 ORDER BY role, id');
$dobs = [];
try {
    foreach (Database::fetchAll('SELECT admin_id, dob FROM ap_members WHERE dob IS NOT NULL') as $m) {
        $dobs[(int) $m['admin_id']] = true;
    }
} catch (Throwable $ex) {
}
$why = ['no_details' => 'DOB / mobile not on file', 'no_match' => 'details did not match', 'no_number' => 'details matched, number not available',
        'inactive' => 'account switched off', 'no_account' => 'no account has that code', 'asked' => 'asked directly', 'office' => 'office'];

admin_header('Login setup & PIN · लगइन तयार', 'login-requests.php');
?>
<style>
.lr-wrap{display:grid;gap:16px;max-width:1000px}
.lr-card{background:var(--card,#fff);border:1px solid var(--line,#dfe5ef);border-radius:14px;padding:16px;overflow-x:auto}
.lr-card h2{margin:0 0 8px;font-size:17px}
.lr-t{width:100%;border-collapse:collapse;font-size:14px}
.lr-t th,.lr-t td{padding:8px 6px;border-bottom:1px solid var(--line,#eef1f6);text-align:left;vertical-align:top}
.lr-ok{color:#0a6b33;font-weight:700}.lr-no{color:#8a1f1f;font-weight:700}
.lr-code{font:800 28px/1.2 ui-monospace,Consolas,monospace;letter-spacing:.12em;text-align:center;padding:12px;border-radius:12px;background:#fff4d6;color:#5a3d00}
.lr-act form{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;margin:2px 0}
.lr-act label{font-size:12px;display:inline-flex;gap:4px;align-items:center}
.lr-ready{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end}
.lr-ready label{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:700}
.lr-ready select,.lr-ready input{min-height:44px;padding:0 10px;border-radius:10px;border:1px solid var(--line,#dfe5ef);font:inherit;font-size:15px;background:var(--card,#fff);color:inherit;width:100%;box-sizing:border-box}
.lr-ph{display:flex;gap:6px}.lr-ph select{width:96px;flex:none}
.lr-ready .btn{min-height:46px}
@media (max-width:640px){.lr-t thead{display:none}.lr-t tr{display:block;border-bottom:1px solid var(--line,#dfe5ef);padding:6px 0}.lr-t td{display:block;border:0;padding:3px 0}}
</style>
<div class="lr-wrap">
  <?php if (!$ready): ?>
    <p class="pill" style="display:block;padding:10px 12px;background:#fff4d6;color:#7a5200">Migration database/upgrade-2026-11-15-code-login.sql has not run yet.</p>
  <?php endif; ?>
  <?php if ($flash !== null): ?>
    <p class="pill" style="display:block;padding:10px 12px;background:<?= $flash[0] === 'ok' ? '#d9f5e3' : '#ffdede' ?>;color:<?= $flash[0] === 'ok' ? '#0a6b33' : '#8a1f1f' ?>"><?= $e($flash[1]) ?></p>
  <?php endif; ?>
  <?php if ($issued !== null): ?>
  <div class="lr-card">
    <h2>✅ Activation code for <?= $e($issued[1]) ?></h2>
    <div class="lr-code"><?= $e($issued[0]) ?></div>
    <p class="muted">Shown once. Tell it to the person yourself (in person or by a call you made). Valid 24 hours, one use, only for this account. They type their code, then this activation code, then choose their own PIN / pattern.</p>
  </div>
  <?php endif; ?>

  <?php /* 15 Nov 2026: the office readies a login in one place - pick the person, DOB, mobile. */
  $pre = (int) ($_GET['aid'] ?? 0); ?>
  <div class="lr-card" id="ready">
    <h2>🆔 लगइन तयार गर्ने · Set up a login</h2>
    <p class="muted">एजेन्ट / स्टाफ छान्नुहोस् र WhatsApp मोबाइल राख्नुहोस्। पहिलो पटक उहाँले आफ्नो code र यही मोबाइल हाल्नुहुन्छ, WhatsApp मा hi (वा QR scan) गरेर YES गर्नुहुन्छ, अनि आफ्नै PIN / pattern र सुरक्षा प्रश्न राख्नुहुन्छ। Office ले PIN कहिल्यै राख्दैन।</p>
    <form method="post" class="lr-ready" autocomplete="off"><?= Security::csrfField() ?><input type="hidden" name="act" value="details">
      <label>👤 को? · Who
        <select name="aid" required>
          <option value="">— छान्नुहोस् · choose —</option>
          <?php foreach ($accts as $a): if ((int) $a['id'] === $me || (!in_array((string) $a['role'], Auth::OTP_DOOR_ROLES, true) && !$isSa)) { continue; } ?>
          <option value="<?= (int) $a['id'] ?>"<?= $pre === (int) $a['id'] ? ' selected' : '' ?>><?= $e(($a['full_name'] ?: '?') . ' · ' . CodeLogin::codeFor((int) $a['id']) . ' · ' . $a['role']
              . (((string) ($a['pin_hash'] ?? '') !== '' || (string) ($a['pattern_hash'] ?? '') !== '') ? ' · PIN ✓' : '')) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label>🎂 जन्म मिति (ऐच्छिक) · Date of birth (optional)<input type="date" name="dob" min="1930-01-01" max="<?= $e(date('Y-m-d', strtotime('-1 day'))) ?>"></label>
      <label>📱 WhatsApp मोबाइल · Mobile
        <span class="lr-ph"><select name="cc" aria-label="Country"><option value="91">🇮🇳 +91</option><option value="977">🇳🇵 +977</option></select>
        <input name="mobile" type="tel" inputmode="numeric" maxlength="14" required placeholder="98XXXXXXXX"></span></label>
      <button class="btn ok" type="submit">✅ सेभ गर्ने · Save — login ready</button>
    </form>
  </div>

  <div class="lr-card">
    <h2>📨 Open requests (<?= count($open) ?>)</h2>
    <?php if ($open === []): ?><p class="muted">No open requests.</p><?php else: ?>
    <table class="lr-t"><thead><tr><th>When</th><th>Code typed</th><th>Account</th><th>Why</th><th>Contact note</th><th></th></tr></thead><tbody>
    <?php foreach ($open as $r): ?>
      <tr>
        <td><?= $e(date('d M H:i', strtotime((string) $r['created_at']) ?: time())) ?><br><small class="muted"><?= $e($r['kind']) ?></small></td>
        <td><b><?= $e($r['code_typed']) ?></b></td>
        <td><?= $r['admin_id'] ? $e(CodeLogin::codeFor((int) $r['admin_id']) . ' · ' . $r['full_name'] . ' · ' . $r['role']) : '<span class="lr-no">no account</span>' ?></td>
        <td><?= $e($why[(string) $r['reason']] ?? (string) $r['reason']) ?><?= $r['details_match'] === null ? '' : ((int) $r['details_match'] === 1 ? ' <span class="lr-ok">· DOB+mobile matched</span>' : ' <span class="lr-no">· DOB+mobile did not match</span>') ?></td>
        <td><?= $e($r['contact_note']) ?></td>
        <td class="lr-act">
          <?php if ($r['admin_id']): ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="act" value="approve"><input type="hidden" name="rid" value="<?= (int) $r['id'] ?>">
            <label><input type="checkbox" name="checked" value="1" required> I confirmed who this person is</label>
            <button class="btn ok" type="submit">Issue code</button></form>
          <?php endif; ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="act" value="reject"><input type="hidden" name="rid" value="<?= (int) $r['id'] ?>">
            <button class="btn" type="submit">Close</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>

  <div class="lr-card">
    <h2>👥 Accounts — who still needs a PIN / pattern</h2>
    <p class="muted">"DOB" and "Mobile" say only whether they are on file (self-setup needs both). Missing ones: fix the record on the member page, or confirm the person and issue a code here. Nothing is ever filled in automatically.</p>
    <table class="lr-t"><thead><tr><th>Code</th><th>Name · role</th><th>PIN / pattern</th><th>DOB</th><th>Mobile</th><th></th></tr></thead><tbody>
    <?php foreach ($accts as $a): $has = (string) ($a['pin_hash'] ?? '') !== '' || (string) ($a['pattern_hash'] ?? '') !== ''; ?>
      <tr>
        <td><b><?= $e(CodeLogin::codeFor((int) $a['id'])) ?></b></td>
        <td><?= $e($a['full_name']) ?> · <small><?= $e($a['role']) ?></small></td>
        <td><?= $has ? '<span class="lr-ok">set</span>' : '<span class="lr-no">not yet</span>' ?></td>
        <td><?= isset($dobs[(int) $a['id']]) ? '<span class="lr-ok">on file</span>' : '<span class="lr-no">missing</span>' ?></td>
        <td><?= TeamPortal::phone10((string) ($a['phone'] ?? '')) !== '' ? '<span class="lr-ok">on file</span>' : '<span class="lr-no">missing</span>' ?></td>
        <td class="lr-act"><?php if ($ready && $mayIssue($a) === null): ?>
          <a class="btn" href="?aid=<?= (int) $a['id'] ?>#ready">✏️ DOB / mobile</a>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="act" value="issue"><input type="hidden" name="aid" value="<?= (int) $a['id'] ?>">
            <label><input type="checkbox" name="checked" value="1" required> confirmed</label>
            <button class="btn" type="submit"><?= $has ? 'Reset code' : 'Activation code' ?></button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>

  <?php if ($recent !== []): ?>
  <div class="lr-card">
    <h2>🕘 Recent</h2>
    <table class="lr-t"><thead><tr><th>#</th><th>Code</th><th>Kind</th><th>Status</th><th>Decided</th></tr></thead><tbody>
    <?php foreach ($recent as $r): ?>
      <tr><td><?= (int) $r['id'] ?></td><td><?= $e($r['code_typed']) ?> <?= $e($r['full_name'] ?? '') ?></td><td><?= $e($r['kind']) ?></td><td><?= $e($r['status']) ?></td><td><?= $e($r['decided_at'] ?? '') ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
  </div>
  <?php endif; ?>
</div>
<?php
admin_footer();
