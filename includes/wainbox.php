<?php
/**
 * =====================================================================
 *  WaInbox — the office's WhatsApp inbox (29 Oct 2026)
 *
 *  Owner's "AI System Prompt (Opus 5.5 Max)", section 6.3: every message
 *  people send us, grouped by sender, with what the bot answered beside
 *  it; a manual reply; the bot paused for one number while a person takes
 *  over; a contacts list (role, last message, opt-out); unread counts.
 *
 *  NOTHING NEW IS RECORDED HERE — it READS what the system already keeps:
 *    wa_inbound    every message that reached the Meta number (its text is
 *                  blanked 7 days after it was answered, so the inbox
 *                  shows the last week in full)
 *    message_logs  everything we sent, the bot's replies (purpose
 *                  bot_reply) among them; to_number is stored either as
 *                  international digits or as the bare 10 digits
 *  and adds two small things in kv_store:
 *    wa_bot_pause  <digits> => unix time the pause ends (the Meta webhook
 *                  skips the bot for that number until then)
 *    wa_inbox_seen <admin id> => the last inbound id that admin opened
 *
 *  16 Nov 2026 (SHG Network Master Upgrade v2.0, P6/P7) — the inbox is the
 *  office's conversation hub:
 *    wa_inbox_seen_t <admin id>:<digits> => last inbound id of THAT chat the
 *                  admin opened, so opening one chat no longer clears the
 *                  badges of the others ("Mark all read" still sets the
 *                  old per-admin id, which stays the floor)
 *    names()       who the number is: office / agent, the customer master,
 *                  a saved WhatsApp contact, or the lead passenger of their
 *                  latest booking — read in a few batched queries
 *    status        needed (an open help-desk ticket for the number) /
 *                  agent (the bot is paused: a person has it) / ai
 *    resolve()     resumes the bot and resolves that number's open tickets
 *    escalate()    opens a help-desk ticket (no message to the customer)
 *    responseStats() inbound -> first bot_reply / inbox_reply, per day
 *    channelTabs() WhatsApp | Messenger | Sent strip for the comms pages
 *    sent()        what went out on WhatsApp (message_logs), newest first
 *    verdict()     the office's 👍 / 👎 on one bot reply -> ai_feedback_log
 *  Nothing here sends a message except reply(), as before — and, only
 *  while wa_handoff_msg_on is ON (default OFF), handoffNotice(): the one
 *  fixed "a person from the office will help you now" line when the office
 *  pauses the bot with "tell the customer" ticked (P6-3b), in-window only.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaInbox
{
    public const PAUSE_MAX_HOURS = 72;

    /** International digits (country code first) for any way a number is written. */
    public static function intl(string $phone): string
    {
        require_once INCLUDE_PATH . '/waroute.php';
        return WaRoute::intl($phone);
    }

    /** The two spellings message_logs uses for one person. @return list<string> */
    private static function spellings(string $intl): array
    {
        $local = strlen($intl) > 10 ? substr($intl, -10) : $intl;
        return array_values(array_unique([$intl, $local]));
    }

    /* ---------------- bot pause ---------------- */

    public static function botPaused(string $phone): bool
    {
        return self::pausedUntil($phone) > time();
    }

    public static function pausedUntil(string $phone): int
    {
        $d = self::intl($phone);
        if ($d === '') {
            return 0;
        }
        try {
            return (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_bot_pause' AND kkey = :k", ['k' => $d], 0);
        } catch (Throwable $e) {
            return 0;                                         // no kv table: never paused
        }
    }

    /** Pause the bot for this number for $hours (0 = resume now). */
    public static function pause(string $phone, int $hours, int $adminId): void
    {
        $d = self::intl($phone);
        if ($d === '') {
            throw new RuntimeException('That is not a phone number.');
        }
        $hours = max(0, min(self::PAUSE_MAX_HOURS, $hours));
        if ($hours === 0) {
            Database::delete('kv_store', "kscope = 'wa_bot_pause' AND kkey = :k", ['k' => $d]);
        } else {
            Database::query(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_bot_pause', :k, :v, :u)
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = VALUES(updated_by)",
                ['k' => $d, 'v' => (string) (time() + $hours * 3600), 'u' => 'admin#' . $adminId]
            );
        }
        Logger::audit('wa.bot_pause', 'whatsapp', $d, null, ['hours' => $hours], ($hours > 0 ? 'Bot paused ' . $hours . ' h' : 'Bot resumed') . ' by admin #' . $adminId);
    }

    /* ---------------- threads ---------------- */

    /** Conversation states (P6-1d): label + chip class. */
    public const STATUS = [
        'needed' => ['एजेन्ट चाहियो · Agent needed', 'needed'],
        'agent'  => ['एजेन्टले हेर्दै · Agent handling', 'agent'],
        'ai'     => ['AI ले जवाफ दिँदै · AI', 'ai'],
    ];

    /** A search widens the list to this many days (P6-1e). */
    public const SEARCH_DAYS = 30;

    /**
     * One row per sender in the last $days: last message, how many, unread
     * (per conversation), who it is and its state. $q (optional) keeps the
     * senders whose number holds those digits or whose name holds the words;
     * a search looks back SEARCH_DAYS days.
     *
     * @return list<array{digits: string, last_at: string, msgs: int, last_text: string, unread: int, paused: bool,
     *                    name: string, role: string, status: string, tickets: int}>
     */
    public static function threads(int $adminId, int $days = 7, int $limit = 80, string $q = ''): array
    {
        $q       = trim(mb_substr($q, 0, 60));
        $qDigits = preg_replace('/\D/', '', $q) ?? '';
        $qWords  = mb_strtolower(trim(preg_replace('/[\d+\-()\s]+/u', ' ', $q) ?? ''));
        $search  = $q !== '';
        if ($search) {
            $days = max($days, self::SEARCH_DAYS);
        }
        $seen   = self::seenId($adminId);
        $params = ['seen' => $seen, 'd' => max(1, $days)];
        $where  = '';
        if ($search && $qWords === '' && strlen($qDigits) >= 3) {
            $where = ' AND from_digits LIKE :qd';          // digits only: let the database narrow it
            $params['qd'] = '%' . $qDigits . '%';
        }
        $rows = Database::fetchAll(
            'SELECT from_digits, COUNT(*) AS msgs, MAX(id) AS last_id, MAX(created_at) AS last_at,
                    SUM(id > :seen) AS unread
               FROM wa_inbound
              WHERE created_at >= NOW() - INTERVAL :d DAY' . $where . '
              GROUP BY from_digits ORDER BY last_at DESC LIMIT ' . ($search ? 300 : max(1, min(300, $limit))),
            $params
        );
        if ($rows === []) {
            return [];
        }
        $digits = array_map(static fn(array $r): string => (string) $r['from_digits'], $rows);
        $names  = self::names($digits);

        /* keep the senders whose resolved name holds the words or whose number holds the digits */
        if ($search && ($qWords !== '' || $qDigits !== '')) {
            $rows = array_values(array_filter($rows, static function (array $r) use ($names, $qWords, $qDigits): bool {
                $k = substr((string) $r['from_digits'], -10);
                $hay = mb_strtolower((string) ($names[$k]['name'] ?? ''));
                return ($qWords !== '' && $hay !== '' && str_contains($hay, $qWords))
                    || ($qDigits !== '' && str_contains((string) $r['from_digits'], $qDigits));
            }));
        }
        $rows = array_slice($rows, 0, max(1, min(300, $limit)));
        if ($rows === []) {
            return [];
        }

        $lastIds = array_map(static fn(array $r): int => (int) $r['last_id'], $rows);
        $texts = [];
        foreach (Database::fetchAll('SELECT id, msg_type, payload FROM wa_inbound WHERE id IN (' . implode(',', $lastIds) . ')') as $m) {
            $texts[(int) $m['id']] = self::textOf((string) $m['msg_type'], (string) ($m['payload'] ?? ''));
        }
        $keep    = array_map(static fn(array $r): string => (string) $r['from_digits'], $rows);
        $seenT   = self::threadSeenMap($adminId, $keep);
        $paused  = self::pausedMap($keep);
        $tickets = self::ticketMap($keep);

        $out = [];
        foreach ($rows as $r) {
            $d    = (string) $r['from_digits'];
            $k    = substr($d, -10);
            $last = (int) $r['last_id'];
            $own  = $seenT[$d] ?? 0;
            $unread = (int) $r['unread'];
            if ($own > $seen) {                      // this chat was opened after the per-admin floor
                $unread = $own >= $last ? 0 : (int) Database::scalar(
                    'SELECT COUNT(*) FROM wa_inbound WHERE from_digits = :d AND id > :s AND created_at >= NOW() - INTERVAL :n DAY',
                    ['d' => $d, 's' => $own, 'n' => max(1, $days)], 0
                );
            }
            $isPaused = ($paused[$d] ?? 0) > time();
            $open     = (int) ($tickets[$k] ?? 0);
            $out[] = [
                'digits'    => $d,
                'last_at'   => (string) $r['last_at'],
                'msgs'      => (int) $r['msgs'],
                'last_text' => $texts[$last] ?? '',
                'unread'    => $unread,
                'paused'    => $isPaused,
                'name'      => (string) ($names[$k]['name'] ?? ''),
                'role'      => (string) ($names[$k]['role'] ?? ''),
                'status'    => self::statusOf($isPaused, $open),
                'tickets'   => $open,
            ];
        }
        return $out;
    }

    /**
     * The state of one conversation. A paused bot means a person has it
     * (agent); else an open / in-progress help-desk ticket for the number
     * means a person is needed; else the bot is answering (ai).
     */
    public static function statusOf(bool $paused, int $openTickets): string
    {
        return $paused ? 'agent' : ($openTickets > 0 ? 'needed' : 'ai');
    }

    /** One conversation's state, read fresh (chat header). */
    public static function statusFor(string $phone): string
    {
        $d = self::intl($phone);
        if ($d === '') {
            return 'ai';
        }
        return self::statusOf(self::botPaused($d), (int) (self::ticketMap([$d])[substr($d, -10)] ?? 0));
    }

    /** Unread messages for this admin (the nav count), per conversation. */
    public static function unread(int $adminId): int
    {
        try {
            return array_sum(array_column(self::threads($adminId, 7, 300), 'unread'));
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * P6-1g — this admin opened this chat: its messages up to now are seen.
     * Only THIS conversation; the others keep their badges.
     */
    public static function markThreadSeen(int $adminId, string $phone): void
    {
        $d = self::intl($phone);
        if ($d === '' || $adminId <= 0) {
            return;
        }
        try {
            $max = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM wa_inbound WHERE from_digits = :d', ['d' => $d], 0);
            if ($max <= 0) {
                return;
            }
            Database::query(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbox_seen_t', :k, :v, 'inbox')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)",
                ['k' => $adminId . ':' . $d, 'v' => (string) $max]
            );
        } catch (Throwable $e) {
        }
    }

    /** @param list<string> $digits @return array<string,int> digits => last seen inbound id */
    private static function threadSeenMap(int $adminId, array $digits): array
    {
        if ($digits === [] || $adminId <= 0) {
            return [];
        }
        $keys = array_map(static fn(string $d): string => $adminId . ':' . $d, $digits);
        $out  = [];
        try {
            foreach (Database::fetchAll(
                "SELECT kkey, kvalue FROM kv_store WHERE kscope = 'wa_inbox_seen_t' AND kkey IN (" . implode(',', array_fill(0, count($keys), '?')) . ')',
                $keys
            ) as $r) {
                $out[substr((string) $r['kkey'], strlen($adminId . ':'))] = (int) $r['kvalue'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /** @param list<string> $digits @return array<string,int> digits => unix time the bot pause ends */
    private static function pausedMap(array $digits): array
    {
        if ($digits === []) {
            return [];
        }
        $out = [];
        try {
            foreach (Database::fetchAll(
                "SELECT kkey, kvalue FROM kv_store WHERE kscope = 'wa_bot_pause' AND kkey IN (" . implode(',', array_fill(0, count($digits), '?')) . ')',
                array_values($digits)
            ) as $r) {
                $out[(string) $r['kkey']] = (int) $r['kvalue'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * Open / in-progress help-desk tickets per number (support_tickets keeps
     * the international digits; matched on the last 10).
     *
     * @param list<string> $digits @return array<string,int> last-10 => count
     */
    private static function ticketMap(array $digits): array
    {
        $local = array_values(array_unique(array_filter(array_map(static fn(string $d): string => substr($d, -10), $digits))));
        if ($local === []) {
            return [];
        }
        $out = [];
        try {
            foreach (Database::fetchAll(
                "SELECT RIGHT(phone, 10) AS k, COUNT(*) AS n FROM support_tickets
                  WHERE status IN ('open', 'in_progress') AND RIGHT(phone, 10) IN (" . implode(',', array_fill(0, count($local), '?')) . ')
                  GROUP BY RIGHT(phone, 10)',
                $local
            ) as $r) {
                $out[(string) $r['k']] = (int) $r['n'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /* ---------------- who is it (P6-1b) ---------------- */

    /** @return array<string, array{role: string, name: string}> last-10 => office / agent member */
    private static function staffMap(): array
    {
        $staff = [];
        try {
            foreach (Database::fetchAll("SELECT phone, role, full_name FROM admins WHERE is_active = 1 AND phone IS NOT NULL AND phone <> ''") as $a) {
                $k = substr(preg_replace('/\D/', '', (string) $a['phone']) ?? '', -10);
                if ($k !== '') {
                    $staff[$k] = ['role' => (string) $a['role'], 'name' => (string) $a['full_name']];
                }
            }
        } catch (Throwable $e) {
        }
        return $staff;
    }

    /**
     * A display name for each number, in a few batched reads: an office /
     * agent member first, then the customer master (user_profiles), the
     * account (users), a saved WhatsApp contact (wa_contacts), and last the
     * lead passenger of the latest booking made under that number.
     *
     * @param list<string> $digits any spelling
     * @return array<string, array{name: string, role: string, source: string}> keyed by the last 10 digits
     */
    public static function names(array $digits): array
    {
        $local = array_values(array_unique(array_filter(array_map(
            static fn(string $d): string => substr(preg_replace('/\D/', '', $d) ?? '', -10), $digits
        ), static fn(string $k): bool => strlen($k) === 10)));
        if ($local === []) {
            return [];
        }
        $out = [];
        $put = static function (string $k, string $name, string $role, string $source) use (&$out): void {
            $name = trim($name);
            if ($name !== '' && !isset($out[$k])) {
                $out[$k] = ['name' => mb_substr($name, 0, 120), 'role' => $role, 'source' => $source];
            }
        };
        $staff = self::staffMap();
        foreach ($local as $k) {
            if (isset($staff[$k])) {
                $r = $staff[$k]['role'];
                $put($k, $staff[$k]['name'], in_array($r, ['manager', 'superadmin', 'admin'], true) ? 'admin'
                    : (in_array($r, ['agent', 'counter'], true) ? 'agent' : 'staff'), 'staff');
            }
        }
        foreach ([['user_profiles', 'full_name', 'phone', 'customer'], ['users', 'full_name', 'phone', 'account'],
                  ['wa_contacts', 'name', 'RIGHT(phone, 10)', 'contact']] as [$table, $col, $key, $src]) {
            $need = array_values(array_diff($local, array_keys($out)));
            if ($need === []) {
                break;
            }
            try {
                foreach (Database::fetchAll(
                    "SELECT $key AS k, $col AS n FROM $table WHERE $key IN (" . implode(',', array_fill(0, count($need), '?')) . ")
                       AND $col IS NOT NULL AND $col <> ''",
                    $need
                ) as $r) {
                    $put((string) $r['k'], (string) $r['n'], 'customer', $src);
                }
            } catch (Throwable $e) {
                // a table this server does not have: the next source answers
            }
        }
        $need = array_values(array_diff($local, array_keys($out)));
        if ($need !== []) {
            try {
                foreach (Database::fetchAll(
                    'SELECT b.contact_phone AS k,
                            (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS n
                       FROM bookings b
                      WHERE b.id IN (SELECT MAX(id) FROM bookings WHERE contact_phone IN (' . implode(',', array_fill(0, count($need), '?')) . ') GROUP BY contact_phone)',
                    $need
                ) as $r) {
                    $put((string) $r['k'], (string) ($r['n'] ?? ''), 'customer', 'booking');
                }
            } catch (Throwable $e) {
            }
        }
        return $out;
    }

    public static function markSeen(int $adminId): void
    {
        try {
            $max = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM wa_inbound', [], 0);
            Database::query(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbox_seen', :k, :v, 'inbox')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)",
                ['k' => (string) $adminId, 'v' => (string) $max]
            );
        } catch (Throwable $e) {
        }
    }

    private static function seenId(int $adminId): int
    {
        try {
            return (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_inbox_seen' AND kkey = :k", ['k' => (string) $adminId], 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * One person's conversation, oldest first: what they sent and what went to them.
     *
     * `id` is the wa_inbound id (in) or the message_logs id (out).
     *
     * @return list<array{id: int, dir: string, at: string, text: string, kind: string, status: string}>
     */
    public static function thread(string $phone, int $days = 7): array
    {
        $d = self::intl($phone);
        if ($d === '') {
            return [];
        }
        $items = [];
        foreach (Database::fetchAll(
            'SELECT id, msg_type, payload, status, created_at FROM wa_inbound
              WHERE from_digits = :d AND created_at >= NOW() - INTERVAL :n DAY ORDER BY id DESC LIMIT 120',
            ['d' => $d, 'n' => max(1, $days)]
        ) as $m) {
            $items[] = ['id' => (int) $m['id'], 'dir' => 'in', 'at' => (string) $m['created_at'], 'text' => self::textOf((string) $m['msg_type'], (string) ($m['payload'] ?? '')),
                        'kind' => (string) $m['msg_type'], 'status' => (string) $m['status']];
        }
        $sp = self::spellings($d);
        $ph = implode(',', array_fill(0, count($sp), '?'));
        foreach (Database::fetchAll(
            "SELECT id, body, purpose, status, created_at FROM message_logs
              WHERE channel = 'whatsapp' AND to_number IN ($ph) AND created_at >= NOW() - INTERVAL " . max(1, $days) . ' DAY
              ORDER BY id DESC LIMIT 120',
            $sp
        ) as $m) {
            $items[] = ['id' => (int) $m['id'], 'dir' => 'out', 'at' => (string) $m['created_at'], 'text' => (string) $m['body'],
                        'kind' => (string) ($m['purpose'] ?? ''), 'status' => (string) $m['status']];
        }
        // same second: what they wrote comes before what we answered
        usort($items, static fn(array $a, array $b): int => strcmp($a['at'], $b['at']) ?: (($a['dir'] === 'in' ? 0 : 1) <=> ($b['dir'] === 'in' ? 0 : 1)) ?: ($a['id'] <=> $b['id']));
        return $items;
    }

    /** The words of the last message this number sent (the "Suggest reply" / Escalate input). */
    public static function lastInbound(string $phone): string
    {
        $d = self::intl($phone);
        if ($d === '') {
            return '';
        }
        try {
            foreach (Database::fetchAll(
                'SELECT msg_type, payload FROM wa_inbound WHERE from_digits = :d ORDER BY id DESC LIMIT 5', ['d' => $d]
            ) as $m) {
                $t = trim(self::textOf((string) $m['msg_type'], (string) ($m['payload'] ?? '')));
                if ($t !== '' && !str_starts_with($t, '[')) {
                    return $t;
                }
            }
        } catch (Throwable $e) {
        }
        return '';
    }

    /** The words of one stored inbound message (text, button, list pick, caption). */
    public static function textOf(string $type, string $payload): string
    {
        if ($payload === '') {
            return $type === '' ? '' : '[' . $type . ']';
        }
        $m = json_decode($payload, true);
        if (!is_array($m)) {
            return '';
        }
        $t = match ($type) {
            'text'        => (string) ($m['text']['body'] ?? ''),
            'button'      => (string) ($m['button']['text'] ?? ''),
            'interactive' => (string) ($m['interactive']['button_reply']['title'] ?? ($m['interactive']['list_reply']['title'] ?? '[form]')),
            default       => trim('[' . $type . '] ' . (string) ($m[$type]['caption'] ?? '')),
        };
        return mb_substr($t, 0, 1000);
    }

    /* ---------------- reply ---------------- */

    /**
     * A person from the office answers. Free text works only inside the
     * 24-hour window that opened when they last wrote (Meta's rule) — no
     * paid template is ever spent from here.
     *
     * @return array{ok: bool, why: string}
     */
    public static function reply(string $phone, string $text, int $adminId): array
    {
        $d = self::intl($phone);
        $text = trim($text);
        if ($d === '' || $text === '') {
            return ['ok' => false, 'why' => 'Write a message first.'];
        }
        if (mb_strlen($text) > 1000) {
            return ['ok' => false, 'why' => 'Keep it under 1000 characters.'];
        }
        require_once INCLUDE_PATH . '/waroute.php';
        if (!WaRoute::metaWindowOpen($d)) {
            return ['ok' => false, 'why' => 'Their 24-hour WhatsApp window is closed (they have not written in the last day). Call them, or wait until they write again.'];
        }
        $r = WaRoute::freeFirst($d, $text, null, 'inbox_reply', ['admin_id' => $adminId]);
        $ok = is_array($r) && !empty($r['success'] ?? ($r['ok'] ?? false));
        Logger::audit('wa.inbox_reply', 'whatsapp', $d, null, ['chars' => mb_strlen($text), 'ok' => $ok], 'Inbox reply by admin #' . $adminId);
        return $ok ? ['ok' => true, 'why' => ''] : ['ok' => false, 'why' => 'WhatsApp did not accept it — see WhatsApp Manager for the reason.'];
    }

    /* ---------------- hand-off line (P6-3b) ---------------- */

    /** The one fixed line a customer gets when a person takes the chat over. Nepali first. */
    public const HANDOFF_TEXT = "Namaste, S Hari office bata ek jana le aba tapai lai sahayog garnuhunchha.\nA person from our office will help you now.";

    /** Switch wa_handoff_msg_on (default OFF): may "Pause bot" offer to tell the customer? */
    public static function handoffOn(): bool
    {
        try {
            return Settings::getBool('wa_handoff_msg_on', false);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * P6-3b (16 Nov 2026): the office paused the bot with "tell the customer"
     * ticked — ONE fixed line (HANDOFF_TEXT), free, ONLY inside the Meta 24 h
     * window, on the same path as reply(); audited like inbox_reply. Never a
     * template, never typed by anyone, nothing while the switch is off. A second
     * tick within 10 minutes is not sent again.
     *
     * @return array{sent: bool, why: string}
     */
    public static function handoffNotice(string $phone, int $adminId): array
    {
        if (!self::handoffOn()) {
            return ['sent' => false, 'why' => 'The hand-off message is switched off (wa_handoff_msg_on).'];
        }
        $d = self::intl($phone);
        if ($d === '') {
            return ['sent' => false, 'why' => 'That is not a phone number.'];
        }
        require_once INCLUDE_PATH . '/waroute.php';
        if (!WaRoute::metaWindowOpen($d)) {
            return ['sent' => false, 'why' => 'Their 24-hour WhatsApp window is closed, so the customer was not told.'];
        }
        try {
            if (!Security::rateLimit('wa_inbox_handoff', $d, 1, 600)) {
                return ['sent' => false, 'why' => 'They were told a few minutes ago.'];
            }
        } catch (Throwable $ignored) {
            // no rate-limit table: still one line per click
        }
        $r = WaRoute::freeFirst($d, self::HANDOFF_TEXT, null, 'inbox_handoff', ['admin_id' => $adminId]);
        $ok = is_array($r) && !empty($r['success'] ?? ($r['ok'] ?? false));
        if (!$ok) {
            try {   // nothing went, so the next tick may try again (never "they were told" after a failure)
                Database::delete('rate_limits', 'bucket = :b AND identifier = :i', ['b' => 'wa_inbox_handoff', 'i' => $d]);
            } catch (Throwable $ignored) {
            }
        }
        Logger::audit('wa.inbox_handoff', 'whatsapp', $d, null, ['chars' => mb_strlen(self::HANDOFF_TEXT), 'ok' => $ok], 'Hand-off line by admin #' . $adminId);
        return $ok ? ['sent' => true, 'why' => ''] : ['sent' => false, 'why' => 'WhatsApp did not accept the hand-off line — see WhatsApp Manager for the reason.'];
    }

    /* ---------------- contacts ---------------- */

    /**
     * Everyone who wrote in the last $days: role, last message, opt-out.
     *
     * @return list<array{digits: string, role: string, name: string, last_at: string, msgs: int, optout: bool, bookings: int}>
     */
    public static function contacts(int $days = 30, int $limit = 200): array
    {
        $rows = Database::fetchAll(
            'SELECT from_digits, COUNT(*) AS msgs, MAX(created_at) AS last_at FROM wa_inbound
              WHERE created_at >= NOW() - INTERVAL :d DAY GROUP BY from_digits ORDER BY last_at DESC LIMIT ' . max(1, min(500, $limit)),
            ['d' => max(1, $days)]
        );
        if ($rows === []) {
            return [];
        }
        $local = array_map(static fn(array $r): string => substr((string) $r['from_digits'], -10), $rows);
        $ph = implode(',', array_fill(0, count($local), '?'));

        $staff = self::staffMap();
        $names = self::names(array_map(static fn(array $r): string => (string) $r['from_digits'], $rows));   // P6-1b: passengers too
        $booked = [];
        try {
            foreach (Database::fetchAll("SELECT contact_phone, COUNT(*) AS n FROM bookings WHERE contact_phone IN ($ph) GROUP BY contact_phone", $local) as $b) {
                $booked[(string) $b['contact_phone']] = (int) $b['n'];
            }
        } catch (Throwable $e) {
        }
        $opt = [];
        try {
            foreach (Database::fetchAll("SELECT phone FROM mk_optout WHERE RIGHT(phone, 10) IN ($ph)", $local) as $o) {
                $opt[substr((string) $o['phone'], -10)] = true;
            }
        } catch (Throwable $e) {
        }

        $out = [];
        foreach ($rows as $r) {
            $k = substr((string) $r['from_digits'], -10);
            $s = $staff[$k] ?? null;
            $role = $s !== null
                ? (in_array($s['role'], ['manager', 'superadmin', 'admin'], true) ? 'admin' : (in_array($s['role'], ['agent', 'counter'], true) ? 'agent' : 'staff'))
                : (isset($booked[$k]) ? 'customer' : 'unknown');
            $out[] = [
                'digits' => (string) $r['from_digits'], 'role' => $role, 'name' => $s['name'] ?? ($names[$k]['name'] ?? ''),
                'last_at' => (string) $r['last_at'], 'msgs' => (int) $r['msgs'],
                'optout' => isset($opt[$k]), 'bookings' => $booked[$k] ?? 0,
            ];
        }
        return $out;
    }

    /* ---------------- desk actions (P6-1d, P6-9c) ---------------- */

    /**
     * "Resolved": the bot answers this number again and its open help-desk
     * tickets are resolved — each audited. Sends nothing to anyone.
     *
     * @return array{resumed: bool, tickets: list<string>}
     */
    public static function resolve(string $phone, int $adminId): array
    {
        $d = self::intl($phone);
        if ($d === '') {
            throw new RuntimeException('That is not a phone number.');
        }
        $wasPaused = self::botPaused($d);
        if ($wasPaused) {
            self::pause($d, 0, $adminId);                       // audited wa.bot_pause
        }
        $refs = [];
        try {
            $open = Database::fetchAll(
                "SELECT id, ticket_ref, status, assigned_to FROM support_tickets
                  WHERE status IN ('open', 'in_progress') AND RIGHT(phone, 10) = :k ORDER BY id",
                ['k' => substr($d, -10)]
            );
        } catch (Throwable $e) {
            $open = [];                                          // no help desk on this server
        }
        foreach ($open as $t) {
            $set = ['status' => 'resolved', 'resolved_at' => date('Y-m-d H:i:s')];
            if (empty($t['assigned_to']) && $adminId > 0) {
                $set['assigned_to'] = $adminId;
            }
            try {
                Database::update('support_tickets', $set, 'id = :id', ['id' => (int) $t['id']]);
            } catch (Throwable $e) {
                unset($set['assigned_to']);                      // a stale admin id must not block the resolve
                Database::update('support_tickets', $set, 'id = :id', ['id' => (int) $t['id']]);
            }
            Logger::audit('support.status', 'support_ticket', (string) $t['ticket_ref'], ['status' => (string) $t['status']],
                ['status' => 'resolved'], 'resolved from the WhatsApp inbox by admin #' . $adminId);
            $refs[] = (string) $t['ticket_ref'];
        }
        Logger::audit('wa.inbox_resolved', 'whatsapp', $d, ['paused' => $wasPaused, 'open_tickets' => count($open)],
            ['paused' => false, 'open_tickets' => 0, 'resolved' => $refs], 'Conversation resolved by admin #' . $adminId);
        return ['resumed' => $wasPaused, 'tickets' => $refs];
    }

    /**
     * "Escalate": a help-desk ticket for this number with their last message,
     * typed by the office (trusted). The customer is NOT messaged (ack off);
     * the desk's own rules (auto-assign, the office alert switch) apply.
     *
     * @return array{ok: bool, ref: string, duplicate: bool, why: string}
     */
    public static function escalate(string $phone, int $adminId, string $name = '', string $note = ''): array
    {
        $d = self::intl($phone);
        if ($d === '') {
            return ['ok' => false, 'ref' => '', 'duplicate' => false, 'why' => 'That is not a phone number.'];
        }
        require_once INCLUDE_PATH . '/helpdesk.php';
        $last = self::lastInbound($d);
        $msg  = trim('WhatsApp: ' . ($last !== '' ? $last : '(no text)') . ($note !== '' ? "\nOffice: " . $note : ''));
        $r = HelpDesk::open([
            'name'       => $name,
            'phone'      => $d,
            'country'    => str_starts_with($d, '977') ? 'NP' : 'IN',
            'message'    => mb_substr($msg, 0, 2000),
            'source'     => 'desk',
            'trusted'    => true,
            'created_by' => $adminId,
            'ack'        => false,
            'lang'       => 'ne',
        ]);
        $ok = !empty($r['ok']);
        Logger::audit('wa.inbox_escalate', 'whatsapp', $d, null, ['ticket' => (string) ($r['ref'] ?? ''), 'ok' => $ok,
            'duplicate' => !empty($r['duplicate'])], 'Escalated from the WhatsApp inbox by admin #' . $adminId);
        return ['ok' => $ok, 'ref' => (string) ($r['ref'] ?? ''), 'duplicate' => !empty($r['duplicate']),
                'why' => $ok ? '' : 'The help desk did not take it (' . (string) ($r['error'] ?? 'error') . ').'];
    }

    /* ---------------- response time (P6-10a) ---------------- */

    /**
     * For every message that reached us on $ymd: how long until the FIRST
     * bot_reply (AI) or inbox_reply (agent) that reached that number, within
     * a day. Unanswered messages are counted apart, never as 0 s.
     *
     * @return array{ai_n: int, agent_n: int, ai_avg_s: ?float, agent_avg_s: ?float, ai_within_30s_pct: ?float,
     *               agent_within_5m_pct: ?float, inbound_n: int, unanswered_n: int}
     */
    public static function responseStats(string $ymd): array
    {
        $out = ['ai_n' => 0, 'agent_n' => 0, 'ai_avg_s' => null, 'agent_avg_s' => null, 'ai_within_30s_pct' => null,
                'agent_within_5m_pct' => null, 'inbound_n' => 0, 'unanswered_n' => 0];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) !== 1 || strtotime($ymd) === false) {
            return $out;
        }
        $from = $ymd . ' 00:00:00';
        $to   = date('Y-m-d', (int) strtotime($ymd . ' +1 day')) . ' 00:00:00';
        $till = date('Y-m-d', (int) strtotime($ymd . ' +2 day')) . ' 00:00:00';
        try {
            $in = Database::fetchAll(
                'SELECT from_digits, created_at FROM wa_inbound WHERE created_at >= :f AND created_at < :t ORDER BY id LIMIT 5000',
                ['f' => $from, 't' => $to]
            );
            if ($in === []) {
                return $out;
            }
            $replies = [];
            foreach (Database::fetchAll(
                "SELECT to_number, purpose, created_at FROM message_logs
                  WHERE channel = 'whatsapp' AND status = 'sent' AND purpose IN ('bot_reply', 'inbox_reply')
                    AND created_at >= :f AND created_at < :t ORDER BY created_at, id LIMIT 20000",
                ['f' => $from, 't' => $till]
            ) as $m) {
                $k = substr(preg_replace('/\D/', '', (string) $m['to_number']) ?? '', -10);
                $replies[$k][] = [(int) strtotime((string) $m['created_at']), (string) $m['purpose']];
            }
        } catch (Throwable $e) {
            return $out;
        }
        $ai = [];
        $agent = [];
        foreach ($in as $r) {
            $k  = substr((string) $r['from_digits'], -10);
            $at = (int) strtotime((string) $r['created_at']);
            $hit = null;
            foreach ($replies[$k] ?? [] as [$ts, $purpose]) {
                if ($ts >= $at) {
                    $hit = [$ts - $at, $purpose];
                    break;
                }
            }
            if ($hit === null || $hit[0] > 86400) {
                $out['unanswered_n']++;
                continue;
            }
            if ($hit[1] === 'inbox_reply') {
                $agent[] = $hit[0];
            } else {
                $ai[] = $hit[0];
            }
        }
        $out['inbound_n'] = count($in);
        $out['ai_n']      = count($ai);
        $out['agent_n']   = count($agent);
        if ($ai !== []) {
            $out['ai_avg_s']          = round(array_sum($ai) / count($ai), 1);
            $out['ai_within_30s_pct'] = round(100 * count(array_filter($ai, static fn(int $s): bool => $s <= 30)) / count($ai), 1);
        }
        if ($agent !== []) {
            $out['agent_avg_s']         = round(array_sum($agent) / count($agent), 1);
            $out['agent_within_5m_pct'] = round(100 * count(array_filter($agent, static fn(int $s): bool => $s <= 300)) / count($agent), 1);
        }
        return $out;
    }

    /* ---------------- the comms hub (P6-9a) ---------------- */

    /**
     * The channel strip every communications page shows on top:
     * WhatsApp | Messenger | Sent. $active: whatsapp, messenger or sent.
     * Messenger appears once admin/messenger-inbox.php exists; Sent needs
     * the same permission as the outbound log (dashboard.view).
     */
    public static function channelTabs(string $active): string
    {
        static $css = false;
        $tabs = ['whatsapp' => ['wa-inbox.php', '💬 WhatsApp']];
        if (is_file(ROOT_PATH . '/admin/messenger-inbox.php')) {
            $tabs['messenger'] = ['messenger-inbox.php', '📘 Messenger'];
        }
        if (!class_exists('Auth') || Auth::can('dashboard.view')) {
            $tabs['sent'] = ['wa-inbox.php?tab=sent', '📤 पठाइएको · Sent'];
        }
        $h = '';
        if (!$css) {
            $css = true;
            $h .= '<style>.wi-chan{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px;padding:4px;border:1px solid var(--line);border-radius:14px;background:var(--card);width:max-content;max-width:100%}'
                . '.wi-chan a{padding:7px 14px;border-radius:10px;font-weight:700;font-size:13.5px;text-decoration:none;color:inherit;white-space:nowrap}'
                . '.wi-chan a.on{background:var(--ink,#0b1220);color:var(--card,#fff)}</style>';
        }
        $h .= '<nav class="wi-chan" aria-label="Channels">';
        foreach ($tabs as $k => [$href, $label]) {
            $h .= '<a href="' . Security::e($href) . '"' . ($k === $active ? ' class="on" aria-current="page"' : '') . '>' . Security::e($label) . '</a>';
        }
        return $h . '</nav>';
    }

    /**
     * What went out on WhatsApp, newest first ("Sent" tab). One-time codes
     * are masked; $q narrows by number digits or words in the message.
     *
     * @return list<array{id: int, to: string, purpose: string, status: string, body: string, error: string, at: string}>
     */
    public static function sent(int $days = 7, string $q = '', int $limit = 150): array
    {
        $q = trim(mb_substr($q, 0, 60));
        $digits = preg_replace('/\D/', '', $q) ?? '';
        $where = "channel = 'whatsapp' AND created_at >= NOW() - INTERVAL :d DAY";
        $p = ['d' => max(1, min(90, $days))];
        if ($q !== '') {
            $where .= strlen($digits) >= 3 ? ' AND (to_number LIKE :q1 OR body LIKE :q2)' : ' AND body LIKE :q2';
            if (strlen($digits) >= 3) {
                $p['q1'] = '%' . $digits . '%';
            }
            $p['q2'] = '%' . $q . '%';
        }
        $out = [];
        try {
            require_once INCLUDE_PATH . '/wasend.php';         // the sender's own secret rules (WaSend::SECRET / isSecret)
            foreach (Database::fetchAll(
                "SELECT id, to_number, purpose, status, body, error, created_at FROM message_logs WHERE $where ORDER BY id DESC LIMIT " . max(1, min(500, $limit)),
                $p
            ) as $m) {
                $purpose = (string) ($m['purpose'] ?? '');
                $body = (string) ($m['body'] ?? '');
                if (in_array($purpose, WaSend::SECRET, true) || preg_match('/credential|password/i', $purpose) === 1) {
                    $body = WaSend::SCRUBBED;                   // e.g. team_credentials: a password is not only digits
                } elseif (preg_match('/otp|code|verify|pin/i', $purpose) === 1 || WaSend::isSecret($purpose, $body)) {
                    $body = WaSend::maskSecrets(preg_replace('/\d{4,8}/u', '••••', $body) ?? '');
                }
                $out[] = ['id' => (int) $m['id'], 'to' => (string) $m['to_number'], 'purpose' => $purpose, 'status' => (string) $m['status'],
                          'body' => $body, 'error' => (string) ($m['error'] ?? ''), 'at' => (string) $m['created_at']];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /* ---------------- 👍 / 👎 on a bot reply (P7-9b) ---------------- */

    /**
     * 0 = no ai_feedback_log table; 1 = the table as live has it; 2 = with
     * the msg_id / admin_id columns (database/upgrade-2026-11-16-ai-feedback.sql).
     */
    public static function feedbackReady(): int
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        try {
            $cols = array_map(static fn(array $r): string => (string) $r['Field'], Database::fetchAll('SHOW COLUMNS FROM ai_feedback_log'));
        } catch (Throwable $e) {
            return $ready = 0;
        }
        return $ready = (in_array('msg_id', $cols, true) && in_array('admin_id', $cols, true)) ? 2 : 1;
    }

    /**
     * The office's verdict on ONE bot reply in this conversation. The reply
     * must be a bot_reply sent to this number; the question it answered is
     * the last message they wrote before it. Stored redacted, the number only
     * as a hash. A second vote by the same person on the same reply replaces
     * the first (when the msg_id column exists). Audited.
     *
     * @return array{ok: bool, why: string}
     */
    public static function verdict(string $phone, int $msgId, string $verdict, string $note, int $adminId): array
    {
        $d = self::intl($phone);
        if ($d === '' || $msgId <= 0 || !in_array($verdict, ['up', 'down'], true)) {
            return ['ok' => false, 'why' => 'Choose 👍 or 👎 on a bot reply.'];
        }
        $ready = self::feedbackReady();
        if ($ready === 0) {
            return ['ok' => false, 'why' => 'Feedback is not installed on this server (database/upgrade-2026-11-16-ai-feedback.sql).'];
        }
        $sp = self::spellings($d);
        $m = Database::fetch(
            "SELECT id, body, created_at FROM message_logs WHERE id = ? AND channel = 'whatsapp' AND purpose = 'bot_reply'
                AND to_number IN (" . implode(',', array_fill(0, count($sp), '?')) . ')',
            array_merge([$msgId], $sp)
        );
        if ($m === null) {
            return ['ok' => false, 'why' => 'That is not a bot reply in this conversation.'];
        }
        $asked = '';
        foreach (Database::fetchAll(
            'SELECT msg_type, payload FROM wa_inbound WHERE from_digits = :d AND created_at <= :t ORDER BY id DESC LIMIT 3',
            ['d' => $d, 't' => (string) $m['created_at']]
        ) as $in) {
            $asked = trim(self::textOf((string) $in['msg_type'], (string) ($in['payload'] ?? '')));
            if ($asked !== '') {
                break;
            }
        }
        require_once INCLUDE_PATH . '/aiknowledge.php';
        $note = trim(mb_substr(Security::clean($note, 500), 0, 500));
        $row = [
            'owner_key'  => hash('sha256', $d),
            'channel'    => 'whatsapp',
            'verdict'    => $verdict,
            'user_text'  => $asked !== '' ? mb_substr(AiKb::redact($asked), 0, 2000) : null,
            'reply_text' => mb_substr((string) $m['body'], 0, 4000),
            'note'       => $note !== '' ? AiKb::redact($note) : null,
        ];
        $old = null;
        if ($ready === 2) {
            $row['msg_id']   = $msgId;
            $row['admin_id'] = $adminId > 0 ? $adminId : null;
            $prev = Database::fetch('SELECT id, verdict FROM ai_feedback_log WHERE msg_id = :m AND admin_id <=> :a ORDER BY id DESC LIMIT 1',
                ['m' => $msgId, 'a' => $row['admin_id']]);
            if ($prev !== null) {
                $old = ['verdict' => (string) $prev['verdict']];
                Database::update('ai_feedback_log', ['verdict' => $verdict, 'note' => $row['note'], 'user_text' => $row['user_text']],
                    'id = :id', ['id' => (int) $prev['id']]);
            }
        }
        if ($old === null) {
            Database::insert('ai_feedback_log', $row);
        }
        Logger::audit('ai.feedback', 'message_log', (string) $msgId, $old, ['verdict' => $verdict, 'note' => $row['note'] !== null],
            'Bot reply marked ' . ($verdict === 'up' ? '👍' : '👎') . ' by admin #' . $adminId);
        return ['ok' => true, 'why' => ''];
    }

    /**
     * This admin's verdicts on these replies (needs the msg_id column).
     *
     * @param list<int> $msgIds @return array<int,string> msg id => up|down
     */
    public static function verdicts(array $msgIds, int $adminId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $msgIds), static fn(int $i): bool => $i > 0)));
        if ($ids === [] || self::feedbackReady() !== 2) {
            return [];
        }
        $out = [];
        try {
            foreach (Database::fetchAll(
                'SELECT msg_id, verdict FROM ai_feedback_log WHERE admin_id = ? AND msg_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id',
                array_merge([$adminId], $ids)
            ) as $r) {
                $out[(int) $r['msg_id']] = (string) $r['verdict'];
            }
        } catch (Throwable $e) {
        }
        return $out;
    }
}
