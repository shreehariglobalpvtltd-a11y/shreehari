<?php
/**
 * ICICI UAT Sandbox Test Script
 * shreehariglobal.in
 *
 * RUN GARNU: browser ma open garnu
 * https://shreehariglobal.in/payment/test_sandbox.php
 *
 * PRODUCTION SERVER MA DELETE GARNU!
 * Yo file sirf testing ko lagi ho.
 */

require_once __DIR__ . '/icici_config.php';

echo "<h2>🏦 ICICI PG Sandbox Test - shreehariglobal.in</h2>";
echo "<p>Environment: <strong>" . ICICI_ENVIRONMENT . "</strong></p>";
echo "<pre>";

// ── Test 1: secureHash generation ──────────────────────────
echo "=== TEST 1: secureHash Generation ===\n";

$testData = [
    'aggregatorID'    => ICICI_AGGREGATOR_ID,
    'amount'          => '1.00',
    'currencyCode'    => '356',
    'customerEmailID' => 'test@shreehariglobal.in',
    'customerMobileNo'=> '9999999999',
    'customerName'    => 'Test Customer',
    'merchantId'      => ICICI_MERCHANT_ID,
    'merchantTxnNo'   => 'TEST_' . time(),
    'payType'         => 0,
    'returnURL'       => PAYMENT_RETURN_URL,
    'transactionType' => 'SALE',
    'txnDate'         => date('YmdHis'),
];

$hash = icici_generate_secure_hash($testData);
echo "MID: " . ICICI_MERCHANT_ID . "\n";
echo "Agg ID: " . ICICI_AGGREGATOR_ID . "\n";
echo "Generated Hash: " . $hash . "\n\n";

// ── Test 2: Live UAT Initiate Sale ─────────────────────────
echo "=== TEST 2: Initiate Sale API Call (UAT) ===\n";

$testData['secureHash'] = $hash;

echo "Calling: " . ICICI_URL_INITIATE_SALE . "\n";
echo "Payload: " . json_encode($testData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

$response = icici_api_post(ICICI_URL_INITIATE_SALE, $testData);

if ($response) {
    echo "Response:\n" . json_encode($response, JSON_PRETTY_PRINT) . "\n\n";

    if (isset($response['redirectURI'])) {
        $payUrl = $response['redirectURI'] . '?tranCtx=' . urlencode($response['tranCtx']);
        echo "✅ SUCCESS! Payment URL:\n" . $payUrl . "\n\n";
        echo "</pre>";
        echo "<h3>✅ API Working!</h3>";
        echo "<p><a href='" . htmlspecialchars($payUrl) . "' target='_blank'>";
        echo "<button style='background:#ff6600;color:white;padding:10px 20px;font-size:16px;'>👉 ICICI Payment Page Open Garnu</button></a></p>";
        echo "<p><strong>Test Card:</strong> 4761 3400 0000 0035 | Expiry: 12/26 | CVV: 123 | OTP: 123456</p>";
        echo "<p><strong>Test UPI:</strong> test@ybl</p>";
        echo "<p><strong>Net Banking:</strong> CC Avenue Test Bank | OTP: 123456</p>";
    } else {
        echo "❌ No redirectURI in response. Check credentials.\n";
        echo "</pre>";
    }
} else {
    echo "❌ API call failed. Check server logs.\n";
    echo "</pre>";
}

echo "\n<hr><p style='color:red'><strong>⚠️ DELETE test_sandbox.php before going live!</strong></p>";
