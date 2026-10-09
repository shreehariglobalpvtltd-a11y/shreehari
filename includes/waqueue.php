<?php
/**
 * includes/waqueue.php — the WhatsApp ticket by "hi" (4 Oct 2026).
 *
 * Owner: "AiSensy bata WhatsApp … Nepal India 2 tai number ma jane … customer
 * lai nagaye kun counter bata katyo tyaslai gayeni hunchha … free automatic
 * … ek choti hi lekhne bas, 24 free msg."
 *
 * HOW WHATSAPP CHARGES. A message the business starts needs an approved
 * template (paid, and ours are still PENDING). A message inside the 24 hours
 * after a person last wrote to us is free and needs no template. So:
 *
 *   1. Every confirmed ticket gets a row here for each number it should reach:
 *      the customer, the customer's second number (India / Nepal), and the
 *      counter or agent who sold it (a copy, so it is never lost).
 *   2. If that number's free window is open it goes NOW (the ticket picture).
 *      If not, the row WAITS.
 *   3. The moment that number writes anything ("hi") to the AiSensy number,
 *      whatsapp/aisensy-webhook.php opens its window here and every waiting
 *      ticket for it goes out, free, automatically.
 *   4. cron/wa-ticket-queue.php asks AiSensy what really happened to each
 *      sent message (an accepted send can still fail later): DELIVERED / READ
 *      are marked, a "no window" failure goes back to waiting, and a customer
 *      whose ticket failed gets the seller copy queued.
 *
 * Numbers are stored as international digits (91…, 977…): an Indian and a
 * Nepali mobile can share the same last 10 digits, so matching is always on
 * the full number. A ticket is only ever sent to a number written on that
 * booking (customer / second number) or to the staff member who sold it —
 * never to whoever types a PNR (PNRs are sequential).
 *
 * Never throws to its callers: every public entry point catches and logs.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

if (!class_exists('AiSensyAPI')) {
    require_once dirname(__DIR__) . '/whatsapp/aisensy.php';
}

final class WaQueue
{
    /** The free window is 24 h; stay ten minutes clear of the edge. */
    public const WINDOW_MINUTES = 1430;

    /** A waiting ticket is dropped after this many days (or once travelled). */
    public const KEEP_DAYS = 7;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready === null) {
            try {
                self::$ready = Database::fetch("SHOW TABLES LIKE 'wa_ticket_queue'") !== null
                    && Database::fetch("SHOW TABLES LIKE 'wa_windows'") !== null;
            } catch (Throwable $e) {
                self::$ready = false;
            }
        }
        return self::$ready && Settings::getBool('wa_hi_queue_on', true);
    }

    /** The AiSensy number customers send "hi" to, as digits (919157001507). */
    public static function hiNumber(): string
    {
        $d = preg_replace('/\D+/', '', Settings::getString('aisensy_wa_number', '919157001507')) ?? '';
        return $d !== '' ? $d : '919157001507';
    }

    /** wa.me link that opens WhatsApp with "Hi <PNR>" typed, to the AiSensy number. */
    public static function hiLink(string $pnr = ''): string
    {
        $text = 'Hi' . ($pnr !== '' ? ' ' . $pnr : '');
        return 'https://wa.me/' . self::hiNumber() . '?text=' . rawurlencode($text);
    }

    /* -----------------------------------------------------------------
     *  Numbers
     * ----------------------------------------------------------------- */

    /** International digits for a raw number and a country hint (IN / NP / 91 / 977), or ''. */
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
            $h = strtoupper(trim($hint));
            $cc = ($h === 'NP' || $h === '977') ? '977' : '91';
            $d = $cc . $d;
        }
        return AiSensyAPI::normalizePhone($d);
    }

    /**
     * Who should get this ticket, customer first, as [phone, role] pairs.
     * (A list, not a phone-keyed map: PHP turns a digit-string key into an
     * int, and a 13-digit int is not the string the queue stores.)
     *
     * @param array<string,mixed> $b a bookings row
     * @return list<array{0:string,1:string}>
     */
    public static function recipients(array $b): array
    {
        $map = self::recipientMap($b);
        $out = [];
        foreach ($map as $phone => $role) {
            $out[] = [(string) $phone, $role];
        }
        return $out;
    }

    /** The role this number has on the booking, or '' when it is not on it. */
    public static function roleOf(array $b, string $phone): string
    {
        foreach (self::recipients($b) as [$p, $role]) {
            if ($p === $phone) {
                return $role;
            }
        }
        return '';
    }

    /** @return array<string|int,string> */
    private static function recipientMap(array $b): array
    {
        $out = [];
        $cust = class_exists('Notify') ? Notify::whatsappNumberFor($b) : '';
        $cust = $cust !== '' ? AiSensyAPI::normalizePhone($cust) : self::intl((string) ($b['contact_phone'] ?? ''), (string) ($b['contact_country_code'] ?? ''));
        if ($cust !== '') {
            $out[$cust] = 'customer';
        }
        $alt = self::intl((string) ($b['alt_phone'] ?? ''), (string) ($b['alt_country_code'] ?? ''));
        if ($alt !== '' && !isset($out[$alt])) {
            $out[$alt] = 'alt';
        }
        if (Settings::getBool('wa_seller_copy_on', true)) {
            $s = self::sellerPhone($b);
            if ($s !== '' && !isset($out[$s])) {
                $out[$s] = 'seller';
            }
        }
        return $out;
    }

    /**
     * The WhatsApp of the person who CUT this ticket: for a desk sale the
     * clerk who took the money (the sale is re-credited to an agent right
     * after, so sold_by is not the clerk), else the selling agent.
     */
    public static function sellerPhone(array $b): string
    {
        $bid = (int) ($b['id'] ?? 0);
        $ids = [];
        try {
            $clerk = (int) Database::scalar(
                "SELECT verified_by FROM payments WHERE booking_id = :b AND verified_by IS NOT NULL ORDER BY id ASC LIMIT 1",
                ['b' => $bid], 0
            );
            if ($clerk > 0 && in_array((string) ($b['source'] ?? ''), ['counter', 'admin', 'agent'], true)) {
                $ids[] = $clerk;
            }
        } catch (Throwable $e) {
        }
        $sold = (int) ($b['sold_by_admin_id'] ?? 0);
        if ($sold > 0 && !in_array($sold, $ids, true)) {
            $ids[] = $sold;
        }
        foreach ($ids as $id) {
            try {
                $r = Database::fetch(
                    'SELECT a.phone, a.is_active, p.whatsapp, p.display_phone, p.country_code, p.counter_code
                       FROM admins a LEFT JOIN admin_profiles p ON p.admin_id = a.id
                      WHERE a.id = :i LIMIT 1',
                    ['i' => $id]
                );
            } catch (Throwable $e) {
                $r = null;
            }
            if ($r === null || (int) $r['is_active'] !== 1) {
                continue;
            }
            $hint = (string) ($r['country_code'] ?? '');
            if ($hint === '' && (string) ($r['counter_code'] ?? '') !== '' && class_exists('CounterDesk')) {
                $hint = (string) (CounterDesk::get((string) $r['counter_code'])['country'] ?? '');
            }
            foreach (['whatsapp', 'display_phone', 'phone'] as $col) {
                $n = self::intl((string) ($r[$col] ?? ''), $hint);
                if ($n !== '') {
                    return $n;
                }
            }
        }
        return '';
    }

    /* -----------------------------------------------------------------
     *  The free 24 h window
     * ----------------------------------------------------------------- */

    public static function windowOpen(string $phone): bool
    {
        if (!self::ready() || $phone === '') {
            return false;
        }
        try {
            return Database::fetch(
                'SELECT 1 FROM wa_windows WHERE phone = :p AND last_in_at >= NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE',
                ['p' => $phone]
            ) !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** A message FROM this number reached us: its free window starts now. */
    public static function touchWindow(string $phone, string $text = ''): void
    {
        if (!self::ready() || $phone === '') {
            return;
        }
        try {
            Database::run(
                'INSERT INTO wa_windows (phone, last_in_at, last_text) VALUES (:p, NOW(), :t)
                 ON DUPLICATE KEY UPDATE last_in_at = NOW(), last_text = :t2',
                ['p' => $phone, 't' => mb_substr($text, 0, 160), 't2' => mb_substr($text, 0, 160)]
            );
        } catch (Throwable $e) {
            Logger::warning('wa window touch failed', ['e' => $e->getMessage()]);
        }
    }

    /* -----------------------------------------------------------------
     *  Queue
     * ----------------------------------------------------------------- */

    /** Insert (or keep) the row for this ticket and number. Returns its id, 0 on failure. */
    public static function enqueue(int $bid, string $pnr, string $phone, string $role): int
    {
        if (!self::ready() || $bid <= 0 || $phone === '') {
            return 0;
        }
        try {
            Database::run(
                'INSERT IGNORE INTO wa_ticket_queue (booking_id, pnr, phone, role) VALUES (:b, :p, :ph, :r)',
                ['b' => $bid, 'p' => $pnr, 'ph' => $phone, 'r' => $role]
            );
            return (int) Database::scalar('SELECT id FROM wa_ticket_queue WHERE booking_id = :b AND phone = :ph', ['b' => $bid, 'ph' => $phone], 0);
        } catch (Throwable $e) {
            Logger::warning('wa queue insert failed', ['e' => $e->getMessage(), 'pnr' => $pnr]);
            return 0;
        }
    }

    /** Record a send done elsewhere (the approved template) so the cron can check it. */
    public static function recordSent(int $bid, string $pnr, string $phone, string $role, string $via, string $wamid): void
    {
        $id = self::enqueue($bid, $pnr, $phone, $role);
        if ($id > 0) {
            Database::run(
                "UPDATE wa_ticket_queue SET status = 'sent', via = :v, wamid = :w, sent_at = NOW(), attempts = attempts + 1, last_error = NULL, checked_at = NULL WHERE id = :i",
                ['v' => $via, 'w' => $wamid !== '' ? $wamid : null, 'i' => $id]
            );
        }
    }

    /**
     * Send this queued ticket now IF the number's free window is open
     * (or $force). Returns true when AiSensy accepted it.
     */
    public static function tryNow(int $rowId, bool $force = false): bool
    {
        if (!self::ready() || $rowId <= 0) {
            return false;
        }
        try {
            $q = Database::fetch('SELECT * FROM wa_ticket_queue WHERE id = :i', ['i' => $rowId]);
            if ($q === null || !in_array((string) $q['status'], ['pending', 'failed'], true)) {
                return false;
            }
            if (!$force && !self::windowOpen((string) $q['phone'])) {
                return false;
            }
            $b = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => (int) $q['booking_id']]);
            if ($b === null || !in_array((string) $b['status'], ['confirmed', 'completed'], true)) {
                Database::run("UPDATE wa_ticket_queue SET status = 'expired', last_error = 'booking not confirmed' WHERE id = :i", ['i' => $rowId]);
                return false;
            }
            return self::deliver($q, $b);
        } catch (Throwable $e) {
            Logger::warning('wa queue send failed', ['e' => $e->getMessage(), 'row' => $rowId]);
            return false;
        }
    }

    /**
     * Someone wrote to the AiSensy number: send every ticket waiting for that
     * number, plus any PNR they typed that belongs to them. Returns how many
     * tickets went out.
     */
    public static function onInbound(string $phone, string $text, string $sender = 'aisensy'): int
    {
        if (!self::ready() || $phone === '') {
            return 0;
        }
        $sent = 0;
        try {
            /* 9 Oct 2026: a "hi" to the META number opens a window THERE
               (recorded by whatsapp/webhook.php in kv wa_inbound, read by
               WaRoute); wa_windows stays the AiSensy number's own record. */
            if ($sender !== 'meta') {
                self::touchWindow($phone, $text);
            }

            // A PNR they typed, when this number is on that booking (or sold it).
            if (preg_match_all('/SHG-?\s?(\d{4})-?\s?(\d{3,6})/i', $text, $m, PREG_SET_ORDER)) {
                foreach (array_slice($m, 0, 3) as $hit) {
                    $pnr = 'SHG-' . $hit[1] . '-' . str_pad($hit[2], 5, '0', STR_PAD_LEFT);
                    $b = Database::fetch('SELECT * FROM bookings WHERE pnr = :p LIMIT 1', ['p' => $pnr]);
                    if ($b === null) {
                        continue;
                    }
                    $role = self::roleOf($b, $phone);
                    $id   = 0;
                    if ($role !== '') {
                        $id = self::enqueue((int) $b['id'], (string) $b['pnr'], $phone, $role);
                        Database::run("UPDATE wa_ticket_queue SET status = 'pending' WHERE id = :i AND status IN ('sent','delivered','read','failed','expired')", ['i' => $id]);
                    } else {
                        // Not on the booking by the tables, but a copy is waiting for this very number (a clerk's).
                        $id = (int) Database::scalar(
                            "SELECT id FROM wa_ticket_queue WHERE phone = :p AND pnr = :n AND status IN ('pending','failed') ORDER BY id DESC LIMIT 1",
                            ['p' => $phone, 'n' => (string) $b['pnr']], 0);
                    }
                    // A PNR asked for by name goes now, whoever they are on it (the Meta rule below sends only customer rows).
                    if ($sender === 'meta' && $id > 0 && self::tryNow($id, true)) {
                        $sent++;
                    }
                }
            }

            /* 9 Oct 2026 (owner: "passenger list magda sara ticket pathaidiyo -
               sensible hos"): on the META number an inbound is usually a
               question, not a request for tickets. So only the CUSTOMER's own
               waiting tickets go (newest 3) - a seller copy goes only when the
               seller typed that PNR (handled above). The AiSensy number keeps
               its old "hi = everything waiting" rule. */
            $meta = $sender === 'meta';
            $rows = Database::fetchAll(
                "SELECT id FROM wa_ticket_queue
                  WHERE phone = :p AND status IN ('pending','failed')" . ($meta ? " AND role IN ('customer','alt')" : '') . "
                    AND created_at >= NOW() - INTERVAL " . self::KEEP_DAYS . " DAY
                  ORDER BY id DESC LIMIT " . ($meta ? 3 : 10),
                ['p' => $phone]
            );
            foreach ($rows as $r) {
                if (self::tryNow((int) $r['id'], true)) {
                    $sent++;
                }
            }
        } catch (Throwable $e) {
            Logger::warning('wa inbound flush failed', ['e' => $e->getMessage()]);
        }
        return $sent;
    }

    /**
     * The booking-confirmed hook (from AiSensyAPI::onBookingConfirmed).
     * $customerVia/$customerWamid: what the template step already did for
     * the customer ('' = nothing went out).
     */
    public static function onConfirmed(array $b, string $customerPhone, string $customerVia, string $customerWamid): void
    {
        if (!self::ready()) {
            return;
        }
        try {
            $bid = (int) ($b['id'] ?? 0);
            $pnr = (string) ($b['pnr'] ?? '');
            $who = self::recipients($b);
            if ($customerPhone !== '' && self::roleOf($b, $customerPhone) === '') {
                array_unshift($who, [$customerPhone, 'customer']);
            }
            $customerGot = false;
            foreach ($who as [$phone, $role]) {
                if ($role === 'customer' && $customerVia !== '') {
                    self::recordSent($bid, $pnr, $phone, $role, $customerVia, $customerWamid);
                    $customerGot = true;
                    continue;
                }
                $id = self::enqueue($bid, $pnr, $phone, $role);
                // The seller copy waits until we know the customer has not got it
                // (the cron reads the real delivery status and sends it then).
                if ($role === 'seller') {
                    continue;
                }
                /* One attempt now for the customer and the second number. If their
                   window is open it arrives; if not, AiSensy accepts it and it
                   fails a minute later (free), the cron sees that and puts the
                   ticket back to wait for their "hi". So a window we never saw
                   (a customer who wrote before the webhook existed) still works. */
                $fresh = $id > 0 && (int) Database::scalar('SELECT attempts FROM wa_ticket_queue WHERE id = :i', ['i' => $id], 0) === 0;
                if ($fresh && self::tryNow($id, true) && $role === 'customer') {   // once per ticket per number
                    $customerGot = true;
                }
            }
        } catch (Throwable $e) {
            Logger::warning('wa queue onConfirmed failed', ['e' => $e->getMessage(), 'pnr' => (string) ($b['pnr'] ?? '')]);
        }
    }

    /**
     * The customer cannot be reached at all (no number / a number WhatsApp
     * cannot be): the seller's copy goes out now instead of waiting for a
     * customer failure that will never be reported. Once per ticket.
     */
    public static function sellerNow(array $b): bool
    {
        if (!self::ready() || !Settings::getBool('wa_seller_copy_on', true)) {
            return false;
        }
        try {
            $seller = self::sellerPhone($b);
            if ($seller === '') {
                return false;
            }
            $id = self::enqueue((int) ($b['id'] ?? 0), (string) ($b['pnr'] ?? ''), $seller, 'seller');
            if ($id <= 0 || (int) Database::scalar('SELECT attempts FROM wa_ticket_queue WHERE id = :i', ['i' => $id], 0) !== 0) {
                return false;
            }
            return self::tryNow($id, true);
        } catch (Throwable $e) {
            Logger::warning('wa queue sellerNow failed', ['e' => $e->getMessage(), 'pnr' => (string) ($b['pnr'] ?? '')]);
            return false;
        }
    }

    /**
     * Cron: ask AiSensy what happened to recent sends, expire old waits,
     * and queue the seller copy for a customer whose ticket failed.
     *
     * @return array<string,int>
     */
    public static function checkStatuses(int $limit = 30): array
    {
        $out = ['checked' => 0, 'delivered' => 0, 'failed' => 0, 'rewait' => 0, 'seller' => 0, 'expired' => 0];
        if (!self::ready()) {
            return $out;
        }
        try {
            $out['expired'] = (int) Database::run(
                "UPDATE wa_ticket_queue q JOIN booking_legs l ON l.booking_id = q.booking_id AND l.leg_type = 'outbound'
                    SET q.status = 'expired', q.last_error = 'travel date passed / waited too long'
                  WHERE q.status IN ('pending','failed')
                    AND (q.created_at < NOW() - INTERVAL " . self::KEEP_DAYS . " DAY OR l.travel_date < CURDATE() - INTERVAL 1 DAY)"
            );
            $rows = Database::fetchAll(
                "SELECT * FROM wa_ticket_queue
                  WHERE status = 'sent' AND wamid IS NOT NULL
                    AND sent_at < NOW() - INTERVAL 1 MINUTE AND sent_at > NOW() - INTERVAL 3 DAY
                    AND (checked_at IS NULL OR checked_at < NOW() - INTERVAL 10 MINUTE)
                  ORDER BY sent_at ASC LIMIT " . max(1, $limit)
            );
            foreach ($rows as $q) {
                // A Meta send reports its status through whatsapp/webhook.php, not AiSensy.
                if (str_starts_with((string) ($q['via'] ?? ''), 'meta')) {
                    Database::run('UPDATE wa_ticket_queue SET checked_at = NOW() WHERE id = :i', ['i' => (int) $q['id']]);
                    continue;
                }
                $st = AiSensyAPI::messageStatus((string) $q['wamid'], (string) $q['phone']);
                $out['checked']++;
                $s = strtoupper((string) ($st['status'] ?? ''));
                if ($s === 'DELIVERED' || $s === 'READ') {
                    Database::run("UPDATE wa_ticket_queue SET status = :s, checked_at = NOW() WHERE id = :i",
                        ['s' => strtolower($s) === 'read' ? 'read' : 'delivered', 'i' => (int) $q['id']]);
                    $out['delivered']++;
                    continue;
                }
                if ($s !== 'FAILED') {
                    Database::run('UPDATE wa_ticket_queue SET checked_at = NOW() WHERE id = :i', ['i' => (int) $q['id']]);
                    continue;
                }
                $err = mb_substr((string) ($st['error'] ?? 'failed'), 0, 250);
                // No open window (131047): wait for this number's "hi" instead.
                $rewait = str_contains($err, '131047') || str_contains($err, '131051') || stripos($err, 're-engage') !== false;
                Database::run("UPDATE wa_ticket_queue SET status = :s, last_error = :e, checked_at = NOW() WHERE id = :i",
                    ['s' => $rewait ? 'pending' : 'failed', 'e' => $err, 'i' => (int) $q['id']]);
                $out[$rewait ? 'rewait' : 'failed']++;

                if (in_array((string) $q['role'], ['customer', 'alt'], true) && Settings::getBool('wa_seller_copy_on', true)) {
                    $b = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => (int) $q['booking_id']]);
                    $seller = $b !== null ? self::sellerPhone($b) : '';
                    if ($seller !== '' && $seller !== (string) $q['phone']) {
                        $id = self::enqueue((int) $q['booking_id'], (string) $q['pnr'], $seller, 'seller');
                        // One attempt for the copy too; if the clerk's window is shut it waits for their "hi".
                        $first = (int) Database::scalar('SELECT attempts FROM wa_ticket_queue WHERE id = :i', ['i' => $id], 0) === 0;
                        if (self::tryNow($id, $first)) {
                            $out['seller']++;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Logger::warning('wa queue status check failed', ['e' => $e->getMessage()]);
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     *  Sending
     * ----------------------------------------------------------------- */

    /** Send the ticket picture (caption = the facts), text + link as the fallback. */
    private static function deliver(array $q, array $b): bool
    {
        $pnr  = (string) $b['pnr'];
        $role = (string) $q['role'];
        if (!class_exists('Ticket')) {
            foreach (['qr', 'pdf', 'ticket'] as $f) {
                require_once INCLUDE_PATH . '/' . $f . '.php';
            }
        }
        $img = Ticket::imageUrl($pnr);
        $f   = AiSensyAPI::facts((int) $b['id']);
        $co  = Settings::companyFor((string) ($b['counter_code'] ?? ''), (string) ($b['entity'] ?? ''));

        $facts = 'PNR: ' . $pnr . "\n"
            . ($f['from'] !== '' ? $f['from'] . ' → ' . $f['to'] . "\n" : '')
            . ($f['date'] !== '' ? 'मिति / Date: ' . $f['date'] . "\n" : '')
            . ($f['seats'] !== '' ? 'सिट / Seat: ' . $f['seats'] . "\n" : '');
        if ($role === 'seller') {
            $phone = (string) ($b['contact_phone'] ?? '');
            $caption = "🧾 टिकट कपी · Ticket copy (you sold it)\n"
                . 'यात्रु / Passenger: ' . ($f['name'] !== '' ? $f['name'] : '-') . ($phone !== '' && $phone !== '0000000000' ? ' · ' . $phone : '') . "\n"
                . $facts
                . "यात्रुलाई फर्वार्ड गर्नुहोस् · Please forward to the passenger.\n"
                . 'यात्रुले +' . self::hiNumber() . ' मा "hi" पठाए टिकट आफैं पुग्छ।';
        } else {
            $caption = '✅ ' . (string) $co['name'] . " — तपाईंको टिकट · Your ticket\n"
                . 'नमस्ते ' . ($f['name'] !== '' ? $f['name'] : '') . "\n"
                . $facts
                . 'सहयोग / Help: ' . (string) $co['phone'];
        }

        /* 5 Oct 2026: the seller's copy on the approved template first. The
           free-form picture only lands inside a 24 h window, which a clerk or
           agent almost never has open - 14 copies waited, 1 was read. Off with
           setting wa_seller_template_on = 0. */
        $r = ['success' => false, 'message_id' => '', 'error' => ''];
        $via = '';
        /* 9 Oct 2026 (includes/waroute.php): the Meta number first, free, when
           this number wrote to it in the last 24 h — the PNG with the facts
           as its caption. WaRoute logs that row itself. */
        try {
            require_once INCLUDE_PATH . '/waroute.php';
            $free = WaRoute::freeFirst((string) $q['phone'], $caption, $img, $role === 'seller' ? 'seller_copy' : 'ticket', ['bookingId' => (int) $b['id']]);
            if ($free !== null && $free['success']) {
                $r   = $free;
                $via = 'meta free';
            }
        } catch (Throwable $e) {
            Logger::warning('wa queue meta-first failed: ' . $e->getMessage(), ['row' => (int) $q['id']]);
        }
        /* aisensy_scope = ticket_only: a seller copy never spends a template
           (AiSensyAPI::post refuses it); the free-form picture below still
           lands inside the clerk's window. */
        if (!$r['success'] && $role === 'seller' && Settings::getBool('wa_seller_template_on', true)) {
            $r = AiSensyAPI::ticketTemplate((string) $q['phone'], (int) $b['id'], $pnr,
                ($f['name'] !== '' ? $f['name'] : 'Passenger') . ' (agent copy - please forward)', false);
            $via = $r['how'];
        }
        $isCustomer = in_array($role, ['customer', 'alt'], true);
        if (!$r['success']) {
            $r = $isCustomer
                ? AiSensyAPI::asTicket(static fn(): array => AiSensyAPI::sendImage((string) $q['phone'], $img, $caption))
                : AiSensyAPI::sendImage((string) $q['phone'], $img, $caption);
            $via = 'image';
        }
        if (!$r['success']) {
            $r = $isCustomer
                ? AiSensyAPI::asTicket(static fn(): array => AiSensyAPI::sendText((string) $q['phone'], $caption . "\nटिकट / Ticket: " . $img))
                : AiSensyAPI::sendText((string) $q['phone'], $caption . "\nटिकट / Ticket: " . $img);
            $via = 'text';
        }
        Database::run(
            "UPDATE wa_ticket_queue SET status = :s, via = :v, wamid = :w, attempts = attempts + 1,
                    last_error = :e, sent_at = IF(:ok = 1, NOW(), sent_at), checked_at = NULL WHERE id = :i",
            ['s' => $r['success'] ? 'sent' : 'failed', 'v' => $via, 'w' => $r['message_id'] !== '' ? $r['message_id'] : null,
             'e' => $r['success'] ? null : mb_substr((string) $r['error'], 0, 250), 'ok' => $r['success'] ? 1 : 0, 'i' => (int) $q['id']]
        );
        if (class_exists('Notify') && $via !== 'meta free') {
            Notify::logOutbound((string) $q['phone'], 'AiSensy ticket ' . $pnr . ' (' . $role . ', ' . $via . ')', $r['success'] ? 'sent' : 'failed', [
                'provider'  => 'aisensy',
                'bookingId' => (int) $b['id'],
                'sid'       => $r['message_id'],
                'purpose'   => $role === 'seller' ? 'seller_copy' : 'aisensy_ticket',
                'error'     => $r['success'] ? null : mb_substr((string) $r['error'], 0, 250),
            ]);
        }
        return (bool) $r['success'];
    }

    /**
     * The Meta number ACCEPTED a ticket and failed it later (whatsapp/
     * webhook.php, 9 Oct 2026): the window had closed after all, or the
     * number is unreachable there. The approved AiSensy template reaches a
     * cold number, so it goes out now — once per failure, never looping
     * back to Meta. Returns true when the template was accepted.
     */
    public static function onMetaFailed(string $wamid, int $code): bool
    {
        if (!self::ready() || $wamid === '') {
            return false;
        }
        try {
            $q = Database::fetch("SELECT * FROM wa_ticket_queue WHERE wamid = :w AND status = 'sent' ORDER BY id DESC LIMIT 1", ['w' => $wamid]);
            if ($q === null || !str_starts_with((string) ($q['via'] ?? ''), 'meta')) {
                return false;
            }
            $b = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => (int) $q['booking_id']]);
            if ($b === null || !in_array((string) $b['status'], ['confirmed', 'completed'], true)) {
                Database::run("UPDATE wa_ticket_queue SET status = 'expired', last_error = 'booking not confirmed' WHERE id = :i", ['i' => (int) $q['id']]);
                return false;
            }
            if (!class_exists('AiSensyAPI') && is_file(ROOT_PATH . '/whatsapp/aisensy.php')) {
                require_once ROOT_PATH . '/whatsapp/aisensy.php';
            }
            $f    = AiSensyAPI::facts((int) $b['id']);
            $role = (string) $q['role'];
            $r    = AiSensyAPI::ticketTemplate((string) $q['phone'], (int) $b['id'], (string) $b['pnr'],
                $role === 'seller' ? ($f['name'] !== '' ? $f['name'] : 'Passenger') . ' (agent copy - please forward)' : '', false);
            Database::run(
                "UPDATE wa_ticket_queue SET status = :s, via = :v, wamid = :w, attempts = attempts + 1,
                        last_error = :e, sent_at = IF(:ok = 1, NOW(), sent_at), checked_at = NULL WHERE id = :i",
                ['s' => $r['success'] ? 'sent' : 'failed', 'v' => mb_substr('ais after ' . $code, 0, 20),   // via is VARCHAR(20)
                 'w' => $r['message_id'] !== '' ? $r['message_id'] : null,
                 'e' => $r['success'] ? null : mb_substr((string) $r['error'], 0, 250), 'ok' => $r['success'] ? 1 : 0, 'i' => (int) $q['id']]
            );
            if (class_exists('Notify')) {
                Notify::logOutbound((string) $q['phone'], 'AiSensy ticket ' . (string) $b['pnr'] . ' (' . $role . ', after Meta ' . $code . ')', $r['success'] ? 'sent' : 'failed', [
                    'provider'  => 'aisensy',
                    'bookingId' => (int) $b['id'],
                    'sid'       => $r['message_id'],
                    'purpose'   => $role === 'seller' ? 'seller_copy' : 'aisensy_ticket',
                    'error'     => $r['success'] ? null : mb_substr((string) $r['error'], 0, 250),
                ]);
            }
            return (bool) $r['success'];
        } catch (Throwable $e) {
            Logger::warning('wa queue meta-failed recovery failed', ['e' => $e->getMessage(), 'wamid' => $wamid]);
            return false;
        }
    }
}
