<?php
/**
 * =====================================================================
 *  includes/agentpro-card.php — the ID card and the visiting card.
 *
 *  ONE LAYOUT, TWO PAINTERS
 *  ------------------------
 *  Each card side is built once as an ApScene — a flat list of shapes
 *  (rectangles, curves, hairlines, text, the photo, a QR) in tenths of a
 *  millimetre — and then painted either as SVG (screen, print sheets: the
 *  browser shapes the text and it stays vector-sharp) or with GD as a PNG
 *  (download, WhatsApp). Text is measured with the same TTF files both
 *  painters use, so a long name shrinks to fit identically on both.
 *
 *  Design ("Guilloche Meridian", revised 30 Oct 2026): the real office
 *  logo (logo-mark-tm, with its ™) always sits on WHITE so its blues and
 *  orange stay true; the wordmark comes from company_legal; dark text on
 *  light ground or white text on the deep royal field — never mid-tones
 *  on mid-tones. Gold is only a filament, India + Nepal flags mark the
 *  route, and the one red seal appears only when the KYC is verified
 *  (else an amber "KYC PENDING"). Company facts (CIN, CEO & Director,
 *  24/7 line, offices, features, the WhatsApp bot) all come from
 *  Settings::company(), so the office edits them in one place.
 *
 *  ApCard::pdf() puts all four faces, true size with crop marks, on one
 *  A4 page (api/agentpro-card.php?m=<id>&pdf=1).
 *
 *  Sizes: ID card CR80 portrait 54 × 85.6 mm; visiting card 3.5 × 2 in
 *  (88.9 × 50.8 mm). PNG at 2 px per unit = 1080 × 1712 / 1778 × 1016,
 *  ~500 dpi, drawn at 2× and scaled down so every edge is smooth.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/agentpro.php';
require_once __DIR__ . '/qr.php';

/** A card side as a list of shapes, in units of 0.1 mm. */
final class ApScene
{
    /** @var list<array<string, mixed>> */
    public array $ops = [];

    public function __construct(public readonly float $w, public readonly float $h, public readonly float $radius = 0)
    {
    }

    /** @param array<string, mixed> $o */
    public function rect(float $x, float $y, float $w, float $h, string $fill, float $r = 0, array $o = []): self
    {
        $this->ops[] = ['t' => 'rect', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'fill' => $fill, 'r' => $r] + $o;
        return $this;
    }

    /** Vertical ('v') or horizontal ('h') gradient across a rounded rectangle. */
    public function grad(float $x, float $y, float $w, float $h, string $from, string $to, string $dir = 'v', float $r = 0): self
    {
        $this->ops[] = ['t' => 'grad', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'from' => $from, 'to' => $to, 'dir' => $dir, 'r' => $r];
        return $this;
    }

    public function circle(float $cx, float $cy, float $r, string $fill, float $opacity = 1.0): self
    {
        $this->ops[] = ['t' => 'circle', 'cx' => $cx, 'cy' => $cy, 'r' => $r, 'fill' => $fill, 'op' => $opacity];
        return $this;
    }

    /** A circular stroke. $dash = [on, off] in units for a dotted ring. */
    public function ring(float $cx, float $cy, float $r, float $width, string $color, float $opacity = 1.0, array $dash = []): self
    {
        $this->ops[] = ['t' => 'ring', 'cx' => $cx, 'cy' => $cy, 'r' => $r, 'sw' => $width, 'color' => $color, 'op' => $opacity, 'dash' => $dash];
        return $this;
    }

    /**
     * A filled outline: segments ['M',x,y] ['L',x,y] ['C',x1,y1,x2,y2,x,y] ['Z'].
     *
     * @param list<array<int, mixed>> $segs
     */
    public function shape(array $segs, string $fill, float $opacity = 1.0): self
    {
        $this->ops[] = ['t' => 'shape', 'segs' => $segs, 'fill' => $fill, 'op' => $opacity];
        return $this;
    }

    /** @param list<array{0: float, 1: float}> $pts */
    public function line(array $pts, string $color, float $width = 1, float $opacity = 1.0): self
    {
        $this->ops[] = ['t' => 'line', 'pts' => $pts, 'color' => $color, 'sw' => $width, 'op' => $opacity];
        return $this;
    }

    /**
     * Text, measured now. $anchor: start | middle | end. When $maxWidth is
     * set, the size shrinks (down to 60 %) until it fits.
     */
    public function text(float $x, float $y, string $text, string $font, float $size, string $color,
                         string $anchor = 'start', float $tracking = 0, float $opacity = 1.0, float $maxWidth = 0): self
    {
        if ($text === '') {
            return $this;
        }
        if (preg_match('/\p{Devanagari}/u', $text) === 1) {
            $font = 'deva';
        }
        $w = ApCard::measure($font, $size, $text, $tracking);
        if ($maxWidth > 0 && $w > $maxWidth) {
            $fit = max($size * 0.6, $size * $maxWidth / $w);
            $tracking *= $fit / $size;
            $min = $size * 0.6;
            $size = $fit;
            $w = ApCard::measure($font, $size, $text, $tracking);
            // Glyph widths are not perfectly linear in size: step down until it really fits.
            while ($w > $maxWidth && $size > $min) {
                $tracking *= 0.98;
                $size = max($min, $size * 0.98);
                $w = ApCard::measure($font, $size, $text, $tracking);
            }
        }
        $this->ops[] = ['t' => 'text', 'x' => $x, 'y' => $y, 'text' => $text, 'font' => $font, 'size' => $size,
                        'color' => $color, 'anchor' => $anchor, 'tr' => $tracking, 'op' => $opacity, 'w' => $w, 'max' => $maxWidth];
        return $this;
    }

    /**
     * Text that must fit $maxWidth: one line shrunk a little, else two
     * balanced lines (broken between words), else the second line ends in
     * "…". Returns the baseline of the last line drawn, so what follows can
     * move down when a long agency name takes two lines.
     */
    public function textFit(float $x, float $y, string $text, string $font, float $size, string $color, string $anchor,
                            float $maxWidth, float $tracking = 0, int $maxLines = 2, float $minScale = 0.6): float
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') {
            return $y;
        }
        $w = ApCard::measure($font, $size, $text, $tracking);
        if ($w <= $maxWidth || $maxLines < 2 || $size * $maxWidth / $w >= $size * 0.78) {
            $fitSize = $w <= $maxWidth ? $size : max($size * $minScale, $size * $maxWidth / $w);
            while ($fitSize > $size * $minScale && ApCard::measure($font, $fitSize, $text, $tracking * $fitSize / $size) > $maxWidth) {
                $fitSize = max($size * $minScale, $fitSize * 0.98);
            }
            $this->text($x, $y, self::ellipsis($text, $font, $fitSize, $tracking, $maxWidth), $font, $fitSize, $color, $anchor, $tracking * $fitSize / $size, 1.0, $maxWidth);
            return $y;
        }
        // Two lines, split where the longer line is shortest.
        $words = explode(' ', $text);
        $best = [$text, ''];
        $bestW = PHP_FLOAT_MAX;
        for ($i = 1; $i < count($words); $i++) {
            $a = implode(' ', array_slice($words, 0, $i));
            $b = implode(' ', array_slice($words, $i));
            $m = max(ApCard::measure($font, $size, $a, $tracking), ApCard::measure($font, $size, $b, $tracking));
            if ($m < $bestW) {
                $bestW = $m;
                $best = [$a, $b];
            }
        }
        $fitSize = max($size * $minScale, min($size * 0.9, $size * $maxWidth / max(1, $bestW)));
        // Glyph widths are not perfectly linear in size: step down until both lines really fit.
        while ($fitSize > $size * $minScale
            && max(ApCard::measure($font, $fitSize, $best[0], $tracking * $fitSize / $size),
                   ApCard::measure($font, $fitSize, $best[1], $tracking * $fitSize / $size)) > $maxWidth) {
            $fitSize = max($size * $minScale, $fitSize * 0.98);
        }
        $tr = $tracking * $fitSize / $size;
        $lh = $fitSize * 1.12;
        $y0 = $y - $lh * 0.42;
        $this->text($x, $y0, self::ellipsis($best[0], $font, $fitSize, $tr, $maxWidth), $font, $fitSize, $color, $anchor, $tr, 1.0, $maxWidth);
        $this->text($x, $y0 + $lh, self::ellipsis($best[1], $font, $fitSize, $tr, $maxWidth), $font, $fitSize, $color, $anchor, $tr, 1.0, $maxWidth);
        return $y0 + $lh;
    }

    /** Shorten with "…" until it fits (only when even the smallest size is too wide). */
    private static function ellipsis(string $t, string $font, float $size, float $tr, float $max): string
    {
        if (ApCard::measure($font, $size, $t, $tr) <= $max) {
            return $t;
        }
        while (mb_strlen($t) > 1 && ApCard::measure($font, $size, $t . '…', $tr) > $max) {
            $t = mb_substr($t, 0, -1);
        }
        return rtrim($t) . '…';
    }

    /** A photo, cropped to cover the box. $shape: 'round' (radius $r) or 'circle'. */
    public function image(float $x, float $y, float $w, float $h, string $abs, float $r = 0, string $shape = 'round'): self
    {
        $this->ops[] = ['t' => 'image', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'abs' => $abs, 'r' => $r, 'shape' => $shape];
        return $this;
    }

    /**
     * A site picture (the office logo, a flag) fitted INSIDE the box, its
     * transparency kept. $rel is relative to the web root, e.g.
     * 'assets/img/logo-mark-tm.png'. $align: start | middle | end.
     * A missing file draws nothing.
     */
    public function art(float $x, float $y, float $w, float $h, string $rel, string $align = 'middle'): self
    {
        $abs = ApCard::assetFile($rel);
        $sz  = $abs !== '' ? @getimagesize($abs) : false;
        if ($sz === false || $sz[0] < 1 || $sz[1] < 1) {
            return $this;
        }
        $k  = min($w / $sz[0], $h / $sz[1]);
        $dw = $sz[0] * $k;
        $dh = $sz[1] * $k;
        $dx = match ($align) {
            'start' => $x,
            'end'   => $x + $w - $dw,
            default => $x + ($w - $dw) / 2,
        };
        $this->ops[] = ['t' => 'art', 'x' => $dx, 'y' => $y + ($h - $dh) / 2, 'w' => $dw, 'h' => $dh, 'rel' => $rel, 'abs' => $abs];
        return $this;
    }

    /** @param array<int, array<int, bool>> $matrix */
    public function qr(float $x, float $y, float $size, array $matrix, string $fg = '#0F1A36'): self
    {
        $this->ops[] = ['t' => 'qr', 'x' => $x, 'y' => $y, 'size' => $size, 'm' => $matrix, 'fg' => $fg];
        return $this;
    }

    /** Everything until unclip() is cut to this rectangle. */
    public function clip(float $x, float $y, float $w, float $h): self
    {
        $this->ops[] = ['t' => 'clip', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
        return $this;
    }

    public function unclip(): self
    {
        $this->ops[] = ['t' => 'unclip'];
        return $this;
    }
}

final class ApCard
{
    public const SIDES = ['id-front', 'id-back', 'vc-front', 'vc-back'];

    /** key => [file, css family, weight, style] */
    private const FONTS = [
        'sans'    => ['agentpro/fonts/Outfit-Regular.ttf', 'APOutfit', 400, 'normal'],
        'sansB'   => ['agentpro/fonts/Outfit-Bold.ttf', 'APOutfit', 700, 'normal'],
        'mono'    => ['agentpro/fonts/GeistMono-Regular.ttf', 'APGeistMono', 400, 'normal'],
        'monoB'   => ['agentpro/fonts/GeistMono-Bold.ttf', 'APGeistMono', 700, 'normal'],
        'caps'    => ['agentpro/fonts/ArsenalSC-Regular.ttf', 'APArsenal', 400, 'normal'],
        'deva'    =>['fonts/NotoSansDevanagari.ttf', 'APDeva', 400, 'normal'],
    ];

    /* The palette of the philosophy: a deep royal field, ivory ground, gold filament, one red seal. */
    private const NAVY   = '#13224F';
    private const ROYAL  = '#1C2F6B';
    private const INK    = '#0F1A36';
    private const GOLD   = '#C9973A';
    private const GOLD_L = '#E9C77B';
    private const GOLD_D = '#946A1E';
    private const IVORY  = '#FBF9F3';
    private const MUTED  = '#5D6680';
    private const RED    = '#B3122D';
    private const WA     = '#169C4F';
    private const AMBER  = '#B45309';

    /** Units per millimetre (the scene is in tenths of a mm). */
    private const UNITS_PER_MM = 10;

    /* =================================================================
     *  Fonts and measuring
     * ================================================================= */

    public static function fontFile(string $key): string
    {
        $rel = self::FONTS[$key][0] ?? self::FONTS['sans'][0];
        $abs = ASSET_PATH . '/' . $rel;
        if (!is_file($abs)) {
            // Tests and odd installs: the add-on's own copy next to this file.
            $alt = dirname(__DIR__) . '/assets/' . $rel;
            $abs = is_file($alt) ? $alt : ASSET_PATH . '/' . self::FONTS['sans'][0];
        }
        return $abs;
    }

    /** @var array<string, float> */
    private static array $advCache = [];

    /** Width of $text at $px (font size in units), with $tracking added between letters. */
    public static function measure(string $font, float $px, string $text, float $tracking = 0): float
    {
        $file = self::fontFile($font);
        $pt   = $px * 0.75;                                   // GD sets type at 96 dpi
        if ($font === 'deva') {
            $text = self::devShape($text);
        }
        if ($tracking == 0.0 && !self::needsFallback($font, $text)) {
            $b = imagettfbbox($pt, 0, $file, $text);
            return $b === false ? 0.0 : (float) ($b[2] - $b[0]);
        }
        $w = 0.0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $c) {
            $w += self::advance(self::glyphFile($font, $file, $c), $pt, $c);
        }
        return $w + $tracking * max(0, count($chars) - 1);
    }

    /** Only Geist Mono carries the rupee sign: GD has no font fallback, so ₹ is drawn from it. */
    private static function needsFallback(string $font, string $text): bool
    {
        return !str_starts_with($font, 'mono') && str_contains($text, '₹');
    }

    private static function glyphFile(string $font, string $file, string $c): string
    {
        if ($c !== '₹' || str_starts_with($font, 'mono')) {
            return $file;
        }
        return self::fontFile($font === 'sansB' ? 'monoB' : 'mono');
    }

    /** One character's advance, including a space (whose ink is empty). */
    private static function advance(string $file, float $pt, string $c): float
    {
        $k = $file . '|' . $pt . '|' . $c;
        if (!isset(self::$advCache[$k])) {
            $a = imagettfbbox($pt, 0, $file, 'H' . $c . 'H');
            $b = imagettfbbox($pt, 0, $file, 'HH');
            self::$advCache[$k] = ($a === false || $b === false) ? $pt : (float) (($a[2] - $a[0]) - ($b[2] - $b[0]));
        }
        return self::$advCache[$k];
    }

    /** GD draws Devanagari without shaping; the site's dev_shape() fixes the i-vowel. */
    private static function devShape(string $text): string
    {
        return function_exists('dev_shape') ? dev_shape($text) : $text;
    }

    /* =================================================================
     *  Building the four sides
     * ================================================================= */

    /** @param array<string, mixed> $m AgentPro::member() */
    public static function scene(array $m, string $side): ApScene
    {
        return match ($side) {
            'id-front' => self::idFront($m),
            'id-back'  => self::idBack($m),
            'vc-front' => self::vcFront($m),
            'vc-back'  => self::vcBack($m),
            default    => throw new RuntimeException('Unknown card side.'),
        };
    }

    /**
     * A QR matrix that always fits the site's encoder (version ≤ 10): the
     * first text that fits at the preferred error correction, else at a
     * lower one. Returns [matrix, ecc] — the caller only overlays a logo
     * when it got ECC_H.
     *
     * $maxVersion caps the density of every text but the last: a code that
     * would come out bigger is skipped for the next, shorter text. On a
     * 27 mm visiting-card QR, version 7 still gives 0.6 mm modules, which a
     * phone reads at arm's length with a logo on top; version 10 does not.
     *
     * @param list<string> $texts most wanted first
     * @return array{0: array<int, array<int, bool>>, 1: int}
     */
    public static function safeQr(array $texts, int $ecc = QrCode::ECC_M, int $maxVersion = 40): array
    {
        $levels = array_values(array_unique([$ecc, QrCode::ECC_M, QrCode::ECC_L]));
        $last = count($texts) - 1;
        foreach (array_values($texts) as $i => $t) {
            foreach ($levels as $lvl) {
                try {
                    $mx = QrCode::matrix($t, $lvl);
                } catch (RuntimeException $e) {
                    continue;                               // too long at this level — try the next
                }
                if ($i < $last && count($mx) > 17 + 4 * $maxVersion) {
                    continue 2;                             // fits, but too dense — the next text
                }
                return [$mx, $lvl];
            }
        }
        return [QrCode::matrix(rtrim(APP_URL, '/'), QrCode::ECC_M), QrCode::ECC_M];
    }

    /** The public verify link printed in the ID card's QR. */
    public static function verifyUrl(array $m): string
    {
        return appUrl('id.php?c=' . rawurlencode((string) $m['cardToken']));
    }

    /* =================================================================
     *  Company identity, shared by every face
     * ================================================================= */

    public const LOGO       = 'assets/img/logo-mark-tm.png';
    public const FLAG_IN    = 'assets/img/flag-in.png';
    public const FLAG_NP    = 'assets/img/flag-np.png';

    /** An asset path under the web root ('' when the file is missing). */
    public static function assetFile(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return '';
        }
        $abs = dirname(__DIR__) . '/' . $rel;
        return is_file($abs) ? $abs : '';
    }

    /**
     * The wordmark from company_legal: "S Hari Global Private Limited" →
     * ['S HARI GLOBAL', 'PRIVATE LIMITED']. A name without a company suffix
     * stays on one line.
     *
     * @return array{0: string, 1: string}
     */
    public static function wordmark(array $co): array
    {
        $legal = trim((string) preg_replace('/\s+/u', ' ', (string) ($co['legal'] ?? '')));
        if ($legal === '') {
            $legal = 'S Hari Global Private Limited';
        }
        if (preg_match('/^(.+?)[\s,]+(private\s+limited|pvt\.?\s*ltd\.?|limited|ltd\.?|llp)$/iu', $legal, $mm) === 1) {
            $tail = preg_match('/^p/iu', $mm[2]) === 1 ? 'PRIVATE  LIMITED' : mb_strtoupper(rtrim($mm[2], '.'));
            return [mb_strtoupper(trim($mm[1])), $tail];
        }
        return [mb_strtoupper($legal), ''];
    }

    /**
     * The office numbers from company_counters ("Mehsana +91 91048 01507 ·
     * Ahmedabad …") as [town, number] pairs. A number that is the 24/7 line
     * (already printed beside the CEO) or repeats is left out, so the
     * Mehsana / Ahmedabad / Surat offices come first.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function offices(array $co, int $max = 3): array
    {
        $digits = static fn(string $v): string => substr(preg_replace('/\D/', '', $v) ?? '', -10);
        $seen = [$digits((string) ($co['phone247'] ?? '')) => true];
        $parts = preg_split('/\s*(?:·|\||;|\n)\s*/u', (string) ($co['counters'] ?? '')) ?: [];
        $out = [];
        foreach ($parts as $c) {
            // Anything that is not a letter, digit or bracket is trimmed off the ends.
            $c = trim((string) preg_replace('/^[^\p{L}\p{N}+(]+|[^\p{L}\p{N})]+$/u', '', $c));
            if ($c === '' || preg_match('/^(.*?)\s*(\+?\d[\d\s-]{6,})$/u', $c, $mm) !== 1) {
                continue;
            }
            $key = $digits($mm[2]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [trim($mm[1]) !== '' ? trim($mm[1]) : 'Office', trim($mm[2])];
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    /** "AGENT CODE" / "STAFF NO." — the label of the number the card carries. */
    public static function codeLabel(array $m): string
    {
        return !empty($m['isAgent']) ? 'AGENT CODE' : 'STAFF NO.';
    }

    public static function kycVerified(array $m): bool
    {
        return (string) ($m['kycStatus'] ?? '') === 'verified';
    }

    /** The office looked at the ID and refused it (admin_profiles.kyc_status = 'rejected'). */
    public static function kycRejected(array $m): bool
    {
        return (string) ($m['kycStatus'] ?? '') === 'rejected';
    }

    /**
     * The line under the company name on the visiting card: the member's own
     * vc_tagline when they typed one, else the company's card line. AgentPro::member()
     * fills 'tagline' with ap_card_tagline when vc_tagline is empty - that is the
     * service line the card already prints top right, so it counts as "not set".
     */
    private static function vcTagline(array $m): string
    {
        $tag = trim((string) ($m['tagline'] ?? ''));
        if ($tag === '' || $tag === trim(Settings::getString('ap_card_tagline', 'India & Nepal Travel Service'))) {
            $co  = (array) ($m['company'] ?? []);
            $tag = trim((string) ($co['cardTagline'] ?? $co['tagline'] ?? ''));
        }
        return $tag;
    }

    /** The company's WhatsApp chat link (the bot on wa_order_number). */
    public static function chatUrl(array $m): string
    {
        $d = (string) ($m['company']['chat'] ?? '');
        if ($d === '') {
            $d = Settings::chatWhatsApp();
        }
        return 'https://wa.me/' . $d;
    }

    /** India and Nepal flags side by side, $h tall, starting at $x ('start') or ending there ('end'). */
    private static function flags(ApScene $s, float $x, float $y, float $h, string $align = 'start'): void
    {
        $wIn = $h * 1.5;
        $wNp = $h * 0.82;
        $gap = $h * 0.28;
        $x0 = $align === 'end' ? $x - ($wIn + $gap + $wNp) : $x;
        // A hairline frame keeps India's white band visible on a white ground.
        $s->rect($x0 - 1, $y - 1, $wIn + 2, $h + 2, '#C9CEDB', 1.5);
        $s->art($x0, $y, $wIn, $h, self::FLAG_IN, 'start');
        $s->art($x0 + $wIn + $gap, $y, $wNp, $h, self::FLAG_NP, 'start');
    }

    /** The KYC mark at the photo's lower right: the red seal when verified, else an amber pill. */
    private static function kycMark(ApScene $s, array $m, float $cx, float $cy, float $photoMidX, float $photoBottom): void
    {
        if (self::kycVerified($m)) {
            $s->circle($cx, $cy, 27, '#FFFFFF');
            $s->circle($cx, $cy, 23, self::RED);
            $s->ring($cx, $cy, 18.5, 1.2, '#FFFFFF', 0.8);
            $s->line([[$cx - 10, $cy + 0.5], [$cx - 2.5, $cy + 8], [$cx + 11, $cy - 8]], '#FFFFFF', 4.4);
            return;
        }
        // A refused ID is not "in progress": say so, in red.
        $rejected = self::kycRejected($m);
        $t = $rejected ? 'KYC  NOT  VERIFIED' : 'KYC  PENDING';
        $w = self::measure('caps', 14, $t, 1.4) + 30;
        $s->rect($photoMidX - $w / 2, $photoBottom - 17, $w, 32, '#FFFFFF', 16);
        $s->rect($photoMidX - $w / 2 + 2, $photoBottom - 15, $w - 4, 28, $rejected ? self::RED : self::AMBER, 14);
        $s->text($photoMidX, $photoBottom + 4.5, $t, 'caps', 14, '#FFFFFF', 'middle', 1.4);
    }

    /** A QR on a white plate; $badge puts the green WhatsApp disc in its heart (only at ECC H). */
    private static function qrPlate(ApScene $s, float $x, float $y, float $size, array $texts, string $rule, bool $badge = false, int $maxVersion = 40): void
    {
        $pad = $size * 0.075;
        $s->rect($x, $y, $size, $size, '#FFFFFF', $size * 0.08, ['stroke' => $rule, 'sw' => 2]);
        [$mx, $lvl] = self::safeQr($texts, $badge ? QrCode::ECC_H : QrCode::ECC_M, $maxVersion);
        $q = $size - 2 * $pad;
        $s->qr($x + $pad, $y + $pad, $q, $mx, self::INK);
        if ($badge && $lvl === QrCode::ECC_H) {
            $c = $x + $size / 2;
            $b = $q * 0.2;
            $s->rect($c - $b / 2, $y + $size / 2 - $b / 2, $b, $b, '#FFFFFF', $b * 0.22);
            self::waGlyph($s, $c, $y + $size / 2, $b * 0.4);
        }
    }

    private static function idFront(array $m): ApScene
    {
        $co = $m['company'];
        [$w1, $w2] = self::wordmark($co);
        $s = new ApScene(540, 856, 32);
        $s->rect(0, 0, 540, 856, '#FFFFFF');
        self::rosette($s, 270, 560, 236, self::ROYAL, 0.05, 30, 7, 0.0);

        // Letterhead on white: the real logo (with its ™), the wordmark, the CIN.
        $s->art(18, 14, 156, 96, self::LOGO, 'start');
        $s->text(186, 54, $w1, 'sansB', 31, self::NAVY, 'start', 0.6, 1.0, 336);
        if ($w2 !== '') {
            $s->text(187, 80, $w2, 'caps', 15, self::GOLD_D, 'start', 3.6, 1.0, 336);
        }
        $s->text(187, 104, 'CIN ' . (string) ($co['cin'] ?? ''), 'mono', 14, self::MUTED, 'start', 0, 1.0, 336);

        // The route band: India · service · Nepal.
        $s->rect(0, 122, 540, 50, self::NAVY);
        $s->rect(0, 172, 540, 3, self::GOLD);
        self::flags($s, 18, 133, 28);
        $s->text(304, 154, mb_strtoupper((string) ($co['service'] ?? 'India & Nepal Travel Service')), 'caps', 15, '#FFFFFF', 'middle', 2.2, 1.0, 380);

        // The portrait plate and the KYC mark.
        $s->rect(166, 194, 208, 250, '#FFFFFF', 22, ['stroke' => self::GOLD, 'sw' => 2.4]);
        self::portrait($s, $m, 176, 204, 188, 230, 16);
        self::kycMark($s, $m, 368, 426, 270, 444);

        // The name sits clear of the KYC mark; a two-line agency name grows downwards.
        $last = $s->textFit(270, 506, (string) $m['name'], 'sansB', 38, self::INK, 'middle', 484);
        $y = 536 + ($last - 506);
        if (!empty($m['contact'])) {
            $s->text(270, $y, (string) $m['contact'], 'sans', 17, self::MUTED, 'middle', 0, 1.0, 470);
            $y += 25;
        }
        $s->textFit(270, $y, mb_strtoupper((string) $m['designation']), 'caps', 16.5, self::GOLD_D, 'middle', 480, 3.6, 1);

        // The registry number: AGENT CODE SHG-0027 / STAFF NO. SHG-S-0005.
        $idNo  = $m['idNo'] !== '' ? (string) $m['idNo'] : 'PENDING';
        $label = self::codeLabel($m);
        $iw = self::measure('monoB', 24, $idNo, 1.2) + self::measure('caps', 14, $label, 1.6) + 60;
        $py = $y + 18;
        $s->rect(270 - $iw / 2, $py, $iw, 46, self::NAVY, 23);
        $s->text(270 - $iw / 2 + 22, $py + 29, $label, 'caps', 14, self::GOLD_L, 'start', 1.6);
        $s->text(270 + $iw / 2 - 22, $py + 32, $idNo, 'monoB', 24, '#FFFFFF', 'end', 1.2);

        // Four facts.
        $rows = [
            ['MOBILE', (string) ($m['callDisplay'] ?: $m['waDisplay'])],
            ['BRANCH', (string) ($m['branch'] !== '' ? $m['branch'] : 'Head Office')],
            ['VALID TILL', $m['validUntil'] !== '' ? date('M Y', (int) strtotime((string) $m['validUntil'])) : '—'],
            [$m['bloodGroup'] !== '' ? 'BLOOD GROUP' : 'MEMBER SINCE', $m['bloodGroup'] !== '' ? (string) $m['bloodGroup'] : ($m['joined'] !== '' ? date('M Y', (int) strtotime((string) $m['joined'])) : '—')],
        ];
        $gy = min(max($py + 82, 664), 712);
        foreach ($rows as $i => [$label, $value]) {
            $x = $i % 2 === 0 ? 46 : 300;
            $ry = $gy + intdiv($i, 2) * 50;
            $s->text($x, $ry, $label, 'caps', 14, self::MUTED, 'start', 1.8);
            $s->text($x, $ry + 23, $value, 'sans', 18.5, self::INK, 'start', 0, 1.0, $i % 2 === 0 ? 240 : 200);
        }

        // Classification band with security micro-text and the website.
        $s->rect(0, 790, 540, 66, self::NAVY);
        $s->rect(0, 790, 540, 2.4, self::GOLD);
        $s->clip(0, 793, 540, 9);
        $s->text(4, 800, str_repeat('SHREEHARIGLOBAL·', 12), 'mono', 5.6, '#FFFFFF', 'start', 0.4, 0.28);
        $s->unclip();
        $class = $m['isAgent'] ? ($m['isOrg'] ? 'AUTHORISED  AGENCY' : 'AUTHORISED  AGENT') : 'OFFICE  STAFF';
        $s->text(270, 826, $class, 'caps', 19, '#FFFFFF', 'middle', 6);
        $s->text(270, 847, 'www.' . self::web($co), 'mono', 14, self::GOLD_L, 'middle', 0.4);
        return $s;
    }

    /** "shreehariglobal.in" — the website without scheme or www. */
    private static function web(array $co): string
    {
        $w = (string) preg_replace('~^(https?://)?(www\.)?~i', '', trim((string) ($co['web'] ?? '')));
        return rtrim($w !== '' ? $w : 'shreehariglobal.in', '/');
    }

    private static function idBack(array $m): ApScene
    {
        $co = $m['company'];
        $s = new ApScene(540, 856, 32);
        $s->rect(0, 0, 540, 856, '#FFFFFF');
        self::rosette($s, 270, 300, 220, self::ROYAL, 0.045, 30, 9, 0.4);

        $s->rect(0, 0, 540, 62, self::NAVY);
        $s->clip(0, 0, 540, 62);
        self::waves($s, 0, 0, 540, 62, '#FFFFFF', 0.06, 6, 12);
        $s->unclip();
        $s->rect(0, 62, 540, 2.4, self::GOLD);
        $s->text(270, 41, (string) ($co['mantra'] ?? 'ॐ नमो नारायणाय'), 'deva', 20, self::GOLD_L, 'middle', 0, 1.0, 480);

        // Two codes: is this card real? · talk to the company on WhatsApp.
        self::qrPlate($s, 36, 84, 214, [self::verifyUrl($m)], self::GOLD);
        self::qrPlate($s, 290, 84, 214, [self::chatUrl($m)], self::WA, true);
        $s->text(143, 324, 'SCAN TO VERIFY', 'caps', 15.5, self::INK, 'middle', 1.6);
        $s->text(143, 346, preg_replace('#^https?://(www\.)?#', '', appUrl('id.php')) ?? '', 'mono', 14, self::MUTED, 'middle', 0, 1.0, 228);
        $s->text(397, 324, 'SCAN TO CHAT', 'caps', 15.5, self::WA, 'middle', 1.6);
        $s->text(397, 346, 'WA ' . AgentPro::displayPhone(substr(self::chatUrl($m), 14)), 'mono', 14, self::MUTED, 'middle', 0, 1.0, 228);

        $rows = [
            ['HOLDER', (string) $m['name']],
            [self::codeLabel($m), $m['idNo'] !== '' ? (string) $m['idNo'] : 'PENDING'],
            ['ISSUED', $m['cardIssued'] !== '' ? date('d M Y', (int) strtotime((string) $m['cardIssued'])) : '—'],
            ['VALID TILL', $m['validUntil'] !== '' ? date('d M Y', (int) strtotime((string) $m['validUntil'])) : '—'],
        ];
        if ($m['emergencyPhone'] !== '') {
            $rows[] = ['EMERGENCY', trim((string) $m['emergencyName'] . ' ' . AgentPro::displayPhone(AgentPro::intlDigits((string) $m['emergencyPhone'], (string) $m['countryCode'])))];
        }
        $y = 384;
        foreach ($rows as $i => [$label, $value]) {
            $code = $i === 1;
            $s->text(40, $y, $label, 'caps', 14, self::MUTED, 'start', 1.2);
            $s->textFit(184, $y, $value, $code ? 'monoB' : 'sans', $code ? 16 : 16.5, self::INK, 'start', 316, 0, 1);
            $y += 27;
        }

        // The company: CEO & Director, the 24/7 line, the offices.
        $y += 4;
        $s->rect(40, $y - 14, 460, 1.2, self::GOLD, 0, ['op' => 0.7]);
        $y += 10;
        $s->text(40, $y, 'CEO & DIRECTOR', 'caps', 14, self::MUTED, 'start', 1.2);
        $s->textFit(184, $y, (string) ($co['ceo'] ?? ''), 'sansB', 16.5, self::INK, 'start', 316, 0, 1);
        $y += 27;
        $s->text(40, $y, '24 × 7 HELP', 'caps', 14, self::MUTED, 'start', 1.2);
        $s->text(184, $y, (string) ($co['phone247'] ?? ''), 'monoB', 16, self::RED, 'start', 0.3);
        $y += 27;
        $offices = self::offices($co, 3);
        if ($offices !== []) {
            $s->text(40, $y, 'OFFICES', 'caps', 14, self::MUTED, 'start', 1.2);
            foreach ($offices as [$town, $num]) {
                $s->text(184, $y, $town, 'sans', 15.5, self::INK, 'start', 0, 1.0, 120);
                $s->text(500, $y, $num, 'mono', 15, self::INK, 'end', 0.2);
                $y += 23;
            }
        }

        // Return address + signatory.
        $y = max($y + 10, 704);
        $s->text(40, $y, 'If found, please return to', 'sans', 14, self::MUTED);
        // Print minimum is 14 units (~1.3 mm): the address column gives the
        // signatory block 182 units, enough for "AUTHORISED SIGNATORY" and the CEO's name at 14.
        $addr = self::wrap((string) ($co['address'] ?? ''), 'sans', 14, 270, 3);
        foreach ($addr as $i => $ln) {
            $s->text(40, $y + 20 + $i * 18, $ln, 'sans', 14, self::INK);
        }
        $sig = trim(Settings::getString('ap_card_signature', '')) ?: (string) ($co['ceo'] ?? $co['operator'] ?? '');
        $s->rect(318, $y + 16, 182, 1.4, self::INK, 0, ['op' => 0.7]);
        $s->text(409, $y + 34, 'AUTHORISED SIGNATORY', 'caps', 14, self::MUTED, 'middle', 0.3, 1.0, 182);
        $s->text(409, $y + 53, $sig, 'sans', 14, self::INK, 'middle', 0, 1.0, 182);

        $s->rect(0, 790, 540, 66, self::NAVY);
        $s->rect(0, 790, 540, 2.4, self::GOLD);
        $s->text(270, 820, 'WWW.' . mb_strtoupper(self::web($co)), 'caps', 16, '#FFFFFF', 'middle', 3.6);
        $s->text(270, 845, 'CIN ' . (string) ($co['cin'] ?? ''), 'mono', 14, self::GOLD_L, 'middle', 0.4);
        return $s;
    }

    /** @return array<string, string> */
    private static function vcPalette(string $theme): array
    {
        return match ($theme) {
            'gold'  => ['panel' => '#F3EBD8', 'panelInk' => self::ROYAL, 'panelSub' => self::GOLD_D, 'panelLine' => self::ROYAL,
                        'ground' => '#FFFFFF', 'ink' => self::INK, 'muted' => self::MUTED, 'accent' => self::GOLD_D, 'rule' => self::GOLD,
                        'back' => '#F3EBD8', 'backInk' => self::ROYAL, 'backSub' => self::GOLD_D, 'pattern' => self::ROYAL],
            'night' => ['panel' => '#0C0D12', 'panelInk' => '#FFFFFF', 'panelSub' => self::GOLD_L, 'panelLine' => '#FFFFFF',
                        'ground' => '#15171F', 'ink' => '#F4F1E8', 'muted' => '#A6A9B6', 'accent' => self::GOLD_L, 'rule' => self::GOLD,
                        'back' => '#0C0D12', 'backInk' => '#FFFFFF', 'backSub' => self::GOLD_L, 'pattern' => '#FFFFFF'],
            default => ['panel' => self::NAVY, 'panelInk' => '#FFFFFF', 'panelSub' => self::GOLD_L, 'panelLine' => '#FFFFFF',
                        'ground' => self::IVORY, 'ink' => self::INK, 'muted' => self::MUTED, 'accent' => self::GOLD_D, 'rule' => self::GOLD,
                        'back' => self::NAVY, 'backInk' => '#FFFFFF', 'backSub' => self::GOLD_L, 'pattern' => '#FFFFFF'],
        };
    }

    private static function vcFront(array $m): ApScene
    {
        $p  = self::vcPalette((string) $m['theme']);
        $co = $m['company'];
        [$w1, $w2] = self::wordmark($co);
        $s = new ApScene(889, 508, 18);
        $s->rect(0, 0, 889, 508, '#FFFFFF');

        // Letterhead on white, whatever the theme: the logo's colours stay true.
        $s->art(26, 16, 176, 112, self::LOGO, 'start');
        $s->text(214, 62, $w1, 'sansB', 38, self::NAVY, 'start', 0.8, 1.0, 470);
        if ($w2 !== '') {
            $s->text(216, 90, $w2, 'caps', 15.5, self::GOLD_D, 'start', 4.2, 1.0, 400);
        }
        $s->text(215, 118, self::vcTagline($m), 'sans', 16, self::MUTED, 'start', 0.2, 1.0, 420);
        self::flags($s, 863, 26, 30, 'end');
        $s->text(863, 90, (string) ($co['service'] ?? ''), 'caps', 14, self::NAVY, 'end', 0.6, 1.0, 230);
        $s->text(863, 118, 'CIN ' . (string) ($co['cin'] ?? ''), 'mono', 14, self::MUTED, 'end', 0, 1.0, 230);

        // The theme's field, entered by the meridian: a gold ribbon over a gentle curve.
        $s->shape([['M', 0, 158], ['C', 260, 134, 560, 170, 889, 142], ['L', 889, 508], ['L', 0, 508], ['Z']], $p['panel']);
        $s->clip(0, 136, 889, 372);
        self::waves($s, 0, 136, 889, 372, $p['panelLine'], 0.05, 26, 15);
        $s->unclip();
        $s->shape([['M', 0, 152], ['C', 260, 128, 560, 164, 889, 136], ['L', 889, 142], ['C', 560, 170, 260, 134, 0, 158], ['Z']], $p['rule']);

        // The person — white on the royal field.
        $x = 36;
        $last = $s->textFit($x, 214, (string) $m['name'], 'sansB', 40, $p['panelInk'], 'start', 600);
        $y = 246 + ($last - 214);
        if (!empty($m['contact'])) {
            $s->text($x, $y, (string) $m['contact'], 'sans', 17, $p['panelInk'], 'start', 0, 0.85, 600);
            $y += 25;
        }
        $s->textFit($x, $y, mb_strtoupper((string) $m['designation']), 'caps', 16, $p['panelSub'], 'start', 600, 3.6, 1);
        $y += 32;
        $label = self::codeLabel($m);
        $s->text($x, $y, $label, 'caps', 14, $p['panelSub'], 'start', 1.4);
        $s->text($x + self::measure('caps', 14, $label, 1.4) + 12, $y + 1, $m['idNo'] !== '' ? (string) $m['idNo'] : 'PENDING',
            'monoB', 19, $p['panelInk'], 'start', 0.8);

        // Contact rows: WA first and strongest.
        $rows = [];
        if ($m['wa'] !== '') {
            $rows[] = ['WA', (string) $m['waDisplay'], 'wa'];
        }
        if ($m['call'] !== '' && $m['call'] !== $m['wa']) {
            $rows[] = ['TEL', (string) $m['callDisplay'], 'n'];
        }
        if ($m['email'] !== '') {
            $rows[] = ['MAIL', (string) $m['email'], 'n'];
        }
        $place = trim((string) $m['branch'] . ($m['address'] !== '' && $m['branch'] === '' ? ' ' . $m['address'] : ''));
        if ($place !== '') {
            $rows[] = ['AT', $place, 'n'];
        }
        $ry = $y + 42;
        foreach ($rows as [$label, $value, $kind]) {
            if ($ry > 448) {
                break;                                   // the strip below is the floor
            }
            if ($kind === 'wa') {
                self::waGlyph($s, $x + 11, $ry - 8, 12);
                $s->text($x + 32, $ry, 'WA:', 'sansB', 22, $p['panelSub']);
                $lw = self::measure('sansB', 22, 'WA:');
                $s->text($x + 32 + $lw + 8, $ry, $value, 'sansB', 22, $p['panelInk'], 'start', 0.2, 1.0, 560);
            } else {
                $s->text($x, $ry, $label, 'caps', 14, $p['panelSub'], 'start', 1.4);
                $s->text($x + 64, $ry, $value, 'sans', 18, $p['panelInk'], 'start', 0, 1.0, 568);
            }
            $ry += 34;
        }

        // Small WhatsApp QR so a one-sided print still opens the chat (just the number).
        if ($m['wa'] !== '') {
            self::qrPlate($s, 706, 194, 152, ['https://wa.me/' . $m['wa']], $p['rule']);
            $s->text(782, 372, 'SCAN · WHATSAPP', 'caps', 14, $p['panelSub'], 'middle', 1, 1.0, 170);
        }

        // The strip: what the coaches offer, and the website.
        $s->rect(0, 462, 889, 1.4, $p['rule'], 0, ['op' => 0.8]);
        $s->text($x, 492, mb_strtoupper(self::featureLine($co)), 'caps', 14.5, $p['panelInk'], 'start', 1, 0.92, 596);
        $s->text(858, 492, 'www.' . self::web($co), 'sansB', 16, $p['panelSub'], 'end', 0.4, 1.0, 240);
        return $s;
    }

    /** "AC Sleeper | Live GPS Tracking | USB / Type-C Charging" → "AC Sleeper · Live GPS Tracking · …". */
    private static function featureLine(array $co): string
    {
        return implode('  ·  ', self::features($co));
    }

    /** @return list<string> */
    private static function features(array $co): array
    {
        $parts = preg_split('/\s*(?:\||·|;|\n)\s*/u', (string) ($co['features'] ?? '')) ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn(string $v): bool => $v !== ''));
    }

    private static function vcBack(array $m): ApScene
    {
        $p  = self::vcPalette((string) $m['theme']);
        $co = $m['company'];
        [$w1, $w2] = self::wordmark($co);
        $s = new ApScene(889, 508, 18);
        $s->rect(0, 0, 889, 508, $p['back']);
        self::waves($s, 0, 0, 889, 508, $p['pattern'], 0.04, 34, 15);
        self::rosette($s, 700, 254, 230, $p['pattern'], 0.05, 32, 8, 0.6);

        // Left: the person's own WhatsApp QR (with the greeting when the code stays small),
        // then WHO this card belongs to — a back printed alone is never anonymous.
        $chat = $m['waLink'] !== '' ? [(string) $m['waLink'], 'https://wa.me/' . $m['wa']] : [self::chatUrl($m)];
        self::qrPlate($s, 50, 34, 268, $chat, $p['rule'], true, 7);
        $y = $s->textFit(184, 340, (string) $m['name'], 'sansB', 24, $p['backInk'], 'middle', 300) + 30;
        $s->text(184, $y, self::codeLabel($m) . '  ' . ($m['idNo'] !== '' ? (string) $m['idNo'] : 'PENDING'), 'monoB', 15.5, $p['backSub'], 'middle', 0.4, 1.0, 300);
        $s->text(184, $y + 28, $m['waLink'] !== '' ? 'Scan to chat on WhatsApp' : 'Scan to chat with our office', 'sans', 15, $p['backInk'], 'middle', 0, 0.9, 300);
        if ($m['wa'] !== '') {
            $s->text(184, $y + 54, 'WA ' . (string) $m['waDisplay'], 'mono', 15, $p['backInk'], 'middle', 0.3, 1.0, 300);
        }

        // Right: the company — logo on a white plate, wordmark, features, offices, the CEO.
        $x = 372;
        $s->rect($x, 34, 136, 84, '#FFFFFF', 12);
        $s->art($x + 8, 40, 120, 72, self::LOGO);
        $s->text($x + 152, 70, $w1, 'sansB', 27, $p['backInk'], 'start', 0.6, 1.0, 330);
        if ($w2 !== '') {
            $s->text($x + 153, 95, $w2, 'caps', 14, $p['backSub'], 'start', 3, 1.0, 330);
        }
        $s->text($x + 153, 118, (string) ($co['service'] ?? ''), 'sans', 15, $p['backInk'], 'start', 0.2, 0.9, 330);
        $s->rect($x, 136, 486, 1.4, $p['rule'], 0, ['op' => 0.8]);

        $y = 164;
        foreach (array_slice(self::features($co), 0, 3) as $f) {
            $s->shape([['M', $x + 4, $y - 11], ['L', $x + 9, $y - 6], ['L', $x + 4, $y - 1], ['L', $x - 1, $y - 6], ['Z']], $p['rule']);
            $s->text($x + 18, $y, mb_strtoupper($f), 'caps', 15, $p['backInk'], 'start', 1.4, 1.0, 470);
            $y += 25;
        }
        $y += 8;
        foreach (self::offices($co, 3) as [$town, $num]) {
            $s->text($x, $y, $town, 'sans', 15, $p['backInk'], 'start', 0, 0.9, 130);
            $s->text($x + 140, $y, $num, 'mono', 15, $p['backInk'], 'start', 0.2);
            $y += 24;
        }
        $y += 8;
        $s->text($x, $y, 'CEO & Director: ' . (string) ($co['ceo'] ?? ''), 'sans', 14.5, $p['backInk'], 'start', 0, 0.95, 486);
        $y += 25;
        $s->text($x, $y, '24 × 7', 'caps', 15, $p['backSub'], 'start', 1);
        $s->text($x + 70, $y, (string) ($co['phone247'] ?? ''), 'monoB', 16.5, $p['backInk'], 'start', 0.2);

        // Bottom right: book on the company's WhatsApp.
        $chatUrl = self::chatUrl($m);
        self::qrPlate($s, 754, 386, 106, [$chatUrl], self::WA);
        $s->text(740, 424, 'BOOK ON WHATSAPP', 'caps', 14, $p['backSub'], 'end', 1);
        $s->text(740, 450, AgentPro::displayPhone(substr($chatUrl, 14)), 'monoB', 17, $p['backInk'], 'end', 0.3);
        $s->text(740, 480, 'www.' . self::web($co), 'sansB', 16, $p['backSub'], 'end', 0.4);
        return $s;
    }

    /* =================================================================
     *  Ornaments
     * ================================================================= */

    /**
     * Machine-turned rosette: $n closed curves r(θ) = R + A·sin(kθ + phase),
     * each a little rotated — the interlace of a banknote.
     *
     * @param array{0: float, 1: float, 2: float, 3: float}|array{} $clip
     */
    private static function rosette(ApScene $s, float $cx, float $cy, float $R, string $color, float $op,
                                    int $n = 32, int $k = 7, float $phase = 0.0, array $clip = []): void
    {
        if ($clip !== []) {
            $s->clip($clip[0], $clip[1], $clip[2], $clip[3]);
        }
        $amp = $R * 0.16;
        for ($i = 0; $i < $n; $i++) {
            $rot = $phase + $i * (2 * M_PI / $n / $k);
            $pts = [];
            for ($j = 0; $j <= 220; $j++) {
                $t = $j / 220 * 2 * M_PI;
                $r = $R - $amp + $amp * sin($k * $t + $i * 0.35) * cos($t * 2 + $i * 0.1) * 0.9;
                $pts[] = [$cx + $r * cos($t + $rot), $cy + $r * sin($t + $rot)];
            }
            $s->line($pts, $color, 0.9, $op);
        }
        if ($clip !== []) {
            $s->unclip();
        }
    }

    /** Interlaced sine waves filling a band — two families in opposite phase. */
    private static function waves(ApScene $s, float $x, float $y, float $w, float $h, string $color, float $op, int $lines, float $gap): void
    {
        $steps = (int) max(40, $w / 6);
        for ($fam = 0; $fam < 2; $fam++) {
            for ($i = 0; $i < $lines; $i++) {
                $y0 = $y + 6 + $i * $gap;
                if ($y0 > $y + $h + 10) {
                    break;
                }
                $pts = [];
                for ($j = 0; $j <= $steps; $j++) {
                    $px = $x + $w * $j / $steps;
                    $pts[] = [$px, $y0 + 7 * sin($px / 34 + $i * 0.55 + $fam * M_PI) + 3 * sin($px / 11 + $fam)];
                }
                $s->line($pts, $color, 0.8, $op);
            }
        }
    }

    /** WhatsApp, drawn: a green disc with the white speech-bubble ring and its tail. */
    private static function waGlyph(ApScene $s, float $cx, float $cy, float $r): void
    {
        $s->circle($cx, $cy, $r, self::WA);
        $s->shape([['M', $cx - $r * 0.52, $cy + $r * 0.34], ['L', $cx - $r * 0.66, $cy + $r * 0.70],
                   ['L', $cx - $r * 0.24, $cy + $r * 0.56], ['Z']], '#FFFFFF');
        $s->ring($cx, $cy - $r * 0.02, $r * 0.56, $r * 0.16, '#FFFFFF');
        $s->circle($cx, $cy - $r * 0.02, $r * 0.18, '#FFFFFF');
    }

    /** The photo, or initials on a soft plate when there is none yet. */
    private static function portrait(ApScene $s, array $m, float $x, float $y, float $w, float $h, float $r): void
    {
        $abs = ($m['photo'] !== '' && str_starts_with((string) $m['photo'], 'agents/')) ? UPLOAD_PATH . '/' . $m['photo'] : '';
        if ($abs !== '' && is_file($abs)) {
            $s->image($x, $y, $w, $h, $abs, $r);
            return;
        }
        $s->grad($x, $y, $w, $h, '#E8ECF5', '#CBD3E6', 'v', $r);
        $s->circle($x + $w / 2, $y + $h * 0.40, $w * 0.21, '#AEB8D2');
        $s->shape([['M', $x + $w * 0.16, $y + $h], ['C', $x + $w * 0.18, $y + $h * 0.66, $x + $w * 0.82, $y + $h * 0.66, $x + $w * 0.84, $y + $h], ['Z']], '#AEB8D2');
        $ini = '';
        foreach (preg_split('/\s+/u', trim((string) $m['name'])) ?: [] as $part) {
            if ($part !== '' && mb_strlen($ini) < 2) {
                $ini .= mb_strtoupper(mb_substr($part, 0, 1));
            }
        }
        $s->text($x + $w / 2, $y + $h * 0.46, $ini, 'sansB', 44, '#FFFFFF', 'middle', 1, 0.95);
    }

    /** @return list<string> lines no wider than $max, at most $limit (the last one shortened). */
    private static function wrap(string $text, string $font, float $size, float $max, int $limit): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if (self::measure($font, $size, $try) <= $max || $cur === '') {
                $cur = $try;
            } else {
                $lines[] = $cur;
                $cur = $w;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        if (count($lines) > $limit) {
            $lines = array_slice($lines, 0, $limit);
            $lines[$limit - 1] = rtrim($lines[$limit - 1], ',') . '…';
        }
        return $lines;
    }

    /* =================================================================
     *  Painter 1 — SVG
     * ================================================================= */

    /**
     * @param string $fontBase where the browser finds the TTFs ('' = same site, root-relative)
     * @param bool   $embedArt  logo + flags as data URIs (a standalone file); false links them
     *                          from $fontBase (a print sheet with many cards on one page)
     */
    public static function svg(ApScene $s, bool $embedPhoto = true, string $fontBase = '', bool $withSize = true, bool $embedArt = true): string
    {
        $uid = 'ap' . substr(bin2hex(random_bytes(4)), 0, 7);
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $n = static fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        $defs = [];
        $body = [];
        $font = '';
        foreach (self::FONTS as $key => [$file, $fam, $wt, $st]) {
            $font .= '@font-face{font-family:' . $fam . ';src:url(' . $e(rtrim($fontBase, '/') . '/assets/' . $file) . ');font-weight:' . $wt . ';font-style:' . $st . '}';
        }
        $defs[] = '<style>' . $font . '</style>';
        $defs[] = '<clipPath id="' . $uid . 'c"><rect width="' . $n($s->w) . '" height="' . $n($s->h) . '" rx="' . $n($s->radius) . '"/></clipPath>';
        $clipN = 0;
        $open = false;
        $gi = 0;
        foreach ($s->ops as $op) {
            switch ($op['t']) {
                case 'rect':
                    $attr = 'x="' . $n($op['x']) . '" y="' . $n($op['y']) . '" width="' . $n($op['w']) . '" height="' . $n($op['h']) . '"'
                        . ($op['r'] > 0 ? ' rx="' . $n($op['r']) . '"' : '') . ' fill="' . $op['fill'] . '"'
                        . (isset($op['op']) && $op['op'] < 1 ? ' fill-opacity="' . $n((float) $op['op']) . '"' : '');
                    if (!empty($op['stroke'])) {
                        $h = (float) $op['sw'] / 2;
                        $body[] = '<rect ' . $attr . '/>';
                        $body[] = '<rect x="' . $n($op['x'] + $h) . '" y="' . $n($op['y'] + $h) . '" width="' . $n($op['w'] - 2 * $h) . '" height="' . $n($op['h'] - 2 * $h) . '"'
                            . ($op['r'] > 0 ? ' rx="' . $n(max(0, $op['r'] - $h)) . '"' : '') . ' fill="none" stroke="' . $op['stroke'] . '" stroke-width="' . $n((float) $op['sw']) . '"/>';
                    } else {
                        $body[] = '<rect ' . $attr . '/>';
                    }
                    break;
                case 'grad':
                    $id = $uid . 'g' . ($gi++);
                    $defs[] = '<linearGradient id="' . $id . '" x1="0" y1="0" x2="' . ($op['dir'] === 'h' ? '1' : '0') . '" y2="' . ($op['dir'] === 'h' ? '0' : '1') . '">'
                        . '<stop offset="0" stop-color="' . $op['from'] . '"/><stop offset="1" stop-color="' . $op['to'] . '"/></linearGradient>';
                    $body[] = '<rect x="' . $n($op['x']) . '" y="' . $n($op['y']) . '" width="' . $n($op['w']) . '" height="' . $n($op['h']) . '"'
                        . ($op['r'] > 0 ? ' rx="' . $n($op['r']) . '"' : '') . ' fill="url(#' . $id . ')"/>';
                    break;
                case 'circle':
                    $body[] = '<circle cx="' . $n($op['cx']) . '" cy="' . $n($op['cy']) . '" r="' . $n($op['r']) . '" fill="' . $op['fill'] . '"'
                        . ($op['op'] < 1 ? ' fill-opacity="' . $n($op['op']) . '"' : '') . '/>';
                    break;
                case 'ring':
                    $body[] = '<circle cx="' . $n($op['cx']) . '" cy="' . $n($op['cy']) . '" r="' . $n($op['r']) . '" fill="none" stroke="' . $op['color'] . '" stroke-width="' . $n($op['sw']) . '"'
                        . ($op['op'] < 1 ? ' stroke-opacity="' . $n($op['op']) . '"' : '')
                        . ($op['dash'] !== [] ? ' stroke-dasharray="' . $n((float) $op['dash'][0]) . ' ' . $n((float) $op['dash'][1]) . '"' : '') . '/>';
                    break;
                case 'shape':
                    $d = '';
                    foreach ($op['segs'] as $sg) {
                        $d .= $sg[0] === 'Z' ? 'Z' : $sg[0] . implode(' ', array_map($n, array_map('floatval', array_slice($sg, 1)))) . ' ';
                    }
                    $body[] = '<path d="' . trim($d) . '" fill="' . $op['fill'] . '"' . ($op['op'] < 1 ? ' fill-opacity="' . $n($op['op']) . '"' : '') . '/>';
                    break;
                case 'line':
                    $pts = [];
                    foreach ($op['pts'] as [$px, $py]) {
                        $pts[] = $n($px) . ',' . $n($py);
                    }
                    $body[] = '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="' . $op['color'] . '" stroke-width="' . $n($op['sw']) . '"'
                        . ' stroke-linecap="round" stroke-linejoin="round"' . ($op['op'] < 1 ? ' stroke-opacity="' . $n($op['op']) . '"' : '') . '/>';
                    break;
                case 'text':
                    [, $fam, $wt, $st] = self::FONTS[$op['font']];
                    $anchor = $op['anchor'];
                    $x = (float) $op['x'];
                    // letter-spacing trails the last glyph in browsers; pull it back so both painters agree.
                    if ($op['tr'] != 0 && $anchor === 'middle') { $x += $op['tr'] / 2; }
                    if ($op['tr'] != 0 && $anchor === 'end')    { $x += $op['tr']; }
                    $body[] = '<text x="' . $n($x) . '" y="' . $n($op['y']) . '" font-family="' . $fam . ',sans-serif" font-size="' . $n($op['size']) . '"'
                        . ' font-weight="' . $wt . '"' . ($st !== 'normal' ? ' font-style="' . $st . '"' : '') . ' fill="' . $op['color'] . '"'
                        . ($anchor !== 'start' ? ' text-anchor="' . $anchor . '"' : '')
                        . ($op['tr'] != 0 ? ' letter-spacing="' . $n($op['tr']) . '"' : '')
                        . ($op['op'] < 1 ? ' fill-opacity="' . $n($op['op']) . '"' : '') . ' xml:space="preserve">' . $e($op['text']) . '</text>';
                    break;
                case 'image':
                    $id = $uid . 'i' . ($gi++);
                    $defs[] = $op['shape'] === 'circle'
                        ? '<clipPath id="' . $id . '"><circle cx="' . $n($op['x'] + $op['w'] / 2) . '" cy="' . $n($op['y'] + $op['h'] / 2) . '" r="' . $n(min($op['w'], $op['h']) / 2) . '"/></clipPath>'
                        : '<clipPath id="' . $id . '"><rect x="' . $n($op['x']) . '" y="' . $n($op['y']) . '" width="' . $n($op['w']) . '" height="' . $n($op['h']) . '" rx="' . $n($op['r']) . '"/></clipPath>';
                    $href = $embedPhoto ? self::photoDataUri((string) $op['abs'], (int) round($op['w'] * 2), (int) round($op['h'] * 2))
                                        : self::photoPublicUrl((string) $op['abs']);
                    $body[] = '<image x="' . $n($op['x']) . '" y="' . $n($op['y']) . '" width="' . $n($op['w']) . '" height="' . $n($op['h']) . '"'
                        . ' preserveAspectRatio="xMidYMid slice" clip-path="url(#' . $id . ')" href="' . $e($href) . '"/>';
                    break;
                case 'art':
                    $href = $embedArt ? self::artDataUri((string) $op['rel']) : rtrim($fontBase, '/') . '/' . self::artWebRel((string) $op['rel']);
                    if ($href !== '' && $href !== '/') {
                        $body[] = '<image x="' . $n($op['x']) . '" y="' . $n($op['y']) . '" width="' . $n($op['w']) . '" height="' . $n($op['h']) . '"'
                            . ' preserveAspectRatio="xMidYMid meet" href="' . $e($href) . '"/>';
                    }
                    break;
                case 'qr':
                    $mx = $op['m'];
                    $cnt = count($mx);
                    $cell = $op['size'] / max(1, $cnt);
                    $d = '';
                    foreach ($mx as $ry => $row) {
                        foreach ($row as $rx => $on) {
                            if ($on) {
                                $d .= 'M' . $n($op['x'] + $rx * $cell) . ' ' . $n($op['y'] + $ry * $cell) . 'h' . $n($cell + 0.06) . 'v' . $n($cell + 0.06) . 'h-' . $n($cell + 0.06) . 'z';
                            }
                        }
                    }
                    $body[] = '<path d="' . $d . '" fill="' . $op['fg'] . '" shape-rendering="crispEdges"/>';
                    break;
                case 'clip':
                    if ($open) { $body[] = '</g>'; }
                    $id = $uid . 'k' . ($clipN++);
                    $defs[] = '<clipPath id="' . $id . '"><rect x="' . $n($op['x']) . '" y="' . $n($op['y']) . '" width="' . $n($op['w']) . '" height="' . $n($op['h']) . '"/></clipPath>';
                    $body[] = '<g clip-path="url(#' . $id . ')">';
                    $open = true;
                    break;
                case 'unclip':
                    if ($open) { $body[] = '</g>'; $open = false; }
                    break;
            }
        }
        if ($open) {
            $body[] = '</g>';
        }
        $mm = static fn(float $u): string => rtrim(rtrim(number_format($u / self::UNITS_PER_MM, 2, '.', ''), '0'), '.') . 'mm';
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n($s->w) . ' ' . $n($s->h) . '"'
            . ($withSize ? ' width="' . $mm($s->w) . '" height="' . $mm($s->h) . '"' : '') . ' role="img">'
            . '<defs>' . implode('', $defs) . '</defs><g clip-path="url(#' . $uid . 'c)">' . implode('', $body) . '</g></svg>';
    }

    private static function photoDataUri(string $abs, int $w, int $h): string
    {
        $img = AgentPro::loadImage($abs);
        if ($img === null) {
            return '';
        }
        $scale = min(1.0, max($w / imagesx($img), $h / imagesy($img)));
        $out = imagescale($img, max(1, (int) round(imagesx($img) * $scale)), -1, IMG_BICUBIC);
        ob_start();
        imagejpeg($out ?: $img, null, 86);
        $bin = (string) ob_get_clean();
        imagedestroy($img);
        if ($out) {
            imagedestroy($out);
        }
        return 'data:image/jpeg;base64,' . base64_encode($bin);
    }

    /** The lighter .webp twin of a site picture when there is one (browsers only; GD keeps the PNG). */
    private static function artWebRel(string $rel): string
    {
        $webp = (string) preg_replace('/\.png$/i', '.webp', $rel);
        return $webp !== $rel && self::assetFile($webp) !== '' ? $webp : $rel;
    }

    /** @var array<string, string> */
    private static array $artUri = [];

    private static function artDataUri(string $rel): string
    {
        $rel = self::artWebRel($rel);
        if (!isset(self::$artUri[$rel])) {
            $abs = self::assetFile($rel);
            $bin = $abs !== '' ? (string) @file_get_contents($abs) : '';
            $mime = str_ends_with(strtolower($rel), '.webp') ? 'image/webp' : (str_ends_with(strtolower($rel), '.jpg') ? 'image/jpeg' : 'image/png');
            self::$artUri[$rel] = $bin !== '' ? 'data:' . $mime . ';base64,' . base64_encode($bin) : '';
        }
        return self::$artUri[$rel];
    }

    /** @var array<string, GdImage|null> decoded site pictures, kept for the request */
    private static array $artGd = [];

    private static function artImage(string $abs): ?GdImage
    {
        if (!array_key_exists($abs, self::$artGd)) {
            $g = null;
            $bin = is_file($abs) ? @file_get_contents($abs) : false;
            if ($bin !== false && $bin !== '') {
                $im = @imagecreatefromstring($bin);
                if ($im instanceof GdImage) {
                    if (!imageistruecolor($im)) {
                        imagepalettetotruecolor($im);
                    }
                    imagealphablending($im, true);
                    imagesavealpha($im, true);
                    $g = $im;
                }
            }
            self::$artGd[$abs] = $g;
        }
        return self::$artGd[$abs];
    }

    private static function photoPublicUrl(string $abs): string
    {
        $base = realpath(UPLOAD_PATH);
        $real = realpath($abs);
        return ($base !== false && $real !== false && str_starts_with($real, $base . '/agents/'))
            ? '/uploads/' . substr($real, strlen($base) + 1) : '';
    }

    /* =================================================================
     *  Painter 2 — GD (PNG for download and WhatsApp)
     * ================================================================= */

    /** @param float $pxPerUnit 2 = phone / WhatsApp quality; 1.1811 = exactly 300 dpi */
    public static function png(ApScene $s, float $pxPerUnit = 2.0): string
    {
        $ss = 2.0;                                           // draw twice as large, then scale down: smooth edges
        $k  = $pxPerUnit * $ss;
        $W  = (int) round($s->w * $k);
        $H  = (int) round($s->h * $k);
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, false);
        imagefilledrectangle($im, 0, 0, $W, $H, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagealphablending($im, true);
        imageantialias($im, false);

        foreach ($s->ops as $op) {
            switch ($op['t']) {
                case 'rect':
                    $c = self::col($im, $op['fill'], (float) ($op['op'] ?? 1));
                    self::gdRoundRect($im, $op['x'] * $k, $op['y'] * $k, $op['w'] * $k, $op['h'] * $k, $op['r'] * $k, $c);
                    if (!empty($op['stroke'])) {
                        self::gdRoundRectStroke($im, $op['x'] * $k, $op['y'] * $k, $op['w'] * $k, $op['h'] * $k, $op['r'] * $k,
                            (float) $op['sw'] * $k, self::col($im, (string) $op['stroke']));
                    }
                    break;
                case 'grad':
                    self::gdGrad($im, $op, $k);
                    break;
                case 'circle':
                    $d = (int) round($op['r'] * 2 * $k);
                    imagefilledellipse($im, (int) round($op['cx'] * $k), (int) round($op['cy'] * $k), $d, $d, self::col($im, $op['fill'], $op['op']));
                    break;
                case 'ring':
                    $c = self::col($im, $op['color'], $op['op']);
                    imagesetthickness($im, max(1, (int) round($op['sw'] * $k)));
                    $circ = 2 * M_PI * $op['r'];
                    $segs = 180;
                    $on = $op['dash'][0] ?? 0;
                    $per = $on > 0 ? $on + $op['dash'][1] : 0;
                    for ($i = 0; $i < $segs; $i++) {
                        $a0 = $i / $segs * 2 * M_PI;
                        $a1 = ($i + 1) / $segs * 2 * M_PI;
                        if ($per > 0 && fmod($a0 / (2 * M_PI) * $circ, $per) > $on) {
                            continue;
                        }
                        imageline($im, (int) round(($op['cx'] + $op['r'] * cos($a0)) * $k), (int) round(($op['cy'] + $op['r'] * sin($a0)) * $k),
                            (int) round(($op['cx'] + $op['r'] * cos($a1)) * $k), (int) round(($op['cy'] + $op['r'] * sin($a1)) * $k), $c);
                    }
                    imagesetthickness($im, 1);
                    break;
                case 'shape':
                    $pts = self::flatten($op['segs'], $k);
                    if (count($pts) >= 6) {
                        imagefilledpolygon($im, $pts, self::col($im, $op['fill'], $op['op']));
                    }
                    break;
                case 'line':
                    $c = self::col($im, $op['color'], $op['op']);
                    $t = max(1, (int) round($op['sw'] * $k));
                    imagesetthickness($im, $t);
                    $prev = null;
                    foreach ($op['pts'] as [$px, $py]) {
                        $cur = [(int) round($px * $k), (int) round($py * $k)];
                        if ($prev !== null && ($prev[0] !== $cur[0] || $prev[1] !== $cur[1])) {
                            imageline($im, $prev[0], $prev[1], $cur[0], $cur[1], $c);
                        }
                        $prev = $cur;
                    }
                    imagesetthickness($im, 1);
                    break;
                case 'text':
                    self::gdText($im, $op, $k);
                    break;
                case 'image':
                    self::gdImage($im, $op, $k);
                    break;
                case 'art':
                    $src = self::artImage((string) $op['abs']);
                    if ($src !== null) {
                        // Alpha blending is on: the logo's transparent edges melt into the card.
                        imagecopyresampled($im, $src, (int) round($op['x'] * $k), (int) round($op['y'] * $k), 0, 0,
                            max(1, (int) round($op['w'] * $k)), max(1, (int) round($op['h'] * $k)), imagesx($src), imagesy($src));
                    }
                    break;
                case 'qr':
                    $c = self::col($im, $op['fg']);
                    $cnt = count($op['m']);
                    $cell = $op['size'] * $k / max(1, $cnt);
                    foreach ($op['m'] as $ry => $row) {
                        foreach ($row as $rx => $on) {
                            if ($on) {
                                imagefilledrectangle($im, (int) floor($op['x'] * $k + $rx * $cell), (int) floor($op['y'] * $k + $ry * $cell),
                                    (int) ceil($op['x'] * $k + ($rx + 1) * $cell) - 1, (int) ceil($op['y'] * $k + ($ry + 1) * $cell) - 1, $c);
                            }
                        }
                    }
                    break;
                case 'clip':
                    imagesetclip($im, (int) floor($op['x'] * $k), (int) floor($op['y'] * $k),
                        (int) ceil(($op['x'] + $op['w']) * $k), (int) ceil(($op['y'] + $op['h']) * $k));
                    break;
                case 'unclip':
                    imagesetclip($im, 0, 0, $W - 1, $H - 1);
                    break;
            }
        }

        // Scale down to the target size (this is the anti-aliasing), then round the corners.
        $w = (int) round($s->w * $pxPerUnit);
        $h = (int) round($s->h * $pxPerUnit);
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $W, $H);
        imagedestroy($im);
        self::gdCorners($out, $s->radius * $pxPerUnit);

        ob_start();
        imagepng($out, null, 6);
        imagedestroy($out);
        return (string) ob_get_clean();
    }

    private static function col(GdImage $im, string $hex, float $op = 1.0): int
    {
        $hex = ltrim($hex, '#');
        $a = (int) round(127 * (1 - max(0.0, min(1.0, $op))));
        return imagecolorallocatealpha($im, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)), $a);
    }

    /** Rounded rectangle as one polygon, so a translucent fill never doubles at the joins. */
    private static function gdRoundRect(GdImage $im, float $x, float $y, float $w, float $h, float $r, int $c): void
    {
        if ($r <= 0.5) {
            imagefilledrectangle($im, (int) round($x), (int) round($y), (int) round($x + $w) - 1, (int) round($y + $h) - 1, $c);
            return;
        }
        $pts = self::roundRectPoints($x, $y, $w, $h, min($r, $w / 2, $h / 2));
        imagefilledpolygon($im, $pts, $c);
    }

    /** @return list<int> */
    private static function roundRectPoints(float $x, float $y, float $w, float $h, float $r): array
    {
        $pts = [];
        $corners = [[$x + $w - $r, $y + $r, -90], [$x + $w - $r, $y + $h - $r, 0], [$x + $r, $y + $h - $r, 90], [$x + $r, $y + $r, 180]];
        foreach ($corners as [$cx, $cy, $start]) {
            for ($i = 0; $i <= 10; $i++) {
                $a = deg2rad($start + 9 * $i);
                $pts[] = (int) round($cx + $r * cos($a));
                $pts[] = (int) round($cy + $r * sin($a));
            }
        }
        return $pts;
    }

    private static function gdRoundRectStroke(GdImage $im, float $x, float $y, float $w, float $h, float $r, float $sw, int $c): void
    {
        $pts = self::roundRectPoints($x + $sw / 2, $y + $sw / 2, $w - $sw, $h - $sw, max(0.0, min($r - $sw / 2, ($w - $sw) / 2, ($h - $sw) / 2)));
        imagesetthickness($im, max(1, (int) round($sw)));
        $n = count($pts);
        for ($i = 0; $i < $n; $i += 2) {
            imageline($im, $pts[$i], $pts[$i + 1], $pts[($i + 2) % $n], $pts[($i + 3) % $n], $c);
        }
        imagesetthickness($im, 1);
    }

    /** Gradient, line by line, trimmed at rounded corners. */
    private static function gdGrad(GdImage $im, array $op, float $k): void
    {
        $x = $op['x'] * $k; $y = $op['y'] * $k; $w = $op['w'] * $k; $h = $op['h'] * $k; $r = min($op['r'] * $k, $w / 2, $h / 2);
        $a = self::rgb($op['from']);
        $b = self::rgb($op['to']);
        $vert = $op['dir'] !== 'h';
        $len = (int) ceil($vert ? $h : $w);
        for ($i = 0; $i < $len; $i++) {
            $t = $len > 1 ? $i / ($len - 1) : 0;
            $c = imagecolorallocate($im, (int) ($a[0] + ($b[0] - $a[0]) * $t), (int) ($a[1] + ($b[1] - $a[1]) * $t), (int) ($a[2] + ($b[2] - $a[2]) * $t));
            $along = $i + 0.5;
            $span = $vert ? $h : $w;
            $inset = 0.0;
            if ($r > 0) {
                $d = min($along, $span - $along);
                if ($d < $r) {
                    $inset = $r - sqrt(max(0, $r * $r - ($r - $d) ** 2));
                }
            }
            if ($vert) {
                imageline($im, (int) round($x + $inset), (int) round($y + $i), (int) round($x + $w - $inset) - 1, (int) round($y + $i), $c);
            } else {
                imageline($im, (int) round($x + $i), (int) round($y + $inset), (int) round($x + $i), (int) round($y + $h - $inset) - 1, $c);
            }
        }
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function gdText(GdImage $im, array $op, float $k): void
    {
        $file = self::fontFile((string) $op['font']);
        $text = $op['font'] === 'deva' ? self::devShape((string) $op['text']) : (string) $op['text'];
        $pt   = $op['size'] * 0.75 * $k;
        $c    = self::col($im, $op['color'], $op['op']);
        $w    = (float) $op['w'] * $k;
        $x    = $op['x'] * $k;
        if ($op['anchor'] === 'middle') { $x -= $w / 2; }
        if ($op['anchor'] === 'end')    { $x -= $w; }
        $y = (int) round($op['y'] * $k);
        if ($op['tr'] == 0 && !self::needsFallback((string) $op['font'], $text)) {
            $b = imagettfbbox($pt, 0, $file, $text);
            // bbox[0] is the left bearing: place the ink where measure() said it would be.
            imagettftext($im, $pt, 0, (int) round($x - ($b === false ? 0 : $b[0])), $y, $c, $file, $text);
            return;
        }
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $f = self::glyphFile((string) $op['font'], $file, $ch);
            if ($ch !== ' ') {
                imagettftext($im, $pt, 0, (int) round($x), $y, $c, $f, $ch);
            }
            $x += self::advance($f, $pt, $ch) + $op['tr'] * $k;
        }
    }

    private static function gdImage(GdImage $im, array $op, float $k): void
    {
        $src = AgentPro::loadImage((string) $op['abs']);
        if ($src === null) {
            return;
        }
        $bw = (int) round($op['w'] * $k);
        $bh = (int) round($op['h'] * $k);
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($bw / $sw, $bh / $sh);
        $cw = (int) round($bw / $scale);
        $ch = (int) round($bh / $scale);
        $tile = imagecreatetruecolor($bw, $bh);
        imagealphablending($tile, false);
        imagesavealpha($tile, true);
        imagecopyresampled($tile, $src, 0, 0, (int) (($sw - $cw) / 2), (int) max(0, ($sh - $ch) * 0.3), $bw, $bh, $cw, $ch);
        imagedestroy($src);
        // Cut the corners away (transparent), then lay the tile on the card.
        $clear = imagecolorallocatealpha($tile, 0, 0, 0, 127);
        $r = $op['shape'] === 'circle' ? min($bw, $bh) / 2 : min($op['r'] * $k, $bw / 2, $bh / 2);
        if ($r > 0) {
            foreach ([[0, 0, $r, $r, 180], [$bw, 0, $bw - $r, $r, 270], [$bw, $bh, $bw - $r, $bh - $r, 0], [0, $bh, $r, $bh - $r, 90]] as [$px, $py, $cx, $cy, $start]) {
                $pts = [(int) $px, (int) $py];
                for ($i = 0; $i <= 12; $i++) {
                    $a = deg2rad($start + 7.5 * $i);
                    $pts[] = (int) round($cx + $r * cos($a));
                    $pts[] = (int) round($cy + $r * sin($a));
                }
                imagefilledpolygon($tile, $pts, $clear);
            }
        }
        imagecopy($im, $tile, (int) round($op['x'] * $k), (int) round($op['y'] * $k), 0, 0, $bw, $bh);
        imagedestroy($tile);
    }

    /** Transparent, anti-aliased card corners. */
    private static function gdCorners(GdImage $im, float $r): void
    {
        if ($r < 1) {
            return;
        }
        $w = imagesx($im);
        $h = imagesy($im);
        $ri = (int) ceil($r);
        for ($y = 0; $y < $ri; $y++) {
            for ($x = 0; $x < $ri; $x++) {
                $dx = $r - ($x + 0.5);
                $dy = $r - ($y + 0.5);
                $dist = sqrt($dx * $dx + $dy * $dy);
                $cover = max(0.0, min(1.0, $r - $dist + 0.5));
                if ($cover >= 1.0) {
                    continue;
                }
                foreach ([[$x, $y], [$w - 1 - $x, $y], [$x, $h - 1 - $y], [$w - 1 - $x, $h - 1 - $y]] as [$px, $py]) {
                    $rgba = imagecolorat($im, $px, $py);
                    $a = ($rgba >> 24) & 0x7F;
                    $na = (int) round(127 - (127 - $a) * $cover);
                    imagesetpixel($im, $px, $py, imagecolorallocatealpha($im, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, $na));
                }
            }
        }
    }

    /** Bezier outlines → polygon points. @return list<int> */
    private static function flatten(array $segs, float $k): array
    {
        $out = [];
        $cx = 0.0;
        $cy = 0.0;
        foreach ($segs as $sg) {
            switch ($sg[0]) {
                case 'M':
                case 'L':
                    $cx = (float) $sg[1];
                    $cy = (float) $sg[2];
                    $out[] = (int) round($cx * $k);
                    $out[] = (int) round($cy * $k);
                    break;
                case 'C':
                    [$x1, $y1, $x2, $y2, $x3, $y3] = array_map('floatval', array_slice($sg, 1, 6));
                    for ($i = 1; $i <= 32; $i++) {
                        $t = $i / 32;
                        $u = 1 - $t;
                        $px = $u ** 3 * $cx + 3 * $u * $u * $t * $x1 + 3 * $u * $t * $t * $x2 + $t ** 3 * $x3;
                        $py = $u ** 3 * $cy + 3 * $u * $u * $t * $y1 + 3 * $u * $t * $t * $y2 + $t ** 3 * $y3;
                        $out[] = (int) round($px * $k);
                        $out[] = (int) round($py * $k);
                    }
                    $cx = $x3;
                    $cy = $y3;
                    break;
            }
        }
        return $out;
    }

    /* =================================================================
     *  A4 print sheets — true size, cut guides, backs mirrored for duplex
     * ================================================================= */

    /**
     * A whole printable HTML page: ID cards 3 × 3 per A4 (front page, then the
     * back page with columns mirrored so a long-edge duplex print lines up),
     * visiting cards 2 × 5 per A4 the same way.
     *
     * @param list<array<string, mixed>> $members AgentPro::member() rows
     * @param string $what 'all' | 'id' | 'vc'
     */
    public static function printHtml(array $members, string $what = 'all', string $title = 'Cards'): string
    {
        $pages = [];
        $kinds = $what === 'id' ? ['id'] : ($what === 'vc' ? ['vc'] : ['id', 'vc']);
        foreach ($kinds as $kind) {
            [$cols, $rows, $w, $h, $gap] = $kind === 'id' ? [3, 3, 54.0, 85.6, 6.0] : [2, 5, 88.9, 50.8, 5.0];
            foreach (array_chunk($members, $cols * $rows) as $chunk) {
                foreach (['front', 'back'] as $face) {
                    $cells = array_fill(0, $cols * $rows, '');
                    foreach ($chunk as $i => $m) {
                        $r = intdiv($i, $cols);
                        $c = $i % $cols;
                        if ($face === 'back') {
                            $c = $cols - 1 - $c;             // mirrored: the back lands behind its front
                        }
                        $cells[$r * $cols + $c] = self::svg(self::scene($m, $kind . '-' . $face), true, '', false, false);
                    }
                    $grid = '';
                    foreach ($cells as $cell) {
                        $grid .= '<div class="cut" style="width:' . $w . 'mm;height:' . $h . 'mm">' . $cell . '</div>';
                    }
                    $pages[] = '<section class="sheet"><div class="grid" style="grid-template-columns:repeat(' . $cols . ',' . $w . 'mm);gap:' . $gap . 'mm">'
                        . $grid . '</div><p class="foot">' . htmlspecialchars($title, ENT_QUOTES) . ' · ' . ($kind === 'id' ? 'ID cards' : 'Visiting cards') . ' · '
                        . $face . ' · print at 100% (no "fit to page"), A4' . '</p></section>';
                }
            }
        }
        $fonts = '';
        foreach (self::FONTS as [$file, $fam, $wt, $st]) {
            $fonts .= '@font-face{font-family:' . $fam . ';src:url(/assets/' . $file . ');font-weight:' . $wt . ';font-style:' . $st . '}';
        }
        return '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>' . $fonts . '@page{size:A4;margin:0}*{box-sizing:border-box}body{margin:0;background:#E9E6DD;font-family:system-ui,sans-serif}'
            . '.bar{position:sticky;top:0;display:flex;gap:10px;align-items:center;justify-content:center;padding:10px;background:#13224F;color:#fff;font-size:14px;z-index:2}'
            . '.bar button{font:inherit;font-weight:700;padding:10px 18px;border-radius:10px;border:0;background:#C9973A;color:#1A1406;cursor:pointer}'
            . '.sheet{width:210mm;height:297mm;margin:10px auto;background:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;page-break-after:always;box-shadow:0 2px 12px rgba(0,0,0,.15);position:relative}'
            . '.grid{display:grid}.cut{outline:0.15mm dashed #B9B2A0;outline-offset:1.2mm}.cut svg{display:block;width:100%;height:100%}'
            . '.foot{position:absolute;bottom:5mm;margin:0;font-size:8pt;color:#8A8577}'
            . '@media print{body{background:#fff}.bar{display:none}.sheet{margin:0;box-shadow:none}}</style></head><body>'
            . '<div class="bar">' . count($members) . ' × ' . htmlspecialchars($title, ENT_QUOTES) . ' <button onclick="window.print()">🖨 Print</button></div>'
            . implode('', $pages) . '</body></html>';
    }

    /* =================================================================
     *  One A4 PDF: ID card front + back, visiting card front + back
     * ================================================================= */

    /**
     * A print-ready A4 page (PDF bytes): all four faces at true size (CR80
     * 54 × 85.6 mm, visiting card 88.9 × 50.8 mm), each a 300 dpi picture
     * from the same scene as the PNG, with crop marks. Print at 100 %.
     *
     * @param array<string, mixed> $m AgentPro::member()
     */
    public static function pdf(array $m): string
    {
        require_once __DIR__ . '/pdf.php';
        $pdf = new Pdf();                                    // A4 portrait, points, top-left origin
        $pt  = 72 / 25.4;
        $W   = $pdf->width();
        $H   = $pdf->height();
        $co  = $m['company'];
        $dpi = 300 / 25.4 / self::UNITS_PER_MM;              // px per scene unit at 300 dpi
        // The PDF's standard fonts are Latin-1 only: common punctuation is spelled out, the rest dropped.
        $latin = static fn(string $v): string => trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^\x20-\x7E]+/', ' ',
            strtr($v, ['–' => '-', '—' => '-', '·' => '-', '×' => 'x', '’' => "'", '‘' => "'", '“' => '"', '”' => '"', '₹' => 'Rs ']))));
        $navy = [19, 34, 79];
        $gold = [201, 151, 58];
        $mute = [93, 102, 128];

        // Letterhead: the logo on white, the legal name, CIN, who these cards are for.
        $pdf->rect(0, 0, $W, 74, [255, 255, 255], true);
        $logo = self::assetFile(self::LOGO);
        if ($logo !== '') {
            $pdf->image($logo, 30, 12, 96, 96 * 228 / 439);
        }
        [$w1, $w2] = self::wordmark($co);
        $pdf->text(138, 32, $latin(trim($w1 . ' ' . str_replace('  ', ' ', $w2))), 15, 'F2', $navy);
        $pdf->text(138, 48, $latin('CIN ' . ($co['cin'] ?? '') . '   ·   ' . ($co['address'] ?? '')), 7.5, 'F1', $mute);
        $who = $latin((string) $m['name']);
        $pdf->text(138, 63, 'ID card + visiting card' . ($who !== '' ? '  -  ' . $who : '') . '  -  '
            . self::codeLabel($m) . ' ' . ($m['idNo'] !== '' ? $m['idNo'] : 'PENDING'), 9, 'F2', $gold);
        $pdf->rect(0, 74, $W, 3, $navy, true);

        $marks = static function (float $x, float $y, float $w, float $h) use ($pdf): void {
            foreach ([[$x, $y], [$x + $w, $y], [$x, $y + $h], [$x + $w, $y + $h]] as [$mx, $my]) {
                $pdf->line($mx - 14, $my, $mx - 4, $my, [120, 120, 120], 0.4);
                $pdf->line($mx + 4, $my, $mx + 14, $my, [120, 120, 120], 0.4);
                $pdf->line($mx, $my - 14, $mx, $my - 4, [120, 120, 120], 0.4);
                $pdf->line($mx, $my + 4, $mx, $my + 14, [120, 120, 120], 0.4);
            }
        };
        $pair = function (string $kind, float $y, float $wmm, float $hmm, float $gapPt) use ($pdf, $pt, $W, $m, $dpi, $marks, $mute): float {
            $w = $wmm * $pt;
            $h = $hmm * $pt;
            $x = ($W - (2 * $w + $gapPt)) / 2;
            foreach (['front', 'back'] as $i => $face) {
                $cx = $x + $i * ($w + $gapPt);
                $marks($cx, $y, $w, $h);
                $pdf->imageFromString(self::png(self::scene($m, $kind . '-' . $face), $dpi), $cx, $y, $w, $h);
                $pdf->textCenter($cx, $cx + $w, $y + $h + 18, ($kind === 'id' ? 'ID card' : 'Visiting card') . ' - ' . $face
                    . '  (' . rtrim(rtrim(number_format($wmm, 1), '0'), '.') . ' x ' . rtrim(rtrim(number_format($hmm, 1), '0'), '.') . ' mm)', 8, 'F1', $mute);
            }
            return $y + $h + 18;
        };

        $y = $pair('id', 104, 54.0, 85.6, 56);
        $y = $pair('vc', $y + 36, 88.9, 50.8, 30);

        // How to print, and where the QR leads.
        $y += 30;
        $pdf->roundedRect(36, $y, $W - 72, 92, 8, [246, 244, 238], true);
        $lines = [
            'Print this page at 100% / "Actual size" on A4 (never "Fit to page"), then cut on the crop marks.',
            'Paste each front to its back (or print on card stock and laminate). Fronts and backs are in the same order.',
            'The left QR on the ID card back opens ' . $latin(preg_replace('#^https?://(www\.)?#', '', appUrl('id.php')) ?? '') . ' - it shows whether this card is valid today.',
            'KYC: ' . (self::kycVerified($m) ? 'verified (red seal printed).'
                : (self::kycRejected($m) ? 'NOT verified - the office refused the ID; the card says "KYC NOT VERIFIED".'
                : 'pending - the card says "KYC PENDING" until the office verifies the ID.'))
                . '   Valid till: ' . ($m['validUntil'] !== '' ? date('d M Y', (int) strtotime((string) $m['validUntil'])) : '-') . '.',
        ];
        foreach ($lines as $i => $ln) {
            $pdf->text(50, $y + 22 + $i * 18, $ln, 8.5, 'F1', [40, 46, 66]);
        }
        $pdf->rect(0, $H - 34, $W, 34, $navy, true);
        $pdf->textCenter(0, $W, $H - 14, $latin(($co['legal'] ?? '') . '  -  CEO & Director: ' . ($co['ceo'] ?? '') . '  -  24x7 ' . ($co['phone247'] ?? '')
            . '  -  www.' . self::web($co)), 8.5, 'F1', [255, 255, 255]);
        return $pdf->output();
    }

    /* =================================================================
     *  Links that work outside a signed-in browser (WhatsApp fetches them)
     * ================================================================= */

    public static function sig(int $adminId, string $side, int $exp): string
    {
        return substr(hash_hmac('sha256', 'apcard|' . $adminId . '|' . $side . '|' . $exp, APP_KEY), 0, 32);
    }

    /** $side: one of SIDES (a PNG), or 'pdf' (the A4 page with all four faces). */
    public static function signedUrl(int $adminId, string $side, int $ttl = 86400): string
    {
        if (!in_array($side, self::SIDES, true) && $side !== 'pdf') {
            throw new RuntimeException('Unknown card side.');
        }
        $exp = time() + max(60, $ttl);
        // A new photo / code must reach WhatsApp's cache too: the version is part of the link.
        $ver = (int) Database::scalar('SELECT photo_ver FROM ap_members WHERE admin_id = :a', ['a' => $adminId], 1);
        return appUrl('api/agentpro-card.php?m=' . $adminId . ($side === 'pdf' ? '&pdf=1' : '&s=' . $side) . '&e=' . $exp . '&v=' . $ver
            . '&k=' . self::sig($adminId, $side, $exp) . ($side === 'pdf' ? '' : '&f=png'));
    }

    public static function checkSig(int $adminId, string $side, int $exp, string $key): bool
    {
        return $exp >= time() && $key !== '' && hash_equals(self::sig($adminId, $side, $exp), $key);
    }
}
