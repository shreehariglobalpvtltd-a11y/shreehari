<?php
declare(strict_types=1);

/**
 * MoneySummary — the dashboard's money figures, with ONE meaning each
 * (Oct 2026 redesign, owner brief: "distinguish collections, revenue,
 * outstanding balances and profit; label missing data, never invent").
 *
 * Every definition here is the one admin/accounting.php already uses, with
 * the same SQL shape, so the Dashboard and Accounting can never disagree:
 *
 *   collected     money actually in: confirmed/completed bookings whose
 *                 confirmation falls in the window, EXCEPT cash-on-delivery
 *                 still marked cod_pending. NPR converted at npr_per_inr.
 *   confirmedFare revenue: the fare of every confirmed/completed booking in
 *                 the window, collected or not (= collected + codPending).
 *   codPending    confirmed fares whose cash is still to be collected.
 *   agentCash     cash agents are holding for the company right now (live
 *                 agent_ledger 'cash' balance — not windowed).
 *   commission    commission accrued to agents in the window, net of voids
 *                 (agent_ledger, the single ledger source).
 *   expenses      recorded trip_expenses in the window + how many rows.
 *
 * Profit is deliberately NOT computed here: it needs recorded expenses and
 * salaries, and Accounting owns that formula ("Net after expenses &
 * salaries"). The dashboard says "Not available" when no expense is
 * recorded for the window and links to Accounting otherwise.
 */
final class MoneySummary
{
    /** @return array{collected: float, collectedCount: int, confirmedFare: float, confirmedCount: int, codPending: float, codCount: int, agentCash: float, commission: float, expenses: float, expenseRows: int, peg: float} */
    public static function window(string $from, string $to): array
    {
        $peg = Settings::getFloat('npr_per_inr', (float) NPR_PER_INR);
        if ($peg <= 0) {
            $peg = (float) NPR_PER_INR;
        }
        $t1  = addDaysISO($to, 1);
        $out = [
            'collected' => 0.0, 'collectedCount' => 0, 'confirmedFare' => 0.0, 'confirmedCount' => 0,
            'codPending' => 0.0, 'codCount' => 0, 'agentCash' => 0.0, 'commission' => 0.0,
            'expenses' => 0.0, 'expenseRows' => 0, 'peg' => $peg,
        ];

        try {
            $rows = Database::fetchAll(
                "SELECT b.total_amount, b.currency,
                        (SELECT p2.status FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1) AS pay_status
                   FROM bookings b
                  WHERE b.status IN ('confirmed','completed')
                    AND b.confirmed_at >= :f AND b.confirmed_at < :t1",
                ['f' => $from, 't1' => $t1]
            );
            foreach ($rows as $r) {
                $amt = strtoupper((string) $r['currency']) === 'NPR' ? (float) $r['total_amount'] / $peg : (float) $r['total_amount'];
                $out['confirmedCount']++;
                $out['confirmedFare'] += $amt;
                if ((string) ($r['pay_status'] ?? '') === 'cod_pending') {
                    $out['codCount']++;
                    $out['codPending'] += $amt;
                    continue;
                }
                $out['collectedCount']++;
                $out['collected'] += $amt;
            }
        } catch (Throwable $e) {
            // leave zeros — the page still renders
        }

        try {
            $out['commission'] = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
                  WHERE account = 'commission' AND entry_type IN ('commission', 'commission_void')
                    AND created_at >= :f AND created_at < :t1",
                ['f' => $from, 't1' => $t1],
                0
            );
        } catch (Throwable $e) { /* ledger absent */ }

        try {
            $out['agentCash'] = (float) Database::scalar(
                "SELECT COALESCE(SUM(bal), 0) FROM (
                    SELECT SUM(amount) bal FROM agent_ledger WHERE account = 'cash' GROUP BY agent_admin_id HAVING bal > 0.5
                 ) t",
                [],
                0
            );
        } catch (Throwable $e) { /* ledger absent */ }

        try {
            $ex = Database::fetch(
                'SELECT COUNT(*) c, COALESCE(SUM(total), 0) amt FROM trip_expenses WHERE expense_date BETWEEN :f AND :t',
                ['f' => $from, 't' => $to]
            );
            $out['expenseRows'] = (int) ($ex['c'] ?? 0);
            $out['expenses']    = (float) ($ex['amt'] ?? 0);
        } catch (Throwable $e) { /* table absent */ }

        return $out;
    }
}
