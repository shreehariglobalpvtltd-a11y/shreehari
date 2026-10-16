<?php
/**
 * =====================================================================
 *  tools/make-logo-tm.php — the full company logo, with the ™ sign
 *  (16 Oct 2026, owner: "logo ma main change garnu paryo ... dekhine
 *  garera TM lekhdeu clearly, logo klasile use garna napaos")
 *
 *  assets/img/logo.png is the owner's artwork: the emblem with
 *  "S HARI GLOBAL" and "PRIVATE LIMITED" set under it. This script
 *  writes assets/img/logo-tm.png (and .webp) — the same artwork with a
 *  trademark sign set beside the wordmark, in the wordmark's own navy.
 *
 *  The source is never touched, so the mark can be re-cut at any time:
 *      php tools/make-logo-tm.php
 *
 *  The sign is placed from the artwork itself, not from typed numbers:
 *  the script finds the wordmark band (the ink rows under the emblem),
 *  takes its right edge and cap height, and sets the ™ at 40% of that
 *  cap height, top-aligned with the capitals. If a future logo sets the
 *  name differently the placement follows it.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = dirname(__DIR__);
$src  = $root . '/assets/img/logo.png';
$out  = $root . '/assets/img/logo-tm.png';
$outW = $root . '/assets/img/logo-tm.webp';
$font = $root . '/assets/fonts/NotoSansDevanagari.ttf';

foreach ([$src, $font] as $f) {
    if (!is_file($f)) { fwrite(STDERR, "missing: $f\n"); exit(1); }
}

$im = imagecreatefrompng($src);
if ($im === false) { fwrite(STDERR, "cannot read $src\n"); exit(1); }
imagepalettetotruecolor($im);           // the source ships as a palette PNG
imagealphablending($im, true);
imagesavealpha($im, true);
$w = imagesx($im);
$h = imagesy($im);

/* ---- 1. find the ink rows, then the wordmark band ------------------- */
$rows = [];
for ($y = 0; $y < $h; $y++) {
    $maxX = -1;
    for ($x = $w - 1; $x >= 0; $x--) {
        if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 40) { $maxX = $x; break; }
    }
    $rows[$y] = $maxX;
}
/* Bands are runs of inked rows. The emblem is the first, the wordmark the
   second, "PRIVATE LIMITED" the third. */
$bands = [];
$start = null;
for ($y = 0; $y <= $h; $y++) {
    $ink = $y < $h && $rows[$y] >= 0;
    if ($ink && $start === null) { $start = $y; }
    if (!$ink && $start !== null) { $bands[] = [$start, $y - 1]; $start = null; }
}
if (count($bands) < 2) { fwrite(STDERR, "no wordmark band found in $src\n"); exit(1); }
[$bTop, $bBot] = $bands[1];
$cap   = $bBot - $bTop + 1;
$right = 0;
for ($y = $bTop; $y <= $bBot; $y++) { $right = max($right, $rows[$y]); }

/* ---- 2. set the ™ beside it ----------------------------------------- */
$size = $cap * 0.62;                       // 62% of the cap height — readable at nav size
$box  = imagettfbbox($size, 0, $font, "\u{2122}");
$tmW  = $box[2] - $box[0];
$tmH  = abs($box[5] - $box[1]);
$gap  = (int) round($cap * 0.12);
$x    = $right + $gap;
$base = $bTop + $tmH;                      // top-aligned with the capitals

if ($x + $tmW > $w) {                      // widen rather than crop the sign
    $wide = imagecreatetruecolor($x + (int) $tmW + $gap, $h);
    imagealphablending($wide, false);
    imagesavealpha($wide, true);
    imagefilledrectangle($wide, 0, 0, imagesx($wide) - 1, $h - 1, imagecolorallocatealpha($wide, 0, 0, 0, 127));
    imagealphablending($wide, true);
    imagecopy($wide, $im, 0, 0, 0, 0, $w, $h);
    imagedestroy($im);
    $im = $wide;
    $w  = imagesx($im);
}

$navy = imagecolorallocate($im, 0x0E, 0x28, 0x56);   // the wordmark's own navy
imagettftext($im, $size, 0, (int) round($x), (int) round($base), $navy, $font, "\u{2122}");

/* ---- 3. write ------------------------------------------------------- */
imagesavealpha($im, true);
imagepng($im, $out, 9);
imagewebp($im, $outW, 92);
imagedestroy($im);

printf("wordmark band y=%d..%d (cap %dpx), right edge x=%d\n", $bTop, $bBot, $cap, $right);
printf("TM at x=%d baseline=%d size=%.1f -> %s (%d bytes), %s (%d bytes)\n",
    (int) round($x), (int) round($base), $size,
    basename($out), filesize($out), basename($outW), filesize($outW));
