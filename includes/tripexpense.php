<?php
/**
 * =====================================================================
 *  TripExpense — ट्रिप खर्च, one row per cost of one trip (25 Nov 2026, op5).
 *
 *  REUSES the trip_expenses table that admin/pnl.php, admin/accounting.php,
 *  MoneySummary, AiTools::financeDay and AiWatch already read. A new entry
 *  is one row that still fills the old shape — route_id + expense_date of
 *  the trip, the old column (diesel / toll / driver_pay / misc) and total,
 *  all in INR — so every existing reader counts it with no change. The
 *  columns added by database/upgrade-2026-11-25-op5-trip-expenses.sql carry
 *  the trip (schedule_id), the bus, the category, what was typed (amount +
 *  currency + the npr_per_inr rate used), diesel litres and a receipt photo.
 *  Without that migration add() quietly writes the old route + day shape.
 *
 *  Money rules: a cost is a record. Nothing here touches bookings, payments,
 *  agent_ledger or payroll, and nothing is ever marked PAID. Monthly salary
 *  stays in payroll; an allowance (भत्ता) here is the per-trip cash only.
 *
 *  Who: superadmin / manager / accountant (office: any row, any day) and
 *  counter (own rows, same day). Agents, support, scanner, viewer never.
 *  Switch: trip_expenses_on (default ON).
 * =====================================================================
 */
declare(strict_types=1);

final class TripExpense
{
    /** key => [icon, Nepali, English, old column]. Order = the button order. */
    public const CATEGORIES = [
        'diesel'           => ['⛽', 'डिजेल', 'Diesel', 'diesel'],
        'toll'             => ['🛣️', 'टोल', 'Toll', 'toll'],
        'driver_allowance' => ['👨‍✈️', 'ड्राइभर भत्ता', 'Driver allowance', 'driver_pay'],
        'staff_allowance'  => ['🧑‍🔧', 'स्टाफ भत्ता', 'Staff allowance', 'driver_pay'],
        'repair'           => ['🔧', 'मर्मत', 'Repair', 'misc'],
        'parking'          => ['🅿️', 'पार्किङ', 'Parking', 'misc'],
        'food'             => ['🍛', 'खाना', 'Food', 'misc'],
        'border'           => ['🛂', 'बोर्डर', 'Border', 'misc'],
        'other'            => ['➕', 'अन्य', 'Other', 'misc'],
    ];

    /** Roles that may enter; the first three are "office" (any row, any day). */
    public const OFFICE_ROLES = ['superadmin', 'manager', 'accountant'];
    public const ENTRY_ROLES  = ['superadmin', 'manager', 'accountant', 'counter'];

    /** Biggest single entry in INR (a typo guard, not a policy). */
    public const MAX_INR = 300000.0;

    public const RECEIPT_DIR = 'trip-receipts';

    /** Tests only: pretend the op5 migration has not run. */
    public static bool $forceLegacy = false;

    /** @var array<string,bool> */
    private static array $cols = [];

    public static function on(): bool
    {
        return Settings::getBool('trip_expenses_on', true);
    }

    public static function resetCache(): void
    {
        self::$cols = [];
    }

    public static function hasCol(string $col): bool
    {
        if (self::$forceLegacy) {
            return false;
        }
        if (!array_key_exists($col, self::$cols)) {
            try {
                self::$cols[$col] = preg_match('/^[a-z_]+$/', $col) === 1
                    && Database::fetch('SHOW COLUMNS FROM `trip_expenses` LIKE ' . Database::pdo()->quote($col)) !== null;
            } catch (Throwable $e) {
                self::$cols[$col] = false;
            }
        }
        return self::$cols[$col];
    }

    /** The per-trip columns are all there (the op5 migration ran). */
    public static function hasSchema(): bool
    {
        foreach (['schedule_id', 'bus_id', 'category', 'amount', 'currency'] as $c) {
            if (!self::hasCol($c)) {
                return false;
            }
        }
        return true;
    }

    public static function label(string $cat, bool $icon = true): string
    {
        $c = self::CATEGORIES[$cat] ?? self::CATEGORIES['other'];
        return ($icon ? $c[0] . ' ' : '') . $c[1] . ' · ' . $c[2];
    }

    /* ------------------------------------------------------------------
     *  Money: NPR -> INR at the office's own rate (npr_per_inr)
     * ---------------------------------------------------------------- */

    public static function rate(): float
    {
        $peg = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);
        return $peg > 0 ? $peg : 1.6;
    }

    public static function toInr(float $amount, string $currency, ?float $rate = null): float
    {
        if (strtoupper($currency) !== 'NPR') {
            return round($amount, 2);
        }
        $r = $rate !== null && $rate > 0 ? $rate : self::rate();
        return round($amount / $r, 2);
    }

    /* ------------------------------------------------------------------
     *  Who may do what
     * ---------------------------------------------------------------- */

    /** @param array<string,mixed>|null $admin */
    public static function mayEnter(?array $admin): bool
    {
        if ($admin === null || (int) ($admin['id'] ?? 0) <= 0) {
            return false;
        }
        if (array_key_exists('is_active', $admin) && (int) $admin['is_active'] !== 1) {
            return false;
        }
        return in_array((string) ($admin['role'] ?? ''), self::ENTRY_ROLES, true);
    }

    /** @param array<string,mixed>|null $admin */
    public static function isOffice(?array $admin): bool
    {
        return $admin !== null && in_array((string) ($admin['role'] ?? ''), self::OFFICE_ROLES, true);
    }

    /**
     * Office: any row. Counter: only a row they entered, on the day they entered it.
     *
     * @param array<string,mixed>|null $admin
     * @param array<string,mixed>      $row trip_expenses row
     */
    public static function mayChange(?array $admin, array $row): bool
    {
        if (!self::mayEnter($admin)) {
            return false;
        }
        if (self::isOffice($admin)) {
            return true;
        }
        return (int) ($row['created_by'] ?? 0) === (int) $admin['id']
            && substr((string) ($row['created_at'] ?? ''), 0, 10) === todayISO();
    }

    /**
     * Trips a counter may file against: 3 days back to tomorrow. Office: any date.
     *
     * @param array<string,mixed>|null $admin
     */
    public static function mayFileDate(?array $admin, string $travelDate): bool
    {
        if (self::isOffice($admin)) {
            return true;
        }
        return $travelDate >= addDaysISO(todayISO(), -3) && $travelDate <= addDaysISO(todayISO(), 1);
    }

    /* ------------------------------------------------------------------
     *  Trips
     * ---------------------------------------------------------------- */

    /** The coach that ran a schedule: its own bus, else the rotation's, else the route's. */
    public static function busOf(array $s): ?int
    {
        if ((int) ($s['bus_id'] ?? 0) > 0) {
            return (int) $s['bus_id'];
        }
        try {
            if (class_exists('Fleet')) {
                $plan = Fleet::planFor(['id' => (int) $s['route_id'], 'from_city' => (string) ($s['from_city'] ?? ''), 'to_city' => (string) ($s['to_city'] ?? '')],
                    (string) $s['travel_date']);
                if ((int) ($plan['bus'] ?? 0) > 0) {
                    return (int) $plan['bus'];
                }
            }
        } catch (Throwable $e) {
            /* rotation unreadable: fall through */
        }
        return (int) ($s['route_bus_id'] ?? 0) > 0 ? (int) $s['route_bus_id'] : null;
    }

    private const TRIP_SQL = "SELECT s.id, s.route_id, s.travel_date, s.slot, s.bus_id, s.status,
                                     COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                                     r.route_code, r.from_city, r.to_city, r.bus_id AS route_bus_id
                                FROM schedules s JOIN routes r ON r.id = s.route_id";

    /** @return array<string,mixed>|null schedule + route + resolved bus */
    public static function trip(int $scheduleId): ?array
    {
        if ($scheduleId <= 0) {
            return null;
        }
        $s = Database::fetch(self::TRIP_SQL . ' WHERE s.id = :i', ['i' => $scheduleId]);
        return $s === null ? null : self::decorate($s, self::savedBuses([$scheduleId])[$scheduleId] ?? null);
    }

    /** @return list<array<string,mixed>> the trips of a date (cancelled ones last, still listed) */
    public static function tripsOn(string $date): array
    {
        $rows = Database::fetchAll(self::TRIP_SQL . " WHERE s.travel_date = :d
                   ORDER BY s.status = 'cancelled', dep_time, r.sort_order, s.route_id, s.slot", ['d' => $date]);
        $saved = self::savedBuses(array_map(static fn(array $s): int => (int) $s['id'], $rows));
        $out = [];
        foreach ($rows as $s) {
            $out[] = self::decorate($s, $saved[(int) $s['id']] ?? null);
        }
        return $out;
    }

    /**
     * The bus frozen on a trip's cost rows (trip_expenses.bus_id, set by add()),
     * so a later bus_rotation change cannot move a past trip to another coach.
     *
     * @param list<int> $scheduleIds
     * @return array<int,int> schedule_id => bus_id
     */
    public static function savedBuses(array $scheduleIds): array
    {
        $scheduleIds = array_values(array_unique(array_filter(array_map('intval', $scheduleIds), static fn(int $i): bool => $i > 0)));
        if ($scheduleIds === [] || !self::hasSchema()) {
            return [];
        }
        $out = [];
        try {
            foreach (array_chunk($scheduleIds, 500) as $chunk) {
                $in = implode(',', $chunk);   /* ints only */
                foreach (Database::fetchAll("SELECT schedule_id, MAX(bus_id) AS bus FROM trip_expenses
                                              WHERE schedule_id IN ($in) AND bus_id IS NOT NULL GROUP BY schedule_id") as $r) {
                    if ((int) $r['bus'] > 0) {
                        $out[(int) $r['schedule_id']] = (int) $r['bus'];
                    }
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /** @var array<int,array<string,mixed>> */
    private static array $busCache = [];

    /** @param int|null $savedBus the bus frozen on the trip's cost rows: it wins over the live resolution */
    private static function decorate(array $s, ?int $savedBus = null): array
    {
        $bus = $savedBus !== null && $savedBus > 0 ? $savedBus : self::busOf($s);
        $s['bus'] = $bus;
        $s['bus_label'] = '';
        if ($bus !== null) {
            if (!isset(self::$busCache[$bus])) {
                self::$busCache[$bus] = Database::fetch('SELECT id, bus_number, bus_name FROM buses WHERE id = :i', ['i' => $bus]) ?? [];
            }
            $b = self::$busCache[$bus];
            $s['bus_label'] = (string) ($b['bus_number'] ?? '') !== '' ? (string) $b['bus_number'] : (string) ($b['bus_name'] ?? ('#' . $bus));
        }
        return $s;
    }

    /* ------------------------------------------------------------------
     *  Write
     * ---------------------------------------------------------------- */

    /**
     * Record one cost of one trip. Returns the new trip_expenses id.
     *
     * @param array<string,mixed> $admin the person entering (Auth::admin())
     */
    public static function add(array $admin, int $scheduleId, string $category, float $amount, string $currency = 'INR',
                               ?float $litres = null, string $note = '', ?string $receiptPath = null): int
    {
        if (!self::on()) {
            throw new RuntimeException('ट्रिप खर्च बन्द छ · Trip expenses are switched off.');
        }
        if (!self::mayEnter($admin)) {
            throw new RuntimeException('तपाईंलाई खर्च लेख्ने अनुमति छैन · You may not record trip expenses.');
        }
        $trip = self::trip($scheduleId);
        if ($trip === null) {
            throw new RuntimeException('ट्रिप भेटिएन · Trip not found.');
        }
        if (!self::mayFileDate($admin, (string) $trip['travel_date'])) {
            throw new RuntimeException('यो मितिको खर्च अफिसले मात्र लेख्छ · Only the office may file costs for that date.');
        }
        [$category, $amount, $currency, $litres, $note] = self::clean($category, $amount, $currency, $litres, $note);
        $rate = self::rate();
        $inr  = self::toInr($amount, $currency, $rate);
        self::guardInr($inr);

        $col = self::CATEGORIES[$category][3];
        $row = [
            'route_id'     => (int) $trip['route_id'],
            'expense_date' => (string) $trip['travel_date'],
            $col           => $inr,
            'total'        => $inr,
            'created_by'   => (int) ($admin['id'] ?? 0) ?: null,
        ];
        if (self::hasSchema()) {
            $row += [
                'schedule_id' => (int) $trip['id'],
                'bus_id'      => $trip['bus'],
                'category'    => $category,
                'amount'      => $amount,
                'currency'    => $currency,
                'note'        => $note !== '' ? $note : null,
            ];
            if (self::hasCol('fx_rate')) {
                $row['fx_rate'] = $currency === 'NPR' ? $rate : null;
            }
            if (self::hasCol('litres')) {
                $row['litres'] = $litres;
            }
            if (self::hasCol('receipt_path') && $receiptPath !== null && $receiptPath !== '') {
                $row['receipt_path'] = $receiptPath;
            }
        } else {
            /* old shape: the category and the typed money live in the note, like accounting.php's [cat] rows */
            $tag  = '[' . $category . ']';
            $orig = $currency === 'NPR' ? ' (NPR ' . number_format($amount, 2, '.', '') . ' @ ' . $rate . ')' : '';
            $row['note'] = mb_substr(trim($tag . ' ' . $note . $orig), 0, 255);
        }
        $id = Database::insert('trip_expenses', $row);
        Logger::audit('trip_expense.add', 'trip_expenses', (string) $id, null, $row,
            'Trip expense ' . $category . ' ' . $currency . ' ' . $amount . ' on trip #' . (int) $trip['id']);
        return $id;
    }

    /**
     * Change amount / currency / category / litres / note of a row.
     *
     * @param array<string,mixed> $admin
     */
    public static function update(array $admin, int $id, string $category, float $amount, string $currency = 'INR',
                                  ?float $litres = null, string $note = ''): void
    {
        $old = self::row($id);
        if ($old === null) {
            throw new RuntimeException('खर्च भेटिएन · Entry not found.');
        }
        if (!self::mayChange($admin, $old)) {
            throw new RuntimeException('आफ्नै र आजकै खर्च मात्र बदल्न मिल्छ · You may change only your own entry, the same day.');
        }
        if (!self::hasSchema()) {
            throw new RuntimeException('Run the op5 migration to edit entries · पहिले migration चलाउनुहोस्।');
        }
        [$category, $amount, $currency, $litres, $note] = self::clean($category, $amount, $currency, $litres, $note);
        $rate = $currency === 'NPR' ? ((float) ($old['fx_rate'] ?? 0) > 0 && (string) ($old['currency'] ?? '') === 'NPR' ? (float) $old['fx_rate'] : self::rate()) : null;
        $inr  = self::toInr($amount, $currency, $rate);
        self::guardInr($inr);
        $new = ['diesel' => 0.0, 'toll' => 0.0, 'driver_pay' => 0.0, 'misc' => 0.0];
        $new[self::CATEGORIES[$category][3]] = $inr;
        $new += ['total' => $inr, 'category' => $category, 'amount' => $amount, 'currency' => $currency,
                 'note' => $note !== '' ? $note : null];
        if (self::hasCol('fx_rate')) {
            $new['fx_rate'] = $rate;
        }
        if (self::hasCol('litres')) {
            $new['litres'] = $litres;
        }
        if (self::hasCol('updated_by')) {
            $new['updated_by'] = (int) ($admin['id'] ?? 0) ?: null;
        }
        if (self::hasCol('updated_at')) {
            $new['updated_at'] = date('Y-m-d H:i:s');
        }
        Database::update('trip_expenses', $new, 'id = :id', ['id' => $id]);
        Logger::audit('trip_expense.edit', 'trip_expenses', (string) $id, $old, $new, 'Trip expense changed');
    }

    /** @param array<string,mixed> $admin */
    public static function remove(array $admin, int $id): void
    {
        $old = self::row($id);
        if ($old === null) {
            return;
        }
        if (!self::mayChange($admin, $old)) {
            throw new RuntimeException('आफ्नै र आजकै खर्च मात्र हटाउन मिल्छ · You may delete only your own entry, the same day.');
        }
        Database::delete('trip_expenses', 'id = :id', ['id' => $id]);
        $rp = (string) ($old['receipt_path'] ?? '');
        if ($rp !== '' && ($f = self::receiptFile($rp)) !== null) {
            @unlink($f);
        }
        Logger::audit('trip_expense.delete', 'trip_expenses', (string) $id, $old, null, 'Trip expense deleted');
    }

    /** @return array<string,mixed>|null */
    public static function row(int $id): ?array
    {
        return $id > 0 ? Database::fetch('SELECT * FROM trip_expenses WHERE id = :i', ['i' => $id]) : null;
    }

    /** @return array{0:string,1:float,2:string,3:?float,4:string} */
    private static function clean(string $category, float $amount, string $currency, ?float $litres, string $note): array
    {
        if (!isset(self::CATEGORIES[$category])) {
            throw new RuntimeException('खर्चको किसिम छान्नुहोस् · Pick the kind of expense.');
        }
        $amount = round($amount, 2);
        if (!is_finite($amount) || $amount <= 0) {
            throw new RuntimeException('रकम ० भन्दा बढी लेख्नुहोस् · Enter an amount above 0.');
        }
        $currency = strtoupper(trim($currency)) === 'NPR' ? 'NPR' : 'INR';
        if ($litres !== null) {
            $litres = round($litres, 2);
            if ($category !== 'diesel' || $litres <= 0) {
                $litres = null;
            } elseif ($litres > 2000) {
                throw new RuntimeException('लिटर धेरै भयो · Litres look wrong (max 2000).');
            }
        }
        $note = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($note)) ?? ''), 0, 200);
        return [$category, $amount, $currency, $litres, $note];
    }

    private static function guardInr(float $inr): void
    {
        if ($inr > self::MAX_INR) {
            throw new RuntimeException('रकम धेरै ठूलो भयो · Amount too large (max ' . inr(self::MAX_INR) . ' per entry).');
        }
    }

    /* ------------------------------------------------------------------
     *  Read: one trip, one bus, a date range
     * ---------------------------------------------------------------- */

    /** @return list<array<string,mixed>> the costs of one trip, newest first */
    public static function forSchedule(int $scheduleId): array
    {
        try {
            if (self::hasSchema()) {
                return Database::fetchAll('SELECT e.*, a.full_name AS by_name, a.username AS by_user FROM trip_expenses e
                                             LEFT JOIN admins a ON a.id = e.created_by
                                            WHERE e.schedule_id = :s ORDER BY e.id DESC', ['s' => $scheduleId]);
            }
            $t = self::trip($scheduleId);
            if ($t === null) {
                return [];
            }
            /* old shape: the route + day stands for the trip */
            return Database::fetchAll('SELECT e.*, a.full_name AS by_name, a.username AS by_user FROM trip_expenses e
                                         LEFT JOIN admins a ON a.id = e.created_by
                                        WHERE e.route_id = :r AND e.expense_date = :d ORDER BY e.id DESC',
                ['r' => (int) $t['route_id'], 'd' => (string) $t['travel_date']]);
        } catch (Throwable $e) {
            return [];
        }
    }

    /** The category of a row: the column, else the old [cat] note tag, else the old money column. */
    public static function categoryOf(array $row): string
    {
        $c = (string) ($row['category'] ?? '');
        if ($c !== '' && isset(self::CATEGORIES[$c])) {
            return $c;
        }
        if (preg_match('/^\[(\w+)\]/', (string) ($row['note'] ?? ''), $m) && isset(self::CATEGORIES[$m[1]])) {
            return $m[1];
        }
        if ((float) ($row['diesel'] ?? 0) > 0) { return 'diesel'; }
        if ((float) ($row['toll'] ?? 0) > 0) { return 'toll'; }
        if ((float) ($row['driver_pay'] ?? 0) > 0) { return 'driver_allowance'; }
        return 'other';
    }

    /**
     * Ticket income of one trip — the same rule as admin/pnl.php (booking_legs of
     * confirmed / completed tickets on that departure).
     *
     * @return array{tickets:int, seats:int, income:float}
     */
    public static function income(int $scheduleId): array
    {
        try {
            $r = Database::fetch("SELECT COUNT(DISTINCT b.id) AS tickets, COALESCE(SUM(bl.seat_count), 0) AS seats, COALESCE(SUM(bl.leg_total), 0) AS income
                                    FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
                                   WHERE bl.schedule_id = :s AND b.status IN ('confirmed','completed')", ['s' => $scheduleId]);
        } catch (Throwable $e) {
            $r = null;
        }
        return ['tickets' => (int) ($r['tickets'] ?? 0), 'seats' => (int) ($r['seats'] ?? 0), 'income' => round((float) ($r['income'] ?? 0), 2)];
    }

    /**
     * One trip's P&L: ticket income − expenses = profit (INR).
     *
     * @return array{tickets:int, seats:int, income:float, expenses:float, profit:float, by_cat:array<string,float>, entries:int}
     */
    public static function tripPnl(int $scheduleId): array
    {
        $inc  = self::income($scheduleId);
        $rows = self::forSchedule($scheduleId);
        $by   = [];
        $exp  = 0.0;
        foreach ($rows as $r) {
            $c = self::categoryOf($r);
            $by[$c] = round(($by[$c] ?? 0.0) + (float) $r['total'], 2);
            $exp += (float) $r['total'];
        }
        $exp = round($exp, 2);
        return $inc + ['expenses' => $exp, 'profit' => round($inc['income'] - $exp, 2), 'by_cat' => $by, 'entries' => count($rows)];
    }

    /**
     * Every trip in a date range with its income, expenses and profit, and the
     * same rolled up per bus. Old rows with no trip on them are reported apart
     * (unlinked) so the totals still match admin/pnl.php.
     *
     * Cancelled trips are loaded too (flag 'cancelled'): a coach that broke down
     * still burnt its diesel, so its costs and any ticket income stay on its bus.
     * A cancelled trip with no income and no costs is left out.
     *
     * @return array{trips: list<array<string,mixed>>, buses: list<array<string,mixed>>, unlinked: float, total: array{trips:int,income:float,expenses:float,profit:float}}
     */
    public static function range(string $from, string $to): array
    {
        $trips = [];
        try {
            $rows  = Database::fetchAll(self::TRIP_SQL . " WHERE s.travel_date BETWEEN :f AND :t
                                          ORDER BY s.travel_date, s.status = 'cancelled', dep_time, s.route_id, s.slot", ['f' => $from, 't' => $to]);
            $saved = self::savedBuses(array_map(static fn(array $s): int => (int) $s['id'], $rows));
            foreach ($rows as $s) {
                $s = self::decorate($s, $saved[(int) $s['id']] ?? null);
                $trips[(int) $s['id']] = $s + ['cancelled' => (string) $s['status'] === 'cancelled',
                                               'tickets' => 0, 'seats' => 0, 'income' => 0.0, 'expenses' => 0.0];
            }
            foreach (Database::fetchAll("SELECT bl.schedule_id, COUNT(DISTINCT b.id) AS tickets, COALESCE(SUM(bl.seat_count), 0) AS seats, COALESCE(SUM(bl.leg_total), 0) AS income
                                           FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
                                          WHERE b.status IN ('confirmed','completed') AND bl.travel_date BETWEEN :f AND :t
                                          GROUP BY bl.schedule_id", ['f' => $from, 't' => $to]) as $r) {
                $sid = (int) $r['schedule_id'];
                if (isset($trips[$sid])) {
                    $trips[$sid]['tickets'] = (int) $r['tickets'];
                    $trips[$sid]['seats']   = (int) $r['seats'];
                    $trips[$sid]['income']  = round((float) $r['income'], 2);
                }
            }
        } catch (Throwable $e) {
            Logger::warning('TripExpense::range trips: ' . $e->getMessage());
        }
        $unlinked = 0.0;
        try {
            if (self::hasSchema()) {
                foreach (Database::fetchAll('SELECT schedule_id, COALESCE(SUM(total), 0) AS x FROM trip_expenses
                                              WHERE expense_date BETWEEN :f AND :t GROUP BY schedule_id', ['f' => $from, 't' => $to]) as $r) {
                    $sid = (int) ($r['schedule_id'] ?? 0);
                    if ($sid > 0 && isset($trips[$sid])) {
                        $trips[$sid]['expenses'] = round((float) $r['x'], 2);
                    } else {
                        $unlinked += (float) $r['x'];
                    }
                }
            } else {
                /* old shape: a route + day's costs go on that route's first trip of the day */
                $first = [];
                foreach ($trips as $sid => $t) {
                    $first[(int) $t['route_id'] . '|' . (string) $t['travel_date']] ??= $sid;
                }
                foreach (Database::fetchAll('SELECT route_id, expense_date, COALESCE(SUM(total), 0) AS x FROM trip_expenses
                                              WHERE expense_date BETWEEN :f AND :t GROUP BY route_id, expense_date', ['f' => $from, 't' => $to]) as $r) {
                    $k = (int) $r['route_id'] . '|' . (string) $r['expense_date'];
                    if (isset($first[$k])) {
                        $trips[$first[$k]]['expenses'] = round($trips[$first[$k]]['expenses'] + (float) $r['x'], 2);
                    } else {
                        $unlinked += (float) $r['x'];
                    }
                }
            }
        } catch (Throwable $e) {
            Logger::warning('TripExpense::range expenses: ' . $e->getMessage());
        }

        /* a cancelled trip that took no money and filed no cost is just noise */
        $trips = array_filter($trips, static fn(array $t): bool => !$t['cancelled'] || $t['tickets'] > 0 || $t['income'] != 0.0 || $t['expenses'] != 0.0);

        $buses = [];
        $total = ['trips' => 0, 'income' => 0.0, 'expenses' => 0.0, 'profit' => 0.0];
        foreach ($trips as &$t) {
            $t['profit'] = round($t['income'] - $t['expenses'], 2);
            $k = $t['bus'] === null ? 0 : (int) $t['bus'];
            $buses[$k] ??= ['bus' => $t['bus'], 'bus_label' => $t['bus'] === null ? 'बस तोकिएको छैन · No bus' : $t['bus_label'],
                            'trips' => 0, 'tickets' => 0, 'income' => 0.0, 'expenses' => 0.0, 'profit' => 0.0];
            $buses[$k]['trips']++;
            $buses[$k]['tickets'] += $t['tickets'];
            foreach (['income', 'expenses', 'profit'] as $f) {
                $buses[$k][$f] = round($buses[$k][$f] + $t[$f], 2);
                $total[$f]     = round($total[$f] + $t[$f], 2);
            }
            $total['trips']++;
        }
        unset($t);
        $unlinked = round($unlinked, 2);
        $total['expenses'] = round($total['expenses'] + $unlinked, 2);
        $total['profit']   = round($total['income'] - $total['expenses'], 2);
        $buses = array_values($buses);
        usort($buses, static fn(array $a, array $b): int => $b['income'] <=> $a['income']);
        return ['trips' => array_values($trips), 'buses' => $buses, 'unlinked' => $unlinked, 'total' => $total];
    }

    /* ------------------------------------------------------------------
     *  Receipt photo (Security::validateUpload: real MIME, image must decode)
     * ---------------------------------------------------------------- */

    /** @param array<string,mixed> $file $_FILES entry. Returns the path to store (relative to uploads/). */
    public static function saveReceipt(array $file): string
    {
        $check = Security::validateUpload($file);
        if (!$check['ok']) {
            throw new RuntimeException($check['error'] ?? 'Upload failed.');
        }
        $ext = strtolower((string) ($check['ext'] ?? ''));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            throw new RuntimeException('रसिद JPG, PNG, WEBP वा PDF हुनुपर्छ · Receipt must be JPG, PNG, WEBP or PDF.');
        }
        $dir = UPLOAD_PATH . '/' . self::RECEIPT_DIR;
        ensureDir($dir);
        $name = Security::safeFilename($ext);
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
            throw new RuntimeException('रसिद सेभ भएन · Could not save the receipt.');
        }
        return self::RECEIPT_DIR . '/' . $name;
    }

    /** Absolute path of a stored receipt, or null when the value is not one of ours. */
    public static function receiptFile(string $rel): ?string
    {
        if (preg_match('#^' . self::RECEIPT_DIR . '/[0-9]{8}_[0-9]{6}_[a-f0-9]{16}\.(jpg|jpeg|png|webp|pdf)$#', $rel) !== 1) {
            return null;
        }
        $f = UPLOAD_PATH . '/' . $rel;
        return is_file($f) ? $f : null;
    }
}
