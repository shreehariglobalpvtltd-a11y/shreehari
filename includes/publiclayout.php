<?php
/**
 * =====================================================================
 *  PublicLayout — one shell for the server-rendered public pages
 *  (9 Oct 2026, the "showcase" upgrade).
 *
 *  Lifted from RoutePages::layout (which stays byte-for-byte as it was —
 *  tests/route-pages-test.php pins it) and grown into a shared page:
 *
 *    - the theme pre-paint every standalone page carries (light / dark /
 *      AMOLED from the app's own localStorage 'shg:theme'), so the page
 *      never flashes the wrong colours
 *    - Material You / Pixel-like styling: tonal surfaces, 24–28 px radii,
 *      pill buttons, an emphasized-easing reveal that switches off under
 *      prefers-reduced-motion and html[data-motion="reduced"]
 *    - a header with the transparent emblem (no white tile), the company
 *      name as the office saved it (Settings::company()), India + Nepal
 *      flags as inline SVG, an English / हिंदी / नेपाली switch and the
 *      section tabs
 *    - a footer with every public office (numbers from the database, never
 *      typed here) and the legal links
 *
 *  Inline CSS, no framework, no blocking request except the emblem image.
 *  Phone-first: 360 px and up, 44 px tap targets, no sideways scroll.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

final class PublicLayout
{
    public const LANGS = ['en', 'hi', 'ne'];
    public const LANG_NAMES = ['ne' => 'नेपाली', 'hi' => 'हिंदी', 'en' => 'English'];

    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Pick a language from ['en'=>…,'ne'=>…,'hi'=>…], falling back to English, then anything. */
    public static function pick(array $m, string $lang): string
    {
        foreach ([$lang, 'en', 'ne', 'hi'] as $l) {
            $v = trim((string) ($m[$l] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /** A column trio from a row: name_en / name_hi / name_ne → the visitor's language. */
    public static function col(array $row, string $base, string $lang): string
    {
        return self::pick(['en' => $row[$base . '_en'] ?? '', 'hi' => $row[$base . '_hi'] ?? '', 'ne' => $row[$base . '_ne'] ?? ''], $lang);
    }

    public static function base(): string
    {
        return rtrim(APP_URL, '/');
    }

    /** /path for English, /hi/path, /ne/path — absolute. */
    public static function url(string $path, string $lang): string
    {
        $path = '/' . ltrim($path, '/');
        return self::base() . ($lang === 'en' ? $path : '/' . $lang . $path);
    }

    /** The same, root-relative ("/ne/tours/x") — for JSON the home page links from any host. */
    public static function path(string $path, string $lang): string
    {
        $path = '/' . ltrim($path, '/');
        return $lang === 'en' ? $path : '/' . $lang . $path;
    }

    /** The four hreflang links (en, hi, ne, x-default) for one path. */
    public static function alts(string $path): string
    {
        $out = '';
        foreach (self::LANGS as $l) {
            $out .= '<link rel="alternate" hreflang="' . $l . '" href="' . self::e(self::url($path, $l)) . '">';
        }
        return $out . '<link rel="alternate" hreflang="x-default" href="' . self::e(self::url($path, 'en')) . '">';
    }

    /* ----------------------------------------------------------------
     *  Brand facts — all from Settings, never typed twice
     * ---------------------------------------------------------------- */

    /** @return array<string,string> Settings::company() (name, legal, legalNe, address, phone, whatsapp, email, cin, …) */
    public static function company(): array
    {
        static $co = null;
        if ($co === null) {
            $co = Settings::company();
        }
        return $co;
    }

    /** tel: href from any written number (spaces and dashes dropped, a leading + kept or added). */
    public static function telHref(string $phone): string
    {
        $d = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        if ($d === '') {
            return '';
        }
        if ($d[0] !== '+' && strlen($d) > 10) {
            $d = '+' . $d;
        }
        return 'tel:' . $d;
    }

    /** wa.me digits from a written number; a bare 10-digit number takes $cc. */
    public static function waDigits(string $phone, string $cc = '91'): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 10) {
            $d = $cc . $d;
        }
        return preg_match('/^[1-9]\d{9,14}$/', $d) === 1 ? $d : '';
    }

    /* ----------------------------------------------------------------
     *  Flags — Brand::flagSvg() when the brand helper is present, else a
     *  local copy of assets/img/flag-in.svg / flag-np.svg (explicit fills,
     *  so they render the same in light, dark and AMOLED).
     * ---------------------------------------------------------------- */
    /** The visitor's language — set by the page builders so flag labels read in it. */
    public static string $lang = 'en';

    /** Brand's sprite flags are available (includes/brand.php, 9 Oct 2026). */
    private static function brandFlags(): bool
    {
        if (!class_exists('Brand', false) && is_file(INCLUDE_PATH . '/brand.php')) {
            require_once INCLUDE_PATH . '/brand.php';
        }
        return class_exists('Brand', false) && method_exists('Brand', 'flagUse') && method_exists('Brand', 'flagSprite');
    }

    public static function flag(string $cc, int $h = 16): string
    {
        $cc = strtoupper($cc) === 'NP' ? 'NP' : 'IN';
        if (self::brandFlags()) {
            try {
                // Drawn from the page sprite (page() prints Brand::flagSprite() once):
                // a few dozen bytes a flag instead of ~1 KB — /explore prints 60+.
                $svg = (string) Brand::flagUse($cc, $h, self::$lang);
                if ($svg !== '') {
                    return $svg;
                }
            } catch (Throwable $e) {
                // fall through to the local copy
            }
        }
        if ($cc === 'NP') {
            $w = (int) round($h * 66 / 86);
            return '<svg class="flg" viewBox="-3 -3 66 86" width="' . $w . '" height="' . $h . '" role="img" aria-label="Nepal">'
                . '<path d="M0,0 L60,37.574 L17.574,37.574 L60,80 L0,80 Z" fill="#003893" stroke="#003893" stroke-width="4.6" stroke-linejoin="miter"/>'
                . '<path d="M0,0 L60,37.574 L17.574,37.574 L60,80 L0,80 Z" fill="#DC143C"/>'
                . '<g fill="#fff"><path d="M5.6,24.6 A9.4,9.4 0 0 0 24.4,24.6 A9.6,7.6 0 0 1 5.6,24.6 Z"/>'
                . '<path d="M17.4,18.4 L17.8,21.4 L20.8,21.8 L19,24.2 L20.8,26.6 L17.8,27 L17.4,30 L15,28.2 L12.6,30 L12.2,27 L9.2,26.6 L11,24.2 L9.2,21.8 L12.2,21.4 L12.6,18.4 L15,20.2 Z"/>'
                . '<path d="M15,48.4 L17,52.7 L20.8,50 L20.4,54.6 L25,54.2 L22.3,58 L26.6,60 L22.3,62 L25,65.8 L20.4,65.4 L20.8,70 L17,67.3 L15,71.6 L13,67.3 L9.2,70 L9.6,65.4 L5,65.8 L7.7,62 L3.4,60 L7.7,58 L5,54.2 L9.6,54.6 L9.2,50 L13,52.7 Z"/>'
                . '<circle cx="15" cy="60" r="7.2"/></g></svg>';
        }
        $w = (int) round($h * 1.5);
        $spokes = '';
        for ($i = 0; $i < 24; $i++) {
            $a = M_PI * 2 * $i / 24;
            $spokes .= sprintf('M45,30 L%.2f,%.2f ', 45 + 8.4 * sin($a), 30 - 8.4 * cos($a));
        }
        return '<svg class="flg" viewBox="0 0 90 60" width="' . $w . '" height="' . $h . '" role="img" aria-label="India">'
            . '<rect width="90" height="20" fill="#FF9933"/><rect y="20" width="90" height="20" fill="#fff"/><rect y="40" width="90" height="20" fill="#138808"/>'
            . '<g fill="none" stroke="#000080"><circle cx="45" cy="30" r="8.6" stroke-width="1.3"/><path d="' . trim($spokes) . '" stroke-width="0.55"/></g>'
            . '<circle cx="45" cy="30" r="1.6" fill="#000080"/></svg>';
    }

    /* ----------------------------------------------------------------
     *  Offices — ONE list for the footer and /offices.
     *
     *  1. SiteFacts::all()['offices'] (the facts package, 9 Oct 2026)
     *  2. CounterDesk::publicOffices()
     *  3. the owner's list (Mehsana head, Nepalgunj, Rajkot, Ahmedabad,
     *     Surat) with numbers read from the database: the desk's phone
     *     when set, else the town's number in the company_counters setting,
     *     the Nepal office from the Nepal settings. A town with no number
     *     still shows, as "number coming soon" — never an invented one.
     *
     *  @return list<array{code:string,name:string,nameNe:string,city:string,kind:string,status:string,
     *                     country:string,phone:string,tel:string,wa:string,address:string,hours:string,mapUrl:string}>
     * ---------------------------------------------------------------- */
    public static function offices(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $raw = [];
        try {
            if (!class_exists('SiteFacts', false) && is_file(INCLUDE_PATH . '/sitefacts.php')) {
                require_once INCLUDE_PATH . '/sitefacts.php';
            }
            if (class_exists('SiteFacts', false) && method_exists('SiteFacts', 'all')) {
                $f = SiteFacts::all();
                if (is_array($f['offices'] ?? null)) {
                    $raw = $f['offices'];
                }
            }
            if ($raw === [] && class_exists('CounterDesk', false) && method_exists('CounterDesk', 'publicOffices')) {
                $raw = (array) CounterDesk::publicOffices();
            }
        } catch (Throwable $e) {
            Logger::warning('PublicLayout: office facts unavailable: ' . $e->getMessage());
            $raw = [];
        }
        if ($raw === []) {
            $raw = self::fallbackOffices();
        }
        $out = [];
        foreach ($raw as $o) {
            if (!is_array($o)) {
                continue;
            }
            $country = strtoupper((string) ($o['country'] ?? 'IN')) === 'NP' ? 'NP' : 'IN';
            $phone = trim((string) ($o['phone'] ?? ''));
            $telIn = preg_replace('/\D/', '', (string) ($o['tel'] ?? '')) ?? '';
            $tel = $phone !== '' ? self::telHref($phone) : ($telIn !== '' ? 'tel:+' . $telIn : '');
            $wa = preg_replace('/\D/', '', (string) ($o['wa'] ?? $o['whatsapp'] ?? '')) ?? '';
            if ($wa === '' && $phone !== '') {
                $wa = self::waDigits($phone, $country === 'NP' ? '977' : '91');
            }
            $name = trim((string) ($o['name'] ?? $o['city'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'code'    => (string) ($o['code'] ?? ''),
                'name'    => $name,
                'nameNe'  => trim((string) ($o['nameNe'] ?? $o['name_ne'] ?? '')),
                'nameHi'  => trim((string) ($o['nameHi'] ?? $o['name_hi'] ?? '')),
                'city'    => trim((string) ($o['city'] ?? $name)),
                'kind'    => in_array($o['kind'] ?? '', ['head', 'branch', 'partner', 'border'], true) ? (string) $o['kind'] : 'branch',
                'status'  => ($o['status'] ?? 'open') === 'coming_soon' ? 'coming_soon' : 'open',
                'country' => $country,
                'phone'   => $phone,
                'tel'     => $tel,
                'wa'      => $wa,
                'address' => trim((string) ($o['address'] ?? '')),
                'addressNe' => trim((string) ($o['addressNe'] ?? $o['address_ne'] ?? '')),
                'hours'   => trim((string) ($o['hours'] ?? '')),
                'mapUrl'  => trim((string) ($o['mapUrl'] ?? $o['map_url'] ?? '')),
            ];
        }
        // Head office first, then the order given.
        usort($out, static fn(array $a, array $b): int => ($a['kind'] === 'head' ? 0 : 1) <=> ($b['kind'] === 'head' ? 0 : 1));
        return $cache = $out;
    }

    /** @return list<array<string,string>> */
    private static function fallbackOffices(): array
    {
        $co = self::company();
        $towns = [];
        foreach (preg_split('/\s*[\x{00B7}|;]\s*/u', (string) ($co['counters'] ?? '')) ?: [] as $part) {
            if (preg_match('/^(.*?)\s*(\+?\d[\d ]{7,})$/u', trim($part), $m) === 1) {
                $towns[mb_strtolower(trim($m[1]))] = trim($m[2]);
            }
        }
        $desk = static function (string $code): array {
            try {
                return class_exists('CounterDesk', false) ? (CounterDesk::get($code) ?? []) : [];
            } catch (Throwable $e) {
                return [];
            }
        };
        $npPhone = trim(Settings::getString('nepal_phone', ''));
        if ($npPhone === '') {
            foreach (Settings::nepalTeam() as $p) {
                if (($p['key'] ?? '') === 'office') {
                    $npPhone = (string) $p['phone'];
                }
            }
        }
        $npAddr = trim(Settings::getString('nepal_office', ''));
        $rows = [
            ['MSA', 'Mehsana', 'मेहसाणा', 'मेहसाणा', 'head', 'IN', (string) $co['phone'], (string) $co['address'], (string) ($co['addressNe'] ?? '')],
            ['NPJ', 'Nepalgunj', 'नेपालगञ्ज', 'नेपालगंज', 'border', 'NP', $npPhone, $npAddr !== '' ? $npAddr : 'Puspalal Chowk, Nepalgunj', trim(Settings::getString('nepal_office_ne', ''))],
            ['RJT', 'Rajkot', 'राजकोट', 'राजकोट', 'branch', 'IN', $towns['rajkot'] ?? '', '', ''],
            ['AMD', 'Ahmedabad', 'अहमदाबाद', 'अहमदाबाद', 'branch', 'IN', $towns['ahmedabad'] ?? '', '', ''],
            ['SRT', 'Surat', 'सुरत', 'सूरत', 'branch', 'IN', $towns['surat'] ?? '', '', ''],
        ];
        $out = [];
        foreach ($rows as [$code, $city, $ne, $hi, $kind, $country, $phone, $addr, $addrNe]) {
            $d = $desk($code);
            $dPhone = trim((string) ($d['phone'] ?? ''));
            $out[] = [
                'code' => $code, 'name' => $city, 'nameNe' => $ne, 'nameHi' => $hi, 'city' => $city,
                'kind' => $kind, 'status' => 'open', 'country' => $country,
                'phone' => $dPhone !== '' ? $dPhone : $phone,
                'address' => $addr !== '' ? $addr : trim((string) ($d['address'] ?? '')),
                'addressNe' => $addrNe,
                'hours' => trim((string) ($d['hours'] ?? '')),
                'mapUrl' => trim((string) ($d['map_url'] ?? '')),
            ];
        }
        return $out;
    }

    /** An office's display name in the visitor's language. */
    public static function officeName(array $o, string $lang): string
    {
        if ($lang === 'ne' && $o['nameNe'] !== '') {
            return $o['nameNe'];
        }
        if ($lang === 'hi' && ($o['nameHi'] ?? '') !== '') {
            return $o['nameHi'];
        }
        if ($lang === 'hi' && $o['nameNe'] !== '') {
            return $o['nameNe'];
        }
        return $o['name'];
    }

    /* ----------------------------------------------------------------
     *  Words for the shell (three languages, one place)
     * ---------------------------------------------------------------- */
    /** @return array<string,string> */
    public static function words(string $lang): array
    {
        $w = [
            'en' => ['skip' => 'Skip to content', 'book' => 'Book a seat', 'home' => 'Home', 'indiaNepal' => 'India–Nepal', 'offices' => 'Offices',
                     'explore' => 'Places to visit', 'tours' => 'Tours', 'travel' => 'Border travel info', 'site' => 'Site sections', 'lang' => 'Language',
                     'theme' => 'Change theme', 'privacy' => 'Privacy', 'terms' => 'Terms', 'refund' => 'Refund policy', 'allRoutes' => 'All bus routes',
                     'ourOffices' => 'Our offices', 'head' => 'Head office', 'soonNum' => 'Number coming soon', 'tag' => 'AC sleeper bus · Gujarat ⇄ India–Nepal border',
                     'company' => 'Company', 'help' => 'Help desk'],
            'hi' => ['skip' => 'सामग्री पर जाएँ', 'book' => 'सीट बुक करें', 'home' => 'होम', 'indiaNepal' => 'भारत–नेपाल', 'offices' => 'कार्यालय',
                     'explore' => 'घूमने की जगहें', 'tours' => 'टूर', 'travel' => 'बॉर्डर यात्रा जानकारी', 'site' => 'साइट के हिस्से', 'lang' => 'भाषा',
                     'theme' => 'थीम बदलें', 'privacy' => 'गोपनीयता', 'terms' => 'शर्तें', 'refund' => 'रिफंड नीति', 'allRoutes' => 'सभी बस रूट',
                     'ourOffices' => 'हमारे कार्यालय', 'head' => 'मुख्य कार्यालय', 'soonNum' => 'नंबर जल्द', 'tag' => 'AC स्लीपर बस · गुजरात ⇄ भारत–नेपाल सीमा',
                     'company' => 'कंपनी', 'help' => 'हेल्प डेस्क'],
            'ne' => ['skip' => 'सामग्रीमा जानुहोस्', 'book' => 'सिट बुक गर्नुहोस्', 'home' => 'गृहपृष्ठ', 'indiaNepal' => 'भारत–नेपाल', 'offices' => 'कार्यालय',
                     'explore' => 'घुम्ने ठाउँ', 'tours' => 'टुर', 'travel' => 'बोर्डर यात्रा जानकारी', 'site' => 'साइटका भाग', 'lang' => 'भाषा',
                     'theme' => 'थिम बदल्नुहोस्', 'privacy' => 'गोपनीयता', 'terms' => 'सर्तहरू', 'refund' => 'फिर्ता नीति', 'allRoutes' => 'सबै बस रुट',
                     'ourOffices' => 'हाम्रा कार्यालय', 'head' => 'प्रधान कार्यालय', 'soonNum' => 'नम्बर छिट्टै', 'tag' => 'AC स्लिपर बस · गुजरात ⇄ भारत–नेपाल सिमाना',
                     'company' => 'कम्पनी', 'help' => 'सहायता डेस्क'],
        ];
        return $w[$lang] ?? $w['en'];
    }

    /* ----------------------------------------------------------------
     *  The page
     *
     *  $opts:
     *    path      the language-neutral path ('/offices') — drives the
     *              language switch (defaults to the canonical's path)
     *    active    'india-nepal' | 'offices' | 'explore' | 'tours'
     *    crumbs    [[name, absolute url], …] — rendered as a trail (the
     *              JSON-LD BreadcrumbList is the caller's)
     *    noindex   true for pages that must stay out of search
     *    css       extra page CSS, appended to the shell's
     * ---------------------------------------------------------------- */
    public static function page(string $lang, string $title, string $desc, string $canonical, string $altsHtml, string $ldJson, string $bodyHtml, array $opts = []): string
    {
        $lang = in_array($lang, self::LANGS, true) ? $lang : 'en';
        $e = Closure::fromCallable([self::class, 'e']);
        $w = self::words($lang);
        $co = self::company();
        $path = (string) ($opts['path'] ?? '');
        if ($path === '') {
            $p = (string) (parse_url($canonical, PHP_URL_PATH) ?: '/');
            $path = (string) preg_replace('#^/(hi|ne)(?=/|$)#', '', $p);
            $path = $path === '' ? '/' : $path;
        }
        $active = (string) ($opts['active'] ?? '');
        $ld = $ldJson !== '' ? '<script type="application/ld+json">' . $ldJson . '</script>' : '';
        $robots = !empty($opts['noindex']) ? '<meta name="robots" content="noindex">' : '';

        $langNav = '';
        $short = ['ne' => 'ने', 'hi' => 'हि', 'en' => 'EN'];
        foreach (self::LANG_NAMES as $l => $lab) {
            // Full name from 480 px up, a two-letter pill on a phone (the header stays one row).
            $langNav .= '<a href="' . $e(self::url($path, $l)) . '" hreflang="' . $l . '" lang="' . $l . '" title="' . $lab . '"' . ($l === $lang ? ' aria-current="true"' : '') . '>'
                . '<span class="lf">' . $lab . '</span><span class="ls" aria-hidden="true">' . $short[$l] . '</span></a>';
        }
        $sprite = self::brandFlags() ? Brand::flagSprite() : '';
        $tabs = '';
        foreach (['india-nepal' => ['🤝', 'indiaNepal'], 'offices' => ['🏢', 'offices'], 'explore' => ['🏔️', 'explore'], 'tours' => ['🧳', 'tours']] as $k => [$ic, $wk]) {
            $tabs .= '<a href="' . $e(self::url('/' . $k, $lang)) . '"' . ($k === $active ? ' aria-current="page"' : '') . '><span aria-hidden="true">' . $ic . '</span>' . $e($w[$wk]) . '</a>';
        }
        $tabs .= '<a href="/travel-info"><span aria-hidden="true">🛂</span>' . $e($w['travel']) . '</a>';

        $crumbs = '';
        if (!empty($opts['crumbs']) && is_array($opts['crumbs'])) {
            $parts = [];
            $n = count($opts['crumbs']);
            foreach (array_values($opts['crumbs']) as $i => [$cn, $cu]) {
                $parts[] = $i === $n - 1 ? '<span aria-current="page">' . $e((string) $cn) . '</span>' : '<a href="' . $e((string) $cu) . '">' . $e((string) $cn) . '</a>';
            }
            $crumbs = '<nav class="crumbs" aria-label="Breadcrumb">' . implode('<span aria-hidden="true">›</span>', $parts) . '</nav>';
        }

        $offices = '';
        foreach (self::offices() as $o) {
            $nm = self::officeName($o, $lang);
            $offices .= '<li>' . self::flag($o['country'], 12) . ' <b>' . $e($nm) . '</b>' . ($o['kind'] === 'head' ? ' <small>· ' . $e($w['head']) . '</small>' : '')
                . ($o['phone'] !== '' && $o['tel'] !== '' ? ' <a href="' . $e($o['tel']) . '">' . $e($o['phone']) . '</a>' : ' <small class="mut">' . $e($o['status'] === 'coming_soon' ? '' : $w['soonNum']) . '</small>')
                . '</li>';
        }
        $year = date('Y');
        $logo = '<picture><source srcset="/assets/img/logo-mark-96h.webp" type="image/webp"><img class="logo" src="/assets/img/logo-mark-96h.png" width="185" height="96" alt="' . $e($co['name']) . '"></picture>';
        $fl = '<span class="flags" aria-label="India · Nepal">' . self::flag('IN', 18) . self::flag('NP', 20) . '</span>';
        $book = $e($w['book']);
        $css = self::css() . (string) ($opts['css'] ?? '');

        return <<<HTML
<!doctype html>
<html lang="{$e($lang)}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>{$e($title)}</title>
<meta name="description" content="{$e($desc)}">
{$robots}<link rel="canonical" href="{$e($canonical)}">
{$altsHtml}
<meta property="og:title" content="{$e($title)}"><meta property="og:description" content="{$e($desc)}"><meta property="og:url" content="{$e($canonical)}"><meta property="og:type" content="website"><meta property="og:image" content="{$e(self::base())}/assets/img/og-shg.png"><meta property="og:locale" content="{$e($lang === 'en' ? 'en_IN' : ($lang === 'hi' ? 'hi_IN' : 'ne_NP'))}">
<meta name="theme-color" content="#0C306C">
<link rel="icon" href="/assets/img/favicon-32.png">
<script>(function(){try{var d=document.documentElement,t=(localStorage.getItem('shg:theme')||'').replace(/"/g,'');if(t!=='light'&&t!=='dark'&&t!=='amoled')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';d.setAttribute('data-theme',t==='light'?'light':'dark');if(t==='amoled')d.setAttribute('data-display','amoled');var m=(localStorage.getItem('shg:motion')||'').replace(/"/g,'');if(m==='reduced')d.setAttribute('data-motion','reduced');if('IntersectionObserver' in window&&m!=='reduced'&&!matchMedia('(prefers-reduced-motion: reduce)').matches)d.classList.add('rv-on')}catch(e){}})();</script>
{$ld}
<style>{$css}</style>
</head>
<body>
{$sprite}<a class="skip" href="#main">{$e($w['skip'])}</a>
<header class="appbar">
  <div class="bar">
    <a class="brand" href="/">{$logo}<span class="bn">{$e($co['name'])}</span></a>
    {$fl}
    <div class="tools">
      <nav class="langs" aria-label="{$e($w['lang'])}">{$langNav}</nav>
      <button type="button" class="themebtn" id="shgTheme" aria-label="{$e($w['theme'])}" title="{$e($w['theme'])}"><span aria-hidden="true">◐</span></button>
    </div>
  </div>
  <nav class="tabs" aria-label="{$e($w['site'])}"><a class="tab-book" href="/#/"><span aria-hidden="true">🎫</span>{$book}</a>{$tabs}</nav>
</header>
<main id="main">
{$crumbs}
{$bodyHtml}
</main>
<footer class="foot">
  <div class="foot-in">
    <div class="foot-brand">{$logo}<div><b>{$e($co['legal'])}</b><small>{$e($w['tag'])}</small><small>CIN {$e($co['cin'])}</small></div>{$fl}</div>
    <div class="foot-cols">
      <section><h2>{$e($w['ourOffices'])}</h2><ul class="foot-off">{$offices}</ul></section>
      <section><h2>{$e($w['company'])}</h2><ul class="foot-links">
        <li><a href="/#/">{$book}</a></li>
        <li><a href="{$e(self::url('/india-nepal', $lang))}">{$e($w['indiaNepal'])}</a></li>
        <li><a href="{$e(self::url('/offices', $lang))}">{$e($w['offices'])}</a></li>
        <li><a href="{$e(self::url('/explore', $lang))}">{$e($w['explore'])}</a></li>
        <li><a href="{$e(self::url('/tours', $lang))}">{$e($w['tours'])}</a></li>
        <li><a href="/travel-info">{$e($w['travel'])}</a></li>
        <li><a href="{$e($lang === 'en' ? '/bus' : '/' . $lang . '/bus')}">{$e($w['allRoutes'])}</a></li>
        <li><a href="/help.php">{$e($w['help'])}</a></li>
      </ul></section>
    </div>
    <p class="legal"><a href="/privacy-policy">{$e($w['privacy'])}</a> · <a href="/terms-of-service">{$e($w['terms'])}</a> · <a href="/refund-policy">{$e($w['refund'])}</a> · <span>© {$year} {$e($co['legal'])}</span></p>
  </div>
</footer>
<script>(function(){var d=document.documentElement;if(!d.classList.contains('rv-on'))return;var els=document.querySelectorAll('.rv');var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add('in');io.unobserve(x.target);}});},{rootMargin:'0px 0px -6% 0px'});els.forEach(function(el){io.observe(el)});addEventListener('beforeprint',function(){els.forEach(function(el){el.classList.add('in')})});})();</script>
<script>(function(){var b=document.getElementById('shgTheme');if(!b)return;b.addEventListener('click',function(){var d=document.documentElement,c=d.getAttribute('data-display')==='amoled'?'amoled':d.getAttribute('data-theme'),n=c==='light'?'dark':(c==='dark'?'amoled':'light');d.setAttribute('data-theme',n==='light'?'light':'dark');if(n==='amoled')d.setAttribute('data-display','amoled');else d.removeAttribute('data-display');try{localStorage.setItem('shg:theme',n)}catch(e){}});})();</script>
</body>
</html>
HTML;
    }

    /**
     * The shared stylesheet: design tokens (assets/css/ds.css palette, Pixel
     * Material-You roles on top), light / dark / AMOLED, components.
     */
    public static function css(): string
    {
        return <<<'CSS'
:root{--md-primary:#1859C3;--md-on-primary:#fff;--md-primary-container:#DCE2FF;--md-on-primary-container:#001944;--md-secondary-container:#DFE0FF;--md-on-secondary-container:#161A30;--md-tertiary:#8C4A60;--md-tertiary-container:#FFD8E4;--md-on-tertiary-container:#3A071D;--md-surface:#F9F9FF;--md-sc-lowest:#fff;--md-sc-low:#F3F3FB;--md-sc:#EDEDF5;--md-sc-high:#E7E7EF;--md-sc-highest:#E2E2E9;--md-on-surface:#1B1B21;--md-on-surface-variant:#454652;--md-outline:#757684;--md-outline-variant:#C5C5D4;--navy:#0C306C;--red:#DC143C;--gold:#EFB544;--wa:#11703F;--on-wa:#fff;--ok:#146C2E;--ok-container:#C8F0CF;--focus:#1859C3;--md-r-card:26px;--ease:cubic-bezier(.2,0,0,1);--shadow:0 1px 2px rgba(15,30,70,.08),0 4px 14px rgba(15,30,70,.07);color-scheme:light}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--md-primary:#B5C4FF;--md-on-primary:#002A77;--md-primary-container:#0F3F9A;--md-on-primary-container:#DCE2FF;--md-secondary-container:#3F445C;--md-on-secondary-container:#DFE0FF;--md-tertiary:#F2B4C9;--md-tertiary-container:#6B3346;--md-on-tertiary-container:#FFD8E4;--md-surface:#121318;--md-sc-lowest:#0D0E13;--md-sc-low:#1A1B21;--md-sc:#1F1F25;--md-sc-high:#292A2F;--md-sc-highest:#34343A;--md-on-surface:#E3E2E9;--md-on-surface-variant:#C5C6D4;--md-outline:#8F909E;--md-outline-variant:#454652;--navy:#0F1A2E;--wa:#5FD39A;--on-wa:#00210F;--ok:#8CD89A;--ok-container:#0C3A1A;--focus:#B5C4FF;--shadow:0 1px 2px rgba(0,0,0,.4),0 6px 18px rgba(0,0,0,.35);color-scheme:dark}}
:root[data-theme="dark"]{--md-primary:#B5C4FF;--md-on-primary:#002A77;--md-primary-container:#0F3F9A;--md-on-primary-container:#DCE2FF;--md-secondary-container:#3F445C;--md-on-secondary-container:#DFE0FF;--md-tertiary:#F2B4C9;--md-tertiary-container:#6B3346;--md-on-tertiary-container:#FFD8E4;--md-surface:#121318;--md-sc-lowest:#0D0E13;--md-sc-low:#1A1B21;--md-sc:#1F1F25;--md-sc-high:#292A2F;--md-sc-highest:#34343A;--md-on-surface:#E3E2E9;--md-on-surface-variant:#C5C6D4;--md-outline:#8F909E;--md-outline-variant:#454652;--navy:#0F1A2E;--wa:#5FD39A;--on-wa:#00210F;--ok:#8CD89A;--ok-container:#0C3A1A;--focus:#B5C4FF;--shadow:0 1px 2px rgba(0,0,0,.4),0 6px 18px rgba(0,0,0,.35);color-scheme:dark}
:root[data-display="amoled"]{--md-surface:#000;--md-sc-lowest:#000;--md-sc-low:#0B0C10;--md-sc:#111217;--md-sc-high:#17181D;--md-sc-highest:#1E1F25;--navy:#000;--md-outline-variant:#2C2D36;--shadow:none}
*{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
body{font-family:'Google Sans Text','Roboto',-apple-system,BlinkMacSystemFont,'Segoe UI','Noto Sans Devanagari','Noto Sans',sans-serif;background:var(--md-surface);color:var(--md-on-surface);line-height:1.6;-webkit-font-smoothing:antialiased;overflow-wrap:anywhere}
img,svg{max-width:100%}
a{color:var(--md-primary)}
a:focus-visible,button:focus-visible,summary:focus-visible,input:focus-visible,select:focus-visible,textarea:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
.skip{position:absolute;left:-999px;top:8px;background:var(--md-primary);color:var(--md-on-primary);padding:10px 16px;border-radius:999px;z-index:20}.skip:focus{left:12px}
.mut{color:var(--md-on-surface-variant)}
.flg{display:inline-block;vertical-align:-2px;border-radius:2px;flex:none}
/* app bar */
.appbar{position:sticky;top:0;z-index:10;background:color-mix(in srgb,var(--md-sc-low) 88%,transparent);-webkit-backdrop-filter:saturate(1.4) blur(14px);backdrop-filter:saturate(1.4) blur(14px);border-bottom:1px solid var(--md-outline-variant)}
.bar{max-width:1040px;margin:0 auto;display:flex;align-items:center;gap:10px;padding:8px 16px;flex-wrap:wrap}
.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--md-on-surface);font-weight:800;min-height:44px;min-width:0;flex:1 1 auto}
.brand .logo{height:40px;width:auto;display:block}
.bn{font-size:15.5px;line-height:1.2;letter-spacing:-.01em}
.flags{display:inline-flex;gap:6px;align-items:center}
.tools{display:flex;align-items:center;gap:6px;margin-left:auto}
.langs{display:inline-flex;background:var(--md-sc-high);border-radius:999px;padding:3px}
.langs a{display:inline-flex;align-items:center;min-height:38px;padding:0 11px;border-radius:999px;font-size:13px;font-weight:700;color:var(--md-on-surface-variant);text-decoration:none;transition:background .2s var(--ease),color .2s}
.langs a[aria-current]{background:var(--md-primary);color:var(--md-on-primary)}
.themebtn{width:44px;height:44px;border-radius:50%;border:0;background:var(--md-sc-high);color:var(--md-on-surface);font-size:18px;cursor:pointer}
.tabs{max-width:1040px;margin:0 auto;display:flex;gap:8px;overflow-x:auto;padding:2px 16px 10px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.tabs::-webkit-scrollbar{display:none}
.tabs a{flex:none;display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:0 14px;border-radius:12px;border:1px solid var(--md-outline-variant);font-size:13.5px;font-weight:700;color:var(--md-on-surface-variant);text-decoration:none;background:var(--md-sc-lowest);transition:background .2s var(--ease)}
.tabs a[aria-current]{background:var(--md-secondary-container);color:var(--md-on-secondary-container);border-color:transparent}
.tabs a.tab-book{background:var(--md-primary);color:var(--md-on-primary);border-color:transparent}
/* layout */
main{max-width:1040px;margin:0 auto;padding:12px 16px 48px}
.crumbs{display:flex;flex-wrap:wrap;gap:6px;font-size:13px;color:var(--md-on-surface-variant);margin:4px 0 12px}.crumbs a{color:inherit}
h1{font-size:clamp(28px,7vw,44px);line-height:1.12;letter-spacing:-.02em;margin:0 0 10px}
h2{font-size:clamp(20px,4.6vw,26px);line-height:1.25;letter-spacing:-.01em;margin:0 0 12px}
h3{font-size:17px;line-height:1.3;margin:0 0 6px}
.lead{font-size:17px;color:var(--md-on-surface-variant);max-width:62ch}
.sec{margin:28px 0}
.sec-h{display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap}
/* hero */
.hero{position:relative;overflow:hidden;border-radius:32px;padding:26px 20px 22px;background:linear-gradient(135deg,var(--md-primary-container),var(--md-tertiary-container));color:var(--md-on-primary-container);box-shadow:var(--shadow);isolation:isolate}
.hero .lead{color:inherit;opacity:.88}
.hero .kick{display:inline-flex;align-items:center;gap:8px;font-size:13px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;background:color-mix(in srgb,var(--md-sc-lowest) 60%,transparent);padding:6px 12px;border-radius:999px;margin-bottom:12px}
.hero-art{display:block;width:100%;height:auto;border-radius:22px;margin:16px 0 0;aspect-ratio:1080/300;object-fit:cover;background:var(--navy)}
.hero-flags{display:flex;gap:12px;align-items:center;margin:14px 0 0;flex-wrap:wrap}
a.flagchip{text-decoration:none}a.flagchip:hover{background:var(--md-sc-high)}
.flagchip{display:inline-flex;align-items:center;gap:8px;padding:8px 14px 8px 10px;border-radius:999px;background:var(--md-sc-lowest);color:var(--md-on-surface);font-weight:800;font-size:14px;box-shadow:var(--shadow)}
.hero .blob{position:absolute;border-radius:50%;filter:blur(40px);opacity:.28;z-index:-1;pointer-events:none}
.hero .b1{width:220px;height:220px;right:-60px;top:-70px;background:var(--md-primary)}
.hero .b2{width:160px;height:160px;left:-50px;bottom:-60px;background:var(--md-tertiary)}
/* buttons */
.btns{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0 0}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:0 20px;border-radius:999px;font-weight:800;font-size:15px;text-decoration:none;border:1px solid transparent;cursor:pointer;font-family:inherit;transition:transform .2s var(--ease),box-shadow .2s var(--ease),background .2s}
.btn:active{transform:scale(.97)}
.btn.fill{background:var(--md-primary);color:var(--md-on-primary);box-shadow:0 2px 6px color-mix(in srgb,var(--md-primary) 35%,transparent)}
.btn.tonal{background:var(--md-secondary-container);color:var(--md-on-secondary-container)}
.btn.out{border-color:var(--md-outline);color:var(--md-primary);background:transparent}
.btn.wa{background:var(--wa);color:var(--on-wa)}
.btn.sm{min-height:44px;padding:0 16px;font-size:14px}
/* cards */
.card{background:var(--md-sc-low);border-radius:var(--md-r-card);padding:18px;box-shadow:var(--shadow)}
.grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr))}
.chip{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:700;padding:5px 11px;border-radius:8px;background:var(--md-sc-high);color:var(--md-on-surface-variant);text-decoration:none}
.chip.on,.chip[aria-current]{background:var(--md-secondary-container);color:var(--md-on-secondary-container)}
.chip.gold{background:#FFF1CC;color:#5C4200}
.chips{display:flex;flex-wrap:wrap;gap:8px}
.filters{display:flex;gap:8px;overflow-x:auto;padding:4px 0 8px;scrollbar-width:none}.filters .chip{flex:none;min-height:40px;font-size:13.5px;padding:0 14px;border:1px solid var(--md-outline-variant);background:var(--md-sc-lowest)}
.note{font-size:13.5px;color:var(--md-on-surface-variant);margin-top:10px}
.empty{text-align:center;padding:30px 18px;border:2px dashed var(--md-outline-variant);border-radius:var(--md-r-card);background:var(--md-sc-lowest)}
.empty .big{font-size:40px;line-height:1}
.stats{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(min(100%,160px),1fr))}
.stat{background:var(--md-sc-low);border-radius:22px;padding:16px}.stat b{display:block;font-size:30px;line-height:1.1;color:var(--md-primary);letter-spacing:-.02em}.stat span{font-size:13.5px;color:var(--md-on-surface-variant)}
.disclaimer{display:flex;gap:10px;align-items:flex-start;background:var(--md-sc);border-radius:20px;padding:14px 16px;font-size:14px;color:var(--md-on-surface-variant)}
.sources{font-size:12.5px;color:var(--md-on-surface-variant);padding-left:18px}.sources li{margin:3px 0}
.band{border-radius:28px;padding:22px 20px;background:linear-gradient(120deg,var(--navy),#123F8C);color:#fff;position:relative;overflow:hidden}
.band h2{color:#fff}.band p{color:#D7E2F7}
.band .btn.fill{background:#fff;color:#0C306C}.band .btn.out{border-color:rgba(255,255,255,.6);color:#fff}
:root[data-display="amoled"] .band{background:#0B0C10;border:1px solid var(--md-outline-variant)}
/* reveal: a soft rise as each block enters, only where scroll timelines exist and motion is welcome */
@keyframes shg-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}
html.rv-on .rv{opacity:0;transform:translateY(22px) scale(.985);transition:opacity .6s var(--ease),transform .6s var(--ease)}
html.rv-on .rv.in{opacity:1;transform:none}
@media (prefers-reduced-motion:no-preference){html:not([data-motion="reduced"]) .float{animation:shg-float 5s var(--ease) infinite}html:not([data-motion="reduced"]) .float:nth-child(2){animation-delay:-2.5s}}
/* footer */
.foot{background:var(--md-sc);border-top:1px solid var(--md-outline-variant);margin-top:24px}
.foot-in{max-width:1040px;margin:0 auto;padding:24px 16px calc(28px + env(safe-area-inset-bottom))}
.foot-brand{display:flex;align-items:center;gap:12px;flex-wrap:wrap}.foot-brand .logo{height:46px;width:auto}.foot-brand b{display:block}.foot-brand small{display:block;color:var(--md-on-surface-variant);font-size:12.5px}
.foot-cols{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));margin:18px 0}
.foot h2{font-size:15px;margin:0 0 8px}
.foot ul{list-style:none}.foot li{margin:6px 0;font-size:14px}
.foot-off a{font-weight:700;text-decoration:none;white-space:nowrap}
.foot-links a{display:inline-flex;min-height:32px;align-items:center;color:var(--md-on-surface);text-decoration:none}
.legal{font-size:12.5px;color:var(--md-on-surface-variant)}.legal a{color:inherit}
main [id]{scroll-margin-top:124px}
.langs .ls{display:none}
.foot-links{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 14px}.foot-links li{margin:2px 0}
@media (min-width:720px){.hero{padding:40px 36px 30px}.bn{font-size:17px}}
@media (max-width:479px){.bar{flex-wrap:nowrap;gap:8px;padding:6px 12px 6px 14px}.brand{gap:8px}.brand .logo{height:34px}.bn{font-size:13px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .langs .lf{display:none}.langs .ls{display:inline}.langs a{min-width:38px;min-height:40px;padding:0 6px;justify-content:center}.bar .flags{display:none}.tabs{padding:0 12px 8px}}
@media (max-width:369px){.brand .logo{height:28px}.bn{font-size:12px}.tools{gap:4px}}
@media print{.appbar,.tabs,.foot,.btns,.themebtn{display:none}.card,.hero{box-shadow:none}html.rv-on .rv{opacity:1;transform:none}}
CSS;
    }
}
