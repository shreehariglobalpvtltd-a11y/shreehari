<?php
/**
 * GET /api/nav-search.php — SHARI NAV place search, from our own database (5 Oct 2026).
 *
 *   ?q=station&lat=21.17&lng=72.83&limit=8  -> places, nearest first among equal matches
 *   ?rev=21.1702,72.8311                     -> the nearest named place / road (≤ 400 m)
 *
 * Our boarding points and offices come first, then nav_places — the named
 * shops, temples, hospitals, stations, bus stops and streets of all of India
 * and Nepal, imported from OpenStreetMap by deploy/navdata/import-places.php.
 * Result shape = window.mapGeocode()'s.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

if (!Security::rateLimit('nav_search', Security::clientIp(), 120, 60)) {
    http_response_code(429);
    exit('{"results":[]}');
}

$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : null;
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : null;
$out = [];
$icon = static function (string $kind): string {
    $k = explode(':', $kind)[1] ?? $kind;
    return match (true) {
        str_contains($k, 'bus') => 'bus', str_contains($k, 'station') || str_contains($k, 'halt') => 'train',
        in_array($k, ['hospital', 'clinic', 'doctors', 'pharmacy'], true) => 'health',
        in_array($k, ['place_of_worship', 'temple'], true) => 'temple',
        in_array($k, ['restaurant', 'cafe', 'fast_food'], true) => 'food',
        in_array($k, ['fuel'], true) => 'fuel', in_array($k, ['hotel', 'guest_house'], true) => 'hotel',
        str_starts_with($kind, 'road:') => 'road', default => 'place',
    };
};

try {
    /* ---- reverse: what is here? ---- */
    if (isset($_GET['rev'])) {
        [$rl, $rg] = array_map('floatval', explode(',', (string) $_GET['rev']) + [1 => 0]);
        $d = 0.004;
        $row = Database::fetch(
            'SELECT name, name_en, kind, lat, lng, (POW(lat - :a, 2) + POW((lng - :b) * 0.92, 2)) AS d2
               FROM nav_places WHERE lat BETWEEN :a1 AND :a2 AND lng BETWEEN :b1 AND :b2
              ORDER BY d2 LIMIT 1',
            ['a' => $rl, 'b' => $rg, 'a1' => $rl - $d, 'a2' => $rl + $d, 'b1' => $rg - $d, 'b2' => $rg + $d]
        );
        echo json_encode(['name' => $row !== null ? (string) ($row['name_en'] ?: $row['name']) : null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---- which town / area am I in? (the navigator's "current city" line) ---- */
    if (isset($_GET['city'])) {
        [$cl, $cg] = array_map('floatval', explode(',', (string) $_GET['city']) + [1 => 0]);
        $near = static function (array $kinds, float $box) use ($cl, $cg): ?array {
            $in = implode(',', array_map(static fn ($k) => "'" . $k . "'", $kinds));
            return Database::fetch(
                "SELECT name, name_en, (POW(lat - :a, 2) + POW((lng - :b) * 0.92, 2)) AS d2 FROM nav_places
                  WHERE kind IN ($in) AND lat BETWEEN :a1 AND :a2 AND lng BETWEEN :b1 AND :b2 ORDER BY d2 LIMIT 1",
                ['a' => $cl, 'b' => $cg, 'a1' => $cl - $box, 'a2' => $cl + $box, 'b1' => $cg - $box, 'b2' => $cg + $box]
            );
        };
        $area = $near(['place:suburb', 'place:neighbourhood', 'place:quarter'], 0.03);
        $town = $near(['place:city', 'place:town'], 0.25) ?? $near(['place:village'], 0.06);
        $nm = static fn (?array $r): string => $r !== null ? (string) ($r['name_en'] ?: $r['name']) : '';
        $parts = array_values(array_unique(array_filter([$nm($area), $nm($town)])));
        echo json_encode(['name' => $parts !== [] ? implode(' · ', $parts) : null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $q = trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 60));
    $limit = max(1, min(12, (int) ($_GET['limit'] ?? 8)));
    if (mb_strlen($q) < 2) {
        echo '{"results":[]}';
        exit;
    }
    $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

    // 1. our own boarding points (route_stops with a surveyed position) come
    //    first — but when we know where the rider is, a pickup more than
    //    ~60 km away waits behind the places near them ("bus stand" in
    //    Nepalgunj must not answer with Ahmedabad's counter)
    $far = [];
    foreach (Database::fetchAll(
        "SELECT stop_name, MIN(landmark) AS landmark, latitude, longitude FROM route_stops
          WHERE is_active = 1 AND latitude IS NOT NULL AND (stop_name LIKE :q1 OR landmark LIKE :q2)
          GROUP BY stop_name, latitude, longitude LIMIT 5",
        ['q1' => '%' . $like . '%', 'q2' => '%' . $like . '%']
    ) as $s) {
        $hit = ['shortName' => (string) $s['stop_name'], 'displayName' => 'S Hari pickup · ' . $s['stop_name'] . ($s['landmark'] ? ' · ' . $s['landmark'] : ''),
                'localName' => '', 'lat' => (float) $s['latitude'], 'lng' => (float) $s['longitude'], 'provider' => 'S Hari', 'kind' => 'bus'];
        $away = $lat !== null && $lng !== null && $lat > 5 && $lng > 60
            && (($hit['lat'] - $lat) ** 2 + (($hit['lng'] - $lng) * 0.92) ** 2) > 0.55 ** 2;
        if ($away) {
            $far[] = $hit;
        } else {
            $out[] = $hit;
        }
    }

    // 2. the place index (1-2 million rows for all of India + Nepal), indexed
    //    lookups only — a "contains" scan of the whole table takes seconds:
    //    a) names that START with the query, anywhere (name prefix indexes)
    //    b) names that CONTAIN it, within ~30 km of the rider (lat/lng index)
    //    Order: a town / city of exactly that name, then what is near the
    //    rider (starts-with first), then the rest (towns first), nearest first.
    $near = $lat !== null && $lng !== null && $lat > 5 && $lng > 60;
    $box = 0.3;
    $d2 = static fn (array $r): float => $near ? (($r['lat'] - $lat) ** 2 + (($r['lng'] - $lng) * 0.92) ** 2) : 0.0;
    $cols = 'name, name_en, kind, lat + 0 AS lat, lng + 0 AS lng';
    $rows = Database::fetchAll(
        "(SELECT $cols FROM nav_places WHERE name = :e1 LIMIT 40)
          UNION ALL (SELECT $cols FROM nav_places WHERE name_en = :e2 LIMIT 40)
          UNION ALL (SELECT $cols FROM nav_places WHERE kind IN ('place:city', 'place:town') AND (name LIKE :t1 OR name_en LIKE :t2) LIMIT 40)
          UNION ALL (SELECT $cols FROM nav_places WHERE name LIKE :p1 LIMIT 120)
          UNION ALL (SELECT $cols FROM nav_places WHERE name_en LIKE :p2 LIMIT 120)",
        ['e1' => $q, 'e2' => $q, 't1' => $like . '%', 't2' => $like . '%', 'p1' => $like . '%', 'p2' => $like . '%']
    );
    if ($near) {
        $rows = array_merge($rows, Database::fetchAll(
            "SELECT $cols FROM nav_places
              WHERE lat BETWEEN :a1 AND :a2 AND lng BETWEEN :b1 AND :b2 AND (name LIKE :c1 OR name_en LIKE :c2)
              LIMIT 200",
            ['a1' => $lat - $box, 'a2' => $lat + $box, 'b1' => $lng - $box, 'b2' => $lng + $box,
             'c1' => '%' . $like . '%', 'c2' => '%' . $like . '%']
        ));
    }
    $ql = mb_strtolower($q);
    $wordRe = '/(^|[^\p{L}\p{M}\p{N}])' . preg_quote($ql, '/') . '($|[^\p{L}\p{M}\p{N}])/u';
    $tier = static function (array $r) use ($ql, $wordRe, $near, $box, $d2): int {
        $names = [mb_strtolower((string) $r['name']), mb_strtolower((string) ($r['name_en'] ?? ''))];
        $exact = in_array($ql, $names, true);
        $starts = str_starts_with($names[0], $ql) || ($names[1] !== '' && str_starts_with($names[1], $ql));
        $word = preg_match($wordRe, $names[0] . ' | ' . $names[1]) === 1;   // "Metro Station", not "Stationery"
        $town = in_array($r['kind'], ['place:city', 'place:town'], true);
        if ($exact && $town) {
            return 0;
        }
        if ($near && $d2($r) <= $box * $box) {
            return $word ? ($starts ? 1 : 2) : ($starts ? 3 : 4);
        }
        return $exact ? 5 : ($town ? 6 : 7);
    };
    foreach ($rows as &$r) {
        $r['_t'] = $tier($r);
        $r['_d'] = $d2($r);
    }
    unset($r);
    usort($rows, static fn (array $a, array $b): int => [$a['_t'], $a['_d']] <=> [$b['_t'], $b['_d']]);
    $seen = [];
    foreach ($rows as $r) {
        $name = (string) ($r['name_en'] ?: $r['name']);
        $grid = str_starts_with((string) $r['kind'], 'road:') ? 1 : 2;       // one long road / one mapped-twice place = one answer
        $key  = mb_strtolower($name) . '|' . round((float) $r['lat'], $grid) . '|' . round((float) $r['lng'], $grid);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $kind = (string) $r['kind'];
        $what = ucfirst(str_replace('_', ' ', explode(':', $kind)[1] ?? $kind));
        $out[] = [
            'shortName'   => $name,
            'displayName' => $name . ' · ' . $what,
            'localName'   => preg_match('/[\x{0900}-\x{097F}\x{0A80}-\x{0AFF}]/u', (string) $r['name']) === 1 ? (string) $r['name'] : '',
            'lat'         => (float) $r['lat'],
            'lng'         => (float) $r['lng'],
            'provider'    => 'SHARI NAV',
            'kind'        => $icon($kind),
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    $out = array_merge($out, $far);
} catch (Throwable $e) {
    // nav_places not imported yet: no own results, the page falls back to the public search
}
echo json_encode(['results' => array_slice($out, 0, $limit ?? 8), 'provider' => 'SHARI NAV'], JSON_UNESCAPED_UNICODE);
