<?php
/**
 * ICICI Payment Gateway - Return URL Handler (joined to bookings, 6 Oct 2026)
 * shreehariglobal.in
 *
 * The bank sends the customer's browser back here after payment.
 * URL: https://www.shreehariglobal.in/payment/payment_return.php
 *
 * The booking is found from merchantTxnNo (no session: the site cookie is
 * SameSite=Lax, so it is not sent on the bank's cross-site POST). The
 * result is shown on this page itself.
 */

declare(strict_types=1);

require_once __DIR__ . '/icici_booking.php';

$responseData = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
if ($responseData === []) {
    http_response_code(403);
    icici_render_page('Unauthorized', '<p>Direct access is not allowed.</p>', 'bad');
    exit;
}

icici_log('RETURN_RECEIVED', $responseData);

$r   = icici_apply_result($responseData, 'RETURN');
$pnr = (string) ($r['booking']['pnr'] ?? '');
$pnrLine = $pnr !== '' ? '<p>बुकिङ नं.: <code>' . icici_h($pnr) . '</code></p>' : '';
$test    = icici_is_uat() ? '<p class="test">UAT TEST — यो परीक्षण भुक्तानी हो, असली पैसा होइन।</p>' : '';

switch ($r['state']) {
    case 'success':
        icici_render_page('✅ भुक्तानी प्राप्त भयो',
            $pnrLine
            . '<p>तपाईंको भुक्तानी बैंकबाट प्राप्त भयो। हाम्रो कार्यालयले पुष्टि गरेपछि टिकट WhatsApp मा पठाइन्छ।</p>'
            . '<p>Payment received. Your ticket will be sent on WhatsApp once our office confirms it.</p>' . $test);
        break;
    case 'pending':
        icici_render_page('⏳ भुक्तानी प्रक्रियामा छ',
            $pnrLine
            . '<p>बैंकले भुक्तानी अझै प्रक्रिया गर्दैछ। फेरि भुक्तानी नगर्नुहोस् — पुष्टि भएपछि हामी खबर गर्छौं।</p>'
            . '<p>The bank is still processing this payment. Please do not pay again.</p>' . $test, 'wait');
        break;
    case 'failed':
        $retry = $pnr !== '' ? '<p><a href="initiate_payment.php?pnr=' . rawurlencode($pnr) . '">फेरि प्रयास गर्नुहोस् · Try again</a></p>' : '';
        icici_render_page('❌ भुक्तानी सफल भएन',
            $pnrLine
            . '<p>' . icici_h($r['message'] !== '' ? $r['message'] : 'Payment was not completed.') . '</p>'
            . '<p>पैसा काटिएको भए हाम्रो कार्यालयलाई WhatsApp गर्नुहोस्।</p>' . $retry . $test, 'bad');
        break;
    default:
        http_response_code(400);
        icici_render_page('❌ भुक्तानी जाँच असफल',
            '<p>We could not verify this payment response. If money was taken, please contact our office on WhatsApp.</p>', 'bad');
}
