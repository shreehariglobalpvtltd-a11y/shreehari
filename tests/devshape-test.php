<?php
/**
 * devshape-test.php — Devanagari on the PNG / PDF documents is shaped by
 * HarfBuzz, and falls back safely when it cannot be (24 Sep 2026).
 *
 * GD and Pdf::textCID map characters to glyphs one by one, so every conjunct
 * printed broken on the tickets: यात्‌रु, जम्‌मा, कृष्‌ण. includes/devshape.php
 * shapes the text with libharfbuzz (FFI in the CLI, a CLI child for web
 * requests) and draws glyph ids — through NotoSansDevanagari-gid.ttf for GD,
 * straight into the Identity-H stream for the PDF.
 *
 *   1. the gid font is the shipped font plus the private mapping, nothing else
 *   2. HarfBuzz forms the conjuncts (glyph ids the plain cmap never yields)
 *   3. the CLI child the web requests use agrees with in-process shaping
 *   4. GD draws the shaped text, and it differs from the unshaped drawing
 *   5. the PDF carries the shaped glyph ids
 *   6. with shaping switched off, nothing breaks: shape() is null and the
 *      callers keep their old path
 *
 * Reads only (renders into memory; the disk cache lives in private /tmp).
 *
 *   php tests/devshape-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/pdf.php';

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

/** Every codepoint -> glyph mapping of a TrueType cmap (formats 4 and 12). */
function cmapOf(string $file): array
{
    $b = (string) file_get_contents($file);
    $n = unpack('n', $b, 4)[1];
    $cmap = null;
    for ($i = 0; $i < $n; $i++) {
        $o = 12 + 16 * $i;
        if (substr($b, $o, 4) === 'cmap') {
            $cmap = unpack('N', $b, $o + 8)[1];
        }
    }
    $map = [];
    $subs = unpack('n', $b, $cmap + 2)[1];
    for ($i = 0; $i < $subs; $i++) {
        $rec = $cmap + 4 + 8 * $i;
        [$pid, $eid] = [unpack('n', $b, $rec)[1], unpack('n', $b, $rec + 2)[1]];
        if (!($pid === 0 || ($pid === 3 && ($eid === 1 || $eid === 10)))) {
            continue;
        }
        $st = $cmap + unpack('N', $b, $rec + 4)[1];
        $fmt = unpack('n', $b, $st)[1];
        if ($fmt === 12) {
            $groups = unpack('N', $b, $st + 12)[1];
            for ($g = 0; $g < $groups; $g++) {
                [$a, $z, $g0] = array_values(unpack('N3', $b, $st + 16 + 12 * $g));
                for ($c = $a; $c <= $z; $c++) {
                    $map[$c] ??= $g0 + ($c - $a);
                }
            }
        } elseif ($fmt === 4) {
            $segX2 = unpack('n', $b, $st + 6)[1];
            $ends = $st + 14; $starts = $ends + $segX2 + 2; $deltas = $starts + $segX2; $ros = $deltas + $segX2;
            for ($s = 0; $s < $segX2 / 2; $s++) {
                $end = unpack('n', $b, $ends + 2 * $s)[1]; $start = unpack('n', $b, $starts + 2 * $s)[1];
                $delta = unpack('n', $b, $deltas + 2 * $s)[1]; $ro = unpack('n', $b, $ros + 2 * $s)[1];
                for ($c = $start; $c <= $end && $c !== 0xFFFF; $c++) {
                    if ($ro === 0) {
                        $gid = ($c + $delta) & 0xFFFF;
                    } else {
                        $gid = unpack('n', $b, $ros + 2 * $s + $ro + 2 * ($c - $start))[1];
                        if ($gid !== 0) { $gid = ($gid + $delta) & 0xFFFF; }
                    }
                    if ($gid !== 0) { $map[$c] ??= $gid; }
                }
            }
        }
    }
    return $map;
}

echo "\n=== Devanagari shaping on the documents (HarfBuzz) ===\n\n";

/* ---- 1. the gid font ------------------------------------------------ */
$src = DevShape::fontFile();
$gid = DevShape::gidFontFile();
check('both fonts ship', is_file($src) && is_file($gid));
$a = cmapOf($src);
$b = cmapOf($gid);
$lost = 0;
foreach ($a as $c => $g) {
    if (($b[$c] ?? null) !== $g) { $lost++; }
}
check('the gid font keeps every original mapping', $a !== [] && $lost === 0, count($a) . ' mappings, ' . $lost . ' changed');
$okPua = true;
for ($g = 0; $g < 1000; $g += 37) {
    if (($b[DevShape::PUA + $g] ?? -1) !== $g) { $okPua = false; break; }
}
check('U+E000 + id maps to glyph id in the gid font', $okPua);
check('the private range is private: the shipped font maps nothing there', !array_filter(array_keys($a), static fn($c) => $c >= DevShape::PUA && $c <= 0xF8FF));
check('the outlines are the same bytes (glyf identical)', (static function () use ($src, $gid): bool {
    $pick = static function (string $f): string {
        $b = (string) file_get_contents($f);
        $n = unpack('n', $b, 4)[1];
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + 16 * $i;
            if (substr($b, $o, 4) === 'glyf') {
                return substr($b, unpack('N', $b, $o + 8)[1], unpack('N', $b, $o + 12)[1]);
            }
        }
        return '';
    };
    $x = $pick($src);
    return $x !== '' && $x === $pick($gid);
})());

/* ---- 2. HarfBuzz forms the conjuncts --------------------------------- */
$words = ['यात्रु', 'जम्मा', 'कृष्ण', 'श्रेष्ठ', 'कार्यालय', 'सिता'];
$local = DevShape::shapeLocal($words);
check('libharfbuzz loads through FFI in the CLI', is_array($local), is_array($local) ? '' : 'install libharfbuzz0b');
if (is_array($local)) {
    $cmapGlyphs = array_flip(array_values($a));
    foreach (array_slice($words, 0, 5) as $i => $w) {
        $ids = array_column($local[$i]['g'], 0);
        $formed = (bool) array_filter($ids, static fn($id) => !isset($cmapGlyphs[$id]));
        $shorter = count($ids) < mb_strlen($w);
        check("'{$w}' is shaped: conjunct glyphs, not one glyph per character", $formed || $shorter, count($ids) . ' glyphs for ' . mb_strlen($w) . ' characters');
    }
    // सिता: the i-matra glyph must come BEFORE the स glyph
    $ids = array_column($local[5]['g'], 0);
    $sa = $a[0x0938] ?? -1;
    check("'सिता': the i-matra is drawn before its consonant", ($ids[0] ?? -1) !== $sa && in_array($sa, $ids, true), implode(',', $ids));
}

/* ---- 3. the CLI child (what php-fpm uses) agrees --------------------- */
$php = PHP_BINDIR . '/php';
$proc = proc_open([is_executable($php) ? $php : PHP_BINARY, dirname(__DIR__) . '/includes/devshape-cli.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], (string) json_encode(['texts' => $words], JSON_UNESCAPED_UNICODE));
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]);
fclose($pipes[1]); fclose($pipes[2]);
proc_close($proc);
$child = json_decode((string) $out, true);
check('the CLI child answers with the same glyphs as in-process shaping', is_array($child) && ($child['runs'] ?? null) == $local);

/* ---- 4. GD draws the shaped text ------------------------------------- */
if (is_array($local)) {
    $draw = static function (bool $shaped): string {
        $im = imagecreatetruecolor(420, 60);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $ink = imagecolorallocate($im, 0, 0, 0);
        if ($shaped) {
            DevShape::gdText($im, 22, 10, 44, $ink, 'यात्रु कृष्ण जम्मा');
        } else {
            imagettftext($im, 22, 0, 10, 44, $ink, DevShape::fontFile(), dev_shape('यात्रु कृष्ण जम्मा'));
        }
        ob_start(); imagepng($im); return (string) ob_get_clean();
    };
    $s1 = $draw(true);
    $s0 = $draw(false);
    check('DevShape::gdText draws', strlen($s1) > 400);
    check('the shaped drawing differs from the unshaped one (the halants are gone)', $s1 !== $s0);
    $w = DevShape::gdWidth(22, 'यात्रु');
    check('gdWidth measures shaped text', is_int($w) && $w > 20 && $w < 200, (string) $w);
}

/* ---- 5. the PDF carries the shaped glyph ids ------------------------- */
try {
    $pdf = new Pdf();
    $pdf->registerTTF(DevShape::fontFile(), 'F7');
    $pdf->textCID(40, 40, 'यात्रु कृष्ण', 14, 'F7', [0, 0, 0]);
    $w1 = $pdf->textWidthCID('यात्रु', 14, 'F7');
    $bytes = $pdf->output();
    $want = is_array($local) ? sprintf('%04X', $local[0]['g'][0][0]) : 'none';
    check('Pdf::textCID renders Devanagari', strlen($bytes) > 1000);
    $expect = is_array($local) ? array_sum(array_column($local[0]['g'], 1)) / $local[0]['upem'] * 14 : -1;
    check('the PDF width is the shaped width', $w1 > 0 && abs($w1 - $expect) < 0.05, sprintf('%.2f pt, HarfBuzz %.2f pt', $w1, $expect));
    $stream = '';
    if (preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $bytes, $m)) {
        foreach ($m[1] as $chunk) {
            $z = @gzuncompress($chunk);
            $stream .= $z !== false ? $z : $chunk;
        }
    }
    check('the page stream positions shaped glyph runs (Tm + Tj)', str_contains($stream, ' Tm') && str_contains($stream, '<' . $want));
} catch (Throwable $e) {
    check('Pdf::textCID renders Devanagari', false, $e->getMessage());
}

/* ---- 6. switched off: nothing breaks --------------------------------- */
$code = 'require "' . addslashes(dirname(__DIR__)) . '/includes/bootstrap.php"; '
      . 'var_export([DevShape::shape("यात्रु"), DevShape::gdWidth(20, "यात्रु")]);';
$off = shell_exec('SHG_DEVSHAPE_OFF=1 ' . escapeshellarg(is_executable($php) ? $php : PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
check('SHG_DEVSHAPE_OFF=1: shape() is null and callers fall back', is_string($off) && str_contains($off, 'NULL') && !str_contains($off, 'Fatal'), trim((string) $off));
check('the old path is still there (dev_shape fallback in gdText/gdWidth/textCID)',
    substr_count((string) file_get_contents(dirname(__DIR__) . '/includes/ticket.php'), '$text = dev_shape($text);') >= 2
    && str_contains((string) file_get_contents(dirname(__DIR__) . '/includes/pdf.php'), '$text = dev_shape($text);'));

echo "\n----------------------------------------\n";
echo "  {$PASS} passed, {$FAIL} failed\n";
echo "----------------------------------------\n\n";

exit($FAIL === 0 ? 0 : 1);
