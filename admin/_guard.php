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
        ['href' => 'seatmap.php',      'icon' => 'seat',      'label' => 'Seat Map',         'perm' => 'schedules.view', 'section' => 'Tickets'],
        ['href' => 'manifest.php',     'icon' => 'clipboard', 'label' => 'Manifest & Chalani', 'perm' => 'bookings.view', 'section' => 'Tickets'],
        ['href' => 'scan.php',         'icon' => 'camera',    'label' => 'Scan Ticket',      'perm' => 'tickets.scan',   'section' => 'Tickets'],

        // Agents — the register first (office roles only — the page itself
        // refuses a counter agent's session), then the working screens.
        // 5 Sep 2026: for OFFICE viewers these gate on commissions.view, not
        // bookings.view — an agent still sees their own pages, but the new
        // counter role (and support) must not browse other sellers' money.
        // The pages enforce the same rule server-side; this only hides nav.
        ['href' => 'agents.php',       'icon' => 'user-cog',  'label' => 'Agents',           'perm' => 'commissions.view', 'section' => 'Agents'],
        ['href' => 'agent.php',        'icon' => 'ticket-alt', 'label' => $agentView ? 'My Dashboard' : 'Agent Panel',    'perm' => $agentView ? 'bookings.view' : 'commissions.view', 'section' => 'Agents'],
        ['href' => 'agent-sales.php',  'icon' => 'doc',        'label' => $agentView ? 'My Sales' : 'Agent Sales',        'perm' => $agentView ? 'bookings.view' : 'commissions.view', 'section' => 'Agents'],
        ['href' => 'agent-passengers.php','icon' => 'user-solo','label' => $agentView ? 'My Passengers' : 'Agent Passengers','perm' => $agentView ? 'bookings.view' : 'commissions.view', 'section' => 'Agents'],
        ['href' => 'agent-offline.php','icon' => 'notepad',   'label' => 'Paper Tickets',    'perm' => $agentView ? 'bookings.view' : 'commissions.view',  'section' => 'Agents'],
        ['href' => 'agent-ranking.php','icon' => 'trophy',    'label' => 'Agent Ranking',    'perm' => 'dashboard.view', 'section' => 'Agents'],
        ['href' => 'staff.php',        'icon' => 'user-cog',  'label' => 'Staff & Approvals', 'perm' => 'staff.manage',  'section' => 'Agents'],

        // Customers — leads and the people who travelled. An agent's own
        // bookings are scoped by sold_by_admin_id, but an unclaimed lead has
        // no seller so it belongs to the office.
        ['href' => 'customers.php',    'icon' => 'users',     'label' => 'Customers',        'perm' => 'customers.view', 'section' => 'Customers'],
        ['href' => 'enquiries.php',    'icon' => 'mail',      'label' => 'Enquiries',        'perm' => 'customers.view', 'section' => 'Customers'],

        // Buses — fleet, the day-by-day schedule and the crew.
        ['href' => 'calendar.php',     'icon' => 'calendar',  'label' => 'Bus Calendar',     'perm' => 'schedules.manage','section' => 'Buses'],
        ['href' => 'buses.php',        'icon' => 'bus',       'label' => 'Bus Fleet',        'perm' => 'schedules.view', 'section' => 'Buses'],
        ['href' => 'schedule.php',     'icon' => 'calendar-plus','label' => 'Schedule Manager','perm' => 'schedules.manage','section' => 'Buses'],
        ['href' => 'trips.php',        'icon' => 'clock',     'label' => 'Trips Board',      'perm' => 'schedules.view', 'section' => 'Buses'],
        ['href' => 'trip-dashboard.php','icon' => 'calendar', 'label' => 'Date View',        'perm' => 'schedules.view', 'section' => 'Buses'],
        ['href' => 'drivers.php',      'icon' => 'user-solo', 'label' => 'Drivers & Crew',   'perm' => 'drivers.view',   'section' => 'Buses'],

        // Routes
        ['href' => 'routes.php',       'icon' => 'road',      'label' => 'Routes',           'perm' => 'routes.view',    'section' => 'Routes'],

        // Payments — money in, money back.
        ['href' => 'payments.php',     'icon' => 'card',      'label' => 'Verify Payments',  'perm' => 'payments.view',  'section' => 'Payments'],
        ['href' => 'refunds.php',      'icon' => 'refund',    'label' => 'Refunds',          'perm' => 'refunds.view',   'section' => 'Payments'],

        // Reports
        ['href' => 'analytics.php',    'icon' => 'chart-up',  'label' => 'Analytics',        'perm' => 'dashboard.view', 'section' => 'Reports'],
        ['href' => 'feedback.php',     'icon' => 'star',      'label' => 'Ratings',          'perm' => 'dashboard.view', 'section' => 'Reports'],
        ['href' => 'accounting.php',   'icon' => 'ledger',    'label' => 'Accounting',       'perm' => 'payments.view',  'section' => 'Reports'],
        ['href' => 'messages-log.php', 'icon' => 'msg',       'label' => 'Message Log',      'perm' => 'dashboard.view', 'section' => 'Reports'],

        // Settings
        ['href' => 'settings.php',     'icon' => 'cog',       'label' => 'Settings',         'perm' => 'dashboard.view', 'section' => 'Settings'],
        ['href' => 'activity-log.php', 'icon' => 'shield',    'label' => 'Activity & Security','perm'=> 'dashboard.view', 'section' => 'Settings'],

        // Map — routes, stops, head office and the driver's live position (5 Sep 2026).
        ['href' => 'map.php',          'icon' => 'road',      'label' => 'Live Map',         'perm' => 'schedules.view', 'section' => 'Map'],
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
        : ' · <a href="' . $base . '/admin/change-password.php" title="Change your password" aria-label="Change password"><svg class="a-ic"><use href="#a-key"/></svg></a>';
    echo '<div class="who">' . $name . ' <em>' . $role . '</em> · '
       . '<button type="button" class="theme-tog" onclick="shgTheme()" aria-label="Toggle dark mode" title="Dark / light mode"><svg class="a-ic"><use href="#a-theme"/></svg></button>'
       . $pwLink
       . ' · <a href="' . $base . '/admin/logout.php">Sign out</a></div>';
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

    echo '<main class="wrap"><h1>' . Security::e($title) . '</h1>';
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
    }

    // 3 Sep 2026: phone-friendly tables everywhere. On a narrow screen every
    // plain list table gets the card-table treatment automatically (labels
    // read from its own header row), so a page never needs hand-written
    // data-label attributes to be usable on a phone. Tables that carry their
    // own mobile layout (.sm-table) or opt out (.no-card), have no thead, or
    // whose rows do not line up with the header are left exactly as they are.
    echo '<script>' . admin_cards_js() . '</script>';
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
.dt-wrap{overflow:auto;max-height:78vh;border:1px solid var(--line);border-radius:12px;background:var(--card);-webkit-overflow-scrolling:touch}
table.dt{width:100%;border-collapse:separate;border-spacing:0;font-size:13.5px;min-width:900px}
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
@media (max-width:820px){table.dt{min-width:760px;font-size:13px}.dt-wrap{max-height:none;border-radius:10px}.dt-bar .dt-count{margin-left:0}}
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
      el.style.cssText = 'position:fixed;top:66px;right:16px;z-index:50;background:#12264E;color:#fff;'
        + 'padding:12px 16px;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.25);display:flex;'
        + 'align-items:center;gap:10px;font-size:13px;max-width:320px';
      el.innerHTML = '<span></span><button type="button" style="background:#F07C1F;border:0;color:#fff;'
        + 'padding:6px 10px;border-radius:7px;font-weight:700;cursor:pointer;font-size:12px">Refresh</button>';
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
      el.style.cssText = 'position:fixed;top:112px;right:16px;z-index:50;background:#0a6b3b;color:#fff;'
        + 'padding:12px 16px;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.25);display:flex;'
        + 'align-items:center;gap:10px;font-size:13px;max-width:320px';
      el.innerHTML = '<span></span><button type="button" style="background:#fff;border:0;color:#0a6b3b;'
        + 'padding:6px 10px;border-radius:7px;font-weight:700;cursor:pointer;font-size:12px">Refresh</button>';
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

/** Small helper: a coloured status pill. */
function admin_pill(string $status): string
{
    $map = [
        'pending'    => ['#8a6d00', '#fff4d1', 'Pending'],
        'confirmed'  => ['#0a6b3b', '#d7f4e3', 'Confirmed'],
        'verified'   => ['#0a6b3b', '#d7f4e3', 'Verified'],
        'cancelled'  => ['#8a1f1f', '#f7dcdc', 'Cancelled'],
        'rejected'   => ['#8a1f1f', '#f7dcdc', 'Rejected'],
        'expired'    => ['#555',    '#e7e7e7', 'Expired'],
        'cod_pending'=> ['#7a4a00', '#ffe6c7', 'Pay at counter'],
    ];
    [$fg, $bg, $label] = $map[$status] ?? ['#333', '#eee', ucfirst($status)];
    return '<span class="pill" style="color:' . $fg . ';background:' . $bg . '">' . Security::e($label) . '</span>';
}

/**
 * Admin SVG icon sprite — one place, every admin page. Rendered once
 * inside admin_header() right after <body>, then referenced with
 * <svg class="a-ic"><use href="#a-KEY"/></svg>. Stroke inherits
 * currentColor via CSS so the sidebar's on/off palette themes the icons.
 */
function admin_sprite(): string
{
    return <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="0" height="0" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false">
<defs>
<symbol id="a-dashboard" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 16V9M12 16v-5M17 16v-9"/></symbol>
<symbol id="a-chevron" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></symbol>
<symbol id="a-ticket-alt" viewBox="0 0 24 24"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v2M13 11v2M13 17v2"/></symbol>
<symbol id="a-doc" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h6"/></symbol>
<symbol id="a-user-solo" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a8 8 0 0 1 16 0v1"/></symbol>
<symbol id="a-notepad" viewBox="0 0 24 24"><path d="M8 3h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M9 8h6M9 12h6M9 16h4"/></symbol>
<symbol id="a-chart-up" viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></symbol>
<symbol id="a-card" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/></symbol>
<symbol id="a-refund" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3.5-7.1"/><polyline points="3 3 3 8 8 8"/><path d="M12 8v4l3 2"/></symbol>
<symbol id="a-ticket" viewBox="0 0 24 24"><path d="M2 9a3 3 0 0 1 0 6v2a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-2a3 3 0 0 1 0-6V7a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2z"/><path d="M13 5v14"/></symbol>
<symbol id="a-clipboard" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M9 12h6M9 16h4"/></symbol>
<symbol id="a-mail" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="m2 7 10 6 10-6"/></symbol>
<symbol id="a-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M17.5 3.13a4 4 0 0 1 0 7.75"/></symbol>
<symbol id="a-road" viewBox="0 0 24 24"><path d="M6 3 3 21M18 3l3 18M12 3v3M12 10v3M12 17v3"/></symbol>
<symbol id="a-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol>
<symbol id="a-seat" viewBox="0 0 24 24"><path d="M5 12a3 3 0 0 1 3-3h4v9H8z"/><path d="M12 9v9h4a3 3 0 0 0 3-3v-3a3 3 0 0 0-3-3z"/><path d="M8 21v-3M16 21v-3"/></symbol>
<symbol id="a-camera" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></symbol>
<symbol id="a-msg" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></symbol>
<symbol id="a-trophy" viewBox="0 0 24 24"><path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6"/><path d="M18 9h1.5a2.5 2.5 0 0 0 0-5H18"/><path d="M4 22h16"/><path d="M10 18v4M14 18v4"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/></symbol>
<symbol id="a-star" viewBox="0 0 24 24"><path d="M12 2.6l2.9 5.9 6.5.95-4.7 4.58 1.11 6.47L12 17.45l-5.81 3.05 1.11-6.47L2.6 9.45l6.5-.95L12 2.6Z"/></symbol>
<symbol id="a-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></symbol>
<symbol id="a-user-cog" viewBox="0 0 24 24"><circle cx="10" cy="7" r="4"/><path d="M2 21v-1a6 6 0 0 1 8-5.66"/><circle cx="18" cy="17" r="3"/><path d="M18 12v1M18 21v1M13.76 14.76l.71.71M22.24 21.24l-.71-.71"/></symbol>
<symbol id="a-cog" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></symbol>
<symbol id="a-globe" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></symbol>
<symbol id="a-theme" viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></symbol>
<symbol id="a-key" viewBox="0 0 24 24"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0 3 3L22 7l-3-3m-3.5 3.5L19 4"/></symbol>
<symbol id="a-bus" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="15" rx="3"/><path d="M3 10h18"/><circle cx="7.5" cy="15.5" r="1.5"/><circle cx="16.5" cy="15.5" r="1.5"/><path d="M7 21v-2M17 21v-2"/></symbol>
<symbol id="a-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
<symbol id="a-plus" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v8M8 12h8"/></symbol>
<symbol id="a-calendar-plus" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M12 14v5M9.5 16.5h5"/></symbol>
<symbol id="a-ledger" viewBox="0 0 24 24"><path d="M6 2h11a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><path d="M9 7h6M9 11h6M9 15h4"/></symbol>
</defs>
</svg>
SVG;
}

/** The admin stylesheet (kept here so pages stay self-contained). */
function admin_css(): string
{
    return <<<CSS
*{box-sizing:border-box}
:root{--navy:#12264E;--blue:#2E5FA8;--orange:#F07C1F;--ink:#1b2436;--mut:#6b7688;--line:#e5e9f0;--bg:#f4f6fb;--card:#fff;--head:#fafbfe;--hover:#eef2fa}
:root[data-theme="dark"]{--ink:#e8f0fb;--mut:#93a4be;--line:#22314a;--bg:#0b1220;--card:#111d33;--head:#0f1a2e;--hover:#182741}
body{margin:0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--ink)}
body,.side,.card,.panel,.panel h2,th,.toolbar input,.toolbar select,.btn.ghost{transition:background-color .2s,color .2s,border-color .2s}
.a-ic{display:inline-block;width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;vertical-align:middle;flex:0 0 auto}
.theme-tog{background:none;border:0;color:#fff;font-size:17px;cursor:pointer;padding:4px 8px;border-radius:7px;line-height:1;display:inline-flex;align-items:center}
.theme-tog:hover{background:rgba(255,255,255,.14)}
a{color:var(--blue);text-decoration:none}
.tb{position:sticky;top:0;z-index:30;height:56px;display:flex;align-items:center;gap:12px;padding:0 16px;
    background:var(--navy);color:#fff;box-shadow:0 1px 8px rgba(0,0,0,.15)}
.tb .brand{display:inline-flex;align-items:center;gap:8px;color:#fff;font-weight:700;font-size:16px}
.tb .brand-logo{width:26px;height:26px;object-fit:contain;filter:drop-shadow(0 1px 2px rgba(0,0,0,.3));flex:0 0 auto}
.tb .brand span{color:var(--orange);font-weight:600}
.tb .who{margin-left:auto;font-size:13px;color:#cdd6e6}
.tb .who em{font-style:normal;color:var(--orange)}
.tb .who a{color:#fff;text-decoration:underline}
.tb .menu{display:none;background:none;border:0;color:#fff;font-size:22px;cursor:pointer}
.side{position:fixed;top:56px;left:0;width:210px;height:calc(100vh - 56px);background:var(--card);border-right:1px solid var(--line);
      padding:12px 8px;overflow:auto}
.side a{display:flex;align-items:center;gap:10px;padding:11px 12px;border-radius:9px;color:var(--ink);font-weight:600;font-size:14px}
.side a span{width:22px;text-align:center}
.side a:hover{background:var(--hover)}
.side a.on{background:var(--navy);color:#fff}
.side a.hot{background:linear-gradient(90deg,rgba(240,124,31,.18),transparent 75%);border:1px solid rgba(240,124,31,.55);color:var(--ink)}
.side a.hot:hover{background:rgba(240,124,31,.24)}
.side a.hot.on{background:var(--orange);border-color:var(--orange);color:#fff}
.navbadge{margin-left:auto;background:var(--orange);color:#fff;border-radius:999px;font-size:11px;font-weight:800;padding:1px 7px;line-height:1.5}
.side a.on .navbadge{background:var(--card);color:var(--navy)}

/* Phase 3: sidebar section headers — small uppercase labels that
   break up the 20-item strip into Operations / Sales / People /
   Insights / System groups. Hidden when only one section is visible
   (agent view). */
.side-sec{padding:14px 12px 4px;font-size:10px;font-weight:800;letter-spacing:1.2px;color:var(--mut);text-transform:uppercase;user-select:none}
.side-sec:first-child{padding-top:6px}
/* Collapsible nav groups (simplify pass): section headers become toggles so
   the ~25-item sidebar reads as a few tidy groups. The active section starts
   open (server-set .open); the client restores the admin's remembered set. */
.side-toggle{display:flex;align-items:center;gap:6px;width:100%;background:none;border:0;text-align:left;cursor:pointer;font:inherit}
.side-sec.side-toggle{color:var(--mut)}
.side-toggle:hover{color:var(--ink)}
.side-group .side-items{display:none}
.side-group.open .side-items{display:block}
.nav-caret{margin-left:auto;width:14px;height:14px;transition:transform .15s;opacity:.7}
.side-group.open .nav-caret{transform:rotate(90deg)}
.side-group:first-child .side-toggle{padding-top:6px}
.side-site{margin-top:8px;border-top:1px solid var(--line);padding-top:12px}

/* Phase 3: top-bar global search. Flex 1 so it takes the middle of the
   top bar; suggest dropdown is absolutely positioned below the input.
   On narrow viewports the form wraps under the brand+menu row so the
   input keeps a usable width instead of shrinking to a stub. */
.tb-search{flex:1;max-width:520px;position:relative;display:flex;align-items:center;gap:8px;padding:0 12px;background:rgba(255,255,255,.10);border-radius:22px;height:36px}
.tb-search:focus-within{background:rgba(255,255,255,.18);box-shadow:0 0 0 2px rgba(240,124,31,.35)}
.tb-search .tb-ic{color:#cdd6e6;flex:0 0 auto}
.tb-search input{flex:1;background:transparent;border:0;color:#fff;font-size:13.5px;height:100%;outline:none;padding:0}
.tb-search input::placeholder{color:rgba(255,255,255,.55)}
.tb-sug{position:absolute;top:calc(100% + 6px);left:0;right:0;background:var(--card);color:var(--ink);border:1px solid var(--line);border-radius:12px;box-shadow:0 8px 30px rgba(0,0,0,.15);max-height:60vh;overflow:auto;z-index:40;padding:6px}
.tb-sug .tbs-sec{padding:6px 10px 4px;font-size:10px;font-weight:800;letter-spacing:1px;color:var(--mut);text-transform:uppercase}
.tb-sug a{display:flex;gap:10px;align-items:center;padding:8px 10px;border-radius:8px;color:var(--ink);font-size:13px}
.tb-sug a:hover,.tb-sug a.act{background:var(--hover)}
.tb-sug .tbs-icon{width:22px;text-align:center;color:var(--blue);font-weight:800;flex-shrink:0}
.tb-sug .tbs-meta{color:var(--mut);font-size:11px;margin-left:auto;flex-shrink:0}
.tb-sug .tbs-empty{padding:14px 12px;color:var(--mut);font-size:13px;text-align:center}
.row-highlight{background:#fff4d1 !important;animation:rowpulse 1.6s ease 2}
@keyframes rowpulse{0%,100%{background:#fff4d1}50%{background:#ffe28a}}
/* Dark mode: the cream highlight + near-white --ink would be invisible, so
   give highlighted rows a legible dark palette (static — the pale pulse is
   suppressed since its cream keyframes lose to this !important background). */
:root[data-theme="dark"] .row-highlight{background:#3a2f12 !important;color:#ffe9a8;animation:none}
:root[data-theme="dark"] .row-highlight td,:root[data-theme="dark"] .row-highlight th{color:#ffe9a8}
.wrap{margin-left:210px;padding:22px 26px;max-width:1150px}
.wrap h1{margin:2px 0 18px;font-size:22px}
.scrim{display:none}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:22px}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 18px}
.card .k{font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.4px}
.card .v{font-size:26px;font-weight:800;margin-top:6px}
.card .v small{font-size:13px;color:var(--mut);font-weight:600}
/* House-style hero stat cards (shared since 3 Sep 2026 so new pages such as
   customer-view.php get them without copying index.php's block). Pages that
   still carry their own copy override with identical rules — harmless. */
.dash-hero{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.hcard{border-radius:16px;padding:20px 22px;color:#fff;position:relative;overflow:hidden}
.hcard::after{content:'';position:absolute;right:-18px;top:-18px;width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.1)}
.hcard .hicon{font-size:28px;margin-bottom:8px;display:block;filter:drop-shadow(0 2px 4px rgba(0,0,0,.15))}
.hcard .hk{font-size:12px;text-transform:uppercase;letter-spacing:.5px;opacity:.85}
.hcard .hv{font-size:30px;font-weight:800;margin:4px 0 2px;line-height:1.1}
.hcard .hsub{font-size:12px;opacity:.75}
.hcard .hlink{display:inline-block;margin-top:8px;font-size:12px;font-weight:700;color:#fff;background:rgba(255,255,255,.2);padding:4px 12px;border-radius:20px;text-decoration:none}
.hc-blue{background:linear-gradient(135deg,#2E5FA8,#1a3d6e)}
.hc-green{background:linear-gradient(135deg,#0a8b4b,#065a30)}
.hc-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.hc-red{background:linear-gradient(135deg,#c0392b,#8e2320)}
.hc-navy{background:linear-gradient(135deg,#12264E,#0b1a36)}
.hc-teal{background:linear-gradient(135deg,#00897b,#00695c)}
@media(max-width:900px){.dash-hero{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}}
.panel{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;margin-bottom:22px}
.panel h2{margin:0;padding:14px 18px;font-size:15px;border-bottom:1px solid var(--line);background:var(--head)}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:11px 14px;text-align:left;border-bottom:1px solid var(--line);vertical-align:middle}
th{font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;background:var(--head)}
tr:last-child td{border-bottom:0}
.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700}
.btn{display:inline-block;padding:8px 14px;border-radius:9px;border:1px solid transparent;font-weight:700;font-size:13px;
     cursor:pointer;background:var(--blue);color:#fff}
.btn.ok{background:#0a6b3b}.btn.bad,.btn.danger{background:#b02a2a}.btn.ghost{background:var(--card);border-color:var(--line);color:var(--ink)}
.btn.ghost.danger{background:var(--card);border-color:#e3b4b4;color:#8a1f1f}
.btn:hover{filter:brightness(1.06)}
.row-actions{display:flex;gap:8px;flex-wrap:wrap}
.muted{color:var(--mut)}
.flash{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-weight:600}
.flash.ok{background:#d7f4e3;color:#0a6b3b}.flash.bad{background:#f7dcdc;color:#8a1f1f}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.toolbar input,.toolbar select{padding:9px 12px;border:1px solid var(--line);border-radius:9px;font-size:14px;background:var(--card);color:var(--ink)}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}
/* --- Mobile-first hardening (simplify pass) ---------------------------
   Nothing should push the whole admin page into horizontal scroll on a
   phone; wide tables scroll inside their own box instead. */
/* html only: body{overflow-x:hidden} makes BODY its own scroll container and
   Android then routes vertical swipes to it — the exact "touch did nothing"
   trap removed from the customer site on 2 Sep 2026 (app.css). */
html{overflow-x:hidden}@supports(overflow:clip){html{overflow-x:clip}}
img,pre{max-width:100%}
/* Shared wrapper: <div class="tbl-scroll"><table>…</table></div>. A few
   pages already use this exact class inline — keep the name identical. */
.tbl-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%}
/* Touch targets: on coarse pointers every primary control is >=44px, matching
   the customer site. Admin previously had no such rule. */
@media(pointer:coarse){
  .btn{min-height:44px;display:inline-flex;align-items:center;justify-content:center}
  .toolbar input,.toolbar select,.side a{min-height:44px}
  /* Filter bars that never adopted .toolbar (wallet statement, chalani
     tools, accounting day-book) still deserve tappable controls. */
  .mf-tools input,.mf-tools select,.acc-tools input,.acc-tools select,
  form input[type=date],form select{min-height:44px}
  .theme-tog,.tb .menu{min-width:44px;min-height:44px;justify-content:center;align-items:center}
}
@media(max-width:820px){
  .side{transform:translateX(-100%);transition:transform .2s;z-index:40}
  /* Phones (3 Sep 2026): 16px controls stop iOS Safari zooming on focus;
     tighter gutters give a 390px screen its width back; top-bar links get
     a finger-sized hit area.

     !important added 2026-09-06: without it an inline font-size on the
     field won this rule, so a handful of controls (booking-view note ×2,
     customers reason, trips/trip-dashboard driver+bus selects) still
     zoomed on an iPhone. Scoped to <=820px and to form fields only, so it
     changes nothing on desktop and cannot touch layout — it only lifts a
     sub-16px field up to 16px on a phone. One rule fixes the whole class,
     including any inline font-size added in future, without editing the
     money-page markup. */
  .wrap{padding:14px 12px}
  input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=range]),select,textarea{font-size:16px !important}
  .tb .who a{display:inline-block;padding:10px 4px}
  body.nav-open .side{transform:none}
  body.nav-open .scrim{display:block;position:fixed;inset:56px 0 0;background:rgba(0,0,0,.35);z-index:35}
  .wrap{margin-left:0}
  .tb .menu{display:block}
  .tb .who{font-size:11px}
  /* Most admin list tables live inside a .panel; on a phone let the panel
     scroll its wide table horizontally instead of clipping it (overflow:hidden)
     or forcing the whole page to scroll. Vertical stays clipped for the
     rounded corners. Covers the pages that lack an explicit .tbl-scroll. */
  .panel{overflow-x:auto}
  /* Phase 3 mobile: the search bar shrinks to a magnifier button — tapping
     it expands into a full-width overlay dropdown so the top bar keeps its
     brand + who strip readable. The input inside stays operable via keyboard;
     autosuggest still opens below. */
  .tb-search{max-width:180px;order:99;flex-basis:100%;margin-top:8px;height:34px}
  .tb{flex-wrap:wrap;height:auto;padding-top:8px;padding-bottom:8px;gap:8px}
  /* Table-to-card pattern: on narrow screens, tables with .card-table class
     turn each row into a stacked card with <th> labels from data-label. */
  table.card-table thead{display:none}
  table.card-table tbody tr{display:block;border:1px solid var(--line);border-radius:10px;padding:12px;margin-bottom:10px;background:var(--card)}
  table.card-table tbody td{display:flex;justify-content:space-between;align-items:center;padding:4px 0;border:none;font-size:14px}
  table.card-table tbody td::before{content:attr(data-label);font-weight:700;font-size:12px;color:var(--mut);margin-right:12px;text-transform:uppercase;letter-spacing:.3px;white-space:nowrap}
  /* Advanced sections hidden by default on mobile */
  details.advanced-section{margin-top:12px}
  details.advanced-section>summary{font-size:13px;font-weight:600;cursor:pointer;color:var(--blue);padding:8px 0;list-style:none}
  details.advanced-section>summary::before{content:'▶ ';font-size:11px}
  details.advanced-section[open]>summary::before{content:'▼ '}
}
/* ── Toast animation — auto-fade after 4s ── */
.flash{animation:flashIn .3s ease}
@keyframes flashIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:none}}
CSS;
}
