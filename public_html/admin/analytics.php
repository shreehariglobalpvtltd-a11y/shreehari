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

/* 1 INR = 1.6 NPR — the app already defines this peg in config; only
   fall back if some environment hasn't (avoids a redefinition fatal). */
if (!defined('NPR_PER_INR')) {
    define('NPR_PER_INR', 1.6);
}
/** SQL fragment: total_amount expressed in INR regardless of stored currency. */
const REV_INR = "SUM(CASE WHEN currency='NPR' THEN total_amount/" . NPR_PER_INR . " ELSE total_amount END)";

/* Confirmed for money purposes = confirmed OR completed. */
$paidStatuses = "'confirmed','completed'";

$d30  = date('Y-m-d', strtotime('-29 days')); // inclusive 30-day window
$d30s = date('Y-m-d 00:00:00', strtotime('-29 days'));

/* ---- KPI cards (last 30 days) ------------------------------------- */
$revenue30 = (float) Database::scalar(
    "SELECT COALESCE(" . REV_INR . ",0) FROM bookings
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
    "SELECT DATE(confirmed_at) d, " . REV_INR . " rev
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

$hasData = $bookings30 > 0 || $statusData !== [];

/* Everything the front-end needs, JSON-safe for inline <script>. */
$payload = [
    'rev'    => ['labels' => $revLabels,   'data' => $revData],
    'status' => ['labels' => $statusLabels,'data' => $statusData],
    'mode'   => ['labels' => $modeLabels,  'data' => $modeData],
    'pay'    => ['labels' => $payLabels,   'data' => $payData],
    'bp'     => ['labels' => $bpLabels,    'data' => $bpData],
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
    padding:14px 18px;border-bottom:1px solid var(--line);background:#fafbfe}
  .an-head h2{margin:0;padding:0;border:0;background:none;font-size:15px}
  .an-body{padding:16px 18px}
  .an-canvas{position:relative;height:300px}
  .an-canvas.tall{height:340px}
  .an-note{padding:26px 18px;color:var(--mut);font-size:14px}
  .an-export{background:#fff;border:1px solid var(--line);color:var(--ink);
    padding:5px 10px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer}
  .an-export:hover{background:#eef2fa}
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
    <div class="an-head"><h2>Revenue — last 30 days (₹, NPR converted @ 1:<?= NPR_PER_INR ?>)</h2>
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
    Chart.defaults.color = '#4a5568';

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
