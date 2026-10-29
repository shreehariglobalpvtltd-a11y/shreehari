<?php
/**
 * =====================================================================
 *  admin/chalan.php — Bus Chalan hub (17 Sep 2026)
 *
 *  ONE screen for the departure documents. The office used to have nine
 *  buttons across three pages (passenger manifest, challan picture, chalani
 *  page picker) and had to know which one was which. Here the desk:
 *
 *    1. picks the date and the departure (every bus that day — the daily
 *       bus is slot 1, Bus-Calendar extra buses are slot 2+; overnight
 *       buses still inside their 24 h grace are offered too),
 *    2. sees the variation the system worked out for it — direction,
 *       daily / extra bus, sleeper / seater, plate, driver, passengers,
 *    3. previews the real files (the seat-grid CHALLAN and page 1 of the
 *       Nepali CHALANI — both drawn by the existing renderers, cached on a
 *       data fingerprint, so the preview IS the file),
 *    4. downloads PDF / PNG (the only two formats kept),
 *    5. sends either picture to the driver, the office or any number on
 *       WhatsApp through the shared one-click sheet (admin_wa_button →
 *       admin/api/wa-send.php; media served by download-chalan.php).
 *
 *  Nothing here renders documents itself — it links the existing
 *  renderers (ChallanPng via challan.php, ChalaniPng/ChalaniPdf via
 *  manifest.php), so the booking engine and the tests around those files
 *  are untouched.
 *
 *  Permission: bookings.view — but a SCOPED agent login is refused, like
 *  challan.php: these sheets list every berth on the bus.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/tripstatus.php';
require_once INCLUDE_PATH . '/challanpng.php';
require_once INCLUDE_PATH . '/chalanipng.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/challanlock.php';   // 29 Oct 2026: finalize, what changed since, history
$admin = admin_boot('bookings.view');
$base  = '';

if (Auth::bookingScopeAdminId() !== null) {
    admin_header('Bus Chalan', 'chalan');
    echo '<div class="flash bad"><svg class="a-ic"><use href="#a-lock"/></svg>The chalan lists every seat on the bus. An agent login sees only its own sales — use <a href="' . $base . '/admin/agent-passengers.php">My Passengers</a>.</div>';
    admin_footer();
    exit;
}

$date = Security::clean($_GET['date'] ?? '', 10);
if (!Security::isValidDate($date)) {
    $date = todayISO();
}
$sid = (int) ($_GET['sid'] ?? 0);

/* ---- one departure by id wins; it also fixes the date ------------------ */
$trip = $sid > 0 ? ChallanPng::schedule($sid) : null;
if ($trip !== null) {
    $date = (string) $trip['travel_date'];
}

/* ---- every departure that day (slot, coach override, bus fallback) ----- */
$departures = Database::fetchAll(
    "SELECT s.id, s.slot, s.status, s.is_blocked, s.total_seats, s.seats_booked,
            r.id AS route_id, r.route_code, r.from_city, r.to_city,
            COALESCE(s.dep_time_override, r.dep_time)      AS dep_time,
            COALESCE(s.coach_type_override, r.coach_type)  AS coach_type,
            bu.bus_number, bu.bus_name,
            d.full_name AS driver_name, d.phone AS driver_phone
       FROM schedules s
       JOIN routes r       ON r.id = s.route_id
       LEFT JOIN buses bu  ON bu.id = COALESCE(s.bus_id, r.bus_id)
       LEFT JOIN drivers d ON d.id = s.driver_id
      WHERE s.travel_date = :d AND r.is_active = 1
      ORDER BY COALESCE(s.dep_time_override, r.dep_time) ASC, s.slot ASC, r.id ASC",
    ['d' => $date]
);
if ($trip === null && $departures !== []) {
    $sid  = (int) $departures[0]['id'];
    $trip = ChallanPng::schedule($sid);
}

/* Overnight buses that left on an earlier date but are inside the 24 h
   counter grace — the driver still wants yesterday's sheet. Same rule as
   manifest.php. */
$stillRunning = Database::fetchAll(
    "SELECT s.id, s.travel_date, s.slot, r.from_city, r.to_city,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time
       FROM schedules s
       JOIN routes r ON r.id = s.route_id
      WHERE r.is_active = 1 AND s.travel_date <> :d AND s.status <> 'cancelled'
        AND TIMESTAMP(s.travel_date, COALESCE(s.dep_time_override, r.dep_time)) <= NOW()
        AND TIMESTAMP(s.travel_date, COALESCE(s.dep_time_override, r.dep_time)) + INTERVAL 24 HOUR >= NOW()
      ORDER BY s.travel_date DESC, dep_time ASC
      LIMIT 8",
    ['d' => $date]
);

/* ---- the variation, worked out — never chosen by hand ------------------ */
$var = null;
$paxCount = 0;
$pageCount = 1;
$state = null;
if ($trip !== null) {
    $direction = QuickTicket::directionOf(['from_city' => $trip['from_city'], 'to_city' => $trip['to_city']]);
    $coach     = Seats::effectiveCoach(['id' => (int) $trip['id'], 'coach_type_override' => $trip['coach_type_override'] ?? ''], ['coach_type' => $trip['coach_type'] ?? '']);
    $slot      = (int) ($trip['slot'] ?? 1);
    $paxCount  = (int) Database::scalar(
        "SELECT COUNT(*) FROM booking_passengers bp
           JOIN booking_legs bl ON bl.id = bp.leg_id
           JOIN bookings b ON b.id = bp.booking_id
          WHERE bl.schedule_id = :s AND b.status IN ('confirmed','pending','completed')",
        ['s' => $sid], 0
    );
    $pageCount = ChalaniPng::pageCount(array_fill(0, $paxCount, []));
    $state     = TripStatus::editableFor($sid);
    $var = [
        'direction' => QuickTicket::directionLabel($direction),
        'toNepal'   => $direction === 'toNepal',
        'coach'     => ucfirst($coach),
        'slotText'  => $slot > 1 ? 'Extra bus #' . $slot : 'Daily bus',
        'bus'       => trim((string) ($trip['bus_number'] ?? '')) ?: 'not assigned',
        'busName'   => trim((string) ($trip['bus_name'] ?? '')),
        'driver'    => trim((string) ($trip['driver_name'] ?? '')),
        'driverPhone' => trim((string) ($trip['driver_phone'] ?? '')),
        'chalanNo'  => ChallanPng::challanNo($trip),
        'dep'       => substr((string) ($trip['dep_time'] ?? ''), 0, 5),
        'route'     => trim((string) $trip['from_city']) . ' → ' . trim((string) $trip['to_city']),
    ];
}

$q = static fn(array $extra): string => Security::e(http_build_query(array_filter($extra, static fn($v) => $v !== null && $v !== '')));
$manifestQs  = $trip !== null ? ['sid' => $sid, 'date' => $date, 'route' => (int) $trip['route_id']] : [];
$hrefChalaniPdf = $base . '/admin/manifest.php?' . $q($manifestQs + ['format' => 'chalanipdf']);
$hrefChalaniPng = $base . '/admin/manifest.php?' . $q($manifestQs + ['format' => 'chalanipng']);
$hrefChalaniP1  = $base . '/admin/manifest.php?' . $q($manifestQs + ['format' => 'chalanipng', 'page' => 1]);
$hrefChalaniPrt = $base . '/admin/manifest.php?' . $q($manifestQs + ['format' => 'chalani']);
$hrefChallan    = $base . '/admin/challan.php?' . $q(['sid' => $sid]);
$hrefChallanDl  = $base . '/admin/challan.php?' . $q(['sid' => $sid, 'dl' => 1]);
$hrefStatus     = $base . '/admin/challan.php?' . $q(['sid' => $sid, 'doc' => 'status']);
$hrefStatusDl   = $base . '/admin/challan.php?' . $q(['sid' => $sid, 'doc' => 'status', 'dl' => 1]);
$hrefManifest   = $base . '/admin/manifest.php?' . $q($manifestQs);
$hrefSeats      = $base . '/admin/seatmap.php?' . $q(['sid' => $sid]);

/* ---- Finalize / lift (29 Oct 2026, management upgrade 3.3) -------------
   Redirect-after-post back to the same departure, flash in the session. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $trip !== null) {
    $act = (string) ($_POST['action'] ?? '');
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again.');
        }
        if ($act === 'challan_finalize') {
            $v = ChallanLock::finalize($sid, (int) $admin['id'], (string) ($_POST['note'] ?? ''));
            $_SESSION['shg_chalan_flash'] = ['ok', 'Finalized as version ' . $v . ' — from now on every change to this list shows here.'];
        } elseif ($act === 'challan_release') {
            if (!Auth::can('dashboard.view')) {
                throw new RuntimeException('Only the office can lift a finalize.');
            }
            ChallanLock::release($sid, (int) $admin['id'], (string) ($_POST['reason'] ?? ''));
            $_SESSION['shg_chalan_flash'] = ['ok', 'Finalize lifted.'];
        }
    } catch (Throwable $e) {
        $_SESSION['shg_chalan_flash'] = ['bad', $e->getMessage()];
    }
    Response::redirect('admin/chalan.php?' . http_build_query(['sid' => $sid, 'date' => $date]) . '#chLock');
}
$chFlash = $_SESSION['shg_chalan_flash'] ?? null;
unset($_SESSION['shg_chalan_flash']);
$lock    = $trip !== null ? ChallanLock::active($sid) : null;
$lockDiff = $lock !== null ? ChallanLock::diff($sid) : null;
$lockHist = $trip !== null ? ChallanLock::history($sid) : [];

admin_header('Bus Chalan', 'chalan');
admin_page_head(
    'Pick the departure — the right chalan is worked out for it. Preview, download PDF / PNG, send on WhatsApp.',
    [],
    $trip !== null ? '<a class="btn ghost" href="' . $hrefManifest . '"><svg class="a-ic"><use href="#a-clipboard"/></svg>Passenger manifest</a><a class="btn ghost" href="' . $hrefSeats . '"><svg class="a-ic"><use href="#a-seat"/></svg>Seat map</a>' : ''
);
?>
<style>
.ch-pick{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.ch-pick label{display:flex;flex-direction:column;gap:5px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)}
.ch-pick input,.ch-pick select{font:14px var(--f-ui);padding:9px 11px;border:1px solid var(--line-2);border-radius:10px;background:var(--card);color:var(--ink);min-height:40px;text-transform:none;letter-spacing:0;font-weight:500}
.ch-var{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px 18px;margin:0}
.ch-var dt{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.06em;font-weight:700}
.ch-var dd{margin:3px 0 0;font-weight:700;font-size:14.5px}
.ch-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 11px;border-radius:999px;font-size:12px;font-weight:800}
.ch-badge.np{background:#FBE3E3;color:#9B1C1C}.ch-badge.in{background:#FCE9D6;color:#B3570C}
:root[data-theme="dark"] .ch-badge.np{background:#3B1717;color:#F4A6A6}:root[data-theme="dark"] .ch-badge.in{background:#3B2610;color:#F5B57A}
.ch-docs{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}
.ch-doc{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh-1);display:flex;flex-direction:column}
.ch-doc .ch-doc-h{padding:12px 16px;border-bottom:1px solid var(--line);background:var(--head);display:flex;align-items:center;gap:8px;font-weight:700}
.ch-doc .ch-doc-h .pill{margin-left:auto}
.ch-doc .ch-prev{background:#E9EDF4;padding:12px;display:flex;align-items:center;justify-content:center;min-height:220px}
:root[data-theme="dark"] .ch-doc .ch-prev{background:#0E192C}
.ch-doc .ch-prev img{max-width:100%;max-height:420px;border-radius:8px;box-shadow:var(--sh-2);background:#fff}
.ch-doc .ch-acts{display:flex;gap:8px;flex-wrap:wrap;padding:12px 16px}
.ch-send{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px 16px;align-items:end}
.ch-send label{display:flex;flex-direction:column;gap:5px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)}
.ch-send input,.ch-send select{font:14px var(--f-ui);padding:9px 11px;border:1px solid var(--line-2);border-radius:10px;background:var(--card);color:var(--ink);min-height:40px;text-transform:none;letter-spacing:0;font-weight:500}
.ch-chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
@media(max-width:640px){.ch-doc .ch-prev{min-height:160px}}
</style>

<div class="panel">
  <h2><svg class="a-ic"><use href="#a-bus"/></svg> 1 · Which bus?</h2>
  <div class="panel-body">
    <form method="get" class="ch-pick">
      <label>Travel date<input type="date" name="date" value="<?= Security::e($date) ?>" onchange="this.form.submit()"></label>
      <?php if ($departures !== []): ?>
      <label>Departure
        <select name="sid" onchange="this.form.submit()">
          <?php foreach ($departures as $d): ?>
            <option value="<?= (int) $d['id'] ?>" <?= (int) $d['id'] === $sid ? 'selected' : '' ?>>
              <?= Security::e(substr((string) $d['dep_time'], 0, 5) . ' · ' . $d['from_city'] . ' → ' . $d['to_city']
                  . ((int) $d['slot'] > 1 ? ' · Bus ' . (int) $d['slot'] . ' (extra)' : '')
                  . ' · ' . ucfirst((string) $d['coach_type'])
                  . ($d['bus_number'] ? ' · ' . $d['bus_number'] : '')
                  . ((string) $d['status'] === 'cancelled' ? ' · CANCELLED' : '')) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
      <button class="btn ghost" type="submit"><svg class="a-ic"><use href="#a-search"/></svg>Show</button>
    </form>
    <?php if ($stillRunning !== []): ?>
      <div class="ch-chips">
        <span class="muted text-sm" style="align-self:center">Still on the road (within 24 h):</span>
        <?php foreach ($stillRunning as $sr): ?>
          <a class="chip" href="<?= $base ?>/admin/chalan.php?sid=<?= (int) $sr['id'] ?>"><svg class="a-ic"><use href="#a-clock"/></svg><?= Security::e(date('d M', strtotime((string) $sr['travel_date'])) . ' · ' . substr((string) $sr['dep_time'], 0, 5) . ' · ' . $sr['from_city'] . ' → ' . $sr['to_city'] . ((int) $sr['slot'] > 1 ? ' · Bus ' . (int) $sr['slot'] : '')) ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($trip === null): ?>
  <?= admin_empty('No departure on ' . formatDate($date), 'Pick another date, or add the trip on the Bus Calendar first.', '🚌',
        '<a class="btn" href="' . $base . '/admin/calendar.php"><svg class="a-ic"><use href="#a-calendar"/></svg>Bus Calendar</a>') ?>
<?php else: ?>

<div class="panel">
  <h2><svg class="a-ic"><use href="#a-layers"/></svg> 2 · This departure
    <span class="ch-badge <?= $var['toNepal'] ? 'np' : 'in' ?>" style="margin-left:auto"><?= $var['toNepal'] ? Brand::flagUse('NP', 16, 'en', true) : Brand::flagUse('IN', 13, 'en', true) ?> <?= Security::e($var['direction']) ?></span>
  </h2>
  <div class="panel-body">
    <dl class="ch-var">
      <div><dt>Chalan no.</dt><dd class="mono"><?= Security::e($var['chalanNo']) ?></dd></div>
      <div><dt>Route · time</dt><dd><?= Security::e($var['route'] . ' · ' . $var['dep']) ?></dd></div>
      <div><dt>Date</dt><dd><?= Security::e(formatDate($date)) ?></dd></div>
      <div><dt>Bus type</dt><dd><?= Security::e($var['slotText'] . ' · ' . $var['coach']) ?></dd></div>
      <div><dt>Vehicle</dt><dd class="mono"><?= Security::e($var['bus']) ?><?= $var['busName'] !== '' ? ' <span class="muted text-xs">' . Security::e($var['busName']) . '</span>' : '' ?></dd></div>
      <div><dt>Driver</dt><dd><?= $var['driver'] !== '' ? Security::e($var['driver']) . ($var['driverPhone'] !== '' ? ' <span class="muted mono text-xs">' . Security::e($var['driverPhone']) . '</span>' : '') : '<span class="muted">not assigned</span>' ?></dd></div>
      <div><dt>Passengers</dt><dd><?= (int) $paxCount ?> <span class="muted text-xs">· chalani <?= (int) $pageCount ?> page<?= $pageCount === 1 ? '' : 's' ?></span></dd></div>
      <div><dt>Trip state</dt><dd><?= admin_pill((string) ($state['state'] ?? 'scheduled'), (string) ($state['label'] ?? '')) ?></dd></div>
    </dl>
    <?php if ((string) ($trip['status'] ?? '') === 'cancelled'): ?>
      <div class="flash bad" style="margin:14px 0 0">This departure is cancelled — the sheets below show the coach as it stood.</div>
    <?php endif; ?>
  </div>
</div>

<!-- ============ FINALIZE + WHAT CHANGED (29 Oct 2026) ============
     A soft lock: selling is never blocked (late tickets inside the 24 h
     grace are normal); the office just sees what moved since the list
     went to the driver, and re-finalizes after resending. -->
<?php if ($chFlash !== null): ?><div class="flash <?= Security::e((string) $chFlash[0]) ?>"><?= Security::e((string) $chFlash[1]) ?></div><?php endif; ?>
<div class="panel" id="chLock" style="<?= $lockDiff !== null && $lockDiff['n'] > 0 ? 'border-left:4px solid var(--orange)' : '' ?>">
  <h2><svg class="a-ic"><use href="#a-lock"/></svg> 🔒 Finalize &amp; changes · चलानी पक्का
    <?php if ($lock !== null): ?><span class="pill <?= $lockDiff['n'] > 0 ? 'st-orange' : 'st-ok' ?> nodot" style="margin-left:auto"><?= $lockDiff['n'] > 0 ? $lockDiff['n'] . ' change' . ($lockDiff['n'] === 1 ? '' : 's') . ' since v' . (int) $lock['version'] : 'v' . (int) $lock['version'] . ' · up to date' ?></span><?php endif; ?>
  </h2>
  <div class="panel-body">
    <?php if ($lock === null): ?>
      <p class="muted" style="margin:0 0 10px;font-size:13px">Not finalized yet. When the challan goes to the driver, finalize it here — any ticket sold, cancelled or moved after that shows up below, so the driver never boards with an old list. Selling is never blocked.</p>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="challan_finalize">
        <input type="text" name="note" maxlength="255" placeholder="Note (optional) · e.g. sent to driver on WhatsApp" style="flex:1 1 260px;min-height:44px;padding:8px 11px;border:1px solid var(--line);border-radius:10px">
        <button class="btn ok" type="submit" style="min-height:44px">🔒 Finalize · <?= (int) $paxCount ?> passengers</button>
      </form>
    <?php else: ?>
      <p style="margin:0 0 10px;font-size:13.5px">Finalized <b>v<?= (int) $lock['version'] ?></b> on <?= Security::e(substr((string) $lock['finalized_at'], 0, 16)) ?><?= (string) ($lock['by_name'] ?? '') !== '' ? ' by <b>' . Security::e((string) $lock['by_name']) . '</b>' : '' ?> · <?= (int) $lock['pax_count'] ?> passengers<?= (string) ($lock['note'] ?? '') !== '' ? ' · ' . Security::e((string) $lock['note']) : '' ?></p>
      <?php if ($lockDiff['n'] === 0): ?>
        <div class="flash ok" style="margin:0 0 10px">✓ No change since — the driver's list is current.</div>
      <?php else: ?>
        <div class="flash" style="margin:0 0 10px;background:#fff3e0;color:#7a3e00">⚠ The list changed after it was finalized — reprint / resend the challan, then re-finalize.</div>
        <table class="dt" style="width:100%;font-size:13px;margin-bottom:10px">
          <thead><tr><th></th><th>Seat</th><th>Passenger</th><th>PNR</th><th>Pickup → drop</th></tr></thead>
          <tbody>
            <?php foreach ($lockDiff['added'] as $x): ?>
              <tr><td><span class="pill st-ok nodot">＋ added</span></td><td class="mono"><?= Security::e(Seats::displayLabel($x['seat'])) ?></td><td><?= Security::e($x['name']) ?></td><td class="mono"><?= Security::e($x['pnr']) ?></td><td><?= Security::e($x['board'] . ' → ' . $x['drop']) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($lockDiff['removed'] as $x): ?>
              <tr><td><span class="pill st-bad nodot">− removed</span></td><td class="mono"><?= Security::e(Seats::displayLabel($x['seat'])) ?></td><td><s><?= Security::e($x['name']) ?></s></td><td class="mono"><?= Security::e($x['pnr']) ?></td><td><?= Security::e($x['board'] . ' → ' . $x['drop']) ?></td></tr>
            <?php endforeach; ?>
            <?php foreach ($lockDiff['changed'] as $x): ?>
              <tr><td><span class="pill st-orange nodot">↔ moved</span></td>
                <td class="mono"><?= Security::e(Seats::displayLabel($x['before']['seat'])) ?><?= $x['before']['seat'] !== $x['after']['seat'] ? ' → ' . Security::e(Seats::displayLabel($x['after']['seat'])) : '' ?></td>
                <td><?= Security::e($x['after']['name']) ?><?= $x['before']['name'] !== $x['after']['name'] ? ' <span class="muted">(was ' . Security::e($x['before']['name']) . ')</span>' : '' ?></td>
                <td class="mono"><?= Security::e($x['after']['pnr']) ?></td>
                <td><?= Security::e($x['after']['board'] . ' → ' . $x['after']['drop']) ?><?= ($x['before']['board'] . $x['before']['drop']) !== ($x['after']['board'] . $x['after']['drop']) ? ' <span class="muted">(was ' . Security::e($x['before']['board'] . ' → ' . $x['before']['drop']) . ')</span>' : '' ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="challan_finalize">
          <input type="text" name="note" maxlength="255" placeholder="Note · e.g. resent to driver" style="min-height:44px;padding:8px 11px;border:1px solid var(--line);border-radius:10px">
          <button class="btn" type="submit" style="min-height:44px">🔒 Re-finalize (v<?= (int) $lock['version'] + 1 ?>)</button>
        </form>
        <?php if (Auth::can('dashboard.view')): ?>
        <form method="post" onsubmit="var r=prompt('Why lift the finalize?','');if(!r||r.trim().length<3)return false;this.reason.value=r;return true;">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="challan_release"><input type="hidden" name="reason" value="">
          <button class="btn ghost" type="submit" style="min-height:44px">Lift finalize</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($lockHist !== []): ?>
      <details style="margin-top:12px"><summary style="cursor:pointer;font-weight:700;color:var(--blue)">History · <?= count($lockHist) ?> event<?= count($lockHist) === 1 ? '' : 's' ?></summary>
        <ol style="margin:8px 0 0;padding-left:20px;font-size:12.5px;line-height:1.7">
          <?php foreach ($lockHist as $h): ?>
            <li><span class="mono muted"><?= Security::e(substr($h['when'], 0, 16)) ?></span> · <?= $h['kind'] === 'finalize' ? '🔒 ' : ($h['kind'] === 'release' ? '🔓 ' : '🖼 ') ?><?= Security::e($h['what']) ?><?= $h['by'] !== '' ? ' · ' . Security::e($h['by']) : '' ?></li>
          <?php endforeach; ?>
        </ol>
      </details>
    <?php endif; ?>
  </div>
</div>

<div class="ch-docs" style="margin-bottom:22px">
  <div class="ch-doc">
    <div class="ch-doc-h"><svg class="a-ic" style="color:var(--blue)"><use href="#a-image"/></svg>Challan — seat picture <span class="pill st-info nodot">PNG</span></div>
    <div class="ch-prev"><a href="<?= $hrefChallan ?>" target="_blank" rel="noopener" title="Open full size"><img src="<?= $hrefChallan ?>" alt="Challan seat picture" loading="lazy"></a></div>
    <div class="ch-acts">
      <a class="btn" href="<?= $hrefChallanDl ?>"><svg class="a-ic"><use href="#a-download"/></svg>Download PNG</a>
      <a class="btn ghost" href="<?= $hrefChallan ?>" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-external"/></svg>Open</a>
    </div>
    <div class="text-xs muted" style="padding:0 16px 12px">Both floors, every berth with name · mobile · pickup — for the driver and the border desk.</div>
  </div>
  <div class="ch-doc">
    <div class="ch-doc-h"><svg class="a-ic" style="color:var(--orange)"><use href="#a-doc"/></svg>Chalani — बस चलानी (Nepali waybill) <span class="pill st-orange nodot">PDF · PNG</span></div>
    <div class="ch-prev"><a href="<?= $hrefChalaniPng ?>" target="_blank" rel="noopener" title="All pages"><img src="<?= $hrefChalaniP1 ?>" alt="Chalani page 1" loading="lazy"></a></div>
    <div class="ch-acts">
      <a class="btn" href="<?= $hrefChalaniPdf ?>"><svg class="a-ic"><use href="#a-pdf"/></svg>Download PDF</a>
      <a class="btn ghost" href="<?= $hrefChalaniPng ?>" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-image"/></svg>PNG pages (<?= (int) $pageCount ?>)</a>
      <a class="btn ghost" href="<?= $hrefChalaniPrt ?>" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-printer"/></svg>Print</a>
    </div>
    <div class="text-xs muted" style="padding:0 16px 12px">The office's printed form: crew fields, passenger table, cash line, signatures.</div>
  </div>
  <div class="ch-doc">
    <div class="ch-doc-h"><svg class="a-ic" style="color:var(--wa-600,#128C7E)"><use href="#a-image"/></svg>Seat status card — live colours, no names <span class="pill st-info nodot">PNG</span></div>
    <div class="ch-prev"><a href="<?= $hrefStatus ?>" target="_blank" rel="noopener" title="Open full size"><img src="<?= $hrefStatus ?>" alt="Seat status card" loading="lazy"></a></div>
    <div class="ch-acts">
      <a class="btn" href="<?= $hrefStatusDl ?>"><svg class="a-ic"><use href="#a-download"/></svg>Download PNG</a>
      <a class="btn ghost" href="<?= $hrefStatus ?>&amp;fresh=1" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-external"/></svg>Redraw now</a>
    </div>
    <div class="text-xs muted" style="padding:0 16px 12px">The card the office / agent WhatsApp receives on every confirmed or cancelled booking (Settings → seat_status_wa_on). Safe for an agent group: sold / free / private / held counts only.</div>
  </div>
</div>

<div class="panel">
  <h2><svg class="a-ic" style="color:var(--wa-600)"><use href="#a-whatsapp"/></svg> 3 · Send on WhatsApp</h2>
  <div class="panel-body">
    <div class="ch-send" id="chSend">
      <label>Document
        <select id="chDoc">
          <option value="challan">Challan — seat picture</option>
          <?php for ($p = 1; $p <= $pageCount; $p++): ?>
            <option value="chalani:<?= $p ?>">Chalani page <?= $p ?> of <?= (int) $pageCount ?></option>
          <?php endfor; ?>
        </select>
      </label>
      <label>Send to
        <select id="chTarget">
          <?php if ($var['driverPhone'] !== ''): ?><option value="driver">Driver · <?= Security::e($var['driver'] . ' ' . $var['driverPhone']) ?></option><?php endif; ?>
          <option value="office">Office WhatsApp</option>
          <option value="custom">Another number…</option>
        </select>
      </label>
      <label id="chPhoneWrap" hidden>Number
        <span class="row" style="gap:6px;flex-wrap:nowrap">
          <select id="chCountry" style="flex:0 0 auto"><option value="IN">🇮🇳 +91</option><option value="NP">🇳🇵 +977</option></select>
          <input type="tel" id="chPhone" placeholder="10-digit mobile" inputmode="numeric" maxlength="15" style="flex:1 1 auto;min-width:0">
        </span>
      </label>
      <div><?= admin_wa_button('chalan_send', ['sid' => $sid, 'doc' => 'challan', 'page' => 1, 'target' => $var['driverPhone'] !== '' ? 'driver' : 'office'], 'Send on WhatsApp', ['class' => 'btn wa', 'id' => 'chWaBtn']) ?></div>
    </div>
    <p class="text-xs muted" style="margin:12px 0 0">Sent through the WhatsApp API when configured (logged in Message Log), otherwise it opens on your phone with the picture link. Links stay valid <?= (int) max(1, Settings::getInt('wa_statement_link_days', 7)) ?> days.</p>
  </div>
</div>
<script>
(function(){
  var doc=document.getElementById('chDoc'),tg=document.getElementById('chTarget'),wrap=document.getElementById('chPhoneWrap'),
      ph=document.getElementById('chPhone'),cc=document.getElementById('chCountry'),btn=document.getElementById('chWaBtn');
  if(!btn)return;
  function sync(){
    var v=doc.value.split(':');
    btn.dataset.waDoc=v[0];btn.dataset.waPage=v[1]||'1';
    btn.dataset.waTarget=tg.value;
    wrap.hidden=tg.value!=='custom';
    if(tg.value==='custom'){btn.dataset.waPhone=(ph.value||'').replace(/\D/g,'');btn.dataset.waCountry=cc.value;}
    else{delete btn.dataset.waPhone;delete btn.dataset.waCountry;}
  }
  [doc,tg,ph,cc].forEach(function(el){el.addEventListener('change',sync);el.addEventListener('input',sync);});
  btn.addEventListener('click',function(e){sync();if(tg.value==='custom'&&(btn.dataset.waPhone||'').length<8){e.stopImmediatePropagation();e.preventDefault();ph.focus();ph.reportValidity&&ph.reportValidity();}},true);
  sync();
})();
</script>

<?php endif; ?>
<?php admin_footer(); ?>
