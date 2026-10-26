<?php
/**
 * =====================================================================
 *  includes/brand.php — one brand helper for every surface (9 Oct 2026)
 *
 *  Owner: "logo haru ma name ni join xa, background hataidinu, only logo
 *  matra rakhdinu ... har ek document haru, thau haru, Nepal India ko
 *  flag haru dekhaunu paryo".
 *
 *  Two things every page and document needs the same way:
 *
 *   1. The EMBLEM — the S Hari Global mark WITHOUT the baked company name
 *      and WITHOUT a white tile. The name is always live text beside it
 *      (Settings company_name), so a rename reaches every surface at once.
 *        Brand::markFile()  absolute path for GD / PDF renderers
 *        Brand::markUrl()   root-relative URL for an <img> (cache-busted)
 *
 *   2. The FLAGS — accurate India + Nepal flags as SVG. Windows has no
 *      flag emoji (it prints the letters "IN" / "NP"), so a drawn flag is
 *      the only way every visitor sees the same thing. Every shape carries
 *      an explicit fill, so light / dark / AMOLED and an inherited
 *      `stroke: currentColor` never repaint them.
 *        Brand::flagSvg('IN'|'NP', $h)   standalone inline <svg>
 *        Brand::flagUse('IN'|'NP', $h)   <svg><use href="#flag-in"></svg>
 *                                        (needs Brand::flagSprite() on the page)
 *        Brand::flagSprite()             hidden <svg> with both <symbol>s
 *        Brand::flagFor($countryOrDesk)  like CounterDesk::flag(), but SVG
 *        Brand::flagPair($h)             India ⇄ Nepal, side by side
 *        Brand::flagPng('IN'|'NP')       transparent raster for GD / PDF
 *
 *  Geometry: Nepal follows the Constitution of Nepal (Schedule 1) — the
 *  double pennant with the blue border offset so the full flag is 1 : 1.219,
 *  the moon (crescent + 8 rays) and the 12-ray sun at their constructed
 *  centres. India is 3 : 2 with the 24-spoke Ashoka Chakra. The same
 *  markup ships in assets/js/23-flags.js (window.shgFlag) and in
 *  assets/img/flag-in.svg / flag-np.svg (tests/brand-flags-test.php keeps
 *  the three in step).
 *
 *  CounterDesk::flag() stays an emoji on purpose: it labels <option>s and
 *  WhatsApp text, where an <svg> cannot go (pinned by counter-desk-test).
 * =====================================================================
 */

declare(strict_types=1);

final class Brand
{
    /** The emblem (439x228, transparent, no name). */
    public const MARK = 'assets/img/logo-mark.png';
    /** The same emblem at nav size (240x125 palette PNG, transparent, 18 KB). */
    public const MARK_SMALL = 'assets/img/logo-nav.png';

    /**
     * Flag geometry. 'vb' is the drawing's own viewBox; 'w'/'h' its size, so
     * a <use> outer box (0 0 w h) maps 1:1 onto the symbol.
     */
    private const FLAGS = [
        'IN' => [
            'vb' => '0 0 30 20', 'w' => 30.0, 'h' => 20.0,
            'body' => '<path fill="#FF9933" stroke="none" d="M.8 0H29.2A.8.8 0 0 1 30 .8V6.667H0V.8A.8.8 0 0 1 .8 0Z"/>'
                . '<path fill="#FFFFFF" stroke="none" d="M0 6.667H30V13.333H0Z"/>'
                . '<path fill="#138808" stroke="none" d="M0 13.333H30V19.2A.8.8 0 0 1 29.2 20H.8A.8.8 0 0 1 0 19.2Z"/>'
                . '<g fill="none" stroke="#000080"><circle cx="15" cy="10" r="2.9" stroke-width=".42"/>'
                . '<path stroke-width=".2" d="M17.9 10L12.1 10M17.801 10.751L12.199 9.249M17.511 11.45L12.489 8.55M17.051 12.051L12.949 7.949M16.45 12.511L13.55 7.489M15.751 12.801L14.249 7.199M15 12.9L15 7.1M14.249 12.801L15.751 7.199M13.55 12.511L16.45 7.489M12.949 12.051L17.051 7.949M12.489 11.45L17.511 8.55M12.199 10.751L17.801 9.249"/></g>'
                . '<circle cx="15" cy="10" r=".55" fill="#000080" stroke="none"/>',
        ],
        'NP' => [
            'vb' => '-2.582 -4.664 71.571 87.246', 'w' => 71.571, 'h' => 87.246,
            'body' => '<path fill="#003893" stroke="none" d="M-2.582 -4.664 L68.989 40.156 L23.808 40.156 L66.234 82.582 L-2.582 82.582Z"/>'
                . '<path fill="#DC143C" stroke="none" d="M0 0L60 37.574L17.574 37.574L60 80L0 80Z"/>'
                . '<g fill="#fff" stroke="none"><path d="M3.05 23.483A11.95 11.95 0 0 0 26.95 23.483A12.84 12.84 0 0 1 3.05 23.483Z"/>'
                . '<circle cx="15" cy="29.045" r="5.561"/>'
                . '<path d="M20.561 29.045L22.575 27.538L20.138 26.916ZM20.138 26.916L21.422 24.754L18.932 25.112ZM18.932 25.112L19.291 22.623L17.128 23.907ZM17.128 23.907L16.507 21.47L15 23.483ZM15 23.483L13.493 21.47L12.872 23.907ZM12.872 23.907L10.709 22.623L11.068 25.112ZM11.068 25.112L8.578 24.754L9.862 26.916ZM9.862 26.916L7.425 27.538L9.439 29.045Z"/>'
                . '<circle cx="15" cy="58.787" r="8.143"/>'
                . '<path d="M17.108 50.921L15 45.947L12.892 50.921L8.58 47.667L9.242 53.029L3.88 52.367L7.134 56.679L2.16 58.787L7.134 60.894L3.88 65.207L9.242 64.545L8.58 69.907L12.892 66.653L15 71.627L17.108 66.653L21.42 69.907L20.758 64.545L26.12 65.207L22.866 60.894L27.84 58.787L22.866 56.679L26.12 52.367L20.758 53.029L21.42 47.667Z"/></g>',
        ],
    ];

    /** Accessible names, per language (labels Nepali by default on documents). */
    private const LABEL = [
        'IN' => ['en' => 'India', 'hi' => 'भारत', 'ne' => 'भारत'],
        'NP' => ['en' => 'Nepal', 'hi' => 'नेपाल', 'ne' => 'नेपाल'],
    ];

    /* -----------------------------------------------------------------
     *  Emblem
     * ----------------------------------------------------------------- */

    /**
     * The transparent emblem for GD / PDF renderers, as an absolute path.
     *
     * Order: $prefer (a company's own mark — the Nepal company's upload, say),
     * the company_logo_mark setting, assets/img/logo-mark.png, and only then
     * Ticket::logoFile() (the old full logo) so a document always carries a
     * mark even on an install without the emblem file. PNG / JPG only (what
     * GD and Pdf::image() read), under 2 MB, never a remote URL.
     */
    public static function markFile(string $prefer = ''): ?string
    {
        $cands = [$prefer];
        if (class_exists('Settings')) {
            try {
                $cands[] = Settings::getString('company_logo_mark', '');
            } catch (\Throwable $e) {
                // settings table not reachable (CLI tooling) — the file still works
            }
        }
        $cands[] = self::MARK;
        foreach ($cands as $cand) {
            $p = self::localImage((string) $cand);
            if ($p !== null) {
                return $p;
            }
        }
        if (class_exists('Ticket') && method_exists('Ticket', 'logoFile')) {
            try {
                return Ticket::logoFile();
            } catch (\Throwable $e) {
            }
        }

        return self::localImage('assets/img/logo.png');
    }

    /**
     * The emblem for an <img src>: the nav-size file for a slot up to 64 CSS
     * px tall (it is 125 px tall, so still sharp at 2x), the full emblem for
     * anything bigger. An admin-set company_logo_mark wins. The ?m= stamp is
     * the file's mtime, so a replaced file is never served stale from cache.
     */
    public static function markUrl(int $cssHeight = 40): string
    {
        $rel = '';
        if (class_exists('Settings')) {
            try {
                $s = Settings::getString('company_logo_mark', '');
                if ($s !== '' && self::localImage($s) !== null && !str_contains($s, '..')) {
                    $rel = ltrim(str_replace('\\', '/', $s), '/');
                }
            } catch (\Throwable $e) {
            }
        }
        if ($rel === '') {
            $rel = $cssHeight <= 64 ? self::MARK_SMALL : self::MARK;
            if (!is_file(self::root() . '/' . $rel)) {
                $rel = self::MARK;
            }
        }
        $m = @filemtime(self::root() . '/' . $rel);

        return '/' . $rel . ($m ? '?m=' . $m : '');
    }

    /**
     * Any shipped asset as a root-relative URL with a ?m=<mtime> stamp — for
     * the favicon / icon links on PHP pages, so a replaced icon is fetched
     * again instead of being served from a year-long cache.
     */
    public static function asset(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return '/';
        }
        $m = @filemtime(self::root() . '/' . $rel);

        return '/' . $rel . ($m ? '?m=' . $m : '');
    }

    /**
     * The <head> icon links every PHP page should carry: the emblem favicon
     * (PNG + ICO), the SVG emblem and the opaque iOS home-screen icon.
     */
    public static function iconLinks(): string
    {
        return '<link rel="icon" type="image/png" sizes="32x32" href="' . self::asset('assets/img/favicon-32.png') . '">'
            . '<link rel="icon" type="image/svg+xml" href="' . self::asset('icon-shg.svg') . '">'
            . '<link rel="apple-touch-icon" sizes="180x180" href="' . self::asset('assets/img/apple-touch-icon.png') . '">';
    }

    /* -----------------------------------------------------------------
     *  Flags
     * ----------------------------------------------------------------- */

    /** 'IN' | 'NP' for anything that names one of the two countries, else ''. */
    public static function cc(string $cc): string
    {
        $v = strtoupper(trim($cc));
        if ($v === 'NP' || $v === 'NPL' || $v === 'NEPAL') {
            return 'NP';
        }
        if ($v === 'IN' || $v === 'IND' || $v === 'INDIA') {
            return 'IN';
        }

        return '';
    }

    /** Width a flag $h tall takes (the Nepal pennant is narrower than tall). */
    public static function flagWidth(string $cc, float $h): float
    {
        $cc = self::cc($cc);
        if ($cc === '') {
            return 0.0;
        }
        $f = self::FLAGS[$cc];

        return round($h * $f['w'] / $f['h'], 2);
    }

    /** The accessible name of a flag in en / hi / ne. */
    public static function flagLabel(string $cc, string $lang = 'en'): string
    {
        $cc = self::cc($cc);
        if ($cc === '') {
            return '';
        }

        return self::LABEL[$cc][$lang] ?? self::LABEL[$cc]['en'];
    }

    /**
     * A standalone inline flag: <svg class="flg flg-in" …> with every shape
     * filled explicitly. $h is the height in CSS px (the width follows the
     * flag's own ratio). Pass $decorative = true when a visible word already
     * names the country (the flag is then aria-hidden). '' for anything that
     * is not India or Nepal.
     */
    public static function flagSvg(string $cc, int $h = 16, string $lang = 'en', bool $decorative = false, string $class = ''): string
    {
        $cc = self::cc($cc);
        if ($cc === '') {
            return '';
        }
        $f = self::FLAGS[$cc];
        $h = max(6, min(512, $h));

        return '<svg xmlns="http://www.w3.org/2000/svg" class="' . self::cls($cc, $class) . '" viewBox="' . $f['vb'] . '"'
            . ' width="' . self::num(self::flagWidth($cc, $h)) . '" height="' . $h . '"'
            . self::a11y($cc, $lang, $decorative) . ' style="vertical-align:middle">' . $f['body'] . '</svg>';
    }

    /**
     * The same flag drawn from the page sprite (<use href="#flag-in">) — a
     * few dozen bytes instead of ~1.3 KB, for pages that print many flags.
     * The page must carry Brand::flagSprite() (every admin page does) or
     * assets/js/23-flags.js (it injects the symbols).
     */
    public static function flagUse(string $cc, int $h = 16, string $lang = 'en', bool $decorative = false, string $class = ''): string
    {
        $cc = self::cc($cc);
        if ($cc === '') {
            return '';
        }
        $f = self::FLAGS[$cc];
        $h = max(6, min(512, $h));

        return '<svg class="' . self::cls($cc, $class) . '" viewBox="0 0 ' . self::num($f['w']) . ' ' . self::num($f['h']) . '"'
            . ' width="' . self::num(self::flagWidth($cc, $h)) . '" height="' . $h . '"'
            . self::a11y($cc, $lang, $decorative) . ' style="vertical-align:middle"><use href="#flag-' . strtolower($cc) . '"/></svg>';
    }

    /** Hidden <svg> holding <symbol id="flag-in"> and <symbol id="flag-np">. Print once per page. */
    public static function flagSprite(): string
    {
        $out = '<svg xmlns="http://www.w3.org/2000/svg" id="shg-flag-sprite" width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false"><defs>';
        foreach (self::FLAGS as $cc => $f) {
            $out .= '<symbol id="flag-' . strtolower($cc) . '" viewBox="' . $f['vb'] . '">' . $f['body'] . '</symbol>';
        }

        return $out . '</defs></svg>';
    }

    /**
     * The flag for a country code OR a desk code (NPJ → Nepal, SRT → India),
     * resolved exactly like CounterDesk::flag(). Unknown → India (an online
     * sale is the India company's), matching the emoji helper. The Nepal
     * pennant is drawn 20% taller than $h so both flags read the same size
     * in a list.
     */
    public static function flagFor(string $countryOrCode, int $h = 16, bool $useSprite = false, string $lang = 'en'): string
    {
        $cc = self::cc($countryOrCode);
        if ($cc === '') {
            $cc = 'IN';
            $code = strtoupper(trim($countryOrCode));
            if ($code !== '' && class_exists('CounterDesk')) {
                try {
                    $cc = self::cc((string) (CounterDesk::get($code)['country'] ?? 'IN')) ?: 'IN';
                } catch (\Throwable $e) {
                }
            }
        }

        $hh = $cc === 'NP' ? (int) round($h * 1.2) : $h;

        return $useSprite ? self::flagUse($cc, $hh, $lang) : self::flagSvg($cc, $hh, $lang);
    }

    /** India ⇄ Nepal side by side (decorative: the surrounding text names the route). */
    public static function flagPair(int $h = 16, bool $useSprite = false, string $sep = ''): string
    {
        $in = $useSprite ? self::flagUse('IN', $h, 'en', true) : self::flagSvg('IN', $h, 'en', true);
        $np = $useSprite ? self::flagUse('NP', (int) round($h * 1.2), 'en', true) : self::flagSvg('NP', (int) round($h * 1.2), 'en', true);

        return '<span class="flg-pair" style="display:inline-flex;align-items:center;gap:4px;vertical-align:middle">'
            . $in . ($sep !== '' ? '<span aria-hidden="true">' . htmlspecialchars($sep, ENT_QUOTES, 'UTF-8') . '</span>' : '') . $np . '</span>';
    }

    /** Transparent raster flag for GD / PDF (assets/img/flag-in.png 360x240, flag-np.png 282x344). */
    public static function flagPng(string $cc): ?string
    {
        $cc = self::cc($cc);
        if ($cc === '') {
            return null;
        }

        return self::localImage('assets/img/flag-' . strtolower($cc) . '.png');
    }

    /** Raw geometry for renderers that draw the flag themselves (tests, PDF primitives). */
    public static function flagGeometry(string $cc): ?array
    {
        $cc = self::cc($cc);

        return $cc === '' ? null : self::FLAGS[$cc];
    }

    /* -----------------------------------------------------------------
     *  internals
     * ----------------------------------------------------------------- */

    private static function root(): string
    {
        return defined('ROOT_PATH') ? (string) ROOT_PATH : dirname(__DIR__);
    }

    /** A readable local PNG / JPG (relative to the web root, or absolute), else null. */
    private static function localImage(string $rel): ?string
    {
        $rel = trim($rel);
        if ($rel === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $rel) === 1 || str_contains($rel, "\0")) {
            return null;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg'], true)) {
            return null;
        }
        $norm = str_replace('\\', '/', $rel);
        $cands = [self::root() . '/' . ltrim($norm, '/')];
        if (preg_match('#^([A-Za-z]:)?/#', $norm) === 1) {
            array_unshift($cands, $rel);    // already absolute
        }
        foreach ($cands as $p) {
            if (is_file($p) && is_readable($p)) {
                $sz = (int) @filesize($p);
                if ($sz > 0 && $sz < 2 * 1024 * 1024) {
                    return $p;
                }
            }
        }

        return null;
    }

    private static function cls(string $cc, string $extra): string
    {
        $c = 'flg flg-' . strtolower($cc);
        $extra = trim(preg_replace('/[^A-Za-z0-9 _-]/', '', $extra) ?? '');

        return $extra !== '' ? $c . ' ' . $extra : $c;
    }

    private static function a11y(string $cc, string $lang, bool $decorative): string
    {
        if ($decorative) {
            return ' aria-hidden="true" focusable="false"';
        }

        return ' role="img" aria-label="' . htmlspecialchars(self::flagLabel($cc, $lang), ENT_QUOTES, 'UTF-8') . '" focusable="false"';
    }

    private static function num(float $v): string
    {
        $s = rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }
}
