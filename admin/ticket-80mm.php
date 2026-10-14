<?php
/**
 * admin/ticket-80mm.php?pnr=… — the counter's 80 mm thermal receipt
 * (15 Oct 2026, SmartBook v5 §11). Opens the print dialog by itself; the
 * A4 ticket stays the PDF (download-ticket.php). Same facts as the ticket:
 * PNR + verify QR (the scanner reads it), route, date, pickup + reporting
 * time, berths, total, payment state, the one cancellation policy and the
 * helpline. Staff only; an agent prints only what they sold.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/sitefacts.php';
require_once INCLUDE_PATH . '/seats.php';

$e   = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$pnr = strtoupper(trim((string) ($_GET['pnr'] ?? '')));
$b   = Security::isValidPnr($pnr) ? BookingService::findByPnr($pnr) : null;
$scope = Auth::bookingScopeAdminId();
if ($b === null || ($scope !== null && (int) $b['sold_by_admin_id'] !== $scope)) {
    http_response_code(404);
    exit('Ticket not found.');
}
if ((string) $b['status'] !== 'confirmed') {
    exit('This booking is not confirmed yet — no ticket to print.');
}
$leg = Database::fetch(
    "SELECT l.*, r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) AS dep, s.id AS sid
       FROM booking_legs l JOIN schedules s ON s.id = l.schedule_id JOIN routes r ON r.id = s.route_id
      WHERE l.booking_id = :b ORDER BY l.id LIMIT 1",
    ['b' => (int) $b['id']]
);
$pax   = Database::fetchAll('SELECT full_name, seat_no, gender FROM booking_passengers WHERE booking_id = :b ORDER BY is_primary DESC, id', ['b' => (int) $b['id']]);
$seats = array_values(array_filter(array_map(static fn(array $p): string => (string) $p['seat_no'], $pax)));
$coach = $leg !== null ? Seats::coachForSchedule((int) $leg['sid']) : 'sleeper';
$mode  = (string) ($b['booking_mode'] ?? 'sharing') === 'private' ? 'private' : 'sharing';
$paid  = (bool) Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND status = 'verified'", ['b' => (int) $b['id']]);
$disp  = $leg !== null ? Boarding::stopDisplay((string) $leg['boarding_stop']) : ['name' => '', 'time' => null];
$time  = $leg !== null && $leg['boarding_time'] ? substr((string) $leg['boarding_time'], 0, 5) : (string) ($disp['time'] ?? '');
$report = '';
if (preg_match('/^(\d{2}):(\d{2})$/', $time, $m) === 1) {
    $mins   = ((int) $m[1] * 60 + (int) $m[2] - Settings::getInt('report_before_min', 60) + 1440) % 1440;
    $report = sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60);
}
$F  = SiteFacts::all();
$qr = QrCode::dataUri(Ticket::qrPayload($b, $seats), 4, 2);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><title><?= $e($pnr) ?> · 80 mm</title>
<style>
@page{size:80mm auto;margin:3mm}
*{box-sizing:border-box}
body{margin:0;width:74mm;font:12px/1.35 "Segoe UI",Arial,"Noto Sans Devanagari",sans-serif;color:#000}
h1{font-size:15px;margin:0;text-align:center;letter-spacing:.04em}
.c{text-align:center}.sm{font-size:10px}.b{font-weight:700}
.pnr{font-size:18px;font-weight:800;text-align:center;letter-spacing:.05em;margin:4px 0}
hr{border:0;border-top:1px dashed #000;margin:5px 0}
table{width:100%;border-collapse:collapse}td{padding:1px 0;vertical-align:top}td:last-child{text-align:right}
.qr{display:block;margin:4px auto;width:34mm;height:34mm;image-rendering:pixelated}
.big{font-size:16px;font-weight:800}
.box{border:1px solid #000;padding:3px;text-align:center;font-weight:800;margin:4px 0}
@media screen{body{margin:10px auto;box-shadow:0 0 0 1px #ccc;padding:6px}}
</style></head>
<body onload="setTimeout(function(){window.print()},250)">
<h1><?= $e(strtoupper((string) $F['brand']['name'])) ?></h1>
<div class="c sm">CIN <?= $e($F['brand']['cin']) ?> · <?= $e($F['support']['phone']) ?></div>
<hr>
<div class="pnr"><?= $e($pnr) ?></div>
<img class="qr" src="<?= $e($qr) ?>" alt="Verify QR">
<?php if ($leg !== null): ?>
<table>
  <tr><td>Route</td><td class="b"><?= $e($leg['from_city'] . ' → ' . $leg['to_city']) ?></td></tr>
  <tr><td>Date</td><td class="b"><?= $e(formatDate((string) $leg['travel_date'], 'D j M Y')) ?></td></tr>
  <tr><td>Boarding</td><td class="b"><?= $e((string) $disp['name']) ?> <?= $e($time) ?></td></tr>
  <?php if ($report !== ''): ?><tr><td>Report by</td><td class="b"><?= $e($report) ?></td></tr><?php endif; ?>
</table>
<?php endif; ?>
<hr>
<table>
<?php foreach ($pax as $p): ?>
  <tr><td><?= $e($p['full_name']) ?></td><td class="b"><?= $e(Seats::displayLabel((string) $p['seat_no'], $coach, $mode)) ?></td></tr>
<?php endforeach; ?>
</table>
<hr>
<table><tr><td>Total</td><td class="big"><?= $e(inr((float) $b['total_amount'])) ?></td></tr></table>
<div class="box"><?= $paid ? 'PAID' : ((int) $b['is_cod'] === 1 ? 'CASH — PAY AT BOARDING' : 'PAYMENT PENDING') ?></div>
<div class="sm"><?= $e(Fare::refundPolicyLine('en')) ?></div>
<hr>
<div class="c sm">Carry a photo ID · Rupaidiha ⇄ Jamunaha border<br>Printed <?= $e(date('j M Y H:i')) ?> · <?= $e((string) ($admin['username'] ?? '')) ?></div>
</body></html>
