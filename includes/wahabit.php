<?php
/**
 * =====================================================================
 *  WaHabit — the bot learns repeated work (9 Oct 2026).
 *
 *  Owner: "sabai logic manche jasto, repeated kaam le learn garos, sikos".
 *
 *  Every local answer (WaLocal) is noted per number: which question, at
 *  what hour. When the same person asks the same thing around the same
 *  hour on HABIT_DAYS different days, the bot offers once: "हरेक दिन यही
 *  समयमा आफैं पठाऊँ? हो / होइन". "हो" turns it on; cron/wa-habits.php then
 *  sends that answer every day at that hour — only FREE, on the Meta
 *  number inside the person's window (a closed window is skipped, never
 *  paid). "habit band" / "auto band" stops everything; "mero habit" lists.
 *
 *  Table wa_habits (database/upgrade-2026-10-09-wa-habits.sql). Silent
 *  when the table is missing. Switch: wa_habits_on.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaHabit
{
    public const HABIT_DAYS = 3;
    /** Questions worth repeating every day. */
    public const INTENTS = ['summary', 'passengers', 'pending', 'seats_left', 'bus_crew', 'bus_live'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            self::$ready = Settings::getBool('wa_habits_on', true) && Database::fetch("SHOW TABLES LIKE 'wa_habits'") !== null;
        } catch (Throwable $e) {
            self::$ready = false;
        }
        return self::$ready;
    }

    /**
     * Note one local answer. Returns the offer to append (line + buttons)
     * the moment a habit is seen for the HABIT_DAYS-th day, else null.
     *
     * @return array{line:string, ui:array}|null
     */
    public static function note(string $intl, string $role, string $intent, string $text, string $lang): ?array
    {
        if (!self::ready() || $intl === '' || !in_array($intent, self::INTENTS, true)) {
            return null;
        }
        try {
            $hour  = (int) date('G');
            $today = date('Y-m-d');
            $row   = Database::fetch('SELECT * FROM wa_habits WHERE phone = :p AND intent = :i LIMIT 1', ['p' => $intl, 'i' => $intent]);
            if ($row === null) {
                Database::insert('wa_habits', ['phone' => $intl, 'role' => $role, 'intent' => $intent, 'ask' => mb_substr($text, 0, 120),
                    'hour' => $hour, 'days_seen' => 1, 'last_day' => $today, 'status' => 'seen', 'lang' => $lang]);
                return null;
            }
            if ((string) $row['last_day'] === $today) {
                return null;                               // once a day counts
            }
            $near = abs($hour - (int) $row['hour']) <= 1 || abs($hour - (int) $row['hour']) >= 23;
            $days = $near ? (int) $row['days_seen'] + 1 : 1;
            Database::update('wa_habits', ['days_seen' => $days, 'last_day' => $today, 'hour' => $hour, 'ask' => mb_substr($text, 0, 120), 'lang' => $lang],
                'id = :i', ['i' => (int) $row['id']]);
            if ($days < self::HABIT_DAYS || (string) $row['status'] !== 'seen') {
                return null;
            }
            Database::update('wa_habits', ['status' => 'offered'], 'id = :i', ['i' => (int) $row['id']]);
            self::kv($intl, (string) (int) $row['id']);
            $when = date('g A', mktime($hour, 0));
            $line = self::t($lang,
                "\n\n🤖 तपाईं हरेक दिन यही बेला (" . $when . ") यो सोध्नुहुन्छ — भोलिदेखि आफैं पठाइदिऊँ? \"ho\" वा \"hoina\" लेख्नुहोस्।",
                "\n\n🤖 आप रोज़ इसी समय (" . $when . ") यह पूछते हैं — कल से अपने आप भेज दूँ? \"ho\" या \"hoina\" लिखें।",
                "\n\n🤖 You ask this every day around " . $when . " — shall I send it by myself from tomorrow? Reply \"yes\" or \"no\".");
            $ui = ['kind' => 'buttons', 'buttons' => [
                ['id' => 'shg:habit ho', 'title' => self::t($lang, '✅ हो, पठाऊ', '✅ हाँ, भेजो', '✅ Yes, send it')],
                ['id' => 'shg:habit hoina', 'title' => self::t($lang, '❌ होइन', '❌ नहीं', '❌ No')],
            ]];
            return ['line' => $line, 'ui' => $ui];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * The person's answer to an offer, or a habit command. Null = not ours.
     *
     * @return array{text:string, media:?string, ui:?array}|null
     */
    public static function handle(string $fromRaw, string $body): ?array
    {
        if (!self::ready()) {
            return null;
        }
        $intl = preg_replace('/\D/', '', $fromRaw) ?? '';
        $t    = mb_strtolower(trim(preg_replace('/[\s\p{P}]+/u', ' ', $body) ?? $body));
        if ($intl === '' || $t === '') {
            return null;
        }
        try {
            $lang = (string) Database::scalar('SELECT lang FROM wa_habits WHERE phone = :p ORDER BY id DESC LIMIT 1', ['p' => $intl], 'ne') ?: 'ne';
            if (preg_match('/^(habit (band|off|stop|list)|auto (band|off|stop)|mero habit|my habits?|habits?)$/u', $t) === 1) {
                if (str_contains($t, 'band') || str_contains($t, 'off') || str_contains($t, 'stop')) {
                    $n = (int) Database::run("UPDATE wa_habits SET status = 'off' WHERE phone = :p AND status IN ('on','offered')", ['p' => $intl]);
                    self::kv($intl, '');
                    return ['text' => self::t($lang, '✅ ठिक छ — अब केही पनि आफैं पठाइँदैन (' . $n . ' बन्द)।', '✅ ठीक है — अब कुछ भी अपने आप नहीं भेजा जाएगा (' . $n . ' बंद)।', '✅ Done — nothing is sent by itself any more (' . $n . ' off).'), 'media' => null, 'ui' => null];
                }
                $rows = Database::fetchAll("SELECT intent, hour, status, ask FROM wa_habits WHERE phone = :p AND status IN ('on','offered') ORDER BY hour", ['p' => $intl]);
                if ($rows === []) {
                    return ['text' => self::t($lang, 'अहिले कुनै दैनिक काम सेट छैन। एउटै कुरा ३ दिन सोध्नुभयो भने म आफैं प्रस्ताव गर्छु।', 'अभी कोई रोज़ का काम सेट नहीं है। एक ही बात 3 दिन पूछेंगे तो मैं खुद प्रस्ताव दूँगा।', 'No daily job is set. Ask the same thing on 3 days and I will offer one.'), 'media' => null, 'ui' => null];
                }
                $lines = [self::t($lang, '🗓 तपाईंका दैनिक काम:', '🗓 आपके रोज़ के काम:', '🗓 Your daily jobs:')];
                foreach ($rows as $r) {
                    $lines[] = '• ' . date('g A', mktime((int) $r['hour'], 0)) . ' — ' . (string) $r['ask'] . ' (' . ((string) $r['status'] === 'on' ? 'ON' : self::t($lang, 'जवाफ बाँकी', 'जवाब बाकी', 'awaiting your yes')) . ')';
                }
                $lines[] = self::t($lang, 'बन्द गर्न: "habit band"', 'बंद करने के लिए: "habit band"', 'To stop: "habit band"');
                return ['text' => implode("\n", $lines), 'media' => null, 'ui' => null];
            }

            $pending = (int) self::kv($intl);
            if ($pending <= 0) {
                return null;
            }
            $yes = preg_match('/^(habit )?(ho|haan|han|yes|y|ok|okay|thik|thik cha|hunxa|huncha|हो|हाँ|हां|ठिक छ|हुन्छ|ठीक)$/u', $t) === 1;
            $no  = preg_match('/^(habit )?(hoina|haina|no|n|nahi|nahin|na|holaina|होइन|नहीं|ना)$/u', $t) === 1;
            if (!$yes && !$no) {
                return null;                               // something else: the offer stays open
            }
            $row = Database::fetch('SELECT * FROM wa_habits WHERE id = :i AND phone = :p', ['i' => $pending, 'p' => $intl]);
            self::kv($intl, '');
            if ($row === null) {
                return null;
            }
            Database::update('wa_habits', ['status' => $yes ? 'on' : 'off'], 'id = :i', ['i' => $pending]);
            $when = date('g A', mktime((int) $row['hour'], 0));
            return ['text' => $yes
                ? self::t($lang, '✅ हुन्छ — हरेक दिन ' . $when . ' तिर "' . $row['ask'] . '" आफैं आउँछ (यो नम्बरले मलाई २४ घण्टाभित्र लेखेको हुनुपर्छ, free)। बन्द गर्न: "habit band"।',
                               '✅ ठीक — रोज़ ' . $when . ' के आसपास "' . $row['ask'] . '" अपने आप आएगा (इस नंबर ने मुझे 24 घंटे में लिखा हो, free)। बंद: "habit band"।',
                               '✅ OK — every day around ' . $when . ' "' . $row['ask'] . '" comes by itself (free, while this number wrote to me within 24 h). Stop: "habit band".')
                : self::t($lang, 'ठिक छ, आफैं पठाउँदिनँ। सोध्दा जहिले पनि दिन्छु।', 'ठीक है, अपने आप नहीं भेजूँगा। पूछने पर हमेशा दूँगा।', 'OK, I will not send it by myself. Ask any time.'),
                'media' => null, 'ui' => null];
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Cron: send every ON habit whose hour has come today — free only.
     *
     * @return array{due:int, sent:int, skipped:int}
     */
    public static function run(?int $now = null): array
    {
        $out = ['due' => 0, 'sent' => 0, 'skipped' => 0];
        if (!self::ready()) {
            return $out;
        }
        $now   = $now ?? time();
        $hour  = (int) date('G', $now);
        $today = date('Y-m-d', $now);
        require_once INCLUDE_PATH . '/waroute.php';
        require_once INCLUDE_PATH . '/walocal.php';
        require_once INCLUDE_PATH . '/aitools.php';
        foreach (Database::fetchAll("SELECT * FROM wa_habits WHERE status = 'on' AND hour = :h AND (last_sent_day IS NULL OR last_sent_day < :d)", ['h' => $hour, 'd' => $today]) as $h) {
            $out['due']++;
            $intl = (string) $h['phone'];
            if (!WaRoute::metaWindowOpen($intl)) {
                $out['skipped']++;                       // never paid: waits for the person's next message
                Database::update('wa_habits', ['last_note' => 'window closed ' . date('H:i', $now)], 'id = :i', ['i' => (int) $h['id']]);
                continue;
            }
            try {
                $r = WaLocal::answer('+' . $intl, (string) $h['ask'], AiTools::whoIs('+' . $intl));
            } catch (Throwable $e) {
                $r = null;
            }
            if ($r === null || trim((string) $r['text']) === '') {
                $out['skipped']++;
                continue;
            }
            $head = self::t((string) ($h['lang'] ?? 'ne'), '🗓 दैनिक (आफैं): ', '🗓 रोज़ (अपने आप): ', '🗓 Daily (by itself): ') . (string) $h['ask'] . "\n";
            $sent = WaRoute::freeFirst($intl, $head . (string) $r['text'], $r['media'] ?? null, 'habit');
            Database::update('wa_habits', ['last_sent_day' => $today, 'last_note' => $sent !== null ? 'sent ' . date('H:i', $now) : 'failed ' . date('H:i', $now)], 'id = :i', ['i' => (int) $h['id']]);
            $sent !== null ? $out['sent']++ : $out['skipped']++;
        }
        return $out;
    }

    /* ----------------------------------------------------------------- */

    /** The open offer for a number (kv scope wa_habit); '' clears it. */
    private static function kv(string $intl, ?string $set = null): string
    {
        if ($set === null) {
            return (string) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_habit' AND kkey = :k", ['k' => $intl], '');
        }
        if ($set === '') {
            Database::run("DELETE FROM kv_store WHERE kscope = 'wa_habit' AND kkey = :k", ['k' => $intl]);
            return '';
        }
        Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_habit', :k, :v, 'habit')
                       ON DUPLICATE KEY UPDATE kvalue = :v2, updated_by = 'habit'", ['k' => $intl, 'v' => $set, 'v2' => $set]);
        return $set;
    }

    private static function t(string $lang, string $ne, string $hi, string $en): string
    {
        return match ($lang) { 'ne' => $ne, 'hi' => $hi, default => $en };
    }
}
