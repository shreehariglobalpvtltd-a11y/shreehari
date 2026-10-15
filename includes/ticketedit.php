<?php
/**
 * =====================================================================
 *  TicketEdit — the helpers behind the Quick Edit card on the ticket page
 *  (admin/booking-view.php#quick, 15 Oct 2026, owner: "ticket ma galti
 *  bhayo bhane ek thau bata chhito milaune - agent / counter / admin").
 *
 *  Nothing here is a second booking engine. Every SALE still goes through
 *  BookingService::create / SmartBook / QuickTicket, every seat move through
 *  Seats::transferSeat / BookingService::rebookLeg, every cancel through
 *  BookingService::cancel. This file only holds what the ticket page needs
 *  around those engines:
 *
 *    correctFare()    super-admin fare / discount correction: booking,
 *                     latest payment, agent_ledger commission (UPDATED to
 *                     the re-rated figure, never delete + re-accrue -
 *                     UNIQUE(booking_id, entry_type)) and cash_due, in ONE
 *                     transaction, audited, ticket re-synced.
 *    request*()       the "Request correction" queue on ticket_edit_audit
 *                     (outcome 'requested' -> 'done' | 'refused'), with the
 *                     office bell. Refunds and cancels are never applied
 *                     from here - the owner settles those by hand.
 *    stopFareDelta()  a pickup / drop change that would re-price the seat.
 *    genderClash()    a gender correction that would mix a shared cabin
 *                     with a passenger of ANOTHER booking.
 *    seatOk() / autoSeats()  the free-seat filter and the one-click Auto
 *                     Assign (AutoBerth, the SmartBook engine).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/seats.php';
require_once __DIR__ . '/autoberth.php';

final class TicketEdit
{
    /** What staff may ask the office to correct (field_changed values). */
    public const REQUEST_FIELDS = [
        'fare'           => 'Fare / discount',
        'payment_method' => 'Payment method',
        'agent'          => 'Selling agent',
        'cancel_request' => 'Cancel the ticket',
    ];

    /** payments.method values (the edit_payment form's list). */
    public const PAY_METHODS = ['upi', 'esewa', 'cash', 'bank', 'wallet', 'cod'];

    private static ?bool $ready = null;

    /** Is the ticket_edit_audit table there (upgrade-2026-10-15-quick-edit.sql)? */
    public static function available(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Database::fetch("SHOW TABLES LIKE 'ticket_edit_audit'") !== null;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    /* =================================================================
     *  Request correction queue
     * ================================================================= */

    /**
     * Staff requests on one ticket (never the WhatsApp customer rows), newest
     * first. $open = only those still waiting for the office.
     *
     * @return list<array<string, mixed>>
     */
    public static function requests(int $bookingId, bool $open = true, int $limit = 20): array
    {
        if ($bookingId <= 0 || !self::available()) {
            return [];
        }
        return Database::fetchAll(
            "SELECT * FROM ticket_edit_audit
              WHERE booking_id = :b AND changed_by LIKE 'staff:%'" . ($open ? " AND outcome = 'requested'" : '') . "
              ORDER BY id DESC LIMIT " . max(1, min(100, $limit)),
            ['b' => $bookingId]
        );
    }

    /**
     * File a "Request correction" for the office: one ticket_edit_audit row
     * (outcome 'requested', changed_by 'staff:<admin id>') plus the office
     * bell. Changes NOTHING on the booking.
     *
     * @param array<string,mixed> $booking bookings row (id, pnr, status, total_amount, sold_by_admin_id)
     * @param array<string,mixed> $actor   the signed-in admin (id, username, full_name)
     * @return int the request row id
     * @throws RuntimeException (user-safe)
     */
    public static function request(array $booking, string $field, string $newValue, string $reason, array $actor): int
    {
        if (!self::available()) {
            throw new RuntimeException('The correction queue is not set up yet - run database/upgrade-2026-10-15-quick-edit.sql.');
        }
        if (!isset(self::REQUEST_FIELDS[$field])) {
            throw new RuntimeException('Choose what should be corrected.');
        }
        $bid = (int) ($booking['id'] ?? 0);
        if ($bid <= 0) {
            throw new RuntimeException('Booking not found.');
        }
        if (!in_array((string) ($booking['status'] ?? ''), ['pending', 'confirmed'], true)) {
            throw new RuntimeException('Only a pending or confirmed ticket can be corrected.');
        }
        $reason = trim(Security::clean($reason, 200));
        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('Write why - the office reads it before applying the change.');
        }

        $newValue = trim(Security::clean($newValue, 120));
        $old = '';
        $shown = $newValue;
        switch ($field) {
            case 'fare':
                $v = str_replace([',', '₹', ' '], '', $newValue);
                if ($v === '' || !is_numeric($v) || (float) $v < 0) {
                    throw new RuntimeException('Type the correct total fare (0 or more).');
                }
                $newValue = number_format(round((float) $v, 2), 2, '.', '');
                $old      = number_format((float) ($booking['total_amount'] ?? 0), 2, '.', '');
                $shown    = inr((float) $old) . ' → ' . inr((float) $newValue);
                if (abs((float) $newValue - (float) $old) < 0.005) {
                    throw new RuntimeException('That is already the fare on the ticket.');
                }
                break;
            case 'payment_method':
                $newValue = strtolower($newValue);
                if (!in_array($newValue, self::PAY_METHODS, true)) {
                    throw new RuntimeException('Choose the correct payment method.');
                }
                $old   = (string) Database::scalar('SELECT method FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bid], '');
                $shown = strtoupper($old !== '' ? $old : '—') . ' → ' . strtoupper($newValue);
                if ($old === $newValue) {
                    throw new RuntimeException('That is already the payment method on the ticket.');
                }
                break;
            case 'agent':
                $aid = (int) preg_replace('/\D/', '', $newValue);
                if ($aid > 0 && !Database::exists("SELECT 1 FROM admins WHERE id = :i AND role = 'agent' AND is_active = 1", ['i' => $aid])) {
                    throw new RuntimeException('Choose an active agent (or the office).');
                }
                $newValue = (string) $aid;
                $old      = (string) (int) ($booking['sold_by_admin_id'] ?? 0);
                $shown    = self::sellerLabel((int) $old) . ' → ' . self::sellerLabel($aid);
                if ($old === $newValue) {
                    throw new RuntimeException('That is already the selling agent.');
                }
                break;
            case 'cancel_request':
                $newValue = $newValue !== '' ? mb_substr('seat ' . strtoupper($newValue), 0, 40) : 'cancel';
                $old      = (string) ($booking['status'] ?? '');
                $shown    = $newValue === 'cancel' ? 'cancel the whole ticket' : 'cancel ' . $newValue;
                break;
        }

        if (Database::exists(
            "SELECT 1 FROM ticket_edit_audit WHERE booking_id = :b AND field_changed = :f AND outcome = 'requested' AND changed_by LIKE 'staff:%'",
            ['b' => $bid, 'f' => $field]
        )) {
            throw new RuntimeException('A request for this is already waiting for the office.');
        }

        $who = trim((string) (($actor['full_name'] ?? '') ?: ($actor['username'] ?? 'staff')));
        $id  = Database::insert('ticket_edit_audit', [
            'booking_id'    => $bid,
            'pnr'           => (string) $booking['pnr'],
            'field_changed' => $field,
            'old_value'     => mb_substr($old, 0, 255),
            'new_value'     => mb_substr($newValue, 0, 255),
            'changed_by'    => mb_substr('staff:' . (int) ($actor['id'] ?? 0), 0, 80),
            'otp_verified'  => 0,
            'outcome'       => 'requested',
            'detail'        => mb_substr($who . ': ' . $reason, 0, 255),
        ]);

        Logger::audit('booking.edit_request', 'booking', (string) $booking['pnr'], [$field => $old], [$field => $newValue],
            'Correction requested (' . self::REQUEST_FIELDS[$field] . ': ' . $shown . ') by admin #' . (int) ($actor['id'] ?? 0), $reason);
        try {
            require_once __DIR__ . '/adminalerts.php';
            AdminAlerts::push('ticket_edit_request', 'Correction request · ' . (string) $booking['pnr'],
                $who . ' asks: ' . self::REQUEST_FIELDS[$field] . ' — ' . $shown . '. Why: ' . $reason
                    . ($field === 'cancel_request' ? ' (settle the cancel + refund by hand)' : ''),
                '/admin/booking-view.php?pnr=' . rawurlencode((string) $booking['pnr']), '📝', 'tedit:' . $id);
        } catch (Throwable $e) {
            Logger::warning('Correction request alert failed: ' . $e->getMessage(), ['pnr' => (string) $booking['pnr']]);
        }
        return $id;
    }

    /** One open staff request of this booking, or null. */
    public static function findOpen(int $requestId, int $bookingId): ?array
    {
        if ($requestId <= 0 || !self::available()) {
            return null;
        }
        return Database::fetch(
            "SELECT * FROM ticket_edit_audit
              WHERE id = :i AND booking_id = :b AND outcome = 'requested' AND changed_by LIKE 'staff:%' LIMIT 1",
            ['i' => $requestId, 'b' => $bookingId]
        );
    }

    /**
     * Close an open request as 'done' or 'refused', naming who decided.
     * Returns 1 when this call closed it, 0 when it was already closed (a
     * second click never applies a change twice).
     */
    public static function close(int $requestId, string $outcome, string $by, string $note = ''): int
    {
        if (!in_array($outcome, ['done', 'refused'], true) || !self::available()) {
            return 0;
        }
        $row = Database::fetch('SELECT detail FROM ticket_edit_audit WHERE id = :i', ['i' => $requestId]);
        if ($row === null) {
            return 0;
        }
        $detail = trim((string) ($row['detail'] ?? '') . ' | ' . $outcome . ' by ' . $by . ' [' . date('j M H:i') . ']'
            . ($note !== '' ? ': ' . $note : ''));
        return Database::run(
            "UPDATE ticket_edit_audit SET outcome = :o, detail = :d WHERE id = :i AND outcome = 'requested'",
            ['o' => $outcome, 'd' => mb_substr($detail, 0, 255), 'i' => $requestId]
        );
    }

    /** A cancelled ticket answers its open cancel requests (the Cancel button ran). */
    public static function closeOpenCancels(int $bookingId, string $by): int
    {
        $n = 0;
        foreach (self::requests($bookingId, true) as $r) {
            if ((string) $r['field_changed'] === 'cancel_request') {
                $n += self::close((int) $r['id'], 'done', $by, 'ticket cancelled');
            }
        }
        return $n;
    }

    /** "SHG-0007 · Ram" / "the office (no agent)". */
    public static function sellerLabel(int $adminId): string
    {
        if ($adminId <= 0) {
            return 'the office (no agent)';
        }
        $name = (string) Database::scalar("SELECT COALESCE(NULLIF(full_name,''), username) FROM admins WHERE id = :i", ['i' => $adminId], '');
        $code = class_exists('AgentWallet') ? AgentWallet::agentCodeLabel($adminId) : '';
        return trim(($code !== '' ? $code . ' · ' : '') . ($name !== '' ? $name : 'agent #' . $adminId));
    }

    /* =================================================================
     *  Fare / discount correction (super-admin only - the page gates it)
     * ================================================================= */

    /**
     * Correct the fare of a live ticket after the sale. ONE transaction, the
     * booking row FOR UPDATE:
     *   - bookings.total_amount / coupon_discount
     *   - the LATEST payments.amount moves by the same difference
     *   - the agent_ledger 'commission' row is UPDATED so the seller's net
     *     on this booking equals AgentWallet::commissionForBooking() of the
     *     new total (any per-seat void row is respected, never deleted)
     *   - the 'cash_due' row, when there is one, moves by the difference
     *   - one audit row old -> new with the reason; TicketSync 'payment'
     *     after the commit (office-side: REV + redraw, no passenger message)
     * Refused on a cancelled / rejected / expired ticket and below zero.
     * Refunds are never touched (owner settles them by hand).
     *
     * @return array{changed: bool, oldTotal: float, newTotal: float, oldDiscount: float, newDiscount: float,
     *               commission: ?array{old: float, new: float}, cash: ?array{old: float, new: float}, payment: ?array{old: float, new: float}}
     * @throws RuntimeException (user-safe)
     */
    public static function correctFare(int $bookingId, ?float $newTotal, ?float $newDiscount, int $adminId, string $reason): array
    {
        $reason = trim(Security::clean($reason, 255));
        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('Write the reason for the fare correction - it goes in the audit trail.');
        }
        if ($newTotal === null && $newDiscount === null) {
            throw new RuntimeException('Type the corrected total or the discount.');
        }
        if ($newTotal !== null && $newTotal < 0) {
            throw new RuntimeException('A total below zero is not allowed.');
        }
        if ($newDiscount !== null && $newDiscount < 0) {
            throw new RuntimeException('A discount below zero is not allowed.');
        }
        require_once __DIR__ . '/agentwallet.php';
        if (!class_exists('TicketSync')) {
            require_once __DIR__ . '/ticketsync.php';
        }

        return Database::transaction(static function () use ($bookingId, $newTotal, $newDiscount, $adminId, $reason): array {
            $b = Database::fetchForUpdate('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId])[0] ?? null;
            if ($b === null) {
                throw new RuntimeException('Booking not found.');
            }
            if (in_array((string) $b['status'], ['cancelled', 'rejected', 'expired'], true)) {
                throw new RuntimeException('A ' . $b['status'] . ' ticket cannot be re-priced - the owner settles its money by hand.');
            }
            $oldTotal = round((float) $b['total_amount'], 2);
            $oldDisc  = round((float) ($b['coupon_discount'] ?? 0), 2);
            $disc     = $newDiscount !== null ? round($newDiscount, 2) : $oldDisc;
            // A discount alone moves the total by the same amount the other way.
            $total    = $newTotal !== null ? round($newTotal, 2) : round($oldTotal - ($disc - $oldDisc), 2);
            if ($total < 0) {
                throw new RuntimeException('That discount would take the total below zero.');
            }
            $out = ['changed' => false, 'oldTotal' => $oldTotal, 'newTotal' => $total, 'oldDiscount' => $oldDisc,
                    'newDiscount' => $disc, 'commission' => null, 'cash' => null, 'payment' => null];
            if (abs($total - $oldTotal) < 0.005 && abs($disc - $oldDisc) < 0.005) {
                return $out;
            }
            $delta = round($total - $oldTotal, 2);
            $pnr   = (string) $b['pnr'];
            $tail  = 'fare corrected ' . inr($oldTotal) . ' -> ' . inr($total);

            Database::update('bookings', [
                'total_amount'    => $total,
                'coupon_discount' => $disc,
                'updated_at'      => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $bookingId]);

            // The latest payment row records what is collected for the ticket.
            /* Only while that money is still TO COLLECT. A verified payment's
               amount is what was really received (Ticket::paymentSummary
               counts it as paid): raising it would print PAID on a rupee
               nobody collected, lowering it would hide an overpayment. So a
               verified sale keeps its payment and cash rows - a dearer fare
               shows as due, a cheaper one as overpaid for the owner. */
            $pay = Database::fetch('SELECT id, amount, status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bookingId]);
            $collected = $pay !== null && (string) $pay['status'] === 'verified';
            if ($pay !== null && !$collected && abs($delta) >= 0.005) {
                $pNew = max(0.0, round((float) $pay['amount'] + $delta, 2));
                Database::update('payments', ['amount' => $pNew], 'id = :i', ['i' => (int) $pay['id']]);
                $out['payment'] = ['old' => (float) $pay['amount'], 'new' => $pNew];
            }

            /* Commission: UPDATE the one 'commission' row (UNIQUE(booking_id,
               entry_type) - never delete + re-accrue) so the seller's NET on
               this booking (commission + any per-seat void) is what the new
               total earns today. Only once it was earned (confirmed). Flat
               per-seat schemes do not move with the fare - the shift is 0. */
            $comm = Database::fetch("SELECT id, agent_admin_id, amount, note FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission' LIMIT 1", ['b' => $bookingId]);
            if ($comm !== null && in_array((string) $b['status'], ['confirmed', 'completed'], true)) {
                $agentId = (int) $comm['agent_admin_id'];
                /* Only the FARE-driven part moves (review fix): the same scheme
                   priced at the old and the new total, so a rate or override
                   changed since the sale never leaks into the correction, and
                   a flat per-seat scheme shifts by exactly 0. */
                $seatsN  = AgentWallet::seatCountFor($bookingId);
                $shift   = round(AgentWallet::commissionForBooking($total, $agentId, $seatsN)
                               - AgentWallet::commissionForBooking($oldTotal, $agentId, $seatsN), 2);
                if (abs($shift) >= 0.005) {
                    $cNew = round((float) $comm['amount'] + $shift, 2);
                    Database::update('agent_ledger', [
                        'amount' => $cNew,
                        'note'   => mb_substr((string) $comm['note'] . '; re-rated, ' . $tail, 0, 255),
                    ], 'id = :i', ['i' => (int) $comm['id']]);
                    $out['commission'] = ['old' => (float) $comm['amount'], 'new' => $cNew];
                }
            }

            // Cash the seller holds for this ticket moves by the same difference.
            $cash = Database::fetch("SELECT id, amount, note FROM agent_ledger WHERE booking_id = :b AND entry_type = 'cash_due' LIMIT 1", ['b' => $bookingId]);
            if ($cash !== null && !$collected && abs($delta) >= 0.005) {
                $kNew = max(0.0, round((float) $cash['amount'] + $delta, 2));
                Database::update('agent_ledger', [
                    'amount' => $kNew,
                    'note'   => mb_substr((string) $cash['note'] . '; ' . $tail, 0, 255),
                ], 'id = :i', ['i' => (int) $cash['id']]);
                $out['cash'] = ['old' => (float) $cash['amount'], 'new' => $kNew];
            }

            Logger::audit('booking.fare_correct', 'booking', $pnr,
                ['total_amount' => $oldTotal, 'coupon_discount' => $oldDisc, 'payment' => $out['payment']['old'] ?? null,
                 'commission' => $out['commission']['old'] ?? null, 'cash_due' => $out['cash']['old'] ?? null],
                ['total_amount' => $total, 'coupon_discount' => $disc, 'payment' => $out['payment']['new'] ?? null,
                 'commission' => $out['commission']['new'] ?? null, 'cash_due' => $out['cash']['new'] ?? null],
                'Fare corrected by admin #' . $adminId, $reason);

            // After the commit: the invoice and the PNG print the amount.
            TicketSync::afterBookingChange($bookingId, 'payment', ['new' => $tail], ['reason' => $reason]);

            $out['changed'] = true;
            return $out;
        });
    }

    /* =================================================================
     *  Validation helpers
     * ================================================================= */

    /**
     * How much a pickup / drop change would move the fare of this ticket
     * (whole ticket, + = dearer). 0 when the stop cannot change the price:
     * a private cabin, a seater, or a bus with its own fare_override - the
     * same precedence BookingService::priceBooking applies at the sale.
     */
    public static function stopFareDelta(int $bookingId, string $oldBoard, string $oldDrop, string $newBoard, string $newDrop): float
    {
        try {
            $row = Database::fetch(
                "SELECT b.booking_mode, l.schedule_id FROM bookings b
                   JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
                  WHERE b.id = :b LIMIT 1",
                ['b' => $bookingId]
            );
            // an empty / NULL mode is a sharing sale (as booking-view treats it)
            if ($row === null || ((string) ($row['booking_mode'] ?? '') ?: 'sharing') !== 'sharing') {
                return 0.0;
            }
            $schedule = Seats::scheduleById((int) $row['schedule_id']);
            $route    = Database::fetch('SELECT * FROM routes WHERE id = :r', ['r' => (int) $schedule['route_id']]);
            if ($route === null || Seats::effectiveCoach($schedule, $route) !== 'sleeper'
                || BookingService::scheduleFareOverride($schedule) !== null) {
                return 0.0;
            }
            $seats = (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b AND released_at IS NULL', ['b' => $bookingId], 0);
            $old   = Fare::perSeatFor($route, $oldBoard, $oldDrop);
            $new   = Fare::perSeatFor($route, $newBoard, $newDrop);
            return round(($new - $old) * max(1, $seats), 2);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Would giving the passenger on $seat the gender $newGender put a man
     * and a woman of DIFFERENT bookings in one shared cabin? (A booking's own
     * party is the server's whole-cabin rule, not this one.)
     */
    public static function genderClash(int $bookingId, string $seat, string $newGender): bool
    {
        $g = AutoBerth::gender($newGender);
        $seat = strtoupper(trim($seat));
        if ($g === null || $seat === '' || Seats::isExtraSeat($seat)) {
            return false;
        }
        $row = Database::fetch(
            "SELECT b.booking_mode, l.schedule_id FROM bookings b
               JOIN booking_seats l ON l.booking_id = b.id AND l.seat_no = :s AND l.released_at IS NULL
              WHERE b.id = :b LIMIT 1",
            ['b' => $bookingId, 's' => $seat]
        );
        if ($row === null) {
            return false;
        }
        $sid   = (int) $row['schedule_id'];
        $coach = Seats::coachForSchedule($sid);
        $mode  = (string) ($row['booking_mode'] ?? 'sharing');
        if (!Seats::isSharedBooking($coach, $mode !== '' ? $mode : 'sharing')) {
            return false;
        }
        $beds = Seats::unitSeats(Seats::unitKey($seat, $coach, $mode !== '' ? $mode : 'sharing'), $coach);
        if ($beds === []) {
            return false;
        }
        $params = ['sid' => $sid, 'bid' => $bookingId];
        $in     = [];
        foreach (array_values($beds) as $i => $bed) {
            $in[] = ':u' . $i;
            $params['u' . $i] = $bed;
        }
        $others = Database::fetchAll(
            'SELECT bp.gender FROM booking_seats bs
               JOIN booking_passengers bp ON bp.booking_id = bs.booking_id AND bp.seat_no = bs.seat_no
              WHERE bs.schedule_id = :sid AND bs.released_at IS NULL AND bs.booking_id <> :bid
                AND bs.seat_no IN (' . implode(',', $in) . ')',
            $params
        );
        foreach ($others as $o) {
            $og = AutoBerth::gender($o['gender'] ?? null);
            if ($og !== null && $og !== $g) {
                return true;
            }
        }
        return false;
    }

    /**
     * May a passenger of this gender take $seat on this availability? The
     * AutoBerth hard rules for one adult: free, women-only berth for a woman
     * only, never into a cabin held for the other gender. Only meaningful for
     * a SHARED booking - callers skip the filter for a private cabin.
     *
     * @param array<string, mixed> $av Seats::availabilityForSchedule()
     */
    public static function seatOk(array $av, string $seat, string $gender, string $coach): bool
    {
        return AutoBerth::checkManual($av, [$seat], [['gender' => $gender, 'band' => 'adult']], $coach) === '';
    }

    /**
     * One-click Auto Assign: the berths AutoBerth (the SmartBook engine) would
     * give this party on a departure, with an upper / lower preference. A
     * private booking gets whole cabins (requested floor first). Nothing is
     * saved here - the page pre-selects the answer and the existing
     * transactional move (rebookLeg / transferSeat) re-checks it on save.
     *
     * @param list<array<string, mixed>> $paxRows booking_passengers rows, in the order the move maps seats
     * @return array{ok: bool, seats: list<string>, why: string}
     */
    public static function autoSeats(int $scheduleId, string $mode, array $paxRows, string $pref, int $count): array
    {
        $pref  = in_array($pref, ['lower', 'upper'], true) ? $pref : 'any';
        $count = max(1, $count);
        try {
            $coach = Seats::coachForSchedule($scheduleId);
            $mode  = $mode !== '' ? $mode : 'sharing';
            $av    = Seats::availabilityForSchedule($scheduleId, $mode);
            if (!Seats::isSharedBooking($coach, $mode)) {
                $cab = self::pickCabins($av, $count, $pref);
                return $cab === []
                    ? ['ok' => false, 'seats' => [], 'why' => 'not enough free cabins']
                    : ['ok' => true, 'seats' => $cab, 'why' => ''];
            }
            if (!class_exists('SmartBook')) {
                require_once __DIR__ . '/smartbook.php';
            }
            $pax = array_slice(SmartBook::paxFromRows($paxRows), 0, $count);
            while (count($pax) < $count) {
                $pax[] = ['gender' => (string) ($pax[0]['gender'] ?? ''), 'band' => 'adult'];
            }
            $pick = AutoBerth::pick($av, $pax, ['pref' => $pref, 'coach' => $coach]);
            return ['ok' => (bool) $pick['ok'], 'seats' => array_values($pick['seats']), 'why' => (string) $pick['why']];
        } catch (Throwable $e) {
            return ['ok' => false, 'seats' => [], 'why' => $e->getMessage()];
        }
    }

    /**
     * Private cabins in the order SmartBook offers them (its pickCabins is
     * private to the engine): the requested floor first, then the
     * timetable's row order.
     *
     * @return list<string>
     */
    private static function pickCabins(array $av, int $count, string $pref): array
    {
        $free = array_values(array_map('strval', (array) ($av['available'] ?? [])));
        if (count($free) < $count) {
            return [];
        }
        $rear = Settings::getString('seat_ai_row_order', 'rear') !== 'front';
        usort($free, static function (string $a, string $b) use ($pref, $rear): int {
            $pa = AutoBerth::pos($a);
            $pb = AutoBerth::pos($b);
            $da = ($pref === 'upper') === ($pa['deck'] === 'U') ? 0 : 1;
            $db = ($pref === 'upper') === ($pb['deck'] === 'U') ? 0 : 1;
            $na = (int) preg_replace('/\D/', '', $a);
            $nb = (int) preg_replace('/\D/', '', $b);
            return [$da, $rear ? -$na : $na] <=> [$db, $rear ? -$nb : $nb];
        });
        return array_slice($free, 0, $count);
    }
}
