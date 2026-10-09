<?php
/**
 * admin/places.php — places to visit for /explore (9 Oct 2026, owner:
 * "Nepal ko famous thau haru ko list, India ko list, recommendation").
 *
 * Same pattern as admin/tours.php and admin/company.php: dashboard.view to
 * look, settings.manage to save, one audit row per change, photos re-encoded
 * into uploads/places/. A place is hidden (is_active = 0), never deleted.
 * "Featured" puts it in "Our picks" on /explore and in the home teaser.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/contentpages.php';
$admin   = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();
$flash   = null;
$e       = static fn($v): string => Security::e((string) $v);

$tableOk = true;
try {
    Database::scalar('SELECT COUNT(*) FROM travel_places');
} catch (Throwable $ex) {
    $tableOk = false;
}
$CATS = ['pilgrimage' => 'Pilgrimage · तीर्थ', 'heritage' => 'Heritage · सम्पदा', 'nature' => 'Nature · प्रकृति', 'wildlife' => 'Wildlife · वन्यजन्तु',
         'lake' => 'Lakes · ताल', 'hill' => 'Hills · पहाड', 'adventure' => 'Adventure · साहसिक', 'city' => 'City · सहर'];

if ($tableOk && $canEdit && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } else {
        $action = (string) ($_POST['action'] ?? 'save');
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $old = $id > 0 ? Database::fetch('SELECT * FROM travel_places WHERE id = :id', ['id' => $id]) : null;
            if ($id > 0 && $old === null) {
                throw new RuntimeException('That place no longer exists.');
            }
            if ($action === 'toggle' && $old !== null) {
                $on = (int) $old['is_active'] === 1 ? 0 : 1;
                Database::update('travel_places', ['is_active' => $on], 'id = :id', ['id' => $id]);
                Logger::audit('content.place.active', 'travel_place', (string) $id, ['is_active' => (int) $old['is_active']], ['is_active' => $on], 'place ' . $old['slug']);
                $flash = ['ok', '“' . $old['name_en'] . '” is now ' . ($on ? 'shown on the website' : 'hidden') . '.'];
            } elseif ($action === 'image_remove' && $old !== null) {
                Database::update('travel_places', ['image' => null], 'id = :id', ['id' => $id]);
                Logger::audit('content.place.image', 'travel_place', (string) $id, ['image' => $old['image']], ['image' => null], 'photo removed');
                $flash = ['ok', 'Photo removed.'];
                $_GET['edit'] = (string) $id;
            } else {
                $s = static fn(string $k, int $max): string => trim(Security::clean((string) ($_POST[$k] ?? ''), $max));
                $nameEn = $s('name_en', 120);
                if ($nameEn === '') {
                    throw new RuntimeException('Name (English) is needed.');
                }
                $slug = strtolower($s('slug', 80));
                if ($slug === '') {
                    $slug = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($nameEn)), '-'), 0, 80);
                }
                if (preg_match('/^[a-z0-9-]{2,80}$/', $slug) !== 1) {
                    throw new RuntimeException('Web key: small letters, numbers and dashes only.');
                }
                if (Database::scalar('SELECT id FROM travel_places WHERE slug = :s AND id <> :id', ['s' => $slug, 'id' => $id], null) !== null) {
                    throw new RuntimeException('Another place already uses “' . $slug . '”.');
                }
                $num = static function (string $k, float $min, float $max): ?float {
                    $v = trim((string) ($_POST[$k] ?? ''));
                    if ($v === '') {
                        return null;
                    }
                    if (!is_numeric($v) || (float) $v < $min || (float) $v > $max) {
                        throw new RuntimeException('Check ' . $k . ' — a number between ' . $min . ' and ' . $max . '.');
                    }
                    return (float) $v;
                };
                $km = $num('km_hint', 0, 5000);
                $row = [
                    'slug' => $slug, 'country' => ($_POST['country'] ?? '') === 'IN' ? 'IN' : 'NP',
                    'region' => $s('region', 80) ?: null,
                    'category' => ContentPages::normCategory((string) ($_POST['category'] ?? '')),
                    'name_en' => $nameEn, 'name_ne' => $s('name_ne', 160) ?: null, 'name_hi' => $s('name_hi', 160) ?: null,
                    'blurb_en' => $s('blurb_en', 2000) ?: null, 'blurb_ne' => $s('blurb_ne', 2000) ?: null, 'blurb_hi' => $s('blurb_hi', 2000) ?: null,
                    'best_season_en' => $s('best_season_en', 160) ?: null, 'best_season_ne' => $s('best_season_ne', 200) ?: null,
                    'how_to_reach_en' => $s('how_to_reach_en', 1000) ?: null, 'how_to_reach_ne' => $s('how_to_reach_ne', 1000) ?: null,
                    'unesco' => !empty($_POST['unesco']) ? 1 : 0,
                    'lat' => $num('lat', -90, 90), 'lng' => $num('lng', -180, 180),
                    'near_stop' => $s('near_stop', 80) ?: null, 'km_hint' => $km !== null ? (int) round($km) : null,
                    'image_credit' => $s('image_credit', 190) ?: null,
                    'is_featured' => !empty($_POST['is_featured']) ? 1 : 0, 'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                    'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                ];
                if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $row['image'] = ContentPages::saveUpload($_FILES['image'], 'places', $slug);
                }
                if ($old === null) {
                    $id = Database::insert('travel_places', $row);
                    Logger::audit('content.place.create', 'travel_place', (string) $id, null, $row, 'place created');
                    $flash = ['ok', 'Place added. / ठाउँ थपियो।'];
                } else {
                    $dOld = [];
                    $dNew = [];
                    foreach ($row as $k => $v) {
                        $c = $old[$k] ?? null;
                        $same = ($v === null && $c === null) || ($v !== null && $c !== null && (is_float($v) ? abs((float) $c - $v) < 1e-7 : (string) $c === (string) $v));
                        if (!$same) {
                            $dOld[$k] = $c;
                            $dNew[$k] = $v;
                        }
                    }
                    if ($dNew !== []) {
                        Database::update('travel_places', $row, 'id = :id', ['id' => $id]);
                        Logger::audit('content.place.save', 'travel_place', (string) $id, $dOld, $dNew, 'place edited by admin #' . (int) $admin['id']);
                        $flash = ['ok', count($dNew) . ' field(s) saved. / सेभ भयो।'];
                    } else {
                        $flash = ['ok', 'Nothing changed.'];
                    }
                }
                $_GET['edit'] = (string) $id;
            }
        } catch (Throwable $ex) {
            $flash = ['bad', $ex->getMessage()];
            if ($id > 0) {
                $_GET['edit'] = (string) $id;
            } elseif ($action === 'save') {
                $_GET['new'] = '1';
            }
        }
    }
}

$editId  = (int) ($_GET['edit'] ?? 0);
$isNew   = ($_GET['new'] ?? '') === '1';
$cur     = $tableOk && $editId > 0 ? Database::fetch('SELECT * FROM travel_places WHERE id = :id', ['id' => $editId]) : null;
$fc      = (string) ($_GET['c'] ?? '');
$list    = $tableOk ? Database::fetchAll('SELECT * FROM travel_places' . (in_array($fc, ['NP', 'IN'], true) ? ' WHERE country = :c' : '') . ' ORDER BY is_active DESC, country DESC, sort_order, name_en',
    in_array($fc, ['NP', 'IN'], true) ? ['c' => $fc] : []) : [];

admin_header('Places to visit', 'places');
echo ContentPages::adminCss();
?>
<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>
<?php if (!$tableOk): ?>
  <div class="flash bad">The travel_places table is missing — run <code>database/upgrade-2026-10-09-content.sql</code>.</div>
<?php else: ?>

<p class="muted" style="margin:0 0 14px;font-size:13.5px">
  Places on <a href="/explore" target="_blank" rel="noopener">/explore</a> (also /hi/explore, /ne/explore). A place within ~150 km of Nepalgunj / Rupaidiha
  (the “Near stop”) is listed under <b>Near the border</b>. ⭐ Featured = “Our picks” and the home page.
  <?php if (!$canEdit): ?><br><b>View only</b> — saving needs the Settings permission.<?php endif; ?>
</p>

<?php if ($cur !== null || $isNew):
    $v = static fn(string $k): string => (string) ($cur[$k] ?? ($cur === null && is_scalar($_POST[$k] ?? null) ? $_POST[$k] : ''));
    $ro = $canEdit ? '' : ' readonly';
    $dis = $canEdit ? '' : ' disabled';
?>
<form method="post" enctype="multipart/form-data" data-no-guard class="ct-form">
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int) ($cur['id'] ?? 0) ?>">
  <div class="ct-head">
    <h2 style="margin:0"><?= $cur !== null ? '✏️ ' . $e($cur['name_en']) : '➕ New place' ?></h2>
    <a class="btn ghost sm" href="/admin/places.php">← All places</a>
    <?php if ($cur !== null): ?><a class="btn ghost sm" href="/explore#place-<?= $e($cur['slug']) ?>" target="_blank" rel="noopener">👁️ View</a><?php endif; ?>
  </div>
  <div class="ct-grid">
    <section class="ct-card">
      <h3>Basics</h3>
      <label class="ct-f">Name (English) *<input name="name_en" required maxlength="120" value="<?= $e($v('name_en')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Name (नेपाली)<input name="name_ne" maxlength="160" value="<?= $e($v('name_ne')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Name (हिंदी) <small>blank = Nepali spelling</small><input name="name_hi" maxlength="160" value="<?= $e($v('name_hi')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Web key <small>blank = from the name</small><input name="slug" maxlength="80" pattern="[a-z0-9-]{2,80}" value="<?= $e($v('slug')) ?>"<?= $ro ?>></label>
      <div class="ct-row">
        <label class="ct-f">Country<select name="country"<?= $dis ?>><option value="NP"<?= ($cur['country'] ?? 'NP') === 'NP' ? ' selected' : '' ?>>🇳🇵 Nepal</option><option value="IN"<?= ($cur['country'] ?? '') === 'IN' ? ' selected' : '' ?>>🇮🇳 India</option></select></label>
        <label class="ct-f">Category<select name="category"<?= $dis ?>>
          <?php foreach ($CATS as $cv => $cl): ?><option value="<?= $cv ?>"<?= ($cur['category'] ?? 'heritage') === $cv ? ' selected' : '' ?>><?= $e($cl) ?></option><?php endforeach; ?>
        </select></label>
      </div>
      <label class="ct-f">Region / district<input name="region" maxlength="80" value="<?= $e($v('region')) ?>"<?= $ro ?>></label>
      <div class="ct-row">
        <label class="ct-f ct-check"><input type="checkbox" name="unesco" value="1"<?= !empty($cur['unesco']) ? ' checked' : '' ?><?= $dis ?>> 🏛️ UNESCO World Heritage</label>
        <label class="ct-f ct-check"><input type="checkbox" name="is_featured" value="1"<?= !empty($cur['is_featured']) ? ' checked' : '' ?><?= $dis ?>> ⭐ Featured</label>
        <label class="ct-f ct-check"><input type="checkbox" name="is_active" value="1"<?= ($cur === null || (int) $cur['is_active'] === 1) ? ' checked' : '' ?><?= $dis ?>> 👁️ Show on website</label>
      </div>
      <label class="ct-f">Order<input type="number" name="sort_order" value="<?= (int) ($cur['sort_order'] ?? 0) ?>"<?= $ro ?>></label>
    </section>
    <section class="ct-card">
      <h3>Getting there</h3>
      <div class="ct-row">
        <label class="ct-f">Near stop <small>Nepalgunj, Rupaidiha, Lucknow…</small><input name="near_stop" maxlength="80" value="<?= $e($v('near_stop')) ?>"<?= $ro ?>></label>
        <label class="ct-f">≈ km from it<input name="km_hint" inputmode="numeric" value="<?= $e($v('km_hint')) ?>"<?= $ro ?>></label>
      </div>
      <label class="ct-f">How to reach (English)<textarea name="how_to_reach_en" maxlength="1000"<?= $ro ?>><?= $e($v('how_to_reach_en')) ?></textarea></label>
      <label class="ct-f">How to reach (नेपाली)<textarea name="how_to_reach_ne" maxlength="1000"<?= $ro ?>><?= $e($v('how_to_reach_ne')) ?></textarea></label>
      <div class="ct-row">
        <label class="ct-f">Best season (English)<input name="best_season_en" maxlength="160" value="<?= $e($v('best_season_en')) ?>"<?= $ro ?>></label>
        <label class="ct-f">Best season (नेपाली)<input name="best_season_ne" maxlength="200" value="<?= $e($v('best_season_ne')) ?>"<?= $ro ?>></label>
      </div>
      <div class="ct-row">
        <label class="ct-f">Latitude<input name="lat" inputmode="decimal" value="<?= $e($v('lat')) ?>"<?= $ro ?>></label>
        <label class="ct-f">Longitude<input name="lng" inputmode="decimal" value="<?= $e($v('lng')) ?>"<?= $ro ?>></label>
      </div>
    </section>
    <section class="ct-card ct-wide">
      <h3>About the place</h3>
      <div class="ct-row">
        <label class="ct-f">Short description (English)<textarea name="blurb_en" rows="4" maxlength="2000"<?= $ro ?>><?= $e($v('blurb_en')) ?></textarea></label>
        <label class="ct-f">Short description (नेपाली)<textarea name="blurb_ne" rows="4" maxlength="2000"<?= $ro ?>><?= $e($v('blurb_ne')) ?></textarea></label>
      </div>
      <label class="ct-f">Short description (हिंदी) <small>blank = English</small><textarea name="blurb_hi" rows="3" maxlength="2000"<?= $ro ?>><?= $e($v('blurb_hi')) ?></textarea></label>
    </section>
    <section class="ct-card">
      <h3>Photo</h3>
      <?php if ($cur !== null && !empty($cur['image'])): ?><img class="ct-img" src="/<?= $e($cur['image']) ?>" alt="">
      <?php else: ?><p class="muted" style="font-size:13px">No photo — the website shows a coloured tile with an icon. Use only your own or properly licensed photos, with the credit below.</p><?php endif; ?>
      <?php if ($canEdit): ?><label class="ct-f">Upload (PNG / JPG / WebP, ≤ 8 MB)<input type="file" name="image" accept="image/png,image/jpeg,image/webp"></label><?php endif; ?>
      <label class="ct-f">Photo credit<input name="image_credit" maxlength="190" value="<?= $e($v('image_credit')) ?>"<?= $ro ?>></label>
    </section>
  </div>
  <?php if ($canEdit): ?>
  <div class="ct-save"><button class="btn ok" type="submit">💾 Save · सेभ गर्नुहोस्</button><span class="muted" style="font-size:12.5px">Every change is kept in the audit log (old → new).</span></div>
  <?php endif; ?>
</form>
<?php if ($cur !== null && !empty($cur['image']) && $canEdit): ?>
  <form method="post" data-no-guard style="margin-top:8px"><?= Security::csrfField() ?><input type="hidden" name="action" value="image_remove"><input type="hidden" name="id" value="<?= (int) $cur['id'] ?>"><button class="btn ghost sm" type="submit">Remove photo</button></form>
<?php endif; ?>

<?php else: ?>
  <div class="ct-head">
    <h2 style="margin:0"><?= count($list) ?> place<?= count($list) === 1 ? '' : 's' ?></h2>
    <a class="btn ghost sm<?= $fc === '' ? ' on' : '' ?>" href="/admin/places.php">All</a>
    <a class="btn ghost sm" href="/admin/places.php?c=NP">🇳🇵 Nepal</a>
    <a class="btn ghost sm" href="/admin/places.php?c=IN">🇮🇳 India</a>
    <?php if ($canEdit): ?><a class="btn ok" href="/admin/places.php?new=1">➕ New place</a><?php endif; ?>
  </div>
  <?php if ($list === []): ?>
    <div class="ct-card" style="text-align:center;padding:26px">No places yet. <?= $canEdit ? 'Press “New place”, or load the research file with <code>tools/content-seed.php</code>.' : '' ?></div>
  <?php endif; ?>
  <div class="ct-list">
  <?php foreach ($list as $p): ?>
    <article class="ct-item">
      <div class="ct-thumb"><?= !empty($p['image']) ? '<img src="/' . $e($p['image']) . '" alt="">' : (ContentPages::CATS[$p['category']] ?? '📍') ?></div>
      <div class="ct-body">
        <b><?= $e($p['name_en']) ?></b> <?= (int) $p['is_featured'] === 1 ? '⭐' : '' ?> <?= (int) $p['unesco'] === 1 ? '🏛️' : '' ?>
        <div class="muted" style="font-size:12.5px"><?= $e($p['name_ne'] ?? '') ?></div>
        <div class="ct-meta">
          <span class="pill st-<?= (int) $p['is_active'] === 1 ? 'on' : 'off' ?>"><?= (int) $p['is_active'] === 1 ? 'On website' : 'Hidden' ?></span>
          <span><?= $p['country'] === 'IN' ? '🇮🇳 India' : '🇳🇵 Nepal' ?> · <?= $e($CATS[$p['category']] ?? $p['category']) ?></span>
          <?php if (!empty($p['near_stop'])): ?><span>📏 <?= $p['km_hint'] !== null ? '≈' . (int) $p['km_hint'] . ' km · ' : '' ?><?= $e($p['near_stop']) ?></span><?php endif; ?>
        </div>
      </div>
      <div class="ct-actions">
        <a class="btn sm" href="/admin/places.php?edit=<?= (int) $p['id'] ?>"><?= $canEdit ? 'Edit' : 'Open' ?></a>
        <?php if ($canEdit): ?>
          <form method="post" data-no-guard><?= Security::csrfField() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn ghost sm" type="submit"><?= (int) $p['is_active'] === 1 ? 'Hide' : 'Show' ?></button></form>
        <?php endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php endif; ?>
<?php admin_footer();
