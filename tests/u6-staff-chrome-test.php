<?php
/**
 * =====================================================================
 *  u6-staff-chrome-test.php — Master Upgrade v2.0, unit U6 (16 Nov 2026):
 *  the staff page chrome (admin/_guard.php), the Home tile
 *  (includes/homeboard.php) and the Colours & Themes page (admin/themes.php).
 *
 *  Static part (no database):
 *    - _guard.php / themes.php / homeboard.php lint
 *    - the staff head: apple-mobile-web-app-capable, mobile-web-app-capable,
 *      status-bar style, the staff apple-touch-icon, the app title, the
 *      shg-admin-csrf meta (the same token as the csrf meta / forms)
 *    - assets/img/mgmt-apple-180.png is a 180x180 opaque PNG
 *    - menu entries: themes.php + ai-copilot.php (always), messenger-inbox
 *      and bulk-tickets (when their page exists), each with its sibling's perm
 *    - the AI bubble script tag sits in admin_footer() behind
 *      admin_ai_bubble_on(); the Home 'ai' tile opens ai-copilot.php
 *
 *  Database part (shari_test only; the four test admins it makes and their
 *  menu-usage rows are deleted at the end):
 *    - admin/themes.php renders for a super-admin: 7 accents (light + dark),
 *      7 Preview links /?accent_preview=<key>, 7 next-day rows whose accent
 *      follows the IST weekday, the head metas and the csrf meta = session token
 *    - 16 Nov 2026 (X2): the only POST forms are the Special dates ones
 *      (back to themes.php); a support login reads the page with no form
 *    - an agent cannot open admin/themes.php
 *    - the bubble: on for super-admin + operations, off for support + agent,
 *      off on easy-settings.php / ai-copilot.php / quick-unlock.php / ?print=1
 *    - the menu per role + the Home 'ai' tile per role
 *
 *    php -c .claude/php-dev.ini tests/u6-staff-chrome-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: one page / one probe in its own PHP process -----------
   argv: --child <base64 json {what: page|probe, admin, script, get}> */
if (($argv[1] ?? '') === '--child') {
    $cfg    = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $script = (string) ($cfg['script'] ?? 'themes.php');
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.66';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME']    = '/admin/' . $script;
    $_SERVER['REQUEST_URI']    = '/admin/' . $script;
    $_GET = (array) ($cfg['get'] ?? []);
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if (($cfg['what'] ?? '') === 'probe') {
        require_once dirname(__DIR__) . '/admin/_guard.php';
        require_once INCLUDE_PATH . '/homeboard.php';
        $nav = [];
        foreach (admin_nav() as $n) { $nav[(string) $n['href']] = (string) $n['perm']; }
        $t = HomeBoard::tiles(Auth::admin(), null, []);
        echo json_encode([
            'bubble' => admin_ai_bubble_on(),
            'nav'    => $nav,
            'hero'   => array_column($t['hero'], 'key'),
            'tiles'  => array_merge(array_column($t['hero'], 'key'), array_column($t['more'], 'key')),
            'aiHref' => (string) (array_values(array_filter(array_merge($t['hero'], $t['more']), static fn(array $x): bool => $x['key'] === 'ai'))[0]['href'] ?? ''),
            'cmd'    => Auth::canManageSettings() || Auth::can('routes.edit') || Auth::can('schedules.manage'),
            'csrf'   => Security::csrfToken(),
        ]);
        exit(0);
    }
    // the session token, printed after the page (so a 403 can still set its status)
    register_shutdown_function(static function (): void { echo "\n<!--CSRF:" . Security::csrfToken() . "-->\n"; });
    require dirname(__DIR__) . '/admin/' . $script;
    exit(0);
}

$ROOT = dirname(__DIR__);
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function finish(): never {
    global $PASS, $FAIL;
    echo "\n  $PASS passed, $FAIL failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
function run(string $args): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . $args;
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}
function child(array $cfg): string {
    return run(escapeshellarg(__FILE__) . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg))));
}
function probe(int $admin, string $script = 'themes.php', array $get = []): array {
    $out = child(['what' => 'probe', 'admin' => $admin, 'script' => $script, 'get' => $get]);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['raw' => substr($out, 0, 400)];
}

echo "\n=== U6 staff chrome ===\n\n";

/* ---- 1. Static part ------------------------------------------------------ */
echo "-- Lint\n";
foreach (['admin/_guard.php', 'admin/themes.php', 'includes/homeboard.php'] as $f) {
    $o = run('-l ' . escapeshellarg($ROOT . '/' . $f));
    check("$f lints", str_contains($o, 'No syntax errors'), trim(substr($o, 0, 160)));
}

echo "\n-- Staff head (admin/_guard.php)\n";
$g = (string) file_get_contents($ROOT . '/admin/_guard.php');
check('apple-mobile-web-app-capable = yes', str_contains($g, '<meta name="apple-mobile-web-app-capable" content="yes">'));
check('mobile-web-app-capable = yes', str_contains($g, '<meta name="mobile-web-app-capable" content="yes">'));
check('status-bar style meta (opaque black: the staff head has no viewport-fit=cover)', str_contains($g, '<meta name="apple-mobile-web-app-status-bar-style" content="black">'));
check('app title S Hari Manage', str_contains($g, '<meta name="apple-mobile-web-app-title" content="S Hari Manage">'));
check('apple-touch-icon = the staff icon, not the customer one',
    str_contains($g, '<link rel="apple-touch-icon" sizes="180x180" href="/assets/img/mgmt-apple-180.png">') && !str_contains($g, 'href="/assets/img/apple-touch-icon.png"'));
check('shg-admin-csrf meta carries Security::csrfToken()', str_contains($g, '<meta name="shg-admin-csrf" content="\' . Security::e(Security::csrfToken()) . \'">'));
check('the old csrf meta stays (wa sheet, copilot, staff chat read it)', str_contains($g, '<meta name="csrf" content="\' . Security::e(Security::csrfToken()) . \'">'));

echo "\n-- Staff icon\n";
$icon = $ROOT . '/assets/img/mgmt-apple-180.png';
$info = is_file($icon) ? @getimagesize($icon) : false;
check('assets/img/mgmt-apple-180.png is a 180x180 PNG', $info !== false && $info[0] === 180 && $info[1] === 180 && $info['mime'] === 'image/png', $info ? $info[0] . 'x' . $info[1] . ' ' . $info['mime'] : 'missing');
if ($info !== false && function_exists('imagecreatefrompng')) {
    $im = imagecreatefrompng($icon);
    $alpha = 0;
    for ($y = 0; $y < 180; $y += 6) { for ($x = 0; $x < 180; $x += 6) { $alpha += ((imagecolorat($im, $x, $y) >> 24) & 0x7F) > 0 ? 1 : 0; } }
    check('the icon is opaque (iOS paints transparency black)', $alpha === 0, "$alpha see-through samples");
    $c = imagecolorsforindex($im, imagecolorat($im, 4, 4));
    check('the icon keeps the staff navy corner (resized from mgmt-512.png)', $c['red'] < 40 && $c['green'] < 40 && $c['blue'] < 60, "rgb {$c['red']},{$c['green']},{$c['blue']}");
}

echo "\n-- Menu entries + bubble + Home tile (source)\n";
check("themes.php in the menu with easy-settings.php's gate (dashboard.view)", preg_match("~'href' => 'themes\.php',[^\n]*'perm' => 'dashboard\.view'~", $g) === 1);
check('ai-copilot.php in the menu as "AI सहायक · AI Sahayak" with bookings.view',
    preg_match("~'href' => 'ai-copilot\.php',[^\n]*'label' => '🤖 AI सहायक · AI Sahayak',[^\n]*'perm' => 'bookings\.view'~u", $g) === 1);
$cp = (string) @file_get_contents($ROOT . '/admin/ai-copilot.php');
check("…which is ai-copilot.php's own admin_boot perm", str_contains($cp, "admin_boot('bookings.view')"));
check('messenger-inbox.php beside the WhatsApp inbox, customers.view (wa-inbox.php\'s gate)',
    preg_match("~'href' => 'wa-inbox\.php'[^\n]*\n[^\n]*\n\s*\.\.\.\(is_file\(__DIR__ \. '/messenger-inbox\.php'\)[^\n]*'perm' => 'customers\.view'~", $g) === 1);
check('bulk-tickets.php beside QuickBot / SmartBook, bookings.view (the desk sale gate)',
    preg_match("~'href' => 'quick-ticket\.php'[^\n]*\n[^\n]*\n\s*\.\.\.\(is_file\(__DIR__ \. '/bulk-tickets\.php'\)[^\n]*'perm' => 'bookings\.view'~", $g) === 1);
$wi = (string) @file_get_contents($ROOT . '/admin/wa-inbox.php');
check("…wa-inbox.php really boots with customers.view", str_contains($wi, "admin_boot('customers.view')"));
$es = (string) @file_get_contents($ROOT . '/admin/easy-settings.php');
check("…easy-settings.php really boots with dashboard.view", str_contains($es, "admin_boot('dashboard.view')"));
check('admin_footer prints the bubble script behind admin_ai_bubble_on()',
    str_contains($g, "if (admin_ai_bubble_on()) {\n        echo '<script src=\"' . admin_asset_url('assets/js/admin-ai-bubble.js') . '\" defer></script>';"));
check('the bubble rule = easy-settings $canCmd (settings / routes.edit / schedules.manage)',
    str_contains($g, "Auth::canManageSettings() || Auth::can('routes.edit') || Auth::can('schedules.manage')")
    && str_contains($es, "\$canEdit || Auth::can('routes.edit') || Auth::can('schedules.manage')"));
check('the bubble clears the Home phone dock (--admin-fab-bottom)', str_contains($g, 'body:has(.os-dock){--admin-fab-bottom:calc(88px'));
check('…and the SmartBook Pay bar under 860px', str_contains($g, '@media(max-width:859px){body:has(#smartBook){--admin-fab-bottom:calc(88px'));
check('the bubble also needs dashboard.view (the gate of admin/api/ai-settings.php)', str_contains($g, "if (!Auth::can('dashboard.view')\n            || !(Auth::canManageSettings()"));
$hb = (string) file_get_contents($ROOT . '/includes/homeboard.php');
check("Home 'ai' tile -> /admin/ai-copilot.php on bookings.view", str_contains($hb, "'ai'        => ['🤖', 'AI सहायक', 'AI Sahayak', '/admin/ai-copilot.php', \$bv]"));

/* ---- 2. Database part ---------------------------------------------------- */
echo "\n-- Database part\n";
define('SHG_APP', true);
require_once $ROOT . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  \033[33mSKIP\033[0m  database part: DB_NAME is not shari_test — refusing to write\n";
    finish();
}
$tag  = strtolower(substr(bin2hex(random_bytes(4)), 0, 6));
$made = [];
$cleanup = static function () use (&$made): void {
    foreach ($made as $aid) {
        try { Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable $e) { echo "  !! could not delete admin $aid: " . $e->getMessage() . "\n"; }
    }
    $made = [];
};
register_shutdown_function($cleanup);
$mk = static function (string $role, array $extra = []) use ($tag, &$made): int {
    $id = (int) Database::insert('admins', ['username' => 'u6-' . $role . '-' . $tag, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'U6 ' . ucfirst($role) . ' Test', 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]
        + ($extra !== [] ? ['permissions' => json_encode(array_values($extra))] : []));
    $made[] = $id;
    return $id;
};

try {
    $SUP = $mk('superadmin');
    $OPS = $mk('operations');
    $SPT = $mk('support');
    $AGT = $mk('agent');
    // a counter login ticked schedules.manage (permission matrix) but no dashboard.view:
    // admin/api/ai-settings.php would refuse it, so it must not get the button
    $CTR = $mk('counter', ['schedules.manage']);

    echo "\n-- admin/themes.php as a super-admin\n";
    $html = child(['what' => 'page', 'admin' => $SUP, 'script' => 'themes.php']);
    if (($dump = getenv('U6_DUMP_HTML')) !== false && $dump !== '') { @file_put_contents($dump, $html); }   // optional: look at it in a browser
    check('renders, no PHP warning / notice', str_contains($html, 'class="th-grid"') && preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $html) !== 1,
        preg_match('/(Warning|Notice|Deprecated|Fatal error):[^\n]{0,160}/', $html, $wm) === 1 ? $wm[0] : '');
    $keys = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $prev = 0;
    foreach ($keys as $k) { $prev += str_contains($html, 'href="/?accent_preview=' . $k . '" target="_blank" rel="noopener"') ? 1 : 0; }
    check('7 Preview links /?accent_preview=<key> (new tab)', $prev === 7, "$prev of 7");
    check('7 swatch cards, each light + dark', substr_count($html, 'class="th-card') === 7 && substr_count($html, '<small>Light · उज्यालो</small>') === 7 && substr_count($html, '<small>Dark · अँध्यारो</small>') === 7);
    $css = (string) file_get_contents($ROOT . '/assets/css/ds.css');
    preg_match('~\[data-theme="light"\]\[data-accent="mon"\]\{[^}]*--royal:(#[0-9A-Fa-f]+)~', $css, $mon);
    preg_match('~\[data-theme="dark"\]\[data-accent="sun"\]\{[^}]*--royal:(#[0-9A-Fa-f]+)~', $css, $sunD);
    check('the swatch colours are the ds.css ones (Mon light, Sun dark)', isset($mon[1], $sunD[1]) && str_contains($html, 'background:' . $mon[1] . ';') && str_contains($html, 'background:' . $sunD[1] . ';'),
        ($mon[1] ?? '?') . ' / ' . ($sunD[1] ?? '?'));
    check('colour names from the ds.css comment (Sat = burnt orange)', str_contains($html, 'गाढा सुन्तला · Burnt orange'));
    if (preg_match('~<h2>अर्को ७ दिन · Next 7 days \(India time\)</h2>\s*<ul class="th-days">(.*?)</ul>~s', $html, $wk) === 1) {
        preg_match_all('~<span class="d">([^<]+)</span>~', $wk[1], $dm);
        $ist = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
        $want = [];
        for ($i = 0; $i < 7; $i++) { $want[] = $ist->modify("+$i day")->format('D, j M'); }
        check('next 7 days = 7 rows, IST dates from today', ($dm[1] ?? []) === $want, implode(' | ', $dm[1] ?? []));
        $ne = ['sun' => 'आइतबार', 'mon' => 'सोमबार', 'tue' => 'मंगलबार', 'wed' => 'बुधबार', 'thu' => 'बिहीबार', 'fri' => 'शुक्रबार', 'sat' => 'शनिबार'];
        check('each row carries its IST weekday\'s accent (the template rule)', str_contains($wk[1], $ne[strtolower($ist->format('D'))]) && substr_count($wk[1], '<li>') === 7);
        check('today is marked', substr_count($wk[1], 'class="now"') === 1);
    } else {
        check('next 7 days panel present', false);
    }
    check('festival panel present (AI Marketing dates)', str_contains($html, 'चाडपर्व · Festival dates'));
    check('owner text in नेपाली · English and says nothing is saved', str_contains($html, 'केही सेभ हुँदैन') && str_contains($html, 'Nothing is saved'));
    /* 16 Nov 2026 (X2): the weekly colours stay read-only; only the Special dates panel posts,
       back to this page, for a settings manager (tests/u9-X2-festival-test.php drives it) */
    preg_match_all('~<form\b[^>]*method="post"[^>]*>~i', $html, $fm, PREG_OFFSET_CAPTURE);
    $posts = array_column($fm[0], 0);
    check('the only POST forms are the Special dates ones, posting back to themes.php',
        count($posts) >= 3 && count(array_filter($posts, static fn(string $f): bool => !str_contains($f, 'action="/admin/themes.php#special"'))) === 0
        && (int) ($fm[0][0][1] ?? 0) > (int) strpos($html, 'id="special"'), count($posts) . ' forms');
    check('the weekly swatch cards have no form', !preg_match('~class="th-card[^"]*"[^>]*>(?:(?!</section>).)*<form~s', $html));
    check('head: capable + status bar + staff apple icon metas render',
        str_contains($html, '<meta name="apple-mobile-web-app-capable" content="yes">') && str_contains($html, '<meta name="mobile-web-app-capable" content="yes">')
        && str_contains($html, 'apple-mobile-web-app-status-bar-style') && str_contains($html, 'href="/assets/img/mgmt-apple-180.png"'));
    $tok = preg_match('~<!--CSRF:([0-9a-f]+)-->~', $html, $tm) === 1 ? $tm[1] : '';
    check('shg-admin-csrf meta = the session CSRF token (what verifyCsrf checks)', $tok !== '' && str_contains($html, '<meta name="shg-admin-csrf" content="' . $tok . '">'));
    check('the menu shows the page and AI Sahayak', str_contains($html, 'href="/admin/themes.php"') && str_contains($html, 'href="/admin/ai-copilot.php"'));
    check('the menu marks Colours & Themes as the open page', preg_match('~<a class="on" href="/admin/themes\.php">~', $html) === 1);
    $bubbleFile = is_file($ROOT . '/assets/js/admin-ai-bubble.js');
    check('bubble script tag on the page for a super-admin ' . ($bubbleFile ? '(file present)' : '(file not built yet -> no tag)'),
        (bool) preg_match('#<script src="/assets/js/admin-ai-bubble\.js\?v=\d+" defer></script>#', $html) === $bubbleFile);

    echo "\n-- admin/themes.php as support (dashboard.view, no settings.manage)\n";
    $sh = child(['what' => 'page', 'admin' => $SPT, 'script' => 'themes.php']);
    check('support reads the page and the Special dates panel', str_contains($sh, 'class="th-grid"') && str_contains($sh, 'id="special"'));
    check('…but gets no form to change anything', stripos($sh, '<form method="post"') === false && stripos($sh, 'name="act"') === false);

    echo "\n-- admin/themes.php as an agent\n";
    $ah = child(['what' => 'page', 'admin' => $AGT, 'script' => 'themes.php']);
    check('an agent cannot open it (refused, no swatches)', !str_contains($ah, 'class="th-grid"') && !str_contains($ah, 'accent_preview')
        && stripos($ah, 'headers already sent') === false, trim(substr((string) preg_replace('/\s+/', ' ', strip_tags($ah)), 0, 120)));

    echo "\n-- AI bubble rule per role and page\n";
    $p = probe($SUP);
    check('super-admin: on (when the script file exists)', ($p['bubble'] ?? null) === $bubbleFile, json_encode($p['bubble'] ?? $p));
    $p = probe($OPS);
    check('operations (routes.edit / schedules.manage): on when built', ($p['cmd'] ?? false) === true && ($p['bubble'] ?? null) === $bubbleFile);
    $p = probe($SPT);
    check('support: off (no settings / routes / schedules right)', ($p['bubble'] ?? true) === false && ($p['cmd'] ?? true) === false);
    $p = probe($AGT);
    check('agent: off', ($p['bubble'] ?? true) === false);
    $p = probe($CTR);
    check('counter + schedules.manage but no dashboard.view: off (the endpoint boots with dashboard.view)',
        ($p['cmd'] ?? false) === true && ($p['bubble'] ?? true) === false, json_encode(['cmd' => $p['cmd'] ?? null, 'bubble' => $p['bubble'] ?? null]));
    $aiApi = (string) @file_get_contents($ROOT . '/admin/api/ai-settings.php');
    check("…admin/api/ai-settings.php really boots with dashboard.view", str_contains($aiApi, "admin_boot('dashboard.view')"));
    foreach (['easy-settings.php', 'ai-copilot.php', 'quick-unlock.php', 'pin-setup.php', 'change-password.php', 'payslip.php'] as $pg) {
        $p = probe($SUP, $pg);
        check("off on $pg", ($p['bubble'] ?? true) === false);
    }
    $p = probe($SUP, 'manifest.php', ['print' => '1']);
    check('off on a ?print=1 view', ($p['bubble'] ?? true) === false);

    echo "\n-- Menu per role\n";
    $p = probe($SUP);
    $nav = (array) ($p['nav'] ?? []);
    check('super-admin: themes.php (dashboard.view) + ai-copilot.php (bookings.view)', ($nav['themes.php'] ?? '') === 'dashboard.view' && ($nav['ai-copilot.php'] ?? '') === 'bookings.view');
    $hasM = is_file($ROOT . '/admin/messenger-inbox.php');
    $hasB = is_file($ROOT . '/admin/bulk-tickets.php');
    check('messenger-inbox.php listed iff the page exists ' . ($hasM ? '(exists)' : '(not built yet)'), isset($nav['messenger-inbox.php']) === $hasM && (!$hasM || $nav['messenger-inbox.php'] === 'customers.view'));
    check('bulk-tickets.php listed iff the page exists ' . ($hasB ? '(exists)' : '(not built yet)'), isset($nav['bulk-tickets.php']) === $hasB && (!$hasB || $nav['bulk-tickets.php'] === 'bookings.view'));
    $keysNav = array_keys($nav);
    $pos = static fn(string $h): int => (int) array_search($h, $keysNav, true);
    if ($hasM) {
        check('messenger-inbox sits in the same trunk as wa-inbox', abs($pos('messenger-inbox.php') - $pos('wa-inbox.php')) <= 12);
    }
    $p = probe($AGT);
    $nav = (array) ($p['nav'] ?? []);
    check('agent: AI Sahayak in the menu, no Colours & Themes', isset($nav['ai-copilot.php']) && !isset($nav['themes.php']), implode(',', array_keys($nav)));
    $p = probe($SPT);
    $nav = (array) ($p['nav'] ?? []);
    check('support: AI Sahayak + Colours & Themes (dashboard.view)', isset($nav['ai-copilot.php']) && isset($nav['themes.php']));

    echo "\n-- Home 'ai' tile per role\n";
    $p = probe($AGT, 'home.php');
    check('agent: the AI tile, and the old hero tiles stay up front',
        in_array('ai', (array) ($p['tiles'] ?? []), true) && array_diff(['sell', 'counter', 'mytix', 'mychalani', 'wallet', 'id'], (array) ($p['hero'] ?? [])) === []
        && ($p['aiHref'] ?? '') === '/admin/ai-copilot.php', implode(',', (array) ($p['hero'] ?? [])));
    $p = probe($SUP, 'home.php');
    check('super-admin: the AI tile, the office hero row unchanged',
        in_array('ai', (array) ($p['tiles'] ?? []), true) && array_slice((array) ($p['hero'] ?? []), 0, 8) === ['sell', 'counter', 'approvals', 'pay', 'team', 'addmember', 'reports', 'admin'],
        implode(',', (array) ($p['hero'] ?? [])));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage());
} finally {
    $cleanup();
}
$left = (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE username LIKE :u", ['u' => 'u6-%-' . $tag], 0);
check('clean-up: no test admin left', $left === 0);
finish();
