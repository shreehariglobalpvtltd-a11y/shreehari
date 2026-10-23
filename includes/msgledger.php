<?php
/**
 * =====================================================================
 *  MsgLedger — every message the system sends, in one book (5 Oct 2026)
 *
 *  Owner: "WhatsApp manager in the admin panel — live, where each
 *  message went, who got what, A to Z, and how much it cost."
 *
 *  Before this, the answer was spread over four places and none of them
 *  was complete:
 *    - message_logs   — Meta / Twilio / Brevo sends, but only 14 of the
 *                       AiSensy sends (most AiSensy traffic never wrote
 *                       a row), and "sent" there only means "accepted";
 *    - wa_ticket_queue — the ticket copies, with real delivery state;
 *    - logs/aisensy.log — every AiSensy call, as text;
 *    - the AiSensy dashboard — the wallet.
 *
 *  msg_ledger joins them:
 *    1. AiSensyAPI::post() writes a row the moment AiSensy answers
 *       (record()), with the template name and the words that went;
 *    2. sync() copies every new message_logs row (all channels) and, when
 *       the wamid is already in the book, only adds the booking / purpose
 *       / staff member to it — one message, one row;
 *    3. poll() asks AiSensy what really happened (accepted is not
 *       delivered): delivered / read / failed + the reason;
 *    4. snapCredit() keeps the AiSensy wallet balance every few minutes,
 *       so "how much did it cost" has a real answer (the drop in the
 *       wallet), next to the estimate from the rate card (price()).
 *
 *  Everything here is best-effort: a missing table or an API that does
 *  not answer never stops a ticket from being sent.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class MsgLedger
{
    /** Rows in these states will not change any more. */
    public const FINAL = ['delivered', 'read', 'failed', 'skipped'];

    /**
     * Rate card, INR per message, charged by Meta when a template is
     * DELIVERED (per-message pricing since July 2025). Service replies
     * (text / image inside the customer's 24 h window) are free. These are
     * estimates and can be changed on the WhatsApp Manager page; the wallet
     * drop next to them is the real figure.
     */
    public const RATES = [
        'IN' => ['utility' => 0.13, 'authentication' => 0.13, 'marketing' => 0.88],
        'NP' => ['utility' => 1.00, 'authentication' => 1.00, 'marketing' => 6.45],
        'OT' => ['utility' => 1.00, 'authentication' => 1.00, 'marketing' => 6.45],
    ];
    public const SMS_RATE = 0.25;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            self::$ready = Settings::getBool('wam_on', true) && (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('msg_ledger','wam_credit')", [], 0) === 2;
        } catch (Throwable $e) {
            self::$ready = false;
        }
        return self::$ready;
    }

    /** IN / NP / OT from international digits. */
    public static function countryOf(string $digits): string
    {
        $d = preg_replace('/\D+/', '', $digits) ?? '';
        if (str_starts_with($d, '977')) {
            return 'NP';
        }
        if (str_starts_with($d, '91') && strlen($d) === 12) {
            return 'IN';
        }
        if (strlen($d) === 10) {
            return preg_match('/^9[78]/', $d) ? 'NP' : 'IN';
        }
        return $d === '' ? '' : 'OT';
    }

    /* =================================================================
     *  1. Write — called by AiSensyAPI::post() for every send
     * ================================================================= */

    /**
     * One AiSensy send, the moment AiSensy answered.
     *
     * @param array<string,mixed> $req  the request body that went to AiSensy
     * @param array<string,mixed> $out  post()'s result (success, message_id, error)
     */
    public static function recordAiSensy(string $to, string $label, array $req, array $out, string $status = ''): void
    {
        /* 15 Oct 2026 (2D-paid-limit): an accepted TEMPLATE is a paid message.
           Counted before the ledger check, so the daily cap holds even on a
           server where the ledger tables are not installed. */
        if (($req['type'] ?? '') === 'template' && !empty($out['success'])) {
            try {
                if (!class_exists('WaRoute') && defined('INCLUDE_PATH') && is_file(INCLUDE_PATH . '/waroute.php')) {
                    require_once INCLUDE_PATH . '/waroute.php';
                }
                if (class_exists('WaRoute')) {
                    WaRoute::count('paid');
                }
            } catch (Throwable $e) {
                // a counter must never break a send
            }
        }
        try {
            if (!self::ready()) {
                return;
            }
            $type = (string) ($req['type'] ?? '');
            $body = '';
            $media = null;
            if ($type === 'template') {
                $vars = [];
                foreach ((array) ($req['template']['components'] ?? []) as $c) {
                    foreach ((array) ($c['parameters'] ?? []) as $p) {
                        if (($p['type'] ?? '') === 'text') {
                            $vars[] = (string) ($p['text'] ?? '');
                        } elseif (($p['type'] ?? '') === 'image') {
                            $media = (string) ($p['image']['link'] ?? '');
                        }
                    }
                }
                $body = $vars !== [] ? implode(' · ', $vars) : '(no variables)';
            } elseif ($type === 'text') {
                $body = (string) ($req['text']['body'] ?? '');
            } elseif ($type === 'image') {
                $media = (string) ($req['image']['link'] ?? '');
                $body  = (string) ($req['image']['caption'] ?? '');
            }
            $ok  = !empty($out['success']);
            $st  = $status !== '' ? $status : ($ok ? 'accepted' : 'failed');
            $ref = trim((string) ($out['message_id'] ?? ''));
            $row = [
                'provider'     => 'aisensy',
                'channel'      => 'whatsapp',
                'to_number'    => substr(preg_replace('/\D+/', '', $to) ?? '', 0, 40),
                'country'      => self::countryOf($to),
                'label'        => mb_substr($label, 0, 80),
                'category'     => in_array($label, ['text', 'image'], true) ? 'service' : self::templateCategory($label),
                'body'         => mb_substr(self::mask($body), 0, 700),
                'media_url'    => $media !== null && $media !== '' ? mb_substr($media, 0, 255) : null,
                'status'       => $st,
                'error'        => $ok ? null : mb_substr((string) ($out['error'] ?? ''), 0, 255),
                'provider_ref' => $ref !== '' ? mb_substr($ref, 0, 190) : null,
                'created_at'   => date('Y-m-d H:i:s'),
            ];
            Database::insertIgnore('msg_ledger', $row);
        } catch (Throwable $e) {
            // The ledger must never break a send.
        }
    }

    /** Hide one-time codes and link tokens, the same way Notify does. */
    private static function mask(string $body): string
    {
        // 22 Oct 2026: the Nepali half too ("तपाईंको लगइन कोड 962250" was kept whole in the ledger)
        $body = preg_replace('/((?:code|कोड)\D{0,12}?)(\d{4,8})/iu', '$1••••', $body) ?? $body;
        // 23 Oct 2026: the one-time password of an approval (Notify::agentCredentials) never sits in a log
        $body = preg_replace('/((?:password|पासवर्ड)\s*[:：]\s*)\S+/iu', '$1[hidden]', $body) ?? $body;
        $body = preg_replace('~([?&]t=)[A-Za-z0-9]{16,}~', '$1[hidden]', $body) ?? $body;
        /* 15 Oct 2026 (2F-logs): the ticket download key (&k=…) and the short
           ticket code (view.php?code=…) open a passenger's ticket - a log
           reader needs neither. The message itself went out whole. */
        $body = preg_replace('~([?&]k=)[A-Za-z0-9]{8,}~', '$1[hidden]', $body) ?? $body;
        return preg_replace('~(view\.php\?code=)[A-Za-z0-9]{6,}~', '$1[hidden]', $body) ?? $body;
    }

    /** UTILITY / MARKETING / AUTHENTICATION for an AiSensy template, from the cached list. */
    public static function templateCategory(string $name): string
    {
        $map = self::kvJson('tpl_cats');
        $c = strtolower((string) ($map[$name] ?? ''));
        if (in_array($c, ['utility', 'marketing', 'authentication'], true)) {
            return $c;
        }
        return str_contains($name, 'offer') || str_contains($name, 'promo') ? 'marketing' : 'utility';
    }

    /* =================================================================
     *  2. Sync — message_logs (every channel) into the ledger
     * ================================================================= */

    /** @return array{copied:int, merged:int, refreshed:int, names:int, queue:int} */
    public static function sync(int $max = 500): array
    {
        $res = ['copied' => 0, 'merged' => 0, 'refreshed' => 0, 'names' => 0, 'queue' => 0];
        if (!self::ready()) {
            return $res;
        }
        $hasPurpose = self::messageLogsHasPurpose();
        $cursor = (int) self::kvGet('cursor', '0');
        $cols = 'id, booking_id, channel, provider, to_number, body, status, provider_ref, error, created_at'
              . ($hasPurpose ? ', purpose, admin_id, media_url' : '');
        $rows = Database::fetchAll(
            'SELECT ' . $cols . ' FROM message_logs WHERE id > :c ORDER BY id ASC LIMIT ' . max(1, min(2000, $max)),
            ['c' => $cursor]
        );
        foreach ($rows as $m) {
            $cursor = max($cursor, (int) $m['id']);
            $ref = trim((string) ($m['provider_ref'] ?? ''));
            $purpose = $hasPurpose ? ($m['purpose'] ?? null) : null;
            if ($ref !== '') {
                $have = Database::fetch('SELECT id, log_id FROM msg_ledger WHERE provider_ref = :r', ['r' => $ref]);
                if ($have !== null) {
                    if ($have['log_id'] === null) {
                        Database::update('msg_ledger', [
                            'log_id'     => (int) $m['id'],
                            'booking_id' => $m['booking_id'] !== null ? (int) $m['booking_id'] : null,
                            'purpose'    => $purpose,
                            'admin_id'   => $hasPurpose && $m['admin_id'] !== null ? (int) $m['admin_id'] : null,
                        ], 'id = :i', ['i' => (int) $have['id']]);
                    }
                    $res['merged']++;
                    continue;
                }
            }
            $provider = (string) $m['provider'];
            /* 7 Oct 2026: a FAILED AiSensy send has no provider_ref, so it never
               merges above - yet recordAiSensy() already wrote it the moment
               AiSensy refused. Copying it again counted every failure twice (or
               more). Skip it when that live row exists for the same number. */
            if ($ref === '' && $provider === 'aisensy' && (string) $m['status'] === 'failed') {
                $num = substr(preg_replace('/\D+/', '', (string) $m['to_number']) ?? '', 0, 40);
                if ($num !== '' && Database::exists(
                    "SELECT 1 FROM msg_ledger WHERE provider = 'aisensy' AND log_id IS NULL AND to_number = :n
                        AND created_at BETWEEN :t - INTERVAL 3 MINUTE AND :t2 + INTERVAL 3 MINUTE LIMIT 1",
                    ['n' => $num, 't' => (string) $m['created_at'], 't2' => (string) $m['created_at']]
                )) {
                    $res['merged']++;
                    continue;
                }
            }
            self::copyOne($m, $hasPurpose);
            $res['copied']++;
        }
        if ($rows !== []) {
            self::kvSet('cursor', (string) $cursor);
        }

        /* A Meta / Twilio webhook rewrites message_logs.status in place
           (accepted → delivered → "sent", or → failed). Carry those changes
           over for the last three days. AiSensy rows are polled instead.
           15 Oct 2026: never back DOWN - a Meta delivered / read written
           straight into the ledger (noteMetaStatus) reads "sent" in
           message_logs, and this used to turn it back into "sent". */
        $res['refreshed'] = (int) Database::run(
            "UPDATE msg_ledger l JOIN message_logs m ON m.id = l.log_id
                SET l.status = m.status, l.error = m.error, l.status_at = NOW()
              WHERE l.provider <> 'aisensy'
                AND l.created_at > NOW() - INTERVAL 3 DAY
                AND m.status IN ('sent','failed','skipped','queued')
                AND l.status <> m.status
                AND NOT (m.status = 'sent' AND l.status IN ('delivered','read'))"
        );

        /* The ticket queue already asks AiSensy about its own sends: take
           its delivered / read for free instead of asking twice. */
        try {
            $res['queue'] = (int) Database::run(
                "UPDATE msg_ledger l JOIN wa_ticket_queue q ON q.wamid = l.provider_ref
                    SET l.status = q.status, l.status_at = COALESCE(q.checked_at, NOW()),
                        l.booking_id = COALESCE(l.booking_id, q.booking_id), l.pnr = COALESCE(l.pnr, q.pnr),
                        l.purpose = COALESCE(l.purpose, IF(q.role = 'seller', 'seller_copy', 'ticket'))
                  WHERE q.status IN ('delivered','read')
                    AND l.status NOT IN ('delivered','read')"
            );
        } catch (Throwable $e) {
            // queue table not there on this server
        }

        $res['names'] = self::resolveNames(300);
        return $res;
    }

    /** One message_logs row into the book (sync() and noteMetaStatus()). */
    private static function copyOne(array $m, bool $hasPurpose): void
    {
        $provider = (string) $m['provider'];
        $channel  = (string) $m['channel'];
        $body     = (string) ($m['body'] ?? '');
        $purpose  = $hasPurpose ? ($m['purpose'] ?? null) : null;
        $ref      = trim((string) ($m['provider_ref'] ?? ''));
        Database::insertIgnore('msg_ledger', [
            'log_id'       => (int) $m['id'],
            'provider'     => mb_substr($provider, 0, 20),
            'channel'      => $channel,
            'to_number'    => substr(preg_replace('/\D+/', '', (string) $m['to_number']) ?? '', 0, 40),
            'country'      => self::countryOf((string) $m['to_number']),
            'booking_id'   => $m['booking_id'] !== null ? (int) $m['booking_id'] : null,
            'purpose'      => $purpose,
            'label'        => self::labelFor($provider, $channel, (string) $purpose, $body),
            'category'     => self::categoryFor($provider, $channel, (string) $purpose, $body),
            'body'         => mb_substr($body, 0, 700),
            'media_url'    => $hasPurpose && !empty($m['media_url']) ? mb_substr((string) $m['media_url'], 0, 255) : null,
            'status'       => self::mapLogStatus($provider, (string) $m['status']),
            'error'        => $m['error'] !== null ? mb_substr((string) $m['error'], 0, 255) : null,
            'provider_ref' => $ref !== '' ? mb_substr($ref, 0, 190) : null,
            'admin_id'     => $hasPurpose && $m['admin_id'] !== null ? (int) $m['admin_id'] : null,
            'created_at'   => (string) $m['created_at'],
        ]);
    }

    /**
     * A Meta delivered / read status, straight into the book (15 Oct 2026).
     *
     * The webhook can only write "sent" into message_logs (its status enum
     * has no delivered / read), and sync() copied that "sent" - so no free
     * Meta message ever reached Delivered or Read, and the WhatsApp
     * Manager's Reached / Read figures counted only the "hi" queue. The
     * webhook now hands every delivered / read here: the ledger row moves
     * forward (never back), and Meta's own pricing says whether it was a
     * free service message - a free one costs nothing in the estimate.
     * A row sync() has not copied yet is copied first.
     */
    public static function noteMetaStatus(string $wamid, string $state, ?array $pricing = null): bool
    {
        if ($wamid === '' || !in_array($state, ['delivered', 'read'], true) || !self::ready()) {
            return false;
        }
        $from = $state === 'read' ? "'queued','accepted','sent','delivered'" : "'queued','accepted','sent'";
        $set  = 'status = :s, status_at = NOW()';
        $params = ['s' => $state, 'r' => mb_substr($wamid, 0, 190)];
        if (is_array($pricing) && ($pricing['billable'] ?? null) === false) {
            $set .= ", category = IF(channel = 'whatsapp', 'service', category)";
        }
        try {
            if (!Database::exists('SELECT 1 FROM msg_ledger WHERE provider_ref = :r LIMIT 1', ['r' => $params['r']])) {
                $hasPurpose = self::messageLogsHasPurpose();
                $m = Database::fetch(
                    'SELECT id, booking_id, channel, provider, to_number, body, status, provider_ref, error, created_at'
                    . ($hasPurpose ? ', purpose, admin_id, media_url' : '')
                    . ' FROM message_logs WHERE provider_ref = :r ORDER BY id DESC LIMIT 1',
                    ['r' => substr($wamid, 0, 64)]
                );
                if ($m === null) {
                    return false;
                }
                self::copyOne($m, $hasPurpose);
            }
            return (int) Database::run(
                "UPDATE msg_ledger SET $set WHERE provider_ref = :r AND status IN ($from)",
                $params
            ) > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** message_logs "sent" means accepted for the async providers. */
    private static function mapLogStatus(string $provider, string $status): string
    {
        return match ($status) {
            'failed'  => 'failed',
            'skipped' => 'skipped',
            'queued'  => 'queued',
            default   => $provider === 'aisensy' ? 'accepted' : 'sent',
        };
    }

    private static function labelFor(string $provider, string $channel, string $purpose, string $body): string
    {
        if ($channel !== 'whatsapp') {
            return $channel;
        }
        if ($provider === 'aisensy' && preg_match('/\((image template|template|image|text)\)\s*$/', $body, $m)) {
            return $m[1] === 'image template' ? 'shg_ticket_image' : ($m[1] === 'template' ? 'shg_ticket_confirmed' : $m[1]);
        }
        return $purpose !== '' ? $purpose : 'message';
    }

    private static function categoryFor(string $provider, string $channel, string $purpose, string $body): string
    {
        if ($channel === 'sms' || $channel === 'email') {
            return $channel;
        }
        if ($purpose === 'bot_reply' || preg_match('/\((image|text)\)\s*$/', $body)) {
            return 'service';
        }
        return str_contains($purpose, 'offer') || str_contains($purpose, 'campaign') ? 'marketing' : 'utility';
    }

    /**
     * Who is this number? Staff / agent first (a seller copy goes to the
     * clerk, not the passenger), then the booking's passenger, then the
     * newest booking made with that number.
     */
    private static function resolveNames(int $limit): int
    {
        $rows = Database::fetchAll(
            'SELECT id, to_number, booking_id FROM msg_ledger WHERE who_kind IS NULL ORDER BY id DESC LIMIT ' . max(1, $limit)
        );
        if ($rows === []) {
            return 0;
        }
        // Keys are prefixed: digit-only keys turn into PHP ints and collide.
        $staff = [];
        foreach (Database::fetchAll("SELECT full_name, phone, role FROM admins WHERE phone IS NOT NULL AND phone <> ''") as $a) {
            $d = substr(preg_replace('/\D+/', '', (string) $a['phone']) ?? '', -10);
            if (strlen($d) === 10) {
                $staff['p' . $d] = [(string) $a['full_name'], (string) $a['role']];
            }
        }
        $n = 0;
        foreach ($rows as $r) {
            $last10 = substr((string) $r['to_number'], -10);
            $name = '';
            $kind = 'unknown';
            $pnr  = null;
            if (strlen($last10) === 10 && isset($staff['p' . $last10])) {
                [$name, $role] = $staff['p' . $last10];
                $kind = match ($role) {
                    'agent'      => 'agent',
                    'counter'    => 'counter',
                    'superadmin' => 'owner',
                    default      => 'staff',
                };
            }
            $bid = $r['booking_id'] !== null ? (int) $r['booking_id'] : 0;
            if ($bid === 0 && strlen($last10) === 10) {
                $full = (string) $r['to_number'];
                $bid = (int) Database::scalar(
                    'SELECT id FROM bookings WHERE contact_phone IN (:a, :b, :c, :d) ORDER BY id DESC LIMIT 1',
                    ['a' => $last10, 'b' => $full, 'c' => '+' . $full, 'd' => '0' . $last10], 0
                );
            }
            if ($bid > 0) {
                $pnr = (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :b', ['b' => $bid], '');
                if ($name === '') {
                    $name = (string) Database::scalar(
                        'SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY id LIMIT 1', ['b' => $bid], '');
                    $kind = 'customer';
                }
            }
            Database::update('msg_ledger', [
                'who_name' => $name !== '' ? mb_substr($name, 0, 120) : null,
                'who_kind' => $kind,
                'pnr'      => $pnr !== null && $pnr !== '' ? $pnr : null,
            ], 'id = :i AND who_kind IS NULL', ['i' => (int) $r['id']]);
            $n++;
        }
        return $n;
    }

    /* =================================================================
     *  3. Poll — what really happened to an AiSensy send
     * ================================================================= */

    /** @return array{checked:int, delivered:int, read:int, failed:int} */
    public static function poll(int $limit = 40): array
    {
        $out = ['checked' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0];
        if (!self::ready() || !self::aisensyLoaded()) {
            return $out;
        }
        /* Back off: a row is asked again after 2, 4, 8 … minutes (max 2 h),
           and given up after 3 days or 14 asks. */
        $rows = Database::fetchAll(
            "SELECT id, provider_ref, to_number, polls FROM msg_ledger
              WHERE provider = 'aisensy' AND provider_ref IS NOT NULL
                AND status IN ('accepted','sent')
                AND created_at > NOW() - INTERVAL 3 DAY AND created_at < NOW() - INTERVAL 40 SECOND
                AND polls < 14
                AND (checked_at IS NULL OR checked_at < NOW() - INTERVAL LEAST(120, POW(2, polls)) MINUTE)
              ORDER BY created_at DESC LIMIT " . max(1, min(200, $limit))
        );
        foreach ($rows as $r) {
            $st = AiSensyAPI::messageStatus((string) $r['provider_ref'], (string) $r['to_number']);
            $out['checked']++;
            $s = strtolower((string) ($st['status'] ?? ''));
            $set = ['checked_at' => date('Y-m-d H:i:s'), 'polls' => min(255, (int) $r['polls'] + 1)];
            if (in_array($s, ['sent', 'delivered', 'read', 'failed'], true)) {
                $set['status'] = $s;
                $set['status_at'] = date('Y-m-d H:i:s');
                if ($s === 'failed') {
                    $set['error'] = mb_substr((string) ($st['error'] !== '' ? $st['error'] : 'failed'), 0, 255);
                }
                if (isset($out[$s])) {
                    $out[$s]++;
                }
            }
            Database::update('msg_ledger', $set, 'id = :i', ['i' => (int) $r['id']]);
        }
        return $out;
    }

    private static function aisensyLoaded(): bool
    {
        if (!class_exists('AiSensyAPI')) {
            $f = defined('ROOT_PATH') ? ROOT_PATH . '/whatsapp/aisensy.php' : dirname(__DIR__) . '/whatsapp/aisensy.php';
            if (is_file($f)) {
                require_once $f;
            }
        }
        return class_exists('AiSensyAPI') && AiSensyAPI::apiKey() !== '';
    }

    /* =================================================================
     *  4. Money — the rate card estimate and the real wallet
     * ================================================================= */

    /** INR per message for one country + category (settings override the card). */
    public static function rate(string $country, string $category): float
    {
        if ($category === 'sms') {
            return Settings::getFloat('wam_rate_sms', self::SMS_RATE);
        }
        if (!in_array($category, ['utility', 'authentication', 'marketing'], true)) {
            return 0.0;
        }
        $cc = isset(self::RATES[$country]) ? $country : 'OT';
        return Settings::getFloat('wam_rate_' . strtolower($cc) . '_' . $category, self::RATES[$cc][$category]);
    }

    /**
     * Put a cost on every message whose state is final. Meta bills a
     * template when it is delivered; a failed one costs nothing, a service
     * reply costs nothing. Meta / Twilio rows only say "sent" (the webhook
     * turns delivered into sent), so for those "sent" counts as delivered.
     */
    public static function price(): int
    {
        if (!self::ready()) {
            return 0;
        }
        $n = (int) Database::run(
            "UPDATE msg_ledger SET cost = 0
              WHERE cost <> 0 AND (status IN ('failed','skipped','queued','accepted') OR category IN ('service','email',''))"
        );
        foreach (['IN', 'NP', 'OT'] as $cc) {
            foreach (['utility', 'authentication', 'marketing'] as $cat) {
                $n += (int) Database::run(
                    "UPDATE msg_ledger SET cost = :r
                      WHERE channel = 'whatsapp' AND country = :c AND category = :k
                        AND (status IN ('delivered','read') OR (provider <> 'aisensy' AND status = 'sent'))
                        AND cost <> :r2 AND created_at > NOW() - INTERVAL 62 DAY",
                    ['r' => self::rate($cc, $cat), 'c' => $cc, 'k' => $cat, 'r2' => self::rate($cc, $cat)]
                );
            }
        }
        $sms = self::rate('IN', 'sms');
        $n += (int) Database::run(
            "UPDATE msg_ledger SET cost = :r WHERE channel = 'sms' AND status = 'sent' AND cost <> :r2
                AND created_at > NOW() - INTERVAL 62 DAY",
            ['r' => $sms, 'r2' => $sms]
        );
        return $n;
    }

    /**
     * AiSensy wallet + account health, kept every 10 minutes (or when it
     * changed). credit comes back in 1/100000 rupee: 4388026 = ₹43.88.
     *
     * @return array<string,mixed> the project facts (also cached in kv)
     */
    public static function snapCredit(bool $force = false): array
    {
        if (!self::ready() || !self::aisensyLoaded()) {
            return [];
        }
        $last = Database::fetch('SELECT credit, at FROM wam_credit ORDER BY id DESC LIMIT 1');
        if (!$force && $last !== null && strtotime((string) $last['at']) > time() - 240) {
            return self::kvJson('project');
        }
        $r = AiSensyAPI::projectInfo();
        $j = is_array($r['response'] ?? null) ? $r['response'] : [];
        if ((int) ($r['http'] ?? 0) !== 200 || !isset($j['credit'])) {
            self::kvSet('project_err', json_encode(['http' => (int) ($r['http'] ?? 0), 'at' => date('c')]) ?: '');
            return self::kvJson('project');
        }
        $facts = [
            'credit'      => round(((float) $j['credit']) / 100000, 2),
            'plan'        => (string) ($j['active_plan'] ?? ''),
            'status'      => (string) ($j['status'] ?? ''),
            'subscription'=> (string) ($j['subscription_status'] ?? ''),
            'renewal'     => isset($j['plan_renewal_on']) ? date('Y-m-d', (int) ($j['plan_renewal_on'] / 1000)) : '',
            'number'      => (string) ($j['wa_number'] ?? ''),
            'name'        => (string) ($j['wa_display_name'] ?? ''),
            'quality'     => (string) ($j['wa_quality_rating'] ?? ''),
            'tier'        => (string) ($j['wa_messaging_tier'] ?? ''),
            'daily_limit' => (int) ($j['daily_template_limit'] ?? 0),
            'fbm'         => (string) ($j['fb_business_manager_status'] ?? ''),
            'sandbox'     => !empty($j['sandbox']),
            'at'          => date('Y-m-d H:i:s'),
        ];
        self::kvSet('project', json_encode($facts, JSON_UNESCAPED_SLASHES) ?: '{}');
        $changed = $last === null || abs((float) $last['credit'] - $facts['credit']) >= 0.005;
        if ($force || $changed || strtotime((string) $last['at']) < time() - 600) {
            Database::insert('wam_credit', [
                'at'      => $facts['at'],
                'credit'  => $facts['credit'],
                'quality' => mb_substr($facts['quality'], 0, 12),
                'tier'    => mb_substr($facts['tier'], 0, 20),
            ]);
        }
        return $facts;
    }

    /**
     * What the wallet really paid between two times: every drop between
     * two readings, summed. A rise is a recharge and is reported apart.
     *
     * @return array{spent:float, recharged:float, from:?float, to:?float}
     */
    public static function walletSpend(string $from, string $to): array
    {
        $out = ['spent' => 0.0, 'recharged' => 0.0, 'from' => null, 'to' => null];
        if (!self::ready()) {
            return $out;
        }
        $prev = Database::scalar('SELECT credit FROM wam_credit WHERE at < :f ORDER BY at DESC LIMIT 1', ['f' => $from]);
        $rows = Database::fetchAll('SELECT credit FROM wam_credit WHERE at >= :f AND at < :t ORDER BY at ASC', ['f' => $from, 't' => $to]);
        $p = $prev !== null ? (float) $prev : null;
        foreach ($rows as $r) {
            $c = (float) $r['credit'];
            if ($out['from'] === null) {
                $out['from'] = $p ?? $c;
            }
            if ($p !== null) {
                $d = $p - $c;
                if ($d > 0) {
                    $out['spent'] += $d;
                } else {
                    $out['recharged'] += -$d;
                }
            }
            $p = $c;
        }
        $out['to'] = $p;
        $out['spent'] = round($out['spent'], 2);
        $out['recharged'] = round($out['recharged'], 2);
        return $out;
    }

    /** name → category for every AiSensy template, refreshed every 6 h. */
    public static function refreshTemplates(bool $force = false): array
    {
        $list = self::kvJson('templates');
        $at = (int) ($list['_at'] ?? 0);
        if (!$force && $at > time() - 21600) {
            return (array) ($list['rows'] ?? []);
        }
        if (!self::aisensyLoaded()) {
            return (array) ($list['rows'] ?? []);
        }
        $rows = AiSensyAPI::templates();
        if ($rows === []) {
            return (array) ($list['rows'] ?? []);
        }
        $cats = [];
        foreach ($rows as $t) {
            $cats[(string) $t['name']] = strtolower((string) $t['category']);
        }
        self::kvSet('tpl_cats', json_encode($cats) ?: '{}');
        self::kvSet('templates', json_encode(['_at' => time(), 'rows' => $rows], JSON_UNESCAPED_UNICODE) ?: '{}');
        return $rows;
    }

    /**
     * First run only: the AiSensy sends made before the ledger existed,
     * read back from logs/aisensy.log (SENT / FAIL / SKIP lines).
     */
    public static function backfillAiSensyLog(): int
    {
        if (!self::ready() || self::kvGet('log_backfilled', '') === '1') {
            return 0;
        }
        $file = (defined('LOG_PATH') ? LOG_PATH : dirname(__DIR__) . '/logs') . '/aisensy.log';
        $n = 0;
        if (is_readable($file)) {
            foreach ((array) @file($file, FILE_IGNORE_NEW_LINES) as $line) {
                if (!preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] (SENT|FAIL|SKIP) \| ([^|]*) \| ([^|]*) \| http=(\d+) \| id=([^|]*) \| err=([^|]*)\|/', (string) $line, $m)) {
                    continue;
                }
                $label = trim($m[4]);
                $ref   = trim($m[6]);
                $to    = preg_replace('/\D+/', '', $m[3]) ?? '';
                $n += Database::insertIgnore('msg_ledger', [
                    'provider'     => 'aisensy',
                    'channel'      => 'whatsapp',
                    'to_number'    => substr($to, 0, 40),
                    'country'      => self::countryOf($to),
                    'label'        => mb_substr($label, 0, 80),
                    'category'     => in_array($label, ['text', 'image'], true) ? 'service' : self::templateCategory($label),
                    'body'         => null,
                    'status'       => $m[2] === 'SENT' ? 'accepted' : ($m[2] === 'SKIP' ? 'skipped' : 'failed'),
                    'error'        => $m[2] === 'SENT' ? null : mb_substr(trim($m[7]), 0, 255),
                    'provider_ref' => $ref !== '' ? mb_substr($ref, 0, 190) : null,
                    'created_at'   => $m[1],
                ]) > 0 ? 1 : 0;
            }
        }
        self::kvSet('log_backfilled', '1');
        return $n;
    }

    /* =================================================================
     *  5. Read — what the WhatsApp Manager page shows
     * ================================================================= */

    /**
     * Totals for a window.
     *
     * @return array<string,mixed>
     */
    public static function totals(string $from, string $to): array
    {
        $r = Database::fetch(
            "SELECT COUNT(*) AS total,
                    SUM(status IN ('accepted','sent','delivered','read')) AS went,
                    SUM(status IN ('delivered','read')) AS delivered,
                    SUM(status = 'read') AS seen,
                    SUM(status = 'failed') AS failed,
                    SUM(status = 'skipped') AS skipped,
                    SUM(status IN ('accepted','sent','queued')) AS waiting,
                    COUNT(DISTINCT to_number) AS people,
                    SUM(cost) AS cost,
                    SUM(channel = 'whatsapp') AS wa, SUM(channel = 'sms') AS sms, SUM(channel = 'email') AS email
               FROM msg_ledger WHERE created_at >= :f AND created_at < :t",
            ['f' => $from, 't' => $to]
        ) ?? [];
        foreach ($r as $k => $v) {
            $r[$k] = $k === 'cost' ? round((float) $v, 2) : (int) $v;
        }
        return $r;
    }

    /** @return list<array<string,mixed>> grouped rows for one dimension */
    public static function breakdown(string $from, string $to, string $by): array
    {
        $col = match ($by) {
            'purpose'  => "COALESCE(NULLIF(purpose,''), label)",
            'country'  => "COALESCE(NULLIF(country,''), '?')",
            'provider' => "CONCAT(provider, ' · ', channel)",
            'kind'     => "COALESCE(who_kind, 'unknown')",
            default    => 'label',
        };
        return Database::fetchAll(
            "SELECT $col AS k, COUNT(*) AS total,
                    SUM(status IN ('delivered','read')) AS delivered, SUM(status = 'read') AS seen,
                    SUM(status = 'failed') AS failed, SUM(status IN ('accepted','sent','queued')) AS waiting,
                    ROUND(SUM(cost), 2) AS cost
               FROM msg_ledger WHERE created_at >= :f AND created_at < :t
              GROUP BY k ORDER BY total DESC LIMIT 25",
            ['f' => $from, 't' => $to]
        );
    }

    /** Failure reasons in plain words, most common first. */
    public static function failReasons(string $from, string $to): array
    {
        $rows = Database::fetchAll(
            "SELECT error, provider, COUNT(*) AS n FROM msg_ledger
              WHERE status = 'failed' AND created_at >= :f AND created_at < :t
              GROUP BY error, provider ORDER BY n DESC LIMIT 60",
            ['f' => $from, 't' => $to]
        );
        $agg = [];
        foreach ($rows as $r) {
            [$code, $text] = self::explain((string) $r['error']);
            $k = 'k' . $code . '|' . $r['provider'];
            if (!isset($agg[$k])) {
                $agg[$k] = ['code' => $code, 'text' => $text, 'provider' => (string) $r['provider'], 'n' => 0];
            }
            $agg[$k]['n'] += (int) $r['n'];
        }
        usort($agg, static fn($a, $b) => $b['n'] <=> $a['n']);
        return array_slice(array_values($agg), 0, 8);
    }

    /**
     * Provider error → [code, what it means and what to do].
     *
     * @return array{0:string,1:string}
     */
    public static function explain(string $error): array
    {
        $e = strtolower($error);
        $known = [
            '131047' => 'No open chat with this number (24 h window). A template would reach them; a plain text cannot.',
            '131026' => 'This number is not on WhatsApp, or cannot receive from a business.',
            '131042' => 'Meta payment method missing on the Meta number — owner must add a card in Meta Business.',
            '131049' => 'Meta held back a marketing message to protect the customer (too many offers).',
            '131051' => 'Message type not supported on this phone.',
            '131053' => 'The PNG / file could not be downloaded by WhatsApp.',
            '132001' => 'Template name / language not found (or not approved yet).',
            '132000' => 'Template variables did not match the template.',
            '130472' => 'Customer is in a Meta experiment group — marketing message not delivered.',
            '63112'  => 'Twilio: Meta disabled that WhatsApp sender.',
            '63016'  => 'Twilio: outside the 24 h window — needs a template.',
            '470'    => 'Outside the 24 h window — needs a template.',
        ];
        foreach ($known as $code => $text) {
            if (str_contains($e, (string) $code)) {
                return [(string) $code, $text];
            }
        }
        if (str_contains($e, 'invalid phone')) {
            return ['phone', 'Phone number on the booking is wrong / too short — it never left.'];
        }
        if (str_contains($e, 'no sms related addons') || str_contains($e, 'credits')) {
            return ['sms', 'Brevo SMS: no SMS credits bought (and India needs DLT approval).'];
        }
        if (str_contains($e, 'not configured')) {
            return ['key', 'API key not set up.'];
        }
        if ($e === '' || $e === 'failed') {
            return ['?', 'Failed — the provider gave no reason.'];
        }
        if (str_contains($e, 'authentication failed') || str_contains($e, 'not active')) {
            return ['auth', 'Provider account is switched off / login refused (Twilio account not active).'];
        }
        // never show an account id (Twilio "AC…" SID) on the page
        return ['other', mb_strimwidth(preg_replace('/\bAC[0-9a-f]{32}\b/i', 'AC…', $error) ?? $error, 0, 120, '…')];
    }

    /**
     * The live feed.
     *
     * Newest first by time (the first run copies history after the
     * AiSensy rows, so ids alone are not in time order); `since` = only rows
     * added after that id (the live poll), `offset` = paging.
     *
     * @param array{q?:string,channel?:string,status?:string,country?:string,since?:int,before?:int,offset?:int} $f
     * @return list<array<string,mixed>>
     */
    public static function feed(array $f, int $limit = 60): array
    {
        $w = [];
        $p = [];
        if (!empty($f['since'])) {
            $w[] = 'l.id > :since';
            $p['since'] = (int) $f['since'];
        }
        if (!empty($f['before'])) {
            $w[] = 'l.id < :before';
            $p['before'] = (int) $f['before'];
        }
        if (!empty($f['from'])) {
            $w[] = 'l.created_at >= :from';
            $p['from'] = (string) $f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'l.created_at < :to';
            $p['to'] = (string) $f['to'];
        }
        if (!empty($f['channel'])) {
            $w[] = 'l.channel = :ch';
            $p['ch'] = (string) $f['channel'];
        }
        if (!empty($f['country'])) {
            $w[] = 'l.country = :cc';
            $p['cc'] = (string) $f['country'];
        }
        if (!empty($f['status'])) {
            if ($f['status'] === 'waiting') {
                $w[] = "l.status IN ('accepted','sent','queued')";
            } elseif ($f['status'] === 'delivered') {
                $w[] = "l.status IN ('delivered','read')";
            } else {
                $w[] = 'l.status = :st';
                $p['st'] = (string) $f['status'];
            }
        }
        if (!empty($f['q'])) {
            $q = '%' . $f['q'] . '%';
            $w[] = '(l.to_number LIKE :q1 OR l.who_name LIKE :q2 OR l.pnr LIKE :q3 OR l.body LIKE :q4 OR l.label LIKE :q5)';
            $p += ['q1' => $q, 'q2' => $q, 'q3' => $q, 'q4' => $q, 'q5' => $q];
        }
        $sql = 'SELECT l.id, l.provider, l.channel, l.to_number, l.country, l.who_name, l.who_kind, l.booking_id, l.pnr,
                       l.purpose, l.label, l.category, l.body, l.media_url, l.status, l.error, l.cost, l.created_at,
                       l.status_at, a.full_name AS by_name
                  FROM msg_ledger l LEFT JOIN admins a ON a.id = l.admin_id'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
             . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . max(1, min(500, $limit))
             . (!empty($f['offset']) ? ' OFFSET ' . max(0, (int) $f['offset']) : '');
        return Database::fetchAll($sql, $p);
    }

    /** Last N days: per day sent / delivered / failed / estimated cost. */
    public static function daily(int $days = 14): array
    {
        $rows = Database::fetchAll(
            "SELECT DATE(created_at) AS d, COUNT(*) AS total,
                    SUM(status IN ('delivered','read')) AS delivered, SUM(status = 'failed') AS failed,
                    ROUND(SUM(cost), 2) AS cost
               FROM msg_ledger WHERE created_at >= CURDATE() - INTERVAL :n DAY
              GROUP BY d ORDER BY d",
            ['n' => max(1, $days - 1)]
        );
        $by = [];
        foreach ($rows as $r) {
            $by['d' . $r['d']] = $r;
        }
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime('-' . $i . ' day'));
            $r = $by['d' . $d] ?? ['total' => 0, 'delivered' => 0, 'failed' => 0, 'cost' => 0];
            $out[] = ['d' => $d, 'total' => (int) $r['total'], 'delivered' => (int) $r['delivered'],
                      'failed' => (int) $r['failed'], 'cost' => (float) $r['cost']];
        }
        return $out;
    }

    /* ----------------------------------------------------------------- */

    private static ?bool $mlPurpose = null;

    private static function messageLogsHasPurpose(): bool
    {
        if (self::$mlPurpose === null) {
            try {
                self::$mlPurpose = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'message_logs'
                        AND COLUMN_NAME IN ('purpose','admin_id','media_url')", [], 0) === 3;
            } catch (Throwable $e) {
                self::$mlPurpose = false;
            }
        }
        return self::$mlPurpose;
    }

    public static function kvGet(string $key, string $default = ''): string
    {
        try {
            $v = Database::scalar('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => 'wam', 'k' => $key]);
            return $v !== null ? (string) $v : $default;
        } catch (Throwable $e) {
            return $default;
        }
    }

    /** @return array<string,mixed> */
    public static function kvJson(string $key): array
    {
        $j = json_decode(self::kvGet($key, ''), true);
        return is_array($j) ? $j : [];
    }

    public static function kvSet(string $key, string $value): void
    {
        try {
            Database::run(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wam', :k, :v, 'wam')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = 'wam'",
                ['k' => $key, 'v' => $value]
            );
        } catch (Throwable $e) {
            // kv is a cache; the page still works without it
        }
    }
}
