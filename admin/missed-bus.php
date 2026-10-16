<?php
/**
 * admin/missed-bus.php?pnr=... — Point 11: "missed bus" 24-hour grace rebooking.
 *
 * A passenger who MISSED their booked departure keeps a usable ticket: staff —
 * or the SELLING AGENT under their own login — move them onto the next available
 * same-route service within 24h of the original departure, same PNR / fare /
 * payment / commission. Thin UI over BookingService::rebookMissedLeg(); the
 * window, seat, money and ticket safety all live there.
 *
 * Access: anyone who can create/edit bookings — managers, superadmin AND counter
 * agents (schedules.edit). Agents are scoped by rebookMissedLeg() to the
 * bookings THEY sold. Customers can never reach this. Every move is audit-logged
 * (who + agent code + original dep + new dep).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';
$admin = admin_boot('bookings.view');

$base  = '';
$pnr   = Security::clean($_GET['pnr'] ?? ($_POST['pnr'] ?? ''), 40);
$flash = null;

// Same gate as new-booking.php: managers (bookings.edit), agents (schedules.edit)
// and superadmin may act; pure view-only roles cannot.
$canMissed = Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin();
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

$b = $pnr !== '' ? BookingService::detail($pnr) : null;
// Agent-scope disclosure guard — an agent only sees bookings they sold.
$scopeId = Auth::bookingScopeAdminId();
if ($b !== null && $scopeId !== null && (int) ($b['sold_by_admin_id'] ?? 0) !== $scopeId) {
    $b = null;
}

/* ---- Perform the missed-bus rebooking ----------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'missed_rebook') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canMissed) {
        $flash = ['bad', 'You do not have permission to rebook bookings.'];
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
            // The missed date — read BEFORE the move so the passenger's notice
            // can say "17 Sep → 18 Sep". Display only.
            $oldDate = (string) Database::scalar(
                "SELECT travel_date FROM booking_legs WHERE booking_id = :b AND leg_type = 'outbound' ORDER BY id LIMIT 1",
                ['b' => (int) $b['id']], ''
            );
            BookingService::rebookMissedLeg((int) $b['id'], $newScheduleId, $seats, $newDate, (int) $admin['id']);
            // Display-only row-letter labels in the TARGET bus's real coach
            // (17 Sep 2026 — was a hard-coded 'sleeper'); $seats stays canonical.
            $newCoach   = Seats::coachForSchedule($newScheduleId);
            $newMode    = (string) ($b['booking_mode'] ?? 'sharing');
            $seatLabels = array_map(static fn($s) => Seats::displayLabel((string) $s, $newCoach, $newMode), $seats);
            $flash = ['ok', 'Missed-bus rebooked to ' . formatDate($newDate) . ' · seat(s) ' . implode(', ', $seatLabels) . '. Ticket re-issued and logged.'];
            $b = BookingService::detail($pnr);
            /* 17 Sep 2026: the passenger is told they were moved (old date →
               new date · seats) with the re-issued PNG — the missed-bus page
               used to send nothing at all. Same best-effort block as
               reschedule.php: an outage never undoes the rebooking. */
            if ($b !== null && Settings::getBool('notify_ticket_edit', true) && (string) ($b['status'] ?? '') === 'confirmed') {
                try {
                    require_once INCLUDE_PATH . '/notify.php';
                    $nr = Notify::ticketChanged($b, 'missed_rebook', [
                        'oldDate' => $oldDate,
                        'newDate' => $newDate,
                        'seats'   => $seatLabels,
                    ]);
                    Logger::audit('booking.edit_notify', 'booking', $pnr, null,
                        ['what' => 'missed_rebook', 'ok' => (bool) ($nr['ok'] ?? false), 'to' => (string) ($b['contact_phone'] ?? '')],
                        (string) ($nr['detail'] ?? ''));
                    $flash[1] .= ($nr['ok'] ?? false) ? ' Passenger notified on WhatsApp.' : ' (WhatsApp not sent: ' . truncate((string) ($nr['detail'] ?? ''), 120) . ')';
                } catch (Throwable $e) {
                    Logger::error('Missed-bus notify failed: ' . $e->getMessage(), ['pnr' => $pnr]);
                }
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

admin_header('Missed-bus rebooking', 'bookings');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}

if ($b === null) {
    echo '<div class="panel"><h2>Booking not found</h2><div style="padding:18px">No booking matches that reference, or it is not yours to manage.</div></div>';
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

$grace   = BookingService::missedGraceStatus($bookingId);
$isSuper = Auth::isSuperadmin();
// Superadmin may override the window for genuine edge cases; everyone else is
// held to "departed AND within 24h" exactly as the backend enforces.
$eligible = ($grace['ok'] ?? false) || $isSuper;

function graceRemainLabel(int $secs): string {
    $secs = max(0, $secs);
    $h = intdiv($secs, 3600); $m = intdiv($secs % 3600, 60);
    if ($h >= 1) { return $h . 'h ' . $m . 'm'; }
    return $m . 'm';
}

/* ---- Upcoming same-route dates (the next available services) ------ */
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
.mb-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 20px;margin-bottom:18px}
.mb-card h2{margin:0 0 12px;font-size:15px}
.mb-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px 22px}
.mb-grid dt{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;margin:0}
.mb-grid dd{margin:2px 0 8px;font-size:14px;font-weight:700}
.mb-banner{border-radius:12px;padding:12px 16px;margin-bottom:18px;font-size:14px;line-height:1.5}
.mb-banner.ok{background:#eafaf0;border:1px solid #bfe6cd;color:#0a5a30}
.mb-banner.warn{background:#fff4e5;border:1px solid #f4d6a6;color:#8a5300}
.mb-banner.bad{background:#fdecea;border:1px solid #f5c2bd;color:#9b271b}
:root[data-theme="dark"] .mb-banner.ok{background:#0f2e1e;border-color:#1c6b40;color:#7fe0a6}
:root[data-theme="dark"] .mb-banner.warn{background:#33240c;border-color:#7a5a20;color:#f0c073}
:root[data-theme="dark"] .mb-banner.bad{background:#3a1613;border-color:#7d2c24;color:#f0a79d}
.mb-dates{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
.mb-date{display:inline-flex;flex-direction:column;align-items:center;gap:2px;padding:8px 12px;border:1px solid var(--line);
  border-radius:10px;text-decoration:none;color:var(--ink);font-size:13px;min-width:70px}
.mb-date:hover{background:var(--hover)}
.mb-date.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.mb-date small{font-size:11px;color:var(--mut)}
.mb-date.on small{color:#cdd6e6}
.mb-seats-wrap{margin:12px 0;display:flex;flex-direction:column;gap:14px}
.mb-deck-head{font-size:11px;font-weight:700;color:var(--mut);letter-spacing:.04em;text-transform:uppercase;margin-bottom:-4px}
.mb-deck-head.floor-head{font-size:12.5px;color:#fff;text-transform:none;letter-spacing:.01em;margin-bottom:8px}
.mb-seats{display:grid;grid-template-columns:repeat(auto-fill,minmax(64px,1fr));gap:8px}
.mb-seat{position:relative}
.mb-seat input{position:absolute;opacity:0;pointer-events:none}
.mb-seat label{display:block;text-align:center;padding:10px 6px;border:2px solid var(--fl-line,var(--line));border-radius:11px;background:var(--card);
  cursor:pointer;font-weight:800;font-family:ui-monospace,Consolas,monospace;font-size:13px}
.mb-seat input:checked + label{background:#0a6b3b;color:#fff;border-color:#0a6b3b}
.mb-hint{font-size:13px;color:var(--mut);margin:4px 0 12px}
@media(pointer:coarse){.mb-date,.mb-seat label{min-height:44px}}
</style>

<div class="mb-card">
  <h2>Missed bus · <span class="mono"><?= Security::e($pnr) ?></span></h2>
  <dl class="mb-grid">
    <div><dt>Route</dt><dd><?= Security::e($leg['from_city'] . ' → ' . $leg['to_city']) ?></dd></div>
    <div><dt>Booked date</dt><dd><?= Security::e(formatDate($curDate)) ?></dd></div>
    <div><dt>Seat(s)</dt><dd class="mono"><?= Security::e(implode(', ', $curSeats) ?: '—') ?></dd></div>
    <div><dt>Status</dt><dd><?= admin_pill($status) ?></dd></div>
    <div><dt>Original departure</dt><dd><?= $grace['origDep'] ? Security::e(date('d M, H:i', (int) $grace['origDep'])) : '—' ?></dd></div>
  </dl>
  <p class="mb-hint">Same-route move within 24h of the missed departure — fare, payment, PNR and commission are kept. For a different route, cancel &amp; rebook.</p>
  <a class="btn ghost" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($pnr) ?>">← Back to booking</a>
</div>

<?php
/* ---- Eligibility banner ------------------------------------------ */
if (!in_array($status, ['pending', 'confirmed'], true)): ?>
  <div class="mb-banner bad">Only a pending or confirmed booking can be rebooked (this one is “<?= Security::e($status) ?>”).</div>
<?php elseif ($grace['reason'] === 'not_departed' && !$isSuper): ?>
  <div class="mb-banner warn">
    This bus <strong>has not departed yet</strong>. The missed-bus grace only applies once the booked departure has passed.
    To change the travel date now, use <a href="<?= $base ?>/admin/reschedule.php?pnr=<?= urlencode($pnr) ?>">Reschedule</a> instead.
  </div>
<?php elseif ($grace['reason'] === 'window_passed' && !$isSuper): ?>
  <div class="mb-banner bad">
    The <strong>24-hour missed-bus window has passed</strong><?= $grace['deadline'] ? ' (closed ' . Security::e(date('d M, H:i', (int) $grace['deadline'])) . ')' : '' ?>.
    Please cancel &amp; rebook as a new sale.
  </div>
<?php else: ?>
  <div class="mb-banner ok">
    <strong>Eligible for missed-bus rebooking.</strong>
    <?php if (!empty($grace['deadline'])): ?>
      Grace window closes <?= Security::e(date('d M, H:i', (int) $grace['deadline'])) ?>
      (<?= Security::e(graceRemainLabel((int) ($grace['remainingSec'] ?? 0))) ?> left).
    <?php endif; ?>
    <?php if ($isSuper && !($grace['ok'] ?? false)): ?>
      <br><em>Superadmin override — this booking is outside the normal window.</em>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$canMissed): ?>
  <div class="mb-banner bad">Your role can view bookings but not rebook them. Ask a manager or superadmin.</div>
<?php elseif ($eligible && in_array($status, ['pending', 'confirmed'], true)): ?>

<div class="mb-card">
  <h2>1 · Pick the next service <span class="muted" style="font-weight:500">(same route)</span></h2>
  <?php if ($upcoming === []): ?>
    <p class="mb-hint">No other scheduled dates on this route yet.</p>
  <?php else: ?>
  <div class="mb-dates">
    <?php foreach ($upcoming as $u):
      $ud = (string) $u['travel_date'];
      $free = (int) $u['free'];
    ?>
      <a class="mb-date<?= $ud === $targetDate ? ' on' : '' ?>"
         href="<?= $base ?>/admin/missed-bus.php?pnr=<?= urlencode($pnr) ?>&date=<?= urlencode($ud) ?>">
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
    <div class="mb-banner bad">No service on this route on <?= Security::e(formatDate($targetDate)) ?>.</div>
  <?php elseif ((int) $targetSchedule['id'] === $curScheduleId): ?>
    <div class="mb-banner bad">That is the missed trip — pick a different date.</div>
  <?php elseif (!($targetGate['counterBookable'] ?? false) && !$isSuper): ?>
    <div class="mb-banner bad">The trip on <?= Security::e(formatDate($targetDate)) ?> is <?= Security::e((string) ($targetGate['label'] ?? 'not bookable')) ?> and can no longer take a booking.</div>
  <?php elseif (count($openSeats) < $seatCount): ?>
    <div class="mb-banner bad">Only <?= count($openSeats) ?> free seat(s) on <?= Security::e(formatDate($targetDate)) ?> — this booking needs <?= $seatCount ?>.</div>
  <?php else: ?>
  <div class="mb-card">
    <h2>2 · Choose <?= $seatCount ?> seat<?= $seatCount === 1 ? '' : 's' ?> on <?= Security::e(formatDate($targetDate)) ?></h2>
    <form method="post" id="mbForm" onsubmit="return mbValidate()">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="missed_rebook">
      <input type="hidden" name="pnr" value="<?= Security::e($pnr) ?>">
      <input type="hidden" name="new_date" value="<?= Security::e($targetDate) ?>">
      <input type="hidden" name="new_schedule_id" value="<?= (int) $targetSchedule['id'] ?>">
      <?php
        $mbCoach   = (string) ($leg['coach_type'] ?? 'sleeper');
        $mbLayout  = Seats::layoutFor($mbCoach, $bookingMode);
        $openFlip  = array_flip($openSeats);
        $decksData = [];
        foreach ($mbLayout['decks'] as $deck) {
            $inDeck = [];
            foreach ($deck['rows'] as $row) {
                foreach (array_merge($row['left'] ?? [], $row['right'] ?? []) as $sid) {
                    if (isset($openFlip[$sid])) { $inDeck[] = $sid; }
                }
            }
            if ($inDeck !== []) { $decksData[] = ['label' => $deck['label'], 'key' => (string) $deck['key'], 'seats' => $inDeck]; }
        }
        $covered = [];
        foreach ($decksData as $d) { foreach ($d['seats'] as $s) { $covered[$s] = 1; } }
        $orphans = array_values(array_diff($openSeats, array_keys($covered)));
        if ($orphans !== []) { $decksData[] = ['label' => 'Other', 'key' => '', 'seats' => $orphans]; }
      ?>
      <div class="mb-seats-wrap">
        <?php foreach ($decksData as $deck):
          // Lower Floor (1F) blue / Upper Floor (2F) green (admin/_guard.php .floor-*).
          $isFloor = in_array($deck['key'], ['L', 'U'], true); ?>
          <div class="mb-floor<?= $isFloor ? ' floor-' . $deck['key'] : '' ?>">
          <?php if (count($decksData) > 1 || $isFloor): ?>
            <div class="mb-deck-head<?= $isFloor ? ' floor-head' : '' ?>"><?= Security::e((string) $deck['label']) ?> · <?= count($deck['seats']) ?> free<?php if ($isFloor): ?><span class="fh-range"><?= Security::e(Seats::floorRange((string) $deck['key'], $mbCoach)) ?></span><?php endif; ?></div>
          <?php endif; ?>
          <div class="mb-seats">
            <?php foreach ($deck['seats'] as $seat): ?>
              <span class="mb-seat">
                <input type="checkbox" name="seats[]" value="<?= Security::e($seat) ?>" id="mb-<?= Security::e($seat) ?>" onchange="mbSync()">
                <label for="mb-<?= Security::e($seat) ?>"><?= Security::e(Seats::displayLabel((string) $seat, $mbCoach ?? 'sleeper', (string) ($bookingMode ?? 'sharing'))) ?></label>
              </span>
            <?php endforeach; ?>
          </div>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="mb-hint"><span id="mbCount">0</span> / <?= $seatCount ?> selected. The shared-cabin gender rule still applies.</p>
      <button class="btn ok" type="submit" id="mbGo" disabled
              onclick="return confirm('Rebook this missed booking to <?= Security::e(formatDate($targetDate)) ?>? The ticket QR will be re-issued and the change logged.')">
        Rebook missed passenger to <?= Security::e(formatDate($targetDate)) ?>
      </button>
    </form>
  </div>
  <script>
    var MB_NEED = <?= (int) $seatCount ?>;
    function mbSync(){
      var boxes = document.querySelectorAll('#mbForm input[name="seats[]"]');
      var n = 0; boxes.forEach(function(b){ if(b.checked) n++; });
      if (n > MB_NEED) { event.target.checked = false; n = MB_NEED; }
      document.getElementById('mbCount').textContent = n;
      document.getElementById('mbGo').disabled = (n !== MB_NEED);
    }
    function mbValidate(){
      var n = document.querySelectorAll('#mbForm input[name="seats[]"]:checked').length;
      return n === MB_NEED;
    }
  </script>
  <?php endif; ?>
<?php endif; ?>

<?php endif; /* eligible */ ?>

<?php
admin_footer();
