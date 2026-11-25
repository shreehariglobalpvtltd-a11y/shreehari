<?php
/* wx6 (25 Nov 2026): Nepal -> India journey (office -> our auto -> Rupaidiha bus, one service),
   the 5 pm Nepal-time warning before booking closes, +977 default, 3D boot coach, ticket
   animation styles, nav stops from the timetable + smooth glide, the app permission card.
   php -c .claude/php-dev.ini tests/wx6-test.php */
define('SHG_APP', true);
require __DIR__ . '/../includes/bootstrap.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/travelguide.php';
require_once INCLUDE_PATH . '/smartbook.php';
require_once INCLUDE_PATH . '/notify.php';
$ok = 0; $bad = 0;
$chk = function ($name, $cond) use (&$ok, &$bad) { if ($cond) { $ok++; echo "  PASS  $name\n"; } else { $bad++; echo "  FAIL  $name\n"; } };
$root = dirname(__DIR__);
$src = static fn(string $f): string => (string) file_get_contents($root . '/' . $f);

$keys = ['np_journey_steps_on', 'sales_close_clock', 'wa_np_journey_on'];
$saved = [];
foreach ($keys as $k) { $saved[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]); }
$tmp = [];
try {
    /* 1. the journey, from temporary rows on every active Nepal -> India route */
    $routes = array_values(array_filter(Database::fetchAll('SELECT * FROM routes WHERE is_active = 1'), static fn(array $r): bool => Boarding::routeDirection($r) === 'toIndia'));
    if ($routes === []) {
        echo "  SKIP  no active Nepal -> India route\n";
    } else {
        foreach ($routes as $r) {
            Database::run("UPDATE route_stops SET is_active = 0 WHERE route_id = :r AND stop_type = 'boarding' AND stop_name LIKE 'WX6%'", ['r' => (int) $r['id']]);
            $tmp[] = (int) Database::insert('route_stops', ['route_id' => (int) $r['id'], 'stop_name' => 'WX6 Nepalgunj Office · Puspalal Chowk', 'stop_type' => 'boarding',
                'stop_time' => '16:00:00', 'sort_order' => -2, 'latitude' => 28.0713177, 'longitude' => 81.6324768, 'is_active' => 1]);
        }
        Boarding::flush();
        $ne = TravelGuide::nepalJourney('ne');
        $en = TravelGuide::nepalJourney('en');
        $chk('journey found the office row', ($ne['office']['name'] ?? '') !== '' && str_contains((string) $ne['office']['map'], '28.0713177'));
        $chk('ne: office step at दिउँसो ४ बजे with ID', str_contains((string) ($ne['steps'][0] ?? ''), 'दिउँसो ४ बजे') && str_contains((string) ($ne['steps'][0] ?? ''), 'परिचयपत्र'));
        $chk('ne: auto step across Jamunaha-Rupaidiha', str_contains((string) ($ne['steps'][1] ?? ''), 'अटो') && str_contains((string) ($ne['steps'][1] ?? ''), 'जमुनाहा'));
        $chk('one-service line', str_contains((string) $ne['one'], 'एउटै S Hari सेवा') && str_contains((string) $en['one'], 'ONE S Hari service'));
        $chk('en: 4 PM', str_contains((string) ($en['steps'][0] ?? ''), '4 PM'));
        if ($ne['bus'] !== null) {
            $chk('bus step present with its time', str_contains((string) end($ne['steps']), 'रुपैडिहा'));
        }
        Settings::set('np_journey_steps_on', '0', 'bool', 'booking', false); Settings::flush();
        $chk('switch off = no journey', TravelGuide::nepalJourney('ne') === []);
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => 'np_journey_steps_on']); Settings::flush();

        /* 2. the SmartBook quote carries it on a Nepal -> India card */
        $q = SmartBook::quote(['direction' => 'toIndia', 'date' => addDaysISO(todayISO(), 1), 'pax' => [['gender' => 'Male', 'band' => 'adult']]], 'customer');
        $chk('quote.journey has ne/hi/en steps + maps', !empty($q['journey']['ne']['steps']) && !empty($q['journey']['hi']['one']) && !empty($q['journey']['en']['steps']) && isset($q['journey']['maps']));
        $q2 = SmartBook::quote(['direction' => 'toNepal', 'date' => addDaysISO(todayISO(), 1), 'pax' => [['gender' => 'Male', 'band' => 'adult']]], 'customer');
        $chk('India -> Nepal quote: no journey', ($q2['journey'] ?? null) === null);

        /* 3. WhatsApp text lines */
        $m = new ReflectionMethod('Notify', 'nepalJourneyLines'); $m->setAccessible(true);
        $r0 = $routes[0];
        $lines = (string) $m->invoke(null, ['from_city' => $r0['from_city'], 'to_city' => $r0['to_city'], 'boarding_stop' => 'WX6 Nepalgunj Office · Puspalal Chowk @ 16:00']);
        $chk('WhatsApp: office booking gets office + auto + bus + one-service', str_contains($lines, 'अटो') && str_contains($lines, 'एउटै S Hari सेवा'));
        $l2 = (string) $m->invoke(null, ['from_city' => $r0['from_city'], 'to_city' => $r0['to_city'], 'boarding_stop' => 'Rupaidiha @ 18:00']);
        $chk('WhatsApp: Rupaidiha booking = bus step only + one line', !str_contains($l2, 'अटोमा जमुनाहा') && str_contains($l2, 'एउटै S Hari सेवा'));
        $l3 = (string) $m->invoke(null, ['from_city' => 'Surat', 'to_city' => 'Rupaidiha', 'boarding_stop' => 'Surat']);
        $chk('WhatsApp: India -> Nepal booking = nothing', $l3 === '');

        /* 4. the clock: a closing time later today is announced, never for a later day */
        $code = strtolower((string) ($r0['route_code'] ?? ''));
        if ($code !== '') {
            $tz = new DateTimeZone('Asia/Kathmandu');
            Settings::set('sales_close_clock', [$code => ['all' => '17:00', 'tz' => 'Asia/Kathmandu']], 'json', 'booking', false); Settings::flush();
            $at15 = (new DateTimeImmutable(todayISO() . ' 15:00:00', $tz))->getTimestamp();
            $at18 = (new DateTimeImmutable(todayISO() . ' 18:00:00', $tz))->getTimestamp();
            $c15 = Boarding::clockClose($r0, todayISO(), 'online', null, $at15);
            $c18 = Boarding::clockClose($r0, todayISO(), 'online', null, $at18);
            $chk('3 pm Nepal time: still open, closes 17:00 NPT', $c15 !== null && $c15['closed'] === false && $c15['at'] === '17:00' && $c15['tz'] === 'Asia/Kathmandu');
            $chk('6 pm Nepal time: closed', $c18 !== null && $c18['closed'] === true);
            $chk('a later travel date never closes by the clock', Boarding::clockClose($r0, addDaysISO(todayISO(), 1), 'online', null, $at18) === null);
        }
        $chk('QuickTicket plan carries clockSoon', str_contains($src('includes/quickticket.php'), "'clockSoon'      => \$clockSoon"));
        $chk('SmartBook passes clockSoon only for today', str_contains($src('includes/smartbook.php'), "'clockSoon'    => !empty(\$plan['isToday'])"));
        $chk('card shows the countdown + requotes at close', str_contains($src('assets/js/27-smartbook.js'), 'function clockSoonLine') && str_contains($src('assets/js/27-smartbook.js'), 'requote(0)'));
    }

    /* 5. pages + front end */
    $ti = $src('travel-info.php');
    $chk('travel-info: no "cross early" for Gujarat-bound, journey wired', !str_contains($ti, 'cross early') && str_contains($ti, 'TravelGuide::nepalJourney('));
    $i18n = $src('assets/js/04-i18n.js');
    $chk('i18n: no "bus waits for everyone" in the route border info', !str_contains($i18n, "rgBorderInfo: 'Rupaidiha (India) ⇄ Jamunaha (Nepal): photo ID check, about 20–40 minutes. The bus waits for everyone.'"));
    $nav = $src('navigate.html');
    $chk('nav: corridor stops from the live timetable', str_contains($nav, 'function corStops()') && str_contains($nav, 'corStops().forEach'));
    $chk('nav: Rupaidiha fallback = our parking pin', str_contains($nav, "lat: 27.9996732, lng: 81.5867382"));
    $chk('nav: fresh fixes + glide', str_contains($nav, 'maximumAge: 0') && str_contains($nav, 'function glideTick'));
    $chk('nav: no "cross on your own" for the Nepal side KB', !str_contains($nav, 'नेपालगञ्ज आफैँ पार गर्नुस्'));
    $tpl = $src('app.template.html');
    $chk('boot scene: 3D coach act', str_contains($tpl, 'class="as-bus"') && str_contains($tpl, '#appSplash.as4 .as-car') && str_contains($tpl, "app_boot_bus_on"));
    $chk('ticket styles rotate', str_contains($src('assets/js/27-smartbook.js'), "['spin', 'coin', 'flip', 'stamp']") && str_contains($src('assets/css/smartbook.css'), '@keyframes tkmCoin'));
    $chk('PayWatch plays the ticket animation', str_contains($tpl, 'window.shgTicketMagic('));
    $chk('app permission card, once', str_contains($tpl, 'id = \'shgPerm\'') && str_contains($tpl, "localStorage.getItem('shg:perm1')"));
    $chk('+977 default on Nepal time', str_contains($src('assets/js/27-smartbook.js'), "S.cc = saved.cc || (NP_TZ && !STAFF ? 'NP' : 'IN')"));
    $chk('boot exposes the new switches', str_contains($src('index.php'), "'app_boot_bus_on'") && str_contains($src('index.php'), "'app_perm_card_on'"));
} catch (Throwable $e) {
    $chk('no exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    foreach ($tmp as $t) { Database::run('DELETE FROM route_stops WHERE id = :i', ['i' => $t]); }
    foreach ($keys as $k) {
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        if ($saved[$k]) { Database::insert('settings', $saved[$k]); }
    }
    Settings::flush(); Boarding::flush();
}
echo "\n  wx6: $ok passed, $bad failed\n";
exit($bad > 0 ? 1 : 0);
