<?php
/**
 * ICICI Payment Gateway - Check Transaction Status (staff only, 6 Oct 2026)
 * shreehariglobal.in
 *
 * Signed-in admin/staff only (it used to answer anyone with the bank's raw reply).
 *   https://www.shreehariglobal.in/payment/check_payment_status.php?txn_no=SHG436T20261006003228123
 *
 * Read-only: shows what the bank says. It does not change the booking —
 * the return page / webhook record payments, and staff confirm them.
 */

declare(strict_types=1);

require_once __DIR__ . '/icici_booking.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (Auth::admin() === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Staff sign-in required']);
    exit;
}

$merchantTxnNo = trim((string) ($_GET['txn_no'] ?? $_POST['txn_no'] ?? ''));
if (icici_booking_id_from_txn($merchantTxnNo) === null && !preg_match('/^[A-Za-z0-9_]{6,40}$/', $merchantTxnNo)) {
    http_response_code(400);
    echo json_encode(['error' => 'valid txn_no parameter required']);
    exit;
}

$requestData = [
    'aggregatorID'    => ICICI_AGGREGATOR_ID,
    'merchantId'      => ICICI_MERCHANT_ID,
    'merchantTxnNo'   => $merchantTxnNo,
    'originalTxnNo'   => $merchantTxnNo,
    'transactionType' => 'STATUS',
    'txnDate'         => date('YmdHis'),
];
$requestData['secureHash'] = icici_generate_secure_hash($requestData);

icici_log('STATUS_CHECK_REQUEST', ['merchantTxnNo' => $merchantTxnNo]);

$response = icici_api_post(ICICI_URL_COMMAND . '?reqType=JSON', $requestData);
if (!$response) {
    icici_log('STATUS_CHECK_ERROR', ['merchantTxnNo' => $merchantTxnNo]);
    http_response_code(502);
    echo json_encode(['error' => 'API call failed']);
    exit;
}

icici_log('STATUS_CHECK_RESPONSE', ['merchantTxnNo' => $merchantTxnNo] + $response);

$responseCode = (string) ($response['responseCode'] ?? '');
$status = in_array($responseCode, ICICI_SUCCESS_CODES, true) ? 'SUCCESS'
        : (in_array($responseCode, ICICI_PENDING_CODES, true) ? 'PENDING' : 'FAILED');

$bid = icici_booking_id_from_txn($merchantTxnNo);
$pnr = $bid !== null ? (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => $bid], '') : '';

echo json_encode([
    'status'        => $status,
    'environment'   => ICICI_ENVIRONMENT,
    'pnr'           => $pnr,
    'responseCode'  => $responseCode,
    'description'   => $response['respDescription'] ?? '',
    'merchantTxnNo' => $merchantTxnNo,
    'txnID'         => $response['txnID'] ?? '',
    'amount'        => $response['amount'] ?? '',
    'paymentMode'   => $response['paymentMode'] ?? '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
