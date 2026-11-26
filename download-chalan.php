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
 *  it without the key; a chalani page that way also needs chalani.export
 *  (25 Nov 2026, release ch). The challan is (re)drawn on demand by ChallanPng
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
    $doc  = in_array((string) ($_GET['doc'] ?? ''), ['chalani', 'status'], true) ? (string) $_GET['doc'] : 'challan';
    $page = max(1, min(50, (int) ($_GET['page'] ?? 1)));
    $exp  = (int) ($_GET['exp'] ?? 0);
    $key  = Security::clean($_GET['k'] ?? '', 64);

    if ($sid <= 0) {
        http_response_code(404);
        exit('No such departure.');
    }

    /* 26 Nov 2026 (fx1): c = the copy tag of a DESK COPY's chalani page (ChalaniStrip::
       shareLinks). It is part of what is signed, so a whole-bus key never opens a copy
       and a copy's key never opens the whole bus. No c = the whole-bus page, as before. */
    $tag  = $doc === 'chalani'
        ? substr((string) preg_replace('/[^A-Z0-9]/', '', strtoupper(Security::clean($_GET['c'] ?? '', 40))), 0, 24) : '';

    $expected = substr(Security::sign('chalan-dl|' . $sid . '|' . $doc . '|' . $page . '|' . $exp . ($tag !== '' ? '|' . $tag : '')), 0, 20);
    $allowed  = $exp > time() && $key !== '' && hash_equals($expected, $key);
    // 16 Nov 2026 (G1): staffActive(), not admin() - a session on a temporary password is a guest here
    if (!$allowed && Auth::staffActive() !== null && Auth::can('bookings.view') && Auth::bookingScopeAdminId() === null) {
        $allowed = true;
        /* 25 Nov 2026 (release ch review): the whole-bus chalani page (names, mobiles)
           without a key needs chalani.export, as on manifest.php (switch
           chalani_export_perm_on; off = bookings.view, as before). The challan and
           the seat-status card stay on bookings.view; a signed link still works. */
        if ($doc === 'chalani') {
            require_once INCLUDE_PATH . '/chalanistrip.php';
            $allowed = ChalaniStrip::mayExport();
        }
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
    /* 23 Sep 2026: the live seat-status card (no passenger details) that the
       office / agent WhatsApp receives on every booking - same signed link. */
    if ($doc === 'status') {
        require_once INCLUDE_PATH . '/seatstatuspng.php';
        $res = SeatStatusPng::render($sid, false);
        Response::inline($res['path'], 'image/png');
    }

    /* ---- chalani page ------------------------------------------------------
       26 Nov 2026 (fx1): DRAWN ON DEMAND from today's rows (ChalaniPng::sheetFor -
       the very arrays the manifest page draws from; fingerprint-cached, so an
       unchanged bus costs no redraw). It used to serve "the newest file somebody
       happened to draw": a link sent to the driver showed yesterday's list until an
       office user opened the PNG pages again, and answered 404 when nobody ever had.
       The page after the last sheet page is the seat chart, as the hub offers it.
       Switch chalani_dl_redraw_on (default on); off = the newest drawn file, as before.
       Any error in the builder falls back to that file too - a link never breaks. */
    require_once INCLUDE_PATH . '/chalanipng.php';
    $best    = '';
    $noPage  = 0;      // > 0: the sheet is known and has only this many pages
    if (Settings::getBool('chalani_dl_redraw_on', true)) {
        try {
            $desk = '';
            if ($tag !== '') {
                foreach (array_keys(CounterDesk::all()) as $dc) {
                    if (ChalaniPng::copyInfo(null, '', (string) $dc, '')['tag'] === $tag) { $desk = (string) $dc; break; }
                }
            }
            $sheet = ($tag === '' || $desk !== '') ? ChalaniPng::sheetFor($sid, $desk) : null;   // an agent copy is never rebuilt here
            if ($sheet !== null && ChalaniPng::copyTag($sheet['fmt']) === $tag) {
                $shown = $sheet['pages'] + ($sheet['hasChart'] ? 1 : 0);
                if ($page <= $sheet['pages']) {
                    $best = ChalaniPng::render($sheet['trip'], $sheet['rows'], $sheet['date'], $sheet['totals'], $sheet['fmt'], $page)['path'];
                } elseif ($page === $shown) {
                    $best = ChalaniPng::renderChart($sheet['trip'], $sheet['rows'], $sheet['date'], $sheet['totals'], $sheet['fmt'], $page, $shown)['path'];
                } else {
                    $noPage = $shown;
                }
            }
        } catch (Throwable $e) {
            Logger::error('Chalani page redraw failed: ' . $e->getMessage(), ['sid' => $sid, 'page' => $page]);
            $best = '';
        }
    }
    if ($noPage > 0) {
        http_response_code(404);
        exit('This chalani has ' . $noPage . ' page' . ($noPage === 1 ? '' : 's') . ' now.');
    }
    if ($best === '') {
        // the newest drawn file of this copy + page (never another copy's)
        $best = ChalaniPng::newestFile($sid, $page, $tag);
        if ($best === '' && $page > 1) {
            // the page after the last sheet page = the seat chart
            $p1 = ChalaniPng::newestFile($sid, 1, $tag);
            if ($p1 !== '' && preg_match('/-p1of(\d+)-[0-9a-f]{8}\.png$/', basename($p1), $pm) === 1 && $page === (int) $pm[1] + 1) {
                $best = ChalaniPng::newestFile($sid, 0, $tag);
            }
        }
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
