<?php
/**
 * =====================================================================
 *  includes/waauthotp.php — WhatsApp login codes on our OWN Meta
 *  AUTHENTICATION template (15 Nov 2026).
 *
 *  Owner: "WhatsApp OTP strong — API le automatic message garne." A code
 *  sent first by us only lands inside the person's 24 h window; outside it
 *  Meta needs an approved template. AiSensy's plan expired (14 Nov), so the
 *  owner's own WABA carries one AUTHENTICATION template:
 *
 *    shg_login_code (en)  body  "<code> is your verification code."
 *                               + "For your security, do not share this code."
 *                         footer "This code expires in N minutes."
 *                         button  Copy code
 *
 *  Meta fixes that wording (authentication templates take no free text);
 *  billed as "authentication" (~₹0.13 India / ~₹1 Nepal a message).
 *
 *    create()   asks Meta for the template (once; Meta reviews, usually minutes)
 *    refresh()  reads its status back into settings (wa_auth_tpl_status)
 *    send()     one code to one number — never queued, never stored
 *    sendFromText()  Notify / AiSensy hand a code message here when no free
 *                    window carried it
 *
 *  Switch: wa_auth_otp_on (default on) — off = codes go as before.
 *  Nothing here logs a code; numbers are masked.
 * =====================================================================
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaAuthOtp
{
    public const NAME = 'shg_login_code';
    public const LANG = 'en';

    /** Tests replace the Graph call: fn(string $method, string $path, ?array $body): array{http:int, json:array} */
    public static $graphHook = null;

    private function __construct() {}

    private static function creds(): array
    {
        if (!defined('META_ACCESS_TOKEN') && defined('ROOT_PATH') && is_file(ROOT_PATH . '/whatsapp/config.php')) {
            try {
                require_once ROOT_PATH . '/whatsapp/config.php';
            } catch (Throwable $e) {
            }
        }
        $ver = defined('META_API_VERSION') && preg_match('/^v\d+\.\d+$/', (string) META_API_VERSION) ? (string) META_API_VERSION : 'v25.0';
        return [
            'token' => defined('META_ACCESS_TOKEN') ? (string) META_ACCESS_TOKEN : '',
            'waba'  => defined('META_WABA_ID') ? (string) META_WABA_ID : '',
            'phone' => defined('META_PHONE_NUMBER_ID') ? (string) META_PHONE_NUMBER_ID : '',
            'base'  => 'https://graph.facebook.com/' . $ver,
        ];
    }

    public static function configured(): bool
    {
        if (self::$graphHook !== null) {
            return true;
        }
        $c = self::creds();
        return $c['token'] !== '' && $c['waba'] !== '' && $c['phone'] !== '' && function_exists('curl_init');
    }

    /** @return array{http: int, json: array<string, mixed>} */
    private static function graph(string $method, string $path, ?array $body = null): array
    {
        if (self::$graphHook !== null) {
            return (self::$graphHook)($method, $path, $body);
        }
        $c = self::creds();
        $ch = curl_init($c['base'] . $path);
        try {
            $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['token'], 'Content-Type: application/json']];
            if ($method === 'POST') {
                $opt[CURLOPT_POST] = true;
                $opt[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            curl_setopt_array($ch, $opt);
            $raw  = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $json = is_string($raw) ? json_decode($raw, true) : null;
            return ['http' => $http, 'json' => is_array($json) ? $json : []];
        } catch (Throwable $e) {
            return ['http' => 0, 'json' => []];
        } finally {
            curl_close($ch);
        }
    }

    private static function err(array $r): string
    {
        $e = (array) ($r['json']['error'] ?? []);
        return mb_substr((string) ($e['error_user_msg'] ?? ($e['message'] ?? ('HTTP ' . $r['http']))), 0, 240);
    }

    public static function status(): string
    {
        return strtoupper(Settings::getString('wa_auth_tpl_status', ''));
    }

    /** Approved, switched on and configured: codes may go on the template. Re-reads a PENDING status at most every 10 minutes. */
    public static function ready(): bool
    {
        if (!Settings::getBool('wa_auth_otp_on', true) || !self::configured()) {
            return false;
        }
        $st = self::status();
        if (!self::manual() && in_array($st, ['PENDING', 'IN_APPEAL', ''], true) && Settings::getString('wa_auth_tpl_id', '') !== ''
            && time() - Settings::getInt('wa_auth_tpl_checked', 0) > 600) {
            self::refresh();
            $st = self::status();
        }
        return $st === 'APPROVED';
    }

    /**
     * Ask Meta for the template. A template of that name already there (Meta code 2388023 /
     * "already exists") is read back instead.
     *
     * @return array{ok: bool, status: string, error: string}
     */
    public static function create(): array
    {
        if (!self::configured()) {
            return ['ok' => false, 'status' => '', 'error' => 'Meta token / WABA id / phone id is missing (Settings → WhatsApp).'];
        }
        $min = max(1, min(90, defined('OTP_EXPIRY_MINUTES') ? (int) OTP_EXPIRY_MINUTES : 10));
        $c = self::creds();
        $r = self::graph('POST', '/' . rawurlencode($c['waba']) . '/message_templates', [
            'name' => self::NAME, 'language' => self::LANG, 'category' => 'AUTHENTICATION',
            'components' => [
                ['type' => 'BODY', 'add_security_recommendation' => true],
                ['type' => 'FOOTER', 'code_expiration_minutes' => $min],
                ['type' => 'BUTTONS', 'buttons' => [['type' => 'OTP', 'otp_type' => 'COPY_CODE', 'text' => 'Copy code']]],
            ],
        ]);
        if ($r['http'] >= 200 && $r['http'] < 300 && isset($r['json']['id'])) {
            Settings::set('wa_auth_tpl_id', (string) $r['json']['id'], 'string', 'whatsapp', false);
            Settings::set('wa_auth_tpl_status', strtoupper((string) ($r['json']['status'] ?? 'PENDING')), 'string', 'whatsapp', false);
            Settings::set('wa_auth_tpl_checked', time(), 'int', 'whatsapp', false);
            Logger::audit('whatsapp.auth_template_created', 'setting', self::NAME, null, ['status' => self::status()], 'Meta AUTHENTICATION template asked for (login codes)');
            return ['ok' => true, 'status' => self::status(), 'error' => ''];
        }
        $why = self::err($r);
        if (stripos($why, 'already') !== false || (int) ($r['json']['error']['error_subcode'] ?? 0) === 2388023) {
            $s = self::refresh();
            return ['ok' => $s['status'] !== '', 'status' => $s['status'], 'error' => $s['status'] !== '' ? '' : $why];
        }
        Logger::warning('WhatsApp auth template: create refused: ' . $why, [], 'whatsapp');
        return ['ok' => false, 'status' => self::status(), 'error' => $why];
    }

    /**
     * Some WABAs refuse the template API (Meta 2388339 "This API is not supported for this
     * WhatsApp Business Account") while sending still works. Then the owner creates the same
     * template in WhatsApp Manager by hand and marks it approved here (audited); a test code to
     * their own number proves it.
     */
    public static function manual(): bool
    {
        return Settings::getBool('wa_auth_tpl_manual', false);
    }

    public static function markManual(bool $on, int $by): void
    {
        Settings::set('wa_auth_tpl_manual', $on, 'bool', 'whatsapp', false);
        Settings::set('wa_auth_tpl_status', $on ? 'APPROVED' : '', 'string', 'whatsapp', false);
        Logger::audit('whatsapp.auth_template_manual', 'setting', self::NAME, null, ['on' => $on],
            ($on ? 'Marked approved in WhatsApp Manager' : 'Manual approval removed') . ' by admin #' . $by);
    }

    /** @return array{status: string, reason: string, error: string} */
    public static function refresh(): array
    {
        if (!self::configured()) {
            return ['status' => self::status(), 'reason' => '', 'error' => 'not configured'];
        }
        $c = self::creds();
        $r = self::graph('GET', '/' . rawurlencode($c['waba']) . '/message_templates?' . http_build_query([
            'name' => self::NAME, 'fields' => 'id,name,status,language,category,rejected_reason', 'limit' => 20]));
        Settings::set('wa_auth_tpl_checked', time(), 'int', 'whatsapp', false);
        if ($r['http'] !== 200) {
            return ['status' => self::status(), 'reason' => '', 'error' => self::err($r)];
        }
        foreach ((array) ($r['json']['data'] ?? []) as $t) {
            if ((string) ($t['name'] ?? '') === self::NAME && (string) ($t['language'] ?? '') === self::LANG) {
                $st = strtoupper((string) ($t['status'] ?? ''));
                if ($st !== self::status()) {
                    Logger::info('WhatsApp auth template status: ' . $st, ['reason' => (string) ($t['rejected_reason'] ?? '')], 'whatsapp');
                }
                Settings::set('wa_auth_tpl_status', $st, 'string', 'whatsapp', false);
                Settings::set('wa_auth_tpl_id', (string) ($t['id'] ?? ''), 'string', 'whatsapp', false);
                return ['status' => $st, 'reason' => (string) ($t['rejected_reason'] ?? ''), 'error' => ''];
            }
        }
        return ['status' => '', 'reason' => '', 'error' => 'not on Meta yet'];
    }

    /**
     * One code to one number on the template. Nothing is queued or stored.
     *
     * @return array{success: bool, message_id: string, error: string}
     */
    public static function send(string $to, string $code): array
    {
        $to   = preg_replace('/\D/', '', $to) ?? '';
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($to) < 11 || $code === '' || strlen($code) > 15) {
            return ['success' => false, 'message_id' => '', 'error' => 'bad number or code'];
        }
        if (!self::ready()) {
            return ['success' => false, 'message_id' => '', 'error' => 'template not approved'];
        }
        $c = self::creds();
        $r = self::graph('POST', '/' . rawurlencode($c['phone']) . '/messages', [
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'template',
            'template' => ['name' => self::NAME, 'language' => ['code' => self::LANG], 'components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
            ]],
        ]);
        $id = (string) ($r['json']['messages'][0]['id'] ?? '');
        $ok = $r['http'] >= 200 && $r['http'] < 300 && $id !== '';
        Logger::info('WhatsApp login code on the auth template: ' . ($ok ? 'accepted' : 'refused ' . self::err($r)),
            ['to' => function_exists('maskPhone') ? maskPhone($to) : substr($to, -4)], 'otp');
        return ['success' => $ok, 'message_id' => $id, 'error' => $ok ? '' : self::err($r)];
    }

    /** The one-time code inside a code message ("... कोड: 482611 ..."), or ''. */
    public static function codeIn(string $text): string
    {
        $n = defined('OTP_LENGTH') ? max(4, min(8, (int) OTP_LENGTH)) : 6;
        return preg_match('/(?<!\d)(\d{' . $n . '})(?!\d)/u', $text, $m) === 1 ? $m[1] : '';
    }

    /**
     * Notify / AiSensy: a code message that no free window carried goes here.
     * null = not a code message or the template is not ready (the caller goes on as before).
     */
    public static function sendFromText(string $to, string $text, string $purpose = ''): ?array
    {
        try {
            require_once __DIR__ . '/wasend.php';
            if (!WaSend::isSecret($purpose !== '' ? $purpose : 'message', $text)) {
                return null;
            }
            $code = self::codeIn($text);
            if ($code === '' || !self::ready()) {
                return null;
            }
            return self::send($to, $code);
        } catch (Throwable $e) {
            return null;
        }
    }
}
