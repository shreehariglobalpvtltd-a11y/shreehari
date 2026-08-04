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

final class Notify
{
    /* =================================================================
     *  Email
     * ================================================================= */

    /**
     * Send an HTML email. Returns true on apparent success.
     *
     * Never throws — a mail failure is logged and swallowed so it can
     * never break a booking.
     */
    public static function email(string $toEmail, string $subject, string $htmlBody, string $textBody = ''): bool
    {
        $toEmail = Security::email($toEmail);
        if ($toEmail === '') {
            return false;
        }

        $host = Settings::getString('smtp_host', '');

        try {
            if ($host !== '') {
                return self::smtpSend($toEmail, $subject, $htmlBody, $textBody);
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
    private static function smtpSend(string $to, string $subject, string $htmlBody, string $textBody): bool
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
        $message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n\r\n";

        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n\r\n";
        $message .= ($textBody !== '' ? $textBody : strip_tags($htmlBody)) . "\r\n\r\n";

        $message .= '--' . $boundary . "\r\n";
        $message .= 'Content-Type: text/html; charset=UTF-8' . "\r\n\r\n";
        $message .= $htmlBody . "\r\n\r\n";
        $message .= '--' . $boundary . '--' . "\r\n";

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
     * @return bool|string true/false for API delivery, or a wa.me URL
     */
    public static function whatsapp(string $phone, string $message, ?string $mediaUrl = null, ?string $countryHint = null): bool|string
    {
        $driver = Settings::getString('whatsapp_driver', 'click_to_chat');
        $number = self::intlDigits($phone, $countryHint);

        if ($number === '') {
            return false;
        }

        if ($driver === 'twilio') {
            $sid   = Settings::getString('twilio_account_sid', '');
            $token = Settings::getString('twilio_auth_token', '');
            $from  = Settings::getString('twilio_whatsapp_from', '');

            if ($sid !== '' && $token !== '' && $from !== '') {
                return self::whatsappTwilio($number, $message, $mediaUrl, $sid, $token, $from);
            }
        } elseif ($driver === 'cloud_api') {
            $token   = Settings::getString('whatsapp_api_token', '');
            $phoneId = Settings::getString('whatsapp_phone_id', '');

            if ($token !== '' && $phoneId !== '') {
                return self::whatsappCloudApi($number, $message, $token, $phoneId, $mediaUrl);
            }
        }

        // No driver configured — log a click-to-chat link so staff can
        // still send the message with one tap from the admin logs/tools.
        $link = whatsappLink($number, $message);
        Logger::info('WhatsApp click-to-chat queued', ['to' => $number, 'link' => $link], 'whatsapp');
        return $link;
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
             . ' WhatsApp test. If you can read this, your automatic ticket alerts are working. 🎫';

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
                $sidOut = is_array($json) ? (string) ($json['sid'] ?? '') : '';
                return ['ok' => true, 'stage' => 'sent',
                    'detail' => 'Sent to +' . $number . ' — open WhatsApp on that phone to confirm.'
                        . ($sidOut !== '' ? ' (Twilio SID ' . $sidOut . ')' : '')];
            }
            $twMsg  = is_array($json) ? (string) ($json['message'] ?? '') : '';
            $twCode = is_array($json) ? (int) ($json['code'] ?? 0) : 0;
            Logger::error('WhatsApp test failed', ['status' => $status, 'code' => $twCode, 'resp' => $response], 'whatsapp');
            return ['ok' => false, 'stage' => 'api',
                'detail' => ($twMsg !== '' ? $twMsg : ('Twilio HTTP ' . $status))
                    . ($twCode ? ' (code ' . $twCode . ')' : '')
                    . self::twilioHint($twCode)
                    . ($curlErr !== '' ? ' · ' . $curlErr : '')];
        }

        if ($driver === 'cloud_api') {
            $ok = self::whatsapp($phone, $msg, null, $countryHint);
            return $ok === true
                ? ['ok' => true, 'stage' => 'sent', 'detail' => 'Sent to +' . $number . ' via Meta Cloud API — check WhatsApp on that phone.']
                : ['ok' => false, 'stage' => 'api', 'detail' => 'Meta Cloud API send failed — check whatsapp_api_token / whatsapp_phone_id and logs/ (channel: whatsapp).'];
        }

        return ['ok' => false, 'stage' => 'driver',
            'detail' => 'WhatsApp driver is "click-to-chat", which does not auto-send. Set whatsapp_driver to "twilio" above, Save, then test.'];
    }

    /** Friendly, actionable advice for the common Twilio WhatsApp error codes. */
    private static function twilioHint(int $code): string
    {
        return match ($code) {
            63015, 63016 => ' — Tip: the recipient must first message your sandbox number the join code (e.g. "join <word>") once. Production needs an approved WhatsApp sender.',
            63007        => ' — Tip: the WhatsApp sender (twilio_whatsapp_from) is not a valid WhatsApp Twilio number. For testing use the sandbox number whatsapp:+14155238886.',
            21211, 21214 => ' — Tip: the destination number looks invalid. Enter it in full international form (country code + number).',
            20003        => ' — Tip: authentication failed — the Account SID or Auth Token is wrong. Copy them again from the Twilio Console (SID starts with AC…).',
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
    public static function resendTicketWhatsApp(array $booking): array
    {
        $company = Settings::getString('company_name', APP_NAME);
        $pnr     = (string) ($booking['pnr'] ?? '');
        if (empty($booking['contact_phone'])) {
            return ['ok' => false, 'detail' => 'This booking has no contact phone number on file.'];
        }
        $ticketUrl = appUrl('download-ticket.php?pnr=' . urlencode($pnr));
        $text = "🚌 " . $company . "\n"
              . "Your booking " . $pnr . " is CONFIRMED ✅\n"
              . "Amount: " . inr((float) ($booking['total_amount'] ?? 0)) . "\n"
              . "Download your ticket: " . $ticketUrl . "\n"
              . "Have a safe journey.";
        $mediaUrl = Settings::getBool('whatsapp_send_pdf', true) ? $ticketUrl : null;
        $res = self::whatsapp((string) $booking['contact_phone'], $text, $mediaUrl, self::countryHint($booking));
        if ($res === true) {
            return ['ok' => true, 'detail' => 'Ticket re-sent to ' . $booking['contact_phone'] . ' on WhatsApp.'];
        }
        if (is_string($res) && $res !== '') {
            return ['ok' => false, 'link' => $res,
                'detail' => 'No WhatsApp API is configured, so nothing auto-sent. Set up Twilio in Settings (then use "Test WhatsApp"), or send via the click-to-chat link.'];
        }
        return ['ok' => false,
            'detail' => 'WhatsApp send failed. Use Settings → "Test WhatsApp" to see the exact Twilio error, or check logs/ (channel: whatsapp).'];
    }

    /**
     * Send through Twilio's WhatsApp REST API (Basic auth: SID:AuthToken).
     * A non-empty $mediaUrl is delivered as a media message (PDF/image).
     */
    private static function whatsappTwilio(string $toDigits, string $message, ?string $mediaUrl, string $sid, string $token, string $from): bool
    {
        // Accept the "From" as "whatsapp:+1415...", "+1415..." or "1415...".
        $from = trim($from);
        if (!str_starts_with($from, 'whatsapp:')) {
            $from = 'whatsapp:+' . (preg_replace('/\D/', '', $from) ?? '');
        }

        $endpoint = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';

        $fields = [
            'From' => $from,
            'To'   => 'whatsapp:+' . $toDigits,
            'Body' => $message,
        ];
        if ($mediaUrl !== null && $mediaUrl !== '') {
            $fields['MediaUrl'] = $mediaUrl;
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

        if ($status >= 200 && $status < 300) {
            return true;
        }

        Logger::error('WhatsApp Twilio API failed', ['status' => $status, 'resp' => $response, 'curl' => $curlErr], 'whatsapp');
        return false;
    }

    private static function whatsappCloudApi(string $number, string $message, string $token, string $phoneId, ?string $mediaUrl = null): bool
    {
        $endpoint = 'https://graph.facebook.com/v18.0/' . $phoneId . '/messages';

        if ($mediaUrl !== null && $mediaUrl !== '') {
            $body = [
                'messaging_product' => 'whatsapp',
                'to'                => $number,
                'type'              => 'document',
                'document'          => ['link' => $mediaUrl, 'filename' => 'ticket.pdf', 'caption' => $message],
            ];
        } else {
            $body = [
                'messaging_product' => 'whatsapp',
                'to'                => $number,
                'type'              => 'text',
                'text'              => ['body' => $message],
            ];
        }

        $payload = json_encode($body, JSON_UNESCAPED_UNICODE);

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
        ]);

        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 200 && $status < 300) {
            return true;
        }

        Logger::error('WhatsApp Cloud API failed', ['status' => $status, 'resp' => $response], 'whatsapp');
        return false;
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
     * Best-effort country hint ('NP' or null) from a booking record. A
     * customer travelling on a Nepali citizenship card is treated as Nepal so
     * a bare 10-digit contact number resolves to +977, not +91. Everyone else
     * falls through to the configured default.
     */
    private static function countryHint(array $booking): ?string
    {
        $idType = strtolower((string) ($booking['id_type'] ?? ''));
        if ($idType !== '' && str_contains($idType, 'nepal')) {
            return 'NP';
        }
        return null;
    }


    /* =================================================================
     *  Templated notifications for booking lifecycle events
     * ================================================================= */

    public static function bookingConfirmed(array $booking): void
    {
        $company = Settings::getString('company_name', APP_NAME);
        $pnr     = (string) $booking['pnr'];

        $subject = 'Your ticket is confirmed · ' . $pnr;
        $html    = self::wrapEmail(
            'Booking confirmed 🎉',
            '<p>Namaste,</p>'
            . '<p>Your booking <strong>' . e($pnr) . '</strong> with ' . e($company) . ' is confirmed.</p>'
            . '<p>Total paid: <strong>' . e(inr((float) $booking['total_amount'])) . '</strong></p>'
            . '<p>Download your ticket any time from '
            . '<a href="' . e(appUrl('track-ticket.php?pnr=' . urlencode($pnr))) . '">this link</a>.</p>'
            . '<p>Have a safe journey.<br>' . e($company) . '</p>'
        );

        if (!empty($booking['contact_email'])) {
            self::email((string) $booking['contact_email'], $subject, $html);
        }

        // WhatsApp: automatic when a driver is configured, otherwise a
        // click-to-chat link is logged so staff can send it in one tap.
        // When whatsapp_send_pdf is on, the ticket PDF itself is attached
        // (the provider fetches it from the public download URL).
        if (Settings::getBool('whatsapp_notify_customer', true) && !empty($booking['contact_phone'])) {
            $ticketUrl = appUrl('download-ticket.php?pnr=' . urlencode($pnr));
            $text = "🚌 " . $company . "\n"
                  . "Your booking " . $pnr . " is CONFIRMED ✅\n"
                  . "Amount: " . inr((float) $booking['total_amount']) . "\n"
                  . "Download your ticket: " . $ticketUrl . "\n"
                  . "Have a safe journey.";
            $mediaUrl = Settings::getBool('whatsapp_send_pdf', true) ? $ticketUrl : null;
            self::whatsapp((string) $booking['contact_phone'], $text, $mediaUrl, self::countryHint($booking));
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
            . e(Settings::getString('company_phone', '')) . '.</p>'
            . '<p>' . e($company) . '</p>'
        );

        if (!empty($booking['contact_email'])) {
            self::email((string) $booking['contact_email'], 'Action needed · ' . $pnr, $html);
        }

        if (Settings::getBool('whatsapp_notify_customer', true) && !empty($booking['contact_phone'])) {
            $text = "🚌 " . $company . "\n"
                  . "Booking " . $pnr . ": payment could not be verified.\n"
                  . "Reason: " . $reason . "\n"
                  . "Please re-submit your proof or call " . Settings::getString('company_phone', '') . ".";
            self::whatsapp((string) $booking['contact_phone'], $text, null, self::countryHint($booking));
        }
    }

    /**
     * A new booking has just been placed — alert admin (WhatsApp + email
     * if configured). Falls back to a click-to-chat link stored in the
     * app log so a staff member can send it in one tap.
     */
    public static function bookingReceived(array $booking): void
    {
        $company    = Settings::getString('company_name', APP_NAME);
        $adminPhone = Settings::getString('admin_whatsapp', Settings::getString('company_phone', ''));
        $adminEmail = Settings::getString('admin_email', Settings::getString('company_email', ''));
        $pnr        = (string) $booking['pnr'];

        $lines = [
            "🎫 New booking " . $pnr,
            "Amount: " . inr((float) $booking['total_amount']),
            "Phone: " . ($booking['contact_phone'] ?? '—'),
            "Method: " . strtoupper((string) ($booking['payment_method'] ?? 'upi')),
            "Verify: " . appUrl('admin/payments.php?pnr=' . urlencode($pnr)),
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
        $adminPhone = Settings::getString('admin_whatsapp', Settings::getString('company_phone', ''));
        $pnr        = (string) $booking['pnr'];

        if (Settings::getBool('whatsapp_notify_customer', true) && !empty($booking['contact_phone'])) {
            $text = "🚌 " . $company . "\n"
                  . "Booking " . $pnr . " is CANCELLED.\n"
                  . "Refund: " . inr((float) ($refund['amount'] ?? 0)) . " (" . (int) ($refund['percent'] ?? 0) . "%)\n"
                  . ($refund['reason'] ?? '');
            self::whatsapp((string) $booking['contact_phone'], $text, null, self::countryHint($booking));
        }

        if (Settings::getBool('whatsapp_notify_admin', true) && $adminPhone !== '') {
            $text = "🚫 Booking " . $pnr . " cancelled by customer.\n"
                  . "Refund: " . inr((float) ($refund['amount'] ?? 0)) . " (" . (int) ($refund['percent'] ?? 0) . "%)\n"
                  . "Phone: " . ($booking['contact_phone'] ?? '—');
            self::whatsapp($adminPhone, $text);
        }
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
