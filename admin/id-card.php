<?php
/**
 * admin/id-card.php?ref=STF-000005 | AGR-000035 — print an ID card
 * (22 Oct 2026, owner: team portal).
 *
 *   ?ref=…               a print page: front + back at card size
 *                        (54 x 85.6 mm), Print and Download buttons
 *   ?ref=…&png=front     the PNG of one side (back | sheet = both)
 *   &dl=1                as a download
 *
 * Who: a super-admin or a manager (the card carries the photo and the
 * mobile), each card made is written to the audit trail. Staff cards are
 * for approved / suspended / exited people (the QR then says "not valid"),
 * agent and partner cards for approved applications. The person prints
 * their own from the team portal.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/idcard.php';
$admin = admin_boot('');

$deny = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
};
if (!(Auth::isSuperadmin() || (string) ($admin['role'] ?? '') === 'manager')) {
    $deny(403, 'Only a super-admin or a manager may print ID cards.');
}
$ref = strtoupper(Security::clean((string) ($_GET['ref'] ?? ''), 20));
$p   = IdCard::person($ref);
if ($p === null) {
    $deny(404, 'No card for ' . $ref . ' — only approved staff, agents and partners get one.');
}
$h = static fn($v): string => Security::e((string) ($v ?? ''));

$side = (string) ($_GET['png'] ?? '');
if (in_array($side, ['front', 'back', 'sheet'], true)) {
    $png = IdCard::png($p, $side);
    if ($side === 'sheet' || isset($_GET['dl'])) {
        Logger::audit('idcard.print', $p['kind'], $p['ref'], null, ['side' => $side], 'ID card made');
    }
    header_remove('Set-Cookie');
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if (isset($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="SHG-ID-' . $p['ref'] . '-' . $side . '.png"');
    }
    echo $png;
    exit;
}
$q = static fn(array $x): string => 'id-card.php?' . http_build_query(['ref' => $p['ref']] + $x);
$back = $p['kind'] === 'staff' ? 'staff-applications.php?id=' . (int) substr($p['ref'], 4) . '#detail' : 'agreements.php?tab=apps';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>ID card <?= $h($p['ref']) ?> · <?= $h($p['name']) ?></title>
<style>
body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,"Noto Sans Devanagari",sans-serif;background:#eef2f8;color:#16233c}
.bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:14px 16px;background:#0a2a8f;color:#fff}
.bar b{flex:1 1 220px}
.bar a,.bar button{display:inline-flex;align-items:center;min-height:42px;padding:0 14px;border-radius:10px;border:0;font:inherit;font-weight:800;font-size:14px;text-decoration:none;cursor:pointer;background:#fff;color:#0a2a8f}
.bar .pr{background:#f07a1e;color:#fff}
.cards{display:flex;flex-wrap:wrap;gap:24px;justify-content:center;padding:24px 16px}
.cards figure{margin:0;text-align:center}
.cards img{width:54mm;height:85.6mm;border-radius:3mm;box-shadow:0 10px 30px -12px rgba(10,42,143,.6);background:#fff}
.cards figcaption{font-size:12px;color:#5c6b85;margin-top:6px}
.note{max-width:640px;margin:0 auto 24px;padding:0 16px;font-size:13px;color:#5c6b85;line-height:1.5}
@media screen and (min-width:700px){.cards img{width:81mm;height:128.4mm}}
@media print{@page{size:A4;margin:12mm}body{background:#fff}.bar,.note,.cards figcaption{display:none}.cards{padding:0;gap:8mm;justify-content:flex-start}.cards img{width:54mm;height:85.6mm;box-shadow:none;border:0.2mm solid #ccd}}
</style>
</head>
<body>
<div class="bar">
  <b>🪪 <?= $h($p['name']) ?> · <?= $h($p['ref']) ?> · <?= $h($p['standing']) ?></b>
  <button class="pr" type="button" onclick="window.print()">🖨️ Print (card size)</button>
  <a href="<?= $h($q(['png' => 'front', 'dl' => 1])) ?>">⬇ Front PNG</a>
  <a href="<?= $h($q(['png' => 'back', 'dl' => 1])) ?>">⬇ Back PNG</a>
  <a href="<?= $h($q(['png' => 'sheet', 'dl' => 1])) ?>">⬇ Both</a>
  <a href="<?= $h(IdCard::verifyUrl($p['ref'])) ?>" target="_blank" rel="noopener">🔍 Verify page</a>
  <a href="<?= $h($back) ?>">← Back</a>
</div>
<div class="cards">
  <figure><img src="<?= $h($q(['png' => 'front'])) ?>" alt="Front of the ID card"><figcaption>Front · अगाडि</figcaption></figure>
  <figure><img src="<?= $h($q(['png' => 'back'])) ?>" alt="Back of the ID card"><figcaption>Back · पछाडि</figcaption></figure>
</div>
<p class="note">Prints at real card size (54 × 85.6 mm, CR80) on A4 — cut along the edge, or send the PNGs to a PVC card printer. The QR opens <?= $h(preg_replace('~^https?://~', '', appUrl('verify-id.php'))) ?>, which always shows the person's current standing<?= $p['valid'] ? '' : ' — this person is <b>not active</b> now, so the QR will say “not valid”' ?>.</p>
</body>
</html>
