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
    check('the payments badge the live poll needs is still on the page, once', substr_count($html, 'id="navBadgePayments"') === 1);
    check('the phone bottom bar: 4 rows + More', str_contains($html, 'class="hm-bar"') && substr_count(explode('class="hm-bar"', $html)[1], '<a ') >= 4);
    $sell = $page('quick-ticket.php', 'Quick Ticket', 'quick-ticket');
    check('the selling screen keeps its whole screen: no tab strip, no bottom bar', !str_contains($sell, 'class="hm-tabs"') && !str_contains($sell, 'class="hm-bar"'));

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
