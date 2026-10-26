<?php
/**
 * ICICI Payment Gateway - Initiate Payment (joined to bookings, 6 Oct 2026)
 * shreehariglobal.in
 *
 * USE:  https://www.shreehariglobal.in/payment/initiate_payment.php?pnr=SHG-2026-00436
 *
 * Only the PNR is taken from the request. Amount, name, phone and email
 * come from the booking itself, so the price cannot be edited in the URL.
 */

declare(strict_types=1);

require_once __DIR__ . '/icici_booking.php';

$pnr = strtoupper(trim((string) ($_REQUEST['pnr'] ?? '')));
if ($pnr === '' || !preg_match('/^[A-Z0-9-]{6,40}$/', $pnr)) {
    http_response_code(400);
    icici_render_page('बुकिङ नं. चाहिन्छ', '<p>Valid booking reference (PNR) is required.</p>', 'bad');
    exit;
}

if (!Security::rateLimit('icici_initiate', Security::clientIp(), 10, 300)) {
    http_response_code(429);
    icici_render_page('केही बेरपछि प्रयास गर्नुहोस्', '<p>Too many attempts. Please wait a few minutes.</p>', 'wait');
    exit;
}

$booking = icici_load_booking($pnr);
if ($booking === null) {
    http_response_code(404);
    icici_render_page('बुकिङ भेटिएन', '<p>Booking <code>' . icici_h($pnr) . '</code> not found.</p>', 'bad');
    exit;
}

$why = icici_cannot_pay_reason($booking);
if ($why !== '') {
    icici_render_page('भुक्तानी गर्न मिल्दैन', '<p>' . icici_h($why) . '</p><p>बुकिङ नं.: <code>' . icici_h($pnr) . '</code></p>', 'wait');
    exit;
}

$amount   = icici_amount_for($booking);
$txnNo    = icici_txn_for_booking((int) $booking['id']);
$mobile   = preg_replace('/\D/', '', (string) ($booking['contact_phone'] ?? ''));
$mobile   = strlen($mobile) >= 10 ? substr($mobile, -10) : '9999999999';
$email    = filter_var((string) ($booking['contact_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: 'customer@shreehariglobal.in';
$name     = (string) Database::scalar(
    'SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY id LIMIT 1',
    ['b' => $booking['id']],
    ''
);
$name     = trim(preg_replace('/[^\p{L}\p{N} .]/u', '', $name) ?? '');

$requestData = [
    'aggregatorID'     => ICICI_AGGREGATOR_ID,
    'amount'           => $amount,
    'currencyCode'     => '356',                    // 356 = INR
    'customerEmailID'  => $email,
    'customerMobileNo' => $mobile,
    'customerName'     => $name !== '' ? mb_substr($name, 0, 50) : 'Customer',
    'merchantId'       => ICICI_MERCHANT_ID,
    'merchantTxnNo'    => $txnNo,
    'payType'          => 0,                        // 0 = all payment modes
    'returnURL'        => PAYMENT_RETURN_URL,
    'transactionType'  => 'SALE',
    'txnDate'          => date('YmdHis'),           // IST (icici_config sets the zone)
];
$requestData['secureHash'] = icici_generate_secure_hash($requestData);

icici_log('INITIATE_REQUEST', ['pnr' => $pnr, 'merchantTxnNo' => $txnNo, 'amount' => $amount]);

$response = icici_api_post(ICICI_URL_INITIATE_SALE, $requestData);
if (!$response) {
    icici_log('INITIATE_ERROR', ['pnr' => $pnr, 'merchantTxnNo' => $txnNo]);
    icici_render_page('बैंकसँग जडान भएन', '<p>Could not reach the bank. Please try again in a minute, or pay by UPI.</p>', 'bad');
    exit;
}

icici_log('INITIATE_RESPONSE', ['pnr' => $pnr] + $response);

if (isset($response['redirectURI'], $response['tranCtx'])) {
    PayEvents::log((int) $booking['id'], $pnr, 'icici_open');
    header('Location: ' . $response['redirectURI'] . '?tranCtx=' . urlencode((string) $response['tranCtx']), true, 302);
    exit;
}

$errorMsg = (string) ($response['respDescription'] ?? $response['message'] ?? 'Unknown error');
icici_log('INITIATE_FAILED', [
    'pnr'          => $pnr,
    'responseCode' => $response['responseCode'] ?? '',
    'message'      => $errorMsg,
    'merchantTxnNo'=> $txnNo,
]);
icici_render_page('भुक्तानी सुरु भएन', '<p>' . icici_h($errorMsg) . '</p><p>Please try again or pay by UPI.</p>', 'bad');
