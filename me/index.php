<?php
/**
 * me/ — every member's own corner, on the phone (owner: "mobile ma afno
 * ID, visiting card, docs sabai dekhine; photo update garna milne").
 *
 *   ID card (flip, download, print, verify link) · visiting card (share
 *   on WhatsApp, the number it prints, theme) · wallet (balance, withdraw,
 *   pay the company by UPI, passbook, PDF statement) · documents · profile,
 *   photo, payout details, fingerprint sign-in.
 *
 * Signed out: agent code + mobile + WhatsApp OTP, or fingerprint / face
 * (passkey). Office staff use their normal admin sign-in.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';

Security::sendHeaders();
$admin = Auth::admin();
$company = Settings::getString('company_name', APP_NAME);
$v = static fn(string $f): string => '/assets/agentpro/' . $f . '?v=' . (is_file(ASSET_PATH . '/agentpro/' . $f) ? filemtime(ASSET_PATH . '/agentpro/' . $f) : '1');
$cfg = [
    'api'    => '/api/agentpro.php',
    'csrf'   => Security::csrfToken(),
    'signed' => $admin !== null,
    'ready'  => AgentPro::available(),
    'company'=> $company,
    'office' => Settings::officePhone(),
    'staffLogin' => '/admin/login.php',
    'join'   => '/join.php',
    'admin'  => $admin !== null && Auth::can('dashboard.view') ? '/admin/index.php' : '',
];
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>My ID · <?= Security::e($company) ?></title>
<meta name="theme-color" content="#13224F">
<meta name="robots" content="noindex">
<link rel="manifest" href="/me/manifest.webmanifest">
<link rel="icon" type="image/png" href="/assets/img/favicon-32.png">
<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
<link rel="stylesheet" href="<?= Security::e($v('ap.css')) ?>">
</head>
<body>
<header class="ap-top">
  <div class="ap-top-in">
    <?php /* 31 Oct 2026 (owner: "top ma logo chhaina"): the company logo, the one the ID card prints */ ?>
    <picture><source srcset="/assets/img/logo-mark-96h-tm.webp" type="image/webp"><img class="ap-logo" src="/assets/img/logo-mark-96h-tm.png" alt="S Hari Global" width="92" height="48" style="height:48px;width:auto;flex:0 0 auto;border-radius:10px;background:#fff;padding:3px 6px"></picture>
    <div class="ap-brand"><b>S HARI GLOBAL</b><small id="who">Private Limited</small></div>
    <div class="ap-top-actions">
      <div class="ap-lang" role="group" aria-label="Language">
        <button type="button" data-lang="ne">ने</button><button type="button" data-lang="hi">हि</button><button type="button" data-lang="en">EN</button>
      </div>
    </div>
  </div>
  <div class="ap-hero"><h1 id="heroT">&nbsp;</h1><p id="heroS">&nbsp;</p></div>
</header>
<nav class="ap-tabbar" id="tabs" role="tablist" hidden></nav>
<main class="ap-wrap" id="app" aria-live="polite">
  <noscript><div class="ap-card">Please turn on JavaScript.</div></noscript>
</main>
<div class="ap-sheet" id="sheet" aria-hidden="true"><div class="scrim"></div><div class="panel" role="dialog" aria-modal="true"><div class="grab"></div><div id="sheetBody"></div></div></div>
<div class="ap-toast" id="toast" role="status"></div>
<script>window.APME = <?= Security::jsonForHtml($cfg) ?>;</script>
<script src="<?= Security::e($v('me.js')) ?>" defer></script>
</body>
</html>
