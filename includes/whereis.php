<?php
/**
 * =====================================================================
 *  WhereIs — "Where is my bus?" for one booking (24 Sep 2026).
 *
 *  One honest answer built from what the register already knows:
 *    · the trip's state (TripStatus: upcoming / boarding / on route / arrived)
 *    · the published coach position (EtaAlerts::fix — stale or inaccurate
 *      fixes are refused, never guessed)
 *    · minutes and km to THIS passenger's boarding stop (EtaAlerts::stopEtas)
 *    · once the coach is past that stop and the passenger is plausibly on
 *      board (21 Oct 2026): onBoard, and minutes / km to THEIR drop
 *
 *  Shared by track.php (the page) and api/whereis.php (its refresh), so the
 *  page and the WhatsApp "bus is near" alert can never disagree.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDE_PATH . '/etaalerts.php';
require_once INCLUDE_PATH . '/tripstatus.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/sitefacts.php';

final class WhereIs
{
    /**
     * Three doors, the same as verify-ticket.php: the keyed link, the
     * signed-in customer who owns the booking, or staff who may see it.
     *
     * 8 Oct 2026: the key may be the tracker's own token (Ticket::trackToken
     * — what Ticket::trackUrl now carries and the family share link passes
     * on) or the download key every link sent before today carries. The
     * customer door is Auth::customerMayOpen() — an OTP-verified session for
     * this number or a PNR booked in this browser session — once that exists;
     * until then the old phone match, with the walk-in placeholder refused.
     */
    public static function authorised(array $booking, string $token): bool
    {
        $pnr = (string) ($booking['pnr'] ?? '');
        if ($token !== '' && (Ticket::checkTrackToken($pnr, $token) || Ticket::checkDownloadToken($pnr, $token))) {
            return true;
        }
        if (method_exists('Auth', 'customerMayOpen')) {
            if (Auth::customerMayOpen($booking)) {
                return true;
            }
        } elseif (($user = Auth::user()) !== null) {
            $mine = normalisePhone((string) ($user['phone'] ?? ''));
            if ($mine !== '' && !isPlaceholderPhone((string) ($booking['contact_phone'] ?? ''))
                && $mine === normalisePhone((string) ($booking['contact_phone'] ?? ''))) {
                return true;
            }
        }
        if (Auth::isAdmin() && Auth::can('bookings.view')) {
            $scope = Auth::bookingScopeAdminId();
            return $scope === null || $scope === (int) ($booking['sold_by_admin_id'] ?? 0);
        }
        return false;
    }

    /**
     * The answer for one booking: the honest live/last-known state (core)
     * plus, since 9 Oct 2026, the whole run as a 'timeline' (stops + halts,
     * scheduled time, km, passed, ETA when live), progress and time on road.
     *
     * @param array<string,mixed> $booking  a bookings row
     * @return array<string,mixed>  JSON-able
     */
    public static function status(array $booking, ?int $now = null): array
    {
        $now = $now ?? time();
        $leg = null;
        $out = self::core($booking, $now, $leg);
        if ($leg !== null) {
            try {
                $out = array_merge($out, self::timeline($leg, $out, $now));
            } catch (Throwable $e) {
                // the timeline is an extra: never let it break the live answer
                $out['timeline'] = [];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $booking  a bookings row
     * @param array<string,mixed>|null $legOut  receives the leg row (null when none)
     * @return array<string,mixed>  JSON-able
     */
    private static function core(array $booking, int $now, ?array &$legOut): array
    {
        $leg = Database::fetch(
            "SELECT bl.schedule_id, bl.travel_date, bl.boarding_stop, bl.drop_stop, bl.boarding_time,
                    s.route_id, s.status AS trip_status, s.delay_minutes, s.delay_note, s.bus_id, s.slot,
                    COALESCE(s.dep_time_override, r.dep_time) AS eff_dep_time,
                    r.from_city, r.to_city, r.dep_time, r.arr_time, r.day_offset, r.route_code,
                    r.duration_text, r.distance_km,
                    b.bus_number, b.bus_name
               FROM booking_legs bl
               JOIN schedules s ON s.id = bl.schedule_id
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses b ON b.id = s.bus_id
              WHERE bl.booking_id = :b
              ORDER BY (bl.leg_type = 'outbound') DESC, bl.id ASC
              LIMIT 1",
            ['b' => (int) $booking['id']]
        );
        $out = [
            'pnr'        => (string) ($booking['pnr'] ?? ''),
            'bookingStatus' => (string) ($booking['status'] ?? ''),
            'live'       => false,
            'state'      => 'unknown',
            'label'      => '',
            'etaMin'     => null,
            'km'         => null,
            'stop'       => '',
            'stopTime'   => '',
            'lat'        => null,
            'lng'        => null,
            'fixAge'     => null,
            'reason'     => '',
            'lastKnown'  => false,
            'updatedAt'  => date('c', $now),
        ];
        $legOut = $leg;
        if ($leg === null) {
            $out['reason'] = 'no leg';
            return $out;
        }

        $stop = Boarding::stopDisplay((string) ($leg['boarding_stop'] ?? ''));
        $out['route']     = trim((string) $leg['from_city']) . ' → ' . trim((string) $leg['to_city']);
        $out['date']      = (string) $leg['travel_date'];
        $out['stop']      = (string) ($stop['name'] ?? '');
        $out['stopTime']  = (string) ($stop['time'] ?? ($leg['boarding_time'] ?? ''));
        $out['bus']       = trim((string) ($leg['bus_number'] ?? ''));
        $out['delayMin']  = max(0, (int) ($leg['delay_minutes'] ?? 0));
        $out['delayNote'] = (string) ($leg['delay_note'] ?? '');
        $out['tripStatus'] = (string) ($leg['trip_status'] ?? '');   // schedules.status — 'departed' once the office pressed "Bus started"
        /* THIS passenger's pickup has come (review, 8 Oct 2026): the trip
           state is per schedule and timed from the origin, so a 23:00 Mehsana
           passenger read "The bus has left" from 13:05. Only after their own
           pickup moment may a page say the bus has gone. */
        $pickTs = Boarding::pickupTs((string) $leg['travel_date'], (string) ($leg['boarding_stop'] ?? ''),
            $leg['boarding_time'] !== null ? (string) $leg['boarding_time'] : null, (string) ($leg['eff_dep_time'] ?? $leg['dep_time']),
            (string) $leg['dep_time'], (int) ($leg['slot'] ?? 1), (int) $leg['route_id']);
        $out['pickupPassed'] = $pickTs > 0 && $now >= $pickTs;
        $out['pickupTs']     = $pickTs > 0 ? $pickTs : null;     // 9 Oct 2026: the page counts down to it

        // Trip state from the same ladder the office board uses.
        $trip = TripStatus::editableFor((int) $leg['schedule_id'], $now);
        $out['state'] = (string) ($trip['state'] ?? 'unknown');
        $out['label'] = (string) ($trip['label'] ?? '');

        // The coach's position: only when it is fresh, accurate and on this
        // route. 20 Oct 2026: THIS coach's own driver-app fix first
        // (EtaAlerts::fixForTrip); the shared `livebus` key only when this is
        // the one coach of its route on the road.
        [$fix, $why, $fixAge] = EtaAlerts::fixForTrip((int) $leg['schedule_id'], (int) $leg['route_id'], $now);
        if ($fix === null) {
            $out['reason'] = $why;
            // A GPS gap on a trip that has left: the last place it was seen,
            // honestly labelled "not live" (8 Oct 2026, D7-06).
            $lk = self::lastKnown($leg, (string) $out['state'], $now);
            if ($lk !== null) {
                $out = array_merge($out, $lk);
            }
            return $out;
        }
        /* 21 Oct 2026: off the route is decided by distance from the line,
           not by "a boarding stop is still ahead" - that silenced every r7
           passenger (all board at Rupaidiha) and every r2 passenger once
           picked up. */
        $routeId = (int) $leg['route_id'];
        $geo = EtaAlerts::routeLine($routeId);
        $pos = EtaAlerts::locate($geo, (float) $fix['lat'], (float) $fix['lng']);
        if ($pos === null || $pos['off'] > EtaAlerts::OFF_ROUTE_KM) {
            $out['reason'] = 'the coach is not on this route right now';
            return $out;
        }
        $out['live']    = true;
        $out['lat']     = $fix['lat'];
        $out['lng']     = $fix['lng'];
        $out['kmh']     = $fix['kmh'] !== null ? (int) round($fix['kmh']) : null;
        $out['bearing'] = $fix['bearing'] ?? ($fix['brg'] ?? null);
        $out['acc']     = $fix['acc'] ?? null;
        $out['fixAge']  = $fixAge;
        /* 20 Oct 2026 (phase 2): the coach's place ALONG the stored road line,
           for the page's map (grey = driven, blue = to go). on=false: the fix
           is near the line of stops but not near the road - the map shows it
           where it is and does not glide it along the road. */
        $road = self::roadAt((int) $leg['route_id'], (float) $fix['lat'], (float) $fix['lng']);
        if ($road !== null) {
            $out['road'] = $road;
        }
        $kmh   = $fix['kmh'] ?? null;
        $order = self::stopOrder($routeId);
        $pick  = self::placeOf($geo, $order, (string) ($leg['boarding_stop'] ?? ''), 'boarding');

        // The pickup still ahead: minutes and km to it (the stopEtas() rule).
        if ($pick['km'] !== null && $pick['km'] - $pos['km'] > 0.5) {
            $ahead = $pick['km'] - $pos['km'];
            if ($ahead <= self::ETA_MAX_KM) {
                $out['etaMin'] = (int) (ceil(EtaAlerts::etaMinutes($ahead, $kmh) / 5) * 5);
                $out['km']     = round($ahead, 1);
            }
            return $out;
        }

        /* Behind the coach? Only when the km say so: the pickup itself, or -
           for a pickup without coordinates - the next stop after it that has
           them. No stop on the line to measure by: the pickup clock. At the
           stop, or between the bounds: live, and nothing more is claimed. */
        if ($pick['after'] !== null) {
            $behind = $pos['km'] - $pick['after'] > 0.5;
        } elseif ($pick['km'] === null) {
            $behind = $pickTs > 0 && $now >= $pickTs + $out['delayMin'] * 60;
        } else {
            $behind = false;
        }
        if (!$behind) {
            return $out;
        }

        /* Plausibly on board: the pickup moment has come (a coach a little
           early still counts) or the crew scanned this ticket aboard. A coach
           well past the stop before its time keeps the old warning - that is
           the passenger who may have missed it. */
        $onBoard = ($pickTs > 0 && $now >= $pickTs - self::ON_BOARD_EARLY_SEC) || self::scannedAboard((int) $booking['id']);
        if (!$onBoard) {
            $out['passed'] = true;      // the bus is already past this stop
            return $out;
        }

        // Their drop on the ticket, else the end of the line. Without coordinates: no ETA.
        $dropLabel = trim((string) ($leg['drop_stop'] ?? ''));
        $drop = $dropLabel !== '' ? self::placeOf($geo, $order, $dropLabel, 'drop') : self::endOfLine($geo, $order);
        $out['drop'] = $drop['name'] !== '' ? $drop['name'] : (string) Boarding::stopDisplay($dropLabel, (string) $leg['to_city'])['name'];
        $out['dropEtaMin'] = null;
        $out['dropKm']     = null;
        if ($drop['km'] !== null) {
            $toGo = $drop['km'] - $pos['km'];
            if ($toGo <= 0.5) {
                return $out;            // at or past their drop: live, no "on the way"
            }
            $out['dropKm'] = round($toGo, 1);
            if ($toGo <= self::ETA_MAX_KM) {
                $out['dropEtaMin'] = (int) (ceil(EtaAlerts::etaMinutes($toGo, $kmh) / 5) * 5);
            }
        }
        $out['onBoard'] = true;
        return $out;
    }

    /** Beyond this many road km a speed-based ETA is not given (the stopEtas() limit). */
    public const ETA_MAX_KM = 400.0;

    /** A coach this early at a pickup still plausibly picked the passenger up. */
    public const ON_BOARD_EARLY_SEC = 30 * 60;

    /**
     * Every stop of the route in travel order (the stopLine() order),
     * with or without coordinates.
     *
     * @return list<array{name:string, type:string}>
     */
    private static function stopOrder(int $routeId): array
    {
        return array_map(static fn(array $r): array => ['name' => (string) $r['stop_name'], 'type' => (string) $r['stop_type']],
            Database::fetchAll(
                "SELECT stop_name, stop_type FROM route_stops WHERE route_id = :r
                  ORDER BY FIELD(stop_type,'boarding','drop'), sort_order, id",
                ['r' => $routeId]
            ));
    }

    /**
     * Where a ticket's stop sits on the route line: its own km when it has
     * coordinates, and 'after' = the km of the first stop from it onwards
     * that has them (the coach past that is surely past this one). Nothing
     * is interpolated - a stop without coordinates never gets a km.
     *
     * @param array{line: list<array<string,mixed>>} $geo  EtaAlerts::routeLine()
     * @param list<array{name:string, type:string}> $order  stopOrder()
     * @return array{km:?float, after:?float, name:string}
     */
    private static function placeOf(array $geo, array $order, string $label, string $type): array
    {
        $key = Boarding::townKey($label);
        $kmOf = static function (array $s) use ($geo): ?float {
            foreach ($geo['line'] as $g) {
                if ($g['name'] === $s['name'] && $g['type'] === $s['type']) { return (float) $g['km']; }
            }
            return null;
        };
        foreach ($order as $i => $s) {
            if ($key === '' || $s['type'] !== $type || Boarding::townKey($s['name']) !== $key) {
                continue;
            }
            $after = null;
            for ($j = $i, $n = count($order); $j < $n && $after === null; $j++) {
                $after = $kmOf($order[$j]);
            }
            return ['km' => $kmOf($s), 'after' => $after, 'name' => (string) Boarding::stopDisplay($s['name'])['name']];
        }
        return ['km' => null, 'after' => null, 'name' => ''];
    }

    /** The last stop of the route (a ticket with no drop gets off there). */
    private static function endOfLine(array $geo, array $order): array
    {
        $last = end($order);
        return $last === false ? ['km' => null, 'after' => null, 'name' => '']
            : self::placeOf($geo, [$last], $last['name'], $last['type']);
    }

    /** The crew's boarding scan stamped a passenger of this ticket. */
    private static function scannedAboard(int $bookingId): bool
    {
        try {
            return Database::exists('SELECT 1 FROM booking_passengers WHERE booking_id = :b AND boarded_at IS NOT NULL', ['b' => $bookingId]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The whole run of this booking's trip (9 Oct 2026, owner ask #12): every
     * stop and halt in travel order (Boarding::journeyLine) with its scheduled
     * moment on THIS trip (the timetable offset laid on the effective
     * departure, so a retimed bus moves its whole timeline), road km, passed /
     * next, the passenger's own pickup and drop, and an ETA ONLY when live.
     *
     * Honest by construction ('progress.source'):
     *   gps        the coach's fix (already gated by core()) projected on the line
     *   timetable  the trip has left but no live fix: passed / km by the clock,
     *              which the page labels "as per timetable", never as a position
     *   done       arrived / completed
     *   none       not on the road yet - nothing is passed
     *
     * @param array<string,mixed> $leg  the core() leg row
     * @param array<string,mixed> $out  the core() answer (live, lat, lng, kmh, state)
     * @return array<string,mixed>
     */
    private static function timeline(array $leg, array $out, int $now): array
    {
        $routeId = (int) $leg['route_id'];
        $line = Boarding::journeyLine(Boarding::lineStops($routeId), Boarding::haltsFor($routeId), (string) $leg['dep_time']);
        if ($line === []) {
            return ['timeline' => []];
        }
        $sum   = Boarding::journeySummary($line, $leg);
        $total = $sum['distanceKm'] !== null ? (float) $sum['distanceKm'] : null;
        $date  = (string) $leg['travel_date'];
        $depTs = (int) strtotime($date . ' ' . (string) (($leg['eff_dep_time'] ?? '') !== '' ? $leg['eff_dep_time'] : $leg['dep_time']));
        $delay = max(0, (int) ($leg['delay_minutes'] ?? 0)) * 60;
        $state = (string) ($out['state'] ?? '');
        $done  = in_array($state, ['arrived', 'completed'], true);
        $live  = !empty($out['live']) && $out['lat'] !== null && $out['lng'] !== null;
        $left  = !$done && in_array($state, ['departed', 'on_route', 'delayed'], true) && $now >= $depTs + $delay;
        $n     = count($line);

        // The end of the line: its timetable minute when only the journey time
        // is known (an estimate - its clock is shown only once confirmed).
        $lastStop = null;
        foreach ($line as $i => $it) {
            if ($it['kind'] === 'stop') { $lastStop = $i; }
        }
        $destApprox = false;
        if ($lastStop !== null && $line[$lastStop]['offMin'] === null && $sum['durationMin'] !== null) {
            $line[$lastStop]['offMin'] = (int) $sum['durationMin'];
            $destApprox = !$sum['durationConfirmed'];
        }

        // The passenger's own pickup and drop.
        $bd = Boarding::stopDisplay((string) ($leg['boarding_stop'] ?? ''), (string) $leg['from_city']);
        $dd = Boarding::stopDisplay((string) ($leg['drop_stop'] ?? ''), (string) $leg['to_city']);
        $is = static fn(array $it, array $d): bool => $it['kind'] === 'stop'
            && (mb_strtolower($it['name']) === mb_strtolower((string) $d['name']) || ($d['code'] !== '' && $it['code'] === $d['code']));
        $mineB = null;
        $mineD = null;
        foreach ($line as $i => $it) {
            if ($mineB === null && $it['role'] === 'board' && $is($it, $bd)) { $mineB = $i; }
            if ($it['role'] === 'drop' && $is($it, $dd)) { $mineD = $i; }
        }
        if ($mineD === null && $lastStop !== null && $line[$lastStop]['role'] === 'drop') {
            $mineD = $lastStop;
        }

        $interp = static function (array $pts, float $x): ?float {   // [[x, km]] sorted by x
            $c = count($pts);
            if ($c < 2) { return null; }
            for ($k = 0; $k < $c - 1; $k++) {
                if ($x <= $pts[$k + 1][0] || $k === $c - 2) {
                    $span = $pts[$k + 1][0] - $pts[$k][0];
                    return $span > 0 ? $pts[$k][1] + ($x - $pts[$k][0]) * ($pts[$k + 1][1] - $pts[$k][1]) / $span : $pts[$k][1];
                }
            }
            return null;
        };

        $posKm  = null;
        $source = $done ? 'done' : 'none';
        if ($live && !$done) {
            $geo = EtaAlerts::routeLine($routeId);
            $pos = EtaAlerts::locate($geo, (float) $out['lat'], (float) $out['lng']);
            if ($pos !== null) {
                $anch = [];
                foreach ($geo['line'] as $g) {
                    $gd = Boarding::stopDisplay((string) $g['name']);
                    foreach ($line as $it) {
                        if ($it['kind'] === 'stop' && $it['km'] !== null && ($it['code'] === $gd['code'] || $it['name'] === $gd['name'])) {
                            $anch[(string) $g['km']] = [(float) $g['km'], (float) $it['km']];
                            break;
                        }
                    }
                }
                $anch = array_values($anch);
                usort($anch, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
                $posKm = $interp($anch, (float) $pos['km']);
                if ($posKm === null && $total !== null) {
                    $lastH = (float) (end($geo['line'])['km'] ?? 0);
                    $posKm = $lastH > 0 ? (float) $pos['km'] / $lastH * $total : null;
                }
                if ($posKm !== null) {
                    $posKm  = max(0.0, $total !== null ? min($total, $posKm) : $posKm);
                    $source = 'gps';
                }
            }
        }
        if ($posKm === null && $left) {
            // On the road with no usable fix: where the TIMETABLE says it would be.
            $pts = [];
            foreach ($line as $it) {
                if ($it['offMin'] !== null && $it['km'] !== null) { $pts[] = [(float) $it['offMin'], (float) $it['km']]; }
            }
            usort($pts, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
            $est = $interp($pts, ($now - $depTs - $delay) / 60);
            if ($est !== null) {
                $posKm  = max(0.0, $total !== null ? min($total, $est) : $est);
                $source = 'timetable';
            }
        }

        $passed = array_fill(0, $n, $done);
        if (!$done && ($source === 'gps' || $source === 'timetable')) {
            foreach ($line as $i => $it) {
                if ($source === 'gps') {
                    $passed[$i] = $it['km'] !== null && $it['km'] <= $posKm + 0.5;
                } else {
                    $passed[$i] = $it['offMin'] !== null && $depTs + $delay + (int) $it['offMin'] * 60 <= $now;
                }
            }
        } elseif (!$done && $left) {
            foreach ($line as $i => $it) {
                $passed[$i] = $it['offMin'] !== null && $depTs + $delay + (int) $it['offMin'] * 60 <= $now;
            }
            $source = 'timetable';
        }
        for ($i = $n - 2, $any = $passed[$n - 1]; $i >= 0; $i--) {   // anything before a passed entry is passed
            $any = $any || $passed[$i + 1];
            if ($any) { $passed[$i] = true; }
        }
        $next = -1;
        if (!$done && $source !== 'none') {
            foreach ($passed as $i => $p) {
                if (!$p) { $next = $i; break; }
            }
        }

        $kmh  = isset($out['kmh']) && $out['kmh'] !== null ? (float) $out['kmh'] : null;
        $etas = [];
        if ($source === 'gps') {
            foreach ($line as $i => $it) {
                if (!$passed[$i] && $it['km'] !== null) {
                    $etas[$i] = (int) (ceil(EtaAlerts::etaMinutes(max(0.0, $it['km'] - $posKm), $kmh) / 5) * 5);
                }
            }
            // the passenger's own stop says exactly what the headline says
            if ($mineB !== null && isset($etas[$mineB]) && ($out['etaMin'] ?? null) !== null) {
                $etas[$mineB] = (int) $out['etaMin'];
            }
            // ...and on board, their drop says what the "on the way" headline says
            $dj = $mineD ?? $lastStop;
            if (!empty($out['onBoard']) && ($out['dropEtaMin'] ?? null) !== null && $dj !== null && isset($etas[$dj])
                && Boarding::townKey((string) $line[$dj]['name']) === Boarding::townKey((string) $out['drop'])) {
                $etas[$dj] = (int) $out['dropEtaMin'];
            }
        }

        $tl = [];
        foreach ($line as $i => $it) {
            $hideClock = $i === $lastStop && $destApprox;
            $ts = $it['offMin'] !== null && !$hideClock ? $depTs + (int) $it['offMin'] * 60 : null;
            $tl[] = [
                'kind'    => $it['kind'],
                'role'    => $it['role'],
                'name'    => $it['name'],
                'code'    => $it['code'],
                'time'    => $ts !== null ? date('H:i', $ts) : '',
                'dayPlus' => $ts !== null ? (int) round((strtotime(date('Y-m-d', $ts)) - strtotime($date)) / 86400) : null,
                'ts'      => $ts,
                'km'      => $it['km'],
                'border'  => $it['border'],
                'halt'    => $it['halt'],
                'haltMin' => $it['haltMin'],
                'approx'  => $it['approx'],
                'passed'  => $passed[$i],
                'next'    => $i === $next,
                'mine'    => $i === $mineB ? 'board' : ($i === $mineD ? 'drop' : ''),
                'eta'     => $etas[$i] ?? null,
            ];
        }

        $destEta = null;
        $di = $mineD ?? $lastStop;
        if ($di !== null && !$done) {
            if ($source === 'gps' && isset($etas[$di])) {
                $destEta = ['min' => $etas[$di], 'source' => 'gps', 'approx' => false];
            } elseif ($line[$di]['offMin'] !== null) {
                $at = $depTs + $delay + (int) $line[$di]['offMin'] * 60;
                $destEta = ['min' => max(0, (int) ceil(($at - $now) / 60)), 'source' => 'timetable',
                            'approx' => $di === $lastStop && $destApprox];
            }
        }
        if ($destEta !== null) {
            $destEta['name'] = $line[$di]['name'];
        }

        return [
            'timeline'        => $tl,
            'totalKm'         => $total !== null ? (int) round($total) : null,
            /* D9 (15 Oct 2026): the distance a screen PRINTS - totalKm stays
               the measured scale progress.pct is computed on. */
            'displayKm'       => SiteFacts::routeKm(),
            'spans'           => $sum['spans'],
            'progress'        => [
                'km'     => $done ? ($total !== null ? (int) round($total) : null) : ($posKm !== null ? (int) round($posKm) : null),
                'pct'    => $done ? 100 : ($posKm !== null && $total ? round(100 * $posKm / $total, 1) : null),
                'source' => $source,
            ],
            'runningSinceMin' => ($left || $source === 'gps') && $now >= $depTs + $delay ? intdiv($now - $depTs - $delay, 60) : null,
            'depTs'           => $depTs,
            'destEta'         => $destEta,
            'durationMin'     => $sum['durationMin'],
            'durationApprox'  => !$sum['durationConfirmed'],
        ];
    }

    /** A last-known position older than this is not shown at all. */
    public const LAST_KNOWN_MAX_SEC = 6 * 3600;

    /**
     * Where the coach was last seen, when the live fix is refused ONLY for
     * being old (8 Oct 2026, D7-06). A GPS dead zone mid-journey used to read
     * "not live yet — check closer to departure", as if the bus had not left.
     *
     * Passes the SAME gates as a live answer, because `livebus` is one
     * anonymous key that another coach can write: the trip has left
     * (departed / on route / overdue), it is the ONLY trip of this route on
     * the road, and the position lies on this route's line. Never an ETA.
     *
     * @param array<string,mixed> $leg  the status() leg row (schedule_id, route_id)
     * @return array<string,mixed>|null
     */
    private static function lastKnown(array $leg, string $state, int $now): ?array
    {
        if (!in_array($state, ['departed', 'on_route', 'delayed'], true)) {
            return null;
        }
        /* 20 Oct 2026: a coach with the driver app answers from its own last
           fix, and never from the shared key (that may be another coach). */
        $own = LiveTrips::forSchedule((int) $leg['schedule_id']);
        if ($own !== null) {
            if ($own['last_ping_at'] === null || $own['last_lat'] === null) {
                return null;
            }
            return self::lastKnownAt($leg, (float) $own['last_lat'], (float) $own['last_lng'],
                (int) strtotime((string) $own['last_ping_at']), '', $now);
        }
        $running = array_filter(EtaAlerts::candidates($now), static fn(array $c): bool => (int) $c['route_id'] === (int) $leg['route_id']);
        if (count($running) !== 1 || (int) reset($running)['id'] !== (int) $leg['schedule_id']) {
            return null;
        }
        $row = Database::fetch("SELECT kvalue, updated_at FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' LIMIT 1");
        if ($row === null) {
            return null;
        }
        $v = json_decode((string) $row['kvalue'], true);
        if (is_string($v)) { $v = json_decode($v, true); }     // the client store double-encodes
        if (!is_array($v) || !is_numeric($v['lat'] ?? null) || !is_numeric($v['lng'] ?? null)) {
            return null;
        }
        return self::lastKnownAt($leg, (float) $v['lat'], (float) $v['lng'],
            (int) strtotime((string) $row['updated_at']), trim((string) ($v['stop'] ?? '')), $now);
    }

    /**
     * The "last seen" answer for one stored position, or null when it is
     * fresh (refused for another reason, e.g. accuracy), too old to mean
     * anything, or not on this route.
     *
     * @param array<string,mixed> $leg
     * @return array<string,mixed>|null
     */
    private static function lastKnownAt(array $leg, float $lat, float $lng, int $at, string $stop, int $now): ?array
    {
        $age = $now - $at;
        if ($at <= 0 || $age <= EtaAlerts::FIX_MAX_AGE_SEC || $age > self::LAST_KNOWN_MAX_SEC) {
            return null;   // fresh (refused for another reason — accuracy) or too old to mean anything
        }
        $geo = EtaAlerts::routeLine((int) $leg['route_id']);
        $pos = EtaAlerts::locate($geo, $lat, $lng);
        if ($pos === null || $pos['off'] > EtaAlerts::OFF_ROUTE_KM) {
            return null;   // not on this route: could be the other coach
        }
        if ($stop === '') {
            $best = null;
            foreach ($geo['line'] as $s) {
                $d = EtaAlerts::haversineKm($lat, $lng, (float) $s['lat'], (float) $s['lng']);
                if ($best === null || $d < $best[0]) { $best = [$d, (string) $s['name']]; }
            }
            $stop = $best !== null ? $best[1] : '';
        }
        $out = [
            'lastKnown'  => true,
            'lastAt'     => date('H:i', $at),
            'lastAtIso'  => date('c', $at),
            'lastAgeMin' => (int) round($age / 60),
            'lastStop'   => $stop !== '' ? (string) Boarding::stopDisplay($stop)['name'] : '',
            'lastLat'    => round($lat, 5),
            'lastLng'    => round($lng, 5),
        ];
        if (isset($geo['road'])) {
            $out['road'] = ['v' => (int) $geo['road']['version'], 'km' => round($pos['km'], 2), 'off' => round($pos['off'], 2), 'on' => $pos['on'] === 'road'];
        }
        return $out;
    }

    /**
     * A position on the route's stored road line (20 Oct 2026, phase 2), or
     * null when the route has no road line or the point is off the route.
     *
     * @return array{v:int, km:float, off:float, on:bool}|null   off = km from the road line
     */
    public static function roadAt(int $routeId, float $lat, float $lng): ?array
    {
        $geo = EtaAlerts::routeLine($routeId);
        if (!isset($geo['road'])) {
            return null;
        }
        $at = EtaAlerts::locate($geo, $lat, $lng);
        if ($at === null || $at['off'] > EtaAlerts::OFF_ROUTE_KM) {
            return null;
        }
        return ['v' => (int) $geo['road']['version'], 'km' => round($at['km'], 2), 'off' => round($at['off'], 2), 'on' => $at['on'] === 'road'];
    }

    /**
     * The booking's travelling leg: schedule, route, pickup and drop
     * (api/track-map.php needs only these, not the whole status).
     *
     * @return array{schedule_id:int, route_id:int, boarding_stop:string, drop_stop:string}|null
     */
    public static function leg(array $booking): ?array
    {
        $l = Database::fetch(
            "SELECT bl.schedule_id, s.route_id, bl.boarding_stop, bl.drop_stop
               FROM booking_legs bl JOIN schedules s ON s.id = bl.schedule_id
              WHERE bl.booking_id = :b
              ORDER BY (bl.leg_type = 'outbound') DESC, bl.id ASC
              LIMIT 1",
            ['b' => (int) $booking['id']]
        );
        return $l === null ? null : ['schedule_id' => (int) $l['schedule_id'], 'route_id' => (int) $l['route_id'],
            'boarding_stop' => (string) ($l['boarding_stop'] ?? ''), 'drop_stop' => (string) ($l['drop_stop'] ?? '')];
    }
}
