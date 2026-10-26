<?php
/**
 * GET /api/nav-explore.php?lat=..&lng=.. - "Explore" near a SHARI NAV
 * destination (Phase 3, 22 Oct 2026).
 *
 *   ours  - our own travel guide (travel_places: blurb, best season, how to
 *           reach, photo) within 80 km, nearest first, at most 6
 *   spots - sights from the place index (nav_places: attraction, viewpoint,
 *           museum, fort, monument, zoo, park reserve ...) within 15 km, 12
 *
 * Text comes in en / hi / ne; the page picks its language. Public, cached 1 h.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/etaalerts.php';

try {
    Security::requireRateLimit('nav_explore', Security::clientIp(), 40, 60);
    $lat = filter_var($_GET['lat'] ?? null, FILTER_VALIDATE_FLOAT);
    $lng = filter_var($_GET['lng'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($lat === false || $lng === false || $lat < 5 || $lat > 40 || $lng < 60 || $lng > 100) {
        Response::invalid(['lat' => 'Outside the service area.']);
    }
    $lat = (float) $lat;
    $lng = (float) $lng;
    $box = static function (float $km) use ($lat, $lng): array {
        $dLat = $km / 111.0;
        $dLng = $km / max(1.0, 111.0 * cos(deg2rad($lat)));
        return ['la1' => $lat - $dLat, 'la2' => $lat + $dLat, 'lo1' => $lng - $dLng, 'lo2' => $lng + $dLng];
    };
    $km = static fn(array $r): float => EtaAlerts::haversineKm($lat, $lng, (float) $r['lat'], (float) $r['lng']);

    $ours = [];
    foreach (Database::fetchAll(
        'SELECT slug, category, country, name_en, name_hi, name_ne, blurb_en, blurb_hi, blurb_ne, best_season_en, best_season_ne,
                how_to_reach_en, how_to_reach_ne, unesco, lat, lng, image
           FROM travel_places
          WHERE is_active = 1 AND lat BETWEEN :la1 AND :la2 AND lng BETWEEN :lo1 AND :lo2', $box(80.0)
    ) as $r) {
        $d = $km($r);
        if ($d > 80) {
            continue;
        }
        $img = (string) ($r['image'] ?? '');
        $ours[] = [
            'slug' => (string) $r['slug'], 'cat' => (string) $r['category'], 'unesco' => (int) $r['unesco'] === 1,
            'name' => ['en' => (string) $r['name_en'], 'hi' => (string) ($r['name_hi'] ?: $r['name_en']), 'ne' => (string) ($r['name_ne'] ?: $r['name_en'])],
            'blurb' => ['en' => (string) $r['blurb_en'], 'hi' => (string) ($r['blurb_hi'] ?: $r['blurb_en']), 'ne' => (string) ($r['blurb_ne'] ?: $r['blurb_en'])],
            'season' => ['en' => (string) $r['best_season_en'], 'ne' => (string) ($r['best_season_ne'] ?: $r['best_season_en'])],
            'reach' => ['en' => (string) $r['how_to_reach_en'], 'ne' => (string) ($r['how_to_reach_ne'] ?: $r['how_to_reach_en'])],
            'img' => $img === '' ? '' : (preg_match('#^https?://#', $img) === 1 ? $img : '/' . ltrim($img, '/')),
            'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'km' => round($d, 1),
        ];
    }
    usort($ours, static fn(array $a, array $b): int => $a['km'] <=> $b['km']);

    $kinds = ['tourism:attraction', 'tourism:viewpoint', 'tourism:museum', 'historic:fort', 'historic:castle', 'historic:monument',
              'tourism:zoo', 'tourism:theme_park', 'leisure:nature_reserve', 'natural:waterfall', 'waterway:waterfall'];
    $in = [];
    foreach ($kinds as $i => $k) {
        $in['k' . $i] = $k;
    }
    $spots = [];
    foreach (Database::fetchAll(
        'SELECT name, name_en, kind, lat, lng FROM nav_places
          WHERE kind IN (:' . implode(', :', array_keys($in)) . ') AND lat BETWEEN :la1 AND :la2 AND lng BETWEEN :lo1 AND :lo2 LIMIT 400',
        $in + $box(15.0)
    ) as $r) {
        $d = $km($r);
        if ($d <= 15 && trim((string) $r['name']) !== '') {
            $spots[] = ['name' => (string) $r['name'], 'en' => (string) ($r['name_en'] ?? ''), 'kind' => (string) $r['kind'],
                        'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'km' => round($d, 1)];
        }
    }
    usort($spots, static fn(array $a, array $b): int => $a['km'] <=> $b['km']);
    Response::$publicCacheSeconds = 3600;
    Response::success(['ours' => array_slice($ours, 0, 6), 'spots' => array_slice($spots, 0, 12)]);
} catch (Throwable $e) {
    Response::serverError($e);
}
