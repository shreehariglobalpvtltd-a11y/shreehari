<?php
/**
 * fx1 (26 Nov 2026) — the hub menu (includes/rolemenu.php + admin/_guard.php).
 *
 * Owner: "duniya dherai option, chalauna garo". The menu became 8 hubs with
 * at most 7 main rows per role. This pins what must stay true:
 *   - nothing a person may open is lost (every permitted page is in a hub,
 *     under More, or found by the menu search);
 *   - nothing is granted (the hub menu only arranges admin_nav()'s own list);
 *   - a role never gets more than 7 main rows (the super-admin 8);
 *   - admin_hub_menu_on = 0 draws the old tree again;
 *   - the parcel link follows parcel_book_on.
 *
 *   php -c .claude/php-dev.ini tests/fx1-rolemenu-test.php
 *   FX1_RENDER=1 php -c .claude/php-dev.ini tests/fx1-rolemenu-test.php   (also writes _audit/render/*.html)
 *
 * Reads the menu only; the three settings it flips are put back. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$_SERVER['SCRIPT_NAME'] = '/admin/bookings.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
require_once dirname(__DIR__) . '/admin/_guard.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $why = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($why !== '' ? "  ($why)" : '') . "\n"; }
}

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$asAdmin = static function (int $id, string $role, string $user): void {
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => $id, 'username' => $user, 'full_name' => 'Fx1 ' . ucfirst($role),
        'role' => $role, 'permissions' => [], 'must_change_pw' => false,
        'logged_in_at' => time(), 'last_seen' => time(), 'checked_at' => time(),
    ];
};
$page = static function (string $href, string $title, string $active): string {
    $_SERVER['SCRIPT_NAME'] = '/admin/' . $href;
    admin_hub_menu(null, true);
    ob_start();
    admin_header($title, $active);
    echo '<div class="card" style="padding:16px">fx1 menu preview</div>';
    admin_footer();
    return (string) ob_get_clean();
};

$keep = [];
foreach (['admin_hub_menu_on', 'parcel_book_on', 'rolemenu_roles', 'rolemenu_hidden', 'admin_hub_tabs_on', 'admin_bottom_bar_on'] as $k) {
    $keep[$k] = Settings::get($k, null);
}
$restore = static function () use ($keep): void {
    foreach ($keep as $k => $v) {
        if ($v === null) {
            Database::delete('settings', 'skey = :k', ['k' => $k]);
        } else {
            Settings::set($k, $v, in_array($k, ['rolemenu_roles', 'rolemenu_hidden'], true) ? 'string' : 'bool', 'admin');
        }
    }
    Settings::flush();
};

try {
    Settings::set('admin_hub_menu_on', '1', 'bool', 'admin');
    Settings::set('admin_hub_tabs_on', '1', 'bool', 'admin');
    Settings::set('admin_bottom_bar_on', '1', 'bool', 'admin');
    Settings::set('rolemenu_roles', '', 'string', 'admin');
    Settings::set('rolemenu_hidden', 'places.php,agent-offline.php,/navigate.html', 'string', 'admin');
    Settings::set('parcel_book_on', '0', 'bool', 'booking');

    echo "\n  The config itself\n";
    $hubs = RoleMenu::hubs();
    check('eight hubs', count($hubs) === 8, (string) count($hubs));
    $seen = []; $dupes = [];
    foreach ($hubs as $hk => $hub) {
        foreach ($hub['tabs'] as $tk => $tab) {
            foreach ($tab[2] as $href) {
                if (isset($seen[$href])) { $dupes[] = $href; }
                $seen[$href] = "$hk/$tk";
            }
        }
    }
    check('no page sits in two hubs', $dupes === [], implode(', ', $dupes));
    foreach (RoleMenu::defaultRoles() as $role => $keys) {
        check("default row list of $role: known hubs, at most " . ($role === 'superadmin' ? 8 : 7),
            array_diff($keys, array_keys($hubs)) === [] && count($keys) <= ($role === 'superadmin' ? 8 : RoleMenu::MAX_MAIN));
    }

    echo "\n  Every role: nothing lost, nothing granted\n";
    $roles = ['superadmin', 'manager', 'accountant', 'operations', 'support', 'official', 'counter', 'agent', 'scanner', 'viewer'];
    $superHrefs = [];
    foreach ($roles as $role) {
        $asAdmin($role === 'superadmin' ? 1 : 999000 + array_search($role, $roles, true), $role, 'fx1_' . $role);
        $items = admin_nav();
        $hrefs = array_map(static fn(array $i): string => (string) $i['href'], $items);
        if ($role === 'superadmin') { $superHrefs = $hrefs; }
        $built = RoleMenu::build($items, $role);
        $cap = $role === 'superadmin' ? 8 : RoleMenu::MAX_MAIN;
        check("$role: " . count($built['main']) . " main rows (max $cap), " . count($built['more']) . ' under More, ' . count($hrefs) . ' pages',
            count($built['main']) <= $cap && ($hrefs === [] || count($built['main']) >= 1));
        $inMenu = [];
        foreach (array_merge($built['main'], $built['more']) as $hub) {
            foreach ($hub['tabs'] as $tab) {
                foreach ($tab['items'] as $it) { $inMenu[] = (string) $it['href']; }
            }
            check("$role: the {$hub['key']} row opens a page this role may open", in_array((string) $hub['href'], $hrefs, true), (string) $hub['href']);
        }
        $hidden = RoleMenu::hidden();
        $lost  = array_diff($hrefs, $inMenu, $hidden);
        $extra = array_diff($inMenu, $hrefs);
        check("$role: every permitted page is in a hub (or on the hidden list)", $lost === [], implode(', ', $lost));
        check("$role: the menu holds no page admin_nav() did not give", $extra === [], implode(', ', $extra));
        check("$role: no page drawn twice", count($inMenu) === count(array_unique($inMenu)));
        $found = array_map(static fn(array $p): string => (string) $p['href'], $built['pages']);
        check("$role: the menu search knows every permitted page, hidden ones too", array_diff($hrefs, $found) === [] && array_diff($found, $hrefs) === []);
    }
    $unmapped = array_diff($superHrefs, array_keys(RoleMenu::index()));
    check('every page of the full menu has a hub and a tab of its own (no fallback)', $unmapped === [], implode(', ', $unmapped));
    $noName = array_diff($superHrefs, array_keys(RoleMenu::pageLabels()));
    check('every page of the full menu has its one Nepali · English name', $noName === [], implode(', ', $noName));
    $asAdmin(999300, 'agent', 'fx1_agent');
    $agentNames = [];
    foreach (admin_nav() as $it) { $agentNames[(string) $it['href']] = admin_hub_label($it); }
    check('an agent keeps "My ..." wording (never the office name)', str_starts_with((string) ($agentNames['settlements.php'] ?? ''), 'My ') || str_contains((string) ($agentNames['settlements.php'] ?? ''), 'My '), (string) ($agentNames['settlements.php'] ?? ''));
    check('the parcel link is gone while parcel_book_on = 0', !in_array('parcels.php', $superHrefs, true));
    Settings::set('parcel_book_on', '1', 'bool', 'booking');
    $asAdmin(1, 'superadmin', 'fx1_superadmin');
    $withParcel = array_column(admin_nav(), 'href');
    check('... and back with parcel_book_on = 1', in_array('parcels.php', $withParcel, true));
    check('... where it has a tab of its own too (no fallback)', array_diff($withParcel, array_keys(RoleMenu::index())) === []);
    Settings::set('parcel_book_on', '0', 'bool', 'booking');

    echo "\n  Role blueprint\n";
    $rowsOf = static function (string $role) use ($asAdmin): array {
        $asAdmin($role === 'superadmin' ? 1 : 999100, $role, 'fx1_' . $role);
        return array_column(RoleMenu::build(admin_nav(), $role)['main'], 'href', 'key');
    };
    $c = $rowsOf('counter');
    check('counter: Book ticket is the first row and opens SmartBook', array_key_first($c) === 'book' && $c['book'] === 'quick-ticket.php', json_encode($c));
    check('counter: Buses & Chalani opens the chalani', ($c['buses'] ?? '') === 'manifest.php', (string) ($c['buses'] ?? ''));
    check('counter: no People, no Settings row', !isset($c['people']) && !isset($c['settings']));
    $a = $rowsOf('agent');
    check('agent: Book first; money = own settlements; live bus; own ID', array_key_first($a) === 'book' && ($a['money'] ?? '') === 'settlements.php' && ($a['buses'] ?? '') === 'map.php' && ($a['people'] ?? '') === '/me/', json_encode($a));
    $m = $rowsOf('manager');
    check('manager: Settings is not a main row', !isset($m['settings']) && isset($m['money'], $m['people']), json_encode(array_keys($m)));
    $s = $rowsOf('superadmin');
    check('super-admin: all eight rows, Home first', array_keys($s) === ['home', 'book', 'tickets', 'buses', 'money', 'people', 'reports', 'settings'], json_encode(array_keys($s)));
    $np = RoleMenu::build(admin_nav(), 'superadmin', ['np_desk' => true]);
    check('a Nepal desk: Book ticket opens the Nepal counter home', (string) (array_column($np['main'], 'href', 'key')['book'] ?? '') === 'nepal-counter.php');

    echo "\n  The owner's own ticks (rolemenu_roles)\n";
    Settings::set('rolemenu_roles', json_encode(['counter' => ['book', 'money', 'nope', 'book'], 'manager' => ['home', 'book', 'tickets', 'buses', 'money', 'people', 'reports', 'settings']]), 'string', 'admin');
    $ov = RoleMenu::roleOverrides();
    check('unknown and doubled keys are dropped', ($ov['counter'] ?? []) === ['book', 'money'], json_encode($ov['counter'] ?? null));
    check('a role is capped at 7 rows', count($ov['manager'] ?? []) === 7);
    check('roleHubs() follows the ticks', RoleMenu::roleHubs('counter') === ['book', 'money']);
    Settings::set('rolemenu_roles', '{broken', 'string', 'admin');
    check('a broken value falls back to the defaults', RoleMenu::roleHubs('counter') === RoleMenu::defaultRoles()['counter']);
    Settings::set('rolemenu_roles', '', 'string', 'admin');

    echo "\n  The drawn page\n";
    $asAdmin(1, 'superadmin', 'fx1_superadmin');
    $html = $page('bookings.php', 'Tickets Register', 'bookings');
    check('hub rows are drawn (8 for the super-admin)', substr_count($html, 'class="hm-row') === 8, (string) substr_count($html, 'class="hm-row'));
    check('the old five trunks are not drawn', !str_contains($html, 'class="side-group'));
    check('the Tickets row is the active one', (bool) preg_match('/class="hm-row on"[^>]*href="\/admin\/bookings\.php"/', $html));
    check('the menu search box and its page list are there', str_contains($html, 'id="hmFind"') && str_contains($html, 'SHG_MENU_FIND='));
    check('the tab strip shows the Tickets tabs', str_contains($html, 'class="hm-tabs"') && str_contains($html, '>Requests<'));
    check('pages are named Nepali first in the strip', str_contains($html, 'टिकट रजिस्टर · Tickets register'));
    check('the payments badge the live poll needs is still on the page, once', substr_count($html, 'id="navBadgePayments"') === 1);
    check('the phone bottom bar: 4 rows + More', str_contains($html, 'class="hm-bar"') && substr_count(explode('class="hm-bar"', $html)[1], '<a ') >= 4);
    $sell = $page('quick-ticket.php', 'Quick Ticket', 'quick-ticket');
    check('the selling screen keeps its whole screen: no tab strip, no bottom bar', !str_contains($sell, 'class="hm-tabs"') && !str_contains($sell, 'class="hm-bar"'));
    $sb = $page('smartbook.php', 'SmartBook', 'smartbook');
    check('SmartBook (where quick-ticket.php lands): no bottom bar under its Pay bar, Book ticket row lit', !str_contains($sb, 'class="hm-bar"') && (bool) preg_match('/class="hm-row on hot"[^>]*href="\/admin\/quick-ticket\.php"/', $sb));

    if (getenv('FX1_RENDER')) {
        $dir = dirname(__DIR__) . '/_audit/render';
        if (!is_dir($dir)) { mkdir($dir, 0777, true); }
        foreach ([['superadmin', 'bookings.php', 'Tickets Register', 'bookings'], ['superadmin', 'payments.php', 'Verify Payments', 'payments'],
                  ['superadmin', 'wa-inbox.php', 'WhatsApp Inbox', 'wa-inbox'], ['manager', 'manifest.php', 'Manifest & Chalani', 'manifest'],
                  ['counter', 'bookings.php', 'My Tickets', 'bookings'], ['agent', 'settlements.php', 'My Settlements', 'settlements'],
                  ['accountant', 'payments.php', 'Verify Payments', 'payments'], ['operations', 'bus-control.php', 'Bus Control', 'bus-control'],
                  ['support', 'ticket-requests.php', 'Requests', 'ticket-requests']] as [$role, $href, $title, $act]) {
            $asAdmin($role === 'superadmin' ? 1 : 999200, $role, 'fx1_' . $role);
            file_put_contents("$dir/$role-" . basename($href, '.php') . '.html', $page($href, $title, $act));
        }
        echo "  (rendered pages written to _audit/render/)\n";
    }

    echo "\n  The staff opening scene\n";
    $bootKeep = Settings::get('staff_boot_scene_on', null);
    Settings::set('staff_boot_scene_on', '1', 'bool', 'admin');
    $withBoot = $page('bookings.php', 'Tickets Register', 'bookings');
    check('the scene is on the page, hidden until its script shows it', str_contains($withBoot, '<div id="shgStaffBoot" aria-hidden="true" hidden>'));
    check('its two pictures are not fetched unless it plays (data-src, no src)', substr_count($withBoot, 'data-src="/assets/img/') === 2 && !preg_match('/id="shgStaffBoot".*?<img src=/s', explode('</style>', explode('id="shgStaffBoot"', $withBoot)[1])[0]));
    check('once per session, skipped for reduced motion', str_contains($withBoot, "sessionStorage.getItem('shg_staff_boot')") && str_contains($withBoot, 'prefers-reduced-motion:reduce') && str_contains($withBoot, "shg:motion"));
    check('it can never cover a page on its own: display none until the script adds sbt-on, no bare display:grid on the id',
        str_contains($withBoot, '#shgStaffBoot{display:none}') && str_contains($withBoot, '#shgStaffBoot.sbt-on{position:fixed') && !preg_match('/#shgStaffBoot\{[^}]*display:grid/', $withBoot));
    check('both pictures exist in the tree', is_file(dirname(__DIR__) . '/assets/img/logo-mark-tm.webp') && is_file(dirname(__DIR__) . '/assets/img/hd/bus-cutout-t.webp'));
    Settings::set('staff_boot_scene_on', '0', 'bool', 'admin');
    check('staff_boot_scene_on = 0: no scene at all', !str_contains($page('bookings.php', 'Tickets Register', 'bookings'), 'shgStaffBoot'));
    if ($bootKeep === null) { Database::delete('settings', 'skey = :k', ['k' => 'staff_boot_scene_on']); Settings::flush(); }
    else { Settings::set('staff_boot_scene_on', $bootKeep, 'bool', 'admin'); }

    echo "\n  The starter-password nudge\n";
    $nudgeKeep = [Settings::get('default_pw_nudge_on', null), Settings::get('login_default_pw_on', null)];
    $hasDefCol = (int) Database::scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admins' AND COLUMN_NAME = 'pw_is_default'", [], 0) === 1;
    if (!$hasDefCol) {
        echo "  (skipped: admins.pw_is_default is not in this database)\n";
    } else {
        Settings::set('default_pw_nudge_on', '1', 'bool', 'security');
        Settings::set('login_default_pw_on', '1', 'bool', 'security');
        Database::delete('admins', 'username = :u', ['u' => 'fx1_nudge_t']);
        $nid = (int) Database::insert('admins', ['username' => 'fx1_nudge_t', 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
            'full_name' => 'Nudge Tester', 'role' => 'counter', 'is_active' => 1, 'must_change_pw' => 0, 'pw_is_default' => 1]);
        try {
            $asAdmin($nid, 'counter', 'fx1_nudge_t'); unset($_SESSION['fx1_pwdef']);
            $n1 = $page('bookings.php', 'My Tickets', 'bookings');
            check('someone on the starter password sees the nudge with a link to change it', str_contains($n1, 'id="hmPwNudge"') && str_contains($n1, 'href="/admin/change-password.php"'));
            check('... but never on the selling screen', !str_contains($page('smartbook.php', 'SmartBook', 'smartbook'), 'id="hmPwNudge"'));
            // the easy door (the one that takes FirstName@2026) opens its session with via_otp = true, via = 'easy' (Auth::openAdminWithoutPassword)
            $_SESSION[ADMIN_SESSION_KEY]['via_otp'] = true; $_SESSION[ADMIN_SESSION_KEY]['via'] = 'easy'; unset($_SESSION['fx1_pwdef']);
            check('a session opened at the easy door is nudged too', str_contains($page('bookings.php', 'My Tickets', 'bookings'), 'id="hmPwNudge"'));
            $_SESSION[ADMIN_SESSION_KEY]['via'] = 'otp'; unset($_SESSION['fx1_pwdef']);
            check('a WhatsApp-code session (no password typed) is not', !str_contains($page('bookings.php', 'My Tickets', 'bookings'), 'id="hmPwNudge"'));
            $asAdmin($nid, 'counter', 'fx1_nudge_t'); unset($_SESSION['fx1_pwdef']);
            $page('bookings.php', 'My Tickets', 'bookings');   // asks the database once and remembers the answer
            Database::update('admins', ['pw_is_default' => 0], 'id = :i', ['i' => $nid]);
            check('the answer is remembered for a few minutes (no query per page)', str_contains($page('bookings.php', 'My Tickets', 'bookings'), 'id="hmPwNudge"'));
            unset($_SESSION['fx1_pwdef']);
            check('own password set: no nudge', !str_contains($page('bookings.php', 'My Tickets', 'bookings'), 'id="hmPwNudge"'));
            Database::update('admins', ['pw_is_default' => 1], 'id = :i', ['i' => $nid]);
            unset($_SESSION['fx1_pwdef']);
            Settings::set('default_pw_nudge_on', '0', 'bool', 'security');
            check('default_pw_nudge_on = 0: no nudge', !str_contains($page('bookings.php', 'My Tickets', 'bookings'), 'id="hmPwNudge"'));
            Settings::set('default_pw_nudge_on', '1', 'bool', 'security');
            Database::update('admins', ['role' => 'superadmin'], 'id = :i', ['i' => $nid]);
            $asAdmin($nid, 'superadmin', 'fx1_nudge_t'); unset($_SESSION['fx1_pwdef']);
            check('a super-admin is never nudged', !str_contains($page('bookings.php', 'Tickets', 'bookings'), 'id="hmPwNudge"'));
        } finally {
            Database::delete('admins', 'id = :i', ['i' => $nid]);
            unset($_SESSION['fx1_pwdef']);
            foreach (['default_pw_nudge_on', 'login_default_pw_on'] as $i => $k) {
                if ($nudgeKeep[$i] === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
                else { Settings::set($k, $nudgeKeep[$i], 'bool', 'security'); }
            }
            Settings::flush();
        }
    }

    echo "\n  Review fixes (26 Nov, after the adversarial review of the live menu)\n";
    $asAdmin(1, 'superadmin', 'fx1_superadmin');
    $sbPage = $page('smartbook.php', 'SmartBook', 'smartbook');
    check('a full-screen page lists its hub\'s other pages under the lit row (Bulk tickets has a click path from SmartBook)',
        str_contains($sbPage, 'class="hm-kids"') && (bool) preg_match('~class="hm-kid" href="/admin/bulk-tickets\.php"~', $sbPage));
    check('... and only there: a page with a tab strip draws no such list for its own hub', !str_contains($page('bookings.php', 'Tickets', 'bookings'), 'class="hm-kid" href="/admin/ticket-requests.php"'));
    $asAdmin(999400, 'agent', 'fx1_agent');
    $agSb = $page('smartbook.php', 'SmartBook', 'smartbook');
    check('an India agent on SmartBook is not offered the Nepal counter home', !str_contains($agSb, 'class="hm-kid" href="/admin/nepal-counter.php"'));
    check('an agent\'s People row (/me/, outside the staff chrome) carries the agent dashboard under it', (bool) preg_match('~class="hm-kid" href="/admin/agent\.php"~', $agSb));
    $agRows = array_column(RoleMenu::build(admin_nav(), 'agent')['main'], 'href', 'key');
    check('agent: Reports is a main row again and opens the ticket statement / withdraw page', ($agRows['reports'] ?? '') === 'ticket-statement.php' && count($agRows) <= 7, json_encode($agRows));

    $asAdmin(1, 'superadmin', 'fx1_superadmin');
    $bv = $page('booking-view.php', 'Ticket', 'bookings');
    check('a page opened from inside a menu page lights the same hub in the sidebar, the tab strip AND the phone bar',
        (bool) preg_match('/class="hm-row on"[^>]*href="\/admin\/bookings\.php"/', $bv) && str_contains($bv, 'class="hm-tabs"')
        && (bool) preg_match('~<nav class="hm-bar".*?<a class="on" aria-current="true" href="/admin/bookings\.php" aria-label="[^"]+">~s', $bv));
    check('... without marking a menu page as the current one', !str_contains(explode('class="hm-sub"', $bv . 'class="hm-sub"')[1], 'aria-current="page"'));
    $_SERVER['SCRIPT_NAME'] = '/admin/agent-receipt.php';
    admin_hub_menu(null, true);
    $atAgent = admin_hub_at('agent', true);
    check('a page that names its row by hand: the exact file wins over a look-alike ("agent" is agent.php, not agents.php / agent-quick.php)',
        $atAgent !== null && in_array('agent.php', array_column($atAgent['hub']['tabs'][$atAgent['tab']]['items'], 'href'), true) && $atAgent['hub']['key'] === 'people');

    $asAdmin(999401, 'viewer', 'fx1_viewer');
    $vItems = admin_nav();
    admin_hub_menu(null, true);
    $vBuilt = admin_hub_menu();
    $vAll = array_column($vBuilt['pages'], 'href');
    $vBad = array_values(array_filter($vAll, static fn(string $h): bool => !str_starts_with($h, '/') && !in_array($h, Auth::VIEWER_PAGES, true)));
    check('a view-only login is offered only pages its gate lets it open (' . count($vAll) . ' of ' . count($vItems) . ')', $vBad === [] && count($vAll) >= 1, implode(', ', $vBad));
    foreach (array_merge($vBuilt['main'], $vBuilt['more']) as $vh) {
        check("viewer: the {$vh['key']} row opens a page that does not bounce", str_starts_with((string) $vh['href'], '/') || in_array((string) $vh['href'], Auth::VIEWER_PAGES, true), (string) $vh['href']);
    }

    $asAdmin(1, 'superadmin', 'fx1_superadmin');
    $rv = $page('payments.php', 'Verify Payments', 'payments');
    check('the menu search sends each page once: no ready-made search key in the page', !str_contains($rv, '"k":"') && str_contains($rv, "all.forEach(function(p){p.k="));
    check('... and an older page name is still findable ("Verify Payments (UPI/eSewa)")', str_contains($rv, '"a":"Verify Payments (UPI/eSewa)"') || str_contains($rv, 'Verify Payments (UPI\/eSewa)'));
    check('the "/" shortcut opens the drawer only where there is a drawer, and Esc closes what it opened',
        str_contains($rv, "matchMedia('(max-width:820px)').matches){document.body.classList.add('nav-open');byKey=true;}") && str_contains($rv, "if(byKey){document.body.classList.remove('nav-open');byKey=false;}"));
    check('the live poll opens the More fold when the Money row with the pending badge sits in it', str_contains($rv, "badge.closest('details.hm-more')"));
    check('sticky action bars are lifted above the phone bar (payments / ranking via --mnav-h, the save bars by class)',
        str_contains($rv, 'body.hm-has-bar{--mnav-h:calc(66px') && str_contains($rv, 'body.hm-has-bar .ap-save,body.hm-has-bar .co-save,body.hm-has-bar .ct-save'));
    check('the open tab is brought into view on a narrow screen', str_contains($rv, 'n.scrollLeft=Math.max(0,a.offsetLeft-n.offsetLeft-12)'));
    check('a page with its own .pc header keeps ONE title although the tab strip now sits between them',
        str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/_pro.php'), '.wrap>h1:has(~.pc>.pc-head:first-child)'));

    echo "\n  The brand fonts\n";
    $fontKeep = Settings::get('admin_brand_font_on', null);
    Settings::set('admin_brand_font_on', '1', 'bool', 'admin');
    $asAdmin(1, 'superadmin', 'fx1_superadmin');
    $withFont = $page('bookings.php', 'Tickets Register', 'bookings');
    check('the two font / token stylesheets are linked with a version stamp', (bool) preg_match('~<link rel="stylesheet" href="/assets/css/fx1-fonts\.css\?v=\d+">~', $withFont) && (bool) preg_match('~href="/assets/css/fx1-tokens\.css\?v=\d+"~', $withFont));
    check('the preload names the font exactly as the CSS requests it (no ?v=, crossorigin) - else it downloads twice',
        str_contains($withFont, '<link rel="preload" href="/assets/fonts/fx1-latin-400.woff2" as="font" type="font/woff2" crossorigin>')
        && str_contains((string) file_get_contents(dirname(__DIR__) . '/assets/css/fx1-fonts.css'), 'url(../fonts/fx1-latin-400.woff2)'));
    check('the override comes after the panel CSS, so it wins', strpos($withFont, 'font-family:var(--fx-font-num)') > strpos($withFont, "font-family:system-ui,-apple-system,'Segoe UI'"));
    $missing = [];
    foreach (['deva', 'latin'] as $fam) { foreach ([400, 500, 600, 700] as $w) { if (!is_file(dirname(__DIR__) . "/assets/fonts/fx1-$fam-$w.woff2")) { $missing[] = "$fam-$w"; } } }
    check('all eight WOFF2 files are in the tree', $missing === [], implode(', ', $missing));
    Settings::set('admin_brand_font_on', '0', 'bool', 'admin');
    $noFont = $page('bookings.php', 'Tickets Register', 'bookings');
    check('admin_brand_font_on = 0: no font files, the system font as before', !str_contains($noFont, 'fx1-fonts.css') && !str_contains($noFont, '--fx-font-num'));
    if ($fontKeep === null) { Database::delete('settings', 'skey = :k', ['k' => 'admin_brand_font_on']); Settings::flush(); }
    else { Settings::set('admin_brand_font_on', $fontKeep, 'bool', 'admin'); }

    echo "\n  The switch\n";
    Settings::set('admin_hub_menu_on', '0', 'bool', 'admin');
    $asAdmin(1, 'superadmin', 'fx1_superadmin');
    $old = $page('bookings.php', 'Tickets Register', 'bookings');
    check('admin_hub_menu_on = 0: the old tree is drawn', str_contains($old, 'class="side-group') && !str_contains($old, 'class="hm-row'));
    check('admin_hub_menu_on = 0: no tab strip, no bottom bar, no hub CSS', !str_contains($old, 'hm-tabs') && !str_contains($old, 'hm-bar'));
} finally {
    $restore();
    unset($_SESSION[ADMIN_SESSION_KEY]);
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL > 0 ? 1 : 0);
