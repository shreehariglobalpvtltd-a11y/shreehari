<?php
/**
 * 19 Nov 2026 (WA-6) — "आजको हिसाब बुझाउने · Day close": CounterShift::closeDay()
 * writes ONE counter_day_close row per desk + day (a correction updates it, with
 * an audit), freezes the register (CounterBook) beside the count, refuses
 * another person's desk, and touches no booking / payment. Plus the shift
 * page renders the card over HTTP (SHG_TEST_BASE, default localhost:8963).
 *
 *   php -c <ini> tests/u10-day-close-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/u10-seed-lib.php';
require_once INCLUDE_PATH . '/counterbook.php';
require_once INCLUDE_PATH . '/countershift.php';

echo "\n=== u10 · Day close (counter_day_close) ===\n\n";
try {
    check('migration applied: counter_day_close exists', CounterShift::dayCloseReady());
    check('switch counter_day_close_on defaults ON', Settings::getBool('counter_day_close_on', true) === CounterShift::dayCloseOn());
    $s = u10_seed();
    $b = $s['b'];
    $snap = static fn(): string => md5((string) json_encode([
        Database::fetchAll('SELECT id, status, total_amount FROM bookings WHERE contact_phone LIKE "90000102%" ORDER BY id'),
        Database::fetchAll('SELECT p.id, p.status, p.amount, p.verified_at FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE b.contact_phone LIKE "90000102%" ORDER BY p.id'),
    ]));
    $before = $snap();

    /* the office closes MSA for the fixture day */
    $msaCash = (float) $b['M1']['total_amount'];
    $r1 = CounterShift::closeDay($s['boss'], 'MSA', U10_FD, $msaCash - 100, 0.0, null, 'u10 note', 'ZZTEST u10 Boss', true);
    check('a row is written with the register frozen beside the count', (string) $r1['day_key'] === U10_FD . '|MSA'
        && abs((float) $r1['cash_inr'] - $msaCash) < 0.01 && (int) $r1['tickets'] === 3 && (int) $r1['pax'] === 4);
    check('expected = register cash, variance = counted - expected (short 100)', abs((float) $r1['expected_cash'] - $msaCash) < 0.01 && abs((float) $r1['variance'] + 100) < 0.01);
    check('verdict short', CounterShift::verdict((float) $r1['variance']) === 'short');
    $r2 = CounterShift::closeDay($s['boss'], 'MSA', U10_FD, $msaCash - 50, 50.0, null, 'corrected', 'ZZTEST u10 Boss', true);
    $n  = (int) Database::scalar('SELECT COUNT(*) FROM counter_day_close WHERE day_key = :k', ['k' => U10_FD . '|MSA'], 0);
    check('a second submit for the same desk + day UPDATES the row (no second row)', $n === 1 && (int) $r2['id'] === (int) $r1['id']);
    check('expenses come off what should be in the drawer: matches now', abs((float) $r2['expected_cash'] - ($msaCash - 50)) < 0.01 && abs((float) $r2['variance']) < 0.01);
    $aud = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'dayclose.save' AND entity_id = :k", ['k' => U10_FD . '|MSA'], 0);
    check('both saves are audited', $aud >= 2, (string) $aud);

    /* NPR desk: expected is the NPR physically taken */
    $npr = round((float) $b['N1']['total_amount'] * 1.6);
    $rn = CounterShift::closeDay($s['boss'], 'NPJ', U10_FD, $npr, 0.0, null, '', 'ZZTEST u10 Boss', true);
    check('NPR desk: currency NPR, expected = NPR taken, matches', (string) $rn['currency'] === 'NPR' && abs((float) $rn['expected_cash'] - $npr) < 0.01 && abs((float) $rn['variance']) < 0.01);
    check('NPR slip prints रू', str_contains(CounterShift::dayCloseText($rn, 'Nepalgunj'), 'रू'));
    $txt = CounterShift::dayCloseText($r2, 'MSA');
    check('slip: Nepali labels, no passenger name / phone', str_contains($txt, 'टिकट') && str_contains($txt, 'नगद') && !str_contains($txt, 'ZZTEST u10 M')
        && preg_match('/\d{10}/', $txt) !== 1);

    /* who may close what */
    $err = '';
    try { CounterShift::closeDay($s['agent'], 'MSA', todayISO(), 0, 0, null, '', 'ZZTEST u10 Agent', false); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('an agent cannot close a desk', str_contains($err, 'own'), $err);
    $err = '';
    try { CounterShift::closeDay($s['agent'], 'AG:' . $s['agent2'], todayISO(), 0, 0, null, '', 'ZZTEST u10 Agent', false); } catch (Throwable $e) { $err = $e->getMessage(); }
    check("agent A cannot close agent B's day", $err !== '', $err);
    $err = '';
    try { CounterShift::closeDay($s['agent'], 'AG:' . $s['agent'], U10_FD, 0, 0, null, '', 'ZZTEST u10 Agent', false); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('an agent cannot close an old day (only today / yesterday)', $err !== '', $err);
    $own = CounterShift::closeDay($s['agent'], 'AG:' . $s['agent'], todayISO(), 0, 0, null, '', 'ZZTEST u10 Agent', false);
    check('an agent closes its own sales today', (string) $own['desk_key'] === 'AG:' . $s['agent']);
    Database::run('DELETE FROM counter_day_close WHERE id = :i', ['i' => (int) $own['id']]);
    $err = '';
    try { CounterShift::closeDay($s['boss'], 'MSA', addDaysISO(todayISO(), 1), 0, 0, null, '', 'ZZTEST u10 Boss', true); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('nobody closes a future day', $err !== '');
    $err = '';
    try { CounterShift::closeDay($s['boss'], 'MSA', U10_FD, 0, 0, null, '', 'ab', true); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('a signature (3+ letters) is required', $err !== '');
    $err = '';
    try { CounterShift::closeDay($s['boss'], 'ZZQ', U10_FD, 0, 0, null, '', 'ZZTEST u10 Boss', true); } catch (Throwable $e) { $err = $e->getMessage(); }
    check('an unknown desk is refused', $err !== '');
    check('dayCloses() lists the day', count(CounterShift::dayCloses(U10_FD)) === 2);
    check('bookings and payments untouched', $snap() === $before);
    check('shifts untouched (no counter_shifts row for the seed)', (int) Database::scalar('SELECT COUNT(*) FROM counter_shifts WHERE admin_id IN (' . (int) $s['boss'] . ',' . (int) $s['agent'] . ')', [], 0) === 0);

    /* review fix: an NPR desk that ALSO collects a pay-at-boarding ticket with
       no NPR stamped on it. The drawer holds both; the expected cash must be
       N1's stamped NPR + P1's rupees x the desk rate - not N1 alone (the old
       all-or-nothing takenNpr made an honest close read "over"). A UPI payment
       with NPR stamped is not drawer cash. */
    $p1Inr = (float) $b['P1']['total_amount'];
    Database::update('bookings', ['counter_code' => 'NPJ'], 'id = :i', ['i' => (int) $b['P1']['id']]);
    Database::update('payments', ['status' => 'verified', 'verified_by' => $s['boss'], 'verified_at' => U10_FD . ' 11:00:00', 'local_currency' => null, 'local_amount' => null, 'fx_rate' => null],
        'booking_id = :b', ['b' => (int) $b['P1']['id']]);
    $deskRate = CounterDesk::rate('NPJ');
    $wantDrawer = round($npr + $p1Inr * $deskRate, 2);
    $lineN = CounterBook::line(U10_FD, 'NPJ');
    check('NPR drawer = stamped NPR + unstamped COD x desk rate', abs(CounterShift::dayDrawer($lineN) - $wantDrawer) < 0.01,
        json_encode(['drawer' => CounterShift::dayDrawer($lineN), 'want' => $wantDrawer, 'takenNpr' => $lineN['takenNpr'] ?? null]));
    $rn2 = CounterShift::closeDay($s['boss'], 'NPJ', U10_FD, $wantDrawer, 0.0, null, '', 'ZZTEST u10 Boss', true);
    check('NPR desk: an honest close of the mixed drawer matches (no false "over")', abs((float) $rn2['expected_cash'] - $wantDrawer) < 0.01 && abs((float) $rn2['variance']) < 0.01,
        json_encode(['expected' => $rn2['expected_cash'], 'variance' => $rn2['variance']]));
    /* a UPI payment with NPR stamped stays out of the drawer */
    Database::update('payments', ['method' => 'upi', 'local_currency' => 'NPR', 'local_amount' => round($p1Inr * 1.6)], 'booking_id = :b', ['b' => (int) $b['P1']['id']]);
    check('NPR drawer ignores a non-cash payment, even with NPR stamped', abs(CounterShift::dayDrawer(CounterBook::line(U10_FD, 'NPJ')) - $npr) < 0.01);
    check('dayDrawer: a rupee line is its cash', abs(CounterShift::dayDrawer(['currency' => 'INR', 'cash' => 1234.0, 'cashLocal' => 99.0]) - 1234.0) < 0.001);

    /* the card renders for a signed-in office user */
    if (function_exists('curl_init')) {
        $base = rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8963'), '/');
        $jar = sys_get_temp_dir() . '/shg_u10_dc_' . getmypid() . '.txt';
        $http = static function (string $u, ?array $form = null) use ($base, $jar): array {
            $ch = curl_init($base . $u);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
            if ($form !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
            $raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $h = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
            return ['code' => $code, 'body' => substr($raw, $h), 'headers' => substr($raw, 0, $h)];
        };
        Database::run('UPDATE admins SET password_hash = :h WHERE id = :i', ['h' => password_hash('U10DayClose77', PASSWORD_DEFAULT), 'i' => $s['boss']]);
        require_once INCLUDE_PATH . '/quickunlock.php';
        QuickUnlock::set($s['boss'], 'pin', '583942');
        try { Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
        $lg = $http('/admin/login.php?classic=1&other=1');
        $tok = preg_match('/<meta name="csrf-token" content="([^"]+)"/', $lg['body'], $m) === 1 ? html_entity_decode($m[1])
             : (preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $lg['body'], $m) ? $m[1] : '');
        $lr = $http('/admin/login.php', [CSRF_TOKEN_NAME => $tok, 'classic' => '1', 'username' => U10_BOSS, 'password' => 'U10DayClose77']);
        if ($lr['code'] !== 302) {
            /* the one door now asks "who" first, then the password (19 Nov 2026, LG-1) */
            $csrfOf = static fn(string $h): string => preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1 ? html_entity_decode($m[1])
                : (preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '');
            $w = $http('/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($lr['body']) ?: $tok, 'classic' => '1', 'mode' => 'who', 'who' => U10_BOSS, 'cc' => '91']);
            $lr = $http('/admin/login.php', [CSRF_TOKEN_NAME => $csrfOf($w['body']) ?: $tok, 'classic' => '1', 'mode' => 'pw', 'password' => 'U10DayClose77']);
        }
        $pg = $http('/admin/shift.php?dcdesk=MSA&dcday=' . U10_FD);
        check('shift.php shows the Day close card with the register', $pg['code'] === 200 && str_contains($pg['body'], 'आजको हिसाब बुझाउने · Day close')
            && str_contains($pg['body'], 'बुझाइसकियो'), 'HTTP ' . $pg['code']);
        @unlink($jar);
    }
} catch (Throwable $e) {
    $GLOBALS['FAIL']++;
    echo '  ERROR  ' . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}
u10_cleanup();
echo "\n  {$GLOBALS['PASS']} passed, {$GLOBALS['FAIL']} failed\n";
exit($GLOBALS['FAIL'] === 0 ? 0 : 1);
