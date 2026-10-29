<?php
/**
 * admin/reconcile.php — Money reconciliation (29 Oct 2026 audit).
 *
 * ONE read-only page for every money question the audit found open, each
 * with what it means and where the owner settles it. NOTHING HERE WRITES:
 * refunds, cash handovers and corrections stay the owner's manual work
 * (standing rule, 3 Oct 2026); each block links to the page that does it.
 *
 *   1. Cash held      agent_ledger cash account per person (collected − handed
 *                     over), split office / counter / agent. With
 *                     commission_agent_only off, the owner's and the counters'
 *                     own sales are booked here too — that is company cash at
 *                     the desk, not agent debt.
 *   2. Cash on tickets that did not travel — a cash_due row on a cancelled /
 *                     rejected booking: refunded to the passenger, or kept?
 *   3. Pay-at-boarding dues — cod_pending on live tickets: upcoming vs the bus
 *                     already left (no-show / not marked collected).
 *   4. Refunds waiting — refund_status = pending.
 *   5. Paid more than the ticket now costs — a seat cancelled after the cash
 *                     was taken (per-seat cancel lowers the total, the payment
 *                     stays): the difference was returned or kept as a fee.
 *   6. Counter shifts vs payments (29 Oct 2026, management upgrade 2.5) —
 *                     the last 14 days: a closed count that did not match,
 *                     payments changed after a shift was closed (re-read now
 *                     vs frozen at close), counter cash taken with no shift
 *                     open, and shifts nobody closed.
 *
 * Who: reports.view or commissions.pay (owner, manager, accountant).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');
if (!Auth::isSuperadmin() && !Auth::can('reports.view') && !Auth::can('commissions.pay')) {
    Auth::requireAdmin('reports.view');   // the standard refusal page
}
$e = static fn($v): string => Security::e((string) $v);

/* 1. cash held per person ---------------------------------------------- */
$held = Database::fetchAll(
    "SELECT l.agent_admin_id AS id, a.full_name, a.username, a.role,
            SUM(CASE WHEN l.entry_type = 'cash_due' THEN l.amount ELSE 0 END)      AS collected,
            SUM(CASE WHEN l.entry_type <> 'cash_due' THEN -l.amount ELSE 0 END)    AS handed,
            SUM(l.amount) AS balance, MIN(l.created_at) AS since, COUNT(*) AS n
       FROM agent_ledger l LEFT JOIN admins a ON a.id = l.agent_admin_id
      WHERE l.account = 'cash'
      GROUP BY l.agent_admin_id
     HAVING ABS(balance) >= 1
      ORDER BY balance DESC"
);
$byKind = ['office' => 0.0, 'counter' => 0.0, 'agent' => 0.0];
foreach ($held as $h) {
    $k = in_array((string) $h['role'], ['agent'], true) ? 'agent' : ((string) $h['role'] === 'counter' ? 'counter' : 'office');
    $byKind[$k] += (float) $h['balance'];
}
$agentOnly = Settings::getBool('commission_agent_only', false);

/* 2. cash on tickets that did not travel -------------------------------- */
$deadCash = Database::fetchAll(
    "SELECT b.id, b.pnr, b.status, b.refund_status, b.refund_amount, b.cancelled_at, l.amount, a.full_name AS holder
       FROM agent_ledger l
       JOIN bookings b ON b.id = l.booking_id
       LEFT JOIN admins a ON a.id = l.agent_admin_id
      WHERE l.entry_type = 'cash_due' AND b.status IN ('cancelled', 'rejected', 'expired')
      ORDER BY b.cancelled_at DESC, b.id DESC"
);
$deadSum = array_sum(array_map(static fn($r) => (float) $r['amount'], $deadCash));

/* 3. pay-at-boarding dues ----------------------------------------------- */
$cod = Database::fetchAll(
    "SELECT b.id, b.pnr, b.status, p.amount, MIN(bl.travel_date) AS travel_date, a.full_name AS seller
       FROM payments p
       JOIN bookings b ON b.id = p.booking_id
       LEFT JOIN booking_legs bl ON bl.booking_id = b.id
       LEFT JOIN admins a ON a.id = b.sold_by_admin_id
      WHERE p.status = 'cod_pending'
      GROUP BY p.id
      ORDER BY travel_date"
);
$codSum = ['upcoming' => [0, 0.0], 'travelled' => [0, 0.0], 'dead' => [0, 0.0]];
$today  = todayISO();
foreach ($cod as $c) {
    $k = !in_array((string) $c['status'], ['confirmed', 'completed'], true) ? 'dead'
        : ((string) $c['travel_date'] >= $today ? 'upcoming' : 'travelled');
    $codSum[$k][0]++;
    $codSum[$k][1] += (float) $c['amount'];
}

/* 4. refunds waiting ---------------------------------------------------- */
$refunds = Database::fetch(
    "SELECT COUNT(*) AS n, COALESCE(SUM(refund_amount), 0) AS amt, MIN(cancelled_at) AS oldest
       FROM bookings WHERE refund_status = 'pending'"
) ?? ['n' => 0, 'amt' => 0, 'oldest' => null];

/* 5. paid more than the ticket now costs -------------------------------- */
$over = Database::fetchAll(
    "SELECT b.id, b.pnr, b.status, b.total_amount, b.refund_status, b.refund_amount, SUM(p.amount) AS paid
       FROM bookings b JOIN payments p ON p.booking_id = b.id AND p.status = 'verified'
      GROUP BY b.id
     HAVING paid > b.total_amount + 1
      ORDER BY b.id DESC LIMIT 200"
);

/* 6. Counter shifts vs payments (29 Oct 2026, management upgrade 2.5). */
require_once INCLUDE_PATH . '/countershift.php';
$shOn = CounterShift::enabled();
$shFrom = addDaysISO(todayISO(), -14);
$shDiff = []; $shMoved = []; $shOpenOld = []; $shLoose = ['n' => 0, 'cash' => 0.0];
try {
    foreach (CounterShift::between(null, $shFrom, todayISO(), 500) as $r) {
        if ((int) ($r['is_open'] ?? 0) === 1) {
            if (substr((string) $r['opened_at'], 0, 10) < todayISO()) { $shOpenOld[] = $r; }
            continue;
        }
        if (CounterShift::verdict((float) $r['variance']) !== 'ok') { $shDiff[] = $r; }
        $now = CounterShift::totals((int) $r['admin_id'], (string) $r['opened_at'], (string) $r['closed_at'],
            CounterShift::currencyOf($r), CounterDesk::rate((string) ($r['counter_code'] ?? '')));
        if (abs($now['cash'] - (float) $r['cash_sales']) > 0.009 || abs($now['upi'] - (float) $r['upi_sales']) > 0.009) {
            $shMoved[] = $r + ['now_cash' => $now['cash'], 'now_upi' => $now['upi']];
        }
    }
    if ($shOn) {
        // Cash a counter login verified while it had no shift open.
        $lr = Database::fetch(
            "SELECT COUNT(*) AS n, COALESCE(SUM(p.amount), 0) AS cash
               FROM payments p JOIN admins a ON a.id = p.verified_by AND a.role = 'counter'
              WHERE p.status = 'verified' AND p.method IN ('cash','cod') AND p.verified_at >= :f
                AND NOT EXISTS (SELECT 1 FROM counter_shifts s WHERE s.admin_id = p.verified_by
                                  AND s.opened_at <= p.verified_at AND (s.closed_at IS NULL OR s.closed_at >= p.verified_at))",
            ['f' => $shFrom . ' 00:00:00']
        );
        $shLoose = ['n' => (int) ($lr['n'] ?? 0), 'cash' => (float) ($lr['cash'] ?? 0)];
    }
} catch (Throwable $e) {
    Logger::warning('reconcile shifts: ' . $e->getMessage());
}

admin_header('Money Reconciliation', 'reconcile');
?>
<style>
.rc-note{font-size:13px;color:var(--mut);margin:0 0 12px;line-height:1.55}
.rc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px;margin:0 0 12px}
.rc-grid div{border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:var(--card)}
.rc-grid b{display:block;font-size:11.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.04em}
.rc-grid span{font-size:20px;font-weight:800;font-variant-numeric:tabular-nums}
.rc-act{display:inline-block;margin-top:6px;font-weight:700;font-size:13px}
.rc-warn{border-left:4px solid var(--orange,#f97316)}
</style>

<p class="rc-note">Every open money item in one place. <b>This page changes nothing</b> — refunds, cash handovers and corrections are
  settled by the owner on the page each block links to. · यो पेजले पैसा सार्दैन — हरेक कुरा मालिकले आफैं मिलाउने।</p>

<div class="panel rc-warn">
  <h2>1. Cash held at desks · डेस्कमा रहेको नगद</h2>
  <div class="rc-grid">
    <div><b>Office (owner / staff)</b><span><?= inr($byKind['office']) ?></span></div>
    <div><b>Counters</b><span><?= inr($byKind['counter']) ?></span></div>
    <div><b>Agents</b><span><?= inr($byKind['agent']) ?></span></div>
  </div>
  <p class="rc-note">"Cash held" = cash tickets sold minus cash handed over. <?php if (!$agentOnly): ?>Because
    <code>commission_agent_only</code> is OFF, tickets the office and counters sell are booked here too — that money is already the
    company's, at the desk; it is not agent debt.<?php endif; ?> No handover has been recorded for a desk whose "handed over" is ₹0:
    count the cash once, then record one opening handover per desk (Agent Portal → person → Cash handover).</p>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Person</th><th>Role</th><th data-type="num">Collected</th><th data-type="num">Handed over</th><th data-type="num">Held now</th><th>Since</th></tr></thead>
    <tbody>
    <?php foreach ($held as $h): ?>
      <tr>
        <td data-label="Person"><a href="agent-360.php?id=<?= (int) $h['id'] ?>"><?= $e($h['full_name'] ?: ($h['username'] ?? '#' . $h['id'])) ?></a></td>
        <td data-label="Role"><?= $e($h['role'] ?? '?') ?></td>
        <td data-label="Collected" class="num"><?= inr((float) $h['collected']) ?></td>
        <td data-label="Handed over" class="num"><?= inr((float) $h['handed']) ?></td>
        <td data-label="Held now" class="num"><b><?= inr((float) $h['balance']) ?></b></td>
        <td data-label="Since"><?= $e(substr((string) $h['since'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="panel">
  <h2>2. Cash on tickets that did not travel (<?= count($deadCash) ?> · <?= inr($deadSum) ?>)</h2>
  <p class="rc-note">The ticket was cancelled or rejected after the cash was taken; the "cash held" row stayed. For each: was the money
    given back (record the refund) or kept as a cancellation fee (leave it)? Listed by Ledger Check too; never changed automatically.</p>
  <?php if ($deadCash !== []): ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>PNR</th><th>Status</th><th>Held by</th><th data-type="num">Cash</th><th>Refund</th><th>Cancelled</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($deadCash, 0, 200) as $d): ?>
      <tr>
        <td data-label="PNR"><a href="booking-view.php?pnr=<?= urlencode((string) $d['pnr']) ?>"><?= $e($d['pnr']) ?></a></td>
        <td data-label="Status"><?= $e($d['status']) ?></td>
        <td data-label="Held by"><?= $e($d['holder'] ?? '') ?></td>
        <td data-label="Cash" class="num"><?= inr((float) $d['amount']) ?></td>
        <td data-label="Refund"><?= $e($d['refund_status']) ?><?= (float) $d['refund_amount'] > 0 ? ' · ' . inr((float) $d['refund_amount']) : '' ?></td>
        <td data-label="Cancelled"><?= $e(substr((string) $d['cancelled_at'], 0, 10)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <a class="rc-act" href="ledger-check.php">→ Ledger Check (मिलान)</a>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>3. Pay-at-boarding (COD) dues</h2>
  <div class="rc-grid">
    <div><b>Upcoming trips</b><span><?= $codSum['upcoming'][0] ?></span><br><?= inr($codSum['upcoming'][1]) ?></div>
    <div><b>Bus already left — not marked collected</b><span><?= $codSum['travelled'][0] ?></span><br><?= inr($codSum['travelled'][1]) ?></div>
    <div><b>On cancelled tickets (nothing due)</b><span><?= $codSum['dead'][0] ?></span><br><?= inr($codSum['dead'][1]) ?></div>
  </div>
  <p class="rc-note">"Bus already left" means the cash was collected and nobody tapped <b>Collected</b>, or the passenger did not board —
    check each in the Cash Book. Requests on cancelled tickets are closed automatically from this release on.</p>
  <a class="rc-act" href="cash-book.php?status=noshow">→ Cash Book</a>
</div>

<div class="panel">
  <h2>4. Refunds waiting (<?= (int) $refunds['n'] ?> · <?= inr((float) $refunds['amt']) ?>)</h2>
  <p class="rc-note">Oldest since <?= $e($refunds['oldest'] ? substr((string) $refunds['oldest'], 0, 10) : '—') ?>. Approving a refund
    records it as paid with your reference; it does not send money by itself.</p>
  <a class="rc-act" href="refunds.php">→ Refunds desk</a>
</div>

<div class="panel">
  <h2>5. Paid more than the ticket now costs (<?= count($over) ?>)</h2>
  <p class="rc-note">A seat was cancelled after the full cash was taken: the ticket total went down, the payment did not. The difference
    was either handed back to the passenger or kept as the cancellation fee — note which on the ticket.</p>
  <?php if ($over !== []): ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>PNR</th><th>Status</th><th data-type="num">Ticket now</th><th data-type="num">Paid</th><th data-type="num">Difference</th><th>Refund</th></tr></thead>
    <tbody>
    <?php foreach ($over as $o): ?>
      <tr>
        <td data-label="PNR"><a href="booking-view.php?pnr=<?= urlencode((string) $o['pnr']) ?>"><?= $e($o['pnr']) ?></a></td>
        <td data-label="Status"><?= $e($o['status']) ?></td>
        <td data-label="Ticket now" class="num"><?= inr((float) $o['total_amount']) ?></td>
        <td data-label="Paid" class="num"><?= inr((float) $o['paid']) ?></td>
        <td data-label="Difference" class="num"><b><?= inr((float) $o['paid'] - (float) $o['total_amount']) ?></b></td>
        <td data-label="Refund"><?= $e($o['refund_status']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>6. Counter shifts vs payments · काउन्टर शिफ्ट मिलान <span class="muted" style="font-size:12.5px;font-weight:500">· last 14 days</span></h2>
  <?php if (!$shOn): ?>
    <p class="rc-note">Shift &amp; cash count is switched off, so counters keep no drawer count to check. <a href="shift.php">Switch it on →</a></p>
  <?php endif; ?>
  <div class="rc-grid">
    <div><b><?= $shDiff === [] ? '✅' : '⚠️' ?> Count did not match</b><span><?= count($shDiff) ?></span></div>
    <div><b><?= $shMoved === [] ? '✅' : '⚠️' ?> Changed after close</b><span><?= count($shMoved) ?></span></div>
    <div><b><?= $shLoose['n'] === 0 ? '✅' : '⚠️' ?> Cash with no shift open</b><span><?= (int) $shLoose['n'] ?><?= $shLoose['cash'] > 0 ? ' · ' . inr($shLoose['cash']) : '' ?></span></div>
    <div><b><?= $shOpenOld === [] ? '✅' : '⚠️' ?> Left open from earlier</b><span><?= count($shOpenOld) ?></span></div>
  </div>
  <p class="rc-note">“Changed after close” = the payments in that shift's window read differently now than when the drawer was counted
    (a payment verified late, corrected or re-assigned). The count stays frozen as counted; check the slip.</p>
  <?php $shRows = array_merge(array_map(static fn(array $r): array => $r + ['why' => 'count ' . CounterShift::verdict((float) $r['variance']) . ' ' . inr(abs((float) $r['variance']))], $shDiff),
                              array_map(static fn(array $r): array => $r + ['why' => 'now cash ' . inr((float) $r['now_cash']) . ' (was ' . inr((float) $r['cash_sales']) . ') · UPI ' . inr((float) $r['now_upi']) . ' (was ' . inr((float) $r['upi_sales']) . ')'], $shMoved),
                              array_map(static fn(array $r): array => $r + ['why' => 'open since ' . substr((string) $r['opened_at'], 0, 16)], $shOpenOld)); ?>
  <?php if ($shRows !== []): ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Shift</th><th>Who</th><th>Counter</th><th>Opened</th><th>What to check</th></tr></thead>
    <tbody>
    <?php foreach ($shRows as $r): ?>
      <tr>
        <td data-label="Shift"><a href="shift.php?<?= (int) ($r['is_open'] ?? 0) === 1 ? 'shift' : 'slip' ?>=<?= (int) $r['id'] ?>">#<?= (int) $r['id'] ?></a></td>
        <td data-label="Who"><?= $e((string) ($r['admin_name'] ?? ('#' . $r['admin_id']))) ?></td>
        <td data-label="Counter"><?= $e((string) ($r['counter_code'] ?? '')) ?></td>
        <td data-label="Opened"><?= $e(substr((string) $r['opened_at'], 0, 16)) ?></td>
        <td data-label="What to check"><?= $e($r['why']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <a class="rc-act" href="shift.php">→ Shift &amp; cash</a>
</div>

<?php admin_footer();
