<?php
/**
 * admin/new-booking.php — full-featured "+ NEW BOOKING" screen for admins
 * and counter agents. Master-prompt §8.
 *
 * The seatmap.php counter form is a 4-field mini (name/phone/gender +
 * one seat, method hardcoded to cash) — good for a walk-in but useless
 * for a phone booking where the customer wants two seats, a specific
 * pickup stop, UPI, and to record their Aadhaar. This page is the full
 * customer-style checkout, driven end-to-end by BookingService::counterSale
 * so double-booking protection, gender-lock enforcement, ticket issue,
 * commission accrual, and audit trail all match the online flow.
 *
 * Flow:
 *   Step 1 — pick route + travel date (page reloads with seats visible).
 *   Step 2 — tick seats, fill passenger cards, submit.
 *
 * Extra fields not accepted by counterSale() itself (id_type, id_number,
 * boarding_stop, drop_stop, boarding_time) are written by a small
 * post-sale UPDATE — the core sale stays untouched so its regression
 * suite still covers it.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';   // TripStatus (5 Sep 2026: explicit, not via another include)
$admin = admin_boot('bookings.view');

/* 3 Sep 2026 — one booking flow for every role: staff now sell through the
   customer app's own seat map + checkout ("counter mode", assets/js/14-counter.js),
   so this page hands over to it, carrying route + date when given.
   ?legacy=1 keeps this form reachable for a while. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['legacy'])) {
    $qs = ['counter' => 1];
    $rq = (int) ($_GET['route'] ?? 0);
    if ($rq > 0) {
        $rr = Database::fetch('SELECT from_city, to_city FROM routes WHERE id = :i', ['i' => $rq]);
        if ($rr !== null) { $qs['from'] = (string) $rr['from_city']; $qs['to'] = (string) $rr['to_city']; }
    }
    if (Security::isValidDate((string) ($_GET['date'] ?? ''))) { $qs['date'] = (string) $_GET['date']; }
    Response::redirect('index.php?' . http_build_query($qs) . '#/');
}

$base = '';
$flash = null;

/* Only staff that can EDIT bookings should be allowed to CREATE one.
   'bookings.view' covers scan/support who must not create bookings
   (they'd steal from the counter agent's commission attribution). */
$canCreate = Auth::can('bookings.edit') || Auth::can('schedules.edit') || Auth::isSuperadmin();

$routes = Database::fetchAll(
    "SELECT id, route_code, from_city, to_city, coach_type, base_fare, is_active
       FROM routes WHERE is_active = 1 ORDER BY sort_order, id"
);

$routeId = (int) ($_REQUEST['route']   ?? ($routes[0]['id'] ?? 0));
$date    = Security::clean($_REQUEST['date'] ?? todayISO(), 10);
if (!Security::isValidDate($date)) {
    $date = todayISO();
}

$route = null;
foreach ($routes as $r) {
    if ((int) $r['id'] === $routeId) { $route = $r; break; }
}

$schedule = null;
$availableSeats = [];
$coach = '';
if ($route !== null) {
    try {
        $schedule = Seats::schedule($routeId, $date);
        $coach = (string) $route['coach_type'];
        $bt    = $coach === 'sleeper' ? 'sharing' : 'seater';
        $av    = Seats::availability($routeId, $date, $bt);
        $availableSeats = $av['available'] ?? [];
    } catch (Throwable $e) {
        $flash = ['bad', 'Cannot load seats: ' . $e->getMessage()];
    }
}

/* ============================================================
   POST — create the booking.
   ============================================================ */
if ($canCreate && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif ($route === null || $schedule === null) {
        $flash = ['bad', 'Choose a route and travel date first.'];
    } else {
        try {
            Auth::requireAdmin($canCreate ? 'bookings.view' : 'bookings.edit');

            // Phase 6 — edit-window gate: refuse new bookings on
            // departed/arrived/completed/cancelled trips. This is the COUNTER,
            // so it uses the wider counter gate — a walk-in during the last
            // 30 min (Boarding) is exactly the "late booking" the office needs,
            // even though the website has already closed the trip.
            // Counter grace: a departed bus stays sellable to staff/agents for
            // 24h after its scheduled departure (owner ask — enter a late/missed
            // passenger who actually travelled). SOLD_OUT / CANCELLED stay shut.
            $gate = TripStatus::counterBookableWithin((int) $schedule['id']);
            if (!($gate['ok'] ?? false) && !Auth::isSuperadmin()) {
                throw new RuntimeException('This trip is ' . ($gate['label'] ?? 'closed') . ' — new bookings are not accepted.');
            }

            $selectedSeats = array_values(array_filter(array_map(
                static fn($s) => strtoupper(trim((string) $s)),
                (array) ($_POST['seats'] ?? [])
            ), static fn(string $s) => $s !== ''));
            if ($selectedSeats === []) {
                throw new RuntimeException('Choose at least one seat before submitting.');
            }

            /* Blank is allowed: this is a DESK sale, and counterSale() below
               already accepts an empty phone and stores the walk-in
               placeholder itself. This gate was stricter than the engine it
               calls, so a walk-in with no phone was refused here and accepted
               everywhere else. A number that IS typed must still be a real one,
               at the desk's 8-15 range. */
            $contactPhone = normalisePhone((string) ($_POST['contact_phone'] ?? ''));
            if ($contactPhone !== '' && !Security::isValidPhone($contactPhone, true)) {
                throw new RuntimeException('That contact phone does not look right — leave it blank for a walk-in, or enter 8-15 digits.');
            }

            $primaryName = Security::clean((string) ($_POST['pax_name'][0] ?? ''), 120);
            if ($primaryName === '') {
                throw new RuntimeException('Enter the primary passenger name.');
            }

            /* Build the per-passenger array, one row per selected seat. */
            $passengers = [];
            foreach ($selectedSeats as $i => $seat) {
                $passengers[] = [
                    'name'   => Security::clean((string) ($_POST['pax_name'][$i]   ?? ''), 120),
                    'gender' => (string) ($_POST['pax_gender'][$i] ?? ''),
                    'age'    => ((string) ($_POST['pax_age'][$i]  ?? '')) !== '' ? (int) $_POST['pax_age'][$i] : null,
                ];
            }

            $paymentMethod = in_array($_POST['payment_method'] ?? 'cash', ['cash', 'upi', 'esewa', 'bank'], true)
                ? (string) $_POST['payment_method'] : 'cash';
            $amount = (float) ($_POST['amount'] ?? 0);
            $note   = Security::clean((string) ($_POST['note'] ?? ''), 255);
            $source = Auth::isCounterAgent() ? 'agent' : 'admin';

            $booking = BookingService::counterSale(
                $route,
                (int) $schedule['id'],
                $date,
                $selectedSeats,
                [
                    'name'          => $primaryName,
                    'phone'         => $contactPhone,
                    'gender'        => (string) ($passengers[0]['gender'] ?? ''),
                    'paymentMethod' => $paymentMethod,
                    'amount'        => $amount > 0 ? $amount : null,
                    // Counter discount (Task 9): flat ₹ or %, clamped server-side
                    // to counter_max_discount_pct.
                    'discountType'  => in_array($_POST['discount_type'] ?? '', ['flat', 'percent'], true) ? (string) $_POST['discount_type'] : '',
                    'discountValue' => (float) ($_POST['discount_value'] ?? 0),
                    'passengers'    => $passengers,
                    'note'          => $note,
                ],
                (int) $admin['id'],
                $source
            );

            /* Post-sale UPDATE for fields counterSale doesn't accept yet:
               government-ID pair on the booking, boarding/drop on the
               outbound leg. Kept as UPDATEs rather than extending the
               tested counterSale signature — this page is the only
               caller that captures these today. */
            $idType    = Security::clean((string) ($_POST['id_type']   ?? ''), 60);
            $idNumber  = Security::clean((string) ($_POST['id_number'] ?? ''), 60);
            $boarding  = Security::clean((string) ($_POST['boarding_stop'] ?? ''), 191);
            $dropStop  = Security::clean((string) ($_POST['drop_stop']     ?? ''), 191);

            if ($idType !== '' || $idNumber !== '') {
                Database::update('bookings',
                    ['id_type' => $idType ?: null, 'id_number' => $idNumber ?: null],
                    'id = :id', ['id' => (int) $booking['id']]
                );
            }
            if ($boarding !== '' || $dropStop !== '') {
                Database::update('booking_legs',
                    ['boarding_stop' => $boarding ?: null, 'drop_stop' => $dropStop ?: null],
                    'booking_id = :b AND leg_type = \'outbound\'',
                    ['b' => (int) $booking['id']]
                );
            }
            if ($idType !== '' || $idNumber !== '' || $boarding !== '' || $dropStop !== '') {
                // The sale itself is audited inside counterSale(); this records
                // the post-sale amendment so the trail shows the ID + stops too.
                Logger::audit('booking.counter_details', 'booking', (string) $booking['pnr'], null,
                    array_filter(['id_type' => $idType, 'id_number' => $idNumber, 'boarding_stop' => $boarding, 'drop_stop' => $dropStop]),
                    'ID / stops captured at the counter');
            }

            /* Straight to the ticket — the admin's next action is almost
               always "print / share the confirmation", not to book another. */
            Response::redirect('admin/booking-view.php?pnr=' . urlencode((string) $booking['pnr']));
            exit;
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

admin_header('New Booking', 'new-booking');

?>
<style>
.nb-form{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:0;margin-bottom:20px;overflow:hidden}
.nb-hdr{padding:14px 20px;border-bottom:1px solid var(--line);background:var(--head);font-weight:800;font-size:15px}
.nb-body{padding:20px}
.nb-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:14px}
.nb-row label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
.nb-row input, .nb-row select, .nb-row textarea{padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:14px;font-weight:400;background:var(--card);color:var(--ink);text-transform:none;letter-spacing:0}
.nb-row textarea{resize:vertical;min-height:60px;font-family:inherit}
.seat-picker{display:flex;flex-wrap:wrap;gap:8px;padding:10px;background:var(--head);border-radius:10px;max-height:220px;overflow:auto}
.seat-picker label{cursor:pointer;padding:8px 12px;background:var(--card);border:2px solid var(--line);border-radius:8px;font-weight:700;font-size:13px;color:var(--ink);display:inline-flex;align-items:center;gap:6px;user-select:none;transition:all .12s}
.seat-picker label:hover{border-color:var(--blue);background:var(--hover)}
.seat-picker input{accent-color:var(--blue);cursor:pointer}
.seat-picker label:has(input:checked){background:#0a6b3b;color:#fff;border-color:#0a6b3b}
.pax-card{background:var(--head);border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:10px}
.pax-card h4{margin:0 0 10px;font-size:13px;color:var(--blue)}
.pay-methods{display:flex;gap:10px;flex-wrap:wrap}
.pay-methods label{cursor:pointer;padding:10px 14px;background:var(--card);border:2px solid var(--line);border-radius:10px;font-weight:700;font-size:13px;user-select:none;display:inline-flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;color:var(--ink)}
.pay-methods label:has(input:checked){background:var(--blue);color:#fff;border-color:var(--blue)}
.pay-methods input{display:none}
.nb-actions{padding:16px 20px;background:var(--head);border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:12px}
.nb-summary{font-size:13px;color:var(--mut)}
.nb-summary strong{color:var(--ink);font-size:16px;margin:0 4px}
</style>

<?php if ($flash !== null): ?>
  <div class="flash <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<?php if (!$canCreate): ?>
  <div class="panel"><h2>Read only</h2>
    <div style="padding:16px 20px">Your role can view bookings but not create them. Ask a manager or superadmin.</div>
  </div>
<?php else: ?>

<!-- Step 1 — Trip picker -->
<form method="get" class="nb-form">
  <div class="nb-hdr">1. Choose trip</div>
  <div class="nb-body">
    <div class="nb-row">
      <label>Route
        <select name="route" onchange="this.form.submit()">
          <?php foreach ($routes as $r): ?>
            <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === $routeId ? 'selected' : '' ?>>
              <?= Security::e($r['route_code'] . ' — ' . $r['from_city'] . ' → ' . $r['to_city'] . ' · ' . ucfirst((string) $r['coach_type'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Travel date
        <?php /* min is yesterday, not today: an overnight bus that left Surat
                 the previous afternoon is still within its 24h counter-grace,
                 so staff must be able to pick that date to add a late ticket. */ ?>
        <input type="date" name="date" value="<?= Security::e($date) ?>" min="<?= Security::e(date('Y-m-d', strtotime('-1 day'))) ?>" onchange="this.form.submit()">
      </label>
      <label>Fare per seat
        <input type="text" readonly value="<?= $route ? inr((float) $route['base_fare']) : '—' ?>" style="background:var(--head)">
      </label>
    </div>
  </div>
</form>

<?php if ($route !== null && $schedule !== null): ?>

<form method="post" class="nb-form" id="nbForm">
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="create">
  <input type="hidden" name="route" value="<?= (int) $route['id'] ?>">
  <input type="hidden" name="date"  value="<?= Security::e($date) ?>">

  <!-- Step 2 — Seat picker -->
  <div class="nb-hdr">2. Choose seat(s) · <?= count($availableSeats) ?> open</div>
  <div class="nb-body">
    <?php if ($availableSeats === []): ?>
      <div class="muted">No open seats on this trip.</div>
    <?php else: ?>
    <div class="seat-picker" id="seatPicker">
      <?php foreach ($availableSeats as $s): ?>
      <label>
        <input type="checkbox" name="seats[]" value="<?= Security::e($s) ?>" onchange="nbSyncPax()">
        <?= Security::e(Seats::displayLabel((string) $s, $coach ?? 'sleeper', (string) ($bt ?? 'sharing'))) ?>
      </label>
      <?php endforeach; ?>
    </div>
    <div class="muted" style="font-size:12px;margin-top:8px">Tick one or more seats. Add one passenger card will appear below per seat.</div>
    <?php endif; ?>
  </div>

  <!-- Step 3 — Passenger details -->
  <div class="nb-hdr">3. Passenger details</div>
  <div class="nb-body">
    <div id="paxCards">
      <!-- Rendered by nbSyncPax() based on tick count -->
      <div class="muted" id="paxEmpty">Tick at least one seat above.</div>
    </div>
  </div>

  <!-- Step 4 — Booking meta -->
  <div class="nb-hdr">4. Contact, boarding &amp; ID</div>
  <div class="nb-body">
    <div class="nb-row">
      <label>Primary phone <input type="tel" name="contact_phone" placeholder="10 digits" pattern="[0-9]{10,15}" required></label>
      <label>Boarding stop <input type="text" name="boarding_stop" maxlength="191" placeholder="e.g. Surat (Chowk)"></label>
      <label>Drop stop <input type="text" name="drop_stop" maxlength="191" placeholder="e.g. Rupaidiha"></label>
    </div>
    <div class="nb-row">
      <label>ID type (optional)
        <select name="id_type">
          <option value="">—</option>
          <option>Aadhaar</option>
          <option>PAN</option>
          <option>Voter ID</option>
          <option>Passport</option>
          <option>Driving License</option>
          <option>Other</option>
        </select>
      </label>
      <label>ID number (optional) <input type="text" name="id_number" maxlength="60" placeholder="e.g. XXXX XXXX 1234"></label>
      <label>Remarks (internal) <input type="text" name="note" maxlength="255" placeholder="Optional note"></label>
    </div>
  </div>

  <!-- Step 5 — Fare + payment -->
  <div class="nb-hdr">5. Fare &amp; payment</div>
  <div class="nb-body">
    <div class="nb-row">
      <label>Fare override (₹) — leave blank for the route price
        <input type="number" name="amount" min="0" step="1" placeholder="Auto-calculated" oninput="nbSyncPax()">
      </label>
    </div>
    <!-- Discount (Task 9): flat ₹ or %, capped by counter_max_discount_pct -->
    <div class="nb-row" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
      <label style="flex:1;min-width:150px">Discount
        <input type="number" name="discount_value" min="0" step="1" placeholder="0" oninput="nbSyncPax()">
      </label>
      <label style="min-width:130px">Type
        <select name="discount_type" onchange="nbSyncPax()">
          <option value="flat">₹ Fixed</option>
          <option value="percent">% Percent</option>
        </select>
      </label>
    </div>
    <div class="muted" style="font-size:12px;margin-top:-4px">Max discount: <?= (float) Settings::getFloat('counter_max_discount_pct', 15) ?>% of base fare — the server enforces this cap.</div>
    <div class="pay-methods">
      <label><input type="radio" name="payment_method" value="cash" checked> 💵 Cash</label>
      <label><input type="radio" name="payment_method" value="upi"> 📱 UPI</label>
      <label><input type="radio" name="payment_method" value="esewa"> 🇳🇵 eSewa</label>
      <label><input type="radio" name="payment_method" value="bank"> 🏦 Bank transfer</label>
    </div>
    <div class="muted" style="font-size:12px;margin-top:10px">
      This creates a CONFIRMED, PAID booking — the payment is marked verified at the same time and the ticket is issued immediately.
      For an online booking that still needs payment proof, ask the customer to book through the website.
    </div>
  </div>

  <div class="nb-actions">
    <div class="nb-summary">
      <span id="nbSummarySeats"><strong>0</strong> seats</span> ·
      <span id="nbSummaryFare"><strong><?= inr(0) ?></strong> total</span>
    </div>
    <button class="btn ok" type="submit" onclick="return confirm('Create this confirmed booking? Payment will be marked verified.')">✅ Create Booking</button>
  </div>
</form>

<script>
/* Sync passenger cards to the number of ticked seats. Keeps whatever
   the operator has already typed for a passenger whose seat is still
   ticked — un-ticking a seat drops that card, re-ticking generates
   a fresh empty one. */
(function () {
  var seatPicker  = document.getElementById('seatPicker');
  var paxCards    = document.getElementById('paxCards');
  var paxEmpty    = document.getElementById('paxEmpty');
  var perSeatFare = <?= (float) ($route['base_fare'] ?? 0) ?>;

  window.nbSyncPax = function () {
    if (!seatPicker || !paxCards) return;
    var ticked = Array.prototype.slice.call(seatPicker.querySelectorAll('input:checked'));
    var seats  = ticked.map(function (i) { return i.value; });

    // Save current values by index so a re-tick doesn't wipe typed name/age/gender.
    var kept = {};
    Array.prototype.forEach.call(paxCards.querySelectorAll('.pax-card'), function (c) {
      var idx = c.getAttribute('data-idx');
      kept[idx] = {
        name:   (c.querySelector('input[name="pax_name[]"]')   || {}).value || '',
        gender: (c.querySelector('select[name="pax_gender[]"]') || {}).value || '',
        age:    (c.querySelector('input[name="pax_age[]"]')    || {}).value || '',
      };
    });

    paxCards.innerHTML = '';
    if (seats.length === 0) {
      var e = document.createElement('div');
      e.className = 'muted'; e.textContent = 'Tick at least one seat above.'; paxCards.appendChild(e);
    } else {
      seats.forEach(function (seat, i) {
        var v = kept[i] || {};
        var esc = function (s) { return String(s == null ? '' : s).replace(/"/g, '&quot;'); };
        var html = '<div class="pax-card" data-idx="' + i + '">'
          + '<h4>Passenger ' + (i + 1) + ' — Seat <span style="color:var(--orange)">' + seat + '</span></h4>'
          + '<div class="nb-row">'
            + '<label>Name<input type="text" name="pax_name[]"   maxlength="120" required value="' + esc(v.name) + '"></label>'
            + '<label>Gender<select name="pax_gender[]"><option value="">—</option>'
              + ['Male','Female','Other'].map(function(g){return '<option '+(v.gender===g?'selected':'')+'>'+g+'</option>'}).join('')
            + '</select></label>'
            + '<label>Age<input type="number" name="pax_age[]" min="1" max="120" placeholder="Age" value="' + esc(v.age) + '"></label>'
          + '</div></div>';
        paxCards.insertAdjacentHTML('beforeend', html);
      });
    }

    // Live summary at the bottom.
    var sSeats = document.getElementById('nbSummarySeats');
    var sFare  = document.getElementById('nbSummaryFare');
    if (sSeats) sSeats.innerHTML = '<strong>' + seats.length + '</strong> seat' + (seats.length === 1 ? '' : 's');
    if (sFare) {
      var override = parseFloat((document.querySelector('input[name="amount"]') || {}).value || '') || 0;
      var base = override > 0 ? override : (perSeatFare * seats.length);
      /* Base → Discount → Final breakdown (Task 9), mirroring the server cap. */
      var dType = ((document.querySelector('select[name="discount_type"]') || {}).value) || 'flat';
      var dVal  = parseFloat((document.querySelector('input[name="discount_value"]') || {}).value || '') || 0;
      var maxPct = <?= (float) Settings::getFloat('counter_max_discount_pct', 15) ?>;
      var disc = 0;
      if (dVal > 0 && base > 0) {
        disc = dType === 'percent' ? (base * dVal / 100) : dVal;
        disc = Math.min(disc, base * maxPct / 100, base);
      }
      var fin = Math.max(0, base - disc);
      sFare.innerHTML = disc > 0
        ? 'Base <s>₹' + Math.round(base).toLocaleString('en-IN') + '</s> − ₹' + Math.round(disc).toLocaleString('en-IN') + ' = <strong>₹' + Math.round(fin).toLocaleString('en-IN') + '</strong>'
        : '<strong>₹' + Math.round(base).toLocaleString('en-IN') + '</strong> total';
    }
  };
  document.querySelector('input[name="amount"]').addEventListener('input', window.nbSyncPax);
})();
</script>

<?php endif; ?>
<?php endif; ?>

<?php admin_footer();
