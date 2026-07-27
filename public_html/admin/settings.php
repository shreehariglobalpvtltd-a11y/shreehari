<?php
/**
 * admin/settings.php — edit the live configuration held in the settings
 * table. Only superadmin/manager reach this. Unknown keys are ignored by
 * Settings::setMany, so the form can never create stray rows.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

// Writing settings is a privileged action.
$canEdit = Auth::can('routes.edit') || ($admin['role'] ?? '') === 'superadmin';

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $pairs = [];
        foreach (($_POST['s'] ?? []) as $key => $val) {
            $pairs[(string) $key] = is_string($val) ? $val : (string) $val;
        }
        // Checkboxes are absent from POST when unticked, so set every known
        // bool key explicitly from whether its box came through.
        foreach (explode(',', (string) ($_POST['_boolkeys'] ?? '')) as $bk) {
            $bk = trim($bk);
            if ($bk !== '') { $pairs[$bk] = isset($_POST['s'][$bk]) ? '1' : '0'; }
        }
        Settings::setMany($pairs);
        Settings::flush();
        $flash = ['ok', 'Settings saved.'];
    }
}

$rows = Database::fetchAll('SELECT skey, svalue, stype, sgroup, label, is_public FROM settings ORDER BY sgroup, skey');
$groups = [];
foreach ($rows as $r) { $groups[$r['sgroup'] ?: 'general'][] = $r; }
ksort($groups);

$boolKeys = array_values(array_map(static fn($r) => $r['skey'],
    array_filter($rows, static fn($r) => $r['stype'] === 'bool')));

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

admin_header('Settings', 'settings');

if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }
if (!$canEdit) { echo '<div class="flash bad">Your role can view settings but not change them.</div>'; }
?>
<form method="post">
  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
  <input type="hidden" name="_boolkeys" value="<?= Security::e(implode(',', $boolKeys)) ?>">

  <?php foreach ($groups as $group => $items): ?>
    <div class="panel">
      <h2><?= Security::e(ucfirst((string) $group)) ?></h2>
      <table>
        <?php foreach ($items as $r):
          $key = (string) $r['skey'];
          $label = $r['label'] ?: ucwords(str_replace('_', ' ', $key));
          $val = (string) ($r['svalue'] ?? '');
          $isJson = $r['stype'] === 'json';
        ?>
        <tr>
          <th style="width:34%"><?= Security::e($label) ?>
            <div class="muted mono" style="font-weight:400;font-size:11px"><?= Security::e($key) ?></div>
          </th>
          <td>
            <?php if ($r['stype'] === 'bool'): ?>
              <label style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="s[<?= Security::e($key) ?>]" value="1" <?= $val === '1' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>>
                <span class="muted"><?= $val === '1' ? 'Enabled' : 'Disabled' ?></span>
              </label>
            <?php elseif ($isJson): ?>
              <textarea name="s[<?= Security::e($key) ?>]" rows="2" style="width:100%;font-family:ui-monospace,monospace;font-size:12px;padding:8px;border:1px solid var(--line);border-radius:8px" <?= $canEdit ? '' : 'readonly' ?>><?= Security::e($val) ?></textarea>
            <?php else: ?>
              <input type="text" name="s[<?= Security::e($key) ?>]" value="<?= Security::e($val) ?>" style="width:100%;max-width:420px;padding:9px 11px;border:1px solid var(--line);border-radius:8px" <?= $canEdit ? '' : 'readonly' ?>>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endforeach; ?>

  <?php if ($canEdit): ?>
    <div class="toolbar"><button class="btn" type="submit">💾 Save all settings</button></div>
  <?php endif; ?>
</form>
<?php
admin_footer();
