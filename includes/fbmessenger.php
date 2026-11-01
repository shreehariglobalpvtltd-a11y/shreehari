<?php
/**
 * =====================================================================
 *  FbMessenger — the WhatsApp assistant, on our Facebook Page (1 Nov 2026).
 *
 *  Owner: "Facebook bata AI le ticket katera pathaos, ya link pathaos,
 *  photo napaye link — manche jasto kura garos, WhatsApp jastai."
 *
 *  A message to the Page arrives at api/facebook-webhook.php (signed by
 *  Meta with the app secret). This class answers it with the SAME
 *  assistant the website and the app use — AiAgent::handleWeb(), same
 *  tools, same quote → "ho" gate, same audit row — handed a 'web' identity
 *  with via = 'facebook'. Back go the words, then the ticket picture (or,
 *  when Messenger will not take the picture, its link), then up to three
 *  buttons from what the tools actually did (Book / Pay / Open ticket /
 *  Where is my bus).
 *
 *  WHO IS WRITING
 *  --------------
 *  A Facebook account carries no mobile number, and name + phone is never
 *  proof (owner, 8 Oct). So a Messenger visitor starts as a website guest:
 *  may ask anything and get a quote, reads no booking, gets no ticket. To
 *  go further they send their mobile number here; the same WhatsApp login
 *  code the app uses goes to that number (Auth::issueOtp, purpose
 *  'verify'); they type it back here; from then on this Messenger account
 *  is that proven number — their own bookings, and (only when the office
 *  switches fb_msgr_sell on) a ticket issued here, picture in Messenger and
 *  on their WhatsApp. "number change" forgets it.
 *
 *  Switches (all OFF until the office pastes the Page token):
 *    fb_msgr_on       the Page answers at all
 *    fb_msgr_sell     the assistant may ISSUE tickets here (quote → ho)
 *    fb_page_token    Page access token (pages_messaging)        — secret
 *    fb_app_secret    the Meta app's secret, checks every webhook — secret
 *    fb_verify_token  what the webhook answers Meta's set-up check with
 *  Settings → admin/facebook.php. Nothing here needs a migration.
 *
 *  NEVER THROWS at its caller: a failed send is logged and the next part
 *  of the reply still goes.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class FbMessenger
{
    private const SCOPE   = 'fb_msgr';
    private const VERSION = 'v21.0';

    /** Test seams — null in production. */
    public static ?Closure $transport = null;   // fn(string $method, string $path, array $body): array{ok, http, body}
    public static ?Closure $brain     = null;   // fn(array $ctx, string $text): ?array  (AiAgent::handleWeb shape)
    public static ?Closure $issueCode = null;   // fn(string $phone, string $country): array{ok, error}
    public static ?Closure $checkCode = null;   // fn(string $phone, string $code): array{ok, error}
    public static ?array   $memStore  = null;   // per-person state in memory instead of kv_store

    private function __construct() {}

    /* =================================================================
     *  Switches and keys
     * ================================================================= */

    public static function enabled(): bool
    {
        return Settings::getBool('fb_msgr_on', false)
            && self::token() !== ''
            && self::appSecret() !== ''
            && function_exists('curl_init');
    }

    public static function token(): string
    {
        return trim(Settings::getString('fb_page_token', ''));
    }

    public static function appSecret(): string
    {
        return trim(Settings::getString('fb_app_secret', ''));
    }

    /** The webhook's set-up password. Made once, on first need, and kept. */
    public static function verifyToken(): string
    {
        $t = trim(Settings::getString('fb_verify_token', ''));
        if ($t === '') {
            $t = 'shg-' . bin2hex(random_bytes(12));
            Settings::set('fb_verify_token', $t, 'string', 'facebook');
        }
        return $t;
    }

    public static function webhookUrl(): string
    {
        return appUrl('api/facebook-webhook.php');
    }

    /* =================================================================
     *  The webhook's two checks
     * ================================================================= */

    /**
     * Meta's set-up call: GET ?hub.mode=subscribe&hub.verify_token=…&hub.challenge=…
     * (PHP turns the dots into underscores). The challenge to echo, or null.
     */
    public static function verifyChallenge(array $get): ?string
    {
        $mode  = (string) ($get['hub_mode'] ?? $get['hub.mode'] ?? '');
        $token = (string) ($get['hub_verify_token'] ?? $get['hub.verify_token'] ?? '');
        $chal  = (string) ($get['hub_challenge'] ?? $get['hub.challenge'] ?? '');
        $want  = trim(Settings::getString('fb_verify_token', ''));
        if ($mode !== 'subscribe' || $want === '' || $chal === '' || !hash_equals($want, $token)) {
            return null;
        }
        return preg_match('/^[A-Za-z0-9_\-]{1,200}$/', $chal) === 1 ? $chal : null;
    }

    /** X-Hub-Signature-256 = "sha256=" . HMAC-SHA256(raw body, app secret). */
    public static function signatureOk(string $raw, string $header): bool
    {
        $secret = self::appSecret();
        if ($secret === '' || !str_starts_with($header, 'sha256=')) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $raw, $secret), substr($header, 7));
    }

    /**
     * The messages in one webhook call, oldest first. Echoes of our own
     * replies, delivery and read receipts are dropped; a button tap
     * (postback / quick reply) arrives as its payload.
     *
     * @return list<array{psid: string, page: string, text: string, payload: string, mid: string, attachment: bool}>
     */
    public static function events(array $payload): array
    {
        if (($payload['object'] ?? '') !== 'page') {
            return [];
        }
        $out = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $page = (string) ($entry['id'] ?? '');
            foreach ((array) ($entry['messaging'] ?? []) as $m) {
                $psid = (string) ($m['sender']['id'] ?? '');
                if ($psid === '' || $psid === $page) {
                    continue;
                }
                if (isset($m['message']) && is_array($m['message'])) {
                    $msg = $m['message'];
                    if (!empty($msg['is_echo'])) {
                        continue;
                    }
                    $out[] = [
                        'psid'       => $psid,
                        'page'       => $page,
                        'text'       => trim((string) ($msg['text'] ?? '')),
                        'payload'    => (string) ($msg['quick_reply']['payload'] ?? ''),
                        'mid'        => (string) ($msg['mid'] ?? ''),
                        'attachment' => !empty($msg['attachments']),
                    ];
                } elseif (isset($m['postback']) && is_array($m['postback'])) {
                    $out[] = [
                        'psid'       => $psid,
                        'page'       => $page,
                        'text'       => trim((string) ($m['postback']['title'] ?? '')),
                        'payload'    => (string) ($m['postback']['payload'] ?? ''),
                        'mid'        => (string) ($m['postback']['mid'] ?? ('pb-' . ($m['timestamp'] ?? ''))),
                        'attachment' => false,
                    ];
                }
            }
        }
        return $out;
    }

    /* =================================================================
     *  One message
     * ================================================================= */

    /** Answer one event from events(). Never throws. */
    public static function handle(array $ev): void
    {
        try {
            self::handleInner($ev);
        } catch (Throwable $e) {
            Logger::error('Facebook Messenger turn failed: ' . $e->getMessage(), [], 'facebook');
        }
    }

    private static function handleInner(array $ev): void
    {
        $psid = (string) ($ev['psid'] ?? '');
        if ($psid === '' || !self::enabled()) {
            return;
        }
        // A message to a different Page (an old subscription) is not ours to answer.
        $page = trim(Settings::getString('fb_page_id', ''));
        if ($page !== '' && (string) ($ev['page'] ?? '') !== '' && (string) $ev['page'] !== $page) {
            return;
        }

        $st  = self::state($psid);
        $mid = (string) ($ev['mid'] ?? '');
        if ($mid !== '') {
            $seen = (array) ($st['mids'] ?? []);
            if (in_array($mid, $seen, true)) {
                return;                                    // Meta re-delivered it
            }
            $st['mids'] = array_slice(array_merge([$mid], $seen), 0, 20);
            self::save($psid, $st);
        }

        // A loop, a prank or a broken integration — never a person.
        if (!Security::rateLimit('fb_msgr', $psid, 20, 300) || !Security::rateLimit('fb_msgr_day', $psid, 150, 86400)) {
            Logger::warning('Facebook Messenger rate limit hit', ['psid' => self::tag($psid)], 'facebook');
            return;
        }

        $text    = trim((string) ($ev['text'] ?? ''));
        $payload = (string) ($ev['payload'] ?? '');
        if ($payload === 'GET_STARTED' || ($text === '' && $payload === '' && empty($ev['attachment']))) {
            self::sendText($psid, self::welcome());
            return;
        }
        if ($text === '' && !empty($ev['attachment'])) {
            self::sendText($psid, "🙏 फोटो/फाइल पाएँ, तर म अहिले लेखेको सन्देश मात्र बुझ्छु। के मद्दत चाहियो, लेखेर भन्नुहोस् — जस्तै: \"bholi Surat bata 2 seat\".");
            return;
        }
        if ($text === '' && $payload !== '') {
            $text = $payload;
        }

        self::typing($psid);

        // "number change" — forget the proven number on this Messenger account.
        if (preg_match('/^\s*(logout|log out|number\s*(change|badal\w*|fer\w*)|naya\s*number|forget\s*me)\b/iu', $text) === 1) {
            unset($st['phone'], $st['country'], $st['verified_at'], $st['pending'], $st['pending_country'], $st['pending_until']);
            self::save($psid, $st);
            self::sendText($psid, "✅ ठिक छ, यो Messenger बाट नम्बर हटाइयो। नयाँ नम्बर यहीँ पठाउनुहोस् — WhatsApp मा कोड आउँछ।");
            return;
        }

        // The WhatsApp code, typed back while we are waiting for it.
        $pending = (string) ($st['pending'] ?? '');
        if ($pending !== '' && (int) ($st['pending_until'] ?? 0) >= time() && ($code = self::codeFrom($text)) !== null) {
            $r = self::checkCodeFor($pending, $code);
            if (!empty($r['ok'])) {
                $st['phone']       = $pending;
                $st['country']     = (string) ($st['pending_country'] ?? '');
                $st['verified_at'] = time();
                unset($st['pending'], $st['pending_country'], $st['pending_until']);
                self::save($psid, $st);
                Logger::info('Facebook Messenger number proven', ['psid' => self::tag($psid), 'phone' => maskPhone($pending)], 'facebook');
                self::sendText($psid, "✅ नम्बर " . maskPhone($pending) . " पक्का भयो। अब यहीँबाट आफ्नो टिकट हेर्न"
                    . (Settings::getBool('fb_msgr_sell', false) ? ' र नयाँ टिकट काट्न' : '')
                    . " सकिन्छ। भन्नुहोस्, के गरौँ? 🙏");
            } else {
                self::sendText($psid, "⚠️ कोड मिलेन: " . (string) ($r['error'] ?? 'try again') . "\nफेरि लेख्नुहोस्, वा नयाँ कोडका लागि नम्बर फेरि पठाउनुहोस्।");
            }
            return;
        }

        // A mobile number on its own: send the WhatsApp code to prove it.
        $num = self::phoneFrom($text);
        if ($num !== null && $num[0] !== (string) ($st['phone'] ?? '')) {
            if (!Security::rateLimit('fb_msgr_code', $psid, 3, 3600)) {
                self::sendText($psid, "⏳ धेरै पटक कोड मागियो। एक घण्टापछि फेरि प्रयास गर्नुहोस्, वा " . appUrl('') . " मा बुक गर्नुहोस्।");
                return;
            }
            $r = self::issueCodeFor($num[0], $num[1]);
            if (!empty($r['ok'])) {
                $st['pending']         = $num[0];
                $st['pending_country'] = $num[1];
                $st['pending_until']   = time() + 60 * (defined('OTP_EXPIRY_MINUTES') ? (int) OTP_EXPIRY_MINUTES : 10);
                self::save($psid, $st);
                self::sendText($psid, "📲 " . maskPhone($num[0]) . " को WhatsApp मा कोड पठाइयो। त्यो कोड यहीँ लेख्नुहोस्।"
                    . ($num[1] === '' ? "\n(नेपालको नम्बर भए +977 सहित पठाउनुहोस्।)" : ''));
            } else {
                self::sendText($psid, "⚠️ कोड पठाउन सकिएन: " . (string) ($r['error'] ?? '') . "\n" . appUrl('') . " मा सिधै बुक गर्न सकिन्छ।");
            }
            return;
        }

        // Everything else: the assistant.
        $phone = (string) ($st['phone'] ?? '');
        $ctx = [
            'role'         => 'customer',
            'admin'        => null,
            'adminId'      => 0,
            'scopeAdminId' => null,
            'name'         => '',
            'phone'        => $phone,
            'channel'      => 'web',
            'via'          => 'facebook',
            'userId'       => 0,
            'stageKey'     => 'fb-' . $psid,
            'verified'     => $phone !== '',
        ];
        $answer = self::ask($ctx, $text);
        if ($answer === null || trim((string) ($answer['text'] ?? '')) === '') {
            self::sendText($psid, self::fallback());
            return;
        }
        self::reply($psid, $answer);
    }

    /* =================================================================
     *  Sending the answer
     * ================================================================= */

    /**
     * Words, then the ticket picture (its link if Messenger refuses the
     * picture), then up to three buttons.
     *
     * @param array{text?: string, media?: ?string, actions?: array} $answer
     */
    public static function reply(string $psid, array $answer): void
    {
        foreach (self::chunks(self::plain((string) ($answer['text'] ?? ''))) as $part) {
            self::sendText($psid, $part);
        }

        $media = trim((string) ($answer['media'] ?? ''));
        if ($media !== '') {
            $url = self::absolute($media);
            if (!self::sendImage($psid, $url)) {
                self::sendText($psid, "🎫 टिकट यहाँ खोल्नुहोस्: " . $url);
            }
        }

        $buttons = [];
        foreach ((array) ($answer['actions'] ?? []) as $a) {
            $href  = trim((string) ($a['href'] ?? ''));
            $label = trim((string) ($a['label'] ?? ''));
            if ($href === '' || $label === '') {
                continue;
            }
            $url = self::absolute($href);
            if (!str_starts_with($url, 'https://')) {
                continue;                                  // Messenger only opens https links
            }
            $buttons[] = ['type' => 'web_url', 'url' => $url, 'title' => mb_substr($label, 0, 20)];
            if (count($buttons) === 3) {
                break;
            }
        }
        if ($buttons !== []) {
            self::send($psid, ['attachment' => ['type' => 'template', 'payload' => [
                'template_type' => 'button',
                'text'          => '👇',
                'buttons'       => $buttons,
            ]]]);
        }
    }

    public static function sendText(string $psid, string $text): bool
    {
        $text = trim($text);
        return $text !== '' && self::send($psid, ['text' => mb_substr($text, 0, 2000)]);
    }

    public static function sendImage(string $psid, string $url): bool
    {
        return self::send($psid, ['attachment' => ['type' => 'image', 'payload' => ['url' => $url, 'is_reusable' => true]]]);
    }

    /** "…typing" while the assistant thinks. */
    public static function typing(string $psid): void
    {
        self::graph('POST', 'me/messages', ['recipient' => ['id' => $psid], 'sender_action' => 'typing_on']);
    }

    private static function send(string $psid, array $message): bool
    {
        $r = self::graph('POST', 'me/messages', [
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => $message,
        ]);
        if (!$r['ok']) {
            $err = (string) (json_decode((string) $r['body'], true)['error']['message'] ?? '');
            Logger::warning('Facebook send failed (HTTP ' . $r['http'] . ')', ['psid' => self::tag($psid), 'error' => mb_substr($err, 0, 200)], 'facebook');
        }
        return $r['ok'];
    }

    /* =================================================================
     *  Page set-up (admin/facebook.php)
     * ================================================================= */

    /** Who does the token belong to? Keeps the Page id + name on success. */
    public static function testToken(): array
    {
        if (self::token() === '') {
            return ['ok' => false, 'detail' => 'no Page token saved'];
        }
        $r = self::graph('GET', 'me?fields=id,name', []);
        $j = json_decode((string) $r['body'], true);
        if (!$r['ok'] || empty($j['id'])) {
            return ['ok' => false, 'detail' => (string) ($j['error']['message'] ?? ('HTTP ' . $r['http']))];
        }
        Settings::set('fb_page_id', (string) $j['id'], 'string', 'facebook');
        Settings::set('fb_page_name', (string) ($j['name'] ?? ''), 'string', 'facebook');
        return ['ok' => true, 'detail' => (string) ($j['name'] ?? '') . ' (' . $j['id'] . ')'];
    }

    /** Ask Meta to send this Page's messages to our app's webhook. */
    public static function subscribe(): array
    {
        $r = self::graph('POST', 'me/subscribed_apps', ['subscribed_fields' => 'messages,messaging_postbacks']);
        $j = json_decode((string) $r['body'], true);
        return ['ok' => $r['ok'] && !empty($j['success']), 'detail' => $r['ok'] ? 'subscribed' : (string) ($j['error']['message'] ?? ('HTTP ' . $r['http']))];
    }

    /** The "Get Started" button and the greeting a new visitor sees. */
    public static function setupProfile(): array
    {
        $company = Settings::getString('company_name', defined('APP_NAME') ? APP_NAME : 'Shree Hari Global');
        $r = self::graph('POST', 'me/messenger_profile', [
            'get_started' => ['payload' => 'GET_STARTED'],
            'greeting'    => [['locale' => 'default', 'text' => mb_substr('🙏 ' . $company . ' — bus ticket, fare, seat, timing: yaha sodhnus. Ticket pani yahi katna milcha.', 0, 160)]],
        ]);
        $j = json_decode((string) $r['body'], true);
        return ['ok' => $r['ok'] && !empty($j['result']), 'detail' => $r['ok'] ? 'Get Started + greeting set' : (string) ($j['error']['message'] ?? ('HTTP ' . $r['http']))];
    }

    /* =================================================================
     *  Pieces
     * ================================================================= */

    /** A message that is just a mobile number → [10-digit phone, 'NP'|'IN'|''], else null. */
    public static function phoneFrom(string $text): ?array
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) > 40) {
            return null;                                   // a sentence that happens to hold a number
        }
        $digits = preg_replace('/\D/', '', $t) ?? '';
        if (!in_array(strlen($digits), [10, 11, 12, 13, 14], true)) {
            return null;
        }
        // Little else besides the number: "+977 98…", "mero number 98…", "number: 98…"
        $rest = trim(preg_replace('/[\d\s+\-().:]/u', '', $t) ?? '');
        if (mb_strlen($rest) > 14) {
            return null;
        }
        $phone = normalisePhone($t);
        if (!Security::isValidPhone($phone)) {
            return null;
        }
        $d = ltrim($digits, '0');
        $country = (strlen($d) >= 13 && str_starts_with($d, '977')) ? 'NP' : ((strlen($d) >= 12 && str_starts_with($d, '91')) ? 'IN' : '');
        return [$phone, $country];
    }

    /** A message that is just the code → the digits, else null. */
    public static function codeFrom(string $text): ?string
    {
        $len = defined('OTP_LENGTH') ? (int) OTP_LENGTH : 6;
        if (preg_match('/^\D{0,16}?(\d{' . $len . '})\D{0,8}$/u', trim($text), $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /** Messenger shows markdown literally and cannot open "#/" app links. */
    public static function plain(string $text): string
    {
        $text = str_replace(['**', '__'], '', $text);
        $text = preg_replace('/^#{1,6}\s+/mu', '', $text) ?? $text;
        $text = preg_replace_callback('/(?<![\w\/])#\/[\w\-\/?=&%.]*/u', static fn(array $m): string => appUrl($m[0]), $text) ?? $text;
        return trim($text);
    }

    /** "#/my", "/download-ticket.php?…" or a full URL → a full https URL. */
    public static function absolute(string $href): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }
        return appUrl($href);
    }

    /**
     * Split a long reply into Messenger-sized parts (2000 chars) at line
     * breaks; never more than three.
     *
     * @return list<string>
     */
    public static function chunks(string $text, int $max = 1900): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        $parts = [];
        $cur = '';
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            if ($cur !== '' && mb_strlen($cur) + 1 + mb_strlen($line) > $max) {
                $parts[] = $cur;
                $cur = '';
            }
            $cur = $cur === '' ? $line : $cur . "\n" . $line;
            while (mb_strlen($cur) > $max) {
                $parts[] = mb_substr($cur, 0, $max);
                $cur = mb_substr($cur, $max);
            }
        }
        if (trim($cur) !== '') {
            $parts[] = $cur;
        }
        return array_slice($parts, 0, 3);
    }

    private static function welcome(): string
    {
        $company = Settings::getString('company_name', defined('APP_NAME') ? APP_NAME : 'Shree Hari Global');
        return "🙏 नमस्ते! म " . $company . " को सहायक हुँ।\n"
            . "बसको समय, भाडा, सिट, बोर्डिङ पोइन्ट — जे पनि सोध्नुहोस्। नेपाली, हिन्दी वा English मा लेख्न सक्नुहुन्छ।\n"
            . "आफ्नो टिकट हेर्न वा यहीँ टिकट काट्न आफ्नो मोबाइल नम्बर पठाउनुहोस् — WhatsApp मा कोड आउँछ।";
    }

    private static function fallback(): string
    {
        $wa = Settings::officeWhatsApp();
        return "🙏 नमस्ते! अहिले म छिटो जवाफ दिन सकिनँ।\n"
            . "टिकट: " . appUrl('') . "\n"
            . ($wa !== '' ? "WhatsApp: https://wa.me/" . preg_replace('/\D/', '', $wa) : "कार्यालय: " . Settings::officePhone());
    }

    private static function ask(array $ctx, string $text): ?array
    {
        if (self::$brain !== null) {
            return (self::$brain)($ctx, $text);
        }
        require_once INCLUDE_PATH . '/aiagent.php';
        return AiAgent::handleWeb($ctx, $text, '');
    }

    /** The app's WhatsApp login code (api/otp.php's sender), purpose 'verify'. */
    private static function issueCodeFor(string $phone, string $country): array
    {
        if (self::$issueCode !== null) {
            return (self::$issueCode)($phone, $country);
        }
        // The same global ceiling api/otp.php keeps on paid code sends.
        if (!Security::rateLimit('otp_send_global_h', 'global', max(1, Settings::getInt('otp_global_hourly_cap', 150)), 3600)
            || !Security::rateLimit('otp_send_global_d', 'global', max(1, Settings::getInt('otp_global_daily_cap', 800)), 86400)) {
            return ['ok' => false, 'error' => 'too many codes right now'];
        }
        $r = Auth::issueOtp($phone, 'verify', 'whatsapp');
        if (empty($r['ok'])) {
            return ['ok' => false, 'error' => (string) ($r['error'] ?? 'could not make a code')];
        }
        $code = (string) $r['code'];
        $sent = false;
        try {
            if (is_file(ROOT_PATH . '/whatsapp/aisensy.php')) {
                if (!class_exists('AiSensyAPI')) {
                    require_once ROOT_PATH . '/whatsapp/aisensy.php';
                }
                if (AiSensyAPI::enabled()) {
                    $onFile = (string) Database::scalar('SELECT full_name FROM users WHERE phone = :p LIMIT 1', ['p' => $phone], '');
                    $s = AiSensyAPI::sendLoginOtp(($country === 'NP' ? '977' : '91') . $phone, $code, mb_substr(trim($onFile), 0, 60));
                    $sent = (bool) ($s['success'] ?? false);
                }
            }
            if (!$sent) {
                require_once INCLUDE_PATH . '/notify.php';
                $company = Settings::getString('company_name', APP_NAME);
                $sent = Notify::whatsapp($phone, $company . ' को कोड: ' . $code . '। यो कोड कसैलाई नबताउनुहोस्।', null, $country !== '' ? $country : null) === true;
            }
        } catch (Throwable $e) {
            Logger::warning('Facebook code send failed: ' . $e->getMessage(), [], 'facebook');
            $sent = false;
        }
        Logger::info('Facebook Messenger code ' . ($sent ? 'sent' : 'NOT delivered'), ['phone' => maskPhone($phone)], 'otp');
        return $sent ? ['ok' => true, 'error' => ''] : ['ok' => false, 'error' => 'WhatsApp could not deliver the code'];
    }

    private static function checkCodeFor(string $phone, string $code): array
    {
        if (self::$checkCode !== null) {
            return (self::$checkCode)($phone, $code);
        }
        $r = Auth::verifyOtp($phone, $code, 'verify');
        return ['ok' => !empty($r['ok']), 'error' => (string) ($r['error'] ?? '')];
    }

    /** One Graph API call. @return array{ok: bool, http: int, body: string} */
    private static function graph(string $method, string $path, array $body): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($method, $path, $body);
        }
        $url = 'https://graph.facebook.com/' . self::VERSION . '/' . $path
            . (str_contains($path, '?') ? '&' : '?') . 'access_token=' . rawurlencode(self::token());
        $ch = curl_init($url);
        $opt = [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT           => 15,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
        ];
        if ($method === 'POST') {
            $opt[CURLOPT_POST]       = true;
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
            $opt[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }
        curl_setopt_array($ch, $opt);
        $out  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['ok' => $out !== false && $http === 200, 'http' => $http, 'body' => is_string($out) ? $out : ''];
    }

    /* ---- per-person state: {phone, country, verified_at, pending…, mids} ---- */

    private static function state(string $psid): array
    {
        if (self::$memStore !== null) {
            return (array) (self::$memStore[$psid] ?? []);
        }
        $raw = (string) Database::scalar(
            'SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k',
            ['s' => self::SCOPE, 'k' => $psid], ''
        );
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }

    private static function save(string $psid, array $st): void
    {
        if (self::$memStore !== null) {
            self::$memStore[$psid] = $st;
            return;
        }
        Database::run(
            "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, 'facebook')
             ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = 'facebook'",
            ['s' => self::SCOPE, 'k' => $psid, 'v' => json_encode($st, JSON_UNESCAPED_UNICODE)]
        );
    }

    /** A short, non-reversible tag for logs — never the raw Facebook id. */
    private static function tag(string $psid): string
    {
        return substr(hash('sha256', $psid), 0, 10);
    }
}
