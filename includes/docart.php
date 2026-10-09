<?php
/**
 * =====================================================================
 *  DocArt — the brand and heritage art every printed document shares
 *  (9 Oct 2026, owner: "logo haru ma name ni join xa, background hataidinu
 *  only logo matra ... har ek document ma Nepal India ko flag ... ticket
 *  dekhi chalani ma ni Nepali chiz haru: Sagarmatha, Gautam Buddha, Nepal
 *  flag").
 *
 *  One place that knows where the transparent EMBLEM (no baked name, no
 *  white tile), the two FLAGS and the HERITAGE band (Sagarmatha, prayer
 *  flags, the seated Buddha and the stupa with Buddha eyes) live, and how
 *  to put them on a GD canvas or on a Pdf page:
 *
 *      GD   placeImage() · mark() · flag() · flagPair() · band() · ridge()
 *      PDF  pdfImage()   · pdfMark() · pdfFlags() · pdfBand()
 *
 *  FAIL-SAFE BY CONTRACT. Nothing here ever throws: a missing or broken
 *  asset means that piece of art is simply skipped (the flags fall back to
 *  a drawn version), so a ticket, a chalani or a statement is never lost
 *  over a picture. Every public method is wrapped in try/catch.
 *
 *  WhatsApp re-encodes the PNG tickets to JPEG, so the art is flat-colour
 *  vector-style (assets/img/*.svg exports) and every soft effect is baked
 *  into opaque pixels on the canvas — nothing depends on PNG alpha
 *  surviving the trip.
 *
 *  The Pdf class decodes PNGs pixel by pixel in PHP, and the chalani PDF
 *  is drawn on every request, so the PDF helpers hand Pdf::image() a small
 *  cached derivative (a baseline JPEG for the opaque band, a PNG with
 *  alpha for the emblem) instead of the full-size asset.
 *
 *  Reads assets only. Writes nothing but those derivatives, under the
 *  system temp folder.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class DocArt
{
    /** Flag ratios of the shipped assets (w / h). */
    private const RATIO_IN = 1.5;          // 360x240
    private const RATIO_NP = 264 / 344;    // the double pennant

    /** The halo file is this many times the mark's width (transparent glow margin). */
    private const HALO_GROW = 1.36;

    /** @var array<string, \GdImage|false> per-request decode cache */
    private static array $gd = [];

    /** Test hook: point the asset lookups at an empty folder to prove the fail-safe. */
    private static ?string $rootOverride = null;

    /* ================================================================
     *  Files
     * ================================================================ */

    private static function root(): string
    {
        return self::$rootOverride ?? dirname(__DIR__);
    }

    /** For tests only: null restores the real web root. */
    public static function setRootForTests(?string $dir): void
    {
        self::$rootOverride = $dir;
        self::$gd = [];
        self::$ratio = [];
    }

    /**
     * An absolute path for a repo-relative (or absolute) image path, or null
     * when the file is missing, empty, too big or not a PNG / JPEG.
     */
    public static function asset(string $rel): ?string
    {
        try {
            $rel = trim($rel);
            if ($rel === '' || preg_match('#^https?://#i', $rel) === 1) {
                return null;
            }
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
                return null;
            }
            $cands = [self::root() . '/' . ltrim(str_replace('\\', '/', $rel), '/')];
            if (self::$rootOverride === null && (is_file($rel) || preg_match('#^([A-Za-z]:)?[\\\\/]#', $rel) === 1)) {
                array_unshift($cands, $rel);
            }
            foreach ($cands as $p) {
                if (is_file($p) && is_readable($p)) {
                    $sz = (int) @filesize($p);
                    if ($sz > 0 && $sz < 2 * 1024 * 1024) {
                        return $p;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /**
     * The transparent emblem — the mark WITHOUT the baked company name and
     * WITHOUT a white tile (the name is always drawn as live text beside it).
     *
     * Order: $prefer (a company's own uploaded logo — the Nepal company's,
     * say), the BRAND helper when it is installed, the company_logo_mark
     * setting, assets/img/logo-mark.png, and only then the old full logo so
     * a document still carries SOME mark on an install that lacks the file.
     */
    public static function markFile(string $prefer = ''): ?string
    {
        try {
            if ($prefer !== '' && ($p = self::asset($prefer)) !== null) {
                return $p;
            }
            if (self::$rootOverride === null && class_exists('Brand') && method_exists('Brand', 'markFile')) {
                try {
                    $b = (string) Brand::markFile();
                    if ($b !== '' && ($p = self::asset($b)) !== null && !str_ends_with(strtolower(str_replace('\\', '/', $p)), '/assets/img/logo.png')) {
                        return $p;
                    }
                } catch (\Throwable $e) {
                }
            }
            if (class_exists('Settings')) {
                try {
                    $s = Settings::getString('company_logo_mark', '');
                    if ($s !== '' && ($p = self::asset($s)) !== null) {
                        return $p;
                    }
                } catch (\Throwable $e) {
                }
            }
            foreach (['assets/img/logo-mark.png', 'assets/img/logo-mark-96h.png'] as $c) {
                if (($p = self::asset($c)) !== null) {
                    return $p;
                }
            }
            if (self::$rootOverride === null && class_exists('Ticket') && method_exists('Ticket', 'logoFile')) {
                return Ticket::logoFile();
            }
            return self::asset('assets/img/logo.png');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** 'IN' | 'NP' → the flag PNG, or null (callers then draw it). */
    public static function flagFile(string $cc): ?string
    {
        $cc = strtoupper(trim($cc));
        if ($cc !== 'IN' && $cc !== 'NP') {
            return null;
        }
        try {
            if (self::$rootOverride === null && class_exists('Brand') && method_exists('Brand', 'flagPng')) {
                try {
                    $b = (string) Brand::flagPng($cc);
                    if ($b !== '' && ($p = self::asset($b)) !== null) {
                        return $p;
                    }
                } catch (\Throwable $e) {
                }
            }
        } catch (\Throwable $e) {
        }
        return self::asset('assets/img/flag-' . strtolower($cc) . '.png');
    }

    /** The heritage band art (1080x300): Sagarmatha, prayer flags, Buddha, stupa. */
    public static function bandFile(): ?string
    {
        return self::asset('assets/img/nepal-band.png');
    }

    /** Is the heritage art switched on? (Settings doc_heritage_art, default on.) */
    public static function heritageOn(): bool
    {
        try {
            return !class_exists('Settings') || Settings::getBool('doc_heritage_art', true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /* ================================================================
     *  GD helpers
     * ================================================================ */

    /** Decode once per request; truecolor with its alpha kept. */
    private static function load(?string $file): ?\GdImage
    {
        if ($file === null || $file === '' || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $key = $file . '|' . (string) @filemtime($file);
        if (!array_key_exists($key, self::$gd)) {
            $im = @imagecreatefromstring((string) @file_get_contents($file));
            if ($im instanceof \GdImage) {
                if (!imageistruecolor($im)) {
                    imagepalettetotruecolor($im);
                }
                imagealphablending($im, false);
                imagesavealpha($im, true);
            }
            self::$gd[$key] = $im instanceof \GdImage ? $im : false;
        }
        $im = self::$gd[$key];
        return $im instanceof \GdImage ? $im : null;
    }

    /**
     * Fit an image inside a box, keeping its aspect, alpha-blended onto the
     * canvas. $align picks where in the box it sits (left|center|right,
     * vertically always centred).
     *
     * @return array{x:int,y:int,w:int,h:int}|null  what was drawn, null when skipped
     */
    public static function placeImage(\GdImage $im, ?string $file, int $x, int $y, int $maxW, int $maxH, string $align = 'left'): ?array
    {
        try {
            $src = self::load($file);
            if ($src === null || $maxW < 2 || $maxH < 2) {
                return null;
            }
            $sw = imagesx($src);
            $sh = imagesy($src);
            if ($sw < 1 || $sh < 1) {
                return null;
            }
            $scale = min($maxW / $sw, $maxH / $sh);
            $w = max(1, (int) round($sw * $scale));
            $h = max(1, (int) round($sh * $scale));
            $dx = $align === 'center' ? $x + intdiv($maxW - $w, 2) : ($align === 'right' ? $x + $maxW - $w : $x);
            $dy = $y + intdiv($maxH - $h, 2);
            imagealphablending($im, true);
            imagecopyresampled($im, $src, $dx, $dy, 0, 0, $w, $h, $sw, $sh);
            return ['x' => $dx, 'y' => $dy, 'w' => $w, 'h' => $h];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * A soft light glow, baked into the canvas, so the emblem's navy globe
     * still reads on a navy band (no white tile — the owner asked for the
     * mark alone). Concentric ellipses with a little opacity each.
     */
    public static function halo(\GdImage $im, int $cx, int $cy, int $rx, int $ry, array $rgb = [190, 214, 255], int $steps = 16, float $peak = 0.42): void
    {
        try {
            imagealphablending($im, true);
            $steps = max(2, $steps);
            // per-step opacity that accumulates to $peak in the centre
            $o = 1 - pow(max(0.0001, 1 - $peak), 1 / $steps);
            $a = (int) max(0, min(127, round(127 * (1 - $o))));
            for ($i = 0; $i < $steps; $i++) {
                $t = 1 - $i / $steps;                       // 1 = outermost
                $col = imagecolorallocatealpha($im, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2], $a);
                imagefilledellipse($im, $cx, $cy, max(2, (int) round(2 * $rx * $t)), max(2, (int) round(2 * $ry * $t)), $col);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * The emblem in a box, aspect kept, optionally on a soft halo.
     *
     * @return array{x:int,y:int,w:int,h:int}|null
     */
    public static function mark(\GdImage $im, int $x, int $y, int $maxW, int $maxH, bool $halo = false, string $prefer = '', string $align = 'left'): ?array
    {
        try {
            $file = self::markFile($prefer);
            $src  = self::load($file);
            if ($src === null) {
                return null;
            }
            $scale = min($maxW / max(1, imagesx($src)), $maxH / max(1, imagesy($src)));
            $w = (int) round(imagesx($src) * $scale);
            $h = (int) round(imagesy($src) * $scale);
            $dx = $align === 'center' ? $x + intdiv($maxW - $w, 2) : ($align === 'right' ? $x + $maxW - $w : $x);
            $dy = $y + intdiv($maxH - $h, 2);
            if ($halo) {
                self::halo($im, $dx + intdiv($w, 2), $dy + (int) round($h * 0.46), (int) round($w * 0.60), (int) round($h * 0.72));
            }
            return self::placeImage($im, $file, $dx, $dy, $w, $h);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @var array<string, float> w/h of the flag files actually shipped */
    private static array $ratio = [];

    /** w / h of a flag: the shipped PNG's own shape when readable, else the drawn default. */
    private static function ratio(string $cc): float
    {
        $cc = strtoupper($cc) === 'NP' ? 'NP' : 'IN';
        if (!isset(self::$ratio[$cc])) {
            $r = $cc === 'NP' ? self::RATIO_NP : self::RATIO_IN;
            try {
                $f = self::flagFile($cc);
                $info = $f !== null ? @getimagesize($f) : false;
                if ($info !== false && $info[0] > 0 && $info[1] > 0) {
                    $r = $info[0] / $info[1];
                }
            } catch (\Throwable $e) {
            }
            self::$ratio[$cc] = $r;
        }
        return self::$ratio[$cc];
    }

    /** Width a flag of height $h takes. */
    public static function flagWidth(string $cc, int $h): int
    {
        return (int) round($h * self::ratio($cc));
    }

    /**
     * One flag, $h tall, top-left at ($x,$y). The asset when it is there,
     * else a drawn flag (so a document never loses the pair). $edge draws a
     * hairline round the Indian flag for a light background (its white band
     * otherwise melts into the page). Returns the width drawn.
     */
    public static function flag(\GdImage $im, string $cc, int $x, int $y, int $h, bool $edge = false): int
    {
        try {
            $cc = strtoupper(trim($cc));
            if ($cc !== 'IN' && $cc !== 'NP') {
                return 0;
            }
            $w = self::flagWidth($cc, $h);
            $drawn = self::placeImage($im, self::flagFile($cc), $x, $y, $w, $h, 'left');
            if ($drawn === null) {
                $cc === 'IN' ? self::drawIndia($im, $x, $y, $w, $h) : self::drawNepal($im, $x, $y, $w, $h);
            }
            if ($edge && $cc === 'IN') {
                imagerectangle($im, $x, $y, $x + $w - 1, $y + $h - 1, imagecolorallocate($im, 196, 204, 218));
            }
            return $w;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Both flags side by side — the issuing side first ($first = 'IN' for an
     * India→Nepal ticket, 'NP' for the return run). Returns the total width.
     */
    public static function flagPair(\GdImage $im, int $x, int $y, int $h, int $gap = 10, string $first = 'IN', bool $edge = false): int
    {
        try {
            $order = strtoupper($first) === 'NP' ? ['NP', 'IN'] : ['IN', 'NP'];
            $w1 = self::flag($im, $order[0], $x, $y, $h, $edge);
            $w2 = self::flag($im, $order[1], $x + $w1 + $gap, $y, $h, $edge);
            return $w1 + $gap + $w2;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Width flagPair() will take, for right-aligning it before drawing. */
    public static function flagPairWidth(int $h, int $gap = 10): int
    {
        return self::flagWidth('IN', $h) + $gap + self::flagWidth('NP', $h);
    }

    /** Drawn tricolour + Ashoka Chakra (24 spokes) — the no-asset fallback. */
    private static function drawIndia(\GdImage $im, int $x, int $y, int $w, int $h): void
    {
        $saffron = imagecolorallocate($im, 255, 153, 51);
        $white   = imagecolorallocate($im, 255, 255, 255);
        $green   = imagecolorallocate($im, 19, 136, 8);
        $navy    = imagecolorallocate($im, 0, 0, 128);
        $b = max(1, intdiv($h, 3));
        imagefilledrectangle($im, $x, $y, $x + $w - 1, $y + $b - 1, $saffron);
        imagefilledrectangle($im, $x, $y + $b, $x + $w - 1, $y + 2 * $b - 1, $white);
        imagefilledrectangle($im, $x, $y + 2 * $b, $x + $w - 1, $y + $h - 1, $green);
        $cx = $x + intdiv($w, 2);
        $cy = $y + intdiv($h, 2);
        $r  = max(2, (int) round($b * 0.45));
        imageellipse($im, $cx, $cy, 2 * $r, 2 * $r, $navy);
        if ($r >= 5) {
            for ($i = 0; $i < 24; $i++) {
                $a = $i * M_PI / 12;
                imageline($im, $cx, $cy, $cx + (int) round(cos($a) * $r), $cy + (int) round(sin($a) * $r), $navy);
            }
        }
    }

    /** Drawn double pennant, crimson with the blue border, moon and sun. */
    private static function drawNepal(\GdImage $im, int $x, int $y, int $w, int $h): void
    {
        $blue    = imagecolorallocate($im, 0, 56, 147);
        $crimson = imagecolorallocate($im, 220, 20, 60);
        $white   = imagecolorallocate($im, 255, 255, 255);
        $fx = static fn(float $u): int => $x + (int) round($u * $w);
        $fy = static fn(float $v): int => $y + (int) round($v * $h);
        // outer (blue) then inner (crimson) — the shape of the 05-router NP_FLAG_SVG path
        $outer = [$fx(0), $fy(0), $fx(1), $fy(0.56), $fx(0.40), $fy(0.56), $fx(1), $fy(1), $fx(0), $fy(1)];
        imagefilledpolygon($im, $outer, $blue);
        $inner = [$fx(0.07), $fy(0.06), $fx(0.83), $fy(0.51), $fx(0.30), $fy(0.51), $fx(0.85), $fy(0.95), $fx(0.07), $fy(0.95)];
        imagefilledpolygon($im, $inner, $crimson);
        $r = max(2, (int) round($w * 0.12));
        imagefilledellipse($im, $fx(0.28), $fy(0.33), 2 * $r, 2 * $r, $white);
        imagefilledellipse($im, $fx(0.28), $fy(0.30), 2 * $r, (int) round(1.4 * $r), $crimson);
        imagefilledellipse($im, $fx(0.28), $fy(0.77), (int) round(2.6 * $r), (int) round(2.6 * $r), $white);
    }

    /**
     * The heritage art in a box, scaled to COVER it (cropped, never
     * stretched). $focusX 0..1 picks which part of a wide crop survives.
     * Rounded corners are painted over with $cornerRgb (the page behind).
     * Falls back to a flat Himalayan ridge when the art file is missing.
     */
    public static function band(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $radius = 0, ?array $cornerRgb = null, float $focusX = 0.5, float $focusY = 0.5): bool
    {
        try {
            $w = $x2 - $x1;
            $h = $y2 - $y1;
            if ($w < 4 || $h < 4) {
                return false;
            }
            $src = self::load(self::bandFile());
            if ($src === null) {
                self::ridge($im, $x1, $y1, $x2, $y2);
            } else {
                [$sx, $sy, $sw, $sh] = self::coverCrop(imagesx($src), imagesy($src), $w, $h, $focusX, $focusY);
                imagealphablending($im, true);
                imagecopyresampled($im, $src, $x1, $y1, $sx, $sy, $w, $h, $sw, $sh);
            }
            if ($radius > 0 && $cornerRgb !== null) {
                self::roundCorners($im, $x1, $y1, $x2 - 1, $y2 - 1, $radius, imagecolorallocate($im, (int) $cornerRgb[0], (int) $cornerRgb[1], (int) $cornerRgb[2]));
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The heritage art as a faint WASH over a dark band (opacity $pct %),
     * fading in from the left edge, so it sits behind white header text as
     * texture — fits any letterhead however long its lines are. Opaque
     * pixels only (imagecopymerge), so it survives a JPEG pass.
     */
    public static function bandWash(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $pct = 24, float $focusX = 0.5, float $focusY = 0.5): bool
    {
        try {
            $w = $x2 - $x1;
            $h = $y2 - $y1;
            $src = self::load(self::bandFile());
            if ($src === null || $w < 8 || $h < 8) {
                return false;
            }
            $tmp = imagecreatetruecolor($w, $h);
            [$sx, $sy, $sw, $sh] = self::coverCrop(imagesx($src), imagesy($src), $w, $h, $focusX, $focusY);
            imagecopyresampled($tmp, $src, 0, 0, $sx, $sy, $w, $h, $sw, $sh);
            $fade = (int) min($w / 3, 260);
            $strip = 8;
            for ($x = 0; $x < $w; $x += $strip) {
                $k = $x >= $fade ? 1.0 : $x / max(1, $fade);
                $p = (int) round($pct * $k);
                if ($p > 0) {
                    imagecopymerge($im, $tmp, $x1 + $x, $y1, $x, 0, min($strip, $w - $x), $h, $p);
                }
            }
            imagedestroy($tmp);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * A flat-fill Himalayan ridge with snow caps on a dusk sky — the band
     * when the art file is missing, and a quiet decoration on its own.
     */
    public static function ridge(\GdImage $im, int $x1, int $y1, int $x2, int $y2, array $sky = [38, 70, 128], array $far = [92, 124, 178], array $near = [52, 84, 140], array $snow = [244, 248, 255], array $ground = [46, 125, 80]): void
    {
        try {
            $w = $x2 - $x1;
            $h = $y2 - $y1;
            if ($w < 4 || $h < 4) {
                return;
            }
            for ($y = $y1; $y < $y2; $y++) {
                $t = ($y - $y1) / max(1, $h);
                imageline($im, $x1, $y, $x2 - 1, $y, imagecolorallocate($im,
                    (int) min(255, $sky[0] + 70 * $t), (int) min(255, $sky[1] + 70 * $t), (int) min(255, $sky[2] + 60 * $t)));
            }
            $px = static fn(float $u): int => $x1 + (int) round($u * $w);
            $py = static fn(float $v): int => $y1 + (int) round($v * $h);
            imagefilledpolygon($im, [$px(0), $py(0.78), $px(0.12), $py(0.46), $px(0.22), $py(0.62), $px(0.36), $py(0.30),
                $px(0.48), $py(0.55), $px(0.62), $py(0.36), $px(0.78), $py(0.58), $px(0.90), $py(0.40), $px(1), $py(0.60), $px(1), $py(0.86), $px(0), $py(0.86)],
                imagecolorallocate($im, $far[0], $far[1], $far[2]));
            // the high peak — Sagarmatha — with its snow cap
            $peak = [$px(0.30), $py(0.86), $px(0.52), $py(0.12), $px(0.74), $py(0.86)];
            imagefilledpolygon($im, $peak, imagecolorallocate($im, $near[0], $near[1], $near[2]));
            imagefilledpolygon($im, [$px(0.465), $py(0.24), $px(0.52), $py(0.12), $px(0.575), $py(0.24), $px(0.545), $py(0.21), $px(0.52), $py(0.27), $px(0.495), $py(0.21)],
                imagecolorallocate($im, $snow[0], $snow[1], $snow[2]));
            imagefilledrectangle($im, $x1, $py(0.86), $x2 - 1, $y2 - 1, imagecolorallocate($im, $ground[0], $ground[1], $ground[2]));
        } catch (\Throwable $e) {
        }
    }

    /**
     * Just the mountains — a low-contrast silhouette for a header band
     * (no sky, no ground): a far range, the high peak and a faint snow cap.
     * The colours should sit a shade off the band so it reads as texture.
     */
    public static function peaks(\GdImage $im, int $x1, int $y1, int $x2, int $y2, array $far, array $near, array $snow): void
    {
        try {
            $w = $x2 - $x1;
            $h = $y2 - $y1;
            if ($w < 4 || $h < 4) {
                return;
            }
            $px = static fn(float $u): int => $x1 + (int) round($u * $w);
            $py = static fn(float $v): int => $y1 + (int) round($v * $h);
            imagefilledpolygon($im, [$px(0), $py(1), $px(0), $py(0.70), $px(0.08), $py(0.52), $px(0.17), $py(0.66), $px(0.30), $py(0.40),
                $px(0.41), $py(0.58), $px(0.53), $py(0.30), $px(0.60), $py(0.44), $px(0.70), $py(0.18), $px(0.79), $py(0.40),
                $px(0.88), $py(0.26), $px(1), $py(0.50), $px(1), $py(1)], imagecolorallocate($im, $far[0], $far[1], $far[2]));
            imagefilledpolygon($im, [$px(0.56), $py(1), $px(0.70), $py(0.02), $px(0.86), $py(1)], imagecolorallocate($im, $near[0], $near[1], $near[2]));
            imagefilledpolygon($im, [$px(0.672), $py(0.20), $px(0.70), $py(0.02), $px(0.728), $py(0.20), $px(0.712), $py(0.16), $px(0.70), $py(0.24), $px(0.688), $py(0.16)],
                imagecolorallocate($im, $snow[0], $snow[1], $snow[2]));
        } catch (\Throwable $e) {
        }
    }

    /** Paint the four corners outside a radius with $col (rounded clip on GD). */
    public static function roundCorners(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $col, bool $top = true, bool $bottom = true): void
    {
        try {
            $r = max(1, $r);
            $corners = [];
            if ($top)    { $corners[] = [$x1, $y1, $x1 + $r, $y1 + $r]; $corners[] = [$x2 - $r, $y1, $x2 - $r, $y1 + $r]; }
            if ($bottom) { $corners[] = [$x1, $y2 - $r, $x1 + $r, $y2 - $r]; $corners[] = [$x2 - $r, $y2 - $r, $x2 - $r, $y2 - $r]; }
            foreach ($corners as [$bx, $by, $cx, $cy]) {
                for ($yy = $by; $yy <= $by + $r; $yy++) {
                    for ($xx = $bx; $xx <= $bx + $r; $xx++) {
                        if (($xx - $cx) ** 2 + ($yy - $cy) ** 2 > $r * $r) {
                            imagesetpixel($im, $xx, $yy, $col);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Source rectangle that COVERS a $dw x $dh box from a $sw x $sh image.
     *
     * @return array{0:int,1:int,2:int,3:int}  sx, sy, sw, sh
     */
    private static function coverCrop(int $sw, int $sh, int $dw, int $dh, float $fx, float $fy): array
    {
        $srcRatio = $sw / max(1, $sh);
        $dstRatio = $dw / max(1, $dh);
        if ($srcRatio > $dstRatio) {              // source wider: crop the sides
            $cw = (int) round($sh * $dstRatio);
            $sx = (int) round(($sw - $cw) * max(0.0, min(1.0, $fx)));
            return [$sx, 0, $cw, $sh];
        }
        $ch = (int) round($sw / max(0.0001, $dstRatio)); // source taller: crop top / bottom
        $sy = (int) round(($sh - $ch) * max(0.0, min(1.0, $fy)));
        return [0, $sy, $sw, $ch];
    }

    /* ================================================================
     *  PDF helpers (Pdf::image takes PNG with alpha → SMask, or baseline JPEG)
     * ================================================================ */

    /**
     * A small cached copy of an asset for the PDF: $mode 'contain' keeps the
     * whole picture inside $w x $h (PNG, alpha kept), 'cover' crops it to
     * fill the box (baseline JPEG — opaque art only).
     */
    public static function derivative(?string $src, int $w, int $h, string $mode = 'contain', float $focusX = 0.5, float $focusY = 0.5): ?string
    {
        try {
            if ($src === null || !is_file($src) || $w < 2 || $h < 2 || !function_exists('imagecreatetruecolor')) {
                return null;
            }
            $jpeg = $mode === 'cover';
            $key  = md5($src . '|' . (string) @filemtime($src) . '|' . (string) @filesize($src) . "|$w|$h|$mode|$focusX|$focusY|v2");
            $dir  = rtrim(sys_get_temp_dir(), '/\\') . '/shg-docart';
            $out  = $dir . '/' . $key . ($jpeg ? '.jpg' : '.png');
            if (is_file($out) && (int) @filesize($out) > 0) {
                return $out;
            }
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return null;
            }
            $img = self::load($src);
            if ($img === null) {
                return null;
            }
            $sw = imagesx($img);
            $sh = imagesy($img);
            if ($jpeg) {
                [$sx, $sy, $cw, $ch] = self::coverCrop($sw, $sh, $w, $h, $focusX, $focusY);
                $dst = imagecreatetruecolor($w, $h);
                imagefilledrectangle($dst, 0, 0, $w, $h, imagecolorallocate($dst, 255, 255, 255));
                imagealphablending($dst, true);
                imagecopyresampled($dst, $img, 0, 0, $sx, $sy, $w, $h, $cw, $ch);
            } else {
                $scale = min($w / max(1, $sw), $h / max(1, $sh), 1.0);
                $tw = max(1, (int) round($sw * $scale));
                $th = max(1, (int) round($sh * $scale));
                $dst = imagecreatetruecolor($tw, $th);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagefilledrectangle($dst, 0, 0, $tw, $th, imagecolorallocatealpha($dst, 0, 0, 0, 127));
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $sw, $sh);
            }
            $tmp = $out . '.' . bin2hex(random_bytes(3)) . '.tmp';
            if ($jpeg) {
                imageinterlace($dst, false);           // Pdf embeds baseline DCT only
                $ok = imagejpeg($dst, $tmp, 86);
            } else {
                $ok = imagepng($dst, $tmp, 6);
            }
            imagedestroy($dst);
            if (!$ok || !@rename($tmp, $out)) {
                @unlink($tmp);
                return null;
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Fit an image in a PDF box, aspect kept.
     *
     * @return array{x:float,y:float,w:float,h:float}|null
     */
    public static function pdfImage(Pdf $pdf, ?string $file, float $x, float $y, float $maxW, float $maxH, string $align = 'left'): ?array
    {
        try {
            if ($file === null || !is_file($file)) {
                return null;
            }
            $info = @getimagesize($file);
            if ($info === false || $info[0] < 1 || $info[1] < 1) {
                return null;
            }
            $scale = min($maxW / $info[0], $maxH / $info[1]);
            $w = $info[0] * $scale;
            $h = $info[1] * $scale;
            $dx = $align === 'center' ? $x + ($maxW - $w) / 2 : ($align === 'right' ? $x + $maxW - $w : $x);
            $dy = $y + ($maxH - $h) / 2;
            $pdf->image($file, $dx, $dy, $w, $h);
            return ['x' => $dx, 'y' => $dy, 'w' => $w, 'h' => $h];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The emblem on a PDF page, aspect-correct, no white rectangle. Uses a
     * cached ~3x derivative so a per-request PDF does not decode the full
     * asset; $halo bakes a soft glow round it (for a navy band).
     *
     * @return array{x:float,y:float,w:float,h:float}|null
     */
    public static function pdfMark(Pdf $pdf, float $x, float $y, float $maxW, float $maxH, string $prefer = '', string $align = 'left', bool $halo = false): ?array
    {
        try {
            $file = self::markFile($prefer);
            if ($file === null) {
                return null;
            }
            $use = $halo ? self::haloFile($file) : self::derivative($file, (int) min(480, ceil($maxW * 3)), (int) min(320, ceil($maxH * 3)), 'contain');
            if ($use === null) {
                $use = $file;
            }
            if ($halo && $use !== $file) {
                // the halo file carries a transparent margin round the mark: grow the box to match
                $info = @getimagesize($file);
                $hinf = @getimagesize($use);
                if ($info !== false && $hinf !== false && $hinf[0] > 0) {
                    $scale = min($maxW / $info[0], $maxH / $info[1]);
                    $mw = $info[0] * $scale;
                    $mh = $info[1] * $scale;
                    $hw = $mw * self::HALO_GROW;                 // the mark is 1/HALO_GROW of the halo file's width
                    $hh = $hw * $hinf[1] / $hinf[0];
                    $dx = $align === 'center' ? $x + ($maxW - $mw) / 2 : ($align === 'right' ? $x + $maxW - $mw : $x);
                    $dy = $y + ($maxH - $mh) / 2;
                    $pdf->image($use, $dx - ($hw - $mw) / 2, $dy - ($hh - $mh) / 2, $hw, $hh);
                    return ['x' => $dx, 'y' => $dy, 'w' => $mw, 'h' => $mh];
                }
            }
            return self::pdfImage($pdf, $use, $x, $y, $maxW, $maxH, $align);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The emblem with a soft glow baked round it, as a PNG with alpha
     * (HALO_GROW times the mark's width), for PDFs on a navy band.
     */
    public static function haloFile(?string $mark): ?string
    {
        try {
            if ($mark === null || !is_file($mark)) {
                return null;
            }
            $key = md5($mark . '|' . (string) @filemtime($mark) . '|' . (string) @filesize($mark) . '|halo|v3');
            $dir = rtrim(sys_get_temp_dir(), '/\\') . '/shg-docart';
            $out = $dir . '/' . $key . '.png';
            if (is_file($out) && (int) @filesize($out) > 0) {
                return $out;
            }
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return null;
            }
            $src = self::load($mark);
            if ($src === null) {
                return null;
            }
            $mw = min(300, imagesx($src));
            $mh = (int) round(imagesy($src) * $mw / max(1, imagesx($src)));
            $W  = (int) round($mw * self::HALO_GROW);
            $H  = (int) round($mh + ($W - $mw));
            $dst = imagecreatetruecolor($W, $H);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefilledrectangle($dst, 0, 0, $W, $H, imagecolorallocatealpha($dst, 190, 214, 255, 127));
            imagealphablending($dst, true);
            self::halo($dst, intdiv($W, 2), (int) round(($H - $mh) / 2 + $mh * 0.46), (int) round($mw * 0.62), (int) round($mh * 0.74), [190, 214, 255], 18, 0.46);
            imagecopyresampled($dst, $src, intdiv($W - $mw, 2), intdiv($H - $mh, 2), 0, 0, $mw, $mh, imagesx($src), imagesy($src));
            $tmp = $out . '.' . bin2hex(random_bytes(3)) . '.tmp';
            $ok = imagepng($dst, $tmp, 6);
            imagedestroy($dst);
            if (!$ok || !@rename($tmp, $out)) {
                @unlink($tmp);
                return null;
            }
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Both flags on a PDF page, $h tall, top-left at ($x,$y). Returns the
     * width used (0 when neither file could be embedded).
     */
    public static function pdfFlags(Pdf $pdf, float $x, float $y, float $h, float $gap = 4.0, string $first = 'IN', bool $edge = false): float
    {
        try {
            $order = strtoupper($first) === 'NP' ? ['NP', 'IN'] : ['IN', 'NP'];
            $cx = $x;
            $any = false;
            foreach ($order as $i => $cc) {
                $w = $h * self::ratio($cc);
                $f = self::flagFile($cc);
                if ($f !== null) {
                    $small = self::derivative($f, (int) ceil($w * 4), (int) ceil($h * 4), 'contain') ?? $f;
                    $pdf->image($small, $cx, $y, $w, $h);
                    if ($edge && $cc === 'IN') {
                        $pdf->rect($cx, $y, $w, $h, [196, 204, 218], false);
                    }
                    $any = true;
                } else {
                    // drawn fallback: the tricolour as three rects / the pennant as a crimson block
                    if ($cc === 'IN') {
                        $pdf->rect($cx, $y, $w, $h / 3, [255, 153, 51], true);
                        $pdf->rect($cx, $y + $h / 3, $w, $h / 3, [255, 255, 255], true);
                        $pdf->rect($cx, $y + 2 * $h / 3, $w, $h / 3, [19, 136, 8], true);
                    } else {
                        $pdf->rect($cx, $y, $w, $h, [220, 20, 60], true);
                        $pdf->rect($cx, $y, $w, $h, [0, 56, 147], false);
                    }
                    $any = true;
                }
                $cx += $w + ($i === 0 ? $gap : 0);
            }
            return $any ? $cx - $x : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    /** Width pdfFlags() takes at height $h. */
    public static function pdfFlagsWidth(float $h, float $gap = 4.0): float
    {
        return $h * self::ratio('IN') + $gap + $h * self::ratio('NP');
    }

    /**
     * The heritage art as a cover-cropped strip on a PDF page (baseline
     * JPEG derivative at ~2.5x, embedded as-is). False when skipped.
     */
    public static function pdfBand(Pdf $pdf, float $x, float $y, float $w, float $h, float $focusX = 0.5, float $focusY = 0.5): bool
    {
        try {
            if ($w < 4 || $h < 4) {
                return false;
            }
            $file = self::derivative(self::bandFile(), (int) ceil($w * 2.5), (int) ceil($h * 2.5), 'cover', $focusX, $focusY);
            if ($file === null) {
                return false;
            }
            $pdf->image($file, $x, $y, $w, $h);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
