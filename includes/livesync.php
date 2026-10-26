<?php
/**
 * =====================================================================
 *  LiveSync — "has anything on this screen changed in the database?"
 *
 *  Owner, 2 Oct 2026: "real time ma jun ticket edit, update, change
 *  garyo … agent panel ma pani, admin panel ma pani change hunu paryo."
 *  A seat moved on the seat map, an agent assigned to a ticket, a name
 *  corrected, a seat cancelled, a ticket moved to the second bus — the
 *  agent panel that was already open kept showing the old picture until
 *  somebody pressed reload.
 *
 *  version() folds everything those screens print into one short hash:
 *  the bookings (status, amount, seller, updated_at), their live seats
 *  (bus + berth), passengers (name / berth / gender), legs (bus, date,
 *  pickup, drop), the schedules those legs ride (bus swap, extra bus,
 *  trip status) and the commission ledger. The admin poll
 *  (admin/pending-count.php, every 15 s) sends the scope the page asked
 *  for and the page refreshes itself when the hash moves.
 *
 *  Scopes:
 *    'agent:<id>'  one seller's book (agent panel, My Sales, My Tickets)
 *    'sched:<id>'  one departure (seat map)
 *    'all'         the office view — bookings made in the last 180 days
 *                  or travelling from a week ago onward
 *
 *  It never decides anything; it only says "changed / not changed".
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class LiveSync
{
    /**
     * Normalise a requested scope to one the signed-in staff member may
     * watch. A counter agent is always pinned to their own book: whatever
     * they ask for, they get 'agent:<self>' (or a departure, which the seat
     * map already lets them open). Returns '' for nonsense.
     */
    public static function scopeFor(string $requested): string
    {
        $requested = strtolower(trim($requested));
        $self      = Auth::bookingScopeAdminId();

        if (preg_match('/^sched:(\d{1,12})$/', $requested, $m)) {
            return 'sched:' . (int) $m[1];
        }
        if ($self !== null) {
            return 'agent:' . $self;
        }
        if (preg_match('/^agent:(\d{1,12})$/', $requested, $m)) {
            return 'agent:' . (int) $m[1];
        }
        return $requested === 'all' ? 'all' : '';
    }

    /** Short hash of everything the scope's screens print. '' = unknown scope. */
    public static function version(string $scope): string
    {
        if ($scope === '') {
            return '';
        }
        try {
            if (preg_match('/^agent:(\d+)$/', $scope, $m)) {
                $parts = self::bookingParts('b.sold_by_admin_id = :a', ['a' => (int) $m[1]]);
                $parts[] = Database::fetch(
                    'SELECT COUNT(*) n, COALESCE(MAX(id), 0) m, COALESCE(SUM(amount), 0) s
                       FROM agent_ledger WHERE agent_admin_id = :a',
                    ['a' => (int) $m[1]]
                );
            } elseif (preg_match('/^sched:(\d+)$/', $scope, $m)) {
                $sid   = (int) $m[1];
                $parts = self::bookingParts(
                    'b.id IN (SELECT bl0.booking_id FROM booking_legs bl0 WHERE bl0.schedule_id = :s
                              UNION SELECT bs0.booking_id FROM booking_seats bs0 WHERE bs0.schedule_id = :s2)',
                    ['s' => $sid, 's2' => $sid]
                );
                // Blocks and the coach / trip status — what the page's tables
                // draw besides bookings. Checkout holds are left out on
                // purpose: they come and go every few minutes, the tiles
                // already repaint them, and a pill for each would be noise.
                $parts[] = Database::fetch(
                    'SELECT COUNT(*) n, COALESCE(MAX(id), 0) m FROM seat_blocks WHERE schedule_id = :s',
                    ['s' => $sid]
                );
                $parts[] = Database::fetch(
                    'SELECT status, is_blocked, bus_id, slot, dep_time_override FROM schedules WHERE id = :s',
                    ['s' => $sid]
                );
            } elseif ($scope === 'all') {
                $since = date('Y-m-d', strtotime('-180 days'));
                $from  = date('Y-m-d', strtotime('-7 days'));
                $parts = self::bookingParts(
                    '(b.created_at >= :since OR b.id IN (SELECT bl0.booking_id FROM booking_legs bl0 WHERE bl0.travel_date >= :from))',
                    ['since' => $since, 'from' => $from]
                );
            } else {
                return '';
            }
        } catch (Throwable $e) {
            // A failed read must not look like "changed" every 15 s (that
            // would reload the page in a loop) — report a stable marker.
            return 'err';
        }
        return substr(hash('sha1', (string) json_encode($parts)), 0, 16);
    }

    /**
     * The four booking-side fingerprints for any WHERE over `bookings b`.
     * CRC32 sums are order-free, so the answer does not depend on how the
     * engine walks the index — only on what the rows say.
     *
     * @param array<string, mixed> $p
     * @return array<int, mixed>
     */
    private static function bookingParts(string $where, array $p): array
    {
        $bookings = Database::fetch(
            "SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT_WS('|', b.id, b.status, b.total_amount,
                    IFNULL(b.sold_by_admin_id, 0), b.updated_at, IFNULL(b.refund_status, '')))), 0) h
               FROM bookings b WHERE $where",
            $p
        );
        $seats = Database::fetch(
            "SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT_WS('|', bs.id, bs.schedule_id, bs.seat_no,
                    IFNULL(bs.released_at, '')))), 0) h
               FROM booking_seats bs JOIN bookings b ON b.id = bs.booking_id WHERE $where",
            $p
        );
        $pax = Database::fetch(
            "SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT_WS('|', bp.id, bp.full_name, bp.seat_no,
                    IFNULL(bp.gender, ''), IFNULL(bp.age, ''), IFNULL(bp.boarded_at, '')))), 0) h
               FROM booking_passengers bp JOIN bookings b ON b.id = bp.booking_id WHERE $where",
            $p
        );
        $legs = Database::fetch(
            "SELECT COUNT(*) n, COALESCE(SUM(CRC32(CONCAT_WS('|', bl.id, bl.schedule_id, bl.travel_date,
                    IFNULL(bl.boarding_stop, ''), IFNULL(bl.drop_stop, ''), IFNULL(bl.boarding_time, ''),
                    IFNULL(s.bus_id, 0), s.slot, s.status, IFNULL(s.dep_time_override, '')))), 0) h
               FROM booking_legs bl
               JOIN bookings b ON b.id = bl.booking_id
               JOIN schedules s ON s.id = bl.schedule_id
              WHERE $where",
            $p
        );
        return [$bookings, $seats, $pax, $legs];
    }

    /**
     * The hidden anchor a page drops to opt in. admin_poll_js() finds it,
     * sends data-scope with every poll and compares data-ver.
     *
     *   mode 'auto' — a read-only board: reload by itself when the viewer
     *                 is idle (nothing typed, no menu open), keep the scroll
     *   mode 'pill' — a working page with forms: only offer a Refresh pill
     */
    public static function anchor(string $scope, string $mode = 'auto'): string
    {
        $scope = self::scopeFor($scope);
        if ($scope === '') {
            return '';
        }
        return '<span id="liveSync" hidden data-scope="' . Security::e($scope) . '" data-ver="'
            . Security::e(self::version($scope)) . '" data-mode="' . ($mode === 'pill' ? 'pill' : 'auto') . '"></span>';
    }
}
