<?php
/**
 * POST /api/book.php — create a pending booking.
 * Body: {
 *   routeId, travelDate, seats[], passengers[{seat,name,age,gender,special?,idType?,idNum?,nationality?}],
 *   contact{phone,email,idType,idNum}, bookingMode?, cabinType?,
 *   sharingTier?, boarding?, drop?, couponCode?, pointsRequested?,
 *   referralCode?, agentCode?, paymentMethod?, isCod?
 * }
 *
 * `agentCode` is an alias of `referralCode` — the customer's optional
 * SHG-NNN input (master-prompt §3). Only the plain string leaves the
 * browser; the server resolves it to `sold_by_admin_id`. Invalid codes
 * never block the booking — the response's `agent.applied=false` tells
 * the client to show a small "booked as direct sale" note.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    /* Counter mode (3 Sep 2026): a signed-in staff member who may sell books
       through this same endpoint. Detected from the SESSION, never from the
       payload — a guest that sends counterPayment is refused below. */
    $staff   = Auth::admin();
    $canSell = $staff !== null && (Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin());
    // An office counter books all day from one IP; the public limit stays as it was.
    Security::requireRateLimit('book_create', Security::clientIp(), $canSell ? 120 : 12, 300);

    $input = Response::input();

    // Normalise passengers into a predictable shape.
    $passengers = [];
    foreach ((array) ($input['passengers'] ?? []) as $pax) {
        if (!is_array($pax)) {
            continue;
        }
        $passengers[] = [
            'seat'   => Security::clean($pax['seat'] ?? '', 10),
            'name'   => Security::clean($pax['name'] ?? '', 120),
            'age'    => isset($pax['age']) ? (int) $pax['age'] : null,
            'gender' => in_array($pax['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? $pax['gender'] : null,
            // Patient / birami mode (4 Sep 2026): a priority-boarding flag the
            // manifest and ticket show. Allow-listed; anything else is dropped.
            'special'=> in_array($pax['special'] ?? '', ['patient', 'senior', 'pregnant'], true) ? $pax['special'] : null,
            // Per-passenger document (17 Sep 2026): allow-listed keys only,
            // cleaned to the booking_passengers column width. Optional — a
            // client that sends none falls back to the contact's document
            // for the primary passenger inside BookingService::create().
            'idType'      => Security::clean($pax['idType'] ?? '', 60),
            'idNum'       => Security::clean($pax['idNum'] ?? '', 60),
            'nationality' => Security::clean($pax['nationality'] ?? '', 60),
        ];
    }

    foreach ($passengers as $index => $pax) {
        if ($pax['name'] === '') {
            Response::invalid(['passengers' => 'Enter a name for passenger ' . ($index + 1) . '.']);
        }
    }

    $request = [
        'routeId'         => (int) ($input['routeId'] ?? 0),
        'travelDate'      => Security::clean($input['travelDate'] ?? '', 10),
        // Extra bus on the same date (Bus Calendar, 5 Sep 2026); 0 = the daily bus.
        'scheduleId'      => (int) ($input['scheduleId'] ?? 0),
        'seats'           => array_map('strval', (array) ($input['seats'] ?? [])),
        'passengers'      => $passengers,
        'contact'         => [
            'phone'  => $input['contact']['phone'] ?? '',
            'email'  => $input['contact']['email'] ?? '',
            'idType' => $input['contact']['idType'] ?? '',
            'idNum'  => $input['contact']['idNum'] ?? '',
            // Country of the contact number ('IN'/'NP'/''): the picker value if
            // the customer chose one, else a +91/+977 prefix they typed. India
            // and Nepal share 10-digit mobiles, so this is the only reliable
            // way to route the ticket WhatsApp to the right country.
            'country'=> resolvePhoneCountry(
                (string) ($input['contact']['country'] ?? ''),
                (string) ($input['contact']['phone'] ?? '')
            ),
        ],
        'bookingMode'     => $input['bookingMode'] ?? null,
        'cabinType'       => $input['cabinType'] ?? null,
        'sharingTier'     => $input['sharingTier'] ?? null,
        'boarding'        => $input['boarding'] ?? '',
        'drop'            => $input['drop'] ?? '',
        // Search-origin hint the server uses to pick the right route_stops
        // row when 'boarding' arrives empty (a route with no visible
        // dropdown, or an old client). See BookingService::defaultBoardingStop.
        'originTown'      => $input['originTown'] ?? '',
        'couponCode'      => $input['couponCode'] ?? '',
        'pointsRequested' => (int) ($input['pointsRequested'] ?? 0),
        // referralCode is the historic field; agentCode is the new §3 input.
        // A non-empty agentCode wins; both go through the same server-side
        // resolver in BookingService::create.
        'referralCode'    => (string) (($input['agentCode'] ?? '') !== ''
            ? $input['agentCode']
            : ($input['referralCode'] ?? '')),
        'paymentMethod'   => $input['paymentMethod'] ?? 'upi',
        'isCod'           => !empty($input['isCod']),
    ];

    $counterPay = in_array((string) ($input['counterPayment'] ?? ''), ['cash', 'upi', 'esewa', 'bank'], true)
        ? (string) $input['counterPayment'] : null;
    if ($counterPay !== null && !$canSell) {
        Response::forbidden('Counter sales need a signed-in staff member who may sell.');
    }

    /* 4 Sep 2026: name + mobile sign-in IS the identity. A signed-in customer
       books against their own number — the payload can no longer aim a
       booking (and its paid notifications) at somebody else's phone. Counter
       sales are exempt: the staff member types the passenger's number. A
       guest with no session keeps the old behaviour. */
    if ($counterPay === null) {
        $me = Auth::user();
        if ($me !== null && normalisePhone((string) ($me['phone'] ?? '')) !== '') {
            $request['contact']['phone'] = normalisePhone((string) $me['phone']);
        }
    }
    $seller = null;
    if ($counterPay !== null) {
        $seller = [
            'adminId'       => (int) ($staff['id'] ?? 0),
            'source'        => Auth::isCounterAgent() ? 'agent' : 'counter',
            'paymentMethod' => $counterPay,
            'discountType'  => (string) ($input['discountType'] ?? ''),
            'discountValue' => (float) ($input['discountValue'] ?? 0),
            'note'          => (string) ($input['note'] ?? ''),
        ];
        // Coupons / loyalty points are customer-app perks; the counter has its own discount.
        $request['couponCode']      = '';
        $request['pointsRequested'] = 0;
        $request['isCod']           = false;
    }
    // Selling staff (admin / counter / agent) may record a ticket for ANY
    // travel date, past or future (owner ask 6 Sep 2026 — the old
    // "yesterday" floor was refusing a paper ticket from three days ago with
    // "Choose a valid travel date"). BookingService::create() still refuses a
    // date before the inaugural departure, a cancelled trip and a per-date
    // OFF. Everyone else: today onwards, exactly as before.
    $validDate = Security::isValidDate($request['travelDate']);
    $tooEarly  = $seller === null && $request['travelDate'] < todayISO();
    if (!$validDate || $tooEarly) {
        Response::invalid(['travelDate' => 'Choose a valid travel date.']);
    }

    $booking = BookingService::create($request, $seller);

    // Payment target details for the QR / instructions screen.
    $payInfo = [
        'upiId'    => Settings::getString('upi_id', ''),
        'upiName'  => Settings::getString('upi_name', ''),
        'esewaId'  => Settings::getString('esewa_id', ''),
        'esewaName'=> Settings::getString('esewa_name', ''),
        'upiLink'  => upiLink(
            Settings::getString('upi_id', ''),
            Settings::getString('upi_name', ''),
            (float) $booking['total_amount'],
            $booking['pnr']
        ),
    ];

    // Agent-code applied/rejected notice for the customer (§3). Only a display
    // label ("SHG-027") leaves the server — never the agent's name, branch or
    // admin id. An empty submission returns null so the client stays quiet.
    $agentNotice = null;
    if (($request['referralCode'] ?? '') !== '') {
        $soldBy = isset($booking['sold_by_admin_id']) ? (int) $booking['sold_by_admin_id'] : 0;
        $agentNotice = $soldBy > 0
            ? ['applied' => true,  'label' => AgentWallet::agentCodeLabel($soldBy) ?: 'Agent code applied']
            : ['applied' => false, 'label' => ''];
    }

    Response::success([
        'pnr'        => $booking['pnr'],
        'total'      => (float) $booking['total_amount'],
        'status'     => $booking['status'],
        'isCod'      => (bool) $booking['is_cod'],
        'payment'    => $payInfo,
        'verifyTime' => Settings::getString('verify_time_text', '1–3 minutes'),
        'agent'      => $agentNotice,
        // Counter mode: the app skips the payment screen and shows the ticket.
        'counter'    => $seller !== null,
        'adminUrl'   => $seller !== null ? '/admin/booking-view.php?pnr=' . rawurlencode((string) $booking['pnr']) : null,
    ], $seller !== null ? 'Counter sale confirmed.' : 'Booking created. Please complete payment to confirm.');
} catch (PDOException $e) {
    // A database failure is a RuntimeException too (6 Sep 2026): it is
    // logged and answered generically, never shown to a passenger as raw SQL.
    Response::serverError($e);
} catch (RuntimeException $e) {
    // Rule violations carry a user-safe message.
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::serverError($e);
}
