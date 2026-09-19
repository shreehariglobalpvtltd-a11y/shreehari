<?php
/**
 * GET /api/eta.php?route=r2 — minutes from the live bus to each pickup still
 * ahead of it on that route (19 Sep 2026).
 *
 * The Trip Companion shows "about 25 min to your stop" from THIS, and the
 * "bus is near" WhatsApp / SMS comes from the same EtaAlerts engine — so the
 * app and the message can never disagree. Same honesty rules: a stale,
 * inaccurate or off-route position answers { live: false } rather than guess.
 *
 * Public on purpose, nothing private in it: the bus position is already the
 * public `livebus` key and the stops are the public timetable. Rate-limited.
 *
 * → { live: bool, stops: [ { name, key, eta, km } ] }   eta rounded up to 5 min
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/etaalerts.php';

try {
    Security::requireRateLimit('eta', Security::clientIp(), 60, 60);
    header('Cache-Control: no-store');

    $code  = Security::clean((string) ($_GET['route'] ?? ''), 20);
    $route = $code !== ''
        ? Database::fetch('SELECT id FROM routes WHERE (route_code = :c OR id = :i) AND is_active = 1 LIMIT 1', ['c' => $code, 'i' => ctype_digit($code) ? (int) $code : 0])
        : null;
    if ($route === null) {
        Response::success(['live' => false, 'stops' => []]);
    }

    $stops = [];
    foreach (EtaAlerts::stopEtas((int) $route['id']) as $key => $s) {
        $stops[] = ['name' => $s['name'], 'key' => $key, 'eta' => (int) (ceil($s['eta'] / 5) * 5), 'km' => $s['km']];
    }
    Response::success(['live' => $stops !== [], 'stops' => $stops]);
} catch (Throwable $e) {
    Response::success(['live' => false, 'stops' => []]);
}
