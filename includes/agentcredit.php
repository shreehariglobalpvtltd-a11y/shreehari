<?php
/**
 * includes/agentcredit.php — agent credit limit + "stop selling" hold
 * (25 Nov 2026, op5-credit).
 *
 * An agent who has collected money and not settled it with the office may cut
 * new tickets only while what they owe stays under a limit. This file is the
 * ONE place that decides that; it invents no ledger of its own:
 *
 *   outstanding  = cash the agent holds (AgentWallet::netPosition()['cash'])
 *                  less only a POSITIVE commission due to them, floored at 0.
 *                  While commission is ≥ 0 this is exactly the net the statement
 *                  prints as "Agent owes company ₹X" (AgentStatement::owes). A
 *                  NEGATIVE commission balance — the office's own advance / loan
 *                  (recordAdvance / issueLoan, recovered from future commission)
 *                  or a commission_void after a payout — is NOT counted: it is
 *                  repaid from new sales, so counting it would lock the agent
 *                  out with no way back (a cash_handover is capped at cash held).
 *   limit        = admin_profiles.credit_limit for the agent (NULL = follow the
 *                  company default, 0 = no limit for this agent), else the
 *                  setting agent_credit_limit_default (0 = no limit).
 *   hold         = admin_profiles.credit_hold_at / credit_hold_reason — its OWN
 *                  pair. NOT suspended_at / suspended_reason: AgentPro::member(),
 *                  the public card verify (api/agentpro-card.php) and the website
 *                  listing (ApWeb::judge) read those as a full agreement
 *                  suspension, and a dues hold must not tell customers that.
 *
 * Refusal (agent_credit_block_on, default ON — but with no limit set and no
 * hold nothing changes): outstanding >= limit, or the office pressed
 * "Stop selling". AgentWallet::assertMaySell() asks assertRoom() at the one
 * choke point every agent sale passes (create() seller branch + counterSale():
 * SmartBook agent mode, Quick Ticket, WhatsApp desk staff_sell, Bulk tickets,
 * the paper register). Customers paying online and office / counter staff are
 * never bound — assertMaySell's existing "who is bound" rule decides that.
 *
 * Settlement: recordSettlement() writes a cash_handover through
 * AgentWallet::record() — the same row Agent 360 "Cash handover" writes — so
 * the statement, the receipt and the aging page show it. Nothing is ever
 * marked paid automatically; a settlement is a person typing what they got.
 *
 * Switches (read inline, not in the boot list):
 *   agent_credit_block_on       bool  true  refuse sales at / over the limit or on hold
 *   agent_credit_limit_default  float 0     company default limit, 0 = none
 *   agent_credit_warn_pct       int   80    banner on the agent's desk from this % of the limit
 *
 * Columns: admin_profiles.credit_limit, credit_hold_at, credit_hold_reason
 * (database/upgrade-2026-11-25-op5-credit.sql). Without them the per-agent limit
 * reads NULL (company default), nobody is on hold, and setLimit() / setHold()
 * refuse with a message; everything else keeps working.
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AgentCredit
{
    /** Settlement modes the office may pick. */
    public const MODES = ['cash' => 'Cash · नगद', 'bank' => 'Bank · बैंक', 'online' => 'Online · अनलाइन'];

    public static function blockOn(): bool
    {
        return Settings::getBool('agent_credit_block_on', true);
    }

    public static function hasLimitColumn(): bool
    {
        return AgentWallet::profileHasColumn('credit_limit');
    }

    /** The agent's own limit: null = follow the company default, 0.0 = none for this agent. */
    public static function ownLimit(int $adminId): ?float
    {
        if ($adminId <= 0 || !self::hasLimitColumn()) {
            return null;
        }
        try {
            $v = Database::scalar('SELECT credit_limit FROM admin_profiles WHERE admin_id = :a', ['a' => $adminId], null);
        } catch (Throwable $e) {
            return null;
        }
        return ($v === null || $v === '') ? null : max(0.0, round((float) $v, 2));
    }

    /** The limit that applies: own, else the company default. 0 = no limit. */
    public static function limitFor(int $adminId): float
    {
        $own = self::ownLimit($adminId);
        if ($own !== null) {
            return $own;
        }
        return max(0.0, round(Settings::getFloat('agent_credit_limit_default', 0.0), 2));
    }

    /** Pure rule: cash held less only a positive commission due (an advance / negative commission never counts). */
    public static function owedFrom(float $cash, float $commission): float
    {
        return max(0.0, round($cash - max(0.0, $commission), 2));
    }

    /** What the agent owes the company now (never negative). */
    public static function outstanding(int $adminId): float
    {
        $n = AgentWallet::netPosition($adminId);
        return self::owedFrom((float) $n['cash'], (float) $n['commission']);
    }

    public static function hasHoldColumns(): bool
    {
        return AgentWallet::profileHasColumn('credit_hold_at') && AgentWallet::profileHasColumn('credit_hold_reason');
    }

    /**
     * Every agent on a selling hold, for a list page in one read.
     *
     * @return array<int, string> admin id => reason
     */
    public static function heldMap(): array
    {
        if (!self::hasHoldColumns()) {
            return [];
        }
        $out = [];
        try {
            foreach (Database::fetchAll('SELECT admin_id, credit_hold_reason FROM admin_profiles WHERE credit_hold_at IS NOT NULL') as $r) {
                $out[(int) $r['admin_id']] = (string) ($r['credit_hold_reason'] ?? '');
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }

    /** @return array{held: bool, reason: string, since: ?string} */
    public static function hold(int $adminId): array
    {
        $none = ['held' => false, 'reason' => '', 'since' => null];
        if ($adminId <= 0 || !self::hasHoldColumns()) {
            return $none;
        }
        try {
            $p = Database::fetch('SELECT credit_hold_at, credit_hold_reason FROM admin_profiles WHERE admin_id = :a', ['a' => $adminId]);
        } catch (Throwable $e) {
            return $none;
        }
        $since  = (string) ($p['credit_hold_at'] ?? '');
        $reason = trim((string) ($p['credit_hold_reason'] ?? ''));
        $held   = $since !== '';
        return ['held' => $held, 'reason' => $held ? $reason : '', 'since' => $held ? $since : null];
    }

    /**
     * Pure rule: the refusal text for these figures, or null when the sale may go.
     * Kept free of the database so a test can pin every edge.
     */
    public static function decide(float $outstanding, float $limit, bool $held, string $holdReason = '', bool $blockOn = true): ?string
    {
        if (!$blockOn) {
            return null;
        }
        if ($held) {
            return 'तपाईंको खाता अफिसले बिक्रीका लागि रोकेको छ' . ($holdReason !== '' ? ' (' . $holdReason . ')' : '')
                . ' — पहिले अफिसमा हिसाब मिलाउनुहोस्। / The office has stopped selling on your account'
                . ($holdReason !== '' ? ' (' . $holdReason . ')' : '') . '. Settle with the office first.';
        }
        if ($limit > 0.009 && $outstanding + 0.009 >= $limit) {
            return 'तपाईंको बाँकी हिसाब ' . inr($outstanding) . ' (सीमा ' . inr($limit) . ') — पहिले अफिसमा हिसाब मिलाउनुहोस्।'
                . ' / You owe ' . inr($outstanding) . ', at your limit of ' . inr($limit) . '. Settle with the office before selling again.';
        }
        return null;
    }

    /**
     * Everything the screens show, in one read.
     *
     * @return array{limit: float, own: ?float, outstanding: float, pct: int, held: bool, holdReason: string,
     *               holdSince: ?string, blocked: bool, warn: bool, refusal: ?string, blockOn: bool, overdueDays: int}
     */
    public static function status(int $adminId): array
    {
        $limit = self::limitFor($adminId);
        $out   = self::outstanding($adminId);
        $hold  = self::hold($adminId);
        $on    = self::blockOn();
        $why   = self::decide($out, $limit, $hold['held'], $hold['reason'], $on);
        $pct   = $limit > 0.009 ? (int) min(999, floor($out * 100 / $limit)) : 0;
        $warnAt = max(1, min(100, Settings::getInt('agent_credit_warn_pct', 80)));
        return [
            'limit'       => $limit,
            'own'         => self::ownLimit($adminId),
            'outstanding' => $out,
            'pct'         => $pct,
            'held'        => $hold['held'],
            'holdReason'  => $hold['reason'],
            'holdSince'   => $hold['since'],
            'blocked'     => $why !== null,
            'warn'        => $why === null && $limit > 0.009 && $pct >= $warnAt,
            'refusal'     => $why,
            'blockOn'     => $on,
            'overdueDays' => AgentWallet::settlementOverdueDays($adminId),
        ];
    }

    /** The refusal for this agent now, or null. No "who is bound" check — the caller decided that. */
    public static function refusal(int $adminId): ?string
    {
        if ($adminId <= 0 || !self::blockOn()) {
            return null;
        }
        $hold  = self::hold($adminId);
        $limit = self::limitFor($adminId);
        if (!$hold['held'] && $limit <= 0.009) {
            return null;   // no limit, no hold: no ledger read at all
        }
        return self::decide(self::outstanding($adminId), $limit, $hold['held'], $hold['reason'], true);
    }

    /** Throw the refusal (shown to the agent as it is). */
    public static function assertRoom(int $adminId): void
    {
        $why = self::refusal($adminId);
        if ($why !== null) {
            Logger::info('Agent sale refused: credit limit / hold', ['agent' => $adminId], 'agent');
            throw new RuntimeException($why);
        }
    }

    /* ------------------------------------------------------------------
     *  Office actions — each audited
     * ---------------------------------------------------------------- */

    /** Set the agent's own limit. null = follow the company default; 0 = no limit for this agent. */
    public static function setLimit(int $adminId, ?float $limit, int $by): void
    {
        if (!self::hasLimitColumn()) {
            throw new RuntimeException('Run database/upgrade-2026-11-25-op5-credit.sql once to set a per-agent credit limit.');
        }
        if ($limit !== null && ($limit < 0 || $limit > 100000000)) {
            throw new RuntimeException('Enter a limit between ₹0 and ₹10,00,00,000.');
        }
        $old = self::ownLimit($adminId);
        $new = $limit === null ? null : round($limit, 2);
        Database::run(
            'INSERT INTO admin_profiles (admin_id, credit_limit) VALUES (:a, :l)
             ON DUPLICATE KEY UPDATE credit_limit = :l2',
            ['a' => $adminId, 'l' => $new, 'l2' => $new]
        );
        Logger::audit('agent.credit_limit', 'admin', (string) $adminId, ['credit_limit' => $old], ['credit_limit' => $new],
            'credit limit ' . ($new === null ? 'follows company default' : ($new <= 0 ? 'none' : inr($new))) . ' (by admin#' . $by . ')');
    }

    /** "Stop selling" (on) / "Resume selling" (off). Own columns — never the agreement-suspension pair. */
    public static function setHold(int $adminId, bool $on, string $reason, int $by): void
    {
        if (!self::hasHoldColumns()) {
            throw new RuntimeException('Run database/upgrade-2026-11-25-op5-credit.sql once to stop / resume an agent\'s selling.');
        }
        $reason = trim(Security::clean($reason, 200));
        $before = self::hold($adminId);
        if ($on) {
            if (mb_strlen($reason) < 3) {
                throw new RuntimeException('Write a short reason for stopping sales (at least 3 characters).');
            }
            Database::run(
                'INSERT INTO admin_profiles (admin_id, credit_hold_at, credit_hold_reason) VALUES (:a, NOW(), :r)
                 ON DUPLICATE KEY UPDATE credit_hold_at = NOW(), credit_hold_reason = :r2',
                ['a' => $adminId, 'r' => $reason, 'r2' => $reason]
            );
            Logger::audit('agent.credit_hold', 'admin', (string) $adminId, $before, ['held' => true, 'reason' => $reason],
                'selling stopped by admin#' . $by);
            return;
        }
        Database::run('UPDATE admin_profiles SET credit_hold_at = NULL, credit_hold_reason = NULL WHERE admin_id = :a', ['a' => $adminId]);
        Logger::audit('agent.credit_release', 'admin', (string) $adminId, $before, ['held' => false],
            'selling resumed by admin#' . $by . ($reason !== '' ? ' — ' . $reason : ''));
    }

    /**
     * "हिसाब क्लियर / Settlement received": the money the agent brought in, as a
     * cash_handover row through AgentWallet::record() (voucher, audit, statement,
     * aging all as before). Never more than the cash the agent holds — the
     * ledger refuses that itself.
     *
     * @return int the ledger id
     */
    public static function recordSettlement(int $adminId, float $amount, string $mode, string $ref, string $note, string $date, int $by): int
    {
        $amount = round(abs($amount), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Enter the amount received.');
        }
        if (!isset(self::MODES[$mode])) {
            throw new RuntimeException('Choose how the money came: cash, bank or online.');
        }
        $ref = trim(Security::clean($ref, 60));
        if ($mode !== 'cash' && mb_strlen($ref) < 4) {
            throw new RuntimeException('A bank / online settlement needs its reference (UTR / transaction no.).');
        }
        $date = trim($date) !== '' ? trim($date) : todayISO();
        if (!Security::isValidDate($date) || $date > todayISO() || $date < date('Y-m-d', strtotime('-60 days'))) {
            throw new RuntimeException('The date received must be today or within the last 60 days.');
        }
        $note = trim(Security::clean($note, 150));
        $text = 'Settlement received · हिसाब क्लियर (' . $mode . ($date !== todayISO() ? ', received ' . $date : '') . ')'
              . ($note !== '' ? ': ' . $note : '');

        $before = self::outstanding($adminId);
        $id = AgentWallet::record($adminId, 'cash_handover', $amount, ['note' => $text, 'ref' => $ref, 'by' => $by]);
        Logger::audit('agent.credit_settlement', 'admin', (string) $adminId, ['outstanding' => $before],
            ['outstanding' => self::outstanding($adminId), 'amount' => $amount, 'mode' => $mode, 'date' => $date, 'ledger' => $id],
            'settlement received (by admin#' . $by . ')');
        return $id;
    }

    /* ------------------------------------------------------------------
     *  The agent's own desk
     * ---------------------------------------------------------------- */

    /** Small banner for the agent's own pages: '' unless near / at the limit or on hold. */
    public static function bannerHtml(int $adminId): string
    {
        try {
            if ($adminId <= 0 || !self::blockOn()) {
                return '';
            }
            $hold = self::hold($adminId);
            if (!$hold['held'] && self::limitFor($adminId) <= 0.009) {
                return '';
            }
            $s = self::status($adminId);
            if ($s['blocked']) {
                return '<div class="flash bad" role="alert" data-credit="blocked">⛔ ' . Security::e((string) $s['refusal']) . '</div>';
            }
            if ($s['warn']) {
                return '<div class="flash warn" role="status" data-credit="warn">⚠️ बाँकी हिसाब ' . Security::e(inr($s['outstanding']))
                    . ' — सीमा ' . Security::e(inr($s['limit'])) . ' को ' . (int) $s['pct'] . '%। सीमा पुगेपछि नयाँ टिकट काट्न मिल्दैन — चाँडै अफिसमा हिसाब मिलाउनुहोस्।'
                    . ' <span class="muted">/ You owe ' . Security::e(inr($s['outstanding'])) . ' of your ' . Security::e(inr($s['limit'])) . ' limit.</span></div>';
            }
        } catch (Throwable $e) {
            return '';
        }
        return '';
    }
}
