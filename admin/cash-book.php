<?php
/**
 * admin/cash-book.php — the Cash book / हिसाब-किताब (15 Oct 2026, SmartBook v5 §7).
 *
 * Every pay-at-boarding (Cash / COD) ticket the SmartBook card, QuickBot or
 * WhatsApp made, in one register:
 *   Pending   — confirmed, cash not taken yet (payments.status cod_pending)
 *   Collected — the desk tapped Collected (payments verified: who, when)
 *   Cancelled — a cash ticket cancelled before the money came in
 *   No-show   — the bus has left and the cash was never collected
 *
 * One tap "Collected" = BookingService::settleCod() (the same call the
 * Payments page makes), so the ticket re-draws as PAID and the bell rings
 * once. Daily totals per collector and per seller, Excel (CSV) export and a
 * print view (Save as PDF). Nothing here cancels or refunds anything (the
 * office does that by hand).
 *
 * Who sees what: the office sees every desk; a counter login with desk
 * isolation sees its own desk; an agent sees the tickets they sold (view
 * only — collecting is a payments.verify action).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
require_once INCLUDE_PATH . '/boarding.php';

$e        = static fn($v): string => Security::e((string) $v);
$me       = (int) $admin['id'];
$canTake  = Auth::can('payments.verify');
$scopeBy  = Auth::bookingScopeAdminId();     // an agent: own sales only
$scopeDesk = Auth::deskScopeCode();          // a counter with desk isolation
$flash    = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } elseif (!$canTake) {
        $flash = ['bad', 'Only a counter or the office records cash.'];
    } else {
        try {
            // The row's own button wins over any ticked boxes (a one-tap collect).
            $ids = isset($_POST['booking_id'])
                ? [(int) $_POST['booking_id']]
                : array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
            $note = Security::clean((string) ($_POST['note'] ?? ''), 255);
            $done = 0;
            foreach (array_slice($ids, 0, 50) as $bid) {
                $row = Database::fetch('SELECT id, sold_by_admin_id, counter_code FROM bookings WHERE id = :b AND is_cod = 1', ['b' => $bid]);
                if ($row === null || ($scopeDesk !== null && (string) $row['counter_code'] !== $scopeDesk)) {
                    continue;
                }
                BookingService::settleCod($bid, $me, $note !== '' ? $note : 'Cash book: collected');
                $done++;
            }
            Logger::audit('cashbook.collect', 'booking', implode(',', $ids), null, ['count' => $done], 'by admin #' . $me);
            $flash = ['ok', $done . ' cash ticket(s) marked Collected. / नगद जम्मा भयो।'];
        } catch (Throwable $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
    }
}

/* ---- filters ---------------------------------------------------------- */
$from   = Security::isValidDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : addDaysISO(todayISO(), -7);
$to     = Security::isValidDate((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : addDaysISO(todayISO(), 30);
$status = in_array($_GET['status'] ?? '', ['pending', 'collected', 'cancelled', 'noshow', 'all'], true) ? (string) $_GET['status'] : 'pending';
$q      = Security::clean((string) ($_GET['q'] ?? ''), 40);

$where  = ['b.is_cod = 1', 'l.travel_date BETWEEN :f AND :t'];
$params = ['f' => $from, 't' => $to];
if ($scopeBy !== null) { $where[] = 'b.sold_by_admin_id = :me'; $params['me'] = $scopeBy; }
if ($scopeDesk !== null) { $where[] = 'b.counter_code = :desk'; $params['desk'] = $scopeDesk; }
if ($q !== '') { $where[] = '(b.pnr LIKE :q OR b.contact_phone LIKE :q2)'; $params['q'] = '%' . $q . '%'; $params['q2'] = '%' . preg_replace('/\D/', '', $q) . '%'; }

$rows = Database::fetchAll(
    "SELECT b.id, b.pnr, b.status, b.total_amount, b.contact_phone, b.counter_code, b.source, b.created_at, b.sold_by_admin_id,
            l.travel_date, l.boarding_stop, l.boarding_time,
            p.status AS pay_status, p.verified_by, p.verified_at, p.admin_note,
            (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS lead_name,
            (SELECT COUNT(*) FROM booking_passengers bp2 WHERE bp2.booking_id = b.id) AS pax,
            ca.full_name AS collector, ca.username AS collector_user,
            sa.full_name AS seller,
            COALESCE(s.status, 'scheduled') AS trip_status
       FROM bookings b
       JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
  LEFT JOIN schedules s ON s.id = l.schedule_id
  LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
  LEFT JOIN admins ca ON ca.id = p.verified_by
  LEFT JOIN admins sa ON sa.id = b.sold_by_admin_id
      WHERE " . implode(' AND ', $where) . "
   ORDER BY l.travel_date, l.boarding_time, b.id
      LIMIT 2000",
    $params
);

$kind = static function (array $r): string {
    if (in_array($r['status'], ['cancelled', 'rejected', 'expired'], true)) {
        return 'cancelled';
    }
    if (($r['pay_status'] ?? '') === 'verified') {
        return 'collected';
    }
    $gone = in_array($r['trip_status'], ['departed', 'arrived'], true) || (string) $r['travel_date'] < addDaysISO(todayISO(), -1);
    return $gone ? 'noshow' : 'pending';
};
$sum = ['pending' => [0, 0.0], 'collected' => [0, 0.0], 'cancelled' => [0, 0.0], 'noshow' => [0, 0.0]];
foreach ($rows as $r) {
    $k = $kind($r);
    $sum[$k][0]++;
    $sum[$k][1] += (float) $r['total_amount'];
}
$shown = $status === 'all' ? $rows : array_values(array_filter($rows, static fn(array $r): bool => $kind($r) === $status));

/* Daily totals: collected cash per day per collector and per seller. */
$daily = [];
foreach ($rows as $r) {
    if ($kind($r) !== 'collected' || $r['verified_at'] === null) {
        continue;
    }
    $day = substr((string) $r['verified_at'], 0, 10);
    $who = (string) ($r['collector'] ?: $r['collector_user'] ?: 'Office');
    $desk = (string) ($r['counter_code'] ?: 'Online');
    $daily[$day][$who . ' · ' . $desk] = ($daily[$day][$who . ' · ' . $desk] ?? 0) + (float) $r['total_amount'];
}
krsort($daily);

/* Excel (CSV) */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cash-book-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['PNR', 'Travel date', 'Boarding', 'Lead passenger', 'Passengers', 'Mobile', 'Amount INR', 'State', 'Collected by', 'Collected at', 'Desk', 'Seller', 'Booked at']);
    foreach ($shown as $r) {
        fputcsv($out, [$r['pnr'], $r['travel_date'], Boarding::stopDisplay((string) $r['boarding_stop'])['name'] ?? '', $r['lead_name'], $r['pax'],
            $r['contact_phone'], $r['total_amount'], $kind($r), $r['collector'] ?: $r['collector_user'], $r['verified_at'], $r['counter_code'], $r['seller'], $r['created_at']]);
    }
    fclose($out);
    exit;
}

admin_header('Cash book', 'cash-book');
$base = '';
$label = ['pending' => '⏳ Pending', 'collected' => '✅ Collected', 'cancelled' => '✖ Cancelled', 'noshow' => '🚌 No-show', 'all' => 'All'];
?>
<style>
.cb-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:0 0 12px}
.cb-cards a{display:block;text-decoration:none;color:inherit;border:1px solid var(--line,#e0e8f2);border-radius:14px;padding:12px;background:var(--card,#fff)}
.cb-cards a.on{outline:2px solid #185bed}
.cb-cards b{display:block;font-size:22px}
.cb-cards small{color:var(--muted,#637491)}
.cb-tbl td,.cb-tbl th{vertical-align:middle}
.cb-st{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:700}
.cb-pending{background:#fff4e5;color:#7a4b00}.cb-collected{background:#e7f8f1;color:#0b7a58}.cb-cancelled{background:#f1f3f6;color:#556}.cb-noshow{background:#fde8ea;color:#a3283c}
.cb-go{min-height:40px}
@media print{.toolbar,.cb-noprint,.scrim,nav,header{display:none!important}}
</style>
<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<p style="margin:0 0 10px;color:var(--muted,#637491)">Pay-at-boarding (Cash) tickets — <?= $e(formatDate($from)) ?> → <?= $e(formatDate($to)) ?> (travel date)<?= $scopeBy !== null ? ' · your sales' : '' ?><?= $scopeDesk !== null ? ' · desk ' . $e($scopeDesk) : '' ?></p>

<div class="cb-cards">
<?php foreach (['pending', 'collected', 'noshow', 'cancelled'] as $k): ?>
  <a href="?<?= $e(http_build_query(['from' => $from, 'to' => $to, 'status' => $k, 'q' => $q])) ?>" class="<?= $status === $k ? 'on' : '' ?>">
    <small><?= $e($label[$k]) ?></small><b><?= $e(inr($sum[$k][1])) ?></b><small><?= (int) $sum[$k][0] ?> ticket(s)</small></a>
<?php endforeach; ?>
</div>

<form method="get" class="toolbar cb-noprint">
  <label style="font-size:12px;font-weight:700">From <input type="date" name="from" value="<?= $e($from) ?>"></label>
  <label style="font-size:12px;font-weight:700">To <input type="date" name="to" value="<?= $e($to) ?>"></label>
  <select name="status"><?php foreach ($label as $k => $v): ?><option value="<?= $e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= $e($v) ?></option><?php endforeach; ?></select>
  <input type="search" name="q" value="<?= $e($q) ?>" placeholder="PNR or mobile">
  <button class="btn ghost" type="submit">Show</button>
  <a class="btn ghost" href="?<?= $e(http_build_query(['from' => $from, 'to' => $to, 'status' => $status, 'q' => $q, 'export' => 'csv'])) ?>">⬇ Excel (CSV)</a>
  <button class="btn ghost" type="button" onclick="window.print()">🖨 Print / PDF</button>
</form>

<form method="post" class="panel" id="cbForm">
  <?= Security::csrfField() ?>
  <h2><?= $e($label[$status]) ?> · <?= count($shown) ?></h2>
  <?php if ($shown === []): ?>
    <p style="color:var(--muted,#637491)">Nothing here for these dates.</p>
  <?php else: ?>
  <div style="overflow-x:auto"><table class="cb-tbl">
    <thead><tr><?php if ($canTake && $status === 'pending'): ?><th class="cb-noprint"><input type="checkbox" onclick="document.querySelectorAll('.cb-pick').forEach(function(c){c.checked=this.checked}.bind(this))" aria-label="All"></th><?php endif; ?>
      <th>PNR</th><th>Travel</th><th>Boarding</th><th>Passenger</th><th>Amount</th><th>State</th><th>Collected by / when</th><th>Desk · seller</th><?php if ($canTake): ?><th class="cb-noprint"></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($shown as $r): $k = $kind($r); ?>
      <tr>
        <?php if ($canTake && $status === 'pending'): ?><td class="cb-noprint"><input type="checkbox" class="cb-pick" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="Pick <?= $e($r['pnr']) ?>"></td><?php endif; ?>
        <td><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= $e(rawurlencode((string) $r['pnr'])) ?>"><?= $e($r['pnr']) ?></a></td>
        <td><?= $e(formatDate((string) $r['travel_date'], 'j M')) ?></td>
        <td><?= $e((string) (Boarding::stopDisplay((string) $r['boarding_stop'])['name'] ?? '')) ?><?= $r['boarding_time'] ? ' ' . $e(substr((string) $r['boarding_time'], 0, 5)) : '' ?></td>
        <td><?= $e($r['lead_name']) ?><?= (int) $r['pax'] > 1 ? ' +' . ((int) $r['pax'] - 1) : '' ?><br><small><?= $e($r['contact_phone']) ?></small></td>
        <td><b><?= $e(inr((float) $r['total_amount'])) ?></b></td>
        <td><span class="cb-st cb-<?= $e($k) ?>"><?= $e($label[$k]) ?></span></td>
        <td><?= $k === 'collected' ? $e($r['collector'] ?: $r['collector_user'] ?: 'Office') . '<br><small>' . $e(date('j M H:i', (int) strtotime((string) $r['verified_at']))) . '</small>' : '—' ?></td>
        <td><?= $e($r['counter_code'] ?: 'Online') ?><?= $r['seller'] ? '<br><small>' . $e($r['seller']) . '</small>' : '' ?></td>
        <?php if ($canTake): ?><td class="cb-noprint"><?php if ($k === 'pending' || $k === 'noshow'): ?>
          <button class="btn ok cb-go" type="submit" name="booking_id" value="<?= (int) $r['id'] ?>" onclick="return confirm('Cash <?= $e(inr((float) $r['total_amount'])) ?> received for <?= $e($r['pnr']) ?>?')">✅ Collected</button>
        <?php endif; ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($canTake && $status === 'pending'): ?>
    <p class="cb-noprint" style="margin-top:10px"><input type="text" name="note" placeholder="Note (optional)" maxlength="120" style="min-height:40px">
      <button class="btn ok" type="submit" onclick="if(!document.querySelector('.cb-pick:checked')){alert('Tick the tickets first.');return false;} return confirm('Mark the ticked tickets Collected?')">✅ Mark ticked as Collected</button></p>
  <?php endif; ?>
  <?php endif; ?>
</form>

<?php if ($daily !== []): ?>
<div class="panel">
  <h2>Daily cash totals (collected)</h2>
  <table><thead><tr><th>Day</th><th>Collector · desk</th><th>Cash</th></tr></thead><tbody>
  <?php foreach ($daily as $day => $list): arsort($list); foreach ($list as $who => $amt): ?>
    <tr><td><?= $e(formatDate($day, 'D j M')) ?></td><td><?= $e($who) ?></td><td><b><?= $e(inr($amt)) ?></b></td></tr>
  <?php endforeach; endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
<?php admin_footer();
