<?php
/**
 * POST /api/payment.php — submit payment proof (UTR and/or screenshot).
 *
 * Accepts multipart/form-data when a screenshot is attached, or JSON for
 * a UTR-only submission.
 * Fields: pnr, utr, payerName, method, screenshot(file, optional),
 *         phone (the booking's mobile) and/or k (the ticket key) when the
 *         proof is sent from another device.
 *
 * 8 Oct 2026 (D8-03): proof used to be accepted for ANY PNR from ANY
 * visitor. Any UTR stops the expiry clock and re-opens a rejected
 * booking, so a junk UTR against a stranger's sequential PNR parked their
 * seats. The sender must now show they belong to the booking: the PNR was
 * booked in this browser session, OR the signed-in account owns it, OR the
 * keyed link's k, OR the typed mobile equals the booking's (never the
 * walk-in placeholder). The office still verifies every proof by hand —
 * nothing here confirms a booking.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    Security::requireRateLimit('payment_proof', Security::clientIp(), 20, 300);

    // With a file upload the body is multipart, so read from $_POST.
    $pnr       = Security::clean($_POST['pnr'] ?? Response::field('pnr', ''), 40);
    $utr       = Security::clean($_POST['utr'] ?? Response::field('utr', ''), 60);
    $payerName = Security::clean($_POST['payerName'] ?? Response::field('payerName', ''), 120);
    $method    = Security::clean($_POST['method'] ?? Response::field('method', 'upi'), 20);

    if (!Security::isValidPnr($pnr)) {
        Response::invalid(['pnr' => 'A valid booking reference is required.']);
    }

    /* ---- Does this sender belong to the booking? (before any file is written) ---- */
    $booking = BookingService::findByPnr($pnr);
    if ($booking === null) {
        Response::notFound('No booking found with that reference.');
    }
    $contact     = (string) ($booking['contact_phone'] ?? '');
    $realContact = !isPlaceholderPhone($contact);
    $me          = Auth::user();
    $key         = Security::clean($_POST['k'] ?? Response::field('k', ''), 64);
    $typed       = normalisePhone(Security::clean($_POST['phone'] ?? Response::field('phone', ''), 20));
    $owns = in_array(strtoupper((string) $booking['pnr']), Auth::ownPnrs(), true)
        || ($me !== null && (
            ((int) ($booking['user_id'] ?? 0) > 0 && (int) $booking['user_id'] === (int) ($me['id'] ?? 0))
            || ($realContact && normalisePhone((string) ($me['phone'] ?? '')) === normalisePhone($contact))
        ))
        || ($key !== '' && Ticket::checkDownloadToken((string) $booking['pnr'], $key))
        || ($realContact && $typed !== '' && $typed === normalisePhone($contact));
    if (!$owns) {
        Response::forbidden('Enter the mobile number used for this booking. / बुकिङको मोबाइल नम्बर हाल्नुहोस्।');
    }

    $hasScreenshot = isset($_FILES['screenshot']) && ($_FILES['screenshot']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

    if ($utr === '' && !$hasScreenshot) {
        Response::invalid([
            'utr' => 'Enter the transaction number or attach a payment screenshot.',
        ]);
    }

    // Screenshot first, so the payment record is marked with proof.
    $uploadInfo = null;
    if ($hasScreenshot) {
        $uploadInfo = BookingService::attachScreenshot($pnr, $_FILES['screenshot']);
    }

    $payment = BookingService::submitPaymentProof($pnr, [
        'utr'           => $utr,
        'payerName'     => $payerName,
        'method'        => $method,
        'hasScreenshot' => $hasScreenshot,
    ]);

    Response::success([
        'pnr'        => $pnr,
        'status'     => $payment['status'] ?? 'pending',
        'screenshot' => $uploadInfo['file'] ?? null,
        'verifyTime' => Settings::getString('verify_time_text', '1–3 minutes'),
    ], 'Payment proof received. We will verify it shortly.');
} catch (RuntimeException $e) {
    Response::error($e->getMessage(), 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
