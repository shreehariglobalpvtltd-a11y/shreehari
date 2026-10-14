<?php
/**
 * =====================================================================
 *  tools/make-og.php — the 1200x630 Open Graph share card (10 Oct 2026,
 *  HD visual pack; owner: "the photos GPT made in HD - replace them").
 *
 *  Composes assets/img/og-shg.png from
 *    • the coach render   assets/img/hd/bus-hero.webp  (promotional
 *      render, contained on the right - never cropped, with a small
 *      "Illustration" pill so a share preview is not taken for a photo),
 *    • the emblem         DocArt::mark() (logo-mark.png, transparent, no
 *      white tile - tests/brand-flags-test.php samples that corner),
 *    • the company name   Settings::company()['name'] (never typed here),
 *    • the two flags      DocArt::flagPair(),
 *    • the line           "India ⇄ Nepal · AC Sleeper" (the arrows are
 *      drawn, Noto Sans Devanagari has no U+21C4),
 *    • the web address    Settings::company()['web'].
 *  Devanagari in the company name goes through DevShape::gdText() (GD does
 *  not shape conjuncts on its own).
 *
 *  Run ONCE from the repo root, then commit the PNG:
 *      php -c .claude/php-dev.ini tools/make-og.php
 *  Writes og-shg.png.tmp and renames it (never a half-written file). The
 *  card must stay under 400 KB (brand-flags-test): a truecolor save that
 *  comes out larger is re-saved as a dithered 255-colour palette PNG.
 *
 *  CLI only. Reads the DB only through Settings (the company row).
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/docart.php';
require_once INCLUDE_PATH . '/devshape.php';

if (!function_exists('imagecreatefromwebp') || !function_exists('imagettftext')) {
    fwrite(STDERR, "GD with WebP + FreeType is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
$out  = $root . '/assets/img/og-shg.png';
$font = $root . '/assets/fonts/NotoSansDevanagari.ttf';
$W = 1200;
$H = 630;

/* ---- palette: the design tokens (ds.css --navy / --royal, showcase --sc-gold) ---- */
$NAVY   = [14, 30, 64];      // #0E1E40
$ROYAL  = [31, 79, 163];     // #1F4FA3
$GOLD   = [213, 154, 55];    // #D59A37 (the pack's accent)
$CREAM  = [255, 244, 222];
$WHITE  = [255, 255, 255];
$MUTED  = [196, 212, 240];

/** One GD colour from an rgb triple. */
function og_col(\GdImage $im, array $rgb, int $alpha = 0): int
{
    return imagecolorallocatealpha($im, $rgb[0], $rgb[1], $rgb[2], $alpha);
}

/** Text width in px for this font / size (Latin through FreeType, Devanagari through DevShape). */
function og_width(string $font, float $size, string $text): int
{
    if (DevShape::needs($text)) {
        return DevShape::gdWidth($size, $text) ?? 0;
    }
    $b = imagettfbbox($size, 0, $font, $text);
    return $b === false ? 0 : (int) abs($b[2] - $b[0]);
}

/** Draw a line of text with its baseline at $y; $bold draws it twice, one px apart. */
function og_text(\GdImage $im, string $font, float $size, int $x, int $y, int $col, string $text, bool $bold = false): void
{
    if (DevShape::needs($text)) {
        DevShape::gdText($im, $size, $x, $y, $col, $text, $bold ? [[0, 0], [1, 0]] : [[0, 0]]);
        return;
    }
    imagettftext($im, $size, 0, $x, $y, $col, $font, $text);
    if ($bold) {
        imagettftext($im, $size, 0, $x + 1, $y, $col, $font, $text);
    }
}

/** The ⇄ glyph drawn: two stacked arrows, $h px tall, starting at $x; returns the width used. */
function og_arrows(\GdImage $im, int $x, int $midY, int $h, int $col): int
{
    $w = (int) round($h * 1.35);
    $t = max(2, (int) round($h / 9));
    imagesetthickness($im, $t);
    $y1 = $midY - intdiv($h, 4);
    $y2 = $midY + intdiv($h, 4);
    $head = intdiv($h, 4);
    // top arrow points right
    imageline($im, $x, $y1, $x + $w, $y1, $col);
    imageline($im, $x + $w, $y1, $x + $w - $head, $y1 - $head, $col);
    imageline($im, $x + $w, $y1, $x + $w - $head, $y1 + $head, $col);
    // bottom arrow points left
    imageline($im, $x, $y2, $x + $w, $y2, $col);
    imageline($im, $x, $y2, $x + $head, $y2 - $head, $col);
    imageline($im, $x, $y2, $x + $head, $y2 + $head, $col);
    imagesetthickness($im, 1);
    return $w;
}

$co   = Settings::company();
$name = trim((string) ($co['name'] ?? ''));
if ($name === '') {
    $name = APP_NAME;
}
$web = trim((string) ($co['web'] ?? ''));

/* ---- 1. canvas: navy → royal gradient, a faint light pool behind the render ---- */
$im = imagecreatetruecolor($W, $H);
imagealphablending($im, true);
DocArt::gradBox($im, 0, 0, $W - 1, $H - 1, 0, $NAVY, $ROYAL);
DocArt::halo($im, 880, 330, 420, 300, [120, 160, 230], 14, 0.28);

/* ---- 2. the coach render, contained in the right column (no crop) ---- */
$bus = @imagecreatefromwebp($root . '/assets/img/hd/bus-hero.webp');
if (!$bus) {
    fwrite(STDERR, "bus-hero.webp could not be read.\n");
    exit(1);
}
// the left column (copy) ends at x = 584, the card starts at 608 - nothing overlaps
$boxX = 608;
$boxY = 118;
$boxW = 552;
$boxH = 394;
$scale = min($boxW / imagesx($bus), $boxH / imagesy($bus));
$bw = (int) round(imagesx($bus) * $scale);
$bh = (int) round(imagesy($bus) * $scale);
$bx = $boxX + intdiv($boxW - $bw, 2);
$by = $boxY + intdiv($boxH - $bh, 2);
// a soft shadow under the card, then the picture, then rounded corners cut in the gradient colour
$shadow = og_col($im, [0, 0, 0], 96);
imagefilledrectangle($im, $bx + 6, $by + 10, $bx + $bw + 6, $by + $bh + 10, $shadow);
imagecopyresampled($im, $bus, $bx, $by, 0, 0, $bw, $bh, imagesx($bus), imagesy($bus));
imagedestroy($bus);
DocArt::roundCorners($im, $bx, $by, $bx + $bw - 1, $by + $bh - 1, 26, og_col($im, DocArt::mix($NAVY, $ROYAL, 0.62)));
// a 2 px gold rule along the bottom edge of the card - the pack's "gold as a small accent"
DocArt::goldFoil($im, $bx + 22, $by + $bh - 3, $bx + $bw - 22, $by + $bh - 1);
// 10 Oct 2026 (HD review): the render is not a photo of the fleet, so the card says so -
// a small "Illustration" pill in the card's top-right corner (the /offices caption's rule).
$pill = 'Illustration';
$pSize = 14.0;
$pW = og_width($font, $pSize, $pill) + 24;
$pH = 28;
$pX = $bx + $bw - $pW - 14;
$pY = $by + 14;
$pBg = og_col($im, DocArt::mix($NAVY, [0, 0, 0], 0.35));   // opaque, so the three shapes never double-darken
imagefilledrectangle($im, $pX + 10, $pY, $pX + $pW - 10, $pY + $pH, $pBg);
imagefilledellipse($im, $pX + 10, $pY + intdiv($pH, 2), 20, $pH + 1, $pBg);
imagefilledellipse($im, $pX + $pW - 10, $pY + intdiv($pH, 2), 20, $pH + 1, $pBg);
og_text($im, $font, $pSize, $pX + 12, $pY + 19, og_col($im, $WHITE), $pill, true);

/* ---- 3. the emblem on the navy, no tile (top-left) ---- */
$mark = DocArt::mark($im, 64, 54, 170, 118, false, '', 'left');
$textX = 64;

/* ---- 4. the company name - shrink until it fits the left column ---- */
$maxTextW = 470;
$size = 42.0;
$upper = DevShape::needs($name) ? $name : mb_strtoupper($name);
while ($size > 24 && og_width($font, $size, $upper) > $maxTextW) {
    $size -= 1.5;
}
$nameY = 262;
og_text($im, $font, $size, $textX, $nameY, og_col($im, $WHITE), $upper, true);
DocArt::goldFoil($im, $textX, $nameY + 16, $textX + min($maxTextW, max(160, og_width($font, $size, $upper))), $nameY + 19);

/* ---- 5. flags + "India ⇄ Nepal · AC Sleeper" ---- */
$lineY = 338;                                   // baseline
$flagH = 30;
$fx = DocArt::flagPair($im, $textX, $lineY - $flagH + 4, $flagH, 10, 'IN', true);
$x = $textX + max($fx, DocArt::flagPairWidth($flagH, 10)) + 18;
$gold = og_col($im, $GOLD);
$cream = og_col($im, $CREAM);
// the tagline must end before the card (x < 584): shrink until it fits
$s2 = 25.0;
$tagW = static fn(float $s): int => og_width($font, $s, 'India') + og_width($font, $s, 'Nepal') + og_width($font, $s, '·') + og_width($font, $s, 'AC Sleeper') + (int) round($s * 1.4) + 14 * 6;
while ($s2 > 16 && $x + $tagW($s2) > 584) {
    $s2 -= 1.0;
}
og_text($im, $font, $s2, $x, $lineY, $cream, 'India', true);
$x += og_width($font, $s2, 'India') + 14;
$x += og_arrows($im, $x, $lineY - 10, (int) round($s2), $gold) + 14;
og_text($im, $font, $s2, $x, $lineY, $cream, 'Nepal', true);
$x += og_width($font, $s2, 'Nepal') + 14;
og_text($im, $font, $s2, $x, $lineY, $gold, '·');
$x += og_width($font, $s2, '·') + 14;
og_text($im, $font, $s2, $x, $lineY, $cream, 'AC Sleeper', true);

/* ---- 6. what the service is, in one calm line, and the web address ---- */
og_text($im, $font, 18.0, $textX, 392, og_col($im, $MUTED), 'Direct AC sleeper coach');
og_text($im, $font, 18.0, $textX, 424, og_col($im, $MUTED), 'Online booking · WhatsApp ticket');
if ($web !== '') {
    og_text($im, $font, 21.0, $textX, 560, og_col($im, $CREAM), $web, true);
}

/* ---- 7. save: .tmp then rename; palette fallback keeps it under 400 KB ---- */
$tmp = $out . '.tmp';
imagepng($im, $tmp, 9);
if (filesize($tmp) > 380 * 1024) {
    imagetruecolortopalette($im, true, 255);
    imagepng($im, $tmp, 9);
}
imagedestroy($im);
if (!rename($tmp, $out)) {
    fwrite(STDERR, "Could not write {$out}\n");
    exit(1);
}
$info = getimagesize($out);
printf("og-shg.png: %dx%d, %d KB, emblem %s\n", (int) ($info[0] ?? 0), (int) ($info[1] ?? 0), (int) round(filesize($out) / 1024), $mark ? 'placed' : 'MISSING');
exit(0);
