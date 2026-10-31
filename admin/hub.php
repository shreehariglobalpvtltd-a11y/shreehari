<?php
/**
 * admin/hub.php — Control Center (Oct 2026, Agent Pro).
 *
 * Owner: "admin panel ko category lai sabai lai milayer, category-wise card
 * banayer, hernu chalaunu bujhnu sajilo hos, mobile bata ni chalos."
 *
 * Every screen the signed-in person may open — read from admin_nav(), the
 * same permission-filtered list the sidebar draws, so nothing here can show
 * a page the menu would hide — grouped into cards by category, each with a
 * one-line purpose and live counts where a count means "work waiting".
 * Above them: the numbers that matter today, and a "needs you" list that
 * only shows what is not zero.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro.php';
$admin = admin_boot();

$counts = AgentPro::counts();

/* 31 Oct 2026: AgentPro::counts() is company-wide, but this page opens for every signed-in role. The
   booking figures follow the rule admin/bookings.php lists by: an agent sees only the tickets they sold
   (Auth::bookingScopeAdminId()), a counter clerk only their desk when desk isolation is on
   (Auth::deskScopeCode(), as admin/_booking_filters.php and admin/payments.php), the office the company. */
$hubScopeId = Auth::bookingScopeAdminId();
$hubDesk    = null;
if ($hubScopeId === null) {
    require_once INCLUDE_PATH . '/counterdesk.php';
    $d = Auth::deskScopeCode();
    $hubDesk = ($d !== null && CounterDesk::stampColumn()) ? $d : null;
}
$hubCount = static function (string $statusSql, bool $todayOnly) use ($hubScopeId, $hubDesk): int {
    $where  = [$statusSql];
    $params = [];
    if ($todayOnly) {
        $where[] = 'DATE(b.created_at) = CURDATE()';
    }
    if ($hubScopeId !== null) {
        $where[] = 'b.sold_by_admin_id = :hubScope';
        $params['hubScope'] = $hubScopeId;
    } elseif ($hubDesk !== null) {
        $where[] = CounterDesk::scopeClause('b', 'hubDesk');
        $params['hubDesk'] = $hubDesk;
    }
    try {
        return (int) Database::scalar('SELECT COUNT(*) FROM bookings b WHERE ' . implode(' AND ', $where), $params, 0);
    } catch (Throwable $e) {
        return 0;
    }
};
if ($hubScopeId !== null || $hubDesk !== null) {
    $counts['todayTickets'] = $hubCount("b.status IN ('confirmed','completed')", true);
    $counts['pendingPay']   = $hubCount("b.status = 'pending'", false);
}
/* An agent never sees the office's queues, even if a permission override hands them the box. */
if ($hubScopeId !== null) {
    foreach (['applications', 'withdrawals', 'deposits', 'agents', 'staff', 'kycWaiting', 'aiQueue', 'waLinks'] as $k) {
        $counts[$k] = 0;
    }
}
$failedWa = 0;
try {
    $failedWa = (int) Database::scalar("SELECT COUNT(*) FROM message_logs WHERE channel = 'whatsapp' AND status = 'failed' AND created_at >= CURDATE()", [], 0);
} catch (Throwable $e) {
}
/* 31 Oct 2026 (review): the failed-WhatsApp count is a company figure - never an agent's. */
if ($hubScopeId !== null) {
    $failedWa = 0;
}

/* What each category is for — one line, plain words. */
$meta = [
    ''          => ['Home', 'Overview and today', 'home', 't-navy'],
    'Tickets'   => ['Tickets', 'Sell, find, change and print tickets', 'ticket', 't-blue'],
    'Agents'    => ['Agents & Team', 'Agents, ID cards, wallets, staff', 'users', 't-gold'],
    'Customers' => ['Customers', 'Passengers and enquiries', 'user', 't-teal'],
    'Buses'     => ['Buses', 'Fleet, calendar, schedules, crew', 'bus', 't-navy'],
    'Routes'    => ['Routes', 'Routes, stops and fares', 'route', 't-violet'],
    'Payments'  => ['Payments', 'Verify money, refunds, offers', 'card', 't-green'],
    'Reports'   => ['Reports', 'Analytics, accounts, messages', 'chart', 't-orange'],
    'Settings'  => ['Settings', 'Company, AI, security, health', 'cog', 't-red'],
    'Map'       => ['Map & Live Fleet', 'Where every bus is, right now', 'map-pin', 't-teal'],
    // The five numbered categories of the sidebar (admin/_guard.php, 29 Oct 2026).
    '1. Staff & Agent Management'   => ['Staff & Agents', 'Logins, codes, KYC, approvals', 'users', 't-gold'],
    '2. Location Management'        => ['Locations', 'Stops, counters, agents by place, map', 'map-pin', 't-teal'],
    '3. Ticketing & Bus Management' => ['Tickets & Buses', 'Sell, change, cancel; fleet and routine', 'ticket', 't-blue'],
    '4. Financial Management'       => ['Money', 'Payments, wallets, salary, accounts', 'card', 't-green'],
    '5. Settings & Configuration'   => ['Settings', 'Company, WhatsApp, AI, logs, backups', 'cog', 't-red'],
];
/* Badges: how much work waits behind a tile. (staff.php has no AgentPro
   applications, so it carries no badge — the queue lives on team.php.) */
$badges = [
    'payments.php' => $counts['pendingPay'],
    'team.php'     => $counts['applications'] + $counts['withdrawals'] + $counts['deposits'],
    'ai-local.php' => $counts['aiQueue'],
];
$canOffice = Auth::isSuperadmin() || (string) ($admin['role'] ?? '') === 'manager' || Auth::can('staff.manage');

$groups = [];
foreach (admin_nav() as $item) {
    if (($item['href'] ?? '') === 'hub.php') {
        continue;
    }
    $groups[(string) ($item['section'] ?? '')][] = $item;
}
$url = static fn(array $i): string => str_starts_with((string) $i['href'], '/') ? (string) $i['href'] : '/admin/' . $i['href'];

$hour = (int) date('G');
$hello = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', trim((string) ($admin['full_name'] ?? '')))[0] ?? '';

admin_header('Control Center', 'hub.php');
echo '<link rel="stylesheet" href="/assets/agentpro/admin.css?v=' . (int) @filemtime(ASSET_PATH . '/agentpro/admin.css') . '">';
?>
<div class="ap-a">
  <div class="cc-hello">
    <div><h2><?= Security::e($hello . ($first !== '' ? ', ' . $first : '')) ?> 🙏</h2>
      <p><?= Security::e(formatDate(date('Y-m-d'), 'l, j F Y')) ?> · <?= Security::e(Settings::getString('company_name', APP_NAME)) ?></p></div>
    <div class="cc-quick">
      <?php if (Auth::can('bookings.view')): ?><a class="primary" href="/admin/quick-ticket.php"><svg class="a-ic sm"><use href="#a-bolt"/></svg> New ticket</a><?php endif; ?>
      <?php if (Auth::can('tickets.scan')): ?><a href="/admin/scan.php"><svg class="a-ic sm"><use href="#a-scan"/></svg> Scan</a><?php endif; ?>
      <?php if ($canOffice): ?><a href="/join.php?office=1"><svg class="a-ic sm"><use href="#a-plus"/></svg> नयाँ सदस्य · Add member</a><?php endif; ?>
      <a href="/me/"><svg class="a-ic sm"><use href="#a-id-card"/></svg> My ID</a>
    </div>
  </div>

  <div class="cc-kpis">
    <?php
    $kpis = [
        [$hubScopeId !== null ? 'My tickets today · मेरो आजको टिकट' : ($hubDesk !== null ? 'Desk tickets today · काउन्टरको आजको टिकट' : 'Tickets today'),
            $counts['todayTickets'], '/admin/bookings.php', 'bookings.view', false],
        [$hubScopeId !== null ? 'My payments to verify · मेरो भुक्तानी जाँच' : 'Payments to verify', $counts['pendingPay'], '/admin/payments.php', 'payments.view', $counts['pendingPay'] > 0],
        ['New applications', $counts['applications'], '/admin/team.php?tab=approvals', 'commissions.view', $counts['applications'] > 0],
        ['Withdrawals', $counts['withdrawals'], '/admin/team.php?tab=money', 'commissions.view', $counts['withdrawals'] > 0],
        ['Payments in', $counts['deposits'], '/admin/team.php?tab=money', 'commissions.view', $counts['deposits'] > 0],
        ['Live buses', $counts['liveBuses'], '/admin/map.php', 'schedules.view', false],
        ['Active agents', $counts['agents'], '/admin/team.php?tab=agents', 'commissions.view', false],
    ];
    foreach ($kpis as [$label, $n, $href, $perm, $hot]):
        if (!Auth::can($perm)) { continue; } ?>
      <a class="cc-kpi<?= $hot ? ' hot' : '' ?>" href="<?= Security::e($href) ?>"><small><?= Security::e($label) ?></small><b><?= (int) $n ?></b></a>
    <?php endforeach; ?>
  </div>

  <?php
  $attn = [];
  if ($counts['applications'] > 0 && Auth::can('commissions.view')) { $attn[] = [$counts['applications'], 'applications waiting for one-tap approval', '/admin/team.php?tab=approvals']; }
  if ($counts['withdrawals'] > 0 && Auth::can('commissions.view'))  { $attn[] = [$counts['withdrawals'], 'withdrawal requests to pay', '/admin/team.php?tab=money']; }
  if ($counts['deposits'] > 0 && Auth::can('commissions.view'))     { $attn[] = [$counts['deposits'], 'payments from agents to match in the bank', '/admin/team.php?tab=money']; }
  if ($counts['pendingPay'] > 0 && Auth::can('payments.view'))      { $attn[] = [$counts['pendingPay'], 'customer payments to verify', '/admin/payments.php']; }
  if ($counts['kycWaiting'] > 0 && Auth::can('staff.manage'))       { $attn[] = [$counts['kycWaiting'], 'KYC documents to check', '/admin/team.php?tab=agents&kyc=submitted']; }
  if ($failedWa > 0 && Auth::can('dashboard.view'))                  { $attn[] = [$failedWa, 'WhatsApp messages failed today', '/admin/messages-log.php']; }
  if ($attn !== []): ?>
  <div class="cc-attn" aria-label="Needs attention">
    <?php foreach ($attn as [$n, $what, $href]): ?>
      <a href="<?= Security::e($href) ?>"><span class="n"><?= (int) $n ?></span><span><?= Security::e($what) ?></span><span class="go"><svg class="a-ic sm"><use href="#a-arrow-right"/></svg></span></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="cc-grid">
    <?php foreach ($groups as $sec => $items):
        [$title, $sub, $icon, $tone] = $meta[$sec] ?? [$sec, '', 'grid', 't-navy']; ?>
      <section class="cc-cat">
        <header><span class="ic <?= Security::e($tone) ?>"><svg class="a-ic"><use href="#a-<?= Security::e($icon) ?>"/></svg></span>
          <div><b><?= Security::e($title) ?></b><small><?= Security::e($sub) ?></small></div></header>
        <div class="cc-tiles">
          <?php foreach ($items as $i): $b = (int) ($badges[$i['href']] ?? 0); ?>
            <a class="cc-tile" href="<?= Security::e($url($i)) ?>"><svg class="a-ic sm"><use href="#a-<?= Security::e((string) $i['icon']) ?>"/></svg><span><?= Security::e((string) $i['label']) ?></span><?= $b > 0 ? '<span class="b">' . $b . '</span>' : '' ?></a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</div>
<?php admin_footer();
