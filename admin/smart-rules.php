<?php
/**
 * admin/smart-rules.php — SmartBook settings (15 Oct 2026, SmartBook v5 §11).
 *
 *   1. Default Rules (D15)  fixed values + IF -> THEN rules, the order history /
 *                           rules win, and a PREVIEW of what a new booking gets
 *   2. Seat rules           the auto-berth soft-rule weights S1-S7 (hard rules
 *                           H1-H5 are code and cannot be switched off)
 *   3. Booking limits       Cash cap per mobile per bus, online hold minutes,
 *                           Cash auto-cancel (0 = off), Cash on / off
 *   4. Policy & facts       the ONE refund policy (slabs + days), the reschedule
 *                           rule, the route distance — the line every page prints
 *   5. Boarding visibility  which modes offer each stop (customer / agent / counter)
 *
 * Every save is audit-logged with the old and new value. Reading is open to
 * the office; saving needs settings.manage (superadmin / manager).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');
require_once INCLUDE_PATH . '/smartbook.php';
require_once INCLUDE_PATH . '/defaultrules.php';

$e      = static fn($v): string => Security::e((string) $v);
$canEdit = Auth::canManageSettings();
$me     = (int) $admin['id'];
$flash  = null;
$tab    = in_array($_GET['tab'] ?? '', ['rules', 'seats', 'limits', 'policy', 'stops'], true) ? (string) $_GET['tab'] : 'rules';

/* The live preview (POST ?preview=1): what a sample booking would get from the rules on screen (not saved). */
if (isset($_GET['preview']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: text/plain; charset=UTF-8');
    if (!Security::verifyCsrf()) {
        exit('Session expired — reload the page.');
    }
    $c = DefaultRules::clean((array) (json_decode((string) ($_POST['config'] ?? ''), true) ?: []));
    $r = DefaultRules::preview([
        'paxCount' => (int) ($_POST['pvN'] ?? 1),
        'gender'   => (string) ($_POST['pvG'] ?? 'Female'),
        'weekday'  => (string) ($_POST['pvD'] ?? date('D')),
        'minAge'   => (int) ($_POST['pvA'] ?? 0),
        'seen'     => !empty($_POST['pvS']),
    ], $c);
    $bands = array_count_values(array_map(static fn(array $p): string => (string) $p['band'], $r['pax']));
    echo 'Direction  : ', $r['direction'] === 'toIndia' ? 'Rupaidiha → Gujarat' : 'Gujarat → Rupaidiha', "\n";
    echo 'Type       : ', $r['type'], $r['type'] === 'private' ? ' (' . $r['cabin'] . ')' : '', "\n";
    echo 'Berth      : ', $r['berth'], "\n";
    echo 'Payment    : ', $r['pay'], "\n";
    echo 'Passengers : ', count($r['pax']), ' — ', implode(', ', array_map(static fn($k, $v): string => $v . ' ' . $k, array_keys($bands), $bands)), "\n";
    if (!empty($r['suggest'])) {
        echo 'Suggestion : ', $r['suggest'], "\n";
    }
    echo 'Rules used : ', $r['rules'] !== [] ? implode(', ', $r['rules']) : '— (built-in defaults)', "\n";
    echo "\nDate = next departure, pickup = the customer's own / nearest / most used — the engine fills those live.";
    exit;
}

$audit = static function (string $key, mixed $old, mixed $new) use ($me): void {
    Logger::audit('smartbook.settings', 'setting', $key, ['value' => $old], ['value' => $new], 'by admin #' . $me);
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canEdit) {
        $flash = ['bad', 'Only a superadmin or manager may change these.'];
    } else {
        try {
            $act = (string) ($_POST['action'] ?? '');
            if ($act === 'rules') {
                $raw = json_decode((string) ($_POST['config'] ?? ''), true);
                if (!is_array($raw)) {
                    throw new RuntimeException('The rules could not be read — nothing saved.');
                }
                $clean = DefaultRules::clean($raw);
                $old   = Settings::getArray('default_rules', []);
                Settings::set('default_rules', $clean, 'json', 'booking', false);
                $audit('default_rules', $old, $clean);
                $flash = ['ok', 'Default Rules saved — ' . count($clean['rules']) . ' rule(s).'];
                $tab   = 'rules';
            } elseif ($act === 'seats') {
                $w = [];
                foreach (AutoBerth::DEFAULT_WEIGHTS as $k => $def) {
                    $w[$k] = max(0, min(500, (int) ($_POST['w'][$k] ?? $def)));
                }
                $old = Settings::getArray('seat_rules', []);
                Settings::set('seat_rules', $w, 'json', 'booking', false);
                $audit('seat_rules', $old, $w);
                $flash = ['ok', 'Seat rule weights saved.'];
                $tab   = 'seats';
            } elseif ($act === 'limits') {
                $pairs = [
                    'cod_max_pax_per_mobile'    => [max(0, min(50, (int) ($_POST['cod_max'] ?? 6))), 'int', true],
                    'smartbook_online_hold_min' => [max(5, min(120, (int) ($_POST['hold'] ?? 15))), 'int', true],
                    'cod_autocancel_minutes'    => [max(0, min(600, (int) ($_POST['cod_cancel'] ?? 0))), 'int', false],
                    'allow_cod'                 => [!empty($_POST['allow_cod']) ? '1' : '0', 'bool', true],
                ];
                foreach ($pairs as $k => [$v, $t, $pub]) {
                    $old = Settings::getString($k, '');
                    Settings::set($k, (string) $v, $t, 'booking', $pub);
                    if ((string) $old !== (string) $v) {
                        $audit($k, $old, $v);
                    }
                }
                $flash = ['ok', 'Booking limits saved.'];
                $tab   = 'limits';
            } elseif ($act === 'policy') {
                $slabs = [];
                foreach ((array) ($_POST['slab_h'] ?? []) as $i => $h) {
                    $p = $_POST['slab_p'][$i] ?? '';
                    if ($h === '' || $p === '' || !is_numeric($h) || !is_numeric($p)) {
                        continue;
                    }
                    $slabs[] = ['minHrs' => max(0, (int) $h), 'pct' => max(0, min(100, (int) $p))];
                }
                if ($slabs === [] || !in_array(0, array_column($slabs, 'minHrs'), true)) {
                    throw new RuntimeException('Keep a last row for 0 hours (what is refunded closest to departure).');
                }
                usort($slabs, static fn(array $a, array $b): int => $b['minHrs'] <=> $a['minHrs']);
                $pairs = [
                    'refund_slabs'        => [$slabs, 'json'],
                    'refund_days'         => [Security::clean((string) ($_POST['refund_days'] ?? '5-7'), 10), 'string'],
                    'route_distance_km'   => [(string) max(1, (int) ($_POST['km'] ?? 1375)), 'int'],
                    'reschedule_free_hrs' => [(string) max(0, (int) ($_POST['rs_free'] ?? 96)), 'int'],
                    'reschedule_min_hrs'  => [(string) max(0, (int) ($_POST['rs_min'] ?? 24)), 'int'],
                    'reschedule_fee_pct'  => [(string) max(0, min(100, (int) ($_POST['rs_fee'] ?? 10))), 'int'],
                ];
                foreach ($pairs as $k => [$v, $t]) {
                    $old = $t === 'json' ? Settings::getArray($k, []) : Settings::getString($k, '');
                    Settings::set($k, $v, $t, 'booking', true);
                    $audit($k, $old, $v);
                }
                SiteFacts::flush();
                $flash = ['ok', 'Policy saved — every page, the ticket, the bot and the AI now print the new line.'];
                $tab   = 'policy';
            } elseif ($act === 'stops') {
                $vis = (array) ($_POST['vis'] ?? []);
                $rows = Database::fetchAll("SELECT rs.id, rs.visible_to FROM route_stops rs JOIN routes r ON r.id = rs.route_id WHERE r.is_active = 1 AND rs.is_active = 1");
                $n = 0;
                foreach ($rows as $r) {
                    $want = array_values(array_intersect(['customer', 'agent', 'counter'], array_keys((array) ($vis[(int) $r['id']] ?? []))));
                    $set  = implode(',', $want);
                    if ($set !== (string) $r['visible_to']) {
                        Database::update('route_stops', ['visible_to' => $set], 'id = :i', ['i' => (int) $r['id']]);
                        $audit('route_stops.' . $r['id'] . '.visible_to', $r['visible_to'], $set);
                        $n++;
                    }
                }
                $flash = ['ok', $n . ' stop(s) updated.'];
                $tab   = 'stops';
            }
        } catch (Throwable $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
    }
}

$cfg    = DefaultRules::config();
$weights = AutoBerth::weights();
$slabs  = Fare::refundSlabs();
$hasVis = (bool) Database::scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'route_stops' AND column_name = 'visible_to'");
$stops  = $hasVis ? Database::fetchAll(
    "SELECT rs.id, rs.stop_name, rs.stop_type, rs.stop_time, rs.visible_to, r.route_code, r.from_city, r.to_city
       FROM route_stops rs JOIN routes r ON r.id = rs.route_id WHERE r.is_active = 1 AND rs.is_active = 1
   ORDER BY r.sort_order, r.id, rs.stop_type, rs.sort_order"
) : [];

admin_header('SmartBook rules', 'smart-rules');
$tabs = ['rules' => 'Default Rules', 'seats' => 'Seat rules', 'limits' => 'Booking limits', 'policy' => 'Policy & facts', 'stops' => 'Boarding visibility'];
?>
<style>
.sr-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px}
.sr-tabs a{padding:8px 12px;border-radius:10px;border:1px solid var(--line,#e0e8f2);text-decoration:none}
.sr-tabs a.on{background:#185bed;color:#fff;border-color:#185bed}
.sr-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.sr-grid label{display:flex;flex-direction:column;gap:4px;font-size:12.5px;font-weight:700}
.sr-grid input,.sr-grid select{min-height:40px}
.sr-rule{display:grid;grid-template-columns:1.2fr repeat(4,minmax(0,1fr)) 1.6fr auto;gap:6px;align-items:center;padding:6px 0;border-bottom:1px dashed var(--line,#e0e8f2)}
.sr-rule input,.sr-rule select{min-height:38px;width:100%}
.sr-note{font-size:12.5px;color:var(--muted,#637491)}
.sr-prev{background:var(--card,#f6f9ff);border:1px solid var(--line,#e0e8f2);border-radius:12px;padding:10px;font-size:13px;white-space:pre-wrap}
@media (max-width:860px){.sr-rule{grid-template-columns:1fr 1fr}}
</style>
<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>
<?php if (!$canEdit): ?><div class="flash warn">You can read these settings; a superadmin or manager changes them.</div><?php endif; ?>
<div class="sr-tabs"><?php foreach ($tabs as $k => $v): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="?tab=<?= $e($k) ?>"><?= $e($v) ?></a><?php endforeach; ?>
  <a href="<?= '' ?>/admin/smartbook.php">⚡ Open SmartBook</a></div>

<?php if ($tab === 'rules'): ?>
<form method="post" class="panel" id="srRules">
  <?= Security::csrfField() ?><input type="hidden" name="action" value="rules"><input type="hidden" name="config" id="srConfig">
  <h2>Default Rules — a ticket fills itself</h2>
  <p class="sr-note">Order of answers for every field: <b>this customer's history</b> (verified number or staff) → <b>browser location</b> (pickup) → <b>most used in the last 90 days</b> → the fixed value below. A rule adds IF → THEN on top. Rules only choose among the card's own choices — never a price, a berth number or a payment status.</p>
  <div class="sr-grid">
    <label>When history and a rule disagree
      <select id="srPrefer"><option value="history"<?= $cfg['prefer'] === 'history' ? ' selected' : '' ?>>The customer's history wins</option><option value="rules"<?= $cfg['prefer'] === 'rules' ? ' selected' : '' ?>>The office rule wins</option></select></label>
    <?php foreach (['direction' => ['' => '—', 'toNepal' => 'To Nepal', 'toIndia' => 'To Gujarat'], 'type' => ['' => '—', 'sharing' => 'Sharing', 'private' => 'Private'], 'berth' => ['' => '—', 'any' => 'Any', 'lower' => 'Lower', 'upper' => 'Upper'], 'pay' => ['' => '—', 'cash' => 'Cash', 'online' => 'Online']] as $f => $opts): ?>
      <label>Fixed <?= $e($f) ?><select class="srFixed" data-f="<?= $e($f) ?>"><?php foreach ($opts as $v => $t): ?><option value="<?= $e($v) ?>"<?= ($cfg['fixed'][$f] ?? '') === $v ? ' selected' : '' ?>><?= $e($t) ?></option><?php endforeach; ?></select></label>
    <?php endforeach; ?>
  </div>
  <h3 style="margin-top:14px">IF → THEN rules</h3>
  <div class="sr-rule sr-note"><b>Name</b><b>IF passengers</b><b>IF gender</b><b>IF day</b><b>IF age ≥</b><b>THEN</b><span></span></div>
  <div id="srList"></div>
  <p><button type="button" class="btn ghost" id="srAdd">＋ Add a rule</button></p>
  <h3>Preview — what a new booking gets</h3>
  <div class="sr-grid">
    <label>Passengers<input type="number" id="pvN" min="1" max="20" value="1"></label>
    <label>Gender<select id="pvG"><option value="Female">Female</option><option value="Male">Male</option><option value="mixed">Mixed</option></select></label>
    <label>Day<select id="pvD"><?php foreach (DefaultRules::WEEKDAYS as $d): ?><option<?= $d === date('D') ? ' selected' : '' ?>><?= $e($d) ?></option><?php endforeach; ?></select></label>
    <label>Oldest age<input type="number" id="pvA" min="0" max="110" value="30"></label>
    <label>Booked before?<select id="pvS"><option value="0">New customer</option><option value="1">Seen before</option></select></label>
  </div>
  <div class="sr-prev" id="pvOut">…</div>
  <?php if ($canEdit): ?><p><button class="btn ok" type="submit">💾 Save Default Rules</button></p><?php endif; ?>
</form>
<script>
(function () {
  var cfg = <?= json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var THEN = { '': '—', 'berth:lower': 'Berth: lower', 'berth:upper': 'Berth: upper', 'type:private': 'Type: Private', 'type:sharing': 'Type: Sharing',
    'cabin:double': 'Cabin: Double', 'suggest:private_double': 'Suggest Private Double', 'band:senior': 'Age band: Senior', 'band:patient': 'Age band: Patient',
    'pay:cash': 'Pay: Cash', 'pay:online': 'Pay: Online', 'direction:toNepal': 'Direction: To Nepal', 'direction:toIndia': 'Direction: To Gujarat', 'date:next': 'Date: next departure' };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function row(r) {
    r = r || { name: '', when: {}, then: {} };
    var thenKeys = Object.keys(r.then || {}).map(function (k) { return k + ':' + r.then[k]; });
    var opt = function (sel) { return Object.keys(THEN).map(function (k) { return '<option value="' + k + '"' + (k === sel ? ' selected' : '') + '>' + esc(THEN[k]) + '</option>'; }).join(''); };
    var d = document.createElement('div'); d.className = 'sr-rule';
    d.innerHTML = '<input class="rN" maxlength="60" value="' + esc(r.name) + '" placeholder="e.g. Solo woman">'
      + '<input class="rP" type="number" min="0" max="20" value="' + esc(r.when.paxCount || '') + '" placeholder="any">'
      + '<select class="rG"><option value="">any</option><option value="Female"' + (r.when.gender === 'Female' ? ' selected' : '') + '>Female</option><option value="Male"' + (r.when.gender === 'Male' ? ' selected' : '') + '>Male</option><option value="mixed"' + (r.when.gender === 'mixed' ? ' selected' : '') + '>Mixed</option></select>'
      + '<select class="rD"><option value="">any</option>' + ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map(function (x) { return '<option' + (r.when.weekday === x ? ' selected' : '') + '>' + x + '</option>'; }).join('') + '</select>'
      + '<input class="rA" type="number" min="0" max="110" value="' + esc(r.when.minAge || '') + '" placeholder="any">'
      + '<span><select class="rT1">' + opt(thenKeys[0] || '') + '</select><select class="rT2">' + opt(thenKeys[1] || '') + '</select></span>'
      + '<button type="button" class="btn ghost rX" aria-label="Remove rule">✕</button>';
    d.querySelector('.rX').onclick = function () { d.remove(); preview(); };
    d.addEventListener('input', preview); d.addEventListener('change', preview);
    document.getElementById('srList').appendChild(d);
  }
  function collect() {
    var out = { prefer: document.getElementById('srPrefer').value, fixed: {}, rules: [] };
    document.querySelectorAll('.srFixed').forEach(function (s) { if (s.value) out.fixed[s.getAttribute('data-f')] = s.value; });
    document.querySelectorAll('#srList .sr-rule').forEach(function (d) {
      var r = { name: d.querySelector('.rN').value.trim(), when: {}, then: {} };
      var p = +d.querySelector('.rP').value; if (p > 0) r.when.paxCount = p;
      if (d.querySelector('.rG').value) r.when.gender = d.querySelector('.rG').value;
      if (d.querySelector('.rD').value) r.when.weekday = d.querySelector('.rD').value;
      var a = +d.querySelector('.rA').value; if (a > 0) r.when.minAge = a;
      [d.querySelector('.rT1').value, d.querySelector('.rT2').value].forEach(function (t) { if (t) { var kv = t.split(':'); r.then[kv[0]] = kv[1]; } });
      if (Object.keys(r.then).length) out.rules.push(r);
    });
    return out;
  }
  function preview() {
    var c = collect();
    document.getElementById('srConfig').value = JSON.stringify(c);
    var fd = new FormData();
    fd.append('config', JSON.stringify(c));
    ['N', 'G', 'D', 'A', 'S'].forEach(function (k) { fd.append('pv' + k, document.getElementById('pv' + k).value); });
    fetch('/admin/smart-rules.php?preview=1', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': <?= json_encode(Security::csrfToken()) ?> }, body: fd })
      .then(function (r) { return r.text(); }).then(function (t) { document.getElementById('pvOut').textContent = t; });
  }
  (cfg.rules || []).forEach(row);
  if (!(cfg.rules || []).length) row({ name: 'Solo woman', when: { paxCount: 1, gender: 'Female' }, then: { berth: 'lower' } });
  document.getElementById('srAdd').onclick = function () { row(); };
  document.querySelectorAll('#srRules select, #srRules input').forEach(function (el) { el.addEventListener('change', preview); });
  document.getElementById('srRules').addEventListener('submit', function () { document.getElementById('srConfig').value = JSON.stringify(collect()); });
  preview();
})();
</script>
<?php elseif ($tab === 'seats'): ?>
<form method="post" class="panel">
  <?= Security::csrfField() ?><input type="hidden" name="action" value="seats">
  <h2>Auto-berth soft rules (weights)</h2>
  <p class="sr-note">Hard rules are code and always on: no unrelated men and women in one sharing cabin (H1), women-only berths for women (H2), a private cabin for one booking (H3), a child beside an adult (H4), never a held / booked / blocked / staff berth (H5). These weights only choose among the berths that pass them.</p>
  <div class="sr-grid">
  <?php $lbl = ['together' => 'S1 same booking together', 'femaleNearFemale' => 'S2 solo woman near women / women-only', 'careLower' => 'S3 senior / patient / child on the lower floor', 'lowerFirst' => 'S4 lower floor before upper', 'maleUpper' => 'S5 solo man upper, away from women', 'emergencyAble' => 'S6 emergency berth for able adults', 'rowBalance' => 'S7 balance front to back (per row)'];
  foreach ($weights as $k => $v): ?>
    <label><?= $e($lbl[$k] ?? $k) ?><input type="number" name="w[<?= $e($k) ?>]" min="0" max="500" value="<?= (int) $v ?>"></label>
  <?php endforeach; ?>
  </div>
  <?php if ($canEdit): ?><p><button class="btn ok" type="submit">💾 Save weights</button></p><?php endif; ?>
</form>
<?php elseif ($tab === 'limits'): ?>
<form method="post" class="panel">
  <?= Security::csrfField() ?><input type="hidden" name="action" value="limits">
  <h2>Booking limits</h2>
  <div class="sr-grid">
    <label>Cash (pay at boarding) allowed on the website<select name="allow_cod"><option value="1"<?= Settings::getBool('allow_cod', true) ? ' selected' : '' ?>>Yes</option><option value=""<?= Settings::getBool('allow_cod', true) ? '' : ' selected' ?>>No — Online only</option></select></label>
    <label>Most Cash passengers per mobile per bus<input type="number" name="cod_max" min="0" max="50" value="<?= (int) Settings::getInt('cod_max_pax_per_mobile', 6) ?>"></label>
    <label>Online hold (minutes the berths wait for the QR payment)<input type="number" name="hold" min="5" max="120" value="<?= (int) Settings::getInt('smartbook_online_hold_min', 15) ?>"></label>
    <label>Auto-cancel unpaid Cash this many minutes before boarding (0 = off)<input type="number" name="cod_cancel" min="0" max="600" value="<?= (int) Settings::getInt('cod_autocancel_minutes', 0) ?>"></label>
  </div>
  <p class="sr-note">Cash is normally paid AT boarding, so the auto-cancel is off; switching it on cancels website Cash tickets that nobody paid by that time (desk sales are never touched).</p>
  <?php if ($canEdit): ?><p><button class="btn ok" type="submit">💾 Save limits</button></p><?php endif; ?>
</form>
<?php elseif ($tab === 'policy'): ?>
<form method="post" class="panel">
  <?= Security::csrfField() ?><input type="hidden" name="action" value="policy">
  <h2>The one cancellation policy</h2>
  <p class="sr-note">Printed on the home page, checkout, Terms, refund page, FAQ, ticket, WhatsApp bot and the AI — from these rows. Now: <b><?= $e(Fare::refundPolicyLine('en')) ?></b></p>
  <table><thead><tr><th>Hours before departure (at least)</th><th>Refund %</th></tr></thead><tbody>
  <?php for ($i = 0; $i < max(count($slabs) + 1, 4); $i++): $s = $slabs[$i] ?? null; ?>
    <tr><td><input type="number" name="slab_h[]" min="0" value="<?= $s !== null ? (int) $s['minHrs'] : '' ?>"></td><td><input type="number" name="slab_p[]" min="0" max="100" value="<?= $s !== null ? (int) $s['pct'] : '' ?>"></td></tr>
  <?php endfor; ?>
  </tbody></table>
  <div class="sr-grid" style="margin-top:10px">
    <label>Refund reaches the account in (working days)<input name="refund_days" maxlength="10" value="<?= $e(Fare::refundDays()) ?>"></label>
    <label>Route distance the site states (km)<input type="number" name="km" min="1" value="<?= (int) SiteFacts::routeKm() ?>"></label>
    <label>Reschedule free if asked ≥ (hours)<input type="number" name="rs_free" min="0" value="<?= (int) Settings::getInt('reschedule_free_hrs', 96) ?>"></label>
    <label>No reschedule under (hours)<input type="number" name="rs_min" min="0" value="<?= (int) Settings::getInt('reschedule_min_hrs', 24) ?>"></label>
    <label>Reschedule fee in between (%)<input type="number" name="rs_fee" min="0" max="100" value="<?= (int) Settings::getInt('reschedule_fee_pct', 10) ?>"></label>
  </div>
  <p class="sr-note">Refunds and date changes stay manual — the office settles them by hand. This only sets what every page says and what the refund calculator suggests.</p>
  <?php if ($canEdit): ?><p><button class="btn ok" type="submit">💾 Save policy</button></p><?php endif; ?>
</form>
<?php else: ?>
<form method="post" class="panel">
  <?= Security::csrfField() ?><input type="hidden" name="action" value="stops">
  <h2>Boarding / drop points by mode</h2>
  <?php if (!$hasVis): ?><p class="flash warn">Apply database/upgrade-2026-10-15-smartbook-engine.sql first.</p><?php else: ?>
  <p class="sr-note">Which booking modes offer each point. Untick Customer to keep a point for agents / counters only.</p>
  <table><thead><tr><th>Route</th><th>Point</th><th>Customer</th><th>Agent</th><th>Counter</th></tr></thead><tbody>
  <?php foreach ($stops as $s): $v = explode(',', (string) $s['visible_to']); ?>
    <tr><td><?= $e($s['from_city'] . ' → ' . $s['to_city']) ?> <small>(<?= $e($s['stop_type']) ?>)</small></td><td><?= $e($s['stop_name']) ?><?= $s['stop_time'] ? ' · ' . $e(substr((string) $s['stop_time'], 0, 5)) : '' ?></td>
      <?php foreach (['customer', 'agent', 'counter'] as $m): ?><td><input type="checkbox" name="vis[<?= (int) $s['id'] ?>][<?= $e($m) ?>]" value="1"<?= in_array($m, $v, true) ? ' checked' : '' ?> aria-label="<?= $e($s['stop_name'] . ' ' . $m) ?>"></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php if ($canEdit): ?><p><button class="btn ok" type="submit">💾 Save visibility</button></p><?php endif; ?>
  <?php endif; ?>
</form>
<?php endif; ?>
<?php admin_footer();
