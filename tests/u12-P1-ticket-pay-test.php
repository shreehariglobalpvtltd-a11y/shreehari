<?php
/**
 * u12-P1-ticket-pay-test.php — pay at boarding on the ticket + free online pay (16 Nov 2026).
 *
 * Owner: "pay at boarding vane option ticket chalani ma add gardeu - cash ho ki online ho
 * tyo ni; ani redirect payment lina sab suvidha hunu paryo, free wala".
 *
 * Seeds its own far-future departure with synthetic passengers only:
 *   A  pay-at-boarding (COD), money due            -> बसमा तिर्ने · PAY ON BOARDING
 *   B  pay-at-boarding, cash settled by the office  -> PAID · नगद/CASH
 *   C  pay-at-boarding, chalani tick "UPI"          -> PAID · अनलाइन/ONLINE (UPI)
 *   D  online booking waiting for approval          -> PAYMENT PENDING (unchanged)
 *   E  desk sale paid by UPI                        -> PAID · अनलाइन/ONLINE (UPI)
 *   F  a second pay-at-boarding ticket (UTR re-use, eSewa claim)
 * and checks the PNG + PDF words, the ticket's pay link (pay.php + the ticket key on a
 * pay-at-boarding ticket), pay.php's free ways (UPI app button, UPI QR, eSewa), the UTR
 * claim (one claim, pending on the ticket's own money row, in the office's Payments COD
 * tab, bad / repeated / re-used UTR refused, never auto-confirmed), and that pending /
 * paid bookings keep pay.php's old answers. Everything it made is removed at the end.
 *
 * Refix checks (review of P1, 16 Nov 2026 night):
 *   G  a UPI claim the office settles on Payments → COD with the note box empty
 *      ('Cash collected')                          -> still PAID · अनलाइन/ONLINE · UPI, not CASH
 *   H  an eSewa claim settled by settleCod('')     -> PAID · अनलाइन/ONLINE · eSewa (pay_claim marker)
 *   B  a chalani tick "UPI" after the cash settle  -> the cached PNG + PDF redraw (ONLINE), once
 *   B  fare raised after the settle (due on a VERIFIED row) -> pay.php keeps the old 302, no UTR box
 *   Ticket::staleBefore() is never earlier than ticket.php's own mtime (the deploy minute)
 *
 *   SHG_TEST_BASE=http://localhost:9201 php -c .claude/php-dev.ini -d extension=php_curl.dll tests/u12-P1-ticket-pay-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/opshub.php';
require_once INCLUDE_PATH . '/payadapter.php';
require_once INCLUDE_PATH . '/paymentrisk.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n"; exit(1); }
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:9201'), '/'));
const TD      = '2099-10-23';
const PHONES  = ['9100012901', '9100012902', '9100012903', '9100012904', '9100012905', '9100012906', '9100012907', '9100012908'];   // P1's own numbers (u12-P2 uses 91000012xx)
const BOSS    = 'u12_p1_pay_boss';
const BOSS_PW = 'U12P1PayBoss77';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$JARS = ['pax' => sys_get_temp_dir() . '/shg_u12p1_pax_' . getmypid() . '.txt', 'pax2' => sys_get_temp_dir() . '/shg_u12p1_pax2_' . getmypid() . '.txt',
         'boss' => sys_get_temp_dir() . '/shg_u12p1_boss_' . getmypid() . '.txt'];
foreach ($JARS as $j) { @unlink($j); }
function http(string $url, array $opt = [], string $jar = 'pax'): array {
    global $JARS;
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $JARS[$jar], CURLOPT_COOKIEFILE => $JARS[$jar], CURLOPT_TIMEOUT => 90,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 14) u12-p1-test']);
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? html_entity_decode($m[1]) : '';
}
function noWarn(string $body): bool {
    return !preg_match('/(Warning|Notice|Deprecated|Fatal error|Uncaught)\b.*(on line|in \/)/i', $body);
}
function pdfPlain(string $path): string {
    $raw = is_file($path) ? (string) file_get_contents($path) : '';
    $plain = $raw;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $m)) {
        foreach ($m[1] as $s) { $z = @gzuncompress($s); if ($z !== false) { $plain .= $z; } }
    }
    return $plain;
}
/** RGB of the PNG pill (top left, inside the pill, left of its text). */
function pillRgb(string $png): array {
    $im = @imagecreatefrompng($png);
    if ($im === false) { return [0, 0, 0]; }
    $c = imagecolorat($im, 66, 115);
    imagedestroy($im);
    return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
}
$load = static function (int $id): array {
    $m = new ReflectionMethod('Ticket', 'loadBooking');
    return $m->invoke(null, $id);
};
function cleanup(): void {
    foreach (PHONES as $p) {
        foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            $bid = (int) $r['id'];
            foreach (['booking_collections', 'notifications', 'payment_events', 'payment_reviews', 'tickets'] as $tb) {
                try { Database::run('DELETE FROM ' . $tb . ' WHERE booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
            }
            foreach (['.png', '.pdf', '.png.lock', '.pdf.lock'] as $ext) { @unlink(TICKET_PATH . '/ticket_' . $r['pnr'] . $ext); }
            foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
            @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf');
            try { Database::run('DELETE FROM agent_wallet WHERE booking_id = :b', ['b' => $bid]); } catch (Throwable $e) {}
            Database::delete('bookings', 'id = :i', ['i' => $bid]);
        }
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
    foreach (Database::fetchAll('SELECT id FROM admins WHERE username = :a', ['a' => BOSS]) as $a) {
        try { Database::run('DELETE FROM notifications WHERE admin_id = :a', ['a' => (int) $a['id']]); } catch (Throwable $e) {}
    }
    Database::delete('admins', 'username = :a', ['a' => BOSS]);
}

/* the switches this test turns on, put back exactly as found */
$keep = [];
foreach (['whatsapp_notify_customer', 'whatsapp_notify_admin', 'allow_cod', 'email_notify_customer'] as $k) {
    $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}
$restore = static function () use ($keep): void {
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    Settings::flush();
};

echo "\n=== u12-P1: pay at boarding on the ticket + free online pay (UPI / QR / eSewa + UTR claim) ===\n\n";
cleanup();
try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('pay_open', 'payment_proof', 'admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')"); } catch (Throwable $e) {}
try {
    Settings::set('whatsapp_notify_customer', '0', 'bool', 'notify', false);
    Settings::set('whatsapp_notify_admin', '0', 'bool', 'notify', false);
    Settings::set('email_notify_customer', '0', 'bool', 'notify', false);
    Settings::set('allow_cod', '1', 'bool', 'booking', false);
    Settings::flush();
    check('UPI is configured on this install (pay.php needs it)', Settings::getString('upi_id', '') !== '');
    $esewaOn = trim(Settings::getString('esewa_id', '')) !== '';

    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $sched   = Seats::schedule($routeId, TD);
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => (int) $sched['id']]);
    $bossId = (int) Database::insert('admins', ['username' => BOSS, 'password_hash' => password_hash(BOSS_PW, PASSWORD_DEFAULT),
        'full_name' => 'U12 P1 Pay Boss', 'role' => 'superadmin', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    require_once INCLUDE_PATH . '/quickunlock.php';
    QuickUnlock::set($bossId, 'pin', '583942');
    $boss = ['id' => $bossId, 'username' => BOSS, 'full_name' => 'U12 P1 Pay Boss', 'role' => 'superadmin'];

    $cod = static fn(string $seat, string $name, string $phone): array => BookingService::create(['routeId' => $routeId, 'travelDate' => TD, 'seats' => [$seat],
        'passengers' => [['name' => $name, 'age' => 30, 'gender' => 'Male']], 'contact' => ['phone' => $phone],
        'bookingMode' => 'sharing', 'cabinType' => 'single', 'paymentMethod' => 'cod', 'isCod' => true, 'boarding' => '']);
    $a = $cod('L8', 'Board Due Pax', PHONES[0]);
    $b = $cod('L10', 'Board Cash Pax', PHONES[1]);
    $c = $cod('L12', 'Board Upi Pax', PHONES[2]);
    $d = BookingService::create(['routeId' => $routeId, 'travelDate' => TD, 'seats' => ['L14'],
        'passengers' => [['name' => 'Online Wait Pax', 'age' => 30, 'gender' => 'Male']], 'contact' => ['phone' => PHONES[3]],
        'bookingMode' => 'sharing', 'cabinType' => 'single', 'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '']);
    $e = BookingService::create(['routeId' => $routeId, 'travelDate' => TD, 'seats' => ['L15'],
        'passengers' => [['name' => 'Desk Upi Pax', 'age' => 30, 'gender' => 'Male']], 'contact' => ['phone' => PHONES[4]],
        'bookingMode' => 'sharing', 'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => ''],
        ['adminId' => $bossId, 'source' => 'admin', 'paymentMethod' => 'upi']);
    $f = $cod('L16', 'Board Second Pax', PHONES[5]);
    $row = static fn(array $x): array => Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $x['id']]) ?? [];
    $pay = static fn(array $x): ?array => Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $x['id']]);
    check('seeded: A / B / C / F are confirmed pay-at-boarding tickets with the money row cod_pending',
        array_filter([$a, $b, $c, $f], static fn(array $x): bool => (string) $x['status'] !== 'confirmed' || (int) $x['is_cod'] !== 1 || ($pay($x)['status'] ?? '') !== 'cod_pending') === []);
    check('seeded: D waits for approval, E is a desk sale paid by UPI', (string) $d['status'] === 'pending' && ($pay($e)['status'] ?? '') === 'verified' && ($pay($e)['method'] ?? '') === 'upi',
        $d['status'] . ' / ' . ($pay($e)['status'] ?? '?') . ' ' . ($pay($e)['method'] ?? '?'));

    BookingService::settleCod((int) $b['id'], $bossId, 'Cash collected');
    $mp = OpsHub::markPaid((int) $c['id'], 'UPI', null, '', $boss);
    check('the office settles B in cash and ticks C "UPI" on the chalani', ($pay($b)['status'] ?? '') === 'verified' && ($pay($c)['status'] ?? '') === 'verified' && !empty($mp['ok']),
        (string) ($mp['msg'] ?? ''));

    /* ---------------------------------------------------------------- the words */
    echo "\n-- ticket words (Ticket::payWords) --\n";
    $wA = Ticket::payWords($load((int) $a['id']));
    $wB = Ticket::payWords($load((int) $b['id']));
    $wC = Ticket::payWords($load((int) $c['id']));
    $wD = Ticket::payWords($load((int) $d['id']));
    $wE = Ticket::payWords($load((int) $e['id']));
    $latin = static fn(array $w): string => implode('', array_map(static fn(array $r): string => $r[1] ? '' : $r[0], $w['band']));
    $all   = static fn(array $w): string => implode('', array_map(static fn(array $r): string => $r[0], $w['band']));
    check('A (due, pay at boarding): pill बसमा तिर्ने · PAY ON BOARDING', $wA['state'] === 'board' && $wA['pill'][0] === 'बसमा तिर्ने · PAY ON BOARDING', $wA['pill'][0]);
    check('A: PDF band Nepali first - बसमा तिर्ने · PAY ON BOARDING · INR X · नगद वा अनलाइन · CASH OR ONLINE',
        $all($wA) === 'बसमा तिर्ने · PAY ON BOARDING  ·  INR ' . indianNumber((float) $a['total_amount']) . '  ·  नगद वा अनलाइन · CASH OR ONLINE', $all($wA));
    check('B (cash settled): PAID · नगद/CASH', $wB['state'] === 'paid' && $wB['way'] === 'cash' && in_array('भुक्तानी भयो · PAID · नगद/CASH', $wB['pill'], true)
        && str_contains($all($wB), 'नगद · CASH'), implode(' | ', $wB['pill']));
    check('C (chalani tick UPI): PAID · अनलाइन/ONLINE · UPI', $wC['state'] === 'paid' && $wC['way'] === 'online' && $wC['detail'] === 'UPI'
        && str_contains($all($wC), 'अनलाइन · ONLINE (UPI)'), implode(' | ', $wC['pill']));
    check('D (online, not approved): unchanged PAYMENT PENDING', $wD['state'] === 'pending' && $wD['pill'] === ['PAYMENT PENDING']
        && $all($wD) === 'PAYMENT PENDING  ·  NOT VALID UNTIL APPROVED');
    check('E (desk sale by UPI): PAID · अनलाइन/ONLINE (UPI)', $wE['state'] === 'paid' && $wE['way'] === 'online' && $wE['detail'] === 'UPI');
    check('cash / online rule: cod + tick note "Chalani tick: paid (eSewa)" = online eSewa', Ticket::paidWay('cod', null, 'Chalani tick: paid (eSewa)') === ['online', 'eSewa']);
    check('cash / online rule: cod + tick Cash / Agent = cash; explicit cash never overridden; upi / bank = online',
        Ticket::paidWay('cod', ['status' => 'paid', 'mode' => 'Cash']) === ['cash', '']
        && Ticket::paidWay('cod', ['status' => 'paid', 'mode' => 'Agent']) === ['cash', '']
        && Ticket::paidWay('cash', ['status' => 'paid', 'mode' => 'UPI']) === ['cash', '']
        && Ticket::paidWay('upi') === ['online', 'UPI'] && Ticket::paidWay('bank') === ['online', 'BANK']
        && Ticket::paidWay('cod', ['status' => 'due', 'mode' => null]) === ['cash', '']);

    $qA = Ticket::paymentQr($load((int) $a['id']));
    $qD = Ticket::paymentQr($load((int) $d['id']));
    check('A: the ticket\'s pay QR is the live pay link with the ticket key', $qA['mode'] === 'due'
        && str_contains($qA['payload'], 'pay.php?pnr=' . rawurlencode((string) $a['pnr']) . '&k=' . Ticket::downloadToken((string) $a['pnr'])), $qA['payload']);
    check('D: an online booking\'s pay QR is unchanged (pay.php?pnr=, no key)', $qD['mode'] === 'due' && str_ends_with($qD['payload'], 'pay.php?pnr=' . rawurlencode((string) $d['pnr'])), $qD['payload']);

    /* ---------------------------------------------------------------- PNG + PDF */
    echo "\n-- PNG + PDF render --\n";
    $png = []; $pdf = [];
    foreach (['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d] as $k => $x) {
        $png[$k] = Ticket::pngPath((int) $x['id'], true);
        $pdf[$k] = pdfPlain(Ticket::pdfPath((int) $x['id'], true));
    }
    $sz = array_map(static fn(string $p): array => is_file($p) ? (array) @getimagesize($p) : [0, 0], $png);
    check('PNG renders for all four (1080 wide, same height as each other)', array_filter($sz, static fn(array $s): bool => (int) ($s[0] ?? 0) !== 1080) === []
        && count(array_unique(array_map(static fn(array $s): int => (int) $s[1], $sz))) === 1, json_encode(array_map(static fn(array $s): string => ($s[0] ?? 0) . 'x' . ($s[1] ?? 0), $sz)));
    [$ra, $ga, $ba] = pillRgb($png['A']); [$rb, $gb, $bb] = pillRgb($png['B']); [$rc, $gc, $bc] = pillRgb($png['C']); [$rd, $gd, $bd] = pillRgb($png['D']);
    check('PNG pill colour: A orange (pay on boarding), B + C green (paid), D red (pending)',
        $ra > 180 && $ga > 60 && $ga < 140 && $ba < 60 && $gb > $rb && $gb > $bb && $gc > $rc && $gc > $bc && $rd > 120 && $gd < 70,
        "A=$ra,$ga,$ba B=$rb,$gb,$bb C=$rc,$gc,$bc D=$rd,$gd,$bd");
    check('PDF A: band says PAY ON BOARDING · INR X · … CASH OR ONLINE (Latin runs)', str_contains($pdf['A'], 'PAY ON BOARDING') && str_contains($pdf['A'], '(CASH OR ONLINE) Tj')
        && !str_contains($pdf['A'], 'TO THE CREW'));
    check('PDF A: the Nepali runs go through the Devanagari font', (bool) preg_match('/\/F8 9(\.00)? Tf/', $pdf['A']) || (bool) preg_match('/\/F7 9(\.00)? Tf/', $pdf['A']));
    check('PDF B: PAID … (CASH)', str_contains($pdf['B'], 'PAID ') && str_contains($pdf['B'], '(CASH) Tj'));
    check('PDF C: PAID … ONLINE (UPI)', str_contains($pdf['C'], 'PAID ') && (bool) preg_match('/\(ONLINE \\\\\(UPI\\\\\)\) Tj/', $pdf['C']));
    check('PDF D: the old PAYMENT PENDING band, untouched', str_contains($pdf['D'], 'PAYMENT PENDING ') && str_contains($pdf['D'], 'NOT VALID UNTIL APPROVED'),
        strlen($pdf['D']) . ' bytes, D is ' . (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => (int) $d['id']], '?')
        . (preg_match('/\(([A-Z][A-Z ]{3,}[^)]{0,60})\) Tj/', $pdf['D'], $dm) ? ', first caps run: ' . $dm[1] : ''));

    /* ---------------------------------------------------------------- pay.php */
    echo "\n-- pay.php: the free ways for a pay-at-boarding ticket --\n";
    $pnrA = (string) $a['pnr']; $pnrF = (string) $f['pnr'];
    $kA = Ticket::downloadToken($pnrA); $kF = Ticket::downloadToken($pnrF);
    $dueA = (float) $a['total_amount'];
    $r = http('/pay.php?pnr=' . rawurlencode($pnrA));
    check('A: 200 page, no 302 into the UPI app', $r['code'] === 200 && !preg_match('/^Location:/mi', $r['headers']), (string) $r['code']);
    check('A: says pay on boarding and the amount', str_contains($r['body'], 'बसमा तिर्ने टिकट · Pay on boarding') && str_contains($r['body'], '₹' . number_format($dueA, 2)));
    check('A: green "UPI app मा खोल्नुहोस्" button with the upi:// link and amount',
        (bool) preg_match('/<a class="btn" href="upi:\/\/pay\?[^"]*am=' . preg_quote(number_format($dueA, 2, '.', ''), '/') . '[^"]*">UPI app मा खोल्नुहोस्<\/a>/u', $r['body'])
        && str_contains($r['body'], '.btn { display:block; text-align:center; background:#0a7c2f;'));
    check('A: UPI QR image with the amount', (bool) preg_match('/<img class="qr" src="data:image\/png;base64,[A-Za-z0-9+\/=]{200,}" alt="UPI QR/', $r['body']));
    check('A: eSewa block (' . ($esewaOn ? 'configured' : 'not configured - hidden') . ')',
        $esewaOn ? (str_contains($r['body'], '🇳🇵 eSewa') && str_contains($r['body'], 'eSewa ID:') && str_contains($r['body'], 'value="esewa"')) : !str_contains($r['body'], 'eSewa ID:'));
    check('A: the UTR form asks for the booking mobile when opened without the ticket key', str_contains($r['body'], 'name="utr"') && str_contains($r['body'], 'name="phone"'));
    check('A: no PHP warning, dark rules present', noWarn($r['body']) && str_contains($r['body'], ':root[data-theme="dark"] .claim'));
    $before = Database::fetch('SELECT COUNT(*) AS n, SUM(status = \'verified\') AS v FROM payments WHERE booking_id = :b', ['b' => (int) $a['id']]);
    $openA = (int) Database::scalar("SELECT COUNT(*) FROM payment_events WHERE booking_id = :b AND kind = 'pay_open'", ['b' => (int) $a['id']], 0);
    check('A: opening the page is logged as a pay tap (pay_open)', $openA >= 1, (string) $openA);

    $rk = http('/pay.php?pnr=' . rawurlencode($pnrA) . '&k=' . $kA);
    check('A with the ticket key: no mobile asked', $rk['code'] === 200 && str_contains($rk['body'], 'name="utr"') && !str_contains($rk['body'], 'name="phone"'));
    $tok = csrfFrom($rk['body']);
    $post = static fn(string $pnr, string $k, array $form, string $jar = 'pax'): array => http('/pay.php?pnr=' . rawurlencode($pnr) . ($k !== '' ? '&k=' . $k : ''), ['form' => $form], $jar);

    $r = $post($pnrA, $kA, [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => '12345']);
    check('bad UTR (5 digits) refused: 422 + Nepali · English reason', $r['code'] === 422 && str_contains($r['body'], 'UPI को UTR १२ अङ्कको हुन्छ') && str_contains($r['body'], 'role="alert"'), (string) $r['code']);
    $r = $post($pnrA, $kA, [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => '111111111111']);
    check('made-up UTR (all one digit) refused: 422', $r['code'] === 422, (string) $r['code']);
    $r = $post($pnrA, $kA, [CSRF_TOKEN_NAME => 'forged', 'method' => 'upi', 'utr' => '4' . str_pad((string) random_int(0, 99999999999), 11, '7', STR_PAD_LEFT)]);
    check('a forged CSRF token is refused (419)', $r['code'] === 419, (string) $r['code']);
    do { $utr = '6' . (string) random_int(10000000000, 99999999999); }
    while (Database::exists('SELECT 1 FROM payments WHERE utr_number = :u', ['u' => $utr]) || PaymentRisk::utrLooksWrong($utr, 'upi') !== null);
    $r = $post($pnrA, '', [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => $utr, 'phone' => '9100009999']);
    check('somebody else\'s mobile (no key) refused: 403', $r['code'] === 403 && str_contains($r['body'], 'Enter the mobile number used for this booking'), (string) $r['code']);
    check('… and nothing was written', trim((string) ($pay($a)['utr_number'] ?? '')) === '');

    $spaced = substr($utr, 0, 4) . ' ' . substr($utr, 4, 4) . ' ' . substr($utr, 8);
    $r = $post($pnrA, '', [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => $spaced, 'phone' => '+91 ' . PHONES[0]]);
    check('the passenger\'s own mobile + UTR typed in groups: 303 back to the page (sent=1)', $r['code'] === 303
        && (bool) preg_match('/^Location: pay\.php\?pnr=' . preg_quote(rawurlencode($pnrA), '/') . '&sent=1\s*$/mi', $r['headers']), (string) $r['code'] . ' ' . trim((string) (preg_match('/^Location:.*$/mi', $r['headers'], $lm) ? $lm[0] : '')));
    $pA = $pay($a); $bA = $row($a);
    $after = Database::fetch('SELECT COUNT(*) AS n, SUM(status = \'verified\') AS v FROM payments WHERE booking_id = :b', ['b' => (int) $a['id']]);
    check('claim stored on the ticket\'s own money row: UTR, amount due, ONLINE CLAIM · UPI note', ($pA['utr_number'] ?? '') === $utr
        && abs((float) ($pA['claimed_amount'] ?? 0) - $dueA) < 0.01 && str_starts_with((string) ($pA['admin_note'] ?? ''), 'ONLINE CLAIM · UPI · INR '), (string) ($pA['admin_note'] ?? ''));
    check('exactly one money row, still pending (cod_pending), method still cod - nothing auto-confirmed',
        (int) $after['n'] === (int) $before['n'] && (int) $after['n'] === 1 && (int) $after['v'] === 0 && ($pA['status'] ?? '') === 'cod_pending' && ($pA['method'] ?? '') === 'cod',
        'rows ' . $after['n'] . ', verified ' . (int) $after['v'] . ', ' . ($pA['status'] ?? '') . '/' . ($pA['method'] ?? ''));
    check('booking untouched: confirmed, is_cod 1, still owes the fare', ($bA['status'] ?? '') === 'confirmed' && (int) ($bA['is_cod'] ?? 0) === 1
        && abs(PaymentAdapters::due($bA) - $dueA) < 0.01);
    check('ticket still says pay on boarding (a UTR is never "paid")', Ticket::payWords($load((int) $a['id']))['state'] === 'board');
    check('the office gets a bell notification; the claim is logged (pay_claim)',
        Database::exists("SELECT 1 FROM notifications WHERE booking_id = :b AND audience = 'admin' AND title LIKE 'Online payment to check%'", ['b' => (int) $a['id']])
        && Database::exists("SELECT 1 FROM payment_events WHERE booking_id = :b AND kind = 'pay_claim'", ['b' => (int) $a['id']]));
    check('no message to the customer (no user notification for the claim)',
        !Database::exists("SELECT 1 FROM notifications WHERE booking_id = :b AND audience = 'user' AND (title LIKE '%claim%' OR title LIKE '%check%' OR body LIKE '%UTR%')", ['b' => (int) $a['id']]));

    $r = http('/pay.php?pnr=' . rawurlencode($pnrA) . '&sent=1');
    check('after the claim: तपाईंको भुक्तानी जाँचमा छ · Your payment is being checked, UTR tail, no form, no pay button', $r['code'] === 200
        && str_contains($r['body'], 'तपाईंको भुक्तानी जाँचमा छ · Your payment is being checked') && str_contains($r['body'], 'UTR …' . substr($utr, -4))
        && str_contains($r['body'], '✅ UTR पठाइयो · Sent') && !str_contains($r['body'], 'name="utr"') && !str_contains($r['body'], 'UPI app मा खोल्नुहोस्') && noWarn($r['body']));
    $r2 = http('/pay.php?pnr=' . rawurlencode($pnrA), [], 'pax2');
    check('another phone sees only "being checked" and the last 4 digits', $r2['code'] === 200 && str_contains($r2['body'], 'Your payment is being checked') && !str_contains($r2['body'], $utr));

    $r = $post($pnrA, $kA, [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => $utr]);
    check('the same UTR again on A: refused (409), still one claim', $r['code'] === 409 && ($pay($a)['utr_number'] ?? '') === $utr, (string) $r['code']);
    $do2 = '6' . (string) random_int(10000000000, 99999999999);
    $r = $post($pnrA, $kA, [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => $do2]);
    check('a second, different UTR on A: refused (409, already being checked)', $r['code'] === 409 && ($pay($a)['utr_number'] ?? '') === $utr, (string) $r['code']);

    $rf = http('/pay.php?pnr=' . rawurlencode($pnrF) . '&k=' . $kF, [], 'pax2');
    $tok2 = csrfFrom($rf['body']);
    $r = $post($pnrF, $kF, [CSRF_TOKEN_NAME => $tok2, 'method' => 'upi', 'utr' => $utr], 'pax2');
    check('A\'s UTR re-used on another ticket F: refused (409 "already been used")', $r['code'] === 409 && str_contains($r['body'], 'This UTR has already been used')
        && trim((string) ($pay($f)['utr_number'] ?? '')) === '', (string) $r['code']);
    if ($esewaOn) {
        $esw = 'ESW' . strtoupper(bin2hex(random_bytes(4)));
        $r = $post($pnrF, $kF, [CSRF_TOKEN_NAME => $tok2, 'method' => 'esewa', 'utr' => $esw], 'pax2');
        $pF = $pay($f);
        check('F paid by eSewa: claim stored (ONLINE CLAIM · eSewa), row still cod_pending', $r['code'] === 303 && ($pF['utr_number'] ?? '') === $esw
            && str_starts_with((string) ($pF['admin_note'] ?? ''), 'ONLINE CLAIM · eSewa') && ($pF['status'] ?? '') === 'cod_pending', (string) $r['code']);
        check('CodPayClaim::current reads it back as eSewa', (CodPayClaim::current((int) $f['id'])['method'] ?? '') === 'esewa');
    }

    /* ---------------------------------------------------------------- the office's queue */
    echo "\n-- the office: Payments → COD (cash owed) shows the claim --\n";
    $login = http('/admin/login.php?classic=1&other=1', [], 'boss');
    $r = http('/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($login['body']), 'classic' => '1', 'username' => BOSS, 'password' => BOSS_PW]], 'boss');
    check('superadmin signs in', $r['code'] === 302 && !str_contains($r['headers'], 'login.php'), 'HTTP ' . $r['code']);
    $q = http('/admin/payments.php?tab=cod&q=' . rawurlencode($pnrA), [], 'boss');
    check('Payments COD tab lists A with the passenger\'s UTR and the settle button', $q['code'] === 200 && str_contains($q['body'], $pnrA)
        && str_contains($q['body'], $utr) && str_contains($q['body'], 'value="cod_settle"'), 'HTTP ' . $q['code']);
    $codRows = Database::fetchAll(
        "SELECT b.pnr, p.utr_number FROM bookings b JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
          WHERE b.is_cod = 1 AND p.status = 'cod_pending' AND b.status NOT IN ('cancelled','rejected') AND b.id = :b", ['b' => (int) $a['id']]);
    check('… the same COD-tab query finds exactly one row for A, carrying the UTR', count($codRows) === 1 && ($codRows[0]['utr_number'] ?? '') === $utr);

    /* the office checks the bank and ticks UPI on the chalani -> the ticket says PAID · अनलाइन */
    OpsHub::markPaid((int) $a['id'], 'UPI', null, 'bank ok', $boss);
    $wA2 = Ticket::payWords($load((int) $a['id']));
    $pdfA2 = pdfPlain(Ticket::pdfPath((int) $a['id'], true));
    check('after the office settles A as UPI: PAID · अनलाइन/ONLINE (UPI) on PNG words and PDF band', $wA2['state'] === 'paid' && $wA2['way'] === 'online'
        && (bool) preg_match('/\(ONLINE \\\\\(UPI\\\\\)\) Tj/', $pdfA2), implode(' | ', $wA2['pill']));
    $r = http('/pay.php?pnr=' . rawurlencode($pnrA));
    check('pay.php for A now: 200 "Payment approved", nothing to pay', $r['code'] === 200 && str_contains($r['body'], 'Payment approved') && !str_contains($r['body'], 'upi://'));

    /* ---------------------------------------------------------------- unchanged answers */
    echo "\n-- pay.php: the old answers stay --\n";
    $r = http('/pay.php');
    check('no params: 400 "Invalid PNR"', $r['code'] === 400 && trim($r['body']) === 'Invalid PNR', (string) $r['code']);
    $r = http('/pay.php?pnr=' . rawurlencode((string) $d['pnr']));
    check('online pending booking D: 302 to upi:// with the amount, as before', $r['code'] === 302
        && (bool) preg_match('/^Location: upi:\/\/pay\?.*am=' . preg_quote(number_format((float) $d['total_amount'], 2, '.', ''), '/') . '/mi', $r['headers']), (string) $r['code']);
    check('D: no UTR box on the 302 fallback card', !str_contains($r['body'], 'name="utr"') && str_contains($r['body'], 'setTimeout(function(){ location.href'));
    $r = http('/pay.php?pnr=' . rawurlencode((string) $b['pnr']));
    check('paid ticket B: 200 nothing to pay', $r['code'] === 200 && str_contains($r['body'], 'Payment approved'));
    BookingService::cancel((string) $f['pnr'], 'u12 test cancel', true);
    $r = http('/pay.php?pnr=' . rawurlencode($pnrF) . '&k=' . $kF, ['form' => [CSRF_TOKEN_NAME => $tok2, 'method' => 'upi', 'utr' => $do2]], 'pax2');
    check('cancelled ticket: a UTR post gets the "nothing to pay" card (200), nothing written', $r['code'] === 200 && str_contains($r['body'], 'No payment is due')
        && ($pay($f)['utr_number'] ?? '') !== $do2, (string) $r['code']);

    /* ================================================================ refix 1: a claim settled on Payments → COD */
    echo "\n-- refix: an online claim settled on Payments → COD stays ONLINE on the ticket --\n";
    check('cash / online rule: cod + a real UTR = online (the claim), 12 digits = UPI, marker esewa = eSewa',
        Ticket::paidWay('cod', null, 'Cash collected', '617645063793') === ['online', 'UPI']
        && Ticket::paidWay('cod', null, 'Cash collected', 'ESW12AB34', 'esewa') === ['online', 'eSewa']
        && Ticket::paidWay('cod', null, 'Cash collected', 'AB12CD34EF') === ['online', '']
        && Ticket::paidWay('cod', null, 'ONLINE CLAIM · eSewa · INR 1,000.00', 'AB12CD34EF') === ['online', 'eSewa']);
    check('cash / online rule: no UTR stays cash; a junk UTR (< 6 letters/digits) stays cash; the tick "Cash" beats a UTR',
        Ticket::paidWay('cod', null, 'Cash collected') === ['cash', ''] && Ticket::paidWay('cod', null, 'Cash collected', '-- ') === ['cash', '']
        && Ticket::paidWay('cod', null, 'Cash collected', 'N/A') === ['cash', '']
        && Ticket::paidWay('cod', ['status' => 'paid', 'mode' => 'Cash'], 'Cash collected', '617645063793') === ['cash', '']);
    check('cash / online rule: the office\'s own settle note - "UPI received, UTR checked" = online UPI; cash notes stay cash',
        Ticket::paidWay('cod', null, 'UPI received, UTR checked') === ['online', 'UPI']
        && Ticket::paidWay('cod', null, 'eSewa aayo') === ['online', 'eSewa']
        && Ticket::paidWay('cod', null, 'NEFT from his son') === ['online', 'BANK']
        && Ticket::paidWay('cod', null, 'Cash collected (UPI failed)') === ['cash', '']
        && Ticket::paidWay('cod', null, 'Cash book: collected') === ['cash', '']
        && Ticket::paidWay('cod', null, 'Cash collected by the agent (WhatsApp)') === ['cash', '']
        && Ticket::paidWay('cod', null, 'Verified via tickets register') === ['cash', '']);

    $g = $cod('L9', 'Board Claim Settle Pax', PHONES[6]);
    $h = $cod('L11', 'Board Esewa Settle Pax', PHONES[7]);
    $pnrG = (string) $g['pnr']; $kG = Ticket::downloadToken($pnrG);
    $rg   = http('/pay.php?pnr=' . rawurlencode($pnrG) . '&k=' . $kG);
    check('G: the free-ways page with the UTR box (eligible: cod_pending)', $rg['code'] === 200 && str_contains($rg['body'], 'name="utr"'), (string) $rg['code']);
    do { $utrG = '6' . (string) random_int(10000000000, 99999999999); }
    while (Database::exists('SELECT 1 FROM payments WHERE utr_number = :u', ['u' => $utrG]) || PaymentRisk::utrLooksWrong($utrG, 'upi') !== null);
    $r = $post($pnrG, $kG, [CSRF_TOKEN_NAME => csrfFrom($rg['body']), 'method' => 'upi', 'utr' => $utrG]);
    $pG = $pay($g);
    check('G: UPI claim stored (303), the row carries the pay_claim marker {"pay_claim":{"method":"upi"', $r['code'] === 303 && ($pG['utr_number'] ?? '') === $utrG
        && str_starts_with((string) ($pG['gateway_payload'] ?? ''), '{"pay_claim":{"method":"upi"') && ($pG['status'] ?? '') === 'cod_pending', (string) $r['code']);
    $q = http('/admin/payments.php?tab=cod&q=' . rawurlencode($pnrG), [], 'boss');
    $r = http('/admin/payments.php?tab=cod&q=' . rawurlencode($pnrG), ['form' => [CSRF_TOKEN_NAME => csrfFrom($q['body']), 'booking_id' => (string) $g['id'],
        'note' => '', 'action' => 'cod_settle']], 'boss');
    $pG = $pay($g);
    check('G: the office taps "Cash Collected" on Payments → COD with no note: verified, method cod, note "Cash collected", UTR kept',
        ($pG['status'] ?? '') === 'verified' && ($pG['method'] ?? '') === 'cod' && ($pG['admin_note'] ?? '') === 'Cash collected' && ($pG['utr_number'] ?? '') === $utrG,
        'HTTP ' . $r['code'] . ' · ' . ($pG['status'] ?? '?') . '/' . ($pG['method'] ?? '?') . ' · ' . ($pG['admin_note'] ?? ''));
    $wG = Ticket::payWords($load((int) $g['id']));
    check('G: the ticket says भुक्तानी भयो · PAID · अनलाइन/ONLINE · UPI - not नगद/CASH', $wG['state'] === 'paid' && $wG['way'] === 'online' && $wG['detail'] === 'UPI'
        && in_array('भुक्तानी भयो · PAID · अनलाइन/ONLINE · UPI', $wG['pill'], true) && !str_contains(implode('|', $wG['pill']), 'CASH'), implode(' | ', $wG['pill']));
    $pdfG = pdfPlain(Ticket::pdfPath((int) $g['id']));
    check('G: the PDF band (drawn after the settle) says ONLINE (UPI), no CASH', (bool) preg_match('/\(ONLINE \\\\\(UPI\\\\\)\) Tj/', $pdfG) && !str_contains($pdfG, '(CASH) Tj'));
    [$rG, $gG, $bG] = pillRgb(Ticket::pngPath((int) $g['id']));
    check('G: the PNG the settle redrew has the green PAID pill', $gG > $rG && $gG > $bG, "$rG,$gG,$bG");

    $hRow = $row($h);
    $esw  = 'ESW' . strtoupper(bin2hex(random_bytes(4)));
    CodPayClaim::submit($hRow, $esw, 'esewa', '', Ticket::downloadToken((string) $h['pnr']));
    check('H: eSewa claim stored with the eSewa marker', str_starts_with((string) ($pay($h)['gateway_payload'] ?? ''), '{"pay_claim":{"method":"esewa"')
        && (CodPayClaim::current((int) $h['id'])['method'] ?? '') === 'esewa');
    BookingService::settleCod((int) $h['id'], $bossId, '');   // the booking page / cash book way, note box empty
    $wH = Ticket::payWords($load((int) $h['id']));
    check('H: settled with an empty note (note wiped to "Cash collected") - the ticket still says अनलाइन/ONLINE · eSewa',
        ($pay($h)['admin_note'] ?? '') === 'Cash collected' && $wH['way'] === 'online' && $wH['detail'] === 'eSewa', implode(' | ', $wH['pill']));

    /* ================================================================ refix 2: a tick after the settle */
    echo "\n-- refix: a chalani tick after the settle redraws the cached ticket (once) --\n";
    $bId  = (int) $b['id'];
    $pngB = Ticket::pngPath($bId);
    $pdfB = Ticket::pdfPath($bId);
    clearstatcache();
    $md5P = (string) md5_file($pngB); $md5D = (string) md5_file($pdfB);
    check('B before the tick: cached ticket says CASH', Ticket::payWords($load($bId))['way'] === 'cash' && str_contains(pdfPlain($pdfB), '(CASH) Tj'));
    $md = OpsHub::markDue($bId, $boss);
    $mp = OpsHub::markPaid($bId, 'UPI', null, 'bank ok', $boss);
    check('the office marks B due, then ticks "UPI" (a verified row: only the tick row changes)', !empty($md['ok']) && !empty($mp['ok'])
        && ($pay($b)['method'] ?? '') === 'cod' && ($pay($b)['admin_note'] ?? '') === 'Cash collected', (string) ($mp['msg'] ?? ''));
    $pngB2 = Ticket::pngPath($bId);
    $pdfB2 = Ticket::pdfPath($bId);
    clearstatcache();
    check('B: the next open serves a REDRAWN PNG (not the cached CASH one)', $pngB2 === $pngB && (string) md5_file($pngB2) !== $md5P);
    $plainB2 = pdfPlain($pdfB2);
    check('B: the next open serves a redrawn PDF that says ONLINE (UPI), no CASH', (string) md5_file($pdfB2) !== $md5D
        && (bool) preg_match('/\(ONLINE \\\\\(UPI\\\\\)\) Tj/', $plainB2) && !str_contains($plainB2, '(CASH) Tj'));
    sleep(2);
    Ticket::pngPath($bId);                       // may redraw once more when the tick and the redraw shared a second
    clearstatcache(); $m1 = (int) filemtime($pngB);
    sleep(1);
    Ticket::pngPath($bId);
    clearstatcache(); $m2 = (int) filemtime($pngB);
    check('… and only once: later opens serve the cached file (no redraw on every open)', $m1 === $m2, "$m1 / $m2");

    /* ================================================================ refix 3: due on a verified row */
    echo "\n-- refix: a settled pay-at-boarding ticket whose fare was raised keeps the old 302 --\n";
    Database::run('UPDATE bookings SET total_amount = total_amount + 300 WHERE id = :i', ['i' => $bId]);   // what TicketEdit::correctFare does to a settled ticket
    $bRow = $row($b);
    check('B owes ₹300 on a VERIFIED row: not eligible for a claim', !CodPayClaim::eligible($bRow) && abs(PaymentAdapters::due($bRow) - 300.0) < 0.01
        && Ticket::payWords($load($bId))['state'] === 'board');
    $kB = Ticket::downloadToken((string) $b['pnr']);
    $r  = http('/pay.php?pnr=' . rawurlencode((string) $b['pnr']) . '&k=' . $kB);
    check('pay.php for B: the old 302 into the UPI app for ₹300.00, no UTR box', $r['code'] === 302
        && (bool) preg_match('/^Location: upi:\/\/pay\?.*am=300\.00/mi', $r['headers']) && !str_contains($r['body'], 'name="utr"'), (string) $r['code']);
    $utrB = '6' . (string) random_int(10000000000, 99999999999);
    $r = $post((string) $b['pnr'], $kB, [CSRF_TOKEN_NAME => $tok, 'method' => 'upi', 'utr' => $utrB]);
    check('a UTR posted to B anyway: no "Nothing is due" refusal, nothing written', $r['code'] === 302 && !str_contains($r['body'], 'Nothing is due')
        && ($pay($b)['utr_number'] ?? '') !== $utrB, (string) $r['code']);

    /* ================================================================ refix 4: the renderer's own deploy time */
    echo "\n-- refix: tickets drawn before ticket.php itself reached the server redraw --\n";
    $sb = new ReflectionMethod('Ticket', 'staleBefore');
    clearstatcache();
    $floor = (int) $sb->invoke(null, '2000-01-01 00:00:00');
    check('Ticket::staleBefore() is never earlier than ticket.php\'s own mtime (the deploy minute)', $floor >= (int) filemtime(INCLUDE_PATH . '/ticket.php'),
        date('Y-m-d H:i:s', $floor) . ' vs ' . date('Y-m-d H:i:s', (int) filemtime(INCLUDE_PATH . '/ticket.php')));
    $pngE = Ticket::pngPath((int) $e['id']);
    touch($pngE, (int) filemtime(INCLUDE_PATH . '/ticket.php') - 120);
    clearstatcache();
    Ticket::pngPath((int) $e['id']);
    clearstatcache();
    check('a PNG older than ticket.php is redrawn on its next open', (int) filemtime($pngE) >= (int) filemtime(INCLUDE_PATH . '/ticket.php'));
} catch (Throwable $ex) {
    check('ran without an exception', false, get_class($ex) . ': ' . $ex->getMessage() . ' @ ' . basename($ex->getFile()) . ':' . $ex->getLine());
} finally {
    cleanup();
    $restore();
    foreach ($JARS as $j) { @unlink($j); }
}
$left = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone IN (' . implode(',', array_map(static fn(string $p): string => "'" . $p . "'", PHONES)) . ')', [], 0);
check('cleanup left no test booking behind', $left === 0, (string) $left);
check('every flipped setting is back', array_filter(array_keys($keep), static function (string $k) use ($keep): bool {
    $now = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
    return ($now['svalue'] ?? null) !== ($keep[$k]['svalue'] ?? null);
}) === []);

/* mv6 lead: Payments -> COD names an online claim for what it is */
$payPage = (string) file_get_contents(dirname(__DIR__) . '/admin/payments.php');
check('Payments COD tab: a row with a claim UTR offers "अनलाइन मिल्यो · Online received" (bank check first), others keep "Cash Collected"',
    str_contains($payPage, "\$codClaim = preg_match('/[A-Za-z0-9]{6,}/', (string) (\$q['utr_number'] ?? '')) === 1;")
    && str_contains($payPage, '✓ अनलाइन मिल्यो · Online received') && str_contains($payPage, '>Cash Collected</button>'));

echo "\n  PASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
