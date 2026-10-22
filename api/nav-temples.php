<?php
/**
 * GET /api/nav-temples.php — temples for the SHARI NAV 🛕 layer, from our own
 * place index (21 Oct 2026, ai1).
 *
 *   ?bbox=S,W,N,E&limit=60       -> temples inside a map view (span ≤ 2.5° each way)
 *   ?near=lat,lng&limit=10&r=40  -> the nearest temples within r km (≤ 80), nearest first
 *
 * Source: nav_places rows whose kind is exactly 'amenity:place_of_worship'
 * (deploy/navdata/navplaces.py writes '<osm key>:<value>' with amenity first
 * and import-places.php loads it as is; the religion tag is not imported).
 * So the NAME decides: mosques, churches, gurudwaras and dargahs are left
 * out; gompas / stupas / viharas are 'buddhist', Jain derasars 'jain',
 * everything else 'hindu'. The curated pilgrimage temples live in the page
 * (KB.temples) — this is the long tail around them. The box on lat/lng comes
 * first in the WHERE so ix_np_ll is used; the kind test then runs on those
 * rows only, and the 400 nearest the box centre are taken, so a dense city
 * hands the page the temples around what it is looking at (the client keeps
 * the ones nearest the view centre, P22).
 *
 * Answer: {results:[{name, nameEn, lat, lng, rel}]}   (+ km for ?near)
 *   200  Cache-Control: public, max-age=600
 *   400  {results:[], error}   bad or missing params      (no-store)
 *   429  {results:[]}          rate limit, Retry-After: 60 (no-store)
 * The layer simply draws nothing on an error. A database hiccup answers an
 * empty 200 with no-store, so a blip is not cached for ten minutes.
 *
 * With SHG_NAV_TEMPLES_TEST defined (tests) the file only declares its
 * functions and answers nothing: nav_temples_handle($_GET, $ip) returns
 * {code, body, cache} for every branch without exiting.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

const NAV_TEMPLES_KIND = 'amenity:place_of_worship';

/**
 * Religion from the name (both columns), or null when it is not a shrine
 * we draw. Lower-cased, so Latin spellings match either case; Devanagari
 * is compared as written. Short words that also start town names (jama,
 * takht, parish, mazar, jain, vihar) only count as whole words: Jamalpur,
 * Takhteshwar Mandir, Vikas Parishad and Jainagar are not what they contain.
 */
function nav_temples_classify(string $name, ?string $nameEn): ?string
{
    $s = mb_strtolower(trim($name . ' ' . (string) $nameEn));
    if ($s === '') {
        return null;
    }
    // not ours: mosques, churches, gurudwaras, dargahs (+ the rarer ones)
    if (preg_match('/masjid|mosque|maszid|dargah|idgah|eidgah|imambara|imambargah|khanqah'
        . '|church|cathedral|chapel|basilica|convent|abbey|priory|catholic|christ|jesus'
        . '|gurud?wara|gurdwara|gurudvara|singh sabha'
        . '|synagogue|agiary|agyari|fire temple|atash|bahai|baha\'i'
        . '|مسجد|मस्जिद|मस्ज़िद|दरगाह|ईदगाह|ईद गाह|इमामबाड़ा|इमामबाडा'
        . '|चर्च|गिरजा|गुरु?द्वारा|गुरूद्वारा/u', $s) === 1) {
        return null;
    }
    if (preg_match('/(?<![a-z])(?:jama|takht|parish|mazar)(?![a-z])'
        . '|(?<![\p{L}\p{M}])(?:जामा|मज़ार|मजार)(?![\p{L}\p{M}])/u', $s) === 1) {
        return null;
    }
    if (preg_match('/(?<![a-z])jain(?![a-z])|derasar|dehrasar|digambar|shwetambar|svetambar|jinalay'
        . '|(?<![\p{L}\p{M}])जैन(?![\p{L}\p{M}])|देरासर|दिगम्बर|दिगंबर|श्वेताम्बर|श्वेतांबर|जिनालय/u', $s) === 1) {
        return 'jain';
    }
    if (preg_match('/gompa|gumba|gonpa|stupa|chaitya|chorten|monastery|pagoda|buddh|bauddha'
        . '|बुद्ध|बौद्ध|गुम्बा|गोम्पा|गोन्पा|स्तूप|स्तुप|चैत्य|चोर्तेन|छोर्तेन|पगोडा/u', $s) === 1) {
        return 'buddhist';
    }
    // "vihar" / "bihar" (Buddhist vihara) also names half the colonies of
    // Delhi and a whole state — a Shiv Mandir in Mayur Vihar stays Hindu
    $vihar = preg_match('/(?<![a-z])(?:maha)?[vb]ihara?(?![a-z])|(?<![\p{L}\p{M}])(?:महा)?[वब]िहार(?![\p{L}\p{M}])/u', $s) === 1;
    $hindu = preg_match('/mandir|temple|shiv|hanuman|devi|mata|durga|kali|ganesh|ganpati|krishna|narayan|vishnu'
        . '|laxmi|lakshmi|bhairav|mahadev|jagannath|ashram|dham'
        . '|मन्दिर|मंदिर|शिव|हनुमान|देवी|माता|दुर्गा|काली|गणेश|कृष्ण|नारायण|विष्णु|लक्ष्मी|भैरव|महादेव|जगन्नाथ|आश्रम|धाम/u', $s) === 1;
    if ($vihar && !$hindu) {
        return 'buddhist';
    }
    return 'hindu';
}

/** Great-circle distance in km (haversine). */
function nav_temples_km(float $la1, float $lo1, float $la2, float $lo2): float
{
    $dLa = deg2rad($la2 - $la1);
    $dLo = deg2rad($lo2 - $lo1);
    $h = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;
    return 12742.0 * asin(min(1.0, sqrt($h)));
}

/**
 * The raw place-of-worship rows of a box: the 400 nearest its centre, so
 * a city centre does not hand back an arbitrary 400. null when the table
 * is not there yet or the database is away — the caller answers empty and
 * uncached. (Every placeholder is named once: this PDO does not emulate
 * prepares.)
 *
 * @return list<array{name: string, name_en: ?string, lat: mixed, lng: mixed}>|null
 */
function nav_temples_query(float $s, float $w, float $n, float $e, float $cLat, float $cLng): ?array
{
    $sql = 'SELECT name, name_en, lat + 0 AS lat, lng + 0 AS lng,
                   (POW(lat - :la, 2) + POW((lng - :lo) * 0.92, 2)) AS d2
              FROM nav_places
             WHERE lat BETWEEN :a1 AND :a2 AND lng BETWEEN :b1 AND :b2 AND kind = :k
             ORDER BY d2 LIMIT 400';
    try {
        return Database::fetchAll($sql, ['la' => $cLat, 'lo' => $cLng, 'a1' => $s, 'a2' => $n, 'b1' => $w, 'b2' => $e, 'k' => NAV_TEMPLES_KIND]);
    } catch (Throwable $e) {
        return null;   // nav_places not imported yet, or the database is away: the layer draws nothing
    }
}

/**
 * Classify, drop the non-temples, sort nearest the centre first, dedupe
 * (same name within ~150 m — a temple mapped as a node and again as its
 * building; the kept row takes the English name of the dropped twin), cut.
 * $center = [lat, lng]; $rKm = radius for ?near (adds km), null for a box.
 *
 * @return list<array{name: string, nameEn: string, lat: float, lng: float, rel: string}>
 */
function nav_temples_shape(array $rows, array $center, ?float $rKm, int $limit): array
{
    $list = [];
    foreach ($rows as $r) {
        $name = trim((string) ($r['name'] ?? ''));
        $en = trim((string) ($r['name_en'] ?? ''));
        $rel = nav_temples_classify($name, $en);
        if ($rel === null) {
            continue;
        }
        $lat = (float) ($r['lat'] ?? 0);
        $lng = (float) ($r['lng'] ?? 0);
        $d = nav_temples_km($center[0], $center[1], $lat, $lng);
        if ($rKm !== null && $d > $rKm) {
            continue;
        }
        $it = ['name' => $name !== '' ? $name : $en, 'nameEn' => $en, 'lat' => round($lat, 5), 'lng' => round($lng, 5), 'rel' => $rel];
        if ($rKm !== null) {
            $it['km'] = round($d, 1);
        }
        $it['_d'] = $d;
        $it['_k1'] = mb_strtolower($it['name']);
        $it['_k2'] = mb_strtolower($en);
        $list[] = $it;
    }
    usort($list, static fn (array $a, array $b): int => $a['_d'] <=> $b['_d']);
    $out = [];
    foreach ($list as $it) {
        foreach ($out as $i => $o) {
            $same = $it['_k1'] === $o['_k1']
                || ($it['_k2'] !== '' && ($it['_k2'] === $o['_k2'] || $it['_k2'] === $o['_k1']))
                || ($o['_k2'] !== '' && $o['_k2'] === $it['_k1']);
            if ($same && nav_temples_km($it['lat'], $it['lng'], $o['lat'], $o['lng']) < 0.15) {
                if ($o['nameEn'] === '' && $it['nameEn'] !== '') {
                    $out[$i]['nameEn'] = $it['nameEn'];
                    $out[$i]['_k2'] = $it['_k2'];
                }
                continue 2;
            }
        }
        $out[] = $it;
        if (count($out) >= $limit) {
            break;
        }
    }
    foreach ($out as &$o) {
        unset($o['_d'], $o['_k1'], $o['_k2']);
    }
    unset($o);
    return $out;
}

/**
 * The whole request: query string in, {code, body, cache} out. Kept free
 * of header()/exit so the test harness can run every branch in one process.
 *
 * @return array{code: int, body: string, cache: bool}
 */
function nav_temples_handle(array $q, string $ip): array
{
    $json = static fn (array $o): string => (string) json_encode($o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // nav-weather style: one closure for every refusal; the body always
    // carries results:[] so the layer never meets a different shape
    $fail = static fn (int $code, string $why = ''): array =>
        ['code' => $code, 'body' => $json($why === '' ? ['results' => []] : ['results' => [], 'error' => $why]), 'cache' => false];
    $ok = static fn (array $res, bool $cache): array => ['code' => 200, 'body' => $json(['results' => $res]), 'cache' => $cache];
    $str = static fn ($v): string => is_string($v) ? trim($v) : '';     // bbox[]=1 is not a box
    $nums = static function (string $v, int $n): ?array {
        $parts = explode(',', $v);
        if (count($parts) !== $n) {
            return null;
        }
        $out = [];
        foreach ($parts as $x) {
            $f = filter_var(trim($x), FILTER_VALIDATE_FLOAT);
            if ($f === false || !is_finite($f)) {
                return null;
            }
            $out[] = (float) $f;
        }
        return $out;
    };

    // 60 views a minute is a fast pan; a scraper of the index is not. A
    // limiter that cannot reach its table lets the request through (as
    // Security::rateLimit itself does): this layer is never worth a 500.
    try {
        $allowed = Security::rateLimit('nav_temples', $ip, 60, 60);
    } catch (Throwable $e) {
        $allowed = true;
    }
    if (!$allowed) {
        return $fail(429);
    }

    $limit = is_numeric($q['limit'] ?? null) ? (int) $q['limit'] : 0;
    if (isset($q['bbox'])) {
        $b = $nums($str($q['bbox']), 4);
        if ($b === null) {
            return $fail(400, 'bbox must be S,W,N,E');
        }
        [$s, $w, $n, $e] = $b;
        if ($s < -90 || $n > 90 || $w < -180 || $e > 180 || $s >= $n || $w >= $e) {
            return $fail(400, 'bbox must be S,W,N,E with S < N and W < E');
        }
        if ($n - $s > 2.5 || $e - $w > 2.5) {
            return $fail(400, 'bbox span must be 2.5 degrees or less');
        }
        $limit = max(1, min(60, $limit > 0 ? $limit : 60));
        $c = [($s + $n) / 2, ($w + $e) / 2];
        $inside = $n >= 5 && $s <= 40 && $e >= 60 && $w <= 100;          // overlaps our map area, else no query
        $rows = $inside ? nav_temples_query($s, $w, $n, $e, $c[0], $c[1]) : [];
        return $ok(nav_temples_shape($rows ?? [], $c, null, $limit), $rows !== null);
    }
    if (isset($q['near'])) {
        $c = $nums($str($q['near']), 2);
        if ($c === null) {
            return $fail(400, 'near must be lat,lng');
        }
        [$lat, $lng] = $c;
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return $fail(400, 'near must be lat,lng');
        }
        $r = filter_var($str($q['r'] ?? ''), FILTER_VALIDATE_FLOAT);
        $r = $r === false || !is_finite($r) ? 40.0 : max(1.0, min(80.0, (float) $r));
        $limit = max(1, min(60, $limit > 0 ? $limit : 10));
        $dLat = $r / 111.0;
        $dLng = $r / (111.0 * max(0.2, cos(deg2rad($lat))));
        $inside = $lat >= 4 && $lat <= 41 && $lng >= 59 && $lng <= 101;
        $rows = $inside ? nav_temples_query($lat - $dLat, $lng - $dLng, $lat + $dLat, $lng + $dLng, $lat, $lng) : [];
        return $ok(nav_temples_shape($rows ?? [], [$lat, $lng], $r, $limit), $rows !== null);
    }
    return $fail(400, 'give bbox=S,W,N,E or near=lat,lng');
}

if (!defined('SHG_NAV_TEMPLES_TEST')) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $res = nav_temples_handle($_GET, Security::clientIp());
    http_response_code($res['code']);
    header($res['cache'] ? 'Cache-Control: public, max-age=600' : 'Cache-Control: no-store');
    if ($res['code'] === 429) {
        header('Retry-After: 60');
    }
    echo $res['body'];
}
