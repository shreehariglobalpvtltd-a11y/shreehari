<?php
/**
 * POST /api/quote.php — authoritative price preview before payment.
 * Body: { routeId, seats[], bookingMode?, cabinType?, couponCode?,
 *         pointsRequested?, phone? }
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $routeId = (int) Response::field('routeId', 0);
    $seats   = Response::field('seats', []);
    $seats   = is_array($seats) ? array_values(array_unique(array_map('strval', $seats))) : [];

    if ($routeId <= 0) {
        Response::invalid(['routeId' => 'Choose a bus first.']);
    }
    if ($seats === []) {
        Response::invalid(['seats' => 'Select at least one seat.']);
    }

    $route = Database::fetch('SELECT * FROM routes WHERE id = :id LIMIT 1', ['id' => $routeId]);
    if ($route === null) {
        Response::notFound('Route not found.');
    }

    $bookingMode = Response::field('bookingMode', null);
    $bookingMode = in_array($bookingMode, ['sharing', 'private'], true) ? $bookingMode : null;
    $cabinType   = Response::field('cabinType', 'single') === 'double' ? 'double' : 'single';
    $seatCount   = count($seats);

    /* Per-departure price (4 Sep 2026). The customer may be pricing an EXTRA
       bus that the office gave its own fare; the authoritative charge in
       BookingService::priceBooking() already applies it, so this preview must
       too or the checkout total would move at the last step. The schedule must
       belong to this route, exactly as create() requires. */
    $quoteSid = (int) Response::field('scheduleId', 0);
    $fareOver = null;
    if ($quoteSid > 0) {
        $qs = Database::fetch(
            'SELECT id, route_id, fare_override FROM schedules WHERE id = :id LIMIT 1',
            ['id' => $quoteSid]
        );
        if ($qs !== null && (int) $qs['route_id'] === $routeId) {
            $fareOver = BookingService::scheduleFareOverride($qs);
        }
    }

    // Base fare
    if ($fareOver !== null && ($route['coach_type'] !== 'sleeper' || $bookingMode !== 'private')) {
        // A per-seat price set on the departure replaces the route / direction
        // fare for sharing and seater. Private cabins keep the cabin price list.
        $perSeat = $fareOver;
        $base    = $perSeat * $seatCount;
        $label   = $route['coach_type'] === 'sleeper' ? 'Sharing · this bus' : '';
    } elseif ($route['coach_type'] === 'sleeper' && $bookingMode !== null) {
        // Pass the destination city so the directional sharing fare resolves
        // correctly — toNepal 2000 (Gujarat→Rupaidiha) / toIndia 1800 (return),
        // one flat fare with no online discount (removed 26 Aug 2026). Without
        // $toCity this preview mis-quoted the reverse leg; the authoritative
        // charge in booking.php already passes to_city.
        $cabin   = Fare::cabinFare($cabinType, $bookingMode, $seatCount, true, (string) ($route['to_city'] ?? ''));
        $base    = $cabin['total'];
        $perSeat = $seatCount > 0 ? round($base / $seatCount, 2) : $base;
        $label   = $cabin['label'];
    } else {
        $perSeat = (float) $route['base_fare'];
        $base    = $perSeat * $seatCount;
        $label   = '';
    }

    // Loyalty context
    $lifetime = 0;
    $avail    = 0;
    $user = Auth::userRecord();
    if ($user !== null) {
        $lifetime = (int) $user['lifetime_points'];
        $avail    = (int) $user['loyalty_points'];
    }

    $phone = normalisePhone(Security::clean(Response::field('phone', ''), 20));

    $quote = Fare::quote(
        $base,
        $seatCount,
        $lifetime,
        (int) Response::field('pointsRequested', 0),
        $avail,
        Security::clean(Response::field('couponCode', ''), 40),
        $phone,
        $routeId
    );

    Response::success([
        'perSeat'    => $perSeat,
        'seatCount'  => $seatCount,
        'cabinLabel' => $label,
        'quote'      => $quote,
        'nprEstimate' => nprEstimate($quote['total']),
        'currencyNote' => '₹1 = NPR ' . Settings::getFloat('npr_per_inr', 1.6),
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
