<?php
/**
 * =====================================================================
 *  LocationMaps — saved satellite pictures of our places, and the road
 *  picture from the Nepalgunj office to Rupaidiha (21 Nov 2026).
 *
 *  Owner: "ye sab ko satellite image nikalakar Nepalgunj office bata
 *  direct hamro parking samma map herna milos - offline ma; saath saathai
 *  NPJ bata Rupaidiha samma herna ni milos."
 *
 *  A picture is built ONCE on our server and saved as a plain JPG under
 *  uploads/locations/ (file name = content hash, so it never changes under
 *  the same URL). A phone that opened it once keeps it (sw.js 'shg-places'
 *  cache) and it can be downloaded / sent on WhatsApp - no signal needed
 *  at the counter or on the road.
 *
 *    satellite(place)  the place from above (Esri World Imagery, zoom 18)
 *                      with our pin and its name.
 *    route(from, to)   the road (our own Valhalla engine, public one as a
 *                      fallback - RouteGeo::valhalla) drawn on satellite,
 *                      with A / B pins, km and the names.
 *
 *  Never invents a coordinate: the place's own Google Maps link gives the
 *  pin (q=, @, !3d!4d, or a maps.app.goo.gl link followed to its long
 *  form). Rupaidiha with no pin yet = the public "Immigration Checkpost
 *  Rupaidiha" point from OpenStreetMap (way 586178937), named as such.
 *
 *  Saved in settings 'locmap_<id>' = {sat, route, km, to, at}.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/companylocations.php';
require_once __DIR__ . '/routegeo.php';

final class LocationMaps
{
    public const TILE_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/%d/%d/%d';
    public const CREDIT   = 'Imagery: Esri, Maxar, Earthstar Geographics | Roads: OpenStreetMap';
    /** Public point used for Rupaidiha until our own parking has a pin (OSM way 586178937). */
    public const BORDER = ['lat' => 28.0174538, 'lng' => 81.5990318, 'name' => 'Rupaidiha border (Indian immigration checkpost)'];
    public const DIR    = 'uploads/locations';

    /** Test hook: fn(string $url): ?string (tile bytes) instead of the network. */
    public static $fetch = null;
    /** Test hook: fn(array $stops): array like RouteGeo::valhalla. */
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

    /** @return array{sat?:string, route?:string, km?:float, to?:string, at?:string} */
    public static function saved(int $id): array
    {
        $j = json_decode(Settings::getString('locmap_' . $id, ''), true);
        return is_array($j) ? $j : [];
    }

    private static function store(int $id, array $data): void
    {
        Settings::set('locmap_' . $id, (string) json_encode($data + self::saved($id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'string', 'locations');
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

    /**
     * Build every picture that can be built: a satellite view of each place
     * with a pin, and the road from the Nepalgunj office to each Rupaidiha
     * place with a pin (or to the border checkpost while none has one).
     *
     * @return list<string> one line per picture, for the office
     */
    public static function buildAll(?int $onlyId = null): array
    {
        $out = [];
        $places = CompanyLocations::all();
        $office = self::nepalOffice();
        $officeAt = $office !== null ? self::placeCoords($office) : null;
        foreach ($places as $p) {
            $at = self::placeCoords($p);
            if ($onlyId !== null && $onlyId !== $p['id'] && !($office !== null && $onlyId === $office['id'])) {
                continue;
            }
            if ($at === null) {
                $out[] = '✍️ ' . $p['name'] . ': no pin in the map link yet - नक्सा link हाल्नुहोस्';
                continue;
            }
            try {
                $sat = self::satellite($at, self::latin($p['name']));
                $data = ['sat' => $sat, 'at' => date('Y-m-d H:i')];
                if ($officeAt !== null && self::isRupaidiha($p)) {
                    $r = self::route($officeAt, $at, 'Nepalgunj office', self::latin($p['name']));
                    $data += ['route' => $r['file'], 'km' => $r['km'], 'to' => $p['name']];
                }
                self::store($p['id'], $data);
                $out[] = '✅ ' . $p['name'] . ': satellite' . (isset($data['route']) ? ' + road from Nepalgunj office (' . $data['km'] . ' km)' : '');
            } catch (Throwable $e) {
                $out[] = '⚠️ ' . $p['name'] . ': ' . $e->getMessage();
            }
        }
        /* The office row carries "Nepalgunj office -> Rupaidiha": to our own
           Rupaidiha parking when it has a pin, else to the border checkpost. */
        $onlyPlace = $onlyId !== null ? CompanyLocations::get($onlyId) : null;
        if ($office !== null && $officeAt !== null
            && ($onlyId === null || $onlyId === $office['id'] || ($onlyPlace !== null && self::isRupaidiha($onlyPlace)))) {
            $target = null;
            foreach ($places as $p) {
                if (self::isRupaidiha($p) && $p['kind'] === 'parking' && ($c = self::placeCoords($p)) !== null) {
                    $target = $c + ['name' => $p['name']];
                    break;
                }
            }
            $target ??= self::BORDER;
            try {
                $r = self::route($officeAt, $target, 'Nepalgunj office', self::latin($target['name']));
                self::store($office['id'], ['route' => $r['file'], 'km' => $r['km'], 'to' => $target['name'], 'at' => date('Y-m-d H:i')]);
                $out[] = '✅ Nepalgunj office → ' . $target['name'] . ': ' . $r['km'] . ' km';
            } catch (Throwable $e) {
                $out[] = '⚠️ Nepalgunj office → Rupaidiha: ' . $e->getMessage();
            }
        }
        return $out;
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

    /** @return \GdImage the satellite canvas whose top-left world pixel is ($ox, $oy) */
    private static function canvas(int $z, float $ox, float $oy, int $w, int $h)
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
        if (count($urls) > 80) {
            throw new RuntimeException('map too big');
        }
        $got = self::fetchMany($urls);
        $ok = 0;
        foreach ($got as $k => $bytes) {
            [$tx, $ty] = array_map('intval', explode(':', $k));
            $t = $bytes !== null ? @imagecreatefromstring($bytes) : false;
            if ($t === false) {
                continue;
            }
            imagecopy($im, $t, (int) round($tx * 256 - $ox), (int) round($ty * 256 - $oy), 0, 0, 256, 256);
            imagedestroy($t);
            $ok++;
        }
        if ($ok === 0) {
            imagedestroy($im);
            throw new RuntimeException('the satellite pictures did not download - try again later');
        }
        return $im;
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

    /** GD draws Latin only (no Devanagari shaping) - keep the ASCII part of a name. */
    public static function latin(string $s): string
    {
        $s = str_replace(['—', '–', '·', '→'], ['-', '-', '|', '->'], $s);
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7E]/', '', $s)));
    }

    private static function font(bool $bold = true): string
    {
        return ROOT_PATH . '/assets/fonts/' . ($bold ? 'Poppins-Bold.ttf' : 'Poppins-Regular.ttf');
    }

    private static function text($im, float $size, int $x, int $y, string $s, int $col, bool $bold = true): void
    {
        $f = self::font($bold);
        if (!is_file($f)) {
            $f = self::font(true);
        }
        imagettftext($im, $size, 0, $x, $y, $col, $f, $s);
    }

    private static function textWidth(float $size, string $s, bool $bold = true): int
    {
        $f = is_file(self::font($bold)) ? self::font($bold) : self::font(true);
        $b = imagettfbbox($size, 0, $f, $s);
        return (int) abs($b[2] - $b[0]);
    }

    /** A dark band with a title (top) and the credit (bottom). */
    private static function frame($im, int $w, int $h, string $title, string $sub): void
    {
        imagealphablending($im, true);
        $band = imagecolorallocatealpha($im, 10, 20, 35, 38);
        imagefilledrectangle($im, 0, 0, $w, 78, $band);
        $white = imagecolorallocate($im, 255, 255, 255);
        $amber = imagecolorallocate($im, 255, 196, 92);
        self::text($im, 21, 22, 36, mb_strimwidth($title, 0, 70, '…'), $white);
        self::text($im, 13, 22, 63, mb_strimwidth($sub, 0, 110, '…'), $amber, false);
        imagefilledrectangle($im, 0, $h - 26, $w, $h, $band);
        self::text($im, 10, 12, $h - 9, self::CREDIT . '  |  S Hari Global', $white, false);
    }

    private static function pin($im, int $x, int $y, string $letter, array $rgb): void
    {
        imagesetthickness($im, 1);
        $shadow = imagecolorallocatealpha($im, 0, 0, 0, 80);
        imagefilledellipse($im, $x + 2, $y + 3, 46, 46, $shadow);
        imagefilledellipse($im, $x, $y, 46, 46, imagecolorallocate($im, 255, 255, 255));
        imagefilledellipse($im, $x, $y, 38, 38, imagecolorallocate($im, ...$rgb));
        $white = imagecolorallocate($im, 255, 255, 255);
        self::text($im, 16, $x - (int) (self::textWidth(16, $letter) / 2) - 1, $y + 8, $letter, $white);
    }

    private static function label($im, int $x, int $y, string $s, int $w): void
    {
        $tw = self::textWidth(13, $s) + 20;
        $lx = max(8, min($w - $tw - 8, $x - (int) ($tw / 2)));
        $ly = $y + 30;
        imagefilledrectangle($im, $lx, $ly, $lx + $tw, $ly + 28, imagecolorallocatealpha($im, 255, 255, 255, 10));
        self::text($im, 13, $lx + 10, $ly + 20, $s, imagecolorallocate($im, 15, 23, 42));
    }

    private static function save($im, string $kind): string
    {
        ob_start();
        imagejpeg($im, null, 84);
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

    /** @param array{lat:float,lng:float} $at */
    public static function satellite(array $at, string $name, int $z = 18, int $w = 1100, int $h = 760): string
    {
        [$cx, $cy] = self::px($at['lat'], $at['lng'], $z);
        $ox = $cx - $w / 2;
        $oy = $cy - $h / 2 + 20;
        $im = self::canvas($z, $ox, $oy, $w, $h);
        imagealphablending($im, true);
        $x = (int) round($cx - $ox);
        $y = (int) round($cy - $oy);
        /* a 100 m ring helps the eye judge the size of the yard */
        $mpp = 156543.03392 * cos(deg2rad($at['lat'])) / (2 ** $z);
        $r = (int) round(100 / $mpp);
        imagesetthickness($im, 3);
        imageellipse($im, $x, $y, 2 * $r, 2 * $r, imagecolorallocatealpha($im, 255, 196, 92, 30));
        self::pin($im, $x, $y, 'P', [234, 88, 12]);
        self::label($im, $x, $y, $name, $w);
        self::frame($im, $w, $h, $name, sprintf('%.6f, %.6f  |  ring = 100 m  |  north is up', $at['lat'], $at['lng']));
        return self::save($im, 'sat');
    }

    /**
     * @param array{lat:float,lng:float} $from
     * @param array{lat:float,lng:float} $to
     * @return array{file:string, km:float}
     */
    public static function route(array $from, array $to, string $fromName, string $toName, int $w = 1200, int $h = 900): array
    {
        $stops = [['lat' => $from['lat'], 'lng' => $from['lng']], ['lat' => $to['lat'], 'lng' => $to['lng']]];
        $r = is_callable(self::$router) ? (self::$router)($stops) : RouteGeo::valhalla($stops);
        if (empty($r['ok']) || count($r['pts'] ?? []) < 2) {
            throw new RuntimeException((string) ($r['error'] ?? 'no road found'));
        }
        $pts = $r['pts'];   // [[lat, lng], ...]
        $cum = RouteGeo::cumulativeKm($pts);
        $km = ($r['routerKm'] ?? null) !== null ? round((float) $r['routerKm'], 1) : round((float) $cum[count($cum) - 1], 1);
        $lats = array_column($pts, 0);
        $lngs = array_column($pts, 1);
        $pad = 90;
        $z = 18;
        for (; $z > 8; $z--) {
            [$x0, $y1] = self::px(min($lats), min($lngs), $z);
            [$x1, $y0] = self::px(max($lats), max($lngs), $z);
            if ($x1 - $x0 <= $w - 2 * $pad && $y1 - $y0 <= $h - 2 * $pad - 80) {
                break;
            }
        }
        $ox = ($x0 + $x1) / 2 - $w / 2;
        $oy = ($y0 + $y1) / 2 - $h / 2 - 20;
        $im = self::canvas($z, $ox, $oy, $w, $h);
        imagealphablending($im, true);
        $xy = array_map(static function (array $p) use ($z, $ox, $oy): array {
            [$x, $y] = self::px((float) $p[0], (float) $p[1], $z);
            return [(int) round($x - $ox), (int) round($y - $oy)];
        }, $pts);
        foreach ([[12, [255, 255, 255]], [7, [37, 99, 235]]] as [$th, $rgb]) {
            imagesetthickness($im, $th);
            $c = imagecolorallocate($im, ...$rgb);
            for ($i = 1, $n = count($xy); $i < $n; $i++) {
                imageline($im, $xy[$i - 1][0], $xy[$i - 1][1], $xy[$i][0], $xy[$i][1], $c);
            }
            foreach ($xy as [$x, $y]) {   // round joins
                imagefilledellipse($im, $x, $y, $th, $th, $c);
            }
        }
        imagesetthickness($im, 1);
        [$ax, $ay] = $xy[0];
        [$bx, $by] = $xy[count($xy) - 1];
        self::pin($im, $ax, $ay, 'A', [22, 163, 74]);
        self::pin($im, $bx, $by, 'B', [234, 88, 12]);
        self::label($im, $ax, $ay, 'A  ' . $fromName, $w);
        self::label($im, $bx, $by, 'B  ' . $toName, $w);
        self::frame($im, $w, $h, $fromName . '  ->  ' . $toName, 'By road ' . number_format($km, 1) . ' km  |  blue line = the road  |  north is up');
        return ['file' => self::save($im, 'route'), 'km' => $km];
    }
}
