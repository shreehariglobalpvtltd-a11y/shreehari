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
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/commandcenter.php';   // 29 Oct 2026: command center tiles, alerts, counters

$base      = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$today     = todayISO();

// Quick Action POST Processing
$dashFlash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $dashFlash = ['bad', 'Security token expired. Please try again.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        if ($act === 'quick_live_bus') {
            $sid = (int) ($_POST['schedule_id'] ?? 0);
            $loc = trim(Security::clean($_POST['location_name'] ?? '', 120));
            $statusLabel = trim(Security::clean($_POST['status_label'] ?? 'On Route', 60));
            $delay = max(0, (int) ($_POST['delay_minutes'] ?? 0));
            if ($sid > 0) {
                Database::run(
                    "UPDATE schedules SET delay_minutes = :dm, delay_note = :dn WHERE id = :id",
                    ['dm' => $delay, 'dn' => "[$statusLabel] $loc", 'id' => $sid]
                );
                // Also update livebus key
                $lb = [
                    'updated_at'   => date('c'),
                    'location'     => $loc,
                    'status_label' => $statusLabel,
                    'delay'        => $delay,
                    'schedule_id'  => $sid,
                    'by'           => $admin['username'] ?? 'admin'
                ];
                Database::run(
                    "INSERT INTO key_values (k, v) VALUES ('livebus', :v) ON DUPLICATE KEY UPDATE v = :v2",
                    ['v' => json_encode($lb), 'v2' => json_encode($lb)]
                );
                $dashFlash = ['ok', 'Live bus tracking updated: ' . htmlspecialchars($statusLabel . ' - ' . $loc)];
            }
        } elseif ($act === 'quick_broadcast') {
            $msg = trim(Security::clean($_POST['broadcast_message'] ?? '', 500));
            if ($msg !== '') {
                Database::run(
                    "INSERT INTO settings (skey, svalue, stype, sgroup, label, is_public)
                     VALUES ('agent_broadcast_active', :msg, 'string', 'agent', 'Active Broadcast to Agents', 1)
                     ON DUPLICATE KEY UPDATE svalue = :msg2",
                    ['msg' => $msg, 'msg2' => $msg]
                );
                $dashFlash = ['ok', 'Broadcast notice dispatched to all agent counters!'];
            }
        }
    }
}

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

// Today's Cash vs Online Collection (Executive Summary KPI)
$collCashToday = 0.0;
$collOnlineToday = 0.0;
try {
    $collCashToday = (float) Database::scalar(
        "SELECT COALESCE(SUM(p.amount),0) FROM payments p
          JOIN bookings b ON b.id = p.booking_id
         WHERE p.status IN ('verified','completed') AND p.method IN ('cash','cod')
           AND p.created_at >= :d0 AND p.created_at < :d1",
        ['d0' => $today, 'd1' => $todayEnd]
    );
    $collOnlineToday = (float) Database::scalar(
        "SELECT COALESCE(SUM(p.amount),0) FROM payments p
          JOIN bookings b ON b.id = p.booking_id
         WHERE p.status IN ('verified','completed') AND p.method IN ('upi','esewa','bank','wallet')
           AND p.created_at >= :d0 AND p.created_at < :d1",
        ['d0' => $today, 'd1' => $todayEnd]
    );
} catch (Throwable $e) {}

// Pending Digital Form & KYC Approvals (Executive Summary KPI)
$pendingKycCount = 0;
try {
    $pendingKycCount += (int) Database::scalar("SELECT COUNT(*) FROM agent_applications WHERE status IN ('new','reviewing','kyc_pending')");
} catch (Throwable $e) {}
try {
    $pendingKycCount += (int) Database::scalar("SELECT COUNT(*) FROM staff_applications WHERE status IN ('pending','submitted','reviewing')");
} catch (Throwable $e) {}
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

$srcRows = Database::fetchAll("SELECT source, COUNT(*) c FROM bookings WHERE created_at>=:d0 AND created_at<:d1 GROUP BY source", ['d0' => $today, 'd1' => $todayEnd]);
$src = ['online' => 0, 'offline' => 0, 'agent' => 0];
foreach ($srcRows as $r) {
    $s = (string) $r['source']; $c = (int) $r['c'];
    if ($s === 'web' || $s === 'app') $src['online'] += $c;
    elseif ($s === 'agent') $src['agent'] += $c;
    else $src['offline'] += $c;
}
$srcTotal = max(1, $src['online'] + $src['offline'] + $src['agent']);

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

/* Command center (29 Oct 2026, management upgrade 2.4 / 4.3 / 4.4): live
   figures, so only on the today view; a past snapshot keeps the old page. */
$cc = null;
if ($isTodayView) {
    try {
        $cc = [
            'rev'       => CommandCenter::revenue($today),
            'cash'      => CommandCenter::cashDue(),
            'occ'       => CommandCenter::occupancy($today),
            'top'       => CommandCenter::topSellers($today),
            'counters'  => CommandCenter::counterBoard($today),
            'alerts'    => CommandCenter::alerts($today),
            'approvals' => CommandCenter::approvals(),
        ];
    } catch (Throwable $e) {
        Logger::error('command center failed: ' . $e->getMessage());
        $cc = null;   // the rest of the dashboard still renders
    }
}

admin_header('Dashboard', 'index');
?>
<style>
.dash-hero{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.hcard{border-radius:16px;padding:20px 22px;color:#fff;position:relative;overflow:hidden}
.hcard::after{content:'';position:absolute;right:-18px;top:-18px;width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.1)}
.hcard .hicon{font-size:28px;margin-bottom:8px;display:block;filter:drop-shadow(0 2px 4px rgba(0,0,0,.15))}
.hcard .hk{font-size:12px;text-transform:uppercase;letter-spacing:.5px;opacity:.85}
.hcard .hv{font-size:30px;font-weight:800;margin:4px 0 2px;line-height:1.1}
.hcard .hsub{font-size:12px;opacity:.75}
.hcard .hlink{display:inline-block;margin-top:8px;font-size:12px;font-weight:700;color:#fff;background:rgba(255,255,255,.2);padding:4px 12px;border-radius:20px;text-decoration:none}
.hcard .hlink:hover{background:rgba(255,255,255,.35)}
.hc-blue{background:linear-gradient(135deg,#2E5FA8,#1a3d6e)}
.hc-green{background:linear-gradient(135deg,#0a8b4b,#065a30)}
.hc-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.hc-red{background:linear-gradient(135deg,#c0392b,#8e2320)}
.hc-navy{background:linear-gradient(135deg,#12264E,#0b1a36)}
.hc-teal{background:linear-gradient(135deg,#00897b,#00695c)}

.dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px}
.dash-panel{background:var(--card);border:1px solid var(--line);border-radius:16px;overflow:hidden}
.dash-panel .dp-head{padding:16px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px;font-weight:700;font-size:15px;background:var(--head)}
.dash-panel .dp-body{padding:20px}

.spark-wrap{display:flex;align-items:flex-end;gap:6px;height:100px;padding:0 4px}
.spark-bar{flex:1;border-radius:6px 6px 0 0;background:linear-gradient(180deg,#2E5FA8,#1a3d6e);min-width:8px;position:relative;transition:height .3s}
.spark-bar:hover{filter:brightness(1.2)}
.spark-bar .spark-tip{display:none;position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:var(--navy);color:#fff;font-size:11px;padding:3px 8px;border-radius:6px;white-space:nowrap;margin-bottom:4px;font-weight:700}
.spark-bar:hover .spark-tip{display:block}
/* Touch screens have no hover: keep each day's value visible above its bar. */
@media(pointer:coarse){.spark-bar .spark-tip{display:block;font-size:9px;padding:2px 4px;margin-bottom:2px}}
.spark-labels{display:flex;gap:6px;padding:8px 4px 0;font-size:11px;color:var(--mut);text-align:center}
.spark-labels span{flex:1;min-width:8px}

.occ-bar{height:20px;border-radius:10px;background:var(--hover);overflow:hidden;margin:6px 0}
.occ-fill{height:100%;border-radius:10px;transition:width .4s}
.occ-green{background:linear-gradient(90deg,#27ae60,#2ecc71)}
.occ-yellow{background:linear-gradient(90deg,#f39c12,#e67e22)}
.occ-red{background:linear-gradient(90deg,#e74c3c,#c0392b)}

.donut-wrap{display:flex;align-items:center;gap:24px}
.donut-svg{width:120px;height:120px;flex-shrink:0}
.donut-legend{display:flex;flex-direction:column;gap:10px}
.donut-item{display:flex;align-items:center;gap:8px;font-size:14px}
.donut-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0}

.quick-actions{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px}
.qa{display:flex;align-items:center;gap:10px;padding:12px 18px;border-radius:12px;background:var(--card);border:1px solid var(--line);color:var(--ink);text-decoration:none;font-weight:600;font-size:14px;transition:all .15s}
.qa:hover{border-color:var(--blue);background:var(--hover);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.06)}
.qa .qa-icon{font-size:22px}

.trip-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)}
.trip-row:last-child{border-bottom:0}
.trip-time{font-size:13px;font-weight:700;color:var(--blue);width:50px;flex-shrink:0}
.trip-route{flex:1;font-size:14px;font-weight:600}
.trip-route small{display:block;font-weight:400;color:var(--mut);font-size:12px}
.trip-occ{width:140px;flex-shrink:0}
.trip-occ-label{font-size:12px;color:var(--mut);display:flex;justify-content:space-between}
.trip-occ .occ-bar{height:10px;margin:3px 0 0}

.status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.status-dot.confirmed{background:#27ae60}.status-dot.pending{background:#f39c12}.status-dot.expired{background:#95a5a6}.status-dot.cancelled{background:#e74c3c}

.src-badge{font-size:11px;padding:2px 8px;border-radius:20px;font-weight:600;display:inline-block}
.src-badge.web,.src-badge.app{background:#e8f5e9;color:#2e7d32}
.src-badge.agent{background:#e3f2fd;color:#1565c0}
.src-badge.counter,.src-badge.admin{background:#fff3e0;color:#e65100}
:root[data-theme="dark"] .src-badge.web,:root[data-theme="dark"] .src-badge.app{background:#1b3d20;color:#81c784}
:root[data-theme="dark"] .src-badge.agent{background:#0d2948;color:#64b5f6}
:root[data-theme="dark"] .src-badge.counter,:root[data-theme="dark"] .src-badge.admin{background:#3e2723;color:#ffb74d}

@media(max-width:900px){
  .dash-grid{grid-template-columns:1fr}
  .dash-hero{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
}

/* ── Phase 2: Live Bus Status ─────────────────────────────────────
   The trip-row here is a beefed-up variant of the existing occupancy
   row that also carries a bus badge, a driver line, and a coloured
   state pill. Uses [data-live-*] hooks so the 15-second JSON poll
   can update just the pill / seat count in place without rebuilding
   the DOM. */
.live-trip{display:grid;grid-template-columns:64px 1fr 180px 200px 140px;gap:14px;align-items:center;padding:14px 0;border-bottom:1px solid var(--line)}
.live-trip:last-child{border-bottom:0}
.live-time{font-weight:800;color:var(--blue);font-size:15px}
.live-route{font-weight:700;font-size:14px;color:var(--ink)}
.live-route small{display:block;font-weight:500;color:var(--mut);font-size:12px;margin-top:2px}
.live-bus{font-size:13px;color:var(--ink);line-height:1.3}
.live-bus .lb-num{font-weight:800;background:var(--hover);padding:2px 8px;border-radius:6px;font-family:ui-monospace,SFMono-Regular,monospace;font-size:12px}
.live-bus small{display:block;font-size:11px;color:var(--mut);margin-top:2px}
.live-driver{font-size:13px;color:var(--ink);line-height:1.3}
.live-driver .ld-empty{font-style:italic;color:var(--mut);font-size:12px}
.live-driver small{display:block;font-size:11px;color:var(--mut);margin-top:2px}
.live-state{text-align:right}
.state-pill{display:inline-block;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:800;letter-spacing:.3px;color:#fff;text-transform:uppercase;box-shadow:0 1px 3px rgba(0,0,0,.15)}
.state-detail{display:block;font-size:11px;color:var(--mut);margin-top:4px}
.live-seats{font-size:12px;color:var(--mut);margin-top:6px;text-align:right}
.live-seats strong{color:var(--ink);font-weight:700}
.live-fresh{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--mut);margin-left:auto}
.live-fresh::before{content:'';width:8px;height:8px;border-radius:50%;background:#27ae60;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:.4}50%{opacity:1}}

@media(max-width:900px){
  .live-trip{grid-template-columns:1fr;gap:6px;padding:14px 0}
  .live-state{text-align:left}
  .live-seats{text-align:left}
}
</style>

<!-- T8-DATE-PARAM begin — date picker toolbar. Auto-submits on change; blank
     reverts to today. When snapshotting a non-today date the Live Bus Status
     card shows a static badge instead of the 15-second live indicator. -->
<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px;padding:12px 16px;background:var(--card);border:1px solid var(--line);border-radius:12px">
  <form method="get" style="display:flex;align-items:center;gap:10px;margin:0">
    <label for="dashDate" style="font-size:13px;font-weight:700;color:var(--ink)">📅 Snapshot date</label>
    <input id="dashDate" type="date" name="date" value="<?= Security::e($today) ?>" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);font-size:13px">
    <?php if (!$isTodayView): ?>
      <a class="btn btn-ghost" href="<?= $base ?>/admin/index.php" style="font-size:12px;padding:6px 12px">← Back to today</a>
    <?php endif; ?>
  </form>
  <?php if (!$isTodayView): ?>
    <span style="margin-left:auto;font-size:12px;font-weight:700;color:#e67e22;background:#fff3e0;padding:4px 12px;border-radius:20px">Static snapshot · <?= Security::e(formatDate($today)) ?></span>
  <?php endif; ?>
</div>
<!-- T8-DATE-PARAM end -->

<!-- ⚡ Quick Ticket Service (6 Sep 2026): the desk's fast lane — name + mobile → ticket -->
<style>
.qt-dash{display:flex;align-items:center;gap:14px;margin:0 0 20px;padding:14px 18px;border-radius:16px;color:#fff;text-decoration:none;position:relative;overflow:hidden;
  background:linear-gradient(135deg,#12264E 0%,#1C3B72 55%,#2E5FA8 100%);box-shadow:0 8px 24px rgba(18,38,78,.28);border:1px solid rgba(255,255,255,.12);transition:transform .2s,box-shadow .2s}
.qt-dash::before{content:"";position:absolute;right:-60px;bottom:-80px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(240,124,31,.55),transparent 65%);pointer-events:none}
.qt-dash:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(18,38,78,.38)}
.qt-dash-ico{font-size:30px;line-height:1;width:54px;height:54px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.14);flex:0 0 auto}
.qt-dash-txt{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1 1 auto}
.qt-dash-txt b{font-size:17px;font-weight:900}
.qt-dash-txt b em{font-style:normal;font-size:10px;letter-spacing:.1em;text-transform:uppercase;background:var(--orange);padding:2px 7px;border-radius:999px;margin-left:8px;vertical-align:middle}
.qt-dash-txt small{font-size:12.5px;opacity:.88;line-height:1.4}
.qt-dash-cta{flex:0 0 auto;background:var(--orange);color:#fff;font-weight:900;padding:10px 16px;border-radius:999px;font-size:14px;box-shadow:0 4px 14px rgba(240,124,31,.45);white-space:nowrap}
@media(max-width:640px){.qt-dash{flex-wrap:wrap}.qt-dash-cta{width:100%;text-align:center}}
</style>
<style>
/* Executive Summary & Category Switcher Styles */
.exec-header{background:linear-gradient(135deg,var(--card) 0%,var(--hover) 100%);border:1.5px solid var(--line);border-radius:18px;padding:18px 20px;margin-bottom:20px;box-shadow:0 8px 24px rgba(0,0,0,.04)}
.exec-title-row{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.exec-title{display:flex;align-items:center;gap:10px;font-size:18px;font-weight:900;color:var(--ink)}
.exec-title span{font-size:12px;font-weight:700;color:var(--blue);background:rgba(46,95,168,.12);padding:3px 10px;border-radius:20px}

/* Quick Action Bar */
.exec-quick-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.exec-btn{display:inline-flex;align-items:center;gap:8px;padding:9px 15px;border-radius:10px;font-size:13px;font-weight:800;text-decoration:none;cursor:pointer;border:0;transition:all .18s;box-shadow:0 2px 6px rgba(0,0,0,.08)}
.exec-btn:hover{transform:translateY(-1px);filter:brightness(1.08)}
.eb-orange{background:linear-gradient(135deg,#f07c1f,#c95f0c);color:#fff}
.eb-blue{background:linear-gradient(135deg,#2E5FA8,#1a3d6e);color:#fff}
.eb-green{background:linear-gradient(135deg,#0a8b4b,#065a30);color:#fff}
.eb-teal{background:linear-gradient(135deg,#00897b,#00695c);color:#fff}
.eb-navy{background:linear-gradient(135deg,#12264E,#0b1a36);color:#fff}
.eb-ghost{background:var(--card);color:var(--ink);border:1.5px solid var(--line)}
.eb-ghost:hover{background:var(--hover);border-color:var(--blue)}

/* Category Switcher Tabs */
.cat-switcher{display:flex;gap:8px;border-bottom:2px solid var(--line);padding-bottom:12px;margin-bottom:20px;overflow-x:auto}
.cat-tab{padding:10px 18px;border-radius:12px;font-size:13.5px;font-weight:800;cursor:pointer;background:var(--card);border:1.5px solid var(--line);color:var(--mut);display:inline-flex;align-items:center;gap:8px;white-space:nowrap;transition:all .15s}
.cat-tab:hover{color:var(--ink);border-color:var(--blue)}
.cat-tab.active{background:var(--blue);color:#fff;border-color:var(--blue);box-shadow:0 4px 12px rgba(46,95,168,.3)}
.cat-tab .badge{padding:2px 7px;border-radius:12px;font-size:11px;font-weight:900;background:rgba(255,255,255,.25);color:#fff}
.cat-tab:not(.active) .badge{background:var(--hover);color:var(--ink)}

/* High-Density KPI Grid */
.exec-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:22px}
.ek-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px;display:flex;flex-direction:column;gap:4px;position:relative;overflow:hidden}
.ek-card .ek-label{font-size:11.5px;text-transform:uppercase;letter-spacing:.5px;font-weight:800;color:var(--mut)}
.ek-card .ek-val{font-size:24px;font-weight:900;color:var(--ink);line-height:1.15}
.ek-card .ek-sub{font-size:12px;color:var(--mut);display:flex;justify-content:space-between;align-items:center;margin-top:2px}
.ek-border-blue{border-top:3.5px solid #2E5FA8}
.ek-border-green{border-top:3.5px solid #0a8b4b}
.ek-border-orange{border-top:3.5px solid #f07c1f}
.ek-border-purple{border-top:3.5px solid #7b1fa2}
.ek-border-teal{border-top:3.5px solid #00897b}
.ek-border-red{border-top:3.5px solid #c0392b}

/* Modal styles */
.shg-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;place-items:center;padding:16px;backdrop-filter:blur(3px)}
.shg-modal.open{display:grid}
.shg-modal-box{background:var(--card);border:1px solid var(--line);border-radius:18px;max-width:540px;width:100%;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.3);position:relative}
.shg-modal-box h3{margin:0 0 14px;font-size:18px;font-weight:900;display:flex;align-items:center;gap:10px}
.shg-close{position:absolute;top:18px;right:18px;font-size:20px;font-weight:700;border:0;background:transparent;cursor:pointer;color:var(--mut)}
.f-row{margin-bottom:14px}
.f-row label{display:block;font-size:12.5px;font-weight:800;color:var(--ink);margin-bottom:5px}
.f-row input,.f-row select,.f-row textarea{width:100%;padding:10px 12px;border:1.5px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font:inherit;font-size:14px;outline:none}
.f-row input:focus,.f-row select:focus,.f-row textarea:focus{border-color:var(--blue)}
</style>

<?php if ($dashFlash): ?>
  <div class="flash <?= $dashFlash[0] === 'ok' ? 'ok' : 'bad' ?>" style="margin-bottom:18px;padding:12px 18px;border-radius:12px;font-weight:700;font-size:14px;background:<?= $dashFlash[0] === 'ok' ? '#e8f5e9' : '#ffebee' ?>;color:<?= $dashFlash[0] === 'ok' ? '#2e7d32' : '#c62828' ?>;border:1px solid <?= $dashFlash[0] === 'ok' ? '#a5d6a7' : '#ef9a9a' ?>">
    <?= Security::e($dashFlash[1]) ?>
  </div>
<?php endif; ?>

<!-- ====================================================================
     EXECUTIVE SUMMARY DASHBOARD (Step 2 - Category-Wise Control)
     ==================================================================== -->
<div class="exec-header">
  <div class="exec-title-row">
    <div class="exec-title">
      👔 Executive Operations Control Center
      <span>Real-Time Unified Hub</span>
    </div>
    <div style="font-size:12.5px;color:var(--mut);display:flex;align-items:center;gap:10px">
      <span>🏢 S Hari Global Pvt Ltd (Gujarat ⇄ Nepal)</span>
      <span class="status-dot confirmed"></span> <strong style="color:var(--ink)">Operational</strong>
    </div>
  </div>

  <!-- Quick Action Bar -->
  <div class="exec-quick-bar">
    <a class="exec-btn eb-orange" href="/admin/quick-ticket.php">⚡ + Instant Ticket (10s)</a>
    <a class="exec-btn eb-blue" href="/admin/team.php?tab=approvals">
      📝 + Approve Pending Agents/Staff
      <?php if ($pendingKycCount > 0): ?>
        <span style="background:#fff;color:#2E5FA8;padding:1px 6px;border-radius:10px;font-size:11px"><?= $pendingKycCount ?></span>
      <?php endif; ?>
    </a>
    <a class="exec-btn eb-teal" href="/admin/schedule.php">🚌 + Add Bus / Trip</a>
    <button type="button" class="exec-btn eb-green" onclick="document.getElementById('modalLiveBus').classList.add('open')">📍 + Update Live Bus Location</button>
    <button type="button" class="exec-btn eb-navy" onclick="document.getElementById('modalBroadcast').classList.add('open')">📢 + Broadcast Notice to Agents</button>
    <button type="button" class="exec-btn eb-ghost" onclick="document.getElementById('modalPaxLookup').classList.add('open')">🔍 Passenger / PNR Lookup</button>
    <a class="exec-btn eb-ghost" href="/admin/manifest.php?export=csv&amp;date=<?= Security::e($today) ?>" target="_blank">📋 Export Today's Manifest</a>
  </div>

  <!-- High-Density Live KPIs Strip -->
  <div class="exec-kpi-grid">
    <!-- KPI 1: Today's Bookings -->
    <div class="ek-card ek-border-blue">
      <div class="ek-label">Today's Bookings</div>
      <div class="ek-val"><?= $stats['todayBookings'] ?></div>
      <div class="ek-sub">
        <span><?= $stats['totalPax'] ?> Passengers</span>
        <strong><?= $stats['seatsToday'] ?> Seats</strong>
      </div>
    </div>

    <!-- KPI 2: Seat Occupancy -->
    <div class="ek-card ek-border-orange">
      <div class="ek-label">Seat Occupancy</div>
      <div class="ek-val" style="color:<?= $occPct > 80 ? '#e74c3c' : ($occPct > 50 ? '#f07c1f' : '#27ae60') ?>">
        <?= $occPct ?>%
      </div>
      <div class="ek-sub">
        <span><?= $seatsOccToday ?> / <?= $seatsTotalToday ?> Berths</span>
        <span style="color:#27ae60"><?= $seatsAvailToday ?> Free</span>
      </div>
      <div class="occ-bar" style="height:6px;margin:4px 0 0">
        <div class="occ-fill <?= $occPct > 80 ? 'occ-red' : ($occPct > 50 ? 'occ-yellow' : 'occ-green') ?>" style="width:<?= $occPct ?>%"></div>
      </div>
    </div>

    <!-- KPI 3: Cash vs Online Collection -->
    <div class="ek-card ek-border-green">
      <div class="ek-label">Collection (Cash vs Online)</div>
      <div class="ek-val" style="font-size:20px"><?= Security::e(inr($stats['revenueToday'])) ?></div>
      <div class="ek-sub">
        <span title="Counter/Agent Cash">💵 Cash: <strong><?= Security::e(inr($collCashToday)) ?></strong></span>
        <span title="UPI / eSewa Online">💳 UPI/eSewa: <strong><?= Security::e(inr($collOnlineToday)) ?></strong></span>
      </div>
    </div>

    <!-- KPI 4: Active Buses on Route -->
    <div class="ek-card ek-border-teal">
      <div class="ek-label">Active Buses on Route</div>
      <div class="ek-val"><?= $tripKpi['onRoute'] ?> <small style="font-size:14px;color:var(--mut);font-weight:600">/ <?= $tripKpi['total'] ?> today</small></div>
      <div class="ek-sub">
        <span>Fleet: <?= $activeBuses ?> buses</span>
        <span><?= $tripKpi['departed'] ?> Departed</span>
      </div>
    </div>

    <!-- KPI 5: Pending Form / KYC Approvals -->
    <div class="ek-card ek-border-purple">
      <div class="ek-label">Pending KYC / Forms</div>
      <div class="ek-val" style="color:<?= $pendingKycCount > 0 ? '#f07c1f' : 'var(--ink)' ?>"><?= $pendingKycCount ?></div>
      <div class="ek-sub">
        <span>Agent &amp; Staff queue</span>
        <a href="/admin/team.php?tab=approvals" style="font-weight:800;color:var(--blue)">Review →</a>
      </div>
    </div>

    <!-- KPI 6: Top Performing Agents -->
    <div class="ek-card ek-border-red">
      <div class="ek-label">Top Agent Today</div>
      <?php if (!empty($topAgents[0])): $ta0 = $topAgents[0]; ?>
        <div class="ek-val" style="font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
          🥇 <?= Security::e((string)($ta0['full_name'] ?: $ta0['username'])) ?>
        </div>
        <div class="ek-sub">
          <span><?= (int)$ta0['confirmed'] ?> confirmed</span>
          <strong><?= Security::e(inr((float)$ta0['revenue'])) ?></strong>
        </div>
      <?php else: ?>
        <div class="ek-val" style="font-size:16px;color:var(--mut)">No sales yet</div>
        <div class="ek-sub"><span>Monthly top rank</span><a href="/admin/agent-ranking.php" style="color:var(--blue)">Ranks →</a></div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Category Switcher Tabs -->
  <div class="cat-switcher" id="catSwitcher">
    <button type="button" class="cat-tab active" data-category="all" onclick="switchCategory('all')">
      🌟 All Executive Overview
    </button>
    <button type="button" class="cat-tab" data-category="org" onclick="switchCategory('org')">
      🏢 1. Organization &amp; Agent Hub
      <span class="badge"><?= $activeAgents ?> Agents</span>
    </button>
    <button type="button" class="cat-tab" data-category="ticketing" onclick="switchCategory('ticketing')">
      🎫 2. Ticketing &amp; Bus Control
      <span class="badge"><?= $stats['todayBookings'] ?> Today</span>
    </button>
    <button type="button" class="cat-tab" data-category="map" onclick="switchCategory('map')">
      🗺️ 3. Global Map (SHARI NAV) Control
      <span class="badge"><?= $tripKpi['onRoute'] ?> Live</span>
    </button>
  </div>
</div>

<?php if ($cc !== null): ?>
<!-- ============ COMMAND CENTER (29 Oct 2026, management upgrade 2.4 / 4.3 / 4.4) ============
     Read-only (includes/commandcenter.php). Every tile links to the page
     that acts on it. The counter board, alerts and approvals refresh every
     60 s from admin/api/command-center.php while the page is visible. -->
<style>
.cc{margin:0 0 22px}
.cc-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}
.cc-tile{display:block;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px;color:var(--ink);text-decoration:none;border-top:4px solid var(--blue);transition:transform .15s,box-shadow .15s;min-height:48px}
.cc-tile:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(18,38,78,.10)}
.cc-tile .k{font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:800;color:var(--mut)}
.cc-tile .v{font-size:22px;font-weight:900;margin-top:2px;font-variant-numeric:tabular-nums}
.cc-tile .s{font-size:12px;color:var(--mut);margin-top:3px;line-height:1.4}
.cc-tile.t-rev{border-top-color:#0a8b4b}.cc-tile.t-cash{border-top-color:var(--orange)}.cc-tile.t-appr{border-top-color:var(--blue)}.cc-tile.t-alert{border-top-color:#c0392b}
.cc-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}
.cc-list{list-style:none;margin:0;padding:0}
.cc-list li a{display:flex;align-items:center;gap:10px;padding:11px 14px;border-bottom:1px solid var(--line);color:var(--ink);text-decoration:none;min-height:48px;font-size:13.5px}
.cc-list li:last-child a{border-bottom:0}
.cc-list li a:hover{background:var(--hover)}
.cc-list .lv{width:8px;height:8px;border-radius:50%;flex:none}
.cc-list .lv.red{background:#c0392b}.cc-list .lv.amber{background:#e67e22}.cc-list .lv.blue{background:var(--blue)}
.cc-list .n{margin-left:auto;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap}
.cc-list .n small{font-weight:500;color:var(--mut);margin-left:6px}
.cc-list .zero{opacity:.55}
.cc-empty{padding:16px;color:var(--mut);font-size:13px}
.cc-tbl{width:100%;border-collapse:collapse;font-size:13px}
.cc-tbl th{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut);text-align:left;padding:8px 10px;border-bottom:1px solid var(--line)}
.cc-tbl td{padding:9px 10px;border-bottom:1px solid var(--line);font-variant-numeric:tabular-nums}
.cc-tbl td.num,.cc-tbl th.num{text-align:right}
.cc-tbl tr.desk td{background:var(--head);font-weight:800;font-size:12px}
.cc-st{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;font-weight:800;white-space:nowrap}
.cc-st.open{background:#d7f4e3;color:#0a6b3b}.cc-st.closed{background:#e1ecfb;color:#1f4f96}.cc-st.closed_diff{background:#fde2e1;color:#a12a22}.cc-st.none{background:var(--head);color:var(--mut)}
.cc-occ{display:flex;align-items:center;gap:10px;padding:9px 14px;border-bottom:1px solid var(--line);font-size:13px}
.cc-occ:last-child{border-bottom:0}
.cc-occ .bar{flex:1;height:8px;border-radius:99px;background:var(--line);overflow:hidden;min-width:60px}
.cc-occ .bar span{display:block;height:100%}
.cc-occ .red{background:#c0392b}.cc-occ .amber{background:#e6a116}.cc-occ .green{background:#0a8b4b}
.cc-occ .lbl{flex:0 1 46%;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cc-occ .pct{width:86px;text-align:right;font-weight:800;white-space:nowrap}
@media(max-width:900px){.cc-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}.cc-grid{grid-template-columns:1fr}}
@media(max-width:560px){.cc-tbl .hide-sm{display:none}.cc-tile .v{font-size:19px}}
</style>
<?php
$ccRev   = $cc['rev'];
$ccCash  = $cc['cash'];
$ccApprN = array_sum(array_column($cc['approvals'], 'n'));
$ccRed   = count(array_filter($cc['alerts'], static fn(array $a): bool => $a['level'] === 'red'));
$ccDiff  = $ccRev['last_month_same'] > 0 ? (int) round(($ccRev['mtd'] - $ccRev['last_month_same']) * 100 / $ccRev['last_month_same']) : null;
?>
<section class="cc" data-cat-section="all" aria-label="Command center">
  <div class="cc-tiles">
    <a class="cc-tile t-rev" href="/admin/analytics.php">
      <div class="k">Revenue · आम्दानी</div>
      <div class="v"><?= Security::e(inr($ccRev['today'])) ?> <small style="font-size:12px;color:var(--mut);font-weight:600">today</small></div>
      <div class="s">MTD <b><?= Security::e(inr($ccRev['mtd'])) ?></b><?= $ccDiff !== null ? ' (' . ($ccDiff >= 0 ? '▲' : '▼') . abs($ccDiff) . '% vs last month)' : '' ?><br>
        Projected <b><?= Security::e(inr($ccRev['projected'])) ?></b> · 7-day avg <?= Security::e(inr($ccRev['avg7'])) ?>/day</div>
    </a>
    <a class="cc-tile t-cash" href="/admin/reconcile.php">
      <div class="k">Agent cash due · एजेन्टसँग नगद</div>
      <div class="v"><?= Security::e(inr($ccCash['agents_total'])) ?></div>
      <div class="s"><?= (int) $ccCash['agents_n'] ?> agent<?= (int) $ccCash['agents_n'] === 1 ? '' : 's' ?> holding<?= $ccCash['top_name'] !== '' ? ' · most: ' . Security::e($ccCash['top_name']) . ' ' . Security::e(inr($ccCash['top_amount'])) : '' ?>
        <?= $ccCash['staff_total'] > 0 ? '<br>+ ' . Security::e(inr($ccCash['staff_total'])) . ' with office / counter staff' : '' ?></div>
    </a>
    <a class="cc-tile t-appr" href="#ccApprovals">
      <div class="k">Waiting approvals · स्वीकृति</div>
      <div class="v" data-cc="apprN"><?= (int) $ccApprN ?></div>
      <div class="s">payments, payouts, settlements, refunds, applications</div>
    </a>
    <a class="cc-tile t-alert" href="#ccAlerts">
      <div class="k">Alerts · चेतावनी</div>
      <div class="v" data-cc="alertN" style="color:<?= $ccRed > 0 ? '#c0392b' : 'var(--ink)' ?>"><?= count($cc['alerts']) ?></div>
      <div class="s" data-cc="alertSub"><?= $ccRed > 0 ? $ccRed . ' need action today' : (count($cc['alerts']) > 0 ? 'to look at' : 'all clear ✓') ?></div>
    </a>
  </div>

  <div class="cc-grid">
    <div class="dash-panel" id="ccAlerts" style="margin-bottom:16px">
      <div class="dp-head">⚠️ Alert feed <span class="muted" style="margin-left:auto;font-size:11.5px;font-weight:500" data-cc="at">live</span></div>
      <ul class="cc-list" data-cc="alerts">
        <?php if ($cc['alerts'] === []): ?><li class="cc-empty">Nothing needs attention right now. ✓</li><?php endif; ?>
        <?php foreach ($cc['alerts'] as $al): ?>
          <li><a href="<?= Security::e($al['href']) ?>"><span class="lv <?= Security::e($al['level']) ?>"></span><span><?= $al['icon'] ?> <?= Security::e($al['text']) ?></span><span class="n">→</span></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="dash-panel" id="ccApprovals" style="margin-bottom:16px">
      <div class="dp-head">✅ Waiting for a decision</div>
      <ul class="cc-list" data-cc="approvals">
        <?php foreach ($cc['approvals'] as $ap): ?>
          <li><a href="<?= Security::e($ap['href']) ?>" class="<?= (int) $ap['n'] === 0 ? 'zero' : '' ?>"><span><?= Security::e($ap['label']) ?></span>
            <span class="n"><?= (int) $ap['n'] ?><?= $ap['amount'] > 0 ? '<small>' . Security::e(inr((float) $ap['amount'])) . '</small>' : '' ?></span></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="dash-panel" id="ccCounters" data-cat-section="ticketing" style="margin-bottom:16px">
    <div class="dp-head">🏪 Counter status · काउन्टर <span class="muted" style="margin-left:auto;font-size:11.5px;font-weight:500">today · refreshes every minute</span></div>
    <div style="overflow-x:auto">
      <table class="cc-tbl">
        <thead><tr><th>Operator</th><th>Shift</th><th class="num">Tickets</th><th class="num">Cash</th><th class="num hide-sm">UPI / bank</th><th class="num hide-sm">Drawer now</th></tr></thead>
        <tbody data-cc="counters"></tbody>
      </table>
    </div>
  </div>

  <div class="cc-grid">
    <div class="dash-panel" data-cat-section="org" style="margin-bottom:16px">
      <div class="dp-head">🥇 Top sellers today <a href="/admin/agent-ranking.php" style="margin-left:auto;font-size:12px;font-weight:600">Leaderboard →</a></div>
      <ul class="cc-list">
        <?php if ($cc['top'] === []): ?><li class="cc-empty">No confirmed sale yet today.</li><?php endif; ?>
        <?php foreach ($cc['top'] as $i => $ts): ?>
          <li><a href="<?= $ts['role'] === 'agent' ? '/admin/agent-360.php?agent=' . (int) $ts['id'] : '/admin/bookings.php?' . Security::e(http_build_query(['from' => $today, 'to' => $today])) ?>">
            <span style="font-size:18px;width:24px;text-align:center"><?= ['🥇', '🥈', '🥉'][$i] ?? ($i + 1) ?></span>
            <span><b><?= Security::e($ts['name']) ?></b> <span class="muted" style="font-size:11.5px"><?= Security::e(ucfirst($ts['role'])) ?></span></span>
            <span class="n"><?= (int) $ts['tickets'] ?> tkt<small><?= Security::e(inr($ts['revenue'])) ?></small></span></a></li>
        <?php endforeach; ?>
      </ul>
    </div>
    <div class="dash-panel" data-cat-section="ticketing map" style="margin-bottom:16px">
      <div class="dp-head">🚌 Occupancy by bus · today</div>
      <div>
        <?php if ($cc['occ'] === []): ?><div class="cc-empty">No trip today.</div><?php endif; ?>
        <?php foreach ($cc['occ'] as $oc): ?>
          <a class="cc-occ" href="/admin/trip-dashboard.php?date=<?= Security::e($today) ?>" style="color:var(--ink);text-decoration:none">
            <span class="lbl" title="<?= Security::e($oc['label']) ?>"><?= Security::e($oc['label']) ?><?= $oc['bus'] !== '' ? ' <span class="muted mono" style="font-size:11px">' . Security::e($oc['bus']) . '</span>' : '' ?></span>
            <span class="bar"><span class="<?= $oc['band'] ?>" style="width:<?= min(100, $oc['pct']) ?>%"></span></span>
            <span class="pct"><?= (int) $oc['pct'] ?>% <small class="muted"><?= (int) $oc['sold'] ?>/<?= (int) $oc['total'] ?></small></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>
<script>
(function () {
  var first = <?= json_encode(['counters' => $cc['counters'], 'alerts' => $cc['alerts'], 'approvals' => $cc['approvals']], JSON_UNESCAPED_UNICODE) ?>;
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function inr(n) { return '₹' + Math.round(Number(n) || 0).toLocaleString('en-IN'); }
  function $(k) { return document.querySelector('[data-cc="' + k + '"]'); }
  var stLabel = { open: 'Open', closed: 'Closed ✓', closed_diff: 'Closed ≠', none: 'No shift' };
  function counters(rows) {
    var tb = $('counters'); if (!tb) return;
    if (!rows || !rows.length) { tb.innerHTML = '<tr><td colspan="6" class="cc-empty">No counter logins yet — add one in Staff (role: Counter) and assign its desk.</td></tr>'; return; }
    var html = '', desk = null;
    rows.forEach(function (r) {
      var d = r.desk || '';
      if (d !== desk) { desk = d; html += '<tr class="desk"><td colspan="6">' + (d ? '📍 ' + esc(r.desk_name || d) + ' <span class="muted mono">(' + esc(d) + ')</span>' : '📍 No desk assigned') + '</td></tr>'; }
      var href = r.shift_id ? (r.state === 'open' ? '/admin/shift.php?shift=' + r.shift_id : '/admin/shift.php?slip=' + r.shift_id) : '/admin/shift.php';
      html += '<tr><td><a href="' + href + '"><b>' + esc(r.name) + '</b></a></td>'
        + '<td><span class="cc-st ' + esc(r.state) + '">' + esc(stLabel[r.state] || r.state) + '</span>' + (r.since ? ' <span class="muted" style="font-size:11.5px">' + esc(r.since) + '</span>' : '') + '</td>'
        + '<td class="num">' + (r.tickets | 0) + '</td><td class="num">' + inr(r.cash) + '</td>'
        + '<td class="num hide-sm">' + inr(r.upi) + '</td><td class="num hide-sm">' + (r.drawer == null ? '<span class="muted">—</span>' : inr(r.drawer)) + '</td></tr>';
    });
    tb.innerHTML = html;
  }
  function alerts(list) {
    var ul = $('alerts'); if (!ul) return;
    ul.innerHTML = (!list || !list.length) ? '<li class="cc-empty">Nothing needs attention right now. ✓</li>'
      : list.map(function (a) { return '<li><a href="' + esc(a.href) + '"><span class="lv ' + esc(a.level) + '"></span><span>' + esc(a.icon) + ' ' + esc(a.text) + '</span><span class="n">→</span></a></li>'; }).join('');
    var red = (list || []).filter(function (a) { return a.level === 'red'; }).length;
    if ($('alertN')) { $('alertN').textContent = (list || []).length; $('alertN').style.color = red ? '#c0392b' : ''; }
    if ($('alertSub')) { $('alertSub').textContent = red ? red + ' need action today' : ((list || []).length ? 'to look at' : 'all clear ✓'); }
  }
  function approvals(list) {
    var ul = $('approvals'); if (!ul || !list) return;
    ul.innerHTML = list.map(function (a) {
      return '<li><a href="' + esc(a.href) + '" class="' + ((a.n | 0) === 0 ? 'zero' : '') + '"><span>' + esc(a.label) + '</span><span class="n">' + (a.n | 0) + (a.amount > 0 ? '<small>' + inr(a.amount) + '</small>' : '') + '</span></a></li>';
    }).join('');
    if ($('apprN')) $('apprN').textContent = list.reduce(function (s, a) { return s + (a.n | 0); }, 0);
  }
  counters(first.counters);
  function poll() {
    if (document.hidden) return;
    fetch('/admin/api/command-center.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || !j.ok) return;
        counters(j.counters); alerts(j.alerts); approvals(j.approvals);
        if ($('at')) $('at').textContent = 'live · ' + j.at;
      })
      .catch(function () { /* keep the last render */ });
  }
  setInterval(poll, 60000);
})();
</script>
<?php endif; ?>

<!-- Modal 1: Quick Update Live Bus Location -->
<div class="shg-modal" id="modalLiveBus">
  <div class="shg-modal-box">
    <button type="button" class="shg-close" onclick="document.getElementById('modalLiveBus').classList.remove('open')">×</button>
    <h3>📍 Update Live Bus Location &amp; Status</h3>
    <form method="post" action="/admin/index.php">
      <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">
      <input type="hidden" name="action" value="quick_live_bus">
      
      <div class="f-row">
        <label>Select Today's Active Trip *</label>
        <select name="schedule_id" required>
          <?php foreach ($trips as $tr): ?>
            <option value="<?= $tr['id'] ?>">
              <?= Security::e($tr['departure_time']) ?> — <?= Security::e($tr['from_city']) ?> → <?= Security::e($tr['to_city']) ?> (<?= Security::e($tr['bus_number'] ?: 'Bus') ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="f-row">
        <label>Current Status</label>
        <select name="status_label">
          <option value="On Time">🟢 On Time</option>
          <option value="Delayed">🟡 Delayed</option>
          <option value="Reached Boarding Point">🔵 Reached Boarding Point</option>
          <option value="Crossed Border">🟠 Crossed Border (Rupaidiha / Nepalgunj)</option>
        </select>
      </div>

      <div class="f-row">
        <label>Current City / Waypoint / Landmark *</label>
        <input type="text" name="location_name" placeholder="e.g. Surat, Kamrej, Vadodara, Godhra, Rupaidiha Checkpoint" required>
      </div>

      <div class="f-row">
        <label>Delay in Minutes (0 if On Time)</label>
        <input type="number" name="delay_minutes" value="0" min="0" max="720">
      </div>

      <div class="f-row">
        <label>Operational Note for Desk &amp; Passengers</label>
        <input type="text" name="note" placeholder="e.g. Dinner stop at Godhra bypass hotel, resuming in 20m">
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
        <button type="button" class="btn ghost" onclick="document.getElementById('modalLiveBus').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn" style="background:var(--blue)">Update Location Now</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal 2: Quick Broadcast Notice to Agents -->
<div class="shg-modal" id="modalBroadcast">
  <div class="shg-modal-box">
    <button type="button" class="shg-close" onclick="document.getElementById('modalBroadcast').classList.remove('open')">×</button>
    <h3>📢 Broadcast Operational Notice to Agents</h3>
    <form method="post" action="/admin/index.php">
      <input type="hidden" name="csrf" value="<?= Security::csrfToken() ?>">
      <input type="hidden" name="action" value="quick_broadcast">
      
      <div class="f-row">
        <label>Notice / Policy / Operational Message *</label>
        <textarea name="broadcast_message" rows="4" placeholder="e.g. Attention all counters: festival rush bookings for Nepalgunj now open with direct private cabins. Ensure passengers have valid Aadhaar/Nagrikta ID." required></textarea>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:18px">
        <button type="button" class="btn ghost" onclick="document.getElementById('modalBroadcast').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn" style="background:var(--orange)">Dispatch to All Counters</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal 3: Quick Passenger & PNR Lookup -->
<div class="shg-modal" id="modalPaxLookup">
  <div class="shg-modal-box" style="max-width:620px">
    <button type="button" class="shg-close" onclick="document.getElementById('modalPaxLookup').classList.remove('open')">×</button>
    <h3>🔍 Quick Passenger &amp; PNR Lookup</h3>
    <div style="margin-bottom:14px">
      <input type="text" id="paxLookupInput" placeholder="Type Passenger Name, Phone Number, or PNR..." style="width:100%;padding:10px 14px;border:1.5px solid var(--line);border-radius:10px;font-size:14px" oninput="doPaxLookup(this.value)">
    </div>
    <div id="paxLookupResults" style="max-height:360px;overflow-y:auto;display:flex;flex-direction:column;gap:8px">
      <p class="muted" style="text-align:center;font-size:13px;margin:20px 0">Enter at least 2 characters to search active bookings.</p>
    </div>
  </div>
</div>

<script>
var paxLookupTimer = null;
function doPaxLookup(val) {
  clearTimeout(paxLookupTimer);
  var box = document.getElementById('paxLookupResults');
  var q = val.trim();
  if (q.length < 2) {
    box.innerHTML = '<p class="muted" style="text-align:center;font-size:13px;margin:20px 0">Enter at least 2 characters to search active bookings.</p>';
    return;
  }
  paxLookupTimer = setTimeout(function() {
    box.innerHTML = '<p class="muted" style="text-align:center;font-size:13px;margin:20px 0">Searching bookings...</p>';
    fetch('/admin/api/search.php?q=' + encodeURIComponent(q))
      .then(function(r){ return r.json(); })
      .then(function(res){
        var rows = (res.results || []).filter(function(x){ return x.type === 'booking'; });
        if (rows.length === 0) {
          box.innerHTML = '<p class="muted" style="text-align:center;font-size:13px;margin:20px 0">No bookings matched "' + q + '".</p>';
          return;
        }
        var html = '';
        rows.forEach(function(b){
          var pnr = b.primary || '';
          var pax = b.secondary || '';
          var badge = b.badge || 'Booking';
          var route = b.sub || '';
          html += '<div style="background:var(--hover);border:1px solid var(--line);border-radius:10px;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">'
               + '<div><b>' + pnr + '</b> — <span>' + pax + '</span><div style="font-size:11.5px;color:var(--mut);margin-top:2px">' + route + ' · <span class="pill" style="font-size:10px">' + badge + '</span></div></div>'
               + '<div style="display:flex;gap:6px">'
               + '<a class="btn ghost" style="font-size:11px;padding:4px 8px" href="/admin/booking-view.php?pnr=' + encodeURIComponent(pnr) + '">👁 View</a>'
               + '<a class="btn" style="background:#25d366;color:#fff;font-size:11px;padding:4px 8px" target="_blank" href="https://wa.me/?text=' + encodeURIComponent('S Hari Global ticket for PNR ' + pnr + ': https://www.shreehariglobal.in/ticket/png.php?pnr=' + pnr) + '">📲 WA</a>'
               + '</div>'
               + '</div>';
        });
        box.innerHTML = html;
      })
      .catch(function(){
        box.innerHTML = '<p class="muted" style="text-align:center;color:#b91c1c;font-size:13px">Search error occurred.</p>';
      });
  }, 250);
}
</script>

<script>
function switchCategory(cat) {
  var tabs = document.querySelectorAll('.cat-tab');
  tabs.forEach(function(t){
    if (t.getAttribute('data-category') === cat) t.classList.add('active');
    else t.classList.remove('active');
  });

  var sections = document.querySelectorAll('[data-cat-section]');
  sections.forEach(function(s){
    var sCat = s.getAttribute('data-cat-section');
    if (cat === 'all' || sCat === 'all' || sCat.indexOf(cat) !== -1) {
      s.style.display = '';
    } else {
      s.style.display = 'none';
    }
  });
}
</script>
<a class="qt-dash" data-cat-section="ticketing" href="/admin/quick-ticket.php">
  <span class="qt-dash-ico">⚡</span>
  <span class="qt-dash-txt"><b>🤖 QuickBot Ticket — 10-Second Booking <em>AI</em></b><small>One line — "Ram 9876543210 2 seats Mehsana kal" or just name + mobile → QuickBot fills route · date · pickup · seats · seat · fare from the passenger's history → Confirm → PNG + PDF ticket, WhatsApp.</small></span>
  <span class="qt-dash-cta">Open desk →</span>
</a>

<!-- Hero stat cards -->
<div class="dash-hero" data-cat-section="ticketing">
  <div class="hcard hc-orange">
    <span class="hicon">⏳</span>
    <div class="hk">Awaiting verification</div>
    <div class="hv"><?= $stats['pendingPay'] ?></div>
    <div class="hsub">payments to review</div>
    <?php if ($stats['pendingPay'] > 0): ?><a class="hlink" href="<?= $base ?>/admin/payments.php">Review now →</a><?php endif; ?>
  </div>
  <div class="hcard hc-blue">
    <span class="hicon">🎫</span>
    <div class="hk">Bookings today</div>
    <div class="hv"><?= $stats['todayBookings'] ?></div>
    <div class="hsub"><?= $stats['totalPax'] ?> passengers</div>
  </div>
  <div class="hcard hc-green">
    <span class="hicon">💰</span>
    <div class="hk">Revenue today</div>
    <div class="hv"><?= Security::e(inr($stats['revenueToday'])) ?></div>
    <div class="hsub"><?= $stats['seatsToday'] ?> seats confirmed</div>
  </div>
  <div class="hcard hc-navy">
    <span class="hicon">📈</span>
    <div class="hk">Revenue this month</div>
    <div class="hv"><?= Security::e(inr($stats['revenueMonth'])) ?></div>
    <div class="hsub"><?= $stats['confirmed'] ?> total confirmed</div>
  </div>
</div>

<!-- Phase 2: Bus Operations Control Room strip (derived, live) -->
<div class="dash-hero" data-cat-section="map">
  <div class="hcard hc-navy">
    <span class="hicon">🕐</span>
    <div class="hk">Today's trips</div>
    <div class="hv" data-live-kpi="todayTrips"><?= $tripKpi['total'] ?></div>
    <div class="hsub">scheduled today</div>
    <?php if ($tripKpi['total'] > 0): ?><a class="hlink" href="<?= $base ?>/admin/trips.php">Open board →</a><?php endif; ?>
  </div>
  <div class="hcard hc-orange">
    <span class="hicon">⏰</span>
    <div class="hk">Departing soon</div>
    <div class="hv" data-live-kpi="departingSoon"><?= $tripKpi['departingSoon'] ?></div>
    <div class="hsub">boarding or ≤3h out</div>
  </div>
  <div class="hcard hc-blue">
    <span class="hicon">🚦</span>
    <div class="hk">Departed today</div>
    <div class="hv" data-live-kpi="departed"><?= $tripKpi['departed'] ?></div>
    <div class="hsub">left the counter</div>
  </div>
  <div class="hcard hc-teal">
    <span class="hicon">🛣️</span>
    <div class="hk">On route</div>
    <div class="hv" data-live-kpi="onRoute"><?= $tripKpi['onRoute'] ?></div>
    <div class="hsub">buses running now</div>
  </div>
</div>

<!-- Fleet / agents / cancellations / commission -->
<div class="dash-hero" data-cat-section="org">
  <div class="hcard hc-teal">
    <span class="hicon">🚌</span>
    <div class="hk">Active buses</div>
    <div class="hv"><?= $activeBuses ?></div>
    <div class="hsub">in the fleet</div>
    <a class="hlink" href="<?= $base ?>/admin/trips.php">Manage fleet →</a>
  </div>
  <div class="hcard hc-blue">
    <span class="hicon">👥</span>
    <div class="hk">Active agents</div>
    <div class="hv"><?= $activeAgents ?></div>
    <div class="hsub">selling tickets</div>
    <a class="hlink" href="<?= $base ?>/admin/staff.php">View agents →</a>
  </div>
  <div class="hcard hc-red">
    <span class="hicon">✖️</span>
    <div class="hk">Cancelled today</div>
    <div class="hv"><?= $cancelledToday ?></div>
    <div class="hsub">bookings cancelled</div>
    <?php if ($cancelledToday > 0): ?><a class="hlink" href="<?= $base ?>/admin/bookings.php?status=cancelled">Review →</a><?php endif; ?>
  </div>
  <div class="hcard hc-green">
    <span class="hicon">🤝</span>
    <div class="hk">Commission this month</div>
    <div class="hv"><?= Security::e(inr($commissionMonth)) ?></div>
    <div class="hsub">accrued to agents</div>
    <a class="hlink" href="<?= $base ?>/admin/agent.php">Agent wallets →</a>
  </div>
</div>

<!-- Quick actions -->
<div class="quick-actions" data-cat-section="all">
  <a class="qa" href="<?= $base ?>/admin/payments.php"><span class="qa-icon">💳</span>Verify Payments<?php if ($stats['pendingPay'] > 0): ?><span style="background:var(--orange);color:#fff;font-size:11px;padding:2px 8px;border-radius:20px;font-weight:800"><?= $stats['pendingPay'] ?></span><?php endif; ?></a>
  <a class="qa" href="<?= $base ?>/admin/manifest.php"><span class="qa-icon">📋</span>Today's Manifest</a>
  <a class="qa" href="<?= $base ?>/admin/scan.php"><span class="qa-icon">📷</span>Scan Ticket</a>
  <a class="qa" href="<?= $base ?>/admin/bookings.php"><span class="qa-icon">🎫</span>All Bookings</a>
  <a class="qa" href="<?= $base ?>/admin/trips.php"><span class="qa-icon">🕐</span>Manage Trips</a>
</div>

<!-- Middle grid: Revenue chart + Channel donut -->
<div class="dash-grid" data-cat-section="ticketing">
  <div class="dash-panel">
    <div class="dp-head">📊 Revenue — Last 7 Days</div>
    <div class="dp-body">
      <div class="spark-wrap">
        <?php foreach ($spark as $s): $h = $sparkMax > 0 ? max(4, ($s['val'] / $sparkMax) * 100) : 4; ?>
        <div class="spark-bar" style="height:<?= $h ?>%">
          <div class="spark-tip"><?= Security::e(inr($s['val'])) ?></div>
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

  <div class="dash-panel">
    <div class="dp-head">🎯 Today by Channel</div>
    <div class="dp-body">
      <?php
      $pOnline  = round(($src['online']  / $srcTotal) * 100);
      $pAgent   = round(($src['agent']   / $srcTotal) * 100);
      $pOffline = 100 - $pOnline - $pAgent;
      $oA = 0; $oB = $pOnline * 3.6; $oC = ($pOnline + $pAgent) * 3.6;
      ?>
      <div class="donut-wrap">
        <svg class="donut-svg" viewBox="0 0 42 42">
          <circle cx="21" cy="21" r="15.9" fill="none" stroke="var(--hover)" stroke-width="6"/>
          <?php if ($srcTotal > 0 && $src['online'] + $src['agent'] + $src['offline'] > 0): ?>
          <circle cx="21" cy="21" r="15.9" fill="none" stroke="#27ae60" stroke-width="6"
                  stroke-dasharray="<?= $pOnline ?> <?= 100 - $pOnline ?>" stroke-dashoffset="25" stroke-linecap="round"/>
          <circle cx="21" cy="21" r="15.9" fill="none" stroke="#2E5FA8" stroke-width="6"
                  stroke-dasharray="<?= $pAgent ?> <?= 100 - $pAgent ?>" stroke-dashoffset="<?= 25 - $pOnline ?>" stroke-linecap="round"/>
          <circle cx="21" cy="21" r="15.9" fill="none" stroke="#e67e22" stroke-width="6"
                  stroke-dasharray="<?= $pOffline ?> <?= 100 - $pOffline ?>" stroke-dashoffset="<?= 25 - $pOnline - $pAgent ?>" stroke-linecap="round"/>
          <?php endif; ?>
          <text x="21" y="22.5" text-anchor="middle" font-size="7" font-weight="800" fill="var(--ink)"><?= $stats['todayBookings'] ?></text>
          <text x="21" y="26.5" text-anchor="middle" font-size="3" fill="var(--mut)">total</text>
        </svg>
        <div class="donut-legend">
          <div class="donut-item"><span class="donut-dot" style="background:#27ae60"></span>Online: <strong><?= $src['online'] ?></strong> <span class="muted">(<?= $pOnline ?>%)</span></div>
          <div class="donut-item"><span class="donut-dot" style="background:#2E5FA8"></span>Agent: <strong><?= $src['agent'] ?></strong> <span class="muted">(<?= $pAgent ?>%)</span></div>
          <div class="donut-item"><span class="donut-dot" style="background:#e67e22"></span>Counter: <strong><?= $src['offline'] ?></strong> <span class="muted">(<?= $pOffline ?>%)</span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Today's occupancy bar (overall) -->
<div class="dash-panel" data-cat-section="ticketing" style="margin-bottom:24px">
  <div class="dp-head">💺 Today's Seat Occupancy
    <span style="margin-left:auto;font-size:13px;font-weight:600;color:<?= $occPct > 80 ? '#e74c3c' : ($occPct > 50 ? '#f39c12' : '#27ae60') ?>"><?= $occPct ?>% filled</span>
  </div>
  <div class="dp-body">
    <div style="display:flex;justify-content:space-between;margin-bottom:4px;gap:10px;flex-wrap:wrap">
      <span style="font-size:14px;font-weight:700"><?= $seatsOccToday ?> booked<?php if ($seatsHeldToday > 0): ?><span style="font-weight:600;color:#B85A00"> · <?= $seatsHeldToday ?> held</span><?php endif; ?></span>
      <span style="font-size:14px;color:var(--mut)"><?= $seatsAvailToday ?> available / <?= $seatsTotalToday ?> total</span>
    </div>
    <div class="occ-bar" style="height:24px;border-radius:12px">
      <div class="occ-fill <?= $occPct > 80 ? 'occ-red' : ($occPct > 50 ? 'occ-yellow' : 'occ-green') ?>" style="width:<?= $occPct ?>%"></div>
    </div>
  </div>
</div>

<!-- Top-performing agents this month (Point 9) -->
<div class="dash-panel" data-cat-section="org" style="margin-bottom:24px">
  <div class="dp-head">🏆 Top Agents · <?= Security::e(date('F')) ?>
    <a href="<?= $base ?>/admin/agent-ranking.php?by=confirmed" style="margin-left:auto;font-size:12px;font-weight:600">Full leaderboard →</a>
  </div>
  <div class="dp-body">
    <?php if ($topAgents === []): ?>
      <div class="muted" style="padding:8px 2px;font-size:13px">No agent sales yet this month.</div>
    <?php else: $medals = ['🥇', '🥈', '🥉']; foreach ($topAgents as $i => $ta):
      $code = AgentWallet::agentCodeLabel((int) $ta['id']); ?>
      <div style="display:flex;align-items:center;gap:12px;padding:8px 2px;border-bottom:1px solid var(--line)">
        <span style="font-size:18px;width:26px;text-align:center"><?= $medals[$i] ?? ($i + 1) ?></span>
        <div style="flex:1;min-width:0">
          <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= Security::e((string) ($ta['full_name'] ?: $ta['username'])) ?>
            <?php if ($code !== ''): ?><span class="mono muted" style="font-size:11px;font-weight:500"> · <?= Security::e($code) ?></span><?php endif; ?>
          </div>
        </div>
        <div style="text-align:right">
          <div style="font-weight:800"><?= (int) $ta['confirmed'] ?> <span class="muted" style="font-weight:500;font-size:12px">ticket<?= (int) $ta['confirmed'] === 1 ? '' : 's' ?></span></div>
          <div class="muted" style="font-size:11px"><?= Security::e(inr((float) $ta['revenue'])) ?></div>
        </div>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- Phase 2: Live Bus Status — the "who is where, right now" panel.
     Each row carries a data-trip-id so the 15-second poll can find it
     and update the state pill + minute count without a full page reload.
     The [data-live-*] hooks are the contract between admin/api/live-status.php
     and the client script at the bottom of this page. -->
<div class="dash-panel" data-cat-section="map" style="margin-bottom:24px">
  <div class="dp-head"><?= $isTodayView ? '🚌 Live Bus Status' : '🚌 Bus Status · ' . Security::e(formatDate($today)) ?>
    <?php if ($isTodayView): ?>
    <span class="live-fresh" id="liveFresh" title="Refreshes every 15 seconds">Live</span>
    <?php else: ?>
    <span style="margin-left:auto;font-size:11px;font-weight:700;color:var(--mut);background:var(--hover);padding:3px 10px;border-radius:20px">Static snapshot</span>
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
      <div class="live-time"><?= Security::e(substr((string) ($t['dep_time'] ?? ''), 0, 5)) ?></div>
      <div class="live-route">
        <?= Security::e(($t['from_city'] ?? '') . ' → ' . ($t['to_city'] ?? '')) ?>
        <small><?= Security::e((string) ($t['code'] ?? '')) ?></small>
      </div>
      <div class="live-bus">
        <?php if ($busN !== ''): ?>
          <span class="lb-num"><?= Security::e($busN) ?></span>
          <?php if ($busName !== ''): ?><small><?= Security::e($busName) ?></small><?php endif; ?>
        <?php else: ?>
          <span class="ld-empty" style="font-style:italic;color:var(--mut)">No bus assigned</span>
        <?php endif; ?>
      </div>
      <div class="live-driver">
        <?php if ($drvN !== ''): ?>
          👤 <?= Security::e($drvN) ?>
          <?php if ($drvP !== ''): ?><small><?= Security::e($drvP) ?></small><?php endif; ?>
        <?php else: ?>
          <span class="ld-empty">No driver assigned</span>
        <?php endif; ?>
      </div>
      <div class="live-state">
        <span class="state-pill" data-live-pill style="background:<?= Security::e($st['color']) ?>"><?= Security::e($st['label']) ?></span>
        <span class="state-detail" data-live-detail><?= Security::e($st['detail']) ?></span>
        <div class="live-seats" data-live-seats><strong><?= $tSold ?></strong>/<?= $tTotal ?> seats · <?= $tTotal > 0 ? (int) round(($tSold/$tTotal)*100) : 0 ?>%</div>
        <a href="/admin/challan.php?sid=<?= (int) $t['id'] ?>" target="_blank" style="font-size:11px;font-weight:700" title="Seat-wise challan picture of this bus">🖼️ Challan</a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <p class="muted" style="text-align:center;padding:16px 0;margin:0">No trips scheduled today.</p>
    <?php endif; ?>
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
<div class="dash-panel" data-cat-section="ticketing">
  <div class="dp-head">🕐 Recent Bookings</div>
  <div style="overflow-x:auto">
    <table>
      <thead><tr><th>PNR</th><th>Route</th><th>Phone</th><th>Amount</th><th>Source</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
      <?php if ($recent === []): ?>
        <tr><td colspan="7" class="muted" style="padding:22px;text-align:center">No bookings yet.</td></tr>
      <?php else: foreach ($recent as $b): ?>
        <tr>
          <td class="mono"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($b['pnr']) ?>"><?= Security::e($b['pnr']) ?></a></td>
          <td><?= Security::e(($b['from_city'] ?? '—') . ' → ' . ($b['to_city'] ?? '—')) ?></td>
          <td class="mono"><?= Security::e(maskPhone((string) $b['contact_phone'])) ?></td>
          <td style="font-weight:600"><?= Security::e(inr((float) $b['total_amount'])) ?></td>
          <td><span class="src-badge <?= Security::e((string) ($b['source'] ?? 'web')) ?>"><?= Security::e(ucfirst((string) ($b['source'] ?? 'web'))) ?></span></td>
          <td><span class="status-dot <?= Security::e((string) $b['status']) ?>"></span><?= Security::e(ucfirst((string) $b['status'])) ?></td>
          <td class="muted"><?= Security::e(timeAgo((string) $b['created_at'])) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php admin_footer(); ?>
