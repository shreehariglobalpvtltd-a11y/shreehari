<?php
/**
 * POST /api/quote.php — authoritative price preview before payment.
 * Body: { routeId, seats[], bookingMode?, cabinType?, couponCode?,
 *         pointsRequested?, phone?, scheduleId?,
 *         travelDate?, boarding?, drop? }
 *
 * 26 Sep 2026: travelDate / boarding / drop arrived with the point-to-point
 * fare board and the advance-booking offer. The board prices a Nana Chiloda
 * pickup differently from a Surat one, and the offer is measured against the
 * departure clock, so a preview without them would quote one number and
 * BookingService::create() would charge another. All three are optional:
 * without them this endpoint answers exactly as it did before.
 *
 * The reply carries `display` — Original fare, Discount %, Discount amount,
 * Final fare — so the checkout, the counter and the agent panel print one
 * calculation instead of three.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    $routeId   = (int) Response::field('routeId', 0);
    $routeCode = Security::clean((string) Response::field('routeCode', ''), 20);
    $seats     = Response::field('seats', []);
    $seats     = is_array($seats) ? array_values(array_unique(array_map('strval', $seats))) : [];

    /* The booking app addresses buses by route_code ('r1', 'r2', ...) while the
       database keys them by numeric id — the same reason /api/lock.php and
       /api/seats.php accept both. Without this the checkout could hold a seat
       but not ask what it costs, because it has no numeric id to send
       (26 Sep 2026). */
    if ($routeId <= 0 && $routeCode !== '') {
        $routeId = (int) Database::scalar(
            'SELECT id FROM routes WHERE route_code = :c AND is_active = 1 LIMIT 1',
            ['c' => $routeCode],
            0
        );
    }

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

    /* The passenger's own ends of the journey and the day they travel. */
    $travelDate  = Security::clean((string) Response::field('travelDate', ''), 10);
    if ($travelDate !== '' && !Security::isValidDate($travelDate)) {
        $travelDate = '';
    }
    $boardingIn  = Security::clean((string) Response::field('boarding', ''), 191);
    $dropIn      = Security::clean((string) Response::field('drop', ''), 191);
    $ends        = Fare::journeyPoints($route, $boardingIn, $dropIn);

    /* Per-departure price (4 Sep 2026). The customer may be pricing an EXTRA
       bus that the office gave its own fare; the authoritative charge in
       BookingService::priceBooking() already applies it, so this preview must
       too or the checkout total would move at the last step. The schedule must
       belong to this route, exactly as create() requires. */
    $quoteSid = (int) Response::field('scheduleId', 0);
    $fareOver = null;
    $qSched   = null;
    if ($quoteSid > 0) {
        $qs = Database::fetch(
            'SELECT id, route_id, fare_override, dep_time_override FROM schedules WHERE id = :id LIMIT 1',
            ['id' => $quoteSid]
        );
        if ($qs !== null && (int) $qs['route_id'] === $routeId) {
            $fareOver = BookingService::scheduleFareOverride($qs);
            $qSched   = $qs;
        }
    }
    /* No explicit bus: the daily departure for this route and date, so the
       advance-booking boundary is measured against the real clock rather
       than midnight. Never fatal — a missing row just means no override. */
    if ($qSched === null && $travelDate !== '') {
        $qSched = Database::fetch(
            'SELECT id, route_id, fare_override, dep_time_override
               FROM schedules WHERE route_id = :r AND travel_date = :d ORDER BY id LIMIT 1',
            ['r' => $routeId, 'd' => $travelDate]
        );
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
        $cabin   = Fare::cabinFare($cabinType, $bookingMode, $seatCount, true, (string) ($route['to_city'] ?? ''), 4, $ends['from']);
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
        $routeId,
        [
            'travelDate'    => $travelDate,
            'departureTime' => Fare::departureTime($qSched, $route),
            'bookingMode'   => $bookingMode,
        ]
    );

    Response::success([
        'perSeat'    => $perSeat,
        'seatCount'  => $seatCount,
        'cabinLabel' => $label,
        'quote'      => $quote,
        /* The four lines that must appear before payment, already worked out
           so no screen has to re-derive them (req 23). */
        'display'    => [
            'originalFare'    => (float) $quote['originalFare'],
            'discountPercent' => (float) $quote['discountPercent'],
            'discountAmount'  => (float) $quote['discountAmount'],
            'finalFare'       => (float) $quote['finalFare'],
        ],
        'advance'    => [
            'amount'      => (float) $quote['advanceDiscount'],
            'percent'     => (float) $quote['advancePercent'],
            'title'       => (string) $quote['advanceTitle'],
            'why'         => (string) $quote['advanceWhy'],
            'hoursLeft'   => (float) $quote['advanceHoursLeft'],
            'hoursNeeded' => (int) $quote['advanceHoursNeeded'],
        ],
        'fareFrom'   => $ends['from'],
        'fareTo'     => $ends['to'],
        'nprEstimate' => nprEstimate($quote['total']),
        'currencyNote' => '₹1 = NPR ' . Settings::getFloat('npr_per_inr', 1.6),
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
