<?php
/**
 * admin/ops-reports.php — two operations reports (15 Nov 2026, P4).
 *
 * CANCELLATIONS (?tab=cancel) — whole tickets cancelled in the date range
 * (bookings.status = 'cancelled', by bookings.cancelled_at):
 *   count, ticket value (total_amount) and refund due (refund_amount)
 *   by channel        BookingChannel::sqlExpr — the register's "Sold via"
 *   by notice         cancelled_at against the outbound departure
 *                     (travel_date + the trip's time, override first):
 *                     > 48 h, 24-48 h, < 24 h, after departure (DeskTools::cancelBand)
 *   by reason         bookings.cancel_reason as typed (same text = same row)
 *   by agent          bookings.sold_by_admin_id
 *   A seat cancelled out of a live ticket (per-seat cancel) leaves the
 *   ticket confirmed, so it is not counted here.
 *
 * FLEET USE (?tab=fleet) — per bus, by TRAVEL date: the bus is the one the
 * ticket shows, COALESCE(schedules.bus_id, routes.bus_id) (BookingService::
 * detail). Trips = departures not cancelled (a blocked date counts only if
 * it carries a sale); seats sold = booking_legs.seat_count of confirmed /
 * completed tickets; fill % = seats sold / the trips' seats
 * (schedules.total_seats); revenue = booking_legs.leg_total (as pnl.php).
 *
 * NPR tickets are turned into rupees at npr_per_inr, as on the dashboard.
 * Read-only. reports.view, office roles only; CSV needs reports.export.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/desktools.php';
$admin = admin_boot('reports.view');
if (Auth::isCounterAgent() || (string) ($admin['role'] ?? '') === 'counter') {
    admin_header('Operations reports', 'ops-reports');
    echo '<div class="flash bad">This is an office page. · यो अफिसको पेज हो।</div>';
    admin_footer();
    exit;
}

$e    = static fn($v): string => Security::e((string) $v);
$export = (string) ($_GET['export'] ?? '');
$tab  = $export === 'fleet' || ($export === '' && ($_GET['tab'] ?? '') === 'fleet') ? 'fleet' : 'cancel';
$to   = Security::isValidDate((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : todayISO();
$from = Security::isValidDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-01', (int) strtotime($to));
if ($from > $to) { [$from, $to] = [$to, $from]; }
$toEnd = addDaysISO($to, 1);
$peg   = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);
$peg   = $peg > 0 ? $peg : 1.6;
$inrOf = static fn(float $amt, string $cur): float => $cur === 'NPR' ? round($amt / $peg, 2) : $amt;
$safe  = static function (string $sql, array $p): array {
    try {
        return Database::fetchAll($sql, $p);
    } catch (Throwable $ex) {
        Logger::warning('ops-reports query failed: ' . $ex->getMessage());
        return [];
    }
};
$num = static fn(float $v): string => number_format($v, 2, '.', '');

/* ---- Cancellations --------------------------------------------------- */
$cx = ['channel' => [], 'band' => [], 'reason' => [], 'agent' => []];
$cxTot = ['n' => 0, 'amount' => 0.0, 'refund' => 0.0];
$cxList = [];
if ($tab === 'cancel') {
    $rows = $safe(
        'SELECT b.id, b.pnr, b.total_amount, b.refund_amount, b.currency, b.cancel_reason, b.cancelled_at, b.sold_by_admin_id,
                a.full_name, a.username, a.role,
                ' . BookingChannel::sqlExpr('b') . " AS ch,
                bl.travel_date, COALESCE(s.dep_time_override, r.dep_time) AS dep_time
           FROM bookings b
           LEFT JOIN admins a ON a.id = b.sold_by_admin_id
           LEFT JOIN booking_legs bl ON bl.id = (SELECT MIN(x.id) FROM booking_legs x WHERE x.booking_id = b.id AND x.leg_type = 'outbound')
           LEFT JOIN schedules s ON s.id = bl.schedule_id
           LEFT JOIN routes r ON r.id = s.route_id
          WHERE b.status = 'cancelled' AND b.cancelled_at >= :f AND b.cancelled_at < :t
          ORDER BY b.cancelled_at",
        ['f' => $from, 't' => $toEnd]
    );
    $add = static function (array &$bag, string $key, string $label, float $amt, float $ref): void {
        if (!isset($bag[$key])) { $bag[$key] = ['label' => $label, 'n' => 0, 'amount' => 0.0, 'refund' => 0.0]; }
        $bag[$key]['n']++;
        $bag[$key]['amount'] = round($bag[$key]['amount'] + $amt, 2);
        $bag[$key]['refund'] = round($bag[$key]['refund'] + $ref, 2);
    };
    foreach (array_keys(DeskTools::CANCEL_BANDS) as $bk) {
        $cx['band'][$bk] = ['label' => DeskTools::CANCEL_BANDS[$bk], 'n' => 0, 'amount' => 0.0, 'refund' => 0.0];
    }
    foreach ($rows as $r) {
        $cur  = (string) ($r['currency'] ?? 'INR');
        $amt  = $inrOf((float) $r['total_amount'], $cur);
        $ref  = $inrOf((float) $r['refund_amount'], $cur);
        $dep  = !empty($r['travel_date']) && !empty($r['dep_time']) ? strtotime($r['travel_date'] . ' ' . $r['dep_time']) : false;
        $cxTs = strtotime((string) $r['cancelled_at']);
        $band = DeskTools::cancelBand($dep === false ? null : (int) $dep, $cxTs === false ? null : (int) $cxTs);
        $tag  = (string) $r['ch'];
        $add($cx['channel'], $tag, BookingChannel::label($tag), $amt, $ref);
        $add($cx['band'], $band, DeskTools::CANCEL_BANDS[$band], $amt, $ref);
        $why = trim(preg_replace('/\s+/u', ' ', (string) ($r['cancel_reason'] ?? '')) ?? '');
        $add($cx['reason'], mb_strtolower($why), $why !== '' ? $why : '(no reason written)', $amt, $ref);
        $sid = (int) ($r['sold_by_admin_id'] ?? 0);
        $tail = (string) ($r['role'] ?? '') === 'agent' ? AgentWallet::agentCodeLabel($sid) : (string) ($r['role'] ?? '');
        $who  = $sid > 0
            ? (string) (($r['full_name'] ?? '') ?: ($r['username'] ?? ('#' . $sid))) . ($tail !== '' ? ' · ' . $tail : '')
            : 'Online · no seller';
        $add($cx['agent'], (string) $sid, $who, $amt, $ref);
        $cxTot['n']++;
        $cxTot['amount'] = round($cxTot['amount'] + $amt, 2);
        $cxTot['refund'] = round($cxTot['refund'] + $ref, 2);
        $cxList[] = ['pnr' => (string) $r['pnr'], 'at' => (string) $r['cancelled_at'],
            'dep' => $dep === false ? '' : date('Y-m-d H:i', (int) $dep),
            'hours' => $dep === false || $cxTs === false ? '' : (string) round(((int) $dep - (int) $cxTs) / 3600, 1),
            'band' => DeskTools::CANCEL_BANDS[$band], 'channel' => BookingChannel::label($tag), 'agent' => $who,
            'reason' => $why, 'amount' => $amt, 'refund' => $ref];
    }
    foreach (['channel', 'reason', 'agent'] as $g) {
        uasort($cx[$g], static fn(array $x, array $y): int => [$y['n'], $y['amount']] <=> [$x['n'], $x['amount']]);
    }
    $cx['band'] = array_filter($cx['band'], static fn(array $v, string $k): bool => $v['n'] > 0 || $k !== 'unknown', ARRAY_FILTER_USE_BOTH);
}

/* ---- Fleet use ------------------------------------------------------- */
$fleet = [];
$flTot = ['trips' => 0, 'cap' => 0, 'seats' => 0, 'tickets' => 0, 'revenue' => 0.0];
if ($tab === 'fleet') {
    $sold = "EXISTS (SELECT 1 FROM booking_legs xl JOIN bookings xb ON xb.id = xl.booking_id
                      WHERE xl.schedule_id = s.id AND xb.status IN ('confirmed','completed'))";
    foreach ($safe("SELECT COALESCE(s.bus_id, r.bus_id) AS bus_id, COUNT(*) AS trips, COALESCE(SUM(s.total_seats), 0) AS cap
                      FROM schedules s JOIN routes r ON r.id = s.route_id
                     WHERE s.travel_date BETWEEN :f AND :t AND s.status <> 'cancelled' AND (s.is_blocked = 0 OR $sold)
                     GROUP BY COALESCE(s.bus_id, r.bus_id)", ['f' => $from, 't' => $to]) as $r) {
        $bid = (int) ($r['bus_id'] ?? 0);
        $fleet[$bid] = ['trips' => (int) $r['trips'], 'cap' => (int) $r['cap'], 'seats' => 0, 'tickets' => 0, 'revenue' => 0.0];
    }
    foreach ($safe("SELECT COALESCE(s.bus_id, r.bus_id) AS bus_id, COALESCE(SUM(bl.seat_count), 0) AS seats, COUNT(DISTINCT b.id) AS tickets,
                           COALESCE(SUM(CASE WHEN b.currency = 'NPR' THEN bl.leg_total / :peg ELSE bl.leg_total END), 0) AS revenue
                      FROM booking_legs bl
                      JOIN schedules s ON s.id = bl.schedule_id
                      JOIN routes r ON r.id = s.route_id
                      JOIN bookings b ON b.id = bl.booking_id
                     WHERE b.status IN ('confirmed','completed') AND bl.travel_date BETWEEN :f AND :t AND s.status <> 'cancelled'
                     GROUP BY COALESCE(s.bus_id, r.bus_id)", ['f' => $from, 't' => $to, 'peg' => $peg]) as $r) {
        $bid = (int) ($r['bus_id'] ?? 0);
        $fleet[$bid] ??= ['trips' => 0, 'cap' => 0, 'seats' => 0, 'tickets' => 0, 'revenue' => 0.0];
        $fleet[$bid]['seats']   = (int) $r['seats'];
        $fleet[$bid]['tickets'] = (int) $r['tickets'];
        $fleet[$bid]['revenue'] = round((float) $r['revenue'], 2);
    }
    $buses = [];
    foreach ($safe('SELECT id, bus_number, bus_name, total_seats, is_active FROM buses', []) as $bu) {
        $buses[(int) $bu['id']] = $bu;
    }
    foreach ($buses as $bid => $bu) {   // an active bus with no trip still shows, as an honest 0 %
        if ((int) $bu['is_active'] === 1 && !isset($fleet[$bid])) {
            $fleet[$bid] = ['trips' => 0, 'cap' => 0, 'seats' => 0, 'tickets' => 0, 'revenue' => 0.0];
        }
    }
    foreach ($fleet as $bid => &$f) {
        $bu = $buses[$bid] ?? null;
        $f['id']    = $bid;
        $f['name']  = $bid === 0 ? 'No bus assigned · बस तोकिएको छैन' : ($bu !== null ? (string) $bu['bus_name'] : 'Bus #' . $bid);
        $f['num']   = $bu !== null ? Fleet::publicNumber((string) $bu['bus_number']) : '';
        $f['fill']  = $f['cap'] > 0 ? round($f['seats'] / $f['cap'] * 100, 1) : 0.0;
        $f['perTrip'] = $f['trips'] > 0 ? round($f['revenue'] / $f['trips'], 2) : 0.0;
        foreach (['trips', 'cap', 'seats', 'tickets', 'revenue'] as $k) { $flTot[$k] += $f[$k]; }
    }
    unset($f);
    $fleet = array_values($fleet);
    usort($fleet, static fn(array $x, array $y): int => [$x['id'] === 0 ? 1 : 0, -$x['revenue'], -$x['trips'], $x['name']] <=> [$y['id'] === 0 ? 1 : 0, -$y['revenue'], -$y['trips'], $y['name']]);
    $flTot['revenue'] = round($flTot['revenue'], 2);
}
$flFill = $flTot['cap'] > 0 ? round($flTot['seats'] / $flTot['cap'] * 100, 1) : 0.0;

/* ---- CSV --------------------------------------------------------------- */
if (in_array($export, ['cancel', 'cancel_list', 'fleet'], true)) {
    if (!Auth::can('reports.export')) {
        http_response_code(403);
        exit('Your role cannot download exports. / तपाईंको भूमिकाले export गर्न मिल्दैन।');
    }
    require __DIR__ . '/_export.php';
    Logger::audit('report.export', 'report', 'ops-' . $export, null, ['from' => $from, 'to' => $to], 'Operations report exported');
    $data = [];
    if ($export === 'cancel') {
        $names = ['channel' => 'Channel', 'band' => 'Notice before departure', 'reason' => 'Reason', 'agent' => 'Seller'];
        foreach ($names as $g => $gName) {
            foreach ($cx[$g] as $v) { $data[] = [$gName, $v['label'], $v['n'], $num($v['amount']), $num($v['refund'])]; }
        }
        $data[] = ['TOTAL', '', $cxTot['n'], $num($cxTot['amount']), $num($cxTot['refund'])];
        shg_export_csv('cancellations-' . $from . '_to_' . $to . '.csv', ['Group', 'Name', 'Cancellations', 'Ticket value (INR)', 'Refund due (INR)'], $data);
    }
    if ($export === 'cancel_list') {
        foreach ($cxList as $c) {
            $data[] = [$c['pnr'], $c['at'], $c['dep'], $c['hours'], $c['band'], $c['channel'], $c['agent'], $c['reason'], $num($c['amount']), $num($c['refund'])];
        }
        shg_export_csv('cancellations-list-' . $from . '_to_' . $to . '.csv',
            ['PNR', 'Cancelled at', 'Departure', 'Hours before', 'Notice', 'Channel', 'Seller', 'Reason', 'Ticket value (INR)', 'Refund due (INR)'], $data);
    }
    foreach ($fleet as $f) {
        $data[] = [$f['name'], $f['num'], $f['trips'], $f['cap'], $f['seats'], $f['tickets'], $f['fill'], $num($f['revenue']), $num($f['perTrip'])];
    }
    $data[] = ['TOTAL', '', $flTot['trips'], $flTot['cap'], $flTot['seats'], $flTot['tickets'], $flFill, $num($flTot['revenue']), ''];
    shg_export_csv('fleet-use-' . $from . '_to_' . $to . '.csv',
        ['Bus', 'Number', 'Trips', 'Seats offered', 'Seats sold', 'Tickets', 'Fill %', 'Revenue (INR)', 'Revenue per trip'], $data);
}

$qs = static fn(array $x): string => $e(http_build_query(['tab' => $tab, 'from' => $from, 'to' => $to] + $x));
$canCsv = Auth::can('reports.export');

admin_header('Operations reports', 'ops-reports');
?>
<style>
.op-k{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 16px}
.op-k div{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px;min-width:0}
.op-k small{display:block;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:700;color:var(--mut)}
.op-k b{font-size:20px;font-variant-numeric:tabular-nums;overflow-wrap:anywhere}
.op-f{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:0 0 14px}
.op-f input[type=date]{display:block;min-height:44px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);box-sizing:border-box;max-width:100%}
.op-f .btn,.op-tabs .dt-chip{min-height:44px;display:inline-flex;align-items:center}
.op-tabs{margin:0 0 12px}
.op-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;align-items:start}
.op-bar{height:8px;border-radius:99px;background:var(--line);overflow:hidden;min-width:60px;flex:1 1 60px;max-width:120px}
.op-bar i{display:block;height:100%;background:#1d4fa3}
.op-fill{display:inline-flex;align-items:center;gap:8px;justify-content:flex-end}
.op-csv{margin-left:auto;padding:5px 11px;font-size:12px;min-height:44px;display:inline-flex;align-items:center}
@media(max-width:900px){.op-grid{grid-template-columns:minmax(0,1fr)}}
@media(max-width:760px){.op-k{grid-template-columns:1fr 1fr}}
</style>

<div class="dt-chips op-tabs" role="tablist" aria-label="Report">
  <a class="dt-chip<?= $tab === 'cancel' ? ' on' : '' ?>" href="?<?= $e(http_build_query(['tab' => 'cancel', 'from' => $from, 'to' => $to])) ?>"<?= $tab === 'cancel' ? ' aria-current="true"' : '' ?>>🚫 Cancellations · रद्द</a>
  <a class="dt-chip<?= $tab === 'fleet' ? ' on' : '' ?>" href="?<?= $e(http_build_query(['tab' => 'fleet', 'from' => $from, 'to' => $to])) ?>"<?= $tab === 'fleet' ? ' aria-current="true"' : '' ?>>🚌 Bus use · बस प्रयोग</a>
</div>

<form method="get" class="op-f">
  <input type="hidden" name="tab" value="<?= $e($tab) ?>">
  <label class="muted" style="font-size:11px"><?= $tab === 'fleet' ? 'Travel from' : 'Cancelled from' ?><input type="date" name="from" value="<?= $e($from) ?>"></label>
  <label class="muted" style="font-size:11px">To<input type="date" name="to" value="<?= $e($to) ?>"></label>
  <button class="btn" type="submit">Show</button>
  <a class="btn ghost" href="?<?= $e(http_build_query(['tab' => $tab, 'from' => date('Y-m-01', strtotime(date('Y-m-01') . ' -1 month')), 'to' => date('Y-m-t', strtotime(date('Y-m-01') . ' -1 month'))])) ?>">Last month</a>
  <a class="btn ghost" href="?<?= $e(http_build_query(['tab' => $tab])) ?>">This month</a>
</form>

<?php if ($tab === 'cancel'): ?>
<div class="op-k">
  <div><small>Cancellations</small><b><?= (int) $cxTot['n'] ?></b></div>
  <div><small>Ticket value</small><b><?= $e(inr($cxTot['amount'])) ?></b></div>
  <div><small>Refund due</small><b><?= $e(inr($cxTot['refund'])) ?></b></div>
  <div><small>Under 24 h / after</small><b><?= (int) (($cx['band']['lt24']['n'] ?? 0) + ($cx['band']['after']['n'] ?? 0)) ?></b></div>
</div>
<?php
$cxTable = static function (string $title, string $col, array $bag) use ($e, $cxTot): void { ?>
  <div class="panel">
    <h2><?= $e($title) ?></h2>
    <?php if ($bag === [] || $cxTot['n'] === 0): ?>
      <p class="muted" style="padding:0 18px 16px;margin:0">No cancellation in these dates. · यी मितिमा रद्द छैन।</p>
    <?php else: ?>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th><?= $e($col) ?></th><th class="num">Count</th><th class="num">Share</th><th class="num">Ticket value</th><th class="num">Refund due</th></tr></thead>
      <tbody>
      <?php foreach ($bag as $v): ?>
        <tr>
          <td data-label="<?= $e($col) ?>"><b><?= $e($v['label']) ?></b></td>
          <td data-label="Count" class="num"><?= (int) $v['n'] ?></td>
          <td data-label="Share" class="num"><?= $cxTot['n'] > 0 ? $e(round($v['n'] / $cxTot['n'] * 100, 1) . ' %') : '—' ?></td>
          <td data-label="Ticket value" class="num"><?= $e(inr($v['amount'])) ?></td>
          <td data-label="Refund due" class="num"><?= $e(inr($v['refund'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>
<?php }; ?>
<?php if ($canCsv): ?>
<p style="display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px">
  <a class="btn ghost" href="?<?= $qs(['export' => 'cancel']) ?>">⬇ CSV · summary</a>
  <a class="btn ghost" href="?<?= $qs(['export' => 'cancel_list']) ?>">⬇ CSV · every cancellation</a>
</p>
<?php endif; ?>
<div class="op-grid">
  <?php $cxTable('⏱️ By notice before departure', 'Notice', $cx['band']); ?>
  <?php $cxTable('🔀 By channel · कहाँबाट', 'Channel', $cx['channel']); ?>
  <?php $cxTable('🧑‍💼 By seller · एजेन्ट', 'Seller', $cx['agent']); ?>
  <?php $cxTable('📝 By reason · कारण', 'Reason', $cx['reason']); ?>
</div>
<p class="muted" style="font-size:12px;margin:4px 2px 12px">Whole tickets cancelled in these dates (by the day of the cancel). Notice = time from the cancel to the outbound departure. A seat cancelled out of a ticket that still travels is not counted. Refund due is what the cancel parked for the owner to settle by hand. NPR tickets are shown in rupees at <?= $e(rtrim(rtrim(number_format($peg, 4, '.', ''), '0'), '.')) ?> NPR per ₹1.</p>

<?php else: ?>
<div class="op-k">
  <div><small>Trips</small><b><?= (int) $flTot['trips'] ?></b></div>
  <div><small>Seats sold</small><b><?= (int) $flTot['seats'] ?> / <?= (int) $flTot['cap'] ?></b></div>
  <div><small>Seat fill</small><b><?= $e($flFill . ' %') ?></b></div>
  <div><small>Revenue</small><b><?= $e(inr($flTot['revenue'])) ?></b></div>
</div>
<div class="panel">
  <h2>🚌 Bus use · <?= $e(formatDate($from)) ?> – <?= $e(formatDate($to)) ?><?php if ($canCsv): ?> <a class="btn ghost op-csv" href="?<?= $qs(['export' => 'fleet']) ?>">⬇ CSV</a><?php endif; ?></h2>
  <?php if ($fleet === []): ?>
    <p class="muted" style="padding:0 18px 16px;margin:0">No trip in these dates. · यी मितिमा यात्रा छैन।</p>
  <?php else: ?>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Bus</th><th class="num">Trips</th><th class="num">Seats sold</th><th class="num">Seat fill</th><th class="num">Revenue</th><th class="num">Per trip</th></tr></thead>
    <tbody>
    <?php foreach ($fleet as $f): ?>
      <tr>
        <td data-label="Bus"><b><?= $e($f['name']) ?></b><?= $f['num'] !== '' ? ' <span class="muted mono" style="font-size:11px">' . $e($f['num']) . '</span>' : '' ?></td>
        <td data-label="Trips" class="num"><?= (int) $f['trips'] ?></td>
        <td data-label="Seats sold" class="num"><?= (int) $f['seats'] ?> / <?= (int) $f['cap'] ?></td>
        <td data-label="Seat fill" class="num"><span class="op-fill"><span class="op-bar" aria-hidden="true"><i style="width:<?= $e(min(100, max(0, $f['fill']))) ?>%"></i></span><?= $e($f['fill'] . ' %') ?></span></td>
        <td data-label="Revenue" class="num"><?= $e(inr($f['revenue'])) ?></td>
        <td data-label="Per trip" class="num"><?= $f['trips'] > 0 ? $e(inr($f['perTrip'])) : '<span class="muted">—</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <th>Total · जम्मा</th><th class="num"><?= (int) $flTot['trips'] ?></th><th class="num"><?= (int) $flTot['seats'] ?> / <?= (int) $flTot['cap'] ?></th>
      <th class="num"><?= $e($flFill . ' %') ?></th><th class="num"><?= $e(inr($flTot['revenue'])) ?></th><th></th>
    </tr></tfoot>
  </table></div>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;padding:0 16px 12px;margin:0">By travel date. A trip is a departure that was not cancelled (a date switched OFF counts only if it carries a sale). Seats sold and revenue come from confirmed and completed tickets on that bus's departures; fill = seats sold ÷ seats the trips offered.</p>
</div>
<?php endif; ?>
<?php admin_footer();
