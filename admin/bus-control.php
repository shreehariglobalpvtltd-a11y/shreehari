<?php
/**
 * admin/bus-control.php — 🚌 Bus · Seat · Chalani, one place (6 Nov 2026).
 *
 * Owner: "bus fleet ma seat haru dekhinchha, chalani, bus management — tei
 * rakhdine sapai eutai setting vitra; merge garera quick, fast, responsive."
 *
 * For one travel date, every departure on ONE card:
 *   - the bus (number / name — edit in place; tickets redraw by themselves),
 *   - sale ON / OFF for that date, this trip's own fare,
 *   - the seat picture coloured by MONEY: paid green · with the agent amber ·
 *     due red · free white (the same rule as the chalani, OpsHub::payState),
 *     extra seats (EX) as their own row,
 *   - seats sold / free / extra, ₹ paid / with agents / due,
 *   - the chalani (screen, print, PDF, picture) and the public live link.
 * Below: the fleet, the routes (on / off), extra seats per bus, the public
 * chalani link — and the S Hari AI box ("9 Nov ko bhada 2500", "Bus 02 ko
 * number GJ05AB1234", "Rajkot route band"), which shows the change first.
 *
 * The deep pages stay (Trips Board, Calendar, Routine, Seat map, Routes &
 * stops, Extra seats register) — linked at the bottom.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('schedules.view');
$e = static fn($v): string => Security::e((string) ($v ?? ''));

$canBus   = Auth::isSuperadmin() || Auth::can('routes.edit') || Auth::can('schedules.manage');
$canSale  = Auth::isSuperadmin() || Auth::can('schedules.manage');
$canRoute = Auth::isSuperadmin() || Auth::can('routes.edit');
$canSet   = Auth::canManageSettings();

$today = todayISO();
$date  = (string) ($_GET['date'] ?? $_POST['date'] ?? $today);
if (!Security::isValidDate($date)) {
    $date = $today;
}
$flash = $_SESSION['bc_flash'] ?? null;
unset($_SESSION['bc_flash']);
/* 7 Nov 2026: one category per tab (departures · fleet · routes · extra seats · share link) */
$TABS = ['trips', 'fleet', 'routes', 'extra', 'link'];
$tab  = (string) ($_GET['tab'] ?? $_POST['tab'] ?? 'trips');
if (!in_array($tab, $TABS, true)) {
    $tab = 'trips';
}
$back = static function (array $f, string $date, string $anchor = ''): never {
    $_SESSION['bc_flash'] = $f;
    $t = in_array($anchor, ['fleet', 'routes', 'extra', 'link'], true) ? $anchor : 'trips';
    Response::redirect('admin/bus-control.php?date=' . rawurlencode($date) . '&tab=' . $t . (str_starts_with($anchor, 'trip') ? '#' . $anchor : ''));
};

/* ---------------------------------------------------------------- POST */
if (Security::isPost()) {
    $act = (string) ($_POST['act'] ?? '');
    if (!Security::verifyCsrf()) {
        $back(['bad', 'Page was open too long — try again.'], $date);
    }
    try {
        switch ($act) {
            case 'bus_save':
                if (!$canBus) { throw new RuntimeException('Bus edit is for the office (routes / schedules manager).'); }
                $bid = (int) ($_POST['bus_id'] ?? 0);
                $num = strtoupper(trim(Security::clean((string) ($_POST['bus_number'] ?? ''), 30)));
                $nam = trim(Security::clean((string) ($_POST['bus_name'] ?? ''), 120));
                if ($num !== '' && preg_match('/^[A-Z0-9][A-Z0-9 \-]{2,29}$/', $num) !== 1) {
                    throw new RuntimeException('Bus number: letters, digits, space or - only (e.g. GJ05AB1234).');
                }
                $old = Database::fetch('SELECT bus_name, bus_number FROM buses WHERE id = :i', ['i' => $bid]);
                if ($old === null) { throw new RuntimeException('That bus no longer exists.'); }
                Fleet::updateBus($bid, $nam !== '' ? $nam : (string) $old['bus_name'], $num !== '' ? $num : (string) $old['bus_number'], (int) $admin['id']);
                $back(['ok', '🚌 ' . ($nam !== '' ? $nam : $old['bus_name']) . ' → ' . ($num !== '' ? $num : $old['bus_number']) . ' · tickets of coming trips redrawn'], $date, 'fleet');

            /* 12 Nov 2026 (owner: "bus add garne, fleet, chalani, seat — sabai ekai thau ma") */
            case 'bus_add':
                if (!$canBus) { throw new RuntimeException('Adding a bus is for the office (routes / schedules manager).'); }
                $nam = trim(Security::clean((string) ($_POST['bus_name'] ?? ''), 120));
                $num = strtoupper(trim(Security::clean((string) ($_POST['bus_number'] ?? ''), 30)));
                if ($nam === '') { throw new RuntimeException('Bus को नाम लेख्नुहोस् · Type a name for the bus.'); }
                if ($num !== '' && preg_match('/^[A-Z0-9][A-Z0-9 \-]{2,29}$/', $num) !== 1) {
                    throw new RuntimeException('Bus number: letters, digits, space or - only (e.g. GJ05AB1234).');
                }
                $typ = (string) ($_POST['coach_type'] ?? 'sleeper') === 'seater' ? 'seater' : 'sleeper';
                Fleet::createBus($nam, $num !== '' ? $num : 'XXXX-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)), $typ, (int) ($_POST['total_seats'] ?? 40), '', (int) $admin['id']);
                $back(['ok', '🚌 ' . $nam . ' थपियो · added to the fleet'], $date, 'fleet');

            case 'bus_active':
                if (!$canBus) { throw new RuntimeException('Bus on / off is for the office (routes / schedules manager).'); }
                $bid = (int) ($_POST['bus_id'] ?? 0);
                $on  = (string) ($_POST['on'] ?? '') === '1';
                Fleet::setActive($bid, $on, (int) $admin['id']);
                $back(['ok', $on ? 'Bus on · चालु' : 'Bus off · बन्द (old tickets stay)'], $date, 'fleet');

            case 'trip_sale':
                if (!$canSale) { throw new RuntimeException('Only a schedules manager may switch a date on / off.'); }
                $sid = (int) ($_POST['schedule_id'] ?? 0);
                $on  = (string) ($_POST['on'] ?? '') === '1';
                $s   = Database::fetch('SELECT s.id, s.status, s.is_blocked, s.travel_date, r.route_code, r.from_city, r.to_city FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :i', ['i' => $sid]);
                if ($s === null) { throw new RuntimeException('Trip not found.'); }
                if ((string) $s['status'] === 'cancelled') { throw new RuntimeException('This trip is cancelled — open Trips Board to bring it back.'); }
                Database::update('schedules', ['is_blocked' => $on ? 0 : 1], 'id = :i', ['i' => $sid]);
                Logger::audit('schedule.sale_toggle', 'schedule', (string) $sid, ['is_blocked' => (int) $s['is_blocked']], ['is_blocked' => $on ? 0 : 1],
                    $s['route_code'] . ' ' . $s['travel_date'] . ' via Bus Control');
                $back(['ok', ($on ? '🟢 Sale ON · बिक्री खुला: ' : '⛔ Sale OFF · बिक्री बन्द: ') . $s['from_city'] . ' → ' . $s['to_city'] . ' · ' . formatDate((string) $s['travel_date'], 'j M')], $date, 'trip' . $sid);

            case 'trip_fare':
                if (!$canBus) { throw new RuntimeException('Fare edit is for the office.'); }
                $sid = (int) ($_POST['schedule_id'] ?? 0);
                $raw = trim((string) ($_POST['fare'] ?? ''));
                $fare = $raw === '' ? null : (float) preg_replace('/[^0-9.]/', '', $raw);
                if ($fare !== null && ($fare < 100 || $fare > 20000)) { throw new RuntimeException('Fare must be ₹100 – ₹20,000 (blank = normal fare).'); }
                $s = Database::fetch('SELECT id, fare_override, travel_date FROM schedules WHERE id = :i', ['i' => $sid]);
                if ($s === null) { throw new RuntimeException('Trip not found.'); }
                Database::update('schedules', ['fare_override' => $fare], 'id = :i', ['i' => $sid]);
                Logger::audit('schedule.fare_override', 'schedule', (string) $sid, ['fare_override' => $s['fare_override']], ['fare_override' => $fare], 'via Bus Control');
                $back(['ok', $fare === null ? '💰 This trip uses the normal pickup fares again.' : '💰 This trip: ' . inr($fare) . ' per seat (only this date).'], $date, 'trip' . $sid);

            case 'trip_bus':
                if (!$canSale) { throw new RuntimeException('Only a schedules manager may change the bus of a date.'); }
                $sid = (int) ($_POST['schedule_id'] ?? 0);
                $bid = (int) ($_POST['bus_id'] ?? 0);
                $s   = Database::fetch('SELECT id, bus_id FROM schedules WHERE id = :i', ['i' => $sid]);
                $bus = Database::fetch('SELECT id, bus_number, bus_name FROM buses WHERE id = :i AND is_active = 1', ['i' => $bid]);
                if ($s === null || $bus === null) { throw new RuntimeException('Choose an active bus.'); }
                Database::update('schedules', ['bus_id' => $bid], 'id = :i', ['i' => $sid]);
                Logger::audit('schedule.bus', 'schedule', (string) $sid, ['bus_id' => $s['bus_id']], ['bus_id' => $bid], 'via Bus Control');
                $back(['ok', '🚌 This date now runs ' . $bus['bus_name'] . ' (' . $bus['bus_number'] . ').'], $date, 'trip' . $sid);

            case 'route_toggle':
                if (!$canRoute) { throw new RuntimeException('Route on / off needs routes.edit.'); }
                $rid = (int) ($_POST['route_id'] ?? 0);
                $r   = Database::fetch('SELECT id, route_code, from_city, to_city, is_active FROM routes WHERE id = :i', ['i' => $rid]);
                if ($r === null) { throw new RuntimeException('Route not found.'); }
                $to = (int) $r['is_active'] === 1 ? 0 : 1;
                Database::update('routes', ['is_active' => $to], 'id = :i', ['i' => $rid]);
                Logger::audit('routes.toggled', 'routes', (string) $rid, ['is_active' => (int) $r['is_active']], ['is_active' => $to],
                    $r['route_code'] . ' ' . $r['from_city'] . ' → ' . $r['to_city'] . ' via Bus Control');
                $back(['ok', $r['from_city'] . ' → ' . $r['to_city'] . ($to === 1 ? ' 🟢 ON — bookable' : ' ⛔ OFF — hidden from sale; old bookings untouched')], $date, 'routes');

            case 'extra_set':
                if (!Auth::isSuperadmin()) { throw new RuntimeException('Only the super-admin changes extra seats.'); }
                $n = max(0, min(40, (int) ($_POST['n'] ?? 0)));
                $old = Seats::extraSeatCount();
                Settings::set('extra_seats_per_bus', (string) $n, 'int', 'booking');
                Logger::audit('settings.extra_seats', 'settings', 'extra_seats_per_bus', ['n' => $old], ['n' => $n], 'via Bus Control');
                $back(['ok', $n > 0 ? '💺 Every bus: ' . $n . ' extra seats (EX1–EX' . $n . ') — sold like normal seats, in chalani & accounts.' : '💺 Extra seats OFF (sold ones stay).'], $date, 'extra');

            case 'link_toggle':
                if (!$canSet) { throw new RuntimeException('Settings managers only.'); }
                $on = !OpsHub::publicOn();
                Settings::set('chalani_public_on', $on, 'bool', 'chalani');
                Logger::audit('chalani.public_toggle', 'settings', 'chalani_public_on', null, ['on' => $on], 'via Bus Control');
                $back(['ok', $on ? '🔗 Public chalani link ON' : '🔒 Public chalani link OFF — the old link shows nothing now'], $date, 'link');

            case 'link_new':
                if (!$canSet) { throw new RuntimeException('Settings managers only.'); }
                OpsHub::rotatePublicKey((int) $admin['id']);
                $back(['ok', '🔑 New public link made — the old link stops working. Share the new one.'], $date, 'link');

            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        $back(['bad', $ex->getMessage()], $date);
    }
}

/* ---------------------------------------------------------------- DATA */
$deps = Database::fetchAll(
    "SELECT s.id AS sid, s.travel_date, s.status, s.is_blocked, s.slot, s.fare_override, s.seats_booked, s.total_seats,
            s.bus_id AS sched_bus,
            r.id AS route_id, r.route_code, r.from_city, r.to_city,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
            COALESCE(s.coach_type_override, r.coach_type) AS coach_type,
            bu.id AS bus_id, bu.bus_number, bu.bus_name, bu.total_seats AS bus_seats,
            d.full_name AS driver_name, d.phone AS driver_phone
       FROM schedules s
       JOIN routes r      ON r.id = s.route_id
       LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id)
       LEFT JOIN drivers d ON d.id = s.driver_id
      WHERE s.travel_date = :d AND r.is_active = 1
      ORDER BY s.is_blocked ASC, COALESCE(s.dep_time_override, r.dep_time) ASC, s.slot ASC, r.id ASC",
    ['d' => $date]
);
$sids = array_map(static fn(array $d): int => (int) $d['sid'], $deps);

$seatRows = [];
if ($sids !== []) {
    $seatRows = Database::fetchAll(
        "SELECT bs.schedule_id, bs.seat_no, b.id AS booking_id, b.pnr, b.booking_mode, b.total_amount, b.status,
                bp.full_name, bp.gender,
                p.status AS pay_status, p.method AS pay_method,
                a.role AS agent_role, a.full_name AS agent_name,
                (SELECT COUNT(*) FROM booking_seats x WHERE x.booking_id = b.id AND x.released_at IS NULL) AS seats_in_booking
           FROM booking_seats bs
           JOIN bookings b ON b.id = bs.booking_id
           LEFT JOIN booking_passengers bp ON bp.booking_id = bs.booking_id AND bp.seat_no = bs.seat_no
           LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
           LEFT JOIN admins a ON a.id = b.sold_by_admin_id
          WHERE bs.schedule_id IN (" . implode(',', $sids) . ") AND bs.released_at IS NULL
            AND b.status IN ('confirmed','pending','completed')"
    );
}
$ticks = OpsHub::collections(array_map(static fn(array $r): int => (int) $r['booking_id'], $seatRows));

/* per departure: bed => state, money totals */
$bySid = [];
foreach ($seatRows as $r) {
    $sid   = (int) $r['schedule_id'];
    $state = OpsHub::payState($r, $ticks[(int) $r['booking_id']] ?? null);
    $share = round((float) $r['total_amount'] / max(1, (int) $r['seats_in_booking']), 2);
    $dep   = null;
    foreach ($deps as $d) { if ((int) $d['sid'] === $sid) { $dep = $d; break; } }
    $coach = (string) ($dep['coach_type'] ?? 'sleeper');
    try {
        $beds = Seats::physicalSeats((string) $r['seat_no'], (string) (($r['booking_mode'] ?? '') ?: 'sharing'), $coach);
    } catch (Throwable $ex) {
        $beds = [strtoupper((string) $r['seat_no'])];
    }
    foreach ($beds as $bed) {
        $bySid[$sid]['beds'][$bed] = ['state' => $state, 'name' => (string) $r['full_name'], 'pnr' => (string) $r['pnr'],
            'agent' => (string) ($r['agent_name'] ?? ''), 'woman' => in_array(strtolower((string) $r['gender']), ['female', 'f'], true)];
    }
    $bySid[$sid]['money'][$state] = ($bySid[$sid]['money'][$state] ?? 0) + $share;
    $bySid[$sid]['seats'] = ($bySid[$sid]['seats'] ?? 0) + 1;
    if (Seats::isExtraSeat((string) $r['seat_no'])) {
        $bySid[$sid]['extra'] = ($bySid[$sid]['extra'] ?? 0) + 1;
    }
}

$buses  = Database::fetchAll('SELECT id, bus_number, bus_name, total_seats, seat_model, is_active FROM buses ORDER BY is_active DESC, id');
$routes = Database::fetchAll('SELECT id, route_code, from_city, to_city, is_active, bus_id, dep_time FROM routes ORDER BY is_active DESC, sort_order, id');
$extraN = Seats::extraSeatCount();

/* ---------------------------------------------------------------- VIEW */
/* 7 Nov 2026 (owner: "professional look deu, cartoon jasto bhayo; category ma design"):
   one category per tab, quiet cards, small text badges, line icons, no emoji. */
$money = ['paid' => 0.0, 'agent' => 0.0, 'due' => 0.0];
$sold = 0; $cap = 0; $exSold = 0; $onSale = 0;
foreach ($deps as $d) {
    $i = $bySid[(int) $d['sid']] ?? [];
    foreach (['paid', 'agent', 'due'] as $k) { $money[$k] += (float) ($i['money'][$k] ?? 0); }
    $ex = (int) ($i['extra'] ?? 0);
    $sold += (int) ($i['seats'] ?? 0) - $ex;
    $exSold += $ex;
    $cap += (int) ($d['total_seats'] ?: ($d['bus_seats'] ?: 72));
    if ((int) $d['is_blocked'] !== 1 && (string) $d['status'] !== 'cancelled') { $onSale++; }
}
$occ = $cap > 0 ? (int) round($sold * 100 / $cap) : 0;

$seatPic = static function (array $dep, array $info) use ($e, $extraN): string {
    $coach  = (string) ($dep['coach_type'] ?? 'sleeper');
    $layout = Seats::layoutFor($coach, 'sharing');
    $beds   = (array) ($info['beds'] ?? []);
    $cell = static function (string $bed) use ($beds, $coach, $e): string {
        $lbl = Seats::displayLabel($bed, $coach, 'sharing');
        $i   = $beds[$bed] ?? null;
        if ($i === null) {
            return '<i class="pc-seat" title="' . $e($lbl) . ' · free">' . $e($lbl) . '</i>';
        }
        return '<i class="pc-seat ' . $e($i['state']) . ($i['woman'] ? ' w' : '') . '" title="' . $e($lbl . ' · ' . $i['name'] . ' · ' . $i['pnr'] . ($i['agent'] !== '' ? ' · ' . $i['agent'] : '')) . '">' . $e($lbl) . '</i>';
    };
    $h = '<div class="pc-seats">';
    foreach ((array) ($layout['decks'] ?? []) as $dk) {
        $h .= '<div class="pc-deck"><h4>' . $e($dk['label']) . '</h4>';
        foreach ((array) $dk['rows'] as $rw) {
            [$g1, $g2] = Seats::rowGroups($rw, $layout);
            $h .= '<div class="pc-srow"><span class="pc-sg">';
            foreach ($g1[0] as $bed) { $h .= $cell((string) $bed); }
            $h .= '</span><span class="pc-sg">';
            foreach ($g2[0] as $bed) { $h .= $cell((string) $bed); }
            $h .= '</span></div>';
        }
        $h .= '</div>';
    }
    $ex = [];
    foreach ($beds as $bed => $i) { if (Seats::isExtraSeat((string) $bed)) { $ex[(string) $bed] = true; } }
    for ($k = 1; $k <= $extraN; $k++) { $ex['EX' . $k] = true; }
    if ($ex !== []) {
        uksort($ex, static fn($a, $b): int => (int) substr((string) $a, 2) <=> (int) substr((string) $b, 2));
        $h .= '<div class="pc-deck"><h4>Extra seats</h4><div class="pc-srow" style="flex-wrap:wrap;max-width:430px;gap:4px">';
        foreach (array_keys($ex) as $bed) { $h .= $cell((string) $bed); }
        $h .= '</div></div>';
    }
    return $h . '</div>';
};
$legend = '<div class="pc-legend"><span><i style="background:#DCFCE7;border-color:#86EFAC"></i>Paid · भुक्तानी</span>'
    . '<span><i style="background:#FEF3C7;border-color:#FCD34D"></i>With agent · एजेन्टसँग</span>'
    . '<span><i style="background:#FEE2E2;border-color:#FCA5A5"></i>Due · बाँकी</span>'
    . '<span><i></i>Free · खाली</span><span><i style="background:#DB2777;border-radius:50%;width:7px;height:7px;border:0"></i>Woman</span></div>';

$prevD = date('Y-m-d', strtotime($date . ' -1 day'));
$nextD = date('Y-m-d', strtotime($date . ' +1 day'));
$keep  = ['date' => $date];

admin_header('Bus Control', 'bus-control');
?>
<div class="pc">
<?php if ($flash !== null): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e(admin_plain((string) $flash[1])) ?></div><?php endif; ?>

<?= pc_head('Bus Control · बस, सिट र चलानी', formatDate($date, 'l, j F Y') . ' — departures, seats, money and fleet in one place',
    '<a class="pc-btn" href="?' . $e(http_build_query(['date' => $prevD, 'tab' => $tab])) . '" aria-label="Previous day">' . pc_icon('left') . '</a>'
  . '<form method="get" class="pc-form"><input type="hidden" name="tab" value="' . $e($tab) . '"><input class="pc-in" type="date" name="date" value="' . $e($date) . '" onchange="this.form.submit()" aria-label="Date"></form>'
  . '<a class="pc-btn" href="?' . $e(http_build_query(['date' => $nextD, 'tab' => $tab])) . '" aria-label="Next day">' . pc_icon('right') . '</a>'
  . ($date !== $today ? '<a class="pc-btn" href="?' . $e(http_build_query(['date' => $today, 'tab' => $tab])) . '">Today</a>' : '')) ?>

<div class="pc-dates" aria-label="Choose a date">
  <?php for ($i = -1; $i <= 10; $i++): $d = date('Y-m-d', strtotime($today . ' ' . ($i >= 0 ? '+' : '') . $i . ' days')); ?>
    <a href="?<?= $e(http_build_query(['date' => $d, 'tab' => $tab])) ?>" class="<?= $d === $date ? 'on' : '' ?>"><small><?= $i === 0 ? 'Today' : ($i === 1 ? 'Tomorrow' : $e(formatDate($d, 'D'))) ?></small><?= $e(formatDate($d, 'j M')) ?></a>
  <?php endfor; ?>
</div>

<div class="pc-kpis">
  <div class="pc-kpi"><small>Departures</small><b><?= count($deps) ?></b><i><?= $onSale ?> on sale</i></div>
  <div class="pc-kpi"><small>Seats sold</small><b><?= $sold ?> / <?= $cap ?></b><i><?= $occ ?>% full<?= $exSold > 0 ? ' · +' . $exSold . ' extra' : '' ?></i></div>
  <div class="pc-kpi ok"><small>Paid</small><b><?= $e(inr($money['paid'])) ?></b><i>received by the office</i></div>
  <div class="pc-kpi warn"><small>With agents</small><b><?= $e(inr($money['agent'])) ?></b><i>cash still with agents</i></div>
  <div class="pc-kpi bad"><small>Due</small><b><?= $e(inr($money['due'])) ?></b><i>not paid yet</i></div>
</div>

<?php if ($canSet || $canBus): ?>
<form method="post" action="/admin/easy-settings.php" class="pc-card pc-card-b pc-ai">
  <?= Security::csrfField() ?><input type="hidden" name="act" value="cmd"><input type="hidden" name="back" value="bus-control.php?date=<?= $e($date) ?>">
  <span class="pc-meta"><span><?= pc_icon('spark') ?><b>S Hari AI</b></span></span>
  <input class="pc-in" id="bcCmd" name="cmd" placeholder="e.g. <?= $e(formatDate($nextD, 'j M')) ?> ko bhada 2500 · Bus 02 ko number GJ05AB1234 · Rajkot route band · 10 extra seat" autocomplete="off">
  <button class="pc-btn pri" type="submit">Preview change</button>
</form>
<?php endif; ?>

<?= pc_tabs([
    'trips'  => ['Departures', 'calendar', count($deps)],
    'fleet'  => ['Fleet', 'bus', count(array_filter($buses, static fn($b) => (int) $b['is_active'] === 1))],
    'routes' => ['Routes', 'route', count(array_filter($routes, static fn($r) => (int) $r['is_active'] === 1))],
    'extra'  => ['Extra seats', 'seat', $extraN],
    'link'   => ['Share link', 'link', null],
], $tab, $keep) ?>

<?php if ($tab === 'trips'): ?>
  <?php if ($deps === []): ?>
    <div class="pc-card pc-empty">No departure on <?= $e(formatDate($date, 'j M Y')) ?>. <a href="/admin/trips.php">Open Trips Board</a></div>
  <?php endif; ?>
  <?php foreach ($deps as $d):
      $sid   = (int) $d['sid'];
      $info  = $bySid[$sid] ?? [];
      $off   = (int) $d['is_blocked'] === 1;
      $cx    = (string) $d['status'] === 'cancelled';
      $ex    = (int) ($info['extra'] ?? 0);
      $seats = (int) ($info['seats'] ?? 0) - $ex;
      $total = (int) ($d['total_seats'] ?: ($d['bus_seats'] ?: 72));
      $plate = Fleet::publicNumber((string) $d['bus_number']);
      $m     = (array) ($info['money'] ?? []);
      $q     = http_build_query(['date' => $date, 'sid' => $sid, 'route' => (int) $d['route_id']]); ?>
    <section class="pc-card" id="trip<?= $sid ?>">
      <div class="pc-card-h">
        <div><h3><?= $e($d['from_city']) ?> → <?= $e($d['to_city']) ?></h3>
          <small>Departs <?= $e(date('g:i A', strtotime((string) $d['dep_time']))) ?><?= (int) $d['slot'] > 1 ? ' · extra bus ' . (int) $d['slot'] : '' ?> · <?= $e($d['route_code']) ?></small></div>
        <?= $cx ? pc_badge('Cancelled', 'mute') : ($off ? pc_badge('Sale off', 'bad') : pc_badge('On sale', 'ok')) ?>
      </div>
      <div class="pc-card-b" style="display:grid;gap:12px">
        <div class="pc-meta">
          <span><?= pc_icon('bus') ?><b><?= $e($d['bus_name'] ?: '—') ?></b> <?= $plate !== '' ? $e($plate) : pc_badge('number not set', 'warn') ?></span>
          <?php if (!empty($d['driver_name'])): ?><span><?= pc_icon('phone') ?><b><?= $e($d['driver_name']) ?></b> <?= $e($d['driver_phone'] ?? '') ?></span><?php endif; ?>
          <span><?= pc_icon('seat') ?>Sold <b><?= $seats ?>/<?= $total ?></b> · free <b><?= max(0, $total - $seats) ?></b><?= $ex > 0 || $extraN > 0 ? ' · extra <b>' . $ex . '</b>' : '' ?></span>
          <?php if ($d['fare_override'] !== null): ?><span><?= pc_icon('cash') ?>This date <b><?= $e(inr((float) $d['fare_override'])) ?></b></span><?php endif; ?>
        </div>
        <div class="pc-bar">
          <?= pc_badge('Paid ' . inr((float) ($m['paid'] ?? 0)), 'ok') ?>
          <?= pc_badge('With agents ' . inr((float) ($m['agent'] ?? 0)), 'warn') ?>
          <?= pc_badge('Due ' . inr((float) ($m['due'] ?? 0)), 'bad') ?>
        </div>
        <?= $seatPic($d, $info) ?>
        <?= $legend ?>
      </div>
      <div class="pc-card-f">
        <?php if (Auth::can('bookings.view')): ?><a class="pc-btn pri sm" href="/admin/quick-ticket.php"><?= pc_icon('plus') ?>टिकट काट्ने · Ticket</a><?php endif; ?>
        <a class="pc-btn pri sm" href="/admin/manifest.php?<?= $e($q) ?>"><?= pc_icon('list') ?>Chalani</a>
        <a class="pc-btn sm" href="/admin/manifest.php?<?= $e($q . '&format=chalani') ?>" target="_blank"><?= pc_icon('printer') ?>Print</a>
        <a class="pc-btn sm" href="/admin/manifest.php?<?= $e($q . '&format=chalanipdf') ?>" target="_blank"><?= pc_icon('download') ?>PDF</a>
        <a class="pc-btn sm" href="/admin/manifest.php?<?= $e($q . '&format=chalanipng') ?>" target="_blank"><?= pc_icon('image') ?>Image</a>
        <a class="pc-btn sm" href="/admin/seatmap.php?<?= $e(http_build_query(['date' => $date, 'route' => (int) $d['route_id']])) ?>"><?= pc_icon('seat') ?>Seat map</a>
        <span class="pc-sep"></span>
        <?php if ($canSale && !$cx): ?>
          <form method="post" class="pc-form" onsubmit="return confirm('<?= $off ? 'Open sale for this date?' : 'Stop sale for this date? Existing tickets stay as they are.' ?>')"><?= Security::csrfField() ?><input type="hidden" name="act" value="trip_sale"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="schedule_id" value="<?= $sid ?>"><input type="hidden" name="on" value="<?= $off ? '1' : '0' ?>">
            <button class="pc-switch<?= $off ? '' : ' on' ?>" type="submit" title="Sale on / off for this date"><i></i><?= $off ? 'Sale off' : 'Sale on' ?></button></form>
        <?php endif; ?>
        <?php if ($canBus): ?>
          <form method="post" class="pc-form"><?= Security::csrfField() ?><input type="hidden" name="act" value="trip_fare"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="schedule_id" value="<?= $sid ?>">
            <input class="pc-in" style="width:120px" name="fare" inputmode="numeric" placeholder="Fare this date" value="<?= $d['fare_override'] !== null ? (int) $d['fare_override'] : '' ?>" aria-label="Fare for this date"><button class="pc-btn sm" type="submit">Save</button></form>
        <?php endif; ?>
        <?php if ($canSale): ?>
          <form method="post" class="pc-form"><?= Security::csrfField() ?><input type="hidden" name="act" value="trip_bus"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="schedule_id" value="<?= $sid ?>">
            <select class="pc-sel" name="bus_id" aria-label="Bus for this date"><?php foreach ($buses as $b): if ((int) $b['is_active'] !== 1) { continue; } ?><option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) $d['bus_id'] ? 'selected' : '' ?>><?= $e($b['bus_name'] . ' · ' . $b['bus_number']) ?></option><?php endforeach; ?></select>
            <button class="pc-btn sm" type="submit">Set bus</button></form>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>

<?php elseif ($tab === 'fleet'): ?>
  <section class="pc-card">
    <div class="pc-card-h"><h3>Fleet · बसहरू</h3><small>Number and name change here; tickets of coming trips redraw by themselves. XXXX = number not entered yet (hidden from customers).</small></div>
    <div style="overflow-x:auto"><table class="pc-table">
      <thead><tr><th>Bus</th><th>Layout</th><th>Status</th><th>Number · Name</th></tr></thead><tbody>
      <?php foreach ($buses as $b): ?>
        <tr<?= (int) $b['is_active'] !== 1 ? ' style="opacity:.6"' : '' ?>>
          <td><b><?= $e($b['bus_name']) ?></b><br><small class="pc-note"><?= $e($b['bus_number']) ?></small></td>
          <td><?= (int) $b['total_seats'] ?> seats · <?= $e($b['seat_model']) ?></td>
          <td><?= (int) $b['is_active'] === 1 ? pc_badge('Active', 'ok') : pc_badge('Off', 'mute') ?>
            <?php if ($canBus): $bOn = (int) $b['is_active'] === 1; ?><form method="post" class="pc-form" style="display:inline" onsubmit="return confirm('<?= $bOn ? 'Switch this bus off? Old tickets stay.' : 'Switch this bus on?' ?>')"><?= Security::csrfField() ?><input type="hidden" name="act" value="bus_active"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="bus_id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="on" value="<?= $bOn ? '0' : '1' ?>"><button class="pc-btn sm" type="submit"><?= $bOn ? 'Off' : 'On' ?></button></form><?php endif; ?></td>
          <td><?php if ($canBus): ?><form method="post" class="pc-form"><?= Security::csrfField() ?><input type="hidden" name="act" value="bus_save"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="bus_id" value="<?= (int) $b['id'] ?>">
            <input class="pc-in" name="bus_number" value="<?= $e($b['bus_number']) ?>" maxlength="30" style="text-transform:uppercase;width:150px" aria-label="Bus number">
            <input class="pc-in" name="bus_name" value="<?= $e($b['bus_name']) ?>" maxlength="120" style="width:180px" aria-label="Bus name">
            <button class="pc-btn sm" type="submit"><?= pc_icon('check') ?>Save</button></form>
            <?php else: ?><?= $e($b['bus_number']) ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <?php if ($canBus): ?>
    <div class="pc-card-b" style="padding:12px 14px;border-top:1px solid var(--line)">
      <form method="post" class="pc-form" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><?= Security::csrfField() ?><input type="hidden" name="act" value="bus_add"><input type="hidden" name="date" value="<?= $e($date) ?>">
        <b style="font-size:13.5px">＋ नयाँ बस · Add a bus</b>
        <input class="pc-in" name="bus_name" required maxlength="120" placeholder="Name (e.g. SHG Karnali Sleeper)" style="width:220px" aria-label="New bus name">
        <input class="pc-in" name="bus_number" maxlength="30" placeholder="Number (later ok)" style="text-transform:uppercase;width:160px" aria-label="New bus number">
        <select class="pc-in" name="coach_type" aria-label="Coach type"><option value="sleeper">AC Sleeper · 72</option><option value="seater">Seater</option></select>
        <input class="pc-in" name="total_seats" type="number" min="1" max="120" value="40" style="width:76px" title="Seats (seater only — a sleeper is always 72)" aria-label="Seats for a seater">
        <button class="pc-btn pri sm" type="submit"><?= pc_icon('plus') ?>Add</button>
      </form>
    </div>
    <?php endif; ?>
    <div class="pc-card-f"><a class="pc-btn sm" href="/admin/buses.php"><?= pc_icon('plus') ?>Layout / more</a><a class="pc-btn sm" href="/admin/trips.php"><?= pc_icon('swap') ?>3-bus rotation</a><a class="pc-btn sm" href="/admin/calendar.php"><?= pc_icon('calendar') ?>Bus calendar</a></div>
  </section>

<?php elseif ($tab === 'routes'): ?>
  <section class="pc-card">
    <div class="pc-card-h"><h3>Routes · रुट</h3><small>Off = not sold; old tickets stay. Stops, times and pickup fares are on Stops &amp; Fares.</small></div>
    <div style="overflow-x:auto"><table class="pc-table">
      <thead><tr><th>Route</th><th>Code</th><th>Departs</th><th>Selling</th></tr></thead><tbody>
      <?php $offShown = 0; foreach ($routes as $r): $on = (int) $r['is_active'] === 1; if (!$on && ++$offShown > 8) { continue; } ?>
        <tr<?= $on ? '' : ' style="opacity:.65"' ?>>
          <td><b><?= $e($r['from_city']) ?> → <?= $e($r['to_city']) ?></b></td>
          <td><?= $e($r['route_code']) ?></td>
          <td><?= $e(date('g:i A', strtotime((string) $r['dep_time']))) ?></td>
          <td><?php if ($canRoute): ?><form method="post" class="pc-form" onsubmit="return confirm('<?= $on ? 'Stop selling this route? Old tickets stay.' : 'Start selling this route now?' ?>')"><?= Security::csrfField() ?><input type="hidden" name="act" value="route_toggle"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="route_id" value="<?= (int) $r['id'] ?>">
            <button class="pc-switch<?= $on ? ' on' : '' ?>" type="submit"><i></i><?= $on ? 'On' : 'Off' ?></button></form>
            <?php else: ?><?= $on ? pc_badge('On', 'ok') : pc_badge('Off', 'mute') ?><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
    <div class="pc-card-f"><a class="pc-btn sm" href="/admin/routes.php"><?= pc_icon('pin') ?>Stops &amp; fares</a></div>
  </section>

<?php elseif ($tab === 'extra'): ?>
  <section class="pc-card">
    <div class="pc-card-h"><h3>Extra seats · थप सिट</h3><small>Sold like normal seats — ticket, chalani, seat chart, accounts and commission.</small></div>
    <div class="pc-card-b" style="display:grid;gap:12px">
      <div class="pc-meta"><span>Every bus carries <b><?= $extraN ?></b> extra seats<?= $extraN > 0 ? ' (EX1–EX' . $extraN . ')' : '' ?>.</span><span>Sold on <?= $e(formatDate($date, 'j M')) ?>: <b><?= $exSold ?></b></span></div>
      <?php if (Auth::isSuperadmin()): ?>
        <form method="post" class="pc-form"><?= Security::csrfField() ?><input type="hidden" name="act" value="extra_set"><input type="hidden" name="date" value="<?= $e($date) ?>">
          <label class="pc-note" for="exN">Extra seats per bus</label><input class="pc-in" id="exN" type="number" name="n" min="0" max="40" value="<?= $extraN ?>" style="width:90px">
          <button class="pc-btn pri sm" type="submit">Save</button></form>
      <?php endif; ?>
    </div>
    <div class="pc-card-f"><a class="pc-btn sm" href="/admin/extra-seats.php"><?= pc_icon('list') ?>Extra seats register</a></div>
  </section>

<?php else: ?>
  <section class="pc-card">
    <div class="pc-card-h"><h3>Live chalani link · login बिना</h3><?= OpsHub::publicOn() ? pc_badge('Link on', 'ok') : pc_badge('Link off', 'mute') ?></div>
    <div class="pc-card-b" style="display:grid;gap:12px">
      <?php if (OpsHub::publicOn()): $pl = OpsHub::publicUrl(); ?>
        <div class="pc-bar"><input class="pc-in" id="pubLink" style="flex:1 1 360px" readonly value="<?= $e($pl) ?>" onclick="this.select()">
          <button class="pc-btn" type="button" onclick="var i=document.getElementById('pubLink');i.select();try{navigator.clipboard.writeText(i.value)}catch(e){document.execCommand('copy')}this.lastChild.textContent='Copied'"><?= pc_icon('link') ?><span>Copy</span></button>
          <a class="pc-btn ok" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode(Settings::getString('company_name', APP_NAME) . ' — live chalani (seats, passengers, paid/due): ' . $pl) ?>"><?= pc_icon('share') ?>WhatsApp</a>
          <a class="pc-btn" target="_blank" href="<?= $e($pl) ?>"><?= pc_icon('right') ?>Open</a></div>
        <p class="pc-note">One permanent link for agents. Changes made in the office show within 30 seconds. Mobile numbers are masked.</p>
      <?php else: ?>
        <p class="pc-note">The public link is switched off.</p>
      <?php endif; ?>
    </div>
    <?php if ($canSet): ?>
      <div class="pc-card-f">
        <form method="post" class="pc-form"><?= Security::csrfField() ?><input type="hidden" name="act" value="link_toggle"><input type="hidden" name="date" value="<?= $e($date) ?>"><button class="pc-switch<?= OpsHub::publicOn() ? ' on' : '' ?>" type="submit"><i></i><?= OpsHub::publicOn() ? 'Link on' : 'Link off' ?></button></form>
        <form method="post" class="pc-form" onsubmit="return confirm('Make a new link? The old link stops working.')"><?= Security::csrfField() ?><input type="hidden" name="act" value="link_new"><input type="hidden" name="date" value="<?= $e($date) ?>"><button class="pc-btn sm" type="submit"><?= pc_icon('refresh') ?>New link</button></form>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>
</div>
<?php admin_footer();
