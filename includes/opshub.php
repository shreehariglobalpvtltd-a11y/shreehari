<?php
/**
 * =====================================================================
 *  includes/opshub.php — "ek thau" operations helpers (6 Nov 2026)
 *
 *  Owner, 6 Nov 2026: "setting haru category wise ... jun recent ma hunx
 *  tyo agaadi aune; bus fleet, seat, chalani, bus management eutai setting
 *  vitra; chalani ma admin le matra payment tick, due ra paid color; chalani
 *  eutai permanent link — login bina herna milne, admin le update garda
 *  update hune; cancel garda admin chahe fine; agent system sapai
 *  shreehariglobal.network bata matra, .in customer portal."
 *
 *    1. MENU USE      which admin pages THIS person opens, how often and
 *                     when (kv_store 'nav_use'), so the menu and the
 *                     Control Center put their recent / everyday pages first.
 *    2. COLLECTIONS   the office's own "paise aayo" tick on a chalani row
 *                     (booking_collections). Paid = green, due = red,
 *                     cash still with the agent = amber. Ticking runs the
 *                     same payment engine the Payments queue uses (COD →
 *                     settleCod, pending online → approveReviewed) and, for
 *                     an agent's cash sale, books the cash as handed over
 *                     (AgentWallet cash_handover, ref = PNR) — so the agent
 *                     sees "jama bhayo" on that ticket the same minute.
 *    3. PUBLIC LINK   one permanent secret link to the live chalani
 *                     (chalani-live.php?k=…), no login, phones masked;
 *                     the office can switch it off or change the key.
 *    4. CANCEL FINE   an optional ₹ fine the office types when cancelling:
 *                     taken off a pending refund, else owed by the selling
 *                     agent (cash_due), always recorded (booking_fines).
 *                     Refund money itself stays manual.
 *    5. STAFF DOOR    staff / agent pages (/admin, /team, /me, join) on the
 *                     customer host redirect to the .network staff door.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class OpsHub
{
    /* =================================================================
     *  1. Menu use — recent first
     * ================================================================= */

    /** @var array<string, array{0:int,1:int}>|null */
    private static ?array $use = null;
    private static int $useFor = 0;

    /**
     * Remember that this admin opened $href (an admin page file name such
     * as 'manifest.php'). One small upsert; never throws.
     */
    public static function trackPage(int $adminId, string $href): void
    {
        $href = trim($href);
        if ($adminId <= 0 || $href === '' || strlen($href) > 80 || preg_match('/^[a-z0-9_\-\/.]+$/i', $href) !== 1) {
            return;
        }
        try {
            $u   = self::usage($adminId);
            $row = $u[$href] ?? [0, 0];
            $u[$href] = [min(99999, (int) $row[0] + 1), time()];
            uasort($u, static fn(array $a, array $b): int => $b[1] <=> $a[1]);
            $u = array_slice($u, 0, 48, true);
            self::$use    = $u;
            self::$useFor = $adminId;
            Database::query(
                "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('nav_use', :k, :v, 'nav')
                 ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_at = CURRENT_TIMESTAMP",
                ['k' => 'a' . $adminId, 'v' => (string) json_encode($u, JSON_UNESCAPED_SLASHES)]
            );
        } catch (Throwable $e) {
            // the menu still works without it
        }
    }

    /** @return array<string, array{0:int,1:int}> href => [hits, last unix time] */
    public static function usage(int $adminId): array
    {
        if (self::$use !== null && self::$useFor === $adminId) {
            return self::$use;
        }
        $u = [];
        try {
            $raw = (string) Database::scalar(
                "SELECT kvalue FROM kv_store WHERE kscope = 'nav_use' AND kkey = :k LIMIT 1",
                ['k' => 'a' . $adminId],
                ''
            );
            $j = json_decode($raw, true);
            if (is_array($j)) {
                foreach ($j as $h => $v) {
                    if (is_array($v)) {
                        $u[(string) $h] = [(int) ($v[0] ?? 0), (int) ($v[1] ?? 0)];
                    }
                }
            }
        } catch (Throwable $e) {
            $u = [];
        }
        self::$use    = $u;
        self::$useFor = $adminId;
        return $u;
    }

    /**
     * How "hot" a page is for this person: hits fade by half every 7 days,
     * and anything opened in the last day gets a bump — so what you used
     * today and what you use every day both rise to the top.
     *
     * @param array<string, array{0:int,1:int}> $u
     */
    public static function score(array $u, string $href): float
    {
        if (!isset($u[$href])) {
            return 0.0;
        }
        [$hits, $last] = $u[$href];
        $ageDays = max(0.0, (time() - $last) / 86400);
        return $hits * (0.5 ** ($ageDays / 7)) + ($ageDays < 1 ? 6.0 : 0.0);
    }

    /* =================================================================
     *  2. Collections — the office's paid / due tick on the chalani
     * ================================================================= */

    private static bool $collReady = false;

    public static function ensureCollections(): bool
    {
        if (self::$collReady) {
            return true;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS booking_collections (
                    booking_id     BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                    status         ENUM('paid','due') NOT NULL DEFAULT 'paid',
                    mode           VARCHAR(40)   NULL,
                    amount         DECIMAL(10,2) NULL,
                    note           VARCHAR(160)  NULL,
                    agent_admin_id BIGINT UNSIGNED NULL,
                    ledger_id      BIGINT UNSIGNED NULL,
                    marked_by      BIGINT UNSIGNED NULL,
                    marked_at      DATETIME NOT NULL,
                    KEY idx_bc_status (status, marked_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$collReady = true;
        } catch (Throwable $e) {
            Logger::warning('booking_collections unavailable: ' . $e->getMessage());
        }
        return self::$collReady;
    }

    /**
     * The office ticks for these bookings.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    public static function collections(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0)));
        if ($ids === [] || !self::ensureCollections()) {
            return [];
        }
        $out = [];
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (Database::fetchAll(
                    'SELECT c.*, a.full_name AS by_name, a.username AS by_user
                       FROM booking_collections c LEFT JOIN admins a ON a.id = c.marked_by
                      WHERE c.booking_id IN (' . implode(',', $chunk) . ')'
                ) as $r) {
                    $out[(int) $r['booking_id']] = $r;
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /**
     * paid | agent | due — ONE rule for the chalani, the public link, Bus
     * Control and the agent's own lists.
     *
     *   office tick              → what the office said (paid / due)
     *   verified UPI/bank/eSewa  → paid (the money reached the company)
     *   verified cash, agent     → agent (cash is with the agent until ticked)
     *   verified cash, office    → paid (the desk took it)
     *   anything else            → due (COD not collected, payment pending)
     *
     * $r needs pay_status, pay_method and agent_role (the seller's role).
     *
     * @param array<string, mixed>      $r
     * @param array<string, mixed>|null $tick
     */
    public static function payState(array $r, ?array $tick): string
    {
        if ($tick !== null) {
            return (string) ($tick['status'] ?? '') === 'paid' ? 'paid' : 'due';
        }
        $ps = (string) ($r['pay_status'] ?? '');
        $pm = (string) ($r['pay_method'] ?? '');
        if ($ps === 'verified') {
            if (in_array($pm, ['upi', 'bank', 'esewa', 'wallet'], true)) {
                return 'paid';
            }
            return strtolower((string) ($r['agent_role'] ?? '')) === 'agent' ? 'agent' : 'paid';
        }
        return 'due';
    }

    /** @return array{0:string,1:string} [Nepali · English label, css class] */
    public static function payLabel(string $state): array
    {
        return match ($state) {
            'paid'  => ['Paid · भुक्तानी', 'pay-paid'],
            'agent' => ['With agent · एजेन्टसँग', 'pay-agent'],
            default => ['Due · बाँकी', 'pay-due'],
        };
    }

    /** Only the office (not an agent / counter login) may tick money. */
    public static function canTick(): bool
    {
        return Auth::can('payments.verify') && Auth::bookingScopeAdminId() === null && !Auth::isCounterAgent();
    }

    /** @return list<string> */
    public static function payModes(): array
    {
        $m = [];
        foreach (Settings::getArray('chalani_pay_modes', []) as $x) {
            $x = trim((string) $x);
            if ($x !== '' && !in_array($x, $m, true)) {
                $m[] = mb_substr($x, 0, 30);
            }
        }
        return $m !== [] ? $m : ['Cash', 'UPI', 'Bank', 'eSewa', 'Agent', 'Office'];
    }

    public static function addPayMode(string $mode, int $by): void
    {
        $mode = trim(Security::clean($mode, 30));
        if ($mode === '') {
            throw new RuntimeException('Payment option खाली छ · type a name.');
        }
        $list = self::payModes();
        if (!in_array($mode, $list, true)) {
            $list[] = $mode;
            Settings::set('chalani_pay_modes', array_slice($list, 0, 20), 'json', 'chalani');
            Logger::audit('chalani.pay_mode_added', 'settings', 'chalani_pay_modes', null, ['mode' => $mode], 'by admin #' . $by);
        }
    }

    public static function removePayMode(string $mode, int $by): void
    {
        $list = array_values(array_filter(self::payModes(), static fn(string $m): bool => $m !== $mode));
        if ($list === []) {
            throw new RuntimeException('At least one payment option must stay.');
        }
        Settings::set('chalani_pay_modes', $list, 'json', 'chalani');
        Logger::audit('chalani.pay_mode_removed', 'settings', 'chalani_pay_modes', ['mode' => $mode], null, 'by admin #' . $by);
    }

    /**
     * The office ticks "paid". Runs the payment engine first so the ticket,
     * the agent's wallet and the accounts agree with the tick.
     *
     * @param array<string, mixed> $admin
     * @return array{ok: bool, msg: string}
     */
    public static function markPaid(int $bookingId, string $mode, ?float $amount, string $note, array $admin): array
    {
        if (!self::ensureCollections()) {
            throw new RuntimeException('Could not save the tick (table missing).');
        }
        $mode = trim(Security::clean($mode, 40));
        if ($mode === '') {
            $mode = 'Cash';
        }
        $b = Database::fetch(
            'SELECT b.*, a.role AS agent_role FROM bookings b LEFT JOIN admins a ON a.id = b.sold_by_admin_id WHERE b.id = :i',
            ['i' => $bookingId]
        );
        if ($b === null) {
            throw new RuntimeException('Booking not found.');
        }
        if (in_array((string) $b['status'], ['cancelled', 'rejected', 'expired'], true)) {
            throw new RuntimeException((string) $b['pnr'] . ' is ' . $b['status'] . ' — nothing to collect.');
        }
        $msg = [];
        $pay = Database::fetch('SELECT * FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bookingId]);
        if ($pay !== null && (string) $pay['status'] !== 'verified') {
            if ((int) $b['is_cod'] === 1 && in_array((string) $pay['status'], ['cod_pending', 'pending'], true)) {
                BookingService::settleCod($bookingId, (int) ($admin['id'] ?? 0), 'Chalani tick: paid (' . $mode . ')');
                $msg[] = 'cash collected, ticket issued';
            } elseif ((string) $pay['status'] === 'pending') {
                $res = BookingService::approveReviewed($bookingId, $admin, ['note' => 'Chalani tick (' . $mode . ')', 'via' => 'chalani']);
                if (($res['state'] ?? '') === 'awaiting_second') {
                    return ['ok' => false, 'msg' => (string) $b['pnr'] . ': high-risk payment — first approval saved; a manager / accountant gives the second on Payments.'];
                }
                $msg[] = 'payment verified';
            }
        }

        $amt = $amount !== null && $amount > 0 ? round($amount, 2) : round((float) $b['total_amount'], 2);

        /* An agent's cash sale: the tick means the agent handed the cash in. */
        $agentId  = null;
        $ledgerId = null;
        $prev     = Database::fetch('SELECT * FROM booking_collections WHERE booking_id = :b', ['b' => $bookingId]);
        $pay2     = Database::fetch('SELECT status, method FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bookingId]);
        if (strtolower((string) ($b['agent_role'] ?? '')) === 'agent' && (int) ($b['sold_by_admin_id'] ?? 0) > 0
            && (string) ($pay2['method'] ?? '') === 'cash' && (string) ($pay2['status'] ?? '') === 'verified') {
            $agentId = (int) $b['sold_by_admin_id'];
            if ($prev !== null && (string) $prev['status'] === 'paid' && !empty($prev['ledger_id'])) {
                $ledgerId = (int) $prev['ledger_id'];   // already booked once
            } else {
                try {
                    $ledgerId = AgentWallet::record($agentId, 'cash_handover', $amt, [
                        'note' => 'Chalani: ' . $b['pnr'] . ' received (' . $mode . ')',
                        'ref'  => (string) $b['pnr'],
                        'by'   => (int) ($admin['id'] ?? 0),
                    ]);
                    $msg[] = 'agent cash −' . inr($amt);
                } catch (Throwable $e) {
                    $msg[] = 'agent wallet unchanged (' . $e->getMessage() . ')';
                }
            }
        }

        Database::query(
            'INSERT INTO booking_collections (booking_id, status, mode, amount, note, agent_admin_id, ledger_id, marked_by, marked_at)
                  VALUES (:b, \'paid\', :m, :a, :n, :ag, :l, :by, NOW())
             ON DUPLICATE KEY UPDATE status = \'paid\', mode = VALUES(mode), amount = VALUES(amount), note = VALUES(note),
                  agent_admin_id = VALUES(agent_admin_id), ledger_id = VALUES(ledger_id), marked_by = VALUES(marked_by), marked_at = NOW()',
            ['b' => $bookingId, 'm' => $mode, 'a' => $amt, 'n' => Security::clean($note, 160) ?: null,
             'ag' => $agentId, 'l' => $ledgerId, 'by' => (int) ($admin['id'] ?? 0) ?: null]
        );
        Logger::audit('chalani.paid', 'booking', (string) $b['pnr'],
            $prev !== null ? ['status' => $prev['status'], 'mode' => $prev['mode']] : null,
            ['status' => 'paid', 'mode' => $mode, 'amount' => $amt], implode(' · ', $msg));

        return ['ok' => true, 'msg' => '✅ ' . $b['pnr'] . ' — ' . inr($amt) . ' ' . $mode . ($msg !== [] ? ' · ' . implode(' · ', $msg) : '')];
    }

    /**
     * Undo a tick: the row reads "due" again. An agent's handed-over cash is
     * put back on the agent (cash_due), so the wallet stays true.
     *
     * @param array<string, mixed> $admin
     * @return array{ok: bool, msg: string}
     */
    public static function markDue(int $bookingId, array $admin): array
    {
        if (!self::ensureCollections()) {
            throw new RuntimeException('Could not save the tick (table missing).');
        }
        $b = Database::fetch('SELECT id, pnr FROM bookings WHERE id = :i', ['i' => $bookingId]);
        if ($b === null) {
            throw new RuntimeException('Booking not found.');
        }
        $c   = Database::fetch('SELECT * FROM booking_collections WHERE booking_id = :b', ['b' => $bookingId]);
        $msg = [];
        if ($c !== null && (string) $c['status'] === 'paid' && !empty($c['ledger_id']) && (int) $c['agent_admin_id'] > 0 && (float) $c['amount'] > 0) {
            try {
                AgentWallet::record((int) $c['agent_admin_id'], 'cash_due', (float) $c['amount'], [
                    'note' => 'Chalani tick undone: ' . $b['pnr'],
                    'ref'  => (string) $b['pnr'],
                    'by'   => (int) ($admin['id'] ?? 0),
                ]);
                $msg[] = 'agent cash +' . inr((float) $c['amount']);
            } catch (Throwable $e) {
                $msg[] = 'agent wallet unchanged (' . $e->getMessage() . ')';
            }
        }
        Database::query(
            'INSERT INTO booking_collections (booking_id, status, mode, amount, note, agent_admin_id, ledger_id, marked_by, marked_at)
                  VALUES (:b, \'due\', NULL, NULL, NULL, NULL, NULL, :by, NOW())
             ON DUPLICATE KEY UPDATE status = \'due\', ledger_id = NULL, marked_by = VALUES(marked_by), marked_at = NOW()',
            ['b' => $bookingId, 'by' => (int) ($admin['id'] ?? 0) ?: null]
        );
        Logger::audit('chalani.due', 'booking', (string) $b['pnr'],
            $c !== null ? ['status' => $c['status'], 'mode' => $c['mode'], 'amount' => $c['amount']] : null,
            ['status' => 'due'], implode(' · ', $msg));
        return ['ok' => true, 'msg' => '↩️ ' . $b['pnr'] . ' — बाँकी · due' . ($msg !== [] ? ' · ' . implode(' · ', $msg) : '')];
    }

    /* =================================================================
     *  3. The permanent public chalani link
     * ================================================================= */

    public static function publicOn(): bool
    {
        return Settings::getBool('chalani_public_on', true);
    }

    public static function publicKey(bool $create = true): string
    {
        $k = Settings::getString('chalani_public_key', '');
        if ($k === '' && $create) {
            $k = bin2hex(random_bytes(12));
            Settings::set('chalani_public_key', $k, 'string', 'chalani');
        }
        return $k;
    }

    public static function rotatePublicKey(int $by): string
    {
        $k = bin2hex(random_bytes(12));
        Settings::set('chalani_public_key', $k, 'string', 'chalani');
        Logger::audit('chalani.public_key_changed', 'settings', 'chalani_public_key', null, null, 'Public chalani link changed by admin #' . $by);
        return $k;
    }

    public static function checkPublicKey(string $k): bool
    {
        $real = self::publicKey(false);
        return self::publicOn() && $real !== '' && $k !== '' && hash_equals($real, $k);
    }

    public static function publicUrl(?string $date = null): string
    {
        return self::staffBase() . '/chalani-live.php?k=' . rawurlencode(self::publicKey())
            . ($date !== null && $date !== '' ? '&d=' . rawurlencode($date) : '');
    }

    /** "98•••••123" — enough for an agent to recognise, not to harvest. */
    public static function maskPhone(string $p): string
    {
        $d = preg_replace('/\D+/', '', $p) ?? '';
        if (strlen($d) < 6) {
            return $d === '' ? '' : '•••';
        }
        return substr($d, 0, 2) . str_repeat('•', max(3, strlen($d) - 5)) . substr($d, -3);
    }

    /* =================================================================
     *  4. Cancel fine
     * ================================================================= */

    private static bool $fineReady = false;

    public static function ensureFines(): bool
    {
        if (self::$fineReady) {
            return true;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS booking_fines (
                    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    booking_id     BIGINT UNSIGNED NOT NULL,
                    amount         DECIMAL(10,2) NOT NULL,
                    reason         VARCHAR(200) NULL,
                    applied_to     ENUM('refund','agent','record') NOT NULL DEFAULT 'record',
                    agent_admin_id BIGINT UNSIGNED NULL,
                    ledger_id      BIGINT UNSIGNED NULL,
                    created_by     BIGINT UNSIGNED NULL,
                    created_at     DATETIME NOT NULL,
                    KEY idx_bf_booking (booking_id),
                    KEY idx_bf_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$fineReady = true;
        } catch (Throwable $e) {
            Logger::warning('booking_fines unavailable: ' . $e->getMessage());
        }
        return self::$fineReady;
    }

    public static function fineOn(): bool
    {
        return Settings::getBool('cancel_fine_on', true);
    }

    public static function fineDefault(): float
    {
        return max(0.0, Settings::getFloat('cancel_fine_default', 0.0));
    }

    /**
     * Apply an office-chosen fine to a booking that was JUST cancelled.
     * Never moves money by itself: a pending refund shrinks (the owner pays
     * refunds by hand), or the selling agent owes it (cash_due), or it is
     * only recorded.
     *
     * @param array<string, mixed> $admin
     */
    public static function applyCancelFine(int $bookingId, float $fine, string $reason, array $admin): string
    {
        $fine = round(max(0.0, min(100000.0, $fine)), 2);
        if ($fine <= 0 || !self::ensureFines()) {
            return '';
        }
        $b = Database::fetch(
            'SELECT b.*, a.role AS agent_role FROM bookings b LEFT JOIN admins a ON a.id = b.sold_by_admin_id WHERE b.id = :i',
            ['i' => $bookingId]
        );
        if ($b === null) {
            return '';
        }
        $appliedTo = 'record';
        $agentId   = null;
        $ledgerId  = null;
        $say       = '';
        $refund    = round((float) ($b['refund_amount'] ?? 0), 2);
        if ((string) ($b['refund_status'] ?? '') === 'pending' && $refund > 0) {
            $new = round(max(0.0, $refund - $fine), 2);
            Database::update('bookings', [
                'refund_amount' => $new,
                'refund_status' => $new > 0 ? 'pending' : 'none',
            ], 'id = :i', ['i' => $bookingId]);
            $appliedTo = 'refund';
            $say = 'refund ' . inr($refund) . ' → ' . inr($new);
        } else {
            $pay = Database::fetch('SELECT status, method FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $bookingId]);
            if (strtolower((string) ($b['agent_role'] ?? '')) === 'agent' && (int) ($b['sold_by_admin_id'] ?? 0) > 0
                && (string) ($pay['method'] ?? '') === 'cash' && (string) ($pay['status'] ?? '') === 'verified') {
                $agentId = (int) $b['sold_by_admin_id'];
                try {
                    $ledgerId = AgentWallet::record($agentId, 'cash_due', $fine, [
                        'note' => 'Cancel fine ' . $b['pnr'] . ($reason !== '' ? ' · ' . $reason : ''),
                        'ref'  => (string) $b['pnr'],
                        'by'   => (int) ($admin['id'] ?? 0),
                    ]);
                    $appliedTo = 'agent';
                    $say = 'agent owes ' . inr($fine);
                } catch (Throwable $e) {
                    $say = 'recorded (agent wallet: ' . $e->getMessage() . ')';
                }
            } else {
                $say = 'recorded';
            }
        }
        Database::insert('booking_fines', [
            'booking_id'     => $bookingId,
            'amount'         => $fine,
            'reason'         => Security::clean($reason, 200) ?: null,
            'applied_to'     => $appliedTo,
            'agent_admin_id' => $agentId,
            'ledger_id'      => $ledgerId,
            'created_by'     => (int) ($admin['id'] ?? 0) ?: null,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        Logger::audit('booking.cancel_fine', 'booking', (string) $b['pnr'], null,
            ['fine' => $fine, 'applied_to' => $appliedTo], $say . ($reason !== '' ? ' · ' . $reason : ''));
        return '💸 Fine ' . inr($fine) . ' — ' . $say;
    }

    /** @return array<int, float> booking id => total fine */
    public static function finesFor(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $i): bool => $i > 0));
        if ($ids === [] || !self::ensureFines()) {
            return [];
        }
        $out = [];
        try {
            foreach (Database::fetchAll('SELECT booking_id, SUM(amount) s FROM booking_fines WHERE booking_id IN (' . implode(',', $ids) . ') GROUP BY booking_id') as $r) {
                $out[(int) $r['booking_id']] = (float) $r['s'];
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /* =================================================================
     *  5. Staff door — agents and staff work on .network only
     * ================================================================= */

    public static function staffHost(): string
    {
        $h = strtolower(trim(Settings::getString('staff_domain', 'shreehariglobal.network')));
        $h = (string) preg_replace('~^https?://~', '', $h);
        $h = (string) preg_replace('~/.*$~', '', $h);
        return $h;
    }

    /** @return list<string> the customer site's own hosts (from APP_URL) */
    public static function customerHosts(): array
    {
        $h = strtolower((string) parse_url(APP_URL, PHP_URL_HOST));
        if ($h === '') {
            return [];
        }
        $alt = str_starts_with($h, 'www.') ? substr($h, 4) : 'www.' . $h;
        return array_values(array_unique([$h, $alt]));
    }

    /** True on the live customer domain (never on localhost / a test host). */
    public static function isLiveCustomerHost(string $host): bool
    {
        $host = strtolower((string) preg_replace('/:\d+$/', '', $host));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        return in_array($host, self::customerHosts(), true);
    }

    /** Base URL for staff / agent links: the .network door on live, APP_URL elsewhere. */
    public static function staffBase(): string
    {
        $appHost = strtolower((string) parse_url(APP_URL, PHP_URL_HOST));
        $staff   = self::staffHost();
        if ($staff === '' || !self::isLiveCustomerHost($appHost)) {
            return rtrim(APP_URL, '/');
        }
        return 'https://' . $staff;
    }

    public static function staffUrl(string $path = ''): string
    {
        return self::staffBase() . '/' . ltrim($path, '/');
    }

    public static function isStaffPath(string $path): bool
    {
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            return !str_starts_with($path, '/admin/api/');
        }
        if ($path === '/team' || str_starts_with($path, '/team/') || $path === '/me' || str_starts_with($path, '/me/')) {
            return true;
        }
        return in_array($path, ['/join.php', '/join-form.php', '/agent-signup.php'], true);
    }

    /**
     * Called from bootstrap for every web request: a staff / agent page
     * opened on the customer domain moves to the staff door, same path and
     * query. GET / HEAD navigations only (a form post or an XHR is never
     * bounced mid-flight); switch off with setting staff_door_only = 0.
     */
    public static function enforceStaffDoor(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }
        $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        if (!self::isLiveCustomerHost($host)) {
            return;
        }
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if (!self::isStaffPath($path)) {
            return;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest') {
            return;
        }
        try {
            if (!Settings::getBool('staff_door_only', true)) {
                return;
            }
            $staff = self::staffHost();
        } catch (Throwable $e) {
            return;   // settings unreadable: never lock anyone out
        }
        if ($staff === '' || $staff === $host || 'www.' . $staff === $host || in_array($staff, self::customerHosts(), true)) {
            return;
        }
        header('Cache-Control: no-store');
        header('Location: https://' . $staff . $uri, true, 302);
        exit;
    }

    /**
     * 12 Nov 2026 (owner: ".network ra .in chhuttai app — .network kholda
     * suru mai pattern / PIN lock"): the bare root of the staff door is not
     * the customer site. "/" on shreehariglobal.network opens the staff lock
     * screen; every other path (tickets, pay links, ?counter=1 booking) is
     * untouched. Switch off with setting staff_root_lock = 0.
     */
    public static function enforceStaffRoot(): void
    {
        if (PHP_SAPI === 'cli' || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return;
        }
        $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        $uri  = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        if ($uri !== '/' && $uri !== '/index.php') {
            return;   // any query string (?counter=1, ?ref=) keeps the old behaviour
        }
        try {
            $staff = self::staffHost();
            if ($staff === '' || ($host !== $staff && $host !== 'www.' . $staff) || in_array($host, self::customerHosts(), true)
                || !Settings::getBool('staff_root_lock', true)) {
                return;
            }
        } catch (Throwable $e) {
            return;
        }
        header('Cache-Control: no-store');
        header('Location: /admin/login.php', true, 302);
        exit;
    }
}
