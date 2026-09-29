<?php
/**
 * =====================================================================
 *  pay-share.php — one friend's share of a group booking (13 Sep 2026)
 *
 *  Opened from the link the organiser sends on WhatsApp:
 *      /pay-share.php?pnr=SHG-2026-00042&s=2&k=<hmac>
 *
 *  Shows the amount for THIS share, a UPI deep link + QR to the company
 *  VPA (Admin → Settings), and an "I have paid" form that records the
 *  payer's name + UTR against the share (api/split-pay.php claim). The
 *  key is an HMAC of pnr|share, so a link cannot be edited to another
 *  ticket or another amount — the amount is fixed server-side.
 *
 *  Public, no sign-in: the friend is not the passenger. Nothing here can
 *  confirm a ticket; the office verifies the total exactly as before.
 * =====================================================================
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/booking.php';

Security::requireRateLimit('pay_share', Security::clientIp(), 60, 60);

$e       = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$company = Settings::getString('company_name', APP_NAME);
$phone   = Settings::officePhone();
$wa      = Settings::officeWhatsApp();

$pnr = strtoupper(Security::clean($_GET['pnr'] ?? '', 40));
$s   = (int) ($_GET['s'] ?? 0);
$k   = Security::clean($_GET['k'] ?? '', 40);

$state   = 'invalid';    // invalid | open | claimed | confirmed | closed
$booking = null; $share = null; $detail = null;
if ($pnr !== '' && Security::isValidPnr($pnr) && $s >= 1 && $s <= 20 && $k !== ''
    && hash_equals(substr(Security::sign('split|' . $pnr . '|' . $s), 0, 20), $k)) {
    $booking = BookingService::findByPnr($pnr);
    if ($booking !== null) {
        $share = Database::fetch('SELECT * FROM payment_shares WHERE booking_id = :b AND share_no = :s', ['b' => (int) $booking['id'], 's' => $s]);
    }
    if ($booking !== null && $share !== null) {
        $st = (string) $booking['status'];
        if ($st === 'confirmed' || $st === 'completed') { $state = 'confirmed'; }
        elseif ($st !== 'pending') { $state = 'closed'; }
        else { $state = (string) $share['status'] === 'open' ? 'open' : 'claimed'; }
        try { $detail = BookingService::detail($pnr); } catch (Throwable $ex) { $detail = null; }
    }
}

$amount   = $share !== null ? (float) $share['amount'] : 0.0;
$nShares  = $booking !== null ? (int) Database::scalar('SELECT COUNT(*) FROM payment_shares WHERE booking_id = :b', ['b' => (int) $booking['id']], 0) : 0;
$leg      = is_array($detail) && !empty($detail['legs']) ? $detail['legs'][0] : [];
$routeLn  = trim((string) ($leg['from_city'] ?? '') . ' → ' . (string) ($leg['to_city'] ?? ''), ' →');
$dateLn   = !empty($leg['travel_date']) ? formatDate((string) $leg['travel_date']) : '';
$leadName = is_array($detail) && !empty($detail['passengers'][0]['full_name']) ? (string) $detail['passengers'][0]['full_name'] : '';
$upiId    = Settings::getString('upi_id', '');
$upiName  = Settings::getString('upi_name', $company);
$upiUrl   = ($upiId !== '' && $amount > 0) ? upiLink($upiId, $upiName, $amount, 'Bus ' . $pnr . ' share ' . $s) : '';
$qr       = $upiUrl !== '' ? QrCode::dataUri($upiUrl, 6, 2) : '';
$csrf     = Security::csrfToken();
$color    = $state === 'open' ? '#F07C1F' : ($state === 'claimed' || $state === 'confirmed' ? '#178A50' : '#C53030');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1A3A6A">
<meta name="robots" content="noindex, nofollow">
<title>Pay your share · <?= $e($company) ?></title>
<style>
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:#f5f6fa;color:#0f172a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Noto Sans Devanagari',sans-serif;-webkit-font-smoothing:antialiased}
  body{min-height:100vh;min-height:100dvh;padding:env(safe-area-inset-top,0) 16px env(safe-area-inset-bottom,24px)}
  .wrap{max-width:480px;margin:0 auto;padding:24px 0}
  .hdr{display:flex;align-items:center;gap:12px;margin-bottom:16px}
  .hdr img{width:40px;height:40px;border-radius:8px}
  .hdr b{font-size:15px;letter-spacing:.02em}.hdr small{display:block;color:#64748b;font-size:12px}
  .card{background:#fff;border-radius:18px;padding:24px 20px;text-align:center;box-shadow:0 4px 24px rgba(15,23,42,.08);border-top:6px solid <?= $color ?>}
  .amt{font-size:40px;font-weight:800;color:#1A3A6A;letter-spacing:-.02em;margin:4px 0}
  .sub{color:#475569;font-size:14px;line-height:1.5;margin:0 auto;max-width:380px}
  .pnr{font-family:'SF Mono',Consolas,monospace;letter-spacing:.06em;font-size:13px;color:#64748b}
  .qr{display:block;width:200px;height:200px;margin:14px auto 6px;border-radius:12px;border:1px solid #e2e8f0;background:#fff}
  .cta{display:flex;align-items:center;justify-content:center;gap:8px;background:#1A3A6A;color:#fff;text-decoration:none;text-align:center;padding:14px;border-radius:12px;margin-top:14px;font-weight:700;min-height:48px;touch-action:manipulation;border:0;width:100%;font-size:15px;cursor:pointer}
  .cta.o{background:#F07C1F}.cta.g{background:transparent;color:#1A3A6A;border:1.5px solid #C9D6EA}
  .vpa{margin-top:10px;font-size:13px;color:#475569}.vpa b{font-family:'SF Mono',Consolas,monospace;color:#0f172a}
  .details{background:#fff;border-radius:18px;margin-top:16px;padding:4px 8px;box-shadow:0 2px 12px rgba(15,23,42,.06)}
  .row{display:flex;padding:12px;border-top:1px solid #eef2f7;align-items:center;gap:8px}.row:first-child{border-top:none}
  .row .k{font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;flex:0 0 90px}
  .row .v{font-size:15px;font-weight:500;text-align:right;flex:1;word-break:break-word}
  form{margin-top:16px;text-align:left}
  label{display:block;font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin:12px 0 4px}
  input{width:100%;font-size:16px;padding:12px 14px;border:1.5px solid #dbe3ee;border-radius:12px;background:#fbfcfe;color:#0f172a}
  input:focus{outline:none;border-color:#1A3A6A}
  .ok{background:#E9F8EF;color:#0a6b3b;border-radius:12px;padding:12px 14px;margin-top:14px;font-size:14px;line-height:1.5}
  .err{color:#b3261e;font-size:13px;margin-top:8px;min-height:1em}
  .foot{text-align:center;margin-top:20px;color:#94a3b8;font-size:12px;line-height:1.6}.foot a{color:#1A3A6A;text-decoration:none;font-weight:600}
  .steps{text-align:left;font-size:13.5px;color:#334155;margin:14px 0 0;padding-left:18px;line-height:1.7}
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <img src="/assets/img/logo.png?v=20260925a" alt="" onerror="this.style.display='none'">
    <div><b><?= $e($company) ?></b><small>Split-pay · भाडा बाँडेर तिर्नुहोस् · किराया बाँटकर भरें</small></div>
  </div>

<?php if ($state === 'invalid'): ?>
  <div class="card">
    <div style="font-size:48px">🔗</div>
    <h1 style="font-size:20px;margin:6px 0">This payment link is not valid</h1>
    <p class="sub">यो लिंक मिलेन — टिकट बुक गर्ने साथीलाई फेरि पठाउन भन्नुहोस्।<br>Ask the person who booked to share the link again.</p>
  </div>
<?php elseif ($state === 'confirmed'): ?>
  <div class="card">
    <div style="font-size:48px">✅</div>
    <h1 style="font-size:20px;margin:6px 0">Ticket already confirmed</h1>
    <p class="sub">धन्यवाद! <?= $e($pnr) ?> को भुक्तानी पूरा भइसक्यो — यो हिस्सा तिर्नु पर्दैन।<br>Payment for <?= $e($pnr) ?> is complete — this share does not need paying.</p>
  </div>
<?php elseif ($state === 'closed'): ?>
  <div class="card">
    <div style="font-size:48px">⏱</div>
    <h1 style="font-size:20px;margin:6px 0">This booking is no longer active</h1>
    <p class="sub">यो बुकिङ रद्द वा समाप्त भयो — कृपया बुक गर्ने साथीसँग कुरा गर्नुहोस्।<br>The booking was cancelled or expired — please check with the person who booked.</p>
  </div>
<?php else: ?>
  <div class="card">
    <div class="pnr"><?= $e($pnr) ?> · share <?= $s ?> of <?= $nShares ?></div>
    <div class="amt"><?= $e(inr($amount)) ?></div>
    <p class="sub"><?= $leadName !== '' ? $e($leadName) . ' · ' : '' ?><?= $e($routeLn) ?><?= $dateLn !== '' ? ' · ' . $e($dateLn) : '' ?><br>
      तपाईंको हिस्सा UPI बाट तिर्नुहोस् · अपना हिस्सा UPI से भरें · Pay your share by UPI</p>

    <?php if ($state === 'claimed'): ?>
      <div class="ok">✓ Recorded as paid by <b><?= $e((string) $share['payer_name']) ?></b><?= !empty($share['utr']) ? ' · UTR ' . $e((string) $share['utr']) : '' ?><br>
        कार्यालयले भुक्तानी जाँचेपछि टिकट पुष्टि हुन्छ। · The office confirms the ticket once every share has arrived.</div>
    <?php endif; ?>

    <?php if ($upiUrl !== ''): ?>
      <?php if ($qr !== ''): ?><img class="qr" src="<?= $e($qr) ?>" alt="UPI QR"><?php endif; ?>
      <a class="cta o" href="<?= $e($upiUrl) ?>">📲 Pay <?= $e(inr($amount)) ?> with any UPI app</a>
      <div class="vpa">UPI ID: <b id="vpa"><?= $e($upiId) ?></b> · <a href="#" id="copyVpa" style="color:#1A3A6A;font-weight:700;text-decoration:none">Copy</a></div>
    <?php else: ?>
      <p class="sub" style="margin-top:12px">UPI details are not configured yet — please pay the organiser directly.</p>
    <?php endif; ?>

    <ol class="steps">
      <li>Pay exactly <b><?= $e(inr($amount)) ?></b> — ठ्याक्कै यति नै रकम।</li>
      <li>Copy the <b>UTR / transaction number</b> from your UPI app.</li>
      <li>Type it below so the office can match your payment · तल UTR लेख्नुहोस्।</li>
    </ol>

    <form id="claimForm" autocomplete="on">
      <label for="cName">Your name · तपाईंको नाम</label>
      <input id="cName" name="name" maxlength="120" required value="<?= $state === 'claimed' ? $e((string) $share['payer_name']) : '' ?>" autocomplete="name">
      <label for="cUtr">UTR / transaction number</label>
      <input id="cUtr" name="utr" maxlength="40" inputmode="text" required placeholder="e.g. 425612345678" value="<?= $state === 'claimed' ? $e((string) $share['utr']) : '' ?>">
      <label for="cPhone">Your mobile (optional) · मोबाइल</label>
      <input id="cPhone" name="phone" maxlength="15" inputmode="tel" autocomplete="tel">
      <div class="err" id="cErr"></div>
      <button class="cta" type="submit" id="cBtn">✅ I have paid · मैले तिरेँ · मैंने भर दिया</button>
    </form>
  </div>

  <div class="details">
    <div class="row"><span class="k">Ticket</span><span class="v pnr"><?= $e($pnr) ?></span></div>
    <?php if ($routeLn !== ''): ?><div class="row"><span class="k">Route</span><span class="v"><?= $e($routeLn) ?></span></div><?php endif; ?>
    <?php if ($dateLn !== ''): ?><div class="row"><span class="k">Date</span><span class="v"><?= $e($dateLn) ?></span></div><?php endif; ?>
    <div class="row"><span class="k">Your share</span><span class="v"><?= $e(inr($amount)) ?> (<?= $s ?>/<?= $nShares ?>)</span></div>
    <div class="row"><span class="k">Total fare</span><span class="v"><?= $e(inr((float) $booking['total_amount'])) ?></span></div>
  </div>
<?php endif; ?>

  <div class="foot">
    <?= $e($company) ?> · <a href="tel:<?= $e(preg_replace('/\D/', '', $phone)) ?>"><?= $e($phone) ?></a>
    <?php if ($wa !== ''): ?> · <a href="https://wa.me/<?= $e($wa) ?>">WhatsApp</a><?php endif; ?><br>
    <a href="/">shreehariglobal.in</a>
  </div>
</div>
<?php if ($state === 'open' || $state === 'claimed'): ?>
<script>
(function () {
  var f = document.getElementById('claimForm'), err = document.getElementById('cErr'), btn = document.getElementById('cBtn');
  var copy = document.getElementById('copyVpa'), vpa = document.getElementById('vpa');
  if (copy && vpa) copy.addEventListener('click', function (e) {
    e.preventDefault();
    var v = vpa.textContent;
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(v).then(function () { copy.textContent = 'Copied ✓'; });
    else { window.prompt('UPI ID', v); }
  });
  if (!f) return;
  f.addEventListener('submit', function (e) {
    e.preventDefault();
    err.textContent = '';
    var body = { action: 'claim', pnr: <?= json_encode($pnr) ?>, s: <?= (int) $s ?>, k: <?= json_encode($k) ?>,
      name: document.getElementById('cName').value.trim(), utr: document.getElementById('cUtr').value.trim(), phone: document.getElementById('cPhone').value.trim() };
    if (body.name.length < 2) { err.textContent = 'Please enter your name · नाम लेख्नुहोस्'; return; }
    if (body.utr.replace(/[^A-Za-z0-9-]/g, '').length < 6) { err.textContent = 'Enter the UTR / transaction number · UTR लेख्नुहोस्'; return; }
    btn.disabled = true; btn.textContent = 'Saving… · सुरक्षित गर्दै…';
    fetch('/api/split-pay.php', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode($csrf) ?> }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok && j && j.ok, j: j }; }); })
      .then(function (res) {
        if (!res.ok) { throw new Error((res.j && res.j.error) || 'Could not save'); }
        var box = document.createElement('div'); box.className = 'ok';
        box.innerHTML = '✓ धन्यवाद! Recorded — the office will verify your payment shortly.<br>कार्यालयले जाँचेपछि टिकट पुष्टि हुन्छ।';
        f.parentNode.insertBefore(box, f); f.style.display = 'none';
        window.scrollTo({ top: 0, behavior: 'smooth' });
      })
      .catch(function (ex) { err.textContent = ex.message || 'Could not save — try again'; btn.disabled = false; btn.textContent = '✅ I have paid · मैले तिरेँ'; });
  });
})();
</script>
<?php endif; ?>
</body>
</html>
