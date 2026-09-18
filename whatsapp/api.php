<?php
/**
 * =====================================================================
 *  whatsapp/api.php — Meta WhatsApp Cloud API send functions.
 *
 *  One HTTP path for every WhatsApp message this app sends through Meta:
 *  Notify::whatsapp() (tickets, alerts, OTPs, staff tools) builds its
 *  payload and hands it to waGraphPost(); the helpers below are the same
 *  call for scripts that want a one-liner.
 *
 *  Contract for every public function:
 *    - never throws;
 *    - returns ['success' => bool, 'message_id' => string, 'error' => string]
 *      (+ 'http' and 'code' for diagnostics);
 *    - retries ONCE on a network failure or a Meta 5xx;
 *    - writes one line per attempt to whatsapp/logs/YYYY-MM-DD.log.
 *
 *  A 200 from Meta means ACCEPTED, not delivered. Delivery / read / failed
 *  arrives later on whatsapp/webhook.php, which updates message_logs by the
 *  message_id returned here.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';

if (!function_exists('waGraphPost')) {

    /**
     * Clean any India / Nepal number to international digits without "+":
     * 91XXXXXXXXXX or 977XXXXXXXXXX. A bare 10-digit number takes the
     * country hint ('IN' / 'NP'), else the configured default (91).
     * Returns '' when the result cannot be a real mobile number.
     */
    function waCleanPhone(string $phone, ?string $countryHint = null): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);                 // 0 98xxxxxxxx trunk prefix
        }
        if (strlen($d) === 10) {
            $cc = match ($countryHint) {
                'NP'    => '977',
                'IN'    => '91',
                default => class_exists('Settings')
                    ? ((preg_replace('/\D/', '', Settings::getString('whatsapp_default_country', '91')) ?? '') ?: '91')
                    : '91',
            };
            $d = $cc . $d;
        }
        // E.164 allows 8..15 digits; India = 12, Nepal = 13.
        if (strlen($d) < 8 || strlen($d) > 15) {
            return '';
        }
        return $d;
    }

    /**
     * Append one line to whatsapp/logs/YYYY-MM-DD.log:
     *   [timestamp] TYPE | phone | status | message_id | detail
     * The directory is denied by nginx (*.log), and one-time codes are
     * masked before anything is written.
     */
    function logWhatsAppEvent(string $type, string $phone, mixed $data, mixed $response): void
    {
        try {
            $dir = defined('WHATSAPP_LOG_DIR') ? WHATSAPP_LOG_DIR : __DIR__ . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $r      = is_array($response) ? $response : [];
            $status = array_key_exists('success', $r) ? ($r['success'] ? 'OK' : 'FAIL') : (string) ($r['status'] ?? '-');
            $msgId  = (string) ($r['message_id'] ?? '');
            $detail = (string) ($r['error'] ?? '');
            if ($detail === '' && !is_array($response) && $response !== null) {
                $detail = (string) $response;
            }
            if (is_array($data) && isset($data['attempt'])) {
                $detail = 'attempt ' . (int) $data['attempt'] . ($detail !== '' ? ' · ' . $detail : '');
            }
            $detail = preg_replace('/(code\D{0,12}?)(\d{4,8})/iu', '$1••••', $detail) ?? $detail;
            $detail = str_replace(["\r", "\n"], ' ', mb_substr($detail, 0, 500));

            $line = sprintf(
                "[%s] %s | %s | %s | %s%s\n",
                date('Y-m-d H:i:s'),
                strtoupper($type),
                $phone !== '' ? $phone : '-',
                $status,
                $msgId !== '' ? $msgId : '-',
                $detail !== '' ? ' | ' . $detail : ''
            );
            @file_put_contents($dir . '/' . date('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $ignored) {
            // Logging must never break a send.
        }
    }

    /**
     * POST a ready /messages payload to the Graph API. Retries once on a
     * network failure (cURL error / no HTTP status) or a Meta 5xx; a 4xx is
     * Meta's final answer and is returned as-is.
     *
     * @param array<string, mixed> $payload  body WITHOUT messaging_product/to (added here)
     * @return array{success: bool, message_id: string, error: string, http: int, code: int}
     */
    function waGraphPost(string $to, array $payload, string $type = 'text', ?string $token = null, ?string $phoneId = null): array
    {
        $token   = $token   ?? META_ACCESS_TOKEN;
        $phoneId = $phoneId ?? META_PHONE_NUMBER_ID;
        $result  = ['success' => false, 'message_id' => '', 'error' => '', 'http' => 0, 'code' => 0];

        if ($token === '' || $phoneId === '') {
            $result['error'] = 'Meta Cloud API not configured (access token / phone number ID missing)';
            logWhatsAppEvent($type, $to, [], $result);
            return $result;
        }
        if ($to === '') {
            $result['error'] = 'invalid phone number';
            logWhatsAppEvent($type, $to, [], $result);
            return $result;
        }

        $body = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to] + $payload;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $result['error'] = 'payload could not be JSON-encoded';
            logWhatsAppEvent($type, $to, [], $result);
            return $result;
        }

        $endpoint = META_API_BASE . '/' . rawurlencode($phoneId) . '/messages';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = false;
            $status   = 0;
            $curlErr  = '';
            try {
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $json,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $token,
                        'Content-Type: application/json',
                    ],
                    CURLOPT_CONNECTTIMEOUT => 8,
                    CURLOPT_TIMEOUT        => 15,
                ]);
                $response = curl_exec($ch);
                $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr  = curl_error($ch);
                curl_close($ch);
            } catch (Throwable $e) {
                $curlErr = $e->getMessage();
            }

            $data = json_decode((string) $response, true);
            $result['http'] = $status;

            if ($status >= 200 && $status < 300 && is_array($data)) {
                $result['success']    = true;
                $result['message_id'] = (string) ($data['messages'][0]['id'] ?? '');
                $result['error']      = '';
                $result['code']       = 0;
                logWhatsAppEvent($type, $to, ['attempt' => $attempt], $result);
                return $result;
            }

            $err = is_array($data) ? ($data['error'] ?? []) : [];
            $result['code']  = (int) ($err['code'] ?? 0);
            $result['error'] = $status === 0
                ? 'network error: ' . ($curlErr !== '' ? $curlErr : 'no response')
                : trim('HTTP ' . $status
                    . ($result['code'] ? ' (code ' . $result['code'] . ')' : '')
                    . ' ' . (string) ($err['error_data']['details'] ?? ($err['message'] ?? mb_substr((string) $response, 0, 200))));
            logWhatsAppEvent($type, $to, ['attempt' => $attempt], $result);

            $retryable = $status === 0 || $status >= 500;
            if (!$retryable || $attempt === 2) {
                break;
            }
            usleep(700000);   // brief back-off before the single retry
        }

        if (class_exists('Logger')) {
            Logger::error('WhatsApp Cloud API failed', [
                'to' => $to, 'type' => $type, 'http' => $result['http'], 'code' => $result['code'], 'error' => $result['error'],
            ], 'whatsapp');
        }
        return $result;
    }

    /**
     * Send an approved template.
     *
     * @param list<string>|array<string,string> $bodyVars   {{1}}, {{2}} … in order
     * @param array<int|string, string>         $headerVars ['image' => url] | ['document' => url] | ['video' => url] | list of header text vars
     */
    function sendWhatsAppTemplate(string $phone, string $templateName, string $langCode = 'en', array $bodyVars = [], array $headerVars = [], ?string $countryHint = null): array
    {
        $to = waCleanPhone($phone, $countryHint);
        if (trim($templateName) === '') {
            $r = ['success' => false, 'message_id' => '', 'error' => 'template name is empty', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('template', $to, [], $r);
            return $r;
        }

        $components = [];
        if ($headerVars !== []) {
            $params = [];
            foreach (['image', 'document', 'video'] as $mediaKind) {
                if (isset($headerVars[$mediaKind]) && (string) $headerVars[$mediaKind] !== '') {
                    $params[] = ['type' => $mediaKind, $mediaKind => ['link' => (string) $headerVars[$mediaKind]]];
                }
            }
            if ($params === []) {
                foreach ($headerVars as $v) {
                    $params[] = ['type' => 'text', 'text' => (string) $v];
                }
            }
            $components[] = ['type' => 'header', 'parameters' => $params];
        }
        if ($bodyVars !== []) {
            if (!array_is_list($bodyVars)) {
                ksort($bodyVars, SORT_NATURAL);
            }
            $params = [];
            foreach ($bodyVars as $v) {
                // WhatsApp rejects an empty variable, and newlines/tabs in one.
                $s = trim(preg_replace('/[\r\n\t]+/', ' ', (string) $v) ?? '');
                $params[] = ['type' => 'text', 'text' => $s !== '' ? $s : '-'];
            }
            $components[] = ['type' => 'body', 'parameters' => $params];
        }

        $template = ['name' => $templateName, 'language' => ['code' => $langCode !== '' ? $langCode : 'en']];
        if ($components !== []) {
            $template['components'] = $components;
        }
        return waGraphPost($to, ['type' => 'template', 'template' => $template], 'template:' . $templateName);
    }

    /** Free-form text — only delivered inside the 24 h customer-service window. */
    function sendWhatsAppText(string $phone, string $message, ?string $countryHint = null): array
    {
        $to = waCleanPhone($phone, $countryHint);
        $message = mb_substr(trim($message), 0, 4096);
        if ($message === '') {
            $r = ['success' => false, 'message_id' => '', 'error' => 'message is empty', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('text', $to, [], $r);
            return $r;
        }
        return waGraphPost($to, ['type' => 'text', 'text' => ['preview_url' => true, 'body' => $message]], 'text');
    }

    /** Image by public https URL, optional caption (max 1024 chars). */
    function sendWhatsAppImage(string $phone, string $imageUrl, string $caption = '', ?string $countryHint = null): array
    {
        $to = waCleanPhone($phone, $countryHint);
        if (!preg_match('~^https://~i', $imageUrl)) {
            $r = ['success' => false, 'message_id' => '', 'error' => 'image URL must be public https', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('image', $to, [], $r);
            return $r;
        }
        $image = ['link' => $imageUrl];
        if (trim($caption) !== '') {
            $image['caption'] = mb_substr($caption, 0, 1024);
        }
        return waGraphPost($to, ['type' => 'image', 'image' => $image], 'image');
    }
}
