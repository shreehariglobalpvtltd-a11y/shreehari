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
$admin = admin_boot('tickets.scan');

$base = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$pnr  = Security::clean($_GET['pnr'] ?? '', 40);
$qrNote = null;

/* ---- Mark boarded ------------------------------------------------
   The one write path for boarding. It records the scan, stamps who boarded
   on the first pass, and refuses a second boarding — so a re-used QR
   screenshot is caught here rather than flashing green "VALID" again (H2). */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'board') {
    if (!Security::verifyCsrf()) {
        $qrNote = ['bad', 'Session expired — reload the page and scan again.'];
    } else {
        $pnr = Security::clean($_POST['pnr'] ?? '', 40);
        try {
            $res = BookingService::markBoarded($pnr, (int) $admin['id']);
            if ($res['state'] === 'boarded') {
                $qrNote = ['ok', '✅ Boarded — passenger(s) checked in. Do not scan this ticket again.'];
            } else {
                $qrNote = ['bad', '⛔ ALREADY BOARDED at ' . formatDate($res['scannedAt'], 'j M Y, g:i A')
                    . ' — this ticket has been used (scan #' . $res['scanCount'] . '). Do NOT board again.'];
            }
        } catch (Throwable $e) {
            $qrNote = ['bad', $e->getMessage()];
        }
    }
}

/* ---- A scanned QR ------------------------------------------------
   POSTed, not put in the query string: the issued payload carries the
   passenger's phone number, and URLs end up in logs, history and referrers.

   The camera used to pull the PNR out with /SHG-[A-Z0-9-]+/i. Every issued
   ticket begins with the literal marker "SHG-TICKET", which that pattern
   matches before it ever reaches the real reference — so scanning a genuine
   ticket looked up "SHG-TICKET", found nothing, and told the crew the
   passenger's ticket was not valid. Ticket::verifyQrPayload() is the right
   reader: it takes the PNR from its known position AND checks the signature,
   so a doctored payload is rejected instead of merely not found. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['qr'])) {
    if (!Security::verifyCsrf()) {
        $qrNote = ['bad', 'Session expired — reload the page and scan again.'];
    } else {
        $raw = trim((string) $_POST['qr']);

        $verified = Ticket::verifyQrPayload($raw);
        if ($verified !== null) {
            $pnr = $verified;
            $qrNote = ['ok', '🔒 Signature verified — this QR was issued by us.'];
        } elseif (str_starts_with($raw, 'SHG-TICKET|')) {
            // Right shape, wrong signature: altered, or from another system.
            $qrNote = ['bad', '⛔ This QR claims to be one of ours but its signature does not match. Treat it as forged and check ID.'];
            $pnr = '';
        } elseif (preg_match('/SHG-[A-Z0-9\-]{4,36}/i', $raw, $m)) {
            // The unsigned QR the app draws on screen — good enough to look
            // the booking up, but say so rather than implying it was verified.
            $pnr = strtoupper($m[0]);
            $qrNote = ['warn', 'Unsigned on-screen QR — the booking below is real, but check a photo ID too.'];
        } else {
            $qrNote = ['bad', 'That code is not a S Hari Global ticket.'];
        }
    }
}

$b = ($pnr !== '' && Security::isValidPnr($pnr)) ? BookingService::detail($pnr) : null;

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

admin_header('Scan ticket', 'scan');

if ($qrNote !== null) {
    // 'warn' is amber, not red: the booking underneath it is genuine, the QR
    // just wasn't the signed one. Showing that in red above a green "VALID"
    // would tell the crew two opposite things at once.
    $style = $qrNote[0] === 'warn' ? ' style="background:#fff4d1;color:#8a6d00"' : '';
    $cls   = $qrNote[0] === 'ok' ? 'ok' : 'bad';
    echo '<div class="flash ' . $cls . '"' . $style . '>' . Security::e($qrNote[1]) . '</div>';
}
?>
<form class="toolbar" method="get">
  <input type="search" name="pnr" placeholder="Enter or scan PNR (e.g. SHG-R1-XXXXX)" value="<?= Security::e($pnr) ?>"
         style="min-width:280px;text-transform:uppercase" autofocus>
  <button class="btn" type="submit">Verify</button>
  <button class="btn ghost" type="button" id="camBtn">📷 Camera</button>
</form>

<form method="post" id="qrForm" style="display:none">
  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
  <input type="hidden" name="qr" id="qrData">
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
    $tk       = $b['ticket'] ?? null;
    $voided   = $tk !== null && (int) ($tk['is_void'] ?? 0) === 1;
    $boarded  = $tk !== null && !empty($tk['scanned_at']);
  ?>
  <?php if (!$confirmed): ?>
    <div class="flash bad">⚠️ NOT confirmed (<?= Security::e(ucfirst((string) $b['status'])) ?>) · Do not board</div>
  <?php elseif ($voided): ?>
    <div class="flash bad">⛔ VOIDED ticket · Do not board</div>
  <?php elseif ($boarded): ?>
    <div class="flash bad" style="background:#ffe6c7;color:#7a4a00">
      ⛔ ALREADY BOARDED at <?= Security::e(formatDate((string) $tk['scanned_at'], 'j M Y, g:i A')) ?><?= !empty($tk['scanned_by_name']) ? ' by ' . Security::e((string) $tk['scanned_by_name']) : '' ?>
      · used <?= (int) $tk['scan_count'] ?> time<?= (int) $tk['scan_count'] === 1 ? '' : 's' ?> — do NOT board again.
    </div>
  <?php else: ?>
    <div class="flash ok">✅ VALID · Ready to board</div>
    <form method="post" style="margin:-6px 0 18px">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="board">
      <input type="hidden" name="pnr" value="<?= Security::e((string) $b['pnr']) ?>">
      <button class="btn ok" type="submit" style="font-size:15px;padding:11px 20px">✅ Mark as boarded</button>
    </form>
  <?php endif; ?>
  <div class="panel">
    <h2><?= Security::e((string) $b['pnr']) ?> · <?= admin_pill((string) $b['status']) ?></h2>
    <table>
      <tr><th>Route</th><td><?= Security::e(($leg['from_city'] ?? '—') . ' → ' . ($leg['to_city'] ?? '—')) ?></td></tr>
      <tr><th>Travel date</th><td><?= Security::e(formatDate($leg['travel_date'] ?? null)) ?></td></tr>
      <tr><th>Seats</th><td class="mono"><?= Security::e(implode(', ', array_map(static fn($s) => Seats::displayLabel((string) $s), $b['seats'] ?? []))) ?></td></tr>
      <tr><th>Contact</th><td class="mono"><?= Security::e(maskPhone((string) $b['contact_phone'])) ?></td></tr>
    </table>
  </div>
  <div class="panel">
    <h2>Passengers (<?= count($b['passengers']) ?>)</h2>
    <table>
      <thead><tr><th>Seat</th><th>Name</th><th>Age</th><th>Gender</th></tr></thead>
      <tbody>
      <?php foreach ($b['passengers'] as $p): ?>
        <tr><td class="mono"><?= Security::e(Seats::displayLabel((string) $p['seat_no'])) ?></td>
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
            /* Hand the whole payload to the server, which verifies its
               signature. POSTed because it contains the passenger's phone. */
            stream.getTracks().forEach(function (t) { t.stop(); });
            document.getElementById('qrData').value = code.data;
            document.getElementById('qrForm').submit();
            return;
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
