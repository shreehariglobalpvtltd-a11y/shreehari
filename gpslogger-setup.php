<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/gpsloggerprofile.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
$tracker = GpsLoggerProfile::authorizedTracker($_GET);
if ($tracker === null) { http_response_code(404); exit('Setup link expired or unavailable. Open Admin > GPS trackers for a new link.'); }
$query = http_build_query(['id' => (int) $_GET['id'], 'e' => (int) $_GET['e'], 'sig' => (string) $_GET['sig']]);
$url = GpsTrackers::PUBLIC_URL . '/api/gpslogger-profile.php?' . $query;
?>
<!doctype html><html lang="ne"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>S Hari Global · GPSLogger setup</title>
<style>body{margin:0;background:#f1f5f9;color:#173047;font:16px/1.6 system-ui,sans-serif}main{max-width:520px;margin:24px auto;padding:24px;background:white;border-radius:20px}h1{font-size:26px;line-height:1.2}a.btn,button{display:block;box-sizing:border-box;width:100%;border:0;border-radius:12px;padding:14px;margin:12px 0;background:#124b72;color:white;text-decoration:none;text-align:center;font:600 16px system-ui;cursor:pointer}.light{background:#e7edf3!important;color:#173047!important}textarea{box-sizing:border-box;width:100%;min-height:100px;padding:10px;font:12px monospace;border:1px solid #bdc9d4;border-radius:10px}small{color:#51647b}@media(max-width:560px){main{margin:12px;padding:20px}}</style>
<main><!-- GPSLOGGER-20261025 -->
<small>S HARI GLOBAL · PHONE GPS SETUP</small>
<h1>🛰️ <?= Security::e($tracker['bus_number']) ?></h1>
<p>यसै Android फोनमा यी दुई चरण पूरा गर्नुहोस्। Server URL र tracker code profile मै तयार छन्।</p>
<a class="btn" href="https://github.com/mendhak/gpslogger/releases/latest" target="_blank" rel="noopener noreferrer">1 · GPSLogger for Android डाउनलोड</a>
<a class="btn" href="<?= Security::e('gpslogger://properties/' . $url) ?>">2 · GPSLogger मा profile खोल्नुहोस्</a>
<p>App खुलेपछि Location मा <b>Precise</b> र <b>Allow all the time</b> दिनुहोस्। Battery usage <b>Unrestricted</b> राखेर <b>Start logging</b> थिच्नुहोस्। परीक्षण गर्दा Traccar Client को Service बन्द राख्नुहोस्।</p>
<p><b>Settings तयार:</b> हरेक 10 सेकेन्ड · satellite GPS · 40 m accuracy filter · speed conversion · पुराना offline reports को भीड रोक्ने।</p>
<details><summary>Profile आफैँ नखुलेमा</summary><p>GPSLogger मा <b>Default Profile → From URL</b> छान्नुहोस् र तलको URL paste गर्नुहोस्।</p>
<textarea id="profile" readonly aria-label="Private GPSLogger profile URL"><?= Security::e($url) ?></textarea>
<button class="light" type="button" onclick="navigator.clipboard.writeText(document.getElementById('profile').value).then(()=>this.textContent='Copied')">Copy profile URL</button>
<a class="btn light" href="<?= Security::e($url) ?>">Configuration file डाउनलोड</a></details>
<small>यो निजी setup link 24 घण्टासम्म मान्य हुन्छ। यो link र configuration file आफूसँग राख्नुहोस्। Location permissions र Start फोनमै गर्नुपर्छ।</small>
</main></html>
