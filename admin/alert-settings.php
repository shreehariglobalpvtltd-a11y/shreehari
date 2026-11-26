<?php
/**
 * admin/alert-settings.php — "घण्टी र सूचना · Ring & alerts" (26 Nov 2026, fx1).
 *
 * One small page for the call-style ring (includes/alertbus.php):
 *   - "Ring on this phone" / "Stop ringing this phone" (web push for this handset; only
 *     a phone that tapped the button rings) and the sound kit
 *   - the phones that ring, with Remove for a lost phone (Settings permission)
 *   - the switches and the ring numbers (seconds, repeat, how many times)
 *   - the matrix: which role gets which alert, and which alerts ring
 *   - per-role sound on/off, volume and quiet hours
 *   - a test button per alert and channel (super-admin only)
 *   - the log: the newest alerts, who tapped Seen, how many phones were rung - the whole
 *     log for whoever may edit the matrix, otherwise only what that role / desk is told
 *
 * Reading is dashboard.view; saving needs the Settings permission; the test
 * buttons are super-admin only (admin/notify-test.php, channel=alert).
 * Nothing here touches a ticket or money.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/alertbus.php';

$admin   = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();
$isSuper = Auth::isSuperadmin();
$e       = static fn($v): string => Security::e((string) $v);
$flash   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('समय सकियो, फेरि प्रयास गर्नुहोस् · Session expired — please try again.');
        }
        if (!$canEdit) {
            throw new RuntimeException('यो बदल्न Settings अनुमति चाहिन्छ · Changing this needs the Settings permission.');
        }
        if ((string) ($_POST['action'] ?? '') === 'save') {
            $n = AlertBus::saveConfig((array) ($_POST['c'] ?? []));
            $m = AlertBus::saveMatrix((array) ($_POST['m'] ?? []));
            $p = AlertBus::saveRolePrefs((array) ($_POST['p'] ?? []));
            Logger::audit('alerts.matrix', 'settings', 'alerts', null, ['changed' => $n, 'matrix' => $m, 'roles' => $p],
                'ring & alert settings saved by admin #' . (int) $admin['id']);
            $flash = ['ok', 'सेभ भयो · Saved.'];
        } elseif ((string) ($_POST['action'] ?? '') === 'phone_off') {
            /* the office takes a lost / handed-on phone off the ring (only the ring; nothing else of that phone changes) */
            $gone = AlertBus::removeRingPhone((string) ($_POST['h'] ?? ''));
            if ($gone) {
                Logger::audit('alerts.push_removed', 'settings', 'alerts', null, ['owner' => (int) ($_POST['o'] ?? 0)],
                    'a ringing phone was removed by admin #' . (int) $admin['id']);
            }
            $flash = $gone ? ['ok', 'त्यो फोनमा अब घण्टी बज्दैन · That phone will not ring any more.']
                           : ['bad', 'त्यो फोन भेटिएन · That phone was not found.'];
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

$ready  = AlertBus::ready();
$cfgOn  = [
    'alert_bus_on'    => Settings::getBool('alert_bus_on', true),
    'alert_ring_on'   => Settings::getBool('alert_ring_on', true),
    'alert_push_on'   => Settings::getBool('alert_push_on', true),
    'alert_wa_on'     => Settings::getBool('alert_wa_on', false),
    'alert_ring_self' => Settings::getBool('alert_ring_self', false),
];
$nums = [
    'alert_ring_seconds'    => Settings::getInt('alert_ring_seconds', 60),
    'alert_ring_repeat_min' => Settings::getInt('alert_ring_repeat_min', 2),
    'alert_ring_repeat_max' => Settings::getInt('alert_ring_repeat_max', 3),
    'alert_ring_burst'      => Settings::getInt('alert_ring_burst', 6),
];
$matrix = AlertBus::matrix();
$prefs  = AlertBus::rolePrefs();
/* The log follows the matrix: whoever may edit "who gets what" sees all of it; everyone else only
   the alerts their own role and desk are told about (an agent's credit line is not for support). */
$log    = AlertBus::recent(30, $canEdit ? null : ['id' => (int) ($admin['id'] ?? 0), 'role' => (string) ($admin['role'] ?? '')]);
$phones = $canEdit ? AlertBus::ringPhones(100) : [];
$pushOk = Settings::getBool('push_enabled', true);

$roleName = [
    'superadmin' => ['मालिक', 'Owner'], 'manager' => ['म्यानेजर', 'Manager'], 'operations' => ['अपरेसन', 'Operations'],
    'accountant' => ['लेखा', 'Accounts'], 'support' => ['सहयोग', 'Support'], 'official' => ['अधिकारी', 'Official'],
    'counter' => ['काउन्टर', 'Counter'], 'agent' => ['एजेन्ट', 'Agent'], 'scanner' => ['स्क्यानर', 'Scanner'],
];
$switchText = [
    'alert_bus_on'    => ['सूचना प्रणाली चालु', 'Alerts on', 'बन्द गरे केही रेकर्ड हुँदैन, केही बज्दैन · Off = nothing is recorded and nothing rings.'],
    'alert_ring_on'   => ['कल जस्तै घण्टी', 'Ring like a call', 'बन्द गरे पुरानो छोटो आवाज र हरियो पट्टी मात्र · Off = the old short chime and green bar.'],
    'alert_push_on'   => ['फोनमा घण्टी (प्यानल बन्द हुँदा पनि)', 'Ring staff phones', 'जसले "यो फोनमा घण्टी" थिचेको छ उसैको फोनमा · Only phones that tapped "Ring on this phone".'],
    'alert_wa_on'     => ['अफिस WhatsApp मा पनि पठाउने', 'Also WhatsApp the office', 'घण्टी बज्ने सूचना मात्र, घण्टामा बढीमा ६ · Ringing alerts only, at most 6 an hour.'],
    'alert_ring_self' => ['टिकट काट्नेलाई पनि बजाउने', 'Ring the seller too', 'सामान्यतया बन्द: आफैँले काटेको टिकटमा घण्टी बज्दैन · Normally off: no ring for your own sale.'],
];
$numText = [
    'alert_ring_seconds'    => ['एक पटक कति सेकेन्ड बज्ने', 'Ring for (seconds)', 10, 300],
    'alert_ring_repeat_min' => ['कति मिनेटपछि फेरि बज्ने', 'Ring again after (minutes)', 1, 30],
    'alert_ring_repeat_max' => ['कति पटक फेरि बज्ने', 'Ring again (times)', 0, 10],
    'alert_ring_burst'      => ['यति भन्दा बढी भए एउटै सूचनामा गन्ती', 'Merge into one above', 1, 50],
];

admin_header('घण्टी र सूचना · Ring & alerts', 'alert-settings');
admin_page_head('नयाँ टिकट आउँदा अफिसको फोन र खुलेको प्यानल कल जस्तै बज्छ, कसैले "देखेँ" नथिचेसम्म। · A new ticket rings like a call until someone taps Seen.');
?>
<style>
.as-grid{display:grid;gap:14px}
.as-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px}
.as-card h2{font-size:17px;margin:0 0 4px}
.as-card h2 small,.as-sub{font-size:13px;font-weight:500;color:var(--mut)}
.as-sub{margin:0 0 10px;display:block}
.as-row{display:grid;grid-template-columns:auto minmax(0,1fr);gap:4px 12px;align-items:start;padding:10px 0;border-top:1px solid var(--line)}
.as-row:first-of-type{border-top:0}
.as-row input[type=checkbox],.as-mx input[type=checkbox]{width:24px;height:24px;accent-color:#1FA35B}
.as-row label{min-height:44px;display:block;cursor:pointer}
.as-row b{font-size:16px}.as-row small{display:block;font-size:13px;color:var(--mut)}
.as-nums{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-top:6px}
.as-nums label{display:block;font-size:14px;font-weight:700}
.as-nums label small{display:block;font-weight:500;color:var(--mut);font-size:12.5px}
.as-card input[type=number],.as-card input[type=time]{width:100%;min-height:46px;padding:8px 10px;border:1px solid var(--line);border-radius:10px;
  font-size:16px;background:var(--card);color:var(--ink)}
.as-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;border:1px solid var(--line);border-radius:12px}
.as-mx{border-collapse:collapse;min-width:680px;width:100%;font-size:14px}
.as-mx th,.as-mx td{padding:0;border-bottom:1px solid var(--line);text-align:center}
.as-mx thead th{font-size:12.5px;padding:8px 4px;background:var(--soft);position:sticky;top:0}
.as-mx thead th small{display:block;font-weight:500;color:var(--mut)}
.as-mx tbody th{text-align:left;padding:8px 10px;font-weight:700;min-width:170px;position:sticky;left:0;background:var(--card)}
.as-mx tbody th small{display:block;font-weight:500;color:var(--mut);font-size:12.5px}
.as-mx td label{display:grid;place-items:center;min-width:48px;min-height:48px;cursor:pointer}
.as-mx td.rg{background:rgba(240,124,31,.10)}
.as-role{display:grid;grid-template-columns:minmax(110px,1.2fr) auto minmax(80px,1fr) minmax(104px,1fr) minmax(104px,1fr);gap:8px;align-items:center;
  padding:8px 0;border-top:1px solid var(--line);font-size:14px}
.as-role:first-of-type{border-top:0}
.as-role .hd{font-size:12.5px;color:var(--mut);font-weight:700}
.as-role label.sw{display:grid;place-items:center;min-width:48px;min-height:48px}
.as-role input[type=checkbox]{width:24px;height:24px;accent-color:#1FA35B}
.as-btns{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.as-btns .btn{min-height:46px;font-size:15px;white-space:normal;text-align:center;max-width:100%}
.as-test{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:8px;align-items:center;padding:8px 0;border-top:1px solid var(--line)}
.as-test:first-of-type{border-top:0}
.as-test .btn{min-height:44px;min-width:64px}
.as-out{margin-top:10px;padding:10px 12px;border-radius:10px;background:var(--soft);font-size:14px;min-height:20px}
.as-help{font-size:14px;color:var(--mut);line-height:1.55}
.as-help li{margin:4px 0}
.as-role .qq{display:contents}
@media(max-width:560px){.as-role{grid-template-columns:1fr auto 84px}.as-role .qq{grid-column:1 / -1;display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .as-role .hd.q{display:none}.as-test{grid-template-columns:1fr 1fr}.as-test b{grid-column:1 / -1}}
</style>
<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>" role="status"><?= $e($flash[1]) ?></div><?php endif; ?>
<?php if (!$ready): ?>
<div class="flash bad" role="status">डाटाबेस अपग्रेड बाँकी छ · The tables are missing — run database/upgrade-2026-11-26-fx1-alertbus.sql once. Until then the panel keeps its old short chime.</div>
<?php endif; ?>

<div class="as-grid">

<section class="as-card" id="asPhone">
  <h2>📱 यो फोनमा घण्टी <small>· Ring on this phone</small></h2>
  <span class="as-sub">प्यानल बन्द भए पनि नयाँ टिकट आउँदा यो फोनमा सूचना बज्छ। हरेक फोनमा एक पटक थिच्नुहोस्। यो बटन थिचेको फोनमा मात्र बज्छ। · Tap once on every office phone. Only a phone that tapped this button rings.</span>
  <div class="as-btns">
    <button class="btn ok" type="button" id="asPushOn">🔔 यो फोनमा घण्टी बजाउनुहोस् · Ring on this phone</button>
    <button class="btn ghost" type="button" id="asPushOff" hidden>🔕 यो फोनमा घण्टी बन्द · Stop ringing this phone</button>
    <span id="asPushState" class="muted" style="font-size:14px"></span>
  </div>
  <div class="as-btns" style="margin-top:12px" id="asVoices" hidden>
    <span class="muted" style="font-size:14px">आवाज सुन्नुहोस् · Hear the sounds:</span>
  </div>
  <p class="as-sub" id="asMuted" hidden style="margin-top:8px">🔇 यो फोनमा आवाज बन्द छ (मेनुको आवाज बटन) · Sound is off on this device (the sound button in the menu).</p>
</section>

<?php if ($canEdit): ?>
<section class="as-card" id="asPhones">
  <h2>📲 घण्टी बज्ने फोनहरू <small>· Phones that ring</small></h2>
  <span class="as-sub">फोन हरायो वा अरूलाई दियो भने यहाँबाट हटाउनुहोस्। पासवर्ड बदलेपछि पनि त्यो खाताका फोन आफैँ रोकिन्छन्। · Remove a lost or handed-on phone here. A password change also stops that account's phones.</span>
  <?php if ($phones === []): ?>
    <p class="as-sub">अहिलेसम्म कुनै फोनले "यो फोनमा घण्टी" थिचेको छैन। · No phone has switched the ring on yet.</p>
  <?php else: foreach ($phones as $ph): ?>
  <form method="post" class="as-test" data-no-guard>
    <?= Security::csrfField() ?><input type="hidden" name="action" value="phone_off">
    <input type="hidden" name="h" value="<?= $e($ph['key']) ?>"><input type="hidden" name="o" value="<?= (int) $ph['admin_id'] ?>">
    <b><?= $e($ph['name'] !== '' ? $ph['name'] : '#' . $ph['admin_id']) ?> <span class="muted" style="font-weight:500">· <?= $e($roleName[$ph['role']][1] ?? $ph['role']) ?></span>
      <small class="muted" style="display:block;font-weight:500;overflow-wrap:anywhere"><?= $e(formatDate($ph['since'], 'j M, g:i A')) ?> · <?= $e(mb_substr($ph['ua'], 0, 70)) ?><?= $ph['live'] ? '' : ' · ⏸ रोकिएको (पासवर्ड बदलियो वा खाता बन्द) · paused' ?></small></b>
    <span></span>
    <button class="btn ghost sm" type="submit">हटाउनुहोस् · Remove</button>
  </form>
  <?php endforeach; endif; ?>
</section>
<?php endif; ?>

<form method="post" class="as-grid" data-no-guard>
<?= Security::csrfField() ?><input type="hidden" name="action" value="save">

<section class="as-card">
  <h2>⚙️ मुख्य स्विच <small>· Switches</small></h2>
  <?php foreach ($switchText as $k => [$ne, $en, $what]): ?>
  <div class="as-row">
    <input type="checkbox" id="c_<?= $e($k) ?>" name="c[<?= $e($k) ?>]" value="1"<?= $cfgOn[$k] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>>
    <label for="c_<?= $e($k) ?>"><b><?= $e($ne) ?></b> <span class="muted">· <?= $e($en) ?></span><small><?= $e($what) ?></small></label>
  </div>
  <?php endforeach; ?>
  <?php if (!$pushOk): ?><p class="as-sub">⚠️ push_enabled बन्द छ, फोनमा घण्टी जाँदैन · Web push is switched off in Settings, so phones are not rung.</p><?php endif; ?>
  <div class="as-nums">
    <?php foreach ($numText as $k => [$ne, $en, $lo, $hi]): ?>
    <label><?= $e($ne) ?><small><?= $e($en) ?></small>
      <input type="number" inputmode="numeric" min="<?= (int) $lo ?>" max="<?= (int) $hi ?>" step="1" name="c[<?= $e($k) ?>]" value="<?= (int) $nums[$k] ?>"<?= $canEdit ? '' : ' readonly' ?>></label>
    <?php endforeach; ?>
  </div>
</section>

<section class="as-card">
  <h2>👥 कसले के पाउने <small>· Who gets what</small></h2>
  <span class="as-sub">🔔 = कल जस्तै बज्ने। एजेन्टले आफ्नै टिकटको मात्र पाउँछ। काउन्टरले आफ्नै डेस्कको टिकट, र टिक लगाए ग्राहकको अनलाइन टिकट पाउँछ। मालिकले सबै पाउँछ। · 🔔 = rings like a call. An agent only ever gets their own tickets. A counter gets its own desk's tickets and, where ticked, customers' online tickets. The owner gets everything.</span>
  <div class="as-scroll">
  <table class="as-mx no-card">
    <thead><tr>
      <th style="text-align:left;padding-left:10px">सूचना <small>Alert</small></th>
      <th>🔔 <small>Ring</small></th>
      <?php foreach (AlertBus::ROLES as $r): ?><th><?= $e($roleName[$r][0]) ?> <small><?= $e($roleName[$r][1]) ?></small></th><?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php foreach (AlertBus::EVENTS as $ev => $def): ?>
    <tr>
      <th scope="row"><?= $e($def[2] . ' ' . $def[0]) ?><small><?= $e($def[1]) ?></small></th>
      <td class="rg"><label><input type="checkbox" name="m[<?= $e($ev) ?>][ring]" value="1"<?= $matrix[$ev]['ring'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?> aria-label="<?= $e($def[1]) ?> rings"></label></td>
      <?php foreach (AlertBus::ROLES as $r):
          $own = $r === 'superadmin'; ?>
      <td><label><input type="checkbox" name="m[<?= $e($ev) ?>][roles][<?= $e($r) ?>]" value="1"<?= ($own || in_array($r, $matrix[$ev]['roles'], true)) ? ' checked' : '' ?><?= ($canEdit && !$own) ? '' : ' disabled' ?> aria-label="<?= $e($def[1] . ' → ' . $roleName[$r][1]) ?>"></label></td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="as-sub" style="margin-top:8px">चालक (ड्राइभर एप): SOS र यात्रु सूची परिवर्तन — अहिले उपलब्ध छैन, ड्राइभर एपमा पछि थपिन्छ। · Driver app (SOS + manifest change): not available yet.</p>
</section>

<section class="as-card">
  <h2>🔊 आवाज र शान्त समय <small>· Sound &amp; quiet hours (per role)</small></h2>
  <span class="as-sub">शान्त समयमा घण्टी र फोन सूचना बन्द, SOS भने सधैँ बज्छ। · In quiet hours nothing rings — except a driver SOS.</span>
  <div class="as-role"><span class="hd">भूमिका · Role</span><span class="hd">आवाज</span><span class="hd">भोल्युम %</span><span class="hd q">शान्त देखि · from</span><span class="hd q">सम्म · to</span></div>
  <?php foreach (AlertBus::ROLES as $r): ?>
  <div class="as-role">
    <b><?= $e($roleName[$r][0]) ?> <span class="muted" style="font-weight:500">· <?= $e($roleName[$r][1]) ?></span></b>
    <label class="sw"><input type="checkbox" name="p[<?= $e($r) ?>][sound]" value="1"<?= $prefs[$r]['sound'] ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?> aria-label="<?= $e($roleName[$r][1]) ?> sound"></label>
    <input type="number" inputmode="numeric" min="0" max="100" step="5" name="p[<?= $e($r) ?>][volume]" value="<?= (int) $prefs[$r]['volume'] ?>"<?= $canEdit ? '' : ' readonly' ?> aria-label="<?= $e($roleName[$r][1]) ?> volume">
    <span class="qq">
      <input type="time" name="p[<?= $e($r) ?>][quiet_from]" value="<?= $e($prefs[$r]['quiet_from']) ?>"<?= $canEdit ? '' : ' readonly' ?> aria-label="<?= $e($roleName[$r][1]) ?> quiet from">
      <input type="time" name="p[<?= $e($r) ?>][quiet_to]" value="<?= $e($prefs[$r]['quiet_to']) ?>"<?= $canEdit ? '' : ' readonly' ?> aria-label="<?= $e($roleName[$r][1]) ?> quiet to">
    </span>
  </div>
  <?php endforeach; ?>
</section>

<?php if ($canEdit): ?>
<div class="as-btns"><button class="btn ok" type="submit" style="min-height:52px;font-size:17px">💾 सेभ गर्नुहोस् · Save</button></div>
<?php else: ?><p class="as-sub">हेर्न मात्र — बदल्न Settings अनुमति चाहिन्छ। · View only.</p><?php endif; ?>
</form>

<?php if ($isSuper): ?>
<section class="as-card" id="asTests">
  <h2>🧪 जाँच <small>· Test (super-admin)</small></h2>
  <span class="as-sub">नमुना सूचना तपाईंलाई मात्र देखिन्छ, अफिसका अरू कसैलाई बज्दैन। कुनै टिकट बन्दैन। · A sample only you can see. No ticket is made.</span>
  <?php foreach (AlertBus::EVENTS as $ev => $def): ?>
  <div class="as-test">
    <b><?= $e($def[2] . ' ' . $def[0]) ?> <span class="muted" style="font-weight:500">· <?= $e($def[1]) ?></span></b>
    <button class="btn ghost sm" type="button" data-as-test="<?= $e($ev) ?>" data-via="inapp">प्यानल · Panel</button>
    <button class="btn ghost sm" type="button" data-as-test="<?= $e($ev) ?>" data-via="push">फोन · Phone</button>
  </div>
  <?php endforeach; ?>
  <div class="as-btns" style="margin-top:10px">
    <button class="btn ghost" type="button" data-as-test="new_booking" data-via="whatsapp">💬 अफिस WhatsApp मा नमुना · Test the office WhatsApp</button>
  </div>
  <div class="as-out" id="asOut" role="status" aria-live="polite"></div>
</section>
<?php endif; ?>

<section class="as-card">
  <h2>🧾 पछिल्ला सूचना <small>· Recent alerts (who tapped Seen)</small></h2>
  <?php if ($log === []): ?>
    <?= admin_empty('अहिलेसम्म केही छैन', 'नयाँ टिकट, भुक्तानी र अनुरोध यहाँ देखिन्छन्। · New tickets, payments and requests will appear here.', '🔔') ?>
  <?php else: ?>
  <div class="dt-wrap"><table class="dt">
    <thead><tr><th>समय · When</th><th>सूचना · Alert</th><th>देखेँ · Seen by</th><th>फोन · Phones</th></tr></thead>
    <tbody>
    <?php foreach ($log as $row):
        $link = (string) ($row['link'] ?? ''); ?>
    <tr>
      <td><time datetime="<?= $e($row['created_at']) ?>"><?= $e(formatDate((string) $row['created_at'], 'j M, g:i A')) ?></time></td>
      <td><?= (int) $row['ring'] === 1 ? '🔔 ' : '' ?><?php if ($link !== ''): ?><a href="<?= $e($link) ?>"><?= $e($row['title']) ?></a><?php else: ?><?= $e($row['title']) ?><?php endif; ?>
        <?php if ((string) $row['body'] !== ''): ?><br><small class="muted"><?= $e($row['body']) ?></small><?php endif; ?></td>
      <td><?php if (!empty($row['acked_at'])): ?>✓ <?= $e($row['acked_name'] ?: '—') ?> <small class="muted">· <?= $e(formatDate((string) $row['acked_at'], 'g:i A')) ?> · <?= $e($row['ack_via'] === 'push' ? 'फोन · phone' : 'प्यानल · panel') ?></small>
          <?php elseif ((int) $row['ring'] === 1): ?><span class="muted">कसैले देखेको छैन · nobody yet</span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
      <td><?= (int) $row['pushes'] ?><?= (int) $row['whatsapps'] > 0 ? ' · 💬' : '' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="as-card">
  <h2>ℹ️ जान्नुपर्ने कुरा <small>· Good to know</small></h2>
  <ul class="as-help">
    <li>खुलेको प्यानलमा पूरा स्क्रिनको कार्ड र घण्टी बज्छ। टिकट काट्ने स्क्रिनमा (SmartBook) सानो पट्टी मात्र आउँछ, बिक्री रोकिँदैन। · On a selling screen only a slim bar shows.</li>
    <li>ब्राउजरले पहिलो पटक पेजमा ट्याप नगरेसम्म आवाज रोक्छ — कार्डमा "आवाज खोल्न ट्याप गर्नुहोस्" देखिन्छ। · A browser plays no sound until the page has been tapped once.</li>
    <li>प्यानल बन्द वा फोन लक हुँदा: फोनको सूचना (push) आउँछ, फोनकै सूचना आवाजमा, "देखेँ / खोल्नुहोस्" बटनसहित, र नथिचेसम्म स्क्रिनमा रहन्छ। लक स्क्रिनमा पूरा कल जस्तो स्क्रिन र आफ्नै रिङटोन वेबबाट सम्भव छैन — त्यसका लागि Android एप अपडेट चाहिन्छ। · With the panel closed the phone shows a notification with the system sound; a true full-screen call needs the Android app update.</li>
    <li>iPhone: होम स्क्रिनमा थपेको एप (iOS 16.4+) मा मात्र सूचना आउँछ, कम्पन र बटन बिना। · iPhone needs the app added to the Home Screen.</li>
    <li>रद्द / मिति फेर्ने सूचना सधैँ <b>अनुरोध</b> हो — निर्णय मालिकले हातैले गर्नुहुन्छ। · A cancel / date-change alert is always a request.</li>
    <li>अफिसले "देखेँ" थिचेपछि सबैको घण्टी बन्द हुन्छ। एजेन्टले (र नयाँ टिकट बाहेक काउन्टरले) थिच्दा उसैको मात्र बन्द हुन्छ, अफिसमा बजिरहन्छ। · Seen by the office stops the ring for everyone; Seen by an agent (or by a counter, except on a new ticket) stops it for that person only.</li>
    <li>"यो फोनमा घण्टी" थिचेको फोनमा मात्र घण्टी जान्छ — अफिस च्याटको सूचना मात्र खोलेको फोनमा जाँदैन। · Only a phone that tapped "Ring on this phone" rings; allowing Office chat notifications does not start it.</li>
  </ul>
</section>

</div>

<script>
(function () {
  var csrf = (document.querySelector('meta[name="shg-admin-csrf"]') || {}).content || '';
  var out = document.getElementById('asOut');
  function say(t) { if (out) { out.textContent = t; } }

  /* test buttons -> admin/notify-test.php (channel=alert) */
  Array.prototype.forEach.call(document.querySelectorAll('[data-as-test]'), function (b) {
    b.addEventListener('click', function () {
      var fd = new URLSearchParams();
      fd.set('channel', 'alert'); fd.set('event', b.getAttribute('data-as-test')); fd.set('via', b.getAttribute('data-via'));
      b.disabled = true; say('पठाउँदै… · Sending…');
      fetch('notify-test.php', { method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json', 'X-CSRF-Token': csrf }, body: fd.toString() })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          say((j && j.ok ? '✅ ' : '⚠️ ') + ((j && j.detail) || ''));
          if (j && j.ok && window.SHGRing && window.SHGRing.refresh) { window.SHGRing.refresh(); }
        })
        .catch(function () { say('⚠️ पठाउन सकिएन · Could not send.'); })
        .then(function () { b.disabled = false; });
    });
  });

  /* this phone + the sound kit: both live in assets/js/admin-ring.js (loaded when the ring is switched on) */
  function ready() {
    var R = window.SHGRing, st = document.getElementById('asPushState'), on = document.getElementById('asPushOn');
    if (!R) {
      on.disabled = true;
      st.textContent = 'घण्टी बन्द छ वा लोड भएन · The ring is switched off (or did not load).';
      return;
    }
    var off = document.getElementById('asPushOff'), rings = false;
    function paint() {
      var s = R.pushState();
      on.disabled = s === 'unsupported' || s === 'denied';
      on.hidden = rings; off.hidden = !rings;
      st.textContent = rings ? '✅ यो फोनमा घण्टी बज्छ · This phone rings'
        : s === 'denied' ? '⛔ ब्राउजरमा सूचना रोकिएको छ, ब्राउजर सेटिङबाट खोल्नुहोस् · Notifications are blocked in this browser'
        : s === 'unsupported' ? 'यो ब्राउजरले फोन सूचना चलाउँदैन · This browser cannot do phone alerts'
        : 'यो फोनमा घण्टी अझै चालु छैन · This phone does not ring yet';
    }
    paint();
    /* does THIS handset ring? (an Office-chat permission alone does not count) */
    if (R.pushRinging) { R.pushRinging().then(function (v) { rings = !!v; paint(); }); }
    on.addEventListener('click', function () {
      on.disabled = true; st.textContent = '…';
      R.enablePush(true).then(function (r) {
        rings = !!(r && r.ok);
        paint();
        if (r && r.why === 'off') { st.textContent = '⚠️ सर्भरमा फोन घण्टी बन्द छ · Phone ring is switched off on the server'; }
        else if (r && !r.ok && r.why === 'error') { st.textContent = '⚠️ चालु गर्न सकिएन, फेरि प्रयास गर्नुहोस् · Could not switch it on, please try again'; }
      });
    });
    off.addEventListener('click', function () {
      off.disabled = true; st.textContent = '…';
      R.disablePush().then(function (r) {
        off.disabled = false;
        if (r && r.ok) { rings = false; }
        paint();
        if (!r || !r.ok) { st.textContent = '⚠️ बन्द गर्न सकिएन, फेरि प्रयास गर्नुहोस् · Could not switch it off, please try again'; }
      });
    });
    var box = document.getElementById('asVoices'), names = { ring: '🎫 घण्टी', chime: '✅ भुक्तानी', tick: '🧾 टिक', alert: '📝 अनुरोध', siren: '🆘 SOS', ding: '📊 रिपोर्ट', pop: '💬 पप' };
    R.voices().forEach(function (v) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'btn ghost sm'; b.style.minHeight = '44px'; b.textContent = names[v] || v;
      b.addEventListener('click', function () { R.play(v); });
      box.appendChild(b);
    });
    box.hidden = false;
    var muted = false; try { muted = localStorage.getItem('shg_admin_sound') === '0'; } catch (e) {}
    document.getElementById('asMuted').hidden = !muted;
  }
  /* admin-ring.js is a deferred script printed by the footer, after this block */
  window.addEventListener('load', ready);
})();
</script>
<?php admin_footer();
