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

final class BookingService
{
    /**
     * Create a pending booking.
     *
     * The $request array is already-validated input from the API layer:
     *   routeId, travelDate, seats[], passengers[], contact{},
     *   bookingMode, sharingTier, couponCode, pointsRequested,
     *   referralCode, isCod, returnLeg{?}
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed> the created booking (with pnr)
     * @throws RuntimeException on any rule violation (message is user-safe)
     */
    public static function create(array $request): array
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

        $maxSeats = Settings::getInt('max_seats_per_booking', MAX_SEATS_BOOKING);
        if (count($seats) > $maxSeats) {
            throw new RuntimeException('A single booking may hold at most ' . $maxSeats . ' seats.');
        }

        if (count($passengers) !== count($seats)) {
            throw new RuntimeException('Please enter details for every selected seat.');
        }

        $phone = normalisePhone((string) ($contact['phone'] ?? ''));
        if (!Security::isValidPhone($phone)) {
            throw new RuntimeException('Please enter a valid mobile number.');
        }

        $route = Database::fetch('SELECT * FROM routes WHERE id = :id AND is_active = 1 LIMIT 1', ['id' => $routeId]);
        if ($route === null) {
            throw new RuntimeException('That route is no longer available.');
        }

        /* ---- Duplicate-booking guard ------------------------------ */
        self::assertNoDuplicate($phone, $routeId, $travelDate, $seats);

        $bookingMode = in_array($request['bookingMode'] ?? '', ['sharing', 'private'], true)
            ? (string) $request['bookingMode'] : null;
        $isCod = !empty($request['isCod']) && Settings::getBool('allow_cod', true);

        /* ---- Everything below is atomic --------------------------- */
        return Database::transaction(function () use (
            $route, $routeId, $travelDate, $seats, $passengers,
            $contact, $phone, $bookingMode, $isCod, $request, $token
        ): array {

            $schedule   = Seats::schedule($routeId, $travelDate);
            $scheduleId = (int) $schedule['id'];

            // Final availability check under the transaction.
            Seats::assertAvailable($scheduleId, $seats, $token);

            /* ---- Price it (server-authoritative) ------------------ */
            $pricing = self::priceBooking($route, $seats, $bookingMode, $request, $phone);

            /* ---- Insert the booking header ------------------------ */
            $pnr    = generatePnr((string) $route['route_code']);
            $userId = Auth::user()['id'] ?? null;

            $expiryMinutes = Settings::getInt('booking_expiry_minutes', 120);

            $bookingId = Database::insert('bookings', [
                'pnr'             => $pnr,
                'user_id'         => $userId,
                'trip_type'       => !empty($request['returnLeg']) ? 'round' : 'oneway',
                'booking_mode'    => $bookingMode,
                'cabin_type'      => $request['cabinType'] ?? null,
                'sharing_tier'    => $request['sharingTier'] ?? null,
                'cabin_label'     => $pricing['cabinLabel'],
                'contact_phone'   => $phone,
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
                'referral_code'   => strtoupper(Security::clean($request['referralCode'] ?? '', 20)) ?: null,
                'status'          => 'pending',
                'is_cod'          => $isCod ? 1 : 0,
                'source'          => 'web',
                'ip_address'      => Security::clientIp(),
                'user_agent'      => Security::userAgent(),
                'expires_at'      => date('Y-m-d H:i:s', time() + $expiryMinutes * 60),
            ]);

            /* ---- Outbound leg ------------------------------------- */
            $legId = Database::insert('booking_legs', [
                'booking_id'    => $bookingId,
                'schedule_id'   => $scheduleId,
                'leg_type'      => 'outbound',
                'travel_date'   => $travelDate,
                'boarding_stop' => Security::clean($request['boarding'] ?? '', 191),
                'drop_stop'     => Security::clean($request['drop'] ?? '', 191),
                'fare_per_seat' => $pricing['perSeat'],
                'seat_count'    => count($seats),
                'leg_total'     => $pricing['base'],
            ]);

            /* ---- Claim seats (throws on a lost race) -------------- */
            Seats::claim($scheduleId, $seats, $bookingId, $legId);

            /* ---- Passengers --------------------------------------- */
            foreach ($passengers as $index => $pax) {
                $seatNo = $seats[$index] ?? ($pax['seat'] ?? '');

                Database::insert('booking_passengers', [
                    'booking_id'    => $bookingId,
                    'leg_id'        => $legId,
                    'passenger_ref' => generatePassengerRef(),
                    'seat_no'       => strtoupper(Security::clean($seatNo, 10)),
                    'full_name'     => Security::clean($pax['name'] ?? '', 120),
                    'age'           => isset($pax['age']) ? (int) $pax['age'] : null,
                    'gender'        => in_array($pax['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $pax['gender'] : null,
                    'is_primary'    => $index === 0 ? 1 : 0,
                ]);
            }

            /* ---- Payment record ----------------------------------- */
            $paymentMethod = self::normaliseMethod($request['paymentMethod'] ?? 'upi');

            Database::insert('payments', [
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
                ($isCod ? 'Pay-at-counter' : 'Awaiting payment') . ' · ' . count($seats) . ' seat(s) · ' . inr($pricing['total']),
                $bookingId
            );

            Logger::audit('booking.create', 'booking', $pnr, null, null, count($seats) . ' seats, ' . inr($pricing['total']));

            $booking = Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]);

            return $booking ?? [];
        });
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

        if (in_array($booking['status'], ['cancelled', 'rejected'], true)) {
            throw new RuntimeException('This booking is no longer active.');
        }

        $payment = Database::fetch(
            'SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
            ['b' => $booking['id']]
        );
        if ($payment === null) {
            throw new RuntimeException('No payment record for this booking.');
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

        self::notifyAdmin('💳', 'Payment proof · ' . $pnr, 'UTR ' . ($utr ?: '—') . ' submitted for verification.', (int) $booking['id']);

        Logger::audit('payment.proof', 'booking', $pnr, null, null, 'UTR ' . $utr);

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

        return ['file' => $relative];
    }

    /**
     * Verify a payment and confirm the booking. Issues the ticket,
     * awards loyalty points and records referral commission.
     */
    public static function confirm(int $bookingId, int $adminId, string $note = ''): array
    {
        return Database::transaction(function () use ($bookingId, $adminId, $note): array {
            $booking = Database::fetch('SELECT * FROM bookings WHERE id = :id LIMIT 1', ['id' => $bookingId]);
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
            }
            if ($booking['status'] === 'confirmed') {
                return $booking; // idempotent
            }

            Database::update('payments', [
                'status'      => 'verified',
                'verified_by' => $adminId,
                'verified_at' => date('Y-m-d H:i:s'),
                'admin_note'  => $note !== '' ? Security::clean($note, 255) : null,
            ], 'booking_id = :b', ['b' => $bookingId]);

            Database::update('bookings', [
                'status'       => 'confirmed',
                'confirmed_at' => date('Y-m-d H:i:s'),
                'expires_at'   => null,
            ], 'id = :id', ['id' => $bookingId]);

            // Issue the ticket now that payment is confirmed.
            Ticket::issue($bookingId);

            self::awardLoyalty($booking);
            self::recordCommission($booking);

            self::notifyUser($booking, '✅', 'Booking confirmed', 'Your ticket for ' . $booking['pnr'] . ' is ready to download.');

            Logger::audit('booking.confirm', 'booking', (string) $booking['pnr'], null, null, $note);

            return Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]) ?? [];
        });
    }

    /**
     * Reject a payment. Releases the seats so they sell again.
     */
    public static function reject(int $bookingId, int $adminId, string $reason): array
    {
        return Database::transaction(function () use ($bookingId, $adminId, $reason): array {
            $booking = Database::fetch('SELECT * FROM bookings WHERE id = :id LIMIT 1', ['id' => $bookingId]);
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
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
            self::refundPointsIfAny($booking);

            self::notifyUser($booking, '❌', 'Payment not verified', 'Booking ' . $booking['pnr'] . ': ' . $reason);

            Logger::audit('booking.reject', 'booking', (string) $booking['pnr'], null, null, $reason);

            return Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $bookingId]) ?? [];
        });
    }

    /**
     * Cancel a confirmed or pending booking and compute the refund.
     */
    public static function cancel(string $pnr, string $reason = '', bool $byCustomer = true): array
    {
        return Database::transaction(function () use ($pnr, $reason, $byCustomer): array {
            $booking = self::findByPnr($pnr);
            if ($booking === null) {
                throw new RuntimeException('Booking not found.');
            }
            if (in_array($booking['status'], ['cancelled', 'rejected'], true)) {
                throw new RuntimeException('This booking is already cancelled.');
            }

            // Refund only applies to money actually taken (confirmed bookings).
            $refund = ['amount' => 0.0, 'percent' => 0.0, 'reason' => 'No payment had been taken.'];

            if ($booking['status'] === 'confirmed') {
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
            self::refundPointsIfAny($booking);

            // Void any commission that was booked for this PNR.
            Database::update('commissions', ['status' => 'void'], 'booking_id = :b', ['b' => $booking['id']]);

            $actor = $byCustomer ? 'customer' : 'admin';
            self::notifyAdmin('🚫', 'Booking cancelled · ' . $pnr, ucfirst($actor) . ' cancelled. Refund due: ' . inr($refund['amount']), (int) $booking['id']);

            Logger::audit('booking.cancel', 'booking', $pnr, null, null, $reason . ' | refund ' . inr($refund['amount']));

            return [
                'booking' => Database::fetch('SELECT * FROM bookings WHERE id = :id', ['id' => $booking['id']]),
                'refund'  => $refund,
            ];
        });
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

        $booking['legs'] = Database::fetchAll(
            'SELECT l.*, r.from_city, r.to_city, r.bus_name, r.route_code,
                    r.dep_time, r.arr_time, r.crew_name, r.crew_phone
               FROM booking_legs l
               JOIN schedules s ON s.id = l.schedule_id
               JOIN routes r ON r.id = s.route_id
              WHERE l.booking_id = :b ORDER BY l.leg_type DESC',
            ['b' => $booking['id']]
        );
        $booking['passengers'] = Database::fetchAll(
            'SELECT * FROM booking_passengers WHERE booking_id = :b ORDER BY id',
            ['b' => $booking['id']]
        );
        $booking['payment'] = Database::fetch(
            'SELECT method, mode, amount, utr_number, status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
            ['b' => $booking['id']]
        );
        $booking['seats'] = array_map('strval', pluck(
            Database::fetchAll('SELECT seat_no FROM booking_seats WHERE booking_id = :b ORDER BY seat_no', ['b' => $booking['id']]),
            'seat_no'
        ));

        return $booking;
    }


    /* =================================================================
     *  Internals
     * ================================================================= */

    /**
     * Price a booking authoritatively.
     *
     * @param array<string, mixed> $route
     * @param array<int, string>   $seats
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private static function priceBooking(array $route, array $seats, ?string $bookingMode, array $request, string $phone): array
    {
        $seatCount = count($seats);
        $isSleeper = ($route['coach_type'] === 'sleeper');

        $cabinLabel = null;
        $perSeat    = (float) $route['base_fare'];

        if ($isSleeper && $bookingMode !== null) {
            $cabinType = ($request['cabinType'] ?? 'single') === 'double' ? 'double' : 'single';
            $cabin     = Fare::cabinFare($cabinType, $bookingMode, $seatCount, true);
            $base      = $cabin['total'];
            $perSeat   = $seatCount > 0 ? round($base / $seatCount, 2) : $base;
            $cabinLabel = $cabin['label'];
        } else {
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
            (int) $route['id']
        );

        return [
            'perSeat'         => $perSeat,
            'base'            => $quote['base'],
            'groupDiscount'   => $quote['groupDiscount'],
            'couponCode'      => ($request['couponCode'] ?? '') !== '' && $quote['couponDiscount'] > 0 ? strtoupper((string) $request['couponCode']) : null,
            'couponDiscount'  => $quote['couponDiscount'],
            'tierDiscount'    => $quote['tierDiscount'],
            'tierName'        => $quote['tierName'],
            'pointsUsed'      => $quote['pointsUsed'],
            'pointsValue'     => $quote['pointsValue'],
            'fee'             => $quote['fee'],
            'tax'             => $quote['tax'],
            'total'           => $quote['total'],
            'cabinLabel'      => $cabinLabel,
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

    private static function awardLoyalty(array $booking): void
    {
        if (empty($booking['user_id'])) {
            return;
        }

        $earned = Fare::pointsEarned((float) $booking['total_amount']);
        $bonus  = Settings::getInt('loyalty_trip_bonus', 10);
        $total  = $earned + $bonus;

        if ($total > 0) {
            self::adjustPoints((int) $booking['user_id'], $total, 'Trip booked ' . $booking['pnr'], (int) $booking['id']);
        }
    }

    private static function refundPointsIfAny(array $booking): void
    {
        $used = (int) ($booking['points_used'] ?? 0);
        if ($used > 0 && !empty($booking['user_id'])) {
            // Only refund once.
            $already = Database::exists(
                'SELECT 1 FROM loyalty_transactions WHERE booking_id = :b AND points > 0 AND reason LIKE :r',
                ['b' => $booking['id'], 'r' => 'Refund%']
            );
            if (!$already) {
                self::adjustPoints((int) $booking['user_id'], $used, 'Refund of points ' . $booking['pnr'], (int) $booking['id']);
            }
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
