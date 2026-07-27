<?php
/**
 * admin/routes.php — routes overview with live seat availability.
 *
 * Route content (stops, timings, fares) is managed from the in-app admin
 * console; this page is the server-side operational view: which routes
 * are active and how full the next few departures are.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
admin_boot('routes.view');

$base   = rtrim(APP_URL, '/');
$routes = Database::fetchAll(
    "SELECT r.*, b.bus_name, b.bus_number
       FROM routes r LEFT JOIN buses b ON b.id=r.bus_id
      ORDER BY r.is_active DESC, r.sort_order, r.id"
);

$today = todayISO();

admin_header('Routes', 'routes');
?>
<div class="panel">
  <h2><?= count($routes) ?> route<?= count($routes) === 1 ? '' : 's' ?> · availability for today (<?= Security::e(formatDate($today)) ?>)</h2>
  <table>
    <thead><tr><th>Code</th><th>Route</th><th>Bus</th><th>Type</th><th>Departs</th><th>Base fare</th><th>Seats free today</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($routes as $r):
      $avail = Seats::availability((int) $r['id'], $today, $r['coach_type'] === 'sleeper' ? 'sharing' : 'seater');
    ?>
      <tr style="<?= (int) $r['is_active'] === 1 ? '' : 'opacity:.5' ?>">
        <td class="mono"><?= Security::e((string) $r['route_code']) ?></td>
        <td><?= Security::e($r['from_city'] . ' → ' . $r['to_city']) ?></td>
        <td><?= Security::e($r['bus_name'] ?? '—') ?><div class="muted mono"><?= Security::e($r['bus_number'] ?? '') ?></div></td>
        <td><?= Security::e(ucfirst((string) $r['coach_type'])) ?></td>
        <td><?= Security::e(formatTime($r['dep_time'] ?? null)) ?></td>
        <td><?= Security::e(inr((float) $r['base_fare'])) ?></td>
        <td><strong><?= (int) ($avail['availableCount'] ?? 0) ?></strong><span class="muted"> / <?= (int) ($avail['total'] ?? 0) ?></span></td>
        <td><?= (int) $r['is_active'] === 1 ? admin_pill('confirmed') : admin_pill('expired') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="muted">To edit stops, timings and fares, open the in-app admin console (Admin mode on the website) — those changes sync to the server through your signed-in session.</p>
<?php
admin_footer();
