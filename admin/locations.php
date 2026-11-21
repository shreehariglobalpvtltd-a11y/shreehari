<?php
/**
 * admin/locations.php — the company's own places (21 Nov 2026).
 *
 * Owner: "sabai thau ko location, map, photo, contact details add garne
 * thau rakhdinus, m fill garchhu; save garepachhi customer ma dekhiyos."
 *
 * dashboard.view to look, settings.manage to save. Photos are re-encoded
 * into uploads/locations/ (max 3 per place). A place shows on /offices only
 * when "Show on website" is ticked AND it has a map link or an address.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/contentpages.php';
require_once INCLUDE_PATH . '/companylocations.php';

$admin   = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();
$flash   = null;
$e       = static fn($v): string => Security::e((string) $v);
$tableOk = CompanyLocations::ensure();

if ($tableOk && $canEdit && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } else {
        $action = (string) ($_POST['action'] ?? 'save');
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $old = $id > 0 ? CompanyLocations::get($id) : null;
            if ($id > 0 && $old === null) {
                throw new RuntimeException('That place no longer exists.');
            }
            if ($action === 'delete' && $old !== null) {
                CompanyLocations::delete($id);
                $flash = ['ok', '“' . $old['name'] . '” removed. / हटाइयो।'];
                $id = 0;
            } elseif ($action === 'photo_remove' && $old !== null) {
                $idx = (int) ($_POST['photo'] ?? -1);
                $photos = $old['photos'];
                if (isset($photos[$idx])) {
                    array_splice($photos, $idx, 1);
                }
                $in = self_input_from($old);
                CompanyLocations::save($in, (int) $admin['id'], $id, $photos);
                $flash = ['ok', 'Photo removed. / फोटो हटाइयो।'];
                $_GET['edit'] = (string) $id;
            } else {
                $photos = $old['photos'] ?? [];
                $files = $_FILES['photos'] ?? null;
                if (is_array($files) && is_array($files['name'] ?? null)) {
                    foreach ($files['name'] as $i => $_n) {
                        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                            continue;
                        }
                        if (count($photos) >= CompanyLocations::MAX_PHOTOS) {
                            break;
                        }
                        $one = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i],
                                'error' => $files['error'][$i], 'size' => $files['size'][$i]];
                        $photos[] = ContentPages::saveUpload($one, 'locations', 'place-' . ($id ?: 'new'));
                    }
                }
                $id = CompanyLocations::save($_POST, (int) $admin['id'], $id ?: null, $photos);
                $flash = ['ok', 'Saved. / सेभ भयो। ' . (!empty($_POST['show_public']) ? 'Customers see it on /offices.' : '(Hidden from customers.)')];
                $_GET['edit'] = (string) $id;
            }
        } catch (Throwable $ex) {
            $flash = ['bad', $ex->getMessage()];
            if ($id > 0) {
                $_GET['edit'] = (string) $id;
            } else {
                $_GET['new'] = '1';
            }
        }
    }
}

/** The saved fields as form input (used when only photos change). */
function self_input_from(array $p): array
{
    return ['name' => $p['name'], 'name_ne' => $p['nameNe'], 'kind' => $p['kind'], 'town' => $p['town'], 'address' => $p['address'],
            'address_ne' => $p['addressNe'], 'map_url' => $p['map'], 'phone' => $p['phone'], 'whatsapp' => $p['whatsapp'],
            'contact_person' => $p['person'], 'hours' => $p['hours'], 'note' => $p['note'], 'show_public' => $p['show_public'] ? 1 : 0,
            'sort_order' => $p['sort']];
}

$editId = (int) ($_GET['edit'] ?? 0);
$isNew  = ($_GET['new'] ?? '') === '1';
$cur    = $tableOk && $editId > 0 ? CompanyLocations::get($editId) : null;
$list   = $tableOk ? CompanyLocations::all() : [];

admin_header('Our locations', 'locations');
echo ContentPages::adminCss();
?>
<style>
.lc-list{display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr))}
.lc-item{display:flex;gap:12px;align-items:flex-start;padding:12px;border:1px solid var(--line,#e2e8f0);border-radius:12px;background:var(--card,#fff);text-decoration:none;color:inherit;min-height:44px}
.lc-item img,.lc-ph{width:64px;height:64px;border-radius:10px;object-fit:cover;flex:none;background:#eef2f7;display:grid;place-items:center;font-size:26px}
.lc-item b{display:block;font-size:15px}
.lc-tag{display:inline-block;font-size:11.5px;padding:2px 8px;border-radius:99px;margin:4px 4px 0 0;background:#eef2f7}
.lc-tag.on{background:#dcfce7;color:#166534}.lc-tag.off{background:#fef3c7;color:#92400e}
.lc-photos{display:flex;gap:8px;flex-wrap:wrap}.lc-photos figure{margin:0;text-align:center}
.lc-photos img{width:110px;height:84px;object-fit:cover;border-radius:10px;display:block}
</style>
<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>
<?php if (!$tableOk): ?>
  <div class="flash bad">The company_locations table could not be made — run <code>database/upgrade-2026-11-21-company-locations.sql</code>.</div>
<?php elseif ($cur !== null || $isNew):
    $v   = static fn(string $k, string $pk): string => (string) ($cur[$k] ?? (is_scalar($_POST[$pk] ?? null) ? $_POST[$pk] : ''));
    $ro  = $canEdit ? '' : ' readonly';
    $dis = $canEdit ? '' : ' disabled';
?>
<form method="post" enctype="multipart/form-data" data-no-guard class="ct-form">
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int) ($cur['id'] ?? 0) ?>">
  <div class="ct-head">
    <h2 style="margin:0"><?= $cur !== null ? '📍 ' . $e($cur['name']) : '➕ New place · नयाँ ठाउँ' ?></h2>
    <a class="btn ghost sm" href="/admin/locations.php">← All places</a>
    <a class="btn ghost sm" href="/offices" target="_blank" rel="noopener">👁️ Customer page</a>
  </div>
  <div class="ct-grid">
    <section class="ct-card">
      <h3>ठाउँ · Place</h3>
      <label class="ct-f">Name (English) *<input name="name" required maxlength="160" value="<?= $e($v('name', 'name')) ?>"<?= $ro ?>></label>
      <label class="ct-f">नाम (नेपाली)<input name="name_ne" maxlength="160" value="<?= $e($v('nameNe', 'name_ne')) ?>"<?= $ro ?>></label>
      <div class="ct-row">
        <label class="ct-f">What is it · के हो<select name="kind"<?= $dis ?>>
          <?php foreach (CompanyLocations::KINDS as $k => [$ne, $en]): ?><option value="<?= $k ?>"<?= ($cur['kind'] ?? 'parking') === $k ? ' selected' : '' ?>><?= $e($ne . ' · ' . $en) ?></option><?php endforeach; ?>
        </select></label>
        <label class="ct-f">Town · सहर <small>for the ticket map QR</small><input name="town" maxlength="60" value="<?= $e($v('town', 'town')) ?>"<?= $ro ?>></label>
      </div>
      <label class="ct-f">Address (English)<input name="address" maxlength="255" value="<?= $e($v('address', 'address')) ?>"<?= $ro ?>></label>
      <label class="ct-f">ठेगाना (नेपाली)<input name="address_ne" maxlength="255" value="<?= $e($v('addressNe', 'address_ne')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Google Maps link · नक्सा <small>paste the share link, or “28.0713,81.6324”</small>
        <input name="map_url" maxlength="500" inputmode="url" value="<?= $e($v('map', 'map_url')) ?>"<?= $ro ?>></label>
      <?php if (($cur['map'] ?? '') !== ''): ?><a class="btn ghost sm" href="<?= $e($cur['map']) ?>" target="_blank" rel="noopener">🗺️ Open map</a><?php endif; ?>
    </section>
    <section class="ct-card">
      <h3>सम्पर्क · Contact</h3>
      <label class="ct-f">Phone · फोन <small>two numbers: 98xxxxxxxx / 98xxxxxxxx</small><input name="phone" maxlength="60" inputmode="tel" value="<?= $e($v('phone', 'phone')) ?>"<?= $ro ?>></label>
      <label class="ct-f">WhatsApp <small>with country code, e.g. 977986…</small><input name="whatsapp" maxlength="20" inputmode="tel" value="<?= $e($v('whatsapp', 'whatsapp')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Person to ask · सम्पर्क व्यक्ति<input name="contact_person" maxlength="120" value="<?= $e($v('person', 'contact_person')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Hours · खुल्ने समय<input name="hours" maxlength="120" placeholder="6 AM – 9 PM" value="<?= $e($v('hours', 'hours')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Note for passengers · सूचना<input name="note" maxlength="255" value="<?= $e($v('note', 'note')) ?>"<?= $ro ?>></label>
      <div class="ct-row">
        <label class="ct-f ct-check"><input type="checkbox" name="show_public" value="1"<?= !empty($cur['show_public']) || ($cur === null && !empty($_POST['show_public'])) ? ' checked' : '' ?><?= $dis ?>> 👁️ Show on website · ग्राहकलाई देखाउ</label>
        <label class="ct-f">Order<input type="number" name="sort_order" value="<?= (int) ($cur['sort'] ?? 0) ?>"<?= $ro ?>></label>
      </div>
    </section>
    <section class="ct-card ct-wide">
      <h3>फोटो · Photos (up to <?= CompanyLocations::MAX_PHOTOS ?>)</h3>
      <?php $ph = $cur['photos'] ?? []; if ($ph !== []): ?><div class="lc-photos">
        <?php foreach ($ph as $i => $p): ?><figure><img src="/<?= $e($p) ?>" alt="">
          <?php if ($canEdit): ?><button class="btn ghost sm" type="submit" form="lcRm<?= $i ?>">Remove</button><?php endif; ?></figure><?php endforeach; ?>
      </div><?php else: ?><p class="muted" style="font-size:13px">No photo yet. Use your own photos of the gate, board or parking.</p><?php endif; ?>
      <?php if ($canEdit && count($ph) < CompanyLocations::MAX_PHOTOS): ?>
        <label class="ct-f">Add photos (PNG / JPG / WebP, ≤ 8 MB each)<input type="file" name="photos[]" multiple accept="image/png,image/jpeg,image/webp"></label>
      <?php endif; ?>
    </section>
  </div>
  <?php if ($canEdit): ?>
  <div class="ct-save"><button class="btn ok" type="submit">💾 Save · सेभ गर्नुहोस्</button><span class="muted" style="font-size:12.5px">Every change is kept in the audit log.</span></div>
  <?php endif; ?>
</form>
<?php if ($cur !== null && $canEdit): ?>
  <?php foreach ($cur['photos'] as $i => $_p): ?><form id="lcRm<?= $i ?>" method="post" data-no-guard><?= Security::csrfField() ?><input type="hidden" name="action" value="photo_remove"><input type="hidden" name="id" value="<?= (int) $cur['id'] ?>"><input type="hidden" name="photo" value="<?= $i ?>"></form><?php endforeach; ?>
  <form method="post" data-no-guard style="margin-top:14px" onsubmit="return confirm('Remove this place?')"><?= Security::csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $cur['id'] ?>"><button class="btn ghost sm" type="submit">🗑️ Remove place</button></form>
<?php endif; ?>

<?php else: ?>
  <div class="ct-head">
    <h2 style="margin:0">📍 Our locations · हाम्रा ठाउँ (<?= count($list) ?>)</h2>
    <?php if ($canEdit): ?><a class="btn ok sm" href="/admin/locations.php?new=1">➕ Add place</a><?php endif; ?>
    <a class="btn ghost sm" href="/offices" target="_blank" rel="noopener">👁️ Customer page</a>
  </div>
  <p class="muted" style="margin:0 0 14px;font-size:13.5px">Fill map link, photos and phone for each place. A place shows to customers on <a href="/offices" target="_blank" rel="noopener">/offices</a> when “Show on website” is ticked and it has a map or an address. The map link is also used for the pickup QR on tickets from that town.</p>
  <div class="lc-list">
    <?php foreach ($list as $p): $k = CompanyLocations::KINDS[$p['kind']]; ?>
      <a class="lc-item" href="/admin/locations.php?edit=<?= (int) $p['id'] ?>">
        <?php if ($p['photos'] !== []): ?><img src="/<?= $e($p['photos'][0]) ?>" alt=""><?php else: ?><span class="lc-ph">📍</span><?php endif; ?>
        <span><b><?= $e($p['name']) ?></b><?php if ($p['nameNe'] !== ''): ?><small class="muted"><?= $e($p['nameNe']) ?></small><?php endif; ?><br>
          <span class="lc-tag"><?= $e($k[0] . ' · ' . $k[1]) ?></span>
          <?php if ($p['show_public'] && $p['filled']): ?><span class="lc-tag on">👁️ on website</span><?php elseif (!$p['filled']): ?><span class="lc-tag off">✍️ fill map / address</span><?php else: ?><span class="lc-tag off">hidden</span><?php endif; ?>
          <?php if ($p['map'] !== ''): ?><span class="lc-tag">🗺️ map</span><?php endif; ?>
          <?php if ($p['phone'] !== ''): ?><span class="lc-tag">📞 <?= $e($p['phone']) ?></span><?php endif; ?>
          <?php if ($p['photos'] !== []): ?><span class="lc-tag">📷 <?= count($p['photos']) ?></span><?php endif; ?>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php admin_footer();
