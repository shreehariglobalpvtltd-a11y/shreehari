<?php
/**
 * deploy/navdata/features-import.php - fill nav_features (SHARI NAV Phase 3,
 * 22 Oct 2026) from our own OpenStreetMap extract. CLI only.
 *
 *   cd /srv/shg-nav/work-full/feat
 *   osmium tags-filter /srv/shg-nav/region.osm.pbf n/highway=speed_camera \
 *       nw/amenity=fuel nw/amenity=charging_station -o features.osm.pbf --overwrite
 *   osmium export features.osm.pbf -f geojsonseq -o features.geojsonseq --overwrite -a type,id
 *   php /var/www/shreehariglobal.in/public_html/deploy/navdata/features-import.php features.geojsonseq
 *
 * Keeps: speed_camera (+ maxspeed), ev (amenity=charging_station), cng / lpg
 * (fuel:cng / fuel:lpg = yes, or CNG / LPG / auto gas in the name). An area
 * becomes the average of its outline. The table is replaced in one
 * transaction, so the map never sees it half-filled.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('SHG_APP', true);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$file = $argv[1] ?? '';
$fh = $file !== '' ? fopen($file, 'r') : false;
if ($fh === false) {
    fwrite(STDERR, "usage: php features-import.php features.geojsonseq\n");
    exit(1);
}

$rows = [];
while (($line = fgets($fh)) !== false) {
    $line = trim($line, "\x1e \r\n");
    if ($line === '') {
        continue;
    }
    $f = json_decode($line, true);
    if (!is_array($f) || !isset($f['geometry']['coordinates'], $f['properties'])) {
        continue;
    }
    $p = $f['properties'];
    $name = trim((string) ($p['name:en'] ?? ($p['name'] ?? '')));
    $kinds = [];
    if (($p['highway'] ?? '') === 'speed_camera') {
        $kinds[] = 'speed_camera';
    }
    if (($p['amenity'] ?? '') === 'charging_station') {
        $kinds[] = 'ev';
    }
    if (($p['amenity'] ?? '') === 'fuel') {
        if (($p['fuel:cng'] ?? '') === 'yes' || preg_match('/\bcng\b/i', $name) === 1) {
            $kinds[] = 'cng';
        }
        if (($p['fuel:lpg'] ?? '') === 'yes' || preg_match('/\b(lpg|auto ?gas)\b/i', $name) === 1) {
            $kinds[] = 'lpg';
        }
    }
    if ($kinds === []) {
        continue;
    }
    $g = $f['geometry'];
    if ($g['type'] === 'Point') {
        [$lng, $lat] = $g['coordinates'];
    } else {
        $ring = match ($g['type']) {
            'LineString'   => $g['coordinates'],              /* a closed way osmium did not see as an area */
            'Polygon'      => $g['coordinates'][0] ?? [],
            'MultiPolygon' => $g['coordinates'][0][0] ?? [],
            default        => [],
        };
        if (!is_array($ring) || $ring === [] || !is_array($ring[0] ?? null)) {
            continue;
        }
        $lng = array_sum(array_column($ring, 0)) / count($ring);
        $lat = array_sum(array_column($ring, 1)) / count($ring);
    }
    if ($lat < 5 || $lat > 40 || $lng < 60 || $lng > 100) {
        continue;
    }
    $ms = preg_match('/^\d{2,3}$/', (string) ($p['maxspeed'] ?? '')) === 1 ? (int) $p['maxspeed'] : null;
    $extra = trim(implode(' ', array_filter([(string) ($p['brand'] ?? ''), (string) ($p['operator'] ?? ''), (string) ($p['socket:type2'] ?? '') !== '' ? 'Type2' : '',
        (string) ($p['socket:ccs'] ?? '') !== '' ? 'CCS' : ''])));
    foreach ($kinds as $k) {
        $rows[] = ['kind' => $k, 'osm_ref' => substr((string) ($p['@type'] ?? 'n'), 0, 1) . (string) ($p['@id'] ?? ''),
                   'name' => mb_substr($name, 0, 150), 'lat' => round((float) $lat, 6), 'lng' => round((float) $lng, 6),
                   'maxspeed' => $k === 'speed_camera' ? $ms : null, 'extra' => mb_substr($extra, 0, 120)];
    }
}
fclose($fh);

Database::transaction(static function () use ($rows): void {
    Database::run('DELETE FROM nav_features');
    foreach ($rows as $r) {
        Database::insert('nav_features', $r);
    }
});
$by = [];
foreach ($rows as $r) {
    $by[$r['kind']] = ($by[$r['kind']] ?? 0) + 1;
}
echo json_encode(['rows' => count($rows), 'kinds' => $by]), "\n";
