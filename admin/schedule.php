<?php
/**
 * admin/schedule.php — Schedule Manager (Round 2)
 *
 * Per-date view of every scheduled trip with the day's operator actions
 * bundled in one screen:
 *   +  New Trip        (add_trip)         opens a modal, creates one schedule row
 *   ⏰ Edit dep time    (set_dep_time)     writes schedules.dep_time_override
 *   🛣  Change route    (change_route)     coach-type-safe route swap
 *   📋 Duplicate       (duplicate_trip)   copies bus/driver/dep_time_override to another date
 *   🚫 Block/Unblock   (block_trip)       schedules.is_blocked = 1|0 (hidden from search, not cancelled)
 *   ❌ Cancel          (cancel_trip)      schedules.status = 'cancelled', with reason
 *   ↩  Reopen          (uncancel_trip)    superadmin-only revert of a cancel
 *
 * All writes go to admin/api/schedule-action.php (AJAX, JSON). This page
 * never mutates state itself — reload on success is a soft one that only
 * re-fetches the table portion via location.reload() so the modal shell,
 * flash + audit trail all stay simple.
 *
 * Bus swap is still handled on trip-dashboard.php (Round 1 shipped the
 * assertBusSwapSafe guard there); the "Change bus" action here links
 * out to that page so the safety check keeps one home.
 *
 * Permission gate: 'schedules.manage' — manager + superadmin only.
 * Agent role does NOT have it; _guard.php denies before the page renders.
 */

declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';   // TripStatus::annotate() below (5 Sep 2026: was relying on another include)
$admin = admin_boot('schedules.manage');

$base = '';
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;
$isSuperadmin = Auth::isSuperadmin();

/* ── Date selection ─────────────────────────────────────────────────── */
$date = Security::clean((string) ($_GET['date'] ?? todayISO()), 10);
if (!Security::isValidDate($date)) {
    $date = todayISO();
}

/* ── Dropdown source data ───────────────────────────────────────────── */
$routes = Database::fetchAll(
    "SELECT id, route_code, from_city, to_city, coach_type, dep_time, arr_time, is_active
       FROM routes
      WHERE is_active = 1
      ORDER BY sort_order, id"
);

$activeBuses = Database::fetchAll(
    "SELECT id, bus_name, bus_number
       FROM buses
      WHERE is_active = 1
      ORDER BY bus_name"
);

$activeDrivers = Database::fetchAll(
    "SELECT id, full_name, phone
       FROM drivers
      WHERE is_active = 1
      ORDER BY full_name"
);

/* ── Schedules for the chosen date ──────────────────────────────────── *
 * COALESCE(s.dep_time_override, r.dep_time) is the Round 1 rule — every
 * reader must respect the override so a delayed trip's boarding window
 * follows the new time. We enrich with `seats_booked` (live count) so
 * TripStatus::compute() can promote to SOLD_OUT when appropriate.
 */
$rows = Database::fetchAll(
    "SELECT s.id, s.route_id, s.travel_date, s.slot, s.bus_id, s.driver_id,
            s.total_seats, s.status, s.is_blocked,
            s.dep_time_override, s.delay_minutes, s.delay_note,
            s.cancel_reason, s.cancelled_by, s.cancelled_at,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
            r.route_code, r.from_city, r.to_city, r.coach_type,
            r.arr_time, r.day_offset, r.dep_time AS route_dep_time,
            b.bus_name, b.bus_number,
            d.full_name AS driver_name, d.phone AS driver_phone,
            ca.username AS cancelled_by_name,
            (SELECT COUNT(*) FROM booking_seats bs
              WHERE bs.schedule_id = s.id AND bs.released_at IS NULL) AS seats_booked
       FROM schedules s
       JOIN routes  r  ON r.id = s.route_id
       LEFT JOIN buses   b  ON b.id = s.bus_id
       LEFT JOIN drivers d  ON d.id = s.driver_id
       LEFT JOIN admins  ca ON ca.id = s.cancelled_by
      WHERE s.travel_date = :d
      ORDER BY COALESCE(s.dep_time_override, r.dep_time), r.route_code",
    ['d' => $date]
);

/* Attach TripStatus::compute() + trip_status marks in one shot */
$rows = TripStatus::annotate($rows);

$totalTrips = count($rows);
$blockedCount = 0;
$cancelledCount = 0;
foreach ($rows as $r) {
    if ((int) ($r['is_blocked'] ?? 0) === 1) { $blockedCount++; }
    if (strtolower((string) ($r['status'] ?? '')) === 'cancelled') { $cancelledCount++; }
}

/* Small helpers shared with the row render */
function shg_fmt_time(?string $hms): string
{
    if ($hms === null || $hms === '') { return '—'; }
    $ts = strtotime($hms);
    return $ts === false ? Security::e($hms) : date('H:i', $ts);
}

function shg_state_pill(array $status, bool $blocked): string
{
    $state = (string) ($status['state'] ?? 'upcoming');
    $label = (string) ($status['label'] ?? ucfirst($state));
    $color = (string) ($status['color'] ?? '#6b7688');
    return '<span class="pill state-pill" style="background:' . $color . '1a;color:' . $color . ';border:1px solid ' . $color . '55">'
         . Security::e($label) . '</span>';
}

admin_header('Schedule Manager', 'schedule');
?>
<meta name="csrf-token" content="<?= $csrf ?>">
<meta name="csrf-name"  content="<?= Security::e($k) ?>">

<style>
/* ── Schedule Manager — scoped to this page ─────────────────────────── */
.sm-toolbar{display:flex;gap:12px;flex-wrap:wrap;align-items:center;
  background:var(--card);border:1px solid var(--line);border-radius:14px;
  padding:12px 16px;margin-bottom:16px}
.sm-toolbar .grow{flex:1}
.sm-toolbar label{font-size:12px;font-weight:700;color:var(--mut);
  text-transform:uppercase;letter-spacing:.04em;margin-right:6px}
.sm-toolbar input[type=date]{padding:9px 12px;border:1.5px solid var(--line);
  border-radius:9px;font-size:14px;background:var(--bg);color:var(--fg)}
.sm-summary{display:flex;gap:14px;flex-wrap:wrap;align-items:center;
  font-size:12px;color:var(--mut)}
.sm-summary b{color:var(--ink);font-size:14px;margin-right:4px}

.sm-panel{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden}

.sm-table{width:100%;border-collapse:collapse;font-size:13.5px}
.sm-table th,.sm-table td{padding:11px 14px;text-align:left;
  border-bottom:1px solid var(--line);vertical-align:middle}
.sm-table th{font-size:11px;color:var(--mut);text-transform:uppercase;
  letter-spacing:.3px;background:var(--head);font-weight:700}
.sm-table tr:last-child td{border-bottom:0}
.sm-table tr.row-cancelled{opacity:.55}
.sm-table tr.row-blocked{background:rgba(240,124,31,.06)}

.sm-when{display:flex;flex-direction:column;gap:2px}
.sm-when .time{font-weight:800;font-family:ui-monospace,Consolas,monospace;font-size:14px}
.sm-when .time .override{color:var(--orange);margin-left:6px;font-size:11px;font-weight:700}
.sm-when .date{color:var(--mut);font-size:11.5px}
.sm-route .from-to{font-weight:700}
.sm-route .rcode{color:var(--mut);font-size:11px;font-family:ui-monospace,Consolas,monospace}
.sm-route .coach{display:inline-block;padding:1px 7px;border-radius:6px;
  font-size:10px;font-weight:700;margin-left:6px;background:var(--head);color:var(--mut);text-transform:uppercase;letter-spacing:.03em}
.sm-bus,.sm-driver{font-size:13px}
.sm-bus small,.sm-driver small{display:block;color:var(--mut);font-size:11px;font-family:ui-monospace,Consolas,monospace}
.sm-sold{font-family:ui-monospace,Consolas,monospace;font-weight:700}
.sm-sold .booked{color:var(--fg)}
.sm-sold .total{color:var(--mut)}
.state-pill{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.02em}
.blocked-pill{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;
  background:rgba(240,124,31,.16);color:#c05e0e;border:1px solid rgba(240,124,31,.5);margin-left:4px}
.cancel-reason{font-size:11px;color:#8a1f1f;margin-top:4px;font-style:italic;max-width:220px;
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}

/* Action column — icon buttons */
.sm-actions{display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}
.sm-btn{display:inline-flex;align-items:center;justify-content:center;
  width:32px;height:32px;padding:0;border-radius:8px;border:1px solid var(--line);
  background:var(--bg);color:var(--ink);cursor:pointer;font-size:15px;transition:all .12s;line-height:1}
.sm-btn:hover{background:var(--hover);border-color:var(--blue);color:var(--blue)}
.sm-btn.warn:hover{border-color:#c05e0e;color:#c05e0e;background:rgba(240,124,31,.08)}
.sm-btn.danger:hover{border-color:#8a1f1f;color:#8a1f1f;background:rgba(138,31,31,.08)}
.sm-btn.ok:hover{border-color:#0a6b3b;color:#0a6b3b;background:rgba(10,107,59,.08)}
.sm-btn[disabled]{opacity:.35;cursor:not-allowed;pointer-events:none}

.sm-empty{padding:44px 20px;text-align:center;color:var(--mut)}
.sm-empty h3{margin:0 0 6px;color:var(--ink);font-size:15px}

/* Add-trip button — top-right of toolbar */
.btn-new-trip{display:inline-flex;align-items:center;gap:6px;
  padding:9px 16px;border-radius:9px;border:0;background:#0a6b3b;color:#fff;
  font-weight:800;font-size:13px;cursor:pointer;box-shadow:0 2px 8px rgba(10,107,59,.25)}
.btn-new-trip:hover{filter:brightness(1.08)}

/* ── Modal shell (mirrors admin/buses.php pattern) ─────────────────── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1000;
  display:flex;align-items:center;justify-content:center;padding:20px}
.modal-box{background:var(--card);border-radius:18px;max-width:540px;width:100%;
  max-height:90vh;overflow-y:auto;box-shadow:0 24px 60px rgba(0,0,0,.4)}
.modal-head{padding:20px 24px 14px;border-bottom:1px solid var(--line);
  display:flex;align-items:center;justify-content:space-between;gap:12px}
.modal-head h2{margin:0;font-size:17px;color:var(--fg)}
.modal-close{background:none;border:none;font-size:22px;cursor:pointer;color:var(--mut);padding:0;line-height:1}
.modal-body{padding:20px 24px}
.modal-body .modal-intro{font-size:13px;color:var(--mut);margin:0 0 14px;line-height:1.5}
.modal-body .modal-intro b{color:var(--ink)}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.form-group{display:flex;flex-direction:column}
.form-group label{display:block;font-size:12px;font-weight:700;color:var(--mut);
  text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:10px 12px;
  border:1.5px solid var(--line);border-radius:9px;font-size:14px;
  background:var(--bg);color:var(--fg);transition:border-color .15s;font-family:inherit}
.form-group textarea{resize:vertical;min-height:70px}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{
  outline:none;border-color:var(--blue);box-shadow:0 0 0 3px rgba(46,95,168,.12)}
.form-group-full{grid-column:1/-1}
.form-hint{font-size:11.5px;color:var(--mut);margin-top:4px;line-height:1.4}
.form-warn{padding:9px 12px;background:rgba(240,124,31,.12);border:1px solid rgba(240,124,31,.4);
  border-radius:8px;font-size:12.5px;color:#8b4708;margin-top:12px;line-height:1.4}
.form-err{padding:9px 12px;background:rgba(138,31,31,.12);border:1px solid rgba(138,31,31,.4);
  border-radius:8px;font-size:12.5px;color:#8a1f1f;margin-top:12px;line-height:1.4;display:none}
.modal-footer{margin-top:20px;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
.modal-footer .spacer{flex:1}
.btn.btn-sm{padding:7px 14px;font-size:13px}
.btn-warn{background:#c05e0e;color:#fff}
.btn-warn:hover{filter:brightness(1.08)}
.btn-ok{background:#0a6b3b;color:#fff}
.btn-ok:hover{filter:brightness(1.08)}
.btn-blue{background:var(--blue);color:#fff}
.btn-blue:hover{filter:brightness(1.08)}
.btn-ghost{background:var(--card);border:1px solid var(--line);color:var(--ink)}
.btn-ghost:hover{background:var(--hover)}
.btn-danger{background:#8a1f1f;color:#fff}
.btn-danger:hover{filter:brightness(1.08)}
.btn[data-loading="1"]{opacity:.65;cursor:progress;pointer-events:none}

/* ── Mobile: table → stacked cards ─────────────────────────────────── */
@media(max-width:820px){
  .sm-table thead{display:none}
  .sm-table,.sm-table tbody,.sm-table tr,.sm-table td{display:block;width:100%}
  .sm-table tr{padding:12px 14px;border-bottom:1px solid var(--line)}
  .sm-table tr:last-child{border-bottom:0}
  .sm-table td{padding:6px 0;border:0;position:relative;padding-left:44%}
  .sm-table td::before{content:attr(data-label);position:absolute;left:0;top:6px;
    font-size:10px;font-weight:800;color:var(--mut);text-transform:uppercase;letter-spacing:.04em;width:40%}
  .sm-table td.cell-actions{padding-left:0}
  .sm-table td.cell-actions::before{display:none}
  .sm-actions{justify-content:flex-start;margin-top:6px}
  .modal-box{max-width:100%;border-radius:14px}
  .modal-body{padding:16px 18px}
  .form-grid{grid-template-columns:1fr}
}
</style>

<!-- ── Toolbar ───────────────────────────────────────────────────── -->
<div class="sm-toolbar">
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0">
    <label for="smDate">Date</label>
    <input type="date" name="date" id="smDate" value="<?= Security::e($date) ?>"
           onchange="this.form.submit()">
    <noscript><button type="submit" class="btn btn-blue btn-sm">Load</button></noscript>
  </form>
  <div class="grow"></div>
  <div class="sm-summary">
    <span><b><?= (int) $totalTrips ?></b>trips</span>
    <?php if ($blockedCount > 0): ?><span><b><?= (int) $blockedCount ?></b>blocked</span><?php endif; ?>
    <?php if ($cancelledCount > 0): ?><span><b><?= (int) $cancelledCount ?></b>cancelled</span><?php endif; ?>
  </div>
  <button type="button" class="btn-new-trip" onclick="openAddTripModal()">+ New Trip</button>
</div>

<!-- ── Schedules panel ───────────────────────────────────────────── -->
<div class="sm-panel">
  <?php if ($totalTrips === 0): ?>
    <div class="sm-empty">
      <h3>No schedules for <?= Security::e($date) ?></h3>
      <div>Use <b>+ New Trip</b> to create one, or pick another date above.</div>
    </div>
  <?php else: ?>
    <table class="sm-table" aria-label="Schedules for <?= Security::e($date) ?>">
      <thead>
        <tr>
          <th style="width:110px">Time</th>
          <th>Route</th>
          <th>Bus</th>
          <th>Driver</th>
          <th style="width:82px">Sold / Total</th>
          <th style="width:180px">State</th>
          <th style="width:170px;text-align:right">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $sid = (int) $r['id'];
        $stored = strtolower((string) ($r['status'] ?? ''));
        $isCancelled = $stored === 'cancelled';
        $isBlocked   = (int) ($r['is_blocked'] ?? 0) === 1;
        $hasOverride = (string) ($r['dep_time_override'] ?? '') !== '';
        $status      = (array) ($r['_status'] ?? []);
        $state       = (string) ($status['state'] ?? 'upcoming');
        $isDeparted  = in_array($state, ['departed','on_route','arrived','completed'], true);
        $booked      = (int) ($r['seats_booked'] ?? 0);
        $total       = (int) ($r['total_seats'] ?? 0);
        $rowClass    = ($isCancelled ? 'row-cancelled ' : '') . ($isBlocked ? 'row-blocked' : '');
        $routeStr    = ($r['from_city'] ?? '') . ' → ' . ($r['to_city'] ?? '');
        $depTimeAttr = shg_fmt_time((string) $r['dep_time']);
      ?>
      <tr class="<?= trim($rowClass) ?>" data-sid="<?= $sid ?>">
        <td data-label="Time">
          <div class="sm-when">
            <span class="time"><?= shg_fmt_time((string) $r['dep_time']) ?>
              <?php if ($hasOverride): ?><span class="override" title="Departure time overridden — route default is <?= shg_fmt_time((string) $r['route_dep_time']) ?>">override</span><?php endif; ?>
            </span>
            <span class="date"><?= Security::e($r['travel_date']) ?></span>
          </div>
        </td>
        <td data-label="Route">
          <div class="sm-route">
            <div class="from-to"><?= Security::e($routeStr) ?>
              <span class="coach"><?= Security::e((string) $r['coach_type']) ?></span>
            </div>
            <div class="rcode"><?= Security::e((string) $r['route_code']) ?><?php if ((int) ($r['slot'] ?? 1) > 1): ?> <span class="pill" style="background:#efeaff;color:#5a3fb0;font-size:10.5px" title="Extra departure added on the Bus Calendar">➕ Bus <?= (int) $r['slot'] ?></span><?php endif; ?></div>
            <?php if ($isCancelled && ($r['cancel_reason'] ?? '') !== ''): ?>
              <div class="cancel-reason" title="<?= Security::e((string) $r['cancel_reason']) ?>">
                ❌ <?= Security::e((string) $r['cancel_reason']) ?>
              </div>
            <?php endif; ?>
          </div>
        </td>
        <td data-label="Bus">
          <div class="sm-bus">
            <?php if (($r['bus_name'] ?? '') !== ''): ?>
              <?= Security::e((string) $r['bus_name']) ?>
              <small><?= Security::e((string) $r['bus_number']) ?></small>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </div>
        </td>
        <td data-label="Driver">
          <div class="sm-driver">
            <?php if (($r['driver_name'] ?? '') !== ''): ?>
              <?= Security::e((string) $r['driver_name']) ?>
              <small><?= Security::e((string) $r['driver_phone']) ?></small>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </div>
        </td>
        <td data-label="Sold / Total" class="sm-sold">
          <span class="booked"><?= $booked ?></span><span class="total">/<?= $total ?></span>
        </td>
        <td data-label="State">
          <?= shg_state_pill($status, $isBlocked) ?>
          <?php if ($isBlocked): ?><span class="blocked-pill">🚫 Blocked</span><?php endif; ?>
        </td>
        <td data-label="Actions" class="cell-actions">
          <div class="sm-actions">
            <?php if (!$isCancelled): ?>
              <button type="button" class="sm-btn" title="Edit departure time"
                onclick='openEditTimeModal(<?= (int) $sid ?>, <?= json_encode($routeStr, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, "<?= Security::e($r['travel_date']) ?>", "<?= $depTimeAttr ?>", <?= $hasOverride ? 'true' : 'false' ?>, <?= $isDeparted ? 'true' : 'false' ?>)'>⏰</button>
              <button type="button" class="sm-btn" title="Change route"
                onclick='openChangeRouteModal(<?= (int) $sid ?>, <?= (int) $r['route_id'] ?>, "<?= Security::e((string) $r['coach_type']) ?>", <?= (int) $booked ?>)'>🛣</button>
              <a class="sm-btn" title="Change bus (goes to trip-dashboard)"
                href="<?= $base ?>/admin/trip-dashboard.php?sid=<?= (int) $sid ?>">🚌</a>
              <a class="sm-btn" title="Seat map for this departure"
                href="<?= $base ?>/admin/seatmap.php?sid=<?= (int) $sid ?>">🪑</a>
              <button type="button" class="sm-btn" title="Add an extra bus on this date (same route)"
                onclick='openExtraBusModal(<?= (int) $sid ?>, <?= json_encode($routeStr, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, "<?= Security::e($r['travel_date']) ?>", "<?= $depTimeAttr ?>")'>➕</button>
              <button type="button" class="sm-btn" title="Duplicate to another date"
                onclick='openDuplicateModal(<?= (int) $sid ?>, <?= json_encode($routeStr, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, "<?= Security::e($r['travel_date']) ?>")'>📋</button>
              <button type="button" class="sm-btn warn" title="<?= $isBlocked ? 'Unblock (make bookable again)' : 'Block from search (keeps existing bookings)' ?>"
                onclick='openBlockModal(<?= (int) $sid ?>, <?= $isBlocked ? 'true' : 'false' ?>, <?= (int) $booked ?>)'>🚫</button>
              <button type="button" class="sm-btn danger" title="Cancel this trip"
                onclick='openCancelModal(<?= (int) $sid ?>, <?= json_encode($routeStr, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, "<?= Security::e($r['travel_date']) ?>", <?= (int) $booked ?>)'>❌</button>
            <?php else: ?>
              <span class="pill" style="background:#f7dcdc;color:#8a1f1f">Cancelled</span>
              <?php if ($isSuperadmin): ?>
                <button type="button" class="sm-btn ok" title="Reopen this cancelled trip"
                  onclick='openUncancelModal(<?= (int) $sid ?>, <?= json_encode($routeStr, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>, "<?= Security::e($r['travel_date']) ?>")'>↩</button>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ── Shared modal root (populated by JS per action) ────────────── -->
<div id="modalRoot" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeModal()"></div>

<!-- ── JSON data for the modals (routes + buses + drivers + Round-1 flag) ─ -->
<script>
window.SHG_SCHED = {
  csrf: <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>,
  csrfName: <?= json_encode($k, JSON_UNESCAPED_UNICODE) ?>,
  today: <?= json_encode(todayISO(), JSON_UNESCAPED_UNICODE) ?>,
  currentDate: <?= json_encode($date, JSON_UNESCAPED_UNICODE) ?>,
  isSuperadmin: <?= $isSuperadmin ? 'true' : 'false' ?>,
  routes: <?= json_encode(array_map(static function (array $r): array {
      return [
          'id'         => (int) $r['id'],
          'route_code' => (string) $r['route_code'],
          'label'      => (string) $r['from_city'] . ' → ' . (string) $r['to_city'] . ' (' . (string) $r['route_code'] . ')',
          'coach_type' => (string) $r['coach_type'],
          'dep_time'   => substr((string) $r['dep_time'], 0, 5),
      ];
  }, $routes), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>,
  buses: <?= json_encode(array_map(static function (array $b): array {
      return [
          'id'    => (int) $b['id'],
          'label' => (string) $b['bus_name'] . ' · ' . (string) $b['bus_number'],
      ];
  }, $activeBuses), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>,
  drivers: <?= json_encode(array_map(static function (array $d): array {
      return [
          'id'    => (int) $d['id'],
          'label' => (string) $d['full_name'] . ' · ' . (string) $d['phone'],
      ];
  }, $activeDrivers), JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>
};
</script>

<script>
/* =====================================================================
 *  Schedule Manager — client script
 *
 *  Everything below is scoped inside one IIFE. No global collisions
 *  with the admin poll_js / search_js shipped by _guard.php.
 * ===================================================================== */
(function () {
  var S = window.SHG_SCHED || {};
  var root = document.getElementById('modalRoot');

  /* ── Tiny escapers (no third-party libs in admin land) ──────────── */
  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  /* ── Flash toast (mirrors payments.php helper) ──────────────────── */
  function showFlash(msg, type) {
    var old = document.querySelector('.flash');
    if (old) old.remove();
    var el = document.createElement('div');
    el.className = 'flash ' + (type === 'ok' ? 'ok' : 'bad');
    el.textContent = msg;
    var anchor = document.querySelector('.wrap h1');
    if (anchor) anchor.insertAdjacentElement('afterend', el);
    else document.body.appendChild(el);
    setTimeout(function () { if (el.parentNode) el.remove(); }, 6000);
  }

  /* ── Modal shell ────────────────────────────────────────────────── */
  function openModal(title, bodyHtml, footerHtml) {
    root.innerHTML =
      '<div class="modal-box" role="dialog" aria-modal="true" aria-label="' + esc(title) + '">' +
        '<div class="modal-head"><h2>' + esc(title) + '</h2>' +
          '<button type="button" class="modal-close" onclick="window.__smClose()" aria-label="Close">✕</button>' +
        '</div>' +
        '<div class="modal-body">' + bodyHtml +
          '<div class="form-err" id="smModalErr"></div>' +
          '<div class="modal-footer">' + footerHtml + '</div>' +
        '</div>' +
      '</div>';
    root.style.display = 'flex';
    /* Focus first field for keyboard flow */
    setTimeout(function () {
      var f = root.querySelector('input, select, textarea, button');
      if (f) f.focus();
    }, 30);
  }
  function closeModal() {
    root.style.display = 'none';
    root.innerHTML = '';
  }
  window.__smClose = closeModal;

  /* Escape key closes any open modal */
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && root.style.display !== 'none') closeModal();
  });

  /* ── Fetch wrapper ──────────────────────────────────────────────── *
   * Every action posts a FormData to schedule-action.php with the
   * page's CSRF token. Response is {ok, message, ...} JSON. Errors
   * paint inside the modal so the operator can fix + retry without
   * losing their inputs.
   * --------------------------------------------------------------- */
  function callApi(action, data, submitBtn) {
    var fd = new FormData();
    fd.append(S.csrfName || 'csrf_token', S.csrf || '');
    fd.append('action', action);
    Object.keys(data || {}).forEach(function (k) {
      if (data[k] === undefined || data[k] === null) return;
      fd.append(k, data[k]);
    });

    var errBox = document.getElementById('smModalErr');
    if (errBox) { errBox.style.display = 'none'; errBox.textContent = ''; }
    if (submitBtn) submitBtn.setAttribute('data-loading', '1');

    return fetch('/admin/api/schedule-action.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server sent a non-JSON reply.' }; }); })
      .then(function (j) {
        if (submitBtn) submitBtn.removeAttribute('data-loading');
        if (j && j.ok) return j;
        var msg = (j && (j.error || j.message)) || 'Something went wrong.';
        if (errBox) { errBox.textContent = msg; errBox.style.display = 'block'; }
        else showFlash(msg, 'bad');
        return null;
      })
      .catch(function () {
        if (submitBtn) submitBtn.removeAttribute('data-loading');
        var msg = 'Network error — please try again.';
        if (errBox) { errBox.textContent = msg; errBox.style.display = 'block'; }
        else showFlash(msg, 'bad');
        return null;
      });
  }

  /* Success handler shared by every action — flash + soft reload. */
  function onSuccess(msg) {
    closeModal();
    /* Persist the flash across the reload */
    try { sessionStorage.setItem('shg_sched_flash', JSON.stringify({ type: 'ok', msg: msg })); } catch (e) {}
    setTimeout(function () { location.reload(); }, 150);
  }

  /* Show a persisted flash after reload */
  (function replayFlash() {
    try {
      var raw = sessionStorage.getItem('shg_sched_flash');
      if (raw) {
        sessionStorage.removeItem('shg_sched_flash');
        var j = JSON.parse(raw);
        if (j && j.msg) showFlash(j.msg, j.type || 'ok');
      }
    } catch (e) {}
  })();

  /* ── Modal 1: Cancel trip ────────────────────────────────────────── */
  window.openCancelModal = function (sid, routeStr, dateStr, bookedCount) {
    var warn = bookedCount > 0
      ? '<div class="form-warn">⚠️ This trip has <b>' + bookedCount + '</b> confirmed booking(s). ' +
        'Cancelling only marks the trip cancelled — seats are NOT auto-released and refunds are NOT auto-issued. ' +
        'Handle each PNR from the Refund Desk once the trip is confirmed off.</div>'
      : '';
    openModal(
      'Cancel trip',
      '<p class="modal-intro">You are about to cancel <b>' + esc(routeStr) + '</b> on <b>' + esc(dateStr) + '</b>. ' +
        'New bookings will be refused. Existing tickets remain valid until refunded from the Refund Desk.</p>' +
      '<div class="form-grid"><div class="form-group form-group-full">' +
        '<label for="cancelReason">Reason (required)</label>' +
        '<textarea id="cancelReason" maxlength="255" placeholder="e.g. Mechanical issue — bus swap not possible"></textarea>' +
        '<div class="form-hint">Max 255 characters. Passengers may see a short version of this.</div>' +
      '</div></div>' + warn,
      '<button type="button" class="btn btn-ghost btn-sm" onclick="window.__smClose()">Keep trip</button>' +
      '<button type="button" class="btn btn-danger btn-sm" id="cancelBtn">Cancel trip</button>'
    );
    document.getElementById('cancelBtn').addEventListener('click', function () {
      var reason = (document.getElementById('cancelReason').value || '').trim();
      if (reason === '') {
        var err = document.getElementById('smModalErr');
        err.textContent = 'Please give a reason so passengers understand the cancel.';
        err.style.display = 'block';
        return;
      }
      callApi('cancel_trip', { schedule_id: sid, reason: reason }, this).then(function (j) {
        if (j) onSuccess(j.message || 'Trip cancelled.');
      });
    });
  };

  /* ── Modal 2: Uncancel (superadmin only) ────────────────────────── */
  window.openUncancelModal = function (sid, routeStr, dateStr) {
    openModal(
      'Reopen cancelled trip',
      '<p class="modal-intro">Reopen <b>' + esc(routeStr) + '</b> on <b>' + esc(dateStr) + '</b>? ' +
        'The trip returns to its state before cancellation and becomes bookable again.</p>' +
      '<div class="form-warn">Existing bookings on this schedule were not touched at cancel time. ' +
        'Any seats freed via Refund Desk stay released — this only clears the schedule-level cancel flag.</div>',
      '<button type="button" class="btn btn-ghost btn-sm" onclick="window.__smClose()">Keep cancelled</button>' +
      '<button type="button" class="btn btn-ok btn-sm" id="uncancelBtn">Reopen trip</button>'
    );
    document.getElementById('uncancelBtn').addEventListener('click', function () {
      callApi('uncancel_trip', { schedule_id: sid }, this).then(function (j) {
        if (j) onSuccess(j.message || 'Trip reopened.');
      });
    });
  };

  /* ── Modal 3: Block / Unblock ───────────────────────────────────── */
  window.openBlockModal = function (sid, isBlocked, bookedCount) {
    var title = isBlocked ? 'Unblock trip' : 'Block trip from search';
    var intro = isBlocked
      ? 'Unblock this trip so it appears in customer search again. Nothing else changes.'
      : 'Blocking hides this trip from customer search without cancelling it. ' +
        'Existing tickets remain fully valid — this is for temporary flags only ' +
        '(pending decision, sold-out override, etc.).';
    var warn = (!isBlocked && bookedCount > 0)
      ? '<div class="form-warn">This trip has <b>' + bookedCount + '</b> confirmed booking(s). ' +
        'Blocking does not affect them.</div>'
      : '';
    openModal(
      title,
      '<p class="modal-intro">' + esc(intro) + '</p>' + warn,
      '<button type="button" class="btn btn-ghost btn-sm" onclick="window.__smClose()">Keep as-is</button>' +
      '<button type="button" class="btn ' + (isBlocked ? 'btn-ok' : 'btn-warn') + ' btn-sm" id="blockBtn">' +
        (isBlocked ? 'Unblock' : 'Block') + '</button>'
    );
    document.getElementById('blockBtn').addEventListener('click', function () {
      callApi(isBlocked ? 'unblock_trip' : 'block_trip', { schedule_id: sid }, this).then(function (j) {
        if (j) onSuccess(j.message || (isBlocked ? 'Trip unblocked.' : 'Trip blocked.'));
      });
    });
  };

  /* ── Modal 4: Edit departure time ───────────────────────────────── */
  window.openEditTimeModal = function (sid, routeStr, dateStr, currentTime, isOverride, isDeparted) {
    if (isDeparted) {
      showFlash('Cannot change dep time — trip has already departed.', 'bad');
      return;
    }
    openModal(
      'Edit departure time',
      '<p class="modal-intro">' + esc(routeStr) + ' · <b>' + esc(dateStr) + '</b><br>' +
        'Current effective dep time: <b>' + esc(currentTime) + '</b>' +
        (isOverride ? ' <span style="color:#c05e0e">(override)</span>' : ' <span class="muted">(from route default)</span>') +
      '</p>' +
      '<div class="form-grid">' +
        '<div class="form-group form-group-full">' +
          '<label for="depTime">New departure time (24h HH:MM)</label>' +
          '<input type="time" id="depTime" value="' + esc(currentTime) + '">' +
          '<div class="form-hint">Writes <code>schedules.dep_time_override</code>. All 8 readers use ' +
            'COALESCE(override, route dep_time), so this takes effect immediately.</div>' +
        '</div>' +
      '</div>',
      (isOverride
        ? '<button type="button" class="btn btn-warn btn-sm" id="clearBtn">Clear override</button>'
        : '') +
      '<div class="spacer"></div>' +
      '<button type="button" class="btn btn-ghost btn-sm" onclick="window.__smClose()">Cancel</button>' +
      '<button type="button" class="btn btn-blue btn-sm" id="saveTimeBtn">Save</button>'
    );
    document.getElementById('saveTimeBtn').addEventListener('click', function () {
      var v = (document.getElementById('depTime').value || '').trim();
      if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(v)) {
        var err = document.getElementById('smModalErr');
        err.textContent = 'Please pick a valid time in HH:MM format.';
        err.style.display = 'block';
        return;
      }
      callApi('set_dep_time', { schedule_id: sid, dep_time: v }, this).then(function (j) {
        if (j) onSuccess(j.message || 'Departure time updated.');
      });
    });
    var clearBtn = document.getElementById('clearBtn');
    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        callApi('set_dep_time', { schedule_id: sid, dep_time: '' }, this).then(function (j) {
          if (j) onSuccess(j.message || 'Override cleared — reverted to route default.');
        });
      });
    }
  };

  /* ── Modal 5: Change route ──────────────────────────────────────── */
  window.openChangeRouteModal = function (sid, currentRouteId, currentCoach, bookedCount) {
    /* Only same-coach routes are safe — a sleeper seat_no (L#/U#)
       cannot survive a switch to a seater coach (1A..10D). */
    var options = (S.routes || [])
      .filter(function (r) { return r.coach_type === currentCoach; })
      .map(function (r) {
        var sel = r.id === currentRouteId ? ' selected' : '';
        return '<option value="' + r.id + '"' + sel + '>' + esc(r.label) + '</option>';
      }).join('');

    var warn = bookedCount > 0
      ? '<div class="form-warn">⚠️ This trip has <b>' + bookedCount + '</b> confirmed booking(s). ' +
        'Only same-coach routes are shown (seat numbers must survive the swap). ' +
        'Passengers keep the same seat_no on the new route.</div>'
      : '';

    openModal(
      'Change route',
      '<p class="modal-intro">Move this trip onto a different route. Only routes with the ' +
        'same coach type (<b>' + esc(currentCoach) + '</b>) are listed.</p>' +
      '<div class="form-grid">' +
        '<div class="form-group form-group-full">' +
          '<label for="newRoute">New route</label>' +
          '<select id="newRoute">' + options + '</select>' +
          '<div class="form-hint">Route timings, fare, and pickup list will follow the new route from the next reload.</div>' +
        '</div>' +
      '</div>' + warn,
      '<button type="button" class="btn btn-ghost btn-sm" onclick="window.__smClose()">Cancel</button>' +
      '<button type="button" class="btn btn-blue btn-sm" id="changeRouteBtn">Change route</button>'
    );
    document.getElementById('changeRouteBtn').addEventListener('click', function () {
      var v = parseInt(document.getElementById('newRoute').value, 10) || 0;
      if (v <= 0) {
        var err = document.getElementById('smModalErr');
        err.textContent = 'Please pick a route.';
        err.style.display = 'block';
        return;
      }
      if (v === currentRouteId) {
        var err2 = document.getElementById('smModalErr');
        err2.textContent = 'That is already the current route.';
        err2.style.display = 'block';
        return;
      }
      callApi('change_route', { schedule_id: sid, route_id: v }, this).then(function (j) {
        if (j) onSuccess(j.message || 'Route changed.');
      });
    });
  };

  /* ── Modal 6: Duplicate to another date ─────────────────────────── */
  window.openDuplicateModal = function (sid, routeStr, currentDate) {
    /* Default the target to +1 day so the operator only clicks Save. */
    var d = new Date(currentDate + 'T00:00:00');
    d.setDate(d.getDate() + 1);
    var suggest = d.toISOString().slice(0, 10);
    var today = S.today || suggest;

    openModal(
      'Duplicate trip to another date',
      '<p class="modal-intro">Copy <b>' + esc(routeStr) + '</b> from <b>' + esc(currentDate) + '</b> to a new date. ' +
        'The bus, driver, and any dep-time override travel with it. Bookings do not.</p>' +
      '<div class="form-grid">' +
        '<div class="form-group form-group-full">' +
          '<label for="dupDate">Target travel date</label>' +
          '<input type="date" id="dupDate" value="' + esc(suggest) + '" min="' + esc(today) + '">' +
          '<div class="form-hint">Must be today or a future date. If a schedule already exists for that (route, date), the API will refuse.</div>' +
        '</div>' +
      '</div>',
      '<button type="button" class="btn btn-ghost btn-sm" onclick="window.__smClose()">Cancel</button>' +
      '<button type="button" class="btn btn-blue btn-sm" id="dupBtn">Duplicate</button>'
    );
    document.getElementById('dupBtn').addEventListener('click', function () {
      var v = (document.getElementById('dupDate').value || '').trim();
      var err = document.getElementById('smModalErr');
      if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) {
        err.textContent = 'Please pick a valid date.'; err.style.display = 'block'; return;
      }
      if (v === currentDate) {
        err.textContent = 'The target date must be different from the source date.'; err.style.display = 'block'; return;
      }
      if (v < today) {
        err.textContent = 'Please pick today or a future date.'; err.style.display = 'block'; return;
      }
      callApi('duplicate_trip', { schedule_id: sid, target_date: v }, this).then(function (j) {
        if (j) onSuccess(j.message || 'Trip duplicated.');
      });
    });
  };

  /* ── Modal 7: + New Trip ─────────────────────────────────────────── */
  window.openAddTripModal = function () {
    var routeOpts = (S.routes || []).map(function (r) {
      return '<option value="' + r.id + '" data-dep="' + esc(r.dep_time) + '">' + esc(r.label) + '</option>';
    }).join('');
    var busOpts = '<option value="0">— Route default / rotation —</option>' +
      (S.buses || []).map(function (b) {
        return '<option value="' + b.id + '">' + esc(b.label) + '</option>';
      }).join('');
    var driverOpts = '<option value="0">— Unassigned —</option>' +
      (S.drivers || []).map(function (d) {
        return '<option value="' + d.id + '">' + esc(d.label) + '</option>';
      }).join('');
    var today = S.today || S.currentDate;

    openModal(
      '+ New Trip',
      '<p class="modal-intro">Create one new schedule row. Fleet rotation still fires on the day itself; ' +
        'pick a specific bus below only if you want to override.</p>' +
      '<div class="form-grid">' +
        '<div class="form-group form-group-full">' +
          '<label for="addRoute">Route</label>' +
          '<select id="addRoute">' + routeOpts + '</select>' +
        '</div>' +
        '<div class="form-group">' +
          '<label for="addDate">Travel date</label>' +
          '<input type="date" id="addDate" value="' + esc(S.currentDate || today) + '" min="' + esc(today) + '">' +
        '</div>' +
        '<div class="form-group">' +
          '<label for="addTime">Dep time (optional)</label>' +
          '<input type="time" id="addTime" placeholder="From route default">' +
          '<div class="form-hint">Leave blank to use the route’s printed dep time.</div>' +
        '</div>' +
        '<div class="form-group">' +
          '<label for="addBus">Bus (optional)</label>' +
          '<select id="addBus">' + busOpts + '</select>' +
        '</div>' +
        '<div class="form-group">' +
          '<label for="addDriver">Driver (optional)</label>' +
          '<select id="addDriver">' + driverOpts + '</select>' +
        '</div>' +
        '<div class="form-group">' +
          '<label for="addFare">Price ₹ / seat (optional)</label>' +
          '<input type="number" id="addFare" min="0" step="1" placeholder="Normal fare">' +
          '<div class="form-hint">Blank uses the normal fare for the route and direction.</div>' +
        '</div>' +
        '<div class="form-group">' +
          '<label for="addCoach">Seat layout (optional)</label>' +
          '<select id="addCoach"><option value="">Same as the route</option>' +
            '<option value="sleeper">Sleeper berths</option><option value="seater">Seater seats</option></select>' +
          '<div class="form-hint">Choosing a layout sets the seat count with it.</div>' +
        '</div>' +
      '</div>',
      '<button type="button" class="btn btn-ghost btn-sm" onclick="window.__smClose()">Cancel</button>' +
      '<button type="button" class="btn btn-ok btn-sm" id="addTripBtn">Create trip</button>'
    );

    document.getElementById('addTripBtn').addEventListener('click', function () {
      var err = document.getElementById('smModalErr');
      var routeId = parseInt(document.getElementById('addRoute').value, 10) || 0;
      var date    = (document.getElementById('addDate').value || '').trim();
      var depTime = (document.getElementById('addTime').value || '').trim();
      var busId   = parseInt(document.getElementById('addBus').value, 10) || 0;
      var driverId= parseInt(document.getElementById('addDriver').value, 10) || 0;

      if (routeId <= 0) { err.textContent = 'Please pick a route.'; err.style.display='block'; return; }
      if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) { err.textContent = 'Please pick a valid date.'; err.style.display='block'; return; }
      if (date < today) { err.textContent = 'Please pick today or a future date.'; err.style.display='block'; return; }
      if (depTime !== '' && !/^([01]\d|2[0-3]):[0-5]\d$/.test(depTime)) {
        err.textContent = 'Dep time must be in HH:MM format.'; err.style.display='block'; return;
      }

      callApi('add_trip', {
        route_id: routeId,
        travel_date: date,
        dep_time: depTime,
        bus_id: busId,
        driver_id: driverId,
        fare: (document.getElementById('addFare').value || '').trim(),
        coach_type: document.getElementById('addCoach').value || ''
      }, this).then(function (j) {
        if (j) {
          /* Jump to the target date after reload so the new row is visible */
          try {
            sessionStorage.setItem('shg_sched_flash',
              JSON.stringify({ type: 'ok', msg: j.message || 'Trip created.' }));
          } catch (e) {}
          closeModal();
          setTimeout(function () {
            var url = new URL(location.href);
            url.searchParams.set('date', date);
            location.href = url.toString();
          }, 150);
        }
      });
    });
  };

  /* ── Modal 8: ➕ Extra bus on the same date (Bus Calendar, 5 Sep 2026) ── */
  window.openExtraBusModal = function (sid, routeStr, date, depTime) {
    var busOpts = '<option value="0">— Same coach as bus 1 (set later) —</option>' +
      (S.buses || []).map(function (b) {
        return '<option value="' + b.id + '">' + esc(b.label) + '</option>';
      }).join('');
    var driverOpts = '<option value="0">— Unassigned —</option>' +
      (S.drivers || []).map(function (d) {
        return '<option value="' + d.id + '">' + esc(d.label) + '</option>';
      }).join('');
    openModal(
      '➕ Extra bus — ' + esc(routeStr) + ' · ' + esc(date),
      '<p class="modal-intro">Runs a <b>second departure</b> of this route on the same date, with its own seats and its own ' +
        'seat map. The daily bus (' + esc(depTime) + ') and its bookings are not touched. Customers see it as an extra bus in search.</p>' +
      '<div class="form-grid">' +
        '<div class="form-group">' +
          '<label for="xbTime">Departure time</label>' +
          '<input type="time" id="xbTime" value="' + esc(depTime) + '">' +
          '<div class="form-hint">Same as bus 1, or a later time (e.g. 21:00).</div>' +
        '</div>' +
        '<div class="form-group">' +
          '<label for="xbBus">Vehicle</label>' +
          '<select id="xbBus">' + busOpts + '</select>' +
        '</div>' +
        '<div class="form-group">' +
          '<label for="xbDriver">Driver (optional)</label>' +
          '<select id="xbDriver">' + driverOpts + '</select>' +
        '</div>' +
        '<div class="form-group">' +
          '<label for="xbFare">Price ₹ / seat (optional)</label>' +
          '<input type="number" id="xbFare" min="0" step="1" placeholder="Normal fare">' +
          '<div class="form-hint">Blank uses the normal fare. Set it to run this bus at its own price.</div>' +
        '</div>' +
        '<div class="form-group">' +
          '<label for="xbCoach">Seat layout (optional)</label>' +
          '<select id="xbCoach"><option value="">Same as the route</option>' +
            '<option value="sleeper">Sleeper berths</option><option value="seater">Seater seats</option></select>' +
          '<div class="form-hint">Run a seater coach beside the sleeper, with its own seat map.</div>' +
        '</div>' +
      '</div>',
      '<button type="button" class="btn btn-ghost btn-sm" onclick="window.__smClose()">Cancel</button>' +
      '<button type="button" class="btn btn-ok btn-sm" id="xbBtn">Add extra bus</button>'
    );
    document.getElementById('xbBtn').addEventListener('click', function () {
      var err = document.getElementById('smModalErr');
      var t = (document.getElementById('xbTime').value || '').trim();
      if (t !== '' && !/^([01]\d|2[0-3]):[0-5]\d$/.test(t)) { err.textContent = 'Time must be HH:MM.'; err.style.display = 'block'; return; }
      callApi('add_extra_bus', {
        schedule_id: sid,
        dep_time: t,
        bus_id: parseInt(document.getElementById('xbBus').value, 10) || 0,
        driver_id: parseInt(document.getElementById('xbDriver').value, 10) || 0,
        fare: (document.getElementById('xbFare').value || '').trim(),
        coach_type: document.getElementById('xbCoach').value || ''
      }, this).then(function (j) { if (j) onSuccess(j.message || 'Extra bus added.'); });
    });
  };
})();
</script>

<?php admin_footer();
