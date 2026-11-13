<?php
/**
 * =====================================================================
 *  two-apps-test.php — 13 Nov 2026 (owner: "two distinct apps: customer
 *  .in = S Hari Travel, staff .network = S Hari Manage").
 *
 *  No database, nothing is written. It reads the files that make the two
 *  apps two apps and proves:
 *    - two manifests with different names, ids, icons and start pages
 *    - the session cookie is host-only (no shared login between domains)
 *      and there is no code that hands a login from one door to the other
 *    - the customer page carries no staff sign-in link unless a selling
 *      staff session is on it (index.php strips the <!--staff--> blocks)
 *    - the staff door names the staff manifest and never gets the
 *      customer install offer
 *    - sw.js: the staff door's code caches have their own names, old ones
 *      are dropped on activate, the saved ticket is never dropped, and
 *      /api + /admin stay network-only
 *    - the first-visit install strip: Install + Continue in browser,
 *      not a dialog, never on staff, in 3 languages
 *    - the weekly accent: the owner's seven colours, the right weekday in
 *      Asia/Kolkata, and every colour pair at 4.5:1 or better, light + dark
 *
 *    php tests/two-apps-test.php [http://localhost:PORT]
 *  With a base URL it also fetches the page, the manifests and sw.js.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
};
$read = static fn(string $p): string => (string) file_get_contents($root . '/' . $p);

echo "\n1. Two manifests, two identities\n";
$cm = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $read('manifest.webmanifest')), true);
$sm = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $read('admin/manifest.webmanifest')), true);
$ok(is_array($cm) && is_array($sm), 'both manifests are valid JSON');
$ok(($cm['short_name'] ?? '') === 'S Hari Travel', 'customer app is "S Hari Travel"');
$ok(($sm['short_name'] ?? '') === 'S Hari Manage', 'staff app is "S Hari Manage"');
$ok(($cm['id'] ?? '') === '/' && ($sm['id'] ?? '') === '/admin/', 'app ids unchanged (installed apps keep updating): / and /admin/');
$ok(($cm['start_url'] ?? '') === '/' && str_starts_with((string) ($sm['start_url'] ?? ''), '/admin/login.php'), 'different start pages');
$icons = static fn(array $m): array => array_map(static fn($i) => (string) preg_replace('/\?.*$/', '', (string) $i['src']), $m['icons'] ?? []);
$ok(array_intersect($icons($cm), $icons($sm)) === [], 'no icon file is shared by the two apps');
foreach (array_merge($icons($cm), $icons($sm)) as $ic) {
    $ok(is_file($root . $ic), "icon exists: $ic");
}
$ok(($cm['theme_color'] ?? '') !== ($sm['theme_color'] ?? ''), 'different theme colours');

echo "\n2. Sessions and logins stay on their own domain\n";
$boot = $read('includes/bootstrap.php');
$ok((bool) preg_match("~session_set_cookie_params\(\[.*?'domain'\s*=>\s*''~s", $boot), "session cookie domain is '' (host-only: .in and .network never share it)");
$ok(str_contains($boot, "'httponly' => true") && str_contains($boot, "'samesite' => 'Lax'"), 'cookie is HttpOnly + SameSite=Lax');
$hand = 0;
foreach (['includes/opshub.php', 'includes/auth.php', 'includes/onedoor.php', 'admin/login.php', 'index.php'] as $f) {
    if (is_file($root . '/' . $f) && preg_match('~(sso|handoff|cross_?login|login_token)\s*=~i', $read($f))) { $hand++; }
}
$ok($hand === 0, 'no login hand-over token between the two doors');
$ops = $read('includes/opshub.php');
$ok(str_contains($ops, 'function onStaffHost('), 'OpsHub::onStaffHost() exists');
$ok(str_contains($ops, 'function enforceStaffDoor(') && str_contains($ops, "'/admin/api/'"), 'staff pages on the customer domain still move to the staff door (old bookmarks keep working)');

echo "\n3. Customer page: no staff UI\n";
$tpl = $read('app.template.html');
$idx = $read('index.php');
$strip = (string) preg_replace('~<!--staff-->.*?<!--/staff-->~s', '', $tpl);
$strip = (string) preg_replace('/<!--(?!\[if).*?-->/s', '', $strip);   // index.php drops every comment at serve time
$ok(!str_contains($strip, '/admin/login.php'), 'no /admin/login.php link for a customer');
$ok(!preg_match('~href="/team/"~', $strip), 'no /team/ login link for a customer');
$ok(!preg_match('~href="/admin/~', $strip), 'no /admin/ link of any kind for a customer');
$ok(str_contains($tpl, '/admin/login.php') && str_contains($tpl, 'href="/team/"'), 'a selling staff session still gets both links');
$ok(substr_count($tpl, '<!--staff-->') === substr_count($tpl, '<!--/staff-->'), 'staff markers are balanced');
$ok(str_contains($idx, "\$boot['door'] = \$shgStaffDoor ? 'staff' : 'customer';"), 'the page tells the app which door it is on');
$ok(str_contains($idx, '/admin/app-manifest.php') && str_contains($tpl, '<link rel="manifest" href="/manifest.webmanifest">'), 'staff door swaps in the staff manifest');
$ok(str_contains($idx, "preg_replace('#<script defer src=\"/assets/js/14-counter"), 'the counter bundle is still dropped from a customer page');

echo "\n4. Service worker\n";
$sw = $read('sw.js');
$ok(str_contains($sw, "var CACHE_NS = STAFF_DOOR ? 'shm-' : 'shg-';"), 'staff door caches are shm-…, customer caches shg-…');
$ok(!preg_match("~'shg-(shell|tiles|assets)-'~", $sw), 'no code cache is still named with a fixed shg- prefix');
$ok(str_contains($sw, "var TICKET_CACHE = 'shg-tickets-v1';") && str_contains($sw, 'if (k === TICKET_CACHE) return null;'), 'the saved ticket cache keeps its name and is never dropped');
$ok(str_contains($sw, "if (STAFF_DOOR && /^shg-(shell|tiles|assets)-/.test(k)) return caches.delete(k);"), 'upgrade: the staff door drops its old customer-named code caches');
$ok((bool) preg_match('~/api/\*\s+and\s+/admin/\*~', $sw) && (bool) preg_match("~pathname\.indexOf\('/api/'\)|/\^\\\\/\(api|admin\)|startsWith\('/api/'\)|\\\\/api\\\\/~", $sw), '/api and /admin are still network-only');
$ok(str_contains($tpl, '/^sh[gm]-(shell|assets)-/'), 'the stale-stylesheet guard knows both names');

echo "\n5. First-visit install strip\n";
$pwa = $read('assets/js/17-pwa.js');
$ok(str_contains($pwa, "function invite()") && str_contains($pwa, "T('pwaContinue')") && str_contains($pwa, "T('pwaInstallBtn')"), 'strip has Install and Continue in browser');
$ok((bool) preg_match('~function invite\(\) \{\s*if \(isStaff\(\) \|\| onStaffDoor\(\) \|\| quiet\(\)~', $pwa), 'never for staff, the staff door, an installed app or after a dismissal');
$inv = substr($pwa, (int) strpos($pwa, 'function invite()'), 4200);
$ok(!str_contains($inv, 'aria-modal') && !str_contains($inv, 'pwa-backdrop'), 'the strip is not a dialog and has no backdrop');
$ok(str_contains($inv, "sb.addEventListener('focusin', inviteGone") && str_contains($inv, "'hashchange'"), 'it leaves when a booking starts or another screen opens');
$ok(str_contains($inv, 'min-height:44px'), 'buttons are 44px touch targets');
$ok(str_contains($inv, 'p.prompt()') && str_contains($inv, 'showIOS()'), "Android: the browser's own prompt from the tap; iOS: the real steps");
$i18n = $read('assets/js/04-i18n.js');
$ok(substr_count($i18n, 'pwaContinue:') === 3, '"Continue in browser" in English, Hindi and Nepali');

echo "\n6. Weekly accent\n";
$want = ['mon' => '#1D4ED8', 'tue' => '#0F766E', 'wed' => '#6D28D9', 'thu' => '#166534', 'fri' => '#4338CA', 'sat' => '#9A3412', 'sun' => '#BE123C'];
$css = $read('assets/css/ds.css');
$lum = static function (string $h): float {
    $c = [];
    foreach ([1, 3, 5] as $i) {
        $v = hexdec(substr($h, $i, 2)) / 255;
        $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    }
    return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
};
$cr = static function (string $a, string $b) use ($lum): float {
    $x = $lum($a); $y = $lum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
};
$tok = static function (string $css, string $theme, string $day): array {
    if (!preg_match('~:root:root\[data-theme="' . $theme . '"\]\[data-accent="' . $day . '"\]\{([^}]*)\}~', $css, $m)) { return []; }
    $o = [];
    foreach (explode(';', $m[1]) as $kv) {
        [$k, $v] = array_pad(explode(':', $kv, 2), 2, '');
        $o[trim($k)] = trim($v);
    }
    return $o;
};
$low = [];
foreach ($want as $d => $hex) {
    $L = $tok($css, 'light', $d); $D = $tok($css, 'dark', $d);
    $ok(($L['--royal'] ?? '') === $hex, "$d light accent is the owner's $hex");
    if ($L === [] || $D === []) { $low[] = "$d missing"; continue; }
    $pairs = [
        ["$d light: white on accent", '#FFFFFF', $L['--royal']], ["$d light: accent on white", $L['--royal'], '#FFFFFF'],
        ["$d light: strong on soft", $L['--royal-strong'], $L['--royal-soft']], ["$d light: accent on tint", $L['--royal'], $L['--royal-tint']],
        ["$d dark: ink on accent", $D['--on-royal'], $D['--royal']], ["$d dark: accent on page", $D['--royal'], '#0B1220'],
        ["$d dark: accent on surface", $D['--royal'], '#111D33'], ["$d dark: strong on soft", $D['--royal-strong'], $D['--royal-soft']],
        ["$d dark: accent on AMOLED black", $D['--royal'], '#000000'],
    ];
    foreach ($pairs as [$n, $a, $b]) {
        if ($cr($a, $b) < 4.5) { $low[] = $n . ' = ' . number_format($cr($a, $b), 2); }
    }
}
$ok($low === [], '63 colour pairs are 4.5:1 or better' . ($low === [] ? '' : ' — ' . implode('; ', $low)));
$ok(!preg_match('~data-accent="[a-z]+"\]\{[^}]*--(success|warning|danger|seat-|navy|red|orange)~', $css), 'accent never touches seat, status, navy or logo colours');
$ok(str_contains($tpl, "K = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'], IST = 19800000"), 'weekday is taken in Asia/Kolkata (UTC+5:30)');
$ok(str_contains($tpl, "localStorage.getItem('shg:accent') === 'fixed'") && str_contains($tpl, 'data-accent-set="weekly"') && str_contains($tpl, 'data-accent-set="fixed"'), 'Weekly / Fixed choice, remembered on the phone');
$acc = substr($tpl, (int) strpos($tpl, '13 Nov 2026: weekly accent'), 2600);
$ok(!str_contains($acc, 'location.reload') && str_contains($acc, 'visibilitychange') && str_contains($acc, 'setTimeout'), 'midnight + app-resume recheck without a reload (a form in progress is kept)');
/* the same weekday arithmetic as the page, checked against PHP's own calendar */
$bad = 0;
$tz = new DateTimeZone('Asia/Kolkata');
foreach ([1794508200, 1794594599, 1794594600, 1794594601, 1795113000, 1795199399] as $ts) {   // around IST midnights, Nov 2026
    $js  = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][(int) gmdate('w', $ts + 19800)];
    $php = strtolower((new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('D'));
    if ($js !== $php) { $bad++; }
}
$ok($bad === 0, 'the page arithmetic agrees with the Asia/Kolkata calendar at midnight edges');

$base = rtrim((string) ($argv[1] ?? ''), '/');
if ($base !== '') {
    echo "\n7. Over HTTP ($base)\n";
    $get = static function (string $u): array {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0]]);
        $b = @file_get_contents($u, false, $ctx);
        return [(string) ($http_response_header[0] ?? ''), (string) $b];
    };
    [$st, $home] = $get($base . '/');
    $ok(str_contains($st, '200'), 'home answers 200');
    $ok(!str_contains($home, '/admin/login.php') && !preg_match('~href="/team/"~', $home), 'served customer page has no staff sign-in link');
    $ok(str_contains($home, '"door":"customer"'), 'served page says door = customer');
    $ok(str_contains($home, '<link rel="manifest" href="/manifest.webmanifest">'), 'served page names the customer manifest');
    $ok(str_contains($home, 'data-accent-set="weekly"') && str_contains($home, 'window.shgAccent'), 'served page carries the accent script and the choice');
    [$st, $b] = $get($base . '/manifest.webmanifest');
    $ok(str_contains($st, '200') && str_contains($b, 'S Hari Travel'), 'customer manifest is served');
    [$st, $b] = $get($base . '/admin/app-manifest.php');
    $ok(str_contains($st, '200') && str_contains($b, 'S Hari Manage'), 'staff manifest is served');
    [$st, $b] = $get($base . '/sw.js');
    $ok(str_contains($st, '200') && str_contains($b, 'CACHE_NS'), 'sw.js is served with the cache names');
}

echo "\n" . ($fail === 0 ? "ALL PASSED: $pass" : "FAILED: $fail (passed $pass)") . "\n";
exit($fail === 0 ? 0 : 1);
