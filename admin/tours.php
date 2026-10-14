<?php
/**
 * admin/tours.php — tour packages for /tours (9 Oct 2026, owner: "ghumne
 * tour package ko ni apply garna milne ecosystem").
 *
 * One list + one form, in the admin/company.php pattern:
 *   - anyone with dashboard.view may look; saving needs settings.manage
 *     (Auth::canManageSettings()); every change is one audit row (old → new)
 *   - draft → published → archived (never deleted: enquiries point at the id)
 *   - price blank = "price on request" on the website (the safe default — a
 *     price is only shown once the office types one)
 *   - photo re-encoded by GD into uploads/tours/
 *   - English / Hindi / Nepali boxes; a blank Hindi box shows English
 *
 * The coach leg note ("our bus ends at Rupaidiha, onward travel by partner")
 * is printed by the website for every package that uses our bus.
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
    Database::scalar('SELECT COUNT(*) FROM tour_packages');
} catch (Throwable $ex) {
    $tableOk = false;
}

/** Lines of a textarea → a JSON list (or NULL). */
$linesJson = static function (string $raw): ?string {
    $l = array_values(array_filter(array_map(static fn(string $s): string => trim($s), preg_split('/\R/u', $raw) ?: []), static fn(string $s): bool => $s !== ''));
    return $l !== [] ? json_encode(array_slice($l, 0, 20), JSON_UNESCAPED_UNICODE) : null;
};

$TRACK = ['slug', 'status', 'country', 'title_en', 'title_hi', 'title_ne', 'summary_en', 'summary_hi', 'summary_ne', 'route_en', 'route_ne', 'days', 'nights',
          'highlights_en', 'highlights_ne', 'itinerary_json', 'price_from', 'price_basis', 'coach_leg', 'partner_name', 'min_pax', 'audience', 'image', 'image_credit', 'is_featured', 'sort_order'];

/* 10 Oct 2026 (BIZ): the audience chip (solo / couple / family / group /
   pilgrim). The column arrives with database/upgrade-2026-10-10-biz.sql; until
   it has run the box is shown read-only and never written, so a save on an
   older database cannot fail. */
$hasAudience = false;
try {
    $hasAudience = $tableOk && (int) Database::scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tour_packages' AND COLUMN_NAME = 'audience'", [], 0) === 1;
} catch (Throwable $ex) {
    $hasAudience = false;
}

if ($tableOk && $canEdit && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } else {
        $action = (string) ($_POST['action'] ?? 'save');
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $old = $id > 0 ? Database::fetch('SELECT * FROM tour_packages WHERE id = :id', ['id' => $id]) : null;
            if ($id > 0 && $old === null) {
                throw new RuntimeException('That package no longer exists.');
            }
            if ($action === 'status' && $old !== null) {
                $st = (string) ($_POST['status'] ?? '');
                if (!in_array($st, ['draft', 'published', 'archived'], true)) {
                    throw new RuntimeException('Unknown status.');
                }
                Database::update('tour_packages', ['status' => $st], 'id = :id', ['id' => $id]);
                Logger::audit('content.tour.status', 'tour_package', (string) $id, ['status' => $old['status']], ['status' => $st], 'tour package ' . $old['slug']);
                $flash = ['ok', '“' . $old['title_en'] . '” is now ' . $st . '.'];
            } elseif ($action === 'image_remove' && $old !== null) {
                Database::update('tour_packages', ['image' => null], 'id = :id', ['id' => $id]);
                Logger::audit('content.tour.image', 'tour_package', (string) $id, ['image' => $old['image']], ['image' => null], 'photo removed');
                $flash = ['ok', 'Photo removed.'];
                $_GET['edit'] = (string) $id;
            } else {
                $s = static fn(string $k, int $max): string => trim(Security::clean((string) ($_POST[$k] ?? ''), $max));
                $titleEn = $s('title_en', 160);
                $slug = strtolower($s('slug', 80));
                if ($slug === '') {
                    $slug = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($titleEn)), '-'), 0, 80);
                }
                if ($titleEn === '') {
                    throw new RuntimeException('Title (English) is needed.');
                }
                if (preg_match('/^[a-z0-9-]{2,80}$/', $slug) !== 1) {
                    throw new RuntimeException('Web address: small letters, numbers and dashes only.');
                }
                if (Database::scalar('SELECT id FROM tour_packages WHERE slug = :s AND id <> :id', ['s' => $slug, 'id' => $id], null) !== null) {
                    throw new RuntimeException('Another package already uses the web address “' . $slug . '”.');
                }
                $price = trim((string) ($_POST['price_from'] ?? ''));
                if ($price !== '' && (!is_numeric($price) || (float) $price <= 0 || (float) $price > 9999999)) {
                    throw new RuntimeException('Price: a number in rupees, or leave it blank for “price on request”.');
                }
                $itin = [];
                $en = preg_split('/\R/u', (string) ($_POST['itin_en'] ?? '')) ?: [];
                $ne = preg_split('/\R/u', (string) ($_POST['itin_ne'] ?? '')) ?: [];
                $en = array_values(array_filter(array_map('trim', $en), static fn(string $x): bool => $x !== ''));
                $ne = array_values(array_filter(array_map('trim', $ne), static fn(string $x): bool => $x !== ''));
                for ($i = 0, $n = min(30, max(count($en), count($ne))); $i < $n; $i++) {
                    $itin[] = ['day' => $i + 1, 'en' => Security::clean($en[$i] ?? '', 400), 'ne' => Security::clean($ne[$i] ?? '', 400)];
                }
                $row = [
                    'slug' => $slug,
                    'status' => in_array($_POST['status'] ?? '', ['draft', 'published', 'archived'], true) ? (string) $_POST['status'] : 'draft',
                    'country' => in_array($_POST['country'] ?? '', ['Nepal', 'India', 'Both'], true) ? (string) $_POST['country'] : 'Nepal',
                    'title_en' => $titleEn, 'title_hi' => $s('title_hi', 200) ?: null, 'title_ne' => $s('title_ne', 200) ?: null,
                    'summary_en' => $s('summary_en', 2000) ?: null, 'summary_hi' => $s('summary_hi', 2000) ?: null, 'summary_ne' => $s('summary_ne', 2000) ?: null,
                    'route_en' => $s('route_en', 255) ?: null, 'route_ne' => $s('route_ne', 255) ?: null,
                    'days' => max(1, min(60, (int) ($_POST['days'] ?? 1))), 'nights' => max(0, min(60, (int) ($_POST['nights'] ?? 0))),
                    'highlights_en' => $linesJson((string) ($_POST['highlights_en'] ?? '')), 'highlights_ne' => $linesJson((string) ($_POST['highlights_ne'] ?? '')),
                    'itinerary_json' => $itin !== [] ? json_encode($itin, JSON_UNESCAPED_UNICODE) : null,
                    'price_from' => $price !== '' ? round((float) $price, 2) : null, 'price_basis' => $s('price_basis', 60) ?: null,
                    'coach_leg' => in_array($_POST['coach_leg'] ?? '', ['toNepal', 'toIndia', 'both', 'none'], true) ? (string) $_POST['coach_leg'] : 'both',
                    'partner_name' => $s('partner_name', 120) ?: null,
                    'min_pax' => (int) ($_POST['min_pax'] ?? 0) > 0 ? min(99, (int) $_POST['min_pax']) : null,
                    'image_credit' => $s('image_credit', 190) ?: null,
                    'is_featured' => !empty($_POST['is_featured']) ? 1 : 0, 'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                ];
                if ($hasAudience) {
                    $row['audience'] = isset(ContentPages::AUDIENCES[(string) ($_POST['audience'] ?? '')]) ? (string) $_POST['audience'] : null;
                }
                if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $row['image'] = ContentPages::saveUpload($_FILES['image'], 'tours', $slug);
                }
                if ($old === null) {
                    $row['created_by'] = (int) $admin['id'];
                    $id = Database::insert('tour_packages', $row);
                    Logger::audit('content.tour.create', 'tour_package', (string) $id, null, array_intersect_key($row, array_flip($TRACK)), 'tour package created');
                    $flash = ['ok', 'Package created (' . $row['status'] . '). / प्याकेज बन्यो।'];
                } else {
                    $diffOld = [];
                    $diffNew = [];
                    foreach ($row as $k => $v) {
                        if ((string) ($old[$k] ?? '') !== (string) ($v ?? '')) {
                            $diffOld[$k] = $old[$k];
                            $diffNew[$k] = $v;
                        }
                    }
                    if ($diffNew !== []) {
                        Database::update('tour_packages', $row, 'id = :id', ['id' => $id]);
                        Logger::audit('content.tour.save', 'tour_package', (string) $id, $diffOld, $diffNew, 'tour package edited by admin #' . (int) $admin['id']);
                        $flash = ['ok', count($diffNew) . ' field(s) saved. / सेभ भयो।'];
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

$editId = (int) ($_GET['edit'] ?? 0);
$isNew  = ($_GET['new'] ?? '') === '1';
$cur    = $tableOk && $editId > 0 ? Database::fetch('SELECT * FROM tour_packages WHERE id = :id', ['id' => $editId]) : null;
$list   = $tableOk ? Database::fetchAll("SELECT p.*, (SELECT COUNT(*) FROM enquiries q WHERE q.package_id = p.id) AS leads FROM tour_packages p ORDER BY FIELD(p.status, 'published', 'draft', 'archived'), p.sort_order, p.title_en") : [];

admin_header('Tour packages', 'tours');
echo ContentPages::adminCss();
?>
<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>
<?php if (!$tableOk): ?>
  <div class="flash bad">The tour_packages table is missing — run <code>database/upgrade-2026-10-09-content.sql</code>.</div>
<?php else: ?>

<p class="muted" style="margin:0 0 14px;font-size:13.5px">
  Packages on <a href="/tours" target="_blank" rel="noopener">/tours</a> (also /hi/tours, /ne/tours). Only <b>published</b> ones are public.
  Leave the price blank to show <b>“Price on request”</b>. Enquiries arrive in <a href="/admin/enquiries.php?source=tour">Enquiries → Tour enquiry</a>.
  <?php if (!$canEdit): ?><br><b>View only</b> — saving needs the Settings permission.<?php endif; ?>
</p>

<?php if ($cur !== null || $isNew):
    $v = static fn(string $k): string => (string) ($cur[$k] ?? ($cur === null && is_scalar($_POST[$k] ?? null) ? $_POST[$k] : ''));
    $lines = static fn(?string $json): string => implode("\n", ContentPages::jsonList($json));
    $itinRows = json_decode((string) ($cur['itinerary_json'] ?? ''), true);
    $itinEn = $itinNe = '';
    if (is_array($itinRows)) {
        $itinEn = implode("\n", array_map(static fn($d): string => is_array($d) ? (string) ($d['en'] ?? '') : (string) $d, $itinRows));
        $itinNe = implode("\n", array_map(static fn($d): string => is_array($d) ? (string) ($d['ne'] ?? '') : '', $itinRows));
    }
    $ro = $canEdit ? '' : ' readonly';
?>
<form method="post" enctype="multipart/form-data" data-no-guard class="ct-form">
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int) ($cur['id'] ?? 0) ?>">
  <div class="ct-head">
    <h2 style="margin:0"><?= $cur !== null ? '✏️ ' . $e($cur['title_en']) : '➕ New package' ?></h2>
    <a class="btn ghost sm" href="/admin/tours.php">← All packages</a>
    <?php if ($cur !== null): ?><a class="btn ghost sm" href="/tours/<?= $e($cur['slug']) ?><?= $cur['status'] !== 'published' ? '?preview=1' : '' ?>" target="_blank" rel="noopener">👁️ View</a><?php endif; ?>
  </div>
  <div class="ct-grid">
    <section class="ct-card">
      <h3>Basics</h3>
      <label class="ct-f">Title (English) *<input name="title_en" required maxlength="160" value="<?= $e($v('title_en')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Title (नेपाली)<input name="title_ne" maxlength="200" value="<?= $e($v('title_ne')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Title (हिंदी) <small>blank = English</small><input name="title_hi" maxlength="200" value="<?= $e($v('title_hi')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Web address <small>/tours/…  (blank = from the title)</small><input name="slug" maxlength="80" pattern="[a-z0-9-]{2,80}" value="<?= $e($v('slug')) ?>"<?= $ro ?>></label>
      <div class="ct-row">
        <label class="ct-f">Status<select name="status"<?= $canEdit ? '' : ' disabled' ?>>
          <?php foreach (['draft' => 'Draft (hidden)', 'published' => 'Published', 'archived' => 'Archived'] as $sv => $sl): ?>
            <option value="<?= $sv ?>"<?= ($cur['status'] ?? 'draft') === $sv ? ' selected' : '' ?>><?= $sl ?></option>
          <?php endforeach; ?></select></label>
        <label class="ct-f">Country<select name="country"<?= $canEdit ? '' : ' disabled' ?>>
          <?php foreach (['Nepal', 'India', 'Both'] as $c): ?><option<?= ($cur['country'] ?? 'Nepal') === $c ? ' selected' : '' ?>><?= $c ?></option><?php endforeach; ?></select></label>
      </div>
      <div class="ct-row">
        <label class="ct-f">Days<input type="number" name="days" min="1" max="60" value="<?= (int) ($cur['days'] ?? 1) ?>"<?= $ro ?>></label>
        <label class="ct-f">Nights<input type="number" name="nights" min="0" max="60" value="<?= (int) ($cur['nights'] ?? 0) ?>"<?= $ro ?>></label>
      </div>
      <div class="ct-row">
        <label class="ct-f">Order<input type="number" name="sort_order" value="<?= (int) ($cur['sort_order'] ?? 0) ?>"<?= $ro ?>></label>
        <label class="ct-f ct-check"><input type="checkbox" name="is_featured" value="1"<?= !empty($cur['is_featured']) ? ' checked' : '' ?><?= $canEdit ? '' : ' disabled' ?>> ⭐ Featured on home</label>
      </div>
      <label class="ct-f">Audience <small>the chip on the website: who the trip suits<?= $hasAudience ? '' : ' — run database/upgrade-2026-10-10-biz.sql first' ?></small><select name="audience"<?= $canEdit && $hasAudience ? '' : ' disabled' ?>>
        <option value="">— no chip —</option>
        <?php foreach (ContentPages::AUDIENCES as $ak => $al): ?><option value="<?= $e($ak) ?>"<?= (string) ($cur['audience'] ?? '') === $ak ? ' selected' : '' ?>><?= $e($al[0] . ' ' . $al[1] . ' · ' . $al[3]) ?></option><?php endforeach; ?>
      </select></label>
    </section>
    <section class="ct-card">
      <h3>Price &amp; bus</h3>
      <label class="ct-f">Price from (₹ per person) <small>blank = “Price on request”</small><input name="price_from" inputmode="decimal" value="<?= $e($cur !== null && $cur['price_from'] !== null ? (string) (float) $cur['price_from'] : '') ?>"<?= $ro ?>></label>
      <label class="ct-f">Price basis <small>e.g. per person, twin sharing</small><input name="price_basis" maxlength="60" value="<?= $e($v('price_basis')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Our bus leg<select name="coach_leg"<?= $canEdit ? '' : ' disabled' ?>>
        <?php foreach (['both' => 'Both ways (Gujarat ⇄ Rupaidiha)', 'toNepal' => 'Going to Nepal only', 'toIndia' => 'Coming back only', 'none' => 'Not with our bus'] as $cv => $cl): ?>
          <option value="<?= $cv ?>"<?= ($cur['coach_leg'] ?? 'both') === $cv ? ' selected' : '' ?>><?= $cl ?></option>
        <?php endforeach; ?></select></label>
      <label class="ct-f">Partner who runs the tour beyond the border<input name="partner_name" maxlength="120" value="<?= $e($v('partner_name')) ?>"<?= $ro ?>></label>
      <label class="ct-f">Minimum people<input type="number" name="min_pax" min="0" max="99" value="<?= $e($v('min_pax')) ?>"<?= $ro ?>></label>
      <p class="muted" style="font-size:12.5px">Our bus ends at Rupaidiha — the website says so on every package that uses it. Tours are never booked or charged online: the office calls back with a quote.</p>
    </section>
    <section class="ct-card ct-wide">
      <h3>Words on the page</h3>
      <div class="ct-row">
        <label class="ct-f">Summary (English)<textarea name="summary_en" maxlength="2000"<?= $ro ?>><?= $e($v('summary_en')) ?></textarea></label>
        <label class="ct-f">Summary (नेपाली)<textarea name="summary_ne" maxlength="2000"<?= $ro ?>><?= $e($v('summary_ne')) ?></textarea></label>
      </div>
      <label class="ct-f">Summary (हिंदी) <small>blank = English</small><textarea name="summary_hi" maxlength="2000"<?= $ro ?>><?= $e($v('summary_hi')) ?></textarea></label>
      <div class="ct-row">
        <label class="ct-f">Route (English) <small>Surat → Rupaidiha → Nepalgunj → …</small><input name="route_en" maxlength="255" value="<?= $e($v('route_en')) ?>"<?= $ro ?>></label>
        <label class="ct-f">Route (नेपाली)<input name="route_ne" maxlength="255" value="<?= $e($v('route_ne')) ?>"<?= $ro ?>></label>
      </div>
      <div class="ct-row">
        <label class="ct-f">Highlights (English) <small>one per line</small><textarea name="highlights_en" rows="5"<?= $ro ?>><?= $e($lines($cur['highlights_en'] ?? null)) ?></textarea></label>
        <label class="ct-f">Highlights (नेपाली) <small>one per line</small><textarea name="highlights_ne" rows="5"<?= $ro ?>><?= $e($lines($cur['highlights_ne'] ?? null)) ?></textarea></label>
      </div>
      <div class="ct-row">
        <label class="ct-f">Day by day (English) <small>line 1 = day 1</small><textarea name="itin_en" rows="6"<?= $ro ?>><?= $e($itinEn) ?></textarea></label>
        <label class="ct-f">Day by day (नेपाली) <small>line 1 = दिन १</small><textarea name="itin_ne" rows="6"<?= $ro ?>><?= $e($itinNe) ?></textarea></label>
      </div>
    </section>
    <section class="ct-card">
      <h3>Photo</h3>
      <?php if ($cur !== null && !empty($cur['image'])): ?>
        <img class="ct-img" src="/<?= $e($cur['image']) ?>" alt="">
      <?php else: ?><p class="muted" style="font-size:13px">No photo — the website shows a coloured tile. Use only your own or properly licensed photos.</p><?php endif; ?>
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
    <h2 style="margin:0"><?= count($list) ?> package<?= count($list) === 1 ? '' : 's' ?></h2>
    <?php if ($canEdit): ?><a class="btn ok" href="/admin/tours.php?new=1">➕ New package</a><?php endif; ?>
  </div>
  <?php if ($list === []): ?>
    <div class="ct-card" style="text-align:center;padding:26px">No packages yet. <?= $canEdit ? 'Press “New package”, or load the research file with <code>tools/content-seed.php</code>.' : '' ?></div>
  <?php endif; ?>
  <div class="ct-list">
  <?php foreach ($list as $p): ?>
    <article class="ct-item">
      <div class="ct-thumb"><?= !empty($p['image']) ? '<img src="/' . $e($p['image']) . '" alt="">' : '🧳' ?></div>
      <div class="ct-body">
        <b><?= $e($p['title_en']) ?></b> <?php if ((int) $p['is_featured'] === 1): ?>⭐<?php endif; ?>
        <div class="muted" style="font-size:12.5px"><?= $e($p['title_ne'] ?? '') ?></div>
        <div class="ct-meta">
          <span class="pill st-<?= $e($p['status']) ?>"><?= $e(ucfirst((string) $p['status'])) ?></span>
          <span><?= (int) $p['days'] ?>d/<?= (int) $p['nights'] ?>n · <?= $e($p['country']) ?></span>
          <?php $ak = ContentPages::audienceKey($p); if ($ak !== ''): ?><span class="pill st-on"><?= $e(ContentPages::AUDIENCES[$ak][0] . ' ' . ContentPages::AUDIENCES[$ak][1]) ?></span><?php endif; ?>
          <span><?= $p['price_from'] !== null ? '₹' . number_format((float) $p['price_from']) : 'Price on request' ?></span>
          <span><?= (int) $p['leads'] ?> enquir<?= (int) $p['leads'] === 1 ? 'y' : 'ies' ?></span>
        </div>
      </div>
      <div class="ct-actions">
        <a class="btn sm" href="/admin/tours.php?edit=<?= (int) $p['id'] ?>"><?= $canEdit ? 'Edit' : 'Open' ?></a>
        <a class="btn ghost sm" href="/tours/<?= $e($p['slug']) ?><?= $p['status'] !== 'published' ? '?preview=1' : '' ?>" target="_blank" rel="noopener">View</a>
        <?php if ($canEdit): foreach (['published' => 'Publish', 'draft' => 'Unpublish', 'archived' => 'Archive'] as $sv => $sl): if ($p['status'] === $sv) { continue; } ?>
          <form method="post" data-no-guard><?= Security::csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="status" value="<?= $sv ?>"><button class="btn ghost sm" type="submit"><?= $sl ?></button></form>
        <?php endforeach; endif; ?>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php endif; ?>
<?php admin_footer();
