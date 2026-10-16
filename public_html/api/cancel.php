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

    // Authorise: matching phone, owner login, or admin.
    // '0000000000' is the counter-sale placeholder — never authorise by it.
    $authorised = ($phone !== '' && $phone !== '0000000000' && $phone === (string) $booking['contact_phone']
                   && (string) $booking['contact_phone'] !== '0000000000')
        || (Auth::isUser() && (int) (Auth::user()['id'] ?? 0) === (int) ($booking['user_id'] ?? -1))
        || Auth::isAdmin();

    if (!$authorised) {
        Response::forbidden('Please confirm the mobile number used at booking to cancel.');
    }

    $result = BookingService::cancel($pnr, $reason, !Auth::isAdmin());

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
