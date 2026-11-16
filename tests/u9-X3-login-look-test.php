<?php
/**
 * =====================================================================
 *  u9-X3-login-look-test.php — master v2.0, unit X3-login-look (16 Nov 2026).
 *
 *    P1-2e + P1-7a + P1-7a2  the optional gold look on the staff door
 *    (admin/login.php, the one door). Setting login_theme:
 *      'classic' (default, a missing row, any other value) = the page exactly
 *                as before — rendered byte for byte against the same page
 *                with its gold code taken out of the source (or, with
 *                U9X3_BASE=<rev>, against that git revision) for every step
 *                drawn here;
 *      'gold'    = <html class="lt-gold"> + one <style id="lt-gold"> block and
 *                nothing else: same <body> classes, same markup, same text.
 *    The gold block: every rule scoped under :root.lt-gold, colours / borders /
 *    glass only (no size, gap, font or layout property, no font file or url),
 *    text colour pairs >= 4.5:1, the phone full-screen rule for the PIN /
 *    pattern steps, the gold keypad ring / dot / OK key.
 *
 *  Each page is drawn in its own PHP process (--child) with a made-up
 *  session; the look is set inside that process only (Settings cache), so
 *  the shared settings row is never written. No database row is written.
 *
 *     php -c .claude/php-dev.ini tests/u9-X3-login-look-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: draw admin/login.php once --------------------------------
   argv: --child <base64 json {file, get, session, theme?}> */
if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.93';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI']    = '/admin/login.php';
    $_SERVER['SCRIPT_NAME']    = '/admin/login.php';
    $_GET  = (array) ($cfg['get'] ?? []);
    $_POST = [];
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    // '@now' / '@now+600' / '@date-7200' in the made-up session: times relative to this process
    $when = static function (mixed $v) use (&$when): mixed {
        if (is_array($v)) {
            return array_map($when, $v);
        }
        if (is_string($v) && preg_match('/^@(now|date)([+-]\d+)?$/', $v, $m) === 1) {
            $t = time() + (int) ($m[2] ?? 0);
            return $m[1] === 'now' ? $t : date('Y-m-d H:i:s', $t);
        }
        return $v;
    };
    $_SESSION = (array) $when((array) ($cfg['session'] ?? []));
    $_SESSION[CSRF_TOKEN_NAME] = 'u9x3-fixed-csrf-token';
    // the look, for THIS process only
    if (array_key_exists('theme', $cfg)) {
        Settings::get('login_theme');
        $rp = new ReflectionProperty(Settings::class, 'cache');
        $c  = (array) $rp->getValue();
        if ($cfg['theme'] === null) {
            unset($c['login_theme']);          // the migration has not run yet
        } else {
            $c['login_theme'] = ['value' => (string) $cfg['theme'], 'type' => 'string', 'public' => false];
        }
        $rp->setValue(null, $c);
    }
    // admin/login.php defines SHG_APP itself; bootstrap already did (and turns warnings into exceptions)
    $prev = null;
    $prev = set_error_handler(static function (int $sev, string $msg, string $f = '', int $l = 0) use (&$prev): bool {
        if (str_contains($msg, 'SHG_APP') && str_contains($msg, 'already defined')) {
            return true;
        }
        return is_callable($prev) ? (bool) $prev($sev, $msg, $f, $l) : false;
    });
    require (string) $cfg['file'];
    exit(0);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/codelogin.php';

$PASS = 0; $FAIL = 0; $SKIP = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function skip(string $l): void { global $SKIP; $SKIP++; echo "  SKIP  $l\n"; }

/** Draw admin/login.php (or the base copy) in a child process; returns the page. */
function draw(string $file, array $get, array $session, string|null|false $theme = false): string {
    $cfg = ['file' => $file, 'get' => $get, 'session' => $session];
    if ($theme !== false) {
        $cfg['theme'] = $theme;
    }
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return '';
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}
/** The lock countdown ticks between two draws: its seconds are not part of the look. */
function norm(string $html): string {
    $html = (string) preg_replace('/data-left="\d+"/', 'data-left="N"', $html);
    // lead 16 Nov 2026: a cache-bust stamp bump (?v=YYYYMMDDx) is not a look change
    $html = (string) preg_replace('/\?v=\d{8}[a-z]?/', '?v=STAMP', $html);
    return (string) preg_replace('/<span>\d{1,2}:\d{2}(:\d{2})?<\/span>/', '<span>T</span>', $html);
}
function bodyClass(string $html): ?string {
    return preg_match('/<body class="([^"]*)">/', $html, $m) === 1 ? $m[1] : null;
}
/** The gold render minus the two gold additions. */
function stripGold(string $html): string {
    $html = (string) preg_replace('/<html lang="ne" class="lt-gold">/', '<html lang="ne">', $html, 1);
    return (string) preg_replace('/<style id="lt-gold">.*?<\/style>/s', '', $html, 1);
}
/** WCAG relative luminance / contrast of two #RRGGBB colours. */
function lum(string $hex): float {
    $hex = ltrim($hex, '#');
    $c = array_map(static function (string $p): float {
        $v = hexdec($p) / 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }, str_split($hex, 2));
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
}
function contrast(string $a, string $b): float {
    $x = lum($a); $y = lum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

echo "\n=== X3-login-look: login_theme classic | gold ===\n\n";

$CUR = $ROOT . '/admin/login.php';
check('CodeLogin / Auth session keys this test fakes are the real ones',
    CodeLogin::KEY === 'code_login' && Auth::DEVICE_HOLD_KEY === 'admin_device_pending');

/* ---- the base copy of the page (the same includes, its own markup) --------
   16 Nov 2026 (battery T1): the base was `git show HEAD:admin/login.php`. That
   proved the point only while the X3 change itself was uncommitted; once it
   was committed it compared the working file with HEAD, so ANY later edit to
   login.php not yet committed (mv5's service-worker line, a ?v= stamp) made
   all 11 "classic = base" checks fail although the look had not changed, and
   any committed edit passed whatever it did. The promise is "classic = the
   page as if the gold look did not exist", so the base is now THIS working
   file with its two gold pieces taken out of the source (the class on <html>
   and the <?php if ($ltGold): ... </style><?php endif; ?> block) - and it must
   find each exactly once, and $ltGold nowhere else. U9X3_BASE=<rev> still
   compares with a git revision, for reviewing one change against its parent. */
$BASE = trim((string) getenv('U9X3_BASE'));
$baseFile = null;
$baseLabel = '';
$curSrc = (string) file_get_contents($CUR);
if ($BASE === '') {
    $baseLabel = 'the page without its gold code';
    $htmlGold  = '<html lang="ne"<?= $ltGold ? \' class="lt-gold"\' : \'\' ?>>';
    $src = str_replace($htmlGold, '<html lang="ne">', $curSrc, $nHtml);
    // (\r?\n)?: PHP's closing tag swallows the one newline right after it, so it goes with the block
    $src = (string) preg_replace('~<\?php if \(\$ltGold\):.*?</style><\?php endif; \?>(\r?\n)?~s', '', $src, -1, $nBlock);
    // what is left of $ltGold once both pieces are out: its own assignment, nothing else
    $uses = substr_count($src, '$ltGold');
    check('the gold look lives in exactly two places of admin/login.php (the <html> class, one style block)',
        $nHtml === 1 && $nBlock === 1 && $uses === 1,
        "html=$nHtml block=$nBlock \$ltGold left=$uses");
    if ($nHtml === 1 && $nBlock === 1) {
        $baseFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'u9x3-base-login-' . getmypid() . '.php';
        file_put_contents($baseFile, str_replace('dirname(__DIR__)', var_export($ROOT, true), $src));
    }
} else {
    $baseLabel = "the $BASE page";
    $src = (string) @shell_exec('git -C ' . escapeshellarg($ROOT) . ' show ' . escapeshellarg($BASE . ':admin/login.php') . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    if (str_contains($src, 'Auth::oneDoorOn()')) {
        // a git run from PHP may hand the file back with other line endings: use the working file's
        if (str_contains($curSrc, "\r\n")) {
            $src = (string) preg_replace("/\r?\n/", "\r\n", $src);
        }
        $baseFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'u9x3-base-login-' . getmypid() . '.php';
        file_put_contents($baseFile, str_replace('dirname(__DIR__)', var_export($ROOT, true), $src));
    } else {
        check("git base $BASE:admin/login.php is readable (U9X3_BASE was asked for)", false);
    }
}

$AID = 999999901;   // no such admin: the page draws what it needs from the session only
$S = [
    'c_code'        => [['step' => 'c_code'], [], 'name="code"'],
    'c_unlock'      => [['step' => 'c_unlock'], ['code_login' => ['aid' => 0, 'code' => 'U9X3-TEST', 't' => '@now']], 'data-qk-form="pin"'],
    'c_unlock_wait' => [['step' => 'c_unlock'], ['code_login' => ['aid' => 0, 'code' => 'U9X3-TEST', 't' => '@now', 'until' => '@now+600']], 'class="cl-until"'],
    'c_unlock_lock' => [['step' => 'c_unlock'], ['code_login' => ['aid' => 0, 'code' => 'U9X3-TEST', 't' => '@now', 'dev' => 1]], 'id="lkClock"'],
    'c_setup'       => [['step' => 'c_setup'], ['code_login' => ['aid' => $AID, 'code' => 'U9X3-TEST', 't' => '@now', 'purpose' => 'setup']], 'cl-steps'],
    'c_forgot'      => [['step' => 'c_forgot'], ['code_login' => ['aid' => $AID, 'code' => 'U9X3-TEST', 't' => '@now', 'purpose' => 'reset']], 'cl-steps'],
    'c_create'      => [['step' => 'c_create'], ['code_login' => ['aid' => $AID, 'code' => 'U9X3-TEST', 't' => '@now', 'ver' => '@now']], 'data-cl-len'],
    'c_confirm_pin' => [['step' => 'c_confirm'], ['code_login' => ['aid' => $AID, 'code' => 'U9X3-TEST', 't' => '@now', 'ver' => '@now', 'pend' => ['kind' => 'pin', 'len' => 4]]], 'data-len="4"'],
    'c_confirm_pat' => [['step' => 'c_confirm'], ['code_login' => ['aid' => $AID, 'code' => 'U9X3-TEST', 't' => '@now', 'ver' => '@now', 'pend' => ['kind' => 'pattern']]], 'data-qk-pad'],
    'devices'       => [[], ['admin_device_pending' => ['id' => $AID, 'at' => '@now', 'limit' => 1,
                        'offer' => ['d1' => ['label' => 'Chrome · Android', 'last_seen' => '@date-7200']]]], 'dv-list'],
    'classic_start' => [['classic' => '1'], [], 'name="who"'],
];

$goldCss = '';
foreach ($S as $name => [$get, $sess, $marker]) {
    echo "-- $name\n";
    $classic = draw($CUR, $get, $sess, 'classic');
    $gold    = draw($CUR, $get, $sess, 'gold');
    $ok = str_contains($classic, '</html>') && str_contains($classic, $marker);
    check("$name: the step draws (classic)", $ok, $ok ? '' : substr(strip_tags($classic), 0, 160));
    if (!$ok) {
        continue;
    }
    check("$name: classic has no gold class / block", !str_contains($classic, 'lt-gold'));
    if ($baseFile !== null) {
        // drawn at 'classic': the pre-gold base ignores the setting, and once this change is committed
        // (base = HEAD = the gold-aware page) a 'gold' draw would compare the gold page to classic
        $base = draw($baseFile, $get, $sess, 'classic');
        check("$name: classic = $baseLabel, byte for byte", norm($classic) === norm($base),
            norm($classic) === norm($base) ? strlen($classic) . ' bytes' : 'first difference at byte ' . strspn(norm($classic) ^ norm($base), "\0"));
    }
    check("$name: no row (migration not run) = classic", norm(draw($CUR, $get, $sess, null)) === norm($classic));
    check("$name: an unknown value ('silver') = classic", norm(draw($CUR, $get, $sess, 'silver')) === norm($classic));
    check("$name: gold adds the class on <html>", str_contains($gold, '<html lang="ne" class="lt-gold">'));
    check("$name: gold adds one style block", substr_count($gold, '<style id="lt-gold">') === 1);
    check("$name: <body> classes are the same", bodyClass($gold) !== null && bodyClass($gold) === bodyClass($classic), (string) bodyClass($classic));
    check("$name: gold minus its class and block = classic (same markup, same text)", norm(stripGold($gold)) === norm($classic));
    if ($name === 'c_unlock') {
        check("$name: ' GOLD ' (case / spaces) = gold", norm(draw($CUR, $get, $sess, ' GOLD ')) === norm($gold));
        check("$name: the stored setting (not overridden) draws classic", !str_contains(draw($CUR, $get, $sess), 'lt-gold'));
    }
    if ($goldCss === '' && preg_match('/<style id="lt-gold">(.*?)<\/style>/s', $gold, $m) === 1) {
        $goldCss = $m[1];
    }
}
if ($baseFile !== null) {
    @unlink($baseFile);
}

/* ---- the gold block itself ------------------------------------------------ */
echo "-- the gold style block\n";
check('gold block found', $goldCss !== '');
$css = (string) preg_replace('~/\*.*?\*/~s', '', $goldCss);
preg_match_all('/([^{}]+)\{/', $css, $sm);
$bad = [];
foreach ($sm[1] as $sel) {
    $sel = trim($sel);
    if ($sel === '' || $sel[0] === '@') {
        continue;
    }
    foreach (explode(',', $sel) as $part) {
        if (!str_starts_with(trim($part), ':root.lt-gold')) {
            $bad[] = trim($part);
        }
    }
}
check('every rule is scoped under :root.lt-gold', $bad === [] && count($sm[1]) > 30, $bad === [] ? count($sm[1]) . ' rules' : implode(' | ', $bad));
preg_match_all('/(?<![-\w])(width|height|min-height|max-height|min-width|max-width|padding[-\w]*|margin[-\w]*|font-size|font-family|font|display|grid[-\w]*|gap|position|top|left|right|bottom|transform|line-height|letter-spacing|flex[-\w]*)\s*:/i',
    (string) preg_replace('/@media[^{]*\{/', '', $css), $lm);   // a media query's own (max-width:…) is not a property
check('no size / gap / font / layout property (colours, borders, glass only)', $lm[0] === [], implode(' ', array_unique($lm[1])));
check('no font file, url or @import (no new font: Poppins skipped)', !preg_match('/@font-face|url\(|@import|poppins/i', $goldCss));
preg_match_all('/border(?:-width)?\s*:\s*([^;}]+)/i', $css, $bm);
check('the only border widths are the classic ones (1px card, 0 lock card, 1.5px key ring)',
    array_diff(array_map(static fn(string $b): string => (string) preg_replace('/\s.*$/', '', trim($b)), $bm[1]), ['1px', '0', '1.5px']) === [],
    implode(' | ', $bm[1]));
check('phones: the PIN / pattern card melts into the dark page',
    preg_match('/@media \(max-width:899px\)\{:root\.lt-gold body \.box:has\(> \.qk\)\{background:transparent;border-color:transparent/', $css) === 1);
check('keypad: circular keys keep their size, gold ring', str_contains($css, ':root.lt-gold body .qk .lk-keys button{') && str_contains($css, 'border:1.5px solid rgba(226,182,80,'));
check('keypad: the hover tint only where a mouse hovers (a tapped key on a phone does not stay tinted)',
    str_contains($css, '@media (hover:hover){:root.lt-gold body .qk .lk-keys button:hover{')
    && preg_match('/(?<!\{):root\.lt-gold body \.qk \.lk-keys button:hover\{/', $css) === 0);
check('gold on the active dot, OK key, PIN / Pattern tab and primary button',
    str_contains($css, '.lk-dots i.on{background:#E2B650') && str_contains($css, 'button.go{background:linear-gradient(180deg,#F5D98A,#E2B650')
    && str_contains($css, '.qk-tabs button.on{background:#E2B650') && str_contains($css, ':root.lt-gold .btn{background:linear-gradient(180deg,#F5D98A,#E2B650'));
check('a wrong / right pattern keeps its red / green', str_contains($css, '.qk-pad:not(.bad):not(.good) .qk-dot.on') && str_contains($css, '.qk-pad:not(.bad):not(.good) polyline'));
check('the remembered-phone lock screen keeps its card-less look', str_contains($css, ':root.lt-gold body.lock .box{background:transparent;border:0;'));

/* text on its background, >= 4.5:1. Backgrounds: the page (#091530 .. #050B1A), the glass card over it
   (white 9 % .. 4.5 % on navy ~ #1F2D4B), an input well (~ #121C33), the gold / green buttons. */
echo "-- contrast (text >= 4.5:1)\n";
$pairs = [
    ['#EEF2FA', '#1F2D4B', 'text on the glass card'],
    ['#B3BFD4', '#1F2D4B', 'muted text on the card'],
    ['#E2B650', '#1F2D4B', 'gold links on the card'],
    ['#E2B650', '#091530', 'gold links on the page (phone, full screen)'],
    ['#A3AEC2', '#1F2D4B', '"or" divider text'],
    ['#93A1BC', '#121C33', 'input placeholder'],
    ['#EEF2FA', '#121C33', 'input text'],
    ['#10182B', '#F5D98A', 'button text on gold (top)'],
    ['#10182B', '#E2B650', 'button text on gold (middle)'],
    ['#10182B', '#C7982F', 'button text on gold (bottom)'],
    ['#FFFFFF', '#12844A', 'WhatsApp button (top)'],
    ['#FFFFFF', '#0B6A36', 'WhatsApp button (bottom)'],
    ['#D5DEEE', '#1F2D4B', 'PIN / Pattern tab'],
    ['#FFFFFF', '#2A3755', 'PIN key digit'],
    ['#FFADB9', '#30161B', 'error message'],
    ['#8CE4B8', '#0F271B', 'ok message'],
    ['#FFD48A', '#2E2410', 'warning message'],
];
foreach ($pairs as [$fg, $bg, $what]) {
    $r = contrast($fg, $bg);
    $inCss = $fg === '#FFFFFF' || stripos($goldCss, $fg) !== false;
    check(sprintf('%s %s on %s = %.1f:1', $what, $fg, $bg, $r), $r >= 4.5 && $inCss, $inCss ? '' : "$fg is not in the gold block");
}

/* ---- the migration ------------------------------------------------------- */
echo "-- migration\n";
$mig = $ROOT . '/database/upgrade-2026-11-16c-X3-login-look.sql';
$sql = is_file($mig) ? (string) file_get_contents($mig) : '';
check('migration: INSERT IGNORE login_theme = classic (string)', preg_match("/INSERT IGNORE INTO settings[^;]*\\('login_theme',\\s*'classic',\\s*'string'/s", $sql) === 1);
check('migration: nothing but that insert (no ALTER / UPDATE / DELETE)', $sql !== '' && !preg_match('/\b(ALTER|UPDATE|DELETE|DROP|TRUNCATE)\b/i', (string) preg_replace('/^\s*--.*$/m', '', $sql)));
$row = Database::fetch("SELECT svalue, stype FROM settings WHERE skey = 'login_theme'");
if ($row === null) {
    skip('settings row login_theme not on this database yet (apply the migration)');
} else {
    check('settings row login_theme is stored at classic (nothing changes until the owner picks gold)', (string) $row['svalue'] === 'classic' && (string) $row['stype'] === 'string', (string) $row['svalue']);
}

echo "\n  $PASS passed, $FAIL failed" . ($SKIP > 0 ? ", $SKIP skipped" : '') . "\n";
exit($FAIL === 0 ? 0 : 1);
