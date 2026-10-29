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

    /**
     * One row per sender in the last $days: last message, how many, unread.
     *
     * @return list<array{digits: string, last_at: string, msgs: int, last_text: string, unread: int, paused: bool}>
     */
    public static function threads(int $adminId, int $days = 7, int $limit = 80): array
    {
        $seen = self::seenId($adminId);
        $rows = Database::fetchAll(
            'SELECT from_digits, COUNT(*) AS msgs, MAX(id) AS last_id, MAX(created_at) AS last_at,
                    SUM(id > :seen) AS unread
               FROM wa_inbound
              WHERE created_at >= NOW() - INTERVAL :d DAY
              GROUP BY from_digits ORDER BY last_at DESC LIMIT ' . max(1, min(300, $limit)),
            ['seen' => $seen, 'd' => max(1, $days)]
        );
        if ($rows === []) {
            return [];
        }
        $lastIds = array_map(static fn(array $r): int => (int) $r['last_id'], $rows);
        $texts = [];
        foreach (Database::fetchAll('SELECT id, msg_type, payload FROM wa_inbound WHERE id IN (' . implode(',', $lastIds) . ')') as $m) {
            $texts[(int) $m['id']] = self::textOf((string) $m['msg_type'], (string) ($m['payload'] ?? ''));
        }
        $out = [];
        foreach ($rows as $r) {
            $d = (string) $r['from_digits'];
            $out[] = [
                'digits'    => $d,
                'last_at'   => (string) $r['last_at'],
                'msgs'      => (int) $r['msgs'],
                'last_text' => $texts[(int) $r['last_id']] ?? '',
                'unread'    => (int) $r['unread'],
                'paused'    => self::botPaused($d),
            ];
        }
        return $out;
    }

    /** Unread messages for this admin (the nav count). */
    public static function unread(int $adminId): int
    {
        try {
            return (int) Database::scalar(
                'SELECT COUNT(*) FROM wa_inbound WHERE id > :s AND created_at >= NOW() - INTERVAL 7 DAY',
                ['s' => self::seenId($adminId)], 0
            );
        } catch (Throwable $e) {
            return 0;
        }
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
     * @return list<array{dir: string, at: string, text: string, kind: string, status: string}>
     */
    public static function thread(string $phone, int $days = 7): array
    {
        $d = self::intl($phone);
        if ($d === '') {
            return [];
        }
        $items = [];
        foreach (Database::fetchAll(
            'SELECT msg_type, payload, status, created_at FROM wa_inbound
              WHERE from_digits = :d AND created_at >= NOW() - INTERVAL :n DAY ORDER BY id DESC LIMIT 120',
            ['d' => $d, 'n' => max(1, $days)]
        ) as $m) {
            $items[] = ['dir' => 'in', 'at' => (string) $m['created_at'], 'text' => self::textOf((string) $m['msg_type'], (string) ($m['payload'] ?? '')),
                        'kind' => (string) $m['msg_type'], 'status' => (string) $m['status']];
        }
        $sp = self::spellings($d);
        $ph = implode(',', array_fill(0, count($sp), '?'));
        foreach (Database::fetchAll(
            "SELECT body, purpose, status, created_at FROM message_logs
              WHERE channel = 'whatsapp' AND to_number IN ($ph) AND created_at >= NOW() - INTERVAL " . max(1, $days) . ' DAY
              ORDER BY id DESC LIMIT 120',
            $sp
        ) as $m) {
            $items[] = ['dir' => 'out', 'at' => (string) $m['created_at'], 'text' => (string) $m['body'],
                        'kind' => (string) ($m['purpose'] ?? ''), 'status' => (string) $m['status']];
        }
        usort($items, static fn(array $a, array $b): int => strcmp($a['at'], $b['at']));
        return $items;
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
                'digits' => (string) $r['from_digits'], 'role' => $role, 'name' => $s['name'] ?? '',
                'last_at' => (string) $r['last_at'], 'msgs' => (int) $r['msgs'],
                'optout' => isset($opt[$k]), 'bookings' => $booked[$k] ?? 0,
            ];
        }
        return $out;
    }
}
