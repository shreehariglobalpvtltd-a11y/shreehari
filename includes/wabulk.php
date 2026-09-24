<?php
/**
 * =====================================================================
 *  WaBulk — many tickets from one WhatsApp message (24 Sep 2026).
 *
 *  Owner ask: "agent haru lai WhatsApp ma bulk ticket support garos —
 *  euta format bata ticket details collect garera ek click ma ticket
 *  aaos; naam ra number ma mistake nahos."
 *
 *  A seller pastes a list — send FORMAT to get the template:
 *
 *      TICKET
 *      Date: 5 Oct            (bholi / 2026-10-05 / 5/10 also fine)
 *      From: Mehsana
 *      Pay: cash
 *      1. Ram Thapa 9876543210 M
 *      2. Sita Thapa 9876543211 F 32
 *      3. Maya Thapa 9876543211 F 8
 *      4. Hari Gurung +977 9812345678
 *
 *  One passenger per line: name, then the 10-digit mobile, then M/F and
 *  age if known. Two lines with the SAME mobile are one booking (Sita and
 *  Maya travel on one PNR, each berth under its own name). The assistant
 *  reads the list back — every name, every number, the bus, the fare per
 *  booking and the grand total — and nothing is sold until the seller
 *  replies "ho". Then every booking goes through QuickTicket::sell(): the
 *  same seat lock, cut-off, fare and commission as the desk, each ticket
 *  to its own passenger's WhatsApp, the sale credited to the seller.
 *
 *  WHY IT IS DETERMINISTIC
 *  -----------------------
 *  The list is read by code, not by a model: a model that "understands"
 *  a list of twelve names will one day swap two mobiles, and a ticket on
 *  the wrong number is the one mistake the owner named. Every row is
 *  validated the same way (PersonName, a real 10-digit mobile, +977 kept),
 *  a row that fails is reported by line number and never guessed at, and
 *  the quote that was read back is pinned — a row whose bus, date, pickup
 *  or fare has changed by the time "ho" arrives is refused, not sold.
 *
 *  Staff only (the number is a staff record, or signed in via WaLogin),
 *  behind wa_bulk_on (OFF). Also offered to the model as the bulk_quote /
 *  bulk_issue tools, which call exactly the same two functions.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/personname.php';

final class WaBulk
{
    /** kv_store scope: the quote a seller is about to say ho to. */
    private const SCOPE = 'wa_bulk';
    /** A quote is good for this long. */
    private const TTL = 900;
    /** Selling a long list must not outlive the webhook's patience. */
    private const ISSUE_BUDGET_SEC = 40;

    public static function enabled(): bool
    {
        return Settings::getBool('wa_bulk_on', false);
    }

    /* =================================================================
     *  The deterministic path (wabot.php, staff only)
     * ================================================================= */

    /**
     * Answer a bulk-related message from a member of staff, or null when
     * the message is something else.
     *
     * @param array<string,mixed> $ctx from AiTools::whoIs()
     * @return array{text: string, media: ?string}|null
     */
    public static function handle(array $ctx, string $text): ?array
    {
        if (!self::enabled() || !in_array((string) ($ctx['role'] ?? ''), ['staff', 'admin'], true)) {
            return null;
        }
        $phone = (string) ($ctx['phone'] ?? '');
        $text  = trim($text);
        if ($phone === '' || $text === '') {
            return null;
        }

        if (self::isFormatRequest($text)) {
            return self::out(self::template());
        }

        $staged = self::staged($phone);
        if ($staged !== null) {
            if (!class_exists('WaBooking')) {
                require_once INCLUDE_PATH . '/wabooking.php';
            }
            if (WaBooking::isYes($text)) {
                $res = self::issue($ctx, is_array($ctx['admin'] ?? null) ? $ctx['admin'] : []);
                return self::out((string) $res['text']);
            }
            if (WaBooking::isNo($text) || WaBooking::isCancel($text)) {
                self::clear($phone);
                return self::out('ठिक छ, यो सूची रद्द भयो — कुनै टिकट काटिएन।');
            }
        }

        if (self::looksLikeBulk($text)) {
            $res = self::quote($ctx, self::parse($text));
            return self::out((string) $res['text']);
        }

        return null;
    }

    /** "FORMAT" / "format pathau" / "bulk format" / "फर्म्याट". */
    public static function isFormatRequest(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        return preg_match('/^(?:bulk\s*)?(?:format|formet|farmat|फर्म्याट|फर्मेट|ढाँचा)(?:\s+(?:pathau|pathaunus|deu|dinus|send|please|de|दिनुस्|पठाउनुस्))?\s*[.!?]?$/u', $t) === 1
            || preg_match('/^bulk\s*(?:ticket)?\s*(?:kasari|kaise|how)\b/u', $t) === 1;
    }

    /** The template a seller copies. */
    public static function template(): string
    {
        $max = max(1, Settings::getInt('wa_bulk_max_rows', 30));
        $d   = addDaysISO(todayISO(), 1);

        return "📋 BULK TICKET — यो ढाँचा कपी गरी भर्नुहोस्:\n\n"
            . "TICKET\n"
            . "Date: " . formatDate($d, 'j M') . "\n"
            . "From: Mehsana\n"
            . "Pay: cash\n"
            . "1. Ram Thapa 9876543210 M\n"
            . "2. Sita Thapa 9876543211 F 32\n"
            . "3. Maya Thapa 9876543211 F 8\n"
            . "4. Hari Gurung +977 9812345678 M\n\n"
            . "नियम:\n"
            . "• एक लाइनमा एक यात्रु: नाम, अनि १० अंकको मोबाइल। M/F र उमेर ऐच्छिक।\n"
            . "• एउटै नम्बर दुई लाइनमा = एउटै बुकिङ (परिवार), हरेक सिटमा आफ्नै नाम।\n"
            . "• नेपाली नम्बरमा +977 अगाडि लेख्नुहोस्।\n"
            . "• Date: bholi / 5 Oct / 2026-10-05 · From: चढ्ने ठाउँ · Pay: cash / upi / esewa / bank\n"
            . "• एक सन्देशमा बढीमा " . $max . " यात्रु।\n\n"
            . "म हरेक नाम, नम्बर र भाडा पढेर सुनाउँछु — तपाईंले \"ho\" लेखेपछि मात्र सबै टिकट काटिन्छ।";
    }

    /**
     * Does this look like a pasted list? The TICKET marker, or at least
     * two lines that each carry a name and a mobile. One bare line is left
     * to the assistant, which asks before it sells.
     */
    public static function looksLikeBulk(string $text): bool
    {
        $lines = preg_split('/\r\n|\r|\n/u', trim($text)) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), static fn (string $l): bool => $l !== ''));
        if ($lines === []) {
            return false;
        }
        $marker = preg_match('/^(?:bulk\s*)?(?:ticket|tickets|टिकट|टिकेट)s?\s*[:\-]?\s*$/iu', $lines[0]) === 1;
        $pax    = 0;
        foreach ($lines as $l) {
            if (self::phoneIn(TicketBot::asciiDigits($l)) !== null) {
                $pax++;
            }
        }
        return $pax >= 1 && ($marker || $pax >= 2);
    }

    /* =================================================================
     *  Reading the list
     * ================================================================= */

    /**
     * Read the list into bookings. Never throws; every problem is a
     * numbered line in errors[] the seller can fix.
     *
     * @return array{header: array<string,string>, rows: list<array<string,mixed>>,
     *               bookings: list<array<string,mixed>>, errors: list<string>, pax: int}
     */
    public static function parse(string $text): array
    {
        if (!class_exists('TicketBot')) {
            require_once INCLUDE_PATH . '/ticketbot.php';
        }
        $header   = ['date' => '', 'boarding' => '', 'direction' => '', 'pay' => ''];
        $rows     = [];
        $errors   = [];
        $free     = [];        // header text without a key, parsed as one sentence
        /* "1) Hari 98…, 2) Gopal 98…" on one line is two people: a list
           marker after a comma starts a new line, as WaBooking::parseParty
           already reads it. */
        $text     = (string) preg_replace('/(?<=\S)\s*[,;]\s*(?=\d{1,2}\s*[.)]\s*\p{L})/u', "\n", $text);
        $lines    = preg_split('/\r\n|\r|\n/u', trim($text)) ?: [];
        $n        = 0;

        foreach ($lines as $rawLine) {
            $n++;
            $l = trim((string) preg_replace('/\s+/u', ' ', TicketBot::asciiDigits($rawLine)));
            if ($l === '') {
                continue;
            }
            // The marker line.
            if ($rows === [] && preg_match('/^(?:bulk\s*)?(?:ticket|tickets|टिकट|टिकेट)s?\s*[:\-]?\s*$/iu', $l) === 1) {
                continue;
            }
            $phone = self::phoneIn($l);

            // "Key: value" header lines.
            if ($phone === null && preg_match('/^([\p{L} ]{2,24}?)\s*[:=]\s*(.+)$/u', $l, $m) === 1) {
                $key = self::headerKey(mb_strtolower(trim($m[1])));
                $val = trim($m[2]);
                if ($key === '') {
                    $errors[] = 'लाइन ' . $n . ': "' . mb_substr($l, 0, 30) . '" बुझिएन';
                    continue;
                }
                $header[$key] = $val;
                continue;
            }

            if ($phone === null) {
                /* A number that was MEANT as a mobile but is not one — five
                   to nine digits, a nine-digit typo with an age glued on —
                   is reported, never quietly read as header text or a name. */
                if (preg_match('/\d[\d\s\-]{3,}\d/u', $l) === 1 && preg_match('/\p{L}/u', $l) === 1
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/u', trim($l)) !== 1
                    && strlen(preg_replace('/\D/', '', $l) ?? '') >= 5) {
                    $errors[] = 'लाइन ' . $n . ': मोबाइल नम्बर मिलेन ("' . mb_substr($l, 0, 30) . '") — १० अंकको हुनुपर्छ';
                    continue;
                }
                /* No mobile on this line. Before the first passenger it is
                   header text ("bholi Mehsana bata"); after one it is a
                   travelling companion on the previous booking. */
                if ($rows === []) {
                    $free[] = $l;
                    continue;
                }
                $bare = self::stripMarker($l);
                [$name, $gender, $age, $why] = self::person($bare);
                if ($name === '') {
                    $errors[] = 'लाइन ' . $n . ': "' . mb_substr($l, 0, 30) . '" — ' . $why;
                    continue;
                }
                $prev   = $rows[count($rows) - 1];
                $rows[] = ['line' => $n, 'name' => $name, 'phone' => $prev['phone'], 'country' => $prev['country'],
                           'gender' => $gender, 'age' => $age, 'joined' => true];
                continue;
            }

            [$cand, $digits] = $phone;
            $norm = normalisePhone($cand);
            if (preg_match('/^[6-9]\d{9}$/', $norm) !== 1) {
                $errors[] = 'लाइन ' . $n . ': मोबाइल नम्बर मिलेन ("' . trim($cand) . '") — १० अंकको हुनुपर्छ';
                continue;
            }
            $country = (strlen($digits) === 13 && str_starts_with($digits, '977')) ? 'NP'
                     : ((strlen($digits) === 12 && str_starts_with($digits, '91')) ? 'IN' : '');
            /* A country word counts only AFTER the number ("… 98… nepal"):
               before it, "Nepal" is a surname — Indira Nepal is a person. */
            $pos    = mb_strpos($l, $cand);
            $before = $pos === false ? $l : mb_substr($l, 0, $pos);
            $after  = $pos === false ? '' : mb_substr($l, $pos + mb_strlen($cand));
            $ctry   = '/(?<![\p{L}\p{M}])(np|nepal|नेपाल|nepali|in|india|भारत|indian)(?![\p{L}\p{M}])/iu';
            if ($country === '' && preg_match($ctry, $after, $cm) === 1) {
                $country = in_array(mb_strtolower($cm[1]), ['np', 'nepal', 'नेपाल', 'nepali'], true) ? 'NP' : 'IN';
            }
            $after = (string) preg_replace($ctry, ' ', $after);
            $rest  = self::stripMarker($before . ' ' . $after);

            [$name, $gender, $age, $why] = self::person($rest);
            if ($name === '') {
                $errors[] = 'लाइन ' . $n . ': नाम बुझिएन ("' . mb_substr(trim($rest), 0, 30) . '") — ' . $why;
                continue;
            }
            foreach ($rows as $r) {
                if ($r['phone'] === $norm && PersonName::same((string) $r['name'], $name)) {
                    $errors[] = 'लाइन ' . $n . ': ' . $name . ' (' . $norm . ') दुई पटक लेखिएको छ';
                    continue 2;
                }
            }
            $rows[] = ['line' => $n, 'name' => $name, 'phone' => $norm, 'country' => $country,
                       'gender' => $gender, 'age' => $age, 'joined' => false];
        }

        // Header: explicit keys win, then whatever the free text said.
        $parsedFree = $free !== [] ? TicketBot::parse(implode(' ', $free)) : null;
        $header = self::readHeader($header, $parsedFree, $errors);

        // Group by mobile: one booking per number, in first-seen order.
        $bookings = [];
        foreach ($rows as $r) {
            $key = $r['phone'];
            if (!isset($bookings[$key])) {
                $bookings[$key] = ['phone' => $key, 'country' => $r['country'], 'passengers' => [], 'lines' => []];
            }
            if ($bookings[$key]['country'] === '' && $r['country'] !== '') {
                $bookings[$key]['country'] = $r['country'];
            }
            $bookings[$key]['passengers'][] = ['name' => $r['name'], 'gender' => $r['gender'], 'age' => $r['age']];
            $bookings[$key]['lines'][]      = (int) $r['line'];
        }
        $bookings = array_values($bookings);

        $max = max(1, Settings::getInt('wa_bulk_max_rows', 30));
        if (count($rows) > $max) {
            $errors[] = 'एक सन्देशमा बढीमा ' . $max . ' यात्रु — ' . count($rows) . ' पठाइयो। बाँकी अर्को सन्देशमा पठाउनुहोस्।';
            $keep = 0;
            foreach ($bookings as $i => $b) {
                $keep += count($b['passengers']);
                if ($keep > $max) {
                    $bookings = array_slice($bookings, 0, $i);
                    break;
                }
            }
        }
        $perBooking = BookingService::maxSeatsFor(true);
        foreach ($bookings as $i => $b) {
            if (count($b['passengers']) > $perBooking) {
                $errors[] = $b['phone'] . ' मा ' . count($b['passengers']) . ' जना — एउटा बुकिङमा बढीमा ' . $perBooking . ' सिट';
                unset($bookings[$i]);
            }
        }
        $bookings = array_values($bookings);

        $pax = 0;
        foreach ($bookings as $b) {
            $pax += count($b['passengers']);
        }

        return ['header' => $header, 'rows' => $rows, 'bookings' => $bookings, 'errors' => $errors, 'pax' => $pax];
    }

    /* =================================================================
     *  Quote, then sell
     * ================================================================= */

    /**
     * Plan every booking, pin what was read back, and say it.
     *
     * @param array<string,mixed> $ctx    from AiTools::whoIs()
     * @param array<string,mixed> $parsed from parse()
     * @return array{ok: bool, text: string, data: array<string,mixed>}
     */
    public static function quote(array $ctx, array $parsed): array
    {
        require_once INCLUDE_PATH . '/quickticket.php';
        $phone  = (string) ($ctx['phone'] ?? '');
        $errors = $parsed['errors'] ?? [];
        $items  = [];
        $total  = 0.0;
        $seats  = 0;
        $bus    = null;
        $left   = null;

        foreach (($parsed['bookings'] ?? []) as $i => $b) {
            $lead   = $b['passengers'][0] ?? ['name' => '', 'gender' => null];
            $genders = array_values(array_unique(array_filter(array_column($b['passengers'], 'gender'))));
            $opts = [
                'seats'     => count($b['passengers']),
                'date'      => (string) ($parsed['header']['date'] ?? ''),
                'direction' => (string) ($parsed['header']['direction'] ?? ''),
                'boarding'  => (string) ($parsed['header']['boarding'] ?? ''),
                'gender'    => count($genders) === 1 ? (string) $genders[0] : null,
                'customer'  => false,
            ];
            try {
                $plan = QuickTicket::plan($opts);
            } catch (RuntimeException $e) {
                $errors[] = self::who($b) . ': ' . self::deskSafe($e->getMessage());
                continue;
            }
            $expect = [
                'date'         => (string) $plan['date'],
                'direction'    => (string) $plan['direction'],
                'boardingCode' => (string) $plan['boardingCode'],
                'seats'        => (int) $plan['seatCount'],
                'total'        => (float) $plan['fare']['total'],
            ];
            if ($bus === null) {
                $bus  = $plan;
                $left = (int) $plan['seatsLeft'];
            }
            $items[] = [
                'key'        => 'b' . $i . '-' . $b['phone'],
                'phone'      => $b['phone'],
                'country'    => $b['country'],
                'passengers' => $b['passengers'],
                'opts'       => ['seats' => $opts['seats'], 'date' => $expect['date'], 'direction' => $expect['direction'],
                                 'boarding' => (string) $plan['boarding'], 'gender' => $opts['gender']],
                'expect'     => $expect,
                'total'      => $expect['total'],
                'dateLabel'  => (string) $plan['dateLabel'],
                'pickup'     => (string) $plan['boardingName'],
                'pickupTime' => (string) $plan['boardingTime'],
                'route'      => $plan['from'] . ' → ' . $plan['to'],
                'depTime'    => (string) $plan['depTime'],
            ];
            $total += $expect['total'];
            $seats += $expect['seats'];
        }

        if ($items === []) {
            self::clear($phone);
            $lines = ['❌ यो सूचीबाट कुनै टिकट बन्न सकेन।'];
            foreach (array_slice($errors, 0, 12) as $e) {
                $lines[] = '• ' . $e;
            }
            $lines[] = '';
            $lines[] = 'सही ढाँचा हेर्न "FORMAT" लेख्नुहोस्।';
            return ['ok' => false, 'text' => implode("\n", $lines), 'data' => ['errors' => $errors, 'bookings' => []]];
        }

        $pay = in_array($parsed['header']['pay'] ?? '', ['cash', 'upi', 'esewa', 'bank'], true)
            ? (string) $parsed['header']['pay']
            : (in_array(Settings::getString('quick_ticket_default_pay', 'cash'), ['cash', 'upi', 'esewa', 'bank'], true)
                ? Settings::getString('quick_ticket_default_pay', 'cash') : 'cash');

        $stage = [
            'turn'  => (int) ($ctx['turn'] ?? 0),
            'at'    => time(),
            'pay'   => $pay,
            'items' => $items,
            'seats' => $seats,
            'total' => $total,
        ];
        self::save($phone, $stage);

        $lines = ['🧾 BULK QUOTE — ' . count($items) . ' बुकिङ · ' . $seats . ' सिट'];
        if ($bus !== null) {
            $lines[] = '🚌 ' . $bus['from'] . ' → ' . $bus['to'] . ' · ' . $bus['dateLabel'] . ' · ' . $bus['depTime']
                     . ' · चढ्ने: ' . $bus['boardingName'] . ($bus['boardingTime'] !== '' ? ' ' . $bus['boardingTime'] : '');
        }
        $lines[] = '';
        foreach ($items as $k => $it) {
            $names = [];
            foreach ($it['passengers'] as $p) {
                $names[] = $p['name'] . ($p['gender'] !== null ? ' (' . mb_substr((string) $p['gender'], 0, 1) . ')' : '')
                    . ($p['age'] !== null ? ' ' . $p['age'] : '');
            }
            $lines[] = ($k + 1) . '. ' . implode(', ', $names)
                . ' · ' . ($it['country'] === 'NP' ? '+977 ' : '') . self::spaced($it['phone'])
                . ' · ' . $it['expect']['seats'] . ' सिट · ' . inr((float) $it['total']);
        }
        if ($errors !== []) {
            $lines[] = '';
            foreach (array_slice($errors, 0, 8) as $e) {
                $lines[] = '❌ ' . $e;
            }
        }
        $lines[] = '';
        $lines[] = 'जम्मा: ' . $seats . ' सिट · ' . inr($total) . ' · भुक्तानी: ' . $pay;
        if ($left !== null && $left < $seats) {
            $lines[] = '⚠️ बसमा ' . $left . ' सिट मात्र बाँकी — सबै नअट्न सक्छ।';
        }
        $lines[] = 'नाम र नम्बर जाँच्नुहोस्। सबै काट्न "ho" लेख्नुहोस्, रद्द गर्न "no"।';

        return ['ok' => true, 'text' => implode("\n", $lines), 'data' => [
            'bookings' => array_map(static fn (array $it): array => [
                'passengers' => array_column($it['passengers'], 'name'),
                'phone'      => $it['phone'],
                'country'    => $it['country'],
                'date'       => $it['expect']['date'],
                'pickup'     => $it['pickup'],
                'seats'      => $it['expect']['seats'],
                'total'      => $it['total'],
            ], $items),
            'seats'  => $seats,
            'total'  => $total,
            'totalLabel' => inr($total),
            'pay'    => $pay,
            'errors' => $errors,
        ]];
    }

    /**
     * Sell what was quoted, after "ho". Each booking is re-planned and
     * refused if it no longer matches what was read back. The stage is
     * consumed FIRST, so a repeat "ho" can never sell the list twice.
     *
     * @param array<string,mixed> $ctx   from AiTools::whoIs()
     * @param array<string,mixed> $admin the seller's admins row
     * @return array{ok: bool, text: string, data: array<string,mixed>}
     */
    public static function issue(array $ctx, array $admin): array
    {
        require_once INCLUDE_PATH . '/quickticket.php';
        $phone = (string) ($ctx['phone'] ?? '');
        if (!self::enabled()) {
            return ['ok' => false, 'text' => 'Bulk ticket WhatsApp मा बन्द छ।', 'data' => []];
        }
        if ((int) ($admin['id'] ?? 0) <= 0) {
            return ['ok' => false, 'text' => 'स्टाफ नम्बर वा लगइन बिना बिक्री हुँदैन।', 'data' => []];
        }
        $stage = self::take($phone, (int) ($ctx['turn'] ?? 0));
        if ($stage === null) {
            return ['ok' => false, 'text' => 'कुनै सूची quote भएको छैन, वा quote पुरानो भयो। सूची फेरि पठाउनुहोस्।', 'data' => []];
        }

        $deadline = microtime(true) + self::ISSUE_BUDGET_SEC;
        $done = [];
        $failed = [];
        $seats = 0;
        $total = 0.0;

        foreach ($stage['items'] as $it) {
            if (microtime(true) >= $deadline) {
                $failed[] = ['who' => self::who($it), 'why' => 'समय सकियो — यो लाइन फेरि पठाउनुहोस्'];
                continue;
            }
            $t0 = microtime(true);
            try {
                $fresh = QuickTicket::plan($it['opts'] + ['customer' => false]);
                $diff  = [];
                foreach (['date', 'direction', 'boardingCode', 'seats'] as $k) {
                    $now = $k === 'seats' ? (int) $fresh['seatCount'] : (string) $fresh[$k];
                    if ($now !== $it['expect'][$k]) {
                        $diff[] = $k;
                    }
                }
                if (abs((float) $fresh['fare']['total'] - (float) $it['expect']['total']) >= 0.01) {
                    $diff[] = 'fare';
                }
                if ($diff !== []) {
                    throw new RuntimeException('quote पछि ' . implode(', ', $diff) . ' बदलियो — फेरि quote गर्नुहोस्');
                }

                $res = QuickTicket::sell([
                    'name'       => (string) $it['passengers'][0]['name'],
                    'phone'      => (string) $it['phone'],
                    'country'    => (string) $it['country'],
                    'seats'      => (int) $it['expect']['seats'],
                    'date'       => (string) $it['opts']['date'],
                    'direction'  => (string) $it['opts']['direction'],
                    'boarding'   => (string) $it['opts']['boarding'],
                    'gender'     => $it['passengers'][0]['gender'] ?? null,
                    'pay'        => (string) $stage['pay'],
                    'passengers' => $it['passengers'],
                    'note'       => 'WhatsApp bulk',
                ], $admin);

                $done[] = [
                    'who'   => self::who($it),
                    'pnr'   => (string) ($res['pnr'] ?? ''),
                    'seats' => (array) ($res['seats'] ?? []),
                    'total' => (float) ($res['total'] ?? 0),
                    'sent'  => (bool) ($res['whatsapp']['sent'] ?? false),
                    'bookingId' => (int) ($res['bookingId'] ?? 0),
                ];
                $seats += (int) $it['expect']['seats'];
                $total += (float) ($res['total'] ?? 0);
                self::record($ctx, true, 'bulk sold ' . ($res['pnr'] ?? ''), (int) ($res['bookingId'] ?? 0), $t0);
            } catch (RuntimeException $e) {
                $failed[] = ['who' => self::who($it), 'why' => self::deskSafe($e->getMessage())];
                self::record($ctx, false, 'bulk refused: ' . $e->getMessage(), null, $t0);
            } catch (Throwable $e) {
                Logger::exception($e, 'whatsapp');
                $failed[] = ['who' => self::who($it), 'why' => 'हाम्रो तर्फबाट समस्या — अफिसलाई भन्नुहोस्'];
                self::record($ctx, false, 'bulk error', null, $t0);
            }
        }

        $lines = [];
        if ($done !== []) {
            $lines[] = '✅ ' . count($done) . ' टिकट काटियो (' . $seats . ' सिट · ' . inr($total) . ')';
            foreach ($done as $i => $d) {
                $lines[] = ($i + 1) . '. ' . $d['who'] . ' · ' . $d['pnr'] . ' · सिट ' . implode(', ', $d['seats']);
            }
        }
        if ($failed !== []) {
            $lines[] = '';
            $lines[] = '❌ ' . count($failed) . ' काटिएन:';
            foreach ($failed as $f) {
                $lines[] = '• ' . $f['who'] . ' — ' . $f['why'];
            }
        }
        if ($done !== []) {
            $lines[] = '';
            $lines[] = 'हरेक टिकट यात्रुकै WhatsApp मा पठाइयो; कमिसन तपाईंको वालेटमा जोडियो।';
        }

        return ['ok' => $done !== [], 'text' => implode("\n", $lines), 'data' => [
            'sold'   => $done,
            'failed' => $failed,
            'seats'  => $seats,
            'total'  => $total,
            'totalLabel' => inr($total),
        ]];
    }

    /* =================================================================
     *  The staged quote
     * ================================================================= */

    public static function staged(string $phone): ?array
    {
        if ($phone === '') {
            return null;
        }
        try {
            $row = Database::fetch('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::SCOPE, 'k' => $phone]);
        } catch (Throwable $e) {
            return null;
        }
        $v = $row !== null ? json_decode((string) $row['kvalue'], true) : null;
        if (!is_array($v) || (time() - (int) ($v['at'] ?? 0)) > self::TTL || !is_array($v['items'] ?? null)) {
            self::clear($phone);
            return null;
        }
        return $v;
    }

    /**
     * Consume the stage. From the model's tools a quote and its sale must
     * sit in different turns (the seller must have read it); from the
     * deterministic path the "ho" is by construction a later message.
     */
    private static function take(string $phone, int $turn): ?array
    {
        $v = self::staged($phone);
        if ($v === null) {
            return null;
        }
        if ($turn > 0 && (int) ($v['turn'] ?? 0) >= $turn && !Settings::getBool('wa_agent_oneshot', false)) {
            return null;
        }
        self::clear($phone);
        return $v;
    }

    private static function save(string $phone, array $stage): void
    {
        $json = json_encode($stage, JSON_UNESCAPED_UNICODE);
        try {
            $done = Database::update('kv_store', ['kvalue' => $json, 'updated_by' => 'wabulk'],
                'kscope = :s AND kkey = :k', ['s' => self::SCOPE, 'k' => $phone]);
            if ($done === 0) {
                Database::insertIgnore('kv_store', ['kscope' => self::SCOPE, 'kkey' => $phone, 'kvalue' => $json, 'updated_by' => 'wabulk']);
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
        }
    }

    public static function clear(string $phone): void
    {
        if ($phone === '') {
            return;
        }
        try {
            Database::delete('kv_store', 'kscope = :s AND kkey = :k', ['s' => self::SCOPE, 'k' => $phone]);
        } catch (Throwable $ignored) {
        }
    }

    /* =================================================================
     *  Small readers
     * ================================================================= */

    /**
     * The mobile in a line, as typed and as digits, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function phoneIn(string $l): ?array
    {
        if (preg_match_all('/\+?\d[\d\s\-()]{7,16}\d/u', $l, $m) < 1) {
            return null;
        }
        $valid = static function (string $d): bool {
            $n = strlen($d);
            return $n === 10 || ($n === 12 && str_starts_with($d, '91')) || ($n === 13 && str_starts_with($d, '977'))
                || ($n === 11 && str_starts_with($d, '0'));
        };
        /* "987654321 2" — a 9-digit typo with an age glued on — strips to a
           perfectly valid ten digits that belong to SOMEBODY ELSE. The same
           guard TicketBot::parse() carries: a run whose last piece is only
           one or two digits is never taken whole. */
        $gluedTail = static function (string $run): bool {
            $pieces = preg_split('/\s+/', trim($run)) ?: [];
            if (count($pieces) < 2) {
                return false;
            }
            $last = preg_replace('/\D/', '', (string) end($pieces)) ?? '';
            return $last !== '' && strlen($last) <= 2;
        };
        foreach ($m[0] as $cand) {
            $cand = trim($cand);
            $d = preg_replace('/\D/', '', $cand) ?? '';
            if ($valid($d) && !$gluedTail($cand)) {
                return [$cand, $d];
            }
        }
        // A run of the right length hidden inside a longer one ("98765 43210 32").
        foreach ($m[0] as $cand) {
            $pieces = preg_split('/\s+/', trim($cand)) ?: [];
            $np = count($pieces);
            for ($i = 0; $i < $np; $i++) {
                $one = $pieces[$i];
                $d1  = preg_replace('/\D/', '', $one) ?? '';
                if ($valid($d1)) {
                    return [$one, $d1];
                }
                // Join two pieces only for a number typed with a space
                // ("98765 43210", "+977 9812345678"), never a short tail.
                $next = $pieces[$i + 1] ?? '';
                $d2   = preg_replace('/\D/', '', $next) ?? '';
                if ($next !== '' && strlen($d2) >= 3 && $valid($d1 . $d2)) {
                    return [$one . ' ' . $next, $d1 . $d2];
                }
            }
        }
        return null;
    }

    private static function stripMarker(string $l): string
    {
        return trim((string) preg_replace('/^\s*(?:\d{1,2}\s*[.)\-:]|[-•*·])\s*/u', '', trim($l)));
    }

    /**
     * Name, gender and age out of what is left of a line once the mobile
     * is gone.
     *
     * @return array{0: string, 1: ?string, 2: ?int, 3: string} name, gender, age, why-not
     */
    private static function person(string $rest): array
    {
        $rest = ' ' . trim((string) preg_replace('/[,;\/|]+/u', ' ', $rest)) . ' ';
        $gender = null;
        /* Letter AND mark lookarounds: in Devanagari the letter before "म"
           in "राम" is a vowel sign (a mark, not a letter), so a letter-only
           lookbehind read the "म" inside every राम as "female". */
        $gre = '/(?<![\p{L}\p{M}])(male|female|other|purush|mahila|पुरुष|महिला|m|f|पु|म|स्त्री|mahilaa)(?![\p{L}\p{M}])/iu';
        if (preg_match($gre, $rest, $g) === 1) {
            $w = mb_strtolower($g[1]);
            $gender = in_array($w, ['female', 'f', 'mahila', 'mahilaa', 'महिला', 'म', 'स्त्री'], true) ? 'Female'
                    : ($w === 'other' ? 'Other' : 'Male');
            // Only the token itself: str_replace('म') would also eat the म inside कमला.
            $rest = (string) preg_replace($gre, ' ', $rest, 1);
        }
        $age = null;
        $are = '/(?<![\d\p{L}])(\d{1,3})(?![\d\p{L}])/u';
        if (preg_match($are, $rest, $a) === 1) {
            $v = (int) $a[1];
            if ($v >= 1 && $v <= 120) {
                $age = $v;
            }
            $rest = (string) preg_replace($are, ' ', $rest, 1);
        }
        // Words that describe, not name: "umer", "age", "seat".
        $rest = (string) preg_replace('/(?<![\p{L}\p{M}])(umer|umar|age|उमेर|yrs|years|saal|barsa|seat|sit)(?![\p{L}\p{M}])/iu', ' ', $rest);
        $rest = trim((string) preg_replace('/\s+/u', ' ', $rest));

        $name = PersonName::clean($rest);
        return [$name, $gender, $age, $name === '' ? PersonName::why($rest) : ''];
    }

    private static function headerKey(string $k): string
    {
        $k = trim($k);
        return match (true) {
            in_array($k, ['date', 'miti', 'din', 'dinank', 'tarikh', 'travel date', 'मिति', 'तारिख', 'दिन', 'kahile'], true) => 'date',
            in_array($k, ['from', 'pickup', 'boarding', 'bata', 'chadne', 'stop', 'place', 'town', 'बाट', 'चढ्ने', 'चढ्ने ठाउँ', 'kaha bata', 'from where'], true) => 'boarding',
            in_array($k, ['to', 'direction', 'jane', 'aaune', 'route', 'towards', 'तर्फ', 'जाने', 'बस'], true) => 'direction',
            in_array($k, ['pay', 'payment', 'paisa', 'bhuktani', 'भुक्तानी', 'method', 'paid', 'tirne'], true) => 'pay',
            in_array($k, ['note', 'notes', 'remark', 'remarks'], true) => 'note',
            default => '',
        };
    }

    /**
     * Turn the header words into what QuickTicket::plan() takes.
     *
     * @param array<string,string> $header
     * @param array<string,mixed>|null $free TicketBot::parse() of the keyless lines
     * @param list<string> $errors
     * @return array<string,string>
     */
    private static function readHeader(array $header, ?array $free, array &$errors): array
    {
        $out = ['date' => '', 'boarding' => '', 'direction' => '', 'pay' => ''];

        $rawDate = trim((string) ($header['date'] ?? ''));
        if ($rawDate !== '') {
            $p = TicketBot::parse('miti ' . $rawDate);
            if ((string) ($p['date'] ?? '') === '') {
                $errors[] = 'Date "' . $rawDate . '" बुझिएन — bholi, 5 Oct वा 2026-10-05 जस्तो लेख्नुहोस्';
            } else {
                $out['date'] = (string) $p['date'];
            }
        } elseif ($free !== null && (string) ($free['date'] ?? '') !== '') {
            $out['date'] = (string) $free['date'];
        }

        $rawFrom = trim((string) ($header['boarding'] ?? ''));
        if ($rawFrom !== '') {
            $p = TicketBot::parse($rawFrom . ' bata');
            $out['boarding'] = (string) ($p['boarding'] ?? '') !== '' ? (string) $p['boarding'] : Security::clean($rawFrom, 80);
        } elseif ($free !== null && (string) ($free['boarding'] ?? '') !== '') {
            $out['boarding'] = (string) $free['boarding'];
        }

        $rawDir = mb_strtolower(trim((string) ($header['direction'] ?? '')));
        if ($rawDir !== '') {
            if (preg_match('/nepal|jane|jana|going|rupaidiha|border|नेपाल|जाने|रुपैडिया/u', $rawDir) === 1) {
                $out['direction'] = 'toNepal';
            } elseif (preg_match('/india|aaune|farkine|return|wapas|gujarat|surat|ahmedabad|mehsana|baroda|भारत|आउने|फर्कने|गुजरात/u', $rawDir) === 1) {
                $out['direction'] = 'toIndia';
            } else {
                $p = TicketBot::parse($rawDir);
                $out['direction'] = (string) ($p['direction'] ?? '');
            }
        } elseif ($free !== null && (string) ($free['direction'] ?? '') !== '') {
            $out['direction'] = (string) $free['direction'];
        }

        $rawPay = mb_strtolower(trim((string) ($header['pay'] ?? '')));
        if ($rawPay !== '') {
            $out['pay'] = match (true) {
                str_contains($rawPay, 'cash') || str_contains($rawPay, 'nagad') || str_contains($rawPay, 'नगद') => 'cash',
                str_contains($rawPay, 'upi') || str_contains($rawPay, 'gpay') || str_contains($rawPay, 'phonepe') || str_contains($rawPay, 'paytm') => 'upi',
                str_contains($rawPay, 'esewa') || str_contains($rawPay, 'khalti') => 'esewa',
                str_contains($rawPay, 'bank') || str_contains($rawPay, 'neft') || str_contains($rawPay, 'transfer') => 'bank',
                default => '',
            };
            if ($out['pay'] === '') {
                $errors[] = 'Pay "' . $rawPay . '" बुझिएन — cash, upi, esewa वा bank लेख्नुहोस्';
            }
        } elseif ($free !== null && in_array((string) ($free['pay'] ?? ''), ['cash', 'upi', 'esewa', 'bank'], true)) {
            $out['pay'] = (string) $free['pay'];
        }

        return $out;
    }

    /** "Ram Thapa, Sita Thapa (98765 43210)" — how a booking is named in a reply. */
    private static function who(array $b): string
    {
        $names = array_column((array) ($b['passengers'] ?? []), 'name');
        return implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' +' . (count($names) - 3) : '')
            . ' (' . self::spaced((string) ($b['phone'] ?? '')) . ')';
    }

    private static function spaced(string $digits): string
    {
        return strlen($digits) === 10 ? substr($digits, 0, 5) . ' ' . substr($digits, 5) : $digits;
    }

    /** QuickTicket's bilingual refusals: keep the Nepali half when there is one. */
    private static function deskSafe(string $msg): string
    {
        $parts = explode(' / ', $msg, 2);
        $ne    = trim($parts[1] ?? '');
        return mb_substr($ne !== '' ? $ne : trim($parts[0]), 0, 160);
    }

    /** One ai_agent_calls row per booking attempted, so the office sees bulk sales beside the rest. */
    private static function record(array $ctx, bool $ok, string $detail, ?int $bookingId, float $t0): void
    {
        try {
            require_once INCLUDE_PATH . '/aitools.php';
            AiTools::logCall('bulk_issue', [], $ctx, $ok, $detail, $bookingId, $t0);
        } catch (Throwable $ignored) {
        }
    }

    /** @return array{text: string, media: ?string} */
    private static function out(string $text, ?string $media = null): array
    {
        return ['text' => $text, 'media' => $media];
    }
}
