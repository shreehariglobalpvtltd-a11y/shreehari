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
 *
 *  8 Oct 2026 (D4-08 / D8-05): the link stays in WhatsApp chats long
 *  after it is used, and it opened a full-fare UPI payment for cancelled,
 *  expired and already-paid bookings. Now it asks only for what is DUE —
 *  total minus verified payments — and only while the booking can still
 *  take money: pending / rejected (proof refused, may pay again) or a
 *  confirmed pay-at-counter ticket not settled yet. Anything else gets the
 *  card with "nothing to pay" and the office WhatsApp, no amount, no UPI
 *  redirect, no pay_open event. Rate-limited per IP (PNRs are sequential).
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

if (!Security::rateLimit('pay_open', Security::clientIp(), 30, 300)) {
    http_response_code(429);
    header('Retry-After: 300');
    exit('Too many requests. Please wait a few minutes and try again.');
}

$booking = Database::fetch(
    'SELECT id, pnr, total_amount, status, is_cod FROM bookings WHERE pnr = :p LIMIT 1',
    ['p' => $pnr]
);
if ($booking === null) {
    http_response_code(404);
    exit('Booking not found');
}

/* What is still owed, and may this booking take money now? */
$total   = (float) ($booking['total_amount'] ?? 0);
$paid    = (float) Database::scalar(
    "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE booking_id = :b AND status = 'verified'",
    ['b' => (int) $booking['id']],
    0
);
$due     = round(max(0, $total - $paid), 2);
$bStatus = (string) ($booking['status'] ?? '');
$payable = $due > 0 && (in_array($bStatus, ['pending', 'rejected'], true)
    || ($bStatus === 'confirmed' && (int) ($booking['is_cod'] ?? 0) === 1));

$upiId   = Settings::getString('upi_id', '');
$upiName = Settings::getString('upi_name', Settings::getString('company_name', APP_NAME));
if ($payable && $upiId === '') {
    http_response_code(503);
    exit('UPI not configured');
}

/* The theme the app saved (D1-10): no white flash after a dark app. */
$payThemeHead = <<<'HTML'
<script>
(function () {
  try {
    var d = document.documentElement, t = (localStorage.getItem('shg:theme') || '').replace(/"/g, '');
    if (t !== 'light' && t !== 'dark' && t !== 'amoled') t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    d.setAttribute('data-theme', t === 'light' ? 'light' : 'dark');
    if (t === 'amoled') d.setAttribute('data-display', 'amoled');
  } catch (e) { /* storage blocked: light */ }
})();
</script>
HTML;
$payThemeCss = ':root[data-theme="dark"] { color-scheme: dark; }'
    . ' :root[data-theme="dark"] body { background:#0B1220; color:#e8eef8; }'
    . ' :root[data-theme="dark"] .card { background:#131d30; box-shadow:0 4px 24px rgba(0,0,0,.45); }'
    . ' :root[data-theme="dark"] .row { color:#c3cfdf; }'
    . ' :root[data-theme="dark"] .amt { color:#4ade80; }'
    . ' :root[data-theme="dark"] code { background:#22324a; color:#e8eef8; }'
    . ' :root[data-theme="dark"][data-display="amoled"] body { background:#000; }'
    . ' :root[data-theme="dark"][data-display="amoled"] .card { background:#0b0f16; }';

/* 9 Oct 2026 (BRAND): who is asking for the money — the emblem (no baked
   name, no white tile), the live company name and the India / Nepal flags,
   so a passenger opening this from WhatsApp sees it is really S Hari Global. */
require_once INCLUDE_PATH . '/brand.php';
$payCompany   = Settings::getString('company_name', APP_NAME);
$payThemeHead .= Brand::iconLinks();
$payBrand     = '<div class="pb"><img src="' . htmlspecialchars(Brand::markUrl(36), ENT_QUOTES, 'UTF-8') . '" alt="" width="69" height="36">'
    . '<b>' . htmlspecialchars($payCompany, ENT_QUOTES, 'UTF-8') . '</b>' . Brand::flagPair(13) . '</div>';
$payThemeCss .= ' .pb { display:flex; align-items:center; gap:10px; margin:0 0 14px; padding-bottom:12px; border-bottom:1px solid rgba(127,140,160,.22); }'
    . ' .pb img { height:36px; width:auto; max-width:76px; object-fit:contain; flex:none; filter:drop-shadow(0 2px 4px rgba(15,23,42,.2)); }'
    . ' .pb b { font-size:14.5px; min-width:0; }'
    . ' .pb .flg-pair { margin-left:auto; flex:none; }'
    . ' :root[data-theme="dark"] .pb img { filter:drop-shadow(0 0 10px rgba(120,170,255,.25)) drop-shadow(0 2px 4px rgba(0,0,0,.5)); }';

if (!$payable) {
    /* Nothing to pay: the same card, no amount, no UPI, no redirect and no
       pay_open event (the desk's intent trail must not show a "tap to pay"
       on a booking that cannot take money). */
    header('Cache-Control: no-store');
    $bizWa = Settings::officeWhatsApp();
    ?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pay <?= htmlspecialchars($pnr, ENT_QUOTES, 'UTF-8') ?></title>
<?= $payThemeHead ?>
<style>
  :root { color-scheme: light; }
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Devanagari", sans-serif;
         background:#f7f8fb; color:#111; margin:0; padding:24px 16px; }
  .card { max-width: 420px; margin: 32px auto; background:#fff;
          border-radius:16px; padding:24px; box-shadow:0 4px 24px rgba(0,0,0,.06); }
  h1 { font-size:20px; margin:0 0 8px; }
  .row { margin:8px 0; color:#444; font-size:16px; line-height:1.5; }
  .btn { display:block; text-align:center; background:#0a7c2f; color:#fff;
         padding:14px 20px; border-radius:12px; text-decoration:none;
         font-weight:600; margin:16px 0 8px; }
  code { background:#eef1f5; padding:2px 6px; border-radius:4px; font-size:14px; }
  <?= $payThemeCss ?>
</style>
</head>
<body>
  <div class="card">
    <?= $payBrand ?>
    <h1>भुक्तानी · Pay</h1>
    <div class="row">बुकिङ नं.: <code><?= htmlspecialchars($pnr, ENT_QUOTES, 'UTF-8') ?></code></div>
    <div class="row">यो बुकिङमा भुक्तानी बाँकी छैन (रद्द / म्याद सकिएको / भुक्तानी भइसकेको) — कार्यालयमा सम्पर्क गर्नुहोस्। · No payment is due on this booking.</div>
<?php if ($bizWa !== ''):
      $waText = rawurlencode('मेरो बुकिङ ' . $pnr . ' बारे सोध्नु छ।'); ?>
    <a class="btn" style="background:#128C7E" href="https://wa.me/<?= htmlspecialchars($bizWa, ENT_QUOTES, 'UTF-8') ?>?text=<?= $waText ?>">WhatsApp मा कार्यालयसँग कुरा गर्नुहोस्</a>
<?php endif; ?>
  </div>
</body>
</html>
<?php
    exit;
}

$total  = $due;   // ask only for what is still owed
$upiUri = upiLink($upiId, $upiName, $total, $pnr);

/* Intent trail for the desk: this passenger tapped pay. The UPI app itself
   never reports back, so this is the last thing we can see. */
require_once INCLUDE_PATH . '/payevents.php';
PayEvents::log((int) ($booking['id'] ?? 0), $pnr, 'pay_open');

// Prefer a direct 302 to the upi:// scheme so the UPI app opens the
// moment the customer taps in WhatsApp. HTML fallback follows for the
// rare browser that ignores an unknown-scheme Location header.
header('Location: ' . $upiUri, true, 302);
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pay <?= htmlspecialchars($pnr, ENT_QUOTES, 'UTF-8') ?></title>
<?= $payThemeHead ?>
<style>
  :root { color-scheme: light; }
  body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Devanagari", sans-serif;
         background:#f7f8fb; color:#111; margin:0; padding:24px 16px; }
  .card { max-width: 420px; margin: 32px auto; background:#fff;
          border-radius:16px; padding:24px; box-shadow:0 4px 24px rgba(0,0,0,.06); }
  h1 { font-size:20px; margin:0 0 8px; }
  .amt { font-size:32px; font-weight:700; color:#0a7c2f; margin:12px 0; }
  .row { margin:8px 0; color:#555; }
  .btn { display:block; text-align:center; background:#0a7c2f; color:#fff;
         padding:14px 20px; border-radius:12px; text-decoration:none;
         font-weight:600; margin:16px 0 8px; }
  code { background:#eef1f5; padding:2px 6px; border-radius:4px; font-size:14px; }
  <?= $payThemeCss ?>
</style>
</head>
<body>
  <div class="card">
    <?= $payBrand ?>
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
