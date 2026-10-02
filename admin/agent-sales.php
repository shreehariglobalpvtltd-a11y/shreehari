<?php
/**
 * admin/agent-sales.php — the agent's own sales book.
 *
 * Ticket search, booking history and the daily / weekly / monthly / custom
 * range reports the agent portal needs, all in one place because they are
 * the same query with a different window over it.
 *
 * Every figure on this page is scoped to one agent. An agent is pinned to
 * themselves; a supervisor (dashboard.view) may pass ?agent=<id> to review
 * someone's book, exactly as admin/agent.php does. The scope is resolved
 * once, here, and threaded into every statement as a bound parameter — no
 * query on this page may be written without it.
 *
 * Gated on bookings.view, which counter agents hold.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
// Office viewers need the commissions permission — an agent sees their own
// sales, but counter/support roles must not read other sellers' money.
if (!Auth::isCounterAgent()) { Auth::requireAdmin('commissions.view'); }

$base         = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$selfId       = (int) ($admin['id'] ?? 0);
$isSupervisor = Auth::can('dashboard.view');
$viewId       = $selfId;

// A supervisor may look at another agent's book; an agent never can. The
// bookingScopeAdminId() null-check is what makes that true — for a counter
// agent it always returns their own id, so ?agent= is simply ignored.
if ($isSupervisor && isset($_GET['agent']) && (int) $_GET['agent'] > 0) {
    $viewId = (int) $_GET['agent'];
}
$scopeId = Auth::bookingScopeAdminId();
if ($scopeId !== null) {
    $viewId = $scopeId;
}
$isOwn = $viewId === $selfId;

/* ---- Date range ----------------------------------------------------
   Presets are resolved to explicit [from, to) bounds so the SQL below has
   exactly one shape, and so a half-open range never double-counts a
   booking made at midnight. */
$preset = (string) ($_GET['range'] ?? 'month');
$today  = todayISO();

$presets = [
    'today'     => 'Today',
    'yesterday' => 'Yesterday',
    'week'      => 'This week',
    'month'     => 'This month',
    'all'       => 'All time',
    'custom'    => 'Custom range',
];
if (!isset($presets[$preset])) {
    $preset = 'month';
}

$customFrom = Security::clean($_GET['from'] ?? '', 10);
$customTo   = Security::clean($_GET['to'] ?? '', 10);
$isDate     = static fn(string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

switch ($preset) {
    case 'today':
        $from = $today;
        $to   = addDaysISO($today, 1);
        break;
    case 'yesterday':
        $from = addDaysISO($today, -1);
        $to   = $today;
        break;
    case 'week':
        $from = date('Y-m-d', strtotime('monday this week'));
        $to   = addDaysISO($today, 1);
        break;
    case 'all':
        $from = '1970-01-01';
        $to   = addDaysISO($today, 1);
        break;
    case 'custom':
        $from = $isDate($customFrom) ? $customFrom : date('Y-m-01');
        $to   = $isDate($customTo) ? addDaysISO($customTo, 1) : addDaysISO($today, 1);
        // A backwards range would silently return nothing; swap instead.
        if ($from > $to) { [$from, $to] = [$to, $from]; }
        break;
    case 'month':
    default:
        $from = date('Y-m-01');
        $to   = addDaysISO($today, 1);
        break;
}

/* ---- Which day a ticket belongs to (2 Oct 2026) ----------------------
   'journey' = the day the passenger travels (outbound leg), 'sale' = the
   day the ticket was cut. The page only knew the sale day, so the owner's
   "yesterday" (yesterday's bus) showed nothing for an agent whose tickets
   were cut the day before — or credited to them later from the seat map,
   which keeps the original sale date. Journey is the default now: it is
   how the desk, the chalani and the owner count; Sale stays one tap away
   for "what did I sell today". */
$basis    = (string) ($_GET['basis'] ?? 'journey') === 'sale' ? 'sale' : 'journey';
$basisCol = $basis === 'sale' ? 'b.created_at' : 'blx.travel_date';
// LEFT on the sale basis so a booking is never dropped for want of a leg row.
$joinSql  = ($basis === 'sale' ? ' LEFT' : '') . " JOIN booking_legs blx ON blx.booking_id = b.id AND blx.leg_type = 'outbound'";
// A sale cannot be in the future, a journey can: on the journey basis "this
// week / this month / all" run to the END of the period, so next Friday's
// passengers are counted in this week instead of vanishing until Friday.
if ($basis === 'journey') {
    if ($preset === 'week')  { $to = date('Y-m-d', strtotime('monday next week')); }
    if ($preset === 'month') { $to = date('Y-m-01', strtotime('first day of next month')); }
    if ($preset === 'all')   { $to = addDaysISO($today, 400); }
}

/* ---- Filters -------------------------------------------------------- */
$q      = Security::clean($_GET['q'] ?? '', 60);
$status = Security::clean($_GET['status'] ?? '', 20);
$valid  = ['pending', 'confirmed', 'cancelled', 'rejected', 'expired'];

$where  = ['b.sold_by_admin_id = :agent', $basisCol . ' >= :from', $basisCol . ' < :to'];
$params = ['agent' => $viewId, 'from' => $from, 'to' => $to];

if ($q !== '') {
    // Passenger name is on a child table, so the name arm is a subquery
    // rather than a join — a join would multiply a booking by its seats.
    $where[] = sqlSearchClause([
        'b.pnr LIKE %s',
        'b.contact_phone LIKE %s',
        'b.contact_email LIKE %s',
        'EXISTS (SELECT 1 FROM booking_passengers bp2
                  WHERE bp2.booking_id = b.id AND bp2.full_name LIKE %s)',
    ], $q, $params);
}
if (in_array($status, $valid, true)) {
    $where[]      = 'b.status = :st';
    $params['st'] = $status;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

/* ---- Range report --------------------------------------------------
   Confirmed-only for money, all-status for the ticket count: a cancelled
   booking is still a ticket the agent wrote, but it is not revenue. */
$report = Database::fetch(
    "SELECT COUNT(*) AS tickets,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN 1 ELSE 0 END), 0) AS confirmed,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN b.status = 'cancelled' THEN 1 ELSE 0 END), 0) AS cancelled
       FROM bookings b" . $joinSql . $whereSql,
    $params
) ?? ['tickets' => 0, 'confirmed' => 0, 'revenue' => 0, 'cancelled' => 0];

$seatsSold = (int) Database::scalar(
    "SELECT COUNT(*) FROM booking_seats bs
       JOIN bookings b ON b.id = bs.booking_id" . $joinSql . $whereSql . " AND b.status = 'confirmed' AND bs.released_at IS NULL",
    $params,
    0
);

// Commission for the same window, read from the ledger rather than
// recomputed from a percentage — see includes/agentwallet.php. On the
// journey basis the window is the travel day of the ticket each row is for.
$commissionRange = (float) ($basis === 'sale'
    ? Database::scalar(
        "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger
          WHERE agent_admin_id = :a AND account = 'commission'
            AND entry_type IN ('commission','commission_void')
            AND created_at >= :from AND created_at < :to",
        ['a' => $viewId, 'from' => $from, 'to' => $to],
        0
    )
    : Database::scalar(
        "SELECT COALESCE(SUM(l.amount), 0) FROM agent_ledger l
           JOIN booking_legs blx ON blx.booking_id = l.booking_id AND blx.leg_type = 'outbound'
          WHERE l.agent_admin_id = :a AND l.account = 'commission'
            AND l.entry_type IN ('commission','commission_void')
            AND blx.travel_date >= :from AND blx.travel_date < :to",
        ['a' => $viewId, 'from' => $from, 'to' => $to],
        0
    ));

/* The same window counted the OTHER way — so "0 sold yesterday" can say
   "but 13 of your passengers travelled yesterday" instead of looking empty. */
$otherCol   = $basis === 'sale' ? 'blx.travel_date' : 'b.created_at';
$otherCount = Database::fetch(
    "SELECT COUNT(DISTINCT b.id) AS tickets,
            (SELECT COUNT(*) FROM booking_seats bs JOIN bookings b2 ON b2.id = bs.booking_id
               JOIN booking_legs bl2 ON bl2.booking_id = b2.id AND bl2.leg_type = 'outbound'
              WHERE b2.sold_by_admin_id = :agent2 AND bs.released_at IS NULL AND b2.status IN ('confirmed','pending','completed')
                AND " . ($basis === 'sale' ? 'bl2.travel_date' : 'b2.created_at') . " >= :from2
                AND " . ($basis === 'sale' ? 'bl2.travel_date' : 'b2.created_at') . " < :to2) AS pax
       FROM bookings b" . $joinSql . "
      WHERE b.sold_by_admin_id = :agent AND $otherCol >= :from AND $otherCol < :to",
    ['agent' => $viewId, 'from' => $from, 'to' => $to, 'agent2' => $viewId, 'from2' => $from, 'to2' => $to]
) ?? ['tickets' => 0, 'pax' => 0];

/* ---- The sales themselves ------------------------------------------- */
$rows = Database::fetchAll(
    "SELECT b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, b.source, b.booking_mode,
            r.from_city, r.to_city, bl.travel_date, s.route_id, s.slot,
            COALESCE(bus.bus_name, '') AS bus_name, COALESCE(bus.bus_number, '') AS bus_number,
            (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
               FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL) AS seats,
            (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL) AS seat_count,
            (SELECT bp.full_name FROM booking_passengers bp
              WHERE bp.booking_id = b.id AND bp.is_primary = 1 LIMIT 1) AS passenger
       FROM bookings b" . $joinSql . "
       LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
       LEFT JOIN schedules s ON s.id = bl.schedule_id
       LEFT JOIN routes r ON r.id = s.route_id
       LEFT JOIN buses bus ON bus.id = COALESCE(s.bus_id, r.bus_id)"
    . $whereSql . ($basis === 'sale' ? ' ORDER BY b.id DESC' : ' ORDER BY blx.travel_date DESC, b.id DESC') . ' LIMIT 300',
    $params
);
$rowsMulti = [];
if ($rows !== []) {
    $rDates    = array_filter(array_map(static fn(array $r): string => (string) ($r['travel_date'] ?? ''), $rows));
    $rowsMulti = $rDates !== [] ? admin_multi_bus_days(min($rDates), max($rDates)) : [];
}

/* ---- Day-by-day breakdown for the range ----------------------------- */
$dayExpr = $basis === 'sale' ? 'DATE(b.created_at)' : 'blx.travel_date';
$daily = Database::fetchAll(
    "SELECT $dayExpr AS d, COUNT(*) AS tickets,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS revenue,
            COALESCE(SUM(CASE WHEN b.status IN ('confirmed','pending','completed')
                THEN (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id AND bs.released_at IS NULL) ELSE 0 END), 0) AS pax
       FROM bookings b" . $joinSql . $whereSql . " GROUP BY $dayExpr ORDER BY d DESC LIMIT 31",
    $params
);

$agentList = $isSupervisor && $scopeId === null
    ? Database::fetchAll("SELECT id, username, full_name FROM admins WHERE role = 'agent' ORDER BY full_name, username")
    : [];

$viewing = Database::fetch('SELECT username, full_name FROM admins WHERE id = :id', ['id' => $viewId]) ?? [];

/** Keep the current filters when linking elsewhere. */
$carry = static function (array $over = []) use ($preset, $customFrom, $customTo, $q, $status, $viewId, $isSupervisor, $scopeId, $basis): string {
    $base = ['range' => $preset, 'from' => $customFrom, 'to' => $customTo, 'q' => $q, 'status' => $status, 'basis' => $basis === 'sale' ? 'sale' : ''];
    if ($isSupervisor && $scopeId === null) { $base['agent'] = $viewId; }
    return http_build_query(array_filter(array_merge($base, $over), static fn($v) => $v !== '' && $v !== null));
};

/* ---- PDF download (§14) ------------------------------------------------ */
if (($_GET['format'] ?? '') === 'pdf') {
    $pdfFrom = ($preset === 'custom' && $isDate($customFrom)) ? $customFrom : substr((string) $from, 0, 10);
    $pdfTo   = ($preset === 'custom' && $isDate($customTo))   ? $customTo   : $today;
    ReportPdf::agentReport($viewId, $pdfFrom, $pdfTo, $presets[$preset] ?? '');
    // ^ never returns (streams + exit)
}

admin_header($isOwn ? 'My Sales' : 'Sales · ' . (string) ($viewing['full_name'] ?: $viewing['username']), 'agent-sales');
?>
<style>
.dash-hero{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.hcard{border-radius:16px;padding:20px 22px;color:#fff;position:relative;overflow:hidden}
.hcard::after{content:'';position:absolute;right:-18px;top:-18px;width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.1)}
.hcard .hicon{font-size:28px;margin-bottom:8px;display:block;filter:drop-shadow(0 2px 4px rgba(0,0,0,.15))}
.hcard .hk{font-size:12px;text-transform:uppercase;letter-spacing:.5px;opacity:.85}
.hcard .hv{font-size:30px;font-weight:800;margin:4px 0 2px;line-height:1.1}
.hcard .hsub{font-size:12px;opacity:.75}
.hc-blue{background:linear-gradient(135deg,#2E5FA8,#1a3d6e)}
.hc-green{background:linear-gradient(135deg,#0a8b4b,#065a30)}
.hc-navy{background:linear-gradient(135deg,#12264E,#0b1a36)}
.hc-teal{background:linear-gradient(135deg,#00897b,#00695c)}

.dash-panel{background:var(--card);border:1px solid var(--line);border-radius:16px;overflow:hidden}
.dash-panel .dp-head{padding:16px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px;font-weight:700;font-size:15px;background:var(--head)}
.dash-panel .dp-body{padding:20px}

.occ-bar{height:20px;border-radius:10px;background:var(--hover);overflow:hidden;margin:6px 0}
.occ-fill{height:100%;border-radius:10px;transition:width .4s}
.occ-blue{background:linear-gradient(90deg,#2E5FA8,#1a3d6e)}

.src-badge{font-size:11px;padding:2px 8px;border-radius:20px;font-weight:600;display:inline-block}
.src-badge.web,.src-badge.app{background:#e8f5e9;color:#2e7d32}
.src-badge.agent{background:#e3f2fd;color:#1565c0}
.src-badge.counter,.src-badge.admin{background:#fff3e0;color:#e65100}
:root[data-theme="dark"] .src-badge.web,:root[data-theme="dark"] .src-badge.app{background:#1b3d20;color:#81c784}
:root[data-theme="dark"] .src-badge.agent{background:#0d2948;color:#64b5f6}
:root[data-theme="dark"] .src-badge.counter,:root[data-theme="dark"] .src-badge.admin{background:#3e2723;color:#ffb74d}

@media(max-width:900px){
  .dash-hero{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
}
/* Journey / sale date switch (2 Oct 2026). */
.basis-sw{display:inline-flex;border:1.5px solid var(--line);border-radius:12px;overflow:hidden;margin:0 0 12px}
.basis-sw a{padding:8px 14px;font-size:13px;font-weight:700;text-decoration:none;color:inherit;background:var(--card)}
.basis-sw a + a{border-left:1.5px solid var(--line)}
.basis-sw a.on{background:#0f766e;color:#fff}
.basis-hint{margin:-4px 0 14px;padding:9px 13px;border-radius:10px;background:#e6f4f1;color:#0b4f48;font-size:13px}
.basis-hint a{font-weight:800;color:inherit}
[data-theme="dark"] .basis-hint{background:#0b3b36;color:#bdeee5}
.bus-mini{font-size:11px;font-weight:700;color:#0f766e}
[data-theme="dark"] .bus-mini{color:#7fe0d0}
</style>
<?= LiveSync::anchor('agent:' . $viewId, 'auto') ?>
<nav class="basis-sw" aria-label="Count tickets by">
  <a href="<?= $base ?>/admin/agent-sales.php?<?= Security::e($carry(['basis' => null])) ?>" class="<?= $basis === 'journey' ? 'on' : '' ?>"<?= $basis === 'journey' ? ' aria-current="true"' : '' ?>>🚌 Journey date · यात्रा मिति</a>
  <a href="<?= $base ?>/admin/agent-sales.php?<?= Security::e($carry(['basis' => 'sale'])) ?>" class="<?= $basis === 'sale' ? 'on' : '' ?>"<?= $basis === 'sale' ? ' aria-current="true"' : '' ?>>🧾 Sale date · टिकट काटेको मिति</a>
</nav>
<?php /* Only for the plain window — the cross count ignores the search box and
         the status filter, so with either set it would compare unlike things. */
      if ($q === '' && $status === '' && (int) ($otherCount['tickets'] ?? 0) !== (int) $report['tickets']): ?>
  <div class="basis-hint">
    <?php if ($basis === 'journey'): ?>
      Counted by the day the ticket was <b>cut</b>, the same window has <?= (int) $otherCount['tickets'] ?> ticket<?= (int) $otherCount['tickets'] === 1 ? '' : 's' ?> · <?= (int) $otherCount['pax'] ?> जना —
      <a href="<?= $base ?>/admin/agent-sales.php?<?= Security::e($carry(['basis' => 'sale'])) ?>">show by sale date</a>.
    <?php else: ?>
      Counted by the day the passengers <b>travel</b>, the same window has <?= (int) $otherCount['tickets'] ?> ticket<?= (int) $otherCount['tickets'] === 1 ? '' : 's' ?> · <?= (int) $otherCount['pax'] ?> जना —
      <a href="<?= $base ?>/admin/agent-sales.php?<?= Security::e($carry(['basis' => null])) ?>">show by journey date</a>.
    <?php endif; ?>
  </div>
<?php endif; ?>
<form class="toolbar" method="get">
  <?php if ($basis === 'sale'): ?><input type="hidden" name="basis" value="sale"><?php endif; ?>
  <?php if ($agentList !== []): ?>
    <select name="agent" title="Agent">
      <?php foreach ($agentList as $a): ?>
        <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $viewId ? 'selected' : '' ?>>
          <?= Security::e($a['full_name'] ?: $a['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  <?php endif; ?>

  <select name="range" onchange="document.getElementById('customDates').style.display = this.value === 'custom' ? 'contents' : 'none'">
    <?php foreach ($presets as $key => $label): ?>
      <option value="<?= $key ?>" <?= $preset === $key ? 'selected' : '' ?>><?= Security::e($label) ?></option>
    <?php endforeach; ?>
  </select>

  <span id="customDates" style="display:<?= $preset === 'custom' ? 'contents' : 'none' ?>">
    <input type="date" name="from" value="<?= Security::e($customFrom ?: date('Y-m-01')) ?>" title="From">
    <input type="date" name="to" value="<?= Security::e($customTo ?: $today) ?>" title="To">
  </span>

  <input type="search" name="q" placeholder="PNR, phone or passenger name" value="<?= Security::e($q) ?>" style="min-width:220px">

  <select name="status">
    <option value="">All statuses</option>
    <?php foreach ($valid as $s): ?>
      <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>

  <button class="btn" type="submit">Search</button>
  <?php if ($q !== '' || $status !== ''): ?>
    <a class="btn ghost" href="<?= $base ?>/admin/agent-sales.php?<?= Security::e($carry(['q' => null, 'status' => null])) ?>">Clear</a>
  <?php endif; ?>
  <?php
  // Carry the SAME date window into the export so the file matches the figures
  // shown above. $to is the exclusive next-day bound; export takes inclusive
  // user dates, so pass the typed custom dates or the preset's inclusive end
  // (= the exclusive bound minus one day). This used to assume the end was
  // always today, so "Yesterday" exported today's sales as well.
  $expFrom = ($preset === 'custom' && $isDate($customFrom)) ? $customFrom : substr((string) $from, 0, 10);
  $expToEx = substr((string) $to, 0, 10);
  $expTo   = ($preset === 'custom' && $isDate($customTo))   ? $customTo   : ($isDate($expToEx) ? addDaysISO($expToEx, -1) : $today);
  ?>
  <a class="btn ghost" style="margin-left:auto" title="The PDF and the CSV list tickets by the day they were cut (sale date)"
     href="<?= $base ?>/admin/agent-sales.php?<?= Security::e($carry(['format' => 'pdf'])) ?>">📄 Download PDF</a>
  <a class="btn ghost" title="The PDF and the CSV list tickets by the day they were cut (sale date)"
     href="<?= $base ?>/admin/export.php?<?= Security::e(http_build_query(['q' => $q, 'status' => $status, 'from' => $expFrom, 'to' => $expTo, 'agent' => $scopeId === null ? $viewId : ''])) ?>">⬇️ Export CSV</a>
</form>

<div class="dash-hero">
  <div class="hcard hc-blue">
    <span class="hicon">🎫</span>
    <div class="hk">Tickets written</div>
    <div class="hv"><?= (int) $report['tickets'] ?></div>
    <div class="hsub"><?= (int) $report['confirmed'] ?> confirmed<?= (int) $report['cancelled'] > 0 ? ' · ' . (int) $report['cancelled'] . ' cancelled' : '' ?></div>
  </div>
  <div class="hcard hc-green">
    <span class="hicon">💰</span>
    <div class="hk">Revenue</div>
    <div class="hv"><?= Security::e(inr((float) $report['revenue'])) ?></div>
    <div class="hsub">confirmed sales only</div>
  </div>
  <div class="hcard hc-teal">
    <span class="hicon">🤝</span>
    <div class="hk">Commission earned</div>
    <div class="hv"><?= Security::e(inr($commissionRange)) ?></div>
    <div class="hsub">net of reversals, from the wallet ledger</div>
  </div>
  <div class="hcard hc-navy">
    <span class="hicon">💺</span>
    <div class="hk">Passengers · जना</div>
    <div class="hv"><?= $seatsSold ?></div>
    <div class="hsub">seats on confirmed tickets</div>
  </div>
</div>

<p class="muted" style="font-size:12.5px;margin:-8px 0 18px">
  <?= Security::e($presets[$preset]) ?> · <?= Security::e(formatDate($from)) ?>
  to <?= Security::e(formatDate(addDaysISO($to, -1))) ?>
  · by <?= $basis === 'sale' ? 'the day the ticket was cut' : 'the day the passenger travels' ?>
  <?= $isOwn ? '· your own sales only' : '' ?>
</p>

<?php if ($daily !== []): ?>
<div class="dash-panel" style="margin-bottom:24px">
  <div class="dp-head">📅 Day by Day · <?= $basis === 'sale' ? 'sale date' : 'journey date' ?></div>
  <div style="overflow-x:auto">
  <table>
    <thead><tr><th>Date</th><th>Tickets</th><th>जना</th><th style="text-align:right">Revenue</th><th style="width:40%">Share</th></tr></thead>
    <tbody>
    <?php
      $peak = 0.0;
      foreach ($daily as $d) { $peak = max($peak, (float) $d['revenue']); }
      foreach ($daily as $d):
        $pct = $peak > 0 ? (int) round((float) $d['revenue'] * 100 / $peak) : 0;
    ?>
      <tr>
        <td><strong><?= Security::e(formatDate((string) $d['d'])) ?></strong></td>
        <td><?= (int) $d['tickets'] ?></td>
        <td><?= (int) ($d['pax'] ?? 0) ?></td>
        <td style="text-align:right"><?= Security::e(inr((float) $d['revenue'])) ?></td>
        <td>
          <div class="occ-bar" style="height:10px;margin:0">
            <div class="occ-fill occ-blue" style="width:<?= $pct ?>%"></div>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="dash-panel">
  <div class="dp-head">🎫 <?= count($rows) ?> ticket<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 300 ? ' (showing latest 300)' : '' ?></div>
  <div style="overflow-x:auto">
  <table class="card-table">
    <thead><tr><th>PNR</th><th>Passenger</th><th>Route / Travel</th><th>Seats</th><th>Amount</th><th>Source</th><th>Status</th><th>Sold</th><th>Ticket</th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="9" class="muted" style="padding:22px;text-align:center">
        No tickets in this range<?= $q !== '' ? ' matching “' . Security::e($q) . '”' : '' ?>.
      </td></tr>
    <?php else: foreach ($rows as $b): ?>
      <tr>
        <td class="mono" data-label="PNR"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $b['pnr']) ?>"><?= Security::e((string) $b['pnr']) ?></a></td>
        <td data-label="Passenger"><span><?= Security::e((string) ($b['passenger'] ?? '—')) ?>
          <div class="muted mono" style="font-size:11px"><?= Security::e(maskPhone((string) $b['contact_phone'])) ?></div></span></td>
        <td data-label="Route / Travel"><span><?= Security::e(($b['from_city'] ?? '—') . ' → ' . ($b['to_city'] ?? '—')) ?>
          <div class="muted"><?= $b['travel_date'] ? Security::e(formatDate((string) $b['travel_date'])) : '' ?></div>
          <?php $rBus = !empty($b['travel_date']) ? admin_bus_tag((int) ($b['slot'] ?? 1), (string) ($b['bus_number'] ?? ''), (string) ($b['bus_name'] ?? ''),
                    isset($rowsMulti[(int) ($b['route_id'] ?? 0) . '|' . (string) $b['travel_date']])) : '';
                if ($rBus !== ''): ?><div class="bus-mini">🚌 <?= Security::e($rBus) ?></div><?php endif; ?></span></td>
        <td class="mono" data-label="Seats"><span><?= Security::e((string) ($b['seats'] ? Seats::displayLabels(explode(' ', (string) $b['seats']), 'sleeper', (string) ($b['booking_mode'] ?? 'sharing'), ' ') : '—')) ?>
          <div class="muted" style="font-size:11px"><?= (int) $b['seat_count'] ?> seat<?= (int) $b['seat_count'] === 1 ? '' : 's' ?></div></span></td>
        <td data-label="Amount"><?= Security::e(inr((float) $b['total_amount'])) ?></td>
        <td data-label="Source"><span class="src-badge <?= Security::e((string) ($b['source'] ?? 'web')) ?>"><?= Security::e(ucfirst((string) ($b['source'] ?? 'web'))) ?></span></td>
        <td data-label="Status"><?= admin_pill((string) $b['status']) ?></td>
        <td class="muted" data-label="Sold"><?= Security::e(timeAgo((string) $b['created_at'])) ?></td>
        <td data-label="Ticket">
          <?php if ((string) $b['status'] === 'confirmed'): ?>
            <a class="btn ghost" style="padding:5px 10px;font-size:12px"
               href="<?= Security::e(Ticket::imageUrl((string) $b['pnr'])) ?>" target="_blank">🎟️ Ticket</a>
            <a class="btn ghost" style="padding:5px 10px;font-size:12px"
               href="<?= Security::e(Ticket::downloadUrl((string) $b['pnr'])) ?>" target="_blank">📄 PDF</a>
          <?php else: ?>
            <span class="muted" style="font-size:12px">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php
admin_footer();
