<?php
/**
 * GET /api/nav-features.php?bbox=minLng,minLat,maxLng,maxLat&kinds=speed_camera,school,ev,cng,lpg
 *
 * SHARI NAV Phase 3 (22 Oct 2026): road features in a box along the route -
 * speed cameras (with the limit), school zones, EV chargers, CNG and LPG
 * stations. Speed cameras / EV / CNG / LPG come from nav_features (our OSM
 * extract), schools from the place index (nav_places, amenity:school).
 * Answer: {features:[{k, lat, lng, n, ms?, x?}]}  - at most 600, box <= 2.5°.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

try {
    Security::requireRateLimit('nav_features', Security::clientIp(), 60, 60);
    $b = array_map('floatval', explode(',', (string) ($_GET['bbox'] ?? '')));
    if (count($b) !== 4 || $b[0] >= $b[2] || $b[1] >= $b[3] || $b[1] < 5 || $b[3] > 40 || $b[0] < 60 || $b[2] > 100
        || $b[2] - $b[0] > 2.5 || $b[3] - $b[1] > 2.5) {
        Response::invalid(['bbox' => 'A box inside India / Nepal, at most 2.5 degrees a side.']);
    }
    $want = array_intersect(explode(',', (string) ($_GET['kinds'] ?? '')), ['speed_camera', 'school', 'ev', 'cng', 'lpg']);
    $box = ['lo1' => $b[0], 'la1' => $b[1], 'lo2' => $b[2], 'la2' => $b[3]];
    $out = [];
    $fk = array_values(array_diff($want, ['school']));
    if ($fk !== []) {
        $in = [];
        foreach ($fk as $i => $k) {
            $in['k' . $i] = $k;
        }
        foreach (Database::fetchAll(
            'SELECT kind, name, lat, lng, maxspeed, extra FROM nav_features
              WHERE kind IN (:' . implode(', :', array_keys($in)) . ')
                AND lat BETWEEN :la1 AND :la2 AND lng BETWEEN :lo1 AND :lo2 LIMIT 600',
            $in + $box
        ) as $r) {
            $o = ['k' => (string) $r['kind'], 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'n' => (string) $r['name']];
            if ($r['maxspeed'] !== null) {
                $o['ms'] = (int) $r['maxspeed'];
            }
            if ((string) $r['extra'] !== '') {
                $o['x'] = (string) $r['extra'];
            }
            $out[] = $o;
        }
    }
    if (in_array('school', $want, true) && count($out) < 600) {
        foreach (Database::fetchAll(
            "SELECT name, lat, lng FROM nav_places
              WHERE kind = 'amenity:school' AND lat BETWEEN :la1 AND :la2 AND lng BETWEEN :lo1 AND :lo2 LIMIT " . (600 - count($out)),
            $box
        ) as $r) {
            $out[] = ['k' => 'school', 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'n' => (string) $r['name']];
        }
    }
    Response::$publicCacheSeconds = 3600;
    Response::success(['features' => $out]);
} catch (Throwable $e) {
    Response::serverError($e);
}
