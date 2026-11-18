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
 *   ?m=<admin id>&pdf=1                     ONE A4 PDF, print-ready: ID card front + back and
 *                                           visiting card front + back at true size, crop marks
 *                                           (same access as a card side; signed side = 'pdf')
 *
 *   &dl=1  download instead of showing
 *
 *   19 Nov 2026 (CD-1) — the same, with long names and the three designs:
 *   ?member=<id>&kind=id|vc&side=front|back&variant=royal|modern|heritage|portrait
 *       &format=png|svg|pdf  (&dpi=300 or &print=1 = exactly 300 dpi)
 *       &sheet=a4            A4 print sheet: 4 ID cards (kind=id) or 10 visiting
 *                            cards (kind=vc), fronts then mirrored backs, crop marks
 *                            (signed side = 'sheet-id' / 'sheet-vc')
 *   PNGs come from a cache (ApCard::cachedPng) keyed on member + side + design + a
 *   hash of what is drawn; after the answer the member's other designs are drawn.
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

/* 19 Nov 2026 (CD-1): the long names too — ?member=&kind=id|vc&side=front|back
   &variant=royal|modern|heritage|portrait&format=png|pdf|svg&sheet=a4 (A4 print sheet:
   4 ID cards / 10 visiting cards, fronts then mirrored backs, crop marks). */
$kindQ = (string) ($_GET['kind'] ?? '');
$faceQ = (string) ($_GET['side'] ?? '');
$side  = (string) ($_GET['s'] ?? (in_array($kindQ, ['id', 'vc'], true) ? $kindQ . '-' . ($faceQ === 'back' ? 'back' : 'front') : 'id-front'));
$fmtQ  = strtolower((string) ($_GET['format'] ?? ($_GET['f'] ?? 'png')));
$fmt   = $fmtQ === 'svg' ? 'svg' : 'png';
$id    = (int) ($_GET['m'] ?? ($_GET['member'] ?? 0));
$exp   = (int) ($_GET['e'] ?? 0);
$key   = (string) ($_GET['k'] ?? '');
$variant = strtolower(trim((string) ($_GET['variant'] ?? '')));
if (!isset(ApCard::ID_STYLES[$variant])) {
    $variant = '';                                       // '' = the office's default design
}
$sheet = strtolower((string) ($_GET['sheet'] ?? '')) === 'a4'
    ? ((str_starts_with($side, 'vc-') || $kindQ === 'vc') ? 'sheet-vc' : 'sheet-id') : '';
if ($sheet === '' && $fmtQ === 'pdf' && !isset($_GET['pdf'])) {
    $_GET['pdf'] = '1';                                  // format=pdf = the one-page PDF with all four faces
}

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

$wantPdf = !empty($_GET['pdf']) || $sheet !== '';
if ($sheet !== '') {
    $side = $sheet;                                     // 'sheet-id' / 'sheet-vc'
} elseif ($wantPdf) {
    $side = 'pdf';                                      // what a signed link was signed for
} elseif (!in_array($side, ApCard::SIDES, true)) {
    $deny(404, 'Unknown card side.');
}

/* ---- who may see this card ---- */
$member = null;
if (isset($_GET['u'])) {
    if ($wantPdf) {
        $deny(404, 'No such card.');                    // the public token shows the visiting card only
    }
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
    // No ?m= means "my own card" (the member portal links /api/agentpro-card.php?pdf=1).
    $own = $id <= 0 || (int) $admin['id'] === $id;
    if (!$own && !(Auth::can('staff.manage') || Auth::can('commissions.view'))) {
        $deny(403, 'You may not view this card.');
    }
    $member = AgentPro::member($id > 0 ? $id : (int) $admin['id']);
}
if ($member === null) {
    $deny(404, 'No such member.');
}

$name  = 'shg-' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($member['idNo'] ?: $member['name'])) ?? 'card') . '-'
    . ($sheet !== '' ? ($sheet === 'sheet-vc' ? 'visiting-cards-a4' : 'id-cards-a4') : ($wantPdf ? 'cards' : $side))
    . ($variant !== '' ? '-' . $variant : '');

/* After the answer has gone out: draw the member's other designs into the cache,
   so the office's next print / download is instant (19 Nov 2026). */
$warm = static function () use ($member): void {
    if (!Settings::getBool('ap_card_prerender', true)) {
        return;
    }
    if (!function_exists('fastcgi_finish_request')) {
        return;                                         // CLI / dev server: never make the viewer wait
    }
    // Free the session lock BEFORE the slow part: bootstrap starts a file session on
    // every request, and a held lock would stall this browser's other card images and
    // admin pages for the whole warm-up (fix pass, 19 Nov 2026).
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    ignore_user_abort(true);
    fastcgi_finish_request();
    @set_time_limit(120);
    try {
        ApCard::prerender($member);
    } catch (Throwable $e) {
        // a cache warm-up never fails a request
    }
};

if ($wantPdf) {
    try {
        Security::requireRateLimit('apcard_pdf', Security::clientIp(), 20, 300);     // four 300 dpi renders each
    } catch (Throwable $e) {
        $deny(429, 'Too many requests.');
    }
    @set_time_limit(60);
    $bin = $sheet !== '' ? ApCard::sheetPdf($member, $sheet === 'sheet-vc' ? 'vc' : 'id', $variant) : ApCard::pdf($member, $variant);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $name . '.pdf"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    $warm();
    exit;
}
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
if (!empty($_GET['dl'])) {
    header('Content-Disposition: attachment; filename="' . $name . '.' . $fmt . '"');
}
if ($fmt === 'svg') {
    header('Content-Type: image/svg+xml; charset=utf-8');
    echo ApCard::svg(ApCard::scene($member, $side, $variant), true, rtrim(APP_URL, '/'));
    exit;
}
header('Content-Type: image/png');
$scale = (isset($_GET['print']) || (int) ($_GET['dpi'] ?? 0) === 300) ? 300 / 25.4 / 10 : 2.0;     // print = exactly 300 dpi
echo ApCard::cachedPng($member, $side, $variant, $scale);
$warm();
