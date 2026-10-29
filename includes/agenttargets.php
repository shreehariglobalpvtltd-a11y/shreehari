<?php
/**
 * =====================================================================
 *  AgentTargets — a monthly sales target per agent, the progress against
 *  it, the agent's rank, and the bonus above it (29 Oct 2026, owner's
 *  management-upgrade prompt, Phase 1.2 "Commission target system").
 *
 *  WHY THIS EXISTS
 *  ---------------
 *  Until now there was ONE company-wide number, Settings
 *  agent_target_monthly, shown to every agent alike. A new agent and the
 *  busiest counter were measured against the same ₹1,00,000. This adds a
 *  row per agent per month (agent_targets) that the office sets from
 *  Agent 360; with no row the company default still applies, so nothing
 *  changes for an agent until the office gives them their own figure.
 *
 *  WHAT COUNTS AS A SALE
 *  ---------------------
 *  Exactly what admin/agent-ranking.php and the agent panel already count:
 *  bookings this agent sold (sold_by_admin_id), status 'confirmed',
 *  created inside the month, SUM(total_amount). One definition, so the
 *  target bar, the leaderboard and the panel can never disagree.
 *
 *  THE BONUS IS NEVER AUTOMATIC
 *  ----------------------------
 *  bonus_pct % of the sales ABOVE the target is shown as a projection
 *  while the month runs. It reaches the wallet only when someone holding
 *  commissions.pay presses "Post bonus" for a month that has ENDED — one
 *  'adjustment' credit on the commission account, ref BONUS-YYYY-MM,
 *  refused a second time. Same rule as every other money movement here:
 *  a person decides, the ledger records.
 *
 *  The table creates itself on first use (same pattern as AdminSessions),
 *  and every read survives its absence.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AgentTargets
{
    private static bool $ready = false;

    /** @var array<string, array<int, float>> month => [agentId => confirmed sales] */
    private static array $salesCache = [];

    /** Create the table on first use (no hand-run migration needed). */
    public static function ensure(): bool
    {
        if (self::$ready) {
            return true;
        }
        try {
            Database::query(
                "CREATE TABLE IF NOT EXISTS agent_targets (
                    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
                    agent_admin_id  INT UNSIGNED  NOT NULL,
                    month           CHAR(7)       NOT NULL,
                    target_amount   DECIMAL(12,2) NOT NULL DEFAULT 0,
                    target_bookings INT UNSIGNED  NOT NULL DEFAULT 0,
                    bonus_pct       DECIMAL(5,2)  NOT NULL DEFAULT 0,
                    note            VARCHAR(255)  NULL,
                    set_by          INT UNSIGNED  NULL,
                    created_at      DATETIME      NOT NULL,
                    updated_at      DATETIME      NOT NULL,
                    UNIQUE KEY uq_agent_month (agent_admin_id, month),
                    KEY idx_month (month)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            self::$ready = true;
        } catch (Throwable $e) {
            Logger::warning('agent_targets unavailable: ' . $e->getMessage(), [], 'agent');
            self::$ready = false;
        }
        return self::$ready;
    }

    public static function validMonth(string $ym): bool
    {
        return preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $ym) === 1;
    }

    /** [first day 'Y-m-d', first day of next month 'Y-m-d'] for 'YYYY-MM'. */
    public static function window(string $ym): array
    {
        $from = $ym . '-01';
        return [$from, date('Y-m-d', (int) strtotime($from . ' +1 month'))];
    }

    /**
     * The target that applies to one agent for one month.
     *  source 'agent'   — the office set this agent's own row
     *         'default' — no row; the company-wide agent_target_monthly
     *         'none'    — neither is set
     *
     * @return array{amount: float, bookings: int, bonus_pct: float, note: string, source: string}
     */
    public static function targetFor(int $agentId, string $ym): array
    {
        $row = null;
        if (self::ensure()) {
            try {
                $row = Database::fetch(
                    'SELECT target_amount, target_bookings, bonus_pct, note FROM agent_targets
                      WHERE agent_admin_id = :a AND month = :m',
                    ['a' => $agentId, 'm' => $ym]
                );
            } catch (Throwable $e) {
                $row = null;
            }
        }
        if ($row !== null) {
            return [
                'amount'    => round((float) $row['target_amount'], 2),
                'bookings'  => (int) $row['target_bookings'],
                'bonus_pct' => round((float) $row['bonus_pct'], 2),
                'note'      => (string) ($row['note'] ?? ''),
                'source'    => 'agent',
            ];
        }
        $def = Settings::getFloat('agent_target_monthly', 0.0);
        return [
            'amount'    => max(0.0, $def),
            'bookings'  => 0,
            'bonus_pct' => max(0.0, Settings::getFloat('agent_target_bonus_pct', 0.0)),
            'note'      => '',
            'source'    => $def > 0 ? 'default' : 'none',
        ];
    }

    /**
     * Set (or replace) one agent's target for a month. An amount and a
     * booking count of 0 together remove the row, so the agent falls back
     * to the company default.
     */
    public static function set(int $agentId, string $ym, float $amount, int $bookings, float $bonusPct, string $note, int $by): void
    {
        if ($agentId <= 0) {
            throw new RuntimeException('Choose an agent first.');
        }
        if (!self::validMonth($ym)) {
            throw new RuntimeException('Pick a month.');
        }
        if ($amount < 0 || $amount > 100000000) {
            throw new RuntimeException('The target amount must be between ₹0 and ₹10 crore.');
        }
        if ($bookings < 0 || $bookings > 100000) {
            throw new RuntimeException('The ticket target must be between 0 and 1,00,000.');
        }
        if ($bonusPct < 0 || $bonusPct > 50) {
            throw new RuntimeException('The bonus must be between 0% and 50% of the sales above the target.');
        }
        if (!self::ensure()) {
            throw new RuntimeException('The targets table could not be created — ask the developer to check the database.');
        }

        $old = self::targetFor($agentId, $ym);
        $note = Security::clean($note, 255);
        $now  = date('Y-m-d H:i:s');

        if ($amount <= 0 && $bookings <= 0) {
            Database::run('DELETE FROM agent_targets WHERE agent_admin_id = :a AND month = :m', ['a' => $agentId, 'm' => $ym]);
            Logger::audit('agent.target_clear', 'admin', (string) $agentId,
                ['month' => $ym, 'amount' => $old['amount'], 'bookings' => $old['bookings'], 'source' => $old['source']],
                ['month' => $ym, 'source' => 'default'], 'Monthly target removed — company default applies');
            return;
        }

        Database::run(
            'INSERT INTO agent_targets
                (agent_admin_id, month, target_amount, target_bookings, bonus_pct, note, set_by, created_at, updated_at)
             VALUES (:a, :m, :amt, :bk, :bp, :n, :by, :c, :u)
             ON DUPLICATE KEY UPDATE target_amount = VALUES(target_amount), target_bookings = VALUES(target_bookings),
                bonus_pct = VALUES(bonus_pct), note = VALUES(note), set_by = VALUES(set_by), updated_at = VALUES(updated_at)',
            ['a' => $agentId, 'm' => $ym, 'amt' => round($amount, 2), 'bk' => $bookings, 'bp' => round($bonusPct, 2),
             'n' => $note !== '' ? $note : null, 'by' => $by ?: null, 'c' => $now, 'u' => $now]
        );
        Logger::audit('agent.target_set', 'admin', (string) $agentId,
            ['month' => $ym, 'amount' => $old['amount'], 'bookings' => $old['bookings'], 'bonus_pct' => $old['bonus_pct'], 'source' => $old['source']],
            ['month' => $ym, 'amount' => round($amount, 2), 'bookings' => $bookings, 'bonus_pct' => round($bonusPct, 2)],
            'Monthly target set', $note);
    }

    /** Every agent's own row for a month, keyed by agent id. */
    public static function rowsForMonth(string $ym): array
    {
        if (!self::ensure()) {
            return [];
        }
        $out = [];
        try {
            foreach (Database::fetchAll('SELECT * FROM agent_targets WHERE month = :m', ['m' => $ym]) as $r) {
                $out[(int) $r['agent_admin_id']] = $r;
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /**
     * Confirmed sales of every ACTIVE agent in one month, highest first —
     * the ranking's input. Cached per request.
     *
     * @return array<int, float> agentId => confirmed sales
     */
    public static function salesBoard(string $ym): array
    {
        if (isset(self::$salesCache[$ym])) {
            return self::$salesCache[$ym];
        }
        [$from, $to] = self::window($ym);
        $out = [];
        try {
            foreach (Database::fetchAll(
                "SELECT a.id, COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS revenue
                   FROM admins a
                   LEFT JOIN bookings b ON b.sold_by_admin_id = a.id AND b.created_at >= :f AND b.created_at < :t
                  WHERE a.role = 'agent' AND a.is_active = 1
                  GROUP BY a.id
                  ORDER BY revenue DESC, a.id ASC",
                ['f' => $from, 't' => $to]
            ) as $r) {
                $out[(int) $r['id']] = round((float) $r['revenue'], 2);
            }
        } catch (Throwable $e) {
            $out = [];
        }
        return self::$salesCache[$ym] = $out;
    }

    /**
     * One agent's month: tickets sold, confirmed, cancelled and the
     * confirmed money.
     *
     * @return array{money: float, confirmed: int, cancelled: int, tickets: int}
     */
    public static function salesFor(int $agentId, string $ym): array
    {
        [$from, $to] = self::window($ym);
        try {
            $r = Database::fetch(
                "SELECT COUNT(*) AS tickets,
                        COALESCE(SUM(status = 'confirmed'), 0) AS confirmed,
                        COALESCE(SUM(status = 'cancelled'), 0) AS cancelled,
                        COALESCE(SUM(CASE WHEN status = 'confirmed' THEN total_amount ELSE 0 END), 0) AS money
                   FROM bookings
                  WHERE sold_by_admin_id = :a AND created_at >= :f AND created_at < :t",
                ['a' => $agentId, 'f' => $from, 't' => $to]
            );
        } catch (Throwable $e) {
            $r = null;
        }
        return [
            'money'     => round((float) ($r['money'] ?? 0), 2),
            'confirmed' => (int) ($r['confirmed'] ?? 0),
            'cancelled' => (int) ($r['cancelled'] ?? 0),
            'tickets'   => (int) ($r['tickets'] ?? 0),
        ];
    }

    /**
     * Where this agent stands among the active agents for the month.
     *  rank    1-based, ties share the better rank (1 + agents strictly ahead)
     *  of      active agents counted
     *  gap     ₹ to the next agent ahead (0 when first)
     *  ahead   that agent's rank, or 0
     *
     * @return array{rank: int, of: int, gap: float, ahead: int}
     */
    public static function rankFor(int $agentId, string $ym): array
    {
        $board = self::salesBoard($ym);
        if (!isset($board[$agentId])) {
            return ['rank' => 0, 'of' => count($board), 'gap' => 0.0, 'ahead' => 0];
        }
        $mine   = $board[$agentId];
        $better = array_filter($board, static fn(float $v): bool => $v > $mine + 0.009);
        $rank   = count($better) + 1;
        $gap    = 0.0;
        $ahead  = 0;
        if ($better !== []) {
            $next  = min($better);                       // the closest one above
            $gap   = round($next - $mine, 2);
            $ahead = count(array_filter($board, static fn(float $v): bool => $v > $next + 0.009)) + 1;
        }
        return ['rank' => $rank, 'of' => count($board), 'gap' => $gap, 'ahead' => $ahead];
    }

    /**
     * Everything the target widget draws for one agent and month.
     *
     * @return array<string, mixed>
     */
    public static function progress(int $agentId, ?string $ym = null): array
    {
        $ym     = ($ym !== null && self::validMonth($ym)) ? $ym : date('Y-m');
        $target = self::targetFor($agentId, $ym);
        $sales  = self::salesFor($agentId, $ym);
        [$from, $to] = self::window($ym);

        $today     = date('Y-m-d');
        $isCurrent = $ym === date('Y-m');
        $isPast    = $to <= $today;
        $daysIn    = (int) date('t', (int) strtotime($from));
        $daysLeft  = $isCurrent ? $daysIn - (int) date('j') + 1 : ($isPast ? 0 : $daysIn);

        $amt       = $target['amount'];
        $pct       = $amt > 0 ? (int) floor(min(999, $sales['money'] * 100 / $amt)) : 0;
        $remaining = $amt > 0 ? max(0.0, round($amt - $sales['money'], 2)) : 0.0;
        $bkPct     = $target['bookings'] > 0 ? (int) floor(min(999, $sales['confirmed'] * 100 / $target['bookings'])) : 0;
        $excess    = $amt > 0 ? max(0.0, $sales['money'] - $amt) : 0.0;
        $bonus     = round($excess * $target['bonus_pct'] / 100, 2);

        return [
            'month'        => $ym,
            'label'        => date('F Y', (int) strtotime($from)),
            'target'       => $target,
            'sales'        => $sales,
            'pct'          => $pct,
            'remaining'    => $remaining,
            'bk_pct'       => $bkPct,
            'bk_left'      => max(0, $target['bookings'] - $sales['confirmed']),
            'days_in'      => $daysIn,
            'days_left'    => $daysLeft,
            'per_day'      => $daysLeft > 0 && $remaining > 0 ? round($remaining / $daysLeft, 2) : 0.0,
            'is_current'   => $isCurrent,
            'is_past'      => $isPast,
            'excess'       => round($excess, 2),
            'bonus'        => $bonus,
            'bonus_posted' => self::bonusPosted($agentId, $ym),
        ];
    }

    /**
     * Badges earned this month, computed from the same figures — nothing
     * is stored, so a cancelled sale takes a badge back on the next load.
     *
     * @param array<string, mixed> $progress from progress()
     * @return array<int, array{icon: string, label: string, why: string}>
     */
    public static function badges(int $agentId, array $progress): array
    {
        $out  = [];
        $rank = self::rankFor($agentId, (string) $progress['month']);
        $s    = $progress['sales'];
        if ($rank['rank'] === 1 && $s['money'] > 0) {
            $out[] = ['icon' => '🏆', 'label' => 'Top Seller', 'why' => 'Highest confirmed sales of all agents in ' . $progress['label']];
        }
        if ((float) $progress['target']['amount'] > 0 && $progress['pct'] >= 100) {
            $out[] = ['icon' => '🎯', 'label' => '100% Target', 'why' => 'Reached the ' . inr((float) $progress['target']['amount']) . ' target'];
        }
        if ($s['tickets'] >= 5 && $s['cancelled'] === 0) {
            $out[] = ['icon' => '✨', 'label' => 'Zero Cancellation', 'why' => $s['tickets'] . ' tickets this month and not one cancelled'];
        }
        return $out;
    }

    private static function bonusRef(string $ym): string
    {
        return 'BONUS-' . $ym;
    }

    public static function bonusPosted(int $agentId, string $ym): bool
    {
        try {
            return Database::exists(
                "SELECT 1 FROM agent_ledger WHERE agent_admin_id = :a AND entry_type = 'adjustment' AND ref = :r",
                ['a' => $agentId, 'r' => self::bonusRef($ym)]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Credit the bonus for a month that has ENDED to the commission
     * account. Once per agent per month. Returns the ledger id.
     */
    public static function postBonus(int $agentId, string $ym, int $by): int
    {
        if (!self::validMonth($ym)) {
            throw new RuntimeException('Pick a month.');
        }
        $p = self::progress($agentId, $ym);
        if (!$p['is_past']) {
            throw new RuntimeException('A bonus can be posted only after the month has ended — ' . $p['label'] . ' is still running.');
        }
        if ($p['bonus_posted']) {
            throw new RuntimeException('The ' . $p['label'] . ' bonus is already in this agent\'s wallet.');
        }
        if ($p['bonus'] < 1) {
            throw new RuntimeException('No bonus is due for ' . $p['label'] . ' — sales did not pass the target, or the bonus is 0%.');
        }
        return AgentWallet::record($agentId, 'adjustment', $p['bonus'], [
            'account'   => 'commission',
            'direction' => 'credit',
            'ref'       => self::bonusRef($ym),
            'note'      => 'Target bonus ' . $p['label'] . ': ' . rtrim(rtrim(number_format((float) $p['target']['bonus_pct'], 2), '0'), '.')
                         . '% of ' . inr($p['excess']) . ' above the ' . inr((float) $p['target']['amount']) . ' target',
            'by'        => $by,
        ]);
    }
}
