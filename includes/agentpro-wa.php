<?php
/**
 * =====================================================================
 *  includes/agentpro-wa.php — the WhatsApp desk for agents and staff
 *  (Oct 2026). Nepali, Hindi and English.
 *
 *  Owner: "har ek kura WhatsApp bata possible hos — statement, details,
 *  sabko name number; ticketing agent le afno code OTP le register,
 *  tespachi code dine bas ticket katne; ticket har kura edit WhatsApp
 *  bata; AI dhilo bhaye ni sahi ticket jaos."
 *
 *  ORDER OF WORK FOR ONE MESSAGE
 *  -----------------------------
 *   1. "JOIN 482913"  — an applicant verifying their WhatsApp (join form)
 *   2. who is this?   — a linked number (ap_wa_links), or a staff member's
 *                       registered mobile; anyone else is a passenger
 *   3. a pending "ho" — the one action this number was asked to confirm
 *   4. instant commands, no AI: LINK + OTP, balance, statement, withdraw,
 *      pay in, ID card, visiting card, my details, directory, office, CIN,
 *      buses, today's report, passengers, a PNR (status / edit / cancel /
 *      resend), and a ticket sale ("2 seat bholi Mehsana, Ram 98…")
 *   5. anything else from a member → the AI on this VPS (ApBrain) reads it
 *      into one of the actions above, or answers a question. Slow on CPU:
 *      the sender gets "⏳" at once.
 *
 *  A passenger's message returns null here (except JOIN / agent sign-up),
 *  so the existing bot (WaBooking, PNR status, …) answers exactly as
 *  before. When the VPS AI is on, customerTurn() replaces the cloud model
 *  for passengers' questions (second hook in WaBot).
 *
 *  SAFETY: money and tickets only ever move after a "ho" in a LATER
 *  message, and every action is the same function the admin screens use
 *  (QuickTicket::sell, AgentWallet::requestPayout, ApEdit, AiTools).
 *
 *  31 Oct 2026 (idc-learn, owner: "training lidai fast hudai jaos"): the
 *  desk learns from examples (ApLearn, includes/agentpro-learn.php) — a
 *  typed command it knew, and an AI reading the member confirmed with
 *  "ho", are kept (normalised, no one's details); words confirmed twice
 *  skip the model next time (the handler and its "ho" stay the same).
 *  New: "form" (my filled form) and, for the office, "id <code | phone |
 *  name>" (anyone's ID card to forward).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/agentpro.php';
require_once __DIR__ . '/agentpro-learn.php';

final class ApWa
{
    /** A pending "ho" expires after this long. */
    private const PENDING_TTL = 900;

    /** Roles that may sell / change tickets from WhatsApp (AiTools' own list). */
    private const SELLERS = ['agent', 'counter', 'manager', 'superadmin'];

    /** Roles that see the whole office: every booking, every member's number. */
    private const OFFICE = ['manager', 'superadmin'];

    /* Round 4 (R1): a typed office DECLINE with its request number ("reject 12 …", "jama no 12 bank ma nahi aayo") is itself the
       decision — its "nahi" is the reason, not a pause. ("jama 700 UTR … abhi nahi" is no request number: it is held.) */
    private const NEG_DECISION = '/^\s*(?:(?:reject|rejct|rejet|rject|decline|declin|wd\s*reject)\s*#?\d{1,9}|(?:jama|jamma|deposit|dep)\s*(?:no|reject|decline|hoina)\s*#?\d{1,9})(?!\d)/iu';
    private const YES = '/^(ho|hoo|ha+|haan|han|yes|y|yeah|ok|okay|okk|confirm|confirmed|done|thik\s*cha|thik\s*xa|theek\s*hai|hunchha|huncha|hunxa|हो|हुन्छ|हाँ|हां|ठीक\s*छ|ठिक\s*छ|ठीक\s*है|पक्का|कन्फर्म)[\s.!।]*$/iu';
    /* 31 Oct 2026 (desk sims: "nahi" / "pardaina" / "mat karo" became the passenger's name and the quote stayed
       ready for a "ha"): romanised Hindi and Nepali refusals are a NO, the same as "no" and "नहीं". */
    private const NO  = '/^(?:no|nah|n|na|naa|cancel|stop|chhod|chhodnu|chodo|chhodo|nai|nahi|nahin|nahii|nhi|nahi\s*chahiye|nahi\s*chaiye|nahin\s*chahiye|mat|mat\s*karo|mat\s*kar|rehne\s*do|rahne\s*do|rehne\s*dijiye|hoina|hoena|hoeina|hoina\s*hai|pardaina|pardain|pardena|chahidaina|chaidaina|chaindaina|chahindaina|chahiyena|hudaina|hunna|bhayena|bhaena|parena|नाइ|होइन|रद्द|छोड|नहीं|नही|मत|मत\s*करो|नहीं\s*चाहिए|रहने\s*दो|पर्दैन|चाहिँदैन|चाहिदैन|हुँदैन|भएन)(?:\s+(?:dai|bhai|sir|ji|hajur|जी))?[\s.!।]*$/iu';

    /** The numbered menu (help): a digit alone runs the same command as its word. */
    private const MENU = [1 => 'ticket', 2 => 'tickets', 3 => 'balance', 4 => 'statement', 5 => 'withdraw', 6 => 'portal',
                          7 => 'id card', 8 => 'aaja', 9 => 'office', 0 => 'admin'];

    /** What the typed command just run meant (ApLearn, source "parser"); null = nothing worth learning. */
    private static ?array $cmdIntent = null;
    /** Round 4 (R1): this turn's line says no / not now / wait (reply()) — no money route is opened for it. */
    private static bool $holdLine = false;

    /**
     * While an AI / learnt reading is acted on (or its booking questions go on):
     * the normalised words + intent that ride along on the pending action, so
     * its "ho" teaches ApLearn ("confirmed").
     */
    private static ?array $learnTag = null;

    /** Seconds one WhatsApp turn may take in all, model included (owner: "up to about a minute is fine"). */
    public static function turnBudget(): int
    {
        return max(15, min(120, Settings::getInt('ap_wa_turn_budget', 60)));
    }

    /** Devanagari digits → ASCII, spaces kept. */
    private static function asciiDigits(string $s): string
    {
        return strtr($s, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    }

    /* =================================================================
     *  Entry points
     * ================================================================= */

    /**
     * WaBot hook #1 — first look at every inbound message.
     *
     * @return array{text: string, media?: ?string}|null null = not ours, the existing bot answers
     */
    public static function reply(string $fromRaw, string $body, string $kind = 'text'): ?array
    {
        if (!AgentPro::available() || !Settings::getBool('ap_wa_on', true)) {
            return null;
        }
        $text  = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        $phone = normalisePhone($fromRaw);
        self::$holdLine = false;
        if ($phone === '' || $text === '') {
            return null;
        }
        try {
            // 1. An applicant verifying their WhatsApp from the join form.
            if (preg_match('/^JOIN[\s:#-]*(\d{6})$/i', $text, $m) === 1) {
                return self::joinVerify($fromRaw, $m[1], $text);
            }
            $member = self::memberFor($phone);
            $state  = self::state($phone);
            $lang   = self::lang($phone, $text, $member, $state);

            // A link in progress: the OTP reply, or LINK itself.
            if (($state['pending']['kind'] ?? '') === 'link') {
                if (preg_match('/^\d{6}$/', ApDocRead_digits($text)) === 1) {
                    return self::linkVerify($phone, ApDocRead_digits($text), $state['pending'], $lang);
                }
            }
            /* 31 Oct 2026 (passenger sim: "Jodhpur bata bus cha?" / "register garnu paryo ticket ko lagi" got "that
               code matched no account"): LINK only as a whole word followed by a code or a mobile number. */
            if (preg_match('/^(?:link|register|regi|jod|jodnu|जोड|दर्ता|मेरो\s*कोड|my\s*code)(?![\p{L}\p{M}])\s*[:#-]?\s*((?:shg[\s-]*(?:[a-z][\s-]*)?)?\d{1,5}|\+?\d[\d\s-]{7,16}\d)\s*$/iu', $text, $m) === 1) {
                return self::linkStart($phone, trim($m[1]), $lang);
            }

            // 30 Oct 2026: a number several IDs share and none picked yet — "which ID?" (once; remembered).
            if ($member === null && self::pickOn() && !self::linked($phone)) {
                $cands = self::sharedCandidates($phone);
                if (count($cands) > 1) {
                    if (!Security::rateLimit('ap_wa', $phone, 90, 3600)) {
                        return self::out(self::t('slow_down', $lang));
                    }
                    return self::pickTurn($phone, $text, $cands, $state, $lang);
                }
            }

            if ($member === null) {
                // Somebody who wants to become an agent.
                if (preg_match('/^(join|agent\s*(?:banne|banna|bannu|bano|apply|form)|apply|एजेन्ट\s*बन्ने|एजेंट\s*बनना)\b/iu', $text) === 1) {
                    return self::out(self::t('join_link', $lang, ['link' => appUrl('join.php')]));
                }
                return null;                              // a passenger: the existing bot answers
            }

            if (!Security::rateLimit('ap_wa', $phone, 90, 3600)) {
                return self::out(self::t('slow_down', $lang));
            }
            $turn = self::bumpTurn($phone);
            self::touchLink($phone);
            $ctx  = self::ctx($member, $phone, $text, $turn);

            /* Round 3 (agent sim: "maile 2000 jama gareko chu" asked for the UTR, and the answer "UTR 412345670006" got the
               menu): the reference the desk itself asked for becomes the PREVIEW of that payment (filed on "ho"). Anything
               else simply goes on as a new message. */
            if ((string) ($state['pending']['kind'] ?? '') === 'pay_need') {
                require_once __DIR__ . '/wapayproof.php';
                $askedRef = WaPayProof::refFrom($text, $phone);
                $askedAmt = (float) ($state['pending']['amount'] ?? 0);
                self::clearPending($phone);
                $state['pending'] = null;
                $state['raw'] = '';
                require_once __DIR__ . '/ticketbot.php';
                if ($askedRef !== '' && $askedAmt > 0 && self::amountIn(mb_strtolower(self::asciiDigits($text)), $askedRef) === ''
                    && !TicketBot::cmdHoldOrNo($text)) {                 // round 4 (R1): "UTR … abhi nahi" previews nothing
                    return self::payIn($member, 'jama ' . (int) $askedAmt . ' UTR ' . $askedRef, $lang, $ctx);
                }
            }

            // 3. The one thing this number was asked to confirm.
            if (!empty($state['pending'])) {
                $p = $state['pending'];
                // 30 Oct 2026 (owner's chat: "Ok yeti ho ticket deu" became the passenger "Yeti Deu"): on a
                // complete sale quote, a sentence that only says "ok, this one, give the ticket" IS the ho.
                $yes = preg_match(self::YES, $text) === 1
                    || (($p['kind'] ?? '') === 'sale' && self::yesPhrase($text) && self::saleComplete((array) ($p['sale'] ?? [])));
                require_once __DIR__ . '/ticketbot.php';
                if ($p['kind'] === 'done') {
                    // A CONFIRM again after the sale / payment went through: say what happened, do nothing.
                    if ($yes) {
                        return self::out(self::t('already_done', $lang) . "\n\n" . (string) ($p['text'] ?? ''), $p['media'] ?? null);
                    }
                    if (preg_match(self::NO, $text) === 1) {
                        self::clearPending($phone);
                        return self::out(self::t('done_no_undo', $lang));
                    }
                } elseif (!$yes && !in_array((string) $p['kind'], ['link', 'pick'], true)
                    && preg_match('/\bSHG-[A-Z0-9]+-[A-Z0-9]+/i', $text) !== 1
                    // a typed office command with a request number ("jama no 12 bank ma nahi aayo") runs as itself
                    && preg_match(self::NEG_DECISION, self::asciiDigits($text)) !== 1
                    && TicketBot::holdOrNo($text)) {
                    /* Round 3 (31 Oct 2026, owner's rule R1 — safety review #1: "abhi nahi", "ahile hoina", "ruko abhi",
                       "ahile nakaat", "wait", "abhi mat kaato, customer se puchh ke batata hu" RE-DATED the waiting quote
                       to today or renamed its passenger "Ruko" / "Wait", and a later "ho" SOLD it): a "no" or a "not now"
                       anywhere in the line drops whatever waits for a "ho" — a quote, a money preview, a change. */
                    self::clearPending($phone);
                    return self::out(self::t($p['kind'] === 'sale' || $p['kind'] === 'book' ? 'held_sale' : 'held', $lang));
                } elseif ($p['kind'] === 'book' && preg_match(self::NO, $text) !== 1) {
                    // Mid-questions an "ok" is no answer: the question is asked again (a command leaves the booking).
                    // An AI-read booking keeps its learn tag through the questions, so its final "ho" still teaches.
                    if (($r = self::withLearnTag($p['learn'] ?? null, fn() => self::bookStep($p, $text, $member, $ctx, $lang))) !== null) {
                        return $r;
                    }
                } elseif ($yes && ($p['kind'] ?? '') === 'sale' && (string) ($p['sale']['ask'] ?? '') !== '') {
                    /* 31 Oct 2026 (usability leftovers a + f): the desk asked for one value ("kun miti?", the added
                       traveller's name) — a yes is not that value. The added name is always needed first; for a part
                       said to be wrong the yes is asked once more, then the quote as shown stands for the NEXT yes. */
                    $sale = (array) $p['sale'];
                    $ask = (string) $sale['ask'];
                    if ($ask !== 'names') {
                        unset($sale['ask']);
                        self::setPending($phone, 'sale', ['sale' => $sale] + (is_array($p['learn'] ?? null) ? ['learn' => $p['learn']] : []));
                    }
                    return self::out(self::t($ask === 'names' ? 'sale_names_first' : 'sale_ask_first', $lang, ['q' => self::askLine($sale, $ask, $lang)]));
                } elseif ($yes) {
                    if (!self::claimPending($phone, (string) $state['raw'])) {
                        return self::out(self::t('nothing_pending', $lang));     // the other CONFIRM took it
                    }
                    // 31 Oct 2026: the member confirmed what the AI (or a learnt example) read — that reading is now an example
                    if (is_array($p['learn'] ?? null) && is_string($p['learn']['n'] ?? null) && is_array($p['learn']['i'] ?? null)) {
                        ApLearn::record($p['learn']['n'], $p['learn']['i'], 'confirmed');
                    }
                    return self::confirm($p, $member, $ctx, $lang);
                } elseif (preg_match(self::NO, $text) === 1) {
                    self::clearPending($phone);
                    return self::out(self::t('cancelled', $lang));
                } elseif ($p['kind'] === 'sale'
                    && ($r = self::withLearnTag($p['learn'] ?? null, fn() => self::saleAmend($p, $text, $member, $ctx, $lang))) !== null) {
                    return $r;
                }
            } elseif (preg_match(self::YES, $text) === 1 || preg_match(self::NO, $text) === 1) {
                // 31 Oct 2026 (owner desk sim: a lone "ho" got the whole menu): nothing is waiting — say so, do nothing.
                return self::out(self::t('nothing_pending', $lang));
            }

            /* Round 4 (31 Oct 2026, owner's rule R1 — safety review A4: with nothing waiting, "SHG-… cancel nagarnu hai" /
               "don't cancel SHG-…" made a cancel preview that "ho" carried out with a refund; "SHG-… phone 98… nahi" changed
               the phone; "withdraw 500 abhi nahi" / "jama 700 UTR … abhi nahi" filed money; "SHG-… cancel mat karna" opened
               an office cancel request; "… nakaatnu" / "ticket mat kaatna" / "wait garnu" / "chaidaina" sale lines were
               quoted and sold): a line that says no / not now / wait never STARTS an action. A PNR line does nothing at
               all; any other line may still answer (a report, a balance) but whatever preview it would open is dropped,
               and the AI / learnt fast path never reads it. A typed office decision ("jama no 12 bank ma nahi aayo") is
               itself the decision and runs as typed. */
            require_once __DIR__ . '/ticketbot.php';
            $holdLine = TicketBot::cmdHoldOrNo($text)
                && preg_match(self::NEG_DECISION, self::asciiDigits($text)) !== 1;
            if ($holdLine && preg_match('/\bSHG-\d{4}-\d{3,6}\b/i', $text, $hm) === 1) {
                if (!empty($state['pending']) && !in_array((string) ($state['pending']['kind'] ?? ''), ['link', 'pick'], true)) {
                    self::clearPending($phone);
                }
                return self::out(self::t('held_cmd', $lang, ['what' => ' (' . strtoupper($hm[0]) . ')']));
            }
            self::$holdLine = $holdLine;
            $pendBefore = $holdLine ? (string) Database::scalar('SELECT pending FROM ap_wa_state WHERE phone = :p', ['p' => $phone], '') : '';

            // 4. Instant commands. What one meant is kept as an example (ApLearn "parser": normalised, no one's details).
            self::$cmdIntent = null;
            $r = self::command($text, $member, $ctx, $lang);
            if ($holdLine) {
                $pendAfter = (string) Database::scalar('SELECT pending FROM ap_wa_state WHERE phone = :p', ['p' => $phone], '');
                if ($pendAfter !== '' && $pendAfter !== $pendBefore) {
                    self::clearPending($phone);                       // the preview this line opened is never left for a "ho"
                    return self::out(self::t('held_cmd', $lang, ['what' => '']));
                }
                if ($r === null) {
                    return self::out(self::t('held_cmd', $lang, ['what' => '']));
                }
                self::$cmdIntent = null;                              // a refused line teaches nothing
            }
            if ($r !== null) {
                if (self::$cmdIntent !== null) {
                    ApLearn::record($text, self::$cmdIntent, 'parser');
                }
                /* 31 Oct 2026 (counter sim: a quote stayed live under "aaja" / "balance" and a later "ok" sold it;
                   a "ho" after other commands replayed an old sale): another command answered, so whatever was
                   waiting for a "ho" (a quote, a preview, a finished action) is dropped. A command that set its
                   own new pending (a new quote / preview) is left alone. */
                $was = (string) ($state['pending']['kind'] ?? '');
                if ($was !== '' && !in_array($was, ['book', 'link', 'pick'], true)) {
                    if ((string) Database::scalar('SELECT pending FROM ap_wa_state WHERE phone = :p', ['p' => $phone], '') === (string) $state['raw']) {
                        self::clearPending($phone);
                        if ($was !== 'done') {
                            $r['text'] = (string) $r['text'] . "\n\n" . self::t('pending_dropped', $lang);
                        }
                    } elseif ($was !== 'done') {
                        /* round 3 (agent sim: "jama 700 UTR …" then "maile 800 tireko chu, UTR …" — the ₹700 preview was
                           silently replaced and "ho" filed only ₹800): a NEW preview replacing one that waited says so. */
                        $r['text'] = (string) $r['text'] . "\n\n" . self::t('pending_dropped', $lang);
                    }
                }
                return $r;
            }

            /* Round 3 (owner's rule R1): a quote / preview still waits and this line was neither its yes, its no, an
               amendment nor a command — it keeps waiting, and the member is told how to answer (never the menu, never
               the AI guessing at it). */
            $wait = (array) ($state['pending'] ?? []);
            if ($wait !== [] && !in_array((string) ($wait['kind'] ?? ''), ['done', 'book', 'link', 'pick'], true)
                && (string) Database::scalar('SELECT pending FROM ap_wa_state WHERE phone = :p', ['p' => $phone], '') === (string) $state['raw']) {
                return self::out(self::pendingAsk($wait, $lang));
            }

            // 5. The AI on this VPS.
            return self::thinkSlowly($fromRaw, $phone, $text, $member, $ctx, $lang);
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
            return isset($lang, $member) && $member !== null ? self::out(self::t('error', $lang ?? 'ne')) : null;
        }
    }

    /** WaBot hook #2 — should the VPS AI answer a passenger instead of the cloud model? */
    public static function customerBrainOn(): bool
    {
        if (!AgentPro::available() || !Settings::getBool('ap_ai_replace_cloud', true) || !Settings::getBool('ap_ai_customers', true)) {
            return false;
        }
        require_once __DIR__ . '/agentpro-ai.php';
        return ApAi::enabled();
    }

    /** @return array{text: string, media: ?string}|null */
    public static function customerTurn(string $fromRaw, string $body): ?array
    {
        $phone = normalisePhone($fromRaw);
        $text = trim($body);
        if ($phone === '' || $text === '') {
            return null;
        }
        $state = self::state($phone);
        $lang = self::lang($phone, $text, null, $state);
        $turn = self::bumpTurn($phone);
        $ctx = ['role' => 'customer', 'admin' => null, 'adminId' => 0, 'scopeAdminId' => null, 'name' => '',
                'phone' => $phone, 'channel' => 'whatsapp', 'messageText' => $text, 'raw_text' => $text, 'turn' => $turn];
        $ctx['t0'] = microtime(true);
        if (!empty($state['pending']) && $state['pending']['kind'] !== 'done') {
            $p = $state['pending'];
            if (preg_match(self::YES, $text) === 1) {
                if (!self::claimPending($phone, (string) $state['raw'])) {
                    return ['text' => self::t('nothing_pending', $lang), 'media' => null];
                }
                $r = self::confirm($p, null, $ctx, $lang);
                return ['text' => $r['text'], 'media' => $r['media'] ?? null];
            }
            if (preg_match(self::NO, $text) === 1) {
                self::clearPending($phone);
                return ['text' => self::t('cancelled', $lang), 'media' => null];
            }
        }
        $r = self::thinkSlowly($fromRaw, $phone, $text, null, $ctx, $lang);
        return $r === null ? null : ['text' => $r['text'], 'media' => $r['media'] ?? null];
    }

    /* =================================================================
     *  Who is writing
     * ================================================================= */

    /**
     * The staff member behind a WhatsApp number: a number linked with an OTP
     * first, else exactly one active staff account registered on it.
     *
     * @return array<string, mixed>|null admins row + 'via'
     */
    public static function memberFor(string $phone): ?array
    {
        $cols = 'id, username, full_name, phone, role, is_active, must_change_pw, locked_until';
        $link = Database::fetch('SELECT admin_id, via FROM ap_wa_links WHERE phone = :p AND revoked_at IS NULL', ['p' => $phone]);
        if ($link !== null) {
            $a = Database::fetch("SELECT $cols FROM admins WHERE id = :i", ['i' => (int) $link['admin_id']]);
            if ($a !== null && self::usable($a)) {
                // 'link' = proved with the OTP sent to the registered mobile; only that number gets the office desk.
                return $a + ['via' => (string) ($link['via'] ?? 'otp') === 'otp' ? 'link' : 'registered'];
            }
            return null;
        }
        $hits = self::sharedCandidates($phone);
        if (count($hits) === 1) {
            return $hits[0] + ['via' => 'registered'];
        }
        // 30 Oct 2026: one number, several IDs (ap_phone_max_accounts) — the one this number
        // picked on the desk ("which ID?", remembered in ap_wa_state.admin_id; "switch" asks again).
        // Never picked: nothing yet — reply() asks which ID.
        $picked = self::pickedId($phone);
        foreach ($hits as $a) {
            if ($picked > 0 && (int) $a['id'] === $picked) {
                return $a + ['via' => 'registered'];
            }
        }
        return null;
    }

    /**
     * The usable (active, not locked) accounts registered on this mobile, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function sharedCandidates(string $phone): array
    {
        $hits = [];
        foreach (Database::fetchAll("SELECT id, username, full_name, phone, role, is_active, must_change_pw, locked_until
                                       FROM admins WHERE phone IS NOT NULL AND phone <> '' ORDER BY id ASC") as $a) {
            if (normalisePhone((string) $a['phone']) === $phone && self::usable($a)) {
                $hits[] = $a;
            }
        }
        return $hits;
    }

    /** Is this WhatsApp number linked to one ID with the OTP (ap_wa_links)? Then that link decides, never a pick. */
    private static function linked(string $phone): bool
    {
        return Database::exists('SELECT 1 FROM ap_wa_links WHERE phone = :p AND revoked_at IS NULL', ['p' => $phone]);
    }

    private static ?bool $pickCol = null;

    /** Is ap_wa_state.admin_id there (database/upgrade-2026-10-30-agentpro3.sql)? */
    private static function pickOn(): bool
    {
        if (self::$pickCol === null) {
            try {
                self::$pickCol = Database::exists("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                                     AND TABLE_NAME = 'ap_wa_state' AND COLUMN_NAME = 'admin_id'");
            } catch (Throwable $e) {
                self::$pickCol = false;
            }
        }
        return self::$pickCol;
    }

    /** The ID this WhatsApp number picked on the desk (0 = none). */
    private static function pickedId(string $phone): int
    {
        return self::pickOn() ? (int) Database::scalar('SELECT admin_id FROM ap_wa_state WHERE phone = :p', ['p' => $phone], 0) : 0;
    }

    /** Remember (or, with 0, forget) the ID this number picked. */
    private static function setPicked(string $phone, int $adminId): void
    {
        if (self::pickOn()) {
            self::saveState($phone, ['admin_id' => $adminId > 0 ? $adminId : null]);
        }
    }

    /**
     * "Which ID?" for a number several accounts share: the numbered list (asked once;
     * the pick is remembered). With $text a digit while that question is open: take it.
     *
     * @param list<array<string, mixed>> $cands
     * @return array{text: string, media?: ?string}
     */
    private static function pickTurn(string $phone, string $text, array $cands, array $state, string $lang): array
    {
        $ids = array_map(static fn(array $a): int => (int) $a['id'], $cands);
        $p = $state['pending'] ?? null;
        $n = ApDocRead_digits($text);
        if (is_array($p) && ($p['kind'] ?? '') === 'pick' && preg_match('/^[1-9]$/', $n) === 1) {
            $want = (int) (((array) ($p['ids'] ?? []))[(int) $n - 1] ?? 0);
            if ($want > 0 && in_array($want, $ids, true)) {   // still one of THIS number's usable accounts
                self::clearPending($phone);
                self::setPicked($phone, $want);
                Logger::audit('agentpro.wa_pick', 'admin', (string) $want, null, ['wa' => $phone], 'WhatsApp desk: ID picked on a shared number');
                $member = self::memberFor($phone);
                $ok = self::t('pick_ok', $lang, ['code' => self::codeOf($want)]);
                return $member !== null ? self::out($ok . "\n\n" . self::help($member, $lang)['text']) : self::out($ok);
            }
        }
        self::setPending($phone, 'pick', ['ids' => $ids]);
        $lines = [self::t('pick_q', $lang)];
        foreach ($cands as $i => $a) {
            $lines[] = ($i + 1) . '. ' . (string) $a['full_name'] . ' · ' . self::codeOf((int) $a['id']) . ' · ' . (AgentPro::ROLE_LABELS[(string) $a['role']] ?? (string) $a['role']);
        }
        $lines[] = '';
        $lines[] = self::t('pick_how', $lang);
        return self::out(implode("\n", $lines));
    }

    private static function usable(array $a): bool
    {
        return (int) $a['is_active'] === 1
            && (empty($a['locked_until']) || strtotime((string) $a['locked_until']) <= time());
    }

    /** The context AiTools and ApEdit expect. */
    private static function ctx(array $member, string $phone, string $text, int $turn): array
    {
        $role = (string) $member['role'];
        $scoped = in_array($role, ['agent', 'counter'], true);
        $toolRole = in_array($role, self::SELLERS, true) && (int) $member['must_change_pw'] === 0
            ? ($scoped ? 'staff' : 'admin') : 'member';
        return [
            'role'         => $toolRole,
            'admin'        => $member,
            'adminId'      => (int) $member['id'],
            'scopeAdminId' => $scoped ? (int) $member['id'] : null,
            'name'         => (string) $member['full_name'],
            'phone'        => $phone,
            'channel'      => 'whatsapp',
            'messageText'  => $text,
            'raw_text'     => $text,
            'turn'         => $turn,
            't0'           => microtime(true),           // the turn's clock: the model gets what is left of ap_wa_turn_budget
            'office'       => self::officeMember($member) && $toolRole === 'admin',
        ];
    }

    /**
     * A super-admin / manager writing from a number linked with the OTP (LINK
     * + the code sent to the registered mobile). A number that only matches
     * the typed admins.phone (maybe mistyped, shared or old) gets no office
     * rights: payouts, declines, bank details, applicants' numbers.
     */
    private static function officeMember(array $member): bool
    {
        return in_array((string) $member['role'], self::OFFICE, true)
            && (int) ($member['must_change_pw'] ?? 0) === 0
            && (string) ($member['via'] ?? '') === 'link';
    }

    /** officeMember() and the office desk switched on (ap_wa_admin_on). */
    private static function isOffice(array $ctx): bool
    {
        return !empty($ctx['office']) && Settings::getBool('ap_wa_admin_on', true);
    }

    /* =================================================================
     *  Conversation state
     * ================================================================= */

    /** @return array{lang: ?string, turn: int, pending: ?array, history: list<array>} */
    private static function state(string $phone): array
    {
        $row = Database::fetch('SELECT * FROM ap_wa_state WHERE phone = :p', ['p' => $phone]);
        $pending = $row !== null ? json_decode((string) ($row['pending'] ?? ''), true) : null;
        if (is_array($pending) && (int) ($pending['at'] ?? 0) < time() - self::PENDING_TTL) {
            $pending = null;
        }
        $hist = $row !== null ? json_decode((string) ($row['history'] ?? ''), true) : null;
        return [
            'lang'    => $row['lang'] ?? null,
            'turn'    => (int) ($row['turn'] ?? 0),
            'pending' => is_array($pending) ? $pending : null,
            'raw'     => is_array($pending) ? (string) $row['pending'] : '',
            'history' => is_array($hist) ? $hist : [],
            'updated' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * Take the pending action for THIS message, atomically: of two "CONFIRM"s
     * arriving together (a double tap, a webhook retry) only one clears the
     * row, so only one sells / pays. The other finds nothing to confirm.
     */
    private static function claimPending(string $phone, string $raw): bool
    {
        if ($raw === '') {
            return false;
        }
        return Database::run('UPDATE ap_wa_state SET pending = NULL WHERE phone = :p AND pending = :r', ['p' => $phone, 'r' => $raw]) === 1;
    }

    /** Remember what a CONFIRM just did, so a repeated CONFIRM answers "already done" instead of doing it again. */
    private static function markDone(string $phone, string $what, string $text, ?string $media = null): void
    {
        self::setPending($phone, 'done', ['what' => $what, 'text' => mb_substr($text, 0, 1500), 'media' => $media]);
    }

    private static function saveState(string $phone, array $cols): void
    {
        $cols['phone'] = $phone;
        $keys = array_keys($cols);
        $ins = implode(', ', array_map(static fn(string $k): string => '`' . $k . '`', $keys));
        $vals = implode(', ', array_map(static fn(string $k): string => ':v_' . $k, $keys));
        $upd = implode(', ', array_map(static fn(string $k): string => '`' . $k . '` = :u_' . $k, array_diff($keys, ['phone'])));
        $params = [];
        foreach ($cols as $k => $v) {
            $params['v_' . $k] = $v;
            if ($k !== 'phone') {
                $params['u_' . $k] = $v;
            }
        }
        Database::run("INSERT INTO ap_wa_state ($ins) VALUES ($vals) ON DUPLICATE KEY UPDATE $upd", $params);
    }

    private static function bumpTurn(string $phone): int
    {
        Database::run('INSERT INTO ap_wa_state (phone, turn) VALUES (:p, 1) ON DUPLICATE KEY UPDATE turn = turn + 1', ['p' => $phone]);
        return (int) Database::scalar('SELECT turn FROM ap_wa_state WHERE phone = :p', ['p' => $phone], 1);
    }

    private static function setPending(string $phone, string $kind, array $payload): void
    {
        // An action proposed from an AI / learnt reading carries that reading (normalised words + intent, no one's
        // details), so the member's "ho" can teach it (reply() → ApLearn::record … 'confirmed').
        if (self::$learnTag !== null && !in_array($kind, ['done', 'pick', 'link'], true)) {
            $payload['learn'] = self::$learnTag;
        }
        self::saveState($phone, ['pending' => json_encode(['kind' => $kind, 'at' => time()] + $payload, JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * Run $fn with this learn tag active (setPending attaches it), then put the
     * previous tag back. A malformed tag counts as none.
     */
    private static function withLearnTag(mixed $tag, callable $fn): mixed
    {
        $before = self::$learnTag;
        self::$learnTag = is_array($tag) && is_string($tag['n'] ?? null) && is_array($tag['i'] ?? null) ? ['n' => $tag['n'], 'i' => $tag['i']] : null;
        try {
            return $fn();
        } finally {
            self::$learnTag = $before;
        }
    }

    private static function clearPending(string $phone): void
    {
        Database::run('UPDATE ap_wa_state SET pending = NULL WHERE phone = :p', ['p' => $phone]);
    }

    private static function touchLink(string $phone): void
    {
        Database::run('UPDATE ap_wa_links SET last_seen_at = NOW() WHERE phone = :p', ['p' => $phone]);
    }

    /** Forget the conversation (reset / naya). */
    public static function forget(string $phone): void
    {
        Database::run('UPDATE ap_wa_state SET pending = NULL, history = NULL WHERE phone = :p', ['p' => $phone]);
    }

    /* =================================================================
     *  Language — Nepali, Hindi, English
     * ================================================================= */

    private static function lang(string $phone, string $text, ?array $member, array $state): string
    {
        $l = mb_strtolower($text);
        $explicit = match (true) {
            preg_match('/^(english|eng|अंग्रेजी|अङ्ग्रेजी)$/u', $l) === 1 => 'en',
            preg_match('/^(nepali|नेपाली)$/u', $l) === 1                => 'ne',
            preg_match('/^(hindi|हिन्दी|हिंदी)$/u', $l) === 1            => 'hi',
            default => null,
        };
        // Switch only on real evidence: a message of names, numbers and a town keeps the
        // language this number has been using (Nepali until shown otherwise).
        $score = 0;
        $detected = $explicit === null ? self::detect($text, $score) : null;
        if (preg_match('/^(?:ok\s+)?(?:thank\s*you|thanks|thank\s*u|thanku|thx|ty)(?:\s+\S+)?[\s.!🙏]*$/iu', trim($l)) === 1) {
            $detected = null;                               // "thank you dai" in a Nepali chat is no switch to English
        }
        /* 31 Oct 2026 (agent sim: after "nepali", "thank you dai" switched the whole desk to English): a language
           the member CHOSE stays until they choose another, or write a real sentence (3+ words) in another one. */
        $locked = '';
        try {
            if ($explicit !== null) {
                Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('ap_wa_lang', :k, :v, 'apwa')
                               ON DUPLICATE KEY UPDATE kvalue = :v2", ['k' => $phone, 'v' => $explicit, 'v2' => $explicit]);
            } else {
                $locked = (string) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'ap_wa_lang' AND kkey = :k", ['k' => $phone], '');
            }
        } catch (Throwable $e) {
            $locked = '';
        }
        if ($locked !== '' && in_array($locked, ['ne', 'hi', 'en'], true) && $detected !== $locked) {
            if ($score < 3) {
                $detected = null;
                $state['lang'] = $locked;
            } elseif ($detected !== null) {
                try {                                   // a real sentence in another language: that is the new choice
                    Database::run("UPDATE kv_store SET kvalue = :v WHERE kscope = 'ap_wa_lang' AND kkey = :k", ['v' => $detected, 'k' => $phone]);
                } catch (Throwable $e) {
                }
            }
        }
        $lang = $explicit ?? $detected ?? ($state['lang'] ?? null) ?? 'ne';
        if ($lang !== ($state['lang'] ?? null)) {
            self::saveState($phone, ['lang' => $lang]);
        }
        return in_array($lang, ['ne', 'hi', 'en'], true) ? $lang : 'ne';
    }

    /** 'ne' | 'hi' | 'en' when the words say so, null when they do not. */
    public static function detect(string $text, ?int &$score = null): ?string
    {
        $score = 0;
        $l = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{M}\s]/u', ' ', $text) ?? $text) . ' ';
        $count = static function (array $words) use ($l): int {
            $n = 0;
            foreach ($words as $w) {
                $n += preg_match_all('/(?<![\p{L}\p{M}])' . preg_quote($w, '/') . '(?![\p{L}\p{M}])/u', $l);
            }
            return $n;
        };
        $deva = preg_match('/\p{Devanagari}/u', $text) === 1;
        $sc = $deva ? [
            'ne' => $count(['छ', 'छन्', 'छैन', 'मेरो', 'मलाई', 'हामीलाई', 'कति', 'चाहियो', 'चाहिन्छ', 'गर्नुहोस्', 'गर्नु', 'तपाईं', 'तपाईंको', 'हुन्छ', 'भोलि', 'पर्सि', 'बाट', 'सम्म', 'हो', 'होइन', 'दिनुहोस्', 'कहाँ', 'जाने', 'आउने', 'पठाउनुहोस्', 'भयो', 'थियो', 'जना']),
            'hi' => $count(['है', 'हैं', 'मेरा', 'मेरी', 'मुझे', 'हमें', 'कितना', 'कितने', 'चाहिए', 'करें', 'कीजिए', 'आप', 'आपका', 'नहीं', 'हूँ', 'हूं', 'कल', 'परसों', 'से', 'तक', 'क्या', 'कहाँ', 'जाना', 'भेजो', 'भेजिए', 'बताइए', 'बताओ', 'था', 'लोग']),
            'en' => 0,
        ] : [
            'ne' => $count(['cha', 'chha', 'xa', 'chaina', 'mero', 'malai', 'hamilai', 'kati', 'chahiyo', 'chaiyo', 'garnu', 'garnus', 'garidinu', 'tapai', 'hunchha', 'huncha', 'bholi', 'parsi', 'bata', 'samma', 'hola', 'kaha', 'pathaunu', 'pathaidinu', 'bhayo', 'thiyo', 'jana', 'paisa', 'baki', 'dinus', 'paryo',
                            // 31 Oct 2026 (counter sim: "aajako hisab dekhau" was answered in English)
                            'aaja', 'aajako', 'hisab', 'hisaab', 'dekhau', 'dekhaunu', 'dekhaideu', 'pathau', 'deu', 'dinu', 'kasko', 'kun', 'lai', 'gareko', 'garyo', 'milena', 'chahidaina', 'pardaina',
                            // 31 Oct 2026 (usability leftover c: "upi chalcha?" was answered in English)
                            'chalcha', 'chalchha', 'chalxa', 'milcha', 'milchha', 'milxa', 'paincha', 'painchha', 'pugyo', 'kina', 'thapnu', 'arko', 'kahile']),
            'hi' => $count(['hai', 'hain', 'mera', 'meri', 'mujhe', 'humein', 'kitna', 'kitne', 'chahiye', 'chaiye', 'karo', 'kijiye', 'aap', 'aapka', 'nahi', 'nahin', 'hoon', 'kal', 'parso', 'se', 'tak', 'kya', 'kahan', 'jaana', 'bhejo', 'batao', 'tha', 'log',
                            // 31 Oct 2026 (owner sim: "aaj kitne ticket bike?" was answered in English)
                            'aaj', 'bike', 'bika', 'bikey', 'kaise', 'karu', 'karun', 'dikhao', 'kaun', 'kab', 'kaam',
                            // 31 Oct 2026 (agent sim: "kal 2 ticket nikal do …" / "haan ticket bana do" were answered in Nepali)
                            'haan', 'nikal', 'nikalo', 'nikaldo', 'banado', 'kaatdo', 'dijiye', 'kardo', 'dena', 'chalega', 'milega', 'milegi', 'jayega']),
            'en' => $count(['the', 'is', 'are', 'my', 'me', 'what', 'where', 'when', 'please', 'want', 'need', 'book', 'seats', 'tomorrow', 'today', 'send', 'how', 'much', 'can', 'you', 'ticket', 'from', 'to', 'for', 'change', 'cancel', 'thanks', 'thank', 'hello', 'i']),
        ];
        arsort($sc);
        $top = array_key_first($sc);
        $vals = array_values($sc);
        // English needs two English words: "to Surat" alone is not a language choice.
        if ($vals[0] === 0 || $vals[0] === $vals[1] || ($top === 'en' && $vals[0] < 2)) {
            return null;
        }
        $score = (int) $vals[0];
        return $top;
    }

    /* =================================================================
     *  Instant commands
     * ================================================================= */

    /** @return array{text: string, media?: ?string}|null */
    private static function command(string $text, array $member, array $ctx, string $lang, bool $nl = true): ?array
    {
        $text = self::fixTicketWord($text);   // 30 Oct 2026: "Tciket book" is a ticket too
        $l = mb_strtolower($text);
        $pnr = '';
        if (preg_match('/\bSHG-[A-Z0-9]+(?:-[A-Z0-9]+)+\b/i', $text, $m) === 1) {
            $pnr = strtoupper($m[0]);
        }

        if (preg_match('/^(reset|naya|नयाँ|restart|clear)$/u', $l)) {
            self::forget($ctx['phone']);
            return self::out(self::t('reset', $lang));
        }
        if (preg_match('/^(english|eng|nepali|hindi|नेपाली|हिन्दी|हिंदी|अंग्रेजी|अङ्ग्रेजी)$/u', $l)) {
            return self::out(self::t('lang_set', $lang));
        }
        if (preg_match('/^(help|menu|madat|maddat|मद्दत|सहायता|सहयोग|\?|start|hi|hello|hey|namaste|namaskar|नमस्ते|नमस्कार|हेलो|hii+)$/u', $l)) {
            self::$cmdIntent = ['intent' => 'help'];
            return self::help($member, $lang);
        }
        if (preg_match('/^unlink$/i', $l)) {
            Database::run('UPDATE ap_wa_links SET revoked_at = NOW() WHERE phone = :p', ['p' => $ctx['phone']]);
            return self::out(self::t('unlinked', $lang));
        }
        // One number, several IDs: pick another one ("switch").
        if (preg_match('/^(?:switch|switch\s*id|id\s*switch|change\s*id|id\s*change|id\s*badal\w*|badal|बदल\w*|आईडी\s*बदल\w*|id\s*बदल\w*)$/u', $l)) {
            if (self::linked($ctx['phone'])) {
                return self::out(self::t('switch_linked', $lang));
            }
            $cands = self::sharedCandidates($ctx['phone']);
            if (count($cands) < 2 || !self::pickOn()) {
                return self::out(self::t('switch_one', $lang));
            }
            self::setPicked($ctx['phone'], 0);
            return self::pickTurn($ctx['phone'], '', $cands, ['pending' => null], $lang);
        }

        // The numbered menu: "3" = balance, … (the same handlers as the words).
        $digit = ApDocRead_digits($text);
        if (preg_match('/^[0-9]$/', $digit) === 1) {
            $word = self::MENU[(int) $digit] ?? 'help';
            if ($word === 'admin' && !self::isOffice($ctx)) {
                $word = 'help';
            }
            $r = self::command($word, $member, $ctx, $lang, false);
            self::$cmdIntent = null;                          // "3" means balance only on this menu: not an example
            return $r;
        }

        /* 31 Oct 2026 (owner: "jhe bhane ni tehi anusar reply"; four desk sims): a SENTENCE that means one of the
           commands below ("mero commission kati bhayo?", "bholi ko yatri list pathau", "Sunil ko number deu",
           "baki kam k k cha?") is read into that command. With the VPS AI off these all got the menu. */
        if ($nl) {
            $money = self::$holdLine ? null : self::moneyRoute($text, $member, $ctx, $lang);
            if ($money !== null) {
                return $money;
            }
            $c = self::canon($text, $ctx);
            if ($c !== null) {
                switch ($c) {
                    case '__private':
                        return self::out(self::t('no_private', $lang, ['phone' => Settings::officePhone()]));
                    case '__thanks':
                        return self::out(self::t('thanks', $lang));
                    case '__local':
                        $local = self::localAnswer($text, $ctx);
                        if ($local !== null) {
                            return $local;
                        }
                        break;
                    case '__fare':
                        // round 2 (agent sim: "Surat dekhi Rupaidiha bhada kati ho?" got the menu): the fare board's answer
                        try {
                            require_once __DIR__ . '/wafaq.php';
                            $fact = WaFaq::factAnswer('+91' . (string) ($ctx['phone'] ?? ''), $text);
                            if ($fact !== null) {
                                return self::out((string) $fact['text'], $fact['media'] ?? null);
                            }
                        } catch (Throwable $e) {
                            Logger::warning('Fare answer on the desk failed: ' . $e->getMessage(), [], 'whatsapp');
                        }
                        break;
                    case '__cancel_which':
                        return self::out(self::t('cancel_which', $lang));
                    default:
                        if (str_starts_with($c, '__ref_only:')) {
                            // round 3 (counter / agent sims: a bare "UTR 4123…" got the whole menu): whose payment, how much
                            $ref = substr($c, 11);
                            return self::out(self::t('ref_only', $lang, ['tail' => substr($ref, -4), 'ref' => $ref, 'link' => appUrl('admin/payments.php')]));
                        }
                        if (str_starts_with($c, '__pay_later:')) {
                            return self::out(self::t('pay_later', $lang, ['n' => substr($c, 12), 'v' => inr((float) substr($c, 12))]));
                        }
                        if (str_starts_with($c, '__paxfind:')) {
                            if (!in_array($ctx['role'], ['staff', 'admin'], true)) {
                                break;
                            }
                            require_once __DIR__ . '/aitools.php';
                            $q = substr($c, 10);
                            $f = AiTools::run('my_sales', ['name' => $q, 'limit' => 10], $ctx);
                            $sales = $f['ok'] ? (array) ($f['data']['sales'] ?? []) : [];
                            if ($sales === []) {
                                return self::out(self::t('pf_none', $lang, ['q' => mb_convert_case($q, MB_CASE_TITLE)]));
                            }
                            $lines = [self::t('pf_head', $lang, ['q' => mb_convert_case($q, MB_CASE_TITLE), 'n' => (string) count($sales)])];
                            foreach ($sales as $s) {
                                $lines[] = '🎫 ' . $s['pnr'] . ' · ' . strtoupper((string) $s['status']) . "\n    " . $s['dateLabel'] . ' · ' . $s['pickup']
                                    . ' · ' . $s['seats'] . ' 💺 · 👤 ' . $s['name'];
                            }
                            $lines[] = '';
                            $lines[] = self::t('card_tail', $lang);
                            return self::out(implode("\n", $lines));
                        }
                        if (str_starts_with($c, '__book')) {
                            if (!in_array((string) $member['role'], self::SELLERS, true)) {
                                break;
                            }
                            self::$cmdIntent = ['intent' => 'book'];
                            $d = substr($c, 7);
                            return self::bookStart($member, $ctx, $lang, $d !== '' ? ['date' => $d] : []);
                        }
                        $r = self::command($c, $member, $ctx, $lang, false);
                        if ($r !== null) {
                            return $r;
                        }
                }
            }
        }

        // The office's own desk (super-admin / manager only).
        if (preg_match('/^(?:admin|office\s*menu|अफिस\s*मेनु|एडमिन)$/u', $l)) {
            return self::isOffice($ctx) ? self::adminHelp($lang) : self::out(self::t('no_permission', $lang));
        }
        if (preg_match('/^(?:pending|baki\s*kam|बाँकी\s*काम|approvals?)$/u', $l)) {
            self::$cmdIntent = ['intent' => 'pending'];
            return self::isOffice($ctx) ? self::adminPending($lang) : self::out(self::t('no_permission', $lang));
        }
        if (preg_match('/^(?:joins?|applications?|apps|aavedan|abedan|आवेदन\w*|join\s*list)$/u', $l)) {
            return self::isOffice($ctx) ? self::adminJoins($lang) : null;
        }
        if (preg_match('/^(?:withdrawals|payouts|wd|wd\s*list|withdraw\s*list|pending\s*withdraw\w*)$/u', $l) && self::isOffice($ctx)) {
            return self::adminWithdrawals($lang);
        }
        // The reference is kept as typed ("UTR430987651234"), the way the Team page records it.
        if (preg_match('/^(?:approve|aprove|aproove|approov\w*|apprve|aprv|apporve|approv|wd\s*pay|wd\s*approve)\s*#?(\d{1,9})\s*[:#-]?\s*(.*)$/iu', self::asciiDigits($text), $m)) {
            return self::isOffice($ctx) ? self::adminPayPreview((int) $m[1], trim($m[2]), $ctx, $lang) : self::out(self::t('no_permission', $lang));
        }
        if (preg_match('/^(?:reject|rejct|rejet|rject|reejct|decline|declin|wd\s*reject)\s*#?(\d{1,9})\s*[:#-]?\s*(.*)$/iu', self::asciiDigits($text), $m)) {
            return self::isOffice($ctx) ? self::adminRejectPreview((int) $m[1], trim($m[2]), $ctx, $lang) : self::out(self::t('no_permission', $lang));
        }
        // Payments members say they made (ap_deposit_requests): list, confirm, decline — before "jama" (pay in) below.
        if (preg_match('/^(?:deposits|payments|jama\s*list|jama\s*check|भुक्तानी\s*जाँच|जम्मा\s*सूची)$/u', $l) && self::isOffice($ctx)) {
            return self::adminDeposits($lang);
        }
        if (preg_match('/^(?:jama|jamma|जम्मा|deposit|dep)\s*(?:ok|okay|confirm|approve|yes|ho|हो)\s*#?(\d{1,9})\s*[:#-]?\s*(.*)$/iu', self::asciiDigits($text), $m)) {
            return self::isOffice($ctx) ? self::adminDepositPreview((int) $m[1], 'confirmed', trim($m[2]), $ctx, $lang) : self::out(self::t('no_permission', $lang));
        }
        if (preg_match('/^(?:jama|jamma|जम्मा|deposit|dep)\s*(?:no|reject|decline|hoina|होइन)\s*#?(\d{1,9})\s*[:#-]?\s*(.*)$/iu', self::asciiDigits($text), $m)) {
            return self::isOffice($ctx) ? self::adminDepositPreview((int) $m[1], 'declined', trim($m[2]), $ctx, $lang) : self::out(self::t('no_permission', $lang));
        }

        // A booking, one question at a time.
        if (preg_match('/^(?:ticket|tikat|tikut|टिकट|टिकेट|book|booking|बुक|बुकिङ|बुकिंग|new\s*ticket|naya\s*ticket|नयाँ\s*टिकट|ticket\s*(?:book|kata\w*|katnu|chahiyo|चाहियो)|टिकट\s*(?:काट\w*|चाहियो|बुक))$/u', $l)) {
            self::$cmdIntent = ['intent' => 'book'];
            return self::bookStart($member, $ctx, $lang);
        }
        // The seller's own tickets: "tickets", "tickets bholi", "mero ticket 2 Nov".
        if (preg_match('/^(?:my\s*tickets?|tickets|mero\s*ticket\w*|mera\s*ticket\w*|मेरो\s*टिकट\w*|मेरा\s*टिकट|sold\s*tickets?|bechya\w*|बेचेको\s*टिकट)(?:\s+(.+))?$/u', $l, $m)) {
            $d = isset($m[1]) ? self::dateWord((string) $m[1]) : '';
            self::$cmdIntent = ['intent' => 'tickets'];
            return self::myTickets($member, $ctx, $lang, $d);
        }
        // The member portal: where and how to sign in.
        if (preg_match('/^(?:portal|login|log\s*in|sign\s*in|signin|me\s*link|my\s*portal|app|लगइन|लग\s*इन|पोर्टल)$/u', $l)) {
            self::$cmdIntent = ['intent' => 'portal'];
            return self::portal($member, $lang);
        }
        // 31 Oct 2026 (owner: "bhareko form WhatsApp ma pathaune"): my own filled application form.
        if (preg_match('/^(?:form|my\s*form|mero\s*form|mera\s*form|फारम|फर्म|फॉर्म|मेरो\s*फारम|मेरो\s*फर्म|मेरा\s*फॉर्म|application\s*form|bhareko\s*form|भरेको\s*फारम|form\s*pdf|फारम\s*pdf)$/u', $l)) {
            self::$cmdIntent = ['intent' => 'form'];
            return self::myForm($member, $lang);
        }

        // 31 Oct 2026 (owner: "ID … WhatsApp ma pathaune"): the office asks for SOMEONE's card — "id SHG-0027",
        // "id SHG-S-0004", "id 98…", "id Ram" (+ "back") — to forward it. An OTP-linked manager / super-admin only
        // (isOffice). Before the PNR below: a staff number SHG-S-0004 looks like a PNR to that pattern.
        if (preg_match('/^(?:id\s*card|idcard|id|आईडी\s*कार्ड|आईडी|आइडी)\s+(.{2,60}?)(?:\s+(back|pachadi|पछाडि|पीछे))?$/u', $l, $m) === 1
            && ($pnr === '' || preg_match('/^shg\s*-\s*s\s*-\s*\d+$/u', trim($m[1])) === 1)) {
            $q = trim($m[1]);
            if (preg_match('/^(?:(front|agadi|अगाडि|card|kard|कार्ड)|(back|pachadi|पछाडि|पीछे))$/u', $q, $own) === 1) {
                self::$cmdIntent = ['intent' => 'id_card'];            // "id back", "id card", "id front": my own card
                return self::card($member, empty($own[2]) ? 'id-front' : 'id-back', $lang);
            }
            if (self::isOffice($ctx)) {
                return self::officeId($q, !empty($m[2]) ? 'id-back' : 'id-front', $ctx, $lang);
            }
            if (preg_match('/\d/', $q) === 1) {
                return self::out(self::t('no_permission', $lang));   // someone else's code / number: the office's desk only
            }
            // round 3 (agent sim: "Kamal ko id card pathau" got the menu; office sim: "Ramesh lai id card pathaidiye" sent
            // the SENDER's own card): another member's card is the office's to send — said plainly, never the sender's own
            $cardOf = self::findMembers($q);
            if ($cardOf !== [] && !in_array((int) $member['id'], array_map(static fn(array $h): int => (int) $h['id'], $cardOf), true)) {
                return self::out(self::t('card_own_only', $lang));
            }
            // "id card bhayena …" from an agent is a sentence, not a lookup: the AI / the menu below answers it
        }

        // A PNR: status, edit, cancel, resend.
        if ($pnr !== '') {
            $r = self::pnrCommand($pnr, $text, $member, $ctx, $lang);
            /* round 3 (counter sim: "<PNR> UTR 4123…" showed the card and dropped the reference silently): a passenger's
               payment reference beside a PNR is answered — the office verifies it; nothing is filed from here (R2). */
            require_once __DIR__ . '/wapayproof.php';
            if ($r !== null && WaPayProof::payTalk($text)) {
                $r['text'] = (string) $r['text'] . "\n\n" . self::t('pay_pax_note', $lang, ['link' => appUrl('admin/payments.php'), 'phone' => Settings::officePhone()]);
            }
            return $r;
        }

        // Money.
        if (preg_match('/^(?:balance|bal|wallet|baki|baaki|बाँकी|बाकी|ब्यालेन्स|बैलेंस|बैलेन्स|kati\s*paisa|mero\s*paisa|paisa\s*kati|commission|कमिसन|कमीशन)\b/u', $l)) {
            // 31 Oct 2026 (owner sim: "balance SHG-0024" answered with the owner's own ₹0): someone else's wallet
            $who = self::otherMember($text, $member, $ctx, $lang);
            if ($who !== null) {
                return isset($who['text']) ? $who : self::balance($who, $lang, true);
            }
            self::$cmdIntent = ['intent' => 'balance'];
            return self::balance($member, $lang);
        }
        if (preg_match('/^(?:statement|stmt|hisab|hisaab|हिसाब|स्टेटमेन्ट|स्टेटमेंट|passbook|ledger|khata|खाता)\b\s*(\d{1,3})?/u', $l, $m)) {
            $who = self::otherMember($text, $member, $ctx, $lang);
            if ($who !== null) {
                return isset($who['text']) ? $who : self::statement($who, $lang, isset($m[1]) ? (int) $m[1] : 0, true);
            }
            self::$cmdIntent = ['intent' => 'statement', 'days' => isset($m[1]) ? (int) $m[1] : 0];
            return self::statement($member, $lang, isset($m[1]) ? (int) $m[1] : 0);
        }
        if (preg_match('/^(?:withdraw|withdrawal|payout|nikal|nikalnu|निकाल|निकाल्ने|पैसा\s*निकाल|paisa\s*nikal|पैसे\s*निकाल)\w*\s*(?:rs\.?|₹|inr)?\s*([\d,]+)?/u', $l, $m)) {
            self::$cmdIntent = ['intent' => 'withdraw'];
            return self::withdraw($member, $ctx, $lang, isset($m[1]) ? (float) str_replace(',', '', $m[1]) : 0.0);
        }
        if (preg_match('/^(?:jama|jamma|जम्मा|deposit|paid|pay|tirne|tireko|तिरेको|भुक्तानी|bhuktani|handover|बुझाएँ|bujhaye)\b/u', $l)) {
            self::$cmdIntent = ['intent' => 'deposit'];
            return self::payIn($member, $text, $lang, $ctx);
        }

        // Cards and me.
        if (preg_match('/^(?:id|id\s*card|idcard|आईडी|आइडी|परिचय\s*पत्र|parichay\s*patra|identity)(?:\s*(back|pachadi|पछाडि|पीछे))?$/u', $l, $m)) {
            self::$cmdIntent = ['intent' => 'id_card'];
            return self::card($member, empty($m[1]) ? 'id-front' : 'id-back', $lang);
        }
        if (preg_match('/^(?:visiting|visiting\s*card|vc|card|business\s*card|भिजिटिङ|विजिटिंग)(?:\s*card)?(?:\s*(back|pachadi|पछाडि|पीछे))?$/u', $l, $m)) {
            self::$cmdIntent = ['intent' => 'visiting_card'];
            return self::card($member, empty($m[1]) ? 'vc-front' : 'vc-back', $lang);
        }
        if (preg_match('/^(?:me|profile|mero\s*detail|my\s*details|details|मेरो\s*विवरण|विवरण)$/u', $l)) {
            self::$cmdIntent = ['intent' => 'profile'];
            return self::profile($member, $lang);
        }

        // People and the company.
        if (preg_match('/^(?:list|contacts?|directory|numbers?|सबैको\s*नम्बर|नम्बरहरू|agents?|staff|team|एजेन्टहरू|स्टाफ)(?:\s+(.+))?$/u', $l, $m)) {
            self::$cmdIntent = ['intent' => 'directory', 'query' => trim((string) ($m[1] ?? ''))];
            return self::directory($member, $lang, trim((string) ($m[1] ?? '')), $l);
        }
        if (preg_match('/^(?:number|nambar|नम्बर|phone|फोन)\s+(?:of\s+)?(.{2,40})$/u', $l, $m) && preg_match('/\d{6,}/', $m[1]) !== 1) {
            self::$cmdIntent = ['intent' => 'directory', 'query' => trim($m[1])];
            return self::directory($member, $lang, trim($m[1]), 'search');
        }
        if (preg_match('/^(?:office|company|cin|address|thegana|ठेगाना|कार्यालय|अफिस|ऑफिस|contact\s*office|email|website|counters?|काउन्टर)\b/u', $l)
            && !(in_array((string) $member['role'], self::SELLERS, true) && self::saleParse($text) !== null)) {   // 31 Oct 2026: a sale line wins
            self::$cmdIntent = ['intent' => 'company'];
            return self::company($lang);
        }
        if (preg_match('/^(?:bus|buses|gadi|gaadi|गाडी|बस|aaja\s*ko\s*bus|bus\s*number|bus\s*details)\b(?:\s+(bholi|tomorrow|भोलि|kal|कल))?/u', $l, $m)) {
            self::$cmdIntent = ['intent' => 'bus'];
            return self::buses($member, $lang, !empty($m[1]) ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d'));
        }
        if (preg_match('/^(?:today|aaja|aja|आज|report|sales|bikri|बिक्री|मेरो\s*दिन|my\s*day|आजको\s*रिपोर्ट)\b/u', $l)) {
            self::$cmdIntent = ['intent' => 'report'];
            return self::report($member, $ctx, $lang, str_contains($l, 'office') || str_contains($l, 'company') ? 'office' : '');
        }
        if (preg_match('/^(?:passengers?|yatri|यात्रु|यात्री|manifest)\b(?:\s+(.+))?$/u', $l, $m)) {
            $d = isset($m[1]) ? self::dateWord((string) $m[1]) : date('Y-m-d');
            self::$cmdIntent = ['intent' => 'passengers'];
            return self::passengers($member, $ctx, $lang, $d !== '' ? $d : date('Y-m-d'));
        }

        // A sale.
        if (in_array((string) $member['role'], self::SELLERS, true)) {
            /* Round 3 (owner's rule R2): a line about a payment made (utr / ref / txn / "paisa pathaye") is never a sale
               line — the payment and the ticket go in two messages. */
            require_once __DIR__ . '/wapayproof.php';
            require_once __DIR__ . '/ticketbot.php';
            if (WaPayProof::payTalk($text)) {
                return (int) TicketBot::parse($text)['seats'] > 0 ? self::out(self::t('pay_split', $lang)) : null;
            }
            $sale = self::saleParse($text);
            if ($sale !== null) {
                // the passenger's name(s) go out of the example (ApLearn::norm), the seats and words stay
                self::$cmdIntent = ['intent' => 'book', 'seats' => (int) ($sale['seats'] ?? 0), 'direction' => (string) ($sale['direction'] ?? ''),
                    'name' => (string) ($sale['name'] ?? ''), 'names' => (array) ($sale['names'] ?? []), 'pay' => (string) ($sale['pay'] ?? '')];
                /* 31 Oct 2026 (agent sim: "Dileep, 2 ticket ok tciket" was quoted for the next Surat bus — a day and a
                   pickup nobody said — and one "ok" would have sold it): no day or no pickup = ask for them. */
                if ((string) $sale['date'] === '' || (string) $sale['boarding'] === '') {
                    return self::bookStart($member, $ctx, $lang, $sale);
                }
                return self::saleQuote($sale, $member, $ctx, $lang, self::asksAvailability($text));
            }
        }
        return null;
    }

    /** 31 Oct 2026 (leftover g): "1 seat bholi Surat Ram Thapa 98… milcha?" — the sale line ASKS, it does not order. */
    private static function asksAvailability(string $text): bool
    {
        $l = mb_strtolower(trim($text));
        return str_contains($l, '?') || str_contains($l, '？')
            || preg_match('/(?<![\p{L}\p{M}])(?:milcha|milchha|milxa|milla|painchha|paincha|paaincha|paainchha|milega|milegi|mil\s*jayega|mil\s*jaegi|mil\s*sakta|available|khali\s*(?:cha|chha|xa|hai)|(?:cha|chha|xa)\s*ki|hai\s*kya|होला|मिल्छ|पाइन्छ|मिलेगा|मिलेगी)\s*[.!।]*$/u', $l) === 1;
    }

    /* ---------------- sentences → commands (31 Oct 2026) ---------------- */

    /**
     * What a sentence means as one of the typed commands: "mero commission kati bhayo?" → "balance",
     * "bholi ko yatri list pathau" → "passengers 2026-11-01", "Sunil ko number deu" → "number sunil",
     * "baki kam k k cha?" (office) → "pending". Special answers: __private (someone's bank / ID numbers —
     * never on WhatsApp), __thanks, __local (a timetable / free-seat question for the local brain).
     * null = not one of these (a sale, a name, anything else goes on exactly as before).
     */
    private static function canon(string $text, array $ctx): ?string
    {
        $l = mb_strtolower(self::asciiDigits($text));
        $question = str_contains($l, '?') || str_contains($l, '？');
        $l = ' ' . trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[?？!.,;:।"\'()]+/u', ' ', $l))) . ' ';
        $bare = trim($l);
        if ($bare === '' || mb_strlen($bare) > 220 || preg_match('/shg-?\s?\d{4}-?\s?\d{3,6}/u', $l) === 1) {
            return null;                                              // a PNR: pnrCommand reads it
        }
        if (preg_match('/^(?:approve|aprove|aproove|approv\w*|reject|rejct|rejet|decline|wd|jama|jamma|deposit|dep|link|unlink|switch|names?)(?![\p{L}])/u', $bare) === 1) {
            return null;                                              // a typed office / money command, read as typed
        }
        $office = self::isOffice($ctx);
        $has = static fn(string $re): bool => preg_match('/(?<![\p{L}\p{M}])(?:' . $re . ')(?![\p{L}\p{M}])/u', $l) === 1;
        $day = $has('parsi|parso|पर्सि|परसों|day\s*after') ? date('Y-m-d', strtotime('+2 day'))
             : ($has('bholi|bholli|tomorrow|kal|भोलि|कल') ? date('Y-m-d', strtotime('+1 day'))
             : ($has('aaja|aaj|aajako|aja|today|आज|आजको') ? date('Y-m-d') : ''));

        if (preg_match('/^(?:ok\s+)?(?:dhanyabad|dhanyabaad|dhanyawad|dhanyavad|dhanybad|thank\s*you|thanks|thank\s*u|thanku|thnx|thx|ty|shukriya|shukria|धन्यवाद|धन्यबाद|शुक्रिया)(?:\s+(?:dai|ji|sir|hajur|bhai|didi|जी|हजुर|very\s*much|so\s*much))*$/u', $bare) === 1) {
            return '__thanks';
        }
        /* A sale line ("company ko 1 seat bholi Surat …", "kal 2 ticket nikal do Surat Ram Thapa 98…", "2 seat … paisa
           4400 jama garchu", "… bank bata pay garchu") is the sale's, never a command. Round 2 (31 Oct 2026): this guard
           now comes FIRST — after the money rules it turned "ticket nikal do" into a withdrawal of the whole commission,
           "jama garchu" into a deposit with the passenger's mobile as the UTR, and "bank bata" into the bank refusal. */
        if (preg_match('/\d+\s*(?:seat|seats|sit|sits|ticket|tickets|tikat|tkt|jana|jan|log|berth|सिट|सीट|टिकट|जना)(?![\p{L}])/u', $l) === 1
            || preg_match('/(?<!\d)\d{10}(?!\d)/', $l) === 1) {
            return null;
        }
        // someone's bank / ID numbers: never on WhatsApp (owner rule; the Team page has them). "mero account ma balance
        // k cha?" is the balance, not a bank number: a bank / account word needs a NUMBER / details word with it.
        $idDoc = $has('aadhar|aadhaar|adhar|pan\s*card|pan\s*number|citizenship|nagarikta|nagrikta|passport|ifsc|आधार|नागरिकता|पासपोर्ट');
        if (($idDoc && $has('number|nambar|nmbr|नम्बर|नंबर|deu|dinu|dinus|pathau\w*|chahiyo|chahiye|batao|send|share|details?|photo|copy|k|ke|kya|ho|cha|de|do'))
            || ($has('bank|account|a\/c|acc|खाता|बैंक') && $has('number|nambar|nmbr|नम्बर|नंबर|details?|ifsc'))) {
            return '__private';
        }
        /* A payment the member made — only ever a PREVIEW (payIn waits for "ho"). The reference must be explicit
           (utr / ref / txn / transaction id + 10 or more digits; never a mobile, never on a question or "I will pay"),
           and the amount is the one beside rs / ₹ or the verb — not a date ("26 Oct") or the first number. */
        /* Round 3: the office never gets its OWN deposit / withdrawal from a sentence (owner's rule R3 — moneyRoute()
           has already sent a sentence that names a member to that member's request); its own is typed: "jama … UTR …". */
        // round 3 (agent sim: "maine 3000 bhej diye hai UTR …", "payment kar diya 3000 UTR …" got the menu): Hindi pay verbs
        $payWords = 'tireko|tire|tiryo|tiriyo|tirisake\w*|pathaye|pathaeko|pathako|pathayeko|pathaisake\w*|jama|jamma|deposit|paid|pay\s*gare\w*|bhuktani|transfer|bujhaye|bujhaeko|bujhayeko|bujhaisake\w*|bhej\s*diy[ae]|bhej\s*diye|bheje|bheja|kar\s*diya|kar\s*diye|payment\s*kiya|bhar\s*diya|bhar\s*diye|तिरेको|पठाएँ|जम्मा|भुक्तानी|बुझाएँ|भेज\s*दिए|भेज\s*दिया';
        require_once __DIR__ . '/wapayproof.php';
        $refAny = $office ? '' : WaPayProof::refFrom($text, (string) ($ctx['phone'] ?? ''));
        // round 3 (agent sim: "maile 2,500 tireko chu UTR …" previewed ₹500): the amount is read with its commas
        $amtRaw = self::amountIn(mb_strtolower(self::asciiDigits($text)), $refAny);
        if (!$office && $refAny !== '' && $amtRaw !== '' && !$has($payWords) && !$has('withdraw\w*|payout|nikal\w*')) {
            return 'jama ' . $amtRaw . ' UTR ' . $refAny;                 // "3000 ko UTR 4123…": the member's own payment (a preview)
        }
        if (!$office && $refAny !== '' && $amtRaw === '' && !$has('withdraw\w*|payout|nikal\w*')) {
            return '__ref_only:' . $refAny;                              // "UTR 4123…" alone: whose / how much is asked
        }
        if (!$office && $has($payWords)) {
            $ref = $refAny;
            $amt = $amtRaw;
            if ($ref !== '') {
                return 'jama' . ($amt !== '' ? ' ' . $amt : '') . ' UTR ' . $ref;
            }
            // round 3 (agent sim: "kal 500 jama karunga UTR …" got the menu): a payment still to be made — how to file it after
            if ($amt !== '' && !$question && WaPayProof::questionOrFuture($text)) {
                return '__pay_later:' . $amt;
            }
            // "maile 2000 jama gareko chu", "office ma 5000 cash bujhaye": no reference yet — payIn asks for it
            if ($amt !== '' && !$question && !WaPayProof::questionOrFuture($text)
                && $has('jama\s*gar\w*|jamma\s*gar\w*|jama\s*bhayo|tireko|tiryo|tiriyo|tirisake\w*|bujhaye|bujhaeko|bujhayeko|bujhaisake\w*|deposit\s*gar\w*|paid|pay\s*gare\w*|transfer\s*gare\w*|pathaye|pathaeko|pathayeko|तिरेको|बुझाएँ|बुझाएको')
                && $has('jama|jamma|deposit|cash|nagad|paisa|rupaya|rs|upi|bank|payment|tireko|tiryo|tiriyo|bujhaye|bujhaeko|bujhayeko|जम्मा|नगद|पैसा')) {
                return 'jama ' . $amt . ($has('cash|nagad|नगद|haat\s*ma|hath\s*ma') ? ' cash' : ' done');
            }
        }
        /* A withdrawal (a preview; "ho" files it). "nikal" alone is Hindi "issue the ticket" / "the bus will leave"
           ("ticket nikal do", "bus kitne baje nikalegi?"): it counts only with money and never beside a ticket / bus. */
        $wdWord = $has('withdraw|withdrawal|withdrawl|payout');
        $nikal = $has('nikal\w*|निकाल\w*') && !$has('ticket|tickets|tikat|tiket|tkt|seat|seats|sit|bus|buses|gadi|gaadi|baje|time|samay|kahile|kab|टिकट|सिट|बस|गाडी|बजे');
        if ($wdWord || $nikal) {
            $amt = self::amountIn(mb_strtolower(self::asciiDigits($text)), '');
            $moneyWord = $has('paisa|paise|rs|rupaya|rupees|commission|comission|kamisan|amount|balance|पैसा|पैसे|कमिसन|कमीशन');
            if ($office && $amt === '' && $has('baki|baaki|pending|kasko|kaskako|kun|list|open|kati|cha|chha|request|haru|ko')) {
                return 'withdrawals';
            }
            if ($office) {
                return null;                                          // round 3 (R3): never the office's own withdrawal from a sentence
            }
            // round 3 (safety review, low: "kati samma withdraw garna milcha" previewed a ₹1,800 withdrawal): a question
            // about withdrawing is answered with the balance — a withdrawal is only previewed when it is asked for
            if ($amt === '' && ($question || $has('milcha|milchha|milxa|milla|sakinchha|sakincha|sakinxa|kati\s*samma|kahile|kaile|kab|how\s*much|kitna|kitne|can\s*i|kasari|kaise'))) {
                return 'balance';
            }
            if (($wdWord || $amt !== '' || $moneyWord) && !$has('kasko|kaskako|list|haru')) {
                return 'withdraw' . ($amt !== '' ? ' ' . $amt : '');
            }
        }
        if ($office && $has('pending|pendng|pendig|pendin|pendings|baki\s*kaa?m|baaki\s*kaa?m|बाँकी\s*काम|बाकी\s*काम|pending\s*kaa?m|kaa?m\s*baa?ki|kaa?m\s*pending')) {
            return 'pending';
        }
        if ($office && $has('form|forms|aavedan|abedan|aawedan|application|applications|join|joining|joins|आवेदन|फारम')
            && !$has('mero|my|mera|meri') && $has('naya|nayan|new|aayo|aaye|aako|aaeko|aayeko|cha|chha|kati|list|pending|baki|आयो|नयाँ|kasko|kun')) {
            return 'joins';
        }
        // "company ko UPI id pathau": the company's pay-in details (UPI + QR), never the sender's own ID card
        if ($has('upi|gpay|google\s*pay|phonepe|phone\s*pe|paytm|qr|scanner|esewa') && $has('id|number|nambar|details?|pathau\w*|deu|dinu|dinus|kun|k|ke|kya|ho|cha|chahiyo|chahiye|batao|send|code')) {
            return 'jama';
        }
        // "ticket cancel garnu cha" with no PNR: ask which one
        if ($has('cancel|cancle|radda|radd|रद्द|क्यान्सिल|कैंसिल') && $has('ticket|tickets|tikat|tiket|booking|टिकट|बुकिङ')) {
            return '__cancel_which';
        }
        // "Ram Thapa ko ticket kun bus ma cha?": the seller's own sales for that passenger (never anyone else's)
        if (preg_match('/^\s*(\p{L}[\p{L}\p{M}.]*(?:\s+\p{L}[\p{L}\p{M}.]*){0,2})\s+(?:ko|ka|ki|को|का|की)\s+(?:ticket|tikat|tiket|seat|sit|bus|pnr|booking|टिकट|सिट|बस)(?![\p{L}\p{M}])/u', $l, $pm) === 1
            && ($question || $has('kun|kaha|kata|kahan|k|ke|kya|cha|chha|hai|kahile|kab|kati|dekhau|batau|batao|check'))) {
            $who = self::nameRef($pm[1]);
            require_once __DIR__ . '/personname.php';
            if ($who !== '' && mb_strlen($who) >= 3 && !PersonName::looksLikePlace($who)
                && !$has('aaja|aaj|bholi|parsi|kal|today|tomorrow|nepal|india|gujarat|aajako|bholiko')) {
                return '__paxfind:' . $who;
            }
        }
        // "Surat dekhi Rupaidiha bhada kati ho?": the fare board
        if ($has('bhada|bhaada|bhadaa|fare|kiraya|kiraaya|kiraye|भाडा|किराया') || ($has('kati\s*parcha|kati\s*lagcha|kitna\s*lagega|kitna\s*lagta|how\s*much') && $has('surat|baroda|mehsana|ahmedabad|rajkot|vapi|bharuch|kamrej|rupaidiha|nepalgunj|nepal|per\s*seat|ek\s*jana|1\s*jana'))) {
            return '__fare';
        }
        if ($has('balance|balanse|ballance|belance|blance|balnce|bal|wallet|commission|comission|commision|kamisan|kamishan|कमिसन|कमीशन|ब्यालेन्स|बैलेंस|बैलेन्स')) {
            $ref = self::memberRef($text);
            return 'balance' . ($ref !== '' ? ' ' . $ref : '');
        }
        if ($has('statement|stmt|passbook|ledger|khata|स्टेटमेन्ट')) {
            $ref = self::memberRef($text);
            return 'statement' . ($ref !== '' ? ' ' . $ref : '');
        }
        if ($has('portal|login|log\s*in|sign\s*in|signin|password|पासवर्ड|लगइन|पोर्टल|लग\s*इन')) {
            return 'portal';
        }
        if ($has('visiting|visiting\s*card|business\s*card|भिजिटिङ|विजिटिंग')) {
            return 'visiting card' . ($has('back|pachadi|पछाडि|पीछे') ? ' back' : '');
        }
        if ($has('id\s*card|idcard|id|आईडी|आइडी|parichay\s*patra|परिचय\s*पत्र') && !$has('switch|badal\w*|change')
            && !$has('upi\s*id|email\s*id|mail\s*id|gmail\s*id|txn\s*id|transaction\s*id|user\s*id|login\s*id')) {
            if (preg_match('/^\s*(.{2,40}?)\s*(?:ko|ka|ki|lai|को|का|की|लाई)\s*(?:id\s*card|idcard|id|आईडी|आइडी)/u', $l, $m) === 1) {
                $head = self::nameRef($m[1]);
                if ($head !== '') {
                    return 'id ' . $head . ($has('back|pachadi|पछाडि|पीछे') ? ' back' : '');
                }
            }
            if ($has('mero|my|mera|pathau\w*|pathai\w*|deu|dinu|dinus|chahiyo|chahiye|send|bhej\w*|dekhau|de|do')) {
                return 'id card' . ($has('back|pachadi|पछाडि|पीछे') ? ' back' : '');
            }
        }
        // "Sunil ko number deu", "Ramesh agent ko phone number k ho?", "सुनिलको नम्बर दिनुस्", "manager ko number"
        if (preg_match('/^\s*(.{2,40}?)\s*(?:ko|ka|ki|को|का|की)?\s*(?:phone\s*number|mobile\s*number|contact\s*number|whatsapp\s*number|number|nambar|nmbr|phone|mobile|contact|नम्बर|नंबर|फोन|मोबाइल)(?:\s|$)/u', $l, $m) === 1) {
            $head = self::nameRef($m[1]);
            if ($head !== '') {
                return 'number ' . $head;
            }
        }
        if (($has('khali|khaali|available|bacheko|bachyo|baki|baaki|खाली|बाँकी') && $has('seat|seats|sit|सिट|सीट|berth'))
            || $has('kati\s*seat|kitni\s*seat|kitne\s*seat|कति\s*सिट|timetable|time\s*table')) {
            return '__local';
        }
        if ($has('bus|buses|gadi|gaadi|गाडी|बस') && !$has('seat|sit|सिट|ticket|tikat|टिकट|khali')
            && $has('kati\s*baje|baje|time|samay|chhut\w*|chut\w*|nikal\w*|kati\s*ota|kati\s*wata|kati\s*vata|list|kun\s*kun|kati|kitne|cha|chha|hai|xa|aaja|aaj|bholi|today|tomorrow|kal|number|details|kaha|kata|kahan|location|live|pugyo|बजे|कति|छ')) {
            return 'bus' . ($day === date('Y-m-d', strtotime('+1 day')) ? ' bholi' : '');
        }
        if ($has('passengers?|pasengers?|passangers?|pasangers?|passenger\s*list|yatri|yatru|yatriharu|yatruharu|यात्रु|यात्री|manifest')) {
            return 'passengers ' . ($day !== '' ? $day : date('Y-m-d'));
        }
        /* "aaja ko ticket book garnu cha", "bholi ko ticket chahiyo": a booking, one question at a time (seeded with the
           day) — before the day report below took "aaja" (round 2, agent sim). */
        if ($has('tickets?|tikat|tiket|tkt|seats?|टिकट|सिट') && $has('book|booking|chahiyo|chaiyo|chahiye|chaiye|chahincha|katnu|kaatnu|katna|kaatna|katne|kaatne|banaunu|banana|nikalnu|बुक|चाहियो|चाहिए|काट्नु')
            && !$has('mero|my|mera|meri|dekhau|dikhao|show|list|haru|becheko|sold|cancel|radda')) {
            return '__book' . ($day !== '' ? ':' . $day : '');
        }
        if (($has('how\s*many|kitne|kitna|kati|kati\s*ota|how\s*much') && $has('sold|sell|bike|bika|bikyo|katyo|kateko|becheko|bechyo|becha|beche|bechi') && $has('aaj|aaja|today|आज') )) {
            return 'aaja';                                    // "how many tickets sold today?" is the day's report
        }
        if ($has('tickets?|tikat|tiket|टिकट') && $has('dekhau|dekhaunu|dekhaideu|dekha|dikhao|dikha|show|list|haru|becheko|bechya|bechyeko|mero|my|mera|meri')
            && !$has('pathau\w*|pathai\w*|resend|feri|pheri|bhej\w*|send|book|kaat\w*|kat\w*|chahiyo|chahiye|chaiyo')) {
            return 'tickets' . ($day !== '' ? ' ' . $day : '');
        }
        if (($has('aaj|aaja|aajako|aja|आज|आजको|today|todays') && $has('bikri|बिक्री|sale|sales|sell|selling|sold|bike|bika|bikey|bechyo|becheko|becha|beche|hisab|hisaab|हिसाब|report|cash|nagad|collection|tickets?|टिकट|kati|kitne|kitna|uthyo|kamai|कति|bhayo|भयो'))
            || ($has('how\s*many\s*tickets?|kitne\s*tickets?|kati\s*tickets?|kati\s*ota\s*ticket') && $has('sold|sell|bike|bika|katyo|kateko|becheko|bechyo|bikyo'))
            || $has('mero\s*bikri|my\s*sales?|mero\s*sale')) {
            return 'aaja';
        }
        return null;
    }

    /**
     * The amount a money sentence names: beside rs / ₹ / rupaya first, else the first number that is not a date
     * ("26 Oct", "15 tarik"), a time, a seat count, a member code, a phone or the payment reference. '' = none.
     */
    private static function amountIn(string $l, string $ref = ''): string
    {
        $s = ' ' . mb_strtolower(self::asciiDigits($l)) . ' ';
        if ($ref !== '') {
            $s = str_ireplace($ref, ' ', $s);
        }
        $months = 'jan\w*|feb\w*|mar\w*|apr\w*|may|jun\w*|jul\w*|aug\w*|sep\w*|oct\w*|nov\w*|dec\w*|kartik|mangsir|असोज|कार्तिक|मंसिर';
        $s = (string) preg_replace('/(?<![a-z\d])shg[\s-]*(?:[a-z][\s-]*)?\d{1,6}(?:-\d+)*/u', ' ', $s);                 // member codes, PNRs
        $s = (string) preg_replace('/(?<![\p{L}\d])(?:utr|ref|reference|txn|rrn|transaction)(?:\s*(?:id|no|number))?\s*[:#-]?\s*[a-z]{0,4}\d{6,}/u', ' ', $s);
        $s = (string) preg_replace('/\+?\d[\d\s-]{8,}\d/u', ' ', $s);                                                   // phones, references
        $s = (string) preg_replace('/\d{1,2}:\d{2}/u', ' ', $s);                                                         // times
        $s = (string) preg_replace('/(?<![\d,])\d{1,2}\s*(?:st|nd|rd|th)?\s*(?:tarik\w*|tarikh\w*|gate|date|' . $months . '|baje|बजे|din|dina|days?|mahina|month\w*|ghanta|hours?|minute\w*|min|seats?|sit|tickets?|tikat|tkt|jana|jan|log|berth|सिट|टिकट|जना|गते|तारिख)(?![\p{L}])/u', ' ', $s);
        $s = (string) preg_replace('/(?<![\p{L}])(?:' . $months . ')\s*\d{1,2}(?![\d,])/u', ' ', $s);
        if (preg_match('/(?:rs\.?|₹|inr|rupees?|rupaya|rupaiya|रु\.?)\s*([\d,]{2,9})(?![\d])/u', $s, $m) === 1
            || preg_match('/(?<![\d,])([\d,]{2,9})\s*(?:rs|₹|rupees?|rupaya|rupaiya|rupaye|रुपैयाँ|रुपये|\/-)/u', $s, $m) === 1) {
            $v = str_replace(',', '', $m[1]);
            return ctype_digit($v) && (int) $v > 0 ? (string) (int) $v : '';
        }
        // "2,500" is one number; "1800, utr …" is 1800 and a clause comma (round 3)
        if (preg_match_all('/(?<!\d)(?<!\d,)(\d{1,3}(?:,\d{2,3})+|\d{2,7})(?!\d|,\d)/u', $s, $all) > 0) {
            foreach ($all[1] as $v) {
                $v = str_replace(',', '', $v);
                if ((int) $v >= 10) {
                    return (string) (int) $v;
                }
            }
        }
        return '';
    }

    /** A member named in a sentence: a code (SHG-0024, SHG-S-0006), a mobile, or "<name> ko balance". '' = none. */
    private static function memberRef(string $text): string
    {
        $l = mb_strtolower(self::asciiDigits(trim($text)));
        if (preg_match('/(?<![a-z\d])(shg[\s-]*(?:[a-z][\s-]*)?\d{1,5})(?![\d-])/u', $l, $m) === 1) {
            return strtoupper((string) preg_replace('/\s+/', '', $m[1]));
        }
        if (preg_match('/(?<!\d)(\+?\d[\d\s-]{8,14}\d)(?!\d)/', $l, $m) === 1 && strlen((string) preg_replace('/\D/', '', $m[1])) >= 10) {
            return (string) preg_replace('/\D/', '', $m[1]);
        }
        $l = trim((string) preg_replace('/[?？!.,;:।]+/u', ' ', $l));
        if (preg_match('/^\s*(.{2,40}?)\s*(?:ko|ka|ki|को|का|की)\s+(?:balance|balanse|bal|wallet|commission|kamisan|statement|stmt|hisab|khata|passbook|ledger|कमिसन|ब्यालेन्स|बैलेंस)/u', $l, $m) === 1) {
            return self::nameRef($m[1]);
        }
        if (preg_match('/^(?:balance|bal|wallet|commission|statement|stmt|ledger|passbook)\s+([\p{L}][\p{L}\p{M}\s.]{1,40})$/u', $l, $m) === 1) {
            return self::nameRef($m[1]);
        }
        return '';
    }

    /** "Ramesh agent" → "ramesh", "सुनिलको" → "sunil"; '' for "mero", "bus", "account" … (not a person). */
    private static function nameRef(string $s): string
    {
        $s = trim(mb_strtolower($s));
        $s = (string) preg_replace('/(को|का|की|ले|लाई)$/u', '', $s);
        if (preg_match('/\p{Devanagari}/u', $s) === 1) {
            $s = self::deva2latin($s);
        }
        $skip = ['agent', 'agents', 'ejent', 'staff', 'counter', 'sir', 'ji', 'jee', 'dai', 'daju', 'bhai', 'didi', 'saheb', 'sahab', 'ko', 'ka', 'ki',
            'ma', 'le', 'lai', 'the', 'of', 'k', 'ke', 'kya', 'what', 'is', 'are', 'please', 'plz', 'pls', 'batao', 'dekhau', 'deu', 'dinu', 'dinus',
            'kati', 'kitna', 'cha', 'chha', 'hai', 'ho', 'bhayo', 'check', 'herna', 'hernu', 'najik', 'nearest', 'hamro', 'hajur', 'yo', 'tyo', 'ani', 'and',
            // round 3 (owner sim: "maile Ramesh lai 400 pathaye UTR …" looked for a member "maile ramesh"; "approve Ramesh ko …")
            'maile', 'maine', 'mai', 'main', 'hamile', 'humne', 'hami', 'ham', 'i', 'we', 'approve', 'aprove', 'approved', 'pass', 'paid', 'pay',
            'sent', 'gave', 'to', 'mr', 'mrs', 'shri', 'ok', 'okay'];
        $bad = ['mero', 'my', 'mera', 'meri', 'bus', 'gadi', 'gaadi', 'seat', 'ticket', 'tickets', 'pnr', 'office', 'company', 'account', 'bank',
            'aadhar', 'aadhaar', 'sabai', 'sab', 'all', 'passenger', 'yatri', 'customer', 'driver', 'conductor', 'helper', 'whatsapp', 'mobile',
            'phone', 'number', 'id', 'card', 'aaja', 'bholi', 'today', 'your', 'tapai', 'tapain', 'apna', 'apka',
            // round 3: money words and days are never a member's name
            'cash', 'nagad', 'upi', 'paisa', 'paise', 'payment', 'commission', 'kamisan', 'withdraw', 'withdrawal', 'payout', 'jama', 'jamma',
            'deposit', 'rs', 'rupaya', 'rupees', 'utr', 'ref', 'txn', 'reference', 'transaction', 'kal', 'parsi', 'hijo', 'tomorrow', 'yesterday',
            'aajako', 'bholiko', 'mahina', 'month', 'week', 'hapta', 'it', 'this', 'that', 'him', 'her', 'them', 'usko', 'uslai', 'unlai', 'tyaslai'];
        $words = array_values(array_filter(preg_split('/[^\p{L}\p{M}\d-]+/u', $s) ?: [], static fn(string $w): bool => $w !== '' && !in_array($w, $skip, true) && !(ctype_digit($w) && strlen($w) < 6)));
        foreach ($words as $w) {
            if (in_array($w, $bad, true)) {
                return '';
            }
        }
        return implode(' ', array_slice($words, 0, 3));
    }

    /** A Devanagari name in Latin letters, close enough to find "Sunil" from "सुनिल". */
    private static function deva2latin(string $s): string
    {
        $cons = ['क' => 'k', 'ख' => 'kh', 'ग' => 'g', 'घ' => 'gh', 'ङ' => 'n', 'च' => 'ch', 'छ' => 'chh', 'ज' => 'j', 'झ' => 'jh', 'ञ' => 'n',
            'ट' => 't', 'ठ' => 'th', 'ड' => 'd', 'ढ' => 'dh', 'ण' => 'n', 'त' => 't', 'थ' => 'th', 'द' => 'd', 'ध' => 'dh', 'न' => 'n', 'प' => 'p',
            'फ' => 'ph', 'ब' => 'b', 'भ' => 'bh', 'म' => 'm', 'य' => 'y', 'र' => 'r', 'ल' => 'l', 'व' => 'v', 'श' => 'sh', 'ष' => 'sh', 'स' => 's', 'ह' => 'h'];
        $vow = ['अ' => 'a', 'आ' => 'aa', 'इ' => 'i', 'ई' => 'i', 'उ' => 'u', 'ऊ' => 'u', 'ए' => 'e', 'ऐ' => 'ai', 'ओ' => 'o', 'औ' => 'au', 'ऋ' => 'ri'];
        $mat = ['ा' => 'a', 'ि' => 'i', 'ी' => 'i', 'ु' => 'u', 'ू' => 'u', 'े' => 'e', 'ै' => 'ai', 'ो' => 'o', 'ौ' => 'au', 'ृ' => 'ri', 'ं' => 'n', 'ँ' => 'n', 'ः' => 'h'];
        $ch = mb_str_split($s);
        $out = '';
        $n = count($ch);
        for ($i = 0; $i < $n; $i++) {
            $c = $ch[$i];
            if (isset($cons[$c])) {
                $out .= $cons[$c];
                $next = $ch[$i + 1] ?? '';
                if ($next === '्') {
                    $i++;
                    continue;
                }
                if (isset($mat[$next]) && !in_array($next, ['ं', 'ँ', 'ः'], true)) {
                    continue;                                         // the vowel sign follows
                }
                if ($next !== '' && (isset($cons[$next]) || isset($mat[$next]))) {
                    $out .= 'a';                                      // the inherent a, not at a word's end
                }
                continue;
            }
            $out .= $mat[$c] ?? $vow[$c] ?? ($c === '्' ? '' : $c);
        }
        return $out;
    }

    /**
     * The member a balance / statement asks about, when it is not the sender: the office gets theirs (audited);
     * anyone else is told it is their own only. null = the sender's own.
     *
     * @return array<string, mixed>|null an admins row, or a reply (with 'text')
     */
    private static function otherMember(string $text, array $member, array $ctx, string $lang): ?array
    {
        $ref = self::memberRef($text);
        if ($ref === '') {
            return null;
        }
        $hits = self::findMembers($ref);
        if ($hits === []) {
            // a name that matches nobody is just words ("balance batao"); a code / number that matches nobody is an answer
            return preg_match('/\d/', $ref) === 1 ? self::out(self::t('oid_none', $lang, ['q' => mb_substr($ref, 0, 40)])) : null;
        }
        if (count($hits) === 1 && (int) $hits[0]['id'] === (int) $member['id']) {
            return null;
        }
        if (!self::isOffice($ctx)) {
            return self::out(self::t('own_only', $lang));
        }
        if (count($hits) > 1) {
            $lines = [self::t('oid_many', $lang, ['n' => (string) count($hits), 'q' => mb_substr($ref, 0, 40)])];
            foreach (array_slice($hits, 0, 5) as $i => $r) {
                $lines[] = ($i + 1) . '. ' . ($r['idNo'] !== '' ? $r['idNo'] . ' · ' : '') . $r['name'] . ' · ' . $r['roleLabel'];
            }
            $lines[] = '';
            $lines[] = self::t('pick_code', $lang);
            return self::out(implode("\n", $lines));
        }
        $row = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) $hits[0]['id']]);
        if ($row === null) {
            return self::out(self::t('oid_none', $lang, ['q' => mb_substr($ref, 0, 40)]));
        }
        Logger::audit('agentpro.wa_office_wallet', 'admin', (string) $row['id'], null, ['by' => (int) ($ctx['adminId'] ?? 0), 'wa' => (string) ($ctx['phone'] ?? '')],
            'Office looked at a member\'s wallet on the WhatsApp desk');
        return $row;
    }

    /**
     * Round 3 (31 Oct 2026, owner's rule R3 — safety review #3 + owner sims: "Ramesh ko 5000 jama bhayo UTR …",
     * "SHG-0039 ko 400 withdraw approve gardeu UTR …", "I paid Ramesh 400, UTR …" from an office number previewed —
     * and on "ho" FILED — a deposit / withdrawal of the office member's OWN account, with the agent's reference):
     * WHOSE money a sentence is about decides where it goes, for every member.
     *   - another member named (a code, "X ko / X le / X lai", "paid X", "withdraw X …"): the office gets that
     *     member's open request (preview + CONFIRM) or a plain answer; anyone else is told member-to-member money goes
     *     through the office. Nobody's own account is touched.
     *   - a passenger's payment ("passenger le UTR … pathayo"): explained (the office verifies it), never filed.
     *   - nobody named: the office is asked whose payment it is (its own only by the typed "jama … UTR …" /
     *     "withdraw …"); a member goes on to their own (payIn / withdraw, whose previews name the payer).
     * Only ever a preview or an answer — the money moves on a later CONFIRM, exactly like "approve 12 UTR…".
     */
    private static function moneyRoute(string $text, array $member, array $ctx, string $lang): ?array
    {
        $l = mb_strtolower(self::asciiDigits(trim($text)));
        // the typed commands with a request number (approve 12 …, jama ok 12 …) and PNR lines are read as typed
        if (preg_match('/^(?:approve|aprove|aproove|approv\w*|apprve|aprv|apporve|wd\s*pay|wd\s*approve|reject|rejct|rejet|rject|reejct|decline|declin|wd\s*reject)\s*#?\d{1,9}(?!\d)/u', $l) === 1
            || preg_match('/^(?:jama|jamma|जम्मा|deposit|dep)\s*(?:ok|okay|confirm|approve|yes|ho|हो|no|reject|decline|hoina|होइन)\s*#?\d/u', $l) === 1
            || preg_match('/shg-?\s?\d{4}-?\s?\d{3,6}/u', $l) === 1) {
            return null;
        }
        $has = static fn(string $re): bool => preg_match('/(?<![\p{L}\p{M}])(?:' . $re . ')(?![\p{L}\p{M}])/u', $l) === 1;
        $toThem = $has('withdraw\w*|payout|approve|aprove|approv\w*|pass|paid|pay|pathaidiye|pathaideko|pathaidiyo|pathaidiye|pathaye|pathaeko|pathayeko|pathayo|transfer\w*|bheja|bhej\s*diya|bhej\s*diye|diye|diyo|dieko|dinu|gave|sent|gardiye|gardiyo|gardie|gardieko|kar\s*diya|kar\s*diye|पठाइदिएँ|पठाएँ|दिएँ')
            || ($has('nikal\w*|निकाल\w*') && !$has('tickets?|tikat|tiket|tkt|seats?|sit|bus|buses|gadi|gaadi|baje|time|samay|kahile|kab|टिकट|सिट|बस|गाडी|बजे'));
        $fromThem = $has('jama|jamma|deposit|bujhaye\w*|bujhayo|bujhaeko|aayo|aaeko|aako|milyo|tiryo|tireko|tirisake\w*|जम्मा|आयो|बुझायो');
        if (!$toThem && !$fromThem) {
            return null;
        }
        require_once __DIR__ . '/wapayproof.php';
        $utr = WaPayProof::refFrom($text, (string) ($ctx['phone'] ?? ''));
        $amount = (float) self::amountIn($l, $utr);
        if ($amount <= 0 && $utr === '' && !$has('paisa|paise|rupees?|rupaya|rs|inr|jama|jamma|deposit|payment|bhuktani|withdraw\w*|payout|commission|kamisan|cash|nagad|upi|bank|neft|imps|rtgs|पैसा|रुपैयाँ|जम्मा|भुक्तानी|नगद')) {
            return null;
        }
        // a sentence about a ticket / seat / form / card / number is no money unless a reference is in it
        if ($utr === '' && $has('tickets?|tikat|tiket|tkt|seats?|sit|form|forms|farm|card|id|photo|pdf|document|kagaj|aavedan|number|nambar|nmbr|टिकट|सिट|फारम|नम्बर|कार्ड')) {
            return null;
        }
        $office = self::isOffice($ctx);
        $question = str_contains($l, '?') || self::isQuestion($l) || $has('ki\s+nai|ki\s+hoina|hola|kya');
        // a passenger's / customer's payment: the office verifies it — never the member's own deposit
        if ($has('passengers?|pasenger\w*|customers?|costumer\w*|yatri|yatru|grahak|पेसेन्जर|यात्रु|यात्री|ग्राहक')) {
            return self::out(self::t('pay_pax_note', $lang, ['link' => appUrl('admin/payments.php'), 'phone' => Settings::officePhone()]));
        }
        $ref = self::moneyWhoRef($l);
        $hits = [];
        // money going TO somebody ("Kamal lai 400 pathaye", "paid X", "approve X") is never a payment to the company
        $toSomebody = $has('lai|लाई|to|approve|aprove|approv\w*|pass|paid|gave|sent|pathaidiye|pathaideko|pathaidiyo|withdraw\w*|payout');
        if ($ref !== '') {
            $found = self::findMembers($ref);
            $hits = array_values(array_filter($found, static fn(array $h): bool => (int) $h['id'] !== (int) $member['id']));
            if ($hits === [] && ($found !== [] || (!$office && !$toSomebody))) {
                // the sender named themselves, or a member wrote about a non-member ("Ram Thapa ko 1500 jama gareko UTR …":
                // the member's own payment — its preview names the payer)
                $ref = '';
            }
        }
        if ($ref === '') {
            if (!$office) {
                return null;                                          // a member's own payment / withdrawal: payIn / withdraw
            }
            if (preg_match('/^(?:jama|jamma|जम्मा|deposit|dep|withdraw\w*|payout|nikal\w*|निकाल\w*)(?![\p{L}\p{M}])/u', $l) === 1) {
                /* Round 4 (R3 — safety review A6: "jama 900 UTR … (Kamal)", "deposit 900 for Kamal UTR …" and "withdraw
                   700 for Kamal" from the office were previewed — and on "ho" FILED — on the office member's OWN account):
                   the office's own typed command is only the bare one ("jama 5000 UTR …", "withdraw 700"). Any other word
                   in it names somebody: that member's request is used, or the office is asked whose money it is. */
                $named = self::ownCmdNames($l, $utr);
                if ($named === null) {
                    return null;                                      // the bare typed command: the office's own (a preview)
                }
                foreach ($named as $w) {
                    $found = array_values(array_filter(self::findMembers($w), static fn(array $h): bool => (int) $h['id'] !== (int) $member['id']));
                    if ($found !== []) {
                        $ref = $w;
                        $hits = $found;
                        break;
                    }
                }
                if ($ref === '') {
                    return self::out(self::t('office_money_whose', $lang));
                }
            } elseif ($question) {
                return null;                                          // a question (canon answers it)
            } else {
                return self::out(self::t('office_money_whose', $lang));
            }
        }
        if (!$office) {
            // member-to-member money ("Kamal lai 400 pathaye utr …"): the office does it — never the sender's own deposit
            return self::out(self::t('money_via_office', $lang, ['phone' => Settings::officePhone()]));
        }
        if ($hits === []) {
            return self::out(self::t('oid_none', $lang, ['q' => mb_substr($ref, 0, 40)]));
        }
        $deposit = $fromThem && !$has('withdraw\w*|payout|approve|aprove|approv\w*|pass|paid|pathaidiye|pathaideko|pathaidiyo|lai|लाई|to')
            || ($has('le|ne|ले|ने') && $has('pathayo|pathaeko|pathayeko|tiryo|tireko|bheja|diyo|dieko') && !$has('lai|लाई|withdraw\w*|payout|approve|approv\w*|pass'));
        if ($question) {
            return self::memberMoneyStatus($hits, $lang);
        }
        return self::officeMoneyFor($hits, $deposit, $amount, $utr, $ref, $ctx, $lang);
    }

    /**
     * Round 4 (R3, review A6): the words of an office's typed "jama … / deposit … / withdraw …" that are not the bare
     * command's own (amount, UTR, pay mode, "mero", "gareko" …). null = none (the office's own command); else the words
     * that may name a member, in order (connectors like "for", "ko", "lai" left out — they only show somebody was named).
     *
     * @return list<string>|null
     */
    private static function ownCmdNames(string $l, string $utr): ?array
    {
        static $own = ['jama', 'jamma', 'jammaa', 'deposit', 'dep', 'withdraw', 'withdrawal', 'withdrawl', 'payout', 'nikal', 'nikalnu', 'nikalne', 'nikaldo',
            'nikaldeu', 'nikalidinu', 'rs', 'inr', 'rupees', 'rupee', 'rupaya', 'rupaiya', 'utr', 'ref', 'refno', 'reference', 'txn', 'txnid', 'transaction',
            'id', 'no', 'number', 'num', 'cash', 'nagad', 'upi', 'bank', 'neft', 'imps', 'rtgs', 'online', 'gpay', 'phonepe', 'paytm', 'esewa', 'khalti',
            'ma', 'bata', 'through', 'via', 'by', 'mero', 'my', 'own', 'maile', 'i', 'ho', 'ok', 'okay', 'please', 'plz', 'pls', 'gare', 'gareko', 'garyo',
            'garchu', 'garnu', 'gardeu', 'gardinu', 'garidinu', 'garidinus', 'kiya', 'kar', 'karo', 'diya', 'done', 'security', 'handover', 'bujhaye',
            'bujhaeko', 'chu', 'chhu', 'cha', 'chha', 'hai', 'request', 'amount', 'commission', 'kamisan', 'paisa', 'paise', 'payment', 'tireko', 'tiryo',
            'pathaye', 'pathaeko', 'sent', 'paid', 'transfer', 'transferred', 'today', 'aaja', 'aaj', 'hijo', 'yesterday', 'account', 'ac', 'wallet',
            'from', 'मेरो', 'जम्मा', 'निकाल', 'रु', 'बैंक', 'नगद', 'गरेको', 'गरें'];
        static $link = ['for', 'of', 'to', 'ko', 'ka', 'ki', 'le', 'ne', 'lai', 'ke', 'liye', 'lagi', 'the', 'a', 'an', 'and', 'ra', 'ani', 'को', 'ले', 'लाई'];
        $s = $utr !== '' ? str_ireplace(mb_strtolower($utr), ' ', $l) : $l;
        $words = preg_split('/[^\p{L}\p{M}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $extra = array_values(array_filter($words, static fn(string $w): bool => !in_array($w, $own, true) && !(str_starts_with($w, 'withdraw') || str_starts_with($w, 'nikal'))));
        if ($extra === []) {
            return null;
        }
        return array_values(array_filter($extra, static fn(string $w): bool => !in_array($w, $link, true) && mb_strlen($w) >= 3));
    }

    /** The other member a money sentence names: a code, "X ko / le / lai / ne", "paid X", "withdraw X …". '' = nobody. */
    private static function moneyWhoRef(string $l): string
    {
        if (preg_match('/(?<![a-z\d])(shg[\s-]*(?:[a-z][\s-]*)?\d{1,5})(?![\d-])/u', $l, $m) === 1) {
            return strtoupper((string) preg_replace('/\s+/', '', $m[1]));
        }
        $l = trim((string) preg_replace('/[?？!.,;:।"\'()]+/u', ' ', $l));
        if (preg_match_all('/((?:\p{L}[\p{L}\p{M}.]*\s+){0,2}\p{L}[\p{L}\p{M}.]*)\s+(?:ko|ka|ki|le|ne|lai|को|का|की|ले|ने|लाई)(?![\p{L}\p{M}])/u', $l, $all) > 0) {
            foreach ($all[1] as $cand) {
                $who = self::nameRef($cand);
                if ($who !== '' && mb_strlen($who) >= 3) {
                    return $who;
                }
            }
        }
        if (preg_match('/(\p{Devanagari}{2,}?)(?:को|ले|लाई)(?![\p{L}\p{M}])/u', $l, $m) === 1) {
            $who = self::nameRef($m[1]);
            if ($who !== '' && mb_strlen($who) >= 3) {
                return $who;
            }
        }
        if (preg_match('/(?<![\p{L}\p{M}])(?:paid|pay|sent|send|gave|transferred|transfer)\s+(?:rs\.?\s*|₹\s*|inr\s*)?(?:\d[\d,]*\s+)?(?:to\s+)?(\p{L}[\p{L}.]*(?:\s+\p{L}[\p{L}.]*)?)/u', $l, $m) === 1
            || preg_match('/(?<![\p{L}\p{M}])to\s+(\p{L}[\p{L}.]*(?:\s+\p{L}[\p{L}.]*)?)/u', $l, $m) === 1) {
            $who = self::nameRef($m[1]);
            if ($who !== '' && mb_strlen($who) >= 3) {
                return $who;
            }
        }
        // "withdraw Ramesh 400", "approve Ramesh" — a word after the command counts only when it IS a member
        if (preg_match('/^(?:withdraw\w*|payout|jama|jamma|deposit|nikal\w*|approve|aprove|approv\w*)\s+(\p{L}[\p{L}.]*(?:\s+\p{L}[\p{L}.]*)?)/u', $l, $m) === 1) {
            $who = self::nameRef($m[1]);
            if ($who !== '' && mb_strlen($who) >= 3 && self::findMembers($who) !== []) {
                return $who;
            }
        }
        return '';
    }

    /** "Ramesh ko cash diyo?" / "Ramesh ko jama aayo?": that member's open payments and withdrawals — nothing changes. */
    private static function memberMoneyStatus(array $hits, string $lang): array
    {
        $lines = [];
        foreach (array_slice($hits, 0, 3) as $h) {
            $aid = (int) $h['id'];
            $deps = AgentPro::openDeposits($aid);
            $wds = AgentWallet::openPayoutRequests($aid);
            $bits = [];
            foreach (array_slice($deps, 0, 3) as $d) {
                $bits[] = '💵 #' . (int) $d['id'] . ' ' . inr((float) $d['amount']) . ((string) ($d['utr'] ?? '') !== '' ? ' (UTR …' . substr((string) $d['utr'], -4) . ')' : '');
            }
            foreach (array_slice($wds, 0, 3) as $w) {
                $bits[] = '💸 #' . (int) $w['id'] . ' ' . inr((float) $w['amount']);
            }
            $lines[] = '👤 ' . (string) $h['name'] . ((string) $h['idNo'] !== '' ? ' (' . $h['idNo'] . ')' : '') . ': '
                . ($bits !== [] ? implode(' · ', $bits) : self::t('adm_member_nothing', $lang));
        }
        return self::out(implode("\n", $lines) . "\n\n" . self::t('adm_member_money_tail', $lang));
    }

    /** The office acting on one named member's open payment (deposit) or withdrawal (payout): a preview or an answer. */
    private static function officeMoneyFor(array $hits, bool $deposit, float $amount, string $utr, string $name, array $ctx, string $lang): array
    {
        if (count($hits) > 1) {
            // two "Ramesh"es: the one with an open payment / withdrawal (of that amount) is meant
            $openOf = static fn(array $h): array => $deposit ? AgentPro::openDeposits((int) $h['id']) : AgentWallet::openPayoutRequests((int) $h['id']);
            $open = array_values(array_filter($hits, static function (array $h) use ($openOf, $amount): bool {
                foreach ($openOf($h) as $r) {
                    if ($amount <= 0 || abs((float) $r['amount'] - $amount) < 0.01) {
                        return true;
                    }
                }
                return false;
            }));
            if ($open === [] && $amount > 0) {
                // owner sim: a wrong amount made it "none of them has a request" while one Ramesh had one — any open one counts
                $open = array_values(array_filter($hits, static fn(array $h): bool => $openOf($h) !== []));
            }
            if ($open === []) {
                $list = implode(', ', array_map(static fn(array $h): string => (string) $h['name'] . ((string) $h['idNo'] !== '' ? ' (' . $h['idNo'] . ')' : ''), array_slice($hits, 0, 5)));
                return self::out(self::t($deposit ? 'adm_dep_none_many' : 'adm_wd_none_many', $lang, ['list' => $list, 'v' => $amount > 0 ? inr($amount) : '']));
            }
            $hits = $open;
        }
        if (count($hits) > 1) {
            $lines = [self::t('oid_many', $lang, ['n' => (string) count($hits), 'q' => mb_substr($name, 0, 40)])];
            foreach (array_slice($hits, 0, 5) as $i => $r) {
                $lines[] = ($i + 1) . '. ' . ($r['idNo'] !== '' ? $r['idNo'] . ' · ' : '') . $r['name'];
            }
            return self::out(implode("\n", $lines) . "\n\n" . self::t($deposit ? 'adm_dep_tail' : 'adm_wd_tail', $lang, ['link' => appUrl('admin/team.php?tab=money')]));
        }
        $aid = (int) $hits[0]['id'];
        $who = ['name' => (string) $hits[0]['name'], 'code' => self::codeOf($aid)];
        if ($deposit) {
            $open = AgentPro::openDeposits($aid);
            $fit = $utr !== '' ? array_values(array_filter($open, static fn(array $d): bool => self::refKey((string) ($d['utr'] ?? '')) === self::refKey($utr))) : [];
            if ($fit === []) {
                $fit = $amount > 0 ? array_values(array_filter($open, static fn(array $d): bool => abs((float) $d['amount'] - $amount) < 0.01)) : $open;
            }
            if (count($fit) !== 1) {
                return self::out(self::t($fit === [] ? 'adm_dep_none_for' : 'adm_dep_tail', $lang, $who + ['v' => $amount > 0 ? inr($amount) : '', 'link' => appUrl('admin/team.php?tab=money')]));
            }
            return self::adminDepositPreview((int) $fit[0]['id'], 'confirmed', '', $ctx, $lang);
        }
        $open = AgentWallet::openPayoutRequests($aid);
        if ($open === []) {
            return self::out(self::t('adm_wd_none_for', $lang, $who));
        }
        $req = $open[0];
        if ($amount > 0 && abs((float) $req['amount'] - $amount) > 0.009) {
            return self::out(self::t('adm_wd_amount_differs', $lang, ['id' => (string) (int) $req['id'], 'v' => inr((float) $req['amount']), 'asked' => inr($amount)]));
        }
        if ($utr === '') {
            return self::out(self::t('adm_wd_needref', $lang, ['id' => (string) (int) $req['id']]));
        }
        return self::adminPayPreview((int) $req['id'], $utr, $ctx, $lang);
    }

    /** A timetable / free-seat / bus question from a member: the local brain answers it the way it answers the office. */
    private static function localAnswer(string $text, array $ctx): ?array
    {
        try {
            require_once __DIR__ . '/walocal.php';
            /* Round 4 (31 Oct 2026, owner's rule R5 — safety review B1: a counter that is not OTP-linked sent "khali seat
               kati cha bholi SHG-0043" and got that agent's personal mobile and home address; "… ani Qwzxbik agent ko
               number pani" another agent's, matched on a town word — around the desk's own directory lock): from the
               desk the local brain answers ONLY the free-seat / timetable question, and with the office's view only for
               the OTP-linked office. Everything else in such a line goes to the desk's own (locked) commands. */
            $officeView = self::isOffice($ctx);
            $who = ['role' => $officeView ? 'admin' : (in_array((string) $ctx['role'], ['staff', 'admin'], true) ? 'staff' : 'customer'),
                    'adminId' => (int) ($ctx['adminId'] ?? 0), 'scopeAdminId' => $ctx['scopeAdminId'] ?? null, 'name' => (string) ($ctx['name'] ?? ''),
                    'phone' => (string) ($ctx['phone'] ?? ''), 'intl' => '', 'admin' => $ctx['admin'] ?? null,
                    'intents' => ['seats_left', 'timetable']];
            $r = WaLocal::answer('+91' . (string) ($ctx['phone'] ?? ''), $text, $who);
            return $r !== null ? self::out((string) $r['text'], $r['media'] ?? null) : null;
        } catch (Throwable $e) {
            Logger::warning('WaLocal from the desk failed: ' . $e->getMessage(), [], 'whatsapp');
            return null;
        }
    }

    /** "SBI 30123456789 IFSC SBIN0001234" → "SBI …6789" — owner: nobody's bank / UPI numbers on WhatsApp. */
    public static function maskAccount(string $s): string
    {
        $s = (string) preg_replace('/\bIFSC\s*[:#-]?\s*[A-Z]{4}0[A-Z0-9]{6}\b/i', '', $s);
        $s = (string) preg_replace('/\b[A-Z]{4}0[A-Z0-9]{6}\b/', '', $s);
        $s = (string) preg_replace_callback('/\d[\d\s-]{3,}\d/', static fn(array $m): string => '…' . substr((string) preg_replace('/\D/', '', $m[0]), -4), $s);
        $s = (string) preg_replace_callback('/\b([a-z0-9._-]{1,2})[a-z0-9._-]*@([a-z0-9.-]+)\b/i', static fn(array $m): string => $m[1] . '…@' . $m[2], $s);
        return trim((string) preg_replace('/\s{2,}/', ' ', $s));
    }

    /* ---------------- help ---------------- */

    private static function help(array $member, string $lang): array
    {
        $code = AgentPro::member((int) $member['id'], false)['idNo'] ?? '';
        $sells = in_array((string) $member['role'], self::SELLERS, true);
        $office = self::officeMember($member) && Settings::getBool('ap_wa_admin_on', true);
        return self::out(self::t($sells ? 'help_seller' : 'help_member', $lang, [
            'name' => explode(' ', (string) $member['full_name'])[0], 'code' => $code !== '' ? ' (' . $code . ')' : '',
            'company' => Settings::getString('company_name', APP_NAME),
        ]) . ($office ? "\n" . self::t('help_admin_line', $lang) : ''));
    }

    /* ---------------- the office's desk ---------------- */

    private static function adminHelp(string $lang): array
    {
        $c = AgentPro::counts();
        return self::out(self::t('admin_help', $lang, ['apps' => (string) $c['applications'], 'wd' => (string) $c['withdrawals'],
            'dep' => (string) $c['deposits'], 'link' => appUrl('admin/team.php')]));
    }

    private static function adminPending(string $lang): array
    {
        $c = AgentPro::counts();
        $lines = [self::t('adm_pending', $lang, ['apps' => (string) $c['applications'], 'wd' => (string) $c['withdrawals'],
            'dep' => (string) $c['deposits'], 'kyc' => (string) $c['kycWaiting'], 'pay' => (string) $c['pendingPay'],
            't' => (string) $c['todayTickets'], 'bus' => (string) $c['liveBuses']])];
        $lines[] = '';
        $lines[] = self::t('adm_pending_tail', $lang);
        return self::out(implode("\n", $lines));
    }

    private static function adminJoins(string $lang): array
    {
        $rows = AgentPro::applications('submitted', 15);
        $lines = [self::t('adm_joins_head', $lang, ['n' => (string) count($rows)])];
        foreach ($rows as $a) {
            $who = trim((string) ($a['org_name'] ?? '') !== '' ? (string) $a['org_name'] . ' · ' . (string) $a['full_name'] : (string) $a['full_name']);
            $role = (string) ($a['kind'] ?? 'agent') === 'staff' ? (string) ($a['designation'] ?: $a['role_wanted'] ?: 'staff') : 'agent';
            $ph = normalisePhone((string) ($a['whatsapp'] ?: $a['phone']));
            $lines[] = '📝 ' . $a['ref'] . ' · ' . ($who !== '' ? $who : '—') . ' · ' . $role
                . ($ph !== '' ? ' · ' . AgentPro::displayPhone(AgentPro::intlDigits($ph)) : '')
                . ((string) ($a['city'] ?? '') !== '' ? ' · ' . $a['city'] : '')
                . ' · ' . formatDate(substr((string) ($a['submitted_at'] ?: $a['created_at']), 0, 10), 'j M')
                . (!empty($a['wa_verified_at']) ? ' ✅WA' : '');
        }
        if ($rows === []) {
            $lines[] = self::t('dir_none', $lang);
        }
        $lines[] = '';
        $lines[] = self::t('adm_joins_tail', $lang, ['link' => appUrl('admin/team.php?tab=approvals')]);
        return self::out(implode("\n", $lines));
    }

    private static function adminWithdrawals(string $lang): array
    {
        $rows = AgentWallet::openPayoutRequests(null);
        $lines = [self::t('adm_wd_head', $lang, ['n' => (string) count($rows)])];
        foreach (array_slice($rows, 0, 20) as $r) {
            $aid = (int) $r['agent_admin_id'];
            $p = AgentWallet::profile($aid);
            $to = self::maskAccount(trim((string) ($p['payout_method'] ?? '') . ' ' . (string) ($p['payout_account'] ?? '')));   // 31 Oct 2026: never the full account
            $code = self::codeOf($aid);
            $lines[] = '💸 #' . (int) $r['id'] . ' · ' . ((string) ($r['agent_name'] ?? '') !== '' ? (string) $r['agent_name'] : 'member #' . $aid)
                . ($code !== '' ? ' (' . $code . ')' : '') . ' · *' . inr((float) $r['amount']) . '*'
                . ' · ' . formatDate(substr((string) $r['created_at'], 0, 10), 'j M')
                . ($to !== '' ? "\n    → " . $to : '')
                . "\n    " . self::t('adm_wd_due', $lang, ['v' => inr(AgentWallet::balances($aid)['commission'])]);
        }
        if ($rows === []) {
            $lines[] = self::t('dir_none', $lang);
        }
        $lines[] = '';
        $lines[] = self::t('adm_wd_tail', $lang);
        return self::out(implode("\n", $lines));
    }

    /** "approve 12 UTR430987651234": show exactly what will be recorded, then wait for CONFIRM. */
    private static function adminPayPreview(int $id, string $ref, array $ctx, string $lang): array
    {
        $req = Database::fetch('SELECT r.*, a.full_name AS agent_name FROM agent_payout_requests r LEFT JOIN admins a ON a.id = r.agent_admin_id WHERE r.id = :i', ['i' => $id]);
        if ($req === null || (string) $req['status'] !== 'open') {
            return self::out(self::t('adm_wd_notopen', $lang, ['id' => (string) $id, 's' => strtoupper((string) ($req['status'] ?? '—'))]));
        }
        // "UTR430987651234" stays as typed; only a spelled-out label ("utr: 4309…", "ref 4309…") is dropped.
        $typed = trim($ref);
        $ref = (string) preg_replace('/^(?:ref|utr|txn|rrn)\.?(?:\s*[:#-]\s*|\s+)(?=\S)/i', '', $typed);
        /* 31 Oct 2026 (owner sim: "approve 176 UTR998877665544 confirm" became the ref "UTR998877665544confirm", which
           payoutRefUsed() would not have matched to the same transfer): the reference is the first word only. */
        $ref = (string) (preg_split('/\s+/u', trim($ref))[0] ?? '');
        $ref = trim((string) preg_replace('/[^A-Za-z0-9\/\-_.]/', '', $ref));
        /* Round 3 (owner sim: "approve 298 utr number 5802…" / "reference 5802…" / "transaction id 5802…" / "payment UTR
           5802…" / "UTR no 5802…" stored the WORD "number" / "reference" … as the payout reference, and the next payout
           was refused as a duplicate): a first word with no digits is a label — the digits come from the same reader the
           sentences use (WaPayProof::refFrom). */
        if (preg_match('/\d{4,}/', $ref) !== 1) {
            require_once __DIR__ . '/wapayproof.php';
            $ref = WaPayProof::refFrom($typed, (string) ($ctx['phone'] ?? ''));
            if ($ref === '' && preg_match('/(?<!\d)(\d{10,22})(?!\d)/', self::asciiDigits($typed), $dm) === 1 && !WaPayProof::phoneLike($dm[1])) {
                $ref = $dm[1];                                       // "approve 12 payment 5802…" — the one long number
            }
        }
        if (mb_strlen($ref) < 5 || mb_strlen($ref) > 60) {
            return self::out(self::t('adm_wd_needref', $lang, ['id' => (string) $id]));
        }
        if (self::payoutRefUsed($ref)) {
            return self::out(self::t('adm_wd_refused', $lang, ['ref' => $ref]));
        }
        $aid = (int) $req['agent_admin_id'];
        $p = AgentWallet::profile($aid);
        $to = self::maskAccount(trim((string) ($p['payout_method'] ?? '') . ' ' . (string) ($p['payout_account'] ?? '')));
        self::setPending($ctx['phone'], 'wd_pay', ['id' => $id, 'ref' => $ref, 'amount' => (float) $req['amount'], 'agent' => $aid]);
        return self::out(self::t('adm_pay_confirm', $lang, ['id' => (string) $id, 'name' => (string) $req['agent_name'], 'code' => self::codeOf($aid),
            'v' => inr((float) $req['amount']), 'to' => $to !== '' ? $to : '—', 'ref' => $ref]));
    }

    private static function adminRejectPreview(int $id, string $note, array $ctx, string $lang): array
    {
        $req = Database::fetch('SELECT r.*, a.full_name AS agent_name FROM agent_payout_requests r LEFT JOIN admins a ON a.id = r.agent_admin_id WHERE r.id = :i', ['i' => $id]);
        if ($req === null || (string) $req['status'] !== 'open') {
            return self::out(self::t('adm_wd_notopen', $lang, ['id' => (string) $id, 's' => strtoupper((string) ($req['status'] ?? '—'))]));
        }
        $note = Security::clean($note, 200);
        if (mb_strlen($note) < 3) {
            return self::out(self::t('adm_wd_needwhy', $lang, ['id' => (string) $id]));
        }
        self::setPending($ctx['phone'], 'wd_decline', ['id' => $id, 'note' => $note, 'amount' => (float) $req['amount']]);
        return self::out(self::t('adm_reject_confirm', $lang, ['id' => (string) $id, 'name' => (string) $req['agent_name'],
            'v' => inr((float) $req['amount']), 'note' => $note]));
    }

    /**
     * Record the payout exactly like the Team page: one transaction, the
     * agent's admins row then the request locked, the ledger payout with the
     * bank reference, the request marked paid with it. An already-decided
     * request (another tab, another phone) changes nothing.
     */
    private static function adminPayDo(array $p, ?array $member, array $ctx, string $lang): array
    {
        if ($member === null || !self::isOffice($ctx)) {
            return self::out(self::t('no_permission', $lang));
        }
        $id = (int) ($p['id'] ?? 0);
        $ref = (string) ($p['ref'] ?? '');
        $by = (int) $member['id'];
        try {
            $req = Database::fetch('SELECT agent_admin_id FROM agent_payout_requests WHERE id = :i', ['i' => $id]);
            if ($req === null) {
                throw new RuntimeException(self::t('adm_wd_notopen', $lang, ['id' => (string) $id, 's' => '—']));
            }
            $aid = (int) $req['agent_admin_id'];
            $lid = Database::transaction(static function () use ($id, $aid, $by, $ref, $p, $lang, $ctx): int {
                Database::fetch('SELECT id FROM admins WHERE id = :a FOR UPDATE', ['a' => $aid]);
                $row = Database::fetch('SELECT * FROM agent_payout_requests WHERE id = :i FOR UPDATE', ['i' => $id]);
                if ($row === null || (string) $row['status'] !== 'open') {
                    throw new RuntimeException(self::t('adm_wd_notopen', $lang, ['id' => (string) $id, 's' => strtoupper((string) ($row['status'] ?? '—'))]));
                }
                if (abs((float) $row['amount'] - (float) ($p['amount'] ?? -1)) > 0.009) {
                    throw new RuntimeException(self::t('adm_wd_changed', $lang, ['id' => (string) $id]));
                }
                // One bank transfer pays one request, however the reference was typed here or on the Team page.
                if (self::payoutRefUsed($ref)) {
                    throw new RuntimeException(self::t('adm_wd_refused', $lang, ['ref' => $ref]));
                }
                $lid = AgentWallet::record($aid, 'payout', (float) $row['amount'], [
                    'note' => 'Withdrawal request #' . $id . ' · WhatsApp desk', 'ref' => $ref, 'by' => $by, 'request_id' => $id,
                ]);
                AgentWallet::decidePayoutRequest($id, 'paid', $by, 'Paid from the WhatsApp desk (+' . $ctx['phone'] . ')', $lid);
                return $lid;
            }, 1);
        } catch (RuntimeException $e) {
            return self::out('⚠️ ' . $e->getMessage());
        }
        $voucher = AgentWallet::voucherFor($lid);
        Logger::audit('agentpro.wa_payout_paid', 'admin', (string) $aid, ['request' => $id, 'status' => 'open'],
            ['request' => $id, 'ledger' => $lid, 'ref' => $ref, 'by' => $by, 'wa' => $ctx['phone']], 'Withdrawal paid from the WhatsApp desk');
        $told = self::tellMemberSent($aid, '💸 ' . inr((float) $p['amount']) . ' पठाइयो / paid to you. Ref ' . $ref
            . ($voucher !== '' && self::refKey($voucher) !== self::refKey($ref) ? ' · ' . $voucher : ''));
        $text = self::t('adm_paid', $lang, ['id' => (string) $id, 'v' => inr((float) $p['amount']),
            'voucher' => $voucher !== '' && self::refKey($voucher) !== self::refKey($ref) ? $voucher . ' · ' : '', 'ref' => $ref,
            'told' => self::t($told ? 'told_yes' : 'told_no', $lang)]);
        self::markDone($ctx['phone'], 'wd_pay', $text);
        return self::out($text);
    }

    private static function adminRejectDo(array $p, ?array $member, array $ctx, string $lang): array
    {
        if ($member === null || !self::isOffice($ctx)) {
            return self::out(self::t('no_permission', $lang));
        }
        $id = (int) ($p['id'] ?? 0);
        try {
            AgentPro::declineWithdrawal($id, (int) $member['id'], (string) $p['note']);
        } catch (RuntimeException $e) {
            return self::out('⚠️ ' . $e->getMessage());
        }
        Logger::audit('agentpro.wa_payout_declined', 'payout_request', (string) $id, ['status' => 'open'],
            ['status' => 'declined', 'note' => (string) $p['note'], 'by' => (int) $member['id'], 'wa' => $ctx['phone']], 'Withdrawal declined from the WhatsApp desk');
        $text = self::t('adm_rejected', $lang, ['id' => (string) $id]);
        self::markDone($ctx['phone'], 'wd_decline', $text);
        return self::out($text);
    }

    /** AgentPro::tellMember(), and whether WhatsApp actually took the message (message_logs 'sent'). */
    private static function tellMemberSent(int $adminId, string $text): bool
    {
        $before = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM message_logs', [], 0);
        AgentPro::tellMember($adminId, $text);
        return self::sentSince($before, (string) (AgentPro::member($adminId, false)['phone'] ?? ''));
    }

    /** Did a WhatsApp message to this number go out (status sent) after message_logs row $before? */
    private static function sentSince(int $before, string $phone): bool
    {
        $d = normalisePhone($phone);
        if ($d === '') {
            return false;
        }
        try {
            return Database::exists("SELECT 1 FROM message_logs WHERE id > :i AND channel = 'whatsapp' AND status = 'sent' AND to_number LIKE :p LIMIT 1",
                ['i' => $before, 'p' => '%' . $d]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** A bank reference without its spelling: "UTR 4309-8765", "utr43098765" and "43098765" are one payment. */
    private static function refKey(string $ref): string
    {
        $k = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $ref));
        $bare = (string) preg_replace('/^(?:UTR|REF|TXN|RRN|UPI|NEFT|IMPS|RTGS)+(?=[0-9A-Z])/', '', $k);
        return $bare !== '' ? $bare : $k;
    }

    /** Is this bank reference already on a payout in the ledger (from here or from the Team page)? */
    private static function payoutRefUsed(string $ref): bool
    {
        $key = self::refKey($ref);
        if (strlen($key) < 5) {
            return false;
        }
        $rows = Database::fetchAll("SELECT ref FROM agent_ledger WHERE entry_type = 'payout' AND ref LIKE :l LIMIT 200",
            ['l' => '%' . substr($key, -6) . '%']);
        foreach ($rows as $r) {
            if (self::refKey((string) $r['ref']) === $key) {
                return true;
            }
        }
        return false;
    }

    /* ---------------- payments members made (ap_deposit_requests) ---------------- */

    private static function adminDeposits(string $lang): array
    {
        $rows = AgentPro::openDeposits(null);
        $lines = [self::t('adm_dep_head', $lang, ['n' => (string) count($rows)])];
        foreach (array_slice($rows, 0, 20) as $d) {
            $aid = (int) $d['admin_id'];
            $code = self::codeOf($aid);
            $sec = (string) $d['purpose'] === 'security';
            $lines[] = '💵 #' . (int) $d['id'] . ' · ' . ((string) ($d['member_name'] ?? '') !== '' ? (string) $d['member_name'] : 'member #' . $aid)
                . ($code !== '' ? ' (' . $code . ')' : '') . ' · *' . inr((float) $d['amount']) . '*'
                . ' · ' . strtoupper((string) $d['method']) . ((string) ($d['utr'] ?? '') !== '' ? ' ' . $d['utr'] : '')
                . ' · ' . formatDate(substr((string) $d['created_at'], 0, 10), 'j M')
                . "\n    " . ($sec ? self::t('adm_dep_security', $lang) : self::t('adm_dep_held', $lang, ['v' => inr(AgentWallet::balances($aid)['cash'])]))
                . (!empty($d['proof_path']) ? ' · 📎' : '');
        }
        if ($rows === []) {
            $lines[] = self::t('dir_none', $lang);
        }
        $lines[] = '';
        $lines[] = self::t('adm_dep_tail', $lang, ['link' => appUrl('admin/team.php?tab=money')]);
        return self::out(implode("\n", $lines));
    }

    /** "jama ok 12" / "jama no 12 not in the bank": show what will happen, then wait for CONFIRM. */
    private static function adminDepositPreview(int $id, string $decision, string $note, array $ctx, string $lang): array
    {
        $d = Database::fetch('SELECT d.*, a.full_name AS member_name FROM ap_deposit_requests d LEFT JOIN admins a ON a.id = d.admin_id WHERE d.id = :i', ['i' => $id]);
        if ($d === null || (string) $d['status'] !== 'open') {
            return self::out(self::t('adm_dep_notopen', $lang, ['id' => (string) $id, 's' => strtoupper((string) ($d['status'] ?? '—'))]));
        }
        $note = Security::clean($note, 200);
        if ($decision === 'declined' && mb_strlen($note) < 3) {
            return self::out(self::t('adm_dep_needwhy', $lang, ['id' => (string) $id]));
        }
        $aid = (int) $d['admin_id'];
        $sec = (string) $d['purpose'] === 'security';
        if ($decision === 'confirmed' && !$sec && (float) $d['amount'] > AgentWallet::balances($aid)['cash'] + 0.009) {
            return self::out(self::t('adm_dep_toomuch', $lang, ['id' => (string) $id, 'v' => inr(AgentWallet::balances($aid)['cash'])]));
        }
        self::setPending($ctx['phone'], $decision === 'confirmed' ? 'dep_confirm' : 'dep_decline',
            ['id' => $id, 'decision' => $decision, 'note' => $note, 'amount' => (float) $d['amount']]);
        $vars = ['id' => (string) $id, 'name' => (string) ($d['member_name'] ?? ''), 'code' => self::codeOf($aid), 'v' => inr((float) $d['amount']),
                 'how' => strtoupper((string) $d['method']) . ((string) ($d['utr'] ?? '') !== '' ? ' ' . $d['utr'] : ''),
                 'what' => $sec ? self::t('adm_dep_security', $lang) : self::t('adm_dep_handover', $lang), 'note' => $note !== '' ? $note : '—'];
        return self::out(self::t($decision === 'confirmed' ? 'adm_dep_confirm' : 'adm_dep_decline', $lang, $vars));
    }

    /**
     * Decide a payment exactly like the Team page: one transaction, the
     * member's admins row then the request locked; confirmed = the ledger
     * entry (cash handed over, or a security deposit), declined = the reason.
     * An already-decided request (another tab, another phone) writes nothing.
     */
    private static function adminDepositDo(array $p, ?array $member, array $ctx, string $lang): array
    {
        if ($member === null || !self::isOffice($ctx)) {
            return self::out(self::t('no_permission', $lang));
        }
        $id = (int) ($p['id'] ?? 0);
        $decision = (string) ($p['decision'] ?? '') === 'confirmed' ? 'confirmed' : 'declined';
        $note = Security::clean((string) ($p['note'] ?? ''), 255);
        $by = (int) $member['id'];
        try {
            if ($decision === 'declined' && $note === '') {
                throw new RuntimeException(self::t('adm_dep_needwhy', $lang, ['id' => (string) $id]));
            }
            $pre = Database::fetch('SELECT admin_id FROM ap_deposit_requests WHERE id = :i', ['i' => $id]);
            if ($pre === null) {
                throw new RuntimeException(self::t('adm_dep_notopen', $lang, ['id' => (string) $id, 's' => '—']));
            }
            $aid = (int) $pre['admin_id'];
            $row = Database::transaction(static function () use ($id, $aid, $decision, $by, $note, $p, $lang): array {
                Database::fetch('SELECT id FROM admins WHERE id = :a FOR UPDATE', ['a' => $aid]);
                $row = Database::fetch('SELECT * FROM ap_deposit_requests WHERE id = :i FOR UPDATE', ['i' => $id]);
                if ($row === null || (string) $row['status'] !== 'open') {
                    throw new RuntimeException(self::t('adm_dep_notopen', $lang, ['id' => (string) $id, 's' => strtoupper((string) ($row['status'] ?? '—'))]));
                }
                $amount = (float) $row['amount'];
                if (abs($amount - (float) ($p['amount'] ?? -1)) > 0.009) {
                    throw new RuntimeException(self::t('adm_wd_changed', $lang, ['id' => (string) $id]));
                }
                $ledgerId = null;
                if ($decision === 'confirmed') {
                    $ref = (string) ($row['utr'] ?? '');
                    if ((string) $row['purpose'] === 'security') {
                        AgentWallet::recordDeposit($aid, $amount, $by, 'Security deposit (' . $row['method'] . ') · WhatsApp desk', $ref);
                    } else {
                        $held = AgentWallet::balances($aid)['cash'];
                        if ($amount > $held + 0.009) {
                            throw new RuntimeException(self::t('adm_dep_toomuch', $lang, ['id' => (string) $id, 'v' => inr($held)]));
                        }
                        $ledgerId = AgentWallet::record($aid, 'cash_handover', $amount, [
                            'note' => 'Paid by ' . strtoupper((string) $row['method']) . ' · WhatsApp desk' . ($note !== '' ? ' · ' . $note : ''),
                            'ref'  => $ref, 'by' => $by,
                        ]);
                    }
                }
                $n = Database::update('ap_deposit_requests', [
                    'status' => $decision, 'decided_by' => $by ?: null, 'decided_at' => date('Y-m-d H:i:s'),
                    'decided_note' => $note !== '' ? $note : null, 'ledger_id' => $ledgerId,
                ], "id = :i AND status = 'open'", ['i' => $id]);
                if ($n !== 1) {
                    throw new RuntimeException(self::t('adm_dep_notopen', $lang, ['id' => (string) $id, 's' => '—']));
                }
                return $row + ['_ledger' => $ledgerId];
            }, 1);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            return self::out(preg_match('/^(?:⚠|✍)/u', $msg) === 1 ? $msg : '⚠️ ' . $msg);
        }
        Logger::audit('agentpro.wa_deposit_' . $decision, 'admin', (string) $aid, ['request' => $id, 'status' => 'open'],
            ['request' => $id, 'status' => $decision, 'amount' => (float) $row['amount'], 'ledger' => $row['_ledger'], 'note' => $note, 'by' => $by, 'wa' => $ctx['phone']],
            'Payment ' . $decision . ' from the WhatsApp desk');
        $told = self::tellMemberSent($aid, $decision === 'confirmed'
            ? '✅ ' . inr((float) $row['amount']) . ' प्राप्त भयो, धन्यवाद। / received, thank you.' . (!empty($row['utr']) ? ' Ref ' . $row['utr'] : '')
            : '⚠️ ' . inr((float) $row['amount']) . ' भुक्तानी भेटिएन / not matched: ' . $note);
        $text = self::t($decision === 'confirmed' ? 'adm_dep_done' : 'adm_dep_declined', $lang, ['id' => (string) $id, 'v' => inr((float) $row['amount']),
            'told' => self::t($told ? 'told_yes' : 'told_no', $lang)]);
        self::markDone($ctx['phone'], 'dep_' . $decision, $text);
        return self::out($text);
    }

    /* ---------------- my tickets, the portal ---------------- */

    private static function myTickets(array $member, array $ctx, string $lang, string $date): array
    {
        if (!in_array($ctx['role'], ['staff', 'admin'], true)) {
            return self::out(self::t('no_permission', $lang));
        }
        require_once __DIR__ . '/aitools.php';
        $r = AiTools::run('my_sales', ['date' => $date, 'limit' => 12], $ctx);
        if (!$r['ok']) {
            return self::out('⚠️ ' . $r['say']);
        }
        $sales = (array) ($r['data']['sales'] ?? []);
        $lines = [self::t($date !== '' ? 'tk_head_date' : 'tk_head', $lang, ['d' => $date !== '' ? formatDate($date, 'D, j M') : '', 'n' => (string) count($sales)])];
        foreach ($sales as $s) {
            $lines[] = '🎫 ' . $s['pnr'] . ' · ' . strtoupper((string) $s['status']) . ($s['paid'] ? ' ✅' : '')
                . "\n    " . $s['dateLabel'] . ' · ' . $s['pickup'] . ' · ' . $s['seats'] . ' 💺 · ' . $s['totalLabel']
                . "\n    👤 " . $s['name'] . ' ' . $s['phone'];
        }
        if ($sales === []) {
            $lines[] = self::t('tk_none', $lang);
        }
        $lines[] = '';
        $lines[] = self::t('card_tail', $lang);
        return self::out(implode("\n", $lines));
    }

    /**
     * Where to sign in: the member portal (agent code + mobile + a WhatsApp
     * OTP, or fingerprint), or the admin sign-in for office staff. No secret
     * travels in the link — the OTP is asked for on the page itself.
     */
    private static function portal(array $member, string $lang): array
    {
        $id = (int) $member['id'];
        $code = AgentWallet::isAgentRow($id) ? AgentWallet::agentCodeLabel($id) : '';
        $reg = normalisePhone((string) $member['phone']);
        $mask = $reg !== '' ? str_repeat('•', max(0, strlen($reg) - 4)) . substr($reg, -4) : '—';
        $me = appUrl('me/');
        if (Auth::oneDoorOn()) {
            // 30 Oct 2026 (owner: "login sabai ko lagi eutai"): one sign-in page for every role
            return self::out(self::t('portal_one', $lang, ['url' => appUrl('admin/login.php'), 'me' => $me,
                'id' => $code !== '' ? $code : (string) $member['username'], 'mob' => $mask]));
        }
        if ($code !== '' && (string) $member['role'] === 'agent') {
            return self::out(self::t('portal_agent', $lang, ['me' => $me, 'code' => $code, 'mob' => $mask]));
        }
        return self::out(self::t('portal_staff', $lang, ['me' => $me, 'admin' => appUrl('admin/login.php'), 'user' => (string) $member['username']]));
    }

    /* ---------------- a booking, one question at a time ---------------- */

    /** "ticket" → route, date, pickup, seats, passenger — then the same quote + CONFIRM as a one-line sale. */
    private static function bookStart(array $member, array $ctx, string $lang, array $seed = []): array
    {
        if ($ctx['role'] === 'member' || !in_array((string) $member['role'], self::SELLERS, true) || !Settings::getBool('ap_wa_sell', true)) {
            return self::out(self::t('no_permission', $lang));
        }
        $b = array_intersect_key($seed, array_flip(['direction', 'date', 'boarding', 'seats', 'name', 'phone', 'country', 'names', 'gender', 'pay', 'agentCode']));
        return self::bookNext($b, $member, $ctx, $lang, '');
    }

    /** @return list<array{key: string, label: string}> */
    private static function bookDirections(): array
    {
        require_once __DIR__ . '/quickticket.php';
        $out = [];
        foreach (QuickTicket::routes() as $r) {
            $d = (string) $r['direction'];
            if (!isset($out[$d])) {
                $out[$d] = ['key' => $d, 'label' => $r['from_city'] . ' → ' . $r['to_city']];
            }
        }
        return array_values($out);
    }

    /** @return list<string> pickup towns (display names) on the routes of one direction ('' = every route) */
    private static function bookPickups(string $direction): array
    {
        require_once __DIR__ . '/quickticket.php';
        require_once __DIR__ . '/boarding.php';
        $seen = [];
        foreach (QuickTicket::routes() as $r) {
            if ($direction !== '' && (string) $r['direction'] !== $direction) {
                continue;
            }
            foreach (Boarding::stopsFor((int) $r['id']) as $s) {
                $name = Boarding::stopDisplay((string) $s['name'])['name'];
                $k = Boarding::townKey($name);
                if ($k !== '' && !isset($seen[$k])) {
                    $seen[$k] = $name;
                }
            }
        }
        return array_slice(array_values($seen), 0, 20);
    }

    private static function bookNext(array $b, array $member, array $ctx, string $lang, string $prefix): array
    {
        $dirs = self::bookDirections();
        if ($dirs === []) {
            return self::out('⚠️ ' . self::t('bk_no_bus', $lang));
        }
        if (empty($b['direction']) && count($dirs) === 1) {
            $b['direction'] = $dirs[0]['key'];
        }
        $step = match (true) {
            empty($b['direction']) && empty($b['boarding']) => 'direction',
            empty($b['date'])                               => 'date',
            empty($b['boarding'])                           => 'pickup',
            (int) ($b['seats'] ?? 0) <= 0                   => 'seats',
            mb_strlen(trim((string) ($b['name'] ?? ''))) < 2
                || ((string) ($b['phone'] ?? '') === '' && !Settings::getBool('ap_wa_sale_seller_phone', true)) => 'who',
            default => 'quote',
        };
        if ($step === 'quote') {
            $sale = [
                'name' => (string) $b['name'], 'phone' => (string) $b['phone'], 'country' => (string) ($b['country'] ?? ''),
                'seats' => (int) $b['seats'], 'date' => (string) $b['date'], 'direction' => (string) ($b['direction'] ?? ''),
                'boarding' => (string) $b['boarding'], 'gender' => (string) ($b['gender'] ?? ''), 'pay' => (string) ($b['pay'] ?? ''),
                'names' => (array) ($b['names'] ?? []), 'agentCode' => (string) ($b['agentCode'] ?? ''),
            ];
            $r = self::saleQuote($sale, $member, $ctx, $lang);
            if (str_starts_with((string) $r['text'], '⚠️')) {
                // No bus can be sold like that (that day / that pickup): ask for another day, keep the rest.
                unset($b['date']);
                return self::bookNext($b, $member, $ctx, $lang, trim($prefix . "\n" . $r['text']));
            }
            return $prefix !== '' ? self::out($prefix . "\n\n" . $r['text'], $r['media'] ?? null) : $r;
        }
        $opts = [];
        $lines = [];
        switch ($step) {
            case 'direction':
                foreach ($dirs as $i => $d) {
                    $opts[] = $d['key'];
                    $lines[] = ($i + 1) . '. ' . $d['label'];
                }
                $q = self::t('bk_q_direction', $lang) . "\n" . implode("\n", $lines);
                break;
            case 'date':
                $opts = [date('Y-m-d'), date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+2 days'))];
                $q = self::t('bk_q_date', $lang, ['d1' => formatDate($opts[0], 'D j M'), 'd2' => formatDate($opts[1], 'D j M'), 'd3' => formatDate($opts[2], 'D j M')]);
                break;
            case 'pickup':
                $opts = self::bookPickups((string) ($b['direction'] ?? ''));
                $list = [];
                foreach ($opts as $i => $o) {
                    $list[] = ($i + 1) . '. ' . $o;
                }
                $q = self::t('bk_q_pickup', $lang) . "\n" . implode("\n", $list);
                break;
            case 'seats':
                $q = self::t('bk_q_seats', $lang, ['max' => (string) BookingService::maxSeatsFor(true)]);
                break;
            default:
                $q = self::t('bk_q_who', $lang, ['n' => (string) (int) ($b['seats'] ?? 1)]);
        }
        self::setPending($ctx['phone'], 'book', ['b' => $b, 'step' => $step, 'opts' => $opts]);
        $sofar = self::bookSoFar($b, $lang);
        return self::out(($prefix !== '' ? $prefix . "\n\n" : '') . ($sofar !== '' ? $sofar . "\n\n" : '') . $q . "\n\n" . self::t('bk_stop', $lang));
    }

    private static function bookSoFar(array $b, string $lang): string
    {
        $bits = [];
        if (!empty($b['date'])) { $bits[] = '📅 ' . formatDate((string) $b['date'], 'D, j M'); }
        if (!empty($b['boarding'])) { $bits[] = '📍 ' . $b['boarding']; }
        if ((int) ($b['seats'] ?? 0) > 0) { $bits[] = '💺 ' . (int) $b['seats']; }
        if (!empty($b['name'])) { $bits[] = '👤 ' . $b['name'] . (!empty($b['phone']) ? ' ' . $b['phone'] : ''); }
        if (!empty($b['agentCode'])) { $bits[] = '🤝 ' . $b['agentCode']; }
        return $bits !== [] ? '🎫 ' . implode(' · ', $bits) : '';
    }

    /** One answer in the booking conversation. null = not an answer (a command): the caller carries on. */
    private static function bookStep(array $pending, string $text, array $member, array $ctx, string $lang): ?array
    {
        $b = (array) ($pending['b'] ?? []);
        $step = (string) ($pending['step'] ?? '');
        $opts = array_values((array) ($pending['opts'] ?? []));
        $l = mb_strtolower(trim($text));
        // A command in the middle (help, balance, a PNR …) leaves the booking and runs the command.
        require_once __DIR__ . '/wapayproof.php';
        if (preg_match('/^(help|menu|madat|मद्दत|reset|naya|नयाँ|balance|statement|bus|office|tickets|portal|admin)$/u', $l) === 1
            || preg_match('/\bSHG-[A-Z0-9]+-/i', $text) === 1
            || WaPayProof::payTalk($text)) {                       // round 3 (R2): a payment line is never a booking answer
            self::clearPending($ctx['phone']);
            return null;
        }
        /* 31 Oct 2026 (counter sim: "company ko 1 seat bholi Surat, Hari 98…" typed while the questions of an
           earlier "SHG-0042 ko lagi 2 ticket" were open was merged into THAT booking — credited to SHG-0042, for a
           passenger "Company Hari Prasad"): a whole new sale line (seats and a day or pickup) starts afresh. */
        if (!in_array($step, ['seats'], true) && preg_match('/^\d{1,2}$/', trim(self::asciiDigits($text))) !== 1) {
            require_once __DIR__ . '/ticketbot.php';
            $fresh = TicketBot::parse($text);
            if ((int) $fresh['seats'] > 0 && ((string) $fresh['date'] !== '' || (string) $fresh['boarding'] !== '')
                && ((string) $fresh['phone'] !== '' || (string) $fresh['name'] !== '' || preg_match('/(?<![a-z\d])shg[\s-]*\d{1,4}(?![\d-])|company|office/iu', $text) === 1)) {
                self::clearPending($ctx['phone']);
                return null;
            }
        }
        // "ok" / "ho" / "CONFIRM" answers no question (never a passenger called "ok"): the same question again.
        if (preg_match(self::YES, $text) === 1) {
            return self::bookNext($b, $member, $ctx, $lang, self::t('bk_again', $lang));
        }
        require_once __DIR__ . '/quickticket.php';
        require_once __DIR__ . '/ticketbot.php';
        $t = trim(self::asciiDigits($text));
        $got = false;
        if (preg_match('/^\d{1,2}$/', $t) === 1) {
            $n = (int) $t;
            if (in_array($step, ['direction', 'date', 'pickup'], true) && $n >= 1 && $n <= count($opts)) {
                $b[$step === 'pickup' ? 'boarding' : $step] = $opts[$n - 1];
                $got = true;
            } elseif ($step === 'seats' && $n >= 1 && $n <= BookingService::maxSeatsFor(true)) {
                $b['seats'] = $n;
                $got = true;
            }
        }
        if (!$got) {
            require_once __DIR__ . '/quickticket.php';
            require_once __DIR__ . '/ticketbot.php';
            $p = TicketBot::parse($text);
            if ($step === 'date' && ($d = self::dateWord($text)) !== '') {
                $b['date'] = $d;
                $got = true;
            } elseif ((string) $p['date'] !== '') {
                $b['date'] = (string) $p['date'];
                $got = true;
            }
            if ($step === 'pickup' && !$got) {
                foreach ($opts as $o) {
                    if (Boarding::townKey($o) !== '' && (str_contains(Boarding::townKey($o), Boarding::townKey($text)) || str_contains(Boarding::townKey($text), Boarding::townKey($o)))
                        && mb_strlen(Boarding::townKey($text)) >= 3) {
                        $b['boarding'] = $o;
                        $got = true;
                        break;
                    }
                }
            }
            if ((string) $p['boarding'] !== '' && empty($b['boarding'])) {
                $b['boarding'] = (string) $p['boarding'];
                $got = true;
            }
            if ((string) $p['direction'] !== '' && empty($b['direction'])) {
                $b['direction'] = (string) $p['direction'];
                $got = true;
            }
            if ((int) $p['seats'] > 0 && ($step === 'seats' || preg_match('/seat|सिट|सीट|jana|जना|berth/iu', $text) === 1)) {
                $b['seats'] = (int) $p['seats'];
                $got = true;
            }
            if (preg_match('/^(?:names?|naam(?:haru)?|नामहरू|नाम)\s*[:\-]?\s*(.+)$/iu', trim($text), $m) === 1) {
                $list = array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|;|\band\b|\bra\b|र|और)\s*/u', (string) preg_replace('/\+?\d[\d\s-]{8,}/', ' ', $m[1])) ?: [])));
                if ($list !== []) {
                    $b['names'] = count($list) >= 2 ? $list : [];
                    $b['name'] = $list[0];
                    $got = true;
                }
            }
            if ((string) $p['phone'] !== '') {
                $b['phone'] = (string) $p['phone'];
                $b['country'] = (string) $p['country'];
                $got = true;
            }
            // 31 Oct 2026 (counter sim: "Surat bata, Ram Thapa 98…" at the route question dropped the name): a name
            // beside a mobile number is the passenger, whatever was asked
            if (($step === 'who' || (string) $p['phone'] !== '') && empty($b['names'])) {
                $nm = self::cleanName((string) $p['name']);
                if ($nm === '' && $step === 'who') {
                    $bare = trim((string) preg_replace('/\+?\d[\d\s-]{6,}\d/', ' ', $text));
                    if (preg_match('/^\p{L}[\p{L}\p{M} .]{1,60}$/u', $bare) === 1 && preg_match(self::YES, $bare) !== 1 && preg_match(self::NO, $bare) !== 1) {
                        $nm = $bare;
                    }
                }
                // round 3: the one name rule (never a pause / payment / chat word)
                require_once __DIR__ . '/personname.php';
                if ($nm !== '' && (PersonName::clean($nm) === '' || self::cleanName($nm) !== $nm)) {
                    $nm = '';
                }
                if (mb_strlen($nm) >= 2) {
                    $b['name'] = Security::clean($nm, 120);
                    $got = true;
                }
            }
            if ((string) $p['gender'] !== '') { $b['gender'] = (string) $p['gender']; }
            if ((string) $p['pay'] !== '') { $b['pay'] = (string) $p['pay']; }
        }
        return self::bookNext($b, $member, $ctx, $lang, $got ? '' : self::t('bk_again', $lang));
    }

    /* ---------------- applicant verification ---------------- */

    private static function joinVerify(string $fromRaw, string $code, string $text): array
    {
        $lang = 'ne';
        $app = AgentPro::reverseVerified($fromRaw, $code);
        if ($app === null) {
            return self::out(self::t('join_bad', $lang));
        }
        return self::out(self::t('join_ok', $lang, ['ref' => (string) $app['ref']]));
    }

    /* ---------------- LINK + OTP ---------------- */

    private static function linkStart(string $phone, string $arg, string $lang): array
    {
        if (!Security::rateLimit('ap_link', $phone, 5, 3600)) {
            return self::out(self::t('slow_down', $lang));
        }
        $adminId = null;
        $code = AgentWallet::resolveAgentCodeFromString(preg_match('/^\d{1,4}$/', $arg) ? 'SHG' . $arg : $arg);
        if ($code !== null) {
            $adminId = $code;
        } elseif (preg_match('/^\+?[\d\s-]{8,16}$/', $arg)) {
            $want = normalisePhone($arg);
            $hits = [];
            foreach (Database::fetchAll("SELECT id, phone FROM admins WHERE is_active = 1 AND phone IS NOT NULL AND phone <> ''") as $a) {
                if (normalisePhone((string) $a['phone']) === $want) {
                    $hits[] = (int) $a['id'];
                }
            }
            $adminId = count($hits) === 1 ? $hits[0] : null;
        }
        if ($adminId === null) {
            return self::out(self::t('link_unknown', $lang));
        }
        $a = Database::fetch('SELECT id, full_name, phone, is_active FROM admins WHERE id = :i', ['i' => $adminId]);
        if ($a === null || (int) $a['is_active'] !== 1 || normalisePhone((string) $a['phone']) === '') {
            return self::out(self::t('link_unknown', $lang));
        }
        $existing = Database::fetch('SELECT admin_id FROM ap_wa_links WHERE phone = :p AND revoked_at IS NULL', ['p' => $phone]);
        if ($existing !== null && (int) $existing['admin_id'] === $adminId) {
            return self::out(self::t('link_already', $lang, ['code' => self::codeOf($adminId)]));
        }
        $otp = Auth::issueOtp('aplink:' . $adminId, 'verify', 'whatsapp');
        if (!$otp['ok']) {
            return self::out((string) ($otp['error'] ?? self::t('error', $lang)));
        }
        require_once __DIR__ . '/notify.php';
        $reg = normalisePhone((string) $a['phone']);
        $msg = Settings::getString('company_name', APP_NAME) . "\n"
            . 'WhatsApp जोड्ने कोड / link code: *' . $otp['code'] . "*\n"
            . '+' . $phone . ' ले ' . self::codeOf($adminId) . ' जोड्न खोज्दैछ। तपाईं हो भने यो कोड त्यही WhatsApp मा पठाउनुहोस्; होइन भने कसैलाई नदिनुहोस्।';
        $cc = (string) (AgentWallet::profile($adminId)['country_code'] ?? '') === '977' ? 'NP' : 'IN';
        Notify::whatsapp($reg, $msg, null, $cc);
        if (Settings::getBool('sms_send_otp', false)) {
            Notify::sms($reg, $msg, $cc);
        }
        self::setPending($phone, 'link', ['adminId' => $adminId, 'tries' => 0]);
        Logger::audit('agentpro.link_otp', 'admin', (string) $adminId, null, ['wa' => $phone], 'WhatsApp link OTP sent to the registered mobile');
        return self::out(self::t('link_sent', $lang, ['tail' => substr($reg, -2), 'code' => self::codeOf($adminId)]));
    }

    private static function linkVerify(string $phone, string $code, array $pending, string $lang): array
    {
        $adminId = (int) ($pending['adminId'] ?? 0);
        $res = Auth::verifyOtp('aplink:' . $adminId, $code, 'verify');
        if (!$res['ok']) {
            $tries = (int) ($pending['tries'] ?? 0) + 1;
            if ($tries >= 5) {
                self::clearPending($phone);
            } else {
                self::setPending($phone, 'link', ['adminId' => $adminId, 'tries' => $tries]);
            }
            return self::out(self::t('link_bad_otp', $lang));
        }
        self::clearPending($phone);
        Database::run(
            'INSERT INTO ap_wa_links (phone, admin_id, via, verified_at, revoked_at) VALUES (:p, :a, \'otp\', NOW(), NULL)
             ON DUPLICATE KEY UPDATE admin_id = :a2, via = \'otp\', verified_at = NOW(), revoked_at = NULL',
            ['p' => $phone, 'a' => $adminId, 'a2' => $adminId]
        );
        Logger::audit('agentpro.wa_linked', 'admin', (string) $adminId, null, ['wa' => $phone], 'WhatsApp number linked by OTP');
        // Tell the registered mobile too: a link nobody expected is a warning sign.
        $a = Database::fetch('SELECT phone FROM admins WHERE id = :i', ['i' => $adminId]);
        if ($a !== null && normalisePhone((string) $a['phone']) !== $phone) {
            AgentPro::tellMember($adminId, '🔗 ' . self::codeOf($adminId) . ' अब WhatsApp +' . $phone . ' सँग जोडियो। तपाईंले नगर्नुभएको हो भने अफिसलाई तुरुन्त फोन गर्नुहोस्।');
        }
        $member = self::memberFor($phone);
        return $member !== null ? self::out(self::t('link_ok', $lang, ['code' => self::codeOf($adminId)]) . "\n\n" . self::help($member, $lang)['text'])
                                : self::out(self::t('link_ok', $lang, ['code' => self::codeOf($adminId)]));
    }

    private static function codeOf(int $adminId): string
    {
        $m = AgentPro::member($adminId, false);
        return $m !== null && $m['idNo'] !== '' ? (string) $m['idNo'] : (string) ($m['name'] ?? '');
    }

    /* ---------------- money ---------------- */

    /** $forOffice: the office looking at SOMEONE ELSE's wallet — third person, and the office's own next steps (round 2). */
    private static function balance(array $member, string $lang, bool $forOffice = false): array
    {
        $id = (int) $member['id'];
        $b = AgentWallet::balances($id);
        $s = AgentWallet::summary($id);
        $open = AgentWallet::openPayoutRequests($id);
        $dep = AgentPro::openDeposits($id);
        $who = ['name' => (string) $member['full_name'], 'code' => self::codeOf($id)];
        $lines = [self::t('bal_head', $lang, $who)];
        $lines[] = self::t($forOffice ? 'bal_commission_o' : 'bal_commission', $lang, $who + ['v' => inr($b['commission'])]);
        $lines[] = self::t($forOffice ? 'bal_cash_o' : 'bal_cash', $lang, $who + ['v' => inr($b['cash'])]);
        $lines[] = self::t('bal_month', $lang, ['v' => inr($s['earnedMonth'])]);
        if ($open !== []) {
            $lines[] = self::t('bal_open', $lang, ['v' => inr((float) $open[0]['amount']), 'd' => formatDate(substr((string) $open[0]['created_at'], 0, 10), 'j M')]);
        }
        if ($dep !== []) {
            $lines[] = self::t('bal_dep', $lang, ['n' => (string) count($dep), 'v' => inr(array_sum(array_map(static fn($d) => (float) $d['amount'], $dep)))]);
        }
        $lines[] = '';
        $lines[] = self::t($forOffice ? 'bal_tail_o' : 'bal_tail', $lang, $who);
        return self::out(implode("\n", $lines));
    }

    private static function statement(array $member, string $lang, int $days, bool $forOffice = false): array
    {
        $id = (int) $member['id'];
        $days = $days > 0 ? min(366, $days) : 30;
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $to = date('Y-m-d');
        $rows = AgentWallet::entriesBetween($id, $from, $to);
        $lines = [self::t('stmt_head', $lang, ['days' => (string) $days, 'name' => (string) $member['full_name']])];
        $labels = self::entryLabels($lang);
        if ($forOffice) {
            $labels['payout'] = ['en' => 'paid out', 'hi' => 'भुगतान किया', 'ne' => 'भुक्तानी गरियो'][$lang] ?? 'भुक्तानी गरियो';
        }
        foreach (array_slice(array_reverse($rows), 0, 12) as $r) {
            $amt = (float) $r['amount'];
            $acct = (string) $r['account'];
            $sign = $acct === 'cash' ? ($amt >= 0 ? '🔸' : '✅') : ($amt >= 0 ? '🟢 +' : '🔴 −');
            $lines[] = date('d M', (int) strtotime((string) $r['created_at'])) . '  ' . $sign . inr(abs($amt)) . '  '
                . ($labels[(string) $r['entry_type']] ?? (string) $r['entry_type'])
                . (!empty($r['ref']) ? ' · ' . $r['ref'] : '');
        }
        if ($rows === []) {
            $lines[] = self::t('stmt_empty', $lang);
        }
        $b = AgentWallet::balances($id);
        $who = ['name' => (string) $member['full_name'], 'code' => self::codeOf($id)];
        $lines[] = '';
        $lines[] = self::t($forOffice ? 'bal_commission_o' : 'bal_commission', $lang, $who + ['v' => inr($b['commission'])]);
        $lines[] = self::t($forOffice ? 'bal_cash_o' : 'bal_cash', $lang, $who + ['v' => inr($b['cash'])]);
        try {
            require_once __DIR__ . '/statement.php';
            require_once __DIR__ . '/reportpdf.php';
            require_once __DIR__ . '/pdf.php';
            $pdf = Statement::agentStatementPdf($id, $from, $to, $id);
            $lines[] = '';
            $lines[] = self::t('stmt_pdf', $lang, ['url' => (string) $pdf['url']]);
        } catch (Throwable $e) {
            Logger::warning('Statement PDF for WhatsApp failed: ' . $e->getMessage(), [], 'agent');
            $lines[] = '';
            $lines[] = '📄 ' . appUrl('me/#wallet');
        }
        return self::out(implode("\n", $lines));
    }

    /** @return array<string, string> */
    private static function entryLabels(string $lang): array
    {
        return match ($lang) {
            'en' => ['commission' => 'commission', 'commission_void' => 'commission reversed', 'payout' => 'paid to you',
                     'cash_due' => 'cash collected', 'cash_handover' => 'cash handed over', 'adjustment' => 'adjustment'],
            'hi' => ['commission' => 'कमीशन', 'commission_void' => 'कमीशन वापस', 'payout' => 'आपको भुगतान',
                     'cash_due' => 'नकद लिया', 'cash_handover' => 'नकद जमा किया', 'adjustment' => 'समायोजन'],
            default => ['commission' => 'कमिसन', 'commission_void' => 'कमिसन फिर्ता', 'payout' => 'तपाईंलाई भुक्तानी',
                        'cash_due' => 'नगद उठाइयो', 'cash_handover' => 'नगद बुझाइयो', 'adjustment' => 'मिलान'],
        };
    }

    private static function withdraw(array $member, array $ctx, string $lang, float $amount): array
    {
        $id = (int) $member['id'];
        $due = AgentWallet::balances($id)['commission'];
        if ($due <= 0) {
            return self::out(self::t('wd_nothing', $lang));
        }
        if ($amount <= 0) {
            $amount = $due;
        }
        if ($amount > $due + 0.009) {
            return self::out(self::t('wd_too_much', $lang, ['v' => inr($due)]));
        }
        if (AgentWallet::openPayoutRequests($id) !== []) {
            return self::out(self::t('wd_open', $lang));
        }
        $p = AgentWallet::profile($id);
        $to = self::maskAccount(trim((string) ($p['payout_method'] ?? '') . ' ' . (string) ($p['payout_account'] ?? '')));
        self::setPending($ctx['phone'], 'withdraw', ['amount' => $amount]);
        return self::out(self::t('wd_confirm', $lang, ['v' => inr($amount), 'to' => $to !== '' ? $to : self::t('wd_to_office', $lang), 'who' => self::payerLabel($member)]));
    }

    /** "Bikash Agent (SHG-0005)": whose wallet a money preview is for (owner's rule R3 — the preview always names them). */
    private static function payerLabel(array $member): string
    {
        $name = trim((string) ($member['full_name'] ?? ''));
        $code = self::codeOf((int) $member['id']);
        return $name . ($code !== '' && $code !== '—' && mb_strtolower($code) !== mb_strtolower($name) ? ' (' . $code . ')' : '');
    }

    /**
     * "jama 1500 UTR 4309…" — round 2 (31 Oct 2026, safety review: a sentence filed a deposit at once, with a
     * passenger's mobile as the UTR and "26 Oct" as ₹26): only a PREVIEW here; the request is filed on a later
     * "ho" (confirm() 'deposit'). The reference must be explicit (WaPayProof::refFrom), the amount is read with
     * amountIn(). No reference: how to pay (or, for cash handed over, that the office confirms it).
     */
    private static function payIn(array $member, string $text, string $lang, array $ctx = []): array
    {
        $id = (int) $member['id'];
        require_once __DIR__ . '/wapayproof.php';
        $t = self::asciiDigits($text);
        $utr = WaPayProof::refFrom($t, (string) ($ctx['phone'] ?? ''));
        $amount = (float) self::amountIn(mb_strtolower($t), $utr);
        $held = AgentWallet::balances($id)['cash'];
        if ($amount > 0 && $utr !== '') {
            if (Database::exists("SELECT 1 FROM ap_deposit_requests WHERE utr = :u AND status <> 'declined'", ['u' => $utr])) {
                return self::out(self::t('pay_ref_used', $lang, ['utr' => $utr]));
            }
            if (($ctx['phone'] ?? '') === '') {
                return self::out(self::t('error', $lang));
            }
            self::setPending((string) $ctx['phone'], 'deposit', ['amount' => $amount, 'utr' => $utr,
                'purpose' => $amount > $held + 0.009 ? 'security' : 'handover']);
            // round 3 (owner's rule R3): the preview always names the payer — whose account the payment goes on
            return self::out(self::t('pay_confirm', $lang, ['v' => inr($amount), 'utr' => $utr, 'who' => self::payerLabel($member)]));
        }
        if ($amount > 0 && $utr === '' && preg_match('/(?<![\p{L}])(?:cash|nagad|नगद)(?![\p{L}])/iu', $t) === 1) {
            return self::out(self::t('pay_cash_note', $lang, ['v' => inr($amount)]));
        }
        if ($amount > 0 && $utr === '' && preg_match('/(?<![\p{L}])done$/iu', trim($t)) === 1) {
            // "maile 2000 jama gareko chu": already paid — ask for the reference, record nothing. Round 3 (agent sim: the
            // answer "UTR 4123…" then got the menu): the amount is kept for that answer (pending 'pay_need', no "ho").
            if (($ctx['phone'] ?? '') !== '') {
                self::setPending((string) $ctx['phone'], 'pay_need', ['amount' => $amount]);
            }
            return self::out(self::t('pay_need_ref', $lang, ['v' => inr($amount), 'n' => (string) (int) $amount]));
        }
        $upi = Settings::getString('upi_id', '');
        $amt = $amount > 0 ? $amount : max(0.0, $held);
        $media = null;
        if ($upi !== '' && $amt > 0) {
            require_once __DIR__ . '/agentpro-card.php';
            $exp = time() + 86400;
            $media = appUrl('api/agentpro-card.php?pay=' . number_format($amt, 2, '.', '') . '&m=' . $id . '&e=' . $exp
                . '&k=' . ApCard::sig($id, 'pay' . number_format($amt, 2, '.', ''), $exp));
        }
        return self::out(self::t('pay_how', $lang, ['held' => inr($held), 'v' => inr($amt), 'upi' => $upi !== '' ? $upi : '—']), $media);
    }

    /* ---------------- cards and me ---------------- */

    private static function card(array $member, string $side, string $lang): array
    {
        require_once __DIR__ . '/agentpro-card.php';
        $m = AgentPro::member((int) $member['id']);
        if ($m === null) {
            return self::out(self::t('error', $lang));
        }
        $url = ApCard::signedUrl((int) $member['id'], $side, 86400);
        $key = str_starts_with($side, 'id') ? 'card_id' : 'card_vc';
        return self::out(self::t($key, $lang, [
            'code' => $m['idNo'] !== '' ? $m['idNo'] : '—', 'me' => appUrl('me/'),
            'share' => appUrl('c.php?u=' . rawurlencode((string) $m['cardToken'])), 'wa' => (string) $m['waDisplay'],
        ]), $url);
    }

    private static function profile(array $member, string $lang): array
    {
        $m = AgentPro::member((int) $member['id']);
        if ($m === null) {
            return self::out(self::t('error', $lang));
        }
        $bank = $m['bank']['upi'] !== '' ? 'UPI ' . $m['bank']['upi'] : ($m['bank']['last4'] !== '' ? $m['bank']['name'] . ' ••••' . $m['bank']['last4'] : '—');
        return self::out(self::t('profile', $lang, [
            'name' => $m['name'], 'code' => $m['idNo'] !== '' ? $m['idNo'] : '—', 'role' => $m['designation'],
            'phone' => $m['callDisplay'] ?: $m['waDisplay'], 'wa' => $m['waDisplay'], 'branch' => $m['branch'] !== '' ? $m['branch'] : '—',
            'kyc' => strtoupper($m['kycStatus']), 'valid' => $m['validUntil'] !== '' ? formatDate($m['validUntil'], 'j M Y') : '—',
            'bank' => $bank, 'me' => appUrl('me/'),
        ]));
    }

    /**
     * "form" — my own filled application form. The applicant's own signed
     * link (/join-form.php?t=…, an HMAC token, 30 days after sending) while
     * it is valid; after that /me/form.php, which opens after signing in to
     * the portal (agent code + mobile + WhatsApp OTP) — the form shows the
     * member's ID, PAN and bank numbers in full, so no longer-lived public
     * link is made here.
     */
    private static function myForm(array $member, string $lang): array
    {
        require_once __DIR__ . '/agentpro-form.php';
        $id = (int) $member['id'];
        $app = null;
        try {
            $app = ApForm::applicationFor($id);
        } catch (Throwable $e) {
            Logger::warning('WhatsApp form link: ' . $e->getMessage(), [], 'agent');
        }
        $signed = '';
        if ($app !== null && ApForm::applicantMayOpen($app) && ($u = ApForm::applicantUrl($app)) !== '') {
            $signed = appUrl(ltrim($u, '/') . '&auto=pdf');
        }
        Logger::audit('agentpro.wa_form', 'admin', (string) $id, null, ['signed' => $signed !== ''], 'Own filled form asked for on the WhatsApp desk');
        if ($signed !== '') {
            return self::out(self::t('form_signed', $lang, ['url' => $signed, 'me' => appUrl('me/form.php?auto=pdf')]));
        }
        return self::out(self::t($app !== null ? 'form_signin' : 'form_none', $lang, ['url' => appUrl('me/form.php?auto=pdf'), 'me' => appUrl('me/')]));
    }

    /**
     * The office: "id <code | phone | name>" → that member's ID card (a
     * signed picture link, 24 h) + their visiting card + one line about them,
     * to forward. Several matches → up to 5 with their codes. Only reached
     * from isOffice() (an OTP-linked manager / super-admin), audited.
     */
    private static function officeId(string $query, string $side, array $ctx, string $lang): array
    {
        require_once __DIR__ . '/agentpro-card.php';
        $hits = self::findMembers($query);
        if ($hits === []) {
            return self::out(self::t('oid_none', $lang, ['q' => mb_substr($query, 0, 40)]));
        }
        if (count($hits) > 1) {
            $lines = [self::t('oid_many', $lang, ['n' => (string) count($hits), 'q' => mb_substr($query, 0, 40)])];
            foreach (array_slice($hits, 0, 5) as $i => $r) {
                $lines[] = ($i + 1) . '. ' . ($r['idNo'] !== '' ? $r['idNo'] . ' · ' : '') . $r['name'] . ' · ' . $r['roleLabel'] . ($r['branch'] !== '' ? ' · ' . $r['branch'] : '');
            }
            if (count($hits) > 5) {
                $lines[] = '… +' . (count($hits) - 5);
            }
            $lines[] = '';
            $lines[] = self::t('oid_pick', $lang);
            return self::out(implode("\n", $lines));
        }
        $m = AgentPro::member((int) $hits[0]['id']);
        if ($m === null) {
            return self::out(self::t('error', $lang));
        }
        $media = ApCard::signedUrl((int) $m['id'], $side, 86400);
        $vc = ApCard::signedUrl((int) $m['id'], 'vc-front', 86400);
        Logger::audit('agentpro.wa_office_id', 'admin', (string) $m['id'], null, ['by' => (int) ($ctx['adminId'] ?? 0), 'side' => $side, 'wa' => (string) ($ctx['phone'] ?? '')],
            'Office asked for a member\'s ID card on the WhatsApp desk');
        return self::out(self::t('oid_one', $lang, [
            'name' => $m['name'], 'code' => $m['idNo'] !== '' ? $m['idNo'] : '—', 'role' => $m['designation'],
            'branch' => $m['branch'] !== '' ? $m['branch'] : '—', 'phone' => $m['callDisplay'] ?: ($m['waDisplay'] ?: '—'),
            'status' => strtoupper((string) $m['status']), 'kyc' => strtoupper((string) ($m['kycStatus'] ?? '')),
            'vc' => $vc, 'share' => (string) $m['cardToken'] !== '' ? appUrl('c.php?u=' . rawurlencode((string) $m['cardToken'])) : '—',
            'back' => $m['idNo'] !== '' ? $m['idNo'] : $query,
        ]), $media);
    }

    /**
     * Members matching a code (SHG-0027, SHG-S-0004 …), a mobile number or a
     * name (every word), active ones only, best first.
     *
     * @return list<array<string, mixed>> AgentPro::roster() rows
     */
    private static function findMembers(string $query): array
    {
        $q = trim(mb_strtolower(self::asciiDigits($query)));
        if ($q === '') {
            return [];
        }
        $rows = array_values(array_filter(AgentPro::roster(), static fn(array $r): bool => (bool) $r['active']));
        $squash = static fn(string $s): string => (string) preg_replace('/[\s_.-]+/', '', mb_strtolower($s));
        // 1. the exact code
        $code = array_values(array_filter($rows, static fn(array $r): bool => $r['idNo'] !== '' && $squash($r['idNo']) === $squash($q)));
        if ($code !== []) {
            return $code;
        }
        // 2. the mobile (10 digits or more)
        $digits = (string) preg_replace('/\D/', '', $q);
        if (strlen($digits) >= 10) {
            $want = normalisePhone($digits);
            return array_values(array_filter($rows, static fn(array $r): bool => $r['phone'] !== '' && normalisePhone((string) $r['phone']) === $want));
        }
        // a bare agent number: "id 27" = SHG-0027
        if (preg_match('/^(?:shg\s*-?\s*)?0*(\d{1,4})$/', $q, $mm) === 1) {
            $label = 'shg' . str_pad($mm[1], 4, '0', STR_PAD_LEFT);
            return array_values(array_filter($rows, static fn(array $r): bool => $r['idNo'] !== '' && $squash($r['idNo']) === $label));
        }
        // 3. the name: every word of the query in the name (or code / branch)
        $words = array_values(array_filter(preg_split('/\s+/u', $q) ?: [], static fn(string $w): bool => mb_strlen($w) >= 2));
        if ($words === []) {
            return [];
        }
        $hits = array_values(array_filter($rows, static function (array $r) use ($words): bool {
            $hay = mb_strtolower($r['name'] . ' ' . $r['idNo'] . ' ' . $r['branch']);
            foreach ($words as $w) {
                if (!str_contains($hay, $w)) {
                    return false;
                }
            }
            return true;
        }));
        // a whole-name match first
        usort($hits, static fn(array $a, array $b): int => (int) (mb_strtolower($b['name']) === $q) <=> (int) (mb_strtolower($a['name']) === $q));
        return $hits;
    }

    /* ---------------- people and the company ---------------- */

    private static function directory(array $member, string $lang, string $query, string $raw): array
    {
        $office = self::officeMember($member);            // every agent's number: an OTP-linked office number only
        $wantAgents = str_starts_with($raw, 'agent') || str_starts_with($raw, 'एजेन्ट');
        $wantStaff  = str_starts_with($raw, 'staff') || str_starts_with($raw, 'स्टाफ') || str_starts_with($raw, 'team');
        $seeAgents  = $office || Settings::getBool('ap_wa_dir_agents', false);
        /* 31 Oct 2026 round 2 (safety review: round 1 gave EVERY counter — even one known only by the typed admins.phone —
           every agent's personal mobile, over ap_wa_dir_agents = off): a counter that proved its number with the OTP
           (via = link) sees only the agents the public website already lists (ApWeb), with the website's own
           visiting-card number — never the roster's personal mobile, never bank / ID numbers. */
        $publicAgents = [];
        if (!$seeAgents && (string) $member['role'] === 'counter' && (string) ($member['via'] ?? '') === 'link') {
            try {
                require_once __DIR__ . '/agentpro-web.php';
                $ids = array_map(static fn(array $r): int => (int) $r['id'], array_filter(AgentPro::roster(), static fn(array $r): bool => (bool) $r['isAgent'] && (bool) $r['active']));
                foreach (ApWeb::shownMany($ids) as $aid => $shown) {
                    if ($shown) {
                        $pm = AgentPro::member((int) $aid);
                        $wa = (string) ($pm['wa'] ?? '');
                        if ($wa !== '') {
                            $publicAgents[(int) $aid] = $wa;
                        }
                    }
                }
            } catch (Throwable $e) {
                $publicAgents = [];
            }
        }
        $rows = AgentPro::roster();
        $out = [];
        $q = mb_strtolower($query);
        foreach ($rows as $r) {
            if (!$r['active'] || $r['phone'] === '') {
                continue;
            }
            $public = $r['isAgent'] && !$seeAgents && isset($publicAgents[(int) $r['id']]);
            if ($r['isAgent'] && !$seeAgents && $r['id'] !== (int) $member['id'] && !$public) {
                continue;
            }
            if (!$r['isAgent'] && !$office && !in_array($r['role'], self::OFFICE, true) && $r['role'] !== 'counter') {
                continue;                                    // agents see the office and the counters
            }
            if ($wantAgents && !$r['isAgent']) { continue; }
            if ($wantStaff && $r['isAgent']) { continue; }
            // the role is searched only where the whole list may be seen ("number agent" listed every agent's mobile)
            if ($q !== '' && !str_contains(mb_strtolower($r['name'] . ' ' . $r['idNo'] . ' ' . $r['branch'] . ' ' . $r['designation'] . ($seeAgents ? ' ' . $r['role'] : '')), $q)) {
                continue;
            }
            $shownPhone = $public ? (string) $publicAgents[(int) $r['id']] : AgentPro::intlDigits($r['phone']);
            $out[] = ($r['idNo'] !== '' ? $r['idNo'] . ' · ' : '') . $r['name'] . ' — ' . AgentPro::displayPhone($shownPhone)
                . ($r['branch'] !== '' ? ' · ' . $r['branch'] : '') . ($r['isAgent'] ? '' : ' (' . $r['designation'] . ')');
        }
        $co = Settings::company();
        $head = self::t('dir_head', $lang, ['n' => (string) count($out)]);
        $lines = array_slice($out, 0, 40);
        if (count($out) > 40) {
            $lines[] = '… +' . (count($out) - 40) . ' · ' . self::t('dir_more', $lang);
        }
        if ($out === []) {
            // an agent who IS there but is not on the public website: say why there is no number (never "nobody")
            $hidden = !$seeAgents && $q !== '' && array_filter($rows, static fn(array $r): bool => $r['isAgent'] && $r['active']
                && str_contains(mb_strtolower($r['name'] . ' ' . $r['idNo']), $q)) !== [];
            $lines[] = $hidden ? self::t('dir_hidden', $lang, ['phone' => Settings::officePhone()]) : self::t('dir_none', $lang);
        }
        $lines[] = '';
        $lines[] = '🏢 ' . AgentPro::fixMojibake((string) $co['counters']);
        return self::out($head . "\n" . implode("\n", $lines));
    }

    private static function company(string $lang): array
    {
        $co = array_map(static fn($v) => is_string($v) ? AgentPro::fixMojibake($v) : $v, Settings::company());
        $lines = [
            '🏢 ' . $co['legal'],
            'CIN: ' . $co['cin'] . (Settings::getString('company_gstin', '') !== '' ? ' · GSTIN: ' . Settings::getString('company_gstin', '') : ''),
            '📍 ' . ($lang === 'en' || $lang === 'hi' ? $co['address'] : ($co['addressNe'] ?: $co['address'])),
            '☎ ' . $co['phone'] . ' · WhatsApp +' . $co['whatsapp'],
            '✉ ' . $co['email'] . ' · 🌐 ' . $co['web'],
            '🚌 ' . $co['counters'],
            self::t('co_operator', $lang, ['n' => $co['operator']]),
        ];
        foreach (['company_facebook' => 'Facebook', 'company_instagram' => 'Instagram', 'company_youtube' => 'YouTube', 'nepal_phone' => '🇳🇵'] as $k => $label) {
            $v = trim(Settings::getString($k, ''));
            if ($v !== '') {
                $lines[] = $label . ': ' . $v;
            }
        }
        return self::out(implode("\n", $lines));
    }

    private static function buses(array $member, string $lang, string $date): array
    {
        require_once __DIR__ . '/seats.php';
        $staff = in_array((string) $member['role'], self::SELLERS, true) || in_array((string) $member['role'], self::OFFICE, true);
        $rows = Database::fetchAll(
            "SELECT s.id, s.status, COALESCE(s.dep_time_override, r.dep_time) AS dep, r.from_city, r.to_city, r.crew_name, r.crew_phone,
                    b.id AS bus_id, b.bus_name, b.bus_number
               FROM schedules s JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses b ON b.id = COALESCE(s.bus_id, r.bus_id)
              WHERE s.travel_date = :d AND s.status <> 'cancelled'
              ORDER BY dep LIMIT 12",
            ['d' => $date]
        );
        $lines = [self::t('bus_head', $lang, ['d' => formatDate($date, 'D, j M')])];
        foreach ($rows as $r) {
            $sold = 0;
            try {
                $sold = Seats::occupiedBeds((int) $r['id']);
            } catch (Throwable $e) {
            }
            $line = '🚌 ' . substr((string) $r['dep'], 0, 5) . ' ' . $r['from_city'] . ' → ' . $r['to_city']
                  . ((string) $r['bus_number'] !== '' ? "\n   " . $r['bus_number'] . ' · ' . $r['bus_name'] : '')
                  . ' · ' . self::t('bus_sold', $lang, ['n' => (string) $sold]);
            if ($staff && (string) ($r['crew_phone'] ?? '') !== '') {
                $line .= "\n   👨‍✈️ " . $r['crew_name'] . ' ' . $r['crew_phone'];
            }
            $live = self::liveLink((int) ($r['bus_id'] ?? 0));
            if ($live !== '') {
                $line .= "\n   📍 " . $live;
            }
            $lines[] = $line;
        }
        if ($rows === []) {
            $lines[] = self::t('bus_none', $lang);
        }
        return self::out(implode("\n", $lines));
    }

    /** The Live Fleet passenger link for a bus that is running now, when that module is installed. */
    private static function liveLink(int $busId): string
    {
        if ($busId <= 0) {
            return '';
        }
        try {
            $t = (string) Database::scalar(
                "SELECT share_token FROM fleet_trips WHERE bus_id = :b AND status = 'running' AND share_token IS NOT NULL ORDER BY id DESC LIMIT 1",
                ['b' => $busId], ''
            );
            return $t !== '' ? appUrl('bus-live.php?t=' . $t) : '';
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function report(array $member, array $ctx, string $lang, string $which): array
    {
        if (!in_array($ctx['role'], ['staff', 'admin'], true)) {
            return self::balance($member, $lang);
        }
        require_once __DIR__ . '/aitools.php';
        $office = $ctx['role'] === 'admin' && $which !== 'own';
        $r = AiTools::run($office ? 'office_day' : 'agent_day', [], $ctx);
        if (!$r['ok']) {
            return self::out('⚠️ ' . $r['say']);
        }
        $d = $r['data'];
        if ($office) {
            $lines = [self::t('rep_office', $lang, ['d' => formatDate((string) $d['date'], 'D, j M'), 't' => (string) $d['ticketsSold'],
                'v' => (string) $d['revenueLabel'], 'p' => (string) $d['pendingPayments']])];
            foreach ((array) $d['byMethod'] as $method => $x) {
                $lines[] = '  · ' . $method . ': ' . inr((float) $x['amount']) . ' (' . $x['tickets'] . ')';
            }
            foreach ((array) $d['departures'] as $b) {
                $lines[] = '🚌 ' . $b['depTime'] . ' ' . $b['route'] . ' — ' . self::t('bus_sold', $lang, ['n' => (string) $b['sold']]);
            }
            return self::out(implode("\n", $lines));
        }
        return self::out(self::t('rep_agent', $lang, ['d' => formatDate((string) $d['date'], 'D, j M'), 't' => (string) $d['tickets'],
            's' => (string) $d['seats'], 'v' => (string) $d['amountLabel'], 'c' => (string) $d['commissionDueLabel'], 'h' => (string) $d['cashDueLabel']]));
    }

    private static function passengers(array $member, array $ctx, string $lang, string $date): array
    {
        if (!in_array($ctx['role'], ['staff', 'admin'], true)) {
            return self::help($member, $lang);
        }
        require_once __DIR__ . '/aitools.php';
        $r = AiTools::run('agent_passengers', ['date' => $date], $ctx);
        if (!$r['ok']) {
            return self::out('⚠️ ' . $r['say']);
        }
        $lines = [self::t('pax_head', $lang, ['d' => formatDate($date, 'D, j M'), 'n' => (string) $r['data']['count']])];
        foreach ((array) $r['data']['byPickup'] as $stop => $list) {
            $lines[] = '📍 ' . Boarding_display((string) $stop);
            foreach ($list as $p) {
                $lines[] = '   ' . self::seatLabel((string) $p['seat'], (string) ($p['pnr'] ?? '')) . ' ' . $p['name'] . ' · ' . $p['phone'];
            }
        }
        return self::out(implode("\n", $lines));
    }

    private static function dateWord(string $w): string
    {
        require_once __DIR__ . '/agentpro-edit.php';
        return ApEdit::parseDate($w);
    }

    /* ---------------- a PNR ---------------- */

    private static function pnrCommand(string $pnr, string $text, ?array $member, array $ctx, string $lang): ?array
    {
        require_once __DIR__ . '/agentpro-edit.php';
        require_once __DIR__ . '/aitools.php';
        $rest = trim(str_ireplace($pnr, ' ', $text));
        $l = mb_strtolower($rest);
        // 31 Oct 2026 (owner sim: "SHG-… ko phone number k ho?" was read as an edit to "number k"): a question is answered.
        $question = self::isQuestion($l);
        if (!$question && preg_match('/(?<![\p{L}\p{M}])(cancel|radda|radd|रद्द|क्यान्सिल|कैंसिल)/u', $l)) {
            self::$cmdIntent = ['intent' => 'cancel'];
            $q = AiTools::run('refund_quote', ['pnr' => $pnr], $ctx);
            if (!$q['ok']) {
                return self::out(self::pnrRefusal($pnr, (string) $q['say'], $ctx, $lang));
            }
            $q['data']['note'] = self::refundNote((string) ($q['data']['note'] ?? ''), $lang);
            // Owner: "refund vane cancel vane sabai manually garxu". An agent / counter only ASKS:
            // the request goes to the office queue (Help Desk) and nothing is cancelled or refunded here.
            if ($member !== null && !self::isOffice($ctx)) {
                $reason = trim((string) preg_replace('/\b(cancel|radda|radd|रद्द|क्यान्सिल|कैंसिल|garnu\w*|गर्नु\w*|please|plz)\b/iu', ' ', $rest));
                self::setPending($ctx['phone'], 'cancel_req', ['pnr' => $pnr, 'refund' => (string) $q['data']['refundLabel'],
                    'note' => (string) ($q['data']['note'] ?? ''), 'reason' => Security::clean($reason, 200)]);
                return self::out(self::t('cancel_req_quote', $lang, ['pnr' => $pnr, 'v' => (string) $q['data']['refundLabel'],
                    'note' => (string) ($q['data']['note'] ?? '')]));
            }
            self::setPending($ctx['phone'], 'cancel', ['pnr' => $pnr]);
            return self::out(self::t('cancel_quote', $lang, ['pnr' => $pnr, 'v' => (string) $q['data']['refundLabel'],
                'note' => (string) ($q['data']['note'] ?? '')]));
        }
        // 31 Oct 2026: "ko ticket feri pathaidinu", "pathaideu", "pheri pathau", "resend garidinu" too
        if (!$question && preg_match('/(?<![\p{L}\p{M}])(resend\w*|pathau\w*|pathai\w*|pathae\w*|feri|pheri|phir|फेरि|फेरी|पठाउ\S*|पठाइ\S*|bhej\w*|भेज\S*|send\s*again|send)(?![\p{L}\p{M}])/u', $l)) {
            self::$cmdIntent = ['intent' => 'resend'];
            $r = AiTools::run('resend_ticket', ['pnr' => $pnr], $ctx);
            if (!$r['ok']) {
                return self::out(self::pnrRefusal($pnr, (string) ($r['say'] ?? ''), $ctx, $lang));
            }
            // round 2 (counter / agent sims: "✅ … फेरि पठाइयो" while message_logs said failed): "sent" only when it went
            if (empty($r['data']['sent'])) {
                $media = $r['media'] ?? null;
                if ($media === null) {
                    try {
                        $media = Ticket::imageUrl($pnr);
                    } catch (Throwable $e) {
                        $media = null;
                    }
                }
                $pdf = '';
                try {
                    $pdf = "\n📄 PDF: " . Ticket::downloadUrl($pnr);
                } catch (Throwable $e) {
                    $pdf = '';
                }
                return self::out(self::t('resent_not_sent', $lang, ['pnr' => $pnr]) . $pdf, $media);
            }
            return self::out('✅ ' . self::t('resent', $lang, ['pnr' => $pnr]), $r['media'] ?? null);
        }
        [$field, $value, $which] = $question ? ['', '', ''] : self::editIntent($rest);
        if ($field !== '') {
            // the new value / the passenger go out of the example (ApLearn::norm): only "edit <field>" is learnt
            self::$cmdIntent = ['intent' => 'edit', 'field' => $field, 'value' => $value, 'which' => $which];
            return self::editPreview($pnr, $field, $value, $which, $ctx, $lang);
        }
        /* A sentence about a ticket: the AI reads it when it is on. With it off (or for a plain question —
           "ko status k cha", "kaha pugyo") the booking card answers; never the menu (owner: "ticket ko
           kura ma jaba pani reply"). */
        if (!$question && $rest !== '' && $member !== null && preg_match('/\p{L}{3,}/u', $rest) === 1
            && !preg_match('/^(status|kaha|कहाँ|where|ticket|टिकट|detail)/iu', $l)) {
            require_once __DIR__ . '/agentpro-ai.php';
            if (ApAi::enabled()) {
                return null;
            }
        }
        self::$cmdIntent = ['intent' => 'status'];
        return self::bookingCard($pnr, $ctx, $lang);
    }

    /** "ko phone number k ho?", "ko date kahile ho?", "kaha pugyo?", "status k cha" — a question, never an edit. */
    private static function isQuestion(string $l): bool
    {
        $l = trim($l);
        return $l !== '' && (str_contains($l, '?')
            || preg_match('/(?<![\p{L}\p{M}])(k|ke|kya|kahile|kaile|kati|kun|kasto|what|when|where|which|who|kab|kitna|kitne|kaun|kahan|kaha|kata|कहिले|कति|के|कुन|क्या|कब|कहाँ)\s*(ho|cha|chha|xa|hai|hola|ho\s*ra|छ|हो|है)?\s*$/u', $l) === 1
            || preg_match('/(?<![\p{L}\p{M}])(status|kaha\s*pug\w*|kata\s*pug\w*|pugyo|pahunch\w*|detail|details|info)(?![\p{L}\p{M}])/u', $l) === 1);
    }

    /** Why a PNR command was refused, in the chat's language: not theirs = one clear line, else the tool's reason. */
    private static function pnrRefusal(string $pnr, string $say, array $ctx, string $lang): string
    {
        require_once __DIR__ . '/agentpro-edit.php';
        $d = BookingService::detail($pnr);
        if ($d === null || !ApEdit::mayRead($d, $ctx)) {
            return self::t('e_notfound', $lang);
        }
        $say = trim(preg_replace('/^⚠️\s*/u', '', $say) ?? $say);
        if (preg_match('/There is no ticket yet — the booking is (\w+)/u', $say, $m) === 1) {
            return self::t('e_notlive', $lang, ['s' => strtoupper($m[1])]);
        }
        if (preg_match('/is already cancelled/u', $say) === 1) {
            return self::t('e_notlive', $lang, ['s' => 'CANCELLED']);
        }
        return '⚠️ ' . ($say !== '' ? $say : self::t('error', $lang));
    }

    /** The refund rule line (Fare::refundFor, English) in the chat's language. */
    private static function refundNote(string $note, string $lang): string
    {
        if ($lang === 'en' || $note === '') {
            return $note;
        }
        if (preg_match('/^Cancelled ([\d,]+) hours before departure — ([\d.]+)% refundable\.?$/u', $note, $m) === 1) {
            return $lang === 'hi' ? 'प्रस्थान से ' . $m[1] . ' घंटे पहले रद्द — ' . $m[2] . '% वापसी।'
                                  : 'छुट्नुभन्दा ' . $m[1] . ' घण्टा अगाडि रद्द — ' . $m[2] . '% फिर्ता।';
        }
        if (str_starts_with($note, 'Cancelled after the free-cancellation window')) {
            return $lang === 'hi' ? 'रद्द करने की समय सीमा निकल चुकी — वापसी नहीं।' : 'रद्द गर्ने समय सीमा नाघिसक्यो — फिर्ता छैन।';
        }
        if (str_starts_with($note, 'No payment had been collected')) {
            return $lang === 'hi' ? 'कोई भुगतान नहीं लिया गया था।' : 'कुनै भुक्तानी लिइएको थिएन।';
        }
        return $note;
    }

    /** A stored berth (L35) as the ticket prints it (F5); a PNR or the coach / mode tell which layout. */
    private static function seatLabel(string $seat, string $pnr = '', string $coach = '', string $mode = ''): string
    {
        if ($seat === '') {
            return '';
        }
        try {
            require_once __DIR__ . '/seats.php';
            if ($pnr !== '' && ($coach === '' || $mode === '')) {
                $row = Database::fetch("SELECT b.booking_mode, l.schedule_id FROM bookings b LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
                                         WHERE b.pnr = :p LIMIT 1", ['p' => $pnr]);
                $mode = $mode !== '' ? $mode : (string) ($row['booking_mode'] ?? 'sharing');
                $coach = $coach !== '' ? $coach : ((int) ($row['schedule_id'] ?? 0) > 0 ? Seats::coachForSchedule((int) $row['schedule_id']) : 'sleeper');
            }
            $lbl = Seats::displayLabel($seat, $coach !== '' ? $coach : 'sleeper', $mode !== '' ? $mode : 'sharing');
            return $lbl !== '' ? $lbl : $seat;
        } catch (Throwable $e) {
            return $seat;
        }
    }

    /**
     * "naam Sita Thapa" / "pickup Surat" / "seat L5" / "phone 98…" / "date 2 Nov" / "gender female" / "age 30"
     *
     * @return array{0: string, 1: string, 2: string} field, value, which passenger
     */
    public static function editIntent(string $s): array
    {
        // Filler that is not part of the new value: "ko … sachyaunu paryo, … banaidinu", "please", "ji".
        $s = (string) preg_replace('/(?<![\p{L}\p{M}])(ko|ka|ki|को|का|की|lai|लाई|change|badal[\p{L}\p{M}]*|बदल[\p{L}\p{M}]*|sachyau[\p{L}\p{M}]*|सच्याउ[\p{L}\p{M}]*|sachchyau[\p{L}\p{M}]*|banau[\p{L}\p{M}]*|banai[\p{L}\p{M}]*|बनाउ[\p{L}\p{M}]*|बनाइ[\p{L}\p{M}]*|garnu[\p{L}\p{M}]*|garidinu|garideu|gardinu|गर्नु[\p{L}\p{M}]*|गरिदिनु[\p{L}\p{M}]*|karo|करो|kardo|करदो|kar\s*do|please|plz|pls|ji|hajur|to|ma|मा|में|chahiyo|चाहियो|hunu\s*parne|paryo|parcha|parne|पर्‍यो|पर्यो|cha|chha|xa|छ|hai|है|ho|हो|should\s*be|be|is|wrong|galat|गलत|instead)(?![\p{L}\p{M}])/iu', ' ', $s);
        $s = trim((string) preg_replace('/[\s,;.!।]+/u', ' ', $s));
        $map = [
            'name'   => '(?:name|naam|nam|नाम|नाउँ)',
            'phone'  => '(?:phone|mobile|mob|number|no\.?|फोन|मोबाइल|नम्बर|नंबर)',
            'pickup' => '(?:pickup|pick\s*up|boarding|board|chadne|charne|चढ्ने|चढने|बोर्डिङ|बोर्डिंग|stop|स्टप)',
            'seat'   => '(?:seat|berth|sit|सिट|सीट|बर्थ)',
            'date'   => '(?:date|din|miti|मिति|तारिख|दिन|tarikh)',
            'gender' => '(?:gender|ling|linga|लिङ्ग|लिंग|sex)',
            'age'    => '(?:age|umer|umar|उमेर|उम्र)',
        ];
        foreach ($map as $field => $re) {
            // "naam Sita Thapa", "Ram ko naam Sita", "naam: Sita (2)", "seat L5", "Sita ko seat L5"
            if (preg_match('/^(?:(.{1,40}?)\s+)?' . $re . '\s*[:=\-]?\s*(.+)$/iu', $s, $m) === 1) {
                $value = trim($m[2], " .:-");
                $which = trim((string) ($m[1] ?? ''));
                if ($value !== '') {
                    return [$field, $value, $which];
                }
            }
        }
        return ['', '', ''];
    }

    private static function editPreview(string $pnr, string $field, string $value, string $which, array $ctx, string $lang): array
    {
        require_once __DIR__ . '/agentpro-edit.php';
        if ($ctx['role'] === 'member') {
            return self::out(self::t('no_permission', $lang));
        }
        if ($field === 'pickup') {
            $value = self::stopLabelFor($pnr, $value);
        }
        $p = ApEdit::preview($pnr, $field, $value, $ctx, $which);
        if (!$p['ok']) {
            return self::out(self::editError($p, $lang));
        }
        if (!empty($p['office'])) {
            // officeQueue opens the Help Desk ticket AND sends the one office note (ApEdit::forwardToOffice would send a second).
            $c = (array) $p['change'];
            self::officeQueue($pnr, $field === 'seat' ? 'seat' : 'booking_help',
                'Change request from the WhatsApp desk: ' . $field . ' → ' . (string) ($c['new'] ?? $value), $ctx);
            return self::out(self::t('edit_office', $lang, ['pnr' => $pnr]));
        }
        $c = $p['change'];
        self::setPending($ctx['phone'], 'edit', ['change' => $c]);
        $label = self::t('f_' . $field, $lang);
        $old = (string) ($c['old'] ?? '');
        $new = (string) ($c['new'] ?? '');
        if ($field === 'pickup') {
            $old = Boarding_display($old);
            $new = Boarding_display($new);
        }
        if ($field === 'date') {
            $old = $old !== '' ? formatDate($old, 'D, j M Y') : '—';
            $new = formatDate($new, 'D, j M Y') . (!empty($c['seats']) ? ' · ' . implode(', ', array_map(static fn($s): string => self::seatLabel((string) $s, $pnr), (array) $c['seats'])) : '');
        }
        if ($field === 'seat') {
            $old = self::seatLabel($old, $pnr);
            $new = self::seatLabel($new, $pnr);
        }
        return self::out(self::t('edit_confirm', $lang, ['pnr' => $pnr, 'f' => $label, 'old' => $old !== '' ? $old : '—', 'new' => $new]));
    }

    /**
     * The route's own stop label for a town the way it is shown ("Baroda" is
     * the stop "Barauda", "Mehsana" is "Mehsana Head Office - Silver Complex"),
     * so a pickup typed as it reads on the ticket finds its stop.
     */
    private static function stopLabelFor(string $pnr, string $value): string
    {
        try {
            require_once __DIR__ . '/boarding.php';
            $d = BookingService::detail($pnr);
            $sid = (int) ($d['legs'][0]['schedule_id'] ?? 0);
            $routeId = $sid > 0 ? (int) Database::scalar('SELECT route_id FROM schedules WHERE id = :s', ['s' => $sid], 0) : 0;
            $want = Boarding::townKey($value);
            if ($routeId <= 0 || $want === '') {
                return $value;
            }
            foreach (Boarding::stopsFor($routeId) as $s) {
                if (Boarding::townKey((string) $s['name']) === $want) {
                    return $value;                                  // already exact
                }
            }
            foreach (Boarding::stopsFor($routeId) as $s) {
                if (Boarding::townKey(Boarding::stopDisplay((string) $s['name'])['name']) === $want) {
                    return (string) $s['name'];
                }
            }
        } catch (Throwable $e) {
            // ApEdit gives its own answer
        }
        return $value;
    }

    private static function editError(array $p, string $lang): string
    {
        $e = (string) ($p['error'] ?? '');
        return match ($e) {
            'notfound'  => self::t('e_notfound', $lang),
            'notlive'   => self::t('e_notlive', $lang, ['s' => strtoupper((string) ($p['status'] ?? ''))]),
            'departed'  => self::t('e_departed', $lang),
            'whichpax'  => self::t('e_whichpax', $lang, ['names' => implode(', ', (array) ($p['names'] ?? []))]),
            'badstop'   => self::t('e_badstop', $lang, ['stops' => implode(', ', (array) ($p['stops'] ?? []))]),
            'same'      => self::t('e_same', $lang),
            'badseat'   => self::t('e_badseat', $lang, ['seat' => (string) ($p['seat'] ?? '')]),
            'seattaken' => self::t('e_seattaken', $lang, ['seat' => (string) ($p['seat'] ?? '')]),
            'tools', 'refused' => '⚠️ ' . (string) ($p['say'] ?? self::t('error', $lang)),
            default     => self::t('e_value', $lang),
        };
    }

    /**
     * A request the office must handle by hand (cancel / refund, a change the
     * desk may not make): one numbered Help Desk ticket, linked to the booking,
     * shown to the office — the passenger is not messaged from here.
     *
     * @return string the HD reference, '' when the Help Desk is not available (the office note still went)
     */
    private static function officeQueue(string $pnr, string $category, string $message, array $ctx): string
    {
        $d = BookingService::detail($pnr);
        $who = (array) ($ctx['admin'] ?? []);
        $from = trim((string) ($who['full_name'] ?? '') . ' ' . ((int) ($ctx['adminId'] ?? 0) > 0 ? '(' . self::codeOf((int) $ctx['adminId']) . ')' : ''));
        $msg = $message . "\nFrom: " . ($from !== '' ? $from : 'WhatsApp') . ' · +' . $ctx['phone'];
        $ref = '';
        try {
            require_once __DIR__ . '/helpdesk.php';
            $r = HelpDesk::open([
                'name' => '', 'phone' => (string) ($d['contact_phone'] ?? $ctx['phone']),
                'country' => (string) ($d['contact_country_code'] ?? '') === '977' ? 'NP' : 'IN',
                'category' => $category, 'message' => $msg, 'pnr' => $pnr, 'source' => 'desk', 'trusted' => true,
                'created_by' => (int) ($ctx['adminId'] ?? 0) ?: null, 'lang' => 'ne', 'ack' => false,
            ]);
            $ref = $r['ok'] ? (string) ($r['ref'] ?? '') : '';
        } catch (Throwable $e) {
            Logger::warning('Help Desk ticket from the WhatsApp desk failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        try {
            require_once __DIR__ . '/notify.php';
            Notify::adminNote('📨 ' . ($category === 'refund' ? 'Cancel request' : 'Ticket change request') . ' (WhatsApp desk)', [
                'PNR' => $pnr, 'Request' => $message, 'From' => $from . ' +' . $ctx['phone'], 'Help Desk' => $ref !== '' ? $ref : '—',
            ], isset($d['id']) ? (int) $d['id'] : null);
        } catch (Throwable $e) {
            // the Help Desk ticket is the record
        }
        Logger::audit('agentpro.wa_office_request', 'booking', $pnr, null,
            ['category' => $category, 'hd' => $ref, 'by' => (int) ($ctx['adminId'] ?? 0), 'wa' => $ctx['phone']], $message);
        return $ref;
    }

    private static function bookingCard(string $pnr, array $ctx, string $lang): ?array
    {
        require_once __DIR__ . '/agentpro-edit.php';
        $d = BookingService::detail($pnr);
        if ($d === null || !ApEdit::mayRead($d, $ctx)) {
            return $ctx['role'] === 'customer' ? null : self::out(self::t('e_notfound', $lang));
        }
        $leg = $d['legs'][0] ?? [];
        $pnrNow = (string) $d['pnr'];
        $pax = array_map(static fn(array $p): string => self::seatLabel((string) $p['seat_no'], $pnrNow) . ' ' . $p['full_name'], $d['passengers'] ?? []);
        $text = "🎫 " . $d['pnr'] . ' · ' . strtoupper((string) $d['status']) . "\n"
              . '🚌 ' . ($leg['from_city'] ?? '') . ' → ' . ($leg['to_city'] ?? '') . ' · ' . formatDate((string) ($leg['travel_date'] ?? ''), 'D, j M')
              . ' ' . substr((string) ($leg['dep_time'] ?? ''), 0, 5) . "\n"
              . '📍 ' . Boarding_display((string) ($leg['boarding_stop'] ?? '')) . "\n"
              . '👥 ' . implode(', ', $pax) . "\n"
              . '💰 ' . inr((float) $d['total_amount']) . ' · ☎ ' . $d['contact_phone'] . "\n\n"
              . self::t('card_tail', $lang);
        return self::out($text, (string) $d['status'] === 'confirmed' ? Ticket::imageUrl($pnr) : null);
    }

    /* ---------------- a sale ---------------- */

    /**
     * Read a booking out of a message: the site's own TicketBot parser, plus
     * an explicit party list ("names Ram, Sita, Gita").
     *
     * @return array<string, mixed>|null
     */
    public static function saleParse(string $text): ?array
    {
        require_once __DIR__ . '/quickticket.php';
        require_once __DIR__ . '/ticketbot.php';
        $names = [];
        $work = $text;
        /* 31 Oct 2026 (counter sim: "SHG-0042 ko lagi 2 ticket bholi" was sold for a passenger "Lagi" and credited to
           the company; "company ko 1 seat …" got the company card): an agent code in the line is who the sale is FOR
           (QuickTicket agentCode → BookingService::attributeDeskSale); "company / office ko" is the company's own code. */
        $agentCode = '';
        if (preg_match('/(?<![a-z\d])shg[\s-]*(?:([a-z])[\s-]*)?0*(\d{1,4})(?![\d-])/iu', $work, $mc) === 1) {
            $agentCode = 'SHG-' . ($mc[1] !== '' ? strtoupper($mc[1]) . '-' : '') . str_pad($mc[2], 4, '0', STR_PAD_LEFT);
            $work = str_replace($mc[0], ' ', $work);
        } elseif (preg_match('/^\s*(?:company|office|कम्पनी|कंपनी|अफिस)\s*(?:ko|ka|को|का)?\s*(?:lagi|liye|लागि|लिए)?(?![\p{L}\p{M}])/iu', $work, $mc) === 1) {
            $agentCode = strtoupper(Settings::getString('company_agent_code', 'SHG-0001'));
            $work = (string) substr($work, strlen($mc[0]));
        }
        $text = $work;
        if (preg_match('/\b(?:names?|naam(?:haru)?|नामहरू|नाम)\s*[:\-]\s*([^\d\n]+?)(?=\s*(?:\+?\d{8,}|$))/iu', $text, $m) === 1
            || preg_match('/\b(?:names|naamharu|नामहरू)\s+([^\d\n]+?)(?=\s*(?:\+?\d{8,}|$))/iu', $text, $m) === 1) {
            $list = array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|;|\band\b|\bra\b|र|और)\s*/u', $m[1]) ?: [])));
            if (count($list) >= 2) {
                $names = $list;
                $work = str_replace($m[0], ' ', $text);
            }
        }
        $p = TicketBot::parse($work);
        $booking = (int) $p['seats'] > 0
            || ((string) $p['phone'] !== '' && ((string) $p['date'] !== '' || (string) $p['boarding'] !== ''));
        if (!$booking) {
            return null;
        }
        /* round 3 (counter sim: "3 seat bholi Surat, Ram Thapa, Sita Thapa and Gita Thapa 98…" became ONE passenger "Ram Thapa
           Sita Thapa Gita" — the parser had already dropped the commas and "and"): the list is read from the line as typed. */
        if ($names === []) {
            $raw = self::rawNameList($text);
            if (count($raw) >= 2) {
                $names = $raw;
            }
        }
        // round 2 (counter sim: "… naam Gopal Thapa ra Sita Thapa 98…" sold two seats to "Gopal Thapa Ra Sita Thapa")
        if ($names === [] && (string) $p['name'] !== '') {
            $split = self::nameList((string) $p['name']);
            if (count($split) >= 2) {
                $names = $split;
            }
        }
        $seats = max((int) $p['seats'], count($names), 1);
        $name = $names[0] ?? self::cleanName((string) $p['name']);
        return [
            'name' => $name, 'phone' => (string) $p['phone'], 'country' => (string) $p['country'], 'seats' => $seats,
            'date' => (string) $p['date'], 'direction' => (string) $p['direction'], 'boarding' => (string) $p['boarding'],
            'gender' => (string) $p['gender'], 'pay' => (string) $p['pay'], 'names' => $names, 'agentCode' => $agentCode,
        ];
    }

    private static function saleQuote(array $sale, array $member, array $ctx, string $lang, bool $asked = false): array
    {
        if ($ctx['role'] === 'member' || !Settings::getBool('ap_wa_sell', true)) {
            return self::out(self::t('no_permission', $lang));
        }
        require_once __DIR__ . '/quickticket.php';
        $opts = ['seats' => (int) $sale['seats'], 'date' => (string) $sale['date'], 'direction' => (string) $sale['direction'],
                 'boarding' => (string) $sale['boarding'], 'gender' => in_array($sale['gender'], ['Male', 'Female', 'Other'], true) ? $sale['gender'] : null];
        try {
            $plan = QuickTicket::plan($opts);
        } catch (RuntimeException $e) {
            return self::out('⚠️ ' . $e->getMessage());
        }
        // 30 Oct 2026 (owner: "ticket katna ekdum sajilo, ticket AI janu paryo"): no passenger mobile =
        // the ticket comes to the seller's own WhatsApp (one question less); a mobile typed later replaces it.
        if ((string) $sale['phone'] === '' && Settings::getBool('ap_wa_sale_seller_phone', true) && (string) ($ctx['phone'] ?? '') !== '') {
            $sale['phone'] = (string) $ctx['phone'];
            $sale['to_seller'] = true;
        }
        $need = [];
        if (mb_strlen(trim((string) $sale['name'])) < 2) { $need[] = self::t('f_name', $lang); }
        if ((string) $sale['phone'] === '') { $need[] = self::t('f_phone', $lang); }
        // 31 Oct 2026: a sale FOR an agent (counter / office): say who gets it; an unknown code is refused here, before any "ho"
        $agentLine = '';
        if ((string) ($sale['agentCode'] ?? '') !== '' && (string) $member['role'] !== 'agent') {
            require_once __DIR__ . '/agentwallet.php';
            $aid = AgentWallet::resolveDeskCode((string) $sale['agentCode']);
            if ($aid === null) {
                return self::out(self::t('agent_code_unknown', $lang, ['code' => (string) $sale['agentCode']]));
            }
            $company = AgentWallet::resolveAgentCodeFromString(Settings::getString('company_agent_code', 'SHG-0001'));
            $agentLine = "\n" . ($aid === $company ? self::t('sale_company', $lang, ['code' => AgentWallet::agentCodeLabel($aid) ?: (string) $sale['agentCode']])
                : self::t('sale_agent', $lang, ['code' => AgentWallet::agentCodeLabel($aid) ?: (string) $sale['agentCode'],
                    'name' => (string) Database::scalar('SELECT full_name FROM admins WHERE id = :i', ['i' => $aid], '')]));
        } elseif ((string) $member['role'] === 'agent') {
            $sale['agentCode'] = '';                                // an agent sells on their own code
        }
        $sale['plan'] = ['date' => (string) $plan['date'], 'direction' => (string) $plan['direction'], 'boarding' => (string) $plan['boarding'],
                         'boardingCode' => (string) $plan['boardingCode'], 'seats' => (int) $plan['seatCount'], 'total' => (float) $plan['fare']['total'],
                         // round 3: the berths as printed, for "seat kun kun ho?" while the quote waits (pendingAsk)
                         'labels' => Seats::displayLabels(array_map('strval', (array) $plan['seats']), (string) ($plan['coach'] ?? 'sleeper'), (string) ($plan['bookingMode'] ?? 'sharing'))];
        self::setPending($ctx['phone'], 'sale', ['sale' => $sale]);
        // 31 Oct 2026 (leftover g): a sale line that ASKED ("… milcha?") is answered "yes, available" — not quoted as if ordered
        $text = self::t($asked ? 'sale_avail' : 'sale_quote', $lang, [
            'date' => (string) $plan['dateLabel'], 'dep' => substr((string) $plan['depTime'], 0, 5),
            'route' => $plan['from'] . ' → ' . $plan['to'], 'pickup' => (string) $plan['boardingName'],
            'ptime' => substr((string) $plan['boardingTime'], 0, 5),
            'seats' => Seats::displayLabels(array_map('strval', (array) $plan['seats']), (string) ($plan['coach'] ?? 'sleeper'), (string) ($plan['bookingMode'] ?? 'sharing')),
            'n' => (string) $plan['seatCount'], 'total' => inr((float) $plan['fare']['total']), 'left' => (string) $plan['seatsLeft'],
            'who' => trim(($sale['names'] !== [] ? implode(', ', $sale['names']) : (string) $sale['name']) . ' ' . (string) $sale['phone']),
        ]) . $agentLine;
        if (in_array((string) ($sale['pay'] ?? ''), ['upi', 'esewa', 'bank'], true)) {
            $text .= "\n💳 " . ['upi' => 'UPI', 'esewa' => 'eSewa', 'bank' => 'Bank'][(string) $sale['pay']];   // round 2: "upi bata tircha" shows on the quote
        }
        if (!empty($sale['to_seller'])) {
            $text .= "\n" . ['ne' => '📲 यात्रुको नम्बर नभएकोले टिकट तपाईंकै WhatsApp मा आउँछ (नम्बर पठाए त्यहीँ जान्छ)।',
                             'hi' => '📲 यात्री का नंबर नहीं, टिकट आपके ही WhatsApp पर आएगा (नंबर भेजें तो वहीं जाएगा)।',
                             'en' => '📲 No passenger number — the ticket comes to your own WhatsApp (send a number to change it).'][$lang];
        }
        if ($need !== []) {
            $text .= "\n\n" . self::t('sale_need', $lang, ['what' => implode(' + ', $need)]);
        } else {
            $text .= "\n\n" . self::t($asked ? 'sale_avail_ask' : 'sale_ask_yes', $lang);
        }
        return self::out($text);
    }

    /* 30 Oct 2026: the words of "ok, this one, give the ticket" in Nepali / Hindi / English — never a name. */
    // round 4 (R1): "hola" (= maybe) is no longer a filler of the yes — "ok hola" sold a quote
    // post-deploy probe A4 (R1): nor "la" — "ho la" / "ok ho la" (≈ "hola") sold a quote
    private const FILLER = ['ok', 'okay', 'okk', 'k', 'yes', 'y', 'ya', 'haan', 'han', 'ha', 'ho', 'hoo', 'yo', 'ye', 'yei', 'yehi',
        'yahi', 'yeti', 'yati', 'ticket', 'tiket', 'tikat', 'tciket', 'tickt', 'tikit', 'tkt', 'deu', 'de', 'dinus', 'dinu', 'dinuhos', 'dijiye',
        'dedo', 'do', 'karo', 'kar', 'katnu', 'kata', 'kaat', 'kaatnu', 'katdeu', 'kaatdeu', 'gardeu', 'garnus', 'gara', 'garo', 'please', 'plz',
        'pls', 'sir', 'dai', 'bhai', 'ji', 'hai', 'lau', 'confirm', 'confirmed', 'pakka', 'thik', 'theek', 'cha', 'chha', 'xa', 'hunchha',
        'huncha', 'hunxa', 'done', 'book', 'final', 'ahile', 'aba', 'abhi', 'हो', 'ठीक', 'छ', 'दिनुहोस्', 'देउ', 'टिकट', 'काट्नु', 'काट', 'पक्का',
        'हुन्छ', 'कन्फर्म', 'यो', 'यही', 'यति', 'हाँ', 'दो', 'दीजिए', 'ओके',
        // round 2 (agent sim: "haan ticket nikal do", "ha ticket kaat do", "haan ticket bana do" = Hindi "yes, issue the ticket")
        'haa', 'haanji', 'hanji', 'kato', 'kaato', 'katdo', 'kaatdo', 'kat', 'nikal', 'nikalo', 'nikaldo', 'nikaal', 'nikaalo', 'bana', 'banao',
        'banado', 'banaa', 'banaao', 'banaideu', 'banaidinu', 'banaunus', 'dena', 'dedijiye', 'kardo', 'kardijiye', 'karde', 'निकाल', 'निकालो', 'बना',
        'बनाओ', 'बनादो', 'काटो', 'काट्दो'];
    private const YES_WORDS = ['ok', 'okay', 'okk', 'yes', 'haan', 'han', 'ho', 'hoo', 'deu', 'de', 'dinus', 'dinuhos', 'dijiye', 'dedo',
        'confirm', 'confirmed', 'pakka', 'thik', 'theek', 'done', 'katdeu', 'kaatdeu', 'gardeu', 'हो', 'ठीक', 'देउ', 'दिनुहोस्', 'पक्का',
        'कन्फर्म', 'हाँ', 'दीजिए', 'ओके', 'ha', 'haa', 'haanji', 'hanji', 'katdo', 'kaatdo', 'nikaldo', 'banado', 'banaideu', 'banaidinu', 'kardo'];
    /** "kaat / nikal / bana" + "do / deu / dijiye": an order to issue the ticket — the yes on a complete quote. */
    private const ISSUE_VERBS = ['kat', 'kaat', 'kato', 'kaato', 'kata', 'katnu', 'kaatnu', 'nikal', 'nikalo', 'nikaal', 'nikaalo', 'bana', 'banao',
        'banaa', 'banaao', 'book', 'काट', 'काटो', 'निकाल', 'निकालो', 'बना', 'बनाओ'];
    private const ISSUE_IMPER = ['do', 'de', 'deu', 'dena', 'dijiye', 'dedo', 'dinus', 'dinu', 'dinuhos', 'karo', 'kar', 'kardo', 'karde', 'gardeu',
        'garnus', 'दो', 'देउ', 'दीजिए', 'दिनुहोस्'];

    /** @return list<string> */
    private static function fillerTokens(string $text): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{M}]+/u', mb_strtolower(trim($text))) ?: [], static fn(string $w): bool => $w !== ''));
    }

    private static function fillerOnly(string $text): bool
    {
        $w = self::fillerTokens($text);
        return $w !== [] && array_diff($w, self::FILLER) === [];
    }

    private static function yesPhrase(string $text): bool
    {
        /* Round 4 (31 Oct 2026, owner's rule R1 — safety review A2: "ticket confirm ho?", "confirm?", "ho?", "pakka ho?",
           "ticket done?", "thik cha?", "ok?", "ok hola", "ha ha" each SOLD a complete quote, posting commission and cash
           due): a question is never the yes ("?" or a question word), nor a guess ("hola" = maybe), nor laughter. */
        /* Post-deploy probe A2/A4 (R1): "ho 9812342025", "ok sir 9812342001", "हो ९८१२३४२०९९", "ok 2", "ok 4400", "ok 1" on a
           2-seat quote SOLD the old quote (the word split threw the digits away) — a line with any digit is never the
           yes, it goes to the amend path; "ha ha ok" (laughter first) is not a yes either. */
        $low = mb_strtolower(trim(self::asciiDigits($text)));
        if (str_contains($low, '?') || str_contains($low, '？') || self::isQuestion($low) || preg_match('/\d/', $low) === 1
            || preg_match('/^(?:h+a+[\s,.!]*){2,}|^(?:ha){2,}h?(?![\p{L}\p{M}])/u', $low) === 1) {
            return false;
        }
        if (!self::fillerOnly($text)) {
            return false;
        }
        // "do ticket kaat do" names a COUNT ("two tickets") — that re-plans the quote, it is not its yes
        if (preg_match('/(?<![\p{L}\p{M}])(?:\d+|ek|do|dui|teen|tin|char|chaar|panch|एक|दो|दुई|तीन|चार)\s+(?:tickets?|tikat|tiket|tkt|seats?|sit|jana|log|टिकट|सिट|जना)(?![\p{L}\p{M}])/iu', $text) === 1) {
            return false;
        }
        $w = self::fillerTokens($text);
        return array_intersect($w, self::YES_WORDS) !== []
            || (array_intersect($w, self::ISSUE_VERBS) !== [] && array_intersect($w, self::ISSUE_IMPER) !== []);
    }

    /** Everything a sale needs before its ho: a name, a number, a priced plan. */
    private static function saleComplete(array $sale): bool
    {
        return mb_strlen(trim((string) ($sale['name'] ?? ''))) >= 2 && (string) ($sale['phone'] ?? '') !== '' && !empty($sale['plan']);
    }

    /** "Tciket book", "tikit", "tickt" → "ticket" (one or two letters off, starting with t; never "tickets"). */
    private static function fixTicketWord(string $l): string
    {
        return (string) preg_replace_callback('/\b(t[a-z]{3,7})\b/iu', static function (array $m): string {
            $w = strtolower($m[1]);
            if (str_starts_with($w, 'ticket') || levenshtein($w, 'ticket') > 2
                || in_array($w, ['today', 'time', 'track', 'tomorrow', 'tour', 'tumro', 'timro', 'tapai', 'tapain', 'tyo', 'tala', 'turunt', 'tikat', 'tikut'], true)) {
                return $m[1];
            }
            return 'ticket';
        }, $l);
    }

    /**
     * Round 3 (owner's rule R1): what a waiting quote / preview says when the line was not its answer — kept, with how
     * to answer. "seat kun kun ho?" / "kati bhayo?" on a quote get its seats and total this way.
     */
    private static function pendingAsk(array $p, string $lang): string
    {
        if ((string) ($p['kind'] ?? '') !== 'sale') {
            return self::t('pending_waiting', $lang);
        }
        $sale = (array) ($p['sale'] ?? []);
        $plan = (array) ($sale['plan'] ?? []);
        $who = trim(((array) ($sale['names'] ?? []) !== [] ? implode(', ', (array) $sale['names']) : (string) ($sale['name'] ?? '')) . ' ' . (string) ($sale['phone'] ?? ''));
        $date = (string) ($plan['date'] ?? ($sale['date'] ?? ''));
        // 31 Oct 2026 (leftovers a + f): the one value the desk asked for comes first
        $first = (string) ($sale['ask'] ?? '') !== '' ? self::askLine($sale, (string) $sale['ask'], $lang) . "\n\n" : '';
        return $first . self::t('sale_waiting', $lang, [
            'who' => $who !== '' ? $who : '—',
            'date' => $date !== '' && Security::isValidDate($date) ? formatDate($date, 'D, j M') : $date,
            'n' => (string) (int) ($plan['seats'] ?? ($sale['seats'] ?? 1)),
            'seats' => (string) ($plan['labels'] ?? '') !== '' ? ' (' . $plan['labels'] . ')' : '',
            'total' => isset($plan['total']) ? inr((float) $plan['total']) : '—',
        ]);
    }

    /** 31 Oct 2026 (leftovers a + f): the one value a waiting quote is asked for, in the chat's language. */
    private static function askLine(array $sale, string $field, string $lang): string
    {
        $lang = in_array($lang, ['ne', 'hi', 'en'], true) ? $lang : 'ne';
        if ($field === 'names') {
            $have = (array) ($sale['names'] ?? []) !== [] ? implode(', ', (array) $sale['names']) : trim((string) ($sale['name'] ?? ''));
            $n = max(1, (int) ($sale['addNames'] ?? 1));
            return ['ne' => '👤 थपिएको ' . $n . ' जनाको नाम? (पहिले नै: ' . ($have !== '' ? $have : '—') . ') — जस्तै *naam Sita Thapa*',
                'hi' => '👤 जोड़े गए ' . $n . ' यात्री का नाम? (पहले से: ' . ($have !== '' ? $have : '—') . ') — जैसे *naam Sita Thapa*',
                'en' => '👤 The name of the ' . $n . ' added traveller(s)? (already: ' . ($have !== '' ? $have : '—') . ') — e.g. *naam Sita Thapa*'][$lang];
        }
        return match ($field) {
            'date' => ['ne' => '📅 कुन मिति? · which date? (जस्तै *bholi*, *parsi*, *5 Nov*)', 'hi' => '📅 कौन सी तारीख? · which date? (जैसे *kal*, *parso*, *5 Nov*)',
                'en' => '📅 Which date? (e.g. *tomorrow*, *5 Nov*)'][$lang],
            'seats' => ['ne' => '💺 कति सिट? · how many seats? (जस्तै *2 seat*)', 'hi' => '💺 कितनी सीट? · how many seats? (जैसे *2 seat*)',
                'en' => '💺 How many seats? (e.g. *2 seat*)'][$lang],
            'boarding' => ['ne' => '📍 कहाँबाट चढ्ने? · which pickup? (जस्तै *Surat*, *Baroda*)', 'hi' => '📍 कहाँ से चढ़ना है? · which pickup? (जैसे *Surat*, *Baroda*)',
                'en' => '📍 Which pickup? (e.g. *Surat*, *Baroda*)'][$lang],
            default => ['ne' => '👤 यात्रुको सही नाम? · the right name? (जस्तै *naam Sita Thapa*)', 'hi' => '👤 यात्री का सही नाम? · the right name? (जैसे *naam Sita Thapa*)',
                'en' => '👤 The right passenger name? (e.g. *naam Sita Thapa*)'][$lang],
        };
    }

    /** While a quote waits: "Ram Thapa 98…", "names …", "upi", "parsi", "Mehsana", "3 seat" fill it in. */
    private static function saleAmend(array $pending, string $text, array $member, array $ctx, string $lang): ?array
    {
        $sale = (array) ($pending['sale'] ?? []);
        if ($sale === [] || preg_match('/\bSHG-[A-Z0-9]+-/i', $text) === 1) {
            return null;
        }
        require_once __DIR__ . '/wapayproof.php';
        require_once __DIR__ . '/ticketbot.php';
        require_once __DIR__ . '/personname.php';
        // round 3 (R2): a payment reference / "paisa pathaye" is never an amendment (never a phone, never a name)
        if (WaPayProof::payTalk($text)) {
            return null;
        }
        // round 3 (agent sim: "ho, naam Sita Thapa" got the menu): a yes-word in front of a correction is not the yes
        $text = (string) preg_replace('/^\s*(?:ho|hoo|ha|haan|han|ok|okay|yes|thik\s*cha|theek\s*hai|हो|हाँ)\s*[,.:;-]+\s*(?=(?:naam|name|names|नाम)(?![\p{L}\p{M}]))/iu', '', $text);
        $ask = (string) ($sale['ask'] ?? '');
        /* 31 Oct 2026 (usability leftover b: "non AC nahi chahiyo" / "no luggage" on a waiting quote were read as a name
           change): a "no" aimed at a THING of the trip is a wish — noted, the quote waits as it was. Whatever else the line
           says (a day, a name) is still read. */
        $rest = TicketBot::withoutPrefNegation($text);
        if ($rest !== mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)))) {
            // a number left over ("AC nahi chahiyo 98…") is still read below — fillerTokens sees letters only
            if (array_diff(self::fillerTokens($rest), self::FILLER) === [] && preg_match('/\d/', $rest) !== 1) {
                return self::out(self::t('sale_pref_noted', $lang) . "\n\n" . self::pendingAsk($pending, $lang));
            }
            $text = $rest;
        }
        /* 31 Oct 2026 (usability leftover f): "arko 1 jana thapnu" / "one more seat" on a waiting quote — one more seat, and
           the added traveller's name is asked for before any "ho" can issue it (the yes is refused until it comes). */
        $more = TicketBot::extraSeats($text);
        if ($more > 0) {
            $have = (array) ($sale['names'] ?? []) !== [] ? array_values((array) $sale['names'])
                : (mb_strlen(trim((string) ($sale['name'] ?? ''))) >= 2 ? [trim((string) $sale['name'])] : []);
            $sale['names'] = $have;
            $sale['seats'] = max(count($have), (int) ($sale['seats'] ?? 1)) + $more;
            $sale['ask'] = 'names';
            $sale['addNames'] = (int) ($ask === 'names' ? (int) ($sale['addNames'] ?? 0) : 0) + $more;
            $r = self::saleQuote($sale, $member, $ctx, $lang);
            return str_starts_with((string) $r['text'], '⚠️') ? $r
                : self::out((string) $r['text'] . "\n\n" . self::t('sale_fix_ask', $lang, ['q' => self::askLine($sale, 'names', $lang)]));
        }
        // …and the added traveller's name, when it comes ("Sita Thapa", "naam Sita Thapa", "Sita Thapa ra Gita Thapa")
        if ($ask === 'names') {
            $line = trim((string) preg_replace('/^\s*(?:naam|nam|name|names|नाम)\s*[:\-]?\s*/iu', '', trim((string) preg_replace('/\+?\d[\d\s-]{8,}\d/', ' ', $text))));
            $new = self::nameList($line);
            if ($new === []) {
                $one = self::nameLine($line, TicketBot::parse($line));
                $new = $one !== '' && !self::isQuestion(mb_strtolower($line)) ? [$one] : [];
            }
            $new = array_values(array_filter($new, static fn(string $n): bool => PersonName::clean($n) !== ''));
            if ($new !== []) {
                $want = max(1, (int) ($sale['addNames'] ?? 1));
                $names = array_merge((array) ($sale['names'] ?? []), array_slice($new, 0, $want));
                $sale['names'] = count($names) >= 2 ? $names : [];
                $sale['name'] = $names[0];
                $left = $want - min($want, count($new));
                if ($left > 0) {
                    $sale['addNames'] = $left;
                } else {
                    unset($sale['ask'], $sale['addNames']);
                }
                $r = self::saleQuote($sale, $member, $ctx, $lang);
                return $left > 0 ? self::out((string) $r['text'] . "\n\n" . self::t('sale_fix_ask', $lang, ['q' => self::askLine($sale, 'names', $lang)])) : $r;
            }
        }
        /* 31 Oct 2026 (usability leftover a: "ho tara date galat cha" / "date galat bhayo" on a waiting quote re-showed the
           waiting card): a part said to be wrong WITHOUT the new value — that one value is asked for; the quote waits and
           the next "ho" alone does not issue it (it is asked once more). The value, when it comes, re-quotes as before. */
        $fixField = TicketBot::fixWithoutValue($text);
        if ($fixField !== '' && $ask !== 'names') {
            $sale['ask'] = $fixField;
            self::setPending($ctx['phone'], 'sale', ['sale' => $sale]);
            return self::out(self::t('sale_fix_ask', $lang, ['q' => self::askLine($sale, $fixField, $lang)]));
        }
        /* round 3 (agent sim: "upi ma pathaucha", "esewa bata pathaunchu", "gpay cha", "ho tara upi ma" DROPPED the quote
           and showed the company's pay-in card): on a waiting quote a short line that names how the passenger pays sets
           the pay mode — never the company's UPI card, never a name. "company ko UPI id pathau" is still the card. */
        $l0 = mb_strtolower($text);
        if (count(self::fillerTokens($text)) <= 7 && !str_contains($l0, '?')
            && preg_match('/(?<![\p{L}\p{M}])(cash|nagad|upi|gpay|phonepe|paytm|online|esewa|e-sewa|bank|नगद|नकद)(?![\p{L}\p{M}])/iu', $text, $pm) === 1
            && preg_match('/(?<![\p{L}\p{M}])(?:company|office|kampani|id|qr|scanner|number|nambar|details?|account|kun|kya|k|ke|jama|jamma|deposit|कम्पनी|अफिस)(?![\p{L}\p{M}])/u', $l0) !== 1) {
            $mode = mb_strtolower($pm[1]);
            $sale['pay'] = match ($mode) {
                'नगद', 'नकद', 'nagad' => 'cash',
                'gpay', 'phonepe', 'paytm', 'online' => 'upi',
                'e-sewa' => 'esewa',
                default => $mode,
            };
            return self::saleQuote($sale, $member, $ctx, $lang);
        }
        /* round 2 (counter sim: "What is my sale today?" / "aaj kitna becha?" / "bus today" RE-DATED the waiting quote to
           today and a bare "yes" then sold it for the wrong day): a question or a command is never an amendment — it
           runs as itself and the quote is dropped (reply()). Only a line that is a date / pickup / phone / name / pay
           mode / seat count fills the quote in. Round 3: a question about the quote itself ("seat kun kun ho?",
           "kati bhayo?") gets the waiting quote back. */
        $isQ = self::isQuestion(mb_strtolower(trim($text)));
        if ($isQ || self::canon($text, $ctx) !== null) {
            if ($isQ && self::canon($text, $ctx) === null
                && preg_match('/(?<![\p{L}\p{M}])(?:seats?|sit|berth|kati|kitna|kitne|total|price|rakam|bhada|fare|kun|kaun|which|miti|date|din|kahile|kab|time|baje|पैसा|कति|सिट|कुन)(?![\p{L}\p{M}])/u', $l0) === 1) {
                return self::out(self::pendingAsk($pending, $lang));
            }
            return null;
        }
        $changed = false;
        $askName = false;
        $explicitName = false;
        if (preg_match('/^(?:names?|naam(?:haru)?|नामहरू|नाम)\s*[:\-]?\s*(.+)$/iu', $text, $m) === 1) {
            $explicitName = true;
            // leftover e: "naam Ram Thapa ho, arko Sita Thapa" — two travellers (never one called "arko Sita Thapa")
            // 31 Oct 2026 (review): "arko / aru / other" only as a WORD - never inside "Tarun", "Charu" or "mother"
            $m[1] = (string) preg_replace('/(?<=[\p{L}\p{M}])(?:\s+(?:ho|hun|hu|hai|हो|है))?(?:\s*[,;।]\s*|\s+)(?:(?:ani|ra|aur|and)\s+)?(?<![\p{L}\p{M}])(?:arko|aru|dusro|dosro|dusra|doosra|another|other|second|अर्को|दोस्रो|दूसरा)(?![\p{L}\p{M}])(?:\s+(?:jana|ko|ka|ki))?(?:\s+(?:naam|name|नाम))?\s*[:\-]?\s*(?=\p{L})/iu', ', ', $m[1]);
            $m[1] = (string) preg_replace('/\s+(?:ho|hai|हो|है)\s*[.!।]*\s*$/iu', '', $m[1]);
            $list = array_values(array_filter(array_map(static fn(string $n): string => self::cleanName(trim($n)),
                preg_split('/\s*(?:,|;|&|\band\b|\bra\b|\baur\b|र|और)\s*/u', (string) preg_replace('/\+?\d[\d\s-]{8,}\d/', ' ', $m[1])) ?: [])));
            // round 3: every name must be a name (PersonName) — "naam wait" / "naam utr" never replace the passenger
            $list = array_values(array_filter($list, static fn(string $n): bool => PersonName::clean($n) !== ''));
            if ($list !== []) {
                $sale['names'] = count($list) >= 2 ? $list : [];
                $sale['name'] = $list[0];
                if (count($list) > (int) ($sale['seats'] ?? 1)) {
                    $sale['seats'] = count($list);
                }
                $changed = true;
            } else {
                return self::out(self::t('sale_name_how', $lang));
            }
        }
        // "Ram Thapa ra Sita Thapa" on a 2-seat quote: two passengers, never one called "Ram Thapa Ra Sita Thapa"
        $twoNames = false;
        if (!$changed) {
            $two = self::nameList((string) preg_replace('/\+?\d[\d\s-]{8,}\d/', ' ', $text));
            if (count($two) >= 2) {
                $sale['names'] = $two;
                $sale['name'] = $two[0];
                if (count($two) > (int) ($sale['seats'] ?? 1)) {
                    $sale['seats'] = count($two);
                }
                $changed = true;
                $twoNames = true;
            }
        }
        if ($twoNames) {
            // a phone typed beside the names still counts
            require_once __DIR__ . '/ticketbot.php';
            $p = TicketBot::parse($text);
            if ((string) $p['phone'] !== '') {
                $sale['phone'] = (string) $p['phone'];
                $sale['country'] = (string) $p['country'];
                unset($sale['to_seller']);
            }
        } elseif (!$changed) {
            require_once __DIR__ . '/ticketbot.php';
            require_once __DIR__ . '/boarding.php';
            $p = TicketBot::parse($text);
            if ((string) $p['phone'] !== '') {
                $sale['phone'] = (string) $p['phone'];
                $sale['country'] = (string) $p['country'];
                unset($sale['to_seller']);                    // 31 Oct 2026: a number given = the ticket goes there, not to the seller
                $changed = true;
            }
            /* 31 Oct 2026 (agent sim: "parsi Surat 98…" re-quoted the OLD day and "ho" sold it; "parsi" alone got the
               menu): a day, a pickup or a seat count in the line re-plans the quote before any "ho".
               Round 3 (owner's rule R1): "abhi" / "ahile" / "now" / "aaja" / "today" alone never re-date it — only a
               day that is stated (bholi, parsi, a weekday) or a written date ("2 Nov", "1/11") does. */
            $plan = (array) ($sale['plan'] ?? []);
            /* Round 4 (R1 — safety review A5: on a quote for Mon 2 Nov, "kal confirm karunga", "bholi pheri msg garchu" and
               "customer bholi aaucha" RE-QUOTED it for Sun 1 Nov and "ho" sold that): a day / pickup / seat count changes
               the quote only when the line IS that change — what the parser left over (less a name typed in it) is only
               the words of a change ("date", "ko garnu", "tara" …). A promise or a pause with a day word in it is not. */
            $resid = (string) ($p['residual'] ?? '');
            $nmIn = self::nameLine($text, $p);
            if ($nmIn !== '') {
                $resid = implode(' ', array_diff(preg_split('/\s+/u', mb_strtolower(trim($resid))) ?: [], preg_split('/\s+/u', mb_strtolower($nmIn)) ?: []));
            }
            $amend = TicketBot::amendOnly($resid);
            if ($amend && (string) $p['date'] !== '' && (string) $p['date'] !== (string) ($plan['date'] ?? ($sale['date'] ?? ''))
                && ((string) $p['date'] !== date('Y-m-d') || self::writtenDate($text))) {
                $sale['date'] = (string) $p['date'];
                $changed = true;
            }
            if ($amend && (string) $p['boarding'] !== '' && Boarding::townKey((string) $p['boarding']) !== Boarding::townKey((string) ($plan['boarding'] ?? ($sale['boarding'] ?? '')))) {
                $sale['boarding'] = (string) $p['boarding'];
                $changed = true;
            }
            if ($amend && (int) $p['seats'] > 0 && (int) $p['seats'] !== (int) ($sale['seats'] ?? 1) && $sale['names'] === []) {
                $sale['seats'] = (int) $p['seats'];
                $changed = true;
            }
            /* A name only from a line that IS a name ("Ram Thapa", "Ram Thapa 98…"): never from a sentence or a
               command ("aajako hisab dekhau", "nahi", "Kamal ko bank account number deu" became the passenger).
               Round 3 (owner's rule R1 — agent sim: "wait", "ruka", "ekchin", "pakhnus", "sochchu", "customer lai
               sodhchu" each REPLACED "Ram Thapa" and a later "ho" sold "Wait, Wait (2)"): a quote that already has a
               name is renamed only by "naam …" or by a name typed WITH its mobile; a bare word is never guessed to
               be a new passenger — the desk asks instead. */
            $nm = self::nameLine($text, $p);
            if ($nm !== '') {
                $hasName = mb_strlen(trim((string) ($sale['name'] ?? ''))) >= 2;
                if (!$hasName || (string) $p['phone'] !== '' || $ask === 'name') {   // leftover a: the name the desk asked for
                    $sale['name'] = $nm;
                    $sale['names'] = [];
                    $changed = true;
                } else {
                    $askName = true;
                }
            }
        }
        if ($askName && !$changed) {
            return self::out(self::t('sale_name_how', $lang));
        }
        if ($changed && $ask !== 'names') {
            unset($sale['ask']);                          // leftover a: the value asked for (or another change) came — a fresh quote
        }
        return $changed ? self::saleQuote($sale, $member, $ctx, $lang) : null;
    }

    /** A day written as a date ("2 Nov", "Nov 2", "1/11", "15 tarik", "15 gate"), not a word for now / today. */
    private static function writtenDate(string $text): bool
    {
        $l = mb_strtolower(self::asciiDigits($text));
        return preg_match('/\d{1,2}\s*[\/.\-]\s*\d{1,2}|\d{1,2}\s*(?:st|nd|rd|th)?\s*(?:jan\w*|feb\w*|mar\w*|apr\w*|may|jun\w*|jul\w*|aug\w*|sep\w*|oct\w*|nov\w*|dec\w*|tarik\w*|tarikh\w*|gate|गते|तारिख)|(?:jan\w*|feb\w*|mar\w*|apr\w*|may|jun\w*|jul\w*|aug\w*|sep\w*|oct\w*|nov\w*|dec\w*)\s*\d{1,2}/u', $l) === 1;
    }

    /** Words that are never part of a passenger's name (commands, questions, refusals, verbs). */
    private const NAME_STOP = ['aaja', 'aajako', 'aaj', 'hisab', 'hisaab', 'dekhau', 'dekhaunu', 'dikhao', 'batao', 'balance', 'balanse', 'bal', 'wallet',
        'report', 'sale', 'sales', 'bikri', 'tickets', 'passengers', 'passenger', 'yatri', 'bus', 'gadi', 'office', 'company', 'help', 'menu', 'statement',
        'withdraw', 'number', 'account', 'bank', 'aadhar', 'aadhaar', 'pathau', 'pathaunu', 'deu', 'dinu', 'dinus', 'do', 'what', 'is', 'my', 'me',
        'today', 'kati', 'kitna', 'kitne', 'kya', 'k', 'ke', 'ko', 'ka', 'ki', 'cha', 'chha', 'hai', 'ho', 'nahi', 'nahin', 'nai', 'mat', 'karo',
        'kar', 'pardaina', 'chahidaina', 'chaidaina', 'chahiye', 'chahiyo', 'chaiyo', 'lagi', 'liye', 'kaat', 'kat', 'tikat', 'tiket', 'tciket',
        'date', 'miti', 'seat', 'upi', 'cash', 'ok', 'yes', 'no', 'haan', 'thik', 'theek', 'commission', 'kamisan', 'khali', 'time', 'baje', 'kaha',
        'kata', 'where', 'when', 'how', 'much', 'sold', 'id', 'card', 'visiting', 'portal', 'login', 'hello', 'hi', 'namaste', 'thanks', 'thank',
        'you', 'dhanyabad', 'rehne', 'dijiye', 'chhod', 'chhodo', 'cancel', 'stop', 'hoina', 'bhayena', 'hudaina', 'parena', 'change', 'badal',
        'badalnu', 'pheri', 'feri', 'naya', 'pending', 'list', 'aayo', 'gardeu', 'garnu', 'paryo', 'milena', 'chaina', 'xaina', 'sakdaina',
        // round 2 (agent sim: "upi bata tircha" renamed the passenger "Tircha"): verbs and postpositions of a sentence
        'bata', 'dekhi', 'samma', 'ma', 'le', 'lai', 'tircha', 'tirchu', 'tirne', 'tireko', 'tiryo', 'tirnu', 'garchu', 'garcha', 'garchha',
        'garne', 'gareko', 'garyo', 'gardai', 'gardaichu', 'dinchu', 'dincha', 'dinchha', 'dinchhu', 'pathaunchu', 'pathaucha', 'pathaidinu',
        'pathaideu', 'pathaye', 'pathayo', 'hunchha', 'huncha', 'bhayo', 'thiyo', 'paisa', 'payment', 'pay', 'paid', 'jama', 'online', 'gpay',
        'phonepe', 'paytm', 'esewa', 'nagad', 'karunga', 'karenge', 'dunga', 'denge', 'bhejo', 'bhej', 'sell', 'sale', 'did', 'i', 'will',
        'nikal', 'nikalo', 'nikalegi', 'bana', 'banao', 'and', 'aur', 'ra',
        // round 3 (safety review #1 + agent sim: "Ruko", "Nakaat", "Wait", "Ekchin", "Pakhnus", "Sochchu", "Customer Sodhchu",
        // "Ramesh Pathaidiye" became passengers): pause / refusal / asking words and past-tense verbs are never a name
        'ruko', 'ruka', 'rukiye', 'rukie', 'ruk', 'wait', 'hold', 'later', 'ekchin', 'ekchhin', 'ek', 'chin', 'chhin', 'pakhnus', 'pakha',
        'parkhanus', 'parkhnus', 'parkha', 'nakaat', 'nakat', 'nakaatnu', 'nagarnu', 'nagara', 'baad', 'pachhi', 'pachi', 'paxi', 'abhi', 'ahile',
        'aile', 'now', 'sochchu', 'sochchhu', 'sochu', 'sodhchu', 'sodhchhu', 'sodhera', 'puchhchu', 'puchh', 'puchhke', 'puchhunga', 'batata',
        'bataunga', 'bhanchu', 'customer', 'customers', 'grahak', 'pathaidiye', 'pathaidiyo', 'pathaideko', 'pathaeko', 'pathayeko', 'utr', 'ref',
        'txn', 'reference', 'transaction', 'rrn', 'wapas', 'firta',
        // round 4 (R1 / R2): "hola", promises and refusals, glued payment labels are never a passenger
        'hola', 'karunga', 'karungi', 'karenge', 'aaucha', 'aauchha', 'aunchha', 'aaunchha', 'msg', 'message', 'confirm', 'nakatnu', 'nakaatnu',
        'kaatna', 'katna', 'chaina', 'utrno', 'refno', 'txnid', 'transactionid'];

    /**
     * "Ram Thapa ra Sita Thapa", "Gopal, Sita & Hari": two or more passenger names, or [] when the line is not a plain
     * list of names (a sentence, a command, one name).
     *
     * @return list<string>
     */
    private static function nameList(string $line): array
    {
        $line = trim((string) preg_replace('/^(?:naam|nam|name|names|नाम)\s*[:\-]?\s*/iu', '', trim($line)));
        if ($line === '' || preg_match('/\d|\?/u', $line) === 1) {
            return [];
        }
        $parts = preg_split('/\s*(?:,|;|&|\+|(?<![\p{L}\p{M}])(?:ra|and|aur|र|और)(?![\p{L}\p{M}]))\s*/iu', $line) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part, " .-\t");
            if ($part === '') {
                continue;
            }
            if (preg_match('/^\p{L}[\p{L}\p{M} .]{0,40}$/u', $part) !== 1) {
                return [];
            }
            foreach (self::fillerTokens($part) as $w) {
                if (in_array($w, self::NAME_STOP, true) || in_array($w, self::FILLER, true)) {
                    return [];                                    // a sentence, not a name
                }
            }
            $out[] = implode(' ', array_map(static fn(string $w): string => mb_convert_case($w, MB_CASE_TITLE), preg_split('/\s+/u', $part) ?: []));
        }
        return count($out) >= 2 ? $out : [];
    }

    /**
     * Round 3: the passengers of a sale line typed as a list — "…, Ram Thapa, Sita Thapa and Gita Thapa 98…",
     * "2 seats tomorrow from Surat, Ram Thapa and Sita Thapa 98…". The line is cut at commas and at ra / and / aur;
     * only the pieces that are names (no digit, no command / chat word, not a stop) are kept, in order.
     *
     * @return list<string>
     */
    private static function rawNameList(string $text): array
    {
        require_once __DIR__ . '/personname.php';
        $t = (string) preg_replace('/\+?\d[\d\s-]{8,}\d/u', ' ', self::asciiDigits($text));
        $out = [];
        foreach (preg_split('/\s*[,;]\s*/u', $t) ?: [] as $chunk) {
            foreach (preg_split('/\s*(?:&|\+|(?<![\p{L}\p{M}])(?:ra|and|aur|र|और)(?![\p{L}\p{M}]))\s*/iu', trim($chunk)) ?: [] as $part) {
                $part = trim($part, " .-\t");
                if ($part === '' || preg_match('/^\p{L}[\p{L}\p{M} .]{1,40}$/u', $part) !== 1 || count(preg_split('/\s+/u', $part) ?: []) > 4) {
                    continue;
                }
                $bad = false;
                foreach (self::fillerTokens($part) as $w) {
                    if (in_array($w, self::NAME_STOP, true) || in_array($w, self::FILLER, true)) {
                        $bad = true;
                        break;
                    }
                }
                if ($bad || PersonName::clean($part) === '') {
                    continue;
                }
                $out[] = PersonName::clean($part);
            }
        }
        return $out;
    }

    /** The passenger name a line gives, '' when the line is not a name (a sentence, a command, a refusal). */
    private static function nameLine(string $text, array $p): string
    {
        $nm = self::cleanName((string) ($p['name'] ?? ''));
        if ($nm === '' || self::fillerOnly($text) || str_contains($text, '?')) {
            return '';
        }
        foreach (self::fillerTokens((string) ($p['residual'] ?? $nm)) as $w) {
            if (in_array($w, self::NAME_STOP, true)) {
                return '';
            }
        }
        // letters only (a name), or a name beside a number / a day / a pickup the parser took out
        $bare = trim((string) preg_replace('/\+?\d[\d\s-]{6,}\d/', ' ', $text));
        if ((string) ($p['phone'] ?? '') === '' && preg_match('/^\p{L}[\p{L}\p{M} .]{1,60}$/u', $bare) !== 1) {
            return '';
        }
        // round 3: the one name rule every path shares (no pause / payment / chat word, not a stop, not a number)
        require_once __DIR__ . '/personname.php';
        return PersonName::clean($nm) !== '' ? $nm : '';
    }

    /** A parsed name with the request words taken out ("Kaat Do Mohan Lal" → "Mohan Lal", "Tikat" → ""). */
    private static function cleanName(string $name): string
    {
        $keep = [];
        foreach (preg_split('/\s+/u', trim($name)) ?: [] as $w) {
            if ($w !== '' && !in_array(mb_strtolower($w), self::FILLER, true) && !in_array(mb_strtolower($w), self::NAME_STOP, true)) {
                $keep[] = $w;
            }
        }
        return implode(' ', $keep);
    }

    /* =================================================================
     *  "ho" — do the thing
     * ================================================================= */

    /** @return array{text: string, media?: ?string} */
    private static function confirm(array $p, ?array $member, array $ctx, string $lang): array
    {
        switch ((string) $p['kind']) {
            case 'withdraw':
                if ($member === null) {
                    return self::out(self::t('no_permission', $lang));
                }
                try {
                    // requestPayout locks the member's row and refuses a second open request: a repeated CONFIRM cannot file two.
                    $id = AgentWallet::requestPayout((int) $member['id'], (float) $p['amount'], 'WhatsApp');
                } catch (RuntimeException $e) {
                    return self::out('⚠️ ' . $e->getMessage());
                }
                Logger::audit('agentpro.wa_withdraw', 'admin', (string) $member['id'], null,
                    ['request' => $id, 'amount' => (float) $p['amount'], 'wa' => $ctx['phone']], 'Withdrawal requested from the WhatsApp desk');
                try {
                    require_once __DIR__ . '/notify.php';
                    Notify::adminNote('💸 Withdrawal request (WhatsApp)', ['Member' => (string) $member['full_name'], 'Amount' => inr((float) $p['amount']),
                        'Open' => appUrl('admin/team.php?tab=money')]);
                } catch (Throwable $e) {
                }
                return self::out(self::t('wd_saved', $lang, ['v' => inr((float) $p['amount']), 'id' => (string) $id]));

            case 'deposit':
                // round 2: the payment the member said they made is filed only now, on their "ho"
                if ($member === null) {
                    return self::out(self::t('no_permission', $lang));
                }
                try {
                    $rid = AgentPro::requestDeposit((int) $member['id'], (float) $p['amount'], (string) ($p['purpose'] ?? 'handover'), 'upi', (string) $p['utr'], null, 'WhatsApp');
                } catch (RuntimeException $e) {
                    return self::out('⚠️ ' . $e->getMessage());
                }
                try {
                    require_once __DIR__ . '/notify.php';
                    Notify::adminNote('💵 Payment to verify (WhatsApp)', ['Member' => (string) $member['full_name'], 'Amount' => inr((float) $p['amount']),
                        'UTR' => (string) $p['utr'], 'Open' => appUrl('admin/team.php?tab=money')]);
                } catch (Throwable $e) {
                    // the request is saved; the office sees it on the Team page
                }
                $text = self::t('pay_saved', $lang, ['v' => inr((float) $p['amount']), 'utr' => (string) $p['utr'], 'id' => (string) $rid]);
                self::markDone($ctx['phone'], 'deposit', $text);
                return self::out($text);

            case 'sale':
                return self::saleConfirm((array) $p['sale'], $member, $ctx, $lang);

            case 'edit':
                require_once __DIR__ . '/agentpro-edit.php';
                $before = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM message_logs', [], 0);
                $r = ApEdit::apply((array) $p['change'], $ctx);
                if (!$r['ok']) {
                    return self::out(self::editError($r, $lang));
                }
                // 31 Oct 2026: "the new ticket also went to the passenger" only when WhatsApp took it
                $went = self::sentSince($before, (string) (BookingService::detail((string) $r['pnr'])['contact_phone'] ?? ''));
                return self::out(self::t($went ? 'edit_done' : 'edit_done_nosend', $lang, ['pnr' => (string) $r['pnr']]), $r['media'] ?? null);

            case 'cancel':
                require_once __DIR__ . '/aitools.php';
                $r = AiTools::run('cancel_ticket', ['pnr' => (string) $p['pnr'], 'reason' => 'Cancelled on WhatsApp', 'confirm' => true], $ctx);
                return self::out($r['ok'] ? self::t('cancel_done', $lang, ['pnr' => (string) $p['pnr'], 'v' => (string) ($r['data']['refundLabel'] ?? '')])
                                          : '⚠️ ' . $r['say']);

            case 'cancel_req':
                if ($member === null) {
                    return self::out(self::t('no_permission', $lang));
                }
                $ref = self::officeQueue((string) $p['pnr'], 'refund',
                    'Cancel request for ' . $p['pnr'] . ' (refund by the policy now: ' . $p['refund'] . ($p['note'] !== '' ? ' — ' . $p['note'] : '') . ')'
                    . ((string) ($p['reason'] ?? '') !== '' ? "\nReason: " . $p['reason'] : '') . "\nNothing was cancelled or refunded automatically.", $ctx);
                $text = self::t('cancel_req_sent', $lang, ['pnr' => (string) $p['pnr'], 'hd' => $ref !== '' ? $ref : '—']);
                self::markDone($ctx['phone'], 'cancel_req', $text);
                return self::out($text);

            case 'wd_pay':
                return self::adminPayDo($p, $member, $ctx, $lang);

            case 'wd_decline':
                return self::adminRejectDo($p, $member, $ctx, $lang);

            case 'dep_confirm':
            case 'dep_decline':
                return self::adminDepositDo($p, $member, $ctx, $lang);

            case 'brain':
                // The AI proposed an action and the sender agreed: run it now, without the model.
                return self::act((array) $p['intent'], $member, $ctx, $lang) ?? self::out(self::t('error', $lang));
        }
        return self::out(self::t('cancelled', $lang));
    }

    private static function saleConfirm(array $sale, ?array $member, array $ctx, string $lang): array
    {
        if ($member === null || $ctx['role'] === 'member') {
            return self::out(self::t('no_permission', $lang));
        }
        if (mb_strlen(trim((string) $sale['name'])) < 2 || (string) $sale['phone'] === '') {
            self::setPending($ctx['phone'], 'sale', ['sale' => $sale]);
            return self::out(self::t('sale_need', $lang, ['what' => self::t('f_name', $lang) . ' + ' . self::t('f_phone', $lang)]));
        }
        require_once __DIR__ . '/quickticket.php';
        $want = (array) ($sale['plan'] ?? []);
        $opts = ['seats' => (int) $sale['seats'], 'date' => (string) ($want['date'] ?? $sale['date']), 'direction' => (string) ($want['direction'] ?? $sale['direction']),
                 'boarding' => (string) ($want['boarding'] ?? $sale['boarding']), 'gender' => in_array($sale['gender'], ['Male', 'Female', 'Other'], true) ? $sale['gender'] : null];
        try {
            $fresh = QuickTicket::plan($opts);
        } catch (RuntimeException $e) {
            return self::out('⚠️ ' . $e->getMessage());
        }
        // The sale must be exactly what was quoted: same day, pickup, seats and price.
        if ((string) $fresh['date'] !== (string) ($want['date'] ?? '') || (string) $fresh['boardingCode'] !== (string) ($want['boardingCode'] ?? '')
            || (int) $fresh['seatCount'] !== (int) ($want['seats'] ?? 0) || abs((float) $fresh['fare']['total'] - (float) ($want['total'] ?? 0)) > 0.009) {
            return self::saleQuote($sale, $member, $ctx, $lang);
        }
        $party = [];
        foreach ((array) ($sale['names'] ?? []) as $n) {
            $party[] = ['name' => Security::clean((string) $n, 120), 'gender' => null];
        }
        $logBefore = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM message_logs', [], 0);
        try {
            $res = QuickTicket::sell([
                'name' => (string) $sale['name'], 'phone' => (string) $sale['phone'], 'country' => (string) ($sale['country'] ?? ''),
                'seats' => (int) $sale['seats'], 'date' => $opts['date'], 'direction' => $opts['direction'], 'boarding' => $opts['boarding'],
                'gender' => $opts['gender'], 'pay' => in_array($sale['pay'], ['cash', 'upi', 'esewa', 'bank'], true) ? $sale['pay'] : 'cash',
                'passengers' => count($party) >= 2 ? $party : [], 'note' => 'WhatsApp desk (Agent Pro)',
                'agentCode' => (string) $member['role'] !== 'agent' ? (string) ($sale['agentCode'] ?? '') : '',
            ], $member);
        } catch (RuntimeException $e) {
            return self::out('⚠️ ' . $e->getMessage());
        }
        $pnr = (string) ($res['pnr'] ?? '');
        Logger::audit('agentpro.wa_sale', 'booking', $pnr, null, ['seller' => (int) $member['id'], 'wa' => $ctx['phone'],
            'seats' => (int) $sale['seats'], 'total' => (float) ($want['total'] ?? 0)], 'Sold from the WhatsApp desk');
        $media = ($res['status'] ?? '') === 'confirmed' && $pnr !== '' ? Ticket::imageUrl($pnr) : null;
        /* 31 Oct 2026 (sims): the berths as the ticket prints them (the quote said F4, the sale said L34); "the ticket
           went to 98…" only when WhatsApp took it; "commission on your wallet" only for an agent — a counter holds the
           cash, an office seller earns none, a sale FOR an agent credits that agent. */
        $seatsShown = Seats::displayLabels(array_map('strval', (array) ($res['seats'] ?? [])), (string) ($fresh['coach'] ?? 'sleeper'), (string) ($fresh['bookingMode'] ?? 'sharing'));
        $total = (string) ($res['totalLabel'] ?? '');
        $sent = !empty($sale['to_seller']) ? self::t('sale_sent_you', $lang)
              : self::t(self::sentSince($logBefore, (string) $sale['phone']) ? 'sale_sent' : 'sale_not_sent', $lang, ['phone' => (string) $sale['phone']]);
        $role = (string) $member['role'];
        $soldBy = $pnr !== '' ? (int) Database::scalar('SELECT sold_by_admin_id FROM bookings WHERE pnr = :p', ['p' => $pnr], 0) : 0;
        $company = AgentWallet::resolveAgentCodeFromString(Settings::getString('company_agent_code', 'SHG-0001'));
        $tail = '';
        if ($role === 'agent') {
            $tail = self::t('sale_tail_agent', $lang);
        } else {
            if ($role === 'counter') {
                // round 3 (counter sim: a UPI sale told the counter "cash ₹2,200 is on your account — hand it to the office")
                $tail = self::t(in_array((string) ($sale['pay'] ?? ''), ['upi', 'esewa', 'bank'], true) ? 'sale_tail_counter_upi' : 'sale_tail_counter', $lang, ['total' => $total]);
            }
            if ($soldBy > 0 && $soldBy !== (int) $member['id'] && $soldBy !== (int) $company) {
                $tail .= ($tail !== '' ? "\n" : '') . self::t('sale_tail_credit', $lang, ['code' => AgentWallet::agentCodeLabel($soldBy),
                    'name' => (string) Database::scalar('SELECT full_name FROM admins WHERE id = :i', ['i' => $soldBy], '')]);
            }
        }
        $text = self::t('sale_done', $lang, ['pnr' => $pnr, 'seats' => $seatsShown !== '' ? $seatsShown : implode(', ', array_map('strval', (array) ($res['seats'] ?? []))),
            'total' => $total, 'sent' => $sent, 'tail' => $tail !== '' ? "\n" . $tail : '']);
        if ($pnr !== '') {
            try {
                $text .= "\n📄 PDF: " . Ticket::downloadUrl($pnr);
            } catch (Throwable $e) {
                // the picture is enough
            }
            // A second CONFIRM now answers "already issued" with this PNR — it never sells again.
            self::markDone($ctx['phone'], 'sale', $text, $media);
        }
        return self::out($text, $media);
    }

    /* =================================================================
     *  The AI on this VPS
     * ================================================================= */

    /**
     * Hand a message to the model. On the Meta / Gupshup webhooks the HTTP
     * answer is already sent, so it runs here (one model call at a time);
     * Twilio waits for its answer, so there the job is queued for the worker
     * and the sender gets "⏳" now and the answer in a minute.
     *
     * @return array{text: string, media?: ?string}|null
     */
    private static function thinkSlowly(string $fromRaw, string $phone, string $text, ?array $member, array $ctx, string $lang): ?array
    {
        require_once __DIR__ . '/agentpro-ai.php';
        // 31 Oct 2026 (idc-learn): words the desk was confirmed on (ap_learn_fast_min times) need no model —
        // instant, even with the WhatsApp AI off. Only the READING is skipped: the same handler runs, and
        // money / tickets still wait for "ho". Members only (passengers' words are left to their own bot).
        if ($member !== null && ($fast = ApLearn::fast($text)) !== null) {
            return self::actLearned($fast, $text, $member, $ctx, $lang) ?? self::help($member, $lang);
        }
        if (!ApAi::enabled()) {
            return $member !== null ? self::help($member, $lang) : null;
        }
        if (self::transport() === 'sync') {
            $id = Database::insert('ap_jobs', ['phone' => $phone, 'kind' => $member !== null ? 'wa' : 'wa_customer', 'text' => mb_substr($text, 0, 1500)]);
            self::kickWorker();
            return self::out(self::t('thinking', $lang)) + ['job' => $id];
        }
        if (Settings::getBool('ap_ai_ack', true) && self::transport() === 'flushed') {
            try {
                require_once __DIR__ . '/notify.php';
                Notify::whatsapp($phone, self::t('thinking', $lang), null, str_starts_with(preg_replace('/\D/', '', $fromRaw) ?? '', '977') ? 'NP' : 'IN');
            } catch (Throwable $e) {
                // the answer itself still comes
            }
        }
        return self::think($phone, $text, $member, $ctx, $lang);
    }

    /**
     * Ask the model what the message means, then act on it with the same
     * deterministic handlers as a typed command. Used inline and by the worker.
     *
     * @return array{text: string, media?: ?string}|null
     */
    public static function think(string $phone, string $text, ?array $member, array $ctx, string $lang): ?array
    {
        require_once __DIR__ . '/agentpro-brain.php';
        $ctx['t0'] = (float) ($ctx['t0'] ?? microtime(true));
        $intent = ApBrain::understand($text, $ctx, $lang, $member);
        if ($intent === null) {
            // Model down, busy or out of time: the numbered menu, never a guess.
            return self::aiFallback($member, $lang);
        }
        return self::actLearned($intent, $text, $member, $ctx, $lang) ?? self::aiFallback($member, $lang);
    }

    /**
     * act() on an AI / learnt reading of a member's message. Any action it
     * proposes (withdraw, cancel, a booking's questions → the sale) carries
     * the reading — normalised words, no one's details — so the member's "ho"
     * makes it an example (ApLearn "confirmed"). A passenger's reading is not
     * learnt.
     *
     * @param array<string, mixed> $intent
     * @return array{text: string, media?: ?string}|null
     */
    private static function actLearned(array $intent, string $text, ?array $member, array $ctx, string $lang): ?array
    {
        if ($member === null) {
            return self::act($intent, $member, $ctx, $lang);
        }
        $tag = ['n' => ApLearn::norm($text, $intent), 'i' => ApLearn::skeleton($intent)];
        return self::withLearnTag($tag, fn() => self::act($intent, $member, $ctx, $lang));
    }

    /** "I could not understand" + the office number, and for a member the numbered menu to tap instead. */
    private static function aiFallback(?array $member, string $lang): array
    {
        $head = self::t('ai_down', $lang, ['phone' => Settings::officePhone()]);
        return self::out($member !== null ? $head . "\n\n" . self::help($member, $lang)['text'] : $head);
    }

    /**
     * Run what the model understood. Every branch is a handler a typed
     * command also reaches, so the model only chooses — it never writes.
     *
     * @param array<string, mixed> $i ApBrain::understand()
     * @return array{text: string, media?: ?string}|null
     */
    public static function act(array $i, ?array $member, array $ctx, string $lang): ?array
    {
        $intent = (string) ($i['intent'] ?? 'other');
        $pnr = strtoupper(trim((string) ($i['pnr'] ?? '')));
        if ($member === null) {
            return self::actCustomer($i, $ctx, $lang);
        }
        switch ($intent) {
            case 'book':
                $sale = [
                    'name' => (string) ($i['name'] ?? ''), 'phone' => normalisePhone((string) ($i['phone'] ?? '')), 'country' => '',
                    'seats' => max(1, (int) ($i['seats'] ?? 1), count((array) ($i['names'] ?? []))), 'date' => (string) ($i['date'] ?? ''),
                    'direction' => (string) ($i['direction'] ?? ''), 'boarding' => (string) ($i['boarding'] ?? ''),
                    'gender' => (string) ($i['gender'] ?? ''), 'pay' => (string) ($i['pay'] ?? ''),
                    'names' => count((array) ($i['names'] ?? [])) >= 2 ? array_values((array) $i['names']) : [],
                ];
                if (!in_array((string) $member['role'], self::SELLERS, true)) {
                    return self::out(self::t('no_permission', $lang));
                }
                // What the model read only fills the questions; anything it left out is ASKED, then quoted, then CONFIRM.
                $sale['seats'] = max((int) ($i['seats'] ?? 0), count($sale['names']));
                return self::bookStart($member, $ctx, $lang, array_filter($sale, static fn($v) => $v !== '' && $v !== [] && $v !== 0));
            case 'edit':
                return $pnr !== '' && in_array((string) ($i['field'] ?? ''), ApEdit_fields(), true)
                    ? self::editPreview($pnr, (string) $i['field'], (string) ($i['value'] ?? ''), (string) ($i['which'] ?? ''), $ctx, $lang)
                    : self::out(self::t('ask_pnr', $lang));
            case 'cancel':
            case 'resend':
            case 'status':
                if ($pnr === '') {
                    return self::out(self::t('ask_pnr', $lang));
                }
                return self::pnrCommand($pnr, $pnr . ' ' . ($intent === 'status' ? '' : $intent), $member, $ctx, $lang)
                    ?? self::bookingCard($pnr, $ctx, $lang);
            case 'balance':       return self::balance($member, $lang);
            case 'statement':     return self::statement($member, $lang, (int) ($i['days'] ?? 0));
            case 'withdraw':
            case 'deposit':
                /* Round 4 (R3 — safety review A6: a learnt "withdraw" example for "Kamal ko payment <AMOUNT> gardiye" made
                   the manager's "ho" file a payout on the MANAGER's own account): what the AI / a learnt example reads for
                   the office goes through moneyRoute — that member's request, or "whose money is it?" — never the office's
                   own account (its own is only the bare typed "jama … UTR …" / "withdraw …"). */
                if (self::isOffice($ctx)) {
                    return self::moneyRoute((string) ($ctx['raw_text'] ?? ($ctx['messageText'] ?? '')), $member, $ctx, $lang)
                        ?? self::out(self::t('office_money_whose', $lang));
                }
                if ($intent === 'withdraw') {
                    return self::withdraw($member, $ctx, $lang, (float) ($i['amount'] ?? 0));
                }
                return self::payIn($member, 'jama ' . ((float) ($i['amount'] ?? 0) ?: '') . ((string) ($i['ref'] ?? '') !== '' ? ' UTR ' . $i['ref'] : ''), $lang, $ctx);
            case 'id_card':
                // the office asking for someone else's card ("AG-RAM27 ko ID pathau"): the same lookup as "id <query>"
                $q = trim((string) ($i['query'] ?? ''));
                return $q !== '' && self::isOffice($ctx) ? self::officeId($q, 'id-front', $ctx, $lang) : self::card($member, 'id-front', $lang);
            case 'visiting_card': return self::card($member, 'vc-front', $lang);
            case 'profile':       return self::profile($member, $lang);
            case 'form':          return self::myForm($member, $lang);
            case 'directory':     return self::directory($member, $lang, (string) ($i['query'] ?? ''), 'search');
            case 'company':       return self::company($lang);
            case 'bus':           return self::buses($member, $lang, (string) ($i['date'] ?? '') ?: date('Y-m-d'));
            case 'report':        return self::report($member, $ctx, $lang, '');
            case 'passengers':    return self::passengers($member, $ctx, $lang, (string) ($i['date'] ?? '') ?: date('Y-m-d'));
            case 'tickets':       return self::myTickets($member, $ctx, $lang, (string) ($i['date'] ?? ''));
            case 'portal':        return self::portal($member, $lang);
            case 'pending':       return self::isOffice($ctx) ? self::adminPending($lang) : self::help($member, $lang);
            case 'help':          return self::help($member, $lang);
        }
        // The model on this CPU only READS intent. A free-text answer from it reaches a member only when
        // the office has switched that on (ap_wa_ai_answers); otherwise the numbered menu.
        $answer = trim((string) ($i['answer'] ?? ''));
        return $answer !== '' && Settings::getBool('ap_wa_ai_answers', false) ? self::out($answer) : self::aiFallback($member, $lang);
    }

    /** What the model may do for a passenger: answer, guide a booking, their own tickets. */
    private static function actCustomer(array $i, array $ctx, string $lang): ?array
    {
        $intent = (string) ($i['intent'] ?? 'other');
        $pnr = strtoupper(trim((string) ($i['pnr'] ?? '')));
        require_once __DIR__ . '/aitools.php';
        switch ($intent) {
            case 'book':
                // Re-word it for the local booking engine, which runs the conversation from here.
                $canon = trim(implode(' ', array_filter([
                    ((int) ($i['seats'] ?? 0) > 0 ? (int) $i['seats'] . ' seat' : ''), (string) ($i['date'] ?? ''), (string) ($i['boarding'] ?? ''),
                    (string) ($i['name'] ?? ''), (string) ($i['phone'] ?? ''),
                ])));
                if ($canon !== '') {
                    try {
                        require_once __DIR__ . '/ticketbot.php';
                        require_once __DIR__ . '/quickticket.php';
                        require_once __DIR__ . '/wabooking.php';
                        $r = WaBooking::handle((string) $ctx['phone'], $canon);
                        if ($r !== null) {
                            return ['text' => (string) $r['text'], 'media' => $r['media'] ?? null];
                        }
                    } catch (Throwable $e) {
                        Logger::exception($e, 'whatsapp');
                    }
                }
                return self::out(self::t('cust_book_hint', $lang));
            case 'status':
            case 'resend':
            case 'cancel':
                if ($pnr === '') {
                    $r = AiTools::run('my_tickets', [], $ctx);
                    $list = array_map(static fn(array $b): string => (string) ($b['pnr'] ?? '') . ' · ' . (string) ($b['date'] ?? $b['travelDate'] ?? '') . ' · ' . strtoupper((string) ($b['status'] ?? '')),
                        (array) ($r['data']['bookings'] ?? $r['data']['tickets'] ?? []));
                    return self::out($list !== [] ? implode("\n", $list) . "\n\n" . self::t('ask_pnr', $lang) : self::t('ask_pnr', $lang));
                }
                return self::pnrCommand($pnr, $pnr . ' ' . ($intent === 'status' ? '' : $intent), null, $ctx, $lang);
            case 'edit':
                if ($pnr === '' || !in_array((string) ($i['field'] ?? ''), ApEdit_fields(), true)) {
                    return self::out(self::t('ask_pnr', $lang));
                }
                return self::editPreview($pnr, (string) $i['field'], (string) ($i['value'] ?? ''), (string) ($i['which'] ?? ''), $ctx, $lang);
            case 'bus':
                $r = AiTools::run('bus_eta', $pnr !== '' ? ['pnr' => $pnr] : [], $ctx);
                if ($r['ok'] && !empty($r['data']['live'])) {
                    $lines = [self::t('eta_head', $lang)];
                    foreach (array_slice((array) $r['data']['stops'], 0, 6) as $s) {
                        $lines[] = '📍 ' . $s['stop'] . ' ~' . $s['minutes'] . ' min';
                    }
                    return self::out(implode("\n", $lines));
                }
                return self::out(self::t('eta_none', $lang, ['phone' => Settings::officePhone()]));
            case 'company':
                return self::company($lang);
            case 'join':
                return self::out(self::t('join_link', $lang, ['link' => appUrl('join.php')]));
        }
        $answer = trim((string) ($i['answer'] ?? ''));
        return $answer !== '' ? self::out($answer) : null;
    }

    /* =================================================================
     *  The worker (cron/agentpro-worker.php)
     * ================================================================= */

    /** Work one queued job: think, then send the answer on WhatsApp. */
    public static function runJob(array $job): bool
    {
        $phone = (string) $job['phone'];
        $text = (string) $job['text'];
        $state = self::state($phone);
        $member = $job['kind'] === 'wa' ? self::memberFor($phone) : null;
        $lang = in_array($state['lang'] ?? '', ['ne', 'hi', 'en'], true) ? (string) $state['lang'] : 'ne';
        $ctx = $member !== null ? self::ctx($member, $phone, $text, max(1, (int) $state['turn']))
            : ['role' => 'customer', 'admin' => null, 'adminId' => 0, 'scopeAdminId' => null, 'name' => '', 'phone' => $phone,
               'channel' => 'whatsapp', 'messageText' => $text, 'raw_text' => $text, 'turn' => max(1, (int) $state['turn'])];
        $r = self::think($phone, $text, $member, $ctx, $lang);
        if ($r === null) {
            return true;
        }
        require_once __DIR__ . '/notify.php';
        $ok = Notify::whatsapp($phone, (string) $r['text'], $r['media'] ?? null);
        Database::update('ap_jobs', ['reply' => mb_substr((string) $r['text'], 0, 4000), 'media' => isset($r['media']) ? mb_substr((string) $r['media'], 0, 500) : null],
            'id = :i', ['i' => (int) $job['id']]);
        return $ok !== false;
    }

    /** Start the worker in the background now (cron runs it every minute anyway). */
    public static function kickWorker(): void
    {
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return;
        }
        $php = trim(Settings::getString('ap_php_cli', ''));
        if ($php === '') {
            $php = is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
        }
        @exec(escapeshellarg($php) . ' ' . escapeshellarg(ROOT_PATH . '/cron/agentpro-worker.php') . ' --once > /dev/null 2>&1 &');
    }

    /** cli · sync (Twilio waits for TwiML) · flushed (Meta / Gupshup answered already). */
    public static function transport(): string
    {
        if (PHP_SAPI === 'cli') {
            return defined('AP_WA_TRANSPORT') ? (string) constant('AP_WA_TRANSPORT') : 'cli';
        }
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        return str_ends_with($script, '/api/whatsapp-webhook.php') ? 'sync' : 'flushed';
    }

    /* =================================================================
     *  Words
     * ================================================================= */

    /** @return array{text: string, media: ?string} */
    private static function out(string $text, ?string $media = null): array
    {
        return ['text' => $text, 'media' => $media];
    }

    public static function t(string $key, string $lang, array $v = []): string
    {
        $all = self::words();
        $s = $all[$key][$lang] ?? $all[$key]['ne'] ?? $key;
        foreach ($v as $k => $val) {
            $s = str_replace('{' . $k . '}', (string) $val, $s);
        }
        return $s;
    }

    /** @return array<string, array<string, string>> */
    private static function words(): array
    {
        return [
            'help_seller' => [
                'ne' => "🙏 नमस्ते {name}{code}! {company} WhatsApp डेस्क।\nनम्बर वा शब्द पठाउनुहोस्:\n\n1️⃣ 🎫 टिकट बुक — *ticket* (वा सिधै: *2 सिट भोलि Mehsana, Ram Thapa 98XXXXXXXX*)\n2️⃣ 📋 मेरा टिकट — *tickets* · *tickets bholi*\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 पोर्टल लगइन — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me* · 📄 *form*\n8️⃣ 📊 *aaja* (आजको हिसाब) · *passengers bholi* · *bus*\n9️⃣ 📞 *office* · *contacts*\n\n✏️ सच्याउने: *SHG-… नाम Sita Thapa* (pickup / seat / phone / date पनि)\n❌ रद्द अनुरोध: *SHG-… cancel* · 🔁 *SHG-… resend*\n💵 *jama* · 🌐 *english* / *hindi* / *nepali*",
                'hi' => "🙏 नमस्ते {name}{code}! {company} WhatsApp डेस्क।\nनंबर या शब्द भेजें:\n\n1️⃣ 🎫 टिकट बुक — *ticket* (या सीधे: *2 सीट कल Mehsana, Ram Thapa 98XXXXXXXX*)\n2️⃣ 📋 मेरे टिकट — *tickets* · *tickets kal*\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 पोर्टल लॉगिन — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me* · 📄 *form*\n8️⃣ 📊 *today* · *passengers kal* · *bus*\n9️⃣ 📞 *office* · *contacts*\n\n✏️ बदलें: *SHG-… नाम Sita Thapa* (pickup / seat / phone / date भी)\n❌ रद्द अनुरोध: *SHG-… cancel* · 🔁 *SHG-… resend*\n💵 *jama* · 🌐 *english* / *hindi* / *nepali*",
                'en' => "🙏 Hello {name}{code}! {company} WhatsApp desk.\nSend a number or a word:\n\n1️⃣ 🎫 Ticket — *ticket* (or in one line: *2 seats tomorrow Mehsana, Ram Thapa 98XXXXXXXX*)\n2️⃣ 📋 My tickets — *tickets* · *tickets tomorrow*\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 Portal sign-in — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me* · 📄 *form*\n8️⃣ 📊 *today* · *passengers tomorrow* · *bus*\n9️⃣ 📞 *office* · *contacts*\n\n✏️ Fix: *SHG-… name Sita Thapa* (or pickup / seat / phone / date)\n❌ Cancel request: *SHG-… cancel* · 🔁 *SHG-… resend*\n💵 *pay* · 🌐 *english* / *hindi* / *nepali*",
            ],
            'help_member' => [
                'ne' => "🙏 नमस्ते {name}{code}! {company} WhatsApp डेस्क।\nनम्बर वा शब्द पठाउनुहोस्:\n\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 पोर्टल लगइन — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me* · 📄 *form*\n9️⃣ 📞 *office* · *contacts* · *bus*\n💵 *jama* · 🌐 *english* / *hindi* / *nepali*",
                'hi' => "🙏 नमस्ते {name}{code}! {company} WhatsApp डेस्क।\nनंबर या शब्द भेजें:\n\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 पोर्टल लॉगिन — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me* · 📄 *form*\n9️⃣ 📞 *office* · *contacts* · *bus*\n💵 *jama* · 🌐 *english* / *hindi* / *nepali*",
                'en' => "🙏 Hello {name}{code}! {company} WhatsApp desk.\nSend a number or a word:\n\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 Portal sign-in — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me* · 📄 *form*\n9️⃣ 📞 *office* · *contacts* · *bus*\n💵 *pay* · 🌐 *english* / *hindi* / *nepali*",
            ],
            'help_admin_line' => ['ne' => "0️⃣ 🛡️ *admin* — अफिस: आवेदन, withdraw स्वीकृति, आजको बिक्री", 'hi' => "0️⃣ 🛡️ *admin* — ऑफिस: आवेदन, withdraw मंज़ूरी, आज की बिक्री", 'en' => "0️⃣ 🛡️ *admin* — office: applications, withdrawal approvals, today's sales"],
            'admin_help'  => [
                'ne' => "🛡️ अफिस डेस्क\n📝 *joins* — नयाँ आवेदन ({apps})\n💸 *withdrawals* — withdraw अनुरोध ({wd})\n✅ *approve 12 UTR430987651234* — भुक्तानी गरेपछि रेकर्ड\n⛔ *reject 12 कारण* — अस्वीकार\n📊 *today* — कम्पनीको आजको बिक्री · *pending* — बाँकी काम\n🪪 *id SHG-0027* · *id 98XXXXXXXX* · *id Ram* — जो कोहीको ID कार्ड (फर्वार्ड गर्न)\n💵 *deposits* — भुक्तानी जाँच ({dep}) · *jama ok 12* · *jama no 12 कारण*\n🖥️ {link}\n\nपैसाको हरेक काम *CONFIRM* पछि मात्र हुन्छ।",
                'hi' => "🛡️ ऑफिस डेस्क\n📝 *joins* — नए आवेदन ({apps})\n💸 *withdrawals* — withdraw अनुरोध ({wd})\n✅ *approve 12 UTR430987651234* — भुगतान के बाद दर्ज करें\n⛔ *reject 12 कारण* — अस्वीकार\n📊 *today* — कंपनी की आज की बिक्री · *pending* — बाकी काम\n🪪 *id SHG-0027* · *id 98XXXXXXXX* · *id Ram* — किसी का भी ID कार्ड (फ़ॉरवर्ड के लिए)\n💵 *deposits* — भुगतान जाँच ({dep}) · *jama ok 12* · *jama no 12 कारण*\n🖥️ {link}\n\nपैसे का हर काम *CONFIRM* के बाद ही होता है।",
                'en' => "🛡️ Office desk\n📝 *joins* — new applications ({apps})\n💸 *withdrawals* — withdrawal requests ({wd})\n✅ *approve 12 UTR430987651234* — record a payout you have made\n⛔ *reject 12 reason* — decline\n📊 *today* — the company's sales today · *pending* — what is waiting\n🪪 *id SHG-0027* · *id 98XXXXXXXX* · *id Ram* — anyone's ID card (to forward)\n💵 *deposits* — payments to check ({dep}) · *jama ok 12* · *jama no 12 reason*\n🖥️ {link}\n\nEvery money action happens only after *CONFIRM*.",
            ],
            'adm_pending' => ['ne' => "⏳ बाँकी काम\n📝 आवेदन: {apps}\n💸 withdraw: {wd}\n💵 भुक्तानी जाँच: {dep}\n🪪 KYC: {kyc}\n🎫 भुक्तानी बाँकी बुकिङ: {pay}\n📊 आज टिकट: {t} · 🚌 लाइभ बस: {bus}", 'hi' => "⏳ बाकी काम\n📝 आवेदन: {apps}\n💸 withdraw: {wd}\n💵 भुगतान जाँच: {dep}\n🪪 KYC: {kyc}\n🎫 भुगतान बाकी बुकिंग: {pay}\n📊 आज टिकट: {t} · 🚌 लाइव बस: {bus}", 'en' => "⏳ Waiting\n📝 Applications: {apps}\n💸 Withdrawals: {wd}\n💵 Payments to check: {dep}\n🪪 KYC: {kyc}\n🎫 Unpaid bookings: {pay}\n📊 Tickets today: {t} · 🚌 Live buses: {bus}"],
            'adm_pending_tail' => ['ne' => "👉 *joins* · *withdrawals* · *deposits* · *today*", 'hi' => "👉 *joins* · *withdrawals* · *deposits* · *today*", 'en' => "👉 *joins* · *withdrawals* · *deposits* · *today*"],
            'adm_joins_head' => ['ne' => "📝 स्वीकृतिको लागि आवेदन ({n})", 'hi' => "📝 मंज़ूरी के लिए आवेदन ({n})", 'en' => "📝 Applications waiting ({n})"],
            'adm_joins_tail' => ['ne' => "ID हेरेर स्वीकृत गर्नुहोस्: {link}", 'hi' => "ID देखकर मंज़ूर करें: {link}", 'en' => "Check the ID and approve: {link}"],
            'adm_wd_head' => ['ne' => "💸 खुला withdraw अनुरोध ({n})", 'hi' => "💸 खुले withdraw अनुरोध ({n})", 'en' => "💸 Open withdrawal requests ({n})"],
            'adm_wd_due'  => ['ne' => "कमिसन बाँकी: {v}", 'hi' => "कमीशन बाकी: {v}", 'en' => "commission due: {v}"],
            'adm_wd_tail' => ['ne' => "भुक्तानी गरेपछि: *approve 12 UTR430987651234* · अस्वीकार: *reject 12 कारण*", 'hi' => "भुगतान के बाद: *approve 12 UTR430987651234* · अस्वीकार: *reject 12 कारण*", 'en' => "After paying: *approve 12 UTR430987651234* · decline: *reject 12 reason*"],
            'adm_wd_notopen' => ['ne' => "⚠️ अनुरोध #{id} खुला छैन ({s})।", 'hi' => "⚠️ अनुरोध #{id} खुला नहीं है ({s})।", 'en' => "⚠️ Request #{id} is not open ({s})."],
            'adm_wd_needref' => ['ne' => "✍️ भुक्तानीको UPI / बैंक रेफरेन्स पनि लेख्नुहोस् (कम्तीमा ५ अक्षर): *approve {id} UTR430987651234*", 'hi' => "✍️ भुगतान का UPI / बैंक रेफरेंस भी लिखें (कम से कम 5 अक्षर): *approve {id} UTR430987651234*", 'en' => "✍️ Add the UPI / bank reference of the payment (5+ characters): *approve {id} UTR430987651234*"],
            'adm_wd_needwhy' => ['ne' => "✍️ कारण पनि लेख्नुहोस्: *reject {id} बैंक विवरण मिलेन*", 'hi' => "✍️ कारण भी लिखें: *reject {id} बैंक विवरण नहीं मिला*", 'en' => "✍️ Add the reason: *reject {id} bank details do not match*"],
            'adm_wd_changed' => ['ne' => "⚠️ अनुरोध #{id} को रकम बदलियो — *withdrawals* फेरि हेर्नुहोस्।", 'hi' => "⚠️ अनुरोध #{id} की रकम बदल गई — *withdrawals* फिर देखें।", 'en' => "⚠️ Request #{id} changed — look at *withdrawals* again."],
            'adm_pay_confirm' => ['ne' => "💸 भुक्तानी रेकर्ड गर्ने?\n#{id} · {name} ({code}) · *{v}*\n→ {to}\nRef: {ref}\n\nतपाईंले पैसा पठाइसक्नुभएको हो भने *CONFIRM* लेख्नुहोस् (नभए *no*)।", 'hi' => "💸 भुगतान दर्ज करें?\n#{id} · {name} ({code}) · *{v}*\n→ {to}\nRef: {ref}\n\nपैसा भेज दिया है तो *CONFIRM* लिखें (नहीं तो *no*)।", 'en' => "💸 Record this payout?\n#{id} · {name} ({code}) · *{v}*\n→ {to}\nRef: {ref}\n\nIf you have sent the money, reply *CONFIRM* (or *no*)."],
            'adm_reject_confirm' => ['ne' => "⛔ अनुरोध #{id} ({name}, {v}) अस्वीकार गर्ने?\nकारण: {note}\n\n*CONFIRM* लेख्नुहोस् (नभए *no*)।", 'hi' => "⛔ अनुरोध #{id} ({name}, {v}) अस्वीकार करें?\nकारण: {note}\n\n*CONFIRM* लिखें (नहीं तो *no*)।", 'en' => "⛔ Decline request #{id} ({name}, {v})?\nReason: {note}\n\nReply *CONFIRM* (or *no*)."],
            'adm_paid'    => ['ne' => "✅ #{id} भुक्तानी रेकर्ड भयो — {v} · {voucher}Ref {ref}।\n{told}", 'hi' => "✅ #{id} भुगतान दर्ज — {v} · {voucher}Ref {ref}।\n{told}", 'en' => "✅ #{id} payout recorded — {v} · {voucher}ref {ref}.\n{told}"],
            'adm_rejected'=> ['ne' => "✅ #{id} अस्वीकार गरियो; सदस्यलाई कारण पठाइयो।", 'hi' => "✅ #{id} अस्वीकार; सदस्य को कारण भेजा गया।", 'en' => "✅ #{id} declined; the member was told why."],
            'adm_wd_refused' => ['ne' => "⚠️ रेफरेन्स {ref} अर्को भुक्तानीमा पहिल्यै रेकर्ड छ — एउटै भुक्तानी दुई पटक लेखिँदैन।", 'hi' => "⚠️ रेफरेंस {ref} किसी और भुगतान में पहले से दर्ज है — एक भुगतान दो बार दर्ज नहीं होता।", 'en' => "⚠️ Reference {ref} is already recorded on another payout — one payment is never recorded twice."],
            'adm_dep_head' => ['ne' => "💵 जाँच्न बाँकी भुक्तानी ({n})", 'hi' => "💵 जाँच के लिए भुगतान ({n})", 'en' => "💵 Payments to check ({n})"],
            'adm_dep_held' => ['ne' => "नगद हातमा: {v}", 'hi' => "नकद हाथ में: {v}", 'en' => "cash held: {v}"],
            'adm_dep_security' => ['ne' => "धरौटी (security deposit)", 'hi' => "सिक्योरिटी डिपॉज़िट", 'en' => "security deposit"],
            'adm_dep_handover' => ['ne' => "नगद बुझाएको (cash handover)", 'hi' => "नकद जमा (cash handover)", 'en' => "cash handed over"],
            'adm_dep_tail' => ['ne' => "बैंकमा भेटिएपछि: *jama ok 12* · नभेटिए: *jama no 12 कारण* · फोटो: {link}", 'hi' => "बैंक में मिलने पर: *jama ok 12* · न मिले तो: *jama no 12 कारण* · फोटो: {link}", 'en' => "Found in the bank: *jama ok 12* · not found: *jama no 12 reason* · screenshots: {link}"],
            'adm_dep_notopen' => ['ne' => "⚠️ भुक्तानी #{id} खुला छैन ({s})।", 'hi' => "⚠️ भुगतान #{id} खुला नहीं है ({s})।", 'en' => "⚠️ Payment #{id} is not open ({s})."],
            'adm_dep_needwhy' => ['ne' => "✍️ कारण पनि लेख्नुहोस्: *jama no {id} बैंकमा भेटिएन*", 'hi' => "✍️ कारण भी लिखें: *jama no {id} बैंक में नहीं मिला*", 'en' => "✍️ Add the reason: *jama no {id} not found in the bank*"],
            'adm_dep_toomuch' => ['ne' => "⚠️ #{id}: यो सदस्यसँग कम्पनीको नगद {v} मात्र छ — बाँकीलाई Team पेजमा धरौटी बनाउनुहोस् वा *jama no {id} कारण*।", 'hi' => "⚠️ #{id}: इस सदस्य के पास कंपनी का नकद सिर्फ़ {v} है — बाकी Team पेज पर सिक्योरिटी डिपॉज़िट करें या *jama no {id} कारण*।", 'en' => "⚠️ #{id}: this member holds only {v} of company cash — record the rest as a security deposit on the Team page, or *jama no {id} reason*."],
            'adm_dep_confirm' => ['ne' => "💵 भुक्तानी प्राप्त भएको रेकर्ड गर्ने?\n#{id} · {name} ({code}) · *{v}*\n{how} · {what}\n\nबैंक/हातमा पैसा आएको हो भने *CONFIRM* लेख्नुहोस् (नभए *no*)।", 'hi' => "💵 भुगतान मिलना दर्ज करें?\n#{id} · {name} ({code}) · *{v}*\n{how} · {what}\n\nपैसा बैंक/हाथ में आ गया है तो *CONFIRM* लिखें (नहीं तो *no*)।", 'en' => "💵 Record this payment as received?\n#{id} · {name} ({code}) · *{v}*\n{how} · {what}\n\nIf the money is in the bank / in hand, reply *CONFIRM* (or *no*)."],
            'adm_dep_decline' => ['ne' => "⛔ भुक्तानी #{id} ({name}, {v}) अस्वीकार गर्ने?\nकारण: {note}\n\n*CONFIRM* लेख्नुहोस् (नभए *no*)।", 'hi' => "⛔ भुगतान #{id} ({name}, {v}) अस्वीकार करें?\nकारण: {note}\n\n*CONFIRM* लिखें (नहीं तो *no*)।", 'en' => "⛔ Decline payment #{id} ({name}, {v})?\nReason: {note}\n\nReply *CONFIRM* (or *no*)."],
            'adm_dep_done' => ['ne' => "✅ #{id} {v} प्राप्त भएको रेकर्ड भयो।\n{told}", 'hi' => "✅ #{id} {v} मिलना दर्ज।\n{told}", 'en' => "✅ #{id} {v} recorded as received.\n{told}"],
            'adm_dep_declined' => ['ne' => "✅ #{id} अस्वीकार गरियो।\n{told}", 'hi' => "✅ #{id} अस्वीकार।\n{told}", 'en' => "✅ #{id} declined.\n{told}"],
            'already_done'=> ['ne' => "ℹ️ यो पहिल्यै भइसक्यो — फेरि गरिएन।", 'hi' => "ℹ️ यह पहले ही हो चुका है — दोबारा नहीं किया।", 'en' => "ℹ️ This was already done — nothing was repeated."],
            'nothing_pending' => ['ne' => "ℹ️ अहिले पुष्टि गर्नुपर्ने केही छैन। *help* लेख्नुहोस्।", 'hi' => "ℹ️ अभी पुष्टि करने को कुछ नहीं है। *help* लिखें।", 'en' => "ℹ️ There is nothing waiting for confirmation. Type *help*."],
            'done_no_undo'=> ['ne' => "ℹ️ त्यो भइसकेको छ — फिर्ता गर्न अफिसलाई भन्नुहोस् (टिकट भए *PNR cancel*)।", 'hi' => "ℹ️ वह हो चुका है — वापस करने के लिए ऑफिस से कहें (टिकट हो तो *PNR cancel*)।", 'en' => "ℹ️ That is already done — ask the office to undo it (for a ticket: *PNR cancel*)."],
            'tk_head'     => ['ne' => "📋 तपाईंले बेचेका पछिल्ला टिकट ({n})", 'hi' => "📋 आपके बेचे हाल के टिकट ({n})", 'en' => "📋 Your latest tickets ({n})"],
            'tk_head_date'=> ['ne' => "📋 {d} मा यात्रा गर्ने तपाईंका टिकट ({n})", 'hi' => "📋 {d} को यात्रा वाले आपके टिकट ({n})", 'en' => "📋 Your tickets travelling {d} ({n})"],
            'tk_none'     => ['ne' => "(कुनै टिकट छैन)", 'hi' => "(कोई टिकट नहीं)", 'en' => "(no tickets)"],
            'portal_agent'=> ['ne' => "🔑 तपाईंको पोर्टल: {me}\nएजेन्ट कोड: *{code}* · मोबाइल: {mob}\nत्यहाँ कोड र मोबाइल लेख्नुहोस् — OTP यहीँ WhatsApp मा आउँछ। पछि fingerprint ले पनि खुल्छ।\nयो लिंक/OTP कसैलाई नदिनुहोस्।", 'hi' => "🔑 आपका पोर्टल: {me}\nएजेंट कोड: *{code}* · मोबाइल: {mob}\nवहाँ कोड और मोबाइल लिखें — OTP यहीं WhatsApp पर आएगा। बाद में fingerprint से भी खुलेगा।\nयह OTP किसी को न दें।", 'en' => "🔑 Your portal: {me}\nAgent code: *{code}* · mobile: {mob}\nType the code and mobile there — the OTP comes here on WhatsApp. Later a fingerprint opens it too.\nNever share the OTP."],
            'portal_one'  => ['ne' => "🔑 लगइन: {url}\nमोबाइल ({mob}) लेख्नुहोस् → WhatsApp मा \"hi\" पठाउनुहोस् वा ६ अङ्कको कोड; वा ID *{id}* + पासवर्ड।\nपासवर्ड बिर्सनुभयो? त्यही पेजमा \"पासवर्ड बिर्सनुभयो?\" — WhatsApp ले पुष्टि हुन्छ।\nतपाईंको ID कार्ड र wallet: {me}\nयो कोड/सन्देश कसैलाई नदिनुहोस्।", 'hi' => "🔑 लॉगिन: {url}\nमोबाइल ({mob}) लिखें → WhatsApp पर \"hi\" भेजें या 6 अंकों का कोड; या ID *{id}* + पासवर्ड।\nपासवर्ड भूल गए? उसी पेज पर \"पासवर्ड भूल गए?\" — WhatsApp से पुष्टि होगी।\nआपका ID कार्ड और wallet: {me}\nयह कोड किसी को न दें।", 'en' => "🔑 Sign in: {url}\nType your mobile ({mob}) → send \"hi\" on WhatsApp or get a 6-digit code; or ID *{id}* + password.\nForgot the password? Tap \"Forgot password?\" on the same page — WhatsApp confirms it is you.\nYour ID card and wallet: {me}\nNever share the code."],
            'portal_staff'=> ['ne' => "🔑 तपाईंको पोर्टल: {me}\nस्टाफ साइन-इन: {admin} (युजर: *{user}*)\nपासवर्ड बिर्सनुभयो भने अफिसलाई भन्नुहोस्।", 'hi' => "🔑 आपका पोर्टल: {me}\nस्टाफ साइन-इन: {admin} (यूज़र: *{user}*)\nपासवर्ड भूल गए तो ऑफिस से कहें।", 'en' => "🔑 Your portal: {me}\nStaff sign-in: {admin} (user: *{user}*)\nForgot the password? Ask the office."],
            'bk_no_bus'   => ['ne' => "अहिले बिक्रीमा कुनै बस छैन।", 'hi' => "अभी बिक्री में कोई बस नहीं।", 'en' => "No bus is on sale right now."],
            'bk_q_direction' => ['ne' => "🚌 कुन रुट? नम्बर पठाउनुहोस्:", 'hi' => "🚌 कौन सा रूट? नंबर भेजें:", 'en' => "🚌 Which route? Send the number:"],
            'bk_q_date'   => ['ne' => "📅 कुन दिन? 1. आज ({d1}) · 2. भोलि ({d2}) · 3. पर्सि ({d3})\nवा मिति: *5 Nov*", 'hi' => "📅 किस दिन? 1. आज ({d1}) · 2. कल ({d2}) · 3. परसों ({d3})\nया तारीख: *5 Nov*", 'en' => "📅 Which day? 1. today ({d1}) · 2. tomorrow ({d2}) · 3. day after ({d3})\nor a date: *5 Nov*"],
            'bk_q_pickup' => ['ne' => "📍 कहाँबाट चढ्ने? नम्बर वा ठाउँको नाम:", 'hi' => "📍 कहाँ से चढ़ना है? नंबर या जगह का नाम:", 'en' => "📍 Pickup? Send the number or the town:"],
            'bk_q_seats'  => ['ne' => "💺 कति सिट? (1–{max})", 'hi' => "💺 कितनी सीट? (1–{max})", 'en' => "💺 How many seats? (1–{max})"],
            'bk_q_who'    => ['ne' => "👤 यात्रुको नाम र मोबाइल: *Ram Thapa 98XXXXXXXX*\n({n} जना भए: *names Ram, Sita* अनि नम्बर)", 'hi' => "👤 यात्री का नाम और मोबाइल: *Ram Thapa 98XXXXXXXX*\n({n} लोग हों तो: *names Ram, Sita* और नंबर)", 'en' => "👤 Passenger name and mobile: *Ram Thapa 98XXXXXXXX*\n(for {n} travellers: *names Ram, Sita* and the number)"],
            'bk_stop'     => ['ne' => "(रोक्न *no*)", 'hi' => "(रोकने के लिए *no*)", 'en' => "(*no* to stop)"],
            'bk_again'    => ['ne' => "🤔 बुझिनँ।", 'hi' => "🤔 समझ नहीं आया।", 'en' => "🤔 I did not catch that."],
            'cancel_req_quote' => ['ne' => "❌ {pnr} रद्द गर्ने अनुरोध अफिसमा पठाउने?\nनियम अनुसार फिर्ता अहिले: *{v}*\n{note}\nरद्द र फिर्ता अफिसले नै गर्छ — यहाँबाट केही रद्द हुँदैन।\n\nपठाउने भए *ho* / *CONFIRM* लेख्नुहोस्।", 'hi' => "❌ {pnr} रद्द करने का अनुरोध ऑफिस भेजें?\nनियम से वापसी अभी: *{v}*\n{note}\nरद्द और वापसी ऑफिस ही करता है — यहाँ से कुछ रद्द नहीं होता।\n\nभेजना है तो *ha* / *CONFIRM* लिखें।", 'en' => "❌ Send a cancel request for {pnr} to the office?\nRefund by the policy now: *{v}*\n{note}\nThe office does the cancel and the refund — nothing is cancelled from here.\n\nReply *yes* / *CONFIRM* to send."],
            'cancel_req_sent' => ['ne' => "📨 {pnr} रद्द गर्ने अनुरोध अफिसमा पुग्यो — Help Desk {hd}। टिकट अहिले जस्ताको तस्तै छ; अफिसले फोन गर्छ।", 'hi' => "📨 {pnr} रद्द करने का अनुरोध ऑफिस पहुँचा — Help Desk {hd}। टिकट अभी वैसा ही है; ऑफिस फ़ोन करेगा।", 'en' => "📨 The cancel request for {pnr} is with the office — Help Desk {hd}. The ticket stays as it is until the office acts; they will call."],
            'join_link'   => ['ne' => "🤝 एजेन्ट बन्न यो फारम भर्नुहोस् (५ मिनेट):\n{link}", 'hi' => "🤝 एजेंट बनने के लिए यह फ़ॉर्म भरें (5 मिनट):\n{link}", 'en' => "🤝 Become an agent — fill this form (5 minutes):\n{link}"],
            'join_ok'     => ['ne' => "✅ तपाईंको WhatsApp प्रमाणित भयो (फारम {ref})।\nअब फारममा फर्केर बाँकी भर्नुहोस्।", 'hi' => "✅ आपका WhatsApp सत्यापित हो गया (फ़ॉर्म {ref})।\nअब फ़ॉर्म पर लौटकर बाकी भरें।", 'en' => "✅ Your WhatsApp is verified (form {ref}).\nGo back to the form to finish."],
            'join_bad'    => ['ne' => "⚠️ यो कोड भेटिएन वा पुरानो भयो। फारममा फेरि \"Verify by WhatsApp\" थिच्नुहोस्।", 'hi' => "⚠️ यह कोड नहीं मिला या पुराना है। फ़ॉर्म में फिर \"Verify by WhatsApp\" दबाएँ।", 'en' => "⚠️ That code was not found or has expired. Tap \"Verify by WhatsApp\" on the form again."],
            'slow_down'   => ['ne' => "⏳ धेरै सन्देश आयो। एकछिन पछि फेरि प्रयास गर्नुहोस्।", 'hi' => "⏳ बहुत संदेश आए। थोड़ी देर बाद फिर कोशिश करें।", 'en' => "⏳ Too many messages. Please try again in a little while."],
            'error'       => ['ne' => "⚠️ अहिले मिलेन। एकछिन पछि फेरि पठाउनुहोस्, वा अफिसमा फोन गर्नुहोस्।", 'hi' => "⚠️ अभी नहीं हो पाया। थोड़ी देर बाद फिर भेजें, या ऑफिस को फ़ोन करें।", 'en' => "⚠️ That did not work just now. Try again shortly, or call the office."],
            'reset'       => ['ne' => "🙏 ठिक छ, नयाँ बाट सुरु गरौँ। *help* लेख्नुहोस्।", 'hi' => "🙏 ठीक है, नए सिरे से शुरू करते हैं। *help* लिखें।", 'en' => "🙏 OK, starting fresh. Type *help*."],
            'lang_set'    => ['ne' => "✅ अब नेपालीमा जवाफ दिन्छु।", 'hi' => "✅ अब हिंदी में जवाब दूँगा।", 'en' => "✅ I will reply in English now."],
            'cancelled'   => ['ne' => "👍 ठिक छ, केही गरिएन।", 'hi' => "👍 ठीक है, कुछ नहीं किया गया।", 'en' => "👍 OK, nothing was done."],
            'unlinked'    => ['ne' => "🔓 यो WhatsApp अब कुनै एजेन्ट कोडसँग जोडिएको छैन।", 'hi' => "🔓 यह WhatsApp अब किसी एजेंट कोड से जुड़ा नहीं है।", 'en' => "🔓 This WhatsApp is no longer linked to an agent code."],
            'pick_q'      => ['ne' => "🪪 यो WhatsApp नम्बरमा एकभन्दा बढी ID छन्। कुन ID बाट काम गर्ने?", 'hi' => "🪪 इस WhatsApp नंबर पर एक से ज़्यादा ID हैं। किस ID से काम करें?", 'en' => "🪪 This WhatsApp number has more than one ID. Which ID do you want to use?"],
            'pick_how'    => ['ne' => "नम्बर मात्र पठाउनुहोस् (जस्तै *1*)। पछि बदल्न: *switch*", 'hi' => "सिर्फ़ नंबर भेजें (जैसे *1*)। बाद में बदलने के लिए: *switch*", 'en' => "Send just the number (e.g. *1*). To change later: *switch*"],
            'pick_ok'     => ['ne' => "✅ अब तपाईं {code} को रूपमा हुनुहुन्छ। अर्को ID: *switch*", 'hi' => "✅ अब आप {code} के रूप में हैं। दूसरी ID: *switch*", 'en' => "✅ You are now working as {code}. Another ID: *switch*"],
            'switch_one'  => ['ne' => "ℹ️ यो नम्बरमा एउटा मात्र ID छ।", 'hi' => "ℹ️ इस नंबर पर सिर्फ़ एक ID है।", 'en' => "ℹ️ This number has only one ID."],
            'switch_linked' => ['ne' => "ℹ️ यो WhatsApp एउटा ID सँग OTP ले जोडिएको छ। अर्को ID: पहिले *unlink*, अनि *LINK <कोड>*", 'hi' => "ℹ️ यह WhatsApp एक ID से OTP से जुड़ा है। दूसरी ID: पहले *unlink*, फिर *LINK <कोड>*", 'en' => "ℹ️ This WhatsApp is linked to one ID with an OTP. For another ID: send *unlink*, then *LINK <code>*."],
            'link_unknown'=> ['ne' => "⚠️ त्यो कोड वा नम्बर कुनै सक्रिय खातासँग मिलेन। उदाहरण: *LINK SHG-0027*", 'hi' => "⚠️ यह कोड या नंबर किसी सक्रिय खाते से नहीं मिला। उदाहरण: *LINK SHG-0027*", 'en' => "⚠️ That code or number does not match an active account. Example: *LINK SHG-0027*"],
            'link_already'=> ['ne' => "✅ यो WhatsApp पहिल्यै {code} सँग जोडिएको छ। *help* लेख्नुहोस्।", 'hi' => "✅ यह WhatsApp पहले से {code} से जुड़ा है। *help* लिखें।", 'en' => "✅ This WhatsApp is already linked to {code}. Type *help*."],
            'link_sent'   => ['ne' => "🔐 {code} को दर्ता भएको मोबाइल (…{tail}) मा ६ अंकको कोड पठाइयो।\nत्यो कोड यहीँ लेख्नुहोस्।", 'hi' => "🔐 {code} के रजिस्टर्ड मोबाइल (…{tail}) पर 6 अंकों का कोड भेजा गया।\nवह कोड यहीं लिखें।", 'en' => "🔐 A 6-digit code went to the mobile registered for {code} (…{tail}).\nType that code here."],
            'link_bad_otp'=> ['ne' => "⚠️ कोड मिलेन। फेरि लेख्नुहोस्, वा *LINK* फेरि पठाउनुहोस्।", 'hi' => "⚠️ कोड गलत है। फिर लिखें, या *LINK* फिर भेजें।", 'en' => "⚠️ That code is not right. Type it again, or send *LINK* again."],
            'link_ok'     => ['ne' => "🎉 जोडियो! यो WhatsApp अब {code} हो — यहाँबाट काटिएका टिकट तपाईंकै कोडमा।", 'hi' => "🎉 जुड़ गया! यह WhatsApp अब {code} है — यहाँ से कटे टिकट आपके कोड में।", 'en' => "🎉 Linked! This WhatsApp is now {code} — tickets sold here go on your code."],
            'bal_head'    => ['ne' => "💰 {name} · {code}", 'hi' => "💰 {name} · {code}", 'en' => "💰 {name} · {code}"],
            'bal_commission' => ['ne' => "🟢 कम्पनीले तपाईंलाई दिनुपर्ने (कमिसन): *{v}*", 'hi' => "🟢 कंपनी को आपको देना है (कमीशन): *{v}*", 'en' => "🟢 Company owes you (commission): *{v}*"],
            'bal_cash'    => ['ne' => "🔸 तपाईंसँग रहेको कम्पनीको नगद: *{v}*", 'hi' => "🔸 आपके पास कंपनी का नकद: *{v}*", 'en' => "🔸 Company cash you hold: *{v}*"],
            'bal_month'   => ['ne' => "📈 यो महिनाको कमाइ: {v}", 'hi' => "📈 इस महीने की कमाई: {v}", 'en' => "📈 Earned this month: {v}"],
            'bal_open'    => ['ne' => "⏳ withdraw अनुरोध {v} ({d}) अफिसमा छ।", 'hi' => "⏳ withdraw अनुरोध {v} ({d}) ऑफिस में है।", 'en' => "⏳ Withdrawal request {v} ({d}) is with the office."],
            'bal_dep'     => ['ne' => "⏳ {n} भुक्तानी ({v}) अफिसले जाँच्दैछ।", 'hi' => "⏳ {n} भुगतान ({v}) ऑफिस जाँच रहा है।", 'en' => "⏳ {n} payment(s) ({v}) waiting for the office."],
            'bal_tail'    => ['ne' => "👉 *statement* · *withdraw 2000* · *jama*", 'hi' => "👉 *statement* · *withdraw 2000* · *jama*", 'en' => "👉 *statement* · *withdraw 2000* · *pay*"],
            'stmt_head'   => ['ne' => "📒 {name} — पछिल्लो {days} दिन", 'hi' => "📒 {name} — पिछले {days} दिन", 'en' => "📒 {name} — last {days} days"],
            'stmt_empty'  => ['ne' => "(यो अवधिमा केही छैन)", 'hi' => "(इस अवधि में कुछ नहीं)", 'en' => "(nothing in this period)"],
            'stmt_pdf'    => ['ne' => "📄 पूरा statement (PDF): {url}", 'hi' => "📄 पूरा statement (PDF): {url}", 'en' => "📄 Full statement (PDF): {url}"],
            'wd_nothing'  => ['ne' => "ℹ️ अहिले निकाल्न मिल्ने कमिसन छैन।", 'hi' => "ℹ️ अभी निकालने लायक कमीशन नहीं है।", 'en' => "ℹ️ There is no commission to withdraw right now."],
            'wd_too_much' => ['ne' => "⚠️ बढीमा {v} मात्र निकाल्न मिल्छ।", 'hi' => "⚠️ ज़्यादा से ज़्यादा {v} निकाल सकते हैं।", 'en' => "⚠️ You can withdraw up to {v}."],
            'wd_open'     => ['ne' => "⏳ तपाईंको अघिल्लो withdraw अनुरोध अझै अफिसमा छ।", 'hi' => "⏳ आपका पिछला withdraw अनुरोध अभी ऑफिस में है।", 'en' => "⏳ Your earlier withdrawal request is still with the office."],
            'wd_to_office'=> ['ne' => "अफिसमा (भुक्तानी विवरण /me/ मा थप्नुहोस्)", 'hi' => "ऑफिस में (भुगतान विवरण /me/ में जोड़ें)", 'en' => "at the office (add payout details in /me/)"],
            'wd_confirm'  => ['ne' => "💸 *{v}* निकाल्ने अनुरोध पठाउने?\n👤 {who} (तपाईंकै हिसाबबाट)\nपठाउने ठाउँ: {to}\n\n*ho* लेख्नुहोस् (नभए *no*)।", 'hi' => "💸 *{v}* निकालने का अनुरोध भेजें?\n👤 {who} (आपके अपने हिसाब से)\nभेजने की जगह: {to}\n\n*ha* लिखें (नहीं तो *no*)।", 'en' => "💸 Send a withdrawal request for *{v}*?\n👤 {who} (from your own wallet)\nPaid to: {to}\n\nReply *yes* (or *no*)."],
            'wd_saved'    => ['ne' => "✅ {v} को अनुरोध #{id} अफिसमा पुग्यो। भुक्तानी भएपछि यहीँ खबर आउँछ।", 'hi' => "✅ {v} का अनुरोध #{id} ऑफिस पहुँच गया। भुगतान होने पर यहीं सूचना आएगी।", 'en' => "✅ Request #{id} for {v} is with the office. You will be told here when it is paid."],
            'pay_how'     => ['ne' => "💵 तपाईंसँग कम्पनीको नगद: {held}\n\nUPI: *{upi}* मा {v} तिर्नुहोस् (QR माथि छ)\nतिरेपछि यस्तो लेख्नुहोस्:\n*jama 1500 UTR 430987651234*", 'hi' => "💵 आपके पास कंपनी का नकद: {held}\n\nUPI: *{upi}* पर {v} भरें (QR ऊपर है)\nभरने के बाद ऐसे लिखें:\n*jama 1500 UTR 430987651234*", 'en' => "💵 Company cash you hold: {held}\n\nPay {v} to UPI *{upi}* (QR above)\nThen send:\n*pay 1500 UTR 430987651234*"],
            'pay_saved'   => ['ne' => "✅ {v} (UTR {utr}) अफिसमा पठाइयो — #{id}। बैंकमा मिलेपछि यहीँ पुष्टि आउँछ।", 'hi' => "✅ {v} (UTR {utr}) ऑफिस भेजा गया — #{id}। बैंक में मिलने पर यहीं पुष्टि आएगी।", 'en' => "✅ {v} (UTR {utr}) sent to the office — #{id}. You will get a confirmation here once it is matched."],
            'pay_confirm' => ['ne' => "💵 कम्पनीलाई तिरेको *{v}* (UTR {utr}) अफिसलाई जाँच्न पठाउने?\n👤 तिर्ने: {who} (तपाईंकै हिसाबमा)\n\n*ho* लेख्नुहोस् (नभए *no*)। अहिले केही रेकर्ड भएको छैन।", 'hi' => "💵 कंपनी को भरे *{v}* (UTR {utr}) ऑफिस को जाँच के लिए भेजें?\n👤 भरने वाले: {who} (आपके अपने हिसाब में)\n\n*ha* लिखें (नहीं तो *no*)। अभी कुछ दर्ज नहीं हुआ है।", 'en' => "💵 Send *{v}* paid to the company (UTR {utr}) to the office to check?\n👤 Paid by: {who} (on your own account)\n\nReply *yes* (or *no*). Nothing is recorded yet."],
            'pay_cash_note' => ['ne' => "💵 {v} नगद अफिसमा बुझाएको — अफिसले Team पेजमा पुष्टि गरेपछि तपाईंको खातामा देखिन्छ। यहाँबाट केही रेकर्ड गरिएन।\nबैंक / UPI बाट तिरेको भए: *jama 1500 UTR 430987651234*", 'hi' => "💵 {v} नकद ऑफिस में जमा — ऑफिस Team पेज पर पुष्टि करेगा तब आपके खाते में दिखेगा। यहाँ से कुछ दर्ज नहीं किया।\nबैंक / UPI से भरा हो तो: *jama 1500 UTR 430987651234*", 'en' => "💵 {v} cash handed to the office — it shows on your wallet once the office confirms it on the Team page. Nothing was recorded from here.\nPaid by bank / UPI? Send: *pay 1500 UTR 430987651234*"],
            'pf_head'     => ['ne' => "🔎 {q} — तपाईंका टिकट ({n}):", 'hi' => "🔎 {q} — आपके टिकट ({n}):", 'en' => "🔎 {q} — your tickets ({n}):"],
            'pf_none'     => ['ne' => "🔎 {q} नामको यात्रु तपाईंका टिकटमा भेटिएन। PNR भए पठाउनुहोस्, वा *tickets* लेख्नुहोस्।", 'hi' => "🔎 {q} नाम का यात्री आपके टिकटों में नहीं मिला। PNR हो तो भेजें, या *tickets* लिखें।", 'en' => "🔎 No passenger called {q} among your tickets. Send the PNR, or write *tickets*."],
            'bal_commission_o' => ['ne' => "🟢 कम्पनीले {name} लाई दिनुपर्ने (कमिसन): *{v}*", 'hi' => "🟢 कंपनी को {name} को देना है (कमीशन): *{v}*", 'en' => "🟢 Company owes {name} (commission): *{v}*"],
            'bal_cash_o'  => ['ne' => "🔸 {name} सँग रहेको कम्पनीको नगद: *{v}*", 'hi' => "🔸 {name} के पास कंपनी का नकद: *{v}*", 'en' => "🔸 Company cash {name} holds: *{v}*"],
            'bal_tail_o'  => ['ne' => "👉 *statement {code}* · *withdrawals* · *deposits*", 'hi' => "👉 *statement {code}* · *withdrawals* · *deposits*", 'en' => "👉 *statement {code}* · *withdrawals* · *deposits*"],
            'adm_wd_none_many' => ['ne' => "ℹ️ {list} — कसैको पनि खुला निकासी अनुरोध छैन। केही रेकर्ड गरिएन।", 'hi' => "ℹ️ {list} — किसी का भी खुला निकासी अनुरोध नहीं। कुछ दर्ज नहीं किया।", 'en' => "ℹ️ {list} — none of them has an open withdrawal request. Nothing was recorded."],
            'adm_dep_none_many' => ['ne' => "ℹ️ {list} — कसैको पनि {v} को खुला भुक्तानी अनुरोध छैन — सूची: *deposits*। केही रेकर्ड गरिएन।", 'hi' => "ℹ️ {list} — किसी का भी {v} का खुला भुगतान अनुरोध नहीं — सूची: *deposits*। कुछ दर्ज नहीं किया।", 'en' => "ℹ️ {list} — none of them has an open payment {v} — list: *deposits*. Nothing was recorded."],
            'pay_need_ref' => ['ne' => "💵 {v} तिर्नुभयो भने बैंक / UPI को UTR (रेफरेन्स) नम्बर पठाउनुहोस्, जस्तै: *jama {n} UTR 430987651234*\nत्यसपछि *ho* भनेपछि मात्र अफिसमा जान्छ। अहिले केही रेकर्ड भएको छैन।", 'hi' => "💵 {v} भर दिए हैं तो बैंक / UPI का UTR (रेफरेंस) नंबर भेजें, जैसे: *jama {n} UTR 430987651234*\nफिर *ha* कहने पर ही ऑफिस जाएगा। अभी कुछ दर्ज नहीं हुआ है।", 'en' => "💵 If you paid {v}, send the bank / UPI reference (UTR), like: *pay {n} UTR 430987651234*\nIt goes to the office only after your *yes*. Nothing is recorded yet."],
            'pay_ref_used' => ['ne' => "⚠️ UTR {utr} पहिले नै पठाइसकिएको छ — केही रेकर्ड गरिएन।", 'hi' => "⚠️ UTR {utr} पहले ही भेजा जा चुका है — कुछ दर्ज नहीं किया।", 'en' => "⚠️ UTR {utr} was already sent — nothing was recorded."],
            'cancel_which' => ['ne' => "❌ कुन टिकट? PNR सहित पठाउनुहोस्: *SHG-2026-01234 cancel* (आफ्ना टिकट: *tickets*)। रद्द / फिर्ता अफिसले नै गर्छ।", 'hi' => "❌ कौन सा टिकट? PNR के साथ भेजें: *SHG-2026-01234 cancel* (अपने टिकट: *tickets*)। रद्द / वापसी ऑफिस ही करता है।", 'en' => "❌ Which ticket? Send it with the PNR: *SHG-2026-01234 cancel* (your tickets: *tickets*). The office does cancellations / refunds."],
            'resent_not_sent' => ['ne' => "📲 {pnr} को टिकट WhatsApp मा अहिले जान सकेन — यो टिकट फोटो / PDF लिंक यात्रुलाई फर्वार्ड गर्नुहोस्।", 'hi' => "📲 {pnr} का टिकट WhatsApp पर अभी नहीं जा सका — यह टिकट फोटो / PDF लिंक यात्री को फॉरवर्ड करें।", 'en' => "📲 The ticket for {pnr} did not go through on WhatsApp just now — forward this ticket picture / PDF link to the passenger."],
            'card_id'     => ['ne' => "🪪 तपाईंको ID कार्ड ({code})\nपछाडि: *id back* · प्रिन्ट / डाउनलोड: {me}", 'hi' => "🪪 आपका ID कार्ड ({code})\nपीछे: *id back* · प्रिंट / डाउनलोड: {me}", 'en' => "🪪 Your ID card ({code})\nBack side: *id back* · print / download: {me}"],
            'card_vc'     => ['ne' => "💼 तपाईंको visiting card — QR स्क्यान गर्दा सिधै WhatsApp {wa}\nपछाडि: *card back*\nसेयर गर्ने लिंक: {share}", 'hi' => "💼 आपका visiting card — QR स्कैन करते ही सीधे WhatsApp {wa}\nपीछे: *card back*\nशेयर लिंक: {share}", 'en' => "💼 Your visiting card — scanning the QR opens WhatsApp {wa}\nBack side: *card back*\nShare link: {share}"],
            'profile'     => ['ne' => "👤 {name}\n🪪 {code} · {role}\n☎ {phone} · WA {wa}\n📍 {branch}\n✅ KYC: {kyc} · कार्ड {valid} सम्म\n🏦 {bank}\n📱 {me}", 'hi' => "👤 {name}\n🪪 {code} · {role}\n☎ {phone} · WA {wa}\n📍 {branch}\n✅ KYC: {kyc} · कार्ड {valid} तक\n🏦 {bank}\n📱 {me}", 'en' => "👤 {name}\n🪪 {code} · {role}\n☎ {phone} · WA {wa}\n📍 {branch}\n✅ KYC: {kyc} · card valid till {valid}\n🏦 {bank}\n📱 {me}"],
            'form_signed' => ['ne' => "📄 तपाईंको भरिएको फारम (PDF):\n{url}\nयो लिंक तपाईंको मात्र हो — ID / बैंक नम्बर पूरै देखिन्छ, कसैलाई नपठाउनुहोस्।\nपछि: {me} (पोर्टलमा लगइन गरेर)", 'hi' => "📄 आपका भरा हुआ फ़ॉर्म (PDF):\n{url}\nयह लिंक सिर्फ़ आपका है — ID / बैंक नंबर पूरे दिखते हैं, किसी को न भेजें।\nबाद में: {me} (पोर्टल में लॉगिन करके)", 'en' => "📄 Your filled form (PDF):\n{url}\nThis link is yours alone — it shows your ID / bank numbers in full; do not forward it.\nLater: {me} (after signing in to the portal)"],
            'form_signin' => ['ne' => "📄 तपाईंको भरिएको फारम (PDF): {url}\nपहिले पोर्टलमा लगइन गर्नुहोस् ({me} — कोड + मोबाइल + WhatsApp OTP), अनि लिंक खोल्नुहोस्। *portal* लेखे लगइनको तरिका आउँछ।", 'hi' => "📄 आपका भरा हुआ फ़ॉर्म (PDF): {url}\nपहले पोर्टल में लॉगिन करें ({me} — कोड + मोबाइल + WhatsApp OTP), फिर लिंक खोलें। *portal* लिखें तो लॉगिन का तरीका आएगा।", 'en' => "📄 Your filled form (PDF): {url}\nSign in to the portal first ({me} — code + mobile + WhatsApp OTP), then open the link. Send *portal* for how to sign in."],
            'form_none'   => ['ne' => "📄 तपाईंको नाममा भरिएको फारम भेटिएन। अफिसले भरिदिन सक्छ — फोन गर्नुहोस्, वा {me} मा हेर्नुहोस्।", 'hi' => "📄 आपके नाम पर भरा हुआ फ़ॉर्म नहीं मिला। ऑफिस भर सकता है — फ़ोन करें, या {me} पर देखें।", 'en' => "📄 No filled form was found for you. The office can fill it in — call them, or look at {me}."],
            'oid_one'     => ['ne' => "🪪 {name} · {code}\n{role} · 📍 {branch} · ☎ {phone}\nकार्ड: {status} · KYC: {kyc}\n💼 Visiting card: {vc}\n🔗 सेयर: {share}\nपछाडि: *id {back} back* · यो सन्देश फर्वार्ड गर्न मिल्छ (लिंक २४ घण्टा)।", 'hi' => "🪪 {name} · {code}\n{role} · 📍 {branch} · ☎ {phone}\nकार्ड: {status} · KYC: {kyc}\n💼 Visiting card: {vc}\n🔗 शेयर: {share}\nपीछे: *id {back} back* · यह संदेश फ़ॉरवर्ड कर सकते हैं (लिंक 24 घंटे)।", 'en' => "🪪 {name} · {code}\n{role} · 📍 {branch} · ☎ {phone}\nCard: {status} · KYC: {kyc}\n💼 Visiting card: {vc}\n🔗 Share: {share}\nBack side: *id {back} back* · you can forward this message (links last 24 h)."],
            'oid_many'    => ['ne' => "🔎 \"{q}\" — {n} जना भेटिए:", 'hi' => "🔎 \"{q}\" — {n} लोग मिले:", 'en' => "🔎 \"{q}\" — {n} found:"],
            'oid_pick'    => ['ne' => "कोड पठाउनुहोस्: *id SHG-0027*", 'hi' => "कोड भेजें: *id SHG-0027*", 'en' => "Send the code: *id SHG-0027*"],
            'oid_none'    => ['ne' => "🔎 \"{q}\" नामको सक्रिय सदस्य भेटिएन। कोड, मोबाइल वा नाम लेख्नुहोस्: *id SHG-0027* · *id 98XXXXXXXX* · *id Ram*", 'hi' => "🔎 \"{q}\" से कोई सक्रिय सदस्य नहीं मिला। कोड, मोबाइल या नाम लिखें: *id SHG-0027* · *id 98XXXXXXXX* · *id Ram*", 'en' => "🔎 No active member matches \"{q}\". Send a code, mobile or name: *id SHG-0027* · *id 98XXXXXXXX* · *id Ram*"],
            'dir_head'    => ['ne' => "📇 सम्पर्क ({n})", 'hi' => "📇 संपर्क ({n})", 'en' => "📇 Contacts ({n})"],
            'dir_more'    => ['ne' => "नाम लेखेर खोज्नुहोस्: *number Ram*", 'hi' => "नाम लिखकर खोजें: *number Ram*", 'en' => "search by name: *number Ram*"],
            'dir_none'    => ['ne' => "(कोही भेटिएन)", 'hi' => "(कोई नहीं मिला)", 'en' => "(nobody found)"],
            'dir_hidden'  => ['ne' => "🔒 एजेन्टको निजी नम्बर WhatsApp मा दिइँदैन (वेबसाइटमा देखिने एजेन्टको मात्र)। अफिस: {phone}", 'hi' => "🔒 एजेंट का निजी नंबर WhatsApp पर नहीं दिया जाता (सिर्फ वेबसाइट पर दिखने वाले एजेंट का)। ऑफिस: {phone}", 'en' => "🔒 An agent's personal number is not given on WhatsApp (only agents listed on the website). Office: {phone}"],
            'co_operator' => ['ne' => "👔 सञ्चालक: {n}", 'hi' => "👔 संचालक: {n}", 'en' => "👔 Director: {n}"],
            'bus_head'    => ['ne' => "🚌 बसहरू — {d}", 'hi' => "🚌 बसें — {d}", 'en' => "🚌 Buses — {d}"],
            'bus_sold'    => ['ne' => "{n} सिट बिक्री", 'hi' => "{n} सीट बिकी", 'en' => "{n} seats sold"],
            'bus_none'    => ['ne' => "(यो दिन कुनै बस छैन)", 'hi' => "(इस दिन कोई बस नहीं)", 'en' => "(no bus on this day)"],
            'rep_office'  => ['ne' => "📊 कम्पनी — {d}\n🎫 {t} टिकट · {v}\n⏳ भुक्तानी जाँच बाँकी: {p}", 'hi' => "📊 कंपनी — {d}\n🎫 {t} टिकट · {v}\n⏳ भुगतान जाँच बाकी: {p}", 'en' => "📊 Company — {d}\n🎫 {t} tickets · {v}\n⏳ Payments to verify: {p}"],
            'rep_agent'   => ['ne' => "📊 तपाईंको दिन — {d}\n🎫 {t} टिकट · {s} सिट · {v}\n🟢 कमिसन बाँकी: {c}\n🔸 तपाईंसँग नगद: {h}", 'hi' => "📊 आपका दिन — {d}\n🎫 {t} टिकट · {s} सीट · {v}\n🟢 कमीशन बाकी: {c}\n🔸 आपके पास नकद: {h}", 'en' => "📊 Your day — {d}\n🎫 {t} tickets · {s} seats · {v}\n🟢 Commission due: {c}\n🔸 Cash you hold: {h}"],
            'pax_head'    => ['ne' => "👥 यात्रु — {d} ({n})", 'hi' => "👥 यात्री — {d} ({n})", 'en' => "👥 Passengers — {d} ({n})"],
            'cancel_quote'=> ['ne' => "❌ {pnr} रद्द गर्दा फिर्ता: *{v}*\n{note}\n\nरद्द गर्ने भए *ho* लेख्नुहोस्।", 'hi' => "❌ {pnr} रद्द करने पर वापसी: *{v}*\n{note}\n\nरद्द करना है तो *ha* लिखें।", 'en' => "❌ Cancelling {pnr} refunds *{v}*\n{note}\n\nReply *yes* to cancel."],
            'cancel_done' => ['ne' => "✅ {pnr} रद्द भयो। फिर्ता: {v}", 'hi' => "✅ {pnr} रद्द हो गया। वापसी: {v}", 'en' => "✅ {pnr} is cancelled. Refund: {v}"],
            'resent'      => ['ne' => "{pnr} को टिकट फेरि पठाइयो।", 'hi' => "{pnr} का टिकट फिर भेजा गया।", 'en' => "The ticket for {pnr} was sent again."],
            'f_name'      => ['ne' => 'यात्रुको नाम', 'hi' => 'यात्री का नाम', 'en' => 'passenger name'],
            'f_phone'     => ['ne' => 'मोबाइल नम्बर', 'hi' => 'मोबाइल नंबर', 'en' => 'mobile number'],
            'f_pickup'    => ['ne' => 'चढ्ने ठाउँ', 'hi' => 'चढ़ने की जगह', 'en' => 'pickup'],
            'f_seat'      => ['ne' => 'सिट', 'hi' => 'सीट', 'en' => 'seat'],
            'f_date'      => ['ne' => 'मिति', 'hi' => 'तारीख', 'en' => 'date'],
            'f_gender'    => ['ne' => 'लिङ्ग', 'hi' => 'लिंग', 'en' => 'gender'],
            'f_age'       => ['ne' => 'उमेर', 'hi' => 'उम्र', 'en' => 'age'],
            'edit_confirm'=> ['ne' => "✏️ {pnr} — {f}\n{old} → *{new}*\n\nठिक छ भने *ho* लेख्नुहोस्।", 'hi' => "✏️ {pnr} — {f}\n{old} → *{new}*\n\nसही है तो *ha* लिखें।", 'en' => "✏️ {pnr} — {f}\n{old} → *{new}*\n\nReply *yes* to apply."],
            'edit_done'   => ['ne' => "✅ {pnr} सच्चियो। नयाँ टिकट यात्रुलाई पनि गयो।", 'hi' => "✅ {pnr} बदल गया। नया टिकट यात्री को भी गया।", 'en' => "✅ {pnr} is corrected. The new ticket also went to the passenger."],
            'edit_office' => ['ne' => "📨 {pnr} को यो परिवर्तन अफिसले गर्छ — अनुरोध पठाइयो, छिट्टै फोन आउँछ।", 'hi' => "📨 {pnr} का यह बदलाव ऑफिस करता है — अनुरोध भेजा गया, जल्द फ़ोन आएगा।", 'en' => "📨 The office makes this change on {pnr} — your request is sent, they will call you shortly."],
            'e_notfound'  => ['ne' => "⚠️ त्यो PNR भेटिएन, वा तपाईंले बेचेको होइन।", 'hi' => "⚠️ यह PNR नहीं मिला, या आपने नहीं बेचा।", 'en' => "⚠️ That PNR was not found, or it is not one of yours."],
            'e_notlive'   => ['ne' => "⚠️ यो बुकिङ {s} छ — परिवर्तन मिल्दैन।", 'hi' => "⚠️ यह बुकिंग {s} है — बदलाव नहीं हो सकता।", 'en' => "⚠️ This booking is {s} — it cannot be changed."],
            'e_departed'  => ['ne' => "⚠️ बस छुटिसक्यो — अब टिकट बदल्न मिल्दैन।", 'hi' => "⚠️ बस निकल चुकी — अब टिकट नहीं बदल सकते।", 'en' => "⚠️ The bus has left — the ticket can no longer be changed."],
            'e_whichpax'  => ['ne' => "👥 कुन यात्रु? ({names}) — जस्तै: *SHG-… Ram ko naam Sita*", 'hi' => "👥 कौन सा यात्री? ({names}) — जैसे: *SHG-… Ram ka naam Sita*", 'en' => "👥 Which passenger? ({names}) — e.g. *SHG-… Ram name Sita*"],
            'e_badstop'   => ['ne' => "⚠️ त्यो ठाउँ यो बसको रुटमा छैन। यीमध्ये: {stops}", 'hi' => "⚠️ यह जगह इस बस के रूट में नहीं है। इनमें से: {stops}", 'en' => "⚠️ That stop is not on this bus's route. One of: {stops}"],
            'e_badseat'   => ['ne' => "⚠️ सिट {seat} यो बसमा छैन — टिकटमा छापिएजस्तै सिट लेख्नुहोस् (जस्तै F5)। केही बदलिएन।", 'hi' => "⚠️ सीट {seat} इस बस में नहीं — टिकट पर छपी सीट लिखें (जैसे F5)। कुछ नहीं बदला।", 'en' => "⚠️ Seat {seat} is not on this bus — type the seat as printed on the ticket (e.g. F5). Nothing was changed."],
            'e_seattaken' => ['ne' => "⚠️ सिट {seat} मा अरू यात्रु हुनुहुन्छ — अर्को खाली सिट छान्नुहोस्। केही बदलिएन।", 'hi' => "⚠️ सीट {seat} पर दूसरा यात्री है — दूसरी खाली सीट चुनें। कुछ नहीं बदला।", 'en' => "⚠️ Seat {seat} is taken — choose another free seat. Nothing was changed."],
            'e_same'      => ['ne' => "ℹ️ टिकटमा पहिल्यै त्यही छ।", 'hi' => "ℹ️ टिकट पर पहले से वही है।", 'en' => "ℹ️ The ticket already says that."],
            'e_value'     => ['ne' => "⚠️ नयाँ मान बुझिएन। जस्तै: *SHG-… seat L5* / *phone 98XXXXXXXX* / *date 2026-11-02*", 'hi' => "⚠️ नया मान समझ नहीं आया। जैसे: *SHG-… seat L5* / *phone 98XXXXXXXX* / *date 2026-11-02*", 'en' => "⚠️ I did not understand the new value. E.g. *SHG-… seat L5* / *phone 98XXXXXXXX* / *date 2026-11-02*"],
            'card_tail'   => ['ne' => "✏️ बदल्न: *PNR नाम / pickup / seat / phone / date …* · ❌ *PNR cancel*", 'hi' => "✏️ बदलने के लिए: *PNR नाम / pickup / seat / phone / date …* · ❌ *PNR cancel*", 'en' => "✏️ To change: *PNR name / pickup / seat / phone / date …* · ❌ *PNR cancel*"],
            'no_permission' => ['ne' => "🔒 तपाईंको खाताले यो गर्न मिल्दैन — अफिसलाई सोध्नुहोस्।", 'hi' => "🔒 आपके खाते से यह नहीं हो सकता — ऑफिस से पूछें।", 'en' => "🔒 Your account cannot do this — please ask the office."],
            'sale_quote'  => ['ne' => "🎫 कोटेसन (अझै बुक भएको छैन)\n📅 {date} · {dep} · {route}\n📍 {pickup} {ptime}\n💺 {n} सिट: {seats} (बाँकी {left})\n👤 {who}\n💰 जम्मा *{total}*", 'hi' => "🎫 कोटेशन (अभी बुक नहीं हुआ)\n📅 {date} · {dep} · {route}\n📍 {pickup} {ptime}\n💺 {n} सीट: {seats} (बाकी {left})\n👤 {who}\n💰 कुल *{total}*", 'en' => "🎫 Quote (nothing booked yet)\n📅 {date} · {dep} · {route}\n📍 {pickup} {ptime}\n💺 {n} seat(s): {seats} ({left} left)\n👤 {who}\n💰 Total *{total}*"],
            // 31 Oct 2026 (usability leftovers a, f, g)
            'sale_pref_noted' => ['ne' => '👍 ठिक छ, नोट गरें — कोटेसन जस्ताको तस्तै छ (टिकट काटिएको छैन):', 'hi' => '👍 ठीक है, नोट किया — कोटेशन वैसा ही है (टिकट नहीं कटा):', 'en' => '👍 Noted — the quote is unchanged (no ticket issued):'],
            'sale_fix_ask' => ['ne' => "👍 ठिक छ — कोटेसन राखेको छु, टिकट काटिएको छैन।\n{q}", 'hi' => "👍 ठीक है — कोटेशन रखा है, टिकट नहीं कटा।\n{q}", 'en' => "👍 OK — the quote is kept, no ticket issued.\n{q}"],
            'sale_ask_first' => ['ne' => "✋ टिकट काटिएको छैन — पहिले यो भन्नुहोस्:\n{q}\n(माथिकै कोटेसन ठिक भए फेरि *ho* लेख्नुहोस्।)", 'hi' => "✋ टिकट नहीं कटा — पहले यह बताइए:\n{q}\n(ऊपर वाला कोटेशन ही ठीक हो तो फिर से *ha* लिखिए।)", 'en' => "✋ No ticket issued — first tell me:\n{q}\n(If the quote above is right after all, send *yes* again.)"],
            'sale_names_first' => ['ne' => "✋ टिकट काटिएको छैन — थपिएको यात्रुको नाम चाहियो:\n{q}", 'hi' => "✋ टिकट नहीं कटा — जोड़े गए यात्री का नाम चाहिए:\n{q}", 'en' => "✋ No ticket issued — the added traveller's name is needed first:\n{q}"],
            'sale_avail'  => ['ne' => "✅ हो, सिट पाइन्छ — अझै बुक गरिएको छैन\n📅 {date} · {dep} · {route}\n📍 {pickup} {ptime}\n💺 {n} सिट: {seats} (बाँकी {left})\n👤 {who}\n💰 जम्मा *{total}*", 'hi' => "✅ हाँ, सीट मिल जाएगी — अभी बुक नहीं किया\n📅 {date} · {dep} · {route}\n📍 {pickup} {ptime}\n💺 {n} सीट: {seats} (बाकी {left})\n👤 {who}\n💰 कुल *{total}*", 'en' => "✅ Yes, seats are available — nothing booked yet\n📅 {date} · {dep} · {route}\n📍 {pickup} {ptime}\n💺 {n} seat(s): {seats} ({left} left)\n👤 {who}\n💰 Total *{total}*"],
            'sale_avail_ask' => ['ne' => "काट्ने हो भने *ho* लेख्नुहोस् (नाम / नम्बर फरक भए पहिले पठाउनुहोस्)।", 'hi' => "काटना है तो *ha* लिखिए (नाम / नंबर अलग हो तो पहले भेजिए)।", 'en' => "Want it issued? Reply *yes* (send a different name / number first if needed)."],
            'sale_ask_yes'=> ['ne' => "टिकट काट्ने भए *ho* वा *CONFIRM* लेख्नुहोस्। (UPI भए *upi*, धेरै नाम: *names Ram, Sita*)", 'hi' => "टिकट काटना है तो *ha* या *CONFIRM* लिखें। (UPI हो तो *upi*, कई नाम: *names Ram, Sita*)", 'en' => "Reply *yes* or *CONFIRM* to issue. (UPI: *upi*; several travellers: *names Ram, Sita*)"],
            'sale_need'   => ['ne' => "✍️ अझै चाहियो: {what}। जस्तै: *Ram Thapa 98XXXXXXXX*", 'hi' => "✍️ अभी चाहिए: {what}। जैसे: *Ram Thapa 98XXXXXXXX*", 'en' => "✍️ Still needed: {what}. E.g. *Ram Thapa 98XXXXXXXX*"],
            'sale_done'   => ['ne' => "✅ टिकट काटियो! PNR *{pnr}*\n💺 {seats} · {total}\n{sent}{tail}", 'hi' => "✅ टिकट कट गया! PNR *{pnr}*\n💺 {seats} · {total}\n{sent}{tail}", 'en' => "✅ Ticket issued! PNR *{pnr}*\n💺 {seats} · {total}\n{sent}{tail}"],
            'sale_sent'   => ['ne' => "📲 टिकट {phone} को WhatsApp मा गयो।", 'hi' => "📲 टिकट {phone} के WhatsApp पर गया।", 'en' => "📲 The ticket went to {phone} on WhatsApp."],
            'sale_not_sent' => ['ne' => "📲 {phone} मा WhatsApp अहिले जान सकेन — यो टिकट फोटो / PDF लिंक यात्रुलाई फर्वार्ड गर्नुहोस् (उहाँले हाम्रो नम्बरमा \"hi\" लेखे पनि आउँछ)।", 'hi' => "📲 {phone} पर WhatsApp अभी नहीं जा सका — यह टिकट फोटो / PDF लिंक यात्री को फॉरवर्ड करें (वे हमारे नंबर पर \"hi\" लिखें तो भी आएगा)।", 'en' => "📲 WhatsApp to {phone} did not go through just now — forward this ticket picture / PDF link (it also arrives once they write \"hi\" to our number)."],
            'sale_sent_you' => ['ne' => "📲 टिकट यहीँ (तपाईंको WhatsApp मा) — यात्रुलाई फर्वार्ड गर्नुहोस्।", 'hi' => "📲 टिकट यहीं (आपके WhatsApp पर) — यात्री को फॉरवर्ड करें।", 'en' => "📲 The ticket is here (your WhatsApp) — forward it to the passenger."],
            'sale_tail_agent' => ['ne' => "💰 कमिसन तपाईंको wallet मा।", 'hi' => "💰 कमीशन आपके wallet में।", 'en' => "💰 The commission is on your wallet."],
            'sale_tail_counter' => ['ne' => "💵 नगद {total} तपाईंको हिसाबमा — अफिसलाई बुझाउनुहोस्।", 'hi' => "💵 नकद {total} आपके हिसाब में — ऑफिस को जमा करें।", 'en' => "💵 Cash {total} is on your account — hand it to the office."],
            'sale_tail_credit' => ['ne' => "🤝 बिक्री र कमिसन {code} {name} लाई।", 'hi' => "🤝 बिक्री और कमीशन {code} {name} को।", 'en' => "🤝 Sale and commission credited to {code} {name}."],
            'sale_company' => ['ne' => "🏢 कम्पनीको आफ्नै बिक्री ({code})", 'hi' => "🏢 कंपनी की अपनी बिक्री ({code})", 'en' => "🏢 The company's own sale ({code})"],
            'sale_agent'  => ['ne' => "🤝 एजेन्ट: {code} · {name} (बिक्री र कमिसन उहाँको)", 'hi' => "🤝 एजेंट: {code} · {name} (बिक्री और कमीशन उनका)", 'en' => "🤝 Agent: {code} · {name} (the sale and commission are theirs)"],
            'agent_code_unknown' => ['ne' => "⚠️ {code} कुनै सक्रिय एजेन्टको कोड होइन — कोड जाँचेर फेरि पठाउनुहोस्। केही बुक भएको छैन।", 'hi' => "⚠️ {code} किसी सक्रिय एजेंट का कोड नहीं — कोड जाँचकर फिर भेजें। कुछ बुक नहीं हुआ।", 'en' => "⚠️ {code} is not an active agent's code — check it and send again. Nothing was booked."],
            'no_private'  => ['ne' => "🔒 कसैको बैंक खाता, आधार / नागरिकता जस्ता नम्बर WhatsApp मा दिइँदैन। अफिसको Team पेजमा हेर्नुहोस्, वा अफिस: {phone}", 'hi' => "🔒 किसी का बैंक खाता, आधार / नागरिकता जैसे नंबर WhatsApp पर नहीं दिए जाते। ऑफिस के Team पेज पर देखें, या ऑफिस: {phone}", 'en' => "🔒 Bank account, Aadhaar / citizenship and similar numbers are never given on WhatsApp. See the office Team page, or call the office: {phone}"],
            'thanks'      => ['ne' => "🙏 धन्यवाद! अरू केही चाहिए भने यहीँ लेख्नुहोस्।", 'hi' => "🙏 धन्यवाद! और कुछ चाहिए तो यहीं लिखें।", 'en' => "🙏 Thank you! Write here if you need anything else."],
            'pending_dropped' => ['ne' => "ℹ️ (अघिल्लो पुष्टि गर्न बाँकी कुरा छोडियो — केही गरिएन।)", 'hi' => "ℹ️ (पिछली पुष्टि वाली बात छोड़ दी — कुछ नहीं किया गया।)", 'en' => "ℹ️ (The earlier request waiting for your yes was dropped — nothing was done.)"],
            // round 3 (owner's rules R1–R3)
            'held_sale'   => ['ne' => "👍 ठिक छ — कोटेसन छोडियो, टिकट काटिएको छैन। फेरि चाहिँदा पूरा लाइन पठाउनुहोस् (जस्तै *1 seat bholi Surat Ram Thapa 98XXXXXXXX*)।", 'hi' => "👍 ठीक है — कोटेशन छोड़ दिया, टिकट नहीं कटा। फिर चाहिए तो पूरी लाइन भेजें (जैसे *1 seat kal Surat Ram Thapa 98XXXXXXXX*)।", 'en' => "👍 OK — the quote is dropped, no ticket was issued. Send the whole line again when you want it (e.g. *1 seat tomorrow Surat Ram Thapa 98XXXXXXXX*)."],
            'held'        => ['ne' => "👍 ठिक छ — रोकियो, केही रेकर्ड / पठाइएको छैन। फेरि चाहिँदा पूरा कुरा पठाउनुहोस्।", 'hi' => "👍 ठीक है — रोक दिया, कुछ दर्ज / भेजा नहीं गया। फिर चाहिए तो पूरी बात भेजें।", 'en' => "👍 OK — stopped; nothing was recorded or sent. Send it again in full when you want it."],
            // round 4 (R1, review A4): a command line that also says no / not now / wait starts nothing
            'held_cmd'    => ['ne' => "✋ केही गरिएन{what} — सन्देशमा \"नगर्नु / होइन / पछि / पर्ख\" जस्तो कुरा छ। गर्ने भए त्यो शब्द बिना फेरि पठाउनुहोस्।", 'hi' => "✋ कुछ नहीं किया{what} — संदेश में \"मत / नहीं / बाद में / रुको\" जैसी बात है। करना हो तो वह शब्द हटाकर फिर भेजिए।", 'en' => "✋ Nothing done{what} — the message says no / not now / wait. To go ahead, send it again without that."],
            'sale_waiting'=> ['ne' => "⏳ कोटेसन अझै *ho* पर्खिरहेको छ (टिकट काटिएको छैन):\n👤 {who} · 📅 {date} · 💺 {n}{seats} · {total}\n\n*ho* = टिकट काट्ने · *no* = छोड्ने\nबदल्न: *naam Sita Thapa* · मोबाइल नम्बर · *bholi* / *parsi* · *upi*", 'hi' => "⏳ कोटेशन अभी *ha* का इंतज़ार कर रहा है (टिकट नहीं कटा):\n👤 {who} · 📅 {date} · 💺 {n}{seats} · {total}\n\n*ha* = टिकट काटें · *no* = छोड़ें\nबदलने के लिए: *naam Sita Thapa* · मोबाइल नंबर · *kal* / *parso* · *upi*", 'en' => "⏳ The quote still waits for your *yes* (no ticket issued yet):\n👤 {who} · 📅 {date} · 💺 {n}{seats} · {total}\n\n*yes* = issue · *no* = drop\nTo change: *naam Sita Thapa* · a mobile number · *tomorrow* · *upi*"],
            'pending_waiting' => ['ne' => "⏳ अघिल्लो कुरा अझै तपाईंको *ho* पर्खिरहेको छ — केही रेकर्ड भएको छैन। *ho* = पठाउने, *no* = छोड्ने।", 'hi' => "⏳ पिछली बात अभी आपके *ha* का इंतज़ार कर रही है — कुछ दर्ज नहीं हुआ। *ha* = भेजें, *no* = छोड़ें।", 'en' => "⏳ The earlier request still waits for your *yes* — nothing is recorded yet. *yes* = go ahead, *no* = drop it."],
            'sale_name_how' => ['ne' => "✍️ यात्रुको नाम बदल्ने भए *naam Sita Thapa* लेख्नुहोस् (वा नाम + मोबाइल: *Sita Thapa 98XXXXXXXX*)। कोटेसन जस्ताको तस्तै छ — काट्न *ho*, छोड्न *no*।", 'hi' => "✍️ यात्री का नाम बदलना हो तो *naam Sita Thapa* लिखें (या नाम + मोबाइल: *Sita Thapa 98XXXXXXXX*)। कोटेशन वैसा ही है — काटने के लिए *ha*, छोड़ने के लिए *no*।", 'en' => "✍️ To change the passenger, write *naam Sita Thapa* (or the name + mobile: *Sita Thapa 98XXXXXXXX*). The quote is unchanged — *yes* to issue, *no* to drop."],
            'money_via_office' => ['ne' => "🔒 सदस्यहरूबीचको पैसा (भुक्तानी, हस्तान्तरण) अफिसले मात्र रेकर्ड गर्छ — यो तपाईंको कम्पनीलाई तिरेको रकममा राखिँदैन। अफिस: {phone}\nआफ्नै भुक्तानी भए: *jama 1500 UTR 430987651234*", 'hi' => "🔒 सदस्यों के बीच का पैसा (भुगतान, हस्तांतरण) सिर्फ़ ऑफिस दर्ज करता है — यह आपकी कंपनी को भरी रकम में नहीं जाता। ऑफिस: {phone}\nअपना भुगतान हो तो: *jama 1500 UTR 430987651234*", 'en' => "🔒 Money between members (payouts, hand-overs) is recorded by the office only — it is not filed as your payment to the company. Office: {phone}\nFor your own payment: *pay 1500 UTR 430987651234*"],
            'office_money_whose' => ['ne' => "❓ यो कसको भुक्तानी हो? सदस्यको नाम वा कोड लेख्नुहोस्, जस्तै *Ramesh lai 400 pathaidiye UTR 430987651234* वा *Ramesh le 600 jama gareko aayo*। केही रेकर्ड भएको छैन।\n(अफिसको आफ्नै: *jama 5000 UTR …*)", 'hi' => "❓ यह किसका भुगतान है? सदस्य का नाम या कोड लिखें, जैसे *Ramesh lai 400 pathaidiye UTR 430987651234* या *Ramesh le 600 jama gareko aayo*। कुछ दर्ज नहीं हुआ।\n(ऑफिस का अपना: *jama 5000 UTR …*)", 'en' => "❓ Whose payment is this? Name the member or their code, e.g. *Ramesh lai 400 pathaidiye UTR 430987651234* or *Ramesh le 600 jama gareko aayo*. Nothing was recorded.\n(The office's own: *jama 5000 UTR …*)"],
            'pay_pax_note' => ['ne' => "🧾 यात्रुको भुक्तानी अफिसले बैंकसँग मिलाएर Verify Payments मा पक्का गर्छ — यहाँबाट कसैको हिसाबमा रेकर्ड हुँदैन।\nयात्रुले आफ्नै WhatsApp बाट PNR सहित UTR वा screenshot पठाओस्, वा अफिसमा भन्नुहोस्: {phone}", 'hi' => "🧾 यात्री का भुगतान ऑफिस बैंक से मिलाकर Verify Payments में पक्का करता है — यहाँ से किसी के हिसाब में दर्ज नहीं होता।\nयात्री अपने WhatsApp से PNR के साथ UTR या screenshot भेजे, या ऑफिस को बताएँ: {phone}", 'en' => "🧾 A passenger's payment is matched with the bank and confirmed by the office in Verify Payments — nothing is filed on anyone's account from here.\nAsk the passenger to send the UTR or screenshot with the PNR from their own WhatsApp, or tell the office: {phone}"],
            'pay_split'   => ['ne' => "✂️ टिकट र भुक्तानी छुट्टाछुट्टै पठाउनुहोस्: पहिले टिकटको लाइन (*1 seat bholi Surat Ram Thapa 98XXXXXXXX*), अनि आफ्नो भुक्तानी *jama 1500 UTR 430987651234*। केही रेकर्ड भएको छैन।", 'hi' => "✂️ टिकट और भुगतान अलग-अलग भेजें: पहले टिकट की लाइन (*1 seat kal Surat Ram Thapa 98XXXXXXXX*), फिर अपना भुगतान *jama 1500 UTR 430987651234*। कुछ दर्ज नहीं हुआ।", 'en' => "✂️ Send the ticket and the payment separately: first the ticket line (*1 seat tomorrow Surat Ram Thapa 98XXXXXXXX*), then your payment *pay 1500 UTR 430987651234*. Nothing was recorded."],
            'ref_only'    => ['ne' => "🧾 UTR …{tail} — कसको, कति रकमको भुक्तानी?\n• आफ्नो (कम्पनीलाई): *jama 1500 UTR {ref}*\n• यात्रुको: PNR सहित — अफिसले Verify Payments मा मिलाउँछ।\nअहिले केही रेकर्ड भएको छैन।", 'hi' => "🧾 UTR …{tail} — किसका, कितनी रकम का भुगतान?\n• अपना (कंपनी को): *jama 1500 UTR {ref}*\n• यात्री का: PNR के साथ — ऑफिस Verify Payments में मिलाता है।\nअभी कुछ दर्ज नहीं हुआ।", 'en' => "🧾 UTR …{tail} — whose payment, and how much?\n• Your own (to the company): *pay 1500 UTR {ref}*\n• A passenger's: with the PNR — the office matches it in Verify Payments.\nNothing is recorded yet."],
            'pay_later'   => ['ne' => "🕐 {v} तिरेपछि मात्र पठाउनुहोस्: *jama {n} UTR 430987651234* — त्यसपछि *ho* भनेपछि अफिसमा जान्छ। अहिले केही रेकर्ड भएको छैन।", 'hi' => "🕐 {v} भरने के बाद ही भेजें: *jama {n} UTR 430987651234* — फिर *ha* कहने पर ऑफिस जाएगा। अभी कुछ दर्ज नहीं हुआ।", 'en' => "🕐 Send it after paying {v}: *pay {n} UTR 430987651234* — it goes to the office after your *yes*. Nothing is recorded yet."],
            'adm_member_nothing' => ['ne' => "कुनै खुला भुक्तानी / निकासी अनुरोध छैन", 'hi' => "कोई खुला भुगतान / निकासी अनुरोध नहीं", 'en' => "no open payment / withdrawal request"],
            'adm_member_money_tail' => ['ne' => "पैसा आएको: *jama ok 12* · निकासी तिरेपछि: *approve 12 UTR430987651234* · सूची: *deposits* / *withdrawals*", 'hi' => "पैसा आया: *jama ok 12* · निकासी भरने के बाद: *approve 12 UTR430987651234* · सूची: *deposits* / *withdrawals*", 'en' => "Money in: *jama ok 12* · payout sent: *approve 12 UTR430987651234* · lists: *deposits* / *withdrawals*"],
            'card_own_only' => ['ne' => "🔒 अरूको ID / visiting कार्ड यहाँबाट दिइँदैन — आफ्नो: *id card* / *visiting card*। अरूको अफिसले पठाउँछ।", 'hi' => "🔒 दूसरों का ID / visiting कार्ड यहाँ से नहीं दिया जाता — अपना: *id card* / *visiting card*। दूसरों का ऑफिस भेजता है।", 'en' => "🔒 Someone else's ID / visiting card is not given here — yours: *id card* / *visiting card*. The office sends others'."],
            'sale_tail_counter_upi' => ['ne' => "💳 यात्रुले {total} UPI / अनलाइन तिर्ने — नगद तपाईंसँग छैन; अफिसले UTR / screenshot जाँचेर मिलाउँछ।", 'hi' => "💳 यात्री {total} UPI / ऑनलाइन भरेगा — नकद आपके पास नहीं; ऑफिस UTR / screenshot जाँचकर मिलाएगा।", 'en' => "💳 The passenger pays {total} by UPI / online — no cash is with you; the office checks the UTR / screenshot."],
            'own_only'    => ['ne' => "🔒 अरूको wallet / हिसाब यहाँबाट हेर्न मिल्दैन — आफ्नो मात्र: *balance*। अरूको अफिसले हेर्छ।", 'hi' => "🔒 दूसरों का wallet / हिसाब यहाँ से नहीं दिखता — सिर्फ अपना: *balance*। दूसरों का ऑफिस देखता है।", 'en' => "🔒 Only your own wallet can be seen here: *balance*. The office sees everyone's."],
            'pick_code'   => ['ne' => "कुन? कोडसहित फेरि पठाउनुहोस् — जस्तै *balance SHG-0024*", 'hi' => "कौन? कोड के साथ फिर भेजें — जैसे *balance SHG-0024*", 'en' => "Which one? Send it again with the code — e.g. *balance SHG-0024*"],
            'adm_wd_none_for' => ['ne' => "ℹ️ {name} ({code}) को कुनै खुला निकासी अनुरोध छैन — केही रेकर्ड गरिएन।", 'hi' => "ℹ️ {name} ({code}) का कोई खुला निकासी अनुरोध नहीं — कुछ दर्ज नहीं किया।", 'en' => "ℹ️ {name} ({code}) has no open withdrawal request — nothing was recorded."],
            'adm_dep_none_for' => ['ne' => "ℹ️ {name} ({code}) को {v} को कुनै खुला भुक्तानी अनुरोध भेटिएन — सूची: *deposits*। केही रेकर्ड गरिएन।", 'hi' => "ℹ️ {name} ({code}) का {v} का कोई खुला भुगतान अनुरोध नहीं मिला — सूची: *deposits*। कुछ दर्ज नहीं किया।", 'en' => "ℹ️ No open payment of {v} from {name} ({code}) — list: *deposits*. Nothing was recorded."],
            'adm_wd_amount_differs' => ['ne' => "⚠️ अनुरोध #{id} को रकम {v} हो, {asked} होइन — जाँचेर *approve {id} UTR…* पठाउनुहोस्। केही रेकर्ड गरिएन।", 'hi' => "⚠️ अनुरोध #{id} की रकम {v} है, {asked} नहीं — जाँचकर *approve {id} UTR…* भेजें। कुछ दर्ज नहीं किया।", 'en' => "⚠️ Request #{id} is for {v}, not {asked} — check and send *approve {id} UTR…*. Nothing was recorded."],
            'told_yes'    => ['ne' => "सदस्यलाई WhatsApp गयो।", 'hi' => "सदस्य को WhatsApp गया।", 'en' => "The member was told on WhatsApp."],
            'told_no'     => ['ne' => "⚠️ सदस्यलाई WhatsApp जान सकेन — आफैं खबर गर्नुहोस्।", 'hi' => "⚠️ सदस्य को WhatsApp नहीं जा सका — खुद बता दें।", 'en' => "⚠️ WhatsApp to the member did not go through — please tell them yourself."],
            'edit_done_nosend' => ['ne' => "✅ {pnr} सच्चियो। नयाँ टिकट यहीँ छ — यात्रुलाई WhatsApp अहिले जान सकेन, फर्वार्ड गर्नुहोस्।", 'hi' => "✅ {pnr} बदल गया। नया टिकट यहीं है — यात्री को WhatsApp अभी नहीं जा सका, फॉरवर्ड करें।", 'en' => "✅ {pnr} is corrected. The new ticket is here — WhatsApp to the passenger did not go through, please forward it."],
            'thinking'    => ['ne' => "⏳ बुझ्दैछु… (करिब १ मिनेटमा जवाफ आउँछ)", 'hi' => "⏳ समझ रहा हूँ… (लगभग 1 मिनट में जवाब)", 'en' => "⏳ Working on it… (answer in about a minute)"],
            'ai_down'     => ['ne' => "🙏 अहिले यो बुझ्न सकिनँ। *help* लेख्नुहोस्, वा अफिस: {phone}", 'hi' => "🙏 अभी यह समझ नहीं पाया। *help* लिखें, या ऑफिस: {phone}", 'en' => "🙏 I could not understand that just now. Type *help*, or call the office: {phone}"],
            'ask_pnr'     => ['ne' => "🔎 कुन टिकट? PNR पठाउनुहोस् (जस्तै SHG-2026-00123)।", 'hi' => "🔎 कौन सा टिकट? PNR भेजें (जैसे SHG-2026-00123)।", 'en' => "🔎 Which ticket? Send the PNR (like SHG-2026-00123)."],
            'cust_book_hint' => ['ne' => "🎫 टिकटका लागि लेख्नुहोस्: मिति, कति सिट, कहाँबाट — जस्तै *2 सिट भोलि Surat*", 'hi' => "🎫 टिकट के लिए लिखें: तारीख, कितनी सीट, कहाँ से — जैसे *2 सीट कल Surat*", 'en' => "🎫 For a ticket, send: date, how many seats, from where — e.g. *2 seats tomorrow Surat*"],
            'eta_head'    => ['ne' => "📍 बस अहिले (लाइभ):", 'hi' => "📍 बस अभी (लाइव):", 'en' => "📍 The bus now (live):"],
            'eta_none'    => ['ne' => "📍 अहिले बसको लाइभ स्थान छैन। समयको लागि अफिस: {phone}", 'hi' => "📍 अभी बस की लाइव लोकेशन नहीं है। समय के लिए ऑफिस: {phone}", 'en' => "📍 The bus is not live right now. For timings call the office: {phone}"],
        ];
    }
}

/** Devanagari digits → ASCII, without loading the document reader for every message. */
function ApDocRead_digits(string $s): string
{
    return trim(strtr($s, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9', ' ' => '']));
}

/** "Mehsana — SHG Head Office @ 20:00 [23.6,72.4]" → "Mehsana · 20:00". */
function Boarding_display(string $label): string
{
    if ($label === '') {
        return '—';
    }
    require_once __DIR__ . '/boarding.php';
    $d = Boarding::stopDisplay($label);
    return $d['name'] . ($d['time'] !== null ? ' · ' . $d['time'] : '');
}

/** @return list<string> */
function ApEdit_fields(): array
{
    return ['name', 'phone', 'pickup', 'seat', 'date', 'gender', 'age'];
}
