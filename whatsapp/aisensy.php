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
        if (strlen($d) === 13 && str_starts_with($d, '977') && preg_match('/^9779[78]/', $d)) {
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
    public static function sendMessage(string $phone, string $templateName, array $params = []): array
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
        if ($parameters !== []) {
            $template['components'] = [['type' => 'body', 'parameters' => $parameters]];
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

    /** POST one message; retries once on a network failure or AiSensy 5xx (a 4xx is final). */
    private static function post(string $to, string $label, string $key, array $body, array $out): array
    {
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
        return $out;
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
                return;
            }

            if (self::mode() === 'fallback' && class_exists('Notify')) {
                $want = substr(preg_replace('/\D+/', '', $number) ?? '', -10);
                foreach (Notify::whatsappJournal() as $j) {
                    $got = substr(preg_replace('/\D+/', '', (string) ($j['to'] ?? '')) ?? '', -10);
                    if (!empty($j['ok']) && $got !== '' && $got === $want) {
                        return;   // main driver reached the customer — nothing to do
                    }
                }
            }

            $f = self::bookingFacts($bid);
            $name = $f['name'] !== '' ? $f['name'] : 'Passenger';
            self::sendTicketConfirmation($number, $name, $f['from'] ?: '-', $f['to'] ?: '-',
                $f['date'] ?: '-', $f['seats'] ?: '-', $pnr);
        } catch (Throwable $e) {
            self::log('ERROR', (string) ($booking['contact_phone'] ?? ''), self::TPL_TICKET,
                ['error' => $e->getMessage(), 'pnr' => (string) ($booking['pnr'] ?? '')]);
        }
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
        } catch (Throwable $ignored) {
            // logging must never break a booking
        }
    }
}
