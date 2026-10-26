<?php
/**
 * =====================================================================
 *  CustomerBook — one place that answers "who is this customer and what
 *  did they do with us" (owner prompt, 3 Oct 2026: Customer Master,
 *  Customer Ledger, Customer 360, global search, duplicate customers).
 *
 *  WHY A NEW FILE AND NOT MORE COPIES OF THE SAME SQL
 *  --------------------------------------------------
 *  Before this, four screens each carried their own query over the same
 *  bookings: customers.php (register), customer-view.php (history),
 *  search.php (global search) and ReportPdf::customerReport(). Each counted
 *  "spend" and "trips" a little differently. The owner's rule is Excel-like:
 *  change one booking and every count that includes it must move. The only
 *  way to keep that true is to compute every total LIVE from the bookings
 *  tables, through ONE filter builder, and never store a total a later edit
 *  could leave stale. ledger() / totals() below are that one builder; the
 *  ledger page, the 360 view, its exports and the duplicate finder all call
 *  it.
 *
 *  IDENTITY
 *  --------
 *  A customer is a MOBILE NUMBER (normalisePhone(): the 10 digits every
 *  channel agrees on) plus the bookings made under it — never a name: the
 *  live book has 140+ different travellers under the desk's 0000000000 and
 *  many "Ram"s on unrelated numbers. user_profiles (the GemVault) is the
 *  master row for that number; merged_into links a second number of the
 *  SAME person (a typo, or a changed SIM) to the main one. Bookings are
 *  never rewritten — the ticket keeps the number and the names that were
 *  typed for it, which is what "historical ticket data must not be
 *  overwritten" means in practice. History is read across the linked
 *  numbers instead.
 *
 *  Placeholder numbers (0000000000, 1111111111, short strings) and numbers
 *  belonging to our own staff / agents are NOT customers: they are shown as
 *  such and kept out of merges and duplicate suggestions.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/gemvault.php';

final class CustomerBook
{
    /** Booking states that count as a sale (money the customer spent with us). */
    public const SOLD = ['confirmed', 'completed'];

    /** Payment filter values the ledger understands. */
    public const PAY_FILTERS = [
        'verified'    => 'Paid (verified)',
        'pending'     => 'Payment pending',
        'cod_pending' => 'Cash on boarding',
        'rejected'    => 'Payment rejected',
        'refunded'    => 'Refunded payment',
        'none'        => 'No payment record',
    ];

    /** @var array<string,string>|null phone => "Staff · Name (role)" */
    private static ?array $staff = null;

    /** @var array<string,bool> */
    private static array $cols = [];

    /* ------------------------------------------------------------------
     *  Identity helpers
     * ---------------------------------------------------------------- */

    public static function norm(string $raw): string
    {
        return normalisePhone($raw);
    }

    /** A number a customer can actually be reached on (and so can BE a customer). */
    public static function isRealPhone(string $phone): bool
    {
        return !GemVault::isPlaceholder($phone);
    }

    /**
     * Numbers that belong to our own people (admins table: owner, managers,
     * counter staff, agents). A booking under one of these was typed with
     * the seller's number instead of the passenger's — it is not that
     * person's travel history.
     *
     * @return array<string,string> normalised phone => label
     */
    public static function staffPhones(): array
    {
        if (self::$staff !== null) {
            return self::$staff;
        }
        $out = [];
        try {
            $rows = Database::fetchAll("SELECT id, username, full_name, role, phone FROM admins WHERE phone IS NOT NULL AND phone <> ''");
            foreach ($rows as $r) {
                $p = normalisePhone((string) $r['phone']);
                if ($p === '' || strlen($p) < 8) { continue; }
                $who  = trim((string) ($r['full_name'] ?: $r['username']));
                $role = (string) $r['role'];
                $code = $role === 'agent' && class_exists('AgentWallet') ? AgentWallet::agentCodeLabel((int) $r['id']) : '';
                $out[$p] = ($role === 'agent' ? 'Agent' : 'Staff') . ' · ' . $who . ($code !== '' ? ' (' . $code . ')' : ' (' . $role . ')');
            }
        } catch (Throwable $e) {
            Logger::warning('staff phone map failed', ['error' => $e->getMessage()], 'customer');
        }
        return self::$staff = $out;
    }

    /** '' for a customer number, otherwise why it is not one. */
    public static function nonCustomerReason(string $phone): string
    {
        $p = normalisePhone($phone);
        if (!self::isRealPhone($p)) {
            return $p === '' ? 'No number' : 'Placeholder / incomplete number';
        }
        return self::staffPhones()[$p] ?? '';
    }

    /** Does user_profiles have this column yet (3 Oct 2026 migration)? */
    public static function hasCol(string $col): bool
    {
        if (!array_key_exists($col, self::$cols)) {
            try {
                self::$cols[$col] = Database::fetch('SHOW COLUMNS FROM user_profiles LIKE ' . Database::pdo()->quote($col)) !== null;
            } catch (Throwable $e) {
                self::$cols[$col] = false;
            }
        }
        return self::$cols[$col];
    }

    public static function customerIdLabel(?array $profile): string
    {
        $n = (int) ($profile['customer_no'] ?? 0);
        return $n > 0 ? 'CUS-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT) : '';
    }

    /** The main number this one is linked to (itself when not merged). */
    public static function canonical(string $phone): string
    {
        $p = normalisePhone($phone);
        if ($p === '') { return ''; }
        $into = (string) Database::scalar('SELECT merged_into FROM user_profiles WHERE phone = :p LIMIT 1', ['p' => $p], '');
        return ($into !== '' && $into !== $p) ? $into : $p;
    }

    /**
     * Every number whose bookings are this customer's history: the main
     * number first, then the ones the office linked to it.
     *
     * @return list<string>
     */
    public static function linkedPhones(string $phone): array
    {
        $main = self::canonical($phone);
        if ($main === '') { return []; }
        $out = [$main];
        foreach (Database::fetchAll('SELECT phone FROM user_profiles WHERE merged_into = :m ORDER BY phone', ['m' => $main]) as $r) {
            $p = (string) $r['phone'];
            if ($p !== '' && !in_array($p, $out, true)) { $out[] = $p; }
        }
        return $out;
    }

    /** Create the master row for a real number if it has none yet. Never throws. */
    public static function ensureProfile(string $phone): ?array
    {
        $p = normalisePhone($phone);
        if (!self::isRealPhone($p)) { return null; }
        try {
            $row = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p LIMIT 1', ['p' => $p]);
            if ($row === null) {
                // enrich() creates the row and learns the name from confirmed
                // tickets; a number with none gets an empty shell. It reads
                // QuickTicket::directionOf(), which admin pages do not load.
                require_once __DIR__ . '/quickticket.php';
                GemVault::enrich($p);
                Database::query(
                    'INSERT IGNORE INTO user_profiles (phone, first_seen_at, last_seen_at) VALUES (:p, :n, :n2)',
                    ['p' => $p, 'n' => date('Y-m-d H:i:s'), 'n2' => date('Y-m-d H:i:s')]
                );
                $row = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p LIMIT 1', ['p' => $p]);
            }
            return $row;
        } catch (Throwable $e) {
            Logger::warning('customer profile create failed', ['error' => $e->getMessage()], 'customer');
            return null;
        }
    }

    /* ------------------------------------------------------------------
     *  The ledger: one filter builder, every screen
     * ---------------------------------------------------------------- */

    /**
     * Normalise raw GET input into the filter array ledger() understands.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function filtersFrom(array $in): array
    {
        $s = static fn(string $k, int $max = 80): string => trim(Security::clean($in[$k] ?? '', $max));
        $date = static function (string $v): string { return Security::isValidDate($v) ? $v : ''; };

        $f = [
            'date_mode' => ($in['date_mode'] ?? '') === 'booking' ? 'booking' : 'journey',
            'from'      => $date($s('from', 10)),
            'to'        => $date($s('to', 10)),
            'q'         => $s('q', 60),
            'name'      => $s('name', 80),
            'mobile'    => preg_replace('/\D/', '', $s('mobile', 20)) ?? '',
            'agent'     => (int) ($in['agent'] ?? 0),
            'source'    => in_array(($in['source'] ?? ''), ['web', 'app', 'counter', 'agent', 'admin'], true) ? (string) $in['source'] : '',
            'route'     => (int) ($in['route'] ?? 0),
            'bus'       => (int) ($in['bus'] ?? 0),
            'pickup'    => $s('pickup', 80),
            'drop'      => $s('drop', 80),
            'status'    => in_array(($in['status'] ?? ''), ['pending', 'confirmed', 'completed', 'cancelled', 'rejected', 'expired', 'refunded', 'active'], true) ? (string) $in['status'] : '',
            'pay'       => array_key_exists((string) ($in['pay'] ?? ''), self::PAY_FILTERS) ? (string) $in['pay'] : '',
            'phones'    => [],
        ];

        // Quick ranges (Today / Yesterday / last 7 / this month) fill from/to.
        $today = date('Y-m-d');
        switch ((string) ($in['range'] ?? '')) {
            case 'today':     $f['from'] = $f['to'] = $today; break;
            case 'yesterday': $f['from'] = $f['to'] = date('Y-m-d', strtotime('-1 day')); break;
            case 'tomorrow':  $f['from'] = $f['to'] = date('Y-m-d', strtotime('+1 day')); break;
            case '7d':        $f['from'] = date('Y-m-d', strtotime('-6 days')); $f['to'] = $today; break;
            case 'month':     $f['from'] = date('Y-m-01'); $f['to'] = date('Y-m-t'); break;
        }
        if ($f['from'] !== '' && $f['to'] !== '' && $f['from'] > $f['to']) {
            [$f['from'], $f['to']] = [$f['to'], $f['from']];
        }
        // ?phone= on the ledger = this customer's whole history (linked numbers too).
        $phone = normalisePhone((string) ($in['phone'] ?? ''));
        if ($phone !== '' && strlen($phone) >= 6) {
            $f['phones'] = self::linkedPhones($phone) ?: [$phone];
        }
        return $f;
    }

    /**
     * WHERE clause for the filters. Every placeholder is unique — PDO here
     * runs native prepares, which refuse a named placeholder used twice.
     *
     * @param array<string,mixed> $f
     * @param array<string,mixed> $p filled with the bound values
     */
    private static function where(array $f, array &$p, ?int $scopeId): string
    {
        $w = ['1=1'];
        if ($scopeId !== null) {
            $w[] = 'b.sold_by_admin_id = :scope';
            $p['scope'] = $scopeId;
        }
        if (!empty($f['phones'])) {
            $ph = [];
            foreach (array_values($f['phones']) as $i => $num) { $ph[] = ':ph' . $i; $p['ph' . $i] = (string) $num; }
            $w[] = 'b.contact_phone IN (' . implode(',', $ph) . ')';
        }
        if (($f['from'] ?? '') !== '' || ($f['to'] ?? '') !== '') {
            if (($f['date_mode'] ?? 'journey') === 'booking') {
                if ($f['from'] !== '') { $w[] = 'b.created_at >= :dfrom'; $p['dfrom'] = $f['from'] . ' 00:00:00'; }
                if ($f['to'] !== '')   { $w[] = 'b.created_at < :dto';    $p['dto']   = date('Y-m-d', strtotime($f['to'] . ' +1 day')) . ' 00:00:00'; }
            } else {
                if ($f['from'] !== '') { $w[] = 'bl.travel_date >= :dfrom'; $p['dfrom'] = $f['from']; }
                if ($f['to'] !== '')   { $w[] = 'bl.travel_date <= :dto';   $p['dto']   = $f['to']; }
            }
        }
        if (($f['mobile'] ?? '') !== '') {
            $w[] = 'b.contact_phone LIKE :mob';
            $p['mob'] = '%' . $f['mobile'] . '%';
        }
        if (($f['name'] ?? '') !== '') {
            $w[] = '(EXISTS (SELECT 1 FROM booking_passengers bpn WHERE bpn.booking_id = b.id AND bpn.full_name LIKE :nm1)
                     OR EXISTS (SELECT 1 FROM user_profiles upn WHERE upn.phone = b.contact_phone AND upn.full_name LIKE :nm2))';
            $p['nm1'] = '%' . $f['name'] . '%';
            $p['nm2'] = '%' . $f['name'] . '%';
        }
        if (($f['q'] ?? '') !== '') {
            $q = (string) $f['q'];
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $arms = ['b.pnr LIKE :qq1', 'EXISTS (SELECT 1 FROM booking_passengers bpq WHERE bpq.booking_id = b.id AND bpq.full_name LIKE :qq2)',
                     'EXISTS (SELECT 1 FROM tickets tq WHERE tq.booking_id = b.id AND tq.ticket_number LIKE :qq3)'];
            $p['qq1'] = '%' . $q . '%'; $p['qq2'] = '%' . $q . '%'; $p['qq3'] = '%' . $q . '%';
            if (strlen($digits) >= 4) {
                $arms[] = 'b.contact_phone LIKE :qq4';
                $p['qq4'] = '%' . $digits . '%';
            }
            $w[] = '(' . implode(' OR ', $arms) . ')';
        }
        if ((int) ($f['agent'] ?? 0) > 0) {
            $w[] = 'b.sold_by_admin_id = :agent';
            $p['agent'] = (int) $f['agent'];
        }
        if (($f['source'] ?? '') !== '') {
            $w[] = 'b.source = :src';
            $p['src'] = $f['source'];
        }
        if ((int) ($f['route'] ?? 0) > 0) {
            $w[] = 's.route_id = :route';
            $p['route'] = (int) $f['route'];
        }
        if ((int) ($f['bus'] ?? 0) > 0) {
            $w[] = 's.bus_id = :bus';
            $p['bus'] = (int) $f['bus'];
        }
        if (($f['pickup'] ?? '') !== '') {
            $w[] = 'bl.boarding_stop LIKE :pick';
            $p['pick'] = '%' . $f['pickup'] . '%';
        }
        if (($f['drop'] ?? '') !== '') {
            $w[] = '(bl.drop_stop LIKE :drp1 OR (COALESCE(bl.drop_stop, \'\') = \'\' AND r.to_city LIKE :drp2))';
            $p['drp1'] = '%' . $f['drop'] . '%';
            $p['drp2'] = '%' . $f['drop'] . '%';
        }
        switch ((string) ($f['status'] ?? '')) {
            case '': break;
            case 'refunded':
                $w[] = "b.refund_status = 'processed'";
                break;
            case 'active':
                $w[] = "b.status IN ('confirmed','completed','pending')";
                break;
            default:
                $w[] = 'b.status = :st';
                $p['st'] = $f['status'];
        }
        if (($f['pay'] ?? '') !== '') {
            if ($f['pay'] === 'none') {
                $w[] = 'NOT EXISTS (SELECT 1 FROM payments pn WHERE pn.booking_id = b.id)';
            } else {
                $w[] = '(SELECT pz.status FROM payments pz WHERE pz.booking_id = b.id ORDER BY pz.id DESC LIMIT 1) = :pay';
                $p['pay'] = $f['pay'];
            }
        }
        return implode(' AND ', $w);
    }

    private const FROM = "
        FROM bookings b
        LEFT JOIN booking_legs bl ON bl.id = (SELECT bl2.id FROM booking_legs bl2 WHERE bl2.booking_id = b.id ORDER BY bl2.leg_type = 'outbound' DESC, bl2.id LIMIT 1)
        LEFT JOIN schedules s ON s.id = bl.schedule_id
        LEFT JOIN routes r    ON r.id = s.route_id
        LEFT JOIN buses bu    ON bu.id = s.bus_id
        LEFT JOIN admins ad   ON ad.id = b.sold_by_admin_id";

    /**
     * One row per booking, newest journey first (or newest booking first in
     * booking-date mode).
     *
     * @param array<string,mixed> $f filtersFrom() output
     * @return list<array<string,mixed>>
     */
    public static function ledger(array $f, int $limit = 100, int $offset = 0, ?int $scopeId = null): array
    {
        $p = [];
        $where = self::where($f, $p, $scopeId);
        $order = ($f['date_mode'] ?? 'journey') === 'booking'
            ? 'b.id DESC'
            : 'bl.travel_date DESC, b.id DESC';
        $rows = Database::fetchAll(
            "SELECT b.id, b.pnr, b.status, b.source, b.total_amount, b.refund_amount, b.refund_status,
                    b.contact_phone, b.contact_country_code, b.created_at, b.cancelled_at, b.sold_by_admin_id,
                    b.booking_mode, b.is_cod, b.currency, b.cancel_reason,
                    bl.travel_date, bl.boarding_stop, bl.drop_stop, bl.leg_type,
                    s.route_id, s.bus_id, r.from_city, r.to_city, r.route_code, bu.bus_number,
                    COALESCE(NULLIF(bu.coach_type, ''), 'sleeper') AS coach_type,
                    ad.full_name AS seller_name, ad.username AS seller_user, ad.role AS seller_role,
                    (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id) AS pax,
                    (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ') FROM booking_seats bs WHERE bs.booking_id = b.id) AS seats,
                    (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS lead_name,
                    (SELECT py.status FROM payments py WHERE py.booking_id = b.id ORDER BY py.id DESC LIMIT 1) AS pay_status,
                    (SELECT py.method FROM payments py WHERE py.booking_id = b.id ORDER BY py.id DESC LIMIT 1) AS pay_method,
                    (SELECT COALESCE(SUM(pv.amount), 0) FROM payments pv WHERE pv.booking_id = b.id AND pv.status = 'verified') AS paid_amount,
                    (SELECT t.ticket_number FROM tickets t WHERE t.booking_id = b.id ORDER BY t.id DESC LIMIT 1) AS ticket_no,
                    (SELECT COALESCE(SUM(al.amount), 0) FROM agent_ledger al WHERE al.booking_id = b.id AND al.entry_type IN ('commission','commission_void')) AS commission
            " . self::FROM . "
            WHERE " . $where . "
            ORDER BY " . $order . "
            LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $p
        );
        foreach ($rows as &$r) {
            $r['seller_label'] = self::sellerLabel($r);
            $r['pay_label']    = self::payLabel($r);
            $r['seat_labels']  = self::seatLabels($r);
            $r['from_label']   = self::stopLabel((string) ($r['boarding_stop'] ?? ''), (string) ($r['from_city'] ?? ''));
            $r['to_label']     = self::stopLabel((string) ($r['drop_stop'] ?? ''), (string) ($r['to_city'] ?? ''));
        }
        unset($r);
        return $rows;
    }

    /**
     * Totals over EXACTLY the rows ledger() would return (all pages).
     *
     * @param array<string,mixed> $f
     * @return array{bookings:int,pax:int,seats:int,sold:int,fare:float,paid:float,cancelled:int,refunds:float,customers:int,commission:float}
     */
    public static function totals(array $f, ?int $scopeId = null): array
    {
        $p = [];
        $where = self::where($f, $p, $scopeId);
        $row = Database::fetch(
            "SELECT COUNT(*) AS bookings,
                    COUNT(DISTINCT b.contact_phone) AS customers,
                    COALESCE(SUM((SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id)), 0) AS pax,
                    COALESCE(SUM((SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id)), 0) AS seats,
                    COALESCE(SUM(b.status IN ('confirmed','completed')), 0) AS sold,
                    COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount ELSE 0 END), 0) AS fare,
                    COALESCE(SUM((SELECT COALESCE(SUM(pv.amount), 0) FROM payments pv WHERE pv.booking_id = b.id AND pv.status = 'verified')), 0) AS paid,
                    COALESCE(SUM(b.status = 'cancelled'), 0) AS cancelled,
                    COALESCE(SUM(CASE WHEN b.refund_status = 'processed' THEN b.refund_amount ELSE 0 END), 0) AS refunds,
                    COALESCE(SUM((SELECT COALESCE(SUM(al.amount), 0) FROM agent_ledger al WHERE al.booking_id = b.id AND al.entry_type IN ('commission','commission_void'))), 0) AS commission
            " . self::FROM . "
            WHERE " . $where,
            $p
        ) ?? [];
        return [
            'bookings'  => (int) ($row['bookings'] ?? 0),
            'customers' => (int) ($row['customers'] ?? 0),
            'pax'       => (int) ($row['pax'] ?? 0),
            'seats'     => (int) ($row['seats'] ?? 0),
            'sold'      => (int) ($row['sold'] ?? 0),
            'fare'      => (float) ($row['fare'] ?? 0),
            'paid'      => (float) ($row['paid'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
            'refunds'   => (float) ($row['refunds'] ?? 0),
            // Net agent commission on these bookings, read from the same
            // ledger rows every agent statement adds up (3 Oct 2026).
            'commission' => (float) ($row['commission'] ?? 0),
        ];
    }

    /** Who sold it, in one short label: "SHG-0005 · Ram", "Counter · Sita", "Online". */
    public static function sellerLabel(array $r): string
    {
        $id = (int) ($r['sold_by_admin_id'] ?? 0);
        $name = trim((string) (($r['seller_name'] ?? '') ?: ($r['seller_user'] ?? '')));
        if ($id > 0) {
            $code = class_exists('AgentWallet') ? AgentWallet::agentCodeLabel($id) : '';
            $role = strtolower((string) ($r['seller_role'] ?? ''));
            if ($code !== '') { return $code . ($name !== '' ? ' · ' . $name : ''); }
            if ($role === 'agent') { return 'Agent · ' . ($name !== '' ? $name : '#' . $id); }
            return ($role === 'counter' ? 'Counter' : 'Office') . ($name !== '' ? ' · ' . $name : '');
        }
        switch ((string) ($r['source'] ?? '')) {
            case 'counter': return 'Counter';
            case 'admin':   return 'Office';
            case 'agent':   return 'Agent';
            default:        return 'Online (customer)';
        }
    }

    public static function payLabel(array $r): string
    {
        $st = (string) ($r['pay_status'] ?? '');
        $m  = strtoupper((string) ($r['pay_method'] ?? ''));
        if ($st === '') {
            return !empty($r['is_cod']) ? 'COD' : '—';
        }
        $map = ['verified' => 'Paid', 'pending' => 'Pending', 'cod_pending' => 'COD due', 'rejected' => 'Rejected', 'refunded' => 'Refunded'];
        return ($map[$st] ?? ucfirst($st)) . ($m !== '' ? ' · ' . $m : '');
    }

    public static function seatLabels(array $r): string
    {
        $seats = trim((string) ($r['seats'] ?? ''));
        if ($seats === '') { return ''; }
        try {
            return Seats::displayLabels(explode(' ', $seats), (string) ($r['coach_type'] ?? 'sleeper'), (string) (($r['booking_mode'] ?? '') ?: 'sharing'), ', ');
        } catch (Throwable $e) {
            return $seats;
        }
    }

    private static function stopLabel(string $stop, string $fallback): string
    {
        $stop = trim($stop);
        if ($stop === '') { return $fallback; }
        try {
            $d = Boarding::stopDisplay($stop, $fallback);
            $name = trim((string) ($d['name'] ?? $d['label'] ?? ''));
            return $name !== '' ? $name : $stop;
        } catch (Throwable $e) {
            return $stop;
        }
    }

    /* ------------------------------------------------------------------
     *  The 360 view
     * ---------------------------------------------------------------- */

    /**
     * Everything the profile page shows, computed live.
     *
     * @return array<string,mixed>
     */
    public static function profile360(string $phone, ?int $scopeId = null): array
    {
        $main    = self::canonical($phone);
        $phones  = self::linkedPhones($main) ?: [$main];
        $profile = self::isRealPhone($main) ? self::ensureProfile($main) : null;
        $user    = Database::fetch('SELECT * FROM users WHERE phone = :p LIMIT 1', ['p' => $main]);

        $f = ['phones' => $phones, 'date_mode' => 'booking'];
        $rows = self::ledger($f, 1000, 0, $scopeId);

        $sum = ['bookings' => count($rows), 'sold' => 0, 'pax' => 0, 'seats' => 0, 'spend' => 0.0, 'paid' => 0.0,
                'cancelled' => 0, 'refunds' => 0.0, 'refund_pending' => 0];
        $first = null; $last = null; $lastJourney = null; $nextJourney = null;
        $routes = []; $pick = []; $drop = []; $sources = []; $sellers = [];
        $today = date('Y-m-d');
        foreach ($rows as $r) {
            $sold = in_array((string) $r['status'], self::SOLD, true);
            $created = (string) $r['created_at'];
            if ($first === null || $created < $first) { $first = $created; }
            if ($last === null || $created > $last) { $last = $created; }
            if ((string) $r['status'] === 'cancelled') { $sum['cancelled']++; }
            if ((string) $r['refund_status'] === 'processed') { $sum['refunds'] += (float) $r['refund_amount']; }
            if ((string) $r['refund_status'] === 'pending') { $sum['refund_pending']++; }
            $sum['paid'] += (float) $r['paid_amount'];
            if (!$sold) { continue; }
            $sum['sold']++;
            $sum['pax']   += (int) $r['pax'];
            $sum['seats'] += count(array_filter(explode(' ', (string) $r['seats'])));
            $sum['spend'] += (float) $r['total_amount'];
            $td = (string) ($r['travel_date'] ?? '');
            if ($td !== '' && $td <= $today && ($lastJourney === null || $td > $lastJourney)) { $lastJourney = $td; }
            if ($td !== '' && $td > $today && ($nextJourney === null || $td < $nextJourney)) { $nextJourney = $td; }
            $rk = trim(($r['from_city'] ?? '') . ' → ' . ($r['to_city'] ?? ''));
            if ($rk !== '→') { $routes[$rk] = ($routes[$rk] ?? 0) + 1; }
            if (($r['from_label'] ?? '') !== '') { $pick[$r['from_label']] = ($pick[$r['from_label']] ?? 0) + 1; }
            if (($r['to_label'] ?? '') !== '')   { $drop[$r['to_label']]   = ($drop[$r['to_label']] ?? 0) + 1; }
            $src = self::sourceWord((string) $r['source'], (int) $r['sold_by_admin_id'], (string) ($r['seller_role'] ?? ''));
            $sources[$src] = ($sources[$src] ?? 0) + 1;
            $sl = (string) $r['seller_label'];
            if (!isset($sellers[$sl])) { $sellers[$sl] = ['label' => $sl, 'bookings' => 0, 'pax' => 0, 'amount' => 0.0, 'agent_id' => (int) $r['sold_by_admin_id']]; }
            $sellers[$sl]['bookings']++;
            $sellers[$sl]['pax']    += (int) $r['pax'];
            $sellers[$sl]['amount'] += (float) $r['total_amount'];
        }
        arsort($routes); arsort($pick); arsort($drop); arsort($sources);
        uasort($sellers, static fn(array $a, array $b): int => $b['bookings'] <=> $a['bookings']);

        // Name: the office's spelling first, then the account, then the latest ticket.
        $name = trim((string) ($profile['full_name'] ?? ''));
        if ($name === '') { $name = trim((string) ($user['full_name'] ?? '')); }
        if ($name === '' && $rows !== []) { $name = trim((string) ($rows[0]['lead_name'] ?? '')); }

        return [
            'phone'        => $main,
            'phones'       => $phones,
            'profile'      => $profile,
            'user'         => $user,
            'name'         => $name,
            'customer_id'  => self::customerIdLabel($profile),
            'non_customer' => self::nonCustomerReason($main),
            'rows'         => $rows,
            'sum'          => $sum,
            'first'        => $first,
            'last'         => $last,
            'last_journey' => $lastJourney,
            'next_journey' => $nextJourney,
            'routes'       => $routes,
            'pickup'       => (string) (($profile['preferred_boarding'] ?? '') !== '' ? self::stopLabel((string) $profile['preferred_boarding'], '') : (array_key_first($pick) ?? '')),
            'drop'         => (string) (($profile['preferred_drop'] ?? '') !== '' ? $profile['preferred_drop'] : (array_key_first($drop) ?? '')),
            'source'       => (string) (array_key_first($sources) ?? ''),
            'sources'      => $sources,
            'sellers'      => array_values($sellers),
            'passengers'   => self::passengerHistory($phones, $scopeId),
        ];
    }

    private static function sourceWord(string $source, int $soldBy, string $role): string
    {
        if ($soldBy > 0) { return $role === 'agent' ? 'Agent' : 'Counter / office'; }
        return in_array($source, ['web', 'app'], true) ? 'Online (self)' : ($source === 'agent' ? 'Agent' : 'Counter / office');
    }

    /**
     * Every traveller booked from these numbers, one line per person
     * (same spelling-insensitive key the duplicate finder uses).
     *
     * @param list<string> $phones
     * @return list<array<string,mixed>>
     */
    public static function passengerHistory(array $phones, ?int $scopeId = null): array
    {
        if ($phones === []) { return []; }
        $ph = []; $p = [];
        foreach (array_values($phones) as $i => $num) { $ph[] = ':ph' . $i; $p['ph' . $i] = $num; }
        $scope = '';
        if ($scopeId !== null) { $scope = ' AND b.sold_by_admin_id = :scope'; $p['scope'] = $scopeId; }
        $rows = Database::fetchAll(
            "SELECT bp.full_name, bp.age, bp.gender, bp.seat_no, b.status, b.pnr, bl.travel_date
               FROM booking_passengers bp
               JOIN bookings b ON b.id = bp.booking_id
          LEFT JOIN booking_legs bl ON bl.id = bp.leg_id
              WHERE b.contact_phone IN (" . implode(',', $ph) . ")" . $scope . "
              ORDER BY bl.travel_date DESC, bp.id DESC
              LIMIT 2000",
            $p
        );
        $out = [];
        foreach ($rows as $r) {
            $key = self::nameSkeleton((string) $r['full_name']);   // Hukkum Bhuda == hukum budha
            if ($key === '') { continue; }
            if (!isset($out[$key])) {
                $out[$key] = ['name' => (string) $r['full_name'], 'spellings' => [], 'trips' => 0, 'cancelled' => 0,
                              'last_travel' => (string) ($r['travel_date'] ?? ''), 'last_pnr' => (string) $r['pnr'],
                              'age' => $r['age'], 'gender' => (string) ($r['gender'] ?? '')];
            }
            $out[$key]['spellings'][(string) $r['full_name']] = true;
            if (in_array((string) $r['status'], self::SOLD, true)) { $out[$key]['trips']++; }
            elseif ((string) $r['status'] === 'cancelled') { $out[$key]['cancelled']++; }
        }
        foreach ($out as &$o) { $o['spellings'] = array_keys($o['spellings']); }
        unset($o);
        $out = array_values($out);
        usort($out, static fn(array $a, array $b): int => [$b['trips'], $b['last_travel']] <=> [$a['trips'], $a['last_travel']]);
        return $out;
    }

    /* ------------------------------------------------------------------
     *  Edits (never touch a booking row)
     * ---------------------------------------------------------------- */

    /**
     * Save the office-kept contact facts. Returns '' on success or the reason.
     *
     * @param array<string,mixed> $in
     */
    public static function saveProfile(string $phone, array $in): string
    {
        $main = self::canonical($phone);
        $row = self::ensureProfile($main);
        if ($row === null) {
            return 'This number is not a customer number (placeholder or incomplete) — fix the booking contact instead.';
        }
        $name  = trim(Security::clean($in['full_name'] ?? '', 120));
        $alt   = normalisePhone((string) ($in['alt_phone'] ?? ''));
        $wa    = normalisePhone((string) ($in['whatsapp_phone'] ?? ''));
        $email = Security::email($in['email'] ?? '');
        $drop  = trim(Security::clean($in['preferred_drop'] ?? '', 120));
        $notes = trim(Security::clean($in['notes'] ?? '', 2000));

        if ($name !== '' && mb_strlen($name) < 2) { return 'Enter the customer\'s full name.'; }
        foreach (['Alternate mobile' => $alt, 'WhatsApp number' => $wa] as $label => $num) {
            if ($num !== '' && !Security::isValidPhone($num, true)) { return $label . ' does not look right — 8 to 15 digits.'; }
        }
        if ($alt === $main) { $alt = ''; }

        $after = ['full_name' => $name !== '' ? $name : null];
        if (self::hasCol('alt_phone')) {
            $after += ['alt_phone' => $alt ?: null, 'whatsapp_phone' => $wa ?: null, 'email' => $email ?: null,
                       'preferred_drop' => $drop ?: null, 'notes' => $notes ?: null];
        }
        $before = array_intersect_key($row, $after);
        $nameChanged = ($name !== '' && $name !== (string) ($row['full_name'] ?? ''));
        if ($nameChanged && self::hasCol('name_locked')) { $after['name_locked'] = 1; }

        $diff = [];
        foreach ($after as $k => $v) {
            if ((string) ($row[$k] ?? '') !== (string) ($v ?? '')) { $diff[$k] = $v; }
        }
        if ($diff === []) { return ''; }

        Database::update('user_profiles', $diff, 'phone = :p', ['p' => $main]);
        // The sign-in account carries the same name; keep the two in step.
        // Tickets already issued keep the name typed for them.
        if ($nameChanged) {
            Database::update('users', ['full_name' => $name], 'phone = :p', ['p' => $main]);
        }
        GemVault::forget($main);
        Logger::audit('customer.profile_edit', 'customer', $main, array_intersect_key($before, $diff), $diff,
            'Customer profile edited (bookings and issued tickets unchanged)');
        return '';
    }

    /**
     * Link $from to $into (same person). Bookings keep the number they were
     * made with; the history reads across both. Returns '' or the reason.
     */
    public static function merge(string $from, string $into, string $why = 'merge', bool $copyContact = false): string
    {
        $from = normalisePhone($from);
        $into = self::canonical($into);
        if ($from === '' || $into === '') { return 'Both numbers are needed.'; }
        if ($from === $into) { return 'That is the same number.'; }
        foreach ([$from, $into] as $n) {
            if (!self::isRealPhone($n)) { return 'A placeholder or incomplete number (like 0000000000) cannot be linked to a customer.'; }
            if (isset(self::staffPhones()[$n])) { return '+' . $n . ' belongs to ' . self::staffPhones()[$n] . ' — not a customer number.'; }
        }
        if (self::canonical($from) === $into) { return 'These numbers are already linked.'; }

        $a = self::ensureProfile($from);
        $b = self::ensureProfile($into);
        if ($a === null || $b === null) { return 'Could not open the customer record.'; }

        Database::transaction(static function () use ($from, $into, $a, $b, $copyContact): void {
            // Anything already linked to $from follows it (one level only).
            Database::update('user_profiles', ['merged_into' => $into], 'merged_into = :f', ['f' => $from]);
            Database::update('user_profiles', ['merged_into' => $into], 'phone = :f', ['f' => $from]);

            // Fill the main record's blanks from the linked one — never overwrite.
            $fill = [];
            if (trim((string) ($b['full_name'] ?? '')) === '' && trim((string) ($a['full_name'] ?? '')) !== '') { $fill['full_name'] = $a['full_name']; }
            if (($b['country_code'] ?? '') === null || ($b['country_code'] ?? '') === '') {
                if (!empty($a['country_code'])) { $fill['country_code'] = $a['country_code']; }
            }
            if (self::hasCol('alt_phone')) {
                if (trim((string) ($b['alt_phone'] ?? '')) === '') { $fill['alt_phone'] = $from; }
                foreach (['whatsapp_phone', 'email', 'preferred_drop', 'notes'] as $k) {
                    if (trim((string) ($b[$k] ?? '')) === '' && trim((string) ($a[$k] ?? '')) !== '') { $fill[$k] = $a[$k]; }
                }
                if ($copyContact && !empty($a['name_locked']) && trim((string) ($a['full_name'] ?? '')) !== '') {
                    $fill['full_name'] = $a['full_name'];
                    $fill['name_locked'] = 1;
                }
            }
            if ($fill !== []) { Database::update('user_profiles', $fill, 'phone = :i', ['i' => $into]); }
        });
        GemVault::forget($from);
        GemVault::forget($into);
        Logger::audit('customer.' . ($why === 'phone_change' ? 'phone_change' : 'merge'), 'customer', $into,
            ['phone' => $from], ['linked_into' => $into],
            $why === 'phone_change'
                ? 'Customer number changed to +' . $into . ' — old +' . $from . ' kept as a linked number; bookings unchanged'
                : 'Duplicate customer +' . $from . ' linked into +' . $into . ' — bookings unchanged');
        return '';
    }

    /** Undo a link. Returns '' or the reason. */
    public static function unmerge(string $phone): string
    {
        $p = normalisePhone($phone);
        $into = (string) Database::scalar('SELECT merged_into FROM user_profiles WHERE phone = :p', ['p' => $p], '');
        if ($into === '') { return 'This number is not linked to another.'; }
        Database::update('user_profiles', ['merged_into' => null], 'phone = :p', ['p' => $p]);
        GemVault::forget($p);
        GemVault::forget($into);
        Logger::audit('customer.unmerge', 'customer', $into, ['linked' => $p], null, 'Number +' . $p . ' separated from +' . $into);
        return '';
    }

    /**
     * The customer has a NEW primary number. The new number becomes the main
     * record (with the old one's name, notes, etc.), the old number is kept
     * as a linked number so its tickets stay in the history. Tickets
     * already issued keep the number they were sent to.
     */
    public static function changePhone(string $old, string $new): string
    {
        $old = self::canonical($old);
        $new = normalisePhone($new);
        if (!Security::isValidPhone($new)) { return 'Enter the new 10-digit mobile number.'; }
        if ($new === $old) { return 'That is already the main number.'; }
        // The new number was linked UNDER this customer: promote it.
        if (self::canonical($new) === $old) { self::unmerge($new); }
        if (self::canonical($new) !== $new) { return '+' . $new . ' is already linked to another customer (+' . self::canonical($new) . ') — open that one instead.'; }
        $oldRow = self::ensureProfile($old);
        $newRow = self::ensureProfile($new);
        if ($oldRow === null || $newRow === null) { return 'This number cannot be changed here.'; }
        // The old record's name wins on the new main record when the new one has none.
        $copy = [];
        if (trim((string) ($newRow['full_name'] ?? '')) === '' && trim((string) ($oldRow['full_name'] ?? '')) !== '') {
            $copy['full_name'] = $oldRow['full_name'];
        }
        if ($copy !== []) { Database::update('user_profiles', $copy, 'phone = :p', ['p' => $new]); }
        // Older links of the old number move with it.
        return self::merge($old, $new, 'phone_change', true);
    }

    /* ------------------------------------------------------------------
     *  Search (global search box + ledger)
     * ---------------------------------------------------------------- */

    /**
     * Customers matching a mobile (any part, incl. the last 4–6 digits) or a
     * name. One row per main number.
     *
     * @return list<array<string,mixed>>
     */
    public static function searchCustomers(string $q, ?int $scopeId = null, int $limit = 30): array
    {
        $q = trim($q);
        $digits = preg_replace('/\D/', '', $q) ?? '';
        $isPhone = strlen($digits) >= 4 && strlen($digits) >= (int) floor(mb_strlen(preg_replace('/\s+/', '', $q) ?? '') * 0.7);
        $hits = []; // phone => rank

        $add = static function (array $rows, int $rank) use (&$hits): void {
            foreach ($rows as $r) {
                $ph = normalisePhone((string) $r['phone']);
                if ($ph === '') { continue; }
                $hits[$ph] = max($hits[$ph] ?? 0, $rank);
            }
        };
        $scopeSql = $scopeId !== null ? ' AND b.sold_by_admin_id = :scope' : '';
        $sp = $scopeId !== null ? ['scope' => $scopeId] : [];

        if ($isPhone) {
            // Ends-with ranks above contains: "4321" is most often the last digits.
            $add(Database::fetchAll(
                "SELECT DISTINCT b.contact_phone AS phone, (b.contact_phone LIKE :tail) AS tail FROM bookings b
                  WHERE b.contact_phone LIKE :any" . $scopeSql . " ORDER BY tail DESC LIMIT 200",
                ['tail' => '%' . $digits, 'any' => '%' . $digits . '%'] + $sp
            ), 2);
            if ($scopeId === null) {
                $add(Database::fetchAll(
                    'SELECT phone FROM user_profiles WHERE phone LIKE :a1' . (self::hasCol('alt_phone') ? ' OR alt_phone LIKE :a2 OR whatsapp_phone LIKE :a3' : '') . ' LIMIT 200',
                    self::hasCol('alt_phone') ? ['a1' => '%' . $digits . '%', 'a2' => '%' . $digits . '%', 'a3' => '%' . $digits . '%'] : ['a1' => '%' . $digits . '%']
                ), 2);
                $add(Database::fetchAll("SELECT phone FROM users WHERE role = 'customer' AND phone LIKE :a LIMIT 100", ['a' => '%' . $digits . '%']), 2);
            }
        } elseif (mb_strlen($q) >= 2) {
            $like = '%' . $q . '%';
            $add(Database::fetchAll(
                "SELECT DISTINCT b.contact_phone AS phone FROM booking_passengers bp JOIN bookings b ON b.id = bp.booking_id
                  WHERE bp.full_name LIKE :n" . $scopeSql . " ORDER BY b.id DESC LIMIT 200",
                ['n' => $like] + $sp
            ), 1);
            if ($scopeId === null) {
                $add(Database::fetchAll('SELECT phone FROM user_profiles WHERE full_name LIKE :n LIMIT 200', ['n' => $like]), 2);
                $add(Database::fetchAll("SELECT phone FROM users WHERE role = 'customer' AND full_name LIKE :n LIMIT 100", ['n' => $like]), 2);
            }
        }
        if ($hits === []) { return []; }

        // Fold linked numbers onto their main record; drop non-customer numbers.
        $main = [];
        foreach ($hits as $ph => $rank) {
            $ph = (string) $ph;   // a 10-digit key comes back as an int
            if (!self::isRealPhone($ph) || isset(self::staffPhones()[$ph])) { continue; }
            $m = self::canonical($ph);
            $main[$m] = max($main[$m] ?? 0, $rank) + (str_ends_with($ph, $digits) && $digits !== '' ? 1 : 0);
        }
        arsort($main);
        $main = array_slice($main, 0, $limit, true);

        $out = [];
        foreach (array_keys($main) as $ph) {
            $ph = (string) $ph;
            $phones = self::linkedPhones($ph) ?: [$ph];
            $ph2 = []; $pp = [];
            foreach ($phones as $i => $n) { $ph2[] = ':p' . $i; $pp['p' . $i] = $n; }
            if ($scopeId !== null) { $pp['scope'] = $scopeId; }
            $agg = Database::fetch(
                "SELECT COUNT(*) AS bookings,
                        COALESCE(SUM(b.status IN ('confirmed','completed')), 0) AS sold,
                        COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id) ELSE 0 END), 0) AS pax,
                        COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount ELSE 0 END), 0) AS spend,
                        MAX((SELECT MAX(bl.travel_date) FROM booking_legs bl WHERE bl.booking_id = b.id)) AS last_travel,
                        MAX(b.id) AS last_id
                   FROM bookings b
                  WHERE b.contact_phone IN (" . implode(',', $ph2) . ")" . $scopeSql,
                $pp
            ) ?? [];
            $prof = Database::fetch('SELECT * FROM user_profiles WHERE phone = :p', ['p' => $ph]);
            $name = trim((string) ($prof['full_name'] ?? ''));
            if ($name === '' && (int) ($agg['last_id'] ?? 0) > 0) {
                $name = (string) Database::scalar(
                    'SELECT full_name FROM booking_passengers WHERE booking_id = :b ORDER BY is_primary DESC, id LIMIT 1',
                    ['b' => (int) $agg['last_id']], ''
                );
            }
            if ($scopeId !== null && (int) ($agg['bookings'] ?? 0) === 0) { continue; }
            $out[] = [
                'phone'       => $ph,
                'phones'      => $phones,
                'name'        => $name,
                'customer_id' => self::customerIdLabel($prof),
                'bookings'    => (int) ($agg['bookings'] ?? 0),
                'sold'        => (int) ($agg['sold'] ?? 0),
                'pax'         => (int) ($agg['pax'] ?? 0),
                'spend'       => (float) ($agg['spend'] ?? 0),
                'last_travel' => (string) ($agg['last_travel'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Money movements matching a reference, UTR, payer phone or PNR.
     *
     * @return list<array<string,mixed>>
     */
    public static function searchTransactions(string $q, ?int $scopeId = null, int $limit = 30): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 3) { return []; }
        $digits = preg_replace('/\D/', '', $q) ?? '';
        $p = ['t1' => '%' . $q . '%', 't2' => '%' . $q . '%', 't3' => '%' . $q . '%'];
        $arms = 'p.payment_ref LIKE :t1 OR p.utr_number LIKE :t2 OR b.pnr LIKE :t3';
        if (strlen($digits) >= 4) { $arms .= ' OR p.payer_phone LIKE :t4'; $p['t4'] = '%' . $digits . '%'; }
        $scope = '';
        if ($scopeId !== null) { $scope = ' AND b.sold_by_admin_id = :scope'; $p['scope'] = $scopeId; }
        return Database::fetchAll(
            "SELECT p.id, p.payment_ref, p.method, p.amount, p.currency, p.utr_number, p.status, p.created_at,
                    b.pnr, b.contact_phone
               FROM payments p JOIN bookings b ON b.id = p.booking_id
              WHERE (" . $arms . ")" . $scope . "
              ORDER BY p.id DESC LIMIT " . max(1, $limit),
            $p
        );
    }

    /* ------------------------------------------------------------------
     *  Names: one spelling-insensitive key
     * ---------------------------------------------------------------- */

    /** Lower-case, punctuation-free, honorific-free name ("Ram Bahadur" == "RAM BAHADUR"). */
    public static function nameKey(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s) ?? '';
        $drop = ['sir', 'ji', 'mr', 'mrs', 'ms', 'miss', 'shree', 'shri', 'sri', 'smt', 'kumar', 'kumari'];
        $parts = array_values(array_filter(explode(' ', $s), static fn(string $t): bool => $t !== '' && !in_array($t, $drop, true)));
        // "bahadur", "bdr", "b" are one word in a Nepali name.
        $parts = array_map(static fn(string $t): string => in_array($t, ['bahadur', 'bhadur', 'bahadhur', 'bhahadhur', 'bhadaur', 'bdr', 'b'], true) ? 'b' : $t, $parts);
        return implode(' ', $parts);
    }

    /** Sound-alike skeleton: "Hukkum Bhuda" and "hukum budha" meet here. */
    public static function nameSkeleton(string $name): string
    {
        $s = self::nameKey($name);
        if ($s === '') { return ''; }
        $s = strtr($s, ['ee' => 'i', 'oo' => 'u', 'aa' => 'a', 'w' => 'v', 'z' => 'j', 'q' => 'k', 'y' => 'i', 'sh' => 's', 'ph' => 'f']);
        $s = preg_replace('/([bcdgjkpt])h/u', '$1', $s) ?? $s;   // bh→b, dh→d, kh→k, th→t …
        $s = preg_replace('/(.)\1+/u', '$1', $s) ?? $s;          // kk→k, ll→l
        $s = preg_replace('/a\b/u', '', $s) ?? $s;               // trailing schwa: krishna/krishn
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    /** Are two names plausibly the same person? 2 = same, 1 = short form / close, 0 = no. */
    public static function nameMatch(string $a, string $b): int
    {
        $ka = self::nameSkeleton($a); $kb = self::nameSkeleton($b);
        if ($ka === '' || $kb === '') { return 0; }
        if ($ka === $kb) { return 2; }
        // Vowels are where Roman spellings of one name drift (Dileep / Dilep /
        // Dilip): the same consonants in the same order are the same name.
        $ca = preg_replace('/[aeiou]/', '', $ka) ?? ''; $cb = preg_replace('/[aeiou]/', '', $kb) ?? '';
        if ($ca === $cb && strlen(str_replace(' ', '', $ca)) >= 3) { return 2; }
        $ta = explode(' ', $ka); $tb = explode(' ', $kb);
        // "Ram" vs "Ram Bahadur", "Dan Bahadur" vs "Dan Bahadur Thapa": the
        // shorter name is the leading part of the longer one.
        $short = count($ta) <= count($tb) ? $ta : $tb;
        $long  = count($ta) <= count($tb) ? $tb : $ta;
        if (count($short) < count($long) && array_slice($long, 0, count($short)) === $short) { return 1; }
        $len = max(strlen($ka), strlen($kb));
        if ($len >= 6 && levenshtein($ka, $kb) <= ($len >= 12 ? 2 : 1)) { return 2; }
        if ($ta[0] === $tb[0] && count($ta) > 1 && count($tb) > 1 && levenshtein(end($ta), end($tb)) <= 1) { return 2; }
        return 0;
    }

    /** Two 10-digit numbers one typo apart (one digit wrong, or two swapped). */
    public static function phonesClose(string $a, string $b): bool
    {
        if (strlen($a) !== strlen($b) || $a === $b) { return false; }
        $diff = [];
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            if ($a[$i] !== $b[$i]) { $diff[] = $i; if (count($diff) > 2) { return false; } }
        }
        if (count($diff) === 1) { return true; }
        return count($diff) === 2 && $diff[1] === $diff[0] + 1 && $a[$diff[0]] === $b[$diff[1]] && $a[$diff[1]] === $b[$diff[0]];
    }

    /* ------------------------------------------------------------------
     *  Duplicate finder (suggestions only — a person decides)
     * ---------------------------------------------------------------- */

    /**
     * @return array{variants:list<array<string,mixed>>, pairs:list<array<string,mixed>>, shared:list<array<string,mixed>>, nonCustomer:list<array<string,mixed>>}
     */
    public static function duplicates(): array
    {
        $rows = Database::fetchAll(
            // BINARY grouping: the column collation is case-insensitive, and
            // "Ram Bahadur" vs "RAM BAHADUR" is exactly what the office wants to see.
            "SELECT b.contact_phone AS phone, CONVERT(MAX(BINARY bp.full_name) USING utf8mb4) AS name, COUNT(*) AS n, MAX(b.created_at) AS last_at
               FROM bookings b
               JOIN booking_passengers bp ON bp.booking_id = b.id AND bp.is_primary = 1
              GROUP BY b.contact_phone, BINARY bp.full_name"
        );
        $profiles = [];
        foreach (Database::fetchAll('SELECT * FROM user_profiles') as $pr) { $profiles[(string) $pr['phone']] = $pr; }
        $staff = self::staffPhones();

        $byPhone = []; $nonCustomer = [];
        foreach ($rows as $r) {
            $ph = (string) $r['phone'];
            $why = !self::isRealPhone($ph) ? 'Placeholder / incomplete number' : ($staff[normalisePhone($ph)] ?? '');
            if ($why !== '') {
                if (!isset($nonCustomer[$ph])) { $nonCustomer[$ph] = ['phone' => $ph, 'why' => $why, 'bookings' => 0, 'names' => 0]; }
                $nonCustomer[$ph]['bookings'] += (int) $r['n'];
                $nonCustomer[$ph]['names']++;
                continue;
            }
            $byPhone[$ph][] = ['name' => trim((string) $r['name']), 'n' => (int) $r['n'], 'last_at' => (string) $r['last_at']];
        }

        // Group the names on each number into people.
        $variants = []; $shared = []; $people = [];  // phone => list of clusters
        foreach ($byPhone as $ph => $names) {
            $ph = (string) $ph;   // numeric keys come back as ints
            usort($names, static fn(array $a, array $b): int => $b['n'] <=> $a['n']);
            $clusters = [];
            foreach ($names as $nm) {
                if ($nm['name'] === '') { continue; }
                $placed = false;
                foreach ($clusters as &$c) {
                    if (self::nameMatch($c['names'][0]['name'], $nm['name']) > 0) { $c['names'][] = $nm; $c['n'] += $nm['n']; $placed = true; break; }
                }
                unset($c);
                if (!$placed) { $clusters[] = ['names' => [$nm], 'n' => $nm['n']]; }
            }
            $people[$ph] = $clusters;
            $prof = $profiles[$ph] ?? null;
            if (!empty($prof['merged_into'])) { continue; }
            foreach ($clusters as $c) {
                if (count($c['names']) > 1) {
                    $variants[] = ['phone' => $ph, 'profile_name' => (string) ($prof['full_name'] ?? ''), 'locked' => !empty($prof['name_locked']),
                                   'names' => $c['names'], 'bookings' => $c['n']];
                }
            }
            if (count($clusters) >= 3) {
                $shared[] = ['phone' => $ph, 'people' => count($clusters), 'bookings' => array_sum(array_column($clusters, 'n')),
                             'sample' => array_slice(array_map(static fn(array $c): string => $c['names'][0]['name'], $clusters), 0, 6)];
            }
        }

        // Two numbers, one person: compare main names within a first-letter bucket.
        $dismissed = [];
        try {
            foreach (Database::fetchAll('SELECT phone_a, phone_b FROM customer_dup_dismissed') as $d) { $dismissed[$d['phone_a'] . '|' . $d['phone_b']] = true; }
        } catch (Throwable $e) { /* table not migrated yet */ }

        $main = []; // phone => name
        foreach ($people as $ph => $clusters) {
            $ph = (string) $ph;
            $prof = $profiles[$ph] ?? null;
            if (!empty($prof['merged_into'])) { continue; }
            $nm = trim((string) ($prof['full_name'] ?? ''));
            if ($nm === '' && $clusters !== []) { $nm = $clusters[0]['names'][0]['name']; }
            if ($nm !== '' && count($clusters) <= 2) { $main[$ph] = $nm; }
        }
        $buckets = [];
        foreach ($main as $ph => $nm) {
            $ph = (string) $ph;
            $sk = self::nameSkeleton($nm);
            if ($sk === '') { continue; }
            $buckets[substr($sk, 0, 2)][] = $ph;
        }
        $pairs = [];
        foreach ($buckets as $list) {
            $n = count($list);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = (string) $list[$i]; $b = (string) $list[$j];   // already strings, kept explicit
                    $key = strcmp($a, $b) < 0 ? $a . '|' . $b : $b . '|' . $a;
                    if (isset($dismissed[$key])) { continue; }
                    $nmScore = self::nameMatch($main[$a], $main[$b]);
                    if ($nmScore === 0) { continue; }
                    $close = self::phonesClose($a, $b);
                    // "Full name" = two real words; "Ram B(ahadur)" alone is too common to mean one person.
                    $words = static fn(string $n): int => count(array_filter(explode(' ', CustomerBook::nameKey($n)), static fn(string $t): bool => strlen($t) > 1));
                    $multi = $words($main[$a]) >= 2 && $words($main[$b]) >= 2;
                    if ($close) {
                        $level = 'high'; $why = 'Number differs by one digit (or two swapped) and the name matches';
                    } elseif ($nmScore === 2 && $multi) {
                        $level = 'check'; $why = 'Same full name on two different numbers — could be two people';
                    } else {
                        continue;
                    }
                    $pairs[] = ['a' => $a, 'b' => $b, 'name_a' => $main[$a], 'name_b' => $main[$b], 'level' => $level, 'why' => $why,
                                'n_a' => array_sum(array_column($people[$a] ?? [], 'n')), 'n_b' => array_sum(array_column($people[$b] ?? [], 'n'))];
                }
            }
        }
        usort($pairs, static fn(array $x, array $y): int => [$x['level'] === 'high' ? 0 : 1, -($x['n_a'] + $x['n_b'])] <=> [$y['level'] === 'high' ? 0 : 1, -($y['n_a'] + $y['n_b'])]);
        usort($variants, static fn(array $x, array $y): int => $y['bookings'] <=> $x['bookings']);
        usort($shared, static fn(array $x, array $y): int => $y['bookings'] <=> $x['bookings']);
        $nonCustomer = array_values($nonCustomer);
        usort($nonCustomer, static fn(array $x, array $y): int => $y['bookings'] <=> $x['bookings']);

        return ['variants' => $variants, 'pairs' => $pairs, 'shared' => $shared, 'nonCustomer' => $nonCustomer];
    }

    /** "These two numbers are different people" — stop suggesting the pair. */
    public static function dismissPair(string $a, string $b, int $by): void
    {
        $a = normalisePhone($a); $b = normalisePhone($b);
        if ($a === '' || $b === '' || $a === $b) { return; }
        if (strcmp($a, $b) > 0) { [$a, $b] = [$b, $a]; }
        Database::query('INSERT IGNORE INTO customer_dup_dismissed (phone_a, phone_b, dismissed_by) VALUES (:a, :b, :by)', ['a' => $a, 'b' => $b, 'by' => $by ?: null]);
        Logger::audit('customer.dup_dismiss', 'customer', $a, null, ['other' => $b], 'Marked +' . $a . ' and +' . $b . ' as different customers');
    }

    /** Use this spelling for the customer (profile name, locked). */
    public static function setName(string $phone, string $name): string
    {
        return self::saveProfile($phone, array_merge(self::editableOf(self::ensureProfile($phone) ?? []), ['full_name' => $name]));
    }

    /** @return array<string,string> the editable fields of a profile row, as form input */
    public static function editableOf(array $row): array
    {
        return [
            'full_name'      => (string) ($row['full_name'] ?? ''),
            'alt_phone'      => (string) ($row['alt_phone'] ?? ''),
            'whatsapp_phone' => (string) ($row['whatsapp_phone'] ?? ''),
            'email'          => (string) ($row['email'] ?? ''),
            'preferred_drop' => (string) ($row['preferred_drop'] ?? ''),
            'notes'          => (string) ($row['notes'] ?? ''),
        ];
    }
}
