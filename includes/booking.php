<?php
/**
 * =====================================================================
 *  BookingService — the transactional heart of the system.
 *
 *  Creating a booking, verifying a payment, confirming, cancelling and
 *  refunding all live here so the rules are enforced in exactly one
 *  place. Every write runs inside a database transaction; a seat clash
 *  or a validation failure rolls the whole thing back rather than
 *  leaving a half-booked PNR behind.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// Counter-agent wallet — confirm/reject/cancel and counterSale() all credit
// or reverse it, so it is a hard dependency of this service rather than
// something each entry point has to remember to include.
require_once __DIR__ . '/agentwallet.php';

final class BookingService
{
    /**
     * Hours after a bus's SCHEDULED departure that an AGENT (valid SHG-NNN
     * code) may still record a NEW ticket on it through the customer app —
     * the "chuteko + 24 ghanta" late-booking window (owner ask, 3 Sep 2026).
     * Anonymous customers are never granted this. Mirrors the counter-side
     * TripStatus::COUNTER_LATE_HOURS.
     */
    public const LATE_BOOK_HOURS = 24;

    /**
     * Sentinel a client posts as `boarding` / `drop` to say "the passenger
     * typed their own point" (owner ask, 17 Sep 2026). The text then arrives
     * in boardingOther / dropOther — api/book.php for create(), $data for
     * counterSale(). Free text is accepted ONLY behind this sentinel; see the
     * "Other" pickup / drop block in create() and manualStop().
     */
    public const BOARDING_OTHER = '__other__';

    /**
     * Create a pending booking.
     *
     * The $request array is already-validated input from the API layer:
     *   routeId, travelDate, seats[], passengers[], contact{},
     *   bookingMode, sharingTier, couponCode, pointsRequested,
     *   referralCode, isCod, returnLeg{?}
     *
     * @param array<string, mixed> $request
     * @param array<string, mixed>|null $seller  Counter mode (3 Sep 2026): a signed-in
     *        staff member selling through the customer app. Keys: adminId,
     *        source (agent|counter|admin), paymentMethod (cash|upi|esewa|bank),
     *        discountType (flat|percent), discountValue, note. Only the API layer
     *        builds this, from the SESSION — never from the request body. When
     *        null (every anonymous customer) this function behaves exactly as before.
     * @return array<string, mixed> the created booking (with pnr)
     * @throws RuntimeException on any rule violation (message is user-safe)
     */
    /**
     * Does booking_passengers carry the special_need column yet? Checked once
     * per request so a live server that has not run the migration keeps
     * booking normally (the flag is simply not stored).
     */
    /**
     * Has database/upgrade-2026-09-counter-desks.sql been applied? Until it
     * has, a sale is written exactly as before — the file may be deployed
     * first and the SQL run after, in either order, with no failed ticket.
     */
    private static function deskColumns(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'counter_code'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /**
     * Does `bookings` carry advance_discount yet
     * (database/upgrade-2026-09-vip-advance.sql)? Same deploy-in-either-order
     * rule as deskColumns(): until the SQL is run the offer is still granted
     * and still reduces total_amount — only its own column is skipped, so no
     * passenger is overcharged by a migration that has not landed.
     */
    private static function advanceColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'advance_discount'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /** The same question for the payment row's local-money columns. */
    private static function tenderColumns(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Database::fetch("SHOW COLUMNS FROM payments LIKE 'local_currency'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /**
     * WHERE this ticket was sold, frozen onto the booking, plus what the
     * customer was quoted in their own money at a Nepal desk.
     *
     * The desk used to be read back at print time through the seller's
     * admin_profiles row — today's desk for that person — so moving a clerk
     * from Surat to Rajkot moved every ticket they had ever sold and last
     * month's Surat sheet changed. An online sale has no desk and is left
     * NULL; a desk nobody has assigned yet is left NULL too, because a wrong
     * town on a ticket is worse than no town.
     *
     * @return array<string,mixed> merged into the bookings insert
     */
    private static function deskStamp(?int $sellerAdminId, float $totalInr): array
    {
        if (!self::deskColumns()) {
            return [];
        }
        $code = CounterDesk::codeForSale($sellerAdminId);
        if ($code === null) {
            return [];
        }
        $out = ['counter_code' => $code];

        /* INR stays the currency the books run in. At an NPR desk we also
           freeze what the window actually said out loud, with the rate used
           at that second — so a rate change tomorrow can never rewrite what
           this passenger was charged. */
        $fx = CounterDesk::convert($totalInr, $code);
        if ($fx['currency'] !== 'INR') {
            $out['fx_currency'] = $fx['currency'];
            $out['fx_rate']     = $fx['rate'];
            $out['fx_total']    = $fx['amount'];
        }

        return $out;
    }

    /**
     * What the drawer physically received, when that is not rupees.
     * payments.amount stays the INR the ledger sums; local_amount is the
     * NPR the clerk counted, so a Nepalgunj day sheet balances in the money
     * that is actually in the box.
     *
     * @return array<string,mixed> merged into the payments insert
     */
    private static function tenderStamp(?int $sellerAdminId, float $amountInr): array
    {
        if (!self::tenderColumns()) {
            return [];
        }
        $code = CounterDesk::codeForSale($sellerAdminId);
        if ($code === null) {
            return [];
        }
        $fx = CounterDesk::convert($amountInr, $code);
        if ($fx['currency'] === 'INR') {
            return [];
        }

        return [
            'local_currency' => $fx['currency'],
            'local_amount'   => $fx['amount'],
            'fx_rate'        => $fx['rate'],
        ];
    }

    private static function paxSpecialColumn(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Database::fetch("SHOW COLUMNS FROM booking_passengers LIKE 'special_need'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /**
     * One passenger document field (id_type / id_number / nationality) as it
     * is stored: the traveller's own value, else $fallback (the contact's
     * document for the primary passenger), cleaned to the column width and
     * NULL when both are blank — so an untouched row reads as "not given",
     * never as an empty string the manifest would print. 17 Sep 2026.
     */
    private static function paxIdField(mixed $own, mixed $fallback): ?string
    {
        $v = Security::clean((string) (is_scalar($own) ? $own : ''), 60);
        if ($v === '') {
            $v = Security::clean((string) (is_scalar($fallback) ? $fallback : ''), 60);
        }
        return $v !== '' ? $v : null;
    }

    public static function create(array $request, ?array $seller = null): array
    {
        $routeId    = (int) $request['routeId'];
        $travelDate = (string) $request['travelDate'];
        $seats      = array_values(array_unique(array_map('strval', $request['seats'] ?? [])));
        $passengers = $request['passengers'] ?? [];
        $contact    = $request['contact'] ?? [];
        $token      = Auth::lockToken();

        /* ---- Shape validation ------------------------------------- */
        if ($seats === []) {
            throw new RuntimeException('Please select at least one seat.');
        }

        // 5 Sep 2026 (bulk booking): a staff sale ($seller present — counter
        // mode, seatmap walk-in, agent) may hold up to counter_max_seats_per_
        // booking (default 20) in ONE booking — a party travelling under one
        // name. Anonymous customers keep the public max_seats_per_booking cap.
        $maxSeats = self::maxSeatsFor($seller !== null);
        if (count($seats) > $maxSeats) {
            throw new RuntimeException('A single booking may hold at most ' . $maxSeats . ' seats.');
        }

        if (count($passengers) !== count($seats)) {
            throw new RuntimeException('Please enter details for every selected seat.');
        }

        $phone = normalisePhone((string) ($contact['phone'] ?? ''));
        if ($seller !== null && $phone === '') {
            // A walk-in sold from the seat map may have no phone at all — the old
            // counterSale() stored this placeholder; keep the manifest honest.
            $phone = '0000000000';
        } elseif (!Security::isValidPhone($phone, $seller !== null)) {
            // Staff get the 8-15 range the counter UI has always promised.
            throw new RuntimeException('Please enter a valid mobile number.');
        }

        $route = Database::fetch('SELECT * FROM routes WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $routeId]);
        if ($route === null) {
            throw new RuntimeException('That route is no longer available.');
        }

        /* ---- Which departure (Bus Calendar, 5 Sep 2026) ---------------
           The daily bus is slot 1, resolved by route + date exactly as before.
           An extra bus the office added for the date has its own schedules.id,
           which the app sends as scheduleId; it must belong to this route and
           date or the request is refused. */
        $scheduleIdReq  = (int) ($request['scheduleId'] ?? 0);
        $chosenSchedule = null;
        if ($scheduleIdReq > 0) {
            $chosenSchedule = Database::fetch('SELECT * FROM schedules WHERE id = :id LIMIT 1', ['id' => $scheduleIdReq]);
            if ($chosenSchedule === null || (int) $chosenSchedule['route_id'] !== $routeId || (string) $chosenSchedule['travel_date'] !== $travelDate) {
                throw new RuntimeException('That departure is no longer available — please search again.');
            }
        }

        /* ---- Agent-code resolution + late-booking window --------------
           Resolved UP FRONT — before the sales-window / departed / boarding
           gates — so a counter AGENT (a valid SHG-NNN code typed on the app
           checkout) can record a ticket for a bus that has ALREADY LEFT, for up
           to LATE_BOOK_HOURS after its scheduled departure. The resolver refuses
           disabled / locked / unknown codes and returns null, so $agentOverride
           is false for every anonymous customer and each gate below behaves
           exactly as it always has. The window itself (Boarding::agentGraceOpen)
           requires the bus to have actually departed AND still be inside 24h —
           it can never open a future/未-departed or a long-past trip. */
        $referralCodeClean = strtoupper(Security::clean($request['referralCode'] ?? '', 20));
        $soldByAdminId     = $referralCodeClean !== ''
            ? AgentWallet::resolveAgentCodeFromString($referralCodeClean)
            : null;
        $agentOverride = $soldByAdminId !== null
            && Boarding::agentGraceOpen($routeId, $travelDate, self::LATE_BOOK_HOURS);

        /* ---- Counter mode (3 Sep 2026) --------------------------------
           ONE booking function for every role. A seller context changes only
           attribution (sold_by / source), the payment record (money already
           taken at the counter -> confirmed + verified now) and the counter
           discount. Pricing, seat rules, gender lock, boarding cut-off and the
           ticket are the same code path a customer runs, so a 5-seat family
           pays the same at the counter as online. */
        $sellerId = null; $sellerSource = 'web'; $counterMethod = null; $counterNote = '';
        if ($seller !== null) {
            $sellerId = (int) ($seller['adminId'] ?? 0);
            if ($sellerId <= 0) {
                throw new RuntimeException('Counter sale needs a signed-in staff member.');
            }
            $sellerSource  = in_array($seller['source'] ?? '', ['agent', 'counter', 'admin'], true) ? (string) $seller['source'] : 'counter';
            $counterMethod = in_array($seller['paymentMethod'] ?? '', ['cash', 'upi', 'esewa', 'bank'], true) ? (string) $seller['paymentMethod'] : 'cash';
            /* A desk with no bank account of its own takes cash (26 Sep 2026).
               Refusing here, on the one path every app sale goes through, is
               the only place it cannot be worked around from a browser. */
            $sellerDesk = CounterDesk::codeForSale($sellerId);
            if ($sellerDesk !== null && !CounterDesk::allowsMethod($sellerDesk, $counterMethod)) {
                throw new RuntimeException(
                    CounterDesk::label($sellerDesk) . ' takes '
                    . implode(' / ', CounterDesk::allowedMethods($sellerDesk) ?? []) . ' only.'
                );
            }
            $counterNote   = Security::clean((string) ($seller['note'] ?? ''), 255);
            $referralCodeClean = '';           // the seller IS the agent; a typed code is ignored
            $soldByAdminId     = $sellerId;
            $agentOverride     = Boarding::agentGraceOpen($routeId, $travelDate, self::LATE_BOOK_HOURS);
            // Route restriction + daily cap: the same choke point counterSale uses,
            // BEFORE the transaction so a refusal costs no lock.
            AgentWallet::assertMaySell($sellerId, $routeId);
        }

        /* ---- Any-date sales for staff (6 Sep 2026) ----------------
           A selling staff member (admin / counter / agent — the same rule
           counter mode's canSell uses) may record a ticket for any travel
           date: a paper ticket from three days ago, a group two months
           out. This lifts the CUSTOMER-only gates below — the today floor,
           the 30-day horizon, "bus already departed" and the pickup
           cut-off. It does NOT lift the inaugural floor (no bus existed
           before booking_open_from), a cancelled trip, or a per-date OFF.
           Requires BOTH a seller context and a real selling session, so a
           bare $seller array from a stray caller does not get it. */
        $staffAnyDate = $seller !== null && Auth::isSellingStaff();

        /* ---- Master switch (4 Sep 2026) ----------------------------
           Admin → Buses → "Daily service" OFF pauses selling for everyone —
           the app, the counter and the agent panel alike — until the office
           switches it back on. Checked before any lock or row is written. */
        if (!Settings::getBool('daily_service_on', true)) {
            $note = trim(Settings::getString('daily_service_note', ''));
            throw new RuntimeException(
                'Booking is paused — the daily service is switched off for now. / बुकिङ अहिले बन्द छ — दैनिक सेवा रोकिएको छ।'
                . ($note !== '' ? ' ' . $note : '')
            );
        }

        /* ---- Sales window -----------------------------------------
           Service is inaugurated 2 Sep 2026; nothing may be sold for an
           earlier travel date, and sales stay within the rolling horizon.
           Server-enforced so no client (or stale cache) can slip past. */
        $window = bookingWindow();
        // Staff any-date keeps exactly one hard floor: the day the service
        // began. A ticket dated before the first bus is not a booking, it is
        // fictional data.
        $openFrom = Settings::getString('booking_open_from', '2026-09-02');
        if ($staffAnyDate && Security::isValidDate($openFrom) && $travelDate < $openFrom) {
            throw new RuntimeException('No service ran before ' . formatDate($openFrom) . ' — the inaugural departure. / ' . formatDate($openFrom) . ' अघि सेवा थिएन।');
        }
        if ($travelDate < $window['from'] && !$agentOverride && !$staffAnyDate) {
            throw new RuntimeException('Booking opens for travel from ' . formatDate($window['from']) . ' — the inaugural departure.');
        }
        if ($travelDate > $window['to'] && !$staffAnyDate) {
            throw new RuntimeException('Booking is open up to ' . formatDate($window['to']) . ' for now. Later dates open soon.');
        }

        /* ---- Duplicate-booking guard ------------------------------ */
        // Not for counter sales: an office phone legitimately books several
        // separate parties on the same coach in a row (counterSale never had it).
        if ($seller === null) {
            self::assertNoDuplicate($phone, $routeId, $travelDate, $seats);
        }

        /* ---- Schedule status gate ---------------------------------
           A schedule row that has been advanced past 'scheduled' — the
           coach for the day has already departed, arrived, or been
           cancelled — must refuse the whole checkout up front, before
           any seat lock, payment, or state mutation. The per-pickup
           Boarding cut-off below still runs on top of this: this is
           the day-level gate, that is the pickup-level one. Only an
           EXISTING row is inspected (never lazily materialised here),
           so a fresh future date passes straight through, and the
           check is safely idempotent on a retry. */
        $scheduleRow = $chosenSchedule ?? Database::fetch(
            'SELECT status, is_blocked FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1 LIMIT 1',
            ['r' => $routeId, 'd' => $travelDate]
        );
        if ($scheduleRow !== null) {
            $schedStatus = (string) $scheduleRow['status'];
            // Per-date OFF (Bus Calendar, 5 Sep 2026): a blocked departure is
            // hidden from search AND refuses a checkout that still holds it —
            // before this, only search knew about is_blocked.
            if ((int) ($scheduleRow['is_blocked'] ?? 0) === 1) {
                throw new RuntimeException('Booking closed — this service is not running on this date. / बुकिङ बन्द छ — यो मितिमा सेवा छैन।');
            }
            // Cancelled ALWAYS blocks — the grace never resurrects a void trip.
            if ($schedStatus === 'cancelled') {
                throw new RuntimeException('Booking closed — this service is cancelled for the day. / बुकिङ बन्द छ — यो सेवा आजका लागि रद्द भएको छ।');
            }
            // Departed / arrived blocks the anonymous customer, but a valid
            // agent inside the 24h window may still record the ticket.
            if (in_array($schedStatus, ['departed', 'arrived'], true) && !$agentOverride && !$staffAnyDate) {
                throw new RuntimeException('Booking closed — this bus has already departed for the day. / बुकिङ बन्द छ — आजको बस पहिले नै छुटिसकेको छ।');
            }
        }

        /* ---- Pickup cut-off ---------------------------------------
           A coach keeps selling for hours after it leaves its first town,
           so the rule is per PICKUP, not per route: Mehsana at 23:00 is
           still sellable at 20:00, while Surat at 13:00 is long gone.
           Checked here, before any row is written, and from the server's
           own route_stops times rather than the submitted label.

           Boarding-stop backfill: an empty payload used to save '' into
           booking_legs.boarding_stop, which made the ticket PDF fall
           back to routes.from_city (Surat) and routes.dep_time (13:00)
           for every passenger — including customers who searched for a
           later town like Ahmedabad. When the label arrives empty we
           reconstruct it from the server's own route_stops rows: an
           optional client-supplied `originTown` hint picks the right
           one; otherwise we default to the first still-open stop, so a
           ticket never says the wrong pickup. (SHG-2026-00056 fix.) */
        /* "Other" pickup / drop (owner ask, 17 Sep 2026) -------------
           Contract: boarding === self::BOARDING_OTHER ('__other__') AND
           boardingOther = the text the passenger typed (same pair for
           drop / dropOther). Only behind that sentinel is free text
           accepted: trimmed, cleaned to 120 chars, at least 3 characters
           (manualStop), and saved as plain text into the SAME
           booking_legs.boarding_stop / drop_stop columns a configured stop
           uses — so the ticket, manifest, chalani and CSV print it with no
           change. Without the sentinel the label takes exactly the path it
           always has, and boardingOther / dropOther are ignored. The
           cut-off for a manual pickup is judged by the town the passenger
           SEARCHED from (originTown → that stop's own time, the same stop
           the dropdown would have defaulted to), else by the route's
           departure — never later than the configured stop they could have
           picked instead. */
        $boardingIsOther = (string) ($request['boarding'] ?? '') === self::BOARDING_OTHER;
        $dropIsOther     = (string) ($request['drop'] ?? '') === self::BOARDING_OTHER;
        $originTown      = Security::clean($request['originTown'] ?? '', 80);
        if ($boardingIsOther) {
            $boardingStop = self::manualStop($request['boardingOther'] ?? '', 'boarding');
        } else {
            $boardingStop = Security::clean($request['boarding'] ?? '', 191);
            if ($boardingStop === '') {
                $boardingStop = self::defaultBoardingStop(
                    $routeId,
                    $travelDate,
                    $originTown,
                    (string) ($route['from_city'] ?? '')
                );
            }
        }
        $dropStop = $dropIsOther
            ? self::manualStop($request['dropOther'] ?? '', 'drop')
            : Security::clean($request['drop'] ?? '', 191);
        // A manual pickup has no time of its own: the searched town's configured
        // stop time (what the cut-off below is judged by) is stamped on the leg
        // so the ticket prints THAT pickup time, not the route origin's departure.
        // Null when the town matches no stop — the ticket then falls back as before.
        $boardingTime = ($boardingIsOther && $originTown !== '') ? self::configuredStopTime($routeId, $originTown) : null;
        $cutoff       = Boarding::status($routeId, $travelDate, ($boardingIsOther && $originTown !== '') ? $originTown : $boardingStop);
        // An extra bus with its own (later) departure sells until IT leaves —
        // the route's stop cut-offs describe the daily bus, not this one.
        if ($chosenSchedule !== null && (int) ($chosenSchedule['slot'] ?? 1) > 1 && !empty($chosenSchedule['dep_time_override'])) {
            $extraDep = strtotime($travelDate . ' ' . (string) $chosenSchedule['dep_time_override']);
            $cutoff   = [
                'open'   => $extraDep !== false && $extraDep > time(),
                'reason' => 'Booking closed — this extra bus has already departed. / बुकिङ बन्द छ — यो थप बस छुटिसक्यो।',
            ];
        }
        // A valid agent inside the 24h window may record a passenger whose
        // pickup has already passed (they actually boarded); the customer path
        // still refuses a gone pickup.
        if (!$cutoff['open'] && !$agentOverride && !$staffAnyDate) {
            throw new RuntimeException($cutoff['reason']);
        }

        $bookingMode = in_array($request['bookingMode'] ?? '', ['sharing', 'private'], true)
            ? (string) $request['bookingMode'] : null;
        $isCod = !empty($request['isCod']) && Settings::getBool('allow_cod', true) && $seller === null;

        /* ---- Agent-code resolution ---------------------------------
           $referralCodeClean / $soldByAdminId were resolved up front (above
           the departed/boarding gates, so an agent's code can open the 24h
           late-booking window). They are stamped onto bookings.sold_by_admin_id
           below so the agent's dashboard, wallet and commission engine
           (AgentWallet::accrue) pick this booking up like a walk-in counter
           sale. An invalid code resolved to null and never blocked checkout. */

        // Only a super-admin may sell a staff / emergency-reserved berth, and only
        // as a seller (the old counterSale() rule); customers never can.
        $allowStaffSeats = $seller !== null && Auth::isSuperadmin();

        /* ---- Everything below is atomic --------------------------- */
        $booking = Database::transaction(function () use (
            $route, $routeId, $travelDate, $scheduleIdReq, $seats, $passengers,
            $contact, $phone, $bookingMode, $isCod, $request, $token, $boardingStop, $dropStop, $boardingTime,
            $referralCodeClean, $soldByAdminId,
            $seller, $sellerId, $sellerSource, $counterMethod, $counterNote, $allowStaffSeats
        ): array {

            // The chosen departure: an extra bus by id, else the daily bus.
            $schedule   = $scheduleIdReq > 0 ? Seats::scheduleById($scheduleIdReq) : Seats::schedule($routeId, $travelDate);
            $scheduleId = (int) $schedule['id'];

            // Final availability check under the transaction. The booking mode
            // is passed so a mode-aware emergency berth (Private Sleeper -> L3)
            // is rejected for a normal customer; sharing/seater are unaffected.
            Seats::assertAvailable($scheduleId, $seats, $token, $allowStaffSeats, $bookingMode);

            // Gender-aware shared-cabin rule (Part 2 · Feature A), enforced in
            // this same transaction so online and agent bookings can never race
            // into a mixed cabin. Gender is aligned to $seats by index (create()
            // has already validated the two counts match). This writes each
            // cabin's lock state; if the claim below loses a race the whole
            // transaction — this write included — rolls back.
            $seatGenders = [];
            foreach ($seats as $i => $seatNo) {
                $seatGenders[$i] = $passengers[$i]['gender'] ?? null;
            }
            // The coach THIS departure runs — an extra bus may carry its own
            // layout (4 Sep 2026); identical to the route's type otherwise.
            $legCoach = Seats::effectiveCoach($schedule, $route);
            Seats::assertGenderAllowed($scheduleId, $legCoach, $bookingMode, $seats, $seatGenders);

            /* Women-only seats (4 Sep 2026): the operator's female_seats list
               (Admin → seat map) is now ENFORCED, not just painted — a male
               passenger cannot be booked on one by a customer. Sharing and
               seater only: a private cabin is the whole cabin. Counter staff
               may override (the brief's "staff override allowed"); a passenger
               who left gender blank is not blocked. */
            if ($seller === null && $bookingMode !== 'private') {
                $femPhys = Seats::toPhysical(Seats::femaleSeats($legCoach), 'sharing', $legCoach);
                if ($femPhys !== []) {
                    foreach ($seats as $i => $seatNo) {
                        if (($seatGenders[$i] ?? null) !== 'Male') {
                            continue;
                        }
                        if (array_intersect(Seats::physicalSeats((string) $seatNo, $bookingMode ?? 'sharing', $legCoach), $femPhys) !== []) {
                            $seatLbl = Seats::displayLabel((string) $seatNo, $legCoach, $bookingMode ?? 'sharing');
                            throw new RuntimeException(
                                'Seat ' . $seatLbl . ' is reserved for women — please choose another seat. / सिट ' . $seatLbl . ' महिलाका लागि आरक्षित छ।'
                            );
                        }
                    }
                }
            }

            /* ---- Price it (server-authoritative) ------------------ */
            /* 26 Sep 2026: the passenger's OWN boarding / drop stop and the
               travel date go in too. The point-to-point board prices a
               Nana Chiloda pickup differently from a Surat one, and the
               advance-booking offer is decided against this date and this
               bus's departure clock. */
            $pricing = self::priceBooking(
                $route, $seats, $bookingMode, $request, $phone, $schedule,
                $boardingStop, $dropStop, $travelDate
            );

            // Counter discount (flat or % off the pre-discount base), clamped to
            // the admin-set cap — the same rule the old counter form applied.
            // Stored in coupon_discount so booking-view / invoice show it
            // without any new column. Never negative, never above the payable.
            if ($seller !== null) {
                $discType = in_array($seller['discountType'] ?? '', ['flat', 'percent'], true) ? (string) $seller['discountType'] : null;
                $discVal  = max(0.0, (float) ($seller['discountValue'] ?? 0));
                if ($discType !== null && $discVal > 0 && (float) $pricing['base'] > 0) {
                    $raw    = $discType === 'percent' ? round((float) $pricing['base'] * $discVal / 100, 2) : round($discVal, 2);
                    $maxPct = max(0.0, Settings::getFloat('counter_max_discount_pct', 15.0));
                    $cap    = round((float) $pricing['base'] * $maxPct / 100, 2);
                    $cd     = min($raw, $cap, (float) $pricing['total']);
                    if ($cd > 0) {
                        $pricing['couponDiscount'] = round((float) $pricing['couponDiscount'] + $cd, 2);
                        $pricing['total']          = round((float) $pricing['total'] - $cd, 2);
                    }
                }
            }

            /* ---- Insert the booking header ------------------------ */
            $pnr    = nextTicketNo((string) $route['route_code']);
            $userId = Auth::user()['id'] ?? null;
            /* A session may outlive its users row (account deleted or
               merged by the office). Booking as a guest is right; failing
               every sale on the foreign key with a raw SQL error is not. */
            if ($userId !== null && !Database::exists('SELECT 1 FROM users WHERE id = :u', ['u' => (int) $userId])) {
                $userId = null;
            }

            /* Which country's dialing code the ticket WhatsApp/SMS must use. The
               10-digit number the customer gave is valid in both India and
               Nepal, so it carries no country of its own — we take the
               picker/prefix the checkout resolved into $contact['country'], and
               fall back to the country on their account when it was left blank.
               Stamped on the booking so Notify never has to guess (that guess
               was sending Nepali tickets to strangers in India). */
            $contactCountryCode = countryDialCode(resolvePhoneCountry(
                (string) ($contact['country'] ?? ''),
                (string) ($contact['phone'] ?? '')
            ));
            if ($contactCountryCode === '' && $userId !== null) {
                $accCc = preg_replace('/\D/', '', (string) Database::scalar(
                    'SELECT country_code FROM users WHERE id = :id',
                    ['id' => (int) $userId]
                )) ?? '';
                if ($accCc === '977' || $accCc === '91') {
                    $contactCountryCode = $accCc;
                }
            }

            $expiryMinutes = Settings::getInt('booking_expiry_minutes', 120);

            /* The advance-booking offer, in its own column when the migration
               has landed (see advanceColumn()). */
            $advanceCol = self::advanceColumn() && (float) ($pricing['advanceDiscount'] ?? 0) > 0
                ? ['advance_discount' => round((float) $pricing['advanceDiscount'], 2)]
                : [];

            $bookingId = Database::insert('bookings', $advanceCol + self::deskStamp($seller !== null ? $sellerId : null, (float) $pricing['total']) + [
                'pnr'             => $pnr,
                'user_id'         => $userId,
                'trip_type'       => !empty($request['returnLeg']) ? 'round' : 'oneway',
                'booking_mode'    => $bookingMode,
                'cabin_type'      => $request['cabinType'] ?? null,
                'sharing_tier'    => $request['sharingTier'] ?? null,
                'cabin_label'     => $pricing['cabinLabel'],
                'contact_phone'   => $phone,
                'contact_country_code' => $contactCountryCode !== '' ? $contactCountryCode : null,
                'contact_email'   => Security::email($contact['email'] ?? ''),
                'id_type'         => Security::clean($contact['idType'] ?? '', 60),
                'id_number'       => Security::clean($contact['idNum'] ?? '', 60),
                'fare_per_seat'   => $pricing['perSeat'],
                'base_total'      => $pricing['base'],
                'group_discount'  => $pricing['groupDiscount'],
                'tier_discount'   => $pricing['tierDiscount'],
                'tier_name'       => $pricing['tierName'],
                'coupon_code'     => $pricing['couponCode'],
                'coupon_discount' => $pricing['couponDiscount'],
                'points_used'     => $pricing['pointsUsed'],
                'points_value'    => $pricing['pointsValue'],
                'booking_fee'     => $pricing['fee'],
                'tax_amount'      => $pricing['tax'],
                'total_amount'    => $pricing['total'],
                'currency'        => 'INR',
                'referral_code'   => $referralCodeClean !== '' ? $referralCodeClean : null,
                // Stamp the counter agent when the typed SHG-NNN resolved. The
                // referral_code stays populated even when null-resolved so the
                // legacy Fare::referralCommission users.ref_code path can still
                // fire for SHGXXXX (letters) affiliate codes — the two engines
                // never overlap because resolveAgentCodeFromString only matches
                // the digit-suffix SHG-NNN pattern.
                'sold_by_admin_id'=> $soldByAdminId,
                'status'          => ($isCod || $seller !== null) ? 'confirmed' : 'pending',
                'confirmed_at'    => ($isCod || $seller !== null) ? date('Y-m-d H:i:s') : null,
                'is_cod'          => $isCod ? 1 : 0,
                'source'          => $sellerSource,
                'ip_address'      => Security::clientIp(),
                'user_agent'      => Security::userAgent(),
                // COD is confirmed on submit, so it never auto-expires; unpaid
                // UPI/eSewa bookings still get the pending-hold expiry window.
                'expires_at'      => ($isCod || $seller !== null) ? null : date('Y-m-d H:i:s', time() + $expiryMinutes * 60),
            ]);

            /* Redeem the coupon WITH the booking, in the same transaction.
               Fare::couponDiscount() checked usage_limit and per_user_limit
               against coupons.used_count and coupon_redemptions — and nothing
               ever wrote either, so a "first 50 bookings" or "once per
               customer" coupon was unlimited. */
            if ((string) ($pricing['couponCode'] ?? '') !== '' && (float) ($pricing['couponDiscount'] ?? 0) > 0) {
                $couponRow = Database::fetch('SELECT id FROM coupons WHERE code = :c LIMIT 1', ['c' => (string) $pricing['couponCode']]);
                if ($couponRow !== null) {
                    Database::run('UPDATE coupons SET used_count = used_count + 1 WHERE id = :id', ['id' => (int) $couponRow['id']]);
                    Database::insert('coupon_redemptions', [
                        'coupon_id'  => (int) $couponRow['id'],
                        'booking_id' => $bookingId,
                        'user_phone' => (string) Database::scalar('SELECT contact_phone FROM bookings WHERE id = :id', ['id' => $bookingId], ''),
                        'amount'     => round((float) $pricing['couponDiscount'], 2),
                    ]);
                }
            }

            /* ---- Outbound leg ------------------------------------- */
            $legRow = [
                'booking_id'    => $bookingId,
                'schedule_id'   => $scheduleId,
                'leg_type'      => 'outbound',
                'travel_date'   => $travelDate,
                // Exactly the value the cut-off above was checked against —
                // or the passenger's own "Other" text, resolved up there too.
                'boarding_stop' => $boardingStop,
                'drop_stop'     => $dropStop,
                'fare_per_seat' => $pricing['perSeat'],
                'seat_count'    => count($seats),
                'leg_total'     => $pricing['base'],
            ];
            if ($boardingTime !== null) {
                $legRow['boarding_time'] = $boardingTime;   // "Other" pickup only (see above)
            }
            $legId = Database::insert('booking_legs', $legRow);

            /* ---- Claim seats (throws on a lost race) -------------- */
            Seats::claim($scheduleId, $seats, $bookingId, $legId);

            /* ---- Passengers --------------------------------------- */
            foreach ($passengers as $index => $pax) {
                $seatNo = $seats[$index] ?? ($pax['seat'] ?? '');

                $paxRow = [
                    'booking_id'    => $bookingId,
                    'leg_id'        => $legId,
                    'passenger_ref' => generatePassengerRef(),
                    'seat_no'       => strtoupper(Security::clean($seatNo, 10)),
                    'full_name'     => Security::clean($pax['name'] ?? '', 120),
                    'age'           => isset($pax['age']) ? (int) $pax['age'] : null,
                    'gender'        => in_array($pax['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $pax['gender'] : null,
                    'is_primary'    => $index === 0 ? 1 : 0,
                ];
                /* Per-passenger document (17 Sep 2026): booking_passengers has
                   carried id_type / id_number / nationality since day one, but
                   only the admin edit form ever filled them — the manifest and
                   customer book showed blanks for every online/counter sale.
                   Each traveller's own document wins; the primary passenger
                   inherits the CONTACT's document when none was typed for them,
                   so the existing checkout (contact-level ID only) keeps
                   working with zero JS change. Blank stays NULL, not ''. */
                $paxRow['id_type']     = self::paxIdField($pax['idType'] ?? '', $index === 0 ? ($contact['idType'] ?? '') : '');
                $paxRow['id_number']   = self::paxIdField($pax['idNum'] ?? '', $index === 0 ? ($contact['idNum'] ?? '') : '');
                $paxRow['nationality'] = self::paxIdField($pax['nationality'] ?? '', '');
                // Patient / birami mode (4 Sep 2026): priority-boarding flag,
                // written only where the column exists (guarded migration
                // database/upgrade-2026-09-passenger-special.sql).
                $special = in_array($pax['special'] ?? '', ['patient', 'senior', 'pregnant'], true) ? (string) $pax['special'] : null;
                if ($special !== null && self::paxSpecialColumn()) {
                    $paxRow['special_need'] = $special;
                }
                Database::insert('booking_passengers', $paxRow);
            }

            /* ---- Payment record ----------------------------------- */
            $paymentMethod = self::normaliseMethod($request['paymentMethod'] ?? 'upi');

            Database::insert('payments', $seller !== null
                ? self::tenderStamp($sellerId, (float) $pricing['total']) + [ // counter sale: the money is already in hand -> verified now, by the seller
                    'booking_id'  => $bookingId,
                    'payment_ref' => generatePaymentRef(),
                    'method'      => $counterMethod,
                    'mode'        => 'offline',
                    'amount'      => $pricing['total'],
                    'currency'    => 'INR',
                    'payer_name'  => Security::clean((string) ($passengers[0]['name'] ?? ''), 120),
                    'payer_phone' => $phone,
                    'status'      => 'verified',
                    'verified_by' => $sellerId,
                    'verified_at' => date('Y-m-d H:i:s'),
                    'admin_note'  => $counterNote !== '' ? $counterNote : null,
                ]
                : [
                    'booking_id'  => $bookingId,
                    'payment_ref' => generatePaymentRef(),
                    'method'      => $isCod ? 'cod' : $paymentMethod,
                    'mode'        => $isCod ? 'offline' : 'utr',
                    'amount'      => $pricing['total'],
                    'currency'    => 'INR',
                    'payer_phone' => $phone,
                    'status'      => $isCod ? 'cod_pending' : 'pending',
                ]);

            /* ---- Release this visitor's holds --------------------- */
            Seats::releaseAll($token);

            /* ---- Spend loyalty points now (refunded if rejected) -- */
            if ($pricing['pointsUsed'] > 0 && $userId !== null) {
                self::adjustPoints((int) $userId, -$pricing['pointsUsed'], 'Redeemed at checkout ' . $pnr, $bookingId);
            }

            /* ---- Notify admin ------------------------------------- */
            self::notifyAdmin(
                '🎫',
                'New booking ' . $pnr,
                ($seller !== null ? 'Counter sale · CONFIRMED' : ($isCod ? 'Pay-at-counter · CONFIRMED' : 'Awaiting payment')) . ' · ' . count($seats) . ' seat(s) · ' . inr($pricing['total']),
                $bookingId
            );

            if ($seller !== null) {
                Logger::audit('booking.counter', 'booking', $pnr, null,
                    ['seats' => implode(',', $seats), 'amount' => $pricing['total'], 'source' => $sellerSource, 'method' => $counterMethod],
                    'counter sale (app) by admin #' . $sellerId . ($counterNote !== '' ? ' · ' . $counterNote : ''));
            } else {
                Logger::audit('booking.create', 'booking', $pnr, null, null, count($seats) . ' seats, ' . inr($pricing['total']) . ($isCod ? ' | COD auto-confirmed' : ''));
            }

            $booking = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
            if ($booking) {
                $booking['payment_method'] = $seller !== null ? $counterMethod : ($isCod ? 'cod' : $paymentMethod);
            }

            return $booking ?? [];
        });

        /* ---- Post-commit side effects -------------------------------
           Deliberately OUTSIDE the transaction above. Issuing the ticket and
           sending the WhatsApp/e-mail both reach outside this connection —
           Twilio fetches the ticket PDF back over a separate HTTP request,
           which cannot see rows that have not been committed yet, and holding
           a write transaction open across an outbound network call invites
           lock contention and timeouts. Neither may break a booking that is
           already safely stored, so both are individually guarded. */
        if ($booking !== []) {
            $isCodBooking = !empty($booking['is_cod']);
            $isCounter    = $seller !== null;

            /* Counter sale: paid + confirmed in one step, so the ticket is issued
               now and the seller's wallet is credited (idempotent, never throws)
               — exactly what counterSale() does for the paper-ticket register. */
            if ($isCounter) {
                try { Ticket::issue((int) $booking['id']); }
                catch (Throwable $e) { Logger::error('Ticket::issue (counter) failed', ['e' => $e->getMessage()]); }
                AgentWallet::accrue($booking);
            }

            /* COD does NOT queue for payment verification — it is confirmed on
               submit (see the insert above) so the ticket unlocks immediately.
               Cash is still collected by the crew before departure, so the
               payment row stays 'cod_pending' and loyalty / referral commission
               are intentionally NOT accrued here — those fire from settleCod()
               once the money is actually in hand. */
            if ($isCodBooking) {
                try { Ticket::issue((int) $booking['id']); }
                catch (Throwable $e) { Logger::error('Ticket::issue (COD) failed', ['e' => $e->getMessage()]); }
            }

            // All messaging fans out through the EventBus listeners
            // (includes/events.php): online → admin "verify" ping + customer
            // ack; COD → the confirmed-ticket delivery. emit() never throws.
            EventBus::emit('booking.created', ['booking' => $booking]);
            if ($isCodBooking || $isCounter) {
                EventBus::emit('booking.approved', ['booking' => $booking, 'via' => $isCounter ? 'counter' : 'cod_create']);
            }
        }

        return $booking;
    }

    /**
     * Sell seats across a counter — the agent / walk-in / phone path.
     *
     * Money has already changed hands by the time this runs, so the
     * booking is created CONFIRMED with a verified payment and the ticket
     * is issued immediately. It still goes through the same availability
     * and shared-cabin checks as an online booking, in the same kind of
     * transaction, so a counter sale can never double-book a berth or
     * break the gender rule.
     *
     * Since 3 Sep 2026 ONLY the offline paper-ticket register uses this: a
     * historical entry with a known collected amount, possibly no phone and
     * possibly a past date. Every LIVE counter sale — the seat map's one-seat
     * form and the app's counter mode — goes through create($request, $seller)
     * so customers, agents and admins share one booking path.
     *
     * @param array<string, mixed> $route  routes row
     * @param array<int, string>   $seats  one or more seat numbers
     * @param array<string, mixed> $data   name, phone, gender, amount, paymentMethod, note, passengers
     * @return array<string, mixed> the booking row
     */
    public static function counterSale(
        array $route,
        int $scheduleId,
        string $date,
        array $seats,
        array $data,
        int $adminId,
        string $source = 'counter'
    ): array {
        $seats = array_values(array_unique(array_map(
            static fn($s): string => strtoupper(trim((string) $s)),
            $seats
        )));
        if ($seats === []) {
            throw new RuntimeException('Choose at least one seat for the counter sale.');
        }
        // Staff cap (5 Sep 2026): the paper register is always a staff sale,
        // so it shares the counter bulk cap, not the public customer one.
        $maxSeatsCounter = self::maxSeatsFor(true);
        if (count($seats) > $maxSeatsCounter) {
            throw new RuntimeException('A single booking may hold at most ' . $maxSeatsCounter . ' seats.');
        }

        $name = Security::clean((string) ($data['name'] ?? ''), 120);
        if ($name === '') {
            throw new RuntimeException('Enter the passenger name for the counter booking.');
        }

        $phone  = normalisePhone((string) ($data['phone'] ?? ''));
        // Which country the counter clerk's typed number belongs to, so the
        // ticket WhatsApp is routed correctly (India and Nepal share 10-digit
        // mobiles). Taken from an explicit country field or a +91/+977 prefix
        // on what was typed; blank when neither, and the notifier defaults.
        $counterCountryCode = countryDialCode(resolvePhoneCountry(
            (string) ($data['country'] ?? ''),
            (string) ($data['phone'] ?? '')
        ));
        $gender = in_array($data['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? (string) $data['gender'] : null;
        // The coach THIS departure runs (4 Sep 2026) — an extra bus may carry
        // its own layout; the route's type for every ordinary row.
        $coach  = Seats::coachForSchedule($scheduleId);
        // Sleeper counter sales default to sharing so the gender rule applies.
        $bookingMode = $coach === 'sleeper' ? 'sharing' : null;

        /* One passenger per seat. A caller that knows every traveller passes
           them in; the seat map only knows the buyer, so the rest of the
           party is numbered after them rather than left blank on the manifest. */
        $passengers = [];
        $given      = is_array($data['passengers'] ?? null) ? $data['passengers'] : [];
        foreach ($seats as $i => $seatNo) {
            $p = $given[$i] ?? [];
            $passengers[] = [
                'seat'   => $seatNo,
                'name'   => Security::clean((string) ($p['name'] ?? ($i === 0 ? $name : $name . ' (' . ($i + 1) . ')')), 120),
                'age'    => isset($p['age']) && $p['age'] !== '' ? (int) $p['age'] : null,
                'gender' => in_array($p['gender'] ?? '', ['Male', 'Female', 'Other'], true)
                    ? (string) $p['gender']
                    : ($i === 0 ? $gender : null),
                // Per-passenger document (17 Sep 2026); the first traveller
                // inherits the register's contact-level idType/idNum.
                'idType'      => self::paxIdField($p['idType'] ?? '', $i === 0 ? ($data['idType'] ?? '') : ''),
                'idNum'       => self::paxIdField($p['idNum'] ?? '', $i === 0 ? ($data['idNum'] ?? '') : ''),
                'nationality' => self::paxIdField($p['nationality'] ?? '', ''),
            ];
        }

        /* Fare: what was actually collected when the caller knows it (the
           paper-ticket register does), otherwise the route's own price. */
        $perSeat = (float) $route['base_fare'];
        if ($coach === 'sleeper') {
            // Pass the destination so the sharing fare is priced by direction
            // — the authoritative rates in Fare::dirFares() are toNepal 2000
            // (Gujarat→Rupaidiha) / toIndia 1800 (return). Without $toCity,
            // cabinFare() defaulted to the toIndia rate, mispricing every
            // toNepal counter sale by ₹200 (M1).
            $cf = Fare::cabinFare('single', 'sharing', 1, false, (string) ($route['to_city'] ?? ''), 4, (string) ($route['from_city'] ?? ''));
            if (($cf['perPerson'] ?? 0) > 0) {
                $perSeat = (float) $cf['perPerson'];
            }
        }
        // A per-departure price set on the Bus Calendar (schedules.fare_override)
        // wins over the route / direction fare for this bus (4 Sep 2026).
        $schedOverride = self::scheduleFareOverride(
            Database::fetch('SELECT id, fare_override FROM schedules WHERE id = :id', ['id' => $scheduleId])
        );
        if ($schedOverride !== null) {
            $perSeat = $schedOverride;
        }
        // Base fare (pre-discount): a manual amount override wins, else the
        // route/cabin price × seat count.
        $base = isset($data['amount']) && (float) $data['amount'] > 0
            ? round((float) $data['amount'], 2)
            : round($perSeat * count($seats), 2);

        // Counter discount (Task 9): a flat ₹ amount OR a % of the base, entered
        // by the admin/agent, clamped to the admin-set maximum so agents cannot
        // discount without limit. Same flat/percent + cap shape as
        // Fare::couponDiscount(). // TODO: confirm max discount % with owner.
        $discType = in_array($data['discountType'] ?? '', ['flat', 'percent'], true) ? (string) $data['discountType'] : null;
        $discVal  = max(0.0, (float) ($data['discountValue'] ?? 0));
        $discount = 0.0;
        if ($discType !== null && $discVal > 0 && $base > 0) {
            $raw    = $discType === 'percent' ? round($base * $discVal / 100, 2) : round($discVal, 2);
            $maxPct = max(0.0, Settings::getFloat('counter_max_discount_pct', 15.0));
            $cap    = round($base * $maxPct / 100, 2);
            $discount = min($raw, $cap, $base);   // never negative, never above the cap or the fare
        }

        $total   = round($base - $discount, 2);
        $perSeat = round($total / count($seats), 2);   // post-discount, per seat

        $method = in_array($data['paymentMethod'] ?? '', ['cash', 'upi', 'esewa', 'bank'], true)
            ? (string) $data['paymentMethod'] : 'cash';
        $note = Security::clean((string) ($data['note'] ?? ''), 255);

        // Only a super-admin may sell a staff/emergency-reserved berth; every
        // other staff role (incl. ticketing agents) is refused by assertAvailable.
        $allowStaffSeats = Auth::isSuperadmin();

        // A counter agent may be restricted to certain routes and to a number
        // of sales per day. Checked here — the one choke point every counter
        // and agent sale passes through — and BEFORE the transaction opens, so
        // a refusal costs no lock and the agent gets a message telling them
        // what to do next. Unrestricted agents and non-agent staff pass
        // straight through.
        AgentWallet::assertMaySell($adminId, (int) $route['id']);

        /* "Other" pickup (owner ask, 17 Sep 2026): $data['boarding'] =
           self::BOARDING_OTHER plus $data['boardingOther'] = the typed text
           (the create() contract) stores that text verbatim; any other label
           is matched to a configured stop below exactly as before. Resolved
           here, outside the transaction, so a too-short text costs no lock. */
        $boardingOther = (string) ($data['boarding'] ?? '') === self::BOARDING_OTHER
            ? self::manualStop($data['boardingOther'] ?? '', 'boarding')
            : null;

        /* The closure reads $data for the boarding hint (boarding / originTown)
           it hands defaultBoardingStop() below, so $data MUST be in its use-list.
           It was not, and because `??` also swallows an undefined variable no
           warning ever showed: the hint was silently '' and every counter ticket
           took the first open stop instead of the caller's town (18 Sep 2026;
           tests/boarding-other-test.php case 5c). */
        $booking = Database::transaction(function () use (
            $route, $scheduleId, $date, $seats, $passengers, $name, $phone, $gender,
            $coach, $bookingMode, $perSeat, $base, $discount, $total, $method, $note, $adminId, $source, $allowStaffSeats,
            $counterCountryCode, $boardingOther, $data
        ): array {
            Seats::assertAvailable($scheduleId, $seats, 'admin-counter-' . $adminId, $allowStaffSeats, $bookingMode);
            Seats::assertGenderAllowed(
                $scheduleId, $coach, $bookingMode, $seats,
                array_map(static fn(array $p) => $p['gender'], $passengers)
            );

            $pnr = nextTicketNo((string) $route['route_code']);
            $bid = Database::insert('bookings', self::deskStamp($adminId ?: null, (float) $total) + [
                'pnr'           => $pnr,
                'trip_type'     => 'oneway',
                'booking_mode'  => $bookingMode,
                'contact_phone' => $phone !== '' ? $phone : '0000000000',
                'contact_country_code' => $counterCountryCode !== '' ? $counterCountryCode : null,
                'fare_per_seat' => $perSeat,
                'base_total'    => $base,
                // Counter discount is stored in coupon_discount so it flows,
                // with no extra plumbing, to the booking-view breakdown and the
                // invoice line-items. total_amount is the post-discount payable.
                'coupon_discount' => $discount,
                'total_amount'  => $total,
                'currency'      => 'INR',
                'status'        => 'confirmed',
                'confirmed_at'  => date('Y-m-d H:i:s'),
                'source'        => in_array($source, ['agent', 'counter', 'admin'], true) ? $source : 'counter',
                // Who sold it — powers the agent panel and the wallet ledger.
                // Older rows stay NULL (unattributed history).
                'sold_by_admin_id' => $adminId ?: null,
                'ip_address'    => Security::clientIp(),
            ]);

            /* The paper register deliberately keeps the DATE floor and the
               pickup cut-off lifted — it records a sale that already happened,
               often after the bus has gone. What it must NOT do is record one
               against a departure that is not running at all: this path had no
               trip-status gate whatsoever, and AgentWallet's caller even
               materialises the schedule row on its way in, so a ticket could be
               written onto a CANCELLED or per-date-OFF bus. */
            $schedRow = Database::fetch(
                'SELECT status, is_blocked FROM schedules WHERE id = :s LIMIT 1',
                ['s' => $scheduleId]
            );
            if ($schedRow !== null
                && ((string) ($schedRow['status'] ?? '') === 'cancelled' || (int) ($schedRow['is_blocked'] ?? 0) === 1)) {
                throw new RuntimeException('That departure is cancelled or switched off — it cannot take a ticket.');
            }

            /* boarding_stop was never written here, so a ticket issued from
               this path printed the ROUTE ORIGIN rather than the passenger's
               own pickup unless a caller patched the row afterwards. create()
               resolves it through this same helper. */
            $lid = Database::insert('booking_legs', [
                'booking_id'    => $bid,
                'schedule_id'   => $scheduleId,
                'leg_type'      => 'outbound',
                'travel_date'   => $date,
                'boarding_stop' => $boardingOther ?? self::defaultBoardingStop(
                    (int) $route['id'],
                    $date,
                    (string) ($data['boarding'] ?? $data['originTown'] ?? ''),
                    (string) ($route['from_city'] ?? '')
                ),
                'fare_per_seat' => $perSeat,
                'seat_count'    => count($seats),
                'leg_total'     => $total,
            ]);

            Seats::claim($scheduleId, $seats, $bid, $lid);

            foreach ($passengers as $i => $pax) {
                Database::insert('booking_passengers', [
                    'booking_id'    => $bid,
                    'leg_id'        => $lid,
                    'passenger_ref' => generatePassengerRef(),
                    'seat_no'       => $pax['seat'],
                    'full_name'     => $pax['name'],
                    'age'           => $pax['age'],
                    'gender'        => $pax['gender'],
                    'id_type'       => $pax['idType'] ?? null,
                    'id_number'     => $pax['idNum'] ?? null,
                    'nationality'   => $pax['nationality'] ?? null,
                    'is_primary'    => $i === 0 ? 1 : 0,
                ]);
            }

            Database::insert('payments', self::tenderStamp($adminId ?: null, (float) $total) + [
                'booking_id'  => $bid,
                'payment_ref' => generatePaymentRef(),
                'method'      => $method,
                'mode'        => 'offline',
                'amount'      => $total,
                'currency'    => 'INR',
                'payer_name'  => $name,
                'payer_phone' => $phone,
                'status'      => 'verified',
                'verified_by' => $adminId ?: null,
                'verified_at' => date('Y-m-d H:i:s'),
                'admin_note'  => $note !== '' ? $note : null,
            ]);

            // Best-effort ticket, like the COD path — never fail the sale on it.
            try { Ticket::issue($bid); }
            catch (Throwable $e) { Logger::error('Counter ticket issue failed', ['e' => $e->getMessage()]); }

            $booking = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bid]) ?? [];

            // Credit the seller's wallet. Never throws — see AgentWallet.
            AgentWallet::accrue($booking);

            Logger::audit('booking.counter', 'booking', $pnr, null,
                ['seats' => implode(',', $seats), 'amount' => $total, 'source' => $source],
                'counter sale by admin #' . $adminId . ($note !== '' ? ' · ' . $note : ''));

            return $booking;
        });

        // Post-commit (never inside the transaction — see create()): the
        // sale is paid + confirmed in one step, so 'approved' delivers the
        // customer's ticket (when a real phone was captured) and the
        // agent's commission WhatsApp.
        if ($booking !== []) {
            EventBus::emit('booking.created', ['booking' => $booking]);
            EventBus::emit('booking.approved', ['booking' => $booking, 'via' => 'counter']);
        }

        return $booking;
    }

    /**
     * Reschedule an existing booking's OUTBOUND leg to a DIFFERENT date on
     * the SAME ROUTE (change-date / change-trip), optionally onto different
     * seats — keeping the SAME booking / PNR / payment / commission. The
     * continuity-preserving alternative to cancel+rebook.
     *
     * v1 scope (deliberately narrow for safety): SAME ROUTE only, so the
     * direction + stopCount — and therefore the fare — are identical and no
     * money is recomputed, refunded or re-collected; the seat COUNT is fixed.
     * A different route / direction would change the fare and is out of scope
     * (use cancel + rebook). Every seat move goes through the transactional
     * Seats core (UNIQUE double-booking firewall + shared-cabin gender rule),
     * never a direct write; the signed ticket QR is re-minted so it validates
     * to the new trip at the gate.
     *
     * @param array<int,string> $newSeatNos seats on the new schedule (same count)
     * @param string $legType 'outbound' (default, every historic caller) or
     *                        'return' — which booking_legs row is moved. Added
     *                        17 Sep 2026 so a round trip's second leg can be
     *                        rescheduled once return legs are sold; the
     *                        default keeps today's behaviour byte-for-byte.
     * @return array{booking: array<string,mixed>, changed: bool}
     */
    public static function rebookLeg(int $bookingId, int $newScheduleId, array $newSeatNos, string $newDate, int $actorAdminId, string $reason = '', string $legType = 'outbound'): array
    {
        /* ---- Pre-transaction gates (no locks held) -------------------- */
        if (!in_array($legType, ['outbound', 'return'], true)) {
            throw new RuntimeException('Invalid leg type — choose outbound or return.');
        }
        $booking = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
        if ($booking === null) { throw new RuntimeException('Booking not found.'); }
        $status = (string) $booking['status'];
        if (!in_array($status, ['confirmed', 'pending'], true)) {
            throw new RuntimeException('Only a pending or confirmed booking can be rescheduled (this one is “' . $status . '”).');
        }

        // Agent scoping: an agent may only reschedule bookings they sold; a
        // manager / superadmin (scope null) may reschedule any.
        $scope = Auth::bookingScopeAdminId();
        if ($scope !== null && (int) ($booking['sold_by_admin_id'] ?? 0) !== $scope) {
            throw new RuntimeException('You can only reschedule bookings you sold.');
        }

        $leg = Database::fetch(
            'SELECT * FROM booking_legs WHERE booking_id = :b AND leg_type = :lt ORDER BY id LIMIT 1',
            ['b' => $bookingId, 'lt' => $legType]
        );
        if ($leg === null) { throw new RuntimeException('This booking has no ' . $legType . ' leg to move.'); }
        $legId         = (int) $leg['id'];
        $oldScheduleId = (int) $leg['schedule_id'];
        $oldDate       = (string) $leg['travel_date'];
        $seatCount     = (int) $leg['seat_count'];

        $newSeatNos = array_values(array_unique(array_map(
            static fn($s) => strtoupper(Security::clean((string) $s, 10)),
            $newSeatNos
        )));
        if ($newSeatNos === []) { throw new RuntimeException('Choose the new seat(s).'); }
        if (count($newSeatNos) !== $seatCount) {
            throw new RuntimeException('Pick exactly ' . $seatCount . ' seat(s) — the seat count cannot change on a reschedule.');
        }
        if (!Security::isValidDate($newDate)) { throw new RuntimeException('Invalid target date.'); }
        if ($newScheduleId === $oldScheduleId) {
            throw new RuntimeException('Choose a different date / trip — this is the current one.');
        }

        // Resolve the NEW schedule + route, and enforce SAME-ROUTE (fare identity).
        $newSchedule = Database::fetch(
            'SELECT s.id, s.route_id, s.travel_date,
                    COALESCE(s.coach_type_override, r.coach_type) AS coach_type
               FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :id',
            ['id' => $newScheduleId]
        );
        if ($newSchedule === null) { throw new RuntimeException('Target trip not found.'); }
        if ((string) $newSchedule['travel_date'] !== $newDate) {
            throw new RuntimeException('The chosen date does not match the target trip.');
        }
        $oldRouteId = (int) Database::scalar('SELECT route_id FROM schedules WHERE id = :id', ['id' => $oldScheduleId], 0);
        if ((int) $newSchedule['route_id'] !== $oldRouteId) {
            throw new RuntimeException('Reschedule is limited to the same route (a different route would change the fare — cancel + rebook instead).');
        }
        $coach       = (string) $newSchedule['coach_type'];
        $bookingMode = (string) ($booking['booking_mode'] ?? 'sharing');

        // Trip-state gates: can't move a departed source; target must be bookable.
        $srcGate = TripStatus::editableFor($oldScheduleId);
        if (!($srcGate['editable'] ?? false) && !Auth::isSuperadmin()) {
            throw new RuntimeException('This booking’s trip is ' . ($srcGate['label'] ?? 'closed') . ' — it can no longer be moved.');
        }
        $dstGate = TripStatus::editableFor($newScheduleId);
        if (!($dstGate['bookable'] ?? false) && !Auth::isSuperadmin()) {
            throw new RuntimeException('The target trip is ' . ($dstGate['label'] ?? 'not bookable') . '.');
        }

        $allowStaffSeats = Auth::isSuperadmin();

        return self::performLegMove(
            $bookingId, $legId, $oldScheduleId, $oldDate,
            $newScheduleId, $newDate, $newSeatNos, $coach, $bookingMode,
            $allowStaffSeats, $actorAdminId,
            'booking.reschedule',
            'rescheduled by admin #' . $actorAdminId,
            [],
            $reason
        );
    }

    /**
     * Hours after the ORIGINAL scheduled departure that a passenger who
     * missed their bus may still be moved onto a later same-route service
     * without a fresh sale (Point 11 — "missed bus" grace). Admin-tunable.
     */
    public const MISSED_GRACE_HOURS = 24;

    /**
     * "Missed bus" 24-hour grace rebooking (Point 11).
     *
     * A passenger who MISSES their booked departure keeps a usable ticket:
     * staff (or the selling agent) may move them onto the next available
     * same-route service within {@see MISSED_GRACE_HOURS} of the ORIGINAL
     * departure, instead of the ticket going to waste. Same PNR / fare /
     * payment / commission — it is a seat move, not a new sale.
     *
     * This is deliberately a SEPARATE method from rebookLeg() rather than a
     * relaxed flag on it: rebookLeg() refuses to move a booking whose source
     * trip has already departed (a live-trip safety guard). A missed bus HAS
     * departed — exactly the case rebookLeg blocks — so the missed-bus gate is
     * the inverse: the source trip must have departed AND we must still be
     * inside the grace window. Everything downstream (release → claim →
     * gender rule → ticket re-mint) reuses the identical transactional core.
     *
     * Who may call it: a manager / superadmin (any booking) OR the counter
     * AGENT who sold it (their own bookings only — same sold_by_admin_id
     * scoping rebookLeg already uses). Customers can never reach it.
     *
     * @param array<int,string> $newSeatNos seats on the new schedule (same count)
     * @param string $legType 'outbound' (default) or 'return' — see rebookLeg().
     * @return array{booking: array<string,mixed>, changed: bool}
     */
    public static function rebookMissedLeg(int $bookingId, int $newScheduleId, array $newSeatNos, string $newDate, int $actorAdminId, string $legType = 'outbound'): array
    {
        /* ---- Pre-transaction gates (no locks held) -------------------- */
        if (!in_array($legType, ['outbound', 'return'], true)) {
            throw new RuntimeException('Invalid leg type — choose outbound or return.');
        }
        $booking = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
        if ($booking === null) { throw new RuntimeException('Booking not found.'); }
        $pnr    = (string) $booking['pnr'];
        $status = (string) $booking['status'];
        if (!in_array($status, ['confirmed', 'pending'], true)) {
            throw new RuntimeException('Only a pending or confirmed booking can be moved (this one is “' . $status . '”).');
        }

        // Agent scoping: an agent may only rebook the bookings they sold; a
        // manager / superadmin (scope null) may rebook any. This is the exact
        // rule rebookLeg uses — it is what lets the SELLING AGENT record a late
        // missed-bus entry under their own login (Point 11's agent clause).
        $scope = Auth::bookingScopeAdminId();
        if ($scope !== null && (int) ($booking['sold_by_admin_id'] ?? 0) !== $scope) {
            throw new RuntimeException('You can only rebook the bookings you sold.');
        }

        $leg = Database::fetch(
            'SELECT * FROM booking_legs WHERE booking_id = :b AND leg_type = :lt ORDER BY id LIMIT 1',
            ['b' => $bookingId, 'lt' => $legType]
        );
        if ($leg === null) { throw new RuntimeException('This booking has no ' . $legType . ' leg to move.'); }
        $legId         = (int) $leg['id'];
        $oldScheduleId = (int) $leg['schedule_id'];
        $oldDate       = (string) $leg['travel_date'];
        $seatCount     = (int) $leg['seat_count'];

        $newSeatNos = array_values(array_unique(array_map(
            static fn($s) => strtoupper(Security::clean((string) $s, 10)),
            $newSeatNos
        )));
        if ($newSeatNos === []) { throw new RuntimeException('Choose the new seat(s).'); }
        if (count($newSeatNos) !== $seatCount) {
            throw new RuntimeException('Pick exactly ' . $seatCount . ' seat(s) — the seat count cannot change on a rebooking.');
        }
        if (!Security::isValidDate($newDate)) { throw new RuntimeException('Invalid target date.'); }
        if ($newScheduleId === $oldScheduleId) {
            throw new RuntimeException('Choose a different trip — this is the one they missed.');
        }

        // Resolve the NEW schedule + route, and enforce SAME-ROUTE (fare identity).
        $newSchedule = Database::fetch(
            'SELECT s.id, s.route_id, s.travel_date,
                    COALESCE(s.coach_type_override, r.coach_type) AS coach_type
               FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :id',
            ['id' => $newScheduleId]
        );
        if ($newSchedule === null) { throw new RuntimeException('Target trip not found.'); }
        if ((string) $newSchedule['travel_date'] !== $newDate) {
            throw new RuntimeException('The chosen date does not match the target trip.');
        }
        $oldRouteId = (int) Database::scalar('SELECT route_id FROM schedules WHERE id = :id', ['id' => $oldScheduleId], 0);
        if ((int) $newSchedule['route_id'] !== $oldRouteId) {
            throw new RuntimeException('A missed-bus rebooking stays on the same route (a different route changes the fare — cancel + rebook instead).');
        }
        $coach       = (string) $newSchedule['coach_type'];
        $bookingMode = (string) ($booking['booking_mode'] ?? 'sharing');

        /* ---- Missed-bus window gate (the inverse of rebookLeg's) ------
           The source (missed) trip must have DEPARTED, and NOW must still be
           within MISSED_GRACE_HOURS of the ORIGINAL scheduled departure. The
           original departure is the FIRST missed leg's time — read back from an
           earlier missed_rebook audit if this booking was already moved once,
           so a second miss does not silently reset the 24h clock. Superadmin
           bypasses the window for genuine edge cases. */
        $srcDep  = self::scheduledDepartureTs($oldScheduleId, $oldDate);
        $origDep = self::originalMissedDepartureTs($pnr) ?? $srcDep;
        if (!Auth::isSuperadmin()) {
            if ($srcDep === null) {
                throw new RuntimeException('Cannot determine the departure time of the missed trip.');
            }
            if (time() < $srcDep) {
                throw new RuntimeException('That bus has not departed yet — use Reschedule to change the date, not the missed-bus grace.');
            }
            if ($origDep !== null && time() > $origDep + self::MISSED_GRACE_HOURS * 3600) {
                throw new RuntimeException('The ' . self::MISSED_GRACE_HOURS . '-hour missed-bus window has passed (original departure ' . date('d M, H:i', $origDep) . '). Please cancel + rebook as a new sale.');
            }
        }

        // Target trip must still accept a counter booking (wider than the
        // online gate — the next service may already be boarding/running when a
        // late entry is made). Superadmin bypasses.
        $dstGate = TripStatus::editableFor($newScheduleId);
        if (!($dstGate['counterBookable'] ?? false) && !Auth::isSuperadmin()) {
            throw new RuntimeException('The target trip is ' . ($dstGate['label'] ?? 'not bookable') . ' and can no longer take a booking.');
        }

        $allowStaffSeats = Auth::isSuperadmin();
        $agentCode = AgentWallet::agentCodeLabel((int) ($booking['sold_by_admin_id'] ?? 0));

        return self::performLegMove(
            $bookingId, $legId, $oldScheduleId, $oldDate,
            $newScheduleId, $newDate, $newSeatNos, $coach, $bookingMode,
            $allowStaffSeats, $actorAdminId,
            'booking.missed_rebook',
            'missed-bus rebooking by admin #' . $actorAdminId
                . ($agentCode !== '' ? ' (agent ' . $agentCode . ')' : ''),
            [
                'reason'       => 'missed_bus',
                'agent_code'   => $agentCode,
                'original_dep' => $origDep !== null ? date('Y-m-d H:i:s', $origDep) : null,
                'missed_dep'   => $srcDep !== null ? date('Y-m-d H:i:s', $srcDep) : null,
            ]
        );
    }

    /**
     * UI helper (Point 11): is a booking eligible for a missed-bus rebooking
     * right now, and how much of the grace window is left? Keeps the window
     * math in one place so admin/missed-bus.php stays a thin view. Never
     * throws — returns a self-describing status array.
     *
     * @return array{ok:bool,reason:string,departed:bool,withinWindow:bool,
     *   srcDep:?int,origDep:?int,deadline:?int,remainingSec:int}
     */
    public static function missedGraceStatus(int $bookingId): array
    {
        $out = ['ok' => false, 'reason' => '', 'departed' => false, 'withinWindow' => false,
                'srcDep' => null, 'origDep' => null, 'deadline' => null, 'remainingSec' => 0];

        $booking = Database::fetch('SELECT id, pnr, status FROM bookings WHERE id = :id', ['id' => $bookingId]);
        if ($booking === null) { $out['reason'] = 'not_found'; return $out; }
        if (!in_array((string) $booking['status'], ['confirmed', 'pending'], true)) {
            $out['reason'] = 'bad_status'; return $out;
        }
        $leg = Database::fetch(
            "SELECT schedule_id, travel_date FROM booking_legs WHERE booking_id = :b AND leg_type='outbound' ORDER BY id LIMIT 1",
            ['b' => $bookingId]
        );
        if ($leg === null) { $out['reason'] = 'no_leg'; return $out; }

        $srcDep = self::scheduledDepartureTs((int) $leg['schedule_id'], (string) $leg['travel_date']);
        $origDep = self::originalMissedDepartureTs((string) $booking['pnr']) ?? $srcDep;
        $out['srcDep']  = $srcDep;
        $out['origDep'] = $origDep;
        if ($srcDep === null) { $out['reason'] = 'no_dep_time'; return $out; }

        $now = time();
        $out['departed'] = $now >= $srcDep;
        if ($origDep !== null) {
            $deadline = $origDep + self::MISSED_GRACE_HOURS * 3600;
            $out['deadline']     = $deadline;
            $out['withinWindow'] = $now <= $deadline;
            $out['remainingSec'] = max(0, $deadline - $now);
        }
        if (!$out['departed'])       { $out['reason'] = 'not_departed'; return $out; }
        if (!$out['withinWindow'])   { $out['reason'] = 'window_passed'; return $out; }
        $out['ok'] = true; $out['reason'] = 'eligible';
        return $out;
    }

    /**
     * Scheduled (effective) departure UNIX ts for a schedule on a given
     * travel date — a per-schedule dep_time_override wins over the route's
     * default, matching TripStatus. Null if the row or time can't be resolved.
     */
    private static function scheduledDepartureTs(int $scheduleId, string $travelDate): ?int
    {
        $row = Database::fetch(
            "SELECT COALESCE(s.dep_time_override, r.dep_time) AS dep_time
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.id = :id",
            ['id' => $scheduleId]
        );
        $time = (string) ($row['dep_time'] ?? '');
        if ($travelDate === '' || $time === '') { return null; }
        $ts = strtotime($travelDate . ' ' . $time);
        return $ts === false ? null : $ts;
    }

    /**
     * The ORIGINAL scheduled departure of a booking that has already been
     * missed-rebooked once — read from the FIRST booking.missed_rebook audit
     * row for this PNR so the 24h grace is always measured from the true first
     * departure, not from each successive rebooking. Null if never missed
     * before (the caller then uses the current leg's departure).
     */
    private static function originalMissedDepartureTs(string $pnr): ?int
    {
        try {
            $raw = Database::scalar(
                "SELECT new_value FROM audit_logs
                  WHERE action = 'booking.missed_rebook' AND entity_type = 'booking' AND entity_id = :p
                  ORDER BY id ASC LIMIT 1",
                ['p' => $pnr],
                null
            );
        } catch (Throwable $e) {
            return null;
        }
        if (!is_string($raw) || $raw === '') { return null; }
        $j = json_decode($raw, true);
        $orig = is_array($j) ? (string) ($j['original_dep'] ?? '') : '';
        if ($orig === '') { return null; }
        $ts = strtotime($orig);
        return $ts === false ? null : $ts;
    }

    /**
     * Shared transactional core for moving an outbound leg's seats from one
     * schedule/date to another on the SAME route. Both rebookLeg() (normal
     * date-change) and rebookMissedLeg() (24h missed-bus grace) funnel through
     * here so the double-booking firewall, shared-cabin gender rule and signed
     * ticket re-mint live in exactly ONE place. Callers own their own
     * pre-gates (status / scope / trip-state / window) and hand in an audit
     * descriptor. Assumes $newSeatNos is already cleaned + count-matched.
     *
     * @param array<int,string> $newSeatNos
     * @param array<string,mixed> $auditExtraNew merged into the audit new_value
     * @return array{booking: array<string,mixed>, changed: bool}
     */
    private static function performLegMove(
        int $bookingId, int $legId, int $oldScheduleId, string $oldDate,
        int $newScheduleId, string $newDate, array $newSeatNos,
        string $coach, string $bookingMode, bool $allowStaffSeats, int $actorAdminId,
        string $auditAction, string $auditDetail, array $auditExtraNew = [], string $reason = ''
    ): array {
        /* ---- Transaction: release old leg → claim new → reissue -------- */
        $result = Database::transaction(function () use (
            $bookingId, $legId, $oldScheduleId, $oldDate, $newScheduleId, $newDate,
            $newSeatNos, $coach, $bookingMode, $allowStaffSeats, $actorAdminId,
            $auditAction, $auditDetail, $auditExtraNew, $reason
        ): array {
            // Lock the booking row to serialise against confirm / cancel / settleCod.
            $locked = Database::fetchForUpdate('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId])[0] ?? null;
            if ($locked === null) { throw new RuntimeException('Booking not found.'); }

            // Re-read the leg's current seats + passengers INSIDE the txn — the
            // closure can be retried on deadlock, so never trust pre-txn reads.
            $oldSeats = pluck(Database::fetchAll(
                'SELECT seat_no FROM booking_seats WHERE booking_id = :b AND leg_id = :l ORDER BY seat_no',
                ['b' => $bookingId, 'l' => $legId]
            ), 'seat_no');
            $pax = Database::fetchAll(
                'SELECT id, gender FROM booking_passengers WHERE booking_id = :b AND leg_id = :l ORDER BY id',
                ['b' => $bookingId, 'l' => $legId]
            );
            if (count($pax) !== count($newSeatNos)) {
                throw new RuntimeException('Passenger / seat count mismatch — move aborted.');
            }
            $genders = array_map(static fn($p) => (string) ($p['gender'] ?? ''), $pax);

            // 1) Release the OLD leg's seats (leg-scoped — never booking-wide,
            //    so a round trip keeps its return seats).
            Seats::releaseLeg($bookingId, $legId);

            // 2) Validate + gender-gate the NEW seats on the NEW schedule.
            //    assertAvailable is the only enforcement of exists-on-coach /
            //    sold / blocked / held / staff; it MUST run before claim.
            Seats::assertAvailable($newScheduleId, $newSeatNos, 'reschedule-' . $actorAdminId, $allowStaffSeats, $bookingMode);
            Seats::assertGenderAllowed($newScheduleId, $coach, $bookingMode, $newSeatNos, $genders);

            // 3) Move the leg row — SAME leg id, fare untouched.
            Database::update('booking_legs', [
                'schedule_id' => $newScheduleId,
                'travel_date' => $newDate,
                'seat_count'  => count($newSeatNos),
            ], 'id = :id', ['id' => $legId]);

            // 4) Claim the NEW seats through the double-booking firewall.
            Seats::claim($newScheduleId, $newSeatNos, $bookingId, $legId);

            // 5) Re-point this leg's passengers onto the new seats, by index —
            //    critical so a later reject→re-approve reclaims the NEW seats.
            foreach ($pax as $i => $p) {
                Database::update('booking_passengers', ['seat_no' => $newSeatNos[$i]], 'id = :id', ['id' => (int) $p['id']]);
            }

            // 6) Re-mint the signed ticket QR + drop the cached PDF (guarded —
            //    a ticket hiccup must never roll back the move; the PDF self-
            //    heals on the next download regardless).
            try { Ticket::reissue($bookingId); }
            catch (Throwable $e) { Logger::error('Leg-move ticket reissue failed', ['e' => $e->getMessage()]); }

            $newAudit = [
                'schedule' => $newScheduleId, 'date' => $newDate, 'seats' => implode(',', $newSeatNos),
            ] + $auditExtraNew;
            Logger::audit($auditAction, 'booking', (string) $locked['pnr'],
                ['schedule' => $oldScheduleId, 'date' => $oldDate, 'seats' => implode(',', $oldSeats)],
                $newAudit,
                $auditDetail,
                $reason);

            $fresh = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]) ?? $locked;
            return ['booking' => $fresh, 'changed' => true];
        });

        // Post-commit: force-regenerate the cached PDF so the next download /
        // resend is already truthful. Outside the txn — the renderer reads
        // committed rows only. Guarded — never fail the move on it.
        try { Ticket::pdfPath($bookingId, true); }
        catch (Throwable $e) { Logger::error('Leg-move PDF regen failed', ['e' => $e->getMessage()]); }

        return $result;
    }

    /**
     * Attach a payment proof (UTR and/or screenshot) to a booking.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function submitPaymentProof(string $pnr, array $data): array
    {
        $booking = self::findByPnr($pnr);
        if ($booking === null) {
            throw new RuntimeException('Booking not found.');
        }

        /* Terminal states cannot take new proof. 'expired' and 'completed' are
           included deliberately: an expired booking's seats were released by
           the cron sweep and may already be resold, so accepting proof there
           would take money for a seat the passenger no longer holds. */
        if (in_array($booking['status'], ['cancelled', 'expired', 'completed'], true)) {
            throw new RuntimeException('This booking is no longer active. Please make a new booking.');
        }

        /* A REJECTED booking may re-submit proof (§3) — but only if that
           actually re-opens it. Rejection released the seats and the admin
           verify queue only lists 'pending', so without this the new proof
           would be written to a dead row and the customer would wait forever
           for a review that can never happen. Re-claim the seats first; if
           they were resold, Seats::claim throws a user-safe message rather
           than reviving a booking with no seats behind it. */
        if ($booking['status'] === 'rejected') {
            $bid = (int) $booking['id'];
            Database::transaction(static function () use ($bid, $booking): void {
                if (!Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b', ['b' => $bid])) {
                    self::reclaimSeatsFor($bid);
                }
                Database::update('bookings', [
                    'status'        => 'pending',
                    'cancel_reason' => null,
                    'expires_at'    => date('Y-m-d H:i:s', time() + Settings::getInt('booking_expiry_minutes', 120) * 60),
                ], 'id = :id', ['id' => $bid]);
                Logger::audit('booking.reopen', 'booking', (string) $booking['pnr'],
                    ['status' => 'rejected'], ['status' => 'pending'],
                    'Customer re-submitted payment proof');
            });
        }

        $payment = Database::fetch(
            'SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
            ['b' => $booking['id']]
        );
        if ($payment === null) {
            throw new RuntimeException('No payment record for this booking.');
        }

        /* Already paid: a second proof (a re-tap on the pay page, a screenshot
           sent twice, or a stranger who knows the PNR) used to write
           payments.status back to 'pending' on a CONFIRMED, verified booking,
           dropping a paid ticket into the verify queue and letting a reject
           there cancel it. Money that is in hand stays verified. */
        if (in_array((string) $payment['status'], ['verified', 'settled'], true)) {
            throw new RuntimeException('This booking is already paid and confirmed — no more proof is needed. / यो टिकटको भुक्तानी पहिले नै पुष्टि भइसक्यो।');
        }

        $utr        = Security::clean($data['utr'] ?? '', 60);
        $payerName  = Security::clean($data['payerName'] ?? '', 120);
        $method     = self::normaliseMethod($data['method'] ?? $payment['method']);

        Database::update('payments', [
            'utr_number' => $utr !== '' ? $utr : $payment['utr_number'],
            'payer_name' => $payerName !== '' ? $payerName : $payment['payer_name'],
            'method'     => $method,
            'mode'       => !empty($data['hasScreenshot']) ? 'both' : 'utr',
            'status'     => 'pending',
        ], 'id = :id', ['id' => $payment['id']]);

        /* The customer has paid and told us so -- from here the booking waits
           on the admin, not on them, so it must stop auto-expiring. Read the
           proof back from the database rather than trusting $data, so a blank
           submit cannot park a seat. */
        if (self::hasPaymentProof((int) $booking['id'])) {
            self::holdForAdminReview(
                (int) $booking['id'],
                $pnr,
                'Payment proof received - awaiting admin verification'
            );
        }

        /* 26 Sep 2026: a signed gateway webhook may already have reported
           this UTR. If so the office sees a "possible match" badge; the
           booking still waits for a person (includes/paywebhook.php). */
        if ($utr !== '') {
            try {
                require_once INCLUDE_PATH . '/paywebhook.php';
                PayWebhook::onProof((int) $booking['id'], $utr);
            } catch (Throwable $e) {
                Logger::exception($e);
            }
        }

        self::notifyAdmin('💳', 'Payment proof · ' . $pnr, 'UTR ' . ($utr ?: '—') . ' submitted for verification.', (int) $booking['id']);

        Logger::audit('payment.proof', 'booking', $pnr, null, null, 'UTR ' . $utr);

        // Outside any transaction here — the listener WhatsApps the admin
        // and reassures the customer (deduped hourly per booking, so the
        // UTR + screenshot pair from one checkout pings once).
        EventBus::emit('booking.payment_uploaded', ['booking' => $booking, 'kind' => $utr !== '' ? 'UTR' : 'screenshot']);

        return Database::fetch('SELECT * FROM payments WHERE id = :id', ['id' => $payment['id']]) ?? [];
    }

    /**
     * Store an uploaded screenshot against the latest payment.
     *
     * @param array<string, mixed> $file $_FILES entry
     */
    public static function attachScreenshot(string $pnr, array $file): array
    {
        $booking = self::findByPnr($pnr);
        if ($booking === null) {
            throw new RuntimeException('Booking not found.');
        }

        /* Refuse terminal bookings BEFORE anything touches the disk. This runs
           ahead of submitPaymentProof(), so without the check an upload for a
           dead PNR would already be written to uploads/ and recorded against
           the payment row before the request failed — orphan files plus a
           corrupted audit trail. 'rejected' is intentionally NOT terminal:
           re-submitting proof re-opens it (see submitPaymentProof). */
        if (in_array($booking['status'], ['cancelled', 'expired', 'completed'], true)) {
            throw new RuntimeException('This booking is no longer active. Please make a new booking.');
        }

        $check = Security::validateUpload($file);
        if (!$check['ok']) {
            throw new RuntimeException($check['error'] ?? 'Upload failed.');
        }

        $payment = Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $booking['id']]);
        if ($payment === null) {
            throw new RuntimeException('No payment record for this booking.');
        }

        $subDir = UPLOAD_PATH . '/' . date('Y') . '/' . date('m');
        ensureDir($subDir);

        $filename = Security::safeFilename((string) $check['ext']);
        $absolute = $subDir . '/' . $filename;
        $relative = date('Y') . '/' . date('m') . '/' . $filename;

        if (!move_uploaded_file((string) $file['tmp_name'], $absolute)) {
            throw new RuntimeException('Could not save the uploaded file.');
        }

        Database::insert('payment_screenshots', [
            'payment_id'    => $payment['id'],
            'booking_id'    => $booking['id'],
            'file_path'     => $relative,
            'original_name' => Security::clean($file['name'] ?? '', 191),
            'mime_type'     => (string) $check['mime'],
            'file_size'     => (int) $check['size'],
            'sha256'        => hash_file('sha256', $absolute) ?: null,
            'uploaded_ip'   => Security::clientIp(),
        ]);

        Database::update('payments', ['mode' => 'both'], 'id = :id', ['id' => $payment['id']]);

        /* A screenshot is proof by itself: customers often upload it and never
           come back to type the UTR. Without this the upload path left the
           expiry clock running and the sweep still took their seats. */
        self::holdForAdminReview(
            (int) $booking['id'],
            (string) $booking['pnr'],
            'Payment screenshot uploaded - awaiting admin verification'
        );

        // Screenshot-only uploads are the common case and used to reach no
        // admin channel at all. Deduped against the UTR ping (same hourly
        // claim key) so a UTR+screenshot checkout alerts once.
        EventBus::emit('booking.payment_uploaded', ['booking' => $booking, 'kind' => 'screenshot']);

        return ['file' => $relative];
    }

    /**
     * Take a booking off the unpaid auto-expiry clock once the customer has
     * actually sent proof of payment.
     *
     * A pending booking carries expires_at = now + booking_expiry_minutes so
     * an abandoned checkout eventually hands its seats back (cron/expire.php).
     * That clock guards against customers who never pay -- but it kept running
     * after they DID pay. Someone who transferred the fare and submitted a UTR
     * at minute 10 was still expired by the sweep at minute 120 if no admin had
     * looked yet: seats released and resellable, money already sent, and
     * 'expired' is terminal so their re-submitted proof was refused as well.
     *
     * Once real proof exists the booking is waiting on US, not on the customer,
     * so it must sit in pending until an admin accepts or rejects it. Clearing
     * expires_at -- status deliberately stays 'pending' -- is what does that.
     *
     * Deliberately NOT called for an empty submit: without the proof test any
     * visitor could park a seat forever by posting a blank UTR.
     */
    private static function holdForAdminReview(int $bookingId, string $pnr, string $why): void
    {
        $affected = Database::query(
            "UPDATE bookings
                SET expires_at = NULL
              WHERE id = :id AND status = 'pending' AND expires_at IS NOT NULL",
            ['id' => $bookingId]
        )->rowCount();

        if ($affected > 0) {
            Logger::audit('booking.await_review', 'booking', $pnr, null, null, $why);
        }
    }

    /** True when this booking already carries a UTR or an uploaded screenshot. */
    private static function hasPaymentProof(int $bookingId): bool
    {
        $utr = (string) Database::scalar(
            "SELECT COALESCE(utr_number, '') FROM payments
              WHERE booking_id = :b ORDER BY id DESC LIMIT 1",
            ['b' => $bookingId],
            ''
        );

        if (trim($utr) !== '') {
            return true;
        }

        return Database::exists(
            'SELECT 1 FROM payment_screenshots WHERE booking_id = :b LIMIT 1',
            ['b' => $bookingId]
        );
    }

    /**
     * Verify a payment and confirm the booking. Issues the ticket,
     * awards loyalty points and records referral commission.
     */
    public static function confirm(int $bookingId, int $adminId, string $note = ''): array
    {
        /* The closure returns {booking, changed} rather than the bare row:
           the post-commit emitter below must be able to tell a real
           pending→confirmed transition from the idempotent noop (double
           click / UI retry), or every re-click would WhatsApp the customer
           and agent again. */
        $result = Database::transaction(function () use ($bookingId, $adminId, $note): array {
            // Lock the row: a plain read takes the transaction's snapshot, so two
            // admins approving the same booking in the same instant would BOTH
            // see 'pending', both take the changed=true path and both emit
            // booking.approved (a duplicate customer/agent WhatsApp). FOR UPDATE
            // makes the loser block until the winner commits, then read
            // 'confirmed' and fall into the noop below — same guard markBoarded
            // already uses.
            $booking = Database::fetchForUpdate('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId])[0] ?? null;
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
            }
            $old = (string) $booking['status'];
            if ($old === 'confirmed') {
                /* Already in the target state — nothing changes, but record the
                   attempt so a re-submitted decision (double click, retry after
                   a UI freeze) still leaves a trace in the audit trail. */
                Logger::audit('booking.confirm.noop', 'booking', (string) $booking['pnr'],
                    ['status' => $old], ['status' => 'confirmed'], 'Already confirmed — no change applied');
                return ['booking' => $booking, 'changed' => false]; // idempotent
            }
            /* Only a pending or rejected booking may be approved. Never resurrect a
               cancelled booking here — cancellation carries its own refund/void state
               (refund_amount, cancelled_at, voided commission) this path does not
               reverse, so re-confirming it would pay the refund a second time (§4). */
            if (!in_array($old, ['pending', 'rejected'], true)) {
                throw new RuntimeException('Only a pending or rejected booking can be approved (this one is “' . $old . '”).');
            }

            /* Reversal safety (§4): if the seats were released (this booking was
               rejected/cancelled and is now being re-approved), re-claim them
               first. If a seat has been resold since, Seats::claim throws and
               the whole transition rolls back — no double-sell. */
            $hasSeats = Database::exists('SELECT 1 FROM booking_seats WHERE booking_id = :b', ['b' => $bookingId]);
            if (!$hasSeats) {
                self::reclaimSeatsFor($bookingId);
            }

            /* A COD booking owes cash on delivery. Approving it — or re-approving
               one a staffer mistakenly rejected — must leave its payment
               'cod_pending', NOT 'verified': marking uncollected cash as paid
               drops the fare off admin/payments.php's cash-owed queue, prints
               PAID on the ticket and (below) pays commission on money nobody
               has. settleCod() stays the single "cash in hand" verify step.
               Online payments are genuinely verified at this point. */
            $isCod = (int) ($booking['is_cod'] ?? 0) === 1;
            Database::update('payments', $isCod
                ? [
                    'status'      => 'cod_pending',
                    'verified_by' => null,
                    'verified_at' => null,
                    'admin_note'  => $note !== '' ? Security::clean($note, 255) : null,
                  ]
                : [
                    'status'      => 'verified',
                    'verified_by' => $adminId,
                    'verified_at' => date('Y-m-d H:i:s'),
                    'admin_note'  => $note !== '' ? Security::clean($note, 255) : null,
                  ], 'booking_id = :b', ['b' => $bookingId]);

            Database::update('bookings', [
                'status'        => 'confirmed',
                'confirmed_at'  => date('Y-m-d H:i:s'),
                'expires_at'    => null,
                'cancel_reason' => null,   // clear any prior rejection reason
            ], 'id = :id', ['id' => $bookingId]);

            // Issue the ticket now that payment is confirmed (idempotent — reuses
            // the existing ticket when re-approving a reversed booking).
            //
            // This MUST NOT be able to roll the confirmation back. It runs inside
            // this transaction, so an unguarded throw here — a ticket-number
            // collision on the UNIQUE key, a QR/signing hiccup, a transient DB
            // error — used to undo the entire approval: the payment stayed
            // unverified and the booking fell back to 'pending'. That is exactly
            // the "approved but shows FAIL / stuck on PENDING" report. The
            // confirmed booking is the valuable record; the ticket row is derived
            // and self-heals, because every read path (pdfPath/invoicePath) calls
            // issue() again on the next download.
            try { Ticket::issue($bookingId); }
            catch (Throwable $e) {
                Logger::error(
                    'Ticket::issue failed after confirm — booking stays CONFIRMED, ticket re-issues on first download',
                    ['booking' => $bookingId, 'pnr' => (string) ($booking['pnr'] ?? ''), 'e' => $e->getMessage()],
                    'ticket'
                );
            }

            self::reconcileRedeemedPoints($booking, true); // re-charge points a prior reject refunded (§4)

            // Money-in-hand rewards fire only when money is actually in hand.
            // For online payments that is now; for COD it is settleCod() (which
            // create() already defers them to). Accruing them here for a COD
            // booking would pay loyalty + referral + counter-agent commission
            // against cash that has not been collected.
            if (!$isCod) {
                self::awardLoyalty($booking);      // idempotent — one award per booking
                self::recordCommission($booking);  // idempotent — one referral commission per booking

                // Counter-agent wallet: commission (and cash held) for the staff
                // member who sold it. Idempotent via UNIQUE(booking_id, entry_type),
                // so re-approving a reversed booking cannot pay twice.
                AgentWallet::accrue($booking);
            }

            self::notifyUser($booking, '✅', 'Booking confirmed', 'Your ticket for ' . $booking['pnr'] . ' is ready to download.');

            Logger::audit('booking.confirm', 'booking', (string) $booking['pnr'], ['status' => $old], ['status' => 'confirmed'], $note);

            return [
                'booking' => Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]) ?? [],
                'changed' => true,
            ];
        });

        /* Post-commit: the customer's ticket WhatsApp/email and the selling
           agent's commission ping. Used to run INSIDE the transaction above —
           an outbound Twilio call while holding row locks, and the provider
           fetches the ticket PDF over a separate HTTP request that cannot
           see uncommitted rows (the exact trap create() documents). */
        $booking = $result['booking'] ?? [];
        if (!empty($result['changed']) && $booking !== []) {
            EventBus::emit('booking.approved', ['booking' => $booking, 'via' => 'admin']);
        }

        return $booking;
    }

    /**
     * Settle a cash-on-delivery booking once the crew or counter has the
     * money in hand.
     *
     * COD is confirmed the moment it is submitted so the passenger's ticket
     * unlocks immediately, but the payment row deliberately stays
     * 'cod_pending' — the fare is still owed. Nothing moved it off that state,
     * so a COD sale could never be reconciled and the loyalty points, referral
     * commission and counter-agent wallet entry that create() defers ("those
     * fire on the verify path once real money is in hand") were never posted.
     * This is that verify path for cash.
     *
     * Idempotent: a payment already verified is returned untouched, so a
     * double-click at the counter cannot pay a commission twice.
     */
    public static function settleCod(int $bookingId, int $adminId, string $note = ''): array
    {
        // {booking, changed} for the same reason as confirm(): the double-
        // click noop (payment already verified) must not re-message anyone.
        $result = Database::transaction(function () use ($bookingId, $adminId, $note): array {
            // Lock the row so two counter clicks cannot both pass the
            // "already verified" guard and both emit booking.cod_settled.
            $booking = Database::fetchForUpdate('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId])[0] ?? null;
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
            }
            if ((int) $booking['is_cod'] !== 1) {
                throw new RuntimeException('This is not a cash-on-delivery booking.');
            }
            if (in_array($booking['status'], ['cancelled', 'rejected'], true)) {
                throw new RuntimeException('This booking is no longer active, so no cash is due on it.');
            }

            $payment = Database::fetch(
                'SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
                ['b' => $bookingId]
            );
            if ($payment === null) {
                throw new RuntimeException('No payment record for this booking.');
            }
            if ((string) $payment['status'] === 'verified') {
                return ['booking' => $booking, 'changed' => false]; // already settled
            }

            Database::update('payments', [
                'status'      => 'verified',
                'method'      => 'cod',
                'mode'        => 'offline',
                'verified_by' => $adminId,
                'verified_at' => date('Y-m-d H:i:s'),
                'admin_note'  => $note !== '' ? Security::clean($note, 255) : 'Cash collected',
            ], 'id = :id', ['id' => $payment['id']]);

            /* The cached ticket PDF still carries the CASH-DUE band; drop the
               cache so the next download regenerates it saying PAID. */
            if (defined('TICKET_PATH')) {
                $stale = TICKET_PATH . '/ticket_' . $booking['pnr'] . '.pdf';
                if (is_file($stale)) {
                    @unlink($stale);
                }
                /* The PNG is the primary ticket (5 Sep 2026) and carries the
                   same CASH-DUE pill; the codSettled WhatsApp promises it "now
                   shows PAID". Until 10 Sep the PNG cache was NOT dropped here,
                   so the promise was false. Ticket::pngPath() redraws on next open. */
                $stalePng = TICKET_PATH . '/ticket_' . $booking['pnr'] . '.png';
                if (is_file($stalePng)) {
                    @unlink($stalePng);
                }
            }
            Database::update('tickets', ['pdf_path' => '', 'png_path' => ''], 'booking_id = :b', ['b' => $bookingId]);

            // A COD booking is normally already confirmed; a counter sale that
            // was somehow left pending is confirmed here so the ticket issues.
            if ((string) $booking['status'] !== 'confirmed') {
                Database::update('bookings', [
                    'status'       => 'confirmed',
                    'confirmed_at' => date('Y-m-d H:i:s'),
                    'expires_at'   => null,
                ], 'id = :id', ['id' => $bookingId]);
                $booking['status'] = 'confirmed';
            }

            // Same rule as confirm(): cash is already in hand here, so a ticket
            // hiccup must never roll back the settlement and lose the payment.
            try { Ticket::issue($bookingId); }   // idempotent — reuses an issued ticket
            catch (Throwable $e) {
                Logger::error(
                    'Ticket::issue failed after COD settle — payment stays VERIFIED, ticket re-issues on first download',
                    ['booking' => $bookingId, 'pnr' => (string) ($booking['pnr'] ?? ''), 'e' => $e->getMessage()],
                    'ticket'
                );
            }
            self::awardLoyalty($booking);       // idempotent — one award per booking
            self::recordCommission($booking);   // idempotent — one referral commission
            AgentWallet::accrue($booking);      // idempotent via UNIQUE(booking_id, entry_type)

            Logger::audit('booking.cod.settle', 'booking', (string) $booking['pnr'],
                ['payment' => $payment['status']], ['payment' => 'verified'],
                'Cash collected ' . inr((float) $booking['total_amount']) . ($note !== '' ? ' · ' . $note : ''));

            return [
                'booking' => Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]) ?? [],
                'changed' => true,
            ];
        });

        // Post-commit: tell the customer their ticket now reads PAID and the
        // selling agent that the cash was settled against their wallet.
        $booking = $result['booking'] ?? [];
        if (!empty($result['changed']) && $booking !== []) {
            EventBus::emit('booking.cod_settled', ['booking' => $booking]);
        }

        return $booking;
    }

    /**
     * Reject a payment. Releases the seats so they sell again.
     */
    public static function reject(int $bookingId, int $adminId, string $reason): array
    {
        // {booking, changed} so the post-commit emitter skips the noop path.
        $result = Database::transaction(function () use ($bookingId, $adminId, $reason): array {
            // Lock the row so a double-reject cannot double-emit booking.rejected.
            $booking = Database::fetchForUpdate('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId])[0] ?? null;
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
            }
            $old = (string) $booking['status'];
            if ($old === 'rejected') {
                Logger::audit('booking.reject.noop', 'booking', (string) $booking['pnr'],
                    ['status' => $old], ['status' => 'rejected'], 'Already rejected — no change applied');
                return ['booking' => $booking, 'changed' => false]; // idempotent
            }
            if (!in_array($old, ['pending', 'confirmed'], true)) {
                throw new RuntimeException('Only a pending or confirmed booking can be rejected (this one is “' . $old . '”).');
            }

            Database::update('payments', [
                'status'        => 'rejected',
                'verified_by'   => $adminId,
                'verified_at'   => date('Y-m-d H:i:s'),
                'reject_reason' => Security::clean($reason, 255),
            ], 'booking_id = :b', ['b' => $bookingId]);

            Database::update('bookings', [
                'status'        => 'rejected',
                'cancel_reason' => Security::clean($reason, 255),
            ], 'id = :id', ['id' => $bookingId]);

            Seats::releaseBooking($bookingId);
            self::reconcileRedeemedPoints($booking, false); // refund redeemed points, reversibly (§4)
            AgentWallet::voidFor($booking, 'payment rejected'); // take the counter agent's commission back

            /* Void the referral-agent commissions row too (master-prompt §5).
               Symmetric with cancel() at line 1317. Without this, a rejected
               booking left a `commissions` row payable forever — permanent
               drift from bookings.status. Uses the same UPDATE-in-place the
               existing cancel path uses so the audit trail (added_at, status)
               stays consistent across the two engines. */
            Database::update(
                'commissions',
                ['status' => 'void'],
                'booking_id = :b AND status IN ("pending","confirmed")',
                ['b' => $bookingId]
            );

            self::notifyUser($booking, '❌', 'Payment not verified', 'Booking ' . $booking['pnr'] . ': ' . $reason);

            /* §4 safe slice — what reversing a CONFIRMED booking does
               automatically (above): releases the seats, re-locks the customer's
               ticket, refunds their redeemed loyalty points, reverses the
               counter agent's wallet commission (restored if re-approved), and
               voids the referral commission. The already-issued ticket PDF is
               still a document decision for a human, so flag that. */
            if ($old === 'confirmed') {
                self::notifyAdmin('↩️', 'Confirmed booking reversed · ' . $booking['pnr'],
                    'Seats released, ticket re-locked, redeemed points refunded, counter-agent commission reversed and referral commission voided automatically. '
                    . 'Still to check by hand: the issued ticket PDF.',
                    $bookingId);
            }

            Logger::audit('booking.reject', 'booking', (string) $booking['pnr'], ['status' => $old], ['status' => 'rejected'], $reason);

            return [
                'booking' => Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]) ?? [],
                'changed' => true,
            ];
        });

        // Post-commit: customer "re-submit your proof" message + agent copy.
        // (Was a Twilio call inside the open transaction before.)
        $booking = $result['booking'] ?? [];
        if (!empty($result['changed']) && $booking !== []) {
            EventBus::emit('booking.rejected', ['booking' => $booking, 'reason' => $reason]);
        }

        return $booking;
    }

    /**
     * Re-claim the seats for a booking whose seats were previously released
     * (e.g. a rejected booking now being re-approved). Throws if any seat has
     * since been resold, which rolls the caller's transaction back.
     */
    private static function reclaimSeatsFor(int $bookingId): void
    {
        $legs = Database::fetchAll('SELECT id, schedule_id FROM booking_legs WHERE booking_id = :b', ['b' => $bookingId]);
        foreach ($legs as $leg) {
            $rows  = Database::fetchAll(
                'SELECT seat_no FROM booking_passengers WHERE booking_id = :b AND leg_id = :l',
                ['b' => $bookingId, 'l' => (int) $leg['id']]
            );
            $seats = array_values(array_filter(
                array_map(static fn($r) => (string) $r['seat_no'], $rows),
                static fn($s) => $s !== ''
            ));
            if ($seats !== []) {
                $sid = (int) $leg['schedule_id'];

                /* Re-claiming is a SALE, so it goes through the same gate as
                   one. This used to call claim() directly, whose only guard is
                   booking_seats' UNIQUE(schedule_id, seat_no) — a LABEL-space
                   key that by construction cannot see a cross-mode clash, since
                   private cabin "L4" and sharing "L7" are different rows. A
                   rejected PRIVATE booking whose beds had meanwhile been resold
                   in sharing mode was therefore re-claimed cleanly, leaving two
                   live bookings on the same physical beds. Reachable from the
                   customer's own "resubmit payment proof" path and from admin
                   re-approval, i.e. by the passenger, not just by staff.
                   assertAvailable() also brings the FOR UPDATE serialisation,
                   the blocked-seat and staff-berth checks and the
                   exists-on-this-coach check, none of which claim() has. */
                $mode = (string) Database::scalar(
                    'SELECT booking_mode FROM bookings WHERE id = :b',
                    ['b' => $bookingId],
                    'sharing'
                );
                Seats::assertAvailable($sid, $seats, 'reclaim-' . $bookingId, false, $mode !== '' ? $mode : 'sharing');

                Seats::claim($sid, $seats, $bookingId, (int) $leg['id']);

                // Restore the shared-cabin gender locks now these passengers
                // occupy their cabins again (Part 2 · Feature A).
                $coach = (string) Database::scalar(
                    'SELECT r.coach_type FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :s',
                    ['s' => $sid],
                    'sleeper'
                );
                $units = [];
                foreach ($seats as $seatNo) {
                    // $mode is this booking's own namespace (resolved above).
                    $units[Seats::unitKey($seatNo, $coach, $mode !== '' ? $mode : 'sharing')] = true;
                }
                foreach (array_keys($units) as $unitKey) {
                    Seats::recomputeUnitLock($sid, $unitKey, $coach);
                }
            }
        }
    }

    /* =================================================================
     *  Bulk cancel (4 Sep 2026)
     *
     *  Cancels many bookings in one action from the tickets register. Each
     *  PNR goes through the SAME cancel() path as a single cancel (own
     *  transaction, seat release, refund slab, commission void, WhatsApp),
     *  so a partial failure never leaves half a booking cancelled — the
     *  ones that failed are reported back by PNR and the rest stand.
     *
     *  Authorisation is per booking: Auth::mayCancelBooking() — an agent
     *  may only touch tickets they sold (sold_by_admin_id = self); a role
     *  holding bookings.cancel may touch any. A booking the actor may not
     *  cancel is skipped and reported, never cancelled.
     *
     *  One summary audit row (booking.bulk_cancel: who, how many, which
     *  PNRs, the reason) on top of the per-booking booking.cancel rows.
     * ================================================================= */

    public const BULK_CANCEL_MAX = 100;

    /**
     * @param array<int,int|string> $bookingIds bookings.id values (ints)
     * @return array{
     *   requested:int, cancelled:int, failed:int, skipped:int,
     *   results: array<int, array{id:int, pnr:string, ok:bool, error?:string, refund?:float}>
     * }
     */
    public static function bulkCancel(array $bookingIds, string $reason, int $actorAdminId): array
    {
        $ids = [];
        foreach ($bookingIds as $v) {
            $n = (int) $v;
            if ($n > 0) { $ids[$n] = $n; }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            throw new RuntimeException('Select at least one ticket to cancel.');
        }
        if (count($ids) > self::BULK_CANCEL_MAX) {
            throw new RuntimeException('You can cancel at most ' . self::BULK_CANCEL_MAX . ' tickets in one go.');
        }
        $reason = Security::clean($reason, 255);
        if ($reason === '') {
            $reason = 'Cancelled by staff (bulk)';
        }

        $in   = implode(',', $ids);   // ints only — safe to inline
        $rows = Database::fetchAll(
            'SELECT id, pnr, status, sold_by_admin_id, total_amount FROM bookings WHERE id IN (' . $in . ')'
        );
        $byId = [];
        foreach ($rows as $r) { $byId[(int) $r['id']] = $r; }

        $results   = [];
        $cancelled = 0;
        $failed    = 0;
        $skipped   = 0;
        $donePnrs  = [];
        $skipPnrs  = [];

        foreach ($ids as $id) {
            $b = $byId[$id] ?? null;
            if ($b === null) {
                $results[] = ['id' => $id, 'pnr' => '', 'ok' => false, 'error' => 'Booking not found.'];
                $failed++;
                continue;
            }
            $pnr = (string) $b['pnr'];

            if (!Auth::mayCancelBooking($b)) {
                $results[] = ['id' => $id, 'pnr' => $pnr, 'ok' => false, 'error' => 'Not your ticket — only the seller or the office can cancel it.'];
                $skipped++;
                $skipPnrs[] = $pnr;
                continue;
            }
            if (!in_array((string) $b['status'], ['pending', 'confirmed'], true)) {
                $results[] = ['id' => $id, 'pnr' => $pnr, 'ok' => false, 'error' => 'Already ' . (string) $b['status'] . '.'];
                $skipped++;
                continue;
            }

            try {
                $res = self::cancel($pnr, $reason, false);
                $results[] = ['id' => $id, 'pnr' => $pnr, 'ok' => true, 'refund' => (float) ($res['refund']['amount'] ?? 0)];
                $cancelled++;
                $donePnrs[] = $pnr;
            } catch (Throwable $e) {
                $results[] = ['id' => $id, 'pnr' => $pnr, 'ok' => false, 'error' => $e->getMessage()];
                $failed++;
                Logger::warning('Bulk cancel: one PNR failed', ['pnr' => $pnr, 'err' => $e->getMessage(), 'by' => $actorAdminId]);
            }
        }

        Logger::audit(
            'booking.bulk_cancel',
            'booking',
            'bulk:' . count($ids),
            null,
            [
                'requested' => count($ids),
                'cancelled' => $cancelled,
                'failed'    => $failed,
                'skipped'   => $skipped,
                'pnrs'      => $donePnrs,
                'refused'   => $skipPnrs,
                'reason'    => $reason,
            ],
            'Bulk cancel by admin #' . $actorAdminId . ': ' . $cancelled . ' of ' . count($ids) . ' cancelled — ' . $reason
        );

        return [
            'requested' => count($ids),
            'cancelled' => $cancelled,
            'failed'    => $failed,
            'skipped'   => $skipped,
            'results'   => $results,
        ];
    }

    /**
     * Cancel a confirmed or pending booking and compute the refund.
     *
     * @param string $via '' for a normal cancel; 'undo' when a passenger undid a
     *                    QuickBot one-tap ticket inside its free window
     *                    (QuickTicket::customerUndo) — same release, same
     *                    commission void, but the office bell says "undone"
     *                    and the fan-out sends the short undo copy instead of
     *                    the "CANCELLED · refund ₹0" alerts (events.php).
     */
    public static function cancel(string $pnr, string $reason = '', bool $byCustomer = true, string $via = ''): array
    {
        $result = Database::transaction(function () use ($pnr, $reason, $byCustomer, $via): array {
            // Lock the row so two concurrent cancels cannot both pass the
            // already-cancelled guard and double-emit / double-void.
            $booking = Database::fetchForUpdate('SELECT * FROM bookings WHERE pnr = :p', ['p' => $pnr])[0] ?? null;
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
            }
            if (in_array($booking['status'], ['cancelled', 'rejected'], true)) {
                throw new RuntimeException('This booking is already cancelled.');
            }

            // Refund only applies to money actually COLLECTED. A COD booking is
            // 'confirmed' the moment it is placed (so the ticket unlocks), but
            // the cash is not in hand until settleCod() marks its payment
            // 'verified'. Gating the slab refund on a genuinely verified payment
            // — not merely on the confirmed status — stops a cancel from parking
            // a payable refund for money that never arrived (M2). Online
            // bookings set payment 'verified' at confirm(), so they are
            // unaffected; a COD booking whose cash was later collected
            // (settleCod → payment 'verified') still refunds correctly.
            $refund = ['amount' => 0.0, 'percent' => 0.0, 'reason' => 'No payment had been collected.'];

            $paymentCollected = Database::exists(
                "SELECT 1 FROM payments WHERE booking_id = :b AND status = 'verified'",
                ['b' => $booking['id']]
            );

            if ($booking['status'] === 'confirmed' && $paymentCollected) {
                $leg = Database::fetch(
                    'SELECT l.travel_date, r.dep_time
                       FROM booking_legs l JOIN schedules s ON s.id = l.schedule_id
                       JOIN routes r ON r.id = s.route_id
                      WHERE l.booking_id = :b AND l.leg_type = \'outbound\' LIMIT 1',
                    ['b' => $booking['id']]
                );

                $refund = Fare::refundFor(
                    (float) $booking['total_amount'],
                    (string) ($leg['travel_date'] ?? $booking['created_at']),
                    (string) ($leg['dep_time'] ?? '00:00:00')
                );
            }

            Database::update('bookings', [
                'status'        => 'cancelled',
                'cancel_reason' => Security::clean($reason, 255),
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'refund_amount' => $refund['amount'],
                'refund_status' => $refund['amount'] > 0 ? 'pending' : 'none',
            ], 'id = :id', ['id' => $booking['id']]);

            Seats::releaseBooking((int) $booking['id']);
            /* Cancel is terminal: return whatever redeemed points are still
               spent. The delta-based reconciler is used (not a flat refund)
               because a prior reject/re-approve cycle (§4) may already have
               refunded and re-charged these points — it posts only what is
               actually outstanding, so it can neither double-refund nor
               silently skip a refund that is still owed. */
            self::reconcileRedeemedPoints($booking, false);

            // Void any commission that was booked for this PNR — the
            // referral agent's row, and the counter agent's wallet entry.
            Database::update('commissions', ['status' => 'void'], 'booking_id = :b', ['b' => $booking['id']]);
            AgentWallet::voidFor($booking, $reason !== '' ? $reason : 'booking cancelled');

            $actor = $byCustomer ? 'customer' : 'admin';
            if ($via === 'undo' && $refund['amount'] <= 0) {
                self::notifyAdmin('↩️', 'Ticket undone · ' . $pnr, 'The passenger undid this QuickBot ticket inside the free window — seat released, nothing to refund.', (int) $booking['id']);
            } else {
                self::notifyAdmin('🚫', 'Booking cancelled · ' . $pnr, ucfirst($actor) . ' cancelled. Refund due: ' . inr($refund['amount']), (int) $booking['id']);
            }

            Logger::audit('booking.cancel', 'booking', $pnr, null, null, $reason . ' | refund ' . inr($refund['amount']));

            return [
                'booking' => Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $booking['id']]),
                'refund'  => $refund,
            ];
        });

        // Post-commit: customer + admin WhatsApp with the refund figures and
        // the selling agent's "commission reversed" copy. (The customer
        // message used to be a Twilio call inside the transaction.)
        if (!empty($result['booking'])) {
            EventBus::emit('booking.cancelled', [
                'booking' => $result['booking'],
                'refund'  => $result['refund'] ?? [],
                'via'     => $via,
            ]);
        }

        return $result;
    }

    /**
     * How many seats one booking may hold. THE definition.
     *
     * A desk (counter mode, seat-map walk-in, agent, QuickBot) may hold up to
     * counter_max_seats_per_booking — a party travelling under one name;
     * anonymous customers keep the public max_seats_per_booking.
     *
     * This existed four times on three different mode predicates, and the
     * fourth copy contradicted the others: admin/quick-ticket.php wrote
     * min(10, ...), a hard-coded ceiling that is not a setting, so with the
     * shipped default of 20 the desk rendered only chips 1-10 and a party of
     * twelve could not be sold from the screen built for exactly that.
     */
    public static function maxSeatsFor(bool $isStaff): int
    {
        return max(1, $isStaff
            ? Settings::getInt('counter_max_seats_per_booking', 20)
            : Settings::getInt('max_seats_per_booking', MAX_SEATS_BOOKING));
    }

    /**
     * Superadmin force-cancel: terminal, seats released, NO refund slab.
     *
     * This lived inline in admin/booking-view.php, where it had drifted from
     * cancel() in three ways that all cost the passenger or the office:
     *   · no fetchForUpdate row lock and no already-cancelled guard, so two
     *     clicks (or a click racing a customer cancel) both ran;
     *   · no reconcileRedeemedPoints(), so loyalty points redeemed at checkout
     *     stayed spent forever — the passenger paid points for a trip the
     *     office cancelled;
     *   · no EventBus::emit('booking.cancelled'), so nobody was told: no
     *     customer WhatsApp, no admin notification, no listener.
     * reconcileRedeemedPoints() is private, which is why the fix belongs here
     * rather than in the page — and it means force-cancel is now part of the
     * cancel engine instead of a second implementation of it.
     *
     * The deliberate difference from cancel() stays: refund_amount 0 and
     * refund_status 'none'. Force-cancel means "no refund policy applies —
     * handle any money by hand", which is why it is superadmin-only.
     *
     * @return array<string, mixed> the refreshed booking row
     */
    public static function forceCancel(int $bookingId, string $reason): array
    {
        $reason = Security::clean($reason, 255);
        if ($reason === '') {
            $reason = 'Force-cancelled by admin';
        }

        $booking = Database::transaction(static function () use ($bookingId, $reason): array {
            $booking = Database::fetchForUpdate('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
            }
            if (in_array($booking['status'], ['cancelled', 'rejected'], true)) {
                throw new RuntimeException('This booking is already cancelled.');
            }

            Database::update('bookings', [
                'status'        => 'cancelled',
                'cancel_reason' => $reason,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'refund_amount' => 0,
                'refund_status' => 'none',
                'updated_at'    => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $bookingId]);

            Seats::releaseBooking($bookingId);
            // Terminal, so any still-spent redeemed points come back. The
            // delta reconciler is used for the same reason cancel() uses it:
            // a prior reject/re-approve cycle may already have refunded and
            // re-charged them, and this posts only what is actually owed.
            self::reconcileRedeemedPoints($booking, false);

            Database::update('commissions', ['status' => 'void'], 'booking_id = :b', ['b' => $bookingId]);
            AgentWallet::voidFor($booking, $reason);

            self::notifyAdmin('🚫', 'Booking force-cancelled · ' . (string) $booking['pnr'],
                'Superadmin force-cancelled this booking. Seats released, commission voided, no refund computed.',
                $bookingId);

            Logger::audit('booking.force_cancel', 'booking', (string) $booking['pnr'],
                ['status' => (string) $booking['status']],
                ['status' => 'cancelled', 'reason' => $reason],
                'superadmin force-cancel');

            return (array) Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
        });

        // Post-commit, exactly as cancel() does it: the passenger is told their
        // trip is off, and the selling agent that the commission is reversed.
        if ($booking !== []) {
            EventBus::emit('booking.cancelled', [
                'booking' => $booking,
                'refund'  => ['amount' => 0.0, 'percent' => 0.0, 'reason' => 'Force-cancelled — no refund slab applied.'],
                'via'     => 'force',
            ]);
        }

        return $booking;
    }

    /* =================================================================
     *  Per-seat cancel  (Admin Panel Upgrade · Section C)
     *
     *  Cancels a SINGLE seat from a multi-seat booking, releasing it back
     *  into the pool and computing the proportional refund. If only one
     *  seat remains, delegates to the full cancel() path instead so the
     *  booking reaches a clean terminal state.
     * ================================================================= */

    /**
     * Cancel one seat within a multi-seat booking.
     *
     * @return array{booking: array, refund: array, cancelledSeat: string}
     * @throws RuntimeException on any rule violation (user-safe message)
     */
    public static function cancelSeat(int $bookingId, string $seatNo, int $adminId, string $reason = ''): array
    {
        $seatNo = strtoupper(trim($seatNo));

        /* ---- Pre-transaction validation --------------------------------- */
        $booking = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);
        if ($booking === null) {
            throw new RuntimeException('Booking not found.');
        }
        if (!in_array($booking['status'], ['confirmed', 'pending'], true)) {
            throw new RuntimeException('Seats can only be cancelled on a pending or confirmed booking (this one is "' . $booking['status'] . '").');
        }

        // Agent scoping: agents can only edit their own bookings.
        $scope = Auth::bookingScopeAdminId();
        if ($scope !== null && (int) ($booking['sold_by_admin_id'] ?? 0) !== $scope) {
            throw new RuntimeException('You can only cancel seats on bookings you sold.');
        }

        // Count live seats on this booking.
        $liveSeats = Database::fetchAll(
            'SELECT seat_no FROM booking_seats WHERE booking_id = :b AND released_at IS NULL',
            ['b' => $bookingId]
        );
        $liveSeatNos = array_map('strval', pluck($liveSeats, 'seat_no'));
        $seatCount   = count($liveSeatNos);

        if (!in_array($seatNo, $liveSeatNos, true)) {
            throw new RuntimeException('Seat ' . $seatNo . ' is not part of this booking.');
        }

        // If this is the LAST seat, delegate to the full cancel path.
        if ($seatCount <= 1) {
            $res = self::cancel((string) $booking['pnr'], $reason ?: 'Last seat cancelled', false);
            $res['cancelledSeat'] = $seatNo;
            return $res;
        }

        /* ---- Compute the per-seat share of fare and refund --------------- */
        $perSeatFare = round((float) $booking['total_amount'] / $seatCount, 2);
        $newTotal    = round((float) $booking['total_amount'] - $perSeatFare, 2);

        // Refund only when a real payment is verified.
        $refund = ['amount' => 0.0, 'percent' => 0.0, 'reason' => 'No payment had been collected.'];

        $paymentCollected = Database::exists(
            "SELECT 1 FROM payments WHERE booking_id = :b AND status = 'verified'",
            ['b' => $bookingId]
        );

        if ((string) $booking['status'] === 'confirmed' && $paymentCollected) {
            $leg = Database::fetch(
                "SELECT l.travel_date, r.dep_time
                   FROM booking_legs l JOIN schedules s ON s.id = l.schedule_id
                   JOIN routes r ON r.id = s.route_id
                  WHERE l.booking_id = :b AND l.leg_type = 'outbound' LIMIT 1",
                ['b' => $bookingId]
            );
            $fullRefund = Fare::refundFor(
                $perSeatFare,
                (string) ($leg['travel_date'] ?? $booking['created_at']),
                (string) ($leg['dep_time'] ?? '00:00:00')
            );
            $refund = $fullRefund;
        }

        /* ---- Transaction: release seat, adjust totals, void commission -- */
        $result = Database::transaction(function () use (
            $bookingId, $seatNo, $booking, $newTotal, $perSeatFare, $refund, $adminId, $reason, $seatCount
        ): array {
            // Lock the booking row.
            $locked = Database::fetchForUpdate('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId])[0] ?? null;
            if ($locked === null) { throw new RuntimeException('Booking not found.'); }

            // 1) Release the single seat.
            Seats::releaseSingleSeat($bookingId, $seatNo);

            // 2) Remove the passenger for this seat.
            Database::delete(
                'booking_passengers',
                'booking_id = :b AND seat_no = :s',
                ['b' => $bookingId, 's' => $seatNo]
            );

            // 3) Update booking totals.
            $existingRefund = (float) ($locked['refund_amount'] ?? 0);
            $newRefundTotal = round($existingRefund + $refund['amount'], 2);

            Database::update('bookings', [
                'total_amount'  => $newTotal,
                'refund_amount' => $newRefundTotal,
                'refund_status' => $newRefundTotal > 0 ? 'pending' : ($locked['refund_status'] ?? 'none'),
                'updated_at'    => date('Y-m-d H:i:s'),
                'admin_note'    => trim(($locked['admin_note'] ?? '') . "\n" . 'Seat ' . $seatNo . ' cancelled' . ($reason !== '' ? ': ' . $reason : '') . ' [' . date('j M H:i') . ']'),
            ], 'id = :id', ['id' => $bookingId]);

            // 4) Update the outbound leg's seat_count.
            Database::query(
                "UPDATE booking_legs SET seat_count = seat_count - 1
                  WHERE booking_id = :b AND leg_type = 'outbound' AND seat_count > 1",
                ['b' => $bookingId]
            );

            // 5) Void the per-seat share of agent commission.
            //    The commission was accrued as a lump sum; insert a proportional
            //    void entry so the agent's dashboard stays accurate.
            $soldBy = (int) ($locked['sold_by_admin_id'] ?? 0);
            if ($soldBy > 0 && AgentWallet::enabled()) {
                $perSeatCommission = AgentWallet::commissionForBooking($perSeatFare, $soldBy, 1);
                if ($perSeatCommission > 0) {
                    try {
                        /* UNIQUE(booking_id, entry_type) allows ONE void row per
                           booking, so the second seat cancelled on a family
                           ticket used to throw a duplicate-key error that was
                           only logged — the agent kept that seat's commission.
                           A second void now tops up the first. */
                        $existingVoid = Database::fetch(
                            "SELECT id, amount, note FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission_void' LIMIT 1",
                            ['b' => $bookingId]
                        );
                        if ($existingVoid !== null) {
                            Database::update('agent_ledger', [
                                'amount' => round((float) $existingVoid['amount'] - $perSeatCommission, 2),
                                'note'   => (string) $existingVoid['note'] . '; seat ' . $seatNo . ' voided',
                            ], 'id = :id', ['id' => (int) $existingVoid['id']]);
                        } else {
                            Database::insert('agent_ledger', [
                                'agent_admin_id' => $soldBy,
                                'booking_id'     => $bookingId,
                                'account'        => 'commission',
                                'entry_type'     => 'commission_void',
                                'amount'         => -$perSeatCommission,
                                'note'           => 'Per-seat cancel: seat ' . $seatNo . ' voided',
                                'ref'            => $locked['pnr'] ?? '',
                                'created_by'     => $adminId,
                            ]);
                        }
                    } catch (Throwable $e) {
                        Logger::error('Per-seat commission void failed', ['e' => $e->getMessage()]);
                    }
                }
            }

            // 6) Re-issue the ticket to reflect the updated seat list.
            try { Ticket::reissue($bookingId); }
            catch (Throwable $e) { Logger::error('Per-seat cancel ticket reissue failed', ['e' => $e->getMessage()]); }

            Logger::audit('booking.cancel_seat', 'booking', (string) $locked['pnr'],
                ['seat' => $seatNo, 'seats_before' => $seatCount, 'total_before' => (float) $locked['total_amount']],
                ['seats_after' => $seatCount - 1, 'total_after' => $newTotal, 'refund' => $refund['amount']],
                'Per-seat cancel by admin #' . $adminId . ($reason !== '' ? ' · ' . $reason : ''));

            return [
                'booking'       => Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]) ?? [],
                'refund'        => $refund,
                'cancelledSeat' => $seatNo,
            ];
        });

        // Post-commit: drop cached ticket PDF so next download reflects the change.
        try { Ticket::pdfPath($bookingId, true); }
        catch (Throwable $e) { Logger::error('Per-seat cancel PDF regen failed', ['e' => $e->getMessage()]); }

        return $result;
    }

    /**
     * Mark a confirmed ticket as boarded at the gate, and refuse a second
     * boarding on the same ticket.
     *
     * Before this, scan.php only displayed a booking's status and never wrote
     * anything, so it flashed green "VALID" on every rescan — one paid QR
     * screenshot could board unlimited passengers (H2). This is the single
     * write path: it takes the ticket row FOR UPDATE so two gate scans of the
     * same PNR at once serialise, records every scan attempt in scan_count, and
     * stamps boarded_at on the passengers only on the FIRST successful board.
     *
     * @return array{state:string, scannedAt:?string, scannedBy:?int, scanCount:int, pnr:string}
     *         state is 'boarded' (first time) | 'already' (re-scan)
     * @throws RuntimeException (user-safe) when the booking cannot board at all
     */
    public static function markBoarded(string $pnr, int $adminId): array
    {
        $result = Database::transaction(function () use ($pnr, $adminId): array {
            $booking = self::findByPnr($pnr);
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
            }
            if ((string) $booking['status'] !== 'confirmed') {
                throw new RuntimeException('Only a confirmed ticket can board (this one is “' . $booking['status'] . '”).');
            }

            $rows   = Database::fetchForUpdate(
                'SELECT id, scanned_at, scanned_by, scan_count, is_void
                   FROM tickets WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
                ['b' => (int) $booking['id']]
            );
            $ticket = $rows[0] ?? null;
            if ($ticket === null) {
                throw new RuntimeException('No ticket has been issued for this booking yet.');
            }
            if ((int) $ticket['is_void'] === 1) {
                throw new RuntimeException('This ticket has been voided and cannot board.');
            }

            $already   = $ticket['scanned_at'] !== null || (int) $ticket['scan_count'] > 0;
            $scanCount = (int) $ticket['scan_count'] + 1;

            // Always record the scan attempt; stamp the boarding identity only
            // on the first pass so a re-scan cannot overwrite who really boarded.
            Database::update('tickets', [
                'scan_count' => $scanCount,
                'scanned_at' => $already ? $ticket['scanned_at'] : date('Y-m-d H:i:s'),
                'scanned_by' => $already ? $ticket['scanned_by'] : $adminId,
            ], 'id = :id', ['id' => (int) $ticket['id']]);

            if ($already) {
                Logger::audit('ticket.rescan', 'booking', $pnr, null,
                    ['scan_count' => $scanCount],
                    'Re-scan attempt — already boarded at ' . (string) $ticket['scanned_at']);

                return [
                    'state'     => 'already',
                    'scannedAt' => (string) $ticket['scanned_at'],
                    'scannedBy' => $ticket['scanned_by'] !== null ? (int) $ticket['scanned_by'] : null,
                    'scanCount' => $scanCount,
                    'pnr'       => $pnr,
                ];
            }

            Database::update('booking_passengers',
                ['boarded_at' => date('Y-m-d H:i:s')],
                'booking_id = :b', ['b' => (int) $booking['id']]);

            Logger::audit('ticket.board', 'booking', $pnr, null, ['boarded' => 1],
                'Boarded at the gate by admin #' . $adminId);

            return [
                'state'     => 'boarded',
                'scannedAt' => date('Y-m-d H:i:s'),
                'scannedBy' => $adminId,
                'scanCount' => $scanCount,
                'pnr'       => $pnr,
            ];
        });

        // Post-commit journal entry — first boarding only, never the
        // 'already' re-scan branch. No outbound message; the automation_log
        // row is the point (live tracking / audits read it).
        if (($result['state'] ?? '') === 'boarded') {
            $boarded = self::findByPnr($pnr);
            if ($boarded !== null) {
                EventBus::emit('booking.boarded', ['booking' => $boarded]);
            }
        }

        return $result;
    }


    /* =================================================================
     *  Lookups
     * ================================================================= */

    /**
     * @return array<string, mixed>|null
     */
    public static function findByPnr(string $pnr): ?array
    {
        return Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => strtoupper(trim($pnr))]);
    }

    /**
     * Full booking detail for the ticket / status view.
     *
     * @return array<string, mixed>|null
     */
    public static function detail(string $pnr): ?array
    {
        $booking = self::findByPnr($pnr);
        if ($booking === null) {
            return null;
        }

        /* Selling agent — the interconnected "who sold this" record. Name +
           number let the desk (and the ticket) show the exact person behind a
           counter/agent sale, keyed on bookings.sold_by_admin_id, the same id
           the agent dashboard and commission ledger are scoped by. Null for a
           customer's own online booking. */
        $booking['agent'] = null;
        $soldById = (int) ($booking['sold_by_admin_id'] ?? 0);
        if ($soldById > 0) {
            $booking['agent'] = Database::fetch(
                'SELECT full_name, phone, username, role FROM admins WHERE id = :id',
                ['id' => $soldById]
            );
        }

        $booking['legs'] = Database::fetchAll(
            'SELECT l.*, r.from_city, r.to_city, r.route_code,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.dep_time AS route_dep_time,
                    r.arr_time, r.duration_text, r.crew_name, r.crew_phone,
                    s.slot AS schedule_slot,
                    -- carried so the customer payload can say whether the journey
                    -- actually finished; the join is already here, so it is free.
                    s.status AS schedule_status,
                    b.bus_name, b.bus_number
               FROM booking_legs l
               JOIN schedules s ON s.id = l.schedule_id
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses b ON b.id = COALESCE(s.bus_id, r.bus_id)
              WHERE l.booking_id = :b ORDER BY l.leg_type DESC',
            ['b' => $booking['id']]
        );

        // Seats per leg, matched by schedule. The flat booking-wide list
        // below stays for its existing callers, but a round trip needs to
        // know WHICH seats belong to WHICH direction — recovering such a
        // ticket from a new device otherwise shows outbound seats only.
        foreach ($booking['legs'] as &$leg) {
            $leg['seats'] = array_map('strval', pluck(Database::fetchAll(
                'SELECT seat_no FROM booking_seats
                  WHERE booking_id = :b AND schedule_id = :s ORDER BY seat_no',
                ['b' => $booking['id'], 's' => (int) $leg['schedule_id']]
            ), 'seat_no'));
        }
        unset($leg);
        $booking['passengers'] = Database::fetchAll(
            'SELECT * FROM booking_passengers WHERE booking_id = :b ORDER BY id',
            ['b' => $booking['id']]
        );
        $booking['payment'] = Database::fetch(
            'SELECT method, mode, amount, utr_number, status'
            /* what a Nepal drawer physically took, frozen with the sale (26 Sep 2026);
               read only where the column exists so an un-migrated checkout still answers */
            . (CounterDesk::frozenColumns()['payments'] ? ', local_currency, local_amount, fx_rate' : '')
            . ' FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
            ['b' => $booking['id']]
        );
        $booking['seats'] = array_map('strval', pluck(
            Database::fetchAll('SELECT seat_no FROM booking_seats WHERE booking_id = :b ORDER BY seat_no', ['b' => $booking['id']]),
            'seat_no'
        ));
        $booking['ticket_number'] = (string) Database::scalar(
            'SELECT ticket_number FROM tickets WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
            ['b' => $booking['id']],
            ''
        );

        // Scan / boarding state, so the gate screen can show "already boarded"
        // instead of a green VALID on a re-scan (H2). scanned_by is resolved to
        // a staff name for the crew to see who boarded them.
        $booking['ticket'] = Database::fetch(
            'SELECT t.ticket_number, t.scanned_at, t.scanned_by, t.scan_count, t.is_void,
                    a.full_name AS scanned_by_name
               FROM tickets t
               LEFT JOIN admins a ON a.id = t.scanned_by
              WHERE t.booking_id = :b ORDER BY t.id DESC LIMIT 1',
            ['b' => $booking['id']]
        );

        return $booking;
    }


    /* =================================================================
     *  Internals
     * ================================================================= */

    /**
     * The office-set per-seat price for ONE departure (Bus Calendar → "Price",
     * schedules.fare_override), or null when that bus runs at the normal
     * route / direction fare. Only a positive value counts as an override.
     *
     * @param array<string,mixed>|null $schedule a schedules row (needs fare_override)
     */
    public static function scheduleFareOverride(?array $schedule): ?float
    {
        if ($schedule === null) {
            return null;
        }
        $v = $schedule['fare_override'] ?? null;
        if ($v === null || $v === '' || (float) $v <= 0) {
            return null;
        }
        return round((float) $v, 2);
    }

    /**
     * Price a booking authoritatively.
     *
     * @param array<string, mixed> $route
     * @param array<int, string>   $seats
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function priceBooking(
        array $route,
        array $seats,
        ?string $bookingMode,
        array $request,
        string $phone,
        ?array $schedule = null,
        string $boardingStop = '',
        string $dropStop = '',
        string $travelDate = ''
    ): array {
        $seatCount = count($seats);
        $isSleeper = ($schedule !== null ? Seats::effectiveCoach($schedule, $route) : (string) $route['coach_type']) === 'sleeper';

        $cabinLabel = null;
        $perSeat    = (float) $route['base_fare'];

        // Per-departure price (4 Sep 2026, "add bus" with its own price):
        // schedules.fare_override is the per-seat sharing fare for THIS bus
        // when the office set one. Private cabins keep the cabin price list.
        $override = self::scheduleFareOverride($schedule);

        /* The two ends the FARE BOARD reasons about: on the outbound leg the
           passenger's boarding stop is the Gujarat end, on the return it is
           their drop. Falls back to the route's own cities. */
        $ends = Fare::journeyPoints($route, $boardingStop, $dropStop);

        if ($isSleeper && $bookingMode !== null) {
            $cabinType = ($request['cabinType'] ?? 'single') === 'double' ? 'double' : 'single';
            $cabin     = Fare::cabinFare($cabinType, $bookingMode, $seatCount, true, (string) ($route['to_city'] ?? ''), 4, $ends['from']);
            if ($override !== null && $bookingMode === 'sharing') {
                $base    = round($override * $seatCount, 2);
                $perSeat = $override;
            } else {
                $base    = $cabin['total'];
                $perSeat = $seatCount > 0 ? round($base / $seatCount, 2) : $base;
            }
            $cabinLabel = $cabin['label'];
        } else {
            if ($override !== null) {
                $perSeat = $override;
            }
            $base = $perSeat * $seatCount;
        }

        // Loyalty context for a signed-in user.
        $lifetimePoints  = 0;
        $pointsAvailable = 0;
        $user = Auth::userRecord();
        if ($user !== null) {
            $lifetimePoints  = (int) $user['lifetime_points'];
            $pointsAvailable = (int) $user['loyalty_points'];
        }

        $quote = Fare::quote(
            $base,
            $seatCount,
            $lifetimePoints,
            (int) ($request['pointsRequested'] ?? 0),
            $pointsAvailable,
            (string) ($request['couponCode'] ?? ''),
            $phone,
            (int) $route['id'],
            [
                'travelDate'    => $travelDate,
                'departureTime' => Fare::departureTime($schedule, $route),
                'bookingMode'   => $bookingMode,
            ]
        );

        return [
            'perSeat'         => $perSeat,
            'base'            => $quote['base'],
            'groupDiscount'   => $quote['groupDiscount'],
            // The code the fare engine actually applied: what the passenger
            // typed, or the running offer it picked for them.
            'couponCode'      => ((string) ($quote['couponCode'] ?? '')) !== '' && $quote['couponDiscount'] > 0
                ? strtoupper((string) $quote['couponCode'])
                : null,
            'couponDiscount'  => $quote['couponDiscount'],
            'tierDiscount'    => $quote['tierDiscount'],
            'tierName'        => $quote['tierName'],
            'pointsUsed'      => $quote['pointsUsed'],
            'pointsValue'     => $quote['pointsValue'],
            'fee'             => $quote['fee'],
            'tax'             => $quote['tax'],
            'total'           => $quote['total'],
            'cabinLabel'      => $cabinLabel,
            /* 26 Sep 2026 — the advance-booking offer and the four lines every
               screen prints before payment. Carried through so the counter, the
               agent panel and the confirmation all read one calculation. */
            'advanceDiscount' => $quote['advanceDiscount'] ?? 0.0,
            'advancePercent'  => $quote['advancePercent'] ?? 0.0,
            'advanceTitle'    => $quote['advanceTitle'] ?? '',
            'originalFare'    => $quote['originalFare'] ?? $quote['base'],
            'discountAmount'  => $quote['discountAmount'] ?? 0.0,
            'discountPercent' => $quote['discountPercent'] ?? 0.0,
            'finalFare'       => $quote['finalFare'] ?? $quote['total'],
            'fareFrom'        => $ends['from'],
            'fareTo'          => $ends['to'],
        ];
    }

    /**
     * Reject an obvious duplicate: same phone, route, date and overlapping
     * seats submitted within the last few minutes.
     *
     * @param array<int, string> $seats
     */
    private static function assertNoDuplicate(string $phone, int $routeId, string $travelDate, array $seats): void
    {
        $recent = Database::fetchAll(
            'SELECT b.id, GROUP_CONCAT(bs.seat_no) AS seats
               FROM bookings b
               JOIN booking_legs l ON l.booking_id = b.id
               JOIN schedules s ON s.id = l.schedule_id
               JOIN booking_seats bs ON bs.booking_id = b.id
              WHERE b.contact_phone = :phone
                AND s.route_id = :route
                AND l.travel_date = :date
                AND b.status IN (\'pending\', \'confirmed\')
                AND b.created_at > (NOW() - INTERVAL 10 MINUTE)
              GROUP BY b.id',
            ['phone' => $phone, 'route' => $routeId, 'date' => $travelDate]
        );

        foreach ($recent as $row) {
            $existingSeats = explode(',', (string) $row['seats']);
            if (array_intersect($seats, $existingSeats) !== []) {
                throw new RuntimeException('You already have a booking for these seats. Check "My bookings" before trying again.');
            }
        }
    }

    private static function normaliseMethod(string $method): string
    {
        $method = strtolower(trim($method));
        return in_array($method, ['upi', 'esewa', 'cash', 'bank', 'wallet', 'cod'], true) ? $method : 'upi';
    }

    /**
     * The passenger's own "Other" pickup / drop text (owner ask, 17 Sep 2026),
     * made safe for the booking_legs.boarding_stop / drop_stop columns:
     * control characters gone, whitespace collapsed, trimmed, cut at 120
     * characters, and refused when fewer than 3 remain. A canonical-label
     * suffix ("@ 21:00", "[lat,lng]") is stripped so typed text can never
     * pose as a configured stop's time on the ticket. Stored as plain text —
     * booking_legs has no marker column and none is added here.
     */
    private static function manualStop(mixed $text, string $which): string
    {
        $s = Security::clean($text, 120);
        $s = (string) preg_replace('/\s*\[[^\]]*\]\s*$/u', '', $s);
        $s = (string) preg_replace('/\s*@\s*[0-2]?\d:\d{2}\s*$/u', '', $s);
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        if ($s === self::BOARDING_OTHER || mb_strlen($s, 'UTF-8') < 3) {
            throw new RuntimeException($which === 'drop'
                ? 'Please type your drop point (at least 3 letters). / कृपया आफ्नो ओर्लने ठाउँ लेख्नुहोस् (कम्तीमा ३ अक्षर)।'
                : 'Please type your boarding point (at least 3 letters). / कृपया आफ्नो चढ्ने ठाउँ लेख्नुहोस् (कम्तीमा ३ अक्षर)।');
        }
        return $s;
    }

    /**
     * 'HH:MM:SS' of the configured pickup whose town matches $town, or null
     * when none does. Unlike Boarding::timeForStop() this never falls back
     * to the route's departure — a guess must not be written to the leg.
     */
    private static function configuredStopTime(int $routeId, string $town): ?string
    {
        $want = Boarding::townKey($town);
        if ($want === '') {
            return null;
        }
        foreach (Boarding::stopsFor($routeId) as $stop) {
            if ($stop['time'] !== null && Boarding::townKey((string) $stop['name']) === $want) {
                return (string) $stop['time'];
            }
        }
        return null;
    }

    /**
     * Build a canonical boarding-stop label ("Name @ HH:MM") for a booking
     * whose payload arrived without one. Prefer a route_stops row whose
     * town matches the customer's searched origin; fall back to the first
     * still-open pickup; last-resort, use routes.from_city so the ticket
     * never carries an empty string.
     *
     * Called from create() when the client posted boarding='' — see the
     * SHG-2026-00056 fix comment there.
     */
    private static function defaultBoardingStop(int $routeId, string $travelDate, string $originHint, string $fromCity): string
    {
        $canonical = static function (array $stop): string {
            $out = trim((string) ($stop['name'] ?? ''));
            $t   = $stop['time'] !== null ? (string) $stop['time'] : '';
            if ($t !== '') {
                $out .= ' @ ' . substr($t, 0, 5);
            }
            return $out;
        };

        $stops = Boarding::openStops($routeId, $travelDate);
        if ($stops === []) {
            // Route with no route_stops rows at all — return the route's
            // own from_city so the ticket header carries something real.
            return trim($fromCity);
        }

        if ($originHint !== '') {
            $hint = Boarding::townKey($originHint);
            if ($hint !== '') {
                foreach ($stops as $stop) {
                    if (Boarding::townKey((string) $stop['name']) === $hint) {
                        return $canonical($stop);
                    }
                }
            }
        }

        return $canonical($stops[0]);
    }

    private static function awardLoyalty(array $booking): void
    {
        if (empty($booking['user_id'])) {
            return;
        }

        // One trip-booked award per booking, so re-confirming a reversed
        // booking (§4) does not award loyalty points twice.
        if (Database::exists(
            'SELECT 1 FROM loyalty_transactions WHERE booking_id = :b AND points > 0 AND reason LIKE :r',
            ['b' => $booking['id'], 'r' => 'Trip booked%']
        )) {
            return;
        }

        $earned = Fare::pointsEarned((float) $booking['total_amount']);
        $bonus  = Settings::getInt('loyalty_trip_bonus', 10);
        $total  = $earned + $bonus;

        if ($total > 0) {
            self::adjustPoints((int) $booking['user_id'], $total, 'Trip booked ' . $booking['pnr'], (int) $booking['id']);
        }
    }

    /**
     * Reconcile a booking's *redeemed* loyalty points to the state its status
     * implies, so the reversible review flow (§4) can't hand out a points-funded
     * discount for free. Confirmed ⇒ points stay spent (ledger net −points_used);
     * rejected ⇒ points refunded (net 0). Idempotent, and self-correcting across
     * reject⇄confirm cycles: it only ever posts the delta needed to hit the target,
     * so re-running for the same target is a no-op. Only touches the redeemed-points
     * ledger entries (checkout spend / refund / re-charge) — earned "Trip booked"
     * points are handled separately by awardLoyalty.
     */
    private static function reconcileRedeemedPoints(array $booking, bool $shouldStaySpent): void
    {
        $used = (int) ($booking['points_used'] ?? 0);
        if ($used <= 0 || empty($booking['user_id'])) {
            return;
        }
        $net = (int) Database::scalar(
            "SELECT COALESCE(SUM(points), 0) FROM loyalty_transactions
              WHERE booking_id = :b
                AND (reason LIKE 'Redeemed at checkout%'
                  OR reason LIKE 'Refund of points%'
                  OR reason LIKE 'Re-charge of points%')",
            ['b' => (int) $booking['id']],
            0
        );
        $target = $shouldStaySpent ? -$used : 0;
        $delta  = $target - $net;
        if ($delta !== 0) {
            $reason = ($delta < 0 ? 'Re-charge of points ' : 'Refund of points ') . $booking['pnr'];
            self::adjustPoints((int) $booking['user_id'], $delta, $reason, (int) $booking['id']);
        }
    }

    /**
     * Apply a points delta and keep the tier + balances in sync.
     */
    private static function adjustPoints(int $userId, int $delta, string $reason, ?int $bookingId = null): void
    {
        $user = Database::fetch('SELECT loyalty_points, lifetime_points FROM users WHERE id = :id', ['id' => $userId]);
        if ($user === null) {
            return;
        }

        $newBalance  = max(0, (int) $user['loyalty_points'] + $delta);
        $newLifetime = (int) $user['lifetime_points'] + max(0, $delta); // lifetime only grows

        $tier = Fare::tierForPoints($newLifetime);

        Database::update('users', [
            'loyalty_points'  => $newBalance,
            'lifetime_points' => $newLifetime,
            'loyalty_tier'    => $tier['name'],
        ], 'id = :id', ['id' => $userId]);

        Database::insert('loyalty_transactions', [
            'user_id'       => $userId,
            'booking_id'    => $bookingId,
            'points'        => $delta,
            'balance_after' => $newBalance,
            'reason'        => Security::clean($reason, 191),
        ]);
    }

    private static function recordCommission(array $booking): void
    {
        $seatCount = (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE booking_id = :b', ['b' => $booking['id']], 0);
        $calc      = Fare::referralCommission($booking, $seatCount);

        if ($calc['agent'] === null) {
            return;
        }

        // One commission per booking.
        if (Database::exists('SELECT 1 FROM commissions WHERE booking_id = :b', ['b' => $booking['id']])) {
            return;
        }

        Database::insert('commissions', [
            'booking_id'   => $booking['id'],
            'agent_id'     => $calc['agent']['id'],
            'agent_code'   => $calc['agent']['ref_code'],
            'amount'       => $calc['amount'],
            'rule_mode'    => $calc['mode'],
            'rule_value'   => $calc['value'],
            'status'       => $calc['withinWindow'] ? 'confirmed' : 'expired',
            'note'         => $calc['withinWindow'] ? '' : 'Confirmed after commission window',
            'confirmed_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function notifyAdmin(string $icon, string $title, string $body, ?int $bookingId = null): void
    {
        Database::insert('notifications', [
            'audience'   => 'admin',
            'booking_id' => $bookingId,
            'icon'       => $icon,
            'title'      => Security::clean($title, 191),
            'body'       => Security::clean($body, 500),
        ]);
    }

    private static function notifyUser(array $booking, string $icon, string $title, string $body): void
    {
        Database::insert('notifications', [
            'audience'   => 'user',
            'user_id'    => $booking['user_id'] ?? null,
            'booking_id' => $booking['id'] ?? null,
            'icon'       => $icon,
            'title'      => Security::clean($title, 191),
            'body'       => Security::clean($body, 500),
        ]);
    }
}
