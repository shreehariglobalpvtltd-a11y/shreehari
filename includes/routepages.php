<?php
/**
 * =====================================================================
 *  RoutePages — server-rendered, three-language pages for every active
 *  route (24 Sep 2026).
 *
 *      /bus                      the index
 *      /bus/surat-to-rupaidiha-bus        English
 *      /hi/bus/surat-to-rupaidiha-bus     Hindi
 *      /ne/bus/surat-to-rupaidiha-bus     Nepali
 *      /sitemap-routes.xml       every page above, with hreflang links
 *
 *  Why: the whole site was one JavaScript URL, so "Surat to Nepal bus" /
 *  "सूरत से नेपाल बस" / "सुरत देखि नेपाल बस" on Google showed the
 *  aggregators and never us. A crawler reads these pages without running
 *  the app. Everything on them comes from the live tables — routes,
 *  route_stops, the fare settings, the refund ladder — never typed twice.
 *  Light (no app bundle), cached for an hour, Trip + LocalBusiness + FAQ
 *  JSON-LD, canonical + hreflang, and three real calls to action.
 * ===================================================================== */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

require_once INCLUDE_PATH . '/fare.php';

final class RoutePages
{
    public const LANGS = ['en', 'hi', 'ne'];
    private const NEPAL_SIDE = ['rupaidiha', 'nepalgunj', 'kohalpur', 'nepal'];

    /** @return array<string, array<string,mixed>> slug → route row */
    public static function routes(): array
    {
        $out = [];
        foreach (Database::fetchAll("SELECT * FROM routes WHERE is_active = 1 ORDER BY id") as $r) {
            $out[self::slugFor($r)] = $r;
        }
        return $out;
    }

    public static function slugFor(array $r): string
    {
        $s = strtolower(trim((string) $r['from_city']) . '-to-' . trim((string) $r['to_city']) . '-bus');
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    }

    public static function url(string $slug, string $lang): string
    {
        $base = rtrim(APP_URL, '/');
        return $lang === 'en' ? $base . '/bus/' . $slug : $base . '/' . $lang . '/bus/' . $slug;
    }

    /** Dispatch from index.php: true when a page was served (it exits). */
    public static function dispatch(string $path): void
    {
        if ($path === '/bus' || $path === '/hi/bus' || $path === '/ne/bus') {
            self::renderIndex(str_starts_with($path, '/hi') ? 'hi' : (str_starts_with($path, '/ne') ? 'ne' : 'en'));
        }
        if ($path === '/sitemap-routes.xml') {
            self::sitemapRoutes();
        }
        if ($path === '/sitemap-pages.xml') {
            self::sitemapPages();
        }
        if (preg_match('#^/(?:(hi|ne)/)?bus/([a-z0-9-]{3,80})$#', $path, $m)) {
            self::render($m[2], $m[1] !== '' ? $m[1] : 'en');
        }
    }

    /* ----------------------------------------------------------------
     *  Facts
     * ---------------------------------------------------------------- */

    /** @return array<string,mixed> everything a page needs, from the tables */
    public static function facts(array $r): array
    {
        $rid = (int) $r['id'];
        $stops = Database::fetchAll(
            'SELECT stop_type, stop_name, landmark, stop_time, latitude, longitude FROM route_stops WHERE route_id = :r' . Boarding::activeSql() . ' ORDER BY stop_type DESC, sort_order, id',
            ['r' => $rid]
        );
        $boarding = array_values(array_filter($stops, static fn(array $s): bool => $s['stop_type'] === 'boarding'));
        usort($boarding, static fn(array $a, array $b): int => strcmp((string) $a['stop_time'], (string) $b['stop_time']));
        $drop = array_values(array_filter($stops, static fn(array $s): bool => $s['stop_type'] === 'drop'));

        $toNepal = in_array(strtolower(trim((string) $r['to_city'])), self::NEPAL_SIDE, true);
        $dir     = Fare::dirFares();
        $sharing = (float) ($toNepal ? $dir['toNepal'] : $dir['toIndia']);
        // The route's own origin / destination pickup fare (Admin -> Routes,
        // 1 Oct 2026) when the office set one — what that passenger pays.
        $own = Fare::stopFare($rid, (string) $r['from_city'], (string) $r['to_city']);
        if ($own !== null) {
            $sharing = $own;
        }
        $cp      = Settings::getArray('cabin_pricing', []);
        $single  = (float) ($cp['private']['single_1pax']['online'] ?? $cp['private']['single_1pax']['offline'] ?? 0);
        $double  = (float) ($cp['private']['double_2pax']['online'] ?? $cp['private']['double_2pax']['offline'] ?? 0);
        $slabs   = Settings::getArray('refund_slabs', [['minHrs' => 96, 'pct' => 90], ['minHrs' => 48, 'pct' => 75], ['minHrs' => 24, 'pct' => 50], ['minHrs' => 6, 'pct' => 25], ['minHrs' => 0, 'pct' => 0]]);
        usort($slabs, static fn(array $a, array $b): int => (int) $b['minHrs'] <=> (int) $a['minHrs']);
        $amen = json_decode((string) ($r['amenities'] ?? '[]'), true);

        return [
            'route'     => $r,
            'toNepal'   => $toNepal,
            'boarding'  => $boarding,
            'drop'      => $drop,
            'dep'       => substr((string) $r['dep_time'], 0, 5),
            'arr'       => substr((string) ($r['arr_time'] ?? ''), 0, 5),
            'dayOffset' => (int) ($r['day_offset'] ?? 0),
            'km'        => (int) ($r['distance_km'] ?? 0),
            'sharing'   => $sharing,
            'single'    => $single,
            'double'    => $double,
            'slabs'     => $slabs,
            'amenities' => is_array($amen) ? array_map('strval', $amen) : [],
            'phone'     => Settings::officePhone(),
            'wa'        => Settings::officeWhatsApp(),
            'company'   => Settings::getString('company_name', APP_NAME),
            'address'   => Settings::getString('company_address', ''),
            'cin'       => Settings::getString('company_cin', ''),
        ];
    }

    /* ----------------------------------------------------------------
     *  Town names in Devanagari, so the Hindi page says "सूरत से नेपाल बस"
     *  and the Nepali page "सुरत देखि" — the words people actually search.
     *  A town not listed keeps its Latin spelling.
     * ---------------------------------------------------------------- */
    private const TOWNS = [
        'surat'      => ['hi' => 'सूरत',       'ne' => 'सुरत'],
        'rupaidiha'  => ['hi' => 'रूपईडीहा',   'ne' => 'रुपैडिहा'],
        'nepalgunj'  => ['hi' => 'नेपालगंज',    'ne' => 'नेपालगञ्ज'],
        'mehsana'    => ['hi' => 'मेहसाणा',     'ne' => 'मेहसाणा'],
        'ahmedabad'  => ['hi' => 'अहमदाबाद',    'ne' => 'अहमदाबाद'],
        'vadodara'   => ['hi' => 'वडोदरा',      'ne' => 'वडोदरा'],
        'barauda'    => ['hi' => 'बड़ौदा',       'ne' => 'बरौदा'],
        'kamrej'     => ['hi' => 'कामरेज',      'ne' => 'कामरेज'],
        'ankleshwar' => ['hi' => 'अंकलेश्वर',    'ne' => 'अंकलेश्वर'],
        'bharuch'    => ['hi' => 'भरूच',        'ne' => 'भरुच'],
        'anand'      => ['hi' => 'आणंद',        'ne' => 'आणन्द'],
        'nadiad'     => ['hi' => 'नडियाद',      'ne' => 'नडियाद'],
        'kohalpur'   => ['hi' => 'कोहलपुर',     'ne' => 'कोहलपुर'],
    ];

    public static function town(string $name, string $lang): string
    {
        $name = trim($name);
        if ($lang === 'en') {
            return $name;
        }
        return self::TOWNS[strtolower($name)][$lang] ?? $name;
    }

    /* ----------------------------------------------------------------
     *  Words (three languages, one place)
     * ---------------------------------------------------------------- */

    /** @return array<string,string> */
    /**
     * The homepage route scene, redrawn for one route page (4 Oct 2026): the
     * same corridor, the same coordinates, with THIS page's Gujarat town lit
     * and the dots running the way this bus runs. Inline SVG + a few lines of
     * inline JS (no new request; the 9 KB map backdrop loads only when the
     * card scrolls into view). transform/opacity only, paused off-screen and
     * by its button, a still frame under reduced motion. Illustrative - says so.
     */
    private static function corridor(string $fromCity, string $toCity, string $lang): string
    {
        $mw = [
            'en' => ['t' => 'The route', 'note' => 'Illustrative corridor — not a road map, distance or live GPS position.', 'aria' => 'Illustrative map: the bus corridor from Gujarat to Rupaidiha on the India–Nepal border', 'pause' => 'Pause', 'play' => 'Play',
                     'surat' => 'Surat', 'vadodara' => 'Vadodara', 'ahmedabad' => 'Ahmedabad', 'rupaidiha' => 'Rupaidiha', 'in' => 'INDIA', 'np' => 'NEPAL'],
            'hi' => ['t' => 'रूट', 'note' => 'सांकेतिक रूट — सड़क का नक्शा, दूरी या लाइव GPS नहीं।', 'aria' => 'सांकेतिक नक्शा: गुजरात से भारत–नेपाल सीमा पर रुपईडीहा तक बस का रूट', 'pause' => 'रोकें', 'play' => 'चलाएँ',
                     'surat' => 'सूरत', 'vadodara' => 'वडोदरा', 'ahmedabad' => 'अहमदाबाद', 'rupaidiha' => 'रुपईडीहा', 'in' => 'भारत', 'np' => 'नेपाल'],
            'ne' => ['t' => 'रुट', 'note' => 'सांकेतिक रुट — सडकको नक्सा, दूरी वा लाइभ GPS होइन।', 'aria' => 'सांकेतिक नक्सा: गुजरातदेखि भारत–नेपाल सिमानाको रुपैडिहासम्म बसको रुट', 'pause' => 'रोक्नुहोस्', 'play' => 'चलाउनुहोस्',
                     'surat' => 'सुरत', 'vadodara' => 'वडोदरा', 'ahmedabad' => 'अहमदाबाद', 'rupaidiha' => 'रुपैडिहा', 'in' => 'भारत', 'np' => 'नेपाल'],
        ];
        $m = $mw[$lang] ?? $mw['en'];
        $e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $pts = ['surat' => [229.61, 374.43], 'vadodara' => [238.71, 350.55], 'ahmedabad' => [222.86, 335.53], 'rupaidiha' => [457.99, 228.12]];
        $key = static function (string $city): string {
            $c = strtolower($city);
            return match (true) {
                str_contains($c, 'surat')                                  => 'surat',
                str_contains($c, 'vadodara') || str_contains($c, 'baroda') => 'vadodara',
                str_contains($c, 'ahmedabad')                              => 'ahmedabad',
                str_contains($c, 'rupaidiha') || str_contains($c, 'nepalgunj') => 'rupaidiha',
                default                                                    => '',
            };
        };
        $kFrom = $key($fromCity);
        $kTo   = $key($toCity);
        $toIndia = $kFrom === 'rupaidiha';
        $lit = $toIndia ? $kTo : $kFrom;   // the Gujarat end of this route

        // Dots along the corridor, in the order this bus runs.
        $sample = [];
        $poly = [[229.61, 374.43], [238.71, 350.55], [222.86, 335.53]];
        foreach ([[0, 1], [1, 2]] as [$a, $b]) {
            for ($i = 0; $i < 2; $i++) {
                $t = $i / 2;
                $sample[] = [$poly[$a][0] + ($poly[$b][0] - $poly[$a][0]) * $t, $poly[$a][1] + ($poly[$b][1] - $poly[$a][1]) * $t];
            }
        }
        $p0 = [222.86, 335.53]; $p1 = [277.86, 260.53]; $p2 = [377.99, 238.12]; $p3 = [457.99, 228.12];
        for ($i = 0; $i <= 9; $i++) {
            $t = $i / 9; $u = 1 - $t;
            $sample[] = [
                $u * $u * $u * $p0[0] + 3 * $u * $u * $t * $p1[0] + 3 * $u * $t * $t * $p2[0] + $t * $t * $t * $p3[0],
                $u * $u * $u * $p0[1] + 3 * $u * $u * $t * $p1[1] + 3 * $u * $t * $t * $p2[1] + $t * $t * $t * $p3[1],
            ];
        }
        if ($toIndia) { $sample = array_reverse($sample); }
        $n = count($sample);
        $dots = '';
        foreach ($sample as $i => [$x, $y]) {
            $dots .= sprintf('<circle class="c-dot" cx="%.1f" cy="%.1f" r="3.4" style="animation-delay:%.2fs"/>', $x, $y, $i * 2.4 / $n);
        }

        $stops = '';
        foreach ($pts as $k => [$x, $y]) {
            $on = $k === $lit || $k === 'rupaidiha';
            $stops .= sprintf('<circle class="c-stop%s" cx="%.2f" cy="%.2f" r="7"/>', $on ? ' on' : '', $x, $y);
        }
        $lbl = static fn(string $k, float $x, float $y, string $anchor): string => sprintf(
            '<text class="c-lbl%s" x="%.1f" y="%.1f" text-anchor="%s">%s</text>', $k === $lit ? ' on' : '', $x, $y, $anchor, $e($m[$k]));
        $pulse = '';
        foreach (array_unique(array_filter([$lit, 'rupaidiha'])) as $k) {
            $pulse .= sprintf('<circle class="c-pulse" cx="%.2f" cy="%.2f" r="14"/>', $pts[$k][0], $pts[$k][1]);
        }
        $path = 'M229.61,374.43 L238.71,350.55 L222.86,335.53 C277.86,260.53 377.99,238.12 457.99,228.12';

        return '<section class="card corridor" data-corr><h2>' . $e($m['t']) . '</h2>'
            . '<div class="corr-art"><svg viewBox="36 145 590 315" role="img" aria-label="' . $e($m['aria']) . '">'
            . '<image data-href="/assets/img/anim/shg-map-bg.svg?v=20261004e" x="0" y="0" width="960" height="720"/>'
            . '<path class="c-glow" d="' . $path . '"/><path class="c-track" d="' . $path . '"/>'
            . '<g aria-hidden="true">' . $dots . $pulse . $stops
            . '<text class="c-cty" x="340" y="445">' . $e($m['in']) . '</text><text class="c-cty" x="495" y="176">' . $e($m['np']) . '</text>'
            . $lbl('surat', 215.6, 402.4, 'end') . $lbl('vadodara', 254, 357, 'start') . $lbl('ahmedabad', 208.9, 321.5, 'end') . $lbl('rupaidiha', 458, 272, 'middle')
            . '</g></svg>'
            . '<button type="button" class="corr-pause" aria-pressed="false" data-p="' . $e($m['pause']) . '" data-g="' . $e($m['play']) . '">' . $e($m['pause']) . '</button></div>'
            . '<p class="note">' . $e($m['note']) . '</p>'
            . '<script>(function(){var s=document.querySelector("[data-corr]");if(!s)return;var b=s.querySelector(".corr-pause"),im=s.querySelector("image[data-href]");'
            . 'b.addEventListener("click",function(){var p=s.classList.toggle("paused");b.setAttribute("aria-pressed",p?"true":"false");b.textContent=p?b.dataset.g:b.dataset.p;});'
            . 'var go=function(on){s.classList.toggle("off",!on);if(on&&im&&!im.getAttribute("href")){im.setAttribute("href",im.getAttribute("data-href"));}};'
            . 'if("IntersectionObserver" in window){new IntersectionObserver(function(es){go(es[0].isIntersecting);},{rootMargin:"200px"}).observe(s);}else{go(true);}'
            . 'document.addEventListener("visibilitychange",function(){s.classList.toggle("off",document.hidden);});})();</script>'
            . '</section>';
    }

    private static function words(string $lang): array
    {
        $w = [
            'en' => [
                'title'   => '%s to %s Bus — AC Sleeper | %s',
                'h1'      => '%s to %s bus',
                'lead'    => 'Direct AC sleeper coach, every day. Departs %s at %s, reaches %s at %s (+%d day). %d km on one ticket — no change of bus at the border.',
                'timetable' => 'Timetable', 'dep' => 'Departure', 'arr' => 'Arrival', 'daily' => 'Every day', 'distance' => 'Distance',
                'boarding' => 'Boarding points', 'drop' => 'Drop points', 'time' => 'Time', 'stop' => 'Stop',
                'fare'    => 'Fare', 'sharing' => 'Sharing sleeper, per person', 'single' => 'Private single cabin', 'double' => 'Private double cabin (2 people)',
                'fareNote' => 'Same fare online and at the counter. Pay by UPI, eSewa QR, or cash on the bus.',
                'refund'  => 'Cancellation & refund', 'refundRow' => 'Cancel %d h or more before departure → %d%% back', 'refundLast' => 'Less than %d h before departure → no refund',
                'refundPromise' => 'If the bus is cancelled by us, the full amount is returned.',
                'amen'    => 'On board', 'book' => 'Book a seat online', 'wa' => 'Book on WhatsApp', 'call' => 'Call the office',
                'faq'     => 'Questions people ask', 'other' => 'Other routes', 'trust' => 'A registered Indian company · CIN %s · %s',
                'q1' => 'Which documents do I need at the Rupaidiha (India–Nepal) border?', 'a1' => 'Indian citizens: voter ID, passport or Aadhaar with a photo. Nepali citizens: citizenship card or passport. Children travel with a school ID or birth certificate. Carry the photo ID whose name is on the ticket.',
                'q2' => 'Where exactly do I board in %s?', 'a2' => 'At the boarding point on your ticket, 20 minutes before the printed time. Every ticket carries a "Where is my bus?" link that shows the coach live and the minutes to your stop.',
                'q3' => 'Can I pay from Nepal?', 'a3' => 'Yes — an eSewa QR is on the payment page, or a friend in India can pay by UPI, or pay cash to the crew on boarding. The seat is confirmed once the office sees the payment.',
                'q4' => 'Is the bus safe for women travelling alone?', 'a4' => 'Sharing cabins are gender-locked: a berth beside a woman is sold only to a woman. Berths reserved for women are marked on the seat map, and the office phone is answered until 11 pm.',
                'q5' => 'Can I change the name or the date?', 'a5' => 'The name on a ticket can be corrected twice before departure from the app or on WhatsApp. A date change is a reschedule — ask the office; the refund ladder above applies to a cancellation.',
                'q6' => 'How much luggage can I bring?', 'a6' => 'Two bags per passenger in the hold plus one hand bag. Tell the office in advance about anything larger.',
            ],
            'hi' => [
                'title'   => '%s से %s बस — AC स्लीपर | %s',
                'h1'      => '%s से %s बस',
                'lead'    => 'सीधी AC स्लीपर बस, हर दिन। %s से %s बजे रवाना, %s पर %s बजे (+%d दिन) पहुँच। एक ही टिकट पर %d किमी — बॉर्डर पर बस बदलनी नहीं पड़ती।',
                'timetable' => 'समय सारणी', 'dep' => 'रवानगी', 'arr' => 'पहुँच', 'daily' => 'रोज़', 'distance' => 'दूरी',
                'boarding' => 'बोर्डिंग पॉइंट', 'drop' => 'उतरने की जगह', 'time' => 'समय', 'stop' => 'स्टॉप',
                'fare'    => 'किराया', 'sharing' => 'शेयरिंग स्लीपर, प्रति व्यक्ति', 'single' => 'प्राइवेट सिंगल केबिन', 'double' => 'प्राइवेट डबल केबिन (2 लोग)',
                'fareNote' => 'ऑनलाइन और काउंटर पर एक ही किराया। UPI, eSewa QR या बस में नकद।',
                'refund'  => 'रद्द करना और रिफंड', 'refundRow' => 'रवानगी से %d घंटे या पहले रद्द → %d%% वापस', 'refundLast' => 'रवानगी से %d घंटे से कम → रिफंड नहीं',
                'refundPromise' => 'बस हमारी ओर से रद्द हो तो पूरा पैसा वापस।',
                'amen'    => 'बस में', 'book' => 'ऑनलाइन सीट बुक करें', 'wa' => 'WhatsApp पर बुक करें', 'call' => 'ऑफ़िस को कॉल करें',
                'faq'     => 'लोग क्या पूछते हैं', 'other' => 'दूसरे रूट', 'trust' => 'रजिस्टर्ड भारतीय कंपनी · CIN %s · %s',
                'q1' => 'रूपईडीहा (भारत–नेपाल) बॉर्डर पर कौन से कागज़ चाहिए?', 'a1' => 'भारतीय नागरिक: वोटर ID, पासपोर्ट या फोटो वाला आधार। नेपाली नागरिक: नागरिकता या पासपोर्ट। बच्चों के लिए स्कूल ID या जन्म प्रमाण। टिकट वाले नाम की फोटो ID साथ रखें।',
                'q2' => '%s में ठीक कहाँ चढ़ना है?', 'a2' => 'टिकट पर लिखे बोर्डिंग पॉइंट पर, समय से 20 मिनट पहले। हर टिकट में "मेरी बस कहाँ है?" लिंक होता है जो बस को लाइव और आपके स्टॉप तक के मिनट दिखाता है।',
                'q3' => 'क्या नेपाल से पैसे दे सकते हैं?', 'a3' => 'हाँ — पेमेंट पेज पर eSewa QR है, या भारत में कोई मित्र UPI से दे सकता है, या बस में चढ़ते समय नकद। ऑफ़िस को पेमेंट दिखते ही सीट पक्की।',
                'q4' => 'अकेली महिला के लिए बस सुरक्षित है?', 'a4' => 'शेयरिंग केबिन जेंडर-लॉक हैं: महिला के बगल की बर्थ सिर्फ महिला को बिकती है। महिलाओं के लिए आरक्षित बर्थ सीट मैप पर दिखती हैं, और ऑफ़िस का फ़ोन रात 11 बजे तक उठता है।',
                'q5' => 'नाम या तारीख बदल सकते हैं?', 'a5' => 'टिकट का नाम रवानगी से पहले दो बार ऐप या WhatsApp से सुधारा जा सकता है। तारीख बदलना रीशेड्यूल है — ऑफ़िस से पूछें; रद्द करने पर ऊपर की रिफंड सीढ़ी लागू।',
                'q6' => 'कितना सामान ले जा सकते हैं?', 'a6' => 'प्रति यात्री दो बैग डिक्की में और एक हाथ में। बड़े सामान के लिए पहले ऑफ़िस को बताएँ।',
            ],
            'ne' => [
                'title'   => '%s देखि %s बस — AC स्लिपर | %s',
                'h1'      => '%s देखि %s बस',
                'lead'    => 'सिधा AC स्लिपर बस, हरेक दिन। %s बाट %s बजे छुट्छ, %s मा %s बजे (+%d दिन) पुग्छ। एउटै टिकटमा %d किमी — बोर्डरमा बस फेर्नु पर्दैन।',
                'timetable' => 'समय तालिका', 'dep' => 'छुट्ने', 'arr' => 'पुग्ने', 'daily' => 'हरेक दिन', 'distance' => 'दूरी',
                'boarding' => 'चढ्ने ठाउँ', 'drop' => 'ओर्लने ठाउँ', 'time' => 'समय', 'stop' => 'स्टप',
                'fare'    => 'भाडा', 'sharing' => 'सेयरिङ स्लिपर, प्रति व्यक्ति', 'single' => 'प्राइभेट सिंगल केबिन', 'double' => 'प्राइभेट डबल केबिन (२ जना)',
                'fareNote' => 'अनलाइन र काउन्टरमा एउटै भाडा। UPI, eSewa QR वा बसमै नगद।',
                'refund'  => 'रद्द र फिर्ता', 'refundRow' => 'छुट्नु %d घण्टा वा अघि रद्द → %d%% फिर्ता', 'refundLast' => 'छुट्नु %d घण्टाभन्दा कम → फिर्ता हुँदैन',
                'refundPromise' => 'बस हाम्रो तर्फबाट रद्द भए पूरै रकम फिर्ता।',
                'amen'    => 'बसमा', 'book' => 'अनलाइन सिट बुक गर्नुहोस्', 'wa' => 'WhatsApp मा बुक गर्नुहोस्', 'call' => 'कार्यालयलाई फोन',
                'faq'     => 'मानिसहरूले सोध्ने प्रश्न', 'other' => 'अरू रुट', 'trust' => 'दर्ता भएको भारतीय कम्पनी · CIN %s · %s',
                'q1' => 'रुपैडिहा (भारत–नेपाल) बोर्डरमा के कागज चाहिन्छ?', 'a1' => 'भारतीय नागरिक: भोटर ID, राहदानी वा फोटो भएको आधार। नेपाली नागरिक: नागरिकता वा राहदानी। बालबालिकाका लागि स्कुल ID वा जन्मदर्ता। टिकटमा भएकै नामको फोटो ID साथमा राख्नुहोस्।',
                'q2' => '%s मा ठ्याक्कै कहाँ चढ्ने?', 'a2' => 'टिकटमा लेखिएको चढ्ने ठाउँमा, समयभन्दा २० मिनेट अघि। हरेक टिकटमा "मेरो बस कहाँ छ?" लिङ्क हुन्छ जसले बस लाइभ र तपाईंको स्टपसम्मको मिनेट देखाउँछ।',
                'q3' => 'नेपालबाट पैसा तिर्न मिल्छ?', 'a3' => 'मिल्छ — भुक्तानी पेजमा eSewa QR छ, वा भारतमा साथीले UPI बाट तिर्न सक्छ, वा बस चढ्दा नगद। कार्यालयले भुक्तानी देखेपछि सिट पक्का।',
                'q4' => 'एक्लै यात्रा गर्ने महिलाका लागि बस सुरक्षित छ?', 'a4' => 'सेयरिङ केबिन जेन्डर-लक छन्: महिलाको छेउको बर्थ महिलालाई मात्र बेचिन्छ। महिलाका लागि छुट्याइएका बर्थ सिट म्यापमा देखिन्छन्, र कार्यालयको फोन राति ११ बजेसम्म उठ्छ।',
                'q5' => 'नाम वा मिति बदल्न मिल्छ?', 'a5' => 'टिकटको नाम छुट्नुअघि दुई पटकसम्म एप वा WhatsApp बाट सच्याउन मिल्छ। मिति बदल्नु रिसेड्युल हो — कार्यालयलाई सोध्नुहोस्; रद्द गर्दा माथिको फिर्ता तालिका लागू हुन्छ।',
                'q6' => 'कति सामान लैजान मिल्छ?', 'a6' => 'प्रति यात्री दुई झोला डिक्कीमा र एउटा हातमा। ठूलो सामानका लागि पहिले कार्यालयलाई भन्नुहोस्।',
            ],
        ];
        return $w[$lang] ?? $w['en'];
    }

    /* ----------------------------------------------------------------
     *  Pages
     * ---------------------------------------------------------------- */

    public static function render(string $slug, string $lang): never
    {
        $routes = self::routes();
        if (!isset($routes[$slug]) || !in_array($lang, self::LANGS, true)) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><meta charset="utf-8"><title>Not found</title><p>No such route. <a href="/bus">All routes</a></p>';
            exit;
        }
        $f = self::facts($routes[$slug]);
        $w = self::words($lang);
        $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $r = $f['route'];
        $from = self::town((string) $r['from_city'], $lang); $to = self::town((string) $r['to_city'], $lang);
        $title = sprintf($w['title'], $from, $to, $f['company']);
        $h1    = sprintf($w['h1'], $from, $to);
        $lead  = sprintf($w['lead'], $from, $f['dep'], $to, $f['arr'], $f['dayOffset'], $f['km']);
        $canon = self::url($slug, $lang);
        $alts  = '';
        foreach (self::LANGS as $l) {
            $alts .= '<link rel="alternate" hreflang="' . $l . '" href="' . $e(self::url($slug, $l)) . '">';
        }
        $alts .= '<link rel="alternate" hreflang="x-default" href="' . $e(self::url($slug, 'en')) . '">';
        $desc = mb_substr($lead, 0, 155);

        $boardRows = '';
        foreach ($f['boarding'] as $s) {
            $boardRows .= '<tr><td>' . $e((string) $s['stop_name']) . ($s['landmark'] !== '' && $s['landmark'] !== null ? '<br><small>' . $e((string) $s['landmark']) . '</small>' : '') . '</td><td>' . $e(substr((string) $s['stop_time'], 0, 5)) . '</td></tr>';
        }
        $dropList = implode(' · ', array_map(static fn(array $s): string => $e((string) $s['stop_name']), $f['drop']));
        $slabRows = '';
        foreach ($f['slabs'] as $sl) {
            $slabRows .= '<li>' . ((int) $sl['minHrs'] > 0 ? sprintf($e($w['refundRow']), (int) $sl['minHrs'], (int) $sl['pct']) : '') . '</li>';
        }
        $last = end($f['slabs']);
        $lastHrs = 0;
        foreach ($f['slabs'] as $sl) { if ((int) $sl['minHrs'] > 0) { $lastHrs = (int) $sl['minHrs']; } }
        $slabRows = str_replace('<li></li>', '<li>' . sprintf($e($w['refundLast']), $lastHrs) . '</li>', $slabRows);
        $amen = implode('', array_map(static fn(string $a): string => '<span class="chip">' . $e($a) . '</span>', $f['amenities']));
        $faq = '';
        $faqLd = [];
        for ($i = 1; $i <= 6; $i++) {
            $q = str_replace('%s', $from, $w['q' . $i]);
            $a = $w['a' . $i];
            $faq .= '<details><summary>' . $e($q) . '</summary><p>' . $e($a) . '</p></details>';
            $faqLd[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
        }
        $others = '';
        foreach ($routes as $s2 => $r2) {
            if ($s2 === $slug) { continue; }
            $others .= '<a class="btn ghost" href="' . $e(self::url($s2, $lang)) . '">' . $e(sprintf($w['h1'], self::town((string) $r2['from_city'], $lang), self::town((string) $r2['to_city'], $lang))) . '</a>';
        }
        $langNav = '';
        foreach (['ne' => 'नेपाली', 'hi' => 'हिंदी', 'en' => 'English'] as $l => $lab) {
            $langNav .= '<a href="' . $e(self::url($slug, $l)) . '"' . ($l === $lang ? ' aria-current="page"' : '') . '>' . $lab . '</a>';
        }
        $waLink = 'https://wa.me/' . $e($f['wa']) . '?text=' . rawurlencode(($lang === 'ne' ? 'नमस्ते, ' : ($lang === 'hi' ? 'नमस्ते, ' : 'Hello, ')) . $from . ' → ' . $to . ' ticket');
        $telLink = 'tel:' . $e(preg_replace('/[^0-9+]/', '', $f['phone']));
        $trust = sprintf($e($w['trust']), $e($f['cin']), $e($f['address']));

        $ld = [
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'LocalBusiness', '@id' => rtrim(APP_URL, '/') . '/#org', 'name' => $f['company'], 'telephone' => $f['phone'], 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $f['address'], 'addressRegion' => 'Gujarat', 'addressCountry' => 'IN'], 'url' => rtrim(APP_URL, '/') . '/', 'areaServed' => ['India', 'Nepal'], 'openingHours' => 'Mo-Su 07:00-23:00'],
                ['@type' => 'Trip', 'name' => $h1, 'description' => $lead, 'url' => $canon, 'inLanguage' => $lang, 'provider' => ['@id' => rtrim(APP_URL, '/') . '/#org'],
                 'departureTime' => $f['dep'], 'arrivalTime' => $f['arr'],
                 'itinerary' => array_map(static fn(array $s): array => ['@type' => 'Place', 'name' => (string) $s['stop_name']], array_merge($f['boarding'], $f['drop'])),
                 'offers' => ['@type' => 'Offer', 'price' => (string) (int) $f['sharing'], 'priceCurrency' => 'INR', 'availability' => 'https://schema.org/InStock', 'url' => rtrim(APP_URL, '/') . '/']],
                ['@type' => 'FAQPage', 'mainEntity' => $faqLd],
                ['@type' => 'BreadcrumbList', 'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => $f['company'], 'item' => rtrim(APP_URL, '/') . '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Bus', 'item' => rtrim(APP_URL, '/') . ($lang === 'en' ? '/bus' : '/' . $lang . '/bus')],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => $h1, 'item' => $canon],
                ]],
            ],
        ];
        $ldJson = json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
        $corridor = self::corridor((string) $r['from_city'], (string) $r['to_city'], $lang);

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo self::layout($lang, $title, $desc, $canon, $alts, $ldJson, <<<HTML
<header class="top"><a class="brand" href="/"><img src="/assets/img/icon-192.png" alt="">{$e($f['company'])}</a><nav class="langs">{$langNav}</nav></header>
<main>
<h1>{$e($h1)}</h1>
<p class="lead">{$e($lead)}</p>
<div class="cta">
  <a class="btn hot" href="/#/">🎫 {$e($w['book'])}</a>
  <a class="btn wa" href="{$waLink}" target="_blank" rel="noopener">💬 {$e($w['wa'])}</a>
  <a class="btn" href="{$telLink}">📞 {$e($w['call'])} · {$e($f['phone'])}</a>
</div>
{$corridor}
<section class="grid">
  <div class="card"><h2>{$e($w['timetable'])}</h2>
    <dl><dt>{$e($w['dep'])}</dt><dd>{$e($from)} · {$e($f['dep'])} · {$e($w['daily'])}</dd>
        <dt>{$e($w['arr'])}</dt><dd>{$e($to)} · {$e($f['arr'])} (+{$f['dayOffset']})</dd>
        <dt>{$e($w['distance'])}</dt><dd>{$f['km']} km</dd></dl>
    <div class="chips">{$amen}</div>
  </div>
  <div class="card"><h2>{$e($w['fare'])}</h2>
    <dl><dt>{$e($w['sharing'])}</dt><dd><b>₹{$e(number_format($f['sharing']))}</b></dd>
        <dt>{$e($w['single'])}</dt><dd><b>₹{$e(number_format($f['single']))}</b></dd>
        <dt>{$e($w['double'])}</dt><dd><b>₹{$e(number_format($f['double']))}</b></dd></dl>
    <p class="note">{$e($w['fareNote'])}</p>
  </div>
</section>
<section class="card"><h2>{$e($w['boarding'])}</h2>
  <table><thead><tr><th>{$e($w['stop'])}</th><th>{$e($w['time'])}</th></tr></thead><tbody>{$boardRows}</tbody></table>
  <p class="note"><b>{$e($w['drop'])}:</b> {$dropList}</p>
</section>
<section class="card"><h2>{$e($w['refund'])}</h2><ul class="ladder">{$slabRows}</ul><p class="note">{$e($w['refundPromise'])}</p></section>
<section class="card faq"><h2>{$e($w['faq'])}</h2>{$faq}</section>
<section class="others"><h2>{$e($w['other'])}</h2><div class="cta">{$others}</div></section>
<p class="trust">{$trust}</p>
</main>
HTML);
        exit;
    }

    public static function renderIndex(string $lang): never
    {
        $w = self::words($lang);
        $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $company = Settings::getString('company_name', APP_NAME);
        $list = '';
        foreach (self::routes() as $slug => $r) {
            $f = self::facts($r);
            $list .= '<a class="card link" href="' . $e(self::url($slug, $lang)) . '"><b>' . $e(sprintf($w['h1'], self::town((string) $r['from_city'], $lang), self::town((string) $r['to_city'], $lang))) . '</b><span>' . $e($w['dep']) . ' ' . $e($f['dep']) . ' · ₹' . $e(number_format($f['sharing'])) . '</span></a>';
        }
        $canon = rtrim(APP_URL, '/') . ($lang === 'en' ? '/bus' : '/' . $lang . '/bus');
        $alts = '';
        foreach (self::LANGS as $l) {
            $alts .= '<link rel="alternate" hreflang="' . $l . '" href="' . $e(rtrim(APP_URL, '/') . ($l === 'en' ? '/bus' : '/' . $l . '/bus')) . '">';
        }
        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        $title = ($lang === 'ne' ? 'सबै बस रुट' : ($lang === 'hi' ? 'सभी बस रूट' : 'All bus routes')) . ' | ' . $company;
        echo self::layout($lang, $title, $title, $canon, $alts, '', '<header class="top"><a class="brand" href="/"><img src="/assets/img/icon-192.png" alt="">' . $e($company) . '</a></header><main><h1>' . $e($title) . '</h1><div class="list">' . $list . '</div></main>');
        exit;
    }

    private static function layout(string $lang, string $title, string $desc, string $canon, string $alts, string $ldJson, string $body): string
    {
        $e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $ld = $ldJson !== '' ? '<script type="application/ld+json">' . $ldJson . '</script>' : '';
        return <<<HTML
<!doctype html>
<html lang="{$e($lang)}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$e($title)}</title>
<meta name="description" content="{$e($desc)}">
<link rel="canonical" href="{$e($canon)}">
{$alts}
<meta property="og:title" content="{$e($title)}"><meta property="og:description" content="{$e($desc)}"><meta property="og:url" content="{$e($canon)}"><meta property="og:type" content="website"><meta property="og:image" content="{$e(rtrim(APP_URL, '/'))}/assets/img/og-shg.png">
<meta name="theme-color" content="#12264E">
<link rel="icon" href="/assets/img/favicon-32.png">
{$ld}
<style>
/* Design system tokens (assets/css/ds.css, 4 Oct 2026) - copied, not linked, so the page keeps zero blocking requests. */
:root{--navy:#0C306C;--on-navy:#FFFFFF;--on-navy-muted:#B9C9E6;--royal:#0054A8;--royal-strong:#003C90;--royal-tint:#EEF4FC;--on-royal:#FFFFFF;--red:#DC143C;--ink:#16233C;--muted:#5C6B85;--line:#E4E9F1;--bg:#F4F6FB;--card:#FFFFFF;--wa:#11703F;--gold:#EFB544;--focus:#0054A8}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--navy:#0F1A2E;--on-navy-muted:#93A4BE;--royal:#5B9BE8;--royal-strong:#8FBFFF;--royal-tint:#152645;--on-royal:#0B1220;--red:#FF5C74;--ink:#E8F0FB;--muted:#93A4BE;--line:#22314A;--bg:#0B1220;--card:#111D33;--wa:#5FD39A;--focus:#8FBFFF}}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Noto Sans Devanagari','Noto Sans',sans-serif;background:var(--bg);color:var(--ink);line-height:1.55;-webkit-font-smoothing:antialiased}
.top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:var(--navy);border-bottom:3px solid var(--red);padding:12px max(16px,calc((100% - 860px)/2 + 16px));margin-bottom:12px}
.brand{display:flex;align-items:center;gap:10px;font-weight:800;color:var(--on-navy);text-decoration:none}.brand img{width:34px;height:34px;border-radius:9px;background:#fff}
.langs{display:flex;gap:4px}
.langs a{display:inline-flex;align-items:center;min-height:36px;font-size:13px;font-weight:700;color:var(--on-navy);text-decoration:none;padding:4px 11px;border-radius:999px;border:1px solid rgba(255,255,255,.35)}
.langs a[aria-current]{background:var(--royal);color:var(--on-royal);border-color:var(--royal)}
a:focus-visible,button:focus-visible,summary:focus-visible{outline:3px solid var(--focus);outline-offset:2px}
main{max-width:860px;margin:0 auto;padding:6px 16px 40px}
h1{font-size:28px;line-height:1.2;margin:8px 0 8px}
h2{font-size:18px;margin:0 0 10px}
.lead{font-size:16.5px;color:var(--muted);margin-bottom:14px}
.cta{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 16px}
.btn{display:inline-flex;align-items:center;gap:8px;min-height:48px;padding:10px 18px;border-radius:14px;font-weight:800;text-decoration:none;border:1.5px solid var(--line);background:var(--card);color:var(--royal)}
.btn:hover{background:var(--royal-tint);border-color:var(--royal)}
.btn.hot{background:var(--royal);border-color:var(--royal);color:var(--on-royal)}.btn.hot:hover{background:var(--royal-strong)}
.btn.wa{background:var(--wa);border-color:var(--wa);color:var(--on-royal)}.btn.ghost{font-weight:700}
/* The corridor (4 Oct 2026): the homepage route scene, this page's town lit. transform/opacity only. */
.corr-art{position:relative;border-radius:14px;overflow:hidden;background:#0C306C;margin-top:4px}
.corr-art svg{display:block;width:100%;height:auto}
.c-glow{fill:none;stroke:rgba(239,181,68,.28);stroke-width:14;stroke-linecap:round;stroke-linejoin:round}
.c-track{fill:none;stroke:#EFB544;stroke-width:4;stroke-linecap:round;stroke-linejoin:round}
.c-stop{fill:#fff;stroke:#0C306C;stroke-width:3}.c-stop.on{fill:#EFB544}
.c-lbl{font:700 23px -apple-system,'Segoe UI',Roboto,'Noto Sans Devanagari',sans-serif;fill:#fff;paint-order:stroke;stroke:#0C306C;stroke-width:6px;stroke-linejoin:round}
.c-lbl.on{fill:#FFD27A}.c-cty{font:800 26px -apple-system,'Segoe UI',Roboto,sans-serif;letter-spacing:.18em;fill:rgba(255,255,255,.55)}
.c-dot{fill:#fff;opacity:.25;animation:c-run 2.4s linear infinite}
.c-pulse{fill:none;stroke:#EFB544;stroke-width:3;transform-box:fill-box;transform-origin:center;animation:c-pulse 2.4s ease-out infinite}
@keyframes c-run{0%,100%{opacity:.2}12%{opacity:1}30%{opacity:.2}}
@keyframes c-pulse{from{transform:scale(.6);opacity:.9}to{transform:scale(2.4);opacity:0}}
.corridor.paused .c-dot,.corridor.paused .c-pulse,.corridor.off .c-dot,.corridor.off .c-pulse{animation-play-state:paused}
@media (prefers-reduced-motion:reduce){.c-dot,.c-pulse{animation:none}.c-dot{opacity:.8}.c-pulse{opacity:0}.corr-pause{display:none}}
.corr-pause{position:absolute;right:10px;bottom:10px;min-width:44px;min-height:44px;border-radius:999px;border:1px solid rgba(255,255,255,.5);background:rgba(12,48,108,.7);color:#fff;font:700 13px sans-serif;cursor:pointer;padding:0 12px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:640px){.grid{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:16px;margin:0 0 12px;box-shadow:0 4px 16px rgba(18,38,78,.06)}
.card.link{display:flex;justify-content:space-between;align-items:center;gap:10px;text-decoration:none;color:var(--ink)}
dl{display:grid;grid-template-columns:auto 1fr;gap:6px 14px;font-size:15px}dt{color:var(--muted)}
.chips{margin-top:10px;display:flex;flex-wrap:wrap;gap:6px}.chip{font-size:12.5px;padding:4px 10px;border-radius:999px;background:var(--bg);border:1px solid var(--line)}
table{width:100%;border-collapse:collapse;font-size:15px}th,td{text-align:left;padding:8px 6px;border-bottom:1px solid var(--line)}th{color:var(--muted);font-size:12.5px;text-transform:uppercase;letter-spacing:.04em}
td small{color:var(--muted)}
.note{color:var(--muted);font-size:14px;margin-top:10px}
.ladder{padding-left:20px;font-size:15px}.ladder li{margin:4px 0}
details{border-top:1px solid var(--line);padding:10px 0}details:first-of-type{border-top:0}summary{font-weight:700;cursor:pointer}details p{margin-top:6px;color:var(--muted);font-size:14.5px}
.others h2{margin:14px 0 8px}
.trust{color:var(--muted);font-size:13px;margin-top:18px}
.list{display:grid;gap:10px}
</style>
</head>
<body>
{$body}
</body>
</html>
HTML;
    }

    /* ----------------------------------------------------------------
     *  Sitemaps
     * ---------------------------------------------------------------- */

    public static function sitemapRoutes(): never
    {
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
           . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
        $today = date('Y-m-d');
        $pages = ['' => ''];
        foreach (array_keys(self::routes()) as $slug) { $pages[$slug] = $slug; }
        foreach ($pages as $slug) {
            foreach (self::LANGS as $lang) {
                $loc = $slug === '' ? rtrim(APP_URL, '/') . ($lang === 'en' ? '/bus' : '/' . $lang . '/bus') : self::url($slug, $lang);
                $x .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc><lastmod>' . $today . '</lastmod><changefreq>weekly</changefreq><priority>' . ($slug === '' ? '0.6' : '0.9') . '</priority>';
                foreach (self::LANGS as $l) {
                    $alt = $slug === '' ? rtrim(APP_URL, '/') . ($l === 'en' ? '/bus' : '/' . $l . '/bus') : self::url($slug, $l);
                    $x .= '<xhtml:link rel="alternate" hreflang="' . $l . '" href="' . htmlspecialchars($alt, ENT_XML1) . '"/>';
                }
                $x .= "</url>\n";
            }
        }
        $x .= '</urlset>';
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $x;
        exit;
    }

    public static function sitemapPages(): never
    {
        $base = rtrim(APP_URL, '/');
        // 2 Oct 2026: /refund-policy and /agent-signup.php joined from the static
        // sitemap-pages.xml the overnight deploy left on live (it shadowed this one).
        $rows = [['/', 'daily', '1.0'], ['/travel-info', 'monthly', '0.6'], ['/refund-policy', 'monthly', '0.5'], ['/agent-signup.php', 'monthly', '0.4'], ['/privacy-policy', 'monthly', '0.3'], ['/terms-of-service', 'monthly', '0.3'], ['/data-deletion', 'yearly', '0.2']];
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($rows as [$p, $f, $pr]) {
            $x .= '  <url><loc>' . htmlspecialchars($base . $p, ENT_XML1) . '</loc><lastmod>' . date('Y-m-d') . '</lastmod><changefreq>' . $f . '</changefreq><priority>' . $pr . '</priority></url>' . "\n";
        }
        $x .= '</urlset>';
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $x;
        exit;
    }
}
