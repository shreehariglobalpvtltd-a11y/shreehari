<?php
/**
 * GET/POST /api/track.php — retrieve a booking by PNR (+ optional phone).
 * Body: { pnr, phone? }
 *
 * Phone is required to reveal full passenger detail; without it only a
 * status summary is returned, so a guessed PNR leaks nothing sensitive.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $pnr   = Security::clean(Response::field('pnr', ''), 40);
    $phone = normalisePhone(Security::clean(Response::field('phone', ''), 20));

    if (!Security::isValidPnr($pnr)) {
        Response::invalid(['pnr' => 'Enter a valid booking reference (PNR).']);
    }

    Security::requireRateLimit('track', Security::clientIp(), 40, 60);

    $detail = BookingService::detail($pnr);
    if ($detail === null) {
        Response::notFound('No booking found with that reference.');
    }

    // Full detail only when the phone matches, or the customer is signed in
    // as the booking owner, or an admin is viewing.
    $ownsIt = ($phone !== '' && $phone === (string) $detail['contact_phone'])
        || (Auth::isUser() && (int) (Auth::user()['id'] ?? 0) === (int) ($detail['user_id'] ?? -1))
        || Auth::isAdmin();

    if (!$ownsIt) {
        Response::success([
            'pnr'        => $detail['pnr'],
            'status'     => $detail['status'],
            'partial'    => true,
            'hint'       => 'Enter the mobile number used at booking to see full details.',
        ]);
    }

    $legs = array_map(static function (array $leg): array {
        return [
            'type'        => $leg['leg_type'],
            'from'        => $leg['from_city'],
            'to'          => $leg['to_city'],
            'date'        => $leg['travel_date'],
            'busName'     => $leg['bus_name'],
            'depTime'     => substr((string) $leg['dep_time'], 0, 5),
            'arrTime'     => substr((string) $leg['arr_time'], 0, 5),
            'boarding'    => $leg['boarding_stop'],
            'drop'        => $leg['drop_stop'],
            'crewName'    => $leg['crew_name'],
            'crewPhone'   => $leg['crew_phone'],
        ];
    }, $detail['legs']);

    Response::success([
        'pnr'         => $detail['pnr'],
        'status'      => $detail['status'],
        'total'       => (float) $detail['total_amount'],
        'currency'    => $detail['currency'],
        'isCod'       => (bool) $detail['is_cod'],
        'seats'       => $detail['seats'],
        'passengers'  => array_map(static fn(array $p): array => [
            'name'   => $p['full_name'],
            'seat'   => $p['seat_no'],
            'age'    => $p['age'],
            'gender' => $p['gender'],
        ], $detail['passengers']),
        'legs'        => $legs,
        'payment'     => [
            'method' => $detail['payment']['method'] ?? '',
            'status' => $detail['payment']['status'] ?? '',
            'utr'    => $detail['payment']['utr_number'] ?? '',
        ],
        'canCancel'   => in_array($detail['status'], ['pending', 'confirmed'], true),
        'ticketUrl'   => $detail['status'] === 'confirmed'
            ? appUrl('download-ticket.php?pnr=' . urlencode($detail['pnr']))
            : null,
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
