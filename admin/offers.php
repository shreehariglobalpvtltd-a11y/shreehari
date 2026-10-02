<?php
/**
 * admin/offers.php — festival and standing offers (20 Sep 2026).
 *
 * An offer here is a row in `coupons`. Two kinds live in that table:
 *   - a CODE the passenger types (auto_apply = 0), and
 *   - a running OFFER that needs no code (auto_apply = 1) — Dashain, Tihar,
 *     a weekend push. Fare::autoOffer() picks the biggest qualifying one at
 *     quote time and puts it through the same validation a typed code gets,
 *     so dates, minimum amount, per-route and usage limits behave the same
 *     either way.
 *
 * The discount is real money off the fare, so the things that could cost the
 * company are bounded: a percent offer must carry a ceiling, every offer can
 * take a minimum booking amount, and switching one off is one click.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('payments.view');

$canManage = Auth::isSuperadmin() || Auth::can('payments.verify');
$flash     = null;

/* ---------------- actions ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canManage) {
        $flash = ['bad', 'Only a manager or super-admin can change offers.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        $id  = (int) ($_POST['id'] ?? 0);

        try {
            if ($act === 'toggle' && $id > 0) {
                $row = Database::fetch('SELECT is_active, code FROM coupons WHERE id = :i', ['i' => $id]);
                if ($row !== null) {
                    $now = (int) $row['is_active'] === 1 ? 0 : 1;
                    Database::update('coupons', ['is_active' => $now], 'id = :i', ['i' => $id]);
                    Logger::audit('offer.toggle', 'coupon', (string) $row['code'], null, null, $now ? 'enabled' : 'disabled');
                    $flash = ['ok', 'Offer ' . $row['code'] . ' is now ' . ($now ? 'ON' : 'OFF') . '.'];
                }
            } elseif ($act === 'delete' && $id > 0) {
                $row = Database::fetch('SELECT code FROM coupons WHERE id = :i', ['i' => $id]);
                Database::delete('coupons', 'id = :i', ['i' => $id]);
                Logger::audit('offer.delete', 'coupon', (string) ($row['code'] ?? $id), null, null, 'deleted');
                $flash = ['ok', 'Offer deleted.'];
            } elseif ($act === 'save') {
                $code  = strtoupper(Security::clean($_POST['code'] ?? '', 40));
                $title = Security::clean($_POST['title'] ?? '', 120);
                $type  = ($_POST['discount_type'] ?? 'flat') === 'percent' ? 'percent' : 'flat';
                $value = round((float) ($_POST['discount_value'] ?? 0), 2);
                $maxD  = trim((string) ($_POST['max_discount'] ?? '')) !== '' ? round((float) $_POST['max_discount'], 2) : null;
                $minA  = round((float) ($_POST['min_amount'] ?? 0), 2);
                $from  = trim((string) ($_POST['valid_from'] ?? ''))  !== '' ? (string) $_POST['valid_from']  : null;
                $until = trim((string) ($_POST['valid_until'] ?? '')) !== '' ? (string) $_POST['valid_until'] : null;
                $auto  = !empty($_POST['auto_apply']) ? 1 : 0;
                $live  = !empty($_POST['is_active']) ? 1 : 0;

                if ($code === '' || !preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
                    $flash = ['bad', 'Give the offer a short code — letters, numbers, - or _ (3 to 40).'];
                } elseif ($value <= 0) {
                    $flash = ['bad', 'The discount must be more than zero.'];
                } elseif ($type === 'percent' && $value > 100) {
                    $flash = ['bad', 'A percent discount cannot be over 100%.'];
                } elseif ($type === 'percent' && $maxD === null) {
                    /* An unbounded percent on a group booking is how a
                       festival offer quietly gives away a whole ticket. */
                    $flash = ['bad', 'A percent offer needs a maximum discount, so one big booking cannot run away with it.'];
                } elseif ($from !== null && $until !== null && $until < $from) {
                    $flash = ['bad', 'The end date is before the start date.'];
                } else {
                    $data = [
                        'title'          => $title !== '' ? $title : $code,
                        'discount_type'  => $type,
                        'discount_value' => $value,
                        'max_discount'   => $maxD,
                        'min_amount'     => $minA,
                        'valid_from'     => $from,
                        'valid_until'    => $until,
                        'auto_apply'     => $auto,
                        'is_active'      => $live,
                    ];
                    if ($id > 0) {
                        Database::update('coupons', $data, 'id = :i', ['i' => $id]);
                        Logger::audit('offer.update', 'coupon', $code, null, null, (string) json_encode($data));
                        $flash = ['ok', 'Offer ' . $code . ' saved.'];
                    } elseif (Database::exists('SELECT 1 FROM coupons WHERE code = :c', ['c' => $code])) {
                        $flash = ['bad', 'An offer with that code already exists.'];
                    } else {
                        Database::insert('coupons', ['code' => $code] + $data);
                        Logger::audit('offer.create', 'coupon', $code, null, null, (string) json_encode($data));
                        $flash = ['ok', 'Offer ' . $code . ' created.'];
                    }
                }
            }

            /* ── Automatic discount switches (2 Oct 2026) ── */
            } elseif ($act === 'discount_switches') {
                $switches = ['group_discount_on', 'loyalty_tier_discount_on', 'sharing_short_run_on'];
                foreach ($switches as $sw) {
                    $val = !empty($_POST[$sw]) ? '1' : '0';
                    Settings::set($sw, $val, 'bool', 'booking', true);
                }
                Settings::flush();
                Logger::audit('offer.discount_switches', 'settings', '', null, null, json_encode(array_map(fn($s) => !empty($_POST[$s]) ? 'on' : 'off', array_combine($switches, $switches))));
                $flash = ['ok', 'Discount switches saved.'];
            }
        } catch (Throwable $e) {
            Logger::exception($e);
            $flash = ['bad', 'That did not save — please try again.'];
        }
    }
}

$offers  = Database::fetchAll('SELECT * FROM coupons ORDER BY is_active DESC, auto_apply DESC, id DESC');
$editing = null;
$editId  = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editing = Database::fetch('SELECT * FROM coupons WHERE id = :i', ['i' => $editId]);
}

$usedToday  = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE coupon_discount > 0 AND DATE(created_at) = CURDATE()', [], 0);
$givenToday = (float) Database::scalar('SELECT COALESCE(SUM(coupon_discount),0) FROM bookings WHERE DATE(created_at) = CURDATE()', [], 0.0);

$liveCount = 0;
$autoCount = 0;
foreach ($offers as $o) {
    if ((int) $o['is_active'] === 1) {
        $liveCount++;
        if ((int) $o['auto_apply'] === 1) {
            $autoCount++;
        }
    }
}

admin_header('Offers & Discounts', 'offers');
?>
<style>
  .of-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
  .of-card{background:#fff;border:1px solid #e5e9f0;border-radius:12px;padding:14px}
  .of-card .hv{font-size:22px;font-weight:700}
  .of-form{background:#fff;border:1px solid #e5e9f0;border-radius:12px;padding:16px;margin-bottom:20px}
  .of-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
  .of-form label{display:block;font-size:12px;color:#66708a;margin-bottom:4px}
  .of-form input,.of-form select{width:100%;padding:8px 10px;border:1px solid #e5e9f0;border-radius:8px;font-size:14px}
  .of-hint{font-size:12px;color:#66708a;margin-top:8px;line-height:1.5}
  .pill-auto{background:#e7f6ec;color:#0a7c2f;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:600}
  .pill-code{background:#eef1f7;color:#41506b;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:600}
  .pill-off{background:#f3f4f6;color:#6b7280;border-radius:999px;padding:2px 8px;font-size:11px}
  .sw-panel{background:#fff;border:1px solid #e5e9f0;border-radius:12px;padding:16px;margin-bottom:20px}
  .sw-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f3f4f6}
  .sw-row:last-child{border-bottom:none}
  .sw-row label{margin:0;flex:1;font-size:14px;font-weight:500;cursor:pointer}
  .sw-row small{color:#66708a;font-size:12px;display:block;font-weight:400;margin-top:2px}
  .sw-toggle{position:relative;width:44px;height:24px;flex-shrink:0}
  .sw-toggle input{opacity:0;position:absolute;width:0;height:0}
  .sw-toggle .slider{position:absolute;inset:0;background:#d1d5db;border-radius:24px;transition:.2s;cursor:pointer}
  .sw-toggle .slider::before{content:'';position:absolute;width:18px;height:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
  .sw-toggle input:checked+.slider{background:#0a7c2f}
  .sw-toggle input:checked+.slider::before{transform:translateX(20px)}
</style>

<?php if ($flash !== null): ?>
  <div class="flash <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<div class="of-grid">
  <div class="of-card"><div class="hv"><?= $liveCount ?></div><div class="muted">Offers running</div></div>
  <div class="of-card"><div class="hv"><?= $autoCount ?></div><div class="muted">Automatic (no code)</div></div>
  <div class="of-card"><div class="hv"><?= $usedToday ?></div><div class="muted">Discounted bookings today</div></div>
  <div class="of-card"><div class="hv"><?= Security::e(inr($givenToday)) ?></div><div class="muted">Discount given today</div></div>
</div>

<?php
/* ── Automatic discount switches (2 Oct 2026) ──
   Group, tier and short-run discounts fire ONLY when the office has flipped
   each one ON here. The setting rows may not exist yet (first visit after
   the upgrade) — getBool defaults to false, which is the safe state. */
$swGroup   = Settings::getBool('group_discount_on', false);
$swTier    = Settings::getBool('loyalty_tier_discount_on', false);
$swShort   = Settings::getBool('sharing_short_run_on', false);

$gdPct     = Settings::getFloat('group_discount_percent', 5.0);
$gdMin     = Settings::getInt('group_discount_min_seats', 5);
$srCut     = Settings::getFloat('sharing_short_run_discount_inr', 200.0);
?>
<?php if ($canManage): ?>
<form method="post" class="sw-panel">
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="discount_switches">
  <h3 style="margin:0 0 6px">Automatic discount rules</h3>
  <p class="muted" style="margin:0 0 10px;font-size:13px">
    These run on every booking — no code needed. Each one fires only when its switch is ON.<br>
    The numeric thresholds (seats, percent, amount) are managed in <a href="settings.php">Settings → Booking</a>.
  </p>

  <div class="sw-row">
    <div class="sw-toggle">
      <input type="checkbox" id="sw_group" name="group_discount_on" value="1" <?= $swGroup ? 'checked' : '' ?>>
      <span class="slider" onclick="this.previousElementSibling.click()"></span>
    </div>
    <label for="sw_group">
      Group discount
      <small><?= (int) $gdMin ?>+ seats → <?= rtrim(rtrim(number_format($gdPct, 2), '0'), '.') ?>% off the base fare</small>
    </label>
  </div>

  <div class="sw-row">
    <div class="sw-toggle">
      <input type="checkbox" id="sw_tier" name="loyalty_tier_discount_on" value="1" <?= $swTier ? 'checked' : '' ?>>
      <span class="slider" onclick="this.previousElementSibling.click()"></span>
    </div>
    <label for="sw_tier">
      Loyalty tier discount
      <small>Gold / Platinum / Diamond members get 2–5% off</small>
    </label>
  </div>

  <div class="sw-row">
    <div class="sw-toggle">
      <input type="checkbox" id="sw_short" name="sharing_short_run_on" value="1" <?= $swShort ? 'checked' : '' ?>>
      <span class="slider" onclick="this.previousElementSibling.click()"></span>
    </div>
    <label for="sw_short">
      Short-run sharing cut
      <small>Sharing cabin — ₹<?= number_format($srCut, 0) ?> off when fewer than <?= Settings::getInt('sharing_short_run_min_stops', 3) ?> stops</small>
    </label>
  </div>

  <button class="btn" type="submit" style="margin-top:12px">Save switches</button>
</form>
<?php endif; ?>

<?php if ($canManage): ?>
<form method="post" class="of-form">
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="save">
  <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
  <h3 style="margin:0 0 12px"><?= $editing ? 'Edit offer' : 'New offer' ?></h3>

  <div class="of-row">
    <div>
      <label>Code</label>
      <input name="code" value="<?= Security::e((string) ($editing['code'] ?? '')) ?>" placeholder="DASHAIN200" <?= $editing ? 'readonly' : '' ?> required>
    </div>
    <div>
      <label>Name the passenger sees</label>
      <input name="title" value="<?= Security::e((string) ($editing['title'] ?? '')) ?>" placeholder="Dashain-Tihar offer">
    </div>
    <div>
      <label>Type</label>
      <select name="discount_type">
        <option value="flat"    <?= ($editing['discount_type'] ?? 'flat') === 'flat'    ? 'selected' : '' ?>>Fixed amount (Rs)</option>
        <option value="percent" <?= ($editing['discount_type'] ?? '')     === 'percent' ? 'selected' : '' ?>>Percent (%)</option>
      </select>
    </div>
    <div>
      <label>Discount</label>
      <input name="discount_value" type="number" step="0.01" min="0" value="<?= Security::e((string) ($editing['discount_value'] ?? '')) ?>" placeholder="200" required>
    </div>
    <div>
      <label>Maximum discount (Rs) — required for percent</label>
      <input name="max_discount" type="number" step="0.01" min="0" value="<?= Security::e((string) ($editing['max_discount'] ?? '')) ?>" placeholder="500">
    </div>
    <div>
      <label>Only on bookings above (Rs)</label>
      <input name="min_amount" type="number" step="0.01" min="0" value="<?= Security::e((string) ($editing['min_amount'] ?? '0')) ?>" placeholder="0">
    </div>
    <div>
      <label>Starts</label>
      <input name="valid_from" type="date" value="<?= Security::e((string) ($editing['valid_from'] ?? '')) ?>">
    </div>
    <div>
      <label>Ends</label>
      <input name="valid_until" type="date" value="<?= Security::e((string) ($editing['valid_until'] ?? '')) ?>">
    </div>
  </div>

  <div style="margin-top:12px;display:flex;gap:18px;flex-wrap:wrap">
    <label style="display:flex;gap:8px;align-items:center;margin:0">
      <input type="checkbox" name="auto_apply" value="1" style="width:auto" <?= (int) ($editing['auto_apply'] ?? 1) === 1 ? 'checked' : '' ?>>
      Apply automatically (passenger types nothing)
    </label>
    <label style="display:flex;gap:8px;align-items:center;margin:0">
      <input type="checkbox" name="is_active" value="1" style="width:auto" <?= (int) ($editing['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
      Running now
    </label>
  </div>

  <div class="of-hint">
    A fixed offer takes rupees off the fare. A percent offer takes a share of it and must carry a
    maximum, so a large group booking cannot run away with the discount. The amount appears on the
    ticket and in the passenger's fare breakdown. Bookings already made keep whatever they were given.
  </div>

  <div style="margin-top:14px;display:flex;gap:10px">
    <button class="btn" type="submit"><?= $editing ? 'Save offer' : 'Create offer' ?></button>
    <?php if ($editing): ?><a class="btn ghost" href="offers.php">Cancel</a><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<table class="dt">
  <thead><tr>
    <th>Offer</th><th>Discount</th><th>Applies to</th><th>Dates</th><th>Used</th><th>Status</th><th style="min-width:180px">Actions</th>
  </tr></thead>
  <tbody>
  <?php if ($offers === []): ?>
    <tr><td colspan="7" class="muted" style="padding:26px;text-align:center">No offers yet. Create one above.</td></tr>
  <?php else: foreach ($offers as $o):
      $isAuto = (int) $o['auto_apply'] === 1;
      $live   = (int) $o['is_active'] === 1;
  ?>
    <tr>
      <td>
        <strong><?= Security::e((string) ($o['title'] !== '' ? $o['title'] : $o['code'])) ?></strong>
        <div class="mono muted" style="font-size:11px"><?= Security::e((string) $o['code']) ?></div>
      </td>
      <td>
        <?php if ($o['discount_type'] === 'percent'): ?>
          <?= Security::e(rtrim(rtrim(number_format((float) $o['discount_value'], 2), '0'), '.')) ?>%
          <?php if ($o['max_discount'] !== null): ?>
            <div class="muted" style="font-size:11px">max <?= Security::e(inr((float) $o['max_discount'])) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <?= Security::e(inr((float) $o['discount_value'])) ?>
        <?php endif; ?>
      </td>
      <td>
        <?= $isAuto ? '<span class="pill-auto">Automatic</span>' : '<span class="pill-code">Needs code</span>' ?>
        <?php if ((float) $o['min_amount'] > 0): ?>
          <div class="muted" style="font-size:11px">above <?= Security::e(inr((float) $o['min_amount'])) ?></div>
        <?php endif; ?>
      </td>
      <td style="font-size:12px">
        <?= $o['valid_from']  ? Security::e(formatDate((string) $o['valid_from']))  : 'any time' ?>
        &rarr;
        <?= $o['valid_until'] ? Security::e(formatDate((string) $o['valid_until'])) : 'no end' ?>
      </td>
      <td><?= (int) $o['used_count'] ?><?php if ($o['usage_limit'] !== null): ?> / <?= (int) $o['usage_limit'] ?><?php endif; ?></td>
      <td><?= $live ? '<span class="pill-auto">ON</span>' : '<span class="pill-off">OFF</span>' ?></td>
      <td>
        <?php if ($canManage): ?>
          <a class="btn ghost" href="offers.php?edit=<?= (int) $o['id'] ?>">Edit</a>
          <form method="post" style="display:inline">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <button class="btn ghost" type="submit"><?= $live ? 'Turn off' : 'Turn on' ?></button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this offer? Bookings already made keep their discount.')">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <button class="btn bad" type="submit">Delete</button>
          </form>
        <?php else: ?><span class="muted">view only</span><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>

<?php admin_footer(); ?>
