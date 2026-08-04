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

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';

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
 * @return array<int, array{href:string, icon:string, label:string, perm:string}>
 */
function admin_nav(): array
{
    $all = [
        ['href' => 'index.php',    'icon' => '📊', 'label' => 'Dashboard',      'perm' => 'dashboard.view'],
        ['href' => 'analytics.php','icon' => '📈', 'label' => 'Analytics',      'perm' => 'dashboard.view'],
        ['href' => 'payments.php', 'icon' => '💳', 'label' => 'Verify Payments','perm' => 'payments.view'],
        ['href' => 'bookings.php', 'icon' => '🎫', 'label' => 'Bookings',       'perm' => 'bookings.view'],
        ['href' => 'enquiries.php','icon' => '📝', 'label' => 'Enquiries',      'perm' => 'bookings.view'],
        ['href' => 'routes.php',   'icon' => '🛣️', 'label' => 'Routes',         'perm' => 'routes.view'],
        ['href' => 'scan.php',     'icon' => '📷', 'label' => 'Scan Ticket',    'perm' => 'tickets.scan'],
        ['href' => 'settings.php', 'icon' => '⚙️', 'label' => 'Settings',       'perm' => 'dashboard.view'],
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
    $base  = rtrim(APP_URL, '/');
    $company = Settings::getString('company_name', APP_NAME);

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . Security::e($title) . ' · ' . Security::e($company) . ' Admin</title>';
    echo '<link rel="icon" href="data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">🚌</text></svg>') . '">';
    echo '<style>' . admin_css() . '</style>';
    // Apply saved/system theme before <body> paints (no flash), + the toggle.
    echo '<script>(function(){try{var t=localStorage.getItem("shg_admin_theme");'
       . 'if(t!=="dark"&&t!=="light")t=(window.matchMedia&&matchMedia("(prefers-color-scheme:dark)").matches)?"dark":"light";'
       . 'document.documentElement.setAttribute("data-theme",t);}catch(e){}})();'
       . 'function shgTheme(){try{var d=document.documentElement,'
       . 'n=d.getAttribute("data-theme")==="dark"?"light":"dark";'
       . 'd.setAttribute("data-theme",n);localStorage.setItem("shg_admin_theme",n);}catch(e){}}</script>';
    echo '</head><body>';

    // Top bar
    echo '<header class="tb">';
    echo '<button class="menu" onclick="document.body.classList.toggle(\'nav-open\')" aria-label="Menu">☰</button>';
    echo '<a class="brand" href="' . $base . '/admin/index.php">🚌 ' . Security::e($company) . ' <span>Admin</span></a>';
    echo '<div class="who">' . $name . ' <em>' . $role . '</em> · '
       . '<button type="button" class="theme-tog" onclick="shgTheme()" aria-label="Toggle dark mode" title="Dark / light mode">🌓</button>'
       . ' · <a href="' . $base . '/admin/logout.php">Sign out</a></div>';
    echo '</header>';

    // Sidebar
    echo '<nav class="side">';
    foreach (admin_nav() as $item) {
        $on    = ($active !== '' && str_contains($item['href'], $active)) ? ' class="on"' : '';
        $badge = $item['href'] === 'payments.php'
            ? '<span class="navbadge" id="navBadgePayments" style="display:none"></span>'
            : '';
        echo '<a' . $on . ' href="' . $base . '/admin/' . $item['href'] . '">'
           . '<span>' . $item['icon'] . '</span>' . Security::e($item['label']) . $badge . '</a>';
    }
    echo '<a href="' . $base . '/" target="_blank"><span>🌐</span>View site</a>';
    echo '</nav>';

    echo '<main class="wrap"><h1>' . Security::e($title) . '</h1>';
}

function admin_footer(): void
{
    echo '</main>';
    echo '<div class="scrim" onclick="document.body.classList.remove(\'nav-open\')"></div>';

    // Live pending-payments badge + chime, so staff don't have to keep
    // hitting refresh. Only for roles that can actually act on payments.
    if (Auth::can('payments.view')) {
        echo '<script>' . admin_poll_js() . '</script>';
    }

    echo '</body></html>';
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

  function poll() {
    fetch('pending-count.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || typeof j.pending !== 'number') return;
        var badge = document.getElementById('navBadgePayments');
        if (badge) {
          if (j.pending > 0) { badge.textContent = j.pending; badge.style.display = 'inline-block'; }
          else { badge.style.display = 'none'; }
        }
        var last = parseInt(sessionStorage.getItem(KEY_LAST) || '-1', 10);
        if (last >= 0 && j.pending > last) { beep(); showBanner(j.pending - last); }
        sessionStorage.setItem(KEY_LAST, String(j.pending));
      })
      .catch(function () {});
  }
  poll();
  setInterval(poll, 20000);
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

/** The admin stylesheet (kept here so pages stay self-contained). */
function admin_css(): string
{
    return <<<CSS
*{box-sizing:border-box}
:root{--navy:#12264E;--blue:#2E5FA8;--orange:#F07C1F;--ink:#1b2436;--mut:#6b7688;--line:#e5e9f0;--bg:#f4f6fb;--card:#fff;--head:#fafbfe;--hover:#eef2fa}
:root[data-theme="dark"]{--ink:#e8f0fb;--mut:#93a4be;--line:#22314a;--bg:#0b1220;--card:#111d33;--head:#0f1a2e;--hover:#182741}
body{margin:0;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--ink)}
body,.side,.card,.panel,.panel h2,th,.toolbar input,.toolbar select,.btn.ghost{transition:background-color .2s,color .2s,border-color .2s}
.theme-tog{background:none;border:0;color:#fff;font-size:17px;cursor:pointer;padding:2px 7px;border-radius:7px;line-height:1}
.theme-tog:hover{background:rgba(255,255,255,.14)}
a{color:var(--blue);text-decoration:none}
.tb{position:sticky;top:0;z-index:30;height:56px;display:flex;align-items:center;gap:12px;padding:0 16px;
    background:var(--navy);color:#fff;box-shadow:0 1px 8px rgba(0,0,0,.15)}
.tb .brand{color:#fff;font-weight:700;font-size:16px}
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
.navbadge{margin-left:auto;background:var(--orange);color:#fff;border-radius:999px;font-size:11px;font-weight:800;padding:1px 7px;line-height:1.5}
.side a.on .navbadge{background:var(--card);color:var(--navy)}
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
.panel{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;margin-bottom:22px}
.panel h2{margin:0;padding:14px 18px;font-size:15px;border-bottom:1px solid var(--line);background:var(--head)}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{padding:11px 14px;text-align:left;border-bottom:1px solid var(--line);vertical-align:middle}
th{font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;background:var(--head)}
tr:last-child td{border-bottom:0}
.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700}
.btn{display:inline-block;padding:8px 14px;border-radius:9px;border:1px solid transparent;font-weight:700;font-size:13px;
     cursor:pointer;background:var(--blue);color:#fff}
.btn.ok{background:#0a6b3b}.btn.bad{background:#b02a2a}.btn.ghost{background:var(--card);border-color:var(--line);color:var(--ink)}
.btn:hover{filter:brightness(1.06)}
.row-actions{display:flex;gap:8px;flex-wrap:wrap}
.muted{color:var(--mut)}
.flash{padding:12px 16px;border-radius:10px;margin-bottom:16px;font-weight:600}
.flash.ok{background:#d7f4e3;color:#0a6b3b}.flash.bad{background:#f7dcdc;color:#8a1f1f}
.toolbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.toolbar input,.toolbar select{padding:9px 12px;border:1px solid var(--line);border-radius:9px;font-size:14px;background:var(--card);color:var(--ink)}
.mono{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}
@media(max-width:820px){
  .side{transform:translateX(-100%);transition:transform .2s;z-index:40}
  body.nav-open .side{transform:none}
  body.nav-open .scrim{display:block;position:fixed;inset:56px 0 0;background:rgba(0,0,0,.35);z-index:35}
  .wrap{margin-left:0}
  .tb .menu{display:block}
  .tb .who{font-size:11px}
}
CSS;
}
