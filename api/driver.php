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
 *    POST {action:'ping', tripId, points[]}  -> {ack, stored, moved}
 *    POST {action:'end', tripId}             -> {}
 *    POST {action:'logout'}                  -> {}
 *
 *  Every action but login needs the device token (X-Driver-Token). No
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

    /* ---------------- sign in ---------------- */
    if ($action === 'login') {
        if ($method !== 'POST') { Response::error('Method not allowed.', 405); }
        $phone = Security::clean((string) Response::field('phone', ''), 20);
        $pin   = Security::clean((string) Response::field('pin', ''), 6);
        Security::requireRateLimit('driver_login', Security::clientIp(), 10, 600);
        Security::requireRateLimit('driver_login_phone', normalisePhone($phone) ?: 'x', 6, 600);
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
            "SELECT id, schedule_id, status, last_seq, last_ping_at FROM live_trips
              WHERE device_id = :dev AND status = 'running' ORDER BY id DESC LIMIT 1",
            ['dev' => $driver['deviceId']]
        );
        Response::success([
            'driver'  => ['name' => $driver['name'], 'phone' => $driver['phone'], 'role' => $driver['role']],
            'trip'    => $trip === null ? null : [
                'tripId' => (int) $trip['id'], 'scheduleId' => (int) $trip['schedule_id'],
                'lastSeq' => (int) $trip['last_seq'], 'lastPingAt' => $trip['last_ping_at'],
            ],
            'trips'   => LiveTrips::choosable(),
            'pingSec' => LiveTrips::pingSeconds(),
        ]);
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

    /* ---------------- GPS points / End Trip: only on this phone's trip ---------------- */
    $tripId = (int) Response::field('tripId', 0);
    $trip   = $tripId > 0 ? LiveTrips::find($tripId) : null;
    if ($trip === null || (int) ($trip['device_id'] ?? 0) !== $driver['deviceId']) {
        // Another phone took the trip over (co-driver) or it never existed:
        // the app drops its queue for it and goes back to the trip list.
        Response::error('This trip is now with another phone.', 409);
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
        Response::success(LiveTrips::ingest($trip, array_values($points)) + ['pingSec' => LiveTrips::pingSeconds()]);
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
