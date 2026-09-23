<?php
/**
 * =====================================================================
 *  WaFaq — the everyday WhatsApp questions, answered on THIS VPS with no
 *  AI call (23 Sep 2026).
 *
 *  Owner: "95 % kam afai garne", "compact garera lekhne".
 *
 *  Most messages this number receives are the same handful: hello, how
 *  much, what time, is there an offer, what is the website, how do I reach
 *  the office, and the policy questions the knowledge base already answers.
 *  Every one of those answers is a database read. Sending them to Gemini
 *  cost 2–40 seconds, spent a free-tier quota that ran out under test load,
 *  and could — in principle — be phrased wrong. Here they are read straight
 *  from the same tables the booking engine and the fare board use, in the
 *  writer's language, in two or three lines.
 *
 *  It claims ONLY what it is sure of:
 *    - anything personal ("mero ticket…", a PNR, a complaint, a payment
 *      already made) is left to the assistant, which can read their booking;
 *    - a knowledge-base answer needs a strong match;
 *    - anything else returns null and the assistant answers as before.
 *
 *  Every answer given here joins the assistant's conversation memory (so a
 *  follow-up keeps its context) and is logged in ai_agent_calls as
 *  "local:<intent>", so Admin → AI Activity shows how much never needed AI.
 *  Switch: wa_faq_on.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaFaq
{
    /* "chhut" (discount) is also the start of "chhutcha" (the bus DEPARTS), so
       the short forms carry a trailing space: a whole word only. */
    private const OFFER = ['discount', 'offer', 'chhut ', 'chut ', 'chhoot', 'sasto', 'scheme', 'coupon', 'promo',
        'छुट ', 'छूट ', 'अफर', 'ऑफर', 'डिस्काउन्ट', 'सस्तो'];

    private const FARE = ['bhada', 'bhaada', 'bhara', 'kiraya', 'kiraaya', 'fare', 'price', 'rate', 'charge ', 'kati parcha',
        'kati parchha', 'kati lagcha', 'kati lagchha', 'kati paisa', 'kati rupaiya', 'kitna', 'kitne ka', 'kitne paise',
        'how much', 'भाडा', 'भाड़ा', 'किराया', 'कति पर्छ', 'कति लाग्छ', 'कितना'];

    private const TIME = ['kati baje', 'kati bajey', 'kati bje', 'kahile', 'kaile', 'kun time', 'kun samay', 'time', 'timing',
        'samay', 'departure', 'chhutcha', 'chutcha', 'chhutchha', 'chhutne', 'chutne', 'kab ', 'kitne baje', 'kitne bje',
        'schedule', 'बजे', 'कहिले', 'समय', 'छुट्छ', 'कब', 'कितने बजे'];

    private const WEBSITE = ['website', 'web site', 'webside', 'app', 'link', 'site', 'वेबसाइट', 'एप', 'लिंक'];

    private const CONTACT = ['office', 'contact', 'sampark', 'phone number', 'contact number', 'office number', 'address',
        'thegana', 'counter', 'call', 'helpline', 'customer care', 'सम्पर्क', 'ठेगाना', 'कार्यालय', 'अफिस', 'काउन्टर'];

    /** Places people type, mapped to a word in the stop's own name. */
    private const PLACE_ALIASES = [
        'ahmedabad' => 'chiloda', 'amdavad' => 'chiloda', 'amd' => 'chiloda', 'naroda' => 'chiloda', 'chiloda' => 'chiloda',
        'hari parking' => 'chiloda', 'baroda' => 'vadodara', 'vadodara' => 'vadodara', 'surat' => 'surat', 'surath' => 'surat',
        'kamrej' => 'kamrej', 'ankleshwar' => 'ankleshwar', 'anklesvar' => 'ankleshwar', 'bharuch' => 'bharuch',
        'anand' => 'anand', 'nadiad' => 'nadiad', 'emli' => 'emli', 'bhupal' => 'emli',
        'rupaidiha' => 'rupaidiha', 'rupdia' => 'rupaidiha', 'rupaidha' => 'rupaidiha', 'rupediha' => 'rupaidiha',
        'rupaidiya' => 'rupaidiha', 'jamunaha' => 'rupaidiha', 'nepalgunj' => 'rupaidiha', 'nepalganj' => 'rupaidiha',
    ];

    public static function enabled(): bool
    {
        return Settings::getBool('wa_faq_on', false);
    }

    /**
     * @return array{text: string, media: ?string, intent: string}|null null = not sure, let the assistant answer
     */
    public static function answer(string $fromRaw, string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 300 || !self::enabled()) {
            return null;
        }
        $t0 = microtime(true);
        require_once INCLUDE_PATH . '/ticketbot.php';

        $t = ' ' . self::norm($text) . ' ';
        if (self::personal($t)) {
            return null;                        // their own ticket / money / number: the assistant reads it
        }
        $lang = self::lang($text);
        $who  = normalisePhone($fromRaw);

        $parts = [];
        $intents = [];
        if (self::isGreeting($t)) {
            $intents[] = 'greeting';
            // A bare "namaste" / "hi" carries no language signal; the desk speaks Nepali first.
            $parts[]   = self::greeting($fromRaw, $who, $lang === 'en' ? 'ne' : $lang);
        } else {
            $isFare  = self::has($t, self::FARE);
            $isTime  = self::has($t, self::TIME);
            $isOffer = self::has($t, self::OFFER);
            if ($isFare) {
                $intents[] = 'fare';
                $parts[]   = self::fare($text, $lang, !$isOffer);
            }
            if ($isTime) {
                $intents[] = 'timing';
                $parts[]   = self::timing($t, $text, $lang);
            }
            if ($isOffer) {
                $intents[] = 'offers';
                $parts[]   = self::offers($lang);
            }
            if ($parts === [] && self::has($t, self::WEBSITE)) {
                $intents[] = 'website';
                $parts[]   = self::website($lang);
            }
            if ($parts === [] && self::has($t, self::CONTACT)) {
                $intents[] = 'contact';
                $parts[]   = self::contact($lang);
            }
            if ($parts === []) {
                $kb = self::knowledge($fromRaw, $text, $lang);
                if ($kb !== null) {
                    $intents[] = 'knowledge';
                    $parts[]   = $kb;
                }
            }
        }
        $parts = array_values(array_filter($parts, static fn($p): bool => is_string($p) && $p !== ''));
        if ($parts === []) {
            return null;
        }

        $reply  = implode("\n", $parts);
        $intent = implode('+', $intents);
        self::remember($who, $text, $reply);
        self::log($fromRaw, $who, $intent, $t0);

        return ['text' => $reply, 'media' => null, 'intent' => $intent];
    }

    /* =================================================================
     *  The answers
     * ================================================================= */

    private static function greeting(string $fromRaw, string $who, string $lang): string
    {
        $name  = '';
        $staff = false;
        try {
            require_once INCLUDE_PATH . '/aitools.php';
            $ctx   = AiTools::whoIs($fromRaw);
            $name  = self::firstName((string) ($ctx['name'] ?? ''));
            $staff = in_array((string) ($ctx['role'] ?? ''), ['staff', 'admin'], true);
        } catch (Throwable $e) {
        }
        $mantra = trim(Settings::getString('company_mantra', ''));
        $open   = ($mantra !== '' && !self::greetedRecently($who)) ? $mantra . "\n" : '';
        $hi     = self::t($lang, 'नमस्ते', 'नमस्ते', 'Namaste') . ($name !== '' ? ' ' . $name . ' ' . self::t($lang, 'जी', 'जी', 'ji') : '') . '! ';

        if ($staff) {
            return $open . $hi . self::t($lang,
                'आजको बिक्री, report, यात्रु सूची वा टिकट — के चाहियो?',
                'आज की बिक्री, report, यात्री सूची या टिकट — क्या चाहिए?',
                "Today's sales, the report, the passenger list or a ticket — what do you need?");
        }
        return $open . $hi . self::t($lang,
            'म S Hari Global को सहायक हुँ। टिकट, भाडा, बसको समय वा आफ्नो टिकटबारे सोध्नुहोस्।',
            'मैं S Hari Global का सहायक हूँ। टिकट, किराया, बस का समय या अपने टिकट के बारे में पूछिए।',
            "I'm S Hari Global's assistant. Ask me about tickets, fares, bus times or your own ticket.");
    }

    private static function fare(string $text, string $lang, bool $withOffer): string
    {
        $p     = TicketBot::parse($text);
        $seats = max(1, (int) ($p['seats'] ?? 0));
        $dir   = (string) ($p['direction'] ?? '');
        $toNp  = Settings::getInt('fare_to_nepal', 2000);
        $toIn  = Settings::getInt('fare_to_india', 1800);
        $priv  = (int) (Settings::getArray('cabin_pricing', [])['private']['single_1pax']['online'] ?? 3800);
        $per   = self::t($lang, '/जना', '/व्यक्ति', '/person');

        $lines = [];
        if ($dir !== 'toIndia') {
            $lines[] = 'Gujarat → Nepal (Rupaidiha): ' . inr($toNp) . $per
                     . ($seats > 1 ? ' · ' . $seats . self::t($lang, ' जनाको ', ' लोगों का ', ' people: ') . inr($toNp * $seats) : '');
        }
        if ($dir !== 'toNepal') {
            $lines[] = 'Nepal → Gujarat: ' . inr($toIn) . $per
                     . ($seats > 1 ? ' · ' . $seats . self::t($lang, ' जनाको ', ' लोगों का ', ' people: ') . inr($toIn * $seats) : '');
        }
        $lines[] = self::t($lang, 'Private cabin ', 'Private cabin ', 'Private cabin from ') . inr($priv)
                 . self::t($lang, ' देखि। Online र counter मा भाडा उस्तै।', ' से। Online और counter पर किराया एक समान।', '. Same fare online and at the counter.');
        if ($withOffer) {
            $offer = self::offerLine();
            if ($offer !== '') {
                $lines[] = '🎁 ' . $offer;
            }
        }
        $lines[] = self::t($lang, 'मिति र कहाँबाट भन्नुहोस्, सिट हेरिदिन्छु।',
            'तारीख और कहाँ से, बताइए — सीट देख देता हूँ।', 'Tell me the date and your pickup, and I will check seats.');
        return implode("\n", $lines);
    }

    private static function timing(string $t, string $text, string $lang): string
    {
        $routes = self::routes();
        if ($routes === []) {
            return '';
        }
        $early = self::t($lang, 'चढ्ने ठाउँमा ६० मिनेट अगाडि पुग्नुहोस्।', 'चढ़ने की जगह पर 60 मिनट पहले पहुँचिए।',
            'Please reach the pickup 60 minutes early.');

        // Which stop did they name? The one written before "bata / se / from"
        // is where they board ("rupdia bata surat kahile aaune" boards at
        // Rupaidiha); else the parser; else the first place in the sentence.
        $want = self::origin($t);
        if ($want === '') {
            $want = mb_strtolower(trim((string) (TicketBot::parse($text)['boarding'] ?? '')));
        }
        if ($want === '') {
            $first = PHP_INT_MAX;
            foreach (self::PLACE_ALIASES as $alias => $key) {
                if (preg_match('/(?<![\p{L}])' . preg_quote($alias, '/') . '/u', $t, $m, PREG_OFFSET_CAPTURE) === 1
                    && $m[0][1] < $first) {
                    $first = $m[0][1];
                    $want  = $key;
                }
            }
        }
        if ($want !== '') {
            foreach ($routes as $r) {
                foreach ($r['board'] as $s) {
                    $name = mb_strtolower($s['name']);
                    if ($want === $name || str_contains($name, $want) || str_contains($want, $name)) {
                        $at = self::short($s['name']);
                        return self::t($lang,
                            $at . ' बाट ' . $r['to'] . ' जाने बस हरेक दिन ' . self::clock($s['time']) . ' मा छुट्छ। ' . $early,
                            $at . ' से ' . $r['to'] . ' जाने वाली बस रोज़ ' . self::clock($s['time']) . ' पर निकलती है। ' . $early,
                            'The bus from ' . $at . ' to ' . $r['to'] . ' leaves daily at ' . self::clock($s['time']) . '. ' . $early);
                    }
                }
            }
        }

        // No stop named: the whole timetable in two lines.
        $lines = [];
        foreach ($routes as $r) {
            $stops = array_map(static fn(array $s): string => self::short($s['name']) . ' ' . self::clock($s['time']), $r['board']);
            $lines[] = $r['from'] . ' → ' . $r['to'] . ': ' . implode(' · ', $stops);
        }
        $lines[] = self::t($lang, 'कुन ठाउँबाट चढ्नुहुन्छ?', 'आप कहाँ से चढ़ेंगे?', 'Where will you board?');
        return implode("\n", $lines);
    }

    private static function offers(string $lang): string
    {
        $line = self::offerLine();
        if ($line !== '') {
            return '🎁 ' . $line . "\n" . self::t($lang, 'यो booking मा आफैं लाग्छ — छुट्टै code चाहिँदैन।',
                'यह booking पर अपने आप लगता है — अलग code नहीं चाहिए।', 'It is applied to your booking automatically — no code needed.');
        }
        return self::t($lang,
            'आज कुनै offer चलिरहेको छैन। भाडा online र counter मा उस्तै छ।',
            'आज कोई offer नहीं चल रहा। किराया online और counter पर एक समान है।',
            'No offer is running today. The fare is the same online and at the counter.');
    }

    private static function website(string $lang): string
    {
        $web = Settings::getString('company_web', 'shreehariglobal.in');
        return '🌐 https://' . $web . "\n"
             . self::t($lang, 'टिकट बुक: ', 'टिकट बुक: ', 'Book: ') . 'https://' . $web . '/#/'
             . self::t($lang, ' · मेरो टिकट: ', ' · मेरा टिकट: ', ' · My ticket: ') . 'https://' . $web . '/#/my'
             . self::t($lang, ' · बस कहाँ: ', ' · बस कहाँ: ', ' · Live bus: ') . 'https://' . $web . "/#/nav\n"
             . self::t($lang, 'मोबाइल नम्बर र OTP मात्र — account चाहिँदैन।', 'मोबाइल नंबर और OTP ही — account नहीं चाहिए।',
                'Just your mobile number and an OTP — no account needed.');
    }

    private static function contact(string $lang): string
    {
        $c = Settings::company();
        return '📞 ' . self::t($lang, 'कार्यालय', 'ऑफिस', 'Office') . ': ' . $c['phone'] . ' (call / WhatsApp)' . "\n"
             . self::t($lang, 'काउन्टर', 'काउंटर', 'Counters') . ': ' . $c['counters'] . "\n"
             . '📍 ' . $c['address'];
    }

    /** A knowledge-base article, only on a strong and clear match. */
    private static function knowledge(string $fromRaw, string $text, string $lang): ?string
    {
        if (!Settings::getBool('ai_kb_on', false)) {
            return null;
        }
        try {
            require_once INCLUDE_PATH . '/aiknowledge.php';
            require_once INCLUDE_PATH . '/aitools.php';
            $role = (string) (AiTools::whoIs($fromRaw)['role'] ?? 'customer');
            $hits = AiKb::search($text, $role, 2);
        } catch (Throwable $e) {
            return null;
        }
        if ($hits === []) {
            return null;
        }
        $top    = (int) ($hits[0]['_score'] ?? 0);
        $second = (int) ($hits[1]['_score'] ?? 0);
        if ($top < 6 || $top - $second < 2) {
            return null;                        // not sure which article: the assistant decides
        }
        return AiKb::answer($hits[0], $lang === 'en' ? 'en' : $lang);
    }

    /* =================================================================
     *  Helpers
     * ================================================================= */

    /**
     * Their own ticket, money, number or a complaint — never a canned answer.
     * Its own list, narrower than WaBooking's: "office kaha cha?" is a
     * question about US, while WaBooking (which guards a SALE) treats every
     * "kaha cha" as a reason not to sell.
     */
    private const PERSONAL = ['mero', 'mera', 'meri', 'my', 'hamro', 'hamaro', 'humara', 'हमारा', 'मेरो', 'मेरा', 'मेरी', 'हाम्रो',
        'admin', 'ticket kahile', 'ticket aau', 'ticket aun',
        'galat', 'galti', 'wrong', 'mistake', 'problem', 'samasya', 'error', 'aayena', 'aaena', 'ayena', 'aayeko chaina',
        'pugena', 'milena', 'nahi aaya', 'nahi aya', 'not received', 'cancel', 'radd', 'refund', 'paisa firta', 'paise wapas',
        'change', 'badal', 'sachya', 'sudhar', 'correct', 'reschedule', 'resend', 'pathaideu', 'harayo', 'lost',
        'payment gar', 'payment kiya', 'paisa tire', 'paid', 'katyo', 'kat gaya', 'complain', 'gunaso', 'ujuri',
        'गलत', 'गल्ती', 'आएन', 'पुगेन', 'मिलेन', 'समस्या', 'रद्द', 'क्यान्सिल', 'फिर्ता', 'रिफन्ड', 'बदल', 'सच्या', 'हरायो',
        'गुनासो', 'नहीं आया'];

    private static function personal(string $t): bool
    {
        if (preg_match('/shg[-\s]?[a-z0-9]/iu', $t) === 1) {
            return true;
        }
        foreach (self::PERSONAL as $w) {
            if (preg_match('/(?<![\p{L}\p{M}\p{N}])' . preg_quote($w, '/') . '/u', $t) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function isGreeting(string $t): bool
    {
        $s = trim(preg_replace('/[!.,?🙏\s]+/u', ' ', $t) ?? $t);
        return preg_match('/^(namaste|namaskar|namaskaar|hello|hi|hii|hey|hlo|helo|jai shree ram|ram ram|good morning|good evening|'
            . 'k cha|ke cha|kasto cha|kasto chha|k xa|नमस्ते|नमस्कार|हेलो|राम राम|सुप्रभात)'
            . '( (ji|jee|dai|daju|bhai|sir|hajur|didi|जी|दाइ|हजुर))?$/u', $s) === 1;
    }

    private static function has(string $t, array $words): bool
    {
        foreach ($words as $w) {
            if (preg_match('/(?<![\p{L}\p{M}])' . preg_quote($w, '/') . '/u', $t) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{from:string, to:string, board:list<array{name:string,time:string}>}> */
    private static function routes(): array
    {
        $out = [];
        try {
            foreach (Database::fetchAll('SELECT id, from_city, to_city FROM routes WHERE is_active = 1 ORDER BY sort_order, dep_time') as $r) {
                $board = [];
                foreach (Database::fetchAll(
                    "SELECT stop_name, stop_time FROM route_stops WHERE route_id = :r AND stop_type = 'boarding' ORDER BY sort_order",
                    ['r' => (int) $r['id']]) as $s) {
                    if ((string) ($s['stop_time'] ?? '') !== '') {
                        $board[] = ['name' => (string) $s['stop_name'], 'time' => (string) $s['stop_time']];
                    }
                }
                if ($board !== []) {
                    $out[] = ['from' => (string) $r['from_city'], 'to' => (string) $r['to_city'], 'board' => $board];
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /** The place written right before "bata / se / from / बाट / से" — where they board. */
    private static function origin(string $t): string
    {
        foreach (self::PLACE_ALIASES as $alias => $key) {
            if (preg_match('/(?<![\p{L}])' . preg_quote($alias, '/') . '[\p{L}]*\s+(?:bata|baata|se|from|बाट|से)(?![\p{L}])/u', $t) === 1) {
                return $key;
            }
        }
        return '';
    }

    private static function offerLine(): string
    {
        if (!class_exists('Fare')) {
            require_once INCLUDE_PATH . '/fare.php';
        }
        $offers = Fare::runningOffers();
        return $offers === [] ? '' : implode(' · ', array_map([Fare::class, 'offerLine'], $offers));
    }

    private static function clock(string $time): string
    {
        $ts = strtotime($time);
        return $ts === false ? $time : date('g:i A', $ts);
    }

    /** "S Hari Parking, Nana Chiloda (Amd)" -> "Ahmedabad (Nana Chiloda)" — what people call it. */
    private static function short(string $name): string
    {
        return stripos($name, 'chiloda') !== false ? 'Ahmedabad (Nana Chiloda)' : $name;
    }

    /** Lower-case, punctuation to spaces, one space between words. */
    private static function norm(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[?？!.,;:।॥"\'()]+/u', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** ne / hi / en — Gujarati writers get English (the app dropped Gujarati). */
    private static function lang(string $text): string
    {
        $l = TicketBot::detectLang($text);
        return in_array($l, ['ne', 'hi'], true) ? $l : 'en';
    }

    private static function t(string $lang, string $ne, string $hi, string $en): string
    {
        return match ($lang) { 'ne' => $ne, 'hi' => $hi, default => $en };
    }

    private static function firstName(string $full): string
    {
        $full = trim($full);
        return $full === '' ? '' : (string) (preg_split('/\s+/u', $full)[0] ?? '');
    }

    /** The house mantra opens a conversation once, not every hello. */
    private static function greetedRecently(string $who): bool
    {
        if ($who === '') {
            return true;
        }
        try {
            $at = (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_greet' AND kkey = :k", ['k' => $who], 0);
            Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_greet', :k, :v, 'wafaq')
                           ON DUPLICATE KEY UPDATE kvalue = :v2", ['k' => $who, 'v' => (string) time(), 'v2' => (string) time()]);
            return $at > time() - 7200;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** A local answer joins the assistant's memory, so "ani bholi ko?" still has its context. */
    private static function remember(string $who, string $user, string $reply): void
    {
        try {
            require_once INCLUDE_PATH . '/aiagent.php';
            AiAgent::remember($who, $user, $reply);
        } catch (Throwable $e) {
        }
    }

    /** Admin → AI Activity counts these as "local:<intent>" — the share that never needed AI. */
    private static function log(string $fromRaw, string $who, string $intent, float $t0): void
    {
        try {
            require_once INCLUDE_PATH . '/aitools.php';
            $role = (string) (AiTools::whoIs($fromRaw)['role'] ?? 'customer');
            Database::insert('ai_agent_calls', [
                'created_at' => date('Y-m-d H:i:s'),
                'phone'      => $who,
                'role'       => in_array($role, ['customer', 'staff', 'admin'], true) ? $role : 'customer',
                'channel'    => 'whatsapp',
                'tool'       => mb_substr('local:' . $intent, 0, 40),
                'args'       => '{}',
                'ok'         => 1,
                'detail'     => 'answered on the VPS, no AI call',
                'booking_id' => null,
                'ms'         => (int) round((microtime(true) - $t0) * 1000),
            ]);
        } catch (Throwable $e) {
        }
    }
}
