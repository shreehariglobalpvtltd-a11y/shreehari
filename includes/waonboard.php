<?php
/**
 * =====================================================================
 *  WaOnboard — a new agent's first 7 days on WhatsApp (29 Oct 2026)
 *
 *  Owner's "AI System Prompt (Opus 5.5 Max)" 5.2: one short lesson a day
 *  for the first week — today's sales, booking for a customer, seats,
 *  cancellations, the commission card, fixing a name, and finally the
 *  dashboard link. Every lesson teaches a phrase the bot REALLY answers
 *  (WaOrder / WaLocal / the assistant), so nothing it promises is empty.
 *
 *  WHO AND WHEN
 *  ------------
 *  Agent and counter logins (admins.role agent | counter, active) whose
 *  account is 0–13 days old. Lesson n is due on day n of the account; a
 *  lesson that could not go out is sent the next time it can, in order,
 *  at most one a day, and never once day 14 has passed.
 *
 *  FREE MESSAGES ONLY
 *  ------------------
 *  Through WaRoute::freeFirst(): only inside the 24-hour window the agent
 *  opened by writing to the office number. No paid template is spent on a
 *  lesson; an agent who has not written simply gets it once they do.
 *
 *  Switch: Settings wa_onboard_on (off). Runs from cron/wa-onboard.php.
 *  Sent lessons are kept in agent_lessons (self-creating).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaOnboard
{
    public const DAYS = 7;
    private const LAST_DAY = 14;
    private static bool $ready = false;

    /** Tests replace the send: fn(string $phone, string $text): bool */
    public static ?Closure $transport = null;

    public static function on(): bool
    {
        return Settings::getBool('wa_onboard_on', false);
    }

    public static function ensure(): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS agent_lessons (
                    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    admin_id  INT UNSIGNED NOT NULL,
                    day       TINYINT UNSIGNED NOT NULL,
                    sent_at   DATETIME NOT NULL,
                    UNIQUE KEY uq_admin_day (admin_id, day)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$ready = true;
        } catch (Throwable $e) {
            Logger::warning('agent_lessons unavailable: ' . $e->getMessage(), [], 'agent');
        }
        return self::$ready;
    }

    /** Lesson text for day 1..7 (Nepali first, the words they type in bold quotes). */
    public static function lesson(int $day, string $name = ''): string
    {
        $hi   = $name !== '' ? 'नमस्ते ' . $name . ' जी 🙏' : 'नमस्ते 🙏';
        $site = rtrim(appUrl(''), '/');
        // The office's "one complete message = ticket at once" switch decides what step 2 says.
        $instant = in_array(Settings::getString('wa_instant_sale', 'staff'), ['staff', 'all'], true);
        return match ($day) {
            1 => $hi . "\nS Hari Global मा स्वागत छ! दिन १/७ 📘\nआजको बिक्री हेर्न यहाँ लेख्नुहोस्: *मेरो बिक्री*\nआजको पैसा र कमिशन सँगै: *hisab*",
            2 => "दिन २/७ 📘 ग्राहकको टिकट\nएकै सन्देशमा: नाम, मोबाइल, पिकअप, मिति, सिट — जस्तै:\n*Ram Thapa 98XXXXXXXX Surat bholi 2 seat*\n"
                . ($instant ? 'सबै विवरण पूरा भए टिकट तुरुन्तै बन्छ र ग्राहकको WhatsApp मा जान्छ।' : 'सारांश आएपछि *ho* लेख्नुहोस्।'),
            3 => "दिन ३/७ 📘 सिट कति बाँकी?\nलेख्नुहोस्: *bholi kati seat* वा *कति सिट*\nबस चलेको ठाउँ: *bus kaha pugyo*",
            4 => "दिन ४/७ 📘 रद्द गर्नुपर्दा\nPNR (SHG-...) पठाउनुहोस् — टिकटको विवरण आउँछ। फिर्ता नियम: *refund rule*\nफिर्ता रकम अफिसले हातले मिलाउँछ।",
            5 => "दिन ५/७ 📘 तपाईंको कमाइ\n*hisab* — आजको बिक्री र कमिशन।\nयो महिनाको पूरा हिसाब र wallet: \"यो महिनाको कमिशन कति?\" भनेर सोध्नुहोस्।",
            6 => "दिन ६/७ 📘 नाम गलत भयो?\nटिकट आएकै बेला सही नाम लेखेर पठाउनुहोस्, वा PNR र सही नाम पठाउनुहोस् — जस्तै: *SHG-... naam Raam Bahadur*\nएउटा टिकटको नाम २ पटकसम्म मात्र सच्याउन मिल्छ।",
            7 => "दिन ७/७ 🎉 तपाईं तयार हुनुहुन्छ!\nतपाईंको dashboard: " . $site . "/team/\nकेही अड्कियो भने यहाँ *help* लेख्नुहोस् वा अफिसमा फोन: " . Settings::officePhone(),
            default => '',
        };
    }

    /**
     * Send what is due now. Returns counts for the cron log.
     *
     * @return array{agents: int, sent: int, waiting: int}
     */
    public static function run(?int $now = null): array
    {
        $stat = ['agents' => 0, 'sent' => 0, 'waiting' => 0];
        if (!self::on() || !self::ensure()) {
            return $stat;
        }
        $now ??= time();
        $agents = Database::fetchAll(
            "SELECT id, full_name, phone, created_at FROM admins
              WHERE role IN ('agent', 'counter') AND is_active = 1 AND phone IS NOT NULL AND phone <> ''
                AND created_at >= :since",
            ['since' => date('Y-m-d H:i:s', $now - self::LAST_DAY * 86400)]
        );
        foreach ($agents as $a) {
            $stat['agents']++;
            $day = self::nextDue((int) $a['id'], (string) $a['created_at'], $now);
            if ($day === null) {
                continue;
            }
            $first = trim(explode(' ', trim((string) $a['full_name']))[0] ?? '');
            if (self::send((string) $a['phone'], self::lesson($day, $first))) {
                Database::insertIgnore('agent_lessons', ['admin_id' => (int) $a['id'], 'day' => $day, 'sent_at' => date('Y-m-d H:i:s', $now)]);
                $stat['sent']++;
            } else {
                $stat['waiting']++;
            }
        }
        return $stat;
    }

    /**
     * The lesson to send now, or null: the lowest unsent day that has come
     * due, never two on the same calendar day, nothing after day 14.
     */
    public static function nextDue(int $adminId, string $createdAt, int $now): ?int
    {
        $start = strtotime(substr($createdAt, 0, 10) . ' 00:00:00');
        if ($start === false) {
            return null;
        }
        $accountDay = (int) floor(($now - $start) / 86400) + 1;          // day 1 = the day the account was made
        if ($accountDay < 1 || $accountDay > self::LAST_DAY) {
            return null;
        }
        $rows = Database::fetchAll('SELECT day, sent_at FROM agent_lessons WHERE admin_id = :a', ['a' => $adminId]);
        $sent = [];
        foreach ($rows as $r) {
            $sent[(int) $r['day']] = (string) $r['sent_at'];
            if (substr((string) $r['sent_at'], 0, 10) === date('Y-m-d', $now)) {
                return null;                                              // one lesson a day
            }
        }
        for ($d = 1; $d <= min(self::DAYS, $accountDay); $d++) {
            if (!isset($sent[$d])) {
                return $d;
            }
        }
        return null;
    }

    private static function send(string $phone, string $text): bool
    {
        if ($text === '') {
            return false;
        }
        if (self::$transport !== null) {
            return (bool) (self::$transport)($phone, $text);
        }
        require_once INCLUDE_PATH . '/waroute.php';
        $r = WaRoute::freeFirst($phone, $text, null, 'agent_lesson');
        return is_array($r) && !empty($r['success']);
    }
}
