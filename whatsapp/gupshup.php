<?php
/**
 * =====================================================================
 *  whatsapp/gupshup.php — Gupshup Enterprise WhatsApp API send functions.
 *
 *  One HTTP path for every WhatsApp message this app pushes through
 *  Gupshup — the parallel of whatsapp/api.php for the Meta Cloud API
 *  driver. When Settings::whatsapp_driver = 'gupshup' the Notify layer
 *  picks up these helpers and Meta is not touched, so a bad Gupshup
 *  config never spills into the Meta path (which is kept live as the
 *  rollback driver until Gupshup passes production testing).
 *
 *  Contract for every public function:
 *    - never throws;
 *    - returns ['success' => bool, 'message_id' => string, 'error' => string]
 *      (+ 'http' and 'code' for diagnostics);
 *    - retries ONCE on a network failure or a Gupshup 5xx;
 *    - writes one line per attempt to whatsapp/logs/YYYY-MM-DD.log.
 *
 *  A 2xx from Gupshup means ACCEPTED, not delivered. Delivery / read /
 *  failed arrives later on whatsapp/gupshup-webhook.php, which updates
 *  message_logs by the message_id returned here (same role Meta's wamid
 *  plays for the Cloud API driver, and the Twilio SID plays for Twilio).
 *
 *  Credentials resolve env -> settings, exactly like whatsapp/config.php:
 *    env var                 settings key
 *    GUPSHUP_API_KEY         gupshup_api_key
 *    GUPSHUP_APP_NAME        gupshup_app_name     (aka "app name" in the console)
 *    GUPSHUP_SOURCE_NUMBER   gupshup_source       (E.164 digits, no '+')
 *    GUPSHUP_WEBHOOK_SECRET  gupshup_webhook_secret  (used by the webhook)
 *    GUPSHUP_API_BASE        gupshup_api_base     (default https://api.gupshup.io)
 *
 *  Loaded only from inside the app; a direct browser hit answers 404.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

// Reuse the phone cleaner and log helper from the Meta module — one
// definition, so a fix to India / Nepal normalisation reaches every driver.
require_once __DIR__ . '/api.php';

if (!function_exists('gsConfigValue')) {
    function gsConfigValue(string $env, string $settingKey, string $default = ''): string
    {
        $v = getenv($env);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        if (class_exists('Settings')) {
            $s = trim(Settings::getString($settingKey, ''));
            if ($s !== '') {
                return $s;
            }
        }
        return $default;
    }
}

defined('GUPSHUP_API_KEY')         || define('GUPSHUP_API_KEY',         gsConfigValue('GUPSHUP_API_KEY', 'gupshup_api_key'));
defined('GUPSHUP_APP_NAME')        || define('GUPSHUP_APP_NAME',        gsConfigValue('GUPSHUP_APP_NAME', 'gupshup_app_name'));
defined('GUPSHUP_SOURCE_NUMBER')   || define('GUPSHUP_SOURCE_NUMBER',   preg_replace('/\D/', '', gsConfigValue('GUPSHUP_SOURCE_NUMBER', 'gupshup_source')) ?? '');
defined('GUPSHUP_WEBHOOK_SECRET')  || define('GUPSHUP_WEBHOOK_SECRET',  gsConfigValue('GUPSHUP_WEBHOOK_SECRET', 'gupshup_webhook_secret'));
defined('GUPSHUP_API_BASE')        || define('GUPSHUP_API_BASE',        rtrim(gsConfigValue('GUPSHUP_API_BASE', 'gupshup_api_base', 'https://api.gupshup.io'), '/'));

if (!function_exists('gupshupPost')) {

    /**
     * POST an x-www-form-urlencoded body to a Gupshup endpoint. Retries
     * once on a network failure (cURL error / no HTTP status) or a
     * Gupshup 5xx; a 4xx is Gupshup's final answer and is returned as-is.
     *
     * @param array<string,string> $form
     * @return array{success: bool, message_id: string, error: string, http: int, code: int}
     */
    function gupshupPost(string $path, array $form, string $type, string $toForLog, ?string $apiKey = null): array
    {
        $apiKey = $apiKey ?? GUPSHUP_API_KEY;
        $result = ['success' => false, 'message_id' => '', 'error' => '', 'http' => 0, 'code' => 0];

        if ($apiKey === '') {
            $result['error'] = 'Gupshup not configured (GUPSHUP_API_KEY missing)';
            logWhatsAppEvent('gs:' . $type, $toForLog, [], $result);
            return $result;
        }
        if (GUPSHUP_SOURCE_NUMBER === '') {
            $result['error'] = 'Gupshup source number missing (GUPSHUP_SOURCE_NUMBER)';
            logWhatsAppEvent('gs:' . $type, $toForLog, [], $result);
            return $result;
        }

        $endpoint = GUPSHUP_API_BASE . '/' . ltrim($path, '/');
        $body     = http_build_query($form);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = false;
            $status   = 0;
            $curlErr  = '';
            try {
                $ch = curl_init($endpoint);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_HTTPHEADER     => [
                        'apikey: ' . $apiKey,
                        'Content-Type: application/x-www-form-urlencoded',
                        'Accept: application/json',
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

            // Gupshup returns { "status": "submitted", "messageId": "..." } on
            // accept. Anything else — including a "status":"error" body with a
            // 2xx code — is a failure.
            $accepted = $status >= 200 && $status < 300
                && is_array($data)
                && strtolower((string) ($data['status'] ?? '')) === 'submitted';

            if ($accepted) {
                $result['success']    = true;
                $result['message_id'] = (string) ($data['messageId'] ?? '');
                $result['error']      = '';
                $result['code']       = 0;
                logWhatsAppEvent('gs:' . $type, $toForLog, ['attempt' => $attempt], $result);
                return $result;
            }

            $gsMsg  = is_array($data) ? trim((string) ($data['message'] ?? '')) : '';
            $gsCode = is_array($data) ? (int) ($data['code'] ?? 0) : 0;
            $result['code']  = $gsCode;
            $result['error'] = $status === 0
                ? 'network error: ' . ($curlErr !== '' ? $curlErr : 'no response')
                : trim('HTTP ' . $status
                    . ($gsCode ? ' (code ' . $gsCode . ')' : '')
                    . ' ' . ($gsMsg !== '' ? $gsMsg : mb_substr((string) $response, 0, 200)));
            logWhatsAppEvent('gs:' . $type, $toForLog, ['attempt' => $attempt], $result);

            $retryable = $status === 0 || $status >= 500;
            if (!$retryable || $attempt === 2) {
                break;
            }
            usleep(700000);   // brief back-off before the single retry
        }

        if (class_exists('Logger')) {
            Logger::error('Gupshup WhatsApp send failed', [
                'to' => $toForLog, 'type' => $type, 'http' => $result['http'], 'code' => $result['code'], 'error' => $result['error'],
            ], 'whatsapp');
        }
        return $result;
    }

    /**
     * Send a template message. Gupshup accepts the same list of body params
     * as Meta (positional {{1}}..{{n}}) and a separate "message" object for
     * the IMAGE / DOCUMENT / VIDEO header. Template lookup is by NAME by
     * default (Gupshup resolves the id against the app); pass a raw uuid in
     * $templateName to bypass that.
     *
     * @param list<string>|array<string,string> $bodyVars
     */
    function sendGupshupTemplate(string $phone, string $templateName, array $bodyVars = [], ?string $headerImageUrl = null, ?string $headerDocUrl = null, ?string $docFilename = null, ?string $countryHint = null): array
    {
        $to = waCleanPhone($phone, $countryHint);
        if ($to === '') {
            $r = ['success' => false, 'message_id' => '', 'error' => 'invalid phone number', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('gs:template', $to, [], $r);
            return $r;
        }
        if (trim($templateName) === '') {
            $r = ['success' => false, 'message_id' => '', 'error' => 'template name is empty', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('gs:template', $to, [], $r);
            return $r;
        }

        $params = [];
        if (!array_is_list($bodyVars)) {
            ksort($bodyVars, SORT_NATURAL);
        }
        foreach ($bodyVars as $v) {
            $s = trim(preg_replace('/[\r\n\t]+/', ' ', (string) $v) ?? '');
            $params[] = $s !== '' ? $s : '-';
        }

        // Gupshup's v1 template endpoint expects two JSON blobs on the form:
        //   template = { "id": "<name-or-uuid>", "params": [...] }
        //   message  = { "type": "image|document|text", ... }  (header media)
        $templateJson = json_encode(['id' => $templateName, 'params' => $params], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $form = [
            'source'      => GUPSHUP_SOURCE_NUMBER,
            'destination' => $to,
            'src.name'    => GUPSHUP_APP_NAME,
            'template'    => (string) $templateJson,
        ];

        if ($headerImageUrl !== null && $headerImageUrl !== '') {
            $form['message'] = (string) json_encode([
                'type'  => 'image',
                'image' => ['link' => $headerImageUrl],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($headerDocUrl !== null && $headerDocUrl !== '') {
            $form['message'] = (string) json_encode([
                'type'     => 'document',
                'document' => ['link' => $headerDocUrl, 'filename' => $docFilename ?: 'ticket.pdf'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return gupshupPost('/wa/api/v1/template/msg', $form, 'template:' . $templateName, $to);
    }

    /** Free-form text — only delivered inside the 24 h customer-service window. */
    function sendGupshupText(string $phone, string $message, ?string $countryHint = null): array
    {
        $to = waCleanPhone($phone, $countryHint);
        $message = mb_substr(trim($message), 0, 4096);
        if ($to === '') {
            $r = ['success' => false, 'message_id' => '', 'error' => 'invalid phone number', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('gs:text', $to, [], $r);
            return $r;
        }
        if ($message === '') {
            $r = ['success' => false, 'message_id' => '', 'error' => 'message is empty', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('gs:text', $to, [], $r);
            return $r;
        }
        $form = [
            'channel'     => 'whatsapp',
            'source'      => GUPSHUP_SOURCE_NUMBER,
            'destination' => $to,
            'src.name'    => GUPSHUP_APP_NAME,
            'message'     => (string) json_encode(['type' => 'text', 'text' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        return gupshupPost('/wa/api/v1/msg', $form, 'text', $to);
    }

    /** Image by public https URL, optional caption (max 1024 chars). */
    function sendGupshupImage(string $phone, string $imageUrl, string $caption = '', ?string $countryHint = null): array
    {
        $to = waCleanPhone($phone, $countryHint);
        if ($to === '') {
            $r = ['success' => false, 'message_id' => '', 'error' => 'invalid phone number', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('gs:image', $to, [], $r);
            return $r;
        }
        if (!preg_match('~^https://~i', $imageUrl)) {
            $r = ['success' => false, 'message_id' => '', 'error' => 'image URL must be public https', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('gs:image', $to, [], $r);
            return $r;
        }
        $msg = [
            'type'         => 'image',
            'originalUrl'  => $imageUrl,
            'previewUrl'   => $imageUrl,
        ];
        if (trim($caption) !== '') {
            $msg['caption'] = mb_substr($caption, 0, 1024);
        }
        $form = [
            'channel'     => 'whatsapp',
            'source'      => GUPSHUP_SOURCE_NUMBER,
            'destination' => $to,
            'src.name'    => GUPSHUP_APP_NAME,
            'message'     => (string) json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        return gupshupPost('/wa/api/v1/msg', $form, 'image', $to);
    }

    /** Document (PDF, statement, etc.) by public https URL. */
    function sendGupshupDocument(string $phone, string $docUrl, string $filename = '', string $caption = '', ?string $countryHint = null): array
    {
        $to = waCleanPhone($phone, $countryHint);
        if ($to === '') {
            $r = ['success' => false, 'message_id' => '', 'error' => 'invalid phone number', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('gs:document', $to, [], $r);
            return $r;
        }
        if (!preg_match('~^https://~i', $docUrl)) {
            $r = ['success' => false, 'message_id' => '', 'error' => 'document URL must be public https', 'http' => 0, 'code' => 0];
            logWhatsAppEvent('gs:document', $to, [], $r);
            return $r;
        }
        $fn = $filename !== '' ? $filename : basename((string) parse_url($docUrl, PHP_URL_PATH));
        if ($fn === '' || $fn === '.') { $fn = 'document.pdf'; }
        $msg = [
            'type'     => 'file',
            'url'      => $docUrl,
            'filename' => $fn,
        ];
        if (trim($caption) !== '') {
            $msg['caption'] = mb_substr($caption, 0, 1024);
        }
        $form = [
            'channel'     => 'whatsapp',
            'source'      => GUPSHUP_SOURCE_NUMBER,
            'destination' => $to,
            'src.name'    => GUPSHUP_APP_NAME,
            'message'     => (string) json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        return gupshupPost('/wa/api/v1/msg', $form, 'document', $to);
    }
}
