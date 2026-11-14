<?php
/**
 * =====================================================================
 *  includes/tripevents.php - what the driver reports from the road
 *  (15 Nov 2026, driver/ app, api/driver.php, table driver_events,
 *  database/upgrade-2026-11-15-trip-events.sql).
 *
 *  The trip screen of the driver app gets a row of buttons. Each tap is
 *  one driver_events row with the phone's latest position:
 *
 *    stop_reached  "Stop pugyo"   the stop the coach is at (one of the
 *                                 route's own stops, never free text);
 *                                 live_trips.current_stop follows it
 *    delay         "Dhilo"        minutes late + a short reason. Fed to
 *                                 schedules.delay_minutes / delay_note the
 *                                 way the office's "Set delay" does (audit
 *                                 trip.set_delay), so the trips board, the
 *                                 track page and the trip_late alert rule
 *                                 read it. Passengers are NOT messaged from
 *                                 here: the trip_late rule's own opt-in
 *                                 (alert_trip_late_auto, 0 = never) decides.
 *                                 Switch: driver_delay_to_schedule (on).
 *    breakdown     "Bus bigriyo"  } the owner / office are told AT ONCE:
 *    sos           SOS            } the panel bell (AdminAlerts::push) and a
 *                                   WhatsApp to admin_whatsapp + ceo_whatsapp
 *                                   (bus, place as a Google Maps link, time).
 *                                   Switch: driver_sos_whatsapp_on (on - the
 *                                   owner's rule allows WhatsApp to the owner
 *                                   by default only for SOS / breakdown).
 *                                   One alert per kind per trip per 5 min.
 *    rest_start / rest_end        the driver's rest, for the continuous-
 *                                 driving timer (4 h gentle, 5 h strong)
 *
 *  Nothing here refunds, cancels, confirms or messages a passenger.
 *
 *  Also for the trip screen (all read-only):
 *    nav()       the route's stops in travel order, which are behind the
 *                coach, the next one and a Google Maps directions link
 *                through the stops still ahead (mapsUrl())
 *    manifest()  passengers grouped by pickup in route order: seat, first
 *                name, the booking's phone, boarded tick. No ID numbers,
 *                no fares.
 *    restState() resting now? since when? last rest end?
 *  and noteBattery(): the phone's battery from each GPS ping, kept in
 *  kv_store (scope driver_battery, key trip:<id>) - no new column.
 *
 *  THE TABLE IS driver_events: `trip_events` already exists (the passenger
 *  notification ledger of includes/tripnotify.php). available() is false
 *  until the migration has run, and every caller degrades quietly.
 *
 *  Settings (all optional):
 *    driver_actions_on          1   the buttons on the trip screen
 *    driver_sos_whatsapp_on     1   WhatsApp the owner / CEO on SOS / breakdown
 *    driver_delay_to_schedule   1   "Dhilo" sets the schedule's delay
 *    driver_speed_warn_kmh      80  the phone warns above this speed
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/livetrips.php';
require_once __DIR__ . '/etaalerts.php';

/**
 * A tap the server refuses, with a message for the driver; getCode() = the
 * HTTP status. Its own class so api/driver.php answers ONLY these as a
 * refusal: a PDOException is a RuntimeException too, and a database fault
 * must stay a 500 (the phone keeps the tap and re-sends it) - never a 422
 * that makes the phone drop an SOS and shows the driver the SQL error.
 */
final class TripEventRefused extends RuntimeException
{
}

final class TripEvents
{
    public const TABLE            = 'driver_events';
    public const KINDS            = ['stop_reached', 'delay', 'breakdown', 'sos', 'rest_start', 'rest_end'];
    public const ALERT_KINDS      = ['sos', 'breakdown'];
    public const REST_SOFT_MIN    = 240;   // 4 h: "aaram garnus"
    public const REST_HARD_MIN    = 300;   // 5 h: the strong warning
    public const MAX_WAYPOINTS    = 9;     // Google Maps directions URL limit
    public const ALERT_REPEAT_SEC = 300;   // one owner alert per kind per trip per 5 min
    public const DELAY_MIN        = 5;
    public const DELAY_MAX        = 600;   // the office's own cap (admin/trips.php set_delay)
    public const BATTERY_LOW_PCT  = 20;
    public const BATTERY_EVERY_S  = 300;   // a battery level is stored at most every 5 min (unless it changes)
    public const FRESH_FIX_SEC    = 900;   // a fix newer than this places the coach for nav()
    public const PASSED_KM        = 2.0;   // a stop this far behind the coach is passed

    /** Tests: fn(string $to, string $text, string $purpose): bool - replaces Notify::whatsapp for the office alert. */
    public static ?Closure $send = null;

    private static ?bool $available = null;

    public static function enabled(): bool
    {
        return Settings::getBool('driver_actions_on', true);
    }

    /** Is the migration on this database? */
    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                Database::fetch('SELECT kind, resolved_at FROM ' . self::TABLE . ' LIMIT 1');
                self::$available = true;
            } catch (Throwable $e) {
                self::$available = false;
            }
        }
        return self::$available;
    }

    /** For tests: forget the cached availability answer. */
    public static function resetCache(): void
    {
        self::$available = null;
    }

    public static function speedWarnKmh(): int
    {
        return max(30, min(150, Settings::getInt('driver_speed_warn_kmh', 80)));
    }

    /**
     * What the trip screen needs to know once (api/driver.php ?action=me).
     *
     * @return array<string,mixed>
     */
    public static function config(): array
    {
        $tel = Settings::officeWhatsApp();
        return [
            'actions'       => self::enabled() && self::available(),
            'speedWarnKmh'  => self::speedWarnKmh(),
            'restSoftMin'   => self::REST_SOFT_MIN,
            'restHardMin'   => self::REST_HARD_MIN,
            'batteryLowPct' => self::BATTERY_LOW_PCT,
            'officePhone'   => Settings::officePhone(),
            'officeTel'     => $tel !== '' ? '+' . $tel : '',
        ];
    }

    /* ---------------------------------------------------------------
     *  The buttons
     * ------------------------------------------------------------- */

    /**
     * The handler of api/driver.php action=event.
     *
     * @param array<string,mixed> $driver DriverAuth::current()
     * @param array<string,mixed> $trip   the live_trips row this phone runs (already checked)
     * @param array<string,mixed> $in     {kind, stop?, minutes?, note?, lat?, lng?, t? (ms)}
     * @return array<string,mixed> {event, repeat, alert, nav?, rest?}
     * @throws TripEventRefused a message for the driver; getCode() = the HTTP status
     */
    public static function report(array $driver, array $trip, array $in, ?int $now = null): array
    {
        $now = $now ?? time();
        if (!self::enabled()) {
            throw new TripEventRefused('These buttons are switched off by the office.', 503);
        }
        if (!self::available()) {
            throw new TripEventRefused('These buttons are not set up on this server yet.', 503);
        }
        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new TripEventRefused('Unknown button.', 422);
        }
        /* An SOS / breakdown from this phone's own trip is never refused for
           bookkeeping: one pressed in a dead zone can reach the server after
           the office has ended the trip, and a 409 would make the phone drop
           it. Every other button needs the trip still running. */
        if ((string) ($trip['status'] ?? '') !== 'running' && !in_array($kind, self::ALERT_KINDS, true)) {
            throw new TripEventRefused('This trip has ended.', 409);
        }
        /* Per driver. SOS / breakdown have their own budget, so a driver who
           tapped "Stop pugyo" thirty times can still raise an alarm. */
        $who     = 'drv' . (int) $driver['driverId'];
        $allowed = in_array($kind, self::ALERT_KINDS, true)
            ? Security::rateLimit('driver_sos', $who, 10, 600)
            : Security::rateLimit('driver_event', $who, 30, 600);
        if (!$allowed) {
            throw new TripEventRefused('Too many taps. Wait a minute, or call the office.', 429);
        }

        $tripId  = (int) $trip['id'];
        $routeId = (int) $trip['route_id'];
        $at      = self::clientTime($in['t'] ?? null, $now);
        $pos     = self::position($in, $trip);
        $note    = Security::clean((string) (is_scalar($in['note'] ?? null) ? $in['note'] : ''), 200);
        $stop    = null;
        $minutes = null;

        if ($kind === 'stop_reached') {
            $stop = self::matchStop($routeId, (string) (is_scalar($in['stop'] ?? null) ? $in['stop'] : ''));
            if ($stop === null) {
                throw new TripEventRefused('Pick the stop from the list.', 422);
            }
        } elseif ($kind === 'delay') {
            $m = $in['minutes'] ?? null;
            if (!is_numeric($m) || (int) $m < self::DELAY_MIN || (int) $m > self::DELAY_MAX) {
                throw new TripEventRefused('The delay must be ' . self::DELAY_MIN . ' to ' . self::DELAY_MAX . ' minutes.', 422);
            }
            $minutes = (int) $m;
        } elseif ($kind === 'rest_start' || $kind === 'rest_end') {
            $rest = self::restState($tripId);
            if (($kind === 'rest_start') === $rest['resting']) {
                // Already resting / already driving (a double tap, a re-sent queue): nothing new.
                return ['event' => null, 'repeat' => true, 'alert' => null, 'rest' => $rest];
            }
        }

        $ctx = self::context($tripId);
        $row = [
            'live_trip_id' => $tripId,
            'schedule_id'  => $trip['schedule_id'] !== null ? (int) $trip['schedule_id'] : null,
            'bus_label'    => $ctx['bus'] !== '' ? mb_substr($ctx['bus'], 0, 40) : null,
            'driver_ref'   => 'D' . (int) $driver['driverId'],
            'kind'         => $kind,
            'stop_name'    => $stop,
            'minutes'      => $minutes,
            'note'         => $note !== '' ? $note : null,
            'lat'          => $pos['lat'],
            'lng'          => $pos['lng'],
            'created_at'   => date('Y-m-d H:i:s', $at),
        ];
        // Only a tap stamped by the phone's clock can be a re-send of one the server already has.
        $rec = self::record($row, is_numeric($in['t'] ?? null));
        $row['id'] = $rec['id'];

        $out = ['event' => self::shape($row), 'repeat' => $rec['repeat'], 'alert' => null];
        if ($rec['repeat']) {
            return $out;   // the phone re-sent a tap the server already has: no second side effect
        }

        Logger::audit('trip.driver_' . $kind, 'schedule', (string) ($row['schedule_id'] ?? ''), null,
            ['trip' => $tripId, 'event' => $rec['id'], 'stop' => $stop, 'minutes' => $minutes, 'note' => $row['note']],
            'driver app · driver #' . (int) $driver['driverId']);

        if ($kind === 'stop_reached') {
            Database::update('live_trips', ['current_stop' => mb_substr((string) $stop, 0, 191)], 'id = :id', ['id' => $tripId]);
            $out['nav'] = self::nav(LiveTrips::find($tripId) ?? $trip, $now);
        } elseif ($kind === 'delay') {
            $out['schedule'] = self::feedDelay((int) ($row['schedule_id'] ?? 0), (int) $minutes, $note, (int) $driver['driverId']);
        } elseif ($kind === 'rest_start' || $kind === 'rest_end') {
            $out['rest'] = self::restState($tripId);
        } else {
            $out['alert'] = self::alertOffice($row, $ctx + ['driver' => $driver, 'approx' => $pos['approx'], 'fixAt' => $pos['fixAt']]);
        }
        return $out;
    }

    /**
     * Store one event once. With $dedupe (the tap carries the phone's own
     * time) a re-sent tap - same trip, kind, stop, minutes, note and phone
     * second - is the row already stored: the answer to the first send was
     * lost in a dead zone and the queue sent it again.
     *
     * @param array<string,mixed> $row
     * @return array{id: int, repeat: bool}
     */
    public static function record(array $row, bool $dedupe = true): array
    {
        if ($dedupe && $row['live_trip_id'] !== null) {
            $dup = Database::scalar(
                'SELECT id FROM ' . self::TABLE . ' WHERE live_trip_id = :t AND kind = :k AND created_at = :c
                    AND COALESCE(stop_name, \'\') = :s AND COALESCE(minutes, -1) = :m AND COALESCE(note, \'\') = :n
                  ORDER BY id LIMIT 1',
                ['t' => (int) $row['live_trip_id'], 'k' => (string) $row['kind'], 'c' => (string) $row['created_at'],
                 's' => (string) ($row['stop_name'] ?? ''), 'm' => $row['minutes'] !== null ? (int) $row['minutes'] : -1,
                 'n' => (string) ($row['note'] ?? '')],
                null
            );
            if ($dup !== null) {
                return ['id' => (int) $dup, 'repeat' => true];
            }
        }
        return ['id' => Database::insert(self::TABLE, $row), 'repeat' => false];
    }

    /** When the driver pressed it: the phone's clock (a queued tap keeps its time), never in the future, at most a day old. */
    public static function clientTime(mixed $t, int $now): int
    {
        if (!is_numeric($t)) {
            return $now;
        }
        $ts = (int) floor((float) $t / 1000);
        if ($ts > $now + 120 || $ts < $now - 86400) {
            return $now;
        }
        return min($ts, $now);
    }

    /**
     * The phone's position, else the coach's last stored fix (approx).
     *
     * @param array<string,mixed> $in
     * @param array<string,mixed> $trip
     * @return array{lat: ?float, lng: ?float, approx: bool, fixAt: ?string}
     */
    public static function position(array $in, array $trip): array
    {
        $lat = $in['lat'] ?? null;
        $lng = $in['lng'] ?? null;
        if (is_numeric($lat) && is_numeric($lng) && self::inArea((float) $lat, (float) $lng)) {
            return ['lat' => round((float) $lat, 7), 'lng' => round((float) $lng, 7), 'approx' => false, 'fixAt' => null];
        }
        if (($trip['last_lat'] ?? null) !== null && ($trip['last_lng'] ?? null) !== null) {
            return ['lat' => (float) $trip['last_lat'], 'lng' => (float) $trip['last_lng'], 'approx' => true,
                    'fixAt' => $trip['last_ping_at'] !== null ? (string) $trip['last_ping_at'] : null];
        }
        return ['lat' => null, 'lng' => null, 'approx' => false, 'fixAt' => null];
    }

    /** India + Nepal, generously (LiveTrips::cleanPoint's box). 0,0 is a broken fix. */
    private static function inArea(float $lat, float $lng): bool
    {
        return $lat >= 6.0 && $lat <= 37.5 && $lng >= 68.0 && $lng <= 98.0;
    }

    /** The route's own name for a stop the phone sent, or null (inactive stops count: the coach still passes them). */
    public static function matchStop(int $routeId, string $name): ?string
    {
        $want = mb_strtolower(trim($name));
        if ($want === '') {
            return null;
        }
        foreach (Boarding::lineStops($routeId, false) as $s) {
            if (mb_strtolower(trim((string) $s['name'])) === $want) {
                return mb_substr((string) $s['name'], 0, 120);
            }
        }
        return null;
    }

    /** "Dhilo" moves the schedule's delay exactly like admin/trips.php set_delay - minus the passenger message. */
    private static function feedDelay(int $scheduleId, int $minutes, string $note, int $driverId): bool
    {
        if ($scheduleId <= 0 || !Settings::getBool('driver_delay_to_schedule', true)) {
            return false;
        }
        $before = Database::fetch('SELECT delay_minutes, delay_note, status FROM schedules WHERE id = :id', ['id' => $scheduleId]);
        if ($before === null || (string) $before['status'] === 'cancelled') {
            return false;
        }
        $noteOut = $note !== '' ? mb_substr($note, 0, 255) : null;
        Database::update('schedules', ['delay_minutes' => $minutes, 'delay_note' => $noteOut], 'id = :id', ['id' => $scheduleId]);
        Logger::audit('trip.set_delay', 'schedule', (string) $scheduleId,
            ['delay_minutes' => (int) $before['delay_minutes'], 'delay_note' => $before['delay_note']],
            ['delay_minutes' => $minutes, 'delay_note' => $noteOut], 'driver app · driver #' . $driverId);
        return true;
    }

    /**
     * Bus, route and departure of a live trip, for the alert text.
     *
     * @return array{bus: string, route: string, date: string, dep: string}
     */
    public static function context(int $tripId): array
    {
        $r = Database::fetch(
            "SELECT r.from_city, r.to_city, r.route_code, s.travel_date,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time, b.bus_number, b.bus_name
               FROM live_trips lt
               JOIN routes r ON r.id = lt.route_id
               LEFT JOIN schedules s ON s.id = lt.schedule_id
               LEFT JOIN buses b ON b.id = COALESCE(lt.bus_id, s.bus_id)
              WHERE lt.id = :id",
            ['id' => $tripId]
        );
        if ($r === null) {
            return ['bus' => '', 'route' => '', 'date' => '', 'dep' => ''];
        }
        $bus = trim((string) ($r['bus_number'] ?? ''));
        if ($bus === '') {
            $bus = trim((string) ($r['bus_name'] ?? ''));
        }
        return [
            'bus'   => $bus,
            'route' => trim($r['from_city'] . ' → ' . $r['to_city']),
            'date'  => (string) ($r['travel_date'] ?? ''),
            'dep'   => substr((string) ($r['dep_time'] ?? ''), 0, 5),
        ];
    }

    /* ---------------------------------------------------------------
     *  SOS / breakdown: the owner and the office, at once
     * ------------------------------------------------------------- */

    /**
     * Bell + WhatsApp for one SOS / breakdown row. Never throws.
     *
     * @param array<string,mixed> $ev  the stored row (with id)
     * @param array<string,mixed> $ctx context() + driver, approx, fixAt
     * @return array{bell: int, whatsapp: int, to: int, skipped: string}
     */
    public static function alertOffice(array $ev, array $ctx): array
    {
        $out = ['bell' => 0, 'whatsapp' => 0, 'to' => 0, 'skipped' => ''];
        try {
            $at = (int) strtotime((string) $ev['created_at']);
            /* An alert the office has already closed no longer means "the
               office knows": a fresh emergency after that is told again. */
            if ($ev['live_trip_id'] !== null && Database::exists(
                'SELECT 1 FROM ' . self::TABLE . ' WHERE live_trip_id = :t AND kind = :k AND id < :i AND created_at >= :since
                    AND resolved_at IS NULL',
                ['t' => (int) $ev['live_trip_id'], 'k' => (string) $ev['kind'], 'i' => (int) $ev['id'],
                 'since' => date('Y-m-d H:i:s', $at - self::ALERT_REPEAT_SEC)]
            )) {
                $out['skipped'] = 'repeat';   // the same emergency pressed again: the office already knows
                return $out;
            }
            $msg = self::message($ev, $ctx);
            require_once __DIR__ . '/adminalerts.php';
            $out['bell'] = AdminAlerts::push('driver_' . $ev['kind'], $msg['title'], $msg['body'], '/admin/map.php',
                $ev['kind'] === 'sos' ? '🆘' : '🛠️');
            if (!Settings::getBool('driver_sos_whatsapp_on', true)) {
                $out['skipped'] = 'whatsapp off';
                return $out;
            }
            $nums = self::officeNumbers();
            $out['to'] = count($nums);
            if ($nums === []) {
                $out['skipped'] = 'no office number';
                return $out;
            }
            $purpose = 'trip_' . $ev['kind'];   // WaSend: 'trip_' office alerts may use the generic template outside the 24 h window
            foreach ($nums as $n) {
                try {
                    if (self::$send !== null) {
                        $ok = (bool) (self::$send)($n, $msg['text'], $purpose);
                    } else {
                        require_once __DIR__ . '/notify.php';
                        $ok = Notify::whatsapp($n, $msg['text'], null, null, [], null, ['purpose' => $purpose]) === true;
                    }
                    if ($ok) {
                        $out['whatsapp']++;
                    }
                } catch (Throwable $e) {
                    Logger::warning('driver alert WhatsApp not sent', ['event' => (int) $ev['id'], 'error' => $e->getMessage()]);
                }
            }
        } catch (Throwable $e) {
            try {
                Logger::warning('driver alert failed', ['event' => (int) ($ev['id'] ?? 0), 'error' => $e->getMessage()]);
            } catch (Throwable $ignored) {
            }
        }
        return $out;
    }

    /**
     * The owner's and the CEO's WhatsApp (admin_whatsapp falls back to the
     * office phone, as Notify::adminNote does), one per person.
     *
     * @return list<string> digits as typed
     */
    public static function officeNumbers(): array
    {
        $out  = [];
        $seen = [];
        foreach ([Settings::getString('admin_whatsapp', Settings::officePhone()), Settings::getString('ceo_whatsapp', '')] as $raw) {
            $d = preg_replace('/\D/', '', (string) $raw) ?? '';
            $k = normalisePhone($d);
            if (strlen($d) < 10 || $k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[]    = $d;
        }
        return $out;
    }

    /**
     * The alert in words: a short bell, a full WhatsApp.
     *
     * @param array<string,mixed> $ev
     * @param array<string,mixed> $ctx
     * @return array{title: string, body: string, text: string}
     */
    public static function message(array $ev, array $ctx): array
    {
        $sos   = (string) $ev['kind'] === 'sos';
        $what  = $sos ? '🆘 SOS' : '🛠️ Bus breakdown · बस बिग्रियो';
        $bus   = (string) ($ctx['bus'] ?? '') !== '' ? (string) $ctx['bus'] : 'Bus';
        $route = (string) ($ctx['route'] ?? '');
        $drv   = is_array($ctx['driver'] ?? null) ? $ctx['driver'] : [];
        $name  = trim((string) ($drv['name'] ?? ''));
        $phone = trim((string) ($drv['phone'] ?? ''));
        $role  = (string) ($drv['role'] ?? 'driver');
        $lat   = $ev['lat'] !== null ? (float) $ev['lat'] : null;
        $lng   = $ev['lng'] !== null ? (float) $ev['lng'] : null;
        $place = self::placeLink($lat, $lng);
        if ($place !== '' && !empty($ctx['approx'])) {
            $place .= ' (last GPS' . (!empty($ctx['fixAt']) ? ' ' . date('H:i', (int) strtotime((string) $ctx['fixAt'])) : '') . ')';
        }
        $when  = date('j M H:i', (int) strtotime((string) $ev['created_at']));
        $note  = trim((string) ($ev['note'] ?? ''));
        $dep   = trim(((string) ($ctx['dep'] ?? '')) . ((string) ($ctx['date'] ?? '') !== '' ? ', ' . formatDate((string) $ctx['date'], 'j M') : ''), ', ');

        $lines = [$what . ' — ' . $bus];
        if ($route !== '') {
            $lines[] = 'Route: ' . $route . ($dep !== '' ? ' (' . $dep . ')' : '');
        }
        if ($name !== '' || $phone !== '') {
            $lines[] = ucfirst($role) . ': ' . trim($name . ($phone !== '' ? ' · ' . $phone : ''));
        }
        $lines[] = 'Place: ' . ($place !== '' ? $place : 'GPS not available - call the driver');
        $lines[] = 'Time: ' . $when;
        if ($note !== '') {
            $lines[] = 'Note: ' . $note;
        }
        $lines[] = 'Office: ' . appUrl('admin/map.php');

        $body = trim(($name !== '' ? $name . ($phone !== '' ? ' (' . $phone . ')' : '') . ' · ' : '') . $when
            . ($note !== '' ? ' · ' . $note : '') . ($place !== '' ? ' · ' . $place : ' · no GPS'));
        return [
            'title' => $what . ' · ' . $bus . ($route !== '' ? ' · ' . $route : ''),
            'body'  => $body,
            'text'  => implode("\n", $lines),
        ];
    }

    /** A Google Maps pin for a position ('' without one). */
    public static function placeLink(?float $lat, ?float $lng): string
    {
        if ($lat === null || $lng === null) {
            return '';
        }
        return 'https://www.google.com/maps?q=' . sprintf('%.6f,%.6f', $lat, $lng);
    }

    /**
     * Open SOS / breakdown rows, newest first, with the driver's name and
     * phone - for the office (the panel's reader).
     *
     * @return list<array<string,mixed>>
     */
    public static function openAlerts(int $limit = 50): array
    {
        if (!self::available()) {
            return [];
        }
        try {
            return Database::fetchAll(
                "SELECT de.*, d.full_name AS driver_name, d.phone AS driver_phone
                   FROM " . self::TABLE . " de
                   LEFT JOIN drivers d ON de.driver_ref = CONCAT('D', d.id)
                  WHERE de.kind IN ('sos','breakdown') AND de.resolved_at IS NULL
                  ORDER BY de.id DESC LIMIT " . max(1, min(200, $limit))
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** The office closes an SOS / breakdown. True when it was open. */
    public static function resolve(int $eventId, int $adminId): bool
    {
        if (!self::available()) {
            return false;
        }
        $n = Database::run(
            "UPDATE " . self::TABLE . " SET resolved_at = NOW(), resolved_by = :a
              WHERE id = :i AND resolved_at IS NULL AND kind IN ('sos','breakdown')",
            ['a' => $adminId, 'i' => $eventId]
        );
        if ($n > 0) {
            Logger::audit('trip.driver_alert_resolved', 'driver_event', (string) $eventId, null, ['by' => $adminId], 'office');
        }
        return $n > 0;
    }

    /* ---------------------------------------------------------------
     *  The trip screen
     * ------------------------------------------------------------- */

    /**
     * Everything the running-trip screen shows, in one read.
     *
     * @param array<string,mixed> $trip live_trips row
     * @return array<string,mixed>
     */
    public static function tripInfo(array $trip, ?int $now = null): array
    {
        $now = $now ?? time();
        $out = [
            'now'       => $now,
            'startedTs' => $trip['started_at'] !== null ? (int) strtotime((string) $trip['started_at']) : null,
            'nav'       => null,
            'manifest'  => null,
            'rest'      => ['resting' => false, 'sinceTs' => null, 'lastEndTs' => null],
            'recent'    => [],
        ];
        try {
            $out['nav'] = self::nav($trip, $now);
        } catch (Throwable $e) {
            Logger::warning('driver nav failed', ['trip' => (int) $trip['id'], 'error' => $e->getMessage()]);
        }
        try {
            if ($trip['schedule_id'] !== null) {
                $out['manifest'] = self::manifest((int) $trip['schedule_id'], (int) $trip['route_id']);
            }
        } catch (Throwable $e) {
            Logger::warning('driver manifest failed', ['trip' => (int) $trip['id'], 'error' => $e->getMessage()]);
        }
        if (self::available()) {
            $out['rest']   = self::restState((int) $trip['id']);
            $out['recent'] = array_map([self::class, 'shape'], Database::fetchAll(
                'SELECT * FROM ' . self::TABLE . ' WHERE live_trip_id = :t ORDER BY created_at DESC, id DESC LIMIT 6',
                ['t' => (int) $trip['id']]
            ));
        }
        return $out;
    }

    /**
     * Resting now? Since when? When did the last rest end?
     *
     * @return array{resting: bool, sinceTs: ?int, lastEndTs: ?int}
     */
    public static function restState(int $tripId): array
    {
        $last = Database::fetch(
            "SELECT kind, created_at FROM " . self::TABLE . "
              WHERE live_trip_id = :t AND kind IN ('rest_start','rest_end')
              ORDER BY created_at DESC, id DESC LIMIT 1",
            ['t' => $tripId]
        );
        $end = Database::scalar(
            "SELECT MAX(created_at) FROM " . self::TABLE . " WHERE live_trip_id = :t AND kind = 'rest_end'",
            ['t' => $tripId],
            null
        );
        $resting = $last !== null && (string) $last['kind'] === 'rest_start';
        return [
            'resting'   => $resting,
            'sinceTs'   => $resting ? (int) strtotime((string) $last['created_at']) : null,
            'lastEndTs' => $end !== null ? (int) strtotime((string) $end) : null,
        ];
    }

    /**
     * The route's stops in travel order, which ones the coach has passed
     * (the last "Stop pugyo", or the coach's fresh fix on the route line),
     * the next one, and the Google Maps link through the stops ahead.
     *
     * @param array<string,mixed> $trip live_trips row
     * @return array{stops: list<array<string,mixed>>, passedIdx: int, next: ?string, mapsUrl: string}
     */
    public static function nav(array $trip, ?int $now = null): array
    {
        $now     = $now ?? time();
        $routeId = (int) $trip['route_id'];
        $stops   = self::routeStops($routeId, self::depTime($routeId, $trip['schedule_id'] !== null ? (int) $trip['schedule_id'] : 0));
        $passed  = -1;

        if (self::available()) {
            $last = Database::scalar(
                "SELECT stop_name FROM " . self::TABLE . " WHERE live_trip_id = :t AND kind = 'stop_reached'
                  ORDER BY created_at DESC, id DESC LIMIT 1",
                ['t' => (int) $trip['id']],
                null
            );
            if (is_string($last) && $last !== '') {
                foreach ($stops as $i => $s) {
                    if (mb_strtolower((string) $s['name']) === mb_strtolower($last)) {
                        $passed = $i;
                        break;
                    }
                }
            }
        }

        $fix = null;
        if (($trip['last_lat'] ?? null) !== null && ($trip['last_lng'] ?? null) !== null && ($trip['last_ping_at'] ?? null) !== null
            && $now - (int) strtotime((string) $trip['last_ping_at']) <= self::FRESH_FIX_SEC) {
            $fix = ['lat' => (float) $trip['last_lat'], 'lng' => (float) $trip['last_lng']];
        }
        if ($fix !== null) {
            $passed = max($passed, self::passedByPosition($stops, $fix['lat'], $fix['lng']));
        }

        $outStops = [];
        foreach ($stops as $i => $s) {
            $outStops[] = [
                'name'   => (string) $s['name'],
                'type'   => (string) $s['type'],
                'time'   => $s['time'] !== null ? substr((string) $s['time'], 0, 5) : null,
                'lat'    => $s['lat'],
                'lng'    => $s['lng'],
                'passed' => $i <= $passed,
            ];
        }
        $ahead = array_slice($stops, $passed + 1);
        return [
            'stops'     => $outStops,
            'passedIdx' => $passed,
            'next'      => $ahead !== [] ? (string) $ahead[0]['name'] : null,
            'mapsUrl'   => self::mapsUrl($fix, $ahead),
        ];
    }

    /**
     * A route's stops in TRAVEL order. The office's sort_order is not that
     * (Surat 13:00 … Mehsana 23:00, then Kamrej 13:30), so the order is
     * Boarding::journeyLine()'s - road km where known, else the timetable
     * clock - the one the timetable and the track page use. Rows are
     * Boarding::lineStops() rows (the route's own stop names); two labels of
     * one town sit side by side; a row journeyLine left out comes last.
     *
     * @return list<array<string,mixed>>
     */
    public static function routeStops(int $routeId, string $depTime, bool $activeOnly = true): array
    {
        $rows = Boarding::lineStops($routeId, $activeOnly);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r['id']] = $r;
        }
        $out = [];
        try {
            foreach (Boarding::journeyLine($rows, [], $depTime) as $it) {
                if ($it['kind'] !== 'stop') {
                    continue;
                }
                foreach ($it['ids'] as $id) {
                    if (isset($byId[(int) $id])) {
                        $out[] = $byId[(int) $id];
                        unset($byId[(int) $id]);
                    }
                }
            }
        } catch (Throwable $e) {
            // the office's order, below
        }
        foreach ($byId as $r) {
            $out[] = $r;
        }
        return $out;
    }

    /** The departure clock of a schedule (its override, else the route's). */
    private static function depTime(int $routeId, int $scheduleId): string
    {
        return (string) Database::scalar(
            'SELECT COALESCE(s.dep_time_override, r.dep_time) FROM routes r LEFT JOIN schedules s ON s.id = :s WHERE r.id = :r',
            ['s' => $scheduleId, 'r' => $routeId],
            ''
        );
    }

    /**
     * The last stop (index into $stops) at least PASSED_KM behind a position
     * on this route, -1 when none / off the route.
     *
     * @param list<array<string,mixed>> $stops routeStops()
     */
    private static function passedByPosition(array $stops, float $lat, float $lng): int
    {
        try {
            /* The line of the stops that have coordinates, in TRAVEL order
               (EtaAlerts::stopLine walks the office's sort order, which is not
               the road order on every route). Coarse - straight steps - but
               stops are tens of km apart: enough to say which are behind. */
            $line = [];
            $km   = 0.0;
            foreach ($stops as $i => $s) {
                if ($s['lat'] === null || $s['lng'] === null) {
                    continue;
                }
                if ($line !== []) {
                    $p   = $line[count($line) - 1];
                    $km += EtaAlerts::haversineKm($p['lat'], $p['lng'], (float) $s['lat'], (float) $s['lng']);
                }
                $line[] = ['lat' => (float) $s['lat'], 'lng' => (float) $s['lng'], 'km' => $km, 'i' => $i];
            }
            $at = EtaAlerts::project($line, $lat, $lng);
            if ($at === null || $at['off'] > EtaAlerts::OFF_ROUTE_KM) {
                return -1;
            }
            $idx = -1;
            foreach ($line as $p) {
                if ($p['km'] < $at['km'] - self::PASSED_KM) {
                    $idx = max($idx, (int) $p['i']);
                }
            }
            return $idx;
        } catch (Throwable $e) {
            return -1;
        }
    }

    /**
     * Google Maps directions through the stops still ahead (those with
     * coordinates, in order): the last one is the destination, up to
     * MAX_WAYPOINTS in between (spread evenly over the road when there are
     * more, so Google keeps to the coach's road). Without an origin Google
     * starts from the phone's own position. '' when no stop ahead has
     * coordinates.
     *
     * @param array{lat: float, lng: float}|null $origin
     * @param list<array<string,mixed>> $stops each with lat / lng (null = unknown)
     */
    public static function mapsUrl(?array $origin, array $stops): string
    {
        $pts = [];
        foreach ($stops as $s) {
            $lat = $s['lat'] ?? null;
            $lng = $s['lng'] ?? null;
            if (!is_numeric($lat) || !is_numeric($lng) || !self::inArea((float) $lat, (float) $lng)) {
                continue;
            }
            $p = sprintf('%.6f,%.6f', (float) $lat, (float) $lng);
            if ($pts === [] || end($pts) !== $p) {
                $pts[] = $p;
            }
        }
        if ($pts === []) {
            return '';
        }
        $dest = array_pop($pts);
        $n    = count($pts);
        if ($n > self::MAX_WAYPOINTS) {
            $pick = [];
            for ($i = 0; $i < self::MAX_WAYPOINTS; $i++) {
                $pick[] = $pts[(int) round($i * ($n - 1) / (self::MAX_WAYPOINTS - 1))];
            }
            $pts = array_values(array_unique($pick));
        }
        $q = 'api=1';
        if ($origin !== null && is_numeric($origin['lat'] ?? null) && is_numeric($origin['lng'] ?? null)) {
            $q .= '&origin=' . rawurlencode(sprintf('%.6f,%.6f', (float) $origin['lat'], (float) $origin['lng']));
        }
        $q .= '&destination=' . rawurlencode($dest);
        if ($pts !== []) {
            $q .= '&waypoints=' . rawurlencode(implode('|', $pts));
        }
        return 'https://www.google.com/maps/dir/?' . $q . '&travelmode=driving';
    }

    /**
     * Who gets on where: the departure's passengers grouped by pickup, in
     * the route's order. Seat, first name, the booking's phone (to call),
     * boarded (booking_passengers.boarded_at, set by the ticket scanner).
     * Cancelled / rejected bookings are not travelling; pending ones are
     * listed and flagged, as on the office manifest and the chalani. No ID
     * numbers, no fares.
     *
     * @return array{groups: list<array{stop: string, pax: list<array<string,mixed>>, booked: int, boarded: int}>, totals: array{booked: int, boarded: int, left: int}}
     */
    public static function manifest(int $scheduleId, int $routeId): array
    {
        $rows = Database::fetchAll(
            "SELECT bp.seat_no, bp.full_name, bp.boarded_at, b.contact_phone, b.contact_country_code,
                    b.status AS booking_status, bl.boarding_stop
               FROM booking_passengers bp
               JOIN bookings b      ON b.id = bp.booking_id
               JOIN booking_legs bl ON bl.id = bp.leg_id
              WHERE bl.schedule_id = :sid AND b.status IN ('confirmed','pending','completed')
              ORDER BY LENGTH(bp.seat_no), bp.seat_no",
            ['sid' => $scheduleId]
        );
        $pickups = array_values(array_filter(self::routeStops($routeId, self::depTime($routeId, $scheduleId), false),
            static fn(array $s): bool => $s['type'] === 'boarding'));
        $groups  = [];
        $boarded = 0;
        foreach ($rows as $r) {
            [$key, $label, $order] = self::pickupOf((string) ($r['boarding_stop'] ?? ''), $pickups);
            $groups[$key] ??= ['stop' => $label, 'order' => $order, 'pax' => [], 'booked' => 0, 'boarded' => 0];
            $on = $r['boarded_at'] !== null;
            $groups[$key]['pax'][] = [
                'seat'    => (string) $r['seat_no'],
                'name'    => self::firstName((string) $r['full_name']),
                'phone'   => self::callable((string) ($r['contact_phone'] ?? ''), (string) ($r['contact_country_code'] ?? '')),
                'boarded' => $on,
                'pending' => (string) $r['booking_status'] === 'pending',
            ];
            $groups[$key]['booked']++;
            if ($on) {
                $groups[$key]['boarded']++;
                $boarded++;
            }
        }
        $groups = array_values($groups);
        usort($groups, static fn(array $a, array $b): int => [$a['order'], $a['stop']] <=> [$b['order'], $b['stop']]);
        foreach ($groups as $i => $g) {
            unset($groups[$i]['order']);
        }
        $booked = count($rows);
        return ['groups' => $groups, 'totals' => ['booked' => $booked, 'boarded' => $boarded, 'left' => $booked - $boarded]];
    }

    /**
     * Which route pickup a sold label is: the longest route stop name it
     * starts with, else the same town; else its own cleaned words, last.
     *
     * @param list<array<string,mixed>> $pickups
     * @return array{0: string, 1: string, 2: int} [group key, label, route order]
     */
    private static function pickupOf(string $label, array $pickups): array
    {
        $low  = mb_strtolower(trim($label));
        $best = -1;
        $len  = 0;
        foreach ($pickups as $i => $s) {
            $n = mb_strtolower(trim((string) $s['name']));
            if ($n !== '' && str_starts_with($low, $n) && mb_strlen($n) > $len) {
                $best = $i;
                $len  = mb_strlen($n);
            }
        }
        if ($best < 0) {
            $tk = Boarding::townKey($label);
            if ($tk !== '') {
                foreach ($pickups as $i => $s) {
                    if (Boarding::townKey((string) $s['name']) === $tk) {
                        $best = $i;
                        break;
                    }
                }
            }
        }
        if ($best >= 0) {
            return ['s' . $best, (string) $pickups[$best]['name'], $best];
        }
        $clean = self::stopShort($label);
        return ['x' . mb_strtolower($clean), $clean !== '' ? $clean : '—', 9999];
    }

    /** A sold label as a person reads it: no [lat,lng], no "@ 13:00", no "(...)" (admin/manifest.php's rule). */
    public static function stopShort(string $label): string
    {
        $s = (string) preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $label);
        $s = (string) preg_replace('/\s*@.*$/u', '', $s);
        $s = (string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $s);
        return trim((string) preg_replace('/\s{2,}/u', ' ', $s), " \t·");
    }

    /** "Mr. Ram Bahadur Thapa" -> "Ram". */
    public static function firstName(string $full): string
    {
        foreach (preg_split('/\s+/u', trim($full)) ?: [] as $w) {
            $bare = mb_strtolower(rtrim($w, '.'));
            if ($bare === '' || in_array($bare, ['mr', 'mrs', 'ms', 'miss', 'dr', 'shri', 'shree', 'smt', 'sri'], true)) {
                continue;
            }
            return mb_substr($w, 0, 30);
        }
        return '';
    }

    /**
     * The booking's phone for a tel: link - '' for a walk-in stand-in
     * (0000000000). India and Nepal share 10-digit mobiles, and the coach
     * crosses the border: a bare Nepali number dialled from an Indian SIM
     * rings a stranger in India. So a bare number takes the country stamped
     * on the booking at sale time (contact_country_code, the first rule of
     * Notify::countryHint) and is left bare - the SIM's own country - only
     * when none was stamped. A trunk 0 ("09812…") is not a country code.
     */
    public static function callable(string $raw, string $countryCode = ''): string
    {
        $d = preg_replace('/\D/', '', $raw) ?? '';
        if ($d === '' || isPlaceholderPhone($d)) {
            return '';
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (strlen($d) === 11 && $d[0] === '0') {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10) {
            $cc = preg_replace('/\D/', '', $countryCode) ?? '';
            return in_array($cc, ['91', '977'], true) ? '+' . $cc . $d : $d;
        }
        return '+' . $d;
    }

    /**
     * One event as the phone sees it.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    public static function shape(array $r): array
    {
        return [
            'id'      => (int) ($r['id'] ?? 0),
            'kind'    => (string) $r['kind'],
            'stop'    => $r['stop_name'] !== null ? (string) $r['stop_name'] : null,
            'minutes' => $r['minutes'] !== null ? (int) $r['minutes'] : null,
            'note'    => $r['note'] !== null ? (string) $r['note'] : null,
            'ts'      => (int) strtotime((string) $r['created_at']),
            'open'    => in_array((string) $r['kind'], self::ALERT_KINDS, true) && ($r['resolved_at'] ?? null) === null,
        ];
    }

    /* ---------------------------------------------------------------
     *  Battery
     * ------------------------------------------------------------- */

    /**
     * The phone's battery from a GPS ping, kept per trip in kv_store
     * (driver_battery / trip:<id>) - at most every BATTERY_EVERY_S unless it
     * moved 5 points, crossed the low mark or the charger changed. Never
     * throws. True when stored.
     */
    public static function noteBattery(int $tripId, mixed $b, ?int $now = null): bool
    {
        $now = $now ?? time();
        if (!is_array($b) || !is_numeric($b['pct'] ?? null)) {
            return false;
        }
        $pct = (int) round((float) $b['pct']);
        if ($pct < 0 || $pct > 100) {
            return false;
        }
        $charging = !empty($b['charging']);
        try {
            $old = self::battery($tripId);
            if ($old !== null) {
                $age = $now - (int) $old['ts'];
                $crossed = $pct < self::BATTERY_LOW_PCT && (int) $old['pct'] >= self::BATTERY_LOW_PCT;
                if ($age >= 0 && $age < self::BATTERY_EVERY_S && abs($pct - (int) $old['pct']) < 5
                    && (bool) $old['charging'] === $charging && !$crossed) {
                    return false;
                }
            }
            $json = json_encode(['pct' => $pct, 'charging' => $charging, 'ts' => $now]);
            Database::query(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('driver_battery', :k, :v, 'driver app')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = VALUES(updated_by)",
                ['k' => 'trip:' . $tripId, 'v' => $json === false ? '{}' : $json]
            );
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The last battery level a trip's phone sent, or null.
     *
     * @return array{pct: int, charging: bool, ts: int}|null
     */
    public static function battery(int $tripId): ?array
    {
        try {
            $raw = Database::scalar(
                "SELECT kvalue FROM kv_store WHERE kscope = 'driver_battery' AND kkey = :k LIMIT 1",
                ['k' => 'trip:' . $tripId],
                null
            );
            $v = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($v) || !isset($v['pct'], $v['ts'])) {
                return null;
            }
            return ['pct' => (int) $v['pct'], 'charging' => !empty($v['charging']), 'ts' => (int) $v['ts']];
        } catch (Throwable $e) {
            return null;
        }
    }
}
