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

final class WhereIs
{
    /**
     * Three doors, the same as verify-ticket.php: the keyed link, the
     * signed-in customer whose number is on the booking, or staff who may
     * see this booking.
     */
    public static function authorised(array $booking, string $token): bool
    {
        $pnr = (string) ($booking['pnr'] ?? '');
        if ($token !== '' && Ticket::checkDownloadToken($pnr, $token)) {
            return true;
        }
        $user = Auth::user();
        if ($user !== null) {
            $mine = normalisePhone((string) ($user['phone'] ?? ''));
            if ($mine !== '' && $mine === normalisePhone((string) ($booking['contact_phone'] ?? ''))) {
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
     * @param array<string,mixed> $booking  a bookings row
     * @return array<string,mixed>  JSON-able
     */
    public static function status(array $booking, ?int $now = null): array
    {
        $now = $now ?? time();
        $leg = Database::fetch(
            "SELECT bl.schedule_id, bl.travel_date, bl.boarding_stop, bl.drop_stop, bl.boarding_time,
                    s.route_id, s.status AS trip_status, s.delay_minutes, s.delay_note, s.bus_id,
                    r.from_city, r.to_city, r.dep_time, r.arr_time, r.day_offset, r.route_code,
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
            'updatedAt'  => date('c', $now),
        ];
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

        // Trip state from the same ladder the office board uses.
        $trip = TripStatus::editableFor((int) $leg['schedule_id'], $now);
        $out['state'] = (string) ($trip['state'] ?? 'unknown');
        $out['label'] = (string) ($trip['label'] ?? '');

        // The coach's position: only when it is fresh, accurate and on this
        // route — and only when exactly one bus of this route is on the road.
        [$fix, $why] = EtaAlerts::fix($now);
        if ($fix === null) {
            $out['reason'] = $why;
            return $out;
        }
        $running = array_filter(EtaAlerts::candidates($now), static fn(array $c): bool => (int) $c['route_id'] === (int) $leg['route_id']);
        if (count($running) !== 1 || (int) reset($running)['id'] !== (int) $leg['schedule_id']) {
            $out['reason'] = count($running) > 1 ? 'two buses on this route, not guessing which' : 'this trip is not on the road yet';
            return $out;
        }
        $etas = EtaAlerts::stopEtas((int) $leg['route_id'], $fix, $now);
        if ($etas === []) {
            $out['reason'] = 'the coach is not on this route right now';
            return $out;
        }
        $out['live']   = true;
        $out['lat']    = $fix['lat'];
        $out['lng']    = $fix['lng'];
        $out['kmh']    = $fix['kmh'] !== null ? (int) round($fix['kmh']) : null;
        $row = Database::fetch("SELECT updated_at FROM kv_store WHERE kscope = 'global' AND kkey = 'livebus' LIMIT 1");
        $out['fixAge'] = $row !== null ? max(0, $now - (int) strtotime((string) $row['updated_at'])) : null;
        $key = Boarding::townKey((string) ($leg['boarding_stop'] ?? ''));
        if (isset($etas[$key])) {
            $out['etaMin'] = (int) (ceil($etas[$key]['eta'] / 5) * 5);
            $out['km']     = $etas[$key]['km'];
        } else {
            $out['passed'] = true;      // the bus is already past this stop
        }
        return $out;
    }
}
