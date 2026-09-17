<?php
/**
 * admin/calendar.php — Bus Calendar (5 Sep 2026).
 *
 * One month at a glance, both directions of the daily service on every
 * date, and every per-date decision in one place:
 *
 *   • Service OFF / ON for a date            (day_off / day_on)
 *   • an extra bus — 2nd / 3rd departure     (add_extra_bus)
 *   • a different departure time             (set_dep_time)
 *   • a different vehicle                    (set_bus)
 *   • block / unblock, cancel / reopen       (block_trip / cancel_trip / …)
 *   • jump to the seat map / manifest of ANY departure, extra buses included
 *
 * Default is the daily "1 + 1" (one bus each way, slot 1) that the nightly
 * seeder creates; the calendar only ever ADDS rows or flips flags — it
 * never deletes a schedule, so past bookings are untouched. All writes go
 * through admin/api/schedule-action.php (CSRF, rate-limited, audited).
 *
 * Permission: schedules.manage (manager + superadmin).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';
require_once INCLUDE_PATH . '/schedulemaker.php';   // MAX_DAYS for the daily-bus horizon card
$admin = admin_boot('schedules.manage');

$base  = '';
$csrf  = Security::e(Security::csrfToken());
$k     = CSRF_TOKEN_NAME;
$isSuper = Auth::isSuperadmin();
$today = todayISO();

/* ── Month selection ────────────────────────────────────────────── */
$ym = (string) ($_GET['m'] ?? substr($today, 0, 7));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) { $ym = substr($today, 0, 7); }
$first = $ym . '-01';
$last  = date('Y-m-t', strtotime($first));
$prevM = date('Y-m', strtotime($first . ' -1 month'));
$nextM = date('Y-m', strtotime($first . ' +1 month'));
$openDay = (string) ($_GET['d'] ?? '');
if (!Security::isValidDate($openDay)) { $openDay = ''; }

/* ── Source data ────────────────────────────────────────────────── */
$routes = Database::fetchAll(
    "SELECT r.id, r.route_code, r.from_city, r.to_city, r.dep_time, r.bus_id, r.coach_type, b.bus_number AS default_bus
       FROM routes r LEFT JOIN buses b ON b.id = r.bus_id
      WHERE r.is_active = 1 AND r.dep_time IS NOT NULL
      ORDER BY r.sort_order, r.id"
);
$buses = Database::fetchAll("SELECT id, bus_name, bus_number, total_seats FROM buses WHERE is_active = 1 ORDER BY bus_name");
$drivers = Database::fetchAll("SELECT id, full_name, phone FROM drivers WHERE is_active = 1 ORDER BY full_name");

$rows = Database::fetchAll(
    "SELECT s.id, s.route_id, s.travel_date, s.slot, s.bus_id, s.driver_id, s.total_seats, s.status, s.is_blocked,
            s.dep_time_override, s.delay_minutes, s.cancel_reason, s.fare_override, s.coach_type_override,
            r.coach_type AS route_coach,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
            r.route_code, r.from_city, r.to_city, r.dep_time AS route_dep_time, r.bus_id AS route_bus_id,
            b.bus_number, b.bus_name, d.full_name AS driver_name,
            (SELECT COUNT(*) FROM booking_seats bs WHERE bs.schedule_id = s.id AND bs.released_at IS NULL) AS sold
       FROM schedules s
       JOIN routes r ON r.id = s.route_id
       LEFT JOIN buses b ON b.id = s.bus_id
       LEFT JOIN drivers d ON d.id = s.driver_id
      WHERE s.travel_date BETWEEN :a AND :b AND r.is_active = 1
      ORDER BY s.travel_date, r.sort_order, r.id, s.slot",
    ['a' => $first, 'b' => $last]
);
/* `sold` above counts booking_seats ROWS, and a private cabin is one row
   over two berths — so a coach carrying private sales read as emptier than it
   is, on the very screen used to decide whether to add an extra bus. Restated
   in PHYSICAL berths through the seat engine (one extra query for the page,
   not one per departure). */
$soldBeds = Seats::occupiedBedsFor(array_map(static fn(array $r): int => (int) $r['id'], $rows));
foreach ($rows as &$__r) {
    $__r['sold'] = $soldBeds[(int) $__r['id']] ?? (int) $__r['sold'];
}
unset($__r);

$rows = TripStatus::annotate($rows);

$byDate = [];
foreach ($rows as $r) {
    $byDate[(string) $r['travel_date']][] = $r;
}

/* Short label for a route chip: first 3 letters each side. */
$short = static function (array $r): string {
    $a = mb_substr((string) $r['from_city'], 0, 3);
    $b = mb_substr((string) $r['to_city'], 0, 3);
    return $a . '→' . $b;
};
$hm = static fn(?string $t): string => ($t === null || $t === '') ? '—' : substr($t, 0, 5);

/* Month stats for the header cards. */
$statOff = 0; $statExtra = 0; $statCancel = 0; $statSold = 0;
foreach ($rows as $r) {
    if ((int) $r['is_blocked'] === 1) { $statOff++; }
    if ((int) $r['slot'] > 1) { $statExtra++; }
    if ((string) $r['status'] === 'cancelled') { $statCancel++; }
    $statSold += (int) $r['sold'];
}
$serviceOn = Settings::getBool('daily_service_on', true);

/* ── Automatic daily bus — how far ahead is it actually created? ────
   The nightly seeder (cron/daily-schedule.php, 00:30) creates one row per
   daily-service route per date, 30 days out. The office should be able to
   SEE that rather than trust it, so: how many consecutive days from today
   every daily route is covered for, and when the job last ran. Counted as a
   contiguous run from today — a stray far-future row must not read as
   "covered", which MAX(travel_date) would.                                */
$dailyRouteIds = array_map(static fn(array $r): int => (int) $r['id'], $routes);
$dailyHorizon  = 0;
$dailyThrough  = '';
if ($dailyRouteIds !== []) {
    $have = [];
    foreach (Database::fetchAll(
        'SELECT travel_date, COUNT(DISTINCT route_id) n
           FROM schedules
          WHERE slot = 1 AND travel_date >= :t AND route_id IN (' . implode(',', $dailyRouteIds) . ')
          GROUP BY travel_date',
        ['t' => $today]
    ) as $d) {
        $have[(string) $d['travel_date']] = (int) $d['n'];
    }
    $need = count($dailyRouteIds);
    for ($i = 0; $i < ScheduleMaker::MAX_DAYS + 1; $i++) {
        $d = addDaysISO($today, $i);
        if (($have[$d] ?? 0) < $need) { break; }
        $dailyHorizon = $i + 1;
        $dailyThrough = $d;
    }
}
$dailyLastRun = Settings::getString('daily_schedule_last_run', '');

/* How many berths a departure actually sells. `schedules.total_seats` is
   bookkeeping only — the seat map is drawn from the route's coach type
   (Seats::seatIds), and the two disagree on every row the seeder created with
   the schema default of 40 against a 72-berth sleeper. The calendar shows the
   number the customer will really see. One count per route, not per row. */
$seatTotalByRoute = [];
foreach ($routes as $r) {
    try {
        $seatTotalByRoute[(int) $r['id']] = count(Seats::seatIds((string) $r['coach_type'], 'sharing'));
    } catch (Throwable $e) {
        $seatTotalByRoute[(int) $r['id']] = 0;
    }
}
$seatTotal = static function (array $row) use ($seatTotalByRoute): int {
    // A departure running its own coach (4 Sep 2026) has its own berth count.
    $ov = (string) ($row['coach_type_override'] ?? '');
    if ($ov !== '') {
        try { return count(Seats::seatIds($ov, 'sharing')); } catch (Throwable $e) { /* fall through */ }
    }
    $n = $seatTotalByRoute[(int) $row['route_id']] ?? 0;
    return $n > 0 ? $n : (int) $row['total_seats'];
};

/* JSON for the side panel — everything the day panel needs, per date. */
$panelData = [];
foreach ($byDate as $d => $list) {
    foreach ($list as $r) {
        $st = (array) ($r['_status'] ?? []);
        $panelData[$d][] = [
            'id'        => (int) $r['id'],
            'routeId'   => (int) $r['route_id'],
            'code'      => (string) $r['route_code'],
            'label'     => (string) $r['from_city'] . ' → ' . (string) $r['to_city'],
            'slot'      => (int) $r['slot'],
            'time'      => $hm((string) $r['dep_time']),
            'routeTime' => $hm((string) $r['route_dep_time']),
            'retimed'   => (string) ($r['dep_time_override'] ?? '') !== '',
            'busId'     => (int) ($r['bus_id'] ?? 0),
            'bus'       => trim((string) ($r['bus_name'] ?? '') . ' ' . (string) ($r['bus_number'] ?? '')),
            'otherBus'  => (int) ($r['bus_id'] ?? 0) > 0 && (int) ($r['bus_id'] ?? 0) !== (int) ($r['route_bus_id'] ?? 0),
            'driver'    => (string) ($r['driver_name'] ?? ''),
            'sold'      => (int) $r['sold'],
            'total'     => $seatTotal($r),
            // Per-departure price (4 Sep 2026); 0 = the normal route fare.
            'fare'      => $r['fare_override'] === null ? 0 : round((float) $r['fare_override'], 2),
            // The coach this departure runs, and whether that differs from the route.
            'coach'     => (string) ($r['coach_type_override'] ?: $r['route_coach']),
            'ownCoach'  => (string) ($r['coach_type_override'] ?? '') !== '',
            'status'    => (string) $r['status'],
            'blocked'   => (int) $r['is_blocked'] === 1,
            'state'     => (string) ($st['label'] ?? ''),
            'stateKey'  => (string) ($st['state'] ?? ''),
            'departed'  => in_array((string) ($st['state'] ?? ''), ['departed', 'on_route', 'arrived', 'completed'], true),
            'reason'    => (string) ($r['cancel_reason'] ?? ''),
        ];
    }
}
$jsRoutes = array_map(static fn(array $r): array => [
    'id' => (int) $r['id'], 'code' => (string) $r['route_code'], 'label' => (string) $r['from_city'] . ' → ' . (string) $r['to_city'],
    'time' => $hm((string) $r['dep_time']), 'short' => $short($r),
], $routes);
$jsBuses = array_map(static fn(array $b): array => ['id' => (int) $b['id'], 'label' => (string) $b['bus_name'] . ' · ' . (string) $b['bus_number'] . ' (' . (int) $b['total_seats'] . ' seats)'], $buses);
$jsDrivers = array_map(static fn(array $d): array => ['id' => (int) $d['id'], 'label' => (string) $d['full_name'] . ' · ' . (string) $d['phone']], $drivers);

admin_header('Bus Calendar · ' . date('F Y', strtotime($first)), 'calendar');
?>
<meta name="csrf-token" content="<?= $csrf ?>">
<meta name="csrf-name"  content="<?= Security::e($k) ?>">
<style>
.cal-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0 0 12px}
.cal-head h2{margin:0;font-size:18px}
.cal-head .spacer{flex:1}
.cal-legend{display:flex;gap:10px;flex-wrap:wrap;font-size:12px;color:var(--mut);margin:0 0 12px}
.cal-legend span{display:inline-flex;align-items:center;gap:5px}
.cal-legend i{display:inline-block;width:12px;height:12px;border-radius:4px;border:1px solid var(--line)}
.cal-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px}
.cal-dow{font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--mut);text-align:center;padding:4px 0}
.cal-cell{background:var(--card);border:1px solid var(--line);border-radius:12px;min-height:96px;padding:6px 7px;cursor:pointer;transition:box-shadow .15s,transform .15s;display:flex;flex-direction:column;gap:4px;position:relative}
.cal-cell:hover{box-shadow:0 4px 14px rgba(0,0,0,.08);transform:translateY(-1px)}
.cal-cell.pad{background:transparent;border-color:transparent;cursor:default;box-shadow:none;transform:none}
.cal-cell.past{opacity:.62}
.cal-cell.today{border-color:var(--blue);box-shadow:inset 0 0 0 1px var(--blue)}
.cal-cell.open{outline:2px solid var(--orange);outline-offset:1px}
.cal-cell .dn{font-weight:700;font-size:13px;display:flex;justify-content:space-between;align-items:center}
.cal-cell .dn small{font-weight:600;color:var(--mut);font-size:10.5px}
.chip{display:flex;align-items:center;gap:4px;font-size:11px;line-height:1.2;padding:3px 6px;border-radius:7px;background:#eef2fa;color:#1c3b72;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chip b{font-weight:700}
.chip .n{margin-left:auto;font-variant-numeric:tabular-nums;color:inherit;opacity:.85}
.chip.dir2{background:#e8f6ee;color:#0a6b3b}
.chip.off{background:#f7dcdc;color:#8a1f1f;text-decoration:line-through}
.chip.cx{background:#ececec;color:#666;text-decoration:line-through}
.chip.extra{background:#efeaff;color:#5a3fb0}
.chip.def{background:transparent;border:1px dashed var(--line);color:var(--mut)}
.chip.full{background:#fff4d1;color:#7a4a00}
.cal-cell .dayoff{position:absolute;inset:0;border-radius:12px;background:repeating-linear-gradient(135deg,rgba(176,42,42,.06) 0 6px,transparent 6px 12px);pointer-events:none}
.cal-wrap{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:16px;align-items:start}
.day-panel{position:sticky;top:70px;background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}
.day-panel h3{margin:0;padding:12px 14px;font-size:15px;border-bottom:1px solid var(--line);background:var(--head);display:flex;align-items:center;gap:8px}
.day-panel h3 .x{margin-left:auto;background:none;border:0;font-size:18px;cursor:pointer;color:var(--mut)}
.day-body{padding:12px 14px;display:flex;flex-direction:column;gap:12px;max-height:74vh;overflow:auto}
.trip{border:1px solid var(--line);border-radius:12px;padding:10px 12px;display:flex;flex-direction:column;gap:6px}
.trip.off{border-color:#e3b4b4;background:#fff7f7}
.trip.cx{opacity:.7}
.trip .t1{display:flex;align-items:center;gap:8px;font-weight:700}
.trip .t1 .time{font-size:18px;font-variant-numeric:tabular-nums}
.trip .meta{font-size:12px;color:var(--mut);display:flex;gap:10px;flex-wrap:wrap}
.trip .acts{display:flex;gap:5px;flex-wrap:wrap}
.trip .acts .btn,.day-body .btn{padding:6px 9px;font-size:12px}
.day-empty{color:var(--mut);font-size:13px}
.dp-note{font-size:12px;color:var(--mut)}
.cal-msg{margin:0 0 10px}
@media(max-width:1020px){.cal-wrap{grid-template-columns:1fr}.day-panel{position:fixed;inset:auto 0 0 0;z-index:60;border-radius:16px 16px 0 0;box-shadow:0 -8px 30px rgba(0,0,0,.18);max-height:80vh}.day-panel[hidden]{display:none}}
@media(max-width:640px){.cal-cell{min-height:74px;padding:5px}.chip{font-size:10px;padding:2px 4px}.cal-legend{font-size:11px}}
@media(pointer:coarse){.trip .acts .btn,.day-body .btn{min-height:40px}}
</style>

<div class="cards">
  <div class="card"><div class="k">Departures this month</div><div class="v"><?= count($rows) ?></div><div class="muted" style="font-size:12px"><?= $statExtra ?> extra bus<?= $statExtra === 1 ? '' : 'es' ?></div></div>
  <div class="card"><div class="k">Days OFF (blocked)</div><div class="v"><?= $statOff ?></div></div>
  <div class="card"><div class="k">Cancelled</div><div class="v"><?= $statCancel ?></div></div>
  <div class="card"><div class="k">Seats sold</div><div class="v"><?= number_format($statSold) ?></div></div>
</div>

<!-- Automatic daily bus (4 Sep 2026): one bus each way, every day, created by
     the nightly job. Shown here so "no manual action needed" is visible, with
     a manual top-up for the day the cron is ever missed. -->
<div class="panel" style="padding:12px 16px;margin:0 0 12px;display:flex;gap:14px;align-items:center;flex-wrap:wrap">
  <div style="flex:1;min-width:260px">
    <b>🔁 Automatic daily bus</b>
    <div class="muted" style="font-size:12.5px;margin-top:3px">
      <?php if ($dailyHorizon > 0): ?>
        One departure each way is already created for the next <b><?= (int) $dailyHorizon ?></b> day<?= $dailyHorizon === 1 ? '' : 's' ?>
        (through <b><?= Security::e(formatDate($dailyThrough)) ?></b>) on <?= count($routes) ?> route<?= count($routes) === 1 ? '' : 's' ?>.
      <?php else: ?>
        <span style="color:#b02a2a">No daily departure is created for today yet.</span>
      <?php endif; ?>
      <?php if ($dailyLastRun !== ''): ?>
        · nightly job last ran <?= Security::e(timeAgo(date('Y-m-d H:i:s', strtotime($dailyLastRun)))) ?>
      <?php endif; ?>
    </div>
  </div>
  <button type="button" class="btn ghost" id="seedDailyBtn" title="Create any missing daily departures for the next 30 days. Existing trips are never touched.">⟳ Top up next 30 days</button>
</div>

<?php if (!$serviceOn): ?>
<div class="flash bad cal-msg">⏸ The <b>master daily-service switch is OFF</b> (Bus Fleet page) — nothing sells on any date until it is turned back on. The calendar below still lets you prepare dates.</div>
<?php endif; ?>
<div id="calFlash" class="flash ok cal-msg" hidden></div>

<div class="cal-head">
  <a class="btn ghost" href="?m=<?= $prevM ?>">‹ <?= date('M', strtotime($prevM . '-01')) ?></a>
  <h2>📅 <?= date('F Y', strtotime($first)) ?></h2>
  <a class="btn ghost" href="?m=<?= $nextM ?>"><?= date('M', strtotime($nextM . '-01')) ?> ›</a>
  <a class="btn ghost" href="?m=<?= substr($today, 0, 7) ?>&amp;d=<?= $today ?>">Today</a>
  <span class="spacer"></span>
  <a class="btn ghost" href="<?= $base ?>/admin/schedule.php">Schedule Manager (list)</a>
  <a class="btn ghost" href="<?= $base ?>/admin/buses.php">Bus Fleet</a>
</div>
<div class="cal-legend">
  <span><i style="background:#eef2fa"></i> <?= Security::e($routes[0]['from_city'] ?? 'Out') ?> → <?= Security::e($routes[0]['to_city'] ?? '') ?></span>
  <span><i style="background:#e8f6ee"></i> return</span>
  <span><i style="background:#efeaff"></i> extra bus</span>
  <span><i style="background:#f7dcdc"></i> OFF / blocked</span>
  <span><i style="background:#ececec"></i> cancelled</span>
  <span><i style="background:#fff4d1"></i> full</span>
  <span>⏰ retimed · 🚌 other vehicle · click a day to manage it</span>
</div>

<div class="cal-wrap">
<div>
  <div class="cal-grid">
    <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $dow): ?><div class="cal-dow"><?= $dow ?></div><?php endforeach; ?>
    <?php
      $startPad = (int) date('w', strtotime($first));
      for ($i = 0; $i < $startPad; $i++) { echo '<div class="cal-cell pad"></div>'; }
      $days = (int) date('t', strtotime($first));
      for ($dnum = 1; $dnum <= $days; $dnum++):
        $d = $ym . '-' . str_pad((string) $dnum, 2, '0', STR_PAD_LEFT);
        $list = $byDate[$d] ?? [];
        $isPast = $d < $today;
        $allOff = $list !== [] && count(array_filter($list, static fn($r) => (int) $r['is_blocked'] === 1 || (string) $r['status'] === 'cancelled')) === count($list);
        $cls = 'cal-cell' . ($isPast ? ' past' : '') . ($d === $today ? ' today' : '') . ($d === $openDay ? ' open' : '');
        $sumSold = array_sum(array_map(static fn($r) => (int) $r['sold'], $list));
    ?>
    <div class="<?= $cls ?>" data-date="<?= $d ?>" onclick="calOpen('<?= $d ?>')" role="button" tabindex="0" onkeydown="if(event.key==='Enter'||event.key===' '){calOpen('<?= $d ?>');event.preventDefault();}">
      <?php if ($allOff): ?><div class="dayoff"></div><?php endif; ?>
      <div class="dn"><span><?= $dnum ?></span><?php if ($sumSold > 0): ?><small><?= $sumSold ?> sold</small><?php endif; ?></div>
      <?php if ($list === []): ?>
        <?php if (!$isPast): foreach ($routes as $ri => $rt): ?>
          <div class="chip def" title="Not created yet — the nightly seeder adds it ~30 days ahead; open the day to create it now"><b><?= Security::e($short($rt)) ?></b> <?= $hm((string) $rt['dep_time']) ?><span class="n">default</span></div>
        <?php endforeach; endif; ?>
      <?php else: foreach ($list as $r):
          $dir2 = (isset($routes[0]) && (int) $r['route_id'] !== (int) $routes[0]['id']);
          $c = 'chip' . ($dir2 ? ' dir2' : '');
          if ((string) $r['status'] === 'cancelled') { $c .= ' cx'; }
          elseif ((int) $r['is_blocked'] === 1) { $c .= ' off'; }
          elseif ((int) $r['slot'] > 1) { $c .= ' extra'; }
          elseif ($seatTotal($r) > 0 && (int) $r['sold'] >= $seatTotal($r)) { $c .= ' full'; }
          $marks = ((string) ($r['dep_time_override'] ?? '') !== '' ? '⏰' : '')
                 . (((int) ($r['bus_id'] ?? 0) > 0 && (int) $r['bus_id'] !== (int) ($r['route_bus_id'] ?? 0)) ? '🚌' : '');
      ?>
        <div class="<?= $c ?>" title="<?= Security::e((string) $r['from_city'] . ' → ' . (string) $r['to_city'] . ' · ' . $hm((string) $r['dep_time']) . ' · ' . (string) ($r['bus_number'] ?? 'no bus') . ' · ' . (int) $r['sold'] . '/' . $seatTotal($r)) ?>">
          <b><?= Security::e($short($r)) ?></b> <?= $hm((string) $r['dep_time']) ?><?= (int) $r['slot'] > 1 ? ' ·B' . (int) $r['slot'] : '' ?> <?= $marks ?><span class="n"><?= (int) $r['sold'] ?>/<?= $seatTotal($r) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endfor; ?>
  </div>
  <p class="dp-note" style="margin-top:10px">Default is one bus each way every day (slot 1), created ~30 days ahead by the nightly seeder. The calendar only adds departures or flips flags — it never deletes a trip, so tickets already sold are never touched.</p>
</div>

<aside class="day-panel" id="dayPanel" hidden>
  <h3><span id="dpTitle">Day</span><button type="button" class="x" onclick="calClose()" aria-label="Close">×</button></h3>
  <div class="day-body" id="dpBody"></div>
</aside>
</div>

<script>
window.CAL = {
  days: <?= json_encode($panelData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  routes: <?= json_encode($jsRoutes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  buses: <?= json_encode($jsBuses, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  drivers: <?= json_encode($jsDrivers, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
  today: <?= json_encode($today) ?>, month: <?= json_encode($ym) ?>, isSuper: <?= $isSuper ? 'true' : 'false' ?>,
  csrf: <?= json_encode(Security::csrfToken()) ?>, csrfName: <?= json_encode($k) ?>, base: <?= json_encode($base) ?>
};
(function () {
  var C = window.CAL, panel = document.getElementById('dayPanel'), body = document.getElementById('dpBody'), title = document.getElementById('dpTitle');
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function fmtDate(d){ var x = new Date(d + 'T00:00:00'); return x.toLocaleDateString('en-IN', { weekday:'short', day:'numeric', month:'short', year:'numeric' }); }
  function flash(msg, ok){ var f = document.getElementById('calFlash'); f.className = 'flash ' + (ok ? 'ok' : 'bad') + ' cal-msg'; f.textContent = msg; f.hidden = false; window.scrollTo({top:0, behavior:'smooth'}); }
  function api(action, data, btn){
    var fd = new FormData(); fd.append(C.csrfName, C.csrf); fd.append('action', action);
    Object.keys(data || {}).forEach(function(k){ if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]); });
    if (btn) { btn.disabled = true; btn.dataset.txt = btn.textContent; btn.textContent = '…'; }
    return fetch(C.base + '/admin/api/schedule-action.php', { method:'POST', body: fd, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'} })
      .then(function(r){ return r.json().catch(function(){ return { ok:false, error:'Server sent a non-JSON reply.' }; }); })
      .then(function(j){
        if (btn) { btn.disabled = false; btn.textContent = btn.dataset.txt || 'OK'; }
        if (j && j.ok) return j;
        flash((j && (j.error || j.message)) || 'Something went wrong.', false);
        return null;
      })
      .catch(function(){ if (btn) { btn.disabled = false; btn.textContent = btn.dataset.txt || 'OK'; } flash('Network error — please try again.', false); return null; });
  }
  function reloadTo(date, msg){
    try { sessionStorage.setItem('shg_cal_flash', msg || ''); } catch (e) {}
    var u = new URL(location.href); u.searchParams.set('m', date.slice(0, 7)); u.searchParams.set('d', date); location.href = u.toString();
  }
  function busSelect(id, selected, allowNone){
    var o = allowNone ? '<option value="0">— route default / rotation —</option>' : '';
    C.buses.forEach(function(b){ o += '<option value="' + b.id + '"' + (b.id === selected ? ' selected' : '') + '>' + esc(b.label) + '</option>'; });
    return '<select id="' + id + '" style="padding:7px 9px;border:1px solid var(--line);border-radius:8px;max-width:100%">' + o + '</select>';
  }
  function driverSelect(id){
    var o = '<option value="0">— no driver —</option>';
    C.drivers.forEach(function(d){ o += '<option value="' + d.id + '">' + esc(d.label) + '</option>'; });
    return '<select id="' + id + '" style="padding:7px 9px;border:1px solid var(--line);border-radius:8px;max-width:100%">' + o + '</select>';
  }

  window.calClose = function(){ panel.hidden = true; document.querySelectorAll('.cal-cell.open').forEach(function(c){ c.classList.remove('open'); }); };

  window.calOpen = function(date){
    var trips = C.days[date] || [], past = date < C.today;
    document.querySelectorAll('.cal-cell.open').forEach(function(c){ c.classList.remove('open'); });
    var cell = document.querySelector('.cal-cell[data-date="' + date + '"]'); if (cell) cell.classList.add('open');
    title.textContent = fmtDate(date);
    var html = '';
    var live = trips.filter(function(t){ return t.status !== 'cancelled'; });
    var allOff = live.length > 0 && live.every(function(t){ return t.blocked; });

    if (!past) {
      html += '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">';
      if (allOff) html += '<button class="btn ok" data-act="day_on">▶ Service ON for this day</button>';
      else html += '<button class="btn danger" data-act="day_off">⏸ Service OFF for this day</button>';
      if (trips.length < C.routes.length) html += '<button class="btn ghost" data-act="seed">＋ Create today\'s default departures</button>';
      html += '</div>';
      html += '<div class="dp-note">OFF hides every departure of the day from booking (tickets already sold stay valid). Extra buses, retiming and a different vehicle are per departure below.</div>';
    } else {
      html += '<div class="dp-note">Past date — shown for the record. Seat map and manifest stay available.</div>';
    }

    if (!trips.length) {
      html += '<div class="day-empty">No departures created for this day yet' + (past ? '.' : ' — the default 1 + 1 appears automatically ~30 days ahead, or create it now.') + '</div>';
    }
    trips.forEach(function(t){
      var cls = 'trip' + (t.blocked ? ' off' : '') + (t.status === 'cancelled' ? ' cx' : '');
      html += '<div class="' + cls + '" data-sid="' + t.id + '">';
      html += '<div class="t1"><span class="time">' + esc(t.time) + '</span><span>' + esc(t.label) + '</span>' + (t.slot > 1 ? '<span class="pill" style="background:#efeaff;color:#5a3fb0">Bus ' + t.slot + '</span>' : '') + '</div>';
      html += '<div class="meta">' +
        '<span>' + (t.bus ? '🚌 ' + esc(t.bus) + (t.otherBus ? ' <b title="Different from the route default">(other vehicle)</b>' : '') : '🚌 no vehicle') + '</span>' +
        (t.driver ? '<span>👤 ' + esc(t.driver) + '</span>' : '') +
        '<span>🎟 ' + t.sold + ' / ' + t.total + ' sold</span>' +
        (t.fare > 0 ? '<span title="This bus has its own price">💰 ₹' + t.fare + ' / seat</span>' : '') +
        (t.ownCoach ? '<span title="This bus runs a different coach than the route">🪑 ' + esc(t.coach) + ' coach</span>' : '') +
        (t.retimed ? '<span>⏰ retimed (route ' + esc(t.routeTime) + ')</span>' : '') +
        (t.state ? '<span class="pill" style="background:var(--head)">' + esc(t.state) + '</span>' : '') +
        (t.blocked ? '<span class="pill" style="background:#f7dcdc;color:#8a1f1f">🚫 OFF / blocked</span>' : '') +
        (t.status === 'cancelled' ? '<span class="pill" style="background:#ececec;color:#666">❌ cancelled' + (t.reason ? ' · ' + esc(t.reason) : '') + '</span>' : '') +
        '</div>';
      html += '<div class="acts">';
      html += '<a class="btn ghost" href="' + C.base + '/admin/seatmap.php?sid=' + t.id + '">🪑 Seats</a>';
      html += '<a class="btn ghost" href="' + C.base + '/admin/manifest.php?sid=' + t.id + '&date=' + encodeURIComponent(date) + '">📋 Manifest</a>';
      html += '<a class="btn ghost" href="' + C.base + '/admin/chalan.php?sid=' + t.id + '">🧾 Chalan</a>';
      if (t.status !== 'cancelled' && !past) {
        if (!t.departed) html += '<button class="btn ghost" data-act="retime" data-sid="' + t.id + '" data-time="' + esc(t.time) + '">⏰ Time</button>';
        html += '<button class="btn ghost" data-act="vehicle" data-sid="' + t.id + '" data-bus="' + t.busId + '">🚌 Vehicle</button>';
        html += '<button class="btn ghost" data-act="price" data-sid="' + t.id + '" data-fare="' + (t.fare || '') + '">💰 Price</button>';
        html += '<button class="btn ghost" data-act="coach" data-sid="' + t.id + '" data-coach="' + esc(t.coach) + '" data-sold="' + t.sold + '">🪑 Layout</button>';
        html += '<button class="btn ghost" data-act="extra" data-sid="' + t.id + '" data-time="' + esc(t.time) + '">➕ Extra bus</button>';
        html += '<button class="btn ghost" data-act="' + (t.blocked ? 'unblock_trip' : 'block_trip') + '" data-sid="' + t.id + '">' + (t.blocked ? '▶ Unblock' : '🚫 Block') + '</button>';
        html += '<button class="btn ghost danger" data-act="cancel" data-sid="' + t.id + '" data-sold="' + t.sold + '">❌ Cancel</button>';
      } else if (t.status === 'cancelled' && C.isSuper && !past) {
        html += '<button class="btn ghost" data-act="uncancel_trip" data-sid="' + t.id + '">↩ Reopen</button>';
      }
      html += '</div><div class="sub" id="sub' + t.id + '"></div></div>';
    });
    body.innerHTML = html;
    panel.hidden = false;
    if (window.matchMedia('(max-width:1020px)').matches) { panel.scrollIntoView({ block:'end' }); }

    body.querySelectorAll('[data-act]').forEach(function(btn){
      btn.addEventListener('click', function(){
        var act = btn.getAttribute('data-act'), sid = btn.getAttribute('data-sid');
        if (act === 'day_off') {
          if (!confirm('Service OFF for ' + fmtDate(date) + '? Every departure of the day is hidden from booking. Tickets already sold stay valid.')) return;
          api('day_off', { date: date }, btn).then(function(j){ if (j) reloadTo(date, j.message); });
        } else if (act === 'day_on') {
          api('day_on', { date: date }, btn).then(function(j){ if (j) reloadTo(date, j.message); });
        } else if (act === 'seed') {
          var have = {}; trips.forEach(function(t){ have[t.routeId] = true; });
          var todo = C.routes.filter(function(r){ return !have[r.id]; });
          if (!todo.length) return;
          btn.disabled = true;
          var chain = Promise.resolve(), made = 0;
          todo.forEach(function(r){ chain = chain.then(function(){ return api('add_trip', { route_id: r.id, travel_date: date }).then(function(j){ if (j) made++; }); }); });
          chain.then(function(){ reloadTo(date, made + ' departure(s) created for ' + date + '.'); });
        } else if (act === 'retime') {
          var v = prompt('New departure time (HH:MM, 24h). Leave empty to go back to the route time.', btn.getAttribute('data-time') || '');
          if (v === null) return; v = v.trim();
          if (v !== '' && !/^([01]\d|2[0-3]):[0-5]\d$/.test(v)) { flash('Time must be HH:MM.', false); return; }
          api('set_dep_time', { schedule_id: sid, dep_time: v }, btn).then(function(j){ if (j) reloadTo(date, j.message); });
        } else if (act === 'vehicle') {
          var sub = document.getElementById('sub' + sid);
          sub.innerHTML = '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px">' + busSelect('bus' + sid, parseInt(btn.getAttribute('data-bus'), 10) || 0, true) + '<button class="btn ok" id="busGo' + sid + '">Set vehicle</button></div>';
          document.getElementById('busGo' + sid).addEventListener('click', function(){
            var b = parseInt(document.getElementById('bus' + sid).value, 10) || 0;
            api('set_bus', { schedule_id: sid, bus_id: b }, this).then(function(j){ if (j) reloadTo(date, j.message); });
          });
        } else if (act === 'price') {
          var cur = btn.getAttribute('data-fare') || '';
          var v = prompt('Price for THIS bus, ₹ per seat.\n\nLeave empty to use the normal fare for the route and direction.\nTickets already sold keep the amount they were sold at.', cur);
          if (v === null) return; v = v.trim();
          if (v !== '' && !/^\d+(\.\d{1,2})?$/.test(v)) { flash('The price must be a number, e.g. 2200.', false); return; }
          api('set_price', { schedule_id: sid, fare: v }, btn).then(function(j){ if (j) reloadTo(date, j.message); });
        } else if (act === 'coach') {
          var soldC = parseInt(btn.getAttribute('data-sold'), 10) || 0;
          if (soldC > 0) { flash('This bus has ' + soldC + ' seat(s) sold — its layout can no longer be changed. Add a separate extra bus instead.', false); return; }
          var cur = btn.getAttribute('data-coach') || '';
          var v3 = prompt('Seat layout for THIS bus.\n\nType  sleeper  or  seater .\nLeave empty to go back to the coach this route normally uses.', cur);
          if (v3 === null) return; v3 = v3.trim().toLowerCase();
          if (v3 !== '' && v3 !== 'sleeper' && v3 !== 'seater') { flash('Type sleeper or seater.', false); return; }
          api('set_coach', { schedule_id: sid, coach_type: v3 }, btn).then(function(j){ if (j) reloadTo(date, j.message); });
        } else if (act === 'extra') {
          var sub2 = document.getElementById('sub' + sid);
          sub2.innerHTML = '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px">' +
            '<input type="time" id="xt' + sid + '" value="' + esc(btn.getAttribute('data-time')) + '" style="padding:7px 9px;border:1px solid var(--line);border-radius:8px" aria-label="Departure time">' +
            busSelect('xb' + sid, 0, true).replace('— route default / rotation —', '— same coach as bus 1 (set later) —') + driverSelect('xd' + sid) +
            '<input type="number" id="xf' + sid + '" min="0" step="1" placeholder="₹ / seat" title="Price per seat for this bus — blank uses the normal fare" style="width:96px;padding:7px 9px;border:1px solid var(--line);border-radius:8px" aria-label="Price per seat">' +
            '<select id="xc' + sid + '" title="Seat layout for this bus" aria-label="Seat layout" style="padding:7px 9px;border:1px solid var(--line);border-radius:8px">' +
              '<option value="">— same layout as the route —</option><option value="sleeper">Sleeper berths</option><option value="seater">Seater seats</option></select>' +
            '<button class="btn ok" id="xGo' + sid + '">Add extra bus</button></div>' +
            '<div class="dp-note">A second departure of this route on the same day, with its own seat map and its own price. Price is optional — blank means the normal fare. The berth layout follows the coach type on this route. Customers see it as an extra bus.</div>';
          document.getElementById('xGo' + sid).addEventListener('click', function(){
            api('add_extra_bus', {
              schedule_id: sid,
              dep_time: document.getElementById('xt' + sid).value || '',
              bus_id: parseInt(document.getElementById('xb' + sid).value, 10) || 0,
              driver_id: parseInt(document.getElementById('xd' + sid).value, 10) || 0,
              fare: document.getElementById('xf' + sid).value || '',
              coach_type: document.getElementById('xc' + sid).value || ''
            }, this).then(function(j){ if (j) reloadTo(date, j.message); });
          });
        } else if (act === 'block_trip' || act === 'unblock_trip' || act === 'uncancel_trip') {
          api(act, { schedule_id: sid }, btn).then(function(j){ if (j) reloadTo(date, j.message); });
        } else if (act === 'cancel') {
          var sold = parseInt(btn.getAttribute('data-sold'), 10) || 0;
          var reason = prompt('Cancel this departure' + (sold ? ' (' + sold + ' seat(s) sold — handle refunds per PNR afterwards)' : '') + '. Reason:', '');
          if (reason === null) return; if (!reason.trim()) { flash('A reason is required to cancel.', false); return; }
          api('cancel_trip', { schedule_id: sid, reason: reason.trim() }, btn).then(function(j){ if (j) reloadTo(date, j.message); });
        }
      });
    });
  };

  /* Manual top-up of the automatic daily bus — the same call the nightly cron
     makes, so it can only ADD missing departures; existing trips are skipped. */
  var seedBtn = document.getElementById('seedDailyBtn');
  if (seedBtn) {
    seedBtn.addEventListener('click', function () {
      api('seed_daily', { days: 30 }, seedBtn).then(function (j) {
        if (j) { reloadTo(C.today, j.message); }
      });
    });
  }

  try { var m = sessionStorage.getItem('shg_cal_flash'); if (m) { flash(m, true); sessionStorage.removeItem('shg_cal_flash'); } } catch (e) {}
  <?php if ($openDay !== '' && substr($openDay, 0, 7) === $ym): ?>calOpen(<?= json_encode($openDay) ?>);<?php endif; ?>
})();
</script>
<?php admin_footer(); ?>
