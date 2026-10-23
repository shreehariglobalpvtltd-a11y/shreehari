<?php
/**
 * admin/id-card.php?ref=STF-000005 | AGR-000035 — a person's digital identity
 * for the office (22 Oct 2026, owner: team portal; 23 Oct 2026: visiting
 * card, PDF, device logins, verification log).
 *
 *   ?ref=…                     the page: ID card + visiting card, Print, PNG /
 *                              PDF downloads, the public verify link, the
 *                              person's passkeys (Face ID / fingerprint devices,
 *                              with "forget"), the verification log
 *   ?ref=…&png=front|back|sheet         the ID card PNG      (&dl=1 to save)
 *   ?ref=…&v=front|back|sheet           the visiting card PNG (&dl=1 to save)
 *   ?ref=…&pdf=id|visiting|all          the print-ready A4 PDF
 *   POST action=passkey_remove id=…     forget one device (CSRF)
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
require_once INCLUDE_PATH . '/passkeys.php';
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

// who owns the passkeys: a staff card -> the staff row; an agent / partner card -> its linked account
$owner = null;
if ($p['kind'] === 'staff') {
    $owner = ['staff', (int) substr($p['ref'], 4)];
} else {
    $aid = (int) Database::scalar('SELECT admin_id FROM agent_applications WHERE ref = :r LIMIT 1', ['r' => $p['ref']], 0);
    $owner = $aid > 0 ? ['admin', $aid] : null;
}
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'This page was open too long — please try again.'];
    } elseif ((string) ($_POST['action'] ?? '') === 'passkey_remove' && $owner !== null) {
        $ok = Passkeys::remove($owner[0], $owner[1], (int) ($_POST['id'] ?? 0), 'the office (' . ($admin['username'] ?? '') . ')');
        $flash = $ok ? ['ok', 'Device login removed — the person signs in with the password (or adds the device again).'] : ['bad', 'Nothing removed.'];
    }
}

$side = (string) ($_GET['png'] ?? '');
$vis  = (string) ($_GET['v'] ?? '');
$pdfW = (string) ($_GET['pdf'] ?? '');
if (in_array($pdfW, ['id', 'visiting', 'all'], true)) {
    $bytes = IdCard::pdf($p, $pdfW);
    Logger::audit('idcard.print', $p['kind'], $p['ref'], null, ['pdf' => $pdfW], 'Card PDF made');
    header_remove('Set-Cookie');
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="SHG-' . ($pdfW === 'visiting' ? 'VisitingCard' : ($pdfW === 'id' ? 'ID' : 'Cards')) . '-' . $p['ref'] . '.pdf"');
    echo $bytes;
    exit;
}
$which = $side !== '' ? $side : $vis;
if (in_array($which, ['front', 'back', 'sheet'], true)) {
    $png = $vis !== '' ? IdCard::visitingPng($p, $which) : IdCard::png($p, $which);
    if ($which === 'sheet' || isset($_GET['dl'])) {
        Logger::audit('idcard.print', $p['kind'], $p['ref'], null, ['side' => $which, 'card' => $vis !== '' ? 'visiting' : 'id'], 'Card made');
    }
    header_remove('Set-Cookie');
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($png));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if (isset($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="SHG-' . ($vis !== '' ? 'VisitingCard' : 'ID') . '-' . $p['ref'] . '-' . $which . '.png"');
    }
    echo $png;
    exit;
}
$q = static fn(array $x): string => 'id-card.php?' . http_build_query(['ref' => $p['ref']] + $x);
$back = $p['kind'] === 'staff' ? 'staff-applications.php?id=' . (int) substr($p['ref'], 4) . '#detail' : 'agreements.php?tab=apps';
$keys = $owner !== null ? Passkeys::listFor($owner[0], $owner[1]) : [];
$log = [];
try {
    $log = Database::fetchAll(
        "SELECT action, actor_name, detail, new_value, created_at FROM audit_logs
          WHERE entity_id = :r AND action IN ('idcard.verify','idcard.print','idcard.self','team.login','team.login.passkey','team.passkey.add','team.passkey.remove','team.agreement.sign')
          ORDER BY id DESC LIMIT 40", ['r' => $p['ref']]);
} catch (Throwable $e) {
    $log = [];
}
if ($owner !== null && $owner[0] === 'admin') {
    try {
        $more = Database::fetchAll(
            "SELECT action, actor_name, detail, new_value, created_at FROM audit_logs
              WHERE entity_type = 'admin' AND entity_id = :i AND action IN ('team.login','team.login.passkey','team.passkey.add','team.passkey.remove')
              ORDER BY id DESC LIMIT 20", ['i' => (string) $owner[1]]);
        $log = array_merge($log, $more);
        usort($log, static fn(array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
    } catch (Throwable $e) {
    }
}
$verify = IdCard::verifyUrl($p['ref']);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Digital ID <?= $h($p['ref']) ?> · <?= $h($p['name']) ?></title>
<style>
body{margin:0;font-family:-apple-system,"Segoe UI",Roboto,"Noto Sans Devanagari",sans-serif;background:#eef2f8;color:#16233c}
.bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:14px 16px;background:#0a2a8f;color:#fff}
.bar b{flex:1 1 220px}
.bar a,.bar button{display:inline-flex;align-items:center;min-height:42px;padding:0 14px;border-radius:10px;border:0;font:inherit;font-weight:800;font-size:14px;text-decoration:none;cursor:pointer;background:#fff;color:#0a2a8f}
.bar .pr{background:#f07a1e;color:#fff}
.wrap{max-width:980px;margin:0 auto;padding:16px}
.box{background:#fff;border:1px solid #dfe5f0;border-radius:18px;padding:16px;margin:0 0 16px;box-shadow:0 12px 30px -24px rgba(10,42,143,.6)}
.box h2{margin:0 0 10px;font-size:17px}
.cards{display:flex;flex-wrap:wrap;gap:24px;justify-content:center}
.cards figure{margin:0;text-align:center}
.cards img.id{width:54mm;height:85.6mm;border-radius:3mm;box-shadow:0 10px 30px -12px rgba(10,42,143,.6);background:#fff}
.cards img.vc{width:89mm;height:51mm;border-radius:2mm;box-shadow:0 10px 30px -12px rgba(10,42,143,.6);background:#fff}
.cards figcaption{font-size:12px;color:#5c6b85;margin-top:6px}
.dl{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}
.dl a{display:inline-flex;align-items:center;min-height:38px;padding:0 12px;border-radius:10px;border:1.5px solid #c9d2e1;font-weight:700;font-size:13px;text-decoration:none;color:#0a2a8f;background:#fff}
.note{font-size:13px;color:#5c6b85;line-height:1.5;margin:10px 0 0}
table{width:100%;border-collapse:collapse;font-size:13.5px}
td,th{padding:7px 8px;border-bottom:1px solid #e4e9f1;text-align:left;vertical-align:top}
th{color:#5c6b85;font-weight:700;font-size:12px}
.tag{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11.5px;font-weight:800;background:#eef4fc;color:#0a2a8f}
.tag.ok{background:#e6f5ea;color:#12652b}.tag.bad{background:#fde8e6;color:#b3261e}
.flash{padding:10px 14px;border-radius:12px;font-weight:700;margin:0 0 14px}
.flash.ok{background:#e6f5ea;color:#12652b}.flash.bad{background:#fde8e6;color:#b3261e}
.rm{border:0;background:#fde8e6;color:#b3261e;border-radius:8px;padding:6px 10px;font:inherit;font-weight:800;font-size:12.5px;cursor:pointer}
@media screen and (min-width:900px){.cards img.id{width:70mm;height:111mm}.cards img.vc{width:120mm;height:68.8mm}}
@media print{@page{size:A4;margin:12mm}body{background:#fff}.bar,.note,.cards figcaption,.box.np,.dl,.flash{display:none}.box{border:0;box-shadow:none;padding:0}.cards{padding:0;gap:8mm;justify-content:flex-start}.cards img.id{width:54mm;height:85.6mm;box-shadow:none;border:0.2mm solid #ccd}.cards img.vc{width:89mm;height:51mm;box-shadow:none;border:0.2mm solid #ccd}}
</style>
</head>
<body>
<div class="bar">
  <b>🪪 <?= $h($p['name']) ?> · <?= $h($p['ref']) ?> · <?= $h($p['standing']) ?><?= (string) ($p['expires'] ?? '') !== '' ? ' · valid till ' . $h(date('d M Y', strtotime((string) $p['expires']) ?: time())) : '' ?></b>
  <button class="pr" type="button" onclick="window.print()">🖨️ Print (card size)</button>
  <a href="<?= $h($q(['pdf' => 'all'])) ?>">📄 PDF (ID + visiting)</a>
  <a href="<?= $h($verify) ?>" target="_blank" rel="noopener">🔍 Public profile / verify</a>
  <a href="<?= $h($back) ?>">← Back</a>
</div>
<div class="wrap">
  <?php if ($flash !== null): ?><div class="flash <?= $h($flash[0]) ?>"><?= $h($flash[1]) ?></div><?php endif; ?>
  <div class="box">
    <h2>Identity card · परिचयपत्र (CR80, 54 × 85.6 mm)</h2>
    <div class="cards">
      <figure><img class="id" src="<?= $h($q(['png' => 'front'])) ?>" alt="Front of the ID card"><figcaption>Front · अगाडि</figcaption></figure>
      <figure><img class="id" src="<?= $h($q(['png' => 'back'])) ?>" alt="Back of the ID card"><figcaption>Back · पछाडि</figcaption></figure>
    </div>
    <div class="dl"><a href="<?= $h($q(['png' => 'front', 'dl' => 1])) ?>">⬇ Front PNG</a><a href="<?= $h($q(['png' => 'back', 'dl' => 1])) ?>">⬇ Back PNG</a><a href="<?= $h($q(['png' => 'sheet', 'dl' => 1])) ?>">⬇ Both</a><a href="<?= $h($q(['pdf' => 'id'])) ?>">📄 ID card PDF</a></div>
  </div>
  <div class="box">
    <h2>Visiting card · भिजिटिङ कार्ड (89 × 51 mm)</h2>
    <div class="cards">
      <figure><img class="vc" src="<?= $h($q(['v' => 'front'])) ?>" alt="Front of the visiting card"><figcaption>Front</figcaption></figure>
      <figure><img class="vc" src="<?= $h($q(['v' => 'back'])) ?>" alt="Back of the visiting card"><figcaption>Back</figcaption></figure>
    </div>
    <div class="dl"><a href="<?= $h($q(['v' => 'front', 'dl' => 1])) ?>">⬇ Front PNG</a><a href="<?= $h($q(['v' => 'back', 'dl' => 1])) ?>">⬇ Back PNG</a><a href="<?= $h($q(['pdf' => 'visiting'])) ?>">📄 Visiting card PDF</a></div>
    <p class="note">Public contact on the card and on the verify page: <b><?= $p['public'] ? 'YES — the person agreed (WhatsApp, call' . ($p['email'] !== '' ? ', e-mail' : '') . ($p['facebook'] !== '' ? ', Facebook' : '') . ')' : 'NO — the number is masked; switch it on from the person\'s record (public listing / public fields)' ?></b>.</p>
  </div>
  <div class="box np">
    <h2>Device logins · Face ID / fingerprint (passkeys)</h2>
    <?php if ($owner === null): ?><p class="note">No login account is linked to this card yet, so no device can be registered.</p>
    <?php elseif ($keys === []): ?><p class="note">None yet. The person adds a device at /team/ → Face ID / fingerprint. Only the public key is stored here — never a fingerprint or face.</p>
    <?php else: ?>
    <table><tr><th>Device</th><th>Added</th><th>Last used</th><th></th></tr>
    <?php foreach ($keys as $k): ?>
      <tr><td><?= $h($k['label']) ?> <span class="tag"><?= $h($k['transports'] ?: 'platform') ?></span></td><td><?= $h($k['created_at']) ?></td><td><?= $h($k['last_used_at'] ?: '—') ?></td>
        <td><form method="post" action="<?= $h($q([])) ?>" onsubmit="return confirm('Forget this device? The person will sign in with the password.')"><?= Security::csrfField() ?><input type="hidden" name="action" value="passkey_remove"><input type="hidden" name="id" value="<?= (int) $k['id'] ?>"><button class="rm" type="submit">Forget</button></form></td></tr>
    <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
  <div class="box np">
    <h2>Verification log · जाँचको अभिलेख</h2>
    <?php if ($log === []): ?><p class="note">Nothing yet — QR scans of this card, cards printed, portal sign-ins and device changes appear here.</p>
    <?php else: ?>
    <table><tr><th>When</th><th>What</th><th>By</th><th>Detail</th></tr>
    <?php foreach (array_slice($log, 0, 40) as $r):
        $nv = json_decode((string) ($r['new_value'] ?? ''), true);
        $lbl = match ((string) $r['action']) {
            'idcard.verify' => isset($nv['valid']) && !$nv['valid'] ? '<span class="tag bad">QR scanned · not valid</span>' : '<span class="tag ok">QR scanned · genuine</span>',
            'idcard.print' => '<span class="tag">Card printed (office)</span>',
            'idcard.self' => '<span class="tag">Card saved (portal)</span>',
            'team.login' => '<span class="tag">Portal sign-in</span>',
            'team.login.passkey' => '<span class="tag ok">Sign-in · Face ID / fingerprint</span>',
            'team.passkey.add' => '<span class="tag ok">Device added</span>',
            'team.passkey.remove' => '<span class="tag bad">Device removed</span>',
            default => '<span class="tag">' . $h($r['action']) . '</span>',
        }; ?>
      <tr><td><?= $h($r['created_at']) ?></td><td><?= $lbl ?></td><td><?= $h($r['actor_name'] ?: 'public') ?></td><td><?= $h(is_array($nv) ? mb_substr((string) ($nv['ua'] ?? ($nv['label'] ?? ($nv['side'] ?? ($nv['pdf'] ?? '')))), 0, 60) : '') ?></td></tr>
    <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
  <p class="note">Prints at real size on A4 — cut along the edge, or send the PNGs / PDF to a card printer. The QR opens <?= $h(preg_replace('~^https?://~', '', appUrl('verify-id.php'))) ?>, which always shows the person's current standing<?= $p['valid'] ? '' : ' — this person is <b>not active</b> now, so the QR will say “not valid”' ?>. To revoke: suspend the staff record or switch the account off; the printed card then fails its QR check.</p>
</div>
</body>
</html>
