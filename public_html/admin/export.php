<?php
/**
 * admin/export.php — stream the bookings list as a CSV (opens in Excel).
 *
 * Honours the same q + status filters as bookings.php, so "Export CSV" on
 * that page downloads exactly the filtered view (just without the 200-row
 * display cap — up to 10k). Read-only; outputs CSV only (no admin chrome).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
admin_boot('bookings.view');

$q      = Security::clean($_GET['q'] ?? '', 60);
$status = Security::clean($_GET['status'] ?? '', 20);
$valid  = ['pending', 'confirmed', 'cancelled', 'rejected', 'expired'];

$where  = [];
$params = [];
if ($q !== '') {
    $where[]     = '(b.pnr LIKE :q OR b.contact_phone LIKE :q OR b.contact_email LIKE :q)';
    $params['q'] = '%' . $q . '%';
}
if (in_array($status, $valid, true)) {
    $where[]      = 'b.status = :st';
    $params['st'] = $status;
}

$sql = "SELECT b.pnr, b.status, b.booking_mode, b.cabin_label,
               r.from_city, r.to_city, bl.travel_date, bl.boarding_stop, bl.drop_stop,
               b.contact_phone, b.contact_email, b.total_amount, b.currency,
               b.created_at, b.confirmed_at,
               (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id=b.id) AS seat_count,
               (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
                  FROM booking_seats bs WHERE bs.booking_id=b.id) AS seats,
               (SELECT bp.full_name FROM booking_passengers bp
                 WHERE bp.booking_id=b.id AND bp.is_primary=1 LIMIT 1) AS primary_passenger,
               (SELECT p.method FROM payments p
                 WHERE p.booking_id=b.id ORDER BY p.id DESC LIMIT 1) AS pay_method
          FROM bookings b
          LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
          LEFT JOIN schedules s ON s.id=bl.schedule_id
          LEFT JOIN routes r ON r.id=s.route_id"
     . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY b.id DESC LIMIT 10000';

$rows = Database::fetchAll($sql, $params);

$fname = 'SHG-bookings-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Unicode (Nepali/Hindi) names

fputcsv($out, [
    'PNR', 'Status', 'Route', 'Travel date', 'Boarding', 'Drop', 'Seats',
    'Seat count', 'Passenger', 'Phone', 'Email', 'Cabin', 'Mode',
    'Amount', 'Currency', 'Payment', 'Created', 'Confirmed',
]);

foreach ($rows as $b) {
    fputcsv($out, [
        $b['pnr'],
        $b['status'],
        ($b['from_city'] ?? '') . ' -> ' . ($b['to_city'] ?? ''),
        $b['travel_date'] ?? '',
        $b['boarding_stop'] ?? '',
        $b['drop_stop'] ?? '',
        $b['seats'] ?? '',
        (int) $b['seat_count'],
        $b['primary_passenger'] ?? '',
        $b['contact_phone'] ?? '',
        $b['contact_email'] ?? '',
        $b['cabin_label'] ?? '',
        $b['booking_mode'] ?? '',
        $b['total_amount'],
        $b['currency'],
        $b['pay_method'] ?? '',
        $b['created_at'] ?? '',
        $b['confirmed_at'] ?? '',
    ]);
}
fclose($out);
exit;
