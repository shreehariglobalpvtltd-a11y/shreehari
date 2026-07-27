<?php
/**
 * POST /api/book.php — create a pending booking.
 * Body: {
 *   routeId, travelDate, seats[], passengers[{seat,name,age,gender}],
 *   contact{phone,email,idType,idNum}, bookingMode?, cabinType?,
 *   sharingTier?, boarding?, drop?, couponCode?, pointsRequested?,
 *   referralCode?, paymentMethod?, isCod?
 * }
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    Security::requireRateLimit('book_create', Security::clientIp(), 12, 300);

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
        'seats'           => array_map('strval', (array) ($input['seats'] ?? [])),
        'passengers'      => $passengers,
        'contact'         => [
            'phone'  => $input['contact']['phone'] ?? '',
            'email'  => $input['contact']['email'] ?? '',
            'idType' => $input['contact']['idType'] ?? '',
            'idNum'  => $input['contact']['idNum'] ?? '',
        ],
        'bookingMode'     => $input['bookingMode'] ?? null,
        'cabinType'       => $input['cabinType'] ?? null,
        'sharingTier'     => $input['sharingTier'] ?? null,
        'boarding'        => $input['boarding'] ?? '',
        'drop'            => $input['drop'] ?? '',
        'couponCode'      => $input['couponCode'] ?? '',
        'pointsRequested' => (int) ($input['pointsRequested'] ?? 0),
        'referralCode'    => $input['referralCode'] ?? '',
        'paymentMethod'   => $input['paymentMethod'] ?? 'upi',
        'isCod'           => !empty($input['isCod']),
    ];

    if (!Security::isValidDate($request['travelDate']) || $request['travelDate'] < todayISO()) {
        Response::invalid(['travelDate' => 'Choose a valid travel date.']);
    }

    $booking = BookingService::create($request);

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

    Response::success([
        'pnr'        => $booking['pnr'],
        'total'      => (float) $booking['total_amount'],
        'status'     => $booking['status'],
        'isCod'      => (bool) $booking['is_cod'],
        'payment'    => $payInfo,
        'verifyTime' => Settings::getString('verify_time_text', '1–3 minutes'),
    ], 'Booking created. Please complete payment to confirm.');
} catch (RuntimeException $e) {
    // Rule violations carry a user-safe message.
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::serverError($e);
}
