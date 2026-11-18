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
 *  THE GUIDED BOOKING (19 Nov 2026, WA-3 - switch fb_msgr_form_on, default OFF)
 *  ---------------------------------------------------------------------------
 *  "ticket" / "book" here walks the SAME conversation as WhatsApp (WaBooking:
 *  name, pickup, day, seats → summary → an explicit yes → QuickTicket's sale
 *  with every rule), keyed by the proven number, its tappable answers as
 *  Messenger quick replies. Before the number is proven the person is asked
 *  for it (the WhatsApp code below) and the order starts by itself once the
 *  code is right - the ticket goes to that number's WhatsApp.
 *
 *  Switches (all OFF until the office pastes the Page token):
 *    fb_msgr_on       the Page answers at all
 *    fb_msgr_sell     the assistant may ISSUE tickets here (quote → ho)
 *    fb_msgr_form_on  the guided WhatsApp-style booking above (needs
 *                     fb_msgr_sell on too: it never sells on its own)
 *    fb_page_token    Page access token (pages_messaging)        — secret
 *    fb_app_secret    the Meta app's secret, checks every webhook — secret
 *    fb_verify_token  what the webhook answers Meta's set-up check with
 *  Settings → admin/facebook.php. Answering needs no migration.
 *
 *  THE CONVERSATION LOG (16 Nov 2026, Master v2 P6-6a)
 *  ----------------------------------------------------
 *  Each message the person sends and each one we send back is written to
 *  fb_inbound (database/upgrade-2026-11-16-fb-inbound.sql) so the office
 *  can read the conversation and answer by hand on admin/messenger-inbox.php
 *  — free text, only while Meta's 24-hour window from the person's last
 *  message is open, every reply audited. A typed login code, a card number
 *  or a password is stored hidden. Without the table nothing is stored and
 *  the assistant answers exactly as before.
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
    public static ?array   $memLog    = null;   // conversation rows in memory instead of fb_inbound
    public static ?Closure $booker    = null;   // fn(string $intl, string $text, string $country): ?array  (WaBooking::handle shape)

    /** Meta's free-reply window after the person's last message. */
    public const WINDOW_HOURS = 24;

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

    /**
     * The Page's messages come from the same Meta app as WhatsApp, so when no
     * Facebook-specific secret is saved the WhatsApp app secret is the one
     * Meta signs with (same rule as includes/messenger.php).
     */
    public static function appSecret(): string
    {
        $own = trim(Settings::getString('fb_app_secret', ''));
        return $own !== '' ? $own : trim(Settings::getString('whatsapp_app_secret', ''));
    }

    /**
     * What the office pasted → the bare token, or '' when it holds none.
     * Copies from a notes file come with a label ("Page token: EAA…"),
     * quotes, a line break or spaces in the middle; a Page token is one
     * "EAA…" run of letters and digits, so that run is taken when present.
     * Anything else (an app secret) only loses its whitespace and quotes.
     */
    public static function cleanToken(string $pasted): string
    {
        $flat = preg_replace('/[\s"\'`]+/u', '', $pasted) ?? '';
        if (preg_match('/EAA[A-Za-z0-9]{30,}/', $flat, $m) === 1) {
            return $m[0];
        }
        return preg_match('/^[A-Za-z0-9_\-|.]{16,1000}$/', $flat) === 1 ? $flat : '';
    }

    /** True when appSecret() is borrowing the WhatsApp app's secret. */
    public static function secretFromWhatsApp(): bool
    {
        return trim(Settings::getString('fb_app_secret', '')) === ''
            && trim(Settings::getString('whatsapp_app_secret', '')) !== '';
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
     * @return list<array{psid: string, page: string, text: string, payload: string, mid: string, attachment: bool, kind: string}>
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
                        'kind'       => !empty($msg['attachments']) ? 'attachment' : 'message',
                    ];
                } elseif (isset($m['postback']) && is_array($m['postback'])) {
                    $out[] = [
                        'psid'       => $psid,
                        'page'       => $page,
                        'text'       => trim((string) ($m['postback']['title'] ?? '')),
                        'payload'    => (string) ($m['postback']['payload'] ?? ''),
                        'mid'        => (string) ($m['postback']['mid'] ?? ('pb-' . ($m['timestamp'] ?? ''))),
                        'attachment' => false,
                        'kind'       => 'postback',
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

        // Written down first (a re-delivered message id is stored once), so the
        // office sees it even when the rate limit below keeps the bot quiet.
        // Best-effort: the log can never cost the person their answer.
        try {
            self::logIn($psid, $ev);
        } catch (Throwable $e) {
            Logger::warning('Facebook conversation log skipped: ' . $e->getMessage(), ['psid' => self::tag($psid)], 'facebook');
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
        // A tapped booking answer ("shg:2", "shg:ho") says exactly what the button meant - not its title.
        if (str_starts_with($payload, 'shg:') && trim(substr($payload, 4)) !== '') {
            $text = trim(substr($payload, 4));
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
                // They asked to book before the number was proven: the order starts now.
                if (self::formOn() && (int) ($st['book_after_proof'] ?? 0) >= time() - 1800) {
                    unset($st['book_after_proof']);
                    self::save($psid, $st);
                    $r = self::book(self::intlOf($st), 'ticket', (string) ($st['country'] ?? ''));
                    if ($r !== null) {
                        self::sendBooking($psid, $r);
                    }
                }
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

        // 19 Nov 2026 (WA-3): the guided booking - the same engine as WhatsApp (fb_msgr_form_on).
        if (self::bookingTurn($psid, $st, $text)) {
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
            // With the country code, as the code was sent: a +977 number must never go out as +91.
            'intl'         => $phone !== '' ? (((string) ($st['country'] ?? '') === 'NP' ? '977' : '91') . $phone) : '',
            'channel'      => 'web',
            'via'          => 'facebook',
            'userId'       => 0,
            'stageKey'     => 'fb-' . $psid,
            'verified'     => $phone !== '',
            // 16 Nov 2026: lets a help desk ticket be opened without a proven
            // number (AiHandoff::open, source 'facebook').
            'psid'         => $psid,
        ];
        $answer = self::ask($ctx, $text);
        if ($answer === null || trim((string) ($answer['text'] ?? '')) === '') {
            self::sendText($psid, self::fallback());
            return;
        }
        self::reply($psid, $answer);
    }

    /* =================================================================
     *  The guided booking (19 Nov 2026, WA-3 - fb_msgr_form_on)
     * ================================================================= */

    /**
     * One turn of the WhatsApp booking conversation, here. true = answered.
     * Unproven person + "ticket" → asked for the mobile first (the ticket goes
     * to that WhatsApp). Proven → WaBooking answers whatever is a booking line
     * or an answer to its own question; anything else (null) goes on to the
     * assistant as before.
     *
     * @param array<string, mixed> $st
     */
    /**
     * The guided booking runs only when BOTH switches are on (19 Nov 2026
     * review): fb_msgr_form_on (the guided conversation) AND fb_msgr_sell (the
     * owner's "Messenger may issue tickets"). The form switch alone never
     * overrides a "no selling here" decision.
     */
    public static function formOn(): bool
    {
        return Settings::getBool('fb_msgr_form_on', false) && Settings::getBool('fb_msgr_sell', false);
    }

    private static function bookingTurn(string $psid, array $st, string $text): bool
    {
        if (!self::formOn() || trim($text) === '') {
            return false;
        }
        if ((string) ($st['phone'] ?? '') === '') {
            if (!self::isBookWord($text)) {
                return false;
            }
            $st['book_after_proof'] = time();
            self::save($psid, $st);
            self::sendText($psid, "🎫 टिकट बुक गर्न पहिले आफ्नो मोबाइल नम्बर यहीँ पठाउनुहोस् (नेपालको भए +977 सहित)। त्यो नम्बरको WhatsApp मा कोड आउँछ, कोड यहाँ लेखेपछि बुकिङ सुरु हुन्छ — टिकट पनि त्यही WhatsApp मा जान्छ।\n"
                . "To book, send your mobile number here first (+977 for Nepal). A code comes on its WhatsApp; type it here and the booking starts — the ticket goes to that WhatsApp.");
            return true;
        }
        $r = self::book(self::intlOf($st), $text, (string) ($st['country'] ?? ''));
        if ($r === null || trim((string) ($r['text'] ?? '')) === '') {
            return false;
        }
        self::sendBooking($psid, $r);
        return true;
    }

    /** "ticket", "book", "टिकट"… - somebody asking to book (before the number is proven). */
    public static function isBookWord(string $text): bool
    {
        $t = mb_strtolower(trim((string) preg_replace('/[\s.!🎫]+/u', ' ', $text)));
        return $t !== '' && mb_strlen($t) <= 40
            && preg_match('/^(?:(?:book|booking|new|order)\s*)?(?:ticket|tickets|tiket|tikat|book|booking|टिकट|बुक|ટિકિટ)(?:\s+(?:book|booking|chahiyo|chaiyo|chahiye|katnu|katne|kat|garnu|garne|karo|बुक|चाहियो|चाहिए|काट्ने|काट्नु|गर्ने|गर्नु))*$/u', $t) === 1;
    }

    /** The proven number with its country code (+977 kept: never sent as +91). */
    private static function intlOf(array $st): string
    {
        $phone = (string) ($st['phone'] ?? '');
        return $phone !== '' ? (((string) ($st['country'] ?? '') === 'NP' ? '977' : '91') . $phone) : '';
    }

    /**
     * WaBooking::handle() for this proven number (or the test seam).
     *
     * @return array{text: string, media?: ?string, ui?: ?array}|null
     */
    private static function book(string $intl, string $text, string $country): ?array
    {
        if ($intl === '') {
            return null;
        }
        $country = $country === 'NP' ? 'NP' : 'IN';
        try {
            if (self::$booker !== null) {
                return (self::$booker)($intl, $text, $country);
            }
            require_once __DIR__ . '/ticketbot.php';
            require_once __DIR__ . '/quickticket.php';
            require_once __DIR__ . '/wabooking.php';
            // A sale made here says so on its channel note (not "the WhatsApp assistant").
            $note = WaBooking::$saleNote;
            WaBooking::$saleNote = 'sold by the Facebook Messenger assistant';
            try {
                return WaBooking::handle($intl, $text, $country, null);
            } finally {
                WaBooking::$saleNote = $note;
            }
        } catch (Throwable $e) {
            Logger::error('Facebook booking turn failed: ' . $e->getMessage(), [], 'facebook');
            return null;
        }
    }

    /**
     * A booking answer on Messenger: the picture (ticket / pay QR / seat map)
     * first, then the words, the last part carrying the tappable answers as
     * quick replies - so the buttons sit under the question.
     *
     * @param array{text: string, media?: ?string, ui?: ?array} $r
     */
    public static function sendBooking(string $psid, array $r): void
    {
        $media = trim((string) ($r['media'] ?? ''));
        if ($media !== '') {
            $url = self::absolute($media);
            if (!self::sendImage($psid, $url)) {
                self::sendText($psid, "🔗 " . $url);
            }
        }
        require_once __DIR__ . '/messenger.php';
        $quick = Messenger::quickReplies(is_array($r['ui'] ?? null) ? $r['ui'] : null);
        $parts = self::chunks(self::plain(str_replace('*', '', (string) ($r['text'] ?? ''))));
        $last  = count($parts) - 1;
        foreach ($parts as $i => $part) {
            if ($i === $last && $quick !== []) {
                self::send($psid, ['text' => mb_substr($part, 0, 2000), 'quick_replies' => $quick]);
            } else {
                self::sendText($psid, $part);
            }
        }
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

    /** $purpose / $adminId only label the logged row: bot_reply (automatic) or inbox_reply (a person). */
    public static function sendText(string $psid, string $text, string $purpose = 'bot_reply', int $adminId = 0): bool
    {
        $text = trim($text);
        return $text !== '' && self::send($psid, ['text' => mb_substr($text, 0, 2000)], $purpose, $adminId);
    }

    public static function sendImage(string $psid, string $url, string $purpose = 'bot_reply', int $adminId = 0): bool
    {
        return self::send($psid, ['attachment' => ['type' => 'image', 'payload' => ['url' => $url, 'is_reusable' => true]]], $purpose, $adminId);
    }

    /** "…typing" while the assistant thinks. */
    public static function typing(string $psid): void
    {
        self::graph('POST', 'me/messages', ['recipient' => ['id' => $psid], 'sender_action' => 'typing_on']);
    }

    private static function send(string $psid, array $message, string $purpose = 'bot_reply', int $adminId = 0): bool
    {
        $r = self::graph('POST', 'me/messages', [
            'recipient'      => ['id' => $psid],
            'messaging_type' => 'RESPONSE',
            'message'        => $message,
        ]);
        $err = '';
        if (!$r['ok']) {
            $err = (string) (json_decode((string) $r['body'], true)['error']['message'] ?? '');
            Logger::warning('Facebook send failed (HTTP ' . $r['http'] . ')', ['psid' => self::tag($psid), 'error' => mb_substr($err, 0, 200)], 'facebook');
            if ($err === '') {
                $err = 'HTTP ' . $r['http'];
            }
        }
        self::logOut($psid, $message, $r['ok'], $purpose, $adminId, $err);
        return $r['ok'];
    }

    /* =================================================================
     *  The conversation log and the office inbox (16 Nov 2026)
     *  fb_inbound — admin/messenger-inbox.php reads it, replies through
     *  inboxReply(). Every write is best-effort: a log that cannot be
     *  written never stops an answer.
     * ================================================================= */

    /** fb_inbound is installed (database/upgrade-2026-11-16-fb-inbound.sql). */
    public static function logReady(bool $recheck = false): bool
    {
        static $has = null;
        if (self::$memLog !== null) {
            return true;
        }
        if ($has === null || $recheck) {
            try {
                $has = Database::fetch("SHOW TABLES LIKE 'fb_inbound'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /** A Page-scoped id as Meta sends it (digits); nothing else ever reaches a query or a send. */
    public static function validPsid(string $psid): bool
    {
        return preg_match('/^[0-9A-Za-z_\-]{1,40}$/', $psid) === 1;
    }

    /** What is kept of the person's words: a typed login code, a card number or a password never is. */
    public static function forLog(string $text): string
    {
        $text = trim($text);
        if (self::codeFrom($text) !== null) {
            return '[code hidden]';
        }
        require_once __DIR__ . '/aihandoff.php';
        return mb_substr(AiHandoff::redact($text), 0, 2000);
    }

    /** True while Meta lets the Page answer freely: the person wrote in the last 24 hours. */
    public static function windowOpen(string $psid): bool
    {
        if (!self::validPsid($psid)) {
            return false;
        }
        if (self::$memLog !== null) {
            foreach (array_reverse(self::$memLog) as $r) {
                if ($r['psid'] === $psid && $r['direction'] === 'in') {
                    return (int) strtotime((string) $r['created_at']) >= time() - self::WINDOW_HOURS * 3600;
                }
            }
            return false;
        }
        if (!self::logReady()) {
            return false;
        }
        try {
            // On the database clock: created_at is a MySQL default.
            return Database::exists(
                "SELECT 1 FROM fb_inbound WHERE psid = :p AND direction = 'in'
                    AND created_at >= NOW() - INTERVAL " . self::WINDOW_HOURS . ' HOUR',
                ['p' => $psid]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * One row per person who wrote or was written to in the last $days.
     *
     * @return list<array{psid: string, last_at: string, msgs: int, last_text: string, unread: int, open: bool, phone: string}>
     */
    public static function threads(int $adminId, int $days = 7, int $limit = 80): array
    {
        if (!self::logReady() || self::$memLog !== null) {
            return [];
        }
        $rows = Database::fetchAll(
            "SELECT psid, COUNT(*) AS msgs, MAX(id) AS last_id, MAX(created_at) AS last_at,
                    SUM(direction = 'in' AND id > :seen) AS unread,
                    MAX(direction = 'in' AND created_at >= NOW() - INTERVAL " . self::WINDOW_HOURS . " HOUR) AS open_now
               FROM fb_inbound
              WHERE created_at >= NOW() - INTERVAL :d DAY
              GROUP BY psid ORDER BY last_at DESC LIMIT " . max(1, min(300, $limit)),
            ['seen' => self::seenId($adminId), 'd' => max(1, $days)]
        );
        if ($rows === []) {
            return [];
        }
        $lastIds = array_map(static fn(array $r): int => (int) $r['last_id'], $rows);
        $texts = [];
        foreach (Database::fetchAll('SELECT id, direction, body FROM fb_inbound WHERE id IN (' . implode(',', $lastIds) . ')') as $m) {
            $texts[(int) $m['id']] = ((string) $m['direction'] === 'out' ? '↩ ' : '') . (string) ($m['body'] ?? '');
        }
        $phones = self::provenPhones(array_map(static fn(array $r): string => (string) $r['psid'], $rows));
        $out = [];
        foreach ($rows as $r) {
            $p = (string) $r['psid'];
            $out[] = [
                'psid'      => $p,
                'last_at'   => (string) $r['last_at'],
                'msgs'      => (int) $r['msgs'],
                'last_text' => $texts[(int) $r['last_id']] ?? '',
                'unread'    => (int) $r['unread'],
                'open'      => (int) ($r['open_now'] ?? 0) === 1,
                'phone'     => $phones[$p] ?? '',
            ];
        }
        return $out;
    }

    /**
     * One person's conversation, oldest first.
     *
     * @return list<array{dir: string, at: string, text: string, kind: string, status: string, purpose: string, by: string}>
     */
    public static function thread(string $psid, int $days = 7): array
    {
        if (!self::validPsid($psid) || !self::logReady() || self::$memLog !== null) {
            return [];
        }
        $rows = Database::fetchAll(
            'SELECT f.direction, f.kind, f.body, f.purpose, f.status, f.error, f.created_at, a.full_name
               FROM fb_inbound f LEFT JOIN admins a ON a.id = f.admin_id
              WHERE f.psid = :p AND f.created_at >= NOW() - INTERVAL :n DAY
              ORDER BY f.id DESC LIMIT 200',
            ['p' => $psid, 'n' => max(1, $days)]
        );
        $out = [];
        foreach (array_reverse($rows) as $m) {
            $out[] = [
                'dir'     => (string) $m['direction'] === 'out' ? 'out' : 'in',
                'at'      => (string) $m['created_at'],
                'text'    => (string) ($m['body'] ?? ''),
                'kind'    => (string) $m['kind'],
                'status'  => (string) $m['status'] . ((string) ($m['error'] ?? '') !== '' ? ' — ' . (string) $m['error'] : ''),
                'purpose' => (string) ($m['purpose'] ?? ''),
                'by'      => (string) ($m['full_name'] ?? ''),
            ];
        }
        return $out;
    }

    /** The number this Messenger account proved with the WhatsApp code ('' = none), as international digits. */
    public static function provenPhone(string $psid): string
    {
        return self::provenPhones([$psid])[$psid] ?? '';
    }

    /** New messages for this admin since they last opened the inbox (the nav count). */
    public static function unread(int $adminId): int
    {
        if (!self::logReady() || self::$memLog !== null) {
            return 0;
        }
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM fb_inbound WHERE direction = 'in' AND id > :s AND created_at >= NOW() - INTERVAL 7 DAY",
                ['s' => self::seenId($adminId)], 0
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function markSeen(int $adminId): void
    {
        if (!self::logReady() || self::$memLog !== null) {
            return;
        }
        try {
            $max = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM fb_inbound', [], 0);
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('fb_inbox_seen', :k, :v, 'inbox')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)",
                ['k' => (string) $adminId, 'v' => (string) $max]
            );
        } catch (Throwable $e) {
        }
    }

    /**
     * A person from the office answers. Free text only while the 24-hour
     * window from the person's last message is open (Meta's rule); outside
     * it nothing is sent — no message tag, no paid route. Audited either way.
     *
     * @return array{ok: bool, why: string}
     */
    public static function inboxReply(string $psid, string $text, int $adminId): array
    {
        $text = trim($text);
        if (!self::validPsid($psid) || $text === '') {
            return ['ok' => false, 'why' => 'पहिले सन्देश लेख्नुहोस् · Write a message first.'];
        }
        if (mb_strlen($text) > 1000) {
            return ['ok' => false, 'why' => '१००० अक्षरभित्र लेख्नुहोस् · Keep it under 1000 characters.'];
        }
        if (self::token() === '' || (self::$transport === null && !function_exists('curl_init'))) {
            return ['ok' => false, 'why' => 'Messenger जोडिएको छैन · No Page token is saved — see Facebook Messenger AI.'];
        }
        if (!self::windowOpen($psid)) {
            return ['ok' => false, 'why' => '२४ घण्टाको झ्याल बन्द छ · Their 24-hour Messenger window is closed (they have not written in the last day). Wait until they write again.'];
        }
        $ok = self::sendText($psid, $text, 'inbox_reply', $adminId);
        Logger::audit('fb.inbox_reply', 'facebook', self::tag($psid), null, ['chars' => mb_strlen($text), 'ok' => $ok],
            'Messenger inbox reply by admin #' . $adminId);
        return $ok
            ? ['ok' => true, 'why' => '']
            : ['ok' => false, 'why' => 'Facebook ले लिएन · Facebook did not accept it — the reason is shown in the conversation.'];
    }

    private static function logIn(string $psid, array $ev): void
    {
        $text    = trim((string) ($ev['text'] ?? ''));
        $payload = (string) ($ev['payload'] ?? '');
        $kind    = (string) ($ev['kind'] ?? (!empty($ev['attachment']) ? 'attachment' : 'message'));
        if ($text === '' && $payload !== '') {
            $text = '[' . mb_substr($payload, 0, 60) . ']';
        } elseif ($text === '' && !empty($ev['attachment'])) {
            $text = '[photo / file]';
        }
        $mid = (string) ($ev['mid'] ?? '');
        self::logRow([
            'mid'       => $mid !== '' ? mb_substr($mid, 0, 191) : null,
            'psid'      => $psid,
            'direction' => 'in',
            'kind'      => in_array($kind, ['message', 'postback', 'attachment'], true) ? $kind : 'message',
            'body'      => self::forLog($text),
            'purpose'   => null,
            'status'    => 'received',
            'admin_id'  => null,
            'error'     => null,
        ]);
    }

    private static function logOut(string $psid, array $message, bool $ok, string $purpose, int $adminId, string $err): void
    {
        if (isset($message['text'])) {
            $kind = 'text';
            $body = (string) $message['text'];
        } elseif (($message['attachment']['type'] ?? '') === 'image') {
            $kind = 'image';
            $body = '[image] ' . (string) ($message['attachment']['payload']['url'] ?? '');
        } else {
            $kind   = 'buttons';
            $titles = array_map(static fn($b): string => (string) ($b['title'] ?? ''), (array) ($message['attachment']['payload']['buttons'] ?? []));
            $body   = '[buttons] ' . implode(' · ', array_filter($titles, static fn(string $t): bool => $t !== ''));
        }
        // The same keys Notify keeps out of message_logs (2F-logs, 15 Oct 2026):
        // a ticket's download key / short code and a one-time link token each
        // open something, so the inbox never stores or shows them.
        try {
            require_once __DIR__ . '/wasend.php';
            $body = WaSend::maskSecrets($body);
        } catch (Throwable $ignored) {
        }
        $body = preg_replace('~([?&]t=)[A-Za-z0-9]{16,}~', '$1[hidden]', $body) ?? $body;
        $body = preg_replace('~([?&]k=)[A-Za-z0-9]{8,}~', '$1[hidden]', $body) ?? $body;
        $body = preg_replace('~(view\.php\?code=)[A-Za-z0-9]{6,}~', '$1[hidden]', $body) ?? $body;
        self::logRow([
            'mid'       => null,
            'psid'      => $psid,
            'direction' => 'out',
            'kind'      => $kind,
            'body'      => mb_substr($body, 0, 2000),
            'purpose'   => preg_match('/^[a-z_]{1,40}$/', $purpose) === 1 ? $purpose : 'bot_reply',
            'status'    => $ok ? 'sent' : 'failed',
            'admin_id'  => $adminId > 0 ? $adminId : null,
            'error'     => $ok ? null : mb_substr($err, 0, 255),
        ]);
    }

    private static function logRow(array $row): void
    {
        if (self::$memLog !== null) {
            if ($row['mid'] !== null) {
                foreach (self::$memLog as $r) {
                    if (($r['mid'] ?? null) === $row['mid']) {
                        return;                                // INSERT IGNORE on the UNIQUE mid
                    }
                }
            }
            $row['id']         = count(self::$memLog) + 1;
            $row['created_at'] = date('Y-m-d H:i:s');
            self::$memLog[]    = $row;
            return;
        }
        try {
            if (!self::logReady()) {
                return;
            }
            Database::run(
                'INSERT IGNORE INTO fb_inbound (mid, psid, direction, kind, body, purpose, status, admin_id, error)
                 VALUES (:mid, :psid, :dir, :kind, :body, :purpose, :status, :admin, :err)',
                [
                    'mid' => $row['mid'], 'psid' => $row['psid'], 'dir' => $row['direction'], 'kind' => $row['kind'],
                    'body' => $row['body'], 'purpose' => $row['purpose'], 'status' => $row['status'],
                    'admin' => $row['admin_id'], 'err' => $row['error'],
                ]
            );
        } catch (Throwable $e) {
            try {
                Logger::warning('Facebook conversation log not written: ' . $e->getMessage(), ['psid' => self::tag((string) $row['psid'])], 'facebook');
            } catch (Throwable $ignored) {
            }
        }
    }

    private static function seenId(int $adminId): int
    {
        try {
            return (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'fb_inbox_seen' AND kkey = :k", ['k' => (string) $adminId], 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** @param list<string> $psids @return array<string, string> psid => international digits */
    private static function provenPhones(array $psids): array
    {
        $psids = array_values(array_filter(array_unique($psids), [self::class, 'validPsid']));
        if ($psids === []) {
            return [];
        }
        $out = [];
        try {
            $ph = implode(',', array_fill(0, count($psids), '?'));
            foreach (Database::fetchAll(
                'SELECT kkey, kvalue FROM kv_store WHERE kscope = ? AND kkey IN (' . $ph . ')',
                array_merge([self::SCOPE], $psids)
            ) as $r) {
                $st = json_decode((string) $r['kvalue'], true);
                $phone = is_array($st) ? (string) ($st['phone'] ?? '') : '';
                if ($phone !== '') {
                    $out[(string) $r['kkey']] = (((string) ($st['country'] ?? '') === 'NP') ? '977' : '91') . $phone;
                }
            }
        } catch (Throwable $e) {
        }
        return $out;
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
