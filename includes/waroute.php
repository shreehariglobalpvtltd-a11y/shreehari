<?php
/**
 * =====================================================================
 *  WaRoute — which WhatsApp sender carries a message (9 Oct 2026).
 *
 *  Owner: "sabai kaam jaha badi jaso Meta ko direct free API use garos;
 *  ticket ko bela matra AiSensy; AiSensy ma utility template matra".
 *
 *  Two senders exist on this site:
 *    - the META number (+91 87358 81507, whatsapp/api.php): the bot's own
 *      number. A customer who wrote to it in the last 24 h can be answered
 *      with ANY text / picture for free (Meta's service window). Templates
 *      to a cold number are refused there (131042, payment method).
 *    - the AISENSY number (+91 91570 01507, whatsapp/aisensy.php): approved
 *      UTILITY templates reach any number, paid per message from the
 *      wallet. It has no inbound webhook, so it never knows a window.
 *
 *  The rule, read from Settings (`wa_policy`):
 *    meta_first   (default) 1. the Meta number, free, when OUR record of
 *                             this number's last inbound is inside 24 h;
 *                           2. else the AiSensy utility template;
 *                           3. a Meta template only while wa_meta_templates_on
 *                             (the owner's "naya number aayepachi" switch).
 *    aisensy_only           exactly the behaviour before 9 Oct 2026.
 *    meta_only              never AiSensy (for the day Meta billing works).
 *
 *  Why our own window record and not a blind try: Meta ACCEPTS a free
 *  text to a closed window synchronously and fails it a minute later with
 *  131047 — the 3 Oct lesson. whatsapp/webhook.php records every inbound
 *  in kv_store (scope wa_inbound, the Meta number's own window), so a
 *  "window open" here is something we saw, not a guess. A ticket that
 *  still fails async is caught by the webhook (WaQueue::onMetaFailed) and
 *  re-sent on the AiSensy template.
 *
 *  Every method here is static, never throws, and returns the same shape
 *  the senders return: ['success', 'message_id', 'error', 'how'].
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaRoute
{
    public const META_FIRST   = 'meta_first';
    public const AISENSY_ONLY = 'aisensy_only';
    public const META_ONLY    = 'meta_only';

    /** Meta's window is 24 h from the LAST inbound; keep a safety margin. */
    public const WINDOW_SEC = 23 * 3600 + 30 * 60;

    /** Tests replace the Meta transport: fn(string $to, string $text, ?string $image): array */
    public static $transport = null;

    /* -----------------------------------------------------------------
     *  Policy
     * ----------------------------------------------------------------- */

    public static function policy(): string
    {
        $p = strtolower(trim(Settings::getString('wa_policy', self::META_FIRST)));
        return in_array($p, [self::META_FIRST, self::AISENSY_ONLY, self::META_ONLY], true) ? $p : self::META_FIRST;
    }

    /** The Meta number may be used for free service replies. */
    public static function metaOn(): bool
    {
        return self::policy() !== self::AISENSY_ONLY && self::metaReady();
    }

    /** AiSensy may be used (templates to cold numbers). */
    public static function aisensyOn(): bool
    {
        return self::policy() !== self::META_ONLY;
    }

    /** "Naya number aayepachi": templates go on Meta first. */
    public static function metaTemplatesOn(): bool
    {
        return Settings::getBool('wa_meta_templates_on', false) && self::metaReady();
    }

    /** AiSensy spends only on UTILITY / AUTHENTICATION templates. */
    public static function utilityOnly(): bool
    {
        return Settings::getBool('aisensy_utility_only', true);
    }

    /** Token + phone number id present (env or settings). */
    public static function metaReady(): bool
    {
        if (self::$transport !== null) {
            return true;
        }
        try {
            if (!defined('META_ACCESS_TOKEN')) {
                require_once ROOT_PATH . '/whatsapp/config.php';
            }
            return META_ACCESS_TOKEN !== '' && META_PHONE_NUMBER_ID !== '';
        } catch (Throwable $e) {
            return false;
        }
    }

    /* -----------------------------------------------------------------
     *  The Meta number's own 24 h window
     * ----------------------------------------------------------------- */

    /** International digits as the webhook records them (91…, 977…). */
    public static function intl(string $phone): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10) {
            $d = (preg_replace('/\D/', '', Settings::getString('whatsapp_default_country', '91')) ?: '91') . $d;
        }
        return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : '';
    }

    /** Seconds the window still has, 0 when closed / never seen. */
    public static function metaWindowLeft(string $phone): int
    {
        $intl = self::intl($phone);
        if ($intl === '') {
            return 0;
        }
        try {
            $at = (int) Database::scalar(
                "SELECT kvalue FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k",
                ['k' => $intl], 0
            );
        } catch (Throwable $e) {
            return 0;
        }
        if ($at <= 0) {
            return 0;
        }
        return max(0, $at + self::WINDOW_SEC - time());
    }

    public static function metaWindowOpen(string $phone): bool
    {
        return self::metaWindowLeft($phone) > 0;
    }

    /** Numbers whose Meta window is open right now (for the admin card). */
    public static function openWindows(): int
    {
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM kv_store WHERE kscope = 'wa_inbound' AND CAST(kvalue AS UNSIGNED) >= :t",
                ['t' => time() - self::WINDOW_SEC], 0
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /* -----------------------------------------------------------------
     *  Free first
     * ----------------------------------------------------------------- */

    /**
     * Send this message on the Meta number for free, IF the policy allows
     * it and the number's window is open. Returns null when not attempted
     * (policy, no window, no credentials) so the caller carries on with the
     * paid template; a refused attempt also returns null (the caller's
     * template is the recovery), but it is logged.
     *
     * @param string      $purpose message_logs.purpose (ticket, otp, trip_delay, agent_statement …)
     * @param array       $meta    bookingId, name, label — logged, never sent
     * @return array{success: bool, message_id: string, error: string, how: string}|null
     */
    public static function freeFirst(string $phone, string $text, ?string $mediaUrl, string $purpose, array $meta = []): ?array
    {
        if (!self::metaOn()) {
            return null;
        }
        $to   = self::intl($phone);
        $text = trim($text);
        if ($to === '' || $text === '' || !self::metaWindowOpen($to)) {
            return null;
        }
        $media = trim((string) $mediaUrl);
        // A picture: ticket / seat map / QR / chart links, or a plain image file. (Meta itself insists on https.)
        $isImg = $media !== '' && preg_match('#^https?://#i', $media) === 1
              && preg_match('#\.(png|jpe?g|webp)(\?|$)|[?&]img=1|ticket|seatmap|pay-image|report-chart|offer-card#i', $media) === 1
              && preg_match('#\.pdf(\?|$)|[?&]f=[^&]*\.pdf#i', $media) !== 1;

        try {
            $r = self::sendMeta($to, $text, $isImg ? $media : null, $media !== '' && !$isImg ? $media : null);
        } catch (Throwable $e) {
            Logger::warning('WaRoute meta free threw: ' . $e->getMessage(), ['to' => $to, 'purpose' => $purpose], 'whatsapp');
            return null;
        }
        $out = [
            'success'    => (bool) ($r['success'] ?? false),
            'message_id' => (string) ($r['message_id'] ?? ''),
            'error'      => (string) ($r['error'] ?? ''),
            'how'        => 'meta free (24 h window)',
        ];
        if (class_exists('Notify')) {
            try {
                // A one-time code is never kept at rest (the Nepali half of the OTP line has no "code" word to mask on).
                $logBody = $purpose === 'otp' ? (preg_replace('/\d{4,8}/u', '••••', $text) ?? $text) : $text;
                Notify::logOutbound($to, $logBody, $out['success'] ? 'sent' : 'failed', [
                    'provider'  => 'cloud_api',
                    'bookingId' => isset($meta['bookingId']) && (int) $meta['bookingId'] > 0 ? (int) $meta['bookingId'] : null,
                    'sid'       => $out['message_id'],
                    'purpose'   => $purpose !== '' ? $purpose : 'message',
                    'error'     => $out['success'] ? null : mb_substr('Meta free: ' . $out['error'], 0, 250),
                    'media_url' => $media !== '' ? $media : null,
                ] + array_intersect_key($meta, ['admin_id' => 1, 'agent_admin_id' => 1]));
            } catch (Throwable $ignored) {
            }
        }
        if (!$out['success']) {
            return null;      // the paid template is the recovery
        }
        self::count('free');
        return $out;
    }

    /**
     * A template on the META number (only while wa_meta_templates_on): the
     * owner's "new number / billing fixed" switch. Null when not attempted.
     *
     * @param list<string>|array<string,string> $vars  {{1}}..{{n}}
     */
    public static function metaTemplate(string $phone, string $name, string $lang, array $vars, ?string $headerImage, string $purpose, array $meta = []): ?array
    {
        if (!self::metaTemplatesOn() || trim($name) === '') {
            return null;
        }
        /* 15 Oct 2026 (2D-paid-limit): a Meta template is a PAID message too.
           Past the office's daily cap it is not attempted; the caller carries
           on to its own fallback (the "hi" queue, click-to-chat). */
        $cap = (self::isLoginTemplate($name) || in_array(strtolower($purpose), ['otp', 'login', 'auth'], true)) ? '' : self::paidRefusal();
        if ($cap !== '') {
            Logger::info('WaRoute: Meta template not sent - ' . $cap, ['template' => $name, 'purpose' => $purpose], 'whatsapp');
            return null;
        }
        $to = self::intl($phone);
        if ($to === '') {
            return null;
        }
        try {
            if (self::$transport !== null) {
                $r = (self::$transport)($to, 'template:' . $name, $headerImage);
            } else {
                require_once ROOT_PATH . '/whatsapp/api.php';
                $r = sendWhatsAppTemplate($to, $name, $lang !== '' ? $lang : 'en', $vars, $headerImage !== null && $headerImage !== '' ? ['image' => $headerImage] : []);
            }
        } catch (Throwable $e) {
            return null;
        }
        $out = [
            'success'    => (bool) ($r['success'] ?? false),
            'message_id' => (string) ($r['message_id'] ?? ''),
            'error'      => (string) ($r['error'] ?? ''),
            'how'        => 'meta template ' . $name,
        ];
        if (class_exists('Notify')) {
            try {
                Notify::logOutbound($to, 'Template ' . $name . ' (Meta)', $out['success'] ? 'sent' : 'failed', [
                    'provider'  => 'cloud_api',
                    'bookingId' => isset($meta['bookingId']) && (int) $meta['bookingId'] > 0 ? (int) $meta['bookingId'] : null,
                    'sid'       => $out['message_id'],
                    'purpose'   => $purpose !== '' ? $purpose : 'message',
                    'error'     => $out['success'] ? null : mb_substr('Meta template: ' . $out['error'], 0, 250),
                ]);
            } catch (Throwable $ignored) {
            }
        }
        if ($out['success']) {
            self::count('meta_tpl');
        }
        return $out['success'] ? $out : null;
    }

    /**
     * May this AiSensy template be sent automatically? Marketing templates
     * cost ~6x a utility one; with aisensy_utility_only they are refused
     * unless the caller (the Campaigns engine, the owner's own decision)
     * says so. Returns '' when allowed, else the reason.
     */
    /**
     * A sign-in code template (AUTHENTICATION category, or a login / OTP
     * name) - never held back by the daily paid cap (15 Oct 2026).
     */
    public static function isLoginTemplate(string $templateName): bool
    {
        $n = strtolower($templateName);
        if (str_contains($n, 'login') || str_contains($n, 'otp')) {
            return true;
        }
        try {
            if (!class_exists('MsgLedger') && is_file(INCLUDE_PATH . '/msgledger.php')) {
                require_once INCLUDE_PATH . '/msgledger.php';
            }
            return class_exists('MsgLedger') && MsgLedger::templateCategory($templateName) === 'authentication';
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function aisensyRefusal(string $templateName, bool $explicitMarketing = false): string
    {
        if (!self::aisensyOn()) {
            return 'wa_policy is meta_only - AiSensy is switched off';
        }
        // 15 Oct 2026: the office's daily cap on paid messages - except a
        // login code: a customer outside the 24 h window could not sign in.
        $cap = self::isLoginTemplate($templateName) ? '' : self::paidRefusal();
        if ($cap !== '') {
            return $cap;
        }
        if ($explicitMarketing || !self::utilityOnly()) {
            return '';
        }
        $cat = 'utility';
        try {
            if (!class_exists('MsgLedger') && is_file(INCLUDE_PATH . '/msgledger.php')) {
                require_once INCLUDE_PATH . '/msgledger.php';
            }
            if (class_exists('MsgLedger')) {
                $cat = MsgLedger::templateCategory($templateName);
            }
        } catch (Throwable $e) {
            $cat = 'utility';
        }
        return $cat === 'marketing'
            ? 'template ' . $templateName . ' is MARKETING - blocked by aisensy_utility_only (campaigns send these on purpose)'
            : '';
    }

    /* -----------------------------------------------------------------
     *  The paid cap and the Meta allowance (15 Oct 2026, owner: "paid
     *  template default ma hoina; kharcha ko limit rakhna milos, 0 pani")
     *
     *  wa_paid_daily_limit  ''  = no cap (exactly the behaviour before);
     *                       '0' = never pay - templates are refused and
     *                             tickets wait for the "hi" queue / the
     *                             click-to-chat hand-over;
     *                       'N' = at most N paid messages a day (AiSensy
     *                             templates + Meta templates together).
     *  Counted at the moment a paid send is ACCEPTED (kv wa_route
     *  'Y-m-d:paid' from MsgLedger::recordAiSensy, 'Y-m-d:meta_tpl' here).
     *
     *  Meta tells us per delivered message whether it was billable
     *  (statuses[].pricing.billable): whatsapp/webhook.php hands that to
     *  noteMetaBilling(), which keeps 'Y-m-d:billable' / 'Y-m-d:metafree'.
     *  wa_meta_free_allowance (per month, 0 = not tracked) is set against it.
     * ----------------------------------------------------------------- */

    /** The daily cap on paid messages, or null for none. */
    public static function paidLimit(): ?int
    {
        $raw = trim(Settings::getString('wa_paid_daily_limit', ''));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return max(0, (int) $raw);
    }

    /** Paid messages accepted today (AiSensy templates + Meta templates). */
    public static function paidToday(): int
    {
        return self::counter(date('Y-m-d') . ':paid') + self::counter(date('Y-m-d') . ':meta_tpl');
    }

    /** '' when a paid message may go now, else why not. */
    public static function paidRefusal(): string
    {
        $cap = self::paidLimit();
        if ($cap === null) {
            return '';
        }
        if ($cap === 0) {
            return 'wa_paid_daily_limit is 0 - no paid WhatsApp messages';
        }
        $used = self::paidToday();
        return $used >= $cap ? 'paid WhatsApp limit reached for today (' . $used . '/' . $cap . ', wa_paid_daily_limit)' : '';
    }

    /**
     * One Meta status with its pricing block: counted once per message
     * (first delivered / read wins), as billable or free, per day.
     */
    public static function noteMetaBilling(string $wamid, string $state, array $pricing): void
    {
        if ($wamid === '' || !in_array($state, ['delivered', 'read'], true) || !array_key_exists('billable', $pricing)) {
            return;
        }
        try {
            // Meta re-sends statuses: the same once-only guard the webhook uses for messages.
            if (!Security::rateLimit('wa_meta_bill', substr($wamid, 0, 128), 1, 172800)) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }
        self::count($pricing['billable'] === true ? 'billable' : 'metafree');
    }

    /** Free allowance per month (wa_meta_free_allowance; 0 = not tracked). */
    public static function freeAllowance(): int
    {
        return max(0, Settings::getInt('wa_meta_free_allowance', 0));
    }

    /** Month-to-date sum of one wa_route counter ('billable', 'metafree', 'paid', 'meta_tpl'). */
    public static function monthCount(string $what): int
    {
        try {
            return (int) Database::scalar(
                "SELECT COALESCE(SUM(CAST(kvalue AS UNSIGNED)), 0) FROM kv_store
                  WHERE kscope = 'wa_route' AND kkey LIKE :k",
                ['k' => date('Y-m') . '-__:' . $what], 0
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /* -----------------------------------------------------------------
     *  Figures for the admin card
     * ----------------------------------------------------------------- */

    /**
     * @return array{policy:string, metaReady:bool, windows:int, freeToday:int, free7:int,
     *               aisToday:int, ais7:int, savedToday:float, saved7:float, lastInbound:string, rate:float,
     *               metaTplToday:int, metaTpl7:int, paidToday:int, paidLimit:?int,
     *               billableMonth:int, metaFreeMonth:int, allowance:int, allowanceLeft:?int}
     */
    public static function stats(): array
    {
        $out = ['policy' => self::policy(), 'metaReady' => self::metaReady(), 'windows' => self::openWindows(),
                'freeToday' => 0, 'free7' => 0, 'aisToday' => 0, 'ais7' => 0, 'savedToday' => 0.0, 'saved7' => 0.0,
                'lastInbound' => '', 'rate' => 0.13,
                'metaTplToday' => 0, 'metaTpl7' => 0, 'paidToday' => self::paidToday(), 'paidLimit' => self::paidLimit(),
                'billableMonth' => self::monthCount('billable'), 'metaFreeMonth' => self::monthCount('metafree'),
                'allowance' => self::freeAllowance(), 'allowanceLeft' => null];
        if ($out['allowance'] > 0) {
            $out['allowanceLeft'] = max(0, $out['allowance'] - $out['billableMonth']);
        }
        try {
            if (!class_exists('MsgLedger') && is_file(INCLUDE_PATH . '/msgledger.php')) {
                require_once INCLUDE_PATH . '/msgledger.php';
            }
            if (class_exists('MsgLedger')) {
                $out['rate'] = MsgLedger::rate('IN', 'utility');
            }
            /* 15 Oct 2026: a Meta TEMPLATE (metaTemplate() logs it as "Template
               <name> (Meta)") is paid - it was counted here as free and as
               money saved. And `purpose <> 'bot_reply'` silently dropped every
               row with no purpose at all (NULL <> x is never true). */
            $rows = Database::fetchAll(
                "SELECT provider, (created_at >= CURDATE()) AS today,
                        (provider = 'cloud_api' AND COALESCE(body, '') LIKE 'Template %(Meta)') AS tpl, COUNT(*) n
                   FROM message_logs
                  WHERE channel = 'whatsapp' AND status = 'sent' AND provider IN ('cloud_api', 'aisensy')
                    AND COALESCE(purpose, '') <> 'bot_reply' AND created_at >= CURDATE() - INTERVAL 6 DAY
                  GROUP BY provider, today, tpl"
            );
            foreach ($rows as $r) {
                $n = (int) $r['n'];
                if ((int) $r['tpl'] === 1) {
                    $out['metaTpl7'] += $n;
                    if ((int) $r['today'] === 1) {
                        $out['metaTplToday'] += $n;
                    }
                } elseif ((string) $r['provider'] === 'cloud_api') {
                    $out['free7'] += $n;
                    if ((int) $r['today'] === 1) {
                        $out['freeToday'] += $n;
                    }
                } else {
                    $out['ais7'] += $n;
                    if ((int) $r['today'] === 1) {
                        $out['aisToday'] += $n;
                    }
                }
            }
            $out['savedToday'] = round($out['freeToday'] * $out['rate'], 2);
            $out['saved7']     = round($out['free7'] * $out['rate'], 2);
            $last = (int) Database::scalar("SELECT MAX(CAST(kvalue AS UNSIGNED)) FROM kv_store WHERE kscope = 'wa_inbound'", [], 0);
            $out['lastInbound'] = $last > 0 ? date('Y-m-d H:i', $last) : '';
        } catch (Throwable $e) {
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     *  Internals
     * ----------------------------------------------------------------- */

    /**
     * The actual Meta send: picture with the text as caption (≤ 1024 chars),
     * else the text (+ a link to the file when it is not a picture).
     */
    private static function sendMeta(string $to, string $text, ?string $image, ?string $link): array
    {
        $asPicture = $image !== null && mb_strlen($text) <= 1024;
        // The words with the file link(s) when they cannot ride as a caption.
        $body = $text;
        if ($image !== null && !$asPicture && !str_contains($text, $image)) {
            $body .= "\n" . $image;
        }
        if ($link !== null && !str_contains($text, $link)) {
            $body .= "\n" . $link;
        }
        if (self::$transport !== null) {
            return (self::$transport)($to, $asPicture ? $text : $body, $asPicture ? $image : null);
        }
        require_once ROOT_PATH . '/whatsapp/api.php';
        if ($asPicture) {
            $r = sendWhatsAppImage($to, $image, $text);
            if ($r['success']) {
                return $r;
            }
            // The picture was refused (URL not reachable by Meta): the words still go, with the link.
            if (!str_contains($body, $image)) {
                $body .= "\n" . $image;
            }
        }
        $r = sendWhatsAppText($to, $body);
        if ($r['success'] && $image !== null && !$asPicture) {
            sendWhatsAppImage($to, $image);
        }
        return $r;
    }

    /**
     * ok counters per day (kv scope wa_route) — never breaks a send.
     * Public since 15 Oct 2026: MsgLedger counts accepted paid AiSensy
     * templates here ('paid'), the webhook Meta's billable / free deliveries.
     */
    public static function count(string $what): void
    {
        try {
            $k = date('Y-m-d') . ':' . $what;
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_route', :k, '1', 'waroute')
                 ON DUPLICATE KEY UPDATE kvalue = CAST(kvalue AS UNSIGNED) + 1, updated_by = 'waroute'",
                ['k' => $k]
            );
        } catch (Throwable $e) {
        }
    }

    /** One wa_route counter's value ('Y-m-d:what'), 0 when unset. */
    private static function counter(string $key): int
    {
        try {
            return (int) Database::scalar(
                "SELECT CAST(kvalue AS UNSIGNED) FROM kv_store WHERE kscope = 'wa_route' AND kkey = :k",
                ['k' => $key], 0
            );
        } catch (Throwable $e) {
            return 0;
        }
    }
}
