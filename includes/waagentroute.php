<?php
/**
 * includes/waagentroute.php — every ticket reaches its passenger (10 Oct 2026).
 *
 * Owner: "ticket pathau main focus … credit ticket first customer deliver
 * garna matra … natra save hos". Paid WhatsApp credit is spent ONLY on the
 * customer's own ticket (the existing ladder: Meta free → Meta template →
 * AiSensy template, untouched). This file is the safety net behind it and
 * spends nothing:
 *
 *   1. booking.approved  → onConfirmed(): a `watching` row in delivery_log.
 *   2. cron (every min)  → tick(): after wa_route_grace_min, did the customer
 *      get the ticket (customerGot)? Yes → `delivered`, done. No → the
 *      route's agent is asked to hand it over from their own WhatsApp:
 *        - the seller of the ticket first, then agents assigned to the route
 *          (agent_route_permissions), then agents who sell on that route;
 *        - only `available` agents (busy / offline are skipped);
 *        - FREE only: the agent's Meta 24 h window must be open (they wrote
 *          to +91 87358 81507 in the last 24 h). Closed window → a web push
 *          nudge if the agent has one, else the next agent.
 *      The offer carries the ticket picture, the short link
 *      (includes/ticketlink.php) and a wa.me link that opens a chat with the
 *      passenger with the message already typed. [✅ Done] [⏳ Busy].
 *   3. no answer in wa_route_timeout_min (5) → the agent is marked busy for
 *      wa_route_busy_min and the next agent is asked.
 *   4. all agents tried → the admin number is told (free text in its window,
 *      else the admin panel bell + web push) and the passenger is sent the
 *      ticket link directly (free, if their window is open; otherwise it is
 *      waiting for their "hi").
 *   Every step is one row in delivery_log (ticket, agent tried, status, time).
 *
 * Agents are `admins` rows (role agent / counter); presence is
 * wa_agent_presence. Agents set it by writing "available" / "busy" /
 * "offline" to the Meta number. Never throws to its callers.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaAgentRoute
{
    /** Tests: fn(string $to, string $text, ?string $image, ?array $ui): array{success:bool,message_id:string,error:string} */
    public static ?Closure $transport = null;

    /** Tests: fn(int $adminId, array $payload): int (pushes sent) */
    public static ?Closure $pusher = null;

    private const YES  = ['ok', 'okay', 'ho', 'हो', 'done', 'sent', 'pathaye', 'pathaen', 'pathayen', 'pathaeko', 'pathaiyo', 'bhejdiya', 'bhej diya', 'bheja', 'haan', 'han', 'yes', 'thik', 'thik cha', '✅', '👍', 'पठाएँ', 'पठाएं', 'पठाइयो', 'भेज दिया'];
    private const NO   = ['busy', 'vyast', 'byast', 'व्यस्त', 'no', 'hoina', 'हैन', 'होइन', 'nahi', 'नहीं', 'pachi', 'पछि', 'later', 'sakdina', 'sakdina hai', 'cannot', "can't"];
    private const AVAIL   = ['available', 'online', 'on duty', 'ready', 'duty on', 'kaam suru', 'ma tayar', 'म तयार', 'तयार', 'उपलब्ध', 'upalabdha', 'free chu', 'free xu', 'free hu'];
    private const BUSYCMD = ['busy', 'vyast', 'byast', 'व्यस्त', 'busy chu', 'busy xu', 'busy hu'];
    private const OFFLINE = ['offline', 'off duty', 'duty off', 'chutti', 'chhutti', 'छुट्टी', 'बिदा', 'bida', 'kaam banda', 'band', 'off'];

    private static ?bool $ready = null;
    /** @var array<string,array<string,mixed>>|null intl => agent row (per request) */
    private static ?array $agentsByIntl = null;

    private function __construct() {}

    /* -----------------------------------------------------------------
     *  Switches
     * ----------------------------------------------------------------- */

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Database::fetch("SHOW TABLES LIKE 'delivery_log'") !== null
                    && Database::fetch("SHOW TABLES LIKE 'wa_agent_presence'") !== null;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function on(): bool
    {
        return self::ready() && Settings::getBool('wa_route_on', true);
    }

    /** Forget per-request caches (tests). */
    public static function reset(): void
    {
        self::$ready = null;
        self::$agentsByIntl = null;
    }

    private static function minutes(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, Settings::getInt($key, $default)));
    }

    /** The admin's WhatsApp as international digits. */
    public static function adminNumber(): string
    {
        $raw = trim(Settings::getString('wa_admin_number', ''));
        if ($raw === '') {
            $raw = Settings::getString('admin_whatsapp', '');
        }
        if ($raw === '') {
            $raw = Settings::getString('company_whatsapp', '');
        }
        return self::intl($raw);
    }

    /* -----------------------------------------------------------------
     *  Agents and presence
     * ----------------------------------------------------------------- */

    /** International digits (91… / 977…) or ''. */
    public static function intl(string $raw, string $hint = ''): string
    {
        $d = preg_replace('/\D+/', '', $raw) ?? '';
        if ($d === '') {
            return '';
        }
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        $d = ltrim($d, '0');
        if (strlen($d) === 10) {
            $h  = strtoupper(trim($hint));
            $cc = ($h === 'NP' || $h === '977') ? '977' : '91';
            $d = $cc . $d;   // India and Nepal share 10-digit mobiles: only the hint decides
        }
        return (strlen($d) >= 11 && strlen($d) <= 15) ? $d : '';
    }

    /**
     * Every active agent / counter with a usable WhatsApp, keyed by intl number.
     *
     * @return array<string,array{id:int,name:string,role:string,intl:string}>
     */
    public static function agents(): array
    {
        if (self::$agentsByIntl !== null) {
            return self::$agentsByIntl;
        }
        $out = [];
        try {
            $rows = Database::fetchAll(
                "SELECT a.id, a.full_name, a.username, a.role, a.phone, p.whatsapp, p.display_phone, p.country_code, p.counter_code
                   FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
                  WHERE a.is_active = 1 AND a.role IN ('agent','counter')"
            );
        } catch (Throwable $e) {
            try {
                $rows = Database::fetchAll(
                    "SELECT id, full_name, username, role, phone, NULL AS whatsapp, NULL AS display_phone, NULL AS country_code, NULL AS counter_code
                       FROM admins WHERE is_active = 1 AND role IN ('agent','counter')"
                );
            } catch (Throwable $e2) {
                $rows = [];
            }
        }
        foreach ($rows as $r) {
            $hint = (string) ($r['country_code'] ?? '');
            if ($hint === '' && (string) ($r['counter_code'] ?? '') !== '' && class_exists('CounterDesk')) {
                try {
                    $hint = (string) (CounterDesk::get((string) $r['counter_code'])['country'] ?? '');
                } catch (Throwable $ignored) {
                }
            }
            foreach (['whatsapp', 'display_phone', 'phone'] as $col) {
                $n = self::intl((string) ($r[$col] ?? ''), $hint);
                if ($n !== '') {
                    if (!isset($out[$n])) {   // a number two accounts share belongs to neither
                        $out[$n] = ['id' => (int) $r['id'], 'name' => trim((string) ($r['full_name'] ?: $r['username'])), 'role' => (string) $r['role'], 'intl' => $n];
                    } else {
                        $out[$n]['shared'] = true;
                    }
                    break;
                }
            }
        }
        foreach ($out as $k => $a) {
            if (!empty($a['shared'])) {
                unset($out[$k]);
            }
        }
        return self::$agentsByIntl = $out;
    }

    /** @return array{id:int,name:string,role:string,intl:string}|null */
    public static function agentByPhone(string $raw): ?array
    {
        $n = self::intl($raw);
        return $n !== '' ? (self::agents()[$n] ?? null) : null;
    }

    /** @return array{id:int,name:string,role:string,intl:string}|null */
    public static function agentById(int $id): ?array
    {
        foreach (self::agents() as $a) {
            if ($a['id'] === $id) {
                return $a;
            }
        }
        return null;
    }

    /** available | busy | offline — busy lapses by itself at busy_until. */
    public static function status(int $adminId): string
    {
        try {
            $r = Database::fetch('SELECT availability_status, busy_until FROM wa_agent_presence WHERE admin_id = :a', ['a' => $adminId]);
        } catch (Throwable $e) {
            return 'available';
        }
        if ($r === null) {
            return 'available';
        }
        $st = (string) $r['availability_status'];
        if ($st === 'busy' && (string) ($r['busy_until'] ?? '') !== '' && strtotime((string) $r['busy_until']) <= time()) {
            return 'available';
        }
        return in_array($st, ['available', 'busy', 'offline'], true) ? $st : 'available';
    }

    public static function setStatus(int $adminId, string $status, int $minutes = 0): void
    {
        if (!self::ready() || $adminId <= 0 || !in_array($status, ['available', 'busy', 'offline'], true)) {
            return;
        }
        $until = ($status === 'busy' && $minutes > 0) ? date('Y-m-d H:i:s', time() + $minutes * 60) : null;
        try {
            Database::run(
                'INSERT INTO wa_agent_presence (admin_id, availability_status, busy_until) VALUES (:a, :s, :u)
                 ON DUPLICATE KEY UPDATE availability_status = :s2, busy_until = :u2',
                ['a' => $adminId, 's' => $status, 'u' => $until, 's2' => $status, 'u2' => $until]
            );
        } catch (Throwable $e) {
            Logger::warning('agent presence update failed: ' . $e->getMessage(), ['admin' => $adminId], 'whatsapp');
        }
    }

    /** The agent wrote to us: last_active now; a lapsed busy goes back to available. */
    public static function touch(int $adminId): void
    {
        if (!self::ready() || $adminId <= 0) {
            return;
        }
        try {
            Database::run(
                "INSERT INTO wa_agent_presence (admin_id, availability_status, last_active) VALUES (:a, 'available', NOW())
                 ON DUPLICATE KEY UPDATE last_active = NOW(),
                   availability_status = IF(availability_status = 'busy' AND busy_until IS NOT NULL AND busy_until <= NOW(), 'available', availability_status)",
                ['a' => $adminId]
            );
        } catch (Throwable $e) {
            Logger::warning('agent touch failed: ' . $e->getMessage(), ['admin' => $adminId], 'whatsapp');
        }
    }

    /* -----------------------------------------------------------------
     *  Who should be asked
     * ----------------------------------------------------------------- */

    public static function routeIdOf(int $bookingId): int
    {
        try {
            return (int) Database::scalar(
                'SELECT s.route_id FROM booking_legs bl JOIN schedules s ON s.id = bl.schedule_id
                  WHERE bl.booking_id = :b ORDER BY bl.id ASC LIMIT 1',
                ['b' => $bookingId], 0
            );
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * The agents to ask for this ticket, best first: the seller, then agents
     * assigned to the route, then agents who sold on this route in the last
     * 30 days (most sales first). Busy / offline agents are left out.
     *
     * @param array<string,mixed> $b bookings row
     * @return list<array{id:int,name:string,role:string,intl:string}>
     */
    public static function candidates(array $b, bool $includeBusy = false): array
    {
        $byId = [];
        foreach (self::agents() as $a) {
            $byId[$a['id']] = $a;
        }
        if ($byId === []) {
            return [];
        }
        $ids = [];
        $sold = (int) ($b['sold_by_admin_id'] ?? 0);
        if ($sold > 0) {
            $ids[] = $sold;
        }
        $routeId = self::routeIdOf((int) ($b['id'] ?? 0));
        if ($routeId > 0) {
            try {
                foreach (Database::fetchAll('SELECT admin_id FROM agent_route_permissions WHERE route_id = :r ORDER BY admin_id', ['r' => $routeId]) as $r) {
                    $ids[] = (int) $r['admin_id'];
                }
            } catch (Throwable $ignored) {
            }
            try {
                foreach (Database::fetchAll(
                    "SELECT b.sold_by_admin_id AS id, COUNT(*) n
                       FROM bookings b
                       JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                       JOIN schedules s ON s.id = bl.schedule_id
                      WHERE s.route_id = :r AND b.sold_by_admin_id IS NOT NULL
                        AND b.status IN ('confirmed','completed') AND b.created_at >= NOW() - INTERVAL 30 DAY
                      GROUP BY b.sold_by_admin_id ORDER BY n DESC LIMIT 12",
                    ['r' => $routeId]
                ) as $r) {
                    $ids[] = (int) $r['id'];
                }
            } catch (Throwable $ignored) {
            }
        }
        $out = [];
        $seen = [];
        foreach ($ids as $id) {
            if ($id <= 0 || isset($seen[$id]) || !isset($byId[$id])) {
                continue;
            }
            $seen[$id] = true;
            $st = self::status($id);
            if (!$includeBusy && $st !== 'available') {
                continue;
            }
            $out[] = $byId[$id];
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     *  Did the customer get the ticket?
     * ----------------------------------------------------------------- */

    /**
     * true = it reached the customer (or their second number); false = it
     * did not; null = a paid send is still in flight, ask again shortly.
     */
    public static function customerGot(int $bookingId): ?bool
    {
        $unknown = false;
        try {
            if (Database::fetch("SHOW TABLES LIKE 'wa_ticket_queue'") !== null) {
                foreach (Database::fetchAll(
                    "SELECT status, via, sent_at FROM wa_ticket_queue WHERE booking_id = :b AND role IN ('customer','alt')",
                    ['b' => $bookingId]
                ) as $q) {
                    $st  = (string) $q['status'];
                    $via = (string) ($q['via'] ?? '');
                    if ($st === 'delivered' || $st === 'read') {
                        return true;
                    }
                    if ($st === 'sent' && str_starts_with($via, 'meta')) {
                        return true;      // free inside an open window; a later failure flips the row
                    }
                    if ($st === 'sent' && (string) ($q['sent_at'] ?? '') !== '' && strtotime((string) $q['sent_at']) > time() - 900) {
                        $unknown = true;  // a template accepted minutes ago: the status cron has not looked yet
                    }
                }
            }
            $ok = Database::exists(
                "SELECT 1 FROM message_logs
                  WHERE booking_id = :b AND channel = 'whatsapp' AND provider = 'cloud_api' AND status = 'sent'
                    AND (purpose IS NULL OR purpose IN ('ticket','ticket_resend','ticket_update'))",
                ['b' => $bookingId]
            );
            if ($ok) {
                return true;
            }
        } catch (Throwable $e) {
            return null;
        }
        return $unknown ? null : false;
    }

    /* -----------------------------------------------------------------
     *  Lifecycle
     * ----------------------------------------------------------------- */

    /** booking.approved: start watching this ticket (once per booking). */
    public static function onConfirmed(array $b): void
    {
        if (!self::on()) {
            return;
        }
        try {
            $bid = (int) ($b['id'] ?? 0);
            // The event may carry the row as it was before the confirmation: read it fresh.
            $b = $bid > 0 ? (Database::fetch('SELECT id, pnr, status FROM bookings WHERE id = :b', ['b' => $bid]) ?? []) : [];
            if ($b === [] || !in_array((string) $b['status'], ['confirmed', 'completed'], true)) {
                return;
            }
            if (Database::exists('SELECT 1 FROM delivery_log WHERE booking_id = :b', ['b' => $bid])) {
                return;
            }
            self::log($bid, (string) ($b['pnr'] ?? ''), null, 'watch', 'watching', 'waiting for the customer ticket',
                date('Y-m-d H:i:s', time() + 60 * self::minutes('wa_route_grace_min', 3, 1, 60)));
        } catch (Throwable $e) {
            Logger::warning('agent route watch failed: ' . $e->getMessage(), ['pnr' => (string) ($b['pnr'] ?? '')], 'whatsapp');
        }
    }

    /**
     * Cron, every minute. Returns counters for the cron log.
     *
     * @return array<string,int>
     */
    public static function tick(int $limit = 40): array
    {
        $out = ['watched' => 0, 'delivered' => 0, 'offered' => 0, 'timeouts' => 0, 'exhausted' => 0, 'lapsed_busy' => 0];
        if (!self::on()) {
            return $out;
        }
        try {
            $out['lapsed_busy'] = Database::run(
                "UPDATE wa_agent_presence SET availability_status = 'available', busy_until = NULL
                  WHERE availability_status = 'busy' AND busy_until IS NOT NULL AND busy_until <= NOW()"
            );
            $rows = Database::fetchAll(
                "SELECT * FROM delivery_log WHERE closed = 0 AND deadline_at IS NOT NULL AND deadline_at <= NOW()
                  ORDER BY deadline_at ASC LIMIT " . max(1, $limit)
            );
            foreach ($rows as $row) {
                $out['watched']++;
                $r = self::step($row);
                if (isset($out[$r])) {
                    $out[$r]++;
                }
            }
        } catch (Throwable $e) {
            Logger::warning('agent route tick failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        return $out;
    }

    /** One due row. Returns what happened (delivered / offered / exhausted / timeouts / ''). */
    private static function step(array $row): string
    {
        $id  = (int) $row['id'];
        $bid = (int) $row['booking_id'];
        $b   = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $bid]);
        if ($b === null || !in_array((string) $b['status'], ['confirmed', 'completed'], true) || self::travelled($bid)) {
            self::close($id, 'stopped: booking not live / travelled');
            return '';
        }
        $got = self::customerGot($bid);

        if ((string) $row['status'] === 'watching') {
            if ($got === true) {
                self::close($id, 'customer got the ticket', 'delivered');
                return 'delivered';
            }
            if ($got === null && strtotime((string) $row['created_at']) > time() - 1200) {
                Database::run('UPDATE delivery_log SET deadline_at = NOW() + INTERVAL 2 MINUTE WHERE id = :i', ['i' => $id]);
                return '';
            }
            self::close($id, 'customer did not get it - asking the route agent');
            return self::offerNext($b) === 'offered' ? 'offered' : 'exhausted';
        }

        if ((string) $row['status'] === 'offered') {
            // No answer in time: busy for a while, next agent.
            self::close($id, 'no answer in ' . self::minutes('wa_route_timeout_min', 5, 1, 60) . ' min', 'timeout');
            if ((int) ($row['agent_id'] ?? 0) > 0) {
                self::setStatus((int) $row['agent_id'], 'busy', self::minutes('wa_route_busy_min', 60, 5, 1440));
            }
            if ($got === true) {
                self::log($bid, (string) $b['pnr'], null, 'watch', 'delivered', 'customer got the ticket meanwhile', null, true);
                return 'timeouts';
            }
            self::offerNext($b);
            return 'timeouts';
        }

        self::close($id, 'closed (unknown state)');
        return '';
    }

    private static function travelled(int $bid): bool
    {
        try {
            $d = (string) Database::scalar(
                "SELECT travel_date FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound' ORDER BY id ASC LIMIT 1",
                ['b' => $bid], ''
            );
            return $d !== '' && $d < date('Y-m-d', time() - 86400);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Ask the next agent who has not been asked for this ticket yet.
     * Returns 'offered' or 'exhausted'.
     */
    public static function offerNext(array $b, array $skipIds = []): string
    {
        $bid = (int) $b['id'];
        $pnr = (string) $b['pnr'];
        $tried = [];
        try {
            foreach (Database::fetchAll(
                "SELECT DISTINCT agent_id FROM delivery_log WHERE booking_id = :b AND agent_id IS NOT NULL",
                ['b' => $bid]
            ) as $r) {
                $tried[(int) $r['agent_id']] = true;
            }
        } catch (Throwable $ignored) {
        }
        foreach ($skipIds as $sid) {
            $tried[(int) $sid] = true;
        }
        $asked = (int) Database::scalar(
            "SELECT COUNT(*) FROM delivery_log WHERE booking_id = :b AND status IN ('offered','timeout','declined','accepted')",
            ['b' => $bid], 0
        );
        $max = self::minutes('wa_route_max_agents', 4, 1, 20);

        foreach (self::candidates($b) as $a) {
            if (isset($tried[$a['id']])) {
                continue;
            }
            if ($asked >= $max) {
                break;
            }
            $tried[$a['id']] = true;
            $deadline = date('Y-m-d H:i:s', time() + 60 * self::minutes('wa_route_timeout_min', 5, 1, 60));

            if (self::windowOpen($a['intl'])) {
                $offerId = self::log($bid, $pnr, $a['id'], 'wa_free', 'offered', 'asked on WhatsApp (free window)', $deadline, false);
                $r = self::sendOffer($a, $b, $offerId);
                if ($r['success']) {
                    Database::run('UPDATE delivery_log SET wamid = :w WHERE id = :i', ['w' => $r['message_id'] !== '' ? $r['message_id'] : null, 'i' => $offerId]);
                    Database::run('UPDATE wa_agent_presence SET last_offer_at = NOW() WHERE admin_id = :a', ['a' => $a['id']]);
                    return 'offered';
                }
                self::close($offerId, 'send refused: ' . mb_substr($r['error'], 0, 150), 'skipped');
                continue;
            }
            // Window closed: a free web push asks them to open WhatsApp.
            if (self::push($a['id'], $b) > 0) {
                self::log($bid, $pnr, $a['id'], 'push', 'offered', 'window closed - web push sent, waiting for the agent to write', $deadline, false);
                return 'offered';
            }
            self::log($bid, $pnr, $a['id'], 'wa_free', 'skipped', 'window closed, no web push (agent has not written to the Meta number in 24 h)', null, true);
        }
        self::exhaust($b);
        return 'exhausted';
    }

    /**
     * Nobody could take it: the admin is told and the passenger gets the
     * link directly — both free, else the admin panel bell.
     */
    public static function exhaust(array $b): void
    {
        $bid  = (int) $b['id'];
        $pnr  = (string) $b['pnr'];
        if (Database::exists("SELECT 1 FROM delivery_log WHERE booking_id = :b AND status = 'exhausted'", ['b' => $bid])) {
            return;   // once per ticket
        }
        $f     = self::facts($b);
        $link  = self::shortLink($bid);
        $tried = (int) Database::scalar("SELECT COUNT(DISTINCT agent_id) FROM delivery_log WHERE booking_id = :b AND agent_id IS NOT NULL", ['b' => $bid], 0);
        self::log($bid, $pnr, null, 'watch', 'exhausted', $tried . ' agent(s) tried', null, true);

        // 1. The passenger, directly (free in their window).
        $cust = self::customerIntl($b);
        $custOk = false;
        if ($cust !== '') {
            $r = self::send($cust, self::customerText($b, $f, $link), $f['img'], null, 'ticket', $bid);
            $custOk = $r['success'];
            self::log($bid, $pnr, null, 'customer', 'customer_direct',
                $custOk ? 'ticket link sent to the passenger (free window)' : 'passenger window closed - ticket waits for their "hi"', null, true);
        } else {
            self::log($bid, $pnr, null, 'customer', 'customer_direct', 'no usable passenger number on the booking', null, true);
        }

        // 2. The admin number: free text in its window, else the panel bell (+ push).
        $text = "⚠️ टिकट यात्रुसम्म पुगेन · Ticket not delivered\n"
            . 'PNR: ' . $pnr . "\n"
            . 'यात्रु: ' . ($f['name'] !== '' ? $f['name'] : '-') . ' · ' . ($f['phone'] !== '' ? $f['phone'] : 'नम्बर छैन') . "\n"
            . ($f['trip'] !== '' ? $f['trip'] . "\n" : '')
            . 'एजेन्ट: ' . ($tried > 0 ? $tried . ' जनालाई सोधियो, जवाफ आएन' : 'यो रुटमा उपलब्ध एजेन्ट छैन') . "\n"
            . ($custOk ? "यात्रुलाई लिंक पठाइयो ✅\n" : "यात्रुको WhatsApp window बन्द — फोन गर्नुहोस्।\n")
            . ($link !== '' ? 'टिकट: ' . $link . "\n" : '')
            . ($f['passengerWa'] !== '' ? 'यात्रुलाई पठाउन: ' . $f['passengerWa'] : '');
        $admin = self::adminNumber();
        $adminOk = false;
        if ($admin !== '') {
            $adminOk = self::send($admin, $text, null, null, 'route_admin', $bid)['success'];
        }
        if (!$adminOk && Settings::getBool('wa_route_admin_tpl_on', false) && $admin !== '') {
            // Owner switch only: the approved ticket_delivery_failed template (paid).
            try {
                require_once INCLUDE_PATH . '/waroute.php';
                $mt = WaRoute::metaTemplate($admin, 'ticket_delivery_failed', 'en',
                    ['1' => $pnr, '2' => $f['name'] !== '' ? $f['name'] : '-', '3' => $f['phone'] !== '' ? $f['phone'] : '-', '4' => $tried > 0 ? 'no agent answered' : 'no agent on this route'],
                    null, 'route_admin', ['bookingId' => $bid]);
                $adminOk = $mt !== null;
            } catch (Throwable $ignored) {
            }
        }
        if (!$adminOk) {
            try {
                require_once INCLUDE_PATH . '/adminalerts.php';
                AdminAlerts::push('wa_route', 'Ticket not delivered · ' . $pnr,
                    ($f['name'] !== '' ? $f['name'] : 'Passenger') . ($f['phone'] !== '' ? ' · ' . $f['phone'] : '') . ' — no agent took it. Call the passenger.',
                    '/admin/wa-routing.php', '📵', 'wa_route:' . $bid);
            } catch (Throwable $e) {
                Logger::warning('route admin alert failed: ' . $e->getMessage(), ['pnr' => $pnr], 'whatsapp');
            }
            self::pushSuperadmins(['title' => '📵 Ticket not delivered · ' . $pnr, 'body' => ($f['name'] ?: 'Passenger') . ' — call the passenger', 'url' => appUrl('admin/wa-routing.php'), 'tag' => 'route-' . $bid]);
        }
        self::log($bid, $pnr, null, 'admin', 'admin_notified', $adminOk ? 'admin WhatsApp (free)' : 'admin panel bell (WhatsApp window closed)', null, true);
    }

    /**
     * "Change agent" from the passenger (includes/waedit.php): the next
     * agent is asked, never one who already had it. Returns the outcome.
     */
    public static function changeAgent(int $bookingId, string $by): string
    {
        if (!self::on()) {
            return 'off';
        }
        $b = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $bookingId]);
        if ($b === null) {
            return 'missing';
        }
        $prev = [];
        foreach (Database::fetchAll("SELECT DISTINCT agent_id FROM delivery_log WHERE booking_id = :b AND agent_id IS NOT NULL", ['b' => $bookingId]) as $r) {
            $prev[] = (int) $r['agent_id'];
        }
        Database::run("UPDATE delivery_log SET closed = 1, deadline_at = NULL WHERE booking_id = :b AND closed = 0", ['b' => $bookingId]);
        self::log($bookingId, (string) $b['pnr'], null, 'watch', 'changed', 'passenger asked for another agent (' . mb_substr($by, 0, 40) . ')', null, true);
        // A new round: the old "exhausted" mark must not stop it.
        Database::run("UPDATE delivery_log SET status = 'exhausted_old' WHERE booking_id = :b AND status = 'exhausted'", ['b' => $bookingId]);
        Database::run("UPDATE delivery_log SET status = CONCAT(status, '_old') WHERE booking_id = :b AND status IN ('offered','timeout','declined','accepted')", ['b' => $bookingId]);
        return self::offerNext($b, $prev);
    }

    /** The agent who accepted this ticket (for the passenger), or null. */
    public static function acceptedAgent(int $bookingId): ?array
    {
        try {
            $id = (int) Database::scalar(
                "SELECT agent_id FROM delivery_log WHERE booking_id = :b AND status = 'accepted' ORDER BY id DESC LIMIT 1",
                ['b' => $bookingId], 0
            );
        } catch (Throwable $e) {
            return null;
        }
        return $id > 0 ? self::agentById($id) : null;
    }

    /* -----------------------------------------------------------------
     *  Inbound: an agent writes to the Meta number
     * ----------------------------------------------------------------- */

    /**
     * Agent replies and presence words. Null when this is not an agent or
     * nothing here applies — the rest of the bot answers then.
     *
     * @return array{text:string, media?:?string, ui?:?array}|null
     */
    public static function handle(string $fromRaw, string $body): ?array
    {
        if (!self::on()) {
            return null;
        }
        $agent = self::agentByPhone($fromRaw);
        if ($agent === null) {
            return null;
        }
        self::touch($agent['id']);
        $t = self::norm($body);
        if ($t === '') {
            return null;
        }

        // Buttons: "rt ok 12" / "rt busy 12" / "rt avail".
        if (preg_match('/^rt\s+(ok|busy|avail|off)(?:\s+(\d+))?$/', $t, $m) === 1) {
            $offer = isset($m[2]) ? self::openOffer($agent['id'], (int) $m[2]) : self::openOffer($agent['id']);
            return match ($m[1]) {
                'ok'    => $offer !== null ? self::accept($offer, $agent) : self::say('यो टिकट अब तपाईंसँग छैन (समय सकियो वा अरूले लिए)। धन्यवाद! 🙏'),
                'busy'  => $offer !== null ? self::decline($offer, $agent) : self::presence($agent, 'busy'),
                'avail' => self::presence($agent, 'available'),
                default => self::presence($agent, 'offline'),
            };
        }

        $offer = self::openOffer($agent['id']);
        // A push-offer agent just opened their window: hand them the ticket now.
        if ($offer !== null && (string) $offer['channel'] === 'push') {
            $b = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => (int) $offer['booking_id']]);
            if ($b !== null) {
                Database::run("UPDATE delivery_log SET channel = 'wa_free', deadline_at = NOW() + INTERVAL :m MINUTE, detail = 'agent opened WhatsApp after the push' WHERE id = :i",
                    ['m' => self::minutes('wa_route_timeout_min', 5, 1, 60), 'i' => (int) $offer['id']]);
                return self::offerReply($agent, $b, (int) $offer['id']);
            }
        }
        if ($offer !== null && self::isWord($t, self::YES)) {
            return self::accept($offer, $agent);
        }
        if ($offer !== null && self::isWord($t, self::NO)) {
            return self::decline($offer, $agent);
        }
        if (self::isWord($t, self::AVAIL)) {
            return self::presence($agent, 'available');
        }
        if (self::isWord($t, self::BUSYCMD)) {
            return self::presence($agent, 'busy');
        }
        if (self::isWord($t, self::OFFLINE)) {
            return self::presence($agent, 'offline');
        }
        if (in_array($t, ['mero status', 'my status', 'routing', 'mero kaam', 'my offers'], true)) {
            return self::myStatus($agent);
        }
        return null;
    }

    /** The open offer for this agent (optionally that id). */
    private static function openOffer(int $agentId, int $offerId = 0): ?array
    {
        try {
            return Database::fetch(
                "SELECT * FROM delivery_log WHERE agent_id = :a AND status = 'offered' AND closed = 0"
                . ($offerId > 0 ? ' AND id = :i' : '') . ' ORDER BY id DESC LIMIT 1',
                $offerId > 0 ? ['a' => $agentId, 'i' => $offerId] : ['a' => $agentId]
            );
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function accept(array $offer, array $agent): array
    {
        $bid = (int) $offer['booking_id'];
        Database::run("UPDATE delivery_log SET status = 'accepted', closed = 1, deadline_at = NULL, detail = 'agent handed the ticket over' WHERE id = :i", ['i' => (int) $offer['id']]);
        self::setStatus($agent['id'], 'available');
        $b = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $bid]);
        // Tell the passenger who is looking after them — free, only if their window is open.
        if ($b !== null) {
            $cust = self::customerIntl($b);
            if ($cust !== '' && self::windowOpen($cust)) {
                $wa = 'https://wa.me/' . $agent['intl'];
                self::send($cust, "🧑‍💼 तपाईंको टिकट " . (string) $b['pnr'] . " को लागि एजेन्ट: " . $agent['name'] . "\nकुनै कुरा भए: " . $wa, null, null, 'route_customer', $bid);
            }
        }
        return self::say("✅ धन्यवाद " . self::first($agent['name']) . "! " . ($b !== null ? (string) $b['pnr'] . ' ' : '') . "पुर्‍याएको मानियो।\nThank you — marked as delivered.");
    }

    private static function decline(array $offer, array $agent): array
    {
        Database::run("UPDATE delivery_log SET status = 'declined', closed = 1, deadline_at = NULL, detail = 'agent said busy' WHERE id = :i", ['i' => (int) $offer['id']]);
        self::setStatus($agent['id'], 'busy', self::minutes('wa_route_busy_min', 60, 5, 1440));
        $b = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => (int) $offer['booking_id']]);
        if ($b !== null) {
            self::offerNext($b);
        }
        return self::say("⏳ ठिक छ, अर्को एजेन्टलाई पठाइयो। तपाईं " . self::minutes('wa_route_busy_min', 60, 5, 1440) . " मिनेट busy रहनुहुन्छ।\n\"available\" लेखे फेरि टिकट आउन थाल्छ।");
    }

    private static function presence(array $agent, string $st): array
    {
        self::setStatus($agent['id'], $st, $st === 'busy' ? self::minutes('wa_route_busy_min', 60, 5, 1440) : 0);
        $ui = ['kind' => 'buttons', 'buttons' => [
            ['id' => 'shg:rt avail', 'title' => '✅ Available'],
            ['id' => 'shg:rt busy', 'title' => '⏳ Busy'],
            ['id' => 'shg:rt off', 'title' => '⛔ Offline'],
        ]];
        return match ($st) {
            'available' => self::say("✅ " . self::first($agent['name']) . " — तपाईं available हुनुभयो। तपाईंको रुटका टिकट यहीँ आउँछन्।\nYou are available: tickets for your route will come here.", $ui),
            'busy'      => self::say("⏳ Busy — " . self::minutes('wa_route_busy_min', 60, 5, 1440) . " मिनेटसम्म टिकट अरू एजेन्टलाई जान्छ।", $ui),
            default     => self::say("⛔ Offline — तपाईंलाई टिकट पठाइने छैन। फेरि सुरु गर्न \"available\" लेख्नुहोस्।", $ui),
        };
    }

    private static function myStatus(array $agent): array
    {
        $st = self::status($agent['id']);
        $open = (int) Database::scalar("SELECT COUNT(*) FROM delivery_log WHERE agent_id = :a AND status = 'offered' AND closed = 0", ['a' => $agent['id']], 0);
        $done = (int) Database::scalar("SELECT COUNT(*) FROM delivery_log WHERE agent_id = :a AND status = 'accepted' AND created_at >= CURDATE()", ['a' => $agent['id']], 0);
        return self::say("📋 " . $agent['name'] . "\nस्थिति: " . $st . "\nखुला टिकट: " . $open . "\nआज पुर्‍याएको: " . $done
            . "\n\nलेख्नुहोस्: available · busy · offline");
    }

    /* -----------------------------------------------------------------
     *  Good morning (cron/wa-good-morning.php, 6 AM)
     * ----------------------------------------------------------------- */

    /**
     * Free good-morning to every agent whose route runs today; a closed
     * window gets a web push instead (the paid template only with
     * wa_good_morning_tpl_on). Returns counters.
     *
     * @return array<string,int>
     */
    public static function goodMorning(): array
    {
        $out = ['agents' => 0, 'sent' => 0, 'pushed' => 0, 'template' => 0, 'skipped' => 0];
        if (!self::ready() || !Settings::getBool('wa_good_morning_on', true)) {
            return $out;
        }
        $today = date('Y-m-d');
        // Today's buses: route, departure, seats sold.
        $trips = [];
        try {
            foreach (Database::fetchAll(
                "SELECT s.id, s.route_id, r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) AS dep
                   FROM schedules s JOIN routes r ON r.id = s.route_id
                  WHERE s.travel_date = :d AND r.is_active = 1 AND s.cancelled_at IS NULL",
                ['d' => $today]
            ) as $s) {
                $sold = (int) Database::scalar(
                    "SELECT COALESCE(SUM(bl.seat_count),0) FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
                      WHERE bl.schedule_id = :s AND b.status IN ('confirmed','completed','pending')",
                    ['s' => (int) $s['id']], 0
                );
                $trips[(int) $s['route_id']][] = ['route' => $s['from_city'] . ' → ' . $s['to_city'], 'dep' => substr((string) $s['dep'], 0, 5), 'sold' => $sold];
            }
        } catch (Throwable $e) {
            Logger::warning('good morning trips failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        if ($trips === []) {
            return $out;
        }
        // Agents by route: assignments, else who sold on that route this month.
        $byAgent = [];
        foreach (array_keys($trips) as $rid) {
            $ids = [];
            try {
                foreach (Database::fetchAll('SELECT admin_id FROM agent_route_permissions WHERE route_id = :r', ['r' => $rid]) as $r) {
                    $ids[] = (int) $r['admin_id'];
                }
                if ($ids === []) {
                    foreach (Database::fetchAll(
                        "SELECT DISTINCT b.sold_by_admin_id AS id FROM bookings b
                           JOIN booking_legs bl ON bl.booking_id = b.id JOIN schedules s ON s.id = bl.schedule_id
                          WHERE s.route_id = :r AND b.sold_by_admin_id IS NOT NULL AND b.created_at >= NOW() - INTERVAL 30 DAY",
                        ['r' => $rid]
                    ) as $r) {
                        $ids[] = (int) $r['id'];
                    }
                }
            } catch (Throwable $ignored) {
            }
            foreach ($ids as $id) {
                $byAgent[$id][$rid] = true;
            }
        }
        $ui = ['kind' => 'buttons', 'buttons' => [
            ['id' => 'shg:rt avail', 'title' => '✅ Available'],
            ['id' => 'shg:rt busy', 'title' => '⏳ Busy'],
            ['id' => 'shg:rt off', 'title' => '⛔ Offline'],
        ]];
        foreach ($byAgent as $id => $routes) {
            $a = self::agentById((int) $id);
            if ($a === null || self::status($a['id']) === 'offline') {
                continue;
            }
            if (!Security::rateLimit('wa_gm', $a['intl'] . date('Ymd'), 1, 86400)) {
                continue;   // once a day
            }
            $out['agents']++;
            $lines = [];
            foreach (array_keys($routes) as $rid) {
                foreach ($trips[$rid] ?? [] as $tr) {
                    $lines[] = '🚌 ' . $tr['route'] . ($tr['dep'] !== '' ? ' · ' . $tr['dep'] : '') . ' · बुक: ' . $tr['sold'] . ' सिट';
                }
            }
            $text = "🌅 शुभ प्रभात " . self::first($a['name']) . "!\nआजका बस:\n" . implode("\n", array_slice($lines, 0, 6))
                . "\n\nआज टिकट पुर्‍याउन तयार हुनुहुन्छ? तल थिच्नुहोस्।";
            if (self::windowOpen($a['intl'])) {
                $r = self::send($a['intl'], $text, null, $ui, 'good_morning', null);
                if ($r['success']) {
                    $out['sent']++;
                    continue;
                }
            }
            if (Settings::getBool('wa_good_morning_tpl_on', false)) {
                try {
                    require_once INCLUDE_PATH . '/waroute.php';
                    $first = $trips[array_key_first($routes)][0] ?? ['route' => '-', 'dep' => '-', 'sold' => 0];
                    if (WaRoute::metaTemplate($a['intl'], 'good_morning_agent', 'en',
                        ['1' => self::first($a['name']), '2' => $first['route'], '3' => $first['dep'] !== '' ? $first['dep'] : '-', '4' => (string) $first['sold']],
                        null, 'good_morning') !== null) {
                        $out['template']++;
                        continue;
                    }
                } catch (Throwable $ignored) {
                }
            }
            if (self::push($a['id'], null, "🌅 Good morning — " . count($lines) . " bus today. Tap to go available on WhatsApp.") > 0) {
                $out['pushed']++;
                continue;
            }
            $out['skipped']++;
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     *  Admin page numbers
     * ----------------------------------------------------------------- */

    /** @return array<string,mixed> */
    public static function stats(): array
    {
        $s = ['today' => [], 'agents' => [], 'open' => 0];
        if (!self::ready()) {
            return $s;
        }
        try {
            foreach (Database::fetchAll("SELECT status, COUNT(*) n FROM delivery_log WHERE created_at >= CURDATE() GROUP BY status") as $r) {
                $s['today'][(string) $r['status']] = (int) $r['n'];
            }
            $s['open'] = (int) Database::scalar('SELECT COUNT(*) FROM delivery_log WHERE closed = 0', [], 0);
            foreach (self::agents() as $a) {
                $p = Database::fetch('SELECT last_active, last_offer_at FROM wa_agent_presence WHERE admin_id = :a', ['a' => $a['id']]);
                $s['agents'][] = $a + [
                    'status'      => self::status($a['id']),
                    'window'      => self::windowOpen($a['intl']),
                    'last_active' => (string) ($p['last_active'] ?? ''),
                    'accepted7'   => (int) Database::scalar("SELECT COUNT(*) FROM delivery_log WHERE agent_id = :a AND status = 'accepted' AND created_at >= NOW() - INTERVAL 7 DAY", ['a' => $a['id']], 0),
                    'timeouts7'   => (int) Database::scalar("SELECT COUNT(*) FROM delivery_log WHERE agent_id = :a AND status = 'timeout' AND created_at >= NOW() - INTERVAL 7 DAY", ['a' => $a['id']], 0),
                ];
            }
        } catch (Throwable $e) {
            Logger::warning('route stats failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        return $s;
    }

    /* -----------------------------------------------------------------
     *  Sending (FREE only)
     * ----------------------------------------------------------------- */

    public static function windowOpen(string $intl): bool
    {
        if ($intl === '') {
            return false;
        }
        try {
            require_once INCLUDE_PATH . '/waroute.php';
            return WaRoute::metaWindowOpen($intl);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * A free message on the Meta number — never a template. With $ui the
     * tappable version is tried first; the plain words are the fallback.
     *
     * @return array{success:bool,message_id:string,error:string}
     */
    public static function send(string $to, string $text, ?string $image, ?array $ui, string $purpose, ?int $bookingId): array
    {
        $no = ['success' => false, 'message_id' => '', 'error' => 'window closed'];
        if ($to === '' || !self::windowOpen($to)) {
            return $no;
        }
        // A test that stubs WaRoute but not this class never reaches the real Graph API.
        $stubbedRoute = self::$transport === null && WaRoute::$transport !== null;
        if ($ui !== null && !$stubbedRoute && Settings::getBool('wa_buttons_on', true) && mb_strlen($text) <= 1024) {
            try {
                if (self::$transport !== null) {
                    $r = (self::$transport)($to, $text, $image, $ui);
                } else {
                    require_once ROOT_PATH . '/whatsapp/config.php';
                    require_once ROOT_PATH . '/whatsapp/api.php';
                    $r = sendWhatsAppInteractive($to, $text, $ui, $image);
                }
            } catch (Throwable $e) {
                $r = ['success' => false, 'message_id' => '', 'error' => $e->getMessage()];
            }
            if (!empty($r['success'])) {
                if (class_exists('Notify')) {
                    try {
                        Notify::logOutbound($to, $text, 'sent', ['provider' => 'cloud_api', 'bookingId' => $bookingId,
                            'sid' => (string) ($r['message_id'] ?? ''), 'purpose' => $purpose, 'media_url' => $image]);
                    } catch (Throwable $ignored) {
                    }
                }
                return ['success' => true, 'message_id' => (string) ($r['message_id'] ?? ''), 'error' => ''];
            }
        }
        if (self::$transport !== null) {
            $r = (self::$transport)($to, $text, $image, null);
            return ['success' => (bool) ($r['success'] ?? false), 'message_id' => (string) ($r['message_id'] ?? ''), 'error' => (string) ($r['error'] ?? '')];
        }
        try {
            require_once INCLUDE_PATH . '/waroute.php';
            $r = WaRoute::freeFirst($to, $text, $image, $purpose, ['bookingId' => $bookingId]);
        } catch (Throwable $e) {
            $r = null;
        }
        return $r !== null && $r['success']
            ? ['success' => true, 'message_id' => (string) $r['message_id'], 'error' => '']
            : ['success' => false, 'message_id' => '', 'error' => 'free send refused'];
    }

    /** @return array{success:bool,message_id:string,error:string} */
    private static function sendOffer(array $agent, array $b, int $offerId): array
    {
        $msg = self::offerReply($agent, $b, $offerId);
        return self::send($agent['intl'], $msg['text'], $msg['media'] ?? null, $msg['ui'] ?? null, 'route_offer', (int) $b['id']);
    }

    /** The offer packet: facts, the passenger link, the buttons. */
    private static function offerReply(array $agent, array $b, int $offerId): array
    {
        $f    = self::facts($b);
        $link = self::shortLink((int) $b['id']);
        $mins = self::minutes('wa_route_timeout_min', 5, 1, 60);
        $text = "🎫 टिकट यात्रुसम्म पुर्‍याउनुहोस् · Please deliver\n"
            . 'PNR: ' . (string) $b['pnr'] . "\n"
            . 'यात्रु: ' . ($f['name'] !== '' ? $f['name'] : '-') . ($f['phone'] !== '' ? ' · ' . $f['phone'] : '') . "\n"
            . ($f['trip'] !== '' ? $f['trip'] . "\n" : '')
            . ($f['seats'] !== '' ? 'सिट: ' . $f['seats'] . "\n" : '')
            . "\n"
            . ($f['passengerWa'] !== ''
                ? "1️⃣ यात्रुलाई पठाउन यो थिच्नुहोस् (सन्देश तयार छ):\n" . $f['passengerWa'] . "\n"
                : "1️⃣ यात्रुको WhatsApp नम्बर छैन — फोन गरेर यो लिंक दिनुहोस्:\n")
            . ($link !== '' ? 'टिकट लिंक: ' . $link . "\n" : '')
            . "2️⃣ पठाएपछि ✅ थिच्नुहोस्। " . $mins . " मिनेटमा जवाफ नआए अर्को एजेन्टलाई जान्छ।";
        $ui = ['kind' => 'buttons', 'buttons' => [
            ['id' => 'shg:rt ok ' . $offerId, 'title' => '✅ पठाएँ · Done'],
            ['id' => 'shg:rt busy ' . $offerId, 'title' => '⏳ व्यस्त · Busy'],
        ]];
        if (mb_strlen($text) > 1000) {
            $text = mb_substr($text, 0, 1000);
        }
        return ['text' => $text, 'media' => $f['img'] !== '' ? $f['img'] : null, 'ui' => $ui];
    }

    /** What the passenger receives (agent-forwarded or direct). */
    private static function customerText(array $b, array $f, string $link): string
    {
        $co = Settings::companyFor((string) ($b['counter_code'] ?? ''), (string) ($b['entity'] ?? ''));
        return '✅ ' . (string) $co['name'] . " — तपाईंको टिकट · Your ticket\n"
            . 'PNR: ' . (string) $b['pnr'] . "\n"
            . ($f['trip'] !== '' ? $f['trip'] . "\n" : '')
            . ($f['seats'] !== '' ? 'सिट / Seat: ' . $f['seats'] . "\n" : '')
            . ($link !== '' ? 'टिकट / Ticket: ' . $link . "\n" : '')
            . 'सहयोग / Help: ' . (string) $co['phone'];
    }

    /**
     * Facts every message here needs.
     *
     * @return array{name:string,phone:string,trip:string,seats:string,img:string,passengerWa:string}
     */
    private static function facts(array $b): array
    {
        $bid = (int) $b['id'];
        $f = ['name' => '', 'phone' => '', 'trip' => '', 'seats' => '', 'img' => '', 'passengerWa' => ''];
        try {
            if (!class_exists('AiSensyAPI')) {
                require_once ROOT_PATH . '/whatsapp/aisensy.php';
            }
            $x = AiSensyAPI::facts($bid);
            $f['name']  = (string) $x['name'];
            $f['seats'] = (string) $x['seats'];
            $f['trip']  = trim(((string) $x['from'] !== '' ? $x['from'] . ' → ' . $x['to'] : '') . ((string) $x['date'] !== '' ? ' · ' . $x['date'] : ''), ' ·');
        } catch (Throwable $ignored) {
        }
        $cust = self::customerIntl($b);
        $f['phone'] = $cust !== '' ? '+' . $cust : '';
        try {
            if (!class_exists('Ticket')) {
                foreach (['qr', 'pdf', 'ticket'] as $file) {
                    require_once INCLUDE_PATH . '/' . $file . '.php';
                }
            }
            $f['img'] = Ticket::imageUrl((string) $b['pnr']);
        } catch (Throwable $ignored) {
        }
        if ($cust !== '') {
            $link = self::shortLink($bid);
            $f['passengerWa'] = 'https://wa.me/' . $cust . '?text=' . rawurlencode(self::customerText($b, $f, $link));
        }
        return $f;
    }

    private static function shortLink(int $bid): string
    {
        try {
            require_once INCLUDE_PATH . '/ticketlink.php';
            return TicketLink::forBooking($bid);
        } catch (Throwable $e) {
            return '';
        }
    }

    public static function customerIntl(array $b): string
    {
        try {
            if (class_exists('Notify')) {
                $n = Notify::whatsappNumberFor($b);
                if ($n !== '') {
                    return self::intl($n);
                }
            }
        } catch (Throwable $ignored) {
        }
        $p = (string) ($b['contact_phone'] ?? '');
        if (preg_replace('/\D+/', '', $p) === '0000000000') {
            return '';
        }
        return self::intl($p, (string) ($b['contact_country_code'] ?? ''));
    }

    /** Web push to one agent (free). Returns how many devices got it. */
    private static function push(int $adminId, ?array $b, string $body = ''): int
    {
        $meta = preg_replace('/\D+/', '', Settings::getString('meta_wa_number', '918735881507')) ?: '918735881507';
        $payload = [
            'title' => $b !== null ? '🎫 Ticket for you · ' . (string) $b['pnr'] : '🌅 S Hari Global',
            'body'  => $body !== '' ? $body : 'Tap to open WhatsApp — the passenger ticket is waiting for you.',
            'url'   => 'https://wa.me/' . $meta . '?text=' . rawurlencode($b !== null ? 'ticket ' . (string) $b['pnr'] : 'available'),
            'tag'   => $b !== null ? 'route-' . (int) $b['id'] : 'gm',
        ];
        if (self::$pusher !== null) {
            return (int) (self::$pusher)($adminId, $payload);
        }
        try {
            require_once INCLUDE_PATH . '/webpush.php';
            if (!WebPush::enabled() || WebPush::forAdmin($adminId) === []) {
                return 0;
            }
            $r = WebPush::sendToAdmin($adminId, $payload, 'wa_route');
            return (int) ($r['sent'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function pushSuperadmins(array $payload): void
    {
        try {
            foreach (Database::fetchAll("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1") as $r) {
                if (self::$pusher !== null) {
                    (self::$pusher)((int) $r['id'], $payload);
                    continue;
                }
                require_once INCLUDE_PATH . '/webpush.php';
                if (WebPush::enabled() && WebPush::forAdmin((int) $r['id']) !== []) {
                    WebPush::sendToAdmin((int) $r['id'], $payload, 'wa_route');
                }
            }
        } catch (Throwable $ignored) {
        }
    }

    /* -----------------------------------------------------------------
     *  delivery_log
     * ----------------------------------------------------------------- */

    /** Insert one attempt row. Returns its id. */
    public static function log(int $bid, string $pnr, ?int $agentId, string $channel, string $status, string $detail, ?string $deadline, bool $closed = false): int
    {
        try {
            return (int) Database::insert('delivery_log', [
                'booking_id'  => $bid,
                'pnr'         => mb_substr($pnr, 0, 40),
                'agent_id'    => $agentId,
                'channel'     => mb_substr($channel, 0, 20),
                'status'      => mb_substr($status, 0, 24),
                'detail'      => mb_substr($detail, 0, 255),
                'deadline_at' => $deadline,
                'closed'      => $closed || $deadline === null ? 1 : 0,
            ]);
        } catch (Throwable $e) {
            Logger::warning('delivery_log insert failed: ' . $e->getMessage(), ['pnr' => $pnr], 'whatsapp');
            return 0;
        }
    }

    private static function close(int $id, string $detail, ?string $status = null): void
    {
        try {
            Database::run(
                'UPDATE delivery_log SET closed = 1, deadline_at = NULL, detail = :d' . ($status !== null ? ', status = :s' : '') . ' WHERE id = :i',
                $status !== null ? ['d' => mb_substr($detail, 0, 255), 's' => $status, 'i' => $id] : ['d' => mb_substr($detail, 0, 255), 'i' => $id]
            );
        } catch (Throwable $ignored) {
        }
    }

    /* -----------------------------------------------------------------
     *  Small helpers
     * ----------------------------------------------------------------- */

    private static function say(string $text, ?array $ui = null): array
    {
        return ['text' => $text, 'media' => null, 'ui' => $ui];
    }

    private static function first(string $name): string
    {
        $p = preg_split('/\s+/u', trim($name)) ?: [];
        return (string) ($p[0] ?? $name);
    }

    private static function norm(string $text): string
    {
        $t = mb_strtolower(trim($text));
        $t = preg_replace('/[!.,।?]+$/u', '', $t) ?? $t;
        return trim(preg_replace('/\s+/u', ' ', $t) ?? $t);
    }

    /** The whole (short) message is one of these words / phrases. */
    private static function isWord(string $t, array $words): bool
    {
        if (mb_strlen($t) > 24) {
            return false;
        }
        return in_array($t, $words, true);
    }
}
