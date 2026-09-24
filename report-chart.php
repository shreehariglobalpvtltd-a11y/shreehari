<?php
/**
 * =====================================================================
 *  report-chart.php — the office's 7-day chart WhatsApp fetches
 *  (23 Sep 2026). /report-chart.php?d=<end date>&e=<expiry>&k=<sig>
 *
 *  Only a link minted by ReportChart::url() (office numbers only) opens
 *  it; 10-minute HMAC over (date, expiry) keyed on APP_KEY. Totals only —
 *  no passenger appears in it (see includes/reportchart.php).
 * =====================================================================
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/reportchart.php';

$d = (string) ($_GET['d'] ?? '');
$e = (int) ($_GET['e'] ?? 0);
$k = (string) ($_GET['k'] ?? '');

if (!ReportChart::verify($d, $e, $k)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('This report link has expired. Ask for the report again on WhatsApp.');
}

try {
    $png = ReportChart::png($d);
} catch (Throwable $ex) {
    Logger::warning('report-chart failed: ' . $ex->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Report chart not available.');
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex');
echo $png;
