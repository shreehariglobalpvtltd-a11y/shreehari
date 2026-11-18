<?php
/**
 * includes/counterbook.php — the counter-wise DAY BOOK (19 Nov 2026, WA-6).
 *
 * Owner: "counter wise hisab - kati ticket, kati yatri, nagad kati, online
 * kati - office ko WhatsApp ma aaos". admin/counters.php already answered this
 * for the screen, inline. The same five queries are COPIED here (not moved:
 * that page belongs to another change) so the office WhatsApp report, the day
 * close card and the tests read one rule:
 *
 *   sold     - confirmed/completed bookings by confirmed_at, per desk: the code
 *              frozen on the sale, else the seller's desk today
 *   taken    - VERIFIED payments by verified_at, per desk of the person who
 *              verified (whose drawer), else the selling desk; cash + cod = नगद,
 *              everything else = अनलाइन; NPR physically taken (local_amount);
 *              cashLocal = the DRAWER in the line's money (cash + cod only:
 *              local_amount, else amount x payments.fx_rate, else x desk rate)
 *   refund   - processed refunds by cancelled_at (display only: refunds stay
 *              a manual act of the owner)
 *   pending  - COD tickets still to collect (latest payment cod_pending)
 *   + pax        - passengers on the sold bookings
 *   + commission - agent_ledger commission + commission_void in the window
 *
 * KEY: the desk code; a sale with no desk but a seller is the SELLER's own
 * line 'AG:<admin id>' (an agent or a clerk with no desk is not "online");
 * '' = a true online sale. net = cash + online - commission - refunds.
 *
 * Read-only. Never writes a booking, a payment or a ledger row.
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class CounterBook
{
    /** @return array{from:string,to:string,rows:array<string,array<string,mixed>>,totals:array<string,float|int>} */
    public static function day(string $day): array
    {
        return self::range($day, $day);
    }

    /**
     * The book for [$from, $to] (Y-m-d, inclusive, IST app clock).
     *
     * @return array{from:string,to:string,rows:array<string,array<string,mixed>>,totals:array<string,float|int>}
     */
    public static function range(string $from, string $to): array
    {
        if (!Security::isValidDate($from)) { $from = todayISO(); }
        if (!Security::isValidDate($to))   { $to = $from; }
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        $t1 = addDaysISO($to, 1);
        $w  = ['f' => $from, 't1' => $t1];

        $hasStamp = CounterDesk::stampColumn();
        $hasFx    = CounterDesk::hasColumn('bookings', 'fx_total');
        $hasLocal = CounterDesk::hasColumn('payments', 'local_amount');

        /* the same expressions as admin/counters.php:143-153 */
        $codeExpr  = $hasStamp ? "COALESCE(NULLIF(b.counter_code,''), ap.counter_code, '')" : "COALESCE(ap.counter_code, '')";
        $takenExpr = "COALESCE(NULLIF(apv.counter_code,''), " . ($hasStamp ? "NULLIF(b.counter_code,''), " : '') . "ap.counter_code, '')";
        /* no desk: the seller's own line, never mixed into "online" */
        $keyOf = static fn(string $code, int $seller): string => $code !== '' ? $code : ($seller > 0 ? 'AG:' . $seller : '');

        $rows = [];
        $row  = static function (string $key) use (&$rows): void {
            if (!isset($rows[$key])) {
                $rows[$key] = ['key' => $key, 'tickets' => 0, 'seats' => 0, 'pax' => 0, 'inr' => 0.0, 'npr' => 0.0,
                    'cash' => 0.0, 'online' => 0.0, 'takenNpr' => 0.0, 'pending' => 0.0, 'pendingN' => 0,
                    'refund' => 0.0, 'commission' => 0.0, 'stamped' => 0,
                    'cashNprKnown' => 0.0, 'cashInrBare' => 0.0, 'cashLocal' => 0.0];
            }
        };

        foreach (Database::fetchAll(
            "SELECT $codeExpr AS code, COALESCE(b.sold_by_admin_id, 0) AS seller,
                    COUNT(*) AS tickets,
                    COALESCE(SUM((SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id)), 0) AS seats,
                    COALESCE(SUM((SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id)), 0) AS pax,
                    COALESCE(SUM(b.total_amount), 0) AS inr" .
                    ($hasFx ? ", COALESCE(SUM(CASE WHEN b.fx_currency = 'NPR' THEN b.fx_total ELSE 0 END), 0) AS npr" : ', 0 AS npr') .
                    ($hasStamp ? ", SUM(CASE WHEN COALESCE(b.counter_code,'') <> '' THEN 1 ELSE 0 END) AS stamped" : ', 0 AS stamped') . "
               FROM bookings b
               LEFT JOIN admin_profiles ap ON ap.admin_id = b.sold_by_admin_id
              WHERE b.status IN ('confirmed','completed')
                AND b.confirmed_at >= :f AND b.confirmed_at < :t1
              GROUP BY code, seller", $w) as $r) {
            $k = $keyOf((string) $r['code'], (int) $r['seller']);
            $row($k);
            $rows[$k]['tickets'] += (int) $r['tickets'];
            $rows[$k]['seats']   += (int) $r['seats'];
            $rows[$k]['pax']     += (int) $r['pax'];
            $rows[$k]['inr']     += (float) $r['inr'];
            $rows[$k]['npr']     += (float) $r['npr'];
            $rows[$k]['stamped'] += (int) $r['stamped'];
        }

        /* The DRAWER in the desk's own money, cash / cod only and payment by
           payment (review fix, 19 Nov 2026): the NPR physically taken where it
           was stamped (payments.local_amount), else the rupee x the rate frozen
           on that payment, else (bare) x the desk's rate - applied below once
           the line's desk is known. takenNpr above stays "every NPR stamped",
           for display only; the day close reads cashLocal. */
        $hasPFx = $hasLocal && CounterDesk::hasColumn('payments', 'fx_rate');
        $stampedNpr = "(COALESCE(p.local_currency, '') = 'NPR' AND COALESCE(p.local_amount, 0) > 0)";   // never NULL: NOT stays a real NOT
        $drawerCols = $hasLocal
            ? ", COALESCE(SUM(CASE WHEN $stampedNpr THEN p.local_amount ELSE 0 END), 0) AS cash_npr_known"
              . ($hasPFx
                  ? ", COALESCE(SUM(CASE WHEN NOT $stampedNpr AND COALESCE(p.fx_rate, 0) > 0 THEN p.amount * p.fx_rate ELSE 0 END), 0) AS cash_npr_fx"
                    . ", COALESCE(SUM(CASE WHEN NOT $stampedNpr AND COALESCE(p.fx_rate, 0) <= 0 THEN p.amount ELSE 0 END), 0) AS cash_inr_bare"
                  : ", 0 AS cash_npr_fx, COALESCE(SUM(CASE WHEN NOT $stampedNpr THEN p.amount ELSE 0 END), 0) AS cash_inr_bare")
            : ', 0 AS cash_npr_known, 0 AS cash_npr_fx, COALESCE(SUM(p.amount), 0) AS cash_inr_bare';
        foreach (Database::fetchAll(
            "SELECT $takenExpr AS code, COALESCE(b.sold_by_admin_id, 0) AS seller, p.method,
                    COALESCE(SUM(p.amount), 0) AS inr" . $drawerCols .
                    ($hasLocal ? ", COALESCE(SUM(CASE WHEN p.local_currency = 'NPR' THEN p.local_amount ELSE 0 END), 0) AS npr" : ', 0 AS npr') . "
               FROM payments p
               JOIN bookings b ON b.id = p.booking_id
               LEFT JOIN admin_profiles ap  ON ap.admin_id  = b.sold_by_admin_id
               LEFT JOIN admin_profiles apv ON apv.admin_id = p.verified_by
              WHERE p.status = 'verified'
                AND p.verified_at >= :f AND p.verified_at < :t1
              GROUP BY code, seller, p.method", $w) as $r) {
            $k = $keyOf((string) $r['code'], (int) $r['seller']);
            $row($k);
            $m = strtolower((string) $r['method']);
            if ($m === 'cash' || $m === 'cod') {
                $rows[$k]['cash'] += (float) $r['inr'];
                $rows[$k]['cashNprKnown'] += (float) $r['cash_npr_known'] + (float) $r['cash_npr_fx'];
                $rows[$k]['cashInrBare']  += (float) $r['cash_inr_bare'];
            } else {
                $rows[$k]['online'] += (float) $r['inr'];
            }
            $rows[$k]['takenNpr'] += (float) $r['npr'];
        }

        foreach (Database::fetchAll(
            "SELECT $codeExpr AS code, COALESCE(b.sold_by_admin_id, 0) AS seller, COALESCE(SUM(b.refund_amount), 0) AS amt
               FROM bookings b
               LEFT JOIN admin_profiles ap ON ap.admin_id = b.sold_by_admin_id
              WHERE b.refund_status = 'processed'
                AND b.cancelled_at >= :f AND b.cancelled_at < :t1
              GROUP BY code, seller", $w) as $r) {
            $k = $keyOf((string) $r['code'], (int) $r['seller']);
            $row($k);
            $rows[$k]['refund'] += (float) $r['amt'];
        }

        foreach (Database::fetchAll(
            "SELECT $codeExpr AS code, COALESCE(b.sold_by_admin_id, 0) AS seller, COUNT(*) AS n, COALESCE(SUM(b.total_amount), 0) AS amt
               FROM bookings b
               LEFT JOIN admin_profiles ap ON ap.admin_id = b.sold_by_admin_id
              WHERE b.status IN ('confirmed','completed')
                AND b.confirmed_at >= :f AND b.confirmed_at < :t1
                AND (SELECT p2.status FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1) = 'cod_pending'
              GROUP BY code, seller", $w) as $r) {
            $k = $keyOf((string) $r['code'], (int) $r['seller']);
            $row($k);
            $rows[$k]['pending']  += (float) $r['amt'];
            $rows[$k]['pendingN'] += (int) $r['n'];
        }

        try {
            $cCode = $hasStamp ? "COALESCE(NULLIF(b.counter_code,''), ap.counter_code, '')" : "COALESCE(ap.counter_code, '')";
            foreach (Database::fetchAll(
                "SELECT $cCode AS code, l.agent_admin_id AS seller, COALESCE(SUM(l.amount), 0) AS amt
                   FROM agent_ledger l
                   LEFT JOIN bookings b ON b.id = l.booking_id
                   LEFT JOIN admin_profiles ap ON ap.admin_id = l.agent_admin_id
                  WHERE l.entry_type IN ('commission','commission_void')
                    AND l.created_at >= :f AND l.created_at < :t1
                  GROUP BY code, seller", $w) as $r) {
                $k = $keyOf((string) $r['code'], (int) $r['seller']);
                $row($k);
                $rows[$k]['commission'] += (float) $r['amt'];
            }
        } catch (Throwable $e) {
            // no ledger table on a bare database: commission reads 0
        }

        /* labels, currency, net */
        $names = [];
        $ids = [];
        foreach (array_keys($rows) as $k) {
            if (str_starts_with((string) $k, 'AG:')) { $ids[] = (int) substr((string) $k, 3); }
        }
        if ($ids !== []) {
            foreach (Database::fetchAll('SELECT id, full_name, username, role FROM admins WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')') as $a) {
                $names[(int) $a['id']] = $a;
            }
        }
        $desks = CounterDesk::all();
        foreach ($rows as $k => &$r) {
            $k = (string) $k;
            $r['currency'] = 'INR';
            $r['rate']     = 1.0;
            if ($k === '') {
                $r['kind'] = 'online';
                $r['label'] = 'अनलाइन · Online';
            } elseif (str_starts_with($k, 'AG:')) {
                $id = (int) substr($k, 3);
                $a  = $names[$id] ?? null;
                $code = '';
                if (class_exists('AgentWallet') && method_exists('AgentWallet', 'agentCodeLabel')) {
                    try { $code = (string) AgentWallet::agentCodeLabel($id); } catch (Throwable $e) { $code = ''; }
                }
                $r['kind']  = 'seller';
                $r['role']  = (string) ($a['role'] ?? '');
                $r['label'] = trim(($code !== '' ? $code . ' ' : '') . (string) (($a['full_name'] ?? '') ?: ($a['username'] ?? ('#' . $id))));
                $r['adminId'] = $id;
            } else {
                $r['kind']     = 'desk';
                $r['label']    = (string) ($desks[$k]['name'] ?? $k);
                $r['currency'] = CounterDesk::currency($k);
                $r['rate']     = CounterDesk::rate($k);
            }
            /* the drawer in the line's own money: cash + cod only, payment by payment */
            $r['cashLocal'] = $r['currency'] === 'INR'
                ? round($r['cash'], 2)
                : (float) round($r['cashNprKnown'] + $r['cashInrBare'] * (float) $r['rate'], 2);
            unset($r['cashNprKnown'], $r['cashInrBare']);
            $r['commission'] = round($r['commission'], 2);
            $r['net'] = round($r['cash'] + $r['online'] - $r['commission'] - $r['refund'], 2);
        }
        unset($r);

        /* desks first (in the office's order), then sellers, then online */
        $order = array_flip(array_keys($desks));
        uksort($rows, static function ($a, $b) use ($order): int {
            $ra = $a === '' ? 2 : (str_starts_with((string) $a, 'AG:') ? 1 : 0);
            $rb = $b === '' ? 2 : (str_starts_with((string) $b, 'AG:') ? 1 : 0);
            if ($ra !== $rb) { return $ra <=> $rb; }
            return ($order[$a] ?? 999) <=> ($order[$b] ?? 999) ?: strcmp((string) $a, (string) $b);
        });

        $tot = ['tickets' => 0, 'seats' => 0, 'pax' => 0, 'inr' => 0.0, 'cash' => 0.0, 'online' => 0.0,
                'takenNpr' => 0.0, 'pending' => 0.0, 'pendingN' => 0, 'refund' => 0.0, 'commission' => 0.0, 'net' => 0.0];
        foreach ($rows as $r) {
            foreach ($tot as $f => $_) { $tot[$f] += $r[$f]; }
        }
        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $tot];
    }

    /**
     * The register figures for ONE line of the book (a desk code, 'AG:<id>' or ''),
     * zeros when it sold / took nothing that day.
     *
     * @return array<string,mixed>
     */
    public static function line(string $day, string $key): array
    {
        $b = self::day($day);
        return $b['rows'][$key] ?? ['key' => $key, 'tickets' => 0, 'seats' => 0, 'pax' => 0, 'inr' => 0.0, 'npr' => 0.0,
            'cash' => 0.0, 'online' => 0.0, 'takenNpr' => 0.0, 'cashLocal' => 0.0, 'pending' => 0.0, 'pendingN' => 0, 'refund' => 0.0,
            'commission' => 0.0, 'net' => 0.0, 'currency' => str_starts_with($key, 'AG:') || $key === '' ? 'INR' : CounterDesk::currency($key),
            'rate' => $key !== '' && !str_starts_with($key, 'AG:') ? CounterDesk::rate($key) : 1.0, 'kind' => $key === '' ? 'online' : (str_starts_with($key, 'AG:') ? 'seller' : 'desk'),
            'label' => $key];
    }
}
