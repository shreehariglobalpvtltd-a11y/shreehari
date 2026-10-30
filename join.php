<?php
/**
 * join.php — become an S Hari Global agent (or join the staff with an
 * office invitation link). Six short steps on a phone:
 *
 *   1 who  ·  2 WhatsApp (OTP, or send "JOIN 123456")  ·  3 ID photo → the
 *   form fills itself (or the hand-filled PAPER form, photos / PDF → AI)  ·
 *   4 your photo (live selfie or a passport photo) + thumb print  ·
 *   5 bank / UPI (optional)  ·  6 extra documents, review, send
 *
 * The office approves with one tap in Admin → Team. Drafts survive a lost
 * connection (the key lives in this browser) and open on any phone with the
 * "continue later" link /join.php?d=<key>. See includes/agentpro.php.
 *
 * OFFICE MODE (30 Oct 2026, owner: "admin/office le fast bharna milos"):
 *   /join.php?office=1            an office user fills it for someone — only
 *                                 name + mobile (+ role) needed (30 Oct: ID,
 *                                 photos, bank "पछि थप्न सकिन्छ · add later");
 *                                 WhatsApp check may be skipped with a reason;
 *                                 "Approve now" issues the login + code at once
 *                                 (no ID photo → KYC stays "submitted");
 *                                 a mobile that already has logins may carry
 *                                 another ID once the office says so (max
 *                                 ap_phone_max_accounts, default 3)
 *   /join.php?from=apply&kind=…   the OLD agreements/apply.html sends everyone
 *                                 here; an office user → ?office=1&role=…
 *   /join.php?office=1&d=<key>    reopen any draft / sent form to finish it
 *   Needs an admin session: superadmin, manager or staff.manage.
 *
 *   /join.php?office=1&role=driver  start with that role (Team → quick add); otherwise the
 *                                 role of the last office form on this browser
 *   Drivers / conductors (30 Oct 2026) also get the driver app (driver/) at approve.
 *
 * ?as=org|person preselects agent / agency (agreements/apply.html sends it);
 * ?name=&phone=&cc= (an office invitation, admin/team-invite.php) prefill a new form.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';

Security::sendHeaders();
$invite = preg_match('/^[A-Za-z0-9_-]{24}$/', (string) ($_GET['invite'] ?? '')) ? (string) $_GET['invite'] : '';
$inv = $invite !== '' && AgentPro::available() ? AgentPro::invite($invite) : null;
$resume = preg_match('/^[a-f0-9]{32}$/', (string) ($_GET['d'] ?? '')) === 1 ? (string) $_GET['d'] : '';
$as = in_array((string) ($_GET['as'] ?? ''), ['org', 'person'], true) ? (string) $_GET['as'] : '';
$co = Settings::company();
$company = (string) $co['name'];

/* ---- office mode ---- */
$officeAsked = (string) ($_GET['office'] ?? '') === '1';

/* 30 Oct 2026 (owner: the OLD one-page form failed with "That is too large to send"): agreements/apply.html
   now sends everyone here as /join.php?from=apply&kind=…&name=&phone=&cc= — a signed-in office user (the
   "Admin filling on behalf" case) goes on to the office form with the role that kind meant. */
if (!$officeAsked && (string) ($_GET['from'] ?? '') === 'apply' && AgentPro::available()) {
    $kindIn = strtolower((string) ($_GET['kind'] ?? ''));
    if (AgentPro::officeAllowed(Auth::admin())) {
        $roleFor = ['agent' => 'agent:person', 'ind' => 'agent:person', 'subagent' => 'agent:person', 'partner' => 'agent:org',
                    'org' => 'agent:org', 'office' => 'counter', 'driver' => 'driver'][$kindIn] ?? '';
        $q = ['office' => '1'] + ($roleFor !== '' ? ['role' => $roleFor] : []);
        foreach (['name', 'phone', 'cc'] as $p) {
            $pv = $_GET[$p] ?? '';
            if (is_string($pv) && trim($pv) !== '') {
                $q[$p] = mb_substr(trim($pv), 0, 120);
            }
        }
        header('Cache-Control: no-store');
        header('Location: /join.php?' . http_build_query($q), true, 302);
        exit;
    }
    if ($as === '' && in_array($kindIn, ['partner', 'org'], true)) {
        $as = 'org';
    } elseif ($as === '' && in_array($kindIn, ['agent', 'ind', 'subagent'], true)) {
        $as = 'person';
    }
}
$officeAdmin = $officeAsked ? Auth::admin() : null;
if ($officeAsked && !AgentPro::officeAllowed($officeAdmin)) {
    http_response_code($officeAdmin === null ? 401 : 403);
    ?><!doctype html><html lang="ne"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Office form · <?= Security::e($company) ?></title><meta name="robots" content="noindex">
<link rel="stylesheet" href="/assets/agentpro/ap.css"></head><body><main class="ap-wrap"><div class="ap-card" style="margin-top:40px">
<h2>🏢 अफिस फारम · Office form</h2>
<?php $tempPw = $officeAdmin !== null && AgentPro::mustChangePw($officeAdmin); ?>
<p class="lead"><?= $officeAdmin === null
    ? 'पहिले अफिस खाताबाट लगइन गर्नुहोस् · Sign in with your office account first, then open this link again.'
    : ($tempPw
        ? 'पहिले अस्थायी पासवर्ड बदल्नुहोस् · Change your temporary password first, then open this link again.'
        : 'यो फारम म्यानेजर वा "staff.manage" अनुमति भएकाले मात्र भर्न सक्छन् · Only a manager or a user with staff permission can fill the office form.') ?></p>
<div class="ap-actions"><?php if ($officeAdmin === null): ?><a class="ap-btn" href="/admin/login.php?next=team.php">🔐 Office sign-in</a><?php elseif ($tempPw): ?><a class="ap-btn" href="/admin/change-password.php">🔐 पासवर्ड बदल्नुहोस् · Change password</a><?php endif; ?>
<a class="ap-btn ghost" href="/join.php">Public form</a></div></div></main></body></html><?php
    exit;
}
$office = $officeAdmin !== null;

/* What the office may make (K1): agent person / agency, or a staff role. */
$roles = [
    ['v' => 'agent:person', 'label' => 'एजेन्ट · Agent (person)'],
    ['v' => 'agent:org',    'label' => 'एजेन्सी · Agency / Pvt. Ltd.'],
    ['v' => 'counter',      'label' => 'काउन्टर स्टाफ · Counter staff'],
    ['v' => 'manager',      'label' => 'म्यानेजर · Manager'],
    ['v' => 'accountant',   'label' => 'लेखा · Accountant'],
    ['v' => 'support',      'label' => 'ग्राहक सेवा · Customer support'],
    ['v' => 'scanner',      'label' => 'बोर्डिङ स्टाफ · Boarding staff'],
    ['v' => 'official',     'label' => 'अधिकारी · Official'],
    ['v' => 'driver',       'label' => '🚌 ड्राइभर · Driver'],
    ['v' => 'conductor',    'label' => '🎫 कन्डक्टर · Conductor'],
];
// KYC "saw the original ID" and the manager / accountant logins: super-admin or manager only (as admin/team.php)
$officeKyc = $office && AgentPro::officeCanKyc($officeAdmin);
if ($office && !$officeKyc) {
    $roles = array_values(array_filter($roles, static fn(array $r): bool => !in_array($r['v'], AgentPro::OFFICE_SENIOR_ROLES, true)));
}

/* Prefill from an invitation link (admin/team-invite.php → agreements/apply.html?kind=&name=&phone=&cc=). */
$pfName = mb_substr(trim(Security::clean((string) ($_GET['name'] ?? ''), 120)), 0, 120);
$pfPhone = substr(preg_replace('/\D/', '', (string) ($_GET['phone'] ?? '')) ?? '', -10);
$pfCc = preg_replace('/\D/', '', (string) ($_GET['cc'] ?? '')) === '977' ? 'NP' : 'IN';

$v = static fn(string $f): string => '/assets/agentpro/' . $f . '?v=' . (is_file(ASSET_PATH . '/agentpro/' . $f) ? filemtime(ASSET_PATH . '/agentpro/' . $f) : '1');

/* The most ONE upload may be: our own cap and what PHP itself takes (upload_max_filesize, post_max_size
   less room for the other fields) — join.js shrinks photos well below it and refuses a bigger PDF. */
$iniBytes = static function (string $k): int {
    $s = trim((string) ini_get($k));
    if ($s === '' || $s === '0' || $s === '-1') {
        return PHP_INT_MAX;
    }
    $n = (int) $s;
    return match (strtolower(substr($s, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
};
$maxUpload = max(500_000, min(MAX_UPLOAD_BYTES, $iniBytes('upload_max_filesize'), $iniBytes('post_max_size') - 64_000));
$cfg = [
    'api'      => '/api/agentpro.php',
    'csrf'     => Security::csrfToken(),
    'invite'   => $invite,
    'inviteOk' => $inv !== null,
    'role'     => $inv['role'] ?? '',
    'designation' => (string) ($inv['designation'] ?? ''),
    'open'     => AgentPro::available() && ($office || Settings::getBool('ap_join_open', true) || $inv !== null || $resume !== ''),
    'company'  => $company,
    'legal'    => (string) $co['legal'],
    'cin'      => (string) $co['cin'],
    'logo'     => '/assets/img/logo-mark-tm.png',
    'wa'       => Settings::bookingWhatsApp(),
    'office'   => Settings::officePhone(),
    'idTypes'  => AgentPro::ID_TYPES,
    'maxExtras'=> max(1, Settings::getInt('ap_join_max_extras', 6)),
    'maxUpload'=> $maxUpload,
    'jsqr'     => $v('vendor/jsQR.js'),
    'pdfjs'    => '/assets/vendor/pdfjs/pdf.min.js',
    'pdfWorker'=> '/assets/vendor/pdfjs/pdf.worker.min.js',
    'blankForm'=> '/agreements/form.php?kind=' . (($inv !== null && $inv['role'] !== 'agent') ? 'staff' : 'agent'),
    'me'       => '/me/',
    'resume'   => $resume,
    'as'       => $as,
    'officeMode' => $office,
    'officeBy' => $office ? (string) ($officeAdmin['full_name'] ?? $officeAdmin['username'] ?? '') : '',
    'roles'    => $office ? $roles : [],
    // ?role=driver (Team → quick add): the office form starts with this role
    'roleWanted' => $office && in_array((string) ($_GET['role'] ?? ''), array_column($roles, 'v'), true) ? (string) $_GET['role'] : '',
    'canKyc'   => $officeKyc,
    'prefill'  => ['name' => $pfName, 'phone' => strlen($pfPhone) >= 8 ? $pfPhone : '', 'cc' => $pfCc],
];
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $office ? 'Office form · ' : 'Join ' ?><?= Security::e($company) ?></title>
<meta name="description" content="Become a ticketing agent of <?= Security::e($company) ?> — India ⇄ Nepal bus service.">
<meta name="theme-color" content="#13224F">
<meta name="robots" content="noindex">
<link rel="icon" type="image/png" href="/assets/img/favicon-32.png">
<link rel="stylesheet" href="<?= Security::e($v('ap.css')) ?>">
</head>
<body<?= $office ? ' class="ap-officemode"' : '' ?>>
<header class="ap-top">
  <div class="ap-top-in ap-top-join">
    <picture class="ap-logo">
      <source srcset="/assets/img/logo-mark-tm.webp" type="image/webp">
      <img src="/assets/img/logo-mark-tm.png" alt="<?= Security::e($company) ?> logo" width="48" height="48">
    </picture>
    <div class="ap-brand ap-brand-join">
      <b><?= Security::e(mb_strtoupper((string) $co['legal'])) ?></b>
      <small class="ap-cin">CIN <?= Security::e((string) $co['cin']) ?> · ☎ <a href="tel:<?= Security::e(preg_replace('/[^\d+]/', '', Settings::officePhone()) ?? '') ?>"><?= Security::e(Settings::officePhone()) ?></a></small>
    </div>
    <div class="ap-top-actions">
      <div class="ap-lang" role="group" aria-label="Language">
        <button type="button" data-lang="ne">नेपाली</button><button type="button" data-lang="hi">हिंदी</button><button type="button" data-lang="en">EN</button>
      </div>
    </div>
  </div>
  <div class="ap-hero"><h1 data-t="heroTitle">Become an agent</h1><p data-t="heroSub">5 minutes · your phone is enough</p></div>
<?php if ($office): ?>
  <div class="ap-officebar" role="note">
    <span>🏢 <b data-t="officeMode">Office mode</b> · <?= Security::e($cfg['officeBy']) ?></span>
    <span class="ap-officebar-a"><a href="/admin/team.php?tab=approvals">Team</a><button type="button" id="officeNew" data-t="officeNew">New form</button></span>
  </div>
<?php endif; ?>
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
<script src="<?= Security::e($v('shrink.js')) ?>" defer></script>
<script src="<?= Security::e($v('join.js')) ?>" defer></script>
</body>
</html>
