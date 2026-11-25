<?php
/**
 * =====================================================================
 *  includes/wahealth.php — what Meta's refusals MEAN for our sending
 *  (25 Nov 2026, mp4; owner: "meta suspition kine hok yr ticket aautio
 *  ko mildeu ytei anusar").
 *
 *  The live diagnosis (read-only, 25 Nov) found three Meta-side causes on
 *  +91 87358 81507 - none of them our language code:
 *
 *    131070 "Caller is not thread owner"  - another app (the old AiSensy
 *           set-up / Meta Business Agent) owns the chat threads. Code cannot
 *           take control (only the escalation partner may). A number that
 *           answered 131070 gets no FREE message from us for
 *           wa_thread_block_hours (default 12): the template / SMS / e-mail /
 *           wa.me link carry it instead of a refusal on every try.
 *    131042 "payment method problem"      - no working payment method on the
 *           WABA. Every paid (template) message fails at delivery. While one
 *           came back in the last wa_billing_hold_hours (default 12) and Meta
 *           has not since shown a billable delivery, ALL template sends are
 *           held (WaRoute::paidRefusal) - no paid attempt that is sure to
 *           fail. The admin page shows it in red ("Meta billing missing").
 *    132001 "template does not exist"     - per template (WaTplMeta::noteRefused
 *           already skips it for a few hours and the ticket rescue uses an
 *           older template that delivers).
 *
 *  These three are PERMANENT for a ticket: cron/whatsapp-retry.php no longer
 *  resends a booking whose newest WhatsApp failed with one of them (it used
 *  to loop: SHG-2026-00958 more than 8 times) - the office gets ONE panel
 *  alert per booking with the wa.me link and the ticket link instead.
 *
 *  "Meta ठीक भयो · Meta fixed" (admin/wa-templates.php, super-admin) =
 *  markFixed(): the holds and number blocks are cleared and the parked
 *  tickets get one more try on the next retry run.
 *
 *  Switches: wa_meta_health_on (1), wa_billing_hold_on (1), wa_thread_block_on (1).
 *  Nothing here sends a message. Never throws to its callers.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaHealth
{
    public const SCOPE = 'wa_health';

    public const THREAD   = 131070;
    public const BILLING  = 131042;
    public const TEMPLATE = 132001;

    /** Codes that will fail again for the same ticket until the owner fixes Meta. */
    public const PERMANENT = [131070, 131042, 132001];

    /** fn(): int — replaces time() in tests. */
    public static $clock = null;

    private function __construct() {}

    public static function now(): int
    {
        return self::$clock !== null ? (int) (self::$clock)() : time();
    }

    public static function on(): bool
    {
        return Settings::getBool('wa_meta_health_on', true);
    }

    /* ---------------- kv (scope wa_health) ---------------- */

    private static function kvGet(string $key): int
    {
        try {
            return (int) Database::scalar('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => self::SCOPE, 'k' => $key], 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function kvSet(string $key, int $value): void
    {
        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, 'wahealth')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = 'wahealth'",
                ['s' => self::SCOPE, 'k' => mb_substr($key, 0, 120), 'v' => (string) $value]
            );
        } catch (Throwable $e) {
            // never breaks a send
        }
    }

    /** The Meta error code inside a logged error text: "(code 131042)", "(#131070)", or 0. */
    public static function codeIn(string $error): int
    {
        if (preg_match('/\((?:code |#)(\d{3,6})\)/', $error, $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * The code of a send WE held back (no call to Meta): "(held 131042)" / "(held 131070)", or 0.
     * Review fix: a held send is written with this marker, NOT "(code N)" - codeIn() must not
     * read it (Notify::logMessage would renew the hold from its own held rows forever), but the
     * retry cron parks it (permanentSql) and does not count it as a try.
     */
    public static function heldCodeIn(string $error): int
    {
        if (preg_match('/\(held (\d{3,6})\)/', $error, $m) === 1) {
            return (int) $m[1];
        }
        return 0;
    }

    /** The code a parked ticket failed on: Meta's own refusal, else our hold. */
    public static function parkCode(string $error): int
    {
        $c = self::codeIn($error);
        return $c !== 0 ? $c : self::heldCodeIn($error);
    }

    /** Remember what one refusal means (called by Notify::logMessage and the webhook). */
    public static function noteFailure(string $to, int $code): void
    {
        if (!self::on() || !in_array($code, [self::THREAD, self::BILLING], true)) {
            return;
        }
        if ($code === self::BILLING) {
            self::kvSet('billing_hold_at', self::now());
            return;
        }
        $d = preg_replace('/\D+/', '', $to) ?? '';
        if (strlen($d) >= 11 && strlen($d) <= 15) {
            self::kvSet('thread.' . $d, self::now());
        }
    }

    /** When the office pressed "Meta ठीक भयो" (0 = never). */
    public static function fixedAt(): int
    {
        return self::kvGet('fixed_at');
    }

    /** The owner fixed Meta: holds and number blocks cleared, parked tickets get one more try. */
    public static function markFixed(): void
    {
        self::kvSet('fixed_at', self::now());
        try {
            Database::run("DELETE FROM kv_store WHERE kscope = :s AND (kkey = 'billing_hold_at' OR kkey LIKE 'thread.%')", ['s' => self::SCOPE]);
        } catch (Throwable $e) {
        }
    }

    private static function hours(string $key, int $default): int
    {
        return max(1, min(72, Settings::getInt($key, $default)));
    }

    /** When 131042 was last seen (0 = never / cleared). */
    public static function billingHoldAt(): int
    {
        return self::kvGet('billing_hold_at');
    }

    /** '' = templates may go; else why every template is held (131042, billing). */
    public static function billingHold(): string
    {
        if (!self::on() || !Settings::getBool('wa_billing_hold_on', true)) {
            return '';
        }
        $at = self::billingHoldAt();
        if ($at <= 0 || $at < self::now() - self::hours('wa_billing_hold_hours', 12) * 3600 || $at < self::fixedAt()) {
            return '';
        }
        /* Meta's own proof that billing works again: a billable delivery after it (whatsapp/webhook.php) */
        try {
            $okAt = (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = 'wa_meta.billing_ok_at'", [], 0);
        } catch (Throwable $e) {
            $okAt = 0;
        }
        if ($okAt > $at) {
            return '';
        }
        return 'Meta billing missing (held 131042) since ' . date('j M H:i', $at) . ' - template messages paused; add a payment method on the WABA';
    }

    /** Did this number answer 131070 recently (another app owns the chat)? Free messages skip it. */
    public static function threadBlocked(string $to): bool
    {
        if (!self::on() || !Settings::getBool('wa_thread_block_on', true)) {
            return false;
        }
        $d = preg_replace('/\D+/', '', $to) ?? '';
        if ($d === '') {
            return false;
        }
        $at = self::kvGet('thread.' . $d);
        return $at > 0 && $at >= self::now() - self::hours('wa_thread_block_hours', 12) * 3600 && $at >= self::fixedAt();
    }

    /** Numbers blocked by 131070 right now. */
    public static function blockedNumbers(): int
    {
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM kv_store WHERE kscope = :s AND kkey LIKE 'thread.%' AND CAST(kvalue AS UNSIGNED) >= :t AND CAST(kvalue AS UNSIGNED) >= :f",
                ['s' => self::SCOPE, 't' => self::now() - self::hours('wa_thread_block_hours', 12) * 3600, 'f' => self::fixedAt()], 0
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** SQL: this message_logs row failed with a permanent Meta code. */
    public static function permanentSql(string $alias = 'm'): string
    {
        $parts = [];
        foreach (self::PERMANENT as $c) {
            $parts[] = "COALESCE({$alias}.error, '') LIKE '%(code {$c})%'";
            $parts[] = "COALESCE({$alias}.error, '') LIKE '%(#{$c})%'";
            $parts[] = "COALESCE({$alias}.error, '') LIKE '%(held {$c})%'";   // our own hold (heldCodeIn)
        }
        return '(' . implode(' OR ', $parts) . ')';
    }

    /** The cut-off: failures after this are "parked" (before it = the owner fixed Meta since). */
    public static function parkedAfter(): string
    {
        return date('Y-m-d H:i:s', max(0, self::fixedAt()));
    }

    /**
     * ONE panel alert per parked booking: the passenger's wa.me link (the office
     * sends the ticket by hand) and the ticket download link. true = a new alert.
     */
    public static function officeAlert(array $b, int $code): bool
    {
        try {
            $bid = (int) ($b['id'] ?? 0);
            $pnr = (string) ($b['pnr'] ?? '');
            if ($bid <= 0 || $pnr === '') {
                return false;
            }
            foreach (['ticket', 'wasend', 'adminalerts'] as $f) {
                require_once __DIR__ . '/' . $f . '.php';
            }
            /* review fix: the number the ticket was ACTUALLY sent to (message_logs.to_number), else
               Notify's own rule (contact_country_code, then a Nepal id_type, then the account's
               country) - never a bare "+91" guess, which once sent Nepali tickets to strangers. */
            $to = preg_replace('/\D+/', '', (string) ($b['to_number'] ?? '')) ?? '';
            if (strlen($to) < 11) {
                require_once __DIR__ . '/notify.php';
                $full = Database::fetch('SELECT * FROM bookings WHERE id = :b LIMIT 1', ['b' => $bid]);
                $to   = is_array($full) ? Notify::whatsappNumberFor($full) : '';
            }
            $link = Ticket::downloadUrl($pnr);
            $text = 'Your S Hari Global ticket ' . $pnr . ': ' . $link;   // plain words: the alert body holds 500 characters
            $why  = match ($code) {
                self::THREAD  => 'अर्को app ले कुराकानी समातेको छ (131070)',
                self::BILLING => 'Meta billing छैन (131042)',
                self::TEMPLATE => 'Meta ले template refuse गर्‍यो (132001)',
                default        => 'Meta refused (' . $code . ')',
            };
            try {
                $who = trim((string) Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY is_primary DESC, id LIMIT 1', ['b' => $bid], ''));
            } catch (Throwable $e) {
                $who = '';
            }
            $body = mb_substr($who !== '' ? $who : 'यात्री', 0, 40) . ' · ' . (string) ($b['contact_phone'] ?? '-') . " — WhatsApp टिकट गएन: {$why}। फेरि पठाइँदैन।\n"
                  . 'टिकट link: ' . $link . "\n"
                  . ($to !== '' ? 'हातले पठाउन: ' . WaSend::waMe($to, $text) : '');
            return AdminAlerts::push('wa_route', '📵 Ticket not delivered · ' . $pnr, $body,
                '/admin/booking-view.php?pnr=' . rawurlencode($pnr), '📵', 'wa_perm:' . $bid . ':' . self::fixedAt()) > 0;   // review fix: one alert per "Meta fixed" cycle
        } catch (Throwable $e) {
            Logger::warning('WaHealth office alert failed: ' . $e->getMessage(), [], 'whatsapp');
            return false;
        }
    }

    /**
     * The last $days of WhatsApp sends (message_logs): totals by status, failures by
     * Meta code (count + last time) and the 132001 refusals by template name.
     *
     * @return array{sent: int, failed: int, codes: array<int, array{n: int, last: string}>, tpl132001: array<string, int>}
     */
    public static function stats(int $days = 7): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'codes' => [], 'tpl132001' => []];
        $since = date('Y-m-d H:i:s', self::now() - max(1, $days) * 86400);
        try {
            foreach (Database::fetchAll("SELECT status, COUNT(*) AS n FROM message_logs WHERE channel = 'whatsapp' AND created_at >= :s GROUP BY status", ['s' => $since]) as $r) {
                if (in_array((string) $r['status'], ['sent', 'failed'], true)) {
                    $out[(string) $r['status']] = (int) $r['n'];
                }
            }
            foreach (Database::fetchAll(
                "SELECT error, body, created_at FROM message_logs WHERE channel = 'whatsapp' AND status = 'failed' AND created_at >= :s AND error IS NOT NULL ORDER BY id DESC LIMIT 5000",
                ['s' => $since]) as $r) {
                $c = self::codeIn((string) $r['error']);
                if ($c <= 0) {
                    continue;
                }
                $out['codes'][$c] ??= ['n' => 0, 'last' => (string) $r['created_at']];
                $out['codes'][$c]['n']++;
                if ($c === self::TEMPLATE && preg_match('/Template (\S+) \(Meta\)/', (string) $r['body'], $m) === 1) {
                    $out['tpl132001'][$m[1]] = ($out['tpl132001'][$m[1]] ?? 0) + 1;
                }
            }
            uasort($out['codes'], static fn(array $a, array $b): int => $b['n'] <=> $a['n']);
            arsort($out['tpl132001']);
        } catch (Throwable $e) {
            // no message_logs on a bare database
        }
        return $out;
    }

    /** Nepali · English line for a code on the health card. */
    public static function hint(int $code): string
    {
        return match ($code) {
            131070 => 'अर्को app (पुरानो AiSensy / Meta Business Agent) ले कुराकानी समातेको छ · another app owns the chat (thread control)',
            131042 => 'WABA मा payment method छैन — template पैसा लाग्ने सबै फेल · no payment method on the WABA',
            132001 => 'Meta ले यो template "छैन" भन्छ (approved भए पनि) · template refused by Meta',
            131026 => 'नम्बर WhatsApp मा छैन / पुरानो app · number not on WhatsApp',
            131047 => '२४ घण्टा window बन्द · the 24 h window had closed',
            131049 => 'Meta ले marketing limit मा रोक्यो · held by Meta (marketing limit)',
            default => '',
        };
    }
}
