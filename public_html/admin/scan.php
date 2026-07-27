<?php
/**
 * admin/scan.php — verify a ticket at boarding.
 *
 * Enter or scan a PNR; the page shows the booking's live status and, when
 * confirmed, the passenger manifest. The QR on the printed ticket also
 * encodes the PNR, so a camera scan (jsQR, loaded from the same CDN the
 * site already allows) fills the box automatically.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
admin_boot('tickets.scan');

$base = rtrim(APP_URL, '/');
$pnr  = Security::clean($_GET['pnr'] ?? '', 40);
$b    = ($pnr !== '' && Security::isValidPnr($pnr)) ? BookingService::detail($pnr) : null;

admin_header('Scan ticket', 'scan');
?>
<form class="toolbar" method="get">
  <input type="search" name="pnr" placeholder="Enter or scan PNR (e.g. SHG-R1-XXXXX)" value="<?= Security::e($pnr) ?>"
         style="min-width:280px;text-transform:uppercase" autofocus>
  <button class="btn" type="submit">Verify</button>
  <button class="btn ghost" type="button" id="camBtn">📷 Camera</button>
</form>

<div id="cam" style="display:none;margin-bottom:16px">
  <video id="v" playsinline style="width:100%;max-width:420px;border-radius:12px;background:#000"></video>
</div>

<?php if ($pnr !== '' && $b === null): ?>
  <div class="flash bad">❌ No booking found for <strong><?= Security::e($pnr) ?></strong>. This ticket is not valid.</div>
<?php elseif ($b !== null): ?>
  <?php
    $leg = $b['legs'][0] ?? [];
    $confirmed = $b['status'] === 'confirmed';
  ?>
  <div class="flash <?= $confirmed ? 'ok' : 'bad' ?>">
    <?= $confirmed ? '✅ VALID · Boarding allowed' : '⚠️ NOT confirmed (' . Security::e(ucfirst((string) $b['status'])) . ') · Do not board' ?>
  </div>
  <div class="panel">
    <h2><?= Security::e((string) $b['pnr']) ?> · <?= admin_pill((string) $b['status']) ?></h2>
    <table>
      <tr><th>Route</th><td><?= Security::e(($leg['from_city'] ?? '—') . ' → ' . ($leg['to_city'] ?? '—')) ?></td></tr>
      <tr><th>Travel date</th><td><?= Security::e(formatDate($leg['travel_date'] ?? null)) ?></td></tr>
      <tr><th>Seats</th><td class="mono"><?= Security::e(implode(', ', $b['seats'] ?? [])) ?></td></tr>
      <tr><th>Contact</th><td class="mono"><?= Security::e(maskPhone((string) $b['contact_phone'])) ?></td></tr>
    </table>
  </div>
  <div class="panel">
    <h2>Passengers (<?= count($b['passengers']) ?>)</h2>
    <table>
      <thead><tr><th>Seat</th><th>Name</th><th>Age</th><th>Gender</th></tr></thead>
      <tbody>
      <?php foreach ($b['passengers'] as $p): ?>
        <tr><td class="mono"><?= Security::e((string) $p['seat_no']) ?></td>
            <td><?= Security::e((string) $p['full_name']) ?></td>
            <td><?= $p['age'] !== null ? (int) $p['age'] : '—' ?></td>
            <td><?= Security::e((string) ($p['gender'] ?? '—')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js" defer></script>
<script>
document.getElementById('camBtn').addEventListener('click', async function () {
  var wrap = document.getElementById('cam'), video = document.getElementById('v');
  if (wrap.style.display === 'none') {
    try {
      var stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
      video.srcObject = stream; await video.play(); wrap.style.display = '';
      var canvas = document.createElement('canvas'), ctx = canvas.getContext('2d');
      var tick = function () {
        if (wrap.style.display === 'none') { stream.getTracks().forEach(t => t.stop()); return; }
        if (video.readyState === video.HAVE_ENOUGH_DATA && window.jsQR) {
          canvas.width = video.videoWidth; canvas.height = video.videoHeight;
          ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
          var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
          var code = window.jsQR(img.data, img.width, img.height);
          if (code && code.data) {
            var m = code.data.match(/SHG-[A-Z0-9-]+/i);
            if (m) { location.href = '<?= $base ?>/admin/scan.php?pnr=' + encodeURIComponent(m[0]); return; }
          }
        }
        requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    } catch (e) { alert('Camera unavailable: ' + e.message); }
  } else {
    wrap.style.display = 'none';
    if (video.srcObject) { video.srcObject.getTracks().forEach(t => t.stop()); }
  }
});
</script>
<?php
admin_footer();
