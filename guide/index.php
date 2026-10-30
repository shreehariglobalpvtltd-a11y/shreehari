<?php
/**
 * /guide/ — the agent-form guide video (30 Oct 2026, owner: "bhidiyo app ma
 * upload gardeu, link share garna milos, link kholda sath bhidiyo chalos").
 *
 * A share link for WhatsApp: the preview shows the thumbnail and title, and
 * the page starts the video at once. Phones only allow a muted autoplay, so
 * it starts muted with a big "turn the sound on" button; one tap unmutes.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

Security::sendHeaders();
$h = static fn(string $s): string => Security::e($s);
$base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
$ver = static function (string $f): string {
    $p = ASSET_PATH . '/agentpro/guide/' . $f;
    return '/assets/agentpro/guide/' . $f . '?v=' . (is_file($p) ? filemtime($p) : '1');
};
$video = $ver('agent-form-guide-ne.mp4');
$thumb = $ver('agent-form-guide-thumb.jpg');
$title = 'एजेन्ट फारम कसरी भर्ने? · S Hari Global';
$desc  = '६ सजिलो चरणमा एजेन्ट फारम भर्ने तरिका — नेपाली भिडियो। How to fill the S Hari Global agent form.';
$office = Settings::officePhone();
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $h($title) ?></title>
<meta name="description" content="<?= $h($desc) ?>">
<meta property="og:type" content="video.other">
<meta property="og:title" content="<?= $h($title) ?>">
<meta property="og:description" content="<?= $h($desc) ?>">
<meta property="og:image" content="<?= $h($base . $thumb) ?>">
<meta property="og:video" content="<?= $h($base . $video) ?>">
<meta property="og:video:type" content="video/mp4">
<meta property="og:video:width" content="1080">
<meta property="og:video:height" content="1920">
<meta property="og:url" content="<?= $h($base . '/guide/') ?>">
<meta name="theme-color" content="#0b1a3a">
<style>
:root{--navy:#0b1a3a;--gold:#d9a441;--orange:#f07a1e;--ink:#fff}
*{box-sizing:border-box}
html,body{margin:0;background:var(--navy);color:var(--ink);font-family:"Noto Sans Devanagari","Segoe UI",Roboto,sans-serif}
.wrap{max-width:520px;margin:0 auto;padding:12px 16px 24px;min-height:100vh;display:flex;flex-direction:column;gap:12px}
.top{display:flex;align-items:center;gap:10px}
.top img{width:44px;height:auto;background:#fff;border-radius:10px;padding:4px}
.top b{font-size:15px;letter-spacing:.02em}
.top small{display:block;color:var(--gold);font-size:12px}
.vbox{position:relative;border-radius:18px;overflow:hidden;background:#000;box-shadow:0 18px 40px -20px rgba(0,0,0,.8)}
video{display:block;width:100%;max-height:78vh;background:#000}
.sound{position:absolute;left:50%;top:14px;transform:translateX(-50%);min-height:52px;padding:0 22px;border:0;border-radius:999px;background:var(--orange);color:#fff;font:inherit;font-weight:800;font-size:16px;box-shadow:0 8px 20px rgba(0,0,0,.45);cursor:pointer}
.sound[hidden]{display:none}
.acts{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.acts a{display:flex;align-items:center;justify-content:center;min-height:52px;border-radius:14px;text-decoration:none;font-weight:800;font-size:15px;text-align:center;padding:0 10px}
.go{background:var(--orange);color:#fff}
.wa{background:#1faa59;color:#fff}
.help{font-size:13px;color:#c9d3ea;text-align:center;margin:4px 0 0}
.help a{color:var(--gold)}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><img src="/assets/img/logo-mark-tm.png" alt="S Hari Global" width="44" height="30"><div><b>S HARI GLOBAL PVT. LTD.</b><small>एजेन्ट फारम भर्ने तरिका · How to apply</small></div></div>
  <div class="vbox">
    <video id="v" src="<?= $h($video) ?>" poster="<?= $h($thumb) ?>" autoplay muted playsinline controls preload="auto"></video>
    <button class="sound" id="snd" type="button">🔊 आवाज खोल्नुहोस् · Sound on</button>
  </div>
  <div class="acts">
    <a class="go" href="/join.php">📝 फारम भर्नुहोस् · Apply now</a>
    <a class="wa" href="https://wa.me/?text=<?= rawurlencode('एजेन्ट फारम भर्ने तरिका (नेपाली भिडियो): ' . $base . '/guide/') ?>">📤 WhatsApp मा सेयर</a>
  </div>
  <p class="help">मद्दत · Help: <a href="tel:<?= $h(preg_replace('/[^0-9+]/', '', $office) ?? '') ?>"><?= $h($office) ?></a></p>
</div>
<script>
(function () {
  var v = document.getElementById('v'), b = document.getElementById('snd');
  function on() { v.muted = false; v.volume = 1; b.hidden = true; var p = v.play(); if (p && p.catch) { p.catch(function () {}); } }
  b.addEventListener('click', on);
  v.addEventListener('volumechange', function () { if (!v.muted) { b.hidden = true; } });
  var p = v.play(); if (p && p.catch) { p.catch(function () { b.hidden = false; }); }
})();
</script>
</body>
</html>
