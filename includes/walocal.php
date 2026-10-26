<?php
/**
 * =====================================================================
 *  WaLocal — the local brain: every fact the website knows, answered on
 *  THIS VPS with no AI call, in Nepali first (9 Oct 2026).
 *
 *  Owner: "AI lai yeti advance banau ki website ko har ek detail — time
 *  table, name, number, ticket, bus live ma kaha cha, seat kati baki —
 *  Nepali ma agent / admin lai, customer lai full assistance, website
 *  ma januparne nai hoina, sabai WhatsApp bata automatic, free, mero
 *  VPS ma chalne."
 *
 *  WaFaq (23 Sep) already answers hello, fare, bus time, offers, website,
 *  office and the knowledge base. This class adds the answers that used
 *  to need the AI (and therefore a third party's quota and 2–40 s):
 *
 *    bus_live       where the bus is right now (GPS fix, ETA to MY stop,
 *                   delay, track link) — a passenger with a ticket, or
 *                   every running trip for staff
 *    timetable      every pickup and its time, arrival next day
 *    seats_left     free berths per direction for the day they named
 *    bus_crew       bus number / name, driver & crew (staff always; a
 *                   ticketed passenger on the travel day)
 *    my_ticket_png  the ticket picture again (free — they wrote to us)
 *    pay_how        how to pay: their pending booking's QR, UPI, counter
 *    refund_rule    the published refund slabs, and what THEIR ticket
 *                   would get right now
 *    nepal_office   the Nepal office / Nepalgunj / Rupaidiha numbers
 *    help           what this number can do (per role)
 *    summary        staff: today's tickets, seats, money, bus fill
 *    passengers     staff: who is on the bus (scoped to an agent's own)
 *    pending        office: payments waiting to be verified
 *
 *  It claims only what it is sure of: an open sale conversation belongs
 *  to WaBooking, a PNR in the text to the PNR reply, a complaint or a
 *  change request to the assistant. Anything it does not recognise
 *  returns null and the older paths answer exactly as before.
 *
 *  Reads the same tables the screens read (schedules, routes, buses,
 *  drivers, bookings, kv livebus, settings); never writes a booking.
 *  Switch: wa_local_brain_on. Staff language: wa_staff_lang.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaLocal
{
    private const HELP = ['help', 'madat', 'madad', 'sahayog', 'sahayata', 'command', 'commands', 'k k garna', 'ke ke garna', 'kya kya kar',
        'k garna milcha', 'ke garna milcha', 'kya kar sakte', 'what can you do', 'सहयोग', 'मदत', 'मदद', 'सहायता', 'के के गर्न', 'क्या क्या'];

    private const TIMETABLE = ['timetable', 'time table', 'samay talika', 'samaya talika', 'samay suchi', 'pura schedule', 'full schedule',
        'sabai time', 'sab time', 'sabai samay', 'all timing', 'all time', 'timing list', 'sabai bus ko time', 'sab bus ka time',
        'समय तालिका', 'तालिका', 'टाइम टेबल', 'सबै समय', 'सब समय', 'ટાઈમ ટેબલ', 'સમય પત્રક'];

    private const SEATS = ['kati seat', 'kitni seat', 'kitne seat', 'kati sit', 'seat baki', 'seat bachi', 'seat bacheko', 'seat khali', 'khali seat',
        'seat khaali', 'khaali seat', 'sit khali', 'khali sit', 'seat available', 'available seat', 'seats available', 'seats left', 'seat left',
        'seat cha', 'seat chha', 'seat xa', 'seat hai', 'seat milcha', 'seat milega', 'free seat', 'seats free', 'seat free', 'empty seat',
        'कति सिट', 'कति सीट', 'कितनी सीट', 'कितने सीट', 'सिट खाली', 'खाली सिट', 'सीट खाली', 'खाली सीट', 'सिट छ', 'सिट बाँकी', 'सीट बाकी',
        'ketli seat', 'ketla seat', 'કેટલી સીટ', 'ખાલી સીટ', 'સીટ ખાલી'];

    private const LIVE_WORDS = ['kaha pug', 'kata pug', 'kahan pahunch', 'kaha pahunch', 'kata xa', 'kata cha', 'kaha cha bus', 'kaha xa bus',
        'bus kaha', 'bus kata', 'bus kahan', 'gadi kaha', 'gadi kata', 'gaadi kaha', 'gadi kahan', 'location', 'live', 'track', 'gps', 'eta',
        'kati time ma aau', 'kati time ma pug', 'kati ber ma', 'kahile pugcha', 'kahile pugchha', 'kab pahunch', 'kitni der', 'pugyo', 'pahunchi',
        'कहाँ पुग', 'कता पुग', 'कहाँ छ', 'कता छ', 'कहाँ पहुँच', 'कहां पहुंच', 'बस कहाँ', 'बस कहां', 'गाडी कहाँ', 'लोकेसन', 'लोकेशन', 'ट्र्याक', 'ट्रैक',
        'કયાં પહોંચી', 'ક્યાં પહોંચી', 'ક્યાં છે', 'બસ ક્યાં'];

    private const CREW = ['driver', 'chalak', 'conductor', 'kandaktar', 'helper', 'crew', 'bus number', 'bus no', 'bus ko number', 'bus ka number',
        'gadi number', 'gaadi number', 'gadi no', 'gadi ko number', 'gadi ka number', 'kun bus', 'kun gadi', 'kaun si bus', 'konsi bus',
        'bus ko naam', 'bus ka naam', 'bus name', 'bus kun', 'gadi kun', 'ड्राइभर', 'ड्राइवर', 'ड्राईवर', 'चालक', 'कन्डक्टर', 'कंडक्टर', 'बस नम्बर',
        'बस नंबर', 'गाडी नम्बर', 'गाड़ी नंबर', 'कुन बस', 'कुन गाडी', 'कौन सी बस', 'बस को नाम', 'ડ્રાઈવર', 'બસ નંબર', 'કઈ બસ'];

    private const TICKET_PNG = ['ticket pathau', 'ticket pathai', 'ticket pathaide', 'ticket patha', 'ticket bhej', 'ticket photo', 'ticket ko photo',
        'ticket ko foto', 'ticket image', 'ticket feri', 'ticket again', 'resend ticket', 'ticket resend', 'send ticket', 'send my ticket', 'ticket send',
        'ticket chahiyo', 'ticket chahiye', 'ticket dekhau', 'ticket dikhao', 'ticket download', 'ticket copy', 'tiket pathau', 'tikat pathau',
        'टिकट पठाउ', 'टिकट पठाइ', 'टिकट भेज', 'टिकट फोटो', 'टिकट फेरि', 'टिकट फिर', 'टिकट चाहियो', 'टिकट चाहिए', 'टिकट देखाउ', 'टिकट दिखाओ',
        'ટિકિટ મોકલો', 'ટિકિટ ફરી'];

    private const PAY = ['kasari tirne', 'kasari tir', 'payment kasari', 'paisa kasari', 'paisa kaha tirne', 'kaise pay', 'kaise bhare', 'kaise bharu',
        'kaise de', 'payment kaise', 'paise kaise', 'how to pay', 'how do i pay', 'how can i pay', 'payment option', 'payment method', 'payment kaha',
        'online pay', 'pay online', 'upi', 'gpay', 'google pay', 'phonepe', 'phone pe', 'paytm', 'esewa', 'khalti', 'qr code', 'qr', 'bank account',
        'account number', 'payment link', 'कसरी तिर्ने', 'कसरी तिरौं', 'भुक्तानी कसरी', 'पैसा कसरी', 'कैसे भरे', 'कैसे भरें', 'कैसे दे', 'पेमेन्ट', 'पेमेंट',
        'पैसे कैसे', 'भुगतान कैसे', 'युपिआई', 'यूपीआई', 'કેવી રીતે ચૂકવ', 'પેમેન્ટ'];

    private const REFUND = ['cancel garda', 'cancel gare', 'cancel garyo', 'cancel kiya', 'cancel karne', 'cancel karu', 'cancel policy', 'cancellation',
        'cancel rule', 'cancel garna milcha', 'refund', 'paisa firta', 'paisa pharta', 'paise wapas', 'kati firta', 'kitna wapas', 'kitne wapas',
        'रद्द गर्दा', 'रद्द गरे', 'रद्द गर्न', 'क्यान्सिल', 'कैंसिल', 'फिर्ता', 'रिफन्ड', 'रिफंड', 'वापस', 'પાછા', 'રિફંડ', 'રદ'];

    private const NEPAL_OFFICE = ['nepal office', 'nepalgunj office', 'nepalganj office', 'nepal ko number', 'nepal number', 'nepal ko office',
        'nepal contact', 'rupaidiha office', 'rupaidiha number', 'rupaidiha contact', 'nepal counter', 'nepalgunj number', 'nepalgunj contact',
        'नेपाल अफिस', 'नेपाल कार्यालय', 'नेपालगन्ज', 'नेपालगंज', 'नेपाल नम्बर', 'नेपाल नंबर', 'रुपैडिहा', 'नेपाल सम्पर्क'];

    private const SUMMARY = ['hisab', 'hisaab', 'summary', 'aaja ko bikri', 'aajako bikri', 'aaj ki bikri', 'kati bikri', 'kati ticket', 'kitne ticket',
        'kitni ticket', 'kati katyo', 'kati kateyo', 'kitne kate', 'aaja kati', 'aaj kitna', 'aaj kitne', 'collection', 'revenue', 'kamai', 'report',
        'bikri', 'sales', 'कति टिकट', 'कितने टिकट', 'आजको हिसाब', 'हिसाब', 'आजको बिक्री', 'कति बिक्री', 'रिपोर्ट', 'बिक्री', 'कमाइ', 'कमाई', 'कलेक्सन', 'કેટલી ટિકિટ', 'હિસાબ'];

    private const PASSENGERS = ['passenger list', 'passengers list', 'yatri list', 'yatru list', 'yatru suchi', 'yatri suchi', 'passenger', 'yatru',
        'yatri', 'manifest', 'kun kun', 'ko ko', 'kaun kaun', 'list', 'यात्री', 'यात्रु', 'सूची', 'लिस्ट', 'को को', 'कुन कुन', 'कौन कौन', 'મુસાફર', 'યાદી'];

    private const PENDING = ['pending', 'baki payment', 'payment baki', 'bhuktani baki', 'verify garna', 'verify baki', 'verify', 'unpaid', 'paid chaina',
        'पेन्डिङ', 'पेंडिंग', 'बाँकी', 'बाकी', 'भुक्तानी बाँकी'];

    private const COMPANY = ['ceo', 'c e o', 'malik', 'maalik', 'owner', 'company ko naam', 'company name', 'company ka naam', 'company details',
        'company detail', 'company info', 'company ko', 'registration', 'cin', 'gst', 'gstin', 'email', 'e mail', 'legal name', 'kasko company',
        'kasle chalaucha', 'kaun chalata', 'ceo ko', 'owner ko', 'सिइओ', 'सीईओ', 'मालिक', 'कम्पनी', 'इमेल', 'ईमेल', 'मालिक को', 'સીઈઓ', 'માલિક', 'કંપની'];

    private const OFFICE_MAP = ['map', 'maps', 'google map', 'gmap', 'location link', 'maps link', 'naksha', 'nakshya', 'नक्सा', 'नक्शा',
        'office kaha', 'office kata', 'office kahan', 'office location', 'office ko location', 'counter kaha', 'counter kahan', 'counter kata',
        'counter location', 'parking kaha', 'parking location', 'parking kata', 'head office', 'address', 'thegana', 'ठेगाना', 'पता',
        'कार्यालय कहाँ', 'अफिस कहाँ', 'ऑफिस कहाँ', 'ऑफिस कहां', 'काउन्टर कहाँ', 'direction', 'kasari pugne', 'kaise pahunche', 'કેવી રીતે પહોંચ', 'સરનામું', 'ઓફિસ ક્યાં'];

    private const AGENT = ['agent', 'ejent', 'agents', 'एजेन्ट', 'एजेंट', 'एजेन्टहरू', 'एजेन्ट को', 'एजेंट का', 'એજન્ટ', 'dalal', 'counter wala', 'counter walo', 'agent code'];

    /** A message that ASKS to cancel / change is for a person, never a policy line. */
    private const IMPERATIVE = ['garideu', 'garidinu', 'garidinus', 'gardeu', 'gardinus', 'kar do', 'kardo', 'kar dijiye', 'kijiye', 'karna hai', 'garnu cha',
        'garnu xa', 'garnu paryo', 'chahanchu', 'chahanchhu', 'chahta', 'chahti', 'please cancel', 'cancel my', 'mero cancel', 'गरिदेउ', 'गरिदिनु', 'गर्देउ',
        'कर दो', 'कर दीजिए', 'करना है', 'गर्नु छ', 'गर्नुपर्‍यो'];

    public static function enabled(): bool
    {
        return Settings::getBool('wa_local_brain_on', true);
    }

    /**
     * @param array<string,mixed>|null $who AiTools::whoIs() when the caller resolved it
     * @return array{text: string, media: ?string, ui: ?array, intent: string}|null null = not mine
     */
    public static function answer(string $fromRaw, string $text, ?array $who = null): ?array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 400 || !self::enabled()) {
            return null;
        }
        $digits = normalisePhone($fromRaw);
        if ($digits === '') {
            return null;
        }
        // A PNR (SHG-2026-00123) in the message is the PNR reply's (or the assistant's) business; an agent code (SHG-0027) is ours.
        if (preg_match('/shg[-\s]?\d{4}[-\s]?\d{3,}/iu', $text) === 1) {
            return null;
        }
        try {
            require_once INCLUDE_PATH . '/ticketbot.php';
            require_once INCLUDE_PATH . '/wabooking.php';
            if (WaBooking::isOpen($digits)) {
                return null;                     // a sale in progress keeps its conversation
            }
        } catch (Throwable $e) {
            return null;
        }
        if ($who === null) {
            try {
                require_once INCLUDE_PATH . '/aitools.php';
                $who = AiTools::whoIs($fromRaw);
            } catch (Throwable $e) {
                $who = ['role' => 'customer'];
            }
        }
        $role    = (string) ($who['role'] ?? 'customer');
        $isStaff = in_array($role, ['staff', 'admin'], true);
        $t       = ' ' . self::norm($text) . ' ';
        $intent  = self::intentOf($t, $text, $role);
        if ($intent === '') {
            return null;
        }
        $lang = self::lang($text, $isStaff);
        $t0   = microtime(true);
        try {
            $r = match ($intent) {
                'help'          => self::help($role, $lang),
                'timetable'     => self::timetable($lang),
                'company_info'  => self::companyInfo($lang),
                'office_map'    => self::officeMap($lang),
                'agent_info'    => self::agentInfo($who, $lang, $text),
                'seats_left'    => self::seatsLeft($text, $lang, $isStaff),
                'bus_live'      => self::busLive($fromRaw, $who, $lang),
                'bus_crew'      => self::busCrew($fromRaw, $who, $lang, $text),
                'my_ticket_png' => self::myTicketPng($fromRaw, $who, $lang),
                'pay_how'       => self::payHow($fromRaw, $lang),
                'refund_rule'   => self::refundRule($fromRaw, $lang),
                'nepal_office'  => self::nepalOffice($lang),
                'summary'       => self::summary($who, $lang),
                'staff_board'   => self::staffBoard($lang, $text),
                'passengers'    => self::passengers($who, $lang, $text),
                'pending'       => self::pending($lang),
                default         => null,
            };
        } catch (Throwable $e) {
            Logger::warning('WaLocal ' . $intent . ' failed: ' . $e->getMessage(), [], 'whatsapp');
            return null;                          // the assistant still answers
        }
        if ($r === null || trim((string) ($r['text'] ?? '')) === '') {
            return null;
        }
        self::remember($digits, $text, (string) $r['text']);
        self::log($digits, $intent, $role, $t0);
        $out = ['text' => (string) $r['text'], 'media' => $r['media'] ?? null, 'ui' => $r['ui'] ?? null, 'intent' => $intent];
        // 9 Oct 2026: the same question at the same hour on 3 days -> offer to send it daily (includes/wahabit.php).
        try {
            require_once INCLUDE_PATH . '/wahabit.php';
            $offer = WaHabit::note((string) ($who['intl'] ?? ''), $role, $intent, $text, $lang);
            if ($offer !== null) {
                $out['text'] .= $offer['line'];
                if ($out['ui'] === null) {
                    $out['ui'] = $offer['ui'] + ['body' => $out['text']];
                }
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * The website chat (api/ai-chat.php) asks the same brain first: WHO is
     * the signed-in session (AiTools::whoIsWeb), so a guest gets the public
     * answers and the office its summaries, without an AI key.
     *
     * @param array<string,mixed> $ctx AiTools::whoIsWeb()
     * @return array{text: string, media: ?string, intent: string}|null
     */
    public static function answerWeb(array $ctx, string $text, string $lang = ''): ?array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 400 || !self::enabled() || preg_match('/shg[-\s]?\d{4}[-\s]?\d{3,}/iu', $text) === 1) {
            return null;
        }
        $role    = (string) ($ctx['role'] ?? 'customer');
        $isStaff = in_array($role, ['staff', 'admin'], true);
        $phone   = (string) ($ctx['phone'] ?? '');
        $from    = $phone !== '' ? '+91' . $phone : '';
        $who     = ['role' => $role, 'adminId' => (int) ($ctx['adminId'] ?? 0), 'scopeAdminId' => $ctx['scopeAdminId'] ?? null,
                    'name' => (string) ($ctx['name'] ?? ''), 'phone' => $phone, 'intl' => $phone !== '' ? '91' . $phone : ''];
        require_once INCLUDE_PATH . '/ticketbot.php';
        $t      = ' ' . self::norm($text) . ' ';
        $intent = self::intentOf($t, $text, $role);
        if ($intent === '' || in_array($intent, ['my_ticket_png'], true)) {
            return null;                          // the picture needs a WhatsApp number
        }
        $lang = in_array($lang, ['ne', 'hi', 'en', 'gu'], true) ? $lang : self::lang($text, $isStaff);
        try {
            $r = match ($intent) {
                'help'         => self::help($role, $lang),
                'timetable'    => self::timetable($lang),
                'company_info' => self::companyInfo($lang),
                'office_map'   => self::officeMap($lang),
                'agent_info'   => self::agentInfo($who, $lang, $text),
                'seats_left'   => self::seatsLeft($text, $lang, $isStaff),
                'bus_live'     => self::busLive($from, $who, $lang),
                'bus_crew'     => self::busCrew($from, $who, $lang, $text),
                'pay_how'      => self::payHow($from, $lang),
                'refund_rule'  => self::refundRule($from, $lang),
                'nepal_office' => self::nepalOffice($lang),
                'summary'      => self::summary($who, $lang),
                'staff_board'  => self::staffBoard($lang, $text),
                'passengers'   => self::passengers($who, $lang, $text),
                'pending'      => self::pending($lang),
                default        => null,
            };
        } catch (Throwable $e) {
            return null;
        }
        if ($r === null || trim((string) ($r['text'] ?? '')) === '') {
            return null;
        }
        return ['text' => (string) $r['text'], 'media' => $r['media'] ?? null, 'intent' => $intent];
    }

    /* =================================================================
     *  Which question is it
     * ================================================================= */

    private static function intentOf(string $t, string $raw, string $role): string
    {
        $isStaff = in_array($role, ['staff', 'admin'], true);
        $p       = TicketBot::parse($raw);
        $seats   = (int) ($p['seats'] ?? 0);
        $booking = $seats > 0 && ((string) ($p['date'] ?? '') !== '' || (string) ($p['direction'] ?? '') !== '');
        /* "malai 2 seat chahiyo" / "2 seat book garidinus" is an ORDER for the
           sale engine, never a seats-left question — even with no date yet. */
        $request = $seats > 0 && preg_match('/chahi|chaiy|chahie|chaiye|dinus|dinu\b|book|garide|katide|kaat|\bkato\b|\bkat\b|चाहि|चाहिए|दिनु|बुक|काट/u', $t) === 1;

        $bare = trim(preg_replace('/[\s\p{P}]+/u', ' ', $t) ?? $t);
        if (self::has($t, self::HELP) && mb_strlen($bare) <= 30) {
            return 'help';
        }
        if (self::has($t, self::TIMETABLE)) {
            return 'timetable';
        }
        /* The company, the people and the places (9 Oct 2026, owner: "CEO ko
           naam, agent ko naam / code / number, office ko address, Google map
           link - sabai share garna sakos"). An agent code (SHG-0027) names
           an agent; "office kaha / map" is a place question, never the bus. */
        $complaint = preg_match('/galat|galti|problem|samasya|cancel|refund|firta|paisa|payment|गलत|समस्या|रद्द|पैसा/u', $t) === 1;
        /* 15 Oct 2026 (owner: "AI le agent / counter haru ko data herdeos"): the
           office asking about its sellers - "agent report", "counter ko hisab",
           "sabai agent kati becheko", "agent cash baki" - gets every agent and
           counter from the books, today or this month. Office numbers only. */
        if ($role === 'admin'
            && preg_match('/agent|ejent|counter|kaunter|seller|एजेन्ट|एजेंट|काउन्टर|काउंटर/u', $t) === 1
            && preg_match('/report|hisab|hisaab|bikri|becheko|sale|kati|kitna|collection|cash|nagad|commission|kamisan|baki|baaki|month|mahina|aaja|aaj|today|रिपोर्ट|हिसाब|बिक्री|कति|कितना|नगद|कमिसन|कमीशन|बाँकी|बाकी|महिना|महीना|आज/u', $t) === 1
            && preg_match('/(?<![a-z\d])shg\s?-?\s?0*\d{1,4}(?![\d-])/u', $t) !== 1) {
            return 'staff_board';
        }
        if (preg_match('/(?<![a-z\d])shg\s?-?\s?0*\d{1,4}(?![\d-])/u', $t) === 1 && !$complaint) {
            return 'agent_info';
        }
        if (self::has($t, self::AGENT) && !$complaint && (mb_strlen($bare) <= 60
                || preg_match('/code|number|list|kun|ko ho|kasko|naam|name|कुन|कसको|नाम|नम्बर|नंबर|सूची|लिस्ट/u', $t) === 1)) {
            return 'agent_info';
        }
        if (self::has($t, self::OFFICE_MAP) && preg_match('/bus kaha|bus kata|bus kahan|gadi kaha|gadi kata|बस कहाँ|बस कहां|गाडी कहाँ/u', $t) !== 1) {
            return 'office_map';
        }
        if (self::has($t, self::COMPANY)) {
            return 'company_info';
        }
        if (self::hasWord($t, self::TICKET_PNG) && !$booking && !$request) {
            return 'my_ticket_png';
        }
        if (self::has($t, self::CREW)) {
            return 'bus_crew';
        }
        if (self::hasWord($t, self::SEATS) && !$request && !($booking && !preg_match('/\?|\bcha\b|\bxa\b|\bhai\b|\bmilcha\b|\bmilega\b|छ|है/u', $t))) {
            return 'seats_left';
        }
        if (self::isLive($t)) {
            return 'bus_live';
        }
        if (self::has($t, self::NEPAL_OFFICE)) {
            return 'nepal_office';
        }
        if (self::has($t, self::REFUND) && !self::has($t, self::IMPERATIVE)
            && preg_match('/galat|galti|problem|samasya|wrong|mistake|गलत|गल्ती|समस्या/u', $t) !== 1) {
            return 'refund_rule';          // a complaint about money is for a person
        }
        if (self::has($t, self::PAY) && !$booking) {
            return 'pay_how';
        }
        if ($isStaff) {
            if ($role === 'admin' && self::has($t, self::PENDING)) {
                return 'pending';
            }
            if (self::has($t, self::PASSENGERS)) {
                return 'passengers';
            }
            if (self::has($t, self::SUMMARY)) {
                // The office chart (WaFaq) stays the report when it is switched on.
                if ($role === 'admin' && Settings::getBool('wa_report_chart_on', false)
                    && preg_match('/\breport\b|रिपोर्ट/u', $t) === 1) {
                    return '';
                }
                return 'summary';
            }
        }
        return '';
    }

    /** "bus kaha pugyo", "location", "track" — but not a timing question ("kahile chhutcha"). */
    private static function isLive(string $t): bool
    {
        if (!self::has($t, self::LIVE_WORDS)) {
            return false;
        }
        // "bus live" / "location" / "track" / "pugyo" are unambiguous; "eta" needs the bus.
        if (preg_match('/(?<![\p{L}])(location|live|track|tracking|gps|pugyo|pahunchi|लोकेसन|लोकेशन|ट्र्याक|ट्रैक|पुग्यो|पहुँची|पहुंची)(?![\p{L}])/u', $t) === 1) {
            return true;
        }
        return preg_match('/bus|gadi|gaadi|बस|गाडी|गाड़ी|બસ/u', $t) === 1;
    }

    /* =================================================================
     *  Answers
     * ================================================================= */

    /** @return array{text:string, media:?string, ui:?array} */
    private static function help(string $role, string $lang): array
    {
        $co = Settings::getString('company_name', APP_NAME);
        if ($role === 'admin') {
            $lines = [self::t($lang, '🤖 ' . $co . ' सहायक — अफिसका लागि:', '🤖 ' . $co . ' सहायक — ऑफिस के लिए:', '🤖 ' . $co . ' assistant — for the office:'),
                self::t($lang, '• ticket — टिकट काट्ने', '• ticket — टिकट काटना', '• ticket — issue a ticket'),
                self::t($lang, '• hisab / aaja kati ticket — आजको बिक्री, पैसा, बस कति भरियो', '• hisab — आज की बिक्री, पैसा, बस कितनी भरी', '• hisab — today\'s sales, money, bus fill'),
                self::t($lang, '• yatri list [bholi] — यात्रु सूची (सिट, पिकअप, फोन)', '• yatri list — यात्री सूची', '• passenger list — who is on the bus'),
                self::t($lang, '• pending — भुक्तानी जाँच बाँकी', '• pending — भुगतान जाँच बाकी', '• pending — payments waiting'),
                self::t($lang, '• agent report [mahina] — सबै एजेन्ट/काउन्टरको बिक्री, नगद, कमिसन', '• agent report — सभी एजेंट/काउंटर की बिक्री, नकद, कमीशन', '• agent report [month] — every agent / counter: sales, cash, commission'),
                self::t($lang, '• khali seat [bholi / nepal] — कति सिट बाँकी', '• khali seat — कितनी सीट बाकी', '• khali seat — seats left'),
                self::t($lang, '• bus kaha — बस अहिले कहाँ छ (live)', '• bus kaha — बस अभी कहाँ है', '• bus kaha — where the bus is now'),
                self::t($lang, '• driver / bus number — आजका बस र चालक', '• driver — आज की बस और ड्राइवर', '• driver — today\'s buses and crew'),
                self::t($lang, '• timetable — सबै समय', '• timetable — सभी समय', '• timetable — every pickup time'),
                self::t($lang, '• report — ग्राफसहित रिपोर्ट · SHG-… — कुनै पनि टिकट', '• report — ग्राफ के साथ · SHG-… — कोई भी टिकट', '• report — chart · SHG-… — any ticket')];
        } elseif ($role === 'staff') {
            $lines = [self::t($lang, '🤖 ' . $co . ' सहायक — एजेन्ट / काउन्टरका लागि:', '🤖 ' . $co . ' सहायक — एजेंट / काउंटर के लिए:', '🤖 ' . $co . ' assistant — for agents / counters:'),
                self::t($lang, '• ticket — यात्रुको टिकट काट्ने', '• ticket — यात्री की टिकट काटना', '• ticket — sell a ticket'),
                self::t($lang, '• mero bikri / hisab — आजको बिक्री र कमिसन', '• mero bikri — आज की बिक्री और कमीशन', '• mero bikri — today\'s sales & commission'),
                self::t($lang, '• yatri list — मेरा यात्रु (सिट, पिकअप, फोन)', '• yatri list — मेरे यात्री', '• passenger list — my passengers'),
                self::t($lang, '• khali seat [bholi / nepal] — कति सिट बाँकी', '• khali seat — कितनी सीट बाकी', '• khali seat — seats left'),
                self::t($lang, '• bus kaha — बस अहिले कहाँ छ', '• bus kaha — बस अभी कहाँ है', '• bus kaha — where the bus is'),
                self::t($lang, '• driver / bus number — आजका बस र चालक', '• driver — आज की बस और ड्राइवर', '• driver — today\'s buses and crew'),
                self::t($lang, '• timetable · bhada · offer', '• timetable · kiraya · offer', '• timetable · fare · offer')];
        } else {
            $lines = [self::t($lang, '🤖 ' . $co . ' सहायक — म यी काम गर्छु:', '🤖 ' . $co . ' सहायक — मैं ये काम करता हूँ:', '🤖 ' . $co . ' assistant — I can:'),
                self::t($lang, '• ticket — टिकट बुक (मिति, सिट, पिकअप)', '• ticket — टिकट बुक', '• ticket — book a ticket'),
                self::t($lang, '• mero ticket — मेरा टिकट · ticket pathau — टिकटको फोटो फेरि', '• mera ticket — मेरी टिकटें · ticket bhejo — फोटो फिर से', '• my ticket — my tickets · send ticket — the picture again'),
                self::t($lang, '• bus kaha — बस अहिले कहाँ छ, कति बेर लाग्छ', '• bus kahan — बस अभी कहाँ है', '• bus kaha — where my bus is'),
                self::t($lang, '• khali seat [bholi] — कति सिट बाँकी', '• khali seat — कितनी सीट बाकी', '• khali seat — seats left'),
                self::t($lang, '• bhada · timetable · offer · office · nepal office', '• kiraya · timetable · offer · office · nepal office', '• fare · timetable · offer · office · nepal office'),
                self::t($lang, '• payment kasari — कसरी तिर्ने · refund — रद्द गर्दा कति फिर्ता', '• payment kaise — कैसे भरें · refund — रद्द पर कितना वापस', '• how to pay · refund rule')];
        }
        $lines[] = self::t($lang, 'कुनै पनि कुरा आफ्नै शब्दमा लेख्नुहोस् — म बुझ्छु।', 'कुछ भी अपने शब्दों में लिखिए — मैं समझ लेता हूँ।', 'Or just ask in your own words.');
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    /** The company and its CEO: names, numbers, registration, head office with a map link. */
    private static function companyInfo(string $lang): array
    {
        $c   = Settings::company();
        $ceo = null;
        foreach (Settings::nepalTeam() as $row) {
            if ((string) $row['key'] === 'ceo') {
                $ceo = $row;
                break;
            }
        }
        $ceoName  = trim((string) ($ceo['name'] ?? $c['operator']));
        $ceoPhone = trim((string) ($ceo['phone'] ?? ''));
        $ceoWa    = trim(Settings::getString('ceo_whatsapp', ''));
        $lines = ['🏢 ' . $c['name'] . ' · ' . $c['legal'] . ($lang === 'ne' ? ' (' . $c['legalNe'] . ')' : '')];
        $tag = trim(Settings::getString('company_tagline', ''));
        if ($tag !== '') {
            $lines[] = $tag;
        }
        $lines[] = '👤 ' . self::t($lang, 'CEO / सञ्चालक: ', 'CEO / संचालक: ', 'CEO / operator: ') . $ceoName
                 . ($ceoPhone !== '' ? ' · ' . $ceoPhone : '') . ($ceoWa !== '' && $ceoWa !== $ceoPhone ? ' · WhatsApp ' . $ceoWa : '');
        $lines[] = '📍 ' . self::t($lang, 'प्रधान कार्यालय: ', 'मुख्य कार्यालय: ', 'Head office: ') . ($lang === 'ne' ? $c['addressNe'] : $c['address']);
        $lines[] = '🗺 ' . self::mapsSearch($c['address']);
        $lines[] = '📞 ' . self::t($lang, 'अफिस: ', 'ऑफिस: ', 'Office: ') . $c['phone'] . ' · ✉️ ' . $c['email'] . ' · 🌐 https://' . $c['web'];
        if ($c['cin'] !== '') {
            $lines[] = 'CIN: ' . $c['cin'] . (trim(Settings::getString('company_gstin', '')) !== '' ? ' · GSTIN: ' . trim(Settings::getString('company_gstin', '')) : '');
        }
        $lines[] = self::t($lang, 'काउन्टर: ', 'काउंटर: ', 'Counters: ') . $c['counters'];
        $social = [];
        foreach (['company_facebook' => 'Facebook', 'company_instagram' => 'Instagram', 'company_youtube' => 'YouTube', 'company_tiktok' => 'TikTok', 'ceo_facebook' => 'CEO Facebook'] as $k => $label) {
            $v = trim(Settings::getString($k, ''));
            if ($v !== '') {
                $social[] = $label . ': ' . $v;
            }
        }
        if ($social !== []) {
            $lines[] = implode(' · ', $social);
        }
        $np = array_values(array_filter(Settings::nepalTeam(), static fn(array $r): bool => (string) $r['key'] !== 'ceo'));
        if ($np !== []) {
            $lines[] = '🇳🇵 ' . implode(' · ', array_map(static fn(array $r): string => ($lang === 'ne' ? (string) $r['roleNe'] : (string) $r['role']) . ' ' . (string) $r['name'] . ' ' . (string) $r['phone'], array_slice($np, 0, 3)));
        }
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    /** Where we are: head office, counters and every pickup with a Google Maps link. */
    private static function officeMap(string $lang): array
    {
        $c = Settings::company();
        $lines = ['📍 ' . self::t($lang, 'प्रधान कार्यालय', 'मुख्य कार्यालय', 'Head office') . ': ' . ($lang === 'ne' ? $c['addressNe'] : $c['address'])];
        $lines[] = '🗺 ' . self::mapsSearch($c['address']) . ' · 📞 ' . $c['phone'];
        // Counters (Settings counter_locations: CODE|Town — note, one per line).
        $counters = [];
        foreach (preg_split('/\r\n|\r|\n|\\\\n/', Settings::getString('counter_locations', '')) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = array_map('trim', explode('|', $line, 2));
            $town  = $parts[1] ?? $parts[0];
            if ($town !== '') {
                $counters[] = $town;
            }
        }
        if ($counters !== []) {
            $lines[] = '';
            $lines[] = self::t($lang, '🏪 काउन्टर:', '🏪 काउंटर:', '🏪 Counters:');
            foreach ($counters as $town) {
                $lines[] = '• ' . $town . ' · ' . self::mapsSearch($c['name'] . ' ' . preg_replace('/\s+[—-].*$/u', '', $town));
            }
            $lines[] = self::t($lang, 'नम्बर: ', 'नंबर: ', 'Numbers: ') . $c['counters'];
        }
        // Pickups with coordinates: a directions link each (same as the ticket's map QR).
        $seen = [];
        $pick = [];
        foreach (Database::fetchAll(
            "SELECT s.stop_name, s.landmark, s.stop_time, s.latitude, s.longitude FROM route_stops s JOIN routes r ON r.id = s.route_id
              WHERE r.is_active = 1 AND s.stop_type = 'boarding' AND s.is_active = 1 AND s.latitude IS NOT NULL AND s.longitude IS NOT NULL
              ORDER BY r.sort_order, s.sort_order, s.id") as $s) {
            $k = Boarding::townKey((string) $s['stop_name']);
            if ($k === '' || isset($seen[$k]) || (float) $s['latitude'] == 0.0) {
                continue;
            }
            $seen[$k] = true;
            $pick[] = '• ' . self::short((string) $s['stop_name']) . ((string) ($s['landmark'] ?? '') !== '' ? ' (' . $s['landmark'] . ')' : '')
                    . ((string) ($s['stop_time'] ?? '') !== '' ? ' ' . self::clock((string) $s['stop_time']) : '')
                    . ' · https://www.google.com/maps/dir/?api=1&destination=' . round((float) $s['latitude'], 6) . ',' . round((float) $s['longitude'], 6);
        }
        if ($pick !== []) {
            $lines[] = '';
            $lines[] = self::t($lang, '🚏 बस चढ्ने ठाउँ (नक्सा):', '🚏 बस चढ़ने की जगह (नक्शा):', '🚏 Pickup points (map):');
            $lines = array_merge($lines, array_slice($pick, 0, 12));
        }
        $np = Settings::nepalTeam();
        if ($np !== []) {
            $lines[] = '';
            $lines[] = '🇳🇵 ' . self::t($lang, 'नेपाल / रुपैडिहा: ', 'नेपाल / रुपैडिहा: ', 'Nepal / Rupaidiha: ')
                     . implode(' · ', array_map(static fn(array $r): string => (string) $r['name'] . ' ' . (string) $r['phone'], array_slice($np, 0, 3)));
        }
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    /**
     * Agents: a code (SHG-0027) or a name names one agent — name, code,
     * number, counter / town (owner: "agent ko naam, code, number share
     * garna sakos"); "agent list" gives the office every active agent and
     * a customer the public directory.
     */
    private static function agentInfo(array $who, string $lang, string $text): array
    {
        require_once INCLUDE_PATH . '/agentwallet.php';
        $role    = (string) ($who['role'] ?? 'customer');
        $isStaff = in_array($role, ['staff', 'admin'], true);
        $office  = Settings::officePhone();
        $t       = mb_strtolower($text);

        $one = static function (array $a, bool $withPhone) use ($lang): string {
            $code = AgentWallet::agentCodeLabel((int) $a['id']);
            $area = trim((string) ($a['counter_name'] ?? ''));
            $addr = trim((string) ($a['address'] ?? ''));
            $ph   = trim((string) ($a['phone'] ?: ($a['display_phone'] ?? '')));
            return '👤 ' . trim((string) $a['full_name']) . ($code !== '' ? ' · ' . $code : '')
                 . ($withPhone && $ph !== '' ? ' · ' . $ph : '')
                 . ($area !== '' ? ' · ' . $area : '') . ($addr !== '' && $withPhone ? "\n   " . $addr : '')
                 . ((string) ($a['role'] ?? '') === 'counter' ? self::t($lang, ' (काउन्टर)', ' (काउंटर)', ' (counter)') : '');
        };
        $sql = "SELECT a.id, a.full_name, a.phone, a.role, p.display_phone, p.counter_name, p.address, p.public_listing, p.public_phone
                  FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
                 WHERE a.role IN ('agent', 'counter') AND a.is_active = 1";

        // A code in the message.
        if (preg_match('/(?<![a-z\d])shg\s?-?\s?0*(\d{1,4})(?![\d-])/u', $t, $m) === 1) {
            $adminId = AgentWallet::adminForAgentCode((int) $m[1]);
            $a = $adminId !== null ? Database::fetch($sql . ' AND a.id = :i', ['i' => $adminId]) : null;
            $label = 'SHG-' . str_pad($m[1], 4, '0', STR_PAD_LEFT);
            if ($a === null) {
                return ['text' => self::t($lang, $label . ' — यो code कुनै सक्रिय एजेन्टको छैन। अफिस: ' . $office,
                    $label . ' — यह code किसी सक्रिय एजेंट का नहीं है। ऑफिस: ' . $office, $label . ' — no active agent holds this code. Office: ' . $office), 'media' => null, 'ui' => null];
            }
            return ['text' => $one($a, true) . "\n" . self::t($lang, 'टिकट बुक गर्दा यो code लेख्नुहोस् — एजेन्टको कमिसन मिल्छ।',
                'टिकट बुक करते समय यह code लिखें — एजेंट को कमीशन मिलता है।', 'Give this code when you book — the agent is credited.'), 'media' => null, 'ui' => null];
        }

        // A name in the message (staff only: "agent dalbir ko number").
        if ($isStaff) {
            $words = array_values(array_filter(preg_split('/[^\p{L}]+/u', $t) ?: [], static fn(string $w): bool => mb_strlen($w) >= 3
                && !in_array($w, ['agent', 'agents', 'code', 'number', 'list', 'naam', 'name', 'kun', 'kasko', 'the', 'phone', 'sabai', 'sab', 'all', 'dekhau', 'pathau'], true)));
            foreach ($words as $w) {
                $hits = Database::fetchAll($sql . ' AND (LOWER(a.full_name) LIKE :w OR LOWER(a.username) LIKE :w2 OR LOWER(p.counter_name) LIKE :w3) ORDER BY a.id LIMIT 5',
                    ['w' => '%' . $w . '%', 'w2' => '%' . $w . '%', 'w3' => '%' . $w . '%']);
                if ($hits !== []) {
                    return ['text' => implode("\n", array_map(static fn(array $a): string => $one($a, true), $hits)), 'media' => null, 'ui' => null];
                }
            }
            // The whole list for the office; an agent sees their own card + the office numbers.
            if ($role === 'admin') {
                $rows = Database::fetchAll($sql . ' ORDER BY a.full_name LIMIT 40');
                $lines = ['👥 ' . self::t($lang, 'एजेन्ट / काउन्टर (' . count($rows) . ')', 'एजेंट / काउंटर (' . count($rows) . ')', 'Agents / counters (' . count($rows) . ')')];
                foreach ($rows as $a) {
                    $lines[] = $one($a, true);
                }
                $lines[] = appUrl('admin/agents.php');
                return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
            }
            $me = (int) ($who['adminId'] ?? 0) > 0 ? Database::fetch($sql . ' AND a.id = :i', ['i' => (int) $who['adminId']]) : null;
            return ['text' => ($me !== null ? $one($me, true) . "\n" : '') . self::t($lang, 'अफिस: ', 'ऑफिस: ', 'Office: ') . $office, 'media' => null, 'ui' => null];
        }

        // A customer: the public directory (agents who opted in), else how to reach one.
        require_once INCLUDE_PATH . '/agentapplications.php';
        $dir = AgentApplications::directory();
        $lines = [self::t($lang, '🤝 हाम्रा एजेन्ट / साझेदार:', '🤝 हमारे एजेंट / पार्टनर:', '🤝 Our agents / partners:')];
        foreach (array_slice($dir, 0, 12) as $d) {
            $lines[] = '• ' . (string) $d['name'] . ((string) $d['area'] !== '' ? ' · ' . $d['area'] : '') . ' · ' . (string) $d['phone'] . (!empty($d['verified']) ? ' ✅' : '');
        }
        if (count($lines) === 1) {
            $lines[] = self::t($lang, 'एजेन्टको code (SHG-0027 जस्तो) पठाउनुहोस्, म उसको नाम र नम्बर दिन्छु।', 'एजेंट का code (जैसे SHG-0027) भेजिए, मैं नाम और नंबर दूँगा।', 'Send an agent code (like SHG-0027) and I will give the name and number.');
        }
        $lines[] = self::t($lang, 'एजेन्ट बन्न: https://' . Settings::getString('company_web', 'shreehariglobal.in') . '/agent-signup.php · अफिस: ' . $office,
            'एजेंट बनने के लिए: https://' . Settings::getString('company_web', 'shreehariglobal.in') . '/agent-signup.php · ऑफिस: ' . $office,
            'Become an agent: https://' . Settings::getString('company_web', 'shreehariglobal.in') . '/agent-signup.php · Office: ' . $office);
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    private static function mapsSearch(string $query): string
    {
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim(preg_replace('/\s+/u', ' ', $query) ?? $query));
    }

    /** Every pickup of every active bus with its time, and the arrival next day. */
    private static function timetable(string $lang): array
    {
        $lines = [self::t($lang, '🕒 समय तालिका (हरेक दिन)', '🕒 समय सारणी (रोज़)', '🕒 Timetable (daily)')];
        $n = 0;
        foreach (Database::fetchAll('SELECT id, from_city, to_city, dep_time, arr_time, day_offset FROM routes WHERE is_active = 1 ORDER BY sort_order, dep_time') as $r) {
            $stops = [];
            foreach (Database::fetchAll(
                "SELECT stop_name, stop_time FROM route_stops WHERE route_id = :r AND stop_type = 'boarding'" . Boarding::activeSql() . ' ORDER BY sort_order, id',
                ['r' => (int) $r['id']]) as $s) {
                if ((string) ($s['stop_time'] ?? '') === '') {
                    continue;
                }
                $stops[] = self::short((string) $s['stop_name']) . ' ' . self::clock((string) $s['stop_time']);
            }
            if ($stops === []) {
                continue;
            }
            $n++;
            $arr = self::clock((string) $r['arr_time']);
            $day = (int) ($r['day_offset'] ?? 1);
            $lines[] = '';
            $lines[] = '🚌 ' . trim((string) $r['from_city']) . ' → ' . trim((string) $r['to_city']);
            $lines[] = self::t($lang, 'चढ्ने: ', 'चढ़ना: ', 'Board: ') . implode(' · ', $stops);
            $lines[] = self::t($lang,
                'पुग्ने: ' . trim((string) $r['to_city']) . ' ' . ($day >= 1 ? 'भोलिपल्ट ' : '') . $arr,
                'पहुँचना: ' . trim((string) $r['to_city']) . ' ' . ($day >= 1 ? 'अगले दिन ' : '') . $arr,
                'Arrive: ' . trim((string) $r['to_city']) . ' ' . ($day >= 1 ? 'next day ' : '') . $arr);
        }
        if ($n === 0) {
            return ['text' => '', 'media' => null, 'ui' => null];
        }
        $lines[] = '';
        $lines[] = self::t($lang, 'चढ्ने ठाउँमा ६० मिनेट अगाडि पुग्नुहोस्। बुक गर्न "ticket" लेख्नुहोस्।',
            'चढ़ने की जगह 60 मिनट पहले पहुँचिए। बुक करने के लिए "ticket" लिखें।', 'Please reach the pickup 60 minutes early. Write "ticket" to book.');
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    /**
     * Free berths for the day / direction they named (today by default,
     * tomorrow once today's bus has left). Staff get both days, both ways.
     */
    private static function seatsLeft(string $text, string $lang, bool $isStaff): array
    {
        require_once INCLUDE_PATH . '/quickticket.php';
        require_once INCLUDE_PATH . '/seats.php';
        $p    = TicketBot::parse($text);
        $date = Security::isValidDate((string) ($p['date'] ?? '')) ? (string) $p['date'] : '';
        $dir  = (string) ($p['direction'] ?? '');
        $routes = array_values(array_filter(QuickTicket::routes(), static fn(array $r): bool => $dir === '' || (string) $r['direction'] === $dir));
        if ($routes === []) {
            return ['text' => '', 'media' => null, 'ui' => null];
        }
        $days = $date !== '' ? [$date] : ($isStaff ? [todayISO(), date('Y-m-d', strtotime('+1 day'))] : [todayISO()]);
        $lines = [self::t($lang, '💺 खाली सिट', '💺 खाली सीट', '💺 Seats left')];
        $media = null;
        $firstSid = 0;
        foreach ($days as $d) {
            foreach ($routes as $r) {
                $rid = (int) $r['id'];
                // Today's bus already gone (customer view): show tomorrow instead.
                $dep = strtotime($d . ' ' . (string) ($r['dep_time'] ?? '00:00:00'));
                $when = $d;
                if ($date === '' && !$isStaff && $dep !== false && $dep < time() - 1800) {
                    $when = date('Y-m-d', strtotime('+1 day', strtotime($d)));
                }
                try {
                    $sch = Seats::scheduleFor($rid, $when);
                } catch (Throwable $e) {
                    continue;
                }
                if (!empty($sch['is_blocked']) || in_array((string) ($sch['status'] ?? ''), ['cancelled'], true)) {
                    $lines[] = formatDate($when, 'D, j M') . ' · ' . trim((string) $r['from_city']) . ' → ' . trim((string) $r['to_city']) . ': '
                             . self::t($lang, 'बस छैन', 'बस नहीं', 'no bus');
                    continue;
                }
                $av = Seats::availabilityForSchedule((int) $sch['id']);
                $free  = (int) ($av['availableCount'] ?? count((array) ($av['available'] ?? [])));
                $total = (int) ($av['total'] ?? 0);
                $sold  = count((array) ($av['booked'] ?? []));
                $lines[] = '📅 ' . formatDate($when, 'D, j M') . ' · ' . trim((string) $r['from_city']) . ' → ' . trim((string) $r['to_city'])
                         . ' (' . self::clock((string) ($sch['dep_time_override'] ?? $r['dep_time'] ?? '')) . ')';
                $lines[] = '   ' . self::t($lang,
                    $total . ' मध्ये ' . $free . ' सिट खाली' . ($isStaff ? ' · ' . $sold . ' बिकेको' : ''),
                    $total . ' में से ' . $free . ' सीट खाली' . ($isStaff ? ' · ' . $sold . ' बिकी' : ''),
                    $free . ' of ' . $total . ' free' . ($isStaff ? ' · ' . $sold . ' sold' : ''));
                if ($firstSid === 0) {
                    $firstSid = (int) $sch['id'];
                }
            }
        }
        if (count($lines) === 1) {
            return ['text' => '', 'media' => null, 'ui' => null];
        }
        if ($firstSid > 0 && Settings::getBool('wa_seat_photo_on', false)) {
            try {
                require_once INCLUDE_PATH . '/seatmappng.php';
                $media = SeatMapPng::url($firstSid);
            } catch (Throwable $e) {
                $media = null;
            }
        }
        $lines[] = '';
        $lines[] = $isStaff
            ? self::t($lang, 'काट्न "ticket" लेख्नुहोस्। अर्को मिति: "khali seat 15 oct"', 'काटने के लिए "ticket" लिखें। दूसरी तारीख: "khali seat 15 oct"', 'Write "ticket" to sell. Another date: "khali seat 15 oct"')
            : self::t($lang, 'बुक गर्न "ticket" लेख्नुहोस्, वा मिति र कति जना भन्नुहोस्।', 'बुक करने के लिए "ticket" लिखें, या तारीख और कितने लोग बताइए।', 'Write "ticket" to book, or tell me the date and how many.');
        return ['text' => implode("\n", $lines), 'media' => $media, 'ui' => null];
    }

    /** Where the bus is: the passenger's own trip, or every running trip for staff. */
    private static function busLive(string $fromRaw, array $who, string $lang): array
    {
        require_once INCLUDE_PATH . '/etaalerts.php';
        require_once INCLUDE_PATH . '/tripstatus.php';
        require_once INCLUDE_PATH . '/whereis.php';
        if (!class_exists('Ticket')) {
            foreach (['qr', 'pdf', 'ticket'] as $f) {
                require_once INCLUDE_PATH . '/' . $f . '.php';
            }
        }
        $isStaff = in_array((string) ($who['role'] ?? ''), ['staff', 'admin'], true);
        $now = time();

        if (!$isStaff) {
            $b = self::ownTrip(normalisePhone($fromRaw), -1, 1);
            if ($b !== null) {
                $st = WhereIs::status($b, $now);
                $lines = ['🚌 ' . (string) ($st['route'] ?? '') . ' · ' . formatDate((string) ($st['date'] ?? ''), 'D, j M') . ' · ' . (string) $b['pnr']];
                if ((string) ($st['stop'] ?? '') !== '') {
                    $lines[] = self::t($lang, 'तपाईंको पिकअप: ', 'आपका पिकअप: ', 'Your pickup: ') . $st['stop'] . ((string) ($st['stopTime'] ?? '') !== '' ? ' ' . self::clock((string) $st['stopTime']) : '');
                }
                if ((int) ($st['delayMin'] ?? 0) > 0) {
                    $lines[] = '⏱ ' . self::t($lang, (int) $st['delayMin'] . ' मिनेट ढिलो', (int) $st['delayMin'] . ' मिनट देरी', (int) $st['delayMin'] . ' min late')
                             . ((string) ($st['delayNote'] ?? '') !== '' ? ' — ' . $st['delayNote'] : '');
                }
                if (!empty($st['live'])) {
                    if (!empty($st['onBoard'])) {
                        // 21 Oct 2026: past the pickup and plausibly aboard - what track.php says.
                        $lines[] = self::onWayLine($st, $lang);
                    } elseif (!empty($st['passed'])) {
                        $lines[] = self::t($lang, '📍 बस तपाईंको चढ्ने ठाउँ पार गरिसक्यो।', '📍 बस आपकी चढ़ने की जगह पार कर चुकी है।', '📍 The bus has already passed your pickup.');
                    } elseif ($st['etaMin'] !== null) {
                        $lines[] = self::t($lang,
                            '📍 बस ' . $st['km'] . ' km टाढा छ — लगभग ' . (int) $st['etaMin'] . ' मिनेटमा तपाईंको ठाउँमा पुग्छ।',
                            '📍 बस ' . $st['km'] . ' km दूर है — लगभग ' . (int) $st['etaMin'] . ' मिनट में आपकी जगह पहुँचेगी।',
                            '📍 The bus is ' . $st['km'] . ' km away — about ' . (int) $st['etaMin'] . ' min to your pickup.');
                    } else {
                        $lines[] = self::t($lang, '📍 बस बाटोमा छ (live)।', '📍 बस रास्ते में है (live)।', '📍 The bus is on the road (live).');
                    }
                    if (($st['kmh'] ?? null) !== null) {
                        $lines[] = self::t($lang, 'गति: ', 'गति: ', 'Speed: ') . (int) $st['kmh'] . ' km/h';
                    }
                } elseif (!empty($st['lastKnown'])) {
                    $lines[] = self::t($lang, '📍 अन्तिम देखिएको: ', '📍 आख़िरी बार देखी गई: ', '📍 Last seen: ') . (string) ($st['label'] ?? '')
                             . self::t($lang, ' (अहिले live छैन)', ' (अभी live नहीं)', ' (not live right now)');
                } else {
                    $lines[] = '📍 ' . self::stateLine((string) ($st['state'] ?? ''), (string) ($st['label'] ?? ''), $lang, !empty($st['pickupPassed']));
                }
                $lines[] = '🗺 ' . Ticket::trackUrl((string) $b['pnr']);
                return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
            }
        }

        // Staff, or a customer with no ticket today: every trip on the road.
        $trips = EtaAlerts::candidates($now);
        $lines = [self::t($lang, '🚌 बस अहिले', '🚌 बस अभी', '🚌 Buses now')];
        if ($trips === []) {
            $lines[] = self::t($lang, 'अहिले कुनै बस बाटोमा छैन।', 'अभी कोई बस रास्ते में नहीं है।', 'No bus is on the road right now.');
            foreach (Database::fetchAll('SELECT from_city, to_city, dep_time FROM routes WHERE is_active = 1 ORDER BY sort_order, dep_time') as $r) {
                $lines[] = '• ' . trim((string) $r['from_city']) . ' → ' . trim((string) $r['to_city']) . ' ' . self::clock((string) $r['dep_time']);
            }
        } else {
            foreach ($trips as $tr) {
                $trip = TripStatus::editableFor((int) $tr['id'], $now);
                $head = '• ' . trim((string) $tr['from_city']) . ' → ' . trim((string) $tr['to_city']) . ' · ' . formatDate((string) $tr['travel_date'], 'j M')
                      . ' ' . self::clock((string) $tr['dep_time']) . ' — ' . self::stateLine((string) ($trip['state'] ?? ''), (string) ($trip['label'] ?? ''), $lang, true);
                if ($isStaff) {
                    $bus = Database::fetch('SELECT b.bus_number, b.bus_name FROM schedules s JOIN buses b ON b.id = s.bus_id WHERE s.id = :s', ['s' => (int) $tr['id']]);
                    if ($bus !== null) {
                        $head .= ' · ' . (string) $bus['bus_number'];
                    }
                }
                $lines[] = $head;
                if ((int) ($tr['delay_minutes'] ?? 0) > 0) {
                    $lines[] = '   ⏱ ' . self::t($lang, (int) $tr['delay_minutes'] . ' मिनेट ढिलो', (int) $tr['delay_minutes'] . ' मिनट देरी', (int) $tr['delay_minutes'] . ' min late');
                }
                /* 20 Oct 2026: each coach answers from its own driver-app fix
                   (fixForTrip keeps the one-coach rule for the shared key). */
                [$fix, $why] = EtaAlerts::fixForTrip((int) $tr['id'], (int) $tr['route_id'], $now);
                if ($fix !== null) {
                    $etas = EtaAlerts::stopEtas((int) $tr['route_id'], $fix, $now);
                    if ($etas !== []) {
                        uasort($etas, static fn(array $a, array $b): int => $a['km'] <=> $b['km']);
                        $next = reset($etas);
                        $lines[] = '   📍 ' . self::t($lang,
                            self::short((string) $next['name']) . ' भन्दा ' . $next['km'] . ' km अगाडि · लगभग ' . (int) $next['eta'] . ' मिनेट',
                            self::short((string) $next['name']) . ' से ' . $next['km'] . ' km पहले · लगभग ' . (int) $next['eta'] . ' मिनट',
                            $next['km'] . ' km before ' . self::short((string) $next['name']) . ' · about ' . (int) $next['eta'] . ' min')
                            . (($fix['kmh'] ?? null) !== null ? ' · ' . (int) round((float) $fix['kmh']) . ' km/h' : '');
                    } else {
                        $lines[] = '   📍 ' . self::t($lang, 'GPS यो बाटोमा छैन', 'GPS इस रूट पर नहीं', 'GPS is not on this route');
                    }
                } elseif ($fix === null) {
                    $lines[] = '   📍 ' . self::t($lang, 'live position छैन (' . $why . ')', 'live position नहीं (' . $why . ')', 'no live position (' . $why . ')');
                }
            }
        }
        if ($isStaff) {
            $lines[] = '🗺 ' . appUrl('admin/map.php');
        } else {
            $lines[] = self::t($lang, 'आफ्नो बसको live ठाउँ: टिकट भएको नम्बरबाट "bus kaha" लेख्नुहोस्, वा टिकटको TRACK लिंक खोल्नुहोस्।',
                'अपनी बस की live जगह: टिकट वाले नंबर से "bus kahan" लिखें, या टिकट का TRACK लिंक खोलें।',
                'Your own bus: write "bus kaha" from the number on the ticket, or open the TRACK link on it.');
        }
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    /** Bus number / name and the crew for today's (or the named day's) buses. */
    private static function busCrew(string $fromRaw, array $who, string $lang, string $text): array
    {
        $role    = (string) ($who['role'] ?? 'customer');
        $isStaff = in_array($role, ['staff', 'admin'], true);
        $office  = Settings::officePhone();
        $p       = TicketBot::parse($text);
        $date    = Security::isValidDate((string) ($p['date'] ?? '')) ? (string) $p['date'] : '';

        if (!$isStaff) {
            $b = self::ownTrip(normalisePhone($fromRaw), -1, 7);
            if ($b !== null) {
                $leg = Database::fetch(
                    "SELECT bl.travel_date, s.id AS sid, s.driver_id, r.from_city, r.to_city, r.crew_name, r.crew_phone, bu.bus_number, bu.bus_name
                       FROM booking_legs bl JOIN schedules s ON s.id = bl.schedule_id JOIN routes r ON r.id = s.route_id
                       LEFT JOIN buses bu ON bu.id = s.bus_id
                      WHERE bl.booking_id = :b ORDER BY (bl.leg_type = 'outbound') DESC, bl.id LIMIT 1", ['b' => (int) $b['id']]);
                if ($leg !== null) {
                    $lines = ['🚌 ' . trim((string) $leg['from_city']) . ' → ' . trim((string) $leg['to_city']) . ' · ' . formatDate((string) $leg['travel_date'], 'D, j M') . ' · ' . (string) $b['pnr']];
                    $lines[] = self::t($lang, 'बस: ', 'बस: ', 'Bus: ') . (trim((string) ($leg['bus_number'] ?? '')) !== '' ? $leg['bus_number'] . ' · ' . $leg['bus_name'] : self::t($lang, 'बस छुट्ने दिन तोकिन्छ', 'बस चलने के दिन तय होगी', 'assigned on the day'));
                    $crew = self::crewFor((int) $leg['sid'], (int) ($leg['driver_id'] ?? 0), (string) ($leg['crew_name'] ?? ''), (string) ($leg['crew_phone'] ?? ''));
                    $today = (string) $leg['travel_date'] === todayISO() || (string) $leg['travel_date'] === date('Y-m-d', strtotime('-1 day'));
                    if ($crew['name'] !== '' || $crew['phone'] !== '') {
                        if ($today && Settings::getBool('wa_driver_phone_on', true) && $crew['phone'] !== '') {
                            $lines[] = self::t($lang, 'चालक: ', 'ड्राइवर: ', 'Driver: ') . trim($crew['name'] . ' ' . $crew['phone']);
                        } else {
                            $lines[] = self::t($lang, 'चालक: ', 'ड्राइवर: ', 'Driver: ') . ($crew['name'] !== '' ? $crew['name'] : '—')
                                     . self::t($lang, ' (नम्बर यात्राको दिन दिइन्छ)', ' (नंबर यात्रा के दिन मिलेगा)', ' (number is shared on the travel day)');
                        }
                    }
                    $lines[] = self::t($lang, 'अफिस: ', 'ऑफिस: ', 'Office: ') . $office;
                    return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
                }
            }
            $lines = [self::t($lang, '🚌 हाम्रा बस (AC स्लिपर):', '🚌 हमारी बसें (AC स्लीपर):', '🚌 Our buses (AC sleeper):')];
            foreach (Database::fetchAll('SELECT bus_number, bus_name FROM buses WHERE is_active = 1 ORDER BY id LIMIT 6') as $bu) {
                $lines[] = '• ' . (string) $bu['bus_name'] . ' (' . (string) $bu['bus_number'] . ')';
            }
            $lines[] = self::t($lang, 'तपाईंको बस र चालकको नम्बर टिकट भएको नम्बरबाट सोध्नुहोस्। अफिस: ' . $office,
                'अपनी बस और ड्राइवर का नंबर टिकट वाले नंबर से पूछिए। ऑफिस: ' . $office,
                'Ask for your bus and driver from the number on your ticket. Office: ' . $office);
            return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
        }

        $days = $date !== '' ? [$date] : [todayISO(), date('Y-m-d', strtotime('+1 day'))];
        $lines = [self::t($lang, '🚌 बस र चालक', '🚌 बस और ड्राइवर', '🚌 Buses and crew')];
        foreach ($days as $d) {
            $rows = Database::fetchAll(
                "SELECT s.id, s.driver_id, s.status, COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.from_city, r.to_city, r.crew_name, r.crew_phone,
                        bu.bus_number, bu.bus_name
                   FROM schedules s JOIN routes r ON r.id = s.route_id LEFT JOIN buses bu ON bu.id = s.bus_id
                  WHERE s.travel_date = :d AND r.is_active = 1 AND s.is_blocked = 0 ORDER BY r.sort_order, s.slot", ['d' => $d]);
            if ($rows === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = '📅 ' . formatDate($d, 'D, j M');
            foreach ($rows as $r) {
                $crew = self::crewFor((int) $r['id'], (int) ($r['driver_id'] ?? 0), (string) ($r['crew_name'] ?? ''), (string) ($r['crew_phone'] ?? ''));
                $lines[] = '• ' . trim((string) $r['from_city']) . ' → ' . trim((string) $r['to_city']) . ' ' . self::clock((string) $r['dep_time'])
                         . ': ' . (trim((string) ($r['bus_number'] ?? '')) !== '' ? $r['bus_number'] . ' (' . $r['bus_name'] . ')' : self::t($lang, 'बस तोकिएको छैन', 'बस तय नहीं', 'no bus assigned'))
                         . ($crew['name'] !== '' || $crew['phone'] !== '' ? ' · ' . self::t($lang, 'चालक ', 'ड्राइवर ', 'driver ') . trim($crew['name'] . ' ' . $crew['phone']) : '')
                         . ((string) $r['status'] !== 'scheduled' ? ' · ' . (string) $r['status'] : '');
            }
        }
        if (count($lines) === 1) {
            $lines[] = self::t($lang, 'यो मितिको बस छैन।', 'इस तारीख की बस नहीं।', 'No bus on that date.');
        }
        $lines[] = '';
        $lines[] = self::t($lang, 'बस / चालक बदल्न: Admin → Schedule', 'बस / ड्राइवर बदलना: Admin → Schedule', 'Change bus / driver: Admin → Schedule');
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    /** The ticket picture again — the person is writing to us, so it goes free. */
    private static function myTicketPng(string $fromRaw, array $who, string $lang): array
    {
        if (!class_exists('Ticket')) {
            foreach (['qr', 'pdf', 'ticket'] as $f) {
                require_once INCLUDE_PATH . '/' . $f . '.php';
            }
        }
        $digits = normalisePhone($fromRaw);
        $rows = Database::fetchAll(
            "SELECT b.id, b.pnr, b.status, MIN(bl.travel_date) AS travel_date
               FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id
              WHERE (b.contact_phone = :p OR b.alt_phone = :p2) AND b.status IN ('confirmed', 'completed')
                AND bl.travel_date >= :d
              GROUP BY b.id, b.pnr, b.status ORDER BY travel_date ASC, b.id DESC LIMIT 3",
            ['p' => $digits, 'p2' => $digits, 'd' => date('Y-m-d', strtotime('-1 day'))]
        );
        if ($rows === []) {
            $ui = ['kind' => 'buttons', 'buttons' => [['id' => 'shg:mero ticket ' . $lang, 'title' => self::t($lang, '📋 मेरो टिकट', '📋 मेरा टिकट', '📋 My ticket')]]];
            $text = self::t($lang, 'यो नम्बरमा आउँदो पक्का टिकट भेटिएन। टिकटको PNR (SHG-…) पठाउनुहोस्, म फोटो पठाइदिन्छु।',
                'इस नंबर पर आगे की कोई पक्की टिकट नहीं मिली। टिकट का PNR (SHG-…) भेजिए, मैं फोटो भेज दूँगा।',
                'No confirmed coming ticket on this number. Send the PNR (SHG-…) and I will send the picture.');
            return ['text' => $text, 'media' => null, 'ui' => $ui + ['body' => $text]];
        }
        if (count($rows) === 1) {
            $r = $rows[0];
            $text = '🎫 ' . (string) $r['pnr'] . ' · ' . formatDate((string) $r['travel_date'], 'D, j M')
                  . "\n" . self::t($lang, 'तपाईंको टिकट फेरि पठाइयो ✅', 'आपकी टिकट फिर भेजी गई ✅', 'Your ticket, sent again ✅');
            return ['text' => $text, 'media' => Ticket::imageUrl((string) $r['pnr']), 'ui' => null];
        }
        $buttons = [];
        $lines = [self::t($lang, 'कुन टिकट? थिच्नुहोस्:', 'कौन सी टिकट? दबाइए:', 'Which ticket? Tap one:')];
        foreach ($rows as $r) {
            $buttons[] = ['id' => 'shg:' . (string) $r['pnr'], 'title' => mb_substr(formatDate((string) $r['travel_date'], 'j M') . ' · ' . substr((string) $r['pnr'], -5), 0, 20)];
            $lines[]   = '• ' . (string) $r['pnr'] . ' · ' . formatDate((string) $r['travel_date'], 'D, j M');
        }
        $text = implode("\n", $lines);
        return ['text' => $text . "\n" . self::t($lang, '(PNR लेखे पनि हुन्छ)', '(PNR लिख भी सकते हैं)', '(or just write the PNR)'),
                'media' => null, 'ui' => ['kind' => 'buttons', 'body' => $text, 'buttons' => $buttons]];
    }

    /** How to pay — their own pending booking first (QR + link), else the ways. */
    private static function payHow(string $fromRaw, string $lang): array
    {
        $digits = normalisePhone($fromRaw);
        $co     = Settings::company();
        $upi    = trim(Settings::getString('upi_id', ''));
        $esewa  = trim(Settings::getString('esewa_id', ''));
        $cod    = Settings::getBool('allow_cod', true);
        $lines  = [];
        $media  = null;
        $pend   = $digits !== '' ? Database::fetch(
            "SELECT pnr, total_amount, currency FROM bookings WHERE (contact_phone = :p OR alt_phone = :p2) AND status = 'pending'
               AND created_at >= NOW() - INTERVAL 3 DAY ORDER BY id DESC LIMIT 1", ['p' => $digits, 'p2' => $digits]) : null;
        if ($pend !== null && (float) $pend['total_amount'] > 0) {
            $lines[] = '💳 ' . (string) $pend['pnr'] . ' · ' . self::t($lang, 'तिर्न बाँकी: ', 'भुगतान बाकी: ', 'Due: ') . inr((float) $pend['total_amount']);
            if ($upi !== '') {
                $media   = appUrl('pay-image.php?pnr=' . urlencode((string) $pend['pnr']));
                $lines[] = self::t($lang, 'माथिको QR स्क्यान गर्नुहोस् (रकम आफैं आउँछ), वा:', 'ऊपर का QR स्कैन कीजिए (रकम अपने आप आएगी), या:', 'Scan the QR above (amount filled in), or:');
            }
            $lines[] = appUrl('pay.php?pnr=' . urlencode((string) $pend['pnr']));
            $lines[] = self::t($lang, 'तिरेपछि screenshot यहीँ पठाउनुहोस् — अफिसले जाँचेर टिकट पठाउँछ।', 'भुगतान के बाद screenshot यहीं भेजिए — ऑफिस जाँच कर टिकट भेजेगा।', 'After paying, send the screenshot here — the office checks it and sends the ticket.');
        } else {
            $lines[] = self::t($lang, '💳 तिर्ने तरिका:', '💳 भुगतान के तरीके:', '💳 Ways to pay:');
            if ($upi !== '') {
                $lines[] = '• UPI: ' . $upi . self::t($lang, ' (GPay / PhonePe / Paytm) — बुक गरेपछि रकमसहितको QR आउँछ', ' (GPay / PhonePe / Paytm) — बुक करने के बाद रकम वाला QR आता है', ' (GPay / PhonePe / Paytm) — a QR with the amount comes after booking');
            }
            if ($esewa !== '') {
                $lines[] = '• eSewa: ' . $esewa;
            }
            if ($cod) {
                $lines[] = self::t($lang, '• बसमा / काउन्टरमा नगद — "ticket" लेखेर बुक गर्दा "pay cash" छान्नुहोस्', '• बस में / काउंटर पर नकद — "ticket" लिखकर बुक करते समय "pay cash" चुनें', '• Cash on the bus / at the counter — choose "pay cash" when you book with "ticket"');
            }
            $lines[] = self::t($lang, '• काउन्टर: ', '• काउंटर: ', '• Counters: ') . $co['counters'];
            $lines[] = self::t($lang, 'Online र counter मा भाडा उस्तै। बुक गर्न "ticket" लेख्नुहोस्।', 'Online और counter पर किराया एक जैसा। बुक करने के लिए "ticket" लिखें।', 'Same fare online and at the counter. Write "ticket" to book.');
        }
        return ['text' => implode("\n", $lines), 'media' => $media, 'ui' => null];
    }

    /** The published refund slabs — and what THEIR next ticket would get now. */
    private static function refundRule(string $fromRaw, string $lang): array
    {
        require_once INCLUDE_PATH . '/fare.php';
        $slabs = Fare::refundSlabs();   // D8: the one policy, highest threshold first
        $lines = [self::t($lang, '↩️ रद्द गर्दा फिर्ता (छुट्ने समय भन्दा अगाडि):', '↩️ रद्द करने पर वापसी (रवानगी से पहले):', '↩️ Refund on cancellation (before departure):')];
        $prevHrs = null;
        foreach ($slabs as $s) {
            $h = (int) $s['minHrs'];
            $pct = (int) $s['pct'];
            $span = $prevHrs === null ? $h . '+' : $h . '–' . $prevHrs;
            $lines[] = '• ' . self::t($lang, $span . ' घण्टा अगाडि: ' . $pct . '% फिर्ता', $span . ' घंटे पहले: ' . $pct . '% वापस', $span . ' h before: ' . $pct . '% back');
            $prevHrs = $h;
        }
        $b = self::ownTrip(normalisePhone($fromRaw), 0, 60);
        if ($b !== null) {
            $leg = Database::fetch(
                "SELECT bl.travel_date, COALESCE(s.dep_time_override, r.dep_time) AS dep_time FROM booking_legs bl
                   JOIN schedules s ON s.id = bl.schedule_id JOIN routes r ON r.id = s.route_id
                  WHERE bl.booking_id = :b ORDER BY bl.id LIMIT 1", ['b' => (int) $b['id']]);
            if ($leg !== null) {
                $rf = Fare::refundFor((float) $b['total_amount'], (string) $leg['travel_date'], (string) $leg['dep_time']);
                $lines[] = '';
                $lines[] = self::t($lang,
                    'तपाईंको ' . $b['pnr'] . ' (' . formatDate((string) $leg['travel_date'], 'j M') . ') अहिले रद्द गर्दा: ' . inr($rf['amount']) . ' फिर्ता (' . (int) $rf['percent'] . '%)',
                    'आपकी ' . $b['pnr'] . ' (' . formatDate((string) $leg['travel_date'], 'j M') . ') अभी रद्द करने पर: ' . inr($rf['amount']) . ' वापस (' . (int) $rf['percent'] . '%)',
                    'Your ' . $b['pnr'] . ' (' . formatDate((string) $leg['travel_date'], 'j M') . ') cancelled now: ' . inr($rf['amount']) . ' back (' . (int) $rf['percent'] . '%)');
            }
        }
        $lines[] = '';
        $lines[] = self::t($lang, 'रद्द गर्न: website → My ticket, वा अफिसमा फोन ' . Settings::officePhone() . '। फिर्ता अफिसले हातले मिलाउँछ।',
            'रद्द करने के लिए: website → My ticket, या ऑफिस को फोन ' . Settings::officePhone() . '। वापसी ऑफिस हाथ से करता है।',
            'To cancel: website → My ticket, or call the office ' . Settings::officePhone() . '. Refunds are settled by the office.');
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    private static function nepalOffice(string $lang): array
    {
        $rows = Settings::nepalTeam();
        $lines = [self::t($lang, '🇳🇵 नेपाल कार्यालय / सम्पर्क', '🇳🇵 नेपाल ऑफिस / संपर्क', '🇳🇵 Nepal office / contacts')];
        foreach ($rows as $r) {
            $lines[] = '• ' . ($lang === 'ne' ? (string) $r['roleNe'] : (string) $r['role']) . ': ' . (string) $r['name'] . ' ' . (string) $r['phone'];
        }
        if (count($lines) === 1) {
            $lines[] = Settings::company()['counters'];
        }
        $lines[] = self::t($lang, 'भारत अफिस: ', 'भारत ऑफिस: ', 'India office: ') . Settings::officePhone();
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    /** Staff: today in numbers. An agent sees only their own book. */
    private static function summary(array $who, string $lang): ?array
    {
        $role  = (string) ($who['role'] ?? '');
        $scope = $who['scopeAdminId'] ?? null;
        if (!in_array($role, ['staff', 'admin'], true)) {
            return null;
        }
        $today = todayISO();
        // Every placeholder once (a PDO named placeholder binds ONCE).
        $scopeSql = $scope !== null ? ' AND b.sold_by_admin_id = :a' : '';
        $scopeArg = $scope !== null ? ['a' => (int) $scope] : [];
        $tot = Database::fetch(
            "SELECT COUNT(*) n, COALESCE(SUM(b.total_amount),0) amt FROM bookings b
              WHERE b.status IN ('confirmed','completed') AND DATE(COALESCE(b.confirmed_at, b.created_at)) = :d" . $scopeSql,
            ['d' => $today] + $scopeArg) ?? ['n' => 0, 'amt' => 0];
        $tot['seats'] = (int) Database::scalar(
            "SELECT COALESCE(SUM(bl.seat_count),0) FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
              WHERE b.status IN ('confirmed','completed') AND DATE(COALESCE(b.confirmed_at, b.created_at)) = :d" . $scopeSql,
            ['d' => $today] + $scopeArg, 0);
        $lines = ['📊 ' . self::t($lang, 'आज ' . formatDate($today, 'D, j M'), 'आज ' . formatDate($today, 'D, j M'), 'Today ' . formatDate($today, 'D, j M'))
                . ($scope !== null ? ' · ' . (string) ($who['name'] ?? '') : '')];
        $lines[] = self::t($lang,
            'टिकट: ' . (int) $tot['n'] . ' · सिट: ' . (int) $tot['seats'] . ' · रकम: ' . inr((float) $tot['amt']),
            'टिकट: ' . (int) $tot['n'] . ' · सीट: ' . (int) $tot['seats'] . ' · रकम: ' . inr((float) $tot['amt']),
            'Tickets: ' . (int) $tot['n'] . ' · seats: ' . (int) $tot['seats'] . ' · amount: ' . inr((float) $tot['amt']));
        if ($scope !== null) {
            try {
                $comm = (float) Database::scalar(
                    "SELECT COALESCE(SUM(amount),0) FROM agent_ledger WHERE agent_admin_id = :a AND DATE(created_at) = :d AND account = 'commission' AND entry_type IN ('commission','commission_void')",
                    ['a' => (int) $scope, 'd' => $today], 0);
                if ($comm > 0) {
                    $lines[] = self::t($lang, 'कमिसन: ', 'कमीशन: ', 'Commission: ') . inr($comm);
                }
            } catch (Throwable $e) {
            }
            $pend = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :a AND status = 'pending'", ['a' => (int) $scope], 0);
            if ($pend > 0) {
                $lines[] = self::t($lang, 'भुक्तानी बाँकी: ' . $pend, 'भुगतान बाकी: ' . $pend, 'Payment pending: ' . $pend);
            }
            $lines[] = appUrl('admin/my-desk.php');
            return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
        }
        // Office: bus fill today and tomorrow, pending payments, drafts waiting.
        foreach ([todayISO(), date('Y-m-d', strtotime('+1 day'))] as $d) {
            $rows = Database::fetchAll(
                "SELECT s.id, r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) dep_time,
                        (SELECT COUNT(*) FROM booking_passengers bp JOIN booking_legs bl ON bl.id = bp.leg_id JOIN bookings b ON b.id = bl.booking_id
                          WHERE bl.schedule_id = s.id AND b.status IN ('confirmed','completed')) sold
                   FROM schedules s JOIN routes r ON r.id = s.route_id
                  WHERE s.travel_date = :d AND r.is_active = 1 AND s.is_blocked = 0 ORDER BY r.sort_order, s.slot", ['d' => $d]);
            foreach ($rows as $r) {
                $lines[] = '🚌 ' . formatDate($d, 'j M') . ' ' . trim((string) $r['from_city']) . ' → ' . trim((string) $r['to_city']) . ' ' . self::clock((string) $r['dep_time'])
                         . ': ' . (int) $r['sold'] . self::t($lang, ' सिट बिकेको', ' सीट बिकी', ' seats sold');
            }
        }
        $pend = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status = 'pending' AND created_at >= NOW() - INTERVAL 3 DAY", [], 0);
        $lines[] = self::t($lang, 'भुक्तानी जाँच बाँकी: ' . $pend, 'भुगतान जाँच बाकी: ' . $pend, 'Payments to verify: ' . $pend) . ($pend > 0 ? ' — "pending"' : '');
        $lines[] = appUrl('admin/');
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    /**
     * Office: every agent and counter from the books (15 Oct 2026). Today, or
     * this month when the question says month / mahina. Per seller: tickets,
     * seats, amount, cash taken; then what each OWES (cash to hand over) and is
     * OWED (commission due) from agent_ledger; then the counter desks.
     */
    private static function staffBoard(string $lang, string $text): array
    {
        require_once INCLUDE_PATH . '/agentwallet.php';
        $month = preg_match('/month|mahina|mahine|महिना|महीना|मास/ui', $text) === 1;
        $from  = $month ? date('Y-m-01') : todayISO();
        $to    = addDaysISO(todayISO(), 1);
        $range = ['f' => $from . ' 00:00:00', 't' => $to . ' 00:00:00'];
        $title = $month ? formatDate($from, 'M Y') : formatDate($from, 'D, j M');
        $lines = ['👥 ' . self::t($lang, 'एजेन्ट / काउन्टर — ', 'एजेंट / काउंटर — ', 'Agents / counters — ') . $title];

        $rows = Database::fetchAll(
            "SELECT a.id, a.full_name, a.role, COUNT(b.id) n, COALESCE(SUM(b.total_amount),0) amt,
                    COALESCE(SUM((SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id)),0) seats,
                    COALESCE(SUM(CASE WHEN b.is_cod = 1 OR EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.method = 'cash') THEN b.total_amount ELSE 0 END),0) cash
               FROM bookings b JOIN admins a ON a.id = b.sold_by_admin_id
              WHERE b.status IN ('confirmed','completed') AND b.created_at >= :f AND b.created_at < :t
           GROUP BY a.id, a.full_name, a.role ORDER BY amt DESC LIMIT 15",
            $range
        );
        $totN = 0;
        $totAmt = 0.0;
        foreach ($rows as $r) {
            $code = AgentWallet::agentCodeLabel((int) $r['id']);
            $totN += (int) $r['n'];
            $totAmt += (float) $r['amt'];
            $lines[] = '• ' . self::short((string) $r['full_name']) . ($code !== '' ? ' (' . $code . ')' : '') . ((string) $r['role'] === 'counter' ? ' 🏪' : '')
                     . ': ' . (int) $r['n'] . self::t($lang, ' टिकट · ', ' टिकट · ', ' tkt · ') . (int) $r['seats'] . self::t($lang, ' सिट · ', ' सीट · ', ' seats · ')
                     . inr((float) $r['amt']) . ((float) $r['cash'] > 0 ? self::t($lang, ' (नगद ', ' (नकद ', ' (cash ') . inr((float) $r['cash']) . ')' : '');
        }
        $lines[] = $rows === []
            ? self::t($lang, 'यो अवधिमा कसैले बेचेको छैन।', 'इस अवधि में कोई बिक्री नहीं।', 'No sales by staff in this period.')
            : self::t($lang, 'जम्मा: ', 'कुल: ', 'Total: ') . $totN . self::t($lang, ' टिकट · ', ' टिकट · ', ' tickets · ') . inr($totAmt);

        // Balances - who owes the office cash, who is owed commission (all time, from the ledger).
        $bal = Database::fetchAll(
            "SELECT l.agent_admin_id id, a.full_name,
                    COALESCE(SUM(CASE WHEN l.account = 'cash' THEN l.amount ELSE 0 END),0) cash,
                    COALESCE(SUM(CASE WHEN l.account = 'commission' THEN l.amount ELSE 0 END),0) comm
               FROM agent_ledger l JOIN admins a ON a.id = l.agent_admin_id
           GROUP BY l.agent_admin_id, a.full_name
             HAVING ABS(cash) >= 1 OR ABS(comm) >= 1
           ORDER BY cash DESC LIMIT 10"
        );
        if ($bal !== []) {
            $lines[] = '';
            $lines[] = '💰 ' . self::t($lang, 'बाँकी हिसाब (नगद बुझाउनु / कमिसन दिनु):', 'बाकी हिसाब (नकद जमा / कमीशन देना):', 'Balances (cash to hand over / commission due):');
            foreach ($bal as $r) {
                $lines[] = '• ' . self::short((string) $r['full_name']) . ': '
                         . self::t($lang, 'नगद ', 'नकद ', 'cash ') . inr((float) $r['cash']) . ' · '
                         . self::t($lang, 'कमिसन ', 'कमीशन ', 'comm ') . inr((float) $r['comm']);
            }
        }

        // Counter desks (bookings.counter_code) in the same period.
        try {
            $desks = Database::fetchAll(
                "SELECT counter_code c, COUNT(*) n, COALESCE(SUM(total_amount),0) amt FROM bookings
                  WHERE counter_code IS NOT NULL AND counter_code <> '' AND status IN ('confirmed','completed')
                    AND created_at >= :f AND created_at < :t GROUP BY counter_code ORDER BY amt DESC LIMIT 8",
                $range
            );
            if ($desks !== []) {
                $lines[] = '';
                $lines[] = '🏪 ' . self::t($lang, 'काउन्टर डेस्क:', 'काउंटर डेस्क:', 'Counter desks:');
                foreach ($desks as $d) {
                    $lines[] = '• ' . (string) $d['c'] . ': ' . (int) $d['n'] . self::t($lang, ' टिकट · ', ' टिकट · ', ' tickets · ') . inr((float) $d['amt']);
                }
            }
        } catch (Throwable $e) {
            // counter_code arrives with the desks migration; the board still answers without it
        }
        $lines[] = '';
        $lines[] = self::t($lang, '"agent report mahina" = यो महिना · SHG-00xx = एक एजेन्ट', '"agent report mahina" = यह महीना · SHG-00xx = एक एजेंट', '"agent report month" = this month · SHG-00xx = one agent');
        $lines[] = appUrl('admin/agent-sales.php');
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    /** Staff: who is on the bus — name, seat, pickup, phone. Agents: their own sales only. */
    private static function passengers(array $who, string $lang, string $text): ?array
    {
        $role  = (string) ($who['role'] ?? '');
        $scope = $who['scopeAdminId'] ?? null;
        if (!in_array($role, ['staff', 'admin'], true)) {
            return null;
        }
        $p    = TicketBot::parse($text);
        $date = Security::isValidDate((string) ($p['date'] ?? '')) ? (string) $p['date'] : todayISO();
        $dir  = (string) ($p['direction'] ?? '');
        $scheds = Database::fetchAll(
            "SELECT s.id, r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) dep_time, r.id AS route_id
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.travel_date = :d AND r.is_active = 1 AND s.is_blocked = 0 ORDER BY r.sort_order, s.slot", ['d' => $date]);
        if ($dir !== '') {
            require_once INCLUDE_PATH . '/quickticket.php';
            $scheds = array_values(array_filter($scheds, static function (array $s) use ($dir): bool {
                $r = Database::fetch('SELECT * FROM routes WHERE id = :r', ['r' => (int) $s['route_id']]);
                return $r !== null && QuickTicket::directionOf($r) === $dir;
            }));
        }
        /* Owner 9 Oct 2026: "passenger list magda sara ticket hoina - chalani
           pathaos". The office gets the bus chalani PICTURE (the same keyed
           link WaTemplates' chalani message uses) with one count line per
           bus; an agent (own book only) gets their own names in words. */
        $lines = ['👥 ' . self::t($lang, 'यात्रु ' . formatDate($date, 'D, j M'), 'यात्री ' . formatDate($date, 'D, j M'), 'Passengers ' . formatDate($date, 'D, j M'))];
        $media = null;
        $shown = 0;
        foreach ($scheds as $s) {
            $sid  = (int) $s['id'];
            $args = ['s' => $sid];
            $sql  = "SELECT b.pnr, b.contact_phone, bl.boarding_stop,
                            GROUP_CONCAT(bp.full_name ORDER BY bp.id SEPARATOR ', ') names,
                            GROUP_CONCAT(bp.seat_no ORDER BY bp.seat_no SEPARATOR ', ') seats,
                            COUNT(bp.id) pax
                       FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
                       JOIN booking_passengers bp ON bp.booking_id = b.id AND bp.leg_id = bl.id
                      WHERE bl.schedule_id = :s AND b.status IN ('confirmed','completed')";
            if ($scope !== null) {
                $sql .= ' AND b.sold_by_admin_id = :a';
                $args['a'] = (int) $scope;
            }
            $sql .= ' GROUP BY b.id, b.pnr, b.contact_phone, bl.boarding_stop ORDER BY bl.boarding_time, b.id';
            $rows = Database::fetchAll($sql, $args);
            $pax  = array_sum(array_map(static fn(array $r): int => (int) $r['pax'], $rows));
            $lines[] = '🚌 ' . trim((string) $s['from_city']) . ' → ' . trim((string) $s['to_city']) . ' ' . self::clock((string) $s['dep_time']) . ': '
                     . self::t($lang, $pax . ' यात्रु · ' . count($rows) . ' बुकिङ', $pax . ' यात्री · ' . count($rows) . ' बुकिंग', $pax . ' passengers · ' . count($rows) . ' bookings');
            if ($scope === null) {
                // The office: the chalani picture of the first bus asked for, keyed links for the rest.
                $exp = time() + 2 * 86400;
                $url = appUrl('download-chalan.php?' . http_build_query([
                    'sid' => $sid, 'doc' => 'chalani', 'page' => 1, 'exp' => $exp,
                    'k' => substr(Security::sign('chalan-dl|' . $sid . '|chalani|1|' . $exp), 0, 20),
                ]));
                if ($media === null && $pax > 0) {
                    $media = $url;
                    $lines[] = '   ' . self::t($lang, '📄 चलानी माथि (फोटो)', '📄 चलानी ऊपर (फोटो)', '📄 chalani above (picture)');
                } else {
                    $lines[] = '   📄 ' . $url;
                }
                continue;
            }
            foreach ($rows as $r) {
                if ($shown >= 30) {
                    $lines[] = self::t($lang, '… बाँकी manifest मा', '… बाकी manifest में', '… more in the manifest');
                    break;
                }
                $shown++;
                $stop = Boarding::stopDisplay((string) ($r['boarding_stop'] ?? ''))['name'] ?? '';
                $lines[] = '• ' . (string) $r['names'] . ' · ' . (string) $r['seats'] . ($stop !== '' ? ' · ' . $stop : '')
                         . ((string) $r['contact_phone'] !== '' && !isPlaceholderPhone((string) $r['contact_phone']) ? ' · ' . (string) $r['contact_phone'] : '')
                         . ' · ' . substr((string) $r['pnr'], -5);
            }
        }
        if ($scheds === []) {
            $lines[] = self::t($lang, 'यो मितिको बस छैन।', 'इस तारीख की बस नहीं।', 'No bus on that date.');
        }
        $lines[] = '🔗 ' . appUrl('admin/manifest.php?date=' . $date);
        return ['text' => implode("\n", $lines), 'media' => $media, 'ui' => null];
    }

    /** Office: payments waiting to be verified. */
    private static function pending(string $lang): array
    {
        $rows = Database::fetchAll(
            "SELECT b.pnr, b.total_amount, b.contact_phone, b.created_at,
                    (SELECT full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.id LIMIT 1) name,
                    (SELECT MIN(travel_date) FROM booking_legs bl WHERE bl.booking_id = b.id) travel_date
               FROM bookings b WHERE b.status = 'pending' AND b.created_at >= NOW() - INTERVAL 3 DAY ORDER BY b.id DESC LIMIT 15");
        $lines = ['⏳ ' . self::t($lang, 'भुक्तानी जाँच बाँकी: ' . count($rows), 'भुगतान जाँच बाकी: ' . count($rows), 'Payments waiting: ' . count($rows))];
        foreach ($rows as $r) {
            $age = max(0, (int) round((time() - (int) strtotime((string) $r['created_at'])) / 60));
            $lines[] = '• ' . (string) $r['pnr'] . ' · ' . (string) ($r['name'] ?? '') . ' · ' . inr((float) $r['total_amount']) . ' · '
                     . formatDate((string) $r['travel_date'], 'j M') . ' · ' . ($age >= 60 ? intdiv($age, 60) . ' h' : $age . ' min');
        }
        $lines[] = appUrl('admin/payments.php');
        return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
    }

    /* =================================================================
     *  Helpers
     * ================================================================= */

    /** The sender's own confirmed booking with a leg between today+$fromDays and today+$toDays. */
    private static function ownTrip(string $digits, int $fromDays, int $toDays): ?array
    {
        if ($digits === '' || isPlaceholderPhone($digits)) {
            return null;
        }
        return Database::fetch(
            "SELECT b.* FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id
              WHERE (b.contact_phone = :p OR b.alt_phone = :p2) AND b.status IN ('confirmed', 'completed')
                AND bl.travel_date BETWEEN :a AND :z
              ORDER BY ABS(DATEDIFF(bl.travel_date, CURDATE())), bl.travel_date, b.id DESC LIMIT 1",
            ['p' => $digits, 'p2' => $digits, 'a' => date('Y-m-d', strtotime($fromDays . ' day')), 'z' => date('Y-m-d', strtotime($toDays . ' day'))]
        );
    }

    /** @return array{name:string, phone:string} the driver on the schedule, else the route's crew. */
    private static function crewFor(int $scheduleId, int $driverId, string $crewName, string $crewPhone): array
    {
        if ($driverId > 0) {
            $d = Database::fetch('SELECT full_name, phone FROM drivers WHERE id = :i', ['i' => $driverId]);
            if ($d !== null) {
                return ['name' => trim((string) $d['full_name']), 'phone' => self::prettyPhone((string) $d['phone'])];
            }
        }
        return ['name' => trim($crewName), 'phone' => self::prettyPhone($crewPhone)];
    }

    private static function prettyPhone(string $p): string
    {
        $d = preg_replace('/\D/', '', $p) ?? '';
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            return '+91 ' . substr($d, 2, 5) . ' ' . substr($d, 7);
        }
        if (strlen($d) === 13 && str_starts_with($d, '977')) {
            return '+977 ' . substr($d, 3);
        }
        return trim($p);
    }

    /** The trip state in words. */
    private static function stateLine(string $state, string $label, string $lang, bool $pickupPassed): string
    {
        return match ($state) {
            'departed', 'on_route', 'en_route' => self::t($lang, 'बस छुटिसक्यो, बाटोमा छ', 'बस निकल चुकी है, रास्ते में है', 'The bus has left and is on its way'),
            'boarding'                         => self::t($lang, 'बस चढ्ने समय — पिकअपमा छ', 'बस चढ़ने का समय — पिकअप पर है', 'Boarding now'),
            'arrived', 'completed'             => self::t($lang, 'बस पुगिसक्यो', 'बस पहुँच चुकी है', 'The bus has arrived'),
            'cancelled'                        => self::t($lang, 'यो यात्रा रद्द भयो — अफिसमा सम्पर्क गर्नुहोस्', 'यह यात्रा रद्द हुई — ऑफिस से संपर्क करें', 'This trip was cancelled — please contact the office'),
            'delayed'                          => self::t($lang, 'बस ढिलो छ', 'बस देरी से है', 'The bus is delayed'),
            'overdue'                          => self::t($lang, 'बस छुट्ने समय भयो, अफिसले अपडेट गर्दैछ', 'बस का समय हो गया, ऑफिस अपडेट कर रहा है', 'Departure time has passed; the office is updating'),
            default                            => $pickupPassed
                ? self::t($lang, 'अहिले live position छैन', 'अभी live position नहीं', 'No live position right now')
                : self::t($lang, 'बस अझै छुटेको छैन — छुट्ने समय नजिक फेरि सोध्नुहोस्', 'बस अभी नहीं निकली — रवानगी के समय फिर पूछिए', 'The bus has not left yet — ask again near departure')
                  . ($label !== '' ? ' (' . $label . ')' : ''),
        };
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

    /** Whole words only ("seat cha" must not match "seat chahiyo"); a plural "s" is allowed. */
    private static function hasWord(string $t, array $words): bool
    {
        foreach ($words as $w) {
            if (preg_match('/(?<![\p{L}\p{M}])' . preg_quote($w, '/') . 's?(?![\p{L}\p{M}])/u', $t) === 1) {
                return true;
            }
        }
        return false;
    }

    private static function norm(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[?？!.,;:।॥"\'()]+/u', ' ', $t) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /**
     * Which language to answer in. Staff: the office's choice (wa_staff_lang)
     * unless the message is clearly in another script. Customers: the script
     * / words of the message; romanised Nepali cues count as Nepali.
     */
    private static function lang(string $text, bool $isStaff): string
    {
        $d = TicketBot::detectLang($text);
        $l = mb_strtolower($text);
        if ($d === 'en' && preg_match('/(?<![a-z])(kaha|kata|kati|pugyo|cha|xa|chha|ho|bata|lai|hajur|garna|garne|garnu|k |ke |dinus|bholi|aaja|baki|khali|tirne|firta|pathau|chahiyo)(?![a-z])/u', $l) === 1) {
            $d = 'ne';
        }
        if ($isStaff) {
            $pref = strtolower(trim(Settings::getString('wa_staff_lang', 'ne')));
            if (in_array($pref, ['ne', 'hi', 'en'], true) && ($d === 'en' || $d === 'ne')) {
                return $pref;
            }
        }
        return in_array($d, ['ne', 'hi', 'gu'], true) ? $d : 'en';
    }

    private static function t(string $lang, string $ne, string $hi, string $en, string $gu = ''): string
    {
        return match ($lang) { 'ne' => $ne, 'hi' => $hi, 'gu' => ($gu !== '' ? $gu : $en), default => $en };
    }

    /**
     * "📍 The bus is on the way to Rupaidiha — about 2 h 25 min (120 km to go)."
     * track.php's onWay / onWayEta / onWayKm, for a passenger the coach has
     * picked up (WhereIs onBoard). Bus-centric on purpose: we only know they
     * are PLAUSIBLY aboard. Place names stay Roman. Km alone when there are
     * no minutes (no coordinates for the drop, or more than 400 km to go).
     *
     * @param array<string,mixed> $st  WhereIs::status()
     */
    private static function onWayLine(array $st, string $lang): string
    {
        $drop  = (string) ($st['drop'] ?? '');
        $km    = $st['dropKm'] ?? null;
        $min   = $st['dropEtaMin'] ?? null;
        $kmTxt = $km === null ? '' : ((float) $km >= 100 ? (string) (int) round((float) $km) : (string) $km);   // 1155 km, 42.5 km
        $line  = self::t($lang, '📍 बस ' . $drop . ' तर्फ जाँदैछ', '📍 बस ' . $drop . ' की ओर जा रही है', '📍 The bus is on the way to ' . $drop);
        if ($min !== null) {
            $m = (int) $min;
            [$h, $r] = [intdiv($m, 60), $m % 60];
            $dur = $m < 60 ? self::t($lang, $m . ' मिनेट', $m . ' मिनट', $m . ' min')
                 : ($r > 0 ? self::t($lang, $h . ' घण्टा ' . $r . ' मिनेट', $h . ' घंटे ' . $r . ' मिनट', $h . ' h ' . $r . ' min')
                           : self::t($lang, $h . ' घण्टा', $h . ' घंटे', $h . ' h'));
            $line .= self::t($lang, ' — करिब ', ' — लगभग ', ' — about ') . $dur
                   . ($km !== null ? ' (' . self::t($lang, $kmTxt . ' किमी बाँकी', $kmTxt . ' किमी बाकी', $kmTxt . ' km to go') . ')' : '');
        } elseif ($km !== null) {
            $line .= ' — ' . self::t($lang, $kmTxt . ' किमी बाँकी', $kmTxt . ' किमी बाकी', $kmTxt . ' km to go');
        }
        return $line . self::t($lang, '।', '।', '.');
    }

    private static function clock(string $time): string
    {
        if (trim($time) === '') {
            return '';
        }
        $ts = strtotime($time);
        return $ts === false ? $time : date('g:i A', $ts);
    }

    private static function short(string $name): string
    {
        $d = Boarding::stopDisplay($name);
        $n = (string) ($d['name'] ?? '');
        return $n !== '' ? $n : trim((string) (explode('·', $name)[0] ?? $name));
    }

    private static function remember(string $who, string $user, string $reply): void
    {
        try {
            require_once INCLUDE_PATH . '/aiagent.php';
            AiAgent::remember($who, $user, $reply);
        } catch (Throwable $e) {
        }
    }

    /** Admin → AI Activity: "local:<intent>" — answered on the VPS, no AI. */
    private static function log(string $who, string $intent, string $role, float $t0): void
    {
        try {
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
