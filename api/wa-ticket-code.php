<?php
/**
 * =====================================================================
 *  api/wa-ticket-code.php — issue a one-time WhatsApp ticket code.
 *  26 Sep 2026.
 *
 *  POST { pnr, phone }  ->  { link, code, expiresIn }
 *
 *  The ticket page calls this when the passenger taps "WhatsApp मा टिकट
 *  पाउनुहोस्". It returns a wa.me link to OUR number with "TICKET K7QM2P"
 *  typed in. The passenger presses send; that inbound message is what
 *  lets us answer (includes/wabot.php). We never message first.
 *
 *  Only a caller who may already see the full booking (the same test as
 *  api/track.php) gets a code, and only for a confirmed ticket: the code is
 *  no stronger than the download link that caller already holds.
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/wachat.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    $pnr   = Security::clean(Response::field('pnr', ''), 40);
    $phone = normalisePhone(Security::clean(Response::field('phone', ''), 20));

    if (!Security::isValidPnr($pnr)) {
        Response::invalid(['pnr' => 'Enter a valid booking reference (PNR).']);
    }

    // Each call writes a row; a page that is simply reloaded needs few.
    Security::requireRateLimit('wa_ticket_code', Security::clientIp(), 10, 600);

    if (!WaChat::enabled() || WaChat::ticketNumber() === '') {
        Response::error('WhatsApp ticket delivery is not available right now.', 503);
    }

    $detail = BookingService::detail($pnr);
    if ($detail === null || !shg_booking_viewer_owns($detail, $phone)) {
        // Same answer for "no such PNR" and "not yours": existence is a disclosure.
        Response::notFound('No booking found with that reference.');
    }
    if ((string) $detail['status'] !== 'confirmed') {
        Response::error('The ticket is sent on WhatsApp once the booking is confirmed.', 409);
    }

    $code = WaChat::mint((int) $detail['id'], 'customer');

    Response::success([
        'link'      => WaChat::buildLink($code),
        'code'      => $code,
        'message'   => WaChat::messageFor($code),
        'expiresIn' => WaChat::ttlMinutes() * 60,
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
