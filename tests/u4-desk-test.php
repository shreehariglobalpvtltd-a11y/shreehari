<?php
/**
 * =====================================================================
 *  u4-desk-test.php — unit u4-desk (19 Nov 2026).
 *
 *  1. NP-1 CounterDesk::openNepalBranch: reuse NPJ for Nepalgunj, idempotent,
 *     an explicit desk, a new NP<town>n desk (NP / NPR / entity NP / toIndia /
 *     ne / address = nepal_office), $mayCreate=false refuses, codeForSale.
 *  2. NP-1 SmartBook: a Nepal-desk seller opens on toIndia and lists it first;
 *     a customer is unchanged.
 *  3. NP-3 Boarding::clockClose: r7 all 17:00 Nepal time, r2 online 19:00 IST,
 *     office never, future date never, '{}' = off; QuickTicket::plan refuses an
 *     explicit today with 'बुकिङ बन्द · Booking closed' and skips to a later day
 *     (clockNote) when the date is automatic; BookingService::create refuses.
 *  4. AG-4: an agent seller with ANOTHER agent's code -> 403; own code passes
 *     that gate; a non-agent (office) seller may credit any agent.
 *  5. Cash note cleaning; alt-country default.
 *
 *  Throwaway rows on the LOCAL shared shari_test: one inactive admin
 *  'zztest_u4_<pid>' + its profile, desks NPZZT*, audit rows - all deleted at
 *  the end. Settings are injected in memory only (never written).
 *
 *     php -c .claude/php-dev.ini tests/u4-desk-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/smartbook.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function signIn(?array $a): void {
    if ($a === null) { unset($_SESSION[ADMIN_SESSION_KEY]); return; }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
        'role' => (string) $a['role'], 'permissions' => [], 'last_seen' => time(),
        'must_change_pw' => false, 'via_otp' => false,
    ];
}
/** Put a setting into the request cache only (never the database). */
function fakeSetting(string $key, ?string $value, string $type = 'json'): void {
    Settings::get('__warm__');
    $rc = new ReflectionClass('Settings');
    $c  = $rc->getProperty('cache'); $c->setAccessible(true);
    $d  = $rc->getProperty('decoded'); $d->setAccessible(true);
    $cache = $c->getValue(); $dec = $d->getValue();
    if ($value === null) { unset($cache[$key]); } else { $cache[$key] = ['value' => $value, 'type' => $type, 'public' => false]; }
    unset($dec[$key]);
    $c->setValue(null, $cache); $d->setValue(null, $dec);
}

$PID   = getmypid();
$uname = 'zztest_u4_' . $PID;
$aid   = 0;
$auditFrom = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$today = todayISO();

try {
    $aid = (int) Database::insert('admins', [
        'username' => $uname, 'password_hash' => password_hash('x' . $PID, PASSWORD_DEFAULT),
        'full_name' => 'ZZTEST u4 desk', 'role' => 'agent', 'is_active' => 0, 'phone' => '9000011' . substr((string) $PID, -3) . '1',
    ]);
    $me = ['id' => $aid, 'username' => $uname, 'full_name' => 'ZZTEST u4 desk', 'role' => 'agent'];

    echo "\n=== 1. NP-1 openNepalBranch ===\n";
    $np = CounterDesk::nepalDesks(true);
    check('there is at least one active Nepal desk locally', $np !== [], implode(',', array_keys($np)));
    $npj = CounterDesk::openNepalBranch($aid, 'Nepalgunj');
    check('Nepalgunj reuses an existing Nepal desk (no new row)', isset($np[$npj]), $npj);
    check('admin_profiles.counter_code is set', CounterDesk::forAdmin($aid)['code'] === $npj);
    check('codeForSale returns it', CounterDesk::codeForSale($aid) === $npj);
    check('the desk sells toIndia', CounterDesk::direction($npj) === 'toIndia');
    $rows0 = (int) Database::scalar('SELECT COUNT(*) FROM counter_locations');
    check('second call is idempotent', CounterDesk::openNepalBranch($aid, 'Somewhere') === $npj
        && (int) Database::scalar('SELECT COUNT(*) FROM counter_locations') === $rows0);
    $other = '';
    foreach ($np as $c => $d) { if ($c !== $npj) { $other = (string) $c; break; } }
    if ($other !== '') {
        check('an explicit Nepal desk is assigned', CounterDesk::openNepalBranch($aid, 'x', $other) === $other
            && CounterDesk::forAdmin($aid)['code'] === $other);
    }
    $refused = false;
    try { CounterDesk::openNepalBranch($aid, 'x', 'SRT'); } catch (InvalidArgumentException $e) { $refused = true; }
    check('an India desk code is refused', $refused);

    // a fresh town: clear the desk first, then create
    Database::query('UPDATE admin_profiles SET counter_code = NULL WHERE admin_id = :a', ['a' => $aid]);
    $refused = false;
    try { CounterDesk::openNepalBranch($aid, 'Zztownu', null, false); } catch (RuntimeException $e) { $refused = true; }
    check('mayCreate=false refuses a new desk', $refused);
    $new = CounterDesk::openNepalBranch($aid, 'Zztownu');
    $nd  = CounterDesk::get($new);
    check('new desk code NP + ZZT + n', preg_match('/^NPZZT\d+$/', $new) === 1, $new);
    check('new desk is NP / NPR / entity NP / toIndia / ne',
        $nd !== null && $nd['country'] === 'NP' && $nd['currency'] === 'NPR' && $nd['entity'] === 'NP'
        && $nd['default_direction'] === 'toIndia' && $nd['lang'] === 'ne' && $nd['default_from'] === 'Rupaidiha');
    check('new desk address = nepal_office (never invented)', (string) ($nd['address'] ?? '') === trim(Settings::getString('nepal_office', '')));
    check('assigned to the person', CounterDesk::forAdmin($aid)['code'] === $new);
    $conv = CounterDesk::convert(2800.0, $new);
    $rate = CounterDesk::rate($new);
    check('convert(2800) at the desk rate', abs((float) ($conv['amount'] ?? 0) - round(2800 * $rate)) < 1.01, json_encode($conv));
    check('audit counter.np_branch written', (int) Database::scalar(
        "SELECT COUNT(*) FROM audit_logs WHERE id > :f AND action = 'counter.np_branch' AND entity_id = :a",
        ['f' => $auditFrom, 'a' => (string) $aid]) >= 2);

    // fixer (19 Nov): a town matches the desk's TOWN only, never default_from / a substring
    check('town match: Nepalgunj — Puspalal Chowk ~ nepalgunj', CounterDesk::deskTownMatches('Nepalgunj — Puspalal Chowk', 'Nepalgunj'));
    check('town match: Kohalpur Chowk ~ kohalpur', CounterDesk::deskTownMatches('Kohalpur Chowk', 'kohalpur'));
    check('town match: gunj / nepal / chowk / rupaidiha do not match', !CounterDesk::deskTownMatches('Nepalgunj — Puspalal Chowk', 'gunj')
        && !CounterDesk::deskTownMatches('Nepalgunj — Puspalal Chowk', 'nepal')
        && !CounterDesk::deskTownMatches('Kohalpur Chowk', 'chowk')
        && !CounterDesk::deskTownMatches('Nepalgunj — Puspalal Chowk', 'Rupaidiha'));
    Database::query('UPDATE admin_profiles SET counter_code = NULL WHERE admin_id = :a', ['a' => $aid]);
    $refused = false;
    try { CounterDesk::openNepalBranch($aid, 'Rupaidiha', null, false); } catch (RuntimeException $e) { $refused = true; }
    check("'Rupaidiha' is not silently linked to an existing Nepal desk", $refused && CounterDesk::forAdmin($aid)['code'] === '');
    $refused = false;
    try { CounterDesk::openNepalBranch($aid, 'Gunj', null, false); } catch (RuntimeException $e) { $refused = true; }
    check("'Gunj' is not silently linked to NPJ", $refused);
    check('Nepalgunj (mixed case) still reuses its desk', isset($np[CounterDesk::openNepalBranch($aid, 'nepalGUNJ', null, false)]));

    echo "\n=== 2. NP-1 SmartBook desk direction ===\n";
    signIn($me);
    $mode = SmartBook::sessionMode('');
    if ($mode === 'customer') {
        echo "  SKIP  agent role cannot sell in this DB (no bookings.edit)\n";
    } else {
        check('deskDirection = toIndia for a Nepal desk seller', SmartBook::deskDirection() === 'toIndia');
        $dirs = SmartBook::directions();
        check('directions() lists toIndia first', ($dirs[0]['direction'] ?? '') === 'toIndia' || count($dirs) < 2);
        $df = SmartBook::defaults([], $mode);
        check('defaults() opens on toIndia', ($df['direction'] ?? '') === 'toIndia', (string) ($df['direction'] ?? ''));
        $df2 = SmartBook::defaults(['direction' => 'toNepal'], $mode);
        check('an explicit direction still wins', ($df2['direction'] ?? '') === 'toNepal');
    }
    signIn(null);
    check('customer: deskDirection empty', SmartBook::deskDirection() === '');
    $dirsC = SmartBook::directions();
    check('customer: toNepal stays first', ($dirsC[0]['direction'] ?? '') === 'toNepal' || count($dirsC) < 2);

    echo "\n=== 3. NP-3 sales closing clock ===\n";
    fakeSetting('sales_close_clock', null);   // absent row = owner default
    $r7 = ['route_code' => 'r7', 'from_city' => 'Rupaidiha', 'to_city' => 'Surat'];
    $r2 = ['route_code' => 'r2', 'from_city' => 'Surat', 'to_city' => 'Rupaidiha'];
    $npt = static fn(string $hm): int => (new DateTimeImmutable($today . ' ' . $hm, new DateTimeZone('Asia/Kathmandu')))->getTimestamp();
    $ist = static fn(string $hm): int => (new DateTimeImmutable($today . ' ' . $hm, new DateTimeZone('Asia/Kolkata')))->getTimestamp();
    $c = Boarding::clockClose($r7, $today, 'agent', null, $npt('16:59'));
    check('r7 agent 16:59 NPT: open', $c !== null && $c['closed'] === false);
    $c = Boarding::clockClose($r7, $today, 'agent', null, $npt('17:01'));
    check('r7 agent 17:01 NPT: closed', $c !== null && $c['closed'] === true);
    check('label reads बुकिङ बन्द · Booking closed 17:00', $c !== null && str_contains($c['label'], 'बुकिङ बन्द · Booking closed 17:00'));
    foreach (['online', 'counter', 'staff', 'whatsapp', 'ai'] as $ch) {
        $c = Boarding::clockClose($r7, $today, $ch, null, $npt('17:01'));
        check("r7 $ch 17:01 NPT: closed", $c !== null && $c['closed']);
    }
    check('r7 office: never', Boarding::clockClose($r7, $today, 'office', null, $npt('23:00')) === null);
    check('r7 superadmin ctx: never', Boarding::clockClose($r7, $today, 'agent', ['role' => 'superadmin'], $npt('23:00')) === null);
    check('r7 tomorrow: no clock', Boarding::clockClose($r7, addDaysISO($today, 1), 'agent') === null);
    check('17:00 NPT = 16:45 IST', ($c2 = Boarding::clockClose($r7, $today, 'agent', null, 0)) !== null && $c2['closesAt'] === $ist('16:45'));
    $c = Boarding::clockClose($r2, $today, 'online', null, $ist('19:01'));
    check('r2 online 19:01 IST: closed', $c !== null && $c['closed']);
    $c = Boarding::clockClose($r2, $today, 'online', null, $ist('18:59'));
    check('r2 online 18:59 IST: open', $c !== null && !$c['closed']);
    check('r2 whatsapp follows online', ($c = Boarding::clockClose($r2, $today, 'whatsapp', null, $ist('19:01'))) !== null && $c['closed']);
    foreach (['agent', 'counter', 'staff'] as $ch) {
        check("r2 $ch: per-stop cut-off only (no clock)", Boarding::clockClose($r2, $today, $ch, null, $ist('22:00')) === null);
    }
    fakeSetting('sales_close_clock', '{}');
    check("'{}' switches the clock off", Boarding::clockClose($r7, $today, 'agent', null, $npt('23:00')) === null);
    check('salesChannel: superadmin = office', Boarding::salesChannel(1) === 'office');
    check('salesChannel: agent row = agent', Boarding::salesChannel($aid) === 'agent');
    check('salesChannel: nobody = online', Boarding::salesChannel(0) === 'online');

    // QuickTicket / create(): r7 closed all day for non-office channels
    $r7row = Database::fetch("SELECT * FROM routes WHERE route_code = 'r7' AND is_active = 1 LIMIT 1");
    if ($r7row === null) {
        echo "  SKIP  no active r7 locally\n";
    } else {
        // every active return run (the local DB has extra rotation routes besides r7)
        $rule = [];
        foreach (QuickTicket::routes() as $qr) {
            if (($qr['direction'] ?? '') === 'toIndia') { $rule[(string) $qr['route_code']] = ['all' => '00:00', 'tz' => 'Asia/Kolkata']; }
        }
        $rule['r7'] = ['all' => '00:00', 'tz' => 'Asia/Kolkata'];
        fakeSetting('sales_close_clock', json_encode($rule));
        $msg = '';
        try { QuickTicket::plan(['direction' => 'toIndia', 'date' => $today, 'channel' => 'agent']); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
        check('plan(explicit today, agent) refuses with the clock', str_contains($msg, 'बुकिङ बन्द · Booking closed 00:00'), $msg);
        $plan = null; $msg = '';
        try { $plan = QuickTicket::plan(['direction' => 'toIndia', 'channel' => 'agent']); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
        if ($plan !== null) {
            check('plan(auto date) moves to a later day', $plan['date'] > $today, (string) $plan['date']);
            check('plan carries clockNote', is_array($plan['clockNote'] ?? null) && str_contains((string) $plan['clockNote']['label'], 'Booking closed'));
        } else {
            echo "  SKIP  auto-date plan unavailable locally: $msg\n";
        }
        $msg = '';
        try { QuickTicket::plan(['direction' => 'toIndia', 'date' => $today, 'channel' => 'office']); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
        check('plan(today, office) is not stopped by the clock', !str_contains($msg, 'Booking closed 00:00'), $msg);

        // create(): a customer for today's r7 (refused before any row is written)
        signIn(null);
        $req = ['routeId' => (int) $r7row['id'], 'travelDate' => $today, 'seats' => ['L1'],
                'passengers' => [['seat' => 'L1', 'name' => 'ZZTEST u4', 'gender' => 'Male']],
                'contact' => ['phone' => '9000011' . substr((string) $PID, -3)], 'boarding' => '', 'drop' => ''];
        $msg = '';
        try { BookingService::create($req); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
        $cut = Boarding::status((int) $r7row['id'], $today, 'Rupaidiha');
        if ($cut['open']) {
            check('create(): today r7 refused by the clock', str_contains($msg, 'Booking closed 00:00'), $msg);
        } else {
            check('create(): today r7 refused (pickup cut-off already passed)', $msg !== '', $msg);
        }
        check('create(): nothing written for the test phone', (int) Database::scalar(
            'SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE :p', ['p' => '%9000011' . substr((string) $PID, -3) . '%']) === 0);
        fakeSetting('sales_close_clock', null);
    }

    // fixer (19 Nov): which channel plan() judges - a staff caller with neither a
    // seller id nor a session (WhatsApp agent desk / AI staff tools) is 'staff'
    signIn(null);
    check('clockChannel: staff caller, no seller, no session = staff', QuickTicket::clockChannel([], false) === 'staff');
    check('clockChannel: customer = online', QuickTicket::clockChannel([], true) === 'online');
    check('clockChannel: sellerId agent = agent', QuickTicket::clockChannel(['sellerId' => $aid], false) === 'agent');
    check('clockChannel: sellerId superadmin = office', QuickTicket::clockChannel(['sellerId' => 1], false) === 'office');
    check('clockChannel: an explicit channel wins', QuickTicket::clockChannel(['channel' => 'whatsapp', 'sellerId' => 1], false) === 'whatsapp');
    signIn($me);
    check('clockChannel: signed-in agent session = agent', QuickTicket::clockChannel([], false) === 'agent');
    signIn(null);
    // r2 "online" closed: a staff WhatsApp-desk plan (no seller, no session) is NOT stopped by it
    $ruleOut = [];
    foreach (QuickTicket::routes() as $qr) {
        if (($qr['direction'] ?? '') === 'toNepal') { $ruleOut[(string) $qr['route_code']] = ['online' => '00:00', 'tz' => 'Asia/Kolkata']; }
    }
    if ($ruleOut === []) {
        echo "  SKIP  no active outbound run locally\n";
    } else {
        fakeSetting('sales_close_clock', json_encode($ruleOut));
        $msg = '';
        try { QuickTicket::plan(['direction' => 'toNepal', 'date' => $today]); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
        check('plan(today, toNepal, staff WhatsApp path) ignores the online-only clock', !str_contains($msg, 'Booking closed 00:00'), $msg);
        $msg = '';
        try { QuickTicket::plan(['direction' => 'toNepal', 'date' => $today, 'customer' => true]); } catch (RuntimeException $e) { $msg = $e->getMessage(); }
        check('plan(today, toNepal, customer) is stopped by the online clock', str_contains($msg, 'Booking closed 00:00'), $msg);
        fakeSetting('sales_close_clock', null);
    }

    echo "\n=== 4. AG-4 own-name selling ===\n";
    $agents = array_values(array_filter(AgentWallet::sellableAgents(), static fn(array $a): bool => $a['type'] !== 'staff'));
    $ids = [];
    foreach ($agents as $a) {
        $id = AgentWallet::resolveAgentCodeFromString($a['code']);
        if ($id !== null) { $ids[] = [$id, $a['code']]; }
        if (count($ids) === 2) { break; }
    }
    if (count($ids) < 2) {
        echo "  SKIP  fewer than two coded agents locally\n";
    } else {
        [[$a1, $c1], [$a2, $c2]] = $ids;
        $req = ['routeId' => (int) ($r7row['id'] ?? 7), 'travelDate' => '2026-01-01', 'seats' => ['L1'],
                'passengers' => [['seat' => 'L1', 'name' => 'ZZTEST u4', 'gender' => 'Male']],
                'contact' => ['phone' => '9000011' . substr((string) $PID, -3)], 'referralCode' => $c2];
        $code = 0; $msg = '';
        try { BookingService::create($req, ['adminId' => $a1, 'source' => 'agent', 'paymentMethod' => 'cash']); }
        catch (RuntimeException $e) { $code = $e->getCode(); $msg = $e->getMessage(); }
        check('agent seller + another agent code -> 403', $code === 403 && str_contains($msg, 'आफ्नै कोडमा'), "$code $msg");
        $code = 0; $msg = '';
        try { BookingService::create(['referralCode' => $c1] + $req, ['adminId' => $a1, 'source' => 'agent', 'paymentMethod' => 'cash']); }
        catch (RuntimeException $e) { $code = $e->getCode(); $msg = $e->getMessage(); }
        check('agent seller + own code passes the gate (later gate refuses the old date)', $code !== 403 && $msg !== '', "$code $msg");
        $code = 0; $msg = '';
        try { BookingService::create($req, ['adminId' => 1, 'source' => 'counter', 'paymentMethod' => 'cash']); }
        catch (RuntimeException $e) { $code = $e->getCode(); $msg = $e->getMessage(); }
        check('office seller may credit any agent (no 403)', $code !== 403, "$code $msg");
        // a signed-in agent on the public path (no seller)
        signIn(['id' => $a1, 'username' => 'a1', 'full_name' => 'a1', 'role' => 'agent']);
        $code = 0;
        try { BookingService::create($req); } catch (RuntimeException $e) { $code = $e->getCode(); }
        check('agent session, public path, another code -> 403', $code === 403);
        signIn(null);
    }

    echo "\n=== 5. cash note + alt country ===\n";
    check('full', BookingService::cashNoteClean(['when' => 'full']) === ['when' => 'full']);
    check('partial needs an amount', BookingService::cashNoteClean(['when' => 'partial']) === null);
    $p = BookingService::cashNoteClean(['when' => 'partial', 'amount' => '1500', 'date' => '2026-11-25', 'note' => 'बाँकी भोलि']);
    check('partial keeps amount, date, note', $p !== null && $p['amount'] === 1500.0 && $p['date'] === '2026-11-25' && $p['note'] === 'बाँकी भोलि');
    check('later with a bad date drops the date', BookingService::cashNoteClean(['when' => 'later', 'date' => '2026-02-31']) === ['when' => 'later']);
    check('junk = null', BookingService::cashNoteClean(['when' => 'maybe']) === null && BookingService::cashNoteClean('x') === null);
    check('JSON string accepted', BookingService::cashNoteClean('{"when":"later","date":"2026-12-01"}') === ['when' => 'later', 'date' => '2026-12-01']);
} catch (Throwable $e) {
    check('no unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    signIn(null);
    if ($aid > 0) {
        Database::query('DELETE FROM admin_profiles WHERE admin_id = :a', ['a' => $aid]);
        Database::query('DELETE FROM admins WHERE id = :a', ['a' => $aid]);
        Database::query("DELETE FROM audit_logs WHERE id > :f AND entity_id = :a AND action IN ('counter.np_branch')", ['f' => $auditFrom, 'a' => (string) $aid]);
    }
    Database::query("DELETE FROM counter_locations WHERE code LIKE 'NPZZT%'");
    CounterDesk::flush();
    CounterDesk::syncSettingsMirror();
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL PASS\033[0m" : "\033[31m$FAIL FAILED\033[0m") . " ($PASS passed)\n";
exit($FAIL === 0 ? 0 : 1);
