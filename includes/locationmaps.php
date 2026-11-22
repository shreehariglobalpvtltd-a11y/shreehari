<?php
/**
 * =====================================================================
 *  LocationMaps — saved satellite pictures of our places, and the road
 *  from the Nepalgunj office to our Rupaidiha parking (21-22 Nov 2026).
 *
 *  Owner 21 Nov: "satellite image nikalakar Nepalgunj office bata direct
 *  hamro parking samma map herna milos - offline ma; NPJ bata Rupaidiha
 *  samma pani."
 *  Owner 22 Nov: "map satellite haru ekdam clear, bato dekhiyos, najik
 *  location, ra Nepali ma guide gardinus."
 *
 *  Pictures are built ONCE on our server and saved as plain JPGs under
 *  uploads/locations/ (file name = hash of the picture, so a URL never
 *  changes content). A phone that opened one keeps it (sw.js 'shg-places'
 *  cache) and it can be saved / sent on WhatsApp - no signal needed.
 *
 *  Per place with a pin:
 *    sat       CLOSE view (zoom 19 where Esri has it, else zoom 18 drawn
 *              twice as large and sharpened) - the gate / yard itself,
 *              50 m ring, Nepali + English name, Nepali note.
 *    area      the neighbourhood (zoom 17): which highway, which side.
 *  The Nepalgunj office row and our Rupaidiha parking also carry:
 *    route     the whole road on satellite, with a Nepali step-by-step
 *              guide panel (turns, km, border crossing) beside it.
 *    approach  the last ~700 m on a close satellite view: the turn in.
 *    guide     the same steps as text (Nepali + English) for the page.
 *
 *  Never invents a coordinate: the place's own Google Maps link gives the
 *  pin (!3d!4d, q=, @, or a maps.app.goo.gl link followed). Rupaidiha with
 *  no pin = the public "Immigration Checkpost Rupaidiha" (OSM way
 *  586178937). Saved in settings 'locmap_<id>'.
 *
 *  Nepali on the pictures is shaped by HarfBuzz (DevShape); where shaping
 *  is not available the picture falls back to English - never broken
 *  Devanagari.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/companylocations.php';
require_once __DIR__ . '/routegeo.php';
require_once __DIR__ . '/devshape.php';

final class LocationMaps
{
    public const TILE_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/%d/%d/%d';
    public const CREDIT   = 'Imagery: Esri, Maxar, Earthstar Geographics | Roads: OpenStreetMap';
    /** Public point used for Rupaidiha until our own parking has a pin (OSM way 586178937). */
    public const BORDER = ['lat' => 28.0174538, 'lng' => 81.5990318, 'name' => 'Rupaidiha border (Indian immigration checkpost)'];
    public const DIR    = 'uploads/locations';
    /** Esri answers a missing tile with a small grey "no data" JPEG. */
    private const EMPTY_TILE_BYTES = 3000;

    /** Test hook: fn(string $url): ?string (tile bytes) instead of the network. */
    public static $fetch = null;
    /** Test hook: fn(array $stops): array like route() expects (ok, pts, routerKm, maneuvers?, seconds?). */
    public static $router = null;

    /* ------------------------------------------------------------------
     *  Coordinates from a map link
     * ---------------------------------------------------------------- */

    /** @return array{lat:float,lng:float}|null */
    public static function coords(string $map): ?array
    {
        $map = trim(rawurldecode($map));
        if ($map === '') {
            return null;
        }
        $pats = [
            '/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/',            // the place's own pin
            '/[?&](?:q|query|ll|destination)=(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/',
            '/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/',                // the camera
            '/^(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)$/',
        ];
        foreach ($pats as $re) {
            if (preg_match($re, $map, $m) === 1) {
                $lat = (float) $m[1];
                $lng = (float) $m[2];
                if (abs($lat) <= 85 && abs($lng) <= 180 && ($lat != 0.0 || $lng != 0.0)) {
                    return ['lat' => $lat, 'lng' => $lng];
                }
            }
        }
        return null;
    }

    /** A short Google link (maps.app.goo.gl / goo.gl) followed to its long form; else as given. */
    public static function expand(string $map): string
    {
        $host = strtolower((string) parse_url($map, PHP_URL_HOST));
        if (!preg_match('/(^|\.)(goo\.gl|g\.co)$/', $host) || self::$fetch !== null) {
            return $map;
        }
        $url = $map;
        for ($i = 0; $i < 4; $i++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => 'Mozilla/5.0 (SHG maps)']);
            curl_exec($ch);
            $next = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);
            if ($next === '') {
                break;
            }
            $url = $next;
            if (self::coords($url) !== null) {
                return $url;
            }
        }
        return $url;
    }

    /** @return array{lat:float,lng:float}|null */
    public static function placeCoords(array $p): ?array
    {
        return $p['map'] !== '' ? self::coords(self::expand($p['map'])) : null;
    }

    /* ------------------------------------------------------------------
     *  Saved pictures
     * ---------------------------------------------------------------- */

    /** @return array{sat?:string, area?:string, route?:string, approach?:string, km?:float, min?:int, to?:string, guide?:list<array{ne:string,en:string}>, at?:string} */
    public static function saved(int $id): array
    {
        $j = json_decode(Settings::getString('locmap_' . $id, ''), true);
        return is_array($j) ? $j : [];
    }

    /** Replace a place's saved pictures (no stale picture of an older build survives). */
    private static function store(int $id, array $data): void
    {
        Settings::set('locmap_' . $id, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'string', 'locations');
        Settings::flush();
    }

    /** The Nepalgunj office row (kind office, town Nepalgunj), or null. */
    public static function nepalOffice(): ?array
    {
        foreach (CompanyLocations::all() as $p) {
            if ($p['kind'] === 'office' && stripos($p['town'], 'nepalgunj') !== false) {
                return $p;
            }
        }
        return null;
    }

    private static function isRupaidiha(array $p): bool
    {
        return stripos($p['town'] . ' ' . $p['name'], 'rupaidiha') !== false;
    }

    /** Our Rupaidiha parking with a pin (where passengers board for Gujarat), else the border checkpost. */
    private static function rupaidihaTarget(array $places): array
    {
        foreach ($places as $p) {
            if (self::isRupaidiha($p) && in_array($p['kind'], ['parking', 'pickup'], true) && ($c = self::placeCoords($p)) !== null) {
                return $c + ['name' => $p['name'], 'nameNe' => $p['nameNe'], 'id' => $p['id']];
            }
        }
        return self::BORDER + ['nameNe' => 'रुपैडिहा बोर्डर (भारतीय अध्यागमन चेकपोस्ट)', 'id' => 0];
    }

    /** "Name — detail" -> "Name" (pictures keep the short name). */
    private static function short(string $name): string
    {
        return trim((string) preg_split('/\s+[—–]\s+/u', $name)[0]);
    }

    /**
     * Build every picture that can be built (see the header).
     *
     * @return list<string> one line per place, for the office
     */
    public static function buildAll(?int $onlyId = null): array
    {
        $out = [];
        $places = CompanyLocations::all();
        $office = self::nepalOffice();
        $officeAt = $office !== null ? self::placeCoords($office) : null;
        $target = self::rupaidihaTarget($places);
        $road = null;   // one road build serves the office row and the Rupaidiha row
        $roadFor = static function () use (&$road, $officeAt, $target): ?array {
            if ($road === null && $officeAt !== null) {
                $road = self::road($officeAt, $target,
                    ['ne' => 'नेपालगञ्ज कार्यालय', 'en' => 'Nepalgunj office'],
                    ['ne' => self::short($target['nameNe'] !== '' ? $target['nameNe'] : $target['name']), 'en' => self::latin(self::short($target['name']))]);
            }
            return $road;
        };
        foreach ($places as $p) {
            $isOffice = $office !== null && $p['id'] === $office['id'];
            $isTarget = $target['id'] > 0 && $p['id'] === $target['id'];
            if ($onlyId !== null && $onlyId !== $p['id'] && !(($isOffice || $isTarget) && in_array($onlyId, [$office['id'] ?? -1, $target['id']], true))) {
                continue;
            }
            $at = self::placeCoords($p);
            if ($at === null) {
                $out[] = '✍️ ' . $p['name'] . ': no pin in the map link yet - नक्सा link हाल्नुहोस्';
                continue;
            }
            try {
                $names = ['ne' => self::short($p['nameNe'] !== '' ? $p['nameNe'] : $p['name']), 'en' => self::latin(self::short($p['name']))];
                $note = self::noteNe($p['note']);
                $data = [
                    'sat'  => self::closeView($at, $names, $note),
                    'area' => self::areaView($at, $names),
                    'at'   => date('Y-m-d H:i'),
                ];
                if (($isOffice || $isTarget) && $officeAt !== null) {
                    $r = $roadFor();
                    $data += ['route' => $r['route'], 'approach' => $r['approach'], 'km' => $r['km'], 'min' => $r['min'],
                              'to' => $target['name'], 'guide' => $r['guide']];
                }
                self::store($p['id'], $data);
                $out[] = '✅ ' . $p['name'] . ': close + area satellite' . (isset($data['route']) ? ' + road from Nepalgunj office (' . $data['km'] . ' km, Nepali guide)' : '');
            } catch (Throwable $e) {
                $out[] = '⚠️ ' . $p['name'] . ': ' . $e->getMessage();
            }
        }
        return $out;
    }

    /** The Nepali half of a "English / नेपाली" note, or the note when it is all Nepali. */
    private static function noteNe(string $note): string
    {
        foreach (array_reverse(preg_split('#\s+/\s+#u', $note) ?: []) as $part) {
            if (preg_match('/\p{Devanagari}/u', $part)) {
                return trim($part);
            }
        }
        return '';
    }

    /* ------------------------------------------------------------------
     *  The road + Nepali guide
     * ---------------------------------------------------------------- */

    /** Nepali digits. */
    public static function nd(string $s): string
    {
        return strtr($s, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']);
    }

    private static function distNe(float $km): string
    {
        return $km < 1 ? self::nd((string) (int) (round($km * 1000 / 10) * 10)) . ' मि.' : self::nd(number_format($km, 1)) . ' कि.मि.';
    }

    private static function distEn(float $km): string
    {
        return $km < 1 ? ((int) (round($km * 1000 / 10) * 10)) . ' m' : number_format($km, 1) . ' km';
    }

    /** Road names as people say them. */
    private static function streetNe(string $s): string
    {
        static $map = ['Ratna Rajmarg' => 'रत्न राजमार्ग', 'Bus Park Road' => 'बसपार्क रोड', 'Jamunaha Gurudwara Road' => 'जमुनहा गुरुद्वारा रोड'];
        return $map[$s] ?? $s;
    }

    /**
     * Valhalla maneuvers -> Nepali + English steps. A border crossing is
     * added where the road passes the Indian immigration checkpost.
     *
     * @param list<array<string,mixed>> $man
     * @param list<array{0:float,1:float}> $pts
     * @return list<array{ne:string,en:string}>
     */
    public static function guide(array $man, array $pts, string $destNe, string $destEn): array
    {
        $borderAt = null;
        $best = 0.6;   // km
        foreach ($pts as $i => $p) {
            $d = EtaAlerts::haversineKm($p[0], $p[1], self::BORDER['lat'], self::BORDER['lng']);
            if ($d < $best) {
                $best = $d;
                $borderAt = $i;
            }
        }
        $steps = [];
        $borderDone = false;
        $n = count($man);
        for ($i = 0; $i < $n; $i++) {
            $m = $man[$i];
            $type = (int) ($m['type'] ?? 0);
            $streets = array_values(array_filter((array) ($m['street_names'] ?? []), 'is_string'));
            $stEn = $streets !== [] ? implode(' / ', array_slice($streets, 0, 2)) : '';
            $stNe = $streets !== [] ? implode(' / ', array_map([self::class, 'streetNe'], array_slice($streets, 0, 2))) : '';
            $len = (float) ($m['length'] ?? 0);
            if ($borderAt !== null && !$borderDone && (int) ($m['begin_shape_index'] ?? 0) > $borderAt) {
                $steps[] = ['ne' => '🛂 नेपाल–भारत बोर्डर (जमुनहा / रुपैडिहा नाका) — भन्सार र अध्यागमन जाँचपछि भारततर्फ।',
                            'en' => '🛂 Nepal–India border (Jamunaha / Rupaidiha) — customs and immigration check, then into India.'];
                $borderDone = true;
            }
            if ($type === 27) {   // leaving a roundabout: the roundabout step already said it
                continue;
            }
            if (in_array($type, [4, 5, 6], true)) {
                $side = $type === 5 ? ['दायाँ', 'on the right'] : ($type === 6 ? ['बायाँ', 'on the left'] : ['', '']);
                $steps[] = ['ne' => '🅿️ ' . $destNe . ' आइपुग्यो' . ($side[0] !== '' ? ' — ' . $side[0] . 'पट्टि' : '') . '।',
                            'en' => '🅿️ ' . $destEn . ($side[1] !== '' ? ' ' . $side[1] : '') . '.'];
                continue;
            }
            $then = $len > 0.02 ? [' — ' . self::distNe($len) . ' जानुहोस्', ' — go ' . self::distEn($len)] : ['', ''];
            $at = $stNe !== '' ? $stNe . 'मा ' : '';
            $onEn = $stEn !== '' ? ' onto ' . $stEn : '';
            [$ne, $en] = match (true) {
                in_array($type, [1, 2, 3], true) => ['🚩 कार्यालयबाट निस्केर' . ($stNe !== '' ? ' ' . $stNe . ' हुँदै' : '') . ' सुरु गर्नुहोस्', 'Start' . ($stEn !== '' ? ' on ' . $stEn : '')],
                $type === 10 => ['↱ ' . $at . 'दायाँ मोडिनुहोस्', 'Turn right' . $onEn],
                $type === 15 => ['↰ ' . $at . 'बायाँ मोडिनुहोस्', 'Turn left' . $onEn],
                $type === 9 => ['↗ ' . $at . 'अलिकति दायाँ लाग्नुहोस्', 'Bear right' . $onEn],
                $type === 16 => ['↖ ' . $at . 'अलिकति बायाँ लाग्नुहोस्', 'Bear left' . $onEn],
                $type === 11 => ['↱ ' . $at . 'तीखो दायाँ मोडिनुहोस्', 'Sharp right' . $onEn],
                $type === 14 => ['↰ ' . $at . 'तीखो बायाँ मोडिनुहोस्', 'Sharp left' . $onEn],
                in_array($type, [12, 13], true) => ['↩ ' . $at . 'यू-टर्न गर्नुहोस्', 'U-turn' . $onEn],
                $type === 26 => ['⟳ गोलचक्करबाट ' . self::nd((string) max(1, (int) ($m['roundabout_exit_count'] ?? 1))) . ' औं निकासमा निस्कनुहोस्' . ($stNe !== '' ? ' (' . $stNe . ')' : ''),
                                 'Roundabout: exit ' . max(1, (int) ($m['roundabout_exit_count'] ?? 1)) . $onEn],
                in_array($type, [18, 20, 23], true) => ['↗ दायाँपट्टिको बाटो लिनुहोस्' . ($stNe !== '' ? ' (' . $stNe . ')' : ''), 'Keep right' . $onEn],
                in_array($type, [19, 21, 24], true) => ['↖ बायाँपट्टिको बाटो लिनुहोस्' . ($stNe !== '' ? ' (' . $stNe . ')' : ''), 'Keep left' . $onEn],
                $type === 25 => ['⤵ मूल सडकमा मिसिनुहोस्' . ($stNe !== '' ? ' (' . $stNe . ')' : ''), 'Merge' . $onEn],
                default => ['⬆ ' . ($stNe !== '' ? $stNe . 'मै ' : '') . 'सिधा', 'Straight' . ($stEn !== '' ? ' on ' . $stEn : '')],
            };
            // a "straight on the same road" right after the same road adds nothing - fold its km in
            $prev = $steps !== [] ? $steps[count($steps) - 1] : null;
            if ($prev !== null && str_starts_with($ne, '⬆') && isset($prev['_st']) && $prev['_st'] === $stEn && $stEn !== '') {
                $steps[count($steps) - 1]['_km'] += $len;
                continue;
            }
            $steps[] = ['ne' => $ne, 'en' => $en, '_km' => $len, '_st' => $stEn];
        }
        if ($borderAt !== null && !$borderDone) {
            array_splice($steps, max(0, count($steps) - 1), 0, [[
                'ne' => '🛂 नेपाल–भारत बोर्डर (जमुनहा / रुपैडिहा नाका) — भन्सार र अध्यागमन जाँचपछि भारततर्फ।',
                'en' => '🛂 Nepal–India border (Jamunaha / Rupaidiha) — customs and immigration check, then into India.']]);
        }
        return array_map(static function (array $s): array {
            if (isset($s['_km'])) {
                if ($s['_km'] > 0.02) {
                    $s['ne'] .= ' — ' . self::distNe($s['_km']) . ' जानुहोस्।';
                    $s['en'] .= ' — ' . self::distEn($s['_km']) . '.';
                } else {
                    $s['ne'] .= '।';
                    $s['en'] .= '.';
                }
            }
            return ['ne' => $s['ne'], 'en' => $s['en']];
        }, $steps);
    }

    /** Our own Valhalla (public one as fallback) with turn steps. */
    private static function router(array $stops): array
    {
        if (is_callable(self::$router)) {
            return (self::$router)($stops);
        }
        $body = (string) json_encode([
            'locations' => array_map(static fn(array $s): array => ['lat' => round((float) $s['lat'], 6), 'lon' => round((float) $s['lng'], 6), 'type' => 'break'], $stops),
            'costing' => 'bus', 'directions_options' => ['units' => 'kilometers', 'language' => 'en-US'],
        ], JSON_UNESCAPED_SLASHES);
        foreach ([[Settings::getString('nav_engine_url', 'http://127.0.0.1:8002'), 30], ['https://valhalla1.openstreetmap.de', 45]] as $i => [$base, $timeout]) {
            if ($i === 1 && !Settings::getBool('nav_fallback_public', true)) {
                break;
            }
            $ch = curl_init(rtrim($base, '/') . '/route');
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_USERAGENT => 'SHARI-NAV/1.0 (+https://www.shreehariglobal.in)']);
            $raw = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $j = is_string($raw) ? json_decode($raw, true) : null;
            $leg = $j['trip']['legs'][0] ?? null;
            if ($http === 200 && is_array($leg)) {
                return ['ok' => true, 'pts' => RouteGeo::decode((string) ($leg['shape'] ?? '')), 'routerKm' => (float) ($j['trip']['summary']['length'] ?? 0),
                        'seconds' => (float) ($j['trip']['summary']['time'] ?? 0), 'maneuvers' => (array) ($leg['maneuvers'] ?? [])];
            }
        }
        return ['ok' => false, 'pts' => [], 'error' => 'the route engine did not answer'];
    }

    /**
     * The road from A to B: overview with the Nepali guide panel, the last
     * stretch close up, and the steps as text.
     *
     * @param array{lat:float,lng:float} $from
     * @param array{lat:float,lng:float} $to
     * @param array{ne:string,en:string} $fromName
     * @param array{ne:string,en:string} $toName
     * @return array{route:string, approach:string, km:float, min:int, guide:list<array{ne:string,en:string}>, file:string}
     */
    public static function road(array $from, array $to, array $fromName, array $toName): array
    {
        $r = self::router([['lat' => $from['lat'], 'lng' => $from['lng']], ['lat' => $to['lat'], 'lng' => $to['lng']]]);
        if (empty($r['ok']) || count($r['pts'] ?? []) < 2) {
            throw new RuntimeException((string) ($r['error'] ?? 'no road found'));
        }
        $pts = $r['pts'];
        $cum = RouteGeo::cumulativeKm($pts);
        $km = round((float) (($r['routerKm'] ?? 0) > 0 ? $r['routerKm'] : $cum[count($cum) - 1]), 1);
        $min = (int) max(1, round(((float) ($r['seconds'] ?? 0) > 0 ? (float) $r['seconds'] : $km / 30 * 3600) / 60));
        $guide = self::guide((array) ($r['maneuvers'] ?? []), $pts, $toName['ne'], $toName['en']);
        if ($guide === []) {
            $guide = [['ne' => '🚩 ' . $fromName['ne'] . 'बाट सुरु — ' . self::distNe($km) . ' सडकबाट।', 'en' => 'Start at ' . $fromName['en'] . ' — ' . self::distEn($km) . ' by road.'],
                      ['ne' => '🅿️ ' . $toName['ne'] . ' आइपुग्यो।', 'en' => '🅿️ ' . $toName['en'] . '.']];
        }
        $route = self::routePicture($pts, $km, $min, $guide, $fromName, $toName);
        $approach = self::approachPicture($pts, $cum, $toName);
        return ['route' => $route, 'approach' => $approach, 'km' => $km, 'min' => $min, 'guide' => $guide, 'file' => $route];
    }

    /**
     * Kept for old callers / tests: the road picture between two points.
     *
     * @return array{file:string, km:float}
     */
    public static function route(array $from, array $to, string $fromName, string $toName, int $w = 1600, int $h = 1000): array
    {
        $r = self::road($from, $to, ['ne' => $fromName, 'en' => self::latin($fromName)], ['ne' => $toName, 'en' => self::latin($toName)]);
        return ['file' => $r['route'], 'km' => $r['km']];
    }

    /* ------------------------------------------------------------------
     *  Drawing
     * ---------------------------------------------------------------- */

    /** World pixel of a point at a zoom (256 px tiles). @return array{0:float,1:float} */
    public static function px(float $lat, float $lng, int $z): array
    {
        $n = 256 * (2 ** $z);
        $s = sin(deg2rad(max(-85.0, min(85.0, $lat))));
        return [($lng + 180) / 360 * $n, (0.5 - log((1 + $s) / (1 - $s)) / (4 * M_PI)) * $n];
    }

    /**
     * The satellite canvas whose top-left world pixel is ($ox, $oy).
     *
     * @return array{0:\GdImage, 1:float} image + share of tiles that had real imagery
     */
    private static function canvas(int $z, float $ox, float $oy, int $w, int $h): array
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 40, 48, 60));
        $tx0 = (int) floor($ox / 256);
        $ty0 = (int) floor($oy / 256);
        $tx1 = (int) floor(($ox + $w - 1) / 256);
        $ty1 = (int) floor(($oy + $h - 1) / 256);
        $urls = [];
        for ($ty = $ty0; $ty <= $ty1; $ty++) {
            for ($tx = $tx0; $tx <= $tx1; $tx++) {
                $urls[$tx . ':' . $ty] = sprintf(self::TILE_URL, $z, $ty, $tx);
            }
        }
        if (count($urls) > 120) {
            throw new RuntimeException('map too big');
        }
        $ok = 0;
        foreach (self::fetchMany($urls) as $k => $bytes) {
            if ($bytes === null || strlen($bytes) < self::EMPTY_TILE_BYTES) {
                continue;
            }
            $t = @imagecreatefromstring($bytes);
            if ($t === false) {
                continue;
            }
            [$tx, $ty] = array_map('intval', explode(':', $k));
            imagecopy($im, $t, (int) round($tx * 256 - $ox), (int) round($ty * 256 - $oy), 0, 0, 256, 256);
            imagedestroy($t);
            $ok++;
        }
        if ($ok === 0) {
            imagedestroy($im);
            throw new RuntimeException('the satellite pictures did not download - try again later');
        }
        return [$im, $ok / count($urls)];
    }

    /**
     * The sharpest picture of a spot: zoom 19 when Esri has it there, else
     * zoom 18 drawn twice as large and sharpened.
     *
     * @return array{0:\GdImage, 1:int, 2:float, 3:float, 4:int} image, zoom, origin x, origin y, scale
     */
    private static function sharp(float $cx, float $cy, int $zWanted, float $lat, float $lng, int $w, int $h, float $shiftY = 0): array
    {
        if ($zWanted >= 19) {
            [$x19, $y19] = self::px($lat, $lng, 19);
            $ox = $x19 + ($cx) - $w / 2;
            $oy = $y19 + ($cy) - $h / 2 + $shiftY;
            try {
                [$im, $share] = self::canvas(19, $ox, $oy, $w, $h);
                if ($share >= 0.85) {
                    return [$im, 19, $ox, $oy, 1];
                }
                imagedestroy($im);
            } catch (RuntimeException $e) {
                // no zoom-19 imagery at all here - zoom 18 below
            }
        }
        $z = min(18, $zWanted);
        $scale = $zWanted >= 19 ? 2 : 1;
        [$xz, $yz] = self::px($lat, $lng, $z);
        $sw = (int) ceil($w / $scale);
        $sh = (int) ceil($h / $scale);
        $ox = $xz + $cx / $scale - $sw / 2;
        $oy = $yz + $cy / $scale - $sh / 2 + $shiftY / $scale;
        [$small] = self::canvas($z, $ox, $oy, $sw, $sh);
        if ($scale === 1) {
            return [$small, $z, $ox, $oy, 1];
        }
        $im = imagecreatetruecolor($w, $h);
        imagecopyresampled($im, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);
        imagedestroy($small);
        imageconvolution($im, [[0, -1, 0], [-1, 5.4, -1], [0, -1, 0]], 1.4, 0);
        return [$im, $z, $ox, $oy, $scale];
    }

    /** @param array<string,string> $urls @return array<string,?string> */
    private static function fetchMany(array $urls): array
    {
        if (is_callable(self::$fetch)) {
            return array_map(self::$fetch, $urls);
        }
        $mh = curl_multi_init();
        $hs = [];
        foreach ($urls as $k => $u) {
            $ch = curl_init($u);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_USERAGENT => 'SHARI-NAV/1.0 (+https://www.shreehariglobal.in)']);
            curl_multi_add_handle($mh, $ch);
            $hs[$k] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $st === CURLM_OK);
        $out = [];
        foreach ($hs as $k => $ch) {
            $body = curl_multi_getcontent($ch);
            $out[$k] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200 && is_string($body) && $body !== '' ? $body : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $out;
    }

    /** GD's own fonts draw Latin only - keep the ASCII part of a name. */
    public static function latin(string $s): string
    {
        $s = str_replace(['—', '–', '·', '→'], ['-', '-', '|', '->'], $s);
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7E]/', '', $s)));
    }

    private static function font(bool $bold = true): string
    {
        return ROOT_PATH . '/assets/fonts/' . ($bold ? 'Poppins-Bold.ttf' : 'Poppins-Regular.ttf');
    }

    /** Latin text. */
    private static function text($im, float $size, int $x, int $y, string $s, int $col, bool $bold = true): void
    {
        imagettftext($im, $size, 0, $x, $y, $col, self::font($bold), self::latin($s));
    }

    private static function textWidth(float $size, string $s, bool $bold = true): int
    {
        $b = imagettfbbox($size, 0, self::font($bold), self::latin($s));
        return (int) abs($b[2] - $b[0]);
    }

    /** Can this server shape Devanagari right now? */
    private static function canNe(): bool
    {
        static $ok = null;
        return $ok ??= DevShape::gdWidth(20, 'नेपाल') !== null;
    }

    /** Nepali (shaped, faux bold) when possible, else the English text. */
    private static function textNe($im, float $size, int $x, int $y, string $ne, string $en, int $col, bool $bold = true): void
    {
        $ne = self::plainNe($ne);
        if ($ne !== '' && self::canNe() && DevShape::gdText($im, $size, $x, $y, $col, $ne, $bold ? [[0, 0], [1, 0]] : [[0, 0]])) {
            return;
        }
        self::text($im, $size, $x, $y, $en, $col, $bold);
    }

    private static function widthNe(float $size, string $ne, string $en, bool $bold = true): int
    {
        $ne = self::plainNe($ne);
        if ($ne !== '' && self::canNe() && ($w = DevShape::gdWidth($size, $ne)) !== null) {
            return $w + ($bold ? 1 : 0);
        }
        return self::textWidth($size, $en, $bold);
    }

    /** Emoji and arrows have no glyph in the fonts - drop them from picture text. */
    private static function plainNe(string $s): string
    {
        return trim((string) preg_replace('/[\x{2190}-\x{21FF}\x{2300}-\x{23FF}\x{2600}-\x{27FF}\x{2900}-\x{297F}\x{2B00}-\x{2BFF}\x{1F000}-\x{1FAFF}\x{FE0F}]/u', '', $s));
    }

    /** Word-wrap for Nepali (or the English fallback) to $maxW px. @return list<array{0:string,1:string}> */
    private static function wrapNe(float $size, string $ne, string $en, int $maxW): array
    {
        $useNe = self::canNe() && self::plainNe($ne) !== '';
        $words = preg_split('/\s+/u', $useNe ? self::plainNe($ne) : self::latin($en)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            $tw = $useNe ? (int) DevShape::gdWidth($size, $try) : self::textWidth($size, $try, false);
            if ($tw > $maxW && $cur !== '') {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return array_map(static fn(string $l): array => $useNe ? [$l, ''] : ['', $l], $lines);
    }

    /** Title band (Nepali + English) on top, credit band at the bottom. */
    private static function frame($im, int $w, int $h, array $title, string $sub, string $foot = ''): void
    {
        imagealphablending($im, true);
        $band = imagecolorallocatealpha($im, 8, 16, 30, 30);
        $white = imagecolorallocate($im, 255, 255, 255);
        $amber = imagecolorallocate($im, 255, 200, 100);
        imagefilledrectangle($im, 0, 0, $w, 96, $band);
        self::textNe($im, 24, 24, 42, $title['ne'], $title['en'], $white);
        self::text($im, 14, 24, 78, ($title['ne'] !== '' && self::canNe() ? $title['en'] . '  |  ' : '') . $sub, $amber, false);
        $fh = $foot !== '' ? 62 : 28;
        imagefilledrectangle($im, 0, $h - $fh, $w, $h, $band);
        if ($foot !== '') {
            self::textNe($im, 17, 20, $h - 32, $foot, '', $white, false);
        }
        self::text($im, 10, 14, $h - 9, self::CREDIT . '  |  S Hari Global', $white, false);
    }

    private static function pin($im, int $x, int $y, string $letter, array $rgb, int $size = 50): void
    {
        imagesetthickness($im, 1);
        imagefilledellipse($im, $x + 2, $y + 3, $size, $size, imagecolorallocatealpha($im, 0, 0, 0, 80));
        imagefilledellipse($im, $x, $y, $size, $size, imagecolorallocate($im, 255, 255, 255));
        imagefilledellipse($im, $x, $y, $size - 9, $size - 9, imagecolorallocate($im, ...$rgb));
        $white = imagecolorallocate($im, 255, 255, 255);
        self::text($im, 17, $x - (int) (self::textWidth(17, $letter) / 2) - 1, $y + 9, $letter, $white);
    }

    /** A white label under a point (Nepali when possible). */
    private static function label($im, int $x, int $y, array $name, int $w, int $h): void
    {
        $tw = self::widthNe(16, $name['ne'], $name['en']) + 24;
        $lx = max(8, min($w - $tw - 8, $x - (int) ($tw / 2)));
        $ly = $y + 34;
        if ($ly + 40 > $h - 70) {
            $ly = $y - 78;
        }
        imagefilledrectangle($im, $lx, $ly, $lx + $tw, $ly + 38, imagecolorallocatealpha($im, 255, 255, 255, 6));
        imagerectangle($im, $lx, $ly, $lx + $tw, $ly + 38, imagecolorallocate($im, 234, 88, 12));
        self::textNe($im, 16, $lx + 12, $ly + 27, $name['ne'], $name['en'], imagecolorallocate($im, 15, 23, 42));
    }

    private static function ring($im, int $x, int $y, float $lat, int $z, int $scale, int $metres): void
    {
        $mpp = 156543.03392 * cos(deg2rad($lat)) / (2 ** $z) / $scale;
        $r = (int) round($metres / $mpp);
        $c = imagecolorallocatealpha($im, 255, 200, 100, 20);
        for ($t = 0; $t < 3; $t++) {
            imageellipse($im, $x, $y, 2 * $r + $t, 2 * $r + $t, $c);
        }
    }

    /** Thick road line with a white casing and round joins. @param list<array{0:int,1:int}> $xy */
    private static function line($im, array $xy, int $width = 9): void
    {
        foreach ([[$width + 6, [255, 255, 255]], [$width, [29, 78, 216]]] as [$th, $rgb]) {
            imagesetthickness($im, $th);
            $c = imagecolorallocate($im, ...$rgb);
            for ($i = 1, $n = count($xy); $i < $n; $i++) {
                imageline($im, $xy[$i - 1][0], $xy[$i - 1][1], $xy[$i][0], $xy[$i][1], $c);
            }
            foreach ($xy as [$x, $y]) {
                imagefilledellipse($im, $x, $y, $th, $th, $c);
            }
        }
        imagesetthickness($im, 1);
    }

    private static function save($im, string $kind): string
    {
        ob_start();
        imagejpeg($im, null, 88);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        $rel = self::DIR . '/' . $kind . '-' . substr(hash('sha256', $bytes), 0, 16) . '.jpg';
        $abs = ROOT_PATH . '/' . $rel;
        if (!is_dir(dirname($abs))) {
            @mkdir(dirname($abs), 0755, true);
        }
        if (!is_file($abs)) {
            $tmp = $abs . '.tmp';
            if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $abs)) {
                @unlink($tmp);
                throw new RuntimeException('could not save the picture');
            }
        }
        return $rel;
    }

    /**
     * Close view of a place (the yard / gate itself).
     *
     * @param array{lat:float,lng:float} $at
     * @param array{ne:string,en:string}|string $name
     */
    public static function closeView(array $at, array|string $name, string $noteNe = '', int $w = 1200, int $h = 900): string
    {
        $name = is_array($name) ? $name : ['ne' => '', 'en' => $name];
        [$im, $z, $ox, $oy, $scale] = self::sharp(0, 0, 19, $at['lat'], $at['lng'], $w, $h, 10);
        imagealphablending($im, true);
        [$px, $py] = self::px($at['lat'], $at['lng'], $z);
        $x = (int) round(($px - $ox) * $scale);
        $y = (int) round(($py - $oy) * $scale);
        self::ring($im, $x, $y, $at['lat'], $z, $scale, 50);
        self::pin($im, $x, $y, 'P', [234, 88, 12]);
        self::label($im, $x, $y, $name, $w, $h);
        self::frame($im, $w, $h, $name, sprintf('%.6f, %.6f  |  close view, ring = 50 m  |  north is up', $at['lat'], $at['lng']), $noteNe);
        return self::save($im, 'sat');
    }

    /** Kept for old callers / tests. */
    public static function satellite(array $at, string $name, int $z = 19, int $w = 1200, int $h = 900): string
    {
        return self::closeView($at, ['ne' => '', 'en' => $name], '', $w, $h);
    }

    /** The neighbourhood around a place (zoom 17). */
    public static function areaView(array $at, array $name, int $w = 1200, int $h = 900): string
    {
        $z = 17;
        [$cx, $cy] = self::px($at['lat'], $at['lng'], $z);
        $ox = $cx - $w / 2;
        $oy = $cy - $h / 2 + 10;
        [$im] = self::canvas($z, $ox, $oy, $w, $h);
        imagealphablending($im, true);
        $x = (int) round($cx - $ox);
        $y = (int) round($cy - $oy);
        self::ring($im, $x, $y, $at['lat'], $z, 1, 250);
        self::pin($im, $x, $y, 'P', [234, 88, 12]);
        self::label($im, $x, $y, $name, $w, $h);
        self::frame($im, $w, $h, $name, 'Area view, ring = 250 m  |  north is up', 'वरिपरिको इलाका — मूल सडक र दिशा हेर्न।');
        return self::save($im, 'area');
    }

    /** Whole road on satellite + Nepali guide panel on the right. */
    private static function routePicture(array $pts, float $km, int $min, array $guide, array $fromName, array $toName, int $w = 1600, int $h = 1150): string
    {
        $mapW = 1040;
        $lats = array_column($pts, 0);
        $lngs = array_column($pts, 1);
        $pad = 70;
        for ($z = 17; $z > 8; $z--) {
            [$x0, $y1] = self::px(min($lats), min($lngs), $z);
            [$x1, $y0] = self::px(max($lats), max($lngs), $z);
            if ($x1 - $x0 <= $mapW - 2 * $pad && $y1 - $y0 <= $h - 96 - 50) {
                break;
            }
        }
        $ox = ($x0 + $x1) / 2 - $mapW / 2;
        $oy = ($y0 + $y1) / 2 - $h / 2 - 30;
        [$map] = self::canvas($z, $ox, $oy, $mapW, $h);
        $im = imagecreatetruecolor($w, $h);
        imagecopy($im, $map, 0, 0, 0, 0, $mapW, $h);
        imagedestroy($map);
        imagealphablending($im, true);
        $xy = array_map(static function (array $p) use ($z, $ox, $oy): array {
            [$x, $y] = self::px((float) $p[0], (float) $p[1], $z);
            return [(int) round($x - $ox), (int) round($y - $oy)];
        }, $pts);
        self::line($im, $xy, 9);
        [$ax, $ay] = $xy[0];
        [$bx, $by] = $xy[count($xy) - 1];
        self::pin($im, $ax, $ay, 'A', [22, 163, 74]);
        self::pin($im, $bx, $by, 'B', [234, 88, 12]);
        self::label($im, $ax, $ay, ['ne' => 'A  ' . $fromName['ne'], 'en' => 'A  ' . $fromName['en']], $mapW, $h);
        self::label($im, $bx, $by, ['ne' => 'B  ' . $toName['ne'], 'en' => 'B  ' . $toName['en']], $mapW, $h);
        // border marker where the road passes the checkpost
        foreach ($pts as $p) {
            if (EtaAlerts::haversineKm($p[0], $p[1], self::BORDER['lat'], self::BORDER['lng']) < 0.6) {
                [$bxx, $byy] = self::px(self::BORDER['lat'], self::BORDER['lng'], $z);
                self::pin($im, (int) round($bxx - $ox), (int) round($byy - $oy), '!', [220, 38, 38], 40);
                break;
            }
        }
        // guide panel
        $panel = imagecolorallocate($im, 250, 250, 247);
        $ink = imagecolorallocate($im, 15, 23, 42);
        $muted = imagecolorallocate($im, 71, 85, 105);
        $brand = imagecolorallocate($im, 234, 88, 12);
        imagefilledrectangle($im, $mapW, 0, $w, $h, $panel);
        imagefilledrectangle($im, $mapW, 0, $mapW + 6, $h, $brand);
        $px = $mapW + 30;
        $pw = $w - $px - 26;
        self::textNe($im, 23, $px, 50, 'बाटो — चरण चरण', 'Directions, step by step', $ink);
        $sumNe = self::nd(number_format($km, 1)) . ' कि.मि. सडकबाट  ·  करिब ' . self::nd((string) $min) . ' मिनेट';
        self::textNe($im, 17, $px, 88, $sumNe, number_format($km, 1) . ' km by road  |  about ' . $min . ' min', $brand, false);
        $y = 132;
        $num = 1;
        foreach ($guide as $step) {
            $lines = self::wrapNe(16, $step['ne'], $step['en'], $pw - 46);
            $need = count($lines) * 27 + 16;
            if ($y + $need > $h - 70) {
                self::text($im, 13, $px, $y + 10, '... more steps on the website / app', $muted, false);
                break;
            }
            imagefilledellipse($im, $px + 14, $y + 8, 28, 28, $brand);
            $n = (string) $num;
            self::text($im, 13, $px + 14 - (int) (self::textWidth(13, $n) / 2), $y + 14, $n, imagecolorallocate($im, 255, 255, 255));
            $ly = $y + 15;
            foreach ($lines as [$ne, $en]) {
                if ($ne !== '') {
                    DevShape::gdText($im, 16, $px + 40, $ly, $ink, $ne);
                } else {
                    self::text($im, 14, $px + 40, $ly, $en, $ink, false);
                }
                $ly += 27;
            }
            $y = $ly + 6;
            $num++;
        }
        imagealphablending($im, true);
        $band = imagecolorallocatealpha($im, 8, 16, 30, 30);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledrectangle($im, 0, 0, $mapW, 96, $band);
        self::textNe($im, 24, 24, 42, $fromName['ne'] . 'देखि ' . $toName['ne'] . 'सम्म', $fromName['en'] . ' -> ' . $toName['en'], $white);
        self::text($im, 14, 24, 78, $fromName['en'] . ' -> ' . $toName['en'] . '  |  blue line = the road  |  north is up', imagecolorallocate($im, 255, 200, 100), false);
        imagefilledrectangle($im, 0, $h - 28, $mapW, $h, $band);
        self::text($im, 10, 14, $h - 9, self::CREDIT . '  |  S Hari Global', $white, false);
        return self::save($im, 'route');
    }

    /** The last ~700 m before the destination, close up, with the road. */
    private static function approachPicture(array $pts, array $cum, array $toName, int $w = 1200, int $h = 900): string
    {
        $total = $cum[count($cum) - 1];
        $tail = array_values(array_filter($pts, static fn($p, $i) => $total - $cum[$i] <= 0.7, ARRAY_FILTER_USE_BOTH));
        if (count($tail) < 2) {
            $tail = array_slice($pts, -2);
        }
        $end = $tail[count($tail) - 1];
        $lats = array_column($tail, 0);
        $lngs = array_column($tail, 1);
        $midLat = (min($lats) + max($lats)) / 2;
        $midLng = (min($lngs) + max($lngs)) / 2;
        // zoom that fits the tail (max 19)
        $zWanted = 19;
        for (; $zWanted > 15; $zWanted--) {
            [$x0, $y1] = self::px(min($lats), min($lngs), $zWanted);
            [$x1, $y0] = self::px(max($lats), max($lngs), $zWanted);
            if ($x1 - $x0 <= $w - 200 && $y1 - $y0 <= $h - 300) {
                break;
            }
        }
        [$im, $z, $ox, $oy, $scale] = self::sharp(0, 0, $zWanted, $midLat, $midLng, $w, $h, 0);
        imagealphablending($im, true);
        $xy = array_map(static function (array $p) use ($z, $ox, $oy, $scale): array {
            [$x, $y] = self::px((float) $p[0], (float) $p[1], $z);
            return [(int) round(($x - $ox) * $scale), (int) round(($y - $oy) * $scale)];
        }, $tail);
        self::line($im, $xy, 11);
        [$bx, $by] = $xy[count($xy) - 1];
        self::pin($im, $bx, $by, 'B', [234, 88, 12]);
        self::label($im, $bx, $by, $toName, $w, $h);
        self::frame($im, $w, $h, ['ne' => 'पुग्ने ठाउँ नजिकबाट — ' . $toName['ne'], 'en' => 'Arriving: ' . $toName['en']],
            'last ~700 m, close up  |  north is up', 'नीलो लाइन = अन्तिम बाटो, B = हाम्रो पार्किङ।');
        return self::save($im, 'approach');
    }

    /* ------------------------------------------------------------------
     *  On the page
     * ---------------------------------------------------------------- */

    /** A saved picture path that is really ours and on disk ('' otherwise). */
    public static function pic(array $lm, string $key): string
    {
        $f = (string) ($lm[$key] ?? '');
        return $f !== '' && preg_match('#^uploads/locations/(sat|area|route|approach)-[0-9a-f]{16}\.jpg$#', $f) === 1 && is_file(ROOT_PATH . '/' . $f) ? $f : '';
    }

    /**
     * The pictures (road, last stretch, close, area) with Save links, and
     * the Nepali step-by-step guide, for /offices and admin/locations.php.
     */
    public static function html(array $lm, string $lang = 'ne'): string
    {
        $e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $en = $lang === 'en';
        $caps = [
            'sat'      => $en ? 'Close view (satellite)' : 'नजिकबाट (स्याटेलाइट)',
            'area'     => $en ? 'Area around it' : 'वरिपरिको इलाका',
            'route'    => ($en ? 'Road from our Nepalgunj office' : 'नेपालगञ्ज कार्यालयबाट बाटो')
                          . (isset($lm['km']) ? ' · ' . ($en ? $lm['km'] . ' km' : self::nd((string) $lm['km']) . ' कि.मि.') : '')
                          . (isset($lm['min']) ? ' · ' . ($en ? '~' . $lm['min'] . ' min' : 'करिब ' . self::nd((string) $lm['min']) . ' मिनेट') : ''),
            'approach' => $en ? 'Arriving (last 700 m)' : 'पुग्ने बेला (अन्तिम ७०० मि.)',
        ];
        $save = $en ? 'Save (opens offline)' : 'सेभ गर्नुहोस् (नेट बिना खुल्छ)';
        $out = '';
        foreach (['route', 'approach', 'sat', 'area'] as $k) {
            $f = self::pic($lm, $k);
            if ($f === '') {
                continue;
            }
            $out .= '<figure class="lm-fig"><a href="/' . $e($f) . '" target="_blank" rel="noopener"><img src="/' . $e($f) . '" alt="' . $e($caps[$k]) . '" loading="lazy" decoding="async"></a>'
                . '<figcaption><span>🛰️ ' . $e($caps[$k]) . '</span><a href="/' . $e($f) . '" download>⬇️ ' . $e($save) . '</a></figcaption></figure>';
        }
        $guide = is_array($lm['guide'] ?? null) ? $lm['guide'] : [];
        if ($guide !== []) {
            // the guide is always Nepali (owner); an English page adds the English line under it
            $out .= '<details class="lm-guide" open><summary>🧭 बाटो — चरण चरण (नेपालगञ्ज कार्यालयबाट)' . ($en ? ' · Directions, step by step' : '') . '</summary><ol>';
            foreach ($guide as $s) {
                $out .= '<li>' . $e($s['ne'] ?? '') . ($en && ($s['en'] ?? '') !== '' ? '<br><small style="opacity:.75">' . $e($s['en']) . '</small>' : '') . '</li>';
            }
            $out .= '</ol></details>';
        }
        return $out === '' ? '' : '<div class="lm-box">' . $out . '</div>';
    }

    /** Styles for html() (both pages). */
    public static function css(): string
    {
        return '<style>.lm-box{display:grid;gap:12px;margin:0 0 12px}.lm-fig{margin:0}.lm-fig img{width:100%;height:auto;border-radius:12px;display:block}'
            . '.lm-fig figcaption{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:13px;margin-top:4px}.lm-fig figcaption a{min-height:32px;display:inline-flex;align-items:center}'
            . '.lm-guide{border:1px solid rgba(127,127,127,.3);border-radius:12px;padding:10px 12px}.lm-guide summary{cursor:pointer;font-weight:700;min-height:32px}'
            . '.lm-guide ol{margin:8px 0 0;padding-left:22px;display:grid;gap:6px;font-size:15px;line-height:1.5}</style>';
    }
}
