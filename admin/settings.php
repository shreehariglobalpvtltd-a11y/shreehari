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

/* ── CEO Photo upload (separate from the settings form) ── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($canEdit) && isset($_FILES['ceo_photo'])) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $f = $_FILES['ceo_photo'];
        $allowed = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
        $mime = mime_content_type($f['tmp_name'] ?? '') ?: '';
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash = ['bad', 'Upload failed (error ' . (int)$f['error'] . ').'];
        } elseif (!isset($allowed[$mime])) {
            $flash = ['bad', 'Only JPG, PNG, or WebP images are accepted.'];
        } elseif (($f['size'] ?? 0) > 5 * 1024 * 1024) {
            $flash = ['bad', 'Image too large — maximum 5 MB.'];
        } else {
            // Always save as ceo.jpg for simplicity (browsers cache by filename)
            $dest = dirname(__DIR__) . '/assets/img/ceo.jpg';
            if ($mime === 'image/png' || $mime === 'image/webp') {
                // Convert to JPEG if GD is available, else just copy
                if (function_exists('imagecreatefromstring')) {
                    $src = imagecreatefromstring(file_get_contents($f['tmp_name']));
                    if ($src !== false) {
                        imagejpeg($src, $dest, 92);
                        imagedestroy($src);
                    } else {
                        copy($f['tmp_name'], $dest);
                    }
                } else {
                    copy($f['tmp_name'], $dest);
                }
            } else {
                copy($f['tmp_name'], $dest);
            }
            Logger::audit('settings.ceo_photo', 'admin', (string)($admin['username'] ?? ''), null, null, 'CEO photo updated');
            $flash = ['ok', 'CEO photo updated! It will appear on the splash screen and agent login page. Hard-refresh to see it.'];
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit && !isset($_FILES['ceo_photo'])) {
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
        /* Fares panel (3 Sep 2026): the private-cabin prices live INSIDE the
           cabin_pricing JSON, so the two number fields are merged into it here
           rather than exposing the blob. Flat pricing: online == offline. */
        $fpSingle = isset($_POST['fp_private_single']) ? (float) $_POST['fp_private_single'] : 0.0;
        $fpDouble = isset($_POST['fp_private_double']) ? (float) $_POST['fp_private_double'] : 0.0;
        try {
            /* 4 Sep 2026: the private-cabin prices are merged into cabin_pricing
               AFTER the generic rows are saved, so an edited cabin_pricing
               textarea and the two number fields cannot overwrite each other
               (the number fields win, as they are the curated place). */
            Settings::setMany($pairs);
            if ($fpSingle > 0 || $fpDouble > 0) {
                Settings::flush();
                $cp = Settings::getArray('cabin_pricing', []);
                if (isset($cp['private']) && is_array($cp['private'])) {
                    if ($fpSingle > 0) { $cp['private']['single_1pax']['offline'] = $fpSingle; $cp['private']['single_1pax']['online'] = $fpSingle; }
                    if ($fpDouble > 0) { $cp['private']['double_2pax']['offline'] = $fpDouble; $cp['private']['double_2pax']['online'] = $fpDouble; }
                    Settings::set('cabin_pricing', $cp, 'json', 'pricing', true);
                }
            }
            /* The legacy in-app "Main-point fares" override (main_fares) would win
               over fare_to_* inside Fare::dirFares(); keep it in step when it exists. */
            if (isset($pairs['fare_to_nepal'], $pairs['fare_to_india']) && Settings::get('main_fares', null) !== null
                && (float) $pairs['fare_to_nepal'] > 0 && (float) $pairs['fare_to_india'] > 0) {
                Settings::set('main_fares', ['toNepal' => (float) $pairs['fare_to_nepal'], 'toIndia' => (float) $pairs['fare_to_india']], 'json', 'pricing', false);
            }
            Settings::flush();
            $flash = ['ok', 'Settings saved.'];
        } catch (InvalidArgumentException $e) {
            // A JSON textarea with a typo: nothing was written (the bulk write
            // is one transaction), so say which key and keep the old values.
            Settings::flush();
            $flash = ['bad', 'Not saved — ' . $e->getMessage() . '. Fix the JSON and press Save again.'];
        }
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

$ceoPhoto = dirname(__DIR__) . '/assets/img/ceo.jpg';
?>

<!-- ── CEO / Brand Photo Upload ── -->
<?php if ($canEdit): ?>
<div class="panel" style="border-left:4px solid #F07C1F">
  <h2>📸 CEO / Brand Photo</h2>
  <p class="muted" style="font-size:13px;margin-bottom:14px">
    This photo appears on the app splash screen and the agent/admin login page.
    Upload a clear portrait photo (JPG/PNG/WebP, max 5 MB). It is saved as
    <code>assets/img/ceo.jpg</code> — a hard refresh clears the browser cache.
  </p>
  <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
    <?php if (file_exists($ceoPhoto)): ?>
      <img src="/assets/img/ceo.jpg?t=<?= filemtime($ceoPhoto) ?>" alt="CEO"
           style="width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:center top;
                  border:3px solid #F07C1F;box-shadow:0 4px 14px rgba(0,0,0,.2)">
    <?php else: ?>
      <div style="width:80px;height:80px;border-radius:50%;background:#F0F4FF;border:2px dashed #ccc;
                  display:flex;align-items:center;justify-content:center;font-size:28px">👤</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="file" name="ceo_photo" accept="image/jpeg,image/png,image/webp" required
             style="font-size:14px">
      <button type="submit" class="btn btn-ok btn-sm">Upload Photo</button>
    </form>
  </div>
</div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
  <input type="hidden" name="_boolkeys" value="<?= Security::e(implode(',', $boolKeys)) ?>">

  <?php
  /* -- Fares & booking rules (3 Sep 2026) ------------------------------
     One curated place for the numbers the office actually changes. The
     fare_to_* / max-seats / discount-cap fields post as normal s[key] rows
     (seeded by database/upgrade-2026-09-fares.php); the private-cabin prices
     are merged into the cabin_pricing JSON by the POST handler above. The
     generic key/value loop below skips these keys so nothing is shown twice. */
  $fpKeys   = ['fare_to_nepal', 'fare_to_india', 'max_seats_per_booking', 'counter_max_seats_per_booking', 'counter_max_discount_pct'];
  $fpHave   = static fn(string $key): bool => Settings::get($key, null) !== null;
  $dirFares = Fare::dirFares();
  $cpNow    = Settings::getArray('cabin_pricing', Fare::pricing());
  $fpSingle = (float) ($cpNow['private']['single_1pax']['offline'] ?? 3800);
  $fpDouble = (float) ($cpNow['private']['double_2pax']['offline'] ?? 7600);
  $fpRo     = $canEdit ? '' : 'readonly';
  $fpMissing = 'disabled title="Run database/upgrade-2026-09-fares.php once to enable this field"';
  ?>
  <div class="panel" id="faresPanel">
    <h2>💰 Fares &amp; booking rules <span class="muted" style="font-weight:400">· भाडा · the numbers that change, in one place</span></h2>
    <div class="fp-grid">
      <label>Fare Gujarat → Rupaidiha (₹ / person)
        <input type="number" name="s[fare_to_nepal]" min="1" max="100000" step="1" value="<?= (int) round($dirFares['toNepal']) ?>" <?= $fpRo ?> <?= $fpHave('fare_to_nepal') ? '' : $fpMissing ?>>
        <small>toNepal · “jane” · sharing sleeper, per person</small></label>
      <label>Fare Rupaidiha → Gujarat (₹ / person)
        <input type="number" name="s[fare_to_india]" min="1" max="100000" step="1" value="<?= (int) round($dirFares['toIndia']) ?>" <?= $fpRo ?> <?= $fpHave('fare_to_india') ? '' : $fpMissing ?>>
        <small>toIndia · “aune” · sharing sleeper, per person</small></label>
      <label>Private Single cabin (₹)
        <input type="number" name="fp_private_single" min="1" max="100000" step="1" value="<?= (int) round($fpSingle) ?>" <?= $fpRo ?>>
        <small>1 passenger · online = offline</small></label>
      <label>Private Double cabin (₹)
        <input type="number" name="fp_private_double" min="1" max="100000" step="1" value="<?= (int) round($fpDouble) ?>" <?= $fpRo ?>>
        <small>2 passengers · online = offline</small></label>
      <label>Max seats per booking (customers)
        <input type="number" name="s[max_seats_per_booking]" min="1" max="20" step="1" value="<?= Settings::getInt('max_seats_per_booking', 6) ?>" <?= $fpRo ?> <?= $fpHave('max_seats_per_booking') ? '' : $fpMissing ?>>
        <small>online self-service cap</small></label>
      <label>Max seats per booking (counter / staff)
        <input type="number" name="s[counter_max_seats_per_booking]" min="1" max="40" step="1" value="<?= Settings::getInt('counter_max_seats_per_booking', 20) ?>" <?= $fpRo ?> <?= $fpHave('counter_max_seats_per_booking') ? '' : str_replace('2026-09-fares.php', '2026-09-counter-bulk.sql', $fpMissing) ?>>
        <small>bulk booking — one party under one name at the desk</small></label>
      <label>Counter discount cap (%)
        <input type="number" name="s[counter_max_discount_pct]" min="0" max="100" step="0.5" value="<?= Security::e(rtrim(rtrim(number_format(Settings::getFloat('counter_max_discount_pct', 15.0), 2, '.', ''), '0'), '.')) ?>" <?= $fpRo ?> <?= $fpHave('counter_max_discount_pct') ? '' : $fpMissing ?>>
        <small>agents / admin may discount up to this</small></label>
    </div>
    <div style="padding:0 18px 14px"><small class="muted">Changes apply to the next search and the next counter sale. Agent commission (₹200 direct / ₹400 team) is set on the Agent Panel. Save with the button at the bottom.</small></div>
  </div>
  <style>.fp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;padding:16px 18px}.fp-grid label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}.fp-grid input{padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-size:16px;font-weight:700;background:var(--card);color:var(--ink);min-width:0}.fp-grid input:disabled{opacity:.5}.fp-grid small{font-weight:500;text-transform:none;letter-spacing:0}</style>

  <?php foreach ($groups as $group => $items): ?>
    <div class="panel">
      <h2><?= Security::e(ucfirst((string) $group)) ?></h2>
      <table>
        <?php foreach ($items as $r):
          $key = (string) $r['skey'];
          if (in_array($key, $fpKeys, true)) { continue; }   // shown in the Fares panel above
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
  $testPhone = Settings::officePhone();
?>
<div class="panel" id="waTestPanel">
  <h2>📲 Test WhatsApp ticket delivery</h2>
  <?php
    // Notify is not bootstrap-loaded (events.php pulls it lazily), so this
    // page asks for it before reading the sender-breaker state.
    require_once INCLUDE_PATH . '/notify.php';
    // Only the Twilio driver can be paused by the breaker — a leftover row must
    // not accuse a Cloud API / click-to-chat setup of refusing anything.
    $waPause = Settings::getString('whatsapp_driver', 'click_to_chat') === 'twilio' ? Notify::twilioPause() : null;
  if ($waPause !== null): ?>
  <p style="margin:-4px 0 14px;padding:11px 13px;border-radius:9px;background:#FFF4E5;color:#8a4b00;border:1px solid #ffcc80;font-size:13.5px;line-height:1.55">
    ⏸️ <b>Automatic WhatsApp is paused.</b> Twilio refused the account
    <?= $waPause['message'] !== '' ? '(' . Security::e($waPause['message']) . ')' : '(code ' . (int) $waPause['code'] . ')' ?>,
    so tickets are going out as one-tap click-to-chat links instead of costing every sale a failed API call.
    It retries by itself after <b><?= Security::e(date('H:i', $waPause['until'])) ?></b> — or send a test below:
    a successful test resumes automatic delivery immediately.
  </p>
  <?php endif; ?>
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

<div class="panel" id="smsTestPanel">
  <h2>📩 Test SMS ticket delivery</h2>
  <p class="muted" style="margin:-4px 0 14px;max-width:640px">
    Sends a real SMS through Twilio. Tick <span class="mono">sms_enabled</span>, fill the
    Twilio <b>SID / Auth Token</b> (same as WhatsApp) and <span class="mono">twilio_sms_from</span>
    (an <b>SMS-capable</b> Twilio number — not the WhatsApp sender) above, then <b>Save</b> before testing.
    Every send — success or failure — is recorded in the message log.
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="tel" id="smsTestPhone" placeholder="+9198XXXXXXXX or +97798XXXXXXXX"
           value="<?= Security::e($testPhone) ?>"
           style="flex:1;min-width:210px;padding:9px 11px;border:1px solid var(--line);border-radius:8px">
    <select id="smsTestCountry" style="padding:9px;border:1px solid var(--line);border-radius:8px">
      <option value="">Country: Auto</option>
      <option value="in">🇮🇳 India (+91)</option>
      <option value="np">🇳🇵 Nepal (+977)</option>
    </select>
    <button class="btn" type="button" id="smsTestBtn">Send test SMS</button>
  </div>
  <div id="smsTestResult" style="margin-top:12px;display:none;padding:11px 13px;border-radius:9px;font-size:13.5px;line-height:1.5"></div>
</div>
<script>
(function(){
  var btn=document.getElementById('smsTestBtn'), out=document.getElementById('smsTestResult');
  if(!btn) return;
  function show(kind,msg){
    out.style.display='block';
    out.style.background = kind==='ok' ? '#E8F5E9' : '#FFF4E5';
    out.style.color      = kind==='ok' ? '#1b5e20' : '#8a4b00';
    out.style.border     = '1px solid ' + (kind==='ok' ? '#a5d6a7' : '#ffcc80');
    out.textContent      = (kind==='ok' ? '✅ ' : '⚠️ ') + msg;
  }
  btn.onclick=function(){
    var phone=(document.getElementById('smsTestPhone').value||'').trim();
    if(!phone){ show('bad','Enter a phone number first.'); return; }
    btn.disabled=true; var old=btn.textContent; btn.textContent='Sending…';
    var fd=new FormData();
    fd.append('phone', phone);
    fd.append('country', document.getElementById('smsTestCountry').value);
    fd.append('channel', 'sms');
    fd.append('<?= $k ?>', '<?= $csrf ?>');
    fetch('notify-test.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){ show(j && j.ok ? 'ok' : 'bad', (j && j.detail) || (j && j.ok ? 'Sent.' : 'Send failed.')); })
      .catch(function(){ show('bad','Could not reach the server — check your connection and try again.'); })
      .finally(function(){ btn.disabled=false; btn.textContent=old; });
  };
})();
</script>

<div class="panel" id="emailTestPanel">
  <h2>📧 Test email (Gmail SMTP)</h2>
  <p class="muted" style="margin:-4px 0 14px;max-width:640px">
    Sends a real email through your SMTP settings above. For Gmail set
    <span class="mono">smtp_host</span> = smtp.gmail.com, <span class="mono">smtp_port</span> = 587,
    <span class="mono">smtp_secure</span> = tls, <span class="mono">smtp_user</span> = your Gmail address and
    <span class="mono">smtp_pass</span> = a 16-character <b>App Password</b>
    (Google Account → Security → 2-Step Verification → App Passwords — <b>not</b> the normal password),
    then <b>Save</b> before testing.
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="email" id="emailTestTo" placeholder="you@example.com"
           style="flex:1;min-width:210px;padding:9px 11px;border:1px solid var(--line);border-radius:8px">
    <button class="btn" type="button" id="emailTestBtn">Send test email</button>
  </div>
  <div id="emailTestResult" style="margin-top:12px;display:none;padding:11px 13px;border-radius:9px;font-size:13.5px;line-height:1.5"></div>
</div>
<script>
(function(){
  var btn=document.getElementById('emailTestBtn'), out=document.getElementById('emailTestResult');
  if(!btn) return;
  function show(kind,msg){
    out.style.display='block';
    out.style.background = kind==='ok' ? '#E8F5E9' : '#FFF4E5';
    out.style.color      = kind==='ok' ? '#1b5e20' : '#8a4b00';
    out.style.border     = '1px solid ' + (kind==='ok' ? '#a5d6a7' : '#ffcc80');
    out.textContent      = (kind==='ok' ? '✅ ' : '⚠️ ') + msg;
  }
  btn.onclick=function(){
    var to=(document.getElementById('emailTestTo').value||'').trim();
    if(!to){ show('bad','Enter an email address first.'); return; }
    btn.disabled=true; var old=btn.textContent; btn.textContent='Sending…';
    var fd=new FormData();
    fd.append('email', to);
    fd.append('channel', 'email');
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
