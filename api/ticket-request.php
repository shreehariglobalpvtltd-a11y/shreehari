<?php
/**
 * =====================================================================
 *  POST /api/ticket-request.php — the passenger ASKS the office to change
 *  the travel date or cancel seats (P6, 15 Nov 2026).
 *
 *  Called by the ticket page (ticket/view.php). It writes ONE row in
 *  ticket_requests and rings the office bell - it never changes the
 *  booking, never cancels, moves or refunds anything, and never messages
 *  the passenger. The office does the change by hand with the existing
 *  booking tools and closes the request on admin/ticket-requests.php.
 *
 *  Authorisation: the ticket's signed key (Ticket::downloadToken, the &k=
 *  every ticket link carries), the same key verify-ticket / download-ticket
 *  and api/feedback.php accept. A PNR alone is never enough - PNRs are not
 *  secrets. A wrong key and an unknown PNR get the same neutral 403, so the
 *  endpoint cannot be used to test whether a PNR exists.
 *
 *  Request  (form or JSON; CSRF token in X-CSRF-Token or the body)
 *    { pnr, k, kind: change_date|cancel_seats|change_seat,
 *      wanted_date? (YYYY-MM-DD), seats? [..] | "L1,L2", whole?: 1,
 *      wanted_seats? "A5, A6" (change_seat, 16 Nov 2026 - P8-3e),
 *      note?, contact_phone? }
 *  Response { ok, data: { id, kind, status: 'open' }, message }
 *
 *  Budgets: TicketRequests::RL_IP per visitor IP (before anything is
 *  looked up) and TicketRequests::RL_BOOKING per ticket (after the key).
 * =====================================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/ticketrequests.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    $ip = Security::clientIp();
    if (!Security::rateLimit('ticket_request', $ip, TicketRequests::RL_IP[0], TicketRequests::RL_IP[1])) {
        Response::error('Too many requests. Please try again later, or call the office. · धेरै पटक भयो, पछि प्रयास गर्नुहोस् वा कार्यालयमा फोन गर्नुहोस्।', 429);
    }

    /* A field sent as a list (kind[]=x) is read as blank, not cast: "Array to
       string" is an ErrorException here and would answer a 500. */
    $str = static function (string $name): string {
        $v = Response::field($name, '');
        return is_scalar($v) ? (string) $v : '';
    };

    $pnr = strtoupper(Security::clean($str('pnr'), 40));
    $key = Security::clean($str('k'), 64);
    if (!Security::isValidPnr($pnr) || $key === '') {
        Response::forbidden('This ticket link cannot send requests. Please open the link we sent you. · यो लिंकबाट अनुरोध पठाउन मिलेन।');
    }

    $booking = Ticket::checkDownloadToken($pnr, $key) ? BookingService::findByPnr($pnr) : null;
    if ($booking === null) {
        Logger::warning('Ticket request refused - not the ticket key', ['pnr' => $pnr, 'ip' => $ip], 'security');
        Response::forbidden('This ticket link cannot send requests. Please open the link we sent you. · यो लिंकबाट अनुरोध पठाउन मिलेन।');
    }

    if (!Security::rateLimit('ticket_request_bk', (string) $booking['id'], TicketRequests::RL_BOOKING[0], TicketRequests::RL_BOOKING[1])) {
        Response::error('Too many requests for this ticket. Please call or WhatsApp the office. · यो टिकटका लागि धेरै अनुरोध भयो, कार्यालयमा सम्पर्क गर्नुहोस्।', 429);
    }

    $seats = Response::field('seats', []);   // a form's seats[] arrives as a list
    $res = TicketRequests::create($booking, [
        'kind'          => $str('kind'),
        'wanted_date'   => $str('wanted_date'),
        'seats'         => is_array($seats) ? array_values(array_filter($seats, 'is_scalar')) : (is_scalar($seats) ? (string) $seats : ''),
        'whole'         => $str('whole'),
        'wanted_seats'  => $str('wanted_seats'),
        'note'          => $str('note'),
        'contact_phone' => $str('contact_phone'),
    ], $ip);

    Response::success(
        ['id' => $res['id'], 'kind' => $res['kind'], 'status' => 'open'],
        'Request sent. The office will call or WhatsApp you - your ticket stays as it is until then. · अनुरोध पठाइयो। कार्यालयले फोन वा WhatsApp गर्छ - त्यतिन्जेल टिकट जस्ताको तस्तै रहन्छ।'
    );
} catch (TicketRequestRefused $e) {
    Response::error($e->getMessage(), $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 409, $e->fields);
} catch (Throwable $e) {
    Response::serverError($e);
}
