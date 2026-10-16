<?php
/**
 * admin/trip-dashboard.php — Bus Management / Trip Summary page.
 *
 * A combined overview for any route + date: summary cards (seats, revenue),
 * a compact visual seat grid, and the booking list for that departure.
 * Links through to the full seat map, passenger manifest, and bookings list.
 *
 * Does NOT modify _guard.php — uses 'trip-dashboard' as the active page
 * identifier; the nav entry is added separately.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('schedules.view');

$base  = '';   // root-relative
$flash = null;

/* ---- Per-date manual bus-assignment (POST) --------------------------
   Mirrors the trips.php assign_driver pattern: a single-select form in
   the "All Buses on selected date" table posts here to override the
   Fleet::rotation() slot for one schedule. bus_id=0 unassigns
   (schedules.bus_id → NULL, which lets the row fall back to
   routes.bus_id via the LEFT JOIN COALESCE below). */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            Auth::requireAdmin('schedules.edit');
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'assign_bus') {
                // Swapping the coach under a trip is fleet management, not
                // counter work — schedules.manage (manager / superadmin).
                // Counter agents keep schedules.edit for selling only.
                Auth::requireAdmin('schedules.manage');
                $sid    = (int) ($_POST['schedule_id'] ?? 0);
                $busId  = (int) ($_POST['bus_id'] ?? 0);
                if ($sid <= 0) { throw new RuntimeException('Missing schedule id.'); }

                $before = Database::fetch(
                    'SELECT bus_id, total_seats FROM schedules WHERE id = :id',
                    ['id' => $sid]
                );
                if ($before === null) { throw new RuntimeException('That trip no longer exists.'); }

                $wasSwap = ((int) ($before['bus_id'] ?? 0)) > 0;

                if ($busId > 0) {
                    // Guard the swap + capacity sync inside one transaction so
                    // schedules.bus_id and schedules.total_seats cannot drift
                    // apart if the second write fails.
                    $check = Seats::assertBusSwapSafe($sid, $busId);
                    Database::transaction(static function () use ($sid, $busId, $check): void {
                        Database::update('schedules', ['bus_id' => $busId], 'id = :id', ['id' => $sid]);
                        if ((int) $check['newTotalSeats'] > 0) {
                            Database::update(
                                'schedules',
                                ['total_seats' => (int) $check['newTotalSeats']],
                                'id = :id',
                                ['id' => $sid]
                            );
                        }
                    });

                    $eventName = $wasSwap ? 'trip.swap_bus' : 'trip.assign_bus';
                    Logger::audit(
                        $eventName,
                        'schedule',
                        (string) $sid,
                        [
                            'bus_id'      => $before['bus_id'] ?? null,
                            'total_seats' => $before['total_seats'] ?? null,
                        ],
                        [
                            'bus_id'       => $busId,
                            'total_seats'  => (int) $check['newTotalSeats'],
                            'bookedCount'  => (int) $check['bookedCount'],
                            'currentCoach' => (string) $check['currentCoach'],
                            'newCoach'     => (string) $check['newCoach'],
                        ],
                        'by admin #' . $admin['id']
                    );
                    $flash = ['ok', $wasSwap ? 'Bus swapped.' : 'Bus assigned.'];
                } else {
                    // Un-assign: clear bus_id; leave total_seats alone (the
                    // route/COALESCE fallback owns capacity in that case).
                    Database::update('schedules', ['bus_id' => null], 'id = :id', ['id' => $sid]);
                    Logger::audit(
                        'trip.assign_bus',
                        'schedule',
                        (string) $sid,
                        ['bus_id' => $before['bus_id'] ?? null],
                        ['bus_id' => null],
                        'by admin #' . $admin['id']
                    );
                    $flash = ['ok', 'Bus un-assigned.'];
                }
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Selection ------------------------------------------------------ */
$routes = Database::fetchAll(
    "SELECT id, route_code, from_city, to_city, coach_type, base_fare, is_active
       FROM routes ORDER BY is_active DESC, sort_order, id"
);

/* ?sid= (Bus Calendar, 5 Sep 2026): an extra bus on the same date by its own
   schedule id — route + date follow from the row; otherwise the daily bus. */
$sidReq = (int) ($_REQUEST['sid'] ?? 0);
$sidRow = $sidReq > 0 ? Database::fetch('SELECT id, route_id, travel_date FROM schedules WHERE id = :id', ['id' => $sidReq]) : null;
if ($sidRow === null) { $sidReq = 0; }
$routeId = $sidRow !== null ? (int) $sidRow['route_id'] : (int) ($_REQUEST['route'] ?? ($routes[0]['id'] ?? 0));
if ($sidRow !== null) { $_REQUEST['date'] = (string) $sidRow['travel_date']; }
$date    = Security::clean($_REQUEST['date'] ?? todayISO(), 10);
if (!Security::isValidDate($date)) {
    $date = todayISO();
}

$route = null;
foreach ($routes as $r) {
    if ((int) $r['id'] === $routeId) { $route = $r; break; }
}

/* Can-this-admin-edit gate — mirrors $canDrive in trips.php. Only
   editors get the bus-picker + the bus dropdown data. Everyone else
   sees the plain bus name (view-only). */
$canEdit     = Auth::canManageSchedules();   // bus swap = schedules.manage (role audit 3 Sep 2026)
$activeBuses = $canEdit
    ? Database::fetchAll("SELECT id, bus_name, bus_number FROM buses WHERE is_active = 1 ORDER BY bus_name")
    : [];
$csrf        = Security::e(Security::csrfToken());
$k           = CSRF_TOKEN_NAME;

/* ---- Load trip data when a route is selected ------------------------ */
$scheduleId  = 0;
$map         = [];
$counts      = ['open' => 0, 'booked' => 0, 'held' => 0, 'blocked' => 0, 'staff' => 0];
$revenue     = 0.0;
$pendingAmt  = 0.0;
$codDue      = 0.0;
$bookings    = [];
$agentCodes  = Settings::getArray('agent_codes', []);

if ($route !== null) {
    $schedule    = Seats::scheduleFor($routeId, $date, $sidReq > 0 ? $sidReq : null);
    $scheduleId  = (int) $schedule['id'];
    $coach       = Seats::effectiveCoach($schedule, $route);   // this departure's own coach
    $map         = Seats::adminSeatMap($scheduleId, $coach);

    foreach ($map as $s) {
        $counts[$s['status']] = ($counts[$s['status']] ?? 0) + 1;
    }

    /* Counter-agent scope (master-prompt §9). A logged-in role='agent' must
       only see the bookings THEY sold on this coach — never another agent's
       PNRs / passengers / SHG codes. Auth::bookingScopeAdminId() returns
       the agent's own admin_id, or null for a supervisor / admin who sees
       everything. The revenue / pending / COD totals shrink to the same
       scope so the summary cards stay honest. */
    $scopeAdminId = Auth::bookingScopeAdminId();
    $scopeWhere   = $scopeAdminId !== null ? ' AND b.sold_by_admin_id = :scope' : '';
    $scopeParams  = $scopeAdminId !== null ? ['scope' => $scopeAdminId] : [];

    /* Revenue / pending / COD due */
    try {
        $fin = Database::fetch(
            "SELECT
               SUM(CASE WHEN b.status='confirmed' THEN b.total_amount ELSE 0 END) AS revenue,
               SUM(CASE WHEN b.status='pending' THEN b.total_amount ELSE 0 END) AS pending_amount,
               SUM(CASE WHEN b.is_cod=1 AND p.status='cod_pending' THEN b.total_amount ELSE 0 END) AS cod_due
             FROM booking_seats bs
             JOIN bookings b ON b.id = bs.booking_id
             LEFT JOIN payments p ON p.booking_id = b.id
                  AND p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
             WHERE bs.schedule_id = :sid AND bs.released_at IS NULL" . $scopeWhere,
            ['sid' => $scheduleId] + $scopeParams
        );
        $revenue    = (float) ($fin['revenue'] ?? 0);
        $pendingAmt = (float) ($fin['pending_amount'] ?? 0);
        $codDue     = (float) ($fin['cod_due'] ?? 0);
    } catch (Throwable $e) {
        // Silently degrade
    }

    /* Booking list for this schedule */
    try {
        $bookings = Database::fetchAll(
            "SELECT DISTINCT b.pnr, b.status, b.total_amount, b.source, b.sold_by_admin_id,
                    b.contact_phone, b.is_cod, b.created_at, b.booking_mode,
                    GROUP_CONCAT(DISTINCT bs.seat_no ORDER BY bs.seat_no SEPARATOR ', ') AS seats,
                    (SELECT full_name FROM booking_passengers bp
                      WHERE bp.booking_id = b.id AND bp.is_primary = 1 LIMIT 1) AS passenger_name,
                    p.method AS pay_method, p.status AS pay_status
               FROM booking_seats bs
               JOIN bookings b ON b.id = bs.booking_id
               LEFT JOIN payments p ON p.booking_id = b.id
                    AND p.id = (SELECT MAX(p3.id) FROM payments p3 WHERE p3.booking_id = b.id)
              WHERE bs.schedule_id = :sid2 AND bs.released_at IS NULL" . $scopeWhere . "
              GROUP BY b.id
              ORDER BY b.created_at ASC",
            ['sid2' => $scheduleId] + $scopeParams
        );
    } catch (Throwable $e) {
        $bookings = [];
    }
}

$totalSeats = count($map);

/**
 * Format agent code from the agent_codes settings map.
 */
function td_agent_code(array $codes, ?int $adminId): string
{
    if ($adminId === null || !isset($codes[$adminId])) {
        return '';
    }
    return 'SHG-' . str_pad((string) ($codes[$adminId] ?? ''), 4, '0', STR_PAD_LEFT);
}

/** Source badge markup. */
function td_source_badge(string $source): string
{
    $map = [
        'web'     => ['Online',  'td-src-online'],
        'app'     => ['App',     'td-src-online'],
        'agent'   => ['Agent',   'td-src-agent'],
        'counter' => ['Counter', 'td-src-counter'],
        'admin'   => ['Admin',   'td-src-admin'],
    ];
    [$label, $cls] = $map[$source] ?? [ucfirst($source), 'td-src-online'];
    return '<span class="pill ' . $cls . '">' . Security::e($label) . '</span>';
}

admin_header('Date View', 'trip-dashboard');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}
?>
<style>
/* ---- Summary cards with colored left border ---- */
.td-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:12px;margin-bottom:22px}
.td-card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px;border-left:4px solid var(--line)}
.td-card .k{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.4px}
.td-card .v{font-size:24px;font-weight:800;margin-top:4px}
.td-card .v small{font-size:12px;color:var(--mut);font-weight:600}
.td-card.green{border-left-color:#0a6b3b}
.td-card.blue{border-left-color:#2E5FA8}
.td-card.amber{border-left-color:#c99200}
.td-card.gray{border-left-color:#888}
.td-card.orange{border-left-color:#F07C1F}
.td-card.money{border-left-color:#0a6b3b}
.td-card .v.green{color:#0a6b3b}.td-card .v.blue{color:#2E5FA8}.td-card .v.amber{color:#c99200}
.td-card .v.gray{color:#888}.td-card .v.orange{color:#F07C1F}
:root[data-theme="dark"] .td-card .v.green{color:#4ade80}
:root[data-theme="dark"] .td-card .v.blue{color:#93b5f5}
:root[data-theme="dark"] .td-card .v.amber{color:#fcd34d}
:root[data-theme="dark"] .td-card .v.orange{color:#fb923c}

/* ---- Mini seat grid ---- */
/* Decks stack top→bottom so lower/upper stay visually separated at a glance,
   matching how customer + admin/seatmap draw the same coach. Each deck is
   still an auto-fill mini-grid (thumbnail scale). */
.seat-grid{display:flex;flex-direction:column;gap:12px;padding:18px}
.seat-grid .sg-deck{display:flex;flex-direction:column;gap:4px}
.seat-grid .sg-deck-head{font-size:11px;font-weight:700;color:var(--mut);letter-spacing:.04em;text-transform:uppercase}
.seat-grid .sg-deck .floor-head{margin:0 0 6px;padding:6px 10px;font-size:12px}
.seat-grid .sg-cells{display:grid;grid-template-columns:repeat(auto-fill,minmax(32px,1fr));gap:4px}
/* One physical row per line: 4 left | aisle | 2 right (sleeper), as the coach sits. */
.seat-grid .sg-rows{display:flex;flex-direction:column;gap:4px}
.seat-grid .sg-row{display:flex;gap:4px;align-items:center}
.seat-grid .sg-aisle{flex:0 0 14px;text-align:center;font-size:10px;font-weight:800;color:var(--fl,var(--mut))}
.seat-cell{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:6px;font-size:10px;font-weight:700;font-family:ui-monospace,Menlo,Consolas,monospace;cursor:default;transition:transform .1s}
.seat-cell:hover{transform:scale(1.15)}
.seat-open{background:#d7f4e3;color:#0a6b3b}
.seat-booked{background:#c7dbf7;color:#1c3b72}
.seat-held{background:#fff4d1;color:#8a6d00}
.seat-blocked{background:#e7e7ea;color:#666}
.seat-staff{background:#fff176;color:#5c4d00}
:root[data-theme="dark"] .seat-open{background:#0f3624;color:#4ade80}
:root[data-theme="dark"] .seat-booked{background:#1a2e52;color:#93b5f5}
:root[data-theme="dark"] .seat-held{background:#3a3000;color:#fcd34d}
:root[data-theme="dark"] .seat-blocked{background:#2a2a2e;color:#999}
:root[data-theme="dark"] .seat-staff{background:#3a3400;color:#fff176}

/* ---- Source badges ---- */
.td-src-online{background:#e2ecfb;color:#1c3b72}
.td-src-agent{background:#fff4d1;color:#8a6d00}
.td-src-counter{background:#ffe6c7;color:#7a4a00}
.td-src-admin{background:#f0e6ff;color:#5a3fb0}
:root[data-theme="dark"] .td-src-online{background:#1c3b72;color:#b8d4fb}
:root[data-theme="dark"] .td-src-agent{background:#4a3d00;color:#ffe28a}
:root[data-theme="dark"] .td-src-counter{background:#4a3200;color:#ffcc8a}
:root[data-theme="dark"] .td-src-admin{background:#2e1f5e;color:#d4bfff}

/* ---- Booking table ---- */
.td-bookings td{font-size:13px}
.td-bookings .mono{font-size:12px}

/* ---- Quick links ---- */
.td-links{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;margin-bottom:22px}
.td-links a{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;background:var(--card);border:1px solid var(--line);border-radius:10px;font-weight:700;font-size:13px;color:var(--ink);transition:background .15s}
.td-links a:hover{background:var(--hover)}

/* ---- Occupancy bar ---- */
.occ-bar{height:10px;border-radius:5px;background:var(--line);overflow:hidden;margin-top:8px}
.occ-fill{height:100%;border-radius:5px;transition:width .3s}

/* ---- Responsive ---- */
@media(max-width:820px){
  .td-cards{grid-template-columns:repeat(auto-fit,minmax(120px,1fr))}
  .seat-grid{grid-template-columns:repeat(auto-fill,minmax(28px,1fr))}
  .seat-cell{width:28px;height:28px;font-size:9px}
}
/* ── Date overview multi-bus table ── */
.date-bus-table{width:100%;border-collapse:collapse;font-size:13px}
.date-bus-table th{font-size:11.5px;text-align:left;padding:8px 12px;border-bottom:2px solid var(--line);
                   color:var(--mut);text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.date-bus-table td{padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:middle}
.date-bus-table tr:hover td{background:var(--head)}
.floor-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 8px;border-radius:6px;
            font-size:11.5px;font-weight:700;white-space:nowrap}
.floor-pill.lower{background:#E0F7F4;color:#00695C}
.floor-pill.upper{background:#E3F2FD;color:#1565C0}
:root[data-theme="dark"] .floor-pill.lower{background:#00352E;color:#80CBC4}
:root[data-theme="dark"] .floor-pill.upper{background:#0D2844;color:#90CAF9}
.mini-occ{height:6px;border-radius:3px;background:var(--line);width:80px;overflow:hidden;display:inline-block;vertical-align:middle;margin-left:4px}
.mini-occ-fill{height:100%;border-radius:3px}
</style>

<?php
/* ── Date-wise overview: all buses on selected date ─────────────── */
/* dep_time / ORDER BY use the effective SCHEDULED departure — a
   per-schedule dep_time_override (delay-workflow reschedule) wins over
   the printed route timetable so this board matches the messaging cron
   and the dashboard Live Bus Status. delay_minutes stays separate and
   is not folded in here. is_blocked / cancel_reason / cancelled_by are
   selected so T5's admin pill has the columns it needs; blocked rows
   are STILL shown to admins (only customers hide them). */
$allDaySchedules = Database::fetchAll(
    "SELECT s.id AS schedule_id, s.total_seats, s.status, s.travel_date,
            s.is_blocked, s.cancel_reason, s.cancelled_by, s.cancelled_at,
            r.id AS route_id, r.route_code, r.from_city, r.to_city,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
            r.coach_type,
            COALESCE(b.bus_name,'—')   AS bus_name,
            COALESCE(b.bus_number,'')  AS bus_number,
            COALESCE(b.seat_model,'72-sleeper') AS seat_model,
            COALESCE(b.floors, 2)      AS floors,
            b.id AS bus_id
       FROM schedules s
       JOIN routes r ON r.id = s.route_id
       LEFT JOIN buses b ON b.id = COALESCE(s.bus_id, r.bus_id)
      WHERE s.travel_date = :d AND s.status <> 'cancelled'
      ORDER BY COALESCE(s.dep_time_override, r.dep_time) ASC",
    ['d' => $date]
);

/* For each schedule, get seat counts (Lower + Upper) */
$daySeatCounts = [];
if ($allDaySchedules !== []) {
    $sids = array_map(static fn($s) => (int)$s['schedule_id'], $allDaySchedules);
    $ph   = implode(',', array_fill(0, count($sids), '?'));
    $rows = Database::fetchAll(
        "SELECT bs.schedule_id,
                SUM(CASE WHEN bs.seat_no LIKE 'L%' THEN 1 ELSE 0 END) AS lower_bkd,
                SUM(CASE WHEN bs.seat_no LIKE 'U%' THEN 1 ELSE 0 END) AS upper_bkd,
                COUNT(*) AS total_bkd
           FROM booking_seats bs
           JOIN bookings bk ON bk.id = bs.booking_id
          WHERE bs.schedule_id IN ($ph) AND bs.released_at IS NULL
            AND bk.status IN ('confirmed','pending')
          GROUP BY bs.schedule_id",
        $sids
    );
    foreach ($rows as $r) {
        $daySeatCounts[(int)$r['schedule_id']] = $r;
    }
}
?>

<div class="panel" style="margin-bottom:18px">
  <h2 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    📅 All Buses — <?= Security::e(formatDate($date)) ?>
    <span class="badge"><?= count($allDaySchedules) ?> schedule(s)</span>
    <a href="<?= $base ?>/admin/buses.php" class="btn btn-ghost btn-sm" style="margin-left:auto">🚌 Manage Buses</a>
  </h2>
  <?php if ($allDaySchedules === []): ?>
    <p class="muted" style="padding:12px 0">No scheduled departures on this date.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="date-bus-table">
    <thead><tr>
      <th>Bus</th>
      <th>Route</th>
      <th>Departs</th>
      <th>🪑 Lower Deck</th>
      <th>🛏️ Upper Deck</th>
      <th>Total</th>
      <th>Status</th>
      <th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($allDaySchedules as $ds):
      $sid      = (int)$ds['schedule_id'];
      $total    = (int)$ds['total_seats'];
      $floors   = (int)$ds['floors'];
      $spf      = $floors >= 2 ? (int)ceil($total/2) : $total;
      $cnts     = $daySeatCounts[$sid] ?? [];
      $lBkd     = (int)($cnts['lower_bkd'] ?? 0);
      $uBkd     = (int)($cnts['upper_bkd'] ?? 0);
      $totBkd   = (int)($cnts['total_bkd'] ?? 0);
      $lFree    = max(0, $spf - $lBkd);
      $uFree    = max(0, $spf - $uBkd);
      $lPct     = $spf > 0 ? min(100, round($lBkd*100/$spf)) : 0;
      $uPct     = $spf > 0 ? min(100, round($uBkd*100/$spf)) : 0;
      $occColor = $totBkd >= $total ? '#e74c3c' : ($totBkd >= $total*0.7 ? '#F07C1F' : '#22c55e');
    ?>
    <tr>
      <td>
        <?php $curBus = (int) ($ds['bus_id'] ?? 0); ?>
        <?php if ($canEdit && $activeBuses !== []): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="assign_bus">
            <input type="hidden" name="schedule_id" value="<?= $sid ?>">
            <select name="bus_id" onchange="this.form.submit()" style="max-width:180px;font-size:12px;padding:4px 6px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--ink);font-weight:700">
              <option value="0">— Auto (rotation) —</option>
              <?php foreach ($activeBuses as $ab): ?>
                <option value="<?= (int) $ab['id'] ?>" <?= (int) $ab['id'] === $curBus ? 'selected' : '' ?>>
                  <?= Security::e((string) $ab['bus_name']) ?><?= $ab['bus_number'] ? ' · ' . Security::e((string) $ab['bus_number']) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php else: ?>
          <b><?= Security::e($ds['bus_name']) ?></b>
        <?php endif; ?>
        <?php if ($ds['bus_number']): ?><br><span class="mono muted" style="font-size:11px"><?= Security::e($ds['bus_number']) ?></span><?php endif; ?>
      </td>
      <td><b><?= Security::e($ds['from_city']) ?> → <?= Security::e($ds['to_city']) ?></b><br><span class="muted mono" style="font-size:11px"><?= Security::e($ds['route_code']) ?></span></td>
      <td><?= Security::e(formatTime($ds['dep_time'] ?? null)) ?></td>
      <td>
        <?php if ($floors >= 2): ?>
          <span class="floor-pill lower">L: <?= $lFree ?>/<?= $spf ?> free</span>
          <div class="mini-occ"><div class="mini-occ-fill" style="width:<?= $lPct ?>%;background:<?= $lPct>=80?'#e74c3c':($lPct>=50?'#F07C1F':'#22c55e') ?>"></div></div>
        <?php else: ?>
          <span class="muted">—</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($floors >= 2): ?>
          <span class="floor-pill upper">U: <?= $uFree ?>/<?= $spf ?> free</span>
          <div class="mini-occ"><div class="mini-occ-fill" style="width:<?= $uPct ?>%;background:<?= $uPct>=80?'#e74c3c':($uPct>=50?'#F07C1F':'#22c55e') ?>"></div></div>
        <?php else: ?>
          <span class="floor-pill lower"><?= max(0,$total-$totBkd) ?>/<?= $total ?> free</span>
        <?php endif; ?>
      </td>
      <td style="font-weight:700;color:<?= $occColor ?>">
        <?= $totBkd ?>/<?= $total ?>
        <span style="font-size:11px;font-weight:400;color:var(--mut)"> (<?= $total-$totBkd ?> free)</span>
      </td>
      <td><?= admin_pill((string)$ds['status']) ?></td>
      <td style="white-space:nowrap">
        <?php /* This ROW's own departure — never the page's pinned ?sid,
                 which seatmap.php would let override route AND date. */ ?>
        <a href="<?= $base ?>/admin/seatmap.php?route=<?= $ds['route_id'] ?? '' ?>&date=<?= urlencode($date) ?>&sid=<?= $sid ?>"
           class="btn btn-blue btn-sm">🗺️ Seat Map</a>
        <a href="?route=<?= (int)($ds['route_id'] ?? 0) ?>&date=<?= urlencode($date) ?>"
           class="btn btn-ghost btn-sm">📊 Detail</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($canEdit && $activeBuses !== []): ?>
    <p class="muted" style="font-size:12px;margin:8px 2px 0">
      Change a bus above to move that date's schedule to a different coach. Bookings already on the schedule move with it.
    </p>
  <?php endif; ?>
  <?php endif; ?>
</div>

<form method="get" class="toolbar">
  <label>Route
    <select name="route" onchange="this.form.submit()">
      <?php if ($routes === []): ?>
        <option value="">No routes found</option>
      <?php endif; ?>
      <?php foreach ($routes as $r): ?>
        <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === $routeId ? 'selected' : '' ?>>
          <?= Security::e($r['route_code'] . ' · ' . $r['from_city'] . ' → ' . $r['to_city'] . ' (' . ucfirst((string) $r['coach_type']) . ')') ?>
          <?= (int) $r['is_active'] === 1 ? '' : ' — inactive' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Date <input type="date" name="date" value="<?= Security::e($date) ?>" onchange="this.form.submit()"></label>
  <button class="btn ghost" type="submit">Load</button>
</form>

<?php if ($route === null): ?>
  <div class="panel"><h2>Select a route and date to begin</h2><p style="padding:12px 18px" class="muted">Choose a route from the dropdown above.</p></div>
<?php else: ?>

<?php
/* ========================================================================
 *  2c. Summary Cards
 * ======================================================================== */
$occPercent = $totalSeats > 0 ? round(($counts['booked'] / $totalSeats) * 100) : 0;
$occColor   = $occPercent >= 80 ? '#0a6b3b' : ($occPercent >= 50 ? '#c99200' : '#2E5FA8');
?>
<div style="margin-bottom:6px">
  <strong><?= Security::e($route['from_city'] . ' → ' . $route['to_city']) ?></strong>
  <span class="muted"> · <?= Security::e(formatDate($date)) ?> · <?= Security::e(ucfirst((string) $route['coach_type'])) ?></span>
</div>

<div class="td-cards">
  <div class="td-card gray">
    <div class="k">Total Seats</div>
    <div class="v"><?= $totalSeats ?></div>
  </div>
  <div class="td-card green">
    <div class="k">Available</div>
    <div class="v green"><?= $counts['open'] ?></div>
  </div>
  <div class="td-card blue">
    <div class="k">Booked</div>
    <div class="v blue"><?= $counts['booked'] ?></div>
  </div>
  <div class="td-card amber">
    <div class="k">Held</div>
    <div class="v amber"><?= $counts['held'] ?></div>
  </div>
  <div class="td-card gray">
    <div class="k">Blocked</div>
    <div class="v gray"><?= $counts['blocked'] ?></div>
  </div>
  <div class="td-card money">
    <div class="k">Revenue</div>
    <div class="v green"><?= Security::e(inr($revenue)) ?></div>
  </div>
  <div class="td-card amber">
    <div class="k">Pending</div>
    <div class="v amber"><?= Security::e(inr($pendingAmt)) ?></div>
  </div>
  <div class="td-card orange">
    <div class="k">Cash Due</div>
    <div class="v orange"><?= Security::e(inr($codDue)) ?></div>
  </div>
</div>

<?php /* Occupancy bar */ ?>
<div style="margin-bottom:22px">
  <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--mut);margin-bottom:4px">
    <span>Occupancy</span>
    <span><strong><?= $occPercent ?>%</strong> (<?= $counts['booked'] ?>/<?= $totalSeats ?> seats)</span>
  </div>
  <div class="occ-bar">
    <div class="occ-fill" style="width:<?= $occPercent ?>%;background:<?= $occColor ?>"></div>
  </div>
</div>

<?php
/* ========================================================================
 *  2d. Mini Seat Map
 * ======================================================================== */
?>
<div class="panel">
  <h2>Seat Overview</h2>
  <a href="<?= $base ?>/admin/seatmap.php?route=<?= $routeId ?>&date=<?= urlencode($date) ?><?= $sidReq > 0 ? '&sid=' . $sidReq : '' ?>" style="display:block">
    <?php
      /* Deck-aware layout — reuses Seats::layoutFor() so the thumbnail
         renders the same physical coach geometry as customer + admin/seatmap.
         (Same source-of-truth pattern as the seat-unification rollout on
         29 Aug 2026.) A coach with no configured layout still falls back
         to the flat iteration of $map, so historical data doesn't break. */
      $coachForLayout = (string) ($route['coach_type'] ?? 'sleeper');
      $tdLayout = Seats::layoutFor($coachForLayout, 'sharing');
      $statusFor = static fn(string $st): string => match($st) {
          'open'    => 'seat-open',
          'booked'  => 'seat-booked',
          'held'    => 'seat-held',
          'blocked' => 'seat-blocked',
          'staff'   => 'seat-staff',
          default   => 'seat-open',
      };
    ?>
    <div class="seat-grid">
      <?php foreach ($tdLayout['decks'] as $deck):
        // Lower Floor (1F) blue / Upper Floor (2F) green (admin/_guard.php .floor-*);
        // cells print the passenger-facing label (A1 / A7), never the stored L1 / U1.
        $tdFloor = in_array($deck['key'], ['L', 'U'], true) ? (string) $deck['key'] : '';
      ?>
        <div class="sg-deck<?= $tdFloor !== '' ? ' floor-' . $tdFloor : '' ?>">
          <?php if (count($tdLayout['decks']) > 1): ?>
            <div class="floor-head"><?= Security::e((string) $deck['label']) ?><span class="fh-range"><?= Security::e(Seats::floorRange($tdFloor, $coachForLayout)) ?></span></div>
          <?php endif; ?>
          <div class="sg-rows">
            <?php foreach ($deck['rows'] as $row): ?>
              <div class="sg-row">
              <?php foreach ([$row['left'] ?? [], $row['right'] ?? []] as $side => $ids): ?>
                <?php if ($side === 1): ?><span class="sg-aisle"><?= $tdFloor !== '' ? Security::e(trim(str_replace('Row', '', (string) ($row['label'] ?? '')))) : '' ?></span><?php endif; ?>
                <?php foreach ($ids as $sid):
                  if (!isset($map[$sid])) continue;
                  $s = $map[$sid];
                  $sLbl = Seats::displayLabel((string) $sid, $coachForLayout, 'sharing');
                ?>
                  <div class="seat-cell <?= $statusFor((string) $s['status']) ?>" title="<?= Security::e($sLbl . ' — ' . ucfirst((string) $s['status'])) ?><?= !empty($s['passenger']) ? ' · ' . Security::e((string) $s['passenger']) : '' ?>">
                    <?= Security::e($sLbl) ?>
                  </div>
                <?php endforeach; ?>
              <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </a>
  <div style="padding:8px 18px 14px;display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--mut)">
    <span><span class="seat-cell seat-open" style="display:inline-block;width:14px;height:14px;font-size:0;vertical-align:middle;margin-right:4px;border-radius:3px"></span> Open</span>
    <span><span class="seat-cell seat-booked" style="display:inline-block;width:14px;height:14px;font-size:0;vertical-align:middle;margin-right:4px;border-radius:3px"></span> Booked</span>
    <span><span class="seat-cell seat-held" style="display:inline-block;width:14px;height:14px;font-size:0;vertical-align:middle;margin-right:4px;border-radius:3px"></span> Held</span>
    <span><span class="seat-cell seat-blocked" style="display:inline-block;width:14px;height:14px;font-size:0;vertical-align:middle;margin-right:4px;border-radius:3px"></span> Blocked</span>
    <?php if ($counts['staff'] > 0): ?>
    <span><span class="seat-cell seat-staff" style="display:inline-block;width:14px;height:14px;font-size:0;vertical-align:middle;margin-right:4px;border-radius:3px"></span> Staff</span>
    <?php endif; ?>
  </div>
</div>

<?php
/* ========================================================================
 *  2e. Booking List for This Trip
 * ======================================================================== */
?>
<div class="panel">
  <h2>Bookings · <?= count($bookings) ?> total</h2>
  <?php if ($bookings === []): ?>
    <div style="padding:18px;color:var(--mut);font-size:14px">No bookings for this trip yet.</div>
  <?php else: ?>
    <div style="overflow-x:auto">
    <table class="td-bookings">
      <thead><tr>
        <th>PNR</th><th>Passenger</th><th>Seats</th><th>Amount</th><th>Payment</th><th>Source</th><th>Agent</th><th>Status</th><th>Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($bookings as $bk):
        $soldBy  = (int) ($bk['sold_by_admin_id'] ?? 0);
        $code    = td_agent_code($agentCodes, $soldBy > 0 ? $soldBy : null);
        $bkSource = strtolower((string) ($bk['source'] ?? 'web'));
      ?>
        <tr>
          <td><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $bk['pnr']) ?>" class="mono"><?= Security::e((string) $bk['pnr']) ?></a></td>
          <td><?= Security::e((string) ($bk['passenger_name'] ?? '—')) ?></td>
          <td class="mono"><?php
            $tdSeats = (string) ($bk['seats'] ?? '');
            if ($tdSeats === '') {
                echo '—';
            } else {
                $tdMode = (string) ($bk['booking_mode'] ?? 'sharing');
                echo Security::e(implode(', ', array_map(
                    static fn($s) => Seats::displayLabel((string) $s, 'sleeper', $tdMode),
                    explode(', ', $tdSeats)
                )));
            }
          ?></td>
          <td><?= Security::e(inr((float) ($bk['total_amount'] ?? 0))) ?>
            <?php if ((int) ($bk['is_cod'] ?? 0) === 1): ?>
              <span class="pill" style="background:#ffe6c7;color:#7a4a00;font-size:10px;padding:1px 6px;margin-left:4px">COD</span>
            <?php endif; ?>
          </td>
          <td>
            <?= Security::e(strtoupper((string) ($bk['pay_method'] ?? '—'))) ?>
            <div><?= admin_pill((string) ($bk['pay_status'] ?? 'pending')) ?></div>
          </td>
          <td><?= td_source_badge($bkSource) ?></td>
          <td class="mono"><?= $code !== '' ? Security::e($code) : '<span class="muted">—</span>' ?></td>
          <td><?= admin_pill((string) ($bk['status'] ?? 'pending')) ?></td>
          <td>
            <a class="btn ghost" style="padding:5px 10px;font-size:12px" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $bk['pnr']) ?>">View</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php
/* ========================================================================
 *  2f. Quick Links
 * ======================================================================== */
?>
<div class="td-links">
  <a href="<?= $base ?>/admin/seatmap.php?route=<?= $routeId ?>&date=<?= urlencode($date) ?><?= $sidReq > 0 ? '&sid=' . $sidReq : '' ?>">
    <svg class="a-ic"><use href="#a-seat"/></svg> View Full Seat Map →
  </a>
  <a href="<?= $base ?>/admin/manifest.php?route=<?= $routeId ?>&date=<?= urlencode($date) ?>">
    <svg class="a-ic"><use href="#a-clipboard"/></svg> View Passenger Manifest →
  </a>
  <?php if (Auth::bookingScopeAdminId() === null): ?>
  <a href="<?= $base ?>/admin/chalan.php?<?= $sidReq > 0 ? 'sid=' . $sidReq : 'date=' . urlencode($date) ?>">
    <svg class="a-ic"><use href="#a-doc"/></svg> Bus Chalan (PDF / PNG / WhatsApp) →
  </a>
  <?php endif; ?>
  <a href="<?= $base ?>/admin/bookings.php?route=<?= $routeId ?>&date=<?= urlencode($date) ?>">
    <svg class="a-ic"><use href="#a-ticket"/></svg> View All Bookings →
  </a>
</div>

<?php endif; ?>
<?php
admin_footer();
