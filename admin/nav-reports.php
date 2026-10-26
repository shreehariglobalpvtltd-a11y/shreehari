<?php
/**
 * admin/nav-reports.php - road reports from SHARI NAV riders (Phase 2, 22 Oct 2026).
 *
 * Owner: "admin can delete in /admin". Every report still on the map, plus
 * the ones that ended in the last 24 h. Delete takes a report off every open
 * map at once (pushed on channel "rep"). Reading is dashboard.view, deleting
 * needs the settings permission (the same rule as admin/notifications.php).
 * Riders stay anonymous here too: only the kind, the place and the votes.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/navlive.php';
$admin = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();

$base  = '';
$flash = null;
$names = [
    'accident' => 'Accident · दुर्घटना', 'police' => 'Police · प्रहरी', 'jam' => 'Traffic jam · जाम',
    'roadwork' => 'Road work · सडक निर्माण', 'closed' => 'Road closed · बाटो बन्द', 'flood' => 'Flood · बाढी',
    'landslide' => 'Landslide · पहिरो', 'pothole' => 'Pothole · खाल्डो', 'animal' => 'Animal on road · जनावर',
    'fog' => 'Fog · हुस्सु', 'border' => 'Border queue · नाकामा लाइन',
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canEdit) {
        $flash = ['bad', 'Only the office can delete reports.'];
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $row = $id > 0 ? Database::fetch('SELECT * FROM nav_reports WHERE id = :id', ['id' => $id]) : null;
        if ($row === null) {
            $flash = ['bad', 'That report is already gone.'];
        } else {
            Database::update('nav_reports', ['status' => 'removed', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
            Logger::audit('nav_report.delete', 'nav_reports', (string) $id, ['status' => $row['status']],
                ['status' => 'removed'], 'deleted by admin #' . $admin['id']);
            $out = NavLive::reportOut(Database::fetch('SELECT * FROM nav_reports WHERE id = :id', ['id' => $id]));
            NavLive::publish('rep', ['op' => 'del', 'r' => $out]);
            $flash = ['ok', 'Report #' . $id . ' deleted — it is off every open map now.'];
        }
    }
}

$rows = Database::fetchAll(
    "SELECT * FROM nav_reports
      WHERE (status = 'active' AND expires_at > :now) OR updated_at > :since
      ORDER BY (status = 'active' AND expires_at > :now2) DESC, created_at DESC LIMIT 300",
    ['now' => date('Y-m-d H:i:s'), 'since' => date('Y-m-d H:i:s', time() - 86400), 'now2' => date('Y-m-d H:i:s')]
);
$live = 0;
foreach ($rows as $r) {
    if ((string) $r['status'] === 'active' && strtotime((string) $r['expires_at']) > time()) {
        $live++;
    }
}

admin_header('Road reports', 'nav-reports');
?>

<?php if ($flash !== null): ?>
  <p class="pill" style="display:block;padding:10px 12px;background:<?= $flash[0] === 'ok' ? '#d9f5e3' : '#ffdede' ?>;color:<?= $flash[0] === 'ok' ? '#0a6b33' : '#8a1f1f' ?>">
    <?= Security::e($flash[1]) ?>
  </p>
<?php endif; ?>

<div class="dash-hero">
  <div class="hcard"><span>On the map now</span><b><?= $live ?></b><small>SHARI NAV riders' reports</small></div>
  <div class="hcard"><span>Ended in 24 h</span><b><?= count($rows) - $live ?></b><small>expired, voted off or deleted</small></div>
</div>

<div class="panel" style="margin-top:14px">
  <h2>Road reports</h2>
  <p class="muted" style="margin:0 0 10px">Riders report from <a href="<?= $base ?>/navigate.html" target="_blank" rel="noopener">navigate.html</a> (⚠️ button). Two 👎 from other riders remove a report by itself.</p>
  <div class="dt-wrap">
    <table class="dt card-table">
      <thead><tr><th>When</th><th>What</th><th>Where</th><th data-type="num">👍</th><th data-type="num">👎</th><th>Until</th><th>State</th><th data-nosort></th></tr></thead>
      <tbody>
      <?php if ($rows === []): ?>
        <tr><td colspan="8" class="muted" style="text-align:center;padding:18px">No road reports right now.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
          $active = (string) $r['status'] === 'active' && strtotime((string) $r['expires_at']) > time();
          $emoji = NavLive::KINDS[(string) $r['kind']][1] ?? '•';
          $ll = sprintf('%.5f,%.5f', (float) $r['lat'], (float) $r['lng']); ?>
        <tr>
          <td data-label="When" data-sort="<?= Security::e((string) $r['created_at']) ?>"><?= Security::e(substr((string) $r['created_at'], 0, 16)) ?></td>
          <td data-label="What"><?= $emoji ?> <?= Security::e($names[(string) $r['kind']] ?? (string) $r['kind']) ?>
            <?php if ((string) $r['note'] !== ''): ?><br><span class="muted" style="font-size:12px"><?= Security::e((string) $r['note']) ?></span><?php endif; ?></td>
          <td data-label="Where"><a href="<?= $base ?>/navigate.html?to=<?= $ll ?>&name=<?= urlencode('Report #' . (int) $r['id']) ?>" target="_blank" rel="noopener"><?= $ll ?></a></td>
          <td data-label="👍"><?= (int) $r['up'] ?></td>
          <td data-label="👎"><?= (int) $r['down'] ?></td>
          <td data-label="Until"><?= Security::e(substr((string) $r['expires_at'], 0, 16)) ?></td>
          <td data-label="State"><?= $active ? '<b style="color:#0a6b33">on the map</b>' : Security::e((string) $r['status'] === 'active' ? 'expired' : (string) $r['status']) ?></td>
          <td>
            <?php if ($active && $canEdit): ?>
              <form method="post" style="margin:0" onsubmit="return confirm('Delete this report from every map?')">
                <input type="hidden" name="<?= CSRF_TOKEN_NAME ?>" value="<?= Security::csrfToken() ?>">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button type="submit" class="btn ghost" style="font-size:12px;padding:5px 10px;color:#b42318">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php admin_footer(); ?>
