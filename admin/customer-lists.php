<?php
/**
 * admin/customer-lists.php — customers by who is looking (10 Oct 2026).
 *
 *   CEO (superadmin)      every customer
 *   agent / counter       only the customers of their own sales
 *   other staff           only customers who travel on the routes assigned
 *                         to them (Assign Agents → agent_route_permissions);
 *                         no route assigned = nothing (ask the CEO)
 *
 * A customer = one contact number: the latest passenger name on it, how many
 * tickets, last travel, total paid, routes. Same admin session as every page
 * (admin/_guard.php). Read-only; WhatsApp click-to-chat per row.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');

$e     = static fn($v): string => Security::e((string) $v);
$me    = (int) ($admin['id'] ?? 0);
$role  = (string) ($admin['role'] ?? '');
$isCeo = Auth::isSuperadmin();
$own   = in_array($role, ['agent', 'counter'], true);

/* What this viewer may see. */
$routeIds = [];
$scopeNote = '';
if ($isCeo) {
    $scopeNote = 'CEO — सबै ग्राहक · all customers';
} elseif ($own) {
    $scopeNote = 'तपाईंले बेचेका टिकटका ग्राहक मात्र · your own customers only';
} else {
    try {
        foreach (Database::fetchAll('SELECT route_id FROM agent_route_permissions WHERE admin_id = :a', ['a' => $me]) as $r) {
            $routeIds[] = (int) $r['route_id'];
        }
    } catch (Throwable $ignored) {
    }
    $scopeNote = $routeIds === []
        ? 'तपाईंलाई कुनै रुट दिइएको छैन — CEO ले Assign Agents मा रुट दिनुपर्छ · no route assigned to you'
        : 'तपाईंको रुटका ग्राहक मात्र · customers of your routes only';
}

$routes = [];
try {
    $routes = Database::fetchAll('SELECT id, route_code, from_city, to_city FROM routes WHERE is_active = 1 ORDER BY sort_order, id');
} catch (Throwable $ignored) {
}
if (!$isCeo && !$own) {
    $routes = array_values(array_filter($routes, static fn(array $r): bool => in_array((int) $r['id'], $routeIds, true)));
}

$q      = Security::clean((string) ($_GET['q'] ?? ''), 60);
$route  = (int) ($_GET['route'] ?? 0);
$days   = max(1, min(730, (int) ($_GET['days'] ?? 180)));

$where  = ["b.status IN ('confirmed','completed','pending')", 'b.created_at >= NOW() - INTERVAL ' . $days . ' DAY',
           "b.contact_phone IS NOT NULL AND b.contact_phone <> '' AND b.contact_phone <> '0000000000'"];
$params = [];
if ($own) {
    $where[] = 'b.sold_by_admin_id = :me';
    $params['me'] = $me;
} elseif (!$isCeo) {
    if ($routeIds === []) {
        $where[] = '1 = 0';
    } else {
        $where[] = 's.route_id IN (' . implode(',', array_map('intval', $routeIds)) . ')';
    }
}
if ($route > 0 && ($isCeo || $own || in_array($route, $routeIds, true))) {
    $where[] = 's.route_id = :r';
    $params['r'] = $route;
}
if ($q !== '') {
    $where[] = '(b.contact_phone LIKE :q OR EXISTS (SELECT 1 FROM booking_passengers p2 WHERE p2.booking_id = b.id AND p2.full_name LIKE :q2))';
    $params['q']  = '%' . $q . '%';
    $params['q2'] = '%' . $q . '%';
}

$rows = [];
try {
    $rows = Database::fetchAll(
        "SELECT b.contact_phone AS phone,
                MAX(b.contact_country_code) AS cc,
                COUNT(DISTINCT b.id) AS tickets,
                SUM(CASE WHEN b.status IN ('confirmed','completed') THEN b.total_amount ELSE 0 END) AS paid,
                MAX(bl.travel_date) AS last_travel,
                MAX(b.id) AS last_id,
                GROUP_CONCAT(DISTINCT CONCAT(r.from_city, '→', r.to_city) ORDER BY r.id SEPARATOR ', ') AS routes
           FROM bookings b
           JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
           JOIN schedules s ON s.id = bl.schedule_id
           JOIN routes r ON r.id = s.route_id
          WHERE " . implode(' AND ', $where) . "
          GROUP BY b.contact_phone
          ORDER BY last_travel DESC, last_id DESC
          LIMIT 300",
        $params
    );
} catch (Throwable $ex) {
    Logger::warning('customer lists query failed: ' . $ex->getMessage(), [], 'admin');
}
// The latest passenger name per customer (one query, not one per row).
$names = [];
$ids = array_map(static fn(array $r): int => (int) $r['last_id'], $rows);
if ($ids !== []) {
    foreach (Database::fetchAll(
        'SELECT booking_id, full_name FROM booking_passengers WHERE booking_id IN (' . implode(',', $ids) . ') ORDER BY is_primary DESC, id ASC'
    ) as $p) {
        $names[(int) $p['booking_id']] ??= (string) $p['full_name'];
    }
}

admin_header('Customer lists', 'customer-lists');
?>
<style>
.cl-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:0 0 12px}
.cl-bar label{display:flex;flex-direction:column;font-size:12px;gap:3px}
.cl-note{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px 14px;margin:0 0 12px;font-size:13.5px}
.cl-wrap{overflow-x:auto}
</style>
<div class="cl-note">👁️ <b><?= $e($scopeNote) ?></b> · <?= count($rows) ?> customer(s)<?= count($rows) >= 300 ? ' (first 300 — narrow the search)' : '' ?></div>
<form class="cl-bar" method="get">
  <label>Search · खोज<input type="search" name="q" value="<?= $e($q) ?>" placeholder="name or mobile"></label>
  <label>Route · रुट<select name="route"><option value="0">All my routes</option>
    <?php foreach ($routes as $r): ?><option value="<?= (int) $r['id'] ?>"<?= $route === (int) $r['id'] ? ' selected' : '' ?>><?= $e($r['from_city'] . ' → ' . $r['to_city']) ?></option><?php endforeach; ?>
  </select></label>
  <label>Period · अवधि<select name="days"><?php foreach ([30 => '30 days', 90 => '90 days', 180 => '6 months', 365 => '1 year', 730 => '2 years'] as $d => $l): ?><option value="<?= $d ?>"<?= $days === $d ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
  <button class="btn" type="submit">Show</button>
</form>
<div class="panel cl-wrap">
  <table>
    <thead><tr><th>Customer · ग्राहक</th><th>Mobile</th><th>Tickets</th><th>Paid</th><th>Last travel</th><th>Routes</th><th></th></tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="7" class="muted">No customers in this view.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r):
        $ph = preg_replace('/\D+/', '', (string) $r['phone']) ?? '';
        $cc = strtoupper((string) ($r['cc'] ?? '')) === 'NP' || (string) ($r['cc'] ?? '') === '977' ? '977' : '91';
        $wa = strlen($ph) === 10 ? $cc . $ph : $ph; ?>
      <tr>
        <td><?= $e($names[(int) $r['last_id']] ?? '-') ?></td>
        <td class="mono"><?= $e($r['phone']) ?></td>
        <td><?= (int) $r['tickets'] ?></td>
        <td><?= $e(inr((float) $r['paid'])) ?></td>
        <td style="white-space:nowrap"><?= $e((string) $r['last_travel']) ?></td>
        <td><small><?= $e(mb_substr((string) $r['routes'], 0, 80)) ?></small></td>
        <td style="white-space:nowrap"><?php if ($wa !== ''): ?><a class="btn ghost sm" target="_blank" rel="noopener" href="https://wa.me/<?= $e($wa) ?>">WhatsApp</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php admin_footer();
