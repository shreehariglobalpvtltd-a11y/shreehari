<?php
/**
 * api/agentpro-card.php — a card side as PNG or SVG.
 *
 *   ?m=<admin id>&s=id-front|id-back|vc-front|vc-back&f=png|svg
 *       signed:  &e=<expiry>&k=<hmac>     (WhatsApp fetches these; ApCard::signedUrl)
 *       or the member themself / office staff, signed in
 *   ?u=<card token>&s=vc-front|vc-back      the visiting card is public by design (it is
 *                                           meant to be handed out); the ID card is not
 *   ?pay=<amount>&m=<id>&e=&k=              a framed UPI QR for paying the company that amount
 *
 *   &dl=1  download instead of showing
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro-card.php';

$deny = static function (int $code, string $msg): never {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($msg);
};

try {
    Security::requireRateLimit('apcard_ip', Security::clientIp(), 120, 60);
} catch (Throwable $e) {
    $deny(429, 'Too many requests.');
}
if (!AgentPro::available()) {
    $deny(404, 'Not installed.');
}

$side = (string) ($_GET['s'] ?? 'id-front');
$fmt  = ($_GET['f'] ?? 'png') === 'svg' ? 'svg' : 'png';
$id   = (int) ($_GET['m'] ?? 0);
$exp  = (int) ($_GET['e'] ?? 0);
$key  = (string) ($_GET['k'] ?? '');

/* ---- the pay-in QR ---- */
if (isset($_GET['pay'])) {
    $amount = round((float) $_GET['pay'], 2);
    if ($id <= 0 || $amount <= 0 || !ApCard::checkSig($id, 'pay' . number_format($amount, 2, '.', ''), $exp, $key)) {
        $deny(403, 'This link has expired.');
    }
    $upi = Settings::getString('upi_id', '');
    if ($upi === '') {
        $deny(404, 'No UPI ID is set.');
    }
    $m = AgentPro::member($id, false);
    $link = upiLink($upi, Settings::getString('upi_name', APP_NAME), $amount, 'SHG ' . ($m['idNo'] ?? ''));
    $s = new ApScene(600, 760, 30);
    $s->rect(0, 0, 600, 760, '#FBF9F3');
    $s->rect(0, 0, 600, 120, '#13224F');
    $s->rect(0, 120, 600, 3, '#C9973A');
    $s->text(300, 62, 'Pay ' . inr($amount), 'sansB', 40, '#FFFFFF', 'middle', 0.5);
    $s->text(300, 98, Settings::getString('upi_name', APP_NAME), 'caps', 15, '#E9C77B', 'middle', 2.5, 1.0, 540);
    $s->rect(80, 160, 440, 440, '#FFFFFF', 24, ['stroke' => '#C9973A', 'sw' => 2.4]);
    $s->qr(116, 196, 368, ApCard::safeQr([$link, upiLink($upi, 'SHG', $amount)])[0]);
    $s->text(300, 650, 'UPI · ' . $upi, 'mono', 18, '#0F1A36', 'middle', 0.4);
    $s->text(300, 690, 'GPay · PhonePe · Paytm · BHIM', 'sans', 17, '#5D6680', 'middle');
    $s->text(300, 724, 'After paying, send: jama ' . (int) $amount . ' UTR <number>', 'sans', 14.5, '#5D6680', 'middle', 0, 1.0, 540);
    header('Content-Type: image/png');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo ApCard::png($s, 1.5);
    exit;
}

if (!in_array($side, ApCard::SIDES, true)) {
    $deny(404, 'Unknown card side.');
}

/* ---- who may see this card ---- */
$member = null;
if (isset($_GET['u'])) {
    $member = AgentPro::memberByToken((string) $_GET['u']);
    if ($member === null || !str_starts_with($side, 'vc-')) {
        $deny(404, 'No such card.');
    }
    if ($member['status'] === 'revoked' || $member['status'] === 'inactive') {
        $deny(410, 'This card is no longer valid.');
    }
} elseif ($id > 0 && $exp > 0) {
    if (!ApCard::checkSig($id, $side, $exp, $key)) {
        $deny(403, 'This link has expired.');
    }
    $member = AgentPro::member($id);
} else {
    $admin = Auth::admin();
    if ($admin === null) {
        $deny(401, 'Sign in first.');
    }
    $own = (int) $admin['id'] === $id;
    if (!$own && !(Auth::can('staff.manage') || Auth::can('commissions.view'))) {
        $deny(403, 'You may not view this card.');
    }
    $member = AgentPro::member($id > 0 ? $id : (int) $admin['id']);
}
if ($member === null) {
    $deny(404, 'No such member.');
}

$scene = ApCard::scene($member, $side);
$name  = 'shg-' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($member['idNo'] ?: $member['name'])) ?? 'card') . '-' . $side;
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
if (!empty($_GET['dl'])) {
    header('Content-Disposition: attachment; filename="' . $name . '.' . $fmt . '"');
}
if ($fmt === 'svg') {
    header('Content-Type: image/svg+xml; charset=utf-8');
    echo ApCard::svg($scene, true, rtrim(APP_URL, '/'));
    exit;
}
header('Content-Type: image/png');
$scale = isset($_GET['print']) ? 300 / 25.4 / 10 : 2.0;     // print = exactly 300 dpi
echo ApCard::png($scene, $scale);
