<?php
/**
 * =====================================================================
 *  ContentPages — the brand / travel pages of the S Hari Global
 *  ecosystem (9 Oct 2026, owner: "India–Nepal relation timeline, office
 *  haru listed, ghumne thau, tour package apply garna milne").
 *
 *      /india-nepal          the two countries' story + open border summary
 *      /offices              every public office, head office first
 *      /explore              places to visit — Nepal, India, near the border
 *      /tours                tour packages (partner-operated beyond Rupaidiha)
 *      /tours/<slug>         one package + its "Apply / Enquire" form
 *      /hi/…  /ne/…          the same pages in Hindi and Nepali
 *      /sitemap-content.xml  every page above with hreflang alternates
 *
 *  Server-rendered, one shared shell (PublicLayout), no app bundle. The
 *  words for the pages live here; the content lives in the tables
 *  travel_places / tour_packages (Admin -> Website content) and in
 *  includes/content/indianepal.php. Until those are filled every page
 *  shows a tidy "coming soon" state — never a crash, never invented facts.
 *
 *  Pages embed NO CSRF token, so they may be cached (public only when the
 *  answer sets no cookie). The enquiry form fetches its token with a GET
 *  from api/tour-enquiry.php.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

require_once INCLUDE_PATH . '/publiclayout.php';

final class ContentPages
{
    public const PAGES = ['india-nepal', 'offices', 'explore', 'tours'];

    /** category => icon. Anything else is shown as written. */
    public const CATS = [
        'pilgrimage' => '🛕', 'heritage' => '🏛️', 'nature' => '🏞️', 'wildlife' => '🐅',
        'lake' => '🛶', 'hill' => '⛰️', 'adventure' => '🧗', 'city' => '🏙️',
    ];

    /** Where "near the border" is measured from. */
    private const BORDER_STOPS = '/nepalgunj|rupaidiha|banke|bardiya|jamunaha|kohalpur|bahraich/i';

    /* ----------------------------------------------------------------
     *  Dispatch (index.php, before Auth::user()). Serves and exits, or
     *  returns false when the path is not one of ours.
     * ---------------------------------------------------------------- */
    public static function dispatch(string $path): bool
    {
        if ($path === '/sitemap-content.xml') {
            self::sitemap();
        }
        if (preg_match('#^/(?:(hi|ne)/)?(india-nepal|offices|explore|tours)(?:/([^/]+))?$#', $path, $m) !== 1) {
            return false;
        }
        $lang = ($m[1] ?? '') !== '' ? $m[1] : 'en';
        PublicLayout::$lang = $lang;
        $page = $m[2];
        $slug = $m[3] ?? '';
        if ($slug !== '' && $page !== 'tours') {
            self::notFound($lang);
        }
        match ($page) {
            'india-nepal' => self::indiaNepal($lang),
            'offices'     => self::offices($lang),
            'explore'     => self::explore($lang),
            default       => $slug === '' ? self::tours($lang) : self::tour($slug, $lang),
        };
        return true;
    }

    /* ----------------------------------------------------------------
     *  Data
     * ---------------------------------------------------------------- */

    /** @return array<string,mixed> includes/content/indianepal.php, shape-checked */
    public static function indiaNepalData(): array
    {
        $d = [];
        $file = INCLUDE_PATH . '/content/indianepal.php';
        if (is_file($file)) {
            try {
                $d = include $file;
            } catch (Throwable $e) {
                Logger::warning('ContentPages: indianepal.php unreadable: ' . $e->getMessage());
                $d = [];
            }
        }
        $d = is_array($d) ? $d : [];
        $border = is_array($d['border'] ?? null) ? $d['border'] : [];
        return [
            'reviewed'    => (string) ($d['reviewed'] ?? ''),
            'intro'       => is_array($d['intro'] ?? null) ? $d['intro'] : [],
            'events'      => array_values(array_filter((array) ($d['events'] ?? []), 'is_array')),
            'key_numbers' => array_values(array_filter((array) ($d['key_numbers'] ?? []), 'is_array')),
            'border'      => [
                'summary'  => is_array($border['summary'] ?? null) ? $border['summary'] : [],
                'rules'    => array_values(array_filter((array) ($border['rules'] ?? []), 'is_array')),
                'crossing' => array_values(array_filter((array) ($border['crossing'] ?? []), 'is_array')),
                'tips'     => array_values(array_filter((array) ($border['tips'] ?? []), 'is_array')),
            ],
            'sources'     => array_values(array_filter((array) ($d['sources'] ?? []), 'is_array')),
        ];
    }

    /** @return list<array<string,mixed>> active places, featured first */
    public static function places(): array
    {
        try {
            return Database::fetchAll('SELECT * FROM travel_places WHERE is_active = 1 ORDER BY is_featured DESC, sort_order, name_en');
        } catch (Throwable $e) {
            return [];   // table not created yet: the page shows "coming soon"
        }
    }

    /** @return list<array<string,mixed>> published packages */
    public static function packages(): array
    {
        try {
            return Database::fetchAll("SELECT * FROM tour_packages WHERE status = 'published' ORDER BY is_featured DESC, sort_order, title_en");
        } catch (Throwable $e) {
            return [];
        }
    }

    /** @return array<string,mixed>|null any status — callers decide who may see a draft */
    public static function package(string $slug): ?array
    {
        if (preg_match('/^[a-z0-9-]{2,80}$/', $slug) !== 1) {
            return null;
        }
        try {
            return Database::fetch('SELECT * FROM tour_packages WHERE slug = :s LIMIT 1', ['s' => $slug]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** "temple", "National park", "Lakes" … → one of CATS (or the cleaned word). */
    public static function normCategory(string $c): string
    {
        $c = strtolower(trim($c));
        if ($c === '') {
            return 'heritage';
        }
        if (isset(self::CATS[$c])) {
            return $c;
        }
        // "Temple / protected monument" is a temple: the first part decides, the whole string is the fallback.
        foreach (array_unique([trim(explode('/', $c)[0]), $c]) as $s) {
            $hit = match (true) {
                (bool) preg_match('/pilgrim|relig|temple|shrine|monaster|spiritual|mandir|buddh|holy|jyotirling|dham|peeth|peetha/', $s) => 'pilgrimage',
                (bool) preg_match('/wild|national park|safari|jungle|tiger|reserve|sanctuary/', $s) => 'wildlife',
                (bool) preg_match('/lake|river|tal\b/', $s) => 'lake',
                (bool) preg_match('/hill|view ?point/', $s) => 'hill',
                (bool) preg_match('/trek|adventure|mountain|himal|raft|paragl/', $s) => 'adventure',
                (bool) preg_match('/herit|histor|fort|palace|culture|museum|monument|unesco|ancient|archaeolog|stepwell|durbar/', $s) => 'heritage',
                (bool) preg_match('/city|town|urban|market/', $s) => 'city',
                (bool) preg_match('/nature|park|garden|waterfall|valley|scenic|desert|salt|beach/', $s) => 'nature',
                default => '',
            };
            if ($hit !== '') {
                return $hit;
            }
        }
        return substr((string) preg_replace('/[^a-z]+/', '-', $c), 0, 20);
    }

    /** Straight-line km from the Rupaidiha ⇄ Nepalgunj crossing — only to group "near the border", never shown as road km. */
    public static function borderKm(?float $lat, ?float $lng): ?float
    {
        if ($lat === null || $lng === null) {
            return null;
        }
        [$bLat, $bLng] = [28.03, 81.61];
        $r = M_PI / 180;
        $a = sin(($lat - $bLat) * $r / 2) ** 2 + cos($bLat * $r) * cos($lat * $r) * sin(($lng - $bLng) * $r / 2) ** 2;
        return 6371 * 2 * asin(min(1.0, sqrt($a)));
    }

    /** @return list<string> */
    public static function jsonList(?string $raw): array
    {
        $a = json_decode((string) $raw, true);
        return is_array($a) ? array_values(array_filter(array_map(static fn($v): string => is_scalar($v) ? trim((string) $v) : '', $a), static fn(string $s): bool => $s !== '')) : [];
    }

    /** A place / package name: Hindi falls back to the Nepali spelling (same script), then English. */
    private static function nameOf(array $row, string $base, string $lang): string
    {
        $order = $lang === 'hi' ? ['hi', 'ne', 'en'] : ($lang === 'ne' ? ['ne', 'en'] : ['en']);
        foreach ($order as $l) {
            $v = trim((string) ($row[$base . '_' . $l] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return trim((string) ($row[$base . '_en'] ?? ''));
    }

    /** Prose: the language, else English (Hindi columns may be empty). */
    private static function textOf(array $row, string $base, string $lang): string
    {
        foreach ([$lang, 'en', 'ne'] as $l) {
            $v = trim((string) ($row[$base . '_' . $l] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return '';
    }

    /* ----------------------------------------------------------------
     *  Small JSON views (api/explore.php — the home page teasers)
     * ---------------------------------------------------------------- */

    /** @return array{places: list<array<string,mixed>>, packages: list<array<string,mixed>>} */
    public static function featured(string $lang = 'en', int $maxPlaces = 8, int $maxPackages = 6): array
    {
        $lang = in_array($lang, PublicLayout::LANGS, true) ? $lang : 'en';
        $places = [];
        foreach (self::places() as $p) {
            if (count($places) >= $maxPlaces) {
                break;
            }
            $places[] = [
                'slug' => (string) $p['slug'], 'name' => (string) $p['name_en'], 'nameNe' => (string) ($p['name_ne'] ?? ''),
                'nameHi' => (string) ($p['name_hi'] ?? ''), 'country' => (string) $p['country'], 'category' => (string) $p['category'],
                'blurb' => (string) ($p['blurb_en'] ?? ''), 'blurbNe' => (string) ($p['blurb_ne'] ?? ''), 'unesco' => (bool) $p['unesco'],
                'featured' => (bool) $p['is_featured'], 'image' => self::imgUrl((string) ($p['image'] ?? '')),
                'url' => PublicLayout::path('/explore', $lang) . '#place-' . $p['slug'],
            ];
        }
        $packages = [];
        foreach (array_slice(self::packages(), 0, $maxPackages) as $k) {
            $packages[] = [
                'slug' => (string) $k['slug'], 'title' => (string) $k['title_en'], 'titleNe' => (string) ($k['title_ne'] ?? ''),
                'titleHi' => (string) ($k['title_hi'] ?? ''), 'days' => (int) $k['days'], 'nights' => (int) $k['nights'],
                'country' => (string) $k['country'], 'priceFrom' => $k['price_from'] !== null ? (float) $k['price_from'] : null,
                'image' => self::imgUrl((string) ($k['image'] ?? '')), 'url' => PublicLayout::path('/tours/' . $k['slug'], $lang),
            ];
        }
        return ['places' => $places, 'packages' => $packages];
    }

    /** An English year label for a Nepali/Hindi page: "Ancient" and "BCE" in words; digits stay as written. */
    private static function yearLocal(string $year, string $lang): string
    {
        if ($lang === 'en' || $year === '') {
            return $year;
        }
        return strtr($year, ['Ancient' => 'प्राचीन काल', 'BCE' => 'ईसापूर्व', 'CE' => 'ईस्वी']);
    }

    /** @return list<array{year:string,title:string,titleNe:string,titleHi:string,icon:string}> */
    public static function timeline(int $limit = 6): array
    {
        $out = [];
        foreach (self::indiaNepalData()['events'] as $ev) {
            $out[] = [
                'year' => (string) ($ev['year'] ?? ''), 'yearNe' => (string) (($ev['year_ne'] ?? '') !== '' ? $ev['year_ne'] : self::yearLocal((string) ($ev['year'] ?? ''), 'ne')),
                'title' => (string) ($ev['title_en'] ?? ''), 'titleNe' => (string) ($ev['title_ne'] ?? ''),
                'titleHi' => (string) ($ev['title_hi'] ?? ''), 'icon' => (string) ($ev['icon'] ?? ''),
            ];
        }
        return array_slice($out, 0, max(1, min(30, $limit)));
    }

    /**
     * Admin upload → a re-encoded image under uploads/<dir>/ (GD, at most
     * 1400 px wide, WebP when GD has it, else JPEG). Nothing the visitor sent
     * is stored as-is: a re-encode drops EXIF/GPS and any payload.
     * Returns the path relative to the web root.
     */
    public static function saveUpload(array $f, string $dir, string $slug): string
    {
        if (!in_array($dir, ['tours', 'places'], true)) {
            throw new RuntimeException('Bad upload folder.');
        }
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
            throw new RuntimeException('Choose an image first.');
        }
        $mime = mime_content_type((string) $f['tmp_name']) ?: '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || (int) ($f['size'] ?? 0) > 8 * 1024 * 1024) {
            throw new RuntimeException('PNG, JPG or WebP, up to 8 MB.');
        }
        $src = @imagecreatefromstring((string) file_get_contents((string) $f['tmp_name']));
        if ($src === false) {
            throw new RuntimeException('That image could not be read.');
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, 1400 / max(1, $w));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $abs = rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : ROOT_PATH . '/uploads', '/\\') . '/' . $dir;
        if (!is_dir($abs) && !mkdir($abs, 0755, true) && !is_dir($abs)) {
            throw new RuntimeException('Could not create the upload folder.');
        }
        $webp = function_exists('imagewebp');
        $name = substr((string) preg_replace('/[^a-z0-9-]/', '', strtolower($slug)), 0, 50) . '-' . bin2hex(random_bytes(4)) . ($webp ? '.webp' : '.jpg');
        $ok = $webp ? imagewebp($dst, $abs . '/' . $name . '.tmp', 82) : imagejpeg($dst, $abs . '/' . $name . '.tmp', 84);
        imagedestroy($src);
        imagedestroy($dst);
        if (!$ok || !rename($abs . '/' . $name . '.tmp', $abs . '/' . $name)) {
            @unlink($abs . '/' . $name . '.tmp');
            throw new RuntimeException('Could not save the image.');
        }
        return 'uploads/' . $dir . '/' . $name;
    }

    /** The shared look of admin/tours.php and admin/places.php (phone-first forms). */
    public static function adminCss(): string
    {
        return '<style>
.ct-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 14px}
.ct-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,400px),1fr));gap:16px;align-items:start}
.ct-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px 18px}
.ct-card h3{margin:0 0 10px;font-size:15.5px}
.ct-wide{grid-column:1/-1}
.ct-f{display:flex;flex-direction:column;gap:4px;margin-bottom:10px;font-size:12.5px;font-weight:700;color:var(--mut);flex:1;min-width:0}
.ct-f small{font-weight:400}
.ct-f input:not([type=checkbox]),.ct-f textarea,.ct-f select{padding:9px 11px;border:1px solid var(--line);border-radius:10px;font-size:15px;font-weight:400;background:var(--card);color:var(--ink);min-height:44px;width:100%;box-sizing:border-box;font-family:inherit}
.ct-f textarea{min-height:76px;resize:vertical}
.ct-row{display:flex;gap:12px;flex-wrap:wrap}.ct-row>.ct-f{min-width:min(100%,180px)}
.ct-check{flex-direction:row;align-items:center;gap:8px;min-height:44px;margin-top:18px}
.ct-check input{width:20px;height:20px}
.ct-img{width:100%;max-height:220px;object-fit:cover;border-radius:12px;border:1px solid var(--line);margin-bottom:10px}
.ct-save{position:sticky;bottom:0;background:var(--card);border-top:1px solid var(--line);padding:12px 0;margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;z-index:2}
.ct-list{display:grid;gap:10px}
.ct-item{display:flex;gap:12px;align-items:center;flex-wrap:wrap;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px}
.ct-thumb{width:72px;height:54px;border-radius:10px;background:var(--line);display:grid;place-items:center;font-size:26px;overflow:hidden;flex:none}
.ct-thumb img{width:100%;height:100%;object-fit:cover}
.ct-body{flex:1;min-width:200px}
.ct-meta{display:flex;gap:10px;flex-wrap:wrap;font-size:12.5px;color:var(--mut);margin-top:4px;align-items:center}
.ct-actions{display:flex;gap:6px;flex-wrap:wrap}.ct-actions form{margin:0}
.pill.st-published{background:#d7f4e3;color:#0a6b3b}.pill.st-draft{background:#fff4d1;color:#8a6d00}.pill.st-archived{background:#e7e7e7;color:#555}
.pill.st-on{background:#d7f4e3;color:#0a6b3b}.pill.st-off{background:#e7e7e7;color:#555}
</style>';
    }

    public static function imgUrl(string $rel): string
    {
        $rel = ltrim(trim($rel), '/');
        if ($rel === '' || str_contains($rel, '..') || preg_match('#^(uploads|assets)/[A-Za-z0-9/_.-]+\.(webp|jpe?g|png)$#', $rel) !== 1) {
            return '';
        }
        return '/' . $rel;
    }

    /* ----------------------------------------------------------------
     *  Words (three languages, one place)
     * ---------------------------------------------------------------- */
    /** @return array<string,string> */
    public static function words(string $lang): array
    {
        $w = [
            'en' => [
                'inTitle' => 'India–Nepal: friendship, the open border and our route', 'inH1' => 'India ⇄ Nepal', 'inKick' => 'Neighbours · friends · one open border',
                'inDesc' => 'The India–Nepal story in a timeline: history, the 1950 Treaty of Peace and Friendship, the open border at Rupaidiha–Nepalgunj and what travellers should know.',
                'timeline' => 'Timeline', 'keyNums' => 'Key numbers', 'border' => 'Open border — what it means', 'rules' => 'The basics',
                'crossing' => 'Crossing at Rupaidiha ⇄ Nepalgunj', 'tips' => 'Good to know', 'carry' => 'What to carry',
                'carryP' => 'Identity documents, money and customs for crossing at Rupaidiha are kept in one up-to-date guide — so they are never out of step.',
                'carryBtn' => 'Open the border travel guide', 'reviewed' => 'Last reviewed: %s', 'sources' => 'Sources',
                'disclaimer' => 'Rules and facts can change. Always check official government sources before you travel.',
                'soonStory' => 'More of this story is being written and checked — coming soon.',
                'soonBorder' => 'A short, sourced summary of the open border is being prepared. Until then, the border travel guide has everything you need for the crossing.',
                'ctaH' => 'Travel the friendship route', 'ctaP' => 'AC sleeper buses between Gujarat and the Rupaidiha border, with offices on both sides.',
                'offTitle' => 'Our offices — %s', 'offH1' => 'Our offices', 'offKick' => 'Gujarat ⇄ Nepal border',
                'offLead' => 'Visit, call or WhatsApp us. Every number here is the office’s own, kept up to date by our team.',
                'offDesc' => 'S Hari Global offices: %s. Call, WhatsApp or visit — head office in %s.',
                'head' => 'Head office', 'branch' => 'Branch office', 'partner' => 'Partner office', 'borderOff' => 'Nepal office',
                'call' => 'Call', 'wa' => 'WhatsApp', 'map' => 'Map', 'hours' => 'Hours', 'soonOpen' => 'Opening soon', 'soonNum' => 'Number coming soon', 'offSvc' => 'Tickets · enquiries · help',
                'inIndia' => '%d in India', 'inNepal' => '%d in Nepal',
                'expH' => 'We are expanding', 'expP' => 'Want a %s counter in your town? Become a partner or an agent — the network is growing.',
                'expPartner' => 'Partner agreement', 'expAgent' => 'Become an agent', 'noOffices' => 'Office details are being updated — please call the head office.',
                'exTitle' => 'Places to visit in Nepal & India', 'exH1' => 'Places to visit', 'exKick' => 'Travel guide',
                'exLead' => 'From the Himalaya to the plains — travel ideas along our India–Nepal route, picked for bus travellers.',
                'exDesc' => 'Famous places to visit in Nepal and India near our bus route: pilgrimages, nature, wildlife and heritage, with the best season and how to reach.',
                'nepal' => 'Nepal', 'india' => 'India', 'nearBorder' => 'Near the border (Nepalgunj / Rupaidiha)', 'all' => 'All', 'picks' => 'Our picks',
                'bestSeason' => 'Best season', 'reach' => 'How to reach', 'unesco' => 'UNESCO World Heritage', 'kmFrom' => '≈ %1$d km from %2$s',
                'onMap' => 'Open in Maps', 'photo' => 'Photo: %s', 'soonPlaces' => 'Our travel guide is being prepared — coming soon.',
                'noMatch' => 'No places in this category yet.', 'seeTours' => 'See tour packages',
                'tTitle' => 'Tour packages — Nepal & India', 'tH1' => 'Tour packages', 'tKick' => 'Bus + tours',
                'tLead' => 'Our bus to the border, trusted partners beyond it. Tell us your dates — we send a quote. No payment online.',
                'tDesc' => 'Nepal and India tour packages with the S Hari Global bus to the Rupaidiha border and partner-operated travel beyond. Enquire for a quote.',
                'days' => '%d days', 'daysNights' => '%1$d days · %2$d nights', 'onRequest' => 'Price on request', 'from' => 'From ₹%s',
                'coach' => 'Our bus runs up to the Rupaidiha border — onward travel is by our partner.', 'highlights' => 'Highlights',
                'itinerary' => 'Day by day', 'day' => 'Day %d', 'route' => 'Route', 'view' => 'View & enquire', 'partnerBy' => 'Operated with %s',
                'minPax' => 'Min. %d people', 'more' => 'More packages', 'waAsk' => 'Ask on WhatsApp', 'soonTours' => 'Tour packages are being prepared with our partners — coming soon. Tell us where you want to go and we will plan it.',
                'custom' => 'Plan a custom trip', 'country' => ['Nepal' => 'Nepal', 'India' => 'India', 'Both' => 'Nepal + India'],
                'apply' => 'Apply / Enquire', 'fName' => 'Your name', 'fPhone' => 'Mobile (WhatsApp)', 'fAdults' => 'Adults', 'fKids' => 'Children',
                'fMonth' => 'Travel month', 'fDate' => 'or exact date', 'fFrom' => 'Travelling from (city)', 'fMsg' => 'Message (optional)',
                'fSend' => 'Send enquiry', 'fSending' => 'Sending…', 'fNote' => 'We call you back with a quote. Nothing is booked or charged until you agree.',
                'fErr' => 'Could not send. Please try again, or call / WhatsApp us.', 'fNoJs' => 'Turn on JavaScript to send this form — or call / WhatsApp us.',
                'nearStop' => 'Closest point on our bus network: %s', 'journey' => 'Your journey', 'jBus' => 'S Hari Global AC sleeper bus',
                'jBusP' => 'From your pickup in Gujarat to the Rupaidiha border', 'jTour' => 'Tour starts — run by our partner', 'jHome' => 'Back home on our bus',
                'jHomeP' => 'Rupaidiha border → your stop in Gujarat', 'partnerOnly' => 'This tour is run by our travel partner — our bus is not part of it.',
                'partnerOnlyS' => 'Tour starts — run by our partner',
                'draft' => 'Preview — this package is not published.', 'nf' => 'Page not found', 'nfP' => 'That page does not exist (or is not published yet).',
            ],
            'hi' => [
                'inTitle' => 'भारत–नेपाल: मित्रता, खुली सीमा और हमारा रूट', 'inH1' => 'भारत ⇄ नेपाल', 'inKick' => 'पड़ोसी · मित्र · एक खुली सीमा',
                'inDesc' => 'भारत–नेपाल की कहानी समयरेखा में: इतिहास, 1950 की शांति और मैत्री संधि, रुपईडीहा–नेपालगंज की खुली सीमा और यात्रियों के लिए ज़रूरी बातें।',
                'timeline' => 'समयरेखा', 'keyNums' => 'मुख्य आँकड़े', 'border' => 'खुली सीमा — इसका मतलब', 'rules' => 'बुनियादी बातें',
                'crossing' => 'रुपईडीहा ⇄ नेपालगंज पर सीमा पार', 'tips' => 'जानने लायक', 'carry' => 'साथ क्या रखें',
                'carryP' => 'रुपईडीहा पर सीमा पार करने के पहचान पत्र, पैसे और कस्टम के नियम एक ही अपडेटेड गाइड में हैं — ताकि जानकारी कभी पुरानी न हो।',
                'carryBtn' => 'बॉर्डर यात्रा गाइड खोलें', 'reviewed' => 'अंतिम समीक्षा: %s', 'sources' => 'स्रोत',
                'disclaimer' => 'नियम और तथ्य बदल सकते हैं। यात्रा से पहले हमेशा आधिकारिक सरकारी स्रोत देखें।',
                'soonStory' => 'इस कहानी का और हिस्सा लिखा और जाँचा जा रहा है — जल्द आ रहा है।',
                'soonBorder' => 'खुली सीमा का छोटा, स्रोत-सहित सार तैयार हो रहा है। तब तक सीमा पार करने की पूरी जानकारी बॉर्डर यात्रा गाइड में है।',
                'ctaH' => 'मित्रता के रूट पर यात्रा करें', 'ctaP' => 'गुजरात और रुपईडीहा सीमा के बीच AC स्लीपर बसें, दोनों तरफ़ हमारे कार्यालय।',
                'offTitle' => 'हमारे कार्यालय — %s', 'offH1' => 'हमारे कार्यालय', 'offKick' => 'गुजरात ⇄ नेपाल सीमा',
                'offLead' => 'मिलने आएँ, कॉल करें या WhatsApp करें। यहाँ हर नंबर कार्यालय का अपना है, हमारी टीम इसे अपडेट रखती है।',
                'offDesc' => 'S Hari Global के कार्यालय: %s। कॉल, WhatsApp या मिलने आएँ — मुख्य कार्यालय %s में।',
                'head' => 'मुख्य कार्यालय', 'branch' => 'शाखा कार्यालय', 'partner' => 'पार्टनर कार्यालय', 'borderOff' => 'नेपाल कार्यालय',
                'call' => 'कॉल', 'wa' => 'WhatsApp', 'map' => 'नक्शा', 'hours' => 'समय', 'soonOpen' => 'जल्द खुलेगा', 'soonNum' => 'नंबर जल्द', 'offSvc' => 'टिकट · पूछताछ · सहायता',
                'inIndia' => 'भारत में %d', 'inNepal' => 'नेपाल में %d',
                'expH' => 'हम बढ़ रहे हैं', 'expP' => 'अपने शहर में %s काउंटर चाहिए? पार्टनर या एजेंट बनें — नेटवर्क बढ़ रहा है।',
                'expPartner' => 'पार्टनर एग्रीमेंट', 'expAgent' => 'एजेंट बनें', 'noOffices' => 'कार्यालय की जानकारी अपडेट हो रही है — कृपया मुख्य कार्यालय को कॉल करें।',
                'exTitle' => 'नेपाल और भारत में घूमने की जगहें', 'exH1' => 'घूमने की जगहें', 'exKick' => 'यात्रा गाइड',
                'exLead' => 'हिमालय से मैदान तक — हमारे भारत–नेपाल रूट के साथ घूमने के सुझाव, बस यात्रियों के लिए चुने हुए।',
                'exDesc' => 'हमारे बस रूट के पास नेपाल और भारत की मशहूर जगहें: तीर्थ, प्रकृति, वन्यजीव और विरासत — सबसे अच्छा मौसम और पहुँचने का तरीका।',
                'nepal' => 'नेपाल', 'india' => 'भारत', 'nearBorder' => 'सीमा के पास (नेपालगंज / रुपईडीहा)', 'all' => 'सभी', 'picks' => 'हमारी पसंद',
                'bestSeason' => 'सबसे अच्छा मौसम', 'reach' => 'कैसे पहुँचें', 'unesco' => 'यूनेस्को विश्व धरोहर', 'kmFrom' => '%2$s से ≈ %1$d किमी',
                'onMap' => 'नक्शे में खोलें', 'photo' => 'फोटो: %s', 'soonPlaces' => 'हमारी यात्रा गाइड तैयार हो रही है — जल्द आ रही है।',
                'noMatch' => 'इस श्रेणी में अभी कोई जगह नहीं।', 'seeTours' => 'टूर पैकेज देखें',
                'tTitle' => 'टूर पैकेज — नेपाल और भारत', 'tH1' => 'टूर पैकेज', 'tKick' => 'बस + टूर',
                'tLead' => 'बॉर्डर तक हमारी बस, आगे भरोसेमंद पार्टनर। अपनी तारीख बताइए — हम कोटेशन भेजेंगे। ऑनलाइन कोई भुगतान नहीं।',
                'tDesc' => 'नेपाल और भारत के टूर पैकेज — रुपईडीहा बॉर्डर तक S Hari Global की बस और आगे पार्टनर द्वारा यात्रा। कोटेशन के लिए पूछें।',
                'days' => '%d दिन', 'daysNights' => '%1$d दिन · %2$d रात', 'onRequest' => 'कीमत पूछने पर', 'from' => '₹%s से',
                'coach' => 'हमारी बस रुपईडीहा बॉर्डर तक जाती है — आगे की यात्रा हमारे पार्टनर द्वारा।', 'highlights' => 'मुख्य आकर्षण',
                'itinerary' => 'दिन-प्रतिदिन', 'day' => 'दिन %d', 'route' => 'रूट', 'view' => 'देखें और पूछें', 'partnerBy' => '%s के साथ',
                'minPax' => 'कम से कम %d लोग', 'more' => 'और पैकेज', 'waAsk' => 'WhatsApp पर पूछें', 'soonTours' => 'टूर पैकेज हमारे पार्टनर के साथ तैयार हो रहे हैं — जल्द आ रहे हैं। बताइए आप कहाँ जाना चाहते हैं, हम योजना बनाएँगे।',
                'custom' => 'अपनी यात्रा प्लान करें', 'country' => ['Nepal' => 'नेपाल', 'India' => 'भारत', 'Both' => 'नेपाल + भारत'],
                'apply' => 'आवेदन / पूछताछ', 'fName' => 'आपका नाम', 'fPhone' => 'मोबाइल (WhatsApp)', 'fAdults' => 'वयस्क', 'fKids' => 'बच्चे',
                'fMonth' => 'यात्रा का महीना', 'fDate' => 'या सही तारीख', 'fFrom' => 'किस शहर से', 'fMsg' => 'संदेश (वैकल्पिक)',
                'fSend' => 'पूछताछ भेजें', 'fSending' => 'भेज रहे हैं…', 'fNote' => 'हम कोटेशन के साथ कॉल करेंगे। आपकी सहमति के बिना कुछ बुक या चार्ज नहीं होगा।',
                'fErr' => 'भेजा नहीं जा सका। फिर कोशिश करें, या कॉल / WhatsApp करें।', 'fNoJs' => 'फ़ॉर्म भेजने के लिए JavaScript चालू करें — या कॉल / WhatsApp करें।',
                'nearStop' => 'हमारे बस नेटवर्क का सबसे नज़दीकी पॉइंट: %s', 'journey' => 'आपकी यात्रा', 'jBus' => 'S Hari Global AC स्लीपर बस',
                'jBusP' => 'गुजरात में आपके पिकअप से रुपईडीहा बॉर्डर तक', 'jTour' => 'टूर शुरू — हमारे पार्टनर द्वारा', 'jHome' => 'हमारी बस से घर वापसी',
                'jHomeP' => 'रुपईडीहा बॉर्डर → गुजरात में आपका स्टॉप', 'partnerOnly' => 'यह टूर हमारे ट्रैवल पार्टनर चलाते हैं — इसमें हमारी बस शामिल नहीं है।',
                'partnerOnlyS' => 'टूर शुरू — हमारे पार्टनर द्वारा',
                'draft' => 'प्रीव्यू — यह पैकेज प्रकाशित नहीं है।', 'nf' => 'पेज नहीं मिला', 'nfP' => 'यह पेज मौजूद नहीं है (या अभी प्रकाशित नहीं हुआ)।',
            ],
            'ne' => [
                'inTitle' => 'भारत–नेपाल: मित्रता, खुला सिमाना र हाम्रो रुट', 'inH1' => 'भारत ⇄ नेपाल', 'inKick' => 'छिमेकी · मित्र · एउटै खुला सिमाना',
                'inDesc' => 'भारत–नेपालको कथा समयरेखामा: इतिहास, १९५० को शान्ति तथा मैत्री सन्धि, रुपैडिहा–नेपालगञ्जको खुला सिमाना र यात्रुले जान्नुपर्ने कुरा।',
                'timeline' => 'समयरेखा', 'keyNums' => 'मुख्य तथ्याङ्क', 'border' => 'खुला सिमाना — यसको अर्थ', 'rules' => 'आधारभूत कुरा',
                'crossing' => 'रुपैडिहा ⇄ नेपालगञ्ज नाका', 'tips' => 'जान्नैपर्ने कुरा', 'carry' => 'साथमा के राख्ने',
                'carryP' => 'रुपैडिहामा सिमाना पार गर्दा चाहिने परिचयपत्र, पैसा र भन्सारका नियम एउटै अद्यावधिक गाइडमा छन् — जानकारी कहिल्यै पुरानो नहोस् भनेर।',
                'carryBtn' => 'बोर्डर यात्रा गाइड खोल्नुहोस्', 'reviewed' => 'अन्तिम समीक्षा: %s', 'sources' => 'स्रोत',
                'disclaimer' => 'नियम र तथ्य परिवर्तन हुन सक्छन्। यात्रा अघि सधैँ आधिकारिक सरकारी स्रोत हेर्नुहोस्।',
                'soonStory' => 'यो कथाको थप भाग लेखिँदै र जाँचिँदैछ — छिट्टै आउँदैछ।',
                'soonBorder' => 'खुला सिमानाको छोटो, स्रोतसहितको सार तयार हुँदैछ। त्यतिन्जेल नाका पार गर्न चाहिने सबै कुरा बोर्डर यात्रा गाइडमा छ।',
                'ctaH' => 'मित्रताको रुटमा यात्रा गर्नुहोस्', 'ctaP' => 'गुजरात र रुपैडिहा सिमानाबीच AC स्लिपर बस, दुवैतिर हाम्रा कार्यालय।',
                'offTitle' => 'हाम्रा कार्यालय — %s', 'offH1' => 'हाम्रा कार्यालयहरू', 'offKick' => 'गुजरात ⇄ नेपाल सिमाना',
                'offLead' => 'भेट्न आउनुहोस्, फोन वा WhatsApp गर्नुहोस्। यहाँका हरेक नम्बर कार्यालयकै हुन्, हाम्रो टोलीले अद्यावधिक राख्छ।',
                'offDesc' => 'S Hari Global का कार्यालय: %s। फोन, WhatsApp वा भेट्न आउनुहोस् — प्रधान कार्यालय %s मा।',
                'head' => 'प्रधान कार्यालय', 'branch' => 'शाखा कार्यालय', 'partner' => 'साझेदार कार्यालय', 'borderOff' => 'नेपाल कार्यालय',
                'call' => 'फोन', 'wa' => 'WhatsApp', 'map' => 'नक्सा', 'hours' => 'समय', 'soonOpen' => 'छिट्टै खुल्दैछ', 'soonNum' => 'नम्बर छिट्टै', 'offSvc' => 'टिकट · सोधपुछ · सहायता',
                'inIndia' => 'भारतमा %d', 'inNepal' => 'नेपालमा %d',
                'expH' => 'नयाँ कार्यालय छिट्टै', 'expP' => 'तपाईंको सहरमा %s काउन्टर चाहनुहुन्छ? साझेदार वा एजेन्ट बन्नुहोस् — सञ्जाल बढ्दैछ।',
                'expPartner' => 'साझेदार सम्झौता', 'expAgent' => 'एजेन्ट बन्नुहोस्', 'noOffices' => 'कार्यालय विवरण अद्यावधिक हुँदैछ — कृपया प्रधान कार्यालयमा फोन गर्नुहोस्।',
                'exTitle' => 'नेपाल र भारतमा घुम्ने ठाउँहरू', 'exH1' => 'घुम्ने ठाउँहरू', 'exKick' => 'यात्रा गाइड',
                'exLead' => 'हिमालदेखि तराईसम्म — हाम्रो भारत–नेपाल रुटसँगै घुम्ने सुझाव, बस यात्रुका लागि छानिएका।',
                'exDesc' => 'हाम्रो बस रुट नजिक नेपाल र भारतका प्रसिद्ध ठाउँ: तीर्थ, प्रकृति, वन्यजन्तु र सम्पदा — उत्तम मौसम र पुग्ने तरिका।',
                'nepal' => 'नेपाल', 'india' => 'भारत', 'nearBorder' => 'सिमाना नजिक (नेपालगञ्ज / रुपैडिहा)', 'all' => 'सबै', 'picks' => 'हाम्रो सिफारिस',
                'bestSeason' => 'उत्तम मौसम', 'reach' => 'कसरी पुग्ने', 'unesco' => 'युनेस्को विश्व सम्पदा', 'kmFrom' => '%2$s बाट ≈ %1$d किमी',
                'onMap' => 'नक्सामा खोल्नुहोस्', 'photo' => 'फोटो: %s', 'soonPlaces' => 'हाम्रो यात्रा गाइड तयार हुँदैछ — छिट्टै आउँदैछ।',
                'noMatch' => 'यो वर्गमा अहिले कुनै ठाउँ छैन।', 'seeTours' => 'टुर प्याकेज हेर्नुहोस्',
                'tTitle' => 'टुर प्याकेज — नेपाल र भारत', 'tH1' => 'टुर प्याकेजहरू', 'tKick' => 'बस + टुर',
                'tLead' => 'सिमानासम्म हाम्रो बस, त्यसपछि भरपर्दा साझेदार। आफ्नो मिति भन्नुहोस् — हामी कोटेसन पठाउँछौं। अनलाइन भुक्तानी छैन।',
                'tDesc' => 'नेपाल र भारतका टुर प्याकेज — रुपैडिहा बोर्डरसम्म S Hari Global को बस र त्यसपछि साझेदारमार्फत यात्रा। कोटेसनका लागि सोध्नुहोस्।',
                'days' => '%d दिन', 'daysNights' => '%1$d दिन · %2$d रात', 'onRequest' => 'मूल्य सोधेर', 'from' => '₹%s देखि',
                'coach' => 'हाम्रो बस रुपैडिहा बोर्डरसम्म जान्छ — त्यसपछिको यात्रा साझेदारमार्फत।', 'highlights' => 'मुख्य आकर्षण',
                'itinerary' => 'दिनदिनै', 'day' => 'दिन %d', 'route' => 'रुट', 'view' => 'हेर्नुहोस् र सोध्नुहोस्', 'partnerBy' => '%s सँग',
                'minPax' => 'कम्तीमा %d जना', 'more' => 'थप प्याकेज', 'waAsk' => 'WhatsApp मा सोध्नुहोस्', 'soonTours' => 'टुर प्याकेज साझेदारसँग तयार हुँदैछन् — छिट्टै आउँदैछन्। कहाँ जान चाहनुहुन्छ भन्नुहोस्, हामी योजना बनाउँछौं।',
                'custom' => 'आफ्नै यात्रा योजना', 'country' => ['Nepal' => 'नेपाल', 'India' => 'भारत', 'Both' => 'नेपाल + भारत'],
                'apply' => 'आवेदन / सोधपुछ', 'fName' => 'तपाईंको नाम', 'fPhone' => 'मोबाइल (WhatsApp)', 'fAdults' => 'वयस्क', 'fKids' => 'बालबालिका',
                'fMonth' => 'यात्रा महिना', 'fDate' => 'वा ठ्याक्कै मिति', 'fFrom' => 'कुन सहरबाट', 'fMsg' => 'सन्देश (ऐच्छिक)',
                'fSend' => 'सोधपुछ पठाउनुहोस्', 'fSending' => 'पठाउँदै…', 'fNote' => 'हामी कोटेसनसहित फोन गर्छौं। तपाईंको सहमतिबिना केही बुक वा शुल्क लाग्दैन।',
                'fErr' => 'पठाउन सकिएन। फेरि प्रयास गर्नुहोस्, वा फोन / WhatsApp गर्नुहोस्।', 'fNoJs' => 'फारम पठाउन JavaScript खोल्नुहोस् — वा फोन / WhatsApp गर्नुहोस्।',
                'nearStop' => 'हाम्रो बस सञ्जालको नजिकको बिन्दु: %s', 'journey' => 'तपाईंको यात्रा', 'jBus' => 'S Hari Global AC स्लिपर बस',
                'jBusP' => 'गुजरातको तपाईंको पिकअपदेखि रुपैडिहा बोर्डरसम्म', 'jTour' => 'टुर सुरु — साझेदारमार्फत', 'jHome' => 'हाम्रो बसमा घर फर्किनुहोस्',
                'jHomeP' => 'रुपैडिहा बोर्डर → गुजरातको तपाईंको स्टप', 'partnerOnly' => 'यो टुर हाम्रो ट्राभल साझेदारले चलाउँछ — यसमा हाम्रो बस पर्दैन।',
                'partnerOnlyS' => 'टुर सुरु — साझेदारमार्फत',
                'draft' => 'पूर्वावलोकन — यो प्याकेज प्रकाशित छैन।', 'nf' => 'पृष्ठ भेटिएन', 'nfP' => 'यो पृष्ठ छैन (वा अझै प्रकाशित भएको छैन)।',
            ],
        ];
        return $w[$lang] ?? $w['en'];
    }

    /** @return array<string,array<string,string>> category labels */
    private static function catLabels(): array
    {
        return [
            'pilgrimage' => ['en' => 'Pilgrimage', 'hi' => 'तीर्थ', 'ne' => 'तीर्थ'],
            'heritage'   => ['en' => 'Heritage', 'hi' => 'विरासत', 'ne' => 'सम्पदा'],
            'nature'     => ['en' => 'Nature', 'hi' => 'प्रकृति', 'ne' => 'प्रकृति'],
            'wildlife'   => ['en' => 'Wildlife', 'hi' => 'वन्यजीव', 'ne' => 'वन्यजन्तु'],
            'lake'       => ['en' => 'Lakes', 'hi' => 'झील', 'ne' => 'ताल'],
            'hill'       => ['en' => 'Hills', 'hi' => 'पहाड़', 'ne' => 'पहाड'],
            'adventure'  => ['en' => 'Adventure', 'hi' => 'रोमांच', 'ne' => 'साहसिक'],
            'city'       => ['en' => 'City', 'hi' => 'शहर', 'ne' => 'सहर'],
        ];
    }

    private static function catLabel(string $cat, string $lang): string
    {
        $l = self::catLabels()[$cat] ?? null;
        return $l !== null ? $l[$lang] : ucfirst(str_replace('-', ' ', $cat));
    }

    /* ----------------------------------------------------------------
     *  Shared bits
     * ---------------------------------------------------------------- */

    private static function e(?string $s): string
    {
        return PublicLayout::e($s);
    }

    /** At most $max characters, cut at a word, with an ellipsis. */
    private static function clip(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        $cut = mb_substr($s, 0, $max);
        $sp = mb_strrpos($cut, ' ');
        return rtrim($sp !== false && $sp > $max * 0.6 ? mb_substr($cut, 0, $sp) : $cut, " ,;:.") . '…';
    }

    private static function ld(array $graph): string
    {
        return (string) json_encode(['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    }

    private static function orgNode(): array
    {
        $co = PublicLayout::company();
        $b = PublicLayout::base();
        return array_filter([
            '@type' => 'Organization', '@id' => $b . '/#org', 'name' => $co['name'], 'legalName' => $co['legal'],
            'url' => $b . '/', 'logo' => $b . '/assets/img/logo-mark.png', 'email' => $co['email'], 'telephone' => $co['phone'],
            'areaServed' => ['India', 'Nepal'],
        ], static fn($v): bool => $v !== '' && $v !== null);
    }

    /** @param list<array{0:string,1:string}> $items [[name, url], …] */
    private static function crumbsLd(array $items): array
    {
        $list = [];
        foreach (array_values($items) as $i => [$n, $u]) {
            $list[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $n, 'item' => $u];
        }
        return ['@type' => 'BreadcrumbList', 'itemListElement' => $list];
    }

    /** @return list<array{0:string,1:string}> */
    private static function crumbs(string $lang, string $name, string $path, array $extra = []): array
    {
        $c = [[PublicLayout::company()['name'], PublicLayout::base() . '/'], [$name, PublicLayout::url($path, $lang)]];
        foreach ($extra as $x) {
            $c[] = $x;
        }
        return $c;
    }

    /** Send a page. Cacheable only when it set no cookie (a first visit starts a session). */
    private static function send(string $html, int $code = 200, bool $cache = true): never
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        if ($cache && $code === 200) {
            $cookie = preg_grep('/^Set-Cookie:/i', headers_list()) !== [];
            header_remove('Pragma');   // session_start()'s nocache limiter - this page holds no per-visitor data
            header_remove('Expires');
            header('Cache-Control: ' . ($cookie ? 'private' : 'public') . ', max-age=600');
        } else {
            header('Cache-Control: no-store');
        }
        echo $html;
        exit;
    }

    public static function notFound(string $lang): never
    {
        $w = self::words($lang);
        $body = '<section class="empty" style="margin-top:20px"><div class="big" aria-hidden="true">🧭</div><h1>' . self::e($w['nf']) . '</h1><p class="lead" style="margin:0 auto">' . self::e($w['nfP']) . '</p>'
            . '<div class="btns" style="justify-content:center"><a class="btn fill" href="/#/">' . self::e(PublicLayout::words($lang)['book']) . '</a><a class="btn tonal" href="' . self::e(PublicLayout::url('/tours', $lang)) . '">' . self::e($w['tH1']) . '</a></div></section>';
        self::send(PublicLayout::page($lang, $w['nf'] . ' | ' . PublicLayout::company()['name'], $w['nfP'], PublicLayout::url('/tours', $lang), '', '', $body, ['noindex' => true, 'path' => '/tours']), 404, false);
    }

    private static function sourcesHtml(array $sources, bool $full = false): string
    {
        $li = '';
        $seen = [];
        foreach ($sources as $s) {
            $key = is_array($s) ? strtolower(rtrim(trim((string) ($s['url'] ?? '')) ?: trim((string) ($s['label'] ?? '')), '/')) : '';
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if (!is_array($s)) {
                continue;
            }
            $label = trim((string) ($s['label'] ?? $s['title'] ?? $s['name'] ?? ''));
            $url = trim((string) ($s['url'] ?? ''));
            if ($url !== '' && preg_match('#^https?://#i', $url) !== 1) {
                $url = '';
            }
            if ($label === '' && $url === '') {
                continue;
            }
            $label = $label !== '' ? $label : (string) parse_url($url, PHP_URL_HOST);
            if ($full && $url !== '') {
                // The full list names the page, not just the site ("kathmandupost.com/…/vivaha-panchami…").
                $short = (string) preg_replace('#^https?://(www\.)?#i', '', rtrim($url, '/'));
                $label = mb_strlen($short) > 78 ? mb_substr($short, 0, 76) . '…' : $short;
            }
            $li .= '<li>' . ($url !== '' ? '<a href="' . self::e($url) . '" rel="noopener nofollow" target="_blank">' . self::e($label) . '</a>' : self::e($label)) . '</li>';
        }
        return $li;
    }

    /** A date 'YYYY-MM-DD' in the visitor's language ("9 October 2026"). */
    private static function niceDate(string $iso, string $lang): string
    {
        $t = strtotime($iso);
        if ($t === false) {
            return $iso;
        }
        if ($lang === 'en') {
            return date('j F Y', $t);
        }
        $months = $lang === 'ne'
            ? ['जनवरी', 'फेब्रुअरी', 'मार्च', 'अप्रिल', 'मे', 'जुन', 'जुलाई', 'अगस्ट', 'सेप्टेम्बर', 'अक्टोबर', 'नोभेम्बर', 'डिसेम्बर']
            : ['जनवरी', 'फ़रवरी', 'मार्च', 'अप्रैल', 'मई', 'जून', 'जुलाई', 'अगस्त', 'सितंबर', 'अक्टूबर', 'नवंबर', 'दिसंबर'];
        return date('j', $t) . ' ' . $months[(int) date('n', $t) - 1] . ' ' . date('Y', $t);
    }

    /* ================================================================
     *  /india-nepal
     * ================================================================ */
    public static function indiaNepal(string $lang): never
    {
        $w = self::words($lang);
        $d = self::indiaNepalData();
        $e = Closure::fromCallable([self::class, 'e']);
        $co = PublicLayout::company();
        $canon = PublicLayout::url('/india-nepal', $lang);
        $title = $w['inTitle'] . ' | ' . $co['name'];
        $intro = PublicLayout::pick($d['intro'], $lang);
        $allSources = [];

        $nums = '';
        foreach ($d['key_numbers'] as $k) {
            $label = PublicLayout::pick(['en' => $k['label_en'] ?? '', 'hi' => $k['label_hi'] ?? '', 'ne' => $k['label_ne'] ?? ''], $lang);
            if (trim((string) ($k['value'] ?? '')) === '' || $label === '') {
                continue;
            }
            $val = $lang === 'ne' && trim((string) ($k['value_ne'] ?? '')) !== '' && trim((string) ($k['label_ne'] ?? '')) !== '' ? (string) $k['value_ne'] : (string) $k['value'];
            $nums .= '<div class="stat rv' . (mb_strlen($val) > 12 ? ' stat-w' : (mb_strlen($val) > 7 ? ' stat-m' : '')) . '"><b>' . $e($val) . '</b><span>' . $e($label) . '</span></div>';
            $allSources = array_merge($allSources, (array) ($k['sources'] ?? []));
        }

        $tl = '';
        foreach ($d['events'] as $ev) {
            $t = PublicLayout::pick(['en' => $ev['title_en'] ?? '', 'hi' => $ev['title_hi'] ?? '', 'ne' => $ev['title_ne'] ?? ''], $lang);
            if ($t === '') {
                continue;
            }
            $body = PublicLayout::pick(['en' => $ev['body_en'] ?? '', 'hi' => $ev['body_hi'] ?? '', 'ne' => $ev['body_ne'] ?? ''], $lang);
            $year = PublicLayout::pick(['en' => $ev['year'] ?? '', 'hi' => $ev['year_hi'] ?? '', 'ne' => $ev['year_ne'] ?? ''], $lang);
            if ($lang !== 'en' && trim((string) ($ev['year_' . $lang] ?? '')) === '') {
                $year = self::yearLocal($year, $lang);
            }
            $src = self::sourcesHtml((array) ($ev['sources'] ?? []));
            $allSources = array_merge($allSources, (array) ($ev['sources'] ?? []));
            $tl .= '<li class="tl-i rv"><span class="tl-dot" aria-hidden="true">' . $e((string) ($ev['icon'] ?? '•') ?: '•') . '</span><article class="tl-card">'
                . ($year !== '' ? '<span class="tl-year">' . $e($year) . '</span>' : '')
                . '<h3>' . $e($t) . '</h3>' . ($body !== '' ? '<p>' . $e($body) . '</p>' : '')
                . ($src !== '' ? '<ul class="tl-src">' . $src . '</ul>' : '') . '</article></li>';
        }
        $tlHtml = $tl !== ''
            ? '<ol class="tl" aria-label="' . $e($w['timeline']) . '">' . $tl . '</ol>'
            : '';
        if (count($d['events']) < 4) {
            $tlHtml .= '<p class="note soon">⏳ ' . $e($w['soonStory']) . '</p>';
        }

        $itemList = static function (array $items) use ($lang, $e, &$allSources): string {
            $li = '';
            foreach ($items as $it) {
                $t = PublicLayout::pick($it, $lang);
                if ($t === '') {
                    continue;
                }
                $allSources = array_merge($allSources, (array) ($it['sources'] ?? []));
                $li .= '<li>' . $e($t) . '</li>';
            }
            return $li;
        };
        // Long, sourced rules: one tap-to-open row each — the first sentence is the row, the rest opens.
        $accordion = static function (array $items) use ($lang, $e, &$allSources): string {
            $out = '';
            foreach ($items as $it) {
                $t = PublicLayout::pick($it, $lang);
                if ($t === '') {
                    continue;
                }
                $allSources = array_merge($allSources, (array) ($it['sources'] ?? []));
                $head = $t;
                $rest = '';
                if (preg_match('/^(.{12,260}?[.!?।])\s+(.+)$/us', $t, $m) === 1) {
                    [$head, $rest] = [$m[1], $m[2]];
                }
                $who = $lang === 'en' && is_array($it['who'] ?? null) ? trim((string) ($it['who']['en'] ?? '')) : '';
                $src = self::sourcesHtml((array) ($it['sources'] ?? []));
                $out .= '<details class="acc"><summary>' . ($who !== '' ? '<small class="acc-who">' . $e($who) . '</small>' : '') . '<span>' . $e($head) . '</span></summary>'
                    . '<div class="acc-b">' . ($rest !== '' ? '<p>' . $e($rest) . '</p>' : '') . ($src !== '' ? '<ul class="tl-src">' . $src . '</ul>' : '') . '</div></details>';
            }
            return $out;
        };
        $b = $d['border'];
        $summary = PublicLayout::pick($b['summary'], $lang);
        $allSources = array_merge($allSources, (array) ($b['summary']['sources'] ?? []));
        $rules = $accordion($b['rules']);
        $crossing = $itemList($b['crossing']);
        $tips = $itemList($b['tips']);
        $borderInner = '';
        if ($summary !== '') {
            $borderInner .= '<p class="lead">' . $e($summary) . '</p>';
        }
        if ($rules !== '') {
            $borderInner .= '<h3 class="acc-h"><span aria-hidden="true">📜</span> ' . $e($w['rules']) . '</h3><div class="accs">' . $rules . '</div>';
        }
        $cols = '';
        foreach ([['crossing', '🛂', $crossing], ['tips', '💡', $tips]] as [$k, $ic, $li]) {
            if ($li !== '') {
                $cols .= '<div class="bcol"><h3><span aria-hidden="true">' . $ic . '</span> ' . $e($w[$k]) . '</h3><ul class="ticks">' . $li . '</ul></div>';
            }
        }
        if ($cols !== '') {
            $borderInner .= '<div class="bcols">' . $cols . '</div>';
        }
        if ($borderInner === '') {
            $borderInner = '<p class="note">' . $e($w['soonBorder']) . '</p>';
        }

        $reviewed = $d['reviewed'] !== '' ? sprintf($w['reviewed'], self::niceDate($d['reviewed'], $lang)) : '';
        $srcHtml = self::sourcesHtml(array_merge($allSources, $d['sources']), true);
        $flagIn = PublicLayout::flag('IN', 22);
        $flagNp = PublicLayout::flag('NP', 26);
        $pw = PublicLayout::words($lang);
        $bandAlt = $lang === 'en' ? 'Illustration: Sagarmatha (Mount Everest), prayer flags, Gautam Buddha and a stupa'
            : ($lang === 'hi' ? 'चित्र: सगरमाथा (माउंट एवरेस्ट), प्रार्थना ध्वज, गौतम बुद्ध और स्तूप' : 'चित्र: सगरमाथा, लुङ्दार, गौतम बुद्ध र स्तूप');

        $body = '<section class="hero">'
            . '<span class="blob b1"></span><span class="blob b2"></span>'
            . '<span class="kick">' . $flagIn . ' ⇄ ' . PublicLayout::flag('NP', 18) . ' ' . $e($w['inKick']) . '</span>'
            . '<h1>' . $e($w['inH1']) . '</h1>'
            . ($intro !== '' ? '<p class="lead">' . $e($intro) . '</p>' : '')
            . '<div class="hero-flags"><span class="flagchip float">' . $flagIn . ' ' . $e($w['india'] ?? 'India') . '</span><span class="flagchip float">' . $flagNp . ' ' . $e($w['nepal'] ?? 'Nepal') . '</span></div>'
            . '<picture><source type="image/webp" srcset="/assets/img/nepal-band-540.webp 540w, /assets/img/nepal-band.webp 1080w" sizes="(max-width: 640px) 100vw, 1000px">'
            . '<img class="hero-art" src="/assets/img/nepal-band.png" width="1080" height="300" alt="' . $e($bandAlt) . '" fetchpriority="high"></picture>'
            . '<div class="btns"><a class="btn fill" href="#timeline">🕰️ ' . $e($w['timeline']) . '</a><a class="btn tonal" href="/travel-info">🛂 ' . $e($w['carry']) . '</a></div>'
            . '</section>'
            . ($nums !== '' ? '<section class="sec"><h2>' . $e($w['keyNums']) . '</h2><div class="stats">' . $nums . '</div></section>' : '')
            . '<section class="sec" id="timeline"><h2>🕰️ ' . $e($w['timeline']) . '</h2>' . $tlHtml . '</section>'
            . '<section class="sec card rv" id="open-border"><h2>🤝 ' . $e($w['border']) . '</h2>' . $borderInner . '</section>'
            . '<section class="sec card carry rv" id="carry"><div class="carry-ic" aria-hidden="true">🎒</div><div><h2>' . $e($w['carry']) . '</h2><p>' . $e($w['carryP']) . '</p>'
            . '<div class="btns"><a class="btn fill" href="/travel-info">🛂 ' . $e($w['carryBtn']) . '</a></div></div></section>'
            . '<section class="sec band rv"><h2>🚌 ' . $e($w['ctaH']) . '</h2><p>' . $e($w['ctaP']) . '</p><div class="btns"><a class="btn fill" href="/#/">🎫 ' . $e($pw['book']) . '</a>'
            . '<a class="btn out" href="' . $e(PublicLayout::url('/offices', $lang)) . '">🏢 ' . $e($pw['offices']) . '</a><a class="btn out" href="' . $e(PublicLayout::url('/explore', $lang)) . '">🏔️ ' . $e($pw['explore']) . '</a></div></section>'
            . '<p class="disclaimer sec"><span aria-hidden="true">ℹ️</span><span>' . ($reviewed !== '' ? '<b>' . $e($reviewed) . '.</b> ' : '') . $e($w['disclaimer']) . '</span></p>'
            . ($srcHtml !== '' ? '<section class="sec" id="sources"><details class="acc src-all"><summary><span>📚 ' . $e($w['sources']) . ' · ' . substr_count($srcHtml, '<li>') . '</span></summary>'
                . '<div class="acc-b"><ol class="sources">' . $srcHtml . '</ol></div></details></section>' : '');

        $crumbs = self::crumbs($lang, $w['inH1'], '/india-nepal');
        $ld = self::ld([
            self::orgNode(),
            array_filter(['@type' => 'Article', '@id' => $canon . '#article', 'headline' => $w['inTitle'], 'description' => $w['inDesc'], 'inLanguage' => $lang,
                'url' => $canon, 'mainEntityOfPage' => $canon, 'image' => PublicLayout::base() . '/assets/img/nepal-band.png',
                'dateModified' => $d['reviewed'] !== '' ? $d['reviewed'] : null, 'author' => ['@id' => PublicLayout::base() . '/#org'], 'publisher' => ['@id' => PublicLayout::base() . '/#org'],
                'about' => [['@type' => 'Country', 'name' => 'India'], ['@type' => 'Country', 'name' => 'Nepal']]], static fn($v): bool => $v !== null),
            self::crumbsLd($crumbs),
        ]);
        self::send(PublicLayout::page($lang, $title, $w['inDesc'], $canon, PublicLayout::alts('/india-nepal'), $ld, $body,
            ['active' => 'india-nepal', 'path' => '/india-nepal', 'crumbs' => $crumbs, 'css' => self::css()]));
    }

    /* ================================================================
     *  /offices
     * ================================================================ */
    public static function offices(string $lang): never
    {
        $w = self::words($lang);
        $pw = PublicLayout::words($lang);
        $e = Closure::fromCallable([self::class, 'e']);
        $co = PublicLayout::company();
        $canon = PublicLayout::url('/offices', $lang);
        $list = PublicLayout::offices();
        // Head office, then our office across the border, then the branches in the order given.
        $rank = static fn(array $o): int => $o['kind'] === 'head' ? 0 : ($o['country'] === 'NP' ? 1 : 2);
        $pos = array_flip(array_keys($list));
        uksort($list, static fn($a, $b): int => [$rank($list[$a]), $pos[$a]] <=> [$rank($list[$b]), $pos[$b]]);
        $list = array_values($list);
        $open = array_values(array_filter($list, static fn(array $o): bool => $o['status'] === 'open'));
        $soon = array_values(array_filter($list, static fn(array $o): bool => $o['status'] === 'coming_soon'));
        $names = array_map(static fn(array $o): string => PublicLayout::officeName($o, $lang), $open);
        $headCity = '';
        foreach ($open as $o) {
            if ($o['kind'] === 'head') {
                $headCity = PublicLayout::officeName($o, $lang);
                break;
            }
        }
        $title = sprintf($w['offTitle'], implode(', ', $names)) . ' | ' . $co['name'];
        $desc = sprintf($w['offDesc'], implode(', ', $names), $headCity !== '' ? $headCity : ($names[0] ?? ''));
        $nIn = count(array_filter($open, static fn(array $o): bool => $o['country'] === 'IN'));
        $nNp = count($open) - $nIn;

        $cards = '';
        $ldOffices = [];
        foreach ($open as $o) {
            $nm = PublicLayout::officeName($o, $lang);
            $kindLabel = match (true) {
                $o['kind'] === 'head' => $w['head'],
                $o['country'] === 'NP' || $o['kind'] === 'border' => $w['borderOff'],
                $o['kind'] === 'partner' => $w['partner'],
                default => $w['branch'],
            };
            $addr = $lang !== 'en' && ($o['addressNe'] ?? '') !== '' ? $o['addressNe'] : $o['address'];
            // A map link only from the office's own data: its saved map link, else its written address.
            $map = $o['mapUrl'] !== '' && preg_match('#^https://#i', $o['mapUrl']) === 1 ? $o['mapUrl']
                : ($o['address'] !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim($o['address'] . (stripos($o['address'], $o['city']) === false ? ', ' . $o['city'] : '') . ', ' . ($o['country'] === 'NP' ? 'Nepal' : 'India'))) : '');
            $btns = '';
            if ($o['phone'] !== '' && $o['tel'] !== '') {
                $btns .= '<a class="btn fill sm" href="' . $e($o['tel']) . '">📞 ' . $e($w['call']) . ' <span class="num">' . $e($o['phone']) . '</span></a>';
            }
            if ($o['wa'] !== '') {
                $btns .= '<a class="btn wa sm" href="https://wa.me/' . $e($o['wa']) . '" target="_blank" rel="noopener">💬 ' . $e($w['wa']) . '</a>';
            }
            if ($map !== '') {
                $btns .= '<a class="btn out sm" href="' . $e($map) . '" target="_blank" rel="noopener">📍 ' . $e($w['map']) . '</a>';
            }
            $cards .= '<article class="card off rv' . ($o['kind'] === 'head' ? ' head' : ($o['country'] === 'NP' ? ' np' : '')) . '" id="office-' . $e(strtolower($o['code'] !== '' ? $o['code'] : preg_replace('/\W+/', '-', $o['city']))) . '">'
                . '<div class="off-top">' . PublicLayout::flag($o['country'], 24) . '<span class="badge">' . $e($kindLabel) . '</span>' . ($o['code'] !== '' ? '<span class="code">' . $e($o['code']) . '</span>' : '') . '</div>'
                . '<h2 class="off-name">' . $e($nm) . '</h2>'
                . ($addr !== '' ? '<p class="off-addr">📍 ' . $e($addr) . '</p>' : '')
                . ($o['hours'] !== '' ? '<p class="off-addr">🕘 ' . $e($w['hours']) . ': ' . $e($o['hours']) . '</p>' : '')
                . ($addr === '' && $o['hours'] === '' ? '<p class="off-addr">🎫 ' . $e($w['offSvc']) . '</p>' : '')
                . ($o['phone'] === '' ? '<p class="off-addr mut">☎️ ' . $e($w['soonNum']) . '</p>' : '')
                . '<div class="btns">' . $btns . '</div></article>';
            $ldOffices[] = array_filter([
                '@type' => 'TravelAgency', '@id' => $canon . '#office-' . strtolower($o['code'] !== '' ? $o['code'] : (string) preg_replace('/\W+/', '-', $o['city'])),
                'name' => $co['name'] . ' — ' . $o['name'], 'telephone' => $o['phone'] !== '' ? $o['phone'] : null, 'url' => $canon,
                'image' => PublicLayout::base() . '/assets/img/logo-mark.png',
                'address' => array_filter(['@type' => 'PostalAddress', 'streetAddress' => $o['address'] !== '' ? $o['address'] : null, 'addressLocality' => $o['city'], 'addressCountry' => $o['country']], static fn($v): bool => $v !== null),
                'parentOrganization' => ['@id' => PublicLayout::base() . '/#org'],
                'areaServed' => ['India', 'Nepal'],
            ], static fn($v): bool => $v !== null);
        }
        if ($cards === '') {
            $cards = '<div class="empty"><div class="big" aria-hidden="true">🏢</div><p>' . $e($w['noOffices']) . '</p></div>';
        }
        $soonChips = '';
        foreach ($soon as $o) {
            $soonChips .= '<span class="chip">' . PublicLayout::flag($o['country'], 12) . ' ' . $e(PublicLayout::officeName($o, $lang)) . ' · ' . $e($w['soonOpen']) . '</span>';
        }

        $body = '<section class="hero"><span class="blob b1"></span><span class="blob b2"></span>'
            . '<span class="kick">' . PublicLayout::flag('IN', 16) . ' ⇄ ' . PublicLayout::flag('NP', 18) . ' ' . $e($w['offKick']) . '</span>'
            . '<h1>' . $e($w['offH1']) . '</h1><p class="lead">' . $e($w['offLead']) . '</p>'
            . '<div class="hero-flags"><span class="flagchip">' . PublicLayout::flag('IN', 20) . ' ' . $e(sprintf($w['inIndia'], $nIn)) . '</span>'
            . ($nNp > 0 ? '<span class="flagchip">' . PublicLayout::flag('NP', 22) . ' ' . $e(sprintf($w['inNepal'], $nNp)) . '</span>' : '')
            . '<span class="flagchip">🚀 ' . $e($w['expH']) . '</span></div></section>'
            . '<section class="sec"><div class="grid offices">' . $cards . '</div></section>'
            . '<section class="sec band rv" id="expanding"><span class="band-ic" aria-hidden="true">🚀</span><h2>' . $e($w['expH']) . '</h2>'
            . ($lang !== 'ne' ? '<p class="band-ne" lang="ne">' . PublicLayout::flag('NP', 16) . ' नयाँ कार्यालय छिट्टै</p>' : '')
            . '<p>' . $e(sprintf($w['expP'], $co['name'])) . '</p>' . ($soonChips !== '' ? '<div class="chips" style="margin-top:10px">' . $soonChips . '</div>' : '')
            . '<div class="btns"><a class="btn fill" href="/agreements/">🤝 ' . $e($w['expPartner']) . '</a><a class="btn out" href="/agent-signup.php">🧑‍💼 ' . $e($w['expAgent']) . '</a></div></section>'
            . '<section class="sec"><div class="btns"><a class="btn tonal" href="/#/">🎫 ' . $e($pw['book']) . '</a><a class="btn tonal" href="' . $e(PublicLayout::url('/india-nepal', $lang)) . '">🤝 ' . $e($pw['indiaNepal']) . '</a></div></section>';

        $crumbs = self::crumbs($lang, $w['offH1'], '/offices');
        $ld = self::ld(array_merge([self::orgNode()], $ldOffices, [self::crumbsLd($crumbs)]));
        self::send(PublicLayout::page($lang, $title, $desc, $canon, PublicLayout::alts('/offices'), $ld, $body,
            ['active' => 'offices', 'path' => '/offices', 'crumbs' => $crumbs, 'css' => self::css()]));
    }

    /* ================================================================
     *  /explore
     * ================================================================ */
    public static function explore(string $lang): never
    {
        $w = self::words($lang);
        $e = Closure::fromCallable([self::class, 'e']);
        $co = PublicLayout::company();
        $canon = PublicLayout::url('/explore', $lang);
        $all = self::places();
        $catIn = strtolower(trim((string) ($_GET['cat'] ?? '')));
        $present = [];
        foreach ($all as $p) {
            $present[(string) $p['category']] = true;
        }
        $cat = isset($present[$catIn]) ? $catIn : '';
        $places = $cat === '' ? $all : array_values(array_filter($all, static fn(array $p): bool => $p['category'] === $cat));

        // "Near the border": within ~120 km (straight line) of the Rupaidiha ⇄ Nepalgunj
        // crossing when the place has coordinates, else a border-side stop with a short km hint.
        $isBorder = static function (array $p): bool {
            $crow = self::borderKm($p['lat'] !== null ? (float) $p['lat'] : null, $p['lng'] !== null ? (float) $p['lng'] : null);
            if ($crow !== null) {
                return $crow <= 120;
            }
            return preg_match(self::BORDER_STOPS, (string) ($p['near_stop'] ?? '')) === 1 && $p['km_hint'] !== null && (int) $p['km_hint'] <= 150;
        };
        $groups = ['border' => [], 'NP' => [], 'IN' => []];
        foreach ($places as $p) {
            if ($isBorder($p)) {
                $groups['border'][] = $p;
            } else {
                $groups[$p['country'] === 'IN' ? 'IN' : 'NP'][] = $p;
            }
        }

        $filters = '';
        if (count($present) > 1) {
            $filters .= '<a class="chip' . ($cat === '' ? ' on' : '') . '" href="' . $e($canon) . '#places"' . ($cat === '' ? ' aria-current="true"' : '') . '>' . $e($w['all']) . '</a>';
            foreach (array_keys(self::CATS + $present) as $c) {
                if (!isset($present[$c])) {
                    continue;
                }
                $filters .= '<a class="chip' . ($cat === $c ? ' on' : '') . '" href="' . $e($canon . '?cat=' . rawurlencode((string) $c)) . '#places"' . ($cat === $c ? ' aria-current="true"' : '') . '>'
                    . (self::CATS[$c] ?? '📍') . ' ' . $e(self::catLabel((string) $c, $lang)) . '</a>';
            }
            $filters = '<nav class="filters" aria-label="' . $e($w['all']) . '">' . $filters . '</nav>';
        }

        $picks = '';
        if ($cat === '') {
            foreach (array_slice(array_values(array_filter($all, static fn(array $p): bool => (int) $p['is_featured'] === 1)), 0, 8) as $p) {
                $picks .= '<a class="pick rv" href="#place-' . $e((string) $p['slug']) . '"><span class="pick-ic ph-' . $e((string) $p['category']) . '" aria-hidden="true">' . (self::CATS[$p['category']] ?? '📍') . '</span>'
                    . '<span><b>' . $e(self::nameOf($p, 'name', $lang)) . '</b><small>' . PublicLayout::flag((string) $p['country'], 11) . ' ' . $e((string) ($p['region'] ?? '')) . '</small></span></a>';
            }
        }

        $section = function (string $key, string $h, string $flag, array $rows) use ($e, $lang, $w): string {
            if ($rows === []) {
                return '';
            }
            $cards = '';
            foreach ($rows as $p) {
                $cards .= self::placeCard($p, $lang, $w);
            }
            return '<section class="sec" id="' . $e($key) . '"><h2 class="sec-t"><span class="sec-f">' . $flag . '</span><span>' . $e($h) . ' <span class="cnt">' . count($rows) . '</span></span></h2><div class="grid places">' . $cards . '</div></section>';
        };
        $content = $section('near-border', $w['nearBorder'], PublicLayout::flag('NP', 20) . PublicLayout::flag('IN', 16), $groups['border'])
            . $section('nepal', $w['nepal'], PublicLayout::flag('NP', 22), $groups['NP'])
            . $section('india', $w['india'], PublicLayout::flag('IN', 18), $groups['IN']);
        if ($all === []) {
            $content = '<div class="empty sec"><div class="big" aria-hidden="true">🏔️</div><h2>' . $e($w['exH1']) . '</h2><p>' . $e($w['soonPlaces']) . '</p>'
                . '<div class="btns" style="justify-content:center"><a class="btn fill" href="' . $e(PublicLayout::url('/india-nepal', $lang)) . '">🤝 ' . $e(PublicLayout::words($lang)['indiaNepal']) . '</a>'
                . '<a class="btn tonal" href="' . $e(PublicLayout::url('/tours', $lang)) . '">🧳 ' . $e($w['seeTours']) . '</a></div></div>';
        } elseif ($content === '') {
            $content = '<p class="empty sec">' . $e($w['noMatch']) . '</p>';
        }

        $body = '<section class="hero"><span class="blob b1"></span><span class="blob b2"></span>'
            . '<span class="kick">🧭 ' . $e($w['exKick']) . '</span><h1>' . $e($w['exH1']) . '</h1><p class="lead">' . $e($w['exLead']) . '</p>'
            . '<div class="hero-flags"><a class="flagchip" href="#nepal">' . PublicLayout::flag('NP', 22) . ' ' . $e($w['nepal']) . '</a><a class="flagchip" href="#india">' . PublicLayout::flag('IN', 18) . ' ' . $e($w['india']) . '</a>'
            . '<a class="flagchip" href="#near-border">🛂 ' . $e($w['nearBorder']) . '</a></div>'
            . '<picture><source type="image/webp" srcset="/assets/img/nepal-band-540.webp 540w, /assets/img/nepal-band.webp 1080w" sizes="(max-width: 640px) 100vw, 1000px">'
            . '<img class="hero-art" src="/assets/img/nepal-band.png" width="1080" height="300" alt="" fetchpriority="high"></picture></section>'
            . ($picks !== '' ? '<section class="sec"><h2>⭐ ' . $e($w['picks']) . '</h2><div class="picks">' . $picks . '</div></section>' : '')
            . '<div id="places">' . $filters . '</div>' . $content
            . '<section class="sec band rv"><h2>🧳 ' . $e($w['tH1']) . '</h2><p>' . $e($w['tLead']) . '</p><div class="btns"><a class="btn fill" href="' . $e(PublicLayout::url('/tours', $lang)) . '">' . $e($w['seeTours']) . '</a>'
            . '<a class="btn out" href="/#/">🎫 ' . $e(PublicLayout::words($lang)['book']) . '</a></div></section>';

        $items = [];
        foreach ($all as $i => $p) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => array_filter([
                '@type' => 'TouristDestination', 'name' => self::nameOf($p, 'name', $lang), 'description' => self::clip(self::textOf($p, 'blurb', $lang), 200) ?: null,
                'url' => $canon . '#place-' . $p['slug'], 'image' => self::imgUrl((string) ($p['image'] ?? '')) !== '' ? PublicLayout::base() . self::imgUrl((string) $p['image']) : null,
                'geo' => $p['lat'] !== null && $p['lng'] !== null ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $p['lat'], 'longitude' => (float) $p['lng']] : null,
                'address' => ['@type' => 'PostalAddress', 'addressCountry' => (string) $p['country'], 'addressRegion' => (string) ($p['region'] ?? '')],
            ], static fn($v): bool => $v !== null)];
        }
        $crumbs = self::crumbs($lang, $w['exH1'], '/explore');
        $ld = self::ld([self::orgNode(), ['@type' => 'ItemList', 'name' => $w['exTitle'], 'url' => $canon, 'numberOfItems' => count($items), 'itemListElement' => $items], self::crumbsLd($crumbs)]);
        self::send(PublicLayout::page($lang, $w['exTitle'] . ' | ' . $co['name'], $w['exDesc'], $canon, PublicLayout::alts('/explore'), $ld, $body,
            ['active' => 'explore', 'path' => '/explore', 'crumbs' => $crumbs, 'css' => self::css()]));
    }

    private static function placeCard(array $p, string $lang, array $w): string
    {
        $e = Closure::fromCallable([self::class, 'e']);
        $cat = (string) $p['category'];
        $img = self::imgUrl((string) ($p['image'] ?? ''));
        $name = self::nameOf($p, 'name', $lang);
        $blurb = self::textOf($p, 'blurb', $lang);
        $season = self::textOf($p, 'best_season', $lang);
        $reach = self::textOf($p, 'how_to_reach', $lang);
        $km = $p['km_hint'] !== null && (int) $p['km_hint'] > 0 && trim((string) ($p['near_stop'] ?? '')) !== ''
            ? sprintf($w['kmFrom'], (int) $p['km_hint'], (string) $p['near_stop']) : '';
        $map = $p['lat'] !== null && $p['lng'] !== null
            ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(sprintf('%.6f,%.6f', (float) $p['lat'], (float) $p['lng'])) : '';
        // No photo yet: a short tonal band with the category drawn big (not an empty 16:9 box).
        $art = $img !== ''
            ? '<img src="' . $e($img) . '" alt="' . $e($name) . '" loading="lazy" decoding="async" width="640" height="360">'
            : '<span class="ph-emo" aria-hidden="true">' . (self::CATS[$cat] ?? '📍') . '</span>';
        $reachText = $reach !== '' ? $reach
            : (trim((string) ($p['near_stop'] ?? '')) !== '' && $km === '' ? sprintf($w['nearStop'], (string) $p['near_stop']) : '');
        $facts = '';
        if ($season !== '') {
            $facts .= '<p class="pf"><span class="pf-k">🗓️ ' . $e($w['bestSeason']) . '</span>' . $e($season) . '</p>';
        }
        if ($reachText !== '') {
            $facts .= mb_strlen($reachText) > 90
                ? '<details class="pf pf-d"><summary><span class="pf-k">🚌 ' . $e($w['reach']) . '</span></summary>' . $e($reachText) . '</details>'
                : '<p class="pf"><span class="pf-k">🚌 ' . $e($w['reach']) . '</span>' . $e($reachText) . '</p>';
        }
        $region = trim((string) ($p['region'] ?? ''));
        return '<article class="card place rv" id="place-' . $e((string) $p['slug']) . '">'
            . '<div class="ph ph-' . $e($cat) . ($img === '' ? ' ph-band' : '') . '">' . $art . '<span class="ph-flag">' . PublicLayout::flag((string) $p['country'], 16) . '</span>'
            . ((int) $p['unesco'] === 1 ? '<span class="ph-unesco">🏛️ ' . $e($w['unesco']) . '</span>' : '')
            . '<span class="ph-cat">' . $e(self::catLabel($cat, $lang)) . '</span></div>'
            . '<div class="pb">'
            . '<h3>' . $e($name) . '</h3>' . ($region !== '' ? '<p class="region">📍 ' . $e($region) . '</p>' : '')
            . ($blurb !== '' ? '<p>' . $e($blurb) . '</p>' : '')
            . ($facts !== '' ? '<div class="facts">' . $facts . '</div>' : '')
            . ($km !== '' || $map !== '' ? '<p class="km">' . ($km !== '' ? '📏 ' . $e($km) : '') . ($map !== '' ? ' <a href="' . $e($map) . '" target="_blank" rel="noopener">🗺️ ' . $e($w['onMap']) . '</a>' : '') . '</p>' : '')
            . (trim((string) ($p['image_credit'] ?? '')) !== '' && $img !== '' ? '<p class="credit">' . $e(sprintf($w['photo'], (string) $p['image_credit'])) . '</p>' : '')
            . '</div></article>';
    }

    /* ================================================================
     *  /tours and /tours/<slug>
     * ================================================================ */
    private static function priceLine(array $k, array $w): string
    {
        if ($k['price_from'] === null || (float) $k['price_from'] <= 0) {
            return $w['onRequest'];
        }
        $s = sprintf($w['from'], number_format((float) $k['price_from']));
        return trim((string) ($k['price_basis'] ?? '')) !== '' ? $s . ' · ' . $k['price_basis'] : $s;
    }

    private static function durLine(array $k, array $w): string
    {
        return (int) $k['nights'] > 0 ? sprintf($w['daysNights'], (int) $k['days'], (int) $k['nights']) : sprintf($w['days'], (int) $k['days']);
    }

    /** A picture word for a package without a photo, from its own title. */
    private static function pkgIcon(array $k): string
    {
        $t = strtolower((string) $k['title_en'] . ' ' . (string) ($k['route_en'] ?? ''));
        return match (true) {
            (bool) preg_match('/safari|jungle|national park|wildlife/', $t) => '🐘',
            (bool) preg_match('/everest|mountain flight|himal/', $t) => '🏔️',
            (bool) preg_match('/buddh|lumbini.*kushinagar|sarnath/', $t) => '☸️',
            (bool) preg_match('/ram|janaki|darshan|yatra|temple|pashupati|muktinath|somnath|dwarka/', $t) => '🛕',
            (bool) preg_match('/heritage|trail|fort|palace/', $t) => '🏛️',
            (bool) preg_match('/lake|pokhara/', $t) => '🛶',
            default => '🗺️',
        };
    }

    private static function packageCard(array $k, string $lang, array $w): string
    {
        $e = Closure::fromCallable([self::class, 'e']);
        $img = self::imgUrl((string) ($k['image'] ?? ''));
        $title = self::nameOf($k, 'title', $lang);
        $hl = self::jsonList($lang === 'ne' ? ($k['highlights_ne'] ?: $k['highlights_en']) : $k['highlights_en']);
        $chips = '';
        foreach (array_slice($hl, 0, 2) as $h) {
            $chips .= '<li>' . $e($h) . '</li>';
        }
        $route = $lang === 'en' ? (string) ($k['route_en'] ?? '') : ((string) ($k['route_ne'] ?? '') ?: (string) ($k['route_en'] ?? ''));
        $url = PublicLayout::url('/tours/' . $k['slug'], $lang);
        $flag = match ((string) $k['country']) { 'India' => PublicLayout::flag('IN', 14), 'Both' => PublicLayout::flag('NP', 16) . PublicLayout::flag('IN', 14), default => PublicLayout::flag('NP', 16) };
        $dw = self::words($lang);
        return '<article class="card pkg rv"><a class="ph ph-tour' . ($img === '' ? ' ph-band' : '') . '" href="' . $e($url) . '" tabindex="-1" aria-hidden="true">'
            . ($img !== '' ? '<img src="' . $e($img) . '" alt="" loading="lazy" decoding="async" width="640" height="360">'
                : '<span class="ph-days"><b>' . (int) $k['days'] . '</b><small>' . $e(trim(str_replace('%d', '', $dw['days']))) . '</small></span><span class="ph-emo">' . self::pkgIcon($k) . '</span>')
            . '<span class="ph-flag">' . $flag . '</span></a>'
            . '<div class="pb"><div class="chips"><span class="chip on">🗓️ ' . $e(self::durLine($k, $w)) . '</span><span class="chip">' . $e($w['country'][(string) $k['country']] ?? (string) $k['country']) . '</span></div>'
            . '<h3><a href="' . $e($url) . '">' . $e($title) . '</a></h3>'
            . ($route !== '' ? '<p class="route">🛣️ ' . $e($route) . '</p>' : '')
            . ($chips !== '' ? '<ul class="ticks">' . $chips . '</ul>' : '')
            . '<div class="pkg-foot"><b class="price">' . $e(self::priceLine($k, $w)) . '</b><a class="btn fill sm" href="' . $e($url) . '">' . $e($w['view']) . ' →</a></div></div></article>';
    }

    public static function tours(string $lang): never
    {
        $w = self::words($lang);
        $e = Closure::fromCallable([self::class, 'e']);
        $co = PublicLayout::company();
        $canon = PublicLayout::url('/tours', $lang);
        $list = self::packages();
        $cards = '';
        foreach ($list as $k) {
            $cards .= self::packageCard($k, $lang, $w);
        }
        $body = '<section class="hero tours-hero"><span class="blob b1"></span><span class="blob b2"></span>'
            . '<span class="kick">🚌 + 🏔️ ' . $e($w['tKick']) . '</span><h1>' . $e($w['tH1']) . '</h1><p class="lead">' . $e($w['tLead']) . '</p>'
            . '<div class="coach"><span aria-hidden="true">🚌</span><span>' . $e($w['coach']) . '</span></div>'
            . '<div class="btns"><a class="btn fill" href="#enquire">✍️ ' . $e($w['apply']) . '</a><a class="btn tonal" href="' . $e(PublicLayout::url('/explore', $lang)) . '">🏔️ ' . $e(PublicLayout::words($lang)['explore']) . '</a></div></section>'
            . ($cards !== '' ? '<section class="sec"><div class="grid pkgs">' . $cards . '</div></section>'
                : '<div class="empty sec"><div class="big" aria-hidden="true">🧳</div><p>' . $e($w['soonTours']) . '</p></div>')
            . self::enquiryForm(null, $lang, $w, $w['custom']);

        $items = [];
        foreach ($list as $i => $k) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => self::tripLd($k, $lang, $w)];
        }
        $crumbs = self::crumbs($lang, $w['tH1'], '/tours');
        $ld = self::ld([self::orgNode(), ['@type' => 'ItemList', 'name' => $w['tTitle'], 'url' => $canon, 'numberOfItems' => count($items), 'itemListElement' => $items], self::crumbsLd($crumbs)]);
        self::send(PublicLayout::page($lang, $w['tTitle'] . ' | ' . $co['name'], $w['tDesc'], $canon, PublicLayout::alts('/tours'), $ld, $body,
            ['active' => 'tours', 'path' => '/tours', 'crumbs' => $crumbs, 'css' => self::css()]));
    }

    /** @return array<string,mixed> TouristTrip node */
    private static function tripLd(array $k, string $lang, array $w): array
    {
        $url = PublicLayout::url('/tours/' . $k['slug'], $lang);
        $img = self::imgUrl((string) ($k['image'] ?? ''));
        $itin = [];
        foreach (self::itinerary($k, $lang) as $i => $d) {
            $itin[] = ['@type' => 'ListItem', 'position' => $i + 1, 'item' => ['@type' => 'TouristAttraction', 'name' => mb_substr($d['text'], 0, 120)]];
        }
        return array_filter([
            '@type' => 'TouristTrip', 'name' => self::nameOf($k, 'title', $lang), 'description' => self::textOf($k, 'summary', $lang) ?: null,
            'url' => $url, 'image' => $img !== '' ? PublicLayout::base() . $img : null, 'inLanguage' => $lang,
            'provider' => ['@id' => PublicLayout::base() . '/#org'],
            'itinerary' => $itin !== [] ? ['@type' => 'ItemList', 'numberOfItems' => count($itin), 'itemListElement' => $itin] : null,
            'offers' => $k['price_from'] !== null && (float) $k['price_from'] > 0
                ? ['@type' => 'Offer', 'price' => (string) (int) $k['price_from'], 'priceCurrency' => 'INR', 'url' => $url] : null,
        ], static fn($v): bool => $v !== null);
    }

    /**
     * "Your journey" — our bus leg(s) and the package route as one stepper.
     * Built only from the package's own route line and coach_leg: our bus
     * Gujarat → Rupaidiha, then the partner's stops, then our bus home.
     */
    private static function journeyHtml(array $k, string $lang, array $w): string
    {
        $e = Closure::fromCallable([self::class, 'e']);
        $route = $lang === 'en' ? (string) ($k['route_en'] ?? '') : ((string) ($k['route_ne'] ?? '') ?: (string) ($k['route_en'] ?? ''));
        $stops = array_values(array_filter(array_map('trim', preg_split('/\s*(?:→|->|⇒)\s*/u', $route) ?: []), static fn(string $s): bool => $s !== ''));
        if (count($stops) < 2) {
            return '';
        }
        $leg = (string) ($k['coach_leg'] ?? 'both');
        $li = '';
        if ($leg === 'both' || $leg === 'toNepal') {
            $li .= '<li class="j-bus"><span class="j-dot" aria-hidden="true">🚌</span><div><b>' . $e($w['jBus']) . '</b><small>' . $e($w['jBusP']) . '</small></div></li>';
        }
        $n = count($stops);
        foreach ($stops as $i => $s) {
            $li .= '<li class="j-stop' . ($i === 0 || $i === $n - 1 ? ' j-end' : '') . '"><span class="j-dot" aria-hidden="true">' . ($i + 1) . '</span><div><b>' . $e($s) . '</b>'
                . ($i === 0 ? '<small>' . $e($leg === 'none' ? $w['partnerOnlyS'] : $w['jTour']) . '</small>' : '') . '</div></li>';
        }
        if ($leg === 'both' || $leg === 'toIndia') {
            $li .= '<li class="j-bus"><span class="j-dot" aria-hidden="true">🚌</span><div><b>' . $e($w['jHome']) . '</b><small>' . $e($w['jHomeP']) . '</small></div></li>';
        }
        return '<section class="sec card rv"><h2>🛣️ ' . $e($w['journey']) . '</h2><ol class="journey">' . $li . '</ol></section>';
    }

    /** @return list<array{day:int,text:string}> */
    private static function itinerary(array $k, string $lang): array
    {
        $raw = json_decode((string) ($k['itinerary_json'] ?? ''), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach (array_values($raw) as $i => $d) {
            if (is_string($d)) {
                $d = ['en' => $d];
            }
            if (!is_array($d)) {
                continue;
            }
            $t = PublicLayout::pick(['en' => $d['en'] ?? $d['title_en'] ?? $d['text_en'] ?? '', 'ne' => $d['ne'] ?? $d['title_ne'] ?? $d['text_ne'] ?? '', 'hi' => $d['hi'] ?? $d['title_hi'] ?? ''], $lang);
            if ($t !== '') {
                $out[] = ['day' => (int) ($d['day'] ?? $i + 1), 'text' => $t];
            }
        }
        return $out;
    }

    public static function tour(string $slug, string $lang): never
    {
        $k = self::package($slug);
        $preview = false;
        if ($k !== null && $k['status'] !== 'published') {
            $preview = ($_GET['preview'] ?? '') === '1' && Auth::can('dashboard.view');
            if (!$preview) {
                $k = null;
            }
        }
        if ($k === null) {
            self::notFound($lang);
        }
        $w = self::words($lang);
        $e = Closure::fromCallable([self::class, 'e']);
        $co = PublicLayout::company();
        $path = '/tours/' . $k['slug'];
        $canon = PublicLayout::url($path, $lang);
        $title = self::nameOf($k, 'title', $lang);
        $summary = self::textOf($k, 'summary', $lang);
        $route = $lang === 'en' ? (string) ($k['route_en'] ?? '') : ((string) ($k['route_ne'] ?? '') ?: (string) ($k['route_en'] ?? ''));
        $img = self::imgUrl((string) ($k['image'] ?? ''));
        $hl = self::jsonList($lang === 'ne' ? ($k['highlights_ne'] ?: $k['highlights_en']) : $k['highlights_en']);
        $hlHtml = '';
        foreach ($hl as $h) {
            $hlHtml .= '<li>' . $e($h) . '</li>';
        }
        $days = '';
        foreach (self::itinerary($k, $lang) as $d) {
            $days .= '<li class="tl-i rv"><span class="tl-dot" aria-hidden="true">' . (int) $d['day'] . '</span><article class="tl-card"><span class="tl-year">' . $e(sprintf($w['day'], (int) $d['day'])) . '</span><p>' . $e($d['text']) . '</p></article></li>';
        }
        $wa = Settings::officeWhatsApp();
        $waLink = 'https://wa.me/' . $wa . '?text=' . rawurlencode(($lang === 'en' ? 'Hello, tour enquiry: ' : 'नमस्ते, टुर: ') . $title);
        $others = '';
        foreach (self::packages() as $o) {
            if ($o['slug'] !== $k['slug']) {
                $others .= self::packageCard($o, $lang, $w);
            }
        }
        $meta = '<span class="chip on">🗓️ ' . $e(self::durLine($k, $w)) . '</span><span class="chip">' . $e($w['country'][(string) $k['country']] ?? (string) $k['country']) . '</span>'
            . ((int) ($k['min_pax'] ?? 0) > 0 ? '<span class="chip">👥 ' . $e(sprintf($w['minPax'], (int) $k['min_pax'])) . '</span>' : '')
            . (trim((string) ($k['partner_name'] ?? '')) !== '' ? '<span class="chip">🤝 ' . $e(sprintf($w['partnerBy'], (string) $k['partner_name'])) . '</span>' : '');

        $body = ($preview ? '<p class="disclaimer" style="margin-bottom:12px">👁️ ' . $e($w['draft']) . '</p>' : '')
            . '<section class="hero tour-hero"><span class="blob b1"></span><span class="blob b2"></span>'
            . '<span class="kick">🧳 ' . $e($w['tH1']) . '</span><h1>' . $e($title) . '</h1>'
            . '<div class="chips">' . $meta . '</div>'
            . ($summary !== '' ? '<p class="lead" style="margin-top:12px">' . $e($summary) . '</p>' : '')
            . ($img !== '' ? '<img class="hero-art" src="' . $e($img) . '" alt="' . $e($title) . '" width="1080" height="600" style="aspect-ratio:16/9">' : '')
            . '<p class="price big-price">' . $e(self::priceLine($k, $w)) . '</p>'
            . '<div class="btns"><a class="btn fill" href="#enquire">✍️ ' . $e($w['apply']) . '</a><a class="btn wa" href="' . $e($waLink) . '" target="_blank" rel="noopener">💬 ' . $e($w['waAsk']) . '</a></div></section>'
            . '<p class="coach sec"><span aria-hidden="true">' . ($k['coach_leg'] !== 'none' ? '🚌' : '🤝') . '</span><span>' . $e($k['coach_leg'] !== 'none' ? $w['coach'] : $w['partnerOnly']) . '</span></p>'
            . (($jr = self::journeyHtml($k, $lang, $w)) !== '' ? $jr
                : ($route !== '' ? '<section class="sec card rv"><h2>🛣️ ' . $e($w['route']) . '</h2><p class="lead">' . $e($route) . '</p></section>' : ''))
            . ($hlHtml !== '' ? '<section class="sec card rv"><h2>✨ ' . $e($w['highlights']) . '</h2><ul class="ticks">' . $hlHtml . '</ul></section>' : '')
            . ($days !== '' ? '<section class="sec"><h2>🗺️ ' . $e($w['itinerary']) . '</h2><ol class="tl">' . $days . '</ol></section>' : '')
            . self::enquiryForm($k, $lang, $w, $w['apply'])
            . ($others !== '' ? '<section class="sec"><h2>' . $e($w['more']) . '</h2><div class="grid pkgs">' . $others . '</div></section>' : '');

        $crumbs = self::crumbs($lang, $w['tH1'], '/tours', [[$title, $canon]]);
        $ld = self::ld([self::orgNode(), self::tripLd($k, $lang, $w), self::crumbsLd($crumbs)]);
        self::send(PublicLayout::page($lang, $title . ' — ' . $w['tH1'] . ' | ' . $co['name'], $summary !== '' ? mb_substr($summary, 0, 155) : $w['tDesc'], $canon, PublicLayout::alts($path), $ld, $body,
            ['active' => 'tours', 'path' => $path, 'crumbs' => $crumbs, 'css' => self::css(), 'noindex' => $preview]), 200, !$preview);
    }

    /** The "Apply / Enquire" form. No token inside — it is fetched with a GET when the form is first touched. */
    private static function enquiryForm(?array $k, string $lang, array $w, string $heading): string
    {
        $e = Closure::fromCallable([self::class, 'e']);
        $phone = Settings::officePhone();
        $tel = PublicLayout::telHref($phone);
        $wa = Settings::officeWhatsApp();
        $min = date('Y-m');
        $minD = date('Y-m-d');
        $pkg = $k !== null ? '<input type="hidden" name="package" value="' . $e((string) $k['slug']) . '">' : '';
        return '<section class="sec card formcard" id="enquire"><h2>✍️ ' . $e($heading) . '</h2><p class="note" style="margin:0 0 14px">' . $e($w['fNote']) . '</p>'
            . '<form id="trvForm" data-sending="' . $e($w['fSending']) . '" data-err="' . $e($w['fErr']) . '" autocomplete="on">' . $pkg
            . '<input type="hidden" name="lang" value="' . $e($lang) . '">'
            . '<div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
            . '<div class="fgrid">'
            . '<label class="fld"><span>' . $e($w['fName']) . ' *</span><input name="name" required minlength="2" maxlength="120" autocomplete="name"></label>'
            . '<label class="fld"><span>' . $e($w['fPhone']) . ' *</span><input name="phone" type="tel" required inputmode="tel" autocomplete="tel" maxlength="20" pattern="[0-9 +()\-]{10,20}"></label>'
            . '<label class="fld"><span>' . $e($w['fAdults']) . '</span><input name="adults" type="number" min="1" max="40" value="2" inputmode="numeric"></label>'
            . '<label class="fld"><span>' . $e($w['fKids']) . '</span><input name="children" type="number" min="0" max="20" value="0" inputmode="numeric"></label>'
            . '<label class="fld"><span>' . $e($w['fMonth']) . '</span><input name="month" type="month" min="' . $min . '"></label>'
            . '<label class="fld"><span>' . $e($w['fDate']) . '</span><input name="date" type="date" min="' . $minD . '"></label>'
            . '<label class="fld full"><span>' . $e($w['fFrom']) . '</span><input name="from_city" maxlength="80" autocomplete="address-level2"></label>'
            . '<label class="fld full"><span>' . $e($w['fMsg']) . '</span><textarea name="message" rows="3" maxlength="1000"></textarea></label>'
            . '</div><div class="btns"><button class="btn fill" type="submit">📨 ' . $e($w['fSend']) . '</button>'
            . ($tel !== '' ? '<a class="btn out" href="' . $e($tel) . '">📞 ' . $e($phone) . '</a>' : '')
            . '<a class="btn wa" href="https://wa.me/' . $e($wa) . '" target="_blank" rel="noopener">💬 WhatsApp</a></div>'
            . '<p class="f-out" role="status" aria-live="polite"></p></form>'
            . '<noscript><p class="note">' . $e($w['fNoJs']) . '</p></noscript></section>'
            . '<script>' . self::formJs() . '</script>';
    }

    private static function formJs(): string
    {
        return <<<'JS'
(function(){var f=document.getElementById('trvForm');if(!f)return;var out=f.querySelector('.f-out'),btn=f.querySelector('button[type=submit]'),label=btn.innerHTML,tok=null;
function token(force){if(tok&&!force)return Promise.resolve(tok);return fetch('/api/tour-enquiry.php',{credentials:'same-origin',headers:{'Accept':'application/json'}}).then(function(r){return r.json()}).then(function(j){tok=j.csrf||null;return tok})}
f.addEventListener('focusin',function(){token().catch(function(){})},{once:true});
function send(d,retry){return token(retry).then(function(t){return fetch('/api/tour-enquiry.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':t||''},body:JSON.stringify(d)})}).then(function(r){return r.json().catch(function(){return{}}).then(function(j){if(r.status===419&&!retry)return send(d,true);return{s:r.status,j:j}})})}
f.addEventListener('submit',function(ev){ev.preventDefault();if(f.reportValidity&&!f.reportValidity())return;var d={};new FormData(f).forEach(function(v,k){d[k]=v});
Array.prototype.forEach.call(f.querySelectorAll('[aria-invalid]'),function(el){el.removeAttribute('aria-invalid')});
btn.disabled=true;btn.textContent=f.dataset.sending;out.className='f-out';out.textContent='';
send(d,false).then(function(x){var j=x.j||{};if(j.ok){out.className='f-out ok';out.textContent=j.message||j.ref||'';f.querySelectorAll('input:not([type=hidden]),textarea').forEach(function(el){if(el.name!=='adults'&&el.name!=='children')el.value=''});}
else{out.className='f-out bad';out.textContent=j.error||f.dataset.err;var fl=j.fields||{};Object.keys(fl).forEach(function(k){var el=f.querySelector('[name="'+k+'"]');if(el){el.setAttribute('aria-invalid','true');}});var first=f.querySelector('[aria-invalid]');if(first)first.focus();}})
.catch(function(){out.className='f-out bad';out.textContent=f.dataset.err}).then(function(){btn.disabled=false;btn.innerHTML=label})});})();
JS;
    }

    /* ----------------------------------------------------------------
     *  Page CSS (on top of the shell's)
     * ---------------------------------------------------------------- */
    private static function css(): string
    {
        return <<<'CSS'
.h-sm{font-size:16px}
.tl{list-style:none;position:relative;margin:6px 0 0;padding:0 0 0 40px}
.tl::before{content:"";position:absolute;left:17px;top:8px;bottom:8px;width:4px;border-radius:4px;background:linear-gradient(var(--md-primary),var(--md-tertiary),var(--red));transform-origin:top}
.tl-i{position:relative;margin:0 0 16px}
.tl-dot{position:absolute;left:-40px;top:14px;width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:var(--md-primary-container);color:var(--md-on-primary-container);font-size:17px;font-weight:900;box-shadow:0 0 0 5px var(--md-surface)}
html.rv-on .tl-i .tl-dot{transform:scale(.3);transition:transform .5s cubic-bezier(.34,1.56,.64,1) .15s}html.rv-on .tl-i.in .tl-dot{transform:none}
.tl-card{background:var(--md-sc-low);border-radius:24px;padding:16px 18px;box-shadow:var(--shadow)}
.tl-card p{margin:4px 0 0;color:var(--md-on-surface-variant)}
.tl-year{display:inline-block;font-weight:900;font-size:13px;letter-spacing:.05em;padding:3px 11px;border-radius:999px;background:var(--md-tertiary-container);color:var(--md-on-tertiary-container);margin-bottom:8px}
.tl-src{list-style:none;margin-top:10px;display:flex;flex-wrap:wrap;gap:6px;font-size:11.5px}
.tl-src li{display:inline-flex}.tl-src a{display:inline-flex;align-items:center;min-height:28px;padding:0 10px;border-radius:999px;background:var(--md-sc-high);color:var(--md-on-surface-variant);text-decoration:none;font-weight:600}
.tl-src a::before{content:"↗";margin-right:4px;opacity:.7}.tl-src a:hover{background:var(--md-secondary-container);color:var(--md-on-secondary-container)}
.soon{display:inline-flex;gap:8px;padding:10px 14px;border-radius:16px;background:var(--md-sc)}
.bcols{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(min(100%,250px),1fr));margin-top:12px}
.bcol{background:var(--md-sc-lowest);border-radius:20px;padding:14px 16px}
.ticks{list-style:none;padding:0;margin:6px 0 0}.ticks li{position:relative;padding:4px 0 4px 26px;font-size:15px}.ticks li::before{content:"✓";position:absolute;left:2px;top:4px;width:18px;height:18px;border-radius:50%;background:var(--ok-container);color:var(--ok);font-size:11px;font-weight:900;display:grid;place-items:center}
.acc-h{margin:18px 0 8px}
.accs{display:grid;gap:8px}
.acc{background:var(--md-sc-lowest);border-radius:18px;border:1px solid var(--md-outline-variant);overflow:hidden}
.acc summary{list-style:none;cursor:pointer;display:flex;flex-direction:column;gap:2px;padding:13px 46px 13px 16px;position:relative;min-height:48px;font-weight:700;font-size:15px;line-height:1.45}
.acc summary::-webkit-details-marker{display:none}
.acc summary::after{content:"";position:absolute;right:18px;top:20px;width:9px;height:9px;border-right:2.5px solid var(--md-on-surface-variant);border-bottom:2.5px solid var(--md-on-surface-variant);transform:rotate(45deg);transition:transform .25s var(--ease)}
.acc[open] summary::after{transform:rotate(225deg);top:24px}
.acc[open]{border-color:var(--md-primary);box-shadow:var(--shadow)}
.acc-who{font-size:11.5px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--md-primary)}
.acc-b{padding:0 16px 14px;color:var(--md-on-surface-variant);font-size:14.5px}
.src-all summary{font-size:16px}
.sources{columns:1}
@media (min-width:720px){.sources{columns:2;column-gap:28px}.sources li{break-inside:avoid}}
.stat-w b{font-size:21px;line-height:1.25}.stat-m b{font-size:25px;white-space:nowrap}
.journey{list-style:none;position:relative;margin:10px 0 0;padding:0}
.journey::before{content:"";position:absolute;left:19px;top:22px;bottom:22px;width:3px;border-radius:3px;background:repeating-linear-gradient(var(--md-outline-variant) 0 6px,transparent 6px 11px)}
.journey li{position:relative;display:flex;gap:14px;align-items:flex-start;padding:7px 0}
.j-dot{position:relative;z-index:1;flex:none;width:41px;height:41px;border-radius:50%;display:grid;place-items:center;font-weight:900;font-size:15px;background:var(--md-sc-high);color:var(--md-on-surface);box-shadow:0 0 0 4px var(--md-sc-low)}
.journey b{display:block;font-size:15.5px;line-height:1.35;padding-top:9px}.journey small{display:block;font-size:13px;color:var(--md-on-surface-variant)}
.j-bus .j-dot{background:var(--md-primary);color:var(--md-on-primary);font-size:19px}
.j-bus b{color:var(--md-primary)}
.j-end .j-dot{background:var(--md-tertiary-container);color:var(--md-on-tertiary-container)}
.carry{display:flex;gap:16px;align-items:flex-start;background:linear-gradient(135deg,var(--md-sc-low),var(--md-secondary-container))}
.carry-ic{font-size:40px;line-height:1;flex:none}
.band-ic{position:absolute;right:16px;top:12px;font-size:44px;opacity:.25}
/* offices */
.offices .off{display:flex;flex-direction:column;gap:6px;position:relative;overflow:hidden}
.off-top{display:flex;align-items:center;gap:10px}
.badge{font-size:12px;font-weight:800;padding:4px 10px;border-radius:999px;background:var(--md-sc-high);color:var(--md-on-surface-variant)}
.code{margin-left:auto;font:800 12px ui-monospace,monospace;color:var(--md-on-surface-variant)}
.off-name{font-size:22px;margin:4px 0 0}
.off-addr{font-size:14px;color:var(--md-on-surface-variant)}
.off .btns{margin-top:auto;padding-top:8px}
.off .num{font-weight:700;opacity:.9}
.off.head{background:linear-gradient(140deg,var(--md-primary-container),var(--md-sc-low) 70%);border:2px solid var(--md-primary)}
.off.head .badge{background:var(--md-primary);color:var(--md-on-primary)}
.off.np{background:linear-gradient(140deg,color-mix(in srgb,var(--red) 13%,var(--md-sc-low)),var(--md-sc-low) 65%);border:2px solid color-mix(in srgb,var(--red) 55%,transparent)}
.off.np .badge{background:var(--red);color:#fff}
.band-ne{display:inline-flex;align-items:center;gap:8px;margin:-4px 0 4px;padding:5px 12px;border-radius:999px;background:rgba(255,255,255,.14);font-weight:800;color:#fff !important}
@media (min-width:720px){.off.head{grid-column:span 2}}
/* explore */
.picks{display:flex;gap:10px;overflow-x:auto;padding:4px 2px 10px;scroll-snap-type:x mandatory;scrollbar-width:thin}
.pick{flex:none;scroll-snap-align:start;display:flex;align-items:center;gap:10px;min-height:64px;padding:10px 16px 10px 10px;border-radius:20px;background:var(--md-sc-low);box-shadow:var(--shadow);text-decoration:none;color:var(--md-on-surface)}
.pick b{display:block;font-size:15px}.pick small{display:block;font-size:12px;color:var(--md-on-surface-variant)}
.pick-ic{width:44px;height:44px;border-radius:14px;display:grid;place-items:center;font-size:22px}
.place,.pkg{padding:0;overflow:hidden;display:flex;flex-direction:column}
.ph{position:relative;display:grid;place-items:center;aspect-ratio:16/9;background:linear-gradient(135deg,#C9D8FF,#FFD8E4);overflow:hidden;text-decoration:none}
.ph img{width:100%;height:100%;object-fit:cover;display:block}
.ph-emo{font-size:56px;filter:drop-shadow(0 6px 10px rgba(0,0,0,.18))}
.ph-pilgrimage{background:linear-gradient(135deg,#FFE0B2,#FFB74D)}.ph-heritage{background:linear-gradient(135deg,#F5E1C8,#C8A27A)}.ph-nature{background:linear-gradient(135deg,#C8F0CF,#7CC88A)}
.ph-wildlife{background:linear-gradient(135deg,#E2F0C0,#8FB35A)}.ph-lake{background:linear-gradient(135deg,#C6ECFF,#5BB4E6)}.ph-hill,.ph-adventure{background:linear-gradient(135deg,#DCE2FF,#8EA4E8)}
.ph-city{background:linear-gradient(135deg,#E6DEFF,#A99AE0)}.ph-tour{background:linear-gradient(135deg,#0C306C,#1859C3 55%,#DC143C)}
:root[data-theme="dark"] .ph{filter:saturate(.85) brightness(.9)}
.ph-flag{position:absolute;left:10px;top:10px;display:flex;gap:4px;padding:5px 7px;border-radius:10px;background:rgba(255,255,255,.88)}
.ph-unesco{position:absolute;right:10px;bottom:10px;font-size:11.5px;font-weight:800;padding:4px 9px;border-radius:999px;background:rgba(12,48,108,.86);color:#fff}
.pb{padding:14px 16px 16px;display:flex;flex-direction:column;gap:8px;flex:1}
.pb p{font-size:14.5px;color:var(--md-on-surface-variant)}
.pb h3 a{color:inherit;text-decoration:none}
.sec-t{display:flex;align-items:flex-start;gap:10px;scroll-margin-top:120px}.sec-f{display:inline-flex;gap:3px;padding-top:6px;flex:none}
.cnt{display:inline-grid;place-items:center;min-width:28px;height:24px;padding:0 8px;border-radius:999px;background:var(--md-secondary-container);color:var(--md-on-secondary-container);font-size:13px;vertical-align:4px}
.ph-band{aspect-ratio:auto;height:118px;place-items:center end;padding:0 22px}
.ph-band::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 18% 120%,rgba(255,255,255,.45) 0 30%,transparent 31%),radial-gradient(circle at 70% -30%,rgba(255,255,255,.28) 0 34%,transparent 35%);pointer-events:none}
.ph-band .ph-emo{font-size:62px;position:relative;transform:rotate(-6deg)}
html:not([data-motion="reduced"]) .place:hover .ph-band .ph-emo{transform:rotate(0) scale(1.08);transition:transform .4s cubic-bezier(.34,1.56,.64,1)}
.ph-tour.ph-band{height:132px;place-items:center end}.ph-tour.ph-band::before{opacity:.3}
.ph-days{position:absolute;left:16px;bottom:14px;display:flex;align-items:baseline;gap:6px;color:#fff}
.ph-days b{font-size:46px;line-height:1;font-weight:900;letter-spacing:-.03em;text-shadow:0 2px 10px rgba(0,0,0,.25)}.ph-days small{font-size:14px;font-weight:800;opacity:.9}
.ph-tour.ph-band .ph-emo{font-size:58px}
.ph-cat{position:absolute;left:12px;bottom:10px;font-size:12px;font-weight:900;letter-spacing:.05em;text-transform:uppercase;color:rgba(20,20,30,.72)}
.ph-unesco{bottom:auto !important;top:10px}
.region{font-size:13px !important;font-weight:600;margin-top:-4px}
.facts{display:grid;gap:8px;margin-top:2px}
.pf{font-size:13.5px !important;background:var(--md-sc-lowest);border-radius:14px;padding:9px 12px;color:var(--md-on-surface-variant)}
.pf-k{display:block;font-weight:800;font-size:12.5px;color:var(--md-on-surface);margin-bottom:2px}
.pf-d summary{list-style:none;cursor:pointer;position:relative;padding-right:24px;min-height:24px}.pf-d summary::-webkit-details-marker{display:none}
.pf-d summary::after{content:"";position:absolute;right:4px;top:5px;width:7px;height:7px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:rotate(45deg);transition:transform .2s}
.pf-d[open] summary::after{transform:rotate(225deg);top:9px}
.pf-d summary .pf-k{margin:0}
.km{font-size:13px;font-weight:700;color:var(--md-on-surface-variant)}.km a{margin-left:6px}
.credit{font-size:11px !important;opacity:.75}
/* tours */
.coach{display:flex;gap:10px;align-items:flex-start;margin-top:14px;padding:12px 14px;border-radius:18px;background:color-mix(in srgb,var(--md-sc-lowest) 70%,transparent);color:var(--md-on-surface);font-size:14.5px;font-weight:600}
p.coach{background:var(--md-sc)}
.route{font-weight:600}
.pkg-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:auto;padding-top:6px}
.price{font-size:15px;color:var(--md-primary)}
.big-price{font-size:20px;font-weight:900;margin-top:14px}
/* form */
.formcard{background:linear-gradient(160deg,var(--md-sc-low),var(--md-sc))}
.fgrid{display:grid;gap:12px;grid-template-columns:repeat(2,minmax(0,1fr))}
.fld{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:800;color:var(--md-on-surface-variant)}
.fld.full{grid-column:1/-1}
.fld input,.fld textarea{font:inherit;font-size:16px;font-weight:400;color:var(--md-on-surface);background:var(--md-sc-lowest);border:1.5px solid var(--md-outline-variant);border-radius:14px;padding:11px 13px;min-height:48px;width:100%}
.fld input:focus,.fld textarea:focus{border-color:var(--md-primary);outline:none;box-shadow:0 0 0 3px color-mix(in srgb,var(--md-primary) 25%,transparent)}
.fld [aria-invalid="true"]{border-color:var(--red)}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.f-out{margin-top:12px;font-weight:700;min-height:1px}.f-out.ok{padding:12px 14px;border-radius:16px;background:var(--ok-container);color:var(--ok)}.f-out.bad{padding:12px 14px;border-radius:16px;background:#FFDAD6;color:#93000A}
.btn[disabled]{opacity:.65;cursor:progress}
@media (max-width:380px){.fgrid{grid-template-columns:1fr}}
CSS;
    }

    /* ================================================================
     *  /sitemap-content.xml
     * ================================================================ */
    /** @return list<string> language-neutral paths of every content page */
    public static function sitemapPaths(): array
    {
        $paths = ['/india-nepal', '/offices', '/explore', '/tours'];
        foreach (self::packages() as $k) {
            $paths[] = '/tours/' . $k['slug'];
        }
        return $paths;
    }

    public static function sitemap(): never
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
           . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        $today = date('Y-m-d');
        foreach (self::sitemapPaths() as $p) {
            foreach (PublicLayout::LANGS as $lang) {
                $x .= '  <url><loc>' . htmlspecialchars(PublicLayout::url($p, $lang), ENT_XML1) . '</loc><lastmod>' . $today . '</lastmod><changefreq>weekly</changefreq><priority>'
                    . (str_starts_with($p, '/tours/') ? '0.6' : '0.7') . '</priority>';
                foreach (PublicLayout::LANGS as $l) {
                    $x .= '<xhtml:link rel="alternate" hreflang="' . $l . '" href="' . htmlspecialchars(PublicLayout::url($p, $l), ENT_XML1) . '"/>';
                }
                $x .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . htmlspecialchars(PublicLayout::url($p, 'en'), ENT_XML1) . '"/>';
                $x .= "</url>\n";
            }
        }
        $x .= '</urlset>';
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $x;
        exit;
    }
}
