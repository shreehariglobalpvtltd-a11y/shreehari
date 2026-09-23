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

        /* JUST SOLD, AND SOMETHING IS WRONG (21 Sep 2026, owner: "confirm
           gare ticket aaune, mistake bhaye feri tyaslai sachhyaera aaune").
           For a few minutes after a sale a complaint about the NAME is
           answered here instead of falling through to the menu. Only the
           name: a wrong date or pickup moves seats and money, and that
           stays with fix_ticket and the desk. */
        if ($state === null) {
            $justSold = self::recentSale($phoneDigits);
            if ($justSold !== null && self::looksLikeNameFix($text)) {
                $soldLang = ($justSold['lang'] ?? '') !== '' ? (string) $justSold['lang'] : $lang;
                $fixed = self::fixNameLocally($phoneDigits, $justSold, $text, $soldLang);
                if ($fixed !== null) {
                    return $fixed;
                }
            }
        }

        // Nothing in progress and nothing bookable in the message — let the
        // PNR lookup, the assistant or the menu answer instead.
        if ($state === null && !self::looksLikeRequest($text)) {
            return null;
        }

        /* Computed BEFORE anything writes the conversation row, because
           greetedAlready() keys on exactly that row: once the state exists
           we are mid-conversation and the mantra must not repeat. */
        $opener = self::opener($phoneDigits, $lang);

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
                /* They answer the name question with "naam Ram", with one
                   name, or — when the party is 3 — with all three at once.
                   parseParty() reads every shape; the first is the booking
                   name and the rest ride on their own berths. */
                $party = self::parseParty($text, max(1, (int) ($slots['seats'] ?? 1)));
                if ($party !== []) {
                    $slots['name']  = $party[0]['name'];
                    $slots['party'] = $party;
                } else {
                    $slots['name'] = self::stripNameWord($text);
                }
                $text = '';
            }
        }

        /* "naam Ram Bahadur" / "नाम राम" — the one correction the parser cannot
           make for itself, since any word could be a name. A list behind the
           same word is the whole family: "naam Ram, Sita 30, Maya 12". */
        if (preg_match('/^\s*(?:naam|nam|name|नाम|નામ)\s*[:\-]?\s*(.{2,400})$/ui', $text, $m)) {
            $party = self::parseParty($m[1], max(1, (int) ($slots['seats'] ?? 1)));
            if ($party !== []) {
                $slots['name']  = $party[0]['name'];
                $slots['party'] = $party;
            } else {
                $slots['name'] = trim($m[1]);
            }
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
            /* A question while the summary is up ("kati ghanta lagcha?")
               deserves an answer, not a re-print of the same summary. Hand it
               to the assistant and keep the booking exactly where it is. */
            if ($text !== '' && self::isQuestion(mb_strtolower($text))) {
                return null;
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
            if ($v === '' || $v === null || $v === 0) {
                continue;
            }
            /* 21 Sep 2026 — "bholi 1 seat Mehsana bata" came back with a
               prefilled name of "Mehsana": the pickup town, read as the
               passenger. The summary then said Name: Mehsana, and a "ho"
               on top of it would have sold a ticket under the name of a
               bus stop. The file already warned about this shape ("asked
               first, the next message (Mehsana) was read as the name") —
               it just never guarded the prefill. A name that IS this
               booking's pickup, or any stop we serve, is refused; the
               passenger is then asked for their name properly. */
            if ($k === 'name' && self::looksLikePlace((string) $v, $slots)) {
                continue;
            }
            $slots[$k] = $v;
        }

        $ask = $sug['ask'] ?? null;
        if (is_array($ask) && ($ask['field'] ?? '') !== '') {
            $options = array_values(array_filter(
                (array) ($ask['options'] ?? []),
                static fn ($o): bool => is_array($o) && ($o['value'] ?? '') !== '' && ($o['value'] ?? '') !== 'other'
            ));
            self::save($phoneDigits, $slots, (string) $ask['field'], $options, $lang);
            return self::out($opener . self::withOptions((string) $ask['question'], $options, $lang));
        }

        /* The name is asked LAST, once the trip itself is settled: asked
           first, the next message ("Mehsana") was read as the name. */
        if (trim((string) ($slots['name'] ?? '')) === '') {
            self::save($phoneDigits, $slots, 'name', [], $lang);
            $paxN = max(1, (int) ($slots['seats'] ?? 1));
            return self::out($opener . ($paxN > 1
                ? sprintf(self::say('askNames', $lang), $paxN)
                : self::say('askName', $lang)));
        }

        /* The party was given as ONE name for several berths — ask for the
           rest before the summary, so the manifest is right the first time
           rather than after a correction. */
        /* Only a PARTY is asked for more names. Without the seats > 1
           guard this fired for a lone traveller too — partyForSale() is
           empty until a list is given, so 0 < 1 was true — and a single
           passenger who had already given their name was asked to "send
           all 1 names in one message". */
        $paxWanted = max(1, (int) ($slots['seats'] ?? 1));
        if ($paxWanted > 1
            && count(self::partyForSale($slots)) < $paxWanted
            && ($slots['namesAsked'] ?? false) !== true) {
            $slots['namesAsked'] = true;
            self::save($phoneDigits, $slots, 'name', [], $lang);
            return self::out($opener . sprintf(self::say('askNames', $lang), $paxWanted));
        }

        $plan = $sug['plan'] ?? null;
        if (!is_array($plan) || $plan === []) {
            self::clear($phoneDigits);
            $why = trim((string) ($sug['planError'] ?? ''));
            return self::out($why !== '' ? $why : self::say('noPlan', $lang));
        }

        // Everything is known: show what it will cost and wait for a yes.
        self::save($phoneDigits, $slots, 'confirm', [], $lang);
        return self::out($opener . self::summary($plan, $slots, $lang));
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
                /* 21 Sep 2026: every traveller on their own berth. Without
                   this QuickTicket numbers the party after the buyer —
                   "Ram Bahadur (2)", "(3)" — which is three real people
                   under one name on the border manifest. */
                'passengers' => self::partyForSale($slots),
            ], null);
        } catch (Throwable $e) {
            self::clear($phoneDigits);
            // QuickTicket throws passenger-safe bilingual text on purpose.
            return self::out(trim($e->getMessage()) !== '' ? $e->getMessage() : self::say('failed', $lang));
        }

        self::clear($phoneDigits);

        $pnr    = (string) ($res['pnr'] ?? '');
        /* Remember what we just sold, briefly. A wrong name is noticed in
           the seconds AFTER the ticket lands, not before — and until now
           the conversation was already cleared, so "naam galat bhayo" fell
           through to the menu and the passenger was told to ring the
           office about a ticket we had cut ten seconds earlier. */
        if ($pnr !== '') {
            /* The language rides along: after a sale the conversation is
               cleared, so a romanised "naam galat bhayo" would be read as
               English and a Nepali customer would suddenly be answered in
               English about their own ticket. */
            self::rememberSale($phoneDigits, $pnr, $lang);
        }
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
        /* Every traveller by name (and age when given), so the party can
           check its OWN manifest before confirming — a wrong name is far
           cheaper to catch here than at the border. One traveller still
           reads as the single "Name:" line it always did. */
        $party = self::partyForSale($slots);
        if (count($party) > 1) {
            $lines[] = self::label('name', $lang) . ':';
            foreach ($party as $i => $p) {
                $lines[] = '  ' . ($i + 1) . '. ' . $p['name']
                         . ($p['age'] !== null ? ' (' . $p['age'] . ')' : '');
            }
        } else {
            $lines[] = self::label('name', $lang) . ': ' . (string) ($slots['name'] ?? '');
        }
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
            /* A party is asked for EVERY name in one message — asking one at
               a time is four round trips for a family of four, and a berth
               with no name on it is a berth the border cannot check. Age is
               invited, never demanded: it is written in brackets if given
               and simply left out if not. */
            'askNames' => [
                'en' => "Please send all %d names in one message, with age if you can.
Example: Ram Bahadur 35, Sita Gurung 30",
                'hi' => "कृपया चारों नाम एक ही संदेश में भेजिए, उम्र हो तो साथ में।
जैसे: राम बहादुर 35, सीता गुरुङ 30",
                'ne' => "कृपया %d जनाकै नाम एउटै सन्देशमा पठाउनुहोस्, उमेर भए सँगै।
जस्तै: राम बहादुर ३५, सीता गुरुङ ३०",
                'gu' => "કૃપા કરીને બધાં %d નામ એક જ સંદેશમાં મોકલો, ઉંમર હોય તો સાથે.
દા.ત.: રામ બહાદુર 35, સીતા ગુરુંગ 30",
            ],
            'askRightName' => [
                'en' => 'Sorry about that. Please send the correct name, like: naam Ram Bahadur',
                'hi' => 'माफ़ कीजिए। सही नाम भेजिए, जैसे: नाम राम बहादुर',
                'ne' => 'माफ गर्नुहोस्। सही नाम पठाउनुहोस्, जस्तै: नाम राम बहादुर',
                'gu' => 'માફ કરશો. સાચું નામ મોકલો, દા.ત.: નામ રામ બહાદુર',
            ],
            'nameFixed' => [
                'en' => 'Fixed — the ticket is now in the name %s. The corrected ticket is below.',
                'hi' => 'ठीक कर दिया — टिकट अब %s के नाम पर है। सुधारा हुआ टिकट नीचे है।',
                'ne' => 'मिलाइयो — टिकट अब %s को नाममा छ। सच्याइएको टिकट तल छ।',
                'gu' => 'સુધારી દીધું — ટિકિટ હવે %s ના નામે છે. સુધારેલી ટિકિટ નીચે છે.',
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
        $t = (string) preg_replace('/^\s*(?:naam|nam|name|नाम|નામ)\s*[:\-]?\s*/ui', '', $t);
        return mb_substr(trim($t), 0, 60);
    }

    /* -----------------------------------------------------------------
     *  Just-sold correction (21 Sep 2026)
     *
     *  A wrong name is noticed in the seconds AFTER the ticket lands. Up
     *  to now the conversation was already cleared by then, so "naam galat
     *  bhayo" fell through to the menu and the passenger was told to ring
     *  the office about a ticket we had cut ten seconds earlier.
     *
     *  Only the NAME is corrected here. A wrong date or pickup moves seats
     *  and money and belongs to fix_ticket (which quotes, guards the fare
     *  and keeps an audit row) or to the desk — this path is deliberately
     *  the smallest one that removes the most common frustration.
     * --------------------------------------------------------------- */

    /** How long after a sale a name complaint is still handled here. */
    private const SOLD_TTL  = 900;          // 15 minutes
    private const SOLD_SCOPE = 'wa_sold';
    /** Corrections allowed per booking from this path. */
    private const SOLD_FIX_MAX = 2;

    private static function rememberSale(string $who, string $pnr, string $lang = ''): void
    {
        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by)
                 VALUES (:s, :k, :v, 'wabooking')
                 ON DUPLICATE KEY UPDATE kvalue = :v2, updated_by = 'wabooking'",
                ['s' => self::SOLD_SCOPE, 'k' => $who,
                 'v'  => json_encode(['pnr' => $pnr, 'at' => time(), 'fixes' => 0, 'lang' => $lang]),
                 'v2' => json_encode(['pnr' => $pnr, 'at' => time(), 'fixes' => 0, 'lang' => $lang])]
            );
        } catch (Throwable $e) {
            // Remembering is a convenience; a sale must never fail on it.
        }
    }

    /** @return array{pnr: string, at: int, fixes: int}|null */
    private static function recentSale(string $who): ?array
    {
        try {
            $row = Database::fetch(
                'SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k',
                ['s' => self::SOLD_SCOPE, 'k' => $who]
            );
        } catch (Throwable $e) {
            return null;
        }
        if ($row === null) {
            return null;
        }
        $v = json_decode((string) $row['kvalue'], true);
        if (!is_array($v) || ($v['pnr'] ?? '') === '') {
            return null;
        }
        if ((time() - (int) ($v['at'] ?? 0)) > self::SOLD_TTL) {
            return null;
        }
        return ['pnr' => (string) $v['pnr'], 'at' => (int) $v['at'],
                'fixes' => (int) ($v['fixes'] ?? 0), 'lang' => (string) ($v['lang'] ?? '')];
    }

    /**
     * Does this message complain about the NAME on the ticket we just cut?
     *
     * Kept deliberately narrow: it must say something is wrong AND be about
     * a name, or be an explicit "naam X" correction. "miti galat cha" is a
     * date complaint and must fall through, not be answered as a name.
     */
    private static function looksLikeNameFix(string $t): bool
    {
        $t = mb_strtolower(trim($t));
        if ($t === '') {
            return false;
        }
        // An outright "naam Ram Bahadur" is a correction on its own.
        if (preg_match('/^\s*(?:naam|nam|name|नाम|નામ)\s*[:\-]?\s*\p{L}/ui', $t) === 1) {
            return true;
        }
        $wrong = ['galat', 'galati', 'wrong', 'mistake', 'gadbad', 'milena', 'mileko chaina',
                  'sachya', 'sudhar', 'change', 'badal', 'fix', 'correct',
                  'गलत', 'गल्ती', 'सच्या', 'सुधार', 'बदल', 'मिलेन', 'ખોટું', 'સુધાર'];
        $about = ['naam', 'nam', 'name', 'नाम', 'નામ', 'spelling', 'हिज्जे'];
        $hasWrong = false;
        foreach ($wrong as $w) { if (str_contains($t, $w)) { $hasWrong = true; break; } }
        if (!$hasWrong) {
            return false;
        }
        foreach ($about as $w) { if (str_contains($t, $w)) { return true; } }
        return false;
    }

    /**
     * The REPLACEMENT name out of a correction message — and nothing else.
     *
     * 21 Sep 2026: the first attempt fed the whole sentence to parseParty,
     * which happily read "naam galat bhayo, naam Ram Bahadur ho" as a party
     * whose first member is called "galat bhayo" — and renamed a live
     * ticket to it. A correction message contains BOTH the complaint and
     * the fix, so the fix has to be picked out, never just "the first thing
     * that looks like a name".
     *
     * So: take the LAST explicit "naam X" in the message (people write the
     * complaint first and the correction after it), strip the trailing "ho"
     * / "hunchha" / "cha" that ends a Nepali sentence, and refuse anything
     * that still reads like a complaint. Returns '' when unsure — the
     * caller then asks for the name plainly instead of guessing.
     */
    private static function correctedNameFrom(string $text): string
    {
        $t = trim($text);
        if ($t === '') {
            return '';
        }
        // Every "naam X" / "नाम X" in the message; the LAST one is the fix.
        if (preg_match_all('/(?:naam|nam|name|नाम|નામ)\s*[:\-]?\s*([^\n,;।]{2,60})/ui', $t, $mm) !== 1 + 0
            && empty($mm[1])) {
            return '';
        }
        if (empty($mm[1])) {
            return '';
        }
        $cand = trim((string) end($mm[1]));

        // Trailing sentence tails: "Ram Bahadur ho", "… hunchha", "… cha".
        $cand = (string) preg_replace('/\s+(?:ho|hos|hunchha|hunxa|huncha|cha|chha|hai|हो|हुन्छ|छ)\s*[.!]?$/ui', '', $cand);
        $cand = trim((string) preg_replace('/\s{2,}/u', ' ', $cand));

        if (mb_strlen($cand) < 2 || preg_match('/\p{L}/u', $cand) !== 1) {
            return '';
        }
        // A candidate that still sounds like the complaint is not a name.
        foreach (['galat', 'galati', 'wrong', 'mistake', 'gadbad', 'milena', 'sachya', 'sudhar',
                  'change', 'badal', 'fix', 'correct', 'गलत', 'गल्ती', 'सच्या', 'सुधार', 'बदल', 'मिलेन'] as $w) {
            if (str_contains(mb_strtolower($cand), $w)) {
                return '';
            }
        }
        // A name is words, not digits.
        if (preg_match('/\d/u', $cand) === 1) {
            return '';
        }
        return mb_substr($cand, 0, 60);
    }

    /**
     * Correct the name on the booking we just sold and re-send the ticket.
     *
     * Returns null when it cannot be done safely — a missing new name, a
     * booking that is no longer ours to touch, a party where we cannot tell
     * WHICH name is meant — so the caller falls through to the assistant or
     * the menu rather than guessing.
     */
    private static function fixNameLocally(string $who, array $sold, string $text, string $lang): ?array
    {
        if ($sold['fixes'] >= self::SOLD_FIX_MAX) {
            return null;                       // hand it on; a human should look
        }

        $new = self::correctedNameFrom($text);
        if ($new === '') {
            // They said it is wrong but not what it should be.
            return self::out(self::say('askRightName', $lang));
        }

        try {
            $detail = BookingService::detail($sold['pnr']);
            if ($detail === null || normalisePhone((string) ($detail['contact_phone'] ?? '')) !== $who) {
                return null;
            }
            if (!in_array((string) ($detail['status'] ?? ''), ['pending', 'confirmed'], true)) {
                return null;
            }
            $pax = $detail['passengers'] ?? [];
            // With a party we cannot tell which of four names they mean —
            // fix_ticket / the desk asks that question properly.
            if (count($pax) !== 1) {
                return null;
            }
            $target = $pax[0];
            if (mb_strtolower(trim((string) $target['full_name'])) === mb_strtolower($new)) {
                return null;
            }

            $bid = (int) $detail['id'];
            Database::run(
                'UPDATE booking_passengers SET full_name = :n WHERE id = :id AND booking_id = :b',
                ['n' => $new, 'id' => (int) $target['id'], 'b' => $bid]
            );
            /* The passenger row IS the manifest — `bookings` has no name
               column at all, and an UPDATE against one threw "Unknown
               column 'full_name' in 'SET'" straight past the rename that
               had already committed, so the ticket showed the new name
               while the passenger was answered with the generic menu.
               AiTools::renamePassenger() touches booking_passengers only,
               for the same reason.

               The rename is COMMITTED by this point. A hiccup re-minting the
               picture must not make this method return null, because the
               caller reads null as "I did nothing" and answers with the
               menu — which is how a corrected ticket once came back looking
               like it had been ignored. The ticket self-heals on next open;
               the passenger is told the name is fixed either way. */
            try {
                Ticket::reissue($bid);
            } catch (Throwable $e) {
                Logger::error('Ticket reissue after WhatsApp rename failed: ' . $e->getMessage(),
                    ['pnr' => $sold['pnr']], 'whatsapp');
            }
            Logger::audit('booking.rename_wabooking', 'booking', $sold['pnr'],
                ['full_name' => (string) $target['full_name']], ['full_name' => $new],
                'Name corrected in the WhatsApp chat right after the sale');

            // Spend one of the two allowed corrections.
            Database::run(
                "UPDATE kv_store SET kvalue = :v WHERE kscope = :s AND kkey = :k",
                ['v' => json_encode(['pnr' => $sold['pnr'], 'at' => $sold['at'],
                                     'fixes' => $sold['fixes'] + 1, 'lang' => (string) ($sold['lang'] ?? '')]),
                 's' => self::SOLD_SCOPE, 'k' => $who]
            );

            /* The office learns of it too. Without this the desk could hand
               the driver a boarding list carrying a name that was corrected
               an hour earlier and never know it had moved. */
            try {
                if (!class_exists('Notify')) { require_once INCLUDE_PATH . '/notify.php'; }
                Notify::adminNote('✏️ नाम सच्चियो (WhatsApp)', [
                    'PNR'  => $sold['pnr'],
                    'थियो' => (string) $target['full_name'],
                    'भयो'  => $new,
                    'फोन'  => $who,
                ], $bid);
            } catch (Throwable $e) {
                Logger::warning('Admin note after rename failed: ' . $e->getMessage(), [], 'whatsapp');
            }

            $fresh = BookingService::detail($sold['pnr']) ?? $detail;
            return self::out(
                sprintf(self::say('nameFixed', $lang), $new) . "\n" . self::label('pnr', $lang) . ': ' . $sold['pnr'],
                (string) ($fresh['status'] ?? '') === 'confirmed' ? Ticket::imageUrl($sold['pnr']) : null
            );
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
            return null;
        }
    }

    /**
     * The captured party in the shape QuickTicket::requestFor() reads.
     *
     * Trimmed to the berths actually being sold: a list longer than the
     * seats would put a name on a berth nobody bought, and a shorter one
     * is fine — the engine numbers the remainder after the buyer exactly
     * as the seat map does.
     *
     * @return list<array{name: string, age: int|null}>
     */
    private static function partyForSale(array $slots): array
    {
        $party = is_array($slots['party'] ?? null) ? $slots['party'] : [];
        if ($party === []) {
            return [];
        }
        $seats = max(1, (int) ($slots['seats'] ?? 1));
        $out   = [];
        foreach ($party as $p) {
            if (count($out) >= $seats) {
                break;
            }
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $age  = isset($p['age']) && $p['age'] !== null ? (int) $p['age'] : null;
            $out[] = ['name' => $name, 'age' => ($age !== null && $age >= 1 && $age <= 120) ? $age : null];
        }
        return $out;
    }

    /**
     * A whole travelling party out of one sentence (21 Sep 2026, owner:
     * "name age ticket count date sabai Nepali Hindi English ma jasto
     * tarika bata ni bujhne").
     *
     * Until now the local engine took ONE name and QuickTicket numbered
     * the rest after it, so a family of three rode on "Ram Bahadur",
     * "Ram Bahadur (2)", "Ram Bahadur (3)" — three real people, one name,
     * and a manifest nobody could check at the border.
     *
     * Everything people actually type is accepted:
     *     naam Ram Bahadur, Sita Gurung, Maya Thapa
     *     Ram 35, Sita 30, Maya 12
     *     नाम: राम बहादुर ३५, सीता गुरुङ ३०
     *     Ram Bahadur (35) ra Sita Gurung (30)
     *     1. Ram Bahadur  2. Sita Gurung
     * Separators may be commas, Devanagari danda, semicolons, slashes,
     * newlines, or the words "ra" / "और" / "ane" / "and".
     *
     * Ages are read only where they are unambiguous — a number glued to a
     * name, in brackets, or after it — and anything outside 1..120 is
     * dropped rather than guessed at. A name is never invented: a fragment
     * with no letters is skipped entirely.
     *
     * @return list<array{name: string, age: int|null}>
     */
    private static function parseParty(string $text, int $max = 12): array
    {
        $t = trim(self::stripNameWord($text));
        if ($t === '') {
            return [];
        }
        // Devanagari and Gujarati digits first, so "३५" is just 35.
        if (class_exists('TicketBot') && method_exists('TicketBot', 'asciiDigits')) {
            $t = (string) TicketBot::asciiDigits($t);
        } else {
            $t = strtr($t, ['०'=>'0','१'=>'1','२'=>'2','३'=>'3','४'=>'4','५'=>'5','६'=>'6','७'=>'7','८'=>'8','९'=>'9',
                            '૦'=>'0','૧'=>'1','૨'=>'2','૩'=>'3','૪'=>'4','૫'=>'5','૬'=>'6','૭'=>'7','૮'=>'8','૯'=>'9']);
        }
        // " ra " / " and " / " और " / " ane " join two people exactly like a comma.
        $t = (string) preg_replace('/\s+(?:ra|and|aur|ane|और|अनि|ર|અને)\s+/ui', ',', $t);
        /* A numbered list often carries no comma at all — "1. Ram Bahadur
           2. Sita Gurung" is two people, and without this the whole line
           came back as one very long name. The marker itself becomes the
           separator; the leading one is stripped per-part below. */
        $t = (string) preg_replace('/(?<=\S)\s+(?=\d{1,2}\s*[.)]\s*\p{L})/u', ',', $t);

        $parts = preg_split('/[,;\/\n\r।|]+/u', $t) ?: [];
        $out   = [];
        foreach ($parts as $part) {
            if (count($out) >= $max) {
                break;
            }
            $p = trim($part);
            // Drop a list marker: "1.", "२)", "-"
            $p = (string) preg_replace('/^\s*[\d]{1,2}\s*[.)\-]\s*/u', '', $p);
            $p = trim($p);
            if ($p === '') {
                continue;
            }

            $age = null;
            // "(35)" or "35" at either end, or "umer 35" / "age 35".
            if (preg_match('/[\(\[]\s*(\d{1,3})\s*[\)\]]/u', $p, $m)) {
                $age = (int) $m[1];
                $p   = trim((string) str_replace($m[0], ' ', $p));
            } elseif (preg_match('/\b(?:umer|umar|age|उमेर|ઉંમર)\s*[:\-]?\s*(\d{1,3})\b/ui', $p, $m)) {
                $age = (int) $m[1];
                $p   = trim((string) str_replace($m[0], ' ', $p));
            } elseif (preg_match('/^(\d{1,3})\s+(?=\D)/u', $p, $m)) {
                $age = (int) $m[1];
                $p   = trim(mb_substr($p, mb_strlen($m[0])));
            } elseif (preg_match('/\s(\d{1,3})\s*$/u', $p, $m)) {
                $age = (int) $m[1];
                $p   = trim(mb_substr($p, 0, mb_strlen($p) - mb_strlen($m[0])));
            }
            if ($age !== null && ($age < 1 || $age > 120)) {
                $age = null;                       // a phone tail, not an age
            }

            $name = trim((string) preg_replace('/\s{2,}/u', ' ', $p));
            // A fragment with no letter at all is not a person.
            if (preg_match('/\p{L}/u', $name) !== 1 || mb_strlen($name) < 2) {
                continue;
            }
            $out[] = ['name' => mb_substr($name, 0, 60), 'age' => $age];
        }

        return $out;
    }

    private static function isYes(string $t): bool
    {
        $t = mb_strtolower(trim($t));
        /* 21 Sep 2026 — THE BUG THAT SWALLOWED EVERY LOCAL SALE.
           This list held Devanagari हो but not romanised "ho", which is
           what a Nepali actually types on a phone keyboard. The summary
           went up, the passenger wrote "ho", nothing matched, and the
           engine re-printed the same summary forever. It was invisible
           while the model sat in front (a model understands "ho" without
           being told); the moment selling moved onto this VPS it became
           the whole feature failing. Romanised Nepali, Hindi and Gujarati
           now sit beside the scripts they are typed instead of. */
        foreach ([
            'yes', 'y', 'ok', 'okay', 'oke', 'sure', 'book', 'confirm', 'done', 'go',
            'ho', 'hoo', 'hos', 'hunchha', 'hunxa', 'huncha', 'hunca', 'hajur', 'hajoor',
            'thik', 'thik cha', 'thikcha', 'theek', 'thek', 'tik', 'tikcha',
            'haa', 'han', 'ha', 'haan', 'hai', 'sahi', 'malai chahiyo', 'chahiyo',
            'kaat', 'kata', 'katnus', 'katidinus', 'katdinus', 'book gara', 'book garnus',
            'हो', 'हजुर', 'हाँ', 'हा', 'ठीक', 'ठिक', 'हां', 'हुन्छ', 'काट', 'काट्नुहोस्',
            'હા', 'બરાબર', 'હોવ',
        ] as $w) {
            if ($t === $w || str_starts_with($t, $w . ' ')) {
                return true;
            }
        }
        return false;
    }

    private static function isNo(string $t): bool
    {
        $t = mb_strtolower(trim($t));
        foreach ([
            'no', 'n', 'nope', 'nahi', 'nai', 'haina', 'hoina', 'chaidaina', 'pardaina',
            'nachahine', 'rahana deu', 'napathau',
            'होइन', 'नहीं', 'नही', 'ना', 'चाहिँदैन', 'पर्दैन', 'ના', 'નથી',
        ] as $w) {
            if ($t === $w || str_starts_with($t, $w . ' ')) {
                return true;
            }
        }
        return false;
    }

    private static function isCancel(string $t): bool
    {
        $t = mb_strtolower(trim($t));
        foreach (['cancel', 'stop', 'radda', 'rokka', 'band gara', 'chhodde', 'chod',
                  'रद्द', 'रोक', 'बन्द', 'બંધ', 'રદ'] as $w) {
            if (str_contains($t, $w)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Does this message actually want a seat?
     *
     * A city name or a bare number is not enough. "kati ghanta lagcha surat
     * bata?" parses a boarding point and used to open a booking — and then
     * swallowed the next message as the passenger's name. A question goes to
     * the assistant unless it also says book / ticket / seat.
     */
    private static function looksLikeRequest(string $text): bool
    {
        $t    = mb_strtolower(trim($text));
        /* 23 Sep 2026 — a message ABOUT a ticket is not a request FOR one.
           "mero ticket ma naam wrong xa" contains "ticket", so it opened a
           brand-new sale named "Mero Wrong" and asked "book it? ho"; "payment
           gare tara ticket aayena" became a ticket for "Payment Gare Tara
           Aayena". A confused passenger answering ho would have been sold a
           second, wrongly-named ticket. Complaints, corrections, cancels,
           refunds, resends and "where is my ticket" go to the assistant,
           which can read their booking; a new sale is never guessed here. */
        if (self::isAboutExistingTicket($t)) {
            return false;
        }
        $said = false;
        foreach (['book', 'ticket', 'seat', 'टिकट', 'बुक', 'सिट', 'सीट', 'चाहियो', 'चाहिए', 'चाहिये',
                  'जानु', 'जाना', 'ટિકિટ', 'બુક', 'સીટ'] as $w) {
            if (str_contains($t, $w)) {
                $said = true;
                break;
            }
        }
        if ($said) {
            /* 23 Sep 2026: "Dashain ma ghar jana ticket milcha?" is a QUESTION
               that mentions a ticket. With no party and no day there is nothing
               to sell yet — it was booked for TODAY under the name "Dashain
               Ghar". The assistant answers it; a question that names the party
               and the day ("bholi 2 ticket milcha?") still opens the booking. */
            if (self::isQuestion($t)) {
                $p = TicketBot::parse($text);
                if ((int) ($p['seats'] ?? 0) === 0 && (string) ($p['date'] ?? '') === '') {
                    return false;
                }
            }
            return true;
        }
        if (self::isQuestion($t)) {
            return false;       // the assistant answers questions
        }

        /* No booking word: only an unmistakable request — how many people
           AND when or which way — may start a sale. */
        $p     = TicketBot::parse($text);
        $seats = (int) ($p['seats'] ?? 0);
        $when  = ((string) ($p['date'] ?? '')) !== '' || ((string) ($p['direction'] ?? '')) !== '';

        return $seats > 0 && $when;
    }

    /**
     * A complaint, correction or follow-up about a ticket that already exists
     * (or a payment that was already made). Deliberately broad: a missed sale
     * costs one more message, a guessed sale costs a wrong ticket.
     */
    private static function isAboutExistingTicket(string $t): bool
    {
        foreach ([
            // something is wrong / did not arrive
            'galat', 'galti', 'wrong', 'mistake', 'problem', 'samasya', 'error', 'gadbad',
            'aayena', 'aaena', 'ayena', 'aayeko chaina', 'aako chaina', 'aayeko chhaina', 'pugena', 'pugeko chaina',
            'milena', 'nahi aaya', 'nahi aya', 'nahin aaya', 'not received', 'not come', "didn't get", 'didnt get',
            // change / fix / cancel / refund
            // "firta" / "wapas" alone also mean the RETURN journey ("firta aaune ticket"), so only with money.
            'cancel', 'radd', 'refund', 'paisa firta', 'firta paisa', 'paise wapas', 'paisa wapas', 'paise vapas',
            'change', 'badal', 'sachya', 'sudhar', 'correct',
            'reschedule', 'sarna', 'sarnu',
            // send again / lost / where is it
            'resend', 'pathaideu', 'pathaidinu', 'pathau', 'bhejo', 'bhej do', 'send again', 'feri pathau',
            'feri banau', 'feri banaideu', 'harayo', 'haraayo', 'lost', 'kaha cha', 'kaha xa', 'kaha chha',
            'kahan hai', 'where is', 'status',
            // it is theirs already
            'mero ticket', 'mera ticket', 'meri ticket', 'my ticket', 'mero booking', 'my booking', 'asti ko',
            // looking at bookings, not making one ("booking" contains "book")
            'sabai booking', 'all booking', 'bookings', 'booking dekh', 'booking dikh', 'booking show',
            'booking check', 'booking her',
            'payment gar', 'payment kiya', 'paisa tire', 'paisa tireko', 'paid', 'katyo', 'kat gaya', 'kat gaye',
            // Devanagari
            'गलत', 'गल्ती', 'गलती', 'आएन', 'आएको छैन', 'पुगेन', 'मिलेन', 'नहीं आया', 'समस्या', 'रद्द', 'क्यान्सिल',
            'कैंसल', 'पैसा फिर्ता', 'रकम फिर्ता', 'पैसे वापस', 'रिफन्ड', 'रिफंड', 'बदल', 'सच्या', 'सुधार', 'पठाइदिनु', 'पठाउनु', 'भेजो',
            'हरायो', 'मेरो टिकट', 'मेरा टिकट', 'मेरी टिकट', 'भुक्तानी गरे', 'पैसा तिरे', 'कहाँ छ',
            // Gujarati
            'ખોટું', 'રદ', 'રિફંડ', 'મારી ટિકિટ',
        ] as $w) {
            /* Matched at the START of a word only (suffixes allowed: "badalnu",
               "pathaunu", "cancelled"). A plain substring test found "paid"
               inside "Ru-paid-iha" — our own destination — and would have
               refused every booking that named it. */
            if (preg_match('/(?<![\p{L}\p{M}\p{N}])' . preg_quote($w, '/') . '/u', $t) === 1) {
                return true;
            }
        }
        return false;
    }

    /** "kati", "kaha", "how much", "?" — someone asking, not booking. */
    private static function isQuestion(string $t): bool
    {
        if (str_contains($t, '?') || str_contains($t, '？')) {
            return true;
        }
        foreach (['kati', 'kaha', 'kahan', 'kasari', 'kaise', 'kyun', 'kina', 'kun ', 'kab ',
                  'milcha', 'milchha', 'milta', 'hunchha', 'huncha',
                  'how ', 'what ', 'when ', 'where ', 'why ', 'can i', 'is there', 'do you',
                  'कति', 'कहाँ', 'कहां', 'कसरी', 'कैसे', 'क्या', 'कब', 'क्यों', 'किन',
                  'કેટલા', 'ક્યાં', 'કેવી'] as $w) {
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

    /* =================================================================
     *  Opening line (21 Sep 2026, owner: "Shree Hari Bhagwan ko naam
     *  lerakhos bolne suruma", and "agent lai number bata identity
     *  garera naam le bolaos")
     *
     *  The AI assistant already opened a conversation with the house
     *  mantra and greeted a known seller by name. The local engine — now
     *  the one that actually answers first — did neither, so the same
     *  company sounded like two different businesses depending on which
     *  path happened to take the message.
     *
     *  Rules kept deliberately tight:
     *    - the mantra opens the FIRST message of a conversation only, and
     *      never a correction, a refusal or a failure. A blessing on top
     *      of "that bus is full" reads as mockery.
     *    - a name is used only when this number is ON FILE as staff or a
     *      known passenger. Guessing a name at somebody is worse than
     *      not greeting them at all.
     * ================================================================= */

    /** Has this number already been greeted inside the live conversation? */
    private static function greetedAlready(string $who): bool
    {
        try {
            return Database::fetch(
                'SELECT 1 FROM kv_store WHERE kscope = :s AND kkey = :k',
                ['s' => self::SCOPE, 'k' => $who]
            ) !== null;
        } catch (Throwable $e) {
            return true;      // unsure → stay quiet rather than repeat it
        }
    }

    /**
     * The mantra + a name, for the first line of a conversation.
     * Returns '' whenever anything is uncertain.
     */
    private static function opener(string $who, string $lang): string
    {
        if (self::greetedAlready($who)) {
            return '';
        }

        $lines = [];
        $mantra = trim(Settings::getString('company_mantra', ''));
        if ($mantra !== '') {
            $lines[] = $mantra;
        }

        $name = self::knownName($who);
        if ($name !== '') {
            $lines[] = match ($lang) {
                'ne'    => 'नमस्ते ' . $name . ' जी 🙏',
                'hi'    => 'नमस्ते ' . $name . ' जी 🙏',
                'gu'    => 'નમસ્તે ' . $name . ' જી 🙏',
                default => 'Namaste ' . $name . ' ji 🙏',
            };
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n\n";
    }

    /**
     * The name we already hold for this number — a staff member by their
     * admin record, otherwise a passenger who has travelled with us.
     * '' when we do not actually know, which is most numbers.
     */
    private static function knownName(string $who): string
    {
        if ($who === '') {
            return '';
        }
        try {
            /* Staff numbers are stored as typed (with or without +91), so
               the match is on the normalised tail, exactly as
               AiTools::whoIs() does it — one rule for who a number is. */
            foreach (Database::fetchAll(
                "SELECT full_name, phone FROM admins
                  WHERE is_active = 1 AND phone IS NOT NULL AND phone <> ''"
            ) as $row) {
                if (normalisePhone((string) $row['phone']) === $who) {
                    return self::firstName((string) $row['full_name']);
                }
            }

            $prev = (string) Database::scalar(
                "SELECT full_name FROM bookings
                  WHERE contact_phone = :p AND status IN ('confirmed','completed')
                  ORDER BY id DESC LIMIT 1",
                ['p' => $who],
                ''
            );
            return self::firstName($prev);
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * Is this "name" actually a place we serve?
     *
     * Checked against the booking's own pickup first (the common case),
     * then against every active stop and city on the route board. A real
     * passenger called after a town is possible but vanishingly rare next
     * to the parser handing us a bus stop — and the cost of being wrong is
     * only that we ask them their name, which we were going to do anyway.
     */
    private static function looksLikePlace(string $name, array $slots): bool
    {
        $n = mb_strtolower(trim($name));
        if ($n === '') {
            return false;
        }
        $board = mb_strtolower(trim((string) ($slots['boarding'] ?? '')));
        if ($board !== '' && ($n === $board || str_contains($board, $n))) {
            return true;
        }
        try {
            foreach (Database::fetchAll(
                'SELECT stop_name FROM route_stops WHERE stop_name IS NOT NULL'
            ) as $r) {
                $stop = mb_strtolower(trim((string) $r['stop_name']));
                if ($stop !== '' && ($n === $stop || str_starts_with($stop, $n . ' ') || str_contains($stop, $n))) {
                    return true;
                }
            }
            foreach (Database::fetchAll(
                'SELECT from_city, to_city FROM routes WHERE is_active = 1'
            ) as $r) {
                foreach ([$r['from_city'] ?? '', $r['to_city'] ?? ''] as $city) {
                    if ($n === mb_strtolower(trim((string) $city))) {
                        return true;
                    }
                }
            }
        } catch (Throwable $e) {
            // Unsure — let the name through rather than block a real booking.
        }
        return false;
    }

    /** "Sher Bahadur Bishwokarma" → "Sher Bahadur": warm, not formal. */
    private static function firstName(string $full): string
    {
        $full = trim((string) preg_replace('/\s+/u', ' ', $full));
        if ($full === '' || mb_strlen($full) < 2) {
            return '';
        }
        $parts = explode(' ', $full);
        $take  = array_slice($parts, 0, 2);
        return mb_substr(implode(' ', $take), 0, 40);
    }
}
