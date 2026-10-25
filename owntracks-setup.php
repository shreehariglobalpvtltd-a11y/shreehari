<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/owntracks.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
$tracker = GpsLoggerProfile::authorizedTracker($_GET);
if ($tracker === null) { http_response_code(404); exit('Setup link expired. Get a new link from Admin > GPS trackers.'); }
$query = http_build_query(['id' => (int) $_GET['id'], 'e' => (int) $_GET['e'], 'sig' => (string) $_GET['sig']]);
$download = GpsTrackers::PUBLIC_URL . '/api/owntracks-profile.php?' . $query;
$import = 'owntracks:///config?inline=' . rawurlencode(base64_encode(OwnTracksGateway::profile($tracker)));
?>
<!doctype html><html lang="ne"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>S Hari Global · OwnTracks setup</title>
<style>body{margin:0;background:#f1f5f9;color:#173047;font:16px/1.6 system-ui,sans-serif}main{max-width:520px;margin:24px auto;padding:24px;background:white;border-radius:20px}h1{font-size:26px}a.btn{display:block;box-sizing:border-box;width:100%;border-radius:12px;padding:14px;margin:12px 0;background:#124b72;color:white;text-decoration:none;text-align:center;font-weight:700}.light{background:#e7edf3!important;color:#173047!important}small{color:#51647b}li{margin:12px 0}code{overflow-wrap:anywhere}@media(max-width:560px){main{margin:12px;padding:20px}}</style>
<main><!-- OWNTRACKS-20261025 -->
<small>S HARI GLOBAL · ANDROID GPS</small><h1>🛰️ <?= Security::e($tracker['bus_number']) ?></h1>
<p>OwnTracks मा तयार settings import गर्नुहोस्। Server URL, bus account र password हातैले भर्नुपर्दैन।</p>
<a class="btn" href="https://play.google.com/store/apps/details?id=org.owntracks.android" target="_blank" rel="noopener noreferrer">1 · Play Store बाट OwnTracks डाउनलोड</a>
<ol start="2"><li>OwnTracks खोल्नुहोस्। Location <b>Precise / Allow all the time</b> दिनुहोस्। Battery usage <b>Unrestricted</b> राख्नुहोस्।</li>
<li>OwnTracks Settings मा <b>Allow configuration by URI and config file</b> चालु गर्नुहोस्। यो नयाँ app version मा import गर्न आवश्यक छ।</li></ol>
<a class="btn" href="<?= Security::e($import) ?>">4 · तयार settings OwnTracks मा import</a>
<p>OwnTracks खुलेपछि <b>✓ / Import</b> थिचेर पुष्टि गर्नुहोस्। Monitoring <b>Move</b> राख्नुहोस्, अनि <b>↑ Publish location</b> एकपटक थिच्नुहोस्।</p>
<details><summary>माथिको बटनले app नखोलेमा</summary><a class="btn light" href="<?= Security::e($download) ?>">Configuration file (.otrc) डाउनलोड</a><p>Downloads बाट यो file खोल्दा <b>OwnTracks</b> छान्नुहोस्। Settings import स्वीकृत गर्नुहोस्।</p></details>
<p><small>यस फोनमा परीक्षण गर्दा Traccar Client र GPSLogger बन्द राख्नुहोस्। Profile: HTTPS · high accuracy · 10-second location request · सही km/h। वास्तविक update GPS signal र Android background settings मा निर्भर हुन्छ।</small></p>
<small>यो निजी setup link 24 घण्टासम्म मान्य हुन्छ। Link र configuration आफूसँग राख्नुहोस्।</small>
</main></html>
