<?php
/**
 * admin/drivers.php — the driver / conductor / crew master (Phase 5).
 *
 * The `drivers` table has been in the schema since day one, and
 * `schedules.driver_id` is already the FK that manifest.php reads to
 * print the crew line on the boarding pass. But the only way to
 * populate either was to hand-INSERT rows through phpMyAdmin — this
 * page is the missing CRUD surface, plus the assignment column on
 * admin/trips.php.
 *
 * Roles carried:
 *   driver     — behind the wheel
 *   co-driver  — the relief driver on overnight runs
 *   conductor  — takes the manifest, boards passengers
 *   host       — hospitality staff
 *
 * A hard-delete refuses when the person is still linked to any
 * upcoming schedule; deactivation is always allowed and is the
 * preferred way to retire someone without breaking history.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
// drivers.view (5 Sep 2026): the sidebar link was already gated on it, but
// the page itself booted on schedules.view — which a counter agent holds —
// so every driver's phone and licence was one typed URL away from an agent.
$admin = admin_boot('drivers.view');

$base    = '';
$flash   = null;
$canEdit = Auth::can('drivers.edit') || Auth::isSuperadmin();

/* ============================================================
   POST — create / update / toggle / delete.
   ============================================================ */
if ($canEdit && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired.'];
    } else {
        try {
            $action = (string) ($_POST['action'] ?? '');
            $roleWhitelist = ['driver', 'co-driver', 'conductor', 'host'];

            if ($action === 'create') {
                $name    = Security::clean((string) ($_POST['full_name'] ?? ''), 120);
                $phone   = normalisePhone((string) ($_POST['phone'] ?? ''));
                $role    = in_array($_POST['role'] ?? 'driver', $roleWhitelist, true) ? (string) $_POST['role'] : 'driver';
                $licence = Security::clean((string) ($_POST['licence_no'] ?? ''), 60);
                $licExp  = Security::clean((string) ($_POST['licence_exp'] ?? ''), 10);
                if ($name === '') { throw new RuntimeException('Enter the driver name.'); }

                $id = Database::insert('drivers', [
                    'full_name'   => $name,
                    'phone'       => $phone !== '' ? $phone : null,
                    'role'        => $role,
                    'licence_no'  => $licence !== '' ? $licence : null,
                    'licence_exp' => $licExp !== '' && Security::isValidDate($licExp) ? $licExp : null,
                    'is_active'   => 1,
                ]);
                Logger::audit('driver.create', 'driver', (string) $id, null,
                    ['name' => $name, 'role' => $role], 'created by admin #' . $admin['id']);
                $flash = ['ok', 'Driver added.'];

            } elseif ($action === 'update') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) { throw new RuntimeException('Missing driver id.'); }
                $before = Database::fetch('SELECT * FROM drivers WHERE id=:id', ['id' => $id]);
                if ($before === null) { throw new RuntimeException('Driver not found.'); }

                $upd = [
                    'full_name'   => Security::clean((string) ($_POST['full_name'] ?? ''), 120),
                    'phone'       => normalisePhone((string) ($_POST['phone'] ?? '')) ?: null,
                    'role'        => in_array($_POST['role'] ?? 'driver', $roleWhitelist, true) ? (string) $_POST['role'] : 'driver',
                    'licence_no'  => Security::clean((string) ($_POST['licence_no'] ?? ''), 60) ?: null,
                    'licence_exp' => Security::isValidDate((string) ($_POST['licence_exp'] ?? '')) ? (string) $_POST['licence_exp'] : null,
                ];
                if ($upd['full_name'] === '') { throw new RuntimeException('Name cannot be empty.'); }
                Database::update('drivers', $upd, 'id = :id', ['id' => $id]);
                Logger::audit('driver.update', 'driver', (string) $id, $before, $upd, 'edited by admin #' . $admin['id']);
                $flash = ['ok', 'Driver updated.'];

            } elseif ($action === 'toggle') {
                $id = (int) ($_POST['id'] ?? 0);
                $on = !empty($_POST['on']);
                if ($id <= 0) { throw new RuntimeException('Missing driver id.'); }
                Database::update('drivers', ['is_active' => $on ? 1 : 0], 'id = :id', ['id' => $id]);
                Logger::audit('driver.toggle', 'driver', (string) $id, null, ['active' => $on], 'toggled by admin #' . $admin['id']);
                $flash = ['ok', $on ? 'Driver reactivated.' : 'Driver deactivated.'];

            } elseif ($action === 'delete') {
                $id = (int) ($_POST['id'] ?? 0);
                if ($id <= 0) { throw new RuntimeException('Missing driver id.'); }
                $upcoming = (int) Database::scalar(
                    "SELECT COUNT(*) FROM schedules WHERE driver_id = :d AND travel_date >= CURDATE()",
                    ['d' => $id]
                );
                if ($upcoming > 0) {
                    throw new RuntimeException("Cannot delete: this person is assigned to $upcoming upcoming trip(s). Deactivate them instead.");
                }
                // Historical schedules.driver_id will null out via the FK's
                // ON DELETE SET NULL (schema.sql fk_sched_driver).
                Database::delete('drivers', 'id = :id', ['id' => $id]);
                Logger::audit('driver.delete', 'driver', (string) $id, null, null, 'deleted by admin #' . $admin['id']);
                $flash = ['ok', 'Driver removed.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ============================================================
   Load list (all, then filter client-side via the search box).
   ============================================================ */
$search = Security::clean($_GET['q'] ?? '', 60);
$where  = [];
$params = [];
if ($search !== '') {
    $where[] = '(full_name LIKE :qA OR phone LIKE :qB OR licence_no LIKE :qC)';
    $like    = '%' . $search . '%';
    $params += ['qA' => $like, 'qB' => $like, 'qC' => $like];
}
$whereSql = $where !== [] ? ' WHERE ' . implode(' AND ', $where) : '';

$drivers = Database::fetchAll(
    "SELECT id, full_name, phone, role, licence_no, licence_exp, is_active,
            (SELECT COUNT(*) FROM schedules WHERE driver_id = drivers.id AND travel_date >= CURDATE()) AS upcoming
       FROM drivers" . $whereSql . "
      ORDER BY is_active DESC, full_name ASC LIMIT 200",
    $params
);
$totalActive   = (int) Database::scalar("SELECT COUNT(*) FROM drivers WHERE is_active=1");
$totalInactive = (int) Database::scalar("SELECT COUNT(*) FROM drivers WHERE is_active=0");

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
    foreach ($drivers as $d) {
        if ((int) $d['id'] === $editId) { $editing = $d; break; }
    }
    if ($editing === null) {
        $editing = Database::fetch('SELECT * FROM drivers WHERE id=:id', ['id' => $editId]);
    }
}

admin_header('Drivers & Crew', 'drivers');

?>
<style>
.drv-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:20px}
.drv-form{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 20px;margin-bottom:20px}
.drv-form h3{margin:0 0 12px;font-size:15px}
.drv-form .row{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:10px}
.drv-form label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
.drv-form input, .drv-form select{padding:8px 11px;border:1px solid var(--line);border-radius:8px;font-size:14px;font-weight:400;background:var(--card);color:var(--ink);text-transform:none;letter-spacing:0}
.role-pill{display:inline-block;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.4px}
.rp-driver{background:#dbeafe;color:#1e40af}
.rp-co-driver{background:#ede9fe;color:#5b21b6}
.rp-conductor{background:#fef3c7;color:#92400e}
.rp-host{background:#dcfce7;color:#166534}
:root[data-theme="dark"] .rp-driver{background:#0d2948;color:#93c5fd}
:root[data-theme="dark"] .rp-co-driver{background:#2e1065;color:#c4b5fd}
:root[data-theme="dark"] .rp-conductor{background:#3e2723;color:#fcd34d}
:root[data-theme="dark"] .rp-host{background:#1b3d20;color:#86efac}
</style>

<?php if ($flash !== null): ?>
  <div class="flash <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<div class="drv-cards">
  <div class="card"><div class="k">Active</div><div class="v"><?= $totalActive ?></div></div>
  <div class="card"><div class="k">Inactive</div><div class="v"><?= $totalInactive ?></div></div>
  <div class="card"><div class="k">Total</div><div class="v"><?= $totalActive + $totalInactive ?></div></div>
</div>

<?php if ($canEdit): ?>
<div class="drv-form">
  <h3><?= $editing ? '✏️ Edit driver #' . (int) $editing['id'] : '➕ Add a driver / conductor' ?></h3>
  <form method="post">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $editing['id'] ?>"><?php endif; ?>
    <div class="row">
      <label>Full name <input type="text" name="full_name" maxlength="120" required value="<?= Security::e((string) ($editing['full_name'] ?? '')) ?>"></label>
      <label>Phone <input type="tel" name="phone" maxlength="15" placeholder="10-digit mobile" value="<?= Security::e((string) ($editing['phone'] ?? '')) ?>"></label>
      <label>Role
        <select name="role">
          <?php foreach (['driver' => 'Driver', 'co-driver' => 'Co-driver', 'conductor' => 'Conductor', 'host' => 'Host'] as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= ($editing['role'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Licence no. <input type="text" name="licence_no" maxlength="60" value="<?= Security::e((string) ($editing['licence_no'] ?? '')) ?>"></label>
      <label>Licence expiry <input type="date" name="licence_exp" value="<?= Security::e((string) ($editing['licence_exp'] ?? '')) ?>"></label>
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <?php if ($editing): ?><a class="btn ghost" href="<?= $base ?>/admin/drivers.php">Cancel</a><?php endif; ?>
      <button class="btn ok" type="submit"><?= $editing ? '💾 Save changes' : '➕ Add driver' ?></button>
    </div>
  </form>
</div>
<?php endif; ?>

<form method="get" class="toolbar">
  <input type="search" name="q" value="<?= Security::e($search) ?>" placeholder="Search by name, phone or licence…">
  <button class="btn ghost" type="submit">Search</button>
  <?php if ($search !== ''): ?><a class="btn ghost" href="<?= $base ?>/admin/drivers.php">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <h2>Crew (<?= count($drivers) ?>)</h2>
  <table>
    <thead><tr><th>Name</th><th>Role</th><th>Phone</th><th>Licence</th><th>Trips</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if ($drivers === []): ?>
      <tr><td colspan="7" class="muted" style="padding:22px;text-align:center">No drivers on file. Add one above.</td></tr>
    <?php else: foreach ($drivers as $d): ?>
      <tr>
        <td><strong><?= Security::e((string) $d['full_name']) ?></strong></td>
        <td><span class="role-pill rp-<?= Security::e((string) $d['role']) ?>"><?= Security::e(str_replace('-', ' ', (string) $d['role'])) ?></span></td>
        <td class="mono"><?= Security::e((string) ($d['phone'] ?? '—')) ?: '—' ?></td>
        <td class="mono">
          <?= Security::e((string) ($d['licence_no'] ?? '—')) ?: '—' ?>
          <?php if (!empty($d['licence_exp'])):
            $expTs = strtotime((string) $d['licence_exp']);
            $isSoon = $expTs && $expTs - time() < 30 * 86400;
            $isDone = $expTs && $expTs < time();
          ?>
            <div class="muted" style="font-size:11px;color:<?= $isDone ? '#b02a2a' : ($isSoon ? '#d68910' : 'var(--mut)') ?>">
              Exp <?= Security::e(formatDate((string) $d['licence_exp'])) ?>
              <?= $isDone ? ' · EXPIRED' : ($isSoon ? ' · expiring' : '') ?>
            </div>
          <?php endif; ?>
        </td>
        <td><?= (int) $d['upcoming'] > 0 ? '<strong>' . (int) $d['upcoming'] . '</strong> upcoming' : '<span class="muted">—</span>' ?></td>
        <td><?php if ((int) $d['is_active'] === 1): ?>
          <span class="pill" style="background:#d7f4e3;color:#0a6b3b">Active</span>
        <?php else: ?>
          <span class="pill" style="background:#f7dcdc;color:#8a1f1f">Inactive</span>
        <?php endif; ?></td>
        <td class="row-actions">
          <?php if ($canEdit): ?>
            <a class="btn ghost" href="<?= $base ?>/admin/drivers.php?edit=<?= (int) $d['id'] ?>" style="padding:5px 10px;font-size:12px">Edit</a>
            <form method="post" style="display:inline">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
              <input type="hidden" name="on" value="<?= (int) $d['is_active'] === 1 ? '' : '1' ?>">
              <button class="btn ghost" type="submit" style="padding:5px 10px;font-size:12px" onclick="return confirm('<?= (int) $d['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?> this driver?')">
                <?= (int) $d['is_active'] === 1 ? 'Deactivate' : 'Reactivate' ?>
              </button>
            </form>
            <?php if ((int) $d['is_active'] === 0 && (int) $d['upcoming'] === 0): ?>
              <form method="post" style="display:inline">
                <?= Security::csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <button class="btn bad" type="submit" style="padding:5px 10px;font-size:12px" onclick="return confirm('Permanently delete this driver? Historical schedules keep the row but their driver becomes NULL.')">Delete</button>
              </form>
            <?php endif; ?>
          <?php else: ?>
            <span class="muted">View only</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<p class="muted">
  Roles: Driver (behind the wheel), Co-driver (relief), Conductor (takes manifest), Host (hospitality).
  A driver can be assigned to a specific trip from the <a href="<?= $base ?>/admin/trips.php">Trips board</a>.
  Deactivation keeps historical assignments intact; delete only works once the driver has no upcoming trip.
</p>

<?php admin_footer();
