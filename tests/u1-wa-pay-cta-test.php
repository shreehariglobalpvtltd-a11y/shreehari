<?php
/**
 * =====================================================================
 *  u1-wa-pay-cta-test.php — WA-7 phase 1 / ON-1 (19 Nov 2026): the
 *  "Pay ₹X" button on WhatsApp. Direct UPI only - no WhatsApp Payments,
 *  nothing here confirms a payment.
 *
 *    A. sendWhatsAppCtaUrl(): Meta's interactive cta_url shape; refuses a
 *       relative URL / empty button; the header picture only over https
 *    B. Notify::bookingPlaced(), window open: ONE interactive message with
 *       "Pay ₹2,800" (exact amount) opening pay.php?pnr=…
 *    C. a redirect gateway switched on (payment_gateway = icici): the
 *       button opens the gateway page instead (PaymentAdapters::redirectUrl)
 *    D. a Nepal booking also shows the eSewa id with the NPR amount
 *       (npr_per_inr)
 *    E. window closed: no button; the registry's shg_pay_request goes
 *       once APPROVED (URL button value = the PNR)
 *    F. wa_pay_cta_on = 0: no button
 *
 *  Settings overridden in-process only. Rows: PNR SHG-2099-901(2x),
 *  phones 90000123xx / 9890000124; all removed at the end.
 *    php -c .claude/php-dev.ini tests/u1-wa-pay-cta-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('META_ACCESS_TOKEN', 'U1_TEST_TOKEN');
define('META_PHONE_NUMBER_ID', '555000111000999');
define('META_WABA_ID', '555000111000888');
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/payadapter.php';
require_once INCLUDE_PATH . '/watplmeta.php';
require_once ROOT_PATH . '/whatsapp/api.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function tset(string $k, mixed $v): void {
    Settings::get('__load__');
    $r = new ReflectionProperty(Settings::class, 'cache');
    $c = (array) $r->getValue();
    $type = is_bool($v) ? 'bool' : (is_float($v) ? 'float' : 'string');
    $c[$k] = ['value' => is_bool($v) ? ($v ? '1' : '0') : (string) $v, 'type' => $type, 'public' => false];
    $r->setValue(null, $c);
}

const U1_PNR_A = 'SHG-2099-90121';
const U1_PNR_N = 'SHG-2099-90122';
const U1_PH_A  = '9000012311';
const U1_PH_N  = '9890000124';

$cleanup = static function (): void {
    foreach (Database::fetchAll('SELECT id FROM bookings WHERE pnr IN (:a, :b)', ['a' => U1_PNR_A, 'b' => U1_PNR_N]) as $r) {
        Database::run('DELETE FROM message_logs WHERE booking_id = :b', ['b' => (int) $r['id']]);
        Database::run('DELETE FROM bookings WHERE id = :b', ['b' => (int) $r['id']]);
    }
    foreach (['%' . U1_PH_A . '%', '%' . U1_PH_N . '%'] as $like) {
        Database::run('DELETE FROM message_logs WHERE to_number LIKE :l', ['l' => $like]);
        Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey LIKE :l", ['l' => $like]);
    }
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_tpl_meta'");
};
$cleanup();

tset('whatsapp_driver', 'cloud_api');
tset('wa_policy', 'meta_only');
tset('wa_meta_templates_on', true);
tset('aisensy_mode', 'off');
tset('whatsapp_notify_customer', true);
tset('notify_customer_on_create', true);
tset('sms_notify_customer', false);
tset('upi_id', 'u1test@upi');
tset('upi_name', 'U1 Test');
tset('esewa_id', 'u1esewa');
tset('npr_per_inr', 1.6);
tset('payment_gateway', '');
tset('wa_pay_cta_on', true);
tset('wa_tpl_meta_on', true);
tset('wa_global_daily_cap', 0);
tset('wa_paid_daily_limit', '');

$graph = [];
$GLOBALS['WA_GRAPH_HOOK'] = static function (string $to, array $body, string $type) use (&$graph): array {
    $graph[] = ['to' => $to, 'type' => $type, 'body' => $body];
    return ['success' => true, 'message_id' => 'wamid.U1PAY' . count($graph), 'error' => '', 'http' => 200, 'code' => 0];
};
$route = [];
WaRoute::$transport = static function (string $to, string $text, ?string $image) use (&$route): array {
    $route[] = ['to' => $to, 'what' => $text, 'image' => $image];
    return ['success' => true, 'message_id' => 'wamid.U1R' . count($route), 'error' => ''];
};
$openWindow = static fn(string $intl) => Database::run(
    "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'u1test') ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)",
    ['k' => $intl, 'v' => (string) time()]);
$mk = static function (string $pnr, string $phone, string $cc): array {
    $id = Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => $phone, 'contact_country_code' => $cc,
        'status' => 'pending', 'total_amount' => 2800, 'source' => 'web']);
    return Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $id]) ?? [];
};
$cta = static fn(array $g): array => array_values(array_filter($g, static fn($x) => $x['type'] === 'interactive' && (($x['body']['interactive']['type'] ?? '') === 'cta_url')));

echo "\n=== WA-7 phase 1: the Pay button ===\n\n";

/* ---------------- A ---------------- */
echo "-- A. sendWhatsAppCtaUrl --\n";
$graph = [];
$r = sendWhatsAppCtaUrl('9000012311', 'Pay for SHG-1', 'Pay ₹100', 'https://x.test/pay.php?pnr=SHG-1', 'https://x.test/pay-image.php?pnr=SHG-1', 'IN');
$i = $graph[0]['body']['interactive'] ?? [];
check('accepted (stub)', $r['success'] === true);
check('interactive cta_url with the button text and URL', ($i['type'] ?? '') === 'cta_url' && ($i['action']['name'] ?? '') === 'cta_url'
    && ($i['action']['parameters']['display_text'] ?? '') === 'Pay ₹100' && ($i['action']['parameters']['url'] ?? '') === 'https://x.test/pay.php?pnr=SHG-1');
check('https picture as the header', ($i['header']['type'] ?? '') === 'image' && ($i['header']['image']['link'] ?? '') === 'https://x.test/pay-image.php?pnr=SHG-1');
check('to the right country', ($graph[0]['to'] ?? '') === '919000012311');
$graph = [];
check('a relative URL is refused before Meta', sendWhatsAppCtaUrl('9000012311', 'x', 'Pay', '/pay.php')['success'] === false && $graph === []);
check('an http picture is left out', (sendWhatsAppCtaUrl('9000012311', 'x', 'Pay', 'https://x.test/p', 'http://x.test/i.png') + ['ok' => 1]) && !isset($graph[0]['body']['interactive']['header']));

/* ---------------- B ---------------- */
echo "\n-- B. bookingPlaced inside the window --\n";
$a = $mk(U1_PNR_A, U1_PH_A, '91');
$openWindow('91' . U1_PH_A);
$graph = []; $route = [];
Notify::bookingPlaced($a);
$c = $cta($graph);
$p = $c[0]['body']['interactive']['action']['parameters'] ?? [];
check('one Pay button message', count($c) === 1, count($c) . ' cta / ' . count($graph) . ' graph / ' . count($route) . ' route');
check('button says the exact amount: "Pay ₹2,800"', ($p['display_text'] ?? '') === 'Pay ₹2,800', (string) ($p['display_text'] ?? ''));
check('button opens pay.php for this PNR', str_ends_with((string) ($p['url'] ?? ''), 'pay.php?pnr=' . U1_PNR_A));
check('header picture only over https (pay-image.php)', str_starts_with(APP_URL, 'https')
    ? str_contains((string) ($c[0]['body']['interactive']['header']['image']['link'] ?? ''), 'pay-image.php')
    : !isset($c[0]['body']['interactive']['header']));
check('no second copy (free picture / template)', $route === [] && count($graph) === 1);
check('logged as sent, purpose booking_received', Database::exists("SELECT 1 FROM message_logs WHERE booking_id = :b AND purpose = 'booking_received' AND status = 'sent'", ['b' => (int) $a['id']]));

/* ---------------- C ---------------- */
echo "\n-- C. a redirect gateway is preferred --\n";
tset('payment_gateway', 'icici');
check('redirectUrl() = the gateway page', str_contains(PaymentAdapters::redirectUrl($a), 'payment/initiate_payment.php?pnr=' . U1_PNR_A));
$graph = [];
Notify::bookingPlaced($a);
$p = $cta($graph)[0]['body']['interactive']['action']['parameters'] ?? [];
check('button opens the gateway', str_contains((string) ($p['url'] ?? ''), 'payment/initiate_payment.php?pnr=' . U1_PNR_A));
tset('payment_gateway', '');
check('no gateway: redirectUrl() is empty', PaymentAdapters::redirectUrl($a) === '');

/* ---------------- D ---------------- */
echo "\n-- D. Nepal booking: eSewa in NPR --\n";
$n = $mk(U1_PNR_N, U1_PH_N, '977');
$openWindow('977' . U1_PH_N);
$graph = [];
Notify::bookingPlaced($n);
$c = $cta($graph);
$txt = (string) ($c[0]['body']['interactive']['body']['text'] ?? '');
check('sent to 977…', ($c[0]['to'] ?? '') === '977' . U1_PH_N);
check('eSewa id with रू 4,480 (2800 × 1.6)', str_contains($txt, 'eSewa: u1esewa · रू 4,480'), mb_substr($txt, 0, 80));

// pay.php's rule: a Nepal SALE uses the Nepal company's own eSewa and the NPR frozen on the sale.
tset('nepal_esewa_id', 'u1npesewa');
Database::run("UPDATE bookings SET entity = 'NP', fx_currency = 'NPR', fx_total = 4500 WHERE id = :i", ['i' => (int) $n['id']]);
$nSale = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $n['id']]) ?? $n;
$graph = [];
Notify::bookingPlaced($nSale);
$txt = (string) ($cta($graph)[0]['body']['interactive']['body']['text'] ?? '');
check('Nepal sale: the Nepal company eSewa with the frozen रू 4,500', str_contains($txt, 'eSewa: u1npesewa · रू 4,500'), mb_substr($txt, 0, 120));
// A Nepal sale reached on an Indian number still shows the eSewa line (sale, not phone, decides).
Database::run("UPDATE bookings SET contact_phone = :p, contact_country_code = '91' WHERE id = :i", ['p' => U1_PH_A, 'i' => (int) $n['id']]);
$nIn = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $n['id']]) ?? $n;
$openWindow('91' . U1_PH_A);
$graph = [];
Notify::bookingPlaced($nIn);
$txt = (string) ($cta($graph)[0]['body']['interactive']['body']['text'] ?? '');
check('Nepal sale on a 91 number: eSewa line still shown', str_contains($txt, 'eSewa: u1npesewa · रू 4,500'), mb_substr($txt, 0, 120));
// An India sale on an India number: no eSewa line.
$graph = [];
Notify::bookingPlaced($a);
$txt = (string) ($cta($graph)[0]['body']['interactive']['body']['text'] ?? '');
check('India sale on a 91 number: no eSewa line', $txt !== '' && !str_contains($txt, 'eSewa'), mb_substr($txt, 0, 80));
tset('nepal_esewa_id', '');

/* ---------------- E ---------------- */
echo "\n-- E. window closed --\n";
Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k", ['k' => '91' . U1_PH_A]);
$graph = []; $route = [];
Notify::bookingPlaced($a);
check('no button outside the window', $cta($graph) === []);
check('shg_pay_request not used before approval', array_filter($route, static fn($x) => $x['what'] === 'template:shg_pay_request') === []);
Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_tpl_meta', 'status.shg_pay_request', :v, 'u1test')
               ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['v' => json_encode(['status' => 'APPROVED', 'at' => time()])]);
$graph = []; $route = [];
Notify::bookingPlaced($a);
check('APPROVED: shg_pay_request goes', array_filter($route, static fn($x) => $x['what'] === 'template:shg_pay_request') !== []);
check('and the booking_received template is not sent on top', array_filter($graph, static fn($x) => $x['type'] === 'template') === []);

/* ---------------- F ---------------- */
echo "\n-- F. switch off --\n";
$openWindow('91' . U1_PH_A);
tset('wa_pay_cta_on', false);
$graph = [];
Notify::bookingPlaced($a);
check('wa_pay_cta_on = 0: no button', $cta($graph) === []);

$cleanup();
unset($GLOBALS['WA_GRAPH_HOOK']);
WaRoute::$transport = null;

echo "\n" . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n";
exit($FAIL === 0 ? 0 : 1);
