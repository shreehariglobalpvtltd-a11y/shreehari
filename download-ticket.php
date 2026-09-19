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
 *   2. the signed-in customer whose mobile number is on the booking;
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

    if ($booking['status'] !== 'confirmed') {
        http_response_code(403);
        exit('The ticket becomes available once your payment is verified.');
    }

    /* ---- Who may take this PDF? (see the file header) -------------- */
    $token   = Security::clean($_GET['k'] ?? '', 64);
    $allowed = Ticket::checkDownloadToken($pnr, $token);

    if (!$allowed) {
        // The customer who owns the booking, identified by the mobile
        // number on it rather than by knowing the reference.
        $user = Auth::user();
        if ($user !== null && normalisePhone((string) ($user['phone'] ?? '')) !== ''
            && normalisePhone((string) ($user['phone'] ?? '')) === normalisePhone((string) ($booking['contact_phone'] ?? ''))) {
            $allowed = true;
        }
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
