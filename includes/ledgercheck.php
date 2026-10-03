<?php
/**
 * =====================================================================
 *  LedgerCheck — does the agent ledger still agree with the bookings?
 *  (3 Oct 2026, owner prompt: "एउटा ठाउँमा data परिवर्तन हुँदा सम्बन्धित
 *  सबै ledger, count र statement automatically update हुनुपर्छ")
 *
 *  Every agent balance, statement and payout is a SUM over agent_ledger.
 *  Those rows are WRITTEN when a booking changes (AgentWallet::accrue,
 *  voidFor, reassignSeller, syncCashDue, purgeBookingRows) — they are not
 *  recomputed. So the one way to keep "change one booking, every
 *  statement follows" honest is to re-read every booking and say exactly
 *  where a stored row no longer matches it. This class does that; the
 *  "Ledger मिलान" page (admin/ledger-check.php) shows the result and lets a
 *  super-admin apply the matching fix, one booking at a time or per kind,
 *  through the SAME AgentWallet functions a normal booking change uses.
 *
 *  What it does NOT do:
 *   - refunds and cancellations of money are the owner's manual work
 *     (3 Oct 2026). Cash held on a cancelled ticket is LISTED, never
 *     changed.
 *   - commission and cash recorded for office / counter staff is the
 *     owner's accounting choice (setting commission_agent_only = off) —
 *     shown as information, not a fault.
 *   - re-rating old commission to today's rates: a commission is frozen
 *     when it is earned.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/agentwallet.php';

final class LedgerCheck
{
    /** Kinds a person can fix from the page, with the wording the page shows. */
    public const KINDS = [
        'orphan'        => ['Rows for a booking that no longer exists', 'Remove these rows (the booking was deleted)'],
        'not_reversed'  => ['Commission still credited on a booking that did not travel', 'Reverse the commission'],
        'not_credited'  => ['Sold and paid, but no commission credited to the seller', 'Credit the commission'],
        'cash_mismatch' => ['"Cash held" does not match how the ticket was paid', 'Match cash held to the payment'],
    ];

    /** Live sale states. */
    private const LIVE = ['confirmed', 'completed'];

    /**
     * Every finding, all time (or since a date).
     *
     * @return array<string, list<array<string,mixed>>> kind => rows
     */
    public static function run(?string $since = null): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $k) {
            $out[$k] = self::find($k, $since);
        }
        return $out;
    }

    /**
     * @return list<array<string,mixed>> rows: booking_id, pnr, status, seller, stored, expected, note
     */
    public static function find(string $kind, ?string $since = null): array
    {
        $p = [];
        $sinceSql = '';
        if ($since !== null) { $sinceSql = ' AND b.created_at >= :since'; $p['since'] = $since . ' 00:00:00'; }

        switch ($kind) {
            case 'orphan':
                $rows = Database::fetchAll(
                    "SELECT l.booking_id, MAX(l.ref) AS pnr, GROUP_CONCAT(DISTINCT l.agent_admin_id) AS agents,
                            SUM(CASE WHEN l.account = 'commission' THEN l.amount ELSE 0 END) AS commission,
                            SUM(CASE WHEN l.account = 'cash' THEN l.amount ELSE 0 END) AS cash,
                            COUNT(*) AS n, MIN(l.created_at) AS at
                       FROM agent_ledger l
                       LEFT JOIN bookings b ON b.id = l.booking_id
                      WHERE l.booking_id IS NOT NULL AND b.id IS NULL" . ($since !== null ? ' AND l.created_at >= :since' : '') . "
                      GROUP BY l.booking_id ORDER BY l.booking_id",
                    $p
                );
                return array_map(static fn(array $r): array => [
                    'booking_id' => (int) $r['booking_id'], 'pnr' => (string) $r['pnr'], 'status' => 'deleted',
                    'seller'     => self::sellerName((int) explode(',', (string) $r['agents'])[0]),
                    'stored'     => 'commission ' . inr((float) $r['commission']) . ' · cash held ' . inr((float) $r['cash']),
                    'expected'   => 'no rows', 'amount' => (float) $r['commission'] + (float) $r['cash'],
                    'note'       => (int) $r['n'] . ' row(s) since ' . substr((string) $r['at'], 0, 10),
                ], $rows);

            case 'not_reversed':
                $rows = Database::fetchAll(
                    "SELECT b.id, b.pnr, b.status, x.agent, x.net
                       FROM (SELECT booking_id, MAX(agent_admin_id) AS agent, SUM(amount) AS net
                               FROM agent_ledger WHERE entry_type IN ('commission','commission_void') AND booking_id IS NOT NULL
                              GROUP BY booking_id HAVING SUM(amount) > 0) x
                       JOIN bookings b ON b.id = x.booking_id
                      WHERE b.status NOT IN ('confirmed','completed')" . $sinceSql . "
                      ORDER BY b.id",
                    $p
                );
                return array_map(static fn(array $r): array => [
                    'booking_id' => (int) $r['id'], 'pnr' => (string) $r['pnr'], 'status' => (string) $r['status'],
                    'seller' => self::sellerName((int) $r['agent']), 'stored' => 'commission ' . inr((float) $r['net']),
                    'expected' => inr(0), 'amount' => (float) $r['net'], 'note' => 'ticket is ' . $r['status'],
                ], $rows);

            case 'not_credited':
                $agentOnly = Settings::getBool('commission_agent_only', false);
                $rows = Database::fetchAll(
                    "SELECT b.id, b.pnr, b.status, b.total_amount, b.sold_by_admin_id, b.is_cod, a.role,
                            COALESCE((SELECT SUM(l.amount) FROM agent_ledger l WHERE l.booking_id = b.id AND l.entry_type IN ('commission','commission_void')), 0) AS net
                       FROM bookings b
                       JOIN admins a ON a.id = b.sold_by_admin_id
                      WHERE b.status IN ('confirmed','completed')" . ($agentOnly ? " AND a.role = 'agent'" : '') . $sinceSql . "
                     HAVING net <= 0
                      ORDER BY b.id",
                    $p
                );
                $out = [];
                foreach ($rows as $r) {
                    $bid = (int) $r['id'];
                    if (!AgentWallet::moneyInHand($bid, $r)) { continue; }   // unpaid COD: earns on collection
                    $seats = AgentWallet::seatCountFor($bid);
                    $due   = AgentWallet::commissionForBooking((float) $r['total_amount'], (int) $r['sold_by_admin_id'], $seats);
                    if ($due <= 0) { continue; }
                    $out[] = [
                        'booking_id' => $bid, 'pnr' => (string) $r['pnr'], 'status' => (string) $r['status'],
                        'seller' => self::sellerName((int) $r['sold_by_admin_id']), 'stored' => 'commission ' . inr((float) $r['net']),
                        'expected' => inr($due), 'amount' => $due, 'note' => $seats . ' seat(s)',
                    ];
                }
                return $out;

            case 'cash_mismatch':
                $rows = Database::fetchAll(
                    "SELECT b.id, b.pnr, b.status, b.total_amount, b.sold_by_admin_id, b.is_cod,
                            (SELECT p.method FROM payments p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS method,
                            c.amount AS cash_due, c.agent_admin_id AS holder
                       FROM bookings b
                       LEFT JOIN agent_ledger c ON c.booking_id = b.id AND c.entry_type = 'cash_due'
                      WHERE (c.id IS NOT NULL OR (b.sold_by_admin_id IS NOT NULL AND b.status IN ('confirmed','completed') AND b.total_amount > 0))" . $sinceSql . "
                      ORDER BY b.id",
                    $p
                );
                $agentOnly = Settings::getBool('commission_agent_only', false);
                $out = [];
                foreach ($rows as $r) {
                    $bid  = (int) $r['id'];
                    $cash = (int) $r['is_cod'] === 1 || in_array((string) $r['method'], ['cash', 'cod'], true);
                    $has  = $r['cash_due'] !== null;
                    if ($has && !$cash) {
                        $out[] = ['booking_id' => $bid, 'pnr' => (string) $r['pnr'], 'status' => (string) $r['status'],
                                  'seller' => self::sellerName((int) $r['holder']), 'stored' => 'cash held ' . inr((float) $r['cash_due']),
                                  'expected' => 'none — paid by ' . strtoupper((string) ($r['method'] ?: '?')), 'amount' => (float) $r['cash_due'], 'note' => 'not a cash sale'];
                    } elseif (!$has && $cash && in_array((string) $r['status'], self::LIVE, true) && AgentWallet::moneyInHand($bid, $r)) {
                        if ($agentOnly && !AgentWallet::isAgentRow((int) $r['sold_by_admin_id'])) { continue; }
                        $out[] = ['booking_id' => $bid, 'pnr' => (string) $r['pnr'], 'status' => (string) $r['status'],
                                  'seller' => self::sellerName((int) $r['sold_by_admin_id']), 'stored' => 'no cash held',
                                  'expected' => 'cash held ' . inr((float) $r['total_amount']), 'amount' => (float) $r['total_amount'], 'note' => 'paid in cash'];
                    }
                }
                return $out;
        }
        return [];
    }

    /**
     * Information only — the owner settles these by hand.
     *
     * @return array{cancelled_cash:array{n:int,amount:float,rows:list<array<string,mixed>>}, staff:list<array<string,mixed>>}
     */
    public static function info(): array
    {
        $cc = Database::fetchAll(
            "SELECT b.id, b.pnr, b.status, b.refund_status, l.amount, l.agent_admin_id, b.cancelled_at
               FROM agent_ledger l JOIN bookings b ON b.id = l.booking_id
              WHERE l.entry_type = 'cash_due' AND b.status IN ('cancelled','rejected','expired')
              ORDER BY b.id DESC"
        );
        $staff = Database::fetchAll(
            "SELECT a.id, a.full_name, a.username, a.role,
                    SUM(CASE WHEN l.account = 'commission' THEN l.amount ELSE 0 END) AS commission,
                    SUM(CASE WHEN l.account = 'cash' THEN l.amount ELSE 0 END) AS cash,
                    COUNT(DISTINCT l.booking_id) AS bookings
               FROM agent_ledger l JOIN admins a ON a.id = l.agent_admin_id
              WHERE a.role <> 'agent'
              GROUP BY a.id, a.full_name, a.username, a.role
              ORDER BY commission DESC"
        );
        return [
            'cancelled_cash' => [
                'n'      => count($cc),
                'amount' => array_sum(array_map(static fn(array $r): float => (float) $r['amount'], $cc)),
                'rows'   => $cc,
            ],
            'staff' => $staff,
        ];
    }

    /**
     * Apply the fix for one booking. Returns '' or why it was not applied.
     */
    public static function fix(string $kind, int $bookingId): string
    {
        if (!isset(self::KINDS[$kind])) { return 'Unknown check.'; }
        // Re-check first: only a booking still listed is touched.
        $still = array_filter(self::find($kind), static fn(array $r): bool => (int) $r['booking_id'] === $bookingId);
        if ($still === []) { return 'Already in order — nothing to change.'; }

        $b = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $bookingId]);
        switch ($kind) {
            case 'orphan':
                if ($b !== null) { return 'The booking exists — not an orphan.'; }
                AgentWallet::purgeBookingRows($bookingId, 'ledger check: booking no longer exists');
                return '';
            case 'not_reversed':
                if ($b === null) { return 'Booking not found.'; }
                AgentWallet::voidFor($b, 'ledger check: ticket is ' . $b['status']);
                break;
            case 'not_credited':
                if ($b === null) { return 'Booking not found.'; }
                AgentWallet::accrue($b);
                break;
            case 'cash_mismatch':
                if ($b === null) { return 'Booking not found.'; }
                AgentWallet::syncCashDue($b, 'ledger check');
                break;
        }
        Logger::audit('agent.ledger_check_fix', 'booking', (string) ($b['pnr'] ?? $bookingId), null, ['check' => $kind], 'Ledger मिलान: ' . self::KINDS[$kind][1]);
        $left = array_filter(self::find($kind), static fn(array $r): bool => (int) $r['booking_id'] === $bookingId);
        return $left === [] ? '' : 'Applied, but the booking still does not match — open it and check by hand.';
    }

    /** @var array<int,string> */
    private static array $names = [];

    private static function sellerName(int $adminId): string
    {
        if ($adminId <= 0) { return '—'; }
        if (!isset(self::$names[$adminId])) {
            $r = Database::fetch('SELECT full_name, username, role FROM admins WHERE id = :i', ['i' => $adminId]);
            if ($r === null) {
                self::$names[$adminId] = 'removed user #' . $adminId;
            } else {
                $code = AgentWallet::agentCodeLabel($adminId);
                self::$names[$adminId] = ($code !== '' ? $code . ' · ' : '') . (string) ($r['full_name'] ?: $r['username'])
                    . ($r['role'] !== 'agent' ? ' (' . $r['role'] . ')' : '');
            }
        }
        return self::$names[$adminId];
    }
}
