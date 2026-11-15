<?php
/**
 * includes/ticketrequests.php — the passenger's "change my date" and
 * "cancel a seat" REQUESTS (P6, 15 Nov 2026).
 *
 * On the ticket page (ticket/view.php) a passenger holding a confirmed
 * ticket whose bus has not left yet may ask the office for:
 *
 *   change_date   another travel date (tomorrow .. ticket_request_max_days)
 *   cancel_seats  one or more seats of THIS ticket, or the whole ticket
 *
 * A request is a row in ticket_requests and a ring of the office bell -
 * nothing more. Owner's standing rule: refunds, cancellations and date
 * changes are settled BY HAND. Nothing in this file writes to bookings,
 * booking_legs, booking_passengers, seats or money; the office does the
 * change with the existing booking tools (booking-view / reschedule /
 * refunds) and then closes the request here as done or rejected with a
 * note (admin/ticket-requests.php). The passenger is not messaged by this
 * file: the office calls or WhatsApps them itself.
 *
 * Who may ask: whoever holds the ticket's signed key (Ticket::downloadToken,
 * the &k= of every ticket link) - checked by api/ticket-request.php, never
 * a PNR alone.
 *
 * Degrades quietly: until upgrade-2026-11-15-ticket-requests.sql has run,
 * ready() is false, the ticket page shows no buttons and the API answers
 * "not available".
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

/** A request the rules refuse. getCode() is the HTTP status; $reason a short machine word. */
final class TicketRequestRefused extends RuntimeException
{
    /** @param array<string, string> $fields per-field messages for the form */
    public function __construct(string $message, public readonly string $reason, int $http = 409, public readonly array $fields = [])
    {
        parent::__construct($message, $http);
    }
}

final class TicketRequests
{
    public const TABLE = 'ticket_requests';

    /** Open requests one booking may hold at a time. */
    public const MAX_OPEN = 3;

    /** api/ticket-request.php budgets: [hits, seconds]. */
    public const RL_IP      = [10, 3600];
    public const RL_BOOKING = [6, 3600];

    /** kind => [Nepali, English] */
    public const KINDS = [
        'change_date'  => ['मिति परिवर्तन', 'Date change'],
        'cancel_seats' => ['सिट रद्द', 'Seat cancel'],
    ];

    /** status => [Nepali, English] as the PASSENGER reads it */
    public const STATUS_CUSTOMER = [
        'open'     => ['कार्यालयले हेर्दैछ', 'With the office'],
        'done'     => ['भयो', 'Done'],
        'rejected' => ['मिलेन', 'Not possible'],
    ];

    private static ?bool $ready = null;

    private function __construct() {}

    /** The table exists. */
    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Database::fetch("SHOW TABLES LIKE 'ticket_requests'") !== null;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /** Tests: forget the memo after creating / dropping the table. */
    public static function resetReady(): void
    {
        self::$ready = null;
    }

    /** The two buttons are offered (switch ticket_requests_on, ON by default) and the table is there. */
    public static function enabled(): bool
    {
        return Settings::getBool('ticket_requests_on', true) && self::ready();
    }

    /** How far ahead a new date may be asked for (ticket_request_max_days, default 60, 1..365). */
    public static function maxDays(): int
    {
        return max(1, min(365, Settings::getInt('ticket_request_max_days', 60)));
    }

    /**
     * The dates a change may be asked for: tomorrow .. today + maxDays().
     *
     * @return array{from: string, to: string} inclusive ISO dates
     */
    public static function dateWindow(?int $now = null): array
    {
        $now ??= time();
        $today = date('Y-m-d', $now);
        return ['from' => addDaysISO($today, 1), 'to' => addDaysISO($today, self::maxDays())];
    }

    /**
     * The next leg of this booking whose bus has not left yet, or null.
     * Departure = travel date + the schedule's departure time (the per-date
     * override first); a schedule already marked departed / arrived is gone.
     *
     * @return array{leg_id:int, travel_date:string, dep_time:string, ts:int}|null
     */
    public static function nextDeparture(int $bookingId, ?int $now = null): ?array
    {
        $now ??= time();
        try {
            $legs = Database::fetchAll(
                'SELECT bl.id, bl.travel_date, COALESCE(s.dep_time_override, r.dep_time) AS dep_time, s.status AS sched_status
                   FROM booking_legs bl
                   JOIN schedules s ON s.id = bl.schedule_id
                   JOIN routes    r ON r.id = s.route_id
                  WHERE bl.booking_id = :b
                  ORDER BY bl.travel_date ASC, bl.id ASC',
                ['b' => $bookingId]
            );
        } catch (Throwable $e) {
            return null;
        }
        foreach ($legs as $l) {
            if (in_array((string) ($l['sched_status'] ?? ''), ['departed', 'arrived'], true)) {
                continue;
            }
            $time = (string) ($l['dep_time'] ?? '');
            $ts   = strtotime((string) $l['travel_date'] . ' ' . ($time !== '' ? substr($time, 0, 8) : '23:59:59'));
            if ($ts !== false && $ts > $now) {
                return ['leg_id' => (int) $l['id'], 'travel_date' => (string) $l['travel_date'], 'dep_time' => substr($time, 0, 5), 'ts' => (int) $ts];
            }
        }
        return null;
    }

    /**
     * The seats of this booking as stored (L12, U3, 4C ...), each once.
     *
     * @return list<string>
     */
    public static function seatsOf(int $bookingId): array
    {
        $out = [];
        try {
            foreach (Database::fetchAll('SELECT seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => $bookingId]) as $r) {
                $s = strtoupper(trim((string) $r['seat_no']));
                if ($s !== '' && !in_array($s, $out, true)) {
                    $out[] = $s;
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /** Open requests of one booking. */
    public static function openCount(int $bookingId): int
    {
        if (!self::ready()) {
            return 0;
        }
        try {
            return (int) Database::scalar("SELECT COUNT(*) FROM ticket_requests WHERE booking_id = :b AND status = 'open'", ['b' => $bookingId], 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * This booking's requests, newest first (the ticket page's list).
     *
     * @return list<array<string, mixed>>
     */
    public static function forBooking(int $bookingId, int $limit = 10): array
    {
        if (!self::ready() || $bookingId <= 0) {
            return [];
        }
        try {
            return Database::fetchAll(
                'SELECT id, kind, wanted_date, seats, note, status, created_at, handled_at
                   FROM ticket_requests WHERE booking_id = :b ORDER BY id DESC LIMIT ' . max(1, min(50, $limit)),
                ['b' => $bookingId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Why this booking may not take a new request right now, or null when it may.
     *
     * @param array<string, mixed> $booking bookings row
     * @return array{reason: string, message: string}|null
     */
    public static function refusal(array $booking, ?int $now = null): ?array
    {
        if (!self::enabled()) {
            return ['reason' => 'off', 'message' => 'Requests are not available here right now - please call or WhatsApp the office. · अहिले यहाँबाट अनुरोध मिल्दैन - कार्यालयमा फोन वा WhatsApp गर्नुहोस्।'];
        }
        $status = (string) ($booking['status'] ?? '');
        if (in_array($status, ['cancelled', 'rejected', 'expired'], true)) {
            return ['reason' => 'cancelled', 'message' => 'This ticket is cancelled. · यो टिकट रद्द भइसकेको छ।'];
        }
        if ($status !== 'confirmed') {
            return ['reason' => 'not_confirmed', 'message' => 'Only a confirmed ticket can ask for a change. · पक्का भएको टिकटले मात्र परिवर्तन माग्न मिल्छ।'];
        }
        if (self::nextDeparture((int) $booking['id'], $now) === null) {
            return ['reason' => 'departed', 'message' => 'This bus has already left. Please call the office. · यो बस गइसक्यो। कार्यालयमा फोन गर्नुहोस्।'];
        }
        if (self::openCount((int) $booking['id']) >= self::MAX_OPEN) {
            return ['reason' => 'cap', 'message' => 'You already have ' . self::MAX_OPEN . ' requests waiting - the office will contact you. · तपाईंका ' . self::MAX_OPEN . ' अनुरोध पर्खँदै छन् - कार्यालयले सम्पर्क गर्छ।'];
        }
        return null;
    }

    /**
     * Write one request for this booking. Never touches the booking itself.
     *
     * $in: kind, wanted_date (change_date), seats (list or "L1,L2") and/or
     * whole=1 (cancel_seats), note, contact_phone (optional).
     *
     * @param array<string, mixed> $booking bookings row
     * @param array<string, mixed> $in
     * @return array{id: int, kind: string, wanted_date: ?string, seats: ?string, alert: int}
     * @throws TicketRequestRefused
     */
    public static function create(array $booking, array $in, string $ip, ?int $now = null): array
    {
        $now ??= time();
        $bid = (int) ($booking['id'] ?? 0);
        $pnr = (string) ($booking['pnr'] ?? '');
        if ($bid <= 0 || $pnr === '') {
            throw new TicketRequestRefused('Ticket not found. · टिकट भेटिएन।', 'missing', 404);
        }
        $why = self::refusal($booking, $now);
        if ($why !== null) {
            throw new TicketRequestRefused($why['message'], $why['reason'], $why['reason'] === 'off' ? 503 : 409);
        }

        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            throw new TicketRequestRefused('Choose date change or seat cancel. · मिति परिवर्तन वा सिट रद्द छान्नुहोस्।', 'kind', 422, ['kind' => 'Choose one.']);
        }
        $note = Security::clean((string) ($in['note'] ?? ''), 255);

        /* The number to call back: a typed one, else the ticket's own (never the walk-in placeholder). */
        $phone = preg_replace('/\D+/', '', (string) ($in['contact_phone'] ?? '')) ?? '';
        if (str_starts_with($phone, '00')) {
            $phone = substr($phone, 2);
        }
        if ($phone !== '' && !Security::isValidPhone($phone)) {
            throw new TicketRequestRefused('Please check the phone number (10 digits, or with 91 / 977). · फोन नम्बर जाँच्नुहोस्।', 'phone', 422, ['contact_phone' => 'Check the number.']);
        }
        if ($phone === '') {
            $own   = (string) ($booking['contact_phone'] ?? '');
            $phone = !isPlaceholderPhone($own) ? (preg_replace('/\D+/', '', $own) ?? '') : '';
        }
        if ($phone === '') {
            throw new TicketRequestRefused('Please write a phone number so the office can call you. · कार्यालयले फोन गर्न नम्बर लेख्नुहोस्।', 'phone', 422, ['contact_phone' => 'A number is needed.']);
        }

        $wanted = null;
        $seats  = null;
        if ($kind === 'change_date') {
            $d = trim((string) ($in['wanted_date'] ?? ''));
            if (!Security::isValidDate($d)) {
                throw new TicketRequestRefused('Choose the new date. · नयाँ मिति छान्नुहोस्।', 'date', 422, ['wanted_date' => 'Choose a date.']);
            }
            $win = self::dateWindow($now);
            if ($d < $win['from'] || $d > $win['to']) {
                throw new TicketRequestRefused('Choose a date from ' . formatDate($win['from'], 'j M') . ' to ' . formatDate($win['to'], 'j M Y') . '. · ' . $win['from'] . ' देखि ' . $win['to'] . ' सम्मको मिति छान्नुहोस्।', 'date', 422, ['wanted_date' => 'Out of range.']);
            }
            $next = self::nextDeparture($bid, $now);
            if ($next !== null && $next['travel_date'] === $d) {
                throw new TicketRequestRefused('That is already your travel date. · यो त तपाईंको हालको यात्रा मिति नै हो।', 'date', 422, ['wanted_date' => 'Same date.']);
            }
            $wanted = $d;
        } else {
            $own   = self::seatsOf($bid);
            $whole = !empty($in['whole']) && (string) $in['whole'] !== '0';
            $raw   = $in['seats'] ?? [];
            $asked = is_array($raw) ? $raw : explode(',', (string) $raw);
            $pick  = [];
            foreach ($asked as $s) {
                $s = strtoupper(trim((string) $s));
                if ($s === '') {
                    continue;
                }
                if (!Security::isValidSeat($s) || !in_array($s, $own, true)) {
                    throw new TicketRequestRefused('That seat is not on this ticket. · यो सिट यो टिकटको होइन।', 'seat', 422, ['seats' => 'Not on this ticket.']);
                }
                if (!in_array($s, $pick, true)) {
                    $pick[] = $s;
                }
            }
            if ($whole) {
                $pick = $own;
            }
            if ($pick === []) {
                throw new TicketRequestRefused('Tick at least one seat, or the whole ticket. · कम्तीमा एउटा सिट वा पूरा टिकट छान्नुहोस्।', 'seat', 422, ['seats' => 'Tick a seat.']);
            }
            // in the order the ticket lists them
            $pick  = array_values(array_filter($own, static fn(string $s): bool => in_array($s, $pick, true)));
            $seats = mb_substr(implode(',', $pick), 0, 255);
        }

        /* The cap again, under a lock on this booking's request rows, so two
           taps at once cannot make a fourth open request. Only ticket_requests
           is locked or written - the booking row is not. */
        $id = (int) Database::transaction(static function () use ($bid, $pnr, $kind, $wanted, $seats, $note, $phone, $ip): int {
            $rows = Database::fetchForUpdate('SELECT id, status FROM ticket_requests WHERE booking_id = :b', ['b' => $bid]);
            $open = count(array_filter($rows, static fn(array $r): bool => (string) $r['status'] === 'open'));
            if ($open >= self::MAX_OPEN) {
                throw new TicketRequestRefused('You already have ' . self::MAX_OPEN . ' requests waiting - the office will contact you. · तपाईंका ' . self::MAX_OPEN . ' अनुरोध पर्खँदै छन्।', 'cap', 409);
            }
            return Database::insert(self::TABLE, [
                'booking_id'    => $bid,
                'pnr'           => $pnr,
                'kind'          => $kind,
                'wanted_date'   => $wanted,
                'seats'         => $seats,
                'note'          => $note !== '' ? $note : null,
                'contact_phone' => mb_substr($phone, 0, 20),
                'status'        => 'open',
                'ip'            => mb_substr($ip, 0, 45),
            ]);
        });

        Logger::audit('ticket_request.create', 'booking', $pnr, null, [
            'request' => $id, 'kind' => $kind, 'wanted_date' => $wanted, 'seats' => $seats, 'phone' => maskPhone($phone),
        ], 'Passenger asked from the ticket page (request #' . $id . ', ' . $ip . ')');

        $alert = self::ringBell($id, $bid, $pnr, $kind, $wanted, $seats, $note, (string) ($booking['booking_mode'] ?? ''));

        return ['id' => $id, 'kind' => $kind, 'wanted_date' => $wanted, 'seats' => $seats, 'alert' => $alert];
    }

    /** One bell row for the office (in-app only). Returns the notifications id (0 when it could not be stored). */
    private static function ringBell(int $id, int $bid, string $pnr, string $kind, ?string $wanted, ?string $seats, string $note, string $mode): int
    {
        try {
            require_once INCLUDE_PATH . '/adminalerts.php';
            $what = $kind === 'change_date'
                ? 'New date asked: ' . formatDate((string) $wanted, 'D, j M Y')
                : 'Cancel asked: ' . (count(explode(',', (string) $seats)) === count(self::seatsOf($bid)) ? 'whole ticket · ' : '') . self::seatLabels((string) $seats, $mode);
            $title = ($kind === 'change_date' ? '📅 Date change request · ' : '🪑 Seat cancel request · ') . $pnr;
            $nid = AdminAlerts::push('ticket_request', $title, $what . ($note !== '' ? ' — “' . mb_substr($note, 0, 140) . '”' : '') . ' · #' . $id,
                '/admin/ticket-requests.php', $kind === 'change_date' ? '📅' : '🪑');
            if ($nid > 0) {
                try {
                    Database::run('UPDATE notifications SET booking_id = :b WHERE id = :n', ['b' => $bid, 'n' => $nid]);
                } catch (Throwable $ignored) {
                    // the bell row stands without its booking link
                }
            }
            return $nid;
        } catch (Throwable $e) {
            Logger::warning('ticket request bell failed', ['request' => $id, 'error' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * The office closes a request: done (with what was done) or rejected
     * (with why). Only an open request; never touches the booking.
     *
     * @return array<string, mixed> the request row as it was
     * @throws RuntimeException
     */
    public static function decide(int $id, string $status, string $note, int $adminId): array
    {
        if (!self::ready()) {
            throw new RuntimeException('Run database/upgrade-2026-11-15-ticket-requests.sql first.');
        }
        if (!in_array($status, ['done', 'rejected'], true)) {
            throw new RuntimeException('Unknown action.');
        }
        $note = Security::clean($note, 255);
        if ($status === 'rejected' && mb_strlen($note) < 3) {
            throw new RuntimeException('Write why it is not possible before rejecting. · किन मिलेन लेख्नुहोस्।');
        }
        $row = Database::fetch('SELECT * FROM ticket_requests WHERE id = :i', ['i' => $id]);
        if ($row === null) {
            throw new RuntimeException('That request no longer exists.');
        }
        $n = Database::run(
            "UPDATE ticket_requests SET status = :s, staff_note = :n, handled_by = :a, handled_at = NOW() WHERE id = :i AND status = 'open'",
            ['s' => $status, 'n' => $note !== '' ? $note : null, 'a' => $adminId > 0 ? $adminId : null, 'i' => $id]
        );
        if ($n < 1) {
            throw new RuntimeException('This request was already closed (' . (string) $row['status'] . ').');
        }
        Logger::audit('ticket_request.' . $status, 'booking', (string) $row['pnr'], ['request' => $id, 'status' => 'open'],
            ['request' => $id, 'status' => $status, 'note' => $note], 'Request #' . $id . ' (' . (string) $row['kind'] . ') closed by admin #' . $adminId, $note);
        return $row;
    }

    /**
     * The office list. 'all' puts open first; inside a status newest first.
     * $deskScope (Auth::deskScopeCode(), counter desk isolation) keeps a
     * counter clerk to their own desk's tickets, as the booking register,
     * booking page and payments do.
     *
     * @return list<array<string, mixed>>
     */
    public static function listFor(string $status, int $limit = 200, ?string $deskScope = null): array
    {
        if (!self::ready()) {
            return [];
        }
        $cond   = [];
        $params = [];
        if (in_array($status, ['open', 'done', 'rejected'], true)) {
            $cond[]      = 't.status = :s';
            $params['s'] = $status;
        }
        if ($deskScope !== null && $deskScope !== '') {
            $cond[]              = CounterDesk::scopeClause('b', 'deskScope');
            $params['deskScope'] = $deskScope;
        }
        $where = $cond !== [] ? 'WHERE ' . implode(' AND ', $cond) : '';
        try {
            return Database::fetchAll(
                "SELECT t.*, b.status AS booking_status, b.booking_mode, b.contact_country_code,
                        a.full_name AS handled_name, a.username AS handled_user,
                        (SELECT bl.travel_date FROM booking_legs bl WHERE bl.booking_id = t.booking_id ORDER BY bl.travel_date, bl.id LIMIT 1) AS travel_date,
                        (SELECT CONCAT(r.from_city, ' → ', r.to_city) FROM booking_legs bl
                           JOIN schedules s ON s.id = bl.schedule_id JOIN routes r ON r.id = s.route_id
                          WHERE bl.booking_id = t.booking_id ORDER BY bl.travel_date, bl.id LIMIT 1) AS route,
                        (SELECT COUNT(DISTINCT p.seat_no) FROM booking_passengers p WHERE p.booking_id = t.booking_id) AS seat_total
                   FROM ticket_requests t
                   LEFT JOIN bookings b ON b.id = t.booking_id
                   LEFT JOIN admins a ON a.id = t.handled_by
                   {$where}
                  ORDER BY (t.status = 'open') DESC, t.created_at DESC, t.id DESC
                  LIMIT " . max(1, min(500, $limit)),
                $params
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array{open:int, done:int, rejected:int, all:int} ($deskScope as listFor) */
    public static function counts(?string $deskScope = null): array
    {
        $out = ['open' => 0, 'done' => 0, 'rejected' => 0, 'all' => 0];
        if (!self::ready()) {
            return $out;
        }
        $scoped = $deskScope !== null && $deskScope !== '';
        try {
            $rows = $scoped
                ? Database::fetchAll('SELECT t.status, COUNT(*) c FROM ticket_requests t JOIN bookings b ON b.id = t.booking_id WHERE '
                    . CounterDesk::scopeClause('b', 'deskScope') . ' GROUP BY t.status', ['deskScope' => $deskScope])
                : Database::fetchAll('SELECT status, COUNT(*) c FROM ticket_requests GROUP BY status');
            foreach ($rows as $r) {
                $out[(string) $r['status']] = (int) $r['c'];
                $out['all'] += (int) $r['c'];
            }
        } catch (Throwable $e) {
            // counts stay 0
        }
        return $out;
    }

    /** "L1,L2" -> "A1, A2" (the label the ticket prints). */
    public static function seatLabels(string $csv, string $mode = ''): string
    {
        $seats = array_values(array_filter(array_map('trim', explode(',', $csv)), 'strlen'));
        if ($seats === []) {
            return '';
        }
        try {
            if (!class_exists('Seats')) {
                require_once INCLUDE_PATH . '/seats.php';
            }
            return Seats::displayLabels($seats, 'sleeper', $mode !== '' ? $mode : 'sharing');
        } catch (Throwable $e) {
            return implode(', ', $seats);
        }
    }

    /** wa.me digits: a bare 10-digit number takes the ticket's country code (else 91, as the booking page does). */
    public static function waDigits(string $phone, string $cc = ''): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        if (strlen($d) === 10) {
            $cc = preg_replace('/\D+/', '', $cc) ?? '';
            $d  = ($cc !== '' ? $cc : '91') . $d;
        }
        return preg_match('/^[1-9]\d{9,14}$/', $d) === 1 ? $d : '';
    }
}
