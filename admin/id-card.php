<?php
/**
 * admin/id-card.php?ref=STF-000005 | AGR-000035 | DRV-000001 — Digital Identity & Visiting Card.
 *
 * Implements:
 *  - High-res CR80 portrait ID Card (54 × 85.6 mm) & Landscape Visiting Card (89 × 51 mm)
 *  - Front & back PNG and PDF downloads
 *  - Official verification QR vs Personal WhatsApp QR (with separate disclaimer)
 *  - Automated WhatsApp Cloud API card delivery tracker & Resend button
 *  - Manual WhatsApp Fallback modal (Web Share API + wa.me prefill + download fallback)
 *  - Photo Studio replacement with instant card regeneration
 *  - Passkeys & Verification logs
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/idcard.php';
require_once INCLUDE_PATH . '/carddelivery.php';
require_once INCLUDE_PATH . '/passkeys.php';
$admin = admin_boot('');

$deny = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
};
if (!(Auth::isSuperadmin() || (string) ($admin['role'] ?? '') === 'manager' || Auth::can('staff.manage'))) {
    $deny(403, 'Only a super-admin or a manager may print ID cards.');
}
$ref = strtoupper(Security::clean((string) ($_GET['ref'] ?? ''), 20));
$p   = IdCard::person($ref);
if ($p === null) {
    $deny(404, 'No card for ' . $ref . ' — only approved staff, agents, partners and drivers get one.');
}
$h = static fn($v): string => Security::e((string) ($v ?? ''));

// Passkey owner resolution
$owner = null;
if ($p['kind'] === 'staff') {
    $owner = ['staff', (int) substr($p['ref'], 4)];
} elseif ($p['kind'] === 'driver') {
    $owner = ['staff', (int) substr($p['ref'], 4)];
} else {
    $aid = (int) Database::scalar('SELECT admin_id FROM agent_applications WHERE ref = :r LIMIT 1', ['r' => $p['ref']], 0);
    $owner = $aid > 0 ? ['admin', $aid] : null;
}

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'This page was open too long — please try again.'];
    } elseif ((string) ($_POST['action'] ?? '') === 'passkey_remove' && $owner !== null) {
        $ok = Passkeys::remove($owner[0], $owner[1], (int) ($_POST['id'] ?? 0), 'the office (' . ($admin['username'] ?? '') . ')');
        $flash = $ok ? ['ok', 'Device login removed — the person signs in with password.'] : ['bad', 'Nothing removed.'];
    } elseif ((string) ($_POST['action'] ?? '') === 'send_whatsapp') {
        $res = CardDelivery::send($p['ref'], !empty($_POST['resend']));
        $flash = $res['ok'] ? ['ok', $res['detail']] : ['bad', $res['detail']];
    }
}

$side = (string) ($_GET['png'] ?? '');
$vis  = (string) ($_GET['v'] ?? '');
$pdfW = (string) ($_GET['pdf'] ?? '');
if (in_array($pdfW, ['id', 'visiting', 'all'], true)) {
    $bytes = IdCard::pdf($p, $pdfW);
    Logger::audit('idcard.print', $p['kind'], $p['ref'], null, ['pdf' => $pdfW], 'Card PDF made');
    header_remove('Set-Cookie');
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="SHG-' . ($pdfW === 'visiting' ? 'VisitingCard' : ($pdfW === 'id' ? 'ID' : 'Cards')) . '-' . $p['ref'] . '.pdf"');
    echo $bytes;
    exit;
}
$which = $side !== '' ? $side : $vis;
if (in_array($which, ['front', 'back', 'sheet'], true)) {
    $png = $vis !== '' ? IdCard::visitingPng($p, $which) : IdCard::png($p, $which);
    if ($which === 'sheet' || isset($_GET['dl'])) {
        Logger::audit('idcard.print', $p['kind'], $p['ref'], null, ['side' => $which, 'card' => $vis !== '' ? 'visiting' : 'id'], 'Card made');
    }
    header_remove('Set-Cookie');
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if (isset($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="SHG-' . ($vis !== '' ? 'VisitingCard' : 'ID') . '-' . $p['ref'] . '-' . $which . '.png"');
    }
    echo $png;
    exit;
}

$q = static fn(array $x): string => 'id-card.php?' . http_build_query(['ref' => $p['ref']] + $x);
$back = 'team.php?tab=members';
$keys = $owner !== null ? Passkeys::listFor($owner[0], $owner[1]) : [];
$delivery = CardDelivery::getDelivery($p['ref']);
$manualShare = CardDelivery::manualFallback($p['ref']);

$log = [];
try {
    $log = Database::fetchAll(
        "SELECT action, actor_name, detail, new_value, created_at FROM audit_logs
          WHERE entity_id = :r AND action IN ('idcard.verify','idcard.print','idcard.self','team.login','team.login.passkey','team.passkey.add','team.passkey.remove','team.agreement.sign','card.whatsapp_sent','photo.replaced')
          ORDER BY id DESC LIMIT 40", ['r' => $p['ref']]);
} catch (Throwable $e) {
    $log = [];
}
$verify = IdCard::verifyUrl($p['ref']);
$waPersonal = IdCard::waQrUrl($p);
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="csrf-token" content="<?= $h(Security::csrfToken()) ?>">
<title>Digital ID <?= $h($p['ref']) ?> · <?= $h($p['name']) ?> | S Hari Global</title>
<style>
:root{--royal:#0a2a8f;--royal2:#1f4fe0;--orange:#f07a1e;--bg:#eef3fc;--card:#fff;--line:#d8e1f3;--ink:#16233c;--mut:#5c6b85;--ok:#0f9d6b;--bad:#d93025;--warn:#b26a00}
body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,"Noto Sans Devanagari",sans-serif;background:var(--bg);color:var(--ink)}
.bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:14px 18px;background:linear-gradient(135deg,var(--royal),var(--royal2));color:#fff}
.bar b{flex:1 1 240px;font-size:16px}
.bar a,.bar button{display:inline-flex;align-items:center;gap:6px;min-height:42px;padding:0 14px;border-radius:10px;border:0;font:inherit;font-weight:800;font-size:13.5px;text-decoration:none;cursor:pointer;background:#fff;color:var(--royal)}
.bar .pr{background:var(--orange);color:#fff}
.bar .pr:hover{background:#d4570c}
.wrap{max-width:1040px;margin:0 auto;padding:16px}
.box{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:18px;margin:0 0 16px;box-shadow:0 12px 30px -24px rgba(10,42,143,.45)}
.box h2{margin:0 0 12px;font-size:17px;display:flex;align-items:center;justify-content:space-between}
.cards{display:flex;flex-wrap:wrap;gap:24px;justify-content:center}
.cards figure{margin:0;text-align:center}
.cards img.id{width:54mm;height:85.6mm;border-radius:3mm;box-shadow:0 12px 32px -12px rgba(10,42,143,.5);background:#fff}
.cards img.vc{width:89mm;height:51mm;border-radius:2mm;box-shadow:0 12px 32px -12px rgba(10,42,143,.5);background:#fff}
.cards figcaption{font-size:12.5px;font-weight:700;color:var(--mut);margin-top:8px}
.dl{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px}
.dl a,.dl button{display:inline-flex;align-items:center;gap:6px;min-height:38px;padding:0 12px;border-radius:10px;border:1.5px solid #c9d2e1;font-weight:800;font-size:13px;text-decoration:none;color:var(--royal);background:#fff;cursor:pointer}
.dl a:hover,.dl button:hover{background:#eef4fc}
.dl .wa-act{background:#1faa59;color:#fff;border-color:#1faa59}
.dl .wa-act:hover{background:#178f49}
.delivery-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;font-size:12.5px;font-weight:800}
.delivery-pill.sent{background:rgba(31,79,224,.12);color:var(--royal2)}
.delivery-pill.delivered{background:rgba(15,157,107,.14);color:var(--ok)}
.delivery-pill.failed{background:rgba(217,48,37,.12);color:var(--bad)}
.delivery-pill.queued{background:rgba(178,106,0,.12);color:var(--warn)}
.note{font-size:13.5px;color:var(--mut);line-height:1.5;margin:10px 0 0}
.qrs{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;margin-top:12px}
.qr-item{padding:12px;border-radius:14px;background:#f8faff;border:1px solid #e0e8f7}
.qr-item b{display:block;margin-bottom:4px;font-size:14px}
.qr-item small{display:block;color:var(--mut);font-size:12px;margin-bottom:8px}
table{width:100%;border-collapse:collapse;font-size:13.5px}
td,th{padding:8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
th{color:var(--mut);font-weight:700;font-size:12px}
.tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11.5px;font-weight:800;background:#eef4fc;color:var(--royal)}
.tag.ok{background:#e6f5ea;color:#12652b}.tag.bad{background:#fde8e6;color:#b3261e}
.flash{padding:12px 14px;border-radius:12px;font-weight:700;margin:0 0 14px}
.flash.ok{background:#e6f5ea;color:#12652b;border:1px solid rgba(18,101,43,.3)}
.flash.bad{background:#fde8e6;color:#b3261e;border:1px solid rgba(179,38,30,.3)}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px}
.modal-box{background:#fff;border-radius:18px;max-width:500px;width:100%;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.4)}
@media screen and (min-width:900px){.cards img.id{width:70mm;height:111mm}.cards img.vc{width:120mm;height:68.8mm}}
@media print{@page{size:A4;margin:12mm}body{background:#fff}.bar,.note,.cards figcaption,.box.np,.dl,.flash,.qrs{display:none!important}.box{border:0;box-shadow:none;padding:0}.cards{padding:0;gap:8mm;justify-content:flex-start}.cards img.id{width:54mm;height:85.6mm;box-shadow:none;border:0.2mm solid #ccd}.cards img.vc{width:89mm;height:51mm;box-shadow:none;border:0.2mm solid #ccd}}
<?php if(!empty($p['studio'])): ?>
.cards img.id{width:<?= ($p['studio']['orient_id']??'')==='landscape'?'85.6mm':'54mm' ?>;height:auto;max-width:100%}.cards img.vc{width:<?= ($p['studio']['orient_visiting']??'')==='portrait'?'51mm':'89mm' ?>;height:auto;max-width:100%}
@media print{.cards img.id{width:<?= ($p['studio']['orient_id']??'')==='landscape'?'85.6mm':'54mm' ?>;height:auto}.cards img.vc{width:<?= ($p['studio']['orient_visiting']??'')==='portrait'?'51mm':'89mm' ?>;height:auto}}
<?php endif; ?>
</style>
<script src="/assets/js/photo-studio.js?v=20261025c"></script>
</head>
<body>
<div class="bar">
  <b>🪪 <?= $h($p['name']) ?> (<?= $h($p['ref']) ?>) · <?= $h($p['roleEn']) ?> · <?= $h($p['standing']) ?></b>
  <button class="pr" type="button" onclick="window.print()">🖨️ Print Real Size</button>
  <a href="<?= $h($q(['pdf' => 'all'])) ?>">📄 PDF (ID + Visiting)</a>
  <a href="<?= $h($verify) ?>" target="_blank" rel="noopener">🛡️ Verify Online</a>
  <a href="<?= $h($back) ?>">← Back to Team</a>
</div>

<div class="wrap">
  <?php if ($flash !== null): ?><div class="flash <?= $h($flash[0]) ?>"><?= $h($flash[1]) ?></div><?php endif; ?>

  <!-- Identity Card Section -->
  <div class="box">
    <h2>
      <span>🪪 Identity Card · परिचयपत्र (CR80 Portrait 54 × 85.6 mm)</span>
      <button type="button" class="tag" style="cursor:pointer;padding:6px 12px;font-size:12px" onclick="openPhotoStudio()">📷 Replace / Crop Photo</button>
    </h2>
    <div class="cards">
      <figure><img class="id" src="<?= $h($q(['png' => 'front'])) ?>" alt="Front ID"><figcaption>Front (Front side with Photo & Verify QR)</figcaption></figure>
      <figure><img class="id" src="<?= $h($q(['png' => 'back'])) ?>" alt="Back ID"><figcaption>Back (Contact & Personal WhatsApp QR)</figcaption></figure>
    </div>
    <div class="dl">
      <a href="<?= $h($q(['png' => 'front', 'dl' => 1])) ?>">⬇ Front PNG</a>
      <a href="<?= $h($q(['png' => 'back', 'dl' => 1])) ?>">⬇ Back PNG</a>
      <a href="<?= $h($q(['png' => 'sheet', 'dl' => 1])) ?>">⬇ Printable Sheet</a>
      <a href="<?= $h($q(['pdf' => 'id'])) ?>">📄 ID Card PDF</a>
    </div>
  </div>

  <!-- Visiting Card Section -->
  <div class="box">
    <h2>Visiting Card · भिजिटिङ कार्ड (Landscape 89 × 51 mm)</h2>
    <div class="cards">
      <figure><img class="vc" src="<?= $h($q(['v' => 'front'])) ?>" alt="Front Visiting"><figcaption>Front (Branded Gold / Identity)</figcaption></figure>
      <figure><img class="vc" src="<?= $h($q(['v' => 'back'])) ?>" alt="Back Visiting"><figcaption>Back (Dual QRs: Verification & Direct WhatsApp)</figcaption></figure>
    </div>
    <div class="dl">
      <a href="<?= $h($q(['v' => 'front', 'dl' => 1])) ?>">⬇ Front PNG</a>
      <a href="<?= $h($q(['v' => 'back', 'dl' => 1])) ?>">⬇ Back PNG</a>
      <a href="<?= $h($q(['pdf' => 'visiting'])) ?>">📄 Visiting Card PDF</a>
    </div>
  </div>

  <!-- WhatsApp Delivery Tracker & Two Distinct QRs -->
  <div class="box">
    <h2>
      <span>📲 WhatsApp Card Delivery & QR Destinations</span>
      <?php if ($delivery !== null): ?>
        <span class="delivery-pill <?= $h($delivery['status']) ?>">
          <?= match($delivery['status']) {
            'delivered' => '✅ Delivered (Webhook confirmed)',
            'read' => '✅ Read (Webhook confirmed)',
            'processing' => '⏳ Sending — no delivery confirmation',
            'unknown' => '⚠️ Outcome unknown — review provider before resend',
            'legacy' => 'Previous attempt — needs receipt review',
            'sent'      => '🚀 Sent via Cloud API (Pending receipt)',
            'failed'    => '❌ Send Failed',
            default     => '⏳ Queued'
          } ?>
        </span>
      <?php else: ?>
        <span class="delivery-pill queued">⚪ Not Dispatched Yet</span>
      <?php endif; ?>
    </h2>

    <p class="note">
      Mobile: <b>+<?= $h($p['waDigits'] ?: preg_replace('/\D/', '', (string) $p['phone'])) ?></b>
      <?php if ($delivery !== null && !empty($delivery['sent_at'])): ?> · Sent: <?= $h($delivery['sent_at']) ?><?php endif; ?>
      <?php if ($delivery !== null && !empty($delivery['delivered_at'])): ?> · Confirmed delivered: <?= $h($delivery['delivered_at']) ?><?php endif; ?>
      <?php if ($delivery !== null && !empty($delivery['error_message'])): ?><br><span style="color:var(--bad)">Reason: <?= $h($delivery['error_message']) ?></span><?php endif; ?>
    </p>

    <div class="dl" style="margin-top:12px">
      <form method="post" action="<?= $h($q([])) ?>" style="display:inline">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="send_whatsapp"><label><input type="checkbox" name="resend" value="1"> Explicit resend (may duplicate a previous accepted message)</label>
        <button type="submit" class="wa-act">💬 Auto-Send Cards via WhatsApp Cloud API</button>
      </form>
      <button type="button" onclick="openShareModal()">📤 Open WhatsApp / Share Cards (Manual Fallback)</button>
    </div>

    <!-- Two distinct QRs breakdown -->
    <div class="qrs">
      <div class="qr-item">
        <b>🛡️ 1. Official Verification QR (Front & Back)</b>
        <small>URL: <a href="<?= $h($verify) ?>" target="_blank"><?= $h($verify) ?></a></small>
        <p style="margin:0;font-size:12.5px;color:var(--ink)">
          Scanned by passengers, border security or office staff. Checks cryptographic HMAC token and confirms active standing in company database.
        </p>
      </div>
      <div class="qr-item">
        <b>💬 2. Personal WhatsApp QR (Back only)</b>
        <small>URL: <a href="<?= $h($waPersonal) ?>" target="_blank"><?= $h($waPersonal) ?></a></small>
        <p style="margin:0;font-size:12.5px;color:var(--ink)">
          Direct personal chat with <b><?= $h($p['name']) ?></b>. Contains mandatory disclaimer: <i>"Personal WhatsApp · सम्पर्क मात्र — Does not verify identity."</i>
        </p>
      </div>
    </div>
  </div>

  <!-- Device Passkeys -->
  <div class="box np">
    <h2>Device Logins · Passkeys (Face ID / Fingerprint)</h2>
    <?php if ($owner === null): ?><p class="note" style="padding:16px">No login account is linked to this card yet.</p>
    <?php elseif ($keys === []): ?><p class="note" style="padding:16px">None yet. The team member adds a device via /team/ portal. Only the cryptographic public key is stored — never raw biometric templates.</p>
    <?php else: ?>
    <table><tr><th>Device</th><th>Added</th><th>Last used</th><th></th></tr>
    <?php foreach ($keys as $k): ?>
      <tr>
        <td><?= $h($k['label']) ?> <span class="tag"><?= $h($k['transports'] ?: 'platform') ?></span></td>
        <td><?= $h($k['created_at']) ?></td>
        <td><?= $h($k['last_used_at'] ?: '—') ?></td>
        <td>
          <form method="post" action="<?= $h($q([])) ?>" onsubmit="return confirm('Forget this device?')">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="passkey_remove">
            <input type="hidden" name="id" value="<?= (int) $k['id'] ?>">
            <button class="rm" type="submit">Forget Device</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <!-- Verification Log -->
  <div class="box np">
    <h2>Verification & Audit Log · जाँच तथा गतिविधि अभिलेख</h2>
    <?php if ($log === []): ?><p class="note" style="padding:16px">No activity recorded yet for this card.</p>
    <?php else: ?>
    <table><tr><th>Timestamp</th><th>Action</th><th>Actor</th><th>Detail</th></tr>
    <?php foreach ($log as $r):
        $nv = json_decode((string) ($r['new_value'] ?? ''), true);
        $lbl = match ((string) $r['action']) {
            'idcard.verify' => isset($nv['valid']) && !$nv['valid'] ? '<span class="tag bad">QR scanned · Invalid</span>' : '<span class="tag ok">QR scanned · Verified</span>',
            'idcard.print' => '<span class="tag">Card Printed</span>',
            'card.whatsapp_sent' => '<span class="tag ok">Cards WhatsApp Dispatched</span>',
            'photo.replaced' => '<span class="tag ok">Photo Replaced</span>',
            default => '<span class="tag">' . $h($r['action']) . '</span>',
        }; ?>
      <tr>
        <td><?= $h($r['created_at']) ?></td>
        <td><?= $lbl ?></td>
        <td><?= $h($r['actor_name'] ?: 'Public / Automated') ?></td>
        <td><?= $h(is_array($nv) ? json_encode($nv, JSON_UNESCAPED_UNICODE) : (string) ($r['detail'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- Manual WhatsApp Share Modal -->
<div class="modal-overlay" id="shareModal" onclick="if(event.target===this)closeShareModal()">
  <div class="modal-box">
    <h3 style="margin:0 0 10px;font-size:18px">📤 Share Cards / Manual WhatsApp</h3>
    <p style="font-size:13.5px;color:var(--mut);line-height:1.5;margin-bottom:12px">
      A <code>wa.me</code> link cannot automatically attach files due to WhatsApp security restrictions. You can share natively using the Web Share API (which attaches the files), or send the prefilled message and attach the downloaded card files.
    </p>

    <div style="background:#f4f7fc;padding:12px;border-radius:12px;font-size:13px;white-space:pre-wrap;margin-bottom:14px;max-height:140px;overflow-y:auto;border:1px solid #d8e1f3"><?= $h($manualShare['message']) ?></div>

    <div style="display:flex;flex-direction:column;gap:8px">
      <button type="button" class="wa-act" style="min-height:46px;border-radius:12px;border:0;font-weight:800;cursor:pointer" onclick="shareViaNative()">📱 Native Device Share (Attach Files)</button>
      <a href="<?= $h($manualShare['wa_me_url']) ?>" target="_blank" rel="noopener" style="display:flex;align-items:center;justify-content:center;min-height:46px;background:#25d366;color:#fff;text-decoration:none;border-radius:12px;font-weight:800">💬 Open in WhatsApp (wa.me)</a>
      <button type="button" style="min-height:40px;background:transparent;border:1.5px solid #c9d2e1;border-radius:12px;color:var(--mut);font-weight:700;cursor:pointer" onclick="closeShareModal()">Close</button>
    </div>
  </div>
</div>

<script>
function openPhotoStudio() {
  PhotoStudio.open('', {
    targetRef: '<?= $h($p['ref']) ?>',
    onSave: function(dataUrl) {
      location.reload();
    }
  });
}

function openShareModal() {
  document.getElementById('shareModal').style.display = 'flex';
}
function closeShareModal() {
  document.getElementById('shareModal').style.display = 'none';
}

function shareViaNative() {
  const text=<?= json_encode($manualShare['message'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  const fallback=()=>{window.open(<?= json_encode($manualShare['wa_me_url'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>, '_blank','noopener');};
  fetch(<?= json_encode($q(['pdf'=>'all'])) ?>).then(r=>{if(!r.ok)throw Error('Download failed');return r.blob();}).then(blob=>{
    const file=new File([blob], 'SHG-ID-and-Visiting-Cards.pdf',{type:'application/pdf'});
    if(navigator.share&&navigator.canShare&&navigator.canShare({files:[file]}))return navigator.share({title:'S Hari Global Cards',text,files:[file]});
    fallback();
  }).catch(e=>{if(e.name!=='AbortError')fallback();});
}

</script>
</body>
</html>
