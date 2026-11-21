<?php
/**
 * location-maps-test.php - satellite / road pictures of our places (21 Nov 2026).
 * No network: tiles and the road come from stubs. Rows and settings are put back.
 *
 *   php -c .claude/php-dev.ini tests/location-maps-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/locationmaps.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
echo "\n=== Location maps ===\n\n";

$chiloda = 'https://www.google.com/maps/place/4M5G%2BMX7,+GIDC+Naroda,+Ahmedabad,+Gujarat+382330,+India/@23.1091625,72.6774219,17z/data=!3m1!4b1!4m5!3m4!1s0x395e80dc1e44a2f1:0x8d59275391abf8cc!8m2!3d23.1091625!4d72.6774219?entry=ttu';
$c = LocationMaps::coords($chiloda);
check("owner's Chiloda link gives the pin", $c !== null && abs($c['lat'] - 23.1091625) < 1e-6 && abs($c['lng'] - 72.6774219) < 1e-6, (string) json_encode($c));
check('?q=lat,lng link', LocationMaps::coords('https://www.google.com/maps?q=28.071306,81.632472') === ['lat' => 28.071306, 'lng' => 81.632472]);
check('!3d!4d wins over the @ camera', LocationMaps::coords('https://www.google.com/maps/place/x/@1.5,2.5,17z/data=!3d10.25!4d20.75') === ['lat' => 10.25, 'lng' => 20.75]);
check('a link with no pin = null (never invented)', LocationMaps::coords('https://maps.app.goo.gl/abcdEF') === null);
check('latin() keeps ASCII only', LocationMaps::latin('S Hari Parking — नाना चिलोडा') === 'S Hari Parking -');

// stubs: a plain tile for every URL, a 3-point road
$tile = (static function (): string { $t = imagecreatetruecolor(256, 256); imagefill($t, 0, 0, imagecolorallocate($t, 90, 110, 90)); ob_start(); imagepng($t); return (string) ob_get_clean(); })();
$urls = [];
LocationMaps::$fetch = static function (string $u) use ($tile, &$urls): ?string { $urls[] = $u; return $tile; };
LocationMaps::$router = static fn(array $s): array => ['ok' => true, 'source' => 'stub', 'error' => '', 'routerKm' => 7.5,
    'pts' => [[$s[0]['lat'], $s[0]['lng']], [($s[0]['lat'] + $s[1]['lat']) / 2, $s[0]['lng']], [$s[1]['lat'], $s[1]['lng']]]];

$sat = LocationMaps::satellite($c, 'S Hari Parking - Nana Chiloda');
check('satellite picture saved as a hashed jpg', (bool) preg_match('#^uploads/locations/sat-[0-9a-f]{16}\.jpg$#', $sat) && is_file(ROOT_PATH . '/' . $sat), $sat);
check('tiles asked from Esri at zoom 18', $urls !== [] && str_contains($urls[0], 'World_Imagery/MapServer/tile/18/'), (string) ($urls[0] ?? ''));
$sz = getimagesize(ROOT_PATH . '/' . $sat);
check('satellite picture is 1100x760', $sz !== false && $sz[0] === 1100 && $sz[1] === 760);
check('same picture = same file (no duplicates)', LocationMaps::satellite($c, 'S Hari Parking - Nana Chiloda') === $sat);

$r = LocationMaps::route(['lat' => 28.071306, 'lng' => 81.632472], LocationMaps::BORDER, 'Nepalgunj office', 'Rupaidiha border');
check('road picture saved with km', str_starts_with($r['file'], 'uploads/locations/route-') && $r['km'] === 7.5, (string) json_encode($r));

// buildAll on the real table; rows and settings put back afterwards
CompanyLocations::ensure();
$rows = Database::fetchAll('SELECT id, map_url FROM company_locations');
$keep = Database::fetchAll("SELECT skey, svalue FROM settings WHERE skey LIKE 'locmap\\_%'");
try {
    $office = LocationMaps::nepalOffice();
    check('the Nepalgunj office row is found', $office !== null);
    $park = Database::fetch("SELECT id FROM company_locations WHERE town = 'Chiloda' LIMIT 1");
    if ($park !== null) {
        Database::run('UPDATE company_locations SET map_url = :m WHERE id = :i', ['m' => $chiloda, 'i' => $park['id']]);
    }
    $lines = LocationMaps::buildAll();
    $all = implode("\n", $lines);
    check('buildAll reports each place', count($lines) >= 3, $all);
    if ($park !== null) {
        $s = LocationMaps::saved((int) $park['id']);
        check('Chiloda now has a satellite picture', !empty($s['sat']), (string) json_encode($s));
        check('Chiloda gets no NPJ road (not in Rupaidiha)', empty($s['route']));
    }
    check('a place with no pin is named, not drawn', str_contains($all, 'no pin'), $all);
    if ($office !== null) {
        $s = LocationMaps::saved((int) $office['id']);
        check('Nepalgunj office -> Rupaidiha border road while no Rupaidiha pin', !empty($s['route']) && str_contains((string) ($s['to'] ?? ''), 'border'), (string) json_encode($s));
        $rp = Database::fetch("SELECT id FROM company_locations WHERE town = 'Rupaidiha' AND kind = 'parking' LIMIT 1");
        if ($rp !== null) {
            Database::run('UPDATE company_locations SET map_url = :m WHERE id = :i', ['m' => 'https://www.google.com/maps?q=28.0150,81.5950', 'i' => $rp['id']]);
            LocationMaps::buildAll();
            $s = LocationMaps::saved((int) $office['id']);
            check('with our Rupaidiha parking pinned, the road goes there', str_contains((string) ($s['to'] ?? ''), 'own parking'), (string) json_encode($s));
            check('the Rupaidiha parking itself carries the road too', !empty(LocationMaps::saved((int) $rp['id'])['route']));
        }
    }
} finally {
    foreach ($rows as $row) {
        Database::run('UPDATE company_locations SET map_url = :m WHERE id = :i', ['m' => $row['map_url'], 'i' => $row['id']]);
    }
    Database::run("DELETE FROM settings WHERE skey LIKE 'locmap\\_%'");
    foreach ($keep as $k) {
        Database::run('INSERT INTO settings (skey, svalue) VALUES (:k, :v)', ['k' => $k['skey'], 'v' => $k['svalue']]);
    }
    Settings::flush();
    foreach (glob(ROOT_PATH . '/uploads/locations/{sat,route}-*.jpg', GLOB_BRACE) ?: [] as $f) {
        if (filemtime($f) >= time() - 600) { @unlink($f); }
    }
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
