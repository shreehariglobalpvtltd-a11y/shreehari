<?php
/**
 * =====================================================================
 *  smartpay.php?pnr=SHG-2026-01234 — the Online payment page (15 Oct
 *  2026, SmartBook v5 §7). The SmartBook card's Online button holds the
 *  berths (smartbook_online_hold_min, default 15) and lands here:
 *
 *    amount + PNR, a countdown to the hold's end, the company UPI QR with
 *    the amount and PNR pre-filled, Copy UPI ID, Open UPI app, the eSewa
 *    ID for Nepal, then the UTR / screenshot (api/payment.php - the office
 *    verifies it) and a live status: "Payment verify hudai chha". When the
 *    office confirms, the page opens the ticket on the home page
 *    (/#/?ticket=PNR). The passenger may leave: My Bookings has it.
 *
 *  Gateways plug in through includes/payadapter.php (no change here).
 *  Who may see it: the browser that booked it, the signed-in owner, or
 *  the keyed ticket link's k - the same rule api/payment.php applies.
 *  Nothing on this page confirms a booking.
 * =====================================================================
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/sitefacts.php';
require_once INCLUDE_PATH . '/payadapter.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$e   = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$pnr = strtoupper(trim((string) ($_GET['pnr'] ?? '')));
if (!Security::isValidPnr($pnr)) {
    http_response_code(400);
    exit('Invalid booking reference.');
}
Security::requireRateLimit('smartpay_view', Security::clientIp(), 60, 300);

$booking = BookingService::findByPnr($pnr);
$key     = Security::clean((string) ($_GET['k'] ?? ''), 64);
$me      = Auth::user();
$owns    = $booking !== null && (
    in_array($pnr, Auth::ownPnrs(), true)
    || ($me !== null && (int) ($booking['user_id'] ?? 0) > 0 && (int) $booking['user_id'] === (int) ($me['id'] ?? 0))
    || ($me !== null && !isPlaceholderPhone((string) $booking['contact_phone']) && normalisePhone((string) ($me['phone'] ?? '')) === normalisePhone((string) $booking['contact_phone']))
    || ($key !== '' && Ticket::checkDownloadToken($pnr, $key))
);
if ($booking !== null && (string) $booking['status'] === 'confirmed' && $owns) {
    // the browser that booked opens the ticket on the home page; a keyed link (an agent's customer) gets the ticket itself
    $dest = in_array($pnr, Auth::ownPnrs(), true) ? '/#/?ticket=' . rawurlencode($pnr) : Ticket::imageUrl($pnr) . '&view=1';
    header('Location: ' . $dest, true, 302);
    exit;
}

$F       = SiteFacts::all();
$state   = 'pay';
$due     = 0.0;
$methods = [];
$leg     = null;
$left    = 0;
if ($booking === null || !$owns) {
    $state = 'who';
} else {
    $st = (string) $booking['status'];
    if (in_array($st, ['cancelled', 'expired', 'rejected'], true)) {
        $state = $st === 'rejected' ? 'rejected' : 'over';
    }
    $due = PaymentAdapters::due($booking);
    $leg = Database::fetch(
        "SELECT l.travel_date, l.boarding_stop, l.boarding_time, r.from_city, r.to_city FROM booking_legs l
           JOIN schedules s ON s.id = l.schedule_id JOIN routes r ON r.id = s.route_id
          WHERE l.booking_id = :b ORDER BY l.id LIMIT 1",
        ['b' => (int) $booking['id']]
    );
    $exp  = strtotime((string) ($booking['expires_at'] ?? ''));
    $left = $exp !== false ? max(0, $exp - time()) : 0;
    $proof = SmartPay::hasProof((int) $booking['id']);
    // While the booking is still pending the proof form stays open, even after
    // the hold's clock ran out (cron/expire.php has the last word, and a
    // passenger who paid at minute 14 must still be able to send the UTR).
    if ($state === 'pay' && $proof) {
        $state = 'verifying';
    }
    foreach (PaymentAdapters::all() as $a) {
        $methods[] = ['key' => $a->key(), 'label' => $a->label(), 'data' => $a->start($booking, $due)];
    }
}
$csrf  = Security::csrfToken();
$npr   = round($due * (float) $F['npr']);
$brand = (string) $F['brand']['name'];
$help  = (string) $F['support']['phone'];
$helpT = (string) $F['support']['tel'];
?><!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Pay <?= $e($pnr) ?> — <?= $e($brand) ?></title>
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
<script>try{var t=localStorage.getItem('shg:theme');if(t==='dark'||t==='amoled'||(!t&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.setAttribute('data-theme','dark');}catch(e){}</script>
<style>
:root{--bg:#edf3fa;--panel:#fff;--text:#122445;--muted:#637491;--line:#e0e8f2;--primary:#185bed;--grad:linear-gradient(120deg,#216af8,#0d47c6);--ok:#0b9e71;--gold:#f0b54a;--err:#cd4154;--chip:#f3f7fc}
:root[data-theme="dark"]{--bg:#060b15;--panel:#101928;--text:#eff5ff;--muted:#9caec9;--line:#293750;--chip:#16233a}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.45 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,"Noto Sans Devanagari",sans-serif}
.top{background:#071a40;color:#fff;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px}
.top b{font-weight:800;letter-spacing:.02em}.top small{display:block;font-size:11px;opacity:.75;letter-spacing:.08em}
.lang{display:flex;gap:4px}.lang button{border:1px solid rgba(255,255,255,.3);background:transparent;color:#fff;border-radius:8px;min-width:40px;min-height:34px;font:inherit;font-size:12px;cursor:pointer}
.lang button.on{background:#fff;color:#071a40}
main{max-width:560px;margin:0 auto;padding:14px 16px 40px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:23px;box-shadow:0 18px 55px rgba(21,52,105,.07);padding:16px;margin:0 0 12px}
.amt{display:flex;justify-content:space-between;align-items:flex-end;gap:10px}
.amt b{font-size:30px;font-weight:800}.amt small{color:var(--muted);display:block}
.pnr{font-weight:800;color:var(--primary);letter-spacing:.04em}
.timer{margin-top:10px;padding:10px 12px;border-radius:12px;background:#fff4e5;color:#7a4b00;font-weight:700;display:flex;justify-content:space-between}
:root[data-theme="dark"] .timer{background:#33240d;color:#ffd79a}
.tabs{display:flex;gap:6px;background:var(--chip);border-radius:11px;padding:4px;margin:0 0 12px}
.tabs button{flex:1;min-height:42px;border:0;border-radius:9px;background:transparent;color:var(--muted);font:inherit;font-weight:700;cursor:pointer}
.tabs button.on{background:var(--panel);color:var(--primary);box-shadow:0 2px 10px rgba(24,91,237,.14)}
.pane[hidden]{display:none}
.qr{display:block;margin:0 auto 10px;width:min(260px,72vw);height:auto;border-radius:14px;border:1px solid var(--line);background:#fff;padding:8px}
.row{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px dashed var(--line);font-size:14px}
.row span{color:var(--muted)}.row code{font-size:14px;word-break:break-all}
.btn{display:flex;align-items:center;justify-content:center;width:100%;min-height:50px;border:0;border-radius:12px;background:var(--grad);color:#fff;font:inherit;font-weight:800;text-decoration:none;cursor:pointer;margin-top:10px}
.btn.alt{background:var(--chip);color:var(--text);border:1px solid var(--line)}
.btn.ok{background:linear-gradient(120deg,#12b886,#0b9e71)}
label{display:block;font-size:12px;font-weight:700;color:var(--muted);margin:10px 0 4px;text-transform:uppercase;letter-spacing:.02em}
input[type=text],input[type=tel]{width:100%;height:46px;border:1px solid var(--line);border-radius:11px;background:var(--panel);color:var(--text);font:inherit;padding:0 12px}
input[type=file]{width:100%;font:inherit;color:var(--muted)}
.note{font-size:13px;color:var(--muted)}
.state{text-align:center}.state .big{font-size:44px}
.err{color:var(--err);font-weight:700;font-size:13px;min-height:18px}
.steps{display:flex;gap:6px;font-size:12px;color:var(--muted);margin:10px 0 0}.steps i{flex:1;height:4px;border-radius:4px;background:var(--line)}.steps i.on{background:var(--ok)}
a{color:var(--primary)}
</style>
</head>
<body>
<div class="top"><div><b><?= $e(strtoupper($brand)) ?></b><small>INDIA ⇄ NEPAL · SMARTBOOK</small></div>
  <div class="lang" role="group" aria-label="Language"><button type="button" data-l="ne">ने</button><button type="button" data-l="hi">हि</button><button type="button" data-l="en">EN</button></div></div>
<main>
<?php if ($state === 'who'): ?>
  <div class="card state"><div class="big">🔒</div>
    <p data-t="who">Open this page from the phone that made the booking, or find it in My Bookings with your mobile number.</p>
    <a class="btn" href="/#/my" data-t="myB">My Bookings</a></div>
<?php else: ?>
  <div class="card">
    <div class="amt"><div><small data-t="pay">Amount to pay</small><b>₹<?= $e(number_format($due)) ?></b><small>≈ NPR <?= $e(number_format($npr)) ?></small></div>
      <div style="text-align:right"><small>PNR</small><div class="pnr"><?= $e($pnr) ?></div></div></div>
    <?php if ($leg !== null): ?>
      <div class="note" style="margin-top:6px"><?= $e($leg['from_city'] . ' → ' . $leg['to_city']) ?> · <?= $e(formatDate((string) $leg['travel_date'])) ?> · <?= $e((string) Boarding::stopDisplay((string) $leg['boarding_stop'])['name']) ?><?= $leg['boarding_time'] ? ' ' . $e(substr((string) $leg['boarding_time'], 0, 5)) : '' ?></div>
    <?php endif; ?>
    <?php if ($state === 'pay' && $left > 0): ?>
      <div class="timer" id="timer" data-left="<?= (int) $left ?>"><span data-t="held">Berths held for</span><span id="clock">--:--</span></div>
    <?php elseif ($state === 'pay'): ?>
      <div class="timer"><span data-t="late">Hold time is over — if you already paid, send the UTR now.</span></div>
    <?php endif; ?>
    <div class="steps" aria-hidden="true"><i class="on"></i><i class="<?= $state !== 'pay' ? 'on' : '' ?>"></i><i></i></div>
  </div>

  <?php if ($state === 'pay'): ?>
  <div class="card">
    <?php $hasUpi = false; $hasEsewa = false; $gw = [];
      foreach ($methods as $m) {
          if ($m['data']['kind'] === 'qr') { $hasUpi = $m['data']['upi'] !== null; $hasEsewa = $m['data']['esewa'] !== null; $qr = $m['data']; }
          else { $gw[] = $m; }
      } ?>
    <div class="tabs" role="tablist">
      <?php if ($hasUpi): ?><button type="button" class="on" data-pane="upi" role="tab">🇮🇳 UPI</button><?php endif; ?>
      <?php if ($hasEsewa): ?><button type="button" class="<?= $hasUpi ? '' : 'on' ?>" data-pane="esewa" role="tab">🇳🇵 eSewa</button><?php endif; ?>
      <?php if ($gw !== []): ?><button type="button" data-pane="gw" role="tab" data-t="card">Card / Bank</button><?php endif; ?>
    </div>
    <?php if ($hasUpi): ?>
    <div class="pane" id="p-upi">
      <img class="qr" src="<?= $e($qr['upi']['qr']) ?>" alt="UPI QR for ₹<?= $e(number_format($due)) ?>" width="260" height="260">
      <p class="note" style="text-align:center" data-t="scan">Scan with GPay / PhonePe / Paytm / BHIM — amount and PNR are filled in.</p>
      <div class="row"><span>UPI ID</span><code id="upiId"><?= $e($qr['upi']['id']) ?></code></div>
      <div class="row"><span data-t="payee">Payee</span><b><?= $e($qr['upi']['name']) ?></b></div>
      <button type="button" class="btn alt" data-copy="upiId" data-t="copyUpi">Copy UPI ID</button>
      <a class="btn" href="<?= $e($qr['upi']['link']) ?>" data-t="openUpi">Open UPI app</a>
    </div>
    <?php endif; ?>
    <?php if ($hasEsewa): ?>
    <div class="pane" id="p-esewa" <?= $hasUpi ? 'hidden' : '' ?>>
      <?php if ($qr['esewa']['qr'] !== ''): ?><img class="qr" src="<?= $e($qr['esewa']['qr']) ?>" alt="eSewa QR" width="260" height="260"><?php endif; ?>
      <div class="row"><span>eSewa ID</span><code id="esewaId"><?= $e($qr['esewa']['id']) ?></code></div>
      <div class="row"><span data-t="payee">Payee</span><b><?= $e($qr['esewa']['name']) ?></b></div>
      <div class="row"><span data-t="amtNpr">Amount (NPR)</span><b>NPR <?= $e(number_format((float) $qr['esewa']['npr'])) ?></b></div>
      <p class="note" data-t="esewaNote">Send this amount from the eSewa app with your PNR in the remarks.</p>
      <button type="button" class="btn alt" data-copy="esewaId" data-t="copyEsewa">Copy eSewa ID</button>
    </div>
    <?php endif; ?>
    <?php if ($gw !== []): ?>
    <div class="pane" id="p-gw" hidden>
      <?php foreach ($gw as $g): ?>
        <a class="btn" href="<?= $e($g['data']['url']) ?>"><?= $e($g['label']['en']) ?></a>
      <?php endforeach; ?>
      <p class="note" data-t="gwNote">You return here after the bank page. The office confirms the ticket.</p>
    </div>
    <?php endif; ?>
  </div>

  <form class="card" id="proof" enctype="multipart/form-data" autocomplete="off">
    <b data-t="paid">Paid? Tell us</b>
    <label for="utr" data-t="utr">UTR / transaction number</label>
    <input type="text" id="utr" name="utr" inputmode="text" maxlength="40" placeholder="e.g. 4321 8765 1234">
    <label for="shot" data-t="shot">or a screenshot</label>
    <input type="file" id="shot" name="screenshot" accept="image/*">
    <input type="hidden" name="pnr" value="<?= $e($pnr) ?>">
    <input type="hidden" name="k" value="<?= $e($key) ?>">
    <input type="hidden" name="amount" value="<?= $e($due) ?>">
    <input type="hidden" name="method" id="method" value="upi">
    <input type="hidden" name="<?= $e(CSRF_TOKEN_NAME) ?>" value="<?= $e($csrf) ?>">
    <p class="err" id="err" role="alert"></p>
    <button type="submit" class="btn ok" data-t="send">Send for verification</button>
  </form>
  <?php elseif ($state === 'verifying'): ?>
  <div class="card state" id="verifying"><div class="big">⏳</div>
    <p><b data-t="verifying">Payment verify hudai chha</b></p>
    <p class="note"><span data-t="verifyNote">The office checks it, usually within</span> <?= $e(Settings::getString('verify_time_text', '1–3 minutes')) ?>. <span data-t="leave">You can leave this page — the ticket comes on WhatsApp and in My Bookings.</span></p>
    <a class="btn alt" href="/#/my" data-t="myB">My Bookings</a></div>
  <?php elseif ($state === 'rejected'): ?>
  <div class="card state"><div class="big">⚠️</div><p data-t="rejected">The payment could not be verified. Please call the office.</p>
    <a class="btn" href="tel:+<?= $e($helpT) ?>">📞 <?= $e($help) ?></a></div>
  <?php else: ?>
  <div class="card state"><div class="big">⌛</div><p data-t="over">Time is over — the berths went back on sale. Your details are kept on the booking card.</p>
    <a class="btn" href="/#/" data-t="again">Book again</a></div>
  <?php endif; ?>
  <p class="note" style="text-align:center"><span data-t="help">Help</span>: <a href="tel:+<?= $e($helpT) ?>"><?= $e($help) ?></a> · <a href="/#/" data-t="home">Home</a></p>
<?php endif; ?>
</main>
<script>
(function () {
  var W = {
    en: { late: 'Hold time is over — if you already paid, send the UTR now.' }, // the rest of the English is the markup
    hi: { who: 'यह पेज बुकिंग करने वाले फ़ोन से खोलें, या मोबाइल से My Bookings में देखें।', myB: 'My Bookings', pay: 'भुगतान राशि', held: 'बर्थ रखी गई है', card: 'कार्ड / बैंक',
      scan: 'GPay / PhonePe / Paytm / BHIM से स्कैन करें — राशि और PNR भरे हुए हैं।', payee: 'प्राप्तकर्ता', copyUpi: 'UPI ID कॉपी करें', openUpi: 'UPI ऐप खोलें',
      amtNpr: 'राशि (NPR)', esewaNote: 'eSewa ऐप से यह राशि भेजें, remarks में PNR लिखें।', copyEsewa: 'eSewa ID कॉपी करें', gwNote: 'बैंक पेज के बाद आप यहीं लौटेंगे। टिकट ऑफिस पक्का करता है।',
      paid: 'भुगतान किया? बताइए', utr: 'UTR / ट्रांज़ैक्शन नंबर', shot: 'या स्क्रीनशॉट', send: 'जाँच के लिए भेजें', verifying: 'भुगतान की जाँच हो रही है',
      verifyNote: 'ऑफिस जाँचता है, आमतौर पर', leave: 'आप यह पेज छोड़ सकते हैं — टिकट WhatsApp और My Bookings में आएगा।', rejected: 'भुगतान सत्यापित नहीं हुआ। कृपया ऑफिस को फ़ोन करें।',
      over: 'समय खत्म — बर्थ फिर बिक्री में। आपकी जानकारी बुकिंग कार्ड में है।', late: 'होल्ड का समय खत्म — भुगतान कर दिया हो तो अभी UTR भेजें।', again: 'फिर बुक करें', help: 'मदद', home: 'होम', copied: 'कॉपी हुआ', need: 'UTR लिखें या स्क्रीनशॉट जोड़ें' },
    ne: { who: 'यो पेज बुकिङ गरेको फोनबाट खोल्नुहोस्, वा मोबाइलले My Bookings मा हेर्नुहोस्।', myB: 'My Bookings', pay: 'तिर्नुपर्ने रकम', held: 'बर्थ राखिएको छ', card: 'कार्ड / बैंक',
      scan: 'GPay / PhonePe / Paytm / BHIM ले स्क्यान गर्नुहोस् — रकम र PNR भरिएको छ।', payee: 'पाउने', copyUpi: 'UPI ID कपी', openUpi: 'UPI एप खोल्नुहोस्',
      amtNpr: 'रकम (NPR)', esewaNote: 'eSewa एपबाट यो रकम पठाउनुहोस्, remarks मा PNR लेख्नुहोस्।', copyEsewa: 'eSewa ID कपी', gwNote: 'बैंक पेजपछि यहीँ फर्किनुहुन्छ। टिकट अफिसले पक्का गर्छ।',
      paid: 'तिर्नुभयो? भन्नुहोस्', utr: 'UTR / कारोबार नम्बर', shot: 'वा स्क्रिनसट', send: 'जाँचका लागि पठाउनुहोस्', verifying: 'भुक्तानी जाँच हुँदैछ',
      verifyNote: 'अफिसले जाँच्छ, प्रायः', leave: 'यो पेज छोड्न सक्नुहुन्छ — टिकट WhatsApp र My Bookings मा आउँछ।', rejected: 'भुक्तानी पुष्टि भएन। कृपया अफिसमा फोन गर्नुहोस्।',
      over: 'समय सकियो — बर्थ फेरि बिक्रीमा। तपाईंको विवरण बुकिङ कार्डमा छ।', late: 'होल्ड समय सकियो — तिरिसक्नुभएको भए अहिले UTR पठाउनुहोस्।', again: 'फेरि बुक गर्नुहोस्', help: 'सहयोग', home: 'होम', copied: 'कपी भयो', need: 'UTR लेख्नुहोस् वा स्क्रिनसट थप्नुहोस्' }
  };
  var EN = {}; document.querySelectorAll('[data-t]').forEach(function (el) { EN[el.getAttribute('data-t')] = el.textContent; });
  var lang = 'ne'; try { lang = localStorage.getItem('shg:lang') || 'ne'; } catch (e) {}
  if (!W[lang]) lang = 'en';
  function tr(k) { return (W[lang] && W[lang][k]) || EN[k] || k; }
  function apply() {
    document.documentElement.lang = lang;
    document.querySelectorAll('[data-t]').forEach(function (el) { el.textContent = tr(el.getAttribute('data-t')); });
    document.querySelectorAll('.lang button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-l') === lang); });
  }
  document.querySelectorAll('.lang button').forEach(function (b) { b.addEventListener('click', function () { lang = b.getAttribute('data-l'); try { localStorage.setItem('shg:lang', lang); } catch (e) {} apply(); }); });
  apply();

  document.querySelectorAll('.tabs button').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.tabs button').forEach(function (x) { x.classList.toggle('on', x === b); });
      document.querySelectorAll('.pane').forEach(function (p) { p.hidden = p.id !== 'p-' + b.getAttribute('data-pane'); });
      var m = document.getElementById('method'); if (m) m.value = b.getAttribute('data-pane') === 'esewa' ? 'esewa' : 'upi';
    });
  });
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = document.getElementById(b.getAttribute('data-copy')).textContent;
      try { navigator.clipboard.writeText(t).then(function () { b.textContent = tr('copied') + ' ✓'; }); } catch (e) {}
    });
  });

  var timer = document.getElementById('timer');
  if (timer) {
    var end = Date.now() + (+timer.getAttribute('data-left') || 0) * 1000;
    var tick = function () {
      var s = Math.max(0, Math.round((end - Date.now()) / 1000));
      document.getElementById('clock').textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
      if (s <= 0) { timer.innerHTML = '<span>' + tr('late') + '</span>'; return; }
      setTimeout(tick, 1000);
    };
    tick();
  }

  var form = document.getElementById('proof');
  if (form) form.addEventListener('submit', function (e) {
    e.preventDefault();
    var err = document.getElementById('err'); err.textContent = '';
    if (!form.utr.value.trim() && !(form.screenshot.files && form.screenshot.files.length)) { err.textContent = tr('need'); return; }
    var btn = form.querySelector('button[type=submit]'); btn.disabled = true;
    var fd = new FormData(form);
    fetch('/api/payment.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': fd.get('<?= $e(CSRF_TOKEN_NAME) ?>') }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.ok) location.reload(); else { err.textContent = (j && j.error) || 'Error'; btn.disabled = false; } })
      .catch(function () { err.textContent = 'Network error'; btn.disabled = false; });
  });

  // While the office verifies: watch the status; confirmed → the ticket on the home page.
  if (document.getElementById('verifying') || timer) {
    var poll = function () {
      fetch('/api/smartbook.php', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode($csrf) ?> },
        body: JSON.stringify({ action: 'status', pnr: <?= json_encode($pnr) ?>, k: <?= json_encode($key) ?> }) })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var s = j && j.ok && j.data ? j.data.status : '';
          if (s === 'confirmed') { location.href = '/#/?ticket=' + encodeURIComponent(<?= json_encode($pnr) ?>); return; }
          if (s === 'expired' || s === 'cancelled' || s === 'rejected') { location.reload(); return; }
          setTimeout(poll, 10000);
        }).catch(function () { setTimeout(poll, 15000); });
    };
    setTimeout(poll, 8000);
  }
})();
</script>
</body>
</html>
