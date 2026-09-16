<?php
/**
 * POST /api/payment.php — submit payment proof (UTR and/or screenshot).
 *
 * Accepts multipart/form-data when a screenshot is attached, or JSON for
 * a UTR-only submission.
 * Fields: pnr, utr, payerName, method, screenshot(file, optional)
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
