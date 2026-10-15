<?php
/**
 * download-ticket.php?pnr=...&k=...  — stream the ticket PDF.
 * Optional &invoice=1 streams the GST invoice instead.
 *
 * ACCESS (master prompt §25). The PNR is NOT a secret: nextTicketNo()
 * mints them sequentially (SHG-2026-00001, 00002, ...), so treating the
 * PNR as a bearer token let anyone count upwards and harvest every
 * passenger's name, phone, ID number, route and seat. A caller must now
 * be one of:
 *
 *   1. holding the keyed download token `k` (every link the system
 *      generates carries it — Ticket::downloadUrl());
 *   2. the customer who owns it — Auth::customerMayOpen(): this browser
 *      session booked it, or the session proved the number on the booking
 *      with the WhatsApp code (8 Oct 2026: a typed name + mobile alone is
 *      never proof, and the walk-in placeholder never is);
 *   3. a signed-in staff member allowed to see bookings.
 *
 * A link WITHOUT a key is not rejected outright — tickets already sent
 * over WhatsApp before this change carry no key, and hard-failing them
 * would strand real passengers. Those are redirected into My Bookings
 * with the PNR prefilled, where the existing phone/OTP check re-issues
 * access properly.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';

try {
    $pnr = Security::clean($_GET['pnr'] ?? '', 40);

    if (!Security::isValidPnr($pnr)) {
        http_response_code(400);
        exit('Invalid booking reference.');
    }

    Security::requireRateLimit('ticket_dl', Security::clientIp(), 30, 60);

    $booking = BookingService::findByPnr($pnr);
    if ($booking === null) {
        http_response_code(404);
        exit('No booking found with that reference.');
    }

    /* Status before authorisation, on purpose (D6-17): the app still fetches
       some tickets keylessly, and a 403 / 410 is what makes sw.js drop a
       saved copy of a ticket that is no longer valid. Once every fetch
       carries the key (D6-03), authorisation can move above this. */
    if (in_array((string) $booking['status'], ['cancelled', 'rejected'], true)) {
        http_response_code(403);
        exit('This ticket was cancelled and is not valid — यो टिकट रद्द भएको छ');
    }
    if ($booking['status'] !== 'confirmed') {
        http_response_code(403);
        exit('The ticket becomes available once your payment is verified.');
    }

    /* A voided ticket (cancelled / rejected / hard-deleted, 1 Oct 2026) is
       gone for good at this URL: 410, which also tells sw.js to drop the
       copy it saved for offline use. */
    if ((int) Database::scalar('SELECT COALESCE(MAX(is_void), 0) FROM tickets WHERE booking_id = :b', ['b' => (int) $booking['id']], 0) === 1) {
        http_response_code(410);
        exit('This ticket has been voided and is no longer valid.');
    }

    /* ---- Who may take this PDF? (see the file header) -------------- */
    $token   = Security::clean($_GET['k'] ?? '', 64);
    $allowed = Ticket::checkDownloadToken($pnr, $token);

    if (!$allowed) {
        // The customer who owns the booking: booked in this browser session,
        // or an OTP-verified owner. A typed / unverified number is not enough.
        $allowed = Auth::customerMayOpen($booking);
    }

    if (!$allowed && Auth::can('bookings.view')) {
        // Office / counter staff working the manifest — but a counter agent
        // must only see the tickets they themselves sold. Same scoping rule
        // as admin/bookings.php and api/track.php.
        $scope = Auth::bookingScopeAdminId();
        if ($scope === null || $scope === (int) ($booking['sold_by_admin_id'] ?? 0)) {
            $allowed = true;
        }
    }

    if (!$allowed) {
        // Keyless legacy link: send them somewhere that actually works
        // instead of a dead end, and record the attempt so a genuine
        // enumeration sweep is visible in the logs.
        Logger::warning('Ticket download refused — no key and not the owner', [
            'pnr' => $pnr, 'ip' => Security::clientIp(),
        ], 'security');

        header('Location: ' . appUrl('#/my?pnr=' . urlencode($pnr)), true, 302);
        exit;
    }

    /* 15 Oct 2026 (owner: "ticket ekdum fast"): authorisation is done, so
       let go of the session file NOW. PHP serialises every request of one
       session on that lock, and the booking card asks for the PNG and the
       PDF together — the second render used to queue behind the first. */
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $wantInvoice = !empty($_GET['invoice']);

    if ($wantInvoice) {
        $path = Ticket::invoicePath((int) $booking['id']);
        Response::download($path, 'invoice_' . $pnr . '.pdf', 'application/pdf');
    }

    // ?img=1 (5 Sep 2026): the PNG ticket — the primary customer format.
    // Phone-friendly HD image; WhatsApp links and the app's download
    // button use this. ?view=1 shows it inline instead of saving.
    /* ?dup=1 (19 Sep 2026): office reprint stamped DUPLICATE COPY, staff only;
       a one-off temp render, deleted once sent. */
    $dup = !empty($_GET['dup']) && Auth::can('bookings.view');
    if (!empty($_GET['img'])) {
        $path = $dup ? Ticket::pngDuplicatePath((int) $booking['id']) : Ticket::pngPath((int) $booking['id']);
        if ($dup) { register_shutdown_function(static function () use ($path) { @unlink($path); }); }
        if (!empty($_GET['view'])) {
            Response::inline($path, 'image/png');
        }
        Response::download($path, 'SHG-Ticket-' . $pnr . '.png', 'image/png');
    }

    $path = $dup ? Ticket::pdfDuplicatePath((int) $booking['id']) : Ticket::pdfPath((int) $booking['id']);
    if ($dup) { register_shutdown_function(static function () use ($path) { @unlink($path); }); }
    // ?print=1 (5 Sep 2026): open the PDF in the browser tab instead of
    // saving it, so the office "Print" button goes straight to Ctrl+P.
    if (!empty($_GET['print'])) {
        Response::inline($path, 'application/pdf');
    }
    Response::download($path, 'ticket_' . $pnr . '.pdf', 'application/pdf');
} catch (Throwable $e) {
    Logger::exception($e);
    http_response_code(500);
    exit('The ticket could not be generated. Please try again shortly.');
}
