<?php
/**
 * admin/accounting.php — the office day-book (requirement 5: "Accounting").
 *
 * One screen that answers the questions a counter/office asks at the end of
 * a day or a month, sourced straight from the live tables (no new storage):
 *
 *   • How much did we collect, split by method (cash / UPI / eSewa / bank)?
 *   • How much cash-on-delivery is still to collect?
 *   • What commission did agents accrue that we now owe them?
 *   • How much went out in refunds?
 *   • What did the paper-ticket register take in?
 *   • Which agents are still holding our cash (to hand over)?
 *   • Which agents are waiting for a payout decision? (17 Sep 2026)
 *
 * Read-only and defensive: every figure falls back to 0 if a table/column
 * is missing on an environment, so the page can never 500 a working panel.
 * Money is normalised to INR with the company peg so one column stays honest.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('payments.view');

$base = '';

if (!defined('NPR_PER_INR')) {
    define('NPR_PER_INR', 1.6);
}

/* ---- Window: default today; presets for month / last 30 days -------- */
$from = Security::clean($_GET['from'] ?? '', 10);
$to   = Security::clean($_GET['to']   ?? '', 10);
if (!Security::isValidDate($from)) { $from = todayISO(); }
if (!Security::isValidDate($to))   { $to   = todayISO(); }
if ($from > $to) { [$from, $to] = [$to, $from]; }   // tolerate a reversed pair

$paidStatuses = "'confirmed','completed'";
$inrOf = static fn(float $amt, ?string $cur): float =>
    strtoupper((string) $cur) === 'NPR' ? $amt / NPR_PER_INR : $amt;

/* =====================================================================
 *  1. Collections — every confirmed booking whose money landed in the
 *     window, one row per booking (so a second payment row can never
 *     double-count the fare), tagged with its latest payment method.
 * ===================================================================== */
$collectByMethod = [];   // method => ['count'=>, 'amount'=>]
$codPending      = ['count' => 0, 'amount' => 0.0];
$grossCollected  = 0.0;
$confirmedCount  = 0;   // all confirmed/completed in window (incl. COD pending)
$collectedCount  = 0;   // only those whose money is actually in (pairs with $grossCollected)
try {
    $rows = Database::fetchAll(
        "SELECT b.total_amount, b.currency, b.is_cod,
                (SELECT p2.method FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1) AS method,
                (SELECT p2.status FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1) AS pay_status
           FROM bookings b
          WHERE b.status IN ($paidStatuses)
            AND b.confirmed_at >= :f AND b.confirmed_at < :t1",
        ['f' => $from, 't1' => addDaysISO($to, 1)]   // sargable range = same days as DATE() BETWEEN, uses ix_bookings_confirmed
    );
    foreach ($rows as $r) {
        $confirmedCount++;
        $amt    = $inrOf((float) $r['total_amount'], $r['currency']);
        $method = strtolower((string) ($r['method'] ?? '')) ?: 'unknown';
        $status = (string) ($r['pay_status'] ?? '');

        if ($status === 'cod_pending') {
            // Money is NOT in yet — it is still to be collected at the counter.
            $codPending['count']++;
            $codPending['amount'] += $amt;
            continue;
        }

        $key = $method === 'cod' ? 'cash' : $method;   // a settled COD is cash in the drawer
        if (!isset($collectByMethod[$key])) { $collectByMethod[$key] = ['count' => 0, 'amount' => 0.0]; }
        $collectByMethod[$key]['count']++;
        $collectByMethod[$key]['amount'] += $amt;
        $grossCollected += $amt;
        $collectedCount++;
    }
} catch (Throwable $e) {
    // leave the section empty — the page still renders
}
arsort($collectByMethod);
$methodNames = ['upi' => 'UPI', 'esewa' => 'eSewa', 'cash' => 'Cash', 'bank' => 'Bank',
                'wallet' => 'Wallet', 'unknown' => 'Unrecorded'];

/* =====================================================================
 *  2. Commission accrued in the window (what we now owe agents), read
 *     from the single ledger source, net of reversals.
 * ===================================================================== */
$commissionAccrued = 0.0;
try {
    $commissionAccrued = (float) Database::scalar(
        "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
          WHERE account = 'commission'
            AND entry_type IN ('commission', 'commission_void')
            AND created_at >= :f AND created_at < :t1",
        ['f' => $from, 't1' => addDaysISO($to, 1)],
        0
    );
} catch (Throwable $e) { $commissionAccrued = 0.0; }

/* =====================================================================
 *  3. Refunds paid out in the window.
 * ===================================================================== */
$refunds = ['count' => 0, 'amount' => 0.0];
try {
    $rf = Database::fetch(
        "SELECT COUNT(*) c, COALESCE(SUM(refund_amount), 0) amt
           FROM bookings
          WHERE refund_amount > 0 AND cancelled_at >= :f AND cancelled_at < :t1",
        ['f' => $from, 't1' => addDaysISO($to, 1)]
    );
    $refunds = ['count' => (int) ($rf['c'] ?? 0), 'amount' => (float) ($rf['amt'] ?? 0)];
} catch (Throwable $e) { /* refund columns absent — skip */ }

/* =====================================================================
 *  4. Paper-ticket register takings in the window.
 * ===================================================================== */
$paper = [];
$paperCash = 0.0;
try {
    foreach (Database::fetchAll(
        "SELECT payment_mode, COUNT(*) c, COALESCE(SUM(amount),0) amt, COALESCE(SUM(commission),0) comm
           FROM offline_tickets
          WHERE created_at >= :f AND created_at < :t1
          GROUP BY payment_mode",
        ['f' => $from, 't1' => addDaysISO($to, 1)]
    ) as $r) {
        $paper[strtolower((string) $r['payment_mode'])] = [
            'count' => (int) $r['c'], 'amount' => (float) $r['amt'], 'comm' => (float) $r['comm'],
        ];
        if (strtolower((string) $r['payment_mode']) === 'cash') { $paperCash += (float) $r['amt']; }
    }
} catch (Throwable $e) { /* offline_tickets absent — skip */ }

/* =====================================================================
 *  5. Cash agents are still holding for the company (live, not windowed
 *     — a handover clears it whenever it happens). One aggregate query.
 * ===================================================================== */
$cashInHand = [];
$cashInHandTotal = 0.0;
try {
    foreach (Database::fetchAll(
        "SELECT l.agent_admin_id, a.full_name, a.username, COALESCE(SUM(l.amount),0) bal
           FROM agent_ledger l
           JOIN admins a ON a.id = l.agent_admin_id
          WHERE l.account = 'cash'
          GROUP BY l.agent_admin_id, a.full_name, a.username
         HAVING bal > 0.5
          ORDER BY bal DESC
          LIMIT 40"
    ) as $r) {
        $r['code'] = AgentWallet::agentCodeLabel((int) $r['agent_admin_id']);
        $cashInHand[] = $r;
        $cashInHandTotal += (float) $r['bal'];
    }
} catch (Throwable $e) { /* ledger absent — skip */ }

/* =====================================================================
 *  6. Open payout requests (17 Sep 2026) — every "please pay out my
 *     commission" still waiting for the office. Live, not windowed: a
 *     request stays here until it is paid or declined on the agent's
 *     360 view. Empty on a database without agent_payout_requests.
 * ===================================================================== */
$openReqs      = AgentWallet::openPayoutRequests();
$openReqTotal  = 0.0;
foreach ($openReqs as &$oq) {
    $oq['code']    = AgentWallet::agentCodeLabel((int) $oq['agent_admin_id']);
    $openReqTotal += (float) $oq['amount'];
}
unset($oq);

$isToday = ($from === $to && $from === todayISO());
$rangeLabel = $from === $to ? formatDate($from) : (formatDate($from) . ' → ' . formatDate($to));

admin_header('Accounting', 'accounting');
?>
<style>
  .acc-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px}
  .acc-tools label{font-size:12px;color:var(--mut)}
  .acc-tools input[type="date"]{display:block;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);font-size:14px}
  .acc-quick{display:flex;gap:6px;flex-wrap:wrap}
  .acc-quick a{font-size:12px;padding:7px 12px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);font-weight:600}
  .acc-quick a.on{background:var(--navy);color:#fff;border-color:var(--navy)}
  .acc-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:22px}
  .acc-grid .panel{margin-bottom:0}
  .acc-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
  .acc-tot td{font-weight:800;border-top:2px solid var(--line)}
  @media(max-width:820px){.acc-grid{grid-template-columns:1fr}}
  @media print{header.tb,nav.side,.acc-tools,.no-print{display:none!important}main.wrap{margin:0!important;padding:0!important;max-width:none!important}}
</style>

<form class="acc-tools no-print" method="get">
  <label>From<input type="date" name="from" value="<?= Security::e($from) ?>" max="<?= Security::e(todayISO()) ?>"></label>
  <label>To<input type="date" name="to" value="<?= Security::e($to) ?>" max="<?= Security::e(todayISO()) ?>"></label>
  <button class="btn" type="submit">Show</button>
  <div class="acc-quick">
    <a class="<?= $isToday ? 'on' : '' ?>" href="?from=<?= todayISO() ?>&to=<?= todayISO() ?>">Today</a>
    <a href="?from=<?= date('Y-m-01') ?>&to=<?= todayISO() ?>">This month</a>
    <a href="?from=<?= date('Y-m-d', strtotime('-29 days')) ?>&to=<?= todayISO() ?>">Last 30 days</a>
  </div>
  <button class="btn ghost" type="button" onclick="window.print()">🖨️ Print</button>
</form>

<p class="muted" style="margin-top:-6px">Day-book for <strong><?= Security::e($rangeLabel) ?></strong> · money shown in ₹ (NPR converted @ 1:<?= NPR_PER_INR ?>).</p>

<div class="cards">
  <div class="card"><div class="k">Collected (money in)</div><div class="v"><?= Security::e(inr($grossCollected)) ?><br><small><?= $collectedCount ?> paid · <?= $confirmedCount ?> confirmed</small></div></div>
  <div class="card"><div class="k">COD still to collect</div><div class="v"><?= Security::e(inr($codPending['amount'])) ?><br><small><?= $codPending['count'] ?> booking<?= $codPending['count'] === 1 ? '' : 's' ?></small></div></div>
  <div class="card"><div class="k">Commission payable</div><div class="v"><?= Security::e(inr($commissionAccrued)) ?><br><small>accrued this period</small></div></div>
  <div class="card"><div class="k">Refunds out</div><div class="v"><?= Security::e(inr($refunds['amount'])) ?><br><small><?= $refunds['count'] ?> refund<?= $refunds['count'] === 1 ? '' : 's' ?></small></div></div>
  <div class="card"><div class="k">Cash held by agents</div><div class="v"><?= Security::e(inr($cashInHandTotal)) ?><br><small>awaiting handover</small></div></div>
  <div class="card"><div class="k">Payout requests open</div><div class="v"><?= count($openReqs) ?><br><small><?= $openReqs !== [] ? Security::e(inr($openReqTotal)) . ' asked for' : 'nothing waiting' ?></small></div></div>
</div>

<div class="acc-grid">
  <div class="panel">
    <h2>Collections by method</h2>
    <table>
      <thead><tr><th>Method</th><th class="acc-num">Bookings</th><th class="acc-num">Amount</th></tr></thead>
      <tbody>
        <?php if ($collectByMethod === []): ?>
          <tr><td colspan="3" class="muted" style="padding:20px;text-align:center">No collections in this period.</td></tr>
        <?php else: foreach ($collectByMethod as $m => $v): ?>
          <tr>
            <td><?= Security::e($methodNames[$m] ?? ucfirst($m)) ?></td>
            <td class="acc-num"><?= (int) $v['count'] ?></td>
            <td class="acc-num"><?= Security::e(inr($v['amount'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        <?php if ($codPending['amount'] > 0): ?>
          <tr>
            <td class="muted">COD — pending <span class="pill" style="background:#fff4d1;color:#8a6d00;font-size:11px">to collect</span></td>
            <td class="acc-num muted"><?= (int) $codPending['count'] ?></td>
            <td class="acc-num muted"><?= Security::e(inr($codPending['amount'])) ?></td>
          </tr>
        <?php endif; ?>
      </tbody>
      <tfoot><tr class="acc-tot"><td>Total collected</td><td class="acc-num"><?= $collectedCount ?></td><td class="acc-num"><?= Security::e(inr($grossCollected)) ?></td></tr></tfoot>
    </table>
  </div>

  <div class="panel">
    <h2>Paper-ticket register</h2>
    <table>
      <thead><tr><th>Paid by</th><th class="acc-num">Tickets</th><th class="acc-num">Amount</th><th class="acc-num">Commission</th></tr></thead>
      <tbody>
        <?php if ($paper === []): ?>
          <tr><td colspan="4" class="muted" style="padding:20px;text-align:center">No paper tickets in this period.</td></tr>
        <?php else: foreach ($paper as $mode => $v): ?>
          <tr>
            <td><?= Security::e(ucfirst($mode)) ?></td>
            <td class="acc-num"><?= (int) $v['count'] ?></td>
            <td class="acc-num"><?= Security::e(inr($v['amount'])) ?></td>
            <td class="acc-num"><?= Security::e(inr($v['comm'])) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
    <p class="muted" style="padding:10px 18px;font-size:12px;margin:0">Cash taken on paper tickets: <strong><?= Security::e(inr($paperCash)) ?></strong> — already on the sellers' cash-in-hand below.</p>
  </div>
</div>

<div class="panel">
  <h2>Cash agents are holding (to hand over)</h2>
  <div class="tbl-scroll">
  <table>
    <thead><tr><th>Agent</th><th>Code</th><th class="acc-num">Cash in hand</th><th></th></tr></thead>
    <tbody>
      <?php if ($cashInHand === []): ?>
        <tr><td colspan="4" class="muted" style="padding:20px;text-align:center">No agent is holding company cash right now.</td></tr>
      <?php else: foreach ($cashInHand as $c): ?>
        <tr>
          <td><?= Security::e((string) ($c['full_name'] ?: $c['username'])) ?></td>
          <td class="mono"><?= Security::e($c['code'] ?: '—') ?></td>
          <td class="acc-num"><?= Security::e(inr((float) $c['bal'])) ?></td>
          <td><a class="btn ghost" style="font-size:12px;padding:5px 12px" href="<?= $base ?>/admin/agent-360.php?agent=<?= (int) $c['agent_admin_id'] ?>&amp;tab=settlements" title="Agent 360 — record the handover and print the receipt">Settle →</a></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <?php if ($cashInHand !== []): ?>
    <tfoot><tr class="acc-tot"><td colspan="2">Total to hand over</td><td class="acc-num"><?= Security::e(inr($cashInHandTotal)) ?></td><td></td></tr></tfoot>
    <?php endif; ?>
  </table>
  </div>
</div>

<div class="panel">
  <h2>Open payout requests <span class="muted" style="font-weight:500;font-size:13px">· agents asking to be paid their commission</span></h2>
  <div class="tbl-scroll">
  <table>
    <thead><tr><th>Agent</th><th>Code</th><th>Requested</th><th class="acc-num">Amount</th><th>Note</th><th></th></tr></thead>
    <tbody>
      <?php if ($openReqs === []): ?>
        <tr><td colspan="6" class="muted" style="padding:20px;text-align:center">No payout request is waiting for a decision.</td></tr>
      <?php else: foreach ($openReqs as $q): ?>
        <tr>
          <td><?= Security::e((string) ($q['agent_name'] ?: $q['agent_username'])) ?></td>
          <td class="mono"><?= Security::e($q['code'] ?: '—') ?></td>
          <td class="muted"><?= Security::e(formatDate(substr((string) $q['created_at'], 0, 10), 'j M Y')) ?> <span style="font-size:11px">· <?= Security::e(timeAgo((string) $q['created_at'])) ?></span></td>
          <td class="acc-num"><?= Security::e(inr((float) $q['amount'])) ?></td>
          <td class="muted" style="font-size:12px"><?= Security::e((string) ($q['note'] ?: '—')) ?></td>
          <td><a class="btn ghost" style="font-size:12px;padding:5px 12px" href="<?= $base ?>/admin/agent-360.php?agent=<?= (int) $q['agent_admin_id'] ?>&amp;tab=requests">Decide →</a></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <?php if ($openReqs !== []): ?>
    <tfoot><tr class="acc-tot"><td colspan="3">Total requested</td><td class="acc-num"><?= Security::e(inr($openReqTotal)) ?></td><td colspan="2"></td></tr></tfoot>
    <?php endif; ?>
  </table>
  </div>
</div>

<div class="panel">
  <h2>Period summary</h2>
  <table>
    <tr><th>Money collected (verified)</th><td class="acc-num"><?= Security::e(inr($grossCollected)) ?></td></tr>
    <tr><th>COD still to collect</th><td class="acc-num"><?= Security::e(inr($codPending['amount'])) ?></td></tr>
    <tr><th>Refunds paid out</th><td class="acc-num">− <?= Security::e(inr($refunds['amount'])) ?></td></tr>
    <tr><th>Agent commission payable (accrued)</th><td class="acc-num">− <?= Security::e(inr($commissionAccrued)) ?></td></tr>
    <tr class="acc-tot"><th>Net after refunds &amp; commission</th><td class="acc-num"><?= Security::e(inr($grossCollected - $refunds['amount'] - $commissionAccrued)) ?></td></tr>
  </table>
  <p class="muted" style="padding:10px 18px;font-size:12px;margin:0">
    Commission is what agents <em>earned</em> this period (payable through their wallet), not what was paid out. Use <a href="<?= $base ?>/admin/agent-ranking.php">Agent Ranking</a> and each agent's wallet for payouts and handovers.
  </p>
</div>
<?php
admin_footer();
