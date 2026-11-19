<?php
/**
 * =====================================================================
 *  /api/driver.php - the driver app's only door (20 Oct 2026).
 *  (driver/ app, includes/livetrips.php, includes/driverauth.php)
 *
 *    POST {action:'login', phone, pin}      -> {token, driver}   (driver.role: driver / co-driver / conductor / host
 *                                              - a conductor shares the bus location exactly like the driver)
 *    GET  ?action=me                         -> {driver, trip, trips[], pingSec}
 *    POST {action:'start', scheduleId}       -> {trip}
 *    POST {action:'ping', tripId, points[], battery?}  -> {ack, stored, moved}
 *                                              (battery {pct, charging}: TripEvents::noteBattery, optional)
 *    POST {action:'end', tripId}             -> {}
 *    POST {action:'logout'}                  -> {}
 *
 *  15 Nov 2026 (includes/tripevents.php, table driver_events):
 *    GET  ?action=trip&tripId=N              -> {nav, manifest, rest, recent, startedTs, now}
 *    POST {action:'event', tripId, kind, stop?, minutes?, note?, lat?, lng?, t?}
 *                                            -> {event, repeat, alert, nav?, rest?}
 *         kind: stop_reached | delay | breakdown | sos | rest_start | rest_end.
 *         SOS / breakdown tell the owner + office at once (bell + WhatsApp).
 *    `me` also carries crew {actions, speedWarnKmh, restSoftMin, …, officeTel}.
 *
 *    POST {action:'pair', code}             -> {token, driver}   (one-time office link / QR, no PIN)
 *
 *  Every action but login / pair needs the device token (X-Driver-Token). No
 *  session cookie is read or written, so no CSRF token is needed: a page on
 *  another site cannot read the token out of the driver's phone.
 *
 *  A driver can only ever write the trip HIS phone started - ping and end
 *  check that the trip's device_id is this device.
 * ===================================================================== */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/etaalerts.php';
require_once INCLUDE_PATH . '/livetrips.php';
require_once INCLUDE_PATH . '/driverauth.php';
require_once INCLUDE_PATH . '/tripevents.php';

header('X-Content-Type-Options: nosniff');

try {
    Response::$maxInputBytes = 96 * 1024;   // 300 points ≈ 30 KB
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = (string) Response::field('action', $method === 'GET' ? 'me' : '');

    if (!LiveTrips::enabled()) {
        Response::error('The driver app is switched off by the office.', 503);
    }
    if (!LiveTrips::available()) {
        Response::error('The driver app is not set up on this server yet.', 503);
    }

    /* ---------------- connect by link / QR (19 Nov 2026) ---------------- */
    if ($action === 'pair') {
        if ($method !== 'POST') { Response::error('Method not allowed.', 405); }
        Security::requireRateLimit('driver_pair', Security::clientIp(), 10, 600);
        $ua  = Security::clean((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 160);
        $res = DriverAuth::pairRedeem(Security::clean((string) Response::field('code', ''), 40), $ua);
        if ($res === null) {
            Response::error('यो लिंक पुरानो वा प्रयोग भइसकेको छ — कार्यालयसँग नयाँ माग्नुहोस् · This link is used or expired: ask the office for a new one.', 401);
        }
        Response::success(['token' => $res['token'], 'driver' => [
            'name' => (string) $res['driver']['full_name'], 'phone' => (string) $res['driver']['phone'],
            'role' => (string) ($res['driver']['role'] ?? 'driver'),
        ]]);
    }

    /* ---------------- sign in ---------------- */
    if ($action === 'login') {
        if ($method !== 'POST') { Response::error('Method not allowed.', 405); }
        $phone = Security::clean((string) Response::field('phone', ''), 20);
        $pin   = Security::clean((string) Response::field('pin', ''), 6);
        Security::requireRateLimit('driver_login', Security::clientIp(), 10, 600);
        /* 16 Nov 2026: 5 tries per phone in 15 minutes, then that phone waits 15 minutes. The old
           6 per 10 minutes never locked, so a 4-digit PIN could be walked through in days.
           Failed tries are audited in DriverAuth::login (driver.app_login_fail). */
        if (!Security::rateLimit('driver_login_phone', normalisePhone($phone) ?: 'x', 5, 900, 900)) {
            Response::error('धेरै पटक प्रयास भयो — १५ मिनेटपछि फेरि, वा कार्यालयलाई PIN बदल्न भन्नुहोस् · Too many tries: wait 15 minutes, or ask the office to reset your PIN.', 429);
        }
        $ua  = Security::clean((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 160);
        $res = DriverAuth::login($phone, $pin, $ua);
        if ($res === null) {
            Response::error('Phone number or PIN is wrong. Ask the office to set your PIN.', 401);
        }
        Response::success(['token' => $res['token'], 'driver' => [
            'name' => (string) $res['driver']['full_name'], 'phone' => (string) $res['driver']['phone'],
            'role' => (string) ($res['driver']['role'] ?? 'driver'),
        ]]);
    }

    $token  = DriverAuth::requestToken();
    $driver = DriverAuth::current($token);
    if ($driver === null) {
        Response::error('Please sign in again.', 401);
    }

    if ($action === 'logout') {
        DriverAuth::logout($token);
        Response::success();
    }

    /* ---------------- who am I, what can I start ---------------- */
    if ($action === 'me') {
        $trip = Database::fetch(
            "SELECT id, schedule_id, status, last_seq, last_ping_at, started_at FROM live_trips
              WHERE device_id = :dev AND status = 'running' ORDER BY id DESC LIMIT 1",
            ['dev' => $driver['deviceId']]
        );
        Response::success([
            'driver'  => ['name' => $driver['name'], 'phone' => $driver['phone'], 'role' => $driver['role']],
            'trip'    => $trip === null ? null : [
                'tripId' => (int) $trip['id'], 'scheduleId' => (int) $trip['schedule_id'],
                'lastSeq' => (int) $trip['last_seq'], 'lastPingAt' => $trip['last_ping_at'],
                'startedTs' => $trip['started_at'] !== null ? (int) strtotime((string) $trip['started_at']) : null,
            ],
            'trips'   => LiveTrips::choosable(),
            'pingSec' => LiveTrips::pingSeconds(),
            'crew'    => TripEvents::config(),
        ]);
    }

    /* The trip this phone runs, or 409: a driver can only ever read or write
       the trip HIS phone started (the co-driver's phone took it over, or it
       never existed - the app drops its queue and goes back to the list). */
    $ownTrip = static function () use ($driver): array {
        $tripId = (int) Response::field('tripId', 0);
        $trip   = $tripId > 0 ? LiveTrips::find($tripId) : null;
        if ($trip === null || (int) ($trip['device_id'] ?? 0) !== $driver['deviceId']) {
            Response::error('This trip is now with another phone.', 409);
        }
        return $trip;
    };

    /* ---------------- the running-trip screen: stops, manifest, rest ---------------- */
    if ($action === 'trip') {
        Security::requireRateLimit('driver_trip', 'dev' . $driver['deviceId'], 60, 600);
        $trip = $ownTrip();
        if (!in_array((string) $trip['status'], ['running', 'paused'], true)) {
            // An ended trip's passengers (first names, phones) are no longer this phone's to read.
            Response::error('This trip has ended.', 409);
        }
        Response::success(TripEvents::tripInfo($trip));
    }

    if ($method !== 'POST') {
        Response::error('Method not allowed.', 405);
    }

    /* ---------------- Start Trip ---------------- */
    if ($action === 'start') {
        Security::requireRateLimit('driver_start', 'dev' . $driver['deviceId'], 20, 600);
        $sid = (int) Response::field('scheduleId', 0);
        try {
            $row = LiveTrips::start($driver['driverId'], $driver['deviceId'], $sid);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 422);
        }
        Response::success(['trip' => ['tripId' => (int) $row['id'], 'scheduleId' => (int) $row['schedule_id'],
            'lastSeq' => (int) $row['last_seq']], 'pingSec' => LiveTrips::pingSeconds()]);
    }

    /* ---------------- GPS points / End Trip / buttons: only on this phone's trip ---------------- */
    $trip   = $ownTrip();
    $tripId = (int) $trip['id'];

    /* "Stop pugyo", "Dhilo", "Bus bigriyo", SOS, rest (rate-limited per driver inside) */
    if ($action === 'event') {
        try {
            $res = TripEvents::report($driver, $trip, Response::input());
        } catch (TripEventRefused $e) {
            // Only a refusal is the driver's message. A PDOException is a RuntimeException
            // too: it falls through to serverError (500), so the phone keeps the tap.
            $code = (int) $e->getCode();
            Response::error($e->getMessage(), in_array($code, [409, 422, 429, 503], true) ? $code : 422);
        }
        Response::success($res);
    }

    if ($action === 'ping') {
        // ~1 request per 10-30 s per phone, plus catch-up batches after a dead zone.
        Security::requireRateLimit('driver_ping', 'dev' . $driver['deviceId'], 40, 60);
        $points = Response::field('points', []);
        if (!is_array($points)) {
            Response::invalid(['points' => 'Expected a list of positions.']);
        }
        if ((string) $trip['status'] !== 'running') {
            // Ended trip: keep nothing new, but acknowledge so the phone empties its queue.
            $max = 0;
            foreach ($points as $p) { if (is_array($p)) { $max = max($max, (int) ($p['seq'] ?? 0)); } }
            Response::success(['ack' => $max, 'stored' => 0, 'moved' => false, 'ended' => true]);
        }
        $res = LiveTrips::ingest($trip, array_values($points));
        TripEvents::noteBattery($tripId, Response::field('battery', null));   // optional, never throws
        Response::success($res + ['pingSec' => LiveTrips::pingSeconds()]);
    }

    if ($action === 'end') {
        LiveTrips::end($tripId);
        Logger::audit('trip.live_end', 'schedule', (string) $trip['schedule_id'], null,
            ['trip' => $tripId, 'driver' => $driver['driverId']], 'driver app');
        Response::success();
    }

    Response::error('Unknown action.', 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
