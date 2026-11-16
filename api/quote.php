<?php
/**
 * POST /api/quote.php — authoritative price preview before payment.
 * Body: { routeId, seats[], bookingMode?, cabinType?, couponCode?,
 *         pointsRequested?, phone?, boarding?, drop? }
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/couponguard.php';

try {
    Security::requireRateLimit('quote', Security::clientIp(), 120, 60);

    /* 16 Nov 2026 (P4-5d): { returnCheck: 1, phone, routeCode | routeId, travelDate,
       scheduleId? } -> { returnPct } - the return-journey discount % this mobile gets
       on this departure (0 = none), for the website checkout, which shows it before
       the payment and only then sends returnSeen to api/book.php. While
       return_discount_pct is 0 (off) the answer is 0 and nothing is read. Says only
       "a % applies", never which ticket; its own tighter per-IP limit. */
    if (!empty(Response::field('returnCheck', null))) {
        if (!(Settings::getFloat('return_discount_pct', 0.0) > 0)) {
            Response::success(['returnPct' => 0]);
        }
        Security::requireRateLimit('quote_return', Security::clientIp(), 20, 600);
        $rcCode  = Response::field('routeCode', '');
        $rcRoute = is_scalar($rcCode) && trim((string) $rcCode) !== ''
            ? Database::fetch('SELECT * FROM routes WHERE route_code = :c AND is_active = 1 LIMIT 1', ['c' => Security::clean((string) $rcCode, 40)])
            : Database::fetch('SELECT * FROM routes WHERE id = :i AND is_active = 1 LIMIT 1', ['i' => (int) Response::field('routeId', 0)]);
        $rcDate  = Security::clean(Response::field('travelDate', ''), 10);
        $rcPhone = normalisePhone(Security::clean(Response::field('phone', ''), 20));
        $rcPct   = 0.0;
        if ($rcRoute !== null && Security::isValidDate($rcDate) && Security::isValidPhone($rcPhone)) {
            $rcSid   = (int) Response::field('scheduleId', 0);
            $rcSched = $rcSid > 0
                ? Database::fetch('SELECT * FROM schedules WHERE id = :i AND route_id = :r AND travel_date = :d LIMIT 1', ['i' => $rcSid, 'r' => (int) $rcRoute['id'], 'd' => $rcDate])
                : null;
            $rcPct = Fare::returnDiscountPct($rcPhone, (int) $rcRoute['id'], Fare::departTs($rcSched ?? ['travel_date' => $rcDate], $rcRoute));
        }
        Response::success(['returnPct' => $rcPct]);
    }
    /* 15 Nov 2026: a request carrying a coupon code is a guess at a code -
       10 different codes per IP per 10 minutes (CouponGuard; the same code
       sent again by a re-quote is not counted). */
    $couponIn = Response::field('couponCode', '');
    $couponIn = is_scalar($couponIn) ? Security::clean($couponIn, 40) : '';
    if ($couponIn !== '' && !CouponGuard::allow($couponIn)) {
        Response::error('Too many promo codes tried. Please wait 10 minutes and try again.', 429);
    }

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
        // The chosen pickup / drop's own fare (Admin -> Routes, 1 Oct 2026),
        // exactly as BookingService::priceBooking() charges it.
        $stopFare = $bookingMode === 'sharing'
            ? Fare::stopFare($routeId, Security::clean(Response::field('boarding', ''), 191), Security::clean(Response::field('drop', ''), 191))
            : null;
        $cabin   = Fare::cabinFare($cabinType, $bookingMode, $seatCount, true, (string) ($route['to_city'] ?? ''), 4, $stopFare);
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

    /* The departure prices the "booked N hours ahead" offers and (16 Nov 2026,
       P4-5d) the return-journey discount: an extra bus by its scheduleId, else
       the daily bus on travelDate when the caller sends one - the same moment
       BookingService::priceBooking() charges by. No date = null, as before. */
    $quoteDep = null;
    if (isset($qs) && $qs !== null && (int) $qs['route_id'] === $routeId) {
        $quoteDep = Fare::departTs(['id' => $quoteSid], $route);
    } else {
        $quoteDate = Security::clean(Response::field('travelDate', ''), 10);
        if (Security::isValidDate($quoteDate)) {
            $quoteDep = Fare::departTs(['travel_date' => $quoteDate], $route);
        }
    }

    $quote = Fare::quote(
        $base,
        $seatCount,
        $lifetime,
        (int) Response::field('pointsRequested', 0),
        $avail,
        $couponIn,
        $phone,
        $routeId,
        $quoteDep
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
