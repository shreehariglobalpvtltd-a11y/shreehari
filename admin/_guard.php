<?php
/**
 * =====================================================================
 *  admin/_guard.php — the front door for every admin page.
 *
 *  Boots the app, requires a signed-in staff member (optionally holding
 *  a named permission) and provides the shared branded chrome via
 *  admin_header() / admin_footer(). Include it first on every page:
 *
 *      require __DIR__ . '/_guard.php';
 *      $admin = admin_boot('payments.view');
 * =====================================================================
 */

declare(strict_types=1);

// Guarded like bootstrap.php's own define — an entry point that already
// booted the app (CLI tooling, tests) must not trip a redefinition warning.
if (!defined('SHG_APP')) {
    define('SHG_APP', true);
}
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/tripnotify.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/reportpdf.php';

/**
 * Require a signed-in admin (optionally with a permission) and return them.
 */
function admin_boot(string $permission = ''): array
{
    return Auth::requireAdmin($permission);
}

/**
 * The navigation, filtered to what this admin may see.
 *
 * Phase 3 (Control Center): every item now carries a `section` so the
 * sidebar renders grouped headers rather than one flat 20-line strip
 * where "Agent Sales", "Agent Ranking" and "Analytics" all read the
 * same. Order within a section is preserved. Agent view is untouched
 * — a counter agent still sees a lean 3-4 item strip because most
 * items simply fail the permission filter for them.
 *
 * Sections used:
 *   OPERATIONS — the live counter: dashboard, trips, seat map, manifest, fleet, routes
 *   SALES      — bookings, payments, refunds, customer intake
 *   PEOPLE     — agent surfaces + staff management
 *   INSIGHTS   — reports / analytics / ranking
 *   SYSTEM     — scan, messages, activity-log, settings
 *
 * @return array<int, array{href:string, icon:string, label:string, perm:string, section:string}>
 */
function admin_nav(): array
{
    $agentView = Auth::isCounterAgent();

    // 5 Sep 2026: the sidebar is grouped the way the owner names the work —
    // Tickets · Agents · Customers · Buses · Routes · Payments · Reports ·
    // Settings · Map. The first item of every group is its register; the
    // rest are the working screens that hang off it. Nothing was removed,
    // only re-homed, so every old bookmark still resolves.
    $all = [
        ['href' => 'index.php',        'icon' => 'dashboard', 'label' => 'Dashboard',        'perm' => 'dashboard.view', 'section' => ''],

        // Tickets — everything that is, or becomes, a ticket.
        ['href' => 'bookings.php',     'icon' => 'ticket',    'label' => $agentView ? 'My Tickets' : 'Tickets', 'perm' => 'bookings.view', 'section' => 'Tickets'],
        // 3 Sep 2026: "+ New Booking" opens the customer app in COUNTER MODE —
        // the same seat map + checkout customers use, with the staff session
        // recognised (see assets/js/14-counter.js). Root-relative so it stays
        // on the host the staff signed in on (.in or the .network door).
        // new-booking.php now redirects there (?legacy=1 keeps the old form).
        ['href' => '/index.php?counter=1#/', 'icon' => 'plus', 'label' => '+ New Booking', 'perm' => 'bookings.view', 'section' => 'Tickets'],
        // 6 Sep 2026: ⚡ Quick Ticket — name + mobile → the server picks the
        // bus, pickup, seat and fare → confirmed sale → PNG ticket → WhatsApp.
        // Flagged `hot` so every desk finds it at a glance (see $renderLink).
        ['href' => 'quick-ticket.php', 'icon' => 'ticket-alt', 'label' => '🤖 QuickBot Ticket', 'perm' => 'bookings.view', 'section' => 'Tickets', 'hot' => true],
        ['href' => 'shift.php',        'icon' => 'coins',      'label' => 'Shift & Cash',      'perm' => 'bookings.view', 'section' => 'Tickets'],
        ['href' => 'offline-requests.php', 'icon' => 'notepad', 'label' => 'Offline Desk',     'perm' => 'bookings.view', 'section' => 'Tickets'],
        ['href' => 'seatmap.php',      'icon' => 'seat',      'label' => 'Seat Map',         'perm' => 'schedules.view', 'section' => 'Tickets'],
        ['href' => 'manifest.php',     'icon' => 'clipboard', 'label' => 'Passenger Manifest', 'perm' => 'bookings.view', 'section' => 'Tickets'],
        // 17 Sep 2026: every departure document on one hub (PDF / PNG / WhatsApp).
        ['href' => 'chalan.php',       'icon' => 'doc',       'label' => 'Bus Chalan',         'perm' => 'bookings.view', 'section' => 'Tickets'],
        ['href' => 'passengers.php',   'icon' => 'id-card',   'label' => 'Passengers',         'perm' => 'bookings.view', 'section' => 'Tickets'],
        ['href' => 'scan.php',         'icon' => 'scan',    'label' => 'Scan Ticket',      'perm' => 'tickets.scan',   'section' => 'Tickets'],

        // Agents — the register first (office roles only — the page itself
        // refuses a counter agent's session), then the working screens.
        // 5 Sep 2026: for OFFICE viewers these gate on commissions.view, not
        // bookings.view — an agent still sees their own pages, but the new
        // counter role (and support) must not browse other sellers' money.
        // The pages enforce the same rule server-side; this only hides nav.
        ['href' => 'agents.php',       'icon' => 'users',  'label' => 'Agents',           'perm' => 'commissions.view', 'section' => 'Agents'],
        ['href' => 'agent.php',        'icon' => 'wallet', 'label' => $agentView ? 'My Dashboard' : 'Agent Panel',    'perm' => $agentView ? 'bookings.view' : 'commissions.view', 'section' => 'Agents'],
        ['href' => 'agent-sales.php',  'icon' => 'chart-up',        'label' => $agentView ? 'My Sales' : 'Agent Sales',        'perm' => $agentView ? 'bookings.view' : 'commissions.view', 'section' => 'Agents'],
        ['href' => 'agent-passengers.php','icon' => 'id-card','label' => $agentView ? 'My Passengers' : 'Agent Passengers','perm' => $agentView ? 'bookings.view' : 'commissions.view', 'section' => 'Agents'],
        ['href' => 'agent-offline.php','icon' => 'notepad',   'label' => 'Paper Tickets',    'perm' => $agentView ? 'bookings.view' : 'commissions.view',  'section' => 'Agents'],
        ['href' => 'agent-ranking.php','icon' => 'trophy',    'label' => 'Agent Ranking',    'perm' => 'dashboard.view', 'section' => 'Agents'],
        ['href' => 'staff.php',        'icon' => 'user-cog',  'label' => 'Staff & Approvals', 'perm' => 'staff.manage',  'section' => 'Agents'],

        // Customers — leads and the people who travelled. An agent's own
        // bookings are scoped by sold_by_admin_id, but an unclaimed lead has
        // no seller so it belongs to the office.
        ['href' => 'customers.php',    'icon' => 'user',     'label' => 'Customers',        'perm' => 'customers.view', 'section' => 'Customers'],
        ['href' => 'enquiries.php',    'icon' => 'mail',      'label' => 'Enquiries',        'perm' => 'customers.view', 'section' => 'Customers'],

        // Buses — fleet, the day-by-day schedule and the crew.
        ['href' => 'calendar.php',     'icon' => 'calendar',  'label' => 'Bus Calendar',     'perm' => 'schedules.manage','section' => 'Buses'],
        ['href' => 'buses.php',        'icon' => 'bus',       'label' => 'Bus Fleet',        'perm' => 'schedules.view', 'section' => 'Buses'],
        ['href' => 'schedule.php',     'icon' => 'calendar-plus','label' => 'Schedule Manager','perm' => 'schedules.manage','section' => 'Buses'],
        ['href' => 'trips.php',        'icon' => 'clock',     'label' => 'Trips Board',      'perm' => 'schedules.view', 'section' => 'Buses'],
        ['href' => 'trip-dashboard.php','icon' => 'grid', 'label' => 'Date View',        'perm' => 'schedules.view', 'section' => 'Buses'],
        ['href' => 'drivers.php',      'icon' => 'user-solo', 'label' => 'Drivers & Crew',   'perm' => 'drivers.view',   'section' => 'Buses'],

        // Routes
        ['href' => 'routes.php',       'icon' => 'route',      'label' => 'Routes',           'perm' => 'routes.view',    'section' => 'Routes'],

        // Payments — money in, money back.
        ['href' => 'payments.php',     'icon' => 'card',      'label' => 'Verify Payments',  'perm' => 'payments.view',  'section' => 'Payments'],
        ['href' => 'refunds.php',      'icon' => 'refund',    'label' => 'Refunds',          'perm' => 'refunds.view',   'section' => 'Payments'],

        // Reports
        ['href' => 'analytics.php',    'icon' => 'chart',  'label' => 'Analytics',        'perm' => 'dashboard.view', 'section' => 'Reports'],
        ['href' => 'feedback.php',     'icon' => 'star',      'label' => 'Ratings',          'perm' => 'dashboard.view', 'section' => 'Reports'],
        ['href' => 'accounting.php',   'icon' => 'banknote',    'label' => 'Accounting',       'perm' => 'payments.view',  'section' => 'Reports'],
        ['href' => 'messages-log.php', 'icon' => 'msg',       'label' => 'Message Log',      'perm' => 'dashboard.view', 'section' => 'Reports'],

        // Settings
        ['href' => 'settings.php',     'icon' => 'cog',       'label' => 'Settings',         'perm' => 'dashboard.view', 'section' => 'Settings'],
        ['href' => 'activity-log.php', 'icon' => 'shield',    'label' => 'Activity & Security','perm'=> 'dashboard.view', 'section' => 'Settings'],
        ['href' => 'health.php',       'icon' => 'alert',     'label' => 'System Health',    'perm' => 'dashboard.view', 'section' => 'Settings'],

        // Map — routes, stops, head office and the driver's live position (5 Sep 2026).
        ['href' => 'map.php',          'icon' => 'map-pin',      'label' => 'Live Map',         'perm' => 'schedules.view', 'section' => 'Map'],
    ];

    return array_values(array_filter($all, static fn(array $i): bool => $i['perm'] === '' || Auth::can($i['perm'])));
}

/**
 * Render the page head, top bar and sidebar. Call once at the top.
 */
function admin_header(string $title, string $active = ''): void
{
    $admin = Auth::admin() ?? [];
    $name  = Security::e($admin['full_name'] ?: ($admin['username'] ?? 'Admin'));
    $role  = Security::e(ucfirst((string) ($admin['role'] ?? '')));
    // Panel links are ROOT-RELATIVE so the admin stays on whichever host they
    // signed in on (.in or the .network staff door). Only the "View site"
    // link below points at APP_URL — that one really means the public website.
    $base  = '';
    $company = Settings::getString('company_name', APP_NAME);

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . Security::e($title) . ' · ' . Security::e($company) . ' Admin</title>';
    echo '<link rel="icon" type="image/png" href="/assets/img/favicon-32.png">';
    echo '<link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">';
    // Design system v2 (17 Sep 2026): Inter for the UI (CSP already allows
    // fonts.googleapis.com / fonts.gstatic.com), swap so text never blocks;
    // the system stack in --f-ui covers offline desks and Devanagari.
    echo '<meta name="theme-color" content="#12264E">';
    // 17 Sep 2026: the CSRF token for the panel's JSON tools (WhatsApp sends).
    echo '<meta name="csrf" content="' . Security::e(Security::csrfToken()) . '">';
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
    echo '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">';
    echo '<style>' . admin_css() . '</style>';
    // Apply saved/system theme before <body> paints (no flash), + the toggle.
    echo '<script>(function(){try{var t=localStorage.getItem("shg_admin_theme");'
       . 'if(t!=="dark"&&t!=="light")t=(window.matchMedia&&matchMedia("(prefers-color-scheme:dark)").matches)?"dark":"light";'
       . 'document.documentElement.setAttribute("data-theme",t);}catch(e){}})();'
       . 'function shgTheme(){try{var d=document.documentElement,'
       . 'n=d.getAttribute("data-theme")==="dark"?"light":"dark";'
       . 'd.setAttribute("data-theme",n);localStorage.setItem("shg_admin_theme",n);}catch(e){}}</script>';
    echo '</head><body>';

    // One admin-wide SVG icon sprite — sidebar, top-bar and brand row all
    // draw from this. Kept here so every admin page inherits it without
    // shipping a separate stylesheet or an extra HTTP request.
    echo admin_sprite();

    // Top bar
    echo '<header class="tb">';
    echo '<button class="menu" onclick="document.body.classList.toggle(\'nav-open\')" aria-label="Menu">☰</button>';
    echo '<a class="brand" href="' . $base . '/admin/index.php">'
       . '<img class="brand-logo" src="/assets/img/logo.png" alt="" width="26" height="26"> '
       . Security::e($company) . ' <span>Admin</span></a>';

    // Phase 3 — Global search. Sits between the brand and the "who" strip
    // on wide viewports, drops below the top bar on small ones (see the
    // .tb-search rules in admin_css). Autosuggest is opt-in per keystroke
    // via /admin/api/search.php; the form itself falls back to a full-page
    // search results screen when the input is submitted with Enter.
    // Guarded on bookings.view — agents can search too, they just see
    // scoped results (bookings/passengers/tickets they themselves sold).
    if (Auth::can('bookings.view')) {
        $q0 = Security::e((string) ($_GET['q'] ?? ''));
        echo '<form class="tb-search" role="search" method="get" action="' . $base . '/admin/search.php">'
           . '<svg class="a-ic tb-ic"><use href="#a-search"/></svg>'
           . '<input type="search" name="q" value="' . $q0 . '" placeholder="Search PNR · phone · passenger · seat · bus · route · SHG code" autocomplete="off">'
           . '<div class="tb-sug" id="tbSuggest" hidden></div>'
           . '</form>';
    }

    // The change-password form asks for the CURRENT password, so it is only
    // offered to someone who signed in WITH one. That now includes agents
    // (email + username + password, 4 Sep 2026); it still excludes the older
    // code + mobile + WhatsApp-OTP door, where the agent never typed a
    // password and the form would deadlock them. Menu hygiene, not the gate —
    // the page itself remains open to whoever is signed in.
    // ...but if the account is under a FORCED change, the link must show even
    // on an OTP session: requireAdmin() is bouncing them to that page anyway,
    // and change-password.php drops the current-password field for exactly
    // this case, so the form no longer deadlocks them.
    $viaOtp = !empty($admin['via_otp']) && empty($admin['must_change_pw']);
    $pwLink = $viaOtp ? ''
        : ' <a href="' . $base . '/admin/change-password.php" title="Change your password" aria-label="Change password"><svg class="a-ic"><use href="#a-key"/></svg></a>';
    // Initials avatar (v2): two letters from the display name, orange gradient.
    $initials = '';
    foreach (preg_split('/\s+/', trim((string) ($admin['full_name'] ?: ($admin['username'] ?? 'A')))) ?: [] as $part) {
        if ($part !== '' && strlen($initials) < 2) { $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
    }
    echo '<div class="who">'
       . '<span class="tb-av" aria-hidden="true">' . Security::e($initials ?: 'A') . '</span>'
       . '<span class="tb-name">' . $name . '</span> <em>' . $role . '</em>'
       . '<button type="button" class="theme-tog" onclick="shgTheme()" aria-label="Toggle dark mode" title="Dark / light mode"><svg class="a-ic"><use href="#a-theme"/></svg></button>'
       . $pwLink
       . ' <a href="' . $base . '/admin/logout.php" title="Sign out" aria-label="Sign out"><svg class="a-ic"><use href="#a-logout"/></svg></a></div>';
    echo '</header>';

    // Sidebar — Phase 3 renders items grouped by their `section` key with
    // a subtle uppercase divider between groups. Section headers only
    // appear when there are ≥2 sections visible to this admin, so a
    // counter agent whose whole nav is one strip still sees no headers.
    echo '<nav class="side">';
    $items = admin_nav();

    // Render one link row. Kept as a closure so the flat (counter-agent) and
    // grouped (office) branches emit identical markup — same href, icon, label
    // and the payments badge span (#navBadgePayments) that the live poll needs.
    $renderLink = static function (array $item) use ($active, $base): void {
        $cls = [];
        if ($active !== '' && !str_starts_with($item['href'], '/') && str_contains($item['href'], $active)) {
            $cls[] = 'on';
        }
        if (!empty($item['hot'])) {
            $cls[] = 'hot';      // highlighted entry (⚡ Quick Ticket, 6 Sep 2026)
        }
        $on    = $cls !== [] ? ' class="' . implode(' ', $cls) . '"' : '';
        $badge = $item['href'] === 'payments.php'
            ? '<span class="navbadge" id="navBadgePayments" style="display:none"></span>'
            : '';
        // A root-relative href (counter mode) is used as-is; everything else lives in /admin/.
        $url = str_starts_with($item['href'], '/') ? $base . $item['href'] : $base . '/admin/' . $item['href'];
        echo '<a' . $on . ' href="' . $url . '">'
           . '<span><svg class="a-ic"><use href="#a-' . $item['icon'] . '"/></svg></span>'
           . Security::e($item['label']) . $badge . '</a>';
    };

    // Group items by section, preserving first-seen order.
    $groups = [];
    foreach ($items as $item) {
        $groups[(string) ($item['section'] ?? '')][] = $item;
    }
    $namedSections = array_filter(array_keys($groups), static fn(string $s): bool => $s !== '');
    // Only office admins see >=2 sections; a counter agent's whole nav is one
    // strip, so it renders flat (no collapsing) exactly as before.
    // Counter agents always get the flat strip (3 Sep 2026): on a phone their
    // "+ New Booking" must never sit under a collapsed group header.
    $showGroups = count($namedSections) >= 2 && !Auth::isCounterAgent();

    if (!$showGroups) {
        foreach ($items as $item) { $renderLink($item); }
    } else {
        // Which section holds the current page? That group starts expanded so
        // the active item is always visible; the rest collapse into tidy
        // headers. The client then restores the admin's remembered open/closed
        // set from localStorage on top of this (see admin_nav_js).
        $activeSec = '';
        foreach ($items as $item) {
            if ($active !== '' && str_contains($item['href'], $active)) { $activeSec = (string) ($item['section'] ?? ''); break; }
        }
        foreach ($groups as $sec => $groupItems) {
            if ($sec === '') { foreach ($groupItems as $item) { $renderLink($item); } continue; }
            $open = ($sec === $activeSec) ? ' open' : '';
            echo '<div class="side-group' . $open . '" data-sec="' . Security::e($sec) . '">';
            echo '<button type="button" class="side-sec side-toggle" onclick="shgNavToggle(this)" aria-label="Toggle ' . Security::e($sec) . '">'
               . Security::e($sec)
               . '<svg class="a-ic nav-caret"><use href="#a-chevron"/></svg></button>';
            echo '<div class="side-items">';
            foreach ($groupItems as $item) { $renderLink($item); }
            echo '</div></div>';
        }
    }
    // View-site stays outside the collapsible groups so it is always reachable.
    echo '<a class="side-site" href="' . rtrim(APP_URL, '/') . '/" target="_blank" rel="noopener">'
       . '<span><svg class="a-ic"><use href="#a-globe"/></svg></span>View site</a>';
    echo '</nav>';
    if ($showGroups) { echo '<script>' . admin_nav_js() . '</script>'; }

    echo admin_mobile_nav($items, $active);

    echo '<main class="wrap"><h1>' . Security::e($title) . '</h1>';
}

/**
 * Phone bottom navigation (v2, 17 Sep 2026). Five thumb-reach slots built
 * from the same permission-filtered nav: Home · Tickets · (+ New, orange
 * FAB) · Sales-or-Payments · Menu (opens the sidebar). Rendered for every
 * role and hidden by CSS above 820px, so desktops never see it. Purely
 * additive — the sidebar stays the full menu.
 */
function admin_mobile_nav(array $items, string $active = ''): string
{
    $find = static function (string $href) use ($items): ?array {
        foreach ($items as $it) { if ($it['href'] === $href) { return $it; } }
        return null;
    };
    /* 19 Sep 2026 (owner: "colourful bottom buttons; Quick Ticket and the map
       in agent mode"). Every slot keeps its own colour, always - a thumb finds
       green = tickets, blue = map without reading. The orange button is the
       fastest sale (QuickBot, name + mobile -> ticket); the seat-map booking
       stays one tap away inside it and in the menu. A selling desk (agent,
       counter - no dashboard.view) gets the Map in slot four, where its
       passengers by pickup and the live bus are; the office keeps money. */
    $home    = Auth::isCounterAgent() ? $find('agent.php') : $find('index.php');
    $tickets = $find('bookings.php');
    $new     = $find('quick-ticket.php') ?? $find('/index.php?counter=1#/');
    $desk    = !Auth::can('dashboard.view');
    $fourth  = $desk
        ? ($find('map.php') ?? $find('agent-sales.php'))
        : ($find('payments.php') ?? $find('agent-sales.php') ?? $find('manifest.php'));
    $slots   = [];
    $slot = static function (?array $it, string $label, string $icon, string $tone, string $active, bool $fab = false): string {
        if ($it === null) { return '<a class="mn-empty" aria-hidden="true"></a>'; }
        $url = str_starts_with($it['href'], '/') ? $it['href'] : '/admin/' . $it['href'];
        $on  = ($active !== '' && !str_starts_with($it['href'], '/') && str_contains($it['href'], $active)) ? ' on' : '';
        if ($fab) {
            return '<a class="fab" href="' . $url . '" aria-label="' . Security::e($label) . '"><span class="mn-fab"><svg class="a-ic"><use href="#a-' . $icon . '"/></svg></span><span class="mn-fab-l">' . Security::e($label) . '</span></a>';
        }
        return '<a class="mn-' . $tone . $on . '" href="' . $url . '"><svg class="a-ic"><use href="#a-' . $icon . '"/></svg>' . Security::e($label) . '</a>';
    };
    $fourthMeta = match ($fourth['href'] ?? '') {
        'map.php'      => ['Map', 'map-pin', 'map'],
        'payments.php' => ['Payments', 'card', 'money'],
        'manifest.php' => ['Manifest', 'clipboard', 'money'],
        default        => ['Sales', 'chart-up', 'money'],
    };
    $slots[] = $slot($home, 'Home', 'home', 'home', $active);
    $slots[] = $slot($tickets, 'Tickets', 'ticket', 'tix', $active);
    $slots[] = $new !== null ? $slot($new, 'Ticket', $new['href'] === 'quick-ticket.php' ? 'bolt' : 'plus-plain', 'fab', $active, true) : '<a class="mn-empty" aria-hidden="true"></a>';
    $slots[] = $slot($fourth, $fourthMeta[0], $fourthMeta[1], $fourthMeta[2], $active);
    $slots[] = '<a class="mn-menu" href="#" onclick="document.body.classList.toggle(\'nav-open\');return false" aria-label="Menu"><svg class="a-ic"><use href="#a-list"/></svg>Menu</a>';
    return '<nav class="mnav" aria-label="Quick navigation">' . implode('', $slots) . '</nav>'
         . '<script>document.body.classList.add("has-mnav")</script>';
}

function admin_footer(): void
{
    echo '</main>';
    echo '<div class="scrim" onclick="document.body.classList.remove(\'nav-open\')"></div>';

    // Live pending-payments badge + chime, so staff don't have to keep
    // hitting refresh. Only for roles that can actually act on payments.
    // 3 Sep 2026: bookings.view too, so a counter agent's "My Bookings" gets
    // the live new-bookings pill (pending-count.php scopes the answer and
    // returns pending=0 for roles that cannot act on payments).
    if (Auth::can('payments.view') || Auth::can('bookings.view')) {
        echo '<script>' . admin_poll_js() . '</script>';
    }

    // Phase 3: global search autosuggest — only rendered when the search
    // form itself is on the page (bookings.view). Debounces per keystroke,
    // asks admin/api/search.php for the top 8 hits across bookings /
    // passengers / tickets / buses / routes, renders a keyboard-navigable
    // dropdown. Falls back to the full-page /admin/search.php on Enter.
    if (Auth::can('bookings.view')) {
        echo '<script>' . admin_search_js() . '</script>';
        // 17 Sep 2026: one-click WhatsApp sheet (admin_wa_button / admin/api/wa-send.php).
        echo '<script>' . admin_wa_js() . '</script>';
    }

    // 3 Sep 2026: phone-friendly tables everywhere. On a narrow screen every
    // plain list table gets the card-table treatment automatically (labels
    // read from its own header row), so a page never needs hand-written
    // data-label attributes to be usable on a phone. Tables that carry their
    // own mobile layout (.sm-table) or opt out (.no-card), have no thead, or
    // whose rows do not line up with the header are left exactly as they are.
    echo '<script>' . admin_cards_js() . '</script>';
    // 17 Sep 2026: every flash gets a dismiss ×; success flashes fade after 7 s
    // (errors and warnings stay until dismissed — the desk must read them).
    echo '<script>(function(){document.querySelectorAll(".flash").forEach(function(f){'
       . 'if(f.querySelector(".flash-x"))return;var x=document.createElement("button");x.type="button";x.className="flash-x";x.setAttribute("aria-label","Dismiss");x.textContent="\u00d7";'
       . 'x.onclick=function(){f.remove()};f.appendChild(x);'
       . 'if(f.classList.contains("ok")){setTimeout(function(){f.style.transition="opacity .4s";f.style.opacity="0";setTimeout(function(){f.remove()},420)},7000)}'
       . '})})();</script>';
    // 5 Sep 2026: Excel-style list tables (Customers / Agents): search,
    // column filters, click-to-sort, sticky header, detail + edit rows.
    echo admin_datatable_assets();
    echo '</body></html>';
}

/**
 * Style + behaviour for `table.dt` list pages. A table opts in with
 * class="dt no-card" (no-card keeps the phone card-table treatment off:
 * these are spreadsheet views that scroll sideways with a sticky first
 * column). Controls live in an element named by data-controls: a .dt-q
 * search box, any [data-dt-filter="attr"] select / date input (matched
 * against the row's data-<attr>), and a .dt-count badge. Headers sort on
 * click (data-type="num" for numbers, data-nosort to skip); a cell's
 * data-sort wins over its text. Buttons with data-dt-toggle="<rowId>" show
 * or hide the matching detail / edit row.
 */
function admin_datatable_assets(): string
{
    return <<<'HTML'
<style>
.dt-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 10px}
.dt-bar input,.dt-bar select{padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font-size:14px;min-height:40px}
.dt-bar .dt-q{flex:1 1 220px;min-width:0}
.dt-bar .dt-count{margin-left:auto;font-size:12px;color:var(--mut);font-variant-numeric:tabular-nums;white-space:nowrap}
/* ONE scroller (19 Sep 2026, owner: 'scroll down, then scroll sideways'). The box used to be 78vh tall on every table AND every table was forced 900px wide, so a four-column list on a laptop got a sideways scrollbar hidden at the bottom of an inner vertical scroller. Now: the page scrolls; a table is only as wide as its columns; only a LONG register (.dt-tall, set below from the row count) keeps a box - sized to the window, so its sticky header and its sideways scrollbar are on screen together. */
.dt-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:12px;background:var(--card);-webkit-overflow-scrolling:touch}
.dt-wrap.dt-tall{overflow:auto;max-height:calc(100vh - 96px);scroll-margin-top:72px}
table.dt{width:100%;border-collapse:separate;border-spacing:0;font-size:13.5px}
table.dt th,table.dt td{padding:9px 10px;border-bottom:1px solid var(--line);vertical-align:top;white-space:nowrap}
table.dt th{position:sticky;top:0;z-index:2;background:var(--head);font-size:11.5px;letter-spacing:.04em;text-transform:uppercase;color:var(--mut);text-align:left}
table.dt th.dt-sortable{cursor:pointer;user-select:none}
table.dt th.dt-sortable::after{content:"↕";opacity:.35;margin-left:5px;font-size:11px}
table.dt th[data-dir="asc"]::after{content:"↑";opacity:1;color:var(--blue)}
table.dt th[data-dir="desc"]::after{content:"↓";opacity:1;color:var(--blue)}
table.dt th:first-child,table.dt td:first-child{position:sticky;left:0;z-index:1;background:var(--card);box-shadow:1px 0 0 var(--line)}
table.dt th:first-child{z-index:3;background:var(--head)}
table.dt tbody tr:hover td{background:var(--hover)}
table.dt tbody tr:hover td:first-child{background:var(--hover)}
table.dt td.num{text-align:right;font-variant-numeric:tabular-nums}
table.dt td.wrap{white-space:normal;min-width:180px}
table.dt tr.dt-x td{white-space:normal;background:var(--bg);border-bottom:2px solid var(--line)}
table.dt tr.dt-x[hidden]{display:none}
.dt-acts{display:inline-flex;gap:4px;flex-wrap:wrap}
.dt-acts .btn{padding:5px 9px;font-size:12px;min-height:32px}
.dt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:8px 16px;padding:6px 2px}
.dt-grid label{display:flex;flex-direction:column;gap:3px;font-size:11.5px;text-transform:uppercase;letter-spacing:.03em;color:var(--mut)}
.dt-grid label input,.dt-grid label select{font-size:14px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);text-transform:none;letter-spacing:0}
.dt-grid .kv b{display:block;font-size:14px;color:var(--ink);font-weight:600;text-transform:none;letter-spacing:0;white-space:normal}
@media (max-width:820px){table.dt{font-size:13px}.dt-wrap,.dt-wrap.dt-tall{max-height:none;border-radius:10px}.dt-bar .dt-count{margin-left:0}}
/* Phones: row buttons and the filter bar meet the 44px touch target the rest
   of the admin already keeps (the compact 32px is a desktop-only density). */
@media (pointer:coarse){.dt-acts .btn{min-height:44px;padding:8px 12px}.dt-bar input,.dt-bar select{min-height:44px}}
</style>
<script>
(function(){
  var tables=document.querySelectorAll('table.dt');
  for(var i=0;i<tables.length;i++) init(tables[i]);
  function init(t){
    var tb=t.tBodies[0]; if(!tb) return;
    var rows=Array.prototype.filter.call(tb.rows,function(r){return !r.classList.contains('dt-x');});
    var wrap=t.closest('.dt-wrap'); if(wrap&&rows.length>25) wrap.classList.add('dt-tall');
    var ctrl=document.getElementById(t.getAttribute('data-controls')||'');
    var q=ctrl?ctrl.querySelector('.dt-q'):null;
    var filters=ctrl?Array.prototype.slice.call(ctrl.querySelectorAll('[data-dt-filter]')):[];
    var count=ctrl?ctrl.querySelector('.dt-count'):null;
    function extraOf(r){var n=r.nextElementSibling,out=[];while(n&&n.classList.contains('dt-x')){out.push(n);n=n.nextElementSibling;}return out;}
    function apply(){
      var needle=(q&&q.value?q.value:'').trim().toLowerCase(),shown=0;
      rows.forEach(function(r){
        var ok=true;
        if(needle){ok=(r.getAttribute('data-search')||r.textContent).toLowerCase().indexOf(needle)>=0;}
        for(var k=0;ok&&k<filters.length;k++){
          var f=filters[k],v=f.value; if(!v) continue;
          var rv=r.getAttribute('data-'+f.getAttribute('data-dt-filter'))||'';
          if(f.tagName==='SELECT') ok=(rv===v);
          else if(f.type==='date'||f.type==='month') ok=(f.getAttribute('data-dt-mode')==='max')?(rv!==''&&rv.slice(0,v.length)<=v):(rv!==''&&rv.slice(0,v.length)>=v);
          else ok=rv.toLowerCase().indexOf(v.toLowerCase())>=0;
        }
        r.style.display=ok?'':'none';
        if(!ok) extraOf(r).forEach(function(x){x.hidden=true;});
        if(ok) shown++;
      });
      if(count) count.textContent=shown+' / '+rows.length;
    }
    if(q) q.addEventListener('input',apply);
    filters.forEach(function(f){f.addEventListener('change',apply);f.addEventListener('input',apply);});
    var ths=t.tHead&&t.tHead.rows[0]?Array.prototype.slice.call(t.tHead.rows[0].cells):[];
    ths.forEach(function(th,ci){
      if(th.hasAttribute('data-nosort')) return;
      th.classList.add('dt-sortable'); th.setAttribute('tabindex','0');
      var dir=0;
      function val(r){var c=r.cells[ci]; if(!c) return ''; var v=c.hasAttribute('data-sort')?c.getAttribute('data-sort'):c.textContent.trim();
        if((th.getAttribute('data-type')||'text')==='num'){var n=parseFloat(String(v).replace(/[^0-9.\-]/g,''));return isNaN(n)?-Infinity:n;} return String(v).toLowerCase();}
      function sortBy(){dir=dir===1?-1:1; ths.forEach(function(o){o.removeAttribute('data-dir');}); th.setAttribute('data-dir',dir===1?'asc':'desc');
        rows.sort(function(a,b){var av=val(a),bv=val(b);return av<bv?-dir:(av>bv?dir:0);});
        rows.forEach(function(r){var ex=extraOf(r);tb.appendChild(r);ex.forEach(function(x){tb.appendChild(x);});});}
      th.addEventListener('click',sortBy);
      th.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();sortBy();}});
    });
    t.addEventListener('click',function(e){
      var b=e.target.closest('[data-dt-toggle]'); if(!b) return;
      var x=document.getElementById(b.getAttribute('data-dt-toggle')); if(!x) return;
      var open=x.hidden; var r=x.previousElementSibling; while(r&&r.classList.contains('dt-x')) r=r.previousElementSibling;
      if(r) extraOf(r).forEach(function(o){o.hidden=true;});
      x.hidden=!open; if(open){var f=x.querySelector('input:not([type=hidden]),select');if(f)f.focus();}
    });
    apply();
  }
})();
</script>
HTML;
}

/**
 * Collapsible sidebar sections. The server renders the active section already
 * open; this restores the admin's remembered open/closed set from localStorage
 * (a section is open if it is server-active OR in the remembered set), and
 * persists every toggle. Pure presentation — no links, perms or DOM ids change.
 */
/** Auto card-table for phones — see admin_footer(). */
function admin_cards_js(): string
{
    return <<<'JS'
(function () {
  if (!window.matchMedia) return;
  var mq = window.matchMedia('(max-width:820px)');
  // Re-run when the viewport crosses the breakpoint (rotated tablet, resized
  // window) — conversion is idempotent, already-converted tables are skipped.
  if (mq.addEventListener) mq.addEventListener('change', function (e) { if (e.matches) run(); });
  else if (mq.addListener) mq.addListener(function (e) { if (e.matches) run(); });
  if (mq.matches) run();
  function run() {
  var tables = document.querySelectorAll('table');
  for (var k = 0; k < tables.length; k++) {
    var t = tables[k];
    if (t.classList.contains('card-table') || /(^|\s)(no-card|sm-table)(\s|$)/.test(t.className)) continue;
    if (!t.tHead || !t.tHead.rows[0]) continue;
    var ths = t.tHead.rows[0].cells, labels = [];
    for (var i = 0; i < ths.length; i++) labels.push((ths[i].textContent || '').replace(/\s+/g, ' ').trim());
    if (labels.length < 3) continue;                      // 1-2 column tables read fine as they are
    var ok = true, rows = [];
    for (var b = 0; b < t.tBodies.length; b++) for (var r = 0; r < t.tBodies[b].rows.length; r++) rows.push(t.tBodies[b].rows[r]);
    for (var j = 0; j < rows.length && ok; j++) {
      var cells = rows[j].cells;
      if (cells.length === 1 && cells[0].colSpan > 1) continue; // "nothing here" placeholder row
      if (cells.length !== labels.length) ok = false;
    }
    if (!ok) continue;
    for (var j2 = 0; j2 < rows.length; j2++) {
      var cs = rows[j2].cells;
      if (cs.length === 1 && cs[0].colSpan > 1) continue;
      for (var c = 0; c < cs.length; c++) if (!cs[c].hasAttribute('data-label') && labels[c]) cs[c].setAttribute('data-label', labels[c]);
    }
    t.classList.add('card-table');
  }
  }
})();
JS;
}

function admin_nav_js(): string
{
    return <<<'JS'
function shgNavToggle(btn){
  try{
    var g = btn.closest('.side-group'); if(!g) return;
    g.classList.toggle('open');
    var open = [];
    document.querySelectorAll('.side-group.open').forEach(function(x){ open.push(x.getAttribute('data-sec')); });
    localStorage.setItem('shg_nav_open', JSON.stringify(open));
  }catch(e){}
}
(function(){
  try{
    var raw = localStorage.getItem('shg_nav_open');
    if(!raw) return;                 // first visit: keep the server default (active section open)
    var open = JSON.parse(raw) || [];
    document.querySelectorAll('.side-group').forEach(function(g){
      if(open.indexOf(g.getAttribute('data-sec')) >= 0){ g.classList.add('open'); }
    });
  }catch(e){}
})();
JS;
}

/**
 * Polls admin/pending-count.php every 20s. Updates the sidebar badge
 * unconditionally; only chimes + shows the "new payment" banner when the
 * count goes UP versus the last check (never on the first load of a
 * session, and never just because the count went down after someone
 * else verified one).
 */
function admin_poll_js(): string
{
    return <<<JS
(function () {
  var KEY_LAST = 'shg_admin_last_pending';

  function beep() {
    try {
      var ctx = new (window.AudioContext || window.webkitAudioContext)();
      [880, 1100].forEach(function (freq, i) {
        setTimeout(function () {
          var o = ctx.createOscillator(), g = ctx.createGain();
          o.type = 'sine'; o.frequency.value = freq;
          g.gain.setValueAtTime(0.0001, ctx.currentTime);
          g.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.02);
          g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.4);
          o.connect(g); g.connect(ctx.destination);
          o.start(); o.stop(ctx.currentTime + 0.45);
        }, i * 180);
      });
    } catch (e) {}
  }

  function showBanner(n) {
    var el = document.getElementById('newPaymentBanner');
    if (!el) {
      el = document.createElement('div');
      el.id = 'newPaymentBanner';
      el.className = 'live-toast pay';
      el.innerHTML = '<span></span><button type="button">Refresh</button>';
      el.querySelector('button').onclick = function () { location.reload(); };
      document.body.appendChild(el);
    }
    el.querySelector('span').textContent = n + ' new payment' + (n === 1 ? '' : 's') + ' waiting for verification.';
    el.style.display = 'flex';
  }

  // Bookings-list live pill: a page that renders the bookings list drops a
  // hidden #liveNewBookings[data-since="<maxId at load>"] anchor. When the
  // server's current max booking id climbs past that baseline, show a
  // non-intrusive "N new bookings — Refresh" pill instead of forcing a reload.
  function showBookingsPill(n) {
    var anchor = document.getElementById('liveNewBookings');
    if (!anchor) return;
    var el = document.getElementById('newBookingsPill');
    if (!el) {
      el = document.createElement('div');
      el.id = 'newBookingsPill';
      el.className = 'live-toast book';
      el.innerHTML = '<span></span><button type="button">Refresh</button>';
      el.querySelector('button').onclick = function () { location.reload(); };
      document.body.appendChild(el);
    }
    el.querySelector('span').textContent = n + ' new booking' + (n === 1 ? '' : 's') + ' since you opened this list.';
    el.style.display = 'flex';
  }

  function poll() {
    fetch('pending-count.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || typeof j.pending !== 'number') return;
        // New-bookings pill (only on pages that supply the baseline anchor).
        var anchor = document.getElementById('liveNewBookings');
        if (anchor && typeof j.maxBooking === 'number') {
          var since = parseInt(anchor.getAttribute('data-since') || '0', 10);
          if (since > 0 && j.maxBooking > since) { showBookingsPill(j.maxBooking - since); }
        }
        var badge = document.getElementById('navBadgePayments');
        if (badge) {
          if (j.pending > 0) {
            badge.textContent = j.pending; badge.style.display = 'inline-block';
            // Keep money visible: if the payments badge sits inside a collapsed
            // nav group, open that group so the pending count is never hidden.
            var grp = badge.closest && badge.closest('.side-group');
            if (grp) { grp.classList.add('open'); }
          } else { badge.style.display = 'none'; }
        }
        var last = parseInt(sessionStorage.getItem(KEY_LAST) || '-1', 10);
        if (last >= 0 && j.pending > last) { beep(); showBanner(j.pending - last); }
        sessionStorage.setItem(KEY_LAST, String(j.pending));
      })
      .catch(function () {});
  }
  poll();
  // 15s — "within seconds" per the ask, still gentle on the single VPS (two
  // cheap scalar reads per tick). Skips work while the tab is hidden.
  setInterval(function () { if (!document.hidden) poll(); }, 15000);
})();
JS;
}

/**
 * Global-search autosuggest — Phase 3. Debounces per keystroke, hits
 * admin/api/search.php, renders a keyboard-navigable dropdown. Kept
 * as a single IIFE so it works on every admin page without a bundler.
 */
function admin_search_js(): string
{
    return <<<'JS'
(function () {
  var form = document.querySelector('.tb-search'); if (!form) return;
  var input = form.querySelector('input[type="search"]');
  var box   = form.querySelector('#tbSuggest');
  if (!input || !box) return;

  var timer = null, latest = 0, cursor = -1, items = [];

  function iconFor(type) {
    return type === 'booking' ? '🎫' : type === 'passenger' ? '👤' :
           type === 'ticket'  ? '🎟️' : type === 'bus'      ? '🚌' :
           type === 'route'   ? '🛣️' : type === 'agent'    ? '🧑‍💼' : '🔎';
  }
  function labelFor(type) {
    return type === 'booking' ? 'Bookings' : type === 'passenger' ? 'Passengers' :
           type === 'ticket'  ? 'Tickets'  : type === 'bus'       ? 'Buses'      :
           type === 'route'   ? 'Routes'   : type === 'agent'      ? 'Agents'     : 'Results';
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g,
    function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  function render(rows) {
    items = rows || [];
    cursor = -1;
    if (items.length === 0) {
      box.innerHTML = '<div class="tbs-empty">No matches.</div>';
      box.hidden = false;
      return;
    }
    var out = [], lastType = null;
    items.forEach(function (r, i) {
      if (r.type !== lastType) {
        out.push('<div class="tbs-sec">' + esc(labelFor(r.type)) + '</div>');
        lastType = r.type;
      }
      out.push('<a data-idx="' + i + '" href="' + esc(r.href) + '">'
        + '<span class="tbs-icon">' + iconFor(r.type) + '</span>'
        + '<span>' + esc(r.title) + (r.subtitle ? '<br><small style="color:var(--mut)">' + esc(r.subtitle) + '</small>' : '') + '</span>'
        + (r.meta ? '<span class="tbs-meta">' + esc(r.meta) + '</span>' : '')
        + '</a>');
    });
    box.innerHTML = out.join('');
    box.hidden = false;
  }

  function fetchSuggest(q) {
    var id = ++latest;
    fetch('/admin/api/search.php?q=' + encodeURIComponent(q) + '&suggest=1', {
      credentials: 'same-origin', headers: { 'Accept': 'application/json' }
    })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (id !== latest) return;
        render(j && j.results ? j.results : []);
      })
      .catch(function () { if (id === latest) render([]); });
  }

  input.addEventListener('input', function () {
    var q = input.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { box.hidden = true; box.innerHTML = ''; return; }
    timer = setTimeout(function () { fetchSuggest(q); }, 180);
  });

  input.addEventListener('keydown', function (e) {
    if (box.hidden || items.length === 0) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); cursor = Math.min(items.length - 1, cursor + 1); paintCursor(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); cursor = Math.max(-1, cursor - 1); paintCursor(); }
    else if (e.key === 'Enter' && cursor >= 0) { e.preventDefault(); var a = box.querySelector('a[data-idx="' + cursor + '"]'); if (a) window.location.href = a.getAttribute('href'); }
    else if (e.key === 'Escape') { box.hidden = true; }
  });
  function paintCursor() {
    box.querySelectorAll('a').forEach(function (a) { a.classList.remove('act'); });
    var a = box.querySelector('a[data-idx="' + cursor + '"]');
    if (a) { a.classList.add('act'); a.scrollIntoView({ block: 'nearest' }); }
  }

  document.addEventListener('click', function (e) {
    if (!form.contains(e.target)) box.hidden = true;
  });
  input.addEventListener('focus', function () { if (box.innerHTML !== '') box.hidden = false; });
})();
JS;
}

/**
 * Small helper: a coloured status pill. v2 maps every status the system
 * uses onto the semantic tokens (.st-ok / .st-warn / .st-bad / .st-info /
 * .st-muted / .st-orange / .st-wa) so pages, dark mode and the mobile card
 * view all agree. Unknown statuses fall back to a neutral pill.
 */
function admin_pill(string $status, string $label = ''): string
{
    $s = strtolower(trim($status));
    $map = [
        'pending'     => ['st-warn',   'Pending'],
        'confirmed'   => ['st-ok',     'Confirmed'],
        'verified'    => ['st-ok',     'Verified'],
        'paid'        => ['st-ok',     'Paid'],
        'completed'   => ['st-ok',     'Completed'],
        'boarded'     => ['st-ok',     'Boarded'],
        'active'      => ['st-ok',     'Active'],
        'delivered'   => ['st-ok',     'Delivered'],
        'read'        => ['st-ok',     'Read'],
        'sent'        => ['st-info',   'Sent'],
        'queued'      => ['st-warn',   'Queued'],
        'processing'  => ['st-info',   'Processing'],
        'cancelled'   => ['st-bad',    'Cancelled'],
        'rejected'    => ['st-bad',    'Rejected'],
        'failed'      => ['st-bad',    'Failed'],
        'void'        => ['st-bad',    'Void'],
        'blocked'     => ['st-bad',    'Blocked'],
        'suspended'   => ['st-bad',    'Suspended'],
        'expired'     => ['st-muted',  'Expired'],
        'inactive'    => ['st-muted',  'Inactive'],
        'skipped'     => ['st-muted',  'Skipped'],
        'draft'       => ['st-muted',  'Draft'],
        'partial'     => ['st-orange', 'Partial'],
        'unpaid'      => ['st-orange', 'Unpaid'],
        'due'         => ['st-orange', 'Due'],
        'cod_pending' => ['st-orange', 'Pay at counter'],
        'refunded'    => ['st-info',   'Refunded'],
        'departed'    => ['st-info',   'Departed'],
        'arrived'     => ['st-ok',     'Arrived'],
        'boarding'    => ['st-orange', 'Boarding'],
        'scheduled'   => ['st-info',   'Scheduled'],
        'whatsapp'    => ['st-wa',     'WhatsApp'],
    ];
    [$cls, $text] = $map[$s] ?? ['st-muted', ucfirst($s)];
    if ($label !== '') { $text = $label; }
    return '<span class="pill ' . $cls . '">' . Security::e($text) . '</span>';
}

/**
 * Page header with an optional subtitle, breadcrumb trail and action
 * buttons. Call right after admin_header() — it sits under the <h1>.
 *   admin_page_head('Everything about this agent', ['Agents' => '/admin/agents.php'], '<a class="btn">…</a>');
 */
function admin_page_head(string $subtitle = '', array $crumbs = [], string $actionsHtml = ''): void
{
    echo '<div class="page-head"><div class="ph-txt">';
    if ($crumbs !== []) {
        echo '<div class="ph-crumbs">';
        $i = 0;
        foreach ($crumbs as $label => $href) {
            if ($i++ > 0) { echo '<svg class="a-ic sm" style="opacity:.5"><use href="#a-chevron"/></svg>'; }
            echo $href !== '' ? '<a href="' . Security::e((string) $href) . '">' . Security::e((string) $label) . '</a>' : '<span>' . Security::e((string) $label) . '</span>';
        }
        echo '</div>';
    }
    if ($subtitle !== '') { echo '<p class="ph-sub">' . Security::e($subtitle) . '</p>'; }
    echo '</div>';
    if ($actionsHtml !== '') { echo '<div class="ph-actions">' . $actionsHtml . '</div>'; }
    echo '</div>';
}

/**
 * One KPI tile. $tone: blue (default) | green | orange | red | navy | teal | violet | wa.
 * $delta: ['up'|'down'|'flat', '+12%'] optional. $href wraps the whole tile.
 */
function admin_kpi(string $label, string $value, string $sub = '', string $icon = 'chart', string $tone = 'blue', ?array $delta = null, string $href = ''): string
{
    $d = '';
    if ($delta !== null && isset($delta[0], $delta[1])) {
        $d = '<span class="kd ' . Security::e((string) $delta[0]) . '">' . Security::e((string) $delta[1]) . '</span>';
    }
    return '<div class="kpi tone-' . Security::e($tone) . '">'
         . '<span class="ki"><svg class="a-ic"><use href="#a-' . Security::e($icon) . '"/></svg></span>'
         . '<div class="kt"><div class="kk">' . Security::e($label) . '</div>'
         . '<div class="kv">' . Security::e($value) . $d . '</div>'
         . ($sub !== '' ? '<div class="ks">' . Security::e($sub) . '</div>' : '')
         . '</div>'
         . ($href !== '' ? '<a class="kl" href="' . Security::e($href) . '" aria-label="' . Security::e($label) . '"></a>' : '')
         . '</div>';
}

/** Empty-state block for a panel or table cell. */
function admin_empty(string $title, string $hint = '', string $icon = '🗂️', string $actionHtml = ''): string
{
    return '<div class="empty"><span class="em-ic">' . $icon . '</span><b>' . Security::e($title) . '</b>'
         . ($hint !== '' ? '<div>' . Security::e($hint) . '</div>' : '')
         . ($actionHtml !== '' ? '<div style="margin-top:12px">' . $actionHtml . '</div>' : '')
         . '</div>';
}

/** Initials avatar (or photo when a public URL is given). */
function admin_avatar(string $name, string $photoUrl = '', string $size = ''): string
{
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
        if ($part !== '' && mb_strlen($initials) < 2) { $initials .= mb_strtoupper(mb_substr($part, 0, 1)); }
    }
    $cls = 'avatar' . ($size !== '' ? ' ' . Security::e($size) : '');
    if ($photoUrl !== '') {
        return '<span class="' . $cls . '"><img src="' . Security::e($photoUrl) . '" alt="' . Security::e($name) . '" loading="lazy"></span>';
    }
    return '<span class="' . $cls . '" aria-hidden="true">' . Security::e($initials ?: '?') . '</span>';
}

/**
 * "Send on WhatsApp" button (17 Sep 2026). Renders a button the shared sheet
 * (admin_wa_js) turns into preview -> send / open-on-phone, all logged.
 *
 *   admin_wa_button('agent_statement', ['agent' => 12, 'from' => '2026-09-01', 'to' => '2026-09-17']);
 *   admin_wa_button('booking_ticket',  ['pnr' => 'SHG-2026-00123'], 'Ticket on WhatsApp');
 *
 * $target keys: agent | pnr | from | to | on | ledger. $opts: class (default
 * 'btn ghost sm'), title, id, fallback (an href for a <noscript> anchor).
 * Nothing renders when the office switch wa_admin_tools_enabled is off or
 * the signed-in role may not send that purpose (WaTemplates::REGISTRY).
 */
function admin_wa_button(string $purpose, array $target, string $label = 'Send on WhatsApp', array $opts = []): string
{
    static $registry = null;
    if ($registry === null) {
        require_once INCLUDE_PATH . '/watemplates.php';
        $registry = WaTemplates::REGISTRY;
    }
    $reg = $registry[$purpose] ?? null;
    if ($reg === null || !Settings::getBool('wa_admin_tools_enabled', true)) {
        return '';
    }
    // 18 Sep 2026: an agent may send their OWN statement-type messages to
    // themselves (WaTemplates::OWN_AGENT_PURPOSES) without the office
    // permission those purposes carry. admin/api/wa-send.php applies the
    // identical rule, so a button never appears that the API would refuse.
    $scopeId = Auth::bookingScopeAdminId();
    $own     = $scopeId !== null && (string) ($reg['target'] ?? '') === 'agent'
        && (int) ($target['agent'] ?? 0) === $scopeId
        && in_array($purpose, WaTemplates::OWN_AGENT_PURPOSES, true);
    if (!$own && !Auth::can((string) $reg['perm'])) {
        return '';
    }
    $attrs = ' data-wa-purpose="' . Security::e($purpose) . '"';
    foreach (['agent' => 'agent', 'pnr' => 'pnr', 'from' => 'from', 'to' => 'to', 'on' => 'on', 'ledger' => 'ledger', 'sid' => 'sid', 'doc' => 'doc', 'page' => 'page', 'target' => 'target', 'phone' => 'phone', 'country' => 'country'] as $k => $attr) {
        if (isset($target[$k]) && (string) $target[$k] !== '') {
            $attrs .= ' data-wa-' . $attr . '="' . Security::e((string) $target[$k]) . '"';
        }
    }
    $cls   = Security::e((string) ($opts['class'] ?? 'btn ghost sm'));
    $title = Security::e((string) ($opts['title'] ?? ((string) $reg['hint'] . ' — opens a preview first')));
    $id    = isset($opts['id']) ? ' id="' . Security::e((string) $opts['id']) . '"' : '';
    $html  = '<button type="button" class="' . $cls . ' wa-send"' . $id . $attrs . ' title="' . $title . '">'
           . '<svg class="a-ic" style="color:var(--wa-600)"><use href="#a-whatsapp"/></svg>' . Security::e($label) . '</button>';
    if (!empty($opts['fallback'])) {
        $html .= '<noscript><a class="' . $cls . '" href="' . Security::e((string) $opts['fallback']) . '" target="_blank" rel="noopener">' . Security::e($label) . '</a></noscript>';
    }
    return $html;
}

/**
 * The WhatsApp sheet: preview the composed message, then send through the
 * configured API (logged) or open it on the staff phone (also logged).
 * One IIFE, delegated on .wa-send, no framework — like admin_search_js().
 */
function admin_wa_js(): string
{
    return <<<'JS'
(function () {
  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var sheet = null, cur = null;
  function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
  function post(body){
    return fetch('/admin/api/wa-send.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)})
      .then(function(r){return r.json();}).catch(function(){return {ok:false,error:'Network error — try again.'};});
  }
  function toast(msg, bad){
    var t=document.createElement('div');t.className='toast';if(bad)t.style.background='var(--bad)';t.textContent=msg;document.body.appendChild(t);
    setTimeout(function(){t.style.transition='opacity .4s';t.style.opacity='0';setTimeout(function(){t.remove();},420);},3200);
  }
  function build(){
    sheet=document.createElement('div');sheet.className='wa-sheet';sheet.hidden=true;
    sheet.innerHTML='<div class="wa-card" role="dialog" aria-modal="true" aria-labelledby="waTitle">'
      +'<div class="wa-head"><span class="wa-ico"><svg class="a-ic"><use href="#a-whatsapp"/></svg></span><div><b id="waTitle"></b><div class="wa-to" id="waTo"></div></div><button type="button" class="wa-x" aria-label="Close">×</button></div>'
      +'<pre class="wa-text" id="waText"></pre>'
      +'<div class="wa-att" id="waAtt"></div>'
      +'<label class="wa-note">Add a note (optional)<input type="text" id="waNote" maxlength="300" placeholder="e.g. Please settle by Saturday"></label>'
      +'<div class="wa-status" id="waStatus"></div>'
      +'<div class="wa-acts"><button type="button" class="btn wa" id="waSend"><svg class="a-ic"><use href="#a-send"/></svg>Send via WhatsApp API</button>'
      +'<a class="btn ghost" id="waOpen" href="#" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-external"/></svg>Open in WhatsApp on this phone</a>'
      +'<button type="button" class="btn ghost" id="waCopy"><svg class="a-ic"><use href="#a-copy"/></svg>Copy text</button></div></div>';
    document.body.appendChild(sheet);
    sheet.addEventListener('click',function(e){if(e.target===sheet||e.target.closest('.wa-x'))close();});
    document.addEventListener('keydown',function(e){if(e.key==='Escape'&&!sheet.hidden)close();});
    sheet.querySelector('#waNote').addEventListener('change',function(){if(cur)preview(cur.btn,cur.body,true);});
    sheet.querySelector('#waSend').addEventListener('click',send);
    sheet.querySelector('#waOpen').addEventListener('click',function(){if(!cur)return;post(Object.assign({},cur.body,{action:'handoff',note:noteVal()}));mark(cur.btn,'Opened on phone');});
    sheet.querySelector('#waCopy').addEventListener('click',function(){var t=sheet.querySelector('#waText').textContent;try{navigator.clipboard.writeText(t);toast('Message copied');}catch(e){}});
  }
  function noteVal(){return (sheet.querySelector('#waNote').value||'').trim();}
  function close(){if(sheet){sheet.hidden=true;}cur=null;}
  function mark(btn,txt){if(!btn)return;btn.classList.add('wa-done');btn.innerHTML='<svg class="a-ic"><use href="#a-check-circle"/></svg>'+esc(txt);}
  function bodyFor(btn){
    var d=btn.dataset,b={purpose:d.waPurpose||''};
    if(d.waAgent)b.agent_id=parseInt(d.waAgent,10);if(d.waPnr)b.pnr=d.waPnr;if(d.waFrom)b.from=d.waFrom;if(d.waTo)b.to=d.waTo;if(d.waOn)b.on=d.waOn;if(d.waLedger)b.ledger_id=parseInt(d.waLedger,10);
    if(d.waSid)b.sid=parseInt(d.waSid,10);if(d.waDoc)b.doc=d.waDoc;if(d.waPage)b.page=parseInt(d.waPage,10);if(d.waTarget)b.target=d.waTarget;if(d.waPhone)b.phone=d.waPhone;if(d.waCountry)b.country=d.waCountry;
    return b;
  }
  function preview(btn,body,keep){
    if(!sheet)build();
    cur={btn:btn,body:body,res:null};
    sheet.hidden=false;
    var st=sheet.querySelector('#waStatus');st.className='wa-status';st.textContent='Preparing the message…';
    sheet.querySelector('#waTitle').textContent='WhatsApp';sheet.querySelector('#waTo').textContent='';
    if(!keep){sheet.querySelector('#waNote').value='';}
    sheet.querySelector('#waText').textContent='';sheet.querySelector('#waAtt').innerHTML='';
    post(Object.assign({},body,{action:'preview',note:noteVal()})).then(function(j){
      if(!cur||cur.btn!==btn)return;
      if(!j||!j.ok){st.className='wa-status bad';st.textContent=(j&&j.error)||'Could not prepare the message.';sheet.querySelector('#waSend').hidden=true;sheet.querySelector('#waOpen').hidden=true;return;}
      cur.res=j;
      sheet.querySelector('#waTitle').textContent=j.label||'WhatsApp';
      sheet.querySelector('#waTo').textContent=(j.recipient?j.recipient+' · ':'')+'+'+j.intl;
      sheet.querySelector('#waText').textContent=j.text||'';
      var att=sheet.querySelector('#waAtt');att.innerHTML='';
      (j.attachments||[]).forEach(function(u){var a=document.createElement('a');a.href=u;a.target='_blank';a.rel='noopener';a.className='chip';a.innerHTML='<svg class="a-ic"><use href="#a-'+(/\.pdf|statement/i.test(u)?'pdf':'image')+'"/></svg>Attachment';att.appendChild(a);});
      var send=sheet.querySelector('#waSend'),open=sheet.querySelector('#waOpen');
      send.hidden=!j.canAutoSend;open.hidden=false;open.href=j.link||'#';
      st.className='wa-status '+(j.canAutoSend?'ok':'warn');st.textContent=j.reason||'';
    });
  }
  function send(){
    if(!cur||!cur.res)return;
    var btn=sheet.querySelector('#waSend');btn.disabled=true;btn.textContent='Sending…';
    post(Object.assign({},cur.body,{action:'send',note:noteVal()})).then(function(j){
      btn.disabled=false;btn.innerHTML='<svg class="a-ic"><use href="#a-send"/></svg>Send via WhatsApp API';
      var st=sheet.querySelector('#waStatus');
      if(j&&j.ok){toast(j.message||'Sent on WhatsApp');mark(cur.btn,'Sent '+new Date().toTimeString().slice(0,5));close();return;}
      if(j&&j.handoff&&j.link){st.className='wa-status warn';st.textContent=(j.reason||'Send it from your phone instead.');var o=sheet.querySelector('#waOpen');o.href=j.link;o.hidden=false;btn.hidden=true;return;}
      st.className='wa-status bad';st.textContent=(j&&j.error)||'Send failed.';
    });
  }
  document.addEventListener('click',function(e){
    var b=e.target.closest('.wa-send');if(!b)return;e.preventDefault();
    preview(b,bodyFor(b),false);
  });
})();
JS;
}

/**
 * Admin SVG icon sprite — one place, every admin page. Rendered once
 * inside admin_header() right after <body>, then referenced with
 * <svg class="a-ic"><use href="#a-KEY"/></svg>. Stroke inherits
 * currentColor via CSS so the sidebar's on/off palette themes the icons.
 * v2 (17 Sep 2026): a full professional set (wallet, whatsapp, id-card,
 * settlement, loan, download, image, pdf, edit, phone, send, …).
 */
function admin_sprite(): string
{
    return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false">
<defs>
<symbol id="a-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></symbol>
<symbol id="a-chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></symbol>
<symbol id="a-chevron-down" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></symbol>
<symbol id="a-ticket-alt" viewBox="0 0 24 24"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v2M13 11v2M13 17v2"/></symbol>
<symbol id="a-ticket" viewBox="0 0 24 24"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v14"/></symbol>
<symbol id="a-doc" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></symbol>
<symbol id="a-pdf" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 17v-5h2a1.5 1.5 0 0 1 0 3H8M13 17v-5h1.5a2.5 2.5 0 0 1 0 5H13"/></symbol>
<symbol id="a-image" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></symbol>
<symbol id="a-user-solo" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/></symbol>
<symbol id="a-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/></symbol>
<symbol id="a-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M17.5 3.13a4 4 0 0 1 0 7.75"/></symbol>
<symbol id="a-user-cog" viewBox="0 0 24 24"><circle cx="10" cy="7" r="4"/><path d="M2 21v-1a6 6 0 0 1 8-5.66"/><circle cx="18" cy="17" r="3"/><path d="M18 12v1M18 21v1M13.76 14.76l.71.71M22.24 21.24l-.71-.71"/></symbol>
<symbol id="a-id-card" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><circle cx="8.5" cy="11" r="2.2"/><path d="M5 16.5a3.5 3.5 0 0 1 7 0M14 10h5M14 13.5h5"/></symbol>
<symbol id="a-notepad" viewBox="0 0 24 24"><path d="M8 3h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M9 8h6M9 12h6M9 16h4"/></symbol>
<symbol id="a-chart-up" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></symbol>
<symbol id="a-chart" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 16V9M12 16v-5M17 16v-9"/></symbol>
<symbol id="a-card" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></symbol>
<symbol id="a-wallet" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 0 1 0-4h13v4"/><path d="M4 7v12a2 2 0 0 0 2 2h14a1 1 0 0 0 1-1V8a1 1 0 0 0-1-1"/><path d="M16 13h4v4h-4a2 2 0 0 1 0-4z"/></symbol>
<symbol id="a-rupee" viewBox="0 0 24 24"><path d="M6 4h12M6 8h12M6 4c5 0 8 1.5 8 4.5S11 13 6 13l8 7"/></symbol>
<symbol id="a-coins" viewBox="0 0 24 24"><ellipse cx="9" cy="6" rx="6" ry="3"/><path d="M3 6v6c0 1.66 2.69 3 6 3s6-1.34 6-3V6"/><path d="M3 12v6c0 1.66 2.69 3 6 3s6-1.34 6-3v-6"/><path d="M15 9.5c3.3 0 6 1.34 6 3v6c0 1.66-2.7 3-6 3"/></symbol>
<symbol id="a-banknote" viewBox="0 0 24 24"><rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/></symbol>
<symbol id="a-handshake" viewBox="0 0 24 24"><path d="M2 9l4-4 5 2 3-2 4 4 4 1-3 7-4 2-3-1-2 1-4-3-4-2z"/><path d="M11 7l-3 3 2 2 3-3M14 12l2 2M12 14l2 2"/></symbol>
<symbol id="a-refund" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3.5-7.1"/><polyline points="3 3 3 8 8 8"/><path d="M12 8v4l3 2"/></symbol>
<symbol id="a-loan" viewBox="0 0 24 24"><path d="M12 3v18M7 8h7a3 3 0 0 1 0 6H8"/><path d="M3 21h18"/><path d="M19 12l2 2-2 2"/></symbol>
<symbol id="a-clipboard" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/></symbol>
<symbol id="a-mail" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="m2 7 10 6 10-6"/></symbol>
<symbol id="a-road" viewBox="0 0 24 24"><path d="M6 3 3 21M18 3l3 18M12 3v3M12 10v3M12 17v3"/></symbol>
<symbol id="a-route" viewBox="0 0 24 24"><circle cx="6" cy="19" r="3"/><circle cx="18" cy="5" r="3"/><path d="M9 19h6a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h6"/></symbol>
<symbol id="a-map-pin" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></symbol>
<symbol id="a-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
<symbol id="a-history" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/><path d="M12 7v5l3 2"/></symbol>
<symbol id="a-seat" viewBox="0 0 24 24"><path d="M5 12a3 3 0 0 1 3-3h4v9H8z"/><path d="M12 9v9h4a3 3 0 0 0 3-3v-3a3 3 0 0 0-3-3z"/><path d="M8 21v-3M16 21v-3"/></symbol>
<symbol id="a-camera" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></symbol>
<symbol id="a-msg" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></symbol>
<symbol id="a-whatsapp" viewBox="0 0 24 24"><path d="M3.5 20.5l1.3-4A8.5 8.5 0 1 1 8 19.3z"/><path d="M9.2 8.6c.2-.5.5-.5.8-.5h.5c.2 0 .4.1.5.4l.7 1.6c.1.2 0 .4-.1.6l-.5.6c-.1.2-.1.3 0 .5a6 6 0 0 0 2.9 2.7c.2.1.4 0 .5-.1l.7-.8c.2-.2.4-.2.6-.1l1.6.8c.2.1.4.2.4.4 0 .3 0 1-.5 1.5s-1.3.8-1.8.7c-1.2-.2-2.8-.8-4.6-2.6S8.8 11.4 8.6 10.2c-.1-.5.2-1.2.6-1.6z"/></symbol>
<symbol id="a-phone" viewBox="0 0 24 24"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.9 2.1z"/></symbol>
<symbol id="a-send" viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></symbol>
<symbol id="a-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/></symbol>
<symbol id="a-upload" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5M12 3v12"/></symbol>
<symbol id="a-share" viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></symbol>
<symbol id="a-printer" viewBox="0 0 24 24"><path d="M6 9V3h12v6"/><rect x="2" y="9" width="20" height="9" rx="2"/><path d="M6 14h12v7H6z"/></symbol>
<symbol id="a-external" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14L21 3"/></symbol>
<symbol id="a-copy" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></symbol>
<symbol id="a-edit" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></symbol>
<symbol id="a-trash" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></symbol>
<symbol id="a-eye" viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></symbol>
<symbol id="a-check" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></symbol>
<symbol id="a-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 12l3 3 5-6"/></symbol>
<symbol id="a-x" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
<symbol id="a-x-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></symbol>
<symbol id="a-alert" viewBox="0 0 24 24"><path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/></symbol>
<symbol id="a-info" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></symbol>
<symbol id="a-bell" viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></symbol>
<symbol id="a-plus" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></symbol>
<symbol id="a-plus-plain" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
<symbol id="a-minus" viewBox="0 0 24 24"><path d="M5 12h14"/></symbol>
<symbol id="a-arrow-right" viewBox="0 0 24 24"><path d="M5 12h14M12 5l7 7-7 7"/></symbol>
<symbol id="a-arrow-left" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></symbol>
<symbol id="a-refresh" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/></symbol>
<symbol id="a-filter" viewBox="0 0 24 24"><path d="M22 3H2l8 9.5V19l4 2v-8.5z"/></symbol>
<symbol id="a-more" viewBox="0 0 24 24"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></symbol>
<symbol id="a-home" viewBox="0 0 24 24"><path d="M3 10.5L12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></symbol>
<symbol id="a-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></symbol>
<symbol id="a-grid" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
<symbol id="a-layers" viewBox="0 0 24 24"><path d="M12 2l10 5-10 5L2 7z"/><path d="M2 12l10 5 10-5M2 17l10 5 10-5"/></symbol>
<symbol id="a-tag" viewBox="0 0 24 24"><path d="M20.6 13.4L13.4 20.6a2 2 0 0 1-2.8 0L2 12V2h10l8.6 8.6a2 2 0 0 1 0 2.8z"/><path d="M7 7h.01"/></symbol>
<symbol id="a-percent" viewBox="0 0 24 24"><path d="M19 5L5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></symbol>
<symbol id="a-lock" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></symbol>
<symbol id="a-unlock" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></symbol>
<symbol id="a-trophy" viewBox="0 0 24 24"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 18v4M14 18v4"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></symbol>
<symbol id="a-star" viewBox="0 0 24 24"><path d="M12 2.6l2.9 5.9 6.5.95-4.7 4.58 1.11 6.47L12 17.45l-5.81 3.05 1.11-6.47L2.6 9.45l6.5-.95L12 2.6Z"/></symbol>
<symbol id="a-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></symbol>
<symbol id="a-cog" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></symbol>
<symbol id="a-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></symbol>
<symbol id="a-theme" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></symbol>
<symbol id="a-key" viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></symbol>
<symbol id="a-bus" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="15" rx="3"/><path d="M3 10h18"/><circle cx="7.5" cy="15.5" r="1.5"/><circle cx="16.5" cy="15.5" r="1.5"/><path d="M7 21v-2M17 21v-2"/></symbol>
<symbol id="a-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
<symbol id="a-calendar" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol>
<symbol id="a-calendar-plus" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M12 14v5M9.5 16.5h5"/></symbol>
<symbol id="a-calendar-move" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M9 16h6M13 13.5l2.5 2.5-2.5 2.5"/></symbol>
<symbol id="a-ledger" viewBox="0 0 24 24"><path d="M6 2h11a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><path d="M9 7h6M9 11h6M9 15h4"/></symbol>
<symbol id="a-receipt" viewBox="0 0 24 24"><path d="M4 2v20l3-2 3 2 3-2 3 2 3-2 1 .7V2z"/><path d="M8 7h8M8 11h8M8 15h5"/></symbol>
<symbol id="a-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></symbol>
<symbol id="a-sparkle" viewBox="0 0 24 24"><path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8z"/><path d="M19 17l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z"/></symbol>
<symbol id="a-bolt" viewBox="0 0 24 24"><path d="M13 2L3 14h8l-1 8 10-12h-8z"/></symbol>
<symbol id="a-scan" viewBox="0 0 24 24"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M3 12h18"/></symbol>
</defs>
</svg>
SVG;
}

/** The admin stylesheet (kept here so pages stay self-contained).
 *
 *  Design system v2 (17 Sep 2026) — every class the pages already use keeps
 *  working (.card .panel .btn .pill .flash .toolbar .hcard .dash-* table.dt);
 *  this pass adds tokens, a real type scale, semantic status colours, icon
 *  buttons, KPI tiles, chips, empty states, a mobile bottom nav and the
 *  micro-interactions. Brand stays navy #12264E · blue #2E5FA8 · orange #F07C1F.
 */
function admin_css(): string
{
    return <<<'CSS'
*{box-sizing:border-box}
:root{
  --navy:#12264E;--navy-700:#1C3B72;--blue:#2E5FA8;--blue-600:#24508F;--blue-100:#E3ECF9;--blue-50:#F0F5FC;
  --orange:#F07C1F;--orange-600:#D96A10;--orange-100:#FCE9D6;--orange-50:#FFF5EC;
  --ink:#16233C;--ink-2:#2B3A55;--mut:#6B7688;--mut-2:#98A2B3;--line:#E4E9F1;--line-2:#D5DCE8;
  --bg:#F4F6FB;--card:#FFFFFF;--head:#F8FAFD;--hover:#EEF2FA;--soft:#F6F8FC;
  --ok:#178A50;--ok-bg:#E4F6EC;--warn:#B7791F;--warn-bg:#FFF4D6;--bad:#C53030;--bad-bg:#FBE3E3;
  --info:#2E5FA8;--info-bg:#E3ECF9;--wa:#25D366;--wa-600:#1DB558;--wa-bg:#E6FAEE;--violet:#6D4FC2;--violet-bg:#EEE9FB;--teal:#0E8C7F;--teal-bg:#E0F5F2;
  --r-xs:8px;--r-sm:10px;--r:14px;--r-lg:18px;--r-xl:22px;
  --sh-1:0 1px 2px rgba(18,38,78,.06),0 1px 6px rgba(18,38,78,.05);
  --sh-2:0 4px 14px rgba(18,38,78,.08),0 2px 6px rgba(18,38,78,.05);
  --sh-3:0 14px 34px rgba(18,38,78,.14),0 4px 12px rgba(18,38,78,.06);
  --ease:cubic-bezier(.4,0,.2,1);--dur:.18s;
  --f-ui:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Devanagari',sans-serif;
  --f-mono:ui-monospace,'SF Mono',Menlo,Consolas,monospace;
  --side-w:236px;--tb-h:58px;
}
:root[data-theme="dark"]{
  --ink:#E8F0FB;--ink-2:#C9D4E6;--mut:#93A4BE;--mut-2:#6F819C;--line:#22314A;--line-2:#2C3D5A;
  --bg:#0B1220;--card:#111D33;--head:#0F1A2E;--hover:#182741;--soft:#0E192C;
  --blue-100:#1B2F55;--blue-50:#152645;--orange-100:#3B2610;--orange-50:#2C1D0F;
  --ok-bg:#12321F;--warn-bg:#3A2B0E;--bad-bg:#3B1717;--info-bg:#1B2F55;--wa-bg:#0F3320;--violet-bg:#261E45;--teal-bg:#0F2E2B;
  --sh-1:0 1px 2px rgba(0,0,0,.35);--sh-2:0 4px 14px rgba(0,0,0,.35);--sh-3:0 14px 34px rgba(0,0,0,.5);
}
html{overflow-x:hidden;-webkit-text-size-adjust:100%}@supports(overflow:clip){html{overflow-x:clip}}
body{margin:0;font-family:var(--f-ui);font-size:14px;line-height:1.45;background:var(--bg);color:var(--ink);-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;font-feature-settings:"cv11","ss01"}
body,.side,.card,.panel,.panel h2,th,.toolbar input,.toolbar select,.btn.ghost{transition:background-color .2s,color .2s,border-color .2s}
img,pre{max-width:100%}
a{color:var(--blue);text-decoration:none}
a:hover{color:var(--blue-600)}
:focus-visible{outline:2px solid var(--blue);outline-offset:2px;border-radius:6px}
::selection{background:var(--blue-100)}
.a-ic{display:inline-block;width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:middle;flex:0 0 auto}
.a-ic.sm{width:15px;height:15px}.a-ic.lg{width:22px;height:22px}.a-ic.xl{width:28px;height:28px;stroke-width:1.75}

/* ── Top bar ─────────────────────────────────────────────────────────── */
.tb{position:sticky;top:0;z-index:30;height:var(--tb-h);display:flex;align-items:center;gap:12px;padding:0 16px;
    background:linear-gradient(90deg,var(--navy) 0%,var(--navy-700) 100%);color:#fff;box-shadow:0 2px 12px rgba(10,22,50,.28)}
.tb .brand{display:inline-flex;align-items:center;gap:9px;color:#fff;font-weight:800;font-size:15.5px;letter-spacing:-.01em;white-space:nowrap}
.tb .brand-logo{width:30px;height:30px;object-fit:contain;background:#fff;border-radius:9px;padding:3px;box-shadow:0 1px 3px rgba(0,0,0,.25);flex:0 0 auto}
.tb .brand span{color:#FFC08A;font-weight:600;font-size:12px;letter-spacing:.08em;text-transform:uppercase;margin-left:2px}
.tb .who{margin-left:auto;font-size:13px;color:#CDD6E6;display:inline-flex;align-items:center;gap:8px;white-space:nowrap}
.tb .who em{font-style:normal;color:#FFC08A;font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.06em}
.tb .who a{color:#fff;text-decoration:none;opacity:.9}
.tb .who a:hover{opacity:1;text-decoration:underline}
.tb .who .tb-av{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--orange),#FFB070);color:#fff;font-weight:800;font-size:12px;display:inline-flex;align-items:center;justify-content:center;letter-spacing:.02em;box-shadow:0 1px 3px rgba(0,0,0,.3)}
.tb .who .tb-name{font-weight:600;color:#fff}
.tb .menu{display:none;background:none;border:0;color:#fff;font-size:22px;cursor:pointer;line-height:1;padding:6px 8px;border-radius:8px}
.tb .menu:hover{background:rgba(255,255,255,.12)}
.theme-tog{background:none;border:0;color:#fff;font-size:17px;cursor:pointer;padding:6px;border-radius:8px;line-height:1;display:inline-flex;align-items:center}
.theme-tog:hover{background:rgba(255,255,255,.14)}
.tb-ibtn{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;color:#fff;background:rgba(255,255,255,.08);border:0;cursor:pointer}
.tb-ibtn:hover{background:rgba(255,255,255,.18)}

/* ── Global search ───────────────────────────────────────────────────── */
.tb-search{flex:1;max-width:560px;position:relative;display:flex;align-items:center;gap:8px;padding:0 12px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.08);border-radius:999px;height:38px;transition:background var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
.tb-search:focus-within{background:rgba(255,255,255,.18);box-shadow:0 0 0 3px rgba(240,124,31,.35);border-color:transparent}
.tb-search .tb-ic{color:#CDD6E6;flex:0 0 auto}
.tb-search input{flex:1;background:transparent;border:0;color:#fff;font-size:13.5px;height:100%;outline:none;padding:0;font-family:inherit;min-width:0}
.tb-search input::placeholder{color:rgba(255,255,255,.55)}
.tb-sug{position:absolute;top:calc(100% + 8px);left:0;right:0;background:var(--card);color:var(--ink);border:1px solid var(--line);border-radius:14px;box-shadow:var(--sh-3);max-height:60vh;overflow:auto;z-index:40;padding:6px}
.tb-sug .tbs-sec{padding:8px 10px 4px;font-size:10.5px;font-weight:800;letter-spacing:.1em;color:var(--mut);text-transform:uppercase}
.tb-sug a{display:flex;gap:10px;align-items:center;padding:9px 10px;border-radius:10px;color:var(--ink);font-size:13px}
.tb-sug a:hover,.tb-sug a.act{background:var(--hover)}
.tb-sug .tbs-icon{width:26px;height:26px;border-radius:8px;background:var(--blue-50);display:inline-flex;align-items:center;justify-content:center;color:var(--blue);font-weight:800;flex-shrink:0;font-size:14px}
.tb-sug .tbs-meta{color:var(--mut);font-size:11px;margin-left:auto;flex-shrink:0}
.tb-sug .tbs-empty{padding:14px 12px;color:var(--mut);font-size:13px;text-align:center}

/* ── Sidebar ─────────────────────────────────────────────────────────── */
.side{position:fixed;top:var(--tb-h);left:0;width:var(--side-w);height:calc(100vh - var(--tb-h));background:var(--card);border-right:1px solid var(--line);
      padding:12px 10px 24px;overflow:auto;scrollbar-width:thin}
.side a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:11px;color:var(--ink-2);font-weight:600;font-size:13.5px;position:relative;transition:background var(--dur) var(--ease),color var(--dur) var(--ease)}
.side a span{width:30px;height:30px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;background:var(--soft);color:var(--mut);flex:0 0 auto;transition:background var(--dur) var(--ease),color var(--dur) var(--ease)}
.side a:hover{background:var(--hover);color:var(--ink)}
.side a:hover span{color:var(--blue)}
.side a.on{background:var(--blue-50);color:var(--blue-600);font-weight:700}
.side a.on span{background:var(--blue);color:#fff;box-shadow:0 3px 8px rgba(46,95,168,.35)}
.side a.on::before{content:'';position:absolute;left:-10px;top:9px;bottom:9px;width:4px;border-radius:0 4px 4px 0;background:var(--orange)}
.side a.hot{background:linear-gradient(90deg,var(--orange-50),transparent 80%);border:1px solid rgba(240,124,31,.35);color:var(--ink)}
.side a.hot span{background:var(--orange-100);color:var(--orange-600)}
.side a.hot:hover{background:var(--orange-100)}
.side a.hot.on{background:var(--orange);border-color:var(--orange);color:#fff}
.side a.hot.on span{background:rgba(255,255,255,.25);color:#fff;box-shadow:none}
.navbadge{margin-left:auto;background:var(--orange);color:#fff;border-radius:999px;font-size:11px;font-weight:800;padding:1px 7px;line-height:1.5;min-width:20px;text-align:center}
.side a.on .navbadge{background:var(--card);color:var(--navy)}
.side-sec{padding:14px 12px 4px;font-size:10.5px;font-weight:800;letter-spacing:.12em;color:var(--mut);text-transform:uppercase;user-select:none}
.side-sec:first-child{padding-top:6px}
.side-toggle{display:flex;align-items:center;gap:6px;width:100%;background:none;border:0;text-align:left;cursor:pointer;font:inherit;border-radius:8px}
.side-sec.side-toggle{color:var(--mut)}
.side-toggle:hover{color:var(--ink);background:var(--soft)}
.side-group .side-items{display:none}
.side-group.open .side-items{display:block;animation:fadeDown .18s var(--ease)}
.nav-caret{margin-left:auto;width:14px;height:14px;transition:transform .15s;opacity:.7}
.side-group.open .nav-caret{transform:rotate(90deg)}
.side-group:first-child .side-toggle{padding-top:6px}
.side-site{margin-top:10px;border-top:1px solid var(--line);padding-top:12px}
.side-site{border-radius:0}

/* ── Mobile bottom nav (agents / counter, phones only) ───────────────── */
.mnav{display:none}
@media(max-width:820px){
  .mnav{position:fixed;left:0;right:0;bottom:0;z-index:36;display:grid;grid-template-columns:repeat(5,1fr);gap:2px;padding:6px 8px calc(6px + env(safe-area-inset-bottom));
        background:var(--card);border-top:1px solid var(--line);box-shadow:0 -6px 20px rgba(18,38,78,.10)}
  .mnav a{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;padding:6px 2px;border-radius:12px;color:var(--mut);font-size:10.5px;font-weight:700;letter-spacing:.01em;min-height:50px}
  .mnav a .a-ic{width:22px;height:22px;stroke-width:1.9}
  .mnav a.on{color:var(--blue-600);background:var(--blue-50)}
  .mnav a.fab{color:#fff}
  .mnav a.fab .mn-fab{width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,var(--orange),#FF9A4D);display:flex;align-items:center;justify-content:center;box-shadow:0 8px 20px rgba(240,124,31,.45);margin-top:-22px;border:3px solid var(--card)}
  .mnav a.fab .a-ic{color:#fff}
  /* One colour per job, always on (19 Sep 2026) - tinted icon + word, a
     filled chip when it is the page you are on. Tokens, so dark mode follows. */
  .mnav a.mn-home{--mn:var(--blue-600)} .mnav a.mn-tix{--mn:var(--ok)} .mnav a.mn-map{--mn:#0E7C86}
  .mnav a.mn-money{--mn:var(--warn)} .mnav a.mn-menu{--mn:var(--mut)}
  .mnav a[class^="mn-"] .a-ic{color:var(--mn)}
  .mnav a[class^="mn-"]{color:var(--ink)}
  .mnav a[class^="mn-"].on{color:var(--mn);background:color-mix(in srgb,var(--mn) 14%,transparent)}
  .mnav a.fab{gap:1px}
  .mnav a.fab .mn-fab-l{font-size:10.5px;font-weight:800;color:var(--orange-600);margin-top:2px}
  :root[data-theme="dark"] .mnav a.mn-map{--mn:#4FC3CC}
  body.has-mnav .wrap{padding-bottom:86px}
  body.has-mnav .side .side-site{display:flex}
}

/* ── Page frame ──────────────────────────────────────────────────────── */
.wrap{margin-left:var(--side-w);padding:22px 28px 40px;max-width:1280px}
.wrap h1{margin:2px 0 18px;font-size:23px;font-weight:800;letter-spacing:-.015em;line-height:1.2}
.page-head{display:flex;align-items:flex-start;gap:14px;flex-wrap:wrap;margin:-8px 0 18px}
.page-head .ph-txt{flex:1 1 260px;min-width:0}
.page-head .ph-sub{margin:2px 0 0;color:var(--mut);font-size:13.5px}
.page-head .ph-crumbs{font-size:12px;color:var(--mut);margin-bottom:4px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.page-head .ph-crumbs a{color:var(--mut)}.page-head .ph-crumbs a:hover{color:var(--blue)}
.page-head .ph-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.scrim{display:none}
.muted{color:var(--mut)}
.mono{font-family:var(--f-mono);font-size:13px}
.money{font-variant-numeric:tabular-nums;font-feature-settings:"tnum"}
.text-sm{font-size:12.5px}.text-xs{font-size:11.5px}.fw7{font-weight:700}.fw8{font-weight:800}
.row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.row.between{justify-content:space-between}
.stack{display:flex;flex-direction:column;gap:10px}
.grid-2,.grid-3,.grid-4{display:grid;gap:14px}
.grid-2{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}.grid-4{grid-template-columns:repeat(4,minmax(0,1fr))}
@media(max-width:1100px){.grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}.grid-3{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.grid-2,.grid-3,.grid-4{grid-template-columns:1fr}}
.divider{height:1px;background:var(--line);margin:14px 0}

/* ── Cards & panels ──────────────────────────────────────────────────── */
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px}
.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);padding:16px 18px;box-shadow:var(--sh-1);transition:box-shadow var(--dur) var(--ease),transform var(--dur) var(--ease),border-color var(--dur) var(--ease)}
.card:hover{box-shadow:var(--sh-2);border-color:var(--line-2)}
.card .k{font-size:11.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.08em;font-weight:700}
.card .v{font-size:26px;font-weight:800;margin-top:6px;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.card .v small{font-size:13px;color:var(--mut);font-weight:600}
.panel{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);overflow:hidden;margin-bottom:22px;box-shadow:var(--sh-1)}
.panel h2{margin:0;padding:14px 18px;font-size:15px;font-weight:700;border-bottom:1px solid var(--line);background:var(--head);display:flex;align-items:center;gap:8px;letter-spacing:-.01em}
.panel h2 .a-ic{color:var(--blue)}
.panel-body{padding:18px}
.panel-foot{padding:12px 18px;border-top:1px solid var(--line);background:var(--head);display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.dash-panel{background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh-1)}
.dash-panel .dp-head{padding:14px 18px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px;font-weight:700;font-size:15px;background:var(--head)}
.dash-panel .dp-body{padding:18px}

/* KPI hero tiles (gradient, kept) */
.dash-hero{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.hcard{border-radius:var(--r-lg);padding:20px 22px;color:#fff;position:relative;overflow:hidden;box-shadow:var(--sh-2);transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
.hcard:hover{transform:translateY(-2px);box-shadow:var(--sh-3)}
.hcard::after{content:'';position:absolute;right:-18px;top:-18px;width:90px;height:90px;border-radius:50%;background:rgba(255,255,255,.12)}
.hcard::before{content:'';position:absolute;left:-30px;bottom:-40px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.06)}
.hcard .hicon{font-size:26px;margin-bottom:8px;display:inline-flex;width:44px;height:44px;border-radius:12px;align-items:center;justify-content:center;background:rgba(255,255,255,.16);filter:drop-shadow(0 2px 4px rgba(0,0,0,.15))}
.hcard .hk{font-size:11.5px;text-transform:uppercase;letter-spacing:.08em;opacity:.88;font-weight:700}
.hcard .hv{font-size:30px;font-weight:800;margin:4px 0 2px;line-height:1.1;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.hcard .hsub{font-size:12px;opacity:.8}
.hcard .hlink{display:inline-block;margin-top:10px;font-size:12px;font-weight:700;color:#fff;background:rgba(255,255,255,.2);padding:5px 12px;border-radius:20px;text-decoration:none;transition:background var(--dur) var(--ease)}
.hcard .hlink:hover{background:rgba(255,255,255,.35)}
.hc-blue{background:linear-gradient(135deg,#2E5FA8,#1a3d6e)}
.hc-green{background:linear-gradient(135deg,#0a8b4b,#065a30)}
.hc-orange{background:linear-gradient(135deg,#F07C1F,#C85A0A)}
.hc-red{background:linear-gradient(135deg,#c0392b,#8e2320)}
.hc-navy{background:linear-gradient(135deg,#12264E,#0b1a36)}
.hc-teal{background:linear-gradient(135deg,#0E8C7F,#065F56)}
.hc-violet{background:linear-gradient(135deg,#6D4FC2,#45308F)}
@media(max-width:900px){.dash-hero{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}}

/* KPI tiles (light, Zepto-style) */
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;margin-bottom:22px}
.kpi{display:flex;gap:14px;align-items:flex-start;background:var(--card);border:1px solid var(--line);border-radius:var(--r-lg);padding:16px 18px;box-shadow:var(--sh-1);position:relative;overflow:hidden;transition:box-shadow var(--dur) var(--ease),transform var(--dur) var(--ease)}
.kpi:hover{box-shadow:var(--sh-2);transform:translateY(-1px)}
.kpi .ki{width:44px;height:44px;border-radius:13px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;font-size:20px;background:var(--blue-50);color:var(--blue)}
.kpi .ki .a-ic{width:22px;height:22px;stroke-width:1.9}
.kpi .kt{min-width:0;flex:1}
.kpi .kk{font-size:11.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.08em;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.kpi .kv{font-size:24px;font-weight:800;letter-spacing:-.02em;line-height:1.15;margin-top:4px;font-variant-numeric:tabular-nums}
.kpi .ks{font-size:12px;color:var(--mut);margin-top:3px}
.kpi .kd{display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;margin-left:6px;vertical-align:middle}
.kpi .kd.up{background:var(--ok-bg);color:var(--ok)}.kpi .kd.down{background:var(--bad-bg);color:var(--bad)}.kpi .kd.flat{background:var(--soft);color:var(--mut)}
.kpi.tone-green .ki{background:var(--ok-bg);color:var(--ok)}.kpi.tone-orange .ki{background:var(--orange-100);color:var(--orange-600)}
.kpi.tone-red .ki{background:var(--bad-bg);color:var(--bad)}.kpi.tone-navy .ki{background:var(--navy);color:#fff}
.kpi.tone-teal .ki{background:var(--teal-bg);color:var(--teal)}.kpi.tone-violet .ki{background:var(--violet-bg);color:var(--violet)}
.kpi.tone-wa .ki{background:var(--wa-bg);color:var(--wa-600)}
.kpi a.kl{position:absolute;inset:0}

/* ── Tables ──────────────────────────────────────────────────────────── */
table{width:100%;border-collapse:collapse;font-size:13.5px}
th,td{padding:11px 14px;text-align:left;border-bottom:1px solid var(--line);vertical-align:middle}
th{font-size:11.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.06em;background:var(--head);font-weight:700;white-space:nowrap}
tbody tr{transition:background var(--dur) var(--ease)}
tbody tr:hover>td{background:var(--soft)}
tr:last-child td{border-bottom:0}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
.tbl-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}
.tbl-sticky{max-height:70vh;overflow:auto}
.tbl-sticky thead th{position:sticky;top:0;z-index:2}
.row-highlight{background:#fff4d1 !important;animation:rowpulse 1.6s ease 2}
@keyframes rowpulse{0%,100%{background:#fff4d1}50%{background:#ffe28a}}
:root[data-theme="dark"] .row-highlight{background:#3a2f12 !important;color:#ffe9a8;animation:none}
:root[data-theme="dark"] .row-highlight td,:root[data-theme="dark"] .row-highlight th{color:#ffe9a8}

/* ── Status pills & chips ────────────────────────────────────────────── */
.pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;line-height:1.5;white-space:nowrap}
.pill::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.75}
.pill.nodot::before,.pill.st-none::before{display:none}
.st-ok{background:var(--ok-bg);color:var(--ok)}.st-warn{background:var(--warn-bg);color:var(--warn)}.st-bad{background:var(--bad-bg);color:var(--bad)}
.st-info{background:var(--info-bg);color:var(--info)}.st-muted{background:var(--soft);color:var(--mut)}.st-navy{background:var(--navy);color:#fff}
.st-orange{background:var(--orange-100);color:var(--orange-600)}.st-wa{background:var(--wa-bg);color:var(--wa-600)}.st-violet{background:var(--violet-bg);color:var(--violet)}.st-teal{background:var(--teal-bg);color:var(--teal)}
.chip{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:999px;font-size:12.5px;font-weight:600;background:var(--soft);color:var(--ink-2);border:1px solid var(--line)}
.chip .a-ic{width:14px;height:14px}
.chip.on{background:var(--blue-50);color:var(--blue-600);border-color:var(--blue-100)}
.chips{display:flex;gap:8px;flex-wrap:wrap}
.avatar{display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--navy));color:#fff;font-weight:800;width:38px;height:38px;font-size:13px;flex:0 0 auto;overflow:hidden;letter-spacing:.02em}
.avatar img{width:100%;height:100%;object-fit:cover}
.avatar.lg{width:64px;height:64px;font-size:20px;border-radius:18px}.avatar.xl{width:96px;height:96px;font-size:30px;border-radius:24px}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background:var(--mut)}
.dot.ok{background:var(--ok)}.dot.warn{background:var(--warn)}.dot.bad{background:var(--bad)}.dot.info{background:var(--blue)}

/* ── Buttons ─────────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 15px;border-radius:var(--r-sm);border:1px solid transparent;font-weight:700;font-size:13.5px;line-height:1.2;
     cursor:pointer;background:var(--blue);color:#fff;font-family:inherit;text-decoration:none;white-space:nowrap;transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease),background var(--dur) var(--ease),filter var(--dur) var(--ease)}
.btn:hover{filter:brightness(1.06);box-shadow:0 4px 12px rgba(46,95,168,.25);transform:translateY(-1px)}
.btn:active{transform:translateY(0) scale(.98);box-shadow:none}
.btn:disabled,.btn[aria-disabled="true"]{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none}
.btn .a-ic{width:16px;height:16px}
.btn.ok,.btn-ok,.btn.success{background:var(--ok)}
.btn.ok:hover,.btn-ok:hover{box-shadow:0 4px 12px rgba(23,138,80,.3)}
.btn.bad,.btn.danger,.btn-danger{background:var(--bad)}
.btn.bad:hover,.btn.danger:hover,.btn-danger:hover{box-shadow:0 4px 12px rgba(197,48,48,.3)}
.btn.warn,.btn-warn{background:var(--orange);color:#fff}
.btn.warn:hover,.btn-warn:hover{box-shadow:0 4px 12px rgba(240,124,31,.35)}
.btn-blue,.btn.primary{background:var(--blue)}
.btn.navy{background:var(--navy)}
.btn.ghost,.btn-ghost{background:var(--card);border-color:var(--line-2);color:var(--ink)}
.btn.ghost:hover,.btn-ghost:hover{background:var(--hover);box-shadow:var(--sh-1);border-color:var(--line-2)}
.btn.ghost.danger,.btn-ghost.danger{background:var(--card);border-color:#e3b4b4;color:#8a1f1f}
.btn.soft{background:var(--blue-50);color:var(--blue-600);border-color:transparent}
.btn.soft:hover{background:var(--blue-100);box-shadow:none}
.btn.wa,.btn-wa{background:var(--wa);color:#fff}
.btn.wa:hover,.btn-wa:hover{background:var(--wa-600);box-shadow:0 4px 12px rgba(37,211,102,.35)}
.btn.link{background:none;border:0;color:var(--blue);padding:4px 6px;box-shadow:none}
.btn.link:hover{text-decoration:underline;transform:none;box-shadow:none;filter:none}
.btn.sm,.btn-sm,.btn.btn-sm{padding:6px 11px;font-size:12.5px;border-radius:8px;min-height:32px}
.btn.lg,.btn-lg{padding:12px 20px;font-size:15px;border-radius:12px}
.btn.block{width:100%}
.btn.icon,.btn-icon{padding:8px;width:36px;height:36px}
.btn.icon.sm{width:30px;height:30px;padding:6px}
.row-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.btn-group{display:inline-flex;border:1px solid var(--line-2);border-radius:var(--r-sm);overflow:hidden}
.btn-group .btn{border-radius:0;border:0;border-right:1px solid var(--line);background:var(--card);color:var(--ink);box-shadow:none;transform:none}
.btn-group .btn:last-child{border-right:0}.btn-group .btn.on{background:var(--blue-50);color:var(--blue-600)}

/* ── Flash / alerts ──────────────────────────────────────────────────── */
.flash{padding:12px 16px 12px 18px;border-radius:12px;margin-bottom:16px;font-weight:600;position:relative;border:1px solid transparent;animation:flashIn .3s ease;display:flex;gap:10px;align-items:flex-start}
.flash::before{content:'';position:absolute;left:0;top:10px;bottom:10px;width:4px;border-radius:0 4px 4px 0;background:currentColor;opacity:.8}
.flash.ok{background:var(--ok-bg);color:var(--ok);border-color:rgba(23,138,80,.18)}
.flash.bad{background:var(--bad-bg);color:var(--bad);border-color:rgba(197,48,48,.18)}
.flash.warn{background:var(--warn-bg);color:var(--warn);border-color:rgba(183,121,31,.2)}
.flash.info{background:var(--info-bg);color:var(--info);border-color:rgba(46,95,168,.18)}
.flash.muted{background:var(--soft);color:var(--mut);border-color:var(--line)}
@keyframes flashIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
@keyframes fadeDown{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.note{font-size:12.5px;color:var(--mut);background:var(--soft);border:1px dashed var(--line-2);border-radius:10px;padding:10px 12px}

/* ── Forms ───────────────────────────────────────────────────────────── */
.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
/* 19 Sep 2026 (phone audit of all 42 admin pages): a long route name in a toolbar <select> pushed Seat Map and Trip Dashboard 83px sideways at 375px - the only two pages that overflowed. */
.toolbar label{min-width:0;max-width:100%}.toolbar select,.toolbar input{max-width:100%}
@media(max-width:560px){.toolbar label{flex:1 1 100%;display:flex;flex-direction:column;gap:4px}.toolbar label select,.toolbar label input{width:100%}}
.toolbar input,.toolbar select,.field input,.field select,.field textarea,input.inp,select.inp,textarea.inp{padding:9px 12px;border:1px solid var(--line-2);border-radius:var(--r-sm);font-size:14px;background:var(--card);color:var(--ink);font-family:inherit;min-height:40px;transition:border-color var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
.toolbar input:focus,.toolbar select:focus,.field input:focus,.field select:focus,.field textarea:focus,.inp:focus{outline:none;border-color:var(--blue);box-shadow:0 0 0 3px var(--blue-100)}
.field{display:flex;flex-direction:column;gap:5px}
.field>label,.field .lbl{font-size:11.5px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.06em}
.field .help{font-size:12px;color:var(--mut)}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px}
.form-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:14px}
.seg{display:inline-flex;background:var(--soft);border:1px solid var(--line);border-radius:12px;padding:3px;gap:2px}
.seg a,.seg button{padding:7px 13px;border-radius:9px;font-size:13px;font-weight:700;color:var(--mut);background:none;border:0;cursor:pointer;font-family:inherit;white-space:nowrap}
.seg a:hover,.seg button:hover{color:var(--ink)}
.seg a.on,.seg button.on{background:var(--card);color:var(--blue-600);box-shadow:var(--sh-1)}
.tabs{display:flex;gap:2px;border-bottom:1px solid var(--line);margin-bottom:16px;overflow-x:auto;scrollbar-width:none}
.tabs a{padding:10px 14px;font-weight:700;font-size:13.5px;color:var(--mut);border-bottom:2px solid transparent;margin-bottom:-1px;white-space:nowrap}
.tabs a:hover{color:var(--ink)}.tabs a.on{color:var(--blue-600);border-bottom-color:var(--blue)}
.switch{position:relative;display:inline-block;width:42px;height:24px}
.switch input{opacity:0;width:0;height:0}
.switch i{position:absolute;inset:0;background:var(--line-2);border-radius:999px;transition:background var(--dur) var(--ease)}
.switch i::after{content:'';position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.3);transition:transform var(--dur) var(--ease)}
.switch input:checked+i{background:var(--ok)}.switch input:checked+i::after{transform:translateX(18px)}

/* ── Misc components ─────────────────────────────────────────────────── */
.empty{padding:34px 16px;text-align:center;color:var(--mut)}
.empty .em-ic{width:56px;height:56px;border-radius:16px;background:var(--soft);display:inline-flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:10px;color:var(--mut)}
.empty b{display:block;color:var(--ink);font-size:15px;margin-bottom:3px}
.progress{height:10px;border-radius:999px;background:var(--soft);overflow:hidden}
.progress i{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--blue),#5B8AD9);transition:width .4s var(--ease)}
.progress i.ok{background:linear-gradient(90deg,#27ae60,#2ecc71)}.progress i.warn{background:linear-gradient(90deg,#f39c12,#e67e22)}.progress i.bad{background:linear-gradient(90deg,#e74c3c,#c0392b)}
.timeline{list-style:none;margin:0;padding:0 0 0 18px;border-left:2px solid var(--line);display:flex;flex-direction:column;gap:14px}
.timeline li{position:relative;padding-left:14px}
.timeline li::before{content:'';position:absolute;left:-24px;top:5px;width:10px;height:10px;border-radius:50%;background:var(--blue);box-shadow:0 0 0 3px var(--card)}
.timeline li.ok::before{background:var(--ok)}.timeline li.bad::before{background:var(--bad)}.timeline li.warn::before{background:var(--warn)}
.timeline .tl-t{font-size:11.5px;color:var(--mut)}
.kv-list{display:grid;grid-template-columns:max-content 1fr;gap:6px 16px;font-size:13.5px}
.kv-list dt{color:var(--mut);font-weight:600}.kv-list dd{margin:0;font-weight:600}
.skeleton{background:linear-gradient(90deg,var(--soft) 25%,var(--hover) 37%,var(--soft) 63%);background-size:400% 100%;animation:shimmer 1.4s ease infinite;border-radius:8px;min-height:14px}
@keyframes shimmer{0%{background-position:100% 0}100%{background-position:0 0}}
.toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:var(--navy);color:#fff;padding:12px 18px;border-radius:12px;box-shadow:var(--sh-3);font-weight:600;font-size:13.5px;z-index:60;animation:flashIn .25s ease}
.wa-ico{display:inline-flex;width:18px;height:18px;border-radius:50%;background:var(--wa);color:#fff;align-items:center;justify-content:center;font-size:11px;font-weight:900}
details.advanced-section>summary{cursor:pointer;font-weight:700;color:var(--blue);padding:8px 0;list-style:none}
details.advanced-section>summary::before{content:'▶ ';font-size:11px}
details.advanced-section[open]>summary::before{content:'▼ '}

/* ── Dashboard widgets (promoted from admin/index.php, 17 Sep 2026) ──── */
.dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:22px}
.dash-grid.two-one{grid-template-columns:2fr 1fr}
.dash-grid.one-two{grid-template-columns:1fr 2fr}
.dp-note{font-size:12px;color:var(--mut)}
.quick-actions{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:22px}
.qa{display:inline-flex;align-items:center;gap:9px;padding:10px 16px;border-radius:12px;background:var(--card);border:1px solid var(--line);color:var(--ink);text-decoration:none;font-weight:700;font-size:13.5px;box-shadow:var(--sh-1);transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease),border-color var(--dur) var(--ease)}
.qa:hover{border-color:var(--blue);background:var(--hover);transform:translateY(-1px);box-shadow:var(--sh-2);color:var(--ink)}
.qa .qa-icon{font-size:20px;display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:9px;background:var(--blue-50);color:var(--blue)}
.qa .qa-icon .a-ic{width:17px;height:17px}
.qa .qa-badge{background:var(--orange);color:#fff;font-size:11px;font-weight:800;padding:1px 7px;border-radius:999px;margin-left:2px}
.spark-wrap{display:flex;align-items:flex-end;gap:6px;height:110px;padding:0 4px}
.spark-bar{flex:1;border-radius:6px 6px 0 0;background:linear-gradient(180deg,#5B8AD9,#2E5FA8);min-width:8px;position:relative;transition:height .3s var(--ease)}
.spark-bar:hover{filter:brightness(1.15)}
.spark-bar .spark-tip{display:none;position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:var(--navy);color:#fff;font-size:11px;padding:3px 8px;border-radius:6px;white-space:nowrap;margin-bottom:4px;z-index:2}
.spark-bar:hover .spark-tip{display:block}
@media(pointer:coarse){.spark-bar .spark-tip{display:block;font-size:9px;padding:2px 4px;margin-bottom:2px}}
.spark-labels{display:flex;gap:6px;padding:8px 4px 0;font-size:11px;color:var(--mut);text-align:center}
.spark-labels span{flex:1;min-width:8px}
.occ-bar{height:20px;border-radius:10px;background:var(--hover);overflow:hidden;margin:6px 0}
.occ-fill{height:100%;border-radius:10px;transition:width .4s var(--ease)}
.occ-green{background:linear-gradient(90deg,#27ae60,#2ecc71)}
.occ-yellow{background:linear-gradient(90deg,#f39c12,#e67e22)}
.occ-red{background:linear-gradient(90deg,#e74c3c,#c0392b)}
.donut-wrap{display:flex;align-items:center;gap:24px;flex-wrap:wrap}
.donut-svg{width:130px;height:130px;flex-shrink:0}
.donut-legend{display:flex;flex-direction:column;gap:10px}
.donut-item{display:flex;align-items:center;gap:8px;font-size:14px}
.donut-dot{width:12px;height:12px;border-radius:4px;flex-shrink:0}
.status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background:var(--mut)}
.status-dot.confirmed{background:#27ae60}.status-dot.pending{background:#f39c12}.status-dot.expired{background:#95a5a6}.status-dot.cancelled{background:#e74c3c}
.src-badge{font-size:11px;padding:2px 9px;border-radius:20px;font-weight:700;display:inline-block}
.src-badge.web,.src-badge.app{background:var(--ok-bg);color:var(--ok)}
.src-badge.agent{background:var(--info-bg);color:var(--info)}
.src-badge.counter,.src-badge.admin{background:var(--orange-100);color:var(--orange-600)}
.trip-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)}
.trip-row:last-child{border-bottom:0}
.live-trip{display:grid;grid-template-columns:64px 1fr 170px 190px 150px;gap:14px;align-items:center;padding:14px 0;border-bottom:1px solid var(--line)}
.live-trip:last-child{border-bottom:0}
.live-time{font-weight:800;color:var(--blue);font-size:15px;font-variant-numeric:tabular-nums}
.live-route{font-weight:700;font-size:14px;color:var(--ink)}
.live-route small{display:block;font-weight:500;color:var(--mut);font-size:12px;margin-top:2px}
.live-bus{font-size:13px;color:var(--ink);line-height:1.3}
.live-bus .lb-num{font-weight:800;background:var(--hover);padding:2px 8px;border-radius:6px;font-family:var(--f-mono);font-size:12px}
.live-bus small{display:block;font-size:11px;color:var(--mut);margin-top:2px}
.live-driver{font-size:13px;color:var(--ink);line-height:1.3}
.live-driver .ld-empty{font-style:italic;color:var(--mut);font-size:12px}
.live-driver small{display:block;font-size:11px;color:var(--mut);margin-top:2px}
.live-state{text-align:right}
.state-pill{display:inline-block;padding:5px 12px;border-radius:20px;font-size:11.5px;font-weight:800;letter-spacing:.04em;color:#fff;text-transform:uppercase;box-shadow:0 1px 3px rgba(0,0,0,.15)}
.state-detail{display:block;font-size:11px;color:var(--mut);margin-top:4px}
.live-seats{font-size:12px;color:var(--mut);margin-top:6px;text-align:right}
.live-seats strong{color:var(--ink);font-weight:700}
.live-fresh{display:inline-flex;align-items:center;gap:6px;font-size:11px;color:var(--mut);margin-left:auto;font-weight:600}
.live-fresh::before{content:'';width:8px;height:8px;border-radius:50%;background:#27ae60;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:.4}50%{opacity:1}}
.promo{display:flex;align-items:center;gap:14px;margin:0 0 20px;padding:14px 18px;border-radius:var(--r-lg);color:#fff;text-decoration:none;position:relative;overflow:hidden;
  background:linear-gradient(135deg,#12264E 0%,#1C3B72 55%,#2E5FA8 100%);box-shadow:0 8px 24px rgba(18,38,78,.28);border:1px solid rgba(255,255,255,.12);transition:transform var(--dur) var(--ease),box-shadow var(--dur) var(--ease)}
.promo::before{content:"";position:absolute;right:-60px;bottom:-80px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,rgba(240,124,31,.55),transparent 65%);pointer-events:none}
.promo:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(18,38,78,.38);color:#fff}
.promo .promo-ico{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.14);flex:0 0 auto}
.promo .promo-ico .a-ic{width:26px;height:26px;stroke-width:1.8}
.promo .promo-txt{display:flex;flex-direction:column;gap:2px;min-width:0;flex:1 1 auto}
.promo .promo-txt b{font-size:16.5px;font-weight:800;letter-spacing:-.01em}
.promo .promo-txt b em{font-style:normal;font-size:10px;letter-spacing:.1em;text-transform:uppercase;background:var(--orange);padding:2px 7px;border-radius:999px;margin-left:8px;vertical-align:middle}
.promo .promo-txt small{font-size:12.5px;opacity:.88;line-height:1.4}
.promo .promo-cta{flex:0 0 auto;background:var(--orange);color:#fff;font-weight:800;padding:10px 16px;border-radius:999px;font-size:13.5px;box-shadow:0 4px 14px rgba(240,124,31,.45);white-space:nowrap}
.live-toast{position:fixed;right:16px;z-index:50;background:var(--navy);color:#fff;padding:12px 16px;border-radius:12px;box-shadow:var(--sh-3);display:flex;align-items:center;gap:10px;font-size:13px;max-width:320px;animation:flashIn .25s ease}
.live-toast.pay{top:66px}.live-toast.book{top:112px;background:var(--ok)}
.live-toast button{background:var(--orange);border:0;color:#fff;padding:6px 10px;border-radius:7px;font-weight:700;cursor:pointer;font-size:12px;font-family:inherit}
.live-toast.book button{background:#fff;color:var(--ok)}
.flash .flash-x{margin-left:auto;background:none;border:0;color:inherit;opacity:.6;cursor:pointer;font-size:16px;line-height:1;padding:0 2px}
.flash .flash-x:hover{opacity:1}
@media(max-width:900px){
  .dash-grid,.dash-grid.two-one,.dash-grid.one-two{grid-template-columns:1fr}
  .live-trip{grid-template-columns:1fr;gap:6px;padding:14px 0}
  .live-state,.live-seats{text-align:left}
  .promo{flex-wrap:wrap}.promo .promo-cta{width:100%;text-align:center}
}

/* ── WhatsApp sheet (admin_wa_button / admin_wa_js, 17 Sep 2026) ─────── */
.wa-sheet{position:fixed;inset:0;z-index:70;background:rgba(6,14,30,.45);display:flex;align-items:flex-end;justify-content:center;padding:16px;backdrop-filter:blur(2px)}
.wa-sheet[hidden]{display:none}
.wa-card{background:var(--card);color:var(--ink);border-radius:var(--r-xl);box-shadow:var(--sh-3);width:min(560px,100%);max-height:92vh;display:flex;flex-direction:column;overflow:hidden;animation:flashIn .2s ease}
.wa-head{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid var(--line);background:var(--head)}
.wa-head b{font-size:15px}.wa-head .wa-to{font-size:12.5px;color:var(--mut);font-family:var(--f-mono)}
.wa-head .wa-ico{width:38px;height:38px;border-radius:12px;background:var(--wa);color:#fff;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto}
.wa-head .wa-x{margin-left:auto;background:none;border:0;font-size:22px;line-height:1;color:var(--mut);cursor:pointer;padding:4px 8px;border-radius:8px}
.wa-head .wa-x:hover{background:var(--hover);color:var(--ink)}
.wa-text{margin:0;padding:14px 16px;font:13.5px/1.5 var(--f-ui);white-space:pre-wrap;word-break:break-word;background:#E7F5EC;color:#14311F;overflow:auto;flex:1 1 auto;border-left:4px solid var(--wa)}
:root[data-theme="dark"] .wa-text{background:#10261A;color:#D7F4E3}
.wa-att{display:flex;gap:8px;flex-wrap:wrap;padding:8px 16px 0}
.wa-att:empty{display:none}
.wa-note{display:flex;flex-direction:column;gap:4px;padding:10px 16px 0;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--mut)}
.wa-note input{font:14px var(--f-ui);padding:9px 11px;border:1px solid var(--line-2);border-radius:10px;background:var(--card);color:var(--ink);text-transform:none;letter-spacing:0;font-weight:500}
.wa-status{padding:10px 16px 0;font-size:12.5px;color:var(--mut)}
.wa-status.ok{color:var(--ok)}.wa-status.warn{color:var(--warn)}.wa-status.bad{color:var(--bad)}
.wa-acts{display:flex;gap:8px;flex-wrap:wrap;padding:12px 16px 16px}
.wa-acts .btn[hidden]{display:none}
.btn.wa-done{background:var(--ok-bg);color:var(--ok);border-color:transparent;pointer-events:none}
@media(min-width:700px){.wa-sheet{align-items:center}}

/* ── Touch targets & responsive ──────────────────────────────────────── */
@media(pointer:coarse){
  .btn{min-height:44px}
  .toolbar input,.toolbar select,.side a{min-height:44px}
  .mf-tools input,.mf-tools select,.acc-tools input,.acc-tools select,form input[type=date],form select{min-height:44px}
  .theme-tog,.tb .menu,.tb-ibtn{min-width:44px;min-height:44px;justify-content:center;align-items:center}
  .btn.sm,.btn-sm,.btn.btn-sm{min-height:40px}
  .code-edit button{min-width:36px;min-height:36px}
}
@media(max-width:1024px){.wrap{padding:20px 20px 40px}}
@media(max-width:820px){
  .side{transform:translateX(-100%);transition:transform .22s var(--ease);z-index:40;box-shadow:var(--sh-3)}
  .wrap{padding:14px 12px 40px;margin-left:0}
  .wrap h1{font-size:20px;margin-bottom:14px}
  input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]),select,textarea{font-size:16px !important}
  .tb .who a{display:inline-block;padding:10px 4px}
  .tb .who .tb-name,.tb .who em{display:none}
  body.nav-open .side{transform:none}
  body.nav-open .scrim{display:block;position:fixed;inset:var(--tb-h) 0 0;background:rgba(6,14,30,.45);z-index:35;backdrop-filter:blur(2px)}
  .tb .menu{display:block}
  .tb .who{font-size:11px}
  .panel{overflow-x:auto;border-radius:14px}
  .tb-search{max-width:none;order:99;flex-basis:100%;margin-top:8px;height:36px}
  .tb{flex-wrap:wrap;height:auto;padding-top:8px;padding-bottom:8px;gap:8px}
  .page-head .ph-actions{width:100%}
  .page-head .ph-actions .btn{flex:1 1 auto}
  table.card-table thead{display:none}
  table.card-table tbody tr{display:block;border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:10px;background:var(--card);box-shadow:var(--sh-1)}
  table.card-table tbody tr:hover>td{background:transparent}
  table.card-table tbody td{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border:none;font-size:14px;gap:10px}
  table.card-table tbody td::before{content:attr(data-label);font-weight:700;font-size:11px;color:var(--mut);margin-right:12px;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}
  details.advanced-section{margin-top:12px}
}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms !important;animation-iteration-count:1 !important;transition-duration:.01ms !important}}
@media print{.tb,.side,.scrim,.mnav,.no-print{display:none !important}.wrap{margin:0;padding:0;max-width:none}.panel,.card{box-shadow:none;break-inside:avoid}}
CSS;
}
