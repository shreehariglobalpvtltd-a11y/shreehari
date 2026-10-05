<?php
/**
 * includes/agentsettlement.php — the settlement RECORD (5 Oct 2026, owner's
 * Point 7: "Settlement & Payout System").
 *
 * Until now an agent was settled by typing one payout (or one cash handover)
 * on Agent 360: a single ledger row, no period, no breakdown, nothing the
 * agent could confirm. A settlement is the paper around that money:
 *
 *   period  ->  opening balance + commission earned - reversals - advances
 *               +- adjustments - already paid  =  commission due
 *   then    ->  - itemised deductions + bonus - cash net-off  =  NET to pay
 *
 *   draft -> approved -> paid -> confirmed (by the agent)      [or disputed]
 *
 * WHAT MOVES MONEY, AND WHEN. Nothing here is automatic. Drafting, approving
 * and cancelling change only the settlement row. The ledger is written at one
 * moment only - when a person with commissions.pay presses "Mark paid" - and
 * always through AgentWallet::record(), the same door Agent 360 uses, inside
 * one transaction with the status change (a repeated tap finds the row
 * already paid and stops). The agent ledger stays the single source of truth:
 * the figures on a settlement are a snapshot of it, re-read every time a
 * draft is opened and frozen at approval.
 *
 * Table: agent_settlements (database/upgrade-2026-10-05-agent-finance.sql).
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once INCLUDE_PATH . '/agentwallet.php';

final class AgentSettlement
{
    public const STATUSES = ['draft', 'approved', 'paid', 'confirmed', 'disputed', 'cancelled'];
    public const MODES    = ['cash' => 'Cash', 'upi' => 'UPI', 'bank' => 'Bank transfer', 'esewa' => 'eSewa', 'adjust' => 'No payment (net-off only)'];
    public const MAX_DEDUCTIONS = 8;

    private static ?bool $hasTable = null;

    public static function hasTable(): bool
    {
        if (self::$hasTable === null) {
            try {
                self::$hasTable = (int) Database::scalar(
                    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agent_settlements'",
                    [], 0
                ) > 0;
            } catch (Throwable $e) {
                self::$hasTable = false;
            }
        }
        return self::$hasTable;
    }

    /** ST-2610-0007: year+month of creation and the row id - unique, never reused. */
    public static function number(array $row): string
    {
        $ts = strtotime((string) ($row['created_at'] ?? 'now')) ?: time();
        return 'ST-' . date('ym', $ts) . '-' . str_pad((string) (int) ($row['id'] ?? 0), 4, '0', STR_PAD_LEFT);
    }

    /**
     * The commission account of one agent over [$from, $to] (inclusive days),
     * straight from the ledger, plus what is owed and held right now.
     *
     * @return array<string, float|int|string>
     */
    public static function compute(int $agentId, string $from, string $to): array
    {
        if (!Security::isValidDate($from) || !Security::isValidDate($to) || $from > $to) {
            throw new RuntimeException('Choose a valid settlement period.');
        }
        $out = [
            'from' => $from, 'to' => $to,
            'opening' => AgentWallet::openingBalance($agentId, $from, 'commission'),
            'gross' => 0.0, 'reversals' => 0.0, 'advances' => 0.0, 'adjustments' => 0.0, 'paid' => 0.0,
            'tickets' => 0, 'seats' => 0,
        ];
        $rows = Database::fetchAll(
            "SELECT entry_type, (ref LIKE 'ADVANCE%') AS is_adv, COALESCE(SUM(amount), 0) AS amt, COUNT(*) AS n
               FROM agent_ledger
              WHERE agent_admin_id = :a AND account = 'commission'
                AND created_at >= :f AND created_at < :t
              GROUP BY entry_type, (ref LIKE 'ADVANCE%')",
            ['a' => $agentId, 'f' => $from . ' 00:00:00', 't' => addDaysISO($to, 1) . ' 00:00:00']
        );
        foreach ($rows as $r) {
            $amt = (float) $r['amt'];
            switch ((string) $r['entry_type']) {
                case 'commission':      $out['gross'] += $amt; $out['tickets'] += (int) $r['n']; break;
                case 'commission_void': $out['reversals'] += $amt; break;
                case 'payout':          $out['paid'] += $amt; break;
                case 'adjustment':
                    if ((int) $r['is_adv'] === 1) { $out['advances'] += $amt; } else { $out['adjustments'] += $amt; }
                    break;
            }
        }
        try {
            $out['seats'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM agent_ledger l
                   JOIN booking_seats bs ON bs.booking_id = l.booking_id AND bs.released_at IS NULL
                  WHERE l.agent_admin_id = :a AND l.entry_type = 'commission'
                    AND l.created_at >= :f AND l.created_at < :t",
                ['a' => $agentId, 'f' => $from . ' 00:00:00', 't' => addDaysISO($to, 1) . ' 00:00:00'], 0
            );
        } catch (Throwable $e) {
            $out['seats'] = 0;
        }
        foreach (['opening', 'gross', 'reversals', 'advances', 'adjustments', 'paid'] as $k) {
            $out[$k] = round((float) $out[$k], 2);
        }
        $out['closing'] = round($out['opening'] + $out['gross'] + $out['reversals'] + $out['advances'] + $out['adjustments'] + $out['paid'], 2);

        $bal = AgentWallet::balances($agentId);
        $out['dueNow']   = round((float) $bal['commission'], 2);
        $out['cashHeld'] = round(max(0.0, (float) $bal['cash']), 2);
        /* What this settlement may pay: the period's closing balance, never
           more than is owed today (a later payout or reversal already took
           its share), never below zero. */
        $out['due'] = round(max(0.0, min($out['closing'], $out['dueNow'])), 2);
        return $out;
    }

    /**
     * The default period for a new settlement: the day after the agent's last
     * settled period (else their first ledger day) up to today.
     *
     * @return array{0: string, 1: string}
     */
    public static function defaultPeriod(int $agentId): array
    {
        $today = todayISO();
        $from  = '';
        if (self::hasTable()) {
            $last = (string) Database::scalar(
                "SELECT MAX(period_to) FROM agent_settlements WHERE agent_admin_id = :a AND status IN ('paid','confirmed','disputed')",
                ['a' => $agentId], ''
            );
            if ($last !== '' && Security::isValidDate($last)) {
                $from = addDaysISO($last, 1);
            }
        }
        if ($from === '') {
            $first = (string) Database::scalar(
                'SELECT DATE(MIN(created_at)) FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $agentId], ''
            );
            $from = ($first !== '' && Security::isValidDate($first)) ? $first : substr($today, 0, 8) . '01';
        }
        if ($from > $today) {
            $from = $today;
        }
        return [$from, $today];
    }

    /**
     * Clean the itemised deductions from a form: [['label' => .., 'amount' => ..], ..].
     *
     * @param  mixed $labels
     * @param  mixed $amounts
     * @return list<array{label: string, amount: float}>
     */
    public static function cleanDeductions($labels, $amounts): array
    {
        $out = [];
        if (!is_array($labels) || !is_array($amounts)) {
            return $out;
        }
        foreach ($labels as $i => $label) {
            $label = trim(Security::clean((string) $label, 80));
            $amt   = round((float) ($amounts[$i] ?? 0), 2);
            if ($amt <= 0) {
                continue;
            }
            if ($label === '') {
                throw new RuntimeException('Every deduction needs a short reason.');
            }
            $out[] = ['label' => $label, 'amount' => $amt];
            if (count($out) >= self::MAX_DEDUCTIONS) {
                break;
            }
        }
        return $out;
    }

    /**
     * Work out the money lines of a settlement from the ledger snapshot and
     * the office's own inputs. Throws when the inputs cannot be honoured.
     *
     * @param  array<string, mixed>                          $calc       compute() result
     * @param  list<array{label: string, amount: float}>     $deductions
     * @return array{deductions_total: float, bonus: float, cash_offset: float, cash_received: float, net: float, cash_left: float}
     */
    public static function totals(array $calc, array $deductions, float $bonus, float $cashOffset, float $cashReceived): array
    {
        $dedTotal = round(array_sum(array_column($deductions, 'amount')), 2);
        $bonus    = round(max(0.0, $bonus), 2);
        $offset   = round(max(0.0, $cashOffset), 2);
        $received = round(max(0.0, $cashReceived), 2);
        $due      = (float) $calc['due'];
        $payable  = round($due - $dedTotal + $bonus, 2);
        if ($payable < -0.001) {
            throw new RuntimeException('The deductions (' . inr($dedTotal) . ') are more than the commission due (' . inr($due + $bonus) . ').');
        }
        if ($offset > $payable + 0.001) {
            throw new RuntimeException('The cash net-off cannot be more than the ' . inr($payable) . ' payable to the agent.');
        }
        if ($offset + $received > (float) $calc['cashHeld'] + 0.001) {
            throw new RuntimeException('Net-off + cash received (' . inr($offset + $received) . ') is more than the ' . inr((float) $calc['cashHeld']) . ' this agent holds.');
        }
        return [
            'deductions_total' => $dedTotal,
            'bonus'            => $bonus,
            'cash_offset'      => $offset,
            'cash_received'    => $received,
            'net'              => round($payable - $offset, 2),
            'cash_left'        => round((float) $calc['cashHeld'] - $offset - $received, 2),
        ];
    }

    /**
     * Create a draft (no money moves). Returns the new id.
     *
     * @param array{deductions?: list<array{label: string, amount: float}>, bonus?: float, bonus_note?: string, cash_offset?: float, cash_received?: float, notes?: string} $in
     */
    public static function createDraft(int $agentId, string $from, string $to, array $in, int $by): int
    {
        if (!self::hasTable()) {
            throw new RuntimeException('Settlements are not set up on this database yet (run the 5 Oct migration).');
        }
        $agent = Database::fetch("SELECT id FROM admins WHERE id = :i AND role = 'agent'", ['i' => $agentId]);
        if ($agent === null) {
            throw new RuntimeException('Choose an agent first.');
        }
        $open = (int) Database::scalar(
            "SELECT COUNT(*) FROM agent_settlements WHERE agent_admin_id = :a AND status IN ('draft','approved')", ['a' => $agentId], 0
        );
        if ($open > 0) {
            throw new RuntimeException('This agent already has a settlement waiting (draft / approved). Finish or cancel that one first.');
        }
        $calc = self::compute($agentId, $from, $to);
        $ded  = $in['deductions'] ?? [];
        $tot  = self::totals($calc, $ded, (float) ($in['bonus'] ?? 0), (float) ($in['cash_offset'] ?? 0), (float) ($in['cash_received'] ?? 0));

        $id = Database::insert('agent_settlements', self::snapshotColumns($calc, $ded, $tot) + [
            'agent_admin_id' => $agentId,
            'period_from'    => $from,
            'period_to'      => $to,
            'bonus_note'     => Security::clean((string) ($in['bonus_note'] ?? ''), 160),
            'notes'          => Security::clean((string) ($in['notes'] ?? ''), 500),
            'status'         => 'draft',
            'created_by'     => $by ?: null,
        ]);
        Logger::audit('settlement.draft', 'settlement', (string) $id, null,
            ['agent' => $agentId, 'from' => $from, 'to' => $to, 'net' => $tot['net']], 'settlement drafted');
        return $id;
    }

    /**
     * Re-read the ledger into a draft and store the office's edits.
     *
     * @param array<string, mixed> $in same keys as createDraft()
     */
    public static function updateDraft(int $id, array $in, int $by): void
    {
        $row = self::get($id);
        if ($row === null || $row['status'] !== 'draft') {
            throw new RuntimeException('Only a draft can be edited.');
        }
        $from = (string) ($in['from'] ?? $row['period_from']);
        $to   = (string) ($in['to'] ?? $row['period_to']);
        $calc = self::compute((int) $row['agent_admin_id'], $from, $to);
        $ded  = $in['deductions'] ?? self::deductionsOf($row);
        $tot  = self::totals($calc, $ded, (float) ($in['bonus'] ?? $row['bonus']), (float) ($in['cash_offset'] ?? $row['cash_offset']), (float) ($in['cash_received'] ?? $row['cash_received']));
        Database::update('agent_settlements', self::snapshotColumns($calc, $ded, $tot) + [
            'period_from' => $from,
            'period_to'   => $to,
            'bonus_note'  => Security::clean((string) ($in['bonus_note'] ?? $row['bonus_note']), 160),
            'notes'       => Security::clean((string) ($in['notes'] ?? $row['notes']), 500),
        ], "id = :id AND status = 'draft'", ['id' => $id]);
    }

    /** draft -> approved: the figures are re-read once more and frozen. */
    public static function approve(int $id, int $by): void
    {
        $row = self::get($id);
        if ($row === null || $row['status'] !== 'draft') {
            throw new RuntimeException('Only a draft can be approved.');
        }
        self::updateDraft($id, [], $by);
        $n = Database::run(
            "UPDATE agent_settlements SET status = 'approved', approved_by = :b, approved_at = NOW() WHERE id = :id AND status = 'draft'",
            ['b' => $by ?: null, 'id' => $id]
        );
        if ($n < 1) {
            throw new RuntimeException('This settlement was changed by someone else — reload the page.');
        }
        Logger::audit('settlement.approve', 'settlement', (string) $id, ['status' => 'draft'], ['status' => 'approved'], 'settlement approved');
    }

    /** draft / approved -> cancelled. No money ever moved, so nothing to undo. */
    public static function cancel(int $id, int $by, string $why): void
    {
        $n = Database::run(
            "UPDATE agent_settlements SET status = 'cancelled', resolved_note = :w WHERE id = :id AND status IN ('draft','approved')",
            ['w' => Security::clean($why, 500), 'id' => $id]
        );
        if ($n < 1) {
            throw new RuntimeException('Only a draft or an approved settlement can be cancelled. A paid one is corrected with a ledger adjustment.');
        }
        Logger::audit('settlement.cancel', 'settlement', (string) $id, null, ['status' => 'cancelled', 'by' => $by], $why);
    }

    /**
     * approved -> paid. THE ONLY PLACE A SETTLEMENT TOUCHES THE LEDGER.
     * Deductions and bonus become ADJ rows, a cash net-off becomes a matching
     * payout + cash handover, cash received becomes a cash handover, and the
     * net becomes the payout - all or nothing, in one transaction.
     */
    public static function markPaid(int $id, string $mode, string $ref, int $by): void
    {
        if (!isset(self::MODES[$mode])) {
            throw new RuntimeException('Choose how it was paid.');
        }
        $ref = Security::clean($ref, 80);
        Database::transaction(static function () use ($id, $mode, $ref, $by): void {
            $row = Database::fetch('SELECT * FROM agent_settlements WHERE id = :id FOR UPDATE', ['id' => $id]);
            if ($row === null || $row['status'] !== 'approved') {
                throw new RuntimeException('Only an approved settlement can be marked paid (was it paid already?).');
            }
            $agentId = (int) $row['agent_admin_id'];
            $no      = self::number($row);
            $net     = (float) $row['net_amount'];
            $offset  = (float) $row['cash_offset'];
            $recv    = (float) $row['cash_received'];
            if ($net > 0.001 && $mode === 'adjust') {
                throw new RuntimeException('There is ' . inr($net) . ' to pay — choose cash, UPI, bank or eSewa.');
            }
            /* The ledger may have moved since approval (a cancelled ticket, a
               payout typed on Agent 360). Never pay a figure it no longer backs. */
            $bal     = AgentWallet::balances($agentId);
            $needCom = round($net + $offset + (float) $row['deductions_total'] - (float) $row['bonus'], 2);
            if ($needCom > (float) $bal['commission'] + 0.001) {
                throw new RuntimeException('The ledger changed after approval: only ' . inr((float) $bal['commission'])
                    . ' is owed now, this settlement needs ' . inr($needCom) . '. Cancel it and draft a new one.');
            }
            if ($offset + $recv > max(0.0, (float) $bal['cash']) + 0.001) {
                throw new RuntimeException('The agent now holds less cash than this settlement nets off. Cancel it and draft a new one.');
            }

            $ids   = [];
            $label = $no . ' · ' . formatDate((string) $row['period_from'], 'j M') . ' – ' . formatDate((string) $row['period_to'], 'j M Y');
            foreach (self::deductionsOf($row) as $d) {
                $ids[] = AgentWallet::record($agentId, 'adjustment', (float) $d['amount'], [
                    'account' => 'commission', 'direction' => 'debit', 'ref' => 'ADJ ' . $no,
                    'note' => 'Deduction: ' . $d['label'], 'by' => $by,
                ]);
            }
            if ((float) $row['bonus'] > 0) {
                $ids[] = AgentWallet::record($agentId, 'adjustment', (float) $row['bonus'], [
                    'account' => 'commission', 'direction' => 'credit', 'ref' => 'ADJ ' . $no,
                    'note' => 'Bonus' . (trim((string) $row['bonus_note']) !== '' ? ': ' . $row['bonus_note'] : ''), 'by' => $by,
                ]);
            }
            if ($offset > 0) {
                $ids[] = AgentWallet::record($agentId, 'payout', $offset, [
                    'ref' => $no, 'note' => 'Net-off against cash held · ' . $label, 'by' => $by,
                ]);
                $ids[] = AgentWallet::record($agentId, 'cash_handover', $offset, [
                    'ref' => $no, 'note' => 'Net-off against commission · ' . $label, 'by' => $by,
                ]);
            }
            $payoutId = 0;
            if ($net > 0.001) {
                $payoutId = AgentWallet::record($agentId, 'payout', $net, [
                    'ref' => $ref !== '' ? $ref : $no,
                    'note' => 'Settlement ' . $label . ' · ' . self::MODES[$mode], 'by' => $by,
                ]);
                $ids[] = $payoutId;
            }
            if ($recv > 0) {
                $ids[] = AgentWallet::record($agentId, 'cash_handover', $recv, [
                    'ref' => $no, 'note' => 'Cash received with settlement · ' . $label, 'by' => $by,
                ]);
            }
            Database::update('agent_settlements', [
                'status'       => 'paid',
                'payment_mode' => $mode,
                'payment_ref'  => $ref,
                'paid_by'      => $by ?: null,
                'paid_at'      => date('Y-m-d H:i:s'),
                'ledger_ids'   => implode(',', array_map('intval', $ids)),
            ], 'id = :id', ['id' => $id]);
            Logger::audit('settlement.paid', 'settlement', (string) $id, ['status' => 'approved'],
                ['status' => 'paid', 'net' => $net, 'mode' => $mode, 'ref' => $ref, 'ledger' => $ids], 'settlement ' . $no . ' paid');
        });
    }

    /** The agent's own answer on a paid settlement: received, or a dispute with a reason. */
    public static function agentRespond(int $id, int $agentId, bool $confirm, string $note): void
    {
        $note = Security::clean($note, 500);
        if (!$confirm && trim($note) === '') {
            throw new RuntimeException('Write what is wrong, so the office can check it.');
        }
        $n = $confirm
            ? Database::run("UPDATE agent_settlements SET status = 'confirmed', confirmed_at = NOW() WHERE id = :id AND agent_admin_id = :a AND status = 'paid'",
                ['id' => $id, 'a' => $agentId])
            : Database::run("UPDATE agent_settlements SET status = 'disputed', dispute_note = :n, disputed_at = NOW() WHERE id = :id AND agent_admin_id = :a AND status IN ('paid','confirmed')",
                ['n' => $note, 'id' => $id, 'a' => $agentId]);
        if ($n < 1) {
            throw new RuntimeException('This settlement cannot be ' . ($confirm ? 'confirmed' : 'disputed') . ' now.');
        }
        Logger::audit('settlement.' . ($confirm ? 'confirmed' : 'disputed'), 'settlement', (string) $id, null,
            ['by_agent' => $agentId], $confirm ? 'agent confirmed the settlement' : $note);
    }

    /** The office closes a dispute (the correction itself, if any, is a ledger adjustment on Agent 360). */
    public static function resolveDispute(int $id, int $by, string $note): void
    {
        $note = Security::clean($note, 500);
        if (trim($note) === '') {
            throw new RuntimeException('Write how it was resolved.');
        }
        $n = Database::run(
            "UPDATE agent_settlements SET status = 'paid', resolved_note = :n WHERE id = :id AND status = 'disputed'",
            ['n' => $note, 'id' => $id]
        );
        if ($n < 1) {
            throw new RuntimeException('This settlement is not in dispute.');
        }
        Logger::audit('settlement.resolved', 'settlement', (string) $id, ['status' => 'disputed'], ['status' => 'paid', 'by' => $by], $note);
    }

    /** @return array<string, mixed>|null */
    public static function get(int $id): ?array
    {
        if (!self::hasTable()) {
            return null;
        }
        return Database::fetch(
            'SELECT s.*, a.full_name, a.username, a.phone
               FROM agent_settlements s JOIN admins a ON a.id = s.agent_admin_id
              WHERE s.id = :id',
            ['id' => $id]
        );
    }

    /** @return list<array{label: string, amount: float}> */
    public static function deductionsOf(array $row): array
    {
        $d = json_decode((string) ($row['deductions_json'] ?? ''), true);
        $out = [];
        foreach (is_array($d) ? $d : [] as $x) {
            if (is_array($x) && isset($x['label'], $x['amount']) && (float) $x['amount'] > 0) {
                $out[] = ['label' => (string) $x['label'], 'amount' => round((float) $x['amount'], 2)];
            }
        }
        return $out;
    }

    /**
     * The register. $f: status, agent (id), from, to (created date), q.
     *
     * @param  array<string, mixed> $f
     * @return list<array<string, mixed>>
     */
    public static function listing(array $f = [], ?int $onlyAgent = null, int $limit = 300): array
    {
        if (!self::hasTable()) {
            return [];
        }
        $where = [];
        $p     = [];
        if ($onlyAgent !== null) {
            $where[] = 's.agent_admin_id = :own';
            $p['own'] = $onlyAgent;
            $where[] = "s.status IN ('paid','confirmed','disputed')";   // an agent never sees office drafts
        } elseif (!empty($f['agent']) && ctype_digit((string) $f['agent'])) {
            $where[] = 's.agent_admin_id = :ag';
            $p['ag'] = (int) $f['agent'];
        }
        if (!empty($f['status']) && in_array($f['status'], self::STATUSES, true)) {
            $where[] = 's.status = :st';
            $p['st'] = $f['status'];
        } elseif (($f['status'] ?? '') === 'open') {
            $where[] = "s.status IN ('draft','approved','disputed')";
        }
        if (!empty($f['from']) && Security::isValidDate((string) $f['from'])) {
            $where[] = 's.created_at >= :f';
            $p['f'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to']) && Security::isValidDate((string) $f['to'])) {
            $where[] = 's.created_at < :t';
            $p['t'] = addDaysISO((string) $f['to'], 1) . ' 00:00:00';
        }
        return Database::fetchAll(
            'SELECT s.*, a.full_name, a.username
               FROM agent_settlements s JOIN admins a ON a.id = s.agent_admin_id'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY s.id DESC LIMIT ' . max(1, min(1000, $limit)),
            $p
        );
    }

    /**
     * Who is waiting to be settled: every active agent with commission due
     * (or cash in hand), how long since they were last paid, and whether a
     * settlement is already in progress. Sorted longest-waiting first.
     *
     * @return list<array<string, mixed>>
     */
    public static function pending(): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT a.id, a.full_name, a.username,
                        COALESCE(SUM(CASE WHEN l.account = 'commission' THEN l.amount END), 0) AS commission,
                        COALESCE(SUM(CASE WHEN l.account = 'cash' THEN l.amount END), 0) AS cash,
                        MAX(CASE WHEN l.entry_type = 'payout' THEN l.created_at END) AS last_payout,
                        MIN(CASE WHEN l.entry_type = 'commission' THEN l.created_at END) AS first_commission,
                        MAX(CASE WHEN l.entry_type = 'cash_handover' THEN l.created_at END) AS last_handover,
                        MIN(CASE WHEN l.entry_type = 'cash_due' THEN l.created_at END) AS first_cash
                   FROM admins a
                   JOIN agent_ledger l ON l.agent_admin_id = a.id
                  WHERE a.role = 'agent'
                  GROUP BY a.id, a.full_name, a.username
                 HAVING commission > 0.009 OR cash > 0.009"
            );
        } catch (Throwable $e) {
            return [];
        }
        $openBy = [];
        if (self::hasTable()) {
            foreach (Database::fetchAll("SELECT id, agent_admin_id, status FROM agent_settlements WHERE status IN ('draft','approved','disputed')") as $o) {
                $openBy[(int) $o['agent_admin_id']] = $o;
            }
        }
        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $com   = round((float) $r['commission'], 2);
            $cash  = round((float) $r['cash'], 2);
            $since = $r['last_payout'] ?: $r['first_commission'];
            $cashSince = $r['last_handover'] ?: $r['first_cash'];
            $out[] = [
                'id'         => (int) $r['id'],
                'name'       => (string) ($r['full_name'] ?: $r['username']),
                'code'       => AgentWallet::agentCodeLabel((int) $r['id']),
                'commission' => $com,
                'cash'       => max(0.0, $cash),
                'net'        => round($com - max(0.0, $cash), 2),          // + the company owes the agent
                'days'       => ($com > 0.009 && $since) ? (int) floor(($now - (int) strtotime((string) $since)) / 86400) : 0,
                'cashDays'   => ($cash > 0.009 && $cashSince) ? (int) floor(($now - (int) strtotime((string) $cashSince)) / 86400) : 0,
                'lastPaid'   => $r['last_payout'] ? (string) $r['last_payout'] : '',
                'open'       => $openBy[(int) $r['id']] ?? null,
            ];
        }
        usort($out, static fn(array $x, array $y): int => [$y['days'], $y['commission']] <=> [$x['days'], $x['commission']]);
        return $out;
    }

    /**
     * Commission earned / paid / still due, per agent per month - the grid the
     * accountant exports. Months newest first, as 'Y-m'.
     *
     * @return array{months: list<string>, rows: list<array<string, mixed>>}
     */
    public static function commissionGrid(int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $start  = date('Y-m-01', strtotime('-' . ($months - 1) . ' months', strtotime(date('Y-m-01'))));
        $keys   = [];
        for ($i = 0; $i < $months; $i++) {
            $keys[] = date('Y-m', strtotime('-' . $i . ' months', strtotime(date('Y-m-01'))));
        }
        try {
            $rows = Database::fetchAll(
                "SELECT l.agent_admin_id AS id, a.full_name, a.username, DATE_FORMAT(l.created_at, '%Y-%m') AS ym,
                        COALESCE(SUM(CASE WHEN l.entry_type IN ('commission','commission_void') THEN l.amount END), 0) AS earned,
                        COALESCE(SUM(CASE WHEN l.entry_type = 'payout' THEN -l.amount END), 0) AS paid
                   FROM agent_ledger l JOIN admins a ON a.id = l.agent_admin_id
                  WHERE l.account = 'commission' AND l.created_at >= :s
                  GROUP BY l.agent_admin_id, a.full_name, a.username, ym",
                ['s' => $start . ' 00:00:00']
            );
        } catch (Throwable $e) {
            return ['months' => $keys, 'rows' => []];
        }
        $by = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if (!isset($by[$id])) {
                $by[$id] = ['id' => $id, 'name' => (string) ($r['full_name'] ?: $r['username']), 'code' => AgentWallet::agentCodeLabel($id),
                            'm' => [], 'earned' => 0.0, 'paid' => 0.0, 'due' => 0.0];
            }
            $by[$id]['m'][(string) $r['ym']] = ['earned' => round((float) $r['earned'], 2), 'paid' => round((float) $r['paid'], 2)];
            $by[$id]['earned'] += (float) $r['earned'];
            $by[$id]['paid']   += (float) $r['paid'];
        }
        foreach ($by as $id => $_) {
            $by[$id]['earned'] = round($by[$id]['earned'], 2);
            $by[$id]['paid']   = round($by[$id]['paid'], 2);
            $by[$id]['due']    = round((float) AgentWallet::balances($id)['commission'], 2);
        }
        $list = array_values($by);
        usort($list, static fn(array $x, array $y): int => $y['earned'] <=> $x['earned']);
        return ['months' => $keys, 'rows' => $list];
    }

    /**
     * @param  array<string, mixed>                      $calc
     * @param  list<array{label: string, amount: float}> $ded
     * @param  array<string, float>                      $tot
     * @return array<string, mixed>
     */
    private static function snapshotColumns(array $calc, array $ded, array $tot): array
    {
        return [
            'opening_balance'    => $calc['opening'],
            'gross_commission'   => $calc['gross'],
            'reversals'          => $calc['reversals'],
            'advance_adjustment' => $calc['advances'],
            'other_adjustments'  => $calc['adjustments'],
            'paid_in_period'     => $calc['paid'],
            'commission_due'     => $calc['due'],
            'cash_held'          => $calc['cashHeld'],
            'tickets'            => $calc['tickets'],
            'seats'              => $calc['seats'],
            'deductions_json'    => json_encode($ded, JSON_UNESCAPED_UNICODE),
            'deductions_total'   => $tot['deductions_total'],
            'bonus'              => $tot['bonus'],
            'cash_offset'        => $tot['cash_offset'],
            'cash_received'      => $tot['cash_received'],
            'net_amount'         => $tot['net'],
        ];
    }
}
