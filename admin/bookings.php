<?php
/**
 * admin/bookings.php — the TICKETS register (searchable list of bookings).
 *
 * "Every booking" for office staff; strictly the agent's own sales for a
 * counter agent. Agents hold bookings.view (they must be able to look up a
 * ticket they sold), so the permission alone cannot be the whole gate —
 * without the scope below an agent could read the company's entire booking
 * book, including other agents' passengers and takings.
 *
 * 5 Sep 2026 (Phase 2 — tickets register): one row per ticket with the
 * columns the office reads (ticket no + PNR, booked on, travel date / route
 * / bus, passenger, seats, sold via, payment method + status, amount,
 * status) and the row actions (View / Edit / Print / PDF / Cancel / Resend
 * / WhatsApp). Filters: search, status, route, bus, agent, source, payment
 * status, payment method, booking-date and travel-date ranges. Server-side
 * pagination (50 per page) with totals computed over the WHOLE filtered
 * set, so the summary cards are never a truncated guess.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');

$base   = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$q      = Security::clean($_GET['q'] ?? '', 60);
$status = Security::clean($_GET['status'] ?? '', 20);
$from   = Security::clean($_GET['from'] ?? '', 10);
$to     = Security::clean($_GET['to'] ?? '', 10);
$valid  = ['pending', 'confirmed', 'completed', 'cancelled', 'rejected', 'expired'];
$isDate = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

// --- Filter inputs ---
$routeFilter     = Security::clean($_GET['route'] ?? '', 20);
$travelFrom      = Security::clean($_GET['travel_from'] ?? '', 10);
$travelTo        = Security::clean($_GET['travel_to'] ?? '', 10);
$sourceFilter    = Security::clean($_GET['source'] ?? '', 20);
$agentFilter     = Security::clean($_GET['agent'] ?? '', 20);
$busFilter       = Security::clean($_GET['bus'] ?? '', 40);
$payStatusFilter = Security::clean($_GET['pay_status'] ?? '', 20);
$payMethodFilter = Security::clean($_GET['pay_method'] ?? '', 20);
// Which counter cut it (4 Oct 2026). Sales made before the desk was frozen
// on the booking fall back to the seller's desk today.
$counterFilter   = mb_strtoupper(Security::clean($_GET['counter'] ?? '', 16));
/* 15 Oct 2026 (owner: chalani register, Excel-style). A month picker
   (?month=YYYY-MM) is just a booked-date range: it sets from/to for any past
   month, and every link after it carries from/to. View modes ride on the
   paging links only: ?hisab=1 the monthly hisab, ?cols=all every column,
   ?view=table the sideways-scrolling sheet on a phone instead of cards. */
$monthPick       = Security::clean($_GET['month'] ?? '', 7);
if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthPick) === 1) {
    $from = $monthPick . '-01';
    $to   = date('Y-m-t', (int) strtotime($from));
} else {
    $monthPick = '';
}
$hisabOn         = (string) ($_GET['hisab'] ?? '') === '1';
$colsAll         = (string) ($_GET['cols'] ?? '') === 'all';
$viewTable       = (string) ($_GET['view'] ?? '') === 'table';
$page            = max(1, (int) ($_GET['p'] ?? 1));
/* 5 Oct 2026: rows per page (25 / 50 / 100) and click-to-sort on a whitelist
   of columns; newest first stays the default. */
$PER_PAGE        = (int) ($_GET['pp'] ?? 50);
if (!in_array($PER_PAGE, [25, 50, 100], true)) {
    $PER_PAGE = 50;
}
/* 15 Oct 2026: + Ticket, Passenger, Route, Seller and Payment. The last four
   sort on the SELECT's own aliases (MariaDB accepts them in ORDER BY); the
   b.id tiebreak below keeps paging stable. */
$SORTS           = ['new' => 'b.id', 'booked' => 'b.created_at', 'travel' => 'bl.travel_date', 'amount' => 'b.total_amount', 'status' => 'b.status',
                    'ticket' => 'ticket_number', 'pax' => 'passenger', 'route' => "CONCAT_WS('-', r.from_city, r.to_city)",
                    'seller' => 'seller_name', 'pay' => 'pay_status'];
$sortKey         = (string) ($_GET['sort'] ?? 'new');
if (!isset($SORTS[$sortKey])) {
    $sortKey = 'new';
}
$sortDir         = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

// Payment enums match the payments table (schema.sql).
$validPayStatus = ['pending', 'verified', 'rejected', 'refunded', 'cod_pending'];
$validPayMethod = ['upi', 'esewa', 'cash', 'bank', 'wallet', 'cod'];

$scopeId = Auth::bookingScopeAdminId();

// --- Load lookup data for filters ---
$allRoutes = Database::fetchAll(
    "SELECT id, from_city, to_city, route_code FROM routes WHERE is_active = 1 ORDER BY from_city, to_city"
);
$allBuses = Database::fetchAll(
    "SELECT DISTINCT b.bus_number FROM buses b WHERE b.is_active = 1 ORDER BY b.bus_number"
);
$agentCodes = Settings::getArray('agent_codes', []);

// Agent dropdown: only visible to non-agent roles (agents see only their own bookings)
$allAgents = [];
if ($scopeId === null) {
    $allAgents = Database::fetchAll(
        "SELECT id, full_name, username FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY full_name"
    );
}

// Filters shared with the export (admin/_booking_filters.php).
require_once __DIR__ . '/_booking_filters.php';

// --- Build WHERE clauses ---
$where  = [];
$params = [];
if ($scopeId !== null) {
    $where[] = 'b.sold_by_admin_id = :scope';
    $params['scope'] = $scopeId;
}
if ($q !== '') {
    // Global search: PNR, ticket number, phone, email, and — via subqueries so
    // a booking is never multiplied by its seats/passengers — passenger name,
    // seat number, the assigned bus number, and the selling agent (name/login).
    // Agent-scoped views still only ever see their own rows because of the
    // scope clause above.
    $where[] = sqlSearchClause([
        'b.pnr LIKE %s',
        'b.contact_phone LIKE %s',
        'b.contact_email LIKE %s',
        'EXISTS (SELECT 1 FROM tickets t2 WHERE t2.booking_id = b.id AND t2.ticket_number LIKE %s)',
        'EXISTS (SELECT 1 FROM booking_passengers bp2
                  WHERE bp2.booking_id = b.id AND bp2.full_name LIKE %s)',
        'EXISTS (SELECT 1 FROM booking_seats bs2
                  WHERE bs2.booking_id = b.id AND bs2.seat_no LIKE %s)',
        'EXISTS (SELECT 1 FROM booking_legs bl2
                    JOIN schedules s2 ON s2.id = bl2.schedule_id
                    JOIN buses bu2 ON bu2.id = s2.bus_id
                  WHERE bl2.booking_id = b.id AND bu2.bus_number LIKE %s)',
        'EXISTS (SELECT 1 FROM admins a2
                  WHERE a2.id = b.sold_by_admin_id
                    AND CONCAT_WS(\' \', a2.full_name, a2.username) LIKE %s)',
    ], $q, $params);
    /* 15 Oct 2026: the seat arm above matches the STORED id (L11), but the
       register, the ticket and the manifest print the grid label (B5). A desk
       typing the seat it can see now finds it too. */
    $qSeatArm = shg_seat_label_exists($q, $params);
    if ($qSeatArm !== null) {
        $lastKey = array_key_last($where);
        $where[$lastKey] = '(' . $where[$lastKey] . ' OR ' . $qSeatArm . ')';
    }
    // A typed SHG code ("SHG-027" / "27") lives in a settings map, not a column,
    // so resolve it and OR the seller's id into the search (office views only;
    // an agent's list is already scoped to themselves).
    $qAgentId = $scopeId === null ? AgentWallet::resolveAgentCodeFromString($q) : null;
    if ($qAgentId !== null) {
        $lastKey = array_key_last($where);
        $where[$lastKey] = '(' . $where[$lastKey] . ' OR b.sold_by_admin_id = :qAgentCode)';
        $params['qAgentCode'] = $qAgentId;
    }
}
if (in_array($status, $valid, true)) {
    $where[] = 'b.status = :st';
    $params['st'] = $status;
}
// Optional "placed between" window (booking date), matching agent-sales.php semantics.
if ($isDate($from)) {
    $where[] = 'b.created_at >= :bkdFrom';
    $params['bkdFrom'] = $from;
}
if ($isDate($to)) {
    $where[] = 'b.created_at < :bkdToEnd';
    $params['bkdToEnd'] = addDaysISO($to, 1);
}
// Route filter
if ($routeFilter !== '' && ctype_digit($routeFilter)) {
    $where[] = 's.route_id = :routeFilter';
    $params['routeFilter'] = (int) $routeFilter;
}
// Travel date range (distinct from booking date)
if ($isDate($travelFrom)) {
    $where[] = 'bl.travel_date >= :travelFrom';
    $params['travelFrom'] = $travelFrom;
}
if ($isDate($travelTo)) {
    $where[] = 'bl.travel_date <= :travelTo';
    $params['travelTo'] = $travelTo;
}
// Sold-via filter (5 Oct 2026): ONE channel tag per booking - Online / Agent /
// Counter / Admin / WhatsApp / Google Ads (includes/bookingchannel.php). An
// agent's sale through the public page with their code now counts as Agent;
// it used to hide under Online.
$sourceFilter = BookingChannel::applyFilter($where, $sourceFilter);
// Agent filter (only for non-agent roles)
if ($scopeId === null && $agentFilter !== '' && ctype_digit($agentFilter)) {
    $where[] = 'b.sold_by_admin_id = :agentFlt';
    $params['agentFlt'] = (int) $agentFilter;
}
/* Desk isolation (4 Oct 2026, ships OFF - Admin -> Counters & collection):
   a counter login sees only its own desk's ticket register. Applied before the user's
   own filters so it cannot be widened by editing the query string. */
$deskScope = Auth::deskScopeCode();
if ($deskScope !== null && CounterDesk::stampColumn()) {
    $where[] = CounterDesk::scopeClause('b');
    $params['deskScope'] = $deskScope;
}
if ($deskScope !== null) {
    $counterFilter = '';   // the scope above already pins the desk
}
// Counter filter — the desk the ticket was cut at (frozen stamp, else the seller's desk today).
if ($counterFilter !== '' && CounterDesk::stampColumn()) {
    $where[] = CounterDesk::scopeClause('b', 'ctrFlt');
    $params['ctrFlt'] = $counterFilter;
}
// Bus filter — exact bus_number match via the outbound leg's schedule.
if ($busFilter !== '') {
    $where[] = 'bu.bus_number = :busFlt';
    $params['busFlt'] = $busFilter;
}
// Payment-status / method filters — payments has many rows per booking
// (retries), so EXISTS avoids multiplying the booking row.
if ($payStatusFilter !== '' && in_array($payStatusFilter, $validPayStatus, true)) {
    $where[] = 'EXISTS (SELECT 1 FROM payments p2
                         WHERE p2.booking_id = b.id AND p2.status = :payStFlt)';
    $params['payStFlt'] = $payStatusFilter;
}
if ($payMethodFilter !== '' && in_array($payMethodFilter, $validPayMethod, true)) {
    $where[] = 'EXISTS (SELECT 1 FROM payments p4
                         WHERE p4.booking_id = b.id AND p4.method = :payMtFlt)';
    $params['payMtFlt'] = $payMethodFilter;
}

/* 5 Oct 2026: customer-number and ticket-reach filters, shared with the export
   (admin/_booking_filters.php) so screen and file can never disagree. */
$xf = shg_booking_extra_filters($where, $params, $_GET);
$phoneFilter = $xf['phone'];
$reachFilter = $xf['reach'];
/* 15 Oct 2026: the column funnels (ticket / passenger / mobile / seat as
   printed) and "only my sales" - the same function the export calls. */
$cf = shg_booking_column_filters($where, $params, $_GET);

$joinSql = "FROM bookings b
          LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
          LEFT JOIN schedules s ON s.id = bl.schedule_id
          LEFT JOIN routes r ON r.id = s.route_id
          LEFT JOIN buses bu ON bu.id = s.bus_id";
$whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';
$fromSql  = $joinSql . $whereSql;
/* The cards and the hisab read each booking's LATEST payment (the row the
   Payment column shows) through one derived join. It lives in these two
   read-only queries only - never in $fromSql - so row paging is untouched. */
$lpJoin = ' LEFT JOIN payments lp ON lp.id = (SELECT MAX(p9.id) FROM payments p9 WHERE p9.booking_id = b.id)';
$liveSeatsSql = '(SELECT COUNT(*) FROM booking_seats bsa WHERE bsa.booking_id = b.id AND bsa.released_at IS NULL)';

/* Totals over the WHOLE filtered set (not just this page). 15 Oct 2026 (3B):
   + passengers (live seats), cash still due, online money in, cancelled and
   the refund position - read-only; the owner settles refunds by hand. */
$aggZero = ['total' => 0, 'confirmed' => 0, 'pending' => 0, 'revenue' => 0, 'pax' => 0, 'cash_due_n' => 0, 'cash_due' => 0,
            'online_n' => 0, 'online_paid' => 0, 'cancelled' => 0, 'cancelled_amt' => 0, 'refunded' => 0,
            'refund_pending_n' => 0, 'refund_pending_amt' => 0];
$agg = Database::fetch(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(b.status = 'confirmed'), 0) AS confirmed,
            COALESCE(SUM(b.status = 'pending'), 0) AS pending,
            COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed','pending') THEN {$liveSeatsSql} ELSE 0 END), 0) AS pax,
            COALESCE(SUM(b.status IN ('confirmed','completed') AND lp.status = 'cod_pending'), 0) AS cash_due_n,
            COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') AND lp.status = 'cod_pending' THEN b.total_amount ELSE 0 END), 0) AS cash_due,
            COALESCE(SUM(b.status IN ('confirmed','completed') AND lp.status = 'verified' AND lp.method NOT IN ('cash','cod')), 0) AS online_n,
            COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') AND lp.status = 'verified' AND lp.method NOT IN ('cash','cod') THEN b.total_amount ELSE 0 END), 0) AS online_paid,
            COALESCE(SUM(b.status = 'cancelled'), 0) AS cancelled,
            COALESCE(SUM(CASE WHEN b.status = 'cancelled' THEN b.total_amount ELSE 0 END), 0) AS cancelled_amt,
            COALESCE(SUM(CASE WHEN b.refund_status = 'processed' THEN b.refund_amount ELSE 0 END), 0) AS refunded,
            COALESCE(SUM(b.refund_status = 'pending'), 0) AS refund_pending_n,
            COALESCE(SUM(CASE WHEN b.refund_status = 'pending' THEN b.refund_amount ELSE 0 END), 0) AS refund_pending_amt
     " . $joinSql . $lpJoin . $whereSql,
    $params
) ?? $aggZero;
$totalResults     = (int) $agg['total'];
$confirmedCount   = (int) $agg['confirmed'];
$pendingCount     = (int) $agg['pending'];
$confirmedRevenue = (float) $agg['revenue'];
/* Company money on the cards (4C, 15 Oct 2026): Revenue and Online paid are
   for the money roles (reports.view) or a viewer whose list is already
   their own - an agent, a desk-isolated counter, or "only my sales". An
   unscoped counter keeps the counts and the cash it must still collect. */
$showMoney = Auth::can('reports.view') || $scopeId !== null
    || ($deskScope !== null && CounterDesk::stampColumn()) || $cf['mine'] === '1';
$pages = max(1, (int) ceil($totalResults / $PER_PAGE));
if ($page > $pages) { $page = $pages; }
$offset = ($page - 1) * $PER_PAGE;

// LIMIT/OFFSET are interpolated as ints on purpose: the PDO driver here runs
// real prepares and would quote a bound LIMIT (same note as activity-log.php).
/* 15 Oct 2026: + b.expires_at (the Payment pill told every unpaid hold
   "Verification pending" because it was never selected) and the coach the
   departure runs (seat labels), plus the All-columns cells (email, desk,
   cabin, confirmed at, refund) - plain columns, cheap to always read. */
$deskSql = CounterDesk::stampColumn()
    ? "COALESCE(NULLIF(b.counter_code,''), (SELECT apd.counter_code FROM admin_profiles apd WHERE apd.admin_id = b.sold_by_admin_id))"
    : 'NULL';
$sql = "SELECT b.id, b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, b.booking_mode,
               b.source, b.sold_by_admin_id, b.is_cod, " . BookingChannel::sqlExpr('b') . " AS channel,
               b.expires_at, b.contact_email, b.cabin_label, b.confirmed_at, b.refund_status, b.refund_amount,
               {$deskSql} AS desk_code, COALESCE(s.coach_type_override, r.coach_type) AS coach_type,
               r.from_city, r.to_city, r.route_code, bl.travel_date, bl.boarding_stop, bl.drop_stop,
               s.id AS schedule_id, COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
               bu.bus_number, bu.bus_name,
               (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
                  FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL) AS seats,
               (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL) AS seat_count,
               (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS passenger,
               (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id) AS pax_count,
               (SELECT p.method FROM payments p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS pay_method,
               (SELECT p.status FROM payments p WHERE p.booking_id = b.id ORDER BY p.id DESC LIMIT 1) AS pay_status,
               (SELECT t.ticket_number FROM tickets t WHERE t.booking_id = b.id LIMIT 1) AS ticket_number,
               (SELECT t.is_void FROM tickets t WHERE t.booking_id = b.id LIMIT 1) AS ticket_void,
               (SELECT full_name FROM admins WHERE id = b.sold_by_admin_id) AS seller_name
          " . $fromSql . '
          ORDER BY ' . $SORTS[$sortKey] . ' ' . $sortDir . ', b.id DESC LIMIT ' . (int) $PER_PAGE . ' OFFSET ' . (int) $offset;

$rows = Database::fetchAll($sql, $params);

/* =====================================================================
 *  MONTHLY HISAB (3A, 15 Oct 2026, owner: "one click - the month's hisab").
 *  ONE read-only query over exactly the filters on screen (pick a month, a
 *  route, a desk ...), grouped by who sold it (NULL = the website):
 *  tickets, passengers (live seats), revenue (confirmed + completed),
 *  cancelled, refunds paid / still pending, and money still to come in
 *  (cash at boarding + unverified online). Display only: refunds and
 *  cancellations stay with the owner, by hand.
 *
 *  The per-seller table is company money, so it is for the money roles
 *  only (reports.view, unscoped - as Accounting and Agent sales gate it).
 *  A counter holds bookings.view, so without this gate one ticket window
 *  could read every other seller's takings. An agent's list is already
 *  scoped to their own sales and sees just the totals.
 * ===================================================================== */
$mayExport     = $scopeId !== null || Auth::can('reports.export') || Auth::isSuperadmin();
$hisabBySeller = $scopeId === null && Auth::can('reports.view');
$hisab         = null;
if ($hisabOn) {
    $hRows = Database::fetchAll(
        "SELECT b.sold_by_admin_id AS sid, MAX(sa.full_name) AS sname, MAX(sa.username) AS suser, MAX(sa.role) AS srole,
                COUNT(*) AS n,
                COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed','pending') THEN {$liveSeatsSql} ELSE 0 END), 0) AS pax,
                COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount ELSE 0 END), 0) AS revenue,
                COALESCE(SUM(b.status = 'cancelled'), 0) AS cancelled,
                COALESCE(SUM(CASE WHEN b.refund_status = 'processed' THEN b.refund_amount ELSE 0 END), 0) AS ref_done,
                COALESCE(SUM(b.refund_status = 'pending'), 0) AS ref_pend_n,
                COALESCE(SUM(CASE WHEN b.refund_status = 'pending' THEN b.refund_amount ELSE 0 END), 0) AS ref_pend,
                COALESCE(SUM(b.status IN ('confirmed','completed') AND lp.status = 'cod_pending'), 0) AS cash_due_n,
                COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') AND lp.status = 'cod_pending' THEN b.total_amount ELSE 0 END), 0) AS cash_due,
                COALESCE(SUM(b.status = 'pending' AND lp.status = 'pending'), 0) AS unver_n,
                COALESCE(SUM(CASE WHEN b.status = 'pending' AND lp.status = 'pending' THEN b.total_amount ELSE 0 END), 0) AS unver
         " . $joinSql . $lpJoin . ' LEFT JOIN admins sa ON sa.id = b.sold_by_admin_id' . $whereSql . '
          GROUP BY b.sold_by_admin_id
          ORDER BY revenue DESC, n DESC',
        $params
    );
    $hKeys = ['n', 'pax', 'revenue', 'cancelled', 'ref_done', 'ref_pend_n', 'ref_pend', 'cash_due_n', 'cash_due', 'unver_n', 'unver'];
    $hTot  = array_fill_keys($hKeys, 0.0);
    $hSell = [];
    foreach ($hRows as $hr) {
        foreach ($hKeys as $hk) {
            $hTot[$hk] += (float) $hr[$hk];
        }
        // Online / counter / office / agent (with the SHG code), in words.
        $sid  = $hr['sid'] !== null ? (int) $hr['sid'] : null;
        $name = $sid === null ? '' : (string) ($hr['sname'] ?: ($hr['suser'] ?: '#' . $sid));
        $role = (string) ($hr['srole'] ?? '');
        $code = $role === 'agent' ? format_agent_code($agentCodes, $sid) : '';
        $hSell[] = $hr + ['label' => $sid === null ? 'Online (website)'
            : ($role === 'agent' ? 'Agent · ' . $name . ($code !== '' ? ' · ' . $code : '')
            : ($role === 'counter' ? 'Counter · ' . $name : 'Office · ' . $name))];
    }
    // The window the hisab covers, said in words above the figures.
    $hWin = [];
    if ($isDate($from) || $isDate($to)) {
        $hWin[] = 'booked ' . ($isDate($from) ? formatDate($from, 'j M Y') : '…') . ' – ' . ($isDate($to) ? formatDate($to, 'j M Y') : 'today');
    }
    if ($isDate($travelFrom) || $isDate($travelTo)) {
        $hWin[] = 'travel ' . ($isDate($travelFrom) ? formatDate($travelFrom, 'j M Y') : '…') . ' – ' . ($isDate($travelTo) ? formatDate($travelTo, 'j M Y') : '…');
    }
    $hisab = ['tot' => $hTot, 'sellers' => $hSell, 'window' => $hWin === [] ? 'all dates' : implode(' · ', $hWin)];

    // ?hisab=1&hx=csv|xlsx - the same table as a file, behind the export gate.
    $hx = (string) ($_GET['hx'] ?? '');
    if (in_array($hx, ['csv', 'xlsx'], true) && $mayExport) {
        require_once __DIR__ . '/_export.php';
        Logger::audit('bookings.export', 'export', 'hisab-' . $hx, null,
            ['filters' => array_filter(array_map(static fn($v): string => is_string($v) ? mb_substr($v, 0, 60) : '', $_GET))],
            'monthly hisab downloaded');
        $hHead = ['Seller', 'Tickets', 'Passengers', 'Revenue', 'Cancelled', 'Refunds paid', 'Refunds pending (no.)',
                  'Refunds pending', 'Cash due (no.)', 'Cash due', 'Unverified (no.)', 'Unverified'];
        $hLine = static fn(string $label, array $v): array => [
            $label, (int) $v['n'], (int) $v['pax'], $showMoney ? (float) $v['revenue'] : '', (int) $v['cancelled'],
            $showMoney ? (float) $v['ref_done'] : '', (int) $v['ref_pend_n'], $showMoney ? (float) $v['ref_pend'] : '',
            (int) $v['cash_due_n'], $showMoney ? (float) $v['cash_due'] : '', (int) $v['unver_n'], $showMoney ? (float) $v['unver'] : '',
        ];
        $hData = [];
        foreach ($hisabBySeller ? $hSell : [] as $hs) {
            $hData[] = $hLine((string) $hs['label'], $hs);
        }
        $hData[] = $hLine('TOTAL (' . $hisab['window'] . ')', $hTot);
        $hName = 'SHG-hisab-' . date('Y-m-d');
        if ($hx === 'xlsx') {
            shg_export_xlsx($hName . '.xlsx', 'Hisab', $hHead, $hData, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11]);
        }
        shg_export_csv($hName . '.csv', $hHead, $hData);
    }
}

/**
 * Source badge for the booking table.
 */
function source_badge(string $source): string
{
    $map = [
        'web'     => ['Online', '#0a6b3b', '#d7f4e3'],
        'app'     => ['Online', '#0a6b3b', '#d7f4e3'],
        'agent'   => ['Agent', '#1c3b72', '#e2ecfb'],
        'counter' => ['Counter', '#7a4a00', '#ffe6c7'],
        'admin'   => ['Admin', '#5a3fb0', '#efeaff'],
    ];
    [$label, $fg, $bg] = $map[$source] ?? [ucfirst($source ?: 'Online'), '#333', '#eee'];
    return '<span class="pill" style="color:' . $fg . ';background:' . $bg . '">' . $label . '</span>';
}

/**
 * Format an agent code from the agent_codes settings map.
 */
function format_agent_code(array $agentCodes, ?int $adminId): string
{
    if ($adminId === null || !isset($agentCodes[$adminId])) {
        return '';
    }
    return 'SHG-' . str_pad((string) ($agentCodes[$adminId] ?? ''), 4, '0', STR_PAD_LEFT);
}

/** Payment status pill (payments.status). */
function pay_status_pill(?string $st, bool $proofIn = false): string
{
    /* 23 Sep 2026 (UI/UX v3 brief §7): once the passenger has sent proof
       (UTR or screenshot) the booking is parked for review - expires_at is
       NULL, see BookingService::holdForAdminReview() - so the desk sees
       "Verification pending" rather than the same "Unverified" it shows a
       booking nobody has paid for yet. Same payments.status value ('pending');
       only the label changes. */
    if ($st === 'pending' && $proofIn) {
        return '<span class="pill" style="color:#7a3e00;background:#ffe3c2">Verification pending</span>';
    }
    $map = [
        'verified'    => ['Paid', '#0a6b3b', '#d7f4e3'],
        'pending'     => ['Unverified', '#8a6d00', '#fff4d1'],
        'cod_pending' => ['Cash due', '#7a4a00', '#ffe6c7'],
        'rejected'    => ['Rejected', '#8a1f1f', '#f7dcdc'],
        'refunded'    => ['Refunded', '#33507f', '#eef2fa'],
    ];
    if ($st === null || $st === '') { return '<span class="muted">—</span>'; }
    [$label, $fg, $bg] = $map[$st] ?? [ucfirst($st), '#333', '#eee'];
    return '<span class="pill" style="color:' . $fg . ';background:' . $bg . '">' . $label . '</span>';
}

/**
 * The ready-to-send WhatsApp text for one booking row.
 *
 * Deliberately plain text with no markup: it is pasted into WhatsApp by the
 * staff member, so anything that is not readable as-is is noise. Wording
 * follows the status, because "your ticket is confirmed" on a booking that
 * is still awaiting payment is the one message that costs a refund.
 */
function booking_wa_message(array $b, string $base): string
{
    $pnr    = (string) ($b['pnr'] ?? '');
    $route  = ($b['from_city'] ?? '') . ' -> ' . ($b['to_city'] ?? '');
    $date   = formatDate($b['travel_date'] ?? null);
    $amount = inr((float) ($b['total_amount'] ?? 0));
    $status = (string) ($b['status'] ?? '');
    $link   = Ticket::downloadUrl($pnr);
    $co     = Settings::getString('company_name', APP_NAME);

    if ($status === 'confirmed') {
        return "Namaste! " . $co . "\n"
             . "Booking " . $pnr . " CONFIRMED\n"
             . $route . " | " . $date . "\n"
             . "Amount: " . $amount . "\n"
             . "Ticket: " . $link . "\n"
             . "Shubha yatra!";
    }
    if ($status === 'cancelled') {
        return "Namaste! " . $co . "\n"
             . "Booking " . $pnr . " is CANCELLED.\n"
             . $route . " | " . $date . "\n"
             . "Refund ko bare ma kunai prashna cha bhane yehi reply garnus.";
    }
    return "Namaste! " . $co . "\n"
         . "Booking " . $pnr . " received - payment verification baaki cha.\n"
         . $route . " | " . $date . "\n"
         . "Amount: " . $amount . "\n"
         . "Confirm bhaye pachhi ticket yehi pathaunchhu.";
}

admin_header($scopeId !== null ? 'My Tickets' : 'Tickets', 'bookings');

// Live sync (2 Oct 2026): an EDIT to a ticket already on this list (seat,
// name, agent, cancel, bus) never raised the new-bookings pill below, which
// only watches for new ids. This anchor raises a Refresh pill for those too.
// An agent is pinned to their own book by LiveSync::scopeFor().
echo LiveSync::anchor('all', 'pill');

// Live "new bookings" pill baseline (P2): admin_poll_js() reads this anchor and
// shows a Refresh pill when a booking with a higher id appears, so the office
// sees new bookings within ~15s without hitting reload. Harmless on roles that
// don't run the poller (the anchor is just ignored).
echo '<span id="liveNewBookings" data-since="'
   . (int) ($scopeId !== null
        // Counter agents see only their own list, so their baseline is their own
        // newest sale — otherwise every other agent's booking would pop the pill.
        ? Database::scalar('SELECT COALESCE(MAX(id),0) FROM bookings WHERE sold_by_admin_id = :s', ['s' => $scopeId], 0)
        : Database::scalar('SELECT COALESCE(MAX(id),0) FROM bookings', [], 0))
   . '" hidden></span>';

// AI Watch (2 Oct 2026): this list is the COUNTER desk's home screen, so its
// own to-do sits on top - unfiltered first page only, never on a search.
if (($admin['role'] ?? '') === 'counter' && $q === '' && $status === '' && $page === 1) {
    /* TODAY AT MY DESK (4C, 15 Oct 2026, owner: what a clerk sees after
       login). One row of the clerk's OWN day - read-only queries, no new
       page: my sales today, the cash / UPI I took (CounterShift::totals, the
       drawer's own source), cash-at-boarding still to collect for today's
       buses, the next departures' free berths, and the four desk buttons. */
    $me     = (int) $admin['id'];
    $tdISO  = todayISO();
    $mySale = Database::fetch(
        "SELECT COUNT(*) AS n, COALESCE(SUM({$liveSeatsSql}), 0) AS seats
           FROM bookings b
          WHERE b.sold_by_admin_id = :me AND b.created_at >= :d0 AND b.created_at < :d1
            AND b.status IN ('pending','confirmed','completed')",
        ['me' => $me, 'd0' => $tdISO, 'd1' => addDaysISO($tdISO, 1)]
    ) ?? ['n' => 0, 'seats' => 0];
    $took = null;
    $shiftOn = false;
    try {
        if (is_file(INCLUDE_PATH . '/countershift.php')) {
            require_once INCLUDE_PATH . '/countershift.php';
            $took    = CounterShift::totals($me, $tdISO . ' 00:00:00');
            $shiftOn = CounterShift::enabled();
        }
    } catch (Throwable $e) {
        $took = null;
    }
    // Cash tickets on TODAY's buses whose money is not in yet (the Cash book's
    // "Pending" rule; a desk-isolated clerk sees its own desk + the website's).
    $tcWhere  = "b.is_cod = 1 AND l.travel_date = :d AND b.status NOT IN ('cancelled','rejected','expired')
                 AND COALESCE((SELECT p2.status FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1), '') <> 'verified'";
    $tcParams = ['d' => $tdISO];
    if ($deskScope !== null && CounterDesk::stampColumn()) {
        $tcWhere .= ' AND (b.counter_code = :desk OR b.counter_code IS NULL)';
        $tcParams['desk'] = $deskScope;
    }
    $toCollect = Database::fetch(
        "SELECT COUNT(*) AS n, COALESCE(SUM(b.total_amount), 0) AS amt
           FROM bookings b JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
          WHERE {$tcWhere}",
        $tcParams
    ) ?? ['n' => 0, 'amt' => 0];
    // The next departure of each route (today / tomorrow), with berths not yet sold.
    $nextBus = [];
    try {
        $seenRoute = [];
        foreach (Database::fetchAll(
            "SELECT s.id, s.route_id, s.travel_date, COALESCE(s.dep_time_override, r.dep_time) AS dep, r.from_city, r.to_city
               FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.travel_date BETWEEN :d0 AND :d1 AND s.status = 'scheduled' AND r.is_active = 1
                AND TIMESTAMP(s.travel_date, COALESCE(s.dep_time_override, r.dep_time)) >= :nowTs
              ORDER BY TIMESTAMP(s.travel_date, COALESCE(s.dep_time_override, r.dep_time)), s.id
              LIMIT 8",
            ['d0' => $tdISO, 'd1' => addDaysISO($tdISO, 1), 'nowTs' => date('Y-m-d H:i:s')]
        ) as $nb) {
            if (count($nextBus) < 2 && !isset($seenRoute[(int) $nb['route_id']])) {
                $seenRoute[(int) $nb['route_id']] = true;
                $nextBus[] = $nb;
            }
        }
        $occ = Seats::occupiedBedsFor(array_map(static fn(array $nb): int => (int) $nb['id'], $nextBus));
        foreach ($nextBus as $i => $nb) {
            $berths = count(Seats::seatIds(Seats::coachForSchedule((int) $nb['id']), 'sharing'));
            $nextBus[$i]['free'] = max(0, $berths - (int) ($occ[(int) $nb['id']] ?? 0));
        }
    } catch (Throwable $e) {
        $nextBus = [];
    }
    $dtTile = static fn(string $href, string $k, string $v, string $sub): string =>
        '<a class="dk-tile" href="' . Security::e($href) . '"><span class="k">' . Security::e($k) . '</span>'
        . '<span class="v">' . Security::e($v) . '</span><span class="s">' . Security::e($sub) . '</span></a>';
    echo '<section class="desk-today" aria-label="Today at my desk"><h2>🧾 Today at my desk <span class="muted">· ' . Security::e(formatDate($tdISO)) . '</span></h2>'
       . '<div class="dk-grid">'
       . $dtTile($base . '/admin/bookings.php?' . http_build_query(['mine' => 1, 'from' => $tdISO, 'to' => $tdISO]), 'My sales today',
                 number_format((int) $mySale['n']) . ' ticket' . ((int) $mySale['n'] === 1 ? '' : 's'), number_format((int) $mySale['seats']) . ' seats')
       . ($took !== null
           ? $dtTile($base . ($shiftOn ? '/admin/shift.php' : '/admin/cash-book.php?status=collected'), 'I took today',
                     inr((float) $took['cash']) . ' cash', inr((float) $took['upi']) . ' UPI / online · ' . (int) $took['tickets'] . ' tickets')
           : '')
       . $dtTile($base . '/admin/cash-book.php?' . http_build_query(['from' => $tdISO, 'to' => $tdISO, 'status' => 'pending']), 'Cash to collect today',
                 number_format((int) $toCollect['n']) . ' ticket' . ((int) $toCollect['n'] === 1 ? '' : 's'), inr((float) $toCollect['amt']) . ' at boarding');
    foreach ($nextBus as $nb) {
        echo $dtTile($base . '/admin/manifest.php?' . http_build_query(['date' => (string) $nb['travel_date'], 'route' => (int) $nb['route_id'], 'sid' => (int) $nb['id']]),
                     'Next bus · ' . substr((string) $nb['dep'], 0, 5) . ((string) $nb['travel_date'] !== $tdISO ? ' tomorrow' : ''),
                     (int) $nb['free'] . ' berths free', (string) $nb['from_city'] . ' → ' . (string) $nb['to_city']);
    }
    echo '</div><div class="dk-acts">'
       . '<a class="btn" href="' . $base . '/admin/smartbook.php">⚡ SmartBook</a>'
       . '<a class="btn ghost" href="' . $base . '/admin/cash-book.php">💵 Cash book</a>'
       . '<a class="btn ghost" href="' . $base . '/admin/manifest.php">📋 Manifest</a>'
       . ($shiftOn ? '<a class="btn ghost" href="' . $base . '/admin/shift.php">🔐 Shift</a>' : '')
       . '</div></section>';

    require_once INCLUDE_PATH . '/aiwatch.php';
    echo AiWatch::render(AiWatch::forSeller((int) $admin['id']), '🤖 AI Watch — your desk');
}

if ($scopeId !== null) {
    echo '<p class="muted" style="font-size:12.5px;margin:-6px 0 14px">'
       . 'Showing only the tickets you sold. Company-wide figures are not part of the agent portal.</p>';
}

// Build export / paging query string with all current filters
$exportBase = array_filter([
    'q'           => $q,
    'status'      => $status,
    'from'        => $isDate($from) ? $from : '',
    'to'          => $isDate($to) ? $to : '',
    'route'       => $routeFilter,
    'travel_from' => $isDate($travelFrom) ? $travelFrom : '',
    'travel_to'   => $isDate($travelTo) ? $travelTo : '',
    'source'      => $sourceFilter,
    'agent'       => ($scopeId === null) ? $agentFilter : '',
    'bus'         => $busFilter,
    'pay_status'  => in_array($payStatusFilter, $validPayStatus, true) ? $payStatusFilter : '',
    'pay_method'  => in_array($payMethodFilter, $validPayMethod, true) ? $payMethodFilter : '',
    // 5 Oct 2026: the Counter filter was missing here - page 2 and every
    // export silently dropped it.
    'counter'     => $counterFilter,
    'phone'       => $phoneFilter,
    'reach'       => $reachFilter,
    // 15 Oct 2026: the column funnels and "only my sales" (export.php reads them too).
    'f_ticket'    => $cf['f_ticket'],
    'f_pax'       => $cf['f_pax'],
    'f_mob'       => $cf['f_mob'],
    'f_seat'      => $cf['f_seat'],
    'mine'        => $cf['mine'],
]);
$colFiltersOn = $cf['f_ticket'] !== '' || $cf['f_pax'] !== '' || $cf['f_mob'] !== '' || $cf['f_seat'] !== '';
$hasFilters = ($q !== '' || $status !== '' || $isDate($from) || $isDate($to)
    || $routeFilter !== '' || $isDate($travelFrom) || $isDate($travelTo)
    || $sourceFilter !== '' || ($scopeId === null && $agentFilter !== '')
    || $busFilter !== '' || in_array($payStatusFilter, $validPayStatus, true)
    || in_array($payMethodFilter, $validPayMethod, true)
    || $counterFilter !== '' || $phoneFilter !== '' || $reachFilter !== ''
    || $colFiltersOn || $cf['mine'] === '1');
/* The view's own extras (rows per page, sort; 15 Oct 2026: hisab, all
   columns, phone table) ride on paging and sort links only - an export is
   the whole filtered list whatever the page shows. */
$viewModes = array_filter([
    'hisab' => $hisabOn ? '1' : '',
    'cols'  => $colsAll ? 'all' : '',
    'view'  => $viewTable ? 'table' : '',
]);
$viewBase = $exportBase + array_filter([
    'pp'   => $PER_PAGE !== 50 ? $PER_PAGE : '',
    'sort' => $sortKey !== 'new' ? $sortKey : '',
    'dir'  => ($sortKey !== 'new' || $sortDir !== 'DESC') ? strtolower($sortDir) : '',
]) + $viewModes;
/** This page with one view setting flipped (and back to page 1). */
$toggleUrl = static fn(string $key, string $val): string => $base . '/admin/bookings.php?'
    . http_build_query(array_diff_key($viewBase, [$key => 1, 'p' => 1]) + ($val !== '' ? [$key => $val] : []));
$pageUrl = static fn(int $p): string => $base . '/admin/bookings.php?' . http_build_query($viewBase + ['p' => $p]);
/**
 * The funnel under a column head (15 Oct 2026, Excel-style): a button that
 * opens a tiny inline GET form for that column's filter(s), carrying every
 * other filter and view setting along. $fields = [[param, placeholder, inputmode], ...]
 */
$filterBox = static function (string $id, string $label, array $fields) use ($base, $viewBase, $cf): string {
    $own  = array_fill_keys(array_column($fields, 0), 1);
    $isOn = false;
    $html = '<form method="get" action="' . $base . '/admin/bookings.php" class="th-fbox" id="' . $id . '" hidden>';
    foreach (array_diff_key($viewBase, $own + ['p' => 1]) as $hk => $hv) {
        $html .= '<input type="hidden" name="' . Security::e((string) $hk) . '" value="' . Security::e((string) $hv) . '">';
    }
    foreach ($fields as [$name, $ph, $mode]) {
        $val   = (string) ($cf[$name] ?? '');
        $isOn  = $isOn || $val !== '';
        $html .= '<input type="search" name="' . $name . '" value="' . Security::e($val) . '" placeholder="' . Security::e($ph) . '"'
               . ' inputmode="' . $mode . '" maxlength="40" aria-label="' . Security::e($label . ': ' . $ph) . '">';
    }
    $html .= '<span class="th-fbtns"><button class="btn" type="submit">Filter</button>';
    if ($isOn) {
        $html .= '<a class="btn ghost" href="' . Security::e($base . '/admin/bookings.php?' . http_build_query(array_diff_key($viewBase, $own + ['p' => 1]))) . '">Clear</a>';
    }
    $html .= '</span></form>';
    return '<button type="button" class="th-f' . ($isOn ? ' on' : '') . '" data-fbox="' . $id . '" aria-expanded="false" aria-controls="' . $id . '"'
         . ' title="Filter ' . Security::e(strtolower($label)) . '" aria-label="Filter ' . Security::e(strtolower($label)) . '">'
         . '<svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true"><path d="M1 2h14l-5.5 6.5V14l-3-1.5V8.5z" fill="currentColor"/></svg></button>'
         . $html;
};
/** A sort link: first tap = newest/highest first, second tap flips. */
$sortLink = static function (string $key, string $label) use ($base, $viewBase, $sortKey, $sortDir): string {
    $on   = $sortKey === $key;
    $next = ($on && $sortDir === 'DESC') ? 'asc' : 'desc';
    $url  = $base . '/admin/bookings.php?' . http_build_query(array_diff_key($viewBase, ['sort' => 1, 'dir' => 1, 'p' => 1]) + ['sort' => $key, 'dir' => $next]);
    return '<a' . ($on ? ' class="on"' : '') . ' href="' . Security::e($url) . '" title="Sort by ' . Security::e(strtolower($label)) . '">' . Security::e($label)
         . '<span aria-hidden="true">' . ($on ? ($sortDir === 'ASC' ? ' ▲' : ' ▼') : ' ↕') . '</span></a>';
};
/** A sortable column head (one or more sort links, then an optional funnel). */
$sortTh = static function (string $key, string $label, string $funnel = '', string $more = '', string $cls = '') use ($sortLink, $sortKey, $sortDir): string {
    $on = $sortKey === $key;
    return '<th class="th-sort' . ($on ? ' on' : '') . ($cls !== '' ? ' ' . $cls : '') . '"' . ($on ? ' aria-sort="' . ($sortDir === 'ASC' ? 'ascending' : 'descending') . '"' : '') . '>'
         . $sortLink($key, $label) . $more . $funnel . '</th>';
};
/* Quick date chips for the BOOKED date (Today / Yesterday / This week / This
   month / Last month), keeping every other filter that is on. */
$__today  = todayISO();
$__monday = addDaysISO($__today, -((int) date('N', (int) strtotime($__today)) - 1));
$__m1     = substr($__today, 0, 8) . '01';
$__lmEnd  = addDaysISO($__m1, -1);
$datePresets = [
    'Today'      => [$__today, $__today],
    'Yesterday'  => [addDaysISO($__today, -1), addDaysISO($__today, -1)],
    'This week'  => [$__monday, $__today],
    'This month' => [$__m1, $__today],
    'Last month' => [substr($__lmEnd, 0, 8) . '01', $__lmEnd],
];
$presetUrl = static fn(string $f, string $t): string => $base . '/admin/bookings.php?'
    . http_build_query(array_diff_key($viewBase, ['from' => 1, 'to' => 1, 'p' => 1]) + ['from' => $f, 'to' => $t]);
/* 15 Oct 2026: the chalani is read by TRAVEL date - "who is on today's bus".
   These chips set the travel day and drop the booked range. */
$travelPresets = [
    "Today's departures"    => $__today,
    "Tomorrow's departures" => addDaysISO($__today, 1),
];
$travelUrl = static fn(string $d): string => $base . '/admin/bookings.php?'
    . http_build_query(array_diff_key($viewBase, ['from' => 1, 'to' => 1, 'travel_from' => 1, 'travel_to' => 1, 'p' => 1]) + ['travel_from' => $d, 'travel_to' => $d]);
// The month the picker shows: the one picked, or the booked range when it is exactly one calendar month.
$monthShown = $monthPick !== '' ? $monthPick
    : (($isDate($from) && substr($from, 8) === '01' && $isDate($to) && substr($to, 0, 7) === substr($from, 0, 7)
        && ($to === date('Y-m-t', (int) strtotime($from)) || $to === $__today)) ? substr($from, 0, 7) : '');
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;
// 4 Sep 2026: bookings.cancel (office — any ticket) OR bookings.cancel_own
// (agent — only tickets they sold; this list is already scoped to their own
// sales, so every open row they can see is one they may cancel). The single
// Cancel button posts to booking-view.php, which re-checks per booking; the
// bulk bar posts to admin/api/bulk-cancel.php, which re-checks per PNR.
$canCancel = Auth::mayCancelAny();
$canBulk   = $canCancel;
$canResend = Auth::can('payments.verify');
$canEdit   = Auth::can('bookings.edit');
?>

<style>
.filter-group{display:flex;flex-direction:column;gap:3px}
.date-chips{display:flex;gap:6px;flex-wrap:wrap;margin:2px 0 10px}
.date-chips .chip{min-height:36px;padding:0 13px;text-decoration:none}
.date-chips .chip.on{background:var(--blue);color:#fff;border-color:var(--blue)}
/* 15 Oct 2026 (chalani register): chip groups that stay visible on a phone, the
   month picker, column funnels, the hisab, the counter's day and the phone sheet. */
.quick-row{display:flex;gap:8px 16px;flex-wrap:wrap;align-items:center;margin:0 0 12px}
.quick-row .date-chips{margin:0}
.quick-row .qgrp{display:flex;gap:6px;align-items:center;flex-wrap:wrap;min-width:0}
.quick-row .qlbl{font-size:11px;color:var(--mut);font-weight:700;text-transform:uppercase;letter-spacing:.3px;align-self:center}
.month-pick{display:inline-flex;gap:6px;align-items:center;margin:0}
.month-pick input{min-height:36px;padding:4px 8px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);max-width:150px}
.month-pick .btn{min-height:36px;padding:0 12px;font-size:12.5px}
.th-f{display:inline-grid;place-items:center;width:24px;height:24px;margin-left:4px;padding:0;border:1px solid transparent;border-radius:6px;background:transparent;color:var(--mut);cursor:pointer;vertical-align:middle}
.th-f:hover,.th-f[aria-expanded="true"]{border-color:var(--line);color:var(--ink)}
.th-f.on{background:var(--blue);color:#fff;border-color:var(--blue)}
.th-fbox{display:flex;flex-direction:column;gap:5px;margin-top:6px;min-width:150px;max-width:220px;text-transform:none;letter-spacing:0;font-weight:400}
.th-fbox[hidden]{display:none}
.th-fbox input[type=search]{padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:var(--card);color:var(--ink);width:100%;min-width:0}
.th-fbtns{display:flex;gap:5px}.th-fbtns .btn{padding:4px 10px;font-size:12px}
.colf-chips{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 10px}
.colf-chips a{font-size:12px;padding:4px 10px;border-radius:999px;background:var(--hover,#eef2fa);color:var(--ink);text-decoration:none;border:1px solid var(--line)}
.view-pick{display:none;gap:4px;align-items:center;font-size:12.5px}
.view-pick a{min-height:32px;padding:0 10px;display:inline-grid;place-items:center;border-radius:8px;border:1px solid var(--line);text-decoration:none;color:var(--ink-2);font-weight:700}
.view-pick a.on{background:var(--royal);color:var(--on-royal);border-color:var(--royal)}
.card a.card-link{color:inherit;text-decoration:none;display:block}
.card .sub{font-size:12px;color:var(--mut);margin-top:4px}
.hisab{margin-bottom:18px}
.hisab .h-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(130px,100%),1fr));gap:10px;margin:8px 0 12px}
.hisab .h-grid div{border:1px solid var(--line);border-radius:12px;padding:10px 12px;background:var(--card)}
.hisab .h-grid b{display:block;font-size:18px;font-variant-numeric:tabular-nums}
.hisab .h-grid span{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.06em;font-weight:700}
.hisab td.num,.hisab th.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.hisab td:first-child{min-width:150px}
.desk-today{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin:0 0 16px}
.desk-today h2{font-size:15px;margin:0 0 10px}
.dk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(130px,100%),1fr));gap:10px}
.dk-tile{display:flex;flex-direction:column;gap:2px;border:1px solid var(--line);border-radius:12px;padding:10px 12px;text-decoration:none;color:var(--ink);min-width:0}
.dk-tile .k{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.06em;font-weight:700}
.dk-tile .v{font-size:17px;font-weight:800;font-variant-numeric:tabular-nums}
.dk-tile .s{font-size:12px;color:var(--mut);overflow-wrap:anywhere}
.dk-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.dk-acts .btn{min-height:40px}
table.tk-sheet th,table.tk-sheet td{white-space:nowrap}
table.tk-sheet .tk-first{position:sticky;left:0;z-index:1;background:var(--card);box-shadow:1px 0 0 var(--line)}
table.tk-sheet thead .tk-first{z-index:2;background:var(--head,var(--card))}
.th-sort a{color:inherit;text-decoration:none;white-space:nowrap;display:inline-flex;align-items:center;gap:2px}
.th-sort a span{opacity:.45;font-size:10px}.th-sort a.on{color:var(--blue)}.th-sort a.on span{opacity:1}
.th-sort .th-more{margin-left:6px;font-weight:400}
.pp-pick{display:inline-flex;gap:4px;align-items:center;font-size:12.5px}
.pp-pick a{min-width:36px;min-height:32px;display:inline-grid;place-items:center;border-radius:8px;border:1px solid var(--line);text-decoration:none;color:var(--ink-2);font-weight:700}
.pp-pick a.on{background:var(--royal);color:var(--on-royal);border-color:var(--royal)}
.filter-group .flbl{font-size:11px;color:var(--mut);font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.filter-row + .filter-row{margin-top:8px}
.filter-actions{display:flex;gap:8px;align-items:flex-end;margin-left:auto}
.export-links{display:flex;gap:8px;align-items:center}
.tk-acts{display:flex;gap:4px;flex-wrap:wrap}
.tk-acts .btn,.tk-acts form .btn{padding:5px 8px;font-size:12px;white-space:nowrap}
.tk-acts form{display:inline}
.pager{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:10px 14px;border-top:1px solid var(--line);font-size:13px}
.pager .btn{padding:6px 10px;font-size:12.5px}
@media(max-width:820px){
  .filter-actions{margin-left:0;width:100%}
  .export-links{width:100%;justify-content:flex-start}
  .filter-group{min-width:0;flex:1 1 140px}
  .tk-acts{justify-content:flex-start}
  .view-pick{display:inline-flex}
  table.tk-sheet .tk-acts{flex-wrap:nowrap}
  /* Phones: each chip group is ONE swipeable line, not a wall of chips above the search. */
  .quick-row{gap:6px}
  .quick-row .qgrp{width:100%;flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;scrollbar-width:none;padding-bottom:2px}
  .quick-row .qgrp::-webkit-scrollbar{display:none}
  .quick-row .qgrp .date-chips{flex-wrap:nowrap}
  .quick-row .chip,.quick-row .qlbl{white-space:nowrap;flex:0 0 auto}
  .quick-row .month-pick{flex:0 0 auto}
}
@media(pointer:coarse){.tk-acts .btn,.tk-acts form .btn{min-height:44px}.th-f{width:32px;height:32px}}
:root[data-theme="dark"] .pill{opacity:.92}
</style>

<?php /* 15 Oct 2026: the quick chips sit ABOVE the folded "Dates & export" box, so a
   phone sees Today / Yesterday / This week / This month without opening it;
   travel-day chips for the chalani, the month picker and the one-click hisab. */ ?>
<div class="quick-row">
  <div class="qgrp">
  <span class="qlbl">Booked</span>
  <div class="date-chips" role="group" aria-label="Booked date - quick ranges">
    <?php foreach ($datePresets as $__lbl => [$__f, $__t]): $__on = ($from === $__f && $to === $__t); ?>
      <a class="chip<?= $__on ? ' on' : '' ?>" href="<?= Security::e($presetUrl($__f, $__t)) ?>"<?= $__on ? ' aria-current="true"' : '' ?>><?= Security::e($__lbl) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="month-pick" aria-label="Any month (booked date)">
    <?php foreach (array_diff_key($viewBase, ['from' => 1, 'to' => 1, 'p' => 1]) as $__hk => $__hv): ?>
      <input type="hidden" name="<?= Security::e((string) $__hk) ?>" value="<?= Security::e((string) $__hv) ?>">
    <?php endforeach; ?>
    <input type="month" name="month" value="<?= Security::e($monthShown) ?>" max="<?= Security::e(substr($__today, 0, 7)) ?>" aria-label="Month" onchange="if(this.value)this.form.submit()">
    <button class="btn ghost" type="submit">Month</button>
  </form>
  </div>
  <div class="qgrp">
  <span class="qlbl">Travel</span>
  <div class="date-chips" role="group" aria-label="Travel date - departures">
    <?php foreach ($travelPresets as $__lbl => $__d): $__on = ($travelFrom === $__d && $travelTo === $__d); ?>
      <a class="chip<?= $__on ? ' on' : '' ?>" href="<?= Security::e($travelUrl($__d)) ?>"<?= $__on ? ' aria-current="true"' : '' ?>><?= Security::e($__lbl) ?></a>
    <?php endforeach; ?>
  </div>
  </div>
  <div class="qgrp date-chips" role="group" aria-label="Views">
    <a class="chip<?= $hisabOn ? ' on' : '' ?>" href="<?= Security::e($toggleUrl('hisab', $hisabOn ? '' : '1')) ?>"<?= $hisabOn ? ' aria-current="true"' : '' ?> title="Bookings, passengers, revenue, refunds and money still to come in - for exactly the filters on screen">📒 Monthly hisab</a>
    <a class="chip<?= $colsAll ? ' on' : '' ?>" href="<?= Security::e($toggleUrl('cols', $colsAll ? '' : 'all')) ?>"<?= $colsAll ? ' aria-current="true"' : '' ?> title="Show every column: stops, desk, email, cabin, confirmed at, refund">Columns: <?= $colsAll ? 'All' : 'Minimal' ?></a>
    <?php if ($scopeId === null || $cf['mine'] === '1'): ?>
    <a class="chip<?= $cf['mine'] === '1' ? ' on' : '' ?>" href="<?= Security::e($toggleUrl('mine', $cf['mine'] === '1' ? '' : '1')) ?>"<?= $cf['mine'] === '1' ? ' aria-current="true"' : '' ?>>Only my sales</a>
    <?php endif; ?>
  </div>
</div>

<form method="get" style="margin-bottom:16px">
  <?php /* View settings and "only my sales" survive a new search (the column filters have their own inputs below). */
  foreach (array_filter(['mine' => $cf['mine']]) + $viewModes as $__hk => $__hv): ?>
  <input type="hidden" name="<?= Security::e((string) $__hk) ?>" value="<?= Security::e((string) $__hv) ?>">
  <?php endforeach; ?>
  <div class="panel" style="padding:14px 16px">
    <div class="filter-row">
      <div class="filter-group" style="flex:2 1 240px">
        <span class="flbl">Search</span>
        <input type="search" name="q" placeholder="Ticket no, PNR, name, phone, seat (A7 / L5), bus, agent" value="<?= Security::e($q) ?>" style="width:100%" title="Search by ticket number, PNR, passenger name, phone, email, seat (as printed, A7 / B5-6, or stored, L5), bus number or agent">
      </div>
      <div class="filter-group">
        <span class="flbl">Status</span>
        <select name="status">
          <option value="">All statuses</option>
          <?php foreach ($valid as $s): ?>
            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <span class="flbl">Route</span>
        <select name="route">
          <option value="">All routes</option>
          <?php foreach ($allRoutes as $rt): ?>
            <option value="<?= (int) $rt['id'] ?>" <?= $routeFilter === (string) $rt['id'] ? 'selected' : '' ?>><?= Security::e($rt['from_city'] . ' → ' . $rt['to_city']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <span class="flbl">Bus</span>
        <select name="bus">
          <option value="">All buses</option>
          <?php foreach ($allBuses as $bu): ?>
            <option value="<?= Security::e($bu['bus_number']) ?>" <?= $busFilter === $bu['bus_number'] ? 'selected' : '' ?>><?= Security::e($bu['bus_number']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($scopeId === null && $allAgents !== []): ?>
      <div class="filter-group">
        <span class="flbl">Agent</span>
        <select name="agent">
          <option value="">All agents</option>
          <?php foreach ($allAgents as $ag):
              $code = format_agent_code($agentCodes, (int) $ag['id']);
              $label = ($code !== '' ? $code . ' ' : '') . ($ag['full_name'] ?: $ag['username']);
          ?>
            <option value="<?= (int) $ag['id'] ?>" <?= $agentFilter === (string) $ag['id'] ? 'selected' : '' ?>><?= Security::e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <?php if ($scopeId === null && CounterDesk::stampColumn() && CounterDesk::all(true) !== []): ?>
      <div class="filter-group">
        <span class="flbl">Counter</span>
        <select name="counter">
          <option value="">All counters</option>
          <?php foreach (CounterDesk::all() as $cCode => $cD): ?>
            <option value="<?= Security::e((string) $cCode) ?>" <?= $counterFilter === (string) $cCode ? 'selected' : '' ?>>
              <?= CounterDesk::flag((string) $cD['country']) ?> <?= Security::e($cD['name']) ?> (<?= Security::e((string) $cCode) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="filter-group">
        <span class="flbl">Sold via</span>
        <select name="source">
          <option value="">All</option>
          <option value="online" <?= $sourceFilter === 'online' ? 'selected' : '' ?>>Online</option>
          <option value="agent" <?= $sourceFilter === 'agent' ? 'selected' : '' ?>>Agent</option>
          <option value="counter" <?= $sourceFilter === 'counter' ? 'selected' : '' ?>>Counter</option>
          <option value="admin" <?= $sourceFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
          <option value="whatsapp" <?= $sourceFilter === 'whatsapp' ? 'selected' : '' ?>>WhatsApp request</option>
          <option value="ads" <?= $sourceFilter === 'ads' ? 'selected' : '' ?>>Google Ads</option>
        </select>
      </div>
      <div class="filter-group">
        <span class="flbl">Payment</span>
        <select name="pay_status">
          <option value="">Any status</option>
          <?php
          $payStatusLabels = [
              'pending'     => 'Unverified',
              'verified'    => 'Paid / verified',
              'rejected'    => 'Rejected',
              'refunded'    => 'Refunded',
              'cod_pending' => 'Cash due (COD)',
          ];
          foreach ($validPayStatus as $ps): ?>
            <option value="<?= $ps ?>" <?= $payStatusFilter === $ps ? 'selected' : '' ?>><?= Security::e($payStatusLabels[$ps] ?? $ps) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <span class="flbl">Method</span>
        <select name="pay_method">
          <option value="">Any method</option>
          <?php foreach ($validPayMethod as $pm): ?>
            <option value="<?= $pm ?>" <?= $payMethodFilter === $pm ? 'selected' : '' ?>><?= strtoupper($pm) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <span class="flbl">Customer number</span>
        <select name="phone">
          <option value="">Any</option>
          <option value="ok" <?= $phoneFilter === 'ok' ? 'selected' : '' ?>>✅ Has a number</option>
          <option value="none" <?= $phoneFilter === 'none' ? 'selected' : '' ?>>📵 No number</option>
          <option value="bad" <?= $phoneFilter === 'bad' ? 'selected' : '' ?>>⚠️ Wrong number</option>
        </select>
      </div>
      <div class="filter-group">
        <span class="flbl">WhatsApp ticket</span>
        <select name="reach">
          <option value="">Any</option>
          <option value="got" <?= $reachFilter === 'got' ? 'selected' : '' ?>>✅ Customer got it</option>
          <option value="agent" <?= $reachFilter === 'agent' ? 'selected' : '' ?>>🧾 Counter / agent got it</option>
          <option value="nobody" <?= $reachFilter === 'nobody' ? 'selected' : '' ?>>❌ Nobody got it</option>
        </select>
      </div>
    </div>

    <details class="advanced-section" id="advFilters" open>
    <summary style="font-size:12px;font-weight:600;color:var(--blue);cursor:pointer;margin:8px 0 4px;list-style:none">📅 Dates, column filters &amp; export</summary>
    <div class="filter-row">
      <?php /* 15 Oct 2026: the column funnels as plain fields too - a phone in card view has no table heads to tap. */ ?>
      <div class="filter-group">
        <span class="flbl">Ticket / PNR</span>
        <input type="search" name="f_ticket" value="<?= Security::e($cf['f_ticket']) ?>" maxlength="40" placeholder="e.g. 00056">
      </div>
      <div class="filter-group">
        <span class="flbl">Passenger name</span>
        <input type="search" name="f_pax" value="<?= Security::e($cf['f_pax']) ?>" maxlength="60" placeholder="Name">
      </div>
      <div class="filter-group">
        <span class="flbl">Mobile</span>
        <input type="search" name="f_mob" value="<?= Security::e($cf['f_mob']) ?>" maxlength="20" inputmode="numeric" placeholder="Digits">
      </div>
      <div class="filter-group">
        <span class="flbl">Seat</span>
        <input type="search" name="f_seat" value="<?= Security::e($cf['f_seat']) ?>" maxlength="16" placeholder="A7 / B5-6 / L5" title="The seat as printed on the ticket (A7, B5-6) or as stored (L5)">
      </div>
    </div>
    <div class="filter-row">
      <div class="filter-group">
        <span class="flbl">Booked from</span>
        <input type="date" name="from" value="<?= Security::e($isDate($from) ? $from : '') ?>">
      </div>
      <div class="filter-group">
        <span class="flbl">Booked to</span>
        <input type="date" name="to" value="<?= Security::e($isDate($to) ? $to : '') ?>">
      </div>
      <div class="filter-group">
        <span class="flbl">Travel from</span>
        <input type="date" name="travel_from" value="<?= Security::e($isDate($travelFrom) ? $travelFrom : '') ?>">
      </div>
      <div class="filter-group">
        <span class="flbl">Travel to</span>
        <input type="date" name="travel_to" value="<?= Security::e($isDate($travelTo) ? $travelTo : '') ?>">
      </div>

      <div class="filter-actions">
        <button class="btn" type="submit">Search</button>
        <?php if ($hasFilters): ?><a class="btn ghost" href="<?= $base ?>/admin/bookings.php">Clear</a><?php endif; ?>
      </div>
    </div>

    <?php /* $mayExport: an agent's own sales, or reports.export (set above, with the hisab file). */ ?>
    <div class="filter-row" style="margin-top:10px"<?= $mayExport ? '' : ' hidden' ?>>
      <div class="export-links">
        <a class="btn ghost" style="font-size:12px;padding:6px 10px" href="<?= $base ?>/admin/export.php?<?= Security::e(http_build_query($exportBase)) ?>">⬇️ Export CSV</a>
        <a class="btn ghost" style="font-size:12px;padding:6px 10px" href="<?= $base ?>/admin/export.php?<?= Security::e(http_build_query($exportBase + ['format' => 'xlsx'])) ?>">⬇️ Export Excel</a>
        <?php if ($counterFilter === '' && $phoneFilter === '' && $reachFilter === '' && !in_array($payMethodFilter, $validPayMethod, true)): /* the PDF report knows fewer filters - never offer a file that would not match the screen */ ?>
        <a class="btn ghost" style="font-size:12px;padding:6px 10px" href="<?= $base ?>/admin/export.php?<?= Security::e(http_build_query($exportBase + ['format' => 'pdf'])) ?>">⬇️ Export PDF</a>
        <?php endif; ?>
      </div>
    </div>
    </details>
  </div>
</form>
<script>
/* Phones: fold the date filters + export links away unless one is in use. */
(function () {
  var d = document.getElementById('advFilters'); if (!d) return;
  var used = <?= ($isDate($from) || $isDate($to) || $isDate($travelFrom) || $isDate($travelTo) || $colFiltersOn) ? 'true' : 'false' ?>;
  if (!used && window.matchMedia('(max-width:820px)').matches) { d.removeAttribute('open'); }
})();
</script>

<!-- Summary Cards (whole filtered set) -->
<div class="cards">
  <div class="card">
    <div class="k">Tickets</div>
    <div class="v"><?= number_format($totalResults) ?></div>
  </div>
  <div class="card">
    <div class="k">Confirmed</div>
    <div class="v" style="color:var(--success)"><?= number_format($confirmedCount) ?></div>
  </div>
  <div class="card">
    <div class="k">Pending</div>
    <div class="v" style="color:var(--warning)"><?= number_format($pendingCount) ?></div>
  </div>
  <?php
  /* 15 Oct 2026 (3B): every card follows the filters above, from the same
     $agg pass. Money cards: see $showMoney. Refunds are read-only here - the
     owner settles them by hand on the Refund desk. */
  $cardUrl = static fn(array $set): string => $base . '/admin/bookings.php?' . http_build_query(array_diff_key($viewBase, $set + ['p' => 1]) + $set);
  $mayRefunds = Auth::can('refunds.view');
  ?>
  <div class="card">
    <div class="k">Passengers</div>
    <div class="v"><?= number_format((int) $agg['pax']) ?></div>
    <div class="sub">live seats on these tickets</div>
  </div>
  <?php if ($showMoney): ?>
  <div class="card">
    <div class="k">Revenue</div>
    <div class="v"><?= Security::e(inr($confirmedRevenue)) ?></div>
  </div>
  <div class="card">
    <a class="card-link" href="<?= Security::e($cardUrl(['pay_status' => 'verified'])) ?>" title="UPI, eSewa, bank, wallet - verified">
      <div class="k">Online paid</div>
      <div class="v" style="color:var(--success)"><?= Security::e(inr((float) $agg['online_paid'])) ?></div>
      <div class="sub"><?= number_format((int) $agg['online_n']) ?> ticket<?= (int) $agg['online_n'] === 1 ? '' : 's' ?> · verified, not cash</div>
    </a>
  </div>
  <?php endif; ?>
  <div class="card">
    <a class="card-link" href="<?= Security::e($cardUrl(['pay_status' => 'cod_pending'])) ?>" title="Pay-at-boarding tickets whose cash is not in yet">
      <div class="k">Cash pending</div>
      <div class="v" style="color:var(--warning)"><?= Security::e(inr((float) $agg['cash_due'])) ?></div>
      <div class="sub"><?= number_format((int) $agg['cash_due_n']) ?> ticket<?= (int) $agg['cash_due_n'] === 1 ? '' : 's' ?> to collect</div>
    </a>
  </div>
  <div class="card">
    <a class="card-link" href="<?= Security::e($cardUrl(['status' => 'cancelled'])) ?>">
      <div class="k">Cancelled</div>
      <div class="v"><?= number_format((int) $agg['cancelled']) ?></div>
      <div class="sub"><?= $showMoney ? Security::e(inr((float) $agg['cancelled_amt'])) . ' of tickets' : 'tickets' ?></div>
    </a>
  </div>
  <div class="card">
    <?php if ($mayRefunds): ?><a class="card-link" href="<?= $base ?>/admin/refunds.php" title="The Refund desk - settled by the office"><?php endif; ?>
      <div class="k">Refunded</div>
      <div class="v"><?= ($showMoney || $mayRefunds) ? Security::e(inr((float) $agg['refunded'])) : '—' ?></div>
      <div class="sub"><?= number_format((int) $agg['refund_pending_n']) ?> pending<?= ($showMoney || $mayRefunds) ? ' · ' . Security::e(inr((float) $agg['refund_pending_amt'])) : '' ?></div>
    <?php if ($mayRefunds): ?></a><?php endif; ?>
  </div>
</div>

<?php if ($hisab !== null): $ht = $hisab['tot']; ?>
<!-- Monthly hisab (15 Oct 2026): one read-only query over the filters on screen. -->
<section class="panel hisab" aria-label="Monthly hisab">
  <h2 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><span>📒 Hisab <span class="muted" style="font-weight:400">· <?= Security::e($hisab['window']) ?></span></span>
    <?php if ($mayExport): ?>
    <span style="margin-left:auto;display:inline-flex;gap:6px">
      <a class="btn ghost" style="font-size:12px;padding:6px 10px" href="<?= Security::e($base . '/admin/bookings.php?' . http_build_query(array_diff_key($viewBase, ['p' => 1]) + ['hx' => 'csv'])) ?>">⬇️ CSV</a>
      <a class="btn ghost" style="font-size:12px;padding:6px 10px" href="<?= Security::e($base . '/admin/bookings.php?' . http_build_query(array_diff_key($viewBase, ['p' => 1]) + ['hx' => 'xlsx'])) ?>">⬇️ Excel</a>
    </span>
    <?php endif; ?>
  </h2>
  <div class="h-grid">
    <div><span>Tickets</span><b><?= number_format((int) $ht['n']) ?></b></div>
    <div><span>Passengers</span><b><?= number_format((int) $ht['pax']) ?></b></div>
    <?php if ($showMoney): ?><div><span>Revenue</span><b><?= Security::e(inr((float) $ht['revenue'])) ?></b></div><?php endif; ?>
    <div><span>Cancelled</span><b><?= number_format((int) $ht['cancelled']) ?></b></div>
    <?php if ($showMoney): ?>
    <div><span>Refunds paid</span><b><?= Security::e(inr((float) $ht['ref_done'])) ?></b></div>
    <div><span>Refunds pending</span><b><?= number_format((int) $ht['ref_pend_n']) ?> · <?= Security::e(inr((float) $ht['ref_pend'])) ?></b></div>
    <div><span>Cash due (boarding)</span><b><?= number_format((int) $ht['cash_due_n']) ?> · <?= Security::e(inr((float) $ht['cash_due'])) ?></b></div>
    <div><span>Unverified online</span><b><?= number_format((int) $ht['unver_n']) ?> · <?= Security::e(inr((float) $ht['unver'])) ?></b></div>
    <?php else: ?>
    <div><span>Refunds pending</span><b><?= number_format((int) $ht['ref_pend_n']) ?></b></div>
    <div><span>Cash due (boarding)</span><b><?= number_format((int) $ht['cash_due_n']) ?></b></div>
    <div><span>Unverified online</span><b><?= number_format((int) $ht['unver_n']) ?></b></div>
    <?php endif; ?>
  </div>
  <?php if (!$showMoney): ?><p class="muted" style="font-size:12.5px;margin:0 0 8px">Money figures are for the accounts desk. Tap <b>Only my sales</b> for your own hisab.</p><?php endif; ?>
  <?php if ($hisabBySeller && $hisab['sellers'] !== []): ?>
  <div class="tbl-scroll">
  <table>
    <thead><tr><th>Seller</th><th class="num">Tickets</th><th class="num">Pax</th><th class="num">Revenue</th><th class="num">Cancelled</th><th class="num">Refunds paid</th><th class="num">Refunds pending</th><th class="num">Cash due</th><th class="num">Unverified</th></tr></thead>
    <tbody>
    <?php foreach ($hisab['sellers'] as $hs): ?>
      <tr>
        <td data-label="Seller"><?= Security::e((string) $hs['label']) ?></td>
        <td data-label="Tickets" class="num"><?= number_format((int) $hs['n']) ?></td>
        <td data-label="Pax" class="num"><?= number_format((int) $hs['pax']) ?></td>
        <td data-label="Revenue" class="num"><?= Security::e(inr((float) $hs['revenue'])) ?></td>
        <td data-label="Cancelled" class="num"><?= number_format((int) $hs['cancelled']) ?></td>
        <td data-label="Refunds paid" class="num"><?= Security::e(inr((float) $hs['ref_done'])) ?></td>
        <td data-label="Refunds pending" class="num"><?= (int) $hs['ref_pend_n'] ?> · <?= Security::e(inr((float) $hs['ref_pend'])) ?></td>
        <td data-label="Cash due" class="num"><?= (int) $hs['cash_due_n'] ?> · <?= Security::e(inr((float) $hs['cash_due'])) ?></td>
        <td data-label="Unverified" class="num"><?= (int) $hs['unver_n'] ?> · <?= Security::e(inr((float) $hs['unver'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
  <p class="muted" style="font-size:12px;margin:8px 0 0">Display only. Refunds and cancellations are settled by the office, by hand.</p>
</section>
<?php endif; ?>

<?php if ($colFiltersOn): /* What the column funnels are holding, one tap to drop each. */ ?>
<div class="colf-chips" aria-label="Column filters on">
  <?php foreach (['f_ticket' => 'Ticket', 'f_pax' => 'Passenger', 'f_mob' => 'Mobile', 'f_seat' => 'Seat'] as $__fk => $__fl): if ($cf[$__fk] === '') { continue; } ?>
    <a href="<?= Security::e($toggleUrl($__fk, '')) ?>" title="Remove this filter"><?= Security::e($__fl . ': ' . $cf[$__fk]) ?> ✕</a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h2 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap"><span><?= number_format($totalResults) ?> ticket<?= $totalResults === 1 ? '' : 's' ?><?= $pages > 1 ? ' · page ' . $page . ' of ' . $pages : '' ?></span>
    <span class="view-pick" role="group" aria-label="Phone layout"><a class="<?= $viewTable ? 'on' : '' ?>" href="<?= Security::e($toggleUrl('view', 'table')) ?>">Table</a><a class="<?= $viewTable ? '' : 'on' ?>" href="<?= Security::e($toggleUrl('view', '')) ?>">Cards</a></span>
    <span class="pp-pick" style="margin-left:auto" role="group" aria-label="Rows per page"><span class="muted">Rows</span><?php foreach ([25, 50, 100] as $__pp): ?><a class="<?= $PER_PAGE === $__pp ? 'on' : '' ?>" href="<?= Security::e($base . '/admin/bookings.php?' . http_build_query(array_diff_key($viewBase, ['pp' => 1, 'p' => 1]) + ($__pp !== 50 ? ['pp' => $__pp] : []))) ?>"><?= $__pp ?></a><?php endforeach; ?></span></h2>
  <div class="tbl-scroll">
  <?php /* ?view=table: no-card keeps the phone card treatment off, so the register scrolls sideways
     like a spreadsheet with the Ticket column pinned (15 Oct 2026). */ ?>
  <table class="<?= $viewTable ? 'no-card tk-sheet' : 'card-table' ?>">
    <thead><tr><?php if ($canBulk): ?><th class="bulk-col"><input type="checkbox" id="bulkAll" aria-label="Select every open ticket on this page" title="Select all open tickets on this page"></th><?php endif; ?><?= $sortTh('ticket', 'Ticket', $filterBox('fbTicket', 'Ticket', [['f_ticket', 'Ticket no / PNR', 'text']]), '', 'tk-first') ?><?= $sortTh('booked', 'Booked') ?><?= $sortTh('travel', 'Travel', '', '<span class="th-more">' . $sortLink('route', 'Route') . '</span>') ?><?= $sortTh('pax', 'Passenger', $filterBox('fbPax', 'Passenger', [['f_pax', 'Name', 'text'], ['f_mob', 'Mobile', 'numeric']])) ?><th>Seats<?= $filterBox('fbSeat', 'Seat', [['f_seat', 'A7 / B5-6 / L5', 'text']]) ?></th><?= $sortTh('seller', 'Sold via') ?><?= $sortTh('pay', 'Payment') ?><?= $sortTh('amount', 'Amount') ?><?= $sortTh('status', 'Status') ?><?php if ($colsAll): ?><th>Boarding</th><th>Drop</th><th>Desk</th><th>Email</th><th>Cabin / mode</th><th>Confirmed at</th><th>Refund</th><?php endif; ?><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="<?= ($canBulk ? 11 : 10) + ($colsAll ? 7 : 0) ?>" class="muted" style="padding:22px;text-align:center">No tickets match.</td></tr>
    <?php else: foreach ($rows as $b):
        $sellerAdminId = $b['sold_by_admin_id'] ? (int) $b['sold_by_admin_id'] : null;
        $sellerName    = $b['seller_name'] ?? '';
        $source        = (string) ($b['source'] ?? 'web');
        $agentCode     = format_agent_code($agentCodes, $sellerAdminId);
        $viewUrl       = $base . '/admin/booking-view.php?pnr=' . urlencode((string) $b['pnr']);
        $open          = in_array((string) $b['status'], ['pending', 'confirmed'], true);
        $waPhone       = preg_replace('/\D/', '', (string) ($b['contact_phone'] ?? '')) ?? '';
        if ($waPhone !== '' && strlen($waPhone) === 10) { $waPhone = '91' . $waPhone; }

        // "Sold via": the booking's one channel tag, and who is credited with it.
        $soldVia = BookingChannel::badge((string) ($b['channel'] ?? 'ONLINE'));
        if ($sellerName !== '') {
            $soldVia .= '<div style="font-size:11.5px;margin-top:3px">' . Security::e($sellerName)
                      . ($agentCode !== '' ? ' <span class="muted mono">' . Security::e($agentCode) . '</span>' : '') . '</div>';
        }
    ?>
      <tr data-bid="<?= (int) $b['id'] ?>">
        <?php if ($canBulk): ?>
        <td class="bulk-col" data-label="Select"><?php if ($open): ?><input type="checkbox" class="bulk-cb" value="<?= (int) $b['id'] ?>"
              data-pnr="<?= Security::e((string) $b['pnr']) ?>" data-pax="<?= Security::e((string) ($b['passenger'] ?: '')) ?>"
              data-when="<?= Security::e(formatDate($b['travel_date'] ?? null, 'j M')) ?>" data-amt="<?= Security::e(inr((float) $b['total_amount'])) ?>"
              aria-label="Select ticket <?= Security::e((string) $b['pnr']) ?>"><?php endif; ?></td>
        <?php endif; ?>
        <td data-label="Ticket" class="tk-first"><a class="mono" href="<?= $viewUrl ?>"><b><?= Security::e((string) ($b['ticket_number'] ?: $b['pnr'])) ?></b></a>
          <?php if (!empty($b['ticket_number'])): ?><div class="muted mono" style="font-size:11px"><?= Security::e((string) $b['pnr']) ?></div><?php endif; ?>
          <?php if ((int) ($b['ticket_void'] ?? 0) === 1): ?><span class="pill" style="background:#f7dcdc;color:#8a1f1f;font-size:10px">VOID</span><?php endif; ?></td>
        <td data-label="Booked"><span title="<?= Security::e(timeAgo((string) $b['created_at'])) ?>"><?= Security::e(formatDate((string) $b['created_at'], 'j M Y')) ?></span>
          <div class="muted" style="font-size:11px"><?= Security::e(date('g:i A', strtotime((string) $b['created_at']))) ?></div></td>
        <td data-label="Travel"><b><?= Security::e(formatDate($b['travel_date'] ?? null)) ?></b><?= !empty($b['dep_time']) ? ' <span class="muted">' . Security::e(substr((string) $b['dep_time'], 0, 5)) . '</span>' : '' ?>
          <div class="muted" style="font-size:11.5px"><?= Security::e(($b['from_city'] ?? '—') . ' → ' . ($b['to_city'] ?? '—')) ?></div>
          <?php if (!empty($b['bus_number'])): ?><div class="muted mono" style="font-size:11px">🚌 <?= Security::e((string) $b['bus_number']) ?></div><?php endif; ?></td>
        <td data-label="Passenger"><?= Security::e((string) ($b['passenger'] ?: '—')) ?><?= (int) $b['pax_count'] > 1 ? ' <span class="muted">+' . ((int) $b['pax_count'] - 1) . '</span>' : '' ?>
          <div class="muted mono" style="font-size:11px"><?= Security::e((string) $b['contact_phone']) ?></div></td>
        <td data-label="Seats" class="mono"><?= Security::e(($seatToks = array_filter(explode(' ', (string) ($b['seats'] ?? '')), static fn($t) => $t !== '')) ? implode(' ', array_map(static fn($t) => Seats::displayLabel((string) $t, (string) ($b['coach_type'] ?: 'sleeper'), (string) ($b['booking_mode'] ?? 'sharing')), $seatToks)) : '—') ?><?= !empty($b['booking_mode']) && $b['booking_mode'] === 'private' ? ' <span class="pill" style="background:#efeaff;color:#5a3fb0;font-size:10px">Private</span>' : '' ?></td>
        <td data-label="Sold via"><?= $soldVia ?></td>
        <td data-label="Payment"><?= pay_status_pill($b['pay_status'] ?? null, (string) ($b['status'] ?? '') === 'pending' && empty($b['expires_at'])) ?><div class="muted" style="font-size:11px;margin-top:3px"><?= Security::e(strtoupper((string) ($b['pay_method'] ?: ((int) ($b['is_cod'] ?? 0) === 1 ? 'COD' : '—')))) ?></div></td>
        <td data-label="Amount"><?= Security::e(inr((float) $b['total_amount'])) ?></td>
        <td data-label="Status"><?= admin_pill((string) $b['status']) ?></td>
        <?php if ($colsAll): /* 15 Oct 2026: Columns: All */ ?>
        <td data-label="Boarding"><?= Security::e((string) ($b['boarding_stop'] ?: '—')) ?></td>
        <td data-label="Drop"><?= Security::e((string) ($b['drop_stop'] ?: '—')) ?></td>
        <td data-label="Desk" class="mono"><?= Security::e((string) ($b['desk_code'] ?: '—')) ?></td>
        <td data-label="Email"><?= Security::e((string) ($b['contact_email'] ?: '—')) ?></td>
        <td data-label="Cabin / mode"><?= Security::e(trim((string) ($b['cabin_label'] ?? '') . ' ' . ucfirst((string) ($b['booking_mode'] ?: 'sharing')))) ?></td>
        <td data-label="Confirmed at"><?= !empty($b['confirmed_at']) ? Security::e(formatDate((string) $b['confirmed_at'], 'j M Y') . ' ' . date('g:i A', (int) strtotime((string) $b['confirmed_at']))) : '—' ?></td>
        <td data-label="Refund"><?= (string) ($b['refund_status'] ?? 'none') === 'none' ? '—' : Security::e(ucfirst((string) $b['refund_status']) . ' · ' . inr((float) $b['refund_amount'])) ?></td>
        <?php endif; ?>
        <td data-label="Actions"><div class="tk-acts">
          <a class="btn ghost" href="<?= $viewUrl ?>" title="Open the ticket">👁 View</a>
          <?php /* 15 Oct 2026 (Feature 1): one-screen Quick Edit on the ticket page, same gate as before. */ if ($canEdit && $open): ?><a class="btn ghost" href="<?= $viewUrl ?>#quick" title="Quick Edit: passenger, phone, stops, seat, payment">✏️ Quick Edit</a><?php endif; ?>
          <?php /* 17 Sep 2026: change-date one click from the register (same gate as reschedule.php) */ if ($open && (Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin())): ?><a class="btn ghost" href="<?= $base ?>/admin/reschedule.php?pnr=<?= urlencode((string) $b['pnr']) ?>" title="Move this ticket to another date or departure (same route)">📅 Date</a><?php endif; ?>
          <?php if ((string) $b['status'] === 'confirmed'): ?>
            <a class="btn ghost" href="<?= Security::e(Ticket::downloadUrl((string) $b['pnr'])) ?>&amp;print=1" target="_blank" rel="noopener" title="Open the ticket for printing">🖨 Print</a>
            <a class="btn ghost" href="<?= Security::e(Ticket::imageUrl((string) $b['pnr'])) ?>" target="_blank" rel="noopener" title="The HD image ticket — the format passengers are sent">🎟️ PNG</a>
            <a class="btn ghost" href="<?= Security::e(Ticket::downloadUrl((string) $b['pnr'])) ?>" target="_blank" rel="noopener" title="Download the ticket PDF">📄 PDF</a>
            <?php if ($canResend): ?>
              <form method="post" action="<?= $viewUrl ?>" onsubmit="return confirm('Re-send ticket <?= Security::e((string) $b['pnr']) ?> to <?= Security::e((string) $b['contact_phone']) ?> on WhatsApp?')">
                <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="resend_wa">
                <button class="btn ghost" type="submit" title="Resend the ticket on WhatsApp">📲 Resend</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($canCancel && $open): ?>
            <form method="post" action="<?= $viewUrl ?>" onsubmit="var r=prompt('Cancel <?= Security::e((string) $b['pnr']) ?> — reason?','Cancelled by staff');if(r===null||r.trim()==='')return false;this.reason.value=r;return true;">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="reason" value="">
              <button class="btn ghost danger" type="submit" title="Cancel this ticket (refund slab applies)">✖ Cancel</button>
            </form>
          <?php endif; ?>
          <?php /* 17 Sep 2026: status-aware message, logged, +91/+977-aware — the same composer every panel uses */
                echo admin_wa_button('booking_confirmation', ['pnr' => (string) $b['pnr']], '💬 WhatsApp',
                    ['class' => 'btn ghost', 'fallback' => ($waPhone !== '' && strlen($waPhone) >= 10) ? 'https://wa.me/' . $waPhone . '?text=' . rawurlencode(booking_wa_message($b, $base)) : '']); ?>
        </div></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  <?php if ($pages > 1): ?>
  <div class="pager">
    <span class="muted">Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $PER_PAGE, $totalResults)) ?> of <?= number_format($totalResults) ?></span>
    <span style="margin-left:auto;display:inline-flex;gap:6px;align-items:center">
      <?php if ($page > 1): ?><a class="btn ghost" href="<?= Security::e($pageUrl(1)) ?>">« First</a><a class="btn ghost" href="<?= Security::e($pageUrl($page - 1)) ?>">‹ Prev</a><?php endif; ?>
      <span class="mono">Page <?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn ghost" href="<?= Security::e($pageUrl($page + 1)) ?>">Next ›</a><a class="btn ghost" href="<?= Security::e($pageUrl($pages)) ?>">Last »</a><?php endif; ?>
    </span>
  </div>
  <?php endif; ?>
</div>
<script>
/* Column funnels (15 Oct 2026): a tap opens that column's tiny filter form
   and focuses it; Escape or a second tap closes it. Sort links stay links. */
(function () {
  document.querySelectorAll('.th-f[data-fbox]').forEach(function (b) {
    var box = document.getElementById(b.getAttribute('data-fbox'));
    if (!box) return;
    b.addEventListener('click', function (e) {
      e.preventDefault();
      var open = box.hidden;
      document.querySelectorAll('.th-fbox').forEach(function (o) { o.hidden = true; });
      document.querySelectorAll('.th-f[data-fbox]').forEach(function (o) { o.setAttribute('aria-expanded', 'false'); });
      box.hidden = !open;
      b.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { var i = box.querySelector('input[type=search]'); if (i) i.focus(); }
    });
    box.addEventListener('keydown', function (e) { if (e.key === 'Escape') { box.hidden = true; b.setAttribute('aria-expanded', 'false'); b.focus(); } });
  });
})();
</script>

<?php if ($canBulk): ?>
<!-- ====================================================================
     BULK CANCEL (4 Sep 2026) — tick tickets, press "Cancel selected", confirm
     the list in a modal, then admin/api/bulk-cancel.php cancels each PNR
     through the normal cancel path. Agents only ever see their own tickets
     here; the server re-checks ownership per PNR regardless.
     ==================================================================== -->
<style>
.bulk-col{width:34px;text-align:center}
.bulk-col input{width:18px;height:18px;cursor:pointer;accent-color:#b02a2a}
.card-table.is-cards .bulk-col{display:flex;align-items:center;gap:8px}
.bulk-bar{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:70;background:#12264E;color:#fff;padding:10px 14px;border-radius:14px;display:flex;gap:10px;align-items:center;box-shadow:0 12px 32px rgba(0,0,0,.32);font-size:13.5px;max-width:calc(100vw - 24px);flex-wrap:wrap;justify-content:center}
.bulk-bar[hidden]{display:none}
.bulk-bar .btn{padding:8px 12px;font-size:13px}
.bulk-bar .btn.ghost{background:transparent;color:#fff;border-color:rgba(255,255,255,.35)}
.bulk-bar .btn.danger{background:#b02a2a;color:#fff;border-color:#b02a2a}
.bulk-modal{position:fixed;inset:0;z-index:80;background:rgba(10,20,40,.55);display:flex;align-items:center;justify-content:center;padding:16px}
.bulk-modal[hidden]{display:none}
.bulk-box{background:var(--card,#fff);color:var(--ink,#1b2436);border-radius:16px;max-width:540px;width:100%;padding:20px 22px;max-height:92vh;overflow:auto;box-shadow:0 24px 60px rgba(0,0,0,.35)}
.bulk-box h3{margin:0 0 6px;font-size:18px}
.bulk-list{margin:10px 0;padding:0;list-style:none;max-height:240px;overflow:auto;border:1px solid var(--line);border-radius:10px;font-size:13px}
.bulk-list li{display:flex;gap:8px;justify-content:space-between;padding:7px 10px;border-bottom:1px solid var(--line)}
.bulk-list li:last-child{border-bottom:0}
.bulk-list .mono{font-weight:700}
.bulk-box label{display:block;font-size:12.5px;color:var(--mut);margin:10px 0 0}
.bulk-box label input{display:block;width:100%;margin-top:5px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;font-size:14px;background:var(--card);color:var(--ink)}
.bulk-acts{display:flex;gap:8px;justify-content:flex-end;margin-top:14px;flex-wrap:wrap}
.bulk-acts .btn.danger{background:#b02a2a;color:#fff;border-color:#b02a2a}
@media(pointer:coarse){.bulk-col input{width:22px;height:22px}}
</style>
<div class="bulk-bar" id="bulkBar" hidden role="region" aria-label="Selected tickets">
  <span><b id="bulkN">0</b> ticket(s) selected</span>
  <button type="button" class="btn ghost" id="bulkClear">Clear</button>
  <button type="button" class="btn danger" id="bulkCancelBtn">✖ Cancel selected</button>
</div>
<div class="bulk-modal" id="bulkModal" hidden role="dialog" aria-modal="true" aria-labelledby="bulkTitle">
  <div class="bulk-box">
    <h3 id="bulkTitle">Cancel <span id="bulkN2">0</span> ticket(s)?</h3>
    <p class="muted" style="margin:0;font-size:13px">Each ticket is cancelled one by one: seats are released, the refund slab is applied and the passenger is told on WhatsApp. <b>This cannot be undone.</b></p>
    <ul class="bulk-list" id="bulkList"></ul>
    <label>Reason (printed on every ticket and in the activity log)
      <input type="text" id="bulkReason" maxlength="255" value="Cancelled by staff" placeholder="e.g. Bus not running on this date">
    </label>
    <div id="bulkErr" class="flash bad" hidden style="margin:12px 0 0"></div>
    <div class="bulk-acts">
      <button type="button" class="btn ghost" id="bulkNo">Keep the tickets</button>
      <button type="button" class="btn danger" id="bulkYes">Yes, cancel <span id="bulkN3">0</span> ticket(s)</button>
    </div>
  </div>
</div>
<script>
(function () {
  var all = document.getElementById('bulkAll'), bar = document.getElementById('bulkBar'), modal = document.getElementById('bulkModal');
  if (!bar || !modal) return;
  var csrfName = <?= json_encode($k) ?>, csrf = <?= json_encode(Security::csrfToken()) ?>, base = <?= json_encode($base) ?>;
  function cbs() { return Array.prototype.slice.call(document.querySelectorAll('.bulk-cb')); }
  function picked() { return cbs().filter(function (c) { return c.checked; }); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function refresh() {
    var p = picked(), n = cbs().length;
    bar.hidden = p.length === 0;
    document.getElementById('bulkN').textContent = p.length;
    if (all) { all.checked = n > 0 && p.length === n; all.indeterminate = p.length > 0 && p.length < n; all.disabled = n === 0; }
  }
  if (all) all.addEventListener('change', function () { cbs().forEach(function (c) { c.checked = all.checked; }); refresh(); });
  cbs().forEach(function (c) { c.addEventListener('change', refresh); });
  document.getElementById('bulkClear').addEventListener('click', function () { cbs().forEach(function (c) { c.checked = false; }); refresh(); });

  function close() { modal.hidden = true; }
  document.getElementById('bulkNo').addEventListener('click', close);
  modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

  document.getElementById('bulkCancelBtn').addEventListener('click', function () {
    var p = picked(); if (!p.length) return;
    document.getElementById('bulkN2').textContent = p.length;
    document.getElementById('bulkN3').textContent = p.length;
    document.getElementById('bulkList').innerHTML = p.map(function (c) {
      return '<li><span class="mono">' + esc(c.getAttribute('data-pnr')) + '</span><span>' + esc(c.getAttribute('data-pax')) + '</span><span class="muted">' + esc(c.getAttribute('data-when')) + ' · ' + esc(c.getAttribute('data-amt')) + '</span></li>';
    }).join('');
    var err = document.getElementById('bulkErr'); err.hidden = true; err.textContent = '';
    document.getElementById('bulkYes').disabled = false;
    modal.hidden = false;
    setTimeout(function () { document.getElementById('bulkReason').focus(); }, 30);
  });

  document.getElementById('bulkYes').addEventListener('click', function () {
    var p = picked(); if (!p.length) { close(); return; }
    var btn = this, err = document.getElementById('bulkErr');
    var reason = (document.getElementById('bulkReason').value || '').trim();
    if (!reason) { err.textContent = 'Please give a reason.'; err.hidden = false; return; }
    btn.disabled = true; btn.textContent = 'Cancelling…';
    var fd = new FormData();
    fd.append(csrfName, csrf); fd.append('reason', reason); fd.append('confirm', '1');
    p.forEach(function (c) { fd.append('ids[]', c.value); });
    fetch(base + '/admin/api/bulk-cancel.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server sent a non-JSON reply.' }; }); })
      .then(function (j) {
        if (j && j.ok) {
          var failed = (j.results || []).filter(function (x) { return !x.ok; });
          var msg = j.message || 'Done.';
          if (failed.length) { msg += ' ' + failed.map(function (x) { return (x.pnr || ('#' + x.id)) + ': ' + (x.error || 'failed'); }).join(' · '); }
          try { sessionStorage.setItem('shg_bulk_flash', JSON.stringify({ ok: j.cancelled > 0, msg: msg })); } catch (e) {}
          location.reload();
          return;
        }
        err.textContent = (j && j.error) || 'Something went wrong. Nothing further was cancelled.'; err.hidden = false;
        btn.disabled = false; btn.textContent = 'Yes, cancel ' + p.length + ' ticket(s)';
      })
      .catch(function () { err.textContent = 'Network error — please check the list and try again.'; err.hidden = false; btn.disabled = false; btn.textContent = 'Yes, cancel ' + p.length + ' ticket(s)'; });
  });

  // Result of the previous bulk action, shown once after the reload.
  try {
    var raw = sessionStorage.getItem('shg_bulk_flash');
    if (raw) {
      sessionStorage.removeItem('shg_bulk_flash');
      var f = JSON.parse(raw), div = document.createElement('div');
      div.className = 'flash ' + (f.ok ? 'ok' : 'bad'); div.textContent = f.msg || '';
      var first = document.querySelector('main .cards, main form, main .panel');
      (first && first.parentNode ? first.parentNode : document.body).insertBefore(div, first || null);
      div.scrollIntoView({ block: 'nearest' });
    }
  } catch (e) {}
  refresh();
})();
</script>
<?php endif; ?>
<?php
admin_footer();
