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
$page            = max(1, (int) ($_GET['p'] ?? 1));
$PER_PAGE        = 50;

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

// Source mapping: "Online" covers web+app
$sourceMap = [
    'online'  => ['web', 'app'],
    'agent'   => ['agent'],
    'counter' => ['counter'],
    'admin'   => ['admin'],
];

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
// Source filter
if ($sourceFilter !== '' && isset($sourceMap[$sourceFilter])) {
    $srcVals = $sourceMap[$sourceFilter];
    $srcParts = [];
    foreach ($srcVals as $si => $sv) {
        $key = 'src' . $si;
        $srcParts[] = ':' . $key;
        $params[$key] = $sv;
    }
    $where[] = 'b.source IN (' . implode(',', $srcParts) . ')';
}
// Agent filter (only for non-agent roles)
if ($scopeId === null && $agentFilter !== '' && ctype_digit($agentFilter)) {
    $where[] = 'b.sold_by_admin_id = :agentFlt';
    $params['agentFlt'] = (int) $agentFilter;
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

$fromSql = "FROM bookings b
          LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
          LEFT JOIN schedules s ON s.id = bl.schedule_id
          LEFT JOIN routes r ON r.id = s.route_id
          LEFT JOIN buses bu ON bu.id = s.bus_id"
     . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');

// Totals over the WHOLE filtered set (not just this page).
$agg = Database::fetch(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(b.status = 'confirmed'), 0) AS confirmed,
            COALESCE(SUM(b.status = 'pending'), 0) AS pending,
            COALESCE(SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount ELSE 0 END), 0) AS revenue
     " . $fromSql,
    $params
) ?? ['total' => 0, 'confirmed' => 0, 'pending' => 0, 'revenue' => 0];
$totalResults     = (int) $agg['total'];
$confirmedCount   = (int) $agg['confirmed'];
$pendingCount     = (int) $agg['pending'];
$confirmedRevenue = (float) $agg['revenue'];
$pages = max(1, (int) ceil($totalResults / $PER_PAGE));
if ($page > $pages) { $page = $pages; }
$offset = ($page - 1) * $PER_PAGE;

// LIMIT/OFFSET are interpolated as ints on purpose: the PDO driver here runs
// real prepares and would quote a bound LIMIT (same note as activity-log.php).
$sql = "SELECT b.id, b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, b.booking_mode,
               b.source, b.sold_by_admin_id, b.is_cod,
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
          ORDER BY b.id DESC LIMIT ' . (int) $PER_PAGE . ' OFFSET ' . (int) $offset;

$rows = Database::fetchAll($sql, $params);

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
function pay_status_pill(?string $st): string
{
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
]);
$hasFilters = ($q !== '' || $status !== '' || $isDate($from) || $isDate($to)
    || $routeFilter !== '' || $isDate($travelFrom) || $isDate($travelTo)
    || $sourceFilter !== '' || ($scopeId === null && $agentFilter !== '')
    || $busFilter !== '' || in_array($payStatusFilter, $validPayStatus, true)
    || in_array($payMethodFilter, $validPayMethod, true));
$pageUrl = static fn(int $p): string => $base . '/admin/bookings.php?' . http_build_query($exportBase + ['p' => $p]);
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
}
@media(pointer:coarse){.tk-acts .btn,.tk-acts form .btn{min-height:44px}}
:root[data-theme="dark"] .pill{opacity:.92}
</style>

<form method="get" style="margin-bottom:16px">
  <div class="panel" style="padding:14px 16px">
    <div class="filter-row">
      <div class="filter-group" style="flex:2 1 240px">
        <span class="flbl">Search</span>
        <input type="search" name="q" placeholder="Ticket no, PNR, name, phone, seat, bus, agent" value="<?= Security::e($q) ?>" style="width:100%" title="Search by ticket number, PNR, passenger name, phone, email, seat number, bus number or agent">
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
      <div class="filter-group">
        <span class="flbl">Sold via</span>
        <select name="source">
          <option value="">All</option>
          <option value="online" <?= $sourceFilter === 'online' ? 'selected' : '' ?>>Online</option>
          <option value="agent" <?= $sourceFilter === 'agent' ? 'selected' : '' ?>>Agent</option>
          <option value="counter" <?= $sourceFilter === 'counter' ? 'selected' : '' ?>>Counter</option>
          <option value="admin" <?= $sourceFilter === 'admin' ? 'selected' : '' ?>>Admin</option>
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
    </div>

    <details class="advanced-section" id="advFilters" open>
    <summary style="font-size:12px;font-weight:600;color:var(--blue);cursor:pointer;margin:8px 0 4px;list-style:none">📅 Dates &amp; export</summary>
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

    <div class="filter-row" style="margin-top:10px">
      <div class="export-links">
        <a class="btn ghost" style="font-size:12px;padding:6px 10px" href="<?= $base ?>/admin/export.php?<?= Security::e(http_build_query($exportBase)) ?>">⬇️ Export CSV</a>
        <a class="btn ghost" style="font-size:12px;padding:6px 10px" href="<?= $base ?>/admin/export.php?<?= Security::e(http_build_query($exportBase + ['format' => 'xlsx'])) ?>">⬇️ Export Excel</a>
        <a class="btn ghost" style="font-size:12px;padding:6px 10px" href="<?= $base ?>/admin/export.php?<?= Security::e(http_build_query($exportBase + ['format' => 'pdf'])) ?>">⬇️ Export PDF</a>
      </div>
    </div>
    </details>
  </div>
</form>
<script>
/* Phones: fold the date filters + export links away unless one is in use. */
(function () {
  var d = document.getElementById('advFilters'); if (!d) return;
  var used = <?= ($isDate($from) || $isDate($to) || $isDate($travelFrom) || $isDate($travelTo)) ? 'true' : 'false' ?>;
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
    <div class="v" style="color:#0a6b3b"><?= number_format($confirmedCount) ?></div>
  </div>
  <div class="card">
    <div class="k">Pending</div>
    <div class="v" style="color:#8a6d00"><?= number_format($pendingCount) ?></div>
  </div>
  <div class="card">
    <div class="k">Revenue</div>
    <div class="v"><?= Security::e(inr($confirmedRevenue)) ?></div>
  </div>
</div>

<div class="panel">
  <h2><?= number_format($totalResults) ?> ticket<?= $totalResults === 1 ? '' : 's' ?><?= $pages > 1 ? ' · page ' . $page . ' of ' . $pages : '' ?></h2>
  <div class="tbl-scroll">
  <table class="card-table">
    <thead><tr><?php if ($canBulk): ?><th class="bulk-col"><input type="checkbox" id="bulkAll" aria-label="Select every open ticket on this page" title="Select all open tickets on this page"></th><?php endif; ?><th>Ticket</th><th>Booked</th><th>Travel</th><th>Passenger</th><th>Seats</th><th>Sold via</th><th>Payment</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="<?= $canBulk ? 11 : 10 ?>" class="muted" style="padding:22px;text-align:center">No tickets match.</td></tr>
    <?php else: foreach ($rows as $b):
        $sellerAdminId = $b['sold_by_admin_id'] ? (int) $b['sold_by_admin_id'] : null;
        $sellerName    = $b['seller_name'] ?? '';
        $source        = (string) ($b['source'] ?? 'web');
        $agentCode     = format_agent_code($agentCodes, $sellerAdminId);
        $viewUrl       = $base . '/admin/booking-view.php?pnr=' . urlencode((string) $b['pnr']);
        $open          = in_array((string) $b['status'], ['pending', 'confirmed'], true);
        $waPhone       = preg_replace('/\D/', '', (string) ($b['contact_phone'] ?? '')) ?? '';
        if ($waPhone !== '' && strlen($waPhone) === 10) { $waPhone = '91' . $waPhone; }

        // "Sold via" display
        if (in_array($source, ['agent', 'counter'], true) && $sellerName !== '') {
            $soldVia = source_badge($source) . '<div style="font-size:11.5px;margin-top:3px">' . Security::e($sellerName)
                     . ($agentCode !== '' ? ' <span class="muted mono">' . Security::e($agentCode) . '</span>' : '') . '</div>';
        } elseif ($source === 'admin' && $sellerName !== '') {
            $soldVia = source_badge($source) . '<div class="muted" style="font-size:11.5px;margin-top:3px">' . Security::e($sellerName) . '</div>';
        } else {
            $soldVia = source_badge($source);
        }
    ?>
      <tr data-bid="<?= (int) $b['id'] ?>">
        <?php if ($canBulk): ?>
        <td class="bulk-col" data-label="Select"><?php if ($open): ?><input type="checkbox" class="bulk-cb" value="<?= (int) $b['id'] ?>"
              data-pnr="<?= Security::e((string) $b['pnr']) ?>" data-pax="<?= Security::e((string) ($b['passenger'] ?: '')) ?>"
              data-when="<?= Security::e(formatDate($b['travel_date'] ?? null, 'j M')) ?>" data-amt="<?= Security::e(inr((float) $b['total_amount'])) ?>"
              aria-label="Select ticket <?= Security::e((string) $b['pnr']) ?>"><?php endif; ?></td>
        <?php endif; ?>
        <td data-label="Ticket"><a class="mono" href="<?= $viewUrl ?>"><b><?= Security::e((string) ($b['ticket_number'] ?: $b['pnr'])) ?></b></a>
          <?php if (!empty($b['ticket_number'])): ?><div class="muted mono" style="font-size:11px"><?= Security::e((string) $b['pnr']) ?></div><?php endif; ?>
          <?php if ((int) ($b['ticket_void'] ?? 0) === 1): ?><span class="pill" style="background:#f7dcdc;color:#8a1f1f;font-size:10px">VOID</span><?php endif; ?></td>
        <td data-label="Booked"><span title="<?= Security::e(timeAgo((string) $b['created_at'])) ?>"><?= Security::e(formatDate((string) $b['created_at'], 'j M Y')) ?></span>
          <div class="muted" style="font-size:11px"><?= Security::e(date('g:i A', strtotime((string) $b['created_at']))) ?></div></td>
        <td data-label="Travel"><b><?= Security::e(formatDate($b['travel_date'] ?? null)) ?></b><?= !empty($b['dep_time']) ? ' <span class="muted">' . Security::e(substr((string) $b['dep_time'], 0, 5)) . '</span>' : '' ?>
          <div class="muted" style="font-size:11.5px"><?= Security::e(($b['from_city'] ?? '—') . ' → ' . ($b['to_city'] ?? '—')) ?></div>
          <?php if (!empty($b['bus_number'])): ?><div class="muted mono" style="font-size:11px">🚌 <?= Security::e((string) $b['bus_number']) ?></div><?php endif; ?></td>
        <td data-label="Passenger"><?= Security::e((string) ($b['passenger'] ?: '—')) ?><?= (int) $b['pax_count'] > 1 ? ' <span class="muted">+' . ((int) $b['pax_count'] - 1) . '</span>' : '' ?>
          <div class="muted mono" style="font-size:11px"><?= Security::e((string) $b['contact_phone']) ?></div></td>
        <td data-label="Seats" class="mono"><?= Security::e(($seatToks = array_filter(explode(' ', (string) ($b['seats'] ?? '')), static fn($t) => $t !== '')) ? implode(' ', array_map(static fn($t) => Seats::displayLabel((string) $t, 'sleeper', (string) ($b['booking_mode'] ?? 'sharing')), $seatToks)) : '—') ?><?= !empty($b['booking_mode']) && $b['booking_mode'] === 'private' ? ' <span class="pill" style="background:#efeaff;color:#5a3fb0;font-size:10px">Private</span>' : '' ?></td>
        <td data-label="Sold via"><?= $soldVia ?></td>
        <td data-label="Payment"><?= pay_status_pill($b['pay_status'] ?? null) ?><div class="muted" style="font-size:11px;margin-top:3px"><?= Security::e(strtoupper((string) ($b['pay_method'] ?: ((int) ($b['is_cod'] ?? 0) === 1 ? 'COD' : '—')))) ?></div></td>
        <td data-label="Amount"><?= Security::e(inr((float) $b['total_amount'])) ?></td>
        <td data-label="Status"><?= admin_pill((string) $b['status']) ?></td>
        <td data-label="Actions"><div class="tk-acts">
          <a class="btn ghost" href="<?= $viewUrl ?>" title="Open the ticket">👁 View</a>
          <?php if ($canEdit && $open): ?><a class="btn ghost" href="<?= $viewUrl ?>#edit" title="Edit passenger, phone, stops, seat, payment">✏️ Edit</a><?php endif; ?>
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
          <?php if ($waPhone !== '' && strlen($waPhone) >= 10): ?>
            <a class="btn ghost" href="https://wa.me/<?= Security::e($waPhone) ?>?text=<?= Security::e(rawurlencode(booking_wa_message($b, $base))) ?>" target="_blank" rel="noopener" title="Open WhatsApp with the message typed">💬 WA</a>
          <?php endif; ?>
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
