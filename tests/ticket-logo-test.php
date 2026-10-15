<?php
/**
 * ticket-logo-test.php — the ticket letterhead set like the company logo
 * (15 Oct 2026, owner: "ticket ma logo lai ali kati thulo, mathi upload
 * gareko jastai, name sath ma trade mark").
 *
 * Locks in:
 *   • Ticket::brandParts() splits a company name the way the logo sets it
 *     (brand / "PRIVATE LIMITED" line), for the India and the Nepal company;
 *   • the three brand assets ship, decode, and sit under DocArt's size cap;
 *   • the PNG header: the emblem is the big free HD cut (orange arrow and
 *     white bus where the box puts them, no gold seal rim in the middle),
 *     the white wordmark sits at PNG_WORD_Y, the ™ sign beside it, the gold
 *     legal-form line under it, and the band ends at 254 + PNG_HEAD_EXTRA;
 *   • an office-set mark (company_logo_mark) wins over the HD cut;
 *   • the PDF letterhead carries the brand and the ™ sign (WinAnsi 0x99).
 *
 * Writes: one ticket's cached PNG + PDF (re-rendered). Test database only.
 *
 *   php -c .claude/php-dev.ini tests/ticket-logo-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$dbName = defined('DB_NAME') ? (string) DB_NAME : '';
if (stripos($dbName, 'test') === false) {
    echo "  refusing to run: database '{$dbName}' is not a test database (this suite re-renders ticket files)\n";
    exit(1);
}

echo "\n== brandParts ==\n";
check('India company', Ticket::brandParts('S Hari Global Pvt Ltd') === ['S HARI GLOBAL', 'PRIVATE LIMITED']);
check('Nepal company keeps its country on the legal line',
    Ticket::brandParts('Shree Hari Global Pvt Ltd Nepal') === ['SHREE HARI GLOBAL', 'PRIVATE LIMITED · NEPAL']);
check('"Private Limited" and dotted forms', Ticket::brandParts('S Hari Global Private Limited') === ['S HARI GLOBAL', 'PRIVATE LIMITED']
    && Ticket::brandParts('S Hari Global Pvt. Ltd.') === ['S HARI GLOBAL', 'PRIVATE LIMITED']);
check('a name without a legal form comes back whole', Ticket::brandParts('Hari Travels') === ['HARI TRAVELS', '']);
check('the trademark sign is U+2122', Ticket::TM === "\u{2122}");

echo "\n== assets ==\n";
$root = dirname(__DIR__);
foreach (['logo-mark-ticket.png' => [500, 260], 'logo-wordmark.png' => [600, 50], 'logo-wordmark-sub.png' => [400, 18]] as $f => [$minW, $minH]) {
    $p = $root . '/assets/img/' . $f;
    $info = is_file($p) ? @getimagesize($p) : false;
    check("$f ships, decodes, is big enough and under 2 MB",
        $info !== false && $info[0] >= $minW && $info[1] >= $minH && filesize($p) < 2 * 1024 * 1024,
        $info ? $info[0] . 'x' . $info[1] . ', ' . filesize($p) . ' B' : 'missing');
}

/* A booking the renderer can load (same joins as Ticket::loadBooking). */
$b = Database::fetch(
    "SELECT b.id, b.pnr
       FROM bookings b
       JOIN tickets t ON t.booking_id = b.id
       JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
       JOIN schedules s ON s.id = l.schedule_id
       JOIN routes r ON r.id = s.route_id
      WHERE b.status = 'confirmed' AND (b.entity IS NULL OR b.entity <> 'NP')
      ORDER BY b.id DESC LIMIT 1"
);
if ($b === null) { echo "  SKIP  no confirmed booking with a renderable ticket\n"; exit(0); }
$bid = (int) $b['id'];
$rc  = new ReflectionClass(Ticket::class);
$EW  = (int) $rc->getConstant('PNG_EMBLEM_W');
$EH  = (int) $rc->getConstant('PNG_EMBLEM_H');
$EY  = (int) $rc->getConstant('PNG_EMBLEM_Y');
$WY  = (int) $rc->getConstant('PNG_WORD_Y');
$HX  = Ticket::PNG_HEAD_EXTRA;

echo "\n== PNG letterhead ==\n";
$savedMark = Settings::getString('company_logo_mark', '');
Database::run("DELETE FROM settings WHERE skey = 'company_logo_mark'");
Settings::flush();
$path = Ticket::pngPath($bid, true);
$im   = @imagecreatefrompng($path);
check('ticket renders', $im instanceof GdImage, $path);
if ($im instanceof GdImage) {
    $W = imagesx($im);
    $rgb = static function (int $x, int $y) use ($im): array {
        $c = imagecolorat($im, $x, $y);
        return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    };
    $count = static function (int $x1, int $y1, int $x2, int $y2, callable $want) use ($rgb): int {
        $n = 0;
        for ($y = $y1; $y <= $y2; $y += 2) { for ($x = $x1; $x <= $x2; $x += 2) { if ($want($rgb($x, $y))) { $n++; } } }
        return $n;
    };
    $orange = static fn(array $p): bool => $p[0] > 200 && $p[1] > 70 && $p[1] < 170 && $p[2] < 80;
    $white  = static fn(array $p): bool => $p[0] > 235 && $p[1] > 235 && $p[2] > 235;
    $goldTx = static fn(array $p): bool => $p[0] > 200 && $p[1] > 180 && $p[2] < 170 && $p[0] - $p[2] > 60;
    $ex = intdiv($W - $EW, 2);
    check('emblem box is ~1.9x the old seal face (>= 460 wide)', $EW >= 460 && $EH >= 240, "{$EW}x{$EH}");
    // the arrow head sits in the top-right quarter of the emblem box
    $arrow = $count($ex + (int) ($EW * 0.6), $EY, $ex + $EW - 1, $EY + (int) ($EH * 0.4), $orange);
    check('orange arrow drawn in the top-right of the emblem box', $arrow > 150, "$arrow px");
    // the bus (white body) sits in the bottom-right
    $bus = $count($ex + (int) ($EW * 0.62), $EY + (int) ($EH * 0.5), $ex + $EW - 1, $EY + $EH - 1, $white);
    check('white bus drawn in the bottom-right of the emblem box', $bus > 150, "$bus px");
    $wordWhite = $count(200, $WY + 8, 860, $WY + 52, $white);
    check('white wordmark under the emblem', $wordWhite > 2500, "$wordWhite px");
    $legalGold = $count(300, $WY + 73, 780, $WY + 96, $goldTx);
    check('gold "PRIVATE LIMITED" line under the wordmark', $legalGold > 300, "$legalGold px");
    // the TM sign: white ink to the right of the 641-wide wordmark, near its top
    $wInfo = getimagesize($root . '/assets/img/logo-wordmark.png');
    $tmW   = 0;
    $box   = imagettfbbox(19, 0, $root . '/assets/fonts/NotoSansDevanagari.ttf', Ticket::TM);
    if ($box !== false) { $tmW = abs($box[2] - $box[0]); }
    $wx  = intdiv($W - ($wInfo[0] + 6 + $tmW), 2);
    $tmX = $wx + $wInfo[0] + 6;
    $tm  = $count($tmX, $WY, $tmX + $tmW + 2, $WY + 26, $white);
    check('™ sign beside the wordmark, top-aligned', $tm > 12, "$tm px at x=$tmX");
    $below = $count($tmX, $WY + 32, $tmX + $tmW + 2, $WY + 56, $white);
    check('…and not at the baseline (it is a superscript)', $below < $tm, "$below px below");
    // band foot: navy above 254+HX, the foil bar at it, cream paper well below
    $bandB = 254 + $HX;
    $foot = $rgb(540, $bandB - 30);
    check('band reaches 254 + PNG_HEAD_EXTRA', $foot[2] > $foot[0] && $foot[2] > 60, implode(',', $foot) . " at y=" . ($bandB - 30));
    imagedestroy($im);
}

echo "\n== an office mark wins over the HD cut ==\n";
$getMark = $rc->getMethod('ticketMarkFile');
$getMark->setAccessible(true);
check('default install: the HD ticket cut', is_string($getMark->invoke(null)) && preg_match('#(^|/)assets/img/logo-mark-ticket\.png$#', str_replace('\\', '/', (string) $getMark->invoke(null))) === 1);
Database::run("INSERT INTO settings (skey, svalue, stype, sgroup) VALUES ('company_logo_mark', 'assets/img/logo-mark-96h.png', 'string', 'company')");
Settings::flush();
check('company_logo_mark set: no HD cut (theirs goes in the box)', $getMark->invoke(null) === null);
Database::run("DELETE FROM settings WHERE skey = 'company_logo_mark'");
if ($savedMark !== '') {
    Database::run("INSERT INTO settings (skey, svalue, stype, sgroup) VALUES ('company_logo_mark', :v, 'string', 'company')", ['v' => $savedMark]);
}
Settings::flush();

echo "\n== PDF letterhead ==\n";
$raw = (string) @file_get_contents(Ticket::pdfPath($bid, true));
check('PDF renders', str_starts_with($raw, '%PDF'));
/* content streams are Flate-compressed: inflate every one and search the lot */
$pdf = '';
if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $mm)) {
    foreach ($mm[1] as $st) { $u = @gzuncompress($st); $pdf .= ($u !== false ? $u : $st) . "\n"; }
}
[$brand] = Ticket::brandParts((string) Ticket::companyOf(Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $bid]) ?? [])['name']);
check('brand drawn on its own', str_contains($pdf, '(' . $brand . ') Tj'), $brand);
check('™ drawn (WinAnsi 0x99)', str_contains($pdf, "(\x99) Tj"));
check('legal form letter-spaced', str_contains($pdf, '(P R I V A T E   L I M I T E D) Tj'));
Ticket::pngPath($bid, true);   // leave a fresh cache behind

echo "\n" . ($FAIL === 0 ? "\033[32mALL PASS\033[0m" : "\033[31mFAILURES\033[0m") . "  ($PASS passed, $FAIL failed)\n";
exit($FAIL === 0 ? 0 : 1);
