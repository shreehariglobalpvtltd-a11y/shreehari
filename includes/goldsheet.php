<?php
/**
 * =====================================================================
 *  GoldSheet — the premium GOLD look shared by the crew sheets
 *  (ChalaniPng pages and the per-bus ChallanPng), 9 Oct 2026.
 *
 *  Owner: "ticket ra chalani ma sadhai payment QR, modern, golden, logo,
 *  extra high-quality depth ra colour, Canva template jasto, bhagwan ko
 *  mantra ra 'शुभ यात्रा'."
 *
 *  Pure drawing + two small data answers (the mantra / blessing lines and
 *  the payment QR target). The callers keep their own text engines (the
 *  DevShape / dev_shape boundary), so nothing here draws a glyph.
 *
 *  Palette (shared with the ticket): NAVY #0A2A8F, ROYAL #1A44C8,
 *  MAROON #C2540A, GOLD_DEEP #8A6A1F, GOLD #C9A227, GOLD_LIGHT #F3DE8A,
 *  CREAM #FFF8E7, INK #1A1A2E. Gold foil = vertical GOLD_DEEP -> GOLD_LIGHT
 *  -> GOLD.
 *
 *  FAIL-SAFE: every soft effect is wrapped so a GD quirk drops the effect,
 *  never the document. Every effect is baked into opaque pixels (WhatsApp
 *  re-encodes to JPEG). Reads settings only; writes nothing but a temp QR
 *  file that is removed straight away.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/docart.php';

final class GoldSheet
{
    public const NAVY       = [10, 42, 143];
    public const ROYAL      = [26, 68, 200];
    public const MAROON     = [194, 84, 10];
    public const GOLD_DEEP  = [138, 106, 31];
    public const GOLD       = [201, 162, 39];
    public const GOLD_LIGHT = [243, 222, 138];
    public const CREAM      = [255, 248, 231];
    public const INK        = [26, 26, 46];

    public const MANTRA_DEFAULT   = 'ॐ श्री गणेशाय नमः · ॐ नमो नारायणाय · जय श्री राम · जय अम्बे';
    public const BLESSING_DEFAULT = 'शुभ यात्रा · सुरक्षित यात्रा · सुखद यात्रा';

    /* ------------------------------------------------------------------ */
    /*  Words                                                              */
    /* ------------------------------------------------------------------ */

    /** The mantra line for the top of the sheet; '' = hidden. */
    public static function mantra(): string
    {
        try {
            if (method_exists('DocArt', 'mantraLine')) {
                return trim((string) DocArt::mantraLine());
            }
        } catch (\Throwable $e) {
        }
        try {
            return trim(Settings::getString('doc_mantra_line', self::MANTRA_DEFAULT));
        } catch (\Throwable $e) {
            return self::MANTRA_DEFAULT;
        }
    }

    /** The blessing for the footer; '' = hidden. */
    public static function blessing(): string
    {
        try {
            if (method_exists('DocArt', 'blessingLine')) {
                return trim((string) DocArt::blessingLine());
            }
        } catch (\Throwable $e) {
        }
        try {
            return trim(Settings::getString('doc_blessing_line', self::BLESSING_DEFAULT));
        } catch (\Throwable $e) {
            return self::BLESSING_DEFAULT;
        }
    }

    /**
     * What the MANDATORY payment QR points at. The company UPI when one is
     * set; else the office WhatsApp; else the website — never nothing.
     *
     * @return array{payload:string,title:string,line1:string,line2:string,kind:string}
     */
    public static function payment(string $ref): array
    {
        $upi  = '';
        $name = '';
        try {
            $upi  = trim(Settings::getString('upi_id', ''));
            $name = trim(Settings::getString('upi_name', defined('APP_NAME') ? (string) APP_NAME : ''));
        } catch (\Throwable $e) {
        }
        if ($upi !== '') {
            return [
                'payload' => 'upi://pay?' . http_build_query(['pa' => $upi, 'pn' => $name, 'cu' => 'INR', 'tn' => $ref]),
                'title'   => 'भुक्तानी QR · UPI',
                'line1'   => 'UPI: ' . $upi,
                'line2'   => $name,
                'kind'    => 'upi',
            ];
        }
        $wa = '';
        try {
            $wa = Settings::officeWhatsApp();
        } catch (\Throwable $e) {
        }
        if ($wa !== '') {
            return [
                'payload' => 'https://wa.me/' . $wa . '?text=' . rawurlencode('Payment for ' . $ref),
                'title'   => 'Pay via office',
                'line1'   => 'WhatsApp +' . $wa,
                'line2'   => 'Scan to message the office',
                'kind'    => 'office',
            ];
        }
        $web = function_exists('appUrl') ? (string) appUrl('') : 'https://www.shreehariglobal.in/';
        return ['payload' => $web, 'title' => 'Pay via office', 'line1' => $web, 'line2' => 'Contact the office to pay', 'kind' => 'web'];
    }

    /**
     * Draw a QR (dark on white, four-module quiet zone, integer modules —
     * the same recipe as the ticket's $drawQr). Returns the drawn side in
     * px, 0 when it could not be drawn.
     */
    public static function qr(\GdImage $im, string $payload, int $x, int $y, int $maxSize): int
    {
        if (!class_exists('QrCode')) {
            require_once __DIR__ . '/qr.php';
        }
        $tmp = (string) tempnam(sys_get_temp_dir(), 'shgqr');
        try {
            $modules = count(QrCode::matrix($payload, QrCode::ECC_M)) + 8;
            $scale   = max(1, intdiv($maxSize, $modules));
            QrCode::png($payload, $tmp, $scale, 4, QrCode::ECC_M);
            $qr = imagecreatefromstring((string) file_get_contents($tmp));
            if ($qr === false) {
                throw new RuntimeException('QR image unavailable');
            }
            $side = imagesx($qr);
            imagecopy($im, $qr, $x, $y, 0, 0, $side, imagesy($qr));
            imagedestroy($qr);
            return $side;
        } catch (\Throwable $e) {
            if (class_exists('Logger')) {
                Logger::error('GoldSheet payment QR: ' . $e->getMessage());
            }
            return 0;
        } finally {
            @unlink($tmp);
        }
    }

    /** The side qr() will draw for this payload and box, without drawing. */
    public static function qrSide(string $payload, int $maxSize): int
    {
        try {
            if (!class_exists('QrCode')) {
                require_once __DIR__ . '/qr.php';
            }
            $modules = count(QrCode::matrix($payload, QrCode::ECC_M)) + 8;
            return max(1, intdiv($maxSize, $modules)) * $modules;
        } catch (\Throwable $e) {
            return $maxSize;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Colour                                                             */
    /* ------------------------------------------------------------------ */

    /** @param array{0:int,1:int,2:int} $rgb */
    public static function col(\GdImage $im, array $rgb, int $alpha = 0): int
    {
        return imagecolorallocatealpha($im, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2], max(0, min(127, $alpha)));
    }

    /** @return array{0:int,1:int,2:int} */
    public static function mix(array $a, array $b, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    /** Gold foil colour at 0..1 down the foil. */
    private static function foilAt(float $t): array
    {
        return $t < 0.45
            ? self::mix(self::GOLD_DEEP, self::GOLD_LIGHT, $t / 0.45)
            : self::mix(self::GOLD_LIGHT, self::GOLD, ($t - 0.45) / 0.55);
    }

    /* ------------------------------------------------------------------ */
    /*  Fills                                                              */
    /* ------------------------------------------------------------------ */

    /** Vertical two-colour gradient. */
    public static function gradientV(\GdImage $im, int $x1, int $y1, int $x2, int $y2, array $top, array $bottom): void
    {
        $h = max(1, $y2 - $y1);
        for ($y = $y1; $y <= $y2; $y++) {
            imageline($im, $x1, $y, $x2, $y, self::col($im, self::mix($top, $bottom, ($y - $y1) / $h)));
        }
    }

    /** Diagonal-feeling header gradient: vertical blend plus a lighter right side. */
    public static function headerGradient(\GdImage $im, int $x1, int $y1, int $x2, int $y2): void
    {
        $w = max(1, $x2 - $x1);
        // columns in steps of 4px keep it cheap and still smooth
        for ($x = $x1; $x <= $x2; $x += 4) {
            $t   = ($x - $x1) / $w;
            $top = self::mix(self::NAVY, self::ROYAL, 0.15 + 0.55 * $t);
            $bot = self::mix(self::NAVY, self::ROYAL, 0.05 + 0.25 * $t);
            $h   = max(1, $y2 - $y1);
            for ($y = $y1; $y <= $y2; $y += 2) {
                $c = self::col($im, self::mix($top, $bot, ($y - $y1) / $h));
                imagefilledrectangle($im, $x, $y, min($x2, $x + 3), min($y2, $y + 1), $c);
            }
        }
    }

    /** Gold foil fill of a rectangle. */
    public static function foil(\GdImage $im, int $x1, int $y1, int $x2, int $y2): void
    {
        $h = max(1, $y2 - $y1);
        for ($y = $y1; $y <= $y2; $y++) {
            imageline($im, $x1, $y, $x2, $y, self::col($im, self::foilAt(($y - $y1) / $h)));
        }
    }

    /** Gold-foil double rule: a 5px foil band, a 2px gap, a 2px foil hairline. */
    public static function foilRule(\GdImage $im, int $x1, int $x2, int $y, ?array $gap = null): void
    {
        self::foil($im, $x1, $y, $x2, $y + 4);
        if ($gap !== null) {
            imagefilledrectangle($im, $x1, $y + 5, $x2, $y + 6, self::col($im, $gap));
        }
        self::foil($im, $x1, $y + 7, $x2, $y + 8);
    }

    /**
     * Rounded rectangle filled one scanline at a time — no overlapping
     * pieces, so a translucent colour blends evenly (shadows, glass).
     */
    public static function roundFill(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, int $col): void
    {
        $r = max(0, min($r, intdiv($x2 - $x1, 2), intdiv($y2 - $y1, 2)));
        imageantialias($im, false);
        for ($y = $y1; $y <= $y2; $y++) {
            $inset = 0;
            if ($r > 0) {
                $d = $y < $y1 + $r ? $y1 + $r - $y : ($y > $y2 - $r ? $y - ($y2 - $r) : 0);
                if ($d > 0) {
                    $inset = (int) round($r - sqrt(max(0, $r * $r - $d * $d)));
                }
            }
            imageline($im, $x1 + $inset, $y, $x2 - $inset, $y, $col);
        }
        imageantialias($im, true);
    }

    /** Rounded gold-foil fill. */
    public static function foilRound(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r): void
    {
        $h = max(1, $y2 - $y1);
        $r = max(0, min($r, intdiv($x2 - $x1, 2), intdiv($h, 2)));
        imageantialias($im, false);
        for ($y = $y1; $y <= $y2; $y++) {
            $inset = 0;
            $d = $y < $y1 + $r ? $y1 + $r - $y : ($y > $y2 - $r ? $y - ($y2 - $r) : 0);
            if ($r > 0 && $d > 0) {
                $inset = (int) round($r - sqrt(max(0, $r * $r - $d * $d)));
            }
            imageline($im, $x1 + $inset, $y, $x2 - $inset, $y, self::col($im, self::foilAt(($y - $y1) / $h)));
        }
        imageantialias($im, true);
    }

    /** Layered soft drop shadow under a rounded box (draw BEFORE the box). */
    public static function shadow(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r = 12, int $spread = 10, int $dy = 6): void
    {
        try {
            // each layer adds ~4 %, so the centre builds to ~30 % and the edge fades out
            $layer = self::col($im, self::NAVY, 122);
            for ($i = $spread; $i >= 1; $i--) {
                self::roundFill($im, $x1 - $i + 2, $y1 - $i + $dy + 2, $x2 + $i - 2, $y2 + $i + $dy - 2, $r + $i, $layer);
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * A card: soft shadow, fill, gold hairline, and a 1px inner highlight
     * along the top so it reads raised.
     */
    public static function card(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $r, array $fill, array $border = self::GOLD, int $spread = 8): void
    {
        self::shadow($im, $x1, $y1, $x2, $y2, $r, $spread, 5);
        self::roundFill($im, $x1, $y1, $x2, $y2, $r, self::col($im, $border));
        self::roundFill($im, $x1 + 2, $y1 + 2, $x2 - 2, $y2 - 2, max(0, $r - 2), self::col($im, $fill));
        imageline($im, $x1 + $r, $y1 + 3, $x2 - $r, $y1 + 3, self::col($im, [255, 255, 255], 40));
    }

    /* ------------------------------------------------------------------ */
    /*  Ornament                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Faint mandala / guilloche rosette, baked into the paper. Draw it on
     * the bare page before any content; $alpha 0..127 (higher = fainter).
     */
    public static function watermark(\GdImage $im, int $cx, int $cy, int $R, int $alpha = 108): void
    {
        try {
            imageantialias($im, false);
            imagesetthickness($im, 1);
            $gold = self::col($im, self::GOLD, $alpha);
            $deep = self::col($im, self::GOLD_DEEP, min(127, $alpha + 6));
            // guilloche: r(t) = R (a + b cos(k t + phase)), several phases
            $curves = [[12, 0.70, 0.30], [12, 0.52, 0.22], [18, 0.88, 0.12], [8, 0.36, 0.18]];
            foreach ($curves as $ci => [$k, $a, $b]) {
                $phases = $ci === 0 ? 6 : 4;
                for ($p = 0; $p < $phases; $p++) {
                    $ph = $p * M_PI / ($k * $phases / 2);
                    $px = null; $py = null;
                    for ($s = 0; $s <= 720; $s++) {
                        $t  = $s / 720 * 2 * M_PI;
                        $r  = $R * ($a + $b * cos($k * $t + $ph));
                        $x  = (int) round($cx + $r * cos($t));
                        $y  = (int) round($cy + $r * sin($t));
                        if ($px !== null) {
                            imageline($im, $px, $py, $x, $y, $ci === 2 ? $deep : $gold);
                        }
                        $px = $x; $py = $y;
                    }
                }
            }
            foreach ([0.18, 0.99, 1.03] as $f) {
                imageellipse($im, $cx, $cy, (int) (2 * $R * $f), (int) (2 * $R * $f), $deep);
            }
            imageantialias($im, true);
        } catch (\Throwable $e) {
        }
    }

    /** Gold corner ornaments for a rectangle: double L, a diamond and a dot. */
    public static function corners(\GdImage $im, int $x1, int $y1, int $x2, int $y2, int $len = 54): void
    {
        try {
            $g  = self::col($im, self::GOLD);
            $gl = self::col($im, self::GOLD_LIGHT);
            $gd = self::col($im, self::GOLD_DEEP);
            foreach ([[$x1, $y1, 1, 1], [$x2, $y1, -1, 1], [$x1, $y2, 1, -1], [$x2, $y2, -1, -1]] as [$x, $y, $sx, $sy]) {
                imagesetthickness($im, 3);
                imageline($im, $x, $y, $x + $sx * $len, $y, $g);
                imageline($im, $x, $y, $x, $y + $sy * $len, $g);
                imagesetthickness($im, 1);
                imageline($im, $x + $sx * 7, $y + $sy * 7, $x + $sx * ($len - 14), $y + $sy * 7, $gl);
                imageline($im, $x + $sx * 7, $y + $sy * 7, $x + $sx * 7, $y + $sy * ($len - 14), $gl);
                // diamond on the corner
                $dx = $x + $sx * 15; $dy = $y + $sy * 15;
                imagefilledpolygon($im, [$dx, $dy - 6, $dx + 6, $dy, $dx, $dy + 6, $dx - 6, $dy], $gl);
                imagepolygon($im, [$dx, $dy - 6, $dx + 6, $dy, $dx, $dy + 6, $dx - 6, $dy], $gd);
                // a bead at each arm's end
                imagefilledellipse($im, $x + $sx * ($len + 6), $y, 6, 6, $g);
                imagefilledellipse($im, $x, $y + $sy * ($len + 6), 6, 6, $g);
            }
            imagesetthickness($im, 1);
        } catch (\Throwable $e) {
        }
    }

    /** Small gold diamond-and-line flourish centred on ($cx,$y), $w wide. */
    public static function flourish(\GdImage $im, int $cx, int $y, int $w): void
    {
        $g  = self::col($im, self::GOLD);
        $gl = self::col($im, self::GOLD_LIGHT);
        $h  = intdiv($w, 2);
        imageline($im, $cx - $h, $y, $cx - 12, $y, $g);
        imageline($im, $cx + 12, $y, $cx + $h, $y, $g);
        imagefilledpolygon($im, [$cx, $y - 6, $cx + 7, $y, $cx, $y + 6, $cx - 7, $y], $gl);
        imagefilledellipse($im, $cx - $h - 4, $y, 5, 5, $g);
        imagefilledellipse($im, $cx + $h + 4, $y, 5, 5, $g);
    }

    /**
     * The emblem on an embossed gold seal: drop shadow, foil disc lit from
     * the top-left, a bead ring, a cream face with a gold ring, the mark.
     */
    public static function seal(\GdImage $im, int $cx, int $cy, int $d): void
    {
        try {
            $R = intdiv($d, 2);
            // shadow
            for ($i = 8; $i >= 1; $i--) {
                imagefilledellipse($im, $cx + 3, $cy + 6, 2 * $R + 2 * $i, 2 * $R + 2 * $i, self::col($im, [0, 0, 0], 118));
            }
            // foil disc, light from the top-left
            for ($r = $R; $r >= 1; $r--) {
                $t   = 1 - $r / $R;                       // 0 rim .. 1 centre
                $rgb = $t < 0.18 ? self::mix(self::GOLD_DEEP, self::GOLD, $t / 0.18)
                     : self::mix(self::GOLD, self::GOLD_LIGHT, min(1.0, ($t - 0.18) / 0.6));
                $off = (int) round(($R - $r) * 0.18);
                imagefilledellipse($im, $cx - $off, $cy - $off, 2 * $r, 2 * $r, self::col($im, $rgb));
            }
            imageellipse($im, $cx, $cy, 2 * $R, 2 * $R, self::col($im, self::GOLD_DEEP));
            // bead ring
            $beads = 36;
            for ($k = 0; $k < $beads; $k++) {
                $a  = $k / $beads * 2 * M_PI;
                $bx = (int) round($cx + 0.88 * $R * cos($a));
                $by = (int) round($cy + 0.88 * $R * sin($a));
                imagefilledellipse($im, $bx + 1, $by + 1, 5, 5, self::col($im, self::GOLD_DEEP));
                imagefilledellipse($im, $bx, $by, 4, 4, self::col($im, [255, 246, 205]));
            }
            // engraved ring + cream face
            $face = (int) round(0.76 * $R);
            imagefilledellipse($im, $cx, $cy, 2 * $face + 6, 2 * $face + 6, self::col($im, self::GOLD_DEEP));
            imagefilledellipse($im, $cx, $cy, 2 * $face + 2, 2 * $face + 2, self::col($im, self::GOLD_LIGHT));
            imagefilledellipse($im, $cx, $cy, 2 * $face - 2, 2 * $face - 2, self::col($im, self::CREAM));
            // inner shade at the top of the face, so the face reads recessed
            for ($i = 0; $i < 6; $i++) {
                imagearc($im, $cx, $cy + 1, 2 * $face - 4 - 2 * $i, 2 * $face - 4 - 2 * $i, 200, 340, self::col($im, self::GOLD_DEEP, 100 + 4 * $i));
            }
            $box = (int) round($face * 1.66);
            DocArt::mark($im, $cx - intdiv($box, 2), $cy - intdiv($box, 2), $box, $box, false, '', 'center');
        } catch (\Throwable $e) {
        }
    }
}
