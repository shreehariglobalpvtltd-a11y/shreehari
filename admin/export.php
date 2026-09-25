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

$q      = Security::clean($_GET['q'] ?? '', 60);
$status = Security::clean($_GET['status'] ?? '', 20);
$from   = Security::clean($_GET['from'] ?? '', 10);
$to     = Security::clean($_GET['to'] ?? '', 10);
$valid  = ['pending', 'confirmed', 'cancelled', 'rejected', 'expired'];
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
$sourceMap       = ['online' => ['web', 'app'], 'agent' => ['agent'], 'counter' => ['counter'], 'admin' => ['admin']];
$extraFilters    = [
    'route' => $routeFilter, 'travel_from' => $travelFrom, 'travel_to' => $travelTo, 'source' => $sourceFilter,
    'agent' => $agentFilter, 'bus' => $busFilter, 'pay_status' => $payStatusFilter,
];

// A company-wide bookings PDF reuses the same filter set — hand it off to
// ReportPdf::bookingsReport() and stream the returned file.
if ($formatParam === 'pdf') {
    $path = ReportPdf::bookingsReport(Database::pdo(), [
        'scope'  => $scopeId,
        'q'      => $q,
        'status' => $status,
        'from'   => $from,
        'to'     => $to,
    ] + $extraFilters);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="SHG-bookings-' . date('Y-m-d') . '.pdf"');
    header('Content-Length: ' . (string) (filesize($path) ?: 0));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
    exit;
}

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
if ($sourceFilter !== '' && isset($sourceMap[$sourceFilter])) {
    $srcParts = [];
    foreach ($sourceMap[$sourceFilter] as $si => $sv) {
        $srcParts[] = ':src' . $si;
        $params['src' . $si] = $sv;
    }
    $where[] = 'b.source IN (' . implode(',', $srcParts) . ')';
}
// Agent filter only for non-agent roles - an agent's export is already scoped.
if ($scopeId === null && $agentFilter !== '' && ctype_digit($agentFilter)) {
    $where[] = 'b.sold_by_admin_id = :agentFlt';
    $params['agentFlt'] = (int) $agentFilter;
}
if ($busFilter !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM booking_legs bl3
                          JOIN schedules s3 ON s3.id = bl3.schedule_id
                          JOIN buses bu3 ON bu3.id = s3.bus_id
                         WHERE bl3.booking_id = b.id AND bu3.bus_number = :busFlt)';
    $params['busFlt'] = $busFilter;
}
if ($payStatusFilter !== '' && in_array($payStatusFilter, $validPayStatus, true)) {
    $where[] = 'EXISTS (SELECT 1 FROM payments p2
                         WHERE p2.booking_id = b.id AND p2.status = :payStFlt)';
    $params['payStFlt'] = $payStatusFilter;
}

$sql = "SELECT b.pnr, b.status, b.booking_mode, b.cabin_label,
               r.from_city, r.to_city, bl.travel_date, bl.boarding_stop, bl.drop_stop,
               b.contact_phone, b.contact_email, b.total_amount, b.currency,
               b.created_at, b.confirmed_at, b.sold_by_admin_id,
               (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id=b.id) AS seat_count,
               (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
                  FROM booking_seats bs WHERE bs.booking_id=b.id) AS seats,
               (SELECT bp.full_name FROM booking_passengers bp
                 WHERE bp.booking_id=b.id AND bp.is_primary=1 LIMIT 1) AS primary_passenger,
               (SELECT p.method FROM payments p
                 WHERE p.booking_id=b.id ORDER BY p.id DESC LIMIT 1) AS pay_method,
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
    'PNR', 'Status', 'Route', 'Travel date', 'Boarding', 'Drop', 'Seats',
    'Seat count', 'Passenger', 'Phone', 'Email', 'Cabin', 'Mode',
    'Amount', 'Currency', 'Payment', 'Created', 'Confirmed',
    'Seller', 'Agent Code',
];

$data = [];
foreach ($rows as $b) {
    // Seller = human name of the admin/agent that sold this booking (Online for web).
    // Agent code = SHG-NNN label derived from the same sold_by_admin_id.
    $sellerId = (int) ($b['sold_by_admin_id'] ?? 0);
    $seller   = $sellerId > 0 ? (string) ($b['seller_name'] ?: $b['seller_user']) : 'Online';
    $agentCd  = $sellerId > 0 ? AgentWallet::agentCodeLabel($sellerId) : '';

    $data[] = [
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
        (float) $b['total_amount'],
        $b['currency'],
        $b['pay_method'] ?? '',
        $b['created_at'] ?? '',
        $b['confirmed_at'] ?? '',
        $seller,
        $agentCd,
    ];
}

// Native XLSX when asked for (and ZipArchive is available); CSV otherwise.
$wantXlsx = ($_GET['format'] ?? '') === 'xlsx' && class_exists('ZipArchive');

if ($wantXlsx) {
    // Columns that should be real numbers in the sheet (0-indexed): Seat count, Amount.
    export_xlsx('SHG-bookings-' . date('Y-m-d') . '.xlsx', 'Bookings', $header, $data, [7, 13]);
    exit;
}

$fname = 'SHG-bookings-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'wb');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Unicode (Nepali/Hindi) names
csv_put($out, $header);
foreach ($data as $row) {
    csv_put($out, $row);
}
fclose($out);
exit;


/**
 * Stream a minimal, valid .xlsx (Office Open XML) built by hand — no library.
 * An .xlsx is a ZIP of a few XML parts; values are written as inline strings,
 * except columns listed in $numericCols which are written as numbers so Excel
 * can SUM them. Falls back to nothing (caller already gated on ZipArchive).
 *
 * @param array<int,string>      $header
 * @param array<int,array<int,mixed>> $data
 * @param array<int,int>         $numericCols 0-indexed numeric columns
 */
function export_xlsx(string $filename, string $sheetName, array $header, array $data, array $numericCols = []): void
{
    $numeric = array_flip($numericCols);

    $xesc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $colRef = static function (int $i): string { // 0 -> A, 26 -> AA
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + (($i - 1) % 26)) . $s;
        }
        return $s;
    };

    $rowXml = static function (array $cells, int $rowNum, bool $headerRow) use ($numeric, $xesc, $colRef): string {
        $x = '<row r="' . $rowNum . '">';
        foreach (array_values($cells) as $ci => $val) {
            $ref = $colRef($ci) . $rowNum;
            if (!$headerRow && isset($numeric[$ci]) && is_numeric($val)) {
                $x .= '<c r="' . $ref . '"><v>' . $xesc($val) . '</v></c>';
            } else {
                $x .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $xesc($val) . '</t></is></c>';
            }
        }
        return $x . '</row>';
    };

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $sheet .= $rowXml($header, 1, true);
    $r = 2;
    foreach ($data as $row) {
        $sheet .= $rowXml($row, $r++, false);
    }
    $sheet .= '</sheetData></worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_QUOTES | ENT_XML1) . '" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
}
