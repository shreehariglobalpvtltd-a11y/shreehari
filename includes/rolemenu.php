<?php
/**
 * =====================================================================
 *  includes/rolemenu.php — ONE menu config for every role (26 Nov 2026, fx1)
 *
 *  Owner, 26 Nov 2026: "duniya dherai option, chalauna garo".
 *  admin_nav() carried ~105 links in 5 trunks. Nothing is deleted here:
 *  this file only says WHERE each existing page lives.
 *
 *    HUB   one row in the menu (8 in all: Home, Book ticket, Tickets,
 *          Buses & Chalani, People, Money, Reports, Settings).
 *    TAB   a group of pages inside a hub (shown as a strip under the page
 *          title, so "same job = one place with tabs").
 *    ROLE  which hubs a role gets as its main rows, in order (max 7; the
 *          super-admin 8). Every other hub the person has a page in sits
 *          under "＋ थप · More", and every page is still found by the
 *          menu search and still opens by its URL.
 *
 *  It never grants anything: build() only arranges the items admin_nav()
 *  already filtered by permission.
 *
 *  Switches (settings table, no deploy needed):
 *    admin_hub_menu_on   1 = this menu, 0 = the five-trunk menu as before
 *    admin_hub_tabs_on   1 = the tab strip under the page title
 *    admin_bottom_bar_on 1 = the phone bottom bar
 *    rolemenu_roles      JSON {role: [hub keys]} the owner ticked
 *                        (admin/menu-setup.php); empty = the defaults below
 *    rolemenu_hidden     comma list of page hrefs kept out of the menu
 *                        (search + URL still open them)
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class RoleMenu
{
    /** Hard ceiling of main rows for a role (the super-admin may have all eight). */
    public const MAX_MAIN = 7;

    /** Pages that keep their whole screen: no tab strip, no phone bottom bar. */
    private const FULL_SCREEN = ['quick-ticket.php', 'nepal-counter.php', 'home.php', 'scan.php'];

    /** Rarely used pages kept out of the menu unless the owner changes rolemenu_hidden. */
    private const DEFAULT_HIDDEN = 'places.php,agent-offline.php,/navigate.html';

    /**
     * The eight hubs. 'tabs' = tab key => [Nepali, English, [hrefs in order]].
     * An href is exactly the 'href' of an admin_nav() item.
     *
     * @return array<string, array{icon:string, ne:string, en:string, tabs:array<string, array{0:string,1:string,2:array<int,string>}>}>
     */
    public static function hubs(): array
    {
        return [
            'home' => ['icon' => 'dashboard', 'ne' => 'गृह', 'en' => 'Home', 'tabs' => [
                'home' => ['गृह', 'Home', ['home.php', 'index.php', 'hub.php']],
            ]],
            'book' => ['icon' => 'ticket-alt', 'ne' => 'टिकट काट्ने', 'en' => 'Book ticket', 'tabs' => [
                'sell' => ['टिकट काट्ने', 'Sell', ['quick-ticket.php', 'nepal-counter.php', '/index.php?counter=1#/']],
                'bulk' => ['धेरै टिकट', 'Bulk', ['bulk-tickets.php']],
            ]],
            'tickets' => ['icon' => 'ticket', 'ne' => 'टिकटहरू', 'en' => 'Tickets', 'tabs' => [
                'register' => ['रजिस्टर', 'Register', ['bookings.php', 'agent-passengers.php']],
                'requests' => ['अनुरोध', 'Requests', ['ticket-requests.php', 'reschedule.php', 'missed-bus.php', 'refunds.php']],
                'wa'       => ['WhatsApp टिकट', 'WhatsApp tickets', ['wa-queue.php']],
                'scan'     => ['स्क्यान', 'Scan', ['scan.php']],
                'paper'    => ['कागजी टिकट', 'Paper tickets', ['agent-offline.php']],
            ]],
            'buses' => ['icon' => 'bus', 'ne' => 'बस र चलानी', 'en' => 'Buses & Chalani', 'tabs' => [
                'today'   => ['आज', 'Today', ['bus-control.php', 'trip-dashboard.php', 'trips.php']],
                'chalani' => ['चलानी', 'Chalani', ['manifest.php', 'parcels.php']],   // parcels.php is in admin_nav() only while parcel_book_on = 1
                'routine' => ['रुटिन', 'Routine', ['schedule.php', 'calendar.php', '/routine.php']],
                'fleet'   => ['बस र सिट', 'Fleet & seats', ['buses.php', 'seatmap.php', 'extra-seats.php']],
                'map'     => ['लाइभ नक्सा', 'Live map', ['map.php', 'gps-center.php', 'gps-trackers.php', 'nav-reports.php', '/navigate.html']],
            ]],
            'people' => ['icon' => 'users', 'ne' => 'मान्छे', 'en' => 'People', 'tabs' => [
                'agents'    => ['एजेन्ट र स्टाफ', 'Agents & staff', ['people.php', 'agent-quick.php', 'agents.php', 'agent.php', 'team.php', 'agreements.php', 'team-invite.php', 'agent-ranking.php']],
                'drivers'   => ['चालक', 'Drivers', ['drivers.php', 'driver-app.php']],
                'customers' => ['ग्राहक', 'Customers', ['customers.php', 'enquiries.php', 'feedback.php']],
                'logins'    => ['लगइन र सुरक्षा', 'Logins & security', ['staff.php', 'permissions.php', 'sessions.php', 'login-reset.php', 'login-requests.php']],
                'office'    => ['कार्यालय', 'Office', ['office-ops.php', 'company.php']],
                'me'        => ['मेरो ID', 'My ID', ['/me/']],
            ]],
            'money' => ['icon' => 'card', 'ne' => 'पैसा', 'en' => 'Money', 'tabs' => [
                'verify'   => ['भुक्तानी जाँच', 'Verify payments', ['payments.php', 'payment-center.php']],
                'cash'     => ['नगद र सिफ्ट', 'Cash & shift', ['cash-book.php', 'shift.php', 'day-memo.php', 'desk-day.php', 'counters.php']],
                'agents'   => ['एजेन्टको पैसा', "Agents' money", ['settlements.php', 'agent-statement.php', 'agent-360.php', 'agent-aging.php', 'agent-sales.php']],
                'accounts' => ['लेखा', 'Accounts', ['accounting.php', 'ledger-check.php', 'reconcile.php', 'payroll.php']],
                'profit'   => ['नाफा', 'Profit', ['pnl.php', 'trip-expenses.php', 'ops-reports.php']],
            ]],
            'reports' => ['icon' => 'chart-up', 'ne' => 'रिपोर्ट', 'en' => 'Reports', 'tabs' => [
                'reports' => ['रिपोर्ट', 'Reports', ['ticket-statement.php', 'analytics.php']],
            ]],
            'settings' => ['icon' => 'cog', 'ne' => 'सेटिङ', 'en' => 'Settings', 'tabs' => [
                'easy'     => ['सजिलो सेटिङ', 'Easy settings', ['easy-settings.php', 'settings.php', 'owner-setup.php', 'menu-setup.php']],
                'places'   => ['कार्यालय र रुट', 'Offices & routes', ['locations.php', 'routes.php', 'places.php']],
                'offers'   => ['अफर', 'Offers', ['offers.php']],
                'messages' => ['सन्देश', 'Messages', ['wa-manager.php', 'wa-inbox.php', 'wa-outbox.php', 'wa-templates.php', 'messages-log.php', 'messenger-inbox.php', 'facebook.php', 'social-posts.php', 'fb-comments.php']],
                'ai'       => ['AI', 'AI', ['ai-center.php', 'ai-hub.php', 'ai-brain.php', 'ai-activity.php', 'ai-local.php', 'ai-copilot.php']],
                'look'     => ['रङ र थिम', 'Look', ['themes.php']],
                'system'   => ['सिस्टम', 'System', ['activity-log.php', 'backup.php', 'health.php']],
            ]],
        ];
    }

    /**
     * The main rows of each role, in order (the owner's blueprint, 26 Nov 2026).
     * A role not listed here gets every hub it has a page in, in hub order.
     *
     * @return array<string, array<int, string>>
     */
    public static function defaultRoles(): array
    {
        return [
            'superadmin' => ['home', 'book', 'tickets', 'buses', 'money', 'people', 'reports', 'settings'],
            'manager'    => ['home', 'book', 'tickets', 'buses', 'money', 'people', 'reports'],
            'operations' => ['home', 'buses', 'tickets', 'people'],
            'accountant' => ['home', 'money', 'reports', 'tickets'],
            'support'    => ['home', 'tickets', 'people', 'settings'],
            'counter'    => ['book', 'tickets', 'buses', 'money', 'home'],
            'agent'      => ['book', 'tickets', 'money', 'buses', 'people', 'home'],
        ];
    }

    /**
     * Which page a role opens first inside a hub (else the hub's first permitted page).
     *
     * @return array<string, array<string, string>>
     */
    private static function firstPage(): array
    {
        return [
            'operations' => ['people' => 'drivers.php', 'tickets' => 'bookings.php'],
            'accountant' => ['money' => 'payments.php'],
            'support'    => ['tickets' => 'ticket-requests.php', 'people' => 'customers.php', 'settings' => 'wa-inbox.php'],
            'counter'    => ['buses' => 'manifest.php', 'money' => 'cash-book.php'],
            'agent'      => ['buses' => 'map.php', 'money' => 'settlements.php', 'people' => '/me/'],
        ];
    }

    public static function on(): bool
    {
        return self::flag('admin_hub_menu_on', true);
    }

    public static function tabsOn(): bool
    {
        return self::on() && self::flag('admin_hub_tabs_on', true);
    }

    public static function bottomBarOn(): bool
    {
        return self::on() && self::flag('admin_bottom_bar_on', true);
    }

    private static function flag(string $key, bool $default): bool
    {
        try {
            return Settings::getBool($key, $default);
        } catch (Throwable $e) {
            return $default;   // a settings hiccup never takes the menu away
        }
    }

    /** May this page carry the tab strip / the phone bottom bar? */
    public static function fullScreen(string $href): bool
    {
        return in_array($href, self::FULL_SCREEN, true);
    }

    /**
     * href => [hub key, tab key] for every page the config knows.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function index(): array
    {
        static $idx = null;
        if ($idx === null) {
            $idx = [];
            foreach (self::hubs() as $hk => $hub) {
                foreach ($hub['tabs'] as $tk => $tab) {
                    foreach ($tab[2] as $href) {
                        $idx[$href] ??= [$hk, $tk];
                    }
                }
            }
        }
        return $idx;
    }

    /**
     * A page the config does not list (a new page someone adds later) still
     * gets a home: the hub that matches its old trunk, tab "more".
     */
    public static function fallbackHub(string $section): string
    {
        if (preg_match('/(\d)\./', $section, $m) === 1) {
            return ['1' => 'tickets', '2' => 'people', '3' => 'money', '4' => 'buses', '5' => 'settings'][$m[1]] ?? 'settings';
        }
        return 'home';
    }

    /** @return array<int, string> hrefs kept out of the menu (still open by search / URL) */
    public static function hidden(): array
    {
        try {
            $raw = Settings::getString('rolemenu_hidden', self::DEFAULT_HIDDEN);
        } catch (Throwable $e) {
            $raw = self::DEFAULT_HIDDEN;
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn(string $s): bool => $s !== ''));
    }

    /**
     * The owner's own ticks (admin/menu-setup.php), validated: known hub keys
     * only, no doubles, at most MAX_MAIN (8 for the super-admin).
     *
     * @return array<string, array<int, string>>
     */
    public static function roleOverrides(): array
    {
        try {
            $raw = Settings::getString('rolemenu_roles', '');
        } catch (Throwable $e) {
            $raw = '';
        }
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        $known = array_keys(self::hubs());
        $out = [];
        foreach ($data as $role => $keys) {
            if (!is_string($role) || !is_array($keys)) {
                continue;
            }
            $keys = array_values(array_unique(array_filter($keys, static fn($k): bool => is_string($k) && in_array($k, $known, true))));
            if ($keys !== []) {
                $out[$role] = array_slice($keys, 0, $role === 'superadmin' ? count($known) : self::MAX_MAIN);
            }
        }
        return $out;
    }

    /** @return array<int, string> the main hub keys of a role, in order */
    public static function roleHubs(string $role): array
    {
        $over = self::roleOverrides();
        if (isset($over[$role])) {
            return $over[$role];
        }
        return self::defaultFor($role);
    }

    /** @return array<int, string> the rows a role gets before the owner ticks anything */
    public static function defaultFor(string $role): array
    {
        $def = self::defaultRoles();
        return $def[$role] ?? array_slice(array_keys(self::hubs()), 0, self::MAX_MAIN);
    }

    /**
     * Arrange the permission-filtered menu items into hubs for one role.
     *
     * @param array<int, array<string, mixed>> $items admin_nav() (already filtered by permission)
     * @param array{np_desk?:bool, hidden?:array<int,string>, role_hubs?:array<int,string>} $opt
     * @return array{
     *   main: array<int, array<string, mixed>>,
     *   more: array<int, array<string, mixed>>,
     *   pages: array<int, array<string, mixed>>
     * }
     *   main / more = hubs: key, icon, ne, en, href (the page the row opens), tabs
     *                 (tab key => ['ne','en','items'=>[nav items]]), count
     *   pages       = every permitted page, hidden ones too, with hub / tab names (the menu search)
     */
    public static function build(array $items, string $role, array $opt = []): array
    {
        $hubs    = self::hubs();
        $idx     = self::index();
        $hidden  = array_flip($opt['hidden'] ?? self::hidden());
        $npDesk  = !empty($opt['np_desk']);
        $first   = self::firstPage()[$role] ?? [];
        $byHub   = [];
        $pages   = [];

        foreach ($items as $it) {
            $href = (string) ($it['href'] ?? '');
            if ($href === '') {
                continue;
            }
            [$hk, $tk] = $idx[$href] ?? [self::fallbackHub((string) ($it['section'] ?? '')), 'more'];
            $pages[] = $it + ['hub' => $hk, 'tab' => $tk,
                'hub_label' => $hubs[$hk]['ne'] . ' · ' . $hubs[$hk]['en'],
                'tab_label' => isset($hubs[$hk]['tabs'][$tk]) ? $hubs[$hk]['tabs'][$tk][0] . ' · ' . $hubs[$hk]['tabs'][$tk][1] : ''];
            if (isset($hidden[$href])) {
                continue;
            }
            $byHub[$hk][$tk][$href] = $it;
        }

        $made = [];
        foreach ($hubs as $hk => $hub) {
            if (empty($byHub[$hk])) {
                continue;
            }
            $tabs = [];
            foreach ($hub['tabs'] as $tk => $tab) {       // config order, never the order pages happened to arrive in
                if (empty($byHub[$hk][$tk])) {
                    continue;
                }
                $ordered = [];
                foreach ($tab[2] as $href) {
                    if (isset($byHub[$hk][$tk][$href])) {
                        $ordered[] = $byHub[$hk][$tk][$href];
                    }
                }
                $tabs[$tk] = ['ne' => $tab[0], 'en' => $tab[1], 'items' => $ordered];
            }
            if (!empty($byHub[$hk]['more'])) {
                $tabs['more'] = ['ne' => 'थप', 'en' => 'More', 'items' => array_values($byHub[$hk]['more'])];
            }
            if ($tabs === []) {
                continue;
            }
            $all = [];
            foreach ($tabs as $t) {
                foreach ($t['items'] as $i) {
                    $all[(string) $i['href']] = true;
                }
            }
            // the page the row opens: the role's own first page, a Nepal desk's counter home, else the first page
            $target = (string) reset($tabs)['items'][0]['href'];
            if ($hk === 'book' && $npDesk && isset($all['nepal-counter.php'])) {
                $target = 'nepal-counter.php';
            } elseif (isset($first[$hk], $all[$first[$hk]])) {
                $target = $first[$hk];
            }
            $made[$hk] = ['key' => $hk, 'icon' => $hub['icon'], 'ne' => $hub['ne'], 'en' => $hub['en'],
                'href' => $target, 'tabs' => $tabs, 'count' => count($all)];
        }

        $want = $opt['role_hubs'] ?? self::roleHubs($role);
        $cap  = $role === 'superadmin' ? count($hubs) : self::MAX_MAIN;
        $main = [];
        foreach ($want as $hk) {
            if (isset($made[$hk]) && count($main) < $cap) {
                $main[$hk] = $made[$hk];
            }
        }
        if ($main === []) {                                 // a role whose list matches nothing still gets a menu
            $main = array_slice($made, 0, $cap, true);
        }
        $more = array_diff_key($made, $main);

        return ['main' => array_values($main), 'more' => array_values($more), 'pages' => $pages];
    }

    /**
     * Where the current page sits: its hub, its tab, and the hub's tabs.
     *
     * @param array{main:array, more:array, pages:array} $built build()
     * @return array{hub:array<string,mixed>, tab:string}|null
     */
    public static function locate(array $built, string $href): ?array
    {
        foreach (array_merge($built['main'], $built['more']) as $hub) {
            foreach ($hub['tabs'] as $tk => $tab) {
                foreach ($tab['items'] as $it) {
                    if ((string) $it['href'] === $href) {
                        return ['hub' => $hub, 'tab' => (string) $tk];
                    }
                }
            }
        }
        // a hidden page still shows its hub's tabs (it is one of them, only not drawn in the menu)
        foreach ($built['pages'] as $p) {
            if ((string) $p['href'] === $href) {
                foreach (array_merge($built['main'], $built['more']) as $hub) {
                    if ($hub['key'] === $p['hub']) {
                        return ['hub' => $hub, 'tab' => (string) $p['tab']];
                    }
                }
            }
        }
        return null;
    }

    /** "/admin/x.php" for a menu href ("x.php" or a root-relative "/me/"). */
    public static function url(string $href): string
    {
        return str_starts_with($href, '/') ? $href : '/admin/' . $href;
    }
}
