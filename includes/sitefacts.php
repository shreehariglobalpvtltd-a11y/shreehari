<?php
/**
 * =====================================================================
 *  SiteFacts — ONE answer to "what does the website say about us?"
 *  (9 Oct 2026, owner: "price haru name haru update hun sath update hune
 *  system bandinu paryo").
 *
 *  Before this, the company name, the CEO, the office list, the phones and
 *  every fare summary were typed again in ~11 places (the SPA markup,
 *  CONFIG, the router, the JSON-LD, the WhatsApp FAQ, the AI prompt, the
 *  offer caption, the route pages, the refund page …) and had drifted
 *  (Surat 73593 vs 73059, "one flat fare" while pickups paid 2,000–2,200).
 *
 *  Now everything is READ, per request, from the helpers the tickets and
 *  the checkout already trust:
 *
 *      brand / head office   Settings::company()
 *      CEO                   company_ceo (+ company_ceo_ne / company_operator_ne),
 *                            phone = Settings::nepalTeam() 'ceo'
 *      offices               CounterDesk::publicOffices()
 *      Nepal office / team   Settings::nepalOfficePlace(), nepalTeam()
 *      fares                 Fare::board() — the numbers perSeatFor() charges
 *
 *      channels / mailbox    company_youtube … company_tiktok, ceo_facebook,
 *                            company_email_alt (10 Oct 2026) - shown only
 *                            when the office has typed a valid value
 *
 *  all()     the full facts, memoised per request
 *  boot()    the public subset for SHG_BOOT.facts (no secrets: no payment
 *            ids, no staff data — nothing here is not already public)
 *  tokens()  {{f:path}} => HTML-escaped value, for index.php's template pass
 *  apply()   strtr() with tokens() + strip any unknown {{f:…}} token, so a
 *            typo renders empty and never ships literally
 *
 *  Change a fare, a phone, an office or the CEO in Admin and every surface
 *  follows on its next load — no deploy, no second copy.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/fare.php';

/* The 24×7 helpline the website has always published (owner's poster, Aug
   2026) - used only while no `support_phone` row exists (Admin -> Company
   details -> Website facts). */
const SITE_SUPPORT_PHONE_DISPLAY = '+91 91734 01507';
/* The WhatsApp number customers chat with (the order bot) - the same
   default includes/waorder.php uses for `wa_order_number`. */
const SITE_SUPPORT_CHAT_DIGITS = '918735881507';

final class SiteFacts
{
    /** The WhatsApp number customers chat with (the Meta number, `wa_order_number`), as digits. */
    public static function chatDigits(): string
    {
        $d = preg_replace('/\D/', '', Settings::getString('wa_order_number', SITE_SUPPORT_CHAT_DIGITS)) ?? '';
        return $d !== '' ? $d : SITE_SUPPORT_CHAT_DIGITS;
    }

    /** @var array<string,mixed>|null */
    private static ?array $memo = null;

    /** Drop the per-request memo (tests, long-running scripts after an edit). */
    public static function flush(): void
    {
        self::$memo = null;
        if (class_exists('CounterDesk', false)) {
            CounterDesk::flush();
        }
        Boarding::flush();
    }

    /**
     * Everything the website states about the company, its people, its
     * offices and its prices.
     *
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }
        $g  = static fn(string $k, string $d = ''): string => trim(Settings::getString($k, '')) !== '' ? trim(Settings::getString($k, '')) : $d;
        $co = Settings::company();

        /* Brand */
        $short = trim((string) preg_replace('/\s+(Pvt\.?\s*Ltd\.?|Private\s+Limited|Ltd\.?)$/i', '', $co['name']));
        $brand = [
            'name'    => $co['name'],
            'legal'   => $co['legal'],
            'legalNe' => $co['legalNe'],
            'short'   => $short !== '' ? $short : $co['name'],
            'web'     => $co['web'],
            'email'   => $co['email'],
            'cin'     => $co['cin'],
            'gstin'   => $g('company_gstin'),
            'tagline' => $g('company_tagline'),
        ];
        /* Channels + the domain mailbox (10 Oct 2026, owner: "Gmail YouTube /
           Facebook sanga connect xa, mention gardeu; shreehariglobal.in ko
           email ni dekhaideu"). Every value is a Settings row the office fills
           in (Admin -> Settings -> Company); an empty or malformed one is
           simply not shown - a bare handle or an http: link never ships. */
        $brand['email2']       = self::emailOrEmpty($g('company_email_alt'));
        $brand['contactEmail'] = $brand['email2'] !== '' ? $brand['email2'] : $brand['email'];
        $brand['social']       = [];
        foreach (array_keys(self::SOCIAL) as $net) {
            $brand['social'][$net] = self::httpsUrlOrEmpty($g('company_' . $net));
        }

        /* CEO - company_ceo is the single website source; his phone is the
           Nepal team's CEO line (np_ceo_phone, else the published Director line). */
        $team = [];
        try {
            $team = Settings::nepalTeam();
        } catch (Throwable $e) {
            $team = [];
        }
        $ceoRow = null;
        foreach ($team as $p) {
            if (($p['key'] ?? '') === 'ceo') {
                $ceoRow = $p;
                break;
            }
        }
        $ceoName = $g('company_ceo', (string) ($ceoRow['name'] ?? $co['operator']));
        $ceoTel  = (string) ($ceoRow['tel'] ?? '');
        $ceo = [
            'name'    => $ceoName,
            'nameNe'  => $g('company_ceo_ne', $co['operatorNe']),
            'title'   => $g('company_ceo_title', 'Founder & CEO · Director'),
            'titleNe' => $g('company_ceo_title_ne', 'संस्थापक तथा प्रमुख कार्यकारी अधिकृत · निर्देशक'),
            'phone'   => (string) ($ceoRow['phone'] ?? ''),
            'tel'     => $ceoTel,
            'wa'      => $ceoTel,
            'country' => (string) ($ceoRow['country'] ?? ''),
            'photo'   => is_file(dirname(__DIR__) . '/assets/img/ceo.jpg') ? '/assets/img/ceo.jpg' : '',
            // 10 Oct 2026: his own profile (ceo_facebook) - the home footer used to carry it typed in
            'facebook' => self::httpsUrlOrEmpty($g('ceo_facebook')),
        ];

        /* Offices (the head office is the first row of the list) */
        $offices = [];
        try {
            $offices = CounterDesk::publicOffices();
        } catch (Throwable $e) {
            $offices = [];
        }
        $headRow = null;
        foreach ($offices as $o) {
            if ($o['kind'] === 'head') {
                $headRow = $o;
                break;
            }
        }
        $head = [
            'code'      => (string) ($headRow['code'] ?? ''),
            'city'      => (string) ($headRow['city'] ?? self::cityFromAddress($co['address'])),
            'cityNe'    => (string) ($headRow['nameNe'] ?? ''),
            'address'   => $co['address'],
            'addressNe' => $co['addressNe'],
            'phone'     => $co['phone'],
            'tel'       => CounterDesk::dialDigits($co['phone'], 'IN') !== '' ? CounterDesk::dialDigits($co['phone'], 'IN') : $co['whatsapp'],
            'wa'        => $co['whatsapp'],
            'mapUrl'    => (string) ($headRow['mapUrl'] ?? ''),
            'lat'       => $headRow['lat'] ?? null,
            'lng'       => $headRow['lng'] ?? null,
        ];

        /* Nepal side */
        $npOffice = null;
        foreach ($team as $p) {
            if (($p['key'] ?? '') === 'office') {
                $npOffice = $p;
                break;
            }
        }
        $npPhone = $g('nepal_phone', (string) ($npOffice['phone'] ?? ''));
        $nepal = [
            'company'   => $g('nepal_company'),
            'companyNe' => $g('nepal_company_ne'),
            'office'    => Settings::nepalOfficePlace(),
            'officeNe'  => $g('nepal_office_ne'),
            'phone'     => $npPhone,
            'tel'       => CounterDesk::dialDigits($npPhone, 'NP'),
            'team'      => array_values(array_map(static fn(array $p): array => [
                'key' => (string) $p['key'], 'role' => (string) $p['role'], 'roleNe' => (string) $p['roleNe'],
                'name' => (string) $p['name'], 'phone' => (string) $p['phone'], 'tel' => (string) $p['tel'],
                'country' => (string) ($p['country'] ?? ''),
            ], $team)),
        ];

        /* Support: the 24×7 helpline + the WhatsApp customers chat with */
        $supPhone = $g('support_phone', SITE_SUPPORT_PHONE_DISPLAY);
        $chat     = preg_replace('/\D/', '', $g('wa_order_number', SITE_SUPPORT_CHAT_DIGITS)) ?? '';
        $support = [
            'phone'     => $supPhone,
            'tel'       => CounterDesk::dialDigits($supPhone, 'IN'),
            'whatsapp'  => $chat !== '' ? $chat : SITE_SUPPORT_CHAT_DIGITS,
            'booking'   => Settings::bookingWhatsApp(),
        ];

        /* Fares - the board the checkout bills from */
        $board = Fare::board();
        $strip = static fn(array $rows): array => array_values(array_map(static fn(array $r): array => [
            'name' => $r['name'], 'time' => $r['time'], 'fare' => (float) $r['fare'], 'own' => (bool) $r['own'],
        ], $rows));
        $fares = [
            'go'       => $strip($board['go']),
            'back'     => $strip($board['back']),
            'range'    => $board['range'],
            'defaults' => $board['defaults'],
            'private'  => $board['private'],
            'nepal'    => $board['nepal'],
        ];

        $facts = [
            'brand'     => $brand,
            'ceo'       => $ceo,
            'head'      => $head,
            'offices'   => $offices,
            'expanding' => true,
            'nepal'     => $nepal,
            'support'   => $support,
            'npr'       => round(Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6), 4),
            'fares'     => $fares,
            'agent'     => [
                'direct' => (float) Settings::getFloat('agent_flat_direct', 200.0),
                'joint'  => (float) Settings::getFloat('agent_flat_joint', 400.0),
            ],
        ];
        $facts = ['v' => substr(md5((string) json_encode($facts)), 0, 10)] + $facts;

        return self::$memo = $facts;
    }

    /**
     * The public subset for the page boot (SHG_BOOT.facts). Everything in
     * all() is already public information; this only trims what the page
     * never reads (route ids, internal labels) to keep the payload small.
     *
     * @return array<string,mixed>
     */
    public static function boot(): array
    {
        $f = self::all();
        $f['offices'] = array_values(array_map(static function (array $o): array {
            unset($o['label']);
            return $o;
        }, $f['offices']));
        unset($f['nepal']['team']);     // the page already has it as SHG_BOOT.npTeam
        return $f;
    }

    /** "₹2,200" — money as the site prints it. */
    public static function money(float|int $amount): string
    {
        return inr((float) $amount);
    }

    /** "₹2,000–₹2,200" (or "₹2,000" when every row costs the same) for 'go' | 'back'. */
    public static function rangeText(string $side): string
    {
        $r = self::all()['fares']['range'][$side === 'back' ? 'back' : 'go'];
        return (float) $r['min'] === (float) $r['max']
            ? self::money($r['min'])
            : self::money($r['min']) . '–' . self::money($r['max']);
    }

    /**
     * {{f:path}} => HTML-escaped value. Every scalar of all() has a token by
     * its dotted path ({{f:brand.name}}, {{f:ceo.tel}} …); money paths render
     * "₹2,200"; {{f:npr}} renders "1.60"; plus a few composites:
     * {{f:fares.go.min}} / .max, {{f:fares.back.min}} / .max,
     * {{f:fares.go.range}}, {{f:fares.back.range}}, {{f:offices.count}},
     * {{f:html:offices}} (the home office list <li> rows, already escaped),
     * {{f:html:social}} / {{f:html:socialSymbols}} / {{f:html:ceoSocial}} /
     * {{f:html:emails}} (10 Oct 2026: channel icons, the CEO's profile and
     * the mailto links - '' when the office has set nothing).
     *
     * @return array<string,string>
     */
    public static function tokens(): array
    {
        $f   = self::all();
        $esc = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $out = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$out, $esc): void {
            foreach ($node as $k => $v) {
                if (is_int($k)) {
                    return;     // lists (offices, fare rows) have no scalar tokens
                }
                $path = $prefix === '' ? (string) $k : $prefix . '.' . $k;
                if (is_array($v)) {
                    $walk($v, $path);
                } elseif (is_scalar($v)) {
                    $out['{{f:' . $path . '}}'] = $esc(is_bool($v) ? ($v ? '1' : '') : $v);
                }
            }
        };
        $walk($f, '');

        $money = [
            'fares.go.min'           => $f['fares']['range']['go']['min'],
            'fares.go.max'           => $f['fares']['range']['go']['max'],
            'fares.back.min'         => $f['fares']['range']['back']['min'],
            'fares.back.max'         => $f['fares']['range']['back']['max'],
            'fares.range.go.min'     => $f['fares']['range']['go']['min'],
            'fares.range.go.max'     => $f['fares']['range']['go']['max'],
            'fares.range.back.min'   => $f['fares']['range']['back']['min'],
            'fares.range.back.max'   => $f['fares']['range']['back']['max'],
            'fares.private.single'   => $f['fares']['private']['single'],
            'fares.private.double'   => $f['fares']['private']['double'],
            'fares.defaults.toNepal' => $f['fares']['defaults']['toNepal'],
            'fares.defaults.toIndia' => $f['fares']['defaults']['toIndia'],
            'agent.direct'           => $f['agent']['direct'],
            'agent.joint'            => $f['agent']['joint'],
        ];
        foreach ($money as $path => $amount) {
            $out['{{f:' . $path . '}}'] = $esc(self::money((float) $amount));
        }
        $out['{{f:fares.go.range}}']   = $esc(self::rangeText('go'));
        $out['{{f:fares.back.range}}'] = $esc(self::rangeText('back'));
        $out['{{f:npr}}']              = $esc(number_format((float) $f['npr'], 2, '.', ''));
        $out['{{f:offices.count}}']    = (string) count($f['offices']);
        $out['{{f:html:offices}}']     = self::officeListHtml();
        /* 10 Oct 2026 (trust): the channel row, its sprite symbols, the CEO's
           profile link and the mailto list - each '' when nothing is set. */
        $out['{{f:html:social}}']        = self::socialHtml();
        $out['{{f:html:socialSymbols}}'] = self::socialSymbols();
        $out['{{f:html:ceoSocial}}']     = self::ceoSocialHtml();
        $out['{{f:html:emails}}']        = self::emailsHtml();

        return $out;
    }

    /* ------------------------------------------------------------------
     *  Channels + mailboxes (10 Oct 2026, the TRUST package)
     * ------------------------------------------------------------------ */

    /**
     * The four networks the office may link, in the order the row shows
     * them: the accessible name and a 24x24 stroke glyph drawn in the same
     * line style as the page sprite (fill none, stroke currentColor).
     *
     * @var array<string, array{0:string,1:string}>
     */
    public const SOCIAL = [
        'youtube'   => ['YouTube',   '<rect x="2.5" y="5" width="19" height="14" rx="4"/><path d="m10 9 5 3-5 3z"/>'],
        'facebook'  => ['Facebook',  '<path d="M13.5 21v-7h2.4l.4-3h-2.8V9.2c0-.9.3-1.5 1.5-1.5h1.5V5.1c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.8V11H8.2v3h2.4v7"/>'],
        'instagram' => ['Instagram', '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>'],
        'tiktok'    => ['TikTok',    '<path d="M14 4v9.5a3.5 3.5 0 1 1-3.5-3.5"/><path d="M14 4c.3 2.7 2 4.3 4.5 4.5"/>'],
    ];

    /**
     * A full https:// link with a real host, else '' - a bare handle or
     * anything with spaces / quotes is not shown.
     *
     * 14 Oct 2026 (review): the office types links the way the WhatsApp bot
     * accepts them ("facebook.com/shreehariglobal", "www.youtube.com/@shg",
     * "http://…"). Such a value used to vanish silently; now a bare
     * host/path gets https:// in front and http:// becomes https:// (every
     * network listed serves https), then the same checks apply.
     */
    public static function httpsUrlOrEmpty(string $raw): string
    {
        $u = trim($raw);
        if ($u === '' || strlen($u) > 300 || preg_match('/[\s<>"\'\\\\]/', $u) === 1) {
            return '';
        }
        if (preg_match('#^http://#i', $u) === 1) {
            $u = 'https://' . substr($u, 7);
        } elseif (preg_match('#^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}(?:[/?\#]|$)#i', $u) === 1) {
            $u = 'https://' . $u;   // a bare host (no scheme, no colon) - "javascript:" or "@handle" never match
        }
        if (!preg_match('#^https://#i', $u) || filter_var($u, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $host = strtolower((string) parse_url($u, PHP_URL_HOST));
        return (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) === 1 && !str_contains($host, '..')) ? $u : '';
    }

    /** A well-formed mailbox, else ''. */
    public static function emailOrEmpty(string $raw): string
    {
        $e = trim($raw);
        return ($e !== '' && strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false) ? $e : '';
    }

    /**
     * The channel row: one 44 px link per network the office has set, the
     * network as its accessible name, opened in a new tab. '' when nothing
     * is set, so a footer shows no dead icons.
     *
     * $inline = true draws the glyph inside the link (a page without the
     * sprite: the content pages, help); false uses <use href="#i-…"> from the
     * page sprite ({{f:html:socialSymbols}} in app.template.html).
     *
     * @param array<string,string>|null $urls network => url (default: the live facts)
     */
    public static function socialHtml(bool $inline = false, ?array $urls = null): string
    {
        $urls = $urls ?? (array) (self::all()['brand']['social'] ?? []);
        $esc  = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $out  = '';
        foreach (self::SOCIAL as $net => [$label, $glyph]) {
            $url = self::httpsUrlOrEmpty((string) ($urls[$net] ?? ''));
            if ($url === '') {
                continue;
            }
            $svg = $inline
                ? '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $glyph . '</svg>'
                : '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-' . $net . '"/></svg>';
            $out .= '<a class="soc soc-' . $net . '" href="' . $esc($url) . '" target="_blank" rel="noopener noreferrer me"'
                  . ' aria-label="' . $esc($label) . '" title="' . $esc($label) . '">' . $svg . '</a>';
        }
        return $out;
    }

    /** The four <symbol id="i-youtube"> … definitions for a page sprite. */
    public static function socialSymbols(): string
    {
        $out = '';
        foreach (self::SOCIAL as $net => [, $glyph]) {
            $out .= '<symbol id="i-' . $net . '" viewBox="0 0 24 24">' . $glyph . '</symbol>';
        }
        return $out;
    }

    /** The Director's own Facebook profile as a sprite icon link (home footer); '' when ceo_facebook is blank. */
    public static function ceoSocialHtml(): string
    {
        $f   = self::all();
        $url = (string) ($f['ceo']['facebook'] ?? '');
        if ($url === '') {
            return '';
        }
        $esc   = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $label = 'Facebook — ' . (string) $f['ceo']['name'];
        return '<a class="soc soc-facebook soc-ceo" href="' . $esc($url) . '" target="_blank" rel="noopener noreferrer"'
             . ' aria-label="' . $esc($label) . '" title="' . $esc($label) . '">'
             . '<svg class="ic" aria-hidden="true" focusable="false"><use href="#i-facebook"/></svg></a>';
    }

    /**
     * The mailboxes as mailto links: the domain address first when set, then
     * the Gmail - never the same one twice. The first carries data-fact-mail /
     * data-fact so 02-config.js hydrateFacts() refreshes it like a phone.
     */
    public static function emailsHtml(): string
    {
        $b    = self::all()['brand'];
        $esc  = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $list = array_values(array_unique(array_filter([(string) $b['email2'], (string) $b['email']], 'strlen')));
        $out  = [];
        foreach ($list as $i => $em) {
            $out[] = '<a href="mailto:' . $esc($em) . '"' . ($i === 0 ? ' data-fact-mail="brand.contactEmail" data-fact="brand.contactEmail"' : '') . '>' . $esc($em) . '</a>';
        }
        return implode(' · ', $out);
    }

    /**
     * Fill every {{f:…}} token in $html; an unknown token renders empty so a
     * typo can never ship "{{f:ceo.nmae}}" to a customer.
     */
    public static function apply(string $html): string
    {
        if (!str_contains($html, '{{f:')) {
            return $html;
        }
        $html = strtr($html, self::tokens());
        return (string) (preg_replace('/\{\{f:[A-Za-z0-9_.:\-]{1,80}\}\}/', '', $html) ?? $html);
    }

    /**
     * The home office list as <li> rows — the same markup 05-router.js
     * renderOffices() draws, so the first paint and the live render agree
     * (#saOffices). Nepali labels at first paint (the site's default
     * language); the router re-localises on language change.
     */
    public static function officeListHtml(): string
    {
        /* Word for word the Nepali set of 05-router.js OFFICE_WORDS, so the
           live render after boot swaps nothing on a Nepali screen. */
        $esc  = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $rows = '';
        foreach (self::all()['offices'] as $o) {
            $np   = $o['country'] === 'NP';
            $head = $o['kind'] === 'head';
            $soon = $o['status'] !== 'open';
            $name = $o['nameNe'] !== '' ? $o['nameNe'] : $o['name'];
            $place = trim(explode(',', (string) $o['address'])[0]);
            $sub  = $soon ? 'छिट्टै खुल्दै'
                  : ($head ? 'प्रधान कार्यालय'
                  : ($o['kind'] === 'partner' || $np ? 'नेपाल कार्यालय' . ($place !== '' ? ' · ' . $place : '')
                  : ($o['kind'] === 'border' ? 'सीमा कार्यालय' : 'शाखा कार्यालय')));
            $cls  = 'th-o ' . ($np ? 'th-np' : 'th-in') . ($head ? ' th-head' : '') . ($soon ? ' th-soon' : '');
            $badge = $np ? '<span class="flg" data-flag="NP"></span>' : $esc(mb_strtoupper(mb_substr($o['name'], 0, 1)));
            $inner = '<span class="th-badge">' . $badge . '</span>'
                   . '<span class="th-l">' . $esc($name) . '</span>'
                   . '<span class="th-sub">' . ($head ? '<b class="th-tagk">HEAD</b>' : '') . $esc($sub) . '</span>'
                   . ($o['phone'] !== '' ? '<span class="th-n">' . $esc($o['phone']) . '</span><span class="th-call" aria-hidden="true">📞</span>' : '');
            $rows .= '<li class="' . $cls . '" data-office="' . $esc($o['code']) . '">'
                   . ($o['tel'] !== ''
                        ? '<a href="tel:+' . $esc($o['tel']) . '" aria-label="' . $esc('फोन गर्नुहोस् ' . $name . ' ' . $o['phone']) . '">' . $inner . '</a>'
                        : '<span class="th-a">' . $inner . '</span>')
                   . '</li>';
        }
        if (self::all()['expanding']) {
            $rows .= '<li class="th-o th-more"><a href="/ne/offices"><span class="th-badge">+</span>'
                   . '<span class="th-l">नयाँ कार्यालय छिट्टै</span><span class="th-sub">नेटवर्क विस्तार हुँदैछ · Network expanding</span>'
                   . '<span class="th-call" aria-hidden="true">→</span></a></li>';
        }
        return $rows;
    }

    /** "…, Mehsana – 384002, Gujarat, India" → "Mehsana". */
    private static function cityFromAddress(string $address): string
    {
        foreach (array_map('trim', explode(',', $address)) as $part) {
            if (preg_match('/^(.*?)\s*[\x{2013}\x{2014}-]?\s*\d{6}\b/u', $part, $m) === 1 && trim($m[1]) !== '') {
                return trim($m[1]);    // the part that carries the PIN code names the town
            }
        }
        return '';
    }
}
