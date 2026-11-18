<?php
/**
 * includes/countershift.php — a ticket window's shift and its cash drawer.
 *
 * The question a counter answers every evening is "how much cash should be in
 * this drawer?", and until 19 Sep 2026 it was answered with a notebook. The
 * register already knows: every rupee a staff member takes is a payments row
 * they verified (a counter sale is inserted 'verified' by the seller, a
 * pay-on-boarding ticket is settled by whoever took the cash). So the person
 * at the window types ONE number — what they counted — and the rest is read.
 *
 *   open   — one number: the float in the drawer.
 *   totals — READ from payments: verified_by = this person, verified_at inside
 *            the shift. cash + cod = the drawer; upi/esewa/bank/wallet = not
 *            in the drawer, shown so the day still adds up.
 *   close  — counted cash (typed, or built on the note pad), optional cash
 *            paid out (a refund or an expense given from the drawer).
 *            expected = opening + cash sales − paid out; variance = counted −
 *            expected. The numbers are FROZEN on the row at close, so a
 *            payment corrected next week cannot rewrite last night's count.
 *
 * NOT on the sale path. Nothing here can refuse, delay or change a booking;
 * the shift only reads what selling already wrote. Switch: counter_shift_on
 * (default off).
 *
 * One open shift per person — UNIQUE(admin_id, is_open), is_open NULL once
 * closed — so two tabs cannot open two drawers.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class CounterShift
{
    /** Indian notes and coins, largest first, for the counting pad. */
    public const DENOMINATIONS = [500, 200, 100, 50, 20, 10, 5, 2, 1];

    /**
     * The notes a drawer is counted in, for the money it actually holds.
     *
     * Nepal circulates a 1000 note and has no 200, so a Nepalgunj cashier
     * counting on the Indian pad could not enter their largest note at all —
     * the pad total would never match and every close would read as short.
     */
    public static function denominationsFor(string $currency): array
    {
        return CounterDesk::DENOMINATIONS[strtoupper($currency)] ?? self::DENOMINATIONS;
    }

    /** The money THIS drawer holds — the desk's, not the company's. */
    public static function currencyOf(?array $shift): string
    {
        $cur = strtoupper((string) ($shift['currency'] ?? ''));

        return isset(CounterDesk::DENOMINATIONS[$cur]) ? $cur : 'INR';
    }

    /** Are the desk columns on counter_shifts yet? */
    private static function deskColumns(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Database::fetch("SHOW COLUMNS FROM counter_shifts LIKE 'counter_code'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    public static function enabled(): bool
    {
        return Settings::getBool('counter_shift_on', false);
    }

    /** The open shift of this person, or null. */
    public static function current(int $adminId): ?array
    {
        if ($adminId <= 0) {
            return null;
        }
        return Database::fetch(
            'SELECT * FROM counter_shifts WHERE admin_id = :a AND is_open = 1 LIMIT 1',
            ['a' => $adminId]
        );
    }

    /**
     * Start a shift.
     *
     * @return array<string,mixed> the open shift (the existing one when a
     *         second tab raced us — opening twice is not an error worth
     *         showing a cashier)
     */
    public static function open(int $adminId, float $openingCash, string $note = ''): array
    {
        if ($adminId <= 0) {
            throw new RuntimeException('Sign in again to open a shift.');
        }
        if ($openingCash < 0 || $openingCash > 10000000) {
            throw new RuntimeException('Opening cash must be zero or more. / सुरुको नगद ० वा बढी हुनुपर्छ।');
        }

        /* Freeze the desk and its money on the drawer (26 Sep 2026). A
           cashier who is moved to another window tomorrow must not take
           tonight's count with them, and a Nepalgunj drawer counts NPR. */
        $desk = [];
        if (self::deskColumns()) {
            $code = CounterDesk::forAdmin($adminId)['code'];
            $desk = [
                'counter_code' => $code !== '' ? $code : null,
                'currency'     => CounterDesk::currency($code),
            ];
            if (CounterDesk::hasColumn('counter_shifts', 'entity')) {
                $desk['entity'] = $code !== '' ? CounterDesk::entity($code) : null;   // whose drawer (4 Oct 2026)
            }
        }

        Database::insertIgnore('counter_shifts', $desk + [
            'admin_id'     => $adminId,
            'is_open'      => 1,
            'opened_at'    => date('Y-m-d H:i:s'),
            'opening_cash' => round($openingCash, 2),
            'note'         => $note !== '' ? mb_substr($note, 0, 255) : null,
        ]);

        $shift = self::current($adminId);
        if ($shift === null) {
            throw new RuntimeException('The shift could not be opened. Please try again.');
        }
        return $shift;
    }

    /**
     * What this person took between two moments, read from the register.
     *
     * A cancelled or rejected booking still counts: the cash WAS taken, and
     * giving it back is a separate act the cashier records as "paid out".
     * Hiding it here would make the drawer look short by exactly the refund.
     *
     * At an NPR desk the drawer holds NPR, so `cash` is read from what was
     * physically taken (payments.local_amount) rather than from the rupee the
     * ledger runs on. The two are the same number at every Indian desk.
     *
     * @return array{cash:float, upi:float, tickets:int, seats:int, cancelled:int, cashInr:float}
     */
    public static function totals(int $adminId, string $from, ?string $to = null, string $currency = 'INR', float $fxNow = 1.0): array
    {
        $to  = $to ?? date('Y-m-d H:i:s');
        /* An NPR drawer counts what was physically taken. A row written before
           the local columns existed has no NPR on it, so it is converted at
           the rate frozen on that payment and, failing that, at the desk's
           rate today — which is stated on the screen rather than hidden. */
        $local = strtoupper($currency) !== 'INR' && self::localColumns();
        $cashExpr = $local
            ? 'COALESCE(p.local_amount, p.amount * COALESCE(p.fx_rate, :fx))'
            : 'p.amount';
        $row = Database::fetch(
            "SELECT
                COALESCE(SUM(CASE WHEN p.method IN ('cash','cod') THEN {$cashExpr} END), 0)  AS cash,
                COALESCE(SUM(CASE WHEN p.method IN ('cash','cod') THEN p.amount END), 0)     AS cash_inr,
                COALESCE(SUM(CASE WHEN p.method NOT IN ('cash','cod') THEN p.amount END), 0) AS upi,
                COUNT(DISTINCT p.booking_id)                                                 AS tickets,
                COUNT(DISTINCT CASE WHEN b.status IN ('cancelled','rejected') THEN b.id END) AS cancelled
               FROM payments p
               JOIN bookings b ON b.id = p.booking_id
              WHERE p.verified_by = :a AND p.status = 'verified'
                AND p.verified_at >= :f AND p.verified_at <= :t",
            ['a' => $adminId, 'f' => $from, 't' => $to] + ($local ? ['fx' => max(0.0001, $fxNow)] : [])
        ) ?? [];

        $seats = (int) Database::scalar(
            "SELECT COALESCE(SUM(l.seat_count), 0)
               FROM booking_legs l
              WHERE l.booking_id IN (
                    SELECT DISTINCT p.booking_id FROM payments p
                     WHERE p.verified_by = :a AND p.status = 'verified'
                       AND p.verified_at >= :f AND p.verified_at <= :t
                  )",
            ['a' => $adminId, 'f' => $from, 't' => $to],
            0
        );

        return [
            'cash'      => round((float) ($row['cash'] ?? 0), 2),
            'cashInr'   => round((float) ($row['cash_inr'] ?? $row['cash'] ?? 0), 2),
            'upi'       => round((float) ($row['upi'] ?? 0), 2),
            'tickets'   => (int) ($row['tickets'] ?? 0),
            'seats'     => $seats,
            'cancelled' => (int) ($row['cancelled'] ?? 0),
        ];
    }

    /** Live view of an open shift: the row plus what the register says now. */
    public static function live(array $shift): array
    {
        $t = self::totals((int) $shift['admin_id'], (string) $shift['opened_at'], null,
            self::currencyOf($shift), CounterDesk::rate((string) ($shift['counter_code'] ?? '')));
        // 29 Oct 2026: expenses already paid from the drawer are out of it.
        $spent = self::expenseTotal((int) $shift['id']);
        return $t + ['expenses' => $spent, 'expected' => round((float) $shift['opening_cash'] + $t['cash'] - $spent, 2)];
    }

    /* =================================================================
     *  Expenses + the closing signature (29 Oct 2026, management upgrade
     *  2.3). A drawer pays for tea, a pen, a refund handed back — each is
     *  now one row with what it was for, instead of one lump "paid out"
     *  typed at night. They are subtracted from what should be in the
     *  drawer, and at close their total IS the cash paid out (plus any
     *  extra the closer still types). The closer types their name to sign
     *  the count, and may name who took the drawer over.
     * ================================================================= */

    public const EXPENSE_CATEGORIES = [
        'tea'        => 'Tea / snacks · चिया नास्ता',
        'stationery' => 'Stationery · स्टेसनरी',
        'cleaning'   => 'Cleaning · सरसफाइ',
        'refund'     => 'Refund given · फिर्ता दिएको',
        'transport'  => 'Transport · यातायात',
        'other'      => 'Other · अन्य',
    ];

    private static ?bool $extrasReady = null;

    /**
     * The expenses table and the two signature columns, created on first
     * use. Call it OUTSIDE a transaction (DDL commits implicitly).
     */
    public static function extrasReady(): bool
    {
        if (self::$extrasReady !== null) {
            return self::$extrasReady;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS counter_shift_expenses (
                    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    shift_id    INT UNSIGNED  NOT NULL,
                    category    VARCHAR(20)   NOT NULL,
                    amount      DECIMAL(12,2) NOT NULL,
                    note        VARCHAR(255)  NULL,
                    created_by  INT UNSIGNED  NULL,
                    created_at  DATETIME      NOT NULL,
                    KEY idx_shift (shift_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            foreach (['signed_name' => 'VARCHAR(120) NULL', 'handover_to' => 'VARCHAR(120) NULL'] as $col => $def) {
                if (Database::fetch("SHOW COLUMNS FROM counter_shifts LIKE '{$col}'") === null) {
                    Database::query("ALTER TABLE counter_shifts ADD COLUMN {$col} {$def}");
                }
            }
            self::$extrasReady = true;
        } catch (Throwable $e) {
            Logger::warning('counter shift extras unavailable: ' . $e->getMessage(), [], 'agent');
            self::$extrasReady = false;
        }
        return self::$extrasReady;
    }

    /** Record one expense paid from an OPEN shift's drawer. */
    public static function addExpense(int $shiftId, int $byAdminId, string $category, float $amount, string $note = ''): int
    {
        if (!self::extrasReady()) {
            throw new RuntimeException('Expenses are not available yet — ask the developer to check the database.');
        }
        if (!isset(self::EXPENSE_CATEGORIES[$category])) {
            throw new RuntimeException('Pick what the money was for. / केका लागि दिनुभयो छान्नुहोस्।');
        }
        $amount = round($amount, 2);
        if ($amount <= 0 || $amount > 100000) {
            throw new RuntimeException('Enter an amount between 1 and 1,00,000. / सही रकम लेख्नुहोस्।');
        }
        $shift = Database::fetch('SELECT id, is_open FROM counter_shifts WHERE id = :i', ['i' => $shiftId]);
        if ($shift === null || (int) ($shift['is_open'] ?? 0) !== 1) {
            throw new RuntimeException('Expenses can be added only while the shift is open.');
        }
        $note = Security::clean($note, 255);
        $id = Database::insert('counter_shift_expenses', [
            'shift_id'   => $shiftId,
            'category'   => $category,
            'amount'     => $amount,
            'note'       => $note !== '' ? $note : null,
            'created_by' => $byAdminId > 0 ? $byAdminId : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::audit('shift.expense_add', 'counter_shift', (string) $shiftId, null,
            ['expense' => $id, 'category' => $category, 'amount' => $amount], $note);
        return $id;
    }

    /** Take back an expense typed by mistake — only while its shift is open. */
    public static function removeExpense(int $expenseId, int $byAdminId): int
    {
        if (!self::extrasReady()) {
            throw new RuntimeException('Expenses are not available yet.');
        }
        $row = Database::fetch(
            'SELECT e.*, s.is_open, s.admin_id FROM counter_shift_expenses e JOIN counter_shifts s ON s.id = e.shift_id WHERE e.id = :i',
            ['i' => $expenseId]
        );
        if ($row === null) {
            throw new RuntimeException('Expense not found.');
        }
        if ((int) ($row['is_open'] ?? 0) !== 1) {
            throw new RuntimeException('The shift is closed — its expenses are part of the count now.');
        }
        Database::delete('counter_shift_expenses', 'id = :i', ['i' => $expenseId]);
        Logger::audit('shift.expense_remove', 'counter_shift', (string) $row['shift_id'],
            ['expense' => $expenseId, 'category' => $row['category'], 'amount' => (float) $row['amount']], null, 'removed by admin #' . $byAdminId);
        return (int) $row['shift_id'];
    }

    /** @return list<array<string,mixed>> */
    public static function expenses(int $shiftId): array
    {
        if ($shiftId <= 0 || !self::extrasReady()) {
            return [];
        }
        try {
            return Database::fetchAll(
                'SELECT e.*, a.full_name AS by_name FROM counter_shift_expenses e LEFT JOIN admins a ON a.id = e.created_by
                  WHERE e.shift_id = :s ORDER BY e.id',
                ['s' => $shiftId]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    public static function expenseTotal(int $shiftId): float
    {
        if ($shiftId <= 0 || !self::extrasReady()) {
            return 0.0;
        }
        try {
            return round((float) Database::scalar('SELECT COALESCE(SUM(amount), 0) FROM counter_shift_expenses WHERE shift_id = :s', ['s' => $shiftId], 0), 2);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    /**
     * Payments this person verified inside a shift's window — the body of
     * the end-of-day shift report.
     *
     * @return list<array<string,mixed>>
     */
    public static function payments(array $shift): array
    {
        $to = (string) ($shift['closed_at'] ?? '') !== '' ? (string) $shift['closed_at'] : date('Y-m-d H:i:s');
        try {
            return Database::fetchAll(
                "SELECT p.id, p.method, p.amount, p.verified_at, b.pnr, b.status AS booking_status,
                        (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS passenger
                   FROM payments p
                   JOIN bookings b ON b.id = p.booking_id
                  WHERE p.verified_by = :a AND p.status = 'verified'
                    AND p.verified_at >= :f AND p.verified_at <= :t
                  ORDER BY p.verified_at",
                ['a' => (int) $shift['admin_id'], 'f' => (string) $shift['opened_at'], 't' => $to]
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Shifts opened inside [$from, $to] (Y-m-d, inclusive) — the history
     * list and its CSV. $scopeAdminId as in recent().
     *
     * @return list<array<string,mixed>>
     */
    public static function between(?int $scopeAdminId, string $from, string $to, int $limit = 500): array
    {
        $limit  = max(1, min(2000, $limit));
        $params = ['f' => $from . ' 00:00:00', 't' => date('Y-m-d', (int) strtotime($to . ' +1 day')) . ' 00:00:00'];
        $where  = 's.opened_at >= :f AND s.opened_at < :t';
        if ($scopeAdminId !== null) {
            $where .= ' AND s.admin_id = :a';
            $params['a'] = $scopeAdminId;
        }
        return Database::fetchAll(
            "SELECT s.*, a.full_name AS admin_name
               FROM counter_shifts s
               LEFT JOIN admins a ON a.id = s.admin_id
              WHERE {$where}
              ORDER BY s.is_open DESC, s.opened_at DESC
              LIMIT {$limit}",
            $params
        );
    }

    /** Are the local-money columns on payments yet? */
    private static function localColumns(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Database::fetch("SHOW COLUMNS FROM payments LIKE 'local_amount'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /**
     * Sum a note-pad count. Unknown denominations and negative counts are
     * refused rather than ignored — a silent drop here is a wrong drawer.
     *
     * @param array<int|string, int|string> $counts denomination => pieces
     */
    public static function sumDenominations(array $counts, string $currency = 'INR'): float
    {
        $notes = self::denominationsFor($currency);
        $sym   = CounterDesk::SYMBOL[strtoupper($currency)] ?? '';
        $sum   = 0;
        foreach ($counts as $d => $n) {
            $d = (int) $d;
            $n = (int) $n;
            if ($n === 0) {
                continue;
            }
            if (!in_array($d, $notes, true) || $n < 0 || $n > 100000) {
                throw new RuntimeException('Check the note count for ' . $sym . $d . '.');
            }
            $sum += $d * $n;
        }
        return (float) $sum;
    }

    /**
     * Close a shift and freeze its numbers.
     *
     * @param array<int|string,int|string> $denoms optional note-pad counts; when
     *        given they ARE the counted cash (the typed figure is ignored), so
     *        the stored breakdown and the stored total can never disagree.
     * @return array<string,mixed> the closed row
     */
    public static function close(int $shiftId, int $byAdminId, float $countedCash, float $paidOut = 0.0, array $denoms = [], string $note = '', string $signedName = '', string $handoverTo = ''): array
    {
        $denoms = array_filter(array_map('intval', $denoms), static fn(int $n): bool => $n !== 0);
        if ($denoms !== []) {
            $cur = self::currencyOf(Database::fetch('SELECT currency FROM counter_shifts WHERE id = :i', ['i' => $shiftId]));
            $countedCash = self::sumDenominations($denoms, $cur);
        }
        if ($countedCash < 0 || $paidOut < 0) {
            throw new RuntimeException('Amounts must be zero or more. / रकम ० वा बढी हुनुपर्छ।');
        }
        /* 29 Oct 2026: the itemised expenses are part of what left the drawer
           (none recorded = exactly the old behaviour), and the signature
           columns are made sure of here — never inside the transaction. */
        $extras     = self::extrasReady();
        $paidOut    = round($paidOut + self::expenseTotal($shiftId), 2);
        $signedName = mb_substr(trim(Security::clean($signedName, 120)), 0, 120);
        $handoverTo = mb_substr(trim(Security::clean($handoverTo, 120)), 0, 120);

        return Database::transaction(static function () use ($shiftId, $byAdminId, $countedCash, $paidOut, $denoms, $note, $extras, $signedName, $handoverTo): array {
            $rows  = Database::fetchForUpdate('SELECT * FROM counter_shifts WHERE id = :i', ['i' => $shiftId]);
            $shift = $rows[0] ?? null;
            if ($shift === null) {
                throw new RuntimeException('Shift not found.');
            }
            if ((int) ($shift['is_open'] ?? 0) !== 1) {
                return $shift;   // already closed (double tap) — the first close stands
            }

            $now      = date('Y-m-d H:i:s');
            $t        = self::totals((int) $shift['admin_id'], (string) $shift['opened_at'], $now,
                self::currencyOf($shift), CounterDesk::rate((string) ($shift['counter_code'] ?? '')));
            $expected = round((float) $shift['opening_cash'] + $t['cash'] - $paidOut, 2);

            Database::update('counter_shifts', [
                'is_open'       => null,
                'closed_at'     => $now,
                'cash_sales'    => $t['cash'],
                'upi_sales'     => $t['upi'],
                'tickets'       => $t['tickets'],
                'seats'         => $t['seats'],
                'cash_paid_out' => round($paidOut, 2),
                'expected_cash' => $expected,
                'counted_cash'  => round($countedCash, 2),
                'variance'      => round($countedCash - $expected, 2),
                'denominations' => $denoms !== [] ? json_encode($denoms) : null,
                'note'          => $note !== '' ? mb_substr($note, 0, 255) : ($shift['note'] ?? null),
                'closed_by'     => $byAdminId > 0 ? $byAdminId : null,
            ] + ($extras ? [
                'signed_name'   => $signedName !== '' ? $signedName : null,
                'handover_to'   => $handoverTo !== '' ? $handoverTo : null,
            ] : []), 'id = :i', ['i' => $shiftId]);

            return Database::fetch('SELECT * FROM counter_shifts WHERE id = :i', ['i' => $shiftId]) ?? $shift;
        });
    }

    /** ok / short / over, inside the office's tolerance. */
    public static function verdict(float $variance): string
    {
        $tol = max(0.0, Settings::getFloat('counter_shift_tolerance', 0.0));
        if (abs($variance) <= $tol + 0.004) {
            return 'ok';
        }
        return $variance < 0 ? 'short' : 'over';
    }

    /**
     * Recent shifts. $scopeAdminId = only that person's (a cashier sees their
     * own drawer, never a colleague's); null = everyone (office).
     *
     * @return list<array<string,mixed>>
     */
    public static function recent(?int $scopeAdminId, int $limit = 60): array
    {
        $limit  = max(1, min(300, $limit));
        $where  = $scopeAdminId !== null ? 'WHERE s.admin_id = :a' : '';
        $params = $scopeAdminId !== null ? ['a' => $scopeAdminId] : [];
        return Database::fetchAll(
            "SELECT s.*, a.full_name AS admin_name
               FROM counter_shifts s
               LEFT JOIN admins a ON a.id = s.admin_id
               {$where}
              ORDER BY s.is_open DESC, s.opened_at DESC
              LIMIT {$limit}",
            $params
        );
    }

    /* =================================================================
     *  DAY CLOSE (19 Nov 2026, WA-6 - owner: "admin, agent, staff, counter
     *  mode bata sajilai aajako hisab bujhauna milos"). One step, no morning
     *  float: the register's day (CounterBook::day, read-only) is shown, the
     *  person types what they counted (+ expenses, a note, their name) and
     *  ONE row per desk + day is written to counter_day_close - a second
     *  submit the same day corrects it (upsert on day_key, audited with the
     *  before / after). Writes nothing to bookings, payments or the ledger.
     *  Switch: counter_day_close_on (default on). Shifts are untouched.
     * ================================================================= */

    public static function dayCloseOn(): bool
    {
        return Settings::getBool('counter_day_close_on', true);
    }

    public static function dayCloseReady(): bool
    {
        static $has = null;
        if ($has === null) {
            try {
                $has = Database::fetch("SHOW TABLES LIKE 'counter_day_close'") !== null;
            } catch (Throwable $e) {
                $has = false;
            }
        }
        return $has;
    }

    /** The book line a person closes: their desk, else their own sales ('AG:<id>'). */
    public static function dayKeyFor(int $adminId): string
    {
        $code = CounterDesk::forAdmin($adminId)['code'];
        return $code !== '' ? $code : ($adminId > 0 ? 'AG:' . $adminId : '');
    }

    /** 'YYYY-MM-DD|MSA' - the upsert key ('' online = ONLINE). */
    public static function dayKey(string $day, string $deskKey): string
    {
        return $day . '|' . ($deskKey === '' ? 'ONLINE' : $deskKey);
    }

    /**
     * The cash that should be in ONE book line's drawer, in that line's money
     * (CounterBook::line()). INR: the rupee cash + cod taken. NPR: cashLocal,
     * summed payment by payment; a line from an older CounterBook without it
     * falls back to cash x the desk's rate.
     *
     * @param array<string,mixed> $line
     */
    public static function dayDrawer(array $line): float
    {
        if ((string) ($line['currency'] ?? 'INR') === 'INR') {
            return (float) ($line['cash'] ?? 0);
        }
        if (array_key_exists('cashLocal', $line)) {
            return (float) $line['cashLocal'];
        }
        return (float) round((float) ($line['cash'] ?? 0) * (float) ($line['rate'] ?? 1));
    }

    /**
     * File (or correct) the day's count for one book line.
     *
     * @param bool $isOffice the office may close any line and any date up to
     *                       today; anyone else only their own line, today or
     *                       yesterday (a late close after midnight)
     * @return array<string,mixed> the saved row
     */
    public static function closeDay(int $byAdminId, string $deskKey, string $day, float $counted, float $expenses = 0.0,
                                    ?float $countedNpr = null, string $note = '', string $signed = '', bool $isOffice = false): array
    {
        require_once INCLUDE_PATH . '/counterbook.php';
        if (!self::dayCloseReady()) {
            throw new RuntimeException('Day close is not set up yet (database/upgrade-2026-11-19-u10-day-close.sql).');
        }
        if ($byAdminId <= 0) {
            throw new RuntimeException('Sign in again. / फेरि लगइन गर्नुहोस्।');
        }
        if (!Security::isValidDate($day) || $day > todayISO()) {
            throw new RuntimeException('Pick a day up to today. / आज वा अघिको दिन छान्नुहोस्।');
        }
        $deskKey = strtoupper(trim($deskKey));
        if ($deskKey !== '' && preg_match('/^(AG:\d{1,10}|[A-Z0-9_-]{1,16})$/', $deskKey) !== 1) {
            throw new RuntimeException('Unknown desk.');
        }
        if (!$isOffice) {
            if ($deskKey !== self::dayKeyFor($byAdminId)) {
                throw new RuntimeException('You can close only your own desk / your own sales. / आफ्नै काउन्टरको हिसाब मात्र।');
            }
            if ($day < addDaysISO(todayISO(), -1)) {
                throw new RuntimeException('Only today or yesterday - ask the office for an older day. / पुरानो दिनको लागि अफिसलाई भन्नुहोस्।');
            }
        } elseif ($deskKey !== '' && !str_starts_with($deskKey, 'AG:') && CounterDesk::get($deskKey) === null) {
            throw new RuntimeException('Unknown desk.');
        }
        if ($counted < 0 || $counted > 100000000 || $expenses < 0 || $expenses > 10000000 || ($countedNpr !== null && $countedNpr < 0)) {
            throw new RuntimeException('Amounts must be zero or more. / रकम ० वा बढी हुनुपर्छ।');
        }
        $signed = mb_substr(trim(Security::clean($signed, 120)), 0, 120);
        if (mb_strlen($signed) < 3) {
            throw new RuntimeException('Type your full name to sign the count. / गन्ती पुष्टि गर्न आफ्नो पूरा नाम लेख्नुहोस्।');
        }

        $line = CounterBook::line($day, $deskKey);
        $cur  = (string) ($line['currency'] ?? 'INR');
        /* what should be in the drawer, in the drawer's money: an NPR desk counts,
           payment by payment and cash / cod only, the NPR it physically took
           (payments.local_amount), else the rupee x the rate frozen on that
           payment, else x the desk's rate (stated on the card) - so a pay-at-
           boarding ticket collected with no NPR stamped is never left out
           (CounterBook 'cashLocal', review fix 19 Nov 2026) */
        $registerCash = self::dayDrawer($line);
        $expected = round($registerCash - $expenses, 2);
        $now = date('Y-m-d H:i:s');
        $key = self::dayKey($day, $deskKey);
        $row = [
            'day_key'        => $key,
            'business_date'  => $day,
            'desk_key'       => $deskKey,
            'currency'       => $cur,
            'tickets'        => (int) $line['tickets'],
            'pax'            => (int) $line['pax'],
            'cash_inr'       => round((float) $line['cash'], 2),
            'online_inr'     => round((float) $line['online'], 2),
            'npr_register'   => round((float) $line['takenNpr'], 2),
            'pending_inr'    => round((float) $line['pending'], 2),
            'commission_inr' => round((float) $line['commission'], 2),
            'refund_inr'     => round((float) $line['refund'], 2),
            'net_inr'        => round((float) $line['net'], 2),
            'expenses'       => round($expenses, 2),
            'counted_cash'   => round($counted, 2),
            'counted_npr'    => $countedNpr !== null ? round($countedNpr, 2) : null,
            'expected_cash'  => $expected,
            'variance'       => round($counted - $expected, 2),
            'note'           => ($n = mb_substr(trim(Security::clean($note, 255)), 0, 255)) !== '' ? $n : null,
            'signed_name'    => $signed,
            'closed_by'      => $byAdminId,
            'updated_at'     => $now,
        ];
        $before = Database::fetch('SELECT * FROM counter_day_close WHERE day_key = :k', ['k' => $key]);
        $cols = array_keys($row);
        $sql = 'INSERT INTO counter_day_close (' . implode(', ', $cols) . ', created_at) VALUES (:'
             . implode(', :', $cols) . ', :created_at) ON DUPLICATE KEY UPDATE '
             . implode(', ', array_map(static fn(string $c): string => $c . ' = VALUES(' . $c . ')', array_diff($cols, ['day_key', 'business_date', 'desk_key'])));
        Database::run($sql, $row + ['created_at' => $now]);
        $saved = Database::fetch('SELECT * FROM counter_day_close WHERE day_key = :k', ['k' => $key]) ?? $row;
        Logger::audit('dayclose.save', 'counter_day_close', $key, $before, $saved,
            ($before === null ? 'day closed' : 'day close corrected') . ' by admin #' . $byAdminId);
        return $saved;
    }

    /** @return list<array<string,mixed>> the day's closes (all, or one line). */
    public static function dayCloses(string $day, ?string $deskKey = null): array
    {
        if (!self::dayCloseReady() || !Security::isValidDate($day)) {
            return [];
        }
        $w = 'business_date = :d';
        $p = ['d' => $day];
        if ($deskKey !== null) {
            $w .= ' AND desk_key = :k';
            $p['k'] = $deskKey;
        }
        try {
            return Database::fetchAll("SELECT c.*, a.full_name AS by_name FROM counter_day_close c LEFT JOIN admins a ON a.id = c.closed_by WHERE {$w} ORDER BY c.desk_key", $p);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** The day-close slip, Nepali first, as the office reads it on WhatsApp. Counts and money only. */
    public static function dayCloseText(array $row, string $label): string
    {
        $cur = strtoupper((string) ($row['currency'] ?? 'INR'));
        $m   = static fn(float $n, string $c = ''): string => CounterDesk::format($n, $c !== '' ? $c : $cur);
        $v   = (float) $row['variance'];
        $ver = self::verdict($v);
        $tag = $ver === 'ok' ? '✅ मिल्यो' : ($ver === 'short' ? '🔴 कम: ' . $m(abs($v)) : '🟠 बढी: ' . $m($v));
        return '🧾 आजको हिसाब बुझाइयो · Day close — ' . $label . ' · ' . date('j M', (int) strtotime((string) $row['business_date'])) . "\n"
            . 'टिकट: ' . (int) $row['tickets'] . ' · यात्रु: ' . (int) $row['pax'] . "\n"
            . 'नगद: ' . $m((float) $row['cash_inr'], 'INR') . ((float) $row['npr_register'] > 0 ? ' (' . $m((float) $row['npr_register'], 'NPR') . ')' : '')
            . ' · अनलाइन: ' . $m((float) $row['online_inr'], 'INR') . "\n"
            . ((float) $row['commission_inr'] != 0.0 ? 'कमिसन: ' . $m((float) $row['commission_inr'], 'INR') . ' · ' : '')
            . 'जम्मा (नेट): ' . $m((float) $row['net_inr'], 'INR')
            . ((float) $row['pending_inr'] > 0 ? ' · बाँकी: ' . $m((float) $row['pending_inr'], 'INR') : '') . "\n"
            . ((float) $row['expenses'] > 0 ? 'खर्च: ' . $m((float) $row['expenses']) . "\n" : '')
            . 'हुनुपर्ने: ' . $m((float) $row['expected_cash']) . ' · गनेको: ' . $m((float) $row['counted_cash'])
            . ($row['counted_npr'] !== null && $cur === 'INR' ? ' + ' . $m((float) $row['counted_npr'], 'NPR') : '') . "\n"
            . $tag . ((string) ($row['signed_name'] ?? '') !== '' ? ' · सही: ' . $row['signed_name'] : '');
    }

    /** The closing summary, as the office reads it on WhatsApp. */
    public static function summaryText(array $shift, string $who): string
    {
        $v   = (float) $shift['variance'];
        $ver = self::verdict($v);
        /* A Nepalgunj drawer is counted in NPR, so the slip the office reads
           must say NPR — a rupee sign on a Nepali count is a wrong number. */
        $cur = self::currencyOf($shift);
        $inr = static fn(float $n): string => CounterDesk::format($n, $cur);
        $tag = $ver === 'ok' ? '✅ मिल्यो' : ($ver === 'short' ? '🔴 कम: ' . $inr(abs($v)) : '🟠 बढी: ' . $inr($v));

        return "🧾 शिफ्ट बन्द — " . $who . "\n"
            . substr((string) $shift['opened_at'], 0, 16) . ' → ' . substr((string) $shift['closed_at'], 11, 5) . "\n"
            . ((string) ($shift['counter_code'] ?? '') !== '' ? 'काउन्टर: ' . $shift['counter_code'] . "\n" : '')
            . "टिकट: " . (int) $shift['tickets'] . ' · सिट: ' . (int) $shift['seats'] . "\n"
            . "नगद बिक्री: " . $inr((float) $shift['cash_sales']) . "\n"
            . "UPI / बैंक: " . $inr((float) $shift['upi_sales']) . "\n"
            . "सुरुको नगद: " . $inr((float) $shift['opening_cash'])
            . ((float) $shift['cash_paid_out'] > 0 ? "\nनगद दिएको: " . $inr((float) $shift['cash_paid_out']) : '') . "\n"
            . "हुनुपर्ने: " . $inr((float) $shift['expected_cash']) . ' · गनेको: ' . $inr((float) $shift['counted_cash']) . "\n"
            . $tag;
    }
}
