<?php
/**
 * admin/ledger-check.php — "Ledger मिलान": agent ledger vs bookings (3 Oct 2026).
 *
 * Re-reads every booking and lists where a stored agent_ledger row no
 * longer matches it (includes/ledgercheck.php explains each check). Office
 * roles with commissions.view can look; only a super-admin applies a fix,
 * and every fix runs the same AgentWallet function a normal booking change
 * would, re-checks the booking first, and is audited.
 *
 * Refunds / cancellations are the owner's manual work: cash held on a
 * cancelled ticket is listed for him, never changed here.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/ledgercheck.php';
$admin = admin_boot('commissions.view');
if (Auth::isCounterAgent()) {
    admin_header('Ledger मिलान', 'ledger-check');
    echo '<div class="flash bad">This is an office page.</div>';
    admin_footer();
    exit;
}
$canFix = Auth::isSuperadmin();
$flash  = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $kind = (string) ($_POST['kind'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canFix) {
        $flash = ['bad', 'Only a super-admin can correct the ledger.'];
    } elseif (!isset(LedgerCheck::KINDS[$kind])) {
        $flash = ['bad', 'Unknown check.'];
    } else {
        $ids = ($_POST['all'] ?? '') === '1'
            ? array_map(static fn(array $r): int => (int) $r['booking_id'], LedgerCheck::find($kind))
            : [(int) ($_POST['booking_id'] ?? 0)];
        $done = 0; $errs = [];
        foreach ($ids as $id) {
            if ($id <= 0) { continue; }
            $err = LedgerCheck::fix($kind, $id);
            if ($err === '') { $done++; } else { $errs[] = '#' . $id . ': ' . $err; }
        }
        $flash = $errs === []
            ? ['ok', $done . ' booking(s) corrected — every agent statement and balance now reads the corrected rows.']
            : ['bad', $done . ' corrected, ' . count($errs) . ' not: ' . implode(' · ', array_slice($errs, 0, 5))];
    }
}

$found = LedgerCheck::run();
$info  = LedgerCheck::info();
$total = array_sum(array_map('count', $found));
$csrf  = Security::e(Security::csrfToken());
$k     = CSRF_TOKEN_NAME;
$e     = static fn($v): string => Security::e((string) ($v ?? ''));

admin_header('Ledger मिलान', 'ledger-check');
if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }
?>
<style>
.lc-ok{padding:16px 18px;color:#0a6b3b;font-weight:600}
.lc-row{display:grid;grid-template-columns:150px 1fr 1fr 1fr auto;gap:10px;align-items:center;padding:10px 16px;border-bottom:1px solid var(--line);font-size:13.5px}
.lc-row:last-child{border-bottom:0}
.lc-row .mut{color:var(--mut);font-size:12px}
.lc-head{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.lc-head form{margin-left:auto}
@media(max-width:820px){.lc-row{grid-template-columns:1fr 1fr}.lc-row form{grid-column:1/-1}}
@media(pointer:coarse){.lc-row .btn{min-height:44px}}
</style>

<div class="cards">
  <div class="card"><div class="k">Bookings not matching</div><div class="v"><?= (int) $total ?></div></div>
  <?php foreach (LedgerCheck::KINDS as $kind => [$title]): ?>
    <div class="card"><div class="k"><?= $e($title) ?></div><div class="v"><?= count($found[$kind]) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="panel" style="padding:12px 18px;margin-bottom:14px;font-size:13.5px;line-height:1.55">
  Every agent balance, statement and payout adds up the agent ledger. This page re-reads <b>every booking</b> and lists where a ledger row no longer matches it.
  <?= $canFix ? 'A fix uses the same rule a normal booking change uses, checks the booking again first, and is logged.' : 'Only a super-admin can apply a fix.' ?>
  Refunds and cancellations are not changed here.
</div>

<?php foreach (LedgerCheck::KINDS as $kind => [$title, $action]): $rows = $found[$kind]; ?>
<div class="panel">
  <h2 class="lc-head"><?= $e($title) ?> <span class="muted" style="font-weight:400;font-size:13px">· <?= count($rows) ?></span>
    <?php if ($canFix && count($rows) > 1): ?>
      <form method="post" onsubmit="return confirm('<?= $e($action) ?> on all <?= count($rows) ?> bookings?')">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="kind" value="<?= $e($kind) ?>"><input type="hidden" name="all" value="1">
        <button class="btn" type="submit"><?= $e($action) ?> — all <?= count($rows) ?></button>
      </form>
    <?php endif; ?>
  </h2>
  <?php if ($rows === []): ?>
    <div class="lc-ok">✓ All bookings match.</div>
  <?php else: foreach (array_slice($rows, 0, 300) as $r): ?>
    <div class="lc-row">
      <div><?php if ($kind !== 'orphan'): ?><a class="mono" href="booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= $e($r['pnr']) ?></a><?php else: ?><span class="mono"><?= $e($r['pnr'] ?: '#' . $r['booking_id']) ?></span><?php endif; ?>
        <div class="mut"><?= $e($r['status']) ?></div></div>
      <div><?= $e($r['seller']) ?><div class="mut"><?= $e($r['note']) ?></div></div>
      <div><span class="mut">Ledger now</span><br><?= $e($r['stored']) ?></div>
      <div><span class="mut">Should be</span><br><b><?= $e($r['expected']) ?></b></div>
      <?php if ($canFix): ?>
        <form method="post" onsubmit="return confirm('<?= $e($action) ?> for <?= $e($r['pnr'] ?: '#' . $r['booking_id']) ?>?')">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="kind" value="<?= $e($kind) ?>"><input type="hidden" name="booking_id" value="<?= (int) $r['booking_id'] ?>">
          <button class="btn ghost" type="submit">Fix</button>
        </form>
      <?php else: ?><span></span><?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
<?php endforeach; ?>

<div class="panel">
  <h2>ℹ️ Cash held on cancelled tickets <span class="muted" style="font-weight:400;font-size:13px">· <?= (int) $info['cancelled_cash']['n'] ?> ticket(s) · <?= $e(inr($info['cancelled_cash']['amount'])) ?> · you settle these by hand</span></h2>
  <?php if ($info['cancelled_cash']['rows'] === []): ?>
    <div class="lc-ok">None.</div>
  <?php else: ?>
  <details style="padding:0 18px 14px"><summary style="cursor:pointer;padding:6px 0">Show the list</summary>
    <div class="tbl-scroll"><table class="card-table">
      <thead><tr><th>PNR</th><th>Status</th><th>Refund</th><th>Cash held</th><th>Cancelled</th></tr></thead>
      <tbody>
      <?php foreach ($info['cancelled_cash']['rows'] as $r): ?>
        <tr><td class="mono" data-label="PNR"><a href="booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= $e($r['pnr']) ?></a></td>
          <td data-label="Status"><?= admin_pill((string) $r['status']) ?></td><td data-label="Refund"><?= $e($r['refund_status']) ?></td>
          <td data-label="Cash held"><?= $e(inr((float) $r['amount'])) ?></td><td data-label="Cancelled"><?= $e(substr((string) ($r['cancelled_at'] ?? ''), 0, 10) ?: '—') ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </details>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>ℹ️ Office &amp; counter staff in the agent ledger <span class="muted" style="font-weight:400;font-size:13px">· recorded because "Only agents earn commission" is <?= Settings::getBool('commission_agent_only', false) ? 'ON' : 'OFF' ?> in Settings</span></h2>
  <?php if ($info['staff'] === []): ?>
    <div class="lc-ok">None.</div>
  <?php else: ?>
  <div class="tbl-scroll"><table class="card-table">
    <thead><tr><th>Staff</th><th>Role</th><th>Bookings</th><th>Commission (net)</th><th>Cash held</th></tr></thead>
    <tbody>
    <?php foreach ($info['staff'] as $s): ?>
      <tr><td data-label="Staff"><?= $e($s['full_name'] ?: $s['username']) ?></td><td data-label="Role"><?= $e($s['role']) ?></td>
        <td data-label="Bookings"><?= (int) $s['bookings'] ?></td><td data-label="Commission"><?= $e(inr((float) $s['commission'])) ?></td><td data-label="Cash held"><?= $e(inr((float) $s['cash'])) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
