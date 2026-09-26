<?php
/**
 * admin/analytics.php — reporting dashboard (Chart.js).
 *
 * Read-only. Pulls straight from the live tables and renders revenue,
 * status, cabin, payment-method and boarding-point charts. Every figure
 * is real (no seed/demo data) and the page degrades to an empty-state
 * note when there are no bookings yet.
 *
 * NPR bookings are normalised to INR with the company peg (1 INR = 1.6 NPR,
 * from the Source-of-Truth) so a single revenue axis stays honest.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

/* The peg is Admin → Settings → npr_per_inr — the row the desk, api/quote.php
   and Accounting read — not the literal that used to be compiled in here. It
   divides ONLY legacy rows whose stored currency is NPR; a Nepal desk's sale
   (26 Sep 2026) is stored in rupees with its NPR frozen beside it. Rendered
   into SQL as a fixed-format number so a setting can never carry text in. */
$peg = Settings::getFloat('npr_per_inr', defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6);
if ($peg <= 0) { $peg = 1.6; }
$pegSql = sprintf('%.4F', $peg);
/** SQL fragment: total_amount expressed in INR regardless of stored currency. */
$REV_INR = "SUM(CASE WHEN currency='NPR' THEN total_amount/$pegSql ELSE total_amount END)";

/* Confirmed for money purposes = confirmed OR completed. */
$paidStatuses = "'confirmed','completed'";

$d30  = date('Y-m-d', strtotime('-29 days')); // inclusive 30-day window
$d30s = date('Y-m-d 00:00:00', strtotime('-29 days'));

/* ---- KPI cards (last 30 days) ------------------------------------- */
$revenue30 = (float) Database::scalar(
    "SELECT COALESCE(" . $REV_INR . ",0) FROM bookings
      WHERE status IN ($paidStatuses) AND confirmed_at >= :d0",
    ['d0' => $d30s]
);
$bookings30 = (int) Database::scalar(
    "SELECT COUNT(*) FROM bookings WHERE created_at >= :d0",
    ['d0' => $d30s]
);
$confirmed30 = (int) Database::scalar(
    "SELECT COUNT(*) FROM bookings WHERE status IN ($paidStatuses) AND confirmed_at >= :d0",
    ['d0' => $d30s]
);
$seats30 = (int) Database::scalar(
    "SELECT COUNT(*) FROM booking_seats bs
       JOIN bookings b ON b.id = bs.booking_id
      WHERE b.status IN ($paidStatuses) AND bs.released_at IS NULL
        AND b.confirmed_at >= :d0",
    ['d0' => $d30s]
);
$avgFare = $confirmed30 > 0 ? $revenue30 / $confirmed30 : 0.0;

/* ---- Chart 1: revenue per day, last 30 days ----------------------- */
$revRows = Database::fetchAll(
    "SELECT DATE(confirmed_at) d, " . $REV_INR . " rev
       FROM bookings
      WHERE status IN ($paidStatuses) AND confirmed_at >= :d0
      GROUP BY DATE(confirmed_at)",
    ['d0' => $d30s]
);
$revByDay = [];
foreach ($revRows as $r) {
    $revByDay[(string) $r['d']] = round((float) $r['rev'], 2);
}
$revLabels = [];
$revData   = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $revLabels[] = date('d M', strtotime($day));
    $revData[]   = $revByDay[$day] ?? 0;
}

/* ---- Chart 2: booking status breakdown (all time) ----------------- */
$statusRows = Database::fetchAll("SELECT status, COUNT(*) c FROM bookings GROUP BY status");
$statusLabels = [];
$statusData   = [];
foreach ($statusRows as $r) {
    $statusLabels[] = ucfirst((string) $r['status']);
    $statusData[]   = (int) $r['c'];
}

/* ---- Chart 3: cabin / booking mode (confirmed) -------------------- */
$modeRows = Database::fetchAll(
    "SELECT COALESCE(NULLIF(booking_mode,''),'seater') m, COUNT(*) c
       FROM bookings WHERE status IN ($paidStatuses) GROUP BY m ORDER BY c DESC"
);
$modeLabels = [];
$modeData   = [];
foreach ($modeRows as $r) {
    $modeLabels[] = ucfirst((string) $r['m']);
    $modeData[]   = (int) $r['c'];
}

/* ---- Chart 4: payment method split (confirmed) -------------------- */
$payRows = Database::fetchAll(
    "SELECT p.method, COUNT(DISTINCT p.booking_id) c
       FROM payments p JOIN bookings b ON b.id = p.booking_id
      WHERE b.status IN ($paidStatuses)
      GROUP BY p.method ORDER BY c DESC"
);
$payLabels = [];
$payData   = [];
$payNames  = ['upi' => 'UPI', 'esewa' => 'eSewa', 'cash' => 'Cash', 'cod' => 'COD', 'bank' => 'Bank', 'wallet' => 'Wallet'];
foreach ($payRows as $r) {
    $m = (string) $r['method'];
    $payLabels[] = $payNames[$m] ?? ucfirst($m);
    $payData[]   = (int) $r['c'];
}

/* ---- Chart 5: top boarding points (confirmed, outbound) ----------- */
$bpRows = Database::fetchAll(
    "SELECT COALESCE(NULLIF(bl.boarding_stop,''),'—') bp, COUNT(*) c
       FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id
      WHERE bl.leg_type = 'outbound' AND b.status IN ($paidStatuses)
      GROUP BY bp ORDER BY c DESC LIMIT 8"
);
$bpLabels = [];
$bpData   = [];
foreach ($bpRows as $r) {
    $bpLabels[] = (string) $r['bp'];
    $bpData[]   = (int) $r['c'];
}

/* ---- Route-wise earnings (6 Sep 2026) -----------------------------
   Revenue was grouped by day, status, cabin mode, payment method,
   boarding point and agent — never by route. For a company running one
   corridor with several town pairs, "which route earns" is the report the
   owner actually asks for, and it was the one number the panel could not
   answer.

   Joined through the OUTBOUND leg so a round trip counts once, against the
   direction it was sold as, and uses the same REV_INR peg and paid-status
   set as every other figure on this page so the totals reconcile. */
$routeRows = Database::fetchAll(
    "SELECT r.id, r.route_code, r.from_city, r.to_city,
            COUNT(DISTINCT b.id)            AS tickets,
            COALESCE(SUM(bl.seat_count), 0) AS seats,
            SUM(CASE WHEN b.currency = 'NPR'
                     THEN b.total_amount / " . $pegSql . "
                     ELSE b.total_amount END) AS rev,
            COUNT(DISTINCT bl.schedule_id)  AS trips
       FROM bookings b
       JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
       JOIN schedules s     ON s.id = bl.schedule_id
       JOIN routes r        ON r.id = s.route_id
      WHERE b.status IN ($paidStatuses)
      GROUP BY r.id, r.route_code, r.from_city, r.to_city
      ORDER BY rev DESC"
);

/* Capacity is a SEPARATE pass on purpose. Folding it into the query above
   would multiply each schedule's seat count by the number of bookings on
   it and report occupancy far below 100%. Counted over the distinct
   schedules that actually carried a paid sale, and read off the bus the
   schedule ran — never schedules.total_seats, which is bookkeeping only
   and is not a seat cap. */
$capByRoute = [];
foreach (Database::fetchAll(
    "SELECT s.route_id, SUM(COALESCE(bus.total_seats, rb.total_seats, 0)) AS cap
       FROM (SELECT DISTINCT bl.schedule_id
               FROM booking_legs bl
               JOIN bookings b ON b.id = bl.booking_id
              WHERE bl.leg_type = 'outbound' AND b.status IN ($paidStatuses)) AS sold
       JOIN schedules s    ON s.id = sold.schedule_id
       JOIN routes r2      ON r2.id = s.route_id
       LEFT JOIN buses bus ON bus.id = s.bus_id
       LEFT JOIN buses rb  ON rb.id  = r2.bus_id
      GROUP BY s.route_id"
) as $c) {
    $capByRoute[(int) $c['route_id']] = (int) $c['cap'];
}

/* Anything the route join cannot attribute. On production today this is
   zero — every paid booking has an outbound leg — but a booking with no
   leg row would otherwise vanish from this report with no hint, and a
   revenue table that silently under-reports is worse than no table. The
   local test database has 51 such legacy rows, which is how this was
   caught. Shown as its own line rather than folded into a route. */
$unattributed = Database::fetch(
    "SELECT COUNT(*) AS n,
            SUM(CASE WHEN b.currency = 'NPR'
                     THEN b.total_amount / " . $pegSql . "
                     ELSE b.total_amount END) AS rev
       FROM bookings b
      WHERE b.status IN ($paidStatuses)
        AND NOT EXISTS (SELECT 1 FROM booking_legs bl
                         WHERE bl.booking_id = b.id AND bl.leg_type = 'outbound')"
) ?: ['n' => 0, 'rev' => 0];
$unattrN   = (int) ($unattributed['n'] ?? 0);
$unattrRev = (float) ($unattributed['rev'] ?? 0);

$routeTotalRev   = 0.0;
$routeTotalSeats = 0;
$routeLabels     = [];
$routeData       = [];
foreach ($routeRows as $i => $rr) {
    $routeTotalRev   += (float) $rr['rev'];
    $routeTotalSeats += (int) $rr['seats'];
    if ($i < 8) {
        $routeLabels[] = trim((string) $rr['from_city']) . ' → ' . trim((string) $rr['to_city']);
        $routeData[]   = round((float) $rr['rev'], 2);
    }
}

$hasData = $bookings30 > 0 || $statusData !== [];

/* Everything the front-end needs, JSON-safe for inline <script>. */
$payload = [
    'rev'    => ['labels' => $revLabels,   'data' => $revData],
    'status' => ['labels' => $statusLabels,'data' => $statusData],
    'mode'   => ['labels' => $modeLabels,  'data' => $modeData],
    'pay'    => ['labels' => $payLabels,   'data' => $payData],
    'bp'     => ['labels' => $bpLabels,    'data' => $bpData],
    'route'  => ['labels' => $routeLabels, 'data' => $routeData],
];
$payloadJson = json_encode(
    $payload,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
);

admin_header('Analytics', 'analytics');
?>
<style>
  .an-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:22px;margin-bottom:22px}
  .an-grid .panel{margin-bottom:0}
  .an-grid .panel.wide{grid-column:1 / -1}
  .an-head{display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding:14px 18px;border-bottom:1px solid var(--line);background:var(--head)}
  .an-head h2{margin:0;padding:0;border:0;background:none;font-size:15px}
  .an-body{padding:16px 18px}
  .an-canvas{position:relative;height:300px}
  .an-canvas.tall{height:340px}
  .an-note{padding:26px 18px;color:var(--mut);font-size:14px}
  .an-export{background:var(--card);border:1px solid var(--line);color:var(--ink);
    padding:5px 10px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer}
  .an-export:hover{background:var(--hover)}
  @media(max-width:820px){.an-grid{grid-template-columns:1fr}}
</style>

<?php if (!$hasData): ?>
  <div class="panel"><div class="an-note">No bookings yet — charts will appear here once orders start coming in.</div></div>
<?php endif; ?>

<div class="cards">
  <div class="card"><div class="k">Revenue · 30 days</div><div class="v"><?= Security::e(inr($revenue30)) ?></div></div>
  <div class="card"><div class="k">Bookings · 30 days</div><div class="v"><?= $bookings30 ?></div></div>
  <div class="card"><div class="k">Confirmed · 30 days</div><div class="v"><?= $confirmed30 ?></div></div>
  <div class="card"><div class="k">Seats sold · 30 days</div><div class="v"><?= $seats30 ?></div></div>
  <div class="card"><div class="k">Avg fare · confirmed</div><div class="v"><?= Security::e(inr($avgFare)) ?></div></div>
</div>

<div class="an-grid">
  <div class="panel wide">
    <div class="an-head"><h2>Revenue — last 30 days (₹, legacy NPR rows converted @ 1:<?= Security::e((string) $peg) ?>)</h2>
      <button class="an-export" type="button" data-export="revChart" data-name="revenue-30d">Export PNG</button></div>
    <div class="an-body"><div class="an-canvas"><canvas id="revChart"></canvas></div></div>
  </div>

  <div class="panel">
    <div class="an-head"><h2>Booking status</h2>
      <button class="an-export" type="button" data-export="statusChart" data-name="status">Export PNG</button></div>
    <div class="an-body"><div class="an-canvas"><canvas id="statusChart"></canvas></div></div>
  </div>

  <div class="panel">
    <div class="an-head"><h2>Cabin mix (confirmed)</h2>
      <button class="an-export" type="button" data-export="modeChart" data-name="cabin-mix">Export PNG</button></div>
    <div class="an-body"><div class="an-canvas"><canvas id="modeChart"></canvas></div></div>
  </div>

  <div class="panel">
    <div class="an-head"><h2>Payment method (confirmed)</h2>
      <button class="an-export" type="button" data-export="payChart" data-name="payment-method">Export PNG</button></div>
    <div class="an-body"><div class="an-canvas"><canvas id="payChart"></canvas></div></div>
  </div>

  <div class="panel">
    <div class="an-head"><h2>Top boarding points</h2>
      <button class="an-export" type="button" data-export="bpChart" data-name="boarding-points">Export PNG</button></div>
    <div class="an-body"><div class="an-canvas tall"><canvas id="bpChart"></canvas></div></div>
  </div>
</div>

<div class="panel">
  <h2>Route earnings</h2>
  <p class="muted" style="margin-top:-6px">
    Confirmed and completed sales, counted against the outbound leg so a round
    trip is not double-counted. NPR converted at the same 1&nbsp;:&nbsp;<?= Security::e((string) $peg) ?> peg (Settings → npr_per_inr)
    used everywhere else on this page, so these totals reconcile with Accounting.
  </p>

  <?php if ($routeRows === []): ?>
    <p class="muted">No confirmed sales yet.</p>
  <?php else: ?>
  <div class="tbl-scroll">
    <table class="dt card-table">
      <thead>
        <tr>
          <th>Route</th>
          <th data-type="num">Tickets</th>
          <th data-type="num">Seats</th>
          <th data-type="num">Trips run</th>
          <th data-type="num">Occupancy</th>
          <th data-type="num">Avg / seat</th>
          <th data-type="num">Revenue</th>
          <th data-type="num">Share</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($routeRows as $rr):
        $rev   = (float) $rr['rev'];
        $seats = (int) $rr['seats'];
        $cap   = $capByRoute[(int) $rr['id']] ?? 0;
        $occ   = $cap > 0 ? round($seats * 100 / $cap) : null;
        $share = $routeTotalRev > 0 ? round($rev * 100 / $routeTotalRev) : 0;
      ?>
        <tr>
          <td data-label="Route">
            <b><?= Security::e(trim((string) $rr['from_city'])) ?> → <?= Security::e(trim((string) $rr['to_city'])) ?></b>
            <?php if (!empty($rr['route_code'])): ?>
              <br><span class="muted" style="font-size:12px"><?= Security::e((string) $rr['route_code']) ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Tickets" data-sort="<?= (int) $rr['tickets'] ?>"><?= (int) $rr['tickets'] ?></td>
          <td data-label="Seats" data-sort="<?= $seats ?>"><?= $seats ?></td>
          <td data-label="Trips run" data-sort="<?= (int) $rr['trips'] ?>"><?= (int) $rr['trips'] ?></td>
          <td data-label="Occupancy" data-sort="<?= $occ === null ? -1 : $occ ?>">
            <?php if ($occ === null): ?>
              <span class="muted" title="No bus seat count on the schedules that ran">—</span>
            <?php else: ?>
              <?= $occ ?>%<br><span class="muted" style="font-size:11px"><?= $seats ?> of <?= $cap ?></span>
            <?php endif; ?>
          </td>
          <td data-label="Avg / seat" data-sort="<?= $seats > 0 ? (int) round($rev / $seats) : 0 ?>">
            <?= $seats > 0 ? Security::e(inr($rev / $seats)) : '—' ?>
          </td>
          <td data-label="Revenue" data-sort="<?= (int) $rev ?>"><b><?= Security::e(inr($rev)) ?></b></td>
          <td data-label="Share" data-sort="<?= $share ?>"><?= $share ?>%</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <?php if ($unattrN > 0): ?>
        <tr>
          <th style="color:var(--bad,#C53030)">Not attributed to a route</th>
          <th><?= $unattrN ?></th>
          <th colspan="4"><span class="muted" style="font-weight:400;font-size:12px">
            paid bookings with no outbound leg row — they are real money, but
            this report cannot say which route earned it
          </span></th>
          <th><?= Security::e(inr($unattrRev)) ?></th>
          <th></th>
        </tr>
        <?php endif; ?>
        <tr>
          <th>All routes</th>
          <th colspan="1"></th>
          <th><?= $routeTotalSeats ?></th>
          <th colspan="3"></th>
          <th><?= Security::e(inr($routeTotalRev)) ?></th>
          <th>100%</th>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="an-card" style="margin-top:14px">
    <div class="an-head"><span>Revenue by route</span>
      <button class="an-export" type="button" data-export="routeChart" data-name="route-earnings">Export PNG</button></div>
    <div class="an-body"><div class="an-canvas tall"><canvas id="routeChart"></canvas></div></div>
  </div>
  <?php endif; ?>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<script>
(function () {
  var DATA = <?= $payloadJson ?>;
  var NAVY = '#12264E', BLUE = '#2E5FA8', ORANGE = '#F07C1F';
  // Categorical palette (brand-led, then supporting hues) for the doughnuts.
  var PALETTE = ['#2E5FA8', '#F07C1F', '#138808', '#DC143C', '#3B2A6E', '#F5A623', '#0a6b3b', '#9BA8B0'];
  var charts = {};

  function money(v) {
    return '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  }

  function ready() {
    if (typeof Chart === 'undefined') { return setTimeout(ready, 60); }
    Chart.defaults.font.family = "system-ui,-apple-system,'Segoe UI',Roboto,sans-serif";
    // Theme-aware axis/legend text (charts draw once; toggling theme needs a reload).
    Chart.defaults.color = document.documentElement.getAttribute('data-theme') === 'dark' ? '#c7d2e5' : '#4a5568';

    // 1) Revenue bar
    charts.revChart = new Chart(document.getElementById('revChart'), {
      type: 'bar',
      data: {
        labels: DATA.rev.labels,
        datasets: [{ label: 'Revenue', data: DATA.rev.data, backgroundColor: BLUE,
          hoverBackgroundColor: ORANGE, borderRadius: 4, maxBarThickness: 26 }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: function (c) { return money(c.parsed.y); } } }
        },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
          y: { beginAtZero: true, ticks: { callback: function (v) { return money(v); } } }
        }
      }
    });

    // Doughnut helper
    function doughnut(id, block) {
      if (!block.data.length) { return; }
      charts[id] = new Chart(document.getElementById(id), {
        type: 'doughnut',
        data: {
          labels: block.labels,
          datasets: [{ data: block.data, backgroundColor: PALETTE, borderWidth: 2, borderColor: '#fff' }]
        },
        options: {
          responsive: true, maintainAspectRatio: false, cutout: '58%',
          plugins: { legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } } }
        }
      });
    }
    doughnut('statusChart', DATA.status);
    doughnut('modeChart',   DATA.mode);
    doughnut('payChart',    DATA.pay);

    // 5) Top boarding points — horizontal bar
    if (DATA.bp.data.length) {
      charts.bpChart = new Chart(document.getElementById('bpChart'), {
        type: 'bar',
        data: {
          labels: DATA.bp.labels,
          datasets: [{ label: 'Bookings', data: DATA.bp.data, backgroundColor: NAVY,
            hoverBackgroundColor: ORANGE, borderRadius: 4, maxBarThickness: 24 }]
        },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid: { display: false } },
                    y: { grid: { display: false } } }
        }
      });
    }

    /* Revenue by route. Only rendered when the panel above actually drew a
       canvas — with no confirmed sales the whole block is absent. */
    if (document.getElementById('routeChart') && DATA.route && DATA.route.labels.length) {
      charts.routeChart = new Chart(document.getElementById('routeChart'), {
        type: 'bar',
        data: {
          labels: DATA.route.labels,
          datasets: [{ label: 'Revenue (INR)', data: DATA.route.data, backgroundColor: NAVY,
            hoverBackgroundColor: ORANGE, borderRadius: 4, maxBarThickness: 24 }]
        },
        options: {
          indexAxis: 'y', responsive: true, maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: function (c) {
              return '₹' + Number(c.parsed.x).toLocaleString('en-IN');
            } } }
          },
          scales: {
            x: { beginAtZero: true, grid: { display: false },
                 ticks: { callback: function (v) { return '₹' + Number(v).toLocaleString('en-IN'); } } },
            y: { grid: { display: false } }
          }
        }
      });
    }

    // Export PNG buttons
    document.querySelectorAll('.an-export').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var ch = charts[btn.getAttribute('data-export')];
        if (!ch) { return; }
        var a = document.createElement('a');
        a.download = 'SHG-' + btn.getAttribute('data-name') + '.png';
        a.href = ch.toBase64Image('image/png', 1);
        a.click();
      });
    });
  }
  ready();
})();
</script>
<?php
admin_footer();
