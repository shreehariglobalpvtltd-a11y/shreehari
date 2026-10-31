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

require_once __DIR__ . '/personname.php';

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
    public static function handle(string $phoneDigits, string $text, string $senderCountry = '', ?array $staff = null): ?array
    {
        $text = trim($text);
        if ($text === '' || $phoneDigits === '') {
            return null;
        }
        /* 31 Oct 2026 (passenger sim: "Tciket book" was summarised for a passenger called "Tciket", at a pickup and
           on a day nobody said): the ways people type "ticket" ARE the word, so "Tciket book" is the order button
           (the combined question asks the name, pickup and day) and "tciket" is never a name. */
        $text = (string) preg_replace('/(?<![\p{L}\p{M}])(?:tciket|tikat|tiket|tikit|tikut|tickt|tikt|ticet|tcket|tikkat)(?![\p{L}\p{M}])/iu', 'ticket', $text);
        $firstText = $text;                       // the message as it came (one-message sale)
        if (!Settings::getBool('wa_booking_on', true)) {
            return null;
        }

        /* THE SELLER (5 Oct 2026, owner: "counter, agent, customer — sabai le
           yei number bata order gare jasto"). $staff is AiTools::whoIs() for a
           number that is on file as agent / counter / office. Their order is
           the same conversation with one more question — whose ticket it is —
           and the sale goes through QuickTicket::sell() under their own
           account, exactly like the ⚡ Quick Ticket screen. */
        $seller = (is_array($staff) && (int) ($staff['adminId'] ?? 0) > 0 && is_array($staff['admin'] ?? null))
            ? $staff['admin'] : null;

        $state = self::load($phoneDigits);
        $lang  = TicketBot::detectLang($text);
        if ($state !== null && ($state['lang'] ?? '') !== '') {
            $lang = (string) $state['lang'];          // the language they opened with
        }

        /* Round 3 (31 Oct 2026, owner's rule R2 — safety review: "paisa pathaye UTR 917612345678", "UTR 9812345678" and
           "<mobile> bata paisa pathaye" became a summary for a passenger "Paisa Pathaye" / "Utr", on a day and at a pickup
           nobody said, and a later "ho" SOLD it): a line about a payment made is never a booking line or an answer to the
           booking's question. A valid reference is previewed for the office, anything else asks for the 12-digit UTR or
           the screenshot. The booking in progress (if any) stays exactly where it was. */
        require_once INCLUDE_PATH . '/wapayproof.php';
        $payAnswer = WaPayProof::answerPayTalk($phoneDigits, $text, $lang);
        if ($payAnswer !== null) {
            return self::out($payAnswer);
        }

        /* THE ORDER BUTTON (5 Oct 2026). "ticket" / "टिकट बुक" / a tap on the
           menu is not a sentence to be read — it is somebody asking to be
           walked through it. Whatever was half-way is dropped and the guided
           order starts: which way, which day, how many, which pickup, each
           with tappable answers. A real sentence ("bholi 2 seat nepal") still
           takes the reading path below, untouched. */
        $command = self::orderCommand($text);
        if ($command !== null) {
            $lang  = $command;
            /* 15 Oct 2026 (owner: "Hi bhane pachhi ekai choti sabai sodhos"): a
               CUSTOMER is asked everything in ONE message - name, boarding,
               date, how many, with an example - and Today / Tomorrow to tap.
               The guided order cost five round trips. A seller keeps the guided
               questions (whose ticket it is comes into it). wa_book_combo_on = 0
               puts the guided order back for everyone. */
            if ($seller === null && self::comboOn()) {
                return self::withForm(self::comboStart($phoneDigits, $lang), $phoneDigits, $lang, false);
            }
            $state = ['slots' => ['_guided' => true], 'awaiting' => '', 'options' => [], 'lang' => $lang];
            $text  = '';
            $formFirst = true;
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
        if ($state === null && $seller === null) {
            $justSold = self::recentSale($phoneDigits);
            if ($justSold !== null && self::looksLikeNameFix($text)) {
                $soldLang = ($justSold['lang'] ?? '') !== '' ? (string) $justSold['lang'] : $lang;
                $fixed = self::fixNameLocally($phoneDigits, $justSold, $text, $soldLang);
                if ($fixed !== null) {
                    return $fixed;
                }
            }
        }

        /* 15 Oct 2026 (owner: "naam + mobile number - teti bhanepachhi ticket
           done"): a fresh message that is a NAME and a MOBILE ("Ram Bahadur
           9812345678", "Name: Ram / Mobile: 98...") is the quick order. Date,
           pickup and seats come from the defaults (next catchable bus, this
           number's usual / the most-used pickup, one seat) - anything else the
           line says still wins. Sold at once where wa_instant_sale allows this
           sender; elsewhere it goes to the usual summary + yes. */
        $quick = $state === null && $command === null ? self::quickOrder($text) : null;

        // Nothing in progress and nothing bookable in the message — let the
        // PNR lookup, the assistant or the menu answer instead.
        if ($state === null && $quick === null && !self::looksLikeRequest($text)
            && !($seller === null && self::comboOn() && self::comboRead($text))) {
            return null;
        }

        /* Round 4 (31 Oct 2026, owner's rule R1 — safety review A4: "bholi 1 jana Surat bata ticket nakatnu", "kal 1 seat
           Surat se baad me book karunga", "… ticket chaidaina" opened a summary for a passenger "Nakatnu" / "Karunga" that
           a natural "ho" then SOLD): a fresh line that says no / not now / later starts nothing — said so, nothing kept.
           The question being answered mid-order ("ruko" at the combined question) is simply asked again. */
        if ($command === null && TicketBot::cmdHoldOrNo($text) && !self::isCancel($text)) {
            $was = (string) ($state['awaiting'] ?? '');
            if ($state === null || $was === '') {
                self::clear($phoneDigits);
                return self::out(self::say('heldFresh', $lang));
            }
            if ($was === 'combo' && $seller === null) {
                return self::comboChange($phoneDigits, is_array($state['slots'] ?? null) ? $state['slots'] : [], $lang);
            }
        }

        /* Computed BEFORE anything writes the conversation row, because
           greetedAlready() keys on exactly that row: once the state exists
           we are mid-conversation and the mantra must not repeat. A tapped
           order button was greeted a moment ago by the menu itself. */
        $opener = ($command !== null || $seller !== null) ? '' : self::opener($phoneDigits, $lang);

        $slots  = is_array($state['slots'] ?? null) ? $state['slots'] : [];
        $guided = !empty($slots['_guided']);
        if ($quick !== null) {
            $slots['_quick'] = true;
            $slots['name']   = $quick['name'];
            if ($seller !== null) {
                $slots['custPhone'] = $quick['phone'];     // the passenger's own WhatsApp
            } elseif ($quick['phone'] !== '' && normalisePhone($quick['phone']) !== normalisePhone($phoneDigits)) {
                $slots['contact'] = $quick['phone'];       // booking for somebody else: their number gets it
            }
        } elseif ($seller === null && preg_match('/\+?\d[\d\s-]{8,14}\d\s*(?:ma|मा|pe|par|पर|me|में)\s*(?:pathau\w*|pathai\w*|pathae\w*|bhej\w*|send\w*|पठाउ\S*|पठाइ\S*|भेज\S*)/iu', $text) === 1) {
            // round 2: "ticket 9327055512 ma pathaunu" with no name yet — that number is where the ticket goes
            self::noteContact($slots, $text, $phoneDigits, $seller);
        }

        /* 15 Oct 2026: "Ram, Surat, bholi, 2" on a fresh chat is the combined
           answer itself - the line the greeting and the website button teach.
           It is read in the combined mode: every one of the four is required,
           and whatever is missing is asked for in ONE message. */
        if ($quick === null && $state === null && $seller === null && self::comboOn() && self::comboRead($text)) {
            $slots['_combo'] = true;
        }
        $combo = !empty($slots['_combo']);

        /* A tapped-looking answer ("2") replies to the question we asked, not
           to the whole message. Options were stored with the question. */
        $awaiting = (string) ($state['awaiting'] ?? '');
        /* 15 Oct 2026: CHANGE (the ✏️ button under the summary, or the word at
           any question) keeps everything already said and puts the combined
           question back with the current values on it, so one message
           ("bholi", "3", "Kamrej", "naam Hari") fixes it. "change" is never
           taken for a passenger's name. */
        // review fix: not at the name question - a passenger called Badal must be able to answer it
        if ($seller === null && $awaiting !== '' && $awaiting !== 'combo' && $awaiting !== 'name' && self::isChange($text)) {
            return self::comboChange($phoneDigits, $slots, $lang);
        }
        if ($awaiting === 'dup') {
            /* 15 Oct 2026 (2E-resume): this number already holds a booking for
               that trip. "Use this booking" answers with it; "Book another"
               goes on to the summary; anything else is read as a correction. */
            $choice = self::dupChoice($text, (array) ($state['options'] ?? []));
            if ($choice === 'use') {
                return self::dupUse($phoneDigits, $slots, $lang);
            }
            if ($choice === 'another') {
                $slots['_dupSeen'] = true;
                $text = '';
            }
        } elseif ($awaiting === 'combo') {
            /* The combined question is on the table. A tap on one of the
               offered answers fills the one field it was asked for; "same as
               last time" fills the trip from this number's own history (the
               date never: it is always said); a question is handed to the
               assistant and the order waits. Everything else is read below. */
            $field  = (string) ($slots['_comboField'] ?? '');
            unset($slots['_comboField']);
            $picked = ($field !== '' && $text !== '') ? self::pickOption($text, (array) ($state['options'] ?? [])) : null;
            if ($picked !== null) {
                $slots[$field] = $field === 'seats' ? (int) $picked : $picked;
                $text = '';
            } elseif (self::isSameAsLast($text)) {
                $last = self::lastTrip($phoneDigits);
                if ($last !== null) {
                    foreach (['boarding', 'direction', 'name'] as $k) {
                        if (trim((string) ($slots[$k] ?? '')) === '' && $last[$k] !== '') {
                            $slots[$k] = $last[$k];
                        }
                    }
                    if ((int) ($slots['seats'] ?? 0) <= 0 && $last['party'] > 0) {
                        $slots['seats'] = $last['party'];
                    }
                }
                $text = '';
            } elseif (self::isChange($text)) {
                $text = '';                          // "change" again: the question is simply asked again
            } elseif ($text !== '' && self::isQuestion(mb_strtolower($text)) && !self::saysTrip($text)) {
                return null;
            }
        } elseif ($awaiting !== '' && $awaiting !== 'confirm') {
            $picked = self::pickOption($text, (array) ($state['options'] ?? []));
            if ($picked !== null) {
                $slots[$awaiting] = $picked;
                $text = '';                          // consumed by the answer
            } elseif ($awaiting === 'seats' && preg_match('/^\d{1,2}$/', trim(TicketBot::asciiDigits($text))) === 1
                      && (int) trim(TicketBot::asciiDigits($text)) > 0) {
                // A party bigger than the list offered: the number itself is the answer.
                $slots['seats'] = (int) trim(TicketBot::asciiDigits($text));
                $text = '';
            } elseif ($awaiting === 'custPhone') {
                /* The passenger's own number — the ticket goes to THAT WhatsApp.
                   "0" / "chaina" is a walk-in with no phone: the desk rule allows
                   it and the seller forwards the picture themselves. */
                $cust = self::readCustomerPhone($text);
                if ($cust === null) {
                    self::save($phoneDigits, $slots, 'custPhone', [], $lang);
                    return self::out(self::say('askCustPhoneAgain', $lang));
                }
                $slots['custPhone'] = $cust;
                $text = '';
            } elseif ($awaiting === 'name') {
                /* They answer the name question with "naam Ram", with one
                   name, or — when the party is 3 — with all three at once.
                   parseParty() reads every shape; the first is the booking
                   name and the rest ride on their own berths. */
                // round 3 (R1): "ruko", "wait", "ek chin" answer no question — the name is simply asked again
                if (TicketBot::holdOrNo($text) && !self::isCancel($text)) {
                    self::save($phoneDigits, $slots, 'name', [], $lang);
                    return self::out(self::say('askName', $lang));
                }
                // round 2: "mero naam Sunil Kumar ho" / "my name is Sunil" / "Sunil Kumar ho" answer with "Sunil Kumar"
                $nameText = self::unwrapName($text);
                $party = self::parseParty($nameText, max(1, (int) ($slots['seats'] ?? 1)));
                $lead  = $party !== [] ? (string) $party[0]['name'] : self::stripNameWord($nameText);
                /* Checked HERE, at intake (24 Sep review): a stop, a request
                   word or a yes-word is refused with the reason, before it
                   can reach the summary and loop at the sale. */
                if (PersonName::clean($lead) === '') {
                    self::save($phoneDigits, $slots, 'name', [], $lang);
                    return self::out($opener . self::say('askName', $lang) . ' (' . PersonName::why($lead) . ')');
                }
                if ($party !== []) {
                    $slots['name']  = $party[0]['name'];
                    $slots['party'] = $party;
                } else {
                    $slots['name'] = $lead;
                }
                self::noteContact($slots, $text, $phoneDigits, $seller);
                $text = '';
            }
        }

        /* The bare count of a combined answer ("Ram, Surat, bholi, 2"): the
           shared parser reads a count only beside a seat word, and the desk
           depends on that, so the lone number is read here instead. */
        if ($combo && $text !== '') {
            $n = self::bareCount($text, $awaiting === 'combo');
            if ($n > 0) {
                $slots['seats'] = $n;
            }
        }

        /* "naam Ram Bahadur" / "नाम राम" — the one correction the parser cannot
           make for itself, since any word could be a name. A list behind the
           same word is the whole family: "naam Ram, Sita 30, Maya 12". */
        if ($quick === null && preg_match('/^\s*(?:naam|nam|name|नाम|નામ)\s*[:\-]?\s*(.{2,400})$/ui', $text, $m)) {
            $m[1] = self::unwrapName($m[1]);
            $party = self::parseParty($m[1], max(1, (int) ($slots['seats'] ?? 1)));
            $lead  = $party !== [] ? (string) $party[0]['name'] : trim($m[1]);
            if (PersonName::clean($lead) === '') {
                self::save($phoneDigits, $slots, 'name', [], $lang);
                return self::out($opener . self::say('askName', $lang) . ' (' . PersonName::why($lead) . ')');
            }
            if ($party !== []) {
                $slots['name']  = $party[0]['name'];
                $slots['party'] = $party;
            } else {
                $slots['name'] = $lead;
            }
            self::noteContact($slots, $text, $phoneDigits, $seller);
            $text = '';
        }

        // The summary was on the table: yes sells it, no drops it.
        if ($awaiting === 'confirm') {
            /* Round 3 (owner's rule R1): "no" anywhere, or a pause ("ruko", "wait", "pachhi", "abhi nahi") while the
               summary waits for its yes drops it — never a correction that renames the passenger or moves the day.
               Round 4 (safety review A1: "ok wait", "ho tara ruko", "haan par abhi nahi", "ok but not now", "hajur ek chin",
               "cash? ruko", "upi wait" SOLD the seat because the yes / pay word was read first): the pause is read FIRST. */
            if (self::isNo($text) || TicketBot::holdOrNo($text)) {
                self::clear($phoneDigits);
                return self::out(self::say('cancelled', $lang));
            }
            // round 4: a question ("ho ki?", "upi chalcha?") is never the yes — it is answered and the summary waits
            if (str_contains($text, '?') || str_contains($text, '？')) {
                return null;
            }
            /* "pay cash" / "pay upi" (the two payment buttons) is a yes that
               also says HOW: on the bus, or by UPI now. A plain yes keeps the
               office default. Round 4: only a WHOLE line of yes / pay words — "ho tara date galat cha", "upi chaina"
               are not a yes (isYes / payChoice). */
            $pay = self::payChoice($text);
            if ($pay !== '' || self::isYes($text)) {
                return self::sell($phoneDigits, $slots, $lang, $senderCountry, $pay, $seller, (string) ($slots['contact'] ?? ''));
            }
            /* A question while the summary is up ("kati ghanta lagcha?")
               deserves an answer, not a re-print of the same summary. Hand it
               to the assistant and keep the booking exactly where it is. */
            if ($text !== '' && self::isQuestion(mb_strtolower($text))) {
                return null;
            }
            /* Anything else is a correction — fall through and re-plan. Round 4 (R1, review A1/A5: "ho tara date galat
               cha", "kal confirm karunga"): only a line that IS a change (a day / pickup / seat count and the words of a
               change, TicketBot::amendOnly) moves anything; any other line re-shows the same summary, still waiting. */
            if ($text !== '') {
                $chk = TicketBot::parse($text);
                if (!TicketBot::amendOnly((string) ($chk['residual'] ?? ''))) {
                    $text = '';
                } elseif ((string) ($chk['date'] ?? '') === date('Y-m-d') && !self::writtenDate($text)
                    && (string) ($slots['date'] ?? '') !== date('Y-m-d')) {
                    // "abhi" / "ahile" / "now" / "today" alone never re-date the summary (a written date does)
                    $text = (string) preg_replace('/(?<![\p{L}\p{M}])(?:abhi|ahile|aile|aba|now|today|aaja|aaj|aja|aajai|आज|अहिले|अभी)(?![\p{L}\p{M}])/iu', ' ', $text);
                }
            }
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
            /* In the combined mode the NAME in the line wins too ("Ram,
               Surat, bholi, 2" - and after CHANGE, "Hari Prasad" alone). A
               stop is never taken as a name; a new name drops the old party. */
            /* review fix: only while the name is still missing or the bot is asking
               for it (the combined question / the name question) - at the
               summary "cash ma tirchu" or "date badalnu" must never rename the
               passenger; a new name there needs "naam X" (the parser's own path). */
            $nameAsked = trim((string) ($slots['name'] ?? '')) === '' || in_array($awaiting, ['combo', 'name'], true);
            $nm = ($combo && $nameAsked) ? trim((string) ($fresh['name'] ?? '')) : '';
            if ($nm !== '' && PersonName::clean($nm) !== '' && !self::looksLikePlace($nm, $slots)
                && mb_strtolower($nm) !== mb_strtolower(trim((string) ($slots['name'] ?? '')))) {
                $slots['name'] = $nm;
                unset($slots['party'], $slots['namesAsked']);
            }
        }

        /* One call does the reading, the memory, the live plan and the
           "what is still missing" list. */
        /* What the person has actually SAID or tapped so far — before the
           engine's own defaults (today, 1 seat, the usual pickup) are folded
           in below. The guided order asks for whatever is not in here. */
        $explicit = $slots;
        /* A seller's memory lookup is the PASSENGER's number once they gave
           it — never the seller's own, or every ticket would be prefilled
           with the agent's name and habits. */
        $custDigits = $seller !== null ? normalisePhone((string) ($slots['custPhone'] ?? '')) : $phoneDigits;

        $sug = TicketBot::suggest([
            'text'         => $text,
            'phone'        => $custDigits,
            'sessionPhone' => $seller !== null ? '' : $phoneDigits,
            'name'         => $slots['name']      ?? '',
            'seats'        => $slots['seats']     ?? null,
            'date'         => $slots['date']      ?? '',
            'direction'    => $slots['direction'] ?? '',
            'boarding'     => $slots['boarding']  ?? '',
        ], $seller);

        /* suggest() falls back to "any direction" when the one asked for
           cannot be sold — right for a desk card, wrong for someone who chose
           Nepal and would be shown a bus to Gujarat. The way they chose stands;
           the real reason is said instead. */
        if (is_array($sug['plan'] ?? null) && (string) ($explicit['direction'] ?? '') !== ''
            && (string) ($sug['plan']['direction'] ?? '') !== (string) $explicit['direction']) {
            $sug['plan'] = null;
            try {
                QuickTicket::plan([
                    'direction' => (string) $explicit['direction'],
                    'date'      => (string) ($explicit['date'] ?? ''),
                    'seats'     => max(1, (int) ($explicit['seats'] ?? 1)),
                    'customer'  => $seller === null,
                ]);
            } catch (RuntimeException $e) {
                $sug['planError'] = $e->getMessage();
            }
        }

        /* THE GUIDED ORDER: one tappable question at a time, in a fixed
           order, until the trip is settled. Only what was said is kept while
           asking, so a default never counts as an answer. */
        if ($guided) {
            foreach (['direction', 'date', 'seats', 'boarding'] as $field) {
                $have = $explicit[$field] ?? '';
                if ($have !== '' && $have !== 0 && $have !== null) {
                    continue;
                }
                $planNow = is_array($sug['plan'] ?? null) ? $sug['plan'] : null;
                $prefix  = '';
                if ($planNow === null && in_array($field, ['seats', 'boarding'], true)) {
                    /* Way and day are chosen and nothing can be sold for them
                       (no bus, full, cut-off passed): say why and offer the
                       days again instead of asking how many. */
                    $prefix = trim((string) ($sug['planError'] ?? '')) !== '' ? trim((string) $sug['planError']) : self::say('noPlan', $lang);
                    unset($explicit['date']);
                    $field  = 'date';
                }
                $q = self::guidedQuestion($field, $lang, $planNow, $seller !== null);
                self::save($phoneDigits, $explicit, $field, $q['options'], $lang);
                $asked = self::out(
                    ($prefix !== '' ? $prefix . "\n\n" : '') . self::withOptions($q['question'], $q['options'], $lang),
                    null,
                    self::choiceUi(($prefix !== '' ? $prefix . "\n\n" : '') . $q['question'], $q['options'], $lang)
                );
                // The order just opened: the one-sheet form instead (the question stays as the text fallback).
                return !empty($formFirst) ? self::withForm($asked, $phoneDigits, $lang, $seller !== null) : $asked;
            }
        }

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
            if ($k === 'name' && (self::looksLikePlace((string) $v, $slots) || PersonName::clean((string) $v) === '')) {
                continue;                 // "Chahiyo" is a request word, not a passenger
            }
            /* 15 Oct 2026: in the combined mode an engine DEFAULT (today, one
               seat, the yard) is never taken for an answer - it would read as
               "said" on the next turn. What they wrote, or their own history
               (shown in the summary, confirmed by a yes), only. */
            if ($combo && in_array((string) ($sug['fields'][$k]['source'] ?? ''), ['auto', 'none'], true)) {
                continue;
            }
            $slots[$k] = $v;
        }

        /* 15 Oct 2026 (2D-combine): everything still missing, in ONE message. */
        $planNow = is_array($sug['plan'] ?? null) ? $sug['plan'] : null;
        if ($combo) {
            $missing = self::comboMissing($slots, $sug);
            if ($missing !== [] && $missing !== ['name']) {
                return self::comboAsk($phoneDigits, $slots, $missing, $lang, $planNow, $opener);
            }
            /* The four are said but nothing can be sold for that day (no bus,
               full, cut-off passed): say why and ask for another day, keeping
               the rest - instead of dropping the whole conversation. */
            if ($planNow === null && trim((string) ($sug['planError'] ?? '')) !== '') {
                unset($slots['date']);
                return self::comboAsk($phoneDigits, $slots, ['date'], $lang, null, trim((string) $sug['planError']) . "\n\n");
            }
        }

        $ask = ($combo || !empty($slots['_quick'])) ? null : ($sug['ask'] ?? null);
        if (is_array($ask) && ($ask['field'] ?? '') !== '') {
            /* A customer with more than one gap is asked for all of them at
               once (and the answer is read in the combined mode). */
            if ($seller === null && self::comboOn()) {
                $missing = self::comboMissing($slots, $sug);
                if (count($missing) > 1) {
                    // The engine's defaults were folded in above: they are not answers.
                    foreach (['name', 'seats', 'date', 'direction', 'boarding', 'country'] as $k) {
                        if (in_array((string) ($sug['fields'][$k]['source'] ?? ''), ['auto', 'none'], true)) {
                            unset($slots[$k]);
                        }
                    }
                    $slots['_combo'] = true;
                    return self::comboAsk($phoneDigits, $slots, $missing, $lang, $planNow, $opener);
                }
            }
            $options = array_values(array_filter(
                (array) ($ask['options'] ?? []),
                static fn ($o): bool => is_array($o) && ($o['value'] ?? '') !== '' && ($o['value'] ?? '') !== 'other'
            ));
            self::save($phoneDigits, $slots, (string) $ask['field'], $options, $lang);
            return self::out(
                $opener . self::withOptions((string) $ask['question'], $options, $lang),
                null,
                self::choiceUi($opener . (string) $ask['question'], $options, $lang)
            );
        }

        /* A seller is asked whose ticket this is BEFORE the name: a number we
           have met fills the name in from its own last ticket. */
        /* 15 Oct 2026 (owner: "WhatsApp bata 1 msg ma ticket"): a seller's
           first message that already carries the passenger's mobile
           ("Ram 9812345678 Surat bholi 2") needs no "whose number?" turn. */
        if ($seller !== null && !array_key_exists('custPhone', $slots) && $state === null) {
            $inLine = (string) (TicketBot::parse($firstText)['phone'] ?? '');
            $cust   = $inLine !== '' ? self::readCustomerPhone($inLine) : null;
            if ($cust !== null && $cust !== '') {
                $slots['custPhone'] = $cust;
            }
        }
        if ($seller !== null && !array_key_exists('custPhone', $slots)) {
            self::save($phoneDigits, $slots, 'custPhone', [], $lang);
            return self::out(self::say('askCustPhone', $lang));
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

        /* 15 Oct 2026 (2E-resume): a booking for this trip already sits on this
           number (started on the website, or an earlier chat). Say so and let
           them pick it up, instead of a silent refusal or a second booking. */
        $dup = $sug['duplicate'] ?? null;
        if ($seller === null && is_array($dup) && (string) ($dup['pnr'] ?? '') !== '' && empty($slots['_dupSeen'])) {
            return self::dupAsk($phoneDigits, $slots, $dup, $lang, $opener);
        }

        /* 15 Oct 2026: ONE message carried the whole order - sold at once,
           no "yes" turn (wa_instant_sale: staff = agents / counter numbers,
           all = customers too, off = always ask). Only what was SAID counts:
           a default day or the yard pickup never sells by itself. */
        if (self::instantOk($seller, $state === null, $sug, $slots, $combo)) {
            return self::sell($phoneDigits, $slots, $lang, $senderCountry, '', $seller, (string) ($slots['contact'] ?? ''));
        }

        // Everything is known: show what it will cost and wait for a yes.
        self::save($phoneDigits, $slots, 'confirm', [], $lang);
        /* 23 Sep 2026 (owner: "customer lai seat photo"): the summary rides on
           the coach picture with THEIR berths in orange, so they see where
           they will sleep before they say yes. No picture = the same text. */
        $picture = null;
        if (Settings::getBool('wa_seat_photo_on', false) && (int) ($plan['scheduleId'] ?? 0) > 0) {
            try {
                require_once INCLUDE_PATH . '/seatmappng.php';
                // Physical beds, so a private cabin lights up both of its berths.
                $beds = [];
                foreach ((array) ($plan['seats'] ?? []) as $s) {
                    foreach (Seats::physicalSeats((string) $s, (string) ($plan['bookingMode'] ?? 'sharing'), (string) ($plan['coach'] ?? 'sleeper')) as $b) {
                        $beds[] = (string) $b;
                    }
                }
                $picture = SeatMapPng::url((int) $plan['scheduleId'], $beds);
            } catch (Throwable $e) {
                $picture = null;
            }
        }
        $summary = self::summary($plan, $slots, $lang, $seller);
        return self::out(
            $opener . $summary . "\n" . self::confirmHelp($lang, $seller !== null),
            $picture,
            self::confirmUi($opener . $summary, $lang, $seller !== null)
        );
    }

    /* -----------------------------------------------------------------
     *  The guided order (5 Oct 2026): commands, questions, tappable answers
     * --------------------------------------------------------------- */

    /**
     * May this order be sold without the summary + yes? (15 Oct 2026)
     * Fresh conversation (the whole order in ONE message), the switch allows
     * this sender, and the day and the pickup were said - in the line, or the
     * pickup from this passenger's own history - never an engine default.
     */
    private static function instantOk(?array $seller, bool $fresh, array $sug, array $slots, bool $combo): bool
    {
        if (!$fresh) {
            return false;
        }
        $mode = strtolower(trim(Settings::getString('wa_instant_sale', 'staff')));
        if (!in_array($mode, ['staff', 'all'], true) || ($mode === 'staff' && $seller === null)) {
            return false;
        }
        if (!empty($slots['_quick'])) {
            return trim((string) ($slots['name'] ?? '')) !== '';   // name + mobile: the defaults are the owner's rule
        }
        $src = static fn(string $k): string => (string) ($sug['fields'][$k]['source'] ?? '');
        if (!in_array($src('date'), ['text', 'input'], true)) {
            return false;
        }
        if (!in_array($src('boarding'), ['text', 'input', 'history'], true)) {
            return false;
        }
        if ($seller === null && !$combo) {
            return false;                          // a customer's line is read in the combined mode only
        }
        return trim((string) ($slots['name'] ?? '')) !== '';
    }

    /**
     * A name and a mobile, and nothing that makes it something else (15 Oct 2026).
     * "Ram Bahadur 9812345678" / "Name: Ram Bahadur\nMobile: 98123 45678" /
     * "Sita 98... Surat bholi" -> ['name' => 'Ram Bahadur', 'phone' => '9812345678'].
     * A complaint, a question or a PNR is never an order.
     *
     * @return array{name: string, phone: string}|null
     */
    private static function quickOrder(string $text): ?array
    {
        $t = mb_strtolower(trim($text));
        if ($t === '' || mb_strlen($t) > 200 || self::isAboutExistingTicket($t) || self::isQuestion($t)
            || preg_match('/shg-\d{4}-\d+|pnr/i', $t) === 1) {
            return null;
        }
        $p     = TicketBot::parse($text);
        $phone = trim((string) ($p['phone'] ?? ''));
        $cust  = $phone !== '' ? self::readCustomerPhone($phone) : null;
        $name  = PersonName::clean((string) ($p['name'] ?? ''));
        if ($cust === null || $cust === '' || $name === '' || self::looksLikePlace($name, [])) {
            return null;
        }
        return ['name' => $name, 'phone' => $cust];
    }

    /** Is an order conversation open for this number? */
    public static function isOpen(string $phoneDigits): bool
    {
        return $phoneDigits !== '' && self::load($phoneDigits) !== null;
    }

    /**
     * Round 4 (R1 + R2, probe of review A3: "paisa pathaidiye" while the summary waited got "send the UTR", and the next
     * "ho" sold the summary as pay-on-bus — a "ho" to which question?): while this chat's summary waits for its yes, the
     * line said under a payment answer — the booking is NOT made, and what "ho" / "UPI" / "no" would do. '' otherwise.
     */
    /** Round 4 (usability): the language this chat's open order was started in ('' = none open). */
    public static function openLang(string $phoneDigits): string
    {
        $st = $phoneDigits !== '' ? self::load($phoneDigits) : null;
        return $st !== null ? (string) ($st['lang'] ?? '') : '';
    }

    public static function summaryWaitingNote(string $phoneDigits): string
    {
        $st = $phoneDigits !== '' ? self::load($phoneDigits) : null;
        if ($st === null || (string) ($st['awaiting'] ?? '') !== 'confirm') {
            return '';
        }
        return "\n\n" . self::say('summaryWaits', (string) ($st['lang'] ?? '') !== '' ? (string) $st['lang'] : 'ne');
    }

    /** 10 Oct 2026: a ticket sold on this number minutes ago — its own name fix (below) answers first. */
    public static function justSold(string $phoneDigits): bool
    {
        return $phoneDigits !== '' && self::recentSale($phoneDigits) !== null;
    }

    /**
     * "ticket", "टिकट बुक", "order", or the menu button ("ticket book ne"):
     * the whole message is a request to be walked through a booking.
     * Returns the language to hold the order in, or null when the message is
     * anything more than the bare command — a sentence is read, not guided.
     */
    public static function orderCommand(string $text): ?string
    {
        $t = mb_strtolower(trim((string) preg_replace('/[\s.!🎫]+/u', ' ', $text)));
        if ($t === '' || mb_strlen($t) > 40) {
            return null;
        }
        // The menu button carries the language the menu was shown in.
        if (preg_match('/^ticket book (ne|hi|en|gu)$/', $t, $m) === 1) {
            return $m[1];
        }
        $byLang = [
            'en' => ['book ticket', 'book a ticket', 'new ticket', 'order', 'order ticket', 'book now', 'booking'],
            'hi' => ['टिकट चाहिए', 'टिकट चाहिये', 'टिकट बुक करो', 'टिकट बुक करना है', 'ticket chahiye', 'ticket book karo', 'ticket book karna hai'],
            'gu' => ['ટિકિટ', 'ટિકિટ બુક', 'ટિકિટ જોઈએ', 'ticket joie', 'ticket joiye'],
            'ne' => ['ticket', 'tickets', 'tiket', 'book', 'ticket book', 'ticket chahiyo', 'ticket chaiyo', 'ticket katnu', 'ticket katne',
                     'ticket kat', 'ticket book garnu', 'ticket book garne', 'टिकट', 'टिकट बुक', 'बुक', 'टिकट चाहियो', 'टिकट काट्ने',
                     'टिकट काट्नु', 'टिकट बुक गर्ने', 'टिकट बुक गर्नु'],
        ];
        foreach ($byLang as $lang => $words) {
            if (in_array($t, $words, true)) {
                return $lang;
            }
        }
        return null;
    }

    /* -----------------------------------------------------------------
     *  The combined question (15 Oct 2026, owner: "WhatsApp bata super
     *  fast ticket - Hi bhane pachhi ek choti ma sabai sodhne")
     *
     *  A customer is asked name, boarding, date and how many in ONE message;
     *  the answer "Ram, Surat, bholi, 2" is read whole, and whatever is still
     *  missing is asked for in ONE more. Nothing here sells: the summary and
     *  its explicit yes are exactly as before.
     * --------------------------------------------------------------- */

    /**
     * The booking FORM (15 Oct 2026, includes/waflow.php): on the Meta number,
     * with the form published, the opening question goes out as the one-sheet
     * WhatsApp form instead. The question is kept as the text - when Meta
     * refuses the form the webhook sends that text, and a typed answer is read
     * by the conversation saved above exactly as before.
     *
     * @param array{text: string, media: ?string, ui: ?array} $reply
     */
    private static function withForm(array $reply, string $who, string $lang, bool $forSeller): array
    {
        try {
            require_once __DIR__ . '/waflow.php';
            if (WaFlow::canSend()) {
                $reply['ui'] = WaFlow::ui($who, $lang, $forSeller);
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
        }
        return $reply;
    }

    /**
     * A submitted booking form (WaFlow::handleReply): straight to the sale -
     * the Book button in the form is the confirmation. $contact is the mobile
     * typed in the form ('' = the sender's own number); for a seller it is the
     * customer's number the ticket goes to.
     *
     * @return array{text: string, media: ?string, ui: ?array}
     */
    public static function sellFromForm(string $phoneDigits, array $slots, string $lang, string $senderCountry, string $pay, ?array $seller, string $contact = ''): array
    {
        $state = self::load($phoneDigits);
        if ($state !== null && (string) ($state['lang'] ?? '') !== '') {
            $lang = (string) $state['lang'];          // the language the order was opened in
        }
        self::clear($phoneDigits);
        if ($seller !== null) {
            $slots['custPhone'] = $contact;
            return self::sell($phoneDigits, $slots, $lang, $senderCountry, 'cash', $seller);
        }
        return self::sell($phoneDigits, $slots, $lang, $senderCountry, $pay, null, $contact);
    }

    /** parseParty() for the form's "other passengers" box. */
    public static function partyFrom(string $text, int $max): array
    {
        return self::parseParty($text, max(1, $max));
    }

    /** wa_book_combo_on (default on): customers get the combined question. */
    private static function comboOn(): bool
    {
        return Settings::getBool('wa_book_combo_on', true);
    }

    /** "ticket" / the Book button from a customer: the one combined question. */
    private static function comboStart(string $who, string $lang): array
    {
        $last  = self::lastTrip($who);
        $lines = [self::say('comboAsk', $lang)];
        if ($last !== null) {
            /* 2A-returning: their own last trip, offered - one tap fills it,
               the date is still theirs to say and the summary still waits
               for an explicit yes. */
            $lines[] = '';
            $lines[] = sprintf(self::say('sameLast', $lang), self::lastLine($last, $lang));
        }
        $text = implode("\n", $lines);
        self::save($who, ['_combo' => true], 'combo', [], $lang);
        return self::out($text, null, ['kind' => 'buttons', 'body' => $text, 'buttons' => self::comboButtons($lang, $last !== null, false)]);
    }

    /** CHANGE under the summary: the same question, showing what we hold now. */
    private static function comboChange(string $who, array $slots, string $lang): array
    {
        $slots['_combo'] = true;
        unset($slots['_comboField']);
        $text = sprintf(self::say('comboNow', $lang), self::haveLine($slots, $lang)) . "\n" . self::say('comboChange', $lang);
        self::save($who, $slots, 'combo', [], $lang);
        return self::out($text, null, ['kind' => 'buttons', 'body' => $text, 'buttons' => self::comboButtons($lang, false, true)]);
    }

    /**
     * [Today] [Tomorrow], and [Same as last time] or [No change]. The ids are
     * WORDS ("shg:tomorrow"), not numbers, so a tap reads exactly like typing
     * them and a typed "2" stays a passenger count.
     *
     * @return list<array{id: string, title: string}>
     */
    private static function comboButtons(string $lang, bool $same, bool $keep): array
    {
        $t = static fn (array $w): string => $w[$lang] ?? $w['en'];
        $b = [
            ['id' => 'shg:today',    'title' => $t(['en' => '📅 Today', 'hi' => '📅 आज', 'ne' => '📅 आज', 'gu' => '📅 આજે'])],
            ['id' => 'shg:tomorrow', 'title' => $t(['en' => '📅 Tomorrow', 'hi' => '📅 कल', 'ne' => '📅 भोलि', 'gu' => '📅 કાલે'])],
        ];
        if ($same) {
            $b[] = ['id' => 'shg:same as last time', 'title' => $t(['en' => '🔁 Same as last time', 'hi' => '🔁 पहले जैसा', 'ne' => '🔁 पहिले जस्तै', 'gu' => '🔁 પહેલાં જેવું'])];
        } elseif ($keep) {
            $b[] = ['id' => 'shg:ok', 'title' => $t(['en' => '✅ No change', 'hi' => '✅ कोई बदलाव नहीं', 'ne' => '✅ केही नबदल्ने', 'gu' => '✅ ફેરફાર નહીં'])];
        }
        return $b;
    }

    /**
     * Which of the four the combined mode still lacks. A value counts when it
     * was said (or tapped) or comes from this number's own history - never
     * an engine default. The boarding point is not needed when the way was
     * said and that way has a single pickup ahead.
     *
     * @return list<string> of name | boarding | date | seats
     */
    private static function comboMissing(array $slots, array $sug): array
    {
        $told = static fn (string $f): bool => !in_array((string) ($sug['fields'][$f]['source'] ?? 'none'), ['auto', 'none'], true);
        $missing = [];
        if (trim((string) ($slots['name'] ?? '')) === '') {
            $missing[] = 'name';
        }
        if (!$told('boarding')) {
            $ahead = 0;
            foreach ((array) ($sug['plan']['stops'] ?? []) as $s) {
                if (!empty($s['ahead'])) {
                    $ahead++;
                }
            }
            if (!($told('direction') && is_array($sug['plan'] ?? null) && $ahead <= 1)) {
                $missing[] = 'boarding';
            }
        }
        if (!$told('date')) {
            $missing[] = 'date';
        }
        if (!$told('seats')) {
            $missing[] = 'seats';
        }
        return $missing;
    }

    /**
     * Ask for everything still missing in ONE message. A single gap gets its
     * own question with tappable answers (a tap, or its number, answers it);
     * several gaps get one line naming them all, with an example.
     */
    private static function comboAsk(string $who, array $slots, array $missing, string $lang, ?array $plan, string $prefix): array
    {
        $slots['_combo'] = true;
        unset($slots['_comboField']);
        $have = self::haveLine($slots, $lang);
        $head = $prefix . ($have !== '' ? '✔️ ' . $have . "\n" : '');

        if (count($missing) === 1 && in_array($missing[0], ['date', 'seats', 'boarding'], true)) {
            $q = self::guidedQuestion($missing[0], $lang, $plan, false);
            $slots['_comboField'] = $missing[0];
            self::save($who, $slots, 'combo', $q['options'], $lang);
            return self::out(
                $head . self::withOptions($q['question'], $q['options'], $lang),
                null,
                self::choiceUi($head . $q['question'], $q['options'], $lang)
            );
        }

        $names = [];
        $eg    = [];
        $day   = ['en' => 'tomorrow', 'hi' => 'kal', 'ne' => 'bholi', 'gu' => 'કાલે'][$lang] ?? 'tomorrow';
        foreach (['name' => ['name', 'Ram'], 'boarding' => ['board', 'Surat'], 'date' => ['date', $day], 'seats' => ['pax', '2']] as $f => [$labelKey, $sample]) {
            if (in_array($f, $missing, true)) {
                $names[] = self::label($labelKey, $lang);
                $eg[]    = $sample;
            }
        }
        $text = $head . sprintf(self::say('comboMore', $lang), implode(', ', $names)) . "\n"
              . sprintf(self::say('example', $lang), implode(', ', $eg));
        self::save($who, $slots, 'combo', [], $lang);
        $ui = in_array('date', $missing, true)
            ? ['kind' => 'buttons', 'body' => $text, 'buttons' => self::comboButtons($lang, false, false)]
            : null;
        return self::out($text, null, $ui);
    }

    /** "Name Ram · Boarding Surat · Date Fri, 16 Oct · Passengers 2" — what we hold. */
    private static function haveLine(array $slots, string $lang): string
    {
        $parts = [];
        $name  = trim((string) ($slots['name'] ?? ''));
        if ($name !== '') {
            $parts[] = self::label('name', $lang) . ' ' . $name;
        }
        $board = trim((string) ($slots['boarding'] ?? ''));
        if ($board !== '') {
            $parts[] = self::label('board', $lang) . ' ' . self::stopName($board);
        } elseif (in_array((string) ($slots['direction'] ?? ''), ['toNepal', 'toIndia'], true)) {
            $parts[] = self::wayName((string) $slots['direction'], $lang);
        }
        $date = trim((string) ($slots['date'] ?? ''));
        if ($date !== '' && Security::isValidDate($date)) {
            $parts[] = self::label('date', $lang) . ' ' . formatDate($date, 'D, j M');
        }
        if ((int) ($slots['seats'] ?? 0) > 0) {
            $parts[] = self::label('pax', $lang) . ' ' . (int) $slots['seats'];
        }
        return implode(' · ', $parts);
    }

    /** "Surat · Station Road @ 23:00" → "Surat". */
    private static function stopName(string $value): string
    {
        try {
            if (class_exists('Boarding')) {
                $n = trim((string) (Boarding::stopDisplay($value)['name'] ?? ''));
                if ($n !== '') {
                    return $n;
                }
            }
        } catch (Throwable $e) {
            // fall through to the value itself
        }
        return trim((string) preg_replace('/\s*@.*$/u', '', $value));
    }

    private static function wayName(string $dir, string $lang): string
    {
        return [
            'toNepal' => ['en' => 'To Nepal', 'hi' => 'नेपाल जाना', 'ne' => 'नेपाल जाने', 'gu' => 'નેપાળ જવું'],
            'toIndia' => ['en' => 'To Gujarat', 'hi' => 'गुजरात जाना', 'ne' => 'गुजरात जाने', 'gu' => 'ગુજરાત જવું'],
        ][$dir][$lang] ?? $dir;
    }

    /**
     * A fresh message that IS the combined answer: three or more pieces
     * split by commas, a day or a stop among them, and a name left over -
     * "Ram, Surat, bholi, 2", "राम, सूरत, भोलि, २". Questions and messages
     * about an existing ticket never qualify.
     */
    private static function comboRead(string $text): bool
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) > 200) {
            return false;
        }
        $parts = array_filter(preg_split('/\s*[,;،।]+\s*/u', $t) ?: [], static fn ($p): bool => trim((string) $p) !== '');
        if (count($parts) < 3) {
            return false;
        }
        $lower = mb_strtolower($t);
        if (self::isAboutExistingTicket($lower) || self::isQuestion($lower)) {
            return false;
        }
        $p = TicketBot::parse($t);
        if ((string) ($p['date'] ?? '') === '' && (string) ($p['boarding'] ?? '') === '') {
            return false;
        }
        $name = trim((string) ($p['name'] ?? ''));
        return $name !== '' && PersonName::clean($name) !== '';
    }

    /**
     * The lone passenger count of a combined answer: the last comma piece
     * that is just a number ("Ram, Surat, bholi, 2"; Devanagari and Gujarati
     * digits too), or a message that is only the number. Inside the combined
     * question ($loose) a number closing a line without commas also counts
     * ("Ram Surat bholi 2") - never one that follows a month or a date word
     * ("Nov 5", "miti 5"). 0 when there is none, or it is more than a
     * customer may book.
     */
    private static function bareCount(string $text, bool $loose): int
    {
        $t = trim(TicketBot::asciiDigits($text));
        if ($t === '') {
            return 0;
        }
        $max  = BookingService::maxSeatsFor(false);
        $pick = static fn (string $tok): int => (preg_match('/^\d{1,2}$/', $tok) === 1 && (int) $tok >= 1 && (int) $tok <= $max) ? (int) $tok : 0;
        $parts = preg_split('/\s*[,;،।|\n]+\s*/u', $t) ?: [];
        if (count($parts) > 1) {
            foreach (array_reverse($parts) as $piece) {
                $n = $pick(trim((string) $piece));
                if ($n > 0) {
                    return $n;
                }
            }
            return 0;
        }
        if ($pick($t) > 0) {
            return $pick($t);
        }
        if ($loose && preg_match('/^(.*\S)\s+(\d{1,2})$/u', $t, $m) === 1) {
            $before = mb_strtolower((string) preg_replace('/^.*\s/u', '', trim($m[1])));
            $dateWords = ['jan', 'january', 'feb', 'february', 'mar', 'march', 'apr', 'april', 'may', 'jun', 'june', 'jul', 'july',
                          'aug', 'august', 'sep', 'sept', 'september', 'oct', 'october', 'nov', 'november', 'dec', 'december',
                          'on', 'date', 'miti', 'मिति', 'तारीख', 'तारिख', 'તારીખ',
                          // review fix: Nepali (Bikram Sambat) months - "Kartik 5" is a date, not 5 seats
                          'baisakh', 'baishakh', 'jestha', 'jeth', 'asar', 'asadh', 'ashadh', 'shrawan', 'saun', 'sawan', 'bhadra', 'bhadau',
                          'ashwin', 'asoj', 'kartik', 'mangsir', 'marga', 'poush', 'push', 'magh', 'falgun', 'phagun', 'chaitra', 'chait',
                          'बैशाख', 'वैशाख', 'जेठ', 'असार', 'साउन', 'श्रावण', 'भदौ', 'भाद्र', 'असोज', 'आश्विन', 'कात्तिक', 'कार्तिक',
                          'मंसिर', 'मङ्सिर', 'पुस', 'पौष', 'माघ', 'फागुन', 'फाल्गुन', 'चैत', 'चैत्र'];
            if (!in_array($before, $dateWords, true)) {
                return $pick($m[2]);
            }
        }
        return 0;
    }

    /** Does a question in the combined mode also carry part of the trip? */
    private static function saysTrip(string $text): bool
    {
        if (self::bareCount($text, true) > 0) {
            return true;
        }
        $p = TicketBot::parse($text);
        return (string) ($p['date'] ?? '') !== '' || (string) ($p['boarding'] ?? '') !== ''
            || (string) ($p['direction'] ?? '') !== '' || (int) ($p['seats'] ?? 0) > 0;
    }

    /** Lower-case, no emoji or punctuation, single spaces. */
    private static function words(string $text): string
    {
        $t = mb_strtolower(trim((string) preg_replace('/[^\p{L}\p{M}\p{N}\s]+/u', ' ', $text)));
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /** "change" / "badal" / "बदल" under the summary (the ✏️ button says "change"). */
    private static function isChange(string $text): bool
    {
        return in_array(self::words($text), ['change', 'change it', 'change booking', 'edit', 'badal', 'badla', 'badlau', 'badalnu',
            'badalne', 'badlo', 'badlna', 'change garne', 'change garnu', 'बदल', 'बदल्ने', 'बदल्नु', 'बदल्नुहोस्', 'बदलें', 'बदलना',
            'बदलो', 'બદલો', 'બદલ', 'બદલવું'], true);
    }

    /** "same as last time" (the 🔁 button) and the ways people say it. */
    private static function isSameAsLast(string $text): bool
    {
        return in_array(self::words($text), ['same as last time', 'same as last', 'same', 'same again', 'as last time', 'pahile jastai',
            'pahile jasto', 'pahila jastai', 'pailako jastai', 'paila jastai', 'pahle jaisa', 'pehle jaisa', 'pahle jaise',
            'पहिले जस्तै', 'पहिलेजस्तै', 'पहिलो जस्तै', 'पहले जैसा', 'पहले जैसे', 'પહેલાં જેવું', 'પહેલા જેવું'], true);
    }

    /**
     * This number's own usual trip (TicketBot::profile - the same memory the
     * desk card uses, only ever read for the number's own chat). Null when
     * it has never travelled with us.
     *
     * @return array{name: string, boarding: string, boardingName: string, direction: string, party: int}|null
     */
    private static function lastTrip(string $who): ?array
    {
        try {
            $p = TicketBot::profile($who);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($p)) {
            return null;
        }
        $board = (string) ($p['boarding']['value'] ?? '');
        $dir   = (string) ($p['direction']['value'] ?? '');
        if ($board === '' && $dir === '') {
            return null;
        }
        $name = trim((string) ($p['name'] ?? ''));
        return [
            'name'         => $name !== '' && PersonName::clean($name) !== '' ? $name : '',
            'boarding'     => $board,
            'boardingName' => trim((string) ($p['boarding']['name'] ?? '')) !== '' ? (string) $p['boarding']['name'] : self::stopName($board),
            'direction'    => $dir,
            'party'        => max(1, (int) ($p['party']['size'] ?? 1)),
        ];
    }

    private static function lastLine(array $last, string $lang): string
    {
        $parts = [];
        if ($last['name'] !== '') {
            $parts[] = $last['name'];
        }
        if ($last['boarding'] !== '') {
            $parts[] = $last['boardingName'];
        }
        if ($last['direction'] !== '') {
            $parts[] = self::wayName($last['direction'], $lang);
        }
        $parts[] = self::label('pax', $lang) . ' ' . $last['party'];
        return implode(' · ', $parts);
    }

    /* -----------------------------------------------------------------
     *  A booking already on this number (15 Oct 2026, 2E-resume)
     * --------------------------------------------------------------- */

    private static function dupAsk(string $who, array $slots, array $dup, string $lang, string $opener): array
    {
        $t = static fn (array $w): string => $w[$lang] ?? $w['en'];
        $slots['_dupPnr'] = (string) $dup['pnr'];
        $options = [
            ['value' => 'use',     'label' => $t(['en' => '📋 Use this booking', 'hi' => '📋 यही बुकिंग', 'ne' => '📋 यही बुकिङ', 'gu' => '📋 આ જ બુકિંગ'])],
            ['value' => 'another', 'label' => $t(['en' => '🎫 Book another', 'hi' => '🎫 एक और बुक करें', 'ne' => '🎫 अर्को बुक गर्ने', 'gu' => '🎫 બીજી બુક કરો'])],
        ];
        $date  = (string) ($dup['date'] ?? '');
        $q = sprintf(
            self::say('dupFound', $lang),
            (string) $dup['pnr'],
            self::say((string) ($dup['status'] ?? '') === 'confirmed' ? 'dupConfirmed' : 'dupPending', $lang),
            $date !== '' && Security::isValidDate($date) ? formatDate($date, 'D, j M') : $date
        );
        self::save($who, $slots, 'dup', $options, $lang);
        return self::out($opener . self::withOptions($q, $options, $lang), null, self::choiceUi($opener . $q, $options, $lang));
    }

    /** 'use' | 'another' | '' — the tap, its number, or the words. */
    private static function dupChoice(string $text, array $options): string
    {
        $picked = self::pickOption($text, $options);
        if ($picked === 'use' || $picked === 'another') {
            return $picked;
        }
        $w = self::words($text);
        if (in_array($w, ['use', 'use this', 'use this booking', 'use it', 'yo', 'yehi', 'yahi', 'tyahi', 'tehi', 'यही', 'यो', 'त्यही', 'उही', 'એ જ', 'આ જ'], true)) {
            return 'use';
        }
        if (in_array($w, ['another', 'book another', 'new', 'new booking', 'arko', 'naya', 'nayan', 'अर्को', 'नयाँ', 'दूसरा', 'दूसरी', 'नया', 'બીજી', 'નવી'], true)) {
            return 'another';
        }
        return '';
    }

    /**
     * "Use this booking": the booking itself - the ticket (with its links)
     * when confirmed, the payment link and QR when it still waits for money.
     * Only ever a booking whose contact number IS this sender.
     */
    private static function dupUse(string $who, array $slots, string $lang): array
    {
        $pnr = (string) ($slots['_dupPnr'] ?? '');
        self::clear($who);
        try {
            $d = $pnr !== '' ? BookingService::detail($pnr) : null;
        } catch (Throwable $e) {
            $d = null;
        }
        if ($d === null || normalisePhone((string) ($d['contact_phone'] ?? '')) !== $who
            || !in_array((string) ($d['status'] ?? ''), ['pending', 'confirmed'], true)) {
            return self::out(self::say('dupGone', $lang));
        }
        $bid   = (int) ($d['id'] ?? 0);
        $date  = (string) Database::scalar('SELECT MIN(travel_date) FROM booking_legs WHERE booking_id = :b', ['b' => $bid], '');
        $lines = [self::say('dupHere', $lang), '', self::label('pnr', $lang) . ': ' . $pnr];
        if ($date !== '') {
            $lines[] = self::label('date', $lang) . ': ' . formatDate($date, 'D, j M');
        }
        $lines[] = self::label('total', $lang) . ': ' . inr((float) ($d['total_amount'] ?? 0));
        $lines[] = '';
        if ((string) $d['status'] === 'confirmed') {
            foreach (self::ticketLinks($pnr, $bid, $lang) as $l) {
                $lines[] = $l;
            }
            return self::out(implode("\n", $lines), Ticket::imageUrl($pnr));
        }
        $lines[] = self::say('payNow', $lang);
        $lines[] = appUrl('pay.php?pnr=' . urlencode($pnr));
        return self::out(implode("\n", $lines), appUrl('pay-image.php?pnr=' . urlencode($pnr)));
    }

    /**
     * Figures for the WhatsApp Manager (15 Oct 2026, 2G): booking chats
     * active in the last 30 minutes, WhatsApp bookings today / 7 days, and -
     * from the booking.wa_sale audit rows - how long a chat sale takes and
     * how many messages the passenger needed.
     *
     * @return array{openChats: int, waToday: int, wa7: int, chatSales7: int, avgSeconds: ?int, avgMessages: ?float}
     */
    public static function stats(): array
    {
        $out = ['openChats' => 0, 'waToday' => 0, 'wa7' => 0, 'chatSales7' => 0, 'avgSeconds' => null, 'avgMessages' => null];
        try {
            $out['openChats'] = (int) Database::scalar(
                'SELECT COUNT(*) FROM kv_store WHERE kscope = :s AND updated_at >= NOW() - INTERVAL 30 MINUTE',
                ['s' => self::SCOPE], 0
            );
        } catch (Throwable $e) {
            // a missing figure, never a broken page
        }
        try {
            if (!class_exists('BookingChannel')) {
                require_once INCLUDE_PATH . '/bookingchannel.php';
            }
            $today = todayISO();
            $out['waToday'] = (int) (BookingChannel::counts($today, $today)['WHATSAPP']['n'] ?? 0);
            $out['wa7']     = (int) (BookingChannel::counts(addDaysISO($today, -6), $today)['WHATSAPP']['n'] ?? 0);
        } catch (Throwable $e) {
        }
        try {
            $secs = [];
            $msgs = [];
            foreach (Database::fetchAll(
                "SELECT new_value FROM audit_logs
                  WHERE action = 'booking.wa_sale' AND created_at >= NOW() - INTERVAL 7 DAY
                  ORDER BY id DESC LIMIT 500"
            ) as $r) {
                $v = json_decode((string) ($r['new_value'] ?? ''), true);
                if (!is_array($v)) {
                    continue;
                }
                if (isset($v['seconds']) && is_numeric($v['seconds'])) {
                    $secs[] = (int) $v['seconds'];
                }
                if (isset($v['messages']) && is_numeric($v['messages'])) {
                    $msgs[] = (int) $v['messages'];
                }
            }
            $out['chatSales7']  = max(count($secs), count($msgs));
            $out['avgSeconds']  = $secs !== [] ? (int) round(array_sum($secs) / count($secs)) : null;
            $out['avgMessages'] = $msgs !== [] ? round(array_sum($msgs) / count($msgs), 1) : null;
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * One guided question with its answers.
     *
     * @return array{question: string, options: list<array{value: string, label: string, desc?: string}>}
     */
    private static function guidedQuestion(string $field, string $lang, ?array $plan, bool $forSeller): array
    {
        $base     = TicketBot::question($field, $lang, $plan) ?? ['question' => '', 'options' => []];
        $question = (string) $base['question'];
        $options  = [];

        if ($field === 'direction') {
            /* Both ways with today's price, so the fare is on the table
               before anything is chosen (owner: "Gujarat jane, aune, bhada
               yeti"). A way with no bus coming simply shows no price. */
            $names = [
                'toNepal' => ['en' => 'To Nepal', 'hi' => 'नेपाल जाना', 'ne' => 'नेपाल जाने', 'gu' => 'નેપાળ જવું'],
                'toIndia' => ['en' => 'To Gujarat', 'hi' => 'गुजरात जाना', 'ne' => 'गुजरात जाने', 'gu' => 'ગુજરાત જવું'],
            ];
            $lines = [];
            foreach ($names as $dir => $label) {
                $fare = '';
                try {
                    $p    = QuickTicket::plan(['direction' => $dir, 'seats' => 1, 'customer' => !$forSeller]);
                    $one  = (float) ($p['fare']['total'] ?? 0);
                    $fare = $one > 0 ? inr($one) : '';
                    if ((string) ($p['direction'] ?? $dir) !== $dir) {
                        $fare = '';
                    }
                } catch (Throwable $e) {
                    $fare = '';
                }
                $flag      = $dir === 'toNepal' ? '🇳🇵 ' : '🇮🇳 ';
                $options[] = ['value' => $dir, 'label' => $flag . ($label[$lang] ?? $label['en'])];
                $lines[]   = $flag . ($label[$lang] ?? $label['en']) . ($fare !== '' ? ' — ' . $fare : '');
            }
            $question = [
                'en' => 'Which way are you travelling?',
                'hi' => 'किस तरफ़ जाना है?',
                'ne' => 'कता जाने हो?',
                'gu' => 'કઈ તરફ જવું છે?',
            ][$lang] . "\n" . implode("\n", $lines);
        } elseif ($field === 'date') {
            $today = todayISO();
            $near  = [
                0 => ['en' => 'Today', 'hi' => 'आज', 'ne' => 'आज', 'gu' => 'આજે'],
                1 => ['en' => 'Tomorrow', 'hi' => 'कल', 'ne' => 'भोलि', 'gu' => 'કાલે'],
            ];
            for ($i = 0; $i < 7; $i++) {
                $iso   = addDaysISO($today, $i);
                $label = formatDate($iso, 'D, j M');
                $options[] = [
                    'value' => $iso,
                    'label' => isset($near[$i]) ? ($near[$i][$lang] ?? $near[$i]['en']) . ' · ' . $label : $label,
                ];
            }
            $question .= "\n" . [
                'en' => '(another day? just write it: 25 Oct)',
                'hi' => '(दूसरी तारीख? लिख दीजिए: 25 Oct)',
                'ne' => '(अर्को मिति? लेख्नुहोस्: 25 Oct)',
                'gu' => '(બીજી તારીખ? લખો: 25 Oct)',
            ][$lang];
        } elseif ($field === 'seats') {
            $unit = ['en' => ' passenger', 'hi' => ' यात्री', 'ne' => ' जना', 'gu' => ' મુસાફર'][$lang];
            $max  = min(6, BookingService::maxSeatsFor($forSeller));
            if ($plan !== null && (int) ($plan['seatsLeft'] ?? 0) > 0) {
                $max = min($max, (int) $plan['seatsLeft']);
            }
            for ($n = 1; $n <= max(1, $max); $n++) {
                $options[] = ['value' => (string) $n, 'label' => $n . $unit . ($lang === 'en' && $n > 1 ? 's' : '')];
            }
        } else {
            foreach ((array) ($plan['stops'] ?? []) as $s) {
                if (empty($s['ahead']) || trim((string) ($s['name'] ?? '')) === '') {
                    continue;
                }
                $short = trim((string) ($s['short'] ?? $s['name']));
                $code  = trim((string) ($s['code'] ?? ''));
                $time  = trim((string) ($s['time'] ?? ''));
                $ts    = $time !== '' ? strtotime($time) : false;
                $options[] = [
                    'value' => (string) $s['name'],
                    'label' => $short,
                    'desc'  => trim(($code !== '' ? $code . ' · ' : '') . ($ts !== false ? date('g:i A', $ts) : $time), ' ·'),
                ];
            }
            if ($options === []) {
                $options = array_values((array) $base['options']);
            }
            $options = array_slice($options, 0, 10);
        }

        return ['question' => $question, 'options' => $options];
    }

    /**
     * The tappable form of a question: up to three answers are buttons,
     * more is a list. Ids are "shg:<n>" — the same number the plain-text
     * version asks for — so a tap and a typed "2" are one code path.
     *
     * @param list<array<string,mixed>> $options
     * @return array<string,mixed>|null
     */
    private static function choiceUi(string $question, array $options, string $lang): ?array
    {
        if ($options === [] || count($options) > 10) {
            return null;
        }
        if (count($options) <= 3) {
            $buttons = [];
            foreach ($options as $i => $o) {
                $buttons[] = ['id' => 'shg:' . ($i + 1), 'title' => mb_substr((string) ($o['label'] ?? $o['value']), 0, 20)];
            }
            return ['kind' => 'buttons', 'body' => $question, 'buttons' => $buttons];
        }
        $rows = [];
        foreach ($options as $i => $o) {
            $rows[] = [
                'id'    => 'shg:' . ($i + 1),
                'title' => mb_substr((string) ($o['label'] ?? $o['value']), 0, 24),
                'desc'  => (string) ($o['desc'] ?? ''),
            ];
        }
        return [
            'kind'   => 'list',
            'body'   => $question,
            'button' => ['en' => 'Choose', 'hi' => 'चुनिए', 'ne' => 'छान्नुहोस्', 'gu' => 'પસંદ કરો'][$lang] ?? 'Choose',
            'rows'   => $rows,
        ];
    }

    /** Can this booking be paid by UPI right now, and may it be paid on the bus? */
    private static function payWays(): array
    {
        return [
            'cod' => Settings::getBool('allow_cod', true),
            'upi' => trim(Settings::getString('upi_id', '')) !== '',
        ];
    }

    /** The buttons under the summary. */
    private static function confirmButtons(string $lang, bool $forSeller): array
    {
        $t = static fn (array $w): string => $w[$lang] ?? $w['en'];
        $cancel = ['id' => 'shg:cancel', 'title' => $t(['en' => '❌ Cancel', 'hi' => '❌ रद्द', 'ne' => '❌ रद्द', 'gu' => '❌ રદ'])];
        if ($forSeller) {
            return [
                ['id' => 'shg:ho', 'title' => $t(['en' => '✅ Issue ticket', 'hi' => '✅ टिकट काटो', 'ne' => '✅ टिकट काट्ने', 'gu' => '✅ ટિકિટ કાપો'])],
                $cancel,
            ];
        }
        $change = ['id' => 'shg:change', 'title' => $t(['en' => '✏️ Change', 'hi' => '✏️ बदलें', 'ne' => '✏️ बदल्ने', 'gu' => '✏️ બદલો'])];
        $ways = self::payWays();
        if ($ways['cod'] && $ways['upi']) {
            // Four choices: confirmUi() sends these as a list (buttons stop at three).
            return [
                ['id' => 'shg:pay cash', 'title' => $t(['en' => '💵 Pay on the bus', 'hi' => '💵 बस में भुगतान', 'ne' => '💵 बसमा तिर्ने', 'gu' => '💵 બસમાં ચૂકવો'])],
                ['id' => 'shg:pay upi', 'title' => $t(['en' => '📲 Pay by UPI now', 'hi' => '📲 अभी UPI से', 'ne' => '📲 अहिले UPI', 'gu' => '📲 હમણાં UPI'])],
                $change,
                $cancel,
            ];
        }
        return [
            ['id' => 'shg:ho', 'title' => $t(['en' => '✅ Confirm', 'hi' => '✅ बुक करो', 'ne' => '✅ बुक गर्ने', 'gu' => '✅ બુક કરો'])],
            $change,
            $cancel,
        ];
    }

    /**
     * The tappable form of the summary (15 Oct 2026): [Confirm][Change][Cancel]
     * as buttons; with pay-on-bus AND UPI both on that is four choices, so a
     * list (Pay on the bus / Pay by UPI now / Change / Cancel) - the payment
     * choice still closes the sale in one tap.
     *
     * @return array<string,mixed>
     */
    private static function confirmUi(string $body, string $lang, bool $forSeller): array
    {
        $choices = self::confirmButtons($lang, $forSeller);
        if (count($choices) <= 3) {
            return ['kind' => 'buttons', 'body' => $body, 'buttons' => $choices];
        }
        $rows = [];
        foreach ($choices as $c) {
            $rows[] = ['id' => (string) $c['id'], 'title' => mb_substr((string) $c['title'], 0, 24), 'desc' => ''];
        }
        return [
            'kind'   => 'list',
            'body'   => $body,
            'button' => ['en' => '✅ Book · pay', 'hi' => '✅ बुक · भुगतान', 'ne' => '✅ बुक · भुक्तानी', 'gu' => '✅ બુક · ચુકવણી'][$lang] ?? '✅ Book · pay',
            'rows'   => $rows,
        ];
    }

    /** The same choices in words, for a phone that shows no buttons. */
    private static function confirmHelp(string $lang, bool $forSeller): string
    {
        $lines = [self::say($forSeller ? 'confirmSeller' : 'confirmQ', $lang)];
        $ways  = self::payWays();
        if (!$forSeller && $ways['cod'] && $ways['upi']) {
            $lines[] = self::say('payHint', $lang);
        }
        return implode("\n", $lines);
    }

    /** '' | 'cod' | 'upi' — the payment button, or the same thing typed. */
    private static function payChoice(string $text): string
    {
        $t = mb_strtolower(trim((string) preg_replace('/[\s.!,]+/u', ' ', $text)));
        if (in_array($t, ['pay upi', 'upi', 'qr', 'online', 'gpay', 'google pay', 'phonepe', 'phone pe', 'paytm', 'pay now',
                          'upi now', 'अहिले upi', 'अभी upi', 'यूपीआई', 'युपिआई', 'ऑनलाइन', 'अनलाइन'], true)) {
            return 'upi';
        }
        if (in_array($t, ['pay cash', 'cash', 'cod', 'nagad', 'bus ma', 'busma', 'bus ma tirne', 'counter', 'pay on bus',
                          'नगद', 'बसमा', 'बसमा तिर्ने', 'बस में', 'કેશ', 'રોકડ'], true)) {
            return 'cod';
        }
        /* 31 Oct 2026 (passenger sim: "UPI bata tirchu" re-printed the summary and the next "ho" sold the seats as
           pay-on-bus): a short line that only says HOW they will pay is that choice — "UPI bata tirchu", "QR
           pathaunus", "cash ma tirchu", "bus ma dinchu". A line that also changes something is a correction. */
        /* Round 4 (R1 — safety review A1: "upi chaina", "upi wait", "upi chalcha?" made a UPI booking): only a line whose
           EVERY word is a pay / "I will pay" word is the choice; a question is never one. */
        static $payWords = ['upi', 'qr', 'gpay', 'google', 'pay', 'phonepe', 'phone', 'pe', 'paytm', 'online', 'cash', 'nagad', 'nakad', 'cod', 'bus',
            'busma', 'ma', 'me', 'mein', 'mai', 'counter', 'bata', 'se', 'through', 'by', 'via', 'with', 'in', 'on', 'the', 'from', 'le', 'tirchu', 'tirchhu',
            'tirxu', 'tirne', 'tirnchu', 'tirchau', 'dinchu', 'dinchhu', 'dinxu', 'dine', 'dunga', 'dungi', 'denge', 'karunga', 'karungi', 'karenge',
            'garchu', 'garchhu', 'garxu', 'garne', 'payment', 'paisa', 'pathaunus', 'pathaideu', 'pathaidinus', 'pathau', 'link', 'scan', 'code', 'will',
            'i', 'we', 'now', 'ahile', 'abhi', 'aba', 'ho', 'ok', 'okay', 'yes', 'haan', 'ha', 'dai', 'ji', 'sir', 'hajur', 'please', 'plz', 'pls', 'chha',
            'cha', 'hai', 'garidinus', 'नगद', 'बसमा', 'मा', 'तिर्छु', 'दिन्छु', 'दूँगा', 'यूपीआई', 'युपिआई', 'बस', 'में', 'से', 'बाट', 'अहिले', 'अभी'];
        if (str_contains($t, '?') || str_contains($t, '？')
            || array_diff(preg_split('/[^\p{L}\p{M}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [], $payWords) !== []) {
            return '';
        }
        if (mb_strlen($t) <= 40 && preg_match('/(?<![\p{L}])(badal\w*|change|date|miti|naam|name|seat|sit|cancel|hoina|nahi|no|jana|bholi|parsi|kal)(?![\p{L}])|\d/u', $t) !== 1) {
            if (preg_match('/(?<![\p{L}])(upi|qr|gpay|google\s*pay|phonepe|phone\s*pe|paytm|online|यूपीआई|युपिआई)(?![\p{L}])/u', $t) === 1) {
                return 'upi';
            }
            if (preg_match('/(?<![\p{L}])(cash|nagad|nakad|नगद|नकद)(?![\p{L}])/u', $t) === 1
                || preg_match('/(?<![\p{L}])(bus\s*ma|bus\s*me|busma|बसमा|बस\s*में)\s*(tir\w*|de\w*|din\w*|pay\w*|तिर्छु|दिन्छु|दूँगा)/u', $t) === 1) {
                return 'cod';
            }
        }
        return '';
    }

    /**
     * 31 Oct 2026 (passenger sim: "Ram Thapa ra Sita Thapa, phone 9327055511" — the number was silently dropped):
     * a mobile typed beside the names is where the ticket goes (shown on the summary). A seller's passenger number
     * keeps its own question (custPhone).
     */
    private static function noteContact(array &$slots, string $text, string $phoneDigits, ?array $seller): void
    {
        if ($seller !== null) {
            return;
        }
        $typed = (string) (TicketBot::parse($text)['phone'] ?? '');
        $cust = $typed !== '' ? self::readCustomerPhone($typed) : null;
        if ($cust !== null && $cust !== '' && normalisePhone($cust) !== normalisePhone($phoneDigits)) {
            $slots['contact'] = $cust;
        }
    }

    /** The last PNR this chat sold (a few minutes back), for a payment reference typed after the sale. */
    public static function lastSoldPnr(string $phoneDigits): string
    {
        return (string) (self::recentSale($phoneDigits)['pnr'] ?? '');
    }

    /** The language that sale was made in ('' = none remembered): "UTR 4123… bhej diya" is answered in it. */
    public static function lastSoldLang(string $phoneDigits): string
    {
        $l = (string) (self::recentSale($phoneDigits)['lang'] ?? '');
        return in_array($l, ['ne', 'hi', 'en', 'gu'], true) ? $l : '';
    }

    /** Stored berths (L34) as the ticket prints them (F4). */
    private static function printedSeats(string $pnr, array $seats): string
    {
        $seats = array_map('strval', $seats);
        try {
            $row = Database::fetch("SELECT b.booking_mode, l.schedule_id FROM bookings b
                                      LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
                                     WHERE b.pnr = :p LIMIT 1", ['p' => $pnr]);
            if ($row !== null && (int) ($row['schedule_id'] ?? 0) > 0) {
                $out = Seats::displayLabels($seats, Seats::coachForSchedule((int) $row['schedule_id']), (string) ($row['booking_mode'] ?? 'sharing'));
                if ($out !== '') {
                    return $out;
                }
            }
        } catch (Throwable $e) {
            // the stored ids, as before
        }
        return implode(', ', $seats);
    }

    /**
     * A seller's answer to "whose number?": the digits as typed (so +977
     * survives), '' for a walk-in with no phone, or null when it is neither.
     */
    private static function readCustomerPhone(string $text): ?string
    {
        $t = mb_strtolower(trim(TicketBot::asciiDigits($text)));
        if (in_array($t, ['0', '00', 'no', 'none', 'skip', 'chaina', 'chhaina', 'xaina', 'nahi', 'छैन', 'नहीं', 'ना', 'નથી', '-'], true)) {
            return '';
        }
        $digits = preg_replace('/\D/', '', $t) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        // Only a message that IS a number: "2 seat" must not be read as a phone.
        if (preg_match('/^[+\d][\d\s\-]{7,17}$/', $t) !== 1) {
            return null;
        }
        $ten = normalisePhone($digits);
        if ($ten === '' || !Security::isValidPhone($ten, true)) {
            return null;
        }
        return $digits;
    }

    /* ----------------------------------------------------------------- */

    /** The sale itself, after an explicit yes. */
    private static function sell(string $phoneDigits, array $slots, string $lang, string $senderCountry = '', string $pay = '', ?array $seller = null, string $contact = ''): array
    {
        /* The name on the ticket must be a person's name (24 Sep 2026): a
           stop, a yes-word or a number that slipped past the slot logic is
           refused here, before any seat is taken, and asked for again. */
        $paxName = PersonName::clean((string) ($slots['name'] ?? ''));
        if ($paxName === '') {
            $why = PersonName::why((string) ($slots['name'] ?? ''));
            $slots['name'] = '';
            $slots['party'] = [];
            self::save($phoneDigits, $slots, 'name', [], $lang);
            return self::out(self::say('askName', $lang) . ($why !== '' ? ' (' . $why . ')' : ''));
        }
        if ($seller !== null) {
            return self::sellAsSeller($phoneDigits, $slots, $lang, $paxName, $seller);
        }
        // Where the WhatsApp journal stood before this sale (2C-dedupe, below).
        if (!class_exists('Notify')) {
            require_once INCLUDE_PATH . '/notify.php';
        }
        $journalAt = count(Notify::whatsappJournal());
        try {
            $res = QuickTicket::sellCustomer([
                'name'      => $paxName,
                // 15 Oct 2026: a clear name ("Sita Kumari") seats by gender; unsure = none, as before
                'gender'    => (int) ($slots['seats'] ?? 1) <= 1 ? self::guessGender($paxName) : null,
                /* 15 Oct 2026: the booking form's own Mobile box books for that
                   number (the ticket goes there); blank = the sender. */
                'phone'     => $contact !== '' ? $contact : $phoneDigits,
                /* Round 3 (R5): who is asking — the double-tap guard keys on THIS chat for a typed number and never
                   names a PNR that number already holds (another person's booking). */
                'sender'    => $phoneDigits,
                'chatPnrs'  => array_values(array_filter([self::lastSoldPnr($phoneDigits)])),
                /* '' = the office default (pay on the bus when allowed);
                   'upi' = they chose to pay now, so the booking waits for the
                   money and the QR goes back instead of the ticket. */
                'pay'       => $pay,
                /* The country the SENDER's own number carries (+977 / +91 from
                   the webhook) is the truth about where this ticket goes; a
                   country parsed out of the text only fills in when the
                   transport gave none. */
                'country'   => ($contact !== '' && strlen($contact) >= 12) ? (str_starts_with($contact, '977') ? 'NP' : 'IN')
                             : ($senderCountry !== '' ? $senderCountry : (string) ($slots['country'] ?? '')),
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
            // 5 Oct 2026: a sale made inside a WhatsApp chat is a WHATSAPP booking.
            BookingChannel::markWhatsappPnr($pnr, 'sold by the WhatsApp assistant');
            /* The language rides along: after a sale the conversation is
               cleared, so a romanised "naam galat bhayo" would be read as
               English and a Nepali customer would suddenly be answered in
               English about their own ticket. */
            self::rememberSale($phoneDigits, $pnr, $lang);
            /* 15 Oct 2026 (2G): how long the chat sale took and how many
               messages it needed - the WhatsApp Manager averages these. */
            self::auditSale($pnr, $slots);
        }
        // 31 Oct 2026 (passenger sim: the summary said F4, the confirmation L34): the berth as the ticket prints it
        $seats  = self::printedSeats($pnr, (array) ($res['seats'] ?? []));
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
            /* 15 Oct 2026 (2C-dedupe): the confirmation fan-out has usually
               just put the ticket picture into THIS chat already (the Meta
               number, free inside the open window). Sending it again as the
               reply was the same PNG twice - the reply now says "above". */
            $sentAbove = self::ticketSentAbove($phoneDigits, $journalAt);
            $lines[] = '';
            $lines[] = self::say($sentAbove ? 'ticketAbove' : 'ticketBelow', $lang);
            $media   = $sentAbove ? null : Ticket::imageUrl($pnr);
            /* D4-06 (8 Oct 2026): a pay-at-counter ticket is CONFIRMED with
               nothing paid yet. "Safe journey!" alone read as if it were
               settled — say plainly what is owed and when. */
            if (!empty($res['isCod']) && (int) ($res['bookingId'] ?? 0) > 0) {
                $codDue = max(0.0, (float) ($res['total'] ?? 0) - (float) Database::scalar(
                    "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = :b AND status = 'verified'",
                    ['b' => (int) $res['bookingId']],
                    0
                ));
                if ($codDue > 0) {
                    $lines[] = sprintf(self::say('codDue', $lang), inr($codDue), inr($codDue));
                }
            }
            /* 15 Oct 2026 (2C-links): the short View link, the printable PDF
               and a one-tap Share, so the ticket can be opened, printed or
               forwarded to the family without asking the office. */
            $lines[] = '';
            foreach (self::ticketLinks($pnr, (int) ($res['bookingId'] ?? 0), $lang) as $l) {
                $lines[] = $l;
            }
        } else {
            $lines[] = '';
            $lines[] = self::say('payNow', $lang);
            $lines[] = appUrl('pay.php?pnr=' . urlencode($pnr));
            $media   = appUrl('pay-image.php?pnr=' . urlencode($pnr));
        }
        // round 2 (passenger sim: "the ticket comes HERE" while it went to the typed number): say where it goes
        if ($seller === null && $contact !== '' && normalisePhone($contact) !== normalisePhone($phoneDigits)) {
            $lines[] = self::label('ticketTo', $lang) . ': ' . $contact;
        }

        return self::out(implode("\n", $lines), $media);
    }

    /**
     * View / PDF / Share lines for a confirmed ticket. The View link is the
     * short ticket/view.php?code= link (TicketLink) - it always opens the
     * CURRENT ticket - and the keyed picture when that table is not there.
     *
     * @return list<string>
     */
    private static function ticketLinks(string $pnr, int $bookingId, string $lang): array
    {
        if ($pnr === '') {
            return [];
        }
        $view = '';
        try {
            if (!class_exists('TicketLink')) {
                require_once INCLUDE_PATH . '/ticketlink.php';
            }
            $view = $bookingId > 0 && TicketLink::ready() ? TicketLink::forBooking($bookingId) : '';
        } catch (Throwable $e) {
            $view = '';
        }
        if ($view === '') {
            $view = Ticket::imageUrl($pnr);
        }
        $company = trim(Settings::getString('company_name', APP_NAME));
        $share   = '🎫 ' . $company . ' · ' . $pnr . "\n" . $view;
        return [
            '👁 ' . self::label('view', $lang) . ': ' . $view,
            '📄 PDF: ' . Ticket::downloadUrl($pnr),
            '📤 ' . self::label('share', $lang) . ': https://wa.me/?text=' . rawurlencode($share),
        ];
    }

    /**
     * Did this sale's own confirmation already put the ticket picture into
     * this very chat? Only the Meta number's sends count (free window or a
     * Meta template): an AiSensy send lands in a different chat.
     */
    private static function ticketSentAbove(string $who, int $journalFrom): bool
    {
        $tail = substr(preg_replace('/\D/', '', $who) ?? '', -8);
        if (strlen($tail) < 8 || !class_exists('Notify')) {
            return false;
        }
        foreach (array_slice(Notify::whatsappJournal(), max(0, $journalFrom)) as $j) {
            if (!empty($j['ok']) && in_array((string) ($j['reason'] ?? ''), ['meta_free', 'meta_template'], true)
                && substr(preg_replace('/\D/', '', (string) ($j['to'] ?? '')) ?? '', -8) === $tail) {
                return true;
            }
        }
        return false;
    }

    /** One audit row per chat sale: seconds from the first message, messages it took. */
    private static function auditSale(string $pnr, array $slots): void
    {
        $started = (int) ($slots['_started'] ?? 0);
        Logger::audit('booking.wa_sale', 'booking', $pnr, null, [
            'seconds'  => $started > 0 ? max(0, time() - $started) : null,
            // every question this conversation asked was answered once, plus the final yes
            'messages' => max(1, (int) ($slots['_turns'] ?? 0) + 1),
            'combo'    => !empty($slots['_combo']),
        ], 'Sold in the WhatsApp chat');
    }

    /**
     * A seller's order, after their yes: QuickTicket::sell() under their own
     * account - the same sale, ledger and commission as the Quick Ticket
     * screen. The ticket goes to the passenger's number by itself (the
     * booking.approved fan-out); the seller gets the picture back here.
     */
    private static function sellAsSeller(string $phoneDigits, array $slots, string $lang, string $paxName, array $seller): array
    {
        $cust = (string) ($slots['custPhone'] ?? '');
        try {
            $res = QuickTicket::sell([
                'name'       => $paxName,
                'gender'     => (int) ($slots['seats'] ?? 1) <= 1 ? self::guessGender($paxName) : null,
                'phone'      => $cust,
                'country'    => (str_starts_with($cust, '977') && strlen($cust) === 13) ? 'NP' : (string) ($slots['country'] ?? ''),
                'seats'      => (int) ($slots['seats'] ?? 1),
                'direction'  => (string) ($slots['direction'] ?? ''),
                'date'       => (string) ($slots['date'] ?? ''),
                'boarding'   => (string) ($slots['boarding'] ?? ''),
                'pay'        => 'cash',
                'passengers' => self::partyForSale($slots),
                'note'       => 'WhatsApp order',
            ], $seller);
        } catch (Throwable $e) {
            self::clear($phoneDigits);
            if (!($e instanceof RuntimeException)) {
                Logger::exception($e, 'whatsapp');
            }
            return self::out($e instanceof RuntimeException && trim($e->getMessage()) !== '' ? $e->getMessage() : self::say('failed', $lang));
        }
        self::clear($phoneDigits);

        $pnr = (string) ($res['pnr'] ?? '');
        if ($pnr !== '') {
            BookingChannel::markWhatsappPnr($pnr, 'ordered on WhatsApp by ' . trim((string) ($seller['full_name'] ?? 'staff')));
            Logger::audit('booking.wa_order_seller', 'booking', $pnr, null,
                ['seller' => (int) ($seller['id'] ?? 0), 'from' => $phoneDigits,
                 'seconds' => (int) ($slots['_started'] ?? 0) > 0 ? max(0, time() - (int) $slots['_started']) : null,
                 'messages' => max(1, (int) ($slots['_turns'] ?? 0) + 1)], 'Ticket ordered on WhatsApp by a seller');
        }
        $seats = self::printedSeats((string) ($res['pnr'] ?? ''), (array) ($res['seats'] ?? []));
        $lines = [self::say('soldSeller', $lang), ''];
        $lines[] = self::label('pnr', $lang) . ': ' . $pnr;
        $lines[] = self::label('name', $lang) . ': ' . $paxName;
        if ((string) ($res['dateLabel'] ?? '') !== '') { $lines[] = self::label('date', $lang) . ': ' . (string) $res['dateLabel']; }
        if ($seats !== '')                            { $lines[] = self::label('seat', $lang) . ': ' . $seats; }
        if ((string) ($res['totalLabel'] ?? '') !== '') { $lines[] = self::label('total', $lang) . ': ' . (string) $res['totalLabel']; }
        $lines[] = '';
        $lines[] = $cust !== '' ? sprintf(self::say('sentToCust', $lang), $cust) : self::say('forwardIt', $lang);

        $text = implode("\n", $lines);
        return self::out($text, ($res['status'] ?? '') === 'confirmed' ? Ticket::imageUrl($pnr) : null, [
            'kind'    => 'buttons',
            'body'    => $text,
            'buttons' => [['id' => 'shg:ticket book ' . $lang,
                           'title' => ['en' => '🎫 Next ticket', 'hi' => '🎫 अगला टिकट', 'ne' => '🎫 अर्को टिकट', 'gu' => '🎫 બીજી ટિકિટ'][$lang] ?? '🎫 Next ticket']],
        ]);
    }

    /** What the passenger is about to buy. */
    private static function summary(array $plan, array $slots, string $lang, ?array $seller = null): string
    {
        /* plan() hands back clean parts — boardingName without the "@ 23:00"
           and the GPS pair the raw stop label carries. */
        $route = trim((string) ($plan['from'] ?? '') . ' → ' . (string) ($plan['to'] ?? ''), ' →');
        $stop  = trim((string) ($plan['boardingName'] ?? ''));
        $code  = trim((string) ($plan['boardingCode'] ?? ''));
        $time  = trim((string) ($plan['boardingTime'] ?? ''));
        $ts    = $time !== '' ? strtotime($time) : false;
        $timeL = $ts !== false ? date('g:i A', $ts) : $time;
        /* 23 Sep 2026: the berth as the TICKET prints it (LB1, UA3 — Ticket::seatLabel
           uses displayLabel), not the canonical L7 the database keeps. The summary said
           "L7" and the ticket then said "LB1" for the same bed. */
        $seats = Seats::displayLabels(array_map('strval', (array) ($plan['seats'] ?? [])),
            (string) ($plan['coach'] ?? 'sleeper'), (string) ($plan['bookingMode'] ?? 'sharing'));
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
            $lines[] = self::label('name', $lang) . ': ' . (string) ($slots['name'] ?? '') . ((int) ($slots['seats'] ?? 1) <= 1 ? self::genderMark((string) ($slots['name'] ?? '')) : '');
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
        /* 23 Sep 2026: an office offer (Admin → Offers & Discounts, auto-apply)
           is already inside the total — say so, with the office's own title,
           so the passenger sees the saving instead of just a smaller number. */
        $offerCut = (float) ($plan['fare']['couponDiscount'] ?? 0);
        if ($offerCut > 0) {
            $title    = trim((string) ($plan['fare']['offerTitle'] ?? ''));
            $lines[]  = '🎁 ' . self::label('offer', $lang) . ': −' . inr($offerCut) . ($title !== '' ? ' (' . $title . ')' : '');
        }
        if ($total > 0) {
            $lines[] = self::label('total', $lang) . ': ' . inr($total);
        }
        if ($seller !== null) {
            $cust    = (string) ($slots['custPhone'] ?? '');
            $lines[] = self::label('custPhone', $lang) . ': ' . ($cust !== '' ? $cust : self::say('noPhone', $lang));
            $lines[] = self::label('seller', $lang) . ': ' . trim((string) ($seller['full_name'] ?? ''));
        } elseif ((string) ($slots['contact'] ?? '') !== '') {
            $lines[] = self::label('ticketTo', $lang) . ': ' . (string) $slots['contact'];
        }
        $lines[] = '';
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
                'hi' => "कृपया सभी %d नाम एक ही संदेश में भेजिए, उम्र हो तो साथ में।
जैसे: राम बहादुर 35, सीता गुरुङ 30",
                'ne' => "कृपया %d जनाकै नाम एउटै सन्देशमा पठाउनुहोस्, उमेर भए सँगै।
जस्तै: राम बहादुर ३५, सीता गुरुङ ३०",
                'gu' => "કૃપા કરીને બધાં %d નામ એક જ સંદેશમાં મોકલો, ઉંમર હોય તો સાથે.
દા.ત.: રામ બહાદુર 35, સીતા ગુરુંગ 30",
            ],
            'askCustPhone' => [
                'en' => "The passenger's WhatsApp number? The ticket goes there. (No phone: send 0)",
                'hi' => 'यात्री का WhatsApp नंबर? टिकट वहीं जाएगा। (नंबर नहीं है: 0 भेजें)',
                'ne' => 'यात्रुको WhatsApp नम्बर? टिकट त्यहीँ जान्छ। (नम्बर छैन भने: 0 पठाउनुहोस्)',
                'gu' => 'મુસાફરનો WhatsApp નંબર? ટિકિટ ત્યાં જશે. (નંબર નથી: 0 મોકલો)',
            ],
            'askCustPhoneAgain' => [
                'en' => 'That is not a mobile number. Send the 10 digits (Nepal: +977…), or 0 if the passenger has no phone.',
                'hi' => 'यह मोबाइल नंबर नहीं है। 10 अंक भेजें (नेपाल: +977…), या नंबर न हो तो 0।',
                'ne' => 'यो मोबाइल नम्बर होइन। १० अंक पठाउनुहोस् (नेपाल: +977…), वा नम्बर छैन भने 0।',
                'gu' => 'આ મોબાઇલ નંબર નથી. 10 આંકડા મોકલો (નેપાળ: +977…), કે નંબર ન હોય તો 0.',
            ],
            'noPhone' => [
                'en' => 'no phone (walk-in)', 'hi' => 'नंबर नहीं', 'ne' => 'नम्बर छैन', 'gu' => 'નંબર નથી',
            ],
            'confirmSeller' => [
                'en' => 'Issue this ticket? Reply YES, or NO to cancel. Cash is counted in your account.',
                'hi' => 'यह टिकट काट दूँ? हाँ लिखें, या नहीं। नकद आपके खाते में गिना जाएगा।',
                'ne' => 'यो टिकट काटौं? हो लेख्नुहोस्, वा होइन। नगद तपाईंको खातामा गनिन्छ।',
                'gu' => 'આ ટિકિટ કાપું? હા લખો, કે ના. રોકડ તમારા ખાતામાં ગણાશે.',
            ],
            'payHint' => [
                'en' => 'YES = pay on the bus · UPI = pay now and get the QR.',
                'hi' => 'हाँ = बस में भुगतान · UPI लिखें = अभी भुगतान, QR मिलेगा।',
                'ne' => 'हो = बसमा चढ्दा तिर्ने · UPI लेख्नुहोस् = अहिले नै तिर्ने, QR आउँछ।',
                'gu' => 'હા = બસમાં ચૂકવો · UPI લખો = હમણાં ચૂકવો, QR મળશે.',
            ],
            'soldSeller' => [
                'en' => '✅ Ticket issued.', 'hi' => '✅ टिकट कट गया।', 'ne' => '✅ टिकट काटियो।', 'gu' => '✅ ટિકિટ કપાઈ ગઈ.',
            ],
            'sentToCust' => [
                'en' => "The ticket is on its way to the passenger's WhatsApp (%s). The picture is here for you too.",
                'hi' => 'टिकट यात्री के WhatsApp (%s) पर जा रहा है। तस्वीर आपके लिए यहाँ भी है।',
                'ne' => 'टिकट यात्रुको WhatsApp (%s) मा जाँदैछ। तस्बिर तपाईंलाई यहाँ पनि छ।',
                'gu' => 'ટિકિટ મુસાફરના WhatsApp (%s) પર જઈ રહી છે. ફોટો તમારા માટે અહીં પણ છે.',
            ],
            'forwardIt' => [
                'en' => 'No passenger number — please forward this ticket picture to them.',
                'hi' => 'यात्री का नंबर नहीं है — यह टिकट की तस्वीर उन्हें भेज दीजिए।',
                'ne' => 'यात्रुको नम्बर छैन — यो टिकटको तस्बिर उहाँलाई पठाइदिनुहोस्।',
                'gu' => 'મુસાફરનો નંબર નથી — આ ટિકિટનો ફોટો તેમને મોકલી આપો.',
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
                'en' => 'Shall I book it? Reply YES to confirm, CHANGE to change something, NO to cancel.',
                'hi' => 'बुक कर दूँ? हाँ लिखें, कुछ बदलना हो तो "बदल", या नहीं लिखें।',
                'ne' => 'बुक गरौं? हो लेख्नुहोस्, केही बदल्नु परे "बदल", वा होइन लेख्नुहोस्।',
                'gu' => 'બુક કરી દઉં? હા લખો, કંઈ બદલવું હોય તો "બદલો", કે ના લખો.',
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
            'ticketAbove' => [
                'en' => 'Your e-ticket picture is just above. Safe journey!',
                'hi' => 'आपका ई-टिकट ठीक ऊपर भेजा गया है। यात्रा शुभ हो!',
                'ne' => 'तपाईंको ई-टिकट माथि नै पठाइएको छ। शुभ यात्रा!',
                'gu' => 'તમારી ઈ-ટિકિટ ઉપર જ મોકલી છે. શુભ યાત્રા!',
            ],
            /* 15 Oct 2026 — the combined question (2A-hi). The example is a
               line the parser reads as it stands: name, a stop, a day word, a
               count. */
            'comboAsk' => [
                'en' => "To book, send all four in ONE message:\nname, boarding point, date, how many\nExample: Ram, Surat, tomorrow, 2",
                'hi' => "बुक करने के लिए चारों बातें एक ही संदेश में भेजिए:\nनाम, चढ़ने की जगह, तारीख, कितने लोग\nजैसे: Ram, Surat, kal, 2",
                'ne' => "बुक गर्न चारवटै कुरा एउटै सन्देशमा पठाउनुहोस्:\nनाम, चढ्ने ठाउँ, मिति, कति जना\nजस्तै: Ram, Surat, bholi, 2",
                'gu' => "બુક કરવા ચારેય વાત એક જ સંદેશમાં મોકલો:\nનામ, ચઢવાની જગ્યા, તારીખ, કેટલા લોકો\nદા.ત.: Ram, Surat, કાલે, 2",
            ],
            'comboMore' => [
                'en' => 'Please also send, in one message: %s',
                'hi' => 'कृपया यह भी एक ही संदेश में भेजिए: %s',
                'ne' => 'कृपया यो पनि एउटै सन्देशमा पठाउनुहोस्: %s',
                'gu' => 'કૃપા કરીને આ પણ એક જ સંદેશમાં મોકલો: %s',
            ],
            'example' => [
                'en' => 'Example: %s', 'hi' => 'जैसे: %s', 'ne' => 'जस्तै: %s', 'gu' => 'દા.ત.: %s',
            ],
            'comboNow' => [
                'en' => 'Now: %s',
                'hi' => 'अभी: %s',
                'ne' => 'अहिले: %s',
                'gu' => 'હમણાં: %s',
            ],
            'comboChange' => [
                'en' => 'Send only what should change, e.g. tomorrow · 3 · Kamrej · name Hari',
                'hi' => 'सिर्फ़ जो बदलना है वही भेजिए, जैसे: kal · 3 · Kamrej · नाम Hari',
                'ne' => 'बदल्नु पर्ने कुरा मात्र पठाउनुहोस्, जस्तै: bholi · 3 · Kamrej · नाम Hari',
                'gu' => 'જે બદલવું હોય તે જ મોકલો, દા.ત.: કાલે · 3 · Kamrej · નામ Hari',
            ],
            'sameLast' => [
                'en' => '🔁 Last time: %s — tap "Same as last time" and send just the date.',
                'hi' => '🔁 पिछली बार: %s — "पहले जैसा" दबाइए और बस तारीख भेजिए।',
                'ne' => '🔁 पछिल्लो पटक: %s — "पहिले जस्तै" थिच्नुहोस् र मिति मात्र पठाउनुहोस्।',
                'gu' => '🔁 ગઈ વખતે: %s — "પહેલાં જેવું" દબાવો અને માત્ર તારીખ મોકલો.',
            ],
            /* 2E-resume: %1$s PNR, %2$s its state, %3$s the travel day. */
            'dupFound' => [
                'en' => 'This number already has booking %1$s (%2$s) for %3$s.' . "\n" . 'Use that booking, or book another seat?',
                'hi' => 'इस नंबर पर %3$s के लिए बुकिंग %1$s (%2$s) पहले से है।' . "\n" . 'वही बुकिंग लें, या एक और सीट बुक करें?',
                'ne' => 'यो नम्बरमा %3$s को बुकिङ %1$s (%2$s) पहिले नै छ।' . "\n" . 'त्यही बुकिङ लिने, कि अर्को सिट बुक गर्ने?',
                'gu' => 'આ નંબર પર %3$s માટે બુકિંગ %1$s (%2$s) પહેલેથી છે.' . "\n" . 'એ જ બુકિંગ લેવી, કે બીજી સીટ બુક કરવી?',
            ],
            'dupPending' => [
                'en' => 'payment pending', 'hi' => 'भुगतान बाकी', 'ne' => 'भुक्तानी बाँकी', 'gu' => 'ચુકવણી બાકી',
            ],
            'dupConfirmed' => [
                'en' => 'confirmed', 'hi' => 'कन्फर्म', 'ne' => 'पक्का', 'gu' => 'કન્ફર્મ',
            ],
            'dupHere' => [
                'en' => 'Here is your booking:', 'hi' => 'यह रही आपकी बुकिंग:', 'ne' => 'तपाईंको बुकिङ यो हो:', 'gu' => 'આ રહી તમારી બુકિંગ:',
            ],
            'dupGone' => [
                'en' => 'That booking is no longer open. Write "ticket" to book again.',
                'hi' => 'वह बुकिंग अब खुली नहीं है। फिर से बुक करने के लिए "ticket" लिखें।',
                'ne' => 'त्यो बुकिङ अब खुला छैन। फेरि बुक गर्न "ticket" लेख्नुहोस्।',
                'gu' => 'એ બુકિંગ હવે ખુલ્લી નથી. ફરી બુક કરવા "ticket" લખો.',
            ],
            /* Two %s, both the amount: the line is bilingual on purpose so the
               crew reading it over the passenger's shoulder understands it too. */
            'codDue' => [
                'en' => 'Pay %s in cash at the counter or when boarding, before departure. · काउन्टरमा / चढ्दा %s नगद तिर्नुहोस्',
                'hi' => 'काउंटर पर या बस में चढ़ते समय %s नकद दीजिए, बस छूटने से पहले। · Pay %s in cash before departure',
                'ne' => 'काउन्टरमा / चढ्दा %s नगद तिर्नुहोस् · Pay %s in cash before departure',
                'gu' => 'કાઉન્ટર પર કે બસમાં ચઢતી વખતે %s રોકડા ચૂકવો, ઉપડતા પહેલાં. · Pay %s in cash before departure',
            ],
            'payNow' => [
                'en' => 'Scan the QR above to pay, or tap here. The ticket arrives here as soon as the payment is checked.',
                'hi' => 'ऊपर का QR स्कैन करके भुगतान करें, या यहाँ दबाएँ। भुगतान जाँचते ही टिकट यहीं आ जाएगा।',
                'ne' => 'माथिको QR स्क्यान गरेर तिर्नुहोस्, वा यहाँ थिच्नुहोस्। भुक्तानी जाँच भएपछि टिकट यहीँ आउँछ।',
                'gu' => 'ઉપરનો QR સ્કેન કરીને ચૂકવો, કે અહીં દબાવો. ચુકવણી તપાસતાં જ ટિકિટ અહીં આવશે.',
            ],
            // round 4 (R1 + R2): under a payment answer while the summary still waits for its yes
            'summaryWaits' => [
                'en' => 'ℹ️ The booking shown above is NOT made yet. Reply *yes* to book it (pay on the bus), *UPI* to pay now, or *no* to drop it.',
                'hi' => 'ℹ️ ऊपर दिखाई बुकिंग अभी नहीं बनी है। बुक करनी हो तो *ha* (बस में भुगतान), अभी भुगतान के लिए *UPI*, या छोड़ने के लिए *no* लिखिए।',
                'ne' => 'ℹ️ माथि देखाइएको बुकिङ अझै बनेको छैन। बुक गर्न *ho* (बसमा तिर्ने), अहिले तिर्न *UPI*, वा छोड्न *no* लेख्नुहोस्।',
                'gu' => 'ℹ️ ઉપર બતાવેલું બુકિંગ હજી થયું નથી. બુક કરવા *ha* (બસમાં ચૂકવણી), હમણાં ચૂકવવા *UPI*, અથવા છોડવા *no* લખો.',
            ],
            // round 4 (R1): a fresh line that says "not now / later / don't book" — nothing is started or kept
            'heldFresh' => [
                'en' => 'OK — nothing is booked. When you want the ticket, send the day, the pickup and how many seats again.',
                'hi' => 'ठीक है — कुछ बुक नहीं हुआ। जब टिकट चाहिए, दिन, चढ़ने की जगह और कितनी सीट फिर से भेजिए।',
                'ne' => 'ठिक छ — केही बुक भएको छैन। टिकट चाहिँदा मिति, चढ्ने ठाउँ र कति सिट फेरि पठाउनुहोस्।',
                'gu' => 'બરાબર — કંઈ બુક થયું નથી. ટિકિટ જોઈએ ત્યારે દિવસ, ચઢવાની જગ્યા અને કેટલી સીટ ફરી મોકલો.',
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
            'offer' => ['en' => 'Offer',      'hi' => 'ऑफ़र छूट',   'ne' => 'अफर छुट',  'gu' => 'ઑફર છૂટ'],
            'bus'   => ['en' => 'Bus',        'hi' => 'बस',        'ne' => 'बस',      'gu' => 'બસ'],
            'custPhone' => ['en' => 'Passenger mobile', 'hi' => 'यात्री मोबाइल', 'ne' => 'यात्रु मोबाइल', 'gu' => 'મુસાફર મોબાઇલ'],
            'ticketTo'  => ['en' => '📲 Ticket goes to', 'hi' => '📲 टिकट इस नंबर पर', 'ne' => '📲 टिकट जाने नम्बर', 'gu' => '📲 ટિકિટ આ નંબર પર'],
            'seller'    => ['en' => 'Sold by', 'hi' => 'बेचने वाला', 'ne' => 'बिक्रेता', 'gu' => 'વેચનાર'],
            'view'      => ['en' => 'View ticket', 'hi' => 'टिकट देखें', 'ne' => 'टिकट हेर्नुहोस्', 'gu' => 'ટિકિટ જુઓ'],
            'share'     => ['en' => 'Share', 'hi' => 'शेयर करें', 'ne' => 'सेयर गर्नुहोस्', 'gu' => 'શેર કરો'],
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

    /**
     * The name inside a sentence about it (31 Oct 2026 round 2, passenger sim: "mero naam Sunil Kumar ho" was sold as
     * the passenger "mero naam Sunil Kumar ho"): "mero naam X ho", "mera naam X hai", "my name is X", "naam X ho",
     * "X ho", "ma X hu", "I am X" → "X". Anything else comes back as it was.
     */
    private static function unwrapName(string $t): string
    {
        $s = trim($t);
        $s = (string) preg_replace('/^\s*(?:mero|mera|meri|my|hamro|मेरो|मेरा)\s+(?=(?:naam|nam|name|नाम|નામ)(?![\p{L}\p{M}]))/iu', '', $s);
        $s = (string) preg_replace('/^\s*(?:naam|nam|name|नाम|નામ)(?![\p{L}\p{M}])\s*(?:is\s+)?[:\-]?\s*/iu', '', $s);
        if (preg_match('/\s(?:hu|hun|hoon|hoo|हुँ|हूँ)\s*[.!।]*\s*$/iu', ' ' . $s) === 1) {
            $s = (string) preg_replace('/^\s*(?:ma|म|main|mai|मैं)\s+/iu', '', $s);
        }
        $s = (string) preg_replace('/^\s*(?:i\s*am|i\'m|im)\s+/iu', '', $s);
        $s = (string) preg_replace('/\s+(?:ho|hun|hu|hoon|hai|hain|ho\s*ni|हो|हुँ|हूँ|है)\s*[.!।🙏]*\s*$/iu', '', $s);
        return trim($s) !== '' ? trim($s) : trim($t);
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
                if (!class_exists('TicketSync')) { require_once INCLUDE_PATH . '/ticketsync.php'; }
                // One hook (1 Oct 2026): new QR + REV n, PNG redrawn, audited.
                // notify=false — the reply below carries the corrected picture.
                TicketSync::afterBookingChange($bid, 'passenger', ['name' => $new], [
                    'notify' => false,
                    'actor'  => 'whatsapp:' . $who,
                    'reason' => 'Name corrected in the WhatsApp chat right after the sale',
                ]);
            } catch (Throwable $e) {
                Logger::error('Ticket sync after WhatsApp rename failed: ' . $e->getMessage(),
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
    /** 'Female' / 'Male' when the name is clear (NameGuess::AUTO), else null.
        Lone travellers only: a family sharing a cabin is never split by a guess (the shared-cabin lock). */
    private static function guessGender(string $name): ?string
    {
        require_once INCLUDE_PATH . '/nameguess.php';
        return NameGuess::confident($name);
    }

    /** The ♀ / ♂ shown beside a name in the summary (the guess the sale will use). */
    private static function genderMark(string $name): string
    {
        $g = self::guessGender($name);
        return $g === 'Female' ? ' ♀' : ($g === 'Male' ? ' ♂' : '');
    }

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

    public static function isYes(string $t): bool
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
        /* Round 4 (31 Oct 2026, owner's rule R1 — safety review A1: "ok wait", "ho tara date galat cha", "thik cha tara
           bholi ko garnu", "ok soch ke batata hu", "ho ki?" each SOLD the seat, because any line that merely STARTED with
           a yes-word was a yes): the yes is the WHOLE line — yes-words, and the polite / "issue it" words around them
           ("ho dai", "ok sir", "haan ji kar do", "thik cha book garnus"), nothing else. A question mark is never a yes,
           and neither is laughter ("ha ha", "haha"). */
        if ($t === '' || str_contains($t, '?') || str_contains($t, '？')) {
            return false;
        }
        $yes = ['yes', 'y', 'ok', 'okay', 'oke', 'okk', 'sure', 'confirm', 'confirmed', 'done', 'go', 'ho', 'hoo', 'hos', 'hunchha', 'hunxa', 'huncha',
            'hunca', 'hajur', 'hajoor', 'thik', 'thikcha', 'theek', 'thek', 'tik', 'tikcha', 'haa', 'han', 'ha', 'haan', 'haanji', 'hanji', 'sahi', 'chahiyo',
            'pakka', 'book', 'kaat', 'kata', 'katnus', 'katdinus', 'katidinus', 'हो', 'हजुर', 'हाँ', 'हा', 'ठीक', 'ठिक', 'हां', 'हुन्छ', 'पक्का', 'काट',
            'काट्नुहोस्', 'કન્ફર્મ', 'હા', 'બરાબર', 'હોવ', 'ઓકે'];
        $polite = ['cha', 'chha', 'xa', 'hai', 'ji', 'jee', 'dai', 'didi', 'bhai', 'sir', 'madam', 'please', 'plz', 'pls', 'ni', 'la', 'ta', 'it', 'now',
            'abhi', 'ahile', 'aba', 'malai', 'book', 'booking', 'ticket', 'kaat', 'kata', 'katnus', 'katidinus', 'katdinus', 'kaatdinus', 'gara', 'garnus',
            'garidinus', 'gardinus', 'kar', 'karo', 'kardo', 'kardijiye', 'kijiye', 'do', 'dijiye', 'deu', 'dinus', 'final', 'this', 'one', 'yehi', 'yahi',
            'yo', 'yei', 'छ', 'है', 'जी', 'दाइ', 'सर', 'कृपया', 'बुक', 'टिकट', 'काट', 'काट्नुहोस्', 'गर्नुहोस्', 'करो', 'कर', 'दो', 'दीजिए', 'छे', 'જી',
            'गर्ने', 'गर्नुस्', 'करें', 'कीजिए', 'काट्ने', 'काटो', 'दिनुस्', 'दिनुहोस्', 'બુક', 'કરો'];
        $words = preg_split('/[^\p{L}\p{M}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words === [] || preg_match('/^(?:h+a+\s*){2,}$|^(?:ha){2,}h?$/u', $t) === 1) {
            return false;
        }
        $saidYes = false;
        foreach ($words as $w) {
            if (in_array($w, $yes, true)) {
                $saidYes = true;
            } elseif (!in_array($w, $polite, true)) {
                return false;                     // any other word: a correction, a pause or a sentence — never the yes
            }
        }
        return $saidYes;
    }

    public static function isNo(string $t): bool
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

    public static function isCancel(string $t): bool
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
        /* 31 Oct 2026 round 2 (passenger sim: "bholi 1 jana Surat bata, ticket 9327055512 ma pathaunu" was parked as a
           draft and the number lost): "ticket <mobile> ma pathaunu" says WHERE the new ticket goes, like "phone <mobile>"
           — it is not a resend of an old one. */
        $t = trim((string) preg_replace('/(?:ticket|tikat|tiket|टिकट)?\s*(?:yo\s*)?(?:number\s*)?\+?\d[\d\s-]{8,14}\d\s*(?:ma|मा|pe|par|पर|me|में)\s*(?:pathau\w*|pathai\w*|pathae\w*|bhej\w*|send\w*|पठाउ\S*|पठाइ\S*|भेज\S*)/u', ' ', $t));
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
    /** Round 4: a day written as a date ("2 Nov", "1/11", "15 tarik") — the one way "today" may re-date a summary. */
    private static function writtenDate(string $text): bool
    {
        $l = mb_strtolower(TicketBot::asciiDigits($text));
        return preg_match('/\d{1,2}\s*[\/.\-]\s*\d{1,2}|\d{1,2}\s*(?:st|nd|rd|th)?\s*(?:jan\w*|feb\w*|mar\w*|apr\w*|may|jun\w*|jul\w*|aug\w*|sep\w*|oct\w*|nov\w*|dec\w*|tarik\w*|tarikh\w*|gate|गते|तारिख)|(?:jan\w*|feb\w*|mar\w*|apr\w*|may|jun\w*|jul\w*|aug\w*|sep\w*|oct\w*|nov\w*|dec\w*)\s*\d{1,2}/u', $l) === 1;
    }

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
        /* 15 Oct 2026 (2G): when the conversation began and how many answers
           it has needed - read back by auditSale() at the sale. */
        if ((int) ($slots['_started'] ?? 0) <= 0) {
            $slots['_started'] = time();
        }
        $slots['_turns'] = (int) ($slots['_turns'] ?? 0) + 1;
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

    /**
     * @param array<string,mixed>|null $ui tappable answers for a transport that can show them
     * @return array{text: string, media: ?string, ui: ?array}
     */
    private static function out(string $text, ?string $media = null, ?array $ui = null): array
    {
        return ['text' => $text, 'media' => $media, 'ui' => $ui];
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

            /* 15 Oct 2026 (2A-returning): `bookings` has no full_name column -
               the name lives on the manifest - so this query always threw and
               no returning passenger was ever greeted by name. The lead
               traveller of their newest real trip. */
            $prev = (string) Database::scalar(
                "SELECT bp.full_name
                   FROM booking_passengers bp
                   JOIN bookings b ON b.id = bp.booking_id
                  WHERE b.contact_phone = :p AND b.status IN ('confirmed','completed')
                  ORDER BY b.id DESC, bp.is_primary DESC, bp.id ASC
                  LIMIT 1",
                ['p' => $who],
                ''
            );
            return PersonName::clean($prev) !== '' ? self::firstName($prev) : '';
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
