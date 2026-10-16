<?php
/**
 * =====================================================================
 *  SeatVersion — a cheap fingerprint of one departure's seat state.
 *
 *  Four indexed reads (live seats, live holds, blocks, the schedule row)
 *  folded into a short hash. It changes whenever anything a seat map
 *  shows changes: a booking lands or is released, a hold is taken or
 *  expires, a berth is blocked, the coach or the trip status changes.
 *
 *  Used by api/seat-events.php (the live stream watches it once a second)
 *  and by api/seats.php (a client that already holds this version gets a
 *  200-byte "unchanged" answer instead of the whole snapshot). It never
 *  decides availability — Seats::availability() still does that.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class SeatVersion
{
    public static function of(int $scheduleId): string
    {
        if ($scheduleId <= 0) {
            return '';
        }
        $p = ['s' => $scheduleId];
        $seats  = Database::fetch('SELECT COUNT(*) c, COALESCE(MAX(id),0) m FROM booking_seats WHERE schedule_id = :s AND released_at IS NULL', $p) ?? [];
        $holds  = Database::fetch('SELECT COUNT(*) c, COALESCE(MAX(id),0) m, COALESCE(MAX(UNIX_TIMESTAMP(expires_at)),0) e FROM seat_locks WHERE schedule_id = :s AND expires_at > NOW()', $p) ?? [];
        $blocks = Database::fetch('SELECT COUNT(*) c, COALESCE(MAX(id),0) m FROM seat_blocks WHERE schedule_id = :s', $p) ?? [];
        $row    = Database::fetch('SELECT status, is_blocked, bus_id, coach_type_override, COALESCE(UNIX_TIMESTAMP(updated_at),0) u FROM schedules WHERE id = :s', $p) ?? [];

        return substr(hash('sha1', json_encode([$seats, $holds, $blocks, $row])), 0, 16);
    }
}
