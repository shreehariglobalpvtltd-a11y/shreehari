<?php
/**
 * admin/gps-trackers.php - GPS trackers by vehicle number (22 Oct 2026).
 *
 * Owner: "mobile ya GPS device trace garera gaadi number wise assign garna
 * milos". Each tracker belongs to a BUS (buses.bus_number):
 *
 *   phone     the free Traccar Client app on a phone that stays in the bus;
 *             the office types the SHG code shown here into the app
 *   hardware  a GPS box (IMEI) on a Traccar server whose forward URL points
 *             at /api/gps.php with the forward key (shown to a super-admin)
 *
 * Every fix lands on the departure that bus runs now (includes/gpstrackers
 * .php), the same live position the driver app writes - track page, "bus
 * is near" WhatsApp and the SHARI NAV map pick it up by themselves.
 *
 * buses.view to look (manager, operations, super-admin), buses.edit to add /
 * switch off / delete. The device id is what lets a phone post the bus
 * position, so counters and agents never see this page.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/gpstrackers.php';
$admin = admin_boot('buses.view');

$base      = '';
$flash     = null;
$canEdit   = Auth::can('buses.edit') || Auth::isSuperadmin();
$canSwitch = Auth::can('settings.manage') || Auth::isSuperadmin();
$isOwner   = Auth::isSuperadmin();
$ready     = GpsTrackers::tableReady();

/* ============================================================
   POST - add / switch off / delete / kill switch / new key.
   ============================================================ */
if ($ready && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired - please try again.'];
    } elseif (!$canEdit) {
        $flash = ['bad', 'You do not have permission to change GPS trackers.'];
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');

            if ($action === 'add') {
                $row = GpsTrackers::add(
                    (int) ($_POST['bus_id'] ?? 0),
                    Security::clean($_POST['label'] ?? '', 80),
                    (string) ($_POST['kind'] ?? 'phone'),
                    Security::clean($_POST['uid'] ?? '', 64)
                );
                Logger::audit('gps_tracker.add', 'bus', (string) $row['bus_id'], null,
                    ['tracker' => (int) $row['id'], 'kind' => $row['kind'], 'label' => $row['label']], 'by admin #' . $admin['id']);
                $flash = ['ok', $row['kind'] === 'phone'
                    ? 'Tracker added. Type the code ' . $row['device_uid'] . ' as "Device identifier" in Traccar Client on the bus phone (steps below).'
                    : 'Tracker added. Point the Traccar server forward URL here (steps below) - its fixes count only with the forward key.'];

            } elseif ($action === 'toggle') {
                $id = (int) ($_POST['id'] ?? 0);
                $t  = Database::fetch('SELECT id, bus_id, is_active FROM gps_trackers WHERE id = :i', ['i' => $id]);
                if ($t === null) { throw new RuntimeException('Tracker not found.'); }
                $on = (int) $t['is_active'] !== 1;
                GpsTrackers::setActive($id, $on);
                Logger::audit('gps_tracker.toggle', 'bus', (string) $t['bus_id'], null, ['tracker' => $id, 'active' => $on], 'by admin #' . $admin['id']);
                $flash = ['ok', $on ? 'Tracker switched on again.' : 'Tracker switched off - its positions are refused from now on.'];

            } elseif ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                $t  = Database::fetch('SELECT id, bus_id, label FROM gps_trackers WHERE id = :i', ['i' => $id]);
                if ($t === null) { throw new RuntimeException('Tracker not found.'); }
                GpsTrackers::remove($id);
                Logger::audit('gps_tracker.delete', 'bus', (string) $t['bus_id'], ['tracker' => $id, 'label' => $t['label']], null, 'by admin #' . $admin['id']);
                $flash = ['ok', 'Tracker deleted.'];

            } elseif ($action === 'switch' && $canSwitch) {
                $on = ((string) ($_POST['on'] ?? '')) === '1';
                Settings::set('gps_trackers_on', $on ? '1' : '0', 'bool', 'automation');
                Settings::flush();
                Logger::audit('settings.update', 'settings', 'gps_trackers_on', null, ['on' => $on], 'GPS trackers ' . ($on ? 'ON' : 'OFF'));
                $flash = ['ok', $on ? 'GPS trackers switched ON.' : 'GPS trackers switched OFF - every tracker gets "switched off" until you turn it back on.'];

            } elseif ($action === 'rotate_key' && $isOwner) {
                GpsTrackers::rotateForwardKey();
                Logger::audit('gps_tracker.rotate_key', 'settings', GpsTrackers::KEY_SETTING, null, null, 'by admin #' . $admin['id']);
                $flash = ['ok', 'New forward key made. Put the new forward URL into every Traccar server - the old key stops working now.'];
            }
        } catch (RuntimeException $e) {
            $flash = ['bad', $e->getMessage()];
        } catch (Throwable $e) {
            Logger::exception($e);
            $flash = ['bad', 'Could not save - please try again.'];
        }
    }
}

/* ============================================================
   Load.
   ============================================================ */
$now      = time();
$buses    = $ready ? GpsTrackers::busesWithTrackers() : [];
$runs     = [];
if ($ready && LiveTrips::available()) {
    try { $runs = GpsTrackers::tripsByBus($now); } catch (Throwable $e) { $runs = []; }
}
$switchOn = GpsTrackers::enabled();
$fwdKey   = $ready && $isOwner ? GpsTrackers::forwardKey() : '';
$endpoint = GpsTrackers::PUBLIC_URL . '/api/gps.php';
$fwdUrl   = $endpoint . '?id={uniqueId}&lat={latitude}&lon={longitude}&timestamp={fixTime}&speed={speed}&bearing={course}&key='
          . ($isOwner ? $fwdKey : 'KEY');

$nTrackers = 0; $nLive = 0; $nBuses = 0;
foreach ($buses as $b) {
    $has = false;
    foreach ($b['trackers'] as $t) {
        if ((int) $t['is_active'] !== 1) { continue; }
        $nTrackers++; $has = true;
        if ($t['last_seen'] !== null && $now - (int) strtotime((string) $t['last_seen']) <= 120) { $nLive++; }
    }
    if ($has) { $nBuses++; }
}
$activeBuses = array_values(array_filter($buses, static fn(array $b): bool => (int) $b['is_active'] === 1));

admin_header('GPS trackers (vehicle number)', 'gps-trackers');
?>
<style>
.gt-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.gt-form{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 20px;margin-bottom:20px}
.gt-form h3{margin:0 0 12px;font-size:15px}
.gt-form .row{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:10px}
.gt-form label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
.gt-form input,.gt-form select{padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px;font-weight:400;background:var(--card);color:var(--ink);text-transform:none;letter-spacing:0;min-width:0}
.gt-uidrow{display:flex;gap:6px}
.gt-uidrow input{flex:1;font-family:ui-monospace,Consolas,monospace}
.gt-uid{font-family:ui-monospace,Consolas,monospace;font-weight:700;font-size:13px;letter-spacing:.5px;word-break:break-all}
.gt-kind{display:inline-block;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px}
.gt-k-phone{background:#dbeafe;color:#1e40af}
.gt-k-hardware{background:#fef3c7;color:#92400e}
.gt-on{color:#0a6b3b;font-weight:700}
.gt-warm{color:#b45309;font-weight:700}
.gt-off{color:var(--mut)}
:root[data-theme="dark"] .gt-k-phone{background:#0d2948;color:#93c5fd}
:root[data-theme="dark"] .gt-k-hardware{background:#3e2723;color:#fcd34d}
:root[data-theme="dark"] .gt-on{color:#86efac}
:root[data-theme="dark"] .gt-warm{color:#fcd34d}
.gt-setup{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px}
.gt-setup ol{margin:6px 0 0;padding-left:20px;line-height:1.6;font-size:14px}
.gt-setup .np{color:var(--mut);font-size:13.5px}
.gt-code{display:block;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:8px 10px;margin:6px 0;font-family:ui-monospace,Consolas,monospace;font-size:12.5px;word-break:break-all;white-space:pre-wrap}
.gt-copy{padding:2px 8px;font-size:11px;margin-left:6px}
</style>

<?php if ($flash !== null): ?>
  <div class="flash <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<?php if (!$ready): ?>
  <div class="panel">
    <h2>GPS trackers are not set up on this server yet</h2>
    <p>Run <b>database/upgrade-2026-10-22-gps-trackers.sql</b> once, then open this page again.</p>
  </div>
<?php admin_footer(); exit; endif; ?>

<div class="gt-cards">
  <div class="card"><div class="k">Trackers on</div><div class="v"><?= $nTrackers ?></div></div>
  <div class="card"><div class="k">Sending now</div><div class="v"><?= $nLive ?></div></div>
  <div class="card"><div class="k">Buses tracked</div><div class="v"><?= $nBuses ?> / <?= count($activeBuses) ?></div></div>
  <div class="card"><div class="k">Switch</div><div class="v" style="font-size:18px"><?= $switchOn ? '🟢 ON' : '🔴 OFF' ?></div>
    <?php if ($canSwitch): ?>
      <form method="post" style="margin-top:6px">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="switch">
        <input type="hidden" name="on" value="<?= $switchOn ? '0' : '1' ?>">
        <button class="btn ghost" type="submit" style="padding:4px 10px;font-size:12px"
          <?= $switchOn ? 'onclick="return confirm(\'Switch OFF every GPS tracker? Buses stop moving on the map until you switch back on.\')"' : '' ?>><?= $switchOn ? 'Switch off' : 'Switch on' ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($canEdit): ?>
<div class="gt-form" id="add">
  <h3>➕ Add a tracker to a bus · बसमा ट्र्याकर थप्नुहोस्</h3>
  <?php if ($activeBuses === []): ?>
    <div class="flash bad" style="margin:0">No active bus - add one in <a href="<?= $base ?>/admin/buses.php">Bus Fleet</a> first.</div>
  <?php else: ?>
  <form method="post" autocomplete="off">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="add">
    <div class="row">
      <label>Bus (vehicle number)
        <select name="bus_id" required>
          <?php foreach ($activeBuses as $b): ?>
            <option value="<?= (int) $b['id'] ?>"><?= Security::e($b['bus_number'] . ' · ' . $b['bus_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Kind
        <select name="kind" id="gtKind">
          <option value="phone">Phone (Traccar Client app)</option>
          <option value="hardware">GPS device (IMEI, via Traccar server)</option>
        </select>
      </label>
      <label>Label <input type="text" name="label" maxlength="80" placeholder="e.g. Cabin phone, GPS box"></label>
      <label>Device id
        <span class="gt-uidrow">
          <input type="text" name="uid" id="gtUid" maxlength="64" placeholder="empty = a new SHG code">
          <button class="btn ghost" type="button" id="gtNew" style="padding:6px 10px;font-size:12px">New code</button>
        </span>
      </label>
    </div>
    <p class="muted" style="margin:0 0 10px;font-size:12.5px">Phone: leave the device id empty (or press <b>New code</b>) - the code is the phone's password, so it is long and random.
      GPS device: type its <b>IMEI</b> exactly as the Traccar server knows it.</p>
    <div style="display:flex;justify-content:flex-end">
      <button class="btn ok" type="submit">➕ Add tracker</button>
    </div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel">
  <h2>Buses and their trackers (<?= count($buses) ?>)</h2>
  <table>
    <thead><tr><th>Bus</th><th>Tracker</th><th>Device id</th><th>Last seen</th><th>Position</th><th>Battery</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($buses === []): ?>
      <tr><td colspan="7" class="muted" style="padding:22px;text-align:center">No buses yet. Add one in Bus Fleet.</td></tr>
    <?php else: foreach ($buses as $b):
      $run = $runs[(int) $b['id']] ?? null;
      ob_start(); ?>
        <strong class="mono"><?= Security::e((string) $b['bus_number']) ?></strong>
        <div class="muted" style="font-size:12px"><?= Security::e((string) $b['bus_name']) ?><?= (int) $b['is_active'] === 1 ? '' : ' · inactive bus' ?></div>
        <div style="font-size:12px;margin-top:2px"><?= $run !== null
          ? '🚌 On ' . Security::e($run['route'] . ' · ' . $run['date'] . ' ' . $run['dep'])
          : '<span class="muted">Parked - no departure within the hour</span>' ?></div>
      <?php $busCell = (string) ob_get_clean();
      if ($b['trackers'] === []): ?>
      <tr>
        <td><?= $busCell ?></td>
        <td class="muted">No tracker yet</td>
        <td class="muted">—</td><td class="muted">—</td><td class="muted">—</td><td class="muted">—</td>
        <td><?php if ($canEdit && (int) $b['is_active'] === 1): ?><a class="btn ghost" href="#add" style="padding:5px 10px;font-size:12px" data-bus="<?= (int) $b['id'] ?>">Add</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      </tr>
      <?php else: foreach ($b['trackers'] as $t):
        $on   = (int) $t['is_active'] === 1;
        $seen = $t['last_seen'] !== null ? $now - (int) strtotime((string) $t['last_seen']) : null;
        $cls  = $seen === null ? 'gt-off' : ($seen <= 120 ? 'gt-on' : ($seen <= 1800 ? 'gt-warm' : 'gt-off'));
      ?>
      <tr>
        <td><?= $busCell ?></td>
        <td>
          <strong><?= Security::e((string) $t['label']) ?></strong>
          <div style="margin-top:3px"><span class="gt-kind gt-k-<?= Security::e((string) $t['kind']) ?>"><?= $t['kind'] === 'hardware' ? 'GPS device' : 'Phone' ?></span>
          <?= $on ? '' : ' <span class="pill" style="background:#f7dcdc;color:#8a1f1f">Off</span>' ?></div>
        </td>
        <td><span class="gt-uid"><?= Security::e((string) $t['device_uid']) ?></span><button class="btn ghost gt-copy" type="button" data-copy="<?= Security::e((string) $t['device_uid']) ?>">Copy</button>
          <?php if (GpsTrackers::needsKey((string) $t['device_uid'])): ?><div class="muted" style="font-size:11px">needs the forward key</div><?php endif; ?>
        </td>
        <td class="<?= $cls ?>"><?= $seen === null ? 'never' : Security::e(timeAgo((string) $t['last_seen'])) ?></td>
        <td><?php if ($t['last_lat'] !== null && $t['last_lng'] !== null):
            $ll = rtrim(rtrim(number_format((float) $t['last_lat'], 6, '.', ''), '0'), '.') . ',' . rtrim(rtrim(number_format((float) $t['last_lng'], 6, '.', ''), '0'), '.');
            $map = GpsTrackers::PUBLIC_URL . '/navigate.html?to=' . $ll . '&name=' . rawurlencode((string) $b['bus_number']); ?>
            <a href="<?= Security::e($map) ?>" target="_blank" rel="noopener">📍 <?= Security::e($ll) ?></a>
            <div class="muted" style="font-size:11px"><?= $t['last_speed'] !== null ? (int) round((float) $t['last_speed']) . ' km/h · ' : '' ?><?= $t['last_fix_at'] !== null ? Security::e(timeAgo((string) $t['last_fix_at'])) : '' ?></div>
          <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><?= $t['last_batt'] !== null ? (int) $t['last_batt'] . '%' : '<span class="muted">—</span>' ?></td>
        <td class="row-actions">
          <?php if ($canEdit): ?>
            <form method="post" style="display:inline">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn ghost" type="submit" style="padding:5px 10px;font-size:12px"
                <?= $on ? 'onclick="return confirm(\'Switch this tracker off? Its positions are refused until you switch it on again.\')"' : '' ?>><?= $on ? 'Switch off' : 'Switch on' ?></button>
            </form>
            <form method="post" style="display:inline">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
              <button class="btn bad" type="submit" style="padding:5px 10px;font-size:12px" onclick="return confirm('Delete this tracker? The phone or GPS box can no longer send this bus\'s position.')">Delete</button>
            </form>
          <?php else: ?>
            <span class="muted">View only</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; endif; endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <h2>How to set up · कसरी सेटअप गर्ने</h2>
  <div class="gt-setup">
    <div>
      <h3 style="margin:0">📱 Phone in the bus · बसमा फोन</h3>
      <ol>
        <li>On a phone that stays in this bus, install the free app <b>Traccar Client</b> (Play Store / App Store).</li>
        <li><b>Server URL:</b> <code class="gt-code"><?= Security::e($endpoint) ?></code></li>
        <li><b>Device identifier:</b> the <b>SHG…</b> code shown next to the bus above - type it exactly.</li>
        <li><b>Location accuracy:</b> High. <b>Frequency:</b> 10 seconds.</li>
        <li>Turn <b>Service status</b> ON. Allow location <b>All the time</b>, switch battery saver OFF for the app, and keep the phone on charge in the bus.</li>
      </ol>
      <ol class="np">
        <li>बसमै रहने फोनमा निःशुल्क <b>Traccar Client</b> एप (Play Store / App Store) राख्नुहोस्।</li>
        <li><b>Server URL</b> मा माथिको ठेगाना लेख्नुहोस्।</li>
        <li><b>Device identifier</b> मा माथि त्यही बसको छेउमा देखिएको <b>SHG…</b> कोड ठ्याक्कै लेख्नुहोस्।</li>
        <li><b>Location accuracy:</b> High, <b>Frequency:</b> 10 सेकेन्ड राख्नुहोस्।</li>
        <li><b>Service status</b> ON गर्नुहोस्। Location <b>All the time</b> दिनुहोस्, एपको battery saver बन्द गर्नुहोस्, र फोनलाई बसमै चार्जमा राख्नुहोस्।</li>
      </ol>
    </div>
    <div>
      <h3 style="margin:0">🛰️ GPS device (hardware) · GPS डिभाइस</h3>
      <ol>
        <li>Connect the GPS box to a <b>Traccar server</b> (your own or a hosted one) - the device manual gives the server address and port.</li>
        <li>In Traccar add the device with its <b>IMEI</b> as identifier. Add the same IMEI above as a <b>GPS device</b> on its bus.</li>
        <li>In the Traccar server's <b>traccar.xml</b> add the lines below (in the XML file every <code>&amp;</code> is written <code>&amp;amp;</code>), then restart Traccar.</li>
      </ol>
      <ol class="np">
        <li>GPS बाकसलाई <b>Traccar सर्भर</b> मा जोड्नुहोस् (आफ्नै वा भाडाको) - सर्भरको ठेगाना र पोर्ट डिभाइसको म्यानुअलमा हुन्छ।</li>
        <li>Traccar मा डिभाइसको <b>IMEI</b> नै identifier राखेर थप्नुहोस्। माथि पनि त्यही IMEI लाई त्यस बसमा <b>GPS device</b> को रूपमा थप्नुहोस्।</li>
        <li>Traccar सर्भरको <b>traccar.xml</b> मा तलका लाइन राख्नुहोस् (XML फाइलमा हरेक <code>&amp;</code> लाई <code>&amp;amp;</code> लेखिन्छ), अनि Traccar restart गर्नुहोस्।</li>
      </ol>
      <div style="font-size:12px;font-weight:700;color:var(--mut);margin-top:8px">Forward URL</div>
      <code class="gt-code"><?= Security::e($fwdUrl) ?></code>
      <div style="font-size:12px;font-weight:700;color:var(--mut)">traccar.xml</div>
      <code class="gt-code"><?= Security::e("<entry key='forward.enable'>true</entry>\n<entry key='forward.type'>url</entry>\n<entry key='forward.url'>" . str_replace('&', '&amp;', $fwdUrl) . '</entry>') ?></code>
      <?php if ($isOwner): ?>
        <form method="post" style="margin-top:6px">
          <?= Security::csrfField() ?>
          <input type="hidden" name="action" value="rotate_key">
          <button class="btn ghost" type="submit" style="padding:4px 10px;font-size:12px" onclick="return confirm('Make a new forward key? Every Traccar server must get the new URL - the old key stops working at once.')">New forward key</button>
          <span class="muted" style="font-size:12px">Only you (super-admin) see the key. Keep it out of WhatsApp groups.</span>
        </form>
      <?php else: ?>
        <p class="muted" style="font-size:12px;margin:4px 0 0">KEY = the forward key. Only the owner (super-admin) can see it on this page.</p>
      <?php endif; ?>
    </div>
  </div>
  <p class="muted" style="margin:14px 0 0;font-size:12.5px">
    A tracker sends the position of its <b>bus</b>. From one hour before departure that position is the live position of the bus's trip:
    the <a href="<?= $base ?>/admin/map.php">Live Map</a>, every passenger's tracking page and the "bus is near" WhatsApp use it, exactly like the
    <a href="<?= $base ?>/admin/drivers.php">driver app</a>. With no departure on, the bus is shown here as parked. If the driver presses END TRIP, the trip stays ended.
  </p>
</div>

<script>
(function () {
  var ABC = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  var uid = document.getElementById('gtUid'), kind = document.getElementById('gtKind'), btn = document.getElementById('gtNew');
  function code() {
    var a = new Uint8Array(13), s = 'SHG';
    (window.crypto || window.msCrypto).getRandomValues(a);
    for (var i = 0; i < a.length; i++) s += ABC.charAt(a[i] % 32);
    return s;
  }
  if (btn && uid) btn.addEventListener('click', function () { uid.value = code(); if (kind) kind.value = 'phone'; });
  if (kind && uid) kind.addEventListener('change', function () {
    uid.placeholder = kind.value === 'hardware' ? 'IMEI, e.g. 358240051111110' : 'empty = a new SHG code';
    if (btn) btn.style.display = kind.value === 'hardware' ? 'none' : '';
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-copy]') : null;
    if (b) {
      var v = b.getAttribute('data-copy');
      if (navigator.clipboard) navigator.clipboard.writeText(v).then(function () { b.textContent = 'Copied'; setTimeout(function () { b.textContent = 'Copy'; }, 1500); });
      return;
    }
    var a = e.target.closest ? e.target.closest('a[data-bus]') : null;
    var sel = document.querySelector('#add select[name="bus_id"]');
    if (a && sel) sel.value = a.getAttribute('data-bus');
  });
})();
</script>

<?php admin_footer();
