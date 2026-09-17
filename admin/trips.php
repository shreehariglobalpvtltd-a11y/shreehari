<?php
/**
 * admin/trips.php — the operations board.
 *
 * Every departure from today onward, sorted by time, showing seats sold /
 * total, who is selling (online customer / named agent / counter), and an
 * urgency flag as departure nears (highlighted within 24h, stronger within
 * 3h). The counts auto-refresh so staff and agents never re-sell or
 * mishandle a soon-to-depart trip. Each row links through to the per-seat
 * map, where the full "who booked which seat" attribution lives.
 *
 * It is also where a journey is driven: one click on Bus started /
 * Reached border / Arrived records the milestone AND messages every
 * confirmed passenger on that coach (V6 §"WhatsApp + SMS Automation").
 * The send is exactly-once — see includes/tripnotify.php — so a
 * double-click, or a second press after a late booking, can never spam
 * anyone.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('schedules.view');
require_once INCLUDE_PATH . '/tripstatus.php';

$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$today = todayISO();
$flash = null;

/* ---- One-click journey milestones -------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            Auth::requireAdmin('schedules.edit');
            $action = (string) ($_POST['action'] ?? 'milestone');

            /* Role audit (3 Sep 2026): counter agents hold schedules.edit so
               they can mark journey milestones and sell from the seat map —
               but that permission was ALSO the only gate on rewriting the
               fleet rotation, renaming/renumbering a bus, reassigning the
               driver and setting a delay that WhatsApps every passenger.
               Those are management actions: require schedules.manage
               (manager / superadmin). Milestones stay on schedules.edit. */
            if (in_array($action, ['rotation', 'bus', 'assign_driver', 'set_delay'], true)) {
                Auth::requireAdmin('schedules.manage');
            }

            if ($action === 'rotation') {
                /* Slot numbers, not checkboxes: the ORDER is the rotation, so
                   the office needs to say "this bus runs 1st, that one 2nd".
                   Blank slot = not in the rotation at all. */
                $slots = [];
                foreach ((array) ($_POST['slot'] ?? []) as $busId => $slot) {
                    $slot = trim((string) $slot);
                    if ($slot !== '' && (int) $slot > 0) {
                        $slots[(int) $busId] = (int) $slot;
                    }
                }
                asort($slots);   // order by the slot the office typed

                Fleet::saveRotation(
                    !empty($_POST['rotation_enabled']),
                    array_keys($slots),
                    (int) ($_POST['cycle_days'] ?? 3),
                    (string) ($_POST['start_date'] ?? $today),
                    (int) $admin['id']
                );
                $flash = ['ok', 'Bus rotation saved.'];

            } elseif ($action === 'bus') {
                Fleet::updateBus(
                    (int) ($_POST['bus_id'] ?? 0),
                    (string) ($_POST['bus_name'] ?? ''),
                    (string) ($_POST['bus_number'] ?? ''),
                    (int) $admin['id'],
                    isset($_POST['coach_type'])  ? (string) $_POST['coach_type']  : null,
                    isset($_POST['total_seats']) ? (int) $_POST['total_seats']    : null,
                    isset($_POST['registration']) ? (string) $_POST['registration'] : null
                );
                $flash = ['ok', 'Bus details updated.'];

            } elseif ($action === 'assign_driver') {
                // Phase 5 — per-trip driver assignment. Guarded on
                // schedules.edit (same permission the milestone buttons
                // demand); blank driver_id means "unassign".
                $sid = (int) ($_POST['schedule_id'] ?? 0);
                $drv = (int) ($_POST['driver_id'] ?? 0);
                if ($sid <= 0) { throw new RuntimeException('Missing schedule id.'); }
                if ($drv > 0) {
                    // Verify the driver actually exists + is active.
                    $ok = Database::exists('SELECT 1 FROM drivers WHERE id = :id AND is_active = 1', ['id' => $drv]);
                    if (!$ok) { throw new RuntimeException('That driver is inactive or gone. Reload the page.'); }
                }
                $before = Database::fetch('SELECT driver_id FROM schedules WHERE id = :id', ['id' => $sid]);
                Database::update('schedules', ['driver_id' => $drv > 0 ? $drv : null], 'id = :id', ['id' => $sid]);
                Logger::audit('trip.assign_driver', 'schedule', (string) $sid, $before, ['driver_id' => $drv > 0 ? $drv : null], 'by admin #' . $admin['id']);
                $flash = ['ok', $drv > 0 ? 'Driver assigned.' : 'Driver un-assigned.'];

            } elseif ($action === 'set_delay') {
                // Phase 6 — delay workflow. Sets delay_minutes + delay_note
                // so TripStatus::compute() shifts the effective departure and
                // the dashboard pill reflects the delay in real time.
                $sid   = (int) ($_POST['schedule_id'] ?? 0);
                $mins  = max(0, min(600, (int) ($_POST['delay_minutes'] ?? 0)));
                $note  = Security::clean((string) ($_POST['delay_note'] ?? ''), 255);
                if ($sid <= 0) { throw new RuntimeException('Missing schedule id.'); }
                $before = Database::fetch('SELECT delay_minutes, delay_note FROM schedules WHERE id = :id', ['id' => $sid]);
                Database::update('schedules', ['delay_minutes' => $mins, 'delay_note' => $note !== '' ? $note : null], 'id = :id', ['id' => $sid]);
                Logger::audit('trip.set_delay', 'schedule', (string) $sid, $before, ['delay_minutes' => $mins, 'delay_note' => $note], 'by admin #' . $admin['id']);

                // Notify passengers about the delay when it's > 0 and the
                // trip hasn't departed yet.
                if ($mins > 0) {
                    try {
                        TripNotify::notifyDelay($sid, $mins, $note, (int) $admin['id']);
                    } catch (Throwable $e) {
                        // Non-fatal — delay is saved even if WA/SMS fails.
                        Logger::error('trip.set_delay.notify', $e->getMessage());
                    }
                }
                $flash = ['ok', $mins > 0 ? "Delay set: {$mins} minutes." : 'Delay cleared.'];

            } else {
                $res   = TripNotify::markTrip((int) ($_POST['schedule_id'] ?? 0), (string) ($_POST['event'] ?? ''), (int) $admin['id']);
                $flash = [$res['failed'] > 0 ? 'bad' : 'ok', $res['message']];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Which slice of the board? ------------------------------------ */
$viewKey = (string) ($_GET['view'] ?? 'upcoming');
$views   = [
    'today'    => 'Today',
    'upcoming' => 'Upcoming',
    'running'  => 'On the road',
    'arrived'  => 'Arrived',
    'all'      => 'All',
];
if (!isset($views[$viewKey])) { $viewKey = 'upcoming'; }

$where  = ["s.travel_date >= :today"];
$params = ['today' => $today];
switch ($viewKey) {
    case 'today':
        $where  = ["s.travel_date = :today", "s.status <> 'cancelled'"];
        break;
    case 'upcoming':
        $where[] = "s.status = 'scheduled'";
        break;
    case 'running':
        $where   = ["s.status = 'departed'", "s.travel_date >= DATE_SUB(:today, INTERVAL 3 DAY)"];
        break;
    case 'arrived':
        $where   = ["s.status = 'arrived'", "s.travel_date >= DATE_SUB(:today, INTERVAL 14 DAY)"];
        break;
    case 'all':
        $where   = ["s.travel_date >= DATE_SUB(:today, INTERVAL 7 DAY)"];
        break;
}

$schedules = Database::fetchAll(
    "SELECT s.id AS schedule_id, s.travel_date, s.total_seats, s.status, s.driver_id,
            s.delay_minutes, s.delay_note,
            r.id AS route_id, r.route_code, r.from_city, r.to_city, r.dep_time, r.coach_type,
            COALESCE(b.bus_name, '—') AS bus_name, COALESCE(b.bus_number, '') AS bus_number,
            d.full_name AS driver_name, d.phone AS driver_phone
       FROM schedules s
       JOIN routes r ON r.id = s.route_id
       LEFT JOIN buses   b ON b.id = COALESCE(s.bus_id, r.bus_id)
       LEFT JOIN drivers d ON d.id = s.driver_id
      WHERE " . implode(' AND ', $where) . "
      ORDER BY s.travel_date ASC, r.dep_time ASC
      LIMIT 300",
    $params
);

/* Active driver list for the per-trip dropdown. Cheap — the fleet
   is O(dozens), not O(thousands). */
$activeDrivers = Database::fetchAll(
    "SELECT id, full_name, role FROM drivers WHERE is_active = 1 ORDER BY role, full_name"
);

/* ---- Return-trip pairing (Phase 7) ----------------------------------
   For each route, find its reverse (from_city↔to_city swapped). Build a
   map: routeId+date → schedule_id of the return leg, so the board can
   show a "↩ Return" link next to each outbound departure. */
$returnPairs = [];
if ($schedules !== []) {
    // Collect distinct route_ids in this list
    $routeIds = array_values(array_unique(array_map(
        static fn($s) => (int) $s['route_id'], $schedules
    )));
    // Build reverse lookup: routeId → reverseRouteId
    $reverseMap = [];
    $routes_list = Database::fetchAll("SELECT id, from_city, to_city FROM routes WHERE is_active = 1");
    $byCities = [];
    foreach ($routes_list as $rl) {
        $byCities[strtolower(trim((string) $rl['to_city'])) . '|' . strtolower(trim((string) $rl['from_city']))][] = (int) $rl['id'];
    }
    foreach ($routes_list as $rl) {
        $key = strtolower(trim((string) $rl['from_city'])) . '|' . strtolower(trim((string) $rl['to_city']));
        if (isset($byCities[$key])) {
            $reverseMap[(int) $rl['id']] = $byCities[$key][0];
        }
    }
    // For each schedule row, look up if the reverse route has a departure on the same date or +1 day
    if ($reverseMap !== []) {
        $dates = array_values(array_unique(array_map(static fn($s) => (string) $s['travel_date'], $schedules)));
        // One query: reverse routes' schedules for the same date window
        $revRouteIds = array_values(array_unique(array_values($reverseMap)));
        if ($revRouteIds !== [] && $dates !== []) {
            $minDate = min($dates);
            $maxDate = date('Y-m-d', strtotime(max($dates) . ' +1 day'));
            $phR = implode(',', array_fill(0, count($revRouteIds), '?'));
            $revScheds = Database::fetchAll(
                "SELECT id, route_id, travel_date FROM schedules
                  WHERE route_id IN ($phR) AND travel_date BETWEEN ? AND ? AND status <> 'cancelled' AND slot = 1",
                array_merge($revRouteIds, [$minDate, $maxDate])
            );
            $revIndex = [];
            foreach ($revScheds as $rs) {
                $revIndex[(int) $rs['route_id'] . ':' . (string) $rs['travel_date']] = (int) $rs['id'];
            }
            foreach ($schedules as $s) {
                $rid  = (int) $s['route_id'];
                $date = (string) $s['travel_date'];
                $revR = $reverseMap[$rid] ?? null;
                if ($revR !== null) {
                    // Same date or next day
                    $returnPairs[(int) $s['schedule_id']] =
                        $revIndex[$revR . ':' . $date] ??
                        $revIndex[$revR . ':' . date('Y-m-d', strtotime($date . ' +1 day'))] ??
                        null;
                }
            }
        }
    }
}

/* Channel breakdown + live sold count in one grouped query. */
$channels = [];
$statuses = [];
if ($schedules !== []) {
    $ids = array_map(static fn($s) => (int) $s['schedule_id'], $schedules);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    foreach (Database::fetchAll(
        "SELECT bs.schedule_id, bk.source, COUNT(*) AS n
           FROM booking_seats bs JOIN bookings bk ON bk.id = bs.booking_id
          WHERE bs.released_at IS NULL AND bs.schedule_id IN ($ph)
          GROUP BY bs.schedule_id, bk.source",
        $ids
    ) as $r) {
        $sid = (int) $r['schedule_id'];
        $channels[$sid] = $channels[$sid] ?? ['Online' => 0, 'Agent' => 0, 'Counter' => 0, 'Admin' => 0, 'sold' => 0];
        $src    = (string) $r['source'];
        $bucket = $src === 'agent' ? 'Agent' : ($src === 'counter' ? 'Counter' : ($src === 'admin' ? 'Admin' : 'Online'));
        $channels[$sid][$bucket] += (int) $r['n'];
        $channels[$sid]['sold']  += (int) $r['n'];
    }

    // Milestones already recorded, so a marked button reads as done rather
    // than inviting a second press. Degrades to "nothing marked yet" when
    // the trip-lifecycle migration has not been run on this database.
    try { $statuses = TripNotify::statusMap($ids); }
    catch (Throwable $e) { $statuses = []; }
}

$within24 = 0; $within3 = 0; $soldUpcoming = 0;
foreach ($schedules as $s) {
    $h = hoursUntil((string) $s['travel_date'], (string) $s['dep_time']);
    if ($h >= 0 && $h < 24) { $within24++; }
    if ($h >= 0 && $h < 3)  { $within3++; }
    $soldUpcoming += $channels[(int) $s['schedule_id']]['sold'] ?? 0;
}

/* Attach the sold count to each schedule row and annotate through
   TripStatus — one pass, so the row loop below can read the unified
   state (colour + label + sold-out detection) instead of the old
   hour-band heuristic. `id` is the key annotate() picks first; the
   original `schedule_id` alias stays for the rest of the render. */
foreach ($schedules as &$_sRow) {
    $_sRow['id']           = (int) $_sRow['schedule_id'];
    $_sRow['seats_booked'] = (int) ($channels[(int) $_sRow['schedule_id']]['sold'] ?? 0);
}
unset($_sRow);
$schedules = TripStatus::annotate($schedules);

/** Urgency → [class, fg, bg, label] using the brand warn/bad tones.
 *  Kept as a FALLBACK for rows where TripStatus::annotate() couldn't
 *  compute a state (missing dep_time etc.) — the primary pill now comes
 *  from the unified TripStatus meta so a sold-out or delayed trip is
 *  coloured the same way everywhere in the admin. */
function trips_urgency(float $h): array
{
    if ($h < 0)  { return ['departed', '#777', '#eee',     'Departed']; }
    if ($h < 3)  { return ['very',     '#fff', '#b02a2a',  'in ' . number_format($h, 1) . 'h']; }
    if ($h < 24) { return ['urgent',   '#7a5200', '#ffe6b0', 'in ' . number_format($h, 0) . 'h']; }
    return ['ok', '#0a6b3b', '#d7f4e3', 'in ' . number_format($h / 24, 0) . 'd'];
}

$canDrive  = Auth::can('schedules.edit');
$canManage = Auth::canManageSchedules();   // rotation / bus / driver / delay forms
$csrf     = Security::e(Security::csrfToken());
$k        = CSRF_TOKEN_NAME;

/** One milestone button — or a "done" stamp once it has been recorded. */
function trip_milestone_btn(int $sid, string $event, string $icon, string $label, array $done, bool $canDrive, string $csrf, string $k): string
{
    $marked = isset($done[$event]);
    if (!$canDrive) {
        return $marked
            ? '<span class="pill" style="background:#d7f4e3;color:#0a6b3b">' . $icon . ' ' . Security::e($label) . '</span>'
            : '';
    }

    $confirm = $marked
        ? 'Already marked. Press again only to catch up passengers booked since then. Continue?'
        : 'Mark "' . $label . '" and message every confirmed passenger on this trip?';

    return '<form method="post" style="display:inline">'
        . '<input type="hidden" name="' . $k . '" value="' . $csrf . '">'
        . '<input type="hidden" name="schedule_id" value="' . $sid . '">'
        . '<input type="hidden" name="event" value="' . Security::e($event) . '">'
        . '<button class="btn ' . ($marked ? 'ghost' : 'ok') . '" type="submit"'
        . ' style="padding:5px 9px;font-size:12px"'
        . ' title="' . ($marked ? 'Marked ' . Security::e($done[$event]) : 'Notifies every confirmed passenger') . '"'
        . ' onclick="return confirm(' . htmlspecialchars(json_encode($confirm) ?: '""', ENT_QUOTES) . ')">'
        . $icon . ' ' . Security::e($label) . ($marked ? ' ✓' : '')
        . '</button></form>';
}

admin_header('Trips Board', 'trips');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}
?>
<div class="cards">
  <div class="card"><div class="k"><?= Security::e($views[$viewKey]) ?> trips</div><div class="v"><?= count($schedules) ?></div></div>
  <div class="card"><div class="k">Within 24h</div><div class="v"><?= $within24 ?></div></div>
  <div class="card"><div class="k">Within 3h</div><div class="v"><?= $within3 ?></div></div>
  <div class="card"><div class="k">Seats sold (listed)</div><div class="v"><?= $soldUpcoming ?></div></div>
</div>

<div class="toolbar">
  <?php foreach ($views as $vk => $vlabel): ?>
    <a class="btn <?= $vk === $viewKey ? '' : 'ghost' ?>" href="?view=<?= Security::e($vk) ?>"><?= Security::e($vlabel) ?></a>
  <?php endforeach; ?>
</div>

<?php
/* ---- Fleet moved to admin/buses.php ---------------------------------
   The add/edit/retire/delete-a-coach panel used to live here alongside
   the schedule board, which meant two panels in two files touched the
   same buses table. Fleet configuration is now owned by admin/buses.php
   — this page keeps only what belongs to *driving* the schedule (per-bus
   rename+renumber for a rotation slot is still inline below). */
?>
<div class="panel" style="padding:14px 18px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
  <div style="flex:1;min-width:220px">
    <div style="font-weight:700">🚌 Fleet</div>
    <div class="muted" style="font-size:12.5px;margin-top:2px">
      Fleet is now managed on <a href="<?= $base ?>/admin/buses.php">Buses</a>.
    </div>
  </div>
  <a class="btn" href="<?= $base ?>/admin/buses.php">Open Buses →</a>
</div>

<?php
/* ---- Bus rotation ("the bus recycles every 3rd day") ----------------
   A coach that leaves today reaches the border tomorrow and is only back
   for the next outbound run on the third day, so a DAILY service is a
   small fleet taking turns. Slot 1,2,3… is the order they take. */
$rot     = Fleet::rotation();
$allBus  = Fleet::allBuses();
$slotOf  = array_flip($rot['buses']);   // busId => index (0-based)
$preview = Fleet::upcoming(7);
$csrfT   = Security::e(Security::csrfToken());
$kT      = CSRF_TOKEN_NAME;
?>
<?php if ($canManage): ?>
<div class="panel">
  <h2>🔄 Bus rotation <span class="muted" style="font-weight:400">· the fleet takes turns, so a bus recycles on its own</span></h2>
  <form method="post" style="padding:14px 18px">
    <input type="hidden" name="<?= $kT ?>" value="<?= $csrfT ?>">
    <input type="hidden" name="action" value="rotation">

    <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:end;margin-bottom:14px">
      <label style="display:flex;align-items:center;gap:8px;font-weight:600">
        <input type="checkbox" name="rotation_enabled" value="1" <?= $rot['enabled'] ? 'checked' : '' ?>>
        Rotation on
      </label>
      <label style="font-size:12px;color:var(--mut)">Cycle length (days)
        <input type="number" name="cycle_days" min="1" max="<?= Fleet::MAX_CYCLE_DAYS ?>" value="<?= (int) $rot['cycleDays'] ?>"
               style="width:90px;display:block;margin-top:4px;padding:8px 10px;border:1px solid var(--line);border-radius:8px"></label>
      <label style="font-size:12px;color:var(--mut)">Cycle starts
        <input type="date" name="start_date" value="<?= Security::e($rot['startDate']) ?>"
               style="display:block;margin-top:4px;padding:8px 10px;border:1px solid var(--line);border-radius:8px"></label>
      <button class="btn" type="submit">Save rotation</button>
    </div>

    <p class="muted" style="font-size:12.5px;margin:0 0 10px">
      Put <b>1, 2, 3…</b> against the buses that take turns — that order IS the rotation.
      Leave a bus blank to keep it out. Three buses = a bus is back on the third day.
      Changing a bus <b>number</b> below keeps its slot, so you can swap the actual coach any day.
    </p>

    <table>
      <thead><tr><th style="width:90px">Slot</th><th>Bus name</th><th>Bus number</th><th>Type</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($allBus as $b): $bid = (int) $b['id']; ?>
        <tr<?= (int) $b['is_active'] === 0 ? ' style="opacity:.55"' : '' ?>>
          <td>
            <input type="number" name="slot[<?= $bid ?>]" min="1" max="99"
                   value="<?= isset($slotOf[$bid]) ? (int) $slotOf[$bid] + 1 : '' ?>" placeholder="—"
                   style="width:70px;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
          </td>
          <td colspan="3" style="padding:0">
            <!-- Renaming/renumbering is its own form so it saves without
                 touching the rotation slots the operator may be mid-edit on. -->
            <div style="display:flex;gap:8px;align-items:center;padding:6px 8px">
              <input form="busf<?= $bid ?>" type="text" name="bus_name" value="<?= Security::e((string) $b['bus_name']) ?>" maxlength="120"
                     style="flex:1;min-width:150px;padding:7px 9px;border:1px solid var(--line);border-radius:8px">
              <input form="busf<?= $bid ?>" type="text" name="bus_number" value="<?= Security::e((string) $b['bus_number']) ?>" maxlength="30"
                     style="width:150px;padding:7px 9px;border:1px solid var(--line);border-radius:8px;font-family:var(--f-code,monospace)">
              <span class="muted" style="font-size:12px;width:70px"><?= Security::e((string) $b['coach_type']) ?></span>
              <button form="busf<?= $bid ?>" class="btn ghost" type="submit">💾</button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if ($allBus === []): ?>
        <tr><td colspan="5" class="muted" style="padding:18px;text-align:center">No buses yet — add them in the <b>Fleet</b> panel above.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </form>

  <?php foreach ($allBus as $b): ?>
    <form id="busf<?= (int) $b['id'] ?>" method="post" style="display:none">
      <input type="hidden" name="<?= $kT ?>" value="<?= $csrfT ?>">
      <input type="hidden" name="action" value="bus">
      <input type="hidden" name="bus_id" value="<?= (int) $b['id'] ?>">
    </form>
  <?php endforeach; ?>

  <?php if ($preview !== []): ?>
    <div style="padding:0 18px 16px">
      <div class="muted" style="font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">
        Next 7 days — who runs when
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php foreach ($preview as $p): ?>
          <div style="border:1px solid var(--line);border-radius:10px;padding:8px 12px;min-width:130px">
            <div style="font-size:11.5px;color:var(--mut)"><?= Security::e(formatDate($p['date'])) ?></div>
            <div style="font-weight:700;font-size:12.5px"><?= Security::e($p['busName']) ?></div>
            <div class="mono muted" style="font-size:11px"><?= Security::e($p['busNumber']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="muted" style="font-size:12px;margin:10px 0 0">
        Applies to trips created from now on. A date whose trip already exists keeps the bus it was given —
        change that one from the departures list below.
      </p>
    </div>
  <?php endif; ?>
</div>
<?php endif; /* $canManage — rotation / bus-details panel */ ?>

<div class="panel">
  <h2><?= Security::e($views[$viewKey]) ?> departures <span class="muted" style="font-weight:400">· auto-refreshing</span></h2>
  <table>
    <thead><tr><th>Departs</th><th>Route</th><th>Bus</th><th>Driver</th><th>Sold / Total</th><th>Who's selling</th><th>Journey</th><th></th></tr></thead>
    <tbody>
    <?php if ($schedules === []): ?>
      <tr><td colspan="8" class="muted" style="padding:26px;text-align:center">Nothing here. A trip appears once it has a booking or a seat hold.</td></tr>
    <?php else: foreach ($schedules as $s):
      $sid   = (int) $s['schedule_id'];
      $h     = hoursUntil((string) $s['travel_date'], (string) $s['dep_time']);
      /* Prefer the unified TripStatus pill so Sold Out / Boarding / Delayed
         all show the same colour across the admin surface. Fall back to
         the old hour-band heuristic only when annotate() left no state
         (e.g. a row with no dep_time). */
      $st = $s['_status'] ?? null;
      if ($st !== null && !empty($st['state'])) {
          $bg    = $st['color'];
          $fg    = '#fff';
          $label = $st['label'];
          $cls   = 'state-' . $st['state'];
      } else {
          [$cls, $fg, $bg, $label] = trips_urgency($h);
      }
      $ch    = $channels[$sid] ?? ['Online' => 0, 'Agent' => 0, 'Counter' => 0, 'Admin' => 0, 'sold' => 0];
      $total = (int) $s['total_seats'];
      $done  = $statuses[$sid] ?? [];
    ?>
      <tr data-sid="<?= $sid ?>">
        <td>
          <strong><?= Security::e(formatDate($s['travel_date'])) ?></strong>
          <div class="muted"><?= Security::e(formatTime($s['dep_time'])) ?> ·
            <span class="pill urg" data-sid="<?= $sid ?>" style="background:<?= $bg ?>;color:<?= $fg ?>"><?= $label ?></span>
          </div>
        </td>
        <td>
          <?= Security::e($s['from_city'] . ' → ' . $s['to_city']) ?>
          <div class="muted mono"><?= Security::e($s['route_code']) ?> · <?= Security::e(ucfirst((string) $s['coach_type'])) ?>
          <?php $retSid = $returnPairs[$sid] ?? null; if ($retSid !== null): ?>
            · <a href="<?= $base ?>/admin/seatmap.php?schedule=<?= $retSid ?>" style="font-size:11px;color:#00897b" title="Return trip on this or next day">↩ Return</a>
          <?php endif; ?>
          </div>
        </td>
        <td><?= Security::e($s['bus_name']) ?><div class="muted mono"><?= Security::e($s['bus_number']) ?></div></td>
        <td>
          <?php $curDrv = (int) ($s['driver_id'] ?? 0); ?>
          <?php if ($canManage): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="assign_driver">
            <input type="hidden" name="schedule_id" value="<?= $sid ?>">
            <select name="driver_id" onchange="this.form.submit()" style="max-width:150px;font-size:12px;padding:4px 6px;border:1px solid var(--line);border-radius:6px;background:var(--card);color:var(--ink)">
              <option value="0">— No driver —</option>
              <?php foreach ($activeDrivers as $d): ?>
                <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $curDrv ? 'selected' : '' ?>>
                  <?= Security::e($d['full_name']) ?> (<?= Security::e(str_replace('-', '·', (string) $d['role'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </form>
          <?php if (!empty($s['driver_phone'])): ?>
            <div class="muted mono" style="font-size:11px;margin-top:2px"><?= Security::e((string) $s['driver_phone']) ?></div>
          <?php endif; ?>
          <?php else: ?>
            <?= Security::e((string) ($s['driver_name'] ?? '—')) ?>
            <?php if (!empty($s['driver_phone'])): ?><div class="muted mono" style="font-size:11px"><?= Security::e((string) $s['driver_phone']) ?></div><?php endif; ?>
          <?php endif; ?>
        </td>
        <td>
          <strong class="sold" data-sid="<?= $sid ?>"><?= $ch['sold'] ?></strong><span class="muted"> / <?= $total ?></span>
          <?php
            // % fill heat-bar: green while there is room, amber as it fills,
            // red once nearly full — so an operator can scan the board at a glance.
            $pct  = $total > 0 ? (int) round($ch['sold'] * 100 / $total) : 0;
            $barC = $pct >= 90 ? '#b02a2a' : ($pct >= 60 ? '#d68910' : '#0a6b3b');
          ?>
          <div style="margin-top:5px;height:6px;border-radius:99px;background:var(--line);overflow:hidden" title="<?= $pct ?>% full">
            <div style="width:<?= $pct ?>%;height:100%;background:<?= $barC ?>"></div>
          </div>
          <div class="muted" style="font-size:11px;margin-top:2px"><?= $pct ?>% full</div>
        </td>
        <td style="font-size:12px">
          <?php $any = false; foreach (['Online', 'Agent', 'Counter', 'Admin'] as $bk): if ($ch[$bk] > 0): $any = true; ?>
            <span class="pill" style="background:var(--head);color:var(--ink);margin:1px"><?= $bk ?> <?= $ch[$bk] ?></span>
          <?php endif; endforeach; if (!$any): ?><span class="muted">—</span><?php endif; ?>
        </td>
        <td>
          <?php if ((string) $s['status'] === 'cancelled'): ?>
            <span class="pill" style="background:#f7dcdc;color:#8a1f1f">Cancelled</span>
          <?php else: ?>
            <div class="row-actions" style="gap:5px">
              <?= trip_milestone_btn($sid, 'departed', '🚌', 'Started', $done, $canDrive, $csrf, $k) ?>
              <?= trip_milestone_btn($sid, 'border',   '🛂', 'Border',  $done, $canDrive, $csrf, $k) ?>
              <?= trip_milestone_btn($sid, 'arrived',  '✅', 'Arrived', $done, $canDrive, $csrf, $k) ?>
            </div>
            <?php if ($done === [] && !$canDrive): ?><span class="muted">—</span><?php endif; ?>
            <?php
              $curDelay = (int) ($s['delay_minutes'] ?? 0);
              $curNote  = (string) ($s['delay_note'] ?? '');
              if ($canManage && !isset($done['departed'])):
            ?>
            <form method="post" style="display:flex;gap:4px;align-items:center;margin-top:6px;flex-wrap:wrap">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="set_delay">
              <input type="hidden" name="schedule_id" value="<?= $sid ?>">
              <input type="number" name="delay_minutes" min="0" max="600" value="<?= $curDelay ?>" placeholder="min" title="Delay in minutes" style="width:60px;padding:4px 6px;font-size:12px;border:1px solid var(--line);border-radius:6px">
              <input type="text" name="delay_note" value="<?= Security::e($curNote) ?>" placeholder="reason" maxlength="255" style="width:90px;padding:4px 6px;font-size:12px;border:1px solid var(--line);border-radius:6px">
              <button class="btn ghost" type="submit" style="padding:4px 8px;font-size:11px" title="Set delay & notify passengers">⏳</button>
            </form>
            <?php elseif ($curDelay > 0): ?>
              <div class="muted" style="font-size:11px;margin-top:4px;color:#c0392b">⏳ <?= $curDelay ?>m delay<?= $curNote !== '' ? ' · ' . Security::e($curNote) : '' ?></div>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td><div class="row-actions"><a class="btn ghost" href="<?= $base ?>/admin/seatmap.php?route=<?= (int) $s['route_id'] ?>&date=<?= Security::e($s['travel_date']) ?>" style="padding:6px 10px">Seat map →</a>
          <?php if (Auth::bookingScopeAdminId() === null): ?><a class="btn ghost" href="<?= $base ?>/admin/chalan.php?sid=<?= (int) $s['id'] ?>" style="padding:6px 10px" title="Bus chalan — preview, PDF / PNG, WhatsApp">📋 Chalan</a><?php endif; ?></div></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
<p class="muted">
  Urgency: <span class="pill" style="background:#ffe6b0;color:#7a5200">within 24h</span> then
  <span class="pill" style="background:#b02a2a;color:#fff">within 3h</span>.
  Live counts refresh automatically so two people never re-sell the same trip.
  <?php if ($canDrive): ?>
    <br><strong>Journey buttons</strong> message every confirmed passenger on that coach once — pressing one twice never sends a duplicate.
    The 12-hour and 2-hour reminders go out on their own via <span class="mono">cron/reminders.php</span>.
  <?php endif; ?>
</p>

<script>
(function () {
  function paint(trips) {
    trips.forEach(function (t) {
      var sold = document.querySelector('.sold[data-sid="' + t.id + '"]');
      if (sold) { sold.textContent = t.sold; }
      var urg = document.querySelector('.urg[data-sid="' + t.id + '"]');
      if (urg) {
        var bg, fg, label;
        // Prefer server-derived unified state (Sold Out / Boarding / …)
        // so the pill matches whatever admin/trips.php rendered the first
        // time. Fall back to the old hour-band heuristic only when the
        // JSON row hasn't been annotated (older servers, edge cases).
        if (t.state && t.stateColor && t.stateLabel) {
          bg = t.stateColor; fg = '#fff'; label = t.stateLabel;
        } else {
          var h = t.hours;
          if (h < 0)       { bg = '#eee';     fg = '#777';    label = 'Departed'; }
          else if (h < 3)  { bg = '#b02a2a';  fg = '#fff';    label = 'in ' + h.toFixed(1) + 'h'; }
          else if (h < 24) { bg = '#ffe6b0';  fg = '#7a5200'; label = 'in ' + Math.round(h) + 'h'; }
          else             { bg = '#d7f4e3';  fg = '#0a6b3b'; label = 'in ' + Math.round(h / 24) + 'd'; }
        }
        urg.style.background = bg; urg.style.color = fg; urg.textContent = label;
      }
    });
  }
  function poll() {
    fetch('trips-data.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { if (j && j.trips) { paint(j.trips); } })
      .catch(function () {});
  }
  setInterval(poll, 15000);
})();
</script>
<?php
admin_footer();
