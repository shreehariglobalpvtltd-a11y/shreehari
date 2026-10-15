<?php
/**
 * ticket-art-test.php — the brand + heritage art and the anti-fake marks on
 * every printed document (9 Oct 2026).
 *
 * Owner asks: the emblem alone (no baked name, no white tile), India + Nepal
 * flags on every document, Nepali heritage art (Sagarmatha, Gautam Buddha /
 * Lumbini, the Nepal flag) on the ticket and the chalani, and a way to tell
 * a fake ticket from a real one.
 *
 *   1. DocArt is fail-safe: with every asset missing nothing throws, the
 *      art is skipped and the flags fall back to a drawn version.
 *   2. DocArt with the real assets: the emblem file is the transparent mark
 *      (never the old logo with the name), drawn aspect-correct.
 *   3. Ticket::securityCode(): 6 letters from a no-0/O/1/I alphabet,
 *      deterministic, and different for another qr_hash (every reissue) or
 *      another PNR.
 *   4. PNG ticket: still 1080 wide, height = the old floor + the heritage
 *      band (appended, nothing above moves), no white chip behind the
 *      emblem, the band really drawn at the foot.
 *   5. PDF ticket: renders, carries the security code on the stub.
 *   6. Cache / layout stamps: ticket stamps in the past and at/after this
 *      change; chalani 2000x1414 unchanged; LAYOUT_VERSION 9 / 7 / stmt-v4.
 *   7. Source pins: verify-ticket NOT GENUINE + audit + report button +
 *      HTML 429 + trip-cancelled override; no white chips left.
 *   8. Over HTTP (SHG_TEST_BASE): a wrong key reads NOT GENUINE for a real
 *      AND a made-up PNR alike (no existence leak); no key = neutral.
 *
 * Writes: one temporary PNG per run under the system temp folder (deleted),
 * plus — over HTTP only — the audit row / office alert a forged check makes.
 * Refuses to run anywhere but a test database.
 *
 *   php tests/ticket-art-test.php
 *   SHG_TEST_BASE=http://localhost:8991 php tests/ticket-art-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ticket.php';
require_once __DIR__ . '/../includes/pdf.php';
require_once __DIR__ . '/../includes/qr.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/chalanipng.php';
require_once __DIR__ . '/../includes/challanpng.php';
require_once __DIR__ . '/../includes/agentstatement.php';

$PASS = 0;
$FAIL = 0;

function check(string $label, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    }
}

$dbName = defined('DB_NAME') ? (string) DB_NAME : '';
if (stripos($dbName, 'test') === false) {
    echo "  refusing to run: database '{$dbName}' is not a test database\n\n";
    exit(1);
}
$root = dirname(__DIR__);

/* ------------------------------------------------------------------ */
echo "\n=== 1. DocArt is fail-safe with every asset missing ===\n\n";

$empty = rtrim(sys_get_temp_dir(), '/\\') . '/shg-docart-empty-' . bin2hex(random_bytes(3));
@mkdir($empty, 0775, true);
DocArt::setRootForTests($empty);
$threw = '';
$res = [];
try {
    $im = imagecreatetruecolor(400, 200);
    $navy = imagecolorallocate($im, 18, 38, 78);
    imagefilledrectangle($im, 0, 0, 399, 199, $navy);
    $res['markFile'] = DocArt::markFile();
    $res['flagFile'] = DocArt::flagFile('IN');
    $res['bandFile'] = DocArt::bandFile();
    $res['mark']     = DocArt::mark($im, 10, 10, 120, 80, true);
    $res['place']    = DocArt::placeImage($im, $empty . '/nope.png', 0, 0, 50, 50);
    $res['flagIN']   = DocArt::flag($im, 'IN', 200, 10, 30);
    $res['flagNP']   = DocArt::flag($im, 'NP', 260, 10, 30);
    $res['flagXX']   = DocArt::flag($im, 'XX', 0, 0, 30);
    $res['pair']     = DocArt::flagPair($im, 10, 120, 24);
    $res['band']     = DocArt::band($im, 10, 150, 390, 195, 8, [255, 255, 255]);
    $res['wash']     = DocArt::bandWash($im, 0, 0, 400, 100);
    $res['deriv']    = DocArt::derivative($empty . '/nope.png', 50, 50);
    $res['halo']     = DocArt::haloFile(null);
    $pdf = new Pdf();
    $res['pdfMark']  = DocArt::pdfMark($pdf, 10, 10, 80, 40);
    $res['pdfFlags'] = DocArt::pdfFlags($pdf, 10, 60, 12);
    $res['pdfBand']  = DocArt::pdfBand($pdf, 10, 80, 200, 50);
    // the drawn India flag: saffron top band, green bottom band
    $top = imagecolorsforindex($im, imagecolorat($im, 215, 13));
    $bot = imagecolorsforindex($im, imagecolorat($im, 215, 37));
    $res['drawnIN'] = $top['red'] > 200 && $top['green'] > 120 && $top['blue'] < 90 && $bot['green'] > 100 && $bot['red'] < 60;
    $ridge = imagecolorsforindex($im, imagecolorat($im, 200, 190));
    $res['ridge'] = !($ridge['red'] === 18 && $ridge['green'] === 38 && $ridge['blue'] === 78);
} catch (Throwable $e) {
    $threw = get_class($e) . ': ' . $e->getMessage();
}
DocArt::setRootForTests(null);
@rmdir($empty);
$isNull = static fn(string $k): bool => array_key_exists($k, $res) && $res[$k] === null;

check('nothing throws with every asset missing', $threw === '', $threw);
check('no emblem file → null (a document then keeps its text-only band)', $isNull('markFile') && $isNull('mark'));
check('no flag / band file → null', $isNull('flagFile') && $isNull('bandFile'));
check('a missing picture is skipped, not fatal', $isNull('place') && $isNull('deriv') && $isNull('halo'));
check('flags fall back to a DRAWN flag (a document never loses the pair)', ($res['flagIN'] ?? 0) === 45 && ($res['flagNP'] ?? 0) > 10 && ($res['pair'] ?? 0) > 40,
    'IN=' . ($res['flagIN'] ?? '?') . ' NP=' . ($res['flagNP'] ?? '?'));
check('the drawn India flag is a real tricolour', ($res['drawnIN'] ?? false) === true);
check('an unknown country code draws nothing', ($res['flagXX'] ?? -1) === 0);
check('no band art → a flat Himalayan ridge is drawn instead', ($res['band'] ?? false) === true && ($res['ridge'] ?? false) === true);
check('the faint band wash is simply skipped', ($res['wash'] ?? true) === false);
check('PDF helpers skip quietly (mark / band) and still draw the flags', $isNull('pdfMark') && ($res['pdfBand'] ?? true) === false && ($res['pdfFlags'] ?? 0.0) > 0);

/* ------------------------------------------------------------------ */
echo "\n=== 2. DocArt with the real assets ===\n\n";

$mf = (string) DocArt::markFile();
$mfN = strtolower(str_replace('\\', '/', $mf));
check('the emblem file is the transparent mark, not the logo with the baked name',
    $mf !== '' && !str_ends_with($mfN, '/assets/img/logo.png') && str_contains($mfN, 'logo-mark'), basename($mf));
$mInfo = $mf !== '' ? @getimagesize($mf) : false;
check('the emblem PNG has an alpha channel / transparency', $mInfo !== false && $mInfo[2] === IMAGETYPE_PNG
    && (static function (string $f): bool { $g = @imagecreatefrompng($f); if (!$g) { return false; } if (!imageistruecolor($g)) { return imagecolortransparent($g) >= 0; } $c = imagecolorsforindex($g, imagecolorat($g, 0, 0)); return $c['alpha'] > 100; })($mf));
check('both flag PNGs ship', DocArt::flagFile('IN') !== null && DocArt::flagFile('NP') !== null);
check('the heritage band art ships', DocArt::bandFile() !== null);
$im = imagecreatetruecolor(600, 300);
imagefilledrectangle($im, 0, 0, 599, 299, imagecolorallocate($im, 18, 38, 78));
$box = DocArt::mark($im, 20, 20, 300, 120, true);
$ratio = $mInfo !== false ? $mInfo[0] / $mInfo[1] : 0;
check('the emblem is drawn aspect-correct (never squashed into a box)', $box !== null && $ratio > 0 && abs($box['w'] / max(1, $box['h']) - $ratio) < 0.05,
    $box !== null ? $box['w'] . 'x' . $box['h'] . ' vs ' . round($ratio, 2) : 'skipped');
check('flagPairWidth() matches what flagPair() draws', DocArt::flagPair($im, 10, 200, 30, 10) === DocArt::flagPairWidth(30, 10));
check('DocArt::heritageOn() defaults to ON', DocArt::heritageOn() === true);

/* ------------------------------------------------------------------ */
echo "\n=== 3. Ticket::securityCode() ===\n\n";

$c1 = Ticket::securityCode('SHG-2026-01234', 'hash-a');
$c2 = Ticket::securityCode('SHG-2026-01234', 'hash-a');
$c3 = Ticket::securityCode('SHG-2026-01234', 'hash-b');
$c4 = Ticket::securityCode('SHG-2026-01235', 'hash-a');
$c5 = Ticket::securityCode('shg-2026-01234 ', 'hash-a');
check('6 characters from the no-0/O/1/I alphabet', preg_match('/^[A-HJ-NP-Z2-9]{6}$/', $c1) === 1, $c1);
check('deterministic', $c1 === $c2);
check('a reissue (new qr_hash) mints a new code', $c1 !== $c3, "$c1 / $c3");
check('another PNR gets another code', $c1 !== $c4);
check('PNR case / spaces do not change it', $c1 === $c5);
$codes = [];
for ($i = 0; $i < 300; $i++) { $codes[Ticket::securityCode('SHG-2026-' . sprintf('%05d', $i), 'h')] = 1; }
check('codes spread (300 PNRs, no more than 1 collision)', count($codes) >= 299, (string) count($codes));
check('grouped for print as "ABC DEF"', Ticket::securityCodeGrouped('ABCDEF') === 'ABC DEF');

/* ------------------------------------------------------------------ */
echo "\n=== 4. PNG ticket: band appended, emblem without a chip ===\n\n";

$rc = new ReflectionClass(Ticket::class);
$artH = (int) $rc->getConstant('PNG_ART_H');
$row = Database::fetch(
    "SELECT b.id FROM bookings b JOIN tickets t ON t.booking_id = b.id
       JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
       JOIN schedules s ON s.id = l.schedule_id
       JOIN booking_passengers p ON p.booking_id = b.id
      WHERE b.status = 'confirmed' AND t.is_void = 0 ORDER BY b.id DESC LIMIT 1");
if ($row === null) {
    check('a confirmed booking with a ticket exists in the test DB', false);
} else {
    $load = $rc->getMethod('loadBooking'); $load->setAccessible(true);
    $png  = $rc->getMethod('renderTicketPng'); $png->setAccessible(true);
    $pdfM = $rc->getMethod('renderTicketPdf'); $pdfM->setAccessible(true);
    try {
        $bk = $load->invoke(null, (int) $row['id']);
    } catch (Throwable $e) {
        check('the sample booking loads', false, $e->getMessage());
        echo "\nticket-art: {$PASS} passed, {$FAIL} failed\n";
        exit(1);
    }
    $tk = Database::fetch('SELECT * FROM tickets WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $row['id']]) ?? [];
    $out = rtrim(sys_get_temp_dir(), '/\\') . '/shg-ticket-art-' . bin2hex(random_bytes(3)) . '.png';
    $err = '';
    try { $png->invoke(null, $bk, $tk, $out); } catch (Throwable $e) { $err = $e->getMessage(); }
    $sz = is_file($out) ? getimagesize($out) : false;
    check('the PNG renders', $err === '' && $sz !== false, $err);
    if ($sz !== false) {
        $g = imagecreatefrompng($out);
        $px = static function (int $x, int $y) use ($g): array { $c = imagecolorsforindex($g, imagecolorat($g, $x, $y)); return [$c['red'], $c['green'], $c['blue']]; };
        check('still 1080 wide', $sz[0] === 1080);
        check('heritage band constant is sane', $artH >= 200 && $artH <= 420, (string) $artH);
        check('height = the old floor (1648 + 270 at least) + the band', $sz[1] >= 1648 + 270 + $artH, $sz[1] . ' >= ' . (1648 + 270 + $artH));
        // no white chip: the old chip was a white rounded box at (60,66)-(220,224)
        $white = 0;
        foreach ([[72, 78], [208, 78], [72, 214], [208, 214], [140, 72]] as [$x, $y]) {
            [$r, $gg, $b] = $px($x, $y);
            if ($r > 235 && $gg > 235 && $b > 235) { $white++; }
        }
        check('no white chip behind the emblem (old chip area reads navy)', $white === 0, $white . ' white probes');
        /* 15 Oct 2026: the letterhead - the emblem is centred and the pay
           pill took the top-left, so the navy probe moved under the pill. */
        [$r, $gg, $b] = $px(140, 160);
        check('the header band is still navy around the emblem', $b > $r && $b > 60, "$r,$gg,$b");
        // the band: a navy caption strip near the very bottom, art above it
        $capY = $sz[1] - 28 - 30;
        [$r, $gg, $b] = $px(200, $capY);
        check('the heritage caption strip is drawn at the foot', $b > $r + 20 && $r < 80, "($r,$gg,$b) at y=$capY");
        $artPix = $px(540, $sz[1] - $artH + 60);
        check('the heritage art is drawn above the caption (not blank page)', !($artPix[0] > 236 && $artPix[1] > 243 && $artPix[2] > 250), implode(',', $artPix));
        imagedestroy($g);
    }
    @unlink($out);

    $err = '';
    $bytes = '';
    try { $bytes = $pdfM->invoke(null, $bk, $tk)->output(); } catch (Throwable $e) { $err = $e->getMessage(); }
    // the content streams are deflated: read the text the page really draws
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $sm);
    foreach ($sm[1] as $chunk) { $u = @gzuncompress($chunk); if ($u !== false) { $bytes .= "\n" . $u; } }
    check('the PDF ticket renders', $err === '' && strlen($bytes) > 20000, $err !== '' ? $err : strlen($bytes) . ' bytes');
    $code = Ticket::securityCode((string) $bk['pnr'], (string) ($tk['qr_hash'] ?? ''));
    check('the PDF stub carries the security code', $bytes !== '' && str_contains($bytes, implode(' ', str_split(Ticket::securityCodeGrouped($code)))), $code);
    check('the PDF embeds images with alpha (emblem / flags, no white chip)', substr_count($bytes, '/SMask') >= 2);
    check('securityCodeFor(booking) = securityCode(pnr, qr_hash)', Ticket::securityCodeFor((int) $row['id']) === $code);
}

/* ------------------------------------------------------------------ */
echo "\n=== 5. Stamps and layout versions ===\n\n";

foreach (['PNG_LAYOUT_CHANGED', 'PDF_LAYOUT_CHANGED'] as $k) {
    $s = (string) $rc->getConstant($k);
    $t = (int) strtotime($s);
    check($k . ' is a real moment in the past', $t > 0 && $t <= time(), $s);
    check($k . ' moved for this layout (at/after 9 Oct 2026)', strcmp($s, '2026-10-09 00:00:00') >= 0, $s);
}
check('chalani PNG stays exactly 2000x1414', ChalaniPng::WIDTH === 2000 && ChalaniPng::HEIGHT === 1414);
// 9 Oct 2026 (gold documents): chalani 10, challan 8. 10 Oct 2026 (trust): 11 / 9 - the statutory
// lines (computer-generated, IST stamp, GSTIN when set, CIN in the challan foot band) redraw every cached page once.
check('ChalaniPng::LAYOUT_VERSION bumped to 11', ChalaniPng::LAYOUT_VERSION === 11);
check('ChallanPng::LAYOUT_VERSION bumped to 9 (width unchanged)', ChallanPng::LAYOUT_VERSION === 9 && ChallanPng::WIDTH === 1600);
check("AgentStatementPng::LAYOUT is 'stmt-v4'", AgentStatementPng::LAYOUT === 'stmt-v4');

/* ------------------------------------------------------------------ */
echo "\n=== 6. Source pins ===\n\n";

$src = static fn(string $f): string => (string) @file_get_contents($root . '/' . $f);
$t = $src('includes/ticket.php');
check('ticket: no white chip behind the logo any more', !str_contains($t, 'self::gdRounded($im, 60, 66, 220, 224, 16, $white)') && !str_contains($t, '$pdf->rect(36, 14, 84, 66, [255, 255, 255], true)'));
check('ticket: emblem via DocArt (PNG + PDF + invoice)', substr_count($t, 'DocArt::mark(') >= 1 && substr_count($t, 'DocArt::pdfMark(') >= 2);
check('ticket: flags on PNG + PDF', str_contains($t, 'DocArt::flagPair(') && str_contains($t, 'DocArt::pdfFlags('));
check('ticket: Nepali label + Roman nouns in the heritage caption', str_contains($t, 'सगरमाथा · Sagarmatha') && str_contains($t, 'लुम्बिनी — गौतम बुद्धको जन्मस्थल · Lumbini'));
check('ticket: header line localised (ई-टिकट)', str_contains($t, "'ई-टिकट  ·  INDIA -> NEPAL'"));
check('ticket: diagonal microtext + security tile', str_contains($t, 'self::gdWatermark(') && str_contains($t, 'सुरक्षा कोड  ·  SECURITY CODE'));
check('ticket: pinned literals kept', str_contains($t, '126, $gold, $issued') && str_contains($t, 'टिकट काट्ने') && str_contains($t, 'foreach ([23, 20, 18] as $try)') && str_contains($t, 'imagecolorallocate($im, 84, 96, 118)'));
$v = $src('verify-ticket.php');
check('verify: NOT GENUINE state for a wrong key / bad signature', str_contains($v, "\$state = 'forged'") && str_contains($v, '!Ticket::checkDownloadToken($pnr, $token)') && str_contains($v, "str_starts_with(\$payload, 'SHG-TICKET|')"));
check('verify: forged check audited + office alert deduped per PNR / hour', str_contains($v, "Logger::audit('ticket.verify_forged'") && str_contains($v, "'verifyforged:' . \$fp . ':' . date('YmdH')"));
check('verify: trip_cancelled overrides the green headline', str_contains($v, "\$state = 'trip_cancelled'"));
check('verify: rate limit answers with an HTML 429', str_contains($v, 'Security::rateLimit(\'ticket_verify\'') && str_contains($v, 'http_response_code(429)') && !str_contains($v, "Security::requireRateLimit('ticket_verify'"));
check('verify: security code, Checked at, genuine-domain line, flags', str_contains($v, 'Ticket::securityCodeFor(') && str_contains($v, 'Checked at') && str_contains($v, 'Genuine only on') && str_contains($v, "\$flagSvg('NP'"));
check('verify: Report this ticket → api/report-fake.php with a fresh CSRF token', str_contains($v, 'Report this ticket · नक्कली टिकट रिपोर्ट') && str_contains($v, "/api/report-fake.php") && str_contains($v, "/api/csrf.php"));
check('verify: pinned strings kept', str_contains($v, 'Auth::customerMayOpen($booking)') && str_contains($v, "['ticket']['is_void']") && str_contains($v, 'Boarding::stopDisplay'));
check('verify: emblem not stretched (no 40x40 box)', !str_contains($v, '.hdr img { width: 40px; height: 40px;') && str_contains($v, 'logo-mark'));
// The gold chalani draws its seal through GoldSheet (includes/goldsheet.php), which calls DocArt::mark.
foreach (['includes/chalanipng.php', 'includes/agentstatement.php'] as $f) {
    $body = $src($f) . ($f === 'includes/chalanipng.php' && str_contains($src($f), 'GoldSheet::') ? $src('includes/goldsheet.php') : '');
    check("$f: emblem via DocArt, no white chip", str_contains($body, 'DocArt::mark(') && !preg_match('/(roundRect|box)\(\$im, \$M - \d+, 34,[^;]*\$white/', $src($f)));
}
check('agentstatement/reportpdf: no hard-coded company name as the header', !str_contains($src('includes/agentstatement.php'), "self::t(\$im, 26, \$tx, 34, \$white, 'S HARI GLOBAL PVT LTD', true)")
    && !str_contains($src('includes/reportpdf.php'), "\$pdf->text(\$tx, 10, 'S HARI GLOBAL PVT. LTD.', 16"));
check('chalani PDF: emblem aspect-correct (no 44x44 squash)', !str_contains($src('includes/chalanipdf.php'), '$pdf->image($logo, $M, $y - 2, 44, 44)') && str_contains($src('includes/chalanipdf.php'), 'DocArt::pdfMark('));
check('offer + promo cards print the live company name', str_contains($src('includes/offercard.php'), "Settings::company()['name']") && str_contains($src('includes/promocard.php'), "Settings::company()['name']"));
check('manifest print band: SVG flags, not emoji', !str_contains($src('admin/manifest.php'), '<span>🇮🇳 गुजरात') && str_contains($src('admin/manifest.php'), "\$flagSvg('IN')"));
check('chalani PNG view: no white tile behind the emblem', !str_contains($src('admin/_chalani-png-view.php'), 'background:#fff;border-radius:8px;padding:4px'));
$js = $src('assets/js/11-pdf-ticket.js');
check('client fallback ticket: live name, no white chip, flag images', !str_contains($js, "'S HARI GLOBAL PVT LTD'") && !str_contains($js, 'doc.roundedRect(24, 20, 68, 56') && str_contains($js, '/assets/img/flag-in.png'));

/* ------------------------------------------------------------------ */
$base = rtrim((string) (getenv('SHG_TEST_BASE') ?: ''), '/');
echo "\n=== 7. verify-ticket.php over HTTP" . ($base === '' ? ' (skipped: set SHG_TEST_BASE)' : " ($base)") . " ===\n\n";
if ($base !== '') {
    try { Database::run("DELETE FROM rate_limits WHERE bucket LIKE '%ticket_verify%'"); } catch (Throwable $e) {}
    $get = static function (string $url): array {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]);
        $body = (string) @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach (($http_response_header ?? []) as $h) { if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) { $code = (int) $m[1]; } }
        return [$code, $body];
    };
    $realPnr = $row !== null ? (string) Database::scalar('SELECT pnr FROM bookings WHERE id = :i', ['i' => $row['id']], '') : 'SHG-2026-00001';
    $fakePnr = 'SHG-2099-' . random_int(10000, 99999);
    [$c1, $b1] = $get($base . '/verify-ticket.php?pnr=' . rawurlencode($realPnr) . '&k=00000000000000000000');
    [$c2, $b2] = $get($base . '/verify-ticket.php?pnr=' . rawurlencode($fakePnr) . '&k=00000000000000000000');
    check('a wrong key on a real PNR reads NOT GENUINE', $c1 === 200 && str_contains($b1, 'NOT GENUINE'));
    check('a wrong key on a made-up PNR reads the same (no existence leak)', $c2 === 200 && str_contains($b2, 'NOT GENUINE')
        && !str_contains($b1, 'E2E') && strlen($b1) > 0 && abs(strlen($b1) - strlen($b2)) < 200);
    [$c3, $b3] = $get($base . '/verify-ticket.php?pnr=' . rawurlencode($realPnr));
    [$c4, $b4] = $get($base . '/verify-ticket.php?pnr=' . rawurlencode($fakePnr));
    check('no key at all stays neutral, never "fake"', $c3 === 200 && !str_contains($b3, 'NOT GENUINE') && !str_contains($b4, 'NOT GENUINE'));
    check('every state offers "Report this ticket"', str_contains($b1, 'Report this ticket') && str_contains($b3, 'Report this ticket'));
    check('the page shows a server Checked-at stamp + genuine-domain line', str_contains($b3, 'Checked at') && str_contains($b3, 'Genuine only on'));
    $aud = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'ticket.verify_forged' AND entity_id = :p", ['p' => strtoupper($fakePnr)], 0);
    check('a forged check is written to the audit log', $aud >= 1, (string) $aud);
    if ($row !== null) {
        $k = Ticket::downloadToken($realPnr);
        [$c5, $b5] = $get($base . '/verify-ticket.php?pnr=' . rawurlencode($realPnr) . '&k=' . $k);
        $code = Ticket::securityCodeFor((int) $row['id']);
        check('the genuine link shows the same security code the ticket prints', $c5 === 200 && $code !== '' && str_contains($b5, Ticket::securityCodeGrouped($code)), $code);
    }
}

echo "\n" . str_repeat('-', 40) . "\n";
echo "ticket-art: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL > 0 ? 1 : 0);
