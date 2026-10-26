<?php
/**
 * admin/index.php — enhanced dashboard.
 *
 * 15 Oct 2026 (owner: "category-wise dashboard, most urgent first"): one
 * page in five sections, in the order the day runs —
 *   1 Today's operations   live bus status (passengers + fill), today's
 *                          bookings, proofs to verify, cash to collect
 *   2 Tickets & passengers find a ticket, open cancel / refund requests,
 *                          the last 20 bookings with Edit / Date
 *   3 Financial            today's collection (cash vs online), still to
 *                          collect, refunds due, agent-wise settlements
 *   4 Agents               today's sellers + the month's top, commission,
 *                          payouts asked for or overdue
 *   5 Bus & route          fleet, fill per route, sales channels
 * The month tiles, the 7-day chart and the channel donut sit in "More
 * figures" (folded on a phone). Every tile and link opens only for a role
 * the page it points at admits ($may) — nobody is handed a refused page.
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
require_once INCLUDE_PATH . '/agentsettlement.php';   // agent-wise settlements (15 Oct 2026)

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

/* 15 Oct 2026 (owner: "one tap to the list — and never a refused page"):
   the gate each linked page checks itself (its admin_boot / requireAdmin),
   plus the view-only account's page list. A tile whose page refuses this
   role is drawn without a link. */
$pageGate = [
    'bookings.php'      => ['bookings.view'],  'booking-view.php' => ['bookings.view'],
    'reschedule.php'    => ['bookings.view'],  'manifest.php'     => ['bookings.view'],
    'chalan.php'        => ['bookings.view'],  'quick-ticket.php' => ['bookings.view'],
    'smartbook.php'     => ['bookings.view'],
    'wa-routing.php'    => ['bookings.view'],  'trips.php'        => ['schedules.view'],
    'seatmap.php'       => ['schedules.view'], 'buses.php'        => ['schedules.view'],
    'scan.php'          => ['tickets.scan'],   'payments.php'     => ['payments.view'],
    'refunds.php'       => ['refunds.view'],   'support-inbox.php' => ['support.view'],
    'accounting.php'    => ['payments.view', 'reports.view'],
    'agents.php'        => ['customers.view', 'commissions.view'],
    'settlements.php'   => ['commissions.view'],
    'agent-ranking.php' => ['dashboard.view'],
    'team.php' => ['staff.manage'], 'gps-center.php' => ['buses.view'],
    'ai-copilot.php' => ['bookings.view'],
];
$may = static function (string $page) use ($pageGate): bool {
    if ($page === 'team.php' && !in_array((string)(Auth::admin()['role']??''), ['superadmin','manager'], true)) { return false; }
    if (!isset($pageGate[$page])) {
        return false;
    }
    if ((string) (Auth::admin()['role'] ?? '') === 'viewer' && !in_array($page, Auth::VIEWER_PAGES, true)) {
        return false;   // a view-only account is sent home from any other page
    }
    foreach ($pageGate[$page] as $perm) {
        if (!Auth::can($perm)) {
            return false;
        }
    }
    return true;
};

require_once INCLUDE_PATH . '/moneysummary.php';
/* Oct 2026 redesign: the money tiles read MoneySummary, which uses the exact
   definitions of admin/accounting.php (collected excludes cash still to be
   collected; NPR converted at the owner's npr_per_inr). */
$moneyDay   = MoneySummary::window($today, $today);
$moneyMonth = MoneySummary::window($monthStart, addDaysISO($monthEnd, -1));
// Company-wide money (tiles + revenue chart) is shown to the accounts roles
// only — the same reports.view gate as Accounting and Payroll.
$seeMoney   = Auth::can('reports.view');

/* 15 Oct 2026: "Bookings today" and its passenger line count the SAME set —
   bookings PLACED on the day, the list the tile opens (bookings.php from/to
   = booking date). The passenger line used to count bookings CONFIRMED on
   the day. A round trip's passenger is counted once (its outbound row). */
$todayBk = Database::fetch(
    "SELECT COUNT(*) AS n, COALESCE(SUM(status = 'confirmed'), 0) AS c
       FROM bookings WHERE created_at >= :d0 AND created_at < :d1",
    ['d0' => $today, 'd1' => $todayEnd]
) ?? [];
$stats = [
    'todayBookings'  => (int) ($todayBk['n'] ?? 0),
    'todayConfirmed' => (int) ($todayBk['c'] ?? 0),
    'totalPax'       => (int) Database::scalar(
        "SELECT COUNT(*) FROM booking_passengers bp
           JOIN bookings b ON b.id = bp.booking_id
           LEFT JOIN booking_legs bl ON bl.id = bp.leg_id
          WHERE b.created_at >= :d0 AND b.created_at < :d1
            AND (bp.leg_id IS NULL OR bl.leg_type = 'outbound')",
        ['d0' => $today, 'd1' => $todayEnd], 0
    ),
    'seatsToday'     => (int) Database::scalar("SELECT COUNT(*) FROM booking_seats bs JOIN bookings b ON b.id=bs.booking_id WHERE b.status='confirmed' AND b.confirmed_at>=:d0 AND b.confirmed_at<:d1", ['d0' => $today, 'd1' => $todayEnd]),
];
/* "To verify" = Verify Payments' default list (a UTR or a screenshot is in),
   the same number AI Watch shows and the list the tile opens (15 Oct 2026:
   it counted every pending booking, unpaid holds included). */
$proofs = AiWatch::proofsWaiting();

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
// Net commission accrued this month (credits minus voids on the commission
// account) — MoneySummary runs that exact agent_ledger query for the month.
$commissionMonth = (float) $moneyMonth['commission'];

/* Top-performing agents (Point 9) — same attribution as
   admin/agent-ranking.php (bookings.sold_by_admin_id, confirmed sales).
   15 Oct 2026: run for the day too, so the desk sees who is selling TODAY
   (top 5) above the month's podium (top 3). Guarded — an empty roster
   shows nothing. */
$sellers = static function (string $d0, string $d1, int $limit): array {
    try {
        return Database::fetchAll(
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
              LIMIT " . max(1, min(20, $limit)),
            ['m0' => $d0, 'm1' => $d1]
        );
    } catch (Throwable $e) {
        return [];
    }
};
$topAgents = $sellers($monthStart, $monthEnd, 3);
$topToday  = $sellers($today, $todayEnd, 5);

/* Agent-wise settlements and payouts (15 Oct 2026) — commissions.view, the
   gate of Settlements itself. AgentSettlement::pending() is the list
   Settlements → "Waiting to be settled" shows, longest-waiting first. */
$seeAgentMoney = Auth::can('commissions.view');
$settlePending = $seeAgentMoney ? AgentSettlement::pending() : [];
$settleTop     = array_slice($settlePending, 0, 5);
$commissionDue = 0.0;
$owedAgents    = 0;
foreach ($settlePending as $sp) {
    if ($sp['commission'] > 0.009) {
        $commissionDue += (float) $sp['commission'];
        $owedAgents++;
    }
}
$payouts = $seeAgentMoney ? AiWatch::payoutCounts($settlePending) : null;
if ($seeAgentMoney) {
    AiWatch::hintPending($settlePending);   // AI Watch reuses it - one ledger pass per page
}

/* Customers asking to cancel / for their money back, and refunds due —
   counted only; the office settles every one by hand (owner rule). */
$cancelReq  = AiWatch::cancelRequestCounts();
$refundsDue = null;
if (Auth::can('refunds.view')) {
    try {
        $refundsDue = Database::fetch("SELECT COUNT(*) AS n, COALESCE(SUM(refund_amount), 0) AS amt FROM bookings WHERE refund_status = 'pending'") ?? ['n' => 0, 'amt' => 0];
    } catch (Throwable $e) {
        $refundsDue = null;
    }
}

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
   5 Sep 2026), gaps filled with 0 so the chart keeps every day. Accounts
   roles only (it is company money). */
$spark = [];
$sparkMax = 1;
if ($seeMoney) {
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
}

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
   search / public timetable hide them. `pax` = people on board by the
   manifest's rule, the same subquery as admin/api/live-status.php. */
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
              WHERE bs.schedule_id=s.id AND bs.released_at IS NULL) AS sold,
            (SELECT COUNT(*) FROM booking_passengers bp
               JOIN booking_legs blp ON blp.booking_id = bp.booking_id AND blp.schedule_id = s.id
                                    AND (bp.leg_id IS NULL OR bp.leg_id = blp.id)
               JOIN bookings bpb ON bpb.id = bp.booking_id AND bpb.status IN ('confirmed','pending','completed')) AS pax
       FROM schedules s
       JOIN routes r ON r.id=s.route_id
       LEFT JOIN buses   bu ON bu.id = s.bus_id
       LEFT JOIN drivers d  ON d.id  = s.driver_id
      WHERE s.travel_date=:t AND r.is_active=1
      ORDER BY COALESCE(s.dep_time_override, r.dep_time) ASC",
    ['t' => $today]
);
/* 15 Oct 2026: `sold` counts booking_seats ROWS — a private cabin is one row
   over two berths, and EX extra seats ride on top of the berths. The 15 s
   poll (admin/api/live-status.php) restates it in physical berths; a ?date=
   snapshot never polls, so its per-trip sold / % and the SOLD OUT pill
   stayed wrong. Restated here the same way, before TripStatus reads it. */
$_beds = Seats::occupiedBedsFor(array_map(static fn(array $r): int => (int) $r['id'], $trips));
foreach ($trips as &$_r) {
    $_r['sold']         = $_beds[(int) $_r['id']] ?? (int) ($_r['sold'] ?? 0);
    $_r['seats_booked'] = (int) $_r['sold'];
}
unset($_r);
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

/* Bus & route (15 Oct 2026): the day's departures folded per route — trips,
   berths sold of the coach total, people on board — and how many distinct
   coaches run today (the fleet count is what is marked active, not what
   is on the road). */
$routeFill = [];
$busesToday = [];
foreach ($trips as $t) {
    $k = (string) ($t['code'] ?? '') !== '' ? (string) $t['code'] : ($t['from_city'] . ' → ' . $t['to_city']);
    $routeFill[$k] ??= ['code' => (string) ($t['code'] ?? ''), 'from' => (string) $t['from_city'], 'to' => (string) $t['to_city'],
                        'trips' => 0, 'sold' => 0, 'total' => 0, 'pax' => 0];
    $routeFill[$k]['trips']++;
    $routeFill[$k]['sold']  += (int) $t['sold'];
    $routeFill[$k]['total'] += (int) $t['total_seats'];
    $routeFill[$k]['pax']   += (int) ($t['pax'] ?? 0);
    $bn = trim((string) ($t['bus_number'] ?? ''));
    if ($bn !== '') {
        $busesToday[$bn] = true;
    }
}
$busesToday = count($busesToday);

/* Last 20 bookings (15 Oct 2026, was 8) with the lead passenger and the
   travel date — the same passenger subquery as admin/bookings.php. */
$recent = Database::fetchAll(
    "SELECT b.id, b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, b.source,
            bl.travel_date, r.from_city, r.to_city,
            (SELECT bp.full_name FROM booking_passengers bp WHERE bp.booking_id = b.id ORDER BY bp.is_primary DESC, bp.id LIMIT 1) AS passenger,
            (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id) AS pax_count
       FROM bookings b
       LEFT JOIN booking_legs bl ON bl.booking_id=b.id AND bl.leg_type='outbound'
       LEFT JOIN schedules s ON s.id=bl.schedule_id
       LEFT JOIN routes r ON r.id=s.route_id
      ORDER BY b.id DESC LIMIT 20"
);

/* --- "Action needed" alerts (compliance + refund backlog) ------------
   Compact, additive and fully guarded: each query is wrapped so a missing
   table/column can never 500 the dashboard, and each alert is gated on the
   same permission as its full page so only authorised office staff see it.
   Not date-dependent — these show regardless of the ?date snapshot. */
$alerts = [];   // each: ['icon','tone','href','text']   tone: bad | warn
// Gate on schedules.manage to match compliance.php's page gate (so the
// "Open" link always lands on a page the viewer can open). Crew licences
// ride along only under the inner drivers.view gate, as compliance.php does.
// `> '1970-01-01'` excludes both NULL and any stored zero-date.
if (Auth::can('schedules.manage')) {
    $docExpired = 0; $docSoon = 0;
    try {
        $r = Database::fetch(
            "SELECT SUM(x.d < CURDATE()) AS expired,
                    SUM(x.d >= CURDATE() AND x.d <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS soon
               FROM (SELECT insurance_exp AS d FROM buses WHERE is_active=1 AND insurance_exp > '1970-01-01'
                     UNION ALL SELECT permit_exp   FROM buses WHERE is_active=1 AND permit_exp   > '1970-01-01'
                     UNION ALL SELECT fitness_exp  FROM buses WHERE is_active=1 AND fitness_exp  > '1970-01-01') x"
        );
        $docExpired += (int) ($r['expired'] ?? 0); $docSoon += (int) ($r['soon'] ?? 0);
        if (Auth::can('drivers.view')) {
            $r = Database::fetch(
                "SELECT SUM(licence_exp < CURDATE()) AS expired,
                        SUM(licence_exp >= CURDATE() AND licence_exp <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)) AS soon
                   FROM drivers WHERE is_active=1 AND licence_exp > '1970-01-01'"
            );
            $docExpired += (int) ($r['expired'] ?? 0); $docSoon += (int) ($r['soon'] ?? 0);
        }
    } catch (Throwable $ex) { $docExpired = 0; $docSoon = 0; }
    if ($docExpired > 0) {
        $alerts[] = ['icon' => 'alert', 'tone' => 'bad', 'href' => $base . '/admin/compliance.php',
                     'text' => $docExpired . ' document' . ($docExpired === 1 ? '' : 's') . ' EXPIRED'];
    }
    if ($docSoon > 0) {
        $alerts[] = ['icon' => 'alert', 'tone' => 'warn', 'href' => $base . '/admin/compliance.php',
                     'text' => $docSoon . ' document' . ($docSoon === 1 ? '' : 's') . ' expiring within 30 days'];
    }
}
if (Auth::can('refunds.view')) {
    $oldRef = 0; $oldRefVal = 0.0;
    try {
        $r = Database::fetch(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(refund_amount),0) AS val
               FROM bookings
              WHERE refund_status='pending'
                AND COALESCE(cancelled_at, created_at) < CURDATE() - INTERVAL 30 DAY"
        );
        $oldRef = (int) ($r['cnt'] ?? 0); $oldRefVal = (float) ($r['val'] ?? 0.0);
    } catch (Throwable $ex) { $oldRef = 0; $oldRefVal = 0.0; }
    if ($oldRef > 0) {
        $alerts[] = ['icon' => 'refund', 'tone' => 'bad', 'href' => $base . '/admin/refund-aging.php',
                     'text' => $oldRef . ' refund' . ($oldRef === 1 ? '' : 's') . ' waiting over 30 days (' . inr($oldRefVal) . ')'];
    }
}

admin_header('CEO Command Center', 'index');

$e = static fn($v): string => Security::e((string) $v);
/* A tile opens as a link only when its page admits this role (15 Oct 2026). */
$tileOpen  = static fn(string $class, string $href): string => $href !== ''
    ? '<a class="' . $class . '" href="' . Security::e($href) . '">'
    : '<div class="' . $class . '">';
$tileClose = static fn(string $href): string => $href !== '' ? '</a>' : '</div>';
$kl = static fn(string $href, string $label): string => $href !== ''
    ? '<a class="kl" href="' . Security::e($href) . '" aria-label="' . Security::e($label) . '"></a>'
    : '';
$dayWord = $isTodayView ? 'today' : formatDate($today, 'j M');

$dateForm = '<form method="get" class="row" style="gap:8px;margin:0">'
    . '<label for="dashDate" class="text-sm fw7 muted" style="display:inline-flex;align-items:center;gap:6px"><svg class="a-ic sm"><use href="#a-calendar"/></svg>Snapshot</label>'
    . '<input id="dashDate" class="inp" type="date" name="date" value="' . $e($today) . '" onchange="this.form.submit()" style="min-height:38px;padding:6px 10px">'
    . (!$isTodayView ? '<a class="btn ghost sm" href="' . $base . '/admin/index.php">Back to today</a>' : '')
    . '</form>';
$actions = $dateForm
    // 8 Oct 2026 (D9-02): .btn.warn is the amber CAUTION face now; QuickBot is
    // the main sale, so it keeps the royal action face it has always shown.
    // 15 Oct 2026: both sale buttons only for a role that may sell (bookings.view).
    // 15 Oct 2026 (owner: "euta matra book"): one sale button - SmartBook.
    . ($may('smartbook.php') ? '<a class="btn" href="' . $base . '/admin/smartbook.php"><svg class="a-ic"><use href="#a-bolt"/></svg>Book ticket</a>' : '')
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
<nav class="command-categories" aria-label="Business management">
<?php foreach (AdminNavigation::groups(array_values(array_filter(admin_nav(), static fn(array $item): bool => $may($item['href'])))) as $category => $links): ?>
  <section class="command-category" data-category="<?= Security::e($category) ?>">
    <h2><?= Security::e(AdminNavigation::CATEGORIES[$category]) ?></h2>
    <?php $priority = array_flip(['bookings.php','team.php','accounting.php','smartbook.php','payments.php','gps-center.php']);
    usort($links, static fn(array $a, array $b): int => ($priority[$a['href']] ?? 99) <=> ($priority[$b['href']] ?? 99)); ?>
    <?php foreach (array_slice($links, 0, 3) as $link): ?>
      <a href="/admin/<?= Security::e($link['href']) ?>"><?= Security::e($link['label']) ?></a>
    <?php endforeach; ?>
    <?php if (count($links) > 3): ?><details><summary>More options</summary>
      <?php foreach (array_slice($links, 3) as $link): ?>
        <a href="/admin/<?= Security::e($link['href']) ?>"><?= Security::e($link['label']) ?></a>
      <?php endforeach; ?>
    </details><?php endif; ?>
  </section>
<?php endforeach; ?>
</nav>
<style>
/* 15 Oct 2026 — the five dashboard sections (phones first, no sideways scroll) */
.dash-sec{display:flex;align-items:center;flex-wrap:wrap;gap:6px 10px;margin:26px 0 12px;scroll-margin-top:72px}
.dash-sec__n{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:9px;background:var(--royal-tint,var(--blue-50));color:var(--royal,var(--blue));font-size:14px;font-weight:800;flex:0 0 auto}
.dash-sec small{font-size:12.5px;font-weight:600;color:var(--mut)}
.dash-sub{font:700 13px/18px var(--font-sans,inherit);color:var(--mut);text-transform:uppercase;letter-spacing:.06em;margin:14px 0 8px}
.dash-note{font-size:12.5px;color:var(--mut);margin:-6px 0 14px}
.dash-more{margin:0 0 22px;border:1px dashed var(--line);border-radius:14px;padding:0 14px}
.dash-more>summary{cursor:pointer;list-style:none;min-height:46px;display:flex;align-items:center;gap:8px;font-weight:700;color:var(--blue)}
.dash-more>summary::-webkit-details-marker{display:none}
.dash-more>summary::before{content:'▶';font-size:11px}
.dash-more[open]>summary::before{content:'▼'}
.dash-more[open]{padding-bottom:14px}
.dash-find{display:flex;gap:8px;margin:0 0 14px}
.dash-find .inp{flex:1 1 auto;min-width:0;min-height:46px}
.dash-find .btn{flex:0 0 auto;min-height:46px}
.dash-row{display:flex;align-items:center;gap:12px;padding:9px 2px;border-bottom:1px solid var(--line);color:inherit;text-decoration:none}
.dash-row:last-child{border-bottom:0}
.dash-row__main{flex:1 1 auto;min-width:0}
.dash-row__main b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.dash-row__end{flex:0 0 auto;text-align:right}
.dash-fill{height:8px;border-radius:6px;background:var(--hover);overflow:hidden;margin-top:6px}
.dash-fill>i{display:block;height:100%;border-radius:6px;background:var(--royal,var(--blue))}
.dash-fill>i.hi{background:var(--danger,#c0392b)}.dash-fill>i.mid{background:var(--warning,#f39c12)}
.tk-mini{display:flex;gap:6px;flex-wrap:wrap}
.tk-mini .btn{min-height:36px;padding:4px 10px;font-size:12.5px}
.live-pax{font-size:12px;color:var(--mut)}
@media(max-width:560px){.dash-kpis-money .kpi .kv{font-size:19px}}
.live-links{display:flex;gap:10px;flex-wrap:wrap}
</style>

<?php if (!$isTodayView): ?>
  <div class="flash warn"><svg class="a-ic"><use href="#a-info"/></svg>You are viewing <b><?= $e(formatDate($today)) ?></b>. Live bus status and the payment badge only update on today's view.</div>
<?php endif; ?>

<?php /* Action needed — fleet/crew document expiry + aged refund backlog (each gated per item above). */ ?>
<?php foreach ($alerts as $al): ?>
  <div class="flash <?= $al['tone'] ?>">
    <svg class="a-ic"><use href="#a-<?= $e($al['icon']) ?>"/></svg>
    <?= $e($al['text']) ?> —
    <a href="<?= $e($al['href']) ?>" style="color:inherit;text-decoration:underline;font-weight:700">Open</a>
  </div>
<?php endforeach; ?>

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

<?php /* ================================================================
   1 · TODAY'S OPERATIONS — the four trip tiles and the bus rows are
   live-updated by the 15 s poll (data-live-kpi / .live-trip).
   ================================================================ */ ?>
<h2 class="dash-sec" id="dash-ops"><span class="dash-sec__n">1</span>Today's operations<?php if (!$isTodayView): ?> <small>· <?= $e(formatDate($today)) ?></small><?php endif; ?></h2>
<?php
  $hBk      = $may('bookings.php') ? '/admin/bookings.php?' . http_build_query(['from' => $today, 'to' => $today]) : '';
  $hTrips   = $may('trips.php') ? '/admin/trips.php' : '';
  $hVerify  = $may('payments.php') ? '/admin/payments.php?tab=pending&sub=proof' : '';
  $hCod     = $may('payments.php') ? '/admin/payments.php?tab=cod' : '';
  // cancelled_from / cancelled_to: the cancel-date window of the register
  // (admin/_booking_filters.php); without it the list shows every cancellation.
  $hCancel  = $may('bookings.php') ? '/admin/bookings.php?' . http_build_query(['status' => 'cancelled', 'cancelled_from' => $today, 'cancelled_to' => $today]) : '';
?>
<div class="kpis">
  <div class="kpi tone-blue"><span class="ki"><svg class="a-ic"><use href="#a-ticket"/></svg></span><div class="kt"><div class="kk">Bookings <?= $e($dayWord) ?></div><div class="kv"><?= (int) $stats['todayBookings'] ?></div><div class="ks"><?= (int) $stats['totalPax'] ?> passenger<?= (int) $stats['totalPax'] === 1 ? '' : 's' ?> · <?= (int) $stats['todayConfirmed'] ?> confirmed</div></div><?= $kl($hBk, 'Bookings placed ' . $dayWord) ?></div>
  <div class="kpi tone-navy"><span class="ki"><svg class="a-ic"><use href="#a-bus"/></svg></span><div class="kt"><div class="kk">Today's trips</div><div class="kv" data-live-kpi="todayTrips"><?= (int) $tripKpi['total'] ?></div><div class="ks">scheduled today</div></div><?= $kl($hTrips, 'Trips') ?></div>
  <div class="kpi tone-orange"><span class="ki"><svg class="a-ic"><use href="#a-bell"/></svg></span><div class="kt"><div class="kk">Departing soon</div><div class="kv" data-live-kpi="departingSoon"><?= (int) $tripKpi['departingSoon'] ?></div><div class="ks">boarding or ≤3h out</div></div><?= $kl($hTrips, 'Departing soon') ?></div>
  <div class="kpi tone-teal"><span class="ki"><svg class="a-ic"><use href="#a-route"/></svg></span><div class="kt"><div class="kk">On route</div><div class="kv" data-live-kpi="onRoute"><?= (int) $tripKpi['onRoute'] ?></div><div class="ks"><span data-live-kpi="departed"><?= (int) $tripKpi['departed'] ?></span> departed today</div></div><?= $kl($hTrips, 'On route') ?></div>
  <div class="kpi tone-violet"><span class="ki"><svg class="a-ic"><use href="#a-card"/></svg></span><div class="kt"><div class="kk">To verify · now</div><div class="kv" data-live-kpi="toVerify"><?= (int) $proofs['n'] ?></div><div class="ks">UTR / screenshot in<?= $proofs['await'] > 0 ? ' · ' . (int) $proofs['await'] . ' not paid yet' : '' ?></div></div><?= $kl($hVerify, 'Verify payments') ?></div>
  <div class="kpi tone-orange"><span class="ki"><svg class="a-ic"><use href="#a-coins"/></svg></span><div class="kt"><div class="kk">Cash to collect</div><div class="kv"><?= (int) $moneyDay['codOutstandingCount'] ?></div><div class="ks">now · pay-at-boarding ticket<?= (int) $moneyDay['codOutstandingCount'] === 1 ? '' : 's' ?><?= $seeMoney ? ' · ' . $e(inr($moneyDay['codOutstanding'])) : '' ?></div></div><?= $kl($hCod, 'Cash to collect') ?></div>
  <div class="kpi tone-red"><span class="ki"><svg class="a-ic"><use href="#a-x-circle"/></svg></span><div class="kt"><div class="kk">Cancelled <?= $e($dayWord) ?></div><div class="kv"><?= (int) $cancelledToday ?></div><div class="ks">bookings cancelled</div></div><?= $cancelledToday > 0 ? $kl($hCancel, 'Cancelled') : '' ?></div>
</div>

<!-- Live bus status: every departure, people on board, berths sold, state -->
<div class="dash-panel" style="margin-bottom:18px">
  <div class="dp-head"><svg class="a-ic" style="color:var(--blue)"><use href="#a-bus"/></svg><?= $isTodayView ? 'Live bus status' : 'Bus status · ' . $e(formatDate($today)) ?>
    <?php if ($isTodayView): ?>
    <span class="live-fresh" id="liveFresh" title="Refreshes every 15 seconds">Live</span>
    <?php else: ?>
    <span class="pill st-muted nodot" style="margin-left:auto">Static snapshot</span>
    <?php endif; ?>
  </div>
  <div class="dp-body">
    <div class="text-sm fw7" style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
      <span>Seat occupancy <?= $e($dayWord) ?></span>
      <span style="color:<?= $occPct > 80 ? '#e74c3c' : ($occPct > 50 ? '#f39c12' : '#27ae60') ?>"><?= (int) $occPct ?>% filled</span>
    </div>
    <div class="occ-bar" style="height:14px;border-radius:8px">
      <div class="occ-fill <?= $occPct > 80 ? 'occ-red' : ($occPct > 50 ? 'occ-yellow' : 'occ-green') ?>" style="width:<?= (int) $occPct ?>%"></div>
    </div>
    <div class="text-xs muted" style="margin-bottom:6px"><?= (int) $seatsOccToday ?> berths booked<?php if ($seatsHeldToday > 0): ?> · <span style="color:#B85A00"><?= (int) $seatsHeldToday ?> held</span><?php endif; ?> · <?= (int) $seatsAvailToday ?> free of <?= (int) $seatsTotalToday ?></div>
    <div id="liveBusList">
      <?php if ($trips): ?>
      <?php foreach ($trips as $t):
        $tSold  = (int) $t['sold'];
        $tTotal = (int) $t['total_seats'];
        $tPax   = (int) ($t['pax'] ?? 0);
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
          <div class="live-pax" data-live-pax><?= $tPax ?> passenger<?= $tPax === 1 ? '' : 's' ?></div>
          <?php if ($may('manifest.php')): ?>
          <div class="live-links">
            <a href="/admin/manifest.php?<?= $e(http_build_query(['date' => (string) $t['travel_date'], 'sid' => (int) $t['id']])) ?>" class="text-xs fw7" title="Passenger manifest for this bus"><svg class="a-ic sm"><use href="#a-clipboard"/></svg> Passengers</a>
            <a href="/admin/chalan.php?sid=<?= (int) $t['id'] ?>" class="text-xs fw7" title="Bus chalan — preview, PDF / PNG, WhatsApp"><svg class="a-ic sm"><use href="#a-doc"/></svg> Chalan</a>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <?= admin_empty('No trips scheduled', $isTodayView ? 'Nothing departs today.' : 'Nothing departs on this date.', '🚌') ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Quick actions — each only for a role its page admits -->
<?php
  $qa = [];
  if ($may('payments.php')) { $qa[] = '<a class="qa" href="/admin/payments.php?tab=pending&amp;sub=proof"><span class="qa-icon"><svg class="a-ic"><use href="#a-card"/></svg></span>Verify payments' . ($proofs['n'] > 0 ? '<span class="qa-badge">' . (int) $proofs['n'] . '</span>' : '') . '</a>'; }
  if ($may('manifest.php')) { $qa[] = '<a class="qa" href="/admin/manifest.php?date=' . $e($today) . '"><span class="qa-icon"><svg class="a-ic"><use href="#a-clipboard"/></svg></span>' . ($isTodayView ? 'Today\'s manifest' : 'Manifest') . '</a>'; }
  if ($may('scan.php'))     { $qa[] = '<a class="qa" href="/admin/scan.php"><span class="qa-icon"><svg class="a-ic"><use href="#a-scan"/></svg></span>Scan Ticket</a>'; }
  if ($may('bookings.php')) { $qa[] = '<a class="qa" href="/admin/bookings.php"><span class="qa-icon"><svg class="a-ic"><use href="#a-ticket"/></svg></span>All tickets</a>'; }
  if ($may('seatmap.php'))  { $qa[] = '<a class="qa" href="/admin/seatmap.php"><span class="qa-icon"><svg class="a-ic"><use href="#a-seat"/></svg></span>Seat map</a>'; }
  if ($may('trips.php'))    { $qa[] = '<a class="qa" href="/admin/trips.php"><span class="qa-icon"><svg class="a-ic"><use href="#a-clock"/></svg></span>Trips board</a>'; }
?>
<?php if ($qa !== []): ?>
<div class="quick-actions"><?= implode('', $qa) ?></div>
<?php endif; ?>

<?php /* ================================================================
   2 · TICKETS & PASSENGERS
   ================================================================ */ ?>
<h2 class="dash-sec" id="dash-tickets"><span class="dash-sec__n">2</span>Tickets &amp; passengers</h2>
<?php if ($may('bookings.php')): ?>
<form class="dash-find" method="get" action="/admin/bookings.php" role="search">
  <input class="inp" type="search" name="q" maxlength="60" placeholder="PNR, ticket no., phone or passenger name" aria-label="Find a ticket">
  <button class="btn" type="submit"><svg class="a-ic"><use href="#a-search"/></svg>Find</button>
</form>
<?php endif; ?>

<?php
  /* Open cancel / refund requests (15 Oct 2026). Counted only — the office
     settles every cancel and refund by hand. */
  $reqTiles = [];
  if (Auth::can('bookings.view')) {
      $hWa = $cancelReq['wa'] === 1 && $cancelReq['waPnr'] !== '' ? '/admin/booking-view.php?pnr=' . rawurlencode($cancelReq['waPnr']) : '/admin/bookings.php?cancel_req=1';
      $reqTiles[] = admin_kpi('Cancel requests', (string) $cancelReq['wa'], 'asked on WhatsApp (code-verified)', 'x-circle', $cancelReq['wa'] > 0 ? 'orange' : 'teal', null, $cancelReq['wa'] > 0 ? $hWa : '');
  }
  if ($may('support-inbox.php')) {
      $reqTiles[] = admin_kpi('Refund / cancel at Help Desk', (string) $cancelReq['desk'], 'open or being worked on', 'msg', $cancelReq['desk'] > 0 ? 'orange' : 'teal', null, '/admin/support-inbox.php?cat=refund');
  }
?>
<?php if ($reqTiles !== []): ?>
<div class="kpis"><?= implode('', $reqTiles) ?></div>
<p class="dash-note">The office settles every cancel and refund by hand — these tiles only count; nothing here cancels a seat or pays money back.</p>
<?php endif; ?>

<!-- Recent bookings: the last 20, lead passenger, travel date, Edit / Date -->
<?php
  $canSeePax = Auth::can('bookings.view');   // the viewer role never sees a passenger's name
  $canEdit   = Auth::can('bookings.edit');
  $showActs  = $may('booking-view.php');
?>
<div class="dash-panel" style="margin-bottom:18px">
  <div class="dp-head"><svg class="a-ic" style="color:var(--blue)"><use href="#a-history"/></svg>Last 20 bookings
    <?php if ($may('bookings.php')): ?><a href="<?= $base ?>/admin/bookings.php" class="text-xs fw7" style="margin-left:auto">All tickets →</a><?php endif; ?>
  </div>
  <div class="tbl-scroll">
    <table>
      <thead><tr><th>PNR</th><th><?= $canSeePax ? 'Passenger' : 'Phone' ?></th><th>Route · travel</th><th class="num">Amount</th><th>Source</th><th>Status</th><th>When</th><?php if ($showActs): ?><th>Actions</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if ($recent === []): ?>
        <tr><td colspan="<?= 7 + ($showActs ? 1 : 0) ?>"><?= admin_empty('No bookings yet', 'The first ticket will appear here.', '🎫') ?></td></tr>
      <?php else: foreach ($recent as $b):
        $viewUrl = $base . '/admin/booking-view.php?pnr=' . urlencode((string) $b['pnr']);
        $open    = in_array((string) $b['status'], ['pending', 'confirmed'], true); ?>
        <tr>
          <td class="mono" style="white-space:nowrap"><?php if ($showActs): ?><a href="<?= $e($viewUrl) ?>"><?= $e($b['pnr']) ?></a><?php else: ?><?= $e($b['pnr']) ?><?php endif; ?></td>
          <td><?php if ($canSeePax): ?><?= $e((string) ($b['passenger'] ?: '—')) ?><?= (int) $b['pax_count'] > 1 ? ' <span class="muted">+' . ((int) $b['pax_count'] - 1) . '</span>' : '' ?><div class="muted mono text-xs"><?= $e(maskPhone((string) $b['contact_phone'])) ?></div><?php else: ?><span class="mono"><?= $e(maskPhone((string) $b['contact_phone'])) ?></span><?php endif; ?></td>
          <td><?= $e(($b['from_city'] ?? '—') . ' → ' . ($b['to_city'] ?? '—')) ?><?php if (!empty($b['travel_date'])): ?><div class="muted text-xs">travels <?= $e(formatDate((string) $b['travel_date'], 'D j M')) ?></div><?php endif; ?></td>
          <td class="num fw7 money"><?= $e(inr((float) $b['total_amount'])) ?></td>
          <td><span class="src-badge <?= $e((string) ($b['source'] ?? 'web')) ?>"><?= $e(ucfirst((string) ($b['source'] ?? 'web'))) ?></span></td>
          <td><?= admin_pill((string) $b['status']) ?></td>
          <td class="muted"><?= $e(timeAgo((string) $b['created_at'])) ?></td>
          <?php if ($showActs): ?>
          <td><div class="tk-mini">
            <?php if ($canEdit && $open): ?><a class="btn ghost" href="<?= $e($viewUrl) ?>#edit" title="Edit passenger, phone, stops, seat, payment">✏️ Edit</a><?php endif; ?>
            <?php /* same gate as the register's Date button (bookings.php) */ if ($open && $may('reschedule.php') && ($canEdit || Auth::can('schedules.edit') || Auth::isSuperadmin())): ?><a class="btn ghost" href="<?= $base ?>/admin/reschedule.php?pnr=<?= urlencode((string) $b['pnr']) ?>" title="Move this ticket to another date or departure (same route)">📅 Date</a><?php endif; ?>
            <?php if (!($canEdit && $open)): ?><a class="btn ghost" href="<?= $e($viewUrl) ?>" title="Open the ticket">👁 View</a><?php endif; ?>
          </div></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($may('smartbook.php')): ?>
<!-- 16 Oct 2026: the ONE booking screen for every desk (QuickBot + New Booking folded in) -->
<a class="promo" href="/admin/smartbook.php">
  <span class="promo-ico"><svg class="a-ic"><use href="#a-bolt"/></svg></span>
  <span class="promo-txt"><b>⚡ SmartBook — one booking screen <em>AI</em></b><small>Agent code first, then name + mobile (or one line: "Ram 9876543210 2 seats Mehsana kal"). Bus, date, pickup, berths and fare come from the engine; Cash = ticket now, Online = bank QR. Same screen for counter, agent and office.</small></span>
  <span class="promo-cta">Book →</span>
</a>
<?php endif; ?>

<?php /* ================================================================
   3 · FINANCIAL — the same definitions as Accounting, so the two agree.
   ================================================================ */ ?>
<?php
  $accDay   = $may('accounting.php') ? $base . '/admin/accounting.php?from=' . rawurlencode($today) . '&to=' . rawurlencode($today) : '';
  $accMonth = $may('accounting.php') ? $base . '/admin/accounting.php?from=' . rawurlencode($monthStart) . '&to=' . rawurlencode(addDaysISO($monthEnd, -1)) : '';
  $hRefunds = $may('refunds.php') ? '/admin/refunds.php' : '';
  $hSettle  = $may('settlements.php') ? '/admin/settlements.php?tab=pending' : '';
?>
<h2 class="dash-sec" id="dash-money"><span class="dash-sec__n">3</span>Financial <small>· <?= $e($dayWord) ?></small></h2>
<?php if ($seeMoney): ?>
<div class="sg sg-stats dash-money">
  <?= $tileOpen('sg-stat', $accDay) ?>
    <span class="sg-stat__label">Collected</span>
    <span class="sg-stat__value"><?= $e(inr($moneyDay['collected'])) ?></span>
    <span class="sg-stat__def"><b><?= $e(inr($moneyDay['collectedCash'])) ?> cash · <?= $e(inr($moneyDay['collectedOnline'])) ?> online</b> — money actually received: <?= (int) $moneyDay['collectedCount'] ?> confirmed booking<?= $moneyDay['collectedCount'] === 1 ? '' : 's' ?>, cash still to collect not counted</span>
  <?= $tileClose($accDay) ?>
  <?= $tileOpen('sg-stat', $accDay) ?>
    <span class="sg-stat__label">Confirmed fares</span>
    <span class="sg-stat__value"><?= $e(inr($moneyDay['confirmedFare'])) ?></span>
    <span class="sg-stat__def">Fare of the <?= (int) $moneyDay['confirmedCount'] ?> booking<?= $moneyDay['confirmedCount'] === 1 ? '' : 's' ?> confirmed this day (revenue, collected or not) · <?= (int) $stats['seatsToday'] ?> seats</span>
  <?= $tileClose($accDay) ?>
  <?= $tileOpen('sg-stat' . (($moneyDay['codOutstanding'] + $moneyDay['agentCash']) > 0.5 ? ' sg-stat--attention' : ''), $hCod) ?>
    <span class="sg-stat__label">Still to collect · now</span>
    <span class="sg-stat__value"><?= $e(inr($moneyDay['codOutstanding'] + $moneyDay['agentCash'])) ?></span>
    <span class="sg-stat__def"><?= $e(inr($moneyDay['codOutstanding'])) ?> cash on boarding not yet collected (<?= (int) $moneyDay['codOutstandingCount'] ?>) + <?= $e(inr($moneyDay['agentCash'])) ?> ticket cash sellers still hold (agents, counters, office)</span>
  <?= $tileClose($hCod) ?>
  <?php if ($refundsDue !== null): ?>
  <?= $tileOpen('sg-stat' . ((int) $refundsDue['n'] > 0 ? ' sg-stat--attention' : ''), $hRefunds) ?>
    <span class="sg-stat__label">Refunds due · now</span>
    <span class="sg-stat__value"><?= $e(inr((float) $refundsDue['amt'])) ?></span>
    <span class="sg-stat__def"><?= (int) $refundsDue['n'] ?> cancelled booking<?= (int) $refundsDue['n'] === 1 ? '' : 's' ?> waiting for money back — settled by hand on Refunds</span>
  <?= $tileClose($hRefunds) ?>
  <?php endif; ?>
</div>
<?php else: ?>
<?php if ($refundsDue !== null): ?>
<div class="kpis"><?= admin_kpi('Refunds due', (string) (int) $refundsDue['n'], 'cancelled bookings waiting for money back · ' . inr((float) $refundsDue['amt']), 'refund', (int) $refundsDue['n'] > 0 ? 'orange' : 'teal', null, $hRefunds) ?></div>
<?php endif; ?>
<p class="muted text-sm">Company money figures are shown to the accounts roles (manager, accountant, super-admin).</p>
<?php endif; ?>

<?php if ($seeAgentMoney): ?>
<!-- Agent-wise: who holds our cash / is owed commission (Settlements' list) -->
<div class="dash-panel" style="margin-bottom:18px">
  <div class="dp-head"><svg class="a-ic" style="color:var(--orange)"><use href="#a-wallet"/></svg>Waiting to be settled · top 5
    <?php if ($hSettle !== ''): ?><a href="<?= $e($hSettle) ?>" class="text-xs fw7" style="margin-left:auto">Settlements →</a><?php endif; ?>
  </div>
  <div class="dp-body">
    <?php if ($settleTop === []): ?>
      <?= admin_empty('Nobody is waiting', 'No seller holds ticket cash and no agent is owed commission.', '✅') ?>
    <?php else: foreach ($settleTop as $sp): ?>
      <?= $tileOpen('dash-row', $hSettle) ?>
        <?= admin_avatar($sp['name']) ?>
        <div class="dash-row__main">
          <b><?= $e($sp['name']) ?></b>
          <span class="muted text-xs"><?= $e($sp['code'] !== '' ? $sp['code'] : ucfirst($sp['role'])) ?><?= $sp['open'] !== null ? ' · settlement ' . $e((string) $sp['open']['status']) : '' ?></span>
        </div>
        <div class="dash-row__end text-sm">
          <?php if ($sp['cash'] > 0.009): ?><div>holds <b class="money"><?= $e(inr((float) $sp['cash'])) ?></b><?= $sp['cashDays'] > 0 ? ' <span class="muted text-xs">' . (int) $sp['cashDays'] . ' d</span>' : '' ?></div><?php endif; ?>
          <?php if ($sp['commission'] > 0.009): ?><div>owed <b class="money"><?= $e(inr((float) $sp['commission'])) ?></b><?= $sp['days'] > 0 ? ' <span class="muted text-xs">' . (int) $sp['days'] . ' d</span>' : '' ?></div><?php endif; ?>
        </div>
      <?= $tileClose($hSettle) ?>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($seeMoney): ?>
<details class="dash-more" data-dash-more>
  <summary>More figures — <?= $e(date('F Y', strtotime($monthStart))) ?> and the last 7 days</summary>
  <div class="dash-sub">Money · <?= $e(date('F Y', strtotime($monthStart))) ?></div>
  <div class="sg sg-stats dash-money">
    <?= $tileOpen('sg-stat', $accMonth) ?>
      <span class="sg-stat__label">Collected</span>
      <span class="sg-stat__value"><?= $e(inr($moneyMonth['collected'])) ?></span>
      <span class="sg-stat__def"><b><?= $e(inr($moneyMonth['collectedCash'])) ?> cash · <?= $e(inr($moneyMonth['collectedOnline'])) ?> online</b> — money received this month (same rule as above)</span>
    <?= $tileClose($accMonth) ?>
    <?= $tileOpen('sg-stat', $accMonth) ?>
      <span class="sg-stat__label">Confirmed fares</span>
      <span class="sg-stat__value"><?= $e(inr($moneyMonth['confirmedFare'])) ?></span>
      <span class="sg-stat__def"><?= (int) $moneyMonth['confirmedCount'] ?> bookings confirmed this month (revenue)</span>
    <?= $tileClose($accMonth) ?>
    <?php $hAgents = $may('agents.php') ? $base . '/admin/agents.php' : ''; ?>
    <?= $tileOpen('sg-stat', $hAgents) ?>
      <span class="sg-stat__label">Commission</span>
      <span class="sg-stat__value"><?= $e(inr($commissionMonth)) ?></span>
      <span class="sg-stat__def">Accrued to agents this month, net of reversals (agent ledger)</span>
    <?= $tileClose($hAgents) ?>
    <?= $tileOpen('sg-stat', $accMonth) ?>
      <span class="sg-stat__label">Profit</span>
      <?php if ($moneyMonth['expenseRows'] === 0): ?>
      <span class="sg-stat__value is-na">Not available</span>
      <span class="sg-stat__def">No expenses recorded this month. Add diesel, toll, crew and office costs in Accounting to see profit.</span>
      <?php else: ?>
      <span class="sg-stat__value is-na">See Accounting</span>
      <span class="sg-stat__def"><?= $e(inr($moneyMonth['expenses'])) ?> expenses recorded (<?= (int) $moneyMonth['expenseRows'] ?>). Accounting shows net after expenses &amp; salaries.</span>
      <?php endif; ?>
    <?= $tileClose($accMonth) ?>
  </div>
  <div class="dash-panel" style="margin-top:14px">
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
</details>
<?php endif; ?>

<?php /* ================================================================
   4 · AGENTS
   ================================================================ */ ?>
<h2 class="dash-sec" id="dash-agents"><span class="dash-sec__n">4</span>Agents</h2>
<?php
  $hAgentsReg = $may('agents.php') ? '/admin/agents.php' : '';
  $agentTiles = [admin_kpi('Active agents', (string) $activeAgents, 'agent logins switched on', 'users', 'violet', null, $hAgentsReg)];
  if ($seeMoney) {
      $agentTiles[] = admin_kpi('Commission · ' . date('M'), inr($commissionMonth), 'accrued this month, net of reversals', 'wallet', 'blue', null, $hAgentsReg);
  }
  if ($seeAgentMoney) {
      $agentTiles[] = admin_kpi('Commission owed · now', inr($commissionDue), $owedAgents . ' agent' . ($owedAgents === 1 ? '' : 's') . ' waiting to be paid', 'banknote', $owedAgents > 0 ? 'orange' : 'teal', null, $hSettle);
      if ($payouts !== null) {
          $agentTiles[] = admin_kpi('Payouts pending', (string) $payouts['requests'],
              ($payouts['requests'] > 0 ? inr($payouts['requestAmt']) . ' asked for · ' : 'no open request · ')
              . $payouts['late'] . ' owed over ' . $payouts['dueDays'] . ' days',
              'clock', ($payouts['requests'] + $payouts['late']) > 0 ? 'red' : 'green', null, $hSettle);
      }
  }
?>
<div class="kpis dash-kpis-money"><?= implode('', $agentTiles) ?></div>

<div class="dash-grid">
  <div class="dash-panel">
    <div class="dp-head"><svg class="a-ic" style="color:var(--orange)"><use href="#a-chart-up"/></svg>Selling <?= $e($dayWord) ?>
      <?php if ($may('agent-ranking.php')): ?><a href="<?= $base ?>/admin/agent-ranking.php?<?= $e(http_build_query($isTodayView ? ['range' => 'today', 'by' => 'confirmed'] : ['range' => 'custom', 'from' => $today, 'to' => $today, 'by' => 'confirmed'])) ?>" class="text-xs fw7" style="margin-left:auto">Leaderboard →</a><?php endif; ?>
    </div>
    <div class="dp-body">
      <?php if ($topToday === []): ?>
        <?= admin_empty('No agent sale yet', $isTodayView ? 'Nobody has confirmed a ticket today so far.' : 'No agent confirmed a ticket on this date.', '🕐') ?>
      <?php else: foreach ($topToday as $i => $ta):
        $code = AgentWallet::agentCodeLabel((int) $ta['id']);
        $nm = (string) ($ta['full_name'] ?: $ta['username']); ?>
        <div class="dash-row">
          <?= admin_avatar($nm) ?>
          <div class="dash-row__main"><b><?= (int) $i + 1 ?>. <?= $e($nm) ?></b><?php if ($code !== ''): ?><span class="mono muted text-xs"><?= $e($code) ?></span><?php endif; ?></div>
          <div class="dash-row__end"><b><?= (int) $ta['confirmed'] ?></b> <span class="muted text-xs">ticket<?= (int) $ta['confirmed'] === 1 ? '' : 's' ?></span><?php if ($seeMoney || $seeAgentMoney): ?><div class="muted text-xs money"><?= $e(inr((float) $ta['revenue'])) ?></div><?php endif; ?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="dash-panel">
    <div class="dp-head"><svg class="a-ic" style="color:var(--orange)"><use href="#a-trophy"/></svg>Top agents · <?= $e(date('F')) ?>
      <?php if ($may('agent-ranking.php')): ?><a href="<?= $base ?>/admin/agent-ranking.php?by=confirmed" class="text-xs fw7" style="margin-left:auto">Month →</a><?php endif; ?>
    </div>
    <div class="dp-body">
      <?php if ($topAgents === []): ?>
        <?= admin_empty('No agent sales yet', 'This month is still open.', '🏆') ?>
      <?php else: $medals = ['🥇', '🥈', '🥉']; foreach ($topAgents as $i => $ta):
        $code = AgentWallet::agentCodeLabel((int) $ta['id']);
        $nm = (string) ($ta['full_name'] ?: $ta['username']); ?>
        <div class="dash-row">
          <?= admin_avatar($nm) ?>
          <div class="dash-row__main"><b><?= $medals[$i] ?? ($i + 1) ?> <?= $e($nm) ?></b><?php if ($code !== ''): ?><span class="mono muted text-xs"><?= $e($code) ?></span><?php endif; ?></div>
          <div class="dash-row__end"><b><?= (int) $ta['confirmed'] ?></b> <span class="muted text-xs">ticket<?= (int) $ta['confirmed'] === 1 ? '' : 's' ?></span><?php if ($seeMoney || $seeAgentMoney): ?><div class="muted text-xs money"><?= $e(inr((float) $ta['revenue'])) ?></div><?php endif; ?></div>
        </div>
      <?php endforeach; endif; ?>
      <?php if ($hAgentsReg !== ''): ?><a class="btn soft sm block" style="margin-top:12px" href="<?= $base ?>/admin/agents.php"><svg class="a-ic"><use href="#a-users"/></svg>All agents &amp; wallets</a><?php endif; ?>
    </div>
  </div>
</div>

<?php /* ================================================================
   5 · BUS & ROUTE
   ================================================================ */ ?>
<h2 class="dash-sec" id="dash-bus"><span class="dash-sec__n">5</span>Bus &amp; route <small>· <?= $e($dayWord) ?></small></h2>
<?php $hBk2 = $may('bookings.php'); ?>
<div class="kpis">
  <?= admin_kpi('Buses running', (string) $busesToday, $activeBuses . ' bus' . ($activeBuses === 1 ? '' : 'es') . ' in the fleet (marked active)', 'bus', 'navy', null, $may('buses.php') ? '/admin/buses.php' : '') ?>
  <?= admin_kpi('Confirmed ' . $dayWord, (string) (int) array_sum(array_column($chan, 'confirmed')), $chanTotal . ' placed · ' . (int) array_sum(array_column($chan, 'cancelled')) . ' cancelled', 'ticket', 'green', null, $hBk2 ? '/admin/bookings.php?' . http_build_query(['status' => 'confirmed', 'from' => $today, 'to' => $today]) : '') ?>
  <?= admin_kpi('Online customers', (string) ($chan['ONLINE']['n'] + $chan['GOOGLE_ADS']['n']), $chan['GOOGLE_ADS']['n'] > 0 ? $chan['GOOGLE_ADS']['n'] . ' from Google Ads 🎉' : 'booked on the website themselves', 'globe', 'teal', null, $hBk2 ? $chanLink('online') : '') ?>
  <?= admin_kpi('Agent bookings', (string) $chan['AGENT']['n'], 'agent login or agent code', 'users', 'blue', null, $hBk2 ? $chanLink('agent') : '') ?>
  <?= admin_kpi('WhatsApp requests', (string) $chan['WHATSAPP']['n'], 'booked from a WhatsApp message', 'msg', 'violet', null, $hBk2 ? $chanLink('whatsapp') : '') ?>
</div>

<div class="dash-panel" style="margin-bottom:18px">
  <div class="dp-head"><svg class="a-ic" style="color:var(--blue)"><use href="#a-route"/></svg>Fill by route · <?= $e($dayWord) ?>
    <?php if ($may('trips.php')): ?><a href="/admin/trips.php" class="text-xs fw7" style="margin-left:auto">Trips board →</a><?php endif; ?>
  </div>
  <div class="dp-body">
    <?php if ($routeFill === []): ?>
      <?= admin_empty('No departures', $isTodayView ? 'Nothing departs today.' : 'Nothing departs on this date.', '🛣️') ?>
    <?php else: foreach ($routeFill as $rf):
      $rp = $rf['total'] > 0 ? (int) round($rf['sold'] * 100 / $rf['total']) : 0; ?>
      <div class="dash-row" style="display:block">
        <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap">
          <b><?= $e($rf['from'] . ' → ' . $rf['to']) ?> <?php if ($rf['code'] !== ''): ?><small class="mono muted"><?= $e($rf['code']) ?></small><?php endif; ?></b>
          <span class="text-sm"><b><?= (int) $rf['sold'] ?></b>/<?= (int) $rf['total'] ?> berths · <?= $rp ?>% · <?= (int) $rf['pax'] ?> passenger<?= (int) $rf['pax'] === 1 ? '' : 's' ?><?= $rf['trips'] > 1 ? ' · ' . (int) $rf['trips'] . ' buses' : '' ?></span>
        </div>
        <div class="dash-fill"><i class="<?= $rp > 80 ? 'hi' : ($rp > 50 ? 'mid' : '') ?>" style="width:<?= max(0, min(100, $rp)) ?>%"></i></div>
      </div>
    <?php endforeach; endif; ?>
    <div class="text-xs muted" style="margin-top:8px">Berths sold in physical berths (a private cabin counts its two berths; extra EX seats ride on top) — as of page load.</div>
  </div>
</div>

<details class="dash-more" data-dash-more>
  <summary>More figures — bookings by channel</summary>
  <div class="dash-panel">
    <div class="dp-head"><svg class="a-ic" style="color:var(--blue)"><use href="#a-layers"/></svg><?= $isTodayView ? 'Today' : $e(formatDate($today)) ?> by channel</div>
    <div class="dp-body">
      <div class="donut-wrap">
        <svg class="donut-svg" viewBox="0 0 42 42" role="img" aria-label="Bookings by channel">
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
          <?php foreach (BookingChannel::FILTERS as $__src => $__t): $__n = $chan[$__t]['n'];
                $__h = $hBk2 ? $chanLink($__src) : ''; ?>
          <?= $tileOpen('donut-item', $__h) ?><span class="donut-dot" style="background:<?= $e(BookingChannel::META[$__t][3]) ?>"></span><?= $e(BookingChannel::label($__t)) ?>: <strong><?= $__n ?></strong> <span class="muted">(<?= $chanTotal > 0 ? (int) round($__n / $chanTotal * 100) : 0 ?>%)</span><?= $tileClose($__h) ?>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</details>

<script>
/* 15 Oct 2026: "More figures" is open on a desk and folded on a phone, so
   the actionable sections come first on a small screen. */
(function () {
  if (!window.matchMedia || !window.matchMedia('(max-width:820px)').matches) {
    document.querySelectorAll('[data-dash-more]').forEach(function (d) { d.setAttribute('open', ''); });
  }
})();

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
          var pax    = row.querySelector('[data-live-pax]');
          if (pill)   { pill.textContent   = t.state_label; pill.style.background = t.state_color; }
          if (detail) { detail.textContent = t.detail || ''; }
          if (seats)  {
            var pct = t.total > 0 ? Math.round((t.sold / t.total) * 100) : 0;
            seats.innerHTML = '<strong>' + t.sold + '</strong>/' + t.total + ' seats · ' + pct + '%';
          }
          if (pax && typeof t.pax === 'number') { pax.textContent = t.pax + ' passenger' + (t.pax === 1 ? '' : 's'); }
        });
        if (freshTag) { freshTag.textContent = 'Live · ' + fmtFresh(); freshTag.title = 'Last updated ' + (j.generated_at || ''); }
      })
      .catch(function () { /* leave the last render intact */ });
  }
  poll();                       // first sync as soon as the page settles
  setInterval(poll, 15000);     // then every 15 s — matches Phase 2 spec
})();
</script>

<?php admin_footer(); ?>
