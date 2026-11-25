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
    try { Database::run("DELETE FROM company_locations WHERE town = 'Wxtown'"); } catch (Throwable $e) { }
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

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
