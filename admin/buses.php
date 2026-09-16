<?php
/**
 * admin/buses.php — Bus Fleet Management
 *
 * Add, edit, activate/deactivate and inspect every bus in the fleet.
 * Default model: 72-seat double-decker (36 Lower + 36 Upper).
 * Future-proof: supports 60-seat, 45-seat, 50-seat and custom layouts.
 *
 * Booking creation from here goes to seatmap.php (existing system).
 * Fleet rotation lives in trips.php — this page is about the coaches
 * themselves, not which day they run.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin  = admin_boot('schedules.view');
$base   = '';
$flash  = null;
$canEdit = Auth::can('routes.edit') || Auth::isSuperadmin();

/* ── Seat model catalogue ─────────────────────────────────────────── */
const SEAT_MODELS = [
    '72-sleeper' => ['label' => '72 Seat – 2 Floor (Standard)', 'seats' => 72, 'floors' => 2, 'coach' => 'sleeper', 'badge' => '#178A50'],
    '60-sleeper' => ['label' => '60 Seat – 2 Floor',           'seats' => 60, 'floors' => 2, 'coach' => 'sleeper', 'badge' => '#2196F3'],
    '50-seater'  => ['label' => '50 Seat – Single Floor',       'seats' => 50, 'floors' => 1, 'coach' => 'seater',  'badge' => '#F07C1F'],
    '45-seater'  => ['label' => '45 Seat – Single Floor',       'seats' => 45, 'floors' => 1, 'coach' => 'seater',  'badge' => '#9C27B0'],
    'custom'     => ['label' => 'Custom',                        'seats' => 0,  'floors' => 1, 'coach' => 'seater',  'badge' => '#607D8B'],
];

/* ── POST actions ─────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canEdit) {
        $flash = ['bad', 'You do not have permission to manage buses.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        try {
            /* ── Daily service master switch (4 Sep 2026) ──
               One bus each way every day; this pauses / resumes the whole
               service. OFF: search shows "service paused" and every new
               booking (app, counter, agent) is refused until it is ON. */
            if ($act === 'daily_service') {
                $on   = ((string) ($_POST['on'] ?? '1')) === '1';
                $note = trim(Security::clean($_POST['note'] ?? '', 200));
                Settings::set('daily_service_on', $on ? '1' : '0', 'bool', 'booking', true);
                Settings::set('daily_service_note', $note, 'string', 'booking', true);
                Settings::flush();
                Logger::audit('service.daily_toggle', 'settings', 'daily_service_on', null, ['on' => $on, 'note' => $note], $on ? 'daily service ON' : 'daily service OFF');
                $flash = ['ok', $on
                    ? 'Daily service switched ON — customers can book again.'
                    : 'Daily service switched OFF — the app now shows "service paused" and no new booking is accepted until you switch it back on.'];
            }
            /* ── Create ── */
            if ($act === 'create') {
                $model    = (string) ($_POST['seat_model'] ?? '72-sleeper');
                $mDef     = SEAT_MODELS[$model] ?? SEAT_MODELS['72-sleeper'];
                $name     = trim(Security::clean($_POST['bus_name'] ?? '', 120));
                $number   = trim(Security::clean($_POST['bus_number'] ?? '', 30));
                $reg      = trim(Security::clean($_POST['registration'] ?? '', 60));
                $seats    = $model === 'custom' ? max(1, (int) ($_POST['total_seats'] ?? 72)) : $mDef['seats'];
                $floors   = $model === 'custom' ? max(1, (int) ($_POST['floors'] ?? 1)) : $mDef['floors'];
                $coach    = $mDef['coach'];

                if ($name === '') { throw new RuntimeException('Bus name is required.'); }
                if ($number === '') { throw new RuntimeException('Bus number/registration is required.'); }
                if (Database::fetch('SELECT id FROM buses WHERE bus_number=:n', ['n' => $number])) {
                    throw new RuntimeException('Bus number "' . $number . '" already exists.');
                }

                $newId = Database::insert('buses', [
                    'bus_name'    => $name,
                    'bus_number'  => $number,
                    'coach_type'  => $coach,
                    'total_seats' => $seats,
                    'seat_model'  => $model,
                    'floors'      => $floors,
                    'registration'=> $reg ?: null,
                    'is_active'   => 1,
                ]);
                Logger::audit('bus.create', 'admin', $number, null, ['model' => $model, 'seats' => $seats], 'by admin#' . $admin['id']);
                $flash = ['ok', 'Bus "' . $name . '" added — ' . $seats . ' seats, ' . $floors . ' floor(s).'];

            /* ── Update ── */
            } elseif ($act === 'update') {
                $busId  = (int) ($_POST['bus_id'] ?? 0);
                $bus    = Database::fetch('SELECT * FROM buses WHERE id=:id', ['id' => $busId]);
                if (!$bus) { throw new RuntimeException('Bus not found.'); }

                $model  = (string) ($_POST['seat_model'] ?? $bus['seat_model'] ?? '72-sleeper');
                $mDef   = SEAT_MODELS[$model] ?? SEAT_MODELS['72-sleeper'];
                $name   = trim(Security::clean($_POST['bus_name'] ?? '', 120));
                $number = trim(Security::clean($_POST['bus_number'] ?? '', 30));
                $reg    = trim(Security::clean($_POST['registration'] ?? '', 60));
                $ins    = Security::clean($_POST['insurance_exp'] ?? '', 10);
                $per    = Security::clean($_POST['permit_exp'] ?? '', 10);
                $fit    = Security::clean($_POST['fitness_exp'] ?? '', 10);
                $crew   = trim(Security::clean($_POST['crew_name'] ?? '', 120));
                $crewPh = trim(Security::clean($_POST['crew_phone'] ?? '', 20));
                $seats  = $model === 'custom' ? max(1, (int) ($_POST['total_seats'] ?? $bus['total_seats'])) : $mDef['seats'];
                $floors = $model === 'custom' ? max(1, (int) ($_POST['floors'] ?? $bus['floors'] ?? 2)) : $mDef['floors'];
                $coach  = $mDef['coach'];

                if ($name === '') { throw new RuntimeException('Bus name is required.'); }
                $dup = Database::fetch('SELECT id FROM buses WHERE bus_number=:n AND id!=:id', ['n' => $number, 'id' => $busId]);
                if ($dup) { throw new RuntimeException('Bus number "' . $number . '" already used by another bus.'); }

                $upd = [
                    'bus_name'    => $name,
                    'bus_number'  => $number !== '' ? $number : $bus['bus_number'],
                    'coach_type'  => $coach,
                    'total_seats' => $seats,
                    'seat_model'  => $model,
                    'floors'      => $floors,
                    'registration'=> $reg ?: null,
                    'insurance_exp'=> $ins !== '' ? $ins : null,
                    'permit_exp'  => $per !== '' ? $per : null,
                    'fitness_exp' => $fit !== '' ? $fit : null,
                ];
                Database::update('buses', $upd, 'id=:id', ['id' => $busId]);

                // Also update any linked routes/schedules crew info if provided
                if ($crew !== '' || $crewPh !== '') {
                    Database::run("UPDATE routes SET crew_name=:cn, crew_phone=:cp WHERE bus_id=:bid",
                        ['cn' => $crew ?: null, 'cp' => $crewPh ?: null, 'bid' => $busId]);
                }

                // Propagate new total_seats to upcoming schedules for this bus
                Database::run(
                    "UPDATE schedules SET total_seats=:ts WHERE bus_id=:bid AND travel_date >= CURDATE()",
                    ['ts' => $seats, 'bid' => $busId]
                );

                Logger::audit('bus.update', 'admin', $bus['bus_number'], null, $upd, 'by admin#' . $admin['id']);
                $flash = ['ok', 'Bus "' . $name . '" updated. Upcoming schedules adjusted to ' . $seats . ' seats.'];

            /* ── Activate / Deactivate ── */
            } elseif ($act === 'toggle') {
                $busId = (int) ($_POST['bus_id'] ?? 0);
                $bus   = Database::fetch('SELECT id, bus_name, is_active FROM buses WHERE id=:id', ['id' => $busId]);
                if (!$bus) { throw new RuntimeException('Bus not found.'); }
                $newActive = (int) $bus['is_active'] === 1 ? 0 : 1;
                // Plain activate / deactivate always clears any maintenance note —
                // maintenance is its own explicit action below.
                Database::update('buses', ['is_active' => $newActive, 'maintenance_note' => null], 'id=:id', ['id' => $busId]);
                Logger::audit('bus.toggle', 'admin', (string)$bus['bus_name'], null, ['active' => $newActive], '');
                $flash = ['ok', 'Bus "' . $bus['bus_name'] . '" ' . ($newActive ? 'activated' : 'deactivated') . '.'];

            /* ── Set Under Maintenance (Point 3) — takes the bus off the road
                  (is_active=0, so it stops selling immediately) but records WHY,
                  so the admin can tell "temporarily out" from "retired". ── */
            } elseif ($act === 'maintenance') {
                $busId = (int) ($_POST['bus_id'] ?? 0);
                $bus   = Database::fetch('SELECT id, bus_name FROM buses WHERE id=:id', ['id' => $busId]);
                if (!$bus) { throw new RuntimeException('Bus not found.'); }
                $note = Security::clean($_POST['maintenance_note'] ?? '', 140);
                if ($note === '') { $note = 'Under maintenance'; }
                Database::update('buses', ['is_active' => 0, 'maintenance_note' => $note], 'id=:id', ['id' => $busId]);
                Logger::audit('bus.maintenance', 'admin', (string)$bus['bus_name'], null, ['maintenance_note' => $note], '');
                $flash = ['ok', 'Bus "' . $bus['bus_name'] . '" marked Under Maintenance — it will not sell until reactivated.'];

            /* ── Delete ── */
            } elseif ($act === 'delete') {
                $busId = (int) ($_POST['bus_id'] ?? 0);
                $bus   = Database::fetch('SELECT id, bus_name, bus_number FROM buses WHERE id=:id', ['id' => $busId]);
                if (!$bus) { throw new RuntimeException('Bus not found.'); }
                // Safety check — don't delete if it has confirmed bookings
                $hasBkd = Database::scalar(
                    "SELECT COUNT(*) FROM bookings bk
                       JOIN booking_seats bs ON bs.booking_id=bk.id
                       JOIN schedules s ON s.id=bs.schedule_id
                      WHERE s.bus_id=:bid AND bk.status IN ('confirmed','pending') AND s.travel_date >= CURDATE()",
                    ['bid' => $busId], 0
                );
                if ((int) $hasBkd > 0) {
                    throw new RuntimeException('Cannot delete: this bus has ' . (int)$hasBkd . ' active/pending booking(s) in upcoming trips. Deactivate it instead.');
                }
                // Unlink from schedules/routes
                Database::run("UPDATE schedules SET bus_id=NULL WHERE bus_id=:bid", ['bid' => $busId]);
                Database::run("UPDATE routes SET bus_id=NULL WHERE bus_id=:bid", ['bid' => $busId]);
                Database::run("DELETE FROM buses WHERE id=:id", ['id' => $busId]);
                Logger::audit('bus.delete', 'admin', (string)$bus['bus_number'], null, null, 'by admin#' . $admin['id']);
                $flash = ['ok', 'Bus "' . $bus['bus_name'] . '" removed from fleet.'];
            }

        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ── Load buses ─────────────────────────────────────────────────── */
$buses = Database::fetchAll(
    "SELECT b.*,
            (SELECT COUNT(DISTINCT s.id) FROM schedules s WHERE s.bus_id=b.id AND s.travel_date >= CURDATE()) AS upcoming_trips,
            (SELECT COUNT(*) FROM booking_seats bs JOIN schedules s ON s.id=bs.schedule_id
              WHERE s.bus_id=b.id AND s.travel_date >= CURDATE() AND bs.released_at IS NULL) AS seats_booked_upcoming
       FROM buses b
      ORDER BY b.is_active DESC, b.id ASC"
);

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

admin_header('Bus Fleet', 'buses');
if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }
/* ── Daily service master switch (4 Sep 2026) ────────────────────────
   The owner runs ONE bus each way, every day. This card is the on/off for
   that service; per-DATE cancel / block stays on the Schedule page. */
require_once INCLUDE_PATH . '/fleet.php';
$svcOn      = Settings::getBool('daily_service_on', true);
$svcNote    = Settings::getString('daily_service_note', '');
$todayBusId = Fleet::busForDate(todayISO());
$todayBus   = $todayBusId ? Database::fetch('SELECT bus_name, bus_number FROM buses WHERE id = :i', ['i' => (int) $todayBusId]) : null;
if ($todayBus === null) {
    $todayBus = Database::fetch("SELECT b.bus_name, b.bus_number FROM routes r JOIN buses b ON b.id = r.bus_id WHERE r.is_active = 1 AND b.is_active = 1 ORDER BY r.id LIMIT 1");
}
?>
<div class="panel" id="dailyServicePanel" style="margin-bottom:16px;border-left:5px solid <?= $svcOn ? '#178A50' : '#C53030' ?>">
  <div style="display:flex;flex-wrap:wrap;align-items:center;gap:14px 22px;padding:16px 18px">
    <div style="flex:1;min-width:220px">
      <div style="font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--mut)">Daily service · दैनिक सेवा</div>
      <div style="font-size:20px;font-weight:800;margin-top:2px"><?= $svcOn ? '🟢 ON — selling' : '🔴 OFF — paused' ?></div>
      <div style="font-size:13px;color:var(--mut);margin-top:4px">One bus each way, every day.
        <?= $todayBus ? 'Today: <b>' . Security::e((string) $todayBus['bus_name']) . '</b> · ' . Security::e((string) $todayBus['bus_number']) : 'No bus assigned today' ?>
        · <a href="schedule.php">per-date cancel / block →</a>
        <?php if (!$svcOn && $svcNote !== ''): ?><br>Customer note: <i><?= Security::e($svcNote) ?></i><?php endif; ?>
      </div>
    </div>
    <?php if ($canEdit): ?>
    <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0">
      <input type="hidden" name="<?= Security::e(CSRF_TOKEN_NAME) ?>" value="<?= Security::e(Security::csrfToken()) ?>">
      <input type="hidden" name="action" value="daily_service">
      <input type="hidden" name="on" value="<?= $svcOn ? '0' : '1' ?>">
      <input type="text" name="note" value="<?= Security::e($svcNote) ?>" maxlength="200" placeholder="Message for customers while OFF (optional)" style="min-width:240px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink)">
      <button class="btn" type="submit" style="background:<?= $svcOn ? '#C53030' : '#178A50' ?>;color:#fff;min-height:44px;font-weight:800;border:0"><?= $svcOn ? '⏸ Switch OFF' : '▶ Switch ON' ?></button>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php
?>

<style>
/* ── Bus card grid ── */
.bus-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:18px;margin-bottom:32px}
.bus-card{background:var(--card);border:1px solid var(--line);border-radius:16px;overflow:hidden;
          box-shadow:0 2px 8px rgba(0,0,0,.06);transition:box-shadow .15s}
.bus-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.12)}
.bus-card.inactive{opacity:.65}
.bus-card-head{padding:16px 18px 12px;border-bottom:1px solid var(--line);display:flex;align-items:flex-start;gap:12px}
.bus-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;
          font-size:24px;flex:0 0 auto;background:#F0F7FF}
.bus-card-head-info{flex:1;min-width:0}
.bus-card-head-info h3{font-size:15px;font-weight:800;color:var(--fg);margin:0 0 2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bus-card-head-info .busnum{font-size:12px;color:var(--mut);font-family:ui-monospace,Consolas,monospace;font-weight:600}
.model-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700;color:#fff;margin-left:6px}
.status-dot{width:8px;height:8px;border-radius:50%;flex:0 0 auto;margin-top:4px}
.status-dot.on{background:#22c55e} .status-dot.off{background:#e74c3c} .status-dot.maint{background:#f39c12}

/* ── Floor display ── */
.bus-floors{padding:12px 18px;display:flex;gap:10px}
.floor-block{flex:1;background:var(--bg);border-radius:10px;padding:10px 12px;border:1px solid var(--line)}
.floor-block .floor-label{font-size:11px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px}
.floor-block .floor-name{font-size:13px;font-weight:700;color:var(--fg);margin-bottom:4px}
.seat-bar{height:8px;border-radius:4px;background:var(--line);overflow:hidden;margin-bottom:4px}
.seat-bar-fill{height:100%;background:#22c55e;border-radius:4px;transition:width .3s}
.seat-bar-fill.med{background:#F07C1F}.seat-bar-fill.high{background:#e74c3c}
.seat-nums{font-size:11px;color:var(--mut)}

/* ── Bus stats ── */
.bus-stats{padding:0 18px 12px;display:flex;gap:14px}
.bstat{flex:1;text-align:center}
.bstat-n{font-size:18px;font-weight:800;color:var(--fg)}
.bstat-l{font-size:11px;color:var(--mut);margin-top:1px}

/* ── Actions ── */
.bus-actions{padding:10px 18px 14px;display:flex;gap:6px;flex-wrap:wrap;border-top:1px solid var(--line)}

/* ── Edit modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px}
.modal-box{background:var(--card);border-radius:18px;max-width:600px;width:100%;max-height:90vh;overflow-y:auto;
           box-shadow:0 24px 60px rgba(0,0,0,.4)}
.modal-head{padding:20px 24px 14px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between}
.modal-head h2{margin:0;font-size:17px}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:var(--mut);padding:0}
.modal-body{padding:20px 24px}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.form-group label{display:block;font-size:12px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 12px;border:1.5px solid var(--line);
  border-radius:9px;font-size:14px;background:var(--bg);color:var(--fg);transition:border-color .15s}
.form-group input:focus,.form-group select:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(46,95,168,.12)}
.form-group-full{grid-column:1/-1}
.custom-only{display:none}
.form-section{margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}
.form-section h3{font-size:13px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.04em;margin:0 0 12px}

/* ── Seat mini-grid ── */
.seat-mini{display:flex;gap:2px;flex-wrap:wrap;margin-top:4px}
.seat-mini span{width:14px;height:14px;border-radius:3px;border:1.5px solid var(--line);font-size:7px;
                display:flex;align-items:center;justify-content:center;color:var(--mut)}
.seat-mini span.bkd{background:#e74c3c;border-color:#c0392b;color:#fff}
.seat-mini span.open{background:#22c55e;border-color:#16a34a;color:#fff}
.seat-mini span.held{background:#F07C1F;border-color:#c05e0e;color:#fff}

/* ── Add bus card ── */
.add-bus-card{background:var(--bg);border:2px dashed var(--line);border-radius:16px;
              display:flex;flex-direction:column;align-items:center;justify-content:center;
              min-height:200px;cursor:pointer;transition:border-color .15s,background .15s;gap:10px}
.add-bus-card:hover{border-color:var(--blue);background:var(--head)}
.add-bus-card .add-ico{font-size:36px}
.add-bus-card p{font-size:14px;font-weight:700;color:var(--blue);margin:0}

/* ── Advanced seat-model gate ──
   The page defaults to a 72-only picker. Anything tagged data-adv="1"
   (60-sleeper / 50-seater / 45-seater / custom, plus their reference
   cards) hides when its container carries the `hide-adv` class. Legacy
   bus rows still render correctly because they only reach display code,
   not the picker. */
.hide-adv [data-adv="1"]{display:none !important}
.adv-toggle{display:inline-flex;align-items:center;gap:6px;font-size:12px;
  color:var(--mut);cursor:pointer;user-select:none;padding:6px 10px;
  border:1px solid var(--line);border-radius:8px;background:var(--bg)}
.adv-toggle input{margin:0}
.legacy-badge{display:inline-block;background:#f59e0b;color:#fff;
  padding:2px 7px;border-radius:6px;font-size:10px;font-weight:700;
  margin-left:6px;text-transform:none;letter-spacing:0;vertical-align:middle}

@media(max-width:600px){.bus-grid{grid-template-columns:1fr}.bus-floors{flex-direction:column}}
</style>

<!-- ── Date-wise Bus Assignment Banner ──────────────────────────── -->
<div class="panel" style="margin-bottom:0">
  <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <h2 style="margin:0;flex:1">🚌 Bus Fleet <span class="badge" style="font-size:13px"><?= count($buses) ?> buses</span></h2>
    <form method="get" action="<?= $base ?>/admin/trip-dashboard.php"
          style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <label style="font-size:13px;font-weight:700;color:var(--mut);white-space:nowrap">📅 View date:</label>
      <input type="date" name="date" value="<?= Security::e(todayISO()) ?>"
             style="padding:8px 10px;border:1.5px solid var(--line);border-radius:9px;font-size:14px">
      <button type="submit" class="btn btn-blue btn-sm">See that day's runs</button>
    </form>
    <?php if ($canEdit): ?>
    <button class="btn btn-ok" onclick="openAddModal()">+ Add New Bus</button>
    <?php endif; ?>
    <label class="adv-toggle" title="Show 60-sleeper / 50-seater / 45-seater / custom models in the picker and reference panel">
      <input type="checkbox" id="advToggle" onchange="toggleAdvanced(this.checked)">
      🔧 Show advanced seat models
    </label>
  </div>
</div>

<!-- ── Bus Grid ─────────────────────────────────────────────────── -->
<div class="bus-grid" style="margin-top:18px">

<?php foreach ($buses as $bus):
  $model   = $bus['seat_model'] ?? '72-sleeper';
  $mDef    = SEAT_MODELS[$model] ?? SEAT_MODELS['72-sleeper'];
  $seats   = (int) ($bus['total_seats'] ?? 72);
  $floors  = (int) ($bus['floors'] ?? 2);
  $seatsPerFloor = $floors > 0 ? (int) ceil($seats / $floors) : $seats;
  $booked  = (int) ($bus['seats_booked_upcoming'] ?? 0);
  $pct     = $seats > 0 ? min(100, round($booked * 100 / $seats)) : 0;
  $fillCls = $pct >= 80 ? 'high' : ($pct >= 50 ? 'med' : '');
  $isActive = (int) $bus['is_active'] === 1;
  $maintNote = trim((string) ($bus['maintenance_note'] ?? ''));
  $isMaint   = !$isActive && $maintNote !== '';
  $statusLabel = $isActive ? 'Active' : ($isMaint ? 'Maintenance' : 'Inactive');
  $statusIcon  = $isActive ? '✅' : ($isMaint ? '🔧' : '❌');
?>
<div class="bus-card <?= $isActive ? '' : 'inactive' ?>">
  <div class="bus-card-head">
    <div class="bus-icon" style="background:<?= $mDef['badge'] ?>22">🚌</div>
    <div class="bus-card-head-info">
      <h3><?= Security::e($bus['bus_name']) ?>
        <span class="model-badge" style="background:<?= $mDef['badge'] ?>"><?= $seats ?> seats</span>
      </h3>
      <div class="busnum"><?= Security::e($bus['bus_number']) ?>
        <?php if ($bus['registration'] ?? ''): ?>
          · <span><?= Security::e($bus['registration']) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($isMaint): ?>
        <div style="margin-top:4px;font-size:12px;color:#B85A00;font-weight:600">🔧 <?= Security::e($maintNote) ?></div>
      <?php endif; ?>
    </div>
    <div class="status-dot <?= $isActive ? 'on' : ($isMaint ? 'maint' : 'off') ?>"></div>
  </div>

  <!-- Floor breakdown -->
  <div class="bus-floors">
    <?php if ($floors >= 2): ?>
      <?php
        // Count booked seats per floor from the DB (approximate split — real split from seatmap)
        $lowerBooked = 0; $upperBooked = 0;
        // Quick query for today's breakdown
        try {
          $todayFloor = Database::fetchAll(
            "SELECT bs.seat_no FROM booking_seats bs
               JOIN schedules s ON s.id=bs.schedule_id
              WHERE s.bus_id=:bid AND s.travel_date=CURDATE() AND bs.released_at IS NULL",
            ['bid' => $bus['id']]
          );
          foreach ($todayFloor as $sf) {
            $sno = (string)($sf['seat_no'] ?? '');
            if (str_starts_with($sno, 'L')) $lowerBooked++;
            elseif (str_starts_with($sno, 'U')) $upperBooked++;
          }
        } catch(Throwable $e) {}
        $lPct = $seatsPerFloor > 0 ? min(100, round($lowerBooked * 100 / $seatsPerFloor)) : 0;
        $uPct = $seatsPerFloor > 0 ? min(100, round($upperBooked * 100 / $seatsPerFloor)) : 0;
      ?>
      <div class="floor-block">
        <div class="floor-label">🪑 Lower Deck (L)</div>
        <div class="floor-name">L1 – L<?= $seatsPerFloor ?> &nbsp;<small style="color:var(--mut)"><?= $seatsPerFloor ?> berths</small></div>
        <div class="seat-bar"><div class="seat-bar-fill <?= $lPct>=80?'high':($lPct>=50?'med':'') ?>" style="width:<?= $lPct ?>%"></div></div>
        <div class="seat-nums"><?= $lowerBooked ?> booked today &nbsp;·&nbsp; <?= $seatsPerFloor - $lowerBooked ?> free</div>
      </div>
      <div class="floor-block">
        <div class="floor-label">🛏️ Upper Deck (U)</div>
        <div class="floor-name">U1 – U<?= $seatsPerFloor ?> &nbsp;<small style="color:var(--mut)"><?= $seatsPerFloor ?> berths</small></div>
        <div class="seat-bar"><div class="seat-bar-fill <?= $uPct>=80?'high':($uPct>=50?'med':'') ?>" style="width:<?= $uPct ?>%"></div></div>
        <div class="seat-nums"><?= $upperBooked ?> booked today &nbsp;·&nbsp; <?= $seatsPerFloor - $upperBooked ?> free</div>
      </div>
    <?php else: ?>
      <div class="floor-block" style="flex:1">
        <div class="floor-label">🪑 Single Floor</div>
        <div class="floor-name">Seats 1 – <?= $seats ?></div>
        <div class="seat-bar"><div class="seat-bar-fill <?= $fillCls ?>" style="width:<?= $pct ?>%"></div></div>
        <div class="seat-nums"><?= $booked ?> booked upcoming</div>
      </div>
    <?php endif; ?>
  </div>

  <!-- Stats row -->
  <div class="bus-stats">
    <div class="bstat"><div class="bstat-n"><?= $seats ?></div><div class="bstat-l">Total seats</div></div>
    <div class="bstat"><div class="bstat-n"><?= $floors ?></div><div class="bstat-l">Floor(s)</div></div>
    <div class="bstat"><div class="bstat-n"><?= (int)($bus['upcoming_trips'] ?? 0) ?></div><div class="bstat-l">Upcoming trips</div></div>
    <div class="bstat"><div class="bstat-n <?= $isActive?'':'muted' ?>" title="<?= $statusLabel ?>"><?= $statusIcon ?></div><div class="bstat-l"><?= $statusLabel ?></div></div>
  </div>

  <!-- Action buttons -->
  <div class="bus-actions">
    <!-- View seat map (today) -->
    <a href="<?= $base ?>/admin/seatmap.php?date=<?= urlencode(todayISO()) ?>"
       class="btn btn-blue btn-sm">🗺️ Seat Map</a>
    <!-- View in trip dashboard -->
    <a href="<?= $base ?>/admin/trip-dashboard.php?date=<?= urlencode(todayISO()) ?>"
       class="btn btn-ghost btn-sm">📅 Trip View</a>

    <?php if ($canEdit): ?>
      <!-- Edit -->
      <button class="btn btn-ghost btn-sm"
              onclick="openEditModal(<?= htmlspecialchars(json_encode([
                'id'           => $bus['id'],
                'bus_name'     => $bus['bus_name'],
                'bus_number'   => $bus['bus_number'],
                'seat_model'   => $bus['seat_model'] ?? '72-sleeper',
                'total_seats'  => $bus['total_seats'],
                'floors'       => $bus['floors'] ?? 2,
                'registration' => $bus['registration'] ?? '',
                'insurance_exp'=> $bus['insurance_exp'] ?? '',
                'permit_exp'   => $bus['permit_exp'] ?? '',
                'fitness_exp'  => $bus['fitness_exp'] ?? '',
              ]), ENT_QUOTES) ?>)">✏️ Edit</button>

      <!-- Toggle -->
      <form method="post" style="display:inline">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="bus_id" value="<?= (int)$bus['id'] ?>">
        <button type="submit" class="btn btn-warn btn-sm"
                onclick="return confirm('<?= $isActive ? 'Deactivate' : 'Activate' ?> this bus?')">
          <?= $isActive ? '⏸️ Deactivate' : '▶️ Activate' ?>
        </button>
      </form>

      <?php /* Maintenance (Point 3) — only offered for an active bus; a bus
               already off the road returns to service via Activate above. */ ?>
      <?php if ($isActive): ?>
      <form method="post" style="display:inline"
            onsubmit="var n=prompt('Maintenance reason (shown on the fleet card):','Under maintenance'); if(n===null){return false;} this.maintenance_note.value=n; return true;">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="maintenance">
        <input type="hidden" name="bus_id" value="<?= (int)$bus['id'] ?>">
        <input type="hidden" name="maintenance_note" value="">
        <button type="submit" class="btn btn-warn btn-sm">🔧 Maintenance</button>
      </form>
      <?php endif; ?>

      <!-- Delete -->
      <form method="post" style="display:inline">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="bus_id" value="<?= (int)$bus['id'] ?>">
        <button type="submit" class="btn btn-warn btn-sm"
                style="background:#C00;border-color:#C00"
                onclick="return confirm('Delete bus &quot;<?= addslashes(Security::e($bus['bus_name'])) ?>&quot;? This cannot be undone.')">
          🗑️ Delete
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if ($canEdit): ?>
<!-- Add bus card -->
<div class="add-bus-card" onclick="openAddModal()">
  <div class="add-ico">➕</div>
  <p>Add New Bus</p>
  <span style="font-size:12px;color:var(--mut)">Default: 72-seat double-decker</span>
</div>
<?php endif; ?>
</div>

<!-- ── Seat Model Reference ─────────────────────────────────────── -->
<div class="panel">
  <h2>📐 Seat Models — Layout Reference</h2>
  <div id="modelRefGrid" class="hide-adv" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-top:4px">
    <?php foreach (SEAT_MODELS as $key => $m): ?>
    <div<?= $key !== '72-sleeper' ? ' data-adv="1"' : '' ?> style="background:var(--bg);border-radius:10px;padding:14px;border:1.5px solid <?= $m['badge'] ?>44">
      <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:<?= $m['badge'] ?>"></span>
        <b style="font-size:13px"><?= Security::e($m['label']) ?></b>
      </div>
      <div style="font-size:12px;color:var(--mut)">
        <?php if ($m['seats'] > 0): ?>
          <b><?= $m['seats'] ?></b> seats &nbsp;·&nbsp; <b><?= $m['floors'] ?></b> floor(s)<br>
          <?php if ($m['floors'] >= 2): ?>
            Lower: <b>L1–L<?= intdiv($m['seats'],2) ?></b> &nbsp; Upper: <b>U1–U<?= intdiv($m['seats'],2) ?></b>
          <?php endif; ?>
        <?php else: ?>
          Custom — enter seats &amp; floors manually
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     ADD BUS MODAL
══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="addModal" style="display:none" onclick="if(event.target===this)closeModal('addModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2>➕ Add New Bus</h2>
      <button class="modal-close" onclick="closeModal('addModal')">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" id="addBusForm">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="create">
        <div class="form-grid">
          <div class="form-group form-group-full hide-adv" id="addModelWrap">
            <label>Seat Model *</label>
            <select name="seat_model" id="addModel" onchange="onModelChange('add')">
              <?php foreach (SEAT_MODELS as $key => $m): ?>
                <option value="<?= $key ?>" <?= $key==='72-sleeper'?'selected':'' ?><?= $key!=='72-sleeper'?' data-adv="1"':'' ?>>
                  <?= Security::e($m['label']) ?> <?= $m['seats']>0?'('.$m['seats'].' seats)':'' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Bus Name *</label>
            <input name="bus_name" required maxlength="120" placeholder="e.g. Shree Hari Express 1">
          </div>
          <div class="form-group">
            <label>Bus Number / ID *</label>
            <input name="bus_number" required maxlength="30" placeholder="e.g. GJ-02-T-4127">
          </div>
          <div class="form-group">
            <label>Registration No.</label>
            <input name="registration" maxlength="60" placeholder="RC / permit number">
          </div>
          <!-- Custom model fields -->
          <div class="form-group custom-only" id="addCustomSeats">
            <label>Total Seats *</label>
            <input type="number" name="total_seats" min="1" max="200" value="72">
          </div>
          <div class="form-group custom-only" id="addCustomFloors">
            <label>Number of Floors</label>
            <select name="floors">
              <option value="1">1 — Single Floor</option>
              <option value="2" selected>2 — Double-Decker</option>
            </select>
          </div>
        </div>
        <!-- Live preview -->
        <div class="form-section" id="addPreview">
          <h3>Layout Preview</h3>
          <div id="addPreviewContent" style="font-size:13px;color:var(--mut)"></div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="closeModal('addModal')">Cancel</button>
          <button type="submit" class="btn btn-ok">✅ Add Bus</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     EDIT BUS MODAL
══════════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal" style="display:none" onclick="if(event.target===this)closeModal('editModal')">
  <div class="modal-box">
    <div class="modal-head">
      <h2>✏️ Edit Bus</h2>
      <button class="modal-close" onclick="closeModal('editModal')">✕</button>
    </div>
    <div class="modal-body">
      <form method="post" id="editBusForm">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="bus_id" id="editBusId">
        <div class="form-grid">
          <div class="form-group form-group-full hide-adv" id="editModelWrap">
            <label>Seat Model
              <span id="editModelLegacyBadge" class="legacy-badge" hidden
                    title="Original model was not one of the supported presets — defaulted to 72-sleeper. Change if needed before saving.">legacy</span>
            </label>
            <select name="seat_model" id="editModel" onchange="onModelChange('edit')">
              <?php foreach (SEAT_MODELS as $key => $m): ?>
                <option value="<?= $key ?>"<?= $key!=='72-sleeper'?' data-adv="1"':'' ?>>
                  <?= Security::e($m['label']) ?> <?= $m['seats']>0?'('.$m['seats'].' seats)':'' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Bus Name *</label>
            <input name="bus_name" id="editBusName" required maxlength="120">
          </div>
          <div class="form-group">
            <label>Bus Number</label>
            <input name="bus_number" id="editBusNumber" maxlength="30">
          </div>
          <div class="form-group">
            <label>Registration No.</label>
            <input name="registration" id="editReg" maxlength="60">
          </div>
          <div class="form-group custom-only" id="editCustomSeats">
            <label>Total Seats</label>
            <input type="number" name="total_seats" id="editTotalSeats" min="1" max="200">
          </div>
          <div class="form-group custom-only" id="editCustomFloors">
            <label>Number of Floors</label>
            <select name="floors" id="editFloors">
              <option value="1">1 — Single Floor</option>
              <option value="2">2 — Double-Decker</option>
            </select>
          </div>
        </div>
        <div class="form-section">
          <h3>📋 Documents &amp; Compliance</h3>
          <div class="form-grid">
            <div class="form-group">
              <label>Insurance Expiry</label>
              <input type="date" name="insurance_exp" id="editIns">
            </div>
            <div class="form-group">
              <label>Permit Expiry</label>
              <input type="date" name="permit_exp" id="editPer">
            </div>
            <div class="form-group">
              <label>Fitness Expiry</label>
              <input type="date" name="fitness_exp" id="editFit">
            </div>
          </div>
        </div>
        <div class="form-section">
          <h3>👤 Crew (optional — updates linked routes)</h3>
          <div class="form-grid">
            <div class="form-group"><label>Driver / Crew Name</label><input name="crew_name" id="editCrew" maxlength="120"></div>
            <div class="form-group"><label>Crew Phone</label><input type="tel" name="crew_phone" id="editCrewPh" maxlength="20"></div>
          </div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px;justify-content:flex-end">
          <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
          <button type="submit" class="btn btn-ok">💾 Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const SEAT_MODELS = <?= json_encode(SEAT_MODELS) ?>;

/* ── Advanced seat-model toggle ──
   Owner runs only 72-seat buses today; the other 4 models stay in the
   catalogue for legacy rows but hide from the picker + reference panel
   until this checkbox flips. Persisted per browser so the choice sticks
   across navigation. */
var ADV_SCOPES = ['modelRefGrid','addModelWrap','editModelWrap'];
function toggleAdvanced(show) {
  try { localStorage.setItem('shg.buses.advanced', show ? '1' : '0'); } catch(_){}
  ADV_SCOPES.forEach(function(id){
    var el = document.getElementById(id);
    if (el) el.classList.toggle('hide-adv', !show);
  });
}
(function initAdv(){
  var show = false;
  try { show = localStorage.getItem('shg.buses.advanced') === '1'; } catch(_){}
  var cb = document.getElementById('advToggle');
  if (cb) cb.checked = show;
  toggleAdvanced(show);
})();

function openAddModal() {
  document.getElementById('addModal').style.display = 'flex';
  onModelChange('add');
}
function openEditModal(bus) {
  var m = document.getElementById('editModal');
  document.getElementById('editBusId').value     = bus.id;
  document.getElementById('editBusName').value   = bus.bus_name;
  document.getElementById('editBusNumber').value = bus.bus_number;
  document.getElementById('editReg').value       = bus.registration;
  document.getElementById('editIns').value       = bus.insurance_exp;
  document.getElementById('editPer').value       = bus.permit_exp;
  document.getElementById('editFit').value       = bus.fitness_exp;

  /* ── Legacy-model fallback ──
     If this bus's stored seat_model isn't one of the known presets
     (unknown/NULL/old label), default the picker to 72-sleeper and
     flag it visually so the operator knows before hitting Save. We do
     NOT auto-migrate — the DB row keeps its old value until Save. */
  var stored   = bus.seat_model || '';
  var isLegacy = !stored || !SEAT_MODELS[stored];
  var pickVal  = isLegacy ? '72-sleeper' : stored;
  document.getElementById('editModel').value     = pickVal;
  document.getElementById('editTotalSeats').value = bus.total_seats;
  var fl = document.getElementById('editFloors');
  fl.value = String(bus.floors || 2);

  var badge = document.getElementById('editModelLegacyBadge');
  if (badge) {
    if (isLegacy) {
      badge.textContent = stored ? ('legacy: ' + stored) : 'legacy';
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  /* If the bus is on a valid-but-advanced model (60/50/45/custom), the
     hidden option would render blank in the closed select — reveal the
     advanced options in this modal only so the current value stays
     visible. The reference panel and Add modal are unaffected. */
  var wrap = document.getElementById('editModelWrap');
  if (wrap) {
    var advSelected = !isLegacy && pickVal !== '72-sleeper';
    if (advSelected) wrap.classList.remove('hide-adv');
    else {
      var show = false;
      try { show = localStorage.getItem('shg.buses.advanced') === '1'; } catch(_){}
      wrap.classList.toggle('hide-adv', !show);
    }
  }

  m.style.display = 'flex';
  onModelChange('edit');
}
function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}
function onModelChange(prefix) {
  var sel   = document.getElementById(prefix + 'Model').value;
  var mDef  = SEAT_MODELS[sel];
  var isCustom = sel === 'custom';
  // Show/hide custom fields
  ['CustomSeats','CustomFloors'].forEach(function(s) {
    var el = document.getElementById(prefix + s);
    if (el) el.style.display = isCustom ? 'block' : 'none';
  });
  // Preview
  var pv = document.getElementById(prefix + 'Preview');
  var pc = document.getElementById(prefix + 'PreviewContent');
  if (!pv || !mDef) return;
  if (isCustom) {
    pc.innerHTML = 'Enter seats &amp; floors above.';
  } else {
    var fl    = mDef.floors;
    var seats = mDef.seats;
    var spf   = Math.ceil(seats / fl);
    var html  = '<b>' + mDef.label + '</b><br>';
    if (fl >= 2) {
      html += '🪑 Lower Deck: L1–L' + spf + ' (' + spf + ' berths)<br>';
      html += '🛏️ Upper Deck: U1–U' + spf + ' (' + spf + ' berths)<br>';
    } else {
      html += '🪑 ' + seats + ' seats — single floor';
    }
    pc.innerHTML = html;
  }
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeModal('addModal');
    closeModal('editModal');
  }
});
</script>

<?php admin_footer(); ?>
