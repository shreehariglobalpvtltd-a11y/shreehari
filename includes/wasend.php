<?php
/**
 * =====================================================================
 *  includes/wasend.php — the official Meta Cloud API as the WhatsApp
 *  sender when AiSensy is not there (30 Oct 2026).
 *
 *  Owner: "AiSensy chaldaina bhane company ko aafnai number (+91 87358
 *  81507, Meta Cloud API) bata pathaos - bistarai, ek ek garera".
 *
 *  AiSensy has no API key on live, so every AiSensy send fails and the
 *  ticket / message ended as a wa.me link nobody tapped. This class is
 *  the fallback the senders hand a message to when AiSensy is missing or
 *  refused it:
 *
 *    1. the bot number's own 24 h window is open (the person wrote to us,
 *       includes/waroute.php reads our webhook record)  -> free text, the
 *       ticket picture or a document;
 *    2. outside the window                              -> an APPROVED
 *       template that is already configured (the ticket template
 *       whatsapp_template_name for tickets, the caller's named template,
 *       or the generic wa_cloud_tpl_message), under the daily paid cap;
 *    3. neither                                         -> the message waits
 *       ("waiting") until the person writes, and the office has a wa.me
 *       click-to-send link meanwhile (admin/wa-outbox.php).
 *
 *  Pacing (no bursts): every message is a row in wa_outbox. A web request
 *  sends a ticket / login code at once; everything else goes one at a time
 *  with wa_cloud_gap_sec between sends, at most wa_cloud_daily_cap a day
 *  (0 = paused). A transient refusal (rate limit, Meta 5xx, network) is
 *  retried with backoff (1, 5, 15, 60 min); a message that was accepted is
 *  never sent again by the queue, and one whose fate is unknown (Meta
 *  answered without an id, or the request died mid-send) waits for a
 *  person to press Retry.
 *
 *  Every attempt that reaches Meta is a message_logs row with provider
 *  'cloud_api' (copied into msg_ledger by MsgLedger::sync(), which drain()
 *  also runs); delivered / read / failed come back from the webhook via
 *  msg_ledger / message_logs and refresh() copies them onto the outbox row.
 *
 *  This is the official API only. Nothing here automates WhatsApp Web or
 *  the app, and nothing tries to look like a person.
 *
 *  Settings (all optional):
 *    wa_cloud_fallback_on   1   use the Cloud API when AiSensy cannot send
 *    wa_cloud_gap_sec       4   seconds between two queued sends (1..60)
 *    wa_cloud_daily_cap     300 Cloud API sends a day by this queue (0 = paused)
 *    wa_cloud_tpl_message   ''  approved generic template ({{1}} label {{2}} text)
 *                               for OFFICE ALERTS outside the window (e.g.
 *                               shg_office_alert) - only the GENERIC_OK purposes,
 *                               never a code / password, a bot or support reply
 *                               or a hand-typed message (those wait for the
 *                               person's reply instead)
 *    wa_cloud_tpl_lang      en
 *
 *  One-time codes / PINs / passwords (SECRET purposes, or any body that
 *  reads like one - secretText()) go only inside an open window, at once,
 *  or not at all (state "skipped": the caller's SMS / click-to-chat runs).
 *  They never wait, are never retried later and their text is scrubbed
 *  from the row whatever the outcome.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaSend
{
    /** Sent at once from a web request (one person waiting on it), never held by the gap. */
    public const PRIORITY = ['ticket', 'ticket_update', 'ticket_resend', 'otp', 'login', 'auth', 'team_credentials', 'verify', 'test'];

    /** A one-time code / PIN / password: free inside the window at once, or not at all; never kept at rest. */
    public const SECRET = ['otp', 'login', 'auth', 'team_credentials', 'verify'];

    /** What a secret row's body becomes once it is done with (any outcome). */
    public const SCRUBBED = '[one-time code / password - not kept]';

    /** The deliberate ticket sends of one booking: one open outbox row per booking + number. */
    public const TICKET_FAMILY = ['ticket', 'ticket_update', 'ticket_resend'];

    /** Office alerts the generic utility template (wa_cloud_tpl_message) may carry outside the window. */
    public const GENERIC_OK = ['admin_note', 'office_note', 'staff_note', 'office_report', 'support_alert', 'shift_close', 'seat_status', 'agreement_renewal'];
    public const GENERIC_OK_PREFIX = ['payment_', 'booking_', 'trip_'];

    /** A body that carries a one-time code / PIN or a password (any language the senders use). */
    private const SECRET_RX = [
        '/((?:code|कोड|\bPIN\b|\bOTP\b)\s*[:：-]?\s*\*?\s*)\d{4,8}(?!\d)/iu',
        '/((?:password|पासवर्ड)\s*[:：-]\s*\*?)[^\s*]{4,}/iu',
    ];

    public const STATES = ['queued', 'sending', 'waiting', 'sent', 'delivered', 'read', 'failed', 'unknown', 'cancelled'];

    /** A message in these states is never sent again by the queue. */
    public const DONE = ['sent', 'delivered', 'read', 'unknown'];

    public const MAX_ATTEMPTS = 5;

    /** A message with no window and no template waits this long for the person to write. */
    public const WAIT_HOURS = 72;

    /** Seconds before retry 1, 2, 3, 4 … (the last one repeats). */
    public const BACKOFF = [60, 300, 900, 3600];

    /** Meta / network answers worth trying again later. */
    public const TRANSIENT_CODES = [1, 2, 4, 17, 341, 80007, 130429, 131000, 131016, 131048, 131056];

    /** "Re-engagement": the window had closed after all. */
    public const WINDOW_CODES = [131047];

    /* ----- test hooks (never set in production) ----- */

    /** fn(string $url, array $body): array{http:int, body:string, error?:string} — replaces the real HTTP call. */
    public static $http = null;

    /** ['token' => …, 'phone_id' => …, 'base' => …] — replaces the stored credentials. */
    public static ?array $creds = null;

    /** true / false — replaces "is AiSensy configured". */
    public static ?bool $aisensy = null;

    /** fn(float $seconds): void — replaces usleep() in the pacing. */
    public static $sleeper = null;

    /** fn(): float — replaces microtime(true). */
    public static $clock = null;

    /** fn(string $intl): bool — replaces the 24 h window lookup. */
    public static $window = null;

    private static bool $ready = false;

    /* =================================================================
     *  Configuration (yes / no only — a secret is never returned)
     * ================================================================= */

    public static function aisensyConfigured(): bool
    {
        if (self::$aisensy !== null) {
            return self::$aisensy;
        }
        try {
            if (!class_exists('AiSensyAPI') && defined('ROOT_PATH') && is_file(ROOT_PATH . '/whatsapp/aisensy.php')) {
                require_once ROOT_PATH . '/whatsapp/aisensy.php';
            }
            return class_exists('AiSensyAPI') && AiSensyAPI::enabled() && AiSensyAPI::mode() !== 'off';
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function cloudConfigured(): bool
    {
        $c = self::creds();
        return $c['token'] !== '' && $c['phone_id'] !== '';
    }

    public static function fallbackOn(): bool
    {
        return Settings::getBool('wa_cloud_fallback_on', true);
    }

    public static function gapSeconds(): int
    {
        return max(1, min(60, Settings::getInt('wa_cloud_gap_sec', 4)));
    }

    public static function dailyCap(): int
    {
        return max(0, Settings::getInt('wa_cloud_daily_cap', 300));
    }

    /** Cloud API sends this queue made today (every attempt that reached Meta). */
    public static function sentToday(): int
    {
        return (int) self::kvGet(date('Y-m-d', (int) self::now()) . ':n', '0');
    }

    /**
     * The generic office-alert template. Always {{1}} label {{2}} text: a
     * bare "{{1}}" template would be free text by another name.
     *
     * @return array{name:string, lang:string, nvars:int}
     */
    public static function genericTemplate(): array
    {
        return [
            'name'  => trim(Settings::getString('wa_cloud_tpl_message', '')),
            'lang'  => trim(Settings::getString('wa_cloud_tpl_lang', 'en')) ?: 'en',
            'nvars' => 2,
        ];
    }

    /** Does this text carry a one-time code / PIN or a password? */
    public static function secretText(string $s): bool
    {
        foreach (self::SECRET_RX as $rx) {
            if (preg_match($rx, $s) === 1) {
                return true;
            }
        }
        return false;
    }

    /** Is this message a secret (by purpose, or - for anything but a ticket - by its text)? */
    public static function isSecret(string $purpose, string $body): bool
    {
        if (in_array($purpose, self::SECRET, true)) {
            return true;
        }
        return !in_array($purpose, [...self::TICKET_FAMILY, 'seller_copy'], true) && self::secretText($body);
    }

    /** The text with every code / PIN / password replaced by ••••. */
    public static function maskSecrets(string $s): string
    {
        foreach (self::SECRET_RX as $rx) {
            $s = preg_replace($rx, '$1••••', $s) ?? $s;
        }
        return $s;
    }

    /** May the generic office-alert template carry this message outside the window? */
    public static function genericAllowed(string $purpose, string $body): bool
    {
        if ($body === '' || self::isSecret($purpose, $body)) {
            return false;
        }
        if (in_array($purpose, self::GENERIC_OK, true)) {
            return true;
        }
        foreach (self::GENERIC_OK_PREFIX as $p) {
            if (str_starts_with($purpose, $p)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{name:string, lang:string} the approved ticket template on the bot number */
    public static function ticketTemplate(): array
    {
        return [
            'name' => trim(Settings::getString('whatsapp_template_name', '')),
            'lang' => trim(Settings::getString('whatsapp_template_lang', 'en')) ?: 'en',
        ];
    }

    /** Is the bot number's free 24 h window open for this number (our webhook record)? */
    public static function windowOpen(string $intl): bool
    {
        if ($intl === '') {
            return false;
        }
        if (self::$window !== null) {
            return (bool) (self::$window)($intl);
        }
        try {
            require_once __DIR__ . '/waroute.php';
            return WaRoute::metaWindowOpen($intl);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Credentials: env first, then the admin settings (whatsapp_api_token /
     * whatsapp_phone_id), exactly as whatsapp/config.php resolves them.
     *
     * @return array{token:string, phone_id:string, base:string}
     */
    private static function creds(): array
    {
        if (self::$creds !== null) {
            return self::$creds + ['token' => '', 'phone_id' => '', 'base' => 'https://graph.facebook.com/v25.0'];
        }
        if (!defined('META_ACCESS_TOKEN') && defined('ROOT_PATH') && is_file(ROOT_PATH . '/whatsapp/config.php')) {
            try {
                require_once ROOT_PATH . '/whatsapp/config.php';
            } catch (Throwable $e) {
                // fall through to the direct read below
            }
        }
        $read = static function (string $env, string $key, string $default = ''): string {
            $v = getenv($env);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            try {
                $s = trim(Settings::getString($key, ''));
            } catch (Throwable $e) {
                $s = '';
            }
            return $s !== '' ? $s : $default;
        };
        $token = defined('META_ACCESS_TOKEN') ? (string) constant('META_ACCESS_TOKEN') : $read('META_ACCESS_TOKEN', 'whatsapp_api_token');
        $phone = defined('META_PHONE_NUMBER_ID') ? (string) constant('META_PHONE_NUMBER_ID') : $read('META_PHONE_NUMBER_ID', 'whatsapp_phone_id');
        $base  = defined('META_API_BASE') ? (string) constant('META_API_BASE')
            : 'https://graph.facebook.com/' . $read('META_API_VERSION', 'whatsapp_api_version', 'v25.0');
        return ['token' => trim($token), 'phone_id' => trim($phone), 'base' => $base];
    }

    /* =================================================================
     *  The decision (pure — unit tested)
     * ================================================================= */

    /**
     * Which way a message goes.
     *
     * @param array{aisensy?:bool, aisensy_failed?:bool, fallback_on?:bool, cloud?:bool,
     *              window?:bool, template?:string, paid_refusal?:string} $s
     * @return array{route:string, why:string}  route: aisensy | cloud_free | cloud_template | wait | manual
     */
    public static function decide(array $s): array
    {
        if (!empty($s['aisensy']) && empty($s['aisensy_failed'])) {
            return ['route' => 'aisensy', 'why' => 'AiSensy is configured - it goes first'];
        }
        if (array_key_exists('fallback_on', $s) && !$s['fallback_on']) {
            return ['route' => 'manual', 'why' => 'Cloud API fallback is switched off (wa_cloud_fallback_on)'];
        }
        if (empty($s['cloud'])) {
            return ['route' => 'manual', 'why' => 'Cloud API is not configured (token / phone number id)'];
        }
        if (!empty($s['window'])) {
            return ['route' => 'cloud_free', 'why' => 'inside the 24 h window - free message'];
        }
        $tpl = trim((string) ($s['template'] ?? ''));
        if ($tpl === '') {
            return ['route' => 'wait', 'why' => 'outside the 24 h window and no approved template - waits for the person to write; wa.me meanwhile'];
        }
        $paid = trim((string) ($s['paid_refusal'] ?? ''));
        if ($paid !== '') {
            return ['route' => 'wait', 'why' => 'template held: ' . $paid];
        }
        return ['route' => 'cloud_template', 'why' => 'outside the 24 h window - approved template ' . $tpl];
    }

    /** Is a refusal worth another try later? */
    public static function transient(int $http, int $code): bool
    {
        return $http === 0 || $http === 429 || $http >= 500 || in_array($code, self::TRANSIENT_CODES, true);
    }

    /** Seconds to wait before attempt number $attempts + 1. */
    public static function backoff(int $attempts): int
    {
        $i = max(0, min(count(self::BACKOFF) - 1, $attempts - 1));
        return self::BACKOFF[$i];
    }

    /* =================================================================
     *  Numbers and links
     * ================================================================= */

    /** International digits (91…, 977…) for a raw number + hint (IN / NP), or ''. */
    public static function intl(string $raw, string $hint = ''): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10) {
            $h  = strtoupper(trim($hint));
            $cc = match ($h) {
                'NP', '977' => '977',
                'IN', '91'  => '91',
                default     => (preg_replace('/\D/', '', Settings::getString('whatsapp_default_country', '91')) ?: '91'),
            };
            $d = $cc . $d;
        }
        return (strlen($d) >= 11 && strlen($d) <= 15 && $d[0] !== '0') ? $d : '';
    }

    /** The manual hand-over: open WhatsApp with this text typed (a person presses send). */
    public static function waMe(string $intl, string $text = ''): string
    {
        $d = preg_replace('/\D+/', '', $intl) ?? '';
        return 'https://wa.me/' . $d . ($text !== '' ? '?text=' . rawurlencode($text) : '');
    }

    /* =================================================================
     *  Tables
     * ================================================================= */

    public static function ensure(): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS wa_outbox (
                    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    dedupe      CHAR(40)        NULL,
                    to_number   VARCHAR(20)     NOT NULL,
                    to_name     VARCHAR(120)    NULL,
                    body        TEXT            NULL,
                    media_url   VARCHAR(500)    NULL,
                    media_kind  VARCHAR(10)     NOT NULL DEFAULT '',
                    filename    VARCHAR(120)    NULL,
                    tpl_name    VARCHAR(128)    NULL,
                    tpl_lang    VARCHAR(12)     NULL,
                    tpl_vars    TEXT            NULL,
                    tpl_header  VARCHAR(500)    NULL,
                    purpose     VARCHAR(40)     NOT NULL DEFAULT 'message',
                    label       VARCHAR(80)     NULL,
                    booking_id  BIGINT UNSIGNED NULL,
                    pnr         VARCHAR(40)     NULL,
                    admin_id    BIGINT UNSIGNED NULL,
                    source      VARCHAR(16)     NOT NULL DEFAULT 'notify',
                    no_free     TINYINT(1)      NOT NULL DEFAULT 0,
                    state       VARCHAR(12)     NOT NULL DEFAULT 'queued',
                    route       VARCHAR(16)     NULL,
                    attempts    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                    next_at     DATETIME        NOT NULL,
                    wamid       VARCHAR(190)    NULL,
                    error       VARCHAR(255)    NULL,
                    created_at  DATETIME        NOT NULL,
                    sent_at     DATETIME        NULL,
                    updated_at  DATETIME        NOT NULL,
                    UNIQUE KEY uq_wo_dedupe (dedupe),
                    KEY ix_wo_state (state, next_at),
                    KEY ix_wo_to (to_number),
                    KEY ix_wo_wamid (wamid),
                    KEY ix_wo_booking (booking_id),
                    KEY ix_wo_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            Database::query(
                "CREATE TABLE IF NOT EXISTS wa_contacts (
                    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    name        VARCHAR(120)    NOT NULL,
                    phone       VARCHAR(20)     NOT NULL,
                    note        VARCHAR(255)    NULL,
                    created_by  BIGINT UNSIGNED NULL,
                    created_at  DATETIME        NOT NULL,
                    updated_at  DATETIME        NOT NULL,
                    UNIQUE KEY uq_wc_phone (phone),
                    KEY ix_wc_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            self::$ready = true;
        } catch (Throwable $e) {
            Logger::warning('wa_outbox unavailable: ' . $e->getMessage(), [], 'whatsapp');
        }
        return self::$ready;
    }

    /* =================================================================
     *  Queue
     * ================================================================= */

    /**
     * Put one message in the outbox (or find the same message already
     * there). Required: to (intl digits), and body or tpl_name.
     *
     * @param array<string,mixed> $m to, name, body, media_url, media_kind, filename, tpl_name, tpl_lang,
     *                               tpl_vars (list), tpl_header, purpose, label, booking_id, pnr,
     *                               admin_id, source, no_free, dedupe (string key, '' = none)
     * @return array{id:int, state:string, dupe:bool, wamid:string, error:string}
     */
    public static function queue(array $m): array
    {
        $out = ['id' => 0, 'state' => 'failed', 'dupe' => false, 'wamid' => '', 'error' => ''];
        if (!self::ensure()) {
            $out['error'] = 'outbox table unavailable';
            return $out;
        }
        $to = preg_replace('/\D+/', '', (string) ($m['to'] ?? '')) ?? '';
        $body = trim((string) ($m['body'] ?? ''));
        $tpl  = trim((string) ($m['tpl_name'] ?? ''));
        if ($to === '' || strlen($to) < 11 || strlen($to) > 15) {
            $out['error'] = 'not a usable WhatsApp number';
            return $out;
        }
        if ($body === '' && $tpl === '') {
            $out['error'] = 'empty message';
            return $out;
        }
        $key = trim((string) ($m['dedupe'] ?? ''));
        $key = $key !== '' ? sha1($key) : null;
        $now = self::stamp();

        if ($key !== null) {
            $have = Database::fetch('SELECT id, state, wamid, error, attempts, purpose FROM wa_outbox WHERE dedupe = :d', ['d' => $key]);
            if ($have !== null) {
                $out = ['id' => (int) $have['id'], 'state' => (string) $have['state'], 'dupe' => true,
                        'wamid' => (string) ($have['wamid'] ?? ''), 'error' => (string) ($have['error'] ?? '')];
                /* The same message again after it FAILED for good: one more go
                   (never after it was accepted). A waiting one is looked at
                   again - the person may have written in the meantime. A
                   secret's text is scrubbed once it is done: never resent. */
                if (in_array((string) $have['purpose'], self::SECRET, true)) {
                    return $out;
                }
                if (($have['state'] === 'failed' && (int) $have['attempts'] < self::MAX_ATTEMPTS * 2) || $have['state'] === 'waiting') {
                    $out['state'] = Database::run(
                        "UPDATE wa_outbox SET state = 'queued', next_at = :n, updated_at = :u WHERE id = :i AND state IN ('failed','waiting')",
                        ['n' => $now, 'u' => $now, 'i' => (int) $have['id']]
                    ) > 0 ? 'queued' : $out['state'];
                }
                return $out;
            }
        }

        $vars = $m['tpl_vars'] ?? null;
        $row = [
            'dedupe'     => $key,
            'to_number'  => $to,
            'to_name'    => self::cut((string) ($m['name'] ?? ''), 120),
            'body'       => $body !== '' ? mb_substr($body, 0, 4000) : null,
            'media_url'  => self::cut((string) ($m['media_url'] ?? ''), 500),
            'media_kind' => in_array((string) ($m['media_kind'] ?? ''), ['image', 'document'], true) ? (string) $m['media_kind'] : self::mediaKind((string) ($m['media_url'] ?? '')),
            'filename'   => self::cut((string) ($m['filename'] ?? ''), 120),
            'tpl_name'   => $tpl !== '' ? mb_substr($tpl, 0, 128) : null,
            'tpl_lang'   => $tpl !== '' ? mb_substr(trim((string) ($m['tpl_lang'] ?? 'en')) ?: 'en', 0, 12) : null,
            'tpl_vars'   => is_array($vars) && $vars !== [] ? json_encode(array_values(array_map('strval', $vars)), JSON_UNESCAPED_UNICODE) : null,
            'tpl_header' => self::cut((string) ($m['tpl_header'] ?? ''), 500),
            'purpose'    => mb_substr(trim((string) ($m['purpose'] ?? 'message')) ?: 'message', 0, 40),
            'label'      => self::cut((string) ($m['label'] ?? ''), 80),
            'booking_id' => !empty($m['booking_id']) ? (int) $m['booking_id'] : null,
            'pnr'        => self::cut((string) ($m['pnr'] ?? ''), 40),
            'admin_id'   => !empty($m['admin_id']) ? (int) $m['admin_id'] : null,
            'source'     => mb_substr((string) ($m['source'] ?? 'notify'), 0, 16),
            'no_free'    => !empty($m['no_free']) ? 1 : 0,
            'state'      => 'queued',
            'attempts'   => 0,
            'next_at'    => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        /* A ticket of a booking already open in the outbox (queued / sending /
           waiting) for this number: that row carries it - with the newest text
           - instead of a second one. Otherwise every whatsapp-retry run (or a
           second "Send ticket") adds a waiting row and the passenger gets N
           copies the moment the window opens. */
        if ($row['booking_id'] !== null && in_array($row['purpose'], self::TICKET_FAMILY, true)) {
            $fam  = "'" . implode("','", self::TICKET_FAMILY) . "'";
            $open = Database::fetch(
                "SELECT id, state FROM wa_outbox WHERE booking_id = :b AND to_number = :t AND purpose IN ($fam)
                    AND state IN ('queued','sending','waiting') ORDER BY id DESC LIMIT 1",
                ['b' => $row['booking_id'], 't' => $to]
            );
            if ($open !== null) {
                $fresh = array_intersect_key($row, array_flip(['to_name', 'body', 'media_url', 'media_kind', 'filename', 'tpl_name', 'tpl_lang',
                                                               'tpl_vars', 'tpl_header', 'purpose', 'label', 'pnr', 'no_free']));
                $fresh['updated_at'] = $now;
                if ((string) $open['state'] === 'waiting') {
                    $fresh['state']   = 'queued';
                    $fresh['next_at'] = $now;
                }
                try {
                    Database::update('wa_outbox', $fresh, "id = :id AND state IN ('queued','waiting')", ['id' => (int) $open['id']]);
                } catch (Throwable $e) {
                    // the open row stays as it was - still only one
                }
                $st = (string) Database::scalar('SELECT state FROM wa_outbox WHERE id = :i', ['i' => (int) $open['id']], 'queued');
                return ['id' => (int) $open['id'], 'state' => $st, 'dupe' => true, 'wamid' => '', 'error' => ''];
            }
        }
        try {
            $id = Database::insertIgnore('wa_outbox', $row);
        } catch (Throwable $e) {
            $out['error'] = 'could not queue: ' . mb_substr($e->getMessage(), 0, 120);
            return $out;
        }
        if ($id <= 0 && $key !== null) {   // a parallel request queued the very same message first
            $id = (int) Database::scalar('SELECT id FROM wa_outbox WHERE dedupe = :d', ['d' => $key], 0);
            return ['id' => $id, 'state' => 'queued', 'dupe' => true, 'wamid' => '', 'error' => ''];
        }
        return ['id' => $id, 'state' => 'queued', 'dupe' => false, 'wamid' => '', 'error' => ''];
    }

    /**
     * The fallback the senders call (Notify::whatsapp(), WaQueue) when AiSensy
     * cannot send. Queues the message and, when the pacing allows, sends it now.
     *
     * @param array<string,mixed> $opt bookingId, admin_id, purpose-specific: tpl_vars (ticket {{1}}..{{7}}),
     *                                 template_name / template_lang (caller's own template), skip_free,
     *                                 label, name, hint, pnr, source, dedupe
     * @return array{state:string, id:int, message_id:string, error:string, how:string}
     *         state: sent | queued | waiting | failed | unknown | skipped
     */
    public static function fallback(string $phone, string $text, ?string $mediaUrl, string $purpose, array $opt = []): array
    {
        $out = ['state' => 'skipped', 'id' => 0, 'message_id' => '', 'error' => '', 'how' => ''];
        try {
            if (!self::fallbackOn()) {
                $out['error'] = 'wa_cloud_fallback_on is off';
                return $out;
            }
            if (!self::cloudConfigured()) {
                $out['error'] = 'Cloud API not configured';
                return $out;
            }
            $to = self::intl($phone, (string) ($opt['hint'] ?? ''));
            if ($to === '' || (trim($text) === '' && trim((string) ($opt['template_name'] ?? '')) === '')) {
                $out['error'] = $to === '' ? 'not a usable WhatsApp number' : 'empty message';
                return $out;
            }
            $purpose = trim($purpose) !== '' ? trim($purpose) : 'message';
            $bid     = isset($opt['bookingId']) && (int) $opt['bookingId'] > 0 ? (int) $opt['bookingId'] : null;
            $media   = trim((string) $mediaUrl);
            /* A login code / 2FA / OTP / driver PIN often comes with no
               purpose at all: the text decides. It goes free inside an open
               window right now, on the caller's own (authentication) template,
               or not at all - "skipped", so the caller's SMS path runs and no
               row keeps the code. */
            $secret = self::isSecret($purpose, $text);
            if ($secret) {
                if (!in_array($purpose, self::SECRET, true)) {
                    $purpose = 'otp';
                }
                $ownTpl = trim((string) ($opt['template_name'] ?? '')) !== '' && (array) ($opt['tpl_vars'] ?? []) !== [];
                if (!$ownTpl && (!empty($opt['skip_free']) || !self::windowOpen($to))) {
                    $out['error'] = 'one-time code: no open 24 h window and no authentication template - not queued';
                    return $out;
                }
                if (!self::mayGoNow($purpose)) {
                    $out['error'] = 'one-time code: the outbox is paused / at its daily cap - not queued';
                    return $out;
                }
            }
            $msg = [
                'to' => $to, 'name' => (string) ($opt['name'] ?? ''), 'body' => $text, 'media_url' => $media,
                'purpose' => $purpose, 'label' => (string) ($opt['label'] ?? ''), 'booking_id' => $bid,
                'pnr' => (string) ($opt['pnr'] ?? ''), 'admin_id' => (int) ($opt['admin_id'] ?? 0),
                'source' => (string) ($opt['source'] ?? 'notify'), 'no_free' => !empty($opt['skip_free']),
            ];
            $msg += self::templateFor($purpose, (array) ($opt['tpl_vars'] ?? []), $media,
                (string) ($opt['template_name'] ?? ''), (string) ($opt['template_lang'] ?? 'en'));
            $msg['dedupe'] = (string) ($opt['dedupe'] ?? self::dedupeKey($to, $purpose, $bid, $text, $media));

            $q = self::queue($msg);
            $out['id'] = $q['id'];
            if ($q['id'] <= 0) {
                $out['state'] = 'failed';
                $out['error'] = $q['error'];
                return $out;
            }
            if ($q['dupe'] && in_array($q['state'], self::DONE, true)) {
                return ['state' => 'sent', 'id' => $q['id'], 'message_id' => $q['wamid'], 'error' => '', 'how' => 'already sent - not sent twice'];
            }
            if ($secret && $q['state'] !== 'queued') {   // the same code already failed / was cancelled: the caller's SMS path
                return ['state' => 'skipped', 'id' => 0, 'message_id' => '', 'error' => 'one-time code: an earlier try did not go', 'how' => ''];
            }
            if ($q['state'] !== 'queued') {
                return ['state' => $q['state'], 'id' => $q['id'], 'message_id' => $q['wamid'], 'error' => $q['error'], 'how' => 'in the outbox'];
            }
            if (!$secret && !self::mayGoNow($purpose)) {
                return ['state' => 'queued', 'id' => $q['id'], 'message_id' => '', 'error' => '', 'how' => 'queued - paced (the outbox sends it in turn)'];
            }
            $r = self::process($q['id']);
            if ($secret && $r['state'] !== 'sent') {
                return self::secretDone($q['id'], $r);
            }
            return ['state' => $r['state'], 'id' => $q['id'], 'message_id' => $r['wamid'], 'error' => $r['error'], 'how' => $r['how']];
        } catch (Throwable $e) {
            Logger::warning('WaSend fallback failed: ' . $e->getMessage(), [], 'whatsapp');
            $out['state'] = 'failed';
            $out['error'] = 'internal error';
            return $out;
        }
    }

    /**
     * A one-time code that did not go at once: never left for later. A row
     * that never reached Meta is removed ("skipped" - the caller's SMS /
     * click-to-chat runs); one that did is closed (a retry would cancel) and
     * its text scrubbed either way.
     *
     * @param array{state:string, wamid:string, error:string, how:string} $r
     * @return array{state:string, id:int, message_id:string, error:string, how:string}
     */
    private static function secretDone(int $id, array $r): array
    {
        $row = Database::fetch('SELECT attempts, state FROM wa_outbox WHERE id = :i', ['i' => $id]);
        $attempts = $row !== null ? (int) $row['attempts'] : 0;
        if ($row !== null && in_array((string) $row['state'], ['queued', 'sending', 'waiting'], true)) {
            self::set($id, ['state' => $attempts > 0 ? 'failed' : 'cancelled',
                            'error' => mb_substr('one-time code - not kept for a later try: ' . ($r['error'] !== '' ? $r['error'] : $r['how']), 0, 255)]);
        }
        self::scrub($id);
        if ($attempts === 0) {
            try {
                Database::run('DELETE FROM wa_outbox WHERE id = :i', ['i' => $id]);
            } catch (Throwable $e) {
                // the scrubbed, cancelled row stays - it holds no code
            }
            return ['state' => 'skipped', 'id' => 0, 'message_id' => '', 'error' => $r['error'], 'how' => 'one-time code - not queued'];
        }
        $st = $r['state'] === 'unknown' ? 'unknown' : 'failed';
        return ['state' => $st, 'id' => $id, 'message_id' => '', 'error' => $r['error'], 'how' => 'one-time code - not retried later'];
    }

    /**
     * Scrub the code / password out of secret rows that are done with
     * (any state but queued / sending), and close a secret that waits - a
     * code never waits for a reply. $id 0 = every such row.
     */
    private static function scrub(int $id = 0): void
    {
        $in = "'" . implode("','", self::SECRET) . "'";
        $one = $id > 0 ? ' AND id = :i' : '';
        $p = $id > 0 ? ['i' => $id] : [];
        try {
            Database::run(
                "UPDATE wa_outbox SET state = 'cancelled', error = 'one-time code - never waits for a reply', updated_at = :u
                  WHERE purpose IN ($in) AND state = 'waiting'" . $one,
                ['u' => self::stamp()] + $p
            );
            Database::run(
                "UPDATE wa_outbox SET body = :b, tpl_vars = NULL
                  WHERE purpose IN ($in) AND state NOT IN ('queued','sending') AND (body IS NULL OR body <> :b2 OR tpl_vars IS NOT NULL)" . $one,
                ['b' => self::SCRUBBED, 'b2' => self::SCRUBBED] + $p
            );
        } catch (Throwable $e) {
            Logger::warning('wa_outbox scrub failed: ' . $e->getMessage(), [], 'whatsapp');
        }
    }

    /**
     * A booking's ticket, by the outbox (office pressed "Send ticket") or by
     * the "hi" queue. Only confirmed / completed bookings.
     *
     * @param array<string,mixed> $opt to (override number), hint, role (customer | alt | seller),
     *                                 admin_id, source, purpose, now (bool: try at once)
     * @return array{state:string, id:int, message_id:string, error:string, how:string, to:string, text:string}
     */
    public static function ticket(string $pnrOrId, array $opt = []): array
    {
        $out = ['state' => 'failed', 'id' => 0, 'message_id' => '', 'error' => '', 'how' => '', 'to' => '', 'text' => ''];
        try {
            $b = ctype_digit($pnrOrId)
                ? Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $pnrOrId])
                : Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => strtoupper(trim($pnrOrId))]);
            if ($b === null) {
                $out['error'] = 'no booking with that PNR';
                return $out;
            }
            if (!in_array((string) $b['status'], ['confirmed', 'completed'], true)) {
                $out['error'] = 'booking is ' . (string) $b['status'] . ' - only a confirmed ticket can be sent';
                return $out;
            }
            foreach (['qr', 'pdf', 'ticket', 'notify'] as $f) {
                if (is_file(__DIR__ . '/' . $f . '.php')) {
                    require_once __DIR__ . '/' . $f . '.php';
                }
            }
            $override = trim((string) ($opt['to'] ?? ''));
            $to = $override !== ''
                ? self::intl($override, (string) ($opt['hint'] ?? ''))
                : self::intl(Notify::whatsappNumberFor($b), (string) ($b['contact_country_code'] ?? ''));
            if ($to === '') {
                $out['error'] = 'no usable WhatsApp number for this booking';
                return $out;
            }
            $pnr  = (string) $b['pnr'];
            $vars = Notify::ticketVars($b);
            $img  = (string) ($vars['7'] ?? Ticket::imageUrl($pnr));
            $co   = Settings::companyFor((string) ($b['counter_code'] ?? ''), (string) ($b['entity'] ?? ''));
            $role = (string) ($opt['role'] ?? 'customer');
            $text = ($role === 'seller' ? "🧾 टिकट कपी · Ticket copy (please forward to the passenger)\n" : '✅ ' . (string) $co['name'] . " — तपाईंको टिकट · Your ticket\n")
                  . 'PNR: ' . $pnr . "\n"
                  . 'Route: ' . (string) ($vars['2'] ?? '-') . "\n"
                  . 'मिति / Date: ' . (string) ($vars['3'] ?? '-') . "\n"
                  . 'चढ्ने / Boarding: ' . (string) ($vars['4'] ?? '-') . "\n"
                  . 'सिट / Seat: ' . (string) ($vars['5'] ?? '-') . "\n"
                  . 'जम्मा / Total: ' . (string) ($vars['6'] ?? '-') . "\n"
                  . 'PDF: ' . Ticket::downloadUrl($pnr) . "\n"
                  . 'सहयोग / Help: ' . (string) ($co['phone'] ?? '');
            $purpose = (string) ($opt['purpose'] ?? ($role === 'seller' ? 'seller_copy' : 'ticket_resend'));
            $bucket  = in_array($purpose, ['ticket', 'seller_copy'], true) ? '' : '|' . intdiv((int) self::now(), 600);
            $msg = [
                'to' => $to, 'name' => (string) ($b['contact_name'] ?? ''), 'body' => $text, 'media_url' => $img, 'media_kind' => 'image',
                'purpose' => $purpose, 'label' => 'Ticket ' . $pnr, 'booking_id' => (int) $b['id'], 'pnr' => $pnr,
                'admin_id' => (int) ($opt['admin_id'] ?? 0), 'source' => (string) ($opt['source'] ?? 'outbox'),
                'no_free' => !empty($opt['no_free']),
                'dedupe' => $to . '|' . $purpose . '|' . (int) $b['id'] . $bucket,
            ];
            $msg += self::templateFor('ticket', $vars, $img, '', 'en');
            $q = self::queue($msg);
            $out = ['state' => $q['state'], 'id' => $q['id'], 'message_id' => $q['wamid'], 'error' => $q['error'], 'how' => '', 'to' => $to, 'text' => $text];
            if ($q['id'] <= 0) {
                return $out;
            }
            if ($q['dupe'] && in_array($q['state'], self::DONE, true)) {
                $out['state'] = 'sent';
                $out['how'] = 'already sent - not sent twice';
                return $out;
            }
            if ($q['state'] === 'queued' && ($opt['now'] ?? true) && self::mayGoNow($purpose)) {
                $r = self::process($q['id']);
                $out['state'] = $r['state'];
                $out['message_id'] = $r['wamid'];
                $out['error'] = $r['error'];
                $out['how'] = $r['how'];
            }
            return $out;
        } catch (Throwable $e) {
            Logger::warning('WaSend ticket failed: ' . $e->getMessage(), ['pnr' => $pnrOrId], 'whatsapp');
            $out['error'] = 'internal error';
            return $out;
        }
    }

    /** The template a queued message may fall back to outside the window. */
    private static function templateFor(string $purpose, array $tplVars, string $media, string $name, string $lang): array
    {
        $ticket = in_array($purpose, ['ticket', 'ticket_update', 'ticket_resend', 'seller_copy'], true);
        if (trim($name) !== '' && $tplVars !== []) {          // the caller's own approved template
            $body = $tplVars;
            unset($body['7']);
            ksort($body, SORT_NATURAL);
            return ['tpl_name' => trim($name), 'tpl_lang' => $lang !== '' ? $lang : 'en', 'tpl_vars' => array_values($body),
                    'tpl_header' => (string) ($tplVars['7'] ?? '')];
        }
        if ($ticket && $tplVars !== []) {
            $t = self::ticketTemplate();
            if ($t['name'] !== '') {
                $body = $tplVars;
                unset($body['7']);
                ksort($body, SORT_NATURAL);
                $hdr = (string) ($tplVars['7'] ?? '');
                return ['tpl_name' => $t['name'], 'tpl_lang' => $t['lang'], 'tpl_vars' => array_values($body),
                        'tpl_header' => $hdr !== '' ? $hdr : (self::mediaKind($media) === 'image' ? $media : '')];
            }
        }
        return [];   // the generic template (if any) is chosen at send time
    }

    /** One message's dedupe key: a ticket once per booking and number; anything else once per 10 minutes. */
    public static function dedupeKey(string $to, string $purpose, ?int $bookingId, string $text, string $media): string
    {
        if (in_array($purpose, ['ticket', 'seller_copy'], true) && $bookingId !== null && $bookingId > 0) {
            return $to . '|' . $purpose . '|' . $bookingId;
        }
        return $to . '|' . $purpose . '|' . (int) $bookingId . '|' . sha1($text . '|' . $media) . '|' . intdiv((int) self::now(), 600);
    }

    /* =================================================================
     *  Sending one row
     * ================================================================= */

    /**
     * Send one queued row now (no pacing check here — drain() / mayGoNow() do that).
     *
     * @return array{state:string, wamid:string, error:string, how:string}
     */
    public static function process(int $id): array
    {
        $res = ['state' => 'failed', 'wamid' => '', 'error' => '', 'how' => ''];
        if (!self::ensure() || $id <= 0) {
            $res['error'] = 'outbox unavailable';
            return $res;
        }
        $now = self::stamp();
        $claimed = Database::run(
            "UPDATE wa_outbox SET state = 'sending', updated_at = :u WHERE id = :i AND state = 'queued' AND next_at <= :n",
            ['u' => $now, 'i' => $id, 'n' => $now]
        ) > 0;
        $row = Database::fetch('SELECT * FROM wa_outbox WHERE id = :i', ['i' => $id]);
        if ($row === null) {
            $res['error'] = 'not found';
            return $res;
        }
        if (!$claimed) {
            return ['state' => (string) $row['state'], 'wamid' => (string) ($row['wamid'] ?? ''), 'error' => (string) ($row['error'] ?? ''), 'how' => 'not queued'];
        }
        // Accepted once already (a retry pressed on a row Meta did take): never twice.
        if ((string) ($row['wamid'] ?? '') !== '') {
            $st = self::ledgerState((string) $row['wamid']);
            if (in_array($st, ['sent', 'delivered', 'read'], true)) {
                self::set($id, ['state' => $st, 'error' => null]);
                return ['state' => $st, 'wamid' => (string) $row['wamid'], 'error' => '', 'how' => 'already accepted - not sent twice'];
            }
        }
        // A first ticket / seller copy another sender already delivered for this booking and number: not again.
        $prior = self::ticketAlreadySent($row);
        if ($prior !== null) {
            self::set($id, ['state' => 'sent', 'wamid' => $prior !== '' ? mb_substr($prior, 0, 190) : null, 'error' => 'already sent by another sender - not sent twice']);
            return ['state' => 'sent', 'wamid' => $prior, 'error' => '', 'how' => 'already sent by another sender - not sent twice'];
        }
        $cap = self::dailyCap();
        if ($cap === 0 || self::sentToday() >= $cap) {
            $tomorrow = date('Y-m-d 00:05:00', (int) self::now() + 86400);
            self::set($id, ['state' => 'queued', 'next_at' => $cap === 0 ? date('Y-m-d H:i:s', (int) self::now() + 900) : $tomorrow,
                            'error' => $cap === 0 ? 'wa_cloud_daily_cap is 0 - outbox paused' : 'daily cap reached (' . $cap . ') - goes tomorrow']);
            return ['state' => 'queued', 'wamid' => '', 'error' => 'daily cap', 'how' => 'held by the daily cap'];
        }

        $to     = (string) $row['to_number'];
        $window = (int) $row['no_free'] !== 1 && trim((string) ($row['body'] ?? '')) !== '' && self::windowOpen($to);
        $tpl    = self::rowTemplate($row);
        $paid   = '';
        if ($tpl['name'] !== '' && !$window) {
            try {
                require_once __DIR__ . '/waroute.php';
                $paid = WaRoute::paidRefusal();
            } catch (Throwable $e) {
                $paid = '';
            }
        }
        $d = self::decide([
            'aisensy' => false, 'fallback_on' => true, 'cloud' => self::cloudConfigured(),
            'window' => $window, 'template' => $tpl['name'], 'paid_refusal' => $paid,
        ]);
        $isSecret = in_array((string) $row['purpose'], self::SECRET, true);
        if ($d['route'] === 'wait' || $d['route'] === 'manual') {
            if ($isSecret) {   // a code never waits for a reply - it would be stale by then
                self::set($id, ['state' => 'cancelled', 'error' => mb_substr('one-time code - not kept waiting: ' . $d['why'], 0, 255)]);
                self::scrub($id);
                return ['state' => 'cancelled', 'wamid' => '', 'error' => $d['why'], 'how' => $d['route']];
            }
            self::set($id, ['state' => 'waiting', 'error' => mb_substr($d['why'], 0, 255)]);
            return ['state' => 'waiting', 'wamid' => '', 'error' => $d['why'], 'how' => $d['route']];
        }

        $payload = $d['route'] === 'cloud_free' ? self::freePayload($row) : self::templatePayload($tpl);
        $r = self::graph($to, $payload);
        self::noteSend();
        $attempts = (int) $row['attempts'] + 1;
        $ok = $r['success'] && $r['message_id'] !== '';

        self::logAttempt($row, $d['route'], $tpl['name'], $r, $ok);

        if ($ok) {
            $done = ['state' => 'sent', 'route' => $d['route'], 'attempts' => $attempts, 'wamid' => mb_substr($r['message_id'], 0, 190),
                     'error' => null, 'sent_at' => self::stamp()];
            if ($isSecret) {
                $done['body'] = self::SCRUBBED;   // never at rest once delivered
                $done['tpl_vars'] = null;
            }
            self::set($id, $done);
            self::afterSent($row, $d['route'], $r['message_id']);
            return ['state' => 'sent', 'wamid' => $r['message_id'], 'error' => '', 'how' => $d['route'] === 'cloud_free' ? 'Cloud API free (24 h window)' : 'Cloud API template ' . $tpl['name']];
        }
        $err = mb_substr(($r['code'] ? '(code ' . $r['code'] . ') ' : '') . ($r['error'] !== '' ? $r['error'] : 'no message id'), 0, 240);
        if ($r['success'] || ($r['http'] >= 200 && $r['http'] < 300)) {
            // Meta answered 2xx without an id: it may have gone. A person decides.
            self::set($id, ['state' => 'unknown', 'route' => $d['route'], 'attempts' => $attempts, 'error' => 'accepted without a message id - check before Retry']);
            if ($isSecret) {
                self::scrub($id);
            }
            return ['state' => 'unknown', 'wamid' => '', 'error' => $err, 'how' => $d['route']];
        }
        if ($d['route'] === 'cloud_free' && in_array($r['code'], self::WINDOW_CODES, true)) {
            // The window had closed after all: the template next (or wait for the person).
            self::set($id, ['state' => 'queued', 'no_free' => 1, 'route' => $d['route'], 'attempts' => $attempts,
                            'next_at' => self::stamp(5), 'error' => mb_substr('window closed: ' . $err, 0, 255)]);
            return ['state' => 'queued', 'wamid' => '', 'error' => $err, 'how' => 'window closed - template next'];
        }
        if (self::transient($r['http'], $r['code']) && $attempts < self::MAX_ATTEMPTS) {
            self::set($id, ['state' => 'queued', 'route' => $d['route'], 'attempts' => $attempts,
                            'next_at' => self::stamp(self::backoff($attempts)), 'error' => mb_substr('retry ' . $attempts . ': ' . $err, 0, 255)]);
            return ['state' => 'queued', 'wamid' => '', 'error' => $err, 'how' => 'will retry'];
        }
        self::set($id, ['state' => 'failed', 'route' => $d['route'], 'attempts' => $attempts, 'error' => $err]);
        if ($isSecret) {
            self::scrub($id);
        }
        return ['state' => 'failed', 'wamid' => '', 'error' => $err, 'how' => $d['route']];
    }

    /**
     * For a FIRST ticket ('ticket') or a seller copy: the wamid / '' of a send
     * that already reached this number for this booking (the "hi" queue, the
     * Meta free route, AiSensy), or null when there is none. A deliberate
     * resend (ticket_resend / ticket_update) is never blocked here.
     */
    private static function ticketAlreadySent(array $row): ?string
    {
        $bid = $row['booking_id'] !== null ? (int) $row['booking_id'] : 0;
        if ($bid <= 0 || !in_array((string) $row['purpose'], ['ticket', 'seller_copy'], true)) {
            return null;
        }
        $to = (string) $row['to_number'];
        try {
            $q = Database::fetch(
                "SELECT wamid FROM wa_ticket_queue WHERE booking_id = :b AND phone = :p AND status IN ('sent','delivered','read') LIMIT 1",
                ['b' => $bid, 'p' => $to]
            );
            $w = $q !== null ? (string) ($q['wamid'] ?? '') : '';
            // …unless that very send was failed by WhatsApp later (the webhook marked its log row).
            if ($q !== null && $w !== (string) ($row['wamid'] ?? '')
                && ($w === '' || !Database::exists("SELECT 1 FROM message_logs WHERE provider_ref = :w AND status = 'failed' LIMIT 1", ['w' => substr($w, 0, 64)]))) {
                return $w;
            }
        } catch (Throwable $e) {
            // queue table not on this server
        }
        try {
            $m = Database::fetch(
                "SELECT provider_ref FROM message_logs
                  WHERE booking_id = :b AND channel = 'whatsapp' AND status = 'sent' AND to_number = :t AND provider_ref <> ''
                    AND purpose IN ('ticket', 'aisensy_ticket', 'seller_copy') ORDER BY id DESC LIMIT 1",
                ['b' => $bid, 't' => $to]
            );
        } catch (Throwable $e) {
            $m = null;
        }
        if ($m !== null && (string) $m['provider_ref'] !== (string) ($row['wamid'] ?? '')) {
            return (string) $m['provider_ref'];
        }
        return null;
    }

    /** @return array{name:string, lang:string, vars:list<string>, header:string} */
    private static function rowTemplate(array $row): array
    {
        $name = trim((string) ($row['tpl_name'] ?? ''));
        if ($name !== '') {
            $vars = json_decode((string) ($row['tpl_vars'] ?? '[]'), true);
            return ['name' => $name, 'lang' => (string) ($row['tpl_lang'] ?? 'en') ?: 'en',
                    'vars' => is_array($vars) ? array_map(static fn($v): string => self::flat((string) $v), $vars) : [],
                    'header' => (string) ($row['tpl_header'] ?? '')];
        }
        $g = self::genericTemplate();
        $body = trim((string) ($row['body'] ?? ''));
        /* The generic utility template carries office alerts only: never a
           code / password (Meta wants AUTHENTICATION templates for those), and
           never a bot / support reply or a hand-typed message - wrapping free
           text in a template outside the 24 h window gets the template paused
           and the number's quality rating lowered. Those wait for a reply. */
        if ($g['name'] === '' || !self::genericAllowed((string) ($row['purpose'] ?? ''), $body)) {
            return ['name' => '', 'lang' => 'en', 'vars' => [], 'header' => ''];
        }
        $flat = self::flat($body . (($row['media_url'] ?? '') !== '' && !str_contains($body, (string) $row['media_url']) ? "\n" . $row['media_url'] : ''));
        $label = trim((string) ($row['label'] ?? '')) !== '' ? (string) $row['label'] : ucfirst(str_replace('_', ' ', (string) $row['purpose']));
        return ['name' => $g['name'], 'lang' => $g['lang'],
                'vars' => [self::flat(mb_substr($label, 0, 60)), $flat], 'header' => ''];
    }

    /** Free text / picture / document inside the window. */
    private static function freePayload(array $row): array
    {
        $text  = trim((string) ($row['body'] ?? ''));
        $media = trim((string) ($row['media_url'] ?? ''));
        $kind  = (string) ($row['media_kind'] ?? '');
        if ($media !== '' && $kind === 'image' && mb_strlen($text) <= 1024) {
            return ['type' => 'image', 'image' => ['link' => $media, 'caption' => $text]];
        }
        if ($media !== '' && $kind === 'document' && mb_strlen($text) <= 1024) {
            $fn = trim((string) ($row['filename'] ?? ''));
            if ($fn === '') {
                $fn = basename((string) parse_url($media, PHP_URL_PATH)) ?: 'document.pdf';
            }
            return ['type' => 'document', 'document' => ['link' => $media, 'filename' => mb_substr($fn, 0, 120), 'caption' => $text]];
        }
        if ($media !== '' && !str_contains($text, $media)) {
            $text .= "\n" . $media;
        }
        return ['type' => 'text', 'text' => ['body' => mb_substr($text, 0, 4096), 'preview_url' => true]];
    }

    /** An approved template (outside the window). */
    private static function templatePayload(array $tpl): array
    {
        $components = [];
        if ($tpl['header'] !== '') {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => $tpl['header']]]]];
        }
        if ($tpl['vars'] !== []) {
            $components[] = ['type' => 'body', 'parameters' => array_map(static fn(string $v): array => ['type' => 'text', 'text' => $v !== '' ? $v : '-'], $tpl['vars'])];
        }
        $t = ['name' => $tpl['name'], 'language' => ['code' => $tpl['lang']]];
        if ($components !== []) {
            $t['components'] = $components;
        }
        return ['type' => 'template', 'template' => $t];
    }

    /**
     * POST one /messages payload. Never throws; the token is never logged or returned.
     *
     * @return array{success:bool, message_id:string, error:string, http:int, code:int}
     */
    private static function graph(string $to, array $payload): array
    {
        $c = self::creds();
        $url = rtrim($c['base'], '/') . '/' . rawurlencode($c['phone_id']) . '/messages';
        $body = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to] + $payload;
        try {
            if (self::$http !== null) {
                $raw = (self::$http)($url, $body);
                $http = (int) ($raw['http'] ?? 0);
                $text = (string) ($raw['body'] ?? '');
                $net  = (string) ($raw['error'] ?? '');
            } else {
                if (!function_exists('curl_init')) {
                    return ['success' => false, 'message_id' => '', 'error' => 'cURL missing', 'http' => 0, 'code' => 0];
                }
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_TIMEOUT        => 25,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $c['token'], 'Content-Type: application/json'],
                    CURLOPT_POSTFIELDS     => (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
                $text = (string) curl_exec($ch);
                $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $net  = (string) curl_error($ch);
                curl_close($ch);
            }
        } catch (Throwable $e) {
            return ['success' => false, 'message_id' => '', 'error' => 'request failed', 'http' => 0, 'code' => 0];
        }
        $j = json_decode($text, true);
        $j = is_array($j) ? $j : [];
        $mid = (string) ($j['messages'][0]['id'] ?? '');
        if ($http >= 200 && $http < 300) {
            return ['success' => true, 'message_id' => $mid, 'error' => $mid === '' ? 'no message id' : '', 'http' => $http, 'code' => 0];
        }
        $err  = (array) ($j['error'] ?? []);
        $code = (int) ($err['code'] ?? 0);
        $msg  = trim((string) ($err['message'] ?? '') . ' ' . (string) ($err['error_data']['details'] ?? ''));
        if ($msg === '') {
            $msg = $http === 0 ? ($net !== '' ? 'network: ' . mb_substr($net, 0, 120) : 'no answer') : 'HTTP ' . $http;
        }
        // A token in an echoed error must never reach a log or a screen.
        if ($c['token'] !== '') {
            $msg = str_replace($c['token'], '[token]', $msg);
        }
        return ['success' => false, 'message_id' => '', 'error' => mb_substr($msg, 0, 300), 'http' => $http, 'code' => $code];
    }

    /** One message_logs row per attempt that reached Meta (msg_ledger copies it). */
    private static function logAttempt(array $row, string $route, string $tplName, array $r, bool $ok): void
    {
        try {
            if (!class_exists('Notify')) {
                require_once __DIR__ . '/notify.php';
            }
            $body = $route === 'cloud_template'
                ? 'Template ' . $tplName . ' (Meta)'            // WaRoute::stats() counts "(Meta)" templates as paid
                : (string) ($row['body'] ?? '');
            $purpose = (string) $row['purpose'];
            if (in_array($purpose, self::SECRET, true)) {
                $body = preg_replace('/\d{4,8}/u', '••••', $body) ?? $body;
            }
            $body = self::maskSecrets($body);   // a code / PIN / password in any message, whatever its purpose
            Notify::logOutbound((string) $row['to_number'], $body, $ok ? 'sent' : 'failed', [
                'provider'  => 'cloud_api',
                'bookingId' => $row['booking_id'] !== null ? (int) $row['booking_id'] : null,
                'sid'       => $ok ? $r['message_id'] : '',
                'purpose'   => $purpose,
                'admin_id'  => $row['admin_id'] !== null ? (int) $row['admin_id'] : null,
                'media_url' => (string) ($row['media_url'] ?? '') !== '' ? (string) $row['media_url'] : null,
                'error'     => $ok ? null : mb_substr('Cloud API (outbox #' . (int) $row['id'] . '): ' . ($r['code'] ? '(code ' . $r['code'] . ') ' : '') . $r['error'], 0, 250),
            ]);
        } catch (Throwable $e) {
            // a log row never breaks a send
        }
    }

    /** Counters and the "hi" queue after an accepted send. */
    private static function afterSent(array $row, string $route, string $wamid): void
    {
        try {
            require_once __DIR__ . '/waroute.php';
            WaRoute::count($route === 'cloud_free' ? 'free' : 'meta_tpl');   // meta_tpl feeds the daily paid cap
        } catch (Throwable $e) {
        }
        $bid = $row['booking_id'] !== null ? (int) $row['booking_id'] : 0;
        if ($bid <= 0 || !in_array((string) $row['purpose'], ['ticket', 'ticket_resend', 'ticket_update', 'seller_copy'], true)) {
            return;
        }
        try {
            if (!class_exists('WaQueue') && is_file(__DIR__ . '/waqueue.php')) {
                require_once __DIR__ . '/waqueue.php';
            }
            if (class_exists('WaQueue') && WaQueue::ready()) {
                $pnr = (string) ($row['pnr'] ?? '');
                if ($pnr === '') {
                    $pnr = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :b', ['b' => $bid], '');
                }
                if ($pnr !== '') {
                    WaQueue::recordSent($bid, $pnr, (string) $row['to_number'], (string) $row['purpose'] === 'seller_copy' ? 'seller' : 'customer',
                        $route === 'cloud_free' ? 'meta cloud' : 'meta tpl', $wamid);
                }
            }
        } catch (Throwable $e) {
        }
    }

    /* =================================================================
     *  Pacing
     * ================================================================= */

    /** May this message go right now (gap + daily cap)? A web request never waits; the CLI sleeps the gap. */
    public static function mayGoNow(string $purpose): bool
    {
        $cap = self::dailyCap();
        if ($cap === 0 || self::sentToday() >= $cap) {
            return false;
        }
        $web = PHP_SAPI !== 'cli';
        if ($web && in_array($purpose, self::PRIORITY, true)) {
            return true;      // one person is waiting on this ticket / code
        }
        $wait = self::waitSeconds();
        if ($wait <= 0.0) {
            return true;
        }
        if ($web) {
            return false;     // paced: the outbox sends it in turn
        }
        self::sleep($wait);
        return true;
    }

    /** Seconds until the gap since the last send has passed (0 = go). */
    public static function waitSeconds(): float
    {
        $last = (float) self::kvGet('last_at', '0');
        return max(0.0, $last + self::gapSeconds() - self::now());
    }

    private static function noteSend(): void
    {
        self::kvSet('last_at', sprintf('%.3f', self::now()));
        $k = date('Y-m-d', (int) self::now()) . ':n';
        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_outbox', :k, '1', 'wasend')
                 ON DUPLICATE KEY UPDATE kvalue = CAST(kvalue AS UNSIGNED) + 1, updated_by = 'wasend'",
                ['k' => $k]
            );
        } catch (Throwable $e) {
        }
    }

    /**
     * Send what is due, one at a time with the gap between, within a time
     * budget. Cron (and the outbox page's "Send queued now") call this.
     *
     * @return array{sent:int, failed:int, waiting:int, queued:int, refreshed:int, woken:int, expired:int, locked:int}
     */
    public static function drain(int $max = 10, int $budgetSec = 50): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'waiting' => 0, 'queued' => 0, 'refreshed' => 0, 'woken' => 0, 'expired' => 0, 'locked' => 0];
        if (!self::ensure()) {
            return $out;
        }
        $lock = false;
        try {
            $lock = (int) Database::scalar("SELECT GET_LOCK('shg_wa_outbox', 0)", [], 0) === 1;
        } catch (Throwable $e) {
            $lock = true;   // no named locks on this server: carry on, the row claim still prevents doubles
        }
        if (!$lock) {
            $out['locked'] = 1;
            return $out;
        }
        $t0 = self::now();
        try {
            $out['refreshed'] = self::refresh();
            [$out['woken'], $out['expired']] = self::wake();
            if (!self::cloudConfigured() || !self::fallbackOn()) {
                return $out;
            }
            $prio = "'" . implode("','", self::PRIORITY) . "'";
            $rows = Database::fetchAll(
                "SELECT id FROM wa_outbox WHERE state = 'queued' AND next_at <= :n
                  ORDER BY (purpose IN ($prio)) DESC, id ASC LIMIT " . max(1, min(100, $max)),
                ['n' => self::stamp()]
            );
            foreach ($rows as $r) {
                $cap = self::dailyCap();
                if ($cap === 0 || self::sentToday() >= $cap) {
                    break;
                }
                $wait = self::waitSeconds();
                if ($wait > 0.0) {
                    if (self::now() - $t0 + $wait > $budgetSec) {
                        break;
                    }
                    self::sleep($wait);
                }
                $res = self::process((int) $r['id']);
                if (isset($out[$res['state']])) {
                    $out[$res['state']]++;
                }
            }
        } catch (Throwable $e) {
            Logger::warning('WaSend drain failed: ' . $e->getMessage(), [], 'whatsapp');
        } finally {
            try {
                Database::scalar("SELECT RELEASE_LOCK('shg_wa_outbox')", [], 0);
            } catch (Throwable $e) {
            }
        }
        try {
            if (!class_exists('MsgLedger') && is_file(__DIR__ . '/msgledger.php')) {
                require_once __DIR__ . '/msgledger.php';
            }
            if (class_exists('MsgLedger') && MsgLedger::ready() && $out['sent'] + $out['failed'] > 0) {
                MsgLedger::sync(300);
            }
        } catch (Throwable $e) {
        }
        return $out;
    }

    /**
     * Waiting rows whose window opened (or a template became available) go
     * back in the queue; waits older than WAIT_HOURS fail; a send that died
     * mid-way (sending > 10 min) becomes "unknown" for a person to check.
     *
     * @return array{0:int, 1:int} woken, expired
     */
    public static function wake(): array
    {
        $woken = 0;
        $expired = 0;
        try {
            $now = self::stamp();
            Database::run(
                "UPDATE wa_outbox SET state = 'unknown', error = 'send interrupted - check the WhatsApp log before Retry', updated_at = :u
                  WHERE state = 'sending' AND updated_at < :t",
                ['u' => $now, 't' => self::stamp(-600)]
            );
            $expired = Database::run(
                "UPDATE wa_outbox SET state = 'failed', error = :e, updated_at = :u WHERE state = 'waiting' AND created_at < :t",
                ['e' => 'no reply window in ' . self::WAIT_HOURS . ' h and no template - send it by wa.me', 'u' => $now, 't' => self::stamp(-self::WAIT_HOURS * 3600)]
            );
            self::scrub();   // a code that waits / expired / failed keeps no text
            /* One ticket per booking + number wakes: the newest waiting one.
               Older copies (rows from before the open-row check in queue())
               are cancelled, so a "hi" never releases a burst of tickets. */
            $fam = "'" . implode("','", self::TICKET_FAMILY) . "'";
            Database::run(
                "UPDATE wa_outbox o
                   JOIN (SELECT booking_id, to_number, MAX(id) AS mx FROM wa_outbox
                          WHERE state IN ('queued','sending','waiting') AND booking_id IS NOT NULL AND purpose IN ($fam)
                          GROUP BY booking_id, to_number) k
                     ON k.booking_id = o.booking_id AND k.to_number = o.to_number
                    SET o.state = 'cancelled', o.error = 'duplicate ticket - a newer copy carries it', o.updated_at = :u
                  WHERE o.state = 'waiting' AND o.purpose IN ($fam) AND o.id < k.mx",
                ['u' => $now]
            );
            $generic = self::genericTemplate()['name'] !== '';
            foreach (Database::fetchAll("SELECT id, to_number, no_free, tpl_name, body, purpose FROM wa_outbox WHERE state = 'waiting' ORDER BY id LIMIT 200") as $w) {
                $hasTpl = trim((string) ($w['tpl_name'] ?? '')) !== ''
                    || ($generic && self::genericAllowed((string) $w['purpose'], trim((string) ($w['body'] ?? ''))));
                $open   = (int) $w['no_free'] !== 1 && self::windowOpen((string) $w['to_number']);
                $paid   = '';
                if (!$open && $hasTpl) {
                    try {
                        require_once __DIR__ . '/waroute.php';
                        $paid = WaRoute::paidRefusal();
                    } catch (Throwable $e) {
                    }
                }
                if ($open || ($hasTpl && $paid === '')) {
                    $woken += Database::run(
                        "UPDATE wa_outbox SET state = 'queued', next_at = :n, updated_at = :u WHERE id = :i AND state = 'waiting'",
                        ['n' => $now, 'u' => $now, 'i' => (int) $w['id']]
                    );
                }
            }
        } catch (Throwable $e) {
            Logger::warning('WaSend wake failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        return [$woken, $expired];
    }

    /**
     * Delivered / read / failed from the webhook (msg_ledger, message_logs)
     * onto the outbox rows of the last three days. A free message that
     * failed because the window had closed goes once more on the template.
     */
    public static function refresh(int $limit = 200): int
    {
        $n = 0;
        try {
            $rows = Database::fetchAll(
                "SELECT id, wamid, state, route, no_free, tpl_name, body, purpose FROM wa_outbox
                  WHERE state IN ('sent','delivered') AND wamid IS NOT NULL AND updated_at >= :t
                  ORDER BY id DESC LIMIT " . max(1, min(1000, $limit)),
                ['t' => self::stamp(-3 * 86400)]
            );
            $generic = self::genericTemplate()['name'] !== '';
            foreach ($rows as $r) {
                [$st, $err] = self::providerState((string) $r['wamid']);
                if ($st === '' || $st === (string) $r['state'] || ($st === 'sent') || ($r['state'] === 'delivered' && $st !== 'read' && $st !== 'failed')) {
                    continue;
                }
                $hasTpl = trim((string) ($r['tpl_name'] ?? '')) !== ''
                    || ($generic && self::genericAllowed((string) $r['purpose'], trim((string) ($r['body'] ?? ''))));
                if ($st === 'failed' && $r['route'] === 'cloud_free' && (int) $r['no_free'] !== 1 && $hasTpl
                    && (str_contains($err, '131047') || stripos($err, 're-engage') !== false)) {
                    $n += Database::run(
                        "UPDATE wa_outbox SET state = 'queued', no_free = 1, wamid = NULL, next_at = :x, updated_at = :u,
                                error = :e WHERE id = :i AND state = 'sent'",
                        ['x' => self::stamp(), 'u' => self::stamp(), 'e' => mb_substr('window closed (131047) - template next', 0, 255), 'i' => (int) $r['id']]
                    );
                    continue;
                }
                $n += self::set((int) $r['id'], ['state' => $st, 'error' => $st === 'failed' ? mb_substr($err !== '' ? $err : 'failed (WhatsApp)', 0, 255) : null]);
            }
        } catch (Throwable $e) {
            Logger::warning('WaSend refresh failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        return $n;
    }

    /**
     * The webhook says Meta failed one of OUR sends later (called by
     * WaQueue::onMetaFailed). A free message whose window had closed goes
     * once more on the template; anything else is marked failed. Returns
     * null when the wamid is not an outbox send, else the row's new state.
     */
    public static function metaFailed(string $wamid, int $code, string $error = ''): ?string
    {
        if ($wamid === '' || !self::ensure()) {
            return null;
        }
        $r = Database::fetch('SELECT * FROM wa_outbox WHERE wamid = :w ORDER BY id DESC LIMIT 1', ['w' => $wamid]);
        if ($r === null) {
            return null;
        }
        if (!in_array((string) $r['state'], ['sent', 'delivered'], true)) {
            return (string) $r['state'];
        }
        $hasTpl = trim((string) ($r['tpl_name'] ?? '')) !== ''
            || (self::genericTemplate()['name'] !== '' && self::genericAllowed((string) $r['purpose'], trim((string) ($r['body'] ?? ''))));
        if (in_array($code, self::WINDOW_CODES, true) && $r['route'] === 'cloud_free' && (int) $r['no_free'] !== 1 && $hasTpl) {
            Database::run(
                "UPDATE wa_outbox SET state = 'queued', no_free = 1, wamid = NULL, next_at = :x, updated_at = :u, error = :e WHERE id = :i",
                ['x' => self::stamp(), 'u' => self::stamp(), 'e' => 'window closed (code ' . $code . ') - template next', 'i' => (int) $r['id']]
            );
            if (self::mayGoNow((string) $r['purpose'])) {
                return self::process((int) $r['id'])['state'];
            }
            return 'queued';
        }
        self::set((int) $r['id'], ['state' => 'failed', 'error' => mb_substr('WhatsApp failed (code ' . $code . ')' . ($error !== '' ? ' ' . $error : ''), 0, 255)]);
        return 'failed';
    }

    /**
     * What WhatsApp said about a message: the ledger's delivered / read,
     * else message_logs' failed. '' when nothing is known yet.
     *
     * @return array{0:string, 1:string} state, error
     */
    private static function providerState(string $wamid): array
    {
        $st = self::ledgerState($wamid);
        if (in_array($st, ['delivered', 'read', 'failed'], true)) {
            $err = '';
            if ($st === 'failed') {
                try {
                    $err = (string) Database::scalar('SELECT error FROM msg_ledger WHERE provider_ref = :w', ['w' => $wamid], '');
                } catch (Throwable $e) {
                }
            }
            return [$st, $err];
        }
        try {
            $m = Database::fetch('SELECT status, error FROM message_logs WHERE provider_ref = :w ORDER BY id DESC LIMIT 1', ['w' => substr($wamid, 0, 64)]);
        } catch (Throwable $e) {
            $m = null;
        }
        if ($m !== null && $m['status'] === 'failed') {
            return ['failed', (string) ($m['error'] ?? '')];
        }
        return ['', ''];
    }

    /** msg_ledger's state for a wamid ('' when not there). */
    private static function ledgerState(string $wamid): string
    {
        try {
            $s = (string) Database::scalar('SELECT status FROM msg_ledger WHERE provider_ref = :w', ['w' => $wamid], '');
        } catch (Throwable $e) {
            return '';
        }
        return $s === 'accepted' ? 'sent' : $s;
    }

    /* =================================================================
     *  Office actions (admin/wa-outbox.php)
     * ================================================================= */

    /** Retry a failed / unknown / waiting row now (a person pressed it). Never a delivered one. */
    public static function retry(int $id): bool
    {
        if (!self::ensure()) {
            return false;
        }
        $row = Database::fetch('SELECT state, wamid, purpose FROM wa_outbox WHERE id = :i', ['i' => $id]);
        if ($row === null || !in_array((string) $row['state'], ['failed', 'unknown', 'waiting'], true)) {
            return false;
        }
        if (in_array((string) $row['purpose'], self::SECRET, true)) {
            return false;   // its code is scrubbed (and stale): the person asks for a new one
        }
        if ((string) ($row['wamid'] ?? '') !== '' && in_array(self::ledgerState((string) $row['wamid']), ['delivered', 'read'], true)) {
            return false;
        }
        return Database::run(
            "UPDATE wa_outbox SET state = 'queued', next_at = :n, updated_at = :u, attempts = LEAST(attempts, :m) WHERE id = :i AND state IN ('failed','unknown','waiting')",
            ['n' => self::stamp(), 'u' => self::stamp(), 'm' => self::MAX_ATTEMPTS - 1, 'i' => $id]
        ) > 0;
    }

    public static function cancel(int $id): bool
    {
        if (!self::ensure()) {
            return false;
        }
        $ok = Database::run(
            "UPDATE wa_outbox SET state = 'cancelled', error = 'cancelled by the office', updated_at = :u WHERE id = :i AND state IN ('queued','waiting','failed','unknown')",
            ['u' => self::stamp(), 'i' => $id]
        ) > 0;
        if ($ok) {
            self::scrub($id);
        }
        return $ok;
    }

    /** @return array{ok:bool, id:int, error:string} */
    public static function saveContact(string $name, string $phone, string $hint = '', string $note = '', int $adminId = 0): array
    {
        if (!self::ensure()) {
            return ['ok' => false, 'id' => 0, 'error' => 'contacts table unavailable'];
        }
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        $to   = self::intl($phone, $hint);
        if ($name === '' || mb_strlen($name) > 120) {
            return ['ok' => false, 'id' => 0, 'error' => 'name is required (max 120 characters)'];
        }
        if ($to === '') {
            return ['ok' => false, 'id' => 0, 'error' => 'not a usable India / Nepal WhatsApp number'];
        }
        $now = self::stamp();
        Database::run(
            'INSERT INTO wa_contacts (name, phone, note, created_by, created_at, updated_at) VALUES (:n, :p, :o, :c, :t, :u)
             ON DUPLICATE KEY UPDATE name = VALUES(name), note = VALUES(note), updated_at = VALUES(updated_at)',
            ['n' => $name, 'p' => $to, 'o' => self::cut($note, 255), 'c' => $adminId > 0 ? $adminId : null, 't' => $now, 'u' => $now]
        );
        return ['ok' => true, 'id' => (int) Database::scalar('SELECT id FROM wa_contacts WHERE phone = :p', ['p' => $to], 0), 'error' => ''];
    }

    public static function deleteContact(int $id): bool
    {
        return self::ensure() && Database::run('DELETE FROM wa_contacts WHERE id = :i', ['i' => $id]) > 0;
    }

    /** @return list<array<string,mixed>> */
    public static function contacts(string $q = '', int $limit = 200): array
    {
        if (!self::ensure()) {
            return [];
        }
        $q = trim($q);
        if ($q === '') {
            return Database::fetchAll('SELECT * FROM wa_contacts ORDER BY name ASC LIMIT ' . max(1, min(1000, $limit)));
        }
        $digits = preg_replace('/\D+/', '', $q) ?? '';
        return Database::fetchAll(
            'SELECT * FROM wa_contacts WHERE name LIKE :q OR note LIKE :q2' . ($digits !== '' ? ' OR phone LIKE :d' : '') . ' ORDER BY name ASC LIMIT ' . max(1, min(1000, $limit)),
            ['q' => '%' . $q . '%', 'q2' => '%' . $q . '%'] + ($digits !== '' ? ['d' => '%' . $digits . '%'] : [])
        );
    }

    /* =================================================================
     *  Small helpers
     * ================================================================= */

    /** 'image' / 'document' / '' from a URL. */
    public static function mediaKind(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }
        if (preg_match('#\.pdf(\?|$)|[?&]f=[^&]*\.pdf|[?&]pdf=#i', $url) === 1) {
            return 'document';
        }
        if (preg_match('#\.(png|jpe?g|webp)(\?|$)|[?&]img=1|ticket|seatmap|pay-image|report-chart|offer-card|[?&]png=#i', $url) === 1) {
            return 'image';
        }
        return '';
    }

    /** A template variable: one line, no 4+ spaces, Meta's length limit. */
    public static function flat(string $s): string
    {
        $s = trim((string) preg_replace('/\s*\R\s*/u', ' · ', $s));
        $s = (string) preg_replace('/[ \t]{4,}/', '   ', $s);
        return mb_substr($s, 0, 900);
    }

    private static function cut(string $s, int $max): ?string
    {
        $s = trim($s);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private static function set(int $id, array $data): int
    {
        $data['updated_at'] = self::stamp();
        try {
            return Database::update('wa_outbox', $data, 'id = :id', ['id' => $id]);
        } catch (Throwable $e) {
            Logger::warning('wa_outbox update failed: ' . $e->getMessage(), ['id' => $id], 'whatsapp');
            return 0;
        }
    }

    public static function now(): float
    {
        return self::$clock !== null ? (float) (self::$clock)() : microtime(true);
    }

    /** 'Y-m-d H:i:s' at now + $offset seconds. */
    private static function stamp(int $offset = 0): string
    {
        return date('Y-m-d H:i:s', (int) self::now() + $offset);
    }

    private static function sleep(float $sec): void
    {
        if ($sec <= 0) {
            return;
        }
        if (self::$sleeper !== null) {
            (self::$sleeper)($sec);
            return;
        }
        usleep((int) min(60_000_000, $sec * 1_000_000));
    }

    private static function kvGet(string $key, string $default = ''): string
    {
        try {
            $v = Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_outbox' AND kkey = :k", ['k' => $key], null);
            return $v === null ? $default : (string) $v;
        } catch (Throwable $e) {
            return $default;
        }
    }

    private static function kvSet(string $key, string $value): void
    {
        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_outbox', :k, :v, 'wasend')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = 'wasend'",
                ['k' => $key, 'v' => $value]
            );
        } catch (Throwable $e) {
        }
    }

    /** Figures for the outbox page. @return array<string,int> state => count (last 14 days) */
    public static function counts(): array
    {
        if (!self::ensure()) {
            return [];
        }
        $out = [];
        foreach (Database::fetchAll('SELECT state, COUNT(*) n FROM wa_outbox WHERE created_at >= :t GROUP BY state', ['t' => self::stamp(-14 * 86400)]) as $r) {
            $out[(string) $r['state']] = (int) $r['n'];
        }
        return $out;
    }
}
