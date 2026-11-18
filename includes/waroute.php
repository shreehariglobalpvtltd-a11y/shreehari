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

    /**
     * 19 Nov 2026 (WA-1): the last template metaTemplate() really put on the
     * wire (null = none this request): name, success, code, error. Notify
     * reads it so the Cloud API driver never sends the same template twice
     * for one message (18 Nov: every ticket called shg_ticket_image two times).
     *
     * @var array{name: string, success: bool, code: int, error: string}|null
     */
    public static ?array $lastTemplate = null;

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

    /**
     * International digits as the webhook records them (91…, 977…).
     *
     * 19 Nov 2026 (WA-1): $countryHint ('NP' / 'IN' / '977' / '91') decides a
     * bare 10-digit number. India and Nepal both use 10-digit mobiles
     * (98xxxxxxxx is valid in both), so a Nepal-stamped booking must pass its
     * hint - only a call without one falls back to whatsapp_default_country.
     */
    public static function intl(string $phone, ?string $countryHint = null): string
    {
        $d = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11 && str_starts_with($d, '0')) {
            $d = substr($d, 1);
        }
        if (strlen($d) === 10) {
            $cc = self::hintDigits($countryHint);
            if ($cc === '') {
                $cc = preg_replace('/\D/', '', Settings::getString('whatsapp_default_country', '91')) ?: '91';
            }
            $d = $cc . $d;
        }
        return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : '';
    }

    /** 'NP' / '977' / '+977' -> '977', 'IN' / '91' -> '91', anything else ''. */
    public static function hintDigits(?string $hint): string
    {
        $h = strtoupper(trim((string) $hint));
        if ($h === 'NP' || ltrim($h, '+') === '977') {
            return '977';
        }
        if ($h === 'IN' || ltrim($h, '+') === '91') {
            return '91';
        }
        return '';
    }

    /** Seconds the window still has, 0 when closed / never seen. */
    public static function metaWindowLeft(string $phone, ?string $countryHint = null): int
    {
        $intl = self::intl($phone, $countryHint);
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

    public static function metaWindowOpen(string $phone, ?string $countryHint = null): bool
    {
        return self::metaWindowLeft($phone, $countryHint) > 0;
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
        $to   = self::intl($phone, isset($meta['country']) ? (string) $meta['country'] : null);
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
        $to = self::intl($phone, isset($meta['country']) ? (string) $meta['country'] : null);
        if ($to === '') {
            return null;
        }
        /* 19 Nov 2026 (WA-2): $meta['header'] = ['document' => pdf] / ['image' => png]
           (WaTplMeta::forPurpose) wins over $headerImage; $meta['buttons'] carries a
           URL button's value (pay.php?pnr=…) or a FLOW button's token. */
        $hdr = [];
        if (isset($meta['header']) && is_array($meta['header']) && $meta['header'] !== []) {
            $hdr = $meta['header'];
        } elseif ($headerImage !== null && $headerImage !== '') {
            $hdr = ['image' => $headerImage];
        }
        $buttons = isset($meta['buttons']) && is_array($meta['buttons']) ? $meta['buttons'] : [];
        try {
            if (self::$transport !== null) {
                $r = (self::$transport)($to, 'template:' . $name, $hdr['image'] ?? ($hdr['document'] ?? $headerImage));
            } else {
                require_once ROOT_PATH . '/whatsapp/api.php';
                $r = sendWhatsAppTemplate($to, $name, $lang !== '' ? $lang : 'en', $vars, $hdr, null, $buttons);
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
        $code = (int) ($r['code'] ?? 0);
        if ($code === 0 && preg_match('/\b(13\d{4})\b/', $out['error'], $cm) === 1) {
            $code = (int) $cm[1];
        }
        self::$lastTemplate = ['name' => $name, 'success' => $out['success'], 'code' => $code, 'error' => $out['error']];
        /* Meta refused the TEMPLATE (132001 "does not exist in en" right after
           approval, paused, ...): the registry skips it for a few hours, so the
           next ticket tries the duplicate (shg_ticket_v2) instead. */
        if (!$out['success'] && $code > 0) {
            try {
                require_once __DIR__ . '/watplmeta.php';
                WaTplMeta::noteRefused($name, $code);
            } catch (Throwable $ignored) {
            }
        }
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

    /* -----------------------------------------------------------------
     *  The ceiling on ALL outbound WhatsApp (16 Nov 2026, P6-11e)
     *
     *  wa_global_daily_cap  0 (default) = no ceiling, exactly as before;
     *                       N = at most N WhatsApp a day before the
     *                           non-essential ones wait for tomorrow.
     *  Counted in Notify::logMessage() (kv wa_route 'Y-m-d:all') for every
     *  WhatsApp a sender ACCEPTED - tickets, bot replies, alerts, all of it
     *  - so the figure is the whole day. Only an offer / campaign /
     *  marketing / broadcast message or the agents' good-morning is ever
     *  held back; a ticket, a login or one-time code, a bot reply, an office
     *  alert, a trip update or anything about money always goes. At 80 %
     *  the office is told once a day (panel bell + one WhatsApp note).
     * ----------------------------------------------------------------- */

    /** Purposes the daily ceiling may hold back - nothing else is ever stopped. */
    public const CAP_BLOCKABLE = ['offer', 'campaign', 'marketing', 'promo', 'broadcast', 'good_morning'];

    /** Never held back, even when a word above also matches. */
    public const CAP_NEVER = ['ticket', 'otp', 'login', 'auth', 'verify', 'reset', 'security', 'credential', 'code',
                              'bot_reply', 'inbox_reply', 'alert', 'admin', 'office', 'trip', 'sos', 'payment', 'refund',
                              'booking', 'seller', 'cancel', 'support'];

    /** The ceiling, 0 = none. */
    public static function globalCap(): int
    {
        try {
            return max(0, Settings::getInt('wa_global_daily_cap', 0));
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Every WhatsApp a sender accepted today (all purposes). */
    public static function allToday(): int
    {
        return self::counter(date('Y-m-d') . ':all');
    }

    /** May the ceiling hold a message with this purpose back? Conservative: unknown = no. */
    public static function capBlockable(string $purpose): bool
    {
        $p = strtolower(trim($purpose));
        if ($p === '') {
            return false;
        }
        foreach (self::CAP_NEVER as $w) {
            if (str_contains($p, $w)) {
                return false;
            }
        }
        foreach (self::CAP_BLOCKABLE as $w) {
            if (str_contains($p, $w)) {
                return true;
            }
        }
        return false;
    }

    /** '' when this message may go now, else why it waits. */
    public static function capRefusal(string $purpose): string
    {
        $cap = self::globalCap();
        if ($cap <= 0 || !self::capBlockable($purpose)) {
            return '';
        }
        $used = self::allToday();
        return $used >= $cap
            ? 'daily WhatsApp cap reached (' . $used . '/' . $cap . ', wa_global_daily_cap) - ' . $purpose . ' waits until tomorrow'
            : '';
    }

    /**
     * One more WhatsApp went out (Notify::logMessage, status sent). Counts
     * it and, with a ceiling set, tells the office once a day at 80 %.
     * Never throws - it runs inside every send, the webhook's included.
     */
    public static function noteSent(): void
    {
        self::count('all');
        try {
            $cap = self::globalCap();
            if ($cap <= 0) {
                return;
            }
            $used = self::allToday();
            if ($used * 100 < $cap * 80) {
                return;
            }
            require_once INCLUDE_PATH . '/adminalerts.php';
            $title = '📵 WhatsApp दैनिक सीमा ' . (int) floor($used * 100 / $cap) . '% · Daily cap';
            $body  = 'आज ' . $used . ' / ' . $cap . ' WhatsApp गयो। सीमा पुगेपछि अफर र क्याम्पेन मात्र रोकिन्छ - टिकट, OTP, बोट जवाफ र कार्यालय अलर्ट जान्छन्। '
                   . 'Today ' . $used . ' of ' . $cap . ' WhatsApp sent. At the cap only offers and campaigns wait; tickets, codes, bot replies and office alerts still go.';
            // The bell's own dedupe key is the once-a-day guard; the WhatsApp note rides on the first ring only.
            $bell = AdminAlerts::push('wa_daily_cap', $title, $body, '/admin/wa-manager.php', '📵', 'wa_cap80:' . date('Y-m-d'));
            if ($bell <= 0) {
                return;
            }
            $head  = '📵 WhatsApp daily cap ' . (int) floor($used * 100 / $cap) . '% · दैनिक सीमा';
            $facts = [
                'Today / आज' => $used . ' / ' . $cap,
                'At the cap' => 'only offers / campaigns wait - tickets, codes, bot replies, office alerts still go',
                'Change'     => appUrl('admin/wa-manager.php'),
            ];
            if (self::$capAlert !== null) {
                (self::$capAlert)($head, $facts);
                return;
            }
            /* After this request's own work: this runs INSIDE another send's
               logging, and a nested send would land in that send's journal
               (Notify::whatsappJournal) as if it were the message itself. */
            register_shutdown_function(static function () use ($head, $facts): void {
                try {
                    if (class_exists('Notify')) {
                        Notify::adminNote($head, $facts);
                    }
                } catch (Throwable $e) {
                }
            });
        } catch (Throwable $e) {
        }
    }

    /** Tests: fn(string $headline, array $facts) - replaces the office WhatsApp note at 80 %. */
    public static ?Closure $capAlert = null;

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
     *               billableMonth:int, metaFreeMonth:int, allowance:int, allowanceLeft:?int,
     *               allToday:int, globalCap:int}
     */
    public static function stats(): array
    {
        $out = ['policy' => self::policy(), 'metaReady' => self::metaReady(), 'windows' => self::openWindows(),
                'freeToday' => 0, 'free7' => 0, 'aisToday' => 0, 'ais7' => 0, 'savedToday' => 0.0, 'saved7' => 0.0,
                'lastInbound' => '', 'rate' => 0.13,
                'metaTplToday' => 0, 'metaTpl7' => 0, 'paidToday' => self::paidToday(), 'paidLimit' => self::paidLimit(),
                'billableMonth' => self::monthCount('billable'), 'metaFreeMonth' => self::monthCount('metafree'),
                'allowance' => self::freeAllowance(), 'allowanceLeft' => null,
                'allToday' => self::allToday(), 'globalCap' => self::globalCap()];
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
