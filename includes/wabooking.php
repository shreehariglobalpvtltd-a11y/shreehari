<?php
/**
 * =====================================================================
 *  WaBooking — book a seat inside the WhatsApp chat (20 Sep 2026)
 *
 *  Until now a request like "2 seat nepal 25 tarikh" only became a DRAFT
 *  for the desk (TicketBrain::capture) and the passenger heard "someone
 *  will confirm". This class holds the conversation instead: it keeps
 *  what it has understood, asks for exactly what is still missing — in
 *  the passenger's own language — shows the fare, and only after an
 *  explicit yes does it create the booking and send the ticket back.
 *
 *  It invents nothing. Every piece is the machinery the desk and the app
 *  already use:
 *    TicketBot::parse/detectLang/question  — reading the message, asking
 *    TicketBot::suggest                    — merge + live plan + what is missing
 *    QuickTicket::sellCustomer             — the sale, with every customer
 *                                            rule (seat cap, cut-off, horizon,
 *                                            gender lock, duplicate guard)
 *
 *  Two rules this file will not bend:
 *    - a seat is never sold without an explicit confirmation, and
 *    - nothing here marks a booking paid. An unpaid booking comes back
 *      with the payment QR; the desk confirms the money as always.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaBooking
{
    /** A conversation this old is over; the next message starts fresh. */
    private const TTL   = 1800;          // 30 minutes
    private const SCOPE = 'wa_book';

    /**
     * Handle one inbound message.
     *
     * @return array{text: string, media: ?string}|null null = not a booking
     *         conversation, so the caller keeps its own reply.
     */
    public static function handle(string $phoneDigits, string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || $phoneDigits === '') {
            return null;
        }
        if (!Settings::getBool('wa_booking_on', true)) {
            return null;
        }

        $state = self::load($phoneDigits);
        $lang  = TicketBot::detectLang($text);
        if ($state !== null && ($state['lang'] ?? '') !== '') {
            $lang = (string) $state['lang'];          // the language they opened with
        }

        // "cancel" ends it at any point, in any of the four languages.
        if (self::isCancel($text)) {
            if ($state === null) {
                return null;
            }
            self::clear($phoneDigits);
            return self::out(self::say('cancelled', $lang));
        }

        // Nothing in progress and nothing bookable in the message — let the
        // PNR lookup, the assistant or the menu answer instead.
        if ($state === null && !self::looksLikeRequest($text)) {
            return null;
        }

        $slots = is_array($state['slots'] ?? null) ? $state['slots'] : [];

        /* A tapped-looking answer ("2") replies to the question we asked, not
           to the whole message. Options were stored with the question. */
        $awaiting = (string) ($state['awaiting'] ?? '');
        if ($awaiting !== '' && $awaiting !== 'confirm') {
            $picked = self::pickOption($text, (array) ($state['options'] ?? []));
            if ($picked !== null) {
                $slots[$awaiting] = $picked;
                $text = '';                          // consumed by the answer
            } elseif ($awaiting === 'name') {
                // They often answer the name question with "naam Ram" too.
                $slots['name'] = self::stripNameWord($text);
                $text = '';
            }
        }

        /* "naam Ram Bahadur" / "नाम राम" — the one correction the parser cannot
           make for itself, since any word could be a name. */
        if (preg_match('/^\s*(?:naam|nam|name|नाम)\s*[:\-]?\s*(.{2,60})$/ui', $text, $m)) {
            $slots['name'] = trim($m[1]);
            $text = '';
        }

        // The summary was on the table: yes sells it, no drops it.
        if ($awaiting === 'confirm') {
            if (self::isYes($text)) {
                return self::sell($phoneDigits, $slots, $lang);
            }
            if (self::isNo($text)) {
                self::clear($phoneDigits);
                return self::out(self::say('cancelled', $lang));
            }
            // Anything else is a correction — fall through and re-plan.
        }

        /* What THIS message states wins over what the conversation already
           held: suggest() prefers an explicit input over parsed text, so
           without this "3 seat" after a 2-seat summary changed nothing. */
        if ($text !== '') {
            $fresh = TicketBot::parse($text);
            foreach (['seats', 'date', 'direction', 'boarding'] as $k) {
                $v = $fresh[$k] ?? '';
                if ($v !== '' && $v !== 0 && $v !== null) {
                    $slots[$k] = $v;
                }
            }
        }

        /* One call does the reading, the memory, the live plan and the
           "what is still missing" list. */
        $sug = TicketBot::suggest([
            'text'         => $text,
            'phone'        => $phoneDigits,
            'sessionPhone' => $phoneDigits,
            'name'         => $slots['name']      ?? '',
            'seats'        => $slots['seats']     ?? null,
            'date'         => $slots['date']      ?? '',
            'direction'    => $slots['direction'] ?? '',
            'boarding'     => $slots['boarding']  ?? '',
        ], null);

        foreach (['name', 'seats', 'date', 'direction', 'boarding', 'country'] as $k) {
            $v = $sug['prefill'][$k] ?? '';
            if ($v !== '' && $v !== null && $v !== 0) {
                $slots[$k] = $v;
            }
        }

        $ask = $sug['ask'] ?? null;
        if (is_array($ask) && ($ask['field'] ?? '') !== '') {
            $options = array_values(array_filter(
                (array) ($ask['options'] ?? []),
                static fn ($o): bool => is_array($o) && ($o['value'] ?? '') !== '' && ($o['value'] ?? '') !== 'other'
            ));
            self::save($phoneDigits, $slots, (string) $ask['field'], $options, $lang);
            return self::out(self::withOptions((string) $ask['question'], $options, $lang));
        }

        /* The name is asked LAST, once the trip itself is settled: asked
           first, the next message ("Mehsana") was read as the name. */
        if (trim((string) ($slots['name'] ?? '')) === '') {
            self::save($phoneDigits, $slots, 'name', [], $lang);
            return self::out(self::say('askName', $lang));
        }

        $plan = $sug['plan'] ?? null;
        if (!is_array($plan) || $plan === []) {
            self::clear($phoneDigits);
            $why = trim((string) ($sug['planError'] ?? ''));
            return self::out($why !== '' ? $why : self::say('noPlan', $lang));
        }

        // Everything is known: show what it will cost and wait for a yes.
        self::save($phoneDigits, $slots, 'confirm', [], $lang);
        return self::out(self::summary($plan, $slots, $lang));
    }

    /* ----------------------------------------------------------------- */

    /** The sale itself, after an explicit yes. */
    private static function sell(string $phoneDigits, array $slots, string $lang): array
    {
        try {
            $res = QuickTicket::sellCustomer([
                'name'      => (string) ($slots['name'] ?? ''),
                'phone'     => $phoneDigits,
                'country'   => (string) ($slots['country'] ?? ''),
                'seats'     => (int) ($slots['seats'] ?? 1),
                'direction' => (string) ($slots['direction'] ?? ''),
                'date'      => (string) ($slots['date'] ?? ''),
                'boarding'  => (string) ($slots['boarding'] ?? ''),
            ], null);
        } catch (Throwable $e) {
            self::clear($phoneDigits);
            // QuickTicket throws passenger-safe bilingual text on purpose.
            return self::out(trim($e->getMessage()) !== '' ? $e->getMessage() : self::say('failed', $lang));
        }

        self::clear($phoneDigits);

        $pnr    = (string) ($res['pnr'] ?? '');
        $seats  = implode(', ', array_map('strval', (array) ($res['seats'] ?? [])));
        $total  = (string) ($res['totalLabel'] ?? '');
        $when   = (string) ($res['dateLabel'] ?? ($slots['date'] ?? ''));
        $isPaid = ($res['status'] ?? '') === 'confirmed';

        $lines = [self::say('booked', $lang), ''];
        $lines[] = self::label('pnr', $lang) . ': ' . $pnr;
        if ($when !== '')  { $lines[] = self::label('date', $lang)  . ': ' . $when; }
        if ($seats !== '') { $lines[] = self::label('seat', $lang)  . ': ' . $seats; }
        if ($total !== '') { $lines[] = self::label('total', $lang) . ': ' . $total; }

        $media = null;
        if ($isPaid) {
            $lines[] = '';
            $lines[] = self::say('ticketBelow', $lang);
            $media   = Ticket::imageUrl($pnr);
        } else {
            $lines[] = '';
            $lines[] = self::say('payNow', $lang);
            $lines[] = appUrl('pay.php?pnr=' . urlencode($pnr));
            $media   = appUrl('pay-image.php?pnr=' . urlencode($pnr));
        }

        return self::out(implode("\n", $lines), $media);
    }

    /** What the passenger is about to buy. */
    private static function summary(array $plan, array $slots, string $lang): string
    {
        /* plan() hands back clean parts — boardingName without the "@ 23:00"
           and the GPS pair the raw stop label carries. */
        $route = trim((string) ($plan['from'] ?? '') . ' → ' . (string) ($plan['to'] ?? ''), ' →');
        $stop  = trim((string) ($plan['boardingName'] ?? ''));
        $code  = trim((string) ($plan['boardingCode'] ?? ''));
        $time  = trim((string) ($plan['boardingTime'] ?? ''));
        $ts    = $time !== '' ? strtotime($time) : false;
        $timeL = $ts !== false ? date('g:i A', $ts) : $time;
        $seats = implode(', ', array_map('strval', (array) ($plan['seats'] ?? [])));
        $total = (float) ($plan['fare']['total'] ?? 0);

        $lines = [self::say('checkThis', $lang), ''];
        $lines[] = self::label('name', $lang) . ': ' . (string) ($slots['name'] ?? '');
        if ($route !== '') {
            $lines[] = self::label('route', $lang) . ': ' . $route;
        }
        $lines[] = self::label('date', $lang) . ': ' . (string) ($plan['dateLabel'] ?? ($slots['date'] ?? ''));
        if ($stop !== '') {
            $lines[] = self::label('board', $lang) . ': ' . ($code !== '' ? $code . ' · ' : '') . $stop
                     . ($timeL !== '' ? ' · ' . $timeL : '');
        }
        $lines[] = self::label('pax', $lang) . ': ' . (int) ($plan['seatCount'] ?? ($slots['seats'] ?? 1));
        if ($seats !== '') {
            $lines[] = self::label('seat', $lang) . ': ' . $seats;
        }
        if (($plan['busName'] ?? '') !== '') {
            $lines[] = self::label('bus', $lang) . ': ' . (string) $plan['busName']
                     . (($plan['busNumber'] ?? '') !== '' ? ' · ' . (string) $plan['busNumber'] : '');
        }
        if ($total > 0) {
            $lines[] = self::label('total', $lang) . ': ' . inr($total);
        }
        $lines[] = '';
        $lines[] = self::say('confirmQ', $lang);
        $lines[] = self::say('howToFix', $lang);

        return implode("
", $lines);
    }

    /* ----------------------------------------------------------------- *
     *  Wording — en / hi / ne / gu, the four TicketBot already speaks.
     * ----------------------------------------------------------------- */

    private static function say(string $key, string $lang): string
    {
        $t = [
            'askName' => [
                'en' => 'What name should the ticket be in?',
                'hi' => 'टिकट किस नाम से बनाएँ?',
                'ne' => 'टिकट कुन नाममा बनाउने?',
                'gu' => 'ટિકિટ કયા નામે બનાવીએ?',
            ],
            'checkThis' => [
                'en' => 'Please check this booking:',
                'hi' => 'यह बुकिंग देख लीजिए:',
                'ne' => 'यो बुकिङ हेर्नुहोस्:',
                'gu' => 'આ બુકિંગ જોઈ લો:',
            ],
            'confirmQ' => [
                'en' => 'Shall I book it? Reply YES to confirm, NO to cancel.',
                'hi' => 'बुक कर दूँ? हाँ लिखें, या नहीं लिखें।',
                'ne' => 'बुक गरौं? हो लेख्नुहोस्, वा होइन लेख्नुहोस्।',
                'gu' => 'બુક કરી દઉં? હા લખો, કે ના લખો.',
            ],
            'howToFix' => [
                'en' => 'To change something, just write it: another date, more seats, a different pickup, or "name Ram Bahadur".',
                'hi' => 'कुछ बदलना हो तो लिख दीजिए: दूसरी तारीख, ज्यादा सीट, दूसरी जगह, या "नाम राम बहादुर"।',
                'ne' => 'केही बदल्नु परे लेख्नुहोस्: अर्को मिति, थप सिट, अर्को ठाउँ, वा "नाम राम बहादुर"।',
                'gu' => 'કંઈ બદલવું હોય તો લખો: બીજી તારીખ, વધુ સીત, બીજી જગ્યા, કે "નામ રામ".',
            ],
            'booked' => [
                'en' => 'Done — your seat is booked.',
                'hi' => 'हो गया — आपकी सीट बुक है।',
                'ne' => 'भयो — तपाईंको सिट बुक भयो।',
                'gu' => 'થઈ ગયું — તમારી સીટ બુક છે.',
            ],
            'ticketBelow' => [
                'en' => 'Your e-ticket is attached. Safe journey!',
                'hi' => 'आपका ई-टिकट साथ में है। यात्रा शुभ हो!',
                'ne' => 'तपाईंको ई-टिकट संलग्न छ। शुभ यात्रा!',
                'gu' => 'તમારી ઈ-ટિકિટ સાથે છે. શુભ યાત્રા!',
            ],
            'payNow' => [
                'en' => 'Scan the QR above to pay, or tap here. The ticket arrives here as soon as the payment is checked.',
                'hi' => 'ऊपर का QR स्कैन करके भुगतान करें, या यहाँ दबाएँ। भुगतान जाँचते ही टिकट यहीं आ जाएगा।',
                'ne' => 'माथिको QR स्क्यान गरेर तिर्नुहोस्, वा यहाँ थिच्नुहोस्। भुक्तानी जाँच भएपछि टिकट यहीँ आउँछ।',
                'gu' => 'ઉપરનો QR સ્કેન કરીને ચૂકવો, કે અહીં દબાવો. ચુકવણી તપાસતાં જ ટિકિટ અહીં આવશે.',
            ],
            'cancelled' => [
                'en' => 'Cancelled — nothing was booked. Message me whenever you want to travel.',
                'hi' => 'रद्द कर दिया — कुछ बुक नहीं हुआ। जब यात्रा करनी हो, लिख दीजिए।',
                'ne' => 'रद्द गरें — केही बुक भएको छैन। यात्रा गर्नु परे लेख्नुहोस्।',
                'gu' => 'રદ કર્યું — કંઈ બુક થયું નથી. મુસાફરી કરવી હોય ત્યારે લખો.',
            ],
            'noPlan' => [
                'en' => 'No seat can be sold for that just now. Please call the office.',
                'hi' => 'अभी उसके लिए सीट नहीं दे पा रहे। कृपया ऑफिस पर कॉल करें।',
                'ne' => 'अहिले त्यसको लागि सिट दिन सकिएन। कृपया कार्यालयमा फोन गर्नुहोस्।',
                'gu' => 'અત્યારે તેના માટે સીટ આપી શકાતી નથી. ઓફિસે ફોન કરો.',
            ],
            'failed' => [
                'en' => 'The booking could not be completed. Please call the office.',
                'hi' => 'बुकिंग पूरी नहीं हो सकी। कृपया ऑफिस पर कॉल करें।',
                'ne' => 'बुकिङ पूरा हुन सकेन। कृपया कार्यालयमा फोन गर्नुहोस्।',
                'gu' => 'બુકિંગ પૂરી થઈ શકી નથી. ઓફિસે ફોન કરો.',
            ],
        ];
        return $t[$key][$lang] ?? $t[$key]['en'];
    }

    private static function label(string $key, string $lang): string
    {
        $l = [
            'pnr'   => ['en' => 'Booking no', 'hi' => 'बुकिंग नं', 'ne' => 'बुकिङ नं', 'gu' => 'બુકિંગ નં'],
            'name'  => ['en' => 'Name',       'hi' => 'नाम',       'ne' => 'नाम',      'gu' => 'નામ'],
            'route' => ['en' => 'Route',      'hi' => 'रूट',       'ne' => 'बाटो',     'gu' => 'રૂટ'],
            'date'  => ['en' => 'Date',       'hi' => 'तारीख',     'ne' => 'मिति',     'gu' => 'તારીખ'],
            'board' => ['en' => 'Boarding',   'hi' => 'चढ़ने की जगह', 'ne' => 'चढ्ने ठाउँ', 'gu' => 'ચઢવાની જગ્યા'],
            'seat'  => ['en' => 'Seat',       'hi' => 'सीट',       'ne' => 'सिट',      'gu' => 'સીટ'],
            'pax'   => ['en' => 'Passengers', 'hi' => 'यात्री',    'ne' => 'यात्रु',   'gu' => 'મુસાફરો'],
            'total' => ['en' => 'Total',      'hi' => 'कुल',       'ne' => 'जम्मा',    'gu' => 'કુલ'],
            'bus'   => ['en' => 'Bus',        'hi' => 'बस',        'ne' => 'बस',      'gu' => 'બસ'],
        ];
        return $l[$key][$lang] ?? $l[$key]['en'];
    }

    /** WhatsApp has no buttons here, so the options are numbered. */
    private static function withOptions(string $question, array $options, string $lang): string
    {
        if ($options === []) {
            return $question;
        }
        $lines = [$question, ''];
        foreach ($options as $i => $o) {
            $lines[] = ($i + 1) . '. ' . (string) ($o['label'] ?? $o['value']);
        }
        $lines[] = '';
        $lines[] = [
            'en' => 'Reply with the number, or write it in your own words.',
            'hi' => 'नंबर लिखें, या अपने शब्दों में लिख दें।',
            'ne' => 'नम्बर लेख्नुहोस्, वा आफ्नै शब्दमा लेख्नुहोस्।',
            'gu' => 'નંબર લખો, કે તમારા શબ્દોમાં લખો.',
        ][$lang] ?? 'Reply with the number.';

        return implode("\n", $lines);
    }

    /** "2" or the option's own words. */
    private static function pickOption(string $text, array $options): ?string
    {
        $t = trim(TicketBot::asciiDigits($text));
        if ($options === []) {
            return null;
        }
        if (preg_match('/^\s*(\d{1,2})[.)]?\s*$/', $t, $m)) {
            $i = (int) $m[1] - 1;
            if ($i >= 0 && $i < count($options)) {
                return (string) ($options[$i]['value'] ?? '');
            }
            return null;
        }
        foreach ($options as $o) {
            $label = trim((string) ($o['label'] ?? ''));
            if ($label !== '' && mb_stripos($t, $label) !== false) {
                return (string) ($o['value'] ?? '');
            }
        }
        return null;
    }

    /** "naam Ram Bahadur" and "नाम राम" both mean the name is Ram. */
    private static function stripNameWord(string $t): string
    {
        $t = trim($t);
        $t = (string) preg_replace('/^\s*(?:naam|nam|name|नाम)\s*[:\-]?\s*/ui', '', $t);
        return mb_substr(trim($t), 0, 60);
    }

    private static function isYes(string $t): bool
    {
        $t = mb_strtolower(trim($t));
        foreach (['yes', 'y', 'ok', 'okay', 'sure', 'book', 'हो', 'हजुर', 'हाँ', 'हा', 'ठीक', 'ठिक', 'हां', 'હા', 'બરાબર'] as $w) {
            if ($t === $w || str_starts_with($t, $w . ' ')) {
                return true;
            }
        }
        return false;
    }

    private static function isNo(string $t): bool
    {
        $t = mb_strtolower(trim($t));
        foreach (['no', 'n', 'nope', 'होइन', 'नहीं', 'नही', 'ना', 'ના'] as $w) {
            if ($t === $w || str_starts_with($t, $w . ' ')) {
                return true;
            }
        }
        return false;
    }

    private static function isCancel(string $t): bool
    {
        $t = mb_strtolower(trim($t));
        foreach (['cancel', 'stop', 'रद्द', 'रोक', 'बन्द', 'બંધ', 'રદ'] as $w) {
            if (str_contains($t, $w)) {
                return true;
            }
        }
        return false;
    }

    /** Does this message want a seat? */
    private static function looksLikeRequest(string $text): bool
    {
        $p = TicketBot::parse($text);
        foreach (['seats', 'date', 'direction', 'boarding'] as $k) {
            $v = $p[$k] ?? '';
            if ($v !== '' && $v !== 0) {
                return true;
            }
        }
        $t = mb_strtolower($text);
        foreach (['book', 'ticket', 'seat', 'टिकट', 'बुक', 'सिट', 'सीट', 'चाहियो', 'चाहिए', 'ટિકિટ', 'બુક'] as $w) {
            if (str_contains($t, $w)) {
                return true;
            }
        }
        return false;
    }

    /* ----------------------------------------------------------------- *
     *  Conversation state (kv_store, same pattern as the chat memory)
     * ----------------------------------------------------------------- */

    private static function load(string $who): ?array
    {
        try {
            $row = Database::fetch(
                'SELECT kvalue, UNIX_TIMESTAMP(updated_at) AS ts FROM kv_store WHERE kscope = :s AND kkey = :k',
                ['s' => self::SCOPE, 'k' => $who]
            );
            if ($row === null || (time() - (int) $row['ts']) > self::TTL) {
                return null;
            }
            $v = json_decode((string) $row['kvalue'], true);
            return is_array($v) ? $v : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function save(string $who, array $slots, string $awaiting, array $options, string $lang): void
    {
        $json = json_encode([
            'slots'    => $slots,
            'awaiting' => $awaiting,
            'options'  => $options,
            'lang'     => $lang,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $done = Database::update('kv_store', ['kvalue' => $json, 'updated_by' => 'wabooking'],
                'kscope = :s AND kkey = :k', ['s' => self::SCOPE, 'k' => $who]);
            if ($done === 0) {
                Database::insertIgnore('kv_store', [
                    'kscope' => self::SCOPE, 'kkey' => $who, 'kvalue' => $json, 'updated_by' => 'wabooking',
                ]);
            }
        } catch (Throwable $e) {
            // A lost conversation is recoverable; a crashed reply is not.
        }
    }

    public static function clear(string $who): void
    {
        try {
            Database::delete('kv_store', 'kscope = :s AND kkey = :k', ['s' => self::SCOPE, 'k' => $who]);
        } catch (Throwable $e) {
            // best effort
        }
    }

    /** @return array{text: string, media: ?string} */
    private static function out(string $text, ?string $media = null): array
    {
        return ['text' => $text, 'media' => $media];
    }
}
