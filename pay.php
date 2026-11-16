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
 *
 *  16 Nov 2026 (P1, owner: "pay at boarding vane option ... redirect
 *  payment lina sab suvidha hunu paryo, free wala"): a PAY-AT-BOARDING
 *  ticket (confirmed, is_cod, money due) no longer jumps straight into
 *  the UPI app. It gets the same card with every FREE way side by side -
 *  the green "UPI app मा खोल्नुहोस्" button (the same upi:// link), a UPI
 *  QR with the amount, the company eSewa (its QR image when the site has
 *  one, as on the checkout) - and, after paying, a UTR box. The UTR is
 *  written on the ticket's own money row for the office to check
 *  (CodPayClaim in includes/payadapter.php): never a confirmation, the
 *  ticket stays "pay on boarding" until the office settles it, and the
 *  page then says "तपाईंको भुक्तानी जाँचमा छ". Sending the UTR needs the
 *  booking's mobile, or the ticket's own key (k) that the ticket's pay QR
 *  carries. Pending online bookings keep the 302 exactly as before.
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
    'SELECT id, pnr, total_amount, status, is_cod, contact_phone, user_id FROM bookings WHERE pnr = :p LIMIT 1',
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

/* 16 Nov 2026 (P1): a pay-at-boarding ticket that still owes money gets the page with
   every free way and the UTR box instead of the straight 302 into the UPI app.
   P1 refix: only while the UTR box can take a claim (CodPayClaim::eligible - the money
   row is still the unsettled 'cod_pending' one). A ticket settled in cash whose fare was
   later corrected upward owes the extra on a VERIFIED row: the box would refuse every
   UTR with "Nothing is due" after the passenger had paid, so that ticket keeps the old
   straight 302 into the UPI app (and its fallback card) for what is still due. */
$boardPay = $payable && $bStatus === 'confirmed' && (int) ($booking['is_cod'] ?? 0) === 1;
if ($boardPay) {
    require_once INCLUDE_PATH . '/payadapter.php';
    $boardPay = CodPayClaim::eligible($booking);
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
/* 16 Nov 2026 (P5-4a): the app's weekly accent (includes/themetokens.php) rides in the head
   like on track / help / verify-ticket, but the "UPI app" pay button deliberately stays GREEN
   every day: green = pay is the one colour cue a passenger must never lose. */
require_once INCLUDE_PATH . '/themetokens.php';
$payThemeHead .= ThemeTokens::accentHead();
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
<?php if ($due <= 0 && in_array($bStatus, ['confirmed', 'completed'], true)): /* 6 Nov 2026: the ticket QR after the office approved the payment */ ?>
    <div class="row" style="font-size:20px;font-weight:800;color:#0a7c2f">✅ भुक्तानी स्वीकृत भइसकेको छ · Payment approved</div>
    <div class="row">यो टिकटको पैसा पूरा भइसक्यो — फेरि नतिर्नुहोस्। · This ticket is fully paid — please do not pay again.</div>
<?php else: ?>
    <div class="row">यो बुकिङमा भुक्तानी बाँकी छैन (रद्द / म्याद सकिएको / भुक्तानी भइसकेको) — कार्यालयमा सम्पर्क गर्नुहोस्। · No payment is due on this booking.</div>
<?php endif; ?>
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
$e      = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/* 16 Nov 2026 (P1): the pay-at-boarding page - free ways + the UTR box. */
$claim    = null;    // the UTR already sent for this ticket (CodPayClaim::current)
$claimErr = '';
$ownsNow  = false;   // this browser / key already proves the ticket is theirs: no mobile asked
$esewa    = null;
$upiQr    = '';
$key      = '';
$isPost   = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($boardPay) {
    require_once INCLUDE_PATH . '/payadapter.php';
    header('Cache-Control: no-store');
    $key  = Security::clean((string) ($_GET['k'] ?? ''), 64);
    $self = 'pay.php?pnr=' . rawurlencode($pnr) . ($key !== '' ? '&k=' . rawurlencode($key) : '');
    if ($isPost) {
        $code = 200;
        if (!Security::rateLimit('payment_proof', Security::clientIp(), 20, 300)) {
            $code     = 429;
            $claimErr = 'धेरै पटक पठाइयो — केही मिनेटपछि फेरि प्रयास गर्नुहोस् · Too many tries - please wait a few minutes.';
        } elseif (!Security::verifyCsrf()) {
            $code     = 419;
            $claimErr = 'पेज पुरानो भयो — पेज फेरि खोलेर पठाउनुहोस् · This page expired - open it again and send.';
        } else {
            try {
                CodPayClaim::submit($booking, (string) ($_POST['utr'] ?? ''), (string) ($_POST['method'] ?? 'upi'),
                    Security::clean((string) ($_POST['phone'] ?? ''), 20), $key);
                header('Location: ' . $self . '&sent=1', true, 303);
                exit;
            } catch (RuntimeException $ex) {
                $c        = $ex->getCode();
                $code     = is_int($c) && in_array($c, [403, 404, 409, 422], true) ? $c : 400;
                $claimErr = $ex->getMessage();
            } catch (Throwable $ex) {
                Logger::error('pay.php online claim failed: ' . $ex->getMessage(), ['pnr' => $pnr]);
                $code     = 500;
                $claimErr = 'केही गडबड भयो — कार्यालयमा सम्पर्क गर्नुहोस् · Something went wrong - please contact the office.';
            }
        }
        http_response_code($code);
    }
    $claim   = CodPayClaim::current((int) $booking['id']);
    $ownsNow = CodPayClaim::owns($booking, $key);
    if ($claim === null) {
        require_once INCLUDE_PATH . '/qr.php';
        try {
            $upiQr = QrCode::dataUri($upiUri, 7, 3);
        } catch (Throwable $ex) {
            $upiQr = '';   // the button and the UPI ID still work
        }
        $esewa = (new ManualQrAdapter())->start($booking, $due)['esewa'] ?? null;   // the checkout's eSewa ID / QR image
    }
}

/* Intent trail for the desk: this passenger tapped pay. The UPI app itself
   never reports back, so this is the last thing we can see. (A pay-at-boarding
   page that only shows "being checked", or a UTR post, is not a pay tap.) */
require_once INCLUDE_PATH . '/payevents.php';
if (!$isPost && $claim === null) {
    PayEvents::log((int) ($booking['id'] ?? 0), $pnr, 'pay_open');
}

// Prefer a direct 302 to the upi:// scheme so the UPI app opens the
// moment the customer taps in WhatsApp. HTML fallback follows for the
// rare browser that ignores an unknown-scheme Location header.
// A pay-at-boarding ticket stays on this page (cash on the bus, or online here).
if (!$boardPay) {
    header('Location: ' . $upiUri, true, 302);
}
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
  .amt { font-size:32px; font-weight:700; color:#0a7c2f; margin:12px 0; }
  .row { margin:8px 0; color:#555; }
  .btn { display:block; text-align:center; background:#0a7c2f; color:#fff;
         padding:14px 20px; border-radius:12px; text-decoration:none;
         font-weight:600; margin:16px 0 8px; }
  code { background:#eef1f5; padding:2px 6px; border-radius:4px; font-size:14px; }
<?php if ($boardPay): /* 16 Nov 2026 (P1): pay-at-boarding page, 360 px and dark included */ ?>
  .card { box-sizing:border-box; }
  .row { line-height:1.5; overflow-wrap:anywhere; }
  .bp { display:inline-block; background:#fff4d6; color:#7a4a00; border:1px solid #f0c674; border-radius:999px;
        padding:4px 12px; font-weight:700; font-size:14px; margin:6px 0 0; }
  .qrbox { text-align:center; margin:14px 0 4px; }
  .qr { width:220px; max-width:100%; height:auto; background:#fff; border:1px solid #e3e8ef; border-radius:12px; padding:8px; box-sizing:border-box; }
  .cap, .note { font-size:13px; color:#555; line-height:1.5; margin:6px 0; }
  .es { margin:16px 0 8px; padding:12px 14px; border:1px solid #e3e8ef; border-radius:12px; }
  .es h2, .claim h2 { font-size:16px; margin:0 0 6px; }
  .claim { margin:16px 0 8px; padding:14px; border:1px solid #d9e2ef; border-radius:12px; background:#f8fafc; }
  .claim .f { display:block; font-size:13px; font-weight:600; margin:12px 0 4px; color:#333; }
  .claim input[type=text], .claim input[type=tel] { width:100%; box-sizing:border-box; height:46px; border:1px solid #c9d3e1; border-radius:10px;
        padding:0 12px; font:inherit; font-size:16px; background:#fff; color:#111; }
  .seg { display:flex; gap:8px; margin:0; }
  .seg label { flex:1; display:flex; align-items:center; justify-content:center; gap:6px; min-height:44px; border:1px solid #c9d3e1;
        border-radius:10px; font-weight:600; cursor:pointer; }
  .seg input { margin:0; }
  .btn2 { display:block; width:100%; text-align:center; background:#1d3f8f; color:#fff; border:0; padding:14px 20px; border-radius:12px;
        font:inherit; font-weight:600; margin:14px 0 8px; cursor:pointer; }
  .err { color:#b3261e; font-weight:600; font-size:14px; margin:10px 0 0; line-height:1.45; }
  .chk { margin:14px 0; padding:14px; border-radius:12px; background:#fff8e1; border:1px solid #f0c674; color:#5c4100; line-height:1.5; }
  .chk b { display:block; font-size:16px; margin-bottom:4px; }
  .sent { margin:14px 0 0; color:#0a7c2f; font-weight:700; }
  :root[data-theme="dark"] .bp { background:#3a2c0b; color:#ffd98a; border-color:#6b5217; }
  :root[data-theme="dark"] .cap, :root[data-theme="dark"] .note { color:#a9b6c9; }
  :root[data-theme="dark"] .es, :root[data-theme="dark"] .claim { background:#0f1828; border-color:#2a3a55; }
  :root[data-theme="dark"] .claim .f { color:#c3cfdf; }
  :root[data-theme="dark"] .claim input[type=text], :root[data-theme="dark"] .claim input[type=tel] { background:#0b1220; color:#e8eef8; border-color:#33465f; }
  :root[data-theme="dark"] .seg label { border-color:#33465f; }
  :root[data-theme="dark"] .btn2 { background:#3b6fd8; }
  :root[data-theme="dark"] .err { color:#ff8a80; }
  :root[data-theme="dark"] .chk { background:#33270a; border-color:#6b5217; color:#ffe2a3; }
  :root[data-theme="dark"] .sent { color:#4ade80; }
<?php endif; ?>
  <?= $payThemeCss ?>
</style>
</head>
<body>
  <div class="card">
    <?= $payBrand ?>
    <h1>भुक्तानी · Pay</h1>
    <div class="row">बुकिङ नं.: <code><?= htmlspecialchars($pnr, ENT_QUOTES, 'UTF-8') ?></code></div>
<?php if ($boardPay): ?>
    <div class="bp">🚌 बसमा तिर्ने टिकट · Pay on boarding</div>
<?php endif; ?>
    <div class="amt">₹<?= number_format($total, 2) ?></div>
<?php if (!$boardPay || $claim === null): ?>
<?php if ($boardPay): ?>
    <div class="row">बसमा चढ्दा नगद दिन सक्नुहुन्छ, वा अहिले नै अनलाइन तिर्नुहोस् — UPI / eSewa, कुनै शुल्क लाग्दैन। · Pay cash when you board, or online now — UPI / eSewa, no fee.</div>
<?php endif; ?>
    <a class="btn" href="<?= htmlspecialchars($upiUri, ENT_QUOTES, 'UTF-8') ?>">UPI app मा खोल्नुहोस्</a>
    <div class="row">UPI ID: <code><?= htmlspecialchars($upiId, ENT_QUOTES, 'UTF-8') ?></code></div>
<?php endif; ?>
<?php if ($boardPay && $claim !== null): ?>
<?php if (isset($_GET['sent'])): ?>
    <div class="sent" role="status">✅ UTR पठाइयो · Sent</div>
<?php endif; ?>
    <div class="chk" role="status">
      <b>⏳ तपाईंको भुक्तानी जाँचमा छ · Your payment is being checked</b>
      <?= $e($claim['method'] === 'esewa' ? 'eSewa' : 'UPI') ?> · UTR …<?= $e(substr($claim['utr'], -4)) ?><?= $claim['amount'] > 0 ? ' · ₹' . $e(number_format($claim['amount'], 2)) : '' ?><br>
      अफिसले बैंकमा मिलाएपछि टिकटमा "भुक्तानी भयो" देखिन्छ — त्यतिन्जेल फेरि नतिर्नुहोस्। भेटिएन भने बसमा नगद तिर्नुहोस्।
      · Once the office matches it with the bank, the ticket shows PAID. Please do not pay again meanwhile; if it is not found, pay cash on the bus.
    </div>
<?php elseif ($boardPay): ?>
<?php if ($upiQr !== ''): ?>
    <div class="qrbox">
      <img class="qr" src="<?= $e($upiQr) ?>" alt="UPI QR · ₹<?= $e(number_format($total, 2)) ?>" width="220" height="220">
      <div class="cap">QR स्क्यान गर्नुहोस् — रकम र PNR भरिएर आउँछ · Scan with any UPI app — amount and PNR filled in</div>
    </div>
<?php endif; ?>
<?php if (is_array($esewa) && ($esewa['id'] ?? '') !== ''): ?>
    <div class="es">
      <h2>🇳🇵 eSewa</h2>
<?php if (($esewa['qr'] ?? '') !== ''): ?>
      <div class="qrbox"><img class="qr" src="<?= $e($esewa['qr']) ?>" alt="eSewa QR" width="220" height="220"></div>
<?php endif; ?>
      <div class="row">eSewa ID: <code><?= $e($esewa['id']) ?></code><?= ($esewa['name'] ?? '') !== '' ? ' · ' . $e($esewa['name']) : '' ?></div>
      <div class="row">रकम · Amount: <b>NPR <?= $e(number_format((float) ($esewa['npr'] ?? 0))) ?></b></div>
      <div class="note">eSewa एपबाट पठाउँदा remarks मा <?= $e($pnr) ?> लेख्नुहोस्। · Put <?= $e($pnr) ?> in the eSewa remarks.</div>
    </div>
<?php endif; ?>
    <form class="claim" method="post" action="<?= $e($self) ?>" autocomplete="off">
      <h2>तिर्नुभयो? UTR पठाउनुहोस् · Paid? Send the UTR</h2>
      <div class="f" id="paidBy">कसरी तिर्नुभयो · Paid by</div>
      <div class="seg" role="radiogroup" aria-labelledby="paidBy">
        <label><input type="radio" name="method" value="upi"<?= ($_POST['method'] ?? 'upi') !== 'esewa' ? ' checked' : '' ?>> UPI</label>
<?php if (is_array($esewa) && ($esewa['id'] ?? '') !== ''): ?>
        <label><input type="radio" name="method" value="esewa"<?= ($_POST['method'] ?? '') === 'esewa' ? ' checked' : '' ?>> eSewa</label>
<?php endif; ?>
      </div>
      <label class="f" for="utr">UTR / कारोबार नम्बर · Transaction no.</label>
      <input type="text" id="utr" name="utr" maxlength="40" required inputmode="text" autocapitalize="characters" spellcheck="false"
             placeholder="UPI: १२ अङ्क · 12 digits" value="<?= $e($isPost ? (string) ($_POST['utr'] ?? '') : '') ?>">
<?php if (!$ownsNow): ?>
      <label class="f" for="phone">बुकिङको मोबाइल नम्बर · Booking mobile</label>
      <input type="tel" id="phone" name="phone" maxlength="15" required inputmode="numeric" autocomplete="tel"
             value="<?= $e($isPost ? (string) ($_POST['phone'] ?? '') : '') ?>">
<?php endif; ?>
      <input type="hidden" name="<?= $e(CSRF_TOKEN_NAME) ?>" value="<?= $e(Security::csrfToken()) ?>">
<?php if ($claimErr !== ''): ?>
      <p class="err" role="alert"><?= $e($claimErr) ?></p>
<?php endif; ?>
      <button class="btn2" type="submit">जाँचका लागि पठाउनुहोस् · Send for checking</button>
      <div class="note">अफिसले बैंक जाँचेपछि मात्र भुक्तानी पक्का हुन्छ — यो पेजले आफैं केही पक्का गर्दैन। · Only the office confirms it, after checking the bank.</div>
    </form>
<?php endif; ?>
<?php
  /* One tap opens our WhatsApp with the PNR already typed. That inbound
     message opens the 24 h service window, which is what lets the ticket
     and every later update reach this customer without Meta billing. */
  if ($bizWa !== ''):
      $waText = rawurlencode($boardPay ? 'मेरो बुकिङ ' . $pnr . ' को भुक्तानी बारे सोध्नु छ।' : 'मेरो बुकिङ ' . $pnr . ' को टिकट पठाउनुहोस्।');
?>
    <a class="btn" style="background:#128C7E" href="https://wa.me/<?= htmlspecialchars($bizWa, ENT_QUOTES, 'UTF-8') ?>?text=<?= $waText ?>"><?= $boardPay ? 'WhatsApp मा कार्यालयसँग कुरा गर्नुहोस्' : 'WhatsApp मा टिकट पाउनुहोस्' ?></a>
<?php endif; ?>
    <div class="row">प्राप्तकर्ता: <?= htmlspecialchars($upiName, ENT_QUOTES, 'UTF-8') ?></div>
<?php if (!$boardPay): ?>
    <script>
      // In case the header redirect was blocked, try once more from JS.
      setTimeout(function(){ location.href = <?= json_encode($upiUri) ?>; }, 400);
    </script>
<?php endif; ?>
  </div>
</body>
</html>
