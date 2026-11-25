<?php
/**
 * =====================================================================
 *  wx1-c2-test.php — 25 Nov 2026 (wx1-c2): people + passenger extras.
 *
 *    - TravelGuide: onward rows need a town AND a real detail (no made-up
 *      data is ever shown), phones are cleaned, luggage 20 + 7 kg by default
 *      and range-checked, the owner's 4 facilities by default
 *    - the route pages' a6 answer (also the WhatsApp bot's luggage answer)
 *      reads luggage_free_kg / cabin_bag_kg (Devanagari digits in Nepali)
 *    - /travel-info: the onward card, the "ask the Nepalgunj office" line when
 *      empty, the rows when filled, gone with travel_onward_on off; the
 *      checklist luggage line follows the settings
 *    - /api/staff-list.php?for=home is empty with home_staff_list_on off
 *    - admin/owner-setup.php act=onward / act=luggage save, CSRF refused
 *    - app.template.html + 05-router.js: partners kept, crew card, guide,
 *      luggage, easy WhatsApp, PNR + phone, women legend always shown,
 *      date / pickup folded and optional
 *    - the migration inserts its rows (inside a rolled-back transaction)
 *
 *  shari_test only; every settings row it touches is put back exactly.
 *
 *    php -c .claude/php-dev.ini tests/wx1-c2-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_GET = (array) ($cfg['get'] ?? []);
    $page = (string) ($cfg['page'] ?? '');
    $_SERVER['SCRIPT_NAME'] = $_SERVER['REQUEST_URI'] = '/' . $page;
    $post = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    if (str_starts_with($page, 'api/')) {   // api/_init.php boots by itself
        require dirname(__DIR__) . '/' . $page;
        exit(0);
    }
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    if (!empty($cfg['admin'])) {
        $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) $cfg['admin']]);
        if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
        $_SESSION[ADMIN_SESSION_KEY] = [
            'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
            'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
        ];
    }
    if ($post !== []) {
        $_POST = $post;
        if (empty($cfg['no_csrf'])) { $_POST[CSRF_TOKEN_NAME] = Security::csrfToken(); }
    }
    if (($cfg['path'] ?? '') !== '') {   // a content page such as /offices (index.php dispatch)
        require_once INCLUDE_PATH . '/contentpages.php';
        ContentPages::dispatch((string) $cfg['path']);
        exit(0);
    }
    require dirname(__DIR__) . '/' . $page;
    exit(0);
}

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/travelguide.php';
require_once INCLUDE_PATH . '/routepages.php';
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function page(array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out;
}

echo "\n=== wx1-c2: people + passenger extras ===\n\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n0 passed, 0 failed\n"; exit(0); }

$TOUCH = ['onward_buses_json', 'travel_onward_on', 'travel_npj_only_on', 'luggage_free_kg', 'cabin_bag_kg', 'bus_facilities_json', 'home_staff_list_on', 'nepal_phone'];
$saved = [];
foreach ($TOUCH as $k) { $saved[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]); }
$restore = static function () use ($saved): void {
    foreach ($saved as $k => $row) {
        Database::query('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        if ($row !== null) {
            unset($row['id']);
            $cols = array_keys($row);
            Database::query('INSERT INTO settings (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')', $row);
        }
    }
    Settings::flush();
};
$auditFrom = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);

try {
    foreach (['onward_buses_json', 'luggage_free_kg', 'cabin_bag_kg', 'bus_facilities_json', 'travel_onward_on', 'travel_npj_only_on', 'home_staff_list_on'] as $k) {
        Database::query('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
    }
    Settings::flush();

    /* ---- TravelGuide ---- */
    check('onward empty by default', TravelGuide::onward() === []);
    $c = TravelGuide::clean([
        ['to' => 'Surkhet', 'to_ne' => 'सुर्खेत'],                                   // name only -> dropped
        ['to' => 'Dang', 'times' => '07:00', 'phone' => '+977 98(1)-x'],
        ['to' => '', 'times' => '08:00'],                                              // no town -> dropped
        'junk',
    ]);
    check('clean keeps only a town with a detail', count($c) === 1 && $c[0]['to'] === 'Dang', json_encode($c));
    check('clean strips a phone to digits + - /', ($c[0]['phone'] ?? '') === '+977 981-', (string) ($c[0]['phone'] ?? ''));
    check('clean caps at 20 rows', count(TravelGuide::clean(array_fill(0, 30, ['to' => 'X', 'note' => 'n']))) === 20);
    check('dial: Nepali mobile gets +977', TravelGuide::dial('9812345678') === '+9779812345678');
    check('dial: first of several numbers', TravelGuide::dial('+977 81-520000 / 9800000000') === '+97781520000');
    check('luggage 20 + 7 by default', TravelGuide::luggage() === ['free' => 20, 'cabin' => 7]);
    check('facilities: the owner\'s 4 by default', TravelGuide::facilities() === TravelGuide::FACILITIES && count(TravelGuide::FACILITIES) === 4);
    check('one onward town prefilled: Surkhet, name only (bus is NPJ only)', TravelGuide::TOWNS === [['Surkhet', 'सुर्खेत']]);
    check('travel_npj_only_on defaults ON', TravelGuide::npjOnlyOn());
    Settings::set('luggage_free_kg', '500', 'int', 'booking'); Settings::flush();
    check('luggage out of range falls back to 20', TravelGuide::luggage()['free'] === 20);

    /* ---- the route pages' a6 (the WhatsApp bot's luggage answer) ---- */
    Database::query("DELETE FROM settings WHERE skey = 'luggage_free_kg'"); Settings::flush();
    $en = RoutePages::faqAnswer('a6', 'en');
    check('a6 en says 20 kg + 7 kg', str_contains($en, '20 kg') && str_contains($en, '7 kg') && !str_contains($en, '{'), $en);
    $neA = RoutePages::faqAnswer('a6', 'ne');
    check('a6 ne in Devanagari digits', str_contains($neA, '२० किलो') && str_contains($neA, '७ किलो'), $neA);
    Settings::set('luggage_free_kg', '25', 'int', 'booking'); Settings::set('cabin_bag_kg', '5', 'int', 'booking'); Settings::flush();
    $hi = RoutePages::faqAnswer('a6', 'hi');
    check('a6 follows the settings', str_contains($hi, '25 किलो') && str_contains($hi, '5 किलो'), $hi);

    /* ---- /travel-info ---- */
    $html = page(['page' => 'travel-info.php', 'get' => ['lang' => 'ne']]);
    check('travel-info renders', str_contains($html, '</html>'), substr($html, 0, 200));
    check('travel-info: checklist luggage from settings', str_contains($html, '25 kg') && str_contains($html, '२५ किलो') && !str_contains($html, '{LUG'));
    check('travel-info: onward card + ask-the-office line when empty', str_contains($html, 'id="onward"') && str_contains($html, 'नेपालगञ्ज कार्यालयलाई सोध्नुहोस्'));
    Settings::set('nepal_phone', '+977 9800000001', 'string', 'company'); Settings::flush();
    $html = page(['page' => 'travel-info.php', 'get' => ['lang' => 'en']]);
    check('travel-info: ask line carries the saved Nepal office number', str_contains($html, 'tel:+9779800000001'));
    /* 25 Nov 2026 (owner: "our bus is NPJ only"): empty onward -> the NPJ-only sentence, the real office number, no other town */
    $onw = preg_match('#<div class="card" id="onward">.*?</div>#s', $html, $mo) === 1 ? $mo[0] : '';
    check('onward empty: NPJ-only sentence in en/hi/ne', str_contains($onw, 'Our bus goes only as far as Nepalgunj (Pushpalal Chowk)')
        && str_contains($onw, 'हमारी बस सिर्फ़ नेपालगंज (पुष्पलाल चोक) तक जाती है') && str_contains($onw, 'हाम्रो बस नेपालगञ्ज (पुष्पलाल चोक) सम्म मात्र जान्छ')
        && str_contains($onw, 'नेपालगञ्ज–सुर्खेत सेवा सुरु भए यहीँ जानकारी दिइनेछ'), substr($onw, 0, 300));
    check('onward empty: carries the real Nepal office number', str_contains($onw, 'href="tel:+9779800000001"') && str_contains($onw, '+977 9800000001'));
    $towns5 = ['Dang', 'Butwal', 'Pokhara', 'Kathmandu', 'Dhangadhi', 'दाङ', 'दांग', 'बुटवल', 'पोखरा', 'काठमाडौं', 'काठमांडू', 'धनगढी', 'धनगढ़ी'];
    $hit = array_values(array_filter($towns5, static fn(string $t): bool => str_contains($html, $t)));
    check('travel-info: none of Dang/Butwal/Pokhara/Kathmandu/Dhangadhi anywhere on the page', $hit === [], implode(',', $hit));
    $tiS = (string) file_get_contents(dirname(__DIR__) . '/travel-info.php');
    check('travel-info source: none of the five towns typed', array_filter($towns5, static fn(string $t): bool => str_contains($tiS, $t)) === []);
    /* review: the onward line's last-resort number is the NPJ office's own row (code NPJ / town Nepalgunj), another Nepal office only if NPJ has none */
    check('travel-info source: onward fallback prefers the NPJ office row', str_contains($tiS, "=== 'NPJ'")
        && str_contains($tiS, '$npOfficePhone = $npjOfficePhone !== \'\' ? $npjOfficePhone : $npOtherPhone;'));
    Settings::set('travel_npj_only_on', '0', 'bool', 'company'); Settings::flush();
    $html2 = page(['page' => 'travel-info.php', 'get' => ['lang' => 'en']]);
    check('travel_npj_only_on off: short ask line, still no town list', str_contains($html2, 'For buses beyond Nepalgunj, ask our Nepalgunj office:') && !str_contains($html2, 'Pushpalal Chowk). We have no bus') && !str_contains($html2, 'Pokhara'));
    Database::query("DELETE FROM settings WHERE skey = 'travel_npj_only_on'"); Settings::flush();
    Settings::set('onward_buses_json', [['to' => 'Pokhara', 'to_ne' => 'पोखरा', 'times' => 'TEST 05:30', 'operator' => 'TEST <b>Op</b>', 'phone' => '9800000002', 'note' => '']], 'json', 'company');
    Settings::flush();
    $html = page(['page' => 'travel-info.php', 'get' => ['lang' => 'en']]);
    check('travel-info: a filled row is shown, escaped', str_contains($html, 'TEST 05:30') && str_contains($html, 'TEST &lt;b&gt;Op&lt;/b&gt;') && str_contains($html, 'tel:+9779800000002'));
    check('travel-info: no ask line once a row exists', !str_contains($html, 'ask our Nepalgunj office'));
    Settings::set('travel_onward_on', '0', 'bool', 'company'); Settings::flush();
    $html = page(['page' => 'travel-info.php', 'get' => ['lang' => 'en']]);
    check('travel-info: travel_onward_on off hides the card', !str_contains($html, 'id="onward"') && str_contains($html, '</html>'));

    /* ---- /api/staff-list.php?for=home ---- */
    Settings::set('home_staff_list_on', '0', 'bool', 'general'); Settings::flush();
    Database::query("DELETE FROM rate_limits WHERE bucket = 'staff_list'");
    $j = json_decode(trim(page(['page' => 'api/staff-list.php', 'get' => ['for' => 'home']])), true);
    check('staff-list for=home empty when the switch is off', is_array($j) && ($j['ok'] ?? false) === true && ($j['count'] ?? -1) === 0 && ($j['items'] ?? null) === []);
    $j2 = json_decode(trim(page(['page' => 'api/staff-list.php'])), true);
    check('staff-list without for=home is not touched by the switch', is_array($j2) && ($j2['ok'] ?? false) === true && array_key_exists('items', $j2));
    Settings::set('home_staff_list_on', '1', 'bool', 'general'); Settings::flush();
    $j3 = json_decode(trim(page(['page' => 'api/staff-list.php', 'get' => ['for' => 'home']])), true);
    check('staff-list for=home answers the normal list when on', is_array($j3) && ($j3['ok'] ?? false) === true && ($j3['count'] ?? -1) === ($j2['count'] ?? -2));
    $first = is_array($j3['items'][0] ?? null) ? array_keys($j3['items'][0]) : ['name', 'role', 'roleEn', 'roleNe', 'district', 'loc', 'photo', 'phone', 'tel', 'wa'];
    check('staff-list item keys unchanged', $first === ['name', 'role', 'roleEn', 'roleNe', 'district', 'loc', 'photo', 'phone', 'tel', 'wa'], implode(',', $first));
    Database::query("DELETE FROM rate_limits WHERE bucket = 'staff_list'");

    /* ---- admin/owner-setup.php ---- */
    $super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 AND must_change_pw = 0 ORDER BY id LIMIT 1", [], 0);
    if ($super > 0) {
        Settings::set('onward_buses_json', [], 'json', 'company'); Settings::flush();
        $out = page(['page' => 'admin/owner-setup.php', 'admin' => $super]);
        check('owner-setup shows the onward form', str_contains($out, 'id="onward"') && str_contains($out, 'name="act" value="onward"'));
        $form = preg_match('#<form method="post" class="os-card" id="onward">.*?</form>#s', $out, $mf) === 1 ? $mf[0] : '';
        preg_match_all('#name="onw\[\d+\]\[to\]"[^>]*value="([^"]*)"#', $form, $mt);
        $filled = array_values(array_filter($mt[1] ?? [], static fn(string $v): bool => $v !== ''));
        check('owner-setup onward prefill: Surkhet only (+ 2 free rows)', $filled === ['Surkhet'] && count($mt[1] ?? []) === 3 && str_contains($form, 'value="सुर्खेत"'), json_encode($mt[1] ?? [], JSON_UNESCAPED_UNICODE));
        check('owner-setup onward prefill: no Dang/Butwal/Pokhara/Kathmandu/Dhangadhi', preg_match('/Dang|Butwal|Pokhara|Kathmandu|Dhangadhi/', $form) !== 1);
        check('owner-setup shows the luggage form', str_contains($out, 'name="act" value="luggage"') && str_contains($out, 'name="luggage_free_kg"'));
        page(['page' => 'admin/owner-setup.php', 'admin' => $super, 'post' => ['act' => 'onward', 'onw' => [
            ['to' => 'Surkhet', 'to_ne' => 'सुर्खेत', 'times' => 'TEST 06:00', 'operator' => '', 'phone' => '', 'note' => ''],
            ['to' => 'Dang', 'to_ne' => 'दाङ', 'times' => '', 'operator' => '', 'phone' => '', 'note' => ''],
        ]]]);
        Settings::flush();
        $rows = TravelGuide::onward();
        check('act=onward stores only the filled town', count($rows) === 1 && $rows[0]['to'] === 'Surkhet' && $rows[0]['times'] === 'TEST 06:00', json_encode($rows, JSON_UNESCAPED_UNICODE));
        Settings::set('onward_buses_json', [['to' => 'Surkhet', 'to_ne' => 'सुर्खेत', 'times' => 'TEST 06:00', 'operator' => '', 'phone' => '', 'note' => ''], ['to' => 'TEST Town', 'to_ne' => '', 'times' => '', 'operator' => 'TEST Op', 'phone' => '', 'note' => '']], 'json', 'company'); Settings::flush();
        $out2 = page(['page' => 'admin/owner-setup.php', 'admin' => $super]);
        check('owner-setup keeps saved rows as saved (Surkhet times, an extra town)', str_contains($out2, 'value="TEST 06:00"') && str_contains($out2, 'value="TEST Town"') && str_contains($out2, 'value="TEST Op"') && substr_count($out2, 'value="Surkhet"') === 1);
        Settings::set('onward_buses_json', $rows, 'json', 'company'); Settings::flush();
        page(['page' => 'admin/owner-setup.php', 'admin' => $super, 'no_csrf' => true, 'post' => ['act' => 'onward', 'onw' => [['to' => 'Dang', 'times' => 'X']]]]);
        Settings::flush();
        check('act=onward without CSRF writes nothing', (TravelGuide::onward()[0]['to'] ?? '') === 'Surkhet' && count(TravelGuide::onward()) === 1);
        page(['page' => 'admin/owner-setup.php', 'admin' => $super, 'post' => ['act' => 'luggage', 'luggage_free_kg' => '30', 'cabin_bag_kg' => '8', 'bus_facilities' => "Wi-Fi TEST\n\n  AC TEST  "]]);
        Settings::flush();
        check('act=luggage saves kg + facilities', TravelGuide::luggage() === ['free' => 30, 'cabin' => 8] && TravelGuide::facilities() === ['Wi-Fi TEST', 'AC TEST'], json_encode(TravelGuide::facilities()));
        page(['page' => 'admin/owner-setup.php', 'admin' => $super, 'post' => ['act' => 'luggage', 'luggage_free_kg' => '0', 'cabin_bag_kg' => '8', 'bus_facilities' => '']]);
        Settings::flush();
        check('act=luggage refuses 0 kg', TravelGuide::luggage()['free'] === 30);
        check('the saves are audited', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :i AND action IN ('settings.onward_buses','settings.luggage')", ['i' => $auditFrom], 0) >= 2);
    } else {
        echo "  SKIP  owner-setup (no super-admin)\n";
    }

    /* ---- the migration ---- */
    $sql = (string) file_get_contents(dirname(__DIR__) . '/database/upgrade-2026-11-25-wx1-c2.sql');
    check('migration is INSERT IGNORE only', str_contains($sql, 'INSERT IGNORE INTO settings') && !preg_match('/\b(DELETE|UPDATE|DROP|ALTER)\b/i', preg_replace('/^--.*$/m', '', $sql) ?? ''));
    check('migration starts onward_buses_json empty', str_contains($sql, "('onward_buses_json',    '[]'"));
    $pdo = Database::pdo();
    $pdo->beginTransaction();
    try {
        Database::query("DELETE FROM settings WHERE skey IN ('home_wa_easy_on','luggage_free_kg')");
        $body = preg_replace('/^--.*$/m', '', $sql) ?? '';
        for ($run = 0; $run < 2; $run++) {   // the second run must be a no-op, not an error
            foreach (array_filter(array_map('trim', explode(';', $body))) as $stmt) { $pdo->exec($stmt); }
        }
        $r = Database::fetch("SELECT svalue, is_public FROM settings WHERE skey = 'home_wa_easy_on'");
        $lk = Database::fetch("SELECT svalue, is_public FROM settings WHERE skey = 'luggage_free_kg'");
        check('migration rows land public, re-run safe', $r !== null && (int) $r['is_public'] === 1 && $r['svalue'] === '1' && $lk !== null && $lk['svalue'] === '20');
    } finally {
        $pdo->rollBack();
    }

    /* ---- the customer page + the WhatsApp sheet ---- */
    $tpl = (string) file_get_contents(dirname(__DIR__) . '/app.template.html');
    $rt  = (string) file_get_contents(dirname(__DIR__) . '/assets/js/05-router.js');
    check('template keeps CRLF + BOM', str_starts_with($tpl, "\xEF\xBB\xBF") && substr_count($tpl, "\n") === substr_count($tpl, "\r\n"));
    check('agents: partners are no longer dropped', !str_contains($tpl, "a.kind !== 'partner'") && str_contains($tpl, "t('partner')"));
    check('agents: ID check link', str_contains($tpl, 'href="/verify-id.php" data-cp="agVid"'));
    check('crew card on the whitelist + hidden off cp1', str_contains($tpl, ':not(#cpStaff)') && str_contains($tpl, 'html:not(.cp1) #cpStaff') && str_contains($tpl, "/api/staff-list.php?for=home"));
    check('crew card hidden until the list has people', (bool) preg_match('/<section[^>]+id="cpStaff"[^>]*\shidden>/', $tpl));
    check('guide + luggage + easy WhatsApp on the home', str_contains($tpl, 'href="/travel-info?lang=ne#onward"') && str_contains($tpl, 'id="cpLugRule"') && (bool) preg_match('/id="cpWaEasy"[\s\S]{0,200}data-wa-open="booking"/', $tpl));
    check('PNR box asks the mobile and calls track.php', str_contains($tpl, 'id="cpTrackPhone"') && str_contains($tpl, "shgApi.post('/track.php'") && str_contains($tpl, 'data-cp-share'));
    check('women berth sign always shown (not in the fold)', str_contains($tpl, '<span><span class="lg fem"></span>') && !str_contains($tpl, '<span class="lg-x"><span class="lg fem">'));
    check('date / pickup folded, not required', (bool) preg_match('/<details class="wa-req-journey" id="waReqJourney">/', $tpl) && !preg_match('/id="waReqDate" type="date" required/', $tpl) && !str_contains($tpl, '<select id="waReqPoint" required>'));
    check('router: short booking behind wa_short_booking_on', str_contains($rt, 'wa_short_booking_on') && str_contains($rt, 'function journeyUsed()') && str_contains($rt, "body.date = journeyUsed() ? val('waReqDate') : ''"));
    check('router: folded direction is not sent unseen', str_contains($rt, "body.direction = journeyUsed() ? (val('waReqDirection') === 'toIndia' ? 'back' : 'go') : ''"));
    $wr = (string) file_get_contents(dirname(__DIR__) . '/api/wa-request.php');
    check('wa-request: blank direction prints a neutral dash', str_contains($wr, "\$dirRaw === 'go' ? 'Gujarat → Rupaidiha' : '—'") && str_contains($wr, "'Boarding/Drop'"));
    check('PNR box: pending / completed / expired / rejected each have a line', str_contains($tpl, "if (st === 'pending')") && str_contains($tpl, "if (st === 'completed')") && str_contains($tpl, "trkFail('trkExpired')") && str_contains($tpl, "trkFail('trkRejected')") && substr_count($tpl, 'trkRejected:') === 3 && substr_count($tpl, 'trkDone:') === 3);
} finally {
    $restore();
    Database::query("DELETE FROM audit_logs WHERE id > :i AND action IN ('settings.onward_buses','settings.luggage')", ['i' => $auditFrom]);
}

/* ---- wx1-c2 review: Nepalgunj boarding line, Counters from the office list, /offices dialing ---- */
echo "\n  -- review: boarding line, counters, /offices dialing --\n";
require_once INCLUDE_PATH . '/contentpages.php';
$tiSrc = (string) file_get_contents(dirname(__DIR__) . '/travel-info.php');
check('travel-info: no HH:MM typed in the page source', preg_match('/\b(?:[01]\d|2[0-3]):[0-5]\d\b/', $tiSrc) !== 1);
check('travel-info: counters read CounterDesk::publicOffices(), old setting only as fallback', str_contains($tiSrc, 'CounterDesk::publicOffices()') && str_contains($tiSrc, "if (\$counters === '') {"));
$wB = ['GEN', 'ONE {A}.', 'TWO {A}, else {B}.'];
check('boardingLine: no stop -> the general sentence', TravelGuide::boardingLine([], $wB) === 'GEN');
check('boardingLine: one stop', TravelGuide::boardingLine([['name' => 'Rupaidiha', 'times' => ['18:00']]], $wB) === 'ONE <b>Rupaidiha</b> (18:00).');
check('boardingLine: two stops, escaped, Devanagari digits',
    TravelGuide::boardingLine([['name' => 'A <i>', 'times' => ['16:00']], ['name' => 'B', 'times' => []]], $wB, true) === 'TWO <b>A &lt;i&gt;</b> (१६:००), else <b>B</b>.',
    TravelGuide::boardingLine([['name' => 'A <i>', 'times' => ['16:00']], ['name' => 'B', 'times' => []]], $wB, true));
check('placeDialCountry: Nepal town / Indian town / Rupaidiha / Nepal address / no town',
    ContentPages::placeDialCountry('Nepalgunj', '9812345678') === 'NP' && ContentPages::placeDialCountry('Surat', '9812345678') === 'IN'
    && ContentPages::placeDialCountry('Rupaidiha', '9812345678') === 'IN' && ContentPages::placeDialCountry('Banke', '9812345678', 'Ward 2, Banke, Nepal') === 'NP'
    && ContentPages::placeDialCountry('', '9812345678') === 'NP' && ContentPages::placeDialCountry('', '9104801507') === 'IN');

$r7 = (int) Database::scalar("SELECT id FROM routes WHERE is_active = 1 AND from_city = 'Rupaidiha' ORDER BY id LIMIT 1", [], 0);
$tmpStop = 0; $tmpOffice = 0; $tmpPlaces = [];
try {
    if ($r7 > 0) {
        Database::query("INSERT INTO route_stops (route_id, stop_type, stop_name, stop_time, sort_order, is_active) VALUES (:r, 'boarding', 'TEST Nepalgunj Stop', '15:58:00', 0, 1)", ['r' => $r7]);
        $tmpStop = (int) Database::scalar('SELECT LAST_INSERT_ID()', [], 0);
        Boarding::flush();
    }
    $nb = TravelGuide::nepalBoarding();
    check('nepalBoarding: only active Nepal -> India boarding stops, earliest first', $r7 === 0 || (($nb[0]['name'] ?? '') === 'TEST Nepalgunj Stop' && ($nb[0]['times'] ?? []) === ['15:58']), json_encode($nb, JSON_UNESCAPED_UNICODE));
    check('nepalBoarding: never a drop stop', !in_array('Surat', array_column($nb, 'name'), true));

    Database::query("INSERT INTO counter_locations (code, name, country, currency, entity, is_active, sort_order, show_public, public_status) VALUES ('ZEMBT', 'TEST Emli Office', 'IN', 'INR', 'IN', 1, 99, 1, 'open')");
    $tmpOffice = (int) Database::scalar('SELECT LAST_INSERT_ID()', [], 0);
    foreach ([['TEST NP place', 'Nepalgunj', '9800000005'], ['TEST IN place', 'Surat', '9800000006']] as [$pn, $pt, $pp]) {
        Database::query("INSERT INTO company_locations (name, kind, town, address, phone, whatsapp, show_public, sort_order, created_at) VALUES (:n, 'pickup', :t, 'TEST address', :p, NULL, 1, 99, NOW())", ['n' => $pn, 't' => $pt, 'p' => $pp]);
        $tmpPlaces[] = (int) Database::scalar('SELECT LAST_INSERT_ID()', [], 0);
    }
    CounterDesk::flush();

    $html = page(['page' => 'travel-info.php', 'get' => ['lang' => 'en']]);
    // wx6: with a Nepalgunj stop the line is the journey (office time -> our auto -> bus), from the same rows
    check('travel-info: Nepalgunj boarding line from the route rows', $r7 === 0 || (str_contains($html, 'Going to Gujarat?') && str_contains($html, '3:58 PM (Nepal time)')), $r7 === 0 ? '' : (string) (preg_match('/Coming from Nepalgunj\?[^<]*(?:<b>[^<]*<\/b>[^<]*)*/', $html, $mm) ? $mm[0] : 'missing'));
    check('travel-info: Nepali line in Devanagari digits', $r7 === 0 || str_contains(page(['page' => 'travel-info.php', 'get' => ['lang' => 'ne']]), 'दिउँसो ३:५८ बजे'));
    check('travel-info: no placeholder left', !str_contains($html, '{NPJBOARD}') && !str_contains($html, '{A}'));
    $ul = preg_match('#<h4>(?:(?!</h4>).)*Counters(?:(?!</h4>).)*</h4><ul class="plain">(.*?)</ul>#s', $html, $m) === 1 ? $m[1] : '';
    $offs = CounterDesk::publicOffices();
    $allIn = $ul !== '';
    foreach ($offs as $o) {
        if ($o['status'] !== 'open') { continue; }
        $allIn = $allIn && str_contains($ul, htmlspecialchars((string) $o['name'], ENT_QUOTES, 'UTF-8')) && ($o['tel'] === '' || str_contains($ul, 'tel:+' . $o['tel']));
    }
    check('travel-info: Counters = the listed offices with their numbers', $allIn && count($offs) > 0, substr($ul, 0, 300));
    /* 25 Nov 2026 (owner: every counter is one company): "<company> · <town>" titles */
    $bIn = trim(Settings::getString('office_brand_in', ''));
    $bIn = $bIn !== '' ? $bIn : (trim(Settings::getString('company_name', '')) !== '' ? trim(Settings::getString('company_name', '')) : 'S Hari Global Pvt Ltd');
    $bNp = trim(Settings::getString('office_brand_np', ''));
    $bNp = $bNp !== '' ? $bNp : 'Shree Hari Global Pvt Ltd';
    $bOn = Settings::getBool('office_brand_title_on', true);
    $bTag = static fn(string $b): string => $bOn ? '<b>' . htmlspecialchars($b, ENT_QUOTES, 'UTF-8') . '</b> · ' : '';
    check('travel-info: an office with no number shows its name only', str_contains($ul, '<li>' . $bTag($bIn) . 'TEST Emli Office</li>'), substr($ul, 0, 300));
    $titlesOk = $ul !== '';
    foreach ($offs as $o) {
        if ($o['status'] !== 'open') { continue; }
        $tw = $o['nameNe'] !== '' && $o['nameNe'] !== $o['name'] ? $o['nameNe'] . ' · ' . $o['name'] : $o['name'];
        $titlesOk = $titlesOk && str_contains($ul, '<li>' . $bTag($o['country'] === 'NP' ? $bNp : $bIn) . htmlspecialchars($tw, ENT_QUOTES, 'UTF-8'));
    }
    check('travel-info: Counters titled "<company> · <town>" (India S Hari / Nepal Shree Hari)', $titlesOk, substr($ul, 0, 400));
    if ($bOn && Settings::getString('office_brand_np', '') === '' && in_array('NP', array_column($offs, 'country'), true)) {
        check('travel-info: Nepal office says Shree Hari Global Pvt Ltd by default', str_contains($ul, '<b>Shree Hari Global Pvt Ltd</b> · '));
    }
    $bSaved = [];
    foreach (['office_brand_title_on', 'office_brand_in', 'office_brand_np'] as $bk) { $bSaved[$bk] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $bk]); }
    $ulOf = static fn(string $h): string => preg_match('#<h4>(?:(?!</h4>).)*Counters(?:(?!</h4>).)*</h4><ul class="plain">(.*?)</ul>#s', $h, $mB) === 1 ? $mB[1] : '';
    try {
        Settings::set('office_brand_in', 'TEST IN Brand', 'string', 'company'); Settings::set('office_brand_np', 'TEST NP Brand', 'string', 'company');
        Settings::set('office_brand_title_on', '1', 'bool', 'company'); Settings::flush();
        $ulB = $ulOf(page(['page' => 'travel-info.php', 'get' => ['lang' => 'en']]));
        check('office_brand_in / office_brand_np are read', str_contains($ulB, '<li><b>TEST IN Brand</b> · TEST Emli Office</li>')
            && (!in_array('NP', array_column($offs, 'country'), true) || str_contains($ulB, '<b>TEST NP Brand</b> · ')), substr($ulB, 0, 300));
        Settings::set('office_brand_title_on', '0', 'bool', 'company'); Settings::flush();
        $ulB = $ulOf(page(['page' => 'travel-info.php', 'get' => ['lang' => 'en']]));
        check('office_brand_title_on off: town names only, as before', str_contains($ulB, '<li>TEST Emli Office</li>') && !str_contains($ulB, 'Brand'));
    } finally {
        foreach ($bSaved as $bk => $row) {
            Database::query('DELETE FROM settings WHERE skey = :k', ['k' => $bk]);
            if ($row !== null) { unset($row['id']); $cols = array_keys($row); Database::query('INSERT INTO settings (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')', $row); }
        }
        Settings::flush();
    }
    check('travel-info: Counters no longer from the free-text line (no Baroda)', !str_contains($ul, 'Baroda'));

    $off = page(['path' => '/offices']);
    check('/offices renders', str_contains($off, '</html>'), substr($off, 0, 300));
    $card = preg_match('#<article[^>]+id="office-zembt".*?</article>#s', $off, $m) === 1 ? $m[0] : '';
    $co2 = Settings::company();
    $hoTel = PublicLayout::telHref((string) $co2['phone']);
    $hoWa = PublicLayout::waDigits((string) $co2['whatsapp']);
    check('/offices: no-number office shows the HEAD OFFICE Call + WhatsApp', $card !== '' && str_contains($card, 'मुख्य कार्यालय · Head office')
        && ($hoTel === '' || str_contains($card, 'href="' . $hoTel . '"')) && ($hoWa === '' || str_contains($card, 'https://wa.me/' . $hoWa . '?text=')), substr($card, 0, 600));
    check('/offices: ... and keeps its map button', str_contains($card, '📍'));
    check('/offices: offices with a number get no head-office button', substr_count($off, 'sm ho"') === ($hoTel !== '' ? 1 : 0) + ($hoWa !== '' ? 1 : 0));
    check('/offices: Nepal place bare number dials +977 + WhatsApp from it', str_contains($off, 'href="tel:+9779800000005"') && str_contains($off, 'https://wa.me/9779800000005"'));
    check('/offices: Indian place bare 98… number dials +91 (town wins)', str_contains($off, 'href="tel:+919800000006"') && str_contains($off, 'https://wa.me/919800000006"'));
    check('/offices: no bare 10-digit tel: left', preg_match('#href="tel:\d{10}"#', $off) !== 1);
} finally {
    if ($tmpStop > 0) { Database::query('DELETE FROM route_stops WHERE id = :i', ['i' => $tmpStop]); }
    if ($tmpOffice > 0) { Database::query('DELETE FROM counter_locations WHERE id = :i', ['i' => $tmpOffice]); }
    foreach ($tmpPlaces as $pid) { Database::query('DELETE FROM company_locations WHERE id = :i', ['i' => $pid]); }
    Boarding::flush();
    CounterDesk::flush();
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
