<?php
/**
 * admin/permissions.php — Roles & permissions (29 Oct 2026, owner's
 * management-system prompt, Phase 1 "Permission Matrix UI: Role × Feature
 * checkbox grid" + "Per-user overrides: extra grants").
 *
 *   1. ROLE MATRIX — every permission the code checks (Auth::PERMISSION_CATALOG)
 *      against every role. A tick that differs from the code's default is
 *      saved in the settings row `role_permissions_override`; Auth::can()
 *      reads the merged set, so the change reaches signed-in people within
 *      30 seconds (their next re-check). "Back to default" clears a role.
 *   2. ONE PERSON — the extra grants on top of their role (admins.permissions,
 *      the JSON list Auth::can() already honoured). Role ticks show greyed.
 *
 * Who: staff.manage may LOOK; only a super-admin may change anything. The
 * super-admin column is not shown — that role is always everything. What a
 * permission opens is still bounded by each page's own rules: an agent given
 * bookings.view still sees only their own tickets (Auth::bookingScopeAdminId),
 * a viewer stays read-only whatever is ticked.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('staff.manage');
$isSa  = Auth::isSuperadmin();
$e     = static fn($v): string => Security::e((string) $v);
$flash = null;

$roles   = Auth::MATRIX_ROLES;
$catalog = Auth::PERMISSION_CATALOG;
$known   = Auth::permissionList();
$roleName = [
    'manager' => 'Manager', 'accountant' => 'Accountant', 'operations' => 'Operations', 'support' => 'Support',
    'official' => 'Office staff', 'counter' => 'Counter', 'agent' => 'Agent', 'scanner' => 'Scanner', 'viewer' => 'Viewer',
];
$personId = (int) ($_GET['id'] ?? $_POST['person'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } elseif (!$isSa) {
        $flash = ['bad', 'Only a super-admin may change permissions. / सुपर-एडमिनले मात्र बदल्न मिल्छ।'];
    } elseif ($act === 'save_matrix' || $act === 'reset_role') {
        $before = Settings::getArray('role_permissions_override', []);
        $ticks  = is_array($_POST['p'] ?? null) ? $_POST['p'] : [];
        $over   = [];
        foreach ($roles as $role) {
            $default = Auth::defaultRolePermissions($role);
            if ($act === 'reset_role') {
                // Keep every other role exactly as saved; drop this one.
                if ((string) ($_POST['role'] ?? '') !== $role && isset($before[$role]) && is_array($before[$role])) {
                    $over[$role] = $before[$role];
                }
                continue;
            }
            foreach ($known as $perm => $_label) {
                $on = isset($ticks[$role][$perm]);
                if ($on !== in_array($perm, $default, true)) {
                    $over[$role][$perm] = $on;
                }
            }
        }
        Settings::set('role_permissions_override', $over, 'json', 'security');
        Logger::audit('permissions.roles', 'settings', 'role_permissions_override', $before, $over,
            $act === 'reset_role' ? 'role ' . (string) ($_POST['role'] ?? '') . ' back to default' : 'role permission matrix saved');
        $flash = ['ok', ($act === 'reset_role' ? 'Role set back to default.' : 'Saved.')
            . ' Signed-in people pick it up within 30 seconds. / ३० सेकेन्डभित्र लागू हुन्छ।'];
    } elseif ($act === 'save_person') {
        $acct = $personId > 0 ? Database::fetch('SELECT id, username, role, permissions FROM admins WHERE id = :id', ['id' => $personId]) : null;
        if ($acct === null) {
            $flash = ['bad', 'No such account.'];
        } elseif ((string) $acct['role'] === 'superadmin') {
            $flash = ['bad', 'A super-admin already has everything.'];
        } else {
            $old   = jsonColumn($acct['permissions']);
            $old   = is_array($old) ? array_values(array_filter($old, 'is_string')) : [];
            $ticks = is_array($_POST['g'] ?? null) ? array_keys($_POST['g']) : [];
            // Grants the matrix does not know are kept as they were.
            $new = array_values(array_filter($old, static fn(string $p): bool => !isset($known[$p])));
            foreach ($ticks as $perm) {
                $perm = (string) $perm;
                if (isset($known[$perm]) && !in_array($perm, $new, true)) {
                    $new[] = $perm;
                }
            }
            sort($new);
            Database::update('admins', ['permissions' => $new === [] ? null : json_encode($new)], 'id = :id', ['id' => (int) $acct['id']]);
            Logger::audit('permissions.person', 'admin', (string) $acct['username'], ['permissions' => $old], ['permissions' => $new],
                'extra grants changed');
            $flash = ['ok', 'Extra grants saved for ' . $acct['username'] . '. Live within 30 seconds.'];
        }
    }
}

$eff = [];
foreach ($roles as $role) {
    $eff[$role] = Auth::rolePermissions($role);
}
$people = Database::fetchAll("SELECT id, username, full_name, role, is_active, permissions FROM admins
                               WHERE role <> 'superadmin' ORDER BY is_active DESC, full_name LIMIT 1000");
$person = null;
foreach ($people as $p) {
    if ((int) $p['id'] === $personId) {
        $person = $p;
    }
}
$grants = $person !== null ? jsonColumn($person['permissions']) : [];
$grants = is_array($grants) ? $grants : [];
$withGrants = array_values(array_filter($people, static function (array $p): bool {
    $g = jsonColumn($p['permissions']);
    return is_array($g) && $g !== [];
}));
$dis = $isSa ? '' : ' disabled';
$csrf = Security::csrfField();

/* Named permission groups (29 Oct 2026, management upgrade 4.2): a one-tap
   way to tick a common set in "One person". 'payments.*' = every catalog
   permission starting with "payments.". Ticking only fills the boxes; the
   super-admin still presses Save, and the server keeps validating each
   permission against the catalog exactly as before. */
$groupDefs = [
    'finance' => ['💰 Full finance access', ['payments.*', 'commissions.*', 'refunds.*', 'reports.*']],
    'desk'    => ['🎫 Ticket desk', ['bookings.view', 'bookings.edit', 'bookings.cancel', 'tickets.scan', 'customers.view', 'schedules.view', 'payments.view', 'payments.verify']],
    'support' => ['🎧 Support desk', ['support.*', 'messages.view', 'customers.view', 'bookings.view', 'waitlist.view']],
    'ops'     => ['🚌 Bus operations', ['schedules.*', 'routes.*', 'buses.*', 'drivers.*', 'liveops.*', 'tickets.scan']],
    'reports' => ['📊 Reports only', ['dashboard.view', 'reports.view', 'reports.export']],
];
$groups = [];
foreach ($groupDefs as $gk => [$glabel, $patterns]) {
    $set = [];
    foreach ($patterns as $pat) {
        foreach (array_keys($known) as $perm) {
            if ($pat === $perm || (str_ends_with($pat, '.*') && str_starts_with($perm, substr($pat, 0, -1)))) {
                $set[$perm] = true;
            }
        }
    }
    $groups[$gk] = ['label' => $glabel, 'perms' => array_keys($set)];
}
/** Grants a person holds beyond their role (the "+N extra" badge). */
$extraOf = static function (array $p) use ($known): array {
    $g = jsonColumn($p['permissions']);
    $g = is_array($g) ? array_values(array_filter($g, 'is_string')) : [];
    return array_values(array_diff(array_filter($g, static fn(string $x): bool => isset($known[$x])), Auth::rolePermissions((string) $p['role'])));
};

admin_header('Roles & Permissions', 'permissions');
?>
<style>
.pm-note{font-size:13px;color:var(--mut);margin:0 0 12px;line-height:1.5}
.pm-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--line);border-radius:12px}
table.pm{border-collapse:collapse;width:100%;min-width:860px;font-size:13px;background:var(--card)}
table.pm th,table.pm td{padding:7px 8px;border-bottom:1px solid var(--line);text-align:center}
table.pm thead th{position:sticky;top:0;background:var(--blue-deep,#0b2a6b);color:#fff;font-size:12px;z-index:1}
table.pm th.pm-perm,table.pm td.pm-perm{text-align:left;position:sticky;left:0;background:var(--card);z-index:1;min-width:230px}
table.pm thead th.pm-perm{background:var(--blue-deep,#0b2a6b)}
table.pm tr.pm-mod td{background:var(--bg,#F4F6FB);font-weight:800;text-align:left;color:var(--blue,#1d4ed8);font-size:12px;letter-spacing:.04em;text-transform:uppercase}
.pm-perm code{font-size:12px;font-weight:700}
.pm-perm small{display:block;color:var(--mut);font-size:11.5px}
.pm-sens code{color:var(--bad)}
.pm-cell{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:9px;cursor:pointer}
.pm-cell input{width:18px;height:18px;accent-color:var(--blue,#1d4ed8);cursor:pointer}
.pm-cell.chg{background:color-mix(in srgb,var(--orange,#f97316) 22%,transparent);outline:2px solid var(--orange,#f97316)}
.pm-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0 0}
.pm-key{display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:var(--mut);margin:0 0 10px}
.pm-key span{display:inline-flex;align-items:center;gap:6px}
.pm-dot{width:14px;height:14px;border-radius:4px;display:inline-block}
.pm-grants{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:6px 14px}
.pm-grants label{display:flex;gap:8px;align-items:flex-start;font-size:13px;padding:6px 8px;border:1px solid var(--line);border-radius:10px;background:var(--card)}
.pm-grants label.from-role{opacity:.6}
.pm-grants input{margin-top:2px;width:17px;height:17px;accent-color:var(--orange,#f97316)}
.pm-chip{font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:999px;border:1px solid var(--line);background:var(--bg,#F4F6FB);margin:2px;display:inline-block}
</style>

<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<nav class="ss-tabs" style="display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px">
  <a class="btn" href="sessions.php">🟢 Sessions &amp; login security</a>
  <a class="btn" href="staff.php">👥 Staff &amp; logins</a>
</nav>

<div class="panel">
  <h2>Role × feature matrix</h2>
  <p class="pm-note">Tick what each role may do. The code's defaults are the starting point; an orange box differs from them.
    Super-admin is not listed — it can always do everything. Pages still apply their own limits (an agent only ever sees their
    own tickets; a viewer can never change anything).
    · कुन भूमिकाले के गर्न पाउने — टिक गर्नुहोस्। सुन्तला = डिफल्टभन्दा फरक।
    <?php if (!$isSa): ?><br><b>View only — a super-admin makes changes.</b><?php endif; ?></p>
  <div class="pm-key">
    <span><i class="pm-dot" style="background:var(--blue,#1d4ed8)"></i>allowed</span>
    <span><i class="pm-dot" style="outline:2px solid var(--orange,#f97316);background:color-mix(in srgb,var(--orange,#f97316) 22%,transparent)"></i>changed from default</span>
    <span><code style="color:var(--bad)">red</code> = moves money / opens others' data</span>
  </div>
  <form method="post" data-no-guard>
    <?= $csrf ?><input type="hidden" name="act" value="save_matrix">
    <div class="pm-wrap">
      <table class="pm">
        <thead><tr><th class="pm-perm">Feature</th>
          <?php foreach ($roles as $role): ?><th><?= $e($roleName[$role] ?? $role) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($catalog as $module => $perms): ?>
          <tr class="pm-mod"><td colspan="<?= count($roles) + 1 ?>"><?= $e($module) ?></td></tr>
          <?php foreach ($perms as $perm => $label): $sens = in_array($perm, Auth::SENSITIVE_PERMISSIONS, true); ?>
          <tr>
            <td class="pm-perm<?= $sens ? ' pm-sens' : '' ?>"><code><?= $e($perm) ?></code><small><?= $e($label) ?></small></td>
            <?php foreach ($roles as $role):
                $on  = in_array($perm, $eff[$role], true);
                $chg = $on !== in_array($perm, Auth::defaultRolePermissions($role), true); ?>
              <td><label class="pm-cell<?= $chg ? ' chg' : '' ?>" title="<?= $e(($roleName[$role] ?? $role) . ' · ' . $perm) ?>">
                <input type="checkbox" name="p[<?= $e($role) ?>][<?= $e($perm) ?>]" value="1"<?= $on ? ' checked' : '' ?><?= $dis ?>></label></td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($isSa): ?>
    <div class="pm-bar">
      <button class="btn ok" type="submit" onclick="return confirm('Save the permission matrix? Signed-in people get it within 30 seconds.');">💾 Save matrix</button>
    </div>
    <?php endif; ?>
  </form>
  <?php if ($isSa): ?>
  <div class="pm-bar">
    <span class="pm-note" style="margin:0">Back to the code's default:</span>
    <?php foreach ($roles as $role): if (array_diff($eff[$role], Auth::defaultRolePermissions($role)) === [] && array_diff(Auth::defaultRolePermissions($role), $eff[$role]) === []) { continue; } ?>
      <form method="post" style="display:inline" onsubmit="return confirm('Put <?= $e($roleName[$role] ?? $role) ?> back to the default permissions?');">
        <?= $csrf ?><input type="hidden" name="act" value="reset_role"><input type="hidden" name="role" value="<?= $e($role) ?>">
        <button class="btn btn-sm">↺ <?= $e($roleName[$role] ?? $role) ?></button></form>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>One person — extra grants</h2>
  <p class="pm-note">Give one person something their role does not have (for example one counter that may also process refunds).
    Greyed boxes already come from their role. · एक जनालाई मात्र थप अनुमति।</p>
  <form method="get" style="margin:0 0 12px">
    <label style="font-size:12px;font-weight:700">Person<br>
      <select name="id" onchange="this.form.submit()" style="min-height:42px;padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);max-width:100%">
        <option value="0">— choose —</option>
        <?php foreach ($people as $p): ?>
          <?php $nx = count($extraOf($p)); ?>
          <option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $personId ? 'selected' : '' ?>><?= $e(($p['full_name'] ?: $p['username']) . ' · ' . $p['role'] . ($nx > 0 ? ' · +' . $nx . ' extra' : '') . ((int) $p['is_active'] === 1 ? '' : ' (off)')) ?></option>
        <?php endforeach; ?>
      </select></label>
  </form>
  <?php if ($person !== null): $fromRole = Auth::rolePermissions((string) $person['role']); ?>
    <?php $pExtra = $extraOf($person); ?>
    <p class="pm-note" style="margin:0 0 8px"><b><?= $e($person['full_name'] ?: $person['username']) ?></b> · <?= $e($roleName[$person['role']] ?? $person['role']) ?>
      <?= $pExtra !== [] ? '· <span class="pm-chip" style="background:#fff1de;border-color:#f6d6a5;color:#8a4b00">+' . count($pExtra) . ' extra beyond the role</span>' : '· nothing beyond the role' ?></p>
    <?php if ($isSa): ?>
    <div class="pm-bar" style="margin:0 0 10px" id="pmGroups">
      <span class="pm-note" style="margin:0">Tick a group:</span>
      <?php foreach ($groups as $gk => $g): ?>
        <button type="button" class="btn btn-sm" data-perms="<?= $e(implode(' ', $g['perms'])) ?>" title="<?= $e(implode(', ', $g['perms'])) ?>"><?= $e($g['label']) ?></button>
      <?php endforeach; ?>
      <button type="button" class="btn btn-sm ghost" data-perms="" title="Untick every extra grant">✕ Clear extras</button>
      <span class="pm-note" style="margin:0">— then Save.</span>
    </div>
    <?php endif; ?>
    <form method="post" data-no-guard id="pmPersonForm">
      <?= $csrf ?><input type="hidden" name="act" value="save_person"><input type="hidden" name="person" value="<?= (int) $person['id'] ?>">
      <?php foreach ($catalog as $module => $perms): ?>
        <h3 style="font-size:13px;margin:12px 0 6px;color:var(--blue,#1d4ed8)"><?= $e($module) ?></h3>
        <div class="pm-grants">
        <?php foreach ($perms as $perm => $label): $byRole = in_array($perm, $fromRole, true); ?>
          <label class="<?= $byRole ? 'from-role' : '' ?>">
            <input type="checkbox" name="g[<?= $e($perm) ?>]" value="1"<?= ($byRole || in_array($perm, $grants, true)) ? ' checked' : '' ?><?= ($byRole || !$isSa) ? ' disabled' : '' ?>>
            <span><code class="<?= in_array($perm, Auth::SENSITIVE_PERMISSIONS, true) ? 'ss-bad' : '' ?>" style="font-size:12px"><?= $e($perm) ?></code><br><small style="color:var(--mut)"><?= $e($byRole ? 'from role · ' . $label : $label) ?></small></span>
          </label>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <?php if ($isSa): ?><div class="pm-bar"><button class="btn ok" type="submit">💾 Save grants for <?= $e($person['full_name'] ?: $person['username']) ?></button></div><?php endif; ?>
    </form>
  <?php endif; ?>

  <?php if ($withGrants !== []): ?>
  <h3 style="font-size:13px;margin:16px 0 6px">People with extra grants (<?= count($withGrants) ?>)</h3>
  <div class="dt-wrap"><table class="dt card-table">
    <thead><tr><th>Person</th><th>Role</th><th>Beyond role</th><th>Extra grants</th></tr></thead>
    <tbody>
    <?php foreach ($withGrants as $p): $g = jsonColumn($p['permissions']); ?>
      <tr>
        <td data-label="Person"><a href="?id=<?= (int) $p['id'] ?>"><?= $e($p['full_name'] ?: $p['username']) ?></a></td>
        <td data-label="Role"><?= $e($p['role']) ?></td>
        <td data-label="Beyond role"><b>+<?= count($extraOf($p)) ?></b></td>
        <td data-label="Grants"><?php foreach ((array) $g as $one): ?><span class="pm-chip"><?= $e(is_string($one) ? $one : json_encode($one)) ?></span><?php endforeach; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>

<script>
/* Permission groups (29 Oct 2026): tick a named set in the person form
   (boxes that come from the role stay as they are); Save still posts. */
(function () {
  var bar = document.getElementById('pmGroups'), form = document.getElementById('pmPersonForm');
  if (!bar || !form) return;
  bar.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-perms]'); if (!b) return;
    var want = (b.getAttribute('data-perms') || '').split(' ').filter(Boolean);
    form.querySelectorAll('input[type=checkbox][name^="g["]').forEach(function (c) {
      if (c.disabled) return;
      var perm = c.name.slice(2, -1);
      if (!want.length) { c.checked = false; } else if (want.indexOf(perm) >= 0) { c.checked = true; }
    });
    form.scrollIntoView({ block: 'start', behavior: 'smooth' });
  });
})();
</script>
<?php admin_footer();
