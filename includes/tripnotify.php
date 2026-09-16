<?php
/**
 * =====================================================================
 *  TripNotify — automatic journey messaging (V6 §"WhatsApp + SMS
 *  Automation").
 *
 *  Five events reach the passenger without anyone in the office pressing
 *  anything twice:
 *
 *      reminder_12h  12 hours before departure   (cron/reminders.php)
 *      reminder_2h    2 hours before departure   (cron/reminders.php)
 *      departed      bus started                 (Admin -> Trips, 1 click)
 *      border        reached the border          (Admin -> Trips, 1 click)
 *      arrived       journey complete            (Admin -> Trips, 1 click)
 *
 *  Exactly-once is the whole point of this class. Every send is CLAIMED
 *  first with `INSERT IGNORE INTO trip_events` — which carries
 *  UNIQUE(booking_id, schedule_id, event) — and only the process that
 *  wins the claim sends. A cron running every five minutes, two crons
 *  overlapping, or an operator double-clicking "Bus started" therefore
 *  cannot message the same passenger twice.
 *
 *  A claim is kept even when the provider rejects the message (ok = 0).
 *  Re-sending on failure would mean a misconfigured WhatsApp sender
 *  re-queues every passenger on every cron tick for the next twelve
 *  hours; a failed row is visible in Admin -> Message Log instead, and
 *  the office can resend that one booking by hand.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class TripNotify
{
    /** Events an operator marks by hand from the Trips board. */
    public const MANUAL_EVENTS = ['departed', 'border', 'arrived'];

    /** Human labels — shared by the admin buttons and the audit trail. */
    public const LABELS = [
        'reminder_12h' => '12-hour reminder',
        'reminder_2h'  => '2-hour reminder',
        'departed'     => 'Bus started',
        'border'       => 'Reached border',
        'arrived'      => 'Arrived',
        'cancelled'    => 'Trip cancelled',
    ];


    /* =================================================================
     *  1. Departure reminders (cron)
     * ================================================================= */

    /**
     * Send every 12h / 2h reminder that is due right now.
     *
     * A booking made three hours before departure only ever gets the 2h
     * reminder — the 12h window has already passed, and claiming it now
     * would just deliver a "your bus leaves tomorrow" message to someone
     * boarding this afternoon.
     *
     * @return array{scanned:int, sent:int, failed:int, skipped:int, byEvent:array<string,int>}
     */
    public static function runReminders(int $limit = 500): array
    {
        $out = ['scanned' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0,
                'byEvent' => ['reminder_12h' => 0, 'reminder_2h' => 0]];

        if (!Settings::getBool('trip_reminders_enabled', true)) {
            $out['skipped'] = -1;   // master switch off — nothing scanned
            return $out;
        }

        $limit = max(1, min(2000, $limit));
        // dep_time on the reminder row is the effective SCHEDULED departure:
        // a per-schedule dep_time_override (delay-workflow reschedule) wins
        // over the route timetable so the 12h/2h band, the printed message
        // body, and the mins_to_go ordering all follow the new time. This is
        // separate from schedules.delay_minutes (the DELTA that fires the
        // "trip.delayed" WhatsApp) — that is not applied here.
        $rows  = Database::fetchAll(
            "SELECT b.id AS booking_id, b.pnr, b.contact_phone, b.contact_email,
                    b.id_type, b.total_amount, b.booking_mode,
                    COALESCE(s.coach_type_override, r.coach_type) AS coach_type,
                    bl.id AS leg_id, bl.schedule_id, bl.travel_date, bl.boarding_stop,
                    r.from_city, r.to_city,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    TIMESTAMPDIFF(MINUTE, NOW(), TIMESTAMP(bl.travel_date, COALESCE(s.dep_time_override, r.dep_time))) AS mins_to_go,
                    (SELECT GROUP_CONCAT(bp.seat_no ORDER BY bp.seat_no SEPARATOR ', ')
                       FROM booking_passengers bp
                      WHERE bp.booking_id = b.id AND bp.leg_id = bl.id) AS seats
               FROM bookings b
               JOIN booking_legs bl ON bl.booking_id = b.id
               JOIN schedules    s  ON s.id  = bl.schedule_id
               JOIN routes       r  ON r.id  = s.route_id
              WHERE b.status = 'confirmed'
                AND s.status = 'scheduled'
                AND b.contact_phone <> ''
                AND TIMESTAMP(bl.travel_date, COALESCE(s.dep_time_override, r.dep_time)) >  NOW()
                AND TIMESTAMP(bl.travel_date, COALESCE(s.dep_time_override, r.dep_time)) <= DATE_ADD(NOW(), INTERVAL 12 HOUR)
              ORDER BY mins_to_go ASC
              LIMIT " . $limit
        );

        $out['scanned'] = count($rows);

        foreach ($rows as $row) {
            $mins = (int) $row['mins_to_go'];
            // Pick by band, with a floor under the 12h reminder: a booking made
            // (or confirmed) 3-10 hours before departure must NOT be told
            // "your journey is tomorrow" (the reminder_12h body is hardcoded
            // "भोलि यात्रा" / tomorrow). Only the genuine ~12h-out window gets
            // it; the 2-10h dead-band waits until it enters the 2h window. The
            // 15-min cron sweeps every booking through the 600-720 window, so
            // early bookings still get their 12h reminder on time.
            if ($mins <= 120) {
                $event = 'reminder_2h';
            } elseif ($mins > 600) {
                $event = 'reminder_12h';
            } else {
                continue;                    // 2-10h band: nothing to send yet
            }

            if (!Notify::tripEventEnabled($event)) {
                $out['skipped']++;
                continue;
            }

            $res = self::deliver($row, $event);
            if ($res === null) {
                $out['skipped']++;          // already sent by an earlier run
                continue;
            }

            $out['byEvent'][$event]++;
            $res['ok'] ? $out['sent']++ : $out['failed']++;
        }

        return $out;
    }


    /* =================================================================
     *  2. Journey milestones (one click on Admin -> Trips)
     * ================================================================= */

    /**
     * Mark a trip as started / at the border / arrived, and tell everyone
     * booked on it.
     *
     * Safe to press twice: the trip_status row is claimed once, and the
     * passenger fan-out skips anyone already messaged. Pressing it again
     * after a late booking is confirmed is in fact the right way to catch
     * that passenger up — they get the message, nobody else is re-sent.
     *
     * @return array{ok:bool, event:string, fresh:bool, notified:int, failed:int, skipped:int, message:string}
     */
    public static function markTrip(int $scheduleId, string $event, int $adminId = 0): array
    {
        if (!in_array($event, self::MANUAL_EVENTS, true)) {
            throw new RuntimeException('Unknown trip status.');
        }

        // dep_time is the effective SCHEDULED time — per-schedule
        // dep_time_override wins so the milestone banner and audit trail
        // reflect the rescheduled departure, not the timetable default.
        $trip = Database::fetch(
            "SELECT s.id, s.status, s.travel_date, r.from_city, r.to_city,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.id = :s",
            ['s' => $scheduleId]
        );
        if ($trip === null) {
            throw new RuntimeException('That trip no longer exists.');
        }

        // Claim the milestone. rowCount tells us whether this press was the
        // first one — insertIgnore() can't, because trip_status has no
        // AUTO_INCREMENT column for lastInsertId() to report.
        $claim = Database::query(
            'INSERT IGNORE INTO `trip_status` (`schedule_id`, `event`, `admin_id`) VALUES (:s, :e, :a)',
            ['s' => $scheduleId, 'e' => $event, 'a' => $adminId > 0 ? $adminId : null]
        );
        $fresh = $claim->rowCount() > 0;

        // Journal the trip-level milestone exactly once ($fresh). A re-press
        // is the sanctioned way to catch up late bookings via the
        // per-passenger claims below — it must not re-emit the trip event.
        if ($fresh) {
            EventBus::emit('trip.' . $event, [
                'schedule_id' => $scheduleId,
                'route'       => $trip['from_city'] . ' → ' . $trip['to_city'],
                'date'        => (string) $trip['travel_date'],
                'admin_id'    => $adminId,
            ]);
        }

        // Keep schedules.status in step. 'border' implies the bus has left,
        // so it promotes a still-'scheduled' trip to 'departed'.
        $newStatus = match ($event) {
            'departed', 'border' => 'departed',
            'arrived'            => 'arrived',
        };
        if ((string) $trip['status'] !== $newStatus && (string) $trip['status'] !== 'cancelled') {
            // Never walk a trip backwards from arrived to departed.
            if (!($newStatus === 'departed' && (string) $trip['status'] === 'arrived')) {
                Database::update('schedules', ['status' => $newStatus], 'id = :id', ['id' => $scheduleId]);
            }
        }

        $notified = 0; $failed = 0; $skipped = 0;

        if (Notify::tripEventEnabled($event)) {
            foreach (self::passengersOn($scheduleId) as $row) {
                $res = self::deliver($row, $event);
                if ($res === null)      { $skipped++; }
                elseif ($res['ok'])     { $notified++; }
                else                    { $failed++; }
            }
        } else {
            $skipped = -1;   // channel switched off in Settings
        }

        Database::update('trip_status',
            ['notified' => max(0, $notified)],
            'schedule_id = :s AND `event` = :e',
            ['s' => $scheduleId, 'e' => $event]
        );

        Logger::audit('trip.' . $event, 'schedule', (string) $scheduleId, null, null,
            self::LABELS[$event] . ' · ' . $trip['from_city'] . '→' . $trip['to_city']
            . ' ' . $trip['travel_date'] . ' · notified ' . $notified);

        $message = self::LABELS[$event] . ' recorded for '
            . $trip['from_city'] . ' → ' . $trip['to_city'] . ' (' . formatDate((string) $trip['travel_date']) . '). ';

        if ($skipped === -1) {
            $message .= 'Passenger messages are switched off in Settings, so nobody was notified.';
        } elseif ($notified === 0 && $failed === 0 && $skipped === 0) {
            $message .= 'No confirmed passengers on this trip yet.';
        } else {
            $message .= $notified . ' passenger' . ($notified === 1 ? '' : 's') . ' notified'
                . ($skipped > 0 ? ', ' . $skipped . ' already had this message' : '')
                . ($failed  > 0 ? ', ' . $failed . ' could not be delivered — see Message Log' : '')
                . '.';
        }

        return ['ok' => true, 'event' => $event, 'fresh' => $fresh,
                'notified' => $notified, 'failed' => $failed, 'skipped' => max(0, $skipped),
                'message' => $message];
    }

    /**
     * Which milestones has this trip already passed?
     *
     * @param array<int, int> $scheduleIds
     * @return array<int, array<string, string>> scheduleId => [event => when]
     */
    public static function statusMap(array $scheduleIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $scheduleIds)));
        if ($ids === []) {
            return [];
        }

        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $map = [];
        foreach (Database::fetchAll(
            "SELECT schedule_id, `event`, created_at, notified
               FROM trip_status WHERE schedule_id IN ($ph)",
            $ids
        ) as $r) {
            $map[(int) $r['schedule_id']][(string) $r['event']] = (string) $r['created_at'];
        }

        return $map;
    }


    /* =================================================================
     *  3. Time-based promotion (cron/lifecycle.php)
     * ================================================================= */

    /**
     * Promote every still-'scheduled' trip whose departure time has
     * already passed to 'departed', firing the same messaging + audit
     * trail as the admin "Bus started" button.
     *
     * Staff sometimes forget to press "Started" on Admin -> Trips. Without
     * this sweep the schedule row would stay 'scheduled' forever even
     * after the bus rolls out, blocking downstream cut-offs and letting
     * runReminders() keep re-scanning departed trips.
     *
     * markTrip() is idempotent (trip_status has UNIQUE(schedule_id,event)
     * and each passenger claim is UNIQUE(booking_id,schedule_id,event)),
     * so a bad-day cron that races an operator click cannot double-message
     * anyone.
     *
     * schedules has no dep_time of its own — the timetable lives in
     * routes.dep_time, so the SELECT joins routes and compares
     * TIMESTAMP(s.travel_date, r.dep_time) to NOW().
     *
     * One bad row is caught and logged; the batch keeps going.
     *
     * @return int number of schedules successfully promoted
     */
    /**
     * Promote a departed trip to 'arrived' once it is long past due.
     *
     * OFF BY DEFAULT and must stay that way until the owner has watched one
     * trip through it: 'arrived' is in Notify::TRIP_EVENTS, so flipping this
     * on WhatsApps every passenger on every completed trip. The exactly-once
     * claim in markTrip() (INSERT IGNORE on trip_status) is what makes that
     * safe to leave running — a cron racing an operator's "Arrived" click
     * cannot double-message anyone.
     *
     * Counted from scheduled DEPARTURE, not from routes.arr_time. The
     * corridor is ~1,375 km and arrival legitimately lands on the next day;
     * arr_time is also deliberately blanked on routes with no honest clock
     * (Ticket renders duration_text = '' rather than invent one). Departure
     * plus a generous, admin-set number of hours is the only figure here
     * that is always true.
     *
     * @param int $hoursAfterDeparture 0 disables (the default).
     * @return int trips promoted on this run
     */
    public static function promoteArrivedByTime(int $hoursAfterDeparture): int
    {
        $hours = max(0, min(240, $hoursAfterDeparture));
        if ($hours === 0) {
            return 0;   // feature off
        }

        $rows = Database::fetchAll(
            "SELECT s.id, r.from_city, r.to_city
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
              WHERE s.status = 'departed'
                AND TIMESTAMP(s.travel_date, COALESCE(s.dep_time_override, r.dep_time))
                    <= DATE_SUB(NOW(), INTERVAL :h HOUR)
              ORDER BY s.travel_date ASC, s.id ASC
              LIMIT 200",
            ['h' => $hours]
        );

        $promoted = 0;
        foreach ($rows as $row) {
            $scheduleId = (int) $row['id'];
            try {
                $res = self::markTrip($scheduleId, 'arrived', 0);
                if (!empty($res['fresh'])) {
                    $promoted++;
                }
            } catch (Throwable $e) {
                Logger::error('lifecycle arrive failed', [
                    'schedule_id' => $scheduleId,
                    'route'       => trim((string) $row['from_city']) . '->' . trim((string) $row['to_city']),
                    'err'         => $e->getMessage(),
                ], 'cron');
            }
        }

        return $promoted;
    }

    public static function promoteDepartedByTime(int $graceMinutes = 5): int
    {
        $grace = max(0, min(1440, $graceMinutes));

        // Effective departure = COALESCE(dep_time_override, r.dep_time); a
        // per-schedule override (delay-workflow reschedule) is the SCHEDULED
        // time this cron should compare against, so the promote-window is
        // "actually late", not "late against the printed timetable".
        // delay_minutes is a runtime DELTA and stays out of this comparison —
        // TripStatus consumes it separately.
        $rows = Database::fetchAll(
            "SELECT s.id, r.from_city, r.to_city
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
              WHERE s.status = 'scheduled'
                AND TIMESTAMP(s.travel_date, COALESCE(s.dep_time_override, r.dep_time))
                    <= DATE_SUB(NOW(), INTERVAL :g MINUTE)
              ORDER BY s.travel_date ASC, s.id ASC
              LIMIT 500",
            ['g' => $grace]
        );

        $promoted = 0;
        foreach ($rows as $row) {
            $scheduleId = (int) $row['id'];
            try {
                $res = self::markTrip($scheduleId, 'departed', 0);
                if (!empty($res['fresh'])) {
                    $promoted++;
                }
            } catch (Throwable $e) {
                Logger::error('lifecycle promote failed', [
                    'schedule_id' => $scheduleId,
                    'route'       => trim((string) $row['from_city']) . '->' . trim((string) $row['to_city']),
                    'err'         => $e->getMessage(),
                ], 'cron');
            }
        }

        return $promoted;
    }


    /* =================================================================
     *  Internals
     * ================================================================= */

    /**
     * Every confirmed passenger booked onto one schedule, with the facts
     * their message needs.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function passengersOn(int $scheduleId): array
    {
        // dep_time in the notify context is the effective SCHEDULED time —
        // a per-schedule dep_time_override wins so the message body carries
        // the rescheduled departure, not the printed timetable.
        return Database::fetchAll(
            "SELECT b.id AS booking_id, b.pnr, b.contact_phone, b.contact_email, b.id_type,
                    b.booking_mode,
                    COALESCE(s.coach_type_override, r.coach_type) AS coach_type,
                    bl.id AS leg_id, bl.schedule_id, bl.travel_date, bl.boarding_stop,
                    r.from_city, r.to_city,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    (SELECT GROUP_CONCAT(bp.seat_no ORDER BY bp.seat_no SEPARATOR ', ')
                       FROM booking_passengers bp
                      WHERE bp.booking_id = b.id AND bp.leg_id = bl.id) AS seats
               FROM booking_legs bl
               JOIN bookings b ON b.id = bl.booking_id
               JOIN schedules s ON s.id = bl.schedule_id
               JOIN routes    r ON r.id = s.route_id
              WHERE bl.schedule_id = :s
                AND b.status = 'confirmed'
                AND b.contact_phone <> ''
              ORDER BY b.id",
            ['s' => $scheduleId]
        );
    }

    /**
     * Claim, send, record. Returns null when this exact message was
     * already claimed by an earlier run (nothing was sent).
     *
     * @param array<string, mixed> $row one row from runReminders()/passengersOn()
     * @return array{ok:bool, channels:string, detail:string}|null
     */
    /**
     * Phase 6 — Delay notification. Messages every confirmed passenger
     * on this schedule that the bus is delayed. Uses the exactly-once
     * claim as every other event ('delayed' in trip_events).
     *
     * A second call with a larger delay won't re-send — the claim is
     * already taken. Clear the delay (set to 0) and re-set it to
     * re-notify, but in practice the office sets it once.
     */
    public static function notifyDelay(int $scheduleId, int $minutes, string $note = '', int $adminId = 0): array
    {
        // dep_time is the effective SCHEDULED time (override wins over the
        // route timetable). delay_minutes / delay_note are the DELTA the
        // template ships to the passenger — they stay separate and are NOT
        // folded into dep_time here.
        $trip = Database::fetch(
            "SELECT s.id, s.status, s.travel_date, r.from_city, r.to_city,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.id = :s",
            ['s' => $scheduleId]
        );
        if ($trip === null) {
            return ['ok' => false, 'notified' => 0, 'failed' => 0, 'message' => 'Trip not found.'];
        }

        // Don't message passengers about a delay on a trip that has
        // already departed or arrived — they're on the bus already.
        if (in_array((string) $trip['status'], ['departed', 'arrived'], true)) {
            return ['ok' => true, 'notified' => 0, 'failed' => 0, 'message' => 'Trip already departed; passengers not notified.'];
        }

        EventBus::emit('trip.delayed', [
            'schedule_id'   => $scheduleId,
            'route'         => $trip['from_city'] . ' → ' . $trip['to_city'],
            'date'          => (string) $trip['travel_date'],
            'delay_minutes' => $minutes,
            'delay_note'    => $note,
            'admin_id'      => $adminId,
        ]);

        $passengers = self::passengersOn($scheduleId);
        $notified   = 0;
        $failed     = 0;
        foreach ($passengers as $p) {
            // Inject delay context into the delivery row so the
            // template can reference delayMinutes / delayNote.
            $p['delayMinutes'] = $minutes;
            $p['delayNote']    = $note;
            $res = self::deliver($p, 'delayed');
            if ($res === null) { continue; }  // already sent
            $res['ok'] ? $notified++ : $failed++;
        }

        return [
            'ok'       => true,
            'notified' => $notified,
            'failed'   => $failed,
            'message'  => $notified > 0
                ? "Delay notification sent to {$notified} passenger(s)."
                : ($failed > 0 ? "Failed to notify {$failed} passenger(s)." : 'No passengers to notify.'),
        ];
    }

    /**
     * Round 2 — Trip cancellation broadcaster. Called from Admin →
     * Schedule Manager's "Cancel trip" action AFTER the schedules row
     * has been flipped to status='cancelled' (see the "mark-only"
     * flow — this method does NOT release seats or process refunds).
     *
     * Exactly-once via the same trip_events ledger every other event
     * uses (kind='cancelled'). A re-cancel or a manual re-fire will not
     * spam passengers — the second call's claims all no-op.
     *
     * Skips the fan-out entirely when the trip has already departed or
     * arrived: passengers are on the bus, telling them the trip is
     * "cancelled" would be confusing and wrong.
     *
     * @return array{ok:bool, sent:int, skipped:int, failed:int, message:string}
     */
    public static function notifyCancellation(int $scheduleId, string $reason = '', int $byAdminId = 0): array
    {
        $trip = Database::fetch(
            "SELECT s.id, s.status, s.travel_date, r.from_city, r.to_city,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.id = :s",
            ['s' => $scheduleId]
        );
        if ($trip === null) {
            return ['ok' => false, 'sent' => 0, 'skipped' => 0, 'failed' => 0, 'message' => 'Trip not found.'];
        }

        // Don't message passengers of a trip that already departed / arrived —
        // they're on the bus. Same rule as notifyDelay.
        if (in_array((string) $trip['status'], ['departed', 'arrived'], true)) {
            return ['ok' => true, 'sent' => 0, 'skipped' => 0, 'failed' => 0,
                    'message' => 'Trip already departed; passengers not notified.'];
        }

        // Journal the trip-level event once. EventBus writes are cheap; the
        // per-passenger deliver() calls carry their own idempotency ledger
        // via trip_events UNIQUE(booking_id, schedule_id, event).
        EventBus::emit('trip.cancelled', [
            'schedule_id' => $scheduleId,
            'route'       => $trip['from_city'] . ' → ' . $trip['to_city'],
            'date'        => (string) $trip['travel_date'],
            'reason'      => $reason,
            'admin_id'    => $byAdminId,
        ]);

        $sent = 0; $skipped = 0; $failed = 0;
        foreach (self::passengersOn($scheduleId) as $p) {
            // Feed the cancel reason into the delivery context so the
            // WhatsApp/SMS body can reference it.
            $p['cancelReason'] = $reason;
            $res = self::deliver($p, 'cancelled');
            if ($res === null) { $skipped++; continue; }   // already claimed
            $res['ok'] ? $sent++ : $failed++;
        }

        Logger::audit('trip.cancelled.notify', 'schedule', (string) $scheduleId, null,
            ['sent' => $sent, 'skipped' => $skipped, 'failed' => $failed],
            self::LABELS['cancelled'] . ' · ' . $trip['from_city'] . '→' . $trip['to_city']
            . ' ' . $trip['travel_date'] . ' · by admin #' . $byAdminId
            . ($reason !== '' ? ' · reason: ' . mb_substr($reason, 0, 120) : ''));

        if ($sent === 0 && $failed === 0 && $skipped === 0) {
            $message = 'Trip cancelled. No confirmed passengers to notify.';
        } else {
            $message = 'Trip cancelled. ' . $sent . ' passenger' . ($sent === 1 ? '' : 's') . ' notified'
                . ($skipped > 0 ? ', ' . $skipped . ' already had this message' : '')
                . ($failed  > 0 ? ', ' . $failed . ' could not be delivered — see Message Log' : '')
                . '.';
        }

        return ['ok' => true, 'sent' => $sent, 'skipped' => $skipped, 'failed' => $failed, 'message' => $message];
    }

    private static function deliver(array $row, string $event): ?array
    {
        $bookingId  = (int) $row['booking_id'];
        $scheduleId = (int) $row['schedule_id'];

        $claim = Database::query(
            'INSERT IGNORE INTO `trip_events` (`booking_id`, `schedule_id`, `event`) VALUES (:b, :s, :e)',
            ['b' => $bookingId, 's' => $scheduleId, 'e' => $event]
        );
        if ($claim->rowCount() === 0) {
            return null;                       // someone already has this one
        }

        $booking = [
            'id'            => $bookingId,
            'pnr'           => (string) $row['pnr'],
            'contact_phone' => (string) $row['contact_phone'],
            'contact_email' => (string) ($row['contact_email'] ?? ''),
            'id_type'       => (string) ($row['id_type'] ?? ''),
        ];

        // Row-letter grid ids (LA1, LA2 …) for the reminder body, matching the
        // ticket and app. The GROUP_CONCAT gives a canonical 'L1, L2' string;
        // split, relabel each, rejoin. coach + booking_mode ride along on $row.
        if (!class_exists('Seats')) { require_once __DIR__ . '/seats.php'; }
        $ctxSeats = Seats::displayLabels(
            array_values(array_filter(array_map('trim', explode(',', (string) ($row['seats'] ?? ''))), static fn($s) => $s !== '')),
            (string) ($row['coach_type'] ?? 'sleeper') ?: 'sleeper',
            (string) ($row['booking_mode'] ?? 'sharing') ?: 'sharing'
        );

        $ctx = [
            'route'        => trim((string) $row['from_city']) . ' → ' . trim((string) $row['to_city']),
            'date'         => formatDate((string) $row['travel_date']),
            'depTime'      => formatTime((string) ($row['dep_time'] ?? '')),
            'seats'        => $ctxSeats,
            'boarding'     => (string) ($row['boarding_stop'] ?? ''),
            'delayMinutes' => (int) ($row['delayMinutes'] ?? 0),
            'delayNote'    => (string) ($row['delayNote'] ?? ''),
            'cancelReason' => (string) ($row['cancelReason'] ?? ''),
        ];

        try {
            $res = Notify::tripEvent($booking, $event, $ctx);
        } catch (Throwable $e) {
            Logger::error('TripNotify send failed', ['pnr' => $booking['pnr'], 'event' => $event, 'err' => $e->getMessage()], 'notify');
            $res = ['ok' => false, 'channels' => '', 'detail' => $e->getMessage()];
        }

        Database::update('trip_events', [
            'channels' => substr($res['channels'], 0, 40),
            'ok'       => $res['ok'] ? 1 : 0,
            'detail'   => mb_substr($res['detail'], 0, 255),
        ], 'booking_id = :b AND schedule_id = :s AND `event` = :e',
           ['b' => $bookingId, 's' => $scheduleId, 'e' => $event]);

        return $res;
    }
}
