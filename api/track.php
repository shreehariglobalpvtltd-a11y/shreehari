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
    // as the booking owner, or a staff member entitled to THIS booking is
    // viewing.
    //
    // Holding a staff session is not enough on its own. A counter agent has
    // one and may still only ever see the bookings they sold themselves, so
    // scope them by bookings.sold_by_admin_id exactly as admin/booking-view.php
    // does. Without this the PNR — which is printed on every ticket, so it is
    // no secret — was the only thing between an agent and full passenger
    // detail (name, phone, seats, crew) for the entire company.
    $scopeId   = Auth::bookingScopeAdminId();
    $staffSees = Auth::isAdmin()
        && ($scopeId === null || (int) ($detail['sold_by_admin_id'] ?? 0) === $scopeId);

    $ownsIt = ($phone !== '' && $phone === (string) $detail['contact_phone'])
        || (Auth::isUser() && (int) (Auth::user()['id'] ?? 0) === (int) ($detail['user_id'] ?? -1))
        || $staffSees;

    if (!$ownsIt) {
        Response::success([
            'pnr'        => $detail['pnr'],
            'status'     => $detail['status'],
            // Live pill is safe to expose without ownership — it is derived
            // from public route dep_time + status, not from passenger data.
            'live_status'=> Ticket::liveStatus($detail),
            'partial'    => true,
            'hint'       => 'Enter the mobile number used at booking to see full details.',
        ]);
    }

    // One shared shape for track.php and my-bookings.php (includes/helpers.php).
    Response::success(shg_customer_payload($detail));
} catch (Throwable $e) {
    Response::serverError($e);
}
