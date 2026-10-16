<?php
/**
 * =====================================================================
 *  DevShape — real Devanagari shaping for the PNG and PDF documents
 *  (24 Sep 2026, owner: "nepali fonts haru bujeko xain, ekchoti ramro
 *  sita gardeu").
 *
 *  THE BUG: GD (imagettftext) and our own Pdf::textCID map characters to
 *  glyphs one at a time and never run the font's OpenType rules. Latin does
 *  not need them; Devanagari cannot do without them. So every conjunct and
 *  reph printed as consonant + visible halant + consonant — यात्‌रु, जम्‌मा,
 *  काट्‌ने, कृष्‌ण — on the WhatsApp ticket, the payment image, the PDF
 *  ticket and the chalani. dev_shape() (helpers.php) only ever moved the
 *  i-matra, and its comment claiming GD forms conjuncts was measured wrong
 *  on the live box (libgd 2.3.3 links FreeType only, no raqm/HarfBuzz).
 *
 *  THE FIX: libharfbuzz0b (Ubuntu package, installed 24 Sep 2026 with the
 *  owner's yes) does the shaping — the same engine Chrome and Android use —
 *  and hands back glyph ids with positions:
 *    - GD draws a glyph by id through assets/fonts/NotoSansDevanagari-gid.ttf,
 *      the same font with character U+E000+id mapped to glyph id
 *      (deploy/build-gid-font.js);
 *    - Pdf::textCID already writes glyph ids, so it takes them directly.
 *
 *  FFI is enabled for the CLI only (ffi.enable=preload), so a web request
 *  shapes through a short-lived `php includes/devshape-cli.php` child fed
 *  JSON on stdin; cron and CLI tools shape in-process. Results are cached
 *  in memory and on disk (private /tmp), so a label is shaped once.
 *
 *  FAILS SAFE: if HarfBuzz, FFI, the child or the gid font is missing,
 *  shape() returns null and every caller keeps its old drawing path —
 *  exactly the output before this file existed. Nothing here can stop a
 *  ticket from being produced.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class DevShape
{
    /** First private-use code point: U+E000 + glyph id draws that glyph
        (BMP only: libgd decodes 1-3 byte UTF-8, nothing beyond U+FFFF). */
    public const PUA = 0xE000;

    /** Bump when the cached shape format changes. */
    private const CACHE_VERSION = 1;

    private const HB_CDEF = <<<'C'
typedef struct hb_blob_t hb_blob_t;
typedef struct hb_face_t hb_face_t;
typedef struct hb_font_t hb_font_t;
typedef struct hb_buffer_t hb_buffer_t;
typedef const struct hb_language_impl_t *hb_language_t;
typedef union { uint32_t u32; int32_t i32; uint16_t u16[2]; int16_t i16[2]; uint8_t u8[4]; int8_t i8[4]; } hb_var_int_t;
typedef struct { uint32_t codepoint; uint32_t mask; uint32_t cluster; hb_var_int_t var1; hb_var_int_t var2; } hb_glyph_info_t;
typedef struct { int32_t x_advance; int32_t y_advance; int32_t x_offset; int32_t y_offset; hb_var_int_t var; } hb_glyph_position_t;
hb_blob_t *hb_blob_create_from_file(const char *file_name);
void hb_blob_destroy(hb_blob_t *blob);
unsigned int hb_blob_get_length(hb_blob_t *blob);
hb_face_t *hb_face_create(hb_blob_t *blob, unsigned int index);
void hb_face_destroy(hb_face_t *face);
unsigned int hb_face_get_upem(const hb_face_t *face);
hb_font_t *hb_font_create(hb_face_t *face);
void hb_font_destroy(hb_font_t *font);
void hb_font_set_scale(hb_font_t *font, int x_scale, int y_scale);
hb_buffer_t *hb_buffer_create(void);
void hb_buffer_destroy(hb_buffer_t *buffer);
void hb_buffer_reset(hb_buffer_t *buffer);
void hb_buffer_add_utf8(hb_buffer_t *buffer, const char *text, int text_length, unsigned int item_offset, int item_length);
void hb_buffer_set_direction(hb_buffer_t *buffer, int direction);
void hb_buffer_set_script(hb_buffer_t *buffer, uint32_t script);
void hb_buffer_set_language(hb_buffer_t *buffer, hb_language_t language);
hb_language_t hb_language_from_string(const char *str, int len);
void hb_shape(hb_font_t *font, hb_buffer_t *buffer, const void *features, unsigned int num_features);
hb_glyph_info_t *hb_buffer_get_glyph_infos(hb_buffer_t *buffer, unsigned int *length);
hb_glyph_position_t *hb_buffer_get_glyph_positions(hb_buffer_t *buffer, unsigned int *length);
C;

    private const HB_DIRECTION_LTR  = 4;
    private const HB_SCRIPT_DEVA    = 0x44657661; // 'Deva'

    /** @var array<string, array{upem:int, g:list<array{0:int,1:int,2:int,3:int}>}|null> */
    private static array $mem = [];
    private static ?bool $disabled = null;
    private static ?array $hb = null; // [FFI, font, upem]

    /** The font HarfBuzz shapes with, and the one the documents embed. */
    public static function fontFile(): string
    {
        return dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari.ttf';
    }

    /** The same font with U+E000+id -> glyph id, for GD. */
    public static function gidFontFile(): string
    {
        return dirname(__DIR__) . '/assets/fonts/NotoSansDevanagari-gid.ttf';
    }

    /** Does this text need shaping at all? Only Devanagari does. */
    public static function needs(string $text): bool
    {
        return (bool) preg_match('/\p{Devanagari}/u', $text);
    }

    /**
     * Shape one run of text.
     *
     * @return array{upem:int, g:list<array{0:int,1:int,2:int,3:int}>}|null
     *         g = [glyph id, x advance, x offset, y offset] in font units
     *         (y up); null = shaping unavailable, use the old path.
     */
    public static function shape(string $text): ?array
    {
        if ($text === '' || self::isDisabled()) {
            return null;
        }
        if (array_key_exists($text, self::$mem)) {
            return self::$mem[$text];
        }
        self::prime([$text]);
        return self::$mem[$text] ?? null;
    }

    /**
     * Shape many runs at once (one child process for a whole document
     * instead of one per label). Safe to call with already-cached texts.
     *
     * @param list<string> $texts
     */
    public static function prime(array $texts): void
    {
        if (self::isDisabled()) {
            return;
        }
        $todo = [];
        foreach ($texts as $t) {
            $t = (string) $t;
            if ($t === '' || array_key_exists($t, self::$mem) || isset($todo[$t])) {
                continue;
            }
            $hit = self::cacheGet($t);
            if ($hit !== null) {
                self::$mem[$t] = $hit;
                continue;
            }
            $todo[$t] = true;
        }
        if (!$todo) {
            return;
        }
        $list = array_keys($todo);
        $list = array_map('strval', $list);
        $shaped = self::canFfi() ? self::shapeLocal($list) : self::shapeChild($list);
        if ($shaped === null) {
            foreach ($list as $t) {
                self::$mem[$t] = null;
            }
            return;
        }
        foreach ($list as $i => $t) {
            $s = $shaped[$i] ?? null;
            self::$mem[$t] = $s;
            if ($s !== null) {
                self::cachePut($t, $s);
            }
        }
    }

    /* -----------------------------------------------------------------
     *  GD drawing
     * ----------------------------------------------------------------- */

    /** GD sizes are points at 96 dpi: pixels per font unit at $size. */
    private static function k(float $size, int $upem): float
    {
        return $size * 96.0 / 72.0 / $upem;
    }

    /**
     * Draw shaped text with GD at baseline ($x, $y). $strikes are the pixel
     * offsets each glyph is drawn at — [[0,0]] plain, more for the callers'
     * faux bold (the shipped face has no Bold). Returns false (and draws
     * nothing) when shaping is unavailable — the caller then draws the way
     * it always did.
     *
     * @param list<array{0:int,1:int}> $strikes
     */
    public static function gdText($im, float $size, int $x, int $y, int $col, string $text, array $strikes = [[0, 0]]): bool
    {
        $s = self::shape($text);
        $gid = self::gidFontFile();
        if ($s === null || !is_file($gid)) {
            return false;
        }
        $k = self::k($size, $s['upem']);
        $pen = 0;
        foreach ($s['g'] as [$g, $ax, $dx, $dy]) {
            $ch = mb_chr(self::PUA + $g, 'UTF-8');
            $gx = (int) round($x + ($pen + $dx) * $k);
            $gy = (int) round($y - $dy * $k);
            foreach ($strikes as [$ox, $oy]) {
                imagettftext($im, $size, 0, $gx + $ox, $gy + $oy, $col, $gid, $ch);
            }
            $pen += $ax;
        }
        return true;
    }

    /** Advance width in pixels at $size, or null when shaping is unavailable. */
    public static function gdWidth(float $size, string $text): ?int
    {
        $s = self::shape($text);
        if ($s === null || !is_file(self::gidFontFile())) {
            return null;
        }
        $w = 0;
        foreach ($s['g'] as $g) {
            $w += $g[1];
        }
        return (int) round($w * self::k($size, $s['upem']));
    }

    /* -----------------------------------------------------------------
     *  Backends
     * ----------------------------------------------------------------- */

    private static function isDisabled(): bool
    {
        if (self::$disabled === null) {
            self::$disabled = !is_file(self::fontFile())
                || (getenv('SHG_DEVSHAPE_OFF') !== false && getenv('SHG_DEVSHAPE_OFF') !== '');
        }
        return self::$disabled;
    }

    /** FFI usable in this process? (CLI with ffi.enable = preload or 1.) */
    private static function canFfi(): bool
    {
        if (!extension_loaded('ffi') || !class_exists('FFI')) {
            return false;
        }
        $mode = strtolower((string) ini_get('ffi.enable'));
        if ($mode === '1' || $mode === 'true' || $mode === 'on') {
            return true;
        }
        return $mode === 'preload' && PHP_SAPI === 'cli';
    }

    /**
     * Shape in this process through libharfbuzz.
     *
     * @param list<string> $texts
     * @return list<array|null>|null
     */
    public static function shapeLocal(array $texts): ?array
    {
        try {
            if (self::$hb === null) {
                $ffi = null;
                foreach (['libharfbuzz.so.0', 'libharfbuzz.so'] as $lib) {
                    try {
                        $ffi = \FFI::cdef(self::HB_CDEF, $lib);
                        break;
                    } catch (\Throwable $e) {
                        $ffi = null;
                    }
                }
                if ($ffi === null) {
                    return null;
                }
                $blob = $ffi->hb_blob_create_from_file(self::fontFile());
                if ($ffi->hb_blob_get_length($blob) < 1000) {
                    return null;
                }
                $face = $ffi->hb_face_create($blob, 0);
                $upem = (int) $ffi->hb_face_get_upem($face);
                $font = $ffi->hb_font_create($face);
                $ffi->hb_font_set_scale($font, $upem, $upem);
                self::$hb = [$ffi, $font, $upem];
            }
            [$ffi, $font, $upem] = self::$hb;
            $lang = $ffi->hb_language_from_string('ne', -1);
            $out  = [];
            foreach ($texts as $text) {
                $buf = $ffi->hb_buffer_create();
                $ffi->hb_buffer_add_utf8($buf, $text, strlen($text), 0, -1);
                $ffi->hb_buffer_set_direction($buf, self::HB_DIRECTION_LTR);
                $ffi->hb_buffer_set_script($buf, self::HB_SCRIPT_DEVA);
                $ffi->hb_buffer_set_language($buf, $lang);
                $ffi->hb_shape($font, $buf, null, 0);
                $n = $ffi->new('unsigned int');
                $info = $ffi->hb_buffer_get_glyph_infos($buf, \FFI::addr($n));
                $pos  = $ffi->hb_buffer_get_glyph_positions($buf, null);
                $g = [];
                for ($i = 0, $len = (int) $n->cdata; $i < $len; $i++) {
                    $g[] = [(int) $info[$i]->codepoint, (int) $pos[$i]->x_advance,
                            (int) $pos[$i]->x_offset, (int) $pos[$i]->y_offset];
                }
                $ffi->hb_buffer_destroy($buf);
                $out[] = ['upem' => $upem, 'g' => $g];
            }
            return $out;
        } catch (\Throwable $e) {
            self::note('ffi: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Shape through a CLI child (web requests cannot use FFI).
     *
     * @param list<string> $texts
     * @return list<array|null>|null
     */
    private static function shapeChild(array $texts): ?array
    {
        $php = self::cliBinary();
        $cli = __DIR__ . '/devshape-cli.php';
        if ($php === null || !is_file($cli) || !function_exists('proc_open')) {
            return null;
        }
        $proc = @proc_open([$php, '-d', 'display_errors=stderr', $cli],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            self::note('child: proc_open failed');
            return null;
        }
        fwrite($pipes[0], (string) json_encode(['texts' => $texts], JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + 8.0;
        while (true) {
            $r = [$pipes[1], $pipes[2]];
            $w = $e = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                break;
            }
            if (@stream_select($r, $w, $e, 0, (int) min(200000, $left * 1e6)) === false) {
                break;
            }
            foreach ($r as $p) {
                $chunk = (string) fread($p, 65536);
                if ($p === $pipes[1]) { $out .= $chunk; } else { $err .= $chunk; }
            }
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_get_status($proc);
        if ($status['running']) {
            proc_terminate($proc);
        }
        proc_close($proc);
        $data = json_decode($out, true);
        if (!is_array($data) || !isset($data['runs']) || !is_array($data['runs']) || count($data['runs']) !== count($texts)) {
            self::note('child: bad reply ' . substr(trim($err . ' ' . $out), 0, 200));
            return null;
        }
        return $data['runs'];
    }

    private static function cliBinary(): ?string
    {
        foreach ([PHP_BINDIR . '/php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, PHP_BINDIR . '/php', '/usr/bin/php'] as $p) {
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }
        return (PHP_SAPI === 'cli' && is_executable(PHP_BINARY)) ? PHP_BINARY : null;
    }

    /* -----------------------------------------------------------------
     *  Disk cache (the private /tmp of whichever service runs this)
     * ----------------------------------------------------------------- */

    private static function cacheDir(): string
    {
        $uid = function_exists('posix_geteuid') ? (string) posix_geteuid() : 'u';
        return rtrim(sys_get_temp_dir(), '/\\') . '/shg-devshape-' . $uid;
    }

    private static function cacheKey(string $text): string
    {
        static $sig = null;
        if ($sig === null) {
            $f = self::fontFile();
            $sig = self::CACHE_VERSION . ':' . (int) @filesize($f) . ':' . (int) @filemtime($f);
        }
        return md5($sig . "\0" . $text);
    }

    private static function cacheGet(string $text): ?array
    {
        $f = self::cacheDir() . '/' . self::cacheKey($text) . '.json';
        if (!is_file($f)) {
            return null;
        }
        $d = json_decode((string) @file_get_contents($f), true);
        return (is_array($d) && isset($d['upem'], $d['g']) && is_array($d['g'])) ? $d : null;
    }

    private static function cachePut(string $text, array $shape): void
    {
        $dir = self::cacheDir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $f = $dir . '/' . self::cacheKey($text) . '.json';
        $tmp = $f . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, (string) json_encode($shape)) !== false) {
            @rename($tmp, $f);
        }
    }

    private static function note(string $msg): void
    {
        static $said = [];
        if (isset($said[$msg])) {
            return;
        }
        $said[$msg] = true;
        error_log('DevShape: ' . $msg);
    }
}
