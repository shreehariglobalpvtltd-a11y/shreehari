<?php
/**
 * =====================================================================
 *  pay.php — WhatsApp-friendly UPI deep-link redirect (19 Sep 2026)
 *
 *  Reached from the tap-to-pay link that Notify::bookingPlaced() puts
 *  in the WhatsApp message:
 *
 *      https://www.shreehariglobal.in/pay.php?pnr=SHG-2026-00436
 *
 *  WhatsApp only auto-links http(s) URLs — a raw upi://pay?... is not
 *  tappable. This tiny page turns a tappable HTTPS link into an
 *  upi://pay redirect with the exact amount + PNR pre-filled, so the
 *  customer taps once in WhatsApp, their default UPI app (GPay /
 *  PhonePe / Paytm / BHIM) opens, they confirm, and the payment lands
 *  with the PNR as the merchant note.
 *
 *  If the browser cannot open the upi:// scheme (unlikely — every
 *  modern Android and iPhone with a UPI app installed handles it),
 *  a minimal fallback page shows the UPI ID, amount and PNR as text
 *  the customer can copy manually.
 * =====================================================================
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';

$pnr = strtoupper(trim((string) ($_GET['pnr'] ?? '')));
if ($pnr === '' || !preg_match('/^[A-Z0-9-]{6,40}$/', $pnr)) {
    http_response_code(400);
    exit('Invalid PNR');
}

$booking = Database::fetch(
    'SELECT pnr, total_amount, status FROM bookings WHERE pnr = :p LIMIT 1',
    ['p' => $pnr]
);
if ($booking === null) {
    http_response_code(404);
    exit('Booking not found');
}

$total = (float) ($booking['total_amount'] ?? 0);
if ($total <= 0) {
    http_response_code(400);
    exit('Amount not set');
}

$upiId   = Settings::getString('upi_id', '');
$upiName = Settings::getString('upi_name', Settings::getString('company_name', APP_NAME));
if ($upiId === '') {
    http_response_code(503);
    exit('UPI not configured');
}

$upiUri = upiLink($upiId, $upiName, $total, $pnr);

// Prefer a direct 302 to the upi:// scheme so the UPI app opens the
// moment the customer taps in WhatsApp. HTML fallback follows for the
// rare browser that ignores an unknown-scheme Location header.
header('Location: ' . $upiUri, true, 302);
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pay <?= htmlspecialchars($pnr, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  :root { color-scheme: light; }
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         background:#f7f8fb; color:#111; margin:0; padding:24px; }
  .card { max-width: 420px; margin: 32px auto; background:#fff;
          border-radius:16px; padding:24px; box-shadow:0 4px 24px rgba(0,0,0,.06); }
  h1 { font-size:20px; margin:0 0 8px; }
  .amt { font-size:32px; font-weight:700; color:#0a7c2f; margin:12px 0; }
  .row { margin:8px 0; color:#555; }
  .btn { display:block; text-align:center; background:#0a7c2f; color:#fff;
         padding:14px 20px; border-radius:12px; text-decoration:none;
         font-weight:600; margin:16px 0 8px; }
  code { background:#eef1f5; padding:2px 6px; border-radius:4px; font-size:14px; }
</style>
</head>
<body>
  <div class="card">
    <h1>भुक्तानी · Pay</h1>
    <div class="row">बुकिङ नं.: <code><?= htmlspecialchars($pnr, ENT_QUOTES, 'UTF-8') ?></code></div>
    <div class="amt">₹<?= number_format($total, 2) ?></div>
    <a class="btn" href="<?= htmlspecialchars($upiUri, ENT_QUOTES, 'UTF-8') ?>">UPI app मा खोल्नुहोस्</a>
    <div class="row">UPI ID: <code><?= htmlspecialchars($upiId, ENT_QUOTES, 'UTF-8') ?></code></div>
<?php
  /* One tap opens our WhatsApp with the PNR already typed. That inbound
     message opens the 24 h service window, which is what lets the ticket
     and every later update reach this customer without Meta billing. */
  $bizWa = Settings::officeWhatsApp();
  if ($bizWa !== ''):
      $waText = rawurlencode('मेरो बुकिङ ' . $pnr . ' को टिकट पठाउनुहोस्।');
?>
    <a class="btn" style="background:#128C7E" href="https://wa.me/<?= htmlspecialchars($bizWa, ENT_QUOTES, 'UTF-8') ?>?text=<?= $waText ?>">WhatsApp मा टिकट पाउनुहोस्</a>
<?php endif; ?>
    <div class="row">प्राप्तकर्ता: <?= htmlspecialchars($upiName, ENT_QUOTES, 'UTF-8') ?></div>
    <script>
      // In case the header redirect was blocked, try once more from JS.
      setTimeout(function(){ location.href = <?= json_encode($upiUri) ?>; }, 400);
    </script>
  </div>
</body>
</html>
