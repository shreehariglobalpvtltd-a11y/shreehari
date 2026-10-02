<?php
/**
 * =====================================================================
 *  Payroll — the staff payroll register (2 Oct 2026).
 *
 *  For people who work for the company WITHOUT a panel login: bus
 *  helpers / conductors, office boys, cleaners, supervisors and drivers.
 *  Agents and counter staff who sign in keep their salary and advances in
 *  the agent wallet (admin/agent.php · AgentWallet) — nothing here touches
 *  agent_ledger, agent_loans or trip_expenses, so Accounting never counts
 *  a salary twice.
 *
 *  Tables (database/upgrade-2026-10-02-staff-payroll.sql):
 *    staff_members          one row per person
 *    staff_advances         money given ahead of salary
 *    staff_salary_payments  one settlement per person per month
 *                           (UNIQUE staff_id + period; corrected by UPDATE)
 *
 *  The one money rule: staff_advances.recovered is DERIVED. syncRecovery()
 *  spreads the SUM of advance_deduction over all of a person's salary
 *  payments across their advances oldest-first, capped at each amount, and
 *  closes an advance once it is fully covered. pay(), correctPayment(),
 *  giveAdvance() and cancelAdvance() all call it inside their own single
 *  transaction, with the person's staff_members row locked first — so two
 *  desks paying the same person at once queue up instead of interleaving.
 *
 *  Every write is audited (Logger::audit, entity_type 'staff_member').
 *  Validation failures throw RuntimeException with a message the owner
 *  can read on a phone.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class Payroll
{
    public const MIGRATION = 'database/upgrade-2026-10-02-staff-payroll.sql';

    /** role => label shown on the page */
    public const ROLES = [
        'helper'     => 'Helper · खलासी',
        'conductor'  => 'Conductor · कन्डक्टर',
        'driver'     => 'Driver · ड्राइभर',
        'office'     => 'Office',
        'cleaner'    => 'Cleaner',
        'supervisor' => 'Supervisor',
        'other'      => 'Other',
    ];

    public const METHODS    = ['cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank'];
    public const CURRENCIES = ['INR', 'NPR'];

    /** Places the company works from — suggestions only, the field is free text. */
    public const LOCATIONS = ['Surat', 'Vadodara', 'Ahmedabad', 'Mehsana', 'Rajkot', 'Rupaidiha', 'Nepalgunj'];

    /** DECIMAL(10,2) holds 99,999,999.99 — one person's monthly figure stays far below. */
    private const MAX_AMOUNT = 9999999.0;

    /* =================================================================
     *  Readiness / periods / money
     * ================================================================= */

    /** Have the three payroll tables been created on this database? */
    public static function ready(): bool
    {
        try {
            return (int) Database::scalar(
                "SELECT COUNT(*) FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME IN ('staff_members', 'staff_advances', 'staff_salary_payments')",
                [],
                0
            ) === 3;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function isPeriod(string $p): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p) === 1;
    }

    public static function currentPeriod(): string
    {
        return substr(todayISO(), 0, 7);
    }

    /** '2026-10' shifted by ±n months. */
    public static function shiftPeriod(string $p, int $months): string
    {
        $d = new DateTimeImmutable($p . '-01');
        return $d->modify(($months >= 0 ? '+' : '') . $months . ' month')->format('Y-m');
    }

    /** '2026-10' -> 'Oct 2026' */
    public static function periodLabel(string $p): string
    {
        return date('M Y', (int) strtotime($p . '-01'));
    }

    /** Last calendar day of the period, 'Y-m-d'. */
    public static function periodEnd(string $p): string
    {
        return date('Y-m-t', (int) strtotime($p . '-01'));
    }

    /** ₹1,23,456 for INR, NPR 1,23,456 for NPR; paise only when there are any. */
    public static function money(float $amount, string $currency = 'INR'): string
    {
        $dec = abs($amount - round($amount)) > 0.004 ? 2 : 0;
        $num = indianNumber($amount, $dec);
        return strtoupper($currency) === 'NPR' ? 'NPR ' . $num : '₹' . $num;
    }

    /** Amount in INR (NPR converted with the company peg) for the month totals. */
    public static function toInr(float $amount, string $currency): float
    {
        if (strtoupper($currency) !== 'NPR') {
            return $amount;
        }
        $peg = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);
        return $peg > 0 ? $amount / $peg : $amount;
    }

    /* =================================================================
     *  Staff
     * ================================================================= */

    /** @return array<string,mixed>|null */
    public static function staff(int $id): ?array
    {
        return $id > 0 ? Database::fetch('SELECT * FROM staff_members WHERE id = :i', ['i' => $id]) : null;
    }

    /**
     * Add a person to the payroll. Returns the new staff_members.id.
     *
     * @param array<string,mixed> $in full_name, phone, phone_country, role, base_location,
     *                                monthly_salary, currency, driver_id, joined_on, notes
     */
    public static function addStaff(array $in, int $by): int
    {
        $row = self::cleanStaff($in, 0);
        $row['is_active']  = 1;
        $row['created_by'] = $by > 0 ? $by : null;
        $id = Database::insert('staff_members', $row);
        Logger::audit('payroll.staff_add', 'staff_member', (string) $id, null, $row, 'Added to payroll: ' . $row['full_name']);
        return $id;
    }

    /** Edit a person's details. Past salary payments keep the figures they were paid at. */
    public static function updateStaff(int $id, array $in, int $by): void
    {
        $before = self::staff($id);
        if ($before === null) {
            throw new RuntimeException('That person is not on the payroll any more.');
        }
        $row = self::cleanStaff($in, $id);
        Database::update('staff_members', $row, 'id = :i', ['i' => $id]);

        [$old, $new] = self::changed($before, $row);
        if ($new === []) {
            return;   // saved the same details — no audit noise
        }
        Logger::audit('payroll.staff_edit', 'staff_member', (string) $id, $old, $new, 'Payroll details edited by admin #' . $by);
    }

    /** Deactivate (left the job) or re-activate. History is always kept. */
    public static function setActive(int $id, bool $active, int $by): void
    {
        $s = self::staff($id);
        if ($s === null) {
            throw new RuntimeException('That person is not on the payroll any more.');
        }
        if ((int) $s['is_active'] === ($active ? 1 : 0)) {
            return;
        }
        Database::update('staff_members', ['is_active' => $active ? 1 : 0], 'id = :i', ['i' => $id]);
        Logger::audit(
            $active ? 'payroll.staff_activate' : 'payroll.staff_deactivate',
            'staff_member', (string) $id,
            ['is_active' => (int) $s['is_active']], ['is_active' => $active ? 1 : 0],
            ($active ? 'Re-activated: ' : 'Deactivated: ') . $s['full_name'] . ' by admin #' . $by
        );
    }

    /** Active drivers for the optional "link to a driver" picker. @return list<array<string,mixed>> */
    public static function driverChoices(): array
    {
        try {
            return Database::fetchAll(
                "SELECT id, full_name, phone, role FROM drivers WHERE is_active = 1 ORDER BY full_name, id LIMIT 300"
            );
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Validate a staff form into a column => value row.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private static function cleanStaff(array $in, int $selfId): array
    {
        $driverId = (int) self::str($in['driver_id'] ?? 0);
        $driver   = null;
        if ($driverId > 0) {
            $driver = Database::fetch('SELECT id, full_name, phone FROM drivers WHERE id = :d', ['d' => $driverId]);
            if ($driver === null) {
                throw new RuntimeException('That driver is no longer in Drivers & Crew — pick again.');
            }
            $dup = Database::fetch(
                'SELECT id, full_name FROM staff_members WHERE driver_id = :d AND is_active = 1 AND id <> :me LIMIT 1',
                ['d' => $driverId, 'me' => $selfId]
            );
            if ($dup !== null) {
                throw new RuntimeException('That driver is already on the payroll as ' . $dup['full_name'] . '.');
            }
        }

        $name = Security::clean(self::str($in['full_name'] ?? ''), 120);
        if ($name === '' && $driver !== null) {
            $name = Security::clean((string) $driver['full_name'], 120);
        }
        if ($name === '') {
            throw new RuntimeException('Enter the person\'s name.');
        }

        $rawPhone = self::str($in['phone'] ?? '');
        if (trim($rawPhone) === '' && $driver !== null) {
            $rawPhone = (string) $driver['phone'];
        }
        $phone = normalisePhone($rawPhone);
        if ($phone !== '' && preg_match('/^\d{10}$/', $phone) !== 1) {
            throw new RuntimeException('The mobile number must be 10 digits (or leave it empty).');
        }
        // A +977 / +91 the owner actually typed wins over the picker.
        $country = resolvePhoneCountry('', $rawPhone);
        if ($country === '') {
            $country = strtoupper(self::str($in['phone_country'] ?? '')) === 'NP' ? 'NP' : 'IN';
        }

        $role = self::str($in['role'] ?? '');
        if ($role === '' && $driver !== null) {
            $role = 'driver';
        }
        if (!isset(self::ROLES[$role])) {
            throw new RuntimeException('Pick the job (helper, conductor, driver …).');
        }

        $currency = strtoupper(self::str($in['currency'] ?? 'INR'));
        if (!in_array($currency, self::CURRENCIES, true)) {
            $currency = 'INR';
        }

        $joined = trim(self::str($in['joined_on'] ?? ''));
        if ($joined !== '' && !Security::isValidDate($joined)) {
            throw new RuntimeException('The joining date is not a real date.');
        }

        return [
            'full_name'      => $name,
            'phone'          => $phone !== '' ? $phone : null,
            'phone_country'  => $country,
            'role'           => $role,
            'base_location'  => Security::clean(self::str($in['base_location'] ?? ''), 80) ?: null,
            'monthly_salary' => self::amount($in['monthly_salary'] ?? 0, 'Monthly salary'),
            'currency'       => $currency,
            'driver_id'      => $driver !== null ? (int) $driver['id'] : null,
            'joined_on'      => $joined !== '' ? $joined : null,
            'notes'          => Security::clean(self::str($in['notes'] ?? ''), 255) ?: null,
        ];
    }

    /* =================================================================
     *  Advances
     * ================================================================= */

    /**
     * Give an advance. $monthly = how much to take back from each salary
     * (0 = take the whole balance back at the next salary).
     *
     * @param array<string,mixed> $in amount, given_on, reason, monthly_deduction
     */
    public static function giveAdvance(int $staffId, array $in, int $by): int
    {
        $amount  = self::amount($in['amount'] ?? 0, 'Advance amount', true);
        $monthly = self::amount($in['monthly_deduction'] ?? 0, 'Monthly deduction');
        $given   = trim(self::str($in['given_on'] ?? ''));
        $given   = $given === '' ? todayISO() : $given;
        $reason  = Security::clean(self::str($in['reason'] ?? ''), 255);

        if (!Security::isValidDate($given)) {
            throw new RuntimeException('The advance date is not a real date.');
        }
        if ($given > todayISO()) {
            throw new RuntimeException('An advance cannot be dated in the future.');
        }
        if ($monthly > $amount) {
            throw new RuntimeException('The monthly deduction cannot be more than the advance itself.');
        }

        return Database::transaction(static function () use ($staffId, $amount, $monthly, $given, $reason, $by): int {
            $staff = self::lockStaff($staffId);
            if ((int) $staff['is_active'] !== 1) {
                throw new RuntimeException($staff['full_name'] . ' is inactive — activate them first.');
            }
            $row = [
                'staff_id'          => $staffId,
                'amount'            => $amount,
                'given_on'          => $given,
                'reason'            => $reason !== '' ? $reason : null,
                'monthly_deduction' => $monthly,
                'recovered'         => 0,
                'status'            => 'open',
                'created_by'        => $by > 0 ? $by : null,
            ];
            $id = Database::insert('staff_advances', $row);
            self::syncRecovery($staffId);
            Logger::audit('payroll.advance', 'staff_member', (string) $staffId, null, $row + ['advance_id' => $id],
                'Advance ' . self::money($amount, (string) $staff['currency']) . ' given to ' . $staff['full_name']);
            return $id;
        });
    }

    /** Cancel an advance entered by mistake — only while nothing of it was recovered. */
    public static function cancelAdvance(int $advanceId, int $by): void
    {
        $peek = Database::fetch('SELECT staff_id FROM staff_advances WHERE id = :a', ['a' => $advanceId]);
        if ($peek === null) {
            throw new RuntimeException('That advance does not exist.');
        }
        $staffId = (int) $peek['staff_id'];

        Database::transaction(static function () use ($advanceId, $staffId, $by): void {
            $staff = self::lockStaff($staffId);
            $adv   = Database::fetchForUpdate('SELECT * FROM staff_advances WHERE id = :a', ['a' => $advanceId])[0] ?? null;
            if ($adv === null || (string) $adv['status'] !== 'open') {
                throw new RuntimeException('Only an open advance can be cancelled.');
            }
            if ((float) $adv['recovered'] > 0.004) {
                throw new RuntimeException('Part of this advance was already taken back from a salary — it cannot be cancelled. Correct that salary payment instead.');
            }
            Database::update('staff_advances', ['status' => 'cancelled'], 'id = :a', ['a' => $advanceId]);
            self::syncRecovery($staffId);
            Logger::audit('payroll.advance_cancel', 'staff_member', (string) $staffId,
                ['advance_id' => $advanceId, 'status' => 'open', 'amount' => (float) $adv['amount']],
                ['advance_id' => $advanceId, 'status' => 'cancelled'],
                'Advance #' . $advanceId . ' of ' . $staff['full_name'] . ' cancelled by admin #' . $by);
        });
    }

    /** What the person still owes across open advances. */
    public static function openBalance(int $staffId): float
    {
        return round((float) Database::scalar(
            "SELECT COALESCE(SUM(amount - recovered), 0) FROM staff_advances WHERE staff_id = :s AND status = 'open'",
            ['s' => $staffId],
            0
        ), 2);
    }

    /** @return list<array<string,mixed>> open advances, oldest first */
    public static function openAdvances(int $staffId): array
    {
        return Database::fetchAll(
            "SELECT * FROM staff_advances WHERE staff_id = :s AND status = 'open' ORDER BY given_on, id",
            ['s' => $staffId]
        );
    }

    /**
     * The deduction the Pay form starts with: each open advance's monthly
     * figure (or its whole balance when the figure is 0), never more than
     * the balance, never more than $cap (the gross, so net cannot go below 0).
     *
     * @param list<array<string,mixed>> $openAdvances
     */
    public static function suggestedDeduction(array $openAdvances, float $cap): float
    {
        $sum = 0.0;
        foreach ($openAdvances as $a) {
            $out = round((float) $a['amount'] - (float) $a['recovered'], 2);
            if ($out <= 0) {
                continue;
            }
            $m    = (float) $a['monthly_deduction'];
            $sum += $m > 0 ? min($m, $out) : $out;
        }
        return round(max(0.0, min($sum, $cap)), 2);
    }

    /**
     * Re-derive recovered / status on every non-cancelled advance of one
     * person from their salary payments (oldest advance first). Runs in the
     * caller's transaction, or opens one.
     */
    public static function syncRecovery(int $staffId): void
    {
        if (!Database::inTransaction()) {
            Database::transaction(static function () use ($staffId): void { self::syncRecovery($staffId); });
            return;
        }
        $advances = Database::fetchForUpdate(
            "SELECT id, amount, recovered, status, cleared_at FROM staff_advances
              WHERE staff_id = :s AND status <> 'cancelled' ORDER BY given_on, id",
            ['s' => $staffId]
        );
        $left = round((float) Database::scalar(
            'SELECT COALESCE(SUM(advance_deduction), 0) FROM staff_salary_payments WHERE staff_id = :s',
            ['s' => $staffId],
            0
        ), 2);

        foreach ($advances as $a) {
            $amount = (float) $a['amount'];
            $take   = round(min($left, $amount), 2);
            $left   = round($left - $take, 2);
            $status = $take >= $amount - 0.004 ? 'cleared' : 'open';
            if (abs($take - (float) $a['recovered']) < 0.005 && $status === (string) $a['status']) {
                continue;
            }
            Database::update('staff_advances', [
                'recovered'  => $take,
                'status'     => $status,
                'cleared_at' => $status === 'cleared'
                    ? ((string) $a['status'] === 'cleared' && !empty($a['cleared_at']) ? $a['cleared_at'] : date('Y-m-d H:i:s'))
                    : null,
            ], 'id = :i', ['i' => (int) $a['id']]);
        }
    }

    /* =================================================================
     *  Salary payments
     * ================================================================= */

    /** @return array<string,mixed>|null */
    public static function payment(int $id): ?array
    {
        return $id > 0 ? Database::fetch('SELECT * FROM staff_salary_payments WHERE id = :i', ['i' => $id]) : null;
    }

    /** @return array<string,mixed>|null */
    public static function paymentFor(int $staffId, string $period): ?array
    {
        return Database::fetch(
            'SELECT * FROM staff_salary_payments WHERE staff_id = :s AND period = :p',
            ['s' => $staffId, 'p' => $period]
        );
    }

    /**
     * Pay one person's salary for a month. ONE transaction: the payment row
     * plus the advance recovery (oldest advance first, closed when covered).
     * A second payment for the same person + month is refused — correct the
     * first one with correctPayment() instead.
     *
     * @param array<string,mixed> $in gross, advance_deduction, bonus, other_deduction,
     *                                paid_on, method, reference, notes, net (optional —
     *                                the figure the form showed; must agree)
     */
    public static function pay(int $staffId, string $period, array $in, int $by): int
    {
        if (!self::isPeriod($period)) {
            throw new RuntimeException('Pick the month the salary is for.');
        }
        $f = self::cleanPay($in);

        return Database::transaction(static function () use ($staffId, $period, $f, $by): int {
            $staff = self::lockStaff($staffId);
            if ((int) $staff['is_active'] !== 1) {
                throw new RuntimeException($staff['full_name'] . ' is inactive — activate them first.');
            }
            if (self::paymentFor($staffId, $period) !== null) {
                throw new RuntimeException($staff['full_name'] . ' is already paid for ' . self::periodLabel($period)
                    . ' — use "Correct payment" to change it.');
            }
            $owed = self::lockedOpenBalance($staffId);
            self::checkFigures($f, $owed, (string) $staff['currency']);

            $row = [
                'staff_id'          => $staffId,
                'period'            => $period,
                'gross'             => $f['gross'],
                'advance_deduction' => $f['advance_deduction'],
                'bonus'             => $f['bonus'],
                'other_deduction'   => $f['other_deduction'],
                'net_paid'          => $f['net'],
                'currency'          => (string) $staff['currency'],
                'paid_on'           => $f['paid_on'],
                'method'            => $f['method'],
                'reference'         => $f['reference'],
                'notes'             => $f['notes'],
                'created_by'        => $by > 0 ? $by : null,
            ];
            try {
                $id = Database::insert('staff_salary_payments', $row);
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {   // UNIQUE(staff_id, period) — another desk got there first
                    throw new RuntimeException($staff['full_name'] . ' is already paid for ' . self::periodLabel($period) . '.');
                }
                throw $e;
            }
            self::syncRecovery($staffId);
            Logger::audit('payroll.pay', 'staff_member', (string) $staffId, null, $row + ['payment_id' => $id],
                'Salary ' . self::periodLabel($period) . ' paid to ' . $staff['full_name'] . ': net '
                . self::money($f['net'], (string) $staff['currency']));
            return $id;
        });
    }

    /**
     * Correct a salary payment in place (UPDATE + audit old → new, never
     * delete + insert). The advances are re-balanced in the same transaction.
     *
     * @param array<string,mixed> $in same keys as pay()
     */
    public static function correctPayment(int $paymentId, array $in, int $by): void
    {
        $peek = self::payment($paymentId);
        if ($peek === null) {
            throw new RuntimeException('That salary payment does not exist.');
        }
        $staffId = (int) $peek['staff_id'];
        $f       = self::cleanPay($in);

        Database::transaction(static function () use ($paymentId, $staffId, $f, $by): void {
            $staff = self::lockStaff($staffId);
            $old   = Database::fetchForUpdate('SELECT * FROM staff_salary_payments WHERE id = :i', ['i' => $paymentId])[0] ?? null;
            if ($old === null) {
                throw new RuntimeException('That salary payment does not exist.');
            }
            // This payment's own deduction is available again while it is re-decided.
            $owed = round(self::lockedOpenBalance($staffId) + (float) $old['advance_deduction'], 2);
            self::checkFigures($f, $owed, (string) $old['currency']);

            $new = [
                'gross'             => $f['gross'],
                'advance_deduction' => $f['advance_deduction'],
                'bonus'             => $f['bonus'],
                'other_deduction'   => $f['other_deduction'],
                'net_paid'          => $f['net'],
                'paid_on'           => $f['paid_on'],
                'method'            => $f['method'],
                'reference'         => $f['reference'],
                'notes'             => $f['notes'],
            ];
            [$before, $after] = self::changed($old, $new);
            if ($after === []) {
                return;   // nothing changed — no write, no audit noise
            }
            Database::update('staff_salary_payments', $after + [
                'updated_by' => $by > 0 ? $by : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :i', ['i' => $paymentId]);
            self::syncRecovery($staffId);
            Logger::audit('payroll.pay_correct', 'staff_member', (string) $staffId,
                $before + ['payment_id' => $paymentId], $after + ['payment_id' => $paymentId],
                'Salary ' . self::periodLabel((string) $old['period']) . ' of ' . $staff['full_name'] . ' corrected by admin #' . $by);
        });
    }

    /**
     * @param array<string,mixed> $in
     * @return array{gross:float,advance_deduction:float,bonus:float,other_deduction:float,net:float,posted_net:?float,paid_on:string,method:string,reference:?string,notes:?string}
     */
    private static function cleanPay(array $in): array
    {
        $gross = self::amount($in['gross'] ?? 0, 'Salary');
        $adv   = self::amount($in['advance_deduction'] ?? 0, 'Advance deduction');
        $bonus = self::amount($in['bonus'] ?? 0, 'Bonus');
        $other = self::amount($in['other_deduction'] ?? 0, 'Other deduction');

        $paidOn = trim(self::str($in['paid_on'] ?? ''));
        $paidOn = $paidOn === '' ? todayISO() : $paidOn;
        if (!Security::isValidDate($paidOn)) {
            throw new RuntimeException('The payment date is not a real date.');
        }
        if ($paidOn > todayISO()) {
            throw new RuntimeException('A salary cannot be marked paid on a future date.');
        }
        $method = self::str($in['method'] ?? 'cash');
        if (!isset(self::METHODS[$method])) {
            throw new RuntimeException('Pick how it was paid: cash, UPI or bank.');
        }
        if ($gross + $bonus <= 0) {
            throw new RuntimeException('Enter the salary amount.');
        }

        $postedRaw = trim(self::str($in['net'] ?? ''));
        $ref   = Security::clean(self::str($in['reference'] ?? ''), 80);
        $notes = Security::clean(self::str($in['notes'] ?? ''), 255);

        return [
            'gross'             => $gross,
            'advance_deduction' => $adv,
            'bonus'             => $bonus,
            'other_deduction'   => $other,
            'net'               => round($gross - $adv + $bonus - $other, 2),
            'posted_net'        => $postedRaw !== '' && is_numeric(str_replace(',', '', $postedRaw)) ? round((float) str_replace(',', '', $postedRaw), 2) : null,
            'paid_on'           => $paidOn,
            'method'            => $method,
            'reference'         => $ref !== '' ? $ref : null,
            'notes'             => $notes !== '' ? $notes : null,
        ];
    }

    /** Server-side re-check of the figures the form computed live. @param array<string,mixed> $f */
    private static function checkFigures(array $f, float $owed, string $currency): void
    {
        if ($f['advance_deduction'] > $owed + 0.004) {
            throw new RuntimeException($owed > 0
                ? 'Advance deduction ' . self::money($f['advance_deduction'], $currency) . ' is more than the '
                  . self::money($owed, $currency) . ' still owed.'
                : 'There is no open advance to deduct — set the advance deduction to 0.');
        }
        if ($f['net'] < 0) {
            throw new RuntimeException('The deductions are more than the salary — net paid would be below zero.');
        }
        if ($f['posted_net'] !== null && abs($f['posted_net'] - $f['net']) > 0.01) {
            throw new RuntimeException('The net amount on the form (' . self::money($f['posted_net'], $currency)
                . ') does not match the figures (' . self::money($f['net'], $currency) . '). Check and save again.');
        }
    }

    /** Lock the person's row (serialises every payroll write for them). @return array<string,mixed> */
    private static function lockStaff(int $staffId): array
    {
        $s = Database::fetchForUpdate('SELECT * FROM staff_members WHERE id = :s', ['s' => $staffId])[0] ?? null;
        if ($s === null) {
            throw new RuntimeException('That person is not on the payroll any more.');
        }
        return $s;
    }

    private static function lockedOpenBalance(int $staffId): float
    {
        $sum = 0.0;
        foreach (Database::fetchForUpdate(
            "SELECT amount, recovered FROM staff_advances WHERE staff_id = :s AND status = 'open'",
            ['s' => $staffId]
        ) as $a) {
            $sum += (float) $a['amount'] - (float) $a['recovered'];
        }
        return round($sum, 2);
    }

    /* =================================================================
     *  The month sheet (page + CSV share it)
     * ================================================================= */

    /**
     * Everyone on the payroll for a month: active staff, plus anyone
     * (even inactive now) who was paid for that month.
     *
     * @return array{rows: list<array<string,mixed>>, totals: array<string,float|int|bool>}
     */
    public static function monthSheet(string $period): array
    {
        $end   = self::periodEnd($period);
        $staff = Database::fetchAll(
            'SELECT s.*, d.full_name AS driver_name
               FROM staff_members s
               LEFT JOIN drivers d ON d.id = s.driver_id
              WHERE s.is_active = 1
                 OR s.id IN (SELECT p.staff_id FROM staff_salary_payments p WHERE p.period = :p)
              ORDER BY s.is_active DESC, s.full_name, s.id',
            ['p' => $period]
        );
        $pays = keyBy(Database::fetchAll('SELECT * FROM staff_salary_payments WHERE period = :p', ['p' => $period]), 'staff_id');

        $open = [];
        foreach (Database::fetchAll("SELECT * FROM staff_advances WHERE status = 'open' ORDER BY given_on, id") as $a) {
            $open[(int) $a['staff_id']][] = $a;
        }

        $t = [
            'due' => 0.0, 'paid' => 0.0, 'paidCount' => 0, 'recovered' => 0.0,
            'pending' => 0.0, 'pendingCount' => 0, 'people' => 0, 'mixed' => false,
        ];
        $rows = [];
        foreach ($staff as $s) {
            $sid     = (int) $s['id'];
            $cur     = (string) $s['currency'];
            $salary  = (float) $s['monthly_salary'];
            $advs    = $open[$sid] ?? [];
            $balance = 0.0;
            foreach ($advs as $a) {
                $balance += (float) $a['amount'] - (float) $a['recovered'];
            }
            $pay       = $pays[$sid] ?? null;
            $suggested = self::suggestedDeduction($advs, $salary);
            $joinedLater = !empty($s['joined_on']) && (string) $s['joined_on'] > $end;
            $due       = $pay !== null || ((int) $s['is_active'] === 1 && !$joinedLater);

            $s['open_advances']  = $advs;
            $s['balance']        = round($balance, 2);
            $s['payment']        = $pay;
            $s['suggested']      = $suggested;
            $s['expected_net']   = round(max(0.0, $salary - $suggested), 2);
            $s['due']            = $due;
            $s['joined_later']   = $joinedLater;
            $rows[] = $s;

            if ($cur !== 'INR') {
                $t['mixed'] = true;
            }
            if ($due) {
                $t['people']++;
                $t['due'] += self::toInr($pay !== null ? (float) $pay['gross'] : $salary, $cur);
            }
            if ($pay !== null) {
                $t['paidCount']++;
                $t['paid']      += self::toInr((float) $pay['net_paid'], (string) $pay['currency']);
                $t['recovered'] += self::toInr((float) $pay['advance_deduction'], (string) $pay['currency']);
            } elseif ($due) {
                $t['pendingCount']++;
                $t['pending'] += self::toInr($salary, $cur);
            }
        }

        // Open advances: everyone, active or not — money out is money out.
        $t['advances'] = 0.0;
        foreach (Database::fetchAll(
            "SELECT s.currency, COALESCE(SUM(a.amount - a.recovered), 0) AS bal
               FROM staff_advances a JOIN staff_members s ON s.id = a.staff_id
              WHERE a.status = 'open' GROUP BY s.currency"
        ) as $r) {
            $t['advances'] += self::toInr((float) $r['bal'], (string) $r['currency']);
        }
        foreach (['due', 'paid', 'recovered', 'pending', 'advances'] as $k) {
            $t[$k] = round((float) $t[$k], 2);
        }

        return ['rows' => $rows, 'totals' => $t];
    }

    /** @return list<array<string,mixed>> inactive people not already on the month sheet */
    public static function inactiveStaff(): array
    {
        return Database::fetchAll(
            "SELECT s.*, (SELECT COALESCE(SUM(a.amount - a.recovered), 0) FROM staff_advances a
                            WHERE a.staff_id = s.id AND a.status = 'open') AS balance
               FROM staff_members s WHERE s.is_active = 0 ORDER BY s.full_name, s.id"
        );
    }

    /**
     * Payment + advance history for a set of people, grouped by staff id.
     *
     * @param list<int> $staffIds
     * @return array<int, array{payments: list<array<string,mixed>>, advances: list<array<string,mixed>>}>
     */
    public static function history(array $staffIds, int $perPerson = 24): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $staffIds), static fn(int $i): bool => $i > 0)));
        $out = [];
        foreach ($ids as $i) {
            $out[$i] = ['payments' => [], 'advances' => []];
        }
        if ($ids === []) {
            return $out;
        }
        $in = implode(',', $ids);   // ints only — cast above
        foreach (Database::fetchAll("SELECT * FROM staff_salary_payments WHERE staff_id IN ($in) ORDER BY period DESC, id DESC") as $p) {
            $sid = (int) $p['staff_id'];
            if (count($out[$sid]['payments']) < $perPerson) {
                $out[$sid]['payments'][] = $p;
            }
        }
        foreach (Database::fetchAll("SELECT * FROM staff_advances WHERE staff_id IN ($in) ORDER BY given_on DESC, id DESC") as $a) {
            $sid = (int) $a['staff_id'];
            if (count($out[$sid]['advances']) < $perPerson) {
                $out[$sid]['advances'][] = $a;
            }
        }
        return $out;
    }

    /* =================================================================
     *  WhatsApp salary slip
     * ================================================================= */

    /** @param array<string,mixed> $staff @param array<string,mixed> $pay */
    public static function slipText(array $staff, array $pay, ?float $stillOwed = null): string
    {
        $cur = (string) ($pay['currency'] ?? $staff['currency'] ?? 'INR');
        $m   = static fn(float $v): string => self::money($v, $cur);
        $lines = [
            'Salary slip – ' . self::periodLabel((string) $pay['period']),
            'Name: ' . $staff['full_name'],
            'Salary: ' . $m((float) $pay['gross']),
            'Advance deduction: −' . $m((float) $pay['advance_deduction']),
            'Bonus: +' . $m((float) $pay['bonus']),
        ];
        if ((float) $pay['other_deduction'] > 0) {
            $lines[] = 'Other deduction: −' . $m((float) $pay['other_deduction']);
        }
        $lines[] = 'Net paid: ' . $m((float) $pay['net_paid']);
        $lines[] = 'Date: ' . formatDate((string) $pay['paid_on'], 'j M Y') . ' · ' . (self::METHODS[(string) $pay['method']] ?? (string) $pay['method'])
                 . (!empty($pay['reference']) ? ' (' . $pay['reference'] . ')' : '');
        if ($stillOwed !== null && $stillOwed > 0.004) {
            $lines[] = 'Advance still to repay: ' . $m($stillOwed);
        }
        $lines[] = Settings::getString('company_name', defined('APP_NAME') ? (string) APP_NAME : 'S Hari Global');
        return implode("\n", $lines);
    }

    /**
     * The international digits (no "+") for a staff mobile — the same
     * resolution every WhatsApp send uses (Notify::whatsappNumberFor), with
     * the person's own country stamped so a Nepali number gets +977.
     *
     * @param array<string,mixed> $staff
     */
    public static function waNumber(array $staff): string
    {
        $phone = (string) ($staff['phone'] ?? '');
        if ($phone === '') {
            return '';
        }
        $cc = countryDialCode((string) ($staff['phone_country'] ?? 'IN')) ?: '91';
        if (class_exists('Notify')) {
            return Notify::whatsappNumberFor(['contact_phone' => $phone, 'contact_country_code' => $cc]);
        }
        $d = preg_replace('/\D/', '', $phone) ?? '';
        return strlen($d) === 10 ? $cc . $d : $d;
    }

    /** wa.me link with the slip pre-typed (no number = WhatsApp asks who to send it to). @param array<string,mixed> $staff */
    public static function waLink(array $staff, string $text): string
    {
        return 'https://wa.me/' . self::waNumber($staff) . '?text=' . rawurlencode($text);
    }

    /* =================================================================
     *  CSV
     * ================================================================= */

    /** @return array{0: list<string>, 1: list<list<string|float>>} header + rows for the month */
    public static function csv(string $period): array
    {
        $header = ['Month', 'Name', 'Job', 'Location', 'Mobile', 'Currency', 'Monthly salary', 'Status',
                   'Gross', 'Advance deduction', 'Bonus', 'Other deduction', 'Net paid', 'Paid on', 'Method',
                   'Reference', 'Advance still owed'];
        $rows = [];
        foreach (self::monthSheet($period)['rows'] as $r) {
            $p = $r['payment'];
            $rows[] = [
                $period,
                (string) $r['full_name'],
                (string) $r['role'],
                (string) ($r['base_location'] ?? ''),
                (string) ($r['phone'] ?? ''),
                (string) $r['currency'],
                number_format((float) $r['monthly_salary'], 2, '.', ''),
                $p !== null ? 'Paid' : ($r['due'] ? 'Due' : 'Not due'),
                $p !== null ? number_format((float) $p['gross'], 2, '.', '') : '',
                $p !== null ? number_format((float) $p['advance_deduction'], 2, '.', '') : '',
                $p !== null ? number_format((float) $p['bonus'], 2, '.', '') : '',
                $p !== null ? number_format((float) $p['other_deduction'], 2, '.', '') : '',
                $p !== null ? number_format((float) $p['net_paid'], 2, '.', '') : '',
                $p !== null ? (string) $p['paid_on'] : '',
                $p !== null ? (string) $p['method'] : '',
                $p !== null ? (string) ($p['reference'] ?? '') : '',
                number_format((float) $r['balance'], 2, '.', ''),
            ];
        }
        return [$header, $rows];
    }

    /* =================================================================
     *  Input helpers
     * ================================================================= */

    /**
     * The columns of $new that differ from the stored $row — money compared
     * as numbers ('12000.00' from MariaDB equals 12000.0 from the form).
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $new
     * @return array{0: array<string,mixed>, 1: array<string,mixed>} [old values, new values]
     */
    private static function changed(array $row, array $new): array
    {
        $old = [];
        $chg = [];
        foreach ($new as $k => $v) {
            $was = $row[$k] ?? null;
            $same = (is_float($v) || is_int($v)) && is_numeric($was)
                ? abs((float) $was - (float) $v) < 0.005
                : (string) ($was ?? '') === (string) ($v ?? '');
            if (!$same) {
                $old[$k] = $was;
                $chg[$k] = $v;
            }
        }
        return [$old, $chg];
    }

    /** A form value as a string; arrays (amount[]=…) become ''. */
    private static function str(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    private static function amount(mixed $v, string $label, bool $mustBePositive = false): float
    {
        if ($v !== null && !is_scalar($v)) {
            throw new RuntimeException($label . ' must be a number.');
        }
        $s = trim(str_replace([',', '₹', ' '], '', self::str($v)));
        if ($s === '') {
            $s = '0';
        }
        if (!is_numeric($s)) {
            throw new RuntimeException($label . ' must be a number.');
        }
        $f = round((float) $s, 2);
        if ($f < 0) {
            throw new RuntimeException($label . ' cannot be negative.');
        }
        if ($f > self::MAX_AMOUNT) {
            throw new RuntimeException($label . ' is too large.');
        }
        if ($mustBePositive && $f <= 0) {
            throw new RuntimeException($label . ' must be more than 0.');
        }
        return $f;
    }
}
