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

/* 4 Oct 2026: payments.verify is held by 'counter' and 'official' too, so a
   ticket window could create a company-wide discount. coupons.edit is the
   manager's own permission. */
$canManage = Auth::isSuperadmin() || Auth::can('coupons.edit');
$flash     = null;

/* 16 Nov 2026 (P4-5b): the rules Fare::couponDiscount() already enforces -
   one route, a per-mobile limit, first booking only - are set here too. A
   database that has not run upgrade-2026-11-15-coupon-first.sql has no
   first_booking_only column: the box is simply not offered. */
$hasFirstCol = Database::fetch("SHOW COLUMNS FROM coupons LIKE 'first_booking_only'") !== null;
$routeRows   = Database::fetchAll('SELECT id, route_code, from_city, to_city, is_active FROM routes ORDER BY is_active DESC, sort_order, id');
$routeName   = [];
foreach ($routeRows as $rr) {
    $routeName[(int) $rr['id']] = (string) $rr['from_city'] . ' → ' . (string) $rr['to_city'] . ' (' . (string) $rr['route_code'] . ')';
}
/** One POST field as a trimmed string ('' for a list or nothing). */
$postStr = static fn(string $k): string => is_scalar($_POST[$k] ?? null) ? trim((string) $_POST[$k]) : '';

/* ---------------- discount usage report (P4-5g, read-only) ----------------
   Bookings PLACED in the range (created_at) that are not cancelled /
   rejected / expired and carry any discount: the offer or desk cut
   (coupon_discount - a desk discount has no code), the group discount and
   the member-tier discount. Grouped by source, route or day. Nothing here
   writes; the CSV goes through admin/_export.php. */
$getStr  = static fn(string $k): string => is_scalar($_GET[$k] ?? null) ? trim((string) $_GET[$k]) : '';
$repTo   = Security::isValidDate($getStr('rt')) ? $getStr('rt') : todayISO();
$repFrom = Security::isValidDate($getStr('rf')) ? $getStr('rf') : substr($repTo, 0, 8) . '01';
if ($repFrom > $repTo) {
    [$repFrom, $repTo] = [$repTo, $repFrom];
}
if ((int) ((strtotime($repTo) - strtotime($repFrom)) / 86400) > 365) {
    $repFrom = addDaysISO($repTo, -365);     // capped at 366 days
}
$repBy = in_array($getStr('rby'), ['code', 'route', 'date'], true) ? $getStr('rby') : 'code';

/** @return list<array{key:string, label:string, n:int, amt:float}> */
$discountReport = static function (string $from, string $to, string $by) use ($routeName): array {
    $live = "status NOT IN ('cancelled', 'rejected', 'expired') AND created_at >= :f%d AND created_at < :t%d";
    $x0   = $from . ' 00:00:00';
    $x1   = addDaysISO($to, 1) . ' 00:00:00';
    $out  = [];
    if ($by === 'code') {
        $rows = Database::fetchAll(
            'SELECT src, COUNT(DISTINCT bid) AS n, SUM(amt) AS amt FROM (
                SELECT id AS bid, CASE WHEN COALESCE(coupon_code, \'\') <> \'\' THEN UPPER(coupon_code) ELSE \'#DESK\' END AS src, coupon_discount AS amt
                  FROM bookings WHERE coupon_discount > 0 AND ' . sprintf($live, 1, 1) . '
                UNION ALL
                SELECT id, \'#GROUP\', group_discount FROM bookings WHERE group_discount > 0 AND ' . sprintf($live, 2, 2) . '
                UNION ALL
                SELECT id, CONCAT(\'#TIER:\', COALESCE(tier_name, \'\')), tier_discount FROM bookings WHERE tier_discount > 0 AND ' . sprintf($live, 3, 3) . '
             ) x GROUP BY src ORDER BY amt DESC',
            ['f1' => $x0, 't1' => $x1, 'f2' => $x0, 't2' => $x1, 'f3' => $x0, 't3' => $x1]
        );
        $titles = [];
        foreach (Database::fetchAll('SELECT code, title FROM coupons') as $c) {
            $titles[strtoupper((string) $c['code'])] = trim((string) ($c['title'] ?? ''));
        }
        foreach ($rows as $r) {
            $k = (string) $r['src'];
            $label = match (true) {
                $k === '#DESK'              => 'काउन्टर छुट · Desk discount',
                $k === '#GROUP'             => 'समूह छुट · GROUP',
                str_starts_with($k, '#TIER:') => 'सदस्य छुट · ' . (substr($k, 6) !== '' ? substr($k, 6) . ' member' : 'Member'),
                default                     => $k . (($titles[$k] ?? '') !== '' && $titles[$k] !== $k ? ' — ' . $titles[$k] : ''),
            };
            $out[] = ['key' => $k, 'label' => $label, 'n' => (int) $r['n'], 'amt' => round((float) $r['amt'], 2)];
        }
        return $out;
    }
    $keySql = $by === 'route'
        ? '(SELECT s.route_id FROM booking_legs bl JOIN schedules s ON s.id = bl.schedule_id WHERE bl.booking_id = bookings.id ORDER BY bl.id LIMIT 1)'
        : 'DATE(created_at)';
    $rows = Database::fetchAll(
        'SELECT ' . $keySql . ' AS k, COUNT(*) AS n, SUM(coupon_discount + group_discount + tier_discount) AS amt
           FROM bookings
          WHERE (coupon_discount + group_discount + tier_discount) > 0 AND ' . sprintf($live, 1, 1) . '
          GROUP BY k ORDER BY ' . ($by === 'date' ? 'k ASC' : 'amt DESC'),
        ['f1' => $x0, 't1' => $x1]
    );
    foreach ($rows as $r) {
        $k = (string) ($r['k'] ?? '');
        $label = $by === 'route'
            ? ($k !== '' ? ($routeName[(int) $k] ?? 'Route #' . $k) : 'रुट छैन · No route')
            : ($k !== '' ? formatDate($k, 'D, j M Y') : '-');
        $out[] = ['key' => $k, 'label' => $label, 'n' => (int) $r['n'], 'amt' => round((float) $r['amt'], 2)];
    }
    return $out;
};

if ($getStr('rcsv') === '1') {
    require_once __DIR__ . '/_export.php';
    $csvRows = [];
    foreach ($discountReport($repFrom, $repTo, $repBy) as $r) {
        $csvRows[] = [$r['label'], $r['n'], number_format($r['amt'], 2, '.', '')];
    }
    $first = ['code' => 'Discount source', 'route' => 'Route', 'date' => 'Booking date'][$repBy];
    Logger::audit('offer.report_export', 'coupon', '', null, ['from' => $repFrom, 'to' => $repTo, 'by' => $repBy, 'rows' => count($csvRows)], 'Discount usage CSV downloaded');
    shg_export_csv('discount-usage-' . $repBy . '-' . $repFrom . '-to-' . $repTo . '.csv', [$first, 'Bookings', 'Discount (INR)'], $csvRows);
}

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
                /* 16 Nov 2026 (P4-5c): a used offer is never deleted. Its
                   coupon_redemptions rows cascade away with it (FK ON DELETE
                   CASCADE), which erases the usage history and resets the
                   per-mobile limit should the code be made again. */
                $row  = Database::fetch('SELECT * FROM coupons WHERE id = :i', ['i' => $id]);
                $used = $row !== null ? max((int) $row['used_count'], (int) Database::scalar('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :i', ['i' => $id], 0)) : 0;
                if ($row === null) {
                    $flash = ['bad', 'That offer no longer exists. · यो अफर भेटिएन।'];
                } elseif ($used > 0) {
                    $flash = ['bad', 'Offer ' . $row['code'] . ' has been used ' . $used . ' time(s), so it cannot be deleted (its usage history would be lost). Switch it OFF instead. · प्रयोग भइसकेको अफर मेटाउन मिल्दैन — OFF गर्नुहोस्।'];
                } else {
                    Database::delete('coupons', 'id = :i', ['i' => $id]);
                    Logger::audit('offer.delete', 'coupon', (string) $row['code'], $row, null, 'deleted');
                    $flash = ['ok', 'Offer deleted.'];
                }
            } elseif ($act === 'save') {
                $hasAhead = Database::fetch("SHOW COLUMNS FROM coupons LIKE 'min_hours_ahead'") !== null;
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
                $ahead = max(0, min(720, (int) ($_POST['min_hours_ahead'] ?? 0)));
                /* 16 Nov 2026 (P4-5b / P4-5c) */
                $routeIn = (int) $postStr('route_id');
                $perUser = max(0, min(999, (int) ($postStr('per_user_limit') !== '' ? $postStr('per_user_limit') : '1')));
                $maxUse  = $postStr('usage_limit') !== '' ? (int) $postStr('usage_limit') : null;
                $firstOn = $postStr('first_booking_only') !== '' && $postStr('first_booking_only') !== '0';
                $oldRow  = $id > 0 ? Database::fetch('SELECT * FROM coupons WHERE id = :i', ['i' => $id]) : null;

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
                } elseif ($id > 0 && $oldRow === null) {
                    $flash = ['bad', 'That offer no longer exists. · यो अफर भेटिएन।'];
                } elseif ($routeIn > 0 && !isset($routeName[$routeIn])) {
                    $flash = ['bad', 'That route was not found. · रुट भेटिएन।'];
                } elseif ($maxUse !== null && ($maxUse < 1 || $maxUse > 1000000)) {
                    $flash = ['bad', 'Max uses must be 1 or more — leave it blank for no limit. · बढीमा प्रयोग १ वा बढी, खाली = सीमा छैन।'];
                } elseif ($maxUse !== null && $oldRow !== null && $maxUse < (int) $oldRow['used_count']) {
                    $flash = ['bad', 'This offer is already used ' . (int) $oldRow['used_count'] . ' time(s) — max uses cannot be lower. To stop it, switch it OFF. · पहिले नै प्रयोग भएभन्दा कम राख्न मिल्दैन — रोक्न OFF गर्नुहोस्।'];
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
                        'route_id'       => $routeIn > 0 ? $routeIn : null,
                        'usage_limit'    => $maxUse,
                        'per_user_limit' => $perUser,
                    ];
                    if ($hasAhead) {
                        $data['min_hours_ahead'] = $ahead > 0 ? $ahead : null;
                    }
                    if ($hasFirstCol) {
                        $data['first_booking_only'] = $firstOn ? 1 : null;
                    }
                    if ($id > 0) {
                        $code = (string) $oldRow['code'];   // the code is fixed once made
                        Database::update('coupons', $data, 'id = :i', ['i' => $id]);
                        Logger::audit('offer.update', 'coupon', $code, array_intersect_key($oldRow, $data), $data, 'Offer edited on admin/offers.php');
                        $flash = ['ok', 'Offer ' . $code . ' saved.'];
                    } elseif (Database::exists('SELECT 1 FROM coupons WHERE code = :c', ['c' => $code])) {
                        $flash = ['bad', 'An offer with that code already exists.'];
                    } else {
                        Database::insert('coupons', ['code' => $code] + $data);
                        Logger::audit('offer.create', 'coupon', $code, null, ['code' => $code] + $data, 'Offer created on admin/offers.php');
                        $flash = ['ok', 'Offer ' . $code . ' created.'];
                    }
                }
            } elseif ($act === 'discount_switches') {
            /* ── Automatic discount switches (2 Oct 2026) ── */
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
  /* 16 Nov 2026: the admin's own tokens (var(--card) / --line / --mut / --ink) so the cards read in dark mode too */
  .of-card{background:var(--card,#fff);border:1px solid var(--line,#e5e9f0);border-radius:12px;padding:14px}
  .of-card .hv{font-size:22px;font-weight:700}
  .of-form{background:var(--card,#fff);color:var(--ink,#1b2436);border:1px solid var(--line,#e5e9f0);border-radius:12px;padding:16px;margin-bottom:20px}
  .of-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
  .of-form label{display:block;font-size:12px;color:var(--mut,#66708a);margin-bottom:4px}
  .of-form input,.of-form select{width:100%;padding:8px 10px;border:1px solid var(--line,#e5e9f0);border-radius:8px;font-size:14px;background:var(--card,#fff);color:var(--ink,#1b2436)}
  .of-hint{font-size:12px;color:var(--mut,#66708a);margin-top:8px;line-height:1.5}
  .pill-auto{background:#e7f6ec;color:#0a7c2f;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:600}
  .pill-code{background:#eef1f7;color:#41506b;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:600}
  .pill-off{background:#f3f4f6;color:#6b7280;border-radius:999px;padding:2px 8px;font-size:11px}
  .sw-panel{background:var(--card,#fff);color:var(--ink,#1b2436);border:1px solid var(--line,#e5e9f0);border-radius:12px;padding:16px;margin-bottom:20px}
  .sw-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--line,#f3f4f6)}
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
    <div>
      <label>Only when booked this many hours before departure (0 = any time)</label>
      <input name="min_hours_ahead" type="number" min="0" max="720" value="<?= (int) ($editing['min_hours_ahead'] ?? 0) ?>" placeholder="24">
    </div>
    <?php $edRoute = (int) ($editing['route_id'] ?? 0); ?>
    <div>
      <label for="of_route">रुट · Route</label>
      <select name="route_id" id="of_route">
        <option value="0">सबै रुट · All routes</option>
        <?php foreach ($routeRows as $rr): if ((int) $rr['is_active'] !== 1 && (int) $rr['id'] !== $edRoute) { continue; } ?>
          <option value="<?= (int) $rr['id'] ?>" <?= (int) $rr['id'] === $edRoute ? 'selected' : '' ?>><?= Security::e($routeName[(int) $rr['id']]) ?><?= (int) $rr['is_active'] !== 1 ? ' — बन्द · off' : '' ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label for="of_peruser">एक मोबाइलले कति पटक · Per mobile (0 = no limit)</label>
      <input name="per_user_limit" id="of_peruser" type="number" min="0" max="999" value="<?= (int) ($editing['per_user_limit'] ?? 1) ?>" placeholder="1">
    </div>
    <div>
      <label for="of_maxuse">बढीमा प्रयोग · Max uses (blank = no limit)<?= $editing ? ' — used ' . (int) $editing['used_count'] : '' ?></label>
      <input name="usage_limit" id="of_maxuse" type="number" min="<?= $editing ? max(1, (int) $editing['used_count']) : 1 ?>" max="1000000" value="<?= isset($editing['usage_limit']) && $editing['usage_limit'] !== null ? (int) $editing['usage_limit'] : '' ?>" placeholder="100">
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
    <?php if ($hasFirstCol): ?>
    <label style="display:flex;gap:8px;align-items:center;margin:0">
      <input type="checkbox" name="first_booking_only" value="1" style="width:auto" <?= !empty($editing['first_booking_only']) ? 'checked' : '' ?>>
      पहिलो बुकिङ मात्र · First booking only (that mobile has no confirmed ticket yet)
    </label>
    <?php endif; ?>
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
        <?php if ((int) ($o['min_hours_ahead'] ?? 0) > 0): ?>
          <div class="muted" style="font-size:11px">booked <?= (int) $o['min_hours_ahead'] ?> h+ before departure</div>
        <?php endif; ?>
        <?php if ($o['route_id'] !== null): ?>
          <div class="muted" style="font-size:11px">रुट · <?= Security::e($routeName[(int) $o['route_id']] ?? ('#' . (int) $o['route_id'])) ?></div>
        <?php endif; ?>
        <div class="muted" style="font-size:11px"><?= (int) $o['per_user_limit'] > 0 ? (int) $o['per_user_limit'] . '× per mobile' : 'no per-mobile limit' ?></div>
        <?php if (!empty($o['first_booking_only'])): ?>
          <div class="muted" style="font-size:11px">पहिलो बुकिङ मात्र · first booking only</div>
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
          <?php if ((int) $o['used_count'] > 0): /* P4-5c: a used offer keeps its history - OFF only */ ?>
            <span class="muted" style="font-size:11px" title="A used offer cannot be deleted - switch it OFF">प्रयोग भएको · used — OFF only</span>
          <?php else: ?>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this offer? Bookings already made keep their discount.')">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
            <button class="btn bad" type="submit">Delete</button>
          </form>
          <?php endif; ?>
        <?php else: ?><span class="muted">view only</span><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>

<?php
/* 16 Nov 2026 (P4-5g): read-only discount usage report + CSV. */
$repRows = $discountReport($repFrom, $repTo, $repBy);
$repN    = 0;
$repAmt  = 0.0;
foreach ($repRows as $r) {
    $repN   += $r['n'];
    $repAmt += $r['amt'];
}
$repQs = static fn(array $extra = []): string => 'offers.php?' . http_build_query(['rf' => $repFrom, 'rt' => $repTo, 'rby' => $repBy] + $extra) . '#discount-report';
?>
<div class="of-form" id="discount-report" style="margin-top:20px">
  <h3 style="margin:0 0 6px">छुट प्रयोग रिपोर्ट · Discount usage report</h3>
  <p class="muted" style="margin:0 0 10px;font-size:13px">
    Bookings made in the dates below (cancelled, rejected and expired ones left out) that carry any discount:
    an offer code, a desk discount (काउन्टर छुट), the group discount or a member-tier discount. Read only.
  </p>
  <form method="get" action="offers.php#discount-report" class="of-row" style="align-items:end">
    <div><label for="rep_from">देखि · From</label><input type="date" id="rep_from" name="rf" value="<?= Security::e($repFrom) ?>" required></div>
    <div><label for="rep_to">सम्म · To</label><input type="date" id="rep_to" name="rt" value="<?= Security::e($repTo) ?>" required></div>
    <div>
      <label for="rep_by">समूह · Group by</label>
      <select id="rep_by" name="rby">
        <?php foreach (['code' => 'कोड / स्रोत · Code / source', 'route' => 'रुट · Route', 'date' => 'मिति · Date'] as $bk => $bl): ?>
          <option value="<?= $bk ?>" <?= $repBy === $bk ? 'selected' : '' ?>><?= Security::e($bl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn" type="submit">हेर्नुहोस् · Show</button>
      <a class="btn ghost" href="<?= Security::e($repQs(['rcsv' => '1'])) ?>">⬇ CSV</a>
    </div>
  </form>
  <div style="overflow-x:auto;margin-top:12px">
    <table class="dt">
      <thead><tr>
        <th><?= Security::e(['code' => 'स्रोत · Source', 'route' => 'रुट · Route', 'date' => 'बुक मिति · Booking date'][$repBy]) ?></th>
        <th style="text-align:right">बुकिङ · Bookings</th>
        <th style="text-align:right">छुट · Discount</th>
      </tr></thead>
      <tbody>
      <?php if ($repRows === []): ?>
        <tr><td colspan="3" class="muted" style="padding:18px;text-align:center">No discounted bookings in these dates. · यी मितिमा छुट भएको बुकिङ छैन।</td></tr>
      <?php else: foreach ($repRows as $r): ?>
        <tr>
          <td><?= Security::e($r['label']) ?></td>
          <td style="text-align:right"><?= (int) $r['n'] ?></td>
          <td style="text-align:right"><?= Security::e(inr($r['amt'])) ?></td>
        </tr>
      <?php endforeach; ?>
        <tr>
          <td><strong>जम्मा · Total</strong><?= $repBy === 'code' ? ' <span class="muted" style="font-size:11px">(a booking with two kinds of discount counts under each)</span>' : '' ?></td>
          <td style="text-align:right"><strong><?= (int) $repN ?></strong></td>
          <td style="text-align:right"><strong><?= Security::e(inr($repAmt)) ?></strong></td>
        </tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php admin_footer(); ?>
