<?php
/**
 * =====================================================================
 *  tools/make-mark-tm.php — the ™ on the EMBLEM files too (29 Oct 2026,
 *  owner's overhaul prompt part 4: "Every instance of the logo across the
 *  entire website must show the TM mark ... in both white and black
 *  versions, adapting to background").
 *
 *  tools/make-logo-tm.php already cut assets/img/logo-tm.png (the full
 *  logo, ™ beside the wordmark). The emblem-only files — the nav mark,
 *  the 96 px mark, the ticket mark — carried no sign. This script writes,
 *  for each of them, two copies with the ™ set at the arrow tip (the
 *  emblem's top-right ink corner), the way the owner's demo logo shows it:
 *
 *      <name>-tm.<ext>   navy ™ with a thin white halo — for light grounds
 *                        (paper, PDFs, white cards); still reads on dark
 *      <name>-tmw.<ext>  white ™ with a thin navy halo — for dark grounds
 *                        (the navy site header, the night coach band, footer)
 *
 *  The sources are never touched, so the marks can be re-cut at any time:
 *      php tools/make-mark-tm.php
 *
 *  Placement comes from the artwork (ink bounding box), not typed numbers:
 *  the sign is 17% of the ink height, set in the free corner right of the
 *  arrow tip (above the bus); the canvas widens only if it would not fit.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = dirname(__DIR__);
$img  = $root . '/assets/img';
$font = $root . '/assets/fonts/NotoSansDevanagari.ttf';
if (!is_file($font)) { fwrite(STDERR, "missing: $font\n"); exit(1); }

/* source => also write .webp copies */
$sources = [
    'logo-mark.png'        => true,
    'logo-mark-96h.png'    => true,
    'logo-nav.png'         => false,
    'logo-mark-ticket.png' => false,
];

$variants = [
    'tm'  => [[0x0E, 0x28, 0x56], [0xFF, 0xFF, 0xFF]],   // navy sign, white halo
    'tmw' => [[0xFF, 0xFF, 0xFF], [0x0E, 0x28, 0x56]],   // white sign, navy halo
];

foreach ($sources as $file => $webp) {
    $src = $img . '/' . $file;
    if (!is_file($src)) { fwrite(STDERR, "missing: $src\n"); exit(1); }

    foreach ($variants as $suffix => [$ink, $halo]) {
        $im = imagecreatefrompng($src);
        if ($im === false) { fwrite(STDERR, "cannot read $src\n"); exit(1); }
        imagepalettetotruecolor($im);
        imagealphablending($im, true);
        imagesavealpha($im, true);
        $w = imagesx($im);
        $h = imagesy($im);

        /* ---- 1. the ink box ------------------------------------------ */
        $minX = $w; $minY = $h; $maxX = -1; $maxY = -1;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 40) {
                    if ($x < $minX) { $minX = $x; }
                    if ($x > $maxX) { $maxX = $x; }
                    if ($y < $minY) { $minY = $y; }
                    if ($y > $maxY) { $maxY = $y; }
                }
            }
        }
        if ($maxX < 0) { fwrite(STDERR, "no ink in $src\n"); exit(1); }
        $inkH = $maxY - $minY + 1;

        /* The arrow tip is the rightmost ink in the top 45% of the emblem;
           the bus front below it reaches further right, so the free corner
           right of the tip and above the bus holds the sign without
           changing the file's size (every <img> keeps its width / height). */
        $tipX = -1;
        $band = $minY + (int) round($inkH * 0.45);
        for ($y = $minY; $y <= $band; $y++) {
            for ($x = $w - 1; $x > $tipX; $x--) {
                if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < 40) { $tipX = $x; break; }
            }
        }

        /* ---- 2. size and place the sign ------------------------------ */
        $size = max(7.0, $inkH * 0.17);
        $box  = imagettfbbox($size, 0, $font, "\u{2122}");
        $tmW  = (int) ceil($box[2] - $box[0]);
        $tmH  = (int) ceil(abs($box[5] - $box[1]));
        $pad  = max(1, (int) round($size * 0.08));          // halo width
        $gap  = max(1, (int) round($size * 0.15));
        $x    = $tipX + $gap;
        $base = $minY + $tmH + 2 * $pad + (int) round($size * 0.25);   // clear of the top edge

        $need = $x + $tmW + 2 * $pad + 1;
        if ($need > $w) {                                    // widen rather than crop
            $wide = imagecreatetruecolor($need, $h);
            imagealphablending($wide, false);
            imagesavealpha($wide, true);
            imagefilledrectangle($wide, 0, 0, $need - 1, $h - 1, imagecolorallocatealpha($wide, 0, 0, 0, 127));
            imagealphablending($wide, true);
            imagecopy($wide, $im, 0, 0, 0, 0, $w, $h);
            imagedestroy($im);
            $im = $wide;
            $w  = $need;
        }

        /* ---- 3. halo, then the sign ---------------------------------- */
        $hc = imagecolorallocate($im, $halo[0], $halo[1], $halo[2]);
        $ic = imagecolorallocate($im, $ink[0], $ink[1], $ink[2]);
        for ($dx = -$pad; $dx <= $pad; $dx++) {
            for ($dy = -$pad; $dy <= $pad; $dy++) {
                if ($dx === 0 && $dy === 0) { continue; }
                imagettftext($im, $size, 0, $x + $dx, $base + $dy, $hc, $font, "\u{2122}");
            }
        }
        imagettftext($im, $size, 0, $x, $base, $ic, $font, "\u{2122}");

        /* ---- 4. write ------------------------------------------------ */
        $stem = substr($file, 0, -4) . '-' . $suffix;
        imagesavealpha($im, true);
        imagepng($im, $img . '/' . $stem . '.png', 9);
        $out = $stem . '.png (' . filesize($img . '/' . $stem . '.png') . ' bytes)';
        if ($webp) {
            imagewebp($im, $img . '/' . $stem . '.webp', 92);
            $out .= ', ' . $stem . '.webp (' . filesize($img . '/' . $stem . '.webp') . ' bytes)';
        }
        printf("%-22s ink %dx%d, %dx%d -> %s\n", $file, $maxX - $minX + 1, $inkH, $w, $h, $out);
        imagedestroy($im);
    }
}
