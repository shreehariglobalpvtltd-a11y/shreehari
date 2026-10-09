<?php
/**
 * =====================================================================
 *  whatsapp/aisensy.php — AiSensy WhatsApp (Project / Direct API) helper.
 *
 *  3 Oct 2026. A second, independent WhatsApp sender beside the Notify
 *  driver router (includes/notify.php). It does NOT replace the live
 *  whatsapp_driver (Meta Cloud API) and never touches whatsapp/webhook.php.
 *
 *  Endpoint: POST https://apis.aisensy.com/project-apis/v1/project/{id}/messages
 *  Auth:     header X-AiSensy-Project-API-Pwd: <project API password>
 *  Body:     Meta-style template message (to / type=template / components).
 *  (The older campaign endpoint backend.aisensy.com/campaign/t1/api/v2 needs
 *  a campaign JWT key, which this project does not use — the credential the
 *  AiSensy dashboard issued is the Project API password.)
 *
 *  Config resolves env -> ROOT/.env -> settings table -> default:
 *    AISENSY_API_KEY        aisensy_api_key       (project API password)
 *    AISENSY_PROJECT_ID     aisensy_project_id    (default below)
 *    AISENSY_TEMPLATE_LANG  aisensy_template_lang (default en)
 *    -                      aisensy_mode          off | fallback | always
 *                                                 (default fallback)
 *
 *  aisensy_mode, for the automatic ticket confirmation only:
 *    off      — never sends from the booking hook.
 *    fallback — sends only when the main driver did NOT reach the customer
 *               (Notify journal has no ok=true entry for that number), so a
 *               passenger never gets the same ticket twice.
 *    always   — sends on every confirmation, in addition to the main driver.
 *
 *  Contract: no method throws; every send returns
 *    ['success' => bool, 'message_id' => string, 'http' => int,
 *     'error' => string, 'response' => mixed]
 *  and writes one line to logs/aisensy.log (the key is never logged).
 *
 *  Templates (submitted to Meta via AiSensy 3 Oct 2026, category UTILITY):
 *    shg_ticket_confirmed   {{1}} name {{2}} from {{3}} to {{4}} date {{5}} seat {{6}} ticket id
 *    shg_departure_reminder {{1}} name {{2}} departure time
 *    shg_booking_cancelled  {{1}} name {{2}} refund amount
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(404);
    exit;
}

final class AiSensyAPI
{
    public const API_BASE           = 'https://apis.aisensy.com/project-apis/v1/project/';
    public const DEFAULT_PROJECT_ID = '6ac0f921f6a7bf2dfb5d9594';

    public const TPL_TICKET   = 'shg_ticket_confirmed';
    public const TPL_TICKET_IMAGE = 'shg_ticket_image';   // same 6 vars + ticket PNG header
    public const TPL_REMINDER = 'shg_departure_reminder';
    public const TPL_CANCEL   = 'shg_booking_cancelled';

    /** @var array<string,string>|null parsed ROOT/.env, read once per request */
    private static ?array $dotenv = null;

    /* -----------------------------------------------------------------
     *  Config
     * ----------------------------------------------------------------- */

    /** Environment first, then ROOT/.env, then the settings table, then the default. */
    public static function config(string $env, string $settingKey, string $default = ''): string
    {
        $v = getenv($env);
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        $file = self::dotenv();
        if (isset($file[$env]) && $file[$env] !== '') {
            return $file[$env];
        }
        if (class_exists('Settings')) {
            try {
                $s = trim(Settings::getString($settingKey, ''));
                if ($s !== '') {
                    return $s;
                }
            } catch (Throwable $ignored) {
                // settings table unreachable — fall back to the default
            }
        }
        return $default;
    }

    public static function apiKey(): string
    {
        return self::config('AISENSY_API_KEY', 'aisensy_api_key');
    }

    public static function projectId(): string
    {
        $id = self::config('AISENSY_PROJECT_ID', 'aisensy_project_id', self::DEFAULT_PROJECT_ID);
        return preg_match('/^[a-f0-9]{24}$/i', $id) ? $id : self::DEFAULT_PROJECT_ID;
    }

    public static function enabled(): bool
    {
        return self::apiKey() !== '';
    }

    public static function mode(): string
    {
        $m = strtolower(self::config('AISENSY_MODE', 'aisensy_mode', 'fallback'));
        return in_array($m, ['off', 'fallback', 'always'], true) ? $m : 'fallback';
    }

    /** KEY=VALUE lines of ROOT/.env (comments and blank lines ignored). */
    private static function dotenv(): array
    {
        if (self::$dotenv !== null) {
            return self::$dotenv;
        }
        self::$dotenv = [];
        $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
        $path = $root . '/.env';
        if (!is_file($path) || !is_readable($path)) {
            return self::$dotenv;
        }
        foreach ((array) @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim((string) $line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[strlen($v) - 1] === $v[0]) {
                $v = substr($v, 1, -1);
            }
            if ($k !== '') {
                self::$dotenv[$k] = $v;
            }
        }
        return self::$dotenv;
    }

    /* -----------------------------------------------------------------
     *  Phone
     * ----------------------------------------------------------------- */

    /**
     * Normalise to international digits, no "+". A bare 10-digit Indian
     * mobile gets the 91 prefix; numbers already carrying 91 or 977 (Nepal)
     * are kept. Anything else returns '' (rejected, never sent).
     */
    public static function normalizePhone(string $phone): string
    {
        $d = preg_replace('/\D+/', '', $phone) ?? '';
        $d = ltrim($d, '0');                       // 0091…, 09876…
        if (strlen($d) === 10 && preg_match('/^[6-9]/', $d)) {
            return '91' . $d;
        }
        if (strlen($d) === 12 && str_starts_with($d, '91') && preg_match('/^91[6-9]/', $d)) {
            return $d;
        }
        if (strlen($d) === 13 && str_starts_with($d, '977') && preg_match('/^9779[678]/', $d)) {
            return $d;
        }
        return '';
    }

    /* -----------------------------------------------------------------
     *  Send
     * ----------------------------------------------------------------- */

    /**
     * Send an approved template.
     *
     * @param list<string|int|float> $params body variables {{1}}..{{n}} in order
     */
    public static function sendMessage(string $phone, string $templateName, array $params = [], ?string $headerImage = null, bool $allowMarketing = false): array
    {
        $out = ['success' => false, 'message_id' => '', 'http' => 0, 'error' => '', 'response' => null];

        $to = self::normalizePhone($phone);
        if ($to === '') {
            $out['error'] = 'invalid phone number';
            self::log('SKIP', $phone, $templateName, $out);
            return $out;
        }
        $key = self::apiKey();
        if ($key === '') {
            $out['error'] = 'AISENSY_API_KEY not configured';
            self::log('SKIP', $to, $templateName, $out);
            return $out;
        }
        if (!preg_match('/^[a-z0-9_]{1,512}$/', $templateName)) {
            $out['error'] = 'invalid template name';
            self::log('SKIP', $to, $templateName, $out);
            return $out;
        }
        /* 9 Oct 2026 (owner: "AiSensy ma sasto utility template matra"): a
           MARKETING template costs ~6x a utility one, so the wallet pays for
           it only when Campaigns asks on purpose; and never while the policy
           is meta_only. includes/waroute.php. */
        $why = self::routeRefusal($templateName, $allowMarketing);
        if ($why !== '') {
            $out['error'] = $why;
            self::log('SKIP', $to, $templateName, $out);
            return $out;
        }

        $parameters = [];
        foreach (array_values($params) as $p) {
            // WhatsApp rejects empty params, newlines/tabs and 4+ spaces in one.
            $t = preg_replace('/[\r\n\t]+| {4,}/u', ' ', trim((string) $p)) ?? '';
            $parameters[] = ['type' => 'text', 'text' => $t !== '' ? mb_substr($t, 0, 900) : '-'];
        }

        $template = [
            'language' => ['policy' => 'deterministic', 'code' => self::config('AISENSY_TEMPLATE_LANG', 'aisensy_template_lang', 'en')],
            'name'     => $templateName,
        ];
        $components = [];
        if ($headerImage !== null && preg_match('#^https://#i', $headerImage)) {
            $components[] = ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => $headerImage]]]];
        }
        if ($parameters !== []) {
            $components[] = ['type' => 'body', 'parameters' => $parameters];
        }
        if ($components !== []) {
            $template['components'] = $components;
        }
        return self::post($to, $templateName, $key, ['to' => $to, 'type' => 'template', 'template' => $template], $out);
    }

    /**
     * Free-form text — WhatsApp delivers it ONLY inside the 24 h window after
     * the customer last wrote to us (bot replies). Outside it use a template.
     */
    public static function sendText(string $phone, string $text): array
    {
        $out = ['success' => false, 'message_id' => '', 'http' => 0, 'error' => '', 'response' => null];
        $to  = self::normalizePhone($phone);
        $key = self::apiKey();
        $text = trim($text);
        if ($to === '' || $key === '' || $text === '') {
            $out['error'] = $to === '' ? 'invalid phone number' : ($key === '' ? 'AISENSY_API_KEY not configured' : 'empty text');
            self::log('SKIP', $to !== '' ? $to : $phone, 'text', $out);
            return $out;
        }
        return self::post($to, 'text', $key, [
            'to' => $to, 'type' => 'text', 'recipient_type' => 'individual',
            'text' => ['preview_url' => true, 'body' => mb_substr($text, 0, 4096)],
        ], $out);
    }

    /** Image by public URL (+ optional caption) — same 24 h window rule as sendText(). */
    public static function sendImage(string $phone, string $imageUrl, string $caption = ''): array
    {
        $out = ['success' => false, 'message_id' => '', 'http' => 0, 'error' => '', 'response' => null];
        $to  = self::normalizePhone($phone);
        $key = self::apiKey();
        if ($to === '' || $key === '' || !preg_match('#^https://#i', $imageUrl)) {
            $out['error'] = $to === '' ? 'invalid phone number' : ($key === '' ? 'AISENSY_API_KEY not configured' : 'image URL must be https');
            self::log('SKIP', $to !== '' ? $to : $phone, 'image', $out);
            return $out;
        }
        $image = ['link' => $imageUrl];
        if (trim($caption) !== '') {
            $image['caption'] = mb_substr(trim($caption), 0, 1024);
        }
        return self::post($to, 'image', $key, ['to' => $to, 'type' => 'image', 'recipient_type' => 'individual', 'image' => $image], $out);
    }

    /* -----------------------------------------------------------------
     *  Scope (9 Oct 2026, owner: "AiSensy only for the ticket to a new
     *  customer - paisa save garnu paryo"). aisensy_scope:
     *    ticket_only (default)  the wallet pays for a customer's ticket
     *                           (the PNG / text template to the customer's
     *                           own number or second number) and nothing
     *                           else: no OTP, bus update, staff note,
     *                           compose, seller copy or campaign. Those
     *                           go free on the Meta number inside a window,
     *                           or wait for the person's "hi".
     *    all                    every send as before.
     * ----------------------------------------------------------------- */

    private static bool $ticketCtx = false;

    public static function ticketOnly(): bool
    {
        return strtolower(trim(self::config('AISENSY_SCOPE', 'aisensy_scope', 'ticket_only'))) !== 'all';
    }

    /** Run $fn with AiSensy allowed as the TICKET sender (the one paid purpose). */
    public static function asTicket(callable $fn): mixed
    {
        $prev = self::$ticketCtx;
        self::$ticketCtx = true;
        try {
            return $fn();
        } finally {
            self::$ticketCtx = $prev;
        }
    }

    /** POST one message; retries once on a network failure or AiSensy 5xx (a 4xx is final). */
    private static function post(string $to, string $label, string $key, array $body, array $out): array
    {
        // Only a TEMPLATE costs money; free-form text / pictures land only inside a window and are free.
        if (($body['type'] ?? '') === 'template' && self::ticketOnly() && !self::$ticketCtx) {
            $out['error'] = 'AiSensy is ticket-only (aisensy_scope) - template ' . $label . ' not sent';
            self::log('SKIP', $to, $label, $out);
            return $out;
        }
        $url = self::API_BASE . self::projectId() . '/messages';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $r = self::request('POST', $url, $key, $body);
            $out['http']     = $r['http'];
            $out['response'] = $r['json'] ?? $r['raw'];
            $out['error']    = '';

            if ($r['http'] >= 200 && $r['http'] < 300) {
                $j = is_array($r['json']) ? $r['json'] : [];
                $out['success']    = true;
                $out['message_id'] = (string) ($j['messages'][0]['id'] ?? ($j['id'] ?? ($j['message_id'] ?? ($j['submitted_message_id'] ?? ''))));
                self::log('SENT', $to, $label, $out);
                self::ledger($to, $label, $body, $out, '');
                return $out;
            }

            $j = is_array($r['json']) ? $r['json'] : [];
            $out['error'] = $r['curl'] !== ''
                ? 'network: ' . $r['curl']
                : (string) ($j['message'] ?? ($j['error']['message'] ?? ('HTTP ' . $r['http'])));
            if (!($r['http'] === 0 || $r['http'] >= 500) || $attempt === 2) {
                break;
            }
            usleep(400000);
        }
        self::log('FAIL', $to, $label, $out);
        self::ledger($to, $label, $body, $out, '');
        return $out;
    }

    /**
     * 5 Oct 2026 — every send also lands in the WhatsApp Manager's ledger
     * (includes/msgledger.php): who, which template, the words, the wamid.
     * Silent when the ledger is not installed; never breaks a send.
     */
    private static function ledger(string $to, string $label, array $body, array $out, string $status): void
    {
        try {
            if (!class_exists('MsgLedger') && defined('INCLUDE_PATH') && is_file(INCLUDE_PATH . '/msgledger.php')) {
                require_once INCLUDE_PATH . '/msgledger.php';
            }
            if (class_exists('MsgLedger')) {
                MsgLedger::recordAiSensy($to, $label, $body, $out, $status);
            }
        } catch (Throwable $ignored) {
        }
    }

    /** a) Ticket confirmed: {{1}} name {{2}} from {{3}} to {{4}} date {{5}} seat {{6}} ticket id */
    public static function sendTicketConfirmation(string $phone, string $passengerName, string $from, string $to, string $date, string $seatNo, string $ticketId): array
    {
        return self::sendMessage($phone, self::tpl('aisensy_tpl_ticket', self::TPL_TICKET),
            [$passengerName, $from, $to, $date, $seatNo, $ticketId]);
    }

    /** b) Departure reminder: {{1}} name {{2}} departure time */
    public static function sendBookingReminder(string $phone, string $passengerName, string $departureTime): array
    {
        return self::sendMessage($phone, self::tpl('aisensy_tpl_reminder', self::TPL_REMINDER),
            [$passengerName, $departureTime]);
    }

    /** c) Cancellation: {{1}} name {{2}} refund amount (number only — the template carries the ₹) */
    public static function sendCancellationNotice(string $phone, string $passengerName, float|int|string $refundAmount): array
    {
        $amt = is_numeric($refundAmount)
            ? rtrim(rtrim(number_format((float) $refundAmount, 2, '.', ''), '0'), '.')
            : (string) $refundAmount;
        return self::sendMessage($phone, self::tpl('aisensy_tpl_cancel', self::TPL_CANCEL),
            [$passengerName, $amt !== '' ? $amt : '0']);
    }

    /** Project status (plan, sandbox, number) — read-only, for the test page. */
    public static function projectInfo(): array
    {
        $key = self::apiKey();
        if ($key === '') {
            return ['http' => 0, 'error' => 'AISENSY_API_KEY not configured'];
        }
        $r = self::request('GET', self::API_BASE . self::projectId(), $key, null);
        return ['http' => $r['http'], 'error' => $r['curl'], 'response' => $r['json'] ?? $r['raw']];
    }

    /**
     * 5 Oct 2026 — the project's WhatsApp templates for the campaign screen:
     * [{name, status, category, language, text, vars}] (vars = how many {{n}}).
     * Only APPROVED ones can be sent; MARKETING ones are for offers.
     *
     * @return list<array{name:string,status:string,category:string,language:string,text:string,vars:int,image:bool}>
     */
    public static function templates(): array
    {
        $key = self::apiKey();
        if ($key === '') {
            return [];
        }
        // limit=100: without it AiSensy returns only the first 10 templates
        $r = self::request('GET', self::API_BASE . self::projectId() . '/wa_template?limit=100', $key, null);
        $j = is_array($r['json']) ? $r['json'] : [];
        // AiSensy answers {template: [...], size, count}
        $list = $j['template'] ?? ($j['data'] ?? ($j['templates'] ?? (array_is_list($j) ? $j : [])));
        $out = [];
        foreach (is_array($list) ? $list : [] as $t) {
            if (!is_array($t) || (string) ($t['name'] ?? '') === '') {
                continue;
            }
            $text = '';
            $image = false;
            foreach ((array) ($t['components'] ?? []) as $c) {
                if (strtoupper((string) ($c['type'] ?? '')) === 'BODY') {
                    $text = (string) ($c['text'] ?? '');
                }
                if (strtoupper((string) ($c['type'] ?? '')) === 'HEADER' && strtoupper((string) ($c['format'] ?? '')) === 'IMAGE') {
                    $image = true;
                }
            }
            if ($text === '') {
                $text = (string) ($t['text'] ?? ($t['body'] ?? ''));
            }
            preg_match_all('/\{\{(\d+)\}\}/', $text, $m);
            $hdr = $t['header'] ?? null;
            if (is_array($hdr) && strtoupper((string) ($hdr['format'] ?? ($hdr['type'] ?? ''))) === 'IMAGE') {
                $image = true;
            }
            $out[] = [
                'name'     => (string) $t['name'],
                'status'   => strtoupper((string) ($t['status'] ?? '')),
                'category' => strtoupper((string) ($t['category'] ?? '')),
                'language' => (string) ($t['language'] ?? ''),
                'text'     => $text,
                'vars'     => $m[1] !== [] ? max(array_map('intval', $m[1])) : (int) ($t['total_parameters'] ?? 0),
                'image'    => $image || strtoupper((string) ($t['type'] ?? '')) === 'IMAGE',
            ];
        }
        return $out;
    }

    /** Submit a new template for Meta approval (same body the 3 Oct templates used). */
    public static function createTemplate(array $body): array
    {
        $key = self::apiKey();
        if ($key === '') {
            return ['http' => 0, 'error' => 'AISENSY_API_KEY not configured'];
        }
        $r = self::request('POST', self::API_BASE . self::projectId() . '/wa_template', $key, $body);
        return ['http' => $r['http'], 'error' => $r['curl'], 'response' => $r['json'] ?? $r['raw']];
    }

    /* -----------------------------------------------------------------
     *  Staff messages (admin/api/wa-send.php): agent statement / summaries,
     *  bus chalani to the crew, office summaries.
     * ----------------------------------------------------------------- */

    public const TPL_AGENT  = 'shg_agent_statement';   // {{1}} name {{2}} what {{3}} summary {{4}} link
    public const TPL_CHALAN = 'shg_bus_chalani';       // {{1}} what {{2}} details {{3}} link
    public const TPL_OFFICE = 'shg_office_update';     // {{1}} report {{2}} details

    /**
     * Send one composed WaTemplates message through AiSensy: the approved
     * template for its audience first, then (template still in review / no
     * template) the full text, which WhatsApp delivers inside a 24 h window.
     *
     * @param array $msg WaTemplates::compose() output
     * @return array sendMessage()-style result + 'how' => template|text
     */
    public static function sendStaffMessage(array $msg, string $audience): array
    {
        $to    = (string) ($msg['intl'] ?? '') !== '' ? (string) $msg['intl'] : (string) ($msg['to'] ?? '');
        $label = trim((string) ($msg['label'] ?? 'Update'));
        $text  = trim((string) ($msg['text'] ?? ''));
        $media = trim((string) ($msg['mediaUrl'] ?? ''));
        $name  = trim((string) ($msg['recipientName'] ?? ''));
        // One template variable cannot hold a newline.
        $flat  = trim((string) preg_replace('/\s*\R\s*/u', ' - ', $text));
        $flat  = mb_substr($flat !== '' ? $flat : '-', 0, 700);
        $link  = $media !== '' ? $media : '-';

        // 9 Oct 2026: free on the Meta number when this person wrote to it in the last 24 h.
        $free = self::metaFree($to, $text, $media !== '' ? $media : null, 'staff_' . $audience, ['name' => $name]);
        if ($free !== null) {
            return $free;
        }

        $r = match ($audience) {
            'agent'  => self::sendMessage($to, self::tpl('aisensy_tpl_agent', self::TPL_AGENT), [$name !== '' ? $name : 'Agent', $label, $flat, $link]),
            'crew'   => self::sendMessage($to, self::tpl('aisensy_tpl_chalan', self::TPL_CHALAN), [$label, $flat, $link]),
            default  => self::sendMessage($to, self::tpl('aisensy_tpl_office', self::TPL_OFFICE), [$label, mb_substr($flat . ($media !== '' ? ' - ' . $media : ''), 0, 700)]),
        };
        if ($r['success']) {
            return $r + ['how' => 'template'];
        }

        // Free-text fallback (24 h window only): picture with caption, or text + link.
        if ($media !== '' && (string) ($msg['mediaType'] ?? '') === 'image' && mb_strlen($text) <= 1024) {
            $r = self::sendImage($to, $media, $text);
            if ($r['success']) {
                return $r + ['how' => 'text'];
            }
        }
        $r = self::sendText($to, $text . ($media !== '' && !str_contains($text, $media) ? "\n" . $media : ''));
        return $r + ['how' => 'text'];
    }

    /**
     * The customer login code (7 Oct 2026, owner: "OTP WhatsApp through
     * AiSensy"). A dedicated AUTHENTICATION template when the office has
     * one approved (setting aisensy_tpl_login_otp - Meta's fixed body
     * "{{1}} is your verification code." plus a copy-code button), else the
     * approved shg_message template / 24 h free text via sendAny().
     *
     * @return array{success: bool, message_id: string, http: int, error: string, response: mixed, how: string}
     */
    public static function sendLoginOtp(string $phone, string $code, string $name = ''): array
    {
        $to  = self::normalizePhone($phone);
        $tpl = trim(Settings::getString('aisensy_tpl_login_otp', ''));
        $company = Settings::getString('company_name', 'S Hari Global');
        $text = 'Your ' . $company . ' login code is ' . $code . ' — तपाईंको लगइन कोड ' . $code
              . '. Valid for ' . (defined('OTP_EXPIRY_MINUTES') ? OTP_EXPIRY_MINUTES : 10) . ' minutes. Do not share it with anyone.';

        // 9 Oct 2026: the code goes free on the Meta number when their 24 h window is open there.
        if ($to !== '') {
            $free = self::metaFree($to, $text, null, 'otp', ['name' => $name]);
            if ($free !== null) {
                return $free;
            }
        }
        if ($to !== '' && $tpl !== '' && preg_match('/^[a-z0-9_]{1,512}$/', $tpl) && self::apiKey() !== '') {
            $out = ['success' => false, 'message_id' => '', 'http' => 0, 'error' => '', 'response' => null];
            $r = self::post($to, $tpl, self::apiKey(), ['to' => $to, 'type' => 'template', 'template' => [
                'language'   => ['policy' => 'deterministic', 'code' => self::config('AISENSY_TEMPLATE_LANG', 'aisensy_template_lang', 'en')],
                'name'       => $tpl,
                'components' => [
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                    ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ],
            ]], $out);
            if ($r['success']) {
                return $r + ['how' => 'template ' . $tpl];
            }
        }
        return self::sendAny($phone, $text, null, $name, 'Login code');
    }

    /** Any message to anyone (5 Oct 2026): {{1}} name {{2}} the message. Submitted for approval. */
    public const TPL_MESSAGE = 'shg_message';

    /** Longest message that still fits one template variable (Meta: 1024 for the whole body). */
    public const MESSAGE_MAX = 850;

    /**
     * Send ANY message to one number (5 Oct 2026, owner: "jun number ma j msg
     * ni lekhera 1-1 jana lai pathaun milne"). AiSensy is the only WhatsApp
     * sender that works, so Notify::whatsapp() hands every non-ticket message
     * here when whatsapp_driver = aisensy.
     *
     *   1. the number wrote to us in the last 24 h  -> the full text / picture (free)
     *   2. shg_message template (once approved)      -> "Namaste {{1}}, ... {{2}}"
     *   3. shg_office_update (approved)              -> "Report: {{1}}. Details: {{2}}"
     *   4. plain text (only lands inside a 24 h window - last resort)
     *
     * A template variable cannot hold a newline, so lines are joined with " · ".
     *
     * @return array{success: bool, message_id: string, http: int, error: string, response: mixed, how: string}
     */
    public static function sendAny(string $phone, string $text, ?string $mediaUrl = null, string $name = '', string $label = ''): array
    {
        $text  = trim($text);
        $media = trim((string) $mediaUrl);
        $isImg = $media !== '' && preg_match('#\.(png|jpe?g|webp)(\?|$)|[?&]img=1#i', $media) === 1;
        $to    = self::normalizePhone($phone);
        if ($to === '' || $text === '') {
            return ['success' => false, 'message_id' => '', 'http' => 0, 'response' => null, 'how' => '',
                    'error' => $to === '' ? 'not an India / Nepal mobile number' : 'empty message'];
        }

        /* 9 Oct 2026 (owner: "Meta ko free API"): the Meta number first, free,
           when this person wrote to it in the last 24 h. includes/waroute.php. */
        $free = self::metaFree($to, $text, $media !== '' ? $media : null, 'message', ['name' => $name, 'label' => $label]);
        if ($free !== null) {
            return $free;
        }

        $window = false;
        try {
            if (!class_exists('WaQueue') && defined('INCLUDE_PATH') && is_file(INCLUDE_PATH . '/waqueue.php')) {
                require_once INCLUDE_PATH . '/waqueue.php';
            }
            $window = class_exists('WaQueue') && WaQueue::ready() && WaQueue::windowOpen($to);
        } catch (Throwable $ignored) {
            $window = false;
        }
        if ($window) {
            $r = ($isImg && mb_strlen($text) <= 1024) ? self::sendImage($to, $media, $text)
                : self::sendText($to, $text . ($media !== '' && !str_contains($text, $media) ? "\n" . $media : ''));
            if ($r['success']) {
                return $r + ['how' => 'free text (24 h window)'];
            }
        }

        $flat = trim((string) preg_replace('/\s*\R\s*/u', ' · ', $text));
        $flat = (string) preg_replace('/[ \t]{4,}/', '   ', $flat);   // Meta: no 4+ spaces in a variable
        if ($media !== '' && !str_contains($flat, $media)) {
            $flat .= ' · ' . $media;
        }
        $flat = mb_substr($flat, 0, self::MESSAGE_MAX);
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 60) : 'Sir / Madam';

        $r = self::sendMessage($to, self::tpl('aisensy_tpl_message', self::TPL_MESSAGE), [$name, $flat]);
        if ($r['success']) {
            return $r + ['how' => 'template ' . self::tpl('aisensy_tpl_message', self::TPL_MESSAGE)];
        }
        $r = self::sendMessage($to, self::tpl('aisensy_tpl_office', self::TPL_OFFICE), [trim($label) !== '' ? mb_substr(trim($label), 0, 60) : 'Message', $flat]);
        if ($r['success']) {
            return $r + ['how' => 'template ' . self::tpl('aisensy_tpl_office', self::TPL_OFFICE)];
        }
        if (!$window) {
            $r2 = self::sendText($to, $text . ($media !== '' && !str_contains($text, $media) ? "\n" . $media : ''));
            if ($r2['success']) {
                return $r2 + ['how' => 'free text'];
            }
        }
        return $r + ['how' => 'template'];
    }

    /* -----------------------------------------------------------------
     *  Passenger notices that are not the ticket (5 Oct 2026)
     *
     *  Bus updates (leaving soon, delayed, started, at the border, arrived)
     *  and the cancellation notice were sent by the Meta driver only, and
     *  Meta has refused every template since 131042 (payment method) - so a
     *  passenger who had not written in the last 24 h heard nothing. While
     *  aisensy_mode is "always" these go out on AiSensy's approved templates
     *  as well, exactly like the ticket does. Back on "fallback" (Meta
     *  billing fixed) they stop, so nobody is told twice.
     * ----------------------------------------------------------------- */

    public const TPL_TRIP = 'shg_trip_update';   // {{1}} name {{2}} booking no {{3}} update {{4}} trip details

    /** May a non-ticket notice go out on AiSensy right now? */
    private static function noticesOn(): bool
    {
        return self::enabled() && self::mode() === 'always'
            && (!class_exists('Settings') || Settings::getBool('aisensy_notices_on', true));
    }

    /**
     * One bus update for one booking. Never throws.
     *
     * @return array{ok: bool, detail: string}
     */
    public static function tripUpdate(array $booking, string $event, string $headline, string $details, string $fullText = ''): array
    {
        $out = ['ok' => false, 'detail' => ''];
        try {
            if (!self::noticesOn()) {
                $out['detail'] = 'AiSensy notices are off';
                return $out;
            }
            $bid = (int) ($booking['id'] ?? 0);
            $pnr = (string) ($booking['pnr'] ?? '');
            $number = class_exists('Notify') ? Notify::whatsappNumberFor($booking) : '';
            $to = self::normalizePhone($number !== '' ? $number : (string) ($booking['contact_phone'] ?? ''));
            if ($to === '' || $pnr === '') {
                $out['detail'] = 'no usable phone';
                return $out;
            }
            $name = $bid > 0 ? self::bookingFacts($bid)['name'] : '';
            // 9 Oct 2026: free on the Meta number inside the passenger's 24 h window there.
            $free = self::metaFree($to, $fullText !== '' ? $fullText : trim($headline . "\n" . $details), null, 'trip_' . $event, ['bookingId' => $bid]);
            if ($free !== null) {
                $out['ok']     = true;
                $out['detail'] = $free['how'];
                return $out;
            }
            $r = self::sendMessage($to, self::tpl('aisensy_tpl_trip', self::TPL_TRIP),
                [$name !== '' ? $name : 'Passenger', $pnr, $headline !== '' ? $headline : 'Bus update', $details !== '' ? $details : '-']);
            $how = 'template';
            if (!$r['success'] && $fullText !== '') {
                $r   = self::sendText($to, $fullText);     // reaches only an open 24 h window
                $how = 'text';
            }
            if (class_exists('Notify')) {
                Notify::logOutbound($to, 'AiSensy bus update ' . $pnr . ' - ' . $event . ' (' . $how . ')', $r['success'] ? 'sent' : 'failed', [
                    'provider'  => 'aisensy',
                    /* A failed bus update is not a failed TICKET: without the
                       booking id the office "ticket not delivered" alert
                       (cron/wa-fail-alert.php) leaves it alone. */
                    'bookingId' => ($r['success'] && $bid > 0) ? $bid : null,
                    'sid'       => (string) $r['message_id'],
                    'purpose'   => 'trip_' . $event,
                    'error'     => $r['success'] ? null : mb_substr((string) $r['error'], 0, 250),
                ]);
            }
            $out['ok']     = (bool) $r['success'];
            $out['detail'] = $r['success'] ? $how : (string) $r['error'];
        } catch (Throwable $e) {
            $out['detail'] = $e->getMessage();
        }
        return $out;
    }

    /**
     * The cancellation notice on the approved shg_booking_cancelled template.
     * Never throws.
     */
    public static function cancellationFor(array $booking, float $refundAmount): bool
    {
        try {
            if (!self::noticesOn()) {
                return false;
            }
            $bid = (int) ($booking['id'] ?? 0);
            $pnr = (string) ($booking['pnr'] ?? '');
            $number = class_exists('Notify') ? Notify::whatsappNumberFor($booking) : '';
            $to = self::normalizePhone($number !== '' ? $number : (string) ($booking['contact_phone'] ?? ''));
            if ($to === '' || $bid <= 0) {
                return false;
            }
            $name = self::bookingFacts($bid)['name'];
            // 9 Oct 2026: free on the Meta number inside the passenger's 24 h window there.
            $co   = class_exists('Settings') ? Settings::getString('company_name', 'S Hari Global') : 'S Hari Global';
            $free = self::metaFree($to,
                '❌ ' . $co . "\n" . 'नमस्ते ' . ($name !== '' ? $name : '') . ', तपाईंको बुकिङ ' . $pnr . ' रद्द भयो। फिर्ता रकम: ' . inr($refundAmount) . "\n"
                . 'Your booking ' . $pnr . ' is cancelled. Refund: ' . inr($refundAmount) . "\n" . 'सहयोग / Help: ' . Settings::officePhone(),
                null, 'booking_cancelled', ['bookingId' => $bid]);
            if ($free !== null) {
                return true;
            }
            $r = self::sendCancellationNotice($to, ($name !== '' ? $name : 'Passenger') . ' (' . $pnr . ')', $refundAmount);
            if (class_exists('Notify')) {
                Notify::logOutbound($to, 'AiSensy cancellation notice ' . $pnr, $r['success'] ? 'sent' : 'failed', [
                    'provider'  => 'aisensy',
                    'bookingId' => $bid,
                    'sid'       => (string) $r['message_id'],
                    'purpose'   => 'booking_cancelled',
                    'error'     => $r['success'] ? null : mb_substr((string) $r['error'], 0, 250),
                ]);
            }
            return (bool) $r['success'];
        } catch (Throwable $e) {
            return false;
        }
    }

    /* -----------------------------------------------------------------
     *  Booking hook (includes/events.php, booking.approved)
     * ----------------------------------------------------------------- */

    /**
     * Called right after Notify::bookingConfirmed(). Never throws. Honours
     * aisensy_mode (see the header) so a ticket the main driver already
     * delivered is not sent a second time.
     */
    public static function onBookingConfirmed(array $booking): void
    {
        try {
            if (!self::enabled() || self::mode() === 'off') {
                return;
            }
            if (class_exists('Settings') && !Settings::getBool('whatsapp_notify_customer', true)) {
                return;
            }
            $bid = (int) ($booking['id'] ?? 0);
            $pnr = (string) ($booking['pnr'] ?? '');
            if ($bid <= 0 || $pnr === '') {
                return;
            }

            // Same number resolution the main driver uses (India / Nepal hint).
            $number = class_exists('Notify') ? Notify::whatsappNumberFor($booking) : '';
            if ($number === '') {
                $number = (string) ($booking['contact_phone'] ?? '');
            }
            if (self::normalizePhone($number) === '') {
                self::log('SKIP', $number, self::TPL_TICKET, ['error' => 'no usable phone', 'pnr' => $pnr]);
                /* 5 Oct 2026: 30 of 123 desk tickets in a week carried no
                   customer number (0000000000) and 12 a 9-digit typo - nobody
                   got those on WhatsApp. The counter / agent who cut it gets
                   the ticket at once and hands it on. */
                if (!class_exists('WaQueue') && defined('INCLUDE_PATH') && is_file(INCLUDE_PATH . '/waqueue.php')) {
                    require_once INCLUDE_PATH . '/waqueue.php';
                }
                if (class_exists('WaQueue') && Security::rateLimit('ais_ticket', $pnr, 1, 2592000)) {
                    WaQueue::sellerNow(Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $bid]) ?? $booking);
                }
                return;
            }

            if (class_exists('Notify')) {
                $want = substr(preg_replace('/\D+/', '', $number) ?? '', -10);
                foreach (Notify::whatsappJournal() as $j) {
                    $got = substr(preg_replace('/\D+/', '', (string) ($j['to'] ?? '')) ?? '', -10);
                    if ($got === '' || $got !== $want || empty($j['ok'])) {
                        continue;
                    }
                    // 'idempotent' = this ticket already went out earlier: never resend.
                    // fallback: the main driver accepted it — nothing to do.
                    if (self::mode() === 'fallback' || (string) ($j['reason'] ?? '') === 'idempotent') {
                        return;
                    }
                    /* 9 Oct 2026: the Meta number delivered the ticket free
                       (includes/waroute.php) — record it and queue only the
                       second number / seller copy; no template on top. */
                    if (in_array((string) ($j['reason'] ?? ''), ['meta_free', 'meta_template'], true)) {
                        if (!class_exists('WaQueue') && defined('INCLUDE_PATH') && is_file(INCLUDE_PATH . '/waqueue.php')) {
                            require_once INCLUDE_PATH . '/waqueue.php';
                        }
                        if (class_exists('WaQueue') && WaQueue::ready() && Security::rateLimit('ais_ticket', $pnr, 1, 2592000)) {
                            $full = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $bid]) ?? $booking;
                            WaQueue::onConfirmed($full, self::normalizePhone($number), (string) ($j['reason'] === 'meta_free' ? 'meta free' : 'meta template'), (string) ($j['ref'] ?? ''));
                        }
                        return;
                    }
                }
            }

            // One AiSensy ticket per PNR (re-fired approvals, webhook duplicates).
            if (!Security::rateLimit('ais_ticket', $pnr, 1, 2592000)) {
                return;
            }

            $f = self::bookingFacts($bid);
            $name = $f['name'] !== '' ? $f['name'] : 'Passenger';
            $link = '';
            try {
                if (!class_exists('Ticket') && defined('INCLUDE_PATH') && is_file(INCLUDE_PATH . '/ticket.php')) {
                    require_once INCLUDE_PATH . '/ticket.php';
                }
                $link = class_exists('Ticket') ? (string) Ticket::imageUrl($pnr) : '';
            } catch (Throwable $ignored) {
                $link = '';
            }
            $vars = [$name, $f['from'] ?: '-', $f['to'] ?: '-', $f['date'] ?: '-', $f['seats'] ?: '-', $pnr];

            /* Order (owner 3 Oct: "ticket direct PNG ma jane"):
                 1. shg_ticket_image  — approved template, ticket PNG as the header
                 2. shg_ticket_confirmed — text template (no picture)
                 3. the PNG itself with the facts as caption  } WhatsApp delivers
                 4. plain text + link                         } these only inside
                                                                the 24 h window */
            /* 9 Oct 2026: ticketTemplate() is the one ladder — the Meta number
               free (open window) → a Meta template (wa_meta_templates_on) →
               the AiSensy image template → the AiSensy text template. */
            $r   = self::ticketTemplate($number, $bid, $pnr);
            $how = (string) $r['how'];
            /* 4 Oct 2026 (owner: "ek choti hi lekhne bas, 24 free msg"): when no
               template went through, the ticket goes free through the "hi"
               queue - now if that number's 24 h window is open, else the moment
               it writes to us. The second number and the counter / agent who
               sold it are queued too (includes/waqueue.php). A blind free-form
               send outside the window only fails later (131047), so it is not
               attempted any more when the queue is available. */
            if (!class_exists('WaQueue') && defined('INCLUDE_PATH') && is_file(INCLUDE_PATH . '/waqueue.php')) {
                require_once INCLUDE_PATH . '/waqueue.php';
            }
            if (class_exists('WaQueue') && WaQueue::ready()) {
                $full = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $bid]) ?? $booking;
                WaQueue::onConfirmed($full, self::normalizePhone($number), $r['success'] ? $how : '', $r['success'] ? (string) $r['message_id'] : '');
                return;
            }

            $caption = "S Hari Global Pvt Ltd - Ticket confirmed\n"
                . 'Dear ' . $name . ",\n"
                . 'Route: ' . ($f['from'] ?: '-') . ' -> ' . ($f['to'] ?: '-') . "\n"
                . 'Date: ' . ($f['date'] ?: '-') . "\n"
                . 'Seat: ' . ($f['seats'] ?: '-') . "\n"
                . 'Ticket ID: ' . $pnr . "\n"
                . 'Help: +919157001507';
            self::asTicket(static function () use (&$r, &$how, $number, $link, $caption): void {
                if (!$r['success'] && $link !== '') {
                    $r = self::sendImage($number, $link, $caption);
                    $how = 'image';
                }
                if (!$r['success']) {
                    $r = self::sendText($number, $caption . ($link !== '' ? "\nTicket: " . $link : ''));
                    $how = 'text';
                }
            });

            if (class_exists('Notify')) {
                Notify::logOutbound(self::normalizePhone($number), 'AiSensy ticket ' . $pnr . ' (' . $how . ')', $r['success'] ? 'sent' : 'failed', [
                    'provider'  => 'aisensy',
                    'bookingId' => $bid,
                    'sid'       => $r['message_id'],
                    'purpose'   => 'aisensy_ticket',
                    'error'     => $r['success'] ? null : mb_substr((string) $r['error'], 0, 250),
                ]);
            }
        } catch (Throwable $e) {
            self::log('ERROR', (string) ($booking['contact_phone'] ?? ''), self::TPL_TICKET,
                ['error' => $e->getMessage(), 'pnr' => (string) ($booking['pnr'] ?? '')]);
        }
    }

    /**
     * Staff "Resend ticket" and the retry cron (5 Oct 2026). Both used to go
     * out only on the Meta number, which has refused every message since the
     * WABA payment problem (131042) - 44 silent failures in one day while
     * the desk believed the ticket had gone. The approved AiSensy templates
     * reach any number, so they go first; no once-per-PNR limit here because
     * a resend is a deliberate second copy.
     *
     * @return array{ok: bool, detail: string, wamid: string, to: string}
     */
    public static function resendTicket(array $booking): array
    {
        $out = ['ok' => false, 'detail' => '', 'wamid' => '', 'to' => ''];
        try {
            if (!self::enabled() || self::mode() === 'off') {
                $out['detail'] = 'AiSensy is off';
                return $out;
            }
            $bid = (int) ($booking['id'] ?? 0);
            $pnr = (string) ($booking['pnr'] ?? '');
            $number = class_exists('Notify') ? Notify::whatsappNumberFor($booking) : '';
            $to = self::normalizePhone($number !== '' ? $number : (string) ($booking['contact_phone'] ?? ''));
            if ($bid <= 0 || $pnr === '' || $to === '') {
                $out['detail'] = 'no usable phone';
                return $out;
            }
            $out['to'] = $to;
            $r   = self::ticketTemplate($to, $bid, $pnr);
            $how = $r['how'];
            // A Meta send is already in message_logs (WaRoute writes its own row).
            if (class_exists('Notify') && !str_starts_with($how, 'meta')) {
                Notify::logOutbound($to, 'AiSensy ticket resend ' . $pnr . ' (' . $how . ')', $r['success'] ? 'sent' : 'failed', [
                    'provider'  => 'aisensy',
                    'bookingId' => $bid,
                    'sid'       => (string) $r['message_id'],
                    'purpose'   => 'ticket_resend',
                    'error'     => $r['success'] ? null : mb_substr((string) $r['error'], 0, 250),
                ]);
            }
            $out['ok']     = (bool) $r['success'];
            $out['wamid']  = (string) $r['message_id'];
            $out['detail'] = $r['success'] ? $how : (string) $r['error'];
        } catch (Throwable $e) {
            $out['detail'] = $e->getMessage();
        }
        return $out;
    }

    /**
     * The approved ticket template to one number: the PNG-header template,
     * then the text one. Templates reach a number with no open 24 h window.
     * $nameAs replaces {{1}} (e.g. "Ram (agent copy)" for the seller's copy).
     *
     * @return array{success: bool, message_id: string, error: string, how: string}
     */
    public static function ticketTemplate(string $to, int $bid, string $pnr, string $nameAs = '', bool $metaFirst = true): array
    {
        $f = self::bookingFacts($bid);
        $name = $nameAs !== '' ? $nameAs : ($f['name'] !== '' ? $f['name'] : 'Passenger');
        $vars = [$name, $f['from'] ?: '-', $f['to'] ?: '-', $f['date'] ?: '-', $f['seats'] ?: '-', $pnr];
        $link = '';
        try {
            if (!class_exists('Ticket') && defined('INCLUDE_PATH') && is_file(INCLUDE_PATH . '/ticket.php')) {
                require_once INCLUDE_PATH . '/ticket.php';
            }
            $link = class_exists('Ticket') ? (string) Ticket::imageUrl($pnr) : '';
        } catch (Throwable $ignored) {
            $link = '';
        }
        $r = ['success' => false, 'message_id' => '', 'error' => ''];
        $how = '';
        /* 9 Oct 2026 (includes/waroute.php): the Meta number first — the PNG
           with the facts as caption, free, when this number's window is open
           there; then a Meta template while wa_meta_templates_on. $metaFirst
           = false is the webhook's recovery after a Meta send failed async. */
        if ($metaFirst) {
            $caption = '✅ ' . Settings::companyFor()['name'] . " — तपाईंको टिकट · Your ticket\n"
                . 'नमस्ते ' . $name . "\n"
                . 'PNR: ' . $pnr . "\n"
                . ($f['from'] !== '' ? $f['from'] . ' → ' . $f['to'] . "\n" : '')
                . ($f['date'] !== '' ? 'मिति / Date: ' . $f['date'] . "\n" : '')
                . ($f['seats'] !== '' ? 'सिट / Seat: ' . $f['seats'] . "\n" : '')
                . ($link !== '' ? 'टिकट / Ticket: ' . $link . "\n" : '')
                . 'सहयोग / Help: ' . Settings::officePhone();
            $free = self::metaFree($to, $caption, $link !== '' ? $link : null, 'ticket', ['bookingId' => $bid]);
            if ($free !== null) {
                return ['success' => true, 'message_id' => (string) $free['message_id'], 'error' => '', 'how' => (string) $free['how']];
            }
            try {
                require_once INCLUDE_PATH . '/waroute.php';
                if (WaRoute::metaTemplatesOn() && class_exists('Notify')) {
                    $b = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $bid]);
                    if ($b !== null) {
                        $tv  = Notify::ticketVars($b);
                        $hdr = (string) ($tv['7'] ?? $link);
                        unset($tv['7']);
                        $mt = WaRoute::metaTemplate($to, Settings::getString('whatsapp_template_name', ''), Settings::getString('whatsapp_template_lang', 'en'),
                            $tv, $hdr !== '' ? $hdr : null, 'ticket', ['bookingId' => $bid]);
                        if ($mt !== null) {
                            return ['success' => true, 'message_id' => (string) $mt['message_id'], 'error' => '', 'how' => (string) $mt['how']];
                        }
                    }
                }
            } catch (Throwable $ignored) {
            }
        }
        /* The paid part. A customer's own ticket ($nameAs = '') is the one
           purpose the wallet pays for under aisensy_scope = ticket_only; a
           seller copy ($nameAs set) waits for the clerk's "hi" on Meta. */
        $send = static function () use (&$r, &$how, $to, $link, $vars): void {
            if ($link !== '') {
                $r = self::sendMessage($to, self::tpl('aisensy_tpl_ticket_image', self::TPL_TICKET_IMAGE), $vars, $link);
                $how = 'image template';
            }
            if (!$r['success']) {
                $r = self::sendTicketConfirmation($to, ...$vars);
                $how = 'template';
            }
        };
        if ($nameAs === '') {
            self::asTicket($send);
        } else {
            $send();
        }
        return ['success' => (bool) $r['success'], 'message_id' => (string) $r['message_id'], 'error' => (string) $r['error'], 'how' => $how];
    }

    /** Public view of bookingFacts() for includes/waqueue.php. */
    public static function facts(int $bid): array
    {
        return self::bookingFacts($bid);
    }

    /* -----------------------------------------------------------------
     *  Meta first (9 Oct 2026, includes/waroute.php)
     * ----------------------------------------------------------------- */

    /**
     * The Meta number, free, when this number wrote to it in the last 24 h
     * (our own record). Null = not attempted / refused: carry on with the
     * paid template. Never throws. Result carries the sendMessage() shape.
     *
     * @return array{success: bool, message_id: string, http: int, error: string, response: mixed, how: string}|null
     */
    private static function metaFree(string $to, string $text, ?string $media, string $purpose, array $meta = []): ?array
    {
        try {
            if (!defined('INCLUDE_PATH') || !is_file(INCLUDE_PATH . '/waroute.php')) {
                return null;
            }
            require_once INCLUDE_PATH . '/waroute.php';
            $r = WaRoute::freeFirst($to, $text, $media, $purpose, $meta);
            if ($r === null || !$r['success']) {
                return null;
            }
            return ['success' => true, 'message_id' => (string) $r['message_id'], 'http' => 200, 'error' => '', 'response' => null, 'how' => (string) $r['how']];
        } catch (Throwable $e) {
            return null;
        }
    }

    /** '' when this template may be sent, else why not (policy / marketing guard). */
    private static function routeRefusal(string $templateName, bool $allowMarketing): string
    {
        try {
            if (!defined('INCLUDE_PATH') || !is_file(INCLUDE_PATH . '/waroute.php')) {
                return '';
            }
            require_once INCLUDE_PATH . '/waroute.php';
            return WaRoute::aisensyRefusal($templateName, $allowMarketing);
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * What really happened to a sent message (an accepted send can still fail
     * later): ['status' => SENT|DELIVERED|READ|FAILED|'', 'error' => text].
     * GET /messages/{wamid}?userNumber=<intl digits>.
     *
     * @return array{status:string,error:string,http:int}
     */
    public static function messageStatus(string $wamid, string $phone): array
    {
        $out = ['status' => '', 'error' => '', 'http' => 0];
        $key = self::apiKey();
        $to  = self::normalizePhone($phone);
        if ($key === '' || $to === '' || $wamid === '') {
            return $out;
        }
        $r = self::request('GET', self::API_BASE . self::projectId() . '/messages/' . rawurlencode($wamid) . '?userNumber=' . $to, $key, null);
        $out['http'] = $r['http'];
        $j = is_array($r['json']) ? $r['json'] : [];
        $m = is_array($j['message'] ?? null) ? $j['message'] : (is_array($j['data'] ?? null) ? $j['data'] : $j);
        $out['status'] = strtoupper((string) ($m['status'] ?? ($j['status'] ?? '')));
        $fail = $m['failureResponse'] ?? ($m['failure_response'] ?? ($m['error'] ?? null));
        if (is_array($fail)) {
            $out['error'] = trim((string) ($fail['code'] ?? '') . ' ' . (string) ($fail['reason'] ?? ($fail['message'] ?? ($fail['title'] ?? ''))));
        } elseif (is_string($fail)) {
            $out['error'] = $fail;
        }
        if (is_numeric($out['status'])) {
            $out['status'] = '';   // an HTTP status, not a message status
        }
        return $out;
    }

    /**
     * Name / from / to / date / seats for a booking — read-only queries,
     * same tables Notify::ticketFacts() reads. Passenger's own boarding and
     * drop stop win over the route's end cities.
     *
     * @return array{name:string,from:string,to:string,date:string,seats:string}
     */
    private static function bookingFacts(int $bid): array
    {
        $f = ['name' => '', 'from' => '', 'to' => '', 'date' => '', 'seats' => ''];

        $pax = Database::fetchAll(
            'SELECT full_name, seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY id',
            ['b' => $bid]
        );
        if ($pax !== []) {
            $f['name'] = trim((string) $pax[0]['full_name']);
            $seats = array_values(array_filter(array_map(static fn($r) => (string) $r['seat_no'], $pax)));
            // Display ids (LA1 …) like the ticket, when the seat helper is around.
            try {
                if (!class_exists('Seats') && defined('INCLUDE_PATH') && is_file(INCLUDE_PATH . '/seats.php')) {
                    require_once INCLUDE_PATH . '/seats.php';
                }
                if (class_exists('Seats') && method_exists('Seats', 'displayLabels')) {
                    $mode = (string) Database::scalar('SELECT booking_mode FROM bookings WHERE id = :b LIMIT 1', ['b' => $bid], 'sharing');
                    $coach = (string) Database::scalar(
                        'SELECT COALESCE(s.coach_type_override, r.coach_type)
                           FROM booking_legs bl
                           JOIN schedules s ON s.id = bl.schedule_id
                           JOIN routes    r ON r.id = s.route_id
                          WHERE bl.booking_id = :b ORDER BY bl.id ASC LIMIT 1',
                        ['b' => $bid], 'sleeper'
                    );
                    $f['seats'] = (string) Seats::displayLabels($seats, $coach !== '' ? $coach : 'sleeper', $mode !== '' ? $mode : 'sharing');
                }
            } catch (Throwable $ignored) {
                $f['seats'] = '';
            }
            if ($f['seats'] === '') {
                $f['seats'] = implode(', ', $seats);
            }
        }

        $leg = Database::fetch(
            'SELECT bl.travel_date, bl.boarding_stop, bl.drop_stop, r.from_city, r.to_city
               FROM booking_legs bl
               JOIN schedules s ON s.id = bl.schedule_id
               JOIN routes    r ON r.id = s.route_id
              WHERE bl.booking_id = :b
              ORDER BY bl.id ASC
              LIMIT 1',
            ['b' => $bid]
        );
        if ($leg !== null) {
            $stop = static fn(string $s): string => trim(explode('·', $s)[0]);
            $bs = trim((string) ($leg['boarding_stop'] ?? ''));
            $ds = trim((string) ($leg['drop_stop'] ?? ''));
            $f['from'] = $bs !== '' && $stop($bs) !== '' ? $stop($bs) : trim((string) $leg['from_city']);
            $f['to']   = $ds !== '' && $stop($ds) !== '' ? $stop($ds) : trim((string) $leg['to_city']);
            $f['from'] = trim(preg_replace('/@\s*[0-2]?\d:\d{2}.*$/u', '', $f['from']) ?? $f['from']);
            $f['date'] = (string) $leg['travel_date'];
        }
        return $f;
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------- */

    private static function tpl(string $settingKey, string $default): string
    {
        $t = self::config('', $settingKey, $default);
        return preg_match('/^[a-z0-9_]{1,512}$/', $t) ? $t : $default;
    }

    /** @return array{http:int, raw:string, json:mixed, curl:string} */
    private static function request(string $method, string $url, string $key, ?array $body): array
    {
        if (!function_exists('curl_init')) {
            return ['http' => 0, 'raw' => '', 'json' => null, 'curl' => 'php-curl missing'];
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => [
                'X-AiSensy-Project-API-Pwd: ' . $key,
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST]       = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = $raw === false ? curl_error($ch) : '';
        curl_close($ch);
        $raw  = is_string($raw) ? $raw : '';
        $json = json_decode($raw, true);
        return ['http' => $http, 'raw' => mb_substr($raw, 0, 2000), 'json' => is_array($json) ? $json : null, 'curl' => $err];
    }

    /** One line per call to logs/aisensy.log. The API key is never written. */
    public static function log(string $status, string $to, string $template, array $info): void
    {
        try {
            $dir = defined('LOG_PATH') ? LOG_PATH : dirname(__DIR__) . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $resp = $info['response'] ?? null;
            $line = sprintf(
                "[%s] %s | %s | %s | http=%d | id=%s | err=%s | resp=%s%s\n",
                date('Y-m-d H:i:s'),
                $status,
                preg_replace('/[^\d+]/', '', $to) ?: '-',
                $template,
                (int) ($info['http'] ?? 0),
                (string) ($info['message_id'] ?? ''),
                str_replace(["\r", "\n"], ' ', (string) ($info['error'] ?? '')),
                mb_substr(is_string($resp) ? str_replace(["\r", "\n"], ' ', $resp) : (string) json_encode($resp, JSON_UNESCAPED_UNICODE), 0, 600),
                isset($info['pnr']) ? ' | pnr=' . $info['pnr'] : ''
            );
            @file_put_contents($dir . '/aisensy.log', $line, FILE_APPEND | LOCK_EX);
            if ($status === 'SKIP') {
                // never reached AiSensy (bad number, no key) - still part of "who got what"
                self::ledger($to, $template, [], $info, 'skipped');
            }
            if (class_exists('Logger', false)) {
                Logger::adopt($dir . '/aisensy.log');      // a root cron must not lock the website out
            }
        } catch (Throwable $ignored) {
            // logging must never break a booking
        }
    }
}
