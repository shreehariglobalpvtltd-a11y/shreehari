<?php
/**
 * =====================================================================
 *  WaGroup — S HARI AI in the office WhatsApp groups (29 Oct 2026)
 *
 *  Owner's "AI System Prompt (Opus 5.5 Max)", section 7: the AI sits in the
 *  office groups (S Hari Office / Agents / Drivers), LISTENS to everything
 *  and REPLIES only when it is called ("@S Hari AI", "AI बताउ"), when a
 *  fare / timetable / seats question is asked, or — on its own switch —
 *  when someone states a pickup time the timetable contradicts. Never to
 *  greetings, jokes or personal talk. Quiet hours 22:00–06:00.
 *
 *  HOW GROUP MESSAGES ARRIVE
 *  -------------------------
 *  Meta's Groups API (groups the business number creates) delivers a group
 *  message on the SAME webhook, with messages[].group_id beside "from".
 *  Before this file the webhook had no idea: a group message would have
 *  been answered PRIVATELY to whoever wrote it. whatsapp/webhook.php now
 *  hands every message carrying a group_id here and stops — whatever the
 *  switches say, a group message never reaches the one-to-one bot.
 *
 *  WHAT IT MAY SAY IN A GROUP
 *  --------------------------
 *  Everyone in the group reads the reply, so it answers as it would answer
 *  a stranger: WaLocal::answerPublic() (timetable, seats, offices, refund
 *  rule — never a ticket, a passenger list, a summary or money), or, when
 *  called by name, the AI with the public briefing and NO tools.
 *
 *  SWITCHES (all off until the office turns them on, admin/wa-inbox.php)
 *    wa_group_reply_on    master switch for any reply in any group
 *    wa_groups            JSON {group_id: {name, reply}} — reply per group
 *    wa_group_correct_on  correct a wrong pickup time
 *    wa_group_quiet_from / wa_group_quiet_to   HH:MM, default 22:00 / 06:00
 *  Every group message is kept in wa_group_log (self-creating) either way.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaGroup
{
    private static bool $ready = false;

    /** Tests replace the Meta call: fn(string $groupId, string $text): array{success:bool, message_id?:string, error?:string} */
    public static ?Closure $transport = null;

    /** Words that call the assistant by name. */
    private const MENTIONS = ['@s hari', '@shari', '@shg', 's hari ai', 'shari ai', 'shg ai', 'ai bata', 'ai batau',
        'ai bataunu', 'ai बताउ', 'ai बताऊ', 'एआई बताउ', 'एआई'];

    public static function ensure(): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS wa_group_log (
                    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    group_id    VARCHAR(64)  NOT NULL,
                    from_digits VARCHAR(20)  NOT NULL DEFAULT '',
                    wamid       VARCHAR(128) NOT NULL DEFAULT '',
                    msg_type    VARCHAR(20)  NOT NULL DEFAULT '',
                    body        TEXT         NULL,
                    replied     TINYINT(1)   NOT NULL DEFAULT 0,
                    reply_why   VARCHAR(40)  NULL,
                    reply_text  TEXT         NULL,
                    created_at  DATETIME     NOT NULL,
                    KEY idx_group_time (group_id, created_at),
                    KEY idx_time (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$ready = true;
        } catch (Throwable $e) {
            Logger::warning('wa_group_log unavailable: ' . $e->getMessage(), [], 'whatsapp');
        }
        return self::$ready;
    }

    public static function isGroupMessage(array $msg): bool
    {
        return trim((string) ($msg['group_id'] ?? '')) !== '';
    }

    public static function replyOn(): bool
    {
        return Settings::getBool('wa_group_reply_on', false);
    }

    public static function correctOn(): bool
    {
        return Settings::getBool('wa_group_correct_on', false);
    }

    /** @return array<string, array{name: string, reply: bool}> */
    public static function groups(): array
    {
        $out = [];
        foreach (Settings::getArray('wa_groups', []) as $id => $g) {
            $id = self::cleanId((string) $id);
            if ($id === '' || !is_array($g)) {
                continue;
            }
            $out[$id] = ['name' => mb_substr(trim((string) ($g['name'] ?? '')), 0, 60), 'reply' => !empty($g['reply'])];
        }
        return $out;
    }

    public static function cleanId(string $id): string
    {
        $id = trim($id);
        return preg_match('/^[A-Za-z0-9_.@:\-]{3,64}$/', $id) === 1 ? $id : '';
    }

    /** Inside the quiet hours (wrapping midnight)? */
    public static function quietNow(?int $now = null): bool
    {
        $from = self::minutes(Settings::getString('wa_group_quiet_from', '22:00'), 22 * 60);
        $to   = self::minutes(Settings::getString('wa_group_quiet_to', '06:00'), 6 * 60);
        $t    = (int) date('G', $now ?? time()) * 60 + (int) date('i', $now ?? time());
        if ($from === $to) {
            return false;
        }
        return $from < $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to);
    }

    private static function minutes(string $hhmm, int $default): int
    {
        return preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m) === 1 && (int) $m[1] < 24 && (int) $m[2] < 60
            ? (int) $m[1] * 60 + (int) $m[2] : $default;
    }

    /* =================================================================
     *  The webhook's door
     * ================================================================= */

    /**
     * Keep the message, decide, and answer IN THE GROUP when the rules say so.
     * Never throws into the webhook.
     */
    public static function inbound(array $msg, string $text): void
    {
        try {
            $gid   = self::cleanId((string) ($msg['group_id'] ?? ''));
            $from  = preg_replace('/\D/', '', (string) ($msg['from'] ?? '')) ?? '';
            $wamid = (string) ($msg['id'] ?? '');
            if ($gid === '') {
                return;
            }
            $rowId = 0;
            if (self::ensure()) {
                $rowId = (int) Database::insert('wa_group_log', [
                    'group_id' => $gid, 'from_digits' => substr($from, 0, 20), 'wamid' => substr($wamid, 0, 128),
                    'msg_type' => substr((string) ($msg['type'] ?? ''), 0, 20),
                    'body' => $text !== '' ? mb_substr($text, 0, 2000) : null, 'created_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $reply = self::decide($gid, $text);
            if ($reply === null) {
                return;
            }
            $r = self::send($gid, $reply['text']);
            if ($rowId > 0) {
                Database::update('wa_group_log', [
                    'replied' => !empty($r['success']) ? 1 : 0,
                    'reply_why' => substr($reply['why'] . (!empty($r['success']) ? '' : ' (send failed)'), 0, 40),
                    'reply_text' => mb_substr($reply['text'], 0, 2000),
                ], 'id = :i', ['i' => $rowId]);
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
        }
    }

    /**
     * What to say in this group to this text, or null for silence.
     *
     * @return array{text: string, why: string}|null
     */
    public static function decide(string $gid, string $text, ?int $now = null): ?array
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 1000 || !self::replyOn()) {
            return null;
        }
        $g = self::groups()[$gid] ?? null;
        if ($g === null || !$g['reply'] || self::quietNow($now)) {
            return null;
        }
        if (!Security::rateLimit('wa_group_reply', $gid, 6, 600)) {
            return null;                                    // a busy group never turns into a bot storm
        }

        $called = self::mentioned($text);
        $clean  = self::stripMention($text);

        // 1. A pickup time the timetable contradicts (own switch).
        if (self::correctOn()) {
            $fix = self::correction($clean);
            if ($fix !== null) {
                return ['text' => $fix, 'why' => 'correct'];
            }
        }

        // 2. A public question — timetable, seats, offices, refund rule.
        if ($called || self::looksLikeQuestion($clean)) {
            require_once INCLUDE_PATH . '/walocal.php';
            $pub = WaLocal::answerPublic($clean);
            if ($pub !== null && ($called || Security::rateLimit('wa_group_unasked', $gid, 1, 600))) {
                return ['text' => $pub['text'], 'why' => 'local:' . $pub['intent']];
            }
        }

        // 3. Called by name with anything else: the AI, public briefing, no tools.
        if ($called) {
            $ai = self::askAi($clean);
            if ($ai !== null) {
                return ['text' => $ai, 'why' => 'ai'];
            }
        }
        return null;                                         // greetings, jokes, personal talk: silence
    }

    public static function mentioned(string $text): bool
    {
        $t = mb_strtolower($text);
        foreach (array_merge(self::MENTIONS, self::extraMentions()) as $w) {
            if ($w !== '' && mb_strpos($t, $w) !== false) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function extraMentions(): array
    {
        $raw = Settings::getString('wa_group_mention_words', '');
        return array_values(array_filter(array_map(static fn(string $w): string => mb_strtolower(trim($w)), explode(',', $raw))));
    }

    private static function stripMention(string $text): string
    {
        $t = $text;
        foreach (array_merge(self::MENTIONS, self::extraMentions()) as $w) {
            if ($w !== '') {
                $t = (string) preg_replace('/' . preg_quote($w, '/') . '/iu', ' ', $t);
            }
        }
        return trim((string) preg_replace('/\s+/u', ' ', $t));
    }

    /** A question, not a remark: a "?" or a question word in Nepali / Hindi / English. */
    public static function looksLikeQuestion(string $text): bool
    {
        $t = ' ' . mb_strtolower($text) . ' ';
        if (str_contains($t, '?') || str_contains($t, '？')) {
            return true;
        }
        return preg_match('/(?<!\pL)(kati|kahile|kaha|kata|kun|ke cha|cha ki|chha ki|xa ki|kitna|kitne|kab|kahan|kaun|kya|when|what time|how many|how much|where|which|कति|कहिले|कहाँ|कुन|छ कि|कितना|कितने|कब|कहाँ|कौन|क्या)(?!\pL)/u', $t) === 1;
    }

    /* =================================================================
     *  Correction — a wrong pickup time
     * ================================================================= */

    /**
     * "⚠️ सच्याउँछु: Surat pickup 14:00 → 13:00 (source: route timetable)"
     * — only when the message names ONE boarding stop and ONE time, the stop
     * has ONE time in the timetable, and the stated time is neither its
     * 12-hour nor its 24-hour reading. Anything less certain stays silent.
     */
    public static function correction(string $text, ?array $stopTimes = null): ?string
    {
        $stopTimes ??= self::stopTimes();
        if ($stopTimes === []) {
            return null;
        }
        $norm = mb_strtolower(strtr($text, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']));
        $hit = [];
        foreach ($stopTimes as $name => $times) {
            if (preg_match('/(?<!\pL)' . preg_quote($name, '/') . '(?!\pL)/u', $norm) === 1) {
                $hit[$name] = $times;
            }
        }
        if (count($hit) !== 1) {
            return null;
        }
        $name  = (string) array_key_first($hit);
        $times = array_values(array_unique($hit[$name]));
        if (count($times) !== 1) {
            return null;                                     // the stop runs at two times on two routes
        }
        $said = self::timesIn($norm);
        if (count($said) !== 1) {
            return null;
        }
        [$label, $cands] = $said[0];
        [$h, $m] = array_map('intval', explode(':', $times[0]));
        if (in_array($h * 60 + $m, $cands, true)) {
            return null;                                     // what they said is right
        }
        return '⚠️ सच्याउँछु: ' . mb_convert_case($name, MB_CASE_TITLE) . ' pickup ' . $label . ' → '
            . sprintf('%02d:%02d', $h, $m) . ' (source: route timetable)';
    }

    /**
     * Times written in the text, each with every minute-of-day it can mean.
     *
     * @return list<array{0: string, 1: list<int>}>
     */
    public static function timesIn(string $t): array
    {
        $out = [];
        if (preg_match_all('/(?<!\d)(\d{1,2})[:.](\d{2})(?!\d)\s*(am|pm)?/u', $t, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $m) {
                $h = (int) $m[1];
                $mi = (int) $m[2];
                if ($h > 23 || $mi > 59) {
                    continue;
                }
                $out[] = [$m[1] . ':' . $m[2] . (isset($m[3]) && $m[3] !== '' ? ' ' . $m[3] : ''), self::readings($h, $mi, $m[3] ?? '')];
            }
            $t = (string) preg_replace('/(?<!\d)(\d{1,2})[:.](\d{2})(?!\d)/u', ' ', $t);
        }
        if (preg_match_all('/(?<!\d)(\d{1,2})\s*(baje|bje|baja|vaje|बजे|बजेको|o\'?clock|am|pm)(?!\pL)/u', $t, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $m) {
                $h = (int) $m[1];
                if ($h > 23) {
                    continue;
                }
                $ap = in_array($m[2], ['am', 'pm'], true) ? $m[2] : '';
                $out[] = [$m[1] . ' ' . $m[2], self::readings($h, 0, $ap)];
            }
        }
        return $out;
    }

    /** @return list<int> */
    private static function readings(int $h, int $mi, string $ampm): array
    {
        if ($ampm === 'am') {
            return [($h % 12) * 60 + $mi];
        }
        if ($ampm === 'pm') {
            return [(($h % 12) + 12) * 60 + $mi];
        }
        return $h >= 1 && $h <= 12 ? array_values(array_unique([($h % 12) * 60 + $mi, (($h % 12) + 12) * 60 + $mi])) : [$h * 60 + $mi];
    }

    /** @return array<string, list<string>> lower-case boarding stop name => its HH:MM times on active routes */
    private static function stopTimes(): array
    {
        $out = [];
        try {
            $rows = Database::fetchAll(
                "SELECT rs.stop_name, rs.stop_time FROM route_stops rs JOIN routes r ON r.id = rs.route_id
                  WHERE r.is_active = 1 AND rs.stop_type = 'boarding' AND rs.stop_time IS NOT NULL" . Boarding::activeSql('rs')
            );
            foreach ($rows as $r) {
                $n = mb_strtolower(trim((string) $r['stop_name']));
                if (mb_strlen($n) >= 3) {
                    $out[$n][] = substr((string) $r['stop_time'], 0, 5);
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /* =================================================================
     *  The AI, for a question it was called for
     * ================================================================= */

    private static function askAi(string $question): ?string
    {
        if ($question === '') {
            return null;
        }
        try {
            require_once INCLUDE_PATH . '/aibrain.php';
            require_once INCLUDE_PATH . '/aiprompt.php';
            require_once INCLUDE_PATH . '/aimaster.php';
            if (!AiBrain::configured()) {
                return null;
            }
            $system = AiMaster::brief(['role' => 'customer']) . ai_system_prompt() . "\n\n"
                . "=== WHATSAPP GROUP RULES ===\n"
                . "You are replying INSIDE a team WhatsApp group: everyone in it reads your answer.\n"
                . "1. Public facts only — timetable, fares, seats left, offices, rules. Never a passenger's name, number, "
                . "booking, money, an agent's sales or anything private, even if asked; say that goes in a private chat with the office.\n"
                . "2. 1–3 short lines, in the language of the question. No markdown, no headings, no 👉 line.\n"
                . "3. If the briefing does not have it, say so in one line. Never guess.";
            $ans = AiBrain::text($system, [['role' => 'user', 'content' => mb_substr($question, 0, 600)]], 400, 'wa_group');
            $t = trim((string) ($ans['text'] ?? ''));
            return $t !== '' ? mb_substr($t, 0, 700) : null;
        } catch (Throwable $e) {
            Logger::warning('WaGroup AI failed: ' . $e->getMessage(), [], 'whatsapp');
            return null;
        }
    }

    /* =================================================================
     *  Sending to a group (Meta Cloud API, recipient_type group)
     * ================================================================= */

    /** @return array{success: bool, message_id?: string, error?: string} */
    public static function send(string $gid, string $text): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($gid, $text);
        }
        require_once ROOT_PATH . '/whatsapp/api.php';
        return waGraphPost($gid, ['type' => 'text', 'text' => ['preview_url' => false, 'body' => $text]], 'group', null, null, 2, 'group');
    }

    /**
     * Groups seen in the log (for the admin list), newest first.
     *
     * @return list<array{group_id: string, msgs: int, replies: int, last_at: string}>
     */
    public static function seen(int $days = 30): array
    {
        if (!self::ensure()) {
            return [];
        }
        return Database::fetchAll(
            'SELECT group_id, COUNT(*) AS msgs, SUM(replied) AS replies, MAX(created_at) AS last_at
               FROM wa_group_log WHERE created_at >= NOW() - INTERVAL :d DAY
              GROUP BY group_id ORDER BY last_at DESC LIMIT 50',
            ['d' => max(1, $days)]
        );
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 60): array
    {
        if (!self::ensure()) {
            return [];
        }
        return Database::fetchAll(
            'SELECT * FROM wa_group_log ORDER BY id DESC LIMIT ' . max(1, min(300, $limit))
        );
    }
}
