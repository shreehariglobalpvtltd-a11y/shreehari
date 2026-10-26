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

    /* 8 Oct 2026 (D7-03): `livebus` is ONE anonymous position. It answers for
       this route only when exactly one trip of the route can be on the road
       right now (EtaAlerts::candidates — departure −30 min … +16 h); with
       none, or two, it may be another coach (the return run passes the same
       towns), so the answer is live:false — never someone else's bus. */
    $now     = time();
    $running = array_filter(EtaAlerts::candidates($now), static fn(array $c): bool => (int) $c['route_id'] === (int) $route['id']);
    if (count($running) !== 1) {
        Response::success(['live' => false, 'stops' => [], 'reason' => count($running) > 1 ? 'two trips on the road' : 'no trip on the road']);
    }

    /* 20 Oct 2026: that one trip's own driver-app fix first (fixForTrip). */
    [$fix] = EtaAlerts::fixForTrip((int) reset($running)['id'], (int) $route['id'], $now);
    if ($fix === null) {
        Response::success(['live' => false, 'stops' => []]);
    }
    $stops = [];
    foreach (EtaAlerts::stopEtas((int) $route['id'], $fix, $now) as $key => $s) {
        $stops[] = ['name' => $s['name'], 'key' => $key, 'eta' => (int) (ceil($s['eta'] / 5) * 5), 'km' => $s['km']];
    }
    Response::success(['live' => $stops !== [], 'stops' => $stops]);
} catch (Throwable $e) {
    Response::success(['live' => false, 'stops' => []]);
}
