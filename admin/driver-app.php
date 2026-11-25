<?php
/**
 * admin/driver-app.php — the S Hari Driver Android app (25 Nov 2026, owner: "admin portal ma
 * rakhdeu"). Download button + a QR the driver scans from his own phone, install steps, how to
 * sign in (mobile or number + FirstName@2026 / PIN). The APK file is hand-placed in /downloads/
 * (not in git, like S-Hari-Manage.apk / S-Hari-Travel.apk).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/qr.php';

$admin = admin_boot('drivers.view');
$e     = static fn($v): string => Security::e((string) ($v ?? ''));
$file  = 'S-Hari-Driver.apk';
$path  = ROOT_PATH . '/downloads/' . $file;
$have  = is_file($path);
$url   = 'https://www.shreehariglobal.in/downloads/' . $file;
$size  = $have ? round(filesize($path) / 1024) . ' KB' : '';
$when  = $have ? date('d M Y', (int) filemtime($path)) : '';
$qr    = '';
try { $qr = QrCode::dataUri($url, 6, 2); } catch (Throwable) {}

admin_header('Driver app · चालक App', 'driver-app.php');
?>
<div class="wrap" style="max-width:760px">
  <h1 style="margin:0 0 6px">🚌 S Hari Driver — चालक / कन्डक्टर App</h1>
  <p class="muted" style="margin:0 0 14px">बसको लाइभ लोकेसन (GPS), लाइभ नक्सा, अर्को स्टप, यात्रु सूची, SOS, आराम — चालक र कन्डक्टर दुवैका लागि एउटै app।</p>
  <?php if (!$have): ?>
    <p style="padding:10px 12px;border-radius:10px;background:#fdecec;color:#9b1c1c;font-weight:700">APK अहिले server मा छैन · The APK file is not on the server yet.</p>
  <?php else: ?>
  <div style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;background:#fff;border-radius:16px;padding:16px;box-shadow:0 2px 10px rgba(10,30,80,.08)">
    <?php if ($qr !== ''): ?><img src="<?= $e($qr) ?>" alt="Download QR" width="180" height="180" style="border-radius:10px"><?php endif; ?>
    <div style="flex:1;min-width:220px">
      <p style="margin:0 0 6px;font-weight:700">चालकको फोनले यो QR स्क्यान गर्नुहोस्, वा तलको बटन थिच्नुहोस्।</p>
      <a class="btn" href="/downloads/<?= $e($file) ?>" download="<?= $e($file) ?>" type="application/vnd.android.package-archive" style="display:inline-flex;align-items:center;min-height:48px;padding:0 18px;font-size:16px">⬇ S Hari Driver डाउनलोड (<?= $e($size) ?>)</a>
      <p class="muted" style="margin:8px 0 0;font-size:12.5px">अपडेट: <?= $e($when) ?> · Android 7+ · <a href="<?= $e($url) ?>"><?= $e($url) ?></a></p>
    </div>
  </div>
  <?php endif; ?>

  <h2 style="font-size:16px;margin:20px 0 6px">Install र लगइन</h2>
  <ol style="line-height:1.7;margin:0 0 0 18px">
    <li>APK download गरेर खोल्नुहोस् → "Unknown apps / अज्ञात app install" अनुमति दिनुहोस् → Install।</li>
    <li>App खुल्दा लोकेसन र सूचनाको अनुमति माग्छ — दुवै <b>Allow</b>।</li>
    <li>लगइन: <b>मोबाइल नम्बर वा चालक नम्बर</b> + <b>पासवर्ड</b> (सुरुमा आफ्नो पहिलो नाम@2026, जस्तै Ram@2026) वा कार्यालयले दिएको PIN। <a href="login-reset.php">सजिलो लगइन / रिसेट</a></li>
    <li>"▶ यात्रा सुरु" थिच्नुहोस् — त्यसपछि स्क्रिन बन्द गरे पनि लोकेसन जान्छ। 🔋 बटनबाट ब्याट्री सेटिङमा "Don't optimise" राख्नुहोस्।</li>
    <li>नाम वा नम्बर Admin → <a href="drivers.php">Drivers &amp; Bus Crew</a> मा हुनुपर्छ (role: driver / co-driver / conductor)।</li>
  </ol>
</div>
<?php
admin_footer();
