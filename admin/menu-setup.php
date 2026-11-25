<?php
/**
 * admin/menu-setup.php — which menu rows each role sees (26 Nov 2026, fx1).
 *
 * Owner, 26 Nov 2026: "duniya dherai option, chalauna garo". The menu is now
 * 8 hubs (includes/rolemenu.php). Here the super-admin:
 *   1. ticks the main rows of each role (at most 7; the rest of what that
 *      role may open sits under "＋ थप · More");
 *   2. keeps rarely used pages out of the menu (they still open by the menu
 *      search and by their link);
 *   3. switches the hub menu, the tab strip and the phone bottom bar on / off;
 *   4. sees which pages nobody opened in the last 30 days.
 *
 * This page changes what is DRAWN, never what a person may open: permissions
 * stay in Roles & Permissions. Who: staff.manage may look, only a super-admin
 * may change. Every save is audited.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('staff.manage');
$isSa  = Auth::isSuperadmin();
$e     = static fn($v): string => Security::e((string) $v);
$flash = null;

$hubs  = RoleMenu::hubs();
$roles = array_merge(['superadmin'], Auth::MATRIX_ROLES);
$roleName = [
    'superadmin' => ['सुपर-एडमिन', 'Super-admin'], 'manager' => ['म्यानेजर', 'Manager'], 'accountant' => ['लेखापाल', 'Accountant'],
    'operations' => ['अपरेसन', 'Operations'], 'support' => ['सपोर्ट', 'Support'], 'official' => ['कार्यालय स्टाफ', 'Office staff'],
    'counter' => ['काउन्टर', 'Counter'], 'agent' => ['एजेन्ट', 'Agent'], 'scanner' => ['स्क्यानर', 'Scanner'], 'viewer' => ['हेर्ने मात्र', 'Viewer'],
];
$switches = [
    'admin_hub_menu_on'   => ['नयाँ छोटो मेनु', 'Short hub menu (off = the old five-trunk menu)'],
    'admin_hub_tabs_on'   => ['पेजको माथि ट्याब', 'Tabs under the page title'],
    'admin_bottom_bar_on' => ['फोनमा तलको बार', 'Phone bottom bar'],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'समय सकियो, फेरि प्रयास गर्नुहोस्। · Session expired — please try again.'];
    } elseif (!$isSa) {
        $flash = ['bad', 'सुपर-एडमिनले मात्र बदल्न मिल्छ। · Only a super-admin may change the menu.'];
    } elseif ($act === 'save') {
        $before = ['roles' => Settings::getString('rolemenu_roles', ''), 'hidden' => Settings::getString('rolemenu_hidden', implode(',', RoleMenu::hidden()))];
        $ticks  = is_array($_POST['h'] ?? null) ? $_POST['h'] : [];
        $over   = [];
        $cut    = [];
        foreach ($roles as $role) {
            $default = RoleMenu::defaultFor($role);
            // the role's own order first, then any other ticked hub in hub order
            $picked = [];
            foreach (array_merge($default, array_keys($hubs)) as $hk) {
                if (isset($ticks[$role][$hk]) && !in_array($hk, $picked, true)) {
                    $picked[] = $hk;
                }
            }
            $cap = $role === 'superadmin' ? count($hubs) : RoleMenu::MAX_MAIN;
            if (count($picked) > $cap) {
                $cut[] = $roleName[$role][1];
                $picked = array_slice($picked, 0, $cap);
            }
            if ($picked !== [] && $picked !== $default) {
                $over[$role] = $picked;
            }
        }
        // hidden pages: only hrefs the menu really has
        $known  = array_column(admin_nav(), 'href');
        $hidden = array_values(array_intersect(array_map('strval', is_array($_POST['hide'] ?? null) ? array_keys($_POST['hide']) : []), $known));
        Settings::set('rolemenu_roles', $over === [] ? '' : (string) json_encode($over), 'string', 'admin');
        Settings::set('rolemenu_hidden', implode(',', $hidden), 'string', 'admin');
        foreach ($switches as $key => $_l) {
            Settings::set($key, isset($_POST['sw'][$key]) ? '1' : '0', 'bool', 'admin');
        }
        Logger::audit('menu.setup', 'settings', 'rolemenu_roles', $before, ['roles' => $over, 'hidden' => $hidden], 'menu rows per role saved');
        $flash = ['ok', 'सुरक्षित भयो। अर्को पेज खोल्दा नयाँ मेनु देखिन्छ। · Saved. The menu changes on the next page each person opens.'
            . ($cut !== [] ? ' (' . implode(', ', $cut) . ': बढीमा ७ वटा मात्र राखियो · kept the first 7.)' : '')];
    } elseif ($act === 'reset') {
        Settings::set('rolemenu_roles', '', 'string', 'admin');
        Logger::audit('menu.setup', 'settings', 'rolemenu_roles', null, null, 'menu rows back to the defaults');
        $flash = ['ok', 'सुरुको मेनुमा फर्काइयो। · Back to the default rows.'];
    }
}

// what is on now
$nowRows = [];
foreach ($roles as $role) {
    $nowRows[$role] = RoleMenu::roleHubs($role);
}
$hiddenNow = array_flip(RoleMenu::hidden());
$allPages  = admin_nav();
$idx       = RoleMenu::index();

// who opened what in the last 30 days (the menu's own counter, kv_store nav_use)
$opened = [];
try {
    foreach (Database::fetchAll("SELECT kvalue FROM kv_store WHERE kscope = 'nav_use'") as $r) {
        $j = json_decode((string) $r['kvalue'], true);
        if (!is_array($j)) { continue; }
        foreach ($j as $href => $v) {
            if (is_array($v) && (int) ($v[1] ?? 0) >= time() - 30 * 86400) {
                $opened[(string) $href] = ($opened[(string) $href] ?? 0) + 1;
            }
        }
    }
} catch (Throwable $ex) {
    $opened = [];
}

$dis  = $isSa ? '' : ' disabled';
$csrf = Security::csrfField();
admin_header('मेनु मिलाउने · Menu setup', 'menu-setup');
?>
<style>
.ms-note{font-size:14px;color:var(--mut);margin:0 0 12px;line-height:1.55}
.ms-grid{width:100%;border-collapse:collapse}
.ms-grid th,.ms-grid td{padding:8px 6px;border-bottom:1px solid var(--line);text-align:center;font-size:13.5px}
.ms-grid th:first-child,.ms-grid td:first-child{text-align:left;white-space:nowrap}
.ms-grid th small,.ms-grid td small{display:block;font-weight:500;color:var(--mut);font-size:11.5px}
.ms-grid input[type=checkbox]{width:22px;height:22px}
.ms-sw{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 6px}
.ms-sw label{display:flex;align-items:center;gap:10px;min-height:48px;padding:8px 14px;border:1px solid var(--line);border-radius:12px;background:var(--card);cursor:pointer}
.ms-sw label small{display:block;color:var(--mut);font-size:12px}
.ms-sw input{width:22px;height:22px}
.ms-pages{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:6px}
.ms-pages label{display:flex;align-items:center;gap:8px;min-height:44px;padding:6px 10px;border:1px solid var(--line);border-radius:10px;font-size:13.5px}
.ms-pages label em{margin-left:auto;font-style:normal;font-size:11.5px;color:var(--mut);white-space:nowrap}
.ms-pages label.zero em{color:#b45309;font-weight:700}
.ms-pages input{width:20px;height:20px;flex:0 0 auto}
.ms-count{font-size:12px;color:var(--mut)}
.ms-count.over{color:#b91c1c;font-weight:700}
</style>
<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>
<p class="ms-note">यहाँ <b>मेनुमा के देखिने</b> मात्र मिलाइन्छ — कसले कुन पेज खोल्न पाउने भन्ने <a href="/admin/permissions.php">Roles &amp; Permissions</a> मै रहन्छ।
टिक नलागेको काम "＋ थप · More" भित्र र खोज्ने बाकस (कुन काम?) बाट सधैं भेटिन्छ।<br>
<span lang="en">This page only arranges what is drawn. An unticked row moves under "More"; the menu search still finds every page.</span></p>

<form method="post">
<?= $csrf ?><input type="hidden" name="act" value="save">

<div class="panel"><h2>१. स्विच · Switches</h2>
  <div class="ms-sw">
  <?php foreach ($switches as $key => [$ne, $en]): ?>
    <label><input type="checkbox" name="sw[<?= $e($key) ?>]" value="1"<?= Settings::getBool($key, true) ? ' checked' : '' ?><?= $dis ?>>
      <span><b><?= $e($ne) ?></b><small><?= $e($en) ?></small></span></label>
  <?php endforeach; ?>
  </div>
</div>

<div class="panel"><h2>२. कुन रोलले कुन मुख्य काम देख्ने · Main rows per role (max 7)</h2>
  <div style="overflow-x:auto"><table class="ms-grid no-card">
    <thead><tr><th>रोल · Role</th>
      <?php foreach ($hubs as $hk => $hub): ?><th><?= $e($hub['ne']) ?><small><?= $e($hub['en']) ?></small></th><?php endforeach; ?>
      <th>जम्मा</th></tr></thead>
    <tbody>
    <?php foreach ($roles as $role): $cap = $role === 'superadmin' ? count($hubs) : RoleMenu::MAX_MAIN; ?>
      <tr data-cap="<?= $cap ?>"><td><b><?= $e($roleName[$role][0]) ?></b><small><?= $e($roleName[$role][1]) ?></small></td>
        <?php foreach ($hubs as $hk => $hub): ?>
          <td><input type="checkbox" name="h[<?= $e($role) ?>][<?= $e($hk) ?>]" value="1" aria-label="<?= $e($roleName[$role][1] . ': ' . $hub['en']) ?>"<?= in_array($hk, $nowRows[$role], true) ? ' checked' : '' ?><?= $dis ?>></td>
        <?php endforeach; ?>
        <td><span class="ms-count"></span></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div>

<div class="panel"><h2>३. मेनुबाट लुकाउने पेज · Pages kept out of the menu</h2>
  <p class="ms-note">टिक लगाएको पेज मेनुमा देखिँदैन (खोज र लिङ्कबाट खुल्छ)। दायाँको संख्या = पछिल्लो ३० दिनमा कति जनाले खोले।
  <span lang="en">Ticked = not drawn in the menu. The number = how many people opened it in the last 30 days; <b style="color:#b45309">0</b> = nobody.</span></p>
  <?php foreach ($hubs as $hk => $hub):
      $mine = array_values(array_filter($allPages, static fn(array $p): bool => ($idx[(string) $p['href']][0] ?? RoleMenu::fallbackHub((string) $p['section'])) === $hk));
      if ($mine === []) { continue; } ?>
    <h3 style="margin:14px 0 6px;font-size:15px"><?= $e($hub['ne'] . ' · ' . $hub['en']) ?></h3>
    <div class="ms-pages">
    <?php foreach ($mine as $p): $href = (string) $p['href']; $n = (int) ($opened[$href] ?? 0); ?>
      <label class="<?= $n === 0 ? 'zero' : '' ?>"><input type="checkbox" name="hide[<?= $e($href) ?>]" value="1"<?= isset($hiddenNow[$href]) ? ' checked' : '' ?><?= $dis ?>>
        <span><?= $e(admin_plain((string) $p['label'])) ?></span><em><?= $n ?></em></label>
    <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($isSa): ?>
<p style="display:flex;gap:10px;flex-wrap:wrap">
  <button class="btn" type="submit">सुरक्षित गर्नुहोस् · Save</button>
  <button class="btn ghost" type="submit" name="act" value="reset" onclick="return confirm('सुरुको मेनुमा फर्काउने? · Back to the default rows?')">सुरुकै मेनु · Back to default</button>
</p>
<?php else: ?>
<p class="ms-note">सुपर-एडमिनले मात्र बदल्न मिल्छ। · Only a super-admin may change this.</p>
<?php endif; ?>
</form>
<script>
(function(){
  function count(tr){
    var n=tr.querySelectorAll('input[type=checkbox]:checked').length,cap=parseInt(tr.getAttribute('data-cap'),10)||7,c=tr.querySelector('.ms-count');
    if(c){c.textContent=n+' / '+cap;c.classList.toggle('over',n>cap);}
  }
  document.querySelectorAll('.ms-grid tbody tr').forEach(function(tr){
    count(tr);
    tr.addEventListener('change',function(ev){
      var cap=parseInt(tr.getAttribute('data-cap'),10)||7;
      if(ev.target.checked&&tr.querySelectorAll('input[type=checkbox]:checked').length>cap){
        ev.target.checked=false;alert('बढीमा '+cap+' वटा मात्र · At most '+cap+' main rows.');
      }
      count(tr);
    });
  });
})();
</script>
<?php admin_footer();
