<?php
/**
 * =====================================================================
 *  AiChart — a report's graph as a PNG, drawn on this server (24 Sep 2026).
 *
 *  Owner ask: "graph, image, report — WhatsApp bata pani". The website
 *  draws a report's `chart` block live with Chart.js; WhatsApp cannot run
 *  a script, so the same block is painted here with GD — the library the
 *  PNG ticket and the bus chalan already draw with — and sent as an
 *  image. No CDN, no headless browser, no third party: one PNG under
 *  uploads/ai-charts/<date>/<random>.png, served by nginx like any other
 *  upload and swept by cron/rotate.php after ai_chart_keep_days.
 *
 *  The chart block is deliberately small and the same on every channel:
 *
 *      [
 *        'type'   => 'bar' | 'line' | 'doughnut',
 *        'title'  => 'Sales · this week · by day',
 *        'labels' => ['01 Sep', '02 Sep', ...],
 *        'series' => [ ['name' => 'Revenue ₹', 'data' => [..], 'format' => 'money'], ... ],
 *        'format' => 'money' | 'count' | 'percent',
 *        'max'    => 100   (optional fixed axis top)
 *      ]
 *
 *  Numbers only; a label is drawn as text, never interpreted. The file
 *  name is 16 random bytes, so a link is not guessable, and the picture
 *  carries nothing a report screen does not already show the same role.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiChart
{
    private const W = 1200;
    private const H = 700;

    /** Brand colours measured from assets/img/logo.png (see views.css, palette "logo"). */
    private const NAVY   = [0x0C, 0x30, 0x6C];
    private const ROYAL  = [0x00, 0x54, 0xA8];
    private const ORANGE = [0xF0, 0x78, 0x00];
    private const GOLD   = [0xFF, 0xB7, 0x03];
    private const INK    = [0x16, 0x23, 0x3C];
    private const MUTED  = [0x6B, 0x76, 0x88];
    private const LINE   = [0xE2, 0xE9, 0xF4];
    private const SERIES = [[0x00, 0x54, 0xA8], [0xF0, 0x78, 0x00], [0x13, 0x88, 0x08], [0xDC, 0x14, 0x3C], [0x5B, 0x4F, 0xA8], [0xFF, 0xB7, 0x03]];

    /** Is the server able to draw at all (GD + FreeType + the shipped face)? */
    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor')
            && function_exists('imagettftext')
            && is_file(self::font());
    }

    /**
     * Draw one chart block to a PNG and return [absolute path, public URL],
     * or null when the block is empty or the server cannot draw.
     *
     * @param array<string,mixed> $spec
     * @return array{path: string, url: string}|null
     */
    public static function png(array $spec): ?array
    {
        if (!self::available() || !self::valid($spec)) {
            return null;
        }

        $dir = rtrim(UPLOAD_PATH, '/\\') . '/ai-charts/' . date('Y-m-d');
        if (!ensureDir($dir)) {
            return null;
        }
        $name = bin2hex(random_bytes(16)) . '.png';
        $path = $dir . '/' . $name;

        $im = self::draw($spec);
        if ($im === null) {
            return null;
        }
        $ok = imagepng($im, $path, 6);
        imagedestroy($im);
        if (!$ok || !is_file($path)) {
            return null;
        }
        @chmod($path, 0644);

        return ['path' => $path, 'url' => appUrl('uploads/ai-charts/' . date('Y-m-d') . '/' . $name)];
    }

    /**
     * Draw into memory and return the PNG bytes (for tests and for an
     * inline data: URL). Null when it cannot be drawn.
     */
    public static function pngBytes(array $spec): ?string
    {
        if (!self::available() || !self::valid($spec)) {
            return null;
        }
        $im = self::draw($spec);
        if ($im === null) {
            return null;
        }
        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);
        $bytes = (string) ob_get_clean();

        return $bytes !== '' ? $bytes : null;
    }

    /** A block the renderer can draw: at least one label and one numeric series. */
    public static function valid(array $spec): bool
    {
        $labels = $spec['labels'] ?? null;
        $series = $spec['series'] ?? null;
        if (!is_array($labels) || $labels === [] || !is_array($series) || $series === []) {
            return false;
        }
        foreach ($series as $s) {
            if (!is_array($s) || !is_array($s['data'] ?? null) || ($s['data'] ?? []) === []) {
                return false;
            }
        }
        return true;
    }

    /** Remove chart PNGs older than $days (cron/rotate.php). Returns files removed. */
    public static function sweep(int $days): int
    {
        $root = rtrim(UPLOAD_PATH, '/\\') . '/ai-charts';
        if (!is_dir($root)) {
            return 0;
        }
        $cut = time() - max(1, $days) * 86400;
        $removed = 0;
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dayDir) {
            foreach (glob($dayDir . '/*.png') ?: [] as $f) {
                if ((int) @filemtime($f) < $cut && @unlink($f)) {
                    $removed++;
                }
            }
            if ((glob($dayDir . '/*') ?: []) === []) {
                @rmdir($dayDir);
            }
        }
        return $removed;
    }

    /* ----------------------------------------------------------------- */

    /** @return resource|GdImage|null */
    private static function draw(array $spec)
    {
        $W = self::W;
        $H = self::H;
        $im = imagecreatetruecolor($W, $H);
        if ($im === false) {
            return null;
        }
        imageantialias($im, true);

        $col = static fn(array $rgb) => imagecolorallocate($im, $rgb[0], $rgb[1], $rgb[2]);
        $white = $col([255, 255, 255]);
        $ink   = $col(self::INK);
        $muted = $col(self::MUTED);
        $line  = $col(self::LINE);
        $navy  = $col(self::NAVY);
        $orange = $col(self::ORANGE);

        imagefilledrectangle($im, 0, 0, $W, $H, $white);

        // Brand band: navy → royal with the orange arrow stripe of the logo.
        for ($x = 0; $x < $W; $x++) {
            $t = $x / $W;
            $c = imagecolorallocate($im,
                (int) round(self::NAVY[0] + (self::ROYAL[0] - self::NAVY[0]) * $t),
                (int) round(self::NAVY[1] + (self::ROYAL[1] - self::NAVY[1]) * $t),
                (int) round(self::NAVY[2] + (self::ROYAL[2] - self::NAVY[2]) * $t));
            imageline($im, $x, 0, $x, 78, $c);
        }
        imagefilledrectangle($im, 0, 78, $W, 84, $orange);

        $title   = self::fit(22, (string) ($spec['title'] ?? 'Report'), $W - 60);
        // Drawable without a database (tests, a broken connection): the
        // company name is a caption, not a fact worth failing the picture for.
        $company = defined('APP_NAME') ? APP_NAME : 'S Hari Global Pvt Ltd';
        try {
            if (class_exists('Settings')) {
                $company = Settings::getString('company_name', $company);
            }
        } catch (Throwable $e) {
            // keep the constant
        }
        self::text($im, 22, 30, 48, $white, $title, true);
        self::text($im, 13, 30, 70, $col([0xCF, 0xE0, 0xF8]), $company . ' · ' . date('d M Y, H:i') . ' · SHG Sahayak');

        $type   = (string) ($spec['type'] ?? 'bar');
        $labels = array_values(array_map('strval', (array) $spec['labels']));
        $series = array_values((array) $spec['series']);
        $format = (string) ($spec['format'] ?? 'count');

        if ($type === 'doughnut') {
            self::doughnut($im, $labels, $series[0], $format);
        } else {
            self::axes($im, $type, $labels, $series, $format, isset($spec['max']) ? (float) $spec['max'] : null);
        }

        // Legend
        $lx = 30;
        $ly = $H - 26;
        foreach ($series as $i => $s) {
            $c = $col(self::SERIES[$i % count(self::SERIES)]);
            imagefilledrectangle($im, $lx, $ly - 12, $lx + 16, $ly + 2, $c);
            $name = (string) ($s['name'] ?? ('Series ' . ($i + 1)));
            self::text($im, 13, $lx + 24, $ly, $ink, $name);
            $lx += 24 + self::width(13, $name) + 28;
        }
        self::text($im, 11, $W - 30 - self::width(11, 'shreehariglobal.in'), $H - 22, $muted, 'shreehariglobal.in');

        return $im;
    }

    /** Bars or lines on a labelled axis. */
    private static function axes($im, string $type, array $labels, array $series, string $format, ?float $fixedMax): void
    {
        $W = self::W;
        $H = self::H;
        $ink   = imagecolorallocate($im, ...self::INK);
        $muted = imagecolorallocate($im, ...self::MUTED);
        $line  = imagecolorallocate($im, ...self::LINE);

        $left = 110;
        $right = $W - 40;
        $top = 120;
        $bottom = $H - 110;
        $n = count($labels);

        // Two scales when a series asks for its own axis (e.g. ₹ and tickets).
        $primary = [];
        $secondary = [];
        foreach ($series as $i => $s) {
            if (($s['axis'] ?? '') === 'y2') {
                $secondary[] = $i;
            } else {
                $primary[] = $i;
            }
        }
        if ($primary === []) {
            $primary = $secondary;
            $secondary = [];
        }
        $maxOf = static function (array $idx) use ($series): float {
            $m = 0.0;
            foreach ($idx as $i) {
                foreach ((array) $series[$i]['data'] as $v) {
                    $m = max($m, (float) $v);
                }
            }
            return $m;
        };
        $max1 = $fixedMax ?? self::niceMax($maxOf($primary));
        $max2 = $secondary !== [] ? self::niceMax($maxOf($secondary)) : 0.0;
        if ($secondary !== []) {
            $right = $W - 90;
        }

        // Grid + left axis labels
        $fmt1 = (string) ($series[$primary[0]]['format'] ?? $format);
        for ($g = 0; $g <= 5; $g++) {
            $y = (int) round($bottom - ($bottom - $top) * $g / 5);
            imageline($im, $left, $y, $right, $y, $line);
            $lab = self::fmt($max1 * $g / 5, $fmt1);
            self::text($im, 12, $left - 12 - self::width(12, $lab), $y + 5, $muted, $lab);
            if ($secondary !== []) {
                $lab2 = self::fmt($max2 * $g / 5, (string) ($series[$secondary[0]]['format'] ?? 'count'));
                self::text($im, 12, $right + 10, $y + 5, $muted, $lab2);
            }
        }
        imageline($im, $left, $bottom, $right, $bottom, $muted);

        $slot = ($right - $left) / max(1, $n);
        $yFor = static function (float $v, float $max) use ($top, $bottom): int {
            return (int) round($bottom - ($max > 0 ? ($bottom - $top) * $v / $max : 0));
        };

        $barSeries = [];
        $lineSeries = [];
        foreach ($series as $i => $s) {
            if ($type === 'line' || ($s['axis'] ?? '') === 'y2') {
                $lineSeries[] = $i;
            } else {
                $barSeries[] = $i;
            }
        }
        if ($type === 'line' && $barSeries === []) {
            $lineSeries = array_keys($series);
        }

        // Bars
        $bw = max(4, (int) floor(($slot * 0.7) / max(1, count($barSeries))));
        foreach ($barSeries as $k => $i) {
            $c = imagecolorallocate($im, ...self::SERIES[$i % count(self::SERIES)]);
            $m = in_array($i, $secondary, true) ? $max2 : $max1;
            foreach ((array) $series[$i]['data'] as $j => $v) {
                if ($j >= $n) { break; }
                $x0 = (int) round($left + $slot * $j + $slot * 0.15 + $bw * $k);
                $y0 = $yFor((float) $v, $m);
                if ($y0 < $bottom) {
                    imagefilledrectangle($im, $x0, $y0, $x0 + $bw - 2, $bottom - 1, $c);
                }
                // Value on top when the chart is not crowded.
                if ($n <= 16 && (float) $v > 0) {
                    $lab = self::fmt((float) $v, (string) ($series[$i]['format'] ?? $format), true);
                    self::text($im, 11, (int) ($x0 + ($bw - self::width(11, $lab)) / 2), $y0 - 6, $ink, $lab);
                }
            }
        }

        // Lines
        foreach ($lineSeries as $i) {
            $c = imagecolorallocate($im, ...self::SERIES[$i % count(self::SERIES)]);
            $m = in_array($i, $secondary, true) ? $max2 : $max1;
            $prev = null;
            imagesetthickness($im, 3);
            foreach ((array) $series[$i]['data'] as $j => $v) {
                if ($j >= $n) { break; }
                $x = (int) round($left + $slot * $j + $slot / 2);
                $y = $yFor((float) $v, $m);
                if ($prev !== null) {
                    imageline($im, $prev[0], $prev[1], $x, $y, $c);
                }
                imagefilledellipse($im, $x, $y, 8, 8, $c);
                $prev = [$x, $y];
            }
            imagesetthickness($im, 1);
        }

        // X labels — thin out when crowded, never overlap.
        $step = max(1, (int) ceil($n / 14));
        foreach ($labels as $j => $lab) {
            if ($j % $step !== 0) { continue; }
            $lab = self::fit(12, $lab, (int) max(40, $slot * $step - 6));
            $x = (int) round($left + $slot * $j + $slot / 2 - self::width(12, $lab) / 2);
            self::text($im, 12, $x, $bottom + 22, $ink, $lab);
        }
    }

    /** One series as a ring with its share labels. */
    private static function doughnut($im, array $labels, array $series, string $format): void
    {
        $W = self::W;
        $H = self::H;
        $ink = imagecolorallocate($im, ...self::INK);
        $cx = 380;
        $cy = 400;
        $r  = 220;
        $data = array_map('floatval', (array) ($series['data'] ?? []));
        $total = array_sum($data);
        if ($total <= 0) {
            self::text($im, 16, 60, 300, $ink, 'No data for this period.');
            return;
        }
        $start = -90.0;
        foreach ($data as $i => $v) {
            $deg = 360.0 * $v / $total;
            $c = imagecolorallocate($im, ...self::SERIES[$i % count(self::SERIES)]);
            imagefilledarc($im, $cx, $cy, $r * 2, $r * 2, (int) round($start), (int) round($start + $deg), $c, IMG_ARC_PIE);
            $start += $deg;
        }
        imagefilledellipse($im, $cx, $cy, (int) ($r * 1.15), (int) ($r * 1.15), imagecolorallocate($im, 255, 255, 255));
        $tl = self::fmt($total, $format, true);
        self::text($im, 26, (int) ($cx - self::width(26, $tl) / 2), $cy + 10, $ink, $tl, true);

        $y = 220;
        foreach ($labels as $i => $lab) {
            if ($i >= 10) { break; }
            $c = imagecolorallocate($im, ...self::SERIES[$i % count(self::SERIES)]);
            imagefilledrectangle($im, 680, $y - 14, 700, $y + 2, $c);
            $v = $data[$i] ?? 0;
            $share = $total > 0 ? round($v * 100 / $total) : 0;
            self::text($im, 15, 714, $y, $ink, self::fit(15, $lab, 300) . '  ' . self::fmt($v, $format, true) . '  (' . $share . '%)');
            $y += 36;
        }
    }

    /* --- small helpers ------------------------------------------------ */

    private static function niceMax(float $m): float
    {
        if ($m <= 0) {
            return 5.0;
        }
        $mag = 10 ** floor(log10($m));
        $unit = $m / $mag;
        $nice = $unit <= 1 ? 1 : ($unit <= 2 ? 2 : ($unit <= 2.5 ? 2.5 : ($unit <= 5 ? 5 : 10)));
        return (float) ($nice * $mag);
    }

    private static function fmt(float $v, string $format, bool $short = false): string
    {
        if ($format === 'percent') {
            return round($v) . '%';
        }
        if ($format === 'money') {
            if ($short && $v >= 100000) {
                return '₹' . round($v / 100000, 1) . 'L';
            }
            if ($short && $v >= 10000) {
                return '₹' . round($v / 1000) . 'k';
            }
            return '₹' . number_format($v, 0, '.', ',');
        }
        if ($short && $v >= 10000) {
            return round($v / 1000, 1) . 'k';
        }
        return (string) round($v);
    }

    private static function font(): string
    {
        return dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
    }

    private static function text($im, float $size, int $x, int $y, int $col, string $text, bool $bold = false): void
    {
        $text = function_exists('dev_shape') ? dev_shape($text) : $text;
        imagettftext($im, $size, 0, $x, $y, $col, self::font(), $text);
        if ($bold) {
            imagettftext($im, $size, 0, $x + 1, $y, $col, self::font(), $text);
        }
    }

    private static function width(float $size, string $text): int
    {
        $text = function_exists('dev_shape') ? dev_shape($text) : $text;
        $box = imagettfbbox($size, 0, self::font(), $text);
        return $box === false ? (int) ($size * 0.6 * mb_strlen($text)) : (int) abs($box[2] - $box[0]);
    }

    private static function fit(float $size, string $text, int $maxW): string
    {
        if (self::width($size, $text) <= $maxW) {
            return $text;
        }
        while (mb_strlen($text) > 1 && self::width($size, $text . '…') > $maxW) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . '…';
    }
}
