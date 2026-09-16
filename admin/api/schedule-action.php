<?php
/**
 * =====================================================================
 *  admin/api/schedule-action.php — Round 2 Schedule Manager write API.
 *
 *  The single JSON endpoint that Admin → Schedule Manager (schedule.php)
 *  posts to for every mutation on a `schedules` row:
 *
 *     cancel_trip     · reason-tagged flip to status='cancelled'
 *                       (mark-only: seats stay claimed, refunds handled
 *                       per-PNR in Refund Desk — the safest option per
 *                       the Round 2 audit). Also releases seat_locks
 *                       (unpaid holds) and fires TripNotify::notifyCancellation.
 *     uncancel_trip   · superadmin-only reversal of the above.
 *     block_trip      · is_blocked=1 (kill sales without cancelling).
 *     unblock_trip    · is_blocked=0.
 *     set_dep_time    · dep_time_override HH:MM (or clear).
 *     change_route    · move a schedule to a different route
 *                       (same coach_type required — seat IDs would break).
 *     duplicate_trip  · new schedule row on target_date, copying
 *                       bus_id/driver_id/dep_time_override from source.
 *     add_trip        · fresh schedule row via ScheduleMaker::insertOne.
 *
 *  Contract (every call, POST):
 *     - CSRF hidden field named CSRF_TOKEN_NAME.
 *     - Auth: schedules.manage (manager + superadmin implicit).
 *     - Rate limit: 60/min per admin session bucket.
 *     - Response: {ok:true, action, message, schedule_id, extra:{…}}
 *                 or {ok:false, error, action}. HTTP 200 always.
 *
 *  Every mutation is wrapped in Database::transaction where >1 row is
 *  touched, and every action fires Logger::audit('trip.<action>', …).
 *  Notification failures never roll back the DB write — the cancel /
 *  block / delay is authoritative; a WhatsApp hiccup is a soft error.
 * =====================================================================
 */

declare(strict_types=1);
require dirname(__DIR__) . '/_guard.php';
// ScheduleMaker is NOT part of the admin bootstrap (_guard.php), and every
// row-creating action here goes through it — add_trip, duplicate_trip,
// add_extra_bus, seed_daily. Without this require those actions died as
// "Class ScheduleMaker not found", swallowed by the catch-all below and shown
// to the operator as a generic failure. Same omission as the TripStatus one
// fixed on 5 Sep 2026; required explicitly here for the same reason.
require_once INCLUDE_PATH . '/schedulemaker.php';
$admin = admin_boot('schedules.manage');   // 403s non-manage roles.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/* ---- Response helpers ------------------------------------------------
   The client always discriminates on `ok`, never HTTP. jsonOk / jsonErr
   exit hard so no downstream write can spill HTML into a JSON body. */

$currentAction = '';

/**
 * @param array<string,mixed> $payload
 */
function jsonOut(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonOk(string $message, int $scheduleId = 0, array $extra = []): void
{
    global $currentAction;
    jsonOut([
        'ok'          => true,
        'action'      => $currentAction,
        'message'     => $message,
        'schedule_id' => $scheduleId,
        'extra'       => $extra,
    ]);
}

function jsonErr(string $error): void
{
    global $currentAction;
    jsonOut([
        'ok'     => false,
        'action' => $currentAction,
        'error'  => $error,
    ]);
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        jsonErr('POST only.');
    }
    if (!Security::verifyCsrf()) {
        jsonErr('Session expired. Please refresh and try again.');
    }

    // Rate limit per admin session — 60 mutations/minute is comfortable
    // for a busy counter and cheap for the DB. Keyed by admin id (not
    // IP) so two ops on the same NAT'd office don't throttle each other.
    $adminId    = (int) ($admin['id'] ?? 0);
    $rateBucket = 'schedule_action';
    $rateIdent  = 'admin:' . $adminId;
    if (!Security::rateLimit($rateBucket, $rateIdent, 60, 60)) {
        jsonErr('Too many actions in a short time — please wait a moment.');
    }

    $currentAction = Security::clean((string) ($_POST['action'] ?? ''), 40);

    switch ($currentAction) {
        case 'cancel_trip':    handleCancel($adminId);      break;
        case 'uncancel_trip':  handleUncancel($adminId);    break;
        case 'block_trip':     handleBlock($adminId);       break;
        case 'unblock_trip':   handleUnblock($adminId);     break;
        case 'set_dep_time':   handleSetDepTime($adminId);  break;
        case 'change_route':   handleChangeRoute($adminId); break;
        case 'duplicate_trip': handleDuplicate($adminId);   break;
        case 'add_trip':       handleAdd($adminId);         break;
        // Bus Calendar (5 Sep 2026)
        case 'add_extra_bus':  handleAddExtraBus($adminId); break;
        case 'set_bus':        handleSetBus($adminId);      break;
        case 'day_off':        handleDayOff($adminId, true);  break;
        case 'day_on':         handleDayOff($adminId, false); break;
        // Manual bus management (4 Sep 2026)
        case 'set_price':      handleSetPrice($adminId);    break;
        case 'set_coach':      handleSetCoach($adminId);    break;
        case 'seed_daily':     handleSeedDaily($adminId);   break;
        default:               jsonErr('Unknown action.');
    }
} catch (Throwable $e) {
    // Anything unexpected → user-safe message + full error into app_logs.
    Logger::error('schedule-action fatal', [
        'action' => $currentAction,
        'err'    => $e->getMessage(),
        'trace'  => $e->getTraceAsString(),
    ], 'admin');
    jsonErr($e->getMessage() !== '' ? $e->getMessage() : 'Something went wrong.');
}

/* ============================================================
 *  Handlers
 * ============================================================ */

/**
 * cancel_trip — mark-only. Flip status='cancelled', fill cancel_reason /
 * cancelled_by / cancelled_at, release seat_locks (unpaid holds), then
 * fire TripNotify::notifyCancellation. Confirmed booking_seats are NOT
 * touched — refund policy is per-PNR in the Refund Desk, not bulk here.
 *
 * Refuses (unless superadmin) if any milestone (departed/border/arrived)
 * has already been recorded — money has moved, passengers are on the bus.
 */
function handleCancel(int $adminId): void
{
    $sid    = scheduleIdInput();
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if ($reason === '') {
        jsonErr('Please tell us why the trip is being cancelled.');
    }
    if (mb_strlen($reason) > 255) {
        $reason = mb_substr($reason, 0, 255);
    }

    $before = Database::fetch(
        'SELECT id, status, cancel_reason, cancelled_by, cancelled_at, is_blocked
           FROM schedules WHERE id = :id',
        ['id' => $sid]
    );
    if ($before === null) {
        jsonErr('That trip no longer exists.');
    }
    $oldStatus = strtolower((string) $before['status']);
    if ($oldStatus === 'cancelled') {
        jsonErr('That trip is already cancelled.');
    }
    if ($oldStatus === 'arrived') {
        jsonErr('That trip has already arrived — cannot cancel it now.');
    }

    // Superadmin bypass — anyone else must not be past a milestone.
    if (!Auth::isSuperadmin()) {
        $milestone = Database::fetch(
            "SELECT event FROM trip_status
              WHERE schedule_id = :s
                AND event IN ('departed','border','arrived')
              LIMIT 1",
            ['s' => $sid]
        );
        if ($milestone !== null) {
            jsonErr('This trip has already been marked '
                . (string) $milestone['event']
                . ' — only a superadmin can cancel it now.');
        }
    }

    $releasedLocks = 0;
    Database::transaction(static function () use ($sid, $reason, $adminId, &$releasedLocks): void {
        Database::update('schedules', [
            'status'        => 'cancelled',
            'cancel_reason' => $reason,
            'cancelled_by'  => $adminId,
            'cancelled_at'  => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $sid]);

        // Release unpaid holds so the seat map doesn't lie about
        // "held" seats on a trip that will never run. Booked/paid
        // seats (booking_seats) are deliberately untouched — the
        // owner decides per-PNR in Refund Desk.
        // Through Seats:: rather than a raw delete — this was the only write to
        // the seat store outside includes/seats.php, and it left no audit row.
        $releasedLocks = Seats::releaseAllHolds($sid, $adminId, 'trip cancelled');
    });

    Logger::audit(
        'trip.cancel',
        'schedule',
        (string) $sid,
        ['status' => $oldStatus, 'cancel_reason' => $before['cancel_reason']],
        ['status' => 'cancelled', 'cancel_reason' => $reason],
        'by admin #' . $adminId . ' · released_locks=' . $releasedLocks
            . ' · confirmed bookings untouched'
    );

    // Best-effort passenger notice. The cancel itself is authoritative;
    // notify hiccups become part of the response message but don't roll
    // anything back. Also swallows any exception from EventBus / Notify.
    $notify = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'message' => ''];
    try {
        $res = TripNotify::notifyCancellation($sid, $reason, $adminId);
        if (is_array($res)) {
            $notify = array_merge($notify, $res);
        }
    } catch (Throwable $e) {
        Logger::error('cancel notify failed', [
            'schedule_id' => $sid, 'err' => $e->getMessage(),
        ], 'notify');
        $notify['message'] = 'Trip cancelled. Passenger notice could not be sent — see Message Log.';
    }

    $msg = 'Trip cancelled.';
    if ((int) $notify['sent'] > 0) {
        $msg .= ' ' . (int) $notify['sent'] . ' passenger'
             . ((int) $notify['sent'] === 1 ? '' : 's') . ' notified.';
    }
    if ($releasedLocks > 0) {
        $msg .= ' Released ' . $releasedLocks . ' held seat'
             . ($releasedLocks === 1 ? '' : 's') . '.';
    }
    if ((int) $notify['failed'] > 0) {
        $msg .= ' ' . (int) $notify['failed']
             . ' notice(s) failed — see Message Log.';
    }

    jsonOk($msg, $sid, [
        'notify'         => $notify,
        'releasedLocks'  => $releasedLocks,
        'oldStatus'      => $oldStatus,
    ]);
}

/**
 * uncancel_trip — reopen a cancelled schedule. Superadmin only, per the
 * Round 2 audit: once a cancel notice has been sent to passengers, only
 * a superadmin should be able to walk that back.
 */
function handleUncancel(int $adminId): void
{
    if (!Auth::isSuperadmin()) {
        http_response_code(403);
        jsonErr('Only a superadmin can reopen a cancelled trip.');
    }
    $sid = scheduleIdInput();

    $before = Database::fetch(
        'SELECT id, status, cancel_reason, cancelled_by, cancelled_at
           FROM schedules WHERE id = :id',
        ['id' => $sid]
    );
    if ($before === null) {
        jsonErr('That trip no longer exists.');
    }
    if (strtolower((string) $before['status']) !== 'cancelled') {
        jsonErr('That trip is not currently cancelled.');
    }

    Database::update('schedules', [
        'status'        => 'scheduled',
        'cancel_reason' => null,
        'cancelled_by'  => null,
        'cancelled_at'  => null,
    ], 'id = :id', ['id' => $sid]);

    Logger::audit(
        'trip.uncancel',
        'schedule',
        (string) $sid,
        [
            'status'        => $before['status'],
            'cancel_reason' => $before['cancel_reason'],
            'cancelled_by'  => $before['cancelled_by'],
        ],
        ['status' => 'scheduled', 'cancel_reason' => null],
        'reopened by superadmin #' . $adminId
    );

    jsonOk('Trip reopened. Passengers have NOT been re-notified — send them a fresh message from Message Log if needed.', $sid);
}

/**
 * block_trip — is_blocked=1. Kills NEW sales but keeps the trip listed
 * for admins. Legal any time; if there are confirmed bookings we still
 * write, but the response message flags it so the operator can act.
 */
function handleBlock(int $adminId): void
{
    $sid = scheduleIdInput();

    $before = Database::fetch(
        "SELECT s.id, s.is_blocked, s.status,
                (SELECT COUNT(*) FROM booking_seats bs
                  WHERE bs.schedule_id = s.id AND bs.released_at IS NULL) AS sold
           FROM schedules s WHERE s.id = :id",
        ['id' => $sid]
    );
    if ($before === null) {
        jsonErr('That trip no longer exists.');
    }
    if ((int) $before['is_blocked'] === 1) {
        jsonErr('That trip is already blocked.');
    }
    if (strtolower((string) $before['status']) === 'cancelled') {
        jsonErr('That trip is cancelled — reopen it first if you meant to block.');
    }

    Database::update('schedules',
        ['is_blocked' => 1], 'id = :id', ['id' => $sid]);

    $sold = (int) $before['sold'];
    Logger::audit(
        'trip.block',
        'schedule',
        (string) $sid,
        ['is_blocked' => 0],
        ['is_blocked' => 1],
        'by admin #' . $adminId . ' · confirmed_bookings=' . $sold
    );

    $msg = 'Trip blocked — new bookings closed.';
    if ($sold > 0) {
        $msg .= ' Warning: ' . $sold . ' passenger'
             . ($sold === 1 ? '' : 's') . ' already booked; they were NOT notified.';
    }

    jsonOk($msg, $sid, ['confirmedBookings' => $sold]);
}

/**
 * unblock_trip — is_blocked=0.
 */
function handleUnblock(int $adminId): void
{
    $sid = scheduleIdInput();

    $before = Database::fetch(
        'SELECT id, is_blocked, status FROM schedules WHERE id = :id',
        ['id' => $sid]
    );
    if ($before === null) {
        jsonErr('That trip no longer exists.');
    }
    if ((int) $before['is_blocked'] === 0) {
        jsonErr('That trip is not currently blocked.');
    }
    if (strtolower((string) $before['status']) === 'cancelled') {
        jsonErr('That trip is cancelled — reopen it before unblocking.');
    }

    Database::update('schedules',
        ['is_blocked' => 0], 'id = :id', ['id' => $sid]);

    Logger::audit(
        'trip.unblock',
        'schedule',
        (string) $sid,
        ['is_blocked' => 1],
        ['is_blocked' => 0],
        'by admin #' . $adminId
    );

    jsonOk('Trip unblocked — bookings reopened.', $sid);
}

/**
 * set_dep_time — dep_time_override HH:MM, or '' to clear (route timetable
 * wins again). Refuses when the trip has already been marked departed
 * (the printed time no longer means anything at that point).
 */
function handleSetDepTime(int $adminId): void
{
    $sid    = scheduleIdInput();
    $depRaw = trim((string) ($_POST['dep_time'] ?? ''));

    $before = Database::fetch(
        'SELECT id, dep_time_override, status FROM schedules WHERE id = :id',
        ['id' => $sid]
    );
    if ($before === null) {
        jsonErr('That trip no longer exists.');
    }
    if (strtolower((string) $before['status']) === 'cancelled') {
        jsonErr('That trip is cancelled — reopen it before changing its time.');
    }
    if (strtolower((string) $before['status']) === 'arrived') {
        jsonErr('That trip has arrived — cannot change its scheduled time now.');
    }

    // Milestone gate — departed/border/arrived means the bus has moved;
    // rewriting dep_time then would mis-time future computes().
    $milestone = Database::fetch(
        "SELECT event FROM trip_status
          WHERE schedule_id = :s
            AND event IN ('departed','border','arrived')
          LIMIT 1",
        ['s' => $sid]
    );
    if ($milestone !== null && !Auth::isSuperadmin()) {
        jsonErr('This trip has already been marked ' . (string) $milestone['event']
            . ' — its scheduled time can no longer be changed.');
    }

    if ($depRaw === '' || strtolower($depRaw) === 'clear') {
        $newOverride = null;   // fall back to route default
    } else {
        if (!preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $depRaw)) {
            jsonErr('Time must be HH:MM (24-hour), e.g. 07:30 or 18:15.');
        }
        $newOverride = str_pad($depRaw, 5, '0', STR_PAD_LEFT) . ':00';
    }

    if ($newOverride === $before['dep_time_override']) {
        jsonErr('That is already the current departure time.');
    }

    Database::update('schedules',
        ['dep_time_override' => $newOverride], 'id = :id', ['id' => $sid]);

    Logger::audit(
        'trip.set_dep_time',
        'schedule',
        (string) $sid,
        ['dep_time_override' => $before['dep_time_override']],
        ['dep_time_override' => $newOverride],
        'by admin #' . $adminId
    );

    $msg = $newOverride === null
        ? 'Departure time reset to route default.'
        : 'Departure time set to ' . substr($newOverride, 0, 5) . '.';

    jsonOk($msg, $sid, [
        'depTimeOverride' => $newOverride !== null ? substr($newOverride, 0, 5) : null,
    ]);
}

/**
 * change_route — move a schedule row onto a different route. The trip's
 * bus_id stays the same; only the route link changes (from/to city,
 * timetable, boarding stops). Enforced guards:
 *   - target route exists AND is_active=1
 *   - target route coach_type MUST match current route coach_type
 *     (sleeper L#/U# vs seater 1A..10D — seat IDs would collide)
 *   - UNIQUE(route_id, travel_date) — schema uq_schedule_route_date
 *   - if the schedule has a bus assigned, verify it's compatible with
 *     the new route's coach_type (Seats::assertBusSwapSafe against the
 *     current bus is enough — same seat IDs on the same bus can only be
 *     invalid if coach_type actually mismatches, which we already checked)
 */
function handleChangeRoute(int $adminId): void
{
    $sid       = scheduleIdInput();
    $newRoute  = (int) ($_POST['route_id'] ?? 0);
    if ($newRoute <= 0) {
        jsonErr('Please pick a target route.');
    }

    $before = Database::fetch(
        'SELECT s.id, s.route_id, s.travel_date, s.slot, s.bus_id, s.status,
                r.coach_type AS cur_coach, r.route_code AS cur_code
           FROM schedules s JOIN routes r ON r.id = s.route_id
          WHERE s.id = :id',
        ['id' => $sid]
    );
    if ($before === null) {
        jsonErr('That trip no longer exists.');
    }
    if (strtolower((string) $before['status']) === 'cancelled') {
        jsonErr('That trip is cancelled — reopen it before changing its route.');
    }
    if (strtolower((string) $before['status']) === 'arrived') {
        jsonErr('That trip has already arrived — cannot change its route now.');
    }
    if ((int) $before['route_id'] === $newRoute) {
        jsonErr('That is already the current route.');
    }

    $newRow = Database::fetch(
        'SELECT id, route_code, coach_type, is_active
           FROM routes WHERE id = :id',
        ['id' => $newRoute]
    );
    if ($newRow === null) {
        jsonErr('That target route no longer exists.');
    }
    if ((int) $newRow['is_active'] !== 1) {
        jsonErr('Target route is disabled — enable it in Admin → Routes first.');
    }

    $curCoach = (string) $before['cur_coach'];
    $newCoach = (string) $newRow['coach_type'];
    if ($curCoach === '' || $newCoach === '' || $curCoach !== $newCoach) {
        jsonErr('Cannot change route: coach types differ ('
            . ($curCoach !== '' ? $curCoach : 'unknown')
            . ' vs '
            . ($newCoach !== '' ? $newCoach : 'unknown')
            . '). Seat IDs would break.');
    }

    // UNIQUE(route_id, travel_date, slot) pre-check for a friendly message.
    $clash = Database::fetch(
        'SELECT id FROM schedules
          WHERE route_id = :r AND travel_date = :d AND slot = :slot AND id <> :self
          LIMIT 1',
        ['r' => $newRoute, 'd' => (string) $before['travel_date'], 'slot' => (int) ($before['slot'] ?? 1), 'self' => $sid]
    );
    if ($clash !== null) {
        jsonErr('Another trip on the target route already exists for '
            . (string) $before['travel_date']
            . ' (id #' . (int) $clash['id'] . ').');
    }

    // If a bus is assigned, sanity-check that its seats still fit the
    // current bookings. Coach types match by construction above, so
    // this is defence-in-depth for capacity / inactive-bus / etc.
    if ((int) ($before['bus_id'] ?? 0) > 0) {
        Seats::assertBusSwapSafe($sid, (int) $before['bus_id']);
    }

    Database::update('schedules',
        ['route_id' => $newRoute], 'id = :id', ['id' => $sid]);

    Logger::audit(
        'trip.change_route',
        'schedule',
        (string) $sid,
        ['route_id' => $before['route_id'], 'route_code' => $before['cur_code']],
        ['route_id' => $newRoute, 'route_code' => $newRow['route_code']],
        'by admin #' . $adminId . ' · coach ' . $newCoach
    );

    jsonOk('Route changed to ' . (string) $newRow['route_code'] . '.', $sid, [
        'newRouteId'   => $newRoute,
        'newRouteCode' => (string) $newRow['route_code'],
        'coachType'    => $newCoach,
    ]);
}

/**
 * duplicate_trip — new schedule row on target_date, copying the source
 * schedule's bus_id / driver_id / dep_time_override. target_date must be
 * today or later. ScheduleMaker::insertOne enforces the UNIQUE guard,
 * so a friendly RuntimeException is turned into the JSON error here.
 */
function handleDuplicate(int $adminId): void
{
    $sid        = scheduleIdInput();
    $targetDate = trim((string) ($_POST['target_date'] ?? ''));

    if (!Security::isValidDate($targetDate)) {
        jsonErr('Target date must be YYYY-MM-DD.');
    }
    if ($targetDate < date('Y-m-d')) {
        jsonErr('Target date cannot be in the past.');
    }

    $before = Database::fetch(
        'SELECT id, route_id, bus_id, driver_id, dep_time_override, travel_date
           FROM schedules WHERE id = :id',
        ['id' => $sid]
    );
    if ($before === null) {
        jsonErr('That source trip no longer exists.');
    }

    $opts = [];
    if ((int) ($before['bus_id']    ?? 0) > 0) { $opts['bus_id']    = (int) $before['bus_id']; }
    if ((int) ($before['driver_id'] ?? 0) > 0) { $opts['driver_id'] = (int) $before['driver_id']; }
    if (!empty($before['dep_time_override'])) {
        $opts['dep_time_override'] = substr((string) $before['dep_time_override'], 0, 5);
    }

    try {
        $res = ScheduleMaker::insertOne((int) $before['route_id'], $targetDate, $opts);
    } catch (RuntimeException $e) {
        jsonErr($e->getMessage());
        return; // unreachable but keeps type-checkers calm
    }

    $newId = (int) $res['id'];

    Logger::audit(
        'trip.duplicate',
        'schedule',
        (string) $newId,
        ['source_schedule_id' => $sid, 'source_travel_date' => $before['travel_date']],
        [
            'route_id'          => (int) $before['route_id'],
            'travel_date'       => $targetDate,
            'bus_id'            => $opts['bus_id']    ?? null,
            'driver_id'         => $opts['driver_id'] ?? null,
            'dep_time_override' => $opts['dep_time_override'] ?? null,
        ],
        'duplicated from #' . $sid . ' by admin #' . $adminId
    );

    jsonOk('Trip duplicated to ' . $targetDate . '.', $newId, [
        'sourceScheduleId' => $sid,
        'targetDate'       => $targetDate,
        'routeCode'        => (string) $res['route_code'],
        'depTime'          => (string) ($res['dep_time'] ?? ''),
    ]);
}

/**
 * add_trip — fresh schedule row for (route_id, travel_date) with optional
 * dep_time / bus_id / driver_id overrides. All the validation
 * (route exists + active, date valid + not past, HH:MM shape, duplicate
 * guard, seat count fallback) lives in ScheduleMaker::insertOne; the
 * handler here is a thin argument mapper + audit.
 */
function handleAdd(int $adminId): void
{
    $routeId    = (int) ($_POST['route_id'] ?? 0);
    $travelDate = trim((string) ($_POST['travel_date'] ?? ''));
    $depRaw     = trim((string) ($_POST['dep_time'] ?? ''));
    $busId      = (int) ($_POST['bus_id'] ?? 0);
    $driverId   = (int) ($_POST['driver_id'] ?? 0);
    // Manual bus (4 Sep 2026): its own price and its own seat layout. There is
    // deliberately no seat-COUNT input — how many berths a coach has is a
    // property of its layout, so choosing the coach chooses the count with it.
    $fareIn     = trim((string) ($_POST['fare'] ?? ''));
    $coachIn    = trim((string) ($_POST['coach_type'] ?? ''));

    if ($routeId <= 0) {
        jsonErr('Please pick a route.');
    }
    if (!Security::isValidDate($travelDate)) {
        jsonErr('Travel date must be YYYY-MM-DD.');
    }

    $opts = [];
    if ($busId    > 0) { $opts['bus_id']    = $busId; }
    if ($driverId > 0) { $opts['driver_id'] = $driverId; }
    if ($depRaw !== '') {
        // Let ScheduleMaker reject a malformed value with its own
        // user-safe RuntimeException so the message stays canonical.
        $opts['dep_time_override'] = $depRaw;
    }
    if ($fareIn !== '')  { $opts['fare_override'] = $fareIn; }
    if ($coachIn !== '') { $opts['coach_type']    = $coachIn; }

    try {
        $res = ScheduleMaker::insertOne($routeId, $travelDate, $opts);
    } catch (RuntimeException $e) {
        jsonErr($e->getMessage());
        return;
    }

    $newId = (int) $res['id'];

    Logger::audit(
        'trip.add',
        'schedule',
        (string) $newId,
        null,
        [
            'route_id'          => $routeId,
            'travel_date'       => $travelDate,
            'bus_id'            => $opts['bus_id']    ?? null,
            'driver_id'         => $opts['driver_id'] ?? null,
            'dep_time_override' => $opts['dep_time_override'] ?? null,
            'fare_override'     => $opts['fare_override'] ?? null,
            'coach_type'        => $opts['coach_type'] ?? null,
        ],
        'added by admin #' . $adminId
    );

    jsonOk('Trip added for ' . $travelDate . '.', $newId, [
        'routeCode' => (string) $res['route_code'],
        'depTime'   => (string) ($res['dep_time'] ?? ''),
    ]);
}

/* ============================================================
 *  Manual bus management (4 Sep 2026)
 *
 *  set_price  — per-departure per-seat fare (schedules.fare_override).
 *               Blank / 0 clears it and the bus goes back to the normal
 *               route + direction fare. Already-sold tickets keep the
 *               amount they were sold at; this only prices NEW sales.
 *  seed_daily — top up the automatic daily bus by hand, the same call the
 *               nightly cron makes. Idempotent: existing rows are skipped.
 * ============================================================ */

function handleSetPrice(int $adminId): void
{
    $sid    = scheduleIdInput();
    $rawIn  = trim((string) ($_POST['fare'] ?? ''));

    $before = Database::fetch(
        'SELECT s.id, s.fare_override, s.status, s.travel_date, r.route_code, r.base_fare
           FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :id',
        ['id' => $sid]
    );
    if ($before === null) {
        jsonErr('That trip no longer exists.');
    }
    if (strtolower((string) $before['status']) === 'cancelled') {
        jsonErr('That trip is cancelled — reopen it before changing its price.');
    }

    try {
        $new = ScheduleMaker::fareInput($rawIn === '' ? null : $rawIn);
    } catch (RuntimeException $e) {
        jsonErr($e->getMessage());
        return;
    }

    $old = $before['fare_override'] === null ? null : round((float) $before['fare_override'], 2);
    if ($old === $new) {
        jsonErr($new === null ? 'That trip already runs at the normal fare.' : 'That is already the price for this trip.');
    }

    Database::update('schedules', ['fare_override' => $new], 'id = :id', ['id' => $sid]);

    Logger::audit('trip.set_price', 'schedule', (string) $sid,
        ['fare_override' => $old], ['fare_override' => $new],
        'price ' . ($new === null ? 'cleared (back to the normal fare)' : 'set to ' . inr($new) . ' per seat')
        . ' by admin #' . $adminId);

    jsonOk(
        $new === null
            ? 'Price cleared — this bus is back on the normal fare. Tickets already sold keep the amount they were sold at.'
            : 'Price set to ' . inr($new) . ' per seat for this bus. Tickets already sold are unchanged.',
        $sid,
        ['fare' => $new]
    );
}

/**
 * set_coach — the SEAT LAYOUT this departure runs. Refused once anything is
 * sold or held: the seat ids change with the layout, so a berth already on a
 * ticket could stop existing. Blank, or the route's own type, clears the
 * override and the bus goes back to the route's coach.
 */
function handleSetCoach(int $adminId): void
{
    $sid   = scheduleIdInput();
    $rawIn = trim((string) ($_POST['coach_type'] ?? ''));

    $before = Database::fetch(
        'SELECT s.id, s.coach_type_override, s.status, s.total_seats, r.coach_type AS route_coach
           FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :id',
        ['id' => $sid]
    );
    if ($before === null) {
        jsonErr('That trip no longer exists.');
    }
    if (strtolower((string) $before['status']) === 'cancelled') {
        jsonErr('That trip is cancelled — reopen it before changing its coach.');
    }

    // Physical berths, so the refusal quotes what the coach is really carrying
    // (a private cabin is one booking_seats row over two berths).
    $sold = Seats::occupiedBeds($sid);
    if ($sold > 0) {
        jsonErr('This bus already has ' . $sold . ' seat(s) sold — its seat layout can no longer be changed. Add a separate extra bus instead.');
    }
    $held = (int) Database::scalar(
        'SELECT COUNT(*) FROM seat_locks WHERE schedule_id = :s AND expires_at > NOW()',
        ['s' => $sid],
        0
    );
    if ($held > 0) {
        jsonErr('Someone is choosing seats on this bus right now — please try again in a few minutes.');
    }

    try {
        $new = ScheduleMaker::coachInput($rawIn === '' ? null : $rawIn, (string) $before['route_coach']);
    } catch (RuntimeException $e) {
        jsonErr($e->getMessage());
        return;
    }

    $old = $before['coach_type_override'] === null ? null : (string) $before['coach_type_override'];
    if ($old === $new) {
        jsonErr($new === null
            ? 'That bus already runs the coach this route normally uses.'
            : 'That bus is already a ' . $new . '.');
    }

    $effective = $new ?? (string) $before['route_coach'];
    $seats     = count(Seats::seatIds($effective, 'sharing'));

    Database::update(
        'schedules',
        ['coach_type_override' => $new, 'total_seats' => $seats],
        'id = :id',
        ['id' => $sid]
    );

    Logger::audit(
        'trip.set_coach',
        'schedule',
        (string) $sid,
        ['coach_type_override' => $old, 'total_seats' => (int) $before['total_seats']],
        ['coach_type_override' => $new, 'total_seats' => $seats],
        'seat layout changed to ' . $effective . ' by admin #' . $adminId
    );

    jsonOk(
        'This bus now runs a ' . $effective . ' coach with ' . $seats . ' seats.'
        . ($new === null ? ' It is back on the coach this route normally uses.' : ''),
        $sid,
        ['coach' => $effective, 'seats' => $seats]
    );
}

function handleSeedDaily(int $adminId): void
{
    $days = (int) ($_POST['days'] ?? 30);
    $days = max(1, min(ScheduleMaker::MAX_DAYS, $days));

    try {
        $res = ScheduleMaker::ensureNextDays(Database::pdo(), $days, 0);
    } catch (Throwable $e) {
        Logger::error('Manual daily-bus top-up failed', ['err' => $e->getMessage()], 'admin');
        jsonErr('The daily bus could not be topped up: ' . $e->getMessage());
        return;
    }

    Settings::set('daily_schedule_last_run', date('c'), 'string', 'booking', false);

    Logger::audit('daily.seed', 'schedule', $res['from'] . '..' . $res['to'], null,
        ['created' => $res['created'], 'skipped' => $res['skipped_existing'], 'routes' => $res['routes'], 'days' => $days],
        'daily bus topped up by hand by admin #' . $adminId);

    jsonOk(
        $res['created'] === 0
            ? 'Already up to date — the daily bus exists on every date to ' . $res['to'] . '.'
            : $res['created'] . ' daily departure(s) created, up to ' . $res['to'] . '.',
        0,
        $res
    );
}

/* ============================================================
 *  Bus Calendar handlers (5 Sep 2026)
 * ============================================================ */

/**
 * add_extra_bus — a SECOND (third, …) departure of the source trip's route
 * on the SAME date: a new schedules row with the next free slot, its own
 * bus / driver / departure time, its own seats. Bookings on the daily bus
 * are untouched. The nightly seeder never creates or removes these.
 */
function handleAddExtraBus(int $adminId): void
{
    $sid      = scheduleIdInput();
    $depRaw   = trim((string) ($_POST['dep_time'] ?? ''));
    $busId    = (int) ($_POST['bus_id'] ?? 0);
    $driverId = (int) ($_POST['driver_id'] ?? 0);

    $src = Database::fetch(
        'SELECT s.id, s.route_id, s.travel_date, s.bus_id, s.status, r.route_code
           FROM schedules s JOIN routes r ON r.id = s.route_id
          WHERE s.id = :id',
        ['id' => $sid]
    );
    if ($src === null) {
        jsonErr('That trip no longer exists.');
    }
    if ((string) $src['travel_date'] < date('Y-m-d')) {
        jsonErr('Cannot add a bus to a date that has passed.');
    }

    // Its own price and its own seat layout (4 Sep 2026), both optional: left
    // blank the extra bus runs at the normal fare on the route's own coach.
    $fareIn  = trim((string) ($_POST['fare'] ?? ''));
    $coachIn = trim((string) ($_POST['coach_type'] ?? ''));

    $slot = ScheduleMaker::nextFreeSlot((int) $src['route_id'], (string) $src['travel_date']);
    $opts = ['slot' => $slot];
    if ($busId > 0) {
        $bus = Database::fetch('SELECT id, total_seats, is_active FROM buses WHERE id = :b', ['b' => $busId]);
        if ($bus === null || (int) $bus['is_active'] !== 1) {
            jsonErr('Pick an active bus for the extra departure.');
        }
        $opts['bus_id'] = $busId;
        if ((int) $bus['total_seats'] > 0) { $opts['total_seats'] = (int) $bus['total_seats']; }
    } elseif ((int) ($src['bus_id'] ?? 0) > 0) {
        $opts['bus_id'] = (int) $src['bus_id'];   // same coach as bus 1 until the office picks another
    }
    if ($driverId > 0) { $opts['driver_id'] = $driverId; }
    if ($depRaw !== '') { $opts['dep_time_override'] = $depRaw; }
    if ($fareIn !== '')  { $opts['fare_override'] = $fareIn; }
    if ($coachIn !== '') {
        $opts['coach_type'] = $coachIn;
        // A chosen layout decides the seat count; drop the vehicle's own
        // figure copied in above so the two cannot disagree.
        unset($opts['total_seats']);
    }

    try {
        $res = ScheduleMaker::insertOne((int) $src['route_id'], (string) $src['travel_date'], $opts);
    } catch (RuntimeException $e) {
        jsonErr($e->getMessage());
        return;
    }
    $newId = (int) $res['id'];

    Logger::audit(
        'trip.add_extra',
        'schedule',
        (string) $newId,
        ['source_schedule_id' => $sid],
        [
            'route_id'          => (int) $src['route_id'],
            'travel_date'       => (string) $src['travel_date'],
            'slot'              => $slot,
            'bus_id'            => $opts['bus_id'] ?? null,
            'driver_id'         => $opts['driver_id'] ?? null,
            'dep_time_override' => $opts['dep_time_override'] ?? null,
            'total_seats'       => $opts['total_seats'] ?? null,
            'fare_override'     => $opts['fare_override'] ?? null,
            'coach_type'        => $opts['coach_type'] ?? null,
        ],
        'extra bus ' . $slot . ' added by admin #' . $adminId
    );

    $sameCoach = isset($opts['bus_id']) && (int) $opts['bus_id'] === (int) ($src['bus_id'] ?? 0) && $busId <= 0;
    jsonOk(
        'Extra bus ' . $slot . ' added for ' . (string) $src['travel_date'] . ' on ' . (string) $src['route_code']
        . ($depRaw !== '' ? ' departing ' . $depRaw : '') . '.'
        . ($sameCoach ? ' It is on the same coach as bus 1 for now — set its vehicle.' : ''),
        $newId,
        ['slot' => $slot, 'routeCode' => (string) $res['route_code'], 'depTime' => (string) ($res['dep_time'] ?? '')]
    );
}

/**
 * set_bus — which vehicle runs this departure. Same guard the Date View
 * uses (Seats::assertBusSwapSafe: capacity vs sold seats, coach type,
 * active bus) and the same seat-count sync, so the two screens agree.
 */
function handleSetBus(int $adminId): void
{
    $sid   = scheduleIdInput();
    $busId = (int) ($_POST['bus_id'] ?? 0);

    $before = Database::fetch('SELECT bus_id, total_seats, status FROM schedules WHERE id = :id', ['id' => $sid]);
    if ($before === null) {
        jsonErr('That trip no longer exists.');
    }
    if (strtolower((string) $before['status']) === 'cancelled') {
        jsonErr('That trip is cancelled — reopen it before changing its vehicle.');
    }

    if ($busId > 0) {
        $check = Seats::assertBusSwapSafe($sid, $busId);
        Database::transaction(static function () use ($sid, $busId, $check): void {
            Database::update('schedules', ['bus_id' => $busId], 'id = :id', ['id' => $sid]);
            if ((int) $check['newTotalSeats'] > 0) {
                Database::update('schedules', ['total_seats' => (int) $check['newTotalSeats']], 'id = :id', ['id' => $sid]);
            }
        });
        Logger::audit(
            ((int) ($before['bus_id'] ?? 0) > 0) ? 'trip.swap_bus' : 'trip.assign_bus',
            'schedule',
            (string) $sid,
            ['bus_id' => $before['bus_id'] ?? null, 'total_seats' => $before['total_seats'] ?? null],
            ['bus_id' => $busId, 'total_seats' => (int) $check['newTotalSeats'], 'bookedCount' => (int) $check['bookedCount']],
            'vehicle set from the Bus Calendar by admin #' . $adminId
        );
        $name = (string) Database::scalar('SELECT CONCAT(bus_name, " · ", bus_number) FROM buses WHERE id = :b', ['b' => $busId], '');
        jsonOk('Vehicle set to ' . $name . '.', $sid, ['busId' => $busId, 'totalSeats' => (int) $check['newTotalSeats']]);
    }

    Database::update('schedules', ['bus_id' => null], 'id = :id', ['id' => $sid]);
    Logger::audit('trip.assign_bus', 'schedule', (string) $sid, ['bus_id' => $before['bus_id'] ?? null], ['bus_id' => null],
        'vehicle un-assigned from the Bus Calendar by admin #' . $adminId);
    jsonOk('Vehicle un-assigned — the route default / rotation applies.', $sid);
}

/**
 * day_off / day_on — service OFF or ON for one DATE across every daily
 * route (both directions). OFF = is_blocked on every departure of the day:
 * hidden from search and refused at checkout, while sold tickets stay
 * valid (the office decides per PNR what to do with them). ON clears it.
 * The daily bus row is materialised first, so a future date can be
 * switched off before the nightly seeder reaches it.
 */
function handleDayOff(int $adminId, bool $off): void
{
    $date = trim((string) ($_POST['date'] ?? ''));
    if (!Security::isValidDate($date)) {
        jsonErr('Date must be YYYY-MM-DD.');
    }
    if ($date < date('Y-m-d')) {
        jsonErr('That date has already passed.');
    }

    $routes = Database::fetchAll("SELECT id, route_code FROM routes WHERE is_active = 1 AND dep_time IS NOT NULL ORDER BY sort_order, id");
    $touched = [];
    foreach ($routes as $r) {
        if ($off) {
            try { Seats::schedule((int) $r['id'], $date); } catch (Throwable $e) { /* no bus on the route — nothing to hide */ }
        }
        $rows = Database::fetchAll('SELECT id, is_blocked, status FROM schedules WHERE route_id = :r AND travel_date = :d', ['r' => (int) $r['id'], 'd' => $date]);
        foreach ($rows as $s) {
            if (strtolower((string) $s['status']) === 'cancelled') { continue; }
            if ((int) $s['is_blocked'] === ($off ? 1 : 0)) { continue; }
            Database::update('schedules', ['is_blocked' => $off ? 1 : 0], 'id = :id', ['id' => (int) $s['id']]);
            $touched[] = (int) $s['id'];
        }
    }

    Logger::audit($off ? 'day.off' : 'day.on', 'schedule', $date, null, ['schedule_ids' => $touched],
        ($off ? 'service OFF' : 'service ON') . ' for ' . $date . ' by admin #' . $adminId);

    jsonOk(
        $off
            ? 'Service OFF for ' . $date . ' — ' . count($touched) . ' departure(s) hidden from booking. Tickets already sold stay valid; handle them per PNR.'
            : 'Service ON for ' . $date . ' — ' . count($touched) . ' departure(s) open for booking again.',
        0,
        ['count' => count($touched), 'date' => $date]
    );
}

/* ============================================================
 *  Shared input helpers
 * ============================================================ */

function scheduleIdInput(): int
{
    $sid = (int) ($_POST['schedule_id'] ?? 0);
    if ($sid <= 0) {
        jsonErr('Missing schedule id.');
    }
    return $sid;
}
