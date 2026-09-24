<?php
/**
 * admin/ai-activity.php — what the WhatsApp assistant actually DID.
 *
 * Every tool the assistant runs — allowed or refused — writes one row to
 * ai_agent_calls (see AiTools::log): who (number + role), which button, did
 * it succeed, how long, and which booking. This screen is that log, made
 * legible: today at a glance, then the last calls with a filter. It is how
 * the office learns to TRUST the assistant before switching selling on, and
 * how it spots a number hammering a tool or a tool that keeps refusing.
 *
 * Read-only. It shows the outcome line, never the passenger's own sentence
 * (that stays in message_logs) and never the raw tool arguments. Office only
 * (dashboard.view) — a counter agent never sees the whole company's traffic.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$today   = date('Y-m-d');
$have    = true;
try {
    Database::scalar('SELECT 1 FROM ai_agent_calls LIMIT 1', [], null);
} catch (Throwable $e) {
    $have = false;
}

/* WhatsApp sign-ins (24 Sep 2026, wa_login_on): who is signed in as staff
   from which number, and a Revoke button. Revoking needs staff.manage — the
   same right that switches an account off in Staff. */
require_once INCLUDE_PATH . '/walogin.php';
$canRevoke = Auth::can('staff.manage') || (($admin['role'] ?? '') === 'superadmin');
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['revoke_login'])) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canRevoke) {
        $flash = ['bad', 'Your role cannot revoke a sign-in.'];
    } else {
        $rid = (int) $_POST['revoke_login'];
        $row = null;
        try {
            $row = Database::fetch('SELECT w.*, a.username FROM wa_logins w LEFT JOIN admins a ON a.id = w.admin_id WHERE w.id = :id', ['id' => $rid]);
        } catch (Throwable $e) {
        }
        if ($row === null) {
            $flash = ['bad', 'That sign-in no longer exists.'];
        } else {
            WaLogin::revoke($rid, (int) ($admin['id'] ?? 0));
            Logger::audit('staff.logout_whatsapp', 'admin', (string) ($row['username'] ?? $row['admin_id']), null,
                ['from' => (string) $row['phone'], 'login_id' => $rid], 'WhatsApp sign-in revoked by the office');
            $flash = ['ok', 'Signed out ' . (string) ($row['username'] ?? '#' . $row['admin_id']) . ' on ' . (string) $row['phone'] . '.'];
        }
    }
}
$logins   = WaLogin::active(100);
$loginOn  = Settings::getBool('wa_login_on', false);
$csrf     = Security::e(Security::csrfToken());
$csrfName = CSRF_TOKEN_NAME;

$sum = ['total' => 0, 'ok' => 0, 'people' => 0, 'refused' => 0];
$tools = [];
$rows  = [];
if ($have) {
    $s = Database::fetch(
        "SELECT COUNT(*) AS total, COALESCE(SUM(ok),0) AS ok, COUNT(DISTINCT phone) AS people
           FROM ai_agent_calls WHERE DATE(created_at) = :d", ['d' => $today]) ?? [];
    $sum['total']   = (int) ($s['total'] ?? 0);
    $sum['ok']      = (int) ($s['ok'] ?? 0);
    $sum['people']  = (int) ($s['people'] ?? 0);
    $sum['refused'] = $sum['total'] - $sum['ok'];

    $tools = Database::fetchAll(
        "SELECT tool, COUNT(*) AS n, COALESCE(SUM(ok),0) AS oks
           FROM ai_agent_calls WHERE DATE(created_at) = :d
          GROUP BY tool ORDER BY n DESC LIMIT 10", ['d' => $today]);

    $rows = Database::fetchAll(
        "SELECT c.id, c.created_at, c.phone, c.role, c.channel, c.tool, c.ok, c.detail, c.ms, b.pnr
           FROM ai_agent_calls c LEFT JOIN bookings b ON b.id = c.booking_id
          ORDER BY c.id DESC LIMIT 200");
}
$okRate = $sum['total'] > 0 ? round($sum['ok'] * 100 / $sum['total']) : 0;

admin_header('AI Activity', 'ai-activity');
?>
<style>
  .aa-note{font-size:13px;color:var(--mut);margin:0 0 14px;line-height:1.5}
  .aa-tools{display:flex;gap:8px;flex-wrap:wrap;margin:2px 0 4px}
  .aa-tool{font-size:12.5px;padding:6px 11px;border:1px solid var(--line);border-radius:999px;background:var(--card)}
  .aa-tool b{font-variant-numeric:tabular-nums}
  .aa-ok{color:var(--ok);font-weight:700} .aa-no{color:var(--warn);font-weight:700}
  table.dt td.aa-detail{white-space:normal;min-width:200px;max-width:420px}
</style>

<?php if (!$have): ?>
  <div class="panel"><p class="aa-note" style="color:var(--bad)">The assistant log table
    <code>ai_agent_calls</code> is not installed on this server yet. It ships with the WhatsApp
    assistant migration.</p></div>
  <?php admin_footer(); return; ?>
<?php endif; ?>

<?php if ($flash !== null): ?><div class="flash <?= $flash[0] ?>"><?= Security::e($flash[1]) ?></div><?php endif; ?>

<p class="aa-note">Every action the WhatsApp assistant takes is recorded here — successes and refusals both.
  The passenger's own words are not shown; only what the assistant did.</p>

<div class="panel">
  <h2>WhatsApp sign-ins (<?= count($logins) ?> live)</h2>
  <p class="aa-note">Staff who signed in over WhatsApp with <code>login &lt;code&gt; &lt;password&gt;</code>
    (<code>wa_login_on</code> is <?= $loginOn ? 'ON' : 'OFF' ?>). A sign-in expires by itself; revoke one here to end it now.</p>
  <?php if ($logins === []): ?>
    <p class="aa-note">Nobody is signed in over WhatsApp right now.</p>
  <?php else: ?>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>Number</th><th>Account</th><th>Role</th><th>Method</th><th>Since</th><th>Last seen</th><th>Expires</th><?php if ($canRevoke): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($logins as $l): ?>
        <tr>
          <td data-label="Number"><?= Security::e((string) $l['phone']) ?></td>
          <td data-label="Account"><?= Security::e((string) ($l['full_name'] ?? '')) ?> <span style="color:var(--mut)">(<?= Security::e((string) ($l['username'] ?? '#' . $l['admin_id'])) ?>)</span></td>
          <td data-label="Role"><?= Security::e((string) ($l['role'] ?? '')) ?></td>
          <td data-label="Method"><?= Security::e((string) $l['method']) ?></td>
          <td data-label="Since"><?= Security::e(substr((string) $l['created_at'], 0, 16)) ?></td>
          <td data-label="Last seen"><?= Security::e(substr((string) ($l['last_seen_at'] ?? ''), 0, 16)) ?></td>
          <td data-label="Expires"><?= Security::e(substr((string) $l['expires_at'], 0, 16)) ?></td>
          <?php if ($canRevoke): ?>
          <td data-label="">
            <form method="post" onsubmit="return confirm('Sign this number out of WhatsApp now?');" style="display:inline">
              <input type="hidden" name="<?= $csrfName ?>" value="<?= $csrf ?>">
              <button class="btn btn-sm" name="revoke_login" value="<?= (int) $l['id'] ?>">Revoke</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<div class="kpis">
  <div class="kpi tone-navy"><span class="ki"><svg class="a-ic"><use href="#a-msg"/></svg></span><div class="kt"><div class="kk">Actions today</div><div class="kv"><?= $sum['total'] ?></div><div class="ks"><?= $sum['people'] ?> people</div></div></div>
  <div class="kpi <?= $okRate >= 80 ? 'tone-green' : ($sum['total'] === 0 ? 'tone-navy' : 'tone-orange') ?>"><span class="ki"><svg class="a-ic"><use href="#a-check-circle"/></svg></span><div class="kt"><div class="kk">Succeeded</div><div class="kv"><?= $okRate ?>%</div><div class="ks"><?= $sum['ok'] ?> of <?= $sum['total'] ?></div></div></div>
  <div class="kpi <?= $sum['refused'] > 0 ? 'tone-orange' : 'tone-green' ?>"><span class="ki"><svg class="a-ic"><use href="#a-alert"/></svg></span><div class="kt"><div class="kk">Refused / failed</div><div class="kv"><?= $sum['refused'] ?></div><div class="ks">today</div></div></div>
</div>

<?php if ($tools !== []): ?>
<div class="panel">
  <h2>Tools used today</h2>
  <div class="aa-tools">
    <?php foreach ($tools as $t): ?>
      <span class="aa-tool"><?= Security::e((string) $t['tool']) ?> · <b><?= (int) $t['n'] ?></b>
        <span class="<?= (int) $t['oks'] === (int) $t['n'] ? 'aa-ok' : 'aa-no' ?>">(<?= (int) $t['oks'] ?> ok)</span></span>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Recent actions (<?= count($rows) ?>)</h2>
  <?php if ($rows === []): ?>
    <p class="aa-note">The assistant has not run any tool yet. Once <code>wa_agent_on</code> is switched on and a
      customer writes in, its actions appear here.</p>
  <?php else: ?>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>When</th><th>Number</th><th>Role</th><th>Tool</th><th>Result</th><th data-type="num">ms</th><th>Detail</th><th>PNR</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td data-label="When" data-sort="<?= Security::e((string) $r['created_at']) ?>"><?= Security::e(substr((string) $r['created_at'], 0, 16)) ?></td>
          <td data-label="Number"><?= Security::e((string) $r['phone']) ?></td>
          <td data-label="Role"><?= Security::e((string) $r['role']) ?></td>
          <td data-label="Tool"><?= Security::e((string) $r['tool']) ?></td>
          <td data-label="Result"><span class="<?= (int) $r['ok'] === 1 ? 'aa-ok' : 'aa-no' ?>"><?= (int) $r['ok'] === 1 ? '✓ ok' : '⚠ no' ?></span></td>
          <td data-label="ms" class="num"><?= (int) $r['ms'] ?></td>
          <td data-label="Detail" class="aa-detail"><?= Security::e((string) $r['detail']) ?></td>
          <td data-label="PNR"><?php if ((string) ($r['pnr'] ?? '') !== ''): ?><a href="/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= Security::e((string) $r['pnr']) ?></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
