<?php
/**
 * admin/desk-day.php — one counter's day, ticket by ticket (21 Nov 2026).
 *
 * Owner: "NPJ counter ko hisab kitab chhuttai dekhiyos - daily ko sell data
 * counter-wise details niklan milos, statement: ticket kati, kasle katyo,
 * passenger kati". The money totals already live on Day memo / Counters; this
 * page is the LIST behind them for one desk and one day:
 *   every ticket cut at the desk (the desk frozen on the sale, else the clerk's
 *   profile desk), its time, PNR, passenger, seats, journey, the amount in the
 *   desk's own money (NPR as taken at a Nepal window, INR otherwise), how it was
 *   paid and WHO cut it; then a per-clerk summary and the totals. Print / PDF.
 *
 * Read-only. reports.view; a desk-isolated clerk sees only their own desk.
 *   ?day=YYYY-MM-DD (default today)   ?desk=<code> (default NPJ when it exists)
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/counterdesk.php';

$admin = admin_boot('reports.view');
$e     = static fn($v): string => Security::e((string) $v);
$day   = Security::isValidDate((string) ($_GET['day'] ?? '')) ? (string) $_GET['day'] : todayISO();
$desks = CounterDesk::all();
$desk  = strtoupper(trim((string) ($_GET['desk'] ?? '')));
$scope = method_exists('Auth', 'deskScopeCode') ? Auth::deskScopeCode() : null;
if ($scope !== null && $scope !== '') {
    $desk = strtoupper($scope);
}
if ($desk === '' || !isset($desks[$desk])) {
    $desk = isset($desks['NPJ']) ? 'NPJ' : (string) (array_key_first($desks) ?? '');
}
$d       = $desks[$desk] ?? [];
$isNpr   = strtoupper((string) ($d['currency'] ?? 'INR')) === 'NPR';
$hasProf = (static function (): bool { try { return Database::fetch("SHOW COLUMNS FROM admin_profiles LIKE 'counter_code'") !== null; } catch (Throwable) { return false; } })();
$hasBy   = (static function (): bool { try { return Database::fetch("SHOW COLUMNS FROM bookings LIKE 'booked_by_admin_id'") !== null; } catch (Throwable) { return false; } })();
$hasFx   = CounterDesk::hasColumn('bookings', 'fx_total');
$cutSql  = $hasBy ? 'COALESCE(b.booked_by_admin_id, b.sold_by_admin_id)' : 'b.sold_by_admin_id';
$parts   = [];
if (CounterDesk::stampColumn()) { $parts[] = "NULLIF(b.counter_code, '')"; }
if ($hasProf) { $parts[] = "NULLIF(ap.counter_code, '')"; }
$deskSql = 'COALESCE(' . implode(', ', array_merge($parts, ["''"])) . ')';
$seatSql = "GREATEST(1, COALESCE((SELECT SUM(l.seat_count) FROM booking_legs l WHERE l.booking_id = b.id AND l.leg_type = 'outbound'), 0))";

$rows = $desk === '' ? [] : Database::fetchAll(
    "SELECT b.id, b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, $seatSql AS seats,
            " . ($hasFx ? "b.fx_currency, b.fx_total" : "'' AS fx_currency, 0 AS fx_total") . ",
            (SELECT COALESCE(NULLIF(y.full_name, ''), y.username) FROM admins y WHERE y.id = $cutSql) AS cut_name,
            (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS pax,
            (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ', ') FROM booking_seats bs JOIN booking_legs l2 ON l2.id = bs.leg_id
              WHERE bs.booking_id = b.id AND l2.leg_type = 'outbound') AS seat_nos,
            (SELECT GROUP_CONCAT(DISTINCT p.method SEPARATOR '+') FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified') AS paid_by,
            (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified') AS paid_inr,
            (SELECT CONCAT(l.travel_date, '|', COALESCE(NULLIF(l.boarding_stop, ''), r.from_city, ''), '|', COALESCE(NULLIF(l.drop_stop, ''), r.to_city, ''))
               FROM booking_legs l LEFT JOIN schedules sc ON sc.id = l.schedule_id LEFT JOIN routes r ON r.id = sc.route_id
              WHERE l.booking_id = b.id ORDER BY l.leg_type = 'outbound' DESC, l.id LIMIT 1) AS trip
       FROM bookings b
       LEFT JOIN admin_profiles ap ON " . ($hasProf ? "ap.admin_id = $cutSql" : '1 = 0') . "
      WHERE b.created_at >= :f AND b.created_at < :t
        AND $cutSql IS NOT NULL
        AND $deskSql = :k
        AND b.status IN ('pending', 'confirmed', 'completed', 'cancelled')
      ORDER BY b.created_at",
    ['f' => $day . ' 00:00:00', 't' => date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00', 'k' => $desk]
);

$money = static function (array $r) use ($isNpr): string {
    if (strtoupper((string) $r['fx_currency']) === 'NPR' && (float) $r['fx_total'] > 0) {
        return 'रू ' . number_format((float) $r['fx_total'], 0);
    }
    return '₹' . number_format((float) $r['total_amount'], 0);
};
$byClerk = [];
$tot = ['tickets' => 0, 'pax' => 0, 'inr' => 0.0, 'npr' => 0.0, 'cancel' => 0, 'pending' => 0];
foreach ($rows as $r) {
    $who = (string) ($r['cut_name'] ?: '—');
    $byClerk[$who] ??= ['tickets' => 0, 'pax' => 0, 'inr' => 0.0, 'npr' => 0.0];
    if ((string) $r['status'] === 'cancelled') { $tot['cancel']++; continue; }
    if ((string) $r['status'] === 'pending') { $tot['pending']++; }
    $npr = strtoupper((string) $r['fx_currency']) === 'NPR' ? (float) $r['fx_total'] : 0.0;
    foreach ([&$tot, &$byClerk[$who]] as &$t) {
        $t['tickets']++;
        $t['pax'] += (int) $r['seats'];
        $t['inr'] += (float) $r['total_amount'];
        $t['npr'] += $npr;
    }
    unset($t);
}

admin_header('Counter day · ' . $desk, 'desk-day');
?>
<style>
.dd-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin:0 0 14px}
.dd-bar label{display:flex;flex-direction:column;font-size:12.5px;gap:4px}
.dd-bar input,.dd-bar select{min-height:44px;padding:0 10px;border-radius:10px;border:1px solid #cbd5e1;font-size:15px}
.dd-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin:0 0 14px}
.dd-kpi div{background:var(--card,#fff);border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px}
.dd-kpi b{display:block;font-size:22px}
.dd-t{width:100%;border-collapse:collapse;font-size:13.5px}
.dd-t th,.dd-t td{padding:8px 6px;border-bottom:1px solid #e2e8f0;text-align:left;vertical-align:top}
.dd-t tr.cx td{opacity:.5;text-decoration:line-through}
.dd-wrap{overflow-x:auto;background:var(--card,#fff);border:1px solid #e2e8f0;border-radius:12px;margin:0 0 14px}
@media print{.dd-bar,.no-print,header,nav,aside{display:none!important}.dd-wrap{border:0}}
</style>
<form class="dd-bar" method="get">
  <label>काउन्टर · Desk<select name="desk"<?= $scope ? ' disabled' : '' ?>>
    <?php foreach ($desks as $code => $x): ?><option value="<?= $e($code) ?>"<?= $code === $desk ? ' selected' : '' ?>><?= $e(CounterDesk::label((string) $code, (string) ($x['name'] ?? ''))) ?></option><?php endforeach; ?>
  </select></label>
  <label>मिति · Day<input type="date" name="day" value="<?= $e($day) ?>"></label>
  <button class="btn" type="submit">हेर्नुहोस् · Show</button>
  <a class="btn ghost" href="?desk=<?= $e($desk) ?>&amp;day=<?= $e(date('Y-m-d', strtotime($day . ' -1 day'))) ?>">← अघिल्लो</a>
  <a class="btn ghost" href="?desk=<?= $e($desk) ?>&amp;day=<?= $e(date('Y-m-d', strtotime($day . ' +1 day'))) ?>">अर्को →</a>
  <button class="btn ghost" type="button" onclick="window.print()">🖨️ Print / PDF</button>
  <a class="btn ghost" href="day-memo.php?day=<?= $e($day) ?>&amp;desk=<?= $e($desk) ?>">🧾 Day memo (cash)</a>
</form>

<h2 style="margin:0 0 10px">🧾 <?= $e(CounterDesk::label($desk, (string) ($d['name'] ?? ''))) ?> · <?= $e(formatDate($day, 'j M Y')) ?></h2>
<div class="dd-kpi">
  <div>टिकट · Tickets<b><?= $tot['tickets'] ?></b></div>
  <div>यात्रु · Passengers<b><?= $tot['pax'] ?></b></div>
  <?php if ($isNpr || $tot['npr'] > 0): ?><div>रकम · NPR<b>रू <?= number_format($tot['npr'], 0) ?></b></div><?php endif; ?>
  <div>Books (INR)<b>₹<?= number_format($tot['inr'], 0) ?></b></div>
  <div>Pending · बाँकी<b><?= $tot['pending'] ?></b></div>
  <div>Cancelled · रद्द<b><?= $tot['cancel'] ?></b></div>
</div>

<?php if ($byClerk !== []): ?>
<h3 style="margin:0 0 8px">कसले कति काट्यो · Who cut how many</h3>
<div class="dd-wrap"><table class="dd-t"><thead><tr><th>Clerk</th><th>Tickets</th><th>Passengers</th><?= $isNpr || $tot['npr'] > 0 ? '<th>NPR</th>' : '' ?><th>INR</th></tr></thead><tbody>
<?php foreach ($byClerk as $who => $c): ?>
  <tr><td><b><?= $e($who) ?></b></td><td><?= $c['tickets'] ?></td><td><?= $c['pax'] ?></td><?= $isNpr || $tot['npr'] > 0 ? '<td>रू ' . number_format($c['npr'], 0) . '</td>' : '' ?><td>₹<?= number_format($c['inr'], 0) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<h3 style="margin:0 0 8px">टिकट सूची · Tickets (<?= count($rows) ?>)</h3>
<div class="dd-wrap">
<?php if ($rows === []): ?><p class="muted" style="padding:12px">यो दिन यो काउन्टरबाट टिकट काटिएको छैन · No ticket cut at this desk on this day.</p>
<?php else: ?>
<table class="dd-t"><thead><tr><th>#</th><th>Time</th><th>PNR</th><th>Passenger</th><th>Journey</th><th>Seats</th><th>Amount</th><th>Paid</th><th>Cut by</th></tr></thead><tbody>
<?php $i = 0; foreach ($rows as $r): $i++; [$td, $bf, $dr] = array_pad(explode('|', (string) $r['trip']), 3, ''); $cx = (string) $r['status'] === 'cancelled'; ?>
  <tr<?= $cx ? ' class="cx"' : '' ?>>
    <td><?= $i ?></td>
    <td><?= $e(date('h:i A', strtotime((string) $r['created_at']))) ?></td>
    <td><a href="/admin/booking-view.php?pnr=<?= rawurlencode((string) $r['pnr']) ?>"><b><?= $e($r['pnr']) ?></b></a></td>
    <td><?= $e($r['pax'] ?: '—') ?><br><small class="muted"><?= $e($r['contact_phone']) ?></small></td>
    <td><?= $e($bf) ?> → <?= $e($dr) ?><?= $td !== '' ? '<br><small class="muted">' . $e(formatDate($td, 'j M Y')) . '</small>' : '' ?></td>
    <td><b><?= (int) $r['seats'] ?></b><?= $r['seat_nos'] ? '<br><small class="muted">' . $e($r['seat_nos']) . '</small>' : '' ?></td>
    <td><b><?= $e($money($r)) ?></b></td>
    <td><?= $cx ? 'रद्द · cancelled' : ((string) $r['status'] === 'pending' ? 'बाँकी · pending' : $e(strtoupper((string) ($r['paid_by'] ?: '—')))) ?></td>
    <td><?= $e($r['cut_name'] ?: '—') ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>
<p class="muted" style="font-size:12.5px">Tickets cut at this desk (the desk saved on the sale, else the clerk's desk). Cancelled tickets are listed but not counted. Cash in the drawer and closing: <a href="day-memo.php?day=<?= $e($day) ?>&amp;desk=<?= $e($desk) ?>">Day memo</a>.</p>
<?php admin_footer();
