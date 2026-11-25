<?php
/**
 * =====================================================================
 *  includes/walookup.php — WaLookup: a ticket found by its PNR or by a
 *  passenger's name, behind the same gates as the rest of the bot
 *  (25 Nov 2026, mp3-bot; owner: "tikcet pnr number le search garn milne,
 *  name le database check garn milne").
 *
 *  PNR ("SHG-2026-00963", "PNR 963", "pnr no 00963", "ticket no 963"):
 *    - a CUSTOMER sees the full card only for a booking whose contact
 *      number is the WhatsApp number writing (the rule wabot.php has kept
 *      since day one). Anyone else is asked once for the LAST 4 DIGITS of
 *      the booking's mobile (wa_pnr_last4_on, default 1): right = the card.
 *      Wrong guesses are counted per number + PNR AND per PNR across every
 *      number, for 24 h (kv_store wa_pnr_fail; sending the PNR again never
 *      resets them): 3 wrong from one number, or 5 wrong from all numbers
 *      together, = status only for that PNR (the office clears it by
 *      deleting the wa_pnr_fail rows). A walk-in's placeholder number is
 *      never proof and never challenged. The office's own Settings numbers
 *      (admin_whatsapp, company_whatsapp, company_phone, office_phone, the
 *      office phone) read any PNR, as wabot.php's old PNR path did.
 *    - staff / office (AiTools::whoIs role staff | admin): the same rule as
 *      find_ticket — the office any PNR, an agent / counter only their own
 *      sales (sold_by, or booked_by for a counter). AiVerify::gate applies
 *      when the office put pnr_lookup in wa_ops_stepup_actions.
 *  NAME ("Ram Bahadur Thapa ko ticket", "naam le khoja Ram Thapa"):
 *    - staff / office only; an agent / counter only their own sales; the
 *      last 30 days + upcoming; at most 10 rows; phones masked (agents see
 *      the last 3 digits, an unlinked office number 2 + 3, an OTP-linked
 *      office the whole number). A customer gets a polite refusal + how to
 *      find their own ticket.
 *  Every search is rate-limited and written to ai_agent_calls (who, role,
 *  tool pnr_lookup | name_search, the query, the result count) — the table
 *  the AI Center already reads.
 *
 *  Switch: wa_lookup_on (default 1) — off = null everywhere, the old paths answer.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

final class WaLookup
{
    private const CHAL_SCOPE = 'wa_pnr_chal';
    private const CHAL_TTL   = 600;
    private const CHAL_TRIES = 3;
    private const FAIL_SCOPE = 'wa_pnr_fail';
    private const FAIL_TTL   = 86400;
    private const PNR_FAIL_CAP = 5;

    public static function on(): bool
    {
        return Settings::getBool('wa_lookup_on', true);
    }

    /* =================================================================
     *  Reading the line
     * ================================================================= */

    /**
     * The PNR a line names — a full one, or a short "PNR 963" / "ticket no 00963" resolved against the register
     * (newest first). '' = none.
     */
    public static function pnrIn(string $text): string
    {
        $a = self::ascii($text);
        if (preg_match('/\bSHG-[A-Z0-9]+(?:-[A-Z0-9]+)+\b/i', $a, $m) === 1) {
            return strtoupper($m[0]);
        }
        if (preg_match('/(?<![\p{L}\p{M}])(?:pnr|p\.n\.r|पीएनआर|ticket\s*(?:no|number|nambar|नम्बर|नं)|tikat\s*no|टिकट\s*(?:नं|नम्बर|नंबर)|booking\s*(?:no|number|id))\.?\s*(?:no\.?|number|nambar|नं\.?|नम्बर)?\s*[:#-]?\s*(\d{3,6})(?!\d)/iu', $a, $m) === 1) {
            $n = str_pad(ltrim($m[1], '0') === '' ? '0' : ltrim($m[1], '0'), 5, '0', STR_PAD_LEFT);
            try {
                $hit = (string) Database::scalar('SELECT pnr FROM bookings WHERE pnr LIKE :p ORDER BY id DESC LIMIT 1', ['p' => 'SHG-%-' . $n], '');
                return $hit !== '' ? strtoupper($hit) : 'SHG-' . date('Y') . '-' . $n;
            } catch (Throwable $e) {
                return '';
            }
        }
        return '';
    }

    /**
     * Is the line ONLY a lookup of that PNR ("SHG-…", "Hi SHG-…", "SHG-… status?", "PNR 963 check gara")?
     * A PNR inside a request ("SHG-… ko naam galat cha", "SHG-… cancel") is left to the paths that change tickets.
     */
    public static function isLookupLine(string $text, string $pnr): bool
    {
        if ($pnr === '') {
            return false;
        }
        $l = mb_strtolower(self::ascii($text));
        $l = (string) preg_replace('/\bshg-[a-z0-9]+(?:-[a-z0-9]+)+\b/i', ' ', $l);
        $l = (string) preg_replace('/(?<![\p{L}\p{M}])(?:pnr|ticket\s*(?:no|number|nambar)|tikat\s*no|booking\s*(?:no|number|id)|पीएनआर|टिकट\s*(?:नं|नम्बर|नंबर))\.?\s*(?:no\.?|number|nambar)?\s*[:#-]?\s*\d{3,6}(?!\d)/iu', ' ', $l);
        $l = (string) preg_replace('/[^\p{L}\p{M}\s]+/u', ' ', $l);
        $rest = array_values(array_filter(preg_split('/\s+/u', trim($l)) ?: [], 'strlen'));
        $ok = ['hi', 'hii', 'hello', 'helo', 'hlo', 'hey', 'namaste', 'namaskar', 'ji', 'sir', 'dai', 'bhai', 'didi', 'hajur', 'pnr', 'ticket', 'tikat', 'tiket',
            'mero', 'my', 'mera', 'meri', 'ko', 'ka', 'ki', 'ke', 'k', 'kya', 'status', 'stithi', 'sthiti', 'check', 'chek', 'details', 'detail', 'info', 'bivaran',
            'cha', 'chha', 'xa', 'ho', 'hai', 'kaha', 'kata', 'kahan', 'kun', 'confirm', 'confirmed', 'bhayo', 'bhaye', 'hua', 'please', 'plz', 'pls', 'dekhau',
            'dekhaunu', 'dekhaidinu', 'batau', 'batao', 'batunu', 'gara', 'garnu', 'garnus', 'garidinu', 'garideu', 'herdinu', 'hernu', 'hera', 'of', 'the', 'is', 'what',
            'show', 'booking', 'no', 'number', 'yo', 'yeh', 'this', 'स्थिति', 'मेरो', 'को', 'टिकट', 'छ', 'हो', 'के', 'कहाँ', 'नमस्ते', 'जाँच', 'हेर्नु', 'हेरिदिनु', 'स्टाटस'];
        foreach ($rest as $w) {
            if (!in_array($w, $ok, true)) {
                return false;
            }
        }
        return true;
    }

    /** "Ram Bahadur Thapa ko ticket kun bus ma cha?", "naam le khoja Ram Thapa", "name search Gita" → the name; '' = none. */
    public static function nameIn(string $text): string
    {
        $l = mb_strtolower(trim(self::ascii($text)));
        $l = trim((string) preg_replace('/[?？!.,;:।"\'()]+/u', ' ', $l));
        $l = (string) preg_replace('/\s+/u', ' ', $l);
        if ($l === '' || mb_strlen($l) > 120 || preg_match('/shg-?\s?\d{4}/u', $l) === 1 || preg_match('/\d{6,}/', $l) === 1) {
            return '';
        }
        $name = '';
        // explicit: "naam le khoja X", "name search X", "search X", "find X", "naam X ko ticket khoja"
        if (preg_match('/^(?:(?:naam|nam|name|नाम)\s*(?:le|bata|se|ले|बाट|से)?\s*(?:khoj\w*|search|find|check|dhund\w*|खोज\S*)|(?:search|find|khoj\w*|खोज\S*)\s*(?:by\s*)?(?:naam|name|नाम)?)\s*[:-]?\s+(\p{L}[\p{L}\p{M}.]*(?:\s+\p{L}[\p{L}\p{M}.]*){0,3})\s*(?:ko|ka|ki|को|का|की)?\s*(?:ticket|tikat|booking|टिकट)?\s*$/u', $l, $m) === 1) {
            $name = $m[1];
        } elseif (preg_match('/^(\p{L}[\p{L}\p{M}.]*(?:\s+\p{L}[\p{L}\p{M}.]*){0,3})\s+(?:naam|nam|name|नाम)\s*(?:le|ko|ले|को)?\s*(?:khoj\w*|search|find|check|खोज\S*)\s*$/u', $l, $m) === 1) {
            $name = $m[1];
        } elseif (preg_match('/^(\p{L}[\p{L}\p{M}.]*(?:\s+\p{L}[\p{L}\p{M}.]*){0,3})\s+(?:ko|ka|ki|को|का|की)\s+(?:ticket|tikat|tiket|booking|pnr|seat|sit|टिकट|बुकिङ|सिट)(?:\s+(.*))?$/u', $l, $m) === 1) {
            $tail = ' ' . (string) ($m[2] ?? '') . ' ';
            // an ORDER ("Ram Thapa ko ticket katdinus", "… book gar", "… chahiyo") is a sale, never a search
            if (preg_match('/(?<![\p{L}\p{M}])(?:kat\w*|kaat\w*|book\w*|chahiyo|chahiye|chaiyo|chaiye|chahincha|bana\w*|nikal\w*|gar(?:a|nu|idinu|ideu|dinus)?|dinus|dinu|deu|chaahiyo|चाहियो|चाहिए|काट\S*|बुक)(?![\p{L}\p{M}])/u', $tail) === 1) {
                return '';
            }
            // a HOW / PRICE question ("Online ko ticket kasari kinne?", "… kati parcha") asks about tickets, not a passenger
            if (preg_match('/(?<![\p{L}\p{M}])(?:kasari|kasri|kaise|kese|how|process|tarika|tariqa|kati|kitna|kitne|kitni|price|rate|bhada|bhaada|vada|bhara|kin\w*|kharid\w*|milcha|milchha|paincha|lagcha|lagchha|कसरी|कैसे|कति|कितना|भाडा|किन्ने)(?![\p{L}\p{M}])/u', $tail) === 1) {
                return '';
            }
            // a question / lookup word must be there ("kun", "kaha", "cha", "check", "khoja", "?")
            if (preg_match('/(?<![\p{L}\p{M}])(?:kun|kaha|kata|kahan|k|ke|kya|cha|chha|xa|hai|ho|kahile|kab|dekhau\w*|batau\w*|batao|check|khoj\w*|search|status|confirm|bhayo|hera|her\w*|कुन|कहाँ|छ|हो|के|खोज\S*)(?![\p{L}\p{M}])/u', $tail) !== 1
                && !str_contains($text, '?')) {
                return '';
            }
            $name = $m[1];
        }
        if ($name === '') {
            return '';
        }
        $stop = ['mero', 'meri', 'mera', 'my', 'hamro', 'tapai', 'tapain', 'aaja', 'aaj', 'aja', 'bholi', 'parsi', 'kal', 'today', 'tomorrow', 'yo', 'tyo', 'arko',
            'sabai', 'sab', 'all', 'company', 'office', 'agent', 'customer', 'passenger', 'yatri', 'yatru', 'nepal', 'india', 'gujarat', 'bus', 'gadi', 'naya', 'new',
            'mr', 'mrs', 'ms', 'shri', 'shree', 'ji', 'sir', 'dai', 'bhai', 'didi', 'sathi', 'friend', 'mummy', 'papa', 'buwa', 'aama', 'मेरो', 'आज', 'भोलि', 'यो', 'त्यो',
            // people by what they are, not by name ("bachha ko ticket lagcha?" asks the child fare)
            'bachha', 'bacha', 'baccha', 'bachcha', 'bachhako', 'bacchako', 'child', 'children', 'kid', 'kids', 'baby', 'infant', 'balak', 'balika', 'chhora',
            'chhori', 'chora', 'chori', 'budo', 'budi', 'buda', 'budhi', 'wife', 'husband', 'family', 'pariwar', 'parivar', 'group', 'student', 'students',
            'senior', 'old', 'lady', 'ladies', 'mahila', 'purush', 'gents', 'ek', 'dui', 'tin', 'char', 'one', 'two', 'three', 'kasko', 'kasai', 'kunai',
            'arulai', 'aru', 'other', 'others', 'sabaiko', 'hamro', 'hamra', 'unko', 'usko', 'tesko', 'bachhalai', 'बच्चा', 'बच्चाको', 'छोरा', 'छोरी', 'बुढा', 'परिवार',
            // a way of buying or a kind of ticket, not a passenger ("Online ko ticket kasari kinne?", "Tatkal ko ticket")
            'online', 'offline', 'tatkal', 'sleeper', 'seater', 'cabin', 'ac', 'nonac', 'non', 'upper', 'lower', 'return', 'single', 'double', 'website',
            'app', 'whatsapp', 'counter', 'agentko', 'cash', 'upi', 'esewa', 'khalti', 'advance', 'confirm', 'waiting', 'general', 'vip', 'ladies', 'women',
            'ऑनलाइन', 'अनलाइन', 'तत्काल', 'स्लीपर'];
        $parts = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [], static fn(string $w): bool => $w !== '' && !in_array($w, $stop, true)));
        if ($parts === [] || in_array(mb_strtolower(trim($name)), $stop, true)) {
            return '';
        }
        $name = implode(' ', $parts);
        try {
            require_once INCLUDE_PATH . '/personname.php';
            if (PersonName::looksLikePlace($name)) {
                return '';
            }
        } catch (Throwable $e) {
            // the name stands
        }
        require_once INCLUDE_PATH . '/wadirectory.php';
        if (WaDirectory::townIn($name) !== '' && count($parts) === 1) {
            return '';                                         // "Surat ko ticket" is a town, not a passenger
        }
        return mb_strlen((string) preg_replace('/\s+/u', '', $name)) >= 3 ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : '';
    }

    /* =================================================================
     *  PNR
     * ================================================================= */

    /**
     * The answer to a PNR lookup, behind the gates above.
     *
     * @param array<string,mixed> $who AiTools::whoIs() (+ 'office' => true for an OTP-linked office number)
     * @return array{text:string, media:?string, access:string}
     */
    public static function pnrAnswer(string $pnr, array $who, string $senderDigits, string $lang): array
    {
        $t0 = microtime(true);
        $role = (string) ($who['role'] ?? 'customer');
        $staff = in_array($role, ['staff', 'admin'], true);
        $ctx = self::ctxOf($who, $senderDigits);
        if (!Security::rateLimit($staff ? 'wa_lookup_staff' : 'wa_lookup', $senderDigits !== '' ? $senderDigits : 'unknown', $staff ? 240 : 30, 3600)) {
            self::audit('pnr_lookup', $ctx, $pnr, 0, false, 'rate limited', null, $t0);
            return ['text' => self::t($lang, '⏳ धेरै खोजी भयो — केही बेरपछि फेरि पठाउनुहोस्। अफिस: ', '⏳ Dherai khoji bhayo — kehi berpachhi feri pathaunus. Office: ',
                '⏳ बहुत खोज हो गई — थोड़ी देर बाद फिर भेजिए। ऑफिस: ', '⏳ Too many lookups — please try again later. Office: ') . Settings::officePhone(), 'media' => null, 'access' => 'limited'];
        }
        if ($staff) {
            require_once INCLUDE_PATH . '/aiverify.php';
            $gate = class_exists('AiVerify') ? AiVerify::gate('pnr_lookup', $ctx) : null;
            if ($gate !== null) {
                self::audit('pnr_lookup', $ctx, $pnr, 0, false, 'step-up verification required', null, $t0);
                return ['text' => '🔐 ' . (string) $gate['say'], 'media' => null, 'access' => 'stepup'];
            }
        }
        require_once INCLUDE_PATH . '/booking.php';
        $detail = Security::isValidPnr($pnr) ? BookingService::detail($pnr) : null;
        if ($detail === null) {
            self::audit('pnr_lookup', $ctx, $pnr, 0, true, 'not found', null, $t0);
            return ['text' => self::t($lang,
                '❌ PNR ' . $pnr . ' को कुनै बुकिङ भेटिएन। कोड जाँचेर फेरि पठाउनुहोस् (जस्तै SHG-2026-00963)। अफिस: ' . Settings::officePhone(),
                '❌ PNR ' . $pnr . ' ko kunai booking bhetiyena. Code jachera feri pathaunus (jastai SHG-2026-00963). Office: ' . Settings::officePhone(),
                '❌ PNR ' . $pnr . ' की कोई बुकिंग नहीं मिली। कोड जाँचकर फिर भेजिए (जैसे SHG-2026-00963)। ऑफिस: ' . Settings::officePhone(),
                '❌ No booking found for PNR ' . $pnr . '. Please check the code (like SHG-2026-00963). Office: ' . Settings::officePhone()), 'media' => null, 'access' => 'none'];
        }
        $bid = (int) $detail['id'];
        $contact = (string) ($detail['contact_phone'] ?? '');
        $owns = $senderDigits !== '' && !isPlaceholderPhone($contact) && normalisePhone($contact) === $senderDigits;
        if ($staff) {
            if (self::staffMayRead($detail, $who)) {
                self::audit('pnr_lookup', $ctx, $pnr, 1, true, 'full (' . $role . ')', $bid, $t0);
                return self::card($detail, $lang, true, $who) + ['access' => 'full'];
            }
            self::audit('pnr_lookup', $ctx, $pnr, 1, true, 'status only: not this seller\'s sale', $bid, $t0);
            return ['text' => self::statusLine($detail, $lang) . "\n" . self::t($lang,
                'यो टिकट तपाईंले बेचेको होइन — पूरा विवरण अफिसबाट।', 'Yo ticket tapaile becheko hoina — pura bibaran office bata.',
                'यह टिकट आपने नहीं बेचा — पूरी जानकारी ऑफिस से।', 'This is not one of your sales — the office has the full details.'), 'media' => null, 'access' => 'status'];
        }
        if (!$owns && self::isOfficeNumber($senderDigits)) {
            self::audit('pnr_lookup', $ctx, $pnr, 1, true, 'full (office number in Settings)', $bid, $t0);
            return self::card($detail, $lang, true, $who) + ['access' => 'full'];
        }
        if ($owns) {
            self::clearChallenge($senderDigits);
            self::audit('pnr_lookup', $ctx, $pnr, 1, true, 'full (own number)', $bid, $t0);
            return self::card($detail, $lang, true, $who) + ['access' => 'full'];
        }
        // someone else's number: the last 4 digits of the booking mobile, once (never for a walk-in's placeholder)
        if (Settings::getBool('wa_pnr_last4_on', true) && !isPlaceholderPhone($contact) && strlen(normalisePhone($contact)) >= 10
            && $senderDigits !== '' && !self::locked($senderDigits, $pnr)
            && Security::rateLimit('wa_pnr_chal', $senderDigits, 6, 3600)) {
            self::setChallenge($senderDigits, $pnr);         // the wrong-guess count lives in wa_pnr_fail: re-sending never resets it
            self::audit('pnr_lookup', $ctx, $pnr, 1, true, 'challenge: last 4 digits asked', $bid, $t0);
            return ['text' => self::statusLine($detail, $lang) . "\n\n" . self::t($lang,
                '🔒 पूरा विवरणका लागि: यो बुकिङमा राखेको मोबाइल नम्बरको अन्तिम ४ अंक पठाउनुहोस् (जस्तै 4321)।',
                '🔒 Pura bibaran ko lagi: yo booking ma rakheko mobile number ko antim 4 anka pathaunus (jastai 4321).',
                '🔒 पूरी जानकारी के लिए: इस बुकिंग वाले मोबाइल नंबर के आखिरी 4 अंक भेजिए (जैसे 4321)।',
                '🔒 For the full details: send the last 4 digits of the mobile number on this booking (like 4321).'), 'media' => null, 'access' => 'challenge'];
        }
        self::audit('pnr_lookup', $ctx, $pnr, 1, true, 'status only', $bid, $t0);
        return ['text' => self::statusLine($detail, $lang) . "\n" . self::t($lang,
            'पूरा विवरण र टिकटका लागि बुकिङमा राखेको मोबाइलबाट सन्देश पठाउनुहोस्।', 'Pura bibaran ra ticket ko lagi booking ma rakheko mobile bata message pathaunus.',
            'पूरी जानकारी और टिकट के लिए बुकिंग वाले मोबाइल से मैसेज भेजिए।', 'For the full details and the ticket, message us from the mobile number on the booking.'), 'media' => null, 'access' => 'status'];
    }

    /** A pending "last 4 digits" question answered. null = no challenge open, or the line is not 4 digits. */
    public static function challengeReply(string $senderDigits, string $text, array $who, string $lang): ?array
    {
        if ($senderDigits === '' || !self::on()) {
            return null;
        }
        $d = (string) preg_replace('/\D/', '', self::ascii($text));
        if (preg_match('/^\s*[\d०-९\s-]{4,6}\s*$/u', $text) !== 1 || strlen($d) !== 4) {
            return null;
        }
        $ch = self::challenge($senderDigits);
        if ($ch === null) {
            return null;
        }
        $t0 = microtime(true);
        $ctx = self::ctxOf($who, $senderDigits);
        $pnr = (string) $ch['pnr'];
        require_once INCLUDE_PATH . '/booking.php';
        $detail = BookingService::detail($pnr);
        if ($detail === null) {
            self::clearChallenge($senderDigits);
            return null;
        }
        $want = substr(normalisePhone((string) ($detail['contact_phone'] ?? '')), -4);
        if (self::locked($senderDigits, $pnr)) {
            // too many wrong guesses already (this number, or every number for this PNR): no more guessing, right or wrong
            self::clearChallenge($senderDigits);
            self::audit('pnr_lookup', $ctx, $pnr, 0, false, 'guess while locked', (int) $detail['id'], $t0);
            return ['text' => self::lockedText($lang), 'media' => null, 'access' => 'locked'];
        }
        if ($want !== '' && hash_equals($want, $d)) {
            self::clearChallenge($senderDigits);
            self::clearFails('s|' . $senderDigits . '|' . $pnr);
            self::audit('pnr_lookup', $ctx, $pnr, 1, true, 'full (last 4 digits matched)', (int) $detail['id'], $t0);
            $c = self::card($detail, $lang, false, $who);
            return ['text' => $c['text'], 'media' => null, 'access' => 'full'];
        }
        $tries = self::addFail('s|' . $senderDigits . '|' . $pnr);
        $all = self::addFail('p|' . $pnr);
        if ($tries >= self::CHAL_TRIES || $all >= self::PNR_FAIL_CAP) {
            self::clearChallenge($senderDigits);
            self::audit('pnr_lookup', $ctx, $pnr, 0, false, 'last 4 digits wrong ' . $tries . 'x (' . $all . 'x all numbers): locked 24 h', (int) $detail['id'], $t0);
            return ['text' => self::lockedText($lang), 'media' => null, 'access' => 'locked'];
        }
        self::setChallenge($senderDigits, $pnr, $tries);
        self::audit('pnr_lookup', $ctx, $pnr, 0, false, 'last 4 digits wrong (' . $tries . ')', (int) $detail['id'], $t0);
        return ['text' => self::t($lang, '❌ अंक मिलेन। फेरि प्रयास गर्नुहोस् (' . (self::CHAL_TRIES - $tries) . ' पटक बाँकी)।',
            '❌ Anka milena. Feri prayas garnus (' . (self::CHAL_TRIES - $tries) . ' patak baki).',
            '❌ अंक नहीं मिले। फिर कोशिश करें (' . (self::CHAL_TRIES - $tries) . ' बार बाकी)।', '❌ The digits did not match. Try again (' . (self::CHAL_TRIES - $tries) . ' left).'),
            'media' => null, 'access' => 'challenge'];
    }

    private static function lockedText(string $lang): string
    {
        return self::t($lang,
            '🔒 अंक मिलेन। यो PNR को विवरण अब बुकिङकै मोबाइलबाट मात्र — वा अफिस: ', '🔒 Anka milena. Yo PNR ko bibaran aba booking kai mobile bata matra — wa office: ',
            '🔒 अंक नहीं मिले। इस PNR की जानकारी अब बुकिंग वाले मोबाइल से ही — या ऑफिस: ', '🔒 The digits did not match. This PNR is now shown only to the booking\'s own number — or call the office: ')
            . Settings::officePhone();
    }

    public static function challengeOpen(string $senderDigits): bool
    {
        return $senderDigits !== '' && self::challenge($senderDigits) !== null;
    }

    /* =================================================================
     *  NAME
     * ================================================================= */

    /**
     * @param array<string,mixed> $who AiTools::whoIs() (+ 'office' => true for an OTP-linked office number)
     * @return array{text:string, count:int, access:string}
     */
    public static function byName(string $name, array $who, string $senderDigits, string $lang): array
    {
        $t0 = microtime(true);
        $role = (string) ($who['role'] ?? 'customer');
        $ctx = self::ctxOf($who, $senderDigits);
        $q = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(Security::clean($name, 60))));
        if (!in_array($role, ['staff', 'admin'], true)) {
            self::audit('name_search', $ctx, $q, 0, false, 'refused: customer', null, $t0);
            return ['text' => self::t($lang,
                "🙏 गोपनीयताका लागि अरूको नामबाट टिकट खोज्दैनौं।\nआफ्नो टिकट: PNR (जस्तै SHG-2026-00963) बुकिङमा राखेको मोबाइलबाट पठाउनुहोस्, वा *mero ticket* लेख्नुहोस्। अफिस: " . Settings::officePhone(),
                "🙏 Gopaniyata ko lagi aru ko naam bata ticket khojdainau.\nAafno ticket: PNR (jastai SHG-2026-00963) booking ma rakheko mobile bata pathaunus, wa *mero ticket* lekhnus. Office: " . Settings::officePhone(),
                "🙏 गोपनीयता के लिए हम किसी और के नाम से टिकट नहीं खोजते।\nअपना टिकट: PNR (जैसे SHG-2026-00963) बुकिंग वाले मोबाइल से भेजिए, या *mera ticket* लिखिए। ऑफिस: " . Settings::officePhone(),
                "🙏 For privacy we do not search tickets by someone's name.\nYour own ticket: send the PNR (like SHG-2026-00963) from the booking's mobile, or write *my ticket*. Office: " . Settings::officePhone()),
                'count' => 0, 'access' => 'refused'];
        }
        if (mb_strlen((string) preg_replace('/\s+/u', '', $q)) < 3) {
            return ['text' => self::t($lang, 'कम्तीमा ३ अक्षरको नाम लेख्नुहोस्।', 'Kamtima 3 akshar ko naam lekhnus.', 'कम से कम 3 अक्षर का नाम लिखिए।', 'Write at least 3 letters of the name.'), 'count' => 0, 'access' => 'none'];
        }
        if (!Security::rateLimit('wa_namesearch', $senderDigits !== '' ? $senderDigits : 'unknown', 60, 3600)) {
            self::audit('name_search', $ctx, $q, 0, false, 'rate limited', null, $t0);
            return ['text' => self::t($lang, '⏳ धेरै खोजी भयो — एक घण्टापछि फेरि।', '⏳ Dherai khoji bhayo — ek ghanta pachhi feri.', '⏳ बहुत खोज हो गई — एक घंटे बाद फिर।', '⏳ Too many searches — try again in an hour.'), 'count' => 0, 'access' => 'limited'];
        }
        require_once INCLUDE_PATH . '/aiverify.php';
        $gate = class_exists('AiVerify') ? AiVerify::gate('name_search', $ctx) : null;
        if ($gate !== null) {
            self::audit('name_search', $ctx, $q, 0, false, 'step-up verification required', null, $t0);
            return ['text' => '🔐 ' . (string) $gate['say'], 'count' => 0, 'access' => 'stepup'];
        }
        require_once INCLUDE_PATH . '/aitools.php';
        require_once INCLUDE_PATH . '/boarding.php';
        $like = '%' . implode('%', array_map(static fn(string $w): string => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $w), preg_split('/\s+/u', $q) ?: [$q])) . '%';
        $scope = $who['scopeAdminId'] ?? null;
        $params = ['n' => $like, 'from' => date('Y-m-d', strtotime('-30 days')), 'fromC' => date('Y-m-d 00:00:00', strtotime('-30 days'))];
        $sql = "SELECT b.id, b.pnr, b.status, b.contact_phone, b.total_amount, l.travel_date, l.boarding_stop, r.from_city, r.to_city,
                       (SELECT GROUP_CONCAT(p.full_name ORDER BY p.is_primary DESC, p.id SEPARATOR ', ') FROM booking_passengers p WHERE p.booking_id = b.id) AS pax,
                       (SELECT GROUP_CONCAT(p.seat_no ORDER BY p.seat_no SEPARATOR ', ') FROM booking_passengers p WHERE p.booking_id = b.id) AS seats
                  FROM bookings b
                  LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
                  LEFT JOIN schedules s ON s.id = l.schedule_id
                  LEFT JOIN routes r ON r.id = s.route_id
                 WHERE EXISTS (SELECT 1 FROM booking_passengers p2 WHERE p2.booking_id = b.id AND LOWER(p2.full_name) LIKE :n)
                   AND (l.travel_date >= :from OR (l.travel_date IS NULL AND b.created_at >= :fromC))";
        if ($scope !== null) {
            $sql .= AiTools::isCounterId((int) $scope) ? ' AND :a IN (b.sold_by_admin_id, b.booked_by_admin_id)' : ' AND b.sold_by_admin_id = :a';
            $params['a'] = (int) $scope;
        }
        $sql .= ' ORDER BY (l.travel_date >= CURDATE()) DESC, l.travel_date ASC, b.id DESC LIMIT 10';
        $rows = [];
        try {
            $rows = Database::fetchAll($sql, $params);
        } catch (Throwable $e) {
            Logger::warning('WaLookup name search failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        $Q = mb_convert_case($q, MB_CASE_TITLE, 'UTF-8');
        self::audit('name_search', $ctx, $q, count($rows), true, count($rows) . ' result(s)' . ($scope !== null ? ' (own sales)' : ''), null, $t0);
        if ($rows === []) {
            return ['text' => self::t($lang,
                '🔎 "' . $Q . '" नामको यात्रु ' . ($scope !== null ? 'तपाईंका टिकटमा ' : '') . 'भेटिएन (पछिल्ला ३० दिन + आउने यात्रा)। PNR भए पठाउनुहोस्।',
                '🔎 "' . $Q . '" naam ko yatri ' . ($scope !== null ? 'tapai ka ticket ma ' : '') . 'bhetiyena (pachhilla 30 din + aaune yatra). PNR bhaye pathaunus.',
                '🔎 "' . $Q . '" नाम का यात्री ' . ($scope !== null ? 'आपके टिकटों में ' : '') . 'नहीं मिला (पिछले 30 दिन + आने वाली यात्रा)। PNR हो तो भेजिए।',
                '🔎 No passenger called "' . $Q . '"' . ($scope !== null ? ' among your tickets' : '') . ' (last 30 days + upcoming). Send the PNR if you have it.'),
                'count' => 0, 'access' => 'none'];
        }
        $lines = ['🔎 ' . $Q . ' — ' . self::t($lang, count($rows) . ' टिकट', count($rows) . ' ticket', count($rows) . ' टिकट', count($rows) . ' ticket(s)')
            . ($scope !== null ? self::t($lang, ' (तपाईंका बिक्री)', ' (tapai ko bikri)', ' (आपकी बिक्री)', ' (your sales)') : '') . ':'];
        foreach ($rows as $r) {
            $route = trim((string) ($r['from_city'] ?? '') . ' → ' . (string) ($r['to_city'] ?? ''), ' →');
            $stop = trim((string) (Boarding::stopDisplay((string) ($r['boarding_stop'] ?? ''))['name'] ?? ''));
            $lines[] = '🎫 ' . $r['pnr'] . ' · ' . strtoupper((string) $r['status'])
                . "\n    👤 " . (string) ($r['pax'] ?? '') . ' · 📱 ' . self::maskPhone((string) $r['contact_phone'], $who)
                . "\n    📅 " . ((string) ($r['travel_date'] ?? '') !== '' ? formatDate((string) $r['travel_date'], 'D, j M') : '-')
                . ($route !== '' ? ' · ' . $route : '') . ($stop !== '' ? ' · ' . $stop : '')
                . ((string) ($r['seats'] ?? '') !== '' ? ' · 💺 ' . (string) $r['seats'] : '');
        }
        $lines[] = self::t($lang, 'पूरा विवरण: PNR पठाउनुहोस्।', 'Pura bibaran: PNR pathaunus.', 'पूरी जानकारी: PNR भेजिए।', 'Full details: send the PNR.');
        return ['text' => implode("\n", $lines), 'count' => count($rows), 'access' => $scope !== null ? 'own' : 'all'];
    }

    /* =================================================================
     *  Pieces
     * ================================================================= */

    /** "98•••••234" for an unlinked office number, "•••••••234" for an agent / counter, the number for an OTP-linked office. */
    public static function maskPhone(string $phone, array $who): string
    {
        $d = normalisePhone($phone);
        if ($d === '' || isPlaceholderPhone($phone)) {
            return '—';
        }
        if (($who['role'] ?? '') === 'admin' && !empty($who['office'])) {
            return $d;
        }
        if (($who['role'] ?? '') === 'admin' && ($who['scopeAdminId'] ?? null) === null) {
            return substr($d, 0, 2) . str_repeat('•', max(0, strlen($d) - 5)) . substr($d, -3);
        }
        return str_repeat('•', max(0, strlen($d) - 3)) . substr($d, -3);
    }

    /** Status, date, route, seats, boarding point + time, paid / due, the ticket link. */
    private static function card(array $detail, string $lang, bool $withMedia, array $who): array
    {
        require_once INCLUDE_PATH . '/boarding.php';
        require_once INCLUDE_PATH . '/seats.php';
        require_once INCLUDE_PATH . '/ticket.php';
        $pnr = (string) $detail['pnr'];
        $status = (string) $detail['status'];
        $leg = (array) ($detail['legs'][0] ?? []);
        $lines = ['🎫 ' . Settings::getString('company_name', APP_NAME), 'PNR: ' . $pnr];
        $lines[] = self::t($lang, 'स्थिति: ', 'Sthiti: ', 'स्थिति: ', 'Status: ') . self::statusWord($status, $lang);
        if ($leg !== []) {
            $route = trim((string) ($leg['from_city'] ?? '') . ' → ' . (string) ($leg['to_city'] ?? ''), ' →');
            if ($route !== '') {
                $lines[] = self::t($lang, 'बाटो: ', 'Bato: ', 'रूट: ', 'Route: ') . $route;
            }
            $date = (string) ($leg['travel_date'] ?? '');
            if ($date !== '') {
                $lines[] = self::t($lang, 'मिति: ', 'Miti: ', 'तारीख: ', 'Date: ') . formatDate($date, 'D, j M Y')
                    . ((string) ($leg['dep_time'] ?? '') !== '' ? ' · ' . self::t($lang, 'बस ', 'bus ', 'बस ', 'bus ') . substr((string) $leg['dep_time'], 0, 5) : '');
            }
            $stop = trim((string) ($leg['boarding_stop'] ?? ''));
            if ($stop !== '') {
                $disp = Boarding::stopDisplay($stop);
                $bt = substr((string) ($leg['boarding_time'] ?? ''), 0, 5);
                if ($bt === '' && preg_match('/@\s*([0-2]?\d:\d{2})/', $stop, $tm) === 1) {
                    $bt = $tm[1];
                }
                $lines[] = self::t($lang, 'चढ्ने ठाउँ: ', 'Chadne thau: ', 'चढ़ने की जगह: ', 'Boarding: ') . (string) ($disp['name'] ?? $stop) . ($bt !== '' ? ' · ' . $bt : '');
            }
        }
        $seats = array_map(static fn($s): string => Seats::displayLabel((string) $s, 'sleeper', (string) ($detail['booking_mode'] ?? 'sharing')), (array) ($detail['seats'] ?? []));
        if ($seats === []) {
            $seats = array_values(array_filter(array_map(static fn(array $p): string => (string) ($p['seat_no'] ?? ''), (array) ($detail['passengers'] ?? []))));
        }
        if ($seats !== []) {
            $lines[] = self::t($lang, 'सिट: ', 'Seat: ', 'सीट: ', 'Seats: ') . implode(', ', $seats) . ' · ' . count((array) ($detail['passengers'] ?? [])) . self::t($lang, ' जना', ' jana', ' लोग', ' pax');
        }
        if (in_array((string) ($who['role'] ?? ''), ['staff', 'admin'], true)) {
            $names = array_values(array_filter(array_map(static fn(array $p): string => trim((string) ($p['full_name'] ?? '')), (array) ($detail['passengers'] ?? []))));
            if ($names !== []) {
                $lines[] = '👤 ' . implode(', ', array_slice($names, 0, 6)) . ' · 📱 ' . self::maskPhone((string) ($detail['contact_phone'] ?? ''), $who);
            }
        }
        $total = (float) ($detail['total_amount'] ?? 0);
        $paid = (string) ($detail['payment']['status'] ?? '') === 'verified';
        $cod = (int) ($detail['is_cod'] ?? 0) === 1;
        $lines[] = self::t($lang, 'जम्मा: ', 'Jamma: ', 'कुल: ', 'Total: ') . inr($total) . ' · '
            . ($paid ? self::t($lang, '✅ भुक्तानी भयो', '✅ bhuktani bhayo', '✅ भुगतान हो गया', '✅ paid')
                : ($cod ? self::t($lang, '💵 चढ्दा तिर्ने', '💵 chadda tirne', '💵 चढ़ते समय भुगतान', '💵 pay at boarding')
                    : self::t($lang, '⏳ तिर्न बाँकी ', '⏳ tirna baki ', '⏳ बाकी ', '⏳ due ') . inr($total)));
        $media = null;
        if ($status === 'confirmed') {
            $lines[] = self::t($lang, '📄 टिकट: ', '📄 Ticket: ', '📄 टिकट: ', '📄 Ticket: ') . Ticket::downloadUrl($pnr);
            if ($withMedia && Settings::getBool('whatsapp_send_pdf', true)) {
                $media = Ticket::imageUrl($pnr);
            }
        } elseif ($status === 'pending') {
            $lines[] = self::t($lang, '⏳ भुक्तानी जाँच हुँदैछ — पक्का भएपछि टिकट यहीँ आउँछ।', '⏳ Bhuktani janch hudaichha — pakka bhayepachhi ticket yahi aauchha.',
                '⏳ भुगतान जाँच हो रहा है — पक्का होते ही टिकट यहीं आएगा।', '⏳ The payment is being checked — the ticket comes here once confirmed.');
            // not paid yet: the way to pay (the QR carries the exact amount) — as wabot.php's PNR path always sent
            if ($withMedia && !$paid && !$cod && Settings::getString('upi_id', '') !== '' && $total > 0) {
                $lines[] = self::t($lang, '💰 तिर्न बाँकी छ भने QR स्क्यान गर्नुहोस्, वा यहाँ थिच्नुहोस्: ', '💰 Tirna baki chha bhane QR scan garnus, wa yaha thichnus: ',
                    '💰 भुगतान बाकी हो तो QR स्कैन करें, या यहाँ दबाइए: ', '💰 To pay, scan the QR or tap: ') . appUrl('pay.php?pnr=' . urlencode($pnr));
                $media = appUrl('pay-image.php?pnr=' . urlencode($pnr));
            }
        }
        return ['text' => implode("\n", $lines), 'media' => $media];
    }

    private static function statusLine(array $detail, string $lang): string
    {
        return '🔒 ' . self::t($lang, 'बुकिङ ', 'Booking ', 'बुकिंग ', 'Booking ') . (string) $detail['pnr'] . ' — ' . self::t($lang, 'स्थिति: ', 'sthiti: ', 'स्थिति: ', 'status: ')
            . self::statusWord((string) $detail['status'], $lang);
    }

    private static function statusWord(string $status, string $lang): string
    {
        return match ($status) {
            'confirmed' => self::t($lang, '✅ पक्का (CONFIRMED)', '✅ pakka (CONFIRMED)', '✅ पक्का (CONFIRMED)', '✅ CONFIRMED'),
            'pending'   => self::t($lang, '⏳ भुक्तानी बाँकी (PENDING)', '⏳ bhuktani baki (PENDING)', '⏳ भुगतान बाकी (PENDING)', '⏳ PENDING'),
            'cancelled' => self::t($lang, '❌ रद्द (CANCELLED)', '❌ radda (CANCELLED)', '❌ रद्द (CANCELLED)', '❌ CANCELLED'),
            'completed' => self::t($lang, '🏁 यात्रा पूरा (COMPLETED)', '🏁 yatra pura (COMPLETED)', '🏁 यात्रा पूरी (COMPLETED)', '🏁 COMPLETED'),
            default     => strtoupper($status),
        };
    }

    /** find_ticket's own rule: the office any booking; an agent / counter only their own sales. */
    private static function staffMayRead(array $detail, array $who): bool
    {
        if (($who['role'] ?? '') === 'admin') {
            return true;
        }
        $scope = $who['scopeAdminId'] ?? null;
        if ($scope === null) {
            return true;
        }
        require_once INCLUDE_PATH . '/aitools.php';
        return (int) ($detail['sold_by_admin_id'] ?? 0) === (int) $scope
            || ((int) ($detail['booked_by_admin_id'] ?? 0) === (int) $scope && AiTools::isCounterId((int) $scope));
    }

    private static function ctxOf(array $who, string $senderDigits): array
    {
        return [
            'role' => in_array((string) ($who['role'] ?? ''), ['staff', 'admin'], true) ? (string) $who['role'] : 'customer',
            'adminId' => (int) ($who['adminId'] ?? 0), 'scopeAdminId' => $who['scopeAdminId'] ?? null,
            'phone' => (string) ($who['phone'] ?? $senderDigits) !== '' ? (string) ($who['phone'] ?? $senderDigits) : $senderDigits,
            'channel' => (string) ($who['channel'] ?? 'whatsapp'), 'admin' => $who['admin'] ?? null, 'name' => (string) ($who['name'] ?? ''),
        ];
    }

    /** One audit row per search (ai_agent_calls: who, role, the tool, the query, the result count). Never throws. */
    private static function audit(string $tool, array $ctx, string $q, int $n, bool $ok, string $detail, ?int $bookingId, float $t0): void
    {
        try {
            require_once INCLUDE_PATH . '/aitools.php';
            AiTools::logCall($tool, ['q' => mb_substr($q, 0, 60), 'results' => $n], $ctx, $ok, 'results ' . $n . ' · ' . $detail, $bookingId, $t0);
        } catch (Throwable $e) {
            Logger::warning('WaLookup audit failed: ' . $e->getMessage(), [], 'whatsapp');
        }
    }

    /* ---- the "last 4 digits" question (kv_store) ---- */

    private static function challenge(string $who): ?array
    {
        try {
            $raw = (string) Database::scalar('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::CHAL_SCOPE, 'k' => $who], '');
        } catch (Throwable $e) {
            return null;
        }
        $v = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($v) || (int) ($v['at'] ?? 0) < time() - self::CHAL_TTL || (string) ($v['pnr'] ?? '') === '') {
            return null;
        }
        return $v;
    }

    private static function setChallenge(string $who, string $pnr, int $tries = 0): void
    {
        $v = json_encode(['pnr' => $pnr, 'tries' => $tries, 'at' => time()]);
        try {
            Database::run('INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, :u) ON DUPLICATE KEY UPDATE kvalue = :v2',
                ['s' => self::CHAL_SCOPE, 'k' => $who, 'v' => $v, 'u' => 'walookup', 'v2' => $v]);
        } catch (Throwable $e) {
            Logger::warning('WaLookup challenge write failed: ' . $e->getMessage(), [], 'whatsapp');
        }
    }

    public static function clearChallenge(string $who): void
    {
        try {
            Database::run('DELETE FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::CHAL_SCOPE, 'k' => $who]);
        } catch (Throwable $e) {
        }
    }

    /* ---- wrong guesses: per number + PNR and per PNR, 24 h from the first one (kv_store wa_pnr_fail) ---- */

    private static function fails(string $key): int
    {
        try {
            $raw = (string) Database::scalar('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::FAIL_SCOPE, 'k' => $key], '');
        } catch (Throwable $e) {
            return 0;
        }
        $v = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($v) && (int) ($v['since'] ?? 0) >= time() - self::FAIL_TTL ? (int) ($v['n'] ?? 0) : 0;
    }

    private static function addFail(string $key): int
    {
        $n = self::fails($key);
        $since = time();
        if ($n > 0) {
            try {
                $old = json_decode((string) Database::scalar('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::FAIL_SCOPE, 'k' => $key], ''), true);
                $since = (int) ($old['since'] ?? time());
            } catch (Throwable $e) {
                $since = time();
            }
        }
        $v = json_encode(['n' => $n + 1, 'since' => $since]);
        try {
            Database::run('INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, :u) ON DUPLICATE KEY UPDATE kvalue = :v2',
                ['s' => self::FAIL_SCOPE, 'k' => $key, 'v' => $v, 'u' => 'walookup', 'v2' => $v]);
        } catch (Throwable $e) {
        }
        return $n + 1;
    }

    private static function clearFails(string $key): void
    {
        try {
            Database::run('DELETE FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::FAIL_SCOPE, 'k' => $key]);
        } catch (Throwable $e) {
        }
    }

    /** 3 wrong from this number for this PNR, or 5 wrong for this PNR from every number, in 24 h. */
    private static function locked(string $who, string $pnr): bool
    {
        return self::fails('s|' . $who . '|' . $pnr) >= self::CHAL_TRIES || self::fails('p|' . $pnr) >= self::PNR_FAIL_CAP;
    }

    /** The office's own numbers in Settings (wabot.php's PNR path read any PNR for these). */
    private static function isOfficeNumber(string $senderDigits): bool
    {
        if ($senderDigits === '') {
            return false;
        }
        foreach (['admin_whatsapp', 'company_whatsapp', 'company_phone', 'office_phone'] as $k) {
            $d = normalisePhone(Settings::getString($k, ''));
            if ($d !== '' && $d === $senderDigits) {
                return true;
            }
        }
        $d = normalisePhone(Settings::officePhone());
        return $d !== '' && $d === $senderDigits;
    }

    private static function ascii(string $s): string
    {
        return strtr($s, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']);
    }

    private static function t(string $lang, string $ne, string $rn, string $hi, string $en): string
    {
        return match ($lang) { 'rn' => $rn, 'hi' => $hi, 'en' => $en, default => $ne };
    }
}
