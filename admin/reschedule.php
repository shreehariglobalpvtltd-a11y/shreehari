<?php
/**
 * admin/reschedule.php?pnr=...[&leg=outbound|return][&date=YYYY-MM-DD][&sid=N]
 * — move one leg of a booking to a different DEPARTURE on the SAME route
 * (change-date / change-trip), optionally onto different seats, keeping the
 * same PNR / payment / commission. Thin UI over BookingService::rebookLeg();
 * all the money/seat/ticket safety lives there.
 *
 * 17 Sep 2026: every departure on the target date is offered (the daily bus
 * is slot 1; Bus-Calendar extra buses are slot 2+ and have their own seats,
 * coach and time), the seat picker and labels use the chosen bus's real
 * coach, ?leg= selects the outbound or return leg, and the passenger is told
 * WHAT changed (old date → new date · seats) via Notify::ticketChanged().
 *
 * Access: staff holding bookings.edit OR schedules.edit — managers,
 * superadmin, the counter role (since 5 Sep 2026 counter staff move dates
 * for walk-ins) and, since 17 Sep 2026, agents for THEIR OWN sales (the
 * engine refuses any booking the agent did not sell; foreign PNRs read as
 * not found here).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';
$admin = admin_boot('bookings.view');

$base  = '';
$pnr   = Security::clean($_GET['pnr'] ?? ($_POST['pnr'] ?? ''), 40);
$flash = null;

// schedules.edit (not .manage) since 5 Sep 2026: counter staff move dates
// for walk-ins, but must not gain the Schedule Manager / Bus Calendar powers
// that ride on schedules.manage. Managers hold both, so nothing tightens.
// 17 Sep 2026: agents (schedules.edit only) may now move THEIR OWN sales too —
// the same door missed-bus.php already opens; rebookLeg() refuses a booking
// the agent did not sell and this page hides foreign PNRs ($scopeId below).
$canReschedule = Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin();
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

// Which leg to move. Only 'outbound' / 'return' exist; anything else is the
// outbound leg exactly as before this parameter was added.
$legType = (string) ($_GET['leg'] ?? ($_POST['leg'] ?? 'outbound'));
if (!in_array($legType, ['outbound', 'return'], true)) {
    $legType = 'outbound';
}

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
            $newDate       = Security::clean($_POST['new_date'] ?? '', 10);
            $newScheduleId = (int) ($_POST['new_schedule_id'] ?? 0);
            $seats         = array_map(
                static fn($s) => strtoupper(Security::clean((string) $s, 10)),
                (array) ($_POST['seats'] ?? [])
            );
            $reason = Security::clean($_POST['reason'] ?? '', 255);   // SHG AI BRAIN 1.6: the desk's reason rides with the audit row

            // What the passenger is leaving — read BEFORE the move so the
            // change notice can say "17 Sep → 18 Sep". Display only.
            $oldLeg  = Database::fetch(
                'SELECT id, travel_date FROM booking_legs WHERE booking_id = :b AND leg_type = :lt ORDER BY id LIMIT 1',
                ['b' => (int) $b['id'], 'lt' => $legType]
            );
            $oldDate = (string) ($oldLeg['travel_date'] ?? '');

            BookingService::rebookLeg((int) $b['id'], $newScheduleId, $seats, $newDate, (int) $admin['id'], $reason, $legType);

            // Display-only row-letter labels in the TARGET bus's real coach;
            // $seats stays canonical for the engine.
            $newCoach   = Seats::coachForSchedule($newScheduleId);
            $newMode    = (string) ($b['booking_mode'] ?? 'sharing');
            $seatLabels = array_map(static fn($s) => Seats::displayLabel((string) $s, $newCoach, $newMode), $seats);
            $flash = ['ok', 'Rescheduled to ' . formatDate($newDate) . ' · seat(s) ' . implode(', ', $seatLabels) . '. Ticket re-issued.'];
            // Re-fetch so the "current trip" card below shows the NEW leg.
            $b = BookingService::detail($pnr);
            /* The passenger learns of the new date from us, not at the bus (SHG AI
               BRAIN 1.6, 10 Sep 2026) — and since 17 Sep 2026 the message SAYS
               the date moved (old → new · seats) with the freshly re-issued PNG.
               Best effort — an outage never undoes the reschedule. */
            if ($b !== null && Settings::getBool('notify_ticket_edit', true) && (string) ($b['status'] ?? '') === 'confirmed') {
                try {
                    require_once INCLUDE_PATH . '/notify.php';
                    $nr = Notify::ticketChanged($b, 'reschedule', [
                        'oldDate' => $oldDate,
                        'newDate' => $newDate,
                        'seats'   => $seatLabels,
                    ]);
                    Logger::audit('booking.edit_notify', 'booking', $pnr, null,
                        ['what' => 'reschedule', 'leg' => $legType, 'ok' => (bool) ($nr['ok'] ?? false), 'to' => (string) ($b['contact_phone'] ?? '')],
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

$backHref = $base . '/admin/booking-view.php?pnr=' . urlencode($pnr);
admin_page_head(
    'Same-route date / departure change — fare, payment, PNR and commission are preserved.',
    ['Bookings' => $base . '/admin/bookings.php', ($pnr !== '' ? $pnr : 'Booking') => $backHref, 'Reschedule' => ''],
    '<a class="btn ghost" href="' . Security::e($backHref) . '"><svg class="a-ic"><use href="#a-arrow-left"/></svg> Back to booking</a>'
);

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}

if ($b === null) {
    echo admin_empty('Booking not found', 'No booking matches that reference, or it is not yours to manage.', '🔎');
    admin_footer();
    exit;
}
if (!$canReschedule) {
    echo '<div class="flash bad">Rescheduling needs booking-edit or schedule-edit rights.</div>';
    admin_footer();
    exit;
}

/* ---- The leg being moved ------------------------------------------ */
$bookingId = (int) $b['id'];
$hasReturn = (int) Database::scalar(
    "SELECT COUNT(*) FROM booking_legs WHERE booking_id = :b AND leg_type = 'return'",
    ['b' => $bookingId], 0
) > 0;
$leg = Database::fetch(
    "SELECT bl.id, bl.schedule_id, bl.travel_date, bl.seat_count,
            s.route_id, s.slot, r.from_city, r.to_city, r.coach_type, r.route_code,
            COALESCE(s.coach_type_override, r.coach_type) AS cur_coach,
            COALESCE(s.dep_time_override, r.dep_time) AS cur_dep_time
       FROM booking_legs bl
       JOIN schedules s ON s.id = bl.schedule_id
       JOIN routes r    ON r.id = s.route_id
      WHERE bl.booking_id = :b AND bl.leg_type = :lt
      ORDER BY bl.id LIMIT 1",
    ['b' => $bookingId, 'lt' => $legType]
);

if ($leg === null) {
    echo admin_empty('No ' . $legType . ' leg', 'This booking has no ' . $legType . ' leg to move.', '🚌');
    admin_footer();
    exit;
}

$routeId       = (int) $leg['route_id'];
$curScheduleId = (int) $leg['schedule_id'];
$curDate       = (string) $leg['travel_date'];
$curCoach      = (string) ($leg['cur_coach'] ?? 'sleeper');
$seatCount     = (int) $leg['seat_count'];
$bookingMode   = (string) ($b['booking_mode'] ?? 'sharing');
$status        = (string) $b['status'];

$curSeats = pluck(Database::fetchAll(
    'SELECT seat_no FROM booking_seats WHERE booking_id = :b AND leg_id = :l ORDER BY seat_no',
    ['b' => $bookingId, 'l' => (int) $leg['id']]
), 'seat_no');
$curSeatLabels = array_map(static fn($s) => Seats::displayLabel((string) $s, $curCoach, $bookingMode), $curSeats);

$srcGate     = TripStatus::editableFor($curScheduleId);
$srcMoveable = ($srcGate['editable'] ?? false) || Auth::isSuperadmin();

/* ---- Upcoming same-route dates (one chip per date, all buses) ------ */
$upcoming = Database::fetchAll(
    "SELECT s.travel_date,
            COUNT(*) AS buses,
            SUM(s.total_seats - s.seats_booked) AS free
       FROM schedules s
      WHERE s.route_id = :r AND s.travel_date >= :today
        AND s.is_blocked = 0 AND s.status <> 'cancelled'
      GROUP BY s.travel_date
      ORDER BY s.travel_date ASC
      LIMIT 45",
    ['r' => $routeId, 'today' => todayISO()]
);

/* ---- Chosen target date → its departures → the chosen bus's seats ---
   Every schedule on the date is listed (slot 1 = daily bus, 2+ = extra buses
   from the Bus Calendar). rebookLeg() accepts any same-route schedule id, so
   the desk may move a passenger onto the extra bus — or onto the OTHER bus
   of the same day. Blocked departures are hidden; a cancelled one shows its
   state and is not selectable. */
$targetDate     = Security::clean($_GET['date'] ?? '', 10);
$departures     = [];
$targetSchedule = null;
$targetSid      = (int) ($_GET['sid'] ?? 0);
$targetCoach    = $curCoach;
$openSeats      = [];
$targetGate     = null;
if ($targetDate !== '' && Security::isValidDate($targetDate)) {
    $departures = Database::fetchAll(
        'SELECT s.id, s.slot, s.status,
                COALESCE(s.coach_type_override, r.coach_type) AS coach_type,
                COALESCE(s.dep_time_override, r.dep_time)     AS dep_time,
                (s.total_seats - s.seats_booked)              AS free
           FROM schedules s
           JOIN routes r ON r.id = s.route_id
          WHERE s.route_id = :r AND s.travel_date = :d AND s.is_blocked = 0
          ORDER BY s.slot ASC, s.id ASC',
        ['r' => $routeId, 'd' => $targetDate]
    );
    // Default to the first departure that is not the current trip.
    $ids = array_map(static fn($d) => (int) $d['id'], $departures);
    if ($targetSid <= 0 || !in_array($targetSid, $ids, true)) {
        $targetSid = 0;
        foreach ($ids as $id) {
            if ($id !== $curScheduleId) { $targetSid = $id; break; }
        }
    }
    foreach ($departures as $d) {
        if ((int) $d['id'] === $targetSid) { $targetSchedule = $d; break; }
    }
    if ($targetSchedule !== null) {
        $targetGate  = TripStatus::editableFor($targetSid);
        $targetCoach = Seats::coachForSchedule($targetSid);
        try {
            $avail = Seats::availability($routeId, $targetDate, $bookingMode, $targetSid);
            $openSeats = $avail['available'] ?? [];
        } catch (Throwable $e) {
            $openSeats = [];
        }
    }
}

/** "Bus 2 · 07:30 · sleeper" — the label a departure chip shows. */
function rsDepLabel(array $d): string
{
    $t = substr((string) ($d['dep_time'] ?? ''), 0, 5);
    return 'Bus ' . (int) ($d['slot'] ?? 1)
        . ($t !== '' ? ' · ' . $t : '')
        . ' · ' . ucfirst((string) ($d['coach_type'] ?? 'sleeper'));
}
/** Page URL for a given leg / date / departure. */
function rsUrl(string $pnr, string $leg, string $date = '', int $sid = 0): string
{
    $q = ['pnr' => $pnr, 'leg' => $leg];
    if ($date !== '') { $q['date'] = $date; }
    if ($sid > 0)     { $q['sid']  = $sid; }
    return '/admin/reschedule.php?' . http_build_query($q);
}
?>
<style>
.rs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px 22px;margin:0}
.rs-grid dt{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;margin:0}
.rs-grid dd{margin:2px 0 8px;font-size:14px;font-weight:700}
.rs-dates{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
.rs-date{display:inline-flex;flex-direction:column;align-items:center;gap:2px;padding:8px 12px;border:1px solid var(--line);
  border-radius:10px;text-decoration:none;color:var(--ink);font-size:13px;min-width:70px}
.rs-date:hover{background:var(--hover)}
.rs-date.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.rs-date small{font-size:11px;color:var(--mut)}
.rs-date.on small{color:#cdd6e6}
.rs-deps{margin:6px 0 14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center}
.rs-deps .seg{flex-wrap:wrap}
.rs-deps .seg a small,.rs-deps .seg span small{font-weight:600;opacity:.75;margin-left:6px}
.rs-deps .seg span.cur{padding:7px 13px;font-size:13px;font-weight:700;color:var(--mut);opacity:.6;cursor:not-allowed}
.rs-seats-wrap{margin:12px 0;display:flex;flex-direction:column;gap:14px}
.rs-deck-head{font-size:11px;font-weight:700;color:var(--mut);letter-spacing:.04em;text-transform:uppercase;margin-bottom:-4px}
.rs-seats{display:grid;grid-template-columns:repeat(auto-fill,minmax(64px,1fr));gap:8px}
.rs-seat{position:relative}
.rs-seat input{position:absolute;opacity:0;pointer-events:none}
.rs-seat label{display:block;text-align:center;padding:10px 6px;border:2px solid var(--line);border-radius:9px;
  cursor:pointer;font-weight:800;font-family:ui-monospace,Consolas,monospace;font-size:13px}
.rs-seat input:checked + label{background:#0a6b3b;color:#fff;border-color:#0a6b3b}
.rs-hint{font-size:13px;color:var(--mut);margin:4px 0 12px}
.rs-reason input{width:100%;max-width:440px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink)}
@media(pointer:coarse){.rs-date,.rs-seat label{min-height:44px}}
</style>

<div class="panel">
  <h2><svg class="a-ic"><use href="#a-ticket"/></svg> Current trip · <span class="mono"><?= Security::e($pnr) ?></span>
    <?php if ($hasReturn): ?>
      <span class="seg" style="margin-left:auto">
        <a class="<?= $legType === 'outbound' ? 'on' : '' ?>" href="<?= Security::e(rsUrl($pnr, 'outbound')) ?>">Outbound</a>
        <a class="<?= $legType === 'return' ? 'on' : '' ?>" href="<?= Security::e(rsUrl($pnr, 'return')) ?>">Return</a>
      </span>
    <?php endif; ?>
  </h2>
  <div class="panel-body">
    <dl class="rs-grid">
      <div><dt>Route</dt><dd><?= Security::e($leg['from_city'] . ' → ' . $leg['to_city']) ?></dd></div>
      <div><dt>Leg</dt><dd><?= Security::e(ucfirst($legType)) ?></dd></div>
      <div><dt>Travel date</dt><dd><?= Security::e(formatDate($curDate)) ?></dd></div>
      <div><dt>Departure</dt><dd>Bus <?= (int) ($leg['slot'] ?? 1) ?> · <?= Security::e(substr((string) ($leg['cur_dep_time'] ?? ''), 0, 5) ?: '—') ?> · <?= Security::e(ucfirst($curCoach)) ?></dd></div>
      <div><dt>Seat(s)</dt><dd class="mono"><?= Security::e(implode(', ', $curSeatLabels) ?: '—') ?></dd></div>
      <div><dt>Status</dt><dd><?= admin_pill($status) ?></dd></div>
      <div><dt>Trip state</dt><dd><?= Security::e((string) ($srcGate['label'] ?? '—')) ?></dd></div>
    </dl>
    <p class="rs-hint" style="margin-bottom:0">Same-route change only — fare, payment, PNR and commission are preserved. For a different route, cancel &amp; rebook.</p>
  </div>
</div>

<?php if (!in_array($status, ['pending', 'confirmed'], true)): ?>
  <div class="flash bad">Only a pending or confirmed booking can be rescheduled (this one is “<?= Security::e($status) ?>”).</div>
<?php elseif (!$srcMoveable): ?>
  <div class="flash bad"><div>This trip is <?= Security::e((string) ($srcGate['label'] ?? 'closed')) ?> — it can no longer be moved.
    <?php if (in_array($status, ['pending', 'confirmed'], true)): ?>
      If the passenger <strong>missed this bus</strong>, use
      <a href="<?= $base ?>/admin/missed-bus.php?pnr=<?= urlencode($pnr) ?>">Missed-bus rebooking (24h grace)</a> instead.
    <?php endif; ?>
  </div></div>
<?php else: ?>

<div class="panel">
  <h2><svg class="a-ic"><use href="#a-calendar-move"/></svg> 1 · Pick a new date <span class="muted" style="font-weight:500">(same route)</span></h2>
  <div class="panel-body">
  <?php if ($upcoming === []): ?>
    <p class="rs-hint">No other scheduled dates on this route yet.</p>
  <?php else: ?>
  <div class="rs-dates">
    <?php foreach ($upcoming as $u):
      $ud    = (string) $u['travel_date'];
      $free  = (int) $u['free'];
      $buses = (int) $u['buses'];
      // The current date is only worth offering when it has ANOTHER bus.
      if ($ud === $curDate && $buses <= 1) { continue; }
    ?>
      <a class="rs-date<?= $ud === $targetDate ? ' on' : '' ?>" href="<?= Security::e(rsUrl($pnr, $legType, $ud)) ?>">
        <span><?= Security::e(date('d M', strtotime($ud))) ?></span>
        <small><?= Security::e(date('D', strtotime($ud))) ?> · <?= $free ?> free<?= $buses > 1 ? ' · ' . $buses . ' buses' : '' ?></small>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <form method="get" class="form-actions" style="margin-top:12px">
    <input type="hidden" name="pnr" value="<?= Security::e($pnr) ?>">
    <input type="hidden" name="leg" value="<?= Security::e($legType) ?>">
    <label>Or any date <input type="date" name="date" value="<?= Security::e($targetDate) ?>" min="<?= Security::e(todayISO()) ?>"></label>
    <button class="btn ghost" type="submit"><svg class="a-ic"><use href="#a-search"/></svg> Show departures</button>
  </form>
  </div>
</div>

<?php if ($targetDate !== ''): ?>
  <?php if ($departures === []): ?>
    <div class="flash bad">No service on this route on <?= Security::e(formatDate($targetDate)) ?>.</div>
  <?php elseif ($targetSchedule === null): ?>
    <div class="flash bad">The only departure on <?= Security::e(formatDate($targetDate)) ?> is the current trip — pick a different date.</div>
  <?php else: ?>

  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-bus"/></svg> 2 · Choose the departure on <?= Security::e(formatDate($targetDate)) ?></h2>
    <div class="panel-body">
      <div class="rs-deps">
        <span class="seg">
          <?php foreach ($departures as $d):
            $did = (int) $d['id'];
            $lbl = rsDepLabel($d);
            $sub = (int) $d['free'] . ' free' . ((string) $d['status'] === 'cancelled' ? ' · cancelled' : '');
          ?>
            <?php if ($did === $curScheduleId): ?>
              <span class="cur" title="This is the current trip"><?= Security::e($lbl) ?><small>current</small></span>
            <?php else: ?>
              <a class="<?= $did === $targetSid ? 'on' : '' ?>" href="<?= Security::e(rsUrl($pnr, $legType, $targetDate, $did)) ?>"><?= Security::e($lbl) ?><small><?= Security::e($sub) ?></small></a>
            <?php endif; ?>
          <?php endforeach; ?>
        </span>
        <?php if (count($departures) > 1): ?>
          <span class="rs-hint" style="margin:0">Extra buses from the Bus Calendar run their own coach, time and seat map.</span>
        <?php endif; ?>
      </div>

      <?php if (!($targetGate['bookable'] ?? false) && !Auth::isSuperadmin()): ?>
        <div class="flash bad" style="margin-bottom:0"><?= Security::e(rsDepLabel($targetSchedule)) ?> on <?= Security::e(formatDate($targetDate)) ?> is <?= Security::e((string) ($targetGate['label'] ?? 'not bookable')) ?>.</div>
      <?php elseif (count($openSeats) < $seatCount): ?>
        <div class="flash bad" style="margin-bottom:0">Only <?= count($openSeats) ?> free seat(s) on <?= Security::e(rsDepLabel($targetSchedule)) ?>, <?= Security::e(formatDate($targetDate)) ?> — this booking needs <?= $seatCount ?>.</div>
      <?php else: ?>

      <h3 style="margin:18px 0 8px;font-size:14px">3 · Choose <?= $seatCount ?> seat<?= $seatCount === 1 ? '' : 's' ?> on <?= Security::e(rsDepLabel($targetSchedule)) ?></h3>
      <form method="post" id="rsForm" onsubmit="return rsValidate()">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="reschedule">
        <input type="hidden" name="pnr" value="<?= Security::e($pnr) ?>">
        <input type="hidden" name="leg" value="<?= Security::e($legType) ?>">
        <input type="hidden" name="new_date" value="<?= Security::e($targetDate) ?>">
        <input type="hidden" name="new_schedule_id" value="<?= (int) $targetSchedule['id'] ?>">
        <?php
          /* Group free seats by deck using Seats::layoutFor() — the same
             source-of-truth every seat surface uses since the 29 Aug 2026
             unification — in the TARGET bus's coach (an extra bus may run a
             seater while the route is sleeper). Header only shows when a
             coach has >1 deck. */
          $rsCoach   = $targetCoach !== '' ? $targetCoach : 'sleeper';
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
                  <input type="checkbox" name="seats[]" value="<?= Security::e($seat) ?>" id="rs-<?= Security::e($seat) ?>" onchange="rsSync(this)">
                  <label for="rs-<?= Security::e($seat) ?>"><?= Security::e(Seats::displayLabel((string) $seat, $rsCoach, $bookingMode)) ?></label>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <p class="rs-hint"><span id="rsCount">0</span> / <?= $seatCount ?> selected. The shared-cabin gender rule still applies.</p>
        <div class="field rs-reason">
          <label for="rsReason">Reason for the change <span class="muted">(written to the audit trail)</span></label>
          <input type="text" id="rsReason" name="reason" maxlength="255" placeholder="e.g. passenger asked to travel a day later">
        </div>
        <div class="form-actions">
          <button class="btn ok" type="submit" id="rsGo" disabled
                  onclick="return confirm('Move this booking to <?= Security::e(formatDate($targetDate)) ?> (<?= Security::e(rsDepLabel($targetSchedule)) ?>)? The ticket QR will be re-issued and the passenger notified.')">
            <svg class="a-ic"><use href="#a-calendar-move"/></svg> Reschedule to <?= Security::e(formatDate($targetDate)) ?>
          </button>
          <a class="btn ghost" href="<?= Security::e($backHref) ?>">Cancel</a>
        </div>
      </form>
      <script>
        var RS_NEED = <?= (int) $seatCount ?>;
        function rsSync(el){
          var boxes = document.querySelectorAll('#rsForm input[name="seats[]"]');
          var n = 0; boxes.forEach(function(b){ if(b.checked) n++; });
          // cap at the required count
          if (n > RS_NEED && el) { el.checked = false; n = RS_NEED; }
          document.getElementById('rsCount').textContent = n;
          document.getElementById('rsGo').disabled = (n !== RS_NEED);
        }
        function rsValidate(){
          var n = document.querySelectorAll('#rsForm input[name="seats[]"]:checked').length;
          return n === RS_NEED;
        }
      </script>
      <?php endif; /* gate + seats */ ?>
    </div>
  </div>
  <?php endif; /* departures */ ?>
<?php endif; /* targetDate */ ?>

<?php endif; /* srcMoveable */ ?>

<?php
admin_footer();
