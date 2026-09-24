<?php
/**
 * =====================================================================
 *  Notify — email and WhatsApp delivery.
 *
 *  Two channels, both provider-agnostic and both safe to call even when
 *  nothing is configured (they degrade to a no-op or a click-to-chat
 *  link rather than throwing).
 *
 *   Email    : native SMTP over fsockopen — no PHPMailer, no Composer.
 *               Falls back to PHP mail() when SMTP is not configured.
 *   WhatsApp : returns a wa.me click-to-chat link by default; if a
 *               Cloud API token is set in settings it posts through it.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

// Ticket::downloadUrl() builds the signed ticket links this class puts in
// messages (§25), so it is a hard dependency rather than something every
// caller must remember. cron/reminders.php loads notify.php WITHOUT
// ticket.php, and a missing class there would silently kill trip reminders.
require_once __DIR__ . '/ticket.php';

// Calendar builds the .ics attachment bookingConfirmed() emails carry —
// same reasoning: a hard dependency here, so no caller can miss it.
require_once __DIR__ . '/calendar.php';

final class Notify
{
    /**
     * Every whatsapp() outcome of THIS request, oldest first (6 Sep 2026).
     * bookingConfirmed() returns void and runs inside the EventBus fan-out,
     * so a caller that needs to know whether the passenger's ticket actually
     * went (Quick Ticket's desk screen) reads it back from here. Never
     * persisted; a request-scoped journal only.
     *
     * @var list<array{to: string, ok: bool, link: ?string, driver: string, reason: string}>
     */
    private static array $journal = [];

    /**
     * Why the provider refused the LAST send, in the provider's own words.
     * whatsappCloudApi() returns a bool, so until 19 Sep 2026 Meta's reason
     * ("template name (x) does not exist in hi") was thrown away and the
     * ledger said only "provider refused the send" - 60 refused tickets in a
     * day with nothing on any screen saying why. Reset before each attempt.
     */
    private static string $lastProviderError = '';

    /** @return list<array{to: string, ok: bool, link: ?string, driver: string, reason: string}> */
    public static function whatsappJournal(): array
    {
        return self::$journal;
    }

    /**
     * The international digits (no "+") a booking's contact number resolves
     * to for WhatsApp — the same resolution every send uses, so a screen can
     * print "+9779812345678" before or without sending.
     */
    public static function whatsappNumberFor(array $booking): string
    {
        return self::intlDigits((string) ($booking['contact_phone'] ?? ''), self::countryHint($booking));
    }

    /* =================================================================
     *  Email
     * ================================================================= */

    /**
     * Send an HTML email. Returns true on apparent success.
     *
     * Never throws — a mail failure is logged and swallowed so it can
     * never break a booking.
     *
     * @param list<array{name: string, mime: string, data: string}> $attachments
     *        raw file bytes to attach (.ics, PDF). Only the SMTP path can
     *        carry them; the mail() fallback sends the body without them.
     */
    public static function email(string $toEmail, string $subject, string $htmlBody, string $textBody = '', array $attachments = []): bool
    {
        if (!Settings::getBool('email_enabled', true)) {
            return false;
        }

        $toEmail = Security::email($toEmail);
        if ($toEmail === '') {
            return false;
        }

        $host = Settings::getString('smtp_host', '');

        try {
            if ($host !== '') {
                return self::smtpSend($toEmail, $subject, $htmlBody, $textBody, $attachments);
            }
            return self::mailSend($toEmail, $subject, $htmlBody);
        } catch (Throwable $e) {
            Logger::error('Email send failed: ' . $e->getMessage(), ['to' => $toEmail], 'mail');
            return false;
        }
    }

    private static function mailSend(string $to, string $subject, string $htmlBody): bool
    {
        $fromName  = Settings::getString('mail_from_name', APP_NAME);
        $fromEmail = Settings::getString('company_email', 'no-reply@' . self::domain());

        $headers  = 'MIME-Version: 1.0' . "\r\n";
        $headers .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $headers .= 'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>' . "\r\n";
        $headers .= 'Reply-To: ' . $fromEmail . "\r\n";

        return @mail($to, self::encodeHeader($subject), $htmlBody, $headers);
    }

    /**
     * Minimal SMTP client (AUTH LOGIN, STARTTLS or implicit SSL).
     */
    private static function smtpSend(string $to, string $subject, string $htmlBody, string $textBody, array $attachments = []): bool
    {
        $host   = Settings::getString('smtp_host', '');
        $port   = Settings::getInt('smtp_port', 587);
        $user   = Settings::getString('smtp_user', '');
        $pass   = Settings::getString('smtp_pass', '');
        $secure = Settings::getString('smtp_secure', 'tls');
        $fromName  = Settings::getString('mail_from_name', APP_NAME);
        $fromEmail = $user !== '' ? $user : Settings::getString('company_email', 'no-reply@' . self::domain());

        $transport = $secure === 'ssl' ? 'ssl://' : '';
        $socket = @fsockopen($transport . $host, $port, $errno, $errstr, 15);

        if ($socket === false) {
            Logger::error("SMTP connect failed: $errstr ($errno)", ['host' => $host], 'mail');
            return false;
        }

        $read = static function () use ($socket): string {
            $data = '';
            while (($line = fgets($socket, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $write = static function (string $cmd) use ($socket): void {
            fputs($socket, $cmd . "\r\n");
        };

        $read();
        $write('EHLO ' . self::domain());
        $read();

        if ($secure === 'tls') {
            $write('STARTTLS');
            $read();
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return false;
            }
            $write('EHLO ' . self::domain());
            $read();
        }

        if ($user !== '') {
            $write('AUTH LOGIN');
            $read();
            $write(base64_encode($user));
            $read();
            $write(base64_encode($pass));
            $authResp = $read();
            if (!str_starts_with(trim($authResp), '235')) {
                Logger::error('SMTP auth failed', ['resp' => trim($authResp)], 'mail');
                fclose($socket);
                return false;
            }
        }

        $write('MAIL FROM:<' . $fromEmail . '>');
        $read();
        $write('RCPT TO:<' . $to . '>');
        $read();
        $write('DATA');
        $read();

        $boundary = 'shg' . bin2hex(random_bytes(8));
        $message  = 'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>' . "\r\n";
        $message .= 'To: <' . $to . '>' . "\r\n";
        $message .= 'Subject: ' . self::encodeHeader($subject) . "\r\n";
        $message .= 'MIME-Version: 1.0' . "\r\n";

        // The text+html pair always travels as multipart/alternative; with
        // attachments that whole pair becomes the first part of an outer
        // multipart/mixed envelope.
        $mixedBoundary = 'shgmix' . bin2hex(random_bytes(8));
        if ($attachments !== []) {
            $message .= 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"' . "\r\n\r\n";
            $message .= '--' . $mixedBoundary . "\r\n";
        }
        $message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n";

        // Both body parts are base64-encoded: wrapEmail() emits the whole HTML
        // document as one ~2 KB line, which violates SMTP's 998-octet line
        // limit (some MTAs wrap or reject it), and the bodies carry raw UTF-8
        // (₹, emoji, Devanagari) with no 8BITMIME negotiated. base64 with
        // 76-char chunks fixes both, and matches the attachment parts below.
        $plain = $textBody !== '' ? $textBody : strip_tags($htmlBody);
        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $message .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $message .= chunk_split(base64_encode($plain), 76, "\r\n") . "\r\n";

        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $message .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
        $message .= chunk_split(base64_encode($htmlBody), 76, "\r\n") . "\r\n";
        $message .= '--' . $boundary . '--' . "\r\n";

        foreach ($attachments as $att) {
            $name = preg_replace('/[^\w.\- ]/', '_', (string) ($att['name'] ?? 'file')) ?: 'file';
            $mime = (string) ($att['mime'] ?? 'application/octet-stream');
            $data = (string) ($att['data'] ?? '');
            if ($data === '') {
                continue;
            }
            $message .= '--' . $mixedBoundary . "\r\n";
            $message .= 'Content-Type: ' . $mime . '; name="' . $name . '"' . "\r\n";
            $message .= 'Content-Transfer-Encoding: base64' . "\r\n";
            $message .= 'Content-Disposition: attachment; filename="' . $name . '"' . "\r\n\r\n";
            $message .= chunk_split(base64_encode($data), 76, "\r\n") . "\r\n";
        }
        if ($attachments !== []) {
            $message .= '--' . $mixedBoundary . '--' . "\r\n";
        }

        // Dot-stuffing so a line that is just "." cannot end DATA early.
        $message = preg_replace('/^\./m', '..', $message) ?? $message;

        $write($message . "\r\n.");
        $finalResp = $read();

        $write('QUIT');
        fclose($socket);

        return str_starts_with(trim($finalResp), '250');
    }

    private static function encodeHeader(string $text): string
    {
        if (preg_match('/[^\x20-\x7E]/', $text)) {
            return '=?UTF-8?B?' . base64_encode($text) . '?=';
        }
        return $text;
    }

    private static function domain(): string
    {
        $host = parse_url(APP_URL, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : 'localhost';
    }


    /* =================================================================
     *  WhatsApp
     * ================================================================= */

    /**
     * Deliver a WhatsApp message, optionally with a media attachment
     * (e.g. the ticket PDF, passed as a public URL the provider fetches).
     *
     * When a driver (Twilio or the Meta Cloud API) is configured it is used
     * and true/false is returned. Otherwise a wa.me click-to-chat URL is
     * returned as a string so the caller (admin panel) can open it in one
     * tap.
     *
     * @param string      $phone       number in any format; a country code is
     *                                 added when the number is a bare local one
     * @param string      $message     the text / caption
     * @param string|null $mediaUrl    public URL of a file to attach (PDF, image)
     * @param string|null $countryHint 'NP'/'IN' to resolve a bare 10-digit
     *                                 number to the right country (else the
     *                                 configured default is used)
     * @param array<string,string> $templateVars variables for the approved
     *                                 WhatsApp template; used only when
     *                                 twilio_content_sid is set, otherwise the
     *                                 free-form $message is sent as before
     * @return bool|string true/false for API delivery, or a wa.me URL
     */
    public static function whatsapp(string $phone, string $message, ?string $mediaUrl = null, ?string $countryHint = null, array $templateVars = [], ?int $bookingId = null, array $meta = []): bool|string
    {
        $driver = Settings::getString('whatsapp_driver', 'click_to_chat');
        $number = self::intlDigits($phone, $countryHint);
        $paused = false;
        $ref    = null;   // Twilio message SID, filled by whatsappTwilio()

        if ($number === '') {
            self::$journal[] = ['to' => '', 'ok' => false, 'link' => null, 'driver' => $driver, 'reason' => 'number'];
            self::logMessage('whatsapp', (string) $phone, $message, 'skipped', [
                'provider' => $driver, 'bookingId' => $bookingId,
                'error'    => 'no usable phone number on the booking',
            ] + $meta);
            return false;
        }

        /* Idempotency guard (21 Sep 2026): a confirmed booking must never
           be handed the same ticket twice. bookingConfirmed() runs inside
           the EventBus fan-out, so if a payment webhook re-fires (Razorpay
           duplicate delivery) or an admin presses "Resend WhatsApp ticket"
           on a booking whose confirmation went out cleanly, this returns
           the earlier success without touching the driver again. Applies
           ONLY to purpose=ticket: staff tools (agent statements, reminders)
           deliberately re-send. The manual Resend button uses
           resendTicketWhatsApp() and passes purpose=ticket, so it is also
           protected — a customer who actually needs a resend is served
           either by cron/whatsapp-retry.php (only fires on a FAILED latest
           row) or by the button after the earlier row is marked failed by
           the delivery callback. */
        if (($meta['purpose'] ?? '') === 'ticket' && $bookingId && (int) $bookingId > 0) {
            try {
                $prior = Database::fetch(
                    "SELECT status FROM message_logs
                      WHERE channel = 'whatsapp' AND booking_id = :b
                        AND (purpose IS NULL OR purpose = 'ticket')
                      ORDER BY id DESC LIMIT 1",
                    ['b' => (int) $bookingId]
                );
                if ($prior !== null && ($prior['status'] ?? '') === 'sent') {
                    self::$journal[] = ['to' => $number, 'ok' => true, 'link' => null, 'driver' => $driver, 'reason' => 'idempotent'];
                    return true;
                }
            } catch (Throwable $ignored) {
                // Idempotency is a guard, not a hard requirement — if the
                // check itself falls over (missing `purpose` column on an
                // older DB, or a transient MySQL hiccup) fall through to
                // the normal send path and let the driver do its work.
            }
        }

        if ($driver === 'twilio') {
            $sid   = Settings::getString('twilio_account_sid', '');
            $token = Settings::getString('twilio_auth_token', '');
            $from  = Settings::getString('twilio_whatsapp_from', '');

            if ($sid !== '' && $token !== '' && $from !== '') {
                /* Sender breaker (7 Sep 2026): while Twilio refuses the whole
                   ACCOUNT (Trust Hub KYC pending, wrong credentials, account
                   suspended) every send would still cost a ~300 ms round
                   trip and a 401 in the log — inside the QuickBot sale, on
                   the passenger's clock. After one such refusal the sender is
                   paused for TWILIO_PAUSE_SEC and messages go straight to the
                   click-to-chat fallback; it retries by itself when the pause
                   lapses, so an approved KYC is picked up within minutes with
                   nothing to configure. Per-message errors (bad number,
                   sandbox not joined) never trip it. */
                if (self::twilioPause() !== null) {
                    $paused = true;
                } elseif (self::whatsappTwilio($number, $message, $mediaUrl, $sid, $token, $from, $templateVars, $ref, isset($meta['content_sid']) ? (string) $meta['content_sid'] : null)) {
                    self::$journal[] = ['to' => $number, 'ok' => true, 'link' => null, 'driver' => 'twilio', 'reason' => ''];
                    // "accepted", not "delivered": WhatsApp decides later and
                    // api/twilio-status.php flips this row to failed when it
                    // does. provider_ref is the Twilio message SID — the key
                    // that callback matches on, so it must be stored here.
                    self::logMessage('whatsapp', $number, $message, 'sent', [
                        'provider' => 'twilio', 'bookingId' => $bookingId, 'sid' => (string) $ref,
                    ] + $meta);
                    return true;
                }
                // Delivery failed — fall through to the click-to-chat link
                // below rather than dropping the ticket silently. This is not
                // hypothetical: a Twilio TRIAL account rejects both the media
                // attachment ("trial accounts have limited parameter access")
                // and the plain Body (21654 "ContentSid Required"), so every
                // confirmation would vanish with only a log line. The exact
                // provider error is already logged by whatsappTwilio().
            }
        } elseif ($driver === 'cloud_api') {
            // 18 Sep 2026: credentials resolve env -> settings in whatsapp/config.php.
            require_once ROOT_PATH . '/whatsapp/api.php';
            $token   = META_ACCESS_TOKEN;
            $phoneId = META_PHONE_NUMBER_ID;

            if ($token !== '' && $phoneId !== '') {
                self::$lastProviderError = '';
                if (self::whatsappCloudApi($number, $message, $token, $phoneId, $mediaUrl, $templateVars, (string) ($meta['media_type'] ?? ''), $ref, (string) ($meta['template_name'] ?? ''), (string) ($meta['template_lang'] ?? 'en'))) {
                    self::$journal[] = ['to' => $number, 'ok' => true, 'link' => null, 'driver' => 'cloud_api', 'reason' => ''];
                    // provider_ref = Meta's wamid, the key whatsapp/webhook.php
                    // matches delivery statuses on (same role as the Twilio SID).
                    self::logMessage('whatsapp', $number, $message, 'sent', [
                        'provider' => 'cloud_api', 'bookingId' => $bookingId, 'sid' => (string) $ref,
                    ] + $meta);
                    return true;
                }
                // Same reasoning as the Twilio branch above.
            }
        } elseif ($driver === 'gupshup') {
            // 21 Sep 2026: Gupshup BSP driver. Credentials resolve env ->
            // settings in whatsapp/gupshup.php (GUPSHUP_API_KEY / _APP_NAME /
            // _SOURCE_NUMBER). The Meta Cloud API path is kept live as the
            // rollback driver — flipping whatsapp_driver back to 'cloud_api'
            // is all it takes.
            require_once ROOT_PATH . '/whatsapp/gupshup.php';
            if (GUPSHUP_API_KEY !== '' && GUPSHUP_SOURCE_NUMBER !== '') {
                self::$lastProviderError = '';
                if (self::whatsappGupshup($number, $message, $mediaUrl, $templateVars, (string) ($meta['media_type'] ?? ''), $ref, (string) ($meta['template_name'] ?? ''))) {
                    self::$journal[] = ['to' => $number, 'ok' => true, 'link' => null, 'driver' => 'gupshup', 'reason' => ''];
                    // provider_ref = Gupshup messageId, the key
                    // whatsapp/gupshup-webhook.php matches delivery statuses
                    // on (same role as the wamid and Twilio SID).
                    self::logMessage('whatsapp', $number, $message, 'sent', [
                        'provider' => 'gupshup', 'bookingId' => $bookingId, 'sid' => (string) $ref,
                    ] + $meta);
                    return true;
                }
                // Same reasoning as the Twilio / Cloud API branches above:
                // fall through to the click-to-chat link so the ticket is
                // never lost silently.
            }
        }

        // No driver configured, or the configured driver failed — log a
        // click-to-chat link so staff can still send the message with one tap
        // from the admin logs/tools.
        $link = whatsappLink($number, $message);
        Logger::info('WhatsApp click-to-chat queued', ['to' => $number, 'link' => $link], 'whatsapp');
        self::$journal[] = ['to' => $number, 'ok' => false, 'link' => $link, 'driver' => $driver, 'reason' => $paused ? 'paused' : 'fallback'];
        // The customer has NOT been reached — only staff got a tappable link.
        // Persisting it is what makes the miss recoverable: admin/messages-log
        // can list it, and cron/whatsapp-retry.php re-sends it once the sender
        // works again. Before this row existed the miss lived only in a file
        // log no screen reads, so a failed ticket was simply lost.
        self::logMessage('whatsapp', $number, $message, 'failed', [
            'provider' => $driver, 'bookingId' => $bookingId,
            'error'    => $paused
                ? 'sender paused after an account-level refusal - click-to-chat link only'
                : ($driver === 'click_to_chat'
                    ? 'no WhatsApp API configured - click-to-chat link only'
                    : (self::$lastProviderError !== ''
                        ? 'provider refused the send: ' . mb_substr(self::$lastProviderError, 0, 300) . ' - click-to-chat link only'
                        : 'provider refused the send - click-to-chat link only')),
        ] + $meta);
        return $link;
    }

    /* -----------------------------------------------------------------
     *  Twilio sender breaker (7 Sep 2026)
     *
     *  Twilio answers an ACCOUNT-level refusal — 20003 (bad credentials,
     *  or "compliance profile is not approved": the Trust Hub KYC is still
     *  pending), 20005 (account inactive), 20006/20008 (not permitted) — the
     *  same way for every message, so retrying it on each ticket only adds
     *  a ~300 ms round trip to the sale and a red line to the log. One such
     *  answer pauses the sender for TWILIO_PAUSE_SEC; the pause lives in
     *  kv_store (shared by web and cron), lapses on its own, and the admin
     *  "Test WhatsApp" tool clears it the moment a test goes through.
     * --------------------------------------------------------------- */

    private const TWILIO_PAUSE_KEY = 'notify.twilio_wa.pause';
    private const TWILIO_PAUSE_SEC = 900;
    /** Twilio error codes that condemn the account, not this one message. */
    private const TWILIO_ACCOUNT_CODES = [20003, 20005, 20006, 20008];

    /** Request-scoped memo so a burst (cron reminders) reads kv_store once. */
    private static ?array $twilioPauseMemo = null;
    private static bool $twilioPauseRead = false;

    /**
     * The active pause, or null when Twilio may be tried.
     *
     * @return array{until: int, status: int, code: int, message: string, since: int}|null
     */
    public static function twilioPause(): ?array
    {
        if (!self::$twilioPauseRead) {
            self::$twilioPauseRead = true;
            self::$twilioPauseMemo = self::kvGet(self::TWILIO_PAUSE_KEY);
        }
        $p = self::$twilioPauseMemo;
        if ($p === null || (int) ($p['until'] ?? 0) <= time()) {
            return null;
        }

        return [
            'until'   => (int) $p['until'],
            'status'  => (int) ($p['status'] ?? 0),
            'code'    => (int) ($p['code'] ?? 0),
            'message' => (string) ($p['message'] ?? ''),
            'since'   => (int) ($p['since'] ?? 0),
        ];
    }

    /** Does this Twilio answer mean the ACCOUNT cannot send (vs. this one number)? */
    public static function twilioAccountBlocked(int $status, int $code): bool
    {
        return in_array($code, self::TWILIO_ACCOUNT_CODES, true) || ($code === 0 && $status === 401);
    }

    /** Pause the sender after an account-level refusal (see twilioPause()). */
    public static function twilioPauseSet(int $status, int $code, string $message): void
    {
        $now = time();
        $row = [
            'until'   => $now + self::TWILIO_PAUSE_SEC,
            'status'  => $status,
            'code'    => $code,
            // Both screens quote this inside a sentence of their own —
            // "…the account (<message>). Automatic sends are paused…" — so the
            // provider's own full stop is trimmed to avoid "approved.)."
            'message' => rtrim(mb_substr(trim($message), 0, 200), " \t.,;:"),
            'since'   => $now,
        ];
        self::kvSet(self::TWILIO_PAUSE_KEY, $row);
        self::$twilioPauseMemo = $row;
        self::$twilioPauseRead = true;
        Logger::warning('WhatsApp Twilio sender paused after an account-level refusal — click-to-chat fallback until ' . date('H:i', $row['until']) . ', then retried automatically', [
            'status' => $status, 'code' => $code, 'message' => $row['message'],
        ], 'whatsapp');
    }

    /** Lift the pause (a test send went through, or the office fixed the account). */
    public static function twilioResume(): void
    {
        self::kvDelete(self::TWILIO_PAUSE_KEY);
        self::$twilioPauseMemo = null;
        self::$twilioPauseRead = true;
    }

    /** @return array<string, mixed>|null */
    private static function kvGet(string $key): ?array
    {
        try {
            $raw = Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k LIMIT 1", ['k' => $key]);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $v = json_decode($raw, true);

        return is_array($v) ? $v : null;
    }

    /** @param array<string, mixed> $value */
    private static function kvSet(string $key, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', :k, :v, 'notify')
                 ON DUPLICATE KEY UPDATE kvalue = :v2, updated_by = 'notify'",
                ['k' => $key, 'v' => $json, 'v2' => $json]
            );
        } catch (Throwable $e) {
            Logger::warning('Notify kv write failed', ['key' => $key, 'e' => $e->getMessage()], 'whatsapp');
        }
    }

    private static function kvDelete(string $key): void
    {
        try {
            Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = :k", ['k' => $key]);
        } catch (Throwable $e) {
            Logger::warning('Notify kv delete failed', ['key' => $key, 'e' => $e->getMessage()], 'whatsapp');
        }
    }

    /**
     * Diagnostic test send for the admin "Send test WhatsApp" tool. Unlike
     * whatsapp() (which returns a plain bool), this surfaces the provider's
     * real status / error so an owner can confirm the Twilio setup in one
     * click. Never throws — returns ['ok'=>bool, 'stage'=>string, 'detail'=>string].
     */
    public static function whatsappTest(string $phone, ?string $countryHint = null): array
    {
        $driver = Settings::getString('whatsapp_driver', 'click_to_chat');
        $number = self::intlDigits($phone, $countryHint);
        if ($number === '') {
            return ['ok' => false, 'stage' => 'number',
                'detail' => 'Enter a valid mobile number with country code, e.g. +9198XXXXXXXX (India) or +97798XXXXXXXX (Nepal).'];
        }
        $msg = '✅ ' . Settings::getString('company_name', APP_NAME)
             . ' WhatsApp परीक्षण। यो सन्देश देखिएमा, तपाईंको स्वचालित टिकट अलर्ट चलिरहेको छ। 🎫';

        if ($driver === 'twilio') {
            $sid   = Settings::getString('twilio_account_sid', '');
            $token = Settings::getString('twilio_auth_token', '');
            $from  = Settings::getString('twilio_whatsapp_from', '');
            if ($sid === '' || $token === '' || $from === '') {
                return ['ok' => false, 'stage' => 'config',
                    'detail' => 'Twilio is selected but SID / Auth Token / WhatsApp sender is missing. Fill all three above, Save, then test again.'];
            }
            $fromN = str_starts_with(trim($from), 'whatsapp:')
                ? trim($from) : 'whatsapp:+' . (preg_replace('/\D/', '', $from) ?? '');
            $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['From' => $fromN, 'To' => 'whatsapp:+' . $number, 'Body' => $msg]),
                CURLOPT_USERPWD        => $sid . ':' . $token,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT        => 20,
            ]);
            $response = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);
            $json = json_decode((string) $response, true);
            if ($status >= 200 && $status < 300) {
                // The account sends again — lift any breaker pause at once so
                // the next ticket goes out automatically, not in 15 minutes.
                self::twilioResume();
                $sidOut = is_array($json) ? (string) ($json['sid'] ?? '') : '';
                return ['ok' => true, 'stage' => 'sent',
                    'detail' => 'Sent to +' . $number . ' — open WhatsApp on that phone to confirm.'
                        . ($sidOut !== '' ? ' (Twilio SID ' . $sidOut . ')' : '')];
            }
            $twMsg  = is_array($json) ? (string) ($json['message'] ?? '') : '';
            $twCode = is_array($json) ? (int) ($json['code'] ?? 0) : 0;
            Logger::error('WhatsApp test failed', ['status' => $status, 'code' => $twCode, 'resp' => $response], 'whatsapp');
            $pausedNote = '';
            if (self::twilioAccountBlocked($status, $twCode)) {
                self::twilioPauseSet($status, $twCode, $twMsg);
                $pausedNote = ' Automatic sends now use the click-to-chat fallback for ' . (int) (self::TWILIO_PAUSE_SEC / 60)
                            . ' minutes at a time and retry Twilio by themselves; a successful test here resumes them immediately.';
            }
            return ['ok' => false, 'stage' => 'api',
                'detail' => ($twMsg !== '' ? $twMsg : ('Twilio HTTP ' . $status))
                    . ($twCode ? ' (code ' . $twCode . ')' : '')
                    . self::twilioHint($twCode)
                    . ($curlErr !== '' ? ' · ' . $curlErr : '')
                    . $pausedNote];
        }

        if ($driver === 'cloud_api') {
            $ok = self::whatsapp($phone, $msg, null, $countryHint);
            return $ok === true
                ? ['ok' => true, 'stage' => 'sent', 'detail' => 'Sent to +' . $number . ' via Meta Cloud API — check WhatsApp on that phone.']
                : ['ok' => false, 'stage' => 'api', 'detail' => 'Meta Cloud API send failed — check whatsapp_api_token / whatsapp_phone_id and logs/ (channel: whatsapp).'];
        }

        if ($driver === 'gupshup') {
            require_once ROOT_PATH . '/whatsapp/gupshup.php';
            if (GUPSHUP_API_KEY === '' || GUPSHUP_SOURCE_NUMBER === '') {
                return ['ok' => false, 'stage' => 'config',
                    'detail' => 'Gupshup is selected but GUPSHUP_API_KEY / GUPSHUP_SOURCE_NUMBER are missing. Fill them in Settings (or the FPM env), Save, then test again.'];
            }
            $ok = self::whatsapp($phone, $msg, null, $countryHint);
            return $ok === true
                ? ['ok' => true, 'stage' => 'sent', 'detail' => 'Sent to +' . $number . ' via Gupshup — check WhatsApp on that phone.']
                : ['ok' => false, 'stage' => 'api',
                    'detail' => 'Gupshup send failed'
                        . (self::$lastProviderError !== '' ? ' — ' . mb_substr(self::$lastProviderError, 0, 300) : '')
                        . '. Check gupshup_api_key / gupshup_app_name / gupshup_source and logs/ (channel: whatsapp).'];
        }

        return ['ok' => false, 'stage' => 'driver',
            'detail' => 'WhatsApp driver is "click-to-chat", which does not auto-send. Set whatsapp_driver to "twilio", "cloud_api" or "gupshup" above, Save, then test.'];
    }

    /** Friendly, actionable advice for the common Twilio WhatsApp error codes. */
    private static function twilioHint(int $code): string
    {
        return match ($code) {
            63015, 63016 => ' — Tip: the recipient must first message your sandbox number the join code (e.g. "join <word>") once. Production needs an approved WhatsApp sender.',
            63007        => ' — Tip: the WhatsApp sender (twilio_whatsapp_from) is not a valid WhatsApp Twilio number. For testing use the sandbox number whatsapp:+14155238886.',
            21211, 21214 => ' — Tip: the destination number looks invalid. Enter it in full international form (country code + number).',
            20003        => ' — Tip: Twilio refused the account. If the message says "compliance profile is not approved", finish the Trust Hub KYC in the Twilio Console; otherwise the Account SID or Auth Token is wrong — copy them again (SID starts with AC…).',
            21606, 21910 => ' — Tip: the sender number can\'t message this destination. Check the sender is WhatsApp-enabled.',
            default      => '',
        };
    }

    /**
     * Re-send just the confirmed-ticket WhatsApp (with the PDF) for one
     * booking — used by the admin "Resend WhatsApp ticket" button when the
     * first automatic send failed (e.g. the customer had not yet joined the
     * Twilio sandbox). Mirrors bookingConfirmed()'s WhatsApp block exactly.
     * Returns a structured result; never throws.
     */
    /**
     * The {{1}}..{{7}} map the approved WhatsApp ticket template expects.
     *
     * One source of truth on purpose: bookingConfirmed() built these inline,
     * so the manual "Resend WhatsApp ticket" button sent none and fell to the
     * free-form Body branch — which a template-only WABA sender rejects with
     * 63016. The only recovery button in the admin UI therefore could not
     * deliver a ticket at all.
     *
     * Every slot is non-empty: WhatsApp rejects a template with a blank
     * variable, and strtotime() is guarded because a malformed boarding time
     * returns false, which date() rejects under strict_types.
     */
    /** Devanagari digits, so a Nepali message never prints "2026". */
    private static function nepaliDigits(string $s): string
    {
        return strtr($s, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४',
                          '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']);
    }

    /** "शनिबार, १९ सेप्टेम्बर २०२६" — the travel date as a passenger reads it. */
    private static function nepaliDateLine(string $ymd): string
    {
        $ts = strtotime($ymd);
        if ($ts === false) {
            return $ymd;
        }
        $days = ['Sun' => 'आइतबार', 'Mon' => 'सोमबार', 'Tue' => 'मङ्गलबार', 'Wed' => 'बुधबार',
                 'Thu' => 'बिहिबार', 'Fri' => 'शुक्रबार', 'Sat' => 'शनिबार'];
        $months = [1 => 'जनवरी', 'फेब्रुअरी', 'मार्च', 'अप्रिल', 'मे', 'जुन',
                    'जुलाई', 'अगस्ट', 'सेप्टेम्बर', 'अक्टोबर', 'नोभेम्बर', 'डिसेम्बर'];

        return ($days[date('D', $ts)] ?? '') . ', '
             . self::nepaliDigits((string) date('j', $ts)) . ' '
             . ($months[(int) date('n', $ts)] ?? '') . ' '
             . self::nepaliDigits((string) date('Y', $ts));
    }

    private static function ticketTemplateVars(array $booking): array
    {
        $facts   = self::ticketFacts($booking);
        $pnr     = (string) ($booking['pnr'] ?? '');
        $company = Settings::getString('company_name', APP_NAME);

        $board = $facts['boardingStop'] !== ''
            ? $facts['boardingStop']
            : ($facts['route'] !== '' ? explode(' → ', $facts['route'])[0] : '');

        /* The stored stop label carries machine bits a passenger must never
           read: a trailing "@ 21:00" and a "[23.171,72.623]" GPS pair. The
           message showed both, the time twice. Strip them; the time is
           printed once, formatted, below. */
        $board = (string) preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $board);
        $board = (string) preg_replace('/\s*@\s*[0-2]?\d:\d{2}\s*$/u', '', trim($board));
        $board = trim((string) preg_replace('/\s{2,}/u', ' ', $board));

        $time = '';
        foreach ([$facts['boardingTime'], $facts['depTime']] as $cand) {
            if ($cand === '') {
                continue;
            }
            $ts = strtotime($cand);
            if ($ts !== false) {
                $time = date('g:i A', $ts);
                break;
            }
        }
        $boardLine = trim($board . ($time !== '' ? ' · ' . $time : ''));

        /* The template in force decides the language of these values: a
           Nepali template must not print "2026-09-19". */
        $nepali = str_starts_with(Settings::getString('whatsapp_template_lang', 'en'), 'hi')
               || str_starts_with(Settings::getString('whatsapp_template_lang', 'en'), 'ne');

        $dateLine = $facts['date'] !== ''
            ? ($nepali ? self::nepaliDateLine($facts['date']) : formatDate($facts['date'], 'D, j M Y'))
            : '-';

        /* "UE5, UE6" alone leaves the passenger counting berths to know how
           many people the ticket covers. */
        $seatLine = $facts['seats'] !== '' ? $facts['seats'] : '-';
        $seatN    = $facts['seats'] !== ''
            ? count(array_filter(array_map('trim', explode(',', $facts['seats']))))
            : 0;
        if ($seatN > 1) {
            $seatLine .= $nepali
                ? ' · ' . self::nepaliDigits((string) $seatN) . ' जना'
                : ' · ' . $seatN . ' passengers';
        }

        return [
            '1' => $pnr                !== '' ? $pnr                : '-',
            '2' => $facts['route']     !== '' ? $facts['route']     : $company,
            '3' => $dateLine,
            '4' => $boardLine          !== '' ? $boardLine          : '-',
            '5' => $seatLine,
            '6' => inr((float) ($booking['total_amount'] ?? 0)),
            // {{7}} is the template's IMAGE header — the ticket PNG itself.
            '7' => Ticket::imageUrl($pnr),
        ];
    }

    public static function resendTicketWhatsApp(array $booking): array
    {
        $company = Settings::getString('company_name', APP_NAME);
        $pnr     = (string) ($booking['pnr'] ?? '');
        if (self::usablePhone($booking['contact_phone'] ?? '') === '') {
            return ['ok' => false, 'detail' => 'This booking has no usable contact phone number on file.'];
        }
        // PNG first (5 Sep 2026): the image opens right in WhatsApp — no PDF
        // viewer needed. The PDF stays one line below for printing.
        $ticketUrl = Ticket::imageUrl($pnr);      // carries the §25 download key
        $text = "🚌 " . $company . "\n"
              . "तपाईंको बुकिङ " . $pnr . " पक्का भयो ✅\n"
              . "जम्मा: " . inr((float) ($booking['total_amount'] ?? 0)) . "\n"
              . "तपाईंको टिकट (फोटो): " . $ticketUrl . "\n"
              . "प्रिन्ट गर्ने (PDF): " . Ticket::downloadUrl($pnr) . "\n"
              . "राम्रो यात्रा होस्! 🙏";
        $mediaUrl = Settings::getBool('whatsapp_send_pdf', true) ? $ticketUrl : null;
        // Same template variables bookingConfirmed() sends: without them this
        // falls to the free-form Body branch, which a template-only WhatsApp
        // sender rejects with 63016 — i.e. the recovery button would fail
        // exactly when it is most needed.
        $res = self::whatsapp(
            (string) $booking['contact_phone'],
            $text,
            $mediaUrl,
            self::countryHint($booking),
            self::ticketTemplateVars($booking),
            isset($booking['id']) && (int) $booking['id'] > 0 ? (int) $booking['id'] : null,
            ['purpose' => 'ticket']
        );
        if ($res === true) {
            return ['ok' => true, 'detail' => 'Ticket re-sent to ' . $booking['contact_phone'] . ' on WhatsApp.'];
        }
        if (is_string($res) && $res !== '') {
            // Only the Twilio driver can be paused by the breaker; a pause row
            // left over from an earlier Twilio spell must not be offered as the
            // explanation for a Cloud API or click-to-chat outcome.
            $pause = Settings::getString('whatsapp_driver', 'click_to_chat') === 'twilio' ? self::twilioPause() : null;
            if ($pause !== null) {
                return ['ok' => false, 'link' => $res,
                    'detail' => 'Twilio is refusing the account right now (' . ($pause['message'] !== '' ? $pause['message'] : 'code ' . $pause['code'])
                        . '), so automatic sends are paused until ' . date('H:i', $pause['until'])
                        . ' and then retried by themselves. Send via the click-to-chat link for now, or fix the account and run "Test WhatsApp" in Settings to resume at once.'];
            }
            return ['ok' => false, 'link' => $res,
                'detail' => 'No WhatsApp API is configured, so nothing auto-sent. Set up Twilio in Settings (then use "Test WhatsApp"), or send via the click-to-chat link.'];
        }
        return ['ok' => false,
            'detail' => 'WhatsApp send failed. Use Settings → "Test WhatsApp" to see the exact Twilio error, or check logs/ (channel: whatsapp).'];
    }

    /**
     * Tell the passenger WHAT changed on their ticket (17 Sep 2026).
     *
     * Reschedule, missed-bus rebooking, seat change and detail edits all used
     * to re-send the plain "is CONFIRMED" ticket, so a passenger moved from
     * the 17th to the 18th was never told a date had moved — they found out at
     * the bus. This is the same delivery path as resendTicketWhatsApp() (same
     * PNG + PDF links, ticketTemplateVars, countryHint, message_logs row) with
     * a clear first line naming the change, in English plus a short Nepali
     * line like every other passenger message.
     *
     * Template-only senders: an approved WhatsApp template carries no free
     * text, so when one is in force (Twilio twilio_content_sid, or Cloud API
     * whatsapp_template_name) the send is EXACTLY resendTicketWhatsApp() — the
     * refreshed ticket goes out, the change note cannot — and the detail says
     * so. Never throws; best effort like the button it mirrors.
     *
     * @param array<string,mixed> $booking FULL BookingService::detail() array
     * @param string $what 'reschedule' | 'missed_rebook' | 'seat' | 'passenger' | 'contact'
     * @param array<string,mixed> $ctx oldDate, newDate, seats (string|array),
     *        oldSeats, old, new, name — all optional, all display strings
     * @return array{ok: bool, detail: string, link?: string}
     */
    public static function ticketChanged(array $booking, string $what, array $ctx = []): array
    {
        if (!in_array($what, ['reschedule', 'missed_rebook', 'seat', 'passenger', 'contact'], true)) {
            return ['ok' => false, 'detail' => 'Unknown ticket change kind "' . $what . '" — nothing sent.'];
        }
        $company = Settings::getString('company_name', APP_NAME);
        $pnr     = (string) ($booking['pnr'] ?? '');
        if (self::usablePhone($booking['contact_phone'] ?? '') === '') {
            return ['ok' => false, 'detail' => 'This booking has no usable contact phone number on file.'];
        }

        $driver = Settings::getString('whatsapp_driver', 'click_to_chat');
        $templateInForce = ($driver === 'twilio' && Settings::getString('twilio_content_sid', '') !== '')
            || ($driver === 'cloud_api' && Settings::getString('whatsapp_template_name', '') !== '')
            || ($driver === 'gupshup' && (Settings::getString('whatsapp_template_name_gupshup', '') !== ''
                                           || Settings::getString('whatsapp_template_name', '') !== ''));
        if ($templateInForce) {
            $res = self::resendTicketWhatsApp($booking);
            $res['detail'] = (string) ($res['detail'] ?? '')
                . ' (Approved-template sender: the refreshed ticket was sent, the change note itself cannot ride on a template.)';
            return $res;
        }

        // Display strings for the change line; the caller passes what it knows.
        $fmt = static function (mixed $v): string {
            $s = trim((string) (is_scalar($v) ? $v : ''));
            return ($s !== '' && Security::isValidDate($s)) ? formatDate($s) : $s;
        };
        $seatStr = static function (mixed $v): string {
            return is_array($v) ? implode(', ', array_map('strval', $v)) : trim((string) (is_scalar($v) ? $v : ''));
        };
        $oldDate = $fmt($ctx['oldDate'] ?? '');
        $newDate = $fmt($ctx['newDate'] ?? '');
        $seats   = $seatStr($ctx['seats'] ?? ($ctx['new'] ?? ''));
        $old     = $seatStr($ctx['oldSeats'] ?? ($ctx['old'] ?? ''));
        $name    = trim((string) ($ctx['name'] ?? ''));
        $dates   = ($oldDate !== '' ? $oldDate . ' -> ' : '') . $newDate;
        $seatTail = $seats !== '' ? ' · seat(s) ' . $seats : '';

        switch ($what) {
            case 'reschedule':
                $en = 'Your ticket ' . $pnr . ' has been RESCHEDULED: ' . $dates . $seatTail;
                $np = 'तपाईंको टिकट ' . $pnr . ' को यात्रा मिति बदलियो: ' . $newDate . ($seats !== '' ? ' · सिट ' . $seats : '');
                break;
            case 'missed_rebook':
                $en = 'Your ticket ' . $pnr . ' has been REBOOKED after the missed bus: ' . $dates . $seatTail;
                $np = 'बस छुटेपछि तपाईंको टिकट ' . $pnr . ' नयाँ मितिमा सारियो: ' . $newDate . ($seats !== '' ? ' · सिट ' . $seats : '');
                break;
            case 'seat':
                $en = 'Your seat on ticket ' . $pnr . ' has CHANGED: ' . ($old !== '' ? $old . ' -> ' : '') . $seats;
                $np = 'टिकट ' . $pnr . ' को सिट बदलियो: ' . $seats;
                break;
            case 'contact':
                $en = 'Contact details on ticket ' . $pnr . ' have been UPDATED' . ($seats !== '' ? ': ' . $seats : '');
                $np = 'टिकट ' . $pnr . ' को सम्पर्क विवरण मिलाइयो।';
                break;
            default: // passenger
                $en = 'Passenger details on ticket ' . $pnr . ' have been UPDATED' . ($name !== '' ? ' (' . $name . ')' : '');
                $np = 'टिकट ' . $pnr . ' को यात्रु विवरण मिलाइयो।';
                break;
        }

        $ticketUrl = Ticket::imageUrl($pnr);      // carries the §25 download key
        unset($en); // customer message is Nepali-only; $en kept for logs/readability above
        $text = "🚌 " . $company . "\n"
              . $np . "\n"
              . "जम्मा: " . inr((float) ($booking['total_amount'] ?? 0)) . " (उही)\n"
              . "तपाईंको नयाँ टिकट (फोटो): " . $ticketUrl . "\n"
              . "प्रिन्ट गर्ने (PDF): " . Ticket::downloadUrl($pnr) . "\n"
              . "नयाँ टिकट लिएर जानुहोला। राम्रो यात्रा होस्! 🙏";
        $mediaUrl = Settings::getBool('whatsapp_send_pdf', true) ? $ticketUrl : null;
        $res = self::whatsapp(
            (string) $booking['contact_phone'],
            $text,
            $mediaUrl,
            self::countryHint($booking),
            self::ticketTemplateVars($booking),
            isset($booking['id']) && (int) $booking['id'] > 0 ? (int) $booking['id'] : null,
            ['purpose' => 'ticket']
        );
        if ($res === true) {
            return ['ok' => true, 'detail' => 'Change notice (' . $what . ') sent to ' . $booking['contact_phone'] . ' on WhatsApp.'];
        }
        if (is_string($res) && $res !== '') {
            $pause = $driver === 'twilio' ? self::twilioPause() : null;
            if ($pause !== null) {
                return ['ok' => false, 'link' => $res,
                    'detail' => 'Twilio is refusing the account right now (' . ($pause['message'] !== '' ? $pause['message'] : 'code ' . $pause['code'])
                        . '), so automatic sends are paused until ' . date('H:i', $pause['until'])
                        . ' and then retried by themselves. Send via the click-to-chat link for now.'];
            }
            return ['ok' => false, 'link' => $res,
                'detail' => 'No WhatsApp API is configured, so nothing auto-sent. Send the change notice via the click-to-chat link.'];
        }
        return ['ok' => false,
            'detail' => 'WhatsApp send failed. Use Settings → "Test WhatsApp" to see the exact provider error, or check logs/ (channel: whatsapp).'];
    }

    /**
     * Send through Twilio's WhatsApp REST API (Basic auth: SID:AuthToken).
     * A non-empty $mediaUrl is delivered as a media message (PDF/image).
     *
     * $templateVars turns the send into an APPROVED-TEMPLATE send. WhatsApp
     * only allows free-form text within 24h of the customer's last message,
     * and a ticket confirmation is business-initiated (they booked on the
     * website, they never messaged us) — so a production sender MUST use a
     * template or Twilio answers 21654 "ContentSid Required".
     *
     * Configure `twilio_content_sid` with an approved template built to this
     * exact variable order (see bookingConfirmed()):
     *   {{1}} PNR   {{2}} route   {{3}} journey date
     *   {{4}} boarding point + time   {{5}} seats   {{6}} total fare
     *   {{7}} ticket PNG URL — the template's image header
     * The live template is "shg_ticket_confirmed" (twilio/media), whose media
     * is declared as ["{{7}}"], which is why the PNG goes in as a variable
     * rather than as MediaUrl.
     *
     * Leaving `twilio_content_sid` empty keeps the old free-form behaviour,
     * so this is inert until an approved template actually exists.
     */
    /**
     * Public URL Twilio POSTs delivery updates to, or '' when we cannot form
     * an https one (Twilio refuses a plain-http callback).
     *
     * Override with twilio_status_callback_url when the site sits behind a
     * proxy. It must be the EXACT public URL — api/twilio-status.php checks
     * the signature over it, and the www/non-www 301 breaks that check the
     * same way it does for the inbound webhook.
     */
    private static function statusCallbackUrl(): string
    {
        $override = trim(Settings::getString('twilio_status_callback_url', ''));
        if ($override !== '') {
            return $override;
        }
        $url = appUrl('api/twilio-status.php');
        return str_starts_with($url, 'https://') ? $url : '';
    }

    private static function whatsappTwilio(string $toDigits, string $message, ?string $mediaUrl, string $sid, string $token, string $from, array $templateVars = [], ?string &$providerRef = null, ?string $contentSidOverride = null): bool
    {
        // Accept the "From" as "whatsapp:+1415...", "+1415..." or "1415...".
        $from = trim($from);
        if (!str_starts_with($from, 'whatsapp:')) {
            $from = 'whatsapp:+' . (preg_replace('/\D/', '', $from) ?? '');
        }

        $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';

        // 17 Sep 2026: a per-purpose approved template (agent statement,
        // payment reminder ...) may replace the global ticket template.
        $contentSid = ($contentSidOverride !== null && $contentSidOverride !== '')
            ? $contentSidOverride
            : Settings::getString('twilio_content_sid', '');

        if ($contentSid !== '' && $templateVars !== []) {
            // No MediaUrl here on purpose. In a Content template the image is
            // part of the template body ("media": ["{{7}}"]), so the ticket PNG
            // travels as template variable 7 — see bookingConfirmed(). Passing
            // MediaUrl alongside ContentSid does not fill that header.
            $fields = [
                'From'             => $from,
                'To'               => 'whatsapp:+' . $toDigits,
                'ContentSid'       => $contentSid,
                'ContentVariables' => (string) json_encode($templateVars, JSON_UNESCAPED_UNICODE),
            ];
        } else {
            $fields = [
                'From' => $from,
                'To'   => 'whatsapp:+' . $toDigits,
                'Body' => $message,
            ];
            if ($mediaUrl !== null && $mediaUrl !== '') {
                $fields['MediaUrl'] = $mediaUrl;
            }
        }

        // A 2xx here only means Twilio ACCEPTED the message. WhatsApp decides
        // later, and a rejection (63016 no approved template, 63112 Meta
        // disabled the WABA) arrives asynchronously on this callback. Without
        // it every accepted-then-failed ticket is recorded as "sent" and the
        // retry job never sees it — which is exactly what happened here: 19 of
        // the last 50 messages failed 63112 while the code counted them good.
        $cb = self::statusCallbackUrl();
        if ($cb !== '') {
            $fields['StatusCallback'] = $cb;
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_USERPWD        => $sid . ':' . $token,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $json = json_decode((string) $response, true);

        if ($status >= 200 && $status < 300) {
            $providerRef = is_array($json) ? (string) ($json['sid'] ?? '') : '';

            // Twilio can reject inside the very same response — the create
            // returns 201 with status already "failed". Treating that as a
            // send is how a dead ticket got a green tick.
            $immediate = is_array($json) ? (string) ($json['status'] ?? '') : '';
            if ($immediate === 'failed' || $immediate === 'undelivered') {
                Logger::error('WhatsApp rejected on create', [
                    'status' => $immediate,
                    'code'   => is_array($json) ? ($json['error_code'] ?? null) : null,
                    'sid'    => $providerRef,
                ], 'whatsapp');
                return false;
            }
            return true;
        }

        Logger::error('WhatsApp Twilio API failed', ['status' => $status, 'resp' => $response, 'curl' => $curlErr], 'whatsapp');
        $code = is_array($json) ? (int) ($json['code'] ?? 0) : 0;
        if (self::twilioAccountBlocked($status, $code)) {
            self::twilioPauseSet($status, $code, is_array($json) ? (string) ($json['message'] ?? '') : '');
        }
        return false;
    }

    /**
     * Send through Meta's WhatsApp Cloud API (Bearer token on the Graph API).
     *
     * $templateVars works exactly as in whatsappTwilio(): when the template
     * name is configured the send becomes an approved-template send, which is
     * what WhatsApp requires for a business-initiated message like a ticket
     * confirmation. Body variables are {{1}}..{{6}} in key order; the ticket
     * PNG becomes the template's IMAGE header (carried as var 7 by
     * bookingConfirmed(), or as $mediaUrl).
     */
    private static function whatsappCloudApi(string $number, string $message, string $token, string $phoneId, ?string $mediaUrl = null, array $templateVars = [], string $mediaType = '', ?string &$providerRef = null, string $templateName = '', string $templateLang = 'en'): bool
    {
        // 18 Sep 2026: the HTTP call (Graph API version, retry-once, per-day
        // log, message id) lives in whatsapp/api.php — one path for every
        // Meta send. This method only chooses the payload.
        require_once ROOT_PATH . '/whatsapp/api.php';

        // The ticket image: explicit media wins, else template var 7.
        $image = ($mediaUrl !== null && $mediaUrl !== '') ? $mediaUrl : (string) ($templateVars['7'] ?? '');
        /* A caller that names its own template (booking received, payment
           rejected, ...) wins over the ticket template in Settings. */
        $template = $templateName !== '' ? $templateName : Settings::getString('whatsapp_template_name', '');
        $lang     = $templateName !== '' ? $templateLang : Settings::getString('whatsapp_template_lang', 'en');

        if ($template !== '' && $templateVars !== []) {
            $bodyVars = $templateVars;
            unset($bodyVars['7']);          // 7 is the header image, not a body slot
            ksort($bodyVars, SORT_NATURAL);

            $components = [];
            $hdrImage = (string) ($templateVars['7'] ?? '');
            if ($hdrImage === '' && $templateName === '') {
                $hdrImage = $image;   // ticket path: media is the header
            }
            if ($hdrImage !== '') {
                $components[] = ['type' => 'header', 'parameters' => [
                    ['type' => 'image', 'image' => ['link' => $hdrImage]],
                ]];
            }
            $params = [];
            foreach ($bodyVars as $v) {
                $params[] = ['type' => 'text', 'text' => (string) $v];
            }
            if ($params !== []) {
                $components[] = ['type' => 'body', 'parameters' => $params];
            }

            $body = [
                'messaging_product' => 'whatsapp',
                'to'                => $number,
                'type'              => 'template',
                'template'          => [
                    'name'       => $template,
                    'language'   => ['code' => $lang],
                    'components' => $components,
                ],
            ];
        } elseif ($image !== '' && ($mediaType === 'document' || preg_match('/\.pdf(\?|$)/i', $image) === 1)) {
            // 17 Sep 2026: an agent statement is a PDF — WhatsApp wants that
            // as a 'document' (the image payload below would be rejected).
            $fn = basename((string) parse_url($image, PHP_URL_PATH));
            if (preg_match('/\.pdf$/i', $fn) !== 1) {
                $fn = 'statement.pdf';
            }
            $body = [
                'messaging_product' => 'whatsapp',
                'to'                => $number,
                'type'              => 'document',
                'document'          => ['link' => $image, 'caption' => mb_substr($message, 0, 1024), 'filename' => $fn],
            ];
        } elseif ($image !== '') {
            // The ticket is a PNG, so send it as an IMAGE — it then renders
            // inline in the chat. This used to go as a "document" named
            // ticket.pdf, which delivered a PNG under a .pdf filename that no
            // viewer could open properly.
            $body = [
                'messaging_product' => 'whatsapp',
                'to'                => $number,
                'type'              => 'image',
                // WhatsApp caps an image caption at 1024 characters.
                'image'             => ['link' => $image, 'caption' => mb_substr($message, 0, 1024)],
            ];
        } else {
            $body = [
                'messaging_product' => 'whatsapp',
                'to'                => $number,
                'type'              => 'text',
                'text'              => ['body' => $message],
            ];
        }

        // messaging_product / to are added by waGraphPost().
        $wasTemplate = ($body['type'] ?? '') === 'template';
        unset($body['messaging_product'], $body['to']);
        $r = waGraphPost($number, $body, (string) ($body['type'] ?? 'text'), $token, $phoneId);

        /* Meta refuses the template itself — not found, still in review,
           paused, or its shape changed. The message is fine, so send it as
           free text/image: inside a 24 h window it still reaches the
           customer, and the moment Meta approves the template this retry
           stops happening on its own. */
        if (!$r['success'] && $wasTemplate
            && in_array((int) ($r['code'] ?? 0), [132000, 132001, 132005, 132007, 132012, 132015, 132016, 132068, 132069], true)) {
            $alt = $image !== ''
                ? ['type' => 'image', 'image' => ['link' => $image, 'caption' => mb_substr($message, 0, 1024)]]
                : ['type' => 'text', 'text' => ['body' => $message]];
            $r = waGraphPost($number, $alt, (string) $alt['type'], $token, $phoneId);
        }

        $providerRef = $r['message_id'];
        if (!$r['success']) {
            self::$lastProviderError = trim((string) ($r['error'] ?? ''));
        }

        return $r['success'];
    }

    /**
     * Gupshup driver (21 Sep 2026) — mirror image of whatsappCloudApi().
     * Same contract: pick the payload shape (template / image / document /
     * plain text) from what the caller passed and hand it to gupshupPost().
     * A refused template (Gupshup 1004/1005/…"template not approved") falls
     * back to a free-form image + caption or text, so a ticket inside a 24 h
     * service window still reaches the customer while a new template moves
     * through review.
     *
     * Templates: Gupshup identifies a template by NAME by default; when the
     * WABA is embedded-signup-linked to Gupshup the Meta-approved templates
     * are already synced and the SAME name works on both drivers, which is
     * why $templateName defaults to Settings::whatsapp_template_name.
     * $whatsapp_template_name_gupshup can override it when the templates
     * are cloned rather than synced.
     */
    private static function whatsappGupshup(string $number, string $message, ?string $mediaUrl = null, array $templateVars = [], string $mediaType = '', ?string &$providerRef = null, string $templateName = ''): bool
    {
        require_once ROOT_PATH . '/whatsapp/gupshup.php';

        // Header image: explicit media wins, else template var 7 (same
        // contract as ticketTemplateVars() -> Meta Cloud API).
        $image = ($mediaUrl !== null && $mediaUrl !== '') ? $mediaUrl : (string) ($templateVars['7'] ?? '');
        // Prefer the Gupshup-specific override when set; else the shared
        // whatsapp_template_name; a caller-supplied $templateName wins over
        // both.
        $template = $templateName !== ''
            ? $templateName
            : (Settings::getString('whatsapp_template_name_gupshup', '') !== ''
                ? Settings::getString('whatsapp_template_name_gupshup', '')
                : Settings::getString('whatsapp_template_name', ''));

        $r = ['success' => false, 'message_id' => '', 'error' => '', 'http' => 0, 'code' => 0];

        if ($template !== '' && $templateVars !== []) {
            $bodyVars = $templateVars;
            unset($bodyVars['7']);   // 7 is the header image, not a body slot
            ksort($bodyVars, SORT_NATURAL);
            $r = sendGupshupTemplate($number, $template, $bodyVars, $image !== '' ? $image : null);

            /* Gupshup refuses the template itself (typical codes 1002/1004/
               1005/2010 for "template not approved / paused / mismatched
               params"). The message is fine, so send it as free text / image
               inside an open 24 h window — the moment Gupshup approves the
               template this retry stops happening on its own. */
            if (!$r['success']) {
                $err = (string) ($r['error'] ?? '');
                $tplBad = str_contains($err, 'template') || str_contains($err, 'Template')
                    || in_array((int) $r['code'], [1002, 1004, 1005, 2010, 62, 66], true);
                if ($tplBad) {
                    if ($image !== '' && ($mediaType === 'document' || preg_match('/\.pdf(\?|$)/i', $image) === 1)) {
                        $fn = basename((string) parse_url($image, PHP_URL_PATH));
                        if (preg_match('/\.pdf$/i', $fn) !== 1) { $fn = 'statement.pdf'; }
                        $r = sendGupshupDocument($number, $image, $fn, mb_substr($message, 0, 1024));
                    } elseif ($image !== '') {
                        $r = sendGupshupImage($number, $image, mb_substr($message, 0, 1024));
                    } else {
                        $r = sendGupshupText($number, $message);
                    }
                }
            }
        } elseif ($image !== '' && ($mediaType === 'document' || preg_match('/\.pdf(\?|$)/i', $image) === 1)) {
            $fn = basename((string) parse_url($image, PHP_URL_PATH));
            if (preg_match('/\.pdf$/i', $fn) !== 1) { $fn = 'statement.pdf'; }
            $r = sendGupshupDocument($number, $image, $fn, mb_substr($message, 0, 1024));
        } elseif ($image !== '') {
            // Ticket path: the PNG rides inline in the chat with the caption.
            $r = sendGupshupImage($number, $image, mb_substr($message, 0, 1024));
        } else {
            $r = sendGupshupText($number, $message);
        }

        $providerRef = $r['message_id'];
        if (!$r['success']) {
            self::$lastProviderError = trim((string) ($r['error'] ?? ''));
        }
        return (bool) $r['success'];
    }

    /**
     * Normalise any phone number to international digits (country code +
     * subscriber number, no "+"). Numbers that already carry a country code
     * (11+ digits, or a 00 prefix) are kept as-is. A bare 10-digit local
     * number is resolved with the country hint when given ('NP' -> 977,
     * 'IN' -> 91), otherwise the configured default country code (91).
     *
     * India <-> Nepal both use 10-digit mobiles, so a bare 10-digit number is
     * genuinely ambiguous — the hint (derived from the booking's ID type)
     * keeps a Nepali customer's ticket from being sent to an Indian number.
     */
    private static function intlDigits(string $phone, ?string $countryHint = null): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 10) {
            if ($countryHint === 'NP') {
                $cc = '977';
            } elseif ($countryHint === 'IN') {
                $cc = '91';
            } else {
                $cc = preg_replace('/\D/', '', Settings::getString('whatsapp_default_country', '91')) ?: '91';
            }
            $d = $cc . $d;
        }
        return $d;
    }

    /**
     * Best-effort country hint ('NP', 'IN', or null) for a booking, tried in
     * order of trust. India and Nepal share 10-digit mobiles, so a bare number
     * cannot be placed by its digits — without a hint intlDigits() prepends the
     * default (91), which is what sent Nepali tickets to strangers in India.
     *
     *   1. contact_country_code stamped ON the booking at sale time — the
     *      country the buyer picked for THIS ticket, so it is authoritative.
     *   2. a Nepali citizenship card as the ID document — a Nepal signal that
     *      also covers bookings made before the column existed.
     *   3. the country on the customer's account (users.country_code), chosen
     *      when they signed in — the retroactive fix for existing bookings.
     *
     * Anything unresolved returns null so the configured default still applies.
     */
    private static function countryHint(array $booking): ?string
    {
        $stamped = preg_replace('/\D/', '', (string) ($booking['contact_country_code'] ?? '')) ?? '';
        if ($stamped === '977') {
            return 'NP';
        }
        if ($stamped === '91') {
            return 'IN';
        }

        $idType = strtolower((string) ($booking['id_type'] ?? ''));
        if ($idType !== '' && str_contains($idType, 'nepal')) {
            return 'NP';
        }

        return self::countryFromAccount((string) ($booking['contact_phone'] ?? ''));
    }

    /**
     * The country the owner of this number chose when signing in
     * (users.country_code), as 'NP' / 'IN', or null when there is no account or
     * the lookup fails. Best-effort only: a notifier must never throw, so a DB
     * hiccup here simply defers to the configured default.
     */
    private static function countryFromAccount(string $phone): ?string
    {
        $norm = normalisePhone($phone);
        if ($norm === '' || preg_match('/^0+$/', $norm) === 1) {
            return null;
        }
        try {
            $cc = Database::scalar(
                'SELECT country_code FROM users WHERE phone = :p LIMIT 1',
                ['p' => $norm]
            );
        } catch (Throwable $e) {
            return null;
        }
        $cc = preg_replace('/\D/', '', (string) $cc) ?? '';
        if ($cc === '977') {
            return 'NP';
        }
        if ($cc === '91') {
            return 'IN';
        }
        return null;
    }


    /* =================================================================
     *  SMS channel (Twilio SMS REST API)
     *
     *  Reuses the same twilio_account_sid / twilio_auth_token as WhatsApp —
     *  only the sender differs (twilio_sms_from must be an SMS-capable
     *  number, not the WhatsApp sender). Every send is written to
     *  message_logs (the "Store SMS logs" requirement).
     * ================================================================= */

    /**
     * Send an SMS. Returns true only when the provider accepted it. A no-op
     * (returns false) when the SMS channel is off or the number is unusable.
     */
    public static function sms(string $phone, string $message, ?string $countryHint = null, ?int $bookingId = null): bool
    {
        if (!Settings::getBool('sms_enabled', false)) {
            return false;
        }

        $number = self::intlDigits($phone, $countryHint);
        if ($number === '') {
            return false;
        }

        // One character outside GSM 03.38 (₹, →, ·, ⇄, en-dash) flips the whole
        // SMS to UCS-2 — 70 chars/segment instead of 160, so a one-part message
        // silently becomes three and costs 3×. Fold those few symbols to ASCII
        // here so every current and future SMS caller is covered in one place.
        $message = strtr($message, [
            '₹' => 'Rs ', '→' => ' - ', '⇄' => ' - ', '·' => ' ',
            '–' => '-', '—' => '-', '“' => '"', '”' => '"', '’' => "'",
        ]);

        $res = self::smsSend($number, $message);
        self::logMessage('sms', $number, $message, $res['ok'] ? 'sent' : 'failed', [
            'provider'  => 'twilio',
            'sid'       => $res['sid'],
            'error'     => $res['ok'] ? null : $res['error'],
            'bookingId' => $bookingId,
        ]);

        return $res['ok'];
    }

    /**
     * Diagnostic test send for the admin "Send test SMS" tool. Surfaces the
     * provider's real status / error. Never throws.
     *
     * @return array{ok: bool, stage: string, detail: string}
     */
    public static function smsTest(string $phone, ?string $countryHint = null): array
    {
        if (!Settings::getBool('sms_enabled', false)) {
            return ['ok' => false, 'stage' => 'driver',
                'detail' => 'The SMS channel is OFF. Tick sms_enabled above, Save, then test.'];
        }

        $number = self::intlDigits($phone, $countryHint);
        if ($number === '') {
            return ['ok' => false, 'stage' => 'number',
                'detail' => 'Enter a valid mobile number with country code, e.g. +9198XXXXXXXX (India) or +97798XXXXXXXX (Nepal).'];
        }

        $msg = '✅ ' . Settings::getString('company_name', APP_NAME)
             . ' SMS test. If you can read this, your automatic ticket SMS is working.';

        $res = self::smsSend($number, $msg);
        self::logMessage('sms', $number, $msg, $res['ok'] ? 'sent' : 'failed', [
            'provider' => 'twilio', 'sid' => $res['sid'], 'error' => $res['ok'] ? null : $res['error'],
        ]);

        if ($res['ok']) {
            return ['ok' => true, 'stage' => 'sent',
                'detail' => 'Sent to +' . $number . ($res['sid'] !== '' ? ' (Twilio SID ' . $res['sid'] . ')' : '') . ' — check that phone.'];
        }

        return ['ok' => false, 'stage' => 'api',
            'detail' => $res['error'] . ($res['code'] ? ' (code ' . $res['code'] . ')' : '') . self::twilioHint($res['code'])];
    }

    /**
     * Low-level Twilio SMS POST. Reads sid/token/from from settings; returns
     * a structured result so both sms() and smsTest() can share it.
     *
     * @return array{ok: bool, status: int, sid: string, code: int, error: string}
     */
    private static function smsSend(string $toDigits, string $message): array
    {
        $sid   = Settings::getString('twilio_account_sid', '');
        $token = Settings::getString('twilio_auth_token', '');
        $from  = trim(Settings::getString('twilio_sms_from', ''));

        if ($sid === '' || $token === '' || $from === '') {
            return ['ok' => false, 'status' => 0, 'sid' => '', 'code' => 0,
                'error' => 'Twilio SMS is not fully configured — set twilio_account_sid, twilio_auth_token and twilio_sms_from.'];
        }

        // A numeric sender is normalised to E.164 (+digits); an alphanumeric
        // sender ID (contains letters) is kept verbatim.
        if (preg_match('/[A-Za-z]/', $from) !== 1) {
            $from = '+' . (preg_replace('/\D/', '', $from) ?? '');
        }

        $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['From' => $from, 'To' => '+' . $toDigits, 'Body' => $message]),
            CURLOPT_USERPWD        => $sid . ':' . $token,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 20,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $json   = json_decode((string) $response, true);
        $sidOut = is_array($json) ? (string) ($json['sid'] ?? '') : '';

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'sid' => $sidOut, 'code' => 0, 'error' => ''];
        }

        $twMsg  = is_array($json) ? (string) ($json['message'] ?? '') : '';
        $twCode = is_array($json) ? (int) ($json['code'] ?? 0) : 0;
        Logger::error('SMS Twilio API failed', ['status' => $status, 'code' => $twCode, 'resp' => $response, 'curl' => $curlErr], 'sms');

        return ['ok' => false, 'status' => $status, 'sid' => '', 'code' => $twCode,
            'error' => ($twMsg !== '' ? $twMsg : ('Twilio HTTP ' . $status)) . ($curlErr !== '' ? ' · ' . $curlErr : '')];
    }

    /**
     * Persist one outbound message to message_logs. Defensive: a missing
     * table (migration not yet run on live) must never break a send.
     *
     * @param array<string, mixed> $meta provider|sid|error|reason|bookingId
     */
    /**
     * Public entry for the staff WhatsApp tools (17 Sep 2026): a wa.me hand-off
     * or a refused send still gets its message_logs row (status 'skipped',
     * provider 'manual') so the log is complete — see admin/api/wa-send.php.
     */
    public static function logOutbound(string $to, string $body, string $status, array $meta = []): void
    {
        self::logMessage('whatsapp', $to, $body, $status, $meta);
    }

    /** The {{1}}..{{7}} ticket template variables — public for WaTemplates. */
    public static function ticketVars(array $booking): array
    {
        return self::ticketTemplateVars($booking);
    }

    /** 'NP' / 'IN' / null for a booking's contact number — public for WaTemplates. */
    public static function bookingCountryHint(array $booking): ?string
    {
        return self::countryHint($booking);
    }

    private static function logMessage(string $channel, string $to, string $body, string $status, array $meta = []): void
    {
        // 4 Sep 2026: never keep a one-time code at rest. Customer OTPs and
        // staff 2FA texts read "… code: 123456 …"; the digits are masked
        // before the row is written, so admin/messages-log.php can show the
        // delivery status without handing any reader a live second factor.
        $body = preg_replace('/(code\D{0,12}?)(\d{4,8})/iu', '$1••••', $body) ?? $body;
        // 24 Sep 2026: the same for a one-time link token (step-up verification,
        // document share) — the bot's reply carrying it is logged here too.
        $body = preg_replace('~([?&]t=)[A-Za-z0-9]{16,}~', '$1[hidden]', $body) ?? $body;
        $row = [
            'booking_id'   => isset($meta['bookingId']) && $meta['bookingId'] ? (int) $meta['bookingId'] : null,
            'channel'      => $channel,
            'provider'     => (string) ($meta['provider'] ?? ''),
            'to_number'    => substr($to, 0, 32),
            'body'         => mb_substr($body, 0, 1000),
            'status'       => $status,
            'provider_ref' => substr((string) ($meta['sid'] ?? ''), 0, 64),
            'error'        => isset($meta['error']) && $meta['error'] !== null
                                ? mb_substr((string) $meta['error'], 0, 255)
                                : (isset($meta['reason']) ? mb_substr((string) $meta['reason'], 0, 255) : null),
        ];
        /* 17 Sep 2026 — purpose / who pressed send / which agent / media
           (database/upgrade-2026-09-wa-templates.sql). Written when given;
           on a database where that migration has not run the insert is
           retried without them, so a row is never lost to a missing column. */
        $extra = [];
        if (!empty($meta['purpose']))        { $extra['purpose']        = substr((string) $meta['purpose'], 0, 40); }
        if (!empty($meta['admin_id']))       { $extra['admin_id']       = (int) $meta['admin_id']; }
        if (!empty($meta['agent_admin_id'])) { $extra['agent_admin_id'] = (int) $meta['agent_admin_id']; }
        if (!empty($meta['media_url']))      { $extra['media_url']      = substr((string) $meta['media_url'], 0, 255); }
        try {
            Database::insert('message_logs', $row + $extra);
        } catch (Throwable $e) {
            if ($extra !== []) {
                try {
                    Database::insert('message_logs', $row);
                    return;
                } catch (Throwable $e2) {
                    $e = $e2;
                }
            }
            Logger::warning('message_logs insert skipped: ' . $e->getMessage(), ['channel' => $channel], $channel);
        }
    }

    /**
     * Seat list, route and travel date for a booking — used to enrich the
     * confirmation SMS / WhatsApp / email with Seat / Route / Date.
     *
     * @return array{seats: string, route: string, date: string, depTime: string}
     */
    private static function ticketFacts(array $booking): array
    {
        $facts = ['seats' => '', 'route' => '', 'date' => '', 'depTime' => '',
                  'boardingStop' => '', 'dropStop' => '', 'boardingTime' => ''];
        $bid   = (int) ($booking['id'] ?? 0);
        if ($bid <= 0) {
            return $facts;
        }

        $seatRows = Database::fetchAll(
            'SELECT seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY seat_no',
            ['b' => $bid]
        );
        $seats = array_values(array_filter(array_map(static fn($r) => (string) $r['seat_no'], $seatRows)));
        /* Row-letter grid ids (LA1, LA2 …) for every message body — WhatsApp
           template {{5}}, e-mail, SMS, the .ics event and the agent copy all read
           $facts['seats']. Storage stays canonical; only this display string is
           prettified. Mode decides the private 3-across grid, so it is resolved
           from the booking; coach falls back to sleeper (seater ids no-op). */
        if (!class_exists('Seats')) { require_once __DIR__ . '/seats.php'; }
        $mode = (string) ($booking['booking_mode'] ?? '');
        if ($mode === '') {
            $mode = (string) Database::scalar('SELECT booking_mode FROM bookings WHERE id = :b LIMIT 1', ['b' => $bid], 'sharing');
        }
        $coach = (string) Database::scalar(
            'SELECT COALESCE(s.coach_type_override, r.coach_type)
               FROM booking_legs bl
               JOIN schedules s ON s.id = bl.schedule_id
               JOIN routes    r ON r.id = s.route_id
              WHERE bl.booking_id = :b ORDER BY bl.id ASC LIMIT 1',
            ['b' => $bid], 'sleeper'
        );
        $facts['seats'] = Seats::displayLabels($seats, $coach !== '' ? $coach : 'sleeper', $mode !== '' ? $mode : 'sharing');

        $leg = Database::fetch(
            'SELECT bl.travel_date, bl.boarding_stop, bl.drop_stop, r.from_city, r.to_city, r.dep_time
               FROM booking_legs bl
               JOIN schedules s ON s.id = bl.schedule_id
               JOIN routes    r ON r.id = s.route_id
              WHERE bl.booking_id = :b
              ORDER BY bl.id ASC
              LIMIT 1',
            ['b' => $bid]
        );
        if ($leg !== null) {
            $facts['route']   = trim((string) $leg['from_city']) . ' → ' . trim((string) $leg['to_city']);
            $facts['date']    = (string) $leg['travel_date'];
            $facts['depTime'] = substr((string) ($leg['dep_time'] ?? ''), 0, 5);

            // Boarding point: use the passenger's selected boarding_stop, not route origin
            $bs = trim((string) ($leg['boarding_stop'] ?? ''));
            if ($bs !== '') {
                $cityPart = trim(explode('·', $bs)[0]);
                $facts['boardingStop'] = $cityPart !== '' ? $cityPart : $bs;
                // Parse pickup time from "@ HH:MM"
                if (preg_match('/@\s*([0-2]?\d:\d{2})/', $bs, $tm)) {
                    $facts['boardingTime'] = $tm[1];
                }
            }
            $ds = trim((string) ($leg['drop_stop'] ?? ''));
            if ($ds !== '') {
                $facts['dropStop'] = trim(explode('·', $ds)[0]) ?: $ds;
            }
        }

        return $facts;
    }


    /* =================================================================
     *  Templated notifications for booking lifecycle events
     * ================================================================= */

    public static function bookingConfirmed(array $booking): void
    {
        $company   = Settings::getString('company_name', APP_NAME);
        $pnr       = (string) $booking['pnr'];
        $bid       = (int) ($booking['id'] ?? 0);
        $facts     = self::ticketFacts($booking);
        $amount    = inr((float) ($booking['total_amount'] ?? 0));
        $ticketUrl = Ticket::imageUrl($pnr);      // PNG first; PDF linked below

        // Journey date · departure time, when known.
        $when = $facts['date'] . ($facts['depTime'] !== '' ? ' · ' . $facts['depTime'] : '');

        /* Web Push (13 Sep 2026): "ticket ready" lands on the phone the moment
           the office confirms — one tap opens the e-ticket. Best-effort. */
        try {
            if (!class_exists('WebPush')) {
                require_once __DIR__ . '/webpush.php';
            }
            if ($bid > 0 && WebPush::enabled()) {
                WebPush::sendToBooking($bid, WebPush::tripEventPayload('ticket_ready', $pnr, $facts, $company), 'ticket_ready');
            }
        } catch (Throwable $e) {
            Logger::warning('WebPush ticket_ready failed', ['pnr' => $pnr, 'err' => $e->getMessage()]);
        }

        $subject      = 'Your ticket is confirmed · ' . $pnr;
        $factRowsHtml = '';
        if ($facts['route'] !== '') { $factRowsHtml .= '<p>Route: <strong>' . e($facts['route']) . '</strong></p>'; }
        if ($when !== '')           { $factRowsHtml .= '<p>Journey date: <strong>' . e($when) . '</strong></p>'; }
        if ($facts['seats'] !== '') { $factRowsHtml .= '<p>Seat(s): <strong>' . e($facts['seats']) . '</strong></p>'; }

        $html = self::wrapEmail(
            'Booking confirmed 🎉',
            '<p>Namaste,</p>'
            . '<p>Your booking <strong>' . e($pnr) . '</strong> with ' . e($company) . ' is confirmed.</p>'
            . $factRowsHtml
            . '<p>Total paid: <strong>' . e($amount) . '</strong></p>'
            . '<p>Download your ticket any time from '
            . '<a href="' . e($ticketUrl) . '">this link</a>'
            . ' (or as a <a href="' . e(Ticket::downloadUrl($pnr)) . '">printable PDF</a>).</p>'
            . '<p>Have a safe journey.<br>' . e($company) . '</p>'
        );

        if (!empty($booking['contact_email'])) {
            // Ride-along files: an "Add to calendar" .ics and the ticket
            // PDF itself. Both best-effort — a PDF hiccup must never stop
            // the confirmation email (Ticket::issue precedent).
            $attachments = [];
            if (Settings::getBool('email_attach_ics', true)) {
                try {
                    $ics = Calendar::journeyEvent([
                        'pnr'     => $pnr,
                        'route'   => $facts['route'],
                        'date'    => $facts['date'],
                        'depTime' => $facts['depTime'],
                        'seats'   => $facts['seats'],
                    ]);
                    if ($ics !== null) {
                        $attachments[] = ['name' => 'journey-' . $pnr . '.ics', 'mime' => 'text/calendar; charset=UTF-8; method=PUBLISH', 'data' => $ics];
                    }
                } catch (Throwable $e) {
                    Logger::error('ICS attach failed: ' . $e->getMessage(), ['pnr' => $pnr], 'mail');
                }
            }
            if ($bid > 0 && Settings::getBool('email_attach_pdf', true)) {
                try {
                    $pdfFile = Ticket::pdfPath($bid);
                    $size    = @filesize($pdfFile);
                    if (is_file($pdfFile) && $size !== false && $size > 0 && $size < 4 * 1024 * 1024) {
                        $attachments[] = ['name' => 'ticket-' . $pnr . '.pdf', 'mime' => 'application/pdf', 'data' => (string) file_get_contents($pdfFile)];
                    }
                } catch (Throwable $e) {
                    Logger::error('PDF attach failed: ' . $e->getMessage(), ['pnr' => $pnr], 'mail');
                }
            }
            self::email((string) $booking['contact_email'], $subject, $html, '', $attachments);
        }

        // WhatsApp details: boarding_stop with pickup time (NOT route origin).
        $waBoard = $facts['boardingStop'] !== '' ? $facts['boardingStop'] : ($facts['route'] !== '' ? explode(' → ', $facts['route'])[0] : '');
        $waDrop  = $facts['dropStop'] !== '' ? $facts['dropStop'] : ($facts['route'] !== '' ? (explode(' → ', $facts['route'])[1] ?? '') : '');
        $waTime  = '';
        if ($facts['boardingTime'] !== '') {
            $waTime = date('g:i A', strtotime($facts['boardingTime']));
        } elseif ($facts['depTime'] !== '') {
            $waTime = date('g:i A', strtotime($facts['depTime']));
        }
        // Nepali date
        $waDateNep = '';
        if ($facts['date'] !== '') {
            $months = [1=>'जनवरी','फेब्रुअरी','मार्च','अप्रिल','मे','जुन',
                       'जुलाई','अगस्ट','सेप्टेम्बर','अक्टोबर','नोभेम्बर','डिसेम्बर'];
            $ts = strtotime($facts['date']);
            if ($ts !== false) {
                $nd = strtr((string) date('d', $ts), ['0'=>'०','1'=>'१','2'=>'२','3'=>'३','4'=>'४','5'=>'५','6'=>'६','7'=>'७','8'=>'८','9'=>'९']);
                $nm = $months[(int) date('n', $ts)] ?? '';
                $ny = strtr(date('Y', $ts), ['0'=>'०','1'=>'१','2'=>'२','3'=>'३','4'=>'४','5'=>'५','6'=>'६','7'=>'७','8'=>'८','9'=>'९']);
                $waDateNep = $nd . ' ' . $nm . ' ' . $ny;
            }
        }

        // WhatsApp: bilingual Nepali message with dynamic boarding data.
        if (Settings::getBool('whatsapp_notify_customer', true) && self::usablePhone($booking['contact_phone'] ?? '') !== '') {
            $text = "नमस्कार 🙏\n\n"
                  . $company . " बाट तपाईंको बस टिकट सफलतापूर्वक बुक भएको छ।\n\n"
                  . "बुकिङ नं.: " . $pnr . "\n"
                  . ($waBoard !== '' ? "चढ्ने ठाउँ: " . $waBoard . "\n" : '')
                  . ($waTime !== '' ? "समय: " . $waTime . "\n" : '')
                  . ($waDrop !== '' ? "गन्तव्य: " . $waDrop . "\n" : '')
                  . ($facts['seats'] !== '' ? "सिट: " . $facts['seats'] . "\n" : '')
                  . ($waDateNep !== '' ? "यात्रा मिति: " . $waDateNep . "\n" : '')
                  . "\nजम्मा: " . $amount . "\n\n"
                  . "तपाईंको E-Ticket: " . $ticketUrl . "\n\n"
                  . "धन्यवाद।\n" . $company;
            $mediaUrl = Settings::getBool('whatsapp_send_pdf', true) ? $ticketUrl : null;

            // Variables for the approved WhatsApp template, used only when
            // twilio_content_sid is configured (see whatsappTwilio()). Built
            // by ticketTemplateVars() so the manual Resend button sends the
            // identical contract — {{7}} carries the ticket PNG regardless of
            // whatsapp_send_pdf, which only governs the free-form path where
            // media is a real attachment.
            $templateVars = self::ticketTemplateVars($booking);

            // $bid ties the message_logs row to the booking, which is what
            // lets admin/messages-log.php name the customer who missed their
            // ticket and lets cron/whatsapp-retry.php find it again later.
            self::whatsapp((string) $booking['contact_phone'], $text, $mediaUrl, self::countryHint($booking), $templateVars, $bid > 0 ? $bid : null, ['purpose' => 'ticket']);
        }

        // SMS: compact plain text carrying the required Ticket No / Seat /
        // Route / Date. Sent only when the SMS channel is enabled.
        if (Settings::getBool('sms_notify_customer', true) && !empty($booking['contact_phone'])) {
            $sms = $company . ': Ticket ' . $pnr . ' CONFIRMED.'
                 . ($facts['seats'] !== '' ? ' Seat ' . $facts['seats'] . '.' : '')
                 . ($facts['route'] !== '' ? ' ' . $facts['route'] . '.' : '')
                 . ($facts['date']  !== '' ? ' ' . $when . '.' : '')
                 . ' Ticket: ' . $ticketUrl;
            self::sms((string) $booking['contact_phone'], $sms, self::countryHint($booking), $bid);
        }
    }

    public static function paymentRejected(array $booking, string $reason): void
    {
        $company = Settings::getString('company_name', APP_NAME);
        $pnr     = (string) $booking['pnr'];

        $html = self::wrapEmail(
            'Payment could not be verified',
            '<p>Namaste,</p>'
            . '<p>We could not verify the payment for booking <strong>' . e($pnr) . '</strong>.</p>'
            . '<p>Reason: ' . e($reason) . '</p>'
            . '<p>Please re-submit your payment proof or contact us at '
            . e(Settings::officePhone()) . '.</p>'
            . '<p>' . e($company) . '</p>'
        );

        if (!empty($booking['contact_email'])) {
            self::email((string) $booking['contact_email'], 'Action needed · ' . $pnr, $html);
        }

        if (Settings::getBool('whatsapp_notify_customer', true) && self::usablePhone($booking['contact_phone'] ?? '') !== '') {
            $text = "🚌 " . $company . "\n"
                  . "बुकिङ " . $pnr . ": भुक्तानी पुष्टि हुन सकेन।\n"
                  . "कारण: " . $reason . "\n"
                  . "कृपया भुक्तानीको प्रमाण फेरि पठाउनुहोस् वा फोन गर्नुहोस्: " . Settings::officePhone() . "।";
            self::whatsapp((string) $booking['contact_phone'], $text, null, self::countryHint($booking), [
                '1' => $pnr,
                '2' => $reason !== '' ? $reason : '-',
                '3' => Settings::officePhone(),
            ], (int) ($booking['id'] ?? 0) ?: null, ['template_name' => 'shg_payment_rejected', 'purpose' => 'payment_rejected']);
        }
    }

    /**
     * A new booking has just been placed — alert admin (WhatsApp + email
     * if configured). Falls back to a click-to-chat link stored in the
     * app log so a staff member can send it in one tap.
     */
    /**
     * A one-line note to the office (21 Sep 2026, owner: "admin update de
     * rakhos").
     *
     * A SALE already reaches the office: BookingService::create emits
     * booking.created and bookingReceived() alerts them. A CORRECTION did
     * not. A name changed in the WhatsApp chat wrote an audit row and
     * nothing else, so the desk could hand a boarding list to the driver
     * with a name that had been corrected an hour earlier and never know
     * it had moved.
     *
     * Deliberately best-effort and deliberately quiet: it never throws, it
     * is logged under its own purpose so it can never be mistaken for a
     * passenger's ticket by the retry cron, and it obeys the same
     * whatsapp_notify_admin switch every other office alert does.
     */
    public static function adminNote(string $headline, array $facts = [], ?int $bookingId = null): void
    {
        try {
            if (!Settings::getBool('whatsapp_notify_admin', true)) {
                return;
            }
            $adminPhone = Settings::getString('admin_whatsapp', Settings::officePhone());
            if (trim($adminPhone) === '') {
                return;
            }
            $lines = [$headline];
            foreach ($facts as $k => $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $lines[] = $k . ': ' . $v;
                }
            }
            self::whatsapp($adminPhone, implode("\n", $lines), null, null, [],
                $bookingId !== null && $bookingId > 0 ? $bookingId : null,
                ['purpose' => 'admin_note']);
        } catch (Throwable $e) {
            Logger::warning('Admin note not sent: ' . $e->getMessage(), [], 'whatsapp');
        }
    }

    public static function bookingReceived(array $booking): void
    {
        $company    = Settings::getString('company_name', APP_NAME);
        $adminPhone = Settings::getString('admin_whatsapp', Settings::officePhone());
        $adminEmail = Settings::getString('admin_email', Settings::getString('company_email', ''));
        $pnr        = (string) $booking['pnr'];

        $lines = [
            "🎫 नयाँ बुकिङ " . $pnr,
            "जम्मा: " . inr((float) $booking['total_amount']),
            "फोन: " . ($booking['contact_phone'] ?? '—'),
            "तरिका: " . strtoupper((string) ($booking['payment_method'] ?? 'upi')),
            "जाँच्नुहोस्: " . appUrl('admin/payments.php?pnr=' . urlencode($pnr)),
        ];
        $text = implode("\n", $lines);

        if (Settings::getBool('whatsapp_notify_admin', true) && $adminPhone !== '') {
            self::whatsapp($adminPhone, $text);
        }

        if ($adminEmail !== '') {
            $html = self::wrapEmail('New booking · ' . $pnr,
                '<p>A customer has just placed a booking.</p><pre style="font-family:monospace;font-size:13px">' . e($text) . '</pre>');
            self::email($adminEmail, 'New booking · ' . $pnr, $html);
        }
    }

    /**
     * Customer cancelled a confirmed booking — alert admin so the refund
     * can be actioned.
     */
    public static function bookingCancelled(array $booking, array $refund): void
    {
        $company    = Settings::getString('company_name', APP_NAME);
        $adminPhone = Settings::getString('admin_whatsapp', Settings::officePhone());
        $pnr        = (string) $booking['pnr'];

        if (Settings::getBool('whatsapp_notify_customer', true) && self::usablePhone($booking['contact_phone'] ?? '') !== '') {
            $text = "🚌 " . $company . "\n"
                  . "बुकिङ " . $pnr . " रद्द भयो।\n"
                  . "फिर्ता: " . inr((float) ($refund['amount'] ?? 0)) . " (" . (int) ($refund['percent'] ?? 0) . "%)\n"
                  . ($refund['reason'] ?? '');
            $cFacts = self::ticketFacts($booking);
            self::whatsapp((string) $booking['contact_phone'], $text, null, self::countryHint($booking), [
                '1' => $pnr,
                '2' => $cFacts['route'] !== '' ? $cFacts['route'] : '-',
                '3' => $cFacts['date']  !== '' ? $cFacts['date']  : '-',
                '4' => inr((float) ($refund['amount'] ?? 0)) . ' (' . (int) ($refund['percent'] ?? 0) . '%)',
            ], (int) ($booking['id'] ?? 0) ?: null, ['template_name' => 'shg_booking_cancelled', 'purpose' => 'booking_cancelled']);
        }

        if (Settings::getBool('whatsapp_notify_admin', true) && $adminPhone !== '') {
            $text = "🚫 बुकिङ " . $pnr . " ग्राहकले रद्द गरे।\n"
                  . "फिर्ता: " . inr((float) ($refund['amount'] ?? 0)) . " (" . (int) ($refund['percent'] ?? 0) . "%)\n"
                  . "फोन: " . ($booking['contact_phone'] ?? '—');
            self::whatsapp($adminPhone, $text);
        }
    }

    /**
     * A passenger undid a QuickBot one-tap ticket inside the free window
     * (7 Sep 2026). Nothing was paid and the seat is already back in the
     * pool, so the "CANCELLED · Refund ₹0.00 (0%)" wording above would only
     * alarm them, and the office does not need a refund alert for money that
     * never arrived — the desk sees it in the admin bell and the register.
     * The passenger gets one short confirmation; the selling agent's
     * "commission reversed" copy still goes out from agentBookingCancelled().
     */
    public static function bookingUndone(array $booking): void
    {
        if (!Settings::getBool('whatsapp_notify_customer', true) || empty($booking['contact_phone'])) {
            return;
        }
        $company = Settings::getString('company_name', APP_NAME);
        $pnr     = (string) ($booking['pnr'] ?? '');
        $text = "↩️ " . $company . "\n"
              . "टिकट " . $pnr . " रद्द भयो — केही तिर्नु पर्दैन।\n"
              . "फेरि बुक गर्न सक्नुहुन्छ: " . appUrl('');
        self::whatsapp((string) $booking['contact_phone'], $text, null, self::countryHint($booking));
    }

    /* =================================================================
     *  Automation layer — customer acks, admin alerts, agent messages.
     *
     *  All of these are fired by EventBus listeners (includes/events.php)
     *  from post-commit positions. Every method degrades to a no-op when
     *  its Settings toggle is off or the recipient has no usable number.
     * ================================================================= */

    /**
     * A phone we may actually message: placeholder numbers written by
     * counter sales ('0000000000') and empty strings both count as none.
     */
    /* PUBLIC since 8 Sep 2026 so the ADMIN UI can ask the same question. The
       placeholder was suppressed on every passenger-facing surface (WhatsApp,
       the PNG ticket, the Chalani PDF, the Brain) but not in the office: the
       booking page rendered a live wa.me/910000000000 button, so "WhatsApp
       Passenger" on a walk-in messaged a stranger about someone else's trip. */
    public static function usablePhone(mixed $phone): string
    {
        $phone  = trim((string) $phone);
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if ($digits === '' || preg_match('/^0+$/', $digits)) {
            return '';
        }
        return $phone;
    }

    /**
     * The counter agent who sold this booking (admins row), or null for
     * online bookings. Never throws.
     *
     * @return array{id: int, name: string, phone: string, email: string, code: string}|null
     */
    private static function agentFor(array $booking): ?array
    {
        $adminId = (int) ($booking['sold_by_admin_id'] ?? 0);
        if ($adminId <= 0) {
            return null;
        }

        try {
            $row = Database::fetch(
                'SELECT id, full_name, phone, email FROM admins WHERE id = :id AND is_active = 1 LIMIT 1',
                ['id' => $adminId]
            );
            if ($row === null) {
                return null;
            }

            require_once __DIR__ . '/agentwallet.php';
            $code = '';
            try {
                $code = AgentWallet::agentCodeLabel($adminId);
            } catch (Throwable $ignored) {
            }
            /* 17 Sep 2026: the agent's WhatsApp number (profile whatsapp, else
               admins.phone) AND its country — a Nepali agent used to be
               dialled as +91 because only bookings knew their country. */
            $phone = self::usablePhone($row['phone'] ?? '');
            $hint  = null;
            try {
                require_once __DIR__ . '/watemplates.php';
                $rc = WaTemplates::agentRecipient($adminId);
                if ($rc['digits'] !== '' && self::usablePhone($rc['digits']) !== '') {
                    $phone = self::usablePhone($rc['digits']);
                }
                $hint = $rc['hint'];
            } catch (Throwable $ignored) {
            }

            return [
                'id'    => (int) $row['id'],
                'name'  => (string) ($row['full_name'] ?? ''),
                'phone' => $phone,
                'email' => (string) ($row['email'] ?? ''),
                'code'  => $code,
                'hint'  => $hint,
            ];
        } catch (Throwable $e) {
            Logger::error('agentFor failed: ' . $e->getMessage(), ['admin_id' => $adminId], 'automation');
            return null;
        }
    }

    /**
     * Customer ack the moment an ONLINE booking is placed: PNR + how to
     * finish paying. (COD bookings confirm instantly and get
     * bookingConfirmed instead.)
     */
    public static function bookingPlaced(array $booking): void
    {
        if (!Settings::getBool('notify_customer_on_create', true)) {
            return;
        }

        $company = Settings::getString('company_name', APP_NAME);
        $pnr     = (string) ($booking['pnr'] ?? '');
        $total   = (float) ($booking['total_amount'] ?? 0);
        $amount  = inr($total);
        $phone   = self::usablePhone($booking['contact_phone'] ?? '');

        if (Settings::getBool('whatsapp_notify_customer', true) && $phone !== '') {
            $upiId   = Settings::getString('upi_id', '');
            // WhatsApp only auto-links http(s), not upi:// — send the
            // tappable pay.php redirect that turns into upi://pay on tap.
            $payUrl = ($upiId !== '' && $total > 0)
                    ? appUrl('pay.php?pnr=' . urlencode($pnr))
                    : '';
            // Ticket-look PNG with the big UPI-pay QR + amount, so the
            // customer sees a scannable image the moment the WhatsApp
            // message opens (no typing, no copy-paste).
            $payImg = ($upiId !== '' && $total > 0)
                    ? appUrl('pay-image.php?pnr=' . urlencode($pnr))
                    : null;

            $text = "🚌 " . $company . "\n"
                  . "तपाईंको बुकिङ प्राप्त भयो! बुकिङ नं.: " . $pnr . "\n"
                  . "जम्मा: " . $amount . "\n";

            if ($upiId !== '') {
                $text .= "\n💰 यहाँ तिर्नुहोस् · Pay Here\n"
                       . "UPI: " . $upiId . "\n";
                if ($payUrl !== '') {
                    $text .= "टेप गरेर तिर्नुहोस्:\n" . $payUrl . "\n";
                }
                $text .= "माथिको QR स्क्यान गर्नुहोस् · Or scan the QR above\n\n";
            }

            $text .= "भुक्तानी पछि प्रमाण अपलोड गर्नुहोस्:\n"
                   . appUrl('') . "\n"
                   . "सहयोग: " . Settings::officePhone();
            $facts = self::ticketFacts($booking);
            self::whatsapp($phone, $text, $payImg, self::countryHint($booking), [
                '1' => $pnr,
                '2' => $facts['route'] !== '' ? $facts['route'] : '-',
                '3' => $facts['date']  !== '' ? $facts['date']  : '-',
                '4' => $amount,
            ], (int) ($booking['id'] ?? 0) ?: null, ['template_name' => 'shg_booking_received', 'purpose' => 'booking_received']);
        }

        if (!empty($booking['contact_email'])) {
            $html = self::wrapEmail(
                'Booking received',
                '<p>Namaste,</p>'
                . '<p>Your booking <strong>' . e($pnr) . '</strong> is registered.</p>'
                . '<p>Amount: <strong>' . e($amount) . '</strong></p>'
                . '<p>Please upload your payment proof on the website to confirm your seat. '
                . 'Your seats are held for a limited time.</p>'
                . '<p>' . e($company) . '</p>'
            );
            self::email((string) $booking['contact_email'], 'Booking received · ' . $pnr, $html);
        }
    }

    /**
     * Payment proof landed (UTR typed and/or screenshot uploaded) — ping
     * the admin to review it, and reassure the customer.
     *
     * Deduped per booking per hour (the checkout posts UTR and screenshot
     * as two separate calls) via the automation_log claim.
     */
    public static function paymentProofUploaded(array $booking, string $kind = ''): void
    {
        if (!Settings::getBool('notify_proof_uploaded', true)) {
            return;
        }

        $bid = (int) ($booking['id'] ?? 0);
        $pnr = (string) ($booking['pnr'] ?? '');

        // Toggle checked above, THEN the exactly-once claim (contract).
        if (class_exists('EventBus') && !EventBus::claim('proofalert:' . $bid . ':' . date('YmdH'), 'booking.payment_uploaded')) {
            return;
        }

        $adminPhone = Settings::getString('admin_whatsapp', Settings::officePhone());
        $adminEmail = Settings::getString('admin_email', Settings::getString('company_email', ''));
        $reviewUrl  = appUrl('admin/payments.php?pnr=' . urlencode($pnr));

        $text = "🧾 भुक्तानी प्रमाण अपलोड भयो · " . $pnr . "\n"
              . "जम्मा: " . inr((float) ($booking['total_amount'] ?? 0)) . "\n"
              . ($kind !== '' ? "प्रमाण: " . $kind . "\n" : '')
              . "जाँच्नुहोस्: " . $reviewUrl;

        if (Settings::getBool('whatsapp_notify_admin', true) && $adminPhone !== '') {
            self::whatsapp($adminPhone, $text);
        }
        if ($adminEmail !== '') {
            self::email($adminEmail, 'Payment proof uploaded · ' . $pnr,
                self::wrapEmail('Payment proof uploaded', '<pre style="font-family:monospace;font-size:13px">' . e($text) . '</pre>'));
        }

        $phone = self::usablePhone($booking['contact_phone'] ?? '');
        if (Settings::getBool('whatsapp_notify_customer', true) && $phone !== '') {
            $company = Settings::getString('company_name', APP_NAME);
            self::whatsapp(
                $phone,
                "🚌 " . $company . "\n" . $pnr . " को भुक्तानी प्रमाण प्राप्त भयो ✅\n"
                . "जाँच भइरहेको छ — छिट्टै तपाईंको टिकट पक्का गर्नेछौं।",
                null,
                self::countryHint($booking),
                ['1' => $pnr, '2' => inr((float) ($booking['total_amount'] ?? 0))],
                $bid > 0 ? $bid : null,
                ['template_name' => 'shg_payment_received', 'purpose' => 'payment_received']
            );
        }
    }

    /** WhatsApp the selling agent: their booking was approved + commission credited. */
    public static function agentBookingApproved(array $booking): void
    {
        if (!Settings::getBool('agent_notify_enabled', true)) {
            return;
        }
        $agent = self::agentFor($booking);
        if ($agent === null || $agent['phone'] === '') {
            return;
        }

        $pnr   = (string) ($booking['pnr'] ?? '');
        $facts = self::ticketFacts($booking);

        $commission = '';
        try {
            $amt = Database::scalar(
                "SELECT amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission' LIMIT 1",
                ['b' => (int) ($booking['id'] ?? 0)]
            );
            if ($amt !== null && (float) $amt > 0) {
                $commission = "कमिसन: " . inr((float) $amt) . " जम्मा भयो ✅\n";
            }
        } catch (Throwable $ignored) {
        }

        $text = "✅ बुकिङ " . $pnr . " स्वीकृत भयो\n"
              . ($facts['route'] !== '' ? "बाटो: " . $facts['route'] . "\n" : '')
              . ($facts['date'] !== ''  ? "मिति: " . $facts['date'] . "\n" : '')
              . ($facts['seats'] !== '' ? "सिट: " . $facts['seats'] . "\n" : '')
              . $commission
              . "— " . ($agent['code'] !== '' ? $agent['code'] . ' · ' : '') . Settings::getString('company_name', APP_NAME);
        self::whatsapp($agent['phone'], $text, null, $agent['hint'] ?? null);
    }

    /** WhatsApp the selling agent: booking rejected, customer asked to re-upload. */
    public static function agentBookingRejected(array $booking, string $reason = ''): void
    {
        if (!Settings::getBool('agent_notify_enabled', true)) {
            return;
        }
        $agent = self::agentFor($booking);
        if ($agent === null || $agent['phone'] === '') {
            return;
        }

        $text = "❌ बुकिङ " . (string) ($booking['pnr'] ?? '') . " अस्वीकृत भयो\n"
              . ($reason !== '' ? "कारण: " . $reason . "\n" : '')
              . "ग्राहकलाई भुक्तानी प्रमाण फेरि पठाउन भनिएको छ।";
        self::whatsapp($agent['phone'], $text, null, $agent['hint'] ?? null);
    }

    /** WhatsApp the selling agent: booking cancelled, commission reversed. */
    public static function agentBookingCancelled(array $booking): void
    {
        if (!Settings::getBool('agent_notify_enabled', true)) {
            return;
        }
        $agent = self::agentFor($booking);
        if ($agent === null || $agent['phone'] === '') {
            return;
        }

        $reversed = '';
        try {
            $amt = Database::scalar(
                "SELECT amount FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission_void' LIMIT 1",
                ['b' => (int) ($booking['id'] ?? 0)]
            );
            if ($amt !== null && (float) $amt < 0) {
                $reversed = "कमिसन " . inr(abs((float) $amt)) . " फिर्ता भयो।\n";
            }
        } catch (Throwable $ignored) {
        }

        $text = "🚫 बुकिङ " . (string) ($booking['pnr'] ?? '') . " रद्द भयो\n" . $reversed;
        self::whatsapp($agent['phone'], $text, null, $agent['hint'] ?? null);
    }

    /**
     * COD cash was settled at the counter — the ticket now reads PAID.
     * Tells the customer (fresh ticket link) and the selling agent.
     */
    public static function codSettled(array $booking): void
    {
        if (!Settings::getBool('notify_cod_settled', true)) {
            return;
        }

        $company = Settings::getString('company_name', APP_NAME);
        $pnr     = (string) ($booking['pnr'] ?? '');
        $amount  = inr((float) ($booking['total_amount'] ?? 0));
        $phone   = self::usablePhone($booking['contact_phone'] ?? '');

        if (Settings::getBool('whatsapp_notify_customer', true) && $phone !== '') {
            $text = "🚌 " . $company . "\n"
                  . $pnr . " को भुक्तानी प्राप्त भयो ✅\n"
                  . "जम्मा: " . $amount . "\n"
                  . "तपाईंको टिकटमा अब PAID देखिन्छ — नयाँ प्रति डाउनलोड गर्नुहोस्:\n"
                  . Ticket::imageUrl($pnr);
            self::whatsapp($phone, $text, null, self::countryHint($booking));
        }

        if (Settings::getBool('agent_notify_enabled', true)) {
            $agent = self::agentFor($booking);
            if ($agent !== null && $agent['phone'] !== '') {
                self::whatsapp(
                    $agent['phone'],
                    "💵 " . $pnr . " को नगद भुक्तानी भयो (" . $amount . ")।\n"
                    . "टिकट PAID मा अपडेट भयो।"
                );
            }
        }
    }

    /** Welcome message when an agent account is created in Staff. */
    public static function agentWelcome(int $adminId, string $name, string $phone): void
    {
        if (!Settings::getBool('agent_notify_enabled', true)) {
            return;
        }
        $phone = self::usablePhone($phone);
        if ($adminId <= 0 || $phone === '') {
            return;
        }

        $code = '';
        try {
            require_once __DIR__ . '/agentwallet.php';
            $code = AgentWallet::agentCodeLabel($adminId);
        } catch (Throwable $ignored) {
        }

        $company = Settings::getString('company_name', APP_NAME);
        $text = "🙏 " . $company . " मा स्वागत छ" . ($name !== '' ? ", " . $name : '') . "!\n"
              . ($code !== '' ? "तपाईंको एजेन्ट कोड: " . $code . "\n" : "तपाईंको एजेन्ट कोड अफिसले दिनेछ।\n")
              . "लगइन (एजेन्ट पोर्टल): " . appUrl('admin/login.php?portal=agent') . "\n"
              . "अफिसले दिएको इमेल, युजरनेम र पासवर्डले साइन इन गर्नुहोस्।";
        self::whatsapp($phone, $text);
    }

    /** Broadcast one message to every active agent (schedule changes etc.). */
    public static function agentScheduleAlert(string $message): int
    {
        if (!Settings::getBool('agent_notify_enabled', true) || trim($message) === '') {
            return 0;
        }

        $sent = 0;
        try {
            $agents = Database::fetchAll(
                "SELECT id, phone FROM admins WHERE role = 'agent' AND is_active = 1 AND COALESCE(phone,'') <> ''"
            );
            foreach ($agents as $a) {
                $phone = self::usablePhone($a['phone']);
                if ($phone === '') {
                    continue;
                }
                if (self::whatsapp($phone, "📢 " . Settings::getString('company_name', APP_NAME) . "\n" . trim($message)) === true) {
                    $sent++;
                }
            }
        } catch (Throwable $e) {
            Logger::error('agentScheduleAlert failed: ' . $e->getMessage(), [], 'automation');
        }
        return $sent;
    }

    /**
     * One agent's end-of-day summary (cron/daily-summary.php).
     *
     * @param array{id: int, name: string, phone: string, email: string, code: string} $agent
     * @param array{bookings: int, pax: int, commission: float, cash: float,
     *              balance_commission: float, balance_cash: float} $stats
     */
    public static function agentDailySummary(array $agent, array $stats): void
    {
        $company = Settings::getString('company_name', APP_NAME);
        $line = "📊 " . $company . " · Daily summary\n"
              . "Bookings today: " . (int) $stats['bookings'] . " (" . (int) $stats['pax'] . " pax)\n"
              . "Commission earned: " . inr((float) $stats['commission']) . "\n"
              . "Cash collected: " . inr((float) $stats['cash']) . "\n"
              . "Wallet — commission: " . inr((float) $stats['balance_commission'])
              . " · cash due: " . inr((float) $stats['balance_cash']);

        $phone = self::usablePhone($agent['phone'] ?? '');
        if ($phone !== '') {
            self::whatsapp($phone, $line);
        }

        if (!empty($agent['email'])) {
            $html = self::wrapEmail(
                'Your daily summary',
                '<p>Namaste' . (!empty($agent['name']) ? ' ' . e($agent['name']) : '') . ',</p>'
                . '<table cellpadding="6" style="border-collapse:collapse;font-size:14px">'
                . '<tr><td>Bookings today</td><td><strong>' . (int) $stats['bookings'] . '</strong> (' . (int) $stats['pax'] . ' pax)</td></tr>'
                . '<tr><td>Commission earned</td><td><strong>' . e(inr((float) $stats['commission'])) . '</strong></td></tr>'
                . '<tr><td>Cash collected</td><td><strong>' . e(inr((float) $stats['cash'])) . '</strong></td></tr>'
                . '<tr><td>Wallet commission balance</td><td>' . e(inr((float) $stats['balance_commission'])) . '</td></tr>'
                . '<tr><td>Cash due to company</td><td>' . e(inr((float) $stats['balance_cash'])) . '</td></tr>'
                . '</table>'
            );
            self::email((string) $agent['email'], 'Daily summary · ' . date('d M Y'), $html);
        }
    }

    /**
     * Admin end-of-day revenue digest (cron/daily-summary.php).
     *
     * @param array{date: string, bookings: int, pax: int, revenue: float,
     *              pending: int, routes: list<string>, agents: list<string>} $stats
     */
    public static function adminDailyDigest(array $stats): void
    {
        $adminPhone = Settings::getString('admin_whatsapp', Settings::officePhone());
        $adminEmail = Settings::getString('admin_email', Settings::getString('company_email', ''));

        $short = "📈 Today (" . $stats['date'] . ")\n"
               . "Bookings: " . (int) $stats['bookings'] . " (" . (int) $stats['pax'] . " pax)\n"
               . "Revenue: " . inr((float) $stats['revenue']) . "\n"
               . "Pending approvals: " . (int) $stats['pending'];

        if (Settings::getBool('whatsapp_notify_admin', true) && $adminPhone !== '') {
            self::whatsapp($adminPhone, $short);
        }

        if ($adminEmail !== '') {
            $routeRows = '';
            foreach ($stats['routes'] as $r) {
                $routeRows .= '<li>' . e($r) . '</li>';
            }
            $agentRows = '';
            foreach ($stats['agents'] as $a) {
                $agentRows .= '<li>' . e($a) . '</li>';
            }
            $html = self::wrapEmail(
                'Daily digest · ' . $stats['date'],
                '<p><strong>' . (int) $stats['bookings'] . '</strong> bookings · <strong>' . (int) $stats['pax'] . '</strong> passengers · '
                . '<strong>' . e(inr((float) $stats['revenue'])) . '</strong> revenue</p>'
                . '<p>Pending approvals: <strong>' . (int) $stats['pending'] . '</strong></p>'
                . ($routeRows !== '' ? '<p><strong>By route</strong></p><ul>' . $routeRows . '</ul>' : '')
                . ($agentRows !== '' ? '<p><strong>Top agents</strong></p><ul>' . $agentRows . '</ul>' : '')
            );
            self::email($adminEmail, 'Daily digest · ' . $stats['date'], $html);
        }
    }

    /**
     * Can an admin WhatsApp alert actually be delivered right now? The
     * exactly-once crons (alerts.php) MUST check this BEFORE they claim a
     * dedupe key — a claim taken while admin WhatsApp is off or unconfigured
     * would burn the one-per-schedule/booking alert forever. Kept here so the
     * gate can never drift from the one inside lowSeatAlert/pendingApprovalAlert.
     */
    public static function adminWhatsappReady(): bool
    {
        return Settings::getBool('whatsapp_notify_admin', true)
            && Settings::getString('admin_whatsapp', Settings::officePhone()) !== '';
    }

    /** Low remaining-seat warning to admin (cron/alerts.php, pre-claimed). */
    public static function lowSeatAlert(array $d): void
    {
        $adminPhone = Settings::getString('admin_whatsapp', Settings::officePhone());
        if (!Settings::getBool('whatsapp_notify_admin', true) || $adminPhone === '') {
            return;
        }
        self::whatsapp(
            $adminPhone,
            "⚠️ Only " . (int) ($d['remaining'] ?? 0) . " seats left for "
            . (string) ($d['date'] ?? '') . " · " . (string) ($d['route'] ?? '') . "\n"
            . "Board: " . appUrl('admin/seatmap.php')
        );
    }

    /** Unreviewed payment proofs waiting too long (cron/alerts.php, pre-claimed). */
    public static function pendingApprovalAlert(array $items): void
    {
        $adminPhone = Settings::getString('admin_whatsapp', Settings::officePhone());
        if (!Settings::getBool('whatsapp_notify_admin', true) || $adminPhone === '' || $items === []) {
            return;
        }
        $lines = ["⏰ Payment proofs waiting for review:"];
        foreach (array_slice($items, 0, 5) as $it) {
            $lines[] = "• " . (string) ($it['pnr'] ?? '') . " · " . inr((float) ($it['amount'] ?? 0))
                     . " · " . (int) ($it['minutes'] ?? 0) . " min";
        }
        if (count($items) > 5) {
            $lines[] = "…and " . (count($items) - 5) . " more.";
        }
        $lines[] = "Review: " . appUrl('admin/payments.php');
        self::whatsapp($adminPhone, implode("\n", $lines));
    }

    /**
     * Admin "send test email" diagnostic (mirrors whatsappTest/smsTest).
     *
     * @return array{ok: bool, stage: string, detail: string}
     */
    public static function emailTest(string $to): array
    {
        $to = Security::email($to);
        if ($to === '') {
            return ['ok' => false, 'stage' => 'input', 'detail' => 'That does not look like an email address.'];
        }
        if (!Settings::getBool('email_enabled', true)) {
            return ['ok' => false, 'stage' => 'config', 'detail' => 'Email is switched off (email_enabled) in Automation settings.'];
        }
        if (Settings::getString('smtp_host', '') === '') {
            return ['ok' => false, 'stage' => 'config', 'detail' => 'No SMTP host configured — set smtp_host (e.g. smtp.gmail.com), smtp_user and a Gmail App Password in Notify settings. Without SMTP, PHP mail() is used, which most servers cannot deliver from.'];
        }

        $ok = self::email(
            $to,
            'Test email · ' . Settings::getString('company_name', APP_NAME),
            self::wrapEmail('SMTP test ✅', '<p>If you can read this, outgoing email works.</p><p>Sent ' . e(date('d M Y H:i:s')) . '</p>')
        );

        return $ok
            ? ['ok' => true, 'stage' => 'sent', 'detail' => 'Test email sent to ' . $to . ' — check the inbox (and spam).']
            : ['ok' => false, 'stage' => 'send', 'detail' => 'SMTP send failed — for Gmail use smtp.gmail.com : 587 : tls with a 16-character App Password (not the account password). Details in Admin → Activity/Logs (mail channel).'];
    }

    /* =================================================================
     *  Trip lifecycle — automatic reminders and journey updates
     *
     *  V6 asks for these to fire with no admin action:
     *    12 hours before departure · 2 hours before departure ·
     *    bus started · reached border · arrived.
     *
     *  The 12h/2h reminders are driven by cron/reminders.php; the three
     *  journey events are driven by one click on Admin -> Trips. Both go
     *  through tripEvent() so the wording, the channel choice and the
     *  message log stay identical whichever path fired them.
     * ================================================================= */

    /** The lifecycle events, in the order a journey goes through them. */
    public const TRIP_EVENTS = ['reminder_12h', 'reminder_2h', 'delayed', 'departed', 'border', 'arrived', 'bus_near'];

    /**
     * Is this lifecycle event switched on? Callers check this BEFORE they
     * claim a row in trip_events — a claim made while the channel is off
     * would permanently suppress the message once it is switched back on.
     */
    public static function tripEventEnabled(string $event): bool
    {
        if (!Settings::getBool('trip_reminders_enabled', true)) {
            return false;
        }

        return match ($event) {
            'reminder_12h' => Settings::getBool('reminder_12h_enabled', true),
            'reminder_2h'  => Settings::getBool('reminder_2h_enabled', true),
            'departed', 'border', 'arrived' => Settings::getBool('trip_status_notify', true),
            // 19 Sep 2026: GPS says the coach is ~30 min from the passenger's own
            // pickup (includes/etaalerts.php). Its own switch, off by default.
            'bus_near'     => Settings::getBool('eta_alert_on', false),
            default        => false,
        };
    }

    /**
     * Send one lifecycle message for one booking, over WhatsApp and SMS.
     *
     * $ctx lets a caller that has already joined the leg (the cron, the
     * admin fan-out) pass route / date / time / seats straight through
     * instead of paying for two more queries per passenger. Anything
     * missing is filled in from ticketFacts().
     *
     * @param array<string, mixed> $booking bookings row (needs id, pnr, contact_phone)
     * @param array<string, mixed> $ctx     route|date|depTime|seats|boarding
     * @return array{ok: bool, channels: string, detail: string}
     */
    public static function tripEvent(array $booking, string $event, array $ctx = []): array
    {
        if (!in_array($event, self::TRIP_EVENTS, true)) {
            return ['ok' => false, 'channels' => '', 'detail' => 'Unknown trip event.'];
        }

        // usablePhone(), not just === '': counter sales store the placeholder
        // '0000000000', which would otherwise be sent five lifecycle messages
        // (intlDigits turns it into +91 0000000000 for a real Twilio attempt).
        $phone = self::usablePhone($booking['contact_phone'] ?? '');
        if ($phone === '') {
            return ['ok' => false, 'channels' => '', 'detail' => 'Booking has no usable contact number.'];
        }

        // Drop blank context keys first: array-union (+=) never overwrites an
        // existing key, so an empty 'route' in $ctx would otherwise mask the
        // real one that ticketFacts() can look up.
        $facts = array_filter(
            array_map(static fn($v): string => trim((string) $v), $ctx),
            static fn(string $v): bool => $v !== ''
        );
        if (($facts['route'] ?? '') === '' || ($facts['seats'] ?? '') === '') {
            $facts += self::ticketFacts($booking);
        }
        $facts += ['route' => '', 'date' => '', 'depTime' => '', 'seats' => '', 'boarding' => ''];

        $company = Settings::getString('company_name', APP_NAME);
        $pnr     = (string) ($booking['pnr'] ?? '');
        $hint    = self::countryHint($booking);
        $bid     = (int) ($booking['id'] ?? 0);

        $body = self::tripEventBody($event, $company, $pnr, $facts);

        $channels = [];
        $ok       = false;

        if (Settings::getBool('whatsapp_notify_customer', true)) {
            $wa = self::whatsapp($phone, $body['whatsapp'], null, $hint);
            if ($wa === true) {
                $channels[] = 'wa';
                $ok = true;
            }
            // A string return is the click-to-chat fallback (no driver
            // configured) — already logged by whatsapp(); not a delivery.
        }

        if (Settings::getBool('sms_notify_customer', true)) {
            if (self::sms($phone, $body['sms'], $hint, $bid > 0 ? $bid : null)) {
                $channels[] = 'sms';
                $ok = true;
            }
        }

        /* Web Push (13 Sep 2026): the installed app's own channel — free,
           instant, and independent of the WhatsApp sender (63112). Counted
           as a channel when at least one phone accepted it; never blocks
           or throws — a push failure must not turn a sent WhatsApp into a
           "failed" event row. */
        try {
            if (!class_exists('WebPush')) {
                require_once __DIR__ . '/webpush.php';
            }
            if ($bid > 0 && WebPush::enabled()) {
                $push = WebPush::sendToBooking($bid, WebPush::tripEventPayload($event, $pnr, $facts, $company), $event);
                if ($push['sent'] > 0) {
                    $channels[] = 'push';
                    $ok = true;
                }
            }
        } catch (Throwable $e) {
            Logger::warning('WebPush trip event failed', ['pnr' => $pnr, 'event' => $event, 'err' => $e->getMessage()]);
        }

        return [
            'ok'       => $ok,
            'channels' => implode(',', $channels),
            'detail'   => $ok
                ? 'Sent via ' . implode(' + ', $channels)
                : 'No channel delivered it — check Settings -> Test WhatsApp / Test SMS.',
        ];
    }

    /**
     * The wording for each lifecycle event. Deliberately short, bilingual
     * (English + Nepali/Hindi) and free of jargon — most passengers on this
     * route read a phone screen slowly, so the first line must already say
     * what happened.
     *
     * @param array{route:string,date:string,depTime:string,seats:string,boarding:string} $f
     * @return array{whatsapp: string, sms: string}
     */
    private static function tripEventBody(string $event, string $company, string $pnr, array $f): array
    {
        $when     = trim($f['date'] . ($f['depTime'] !== '' ? ' · ' . $f['depTime'] : ''));
        $seatLine = $f['seats'] !== '' ? 'Seat: ' . $f['seats'] . "\n" : '';
        $routeLn  = $f['route'] !== '' ? 'Route: ' . $f['route'] . "\n" : '';
        $boardLn  = $f['boarding'] !== '' ? 'Boarding: ' . $f['boarding'] . "\n" : '';
        $helpline = Settings::officePhone();
        $border   = Settings::getString('border_point_name', 'Rupaidiha ⇄ Jamunaha');
        $destCity = '';
        if ($f['route'] !== '' && str_contains($f['route'], '→')) {
            $destCity = trim((string) (explode('→', $f['route'])[1] ?? ''));
        }

        return match ($event) {
            'reminder_12h' => [
                'whatsapp' => "🚌 " . $company . "\n"
                    . "Yatra kal / भोलि यात्रा — booking " . $pnr . "\n"
                    . $routeLn . ($when !== '' ? "Departure: " . $when . "\n" : '') . $seatLine . $boardLn
                    . "\n📄 Photo ID sathai lyaunuhos — border ma chahincha.\n"
                    . "Carry a valid photo ID for the border crossing.\n"
                    . ($helpline !== '' ? "Help: " . $helpline : ''),
                'sms' => $company . ': Trip reminder ' . $pnr . '.'
                    . ($f['route'] !== '' ? ' ' . $f['route'] . '.' : '')
                    . ($when !== '' ? ' Departs ' . $when . '.' : '')
                    . ($f['seats'] !== '' ? ' Seat ' . $f['seats'] . '.' : '')
                    . ' Carry photo ID for the border.',
            ],
            'reminder_2h' => [
                'whatsapp' => "⏰ " . $company . "\n"
                    . "2 ghanta ma bus chhutcha / 2 घंटे में बस — booking " . $pnr . "\n"
                    . $routeLn . ($when !== '' ? "Departure: " . $when . "\n" : '') . $seatLine . $boardLn
                    . "\n🎒 Boarding point ma samayamai pugnuhos.\n"
                    . "Please reach the boarding point on time.\n"
                    . ($helpline !== '' ? "Help: " . $helpline : ''),
                'sms' => $company . ': Your bus departs in about 2 hours. ' . $pnr . '.'
                    . ($when !== '' ? ' ' . $when . '.' : '')
                    . ($f['seats'] !== '' ? ' Seat ' . $f['seats'] . '.' : '')
                    . ' Please reach the boarding point on time.',
            ],
            'delayed' => [
                'whatsapp' => "⏳ " . $company . "\n"
                    . "Bus dhila bhayo / बस में देरी — " . $pnr . "\n"
                    . $routeLn . ($when !== '' ? "Original departure: " . $when . "\n" : '')
                    . (!empty($f['delayMinutes']) ? "Delay: ~" . $f['delayMinutes'] . " min\n" : '')
                    . (!empty($f['delayNote']) ? "Note: " . $f['delayNote'] . "\n" : '')
                    . "\nKripaya dhairya rakhnuhos. Updated time ma bus chhalchha.\n"
                    . "We apologise for the delay. The bus will depart at the updated time.\n"
                    . ($helpline !== '' ? "Help: " . $helpline : ''),
                'sms' => $company . ': Your bus is delayed. ' . $pnr . '.'
                    . (!empty($f['delayMinutes']) ? ' ~' . $f['delayMinutes'] . ' min delay.' : '')
                    . ' We apologise for the inconvenience.',
            ],
            'departed' => [
                'whatsapp' => "🚌 " . $company . "\n"
                    . "Bus hindyo / बस चल पड़ी — " . $pnr . "\n"
                    . $routeLn . $seatLine
                    . "\nYour bus has started its journey. Safe travels 🙏",
                'sms' => $company . ': Your bus has started. ' . $pnr . '.'
                    . ($f['route'] !== '' ? ' ' . $f['route'] . '.' : '') . ' Safe journey.',
            ],
            'border' => [
                'whatsapp' => "🛂 " . $company . "\n"
                    . "Bus border pugyo / बस बॉर्डर पहुँची — " . $pnr . "\n"
                    . "Border: " . $border . "\n"
                    . "\n📄 Aafno photo ID tayar rakhnuhos.\n"
                    . "Please keep your photo ID ready for the crossing.",
                'sms' => $company . ': Your bus has reached the border (' . $border . '). '
                    . $pnr . '. Keep your photo ID ready.',
            ],
            'arrived' => [
                'whatsapp' => "✅ " . $company . "\n"
                    . "Bus pugyo / बस पहुँच गई — " . $pnr . "\n"
                    . ($destCity !== '' ? "Arrived at: " . $destCity . "\n" : '')
                    . "\nThank you for travelling with us 🙏\n"
                    . "Feriparne ma feri bhetaula.",
                'sms' => $company . ': Your bus has arrived'
                    . ($destCity !== '' ? ' at ' . $destCity : '') . '. ' . $pnr
                    . '. Thank you for travelling with us.',
            ],
            'bus_near' => [
                'whatsapp' => "🚌 " . $company . "
"
                    . "बस नजिकै आइपुग्यो — " . $pnr . "
"
                    . ($f['boarding'] !== '' ? "तपाईंको चढ्ने ठाउँ: " . $f['boarding'] . "
" : '')
                    . "बस करिब " . (string) ($f['etaMin'] ?? '30') . " मिनेटमा आइपुग्छ। कृपया तयार भएर बस्नुहोस्।
"
                    . ($f['seats'] !== '' ? "सिट: " . $f['seats'] . "
" : '')
                    . ($helpline !== '' ? "सहयोग: " . $helpline : ''),
                'sms' => $company . ': Bus is about ' . (string) ($f['etaMin'] ?? '30') . ' min from '
                    . ($f['boarding'] !== '' ? $f['boarding'] : 'your stop') . '. Please be ready. ' . $pnr,
            ],
            default => ['whatsapp' => '', 'sms' => ''],
        };
    }


    /* =================================================================
     *  Refund desk
     * ================================================================= */

    /**
     * A refund was approved by the accounts desk — tell the customer how
     * much is coming back and against which reference.
     */
    public static function refundProcessed(array $booking, float $amount, string $ref = ''): void
    {
        if (!Settings::getBool('refund_notify_customer', true)) {
            return;
        }

        $company = Settings::getString('company_name', APP_NAME);
        $pnr     = (string) ($booking['pnr'] ?? '');
        $money   = inr($amount);

        if (!empty($booking['contact_email'])) {
            self::email(
                (string) $booking['contact_email'],
                'Refund approved · ' . $pnr,
                self::wrapEmail(
                    'Refund approved ✅',
                    '<p>Namaste,</p>'
                    . '<p>Your refund for booking <strong>' . e($pnr) . '</strong> has been approved.</p>'
                    . '<p>Amount: <strong>' . e($money) . '</strong></p>'
                    . ($ref !== '' ? '<p>Reference: <strong>' . e($ref) . '</strong></p>' : '')
                    . '<p>It normally reaches your account within 5–7 working days, depending on your bank.</p>'
                    . '<p>' . e($company) . '</p>'
                )
            );
        }

        if (empty($booking['contact_phone'])) {
            return;
        }

        $text = "💰 " . $company . "\n"
              . "Refund approved / रिफंड स्वीकृत — " . $pnr . "\n"
              . "Amount: " . $money . "\n"
              . ($ref !== '' ? "Reference: " . $ref . "\n" : '')
              . "\n5–7 working days bhitra tapaiko account ma pugcha.\n"
              . "It should reach your account within 5–7 working days.";

        self::whatsapp((string) $booking['contact_phone'], $text, null, self::countryHint($booking));
        self::sms(
            (string) $booking['contact_phone'],
            $company . ': Refund of ' . $money . ' approved for ' . $pnr
                . ($ref !== '' ? ' (ref ' . $ref . ')' : '') . '. Allow 5-7 working days.',
            self::countryHint($booking),
            (int) ($booking['id'] ?? 0) ?: null
        );
    }

    /**
     * A refund request was declined — say why, in plain language, and give
     * the customer somewhere to go with it.
     */
    public static function refundDenied(array $booking, string $reason): void
    {
        if (!Settings::getBool('refund_notify_customer', true)) {
            return;
        }

        $company  = Settings::getString('company_name', APP_NAME);
        $pnr      = (string) ($booking['pnr'] ?? '');
        $helpline = Settings::officePhone();

        if (!empty($booking['contact_email'])) {
            self::email(
                (string) $booking['contact_email'],
                'About your refund · ' . $pnr,
                self::wrapEmail(
                    'Refund not approved',
                    '<p>Namaste,</p>'
                    . '<p>We could not approve the refund for booking <strong>' . e($pnr) . '</strong>.</p>'
                    . '<p>Reason: ' . e($reason) . '</p>'
                    . ($helpline !== '' ? '<p>If you think this is a mistake, please call ' . e($helpline) . '.</p>' : '')
                    . '<p>' . e($company) . '</p>'
                )
            );
        }

        if (empty($booking['contact_phone'])) {
            return;
        }

        $text = "ℹ️ " . $company . "\n"
              . "Refund " . $pnr . " could not be approved.\n"
              . "Reason: " . $reason . "\n"
              . ($helpline !== '' ? "\nGalti jasto lagcha bhane call garnuhos: " . $helpline : '');

        self::whatsapp((string) $booking['contact_phone'], $text, null, self::countryHint($booking));
    }


    /**
     * Standard branded email shell.
     */
    public static function wrapEmail(string $heading, string $bodyHtml): string
    {
        $company = e(Settings::getString('company_name', APP_NAME));

        return '<!doctype html><html><body style="margin:0;background:#f4f4f7;font-family:Arial,Helvetica,sans-serif">'
            . '<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">'
            . '<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:10px;overflow:hidden">'
            . '<tr><td style="background:#1a3a6a;padding:22px 28px">'
            . '<span style="color:#fff;font-size:20px;font-weight:bold">' . $company . '</span><br>'
            . '<span style="color:#ffd700;font-size:12px">India ⇄ Nepal · International Bus</span></td></tr>'
            . '<tr><td style="padding:28px"><h2 style="margin:0 0 14px;color:#1a3a6a">' . e($heading) . '</h2>'
            . '<div style="color:#333;font-size:14px;line-height:1.6">' . $bodyHtml . '</div></td></tr>'
            . '<tr><td style="background:#faf7ff;padding:16px 28px;color:#888;font-size:12px">'
            . 'This is an automated message from ' . $company . '. Please do not reply.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }
}
