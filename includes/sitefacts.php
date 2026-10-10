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
     * {{f:html:offices}} (the home office list <li> rows, already escaped).
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

        return $out;
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
