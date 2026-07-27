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
     * Deliver a WhatsApp message.
     *
     * When the Cloud API is configured it is used and true/false is
     * returned. Otherwise a wa.me click-to-chat URL is returned as a
     * string so the caller (admin panel) can open it in one tap.
     *
     * @return bool|string true/false for API delivery, or a wa.me URL
     */
    public static function whatsapp(string $phone, string $message): bool|string
    {
        $driver = Settings::getString('whatsapp_driver', 'click_to_chat');
        $number = preg_replace('/\D/', '', $phone) ?? '';

        if ($number === '') {
            return false;
        }

        if ($driver === 'cloud_api') {
            $token   = Settings::getString('whatsapp_api_token', '');
            $phoneId = Settings::getString('whatsapp_phone_id', '');

            if ($token !== '' && $phoneId !== '') {
                return self::whatsappCloudApi($number, $message, $token, $phoneId);
            }
        }

        // Default: a click-to-chat link.
        return whatsappLink($number, $message);
    }

    private static function whatsappCloudApi(string $number, string $message, string $token, string $phoneId): bool
    {
        $endpoint = 'https://graph.facebook.com/v18.0/' . $phoneId . '/messages';

        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to'                => $number,
            'type'              => 'text',
            'text'              => ['body' => $message],
        ], JSON_UNESCAPED_UNICODE);

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
