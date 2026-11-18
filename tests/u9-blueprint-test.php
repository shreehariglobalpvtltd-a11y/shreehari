<?php
/**
 * u9-blueprint-test.php — the owner's 16 Nov blueprint gaps (19 Nov 2026, unit u9).
 *
 *   pay.php      Google Pay / PhonePe / Paytm buttons (tez:// phonepe:// paytmmp://) with the exact
 *                amount and tn=PNR beside the green any-UPI button; the exact-amount QR at 300 px;
 *                eSewa in रू (INR x npr_per_inr, or the NPR frozen on a Nepal sale - then first);
 *                an optional screenshot on the UTR claim lands in payment_screenshots, the money row
 *                stays cod_pending with mode 'utr' and no proof-uploaded event (review fix); a
 *                pending online booking keeps the 302 unless pay_app_chooser_on = 1 or it is a
 *                Nepal sale with eSewa (pay_nepal_chooser_on); there it can send UTR / screenshot
 *                through the website proof path (ownership, dup-UTR refused, stays pending);
 *                pay_upi_app_buttons = 0 hides the three buttons; eSewa holder name matches the wallet.
 *   WaPayProof   a UTR already on ANOTHER booking is refused (screenshot and typed), the office
 *                gets a bell, nothing is stored on the booking.
 *   WaBot        office words report / agents / revenue (office numbers only, full-number match),
 *                status, track, "resend <name> <mobile>" (a bare name + mobile stays WaBooking's
 *                quick order; mocked send; same words on hit/miss; per-booking 6 h and per-sender
 *                caps; past trips never), "ticket" gets the website link.
 *   api/search   seatsLow + 'केवल N सिट बाँकी · Only N seats left' from the same availability number.
 *
 * Everything it makes is removed at the end (phones 90000190xx, names ZZTEST U9 …, date 2099-11-19).
 *
 *   SHG_TEST_BASE=http://localhost:8963 php -c .claude/php-dev.ini tests/u9-blueprint-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/payadapter.php';
require_once INCLUDE_PATH . '/wapayproof.php';
require_once INCLUDE_PATH . '/wabot.php';
require_once INCLUDE_PATH . '/officereport.php';
require_once INCLUDE_PATH . '/paymentrisk.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded\n"; exit(1); }
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8963'), '/'));
const TD     = '2099-11-19';
const PHONES = ['9000019001', '9000019002', '9000019003', '9000019004'];
const NEW_KEYS = ['pay_app_chooser_on', 'pay_upi_app_buttons', 'search_low_seats_at', 'pay_nepal_chooser_on', 'pay_pending_proof_on'];

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$JAR = sys_get_temp_dir() . '/shg_u9_' . getmypid() . '.txt';
@unlink($JAR);
function http(string $url, ?array $multipart = null): array {
    global $JAR;
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR, CURLOPT_TIMEOUT => 90, CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14) u9-test']);
    if ($multipart !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart); }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? html_entity_decode($m[1]) : '';
}
/** In-process only: a setting this PHP process sees, never written to the shared test DB. */
function localSetting(string $k, string $v, string $type = 'string'): void {
    $rp = new ReflectionProperty('Settings', 'cache');
    Settings::get('company_name');                          // loads the cache
    $c = $rp->getValue();
    $c[$k] = ['value' => $v, 'type' => $type, 'public' => false];
    $rp->setValue(null, $c);
}
/** A NEW key only this unit reads (written to the DB for the web server, removed at the end). */
function dbSetting(string $k, string $v, string $type): void {
    Settings::set($k, $v, $type, 'payments', false);
    Settings::flush();
}
function cleanup(): void {
    foreach (PHONES as $p) {
        foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            $bid = (int) $r['id'];
            try { Database::run("DELETE FROM rate_limits WHERE bucket = 'wa_find_resend_to' AND identifier = :i", ['i' => 'b' . $bid]); } catch (Throwable $e) {}
            foreach (Database::fetchAll('SELECT file_path FROM payment_screenshots WHERE booking_id = :b', ['b' => $bid]) as $s) {
                @unlink(UPLOAD_PATH . '/' . $s['file_path']);
            }
            foreach (['payment_screenshots', 'notifications', 'payment_events', 'payment_reviews', 'tickets', 'booking_collections'] as $tb) {
                try { Database::run('DELETE FROM ' . $tb . ' WHERE booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
            }
            foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
            try { Database::run('DELETE FROM agent_wallet WHERE booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
            Database::delete('bookings', 'id = :i', ['i' => $bid]);
        }
        try { Database::run("DELETE FROM rate_limits WHERE identifier LIKE :p", ['p' => '%' . $p . '%']); } catch (Throwable $e) {}
        try { Database::run("DELETE FROM kv_store WHERE kkey LIKE :p", ['p' => '%' . $p . '%']); } catch (Throwable $e) {}
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
    foreach (NEW_KEYS as $k) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
    Settings::flush();
}

echo "\n=== u9: blueprint gaps — UPI app buttons + eSewa रू, duplicate UTR, bot words, seats-left badge ===\n\n";
cleanup();
try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('pay_open', 'payment_proof')"); } catch (Throwable $e) {}
$keep = [];
foreach (['whatsapp_notify_customer', 'whatsapp_notify_admin', 'allow_cod', 'email_notify_customer'] as $k) {
    $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}
try {
    /* no message leaves this test: the web server reads these too (put back exactly as found) */
    foreach (['whatsapp_notify_customer', 'whatsapp_notify_admin', 'email_notify_customer'] as $k) { Settings::set($k, '0', 'bool', 'notify', false); }
    Settings::set('allow_cod', '1', 'bool', 'booking', false);
    Settings::flush();
    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $sched   = Seats::schedule($routeId, TD);
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => (int) $sched['id']]);
    $mk = static fn(string $seat, string $name, string $phone, bool $cod): array => BookingService::create(['routeId' => $routeId, 'travelDate' => TD, 'seats' => [$seat],
        'passengers' => [['name' => $name, 'age' => 30, 'gender' => 'Male']], 'contact' => ['phone' => $phone],
        'bookingMode' => 'sharing', 'cabinType' => 'single', 'paymentMethod' => $cod ? 'cod' : 'upi', 'isCod' => $cod, 'boarding' => '']);
    $a = $mk('L8', 'ZZTEST Uninea Board', PHONES[0], true);     // pay at boarding (COD)
    $d = $mk('L10', 'ZZTEST Uninea Online', PHONES[1], false);  // pending online
    $n = $mk('L12', 'ZZTEST Uninea Nepal', PHONES[2], true);    // a Nepal sale (frozen NPR)
    check('seeded: A + N confirmed pay-at-boarding, D pending online', (string) $a['status'] === 'confirmed' && (int) $a['is_cod'] === 1
        && (string) $d['status'] === 'pending' && (string) $n['status'] === 'confirmed');
    $pnrA = (string) $a['pnr']; $pnrD = (string) $d['pnr']; $pnrN = (string) $n['pnr'];
    $dueA = (float) $a['total_amount'];
    $amA  = number_format($dueA, 2, '.', '');
    $esewaOn = trim(Settings::getString('esewa_id', '')) !== '';

    /* ---------------------------------------------------------------- pay.php */
    echo "\n-- pay.php: UPI app buttons, 300 px QR, eSewa रू --\n";
    $r = http('/pay.php?pnr=' . rawurlencode($pnrA) . '&k=' . Ticket::downloadToken($pnrA));
    $b = $r['body'];
    check('A: 200, no 302', $r['code'] === 200 && !preg_match('/^Location:/mi', $r['headers']), (string) $r['code']);
    foreach ([['Google Pay', 'tez://upi/pay?'], ['PhonePe', 'phonepe://pay?'], ['Paytm', 'paytmmp://pay?']] as [$app, $scheme]) {
        $ok = (bool) preg_match('/href="' . preg_quote(htmlspecialchars($scheme), '/') . '[^"]*am=' . preg_quote($amA, '/') . '[^"]*tn=' . preg_quote(rawurlencode($pnrA), '/') . '[^"]*"[^>]*>' . preg_quote($app, '/') . '/u', $b);
        check("A: $app button with the exact amount and tn=PNR", $ok);
    }
    check('A: the green any-UPI button stays (upi://pay, same amount) + BHIM caption',
        (bool) preg_match('/<a class="btn" href="upi:\/\/pay\?[^"]*am=' . preg_quote($amA, '/') . '[^"]*">UPI app मा खोल्नुहोस्<\/a>/u', $b) && str_contains($b, 'BHIM or any UPI app'));
    check('A: the UPI QR is 300 px', (bool) preg_match('/<img class="qr" src="data:image\/png;base64,[^"]+" alt="UPI QR[^"]*" width="300" height="300">/u', $b) && str_contains($b, '.qr { width:300px;'));
    if ($esewaOn) {
        $npr = number_format(round($dueA * Settings::getFloat('npr_per_inr', 1.6)));
        check('A: eSewa amount in रू = INR due x npr_per_inr, with the ₹ beside it', str_contains($b, '<b class="npr">रू ' . $npr . '</b> (₹' . number_format($dueA, 2) . ')'), 'रू ' . $npr);
        check('A (India sale): the UPI QR comes before eSewa', strpos($b, 'alt="UPI QR') < strpos($b, '🇳🇵 eSewa'));
    }
    check('A: the claim form takes an optional screenshot (multipart)', str_contains($b, 'enctype="multipart/form-data"') && str_contains($b, 'name="shot"'));

    // a Nepal sale: entity NP + NPR frozen at the desk -> eSewa first, the frozen रू
    Database::update('bookings', ['entity' => 'NP', 'fx_currency' => 'NPR', 'fx_rate' => 1.6, 'fx_total' => 7777], 'id = :i', ['i' => (int) $n['id']]);
    $r = http('/pay.php?pnr=' . rawurlencode($pnrN) . '&k=' . Ticket::downloadToken($pnrN));
    $co = Settings::companyFor('', 'NP');
    if (trim((string) ($co['payEsewa'] ?? '')) !== '') {
        check('N (Nepal sale): eSewa shows the frozen NPR (रू 7,777), not the live rate', str_contains($r['body'], 'रू 7,777'));
        check('N: eSewa comes BEFORE the UPI QR', strpos($r['body'], '🇳🇵 eSewa') !== false && strpos($r['body'], '🇳🇵 eSewa') < strpos($r['body'], 'alt="UPI QR'));
    } else {
        check('N: no eSewa configured for the Nepal company - none shown', !str_contains($r['body'], 'eSewa ID:'));
    }

    // the claim with a screenshot
    $png = sys_get_temp_dir() . '/shg_u9_shot_' . getmypid() . '.png';
    $im = imagecreatetruecolor(120, 80); imagefilledrectangle($im, 0, 0, 119, 79, imagecolorallocate($im, 20, 140, 60));
    imagestring($im, 3, 8, 30, 'ZZTEST U9 ' . random_int(1000, 9999), imagecolorallocate($im, 255, 255, 255)); imagepng($im, $png); imagedestroy($im);
    do { $utr = '7' . (string) random_int(10000000000, 99999999999); }
    while (Database::exists('SELECT 1 FROM payments WHERE utr_number = :u', ['u' => $utr]) || PaymentRisk::utrLooksWrong($utr, 'upi') !== null);
    $tok = csrfFrom($b);
    $r = http('/pay.php?pnr=' . rawurlencode($pnrA) . '&k=' . Ticket::downloadToken($pnrA),
        [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => $utr, 'shot' => new CURLFile($png, 'image/png', 'pay.png')]);
    $pA = Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $a['id']]);
    check('claim + screenshot: 303, UTR on the money row, still cod_pending (never auto-confirmed)', $r['code'] === 303
        && ($pA['utr_number'] ?? '') === $utr && ($pA['status'] ?? '') === 'cod_pending', $r['code'] . ' ' . ($pA['status'] ?? ''));
    check('the screenshot is in payment_screenshots for the office (on the claim\'s money row, sha256 kept)',
        Database::exists('SELECT 1 FROM payment_screenshots WHERE booking_id = :b AND payment_id = :p AND sha256 IS NOT NULL', ['b' => (int) $a['id'], 'p' => (int) ($pA['id'] ?? 0)]));
    check('review fix: no attachScreenshot side effects - payments.mode stays utr, own audit row, no "proof uploaded" customer path',
        ($pA['mode'] ?? '') === 'utr'
        && Database::exists("SELECT 1 FROM audit_logs WHERE action = 'payment.cod_claim_screenshot' AND entity_id = :p", ['p' => $pnrA]), (string) ($pA['mode'] ?? ''));
    check('booking still confirmed + pay on boarding', (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $a['id']], '') === 'confirmed');
    @unlink($png);

    echo "\n-- pay.php: pending online booking --\n";
    $r = http('/pay.php?pnr=' . rawurlencode($pnrD));
    check('D (pending, chooser off): the old 302 into upi://', $r['code'] === 302 && (bool) preg_match('/^Location: upi:\/\/pay\?/mi', $r['headers']), (string) $r['code']);
    dbSetting('pay_app_chooser_on', '1', 'bool');
    $r = http('/pay.php?pnr=' . rawurlencode($pnrD));
    check('D (pay_app_chooser_on=1): 200 page, no redirect, app buttons + 300 px QR + the proof box (UTR or screenshot)', $r['code'] === 200
        && !preg_match('/^Location:/mi', $r['headers']) && str_contains($r['body'], 'tez://upi/pay?') && str_contains($r['body'], 'width="300"')
        && str_contains($r['body'], 'name="utr"') && str_contains($r['body'], 'name="shot"') && str_contains($r['body'], 'Send the UTR or the screenshot')
        && !str_contains($r['body'], 'location.href'), (string) $r['code']);
    $pageD = $r['body'];
    dbSetting('pay_pending_proof_on', '0', 'bool');
    $r = http('/pay.php?pnr=' . rawurlencode($pnrD));
    check('pay_pending_proof_on=0: no proof box, the "send it on WhatsApp" note', !str_contains($r['body'], 'name="utr"') && str_contains($r['body'], 'send the UTR or the screenshot on WhatsApp'));
    Database::delete('settings', 'skey = :k', ['k' => 'pay_pending_proof_on']);
    dbSetting('pay_upi_app_buttons', '0', 'bool');
    $r = http('/pay.php?pnr=' . rawurlencode($pnrD));
    check('pay_upi_app_buttons=0: no app buttons, the green upi button stays', !str_contains($r['body'], 'tez://') && str_contains($r['body'], 'UPI app मा खोल्नुहोस्'));
    Database::delete('settings', 'skey = :k', ['k' => 'pay_app_chooser_on']);
    Database::delete('settings', 'skey = :k', ['k' => 'pay_upi_app_buttons']);

    // spec gap: a NEPAL pending online sale gets the page (eSewa रू first) even with the chooser off
    $entD = Database::fetch('SELECT entity FROM bookings WHERE id = :i', ['i' => (int) $d['id']])['entity'] ?? null;
    Database::update('bookings', ['entity' => 'NP'], 'id = :i', ['i' => (int) $d['id']]);
    $coNp = Settings::companyFor('', 'NP');
    $r = http('/pay.php?pnr=' . rawurlencode($pnrD));
    if (trim((string) ($coNp['payEsewa'] ?? '')) !== '') {
        check('D as a Nepal sale (chooser off): 200 page, eSewa रू first, no 302', $r['code'] === 200 && !preg_match('/^Location:/mi', $r['headers'])
            && strpos($r['body'], '🇳🇵 eSewa') !== false && strpos($r['body'], '🇳🇵 eSewa') < strpos($r['body'], 'alt="UPI QR'), (string) $r['code']);
        $nm = trim(Settings::getString('nepal_esewa_id', '')) !== '' ? trim(Settings::getString('nepal_esewa_name', '')) : trim(Settings::getString('esewa_name', ''));
        check('review fix: the eSewa holder name belongs to the wallet shown', $nm === '' ? !preg_match('/eSewa ID: <code>[^<]*<\/code> · /u', $r['body'])
            : str_contains($r['body'], ' · ' . htmlspecialchars($nm, ENT_QUOTES, 'UTF-8') . '</div>'));
        dbSetting('pay_nepal_chooser_on', '0', 'bool');
        $r = http('/pay.php?pnr=' . rawurlencode($pnrD));
        check('pay_nepal_chooser_on=0: the Nepal sale keeps the old 302', $r['code'] === 302, (string) $r['code']);
        Database::delete('settings', 'skey = :k', ['k' => 'pay_nepal_chooser_on']);
    } else {
        check('D as a Nepal sale with no eSewa anywhere: the old 302 (nothing extra to show)', $r['code'] === 302, (string) $r['code']);
    }
    Database::update('bookings', ['entity' => $entD], 'id = :i', ['i' => (int) $d['id']]);
    Settings::flush();

    /* ---------------------------------------------------------------- duplicate UTR */
    echo "\n-- WaPayProof: a UTR already on another booking is refused --\n";
    check('utrUsedElsewhere: A\'s UTR seen from D = A\'s PNR; from A itself = none',
        WaPayProof::utrUsedElsewhere($utr, (int) $d['id']) === $pnrA && WaPayProof::utrUsedElsewhere($utr, (int) $a['id']) === '');
    check('… typed in groups of four it still matches', WaPayProof::utrUsedElsewhere(substr($utr, 0, 4) . ' ' . substr($utr, 4, 4) . '-' . substr($utr, 8), (int) $d['id']) === $pnrA);
    check('junk ("na") is never a UTR', WaPayProof::utrUsedElsewhere('na', 0) === '');
    localSetting('wa_pay_ocr_on', '1', 'bool');
    WaPayProof::$fetcher = static fn(string $id): array => ['bytes' => 'x', 'mime' => 'image/jpeg'];
    WaPayProof::$reader  = static fn(string $bytes, string $mime): array => ['is_payment' => true, 'success' => true, 'utr' => $utr, 'amount' => (float) $d['total_amount']];
    $reply = (string) WaPayProof::handle('91' . PHONES[1], '', ['id' => 'zz-u9-media']);
    $pD = Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $d['id']]);
    check('screenshot with A\'s UTR for D: refused, other PNR not shown', str_contains($reply, 'पहिले नै अर्को बुकिङमा प्रयोग भइसकेको छ') && !str_contains($reply, $pnrA), mb_substr($reply, 0, 80));
    check('… nothing stored on D (no UTR, still pending)', trim((string) ($pD['utr_number'] ?? '')) === '' && (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $d['id']], '') === 'pending');
    check('… the office has a bell naming both PNRs', Database::exists("SELECT 1 FROM notifications WHERE booking_id = :b AND title LIKE 'Duplicate UTR refused%' AND body LIKE :o",
        ['b' => (int) $d['id'], 'o' => '%' . $pnrA . '%']));
    $typed = (string) WaPayProof::handleText('91' . PHONES[1], 'UTR ' . $utr, 'en');
    check('typed "UTR <A\'s UTR>" from D\'s number: refused before any preview', str_contains($typed, 'already used for another booking') && !str_contains($typed, 'Reply *yes*'), mb_substr($typed, 0, 80));
    localSetting('wa_pay_dup_utr_block', '0', 'bool');
    check('wa_pay_dup_utr_block=0: the old preview again', str_contains((string) WaPayProof::handleText('91' . PHONES[1], 'UTR ' . $utr, 'en'), 'Reply *yes*'));
    localSetting('wa_pay_dup_utr_block', '1', 'bool');
    WaPayProof::$fetcher = null; WaPayProof::$reader = null;
    try { Database::run("DELETE FROM kv_store WHERE kkey LIKE '%wa_utr_pending%' AND kkey LIKE :p", ['p' => '%' . PHONES[1] . '%']); } catch (Throwable $e) {}

    /* ---------------------------------------------------------------- pending proof on pay.php */
    echo "\n-- pay.php: a pending online booking sends its proof here (spec gap) --\n";
    dbSetting('pay_app_chooser_on', '1', 'bool');
    $r   = http('/pay.php?pnr=' . rawurlencode($pnrD));
    $tok = csrfFrom($r['body']);
    $r = http('/pay.php?pnr=' . rawurlencode($pnrD), [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => $utr, 'phone' => '9000000000']);
    check('pending proof with a stranger\'s mobile: 403, nothing stored', $r['code'] === 403
        && trim((string) Database::scalar('SELECT COALESCE(utr_number, \'\') FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $d['id']], '')) === '', (string) $r['code']);
    $r = http('/pay.php?pnr=' . rawurlencode($pnrD), [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => $utr, 'phone' => PHONES[1]]);
    check('pending proof with A\'s UTR (already on another booking): 409, A\'s PNR not shown, nothing stored', $r['code'] === 409
        && str_contains($r['body'], 'already been used') && !str_contains($r['body'], $pnrA)
        && trim((string) Database::scalar('SELECT COALESCE(utr_number, \'\') FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $d['id']], '')) === '', (string) $r['code']);
    $png2 = sys_get_temp_dir() . '/shg_u9_shot2_' . getmypid() . '.png';
    $im = imagecreatetruecolor(120, 80); imagefilledrectangle($im, 0, 0, 119, 79, imagecolorallocate($im, 30, 60, 160));
    imagestring($im, 3, 8, 30, 'ZZTEST U9 D ' . random_int(1000, 9999), imagecolorallocate($im, 255, 255, 255)); imagepng($im, $png2); imagedestroy($im);
    do { $utrD = '6' . (string) random_int(10000000000, 99999999999); }
    while (Database::exists('SELECT 1 FROM payments WHERE utr_number = :u', ['u' => $utrD]) || PaymentRisk::utrLooksWrong($utrD, 'upi') !== null);
    $r = http('/pay.php?pnr=' . rawurlencode($pnrD), [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => $utrD, 'phone' => PHONES[1],
        'shot' => new CURLFile($png2, 'image/png', 'pay.png')]);
    $pD = Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $d['id']]);
    $bD = Database::fetch('SELECT status, expires_at FROM bookings WHERE id = :i', ['i' => (int) $d['id']]);
    check('pending proof UTR + screenshot: 303, the website proof path (UTR, mode both, payment pending), booking still PENDING (never confirmed), expiry clock held',
        $r['code'] === 303 && ($pD['utr_number'] ?? '') === $utrD && ($pD['mode'] ?? '') === 'both' && ($pD['status'] ?? '') === 'pending'
        && ($bD['status'] ?? '') === 'pending' && is_array($bD) && array_key_exists('expires_at', $bD) && $bD['expires_at'] === null
        && Database::exists('SELECT 1 FROM payment_screenshots WHERE booking_id = :b', ['b' => (int) $d['id']]), $r['code'] . ' ' . ($pD['mode'] ?? '') . ' ' . ($bD['status'] ?? ''));
    @unlink($png2);
    $r = http('/pay.php?pnr=' . rawurlencode($pnrD) . '&sent=1');
    check('… then the page says "being checked", no pay buttons, no second form', $r['code'] === 200 && str_contains($r['body'], 'Your payment is being checked')
        && !str_contains($r['body'], 'name="utr"') && !str_contains($r['body'], 'tez://'), (string) $r['code']);
    Database::delete('settings', 'skey = :k', ['k' => 'pay_app_chooser_on']);
    Settings::flush();

    /* ---------------------------------------------------------------- bot words */
    echo "\n-- WaBot: blueprint words --\n";
    $office = (string) (OfficeReport::numbers()[0] ?? '');
    if ($office === '') {
        localSetting('office_report_numbers', '919000019099');
        $office = '919000019099';
    }
    $od = normalisePhone($office);
    $rep = WaBot::blueprintWords('+' . $office, $od, 'report');
    check('office "report": today\'s office report text', $rep !== null && str_contains($rep['text'], 'अफिस रिपोर्ट'), mb_substr((string) ($rep['text'] ?? ''), 0, 60));
    $ag = WaBot::blueprintWords('+' . $office, $od, 'agents');
    check('office "agents": top sellers today', $ag !== null && str_contains($ag['text'], 'Top sellers today'));
    $rv = WaBot::blueprintWords('+' . $office, $od, 'Revenue');
    check('office "Revenue": today + this month', $rv !== null && str_contains($rv['text'], 'आज · Today:') && str_contains($rv['text'], 'This month'));
    check('a customer number writing "report" gets nothing from here', WaBot::blueprintWords('+91' . PHONES[3], PHONES[3], 'report') === null);

    $st = WaBot::blueprintWords('+91' . PHONES[0], PHONES[0], 'status');
    check('"status" from A\'s number: PNR, Confirmed pay on boarding, pay link, track link', $st !== null && str_contains($st['text'], $pnrA)
        && str_contains($st['text'], 'Confirmed, pay on boarding') && str_contains($st['text'], 'pay.php?pnr=' . rawurlencode($pnrA)) && str_contains($st['text'], 'track.php?pnr='),
        str_replace("\n", ' | ', (string) ($st['text'] ?? '')));
    $st = WaBot::blueprintWords('+91' . PHONES[1], PHONES[1], 'स्थिति');
    check('"स्थिति" from D\'s number: Payment pending', $st !== null && str_contains($st['text'], 'Payment pending'));
    $st = WaBot::blueprintWords('+91' . PHONES[3], PHONES[3], 'status');
    check('"status" from a number with no booking: says so', $st !== null && str_contains($st['text'], 'No booking on this number'));
    $tr = WaBot::blueprintWords('+91' . PHONES[0], PHONES[0], 'track');
    check('"track" for a trip far ahead: the trip\'s live link', $tr !== null && str_contains($tr['text'], Ticket::trackUrl($pnrA)));
    check('"track" with no ticket: left to WaLocal (null)', WaBot::blueprintWords('+91' . PHONES[3], PHONES[3], 'track') === null);

    check('nameAndPhone: "Ram Thapa 9812345678" and "+977 9812345678 Ram"',
        WaBot::nameAndPhone('Ram Thapa 9812345678') === ['Ram Thapa', '9812345678'] && WaBot::nameAndPhone('+977 9812345678 Ram') === ['Ram', '9812345678']);
    check('nameAndPhone: never a booking / payment line', WaBot::nameAndPhone('utr 9812345678') === null && WaBot::nameAndPhone('bholi 2 seat 9812345678') === null
        && WaBot::nameAndPhone('ticket 9812345678') === null);
    check('resendRequest: a resend word is required; before or after the name + mobile',
        WaBot::resendRequest('Ram Thapa 9812345678') === null
        && WaBot::resendRequest('resend Ram Thapa 9812345678') === ['Ram Thapa', '9812345678']
        && WaBot::resendRequest('ticket pathau: Ram Thapa 9812345678') === ['Ram Thapa', '9812345678']
        && WaBot::resendRequest('Ram Thapa 9812345678 ticket pathau') === ['Ram Thapa', '9812345678']
        && WaBot::resendRequest('टिकट पठाउनुहोस् Ram 9812345678') === ['Ram', '9812345678']
        && WaBot::resendRequest('ticket pathau') === null);
    $sent = [];
    WaBot::$resendHook = static function (array $bk) use (&$sent): array { $sent[] = (string) $bk['pnr']; return ['ok' => true]; };
    check('review fix (high): a bare "<name> <mobile>" from the SAME number is NOT intercepted - it stays WaBooking\'s quick order',
        WaBot::blueprintWords('+91' . PHONES[0], PHONES[0], 'ZZTEST Uninea Board ' . PHONES[0]) === null && $sent === []);
    check('… nor from another number (no resend started by a bare line)',
        WaBot::blueprintWords('+91' . PHONES[3], PHONES[3], 'zztest uninea board ' . PHONES[0]) === null && $sent === []);
    $fr = WaBot::blueprintWords('+91' . PHONES[3], PHONES[3], 'resend zztest uninea board ' . PHONES[0]);
    check('"resend <name> <mobile>" from ANOTHER number: resent to the booking\'s own number only, PNR not shown', $fr !== null && $sent === [$pnrA]
        && str_contains($fr['text'], '••01') && !str_contains($fr['text'], $pnrA), (string) ($fr['text'] ?? 'null'));
    $miss = WaBot::blueprintWords('+91' . PHONES[3], PHONES[3], 'resend Hari Prasad ' . PHONES[0]);
    check('review fix: a miss from another number gets the SAME words as a hit (no "does this person travel" probe), nothing sent',
        $miss !== null && $fr !== null && $miss['text'] === $fr['text'] && count($sent) === 1);
    $again = WaBot::blueprintWords('+91' . PHONES[2], PHONES[2], 'ticket pathau ZZTEST Uninea Board ' . PHONES[0]);
    check('review fix: one resend per booking per 6 h - a second asker gets the same words, no second send',
        $again !== null && $again['text'] === $fr['text'] && count($sent) === 1, (string) count($sent));
    $own = WaBot::blueprintWords('+91' . PHONES[0], PHONES[0], 'ticket pathau ZZTEST Uninea Board ' . PHONES[0]);
    check('"ticket pathau <name> <mobile>" from the SAME number: the ticket picture right here (no extra send)', $own !== null && str_contains((string) $own['media'], 'ticket') && count($sent) === 1);
    $pend = WaBot::blueprintWords('+91' . PHONES[3], PHONES[3], 'resend ZZTEST Uninea Online ' . PHONES[1]);
    check('a pending booking is never "found" (no ticket yet): same words, nothing sent', $pend !== null && $pend['text'] !== '' && count($sent) === 1);
    Database::update('bookings', ['status' => 'completed'], 'id = :i', ['i' => (int) $n['id']]);
    WaBot::blueprintWords('+91' . PHONES[3], PHONES[3], 'resend ZZTEST Uninea Nepal ' . PHONES[2]);
    check('review fix: a completed (past) trip is never re-sent', count($sent) === 1, implode(',', $sent));
    Database::update('bookings', ['status' => 'confirmed'], 'id = :i', ['i' => (int) $n['id']]);
    $capd = WaBot::blueprintWords('+91' . PHONES[3], PHONES[3], 'resend Sita Gurung ' . PHONES[0]);
    $capd2 = WaBot::blueprintWords('+91' . PHONES[3], PHONES[3], 'resend Gita Gurung ' . PHONES[0]);
    check('review fix: misses count against the sender too (5 tries an hour, then "too many tries")',
        $capd !== null && $capd2 !== null && str_contains($capd2['text'], 'Too many tries'), mb_substr((string) ($capd2['text'] ?? ''), 0, 60));
    WaBot::$resendHook = null;

    /* review fix (low): office numbers match the FULL number when the setting has a country code */
    $prevOrn = Settings::getString('office_report_numbers', '');
    localSetting('office_report_numbers', '9779000019098');
    check('isOfficeNumber: +977 office number matches itself, NOT the Indian number with the same ten digits',
        WaBot::isOfficeNumber('+9779000019098') && !WaBot::isOfficeNumber('+919000019098') && !WaBot::isOfficeNumber('919000019098'));
    check('… and "revenue" from that Indian number gets nothing from here', WaBot::blueprintWords('+919000019098', '9000019098', 'revenue') === null);
    localSetting('office_report_numbers', $prevOrn);

    $wl = new ReflectionMethod('WaBot', 'withBookingLink');
    check('"ticket" reply gets the website booking link; other words and staff do not',
        str_contains((string) $wl->invoke(null, 'Q?', 'ticket', false), 'Book online: ' . appUrl(''))
        && str_contains((string) $wl->invoke(null, 'Q?', 'टिकट', false), 'Book online')
        && $wl->invoke(null, 'Q?', 'bholi 2 seat', false) === 'Q?' && $wl->invoke(null, 'Q?', 'ticket', true) === 'Q?');

    $full = WaBot::reply('+91' . PHONES[1], 'status');
    check('the whole bot: "status" reaches the new words (router u9words)', WaBot::$lastRouter === 'u9words' && str_contains($full['text'], $pnrD), WaBot::$lastRouter);

    /* ---------------------------------------------------------------- search badge */
    echo "\n-- api/search.php: seats-left urgency --\n";
    $q = '/api/search.php?from=' . rawurlencode((string) $route['from_city']) . '&to=' . rawurlencode((string) $route['to_city']) . '&date=' . TD;
    $j = json_decode(http($q)['body'], true);
    $res0 = $j['data']['results'][0] ?? null;
    check('search: every result carries seatsLow / seatsLowLabel', is_array($res0) && array_key_exists('seatsLow', $res0) && array_key_exists('seatsLowLabel', $res0));
    if (is_array($res0)) {
        $left = (int) $res0['seatsLeft'];
        check('search: ' . $left . ' free > 6 -> not low, no label', $left > 6 ? ($res0['seatsLow'] === false && $res0['seatsLowLabel'] === '') : true);
        dbSetting('search_low_seats_at', (string) ($left + 1), 'int');
        $res1 = json_decode(http($q)['body'], true)['data']['results'][0] ?? [];
        check('search_low_seats_at above the free count -> केवल N सिट बाँकी · Only N seats left', ($res1['seatsLow'] ?? false) === true
            && ($res1['seatsLowLabel'] ?? '') === 'केवल ' . $left . ' सिट बाँकी · Only ' . $left . ' seat' . ($left === 1 ? '' : 's') . ' left', (string) ($res1['seatsLowLabel'] ?? ''));
    }

    /* ---------------------------------------------------------------- source */
    $src = (string) file_get_contents(dirname(__DIR__) . '/pay.php');
    check('pay.php: still exactly two green .btn rules and two WhatsApp buttons (u11 theme rule)',
        substr_count($src, '.btn { display:block; text-align:center; background:#0a7c2f; color:#fff;') === 2 && substr_count($src, 'style="background:#128C7E"') === 2);
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    cleanup();
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    Settings::flush();
    @unlink($JAR);
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
