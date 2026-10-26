<?php
/**
 * GET /api/ticket-live.php?pnr=SHG-...&k=<ticket key>
 *
 * The live part of the passenger's on-screen ticket (9 Oct 2026, anti-fake;
 * owner: "ticket fake badi hun layo"). The app's #ticketCard shows a moving
 * security band with a ticking clock and a 4-digit code that changes every
 * 30 seconds. A screenshot or an edited picture freezes both; the gate
 * scanner (admin/scan.php) shows the code a live screen must read right now.
 *
 * Who may ask: the ticket key (k = Ticket::downloadToken), the owning
 * customer (Auth::customerMayOpen - booked in this browser session or an
 * OTP-verified owner) or signed-in staff who may see the booking. Anyone
 * else - and a PNR that does not exist - gets the same 403, so the answer
 * says nothing about which references are real.
 *
 * Returns {ok, data:{pnr, status, live, code, step, validFor, serverTime,
 * serverMs, securityCode}}. code is null unless the ticket is confirmed,
 * not voided and the band is switched on (ticket_live_code_on). The code is
 * never stored and never sent in a message.
 *
 * Rate limit: 'ticket_live' 120 per minute per IP (the app polls every 30 s
 * while the ticket is on screen; many phones can share one carrier IP).
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/paymentrisk.php';

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        Response::error('Method not allowed.', 405);
    }
    /* 120 a minute per IP: one phone polls twice a minute, but Indian mobile
       carriers put many phones behind one CGNAT address - at a boarding point
       a tight limit would turn genuine tickets "OFFLINE". The answer is cheap
       (one HMAC + two indexed reads) and never says whether a PNR exists. */
    Security::requireRateLimit('ticket_live', Security::clientIp(), 120, 60);

    $pnr = strtoupper(Security::clean((string) ($_GET['pnr'] ?? ''), 40));
    $key = Security::clean((string) ($_GET['k'] ?? ''), 64);
    $deny = static function (): never {
        Response::forbidden('This ticket cannot be checked from here. / यहाँबाट यो टिकट जाँच्न मिलेन।');
    };
    if (!Security::isValidPnr($pnr)) {
        $deny();
    }

    $booking = BookingService::findByPnr($pnr);
    if ($booking === null) {
        $deny();
    }

    $allowed = ($key !== '' && Ticket::checkDownloadToken((string) $booking['pnr'], $key))
        || Auth::customerMayOpen($booking);
    if (!$allowed && (Auth::can('tickets.scan') || Auth::can('bookings.view'))) {
        $scope = Auth::bookingScopeAdminId();
        $allowed = $scope === null || $scope === (int) ($booking['sold_by_admin_id'] ?? 0);
    }
    if (!$allowed) {
        $deny();
    }

    $ticket = Database::fetch(
        'SELECT is_void, scanned_at FROM tickets WHERE booking_id = :b ORDER BY id DESC LIMIT 1',
        ['b' => (int) $booking['id']]
    );
    $status = (string) $booking['status'];
    if ($status === 'confirmed' && $ticket !== null && (int) $ticket['is_void'] === 1) {
        $status = 'void';
    }
    $live = $status === 'confirmed' && $ticket !== null && TicketLive::on();

    $now = time();
    header('Cache-Control: no-store');
    Response::success([
        'pnr'          => (string) $booking['pnr'],
        'status'       => $status,
        'boarded'      => $ticket !== null && !empty($ticket['scanned_at']),
        'live'         => $live,
        'code'         => $live ? TicketLive::code((string) $booking['pnr'], $now) : null,
        'step'         => TicketLive::STEP,
        'validFor'     => TicketLive::validFor($now),
        'serverTime'   => date('c', $now),
        'serverMs'     => (int) round(microtime(true) * 1000),
        'securityCode' => $live ? TicketLive::securityCode((string) $booking['pnr']) : null,
    ]);
} catch (Throwable $e) {
    Response::serverError($e);
}
