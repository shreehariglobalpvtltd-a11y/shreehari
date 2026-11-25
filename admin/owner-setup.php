<?php
/**
 * admin/owner-setup.php — बाँकी भर्ने ठाउँ · Owner setup (19 Nov 2026).
 *
 * Owner: "1, 2, 3 fill garne thau bandeu, pachi add gardinchhu". One page with the
 * few facts only the owner can give, each saved where the system already reads it:
 *   1. Driver per bus      → drivers row + buses.default_driver_id (the driver list,
 *                             the 21:30 report and the chalani all read it)
 *   2. Map links           → india_hub_map / nepal_office_map (ticket map QR)
 *   3. ICICI live details  → icici_live_* settings (kept for the switch to production;
 *                             nothing goes live until the bank's go-live is checked)
 *   4. Nepal company       → nepal_reg, nepal_esewa_id, nepal_esewa_name (Nepal ticket)
 *   5. Play App Signing    → android_*_sha256 (added to /.well-known/assetlinks.json)
 * plus a read-only checklist of what is still waiting.
 *
 * Settings permission; every save CSRF-checked and audited. A secret is never shown back.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/aiops.php';   // AiOps::superOnlySetting (payment ids are a super-admin's)
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/driverauth.php';
require_once INCLUDE_PATH . '/normalfare.php';

$admin = admin_boot('dashboard.view');
$can   = Auth::canManageSettings();
$e     = static fn($v): string => Security::e((string) $v);
$flash = [];
$pair  = null;   // ['name' =>, 'url' =>, 'qr' =>] right after a connect link is made

$keys = [
    'india_hub_map'          => ['map'],
    'nepal_office_map'       => ['map'],
    'icici_live_merchant_id' => ['text', 40],
    'icici_live_aggregator_id' => ['text', 40],
    'icici_live_app_id'      => ['text', 60],
    'icici_live_secret_key'  => ['secret', 200],
    'nepal_company'          => ['text', 120],
    'nepal_pan'              => ['text', 40],
    'nepal_phone'            => ['text', 80],
    'nepal_office'           => ['text', 255],
    'nepal_reg'              => ['text', 60],
    'nepal_esewa_id'         => ['text', 80],
    'nepal_esewa_name'       => ['text', 120],
    'android_travel_sha256'  => ['sha', 0],
    'android_manage_sha256'  => ['sha', 0],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash[] = ['bad', 'Session expired — फेरि खोल्नुहोस्।'];
    } elseif (!$can) {
        $flash[] = ['bad', 'Settings permission चाहिन्छ · needs the Settings permission'];
    } elseif ((string) ($_POST['act'] ?? '') === 'drivers') {
        $done = 0;
        foreach ((array) ($_POST['bus'] ?? []) as $busId => $row) {
            $busId = (int) $busId;
            if ($busId <= 0 || !is_array($row)) { continue; }
            $pick  = (string) ($row['driver'] ?? '');
            $name  = Security::clean((string) ($row['name'] ?? ''), 120);
            $phone = preg_replace('/\D/', '', (string) ($row['phone'] ?? '')) ?? '';
            $driverId = null;
            if ($pick === 'new') {
                if ($name === '' || preg_match('/^(91|977)?[6-9]\d{9}$/', $phone) !== 1) {
                    $flash[] = ['bad', 'Bus #' . $busId . ': नाम र १० अंकको phone चाहिन्छ · name and a 10-digit phone are needed'];
                    continue;
                }
                $local = substr($phone, -10);
                $ex = Database::fetch('SELECT id FROM drivers WHERE phone = :p', ['p' => $local]);
                $driverId = $ex !== null ? (int) $ex['id'] : (int) Database::insert('drivers', ['full_name' => $name, 'phone' => $local, 'role' => in_array((string) ($row['role'] ?? ''), ['driver', 'conductor'], true) ? (string) $row['role'] : 'conductor', 'is_active' => 1]);
            } elseif ($pick !== '' && ctype_digit($pick)) {
                $driverId = (int) $pick;
            } elseif ($pick === '') {
                $driverId = null;
            } else {
                continue;
            }
            $old = Database::scalar('SELECT default_driver_id FROM buses WHERE id = :i', ['i' => $busId]);
            if ((string) $old === (string) $driverId) { continue; }
            Database::update('buses', ['default_driver_id' => $driverId], 'id = :i', ['i' => $busId]);
            Logger::audit('bus.default_driver', 'bus', (string) $busId, ['driver' => $old], ['driver' => $driverId], 'Owner setup');
            $done++;
        }
        $flash[] = ['ok', 'Driver सेभ भयो · saved (' . $done . ')'];
    } elseif ((string) ($_POST['act'] ?? '') === 'pair') {
        try {
            $did  = (int) ($_POST['driver_id'] ?? 0);
            $code = DriverAuth::pairCode($did, (int) ($admin['id'] ?? 0));
            $url  = appUrl('driver/?pair=' . $code);
            $pair = ['name' => (string) Database::scalar('SELECT full_name FROM drivers WHERE id = :i', ['i' => $did], ''),
                     'url' => $url, 'qr' => QrCode::dataUri($url, 6, 3)];
        } catch (Throwable $ex) {
            $flash[] = ['bad', $ex->getMessage()];
        }
    } elseif ((string) ($_POST['act'] ?? '') === 'normalfare') {
        /* 20 Nov 2026 (nf1): the route's REAL normal fare, per seat. Shown struck
           through beside a lower real price - never changes what is charged. */
        $nf = [];
        foreach ((array) ($_POST['nf'] ?? []) as $rid => $amt) {
            $a = (float) preg_replace('/[^0-9.]/', '', (string) $amt);
            if ((int) $rid > 0 && $a > 0 && $a < 100000) { $nf[(string) (int) $rid] = round($a, 2); }
        }
        $on = !empty($_POST['nf_on']) ? '1' : '0';
        Settings::set('normal_fare_json', json_encode($nf), 'json', 'pricing');
        Settings::set('normal_fare_on', $on, 'bool', 'pricing');
        Settings::touchTicketIdentity();   // mp1 tk: the struck-through normal fare prints on the ticket - cached tickets redraw
        Settings::flush();
        Logger::audit('settings.normal_fare', 'settings', 'owner-setup', null, ['fares' => $nf, 'on' => $on], 'Normal fare (struck-through price)');
        $flash[] = ['ok', 'सामान्य भाडा सेभ भयो · normal fare saved' . ($on === '1' ? ' (ON)' : ' (OFF)')];
    } elseif ((string) ($_POST['act'] ?? '') === 'esewa_qr' && !Auth::isSuperadmin()) {
        /* 25 Nov 2026 (mp1): the Nepal payment QR is a super-admin's (Payment Management). */
        $flash[] = ['bad', 'नेपाली QR सुपर-एडमिनले मात्र राख्न/हटाउन मिल्छ · only a super-admin can change the Nepal QR'];
    } elseif ((string) ($_POST['act'] ?? '') === 'esewa_qr') {
        /* 21 Nov 2026 (owner: "NPJ branch ko chhuttai Nepali wala QR rakhdinus"): the
           Nepal counter's own eSewa / Fonepay QR image, printed on NPR tickets that
           still owe money. Re-encoded (never stored as sent); 'remove' clears it. */
        try {
            if (!empty($_POST['remove'])) {
                $oldQr = Settings::getString('nepal_esewa_qr', '');
                Settings::set('nepal_esewa_qr', '', 'string', 'company');
                Settings::touchTicketIdentity();   // mp1 tk: the Nepal pay QR on due tickets changes - cached tickets redraw
                Settings::flush();
                Logger::audit('settings.esewa_qr', 'settings', 'owner-setup', ['file' => $oldQr], ['file' => ''], 'Nepal eSewa QR removed');
                $flash[] = ['ok', 'eSewa QR हटाइयो · removed'];
            } else {
                $f = $_FILES['esewa_qr'] ?? [];
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($f['tmp_name'] ?? ''))) {
                    throw new RuntimeException('QR को फोटो छान्नुहोस् · choose the QR image');
                }
                $mime = mime_content_type((string) $f['tmp_name']) ?: '';
                if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || (int) ($f['size'] ?? 0) > 3 * 1024 * 1024) {
                    throw new RuntimeException('PNG / JPG / WebP, 3 MB सम्म');
                }
                $src = @imagecreatefromstring((string) file_get_contents((string) $f['tmp_name']));
                if ($src === false) {
                    throw new RuntimeException('फोटो खुलेन · image could not be read');
                }
                $w = imagesx($src); $h = imagesy($src);
                $sc = min(1.0, 900 / max(1, $w, $h));
                $dst = imagecreatetruecolor(max(1, (int) round($w * $sc)), max(1, (int) round($h * $sc)));
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
                $dir = rtrim(defined('UPLOAD_PATH') ? UPLOAD_PATH : ROOT_PATH . '/uploads', '/\\') . '/payqr';
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                    throw new RuntimeException('Could not create uploads/payqr');
                }
                $name = 'esewa-npj-' . bin2hex(random_bytes(4)) . '.png';
                $ok = imagepng($dst, $dir . '/' . $name . '.tmp', 6) && rename($dir . '/' . $name . '.tmp', $dir . '/' . $name);
                imagedestroy($src); imagedestroy($dst);
                if (!$ok) {
                    @unlink($dir . '/' . $name . '.tmp');
                    throw new RuntimeException('Could not save the QR');
                }
                $oldQr = Settings::getString('nepal_esewa_qr', '');
                Settings::set('nepal_esewa_qr', 'uploads/payqr/' . $name, 'string', 'company');
                Settings::touchTicketIdentity();   // mp1 tk: the Nepal pay QR on due tickets changes - cached tickets redraw
                Settings::flush();
                Logger::audit('settings.esewa_qr', 'settings', 'owner-setup', ['file' => $oldQr], ['file' => 'uploads/payqr/' . $name], 'Nepal eSewa QR uploaded');
                $flash[] = ['ok', 'eSewa QR सेभ भयो · saved — NPR टिकटमा देखिन्छ'];
            }
        } catch (Throwable $ex) {
            $flash[] = ['bad', $ex->getMessage()];
        }
    } elseif ((string) ($_POST['act'] ?? '') === 'facts') {
        $changed = [];
        foreach ($keys as $k => $spec) {
            if (!array_key_exists($k, $_POST)) { continue; }
            $v = trim((string) $_POST[$k]);
            /* 25 Nov 2026 (mp1): payment ids / QR are a super-admin's (AiOps::superOnlySetting). */
            if (!Auth::isSuperadmin() && AiOps::superOnlySetting($k)) {
                if ($v !== Settings::getString($k, '')) {
                    $flash[] = ['bad', $k . ': भुक्तानी ID सुपर-एडमिनले मात्र बदल्न मिल्छ · only a super-admin can change it'];
                }
                continue;
            }
            if ($spec[0] === 'secret' && $v === '') { continue; }          // blank = keep the saved secret
            if ($spec[0] === 'map' && $v !== '') {
                if (Ticket::mapLink($v) === '') {
                    $flash[] = ['bad', $k . ': Google Maps link वा "lat, lng" चाहिन्छ · paste a Google Maps link or "lat, lng"'];
                    continue;
                }
            }
            if ($spec[0] === 'sha' && $v !== '') {
                $v = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $v) ?? '');
                if (strlen($v) !== 64) {
                    $flash[] = ['bad', $k . ': SHA-256 = 64 hex (AB:CD:…) · not a SHA-256'];
                    continue;
                }
                $v = implode(':', str_split($v, 2));
            }
            if (in_array($spec[0], ['text', 'secret'], true)) {
                $v = mb_substr(Security::clean($v, (int) $spec[1]), 0, (int) $spec[1]);
            }
            if ($v === Settings::getString($k, '')) { continue; }
            Settings::set($k, $v, 'string', str_starts_with($k, 'icici') ? 'payments' : (str_starts_with($k, 'android') ? 'app' : 'company'));
            $changed[] = $k;
        }
        Settings::flush();
        // mp1 tk: a company / Nepal line (PAN, eSewa id, office, map pins) prints on the ticket - cached tickets redraw
        if (array_filter($changed, static fn(string $k): bool => !str_starts_with($k, 'icici') && !str_starts_with($k, 'android')) !== []) {
            Settings::touchTicketIdentity();
        }
        if ($changed !== []) {
            Logger::audit('settings.owner_setup', 'settings', 'owner-setup', null, ['changed' => $changed], 'Owner setup page');
            $flash[] = ['ok', 'सेभ भयो · saved: ' . implode(', ', $changed)];
        } elseif (!array_filter($flash, static fn($f) => $f[0] === 'bad')) {
            $flash[] = ['ok', 'केही बदलिएन · nothing changed'];
        }
    }
}

$buses   = Database::fetchAll('SELECT id, bus_number, default_driver_id FROM buses WHERE is_active = 1 ORDER BY id');
$drivers = Database::fetchAll("SELECT id, full_name, phone, role FROM drivers WHERE is_active = 1 AND full_name <> '' ORDER BY full_name");
$val     = static fn(string $k): string => Settings::getString($k, '');
$tplReady = null;
try {
    require_once INCLUDE_PATH . '/watplmeta.php';
    $tplReady = method_exists('WaTplMeta', 'overview') ? WaTplMeta::overview() : null;
} catch (Throwable $ex) {
    $tplReady = null;
}
$approved = 0; $pending = 0; $missing = [];
foreach ((array) $tplReady as $row) {
    if (!is_array($row) || (string) ($row['name'] ?? '') === 'shg_booking_form') { continue; }   // waits for a published Flow
    $st = strtoupper((string) ($row['status'] ?? ''));
    if ($st === 'APPROVED') { $approved++; } elseif ($st === 'PENDING') { $pending++; } else { $missing[] = (string) ($row['name'] ?? '?'); }
}
$withDriver = count(array_filter($buses, static fn($b) => !empty($b['default_driver_id'])));
$checks = [
    ['🚌 Driver phone (हरेक बस)', $withDriver === count($buses) && $buses !== [], $withDriver . ' / ' . count($buses) . ' बसमा driver'],
    ['📍 S Hari Parking map', $val('india_hub_map') !== '', $val('india_hub_map') !== '' ? 'भरियो' : 'खाली'],
    ['📍 Pushpalal Chowk map', $val('nepal_office_map') !== '', $val('nepal_office_map') !== '' ? 'भरियो' : 'खाली (अहिले नामबाट Google खोज)'],
    ['🏦 ICICI live details', $val('icici_live_merchant_id') !== '' && $val('icici_live_secret_key') !== '', 'भरेपछि Claude ले bank go-live जाँचेर switch गर्छ'],
    ['🇳🇵 Nepal company reg no', $val('nepal_reg') !== '', $val('nepal_reg') !== '' ? $val('nepal_reg') : 'खाली'],
    ['🇳🇵 Nepal eSewa ID', $val('nepal_esewa_id') !== '', $val('nepal_esewa_id') !== '' ? 'भरियो' : 'खाली (नेपाल टिकटमा eSewa लाइन देखिँदैन)'],
    ['📱 Play App Signing SHA-256', $val('android_travel_sha256') !== '' && $val('android_manage_sha256') !== '', 'Play Console → App signing बाट'],
    ['💬 Meta templates approved', $tplReady !== null && $approved > 0 && $pending === 0 && $missing === [], $tplReady === null ? '—' : ($approved . ' approved · ' . $pending . ' pending' . ($missing ? ' · missing: ' . implode(', ', array_slice($missing, 0, 3)) : ''))],
];

admin_header('बाँकी भर्ने ठाउँ · Owner setup', 'owner-setup');
?>
<style>
.os-grid{display:grid;gap:14px;max-width:880px}
.os-card{background:var(--surface,#fff);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.os-card h2{margin:0 0 4px;font-size:16px}.os-card p.hint{margin:0 0 10px;color:var(--muted);font-size:12.5px}
.os-f{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.os-f label{font-size:12px;color:var(--muted)}.os-f input,.os-f select{display:block;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:9px;min-height:40px;font:inherit}
.os-bus{display:grid;grid-template-columns:140px 1fr 1fr 1fr;gap:8px;align-items:end;padding:8px 0;border-bottom:1px dashed var(--line)}
@media (max-width:640px){.os-bus{grid-template-columns:1fr}}
.os-ck{width:100%;border-collapse:collapse}.os-ck td{padding:7px 6px;border-bottom:1px solid var(--line);font-size:13.5px}
.os-ok{color:var(--ok,#0a7c2f);font-weight:700}.os-no{color:#b26a00;font-weight:700}
.os-flash{padding:9px 12px;border-radius:10px;margin:0 0 6px}.os-flash.ok{background:#e9f8ee;color:#0a7c2f}.os-flash.bad{background:#fdecec;color:#b3261e}
</style>
<div class="os-grid">
  <?php foreach ($flash as [$t, $m]): ?><div class="os-flash <?= $e($t) ?>"><?= $e($m) ?></div><?php endforeach; ?>

  <div class="os-card">
    <h2>✅ के बाँकी छ · What is still waiting</h2>
    <table class="os-ck"><?php foreach ($checks as [$n, $ok, $note]): ?>
      <tr><td><?= $e($n) ?></td><td class="<?= $ok ? 'os-ok' : 'os-no' ?>"><?= $ok ? '✔ तयार' : '⏳ बाँकी' ?></td><td class="muted"><?= $e($note) ?></td></tr>
    <?php endforeach; ?></table>
  </div>

  <form method="post" class="os-card">
    <?= Security::csrfField() ?><input type="hidden" name="act" value="drivers">
    <h2>1 · 🚌 बसको कन्डक्टर / ड्राइभर · Crew per bus</h2>
    <p class="hint">यहाँ राखेको मान्छेलाई ५ यात्रु पुगेपछि यात्रु सूची WhatsApp मा जान्छ। नयाँ: "+ नयाँ" छानेर नाम र phone लेख्नुहोस्।</p>
    <?php foreach ($buses as $b): ?>
      <div class="os-bus">
        <b><?= $e($b['bus_number']) ?></b>
        <label>Driver<select name="bus[<?= (int) $b['id'] ?>][driver]">
          <option value="">— छैन · none —</option>
          <?php foreach ($drivers as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (int) $b['default_driver_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= $e($d['full_name']) ?> (<?= $e($d['role']) ?>) · …<?= $e(substr((string) $d['phone'], -4)) ?></option><?php endforeach; ?>
          <option value="new">+ नयाँ · new</option>
        </select></label>
        <label>नयाँ नाम · New name<input name="bus[<?= (int) $b['id'] ?>][name]" maxlength="120" placeholder="Ram Bahadur"></label>
        <label>Phone<input name="bus[<?= (int) $b['id'] ?>][phone]" inputmode="tel" maxlength="16" placeholder="98XXXXXXXX / 9XXXXXXXXX">
          <select name="bus[<?= (int) $b['id'] ?>][role]" style="margin-top:4px"><option value="conductor">कन्डक्टर · Conductor</option><option value="driver">ड्राइभर · Driver</option></select></label>
      </div>
    <?php endforeach; ?>
    <p style="margin:10px 0 0"><button class="btn btn-orange" type="submit" <?= $can ? '' : 'disabled' ?>>सेभ · Save drivers</button> <a class="btn" href="drivers.php">Drivers page</a></p>
  </form>

  <div class="os-card">
    <h2>💬 WhatsApp बुकिङ लिंक · Booking link</h2>
    <p class="hint">यो link थिच्दा WhatsApp मा फारम (नाम, मोबाइल, चढ्ने ठाउँ, मिति, सिट) खुल्छ — भरेर Send गरेपछि bot ले टिकट पठाउँछ। एजेन्टको आफ्नै link: अन्तमा <code>?a=SHG-0008</code> (उसको code) — त्यो टिकट उसकै नाममा जान्छ।</p>
    <input readonly value="<?= $e(appUrl('wa.php')) ?>" onclick="this.select()" style="width:100%;padding:8px;border:1px solid var(--line);border-radius:8px">
    <p style="margin:8px 0 0"><a class="btn" target="_blank" rel="noopener" href="<?= $e(appUrl('wa.php?go=0&qr=1')) ?>">QR + नमुना · QR and preview</a></p>
  </div>

  <div class="os-card" id="pair">
    <h2>📲 कन्डक्टरको phone जोड्ने · Connect a crew phone (link / QR)</h2>
    <p class="hint">"🔗 Link बनाउ" थिच्नुहोस् → आएको QR कन्डक्टरको phone ले scan गर्ने, वा link WhatsApp मा पठाउने। खोल्नेबित्तिकै phone जोडिन्छ (PIN चाहिँदैन) र बसको live location + यात्रु सूची खुल्छ। Link एक पटक मात्र, २४ घण्टा चल्छ।</p>
    <?php if ($pair !== null): ?>
      <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;background:#f3f8ff;border:1px solid #cfe0ff;border-radius:12px;padding:12px;margin-bottom:10px">
        <img src="<?= $e($pair['qr']) ?>" alt="QR" width="180" height="180" style="background:#fff;border-radius:8px">
        <div style="flex:1;min-width:220px">
          <b><?= $e($pair['name']) ?></b> को phone ले यो QR scan गर्नुहोस्, वा link पठाउनुहोस्:
          <input readonly value="<?= $e($pair['url']) ?>" onclick="this.select()" style="display:block;width:100%;margin:6px 0;padding:8px;border:1px solid var(--line);border-radius:8px;font-size:12px">
          <a class="btn" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode('S Hari Global — बस app जोड्ने link (एक पटक मात्र): ' . $pair['url']) ?>">WhatsApp मा पठाउ · Send</a>
        </div>
      </div>
    <?php endif; ?>
    <?php foreach ($drivers as $d): ?>
      <form method="post" action="#pair" style="display:flex;gap:10px;align-items:center;padding:6px 0;border-bottom:1px dashed var(--line)">
        <?= Security::csrfField() ?><input type="hidden" name="act" value="pair"><input type="hidden" name="driver_id" value="<?= (int) $d['id'] ?>">
        <span style="flex:1"><b><?= $e($d['full_name']) ?></b> <span class="muted">(<?= $e($d['role']) ?>) · …<?= $e(substr((string) $d['phone'], -4)) ?></span></span>
        <button class="btn" type="submit" <?= $can ? '' : 'disabled' ?>>🔗 Link बनाउ · Make link</button>
      </form>
    <?php endforeach; ?>
    <?php if ($drivers === []): ?><p class="muted">पहिले माथि कन्डक्टर/ड्राइभर थप्नुहोस्।</p><?php endif; ?>
  </div>

  <?php $nfMap = NormalFare::map(); $nfRoutes = Database::fetchAll('SELECT id, route_code, from_city, to_city FROM routes WHERE is_active = 1 ORDER BY sort_order, id'); ?>
  <form method="post" class="os-card" id="normal-fare">
    <?= Security::csrfField() ?><input type="hidden" name="act" value="normalfare">
    <h2>🏷️ सामान्य भाडा · Normal fare (काटिएको भाडा देखाउने)</h2>
    <p class="hint">यहाँ रुटको <b>साँचो</b> सामान्य भाडा (प्रति सिट) राख्नुहोस् — जस्तै काउन्टर / भीडको दिनमा साँच्चै लिने भाडा। आजको भाडा यो भन्दा कम भए app, desk र टिकटमा <s>सामान्य भाडा</s> र "बचत ₹…" देखिन्छ। भाडा आफैं बदलिँदैन। ⚠️ कहिल्यै नलिएको नक्कली भाडा राख्नु भ्रामक विज्ञापन हो (भारत Consumer Protection Act 2019, नेपाल उपभोक्ता कानुन) — उजुरी परे जरिवाना लाग्न सक्छ।</p>
    <div class="os-f">
      <?php foreach ($nfRoutes as $r): ?>
      <label><?= $e($r['from_city'] . ' → ' . $r['to_city']) ?> <small class="muted"><?= $e($r['route_code']) ?></small>
        <input name="nf[<?= (int) $r['id'] ?>]" inputmode="decimal" value="<?= isset($nfMap[(int) $r['id']]) ? $e((string) $nfMap[(int) $r['id']] + 0) : '' ?>" placeholder="₹ प्रति सिट · per seat (खाली = नदेखाउने)"></label>
      <?php endforeach; ?>
    </div>
    <label style="display:flex;gap:8px;align-items:center;margin-top:10px"><input type="checkbox" name="nf_on" value="1" <?= NormalFare::on() ? 'checked' : '' ?>> देखाउने · Show on app, desk and ticket</label>
    <p style="margin:12px 0 0"><button class="btn btn-orange" type="submit" <?= $can ? '' : 'disabled' ?>>सेभ · Save</button></p>
  </form>

  <form method="post" class="os-card">
    <?= Security::csrfField() ?><input type="hidden" name="act" value="facts">
    <h2>2 · 📍 नक्सा · Map links (ticket QR)</h2>
    <p class="hint">Google Maps मा ठाउँ खोल्नुहोस् → Share → Copy link → यहाँ paste। वा "23.17, 72.62" जस्तो lat, lng।</p>
    <div class="os-f">
      <label>S Hari Parking, Nana Chiloda (AMD)<input name="india_hub_map" value="<?= $e($val('india_hub_map')) ?>" placeholder="https://maps.app.goo.gl/…"></label>
      <label>Pushpalal Chowk, Nepalgunj (Hotel Darbar अगाडि)<input name="nepal_office_map" value="<?= $e($val('nepal_office_map')) ?>" placeholder="https://maps.app.goo.gl/…"></label>
    </div>

    <h2 style="margin-top:16px">3 · 🏦 ICICI live (bank बाट आएपछि)</h2>
    <p class="hint">Bank ले दिएको production details। Secret एकपटक सेभ भएपछि फेरि देखाइँदैन (खाली छोडे पुरानै रहन्छ)। सेभ गरेपछि Claude लाई भन्नुहोस् — bank go-live जाँचेर मात्र असली payment मा switch गरिन्छ।</p>
    <div class="os-f">
      <label>Merchant ID<input name="icici_live_merchant_id" value="<?= $e($val('icici_live_merchant_id')) ?>" autocomplete="off"></label>
      <label>Aggregator ID<input name="icici_live_aggregator_id" value="<?= $e($val('icici_live_aggregator_id')) ?>" autocomplete="off"></label>
      <label>App ID<input name="icici_live_app_id" value="<?= $e($val('icici_live_app_id')) ?>" autocomplete="off"></label>
      <label>Secret key <?= $val('icici_live_secret_key') !== '' ? '<span class="os-ok">✔ saved</span>' : '' ?><input name="icici_live_secret_key" type="password" value="" autocomplete="new-password" placeholder="<?= $val('icici_live_secret_key') !== '' ? '•••••••• (बदल्न मात्र लेख्नुहोस्)' : '' ?>"></label>
    </div>

    <h2 style="margin-top:16px">4 · 🇳🇵 नेपाल कम्पनी · Nepal company (NPJ branch)</h2>
    <p class="hint">नेपाल काउन्टर (NPJ) बाट काटिएको टिकटमा यही नाम, PAN, फोन र ठेगाना छापिन्छ। PAN र फोन सबै टिकटको "NEPAL OFFICE" QR मुनि पनि आउँछ।</p>
    <div class="os-f">
      <label>टिकटमा कम्पनी नाम · name on Nepal tickets<input name="nepal_company" value="<?= $e($val('nepal_company')) ?>" placeholder="Shree Hari Global — NPJ Branch"></label>
      <label>PAN (स्थायी लेखा नम्बर)<input name="nepal_pan" value="<?= $e($val('nepal_pan')) ?>" inputmode="numeric"></label>
      <label>फोन · phones (/ ले छुट्याउनुहोस्)<input name="nepal_phone" value="<?= $e($val('nepal_phone')) ?>" placeholder="+977 98XXXXXXXX / 98XXXXXXXX"></label>
      <label>ठेगाना · address<input name="nepal_office" value="<?= $e($val('nepal_office')) ?>"></label>
      <label>Reg. No. (दर्ता नं)<input name="nepal_reg" value="<?= $e($val('nepal_reg')) ?>"></label>
      <label>eSewa ID<input name="nepal_esewa_id" value="<?= $e($val('nepal_esewa_id')) ?>" placeholder="98XXXXXXXX"></label>
      <label>eSewa नाम · name<input name="nepal_esewa_name" value="<?= $e($val('nepal_esewa_name')) ?>"></label>
    </div>

    <h2 style="margin-top:16px">5 · 📱 Play Store App signing SHA-256</h2>
    <p class="hint">Play Console → app → Setup → App signing → "App signing key certificate" को SHA-256। भरेपछि Claude ले app link (assetlinks) मा थप्छ।</p>
    <div class="os-f">
      <label>S Hari Travel<input name="android_travel_sha256" value="<?= $e($val('android_travel_sha256')) ?>" placeholder="AB:CD:…"></label>
      <label>S Hari Manage<input name="android_manage_sha256" value="<?= $e($val('android_manage_sha256')) ?>" placeholder="AB:CD:…"></label>
    </div>
    <p style="margin:12px 0 0"><button class="btn btn-orange" type="submit" <?= $can ? '' : 'disabled' ?>>सेभ · Save</button></p>
  </form>

  <form method="post" enctype="multipart/form-data" class="os-card">
    <?= Security::csrfField() ?><input type="hidden" name="act" value="esewa_qr">
    <h2>6 · 🇳🇵 NPJ eSewa / Fonepay QR (नेपाली भुक्तानी QR)</h2>
    <p class="hint">नेपाल काउन्टरको आफ्नै QR को फोटो। NPR मा काटिएको र पैसा बाँकी भएको टिकटमा यही QR छापिन्छ (भारतको UPI QR होइन)। पैसा आएपछि office ले हातैले approve गर्छ।</p>
    <?php $qrNow = Ticket::esewaQrFile(); if ($qrNow !== ''): ?>
      <p><img src="data:<?= $e(mime_content_type($qrNow) ?: 'image/png') ?>;base64,<?= base64_encode((string) file_get_contents($qrNow)) ?>" alt="eSewa QR" style="width:160px;height:auto;border:1px solid #e2e8f0;border-radius:10px"></p>
    <?php else: ?><p class="hint"><b>अहिले कुनै QR छैन।</b></p><?php endif; ?>
    <div class="os-f"><label>QR फोटो (PNG / JPG)<input type="file" name="esewa_qr" accept="image/png,image/jpeg,image/webp"></label></div>
    <p style="margin:12px 0 0;display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn-orange" type="submit" <?= $can ? '' : 'disabled' ?>>अपलोड · Upload</button>
      <?php if (Settings::getString('nepal_esewa_qr', '') !== ''): ?><button class="btn" type="submit" name="remove" value="1" <?= $can ? '' : 'disabled' ?>>हटाउ · Remove</button><?php endif; ?></p>
  </form>
</div>
<?php admin_footer(); ?>
