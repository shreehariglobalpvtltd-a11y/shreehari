<?php
/**
 * 16 Nov 2026 — master upgrade v2.0, unit Y1 (PWA polish):
 *
 *   P2-1g  staff "📲 एप राख्नुहोस् · Install app" chip on the Management home
 *          (admin/home.php) + the service worker registered from the sign-in
 *          door (admin/login.php) so Chrome can offer the install
 *   P2-4f  the customer offline indicator is a full-width amber bar at the top
 *          (.shg-offline-bar in assets/css/polish.css, light + dark)
 *   P2-5a  sw.js SHELL_WAIT_MS 3500 -> 1500
 *   P2-7b/d manifest screenshots (3 customer + 3 staff, 1080x1920 WebP)
 *
 * Static checks always run; the HTTP part signs a throw-away superadmin in on
 * the dev server and renders admin/home.php + the door + both manifests.
 *
 *   SHG_TEST_BASE=http://localhost:8981 php -c <ini> -d extension=php_curl.dll tests/u11-Y1-pwa-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$PASS = 0; $FAIL = 0;
function check(string $what, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $what" . ($why !== '' ? " — $why" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $what" . ($why !== '' ? " — $why" : '') . "\n"; }
}
$root = dirname(__DIR__);
$read = static fn(string $p): string => (string) @file_get_contents($root . '/' . $p);

echo "\n=== Y1 PWA: install chip, offline bar, shell wait, manifest screenshots ===\n\n";

/* ---- 1. manifests + screenshots ---------------------------------------- */
echo "1. Manifest screenshots\n";
$manifests = [
    'manifest.webmanifest'       => ['shot-home.webp', 'shot-seats.webp', 'shot-ticket.webp'],
    'admin/manifest.webmanifest' => ['mgmt-shot-lock.webp', 'mgmt-shot-home.webp', 'mgmt-shot-chalani.webp'],
];
$jsonOf = static fn(string $s) => json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $s), true);
foreach ($manifests as $file => $want) {
    $m = $jsonOf($read($file));
    check("$file is valid JSON", is_array($m), json_last_error_msg());
    $shots = is_array($m) ? ($m['screenshots'] ?? null) : null;
    check("$file has 3 screenshots", is_array($shots) && count($shots) === 3);
    $srcs = [];
    foreach ((array) $shots as $i => $s) {
        $src = (string) ($s['src'] ?? '');
        $srcs[] = basename($src);
        $path = $root . $src;
        $info = is_file($path) ? @getimagesize($path) : false;
        check("  $src exists", is_file($path));
        check('  …is a 1080x1920 WebP', is_array($info) && $info[0] === 1080 && $info[1] === 1920 && ($info['mime'] ?? '') === 'image/webp',
            is_array($info) ? $info[0] . 'x' . $info[1] . ' ' . ($info['mime'] ?? '') : 'unreadable');
        check('  …under 150 KB', is_file($path) && filesize($path) <= 150 * 1024, is_file($path) ? round(filesize($path) / 1024, 1) . ' KB' : '');
        check('  …declared sizes 1080x1920, image/webp, narrow, with a label',
            ($s['sizes'] ?? '') === '1080x1920' && ($s['type'] ?? '') === 'image/webp' && ($s['form_factor'] ?? '') === 'narrow'
            && trim((string) ($s['label'] ?? '')) !== '' && mb_strlen((string) $s['label']) <= 60);
        check('  …no ?v= stamp (the lead owns the asset stamp)', !str_contains($src, '?'));
    }
    check("$file names the right pictures", $srcs === $want, implode(', ', $srcs));
}
$cm = $jsonOf($read('manifest.webmanifest'));
$sm = $jsonOf($read('admin/manifest.webmanifest'));
check('app identities unchanged (ids / / and /admin/, short names)',
    ($cm['id'] ?? '') === '/' && ($sm['id'] ?? '') === '/admin/' && ($cm['short_name'] ?? '') === 'S Hari Travel' && ($sm['short_name'] ?? '') === 'S Hari Manage');

/* ---- 2. the install chip on the Management home ------------------------ */
echo "\n2. Staff install chip (admin/home.php)\n";
$home = $read('admin/home.php');
check('chip markup: hidden by default, full width, bilingual label',
    str_contains($home, '<div class="mg-inst" id="mgInst" hidden>') && str_contains($home, '📲 एप राख्नुहोस् · Install app'));
check('chip: dismiss button "पछि · Not now"', str_contains($home, 'id="mgInstX"') && str_contains($home, 'aria-label="पछि · Not now"'));
check('chip: at least 48px tall, [hidden] really hides it',
    (bool) preg_match('/\.mg-inst-go\{[^}]*min-height:48px/', $home) && (bool) preg_match('/\.mg-inst-x\{[^}]*min-height:48px/', $home)
    && str_contains($home, '.mg-inst[hidden],.mg-inst ol[hidden]{display:none}'));
check('chip: fits 360px (flex row, the label shrinks, no fixed width)', (bool) preg_match('/\.mg-inst-go\{flex:1 1 0;min-width:0/', $home));
check('chip: dark theme variant', str_contains($home, ':root[data-theme="dark"] .mg-inst-go{'));
check('chip: catches beforeinstallprompt, keeps it, preventDefault',
    (bool) preg_match("/addEventListener\('beforeinstallprompt',function\(ev\)\{ev\.preventDefault\(\);deferred=ev;/", $home));
check('chip: click -> prompt()', str_contains($home, 'p.prompt();'));
check('chip: hides on appinstalled', str_contains($home, "addEventListener('appinstalled',function(){deferred=null;hide();})"));
check('chip: never inside the installed app (display-mode standalone / navigator.standalone)',
    str_contains($home, "matchMedia('(display-mode: standalone)')") && str_contains($home, 'window.navigator.standalone===true'));
check('chip: iPhone Safari gets the 3 bilingual Share -> Add to Home Screen steps',
    str_contains($home, '<ol id="mgInstIos" hidden>') && substr_count($home, '<li>') >= 3 && str_contains($home, 'Share ⬆️')
    && str_contains($home, 'Add to Home Screen ➕') && str_contains($home, 'CriOS|FxiOS|EdgiOS|OPiOS'));
check('chip: dismiss remembered 30 days under shm:installDismiss',
    str_contains($home, "KEY='shm:installDismiss'") && str_contains($home, 'KEEP=30*86400000'));
$seg = '';
if (preg_match('~/\* 16 Nov 2026 \(P2-1g\): the "Install app" chip.*?\}\)\(\);~s', $home, $mm)) { $seg = $mm[0]; }
$lsAll = substr_count($seg, 'localStorage.');
$lsTry = preg_match_all('/try\{[^{}]*localStorage\./', $seg);
check('chip: every localStorage access is inside try/catch', $seg !== '' && $lsAll >= 2 && $lsAll === $lsTry, "$lsTry of $lsAll");
check('chip: home also registers the same worker, guarded', str_contains($seg, "if('serviceWorker' in navigator)") && str_contains($seg, "navigator.serviceWorker.register('/sw.js',{scope:'/'}).catch(function(){})"));

/* ---- 3. the sign-in door registers the worker ------------------------- */
echo "\n3. Sign-in door (admin/login.php)\n";
$login = $read('admin/login.php');
$manPos = strpos($login, '<link rel="manifest" href="/admin/app-manifest.php">');
$regPos = strpos($login, "navigator.serviceWorker.register('/sw.js',{scope:'/'})");
check("login.php registers /sw.js with scope '/'", $regPos !== false);
check('  …right after the staff manifest link', $manPos !== false && $regPos !== false && $regPos > $manPos && $regPos - $manPos < 1200);
check("  …guarded: 'serviceWorker' in navigator, try/catch and .catch()", (bool) preg_match(
    "~<script>\(function\(\)\{try\{if\(!\('serviceWorker' in navigator\)\)return;.*?\.catch\(function\(\)\{\}\);\}catch\(e\)\{\}\};.*?\}catch\(e\)\{\}\}\)\(\);</script>~", $login));
check('  …same call as admin/staff-chat.php', str_contains($read('admin/staff-chat.php'), "navigator.serviceWorker.register('/sw.js', { scope: '/' })"));
$sw = $read('sw.js');
check('sw.js still passes /admin/* to the network (the door is never cached)', str_contains($sw, "url.pathname.indexOf('/admin') === 0"));

/* ---- 4. sw.js shell wait ---------------------------------------------- */
echo "\n4. Service worker shell wait (sw.js)\n";
check('SHELL_WAIT_MS is 1500', (bool) preg_match('/^var SHELL_WAIT_MS = 1500;$/m', $sw));
check('  …declared once and used by the navigate race', substr_count($sw, 'var SHELL_WAIT_MS') === 1 && str_contains($sw, "resolve('timeout'); }, SHELL_WAIT_MS);"));
check('  …comment says why (P2-5a, same VERSION shell, cache keeps refreshing)', str_contains($sw, 'P2-5a') && str_contains($sw, '3500 -> 1500'));

/* ---- 5. offline bar ---------------------------------------------------- */
echo "\n5. Offline bar (polish.css + 13-admin-routes.js)\n";
$css = $read('assets/css/polish.css');
check('.shg-offline-bar: full width at the very top, no transform, square corners',
    (bool) preg_match('/\.shg-offline-bar\{position:fixed;top:0;left:0;right:0;transform:none;/', $css)
    && (bool) preg_match('/\.shg-offline-bar\{[^}]*border-radius:0;/', $css));
check('  …safe-area padding + centred text', str_contains($css, 'padding:calc(4px + env(safe-area-inset-top,0px)) 12px 4px;') && (bool) preg_match('/\.shg-offline-bar\{[^}]*text-align:center/', $css));
check('  …amber light colours #FFF0D1 / #7A4A00', (bool) preg_match('/\.shg-offline-bar\{[^}]*background:#FFF0D1;color:#7A4A00/', $css));
check('  …dark variant html[data-theme="dark"] #3A2A0A / #FFD48A', (bool) preg_match('/html\[data-theme="dark"\] \.shg-offline-bar\{background:#3A2A0A;color:#FFD48A/', $css));
check('  …follows prefers-color-scheme when no theme is chosen',
    (bool) preg_match('/@media \(prefers-color-scheme:dark\)\{\s*:root:not\(\[data-theme="light"\]\) \.shg-offline-bar\{background:#3A2A0A;color:#FFD48A/', $css));
check('  …never takes a tap (the top bar buttons under it still work)', (bool) preg_match('/\.shg-offline-bar\{[^}]*pointer-events:none/', $css));
check('  …wraps at 360px', (bool) preg_match('/\.shg-offline-bar\{[^}]*overflow-wrap:anywhere/', $css));
$js = $read('assets/js/13-admin-routes.js');
check('offline indicator uses the class, not the old red pill', str_contains($js, "el.className = 'shg-offline-bar';") && !str_contains($js, 'background:#8a1f1f'));
check('  …keeps the bilingual text and the " · N queued" suffix',
    substr_count($js, "'📵 Offline — cached data · अफलाइन'") === 2 && str_contains($js, "tf('netQueued', { n: n })"));
check('  …shows only while offline', str_contains($js, "el.style.display = navigator.onLine ? 'none' : 'block';") && str_contains($js, "el.style.display = 'none';"));

/* ---- 6. lint ------------------------------------------------------------ */
echo "\n6. Lint\n";
$php = PHP_BINARY;
$ini = php_ini_loaded_file();
foreach (['admin/home.php', 'admin/login.php', 'tests/u11-Y1-pwa-test.php'] as $f) {
    $out = []; $rc = 0;
    exec(escapeshellarg($php) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $rc);
    check("php -l $f", $rc === 0, $rc === 0 ? '' : implode(' ', $out));
}
$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
$node = $node !== '' ? strtok($node, "\r\n") : '';
foreach (['assets/js/13-admin-routes.js', 'sw.js'] as $f) {
    if ($node === '') { check("node --check $f (node not found — skipped)", true); continue; }
    $out = []; $rc = 0;
    exec(escapeshellarg($node) . ' --check ' . escapeshellarg($root . '/' . $f) . ' 2>&1', $out, $rc);
    check("node --check $f", $rc === 0, $rc === 0 ? '' : implode(' ', array_slice($out, 0, 4)));
}

/* ---- 7. over HTTP ------------------------------------------------------- */
echo "\n7. Over HTTP\n";
require_once $root . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickunlock.php';
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
const Y1_USER = 'u11_y1_pwa_boss';
$JAR = sys_get_temp_dir() . '/shg_u11_y1_' . getmypid() . '.txt';
@unlink($JAR);
function http(string $url, array $opt = []): array
{
    global $JAR;
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR, CURLOPT_TIMEOUT => 60]);
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function cleanupY1(): void
{
    $a = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => Y1_USER]);
    if (!$a) { return; }
    try { Database::run("DELETE FROM audit_logs WHERE actor_type = 'admin' AND actor_id = :a", ['a' => (int) $a['id']]); } catch (Throwable $e) {}
    $tables = Database::fetchAll("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'admin_id'");
    foreach ($tables as $t) {
        try { Database::run('DELETE FROM `' . str_replace('`', '', (string) $t['TABLE_NAME']) . '` WHERE admin_id = :a', ['a' => (int) $a['id']]); } catch (Throwable $e) {}
    }
    Database::delete('admins', 'id = :i', ['i' => (int) $a['id']]);
}
if (!function_exists('curl_init')) {
    check('curl extension loaded (re-run with -d extension=php_curl.dll)', false);
} else {
    $ping = http('/admin/app-manifest.php');
    if ($ping['code'] === 0) {
        check('dev server answers at ' . BASE . ' (set SHG_TEST_BASE)', false);
    } else {
        cleanupY1();
        try {
            $pw = 'U11y1Pwa' . bin2hex(random_bytes(4));
            $id = (int) Database::insert('admins', ['username' => Y1_USER, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'full_name' => 'U11 Y1 Boss', 'role' => 'superadmin', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
            QuickUnlock::set($id, 'pin', '583941');   // the code-first door sends a PIN-less account to pin-setup first

            $door = http('/admin/login.php');
            check('GET /admin/login.php is 200', $door['code'] === 200, 'HTTP ' . $door['code']);
            check('  …served door registers the worker', str_contains($door['body'], "navigator.serviceWorker.register('/sw.js',{scope:'/'})"));
            check('  …and still shows the sign-in form', str_contains($door['body'], '<form') && str_contains($door['body'], 'name="' . CSRF_TOKEN_NAME . '"'));

            $classic = http('/admin/login.php?classic=1&other=1');
            $csrf = preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $classic['body'], $m) ? $m[1] : '';
            $r = http('/admin/login.php', ['form' => [CSRF_TOKEN_NAME => $csrf, 'classic' => '1', 'username' => Y1_USER, 'password' => $pw]]);
            check('superadmin signs in through the door', $r['code'] === 302 && !str_contains($r['headers'], 'login.php'), 'HTTP ' . $r['code']);

            $h = http('/admin/home.php');
            check('GET /admin/home.php is 200 (logged in)', $h['code'] === 200, 'HTTP ' . $h['code']);
            check('  …the install chip is on the page, hidden until the browser can install',
                str_contains($h['body'], '<div class="mg-inst" id="mgInst" hidden>') && str_contains($h['body'], '📲 एप राख्नुहोस् · Install app'));
            check('  …with its dismiss key and the iPhone steps', str_contains($h['body'], "shm:installDismiss") && str_contains($h['body'], 'id="mgInstIos"'));
            check('  …the launcher still renders (apps grid)', str_contains($h['body'], 'class="os-grid"'));
            check('  …the staff manifest is linked', str_contains($h['body'], '<link rel="manifest" href="/admin/app-manifest.php">'));

            $sm2 = http('/admin/app-manifest.php');
            $smj = json_decode($sm2['body'], true);
            check('staff manifest served as JSON with 3 screenshots', $sm2['code'] === 200 && is_array($smj) && count($smj['screenshots'] ?? []) === 3);
            $cm2 = http('/manifest.webmanifest');
            $cmj = json_decode((string) preg_replace('/^\xEF\xBB\xBF/', '', $cm2['body']), true);
            check('customer manifest served as JSON with 3 screenshots', $cm2['code'] === 200 && is_array($cmj) && count($cmj['screenshots'] ?? []) === 3);
            foreach (array_merge((array) ($cmj['screenshots'] ?? []), (array) ($smj['screenshots'] ?? [])) as $s) {
                $img = http((string) $s['src']);
                check('  ' . $s['src'] . ' is served as image/webp', $img['code'] === 200 && stripos($img['headers'], 'Content-Type: image/webp') !== false && strlen($img['body']) > 1000);
            }
            $swr = http('/sw.js');
            check('served sw.js waits 1500 ms for the shell', $swr['code'] === 200 && str_contains($swr['body'], 'var SHELL_WAIT_MS = 1500;'));
        } catch (Throwable $e) {
            check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        }
        cleanupY1();
        check('throw-away account removed', (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE username = :u', ['u' => Y1_USER], 0) === 0);
    }
}
@unlink($JAR);
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
