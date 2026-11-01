<?php
/**
 * bus-phone-setup.php?id=&e=&sig= — one-scan setup for the bus phone
 * (owner, 31 Oct 2026: "GPSLogger install garna jhau bhayo — simple setup,
 * download, ekchoti permission, bas").
 *
 * Traccar Client (free, Play Store) has "Scan QR code": a QR holding
 *   https://www.shreehariglobal.in/api/gps.php?id=<SHG code>&interval=10…
 * sets the server URL (origin + path), the device id and the tracking
 * settings in one go (traccar-client lib/configuration_service.dart,
 * applyUri). So the bus phone needs: install → scan → allow location → ON.
 *
 * The same signed 24-hour link as the OwnTracks / GPSLogger setup pages
 * (GpsLoggerProfile). Open it on the office screen and scan with the bus
 * phone; opened on the bus phone itself it shows copy buttons instead.
 * ?check=1 answers the page's live test light: last report age only —
 * never coordinates or the device id.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/gpsloggerprofile.php';
require_once INCLUDE_PATH . '/qr.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }

$tracker = GpsLoggerProfile::authorizedTracker($_GET);
if ($tracker === null) {
    http_response_code(404);
    exit('Setup link expired. Get a new link from Admin > GPS trackers. · Link पुरानो भयो — Admin > GPS trackers बाट नयाँ link लिनुहोस्।');
}

if (isset($_GET['check'])) {
    header('Content-Type: application/json');
    $row = Database::fetch('SELECT last_seen, last_fix_at, last_batt FROM gps_trackers WHERE id = :i', ['i' => (int) $tracker['id']]);
    $age = ($row['last_seen'] ?? null) !== null ? max(0, time() - (int) strtotime((string) $row['last_seen'])) : null;
    echo json_encode(['age' => $age, 'batt' => $row['last_batt'] !== null ? (int) $row['last_batt'] : null]);
    exit;
}

$endpoint = GpsTrackers::PUBLIC_URL . '/api/gps.php';
$uid      = (string) $tracker['device_uid'];
// Every 10 s, highest accuracy, keep fixes while offline, stay awake — the
// same pace the live map and the "bus is near" alert are built for.
$config = $endpoint . '?' . http_build_query([
    'id' => $uid, 'accuracy' => 'highest', 'interval' => 10, 'distance' => 0, 'angle' => 0,
    'heartbeat' => 60, 'buffer' => 'true', 'wakelock' => 'true', 'stop_detection' => 'false',
]);
$qr    = QrCode::dataUri($config, 7, 3);
$self  = GpsTrackers::PUBLIC_URL . '/bus-phone-setup.php?' . http_build_query(['id' => (int) $_GET['id'], 'e' => (int) $_GET['e'], 'sig' => (string) $_GET['sig']]);
$e     = static fn(string $s): string => Security::e($s);
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bus phone setup · S Hari Global</title>
<style>
body{margin:0;background:#f1f5f9;color:#173047;font:16px/1.55 system-ui,"Noto Sans Devanagari",sans-serif}
main{max-width:560px;margin:20px auto;padding:22px;background:#fff;border-radius:20px}
h1{font-size:26px;margin:2px 0 6px}small{color:#51647b}
.step{display:flex;gap:12px;margin:16px 0;align-items:flex-start}
.n{flex:0 0 34px;height:34px;border-radius:50%;background:#124b72;color:#fff;font-weight:900;display:grid;place-items:center}
a.btn,button.btn{display:block;box-sizing:border-box;width:100%;border:0;border-radius:12px;padding:14px;margin:10px 0;background:#124b72;color:#fff;text-decoration:none;text-align:center;font-weight:800;font-size:16px;cursor:pointer;min-height:48px}
.light{background:#e7edf3!important;color:#173047!important}
.qr{text-align:center;background:#fff;border:2px solid #124b72;border-radius:16px;padding:10px;margin:8px 0}
.qr img{width:100%;max-width:320px;image-rendering:pixelated}
.copy{display:flex;gap:8px;align-items:center;background:#f1f5f9;border-radius:10px;padding:8px 10px;margin:6px 0}
.copy code{flex:1;overflow-wrap:anywhere;font-size:14px}
.copy button{border:0;background:#124b72;color:#fff;border-radius:8px;padding:8px 12px;font-weight:700;min-height:40px}
#light{border-radius:14px;padding:14px;margin-top:14px;font-weight:800;text-align:center;background:#fff4d6;color:#7a5200}
#light.ok{background:#dcf5e5;color:#0f6b36}
@media(max-width:600px){main{margin:10px;padding:16px}}
</style>
</head>
<body>
<main>
  <small>S HARI GLOBAL · BUS PHONE GPS · बसको फोन</small>
  <h1>🚌 <?= $e((string) $tracker['bus_number']) ?></h1>
  <p>यो फोन बसमै राख्नुहोस्। ४ चरण — केही टाइप गर्नु पर्दैन। · Keep this phone in the bus. 4 steps, nothing to type.</p>

  <div class="step"><div class="n">1</div><div style="flex:1">
    <b>Traccar Client app डाउनलोड गर्नुहोस्</b> (free)
    <a class="btn" href="https://play.google.com/store/apps/details?id=org.traccar.client" target="_blank" rel="noopener noreferrer">⬇️ Play Store · Traccar Client</a>
  </div></div>

  <div class="step"><div class="n">2</div><div style="flex:1">
    <b>App खोल्नुहोस् → Settings (⚙️) → “Scan QR code” → यो QR scan गर्नुहोस्।</b>
    <small>Open the app → Settings → Scan QR code → scan this. Server, bus code and speed are set by themselves.</small>
    <div class="qr"><img src="<?= $e($qr) ?>" alt="Traccar Client setup QR code for bus <?= $e((string) $tracker['bus_number']) ?>"></div>
    <details><summary>QR scan गर्न नमिले (यही फोनमा link खोल्नुभयो भने)</summary>
      <p><small>Settings मा यी दुई कुरा paste गर्नुहोस्: · Paste these two in Settings:</small></p>
      <div class="copy"><code>Server URL: <?= $e($endpoint) ?></code><button type="button" data-copy="<?= $e($endpoint) ?>">Copy</button></div>
      <div class="copy"><code>Device identifier: <?= $e($uid) ?></code><button type="button" data-copy="<?= $e($uid) ?>">Copy</button></div>
      <p><small>Location accuracy: <b>Highest</b> · Interval: <b>10</b> seconds · Distance: <b>0</b></small></p>
    </details>
  </div></div>

  <div class="step"><div class="n">3</div><div style="flex:1">
    <b>Permission एकपटक दिनुहोस्:</b> Location → <b>Allow all the time</b> + <b>Precise</b>। Battery → <b>Unrestricted</b> (battery saver बन्द)।
    <small>Allow location “All the time” and set battery to “Unrestricted”, so it keeps sending with the screen off.</small>
  </div></div>

  <div class="step"><div class="n">4</div><div style="flex:1">
    <b>Service status → ON</b> गर्नुहोस्। फोन बसमा charger मा राख्नुहोस्।
    <small>Turn Service status ON and keep the phone on charge in the bus.</small>
  </div></div>

  <div id="light" role="status" aria-live="polite">⏳ पहिलो location पर्खँदै… · Waiting for the first report…</div>

  <a class="btn light" href="https://wa.me/?text=<?= rawurlencode('🚌 ' . $tracker['bus_number'] . ' — bus phone GPS setup (24 h link): ' . $self) ?>" target="_blank" rel="noopener noreferrer">💬 यो setup link WhatsApp मा पठाउनुहोस्</a>
  <p><small>एउटै फोनमा GPSLogger / OwnTracks पनि चलिरहेको भए बन्द गर्नुहोस् — एउटा मात्र app राख्नुहोस्। यो निजी link २४ घण्टा मान्य हुन्छ। · Use only one tracking app per phone. This private link works for 24 hours.</small></p>
</main>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-copy]'); if (!b) return;
  var t = b.getAttribute('data-copy');
  (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { b.textContent = '✓'; })
    .catch(function () { window.prompt('Copy:', t); });
});
(function poll() {
  fetch(location.pathname + location.search + '&check=1', { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (d) {
    var el = document.getElementById('light');
    if (d.age !== null && d.age <= 120) {
      el.className = 'ok';
      el.textContent = '✅ चलिरहेको छ — ' + d.age + ' सेकेन्ड अघि location आयो' + (d.batt !== null ? ' · battery ' + d.batt + '%' : '') + ' · Working';
    } else if (d.age !== null) {
      el.className = '';
      el.textContent = '⚠️ अन्तिम location ' + Math.round(d.age / 60) + ' मिनेट अघि — Service ON र permission जाँच्नुहोस्।';
    }
  }).catch(function () {}).then(function () { setTimeout(poll, 5000); });
})();
</script>
</body>
</html>
