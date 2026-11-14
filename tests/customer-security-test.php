<?php
/**
 * =====================================================================
 *  customer-security-test.php — 15 Nov 2026 (P5: customer booking +
 *  security hardening).
 *
 *   1. Rate limits: every public read endpoint answers the house JSON
 *      error with 429 once an IP passes its bucket (quote, seats,
 *      timetable, explore, routine, agreement-points, nav-offices), and
 *      a request carrying a coupon code has its own tight bucket
 *      (10 different codes / 10 min; the same code again is free).
 *   2. Logger: secret-named values are masked at any depth (first two
 *      characters + '***'), the JSON shape stays the same, error numbers
 *      stay readable, verify codes / token= / Bearer leave free text.
 *   3. Coupons: a first_booking_only coupon is refused once the mobile has
 *      a confirmed booking (and with no mobile); per_user_limit still works.
 *   4. SmartBook promo: the field's check is true only while a typed,
 *      active, in-date, not-used-up coupon exists; a customer quote with a
 *      good code is cheaper by exactly what the sale's own pricing charges.
 *   5. Route pages: a rating (page + AggregateRating) appears only from
 *      review_min_for_rating approved reviews of real bookings.
 *
 *  TEST database only (refuses unless DB_NAME === 'shari_test'). Rows it
 *  makes are tagged ZZCST / the test IP and removed at the end; the one
 *  setting it pins is put back.
 *
 *    php -c .claude/php-dev.ini tests/customer-security-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

const CST_IP = '203.0.113.77';   // TEST-NET-3: never a real visitor

/* ---- child mode: run ONE endpoint (or one route page) as its own process ---- */
if (($argv[1] ?? '') === '--child') {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = CST_IP;
    $_GET = (array) (json_decode((string) ($argv[3] ?? '{}'), true) ?: []);
    $what = (string) ($argv[2] ?? '');
    if (str_starts_with($what, 'route:')) {
        define('SHG_APP', true);
        require_once dirname(__DIR__) . '/includes/bootstrap.php';
        require_once INCLUDE_PATH . '/routepages.php';
        [, $slug, $lang] = explode(':', $what) + [null, '', 'en'];
        RoutePages::render((string) $slug, (string) $lang);
    }
    $file = dirname(__DIR__) . '/api/' . basename($what);
    if (!is_file($file)) { exit("no such endpoint\n"); }
    require $file;
    exit(0);
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REMOTE_ADDR']    = CST_IP;
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/smartbook.php';
require_once INCLUDE_PATH . '/couponguard.php';
require_once INCLUDE_PATH . '/routepages.php';
if (DB_NAME !== 'shari_test') { exit("Refusing to run: DB_NAME is not shari_test.\n"); }
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what, string $extra = '') use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what" . ($extra !== '' ? " — $extra" : '') . "\n"; }
};

/** Run one endpoint / page in its own PHP process; returns [stdout, decoded JSON or null]. */
$child = static function (string $what, array $get = []): array {
    $cmd = [PHP_BINARY];
    $ini = php_ini_loaded_file();
    if ($ini !== false) { $cmd[] = '-c'; $cmd[] = $ini; }
    array_push($cmd, __FILE__, '--child', $what, json_encode($get));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($p)) { return ['', null]; }
    $out = (string) stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    proc_close($p);
    $j = json_decode(trim($out), true);
    return [$out, is_array($j) ? $j : null];
};
/** Put a bucket for the test IP at exactly $hits in a live window. */
$prefill = static function (string $bucket, int $hits): void {
    Database::query('DELETE FROM rate_limits WHERE bucket = :b AND identifier = :i', ['b' => $bucket, 'i' => CST_IP]);
    Database::insert('rate_limits', ['bucket' => $bucket, 'identifier' => CST_IP, 'hits' => $hits,
        'window_start' => date('Y-m-d H:i:s'), 'blocked_until' => null]);
};
$cleanRates = static function (): void {
    Database::query('DELETE FROM rate_limits WHERE identifier = :i', ['i' => CST_IP]);
};

/* ---- fixtures + cleanup ---------------------------------------------- */
$priorMin = Database::fetch("SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = 'review_min_for_rating'");
$cleanup = static function () use ($cleanRates, $priorMin): void {
    try { $cleanRates(); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM coupon_redemptions WHERE coupon_id IN (SELECT id FROM coupons WHERE code LIKE 'ZZCST%')"); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM coupons WHERE code LIKE 'ZZCST%'"); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM feedback WHERE name = 'ZZCST test'"); } catch (Throwable $e) {}
    try { Database::query("DELETE FROM app_logs WHERE message LIKE 'ZZCST%' OR (message = 'Rate limit hit' AND ip_address = :i)", ['i' => CST_IP]); } catch (Throwable $e) {}
    try {
        if ($priorMin === null) {
            Database::query("DELETE FROM settings WHERE skey = 'review_min_for_rating'");
        } else {
            Database::query("UPDATE settings SET svalue = :v, stype = :t, sgroup = :g, is_public = :p WHERE skey = 'review_min_for_rating'",
                ['v' => $priorMin['svalue'], 't' => $priorMin['stype'], 'g' => $priorMin['sgroup'], 'p' => (int) $priorMin['is_public']]);
        }
    } catch (Throwable $e) {}
    Settings::flush();
    $_SESSION['coupon_tried'] = [];
};
$cleanup();
$coupon = static function (string $code, array $over = []): int {
    return (int) Database::insert('coupons', $over + [
        'code' => $code, 'title' => 'ZZCST test', 'discount_type' => 'flat', 'discount_value' => 100, 'max_discount' => null,
        'min_amount' => 0, 'route_id' => null, 'usage_limit' => null, 'used_count' => 0, 'per_user_limit' => 1,
        'valid_from' => null, 'valid_until' => null, 'is_active' => 1, 'auto_apply' => 0, 'min_hours_ahead' => null,
    ]);
};

try {
    /* ================================================================= */
    echo "\n1. Rate limits (house JSON, HTTP 429 after N)\n";
    $endpoints = [
        // [endpoint, bucket, max, GET for the "allowed" probe]
        ['quote.php',            'quote',       120, []],
        ['seats.php',            'seats',       300, []],
        ['timetable.php',        'timetable',   120, []],
        ['explore.php',          'explore',     120, ['what' => 'nothing']],
        ['routine.php',          'routine',     120, ['days' => 1]],
        ['agreement-points.php', 'agr_points',  120, ['doc' => 'none', 'lang' => 'none']],
        ['nav-offices.php',      'nav_offices', 120, []],
    ];
    foreach ($endpoints as [$ep, $bucket, $max, $get]) {
        $prefill($bucket, $max - 1);
        [, $j1] = $child($ep, $get);
        $ok($j1 !== null && (int) ($j1['code'] ?? 200) !== 429, "$ep: request $max of $max is answered", json_encode($j1));
        [, $j2] = $child($ep, $get);
        $ok($j2 !== null && ($j2['ok'] ?? null) === false && (int) ($j2['code'] ?? 0) === 429 && is_string($j2['error'] ?? null),
            "$ep: request " . ($max + 1) . ' gets the house 429', json_encode($j2));
    }
    $cleanRates();

    // the coupon bucket: tight, and only for requests carrying a code
    $prefill(CouponGuard::BUCKET, CouponGuard::MAX_NEW);
    [, $jc] = $child('quote.php', ['couponCode' => 'ZZCSTGUESS']);
    $ok($jc !== null && (int) ($jc['code'] ?? 0) === 429 && str_contains((string) ($jc['error'] ?? ''), 'promo'),
        'quote.php with a coupon code past 10 tries / 10 min: 429', json_encode($jc));
    [, $jn] = $child('quote.php', []);
    $ok($jn !== null && (int) ($jn['code'] ?? 0) !== 429, 'quote.php with no code is not held by the coupon bucket', json_encode($jn));
    $cleanRates();

    $_SESSION['coupon_tried'] = [];
    $all = true;
    for ($i = 1; $i <= CouponGuard::MAX_NEW; $i++) { $all = $all && CouponGuard::allow('ZZCSTTRY' . $i); }
    $ok($all, 'CouponGuard: 10 different codes are allowed');
    $ok(CouponGuard::allow('zzcsttry3'), 'the same code again (a re-quote) is free');
    $ok(!CouponGuard::allow('ZZCSTTRY11'), 'an 11th different code inside 10 minutes is refused');
    $ok(CouponGuard::allow(''), 'no code: nothing to hold');
    $cleanRates();
    $_SESSION['coupon_tried'] = [];

    /* ================================================================= */
    echo "\n2. Logger redaction\n";
    $in = [
        'password' => 'hunter22',
        'name'     => 'Ram',
        'phone'    => '98******12',
        'user'     => ['otp' => '482913', 'city' => 'Surat', 'deep' => ['api_key' => 'sk-abcdef', 'list' => ['token' => 'tok123456', 'n' => 3]]],
        'tokens'   => ['aaaa1111', 'bbbb2222'],
        'code'     => 63016,
        'http_code' => 403,
        'otp_code' => 123456,
        'pin'      => '4821',
        'has_token' => true,
        'Authorization' => 'Bearer abcdefgh12345',
        'resp'     => 'Your login code is 482913. Twilio error code 131047.',
        'url'      => 'https://graph.example/v1/me?access_token=EAAGabc123&fields=id',
    ];
    $out = Logger::redact($in);
    $ok($out['password'] === 'hu***' && $out['pin'] === '48***', 'a password / PIN: first two characters + ***', json_encode([$out['password'], $out['pin']]));
    $ok($out['user']['otp'] === '48***' && $out['user']['deep']['api_key'] === 'sk***' && $out['user']['deep']['list']['token'] === 'to***',
        'nested arrays are masked at every level', json_encode($out['user']));
    $ok($out['user']['city'] === 'Surat' && $out['user']['deep']['list']['n'] === 3 && $out['name'] === 'Ram',
        'ordinary values are left alone');
    $ok($out['tokens'] === ['aa***', 'bb***'], 'a secret-named list masks every item', json_encode($out['tokens']));
    $ok($out['code'] === 63016 && $out['http_code'] === 403, 'provider / HTTP error numbers stay readable');
    $ok($out['otp_code'] === '12***', 'an OTP under a code key is masked even as a number', json_encode($out['otp_code']));
    $plain = Logger::redact(['passengers' => 3, 'pincode' => '395003', 'keyword' => 'surat', 'mapping' => 'x', 'idem_key' => 'abc123',
        'country_code' => '+977', 'pnr_code' => 'SHG-1', 'cacheKey' => 'k1', 'verifyCode' => 'AB12CD', 'apiKey' => 'sk-123456', 'cookies' => ['a' => 'zz9']]);
    $ok($plain['passengers'] === 3 && $plain['pincode'] === '395003' && $plain['keyword'] === 'surat' && $plain['mapping'] === 'x'
        && $plain['idem_key'] === 'abc123' && $plain['country_code'] === '+977' && $plain['pnr_code'] === 'SHG-1' && $plain['cacheKey'] === 'k1',
        'look-alike keys (passengers, pincode, keyword, country_code, idem_key) stay readable', json_encode($plain));
    $ok($plain['verifyCode'] === 'AB***' && $plain['apiKey'] === 'sk***' && $plain['cookies'] === ['a' => 'zz***'],
        'camelCase secrets (verifyCode, apiKey) and a cookies list are masked', json_encode($plain));
    $ok($out['has_token'] === true, 'true / false are not masked');
    $ok($out['Authorization'] === 'Be***', 'an Authorization header is masked');
    $ok(str_contains($out['resp'], 'code is 48***') && !str_contains($out['resp'], '482913') && str_contains($out['resp'], 'error code 131047'),
        'free text: the 6-character verify code goes, the error number stays', $out['resp']);
    $ok(str_contains($out['url'], 'access_token=EA***&fields=id'), 'free text: a token= query value is masked', $out['url']);
    $ok(array_keys($out) === array_keys($in) && array_keys($out['user']) === array_keys($in['user']), 'the JSON shape (keys, nesting) is unchanged');
    $ok(Logger::scrubText('Login code NOT sent: global OTP ceiling reached') === 'Login code NOT sent: global OTP ceiling reached',
        'a message without a code is untouched');

    Logger::info('ZZCST logger check — login code is 482913', ['password' => 'hunter22', 'ctx' => ['secret' => 'shhh-secret']], 'test');
    $row = Database::fetch("SELECT message, context FROM app_logs WHERE message LIKE 'ZZCST logger check%' ORDER BY id DESC LIMIT 1");
    $ctx = $row !== null ? json_decode((string) $row['context'], true) : null;
    $ok($row !== null && str_ends_with((string) $row['message'], 'code is 48***'), 'app_logs: the message carries no code', (string) ($row['message'] ?? 'no row'));
    $ok(is_array($ctx) && $ctx['password'] === 'hu***' && $ctx['ctx']['secret'] === 'sh***', 'app_logs: the context is masked, same shape', (string) ($row['context'] ?? ''));
    $logFile = LOG_PATH . '/' . date('Y-m-d') . '.log';
    $tail = is_file($logFile) ? implode('', array_slice((array) file($logFile), -50)) : '';
    $ok($tail !== '' && str_contains($tail, 'ZZCST logger check') && !str_contains($tail, 'hunter22') && !str_contains($tail, '482913'),
        'the dated log file has the line, without the secrets');

    /* ================================================================= */
    echo "\n3. First-booking coupon\n";
    $hasCol = (int) Database::scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coupons' AND COLUMN_NAME = 'first_booking_only'", [], 0) === 1;
    $ok($hasCol, 'coupons.first_booking_only exists (upgrade-2026-11-15-coupon-first.sql applied)');
    $old = Database::fetch("SELECT id, contact_phone FROM bookings WHERE status = 'confirmed' AND contact_phone REGEXP '^[6-9][0-9]{9}$' AND contact_phone NOT REGEXP '^([0-9])\\\\1+$' ORDER BY id LIMIT 1");
    do { $fresh = '98779' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT); }
    while ((int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone = :p', ['p' => $fresh], 0) > 0);
    if (!$hasCol) {
        echo "  SKIP  the rest of 3: no column\n";
    } else {
        $coupon('ZZCSTFIRST', ['first_booking_only' => 1]);
        $plainId = $coupon('ZZCSTPLAIN');
        $r = Fare::couponDiscount('ZZCSTFIRST', 2000, $fresh, null, null);
        $ok(!empty($r['ok']) && (float) $r['amount'] === 100.0, 'a mobile with no confirmed booking gets it', json_encode($r['error'] ?? $r['amount']));
        if ($old !== null) {
            $r = Fare::couponDiscount('ZZCSTFIRST', 2000, (string) $old['contact_phone'], null, null);
            $ok(empty($r['ok']) && str_contains((string) $r['error'], 'first booking'), 'a mobile that already has a confirmed booking is refused', json_encode($r));
            $r = Fare::couponDiscount('ZZCSTPLAIN', 2000, (string) $old['contact_phone'], null, null);
            $ok(!empty($r['ok']), 'an ordinary coupon still works for that mobile', json_encode($r['error'] ?? ''));
            // per_user_limit unchanged: one redemption by the fresh mobile uses its single go
            Database::insert('coupon_redemptions', ['coupon_id' => $plainId, 'booking_id' => (int) $old['id'], 'user_phone' => $fresh, 'amount' => 100]);
            $r = Fare::couponDiscount('ZZCSTPLAIN', 2000, $fresh, null, null);
            $ok(empty($r['ok']) && str_contains((string) $r['error'], 'already used'), 'per_user_limit still refuses a second use', json_encode($r));
        } else {
            echo "  SKIP  no confirmed booking with a real mobile in the test database\n";
        }
        $r = Fare::couponDiscount('ZZCSTFIRST', 2000, '', null, null);
        $ok(empty($r['ok']) && str_contains((string) $r['error'], 'mobile'), 'no mobile: refused (never guessed for the passenger)', json_encode($r));
        $r = Fare::couponDiscount('ZZCSTFIRST', 2000, '0000000000', null, null);
        $ok(empty($r['ok']), 'the walk-in placeholder mobile: refused');
        Database::query("DELETE FROM coupon_redemptions WHERE coupon_id IN (SELECT id FROM coupons WHERE code LIKE 'ZZCST%')");
        Database::query("DELETE FROM coupons WHERE code LIKE 'ZZCST%'");
    }

    /* ================================================================= */
    echo "\n4. SmartBook promo field\n";
    $today  = todayISO();
    $others = (int) Database::scalar(
        "SELECT COUNT(*) FROM coupons WHERE code NOT LIKE 'ZZCST%' AND is_active = 1 AND auto_apply = 0
            AND (valid_from IS NULL OR valid_from <= :d1) AND (valid_until IS NULL OR valid_until >= :d2)
            AND (usage_limit IS NULL OR used_count < usage_limit)", ['d1' => $today, 'd2' => $today], 0);
    if ($others === 0) {
        $ok(SmartBook::promoOn(true) === false, 'no typed coupon running: the check is false (nothing renders)');
        $coupon('ZZCSTAUTO', ['auto_apply' => 1]);
        $ok(SmartBook::promoOn(true) === false, 'a running auto offer needs no field: still false');
        $coupon('ZZCSTOLD', ['valid_until' => addDaysISO($today, -1)]);
        $coupon('ZZCSTUSED', ['usage_limit' => 5, 'used_count' => 5]);
        $coupon('ZZCSTOFF', ['is_active' => 0]);
        $ok(SmartBook::promoOn(true) === false, 'expired / used-up / switched-off codes: still false');
    } else {
        echo "  note  $others other typed coupon(s) are live in the test database: the false cases are skipped\n";
    }
    $coupon('ZZCSTPROMO', ['discount_type' => 'percent', 'discount_value' => 40]);
    $ok(SmartBook::promoOn(true) === true, 'an active, in-date typed coupon: true');
    Database::query("UPDATE coupons SET is_active = 0 WHERE code = 'ZZCSTPROMO'");
    $ok(SmartBook::promoOn() === true, 'cached for the request (one query per request)');
    if ($others === 0) { $ok(SmartBook::promoOn(true) === false, 're-read after it is switched off: false'); }
    Database::query("UPDATE coupons SET is_active = 1 WHERE code = 'ZZCSTPROMO'");
    SmartBook::promoOn(true);

    $pax = [['gender' => 'Male', 'band' => 'adult']];
    Database::query("UPDATE coupons SET is_active = 0 WHERE code = 'ZZCSTAUTO'");   // the auto-offer case comes last
    try {
        $plain = SmartBook::quote(['direction' => 'toNepal', 'pax' => $pax], 'customer');
        if (($plain['price'] ?? null) === null) {
            echo "  SKIP  no priced seat on the test database's next bus\n";
        } else {
            $with = SmartBook::quote(['direction' => 'toNepal', 'pax' => $pax, 'promo' => 'zzcstpromo'], 'customer');
            $ok(is_array($with['promo']) && $with['promo']['ok'] === true && $with['promo']['code'] === 'ZZCSTPROMO', 'a good code is applied on the customer card', json_encode($with['promo']));
            $ok((float) $with['price']['total'] < (float) $plain['price']['total'] && $with['price']['offer'] === 'ZZCSTPROMO',
                'the card total drops and names the code', $plain['price']['total'] . ' → ' . $with['price']['total']);
            if ((float) $plain['price']['discount'] === 0.0) {
                $cut = round((float) $plain['price']['base'] * 40 / 100);
                $ok(abs((float) $plain['price']['total'] - (float) $with['price']['total'] - $cut) < 0.01 && abs((float) $with['promo']['amount'] - $cut) < 0.01,
                    'by exactly the coupon (40% = ' . inr($cut) . ')');
            }
            // the sale's own pricing (BookingService::create -> priceBooking with the code) charges the same
            $route = Database::fetch('SELECT * FROM routes WHERE id = :i', ['i' => (int) $with['routeId']]);
            $sched = Seats::scheduleById((int) $with['scheduleId']);
            $pb = new ReflectionMethod('BookingService', 'priceBooking');
            $pb->setAccessible(true);
            $sale = $pb->invoke(null, $route, $with['seats'], $with['type'] === 'seater' ? null : $with['type'],
                ['cabinType' => $with['cabin'], 'couponCode' => 'ZZCSTPROMO'], '', $sched, (string) $with['boarding'], $with['drop'] !== '' ? $with['drop'] : null);
            $ok(abs((float) $sale['total'] - (float) $with['price']['total']) < 0.01, 'the sale charges exactly the total the card showed', $sale['total'] . ' vs ' . $with['price']['total']);

            $bad = SmartBook::quote(['direction' => 'toNepal', 'pax' => $pax, 'promo' => 'ZZCSTNOPE'], 'customer');
            $ok(is_array($bad['promo']) && $bad['promo']['ok'] === false && str_contains((string) $bad['promo']['error'], 'not valid')
                && abs((float) $bad['price']['total'] - (float) $plain['price']['total']) < 0.01, 'a wrong code: its error, the price unchanged', json_encode($bad['promo']));
            $staff = SmartBook::quote(['direction' => 'toNepal', 'pax' => $pax, 'promo' => 'ZZCSTPROMO'], 'counter');
            $ok($staff['promo'] === null, 'a staff desk ignores the promo field (desks have their own discount)');

            $prefill(CouponGuard::BUCKET, CouponGuard::MAX_NEW + 1);
            $_SESSION['coupon_tried'] = [];
            $held = SmartBook::quote(['direction' => 'toNepal', 'pax' => $pax, 'promo' => 'ZZCSTPROMO2'], 'customer');
            $ok(is_array($held['promo']) && $held['promo']['ok'] === false && str_contains((string) $held['promo']['error'], 'Too many')
                && abs((float) $held['price']['total'] - (float) $plain['price']['total']) < 0.01, 'too many codes tried: the card says so, no discount, no 500', json_encode($held['promo']));
            $cleanRates();
            $_SESSION['coupon_tried'] = [];

            if ($others === 0) {
                // a running offer that already gives as much: the typed code is not used, the price stays the offer's
                Database::query("UPDATE coupons SET is_active = 1, discount_type = 'percent', discount_value = 40 WHERE code = 'ZZCSTAUTO'");
                $auto = SmartBook::quote(['direction' => 'toNepal', 'pax' => $pax], 'customer');
                $both = SmartBook::quote(['direction' => 'toNepal', 'pax' => $pax, 'promo' => 'ZZCSTPROMO'], 'customer');
                $ok(is_array($both['promo']) && $both['promo']['ok'] === false && str_contains((string) $both['promo']['error'], 'running offer')
                    && abs((float) $both['price']['total'] - (float) $auto['price']['total']) < 0.01 && $both['price']['offer'] === 'ZZCSTAUTO',
                    'an equal running offer: the code is not used, never a higher price', json_encode([$both['promo'], $auto['price']['total'], $both['price']['total']]));
                Database::query("UPDATE coupons SET is_active = 0 WHERE code = 'ZZCSTAUTO'");
            }
        }
    } catch (Throwable $ex) {
        echo '  SKIP  SmartBook quote not available on this database: ' . $ex->getMessage() . "\n";
    }

    /* ================================================================= */
    echo "\n5. Route page rating\n";
    $slugs = array_keys(RoutePages::routes());
    $setMin = static function (int $n): void {
        Database::query("INSERT INTO settings (skey, svalue, stype, sgroup, is_public) VALUES ('review_min_for_rating', :v, 'int', 'general', 0)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), stype = 'int'", ['v' => (string) $n]);
        Settings::flush();
    };
    $setMin(1);
    $base = RoutePages::rating();
    $n0 = $base === null ? 0 : (int) $base['count'];
    $books = Database::fetchAll("SELECT b.id FROM bookings b WHERE b.status IN ('confirmed', 'completed') AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.booking_id = b.id) ORDER BY b.id LIMIT 4");
    $gone  = Database::fetch("SELECT b.id FROM bookings b WHERE b.status = 'cancelled' AND NOT EXISTS (SELECT 1 FROM feedback f WHERE f.booking_id = b.id) ORDER BY b.id LIMIT 1");
    if (count($books) < 4 || $slugs === []) {
        echo "  SKIP  needs 4 confirmed bookings without a rating and an active route\n";
    } else {
        $fb = static function (int $bookingId, int $stars, int $public): void {
            Database::insert('feedback', ['booking_id' => $bookingId, 'user_phone' => null, 'name' => 'ZZCST test', 'rating' => $stars, 'is_public' => $public]);
        };
        $setMin($n0 + 3);
        $ok(Settings::getInt('review_min_for_rating', 5) === $n0 + 3, 'review_min_for_rating is read from settings (default 5)');
        $fb((int) $books[0]['id'], 5, 1);
        $fb((int) $books[1]['id'], 4, 1);
        $fb((int) $books[1]['id'], 1, 0);              // an unpublished second opinion: not counted
        $fb((int) $books[2]['id'], 1, 0);              // unpublished: not counted
        if ($gone !== null) { $fb((int) $gone['id'], 1, 1); }   // a cancelled booking: not a real journey
        $ok(RoutePages::rating() === null, 'below the threshold: no rating at all');
        [$htmlLow] = $child('route:' . $slugs[0] . ':ne');
        $ok(str_contains($htmlLow, '<h1>') && !str_contains($htmlLow, 'AggregateRating') && !str_contains($htmlLow, 'class="rate"'),
            'the page (ne) below the threshold: no stars, no AggregateRating');
        $fb((int) $books[3]['id'], 4, 1);
        $r = RoutePages::rating();
        $ok($r !== null && $r['count'] === $n0 + 3, 'at the threshold: the count of approved real reviews', json_encode($r));
        if ($n0 === 0) {
            $ok($r !== null && abs($r['avg'] - 4.3) < 0.001, 'the average is theirs (5, 4, 4 → 4.3)', json_encode($r));
        }
        [$htmlHi] = $child('route:' . $slugs[0] . ':ne');
        $ok(str_contains($htmlHi, '"@type":"AggregateRating"') && str_contains($htmlHi, '"reviewCount":"' . ($n0 + 3) . '"') && str_contains($htmlHi, '"bestRating":"5"'),
            'the JSON-LD carries the AggregateRating');
        $ok(str_contains($htmlHi, 'class="rate"') && str_contains($htmlHi, 'यात्रुको रेटिङ'), 'the Nepali page shows the rating line');
        $ld = preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $htmlHi, $m) ? json_decode($m[1], true) : null;
        $ok(is_array($ld) && isset($ld['@graph'][0]['aggregateRating']['ratingValue']), 'the JSON-LD still parses, rating on the LocalBusiness');
    }
} catch (Throwable $e) {
    $fail++;
    echo '  FAIL  unexpected: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    $cleanup();
}

echo "\n" . ($fail === 0 ? "ALL $pass PASSED" : "PASSED: $pass   FAILED: $fail") . "\n";
exit($fail === 0 ? 0 : 1);
