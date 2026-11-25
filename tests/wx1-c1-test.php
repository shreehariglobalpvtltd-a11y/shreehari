<?php
/**
 * wx1-c1-test.php — office cards: in-charge + uniform WhatsApp / map, pickup map links (25 Nov 2026).
 *
 *  1. CounterDesk helpers: firstDial() takes the first real number, cleanPhotoPath() only an upload path.
 *  2. Migration upgrade-2026-11-25-wx1-c1.sql is idempotent; save() stores the in-charge phone + photo.
 *  3. publicOffices(): WhatsApp falls back to the office phone, in-charge fields, switch
 *     home_office_incharge_on = 0 gives the old list back.
 *  4. SiteFacts places: only https pins, one row per town, switch home_pickup_maps_on = 0 -> [].
 *  5. The home cards (app.template.html) and /offices carry the same order: Call, WhatsApp, Map.
 *
 *   php -c .claude/php-dev.ini tests/wx1-c1-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/counterdesk.php';
require_once INCLUDE_PATH . '/sitefacts.php';
require_once INCLUDE_PATH . '/companylocations.php';
require_once INCLUDE_PATH . '/publiclayout.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
echo "\n=== wx1-c1 office in-charge + pickup maps ===\n\n";

$keep = [];
foreach (['home_office_incharge_on', 'home_pickup_maps_on'] as $k) {
    $keep[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]);   // the whole row, put back as it was
}
$restore = static function () use ($keep): void {
    foreach ($keep as $k => $row) {
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        if ($row !== null) {
            Database::insert('settings', $row);
        }
    }
    Settings::flush();
};
foreach (array_keys($keep) as $k) {
    Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);   // the defaults (ON)
}
Settings::flush();

/* ---------- 1. helpers ---------- */
check('firstDial: "+977 98.. / 98.." dials the first', CounterDesk::firstDial('+977 9866201375 / 9825788450', 'NP') === '9779866201375');
check('firstDial: bare 10 digits take the country', CounterDesk::firstDial('90000 00001', 'IN') === '919000000001');
check('firstDial: junk -> empty', CounterDesk::firstDial('office lai sodhnus', 'IN') === '' && CounterDesk::firstDial('', 'IN') === '');
check('photo path: an upload path is kept', CounterDesk::cleanPhotoPath('/uploads/locations/incharge-msa-1a2b.webp') === 'uploads/locations/incharge-msa-1a2b.webp');
check('photo path: "..", a URL, a script are refused',
    CounterDesk::cleanPhotoPath('uploads/locations/../config.php') === ''
    && CounterDesk::cleanPhotoPath('https://evil.example/x.jpg') === ''
    && CounterDesk::cleanPhotoPath('uploads/locations/x.php') === ''
    && CounterDesk::cleanPhotoPath('assets/img/ceo.jpg') === '');

/* ---------- 2. migration + save ---------- */
$sql = (string) file_get_contents(dirname(__DIR__) . '/database/upgrade-2026-11-25-wx1-c1.sql');
$stmt = trim((string) preg_replace('/^--.*$/m', '', $sql));
$okMig = true;
try {
    Database::query(rtrim($stmt, "; \r\n"));
    Database::query(rtrim($stmt, "; \r\n"));   // twice: idempotent
} catch (Throwable $e) {
    $okMig = false;
}
check('migration runs twice', $okMig);
check('columns there', CounterDesk::hasColumn('counter_locations', 'incharge_phone') && CounterDesk::hasColumn('counter_locations', 'incharge_photo'));

$code = 'WXT';
Database::run('DELETE FROM counter_locations WHERE code = :c', ['c' => $code]);
try {
    CounterDesk::save([
        'code' => $code, 'name' => 'Wxtown — Test Desk', 'country' => 'IN', 'is_active' => 1, 'sort_order' => 999,
        'phone' => '+91 90000 00001', 'show_public' => 1, 'office_kind' => 'branch', 'public_status' => 'open',
        'whatsapp' => '', 'contact_person' => 'Test In-charge', 'incharge_phone' => '90000 00002',
        'incharge_photo' => '/uploads/locations/incharge-wxt-test.webp',
    ]);
    $row = Database::fetch('SELECT incharge_phone, incharge_photo, contact_person FROM counter_locations WHERE code = :c', ['c' => $code]);
    check('save stores in-charge phone + clean photo path', $row !== null && $row['incharge_phone'] === '90000 00002'
        && $row['incharge_photo'] === 'uploads/locations/incharge-wxt-test.webp');
    CounterDesk::save(array_merge((array) CounterDesk::get($code), ['incharge_photo' => 'https://evil.example/x.jpg']));
    $row = Database::fetch('SELECT incharge_photo FROM counter_locations WHERE code = :c', ['c' => $code]);
    check('a URL is never stored as the photo', $row !== null && $row['incharge_photo'] === null);
    CounterDesk::save(array_merge((array) CounterDesk::get($code), ['incharge_photo' => 'uploads/locations/incharge-wxt-test.webp']));

    /* ---------- 3. publicOffices ---------- */
    SiteFacts::flush();
    $find = static function (string $c): ?array {
        foreach (CounterDesk::publicOffices() as $o) {
            if ($o['code'] === $c) { return $o; }
        }
        return null;
    };
    $o = $find($code);
    check('test desk listed', $o !== null);
    check('no WhatsApp typed -> the office phone', $o !== null && $o['wa'] === '919000000001' && $o['tel'] === '919000000001');
    check('in-charge name / phone / tel / photo', $o !== null && $o['inchargeName'] === 'Test In-charge' && $o['inchargePhone'] === '90000 00002'
        && $o['inchargeTel'] === '919000000002' && $o['inchargePhoto'] === '/uploads/locations/incharge-wxt-test.webp');
    $npj = $find('NPJ');
    if ($npj !== null) {
        check('Nepal office has a WhatsApp button', $npj['wa'] !== '', $npj['wa']);
        $pp = CompanyLocations::publicPlaceForTown('Nepalgunj', 'map');
        $place = $pp !== null ? (string) $pp['map'] : '';
        $desk = CounterDesk::get('NPJ');
        if ($place !== '' && $desk !== null && trim((string) $desk['map_url']) === '' && $desk['lat'] === null) {
            check('Nepal office map = the saved place pin, not a text search', $npj['mapUrl'] === $place, $npj['mapUrl']);
        }
    }
    $allWa = array_filter(CounterDesk::publicOffices(), static fn(array $x): bool => $x['status'] === 'open' && $x['tel'] !== '' && $x['wa'] === '');
    check('every open office with a phone has WhatsApp', $allWa === []);

    /* review: an office card only takes a PUBLIC place's pin, office > pickup > parking, never a border */
    CompanyLocations::all();   // makes sure the table is there
    Database::run("DELETE FROM company_locations WHERE town = 'Wxtown'");
    $mk = static function (string $kind, int $pub, string $map, string $wa = ''): void {
        Database::insert('company_locations', ['name' => 'Wxtown test ' . $kind, 'kind' => $kind, 'town' => 'Wxtown',
            'map_url' => $map, 'whatsapp' => $wa !== '' ? $wa : null, 'show_public' => $pub, 'sort_order' => 999, 'created_at' => date('Y-m-d H:i:s')]);
    };
    $mk('office',  0, 'https://www.google.com/maps?q=0.000001,0.000001', '9800000009');   // hidden
    $mk('border',  1, 'https://www.google.com/maps?q=0.000002,0.000002', '9800000008');
    $mk('parking', 1, 'https://www.google.com/maps?q=0.000003,0.000003');
    $mk('pickup',  1, 'https://www.google.com/maps?q=0.000004,0.000004');
    $pp = CompanyLocations::publicPlaceForTown('Wxtown', 'map');
    check('place pin: hidden office + border skipped, pickup before parking', $pp !== null && $pp['kind'] === 'pickup');
    check('place WhatsApp: hidden / border numbers never used', CompanyLocations::publicPlaceForTown('Wxtown', 'whatsapp') === null);
    CounterDesk::flush();
    $o = $find($code);
    check('office card map = the public pickup pin', $o !== null && $o['mapUrl'] === 'https://www.google.com/maps?q=0.000004,0.000004', (string) ($o['mapUrl'] ?? ''));
    $mk('office', 1, 'https://www.google.com/maps?q=0.000005,0.000005');
    $pp = CompanyLocations::publicPlaceForTown('Wxtown', 'map');
    check('place pin: a public office wins', $pp !== null && $pp['kind'] === 'office');
    check('place pin: another town never matches', CompanyLocations::publicPlaceForTown('Wxtow', 'map') === null);
    Database::run("UPDATE company_locations SET show_public = 0 WHERE town = 'Wxtown'");
    CounterDesk::flush();
    $o = $find($code);
    check('no public place: text search, not a hidden pin', $o !== null && str_starts_with($o['mapUrl'], 'https://www.google.com/maps/search/'), (string) ($o['mapUrl'] ?? ''));
    Database::run("DELETE FROM company_locations WHERE town = 'Wxtown'");

    Settings::set('home_office_incharge_on', '0', 'bool', 'company');
    Settings::flush(); SiteFacts::flush();
    $o = $find($code);
    check('switch OFF: no in-charge, no WhatsApp fallback', $o !== null && $o['inchargeName'] === '' && $o['inchargePhone'] === '' && $o['inchargePhoto'] === '' && $o['wa'] === '');
    Database::run("DELETE FROM settings WHERE skey = 'home_office_incharge_on'");
    Settings::flush(); SiteFacts::flush();

    /* ---------- 4. pickup places ---------- */
    $places = SiteFacts::pickupPlaces();
    $badMap = array_filter($places, static fn(array $p): bool => !str_starts_with($p['map'], 'https://'));
    check('places: every row has a real https map link', $badMap === [], (string) count($places) . ' rows');
    $towns = array_map(static fn(array $p): string => mb_strtolower($p['town']), array_filter($places, static fn(array $p): bool => $p['town'] !== ''));
    check('places: one row per town', count($towns) === count(array_unique($towns)));
    $pinStops = (int) Database::scalar("SELECT COUNT(*) FROM route_stops s JOIN routes r ON r.id = s.route_id WHERE r.is_active = 1 AND s.stop_type = 'boarding' AND s.latitude IS NOT NULL AND s.latitude <> 0", [], 0);
    if ($pinStops > 0) {
        check('places: pinned boarding stops reach the list', $places !== []);
    }
    $boot = SiteFacts::boot();
    check('boot carries facts.places', isset($boot['places']) && is_array($boot['places']));
    Settings::set('home_pickup_maps_on', '0', 'bool', 'company');
    Settings::flush(); SiteFacts::flush();
    check('switch OFF: no places', SiteFacts::pickupPlaces() === []);
    Database::run("DELETE FROM settings WHERE skey = 'home_pickup_maps_on'");
    Settings::flush(); SiteFacts::flush();

    /* ---------- PublicLayout (/offices) carries the in-charge ---------- */
    $pl = null;
    foreach (PublicLayout::offices() as $x) {
        if ($x['code'] === $code) { $pl = $x; }
    }
    check('/offices list: in-charge + same-origin photo', $pl !== null && $pl['inchargeName'] === 'Test In-charge'
        && $pl['inchargePhoto'] === '/uploads/locations/incharge-wxt-test.webp' && $pl['inchargeTel'] !== '');
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage());
} finally {
    Database::run('DELETE FROM counter_locations WHERE code = :c', ['c' => $code]);
    try { Database::run("DELETE FROM company_locations WHERE town IN ('Wxtown', 'Wxspot')"); } catch (Throwable $e) { }
    CounterDesk::flush();
    CounterDesk::syncSettingsMirror();
    $restore();
}

/* ---------- 5. the markup ---------- */
$tpl = (string) file_get_contents(dirname(__DIR__) . '/app.template.html');
$fn  = substr($tpl, (int) strpos($tpl, '  function offices(F) {'), 6000);
$iCall = strpos($fn, "t('call')"); $iWa = strpos($fn, "t('wa')"); $iMap = strpos($fn, "t('map')");
check('home card buttons: Call, WhatsApp, Map', $iCall !== false && $iWa !== false && $iMap !== false && $iCall < $iWa && $iWa < $iMap);
check('home card: WhatsApp greeting + in-charge row', str_contains($fn, "'?text='") && str_contains($fn, 'cp-in') && str_contains($fn, 'samePhoto('));
check('home: pickup list markup', str_contains($tpl, 'id="cpPickList"') && str_contains($tpl, 'function pickups(F)'));
check('template keeps CRLF + BOM', str_starts_with($tpl, "\xEF\xBB\xBF") && substr_count($tpl, "\n") === substr_count($tpl, "\r\n"));
$cp = (string) file_get_contents(INCLUDE_PATH . '/contentpages.php');
check('/offices card: in-charge row', str_contains($cp, 'off-in') && str_contains($cp, 'कार्यालय प्रमुख · In-charge'));
$adm = (string) file_get_contents(dirname(__DIR__) . '/admin/counters.php');
check('admin form: upload + in-charge boxes', str_contains($adm, 'enctype="multipart/form-data"') && str_contains($adm, 'name="incharge_phone"')
    && str_contains($adm, 'name="incharge_photo"') && str_contains($adm, 'incharge_photo_remove'));
check('admin form: a too-big / bad photo is skipped, the desk still saves', str_contains($adm, 'UPLOAD_ERR_INI_SIZE')
    && str_contains($adm, 'catch (Throwable $photoErr)') && str_contains($adm, '$photoLimitMb') && str_contains($adm, "'Desk ' . \$code . ' saved. ' . \$photoWarn"));

/* ---------- 6. wx1 (owner 25 Nov): the company is every office card's title, Nepal "Shree"; Kamrej dedupe ---------- */
echo "\n  -- office titles + pickup dedupe --\n";
$plBefore = PublicLayout::offices();   // static cache: built with the settings as they were
$brandBefore = ['IN' => SiteFacts::officeBrand('IN'), 'NP' => SiteFacts::officeBrand('NP')];
check('/offices list rows carry the brand of their country', $plBefore !== [] && array_filter($plBefore,
    static fn(array $x): bool => ($x['brand'] ?? null) !== $brandBefore[$x['country']]) === []);
$keep6 = [];
foreach (['office_brand_title_on', 'office_brand_in', 'office_brand_np', 'home_pickup_dedupe_on', 'home_pickup_maps_on'] as $k) {
    $keep6[$k] = Database::fetch('SELECT * FROM settings WHERE skey = :k', ['k' => $k]);
}
try {
    foreach (array_keys($keep6) as $k) {
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);   // the defaults
    }
    Settings::flush(); SiteFacts::flush();
    $cn = trim(Settings::getString('company_name', ''));
    check('title IN = the company name (default)', SiteFacts::officeBrand('IN') === ($cn !== '' ? $cn : 'S Hari Global Pvt Ltd'), SiteFacts::officeBrand('IN'));
    check('title NP = Shree Hari Global Pvt Ltd (no branch suffix)', SiteFacts::officeBrand('NP') === 'Shree Hari Global Pvt Ltd' && SiteFacts::officeBrand('np') === 'Shree Hari Global Pvt Ltd');
    $offs = SiteFacts::all()['offices'];
    check('facts offices: IN rows S Hari, NP rows Shree', $offs !== [] && array_filter($offs, static fn(array $x): bool =>
        $x['brand'] !== (($x['country'] ?? 'IN') === 'NP' ? 'Shree Hari Global Pvt Ltd' : SiteFacts::officeBrand('IN'))) === []);
    $npRow = array_values(array_filter($offs, static fn(array $x): bool => ($x['country'] ?? '') === 'NP'))[0] ?? null;
    if ($npRow !== null) {
        check('NP office keeps its own town name + phone', $npRow['name'] !== '' && $npRow['name'] !== $npRow['brand']);
    }
    Settings::set('office_brand_in', 'Wx Brand India', 'string', 'company');
    Settings::set('office_brand_np', 'Wx Brand Nepal', 'string', 'company');
    Settings::flush(); SiteFacts::flush();
    check('office_brand_in / office_brand_np override', SiteFacts::officeBrand('IN') === 'Wx Brand India' && SiteFacts::officeBrand('NP') === 'Wx Brand Nepal');
    Settings::set('office_brand_title_on', '0', 'bool', 'company');
    Settings::flush(); SiteFacts::flush();
    $offs = SiteFacts::all()['offices'];
    check('switch office_brand_title_on OFF: no brand = the old town titles', SiteFacts::officeBrand('IN') === '' && SiteFacts::officeBrand('NP') === ''
        && array_filter($offs, static fn(array $x): bool => $x['brand'] !== '') === []);
    foreach (['office_brand_title_on', 'office_brand_in', 'office_brand_np'] as $k) {
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
    }
    Settings::flush(); SiteFacts::flush();

    /* Kamrej: a company place "… — Kamrej Char Rasta" covers the separate "Kamrej" route stop */
    $isKam = static fn(array $p): bool => mb_strtolower($p['town']) === 'kamrej' || mb_strtolower($p['name']) === 'kamrej';
    $base = SiteFacts::pickupPlaces();
    $names = static fn(array $l): array => array_map(static fn(array $p): string => $p['name'], $l);
    /* review: the city after the comma (or the place's town) must never hide a different stop -
       "S Hari Parking — Nana Chiloda, Ahmedabad" and "Ahmedabad — Paldi Bus Stand" are two boarding points */
    $isAhm = static fn(array $p): bool => $p['kind'] === 'pickup' && mb_strtolower($p['town']) === 'ahmedabad';
    $pinnedAhm = Database::fetch("SELECT id FROM route_stops WHERE stop_type = 'boarding' AND stop_name LIKE 'Ahmedabad%'
                                   AND latitude IS NOT NULL AND longitude IS NOT NULL AND latitude <> 0 AND longitude <> 0 LIMIT 1");
    $chiloda = array_filter($base, static fn(array $p): bool => str_contains(mb_strtolower($p['name']), 'nana chiloda, ahmedabad')) !== [];
    if ($pinnedAhm === null || !$chiloda) {
        echo "  SKIP  dedupe: Ahmedabad stop next to the Nana Chiloda place (no pinned Ahmedabad stop / Chiloda place here)\n";
    } else {
        check('dedupe: the Ahmedabad route stop stays beside "Nana Chiloda, Ahmedabad"', array_filter($base, $isAhm) !== [], implode(', ', $names($base)));
    }
    if (array_filter($base, $isKam) === []) {
        echo "  SKIP  dedupe: Kamrej checks (no pinned Kamrej stop on this DB)\n";
    } else {
        Database::run("DELETE FROM company_locations WHERE town IN ('Wxtown', 'Wxspot')");
        Database::insert('company_locations', ['name' => 'Wxtown — Kamrej Char Rasta', 'kind' => 'pickup', 'town' => 'Wxtown',
            'map_url' => 'https://www.google.com/maps?q=0.000006,0.000006', 'show_public' => 1, 'sort_order' => 999, 'created_at' => date('Y-m-d H:i:s')]);
        // a place whose comma city / town is every other stop's town: it must hide none of them
        $cities = implode(' ', array_map(static fn(array $p): string => $p['town'], array_filter($base, static fn(array $p): bool => $p['kind'] === 'pickup')));
        Database::insert('company_locations', ['name' => 'Wxspot Parking — Wxnana Spot, ' . $cities, 'kind' => 'parking', 'town' => 'Wxspot',
            'map_url' => 'https://www.google.com/maps?q=0.000007,0.000007', 'show_public' => 1, 'sort_order' => 999, 'created_at' => date('Y-m-d H:i:s')]);
        $after = SiteFacts::pickupPlaces();
        $hasPlace = array_filter($after, static fn(array $p): bool => $p['name'] === 'Wxtown — Kamrej Char Rasta') !== [];
        check('dedupe: company place kept, the "Kamrej" stop dropped', $hasPlace && array_filter($after, $isKam) === [], (string) count($after) . ' rows');
        // the two test places come first, so the 16-row cap can only cut base rows past 14
        $want = array_slice(array_values(array_filter($base, static fn(array $p): bool => !$isKam($p))), 0, 14);
        $missing = array_values(array_diff($names($want), $names($after)));
        $hasSpot = array_filter($after, static fn(array $p): bool => str_starts_with($p['name'], 'Wxspot Parking')) !== [];
        check('dedupe: every other stop / place stays, by name (comma city words hide nothing)', $hasSpot && $missing === [],
            $missing === [] ? count($want) . ' kept' : 'missing: ' . implode(', ', $missing));
        Settings::set('home_pickup_dedupe_on', '0', 'bool', 'company');
        Settings::flush();
        check('switch home_pickup_dedupe_on OFF: the Kamrej stop is back', array_filter(SiteFacts::pickupPlaces(), $isKam) !== []);
    }

    $base6 = getenv('SHG_TEST_BASE') ?: '';
    if ($base6 !== '') {
        Database::run("DELETE FROM settings WHERE skey = 'home_pickup_dedupe_on'");
        Settings::flush();
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true]]);
        $page = @file_get_contents(rtrim($base6, '/') . '/offices', false, $ctx);
        if (is_string($page) && $page !== '') {
            check('/offices: cards titled with the company, the town under it', str_contains($page, 'class="off-town"')
                && str_contains($page, '<h2 class="off-name">' . htmlspecialchars(SiteFacts::officeBrand('IN'), ENT_QUOTES) . '</h2>'));
            if ($npRow !== null) {
                check('/offices: the Nepal card says Shree Hari Global Pvt Ltd', str_contains($page, '<h2 class="off-name">Shree Hari Global Pvt Ltd</h2>'));
            }
        } else {
            check('/offices page reachable at SHG_TEST_BASE', false, $base6);
        }
    }
} catch (Throwable $e) {
    check('section 6: no exception', false, $e->getMessage());
} finally {
    try { Database::run("DELETE FROM company_locations WHERE town IN ('Wxtown', 'Wxspot')"); } catch (Throwable $e) { }
    foreach ($keep6 as $k => $row) {
        Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        if ($row !== null) {
            Database::insert('settings', $row);
        }
    }
    Settings::flush(); SiteFacts::flush(); CounterDesk::flush();
}
$tpl = (string) file_get_contents(dirname(__DIR__) . '/app.template.html');
$fn  = substr($tpl, (int) strpos($tpl, '  function offices(F) {'), 7000);
check('home card: o.brand title + town line (cp-town), old title when no brand', str_contains($fn, "String(o.brand || '')") && str_contains($fn, 'cp-town')
    && str_contains($fn, "esc(nm) + badge + '</h3>'"));
check('home guide: "नेपालगञ्जपछि · After Nepalgunj" (markup + 3 tables)', substr_count($tpl, 'After Nepalgunj') === 4 && !str_contains($tpl, 'Onward buses'));

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
