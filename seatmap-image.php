<?php
/**
 * =====================================================================
 *  seatmap-image.php — the customer seat picture WhatsApp fetches
 *  (23 Sep 2026). /seatmap-image.php?s=<schedule>&h=<seats>&e=<expiry>&k=<sig>
 *
 *  Only a link minted by SeatMapPng::url() opens it: the signature covers
 *  the departure, the highlighted berths and the expiry (30 min), so it
 *  cannot be edited to another bus. The picture shows berth STATUS only —
 *  no name, phone or PNR (see includes/seatmappng.php).
 * =====================================================================
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seatmappng.php';

$s = (int) ($_GET['s'] ?? 0);
$h = (string) ($_GET['h'] ?? '');
$e = (int) ($_GET['e'] ?? 0);
$k = (string) ($_GET['k'] ?? '');

if (!SeatMapPng::verify($s, $h, $e, $k)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('This seat picture link has expired. Ask again on WhatsApp for a fresh one.');
}

try {
    $png = SeatMapPng::png($s, $h === '' ? [] : explode(',', $h));
} catch (Throwable $ex) {
    Logger::warning('seatmap-image failed: ' . $ex->getMessage(), ['schedule' => $s]);
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Seat picture not available.');
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($png));
header('Cache-Control: private, max-age=60');
header('X-Robots-Tag: noindex');
echo $png;
