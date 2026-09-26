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
        return $t + ['expected' => round((float) $shift['opening_cash'] + $t['cash'], 2)];
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
    public static function close(int $shiftId, int $byAdminId, float $countedCash, float $paidOut = 0.0, array $denoms = [], string $note = ''): array
    {
        $denoms = array_filter(array_map('intval', $denoms), static fn(int $n): bool => $n !== 0);
        if ($denoms !== []) {
            $cur = self::currencyOf(Database::fetch('SELECT currency FROM counter_shifts WHERE id = :i', ['i' => $shiftId]));
            $countedCash = self::sumDenominations($denoms, $cur);
        }
        if ($countedCash < 0 || $paidOut < 0) {
            throw new RuntimeException('Amounts must be zero or more. / रकम ० वा बढी हुनुपर्छ।');
        }

        return Database::transaction(static function () use ($shiftId, $byAdminId, $countedCash, $paidOut, $denoms, $note): array {
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
            ], 'id = :i', ['i' => $shiftId]);

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
            . "नगद बिक्री: " . inr((float) $shift['cash_sales']) . "\n"
            . "UPI / बैंक: " . inr((float) $shift['upi_sales']) . "\n"
            . "सुरुको नगद: " . inr((float) $shift['opening_cash'])
            . ((float) $shift['cash_paid_out'] > 0 ? "\nनगद दिएको: " . inr((float) $shift['cash_paid_out']) : '') . "\n"
            . "हुनुपर्ने: " . inr((float) $shift['expected_cash']) . ' · गनेको: ' . inr((float) $shift['counted_cash']) . "\n"
            . $tag;
    }
}
