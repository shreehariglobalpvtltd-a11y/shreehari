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
          $isSecret = (bool) preg_match('/(token|secret|_pass$|password|api_key)/i', $key);
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
            <?php elseif ($isSecret): ?>
              <input type="password" autocomplete="new-password" name="s[<?= Security::e($key) ?>]" value="<?= Security::e($val) ?>" style="width:100%;max-width:420px;padding:9px 11px;border:1px solid var(--line);border-radius:8px" <?= $canEdit ? '' : 'readonly' ?>>
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

<?php if ($canEdit):
  $testPhone = Settings::getString('company_phone', '');
?>
<div class="panel" id="waTestPanel">
  <h2>📲 Test WhatsApp ticket delivery</h2>
  <p class="muted" style="margin:-4px 0 14px;max-width:640px">
    Sends a real WhatsApp to the number below through your current settings, so you can confirm
    tickets will reach customers. Set <span class="mono">whatsapp_driver</span> = <b>twilio</b> and fill
    the Twilio SID / Auth Token / sender above (then <b>Save</b>) before testing.
    On the Twilio <b>sandbox</b>, the test phone must first send the join code to your sandbox number once.
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="tel" id="waTestPhone" placeholder="+9198XXXXXXXX or +97798XXXXXXXX"
           value="<?= Security::e($testPhone) ?>"
           style="flex:1;min-width:210px;padding:9px 11px;border:1px solid var(--line);border-radius:8px">
    <select id="waTestCountry" style="padding:9px;border:1px solid var(--line);border-radius:8px">
      <option value="">Country: Auto</option>
      <option value="in">🇮🇳 India (+91)</option>
      <option value="np">🇳🇵 Nepal (+977)</option>
    </select>
    <button class="btn" type="button" id="waTestBtn">Send test</button>
  </div>
  <div id="waTestResult" style="margin-top:12px;display:none;padding:11px 13px;border-radius:9px;font-size:13.5px;line-height:1.5"></div>
</div>
<script>
(function(){
  var btn=document.getElementById('waTestBtn'), out=document.getElementById('waTestResult');
  if(!btn) return;
  function show(kind,msg){
    out.style.display='block';
    out.style.background = kind==='ok' ? '#E8F5E9' : '#FFF4E5';
    out.style.color      = kind==='ok' ? '#1b5e20' : '#8a4b00';
    out.style.border     = '1px solid ' + (kind==='ok' ? '#a5d6a7' : '#ffcc80');
    out.textContent      = (kind==='ok' ? '✅ ' : '⚠️ ') + msg;
  }
  btn.onclick=function(){
    var phone=(document.getElementById('waTestPhone').value||'').trim();
    if(!phone){ show('bad','Enter a phone number first.'); return; }
    btn.disabled=true; var old=btn.textContent; btn.textContent='Sending…';
    var fd=new FormData();
    fd.append('phone', phone);
    fd.append('country', document.getElementById('waTestCountry').value);
    fd.append('<?= $k ?>', '<?= $csrf ?>');
    fetch('notify-test.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){ show(j && j.ok ? 'ok' : 'bad', (j && j.detail) || (j && j.ok ? 'Sent.' : 'Send failed.')); })
      .catch(function(){ show('bad','Could not reach the server — check your connection and try again.'); })
      .finally(function(){ btn.disabled=false; btn.textContent=old; });
  };
})();
</script>
<?php endif; ?>
<?php
admin_footer();
