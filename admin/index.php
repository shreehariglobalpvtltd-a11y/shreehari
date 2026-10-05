<?php
/**
 * admin/index.php — enhanced dashboard.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot();

if (($admin['role'] ?? '') === 'agent') { header('Location: /admin/agent.php'); exit; }
if (($admin['role'] ?? '') === 'counter') { header('Location: /admin/bookings.php'); exit; }
Auth::requireAdmin('dashboard.view');

// TripStatus (Phase 2) — the 9-state ladder used for the Live Bus Status
// panel and the "Today's Trips / Departing Soon / Departed / On Route"
// hero KPIs. Loaded here so both the initial render and the JSON poll at
// admin/api/live-status.php share one derivation.
require_once INCLUDE_PATH . '/tripstatus.php';
require_once INCLUDE_PATH . '/tripnotify.php';
require_once INCLUDE_PATH . '/aiwatch.php';   // AI Watch panel (2 Oct 2026)

$base      = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$today     = todayISO();
// T8-DATE-PARAM begin — optional ?date=YYYY-MM-DD lets the operator snapshot the
// dashboard on a past or future date. Falls back silently to today when the
// input is missing or fails Security::isValidDate. All "today" KPIs and the
// trips list re-run against the chosen date; the 7-day sparkline and month-
// to-date totals stay wall-clock (they're not a per-day view).
$requestedDate = isset($_GET['date']) ? (string) $_GET['date'] : '';
if ($requestedDate !== '' && Security::isValidDate($requestedDate)) {
    $today = $requestedDate;
}
$isTodayView = ($today === todayISO());
// T8-DATE-PARAM end
$todayEnd  = addDaysISO($today, 1);
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-01', strtotime('+1 month'));

require_once INCLUDE_PATH . '/moneysummary.php';
/* Oct 2026 redesign: the money tiles read MoneySummary, which uses the exact
   definitions of admin/accounting.php (collected excludes cash still to be
   collected; NPR converted at the owner's npr_per_inr). */
$moneyDay   = MoneySummary::window($today, $today);
$moneyMonth = MoneySummary::window($monthStart, addDaysISO($monthEnd, -1));
// Company-wide money (tiles + revenue chart) is shown to the accounts roles
// only — the same reports.view gate as Accounting and Payroll.
$seeMoney   = Auth::can('reports.view');

$stats = [
    'pendingPay'   => (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status='pending'"),
    'todayBookings'=> (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE created_at>=:d0 AND created_at<:d1", ['d0' => $today, 'd1' => $todayEnd]),
    'confirmed'    => (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status='confirmed'"),
    'revenueToday' => (float) Database::scalar("SELECT COALESCE(SUM(total_amount),0) FROM bookings WHERE status='confirmed' AND confirmed_at>=:d0 AND confirmed_at<:d1", ['d0' => $today, 'd1' => $todayEnd]),
    'revenueMonth' => (float) Database::scalar("SELECT COALESCE(SUM(total_amount),0) FROM bookings WHERE status='confirmed' AND confirmed_at>=:m0 AND confirmed_at<:m1", ['m0' => $monthStart, 'm1' => $monthEnd]),
    'seatsToday'   => (int) Database::scalar("SELECT COUNT(*) FROM booking_seats bs JOIN bookings b ON b.id=bs.booking_id WHERE b.status='confirmed' AND b.confirmed_at>=:d0 AND b.confirmed_at<:d1", ['d0' => $today, 'd1' => $todayEnd]),
    'totalPax'     => (int) Database::scalar("SELECT COUNT(*) FROM booking_passengers bp JOIN bookings b ON b.id=bp.booking_id WHERE b.status='confirmed' AND b.confirmed_at>=:d0 AND b.confirmed_at<:d1", ['d0' => $today, 'd1' => $todayEnd]),
];

/* The four explicitly-named cards the master prompt asks for: active buses,
   active agents, cancelled today, commission this month. Each is a cheap
   scalar; agent_ledger ships in an upgrade migration, so its query is guarded
   so a not-yet-migrated DB shows 0 rather than 500-ing the whole dashboard. */
try { $activeBuses = (int) Database::scalar("SELECT COUNT(*) FROM buses WHERE is_active=1"); }
catch (Throwable $e) { $activeBuses = 0; }
try { $activeAgents = (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE role='agent' AND is_active=1"); }
catch (Throwable $e) { $activeAgents = 0; }
$cancelledToday = (int) Database::scalar(
    "SELECT COUNT(*) FROM bookings WHERE status='cancelled' AND cancelled_at>=:d0 AND cancelled_at<:d1",
    ['d0' => $today, 'd1' => $todayEnd]
);
try {
    // Net commission accrued this month (credits minus voids on the commission account).
    $commissionMonth = (float) Database::scalar(
        "SELECT COALESCE(SUM(amount),0) FROM agent_ledger
          WHERE account='commission' AND entry_type IN ('commission','commission_void')
            AND created_at>=:m0 AND created_at<:m1",
        ['m0' => $monthStart, 'm1' => $monthEnd]
    );
} catch (Throwable $e) { $commissionMonth = 0.0; }

/* Top-performing agents this month (Point 9) — same attribution as
   admin/agent-ranking.php (bookings.sold_by_admin_id, confirmed sales), but a
   compact top-3 so a non-technical admin sees it on the dashboard itself
   instead of navigating to Insights. Guarded — an empty roster shows nothing. */
$topAgents = [];
try {
    $topAgents = Database::fetchAll(
        "SELECT a.id, a.full_name, a.username,
                COALESCE(SUM(CASE WHEN b.status='confirmed' THEN 1 ELSE 0 END),0)            AS confirmed,
                COALESCE(SUM(CASE WHEN b.status='confirmed' THEN b.total_amount ELSE 0 END),0) AS revenue
           FROM admins a
           LEFT JOIN bookings b
                  ON b.sold_by_admin_id = a.id
                 AND b.created_at >= :m0 AND b.created_at < :m1
          WHERE a.role='agent' AND a.is_active=1
          GROUP BY a.id, a.full_name, a.username
         HAVING confirmed > 0
          ORDER BY confirmed DESC, revenue DESC
          LIMIT 3",
        ['m0' => $monthStart, 'm1' => $monthEnd]
    );
} catch (Throwable $e) { $topAgents = []; }

/* 5 Oct 2026: "today by channel" reads the ONE tag every booking carries
   (includes/bookingchannel.php) - Online / Agent / Counter / Admin / WhatsApp /
   Google Ads. The old split grouped the raw source column, so an agent's sale
   through the public page with their code was counted as Online. */
$chan      = BookingChannel::counts($today, $today);
$chanTotal = (int) array_sum(array_column($chan, 'n'));
$chanLink  = static fn(string $src): string => '/admin/bookings.php?' . http_build_query(['source' => $src, 'from' => $today, 'to' => $today]);
$npTeam    = Settings::nepalTeam();

$seatsTotalToday = (int) Database::scalar("SELECT COALESCE(SUM(total_seats),0) FROM schedules WHERE travel_date=:t", ['t' => $today]);
// released_at IS NULL — match the authoritative per-trip 'sold' count (below).
// Without it, cancelled/released seats still counted as booked, inflating
// "Booked" and deflating "Available" on the occupancy bar.
/* PHYSICAL berths, not rows: a private cabin is one booking_seats row over
   two berths, so the occupancy bar under-stated how full today's coaches are. */
$seatsOccToday   = array_sum(Seats::occupiedBedsFor(array_map(
    static fn(array $r): int => (int) $r['id'],
    Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :t', ['t' => $today])
)));
// Soft-held seats (active, unexpired locks) — shown as a separate slice so the
// desk can see inventory that is neither free nor sold yet.
$seatsHeldToday = 0;
try {
    $seatsHeldToday = (int) Database::scalar(
        "SELECT COUNT(*) FROM seat_locks sl JOIN schedules s ON s.id=sl.schedule_id
          WHERE s.travel_date=:t AND sl.expires_at > NOW()", ['t' => $today], 0);
} catch (Throwable $e) { /* seat_locks optional; occupancy still renders */ }
$seatsAvailToday = max(0, $seatsTotalToday - $seatsOccToday - $seatsHeldToday);
$occPct = $seatsTotalToday > 0 ? round(($seatsOccToday / $seatsTotalToday) * 100) : 0;

/* 7-day revenue sparkline — one GROUP BY over the window (was 7 queries;
   5 Sep 2026), gaps filled with 0 so the chart keeps every day. */
$spark = [];
$sparkFrom = date('Y-m-d', strtotime('-6 days'));
$sparkTo   = date('Y-m-d', strtotime('+1 day'));
$sparkRows = [];
try {
    foreach (Database::fetchAll(
        "SELECT DATE(confirmed_at) AS d, COALESCE(SUM(total_amount),0) AS v FROM bookings
          WHERE status='confirmed' AND confirmed_at >= :d0 AND confirmed_at < :d1 GROUP BY DATE(confirmed_at)",
        ['d0' => $sparkFrom, 'd1' => $sparkTo]
    ) as $sr) { $sparkRows[(string) $sr['d']] = (float) $sr['v']; }
} catch (Throwable $e) { $sparkRows = []; }
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $spark[] = ['day' => date('D', strtotime($d)), 'date' => date('d', strtotime($d)), 'val' => $sparkRows[$d] ?? 0.0];
}
$sparkMax = max(1, max(array_column($spark, 'val')));

/* Today's trips with occupancy + the columns TripStatus needs to
   compute a state (arr_time, day_offset, delay_minutes) and the
   columns the Live Bus Status card wants (bus_number, driver_name).
   The LEFT JOINs let a trip with no assigned bus/driver still show
   up rather than disappearing from the dashboard. */
/* dep_time is the effective SCHEDULED departure — per-schedule
   dep_time_override (delay-workflow reschedule) wins over the route
   timetable so both the Live Bus Status pill and the row ordering
   follow the new time. delay_minutes stays a separate DELTA consumed
   by TripStatus::compute(). Blocked schedules are still shown to
   admins (T5 will paint a "blocked" pill on top) — only the customer
   search / public timetable hide them. */
$trips = Database::fetchAll(
    "SELECT s.id, s.travel_date, s.status, s.delay_minutes, s.delay_note, s.total_seats,
            s.is_blocked,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
            r.arr_time, r.day_offset,
            r.from_city, r.to_city, r.route_code AS code,
            COALESCE(s.dep_time_override, r.dep_time) AS departure_time,
            bu.bus_number, bu.bus_name,
            d.full_name AS driver_name, d.phone AS driver_phone,
            (SELECT COUNT(*) FROM booking_seats bs
              WHERE bs.schedule_id=s.id AND bs.released_at IS NULL) AS sold
       FROM schedules s
       JOIN routes r ON r.id=s.route_id
       LEFT JOIN buses   bu ON bu.id = s.bus_id
       LEFT JOIN drivers d  ON d.id  = s.driver_id
      WHERE s.travel_date=:t AND r.is_active=1
      ORDER BY COALESCE(s.dep_time_override, r.dep_time) ASC",
    ['t' => $today]
);
$trips = TripStatus::annotate($trips);

// Hero-row KPIs for the third stat strip (the Control Center row).
$tripKpi = ['total' => count($trips), 'departingSoon' => 0, 'departed' => 0, 'onRoute' => 0];
foreach ($trips as $t) {
    $state = $t['_status']['state'];
    if ($state === TripStatus::STATE_BOARDING || $state === TripStatus::STATE_DEPARTING) {
        $tripKpi['departingSoon']++;
    }
    if (in_array($state, [
        TripStatus::STATE_DEPARTED, TripStatus::STATE_DELAYED,
        TripStatus::STATE_ON_ROUTE, TripStatus::STATE_ARRIVED, TripStatus::STATE_COMPLETED,
    ], true)) {
        $tripKpi['departed']++;
    }
    if (in_array($state, [TripStatus::STATE_ON_ROUTE, TripStatus::STATE_ARRIVED], true)) {
        $tripKpi['onRoute']++;
    }
}

$recent = Database::fetchAll(
    "SELECT b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, b.source,
            r.from_city, r.to_city
       FROM bookings b
       LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
       LEFT JOIN schedules s ON s.id=bl.schedule_id
       LEFT JOIN routes r ON r.id=s.route_id
      ORDER BY b.id DESC LIMIT 8"
);

admin_header('Dashboard', 'index');

$e = static fn($v): string => Security::e((string) $v);
$dateForm = '<form method="get" class="row" style="gap:8px;margin:0">'
    . '<label for="dashDate" class="text-sm fw7 muted" style="display:inline-flex;align-items:center;gap:6px"><svg class="a-ic sm"><use href="#a-calendar"/></svg>Snapshot</label>'
    . '<input id="dashDate" class="inp" type="date" name="date" value="' . $e($today) . '" onchange="this.form.submit()" style="min-height:38px;padding:6px 10px">'
    . (!$isTodayView ? '<a class="btn ghost sm" href="' . $base . '/admin/index.php">Back to today</a>' : '')
    . '</form>';
$actions = $dateForm
    . '<a class="btn warn" href="' . $base . '/admin/quick-ticket.php"><svg class="a-ic"><use href="#a-bolt"/></svg>QuickBot Ticket</a>'
    . '<a class="btn" href="/index.php?counter=1#/"><svg class="a-ic"><use href="#a-plus-plain"/></svg>New booking</a>'
    // 17 Sep 2026: today's office summary to the admin WhatsApp number in one
    // click (admin/api/wa-send.php). Renders nothing when wa_admin_tools_enabled
    // is off or the role lacks dashboard.view — the dashboard itself is untouched.
    . admin_wa_button('admin_daily_summary', ['on' => todayISO()], 'Today on WhatsApp', ['class' => 'btn ghost']);
admin_page_head(
    $isTodayView ? 'Live view of today\'s tickets, money and buses.' : 'Static snapshot for ' . formatDate($today) . ' — live numbers are paused.',
    [],
    $actions
);
?>

<?php if (!$isTodayView): ?>
  <div class="flash warn"><svg class="a-ic"><use href="#a-info"/></svg>You are viewing <b><?= $e(formatDate($today)) ?></b>. Live bus status and the payment badge only update on today's view.</div>
<?php endif; ?>

<?php /* 5 Oct 2026: the Nepal office's people, one tap to call or WhatsApp
   (Admin -> Company details; a person shows once a number is typed). */ ?>
<?php if ($npTeam !== []): ?>
<div class="np-strip" aria-label="Nepal office contacts">
  <span class="np-strip__h"><svg viewBox="0 0 26 32" width="15" height="19" aria-hidden="true"><path d="M2 2 23 15H9.6L23 30H2Z" fill="#DC143C" stroke="#003893" stroke-width="2.4" stroke-linejoin="round"/><circle cx="8" cy="12.4" r="2.2" fill="#fff"/><circle cx="8.3" cy="23.4" r="2.7" fill="#fff"/></svg>Nepal office</span>
  <?php foreach ($npTeam as $p): ?>
  <span class="np-strip__p">
    <span class="np-strip__who"><small><?= $e($p['role']) ?></small><b><?= $e($p['name']) ?></b></span>
    <a class="np-strip__b" href="tel:+<?= $e($p['tel']) ?>" aria-label="Call <?= $e($p['name']) ?>">📞 <?= $e($p['phone']) ?></a>
    <a class="np-strip__b wa" href="https://wa.me/<?= $e($p['tel']) ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp <?= $e($p['name']) ?>">💬</a>
  </span>
  <?php endforeach; ?>
  <?php if (count($npTeam) < 3 && Auth::canManageSettings()): ?><a class="np-strip__add" href="/admin/company.php">+ add the missing number<?= count($npTeam) < 2 ? 's' : '' ?></a><?php endif; ?>
</div>
<?php endif; ?>

<?php /* AI Watch (2 Oct 2026): what needs a person right now, most urgent
   first, each with a one-tap button. Live view only - a past snapshot
   has nothing left to act on. */ ?>
<?php if ($isTodayView) { echo AiWatch::render(AiWatch::forOffice()); } ?>

<!-- QuickBot promo (the desk's fast lane) -->
<a class="promo" href="/admin/quick-ticket.php">
  <span class="promo-ico"><svg class="a-ic"><use href="#a-bolt"/></svg></span>
  <span class="promo-txt"><b>QuickBot Ticket — 10-second booking <em>AI</em></b><small>One line — "Ram 9876543210 2 seats Mehsana kal" or just name + mobile. QuickBot fills the route, date, pickup, seat and fare from the passenger's history, confirms, and sends the PNG ticket on WhatsApp.</small></span>
  <span class="promo-cta">Open desk →</span>
</a>

<!-- Money for the day and the month (Oct 2026 redesign). Each tile says what
     it counts — the same definitions as Accounting, so the two always agree. -->
<?php $accDay = $base . '/admin/accounting.php?from=' . rawurlencode($today) . '&to=' . rawurlencode($today); ?>
<?php $accMonth = $base . '/admin/accounting.php?from=' . rawurlencode($monthStart) . '&to=' . rawurlencode(addDaysISO($monthEnd, -1)); ?>
<?php if ($seeMoney): ?>
<h2 class="dash-sec">Money · <?= $isTodayView ? 'today' : $e(formatDate($today)) ?></h2>
<div class="sg sg-stats dash-money">
  <a class="sg-stat" href="<?= $e($accDay) ?>">
    <span class="sg-stat__label">Collected</span>
    <span class="sg-stat__value"><?= $e(inr($moneyDay['collected'])) ?></span>
    <span class="sg-stat__def">Money actually received: <?= (int) $moneyDay['collectedCount'] ?> confirmed booking<?= $moneyDay['collectedCount'] === 1 ? '' : 's' ?>, cash still to collect not counted</span>
  </a>
  <a class="sg-stat" href="<?= $e($accDay) ?>">
    <span class="sg-stat__label">Confirmed fares</span>
    <span class="sg-stat__value"><?= $e(inr($moneyDay['confirmedFare'])) ?></span>
    <span class="sg-stat__def">Fare of the <?= (int) $moneyDay['confirmedCount'] ?> booking<?= $moneyDay['confirmedCount'] === 1 ? '' : 's' ?> confirmed this day (revenue, collected or not) · <?= (int) $stats['seatsToday'] ?> seats</span>
  </a>
  <a class="sg-stat<?= ($moneyDay['codOutstanding'] + $moneyDay['agentCash']) > 0.5 ? ' sg-stat--attention' : '' ?>" href="<?= $base ?>/admin/payments.php">
    <span class="sg-stat__label">Still to collect · now</span>
    <span class="sg-stat__value"><?= $e(inr($moneyDay['codOutstanding'] + $moneyDay['agentCash'])) ?></span>
    <span class="sg-stat__def"><?= $e(inr($moneyDay['codOutstanding'])) ?> cash on boarding not yet collected (<?= (int) $moneyDay['codOutstandingCount'] ?>) + <?= $e(inr($moneyDay['agentCash'])) ?> ticket cash sellers still hold (agents, counters, office)</span>
  </a>
  <a class="sg-stat<?= $stats['pendingPay'] > 0 ? ' sg-stat--attention' : '' ?>" href="<?= $base ?>/admin/payments.php">
    <span class="sg-stat__label">To verify</span>
    <span class="sg-stat__value"><?= (int) $stats['pendingPay'] ?></span>
    <span class="sg-stat__def">Bookings waiting for payment verification<?= $stats['pendingPay'] > 0 ? ' — open Verify payments' : '' ?></span>
  </a>
</div>
<h2 class="dash-sec">Money · <?= $e(date('F Y', strtotime($monthStart))) ?></h2>
<div class="sg sg-stats dash-money">
  <a class="sg-stat" href="<?= $e($accMonth) ?>">
    <span class="sg-stat__label">Collected</span>
    <span class="sg-stat__value"><?= $e(inr($moneyMonth['collected'])) ?></span>
    <span class="sg-stat__def">Money received this month (same rule as above)</span>
  </a>
  <a class="sg-stat" href="<?= $e($accMonth) ?>">
    <span class="sg-stat__label">Confirmed fares</span>
    <span class="sg-stat__value"><?= $e(inr($moneyMonth['confirmedFare'])) ?></span>
    <span class="sg-stat__def"><?= (int) $moneyMonth['confirmedCount'] ?> bookings confirmed this month (revenue)</span>
  </a>
  <a class="sg-stat" href="<?= $base ?>/admin/agents.php">
    <span class="sg-stat__label">Commission</span>
    <span class="sg-stat__value"><?= $e(inr($commissionMonth)) ?></span>
    <span class="sg-stat__def">Accrued to agents this month, net of reversals (agent ledger)</span>
  </a>
  <a class="sg-stat" href="<?= $e($accMonth) ?>">
    <span class="sg-stat__label">Profit</span>
    <?php if ($moneyMonth['expenseRows'] === 0): ?>
    <span class="sg-stat__value is-na">Not available</span>
    <span class="sg-stat__def">No expenses recorded this month. Add diesel, toll, crew and office costs in Accounting to see profit.</span>
    <?php else: ?>
    <span class="sg-stat__value is-na">See Accounting</span>
    <span class="sg-stat__def"><?= $e(inr($moneyMonth['expenses'])) ?> expenses recorded (<?= (int) $moneyMonth['expenseRows'] ?>). Accounting shows net after expenses &amp; salaries.</span>
    <?php endif; ?>
  </a>
</div>
<?php else: ?>
<div class="sg sg-stats dash-money">
  <a class="sg-stat<?= $stats['pendingPay'] > 0 ? ' sg-stat--attention' : '' ?>" href="<?= $base ?>/admin/payments.php">
    <span class="sg-stat__label">To verify</span>
    <span class="sg-stat__value"><?= (int) $stats['pendingPay'] ?></span>
    <span class="sg-stat__def">Bookings waiting for payment verification</span>
  </a>
</div>
<p class="muted text-sm">Company money figures are shown to the accounts roles (manager, accountant, super-admin).</p>
<?php endif; ?>

<!-- Operations strip (light KPI tiles; the four trip tiles are live-updated) -->
<div class="kpis">
  <div class="kpi tone-blue"><span class="ki"><svg class="a-ic"><use href="#a-ticket"/></svg></span><div class="kt"><div class="kk">Bookings today</div><div class="kv"><?= (int) $stats['todayBookings'] ?></div><div class="ks"><?= (int) $stats['totalPax'] ?> passengers</div></div><a class="kl" href="<?= $base ?>/admin/bookings.php" aria-label="Bookings"></a></div>
  <div class="kpi tone-navy"><span class="ki"><svg class="a-ic"><use href="#a-bus"/></svg></span><div class="kt"><div class="kk">Today's trips</div><div class="kv" data-live-kpi="todayTrips"><?= (int) $tripKpi['total'] ?></div><div class="ks">scheduled today</div></div><a class="kl" href="<?= $base ?>/admin/trips.php" aria-label="Trips"></a></div>
  <div class="kpi tone-orange"><span class="ki"><svg class="a-ic"><use href="#a-bell"/></svg></span><div class="kt"><div class="kk">Departing soon</div><div class="kv" data-live-kpi="departingSoon"><?= (int) $tripKpi['departingSoon'] ?></div><div class="ks">boarding or ≤3h out</div></div></div>
  <div class="kpi tone-teal"><span class="ki"><svg class="a-ic"><use href="#a-route"/></svg></span><div class="kt"><div class="kk">On route</div><div class="kv" data-live-kpi="onRoute"><?= (int) $tripKpi['onRoute'] ?></div><div class="ks"><span data-live-kpi="departed"><?= (int) $tripKpi['departed'] ?></span> departed today</div></div></div>
  <div class="kpi tone-violet"><span class="ki"><svg class="a-ic"><use href="#a-users"/></svg></span><div class="kt"><div class="kk">Active agents</div><div class="kv"><?= (int) $activeAgents ?></div><div class="ks"><?= (int) $activeBuses ?> active bus<?= (int) $activeBuses === 1 ? '' : 'es' ?></div></div><a class="kl" href="<?= $base ?>/admin/agents.php" aria-label="Agents"></a></div>
  <div class="kpi tone-red"><span class="ki"><svg class="a-ic"><use href="#a-x-circle"/></svg></span><div class="kt"><div class="kk">Cancelled today</div><div class="kv"><?= (int) $cancelledToday ?></div><div class="ks">bookings cancelled</div></div><?php if ($cancelledToday > 0): ?><a class="kl" href="<?= $base ?>/admin/bookings.php?status=cancelled" aria-label="Cancelled"></a><?php endif; ?></div>
</div>

<!-- Bookings today, by how they came in (5 Oct 2026) - each tile opens that list -->
<div class="kpis kpis-chan">
  <?= admin_kpi('Confirmed today', (string) (int) array_sum(array_column($chan, 'confirmed')), $chanTotal . ' placed · ' . (int) array_sum(array_column($chan, 'cancelled')) . ' cancelled', 'ticket', 'green', null, '/admin/bookings.php?' . http_build_query(['status' => 'confirmed', 'from' => $today, 'to' => $today])) ?>
  <?= admin_kpi('Online customers', (string) ($chan['ONLINE']['n'] + $chan['GOOGLE_ADS']['n']), $chan['GOOGLE_ADS']['n'] > 0 ? $chan['GOOGLE_ADS']['n'] . ' from Google Ads 🎉' : 'booked on the website themselves', 'globe', 'teal', null, $chanLink('online')) ?>
  <?= admin_kpi('Agent bookings', (string) $chan['AGENT']['n'], 'agent login or agent code', 'users', 'blue', null, $chanLink('agent')) ?>
  <?= admin_kpi('WhatsApp requests', (string) $chan['WHATSAPP']['n'], 'booked from a WhatsApp message', 'msg', 'violet', null, $chanLink('whatsapp')) ?>
</div>

<!-- Quick actions -->
<div class="quick-actions">
  <a class="qa" href="<?= $base ?>/admin/payments.php"><span class="qa-icon"><svg class="a-ic"><use href="#a-card"/></svg></span>Verify payments<?php if ($stats['pendingPay'] > 0): ?><span class="qa-badge"><?= (int) $stats['pendingPay'] ?></span><?php endif; ?></a>
  <a class="qa" href="<?= $base ?>/admin/manifest.php"><span class="qa-icon"><svg class="a-ic"><use href="#a-clipboard"/></svg></span>Today's manifest</a>
  <a class="qa" href="<?= $base ?>/admin/scan.php"><span class="qa-icon"><svg class="a-ic"><use href="#a-scan"/></svg></span>Scan Ticket</a>
  <a class="qa" href="<?= $base ?>/admin/bookings.php"><span class="qa-icon"><svg class="a-ic"><use href="#a-ticket"/></svg></span>All tickets</a>
  <a class="qa" href="<?= $base ?>/admin/seatmap.php"><span class="qa-icon"><svg class="a-ic"><use href="#a-seat"/></svg></span>Seat map</a>
  <a class="qa" href="<?= $base ?>/admin/trips.php"><span class="qa-icon"><svg class="a-ic"><use href="#a-clock"/></svg></span>Trips board</a>
</div>

<!-- Revenue chart + channel donut -->
<div class="dash-grid two-one">
  <?php if ($seeMoney): ?>
  <div class="dash-panel">
    <div class="dp-head"><svg class="a-ic" style="color:var(--blue)"><use href="#a-chart"/></svg>Revenue — last 7 days</div>
    <div class="dp-body">
      <div class="spark-wrap">
        <?php foreach ($spark as $s): $h = $sparkMax > 0 ? max(4, ($s['val'] / $sparkMax) * 100) : 4; ?>
        <div class="spark-bar" style="height:<?= $h ?>%">
          <div class="spark-tip"><?= $e(inr($s['val'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="spark-labels">
        <?php foreach ($spark as $s): ?>
        <span><?= $s['day'] ?><br><?= $s['date'] ?></span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="dash-panel">
    <div class="dp-head"><svg class="a-ic" style="color:var(--blue)"><use href="#a-layers"/></svg>Today by channel</div>
    <div class="dp-body">
      <div class="donut-wrap">
        <svg class="donut-svg" viewBox="0 0 42 42" role="img" aria-label="Bookings today by channel">
          <circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--hover)" stroke-width="6"/>
          <?php $__acc = 0.0; foreach (BookingChannel::TAGS as $__t): $__n = $chan[$__t]['n']; if ($__n <= 0) { continue; }
                $__pct = $__n / max(1, $chanTotal) * 100; ?>
          <circle cx="21" cy="21" r="15.9" fill="none" stroke="<?= $e(BookingChannel::META[$__t][3]) ?>" stroke-width="6"
                  stroke-dasharray="<?= round($__pct, 2) ?> <?= round(100 - $__pct, 2) ?>" stroke-dashoffset="<?= round(25 - $__acc, 2) ?>"/>
          <?php $__acc += $__pct; endforeach; ?>
          <text x="21" y="22.5" text-anchor="middle" font-size="7" font-weight="800" fill="var(--ink)"><?= $chanTotal ?></text>
          <text x="21" y="26.5" text-anchor="middle" font-size="3" fill="var(--mut)">total</text>
        </svg>
        <div class="donut-legend">
          <?php foreach (BookingChannel::FILTERS as $__src => $__t): $__n = $chan[$__t]['n']; ?>
          <a class="donut-item" href="<?= $e($chanLink($__src)) ?>" style="text-decoration:none;color:inherit"><span class="donut-dot" style="background:<?= $e(BookingChannel::META[$__t][3]) ?>"></span><?= $e(BookingChannel::label($__t)) ?>: <strong><?= $__n ?></strong> <span class="muted">(<?= $chanTotal > 0 ? (int) round($__n / $chanTotal * 100) : 0 ?>%)</span></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="divider"></div>
      <div class="text-sm fw7" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
        <span>Seat occupancy today</span>
        <span style="color:<?= $occPct > 80 ? '#e74c3c' : ($occPct > 50 ? '#f39c12' : '#27ae60') ?>"><?= (int) $occPct ?>% filled</span>
      </div>
      <div class="occ-bar" style="height:14px;border-radius:8px">
        <div class="occ-fill <?= $occPct > 80 ? 'occ-red' : ($occPct > 50 ? 'occ-yellow' : 'occ-green') ?>" style="width:<?= (int) $occPct ?>%"></div>
      </div>
      <div class="text-xs muted"><?= (int) $seatsOccToday ?> booked<?php if ($seatsHeldToday > 0): ?> · <span style="color:#B85A00"><?= (int) $seatsHeldToday ?> held</span><?php endif; ?> · <?= (int) $seatsAvailToday ?> free of <?= (int) $seatsTotalToday ?></div>
    </div>
  </div>
</div>

<!-- Live bus status + top agents -->
<div class="dash-grid two-one">
  <div class="dash-panel">
    <div class="dp-head"><svg class="a-ic" style="color:var(--blue)"><use href="#a-bus"/></svg><?= $isTodayView ? 'Live bus status' : 'Bus status · ' . $e(formatDate($today)) ?>
      <?php if ($isTodayView): ?>
      <span class="live-fresh" id="liveFresh" title="Refreshes every 15 seconds">Live</span>
      <?php else: ?>
      <span class="pill st-muted nodot" style="margin-left:auto">Static snapshot</span>
      <?php endif; ?>
    </div>
    <div class="dp-body" id="liveBusList">
      <?php if ($trips): ?>
      <?php foreach ($trips as $t):
        $tSold  = (int) $t['sold'];
        $tTotal = (int) $t['total_seats'];
        $st     = $t['_status'];
        $busN   = trim((string) ($t['bus_number'] ?? ''));
        $busName= trim((string) ($t['bus_name']   ?? ''));
        $drvN   = trim((string) ($t['driver_name'] ?? ''));
        $drvP   = trim((string) ($t['driver_phone'] ?? ''));
      ?>
      <div class="live-trip" data-trip-id="<?= (int) $t['id'] ?>">
        <div class="live-time"><?= $e(substr((string) ($t['dep_time'] ?? ''), 0, 5)) ?></div>
        <div class="live-route">
          <?= $e(($t['from_city'] ?? '') . ' → ' . ($t['to_city'] ?? '')) ?>
          <small><?= $e((string) ($t['code'] ?? '')) ?></small>
        </div>
        <div class="live-bus">
          <?php if ($busN !== ''): ?>
            <span class="lb-num"><?= $e($busN) ?></span>
            <?php if ($busName !== ''): ?><small><?= $e($busName) ?></small><?php endif; ?>
          <?php else: ?>
            <span class="ld-empty" style="font-style:italic;color:var(--mut)">No bus assigned</span>
          <?php endif; ?>
        </div>
        <div class="live-driver">
          <?php if ($drvN !== ''): ?>
            <svg class="a-ic sm"><use href="#a-user"/></svg> <?= $e($drvN) ?>
            <?php if ($drvP !== ''): ?><small><?= $e($drvP) ?></small><?php endif; ?>
          <?php else: ?>
            <span class="ld-empty">No driver assigned</span>
          <?php endif; ?>
        </div>
        <div class="live-state">
          <span class="state-pill" data-live-pill style="background:<?= $e($st['color']) ?>"><?= $e($st['label']) ?></span>
          <span class="state-detail" data-live-detail><?= $e($st['detail']) ?></span>
          <div class="live-seats" data-live-seats><strong><?= $tSold ?></strong>/<?= $tTotal ?> seats · <?= $tTotal > 0 ? (int) round(($tSold/$tTotal)*100) : 0 ?>%</div>
          <a href="/admin/chalan.php?sid=<?= (int) $t['id'] ?>" class="text-xs fw7" title="Bus chalan — preview, PDF / PNG, WhatsApp"><svg class="a-ic sm"><use href="#a-doc"/></svg> Chalan</a>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <?= admin_empty('No trips scheduled', $isTodayView ? 'Nothing departs today.' : 'Nothing departs on this date.', '🚌') ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="dash-panel">
    <div class="dp-head"><svg class="a-ic" style="color:var(--orange)"><use href="#a-trophy"/></svg>Top agents · <?= $e(date('F')) ?>
      <a href="<?= $base ?>/admin/agent-ranking.php?by=confirmed" class="text-xs fw7" style="margin-left:auto">Leaderboard →</a>
    </div>
    <div class="dp-body">
      <?php if ($topAgents === []): ?>
        <?= admin_empty('No agent sales yet', 'This month is still open.', '🏆') ?>
      <?php else: $medals = ['🥇', '🥈', '🥉']; foreach ($topAgents as $i => $ta):
        $code = AgentWallet::agentCodeLabel((int) $ta['id']);
        $nm = (string) ($ta['full_name'] ?: $ta['username']); ?>
        <div style="display:flex;align-items:center;gap:12px;padding:9px 2px;border-bottom:1px solid var(--line)">
          <?= admin_avatar($nm) ?>
          <div style="flex:1;min-width:0">
            <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $medals[$i] ?? ($i + 1) ?> <?= $e($nm) ?></div>
            <?php if ($code !== ''): ?><div class="mono muted text-xs"><?= $e($code) ?></div><?php endif; ?>
          </div>
          <div style="text-align:right">
            <div style="font-weight:800"><?= (int) $ta['confirmed'] ?> <span class="muted text-xs" style="font-weight:500">ticket<?= (int) $ta['confirmed'] === 1 ? '' : 's' ?></span></div>
            <div class="muted text-xs money"><?= $e(inr((float) $ta['revenue'])) ?></div>
          </div>
        </div>
      <?php endforeach; endif; ?>
      <a class="btn soft sm block" style="margin-top:12px" href="<?= $base ?>/admin/agents.php"><svg class="a-ic"><use href="#a-users"/></svg>All agents &amp; wallets</a>
    </div>
  </div>
</div>

<script>
/* Phase 2 — 15-second poll for the Control Room hero KPIs and the
   Live Bus Status pills. Fetches admin/api/live-status.php, updates
   in place. Everything is best-effort: any fetch failure leaves the
   last known values on screen and tries again in 15s.

   T8-DATE-PARAM: only run when the operator is viewing TODAY. On a
   past/future snapshot the numbers are frozen — no polling, no live
   pill — because "in 12m" or "boarding" is meaningless off-today. */
(function () {
  var IS_TODAY = <?= $isTodayView ? 'true' : 'false' ?>;
  if (!IS_TODAY) return;
  var URL = '/admin/api/live-status.php';
  var freshTag = document.getElementById('liveFresh');

  function fmtFresh() {
    var d = new Date();
    var pad = function (n) { return n < 10 ? '0' + n : String(n); };
    return 'Updated ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
  }

  function poll() {
    fetch(URL, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j) return;
        // KPI hero cards — one selector per key.
        if (j.kpi) {
          Object.keys(j.kpi).forEach(function (k) {
            var el = document.querySelector('[data-live-kpi="' + k + '"]');
            if (el) el.textContent = j.kpi[k];
          });
        }
        // Live Bus Status rows.
        (j.trips || []).forEach(function (t) {
          var row = document.querySelector('.live-trip[data-trip-id="' + t.id + '"]');
          if (!row) return;
          var pill   = row.querySelector('[data-live-pill]');
          var detail = row.querySelector('[data-live-detail]');
          var seats  = row.querySelector('[data-live-seats]');
          if (pill)   { pill.textContent   = t.state_label; pill.style.background = t.state_color; }
          if (detail) { detail.textContent = t.detail || ''; }
          if (seats)  {
            var pct = t.total > 0 ? Math.round((t.sold / t.total) * 100) : 0;
            seats.innerHTML = '<strong>' + t.sold + '</strong>/' + t.total + ' seats · ' + pct + '%';
          }
        });
        if (freshTag) { freshTag.textContent = 'Live · ' + fmtFresh(); freshTag.title = 'Last updated ' + (j.generated_at || ''); }
      })
      .catch(function () { /* leave the last render intact */ });
  }
  poll();                       // first sync as soon as the page settles
  setInterval(poll, 15000);     // then every 15 s — matches Phase 2 spec
})();
</script>

<!-- Recent bookings -->
<div class="dash-panel">
  <div class="dp-head"><svg class="a-ic" style="color:var(--blue)"><use href="#a-history"/></svg>Recent tickets
    <a href="<?= $base ?>/admin/bookings.php" class="text-xs fw7" style="margin-left:auto">All tickets →</a>
  </div>
  <div class="tbl-scroll">
    <table>
      <thead><tr><th>PNR</th><th>Route</th><th>Phone</th><th class="num">Amount</th><th>Source</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
      <?php if ($recent === []): ?>
        <tr><td colspan="7"><?= admin_empty('No bookings yet', 'The first ticket will appear here.', '🎫') ?></td></tr>
      <?php else: foreach ($recent as $b): ?>
        <tr>
          <td class="mono"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($b['pnr']) ?>"><?= $e($b['pnr']) ?></a></td>
          <td><?= $e(($b['from_city'] ?? '—') . ' → ' . ($b['to_city'] ?? '—')) ?></td>
          <td class="mono"><?= $e(maskPhone((string) $b['contact_phone'])) ?></td>
          <td class="num fw7 money"><?= $e(inr((float) $b['total_amount'])) ?></td>
          <td><span class="src-badge <?= $e((string) ($b['source'] ?? 'web')) ?>"><?= $e(ucfirst((string) ($b['source'] ?? 'web'))) ?></span></td>
          <td><?= admin_pill((string) $b['status']) ?></td>
          <td class="muted"><?= $e(timeAgo((string) $b['created_at'])) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php admin_footer(); ?>
