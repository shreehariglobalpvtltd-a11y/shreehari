<?php
/**
 * =====================================================================
 *  AiHandoff — when the assistant must hand a person to a human
 *  (24 Sep 2026).
 *
 *  The assistant answers what it can from the register and the approved
 *  knowledge. Everything it must NOT decide — a complaint, a payment
 *  dispute, a refund outside the published slabs, an identity doubt, a
 *  safety issue, a policy exception, a confidential paper, anything that
 *  needs approval — becomes a trackable SUPPORT REQUEST here, with a
 *  reference the person can quote (SUP-yymmdd-xxxxx).
 *
 *  It writes to the support_tickets / support_messages tables the first
 *  schema already had (never used until now), alerts the office WhatsApp
 *  when a driver is configured, and is honest about what happened: the
 *  tool result tells the model whether the office was actually alerted,
 *  so the assistant never says "staff have been informed" when only a
 *  row was written. No response time is promised — the company has not
 *  configured one.
 *
 *  A retried message (Meta re-delivery, model retry, the person sending
 *  the same complaint twice) does not open two tickets: the sender,
 *  category and redacted summary form a dedupe key that returns the
 *  open ticket for fifteen minutes.
 *
 *  What is stored is REDACTED: one-time codes, passwords, card numbers
 *  and CVVs never reach the queue. The phone number stays — the desk has
 *  to call the person back.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiHandoff
{
    /** category => [label, default priority] */
    public const CATEGORIES = [
        'complaint'        => ['Complaint',                       'high'],
        'payment_dispute'  => ['Payment dispute',                 'high'],
        'refund_exception' => ['Refund outside the rules',        'normal'],
        'identity'         => ['Identity / account check',        'normal'],
        'document_request' => ['Confidential document request',   'normal'],
        'safety'           => ['Safety / emergency',              'urgent'],
        'policy_exception' => ['Policy exception',                'normal'],
        'approval'         => ['Needs approval',                  'normal'],
        'booking_help'     => ['Booking help',                    'normal'],
        'other'            => ['Other',                           'normal'],
    ];

    /** The same request from the same number inside this window is one ticket. */
    private const DEDUPE_WINDOW = 900;

    private function __construct() {}

    public static function enabled(): bool
    {
        return Settings::getBool('wa_ops_handoff_on', false) && self::available();
    }

    /** The migration added dedupe_key; without it the tool stays hidden. */
    public static function available(bool $recheck = false): bool
    {
        static $has = null;
        if ($has === null || $recheck) {
            try {
                $has = Database::fetch("SHOW COLUMNS FROM support_tickets LIKE 'dedupe_key'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    public static function label(string $category): string
    {
        return self::CATEGORIES[$category][0] ?? ucfirst(str_replace('_', ' ', $category));
    }

    /* -----------------------------------------------------------------
     *  Open a request
     * ----------------------------------------------------------------- */

    /**
     * @param array<string,mixed> $ctx  from AiTools::whoIs (+ channel, messageText, attachment)
     * @param array<string,mixed> $args category, summary, pnr, urgent
     * @return array{ok: bool, say: string, data: array<string,mixed>, media: ?string}
     */
    public static function open(array $ctx, array $args): array
    {
        if (!self::enabled()) {
            return self::no('Human handoff is switched off. Give the office number and say a person will help there.');
        }
        $phone = (string) ($ctx['phone'] ?? '');
        if ($phone === '') {
            return self::no('The sender has no usable number, so a request cannot be opened. Give the office number.');
        }
        // Stored WITH the country code so the desk's reply and wa.me link reach
        // a +977 sender in Nepal, not +91 + the same digits in India.
        $intl = (string) ($ctx['intl'] ?? '') !== '' ? (string) $ctx['intl'] : $phone;
        $category = (string) ($args['category'] ?? 'other');
        if (!isset(self::CATEGORIES[$category])) {
            $category = 'other';
        }
        $summary = self::redact(Security::clean((string) ($args['summary'] ?? ''), 2000));
        if (mb_strlen($summary) < 3) {
            return self::no('Say what the person needs help with, in one or two lines, before opening a request.');
        }
        $urgent   = ($args['urgent'] ?? false) === true;
        $role     = in_array((string) ($ctx['role'] ?? ''), ['customer', 'staff', 'admin'], true) ? (string) $ctx['role'] : 'customer';
        $lang     = self::lang($ctx);
        $priority = $urgent ? 'urgent' : self::CATEGORIES[$category][1];

        // A PNR is attached only when this sender may see that booking.
        $bookingId = null;
        $pnr = strtoupper(trim((string) ($args['pnr'] ?? '')));
        if ($pnr !== '' && Security::isValidPnr($pnr)) {
            try {
                $b = Database::fetch('SELECT id, contact_phone, sold_by_admin_id FROM bookings WHERE pnr = :p LIMIT 1', ['p' => $pnr]);
            } catch (Throwable $e) {
                $b = null;
            }
            if ($b !== null) {
                $owns = match ($role) {
                    'admin' => true,
                    'staff' => ($ctx['scopeAdminId'] ?? null) === null
                                || (int) ($b['sold_by_admin_id'] ?? 0) === (int) $ctx['scopeAdminId'],
                    default => normalisePhone((string) $b['contact_phone']) === $phone,
                };
                if ($owns) {
                    $bookingId = (int) $b['id'];
                } else {
                    $pnr = '';                     // somebody else's booking: not attached, not named
                }
            } else {
                $pnr = '';
            }
        } else {
            $pnr = '';
        }

        $key = hash('sha256', $phone . '|' . $category . '|' . mb_strtolower($summary));
        try {
            // Compared on the database clock: created_at is a MySQL default,
            // so a PHP timestamp here would drift by the two clocks' offset.
            $dup = Database::fetch(
                "SELECT id, ticket_ref, status, office_notified_at FROM support_tickets
                  WHERE dedupe_key = :k AND created_at >= NOW() - INTERVAL " . (int) self::DEDUPE_WINDOW . " SECOND
                    AND status IN ('open','in_progress')
                  ORDER BY id DESC LIMIT 1",
                ['k' => $key]
            );
        } catch (Throwable $e) {
            $dup = null;
        }
        if ($dup !== null) {
            return [
                'ok'   => true,
                'say'  => 'This exact request is ALREADY recorded as ' . $dup['ticket_ref'] . ' (status: ' . $dup['status']
                        . '). Do not open another one — tell the person their reference number.',
                'data' => ['ref' => (string) $dup['ticket_ref'], 'status' => (string) $dup['status'], 'duplicate' => true,
                           'office_notified' => !empty($dup['office_notified_at']), 'bookingId' => $bookingId],
                'media' => null,
            ];
        }

        $name = trim((string) ($ctx['name'] ?? ''));
        if ($name === '') {
            $name = 'WhatsApp ' . maskPhone($phone);
        }
        $attachment = is_array($ctx['attachment'] ?? null) ? $ctx['attachment'] : [];
        $evidence   = (string) ($attachment['stash'] ?? '');
        $message    = $summary;
        if ($attachment !== []) {
            $message .= "\n\n[Attachment received on WhatsApp: " . (string) ($attachment['kind'] ?? 'file')
                      . ((string) ($attachment['mime'] ?? '') !== '' ? ', ' . (string) $attachment['mime'] : '')
                      . ($evidence !== '' ? ' — kept for the desk' : ' — not stored') . ']';
        }

        $ref = '';
        for ($i = 0; $i < 5 && $ref === ''; $i++) {
            $try = generateSupportRef();
            if (!Database::exists('SELECT 1 FROM support_tickets WHERE ticket_ref = :r', ['r' => $try])) {
                $ref = $try;
            }
        }
        if ($ref === '') {
            return self::no('A reference number could not be issued right now. Give the office number.');
        }

        try {
            $id = Database::transaction(static function () use (
                $ref, $bookingId, $name, $intl, $category, $pnr, $message, $priority, $role, $lang, $key, $evidence
            ): int {
                $id = (int) Database::insert('support_tickets', [
                    'ticket_ref'  => $ref,
                    'booking_id'  => $bookingId,
                    'name'        => mb_substr($name, 0, 120),
                    'phone'       => substr($intl, 0, 20),
                    'subject'     => mb_substr(self::label($category) . ($pnr !== '' ? ' · ' . $pnr : ''), 0, 191),
                    'message'     => $message,
                    'category'    => substr($category, 0, 60),
                    'priority'    => $priority,
                    'status'      => 'open',
                    'source'      => 'whatsapp_ai',
                    'sender_role' => $role,
                    'language'    => $lang,
                    'dedupe_key'  => $key,
                    'evidence_path' => $evidence !== '' ? substr($evidence, 0, 255) : null,
                ]);
                Database::insert('support_messages', [
                    'ticket_id'   => $id,
                    'sender_type' => 'customer',
                    'sender_name' => mb_substr($name, 0, 120),
                    'message'     => $message,
                ]);
                return $id;
            });
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
            return self::no('The request could not be recorded. Give the office number and say the person should call.');
        }

        Logger::audit('support.handoff', 'support_ticket', $ref, null, [
            'category' => $category, 'priority' => $priority, 'role' => $role, 'lang' => $lang,
            'phone' => maskPhone($phone), 'booking' => $bookingId,
        ], 'opened by the WhatsApp assistant');

        $notified = self::notifyOffice($ref, $category, $priority, $intl, $summary, $pnr, $bookingId);
        if ($notified) {
            try {
                Database::update('support_tickets', ['office_notified_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            } catch (Throwable $ignored) {
            }
        }

        return [
            'ok'   => true,
            'say'  => 'Recorded as ' . $ref . ' (status: open, waiting for staff). Tell the person this reference number and that the request is with the office. '
                    . ($notified
                        ? 'The office WhatsApp has been alerted.'
                        : 'The office has NOT been alerted automatically — do not say staff were informed; say it is in the office queue and staff see it when they open the panel, and give the office number for anything urgent.')
                    . ' Never promise a response time.',
            'data' => [
                'ref' => $ref, 'status' => 'open', 'category' => $category, 'priority' => $priority,
                'office_notified' => $notified, 'bookingId' => $bookingId, 'language' => $lang,
            ],
            'media' => null,
        ];
    }

    /**
     * The state of one request. A customer or seller sees only requests
     * opened from their own number; the office sees any.
     */
    public static function status(array $ctx, array $args): array
    {
        if (!self::available()) {
            return self::no('Support requests are not available on this server yet.');
        }
        $ref = strtoupper(trim((string) ($args['ref'] ?? '')));
        if (preg_match('/^SUP-\d{6}-[A-Z0-9]{5}$/', $ref) !== 1) {
            return self::no('Ask for the reference number (SUP-…) exactly as it was given.');
        }
        try {
            $t = Database::fetch(
                'SELECT id, ticket_ref, phone, subject, category, priority, status, created_at, updated_at, resolved_at
                   FROM support_tickets WHERE ticket_ref = :r LIMIT 1', ['r' => $ref]);
        } catch (Throwable $e) {
            $t = null;
        }
        $role = (string) ($ctx['role'] ?? 'customer');
        if ($t === null || ($role !== 'admin' && normalisePhone((string) $t['phone']) !== (string) ($ctx['phone'] ?? ''))) {
            // Not found and not-yours read the same: confirming a reference exists is a disclosure.
            return self::no('No request with that reference is on this number.');
        }
        $last = null;
        try {
            $last = Database::fetch(
                "SELECT message, created_at FROM support_messages WHERE ticket_id = :t AND sender_type = 'admin' ORDER BY id DESC LIMIT 1",
                ['t' => (int) $t['id']]);
        } catch (Throwable $ignored) {
        }
        return [
            'ok'   => true,
            'say'  => 'Read the status back plainly. open = waiting for staff, in_progress = staff are working on it, resolved/closed = done. Do not promise a time.',
            'data' => [
                'ref' => $ref, 'status' => (string) $t['status'], 'category' => (string) $t['category'],
                'priority' => (string) $t['priority'], 'subject' => (string) $t['subject'],
                'opened' => (string) $t['created_at'], 'updated' => (string) $t['updated_at'],
                'staffReply' => $last !== null ? mb_substr((string) $last['message'], 0, 600) : null,
            ],
            'media' => null,
        ];
    }

    /* -----------------------------------------------------------------
     *  Helpers
     * ----------------------------------------------------------------- */

    /** One-time codes, passwords, card numbers and CVVs never reach the queue. */
    public static function redact(string $text): string
    {
        $text = preg_replace('/\b(otp|code|password|passcode|pin|cvv|cvc)\s*(?:is|:|=|-)?\s*\d{3,8}\b/iu', '$1 [hidden]', $text) ?? $text;
        // A card is 15–19 digits (Amex 15, Visa / Mastercard / RuPay 16). 13 would
        // also eat a Nepali number with its country code (977 + 10), which the
        // desk needs.
        $text = preg_replace('/(?<!\d)(?:\d[ -]?){14,18}\d(?!\d)/', '[card hidden]', $text) ?? $text;
        $text = preg_replace('/\b(password|passcode|secret|api[_ ]?key|token)\s*[:=]\s*\S+/iu', '$1: [hidden]', $text) ?? $text;
        return trim($text);
    }

    /**
     * ne / hi / en / gu from the sender's own words. TicketBot::detectLang
     * knows the booking vocabulary; a complaint uses other words ("mero
     * paisa katiyo tara ticket aayena"), so romanised everyday markers are
     * counted too before falling back to English.
     */
    public static function lang(array $ctx): string
    {
        $text = (string) ($ctx['messageText'] ?? '');
        try {
            if (!class_exists('TicketBot')) {
                require_once INCLUDE_PATH . '/ticketbot.php';
            }
            $l = TicketBot::detectLang($text);
            if (in_array($l, ['ne', 'hi', 'gu'], true)) {
                return $l;
            }
        } catch (Throwable $e) {
            // fall through to the markers below
        }
        $low   = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text) . ' ';
        $count = static function (array $words) use ($low): int {
            $n = 0;
            foreach ($words as $w) {
                $n += preg_match_all('/ ' . preg_quote($w, '/') . ' /u', $low);
            }
            return $n;
        };
        $ne = $count(['mero', 'hamro', 'malai', 'tapai', 'tapain', 'timro', 'cha', 'chha', 'xa', 'hoina', 'bhayo', 'bhayena', 'aayo', 'aayena',
                      'garnu', 'garne', 'garnus', 'dinus', 'katiyo', 'tara', 'kina', 'kasari', 'kaha', 'kati', 'paisa', 'firta', 'pathau', 'pathaunus', 'ho', 'hola']);
        $hi = $count(['mera', 'meri', 'mere', 'mujhe', 'hai', 'hain', 'nahi', 'nahin', 'nhi', 'kyu', 'kyun', 'kaise', 'kahan', 'kitna', 'karo', 'kar',
                      'bhejo', 'paise', 'aaya', 'aayi', 'hua', 'hui', 'wapas', 'chahiye', 'kat', 'gaya', 'gayi', 'lekin', 'par', 'abhi']);
        $gu = $count(['mane', 'tame', 'chhe', 'che', 'nathi', 'kem', 'kyare', 'kevi', 'joie', 'aapo', 'mara', 'maru', 'mari', 'paisa', 'gaya', 'aavyu', 'nai']);
        if ($gu >= 2 && $gu > $hi && $gu > $ne) {
            return 'gu';
        }
        if ($ne >= 2 && $ne >= $hi) {
            return 'ne';
        }
        if ($hi >= 2) {
            return 'hi';
        }
        return 'en';
    }

    /**
     * Tell the office WhatsApp. Returns true ONLY when the driver reported
     * the message accepted — a click-to-chat link or a failed send is
     * false, and the assistant is told so.
     */
    private static function notifyOffice(string $ref, string $category, string $priority, string $phone, string $summary, string $pnr, ?int $bookingId): bool
    {
        if (!Settings::getBool('wa_ops_handoff_notify', true)) {
            return false;
        }
        $to = Settings::officeWhatsApp();
        if ($to === '') {
            return false;
        }
        try {
            require_once INCLUDE_PATH . '/notify.php';
            $company = Settings::getString('company_name', APP_NAME);
            $text = "🛎️ " . $company . " — नयाँ सहयोग अनुरोध " . $ref . "\n"
                  . self::label($category) . " · " . strtoupper($priority) . "\n"
                  . "बाट: +" . $phone . ($pnr !== '' ? " · " . $pnr : '') . "\n"
                  . mb_substr($summary, 0, 300) . "\n"
                  . "Admin → Support Inbox मा हेर्नुहोस्।";
            $r = Notify::whatsapp($to, $text, null, null, [], $bookingId, ['purpose' => 'handoff']);
            return $r === true;
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
            return false;
        }
    }

    private static function no(string $why): array
    {
        return ['ok' => false, 'say' => $why, 'data' => [], 'media' => null];
    }
}
