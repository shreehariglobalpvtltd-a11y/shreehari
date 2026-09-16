<?php
/**
 * POST /api/cancel.php — cancel a booking and compute the refund.
 * Body: { pnr, phone, reason? }
 *
 * The phone number must match the booking — this is the customer's
 * authorisation without needing a full login.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    $pnr    = Security::clean(Response::field('pnr', ''), 40);
    $phone  = normalisePhone(Security::clean(Response::field('phone', ''), 20));
    $reason = Security::clean(Response::field('reason', 'Cancelled by customer'), 255);

    if (!Security::isValidPnr($pnr)) {
        Response::invalid(['pnr' => 'Enter a valid booking reference.']);
    }

    Security::requireRateLimit('cancel', Security::clientIp(), 10, 300);

    $booking = BookingService::findByPnr($pnr);
    if ($booking === null) {
        Response::notFound('No booking found with that reference.');
    }

    // Authorise: matching phone, owner login, or a staff member who actually
    // holds bookings.cancel.
    //
    // Being signed in as staff used to be enough. That let ANY staff account
    // cancel ANY booking by reference alone — including a counter agent, who
    // is deliberately never granted bookings.cancel and may not so much as
    // READ another agent's sale (see Auth::bookingScopeAdminId). Cancelling
    // is the most destructive act in the system, so it gates on the
    // permission rather than on merely holding a session.
    $staffMayCancel = Auth::can('bookings.cancel');

    $authorised = ($phone !== '' && $phone === (string) $booking['contact_phone'])
        || (Auth::isUser() && (int) (Auth::user()['id'] ?? 0) === (int) ($booking['user_id'] ?? -1))
        || $staffMayCancel;

    if (!$authorised) {
        Response::forbidden('Please confirm the mobile number used at booking to cancel.');
    }

    // Staff waive the customer-facing cancellation penalty; a customer
    // cancelling their own booking does not. Keyed on the same permission,
    // so an agent who cancelled via the phone-match path above is correctly
    // treated as the customer they are acting for.
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
