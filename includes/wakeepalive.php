<?php
/**
 * =====================================================================
 *  WaKeepAlive — keep the office numbers' free WhatsApp window open
 *  (14 Nov 2026).
 *
 *  Meta lets the bot send free text to a number only for 24 hours after
 *  that number last wrote to it. The office report (07:00, 13:00, 20:00)
 *  and the office alerts go to the owner's and the CEO's numbers for free
 *  inside that window; once it closes they wait in the outbox, and on
 *  14 Nov the CEO's 07:00 and 13:00 reports failed with 131047 ("more than
 *  24 h since the customer last wrote").
 *
 *  So, while the window is still OPEN and about to close (by default in
 *  the last 2 hours), the number gets ONE short free message with a
 *  "📊 Report" button. Tapping it (or any reply) opens a fresh 24 hours,
 *  and the tap also brings today's report. Nothing is paid: a closed
 *  window is never written to, and no template is used.
 *
 *  Who: OfficeReport::numbers() (admin_whatsapp, ceo_whatsapp and
 *  office_report_numbers). Once per window: the reminder is keyed on the
 *  number's last-inbound time, so a new reply means a new reminder later,
 *  never two for the same window. Quiet hours (22:00-07:00 IST by
 *  default) are skipped, so the message never wakes anyone; a window that
 *  closes at night is reminded before 22:00.
 *
 *  Settings: wa_keepalive_on (default on), wa_keepalive_before_min (120),
 *  wa_keepalive_quiet ("22-7").
 *  Run from the five-minute WhatsApp cron (WaQueue::checkStatuses).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaKeepAlive
{
    /** Tests replace the clock and the sender. */
    public static $now = null;
    /** @var callable|null fn(string $intl, string $text, array $ui): bool */
    public static $send = null;

    public static function enabled(): bool
    {
        return Settings::getBool('wa_keepalive_on', true);
    }

    private static function now(): int
    {
        return self::$now !== null ? (int) self::$now : time();
    }

    /** Minutes before the window closes at which the reminder may go. */
    public static function beforeMin(): int
    {
        return max(30, min(600, Settings::getInt('wa_keepalive_before_min', 120)));
    }

    /** Is this hour (IST, the app clock) inside the quiet hours? */
    public static function quiet(int $ts): bool
    {
        $raw = trim(Settings::getString('wa_keepalive_quiet', '22-7'));
        if (!preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $raw, $m)) {
            return false;
        }
        [$from, $to] = [(int) $m[1] % 24, (int) $m[2] % 24];
        $h = (int) date('G', $ts);
        return $from > $to ? ($h >= $from || $h < $to) : ($h >= $from && $h < $to);
    }

    /**
     * Should this number be reminded now? $lastIn = unix time of its last
     * message to the bot (0 = never). Returns the reason when not.
     */
    public static function due(int $lastIn, int $now, int $sentForWindow): string
    {
        if ($lastIn <= 0) {
            return 'never wrote - nothing free to keep open';
        }
        $closes = $lastIn + WaRoute::WINDOW_SEC;
        $left = $closes - $now;
        if ($left <= 0) {
            return 'window already closed - a free message cannot reach it';
        }
        if ($sentForWindow === $lastIn) {
            return 'already reminded in this window';
        }
        $before = self::beforeMin() * 60;
        if (self::quiet($now)) {
            return 'quiet hours';
        }
        /* Normally the last $before minutes. A window that closes inside the
           quiet hours is reminded at the last hour before they start, when
           the reminder could otherwise never be sent in time. */
        if ($left <= $before) {
            return '';
        }
        $t = $now + 3600;
        if ($t < $closes && self::quiet($t)) {
            for ($s = $t; $s < $closes; $s += 3600) {
                if (!self::quiet($s)) {
                    return 'not yet';           // the window outlives the night
                }
            }
            return '';
        }
        return 'not yet';
    }

    /** The message: short, Nepali first, one button. */
    public static function message(int $left): array
    {
        $h = intdiv(max(0, $left), 3600);
        $m = intdiv(max(0, $left) % 3600, 60);
        $when = $h > 0 ? $h . ' घण्टा ' . $m . ' मिनेट' : $m . ' मिनेट';
        $text = "🔔 S Hari AI: Report र alert free मा आइरहन WhatsApp को 24 घण्टे समय " . $when . " मा सकिँदैछ।\n"
              . "तलको 📊 Report थिच्नुहोस् (वा जे पनि लेख्नुहोस्) — अर्को 24 घण्टा खुल्छ।\n"
              . "Tap Report (or reply anything) to keep reports coming for free.";
        $ui = ['kind' => 'buttons', 'buttons' => [['id' => 'shg:report', 'title' => '📊 Report']]];
        return ['text' => $text, 'ui' => $ui];
    }

    /**
     * One pass over the office numbers.
     *
     * @return array{checked:int, sent:int, skipped:array<string,string>}
     */
    public static function run(bool $dry = false): array
    {
        $out = ['checked' => 0, 'sent' => 0, 'skipped' => []];
        if (!self::enabled()) {
            $out['skipped']['all'] = 'wa_keepalive_on is off';
            return $out;
        }
        require_once INCLUDE_PATH . '/waroute.php';
        require_once INCLUDE_PATH . '/officereport.php';
        $now = self::now();
        foreach (OfficeReport::numbers() as $raw) {
            $intl = WaRoute::intl($raw);
            if ($intl === '') {
                continue;
            }
            $out['checked']++;
            $lastIn = (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k", ['k' => $intl], 0);
            $sent   = (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_keepalive' AND kkey = :k", ['k' => $intl], 0);
            $why = self::due($lastIn, $now, $sent);
            $mask = maskPhone(substr($intl, -10));
            if ($why !== '') {
                $out['skipped'][$mask] = $why;
                continue;
            }
            if ($dry) {
                $out['skipped'][$mask] = 'due (dry run, not sent)';
                continue;
            }
            $msg = self::message($lastIn + WaRoute::WINDOW_SEC - $now);
            $ok = false;
            try {
                if (self::$send !== null) {
                    $ok = (bool) (self::$send)($intl, $msg['text'], $msg['ui']);
                } else {
                    require_once INCLUDE_PATH . '/waagentroute.php';
                    $ok = (bool) (WaAgentRoute::send($intl, $msg['text'], null, $msg['ui'], 'wa_keepalive', null)['success'] ?? false);
                }
            } catch (Throwable $e) {
                Logger::warning('WhatsApp keep-alive failed: ' . $e->getMessage(), ['to' => $mask], 'whatsapp');
            }
            // Recorded either way: a failed send is not retried every five minutes.
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_keepalive', :k, :v, 'cron')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = 'cron'",
                ['k' => $intl, 'v' => (string) $lastIn]
            );
            if ($ok) {
                $out['sent']++;
            } else {
                $out['skipped'][$mask] = 'send failed';
            }
        }
        return $out;
    }
}
