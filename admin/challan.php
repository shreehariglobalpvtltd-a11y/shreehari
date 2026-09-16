<?php
/**
 * admin/challan.php — the per-bus, per-date challan PNG (SHG AI BRAIN Phase 1.5).
 *
 *   ?sid=<schedule id>            one departure (an extra bus has its own id)
 *   ?route=<id>&date=YYYY-MM-DD   the daily bus (slot 1) of a route on a date
 *   &dl=1                         download instead of showing inline
 *   &fresh=1                      re-render even when nothing changed
 *
 * Renders through ChallanPng::render(), which reuses the last file while the
 * booking data behind it is unchanged and re-draws the moment a seat, hold,
 * block, bus or driver moves — so "download" always hands over the current
 * coach without anything running on the sale path.
 *
 * Permission: bookings.view — the counter role has it, so every ticket window
 * can print its own bus. A SCOPED agent login is refused outright: the challan
 * shows every berth on the bus, and the whole point of agent scoping is that
 * an agent reads only their own sales (they have the manifest for that).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/challanpng.php';

$admin = admin_boot('bookings.view');

if (Auth::bookingScopeAdminId() !== null) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('The challan shows every seat on the bus. An agent login sees only its own sales — use My Passengers / the manifest.');
}

$sid = (int) ($_GET['sid'] ?? 0);
if ($sid <= 0) {
    $routeId = (int) ($_GET['route'] ?? 0);
    $date    = Security::clean($_GET['date'] ?? '', 10);
    if ($routeId > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
        $sid = (int) Database::scalar(
            'SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d ORDER BY slot ASC, id ASC LIMIT 1',
            ['r' => $routeId, 'd' => $date],
            0
        );
    }
}
if ($sid <= 0 || ChallanPng::schedule($sid) === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No departure found for that bus and date.');
}

try {
    $res = ChallanPng::render($sid, 'manual', (int) $admin['id'], isset($_GET['fresh']));
} catch (Throwable $e) {
    Logger::error('Challan render failed: ' . $e->getMessage(), ['sid' => $sid]);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('The challan could not be drawn: ' . $e->getMessage());
}

Logger::audit('challan.download', 'schedule', (string) $sid, null,
    ['file' => $res['file'], 'fresh' => $res['fresh'], 'bus' => $res['bus']],
    (isset($_GET['dl']) ? 'downloaded' : 'viewed') . ' by admin #' . (int) $admin['id']);

if (isset($_GET['dl'])) {
    Response::download($res['path'], $res['file'], 'image/png');
}
Response::inline($res['path'], 'image/png');
