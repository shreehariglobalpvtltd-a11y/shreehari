<?php
/**
 * =====================================================================
 *  includes/wadirectory.php — WaDirectory: every place and every contact
 *  the bot may name, from ONE source of truth (25 Nov 2026, mp3-bot).
 *
 *  Owner: "saboi thau ko location current name number, agent CEO ko name
 *  number, har ek location name number" — "Nepalgunj office ko number?",
 *  "Ahmedabad pickup kaha?", "CEO ko number", "Surat agent" were answered
 *  with the ticket menu, the head office only, or a refusal.
 *
 *  Read-only. Built from the live tables the website already shows:
 *    - CounterDesk::publicOffices()   offices ticked "show on website"
 *                                      (phone, WhatsApp, in-charge, map)
 *    - CompanyLocations::forPublic()  parkings / pickup yards / offices
 *                                      the office switched on (Admin → Locations)
 *    - route_stops (boarding)          the pickup points with time + map pin
 *    - SiteFacts ceo / nepal team      the CEO's PUBLISHED line and the Nepal
 *                                      contacts the home page prints
 *    - AgentApplications::directory()  the PUBLIC agent directory (agents who
 *                                      are shown on the website) — never a
 *                                      private roster phone for a customer
 *  A staff / office number (AiTools::whoIs role staff | admin) also sees
 *  the desks and places that are not public, with their in-charge.
 *  NOTHING IS INVENTED: a place with no number says so and gives the office.
 *
 *  Switches: wa_directory_on (default 1) — off = lookup() returns null and
 *  every caller answers exactly as before. wa_dir_ceo_public_on (default 1,
 *  owner's own ask 25 Nov) — the CEO's published name + line; off = the old
 *  "we do not share personal numbers" answer.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

final class WaDirectory
{
    /** town key => the ways people write it (Roman, Devanagari, short codes). */
    private const TOWNS = [
        'mehsana'   => ['mehsana', 'mahesana', 'mehesana', 'mehsaana', 'msa', 'मेहसाना', 'मेहसाणा', 'महेसाणा', 'મહેસાણા'],
        'ahmedabad' => ['ahmedabad', 'amdavad', 'ahmdabad', 'ahemdabad', 'ahmadabad', 'amd', 'chiloda', 'nana chiloda', 'paldi', 'अहमदाबाद', 'अमदावाद', 'चिलोडा', 'અમદાવાદ'],
        'surat'     => ['surat', 'kamrej', 'srt', 'सुरत', 'कामरेज', 'સુરત'],
        'baroda'    => ['baroda', 'vadodara', 'brd', 'बडोदा', 'वडोदरा', 'વડોદરા'],
        'rajkot'    => ['rajkot', 'rjt', 'राजकोट', 'રાજકોટ'],
        'nepalgunj' => ['nepalgunj', 'nepalganj', 'nepalgunja', 'npj', 'नेपालगञ्ज', 'नेपालगंज', 'नेपालगन्ज'],
        'rupaidiha' => ['rupaidiha', 'rupaidiya', 'rupediha', 'rupaideha', 'रुपैडिहा', 'रुपईडीहा', 'रुपैडीहा'],
        'kohalpur'  => ['kohalpur', 'कोहलपुर'],
        'bharuch'   => ['bharuch', 'भरुच'],
        'vapi'      => ['vapi', 'वापी'],
        'palanpur'  => ['palanpur', 'पालनपुर'],
        'himatnagar' => ['himatnagar', 'हिम्मतनगर'],
        'godhra'    => ['godhra', 'गोधरा'],
    ];

    private const W_OFFICE  = 'office|offices|karyalay|karyalaya|branch|head\s*office|अफिस|अफिसको|ऑफिस|कार्यालय|शाखा';
    private const W_COUNTER = 'counter|counters|kaunter|desk|काउन्टर|काउंटर';
    private const W_PARKING = 'parking|parkings|park|पार्किङ|पार्किंग';
    private const W_PICKUP  = 'pickup|pick\s*up|boarding|chadne|chadhne|chadhna|chadna|chadhne\s*thau|stop|bus\s*stand|बोर्डिङ|चढ्ने|चढ़ने|स्टप';
    private const W_BORDER  = 'border|boder|jamunaha|बोर्डर|बॉर्डर|जमुनहा';
    private const W_CONTACT = 'number|numbers|nambar|nmbr|phone|fone|mobile|contact|sampark|whatsapp|helpline|नम्बर|नंबर|फोन|फ़ोन|मोबाइल|सम्पर्क|संपर्क';
    private const W_WHERE   = 'kaha|kahan|kata|kidhar|where|location|address|adress|thegana|thau|map|maps|naksha|direction|directions|ठेगाना|कहाँ|कहां|कता|नक्सा|नक्शा|लोकेसन|लोकेशन|पता';
    private const W_CEO     = 'ceo|c\s*e\s*o|director|founder|malik|maalik|owner|sanchalak|सीईओ|सञ्चालक|संचालक|मालिक|निर्देशक';
    private const W_SUPPORT = 'support|helpline|customer\s*care|help\s*line|सहयोग|हेल्पलाइन';
    private const W_AGENT   = 'agent|agents|ejent|dalal|एजेन्ट|एजेंट';
    private const W_LIST    = 'sabai|sab|sabbai|all|list|har\s*ek|harek|every|सबै|सब|हरेक|सूची|लिस्ट';
    private const W_PERSONAL = 'personal|niji|nijee|private|ghar\s*ko|निजी|व्यक्तिगत';

    public static function on(): bool
    {
        return Settings::getBool('wa_directory_on', true);
    }

    public static function ceoPublic(): bool
    {
        return Settings::getBool('wa_dir_ceo_public_on', true);
    }

    /* =================================================================
     *  The one list
     * ================================================================= */

    /**
     * Every place the bot may name.
     *
     * @param bool $internal a staff / office number: desks and places that are not public too
     * @return list<array{type:string,name:string,nameNe:string,town:string,townKey:string,phone:string,wa:string,
     *                    person:string,personPhone:string,map:string,address:string,hours:string,public:bool,source:string}>
     */
    public static function places(bool $internal = false): array
    {
        $out = [];
        try {
            require_once INCLUDE_PATH . '/counterdesk.php';
            foreach (CounterDesk::publicOffices() as $o) {
                $out[] = [
                    'type'    => $o['kind'] === 'head' ? 'head' : 'office',
                    'name'    => (string) $o['label'],
                    'nameNe'  => (string) ($o['nameNe'] ?? ''),
                    'town'    => (string) $o['city'],
                    'townKey' => self::townKeyOf((string) $o['city'] . ' ' . (string) $o['label']),
                    'phone'   => (string) $o['phone'],
                    'wa'      => (string) $o['wa'],
                    'person'  => trim((string) ($o['inchargeName'] ?: $o['contact'])),
                    'personPhone' => (string) ($o['inchargePhone'] ?? ''),
                    'map'     => (string) $o['mapUrl'],
                    'address' => (string) $o['address'],
                    'hours'   => (string) ($o['hours'] ?? ''),
                    'public'  => true,
                    'source'  => 'desk:' . (string) $o['code'],
                ];
            }
            if ($internal) {
                $shown = array_map(static fn(array $p): string => $p['source'], $out);
                foreach (CounterDesk::all(true) as $code => $d) {
                    if (in_array('desk:' . $code, $shown, true)) {
                        continue;
                    }
                    $name = (string) ($d['name'] ?? $code);
                    $town = CounterDesk::townOf($name);
                    $out[] = [
                        'type' => 'counter', 'name' => $name, 'nameNe' => (string) ($d['name_ne'] ?? ''), 'town' => $town,
                        'townKey' => self::townKeyOf($town . ' ' . $name),
                        'phone' => trim((string) ($d['phone'] ?? '')), 'wa' => trim((string) ($d['whatsapp'] ?? '')),
                        'person' => trim((string) ($d['contact_person'] ?? '')), 'personPhone' => trim((string) ($d['incharge_phone'] ?? '')),
                        'map' => trim((string) ($d['map_url'] ?? '')), 'address' => trim((string) ($d['address'] ?? '')),
                        'hours' => trim((string) ($d['hours'] ?? '')), 'public' => false, 'source' => 'desk:' . $code,
                    ];
                }
            }
        } catch (Throwable $e) {
            Logger::warning('WaDirectory desks failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        try {
            require_once INCLUDE_PATH . '/companylocations.php';
            foreach ($internal ? CompanyLocations::all() : CompanyLocations::forPublic() as $p) {
                if (!$internal && !$p['show_public']) {
                    continue;
                }
                $out[] = [
                    'type' => (string) $p['kind'], 'name' => (string) $p['name'], 'nameNe' => (string) $p['nameNe'],
                    'town' => (string) $p['town'], 'townKey' => self::townKeyOf((string) $p['town'] . ' ' . (string) $p['name']),
                    'phone' => trim((string) $p['phone']), 'wa' => trim((string) $p['whatsapp']),
                    'person' => trim((string) $p['person']), 'personPhone' => '', 'map' => trim((string) $p['map']),
                    'address' => trim((string) $p['address']), 'hours' => trim((string) $p['hours']),
                    'public' => (bool) $p['show_public'], 'source' => 'place:' . (int) $p['id'],
                ];
            }
        } catch (Throwable $e) {
            Logger::warning('WaDirectory places failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        return $out;
    }

    /**
     * The pickup points (route_stops, boarding) of one town — name, time, map pin.
     *
     * @return list<array{name:string,landmark:string,time:string,map:string,route:string}>
     */
    public static function pickups(string $townKey): array
    {
        $out = [];
        $seen = [];
        try {
            require_once INCLUDE_PATH . '/boarding.php';
            foreach (Database::fetchAll(
                "SELECT s.stop_name, s.landmark, s.stop_time, s.latitude, s.longitude, r.from_city, r.to_city
                   FROM route_stops s JOIN routes r ON r.id = s.route_id
                  WHERE r.is_active = 1 AND s.stop_type = 'boarding' AND s.is_active = 1
                  ORDER BY r.sort_order, s.sort_order, s.id") as $s) {
                $name = (string) $s['stop_name'];
                if ($townKey !== '' && self::townKeyOf($name . ' ' . (string) ($s['landmark'] ?? '')) !== $townKey) {
                    continue;
                }
                $k = mb_strtolower(trim($name)) . '|' . (string) $s['from_city'];
                if (isset($seen[$k])) {
                    continue;
                }
                $seen[$k] = true;
                $lat = (float) ($s['latitude'] ?? 0);
                $lng = (float) ($s['longitude'] ?? 0);
                $out[] = [
                    'name'     => trim((string) (Boarding::stopDisplay($name)['name'] ?? $name)),
                    'landmark' => trim((string) ($s['landmark'] ?? '')),
                    'time'     => (string) ($s['stop_time'] ?? '') !== '' ? substr((string) $s['stop_time'], 0, 5) : '',
                    'map'      => ($lat != 0.0 && $lng != 0.0) ? 'https://www.google.com/maps/dir/?api=1&destination=' . round($lat, 6) . ',' . round($lng, 6) : '',
                    'route'    => trim((string) $s['from_city']) . ' → ' . trim((string) $s['to_city']),
                ];
            }
        } catch (Throwable $e) {
            Logger::warning('WaDirectory pickups failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        return $out;
    }

    /**
     * The people the website itself publishes: the CEO (when wa_dir_ceo_public_on), the Nepal team, the 24×7 line.
     *
     * @return list<array{key:string,role:string,roleNe:string,name:string,phone:string}>
     */
    public static function people(): array
    {
        $out = [];
        try {
            require_once INCLUDE_PATH . '/sitefacts.php';
            $f = SiteFacts::all();
            $ceo = (array) ($f['ceo'] ?? []);
            if (self::ceoPublic() && trim((string) ($ceo['name'] ?? '')) !== '') {
                $out[] = ['key' => 'ceo', 'role' => (string) ($ceo['title'] ?? 'CEO'), 'roleNe' => (string) ($ceo['titleNe'] ?? 'प्रमुख कार्यकारी'),
                    'name' => (string) $ceo['name'], 'phone' => (string) ($ceo['phone'] ?? '')];
            }
            foreach ((array) ($f['nepal']['team'] ?? []) as $p) {
                if ((string) ($p['key'] ?? '') === 'ceo' || trim((string) ($p['phone'] ?? '')) === '') {
                    continue;
                }
                $out[] = ['key' => (string) $p['key'], 'role' => (string) $p['role'], 'roleNe' => (string) $p['roleNe'],
                    'name' => (string) $p['name'], 'phone' => (string) $p['phone']];
            }
        } catch (Throwable $e) {
            Logger::warning('WaDirectory people failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        $co = Settings::company();
        $out[] = ['key' => 'office', 'role' => 'Office / booking', 'roleNe' => 'अफिस / बुकिङ', 'name' => (string) $co['name'], 'phone' => (string) $co['phone']];
        if (trim((string) $co['phone247']) !== '' && normalisePhone((string) $co['phone247']) !== normalisePhone((string) $co['phone'])) {
            $out[] = ['key' => 'support', 'role' => '24×7 support', 'roleNe' => '२४ घण्टा सहयोग', 'name' => (string) $co['name'], 'phone' => (string) $co['phone247']];
        }
        return $out;
    }

    /* =================================================================
     *  Reading a question
     * ================================================================= */

    /** The town a line names ('' = none). */
    public static function townIn(string $text): string
    {
        $t = ' ' . self::norm($text) . ' ';
        foreach (self::TOWNS as $key => $ways) {
            foreach ($ways as $w) {
                if (preg_match('/(?<![\p{L}\p{M}])' . preg_quote($w, '/') . '(?![\p{L}\p{M}])/u', $t) === 1) {
                    return $key;
                }
            }
        }
        return '';
    }

    /**
     * Is this line a question about a place or a contact the directory holds?
     * Deliberately narrow: a place / person word AND a "number / where" word (or a list word), or a
     * town + agent. Never a trip ("bholi Surat bata 2 seat") and never the bus itself ("bus kaha pugyo").
     */
    public static function isQuestion(string $text): bool
    {
        if (!self::on()) {
            return false;
        }
        $t = ' ' . self::norm($text) . ' ';
        if (mb_strlen($t) > 160 || preg_match('/shg[-\s]?\d{4}[-\s]?\d{3,}/u', $t) === 1) {
            return false;
        }
        $has = static fn(string $re): bool => preg_match('/(?<![\p{L}\p{M}])(?:' . $re . ')(?![\p{L}\p{M}])/u', $t) === 1;
        // a trip line is the booking's: a seat count, a ticket order, a date with a trip
        if (preg_match('/\d+\s*(?:seat|seats|sit|ticket|tickets|tikat|jana|jan|log|berth|सिट|सीट|टिकट|जना)(?![\p{L}])/u', self::ascii($t)) === 1
            || $has('book|booking|katnu|kaatnu|katdinus|katideu|chahiyo|chahiye|chaiyo|chaiye|बुक|चाहियो|चाहिए')) {
            return false;
        }
        // the bus itself / the crew / a timing is WaLocal's
        if ($has('bus|gadi|gaadi|gaari|बस|गाडी|गाड़ी') && !$has(self::W_OFFICE . '|' . self::W_COUNTER . '|' . self::W_PARKING . '|' . self::W_PICKUP . '|' . self::W_BORDER)) {
            return false;
        }
        if ($has('driver|conductor|khalasi|helper|चालक|ड्राइभर')) {
            return false;
        }
        $place   = $has(self::W_OFFICE . '|' . self::W_COUNTER . '|' . self::W_PARKING . '|' . self::W_PICKUP . '|' . self::W_BORDER);
        $person  = $has(self::W_CEO . '|' . self::W_SUPPORT);
        $agent   = $has(self::W_AGENT);
        $contact = $has(self::W_CONTACT);
        $where   = $has(self::W_WHERE);
        $list    = $has(self::W_LIST);
        $town    = self::townIn($text) !== '';
        if (($place || $person) && ($contact || $where || $list)) {
            return true;
        }
        if ($person && mb_strlen(trim($t)) <= 30) {
            return true;                               // "ceo?", "CEO ko", "malik ko"
        }
        if ($agent && $town && !$has('banna|banne|bannu|bano|apply|join|register|darta')) {
            return true;                               // "Surat agent", "Nepalgunj ma agent cha?"
        }
        if ($town && ($contact) && !$has('bata|dekhi|se|from|samma|tak|to|jane|janxa|jancha|जाने|बाट|देखि|सम्म')) {
            return true;                               // "Surat ko number", "Nepalgunj contact"
        }
        if ($town && $where && $has('office|counter|parking|pickup|kaunter|अफिस|काउन्टर|पार्किङ')) {
            return true;
        }
        return false;
    }

    /**
     * The answer to a directory question, or null when the line is not one.
     *
     * @param string $view 'customer' | 'staff' | 'admin' (AiTools::whoIs role)
     * @param string $lang ne | rn (Roman Nepali) | hi | en
     * @param bool $force the AI's directory_lookup tool: the model already decided it is a directory question
     * @return array{text:string, kind:string, hits:int}|null
     */
    public static function lookup(string $text, string $view, string $lang, bool $force = false): ?array
    {
        if (!self::on() || (!$force && !self::isQuestion($text))) {
            return null;
        }
        $internal = in_array($view, ['staff', 'admin'], true);
        $t = ' ' . self::norm($text) . ' ';
        $has = static fn(string $re): bool => preg_match('/(?<![\p{L}\p{M}])(?:' . $re . ')(?![\p{L}\p{M}])/u', $t) === 1;
        $town = self::townIn($text);

        // People first: "CEO ko number", "malik ko number", "support number"
        if ($has(self::W_CEO)) {
            return self::ceoAnswer($lang, $has(self::W_PERSONAL));
        }
        if ($has(self::W_SUPPORT) && !$has(self::W_OFFICE . '|' . self::W_COUNTER)) {
            return self::peopleAnswer($lang, 'support');
        }
        // Agents of a town
        if ($has(self::W_AGENT) && $town !== '') {
            return self::agentsAnswer($town, $view, $lang);
        }

        $want = '';
        if ($has(self::W_PICKUP)) {
            $want = 'pickup';
        } elseif ($has(self::W_PARKING)) {
            $want = 'parking';
        } elseif ($has(self::W_BORDER)) {
            $want = 'border';
        } elseif ($has(self::W_COUNTER)) {
            $want = 'counter';
        } elseif ($has(self::W_OFFICE)) {
            $want = 'office';
        }

        $places = self::places($internal);
        $match = array_values(array_filter($places, static function (array $p) use ($town, $want): bool {
            if ($town !== '' && $p['townKey'] !== $town) {
                return false;
            }
            if ($want === '' || $want === 'counter') {
                return true;
            }
            if ($want === 'office') {
                return in_array($p['type'], ['head', 'office', 'counter'], true);
            }
            return $p['type'] === $want || ($want === 'pickup' && in_array($p['type'], ['parking', 'pickup'], true));
        }));
        $stops = ($want === 'pickup' || $want === '') && $town !== '' ? self::pickups($town) : [];
        if ($want === 'pickup' && $town === '') {
            $stops = array_slice(self::pickups(''), 0, 14);
        }
        if ($town === '' && $want === '') {
            $match = array_values(array_filter($places, static fn(array $p): bool => in_array($p['type'], ['head', 'office'], true) || $p['public']));
        }
        if ($match === [] && $stops === []) {
            return self::noneAnswer($town, $want, $lang);
        }

        $lines = [];
        $T = $town !== '' ? self::townLabel($town) : '';
        $lines[] = match (true) {
            $want === 'pickup' => '🚏 ' . self::t($lang, ($T !== '' ? $T . ' — ' : '') . 'चढ्ने ठाउँ', ($T !== '' ? $T . ' — ' : '') . 'chadne thau', ($T !== '' ? $T . ' — ' : '') . 'चढ़ने की जगह', ($T !== '' ? $T . ' — ' : '') . 'pickup points'),
            $T !== ''          => '📍 ' . $T . self::t($lang, ' — ठेगाना र सम्पर्क', ' — thegana ra sampark', ' — पता और संपर्क', ' — address and contact'),
            default            => '📍 ' . self::t($lang, 'हाम्रा कार्यालय र ठाउँ', 'hamra karyalay ra thau', 'हमारे ऑफिस और जगह', 'Our offices and places'),
        };
        foreach (array_slice($match, 0, 10) as $p) {
            $lines[] = self::placeLine($p, $lang, $internal);
        }
        foreach (array_slice($stops, 0, 10) as $s) {
            $lines[] = '🚏 ' . $s['name'] . ($s['landmark'] !== '' && !str_contains(mb_strtolower($s['name']), mb_strtolower($s['landmark'])) ? ' (' . $s['landmark'] . ')' : '')
                . ($s['time'] !== '' ? ' · ' . $s['time'] : '') . ' · ' . $s['route'] . ($s['map'] !== '' ? "\n   🗺 " . $s['map'] : '');
        }
        if (in_array($want, ['office', 'counter'], true) || ($want === '' && $town === '')) {
            // every counter's number in one line (the line the documents print: company_counters)
            $lines[] = self::t($lang, '🏪 सबै काउन्टर: ', '🏪 Sabai counter: ', '🏪 सभी काउंटर: ', '🏪 All counters: ') . (string) Settings::company()['counters'];
        }
        $lines[] = self::t($lang, '📞 मुख्य अफिस: ', '📞 Mukhya office: ', '📞 मुख्य ऑफिस: ', '📞 Head office: ') . Settings::officePhone();
        return ['text' => implode("\n", $lines), 'kind' => $want !== '' ? $want : 'place', 'hits' => count($match) + count($stops)];
    }

    /* =================================================================
     *  Answers
     * ================================================================= */

    private static function placeLine(array $p, string $lang, bool $internal): string
    {
        $icon = match ($p['type']) { 'head' => '🏢', 'office' => '🏢', 'counter' => '🏪', 'parking' => '🅿️', 'pickup' => '🚏', 'border' => '🛂', default => '📍' };
        $name = ($lang === 'ne' && trim($p['nameNe']) !== '') ? $p['nameNe'] : $p['name'];
        $line = $icon . ' ' . $name;
        $phone = trim($p['phone']);
        $wa = trim($p['wa']);
        if ($phone !== '') {
            $line .= "\n   📞 " . $phone;
        }
        if ($wa !== '' && preg_replace('/\D/', '', $wa) !== preg_replace('/\D/', '', $phone)) {
            $line .= ($phone !== '' ? ' · ' : "\n   ") . 'WhatsApp wa.me/' . preg_replace('/\D/', '', $wa);
        }
        if ($phone === '' && $wa === '') {
            $line .= "\n   📞 " . self::t($lang, 'नम्बर राखिएको छैन — अफिस: ', 'number rakhiyeko chhaina — office: ', 'नंबर नहीं रखा — ऑफिस: ', 'no number on file — office: ') . Settings::officePhone();
        }
        $person = trim($p['person']);
        if ($person !== '' && ($p['public'] || $internal)) {
            $line .= "\n   👤 " . self::t($lang, 'सम्पर्क व्यक्ति: ', 'Sampark: ', 'संपर्क: ', 'In charge: ') . $person
                . ($internal && trim($p['personPhone']) !== '' && trim($p['personPhone']) !== $phone ? ' · ' . trim($p['personPhone']) : '');
        }
        if (trim($p['address']) !== '') {
            $line .= "\n   📍 " . trim($p['address']);
        }
        if (trim($p['hours']) !== '') {
            $line .= "\n   🕒 " . trim($p['hours']);
        }
        if (trim($p['map']) !== '') {
            $line .= "\n   🗺 " . trim($p['map']);
        }
        if ($internal && !$p['public']) {
            $line .= ' ' . self::t($lang, '(भित्री — वेबसाइटमा छैन)', '(bhitri — website ma chhaina)', '(अंदर का — वेबसाइट पर नहीं)', '(internal — not on the website)');
        }
        return $line;
    }

    private static function ceoAnswer(string $lang, bool $personal): array
    {
        $office = Settings::officePhone();
        $ceo = null;
        foreach (self::people() as $p) {
            if ($p['key'] === 'ceo') {
                $ceo = $p;
                break;
            }
        }
        if ($ceo === null || $personal) {
            // the switch off, or a PERSONAL number asked for: the office line reaches the management
            $name = '';
            try {
                require_once INCLUDE_PATH . '/sitefacts.php';
                $name = trim((string) (SiteFacts::all()['ceo']['name'] ?? ''));
            } catch (Throwable $e) {
                $name = '';
            }
            return ['text' => ($name !== '' ? '👤 CEO: ' . $name . "\n" : '') . self::t($lang,
                '🙏 निजी नम्बर हामी दिँदैनौं। अफिस ' . $office . ' मा फोन / WhatsApp गर्नुहोस् — कुरा व्यवस्थापनसम्म पुग्छ।',
                '🙏 Niji number hami dindainau. Office ' . $office . ' ma phone / WhatsApp garnus — kura management samma pugchha.',
                '🙏 निजी नंबर हम नहीं देते। ऑफिस ' . $office . ' पर कॉल / WhatsApp करें — बात मैनेजमेंट तक पहुँचती है।',
                '🙏 We do not share personal numbers. Call or WhatsApp the office on ' . $office . ' — it reaches the management.'),
                'kind' => 'ceo', 'hits' => $name !== '' ? 1 : 0];
        }
        $lines = ['👤 ' . ($lang === 'ne' ? $ceo['roleNe'] : $ceo['role']) . ': ' . $ceo['name']];
        if (trim($ceo['phone']) !== '') {
            $lines[] = '📞 ' . $ceo['phone'] . self::t($lang, ' (वेबसाइटमा प्रकाशित नम्बर)', ' (website ma prakashit number)', ' (वेबसाइट पर दिया नंबर)', ' (the number published on our website)');
        }
        $lines[] = self::t($lang, '🏢 टिकट / बुकिङ: अफिस ', '🏢 Ticket / booking: office ', '🏢 टिकट / बुकिंग: ऑफिस ', '🏢 Tickets / booking: office ') . $office;
        return ['text' => implode("\n", $lines), 'kind' => 'ceo', 'hits' => 1];
    }

    private static function peopleAnswer(string $lang, string $key): array
    {
        $lines = ['☎️ ' . self::t($lang, 'सम्पर्क', 'Sampark', 'संपर्क', 'Contacts')];
        foreach (self::people() as $p) {
            if ($key !== '' && !in_array($p['key'], [$key, 'office', 'support'], true)) {
                continue;
            }
            $lines[] = '• ' . ($lang === 'ne' ? $p['roleNe'] : $p['role']) . ': ' . $p['phone'] . ($p['key'] !== 'office' && $p['key'] !== 'support' ? ' (' . $p['name'] . ')' : '');
        }
        return ['text' => implode("\n", $lines), 'kind' => 'people', 'hits' => count($lines) - 1];
    }

    /** Agents of one town: the public directory for a passenger; name + code + area for staff; + phone for the office. */
    private static function agentsAnswer(string $town, string $view, string $lang): array
    {
        $T = self::townLabel($town);
        $lines = ['🤝 ' . $T . self::t($lang, ' — हाम्रा एजेन्ट', ' — hamra agent', ' — हमारे एजेंट', ' — our agents')];
        $n = 0;
        if ($view === 'customer') {
            try {
                require_once INCLUDE_PATH . '/agentapplications.php';
                foreach (AgentApplications::directory() as $d) {
                    if (self::townKeyOf((string) $d['area'] . ' ' . (string) ($d['addr'] ?? '')) !== $town) {
                        continue;
                    }
                    $lines[] = '• ' . (string) $d['name'] . ((string) $d['area'] !== '' ? ' · ' . $d['area'] : '') . ' · ' . (string) $d['phone'] . (!empty($d['verified']) ? ' ✅' : '');
                    if (++$n >= 10) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                $n = 0;
            }
        } else {
            try {
                require_once INCLUDE_PATH . '/agentwallet.php';
                foreach (Database::fetchAll("SELECT a.id, a.full_name, a.phone, a.role, p.counter_name, p.display_phone, p.address
                                               FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
                                              WHERE a.role IN ('agent','counter') AND a.is_active = 1 ORDER BY a.full_name") as $a) {
                    if (self::townKeyOf((string) ($a['counter_name'] ?? '') . ' ' . (string) ($a['address'] ?? '')) !== $town) {
                        continue;
                    }
                    $code = AgentWallet::agentCodeLabel((int) $a['id']);
                    $ph = trim((string) ($a['phone'] ?: ($a['display_phone'] ?? '')));
                    $lines[] = '• ' . trim((string) $a['full_name']) . ($code !== '' ? ' · ' . $code : '') . ((string) ($a['counter_name'] ?? '') !== '' ? ' · ' . $a['counter_name'] : '')
                        . ($view === 'admin' && $ph !== '' ? ' · ' . $ph : '') . ((string) $a['role'] === 'counter' ? ' (counter)' : '');
                    if (++$n >= 15) {
                        break;
                    }
                }
            } catch (Throwable $e) {
                $n = 0;
            }
        }
        if ($n === 0) {
            $lines[] = self::t($lang, 'यस ठाउँमा सूचीमा रहेको एजेन्ट अहिले छैन।', 'Yo thau ma suchi ma raheko agent ahile chhaina.', 'इस जगह अभी कोई सूचीबद्ध एजेंट नहीं है।', 'No listed agent in this town right now.');
        }
        // the town's own office / counter, if any
        foreach (self::places(false) as $p) {
            if ($p['townKey'] === $town && trim($p['phone']) !== '') {
                $lines[] = self::t($lang, '🏪 यहाँको अफिस: ', '🏪 Yaha ko office: ', '🏪 यहाँ का ऑफिस: ', '🏪 Office here: ') . $p['phone'];
                break;
            }
        }
        $lines[] = self::t($lang, '📞 मुख्य अफिस: ', '📞 Mukhya office: ', '📞 मुख्य ऑफिस: ', '📞 Head office: ') . Settings::officePhone();
        return ['text' => implode("\n", $lines), 'kind' => 'agents', 'hits' => $n];
    }

    private static function noneAnswer(string $town, string $want, string $lang): array
    {
        $T = $town !== '' ? self::townLabel($town) : '';
        $office = Settings::officePhone();
        return ['text' => self::t($lang,
            '🙏 ' . ($T !== '' ? $T . ' को ' : '') . 'यो ठाउँको विवरण हामीसँग राखिएको छैन। मुख्य अफिस: ' . $office . ' (फोन / WhatsApp)',
            '🙏 ' . ($T !== '' ? $T . ' ko ' : '') . 'yo thau ko bibaran hamisanga rakhiyeko chhaina. Mukhya office: ' . $office . ' (phone / WhatsApp)',
            '🙏 ' . ($T !== '' ? $T . ' की ' : '') . 'इस जगह की जानकारी हमारे पास नहीं है। मुख्य ऑफिस: ' . $office . ' (फ़ोन / WhatsApp)',
            '🙏 We have no details on file for ' . ($T !== '' ? $T : 'that place') . '. Head office: ' . $office . ' (call / WhatsApp)'),
            'kind' => 'none', 'hits' => 0];
    }

    /* =================================================================
     *  Helpers
     * ================================================================= */

    public static function townKeyOf(string $label): string
    {
        $t = ' ' . self::norm($label) . ' ';
        foreach (self::TOWNS as $key => $ways) {
            foreach ($ways as $w) {
                if (preg_match('/(?<![\p{L}\p{M}])' . preg_quote($w, '/') . '(?![\p{L}\p{M}])/u', $t) === 1) {
                    return $key;
                }
            }
        }
        $first = (string) (preg_split('/[\s,—–\-·|(]+/u', trim(self::norm($label))) ?: [''])[0];
        return (string) preg_replace('/[^\p{L}]/u', '', $first);
    }

    private static function townLabel(string $key): string
    {
        return mb_convert_case($key, MB_CASE_TITLE, 'UTF-8');
    }

    private static function norm(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = (string) preg_replace('/[?？!.,;:।"\'()\[\]*_~]+/u', ' ', $t);
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    }

    /** ne (Devanagari Nepali) | rn (Roman Nepali) | hi | en. */
    public static function t(string $lang, string $ne, string $rn, string $hi, string $en): string
    {
        return match ($lang) { 'rn' => $rn, 'hi' => $hi, 'en' => $en, default => $ne };
    }
}
