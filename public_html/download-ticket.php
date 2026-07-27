<?php
/**
 * download-ticket.php?pnr=...  — stream the ticket PDF.
 * Optional &invoice=1 streams the GST invoice instead.
 *
 * A confirmed booking's ticket is downloadable by anyone holding the PNR,
 * since the PNR itself is the bearer token printed on the ticket.
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

    $wantInvoice = !empty($_GET['invoice']);

    if ($wantInvoice) {
        $path = Ticket::invoicePath((int) $booking['id']);
        Response::download($path, 'invoice_' . $pnr . '.pdf', 'application/pdf');
    }

    $path = Ticket::pdfPath((int) $booking['id']);
    Response::download($path, 'ticket_' . $pnr . '.pdf', 'application/pdf');
} catch (Throwable $e) {
    Logger::exception($e);
    http_response_code(500);
    exit('The ticket could not be generated. Please try again shortly.');
}
