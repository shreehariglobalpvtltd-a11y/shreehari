<?php
/**
 * Standalone refund & cancellation policy page.
 * Server-rendered so search engines can crawl it without JS.
 */
declare(strict_types=1);
if (!defined('SHG_APP')) { define('SHG_APP', true); }   // also served by index.php at /refund-policy
require_once __DIR__ . '/includes/bootstrap.php';

$baseUrl = rtrim(APP_URL, '/');
$e = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

/* The policy's real revision date (8 Oct 2026, D3-17). It printed date()
   — always "today" — so a legal page had no knowable effective date.
   Change this line, by hand, whenever the wording below changes. */
if (!defined('POLICY_REVISED')) { define('POLICY_REVISED', '8 October 2026'); }

/* 9 Oct 2026 (site facts): the company name, phones, email and CIN come from
   Admin (includes/sitefacts.php), the same values the tickets print - no
   typed copy on a legal page to drift. */
require_once INCLUDE_PATH . '/sitefacts.php';
$F       = SiteFacts::all();
$coName  = (string) $F['brand']['name'];
$coShort = (string) $F['brand']['short'];
$telHead = (string) $F['head']['tel'];
$telHelp = (string) $F['support']['tel'];

// D8 (15 Oct 2026): the one policy - Fare::refundSlabs() is what cancellations are priced with.
$slabs      = Fare::refundSlabs();
$policyLine = Fare::refundPolicyLine('en');
$refundDays = Fare::refundDays();

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Refund &amp; Cancellation Policy — <?= $e($coName) ?></title>
<meta name="description" content="Refund and cancellation policy for <?= $e($coShort) ?> bus tickets. <?= $e($policyLine) ?> Full details on rescheduling, no-show rules, and operator-initiated cancellations.">
<meta property="og:title" content="Refund &amp; Cancellation Policy — <?= $e($coShort) ?>">
<meta property="og:description" content="Bus ticket cancellation policy: <?= $e($policyLine) ?>">
<meta property="og:type" content="website">
<meta property="og:url" content="<?= $e($baseUrl) ?>/refund-policy">
<meta property="og:image" content="<?= $e($baseUrl) ?>/assets/img/og-share.png">
<link rel="canonical" href="<?= $e($baseUrl) ?>/refund-policy">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png">
<link rel="icon" type="image/svg+xml" href="/icon-shg.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;800&display=swap" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;800&display=swap"></noscript>
<script>/* The app's saved theme before the first frame (8 Oct 2026): localStorage 'shg:theme' = light | dark | amoled, else the phone's setting. */
(function(){try{var d=document.documentElement,t=(localStorage.getItem('shg:theme')||'').replace(/"/g,'');if(t!=='light'&&t!=='dark'&&t!=='amoled')t=matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';d.setAttribute('data-theme',t==='light'?'light':'dark');if(t==='amoled')d.setAttribute('data-display','amoled')}catch(e){}})();</script>
<style>
:root { --navy: #0E1E40; --mid: #1A3A6A; --gold: #F07C1F; --bg: #F8F9FB; --card: #fff; --text: #1a1a2e; --muted: #5B6B85; --border: #e2e6ee; }
:root[data-theme="dark"] { color-scheme: dark; }
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) { --bg: #0B1424; --card: #162338; --text: #E8ECF4; --muted: #8899B0; --border: #253550; --navy: #0B1424; }
}
:root[data-theme="dark"] { --bg: #0B1424; --card: #162338; --text: #E8ECF4; --muted: #8899B0; --border: #253550; --navy: #0B1424; }
/* AMOLED (8 Oct 2026): pure-black ground, after the dark block so it wins. */
:root[data-display="amoled"] { --bg: #000; --card: #101822; --text: #EEF4FF; --muted: #ACBBD0; --border: #2C3D55; --navy: #050B16; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); line-height: 1.7; }
.header { background: var(--navy); color: #fff; padding: 20px 0; text-align: center; }
.header a { color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 10px; }
.header img { display: block; height: 40px; width: auto; filter: drop-shadow(0 1px 2px rgba(0,0,0,.35)); }   /* the transparent emblem - no white tile (9 Oct 2026) */
.header picture { display: block; line-height: 0; }
.header .brandname { font-family: 'Cinzel', Georgia, serif; font-size: clamp(14px, 3vw, 18px); font-weight: 700; }
.container { max-width: 800px; margin: 0 auto; padding: 32px 16px 64px; }
h1.page-title, h2 { font-family: 'Cinzel', Georgia, serif; font-size: 28px; font-weight: 800; color: var(--text); margin-bottom: 8px; }
.subtitle { color: var(--muted); font-size: 15px; margin-bottom: 32px; }
.card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 24px; }
.card h3 { font-size: 18px; font-weight: 700; margin-bottom: 12px; color: var(--text); }
.card p, .card li { font-size: 15px; color: var(--text); }
.card ul { padding-left: 20px; }
.card li { margin-bottom: 8px; }
.slab-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
.slab-table th, .slab-table td { padding: 12px 16px; text-align: left; border-bottom: 1px solid var(--border); font-size: 15px; }
.slab-table th { background: var(--navy); color: #fff; font-weight: 700; }
.slab-table tr:last-child td { border-bottom: none; }
.slab-table .pct { font-weight: 700; color: var(--gold); font-size: 18px; }
.slab-table .no-refund { color: #C62828; font-weight: 700; }
.highlight { background: linear-gradient(135deg, rgba(240,124,31,0.08), rgba(26,58,106,0.05)); border-color: rgba(240,124,31,0.3); }
.note { font-size: 13px; color: var(--muted); margin-top: 16px; padding-top: 12px; border-top: 1px solid var(--border); }
.back { display: inline-flex; align-items: center; gap: 6px; color: var(--gold); text-decoration: none; font-weight: 600; font-size: 14px; margin-bottom: 24px; }
.back:hover { text-decoration: underline; }
.footer { background: var(--navy); color: var(--muted); text-align: center; padding: 24px 16px; font-size: 13px; }
.footer a { color: var(--gold); text-decoration: none; }
</style>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": "Refund & Cancellation Policy",
  "description": <?= json_encode('Refund and cancellation policy for ' . $coShort . ' bus tickets between Gujarat and Rupaidiha (India-Nepal).', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>,
  "publisher": { "@type": "Organization", "name": <?= json_encode($coName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?> },
  "breadcrumb": {
    "@type": "BreadcrumbList",
    "itemListElement": [
      { "@type": "ListItem", "position": 1, "name": "Home", "item": "<?= $e($baseUrl) ?>/" },
      { "@type": "ListItem", "position": 2, "name": "Refund Policy", "item": "<?= $e($baseUrl) ?>/refund-policy" }
    ]
  }
}
</script>
</head>
<body>
<div class="header">
  <a href="/">
    <picture><source srcset="/assets/img/logo-mark-96h.webp" type="image/webp"><img src="/assets/img/logo-mark-96h.png" alt="" width="77" height="40" loading="eager"></picture>
    <span class="brandname"><?= $e($coName) ?></span>
  </a>
</div>
<div class="container">
  <a href="/" class="back">← Back to Home</a>
  <h1 class="page-title">Refund &amp; Cancellation Policy</h1>
  <p class="subtitle">Last updated: <?= $e(POLICY_REVISED) ?> · Applies to all Gujarat ⇄ Rupaidiha bus tickets</p>

  <div class="card highlight">
    <h3>Refund Slabs</h3>
    <p>Refund percentage depends on how far in advance of departure you cancel:</p>
    <table class="slab-table">
      <thead><tr><th>Cancellation Window</th><th>Refund</th></tr></thead>
      <tbody>
<?php
$prev = null;
foreach ($slabs as $s) {
    $hrs = (int) ($s['minHrs'] ?? 0);
    $pct = (int) ($s['pct'] ?? 0);
    if ($prev === null) {
        $label = $hrs . '+ hours before departure';
    } else {
        $label = $hrs . '–' . $prev . ' hours before departure';
    }
    if ($pct > 0) {
        echo "        <tr><td>{$label}</td><td class=\"pct\">{$pct}%</td></tr>\n";
    } else {
        echo "        <tr><td>Under {$prev} hours / No-show</td><td class=\"no-refund\">No refund</td></tr>\n";
    }
    $prev = $hrs;
}
?>
      </tbody>
    </table>
    <p class="note">Refund percentages are calculated on the base fare. Platform/service fees may not be refundable.</p>
  </div>

  <div class="card">
    <h3>How to Cancel</h3>
    <ul>
      <li><b>Online:</b> Go to <a href="/#/my" style="color:var(--gold)">My Bookings</a>, find your ticket, and tap "Cancel".</li>
      <li><b>WhatsApp:</b> Send your PNR (SHG-XXXX) to <a href="https://wa.me/<?= $e($telHelp) ?>" style="color:var(--gold)"><?= $e($F['support']['phone']) ?></a>.</li>
      <li><b>Phone:</b> Call <a href="tel:+<?= $e($telHead) ?>" style="color:var(--gold)"><?= $e($F['head']['phone']) ?></a> (Mon–Sat, 9 AM–8 PM IST).</li>
    </ul>
  </div>

  <div class="card">
    <h3>Refund Processing</h3>
    <ul>
      <li>Refunds are sent to the <b>same account/method</b> used for payment.</li>
      <li>INR and NPR / eSewa payments: refund within <b><?= $e($refundDays) ?> working days</b>.</li>
      <li>Cash payments: refund at the booking counter or via bank transfer.</li>
    </ul>
  </div>

  <div class="card">
    <h3>Rescheduling</h3>
    <ul>
      <li>One free reschedule allowed per ticket if done <b>24+ hours before departure</b>.</li>
      <li>Rescheduling to a different date is subject to seat availability.</li>
      <li>Fare difference (if any) is charged at the time of rescheduling.</li>
      <li>Second reschedule is treated as a cancellation + new booking.</li>
    </ul>
  </div>

  <div class="card">
    <h3>Operator-Initiated Cancellation</h3>
    <ul>
      <li>If <?= $e($coShort) ?> cancels a service (mechanical, weather, force majeure), you receive a <b>100% refund</b> or a free seat on the next available bus.</li>
      <li>We are not liable for delays caused by border/immigration, road blocks, or natural events.</li>
    </ul>
  </div>

  <div class="card">
    <h3>No-Show Policy</h3>
    <p>If you do not board the bus and have not cancelled, <b>no refund is applicable</b>. Please cancel in advance to receive the applicable refund.</p>
  </div>

  <div class="card">
    <h3>Contact for Refund Queries</h3>
    <ul>
      <li>Email: <a href="mailto:<?= $e($F['brand']['email']) ?>" style="color:var(--gold)"><?= $e($F['brand']['email']) ?></a></li>
      <li>WhatsApp: <a href="https://wa.me/<?= $e($telHelp) ?>" style="color:var(--gold)"><?= $e($F['support']['phone']) ?></a> (24×7)</li>
      <li>Phone: <a href="tel:+<?= $e($telHead) ?>" style="color:var(--gold)"><?= $e($F['head']['phone']) ?></a></li>
    </ul>
    <p class="note">For complete terms including luggage, conduct, and border rules, see our <a href="/#/terms" style="color:var(--gold)">Terms & Conditions</a>.</p>
  </div>
</div>
<div class="footer">
  © <?= date('Y') ?> <?= $e($coName) ?><?= $F['brand']['cin'] !== '' ? ' · CIN: ' . $e($F['brand']['cin']) : '' ?> ·
  <a href="/refund-policy">Refund Policy</a> ·
  <a href="/#/terms">Terms</a> ·
  <a href="/">Home</a>
</div>
</body>
</html>
