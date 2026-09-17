<?php
/**
 * =====================================================================
 *  download-chalan.php — the public gate for chalan pictures sent on
 *  WhatsApp (17 Sep 2026)
 *
 *  Twilio / Meta fetch media from a public URL. The challan (seat grid) and
 *  the chalani pages (Nepali waybill) live under /uploads/challan/ and
 *  /uploads/chalani/ — a tree the deploy nginx config now denies (they
 *  carry passenger names and mobiles) — so the ONLY public way to one is
 *  this script with a signed, expiring key:
 *
 *    sid   schedule id      doc   challan | chalani      page  1..N (chalani)
 *    exp   unix expiry      k     substr(HMAC(APP_KEY, 'chalan-dl|sid|doc|page|exp'), 0, 20)
 *
 *  A signed-in office user (bookings.view, not a scoped agent) may also open
 *  it without the key. The challan is (re)drawn on demand by ChallanPng
 *  (fingerprint-cached); a chalani page is served from the newest page file
 *  the Bus Chalan hub / manifest already drew for that departure.
 * =====================================================================
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/challanpng.php';

try {
    Security::requireRateLimit('chalan_dl', Security::clientIp(), 40, 60);

    $sid  = (int) ($_GET['sid'] ?? 0);
    $doc  = ($_GET['doc'] ?? '') === 'chalani' ? 'chalani' : 'challan';
    $page = max(1, min(50, (int) ($_GET['page'] ?? 1)));
    $exp  = (int) ($_GET['exp'] ?? 0);
    $key  = Security::clean($_GET['k'] ?? '', 64);

    if ($sid <= 0) {
        http_response_code(404);
        exit('No such departure.');
    }

    $expected = substr(Security::sign('chalan-dl|' . $sid . '|' . $doc . '|' . $page . '|' . $exp), 0, 20);
    $allowed  = $exp > time() && $key !== '' && hash_equals($expected, $key);
    if (!$allowed && Auth::admin() !== null && Auth::can('bookings.view') && Auth::bookingScopeAdminId() === null) {
        $allowed = true;
    }
    if (!$allowed) {
        Logger::warning('Chalan download refused', ['sid' => $sid, 'doc' => $doc, 'ip' => Security::clientIp()], 'security');
        http_response_code(403);
        exit('This chalan link has expired or is not valid.');
    }

    if (ChallanPng::schedule($sid) === null) {
        http_response_code(404);
        exit('No such departure.');
    }

    if ($doc === 'challan') {
        $res = ChallanPng::render($sid, 'whatsapp', null, false);
        Response::inline($res['path'], 'image/png');
    }

    // Chalani page: the newest drawn file for this departure + page.
    $dir   = rtrim(UPLOAD_PATH, '/\\') . '/chalani';
    $best  = '';
    $bestT = 0;
    foreach (glob($dir . '/*/*-S' . $sid . '-p' . $page . 'of*.png') ?: [] as $f) {
        $t = (int) @filemtime($f);
        if ($t > $bestT) { $bestT = $t; $best = $f; }
    }
    if ($best === '') {
        http_response_code(404);
        exit('This chalani page has not been drawn yet — open the Bus Chalan page in the admin panel first.');
    }
    Response::inline($best, 'image/png');
} catch (Throwable $e) {
    Logger::error('Chalan download failed: ' . $e->getMessage(), [], 'security');
    http_response_code(500);
    exit('The chalan could not be served.');
}
