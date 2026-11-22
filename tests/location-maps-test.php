<?php
/**
 * location-maps-test.php - satellite / road pictures of our places (21-22 Nov 2026).
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
check('nd(): Nepali digits', LocationMaps::nd('9.9') === '९.९');

// a noisy tile (a flat one compresses below Esri's "no data" size and would count as missing)
$tile = (static function (): string { $t = imagecreatetruecolor(256, 256); mt_srand(7); for ($i = 0; $i < 4000; $i++) { imagesetpixel($t, mt_rand(0, 255), mt_rand(0, 255), imagecolorallocate($t, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255))); } ob_start(); imagepng($t); return (string) ob_get_clean(); })();
$blank = (static function (): string { $t = imagecreatetruecolor(256, 256); imagefill($t, 0, 0, imagecolorallocate($t, 200, 200, 200)); ob_start(); imagejpeg($t); return (string) ob_get_clean(); })();
check('stub tile is bigger than a "no data" tile', strlen($tile) > 3000 && strlen($blank) < 3000, strlen($tile) . ' / ' . strlen($blank));
$urls = [];
LocationMaps::$fetch = static function (string $u) use ($tile, &$urls): ?string { $urls[] = $u; return $tile; };
LocationMaps::$router = static fn(array $s): array => ['ok' => true, 'routerKm' => 7.5, 'seconds' => 1080,
    'pts' => [[$s[0]['lat'], $s[0]['lng']], [($s[0]['lat'] + $s[1]['lat']) / 2, $s[0]['lng']], [$s[1]['lat'], $s[1]['lng']]],
    'maneuvers' => [
        ['type' => 2, 'length' => 0.08, 'street_names' => ['Bus Park Road'], 'begin_shape_index' => 0],
        ['type' => 15, 'length' => 5.2, 'street_names' => ['NH58', 'Ratna Rajmarg'], 'begin_shape_index' => 1],
        ['type' => 10, 'length' => 0.07, 'begin_shape_index' => 2],
        ['type' => 5, 'length' => 0, 'begin_shape_index' => 2]]];

$sat = LocationMaps::satellite($c, 'S Hari Parking - Nana Chiloda');
check('close picture saved as a hashed jpg', (bool) preg_match('#^uploads/locations/sat-[0-9a-f]{16}\.jpg$#', $sat) && is_file(ROOT_PATH . '/' . $sat), $sat);
check('close view asks Esri for zoom 19 first', $urls !== [] && str_contains($urls[0], 'World_Imagery/MapServer/tile/19/'), (string) ($urls[0] ?? ''));
$sz = getimagesize(ROOT_PATH . '/' . $sat);
check('close picture is 1200x900', $sz !== false && $sz[0] === 1200 && $sz[1] === 900);
check('same picture = same file (no duplicates)', LocationMaps::satellite($c, 'S Hari Parking - Nana Chiloda') === $sat);

// where Esri has no zoom-19 imagery, the close view falls back to zoom 18 drawn larger
$urls = [];
LocationMaps::$fetch = static function (string $u) use ($tile, $blank, &$urls): ?string { $urls[] = $u; return str_contains($u, '/tile/19/') ? $blank : $tile; };
$sat18 = LocationMaps::closeView($c, ['ne' => '', 'en' => 'Fallback test']);
check('no zoom-19 imagery -> zoom 18 used', (bool) array_filter($urls, static fn($u) => str_contains($u, '/tile/18/')) && is_file(ROOT_PATH . '/' . $sat18));
$s18 = getimagesize(ROOT_PATH . '/' . $sat18);
check('fallback picture still 1200x900', $s18 !== false && $s18[0] === 1200 && $s18[1] === 900);
LocationMaps::$fetch = static function (string $u) use ($tile, &$urls): ?string { $urls[] = $u; return $tile; };

$area = LocationMaps::areaView($c, ['ne' => 'चिलोडा', 'en' => 'Chiloda']);
check('area view saved', str_starts_with($area, 'uploads/locations/area-') && is_file(ROOT_PATH . '/' . $area), $area);

$g = LocationMaps::guide([
        ['type' => 2, 'length' => 0.08, 'street_names' => ['Bus Park Road'], 'begin_shape_index' => 0],
        ['type' => 15, 'length' => 0.74, 'street_names' => ['NH58', 'Ratna Rajmarg'], 'begin_shape_index' => 1],
        ['type' => 26, 'length' => 0.01, 'roundabout_exit_count' => 2, 'begin_shape_index' => 2],
        ['type' => 27, 'length' => 0.97, 'street_names' => ['NH58'], 'begin_shape_index' => 3],
        ['type' => 15, 'length' => 2.54, 'street_names' => ['NH927'], 'begin_shape_index' => 5],
        ['type' => 5, 'length' => 0, 'begin_shape_index' => 6]],
    [[28.07, 81.63], [28.06, 81.62], [28.05, 81.61], [28.04, 81.60], [LocationMaps::BORDER['lat'], LocationMaps::BORDER['lng']], [28.01, 81.59], [27.9997, 81.5867]],
    'रुपैडिहा पार्किङ', 'Rupaidiha parking');
$gt = implode("\n", array_column($g, 'ne'));
check('guide: Nepali turn words + road names', str_contains($gt, 'बायाँ मोडिनुहोस्') && str_contains($gt, 'रत्न राजमार्ग'), $gt);
check('guide: roundabout exit in Nepali digits', str_contains($gt, '२ औं निकास'), $gt);
check('guide: border crossing step added', str_contains($gt, 'बोर्डर') && str_contains(implode(' ', array_column($g, 'en')), 'border'));
$last = $g[count($g) - 1]['ne'];
check('guide: arrives on the right', str_contains($last, 'आइपुग्यो') && str_contains($last, 'दायाँ'), $last);
check('guide: km in Nepali digits', str_contains($gt, '२.५ कि.मि.'));

$r = LocationMaps::route(['lat' => 28.071306, 'lng' => 81.632472], LocationMaps::BORDER, 'Nepalgunj office', 'Rupaidiha border');
check('road picture saved with km', str_starts_with($r['file'], 'uploads/locations/route-') && $r['km'] === 7.5, (string) json_encode($r));
$rd = LocationMaps::road(['lat' => 28.071306, 'lng' => 81.632472], ['lat' => 27.9996732, 'lng' => 81.5867382], ['ne' => 'नेपालगञ्ज कार्यालय', 'en' => 'Nepalgunj office'], ['ne' => 'रुपैडिहा पार्किङ', 'en' => 'Rupaidiha parking']);
check('road: overview + approach + guide + minutes', is_file(ROOT_PATH . '/' . $rd['route']) && str_starts_with($rd['approach'], 'uploads/locations/approach-') && count($rd['guide']) >= 3 && $rd['min'] === 18, (string) json_encode(['min' => $rd['min'], 'n' => count($rd['guide'])]));
$rs = getimagesize(ROOT_PATH . '/' . $rd['route']);
check('road picture is 1600x1150 (map + guide panel)', $rs !== false && $rs[0] === 1600 && $rs[1] === 1150);

// buildAll on the real table; rows and settings put back afterwards
CompanyLocations::ensure();
$rows = Database::fetchAll('SELECT id, map_url, kind FROM company_locations');
$keep = Database::fetchAll("SELECT skey, svalue FROM settings WHERE skey LIKE 'locmap\\_%'");
try {
    $office = LocationMaps::nepalOffice();
    check('the Nepalgunj office row is found', $office !== null);
    $park = Database::fetch("SELECT id FROM company_locations WHERE town = 'Chiloda' LIMIT 1");
    if ($park !== null) {
        Database::run('UPDATE company_locations SET map_url = :m WHERE id = :i', ['m' => $chiloda, 'i' => $park['id']]);
    }
    $rp = Database::fetch("SELECT id FROM company_locations WHERE town = 'Rupaidiha' AND kind IN ('parking', 'pickup') ORDER BY sort_order LIMIT 1");
    if ($rp !== null) {
        Database::run('UPDATE company_locations SET map_url = NULL WHERE town = :t', ['t' => 'Rupaidiha']);
    }
    $lines = LocationMaps::buildAll();
    $all = implode("\n", $lines);
    check('buildAll reports each place', count($lines) >= 3, $all);
    if ($park !== null) {
        $s = LocationMaps::saved((int) $park['id']);
        check('Chiloda now has close + area pictures', !empty($s['sat']) && !empty($s['area']), (string) json_encode($s));
        check('Chiloda gets no NPJ road (not in Rupaidiha)', empty($s['route']));
    }
    check('a place with no pin is named, not drawn', str_contains($all, 'no pin'), $all);
    if ($office !== null) {
        $s = LocationMaps::saved((int) $office['id']);
        check('Nepalgunj office -> Rupaidiha border road while no Rupaidiha pin', !empty($s['route']) && str_contains((string) ($s['to'] ?? ''), 'border'), (string) json_encode($s, JSON_UNESCAPED_UNICODE));
        if ($rp !== null) {
            // a Rupaidiha pickup (not only "parking") is the target too
            Database::run("UPDATE company_locations SET map_url = :m, kind = 'pickup' WHERE id = :i", ['m' => 'https://www.google.com/maps?q=27.9996732,81.5867382', 'i' => $rp['id']]);
            LocationMaps::buildAll();
            $s = LocationMaps::saved((int) $office['id']);
            check('with our Rupaidiha place pinned, the road goes there', !str_contains((string) ($s['to'] ?? ''), 'checkpost') && !empty($s['guide']), (string) ($s['to'] ?? ''));
            $rs2 = LocationMaps::saved((int) $rp['id']);
            check('the Rupaidiha place carries the road, approach and Nepali guide too', !empty($rs2['route']) && !empty($rs2['approach']) && !empty($rs2['guide']), (string) json_encode(array_keys($rs2)));
            check('a rebuild replaces old keys (no stale picture)', !isset($s['old']));
        }
    }
} finally {
    foreach ($rows as $row) {
        Database::run('UPDATE company_locations SET map_url = :m, kind = :k WHERE id = :i', ['m' => $row['map_url'], 'k' => $row['kind'], 'i' => $row['id']]);
    }
    Database::run("DELETE FROM settings WHERE skey LIKE 'locmap\\_%'");
    foreach ($keep as $k) {
        Database::run('INSERT INTO settings (skey, svalue) VALUES (:k, :v)', ['k' => $k['skey'], 'v' => $k['svalue']]);
    }
    Settings::flush();
    foreach (glob(ROOT_PATH . '/uploads/locations/{sat,route,area,approach}-*.jpg', GLOB_BRACE) ?: [] as $f) {
        if (filemtime($f) >= time() - 600) { @unlink($f); }
    }
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
