<?php
/**
 * admin/export.php — stream the bookings list as a CSV (opens in Excel).
 *
 * Honours the same q + status filters as bookings.php, so "Export CSV" on
 * that page downloads exactly the filtered view (just without the 200-row
 * display cap — up to 10k). Read-only; outputs CSV only (no admin chrome).
 *
 * It must also honour the same agent scope. This file is the sharper edge of
 * the two: an unscoped export hands a counter agent one file containing every
 * passenger's name, phone, email and fare the company has ever taken.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

/*
 * A per-customer trip-history PDF (customer=<phone>&format=pdf) is triggered
 * from admin/customers.php — that page is gated by customers.view, so this
 * branch matches that permission and short-circuits before the normal
 * bookings export code path runs.
 */
$customerParam = Security::clean($_GET['customer'] ?? '', 20);
$formatParam   = Security::clean($_GET['format'] ?? '', 10);

if ($customerParam !== '' && $formatParam === 'pdf') {
    admin_boot('customers.view');
    $fromCust = Security::clean($_GET['from'] ?? '', 10);
    $toCust   = Security::clean($_GET['to'] ?? '', 10);
    $isDateC  = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
    $path = ReportPdf::customerReport(
        Database::pdo(),
        $customerParam,
        $isDateC($fromCust) ? $fromCust : null,
        $isDateC($toCust) ? $toCust : null
    );
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="customer-' . preg_replace('/[^0-9A-Za-z_-]/', '', $customerParam) . '-' . date('Y-m-d') . '.pdf"');
    header('Content-Length: ' . (string) (filesize($path) ?: 0));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
    exit;
}

admin_boot('bookings.view');

/* 4 Oct 2026: an agent's download is scoped to their own sales by
   Auth::bookingScopeAdminId(), but every other role holding bookings.view
   (counter, support) is NOT scoped - so this file handed one ticket window
   the phone and e-mail of every passenger the company has carried. Anyone
   unscoped must hold reports.export, and every download leaves an audit row. */
if (Auth::bookingScopeAdminId() === null && !Auth::can('reports.export') && !Auth::isSuperadmin()) {
    admin_boot('reports.export');
}
Logger::audit('bookings.export', 'export', (string) ($_GET['format'] ?? 'csv'), null,
    ['filters' => array_filter(array_map(static fn($v): string => is_string($v) ? mb_substr($v, 0, 60) : '', $_GET))],
    'bookings export downloaded');

$q      = Security::clean($_GET['q'] ?? '', 60);
$status = Security::clean($_GET['status'] ?? '', 20);
$from   = Security::clean($_GET['from'] ?? '', 10);
$to     = Security::clean($_GET['to'] ?? '', 10);
$valid  = ['pending', 'confirmed', 'completed', 'cancelled', 'rejected', 'expired'];   // 5 Oct 2026: 'completed' was missing - that export returned every status
$isDate = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

$scopeId = Auth::bookingScopeAdminId();

/* 3 Sep 2026: bookings.php passes ELEVEN filters in its export links, but this
   file read only four - so "Travel from / to", route, source, agent, bus and
   payment status were silently dropped and the download was the whole history
   (the reported "export date bug"). Same parsing + SQL fragments as
   bookings.php so the file always matches the screen. */
$routeFilter     = Security::clean($_GET['route'] ?? '', 20);
$travelFrom      = Security::clean($_GET['travel_from'] ?? '', 10);
$travelTo        = Security::clean($_GET['travel_to'] ?? '', 10);
$sourceFilter    = Security::clean($_GET['source'] ?? '', 20);
$agentFilter     = Security::clean($_GET['agent'] ?? '', 20);
$busFilter       = Security::clean($_GET['bus'] ?? '', 40);
$payStatusFilter = Security::clean($_GET['pay_status'] ?? '', 20);
$validPayStatus  = ['pending', 'verified', 'rejected', 'refunded', 'cod_pending'];
$extraFilters    = [
    'route' => $routeFilter, 'travel_from' => $travelFrom, 'travel_to' => $travelTo, 'source' => $sourceFilter,
    'agent' => $agentFilter, 'bus' => $busFilter, 'pay_status' => $payStatusFilter,
];


require_once __DIR__ . '/_booking_filters.php';
require_once __DIR__ . '/_export.php';

$where  = [];
$params = [];
if ($scopeId !== null) {
    $where[] = 'b.sold_by_admin_id = :scope';
    $params['scope'] = $scopeId;
}
if ($q !== '') {
    // Same global-search arms as bookings.php so the export matches the view.
    $where[] = sqlSearchClause([
        'b.pnr LIKE %s',
        'b.contact_phone LIKE %s',
        'b.contact_email LIKE %s',
        'EXISTS (SELECT 1 FROM tickets t2 WHERE t2.booking_id = b.id AND t2.ticket_number LIKE %s)',
        'EXISTS (SELECT 1 FROM booking_passengers bp2
                  WHERE bp2.booking_id = b.id AND bp2.full_name LIKE %s)',
        'EXISTS (SELECT 1 FROM booking_seats bs2
                  WHERE bs2.booking_id = b.id AND bs2.seat_no LIKE %s)',
        'EXISTS (SELECT 1 FROM booking_legs bl2
                    JOIN schedules s2 ON s2.id = bl2.schedule_id
                    JOIN buses bu2 ON bu2.id = s2.bus_id
                  WHERE bl2.booking_id = b.id AND bu2.bus_number LIKE %s)',
        'EXISTS (SELECT 1 FROM admins a2
                  WHERE a2.id = b.sold_by_admin_id
                    AND CONCAT_WS(\' \', a2.full_name, a2.username) LIKE %s)',
    ], $q, $params);
    // 15 Oct 2026: a seat typed as PRINTED (A7, B5-6) - same arm as bookings.php.
    $qSeatArm = shg_seat_label_exists($q, $params);
    if ($qSeatArm !== null) {
        $lastKey = array_key_last($where);
        $where[$lastKey] = '(' . $where[$lastKey] . ' OR ' . $qSeatArm . ')';
    }
    // A typed SHG code ("SHG-027"), exactly as bookings.php resolves it.
    $qAgentId = $scopeId === null ? AgentWallet::resolveAgentCodeFromString($q) : null;
    if ($qAgentId !== null) {
        $lastKey = array_key_last($where);
        $where[$lastKey] = '(' . $where[$lastKey] . ' OR b.sold_by_admin_id = :qAgentCode)';
        $params['qAgentCode'] = $qAgentId;
    }
}
if (in_array($status, $valid, true)) {
    $where[]      = 'b.status = :st';
    $params['st'] = $status;
}
// Honour the same placed-between window the on-screen views use. Without this,
// a ranged "Export my sales" from agent-sales.php silently dropped from/to and
// downloaded the agent's entire history instead of the shown range.
if ($isDate($from)) {
    $where[]        = 'b.created_at >= :from';
    $params['from'] = $from;
}
if ($isDate($to)) {
    $where[]         = 'b.created_at < :toEnd';
    $params['toEnd'] = addDaysISO($to, 1);
}
if ($routeFilter !== '' && ctype_digit($routeFilter)) {
    $where[] = 's.route_id = :routeFilter';
    $params['routeFilter'] = (int) $routeFilter;
}
if ($isDate($travelFrom)) {
    $where[] = 'bl.travel_date >= :travelFrom';
    $params['travelFrom'] = $travelFrom;
}
if ($isDate($travelTo)) {
    $where[] = 'bl.travel_date <= :travelTo';
    $params['travelTo'] = $travelTo;
}
// 5 Oct 2026: the same one-tag-per-booking channel filter as the register.
$sourceFilter = BookingChannel::applyFilter($where, $sourceFilter);
// Agent filter only for non-agent roles - an agent's export is already scoped.
if ($scopeId === null && $agentFilter !== '' && ctype_digit($agentFilter)) {
    $where[] = 'b.sold_by_admin_id = :agentFlt';
    $params['agentFlt'] = (int) $agentFilter;
}
if ($busFilter !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM booking_legs bl3
                          JOIN schedules s3 ON s3.id = bl3.schedule_id
                          JOIN buses bu3 ON bu3.id = s3.bus_id
                         WHERE bl3.booking_id = b.id AND bl3.leg_type = \'outbound\'
                           AND bu3.bus_number = :busFlt)';   // outbound bus only, as on screen
    $params['busFlt'] = $busFilter;
}
if ($payStatusFilter !== '' && in_array($payStatusFilter, $validPayStatus, true)) {
    $where[] = 'EXISTS (SELECT 1 FROM payments p2
                         WHERE p2.booking_id = b.id AND p2.status = :payStFlt)';
    $params['payStFlt'] = $payStatusFilter;
}
/* 5 Oct 2026: the export now honours EVERY filter the register has. It ignored
   Payment method and Counter (and the desk-isolation scope), so the file held
   more rows than the screen it was exported from. */
$payMethodFilter = Security::clean($_GET['pay_method'] ?? '', 20);
if (in_array($payMethodFilter, ['upi', 'esewa', 'cash', 'bank', 'wallet', 'cod'], true)) {
    $where[] = 'EXISTS (SELECT 1 FROM payments p4
                         WHERE p4.booking_id = b.id AND p4.method = :payMtFlt)';
    $params['payMtFlt'] = $payMethodFilter;
}
$counterFilter = mb_strtoupper(Security::clean($_GET['counter'] ?? '', 16));
$deskScope = Auth::deskScopeCode();
if ($deskScope !== null && CounterDesk::stampColumn()) {
    $where[] = CounterDesk::scopeClause('b');
    $params['deskScope'] = $deskScope;
    $counterFilter = '';
}
if ($counterFilter !== '' && CounterDesk::stampColumn()) {
    $where[] = CounterDesk::scopeClause('b', 'ctrFlt');
    $params['ctrFlt'] = $counterFilter;
}
shg_booking_extra_filters($where, $params, $_GET);
// 15 Oct 2026: the register's column filters (ticket / passenger / mobile /
// seat as printed) and "only my sales" - the file is still the screen.
shg_booking_column_filters($where, $params, $_GET);

// The bookings PDF (7 Oct 2026) takes this exact WHERE: it used to rebuild
// its own from a few filters and ignored method, counter, desk scope, phone,
// reach, ticket-number and agent-code search - so the PDF disagreed with
// the screen it was printed from.
if ($formatParam === 'pdf') {
    $path = ReportPdf::bookingsReport(Database::pdo(), [
        'scope'   => $scopeId,
        'q'       => $q,
        'status'  => $status,
        'from'    => $from,
        'to'      => $to,
        '_where'  => $where,
        '_params' => $params,
    ] + $extraFilters);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="SHG-bookings-' . date('Y-m-d') . '.pdf"');
    header('Content-Length: ' . (string) (filesize($path) ?: 0));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
    exit;
}

/* 15 Oct 2026 (chalani register): the file now carries what the register
   shows - the Ticket no and the payment status - and counts only LIVE seats
   (a released seat is no longer on the ticket), printed as the grid label
   the ticket uses (raw ids kept in their own column). A booking with no
   is_primary row falls back to its first passenger instead of a blank. */
$sql = "SELECT b.pnr, b.status, b.booking_mode, b.cabin_label, b.expires_at,
               r.from_city, r.to_city, bl.travel_date, bl.boarding_stop, bl.drop_stop,
               COALESCE(s.coach_type_override, r.coach_type) AS coach_type,
               b.contact_phone, b.contact_email, b.total_amount, b.currency,
               b.created_at, b.confirmed_at, b.sold_by_admin_id,
               (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id=b.id AND bs.released_at IS NULL) AS seat_count,
               (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
                  FROM booking_seats bs WHERE bs.booking_id=b.id AND bs.released_at IS NULL) AS seats,
               (SELECT bp.full_name FROM booking_passengers bp
                 WHERE bp.booking_id=b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS primary_passenger,
               (SELECT p.method FROM payments p
                 WHERE p.booking_id=b.id ORDER BY p.id DESC LIMIT 1) AS pay_method,
               (SELECT p.status FROM payments p
                 WHERE p.booking_id=b.id ORDER BY p.id DESC LIMIT 1) AS pay_status,
               (SELECT t.ticket_number FROM tickets t WHERE t.booking_id=b.id LIMIT 1) AS ticket_number,
               ad.full_name AS seller_name, ad.username AS seller_user
          FROM bookings b
          LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
          LEFT JOIN schedules s ON s.id=bl.schedule_id
          LEFT JOIN routes r ON r.id=s.route_id
          LEFT JOIN admins ad ON ad.id=b.sold_by_admin_id"
     . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY b.id DESC LIMIT 10000';

$rows = Database::fetchAll($sql, $params);

$header = [
    'PNR', 'Ticket no', 'Status', 'Route', 'Travel date', 'Boarding', 'Drop', 'Seats', 'Seat ids',
    'Seat count', 'Passenger', 'Phone', 'Email', 'Cabin', 'Mode',
    'Amount', 'Currency', 'Payment', 'Payment status', 'Created', 'Confirmed',
    'Seller', 'Agent Code',
];
// The register's payment pill, in words (payments.status of the LATEST row).
$payWords = ['verified' => 'Paid', 'pending' => 'Unverified', 'cod_pending' => 'Cash due', 'rejected' => 'Rejected', 'refunded' => 'Refunded'];

$data = [];
foreach ($rows as $b) {
    // Seller = human name of the admin/agent that sold this booking (Online for web).
    // Agent code = SHG-NNN label derived from the same sold_by_admin_id.
    $sellerId = (int) ($b['sold_by_admin_id'] ?? 0);
    $seller   = $sellerId > 0 ? (string) ($b['seller_name'] ?: $b['seller_user']) : 'Online';
    $agentCd  = $sellerId > 0 ? AgentWallet::agentCodeLabel($sellerId) : '';
    $seatIds  = array_values(array_filter(explode(' ', (string) ($b['seats'] ?? '')), static fn($t) => $t !== ''));
    $paySt    = (string) ($b['pay_status'] ?? '');
    // Proof sent and parked for review (expires_at NULL) reads as on screen.
    $payLbl   = ($paySt === 'pending' && (string) $b['status'] === 'pending' && empty($b['expires_at']))
        ? 'Verification pending' : ($payWords[$paySt] ?? $paySt);

    $data[] = [
        $b['pnr'],
        $b['ticket_number'] ?? '',
        $b['status'],
        ($b['from_city'] ?? '') . ' -> ' . ($b['to_city'] ?? ''),
        $b['travel_date'] ?? '',
        $b['boarding_stop'] ?? '',
        $b['drop_stop'] ?? '',
        Seats::displayLabels($seatIds, (string) ($b['coach_type'] ?: 'sleeper'), (string) ($b['booking_mode'] ?: 'sharing'), ' '),
        implode(' ', $seatIds),
        (int) $b['seat_count'],
        $b['primary_passenger'] ?? '',
        $b['contact_phone'] ?? '',
        $b['contact_email'] ?? '',
        $b['cabin_label'] ?? '',
        $b['booking_mode'] ?? '',
        (float) $b['total_amount'],
        $b['currency'],
        $b['pay_method'] ?? '',
        $payLbl,
        $b['created_at'] ?? '',
        $b['confirmed_at'] ?? '',
        $seller,
        $agentCd,
    ];
}

/* 15 Oct 2026: ONE writer for every admin list (admin/_export.php). Its CSV
   prefixes a cell that starts with = + - @, so a passenger name typed as a
   formula can no longer run in Excel; the XLSX falls back to that CSV when
   ZipArchive is missing. Numeric columns (0-indexed): Seat count, Amount. */
if (($_GET['format'] ?? '') === 'xlsx') {
    shg_export_xlsx('SHG-bookings-' . date('Y-m-d') . '.xlsx', 'Bookings', $header, $data, [9, 15]);
}
shg_export_csv('SHG-bookings-' . date('Y-m-d') . '.csv', $header, $data);
