<?php
/**
 * =====================================================================
 *  /api/alert-feed.php — what the open staff panel should ring / show now
 *  (26 Nov 2026, fx1). Asked by assets/js/admin-ring.js on the panel's
 *  existing 15 s poll (admin_poll_js -> SHGRing.tick()).
 *
 *  GET -> { ok, data: { on, ring, now, seconds, repeat, max, rings[], ringCount,
 *                       soft[], sound, volume, quiet, push:{on} } }
 *
 *  Signed-in staff only (bookings.view or payments.view). What a person gets
 *  is decided here on the server (AlertBus::visibleTo: role matrix, an agent's
 *  own tickets, a counter's own desk) - the browser never filters.
 *
 *  Reads only. Before answering it lets the alert bus read the event journal
 *  (at most once every 4 s across all panels); after the answer has left
 *  (php-fpm) it sends any web push that is due. No per-IP limit: a whole
 *  office shares one IP - the limit is per signed-in person.
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/alertbus.php';

try {
    $admin = Auth::admin();
    if ($admin === null) {
        Response::unauthorized();
    }
    if (!Auth::can('bookings.view') && !Auth::can('payments.view')) {
        Response::forbidden();
    }
    $me = (int) ($admin['id'] ?? 0);
    /* the poll must never hold the session lock while it reads */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (!AlertBus::on()) {
        Response::success(['on' => false, 'ring' => false, 'now' => time(), 'rings' => [], 'ringCount' => 0, 'soft' => []]);
    }
    if (!Security::rateLimit('alert_feed', 'admin:' . $me, 60, 60)) {
        Response::error('Too many requests.', 429);
    }

    AlertBus::sweepThrottled();
    $feed = AlertBus::feed(['id' => $me, 'role' => (string) ($admin['role'] ?? '')]);

    /* After the answer: push repeats. Only where the answer can really leave first. */
    if (PHP_SAPI === 'fpm-fcgi' && function_exists('fastcgi_finish_request')) {
        register_shutdown_function(static function (): void {
            try {
                fastcgi_finish_request();
                AlertBus::afterPoll();
            } catch (Throwable $e) {
            }
        });
    }
    Response::success($feed);
} catch (Throwable $e) {
    Response::serverError($e);
}
