<?php
/**
 * admin/reschedule.php?pnr=... — move a booking's outbound leg to a different
 * DATE on the SAME route (change-date / change-trip), optionally onto different
 * seats, keeping the same PNR / payment / commission. Thin UI over
 * BookingService::rebookLeg(); all the money/seat/ticket safety lives there.
 *
 * Gated to staff who can edit bookings AND manage schedules (manager /
 * superadmin) — a counter agent cannot reschedule.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';
$admin = admin_boot('bookings.edit');

$base  = '';
$pnr   = Security::clean($_GET['pnr'] ?? ($_POST['pnr'] ?? ''), 40);
$flash = null;

// schedules.edit (not .manage) since 5 Sep 2026: counter staff move dates
// for walk-ins, but must not gain the Schedule Manager / Bus Calendar powers
// that ride on schedules.manage. Managers hold both, so nothing tightens.
$canReschedule = Auth::can('bookings.edit') && Auth::can('schedules.edit');
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

$b = $pnr !== '' ? BookingService::detail($pnr) : null;
// Same agent-scope disclosure guard as booking-view.php.
$scopeId = Auth::bookingScopeAdminId();
if ($b !== null && $scopeId !== null && (int) ($b['sold_by_admin_id'] ?? 0) !== $scopeId) {
    $b = null;
}

/* ---- Perform the reschedule -------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'reschedule') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canReschedule) {
        $flash = ['bad', 'You do not have permission to reschedule bookings.'];
    } elseif ($b === null) {
        $flash = ['bad', 'Booking not found.'];
    } else {
        try {
            $newDate      = Security::clean($_POST['new_date'] ?? '', 10);
            $newScheduleId = (int) ($_POST['new_schedule_id'] ?? 0);
            $seats        = array_map(
                static fn($s) => strtoupper(Security::clean((string) $s, 10)),
                (array) ($_POST['seats'] ?? [])
            );
            $reason = Security::clean($_POST['reason'] ?? '', 255);   // SHG AI BRAIN 1.6: the desk's reason rides with the audit row
            BookingService::rebookLeg((int) $b['id'], $newScheduleId, $seats, $newDate, (int) $admin['id'], $reason);
            // Display-only row-letter labels; $seats stays canonical for the engine.
            // Coach not loaded in this handler → 'sleeper'; $bookingMode not yet in scope → 'sharing'.
            $seatLabels = array_map(static fn($s) => Seats::displayLabel((string) $s, 'sleeper', 'sharing'), $seats);
            $flash = ['ok', 'Rescheduled to ' . formatDate($newDate) . ' · seat(s) ' . implode(', ', $seatLabels) . '. Ticket re-issued.'];
            // Re-fetch so the "current trip" card below shows the NEW leg.
            $b = BookingService::detail($pnr);
            /* The passenger learns of the new date from us, not at the bus (SHG AI
               BRAIN 1.6, 10 Sep 2026): the standard ticket WhatsApp with the freshly
               re-issued PNG. Best effort — an outage never undoes the reschedule. */
            if ($b !== null && Settings::getBool('notify_ticket_edit', true) && (string) ($b['status'] ?? '') === 'confirmed') {
                try {
                    require_once INCLUDE_PATH . '/notify.php';
                    $nr = Notify::resendTicketWhatsApp($b);
                    Logger::audit('booking.edit_notify', 'booking', $pnr, null,
                        ['what' => 'reschedule', 'ok' => (bool) ($nr['ok'] ?? false), 'to' => (string) ($b['contact_phone'] ?? '')],
                        (string) ($nr['detail'] ?? ''));
                    $flash[1] .= ($nr['ok'] ?? false) ? ' Passenger notified on WhatsApp.' : ' (WhatsApp not sent: ' . truncate((string) ($nr['detail'] ?? ''), 120) . ')';
                } catch (Throwable $e) {
                    Logger::error('Reschedule notify failed: ' . $e->getMessage(), ['pnr' => $pnr]);
                }
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

admin_header('Reschedule booking', 'bookings');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}

if ($b === null) {
    echo '<div class="panel"><h2>Booking not found</h2><div style="padding:18px">No booking matches that reference, or it is not yours to manage.</div></div>';
    admin_footer();
    exit;
}
if (!$canReschedule) {
    echo '<div class="flash bad">Rescheduling needs both booking-edit and schedule-management rights.</div>';
    admin_footer();
    exit;
}

/* ---- Current outbound leg ---------------------------------------- */
$bookingId = (int) $b['id'];
$leg = Database::fetch(
    "SELECT bl.id, bl.schedule_id, bl.travel_date, bl.seat_count,
            s.route_id, r.from_city, r.to_city, r.coach_type, r.route_code
       FROM booking_legs bl
       JOIN schedules s ON s.id = bl.schedule_id
       JOIN routes r    ON r.id = s.route_id
      WHERE bl.booking_id = :b AND bl.leg_type = 'outbound'
      ORDER BY bl.id LIMIT 1",
    ['b' => $bookingId]
);

if ($leg === null) {
    echo '<div class="panel"><h2>No outbound leg</h2><div style="padding:18px">This booking has nothing to move.</div></div>';
    admin_footer();
    exit;
}

$routeId       = (int) $leg['route_id'];
$curScheduleId = (int) $leg['schedule_id'];
$curDate       = (string) $leg['travel_date'];
$seatCount     = (int) $leg['seat_count'];
$bookingMode   = (string) ($b['booking_mode'] ?? 'sharing');
$status        = (string) $b['status'];

$curSeats = pluck(Database::fetchAll(
    'SELECT seat_no FROM booking_seats WHERE booking_id = :b AND leg_id = :l ORDER BY seat_no',
    ['b' => $bookingId, 'l' => (int) $leg['id']]
), 'seat_no');

$srcGate  = TripStatus::editableFor($curScheduleId);
$srcMoveable = ($srcGate['editable'] ?? false) || Auth::isSuperadmin();

/* ---- Upcoming same-route dates (existing schedules only) ---------- */
$upcoming = Database::fetchAll(
    "SELECT s.id, s.travel_date,
            (s.total_seats - s.seats_booked) AS free
       FROM schedules s
      WHERE s.route_id = :r AND s.travel_date >= :today AND s.id <> :cur
      ORDER BY s.travel_date ASC
      LIMIT 45",
    ['r' => $routeId, 'today' => todayISO(), 'cur' => $curScheduleId]
);

/* ---- Chosen target date + its open seats -------------------------- */
$targetDate = Security::clean($_GET['date'] ?? '', 10);
$targetSchedule = null;
$openSeats = [];
$targetGate = null;
if ($targetDate !== '' && Security::isValidDate($targetDate)) {
    $targetSchedule = Database::fetch(
        'SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1 LIMIT 1',
        ['r' => $routeId, 'd' => $targetDate]
    );
    if ($targetSchedule !== null) {
        $targetGate = TripStatus::editableFor((int) $targetSchedule['id']);
        try {
            $avail = Seats::availability($routeId, $targetDate, $bookingMode);
            $openSeats = $avail['available'] ?? [];
        } catch (Throwable $e) {
            $openSeats = [];
        }
    }
}
?>
<style>
.rs-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 20px;margin-bottom:18px}
.rs-card h2{margin:0 0 12px;font-size:15px}
.rs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px 22px}
.rs-grid dt{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;margin:0}
.rs-grid dd{margin:2px 0 8px;font-size:14px;font-weight:700}
.rs-dates{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
.rs-date{display:inline-flex;flex-direction:column;align-items:center;gap:2px;padding:8px 12px;border:1px solid var(--line);
  border-radius:10px;text-decoration:none;color:var(--ink);font-size:13px;min-width:70px}
.rs-date:hover{background:var(--hover)}
.rs-date.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.rs-date small{font-size:11px;color:var(--mut)}
.rs-date.on small{color:#cdd6e6}
.rs-seats-wrap{margin:12px 0;display:flex;flex-direction:column;gap:14px}
.rs-deck-head{font-size:11px;font-weight:700;color:var(--mut);letter-spacing:.04em;text-transform:uppercase;margin-bottom:-4px}
.rs-seats{display:grid;grid-template-columns:repeat(auto-fill,minmax(64px,1fr));gap:8px}
.rs-seat{position:relative}
.rs-seat input{position:absolute;opacity:0;pointer-events:none}
.rs-seat label{display:block;text-align:center;padding:10px 6px;border:2px solid var(--line);border-radius:9px;
  cursor:pointer;font-weight:800;font-family:ui-monospace,Consolas,monospace;font-size:13px}
.rs-seat input:checked + label{background:#0a6b3b;color:#fff;border-color:#0a6b3b}
.rs-hint{font-size:13px;color:var(--mut);margin:4px 0 12px}
@media(pointer:coarse){.rs-date,.rs-seat label{min-height:44px}}
</style>

<div class="rs-card">
  <h2>Current trip · <span class="mono"><?= Security::e($pnr) ?></span></h2>
  <dl class="rs-grid">
    <div><dt>Route</dt><dd><?= Security::e($leg['from_city'] . ' → ' . $leg['to_city']) ?></dd></div>
    <div><dt>Travel date</dt><dd><?= Security::e(formatDate($curDate)) ?></dd></div>
    <div><dt>Seat(s)</dt><dd class="mono"><?= Security::e(implode(', ', $curSeats) ?: '—') ?></dd></div>
    <div><dt>Status</dt><dd><?= admin_pill($status) ?></dd></div>
    <div><dt>Trip state</dt><dd><?= Security::e((string) ($srcGate['label'] ?? '—')) ?></dd></div>
  </dl>
  <p class="rs-hint">Same-route date change only — fare, payment, PNR and commission are preserved. For a different route, cancel &amp; rebook.</p>
  <a class="btn ghost" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($pnr) ?>">← Back to booking</a>
</div>

<?php if (!in_array($status, ['pending', 'confirmed'], true)): ?>
  <div class="flash bad">Only a pending or confirmed booking can be rescheduled (this one is “<?= Security::e($status) ?>”).</div>
<?php elseif (!$srcMoveable): ?>
  <div class="flash bad">This trip is <?= Security::e((string) ($srcGate['label'] ?? 'closed')) ?> — it can no longer be moved.
    <?php if (in_array($status, ['pending', 'confirmed'], true)): ?>
      If the passenger <strong>missed this bus</strong>, use
      <a href="<?= $base ?>/admin/missed-bus.php?pnr=<?= urlencode($pnr) ?>">Missed-bus rebooking (24h grace)</a> instead.
    <?php endif; ?>
  </div>
<?php else: ?>

<div class="rs-card">
  <h2>1 · Pick a new date <span class="muted" style="font-weight:500">(same route)</span></h2>
  <?php if ($upcoming === []): ?>
    <p class="rs-hint">No other scheduled dates on this route yet.</p>
  <?php else: ?>
  <div class="rs-dates">
    <?php foreach ($upcoming as $u):
      $ud = (string) $u['travel_date'];
      $free = (int) $u['free'];
    ?>
      <a class="rs-date<?= $ud === $targetDate ? ' on' : '' ?>"
         href="<?= $base ?>/admin/reschedule.php?pnr=<?= urlencode($pnr) ?>&date=<?= urlencode($ud) ?>">
        <span><?= Security::e(date('d M', strtotime($ud))) ?></span>
        <small><?= Security::e(date('D', strtotime($ud))) ?> · <?= $free ?> free</small>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <form method="get" style="margin-top:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="hidden" name="pnr" value="<?= Security::e($pnr) ?>">
    <label>Or any date <input type="date" name="date" value="<?= Security::e($targetDate) ?>" min="<?= Security::e(todayISO()) ?>"></label>
    <button class="btn ghost" type="submit">Show seats</button>
  </form>
</div>

<?php if ($targetDate !== ''): ?>
  <?php if ($targetSchedule === null): ?>
    <div class="flash bad">No service on this route on <?= Security::e(formatDate($targetDate)) ?>.</div>
  <?php elseif ((int) $targetSchedule['id'] === $curScheduleId): ?>
    <div class="flash bad">That is the current trip — pick a different date.</div>
  <?php elseif (!($targetGate['bookable'] ?? false) && !Auth::isSuperadmin()): ?>
    <div class="flash bad">The trip on <?= Security::e(formatDate($targetDate)) ?> is <?= Security::e((string) ($targetGate['label'] ?? 'not bookable')) ?>.</div>
  <?php elseif (count($openSeats) < $seatCount): ?>
    <div class="flash bad">Only <?= count($openSeats) ?> free seat(s) on <?= Security::e(formatDate($targetDate)) ?> — this booking needs <?= $seatCount ?>.</div>
  <?php else: ?>
  <div class="rs-card">
    <h2>2 · Choose <?= $seatCount ?> seat<?= $seatCount === 1 ? '' : 's' ?> on <?= Security::e(formatDate($targetDate)) ?></h2>
    <form method="post" id="rsForm" onsubmit="return rsValidate()">
      <p style="margin:0 0 10px"><input type="text" name="reason" maxlength="255" placeholder="Reason for the date change (written to the audit trail)" style="width:100%;max-width:440px;padding:8px 10px;border:1px solid var(--line);border-radius:8px"></p>
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="reschedule">
      <input type="hidden" name="pnr" value="<?= Security::e($pnr) ?>">
      <input type="hidden" name="new_date" value="<?= Security::e($targetDate) ?>">
      <input type="hidden" name="new_schedule_id" value="<?= (int) $targetSchedule['id'] ?>">
      <?php
        /* Group free seats by deck using Seats::layoutFor() — the same
           source-of-truth every seat surface uses since the 29 Aug 2026
           unification. Header only shows when a coach has >1 deck. */
        $rsCoach   = (string) ($leg['coach_type'] ?? 'sleeper');
        $rsLayout  = Seats::layoutFor($rsCoach, $bookingMode);
        $openFlip  = array_flip($openSeats);
        $decksData = [];
        foreach ($rsLayout['decks'] as $deck) {
            $inDeck = [];
            foreach ($deck['rows'] as $row) {
                foreach (array_merge($row['left'] ?? [], $row['right'] ?? []) as $sid) {
                    if (isset($openFlip[$sid])) { $inDeck[] = $sid; }
                }
            }
            if ($inDeck !== []) { $decksData[] = ['label' => $deck['label'], 'seats' => $inDeck]; }
        }
        // Anything the layout didn't cover (shouldn't happen, but keeps
        // historical/oddly-labelled seats visible instead of vanishing).
        $covered = [];
        foreach ($decksData as $d) { foreach ($d['seats'] as $s) { $covered[$s] = 1; } }
        $orphans = array_values(array_diff($openSeats, array_keys($covered)));
        if ($orphans !== []) { $decksData[] = ['label' => 'Other', 'seats' => $orphans]; }
      ?>
      <div class="rs-seats-wrap">
        <?php foreach ($decksData as $deck): ?>
          <?php if (count($decksData) > 1): ?>
            <div class="rs-deck-head"><?= Security::e((string) $deck['label']) ?> · <?= count($deck['seats']) ?> free</div>
          <?php endif; ?>
          <div class="rs-seats">
            <?php foreach ($deck['seats'] as $seat): ?>
              <span class="rs-seat">
                <input type="checkbox" name="seats[]" value="<?= Security::e($seat) ?>" id="rs-<?= Security::e($seat) ?>" onchange="rsSync()">
                <label for="rs-<?= Security::e($seat) ?>"><?= Security::e(Seats::displayLabel((string) $seat, $rsCoach ?? 'sleeper', (string) ($bookingMode ?? 'sharing'))) ?></label>
              </span>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="rs-hint"><span id="rsCount">0</span> / <?= $seatCount ?> selected. The shared-cabin gender rule still applies.</p>
      <button class="btn ok" type="submit" id="rsGo" disabled
              onclick="return confirm('Move this booking to <?= Security::e(formatDate($targetDate)) ?>? The ticket QR will be re-issued.')">
        Reschedule to <?= Security::e(formatDate($targetDate)) ?>
      </button>
    </form>
  </div>
  <script>
    var RS_NEED = <?= (int) $seatCount ?>;
    function rsSync(){
      var boxes = document.querySelectorAll('#rsForm input[name="seats[]"]');
      var n = 0; boxes.forEach(function(b){ if(b.checked) n++; });
      // cap at the required count
      if (n > RS_NEED) { event.target.checked = false; n = RS_NEED; }
      document.getElementById('rsCount').textContent = n;
      document.getElementById('rsGo').disabled = (n !== RS_NEED);
    }
    function rsValidate(){
      var n = document.querySelectorAll('#rsForm input[name="seats[]"]:checked').length;
      return n === RS_NEED;
    }
  </script>
  <?php endif; ?>
<?php endif; ?>

<?php endif; /* srcMoveable */ ?>

<?php
admin_footer();
