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

/* 19 Nov 2026 (u9, blueprint): SELECT * so the sale's company (counter_code / entity) and the
   NPR frozen at a Nepal desk (fx_currency / fx_total) reach the eSewa box when those columns exist. */
$booking = Database::fetch(
    'SELECT * FROM bookings WHERE pnr = :p LIMIT 1',
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
/* 25 Nov 2026 (mp1): while the India bank API is live the link also carries tr=<PNR> (BankApi::upiUri). */
if (is_file(INCLUDE_PATH . '/bankapi.php')) {
    require_once INCLUDE_PATH . '/bankapi.php';
}
$upiUri = class_exists('BankApi') && BankApi::upiOn() ? BankApi::upiUri($upiId, $upiName, $total, $pnr) : upiLink($upiId, $upiName, $total, $pnr);
$e      = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

/* 19 Nov 2026 (u9, owner's blueprint "direct UPI only, deep links with the exact amount"):
   one big button per UPI app - Google Pay, PhonePe, Paytm - each with the SAME pa / pn / am /
   cu / tn=PNR as the upi:// link, so the app opens with the amount and the PNR filled in. The
   green upi:// button below stays the "BHIM / any UPI app" one. pay_upi_app_buttons = 0 hides
   the three. pay_app_chooser_on = 1 also shows this page (apps + the exact-amount QR + eSewa)
   to a pending online booking instead of the straight 302 into the UPI app (default 0 = the
   302 stays exactly as it is). Nothing here confirms money: the office does, after the bank. */
$upiQuery   = (string) substr($upiUri, strlen('upi://pay?'));
$appButtons = Settings::getBool('pay_upi_app_buttons', true);
$upiApps    = $appButtons ? [
    ['gpay',    'Google Pay', 'tez://upi/pay?' . $upiQuery],
    ['phonepe', 'PhonePe',    'phonepe://pay?' . $upiQuery],
    ['paytm',   'Paytm',      'paytmmp://pay?' . $upiQuery],
] : [];
/* 19 Nov 2026 (u9): eSewa of the company that issued THIS ticket (Settings::companyFor: the
   Nepal company's own eSewa once the office typed it, else the India one - the checkout's
   rule), in रू: the NPR frozen on the sale at a Nepal desk when there is one (scaled to what
   is still due), else the INR due x npr_per_inr. The eSewa QR picture only when the site
   has one (the same files the checkout looks for) - never a made-up one. Worked out before
   the page / 302 choice: a Nepal sale's passenger may have no UPI app at all. */
if (is_file(INCLUDE_PATH . '/counterdesk.php')) {
    require_once INCLUDE_PATH . '/counterdesk.php';   // companyFor reads the desk's own entity
}
$co        = Settings::companyFor((string) ($booking['counter_code'] ?? ''), (string) ($booking['entity'] ?? ''));
$esewaId   = trim((string) ($co['payEsewa'] ?? ''));
$frozenNpr = strtoupper((string) ($booking['fx_currency'] ?? '')) === 'NPR'
    && (float) ($booking['fx_total'] ?? 0) > 0 && (float) ($booking['total_amount'] ?? 0) > 0;
$npSaleish = ($co['saleEntity'] ?? 'IN') === 'NP' || ($co['entity'] ?? 'IN') === 'NP' || $frozenNpr;
/* 26 Nov 2026 (mp4 review fix): a Nepal sale whose QR library row (this desk's, else the NP
   default) is set up gets the Nepal box even with no eSewa id typed in settings - the same
   rule the ticket prints by (Ticket::paymentQr), so the ticket's "pay" link never lands on a
   page without the QR the ticket showed. */
require_once INCLUDE_PATH . '/payadapter.php';
$npLibRow  = $npSaleish && ManualQrAdapter::nepalQr($booking, $esewaId)['row'] !== null;
$nepalSale = ($esewaId !== '' || $npLibRow) && $npSaleish;

/* The page (not the 302) for a pending / rejected online booking: when the office turned the
   chooser on for everyone (pay_app_chooser_on, default off), or - spec gap fix - for a NEPAL
   sale with an eSewa to show (pay_nepal_chooser_on, default on): eSewa in रू comes first there. */
$pendingNow = in_array($bStatus, ['pending', 'rejected'], true) && !$boardPay;
$chooser    = $boardPay || ($pendingNow && (Settings::getBool('pay_app_chooser_on', false)
    || ($nepalSale && Settings::getBool('pay_nepal_chooser_on', true))));

/* 16 Nov 2026 (P1): the pay-at-boarding page - free ways + the UTR box. */
$claim    = null;    // the UTR already sent for this ticket (CodPayClaim::current)
$claimErr = '';
$ownsNow  = false;   // this browser / key already proves the ticket is theirs: no mobile asked
$esewa    = null;
$upiQr    = '';
$upiQrStatic = '';
$key      = '';
$isPost   = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$shotOn   = Settings::getBool('pay_claim_screenshot_on', true);

/* 19 Nov 2026 (u9 review): the pay-at-boarding claim's screenshot is stored WITHOUT the
   'booking.payment_uploaded' event. BookingService::attachScreenshot emits it, and its
   listener WhatsApps the CUSTOMER "we will confirm your ticket soon" (on a ticket that is
   already confirmed) plus a second office message - CodPayClaim promises no customer message
   and pings the office once itself. Same checks as attachScreenshot (Security::validateUpload,
   sha256, the PaymentRisk look-alike fingerprint) on the claim's own cod_pending money row;
   payments.mode stays 'utr'. Returns the stored path, or '' when nothing was stored. */
$storeClaimShot = static function (array $booking, array $file): string {
    $check = Security::validateUpload($file);
    if (empty($check['ok'])) {
        throw new RuntimeException((string) ($check['error'] ?? 'Upload failed.'));
    }
    $p = CodPayClaim::row((int) $booking['id']);
    if ($p === null) {
        return '';
    }
    $subDir = UPLOAD_PATH . '/' . date('Y') . '/' . date('m');
    ensureDir($subDir);
    $filename = Security::safeFilename((string) $check['ext']);
    $absolute = $subDir . '/' . $filename;
    $relative = date('Y') . '/' . date('m') . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $absolute)) {
        throw new RuntimeException('Could not save the uploaded file.');
    }
    $row = [
        'payment_id'    => (int) $p['id'],
        'booking_id'    => (int) $booking['id'],
        'file_path'     => $relative,
        'original_name' => Security::clean((string) ($file['name'] ?? ''), 191),
        'mime_type'     => (string) $check['mime'],
        'file_size'     => (int) $check['size'],
        'sha256'        => hash_file('sha256', $absolute) ?: null,
        'uploaded_ip'   => Security::clientIp(),
    ];
    try {
        require_once INCLUDE_PATH . '/paymentrisk.php';
        $fp = PaymentRisk::fingerprint($absolute, (string) $check['mime']);
        if ($fp['phash'] !== null && PaymentRisk::hasCol('payment_screenshots', 'phash')) {
            $row['phash'] = $fp['phash'];
        }
        if ($fp['editTool'] !== null && PaymentRisk::hasCol('payment_screenshots', 'edit_tool')) {
            $row['edit_tool'] = $fp['editTool'];
        }
    } catch (Throwable $ex) {
        // the proof matters more than its fingerprint
    }
    Database::insert('payment_screenshots', $row);
    Logger::audit('payment.cod_claim_screenshot', 'booking', (string) $booking['pnr'], null, ['file' => $relative],
        'Pay-at-boarding online claim: payment screenshot attached (no message sent; nothing confirmed)');
    return $relative;
};

/* 19 Nov 2026 (u9, spec gap): a pending / rejected online booking that gets the page also gets
   the website's own proof box - UTR and/or screenshot - through the SAME calls api/payment.php
   makes (BookingService::attachScreenshot + submitPaymentProof: the verify queue, the hold on
   the expiry clock, the "proof received" note). The same ownership rule (this browser's PNR,
   the account, the ticket key k, or the booking's mobile typed), the UTR shape rule and a UTR
   already on ANOTHER booking refused. Never a confirmation. pay_pending_proof_on = 0 keeps the
   older "send it on WhatsApp" note. */
$pendProof = $chooser && $pendingNow && Settings::getBool('pay_pending_proof_on', true);
$proofErr  = '';
$proofIn   = false;   // proof already waiting for the office on this pending booking
if ($pendProof) {
    require_once INCLUDE_PATH . '/payadapter.php';
    require_once INCLUDE_PATH . '/booking.php';
    header('Cache-Control: no-store');
    $key  = Security::clean((string) ($_GET['k'] ?? ''), 64);
    $self = 'pay.php?pnr=' . rawurlencode($pnr) . ($key !== '' ? '&k=' . rawurlencode($key) : '');
    if ($isPost) {
        $code = 200;
        if (!Security::rateLimit('payment_proof', Security::clientIp(), 20, 300)) {
            $code     = 429;
            $proofErr = 'धेरै पटक पठाइयो — केही मिनेटपछि फेरि प्रयास गर्नुहोस् · Too many tries - please wait a few minutes.';
        } elseif (!Security::verifyCsrf()) {
            $code     = 419;
            $proofErr = 'पेज पुरानो भयो — पेज फेरि खोलेर पठाउनुहोस् · This page expired - open it again and send.';
        } else {
            try {
                if (!CodPayClaim::owns($booking, $key, Security::clean((string) ($_POST['phone'] ?? ''), 20))) {
                    throw new RuntimeException('बुकिङ गर्दा दिएको मोबाइल नम्बर हाल्नुहोस् · Enter the mobile number used for this booking.', 403);
                }
                $method  = strtolower((string) ($_POST['method'] ?? 'upi')) === 'esewa' ? 'esewa' : 'upi';
                $utr     = CodPayClaim::normaliseUtr((string) ($_POST['utr'] ?? ''));
                $shot    = $_FILES['shot'] ?? null;
                $hasShot = $shotOn && is_array($shot) && (int) ($shot['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
                    && is_uploaded_file((string) ($shot['tmp_name'] ?? ''));
                if ($utr === '' && !$hasShot) {
                    throw new RuntimeException('UTR लेख्नुहोस् वा screenshot राख्नुहोस् · Type the UTR or attach the payment screenshot.', 422);
                }
                if ($utr !== '') {
                    require_once INCLUDE_PATH . '/paymentrisk.php';
                    $bad = PaymentRisk::utrLooksWrong($utr, $method);
                    if ($bad !== null) {
                        throw new RuntimeException(($method === 'upi'
                            ? 'UPI को UTR १२ अङ्कको हुन्छ — भुक्तानी एपमा हेरेर फेरि लेख्नुहोस् · '
                            : 'eSewa को कारोबार कोड ६-२० अक्षर/अङ्कको हुन्छ · ') . $bad . '.', 422);
                    }
                    require_once INCLUDE_PATH . '/wapayproof.php';
                    $other = WaPayProof::utrUsedElsewhere($utr, (int) $booking['id']);
                    if ($other !== '') {
                        Logger::audit('payment.dup_utr_refused', 'booking', $pnr, null, ['utr_tail' => substr($utr, -4), 'also_on' => $other, 'via' => 'pay_page'],
                            'A payment reference already used on another booking was refused on the pay page (nothing stored)');
                        throw new RuntimeException('यो UTR पहिले नै प्रयोग भइसकेको छ · This UTR has already been used.', 409);
                    }
                }
                if ($hasShot) {
                    BookingService::attachScreenshot($pnr, $shot);
                }
                BookingService::submitPaymentProof($pnr, [
                    'utr'           => $utr,
                    'payerName'     => '',
                    'method'        => $method,
                    'hasScreenshot' => $hasShot,
                ]);
                header('Location: ' . $self . '&sent=1', true, 303);
                exit;
            } catch (RuntimeException $ex) {
                $c        = $ex->getCode();
                $code     = is_int($c) && in_array($c, [403, 404, 409, 422], true) ? $c : 400;
                $proofErr = $ex->getMessage();
            } catch (Throwable $ex) {
                Logger::error('pay.php pending proof failed: ' . $ex->getMessage(), ['pnr' => $pnr]);
                $code     = 500;
                $proofErr = 'केही गडबड भयो — कार्यालयमा सम्पर्क गर्नुहोस् · Something went wrong - please contact the office.';
            }
        }
        http_response_code($code);
    }
    $ownsNow = CodPayClaim::owns($booking, $key);
    if ($bStatus === 'pending') {
        $lastPay = Database::fetch('SELECT id, utr_number, status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => (int) $booking['id']]);
        $proofIn = $lastPay !== null && (string) $lastPay['status'] === 'pending' && (trim((string) ($lastPay['utr_number'] ?? '')) !== ''
            || Database::exists('SELECT 1 FROM payment_screenshots WHERE payment_id = :p LIMIT 1', ['p' => (int) $lastPay['id']]));
    }
}

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
                /* 19 Nov 2026 (u9): the payment screenshot, when one came with the UTR, goes on the
                   same money row ($storeClaimShot above - validated, hashed, fingerprinted, NO
                   customer message; the office sees it on Payments -> COD with the "Screenshot
                   also on" duplicate check). Only after the claim was accepted, so a refused UTR
                   never leaves a file behind. A failed upload never undoes the claim. */
                $shot = $_FILES['shot'] ?? null;
                if ($shotOn && is_array($shot)
                    && (int) ($shot['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) ($shot['tmp_name'] ?? ''))) {
                    try {
                        $storeClaimShot($booking, $shot);
                    } catch (Throwable $ex) {
                        Logger::warning('pay.php claim screenshot not saved: ' . $ex->getMessage(), ['pnr' => $pnr]);
                    }
                }
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
}
if ($chooser && $claim === null && !$proofIn) {
    header('Cache-Control: no-store');
    require_once INCLUDE_PATH . '/qr.php';
    try {
        $upiQr = QrCode::dataUri($upiUri, 7, 3);
    } catch (Throwable $ex) {
        $upiQr = '';   // the button and the UPI ID still work
    }
    /* 25 Nov 2026 (mp4): only when the dynamic UPI QR (amount + PNR) cannot be drawn, the India
       QR picture uploaded on Payment Management (its default, or this desk's own) stands in. */
    $upiQrStatic = '';   // review fix: the static picture's own UPI id, printed under it
    if ($upiQr === '') {
        try {
            require_once INCLUDE_PATH . '/payqr.php';
            $inQ   = PayQr::resolve($booking, 'IN');   // the same resolver (and desk) as the Nepal QR
            $upiQr = PayQr::dataUri($inQ['row']);
            if ($upiQr !== '') {
                /* the picture's own UPI id; a row without one says "scan" only, never the global upi_id
                   (that may be another account than the one in the picture) */
                $upiQrStatic = $inQ['payee'] !== '' ? $inQ['payee'] : '-';   // '-' = a static picture with no id typed
            }
        } catch (Throwable $ex) {
            $upiQr = '';
        }
    }
    if ($esewaId !== '' || $npLibRow) {
        $npr = $frozenNpr
            ? round((float) $booking['fx_total'] * $due / (float) $booking['total_amount'])
            : round($due * Settings::getFloat('npr_per_inr', 1.6));
        /* 25 Nov 2026 (mp1): one Nepal QR everywhere - the uploaded NPJ QR the ticket prints
           (Ticket::esewaQrFile), else the fixed esewa-qr files. */
        require_once INCLUDE_PATH . '/payadapter.php';
        /* review fix: the holder name belongs to the wallet shown. The Nepal company's own eSewa
           shows nepal_esewa_name (or no name); India's esewa_name only beside India's esewa_id. */
        $npOwnEsewa = ($co['saleEntity'] ?? 'IN') === 'NP' && trim(Settings::getString('nepal_esewa_id', '')) !== ''
            && $esewaId === trim(Settings::getString('nepal_esewa_id', ''));
        /* review fix (money, mp4): picture, id and wallet word from ONE resolver - PayQr::resolve,
           the same one the ticket prints from (the issuing desk's QR, else the NP default, else the
           old single QR with this sale's own eSewa id). Never one wallet's QR beside another's id. */
        $npQ = ManualQrAdapter::nepalQr($booking, $esewaId,
            $npOwnEsewa ? trim((string) ($co['payEsewaName'] ?? '')) : trim(Settings::getString('esewa_name', '')));
        $esewa = [
            'id'   => $npQ['payee'],
            'name' => $npQ['name'],
            'kind' => $npQ['kind'],
            'npr'  => $npr,
            'qr'   => ManualQrAdapter::qrSrc($npQ['file']),
        ];
    }
}

/* Intent trail for the desk: this passenger tapped pay. The UPI app itself
   never reports back, so this is the last thing we can see. (A pay-at-boarding
   page that only shows "being checked", or a UTR post, is not a pay tap.) */
require_once INCLUDE_PATH . '/payevents.php';
if (!$isPost && $claim === null && !$proofIn) {
    PayEvents::log((int) ($booking['id'] ?? 0), $pnr, 'pay_open');
}

// Prefer a direct 302 to the upi:// scheme so the UPI app opens the
// moment the customer taps in WhatsApp. HTML fallback follows for the
// rare browser that ignores an unknown-scheme Location header.
// A pay-at-boarding ticket stays on this page (cash on the bus, or online here),
// and so does a pending one while the office has pay_app_chooser_on (19 Nov 2026, u9).
if (!$chooser) {
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
<?php if ($upiApps !== []): /* 19 Nov 2026 (u9): one big button per UPI app, the green any-UPI button stays below */ ?>
  .apps { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:8px; margin:16px 0 0; }
  .apps a { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:2px; min-height:56px; padding:8px 4px;
        border-radius:12px; text-decoration:none; font-weight:700; font-size:14px; color:#fff; text-align:center; box-sizing:border-box; }
  .apps a small { font-weight:600; font-size:11px; opacity:.9; }
  .apps .gpay { background:#1a73e8; } .apps .phonepe { background:#5f259f; } .apps .paytm { background:#00a2e8; }
  .anyupi { font-size:12px; color:#555; text-align:center; margin:-4px 0 8px; }
  :root[data-theme="dark"] .anyupi { color:#a9b6c9; }
<?php endif; ?>
<?php if ($chooser): /* 16 Nov 2026 (P1): pay-at-boarding page, 360 px and dark included (19 Nov: also the pending chooser) */ ?>
  .card { box-sizing:border-box; }
  @media (max-width: 380px) { .card { padding:18px 14px; } }
  .row { line-height:1.5; overflow-wrap:anywhere; }
  .bp { display:inline-block; background:#fff4d6; color:#7a4a00; border:1px solid #f0c674; border-radius:999px;
        padding:4px 12px; font-weight:700; font-size:14px; margin:6px 0 0; }
  .qrbox { text-align:center; margin:14px 0 4px; }
  .qr { width:300px; max-width:100%; height:auto; background:#fff; border:1px solid #e3e8ef; border-radius:12px; padding:8px; box-sizing:border-box; }
  .npr { font-size:24px; font-weight:800; color:#0a7c2f; }
  :root[data-theme="dark"] .npr { color:#4ade80; }
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
<?php if ((!$boardPay || $claim === null) && !$proofIn): ?>
<?php if ($boardPay): ?>
    <div class="row">बसमा चढ्दा नगद दिन सक्नुहुन्छ, वा अहिले नै अनलाइन तिर्नुहोस् — UPI / eSewa, कुनै शुल्क लाग्दैन। · Pay cash when you board, or online now — UPI / eSewa, no fee.</div>
<?php endif; ?>
<?php if ($upiApps !== []): ?>
    <div class="apps" role="group" aria-label="UPI app छान्नुहोस् · Pick your UPI app">
<?php foreach ($upiApps as [$appKey, $appName, $appUri]): ?>
      <a class="<?= $e($appKey) ?>" href="<?= $e($appUri) ?>" rel="nofollow"><?= $e($appName) ?><small>₹<?= $e(number_format($total, 2)) ?></small></a>
<?php endforeach; ?>
    </div>
<?php endif; ?>
    <a class="btn" href="<?= htmlspecialchars($upiUri, ENT_QUOTES, 'UTF-8') ?>">UPI app मा खोल्नुहोस्</a>
<?php if ($upiApps !== []): ?>
    <div class="anyupi">BHIM वा जुनसुकै UPI app · BHIM or any UPI app — रकम र PNR भरिएर आउँछ · amount and PNR filled in</div>
<?php endif; ?>
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
<?php elseif ($proofIn): /* 19 Nov 2026 (u9): a pending booking whose proof already waits for the office */ ?>
<?php if (isset($_GET['sent'])): ?>
    <div class="sent" role="status">✅ पठाइयो · Sent</div>
<?php endif; ?>
    <div class="chk" role="status">
      <b>⏳ तपाईंको भुक्तानी जाँचमा छ · Your payment is being checked</b>
      अफिसले बैंक जाँचेपछि टिकट पक्का हुन्छ र WhatsApp मा आउँछ — त्यतिन्जेल फेरि नतिर्नुहोस्।
      · The office confirms the ticket after checking the bank and sends it on WhatsApp. Please do not pay again meanwhile.
    </div>
<?php elseif ($chooser): ?>
<?php
    /* 19 Nov 2026 (u9): the eSewa box in रू (frozen NPR, else INR due x npr_per_inr) - first on a
       Nepal sale, after the UPI QR otherwise. Built once, printed in one of the two places. */
    $esewaHtml = '';
    if (is_array($esewa) && (($esewa['id'] ?? '') !== '' || ($esewa['qr'] ?? '') !== '')) {   // mp4: a library QR without a payee = the picture only
        $esKind = (string) ($esewa['kind'] ?? 'eSewa');
        $esewaHtml = '    <div class="es">' . "\n" . '      <h2>🇳🇵 ' . $e($esKind) . '</h2>' . "\n"
            . (($esewa['qr'] ?? '') !== '' ? '      <div class="qrbox"><img class="qr" src="' . $e($esewa['qr']) . '" alt="' . $e($esKind) . ' QR" width="300" height="300"></div>' . "\n" : '')
            . (($esewa['id'] ?? '') !== '' ? '      <div class="row">' . $e($esKind) . ' ID: <code>' . $e($esewa['id']) . '</code>' . (($esewa['name'] ?? '') !== '' ? ' · ' . $e($esewa['name']) : '') . '</div>' . "\n" : '')
            . '      <div class="row">रकम · Amount: <b class="npr">रू ' . $e(number_format((float) ($esewa['npr'] ?? 0))) . '</b> (₹' . $e(number_format($total, 2)) . ')</div>' . "\n"
            . '      <div class="note">' . $e($esKind) . ' बाट पठाउँदा remarks मा ' . $e($pnr) . ' लेख्नुहोस्। · Put ' . $e($pnr) . ' in the ' . $e($esKind) . ' remarks.</div>' . "\n"
            . '    </div>' . "\n";
    }
    if ($nepalSale) {
        echo $esewaHtml;
    }
?>
<?php if ($upiQr !== ''): ?>
    <div class="qrbox">
      <img class="qr" src="<?= $e($upiQr) ?>" alt="UPI QR · ₹<?= $e(number_format($total, 2)) ?>" width="300" height="300">
<?php if ($upiQrStatic !== ''): /* the uploaded static picture: no amount inside it, its own UPI id under it */ ?>
      <div class="cap"><?php if ($upiQrStatic !== '-'): ?>UPI ID: <code><?= $e($upiQrStatic) ?></code> — <?php endif; ?>₹<?= $e(number_format($total, 2)) ?> आफैं भर्नुहोस्, remarks मा <?= $e($pnr) ?> · type ₹<?= $e(number_format($total, 2)) ?> yourself, put <?= $e($pnr) ?> in the remarks</div>
<?php else: ?>
      <div class="cap">QR स्क्यान गर्नुहोस् — रकम र PNR भरिएर आउँछ · Scan with any UPI app — amount and PNR filled in</div>
<?php endif; ?>
    </div>
<?php endif; ?>
<?php if (!$nepalSale) { echo $esewaHtml; } ?>
<?php if (!$boardPay && !$pendProof): ?>
    <div class="note">तिरेपछि UTR (१२ अङ्क) वा screenshot तलको WhatsApp मा पठाउनुहोस् — अफिसले बैंक जाँचेर टिकट पक्का गर्छ। · After paying, send the UTR or the screenshot on WhatsApp below — the office confirms after checking the bank.</div>
<?php else: $formErr = $boardPay ? $claimErr : $proofErr; ?>
    <form class="claim" method="post" action="<?= $e($self) ?>" autocomplete="off"<?= $shotOn ? ' enctype="multipart/form-data"' : '' ?>>
<?php if ($boardPay): ?>
      <h2>तिर्नुभयो? UTR पठाउनुहोस् · Paid? Send the UTR</h2>
<?php else: ?>
      <h2>तिर्नुभयो? UTR वा screenshot पठाउनुहोस् · Paid? Send the UTR or the screenshot</h2>
<?php endif; ?>
      <div class="f" id="paidBy">कसरी तिर्नुभयो · Paid by</div>
      <div class="seg" role="radiogroup" aria-labelledby="paidBy">
        <label><input type="radio" name="method" value="upi"<?= ($_POST['method'] ?? 'upi') !== 'esewa' ? ' checked' : '' ?>> UPI</label>
<?php if (is_array($esewa) && (($esewa['id'] ?? '') !== '' || ($esewa['qr'] ?? '') !== '')): ?>
        <label><input type="radio" name="method" value="esewa"<?= ($_POST['method'] ?? '') === 'esewa' ? ' checked' : '' ?>> <?= $e((string) ($esewa['kind'] ?? 'eSewa')) ?></label>
<?php endif; ?>
      </div>
      <label class="f" for="utr">UTR / कारोबार नम्बर · Transaction no.</label>
      <input type="text" id="utr" name="utr" maxlength="40"<?= $boardPay ? ' required' : '' ?> inputmode="text" autocapitalize="characters" spellcheck="false"
             placeholder="UPI: १२ अङ्क · 12 digits" value="<?= $e($isPost ? (string) ($_POST['utr'] ?? '') : '') ?>">
<?php if (!$ownsNow): ?>
      <label class="f" for="phone">बुकिङको मोबाइल नम्बर · Booking mobile</label>
      <input type="tel" id="phone" name="phone" maxlength="15" required inputmode="numeric" autocomplete="tel"
             value="<?= $e($isPost ? (string) ($_POST['phone'] ?? '') : '') ?>">
<?php endif; ?>
<?php if ($shotOn): ?>
      <label class="f" for="shot">भुक्तानीको screenshot (ऐच्छिक) · Payment screenshot (optional)</label>
      <input type="file" id="shot" name="shot" accept="image/jpeg,image/png,image/webp">
<?php endif; ?>
      <input type="hidden" name="<?= $e(CSRF_TOKEN_NAME) ?>" value="<?= $e(Security::csrfToken()) ?>">
<?php if ($formErr !== ''): ?>
      <p class="err" role="alert"><?= $e($formErr) ?></p>
<?php endif; ?>
      <button class="btn2" type="submit">जाँचका लागि पठाउनुहोस् · Send for checking</button>
      <div class="note">अफिसले बैंक जाँचेपछि मात्र भुक्तानी पक्का हुन्छ — यो पेजले आफैं केही पक्का गर्दैन। · Only the office confirms it, after checking the bank.</div>
    </form>
<?php endif; ?>
<?php endif; ?>
<?php
  /* One tap opens our WhatsApp with the PNR already typed. That inbound
     message opens the 24 h service window, which is what lets the ticket
     and every later update reach this customer without Meta billing. */
  if ($bizWa !== ''):
      $waText = rawurlencode($boardPay ? 'मेरो बुकिङ ' . $pnr . ' को भुक्तानी बारे सोध्नु छ।'
          : ($chooser ? 'मेरो बुकिङ ' . $pnr . ' को भुक्तानी गरें। UTR: ' : 'मेरो बुकिङ ' . $pnr . ' को टिकट पठाउनुहोस्।'));
?>
    <a class="btn" style="background:#128C7E" href="https://wa.me/<?= htmlspecialchars($bizWa, ENT_QUOTES, 'UTF-8') ?>?text=<?= $waText ?>"><?= $boardPay ? 'WhatsApp मा कार्यालयसँग कुरा गर्नुहोस्' : 'WhatsApp मा टिकट पाउनुहोस्' ?></a>
<?php endif; ?>
    <div class="row">प्राप्तकर्ता: <?= htmlspecialchars($upiName, ENT_QUOTES, 'UTF-8') ?></div>
<?php if (!$chooser): ?>
    <script>
      // In case the header redirect was blocked, try once more from JS.
      setTimeout(function(){ location.href = <?= json_encode($upiUri) ?>; }, 400);
    </script>
<?php endif; ?>
  </div>
</body>
</html>
