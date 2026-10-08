<?php
/**
 * POST /api/cancel.php — cancel a booking and compute the refund.
 * Body: { pnr, phone?, reason? }
 *
 * 8 Oct 2026 (owner: name + phone is never proof). A PNR and the contact
 * number used to be enough to cancel anybody's ticket — PNRs are
 * sequential, so knowing someone's mobile meant cancelling their seat.
 * A customer may now cancel only through Auth::customerMayOpen(): this
 * browser booked the ticket, or the session proved the number with the
 * WhatsApp code. Otherwise 403 with code 'needs_otp' (the app offers the
 * code, or the office number). Staff holding bookings.cancel unchanged.
 * The refund itself is settled by the owner by hand, as always.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    $pnr    = Security::clean(Response::field('pnr', ''), 40);
    $reason = Security::clean(Response::field('reason', 'Cancelled by customer'), 255);

    if (!Security::isValidPnr($pnr)) {
        Response::invalid(['pnr' => 'Enter a valid booking reference.']);
    }

    Security::requireRateLimit('cancel', Security::clientIp(), 10, 300);

    $booking = BookingService::findByPnr($pnr);
    if ($booking === null) {
        Response::notFound('No booking found with that reference.');
    }

    // Authorise: the owning customer session (see the header), or a staff
    // member who actually holds bookings.cancel.
    //
    // Being signed in as staff used to be enough. That let ANY staff account
    // cancel ANY booking by reference alone — including a counter agent, who
    // is deliberately never granted bookings.cancel and may not so much as
    // READ another agent's sale (see Auth::bookingScopeAdminId). Cancelling
    // is the most destructive act in the system, so it gates on the
    // permission rather than on merely holding a session.
    $staffMayCancel = Auth::can('bookings.cancel');

    // A typed phone is no longer proof, and the walk-in placeholder
    // (0000000000) never is (Auth::customerMayOpen refuses it).
    $authorised = $staffMayCancel || Auth::customerMayOpen($booking);

    if (!$authorised) {
        Response::error(
            'Verify your number with the WhatsApp code to cancel, or call the office. / रद्द गर्न WhatsApp कोडले नम्बर पुष्टि गर्नुहोस् वा कार्यालयमा फोन गर्नुहोस्।',
            403,
            ['code' => 'needs_otp']
        );
    }

    // Staff waive the customer-facing cancellation penalty; a customer
    // cancelling their own booking does not. Keyed on the same permission,
    // so an agent without it who reaches here through a customer session
    // is correctly treated as the customer they are acting for.
    $result = BookingService::cancel($pnr, $reason, !$staffMayCancel);

    Response::success([
        'pnr'          => $pnr,
        'status'       => 'cancelled',
        'refundAmount' => $result['refund']['amount'],
        'refundPct'    => $result['refund']['percent'],
        'message'      => $result['refund']['reason'],
    ], 'Your booking has been cancelled.');
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
