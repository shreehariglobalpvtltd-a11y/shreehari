<?php
/**
 * join.php — become an S Hari Global agent (or join the staff with an
 * office invitation link). Six short steps on a phone:
 *
 *   1 who  ·  2 WhatsApp (OTP, or send "JOIN 123456")  ·  3 ID photo → the
 *   form fills itself  ·  4 your photo (this is your ID card photo)  ·
 *   5 bank / UPI (optional)  ·  6 extra documents, review, send
 *
 * The office approves with one tap in Admin → Team. Drafts survive a lost
 * connection (the key lives in this browser). See includes/agentpro.php.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';

Security::sendHeaders();
$invite = preg_match('/^[A-Za-z0-9_-]{24}$/', (string) ($_GET['invite'] ?? '')) ? (string) $_GET['invite'] : '';
$inv = $invite !== '' && AgentPro::available() ? AgentPro::invite($invite) : null;
$company = Settings::getString('company_name', APP_NAME);
$v = static fn(string $f): string => '/assets/agentpro/' . $f . '?v=' . (is_file(ASSET_PATH . '/agentpro/' . $f) ? filemtime(ASSET_PATH . '/agentpro/' . $f) : '1');
$cfg = [
    'api'      => '/api/agentpro.php',
    'csrf'     => Security::csrfToken(),
    'invite'   => $invite,
    'inviteOk' => $inv !== null,
    'role'     => $inv['role'] ?? '',
    'designation' => (string) ($inv['designation'] ?? ''),
    'open'     => AgentPro::available() && (Settings::getBool('ap_join_open', true) || $inv !== null),
    'company'  => $company,
    'wa'       => Settings::bookingWhatsApp(),
    'office'   => Settings::officePhone(),
    'idTypes'  => AgentPro::ID_TYPES,
    'maxExtras'=> max(1, Settings::getInt('ap_join_max_extras', 6)),
    'jsqr'     => $v('vendor/jsQR.js'),
    'me'       => '/me/',
];
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Join <?= Security::e($company) ?></title>
<meta name="description" content="Become a ticketing agent of <?= Security::e($company) ?> — India ⇄ Nepal bus service.">
<meta name="theme-color" content="#13224F">
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" href="/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= Security::e($v('ap.css')) ?>">
</head>
<body>
<header class="ap-top">
  <div class="ap-top-in">
    <img class="ap-crest" src="/assets/agentpro/crest-dark.svg" alt="" width="42" height="52">
    <div class="ap-brand"><b>S HARI GLOBAL</b><small>Private Limited</small></div>
    <div class="ap-top-actions">
      <div class="ap-lang" role="group" aria-label="Language">
        <button type="button" data-lang="ne">नेपाली</button><button type="button" data-lang="hi">हिंदी</button><button type="button" data-lang="en">EN</button>
      </div>
    </div>
  </div>
  <div class="ap-hero"><h1 data-t="heroTitle">Become an agent</h1><p data-t="heroSub">5 minutes · your phone is enough</p></div>
</header>

<main class="ap-wrap" id="app" aria-live="polite">
  <noscript><div class="ap-card"><b>Please turn on JavaScript</b> — or call <?= Security::e(Settings::officePhone()) ?>.</div></noscript>
  <div class="ap-card" id="booting"><div class="ap-row"><span class="ap-spin" style="border-color:var(--line-2);border-top-color:var(--royal)"></span><span data-t="loading">Loading…</span></div></div>
</main>

<div class="ap-bar" id="bar" hidden><div class="ap-bar-in">
  <button type="button" class="ap-btn ghost" id="back" data-t="back">Back</button>
  <button type="button" class="ap-btn" id="next" data-t="next">Continue</button>
</div></div>
<div class="ap-toast" id="toast" role="status"></div>

<script>window.APJOIN = <?= Security::jsonForHtml($cfg) ?>;</script>
<script src="<?= Security::e($v('join.js')) ?>" defer></script>
</body>
</html>
