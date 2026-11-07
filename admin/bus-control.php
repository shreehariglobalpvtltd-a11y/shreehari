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
$back = static function (array $f, string $date, string $anchor = ''): never {
    $_SESSION['bc_flash'] = $f;
    Response::redirect('admin/bus-control.php?date=' . rawurlencode($date) . ($anchor !== '' ? '#' . $anchor : ''));
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
$moneyLine = static function (array $m): string {
    $p = (float) ($m['paid'] ?? 0); $a = (float) ($m['agent'] ?? 0); $d = (float) ($m['due'] ?? 0);
    return '<span class="m-paid">✅ ' . inr($p) . '</span><span class="m-agent">🟠 ' . inr($a) . '</span><span class="m-due">🔴 ' . inr($d) . '</span>';
};
$seatPic = static function (array $dep, array $info) use ($e, $extraN): string {
    $coach  = (string) ($dep['coach_type'] ?? 'sleeper');
    $layout = Seats::layoutFor($coach, 'sharing');
    $beds   = (array) ($info['beds'] ?? []);
    $cell = static function (string $bed) use ($beds, $coach, $e): string {
        $lbl = Seats::displayLabel($bed, $coach, 'sharing');
        $i   = $beds[$bed] ?? null;
        if ($i === null) {
            return '<i class="sp sp-free" title="' . $e($lbl) . ' · खाली">' . $e($lbl) . '</i>';
        }
        return '<i class="sp sp-' . $e($i['state']) . ($i['woman'] ? ' sp-w' : '') . '" title="' . $e($lbl . ' · ' . $i['name'] . ' · ' . $i['pnr'] . ($i['agent'] !== '' ? ' · ' . $i['agent'] : '')) . '">' . $e($lbl) . '</i>';
    };
    $h = '<div class="spic">';
    foreach ((array) ($layout['decks'] ?? []) as $dk) {
        $h .= '<div class="sdeck"><b>' . ($dk['key'] === 'U' ? '🔼' : '🔽') . ' ' . $e($dk['label']) . '</b>';
        foreach ((array) $dk['rows'] as $rw) {
            [$g1, $g2] = Seats::rowGroups($rw, $layout);
            $h .= '<div class="srow"><span class="sg">';
            foreach ($g1[0] as $bed) { $h .= $cell((string) $bed); }
            $h .= '</span><span class="saisle"></span><span class="sg">';
            foreach ($g2[0] as $bed) { $h .= $cell((string) $bed); }
            $h .= '</span></div>';
        }
        $h .= '</div>';
    }
    /* extra seats: the sold ones always, the free ones while extra seats are on */
    $ex = [];
    foreach ($beds as $bed => $i) { if (Seats::isExtraSeat((string) $bed)) { $ex[(string) $bed] = true; } }
    for ($k = 1; $k <= $extraN; $k++) { $ex['EX' . $k] = true; }
    if ($ex !== []) {
        uksort($ex, static fn($a, $b): int => (int) substr((string) $a, 2) <=> (int) substr((string) $b, 2));
        $h .= '<div class="sdeck sdeck-ex"><b>💺 EXTRA · थप सिट</b><div class="srow exrow">';
        foreach (array_keys($ex) as $bed) { $h .= $cell((string) $bed); }
        $h .= '</div></div>';
    }
    return $h . '</div>';
};

admin_header('🚌 बस · सिट · चलानी — एकै ठाउँ · Bus Control', 'bus-control');
?>
<style>
.bc{display:grid;gap:14px;max-width:1240px}
.bc .card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px 16px}
.bc h2{margin:0 0 8px;font-size:17px}
.bc-days{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.bc-days a{display:inline-flex;flex-direction:column;align-items:center;min-width:62px;padding:6px 10px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink);text-decoration:none;font-weight:700;font-size:13px;line-height:1.2}
.bc-days a small{font-weight:600;color:var(--mut);font-size:11px}
.bc-days a.on{background:var(--navy);border-color:var(--navy);color:#fff}.bc-days a.on small{color:#cfe0ff}
.bc-days input{min-height:44px;border-radius:10px;border:1px solid var(--line);padding:0 10px;background:var(--card);color:var(--ink)}
.bc-ai{display:flex;gap:8px;flex-wrap:wrap}
.bc-ai input{flex:1 1 280px;min-height:50px;font-size:16px;padding:8px 14px;border-radius:12px;border:1.5px solid #F0B44C;background:var(--bg);color:var(--ink)}
.bc-ai .btn{min-height:50px;font-weight:800}
.bc-ex{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}.bc-ex button{border:1px dashed var(--line);background:transparent;color:inherit;border-radius:99px;padding:4px 10px;font-size:12px;cursor:pointer}
.trip{border:1px solid var(--line);border-radius:18px;background:var(--card);padding:14px 16px;display:grid;gap:10px}
.trip.off{opacity:.78;border-style:dashed}
.trip-h{display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;justify-content:space-between}
.trip-h h3{margin:0;font-size:18px}
.trip-h .tag{font-size:12px;font-weight:800;border-radius:99px;padding:3px 10px}
.tag.on{background:#DCFCE7;color:#14532D}.tag.offt{background:#FEE2E2;color:#7F1D1D}.tag.cx{background:#E5E7EB;color:#374151}
.trip-k{display:flex;flex-wrap:wrap;gap:8px}
.trip-k span{background:var(--bg);border:1px solid var(--line);border-radius:10px;padding:6px 10px;font-size:13px;font-weight:700}
.m-paid{color:#15803D}.m-agent{color:#B45309}.m-due{color:#B91C1C}
.trip-k .money span{border:0;background:none;padding:0 6px 0 0}
.trip-a{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.trip-a form{display:inline-flex;gap:4px;align-items:center;margin:0}
.trip-a input,.trip-a select{min-height:40px;border-radius:9px;border:1px solid var(--line);padding:0 8px;background:var(--bg);color:var(--ink);max-width:150px}
.trip-a .btn{min-height:40px;padding:0 12px}
.spic{display:flex;flex-wrap:wrap;gap:10px}
.sdeck{border:1px solid var(--line);border-radius:12px;padding:6px 8px;background:var(--bg)}
.sdeck>b{display:block;font-size:12px;margin-bottom:4px}
.srow{display:flex;gap:6px;margin:3px 0}.sg{display:inline-flex;gap:3px}.saisle{width:10px}
.exrow{flex-wrap:wrap;max-width:420px}
.sp{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:26px;padding:0 3px;border-radius:6px;font-style:normal;font-size:10.5px;font-weight:800;border:1.5px solid #CBD5E1;background:#fff;color:#334155}
.sp-paid{background:#16A34A;border-color:#15803D;color:#fff}
.sp-agent{background:#F59E0B;border-color:#B45309;color:#1F1300}
.sp-due{background:#DC2626;border-color:#991B1B;color:#fff}
.sp-w{box-shadow:inset 0 -3px 0 #F9A8D4}
.leg{display:flex;flex-wrap:wrap;gap:10px;font-size:12px;font-weight:700;color:var(--mut)}
.leg i{display:inline-block;width:14px;height:12px;border-radius:3px;vertical-align:-2px;margin-right:4px}
.bc table{width:100%;border-collapse:collapse;font-size:14px}
.bc td,.bc th{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}
.bc td form{display:flex;gap:6px;flex-wrap:wrap;margin:0}
.bc td input{min-height:40px;border-radius:9px;border:1px solid var(--line);padding:0 8px;background:var(--bg);color:var(--ink)}
.pill{font-size:12px;font-weight:900;border-radius:99px;padding:3px 10px;border:0;cursor:pointer}
.pill.on{background:#16A34A;color:#fff}.pill.off{background:#9CA3AF;color:#fff}
.bc details>summary{cursor:pointer;font-weight:800;font-size:16px;list-style:none}
.bc details>summary::-webkit-details-marker{display:none}
.bc .links{display:flex;flex-wrap:wrap;gap:6px}.bc .links a{border:1px solid var(--line);border-radius:10px;padding:8px 12px;text-decoration:none;color:var(--ink);font-weight:700;font-size:13px;background:var(--card)}
.bc .linkbox{display:flex;gap:6px;flex-wrap:wrap}.bc .linkbox input{flex:1 1 300px;min-height:44px;border-radius:10px;border:1px solid var(--line);padding:0 10px;background:var(--bg);color:var(--ink);font-size:13px}
@media(max-width:640px){.trip-h h3{font-size:16px}.sp{min-width:26px;height:24px;font-size:9.5px}.bc-days a{min-width:54px}}
</style>
<div class="bc">
<?php if ($flash !== null): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

  <div class="card">
    <div class="bc-days">
      <?php for ($i = -1; $i <= 7; $i++):
          $d = date('Y-m-d', strtotime($today . ' ' . ($i >= 0 ? '+' : '') . $i . ' days')); ?>
        <a href="?date=<?= $e($d) ?>" class="<?= $d === $date ? 'on' : '' ?>"><?= $e(formatDate($d, 'j M')) ?><small><?= $i === 0 ? 'आज' : ($i === 1 ? 'भोलि' : ($i === -1 ? 'हिजो' : $e(formatDate($d, 'D')))) ?></small></a>
      <?php endfor; ?>
      <form method="get" style="margin:0"><input type="date" name="date" value="<?= $e($date) ?>" onchange="this.form.submit()" aria-label="Date"></form>
    </div>
  </div>

  <div class="card">
    <h2>🤖 S Hari AI — भन्नुहोस्, AI ले बदल्छ · Tell the AI</h2>
    <form method="post" action="/admin/easy-settings.php" class="bc-ai">
      <?= Security::csrfField() ?><input type="hidden" name="act" value="cmd"><input type="hidden" name="back" value="bus-control.php?date=<?= $e($date) ?>">
      <input id="bcCmd" name="cmd" placeholder="जस्तै: <?= $e(formatDate(date('Y-m-d', strtotime($date . ' +1 day')), 'j M')) ?> ko bhada 2500 · Bus 02 ko number GJ05AB1234 · Rajkot route band · 5 extra seat" autocomplete="off" <?= $canSet || $canBus ? '' : 'disabled' ?>>
      <button class="btn ok" type="submit" <?= $canSet || $canBus ? '' : 'disabled' ?>>पठाउनुहोस् →</button>
    </form>
    <div class="bc-ex">
      <?php foreach (['Surat ko bhada 2300', formatDate(date('Y-m-d', strtotime($date . ' +1 day')), 'j M') . ' ko bus band', 'Bus 03 ko number GJ05AB1234', 'Rupaidiha Surat route on', '10 extra seat', 'cancel fine 500'] as $x): ?>
        <button type="button" onclick="var i=document.getElementById('bcCmd');i.value=this.textContent;i.focus()"><?= $e($x) ?></button>
      <?php endforeach; ?>
    </div>
    <p class="muted" style="font-size:12.5px;margin:6px 0 0">नेपाली · हिन्दी · English, जुन भाषामा पनि। सधैं पहिला के बदलिन्छ देखाउँछ, "हो" भनेपछि मात्र बदल्छ।</p>
  </div>

  <?php if ($deps === []): ?>
    <div class="card">यो दिन कुनै बस छैन · No departure on <?= $e(formatDate($date, 'j M Y')) ?>. <a href="/admin/trips.php?date=<?= $e($date) ?>">Trips Board →</a></div>
  <?php endif; ?>

  <?php foreach ($deps as $d):
      $sid   = (int) $d['sid'];
      $info  = $bySid[$sid] ?? [];
      $off   = (int) $d['is_blocked'] === 1;
      $cx    = (string) $d['status'] === 'cancelled';
      $seats = (int) ($info['seats'] ?? 0);
      $exS   = (int) ($info['extra'] ?? 0);
      $total = (int) ($d['total_seats'] ?: ($d['bus_seats'] ?: 72));
      $plate = Fleet::publicNumber((string) $d['bus_number']);
      $q = http_build_query(['date' => $date, 'sid' => $sid, 'route' => (int) $d['route_id']]); ?>
    <section class="trip<?= $off || $cx ? ' off' : '' ?>" id="trip<?= $sid ?>">
      <div class="trip-h">
        <h3><?= $e($d['from_city']) ?> → <?= $e($d['to_city']) ?> · <?= $e(substr((string) $d['dep_time'], 0, 5)) ?><?= (int) $d['slot'] > 1 ? ' · Bus ' . (int) $d['slot'] : '' ?></h3>
        <span class="tag <?= $cx ? 'cx' : ($off ? 'offt' : 'on') ?>"><?= $cx ? '✖ Cancelled' : ($off ? '⛔ बिक्री बन्द · Sale OFF' : '🟢 बिक्री खुला · Sale ON') ?></span>
      </div>
      <div class="trip-k">
        <span>🚌 <?= $e($d['bus_name'] ?: '—') ?> · <?= $plate !== '' ? $e($plate) : '<em style="color:#B45309">number बाँकी</em>' ?></span>
        <span>💺 <?= $seats - $exS ?>/<?= $total ?> बिक्री · <?= max(0, $total - ($seats - $exS)) ?> खाली</span>
        <span>➕ Extra <?= $exS ?><?= $extraN > 0 ? '/' . $extraN : '' ?></span>
        <span class="money"><?= $moneyLine((array) ($info['money'] ?? [])) ?></span>
        <?php if ($d['fare_override'] !== null): ?><span>💰 यो दिन <?= inr((float) $d['fare_override']) ?></span><?php endif; ?>
        <?php if (!empty($d['driver_name'])): ?><span>👤 <?= $e($d['driver_name']) ?> <?= $e($d['driver_phone'] ?? '') ?></span><?php endif; ?>
      </div>
      <?= $seatPic($d, $info) ?>
      <div class="leg"><span><i style="background:#16A34A"></i>भुक्तानी भयो · Paid</span><span><i style="background:#F59E0B"></i>एजेन्टसँग · With agent</span><span><i style="background:#DC2626"></i>बाँकी · Due</span><span><i style="background:#fff;border:1px solid #CBD5E1"></i>खाली · Free</span><span><i style="background:#fff;box-shadow:inset 0 -3px 0 #F9A8D4;border:1px solid #CBD5E1"></i>महिला · Woman</span></div>
      <div class="trip-a">
        <a class="btn" href="/admin/manifest.php?<?= $e($q) ?>">🧾 चलानी · Chalani</a>
        <a class="btn ghost" href="/admin/manifest.php?<?= $e($q . '&format=chalani') ?>" target="_blank">🖨 Print</a>
        <a class="btn ghost" href="/admin/manifest.php?<?= $e($q . '&format=chalanipdf') ?>" target="_blank">📄 PDF</a>
        <a class="btn ghost" href="/admin/manifest.php?<?= $e($q . '&format=chalanipng') ?>" target="_blank">🖼 Photo</a>
        <a class="btn ghost" href="/admin/seatmap.php?<?= $e(http_build_query(['date' => $date, 'route' => (int) $d['route_id']])) ?>">💺 Seat map</a>
        <?php if ($canSale && !$cx): ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="act" value="trip_sale"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="schedule_id" value="<?= $sid ?>"><input type="hidden" name="on" value="<?= $off ? '1' : '0' ?>">
            <button class="btn <?= $off ? 'ok' : 'bad' ?>" type="submit" onclick="return confirm('<?= $off ? 'यो दिनको बिक्री खोल्ने?' : 'यो दिनको बिक्री बन्द गर्ने? (पुराना टिकट जस्ताको तस्तै)' ?>')"><?= $off ? '🟢 Sale ON' : '⛔ Sale OFF' ?></button></form>
        <?php endif; ?>
        <?php if ($canBus): ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="act" value="trip_fare"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="schedule_id" value="<?= $sid ?>">
            <input name="fare" inputmode="numeric" placeholder="यो दिनको भाडा ₹" value="<?= $d['fare_override'] !== null ? (int) $d['fare_override'] : '' ?>" aria-label="Fare for this date"><button class="btn ghost" type="submit">💰 Save</button></form>
        <?php endif; ?>
        <?php if ($canSale): ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="act" value="trip_bus"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="schedule_id" value="<?= $sid ?>">
            <select name="bus_id" aria-label="Bus for this date"><?php foreach ($buses as $b): if ((int) $b['is_active'] !== 1) { continue; } ?><option value="<?= (int) $b['id'] ?>" <?= (int) $b['id'] === (int) $d['bus_id'] ? 'selected' : '' ?>><?= $e($b['bus_name'] . ' · ' . $b['bus_number']) ?></option><?php endforeach; ?></select>
            <button class="btn ghost" type="submit">🚌 Set</button></form>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <div class="card" id="link">
    <h2>🔗 Chalani permanent link — login बिना, live · for agents</h2>
    <?php if (OpsHub::publicOn()): $pl = OpsHub::publicUrl(); ?>
      <div class="linkbox"><input id="pubLink" readonly value="<?= $e($pl) ?>" onclick="this.select()">
        <button class="btn" type="button" onclick="var i=document.getElementById('pubLink');i.select();try{navigator.clipboard.writeText(i.value)}catch(e){document.execCommand('copy')}this.textContent='✅ Copied'">📋 Copy</button>
        <a class="btn ok" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode('🚌 ' . Settings::getString('company_name', APP_NAME) . ' — live chalani (seat · passenger · paid/due): ' . $pl) ?>">💬 WhatsApp</a>
        <a class="btn ghost" target="_blank" href="<?= $e($pl) ?>">👁 Open</a></div>
      <p class="muted" style="font-size:12.5px">जहिले पनि एउटै link — admin ले बदलेको तुरुन्त देखिन्छ (३० सेकेन्डमा आफैं refresh)। Mobile number लुकेको (98•••••123)।</p>
    <?php else: ?>
      <p>🔒 Public link बन्द छ · OFF.</p>
    <?php endif; ?>
    <?php if ($canSet): ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <form method="post"><?= Security::csrfField() ?><input type="hidden" name="act" value="link_toggle"><input type="hidden" name="date" value="<?= $e($date) ?>"><button class="btn ghost" type="submit"><?= OpsHub::publicOn() ? '🔒 Link OFF' : '🔗 Link ON' ?></button></form>
        <form method="post" onsubmit="return confirm('नयाँ link बनाउने? पुरानो link काम गर्न छोड्छ।')"><?= Security::csrfField() ?><input type="hidden" name="act" value="link_new"><input type="hidden" name="date" value="<?= $e($date) ?>"><button class="btn ghost" type="submit">🔑 New link</button></form>
      </div>
    <?php endif; ?>
  </div>

  <details class="card" id="fleet" open>
    <summary>🚍 बसहरू · Fleet (number / name यहीँ बदल्नुहोस्)</summary>
    <table><thead><tr><th>Bus</th><th>Seats</th><th>Number · Name</th></tr></thead><tbody>
    <?php foreach ($buses as $b): ?>
      <tr<?= (int) $b['is_active'] !== 1 ? ' style="opacity:.55"' : '' ?>>
        <td><b><?= $e($b['bus_name']) ?></b><br><small><?= (int) $b['is_active'] === 1 ? '🟢 active' : '⛔ off' ?></small></td>
        <td><?= (int) $b['total_seats'] ?> · <?= $e($b['seat_model']) ?></td>
        <td><?php if ($canBus): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="act" value="bus_save"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="bus_id" value="<?= (int) $b['id'] ?>">
          <input name="bus_number" value="<?= $e($b['bus_number']) ?>" maxlength="30" style="text-transform:uppercase;width:150px" aria-label="Bus number">
          <input name="bus_name" value="<?= $e($b['bus_name']) ?>" maxlength="120" style="width:170px" aria-label="Bus name">
          <button class="btn ghost" type="submit">💾</button></form>
          <?php else: ?><?= $e($b['bus_number']) ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <p class="muted" style="font-size:12.5px">XXXX = number अझै राखिएको छैन (customer लाई लुकेको)। नयाँ बस / layout: <a href="/admin/buses.php">Bus Fleet →</a></p>
  </details>

  <details class="card" id="routes" open>
    <summary>🛣 रुट · Routes (on / off एक ट्याप)</summary>
    <table><thead><tr><th>Route</th><th>Time</th><th>Status</th></tr></thead><tbody>
    <?php $offShown = 0; foreach ($routes as $r): $on = (int) $r['is_active'] === 1; if (!$on && ++$offShown > 8) { continue; } ?>
      <tr<?= $on ? '' : ' style="opacity:.6"' ?>>
        <td><b><?= $e($r['from_city']) ?> → <?= $e($r['to_city']) ?></b> <small>(<?= $e($r['route_code']) ?>)</small></td>
        <td><?= $e(substr((string) $r['dep_time'], 0, 5)) ?></td>
        <td><?php if ($canRoute): ?><form method="post" onsubmit="return confirm('<?= $on ? 'यो रुट बन्द गर्ने? नयाँ बिक्री रोकिन्छ, पुराना टिकट जस्ताको तस्तै।' : 'यो रुट खोल्ने? तुरुन्त बिक्री हुन्छ।' ?>')"><?= Security::csrfField() ?><input type="hidden" name="act" value="route_toggle"><input type="hidden" name="date" value="<?= $e($date) ?>"><input type="hidden" name="route_id" value="<?= (int) $r['id'] ?>">
          <button class="pill <?= $on ? 'on' : 'off' ?>" type="submit"><?= $on ? 'ON' : 'OFF' ?></button></form>
          <?php else: ?><?= $on ? 'ON' : 'OFF' ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
    <p class="muted" style="font-size:12.5px">Pickup stops, समय र भाडा: <a href="/admin/routes.php">Stops & Fares →</a> · 3-bus rotation: <a href="/admin/trips.php">Trips Board →</a></p>
  </details>

  <details class="card" id="extra">
    <summary>💺 Extra seats — हरेक बसमा <?= $extraN ?> (EX1–EX<?= max(1, $extraN) ?>)</summary>
    <p>Extra seat पनि normal seat जस्तै बिक्छ — टिकट, चलानी, seat chart, हिसाब, commission सबैमा आउँछ।</p>
    <?php if (Auth::isSuperadmin()): ?>
      <form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><?= Security::csrfField() ?><input type="hidden" name="act" value="extra_set"><input type="hidden" name="date" value="<?= $e($date) ?>">
        <label>हरेक बसमा <input type="number" name="n" min="0" max="40" value="<?= $extraN ?>" style="width:80px;min-height:40px"> extra seat</label>
        <button class="btn" type="submit">💾 Save</button></form>
    <?php endif; ?>
    <p class="muted" style="font-size:12.5px"><a href="/admin/extra-seats.php">Extra seats register (कसले बेच्यो, कति पैसा) →</a></p>
  </details>

  <div class="card">
    <h2>📚 अरू पेज · More pages</h2>
    <div class="links">
      <a href="/admin/trips.php">🕒 Trips Board</a><a href="/admin/trip-dashboard.php">📅 Daily Trip View</a><a href="/admin/calendar.php">🗓 Bus Calendar</a>
      <a href="/routine.php">🗓️ 3-Bus Routine</a><a href="/admin/seatmap.php">💺 Seat / Berth Map</a><a href="/admin/buses.php">🚍 Bus Fleet</a>
      <a href="/admin/routes.php">🛣 Stops & Fares</a><a href="/admin/extra-seats.php">➕ Extra Seats</a><a href="/admin/manifest.php">🧾 Manifest & Chalani</a>
    </div>
  </div>
</div>
<?php admin_footer();
