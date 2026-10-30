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
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/agentpro.php';

final class ApWa
{
    /** A pending "ho" expires after this long. */
    private const PENDING_TTL = 900;

    /** Roles that may sell / change tickets from WhatsApp (AiTools' own list). */
    private const SELLERS = ['agent', 'counter', 'manager', 'superadmin'];

    /** Roles that see the whole office: every booking, every member's number. */
    private const OFFICE = ['manager', 'superadmin'];

    private const YES = '/^(ho|hoo|ha+|haan|han|yes|y|yeah|ok|okay|okk|confirm|confirmed|done|thik\s*cha|thik\s*xa|theek\s*hai|hunchha|huncha|hunxa|हो|हुन्छ|हाँ|हां|ठीक\s*छ|ठिक\s*छ|ठीक\s*है|पक्का|कन्फर्म)[\s.!।]*$/iu';
    private const NO  = '/^(no|nah|n|cancel|stop|chhod|chhodnu|chodo|nai|hoina|hoena|hoeina|नाइ|होइन|रद्द|छोड|नहीं|नही|मत)[\s.!।]*$/iu';

    /** The numbered menu (help): a digit alone runs the same command as its word. */
    private const MENU = [1 => 'ticket', 2 => 'tickets', 3 => 'balance', 4 => 'statement', 5 => 'withdraw', 6 => 'portal',
                          7 => 'id card', 8 => 'aaja', 9 => 'office', 0 => 'admin'];

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
            if (preg_match('/^(?:link|register|regi|jod|jodnu|जोड|दर्ता|मेरो\s*कोड|my\s*code)\s*[:#-]?\s*(.+)$/iu', $text, $m) === 1) {
                return self::linkStart($phone, trim($m[1]), $lang);
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

            // 3. The one thing this number was asked to confirm.
            if (!empty($state['pending'])) {
                $p = $state['pending'];
                $yes = preg_match(self::YES, $text) === 1;
                if ($p['kind'] === 'done') {
                    // A CONFIRM again after the sale / payment went through: say what happened, do nothing.
                    if ($yes) {
                        return self::out(self::t('already_done', $lang) . "\n\n" . (string) ($p['text'] ?? ''), $p['media'] ?? null);
                    }
                    if (preg_match(self::NO, $text) === 1) {
                        self::clearPending($phone);
                        return self::out(self::t('done_no_undo', $lang));
                    }
                } elseif ($p['kind'] === 'book' && preg_match(self::NO, $text) !== 1) {
                    // Mid-questions an "ok" is no answer: the question is asked again (a command leaves the booking).
                    if (($r = self::bookStep($p, $text, $member, $ctx, $lang)) !== null) {
                        return $r;
                    }
                } elseif ($yes) {
                    if (!self::claimPending($phone, (string) $state['raw'])) {
                        return self::out(self::t('nothing_pending', $lang));     // the other CONFIRM took it
                    }
                    return self::confirm($p, $member, $ctx, $lang);
                } elseif (preg_match(self::NO, $text) === 1) {
                    self::clearPending($phone);
                    return self::out(self::t('cancelled', $lang));
                } elseif ($p['kind'] === 'sale' && ($r = self::saleAmend($p, $text, $member, $ctx, $lang)) !== null) {
                    return $r;
                }
            } elseif (preg_match(self::YES, $text) === 1 && preg_match('/^(confirm|confirmed|कन्फर्म)/iu', $text) === 1) {
                return self::out(self::t('nothing_pending', $lang));
            }

            // 4. Instant commands.
            $r = self::command($text, $member, $ctx, $lang);
            if ($r !== null) {
                return $r;
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
        $hits = [];
        foreach (Database::fetchAll("SELECT $cols FROM admins WHERE phone IS NOT NULL AND phone <> ''") as $a) {
            if (normalisePhone((string) $a['phone']) === $phone) {
                $hits[] = $a;
            }
        }
        // A shared number confers nothing: which of them would it be?
        if (count($hits) === 1 && self::usable($hits[0])) {
            return $hits[0] + ['via' => 'registered'];
        }
        return null;
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
        self::saveState($phone, ['pending' => json_encode(['kind' => $kind, 'at' => time()] + $payload, JSON_UNESCAPED_UNICODE)]);
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
        $detected = $explicit === null ? self::detect($text) : null;
        $lang = $explicit ?? $detected ?? ($state['lang'] ?? null) ?? 'ne';
        if ($lang !== ($state['lang'] ?? null)) {
            self::saveState($phone, ['lang' => $lang]);
        }
        return in_array($lang, ['ne', 'hi', 'en'], true) ? $lang : 'ne';
    }

    /** 'ne' | 'hi' | 'en' when the words say so, null when they do not. */
    public static function detect(string $text): ?string
    {
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
            'ne' => $count(['cha', 'chha', 'xa', 'chaina', 'mero', 'malai', 'hamilai', 'kati', 'chahiyo', 'chaiyo', 'garnu', 'garnus', 'garidinu', 'tapai', 'hunchha', 'huncha', 'bholi', 'parsi', 'bata', 'samma', 'hola', 'kaha', 'pathaunu', 'pathaidinu', 'bhayo', 'thiyo', 'jana', 'paisa', 'baki', 'dinus', 'paryo']),
            'hi' => $count(['hai', 'hain', 'mera', 'meri', 'mujhe', 'humein', 'kitna', 'kitne', 'chahiye', 'chaiye', 'karo', 'kijiye', 'aap', 'aapka', 'nahi', 'nahin', 'hoon', 'kal', 'parso', 'se', 'tak', 'kya', 'kahan', 'jaana', 'bhejo', 'batao', 'tha', 'log']),
            'en' => $count(['the', 'is', 'are', 'my', 'me', 'what', 'where', 'when', 'please', 'want', 'need', 'book', 'seats', 'tomorrow', 'today', 'send', 'how', 'much', 'can', 'you', 'ticket', 'from', 'to', 'for', 'change', 'cancel', 'thanks', 'thank', 'hello', 'i']),
        ];
        arsort($sc);
        $top = array_key_first($sc);
        $vals = array_values($sc);
        // English needs two English words: "to Surat" alone is not a language choice.
        if ($vals[0] === 0 || $vals[0] === $vals[1] || ($top === 'en' && $vals[0] < 2)) {
            return null;
        }
        return $top;
    }

    /* =================================================================
     *  Instant commands
     * ================================================================= */

    /** @return array{text: string, media?: ?string}|null */
    private static function command(string $text, array $member, array $ctx, string $lang): ?array
    {
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
            return self::help($member, $lang);
        }
        if (preg_match('/^unlink$/i', $l)) {
            Database::run('UPDATE ap_wa_links SET revoked_at = NOW() WHERE phone = :p', ['p' => $ctx['phone']]);
            return self::out(self::t('unlinked', $lang));
        }

        // The numbered menu: "3" = balance, … (the same handlers as the words).
        $digit = ApDocRead_digits($text);
        if (preg_match('/^[0-9]$/', $digit) === 1) {
            $word = self::MENU[(int) $digit] ?? 'help';
            if ($word === 'admin' && !self::isOffice($ctx)) {
                $word = 'help';
            }
            return self::command($word, $member, $ctx, $lang);
        }

        // The office's own desk (super-admin / manager only).
        if (preg_match('/^(?:admin|office\s*menu|अफिस\s*मेनु|एडमिन)$/u', $l)) {
            return self::isOffice($ctx) ? self::adminHelp($lang) : self::out(self::t('no_permission', $lang));
        }
        if (preg_match('/^(?:pending|baki\s*kam|बाँकी\s*काम|approvals?)$/u', $l)) {
            return self::isOffice($ctx) ? self::adminPending($lang) : self::out(self::t('no_permission', $lang));
        }
        if (preg_match('/^(?:joins?|applications?|apps|aavedan|abedan|आवेदन\w*|join\s*list)$/u', $l)) {
            return self::isOffice($ctx) ? self::adminJoins($lang) : null;
        }
        if (preg_match('/^(?:withdrawals|payouts|wd|wd\s*list|withdraw\s*list|pending\s*withdraw\w*)$/u', $l) && self::isOffice($ctx)) {
            return self::adminWithdrawals($lang);
        }
        // The reference is kept as typed ("UTR430987651234"), the way the Team page records it.
        if (preg_match('/^(?:approve|wd\s*pay|wd\s*approve)\s*#?(\d{1,9})\s*[:#-]?\s*(.*)$/iu', self::asciiDigits($text), $m)) {
            return self::isOffice($ctx) ? self::adminPayPreview((int) $m[1], trim($m[2]), $ctx, $lang) : self::out(self::t('no_permission', $lang));
        }
        if (preg_match('/^(?:reject|decline|wd\s*reject)\s*#?(\d{1,9})\s*[:#-]?\s*(.*)$/iu', self::asciiDigits($text), $m)) {
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
            return self::bookStart($member, $ctx, $lang);
        }
        // The seller's own tickets: "tickets", "tickets bholi", "mero ticket 2 Nov".
        if (preg_match('/^(?:my\s*tickets?|tickets|mero\s*ticket\w*|mera\s*ticket\w*|मेरो\s*टिकट\w*|मेरा\s*टिकट|sold\s*tickets?|bechya\w*|बेचेको\s*टिकट)(?:\s+(.+))?$/u', $l, $m)) {
            $d = isset($m[1]) ? self::dateWord((string) $m[1]) : '';
            return self::myTickets($member, $ctx, $lang, $d);
        }
        // The member portal: where and how to sign in.
        if (preg_match('/^(?:portal|login|log\s*in|sign\s*in|signin|me\s*link|my\s*portal|app|लगइन|लग\s*इन|पोर्टल)$/u', $l)) {
            return self::portal($member, $lang);
        }

        // A PNR: status, edit, cancel, resend.
        if ($pnr !== '') {
            return self::pnrCommand($pnr, $text, $member, $ctx, $lang);
        }

        // Money.
        if (preg_match('/^(?:balance|bal|wallet|baki|baaki|बाँकी|बाकी|ब्यालेन्स|बैलेंस|बैलेन्स|kati\s*paisa|mero\s*paisa|paisa\s*kati|commission|कमिसन|कमीशन)\b/u', $l)) {
            return self::balance($member, $lang);
        }
        if (preg_match('/^(?:statement|stmt|hisab|hisaab|हिसाब|स्टेटमेन्ट|स्टेटमेंट|passbook|ledger|khata|खाता)\b\s*(\d{1,3})?/u', $l, $m)) {
            return self::statement($member, $lang, isset($m[1]) ? (int) $m[1] : 0);
        }
        if (preg_match('/^(?:withdraw|withdrawal|payout|nikal|nikalnu|निकाल|निकाल्ने|पैसा\s*निकाल|paisa\s*nikal|पैसे\s*निकाल)\w*\s*(?:rs\.?|₹|inr)?\s*([\d,]+)?/u', $l, $m)) {
            return self::withdraw($member, $ctx, $lang, isset($m[1]) ? (float) str_replace(',', '', $m[1]) : 0.0);
        }
        if (preg_match('/^(?:jama|jamma|जम्मा|deposit|paid|pay|tirne|tireko|तिरेको|भुक्तानी|bhuktani|handover|बुझाएँ|bujhaye)\b/u', $l)) {
            return self::payIn($member, $text, $lang);
        }

        // Cards and me.
        if (preg_match('/^(?:id|id\s*card|idcard|आईडी|आइडी|परिचय\s*पत्र|parichay\s*patra|identity)(?:\s*(back|pachadi|पछाडि|पीछे))?$/u', $l, $m)) {
            return self::card($member, empty($m[1]) ? 'id-front' : 'id-back', $lang);
        }
        if (preg_match('/^(?:visiting|visiting\s*card|vc|card|business\s*card|भिजिटिङ|विजिटिंग)(?:\s*card)?(?:\s*(back|pachadi|पछाडि|पीछे))?$/u', $l, $m)) {
            return self::card($member, empty($m[1]) ? 'vc-front' : 'vc-back', $lang);
        }
        if (preg_match('/^(?:me|profile|mero\s*detail|my\s*details|details|मेरो\s*विवरण|विवरण)$/u', $l)) {
            return self::profile($member, $lang);
        }

        // People and the company.
        if (preg_match('/^(?:list|contacts?|directory|numbers?|सबैको\s*नम्बर|नम्बरहरू|agents?|staff|team|एजेन्टहरू|स्टाफ)(?:\s+(.+))?$/u', $l, $m)) {
            return self::directory($member, $lang, trim((string) ($m[1] ?? '')), $l);
        }
        if (preg_match('/^(?:number|nambar|नम्बर|phone|फोन)\s+(?:of\s+)?(.{2,40})$/u', $l, $m) && preg_match('/\d{6,}/', $m[1]) !== 1) {
            return self::directory($member, $lang, trim($m[1]), 'search');
        }
        if (preg_match('/^(?:office|company|cin|address|thegana|ठेगाना|कार्यालय|अफिस|ऑफिस|contact\s*office|email|website|counters?|काउन्टर)\b/u', $l)) {
            return self::company($lang);
        }
        if (preg_match('/^(?:bus|buses|gadi|gaadi|गाडी|बस|aaja\s*ko\s*bus|bus\s*number|bus\s*details)\b(?:\s+(bholi|tomorrow|भोलि|kal|कल))?/u', $l, $m)) {
            return self::buses($member, $lang, !empty($m[1]) ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d'));
        }
        if (preg_match('/^(?:today|aaja|aja|आज|report|sales|bikri|बिक्री|मेरो\s*दिन|my\s*day|आजको\s*रिपोर्ट)\b/u', $l)) {
            return self::report($member, $ctx, $lang, str_contains($l, 'office') || str_contains($l, 'company') ? 'office' : '');
        }
        if (preg_match('/^(?:passengers?|yatri|यात्रु|यात्री|manifest)\b(?:\s+(.+))?$/u', $l, $m)) {
            $d = isset($m[1]) ? self::dateWord((string) $m[1]) : date('Y-m-d');
            return self::passengers($member, $ctx, $lang, $d !== '' ? $d : date('Y-m-d'));
        }

        // A sale.
        if (in_array((string) $member['role'], self::SELLERS, true)) {
            $sale = self::saleParse($text);
            if ($sale !== null) {
                return self::saleQuote($sale, $member, $ctx, $lang);
            }
        }
        return null;
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
            $to = trim((string) ($p['payout_method'] ?? '') . ' ' . (string) ($p['payout_account'] ?? ''));
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
        $ref = (string) preg_replace('/^(?:ref|utr|txn|rrn)\.?(?:\s*[:#-]\s*|\s+)(?=\S)/i', '', trim($ref));
        $ref = trim((string) preg_replace('/[^A-Za-z0-9\/\-_.]/', '', $ref));
        if (mb_strlen($ref) < 5 || mb_strlen($ref) > 60) {
            return self::out(self::t('adm_wd_needref', $lang, ['id' => (string) $id]));
        }
        if (self::payoutRefUsed($ref)) {
            return self::out(self::t('adm_wd_refused', $lang, ['ref' => $ref]));
        }
        $aid = (int) $req['agent_admin_id'];
        $p = AgentWallet::profile($aid);
        $to = trim((string) ($p['payout_method'] ?? '') . ' ' . (string) ($p['payout_account'] ?? ''));
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
        AgentPro::tellMember($aid, '💸 ' . inr((float) $p['amount']) . ' पठाइयो / paid to you. Ref ' . $voucher . ' · ' . $ref);
        $text = self::t('adm_paid', $lang, ['id' => (string) $id, 'v' => inr((float) $p['amount']), 'voucher' => $voucher, 'ref' => $ref]);
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
        AgentPro::tellMember($aid, $decision === 'confirmed'
            ? '✅ ' . inr((float) $row['amount']) . ' प्राप्त भयो, धन्यवाद। / received, thank you.' . (!empty($row['utr']) ? ' Ref ' . $row['utr'] : '')
            : '⚠️ ' . inr((float) $row['amount']) . ' भुक्तानी भेटिएन / not matched: ' . $note);
        $text = self::t($decision === 'confirmed' ? 'adm_dep_done' : 'adm_dep_declined', $lang, ['id' => (string) $id, 'v' => inr((float) $row['amount'])]);
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
        $b = array_intersect_key($seed, array_flip(['direction', 'date', 'boarding', 'seats', 'name', 'phone', 'country', 'names', 'gender', 'pay']));
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
            mb_strlen(trim((string) ($b['name'] ?? ''))) < 2 || (string) ($b['phone'] ?? '') === '' => 'who',
            default => 'quote',
        };
        if ($step === 'quote') {
            $sale = [
                'name' => (string) $b['name'], 'phone' => (string) $b['phone'], 'country' => (string) ($b['country'] ?? ''),
                'seats' => (int) $b['seats'], 'date' => (string) $b['date'], 'direction' => (string) ($b['direction'] ?? ''),
                'boarding' => (string) $b['boarding'], 'gender' => (string) ($b['gender'] ?? ''), 'pay' => (string) ($b['pay'] ?? ''),
                'names' => (array) ($b['names'] ?? []),
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
        if (preg_match('/^(help|menu|madat|मद्दत|reset|naya|नयाँ|balance|statement|bus|office|tickets|portal|admin)$/u', $l) === 1
            || preg_match('/\bSHG-[A-Z0-9]+-/i', $text) === 1) {
            self::clearPending($ctx['phone']);
            return null;
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
            if ($step === 'who' && empty($b['names'])) {
                $nm = (string) $p['name'];
                if ($nm === '') {
                    $bare = trim((string) preg_replace('/\+?\d[\d\s-]{6,}\d/', ' ', $text));
                    if (preg_match('/^\p{L}[\p{L}\p{M} .]{1,60}$/u', $bare) === 1 && preg_match(self::YES, $bare) !== 1 && preg_match(self::NO, $bare) !== 1) {
                        $nm = $bare;
                    }
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

    private static function balance(array $member, string $lang): array
    {
        $id = (int) $member['id'];
        $b = AgentWallet::balances($id);
        $s = AgentWallet::summary($id);
        $open = AgentWallet::openPayoutRequests($id);
        $dep = AgentPro::openDeposits($id);
        $lines = [self::t('bal_head', $lang, ['name' => (string) $member['full_name'], 'code' => self::codeOf($id)])];
        $lines[] = self::t('bal_commission', $lang, ['v' => inr($b['commission'])]);
        $lines[] = self::t('bal_cash', $lang, ['v' => inr($b['cash'])]);
        $lines[] = self::t('bal_month', $lang, ['v' => inr($s['earnedMonth'])]);
        if ($open !== []) {
            $lines[] = self::t('bal_open', $lang, ['v' => inr((float) $open[0]['amount']), 'd' => formatDate(substr((string) $open[0]['created_at'], 0, 10), 'j M')]);
        }
        if ($dep !== []) {
            $lines[] = self::t('bal_dep', $lang, ['n' => (string) count($dep), 'v' => inr(array_sum(array_map(static fn($d) => (float) $d['amount'], $dep)))]);
        }
        $lines[] = '';
        $lines[] = self::t('bal_tail', $lang);
        return self::out(implode("\n", $lines));
    }

    private static function statement(array $member, string $lang, int $days): array
    {
        $id = (int) $member['id'];
        $days = $days > 0 ? min(366, $days) : 30;
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $to = date('Y-m-d');
        $rows = AgentWallet::entriesBetween($id, $from, $to);
        $lines = [self::t('stmt_head', $lang, ['days' => (string) $days, 'name' => (string) $member['full_name']])];
        $labels = self::entryLabels($lang);
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
        $lines[] = '';
        $lines[] = self::t('bal_commission', $lang, ['v' => inr($b['commission'])]);
        $lines[] = self::t('bal_cash', $lang, ['v' => inr($b['cash'])]);
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
        $to = trim((string) ($p['payout_method'] ?? '') . ' ' . (string) ($p['payout_account'] ?? ''));
        self::setPending($ctx['phone'], 'withdraw', ['amount' => $amount]);
        return self::out(self::t('wd_confirm', $lang, ['v' => inr($amount), 'to' => $to !== '' ? $to : self::t('wd_to_office', $lang)]));
    }

    private static function payIn(array $member, string $text, string $lang): array
    {
        $id = (int) $member['id'];
        require_once __DIR__ . '/agentpro-docs.php';
        $t = ApDocRead::asciiDigits($text);
        $utr = preg_match('/\b(?:utr|ref|txn|reference|रेफ)\s*[:#-]?\s*([A-Za-z0-9-]{6,30})/iu', $t, $m) === 1 ? $m[1]
             : (preg_match('/\b(\d{10,22})\b/', $t, $m2) === 1 ? $m2[1] : '');
        $amount = 0.0;
        $tNoUtr = $utr !== '' ? str_replace($utr, ' ', $t) : $t;
        if (preg_match('/(?:rs\.?|₹|inr)?\s*([\d,]{2,8}(?:\.\d{1,2})?)/iu', $tNoUtr, $m3) === 1) {
            $amount = (float) str_replace(',', '', $m3[1]);
        }
        $held = AgentWallet::balances($id)['cash'];
        if ($amount > 0 && $utr !== '') {
            try {
                $rid = AgentPro::requestDeposit($id, $amount, $amount > $held + 0.009 ? 'security' : 'handover', 'upi', $utr, null, 'WhatsApp');
            } catch (RuntimeException $e) {
                return self::out('⚠️ ' . $e->getMessage());
            }
            try {
                require_once __DIR__ . '/notify.php';
                Notify::adminNote('💵 Payment to verify (WhatsApp)', ['Member' => (string) $member['full_name'], 'Amount' => inr($amount),
                    'UTR' => $utr, 'Open' => appUrl('admin/team.php?tab=money')]);
            } catch (Throwable $e) {
                // the request is saved; the office sees it on the Team page
            }
            return self::out(self::t('pay_saved', $lang, ['v' => inr($amount), 'utr' => $utr, 'id' => (string) $rid]));
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

    /* ---------------- people and the company ---------------- */

    private static function directory(array $member, string $lang, string $query, string $raw): array
    {
        $office = self::officeMember($member);            // every agent's number: an OTP-linked office number only
        $wantAgents = str_starts_with($raw, 'agent') || str_starts_with($raw, 'एजेन्ट');
        $wantStaff  = str_starts_with($raw, 'staff') || str_starts_with($raw, 'स्टाफ') || str_starts_with($raw, 'team');
        $seeAgents  = $office || Settings::getBool('ap_wa_dir_agents', false);
        $rows = AgentPro::roster();
        $out = [];
        $q = mb_strtolower($query);
        foreach ($rows as $r) {
            if (!$r['active'] || $r['phone'] === '') {
                continue;
            }
            if ($r['isAgent'] && !$seeAgents && $r['id'] !== (int) $member['id']) {
                continue;
            }
            if (!$r['isAgent'] && !$office && !in_array($r['role'], self::OFFICE, true) && $r['role'] !== 'counter') {
                continue;                                    // agents see the office and the counters
            }
            if ($wantAgents && !$r['isAgent']) { continue; }
            if ($wantStaff && $r['isAgent']) { continue; }
            if ($q !== '' && !str_contains(mb_strtolower($r['name'] . ' ' . $r['idNo'] . ' ' . $r['branch']), $q)) {
                continue;
            }
            $out[] = ($r['idNo'] !== '' ? $r['idNo'] . ' · ' : '') . $r['name'] . ' — ' . AgentPro::displayPhone(AgentPro::intlDigits($r['phone']))
                . ($r['branch'] !== '' ? ' · ' . $r['branch'] : '') . ($r['isAgent'] ? '' : ' (' . $r['designation'] . ')');
        }
        $co = Settings::company();
        $head = self::t('dir_head', $lang, ['n' => (string) count($out)]);
        $lines = array_slice($out, 0, 40);
        if (count($out) > 40) {
            $lines[] = '… +' . (count($out) - 40) . ' · ' . self::t('dir_more', $lang);
        }
        if ($out === []) {
            $lines[] = self::t('dir_none', $lang);
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
                $lines[] = '   ' . $p['seat'] . ' ' . $p['name'] . ' · ' . $p['phone'];
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
        if (preg_match('/\b(cancel|radda|radd|रद्द|क्यान्सिल|कैंसिल)\b/u', $l)) {
            $q = AiTools::run('refund_quote', ['pnr' => $pnr], $ctx);
            if (!$q['ok']) {
                return self::out('⚠️ ' . $q['say']);
            }
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
        if (preg_match('/\b(resend|pathau|pathaunu|फेरि\s*पठाउ|पठाउनु|bhej|भेज|send\s*again)\b/u', $l)) {
            $r = AiTools::run('resend_ticket', ['pnr' => $pnr], $ctx);
            return self::out(($r['ok'] ? '✅ ' : '⚠️ ') . self::t($r['ok'] ? 'resent' : 'error', $lang, ['pnr' => $pnr]), $r['media'] ?? null);
        }
        [$field, $value, $which] = self::editIntent($rest);
        if ($field !== '') {
            return self::editPreview($pnr, $field, $value, $which, $ctx, $lang);
        }
        if ($rest !== '' && $member !== null && preg_match('/\p{L}{3,}/u', $rest) === 1 && !preg_match('/^(status|kaha|कहाँ|where|ticket|टिकट|detail)/iu', $l)) {
            return null;                                       // a sentence about a ticket: let the AI read it
        }
        return self::bookingCard($pnr, $ctx, $lang);
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
            $new = formatDate($new, 'D, j M Y') . (!empty($c['seats']) ? ' · ' . implode(', ', (array) $c['seats']) : '');
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
        $pax = array_map(static fn(array $p): string => (string) $p['seat_no'] . ' ' . $p['full_name'], $d['passengers'] ?? []);
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
        $seats = max((int) $p['seats'], count($names), 1);
        $name = $names[0] ?? (string) $p['name'];
        return [
            'name' => $name, 'phone' => (string) $p['phone'], 'country' => (string) $p['country'], 'seats' => $seats,
            'date' => (string) $p['date'], 'direction' => (string) $p['direction'], 'boarding' => (string) $p['boarding'],
            'gender' => (string) $p['gender'], 'pay' => (string) $p['pay'], 'names' => $names,
        ];
    }

    private static function saleQuote(array $sale, array $member, array $ctx, string $lang): array
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
        $need = [];
        if (mb_strlen(trim((string) $sale['name'])) < 2) { $need[] = self::t('f_name', $lang); }
        if ((string) $sale['phone'] === '') { $need[] = self::t('f_phone', $lang); }
        $sale['plan'] = ['date' => (string) $plan['date'], 'direction' => (string) $plan['direction'], 'boarding' => (string) $plan['boarding'],
                         'boardingCode' => (string) $plan['boardingCode'], 'seats' => (int) $plan['seatCount'], 'total' => (float) $plan['fare']['total']];
        self::setPending($ctx['phone'], 'sale', ['sale' => $sale]);
        $text = self::t('sale_quote', $lang, [
            'date' => (string) $plan['dateLabel'], 'dep' => substr((string) $plan['depTime'], 0, 5),
            'route' => $plan['from'] . ' → ' . $plan['to'], 'pickup' => (string) $plan['boardingName'],
            'ptime' => substr((string) $plan['boardingTime'], 0, 5), 'seats' => implode(', ', array_map('strval', (array) $plan['seats'])),
            'n' => (string) $plan['seatCount'], 'total' => inr((float) $plan['fare']['total']), 'left' => (string) $plan['seatsLeft'],
            'who' => trim(($sale['names'] !== [] ? implode(', ', $sale['names']) : (string) $sale['name']) . ' ' . (string) $sale['phone']),
        ]);
        if ($need !== []) {
            $text .= "\n\n" . self::t('sale_need', $lang, ['what' => implode(' + ', $need)]);
        } else {
            $text .= "\n\n" . self::t('sale_ask_yes', $lang);
        }
        return self::out($text);
    }

    /** While a quote waits: "Ram Thapa 98…", "names …", "upi", "female" fill it in. */
    private static function saleAmend(array $pending, string $text, array $member, array $ctx, string $lang): ?array
    {
        $sale = (array) ($pending['sale'] ?? []);
        if ($sale === [] || preg_match('/\bSHG-[A-Z0-9]+-/i', $text) === 1) {
            return null;
        }
        $changed = false;
        if (preg_match('/^(?:names?|naam(?:haru)?|नामहरू|नाम)\s*[:\-]?\s*(.+)$/iu', $text, $m) === 1) {
            $list = array_values(array_filter(array_map('trim', preg_split('/\s*(?:,|;|\band\b|\bra\b|र|और)\s*/u', $m[1]) ?: [])));
            if ($list !== []) {
                $sale['names'] = count($list) >= 2 ? $list : [];
                $sale['name'] = $list[0];
                $changed = true;
            }
        }
        if (preg_match('/^(cash|upi|esewa|bank|नगद|नकद)$/iu', trim($text), $m) === 1) {
            $sale['pay'] = ['नगद' => 'cash', 'नकद' => 'cash'][$m[1]] ?? strtolower($m[1]);
            $changed = true;
        }
        if (!$changed) {
            $again = self::saleParse($text . ' ' . ((int) ($sale['seats'] ?? 1)) . ' seat');
            require_once __DIR__ . '/ticketbot.php';
            $p = TicketBot::parse($text);
            if ((string) $p['phone'] !== '' || ((string) $p['name'] !== '' && preg_match('/^\p{L}[\p{L} .]{1,60}$/u', trim($text)) === 1)) {
                if ((string) $p['phone'] !== '') { $sale['phone'] = (string) $p['phone']; }
                if ((string) $p['name'] !== '') { $sale['name'] = (string) $p['name']; }
                $changed = true;
            } elseif ($again !== null && ((string) $again['date'] !== '' || (int) $again['seats'] !== (int) ($sale['seats'] ?? 1))) {
                return null;                                    // a different booking: quote it fresh
            }
        }
        return $changed ? self::saleQuote($sale, $member, $ctx, $lang) : null;
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

            case 'sale':
                return self::saleConfirm((array) $p['sale'], $member, $ctx, $lang);

            case 'edit':
                require_once __DIR__ . '/agentpro-edit.php';
                $r = ApEdit::apply((array) $p['change'], $ctx);
                return $r['ok'] ? self::out(self::t('edit_done', $lang, ['pnr' => (string) $r['pnr']]), $r['media'] ?? null)
                                : self::out(self::editError($r, $lang));

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
        try {
            $res = QuickTicket::sell([
                'name' => (string) $sale['name'], 'phone' => (string) $sale['phone'], 'country' => (string) ($sale['country'] ?? ''),
                'seats' => (int) $sale['seats'], 'date' => $opts['date'], 'direction' => $opts['direction'], 'boarding' => $opts['boarding'],
                'gender' => $opts['gender'], 'pay' => in_array($sale['pay'], ['cash', 'upi', 'esewa', 'bank'], true) ? $sale['pay'] : 'cash',
                'passengers' => count($party) >= 2 ? $party : [], 'note' => 'WhatsApp desk (Agent Pro)',
            ], $member);
        } catch (RuntimeException $e) {
            return self::out('⚠️ ' . $e->getMessage());
        }
        $pnr = (string) ($res['pnr'] ?? '');
        Logger::audit('agentpro.wa_sale', 'booking', $pnr, null, ['seller' => (int) $member['id'], 'wa' => $ctx['phone'],
            'seats' => (int) $sale['seats'], 'total' => (float) ($want['total'] ?? 0)], 'Sold from the WhatsApp desk');
        $media = ($res['status'] ?? '') === 'confirmed' && $pnr !== '' ? Ticket::imageUrl($pnr) : null;
        $text = self::t('sale_done', $lang, ['pnr' => $pnr, 'seats' => implode(', ', array_map('strval', (array) ($res['seats'] ?? []))),
            'total' => (string) ($res['totalLabel'] ?? ''), 'phone' => (string) $sale['phone']]);
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
        return self::act($intent, $member, $ctx, $lang) ?? self::aiFallback($member, $lang);
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
            case 'withdraw':      return self::withdraw($member, $ctx, $lang, (float) ($i['amount'] ?? 0));
            case 'deposit':       return self::payIn($member, 'jama ' . ((float) ($i['amount'] ?? 0) ?: '') . ' ' . ($i['ref'] ?? ''), $lang);
            case 'id_card':       return self::card($member, 'id-front', $lang);
            case 'visiting_card': return self::card($member, 'vc-front', $lang);
            case 'profile':       return self::profile($member, $lang);
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
                'ne' => "🙏 नमस्ते {name}{code}! {company} WhatsApp डेस्क।\nनम्बर वा शब्द पठाउनुहोस्:\n\n1️⃣ 🎫 टिकट बुक — *ticket* (वा सिधै: *2 सिट भोलि Mehsana, Ram Thapa 98XXXXXXXX*)\n2️⃣ 📋 मेरा टिकट — *tickets* · *tickets bholi*\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 पोर्टल लगइन — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me*\n8️⃣ 📊 *aaja* (आजको हिसाब) · *passengers bholi* · *bus*\n9️⃣ 📞 *office* · *contacts*\n\n✏️ सच्याउने: *SHG-… नाम Sita Thapa* (pickup / seat / phone / date पनि)\n❌ रद्द अनुरोध: *SHG-… cancel* · 🔁 *SHG-… resend*\n💵 *jama* · 🌐 *english* / *hindi* / *nepali*",
                'hi' => "🙏 नमस्ते {name}{code}! {company} WhatsApp डेस्क।\nनंबर या शब्द भेजें:\n\n1️⃣ 🎫 टिकट बुक — *ticket* (या सीधे: *2 सीट कल Mehsana, Ram Thapa 98XXXXXXXX*)\n2️⃣ 📋 मेरे टिकट — *tickets* · *tickets kal*\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 पोर्टल लॉगिन — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me*\n8️⃣ 📊 *today* · *passengers kal* · *bus*\n9️⃣ 📞 *office* · *contacts*\n\n✏️ बदलें: *SHG-… नाम Sita Thapa* (pickup / seat / phone / date भी)\n❌ रद्द अनुरोध: *SHG-… cancel* · 🔁 *SHG-… resend*\n💵 *jama* · 🌐 *english* / *hindi* / *nepali*",
                'en' => "🙏 Hello {name}{code}! {company} WhatsApp desk.\nSend a number or a word:\n\n1️⃣ 🎫 Ticket — *ticket* (or in one line: *2 seats tomorrow Mehsana, Ram Thapa 98XXXXXXXX*)\n2️⃣ 📋 My tickets — *tickets* · *tickets tomorrow*\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 Portal sign-in — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me*\n8️⃣ 📊 *today* · *passengers tomorrow* · *bus*\n9️⃣ 📞 *office* · *contacts*\n\n✏️ Fix: *SHG-… name Sita Thapa* (or pickup / seat / phone / date)\n❌ Cancel request: *SHG-… cancel* · 🔁 *SHG-… resend*\n💵 *pay* · 🌐 *english* / *hindi* / *nepali*",
            ],
            'help_member' => [
                'ne' => "🙏 नमस्ते {name}{code}! {company} WhatsApp डेस्क।\nनम्बर वा शब्द पठाउनुहोस्:\n\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 पोर्टल लगइन — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me*\n9️⃣ 📞 *office* · *contacts* · *bus*\n💵 *jama* · 🌐 *english* / *hindi* / *nepali*",
                'hi' => "🙏 नमस्ते {name}{code}! {company} WhatsApp डेस्क।\nनंबर या शब्द भेजें:\n\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 पोर्टल लॉगिन — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me*\n9️⃣ 📞 *office* · *contacts* · *bus*\n💵 *jama* · 🌐 *english* / *hindi* / *nepali*",
                'en' => "🙏 Hello {name}{code}! {company} WhatsApp desk.\nSend a number or a word:\n\n3️⃣ 💰 *balance* · 4️⃣ 📒 *statement* · 5️⃣ 💸 *withdraw 2000*\n6️⃣ 🔑 Portal sign-in — *portal*\n7️⃣ 🪪 *id card* · *visiting card* · *me*\n9️⃣ 📞 *office* · *contacts* · *bus*\n💵 *pay* · 🌐 *english* / *hindi* / *nepali*",
            ],
            'help_admin_line' => ['ne' => "0️⃣ 🛡️ *admin* — अफिस: आवेदन, withdraw स्वीकृति, आजको बिक्री", 'hi' => "0️⃣ 🛡️ *admin* — ऑफिस: आवेदन, withdraw मंज़ूरी, आज की बिक्री", 'en' => "0️⃣ 🛡️ *admin* — office: applications, withdrawal approvals, today's sales"],
            'admin_help'  => [
                'ne' => "🛡️ अफिस डेस्क\n📝 *joins* — नयाँ आवेदन ({apps})\n💸 *withdrawals* — withdraw अनुरोध ({wd})\n✅ *approve 12 UTR430987651234* — भुक्तानी गरेपछि रेकर्ड\n⛔ *reject 12 कारण* — अस्वीकार\n📊 *today* — कम्पनीको आजको बिक्री · *pending* — बाँकी काम\n💵 *deposits* — भुक्तानी जाँच ({dep}) · *jama ok 12* · *jama no 12 कारण*\n🖥️ {link}\n\nपैसाको हरेक काम *CONFIRM* पछि मात्र हुन्छ।",
                'hi' => "🛡️ ऑफिस डेस्क\n📝 *joins* — नए आवेदन ({apps})\n💸 *withdrawals* — withdraw अनुरोध ({wd})\n✅ *approve 12 UTR430987651234* — भुगतान के बाद दर्ज करें\n⛔ *reject 12 कारण* — अस्वीकार\n📊 *today* — कंपनी की आज की बिक्री · *pending* — बाकी काम\n💵 *deposits* — भुगतान जाँच ({dep}) · *jama ok 12* · *jama no 12 कारण*\n🖥️ {link}\n\nपैसे का हर काम *CONFIRM* के बाद ही होता है।",
                'en' => "🛡️ Office desk\n📝 *joins* — new applications ({apps})\n💸 *withdrawals* — withdrawal requests ({wd})\n✅ *approve 12 UTR430987651234* — record a payout you have made\n⛔ *reject 12 reason* — decline\n📊 *today* — the company's sales today · *pending* — what is waiting\n💵 *deposits* — payments to check ({dep}) · *jama ok 12* · *jama no 12 reason*\n🖥️ {link}\n\nEvery money action happens only after *CONFIRM*.",
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
            'adm_paid'    => ['ne' => "✅ #{id} भुक्तानी रेकर्ड भयो — {v} · भौचर {voucher} · Ref {ref}। सदस्यलाई WhatsApp गयो।", 'hi' => "✅ #{id} भुगतान दर्ज — {v} · वाउचर {voucher} · Ref {ref}। सदस्य को WhatsApp गया।", 'en' => "✅ #{id} payout recorded — {v} · voucher {voucher} · ref {ref}. The member was told on WhatsApp."],
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
            'adm_dep_done' => ['ne' => "✅ #{id} {v} प्राप्त भएको रेकर्ड भयो; सदस्यलाई WhatsApp गयो।", 'hi' => "✅ #{id} {v} मिलना दर्ज; सदस्य को WhatsApp गया।", 'en' => "✅ #{id} {v} recorded as received; the member was told on WhatsApp."],
            'adm_dep_declined' => ['ne' => "✅ #{id} अस्वीकार गरियो; सदस्यलाई कारण पठाइयो।", 'hi' => "✅ #{id} अस्वीकार; सदस्य को कारण भेजा गया।", 'en' => "✅ #{id} declined; the member was told why."],
            'already_done'=> ['ne' => "ℹ️ यो पहिल्यै भइसक्यो — फेरि गरिएन।", 'hi' => "ℹ️ यह पहले ही हो चुका है — दोबारा नहीं किया।", 'en' => "ℹ️ This was already done — nothing was repeated."],
            'nothing_pending' => ['ne' => "ℹ️ अहिले पुष्टि गर्नुपर्ने केही छैन। *help* लेख्नुहोस्।", 'hi' => "ℹ️ अभी पुष्टि करने को कुछ नहीं है। *help* लिखें।", 'en' => "ℹ️ There is nothing waiting for confirmation. Type *help*."],
            'done_no_undo'=> ['ne' => "ℹ️ त्यो भइसकेको छ — फिर्ता गर्न अफिसलाई भन्नुहोस् (टिकट भए *PNR cancel*)।", 'hi' => "ℹ️ वह हो चुका है — वापस करने के लिए ऑफिस से कहें (टिकट हो तो *PNR cancel*)।", 'en' => "ℹ️ That is already done — ask the office to undo it (for a ticket: *PNR cancel*)."],
            'tk_head'     => ['ne' => "📋 तपाईंले बेचेका पछिल्ला टिकट ({n})", 'hi' => "📋 आपके बेचे हाल के टिकट ({n})", 'en' => "📋 Your latest tickets ({n})"],
            'tk_head_date'=> ['ne' => "📋 {d} मा यात्रा गर्ने तपाईंका टिकट ({n})", 'hi' => "📋 {d} को यात्रा वाले आपके टिकट ({n})", 'en' => "📋 Your tickets travelling {d} ({n})"],
            'tk_none'     => ['ne' => "(कुनै टिकट छैन)", 'hi' => "(कोई टिकट नहीं)", 'en' => "(no tickets)"],
            'portal_agent'=> ['ne' => "🔑 तपाईंको पोर्टल: {me}\nएजेन्ट कोड: *{code}* · मोबाइल: {mob}\nत्यहाँ कोड र मोबाइल लेख्नुहोस् — OTP यहीँ WhatsApp मा आउँछ। पछि fingerprint ले पनि खुल्छ।\nयो लिंक/OTP कसैलाई नदिनुहोस्।", 'hi' => "🔑 आपका पोर्टल: {me}\nएजेंट कोड: *{code}* · मोबाइल: {mob}\nवहाँ कोड और मोबाइल लिखें — OTP यहीं WhatsApp पर आएगा। बाद में fingerprint से भी खुलेगा।\nयह OTP किसी को न दें।", 'en' => "🔑 Your portal: {me}\nAgent code: *{code}* · mobile: {mob}\nType the code and mobile there — the OTP comes here on WhatsApp. Later a fingerprint opens it too.\nNever share the OTP."],
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
            'wd_confirm'  => ['ne' => "💸 *{v}* निकाल्ने अनुरोध पठाउने?\nपठाउने ठाउँ: {to}\n\n*ho* लेख्नुहोस् (नभए *no*)।", 'hi' => "💸 *{v}* निकालने का अनुरोध भेजें?\nभेजने की जगह: {to}\n\n*ha* लिखें (नहीं तो *no*)।", 'en' => "💸 Send a withdrawal request for *{v}*?\nPaid to: {to}\n\nReply *yes* (or *no*)."],
            'wd_saved'    => ['ne' => "✅ {v} को अनुरोध #{id} अफिसमा पुग्यो। भुक्तानी भएपछि यहीँ खबर आउँछ।", 'hi' => "✅ {v} का अनुरोध #{id} ऑफिस पहुँच गया। भुगतान होने पर यहीं सूचना आएगी।", 'en' => "✅ Request #{id} for {v} is with the office. You will be told here when it is paid."],
            'pay_how'     => ['ne' => "💵 तपाईंसँग कम्पनीको नगद: {held}\n\nUPI: *{upi}* मा {v} तिर्नुहोस् (QR माथि छ)\nतिरेपछि यस्तो लेख्नुहोस्:\n*jama 1500 UTR 430987651234*", 'hi' => "💵 आपके पास कंपनी का नकद: {held}\n\nUPI: *{upi}* पर {v} भरें (QR ऊपर है)\nभरने के बाद ऐसे लिखें:\n*jama 1500 UTR 430987651234*", 'en' => "💵 Company cash you hold: {held}\n\nPay {v} to UPI *{upi}* (QR above)\nThen send:\n*pay 1500 UTR 430987651234*"],
            'pay_saved'   => ['ne' => "✅ {v} (UTR {utr}) अफिसमा पठाइयो — #{id}। बैंकमा मिलेपछि यहीँ पुष्टि आउँछ।", 'hi' => "✅ {v} (UTR {utr}) ऑफिस भेजा गया — #{id}। बैंक में मिलने पर यहीं पुष्टि आएगी।", 'en' => "✅ {v} (UTR {utr}) sent to the office — #{id}. You will get a confirmation here once it is matched."],
            'card_id'     => ['ne' => "🪪 तपाईंको ID कार्ड ({code})\nपछाडि: *id back* · प्रिन्ट / डाउनलोड: {me}", 'hi' => "🪪 आपका ID कार्ड ({code})\nपीछे: *id back* · प्रिंट / डाउनलोड: {me}", 'en' => "🪪 Your ID card ({code})\nBack side: *id back* · print / download: {me}"],
            'card_vc'     => ['ne' => "💼 तपाईंको visiting card — QR स्क्यान गर्दा सिधै WhatsApp {wa}\nपछाडि: *card back*\nसेयर गर्ने लिंक: {share}", 'hi' => "💼 आपका visiting card — QR स्कैन करते ही सीधे WhatsApp {wa}\nपीछे: *card back*\nशेयर लिंक: {share}", 'en' => "💼 Your visiting card — scanning the QR opens WhatsApp {wa}\nBack side: *card back*\nShare link: {share}"],
            'profile'     => ['ne' => "👤 {name}\n🪪 {code} · {role}\n☎ {phone} · WA {wa}\n📍 {branch}\n✅ KYC: {kyc} · कार्ड {valid} सम्म\n🏦 {bank}\n📱 {me}", 'hi' => "👤 {name}\n🪪 {code} · {role}\n☎ {phone} · WA {wa}\n📍 {branch}\n✅ KYC: {kyc} · कार्ड {valid} तक\n🏦 {bank}\n📱 {me}", 'en' => "👤 {name}\n🪪 {code} · {role}\n☎ {phone} · WA {wa}\n📍 {branch}\n✅ KYC: {kyc} · card valid till {valid}\n🏦 {bank}\n📱 {me}"],
            'dir_head'    => ['ne' => "📇 सम्पर्क ({n})", 'hi' => "📇 संपर्क ({n})", 'en' => "📇 Contacts ({n})"],
            'dir_more'    => ['ne' => "नाम लेखेर खोज्नुहोस्: *number Ram*", 'hi' => "नाम लिखकर खोजें: *number Ram*", 'en' => "search by name: *number Ram*"],
            'dir_none'    => ['ne' => "(कोही भेटिएन)", 'hi' => "(कोई नहीं मिला)", 'en' => "(nobody found)"],
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
            'e_same'      => ['ne' => "ℹ️ टिकटमा पहिल्यै त्यही छ।", 'hi' => "ℹ️ टिकट पर पहले से वही है।", 'en' => "ℹ️ The ticket already says that."],
            'e_value'     => ['ne' => "⚠️ नयाँ मान बुझिएन। जस्तै: *SHG-… seat L5* / *phone 98XXXXXXXX* / *date 2026-11-02*", 'hi' => "⚠️ नया मान समझ नहीं आया। जैसे: *SHG-… seat L5* / *phone 98XXXXXXXX* / *date 2026-11-02*", 'en' => "⚠️ I did not understand the new value. E.g. *SHG-… seat L5* / *phone 98XXXXXXXX* / *date 2026-11-02*"],
            'card_tail'   => ['ne' => "✏️ बदल्न: *PNR नाम / pickup / seat / phone / date …* · ❌ *PNR cancel*", 'hi' => "✏️ बदलने के लिए: *PNR नाम / pickup / seat / phone / date …* · ❌ *PNR cancel*", 'en' => "✏️ To change: *PNR name / pickup / seat / phone / date …* · ❌ *PNR cancel*"],
            'no_permission' => ['ne' => "🔒 तपाईंको खाताले यो गर्न मिल्दैन — अफिसलाई सोध्नुहोस्।", 'hi' => "🔒 आपके खाते से यह नहीं हो सकता — ऑफिस से पूछें।", 'en' => "🔒 Your account cannot do this — please ask the office."],
            'sale_quote'  => ['ne' => "🎫 कोटेसन (अझै बुक भएको छैन)\n📅 {date} · {dep} · {route}\n📍 {pickup} {ptime}\n💺 {n} सिट: {seats} (बाँकी {left})\n👤 {who}\n💰 जम्मा *{total}*", 'hi' => "🎫 कोटेशन (अभी बुक नहीं हुआ)\n📅 {date} · {dep} · {route}\n📍 {pickup} {ptime}\n💺 {n} सीट: {seats} (बाकी {left})\n👤 {who}\n💰 कुल *{total}*", 'en' => "🎫 Quote (nothing booked yet)\n📅 {date} · {dep} · {route}\n📍 {pickup} {ptime}\n💺 {n} seat(s): {seats} ({left} left)\n👤 {who}\n💰 Total *{total}*"],
            'sale_ask_yes'=> ['ne' => "टिकट काट्ने भए *ho* वा *CONFIRM* लेख्नुहोस्। (UPI भए *upi*, धेरै नाम: *names Ram, Sita*)", 'hi' => "टिकट काटना है तो *ha* या *CONFIRM* लिखें। (UPI हो तो *upi*, कई नाम: *names Ram, Sita*)", 'en' => "Reply *yes* or *CONFIRM* to issue. (UPI: *upi*; several travellers: *names Ram, Sita*)"],
            'sale_need'   => ['ne' => "✍️ अझै चाहियो: {what}। जस्तै: *Ram Thapa 98XXXXXXXX*", 'hi' => "✍️ अभी चाहिए: {what}। जैसे: *Ram Thapa 98XXXXXXXX*", 'en' => "✍️ Still needed: {what}. E.g. *Ram Thapa 98XXXXXXXX*"],
            'sale_done'   => ['ne' => "✅ टिकट काटियो! PNR *{pnr}*\n💺 {seats} · {total}\n📲 टिकट {phone} को WhatsApp मा गयो; कमिसन तपाईंको wallet मा।", 'hi' => "✅ टिकट कट गया! PNR *{pnr}*\n💺 {seats} · {total}\n📲 टिकट {phone} के WhatsApp पर गया; कमीशन आपके wallet में।", 'en' => "✅ Ticket issued! PNR *{pnr}*\n💺 {seats} · {total}\n📲 The ticket went to {phone} on WhatsApp; the commission is on your wallet."],
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
