<?php
/**
 * admin/trip-expenses.php — ट्रिप खर्च · Trip expenses (25 Nov 2026, op5).
 *
 * Owner: per-trip costs must be easy to enter and the profit must be exact.
 * Pick the date, tap the trip (route + bus), tap a big button (⛽ डिजेल, 🛣️ टोल,
 * 👨‍✈️ ड्राइभर भत्ता, 🧑‍🔧 स्टाफ भत्ता, 🔧 मर्मत, …), type the amount (₹ or रू),
 * optional litres and a receipt photo. NPR is turned into ₹ at npr_per_inr.
 *
 * Every entry is one trip_expenses row (TripExpense) — the same table
 * admin/pnl.php and admin/accounting.php already total, so their figures
 * include these with no change.
 *
 * Who: superadmin / manager / accountant (any row) and counter (own rows,
 * same day). Ticket income and profit show only with reports.view (the
 * counter role has no revenue view by design). Agents never reach the page.
 * Switch trip_expenses_on (default ON). CSRF on every POST, audited.
 *
 *   ?date=YYYY-MM-DD  ?sid=<schedule id>  ?from=&to= (bus summary)  ?receipt=<id>
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripexpense.php';

$admin    = admin_boot('payments.view');
$e        = static fn($v): string => Security::e((string) $v);
$canEnter = TripExpense::mayEnter($admin);
$canMoney = Auth::can('reports.view');
$isOffice = TripExpense::isOffice($admin);

if (!$canEnter && !$canMoney) {
    http_response_code(403);
    admin_header('ट्रिप खर्च · Trip expenses', 'trip-expenses');
    echo '<div class="panel" style="padding:16px">यो पेज अफिस र काउन्टरका लागि मात्र हो · Only office and counter staff may use this page.</div>';
    admin_footer();
    exit;
}

/* ---- receipt photo, streamed behind the login (never a public link) ---- */
if (isset($_GET['receipt'])) {
    $r = TripExpense::row((int) $_GET['receipt']);
    $f = $r !== null ? TripExpense::receiptFile((string) ($r['receipt_path'] ?? '')) : null;
    if ($f === null) {
        http_response_code(404);
        exit('Receipt not found.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f) ?: 'application/octet-stream';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
        http_response_code(415);
        exit('Unsupported file.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($f));
    header('Content-Disposition: inline; filename="receipt-' . (int) $r['id'] . '.' . pathinfo($f, PATHINFO_EXTENSION) . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=300');
    readfile($f);
    exit;
}

$on    = TripExpense::on();
$date  = Security::isValidDate((string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : todayISO();
$sid   = (int) ($_GET['sid'] ?? 0);
$to    = Security::isValidDate((string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : todayISO();
$from  = Security::isValidDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-01', (int) strtotime($to));
if ($from > $to) { [$from, $to] = [$to, $from]; }
if ((strtotime($to) - strtotime($from)) > 92 * 86400) { $from = addDaysISO($to, -92); }   // keep the summary quick

$self = static fn(array $x = []): string => 'trip-expenses.php?' . http_build_query(array_merge(['date' => $date, 'sid' => $sid ?: null, 'from' => $from, 'to' => $to], $x));

/* ---- POST: add / edit / delete ---------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act  = (string) ($_POST['act'] ?? '');
    $back = $self();
    $savedReceipt = null;
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — फेरि खोल्नुहोस् · please try again.');
        }
        if (!$on) {
            throw new RuntimeException('ट्रिप खर्च बन्द छ · Trip expenses are switched off.');
        }
        if (!$canEnter) {
            throw new RuntimeException('तपाईंलाई खर्च लेख्ने अनुमति छैन · You may not record trip expenses.');
        }
        $cat    = (string) ($_POST['category'] ?? '');
        $amount = (float) str_replace(',', '', (string) ($_POST['amount'] ?? '0'));
        $cur    = (string) ($_POST['currency'] ?? 'INR');
        $lit    = trim((string) ($_POST['litres'] ?? ''));
        $litres = $lit === '' ? null : (float) $lit;
        $note   = Security::clean($_POST['note'] ?? '', 200);
        if ($act === 'add') {
            $postSid = (int) ($_POST['sid'] ?? 0);
            $file = $_FILES['receipt'] ?? null;
            if (is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (!TripExpense::hasCol('receipt_path')) {
                    throw new RuntimeException('रसिद राख्न पहिले migration चलाउनुहोस् · Run the op5 migration to keep receipts.');
                }
                $savedReceipt = TripExpense::saveReceipt($file);
            }
            $id = TripExpense::add($admin, $postSid, $cat, $amount, $cur, $litres, $note, $savedReceipt);
            $savedReceipt = null;
            $_SESSION['tx_flash'] = ['ok', '✔ ' . TripExpense::label($cat, false) . ' — ' . ($cur === 'NPR' ? 'रू ' . number_format($amount, 2) . ' = ' : '') . inr(TripExpense::toInr($amount, $cur)) . ' सेभ भयो · saved (#' . $id . ')'];
            $back = $self(['sid' => $postSid]) . '#list';
        } elseif ($act === 'edit') {
            TripExpense::update($admin, (int) ($_POST['id'] ?? 0), $cat, $amount, $cur, $litres, $note);
            $_SESSION['tx_flash'] = ['ok', '✔ खर्च बदलियो · Entry updated.'];
            $back .= '#list';
        } elseif ($act === 'delete') {
            TripExpense::remove($admin, (int) ($_POST['id'] ?? 0));
            $_SESSION['tx_flash'] = ['ok', '🗑 खर्च हटाइयो · Entry deleted.'];
            $back .= '#list';
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        if ($savedReceipt !== null && ($f = TripExpense::receiptFile($savedReceipt)) !== null) {
            @unlink($f);
        }
        $_SESSION['tx_flash'] = ['bad', $ex instanceof RuntimeException ? $ex->getMessage() : 'Could not save — फेरि प्रयास गर्नुहोस्।'];
        if (!$ex instanceof RuntimeException) {
            Logger::warning('trip-expenses: ' . $ex->getMessage());
        }
    }
    header('Location: ' . $back, true, 303);
    exit;
}

$flash = $_SESSION['tx_flash'] ?? null;
unset($_SESSION['tx_flash']);

$trips   = $on ? TripExpense::tripsOn($date) : [];
$trip    = null;
foreach ($trips as $t) { if ((int) $t['id'] === $sid) { $trip = $t; } }
if ($trip === null && $sid === 0 && count($trips) === 1) { $trip = $trips[0]; $sid = (int) $trip['id']; }
$entries = $trip !== null ? TripExpense::forSchedule((int) $trip['id']) : [];
$pnl     = $trip !== null && $canMoney ? TripExpense::tripPnl((int) $trip['id']) : null;
$dayPnl  = [];
if ($canMoney && $trips !== []) {
    foreach (TripExpense::range($date, $date)['trips'] as $t) { $dayPnl[(int) $t['id']] = $t; }
}
$range   = $canMoney && $on ? TripExpense::range($from, $to) : null;
$schema  = TripExpense::hasSchema();
$rate    = TripExpense::rate();
$canFile = $trip !== null && $canEnter && TripExpense::mayFileDate($admin, (string) $trip['travel_date']);
$defCur  = 'INR';
if ((string) ($admin['role'] ?? '') === 'counter') {
    $desk = CounterDesk::forAdmin((int) $admin['id'])['code'];
    if ($desk !== '' && CounterDesk::currency($desk) === 'NPR') { $defCur = 'NPR'; }
}
$entryTotal = 0.0;
foreach ($entries as $x) { $entryTotal += (float) $x['total']; }
$tripName = static fn(array $t): string => $t['from_city'] . ' → ' . $t['to_city'] . ((int) $t['slot'] > 1 ? ' (#' . (int) $t['slot'] . ')' : '');
$money = static fn(float $v): string => ($v < 0 ? '−' : '') . inr(abs($v));

admin_header('ट्रिप खर्च · Trip expenses', 'trip-expenses');
?>
<style>
.tx{display:grid;gap:14px;max-width:920px}
.tx-card{background:var(--card,#fff);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.tx-card h2{margin:0 0 8px;font-size:16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.tx-row{display:flex;flex-wrap:wrap;gap:8px;align-items:end}
.tx-row input[type=date]{padding:9px 10px;border:1px solid var(--line);border-radius:10px;min-height:44px;font:inherit}
.tx-trips{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px}
.tx-trip{display:block;padding:10px 12px;border:2px solid var(--line);border-radius:12px;text-decoration:none;color:inherit;min-height:44px}
.tx-trip.on{border-color:var(--brand,#1f5fbf);background:rgba(31,95,191,.07)}
.tx-trip small{display:block;color:var(--muted,#667);font-size:12px}
.tx-trip.cx{opacity:.55}
.tx-cats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0 0 10px}
.tx-cats input{position:absolute;opacity:0;width:1px;height:1px}
.tx-cats label{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;min-height:64px;padding:6px;border:2px solid var(--line);border-radius:12px;cursor:pointer;text-align:center;font-size:13px;line-height:1.15}
.tx-cats label b{font-size:24px;line-height:1}
.tx-cats input:checked+label{border-color:var(--brand,#1f5fbf);background:rgba(31,95,191,.10);font-weight:700}
.tx-cats input:focus-visible+label{outline:3px solid var(--brand,#1f5fbf);outline-offset:2px}
.tx-f{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}
.tx-f label,.tx-o label{font-size:12px;color:var(--muted,#667)}
.tx-f input,.tx-o input{display:block;width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;min-height:44px;font:inherit;box-sizing:border-box}
.tx-amt{font-size:22px!important;font-weight:700}
.tx-cur{display:flex;border:1px solid var(--line);border-radius:10px;overflow:hidden}
.tx-cur input{position:absolute;opacity:0;width:1px}
.tx-cur label{padding:0 14px;min-height:44px;display:flex;align-items:center;cursor:pointer;font-weight:700;color:inherit;font-size:15px}
.tx-cur input:checked+label{background:var(--brand,#1f5fbf);color:#fff}
.tx-o{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px;margin-top:8px}
.tx-lit{display:none}
.tx-form:has(#c-diesel:checked) .tx-lit{display:block}
.tx-go{width:100%;min-height:50px;font-size:17px;margin-top:10px}
.tx-list{list-style:none;margin:0;padding:0}
.tx-list li{display:flex;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px dashed var(--line)}
.tx-list .ic{font-size:22px;width:30px;text-align:center}
.tx-list .bd{flex:1;min-width:0}
.tx-list .am{font-weight:700;white-space:nowrap;text-align:right}
.tx-list small{color:var(--muted,#667);display:block}
.tx-list details summary{cursor:pointer;color:var(--brand,#1f5fbf);font-size:12.5px;margin-top:4px}
.tx-k{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.tx-k div{border:1px solid var(--line);border-radius:12px;padding:10px}
.tx-k small{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted,#667);font-weight:700}
.tx-k b{font-size:19px;font-variant-numeric:tabular-nums}
.tx-neg{color:#b02a2a}.tx-pos{color:#0a6b3b}
.tx-flash{padding:10px 12px;border-radius:10px}.tx-flash.ok{background:#e9f8ee;color:#0a6b3b}.tx-flash.bad{background:#fdecec;color:#b3261e}
.tx-note{font-size:12.5px;color:var(--muted,#667);margin:8px 0 0}
@media (max-width:420px){.tx-k{grid-template-columns:1fr 1fr}.tx-k div:last-child{grid-column:1/-1}.tx-card{padding:12px}}
</style>
<div class="tx">
  <?php if (is_array($flash)): ?><div class="tx-flash <?= $e($flash[0]) ?>" role="status"><?= $e($flash[1]) ?></div><?php endif; ?>

  <?php if (!$on): ?>
    <div class="tx-card"><h2>⏸ ट्रिप खर्च बन्द छ · Trip expenses are off</h2>
      <p class="tx-note">Settings मा <code>trip_expenses_on</code> खोल्नुहोस् · Turn on the <code>trip_expenses_on</code> switch.</p></div>
  <?php else: ?>

  <form method="get" class="tx-card">
    <h2>📅 मिति र ट्रिप · Date and trip</h2>
    <div class="tx-row">
      <a class="btn ghost" href="<?= $e($self(['date' => addDaysISO($date, -1), 'sid' => null])) ?>" aria-label="Previous day">◀</a>
      <input type="date" name="date" value="<?= $e($date) ?>" onchange="this.form.submit()" aria-label="Trip date">
      <a class="btn ghost" href="<?= $e($self(['date' => addDaysISO($date, 1), 'sid' => null])) ?>" aria-label="Next day">▶</a>
      <a class="btn ghost" href="<?= $e($self(['date' => todayISO(), 'sid' => null])) ?>">आज · Today</a>
      <input type="hidden" name="from" value="<?= $e($from) ?>"><input type="hidden" name="to" value="<?= $e($to) ?>">
      <noscript><button class="btn" type="submit">Show</button></noscript>
    </div>
    <div class="tx-trips" style="margin-top:10px">
      <?php foreach ($trips as $t): $p = $dayPnl[(int) $t['id']] ?? null; ?>
        <a class="tx-trip<?= (int) $t['id'] === $sid ? ' on' : '' ?><?= $t['status'] === 'cancelled' ? ' cx' : '' ?>" href="<?= $e($self(['sid' => (int) $t['id']])) ?>#entry">
          <b>🚌 <?= $e($tripName($t)) ?></b>
          <small><?= $e($t['bus_label'] !== '' ? $t['bus_label'] : 'बस तोकिएको छैन') ?> · <?= $e(substr((string) $t['dep_time'], 0, 5)) ?><?= $t['status'] === 'cancelled' ? ' · रद्द cancelled' : '' ?></small>
          <?php if ($p !== null): ?><small>आम्दानी <?= $e(inr($p['income'])) ?> · खर्च <?= $e(inr($p['expenses'])) ?> · <b class="<?= $p['profit'] < 0 ? 'tx-neg' : 'tx-pos' ?>"><?= $e($money($p['profit'])) ?></b></small><?php endif; ?>
        </a>
      <?php endforeach; ?>
      <?php if ($trips === []): ?><p class="muted" style="margin:0">यो मितिमा कुनै ट्रिप छैन · No trip on this date.</p><?php endif; ?>
    </div>
  </form>

  <?php if ($trip !== null): ?>
    <?php if ($canFile): ?>
    <form method="post" enctype="multipart/form-data" class="tx-card tx-form" id="entry" action="<?= $e($self()) ?>">
      <?= Security::csrfField() ?><input type="hidden" name="act" value="add"><input type="hidden" name="sid" value="<?= (int) $trip['id'] ?>">
      <h2>➕ खर्च थप्नुहोस् · Add a cost <span class="muted" style="font-size:12.5px;font-weight:400"><?= $e($tripName($trip)) ?> · <?= $e($trip['bus_label']) ?> · <?= $e(formatDate((string) $trip['travel_date'])) ?></span></h2>
      <div class="tx-cats" role="radiogroup" aria-label="Kind of cost">
        <?php foreach (TripExpense::CATEGORIES as $k => [$ic, $np, $en]): ?>
          <input type="radio" name="category" id="c-<?= $e($k) ?>" value="<?= $e($k) ?>" required><label for="c-<?= $e($k) ?>"><b aria-hidden="true"><?= $ic ?></b><?= $e($np) ?><small style="font-size:11px;color:var(--muted,#667)"><?= $e($en) ?></small></label>
        <?php endforeach; ?>
      </div>
      <div class="tx-f">
        <label>रकम · Amount<input class="tx-amt" type="number" name="amount" min="0.01" step="0.01" inputmode="decimal" required placeholder="0"></label>
        <div class="tx-cur" role="radiogroup" aria-label="Currency">
          <input type="radio" name="currency" id="cur-inr" value="INR" <?= $defCur === 'INR' ? 'checked' : '' ?>><label for="cur-inr">₹ INR</label>
          <input type="radio" name="currency" id="cur-npr" value="NPR" <?= $defCur === 'NPR' ? 'checked' : '' ?>><label for="cur-npr">रू NPR</label>
        </div>
      </div>
      <p class="tx-note">रू NPR लेखे ₹ मा बदलिन्छ: ₹1 = रू <?= $e(rtrim(rtrim(number_format($rate, 4, '.', ''), '0'), '.')) ?> · NPR is converted at the office rate.</p>
      <div class="tx-o">
        <label class="tx-lit">लिटर (डिजेल) · Litres<input type="number" name="litres" min="0" max="2000" step="0.01" inputmode="decimal" placeholder="e.g. 180"></label>
        <label>नोट · Note<input type="text" name="note" maxlength="200" placeholder="पम्प, बिल नं, कसलाई दिइयो"></label>
        <?php if ($schema): ?><label>रसिद फोटो · Receipt (optional)<input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment"></label><?php endif; ?>
      </div>
      <button class="btn btn-orange tx-go" type="submit">💾 सेभ · Save cost</button>
    </form>
    <?php elseif ($canEnter): ?>
      <div class="tx-card" id="entry"><p class="tx-note" style="margin:0">यो मितिको खर्च अफिसले मात्र लेख्छ (काउन्टर: ३ दिन अघिदेखि भोलिसम्म) · Only the office may file costs for this date.</p></div>
    <?php endif; ?>

    <?php if ($pnl !== null): ?>
    <div class="tx-card">
      <h2>📊 यो ट्रिपको नाफा · This trip's profit</h2>
      <div class="tx-k">
        <div><small>टिकट आम्दानी · Income</small><b><?= $e(inr($pnl['income'])) ?></b><small style="text-transform:none;font-weight:400"><?= (int) $pnl['tickets'] ?> tickets · <?= (int) $pnl['seats'] ?> seats</small></div>
        <div><small>खर्च · Expenses</small><b><?= $e(inr($pnl['expenses'])) ?></b></div>
        <div><small>नाफा · Profit</small><b class="<?= $pnl['profit'] < 0 ? 'tx-neg' : 'tx-pos' ?>"><?= $e($money($pnl['profit'])) ?></b></div>
      </div>
      <p class="tx-note">आम्दानी = यो बसमा confirmed / completed टिकट (Profit &amp; loss पेजकै नियम)। एजेन्ट कमिसन घटाइएको छैन — त्यो <a href="pnl.php?<?= $e(http_build_query(['from' => $trip['travel_date'], 'to' => $trip['travel_date']])) ?>">Profit &amp; loss</a> मा।</p>
    </div>
    <?php endif; ?>

    <div class="tx-card" id="list">
      <h2>🧾 यो ट्रिपका खर्च · Costs of this trip <span class="muted" style="margin-left:auto"><?= count($entries) ?> · <?= $e(inr($entryTotal)) ?></span></h2>
      <?php if (!$schema): ?><p class="tx-note">पुरानो ढाँचा: यो route र मितिका सबै खर्च देखिन्छन् (migration पछि ट्रिप-अनुसार) · Old shape until the op5 migration runs.</p><?php endif; ?>
      <ul class="tx-list">
        <?php foreach ($entries as $x):
            $c = TripExpense::categoryOf($x); [$ic, $np, $en] = TripExpense::CATEGORIES[$c];
            $mine = TripExpense::mayChange($admin, $x);
            $cur  = (string) ($x['currency'] ?? 'INR');
            $orig = $cur === 'NPR' && $x['amount'] !== null ? 'रू ' . number_format((float) $x['amount'], 2) : ''; ?>
          <li>
            <span class="ic" aria-hidden="true"><?= $ic ?></span>
            <div class="bd">
              <b><?= $e($np) ?></b> <span class="muted"><?= $e($en) ?></span>
              <?php if (!empty($x['litres'])): ?> · <?= $e(rtrim(rtrim((string) $x['litres'], '0'), '.')) ?> L<?php endif; ?>
              <?php if ((string) ($x['note'] ?? '') !== ''): ?><small><?= $e((string) $x['note']) ?></small><?php endif; ?>
              <small><?= $e((string) ($x['by_name'] ?: $x['by_user'] ?: '—')) ?> · <?= $e(substr((string) $x['created_at'], 0, 16)) ?><?php if (!empty($x['receipt_path'])): ?> · <a href="<?= $e('trip-expenses.php?receipt=' . (int) $x['id']) ?>" target="_blank" rel="noopener">🧾 रसिद</a><?php endif; ?></small>
              <?php if ($mine && $schema): ?>
              <details><summary>✏️ बदल्नुहोस् / हटाउनुहोस् · Edit / delete</summary>
                <form method="post" action="<?= $e($self()) ?>" class="tx-o">
                  <?= Security::csrfField() ?><input type="hidden" name="act" value="edit"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                  <label>किसिम · Kind<select name="category" style="display:block;width:100%;min-height:44px;border:1px solid var(--line);border-radius:10px"><?php foreach (TripExpense::CATEGORIES as $k => $cc): ?><option value="<?= $e($k) ?>" <?= $k === $c ? 'selected' : '' ?>><?= $e($cc[0] . ' ' . $cc[1] . ' · ' . $cc[2]) ?></option><?php endforeach; ?></select></label>
                  <label>रकम · Amount<input type="number" name="amount" min="0.01" step="0.01" required value="<?= $e((string) ($x['amount'] ?? $x['total'])) ?>"></label>
                  <label>मुद्रा · Currency<select name="currency" style="display:block;width:100%;min-height:44px;border:1px solid var(--line);border-radius:10px"><option value="INR" <?= $cur !== 'NPR' ? 'selected' : '' ?>>₹ INR</option><option value="NPR" <?= $cur === 'NPR' ? 'selected' : '' ?>>रू NPR</option></select></label>
                  <label>लिटर · Litres<input type="number" name="litres" min="0" max="2000" step="0.01" value="<?= $e((string) ($x['litres'] ?? '')) ?>"></label>
                  <label>नोट · Note<input type="text" name="note" maxlength="200" value="<?= $e((string) ($x['note'] ?? '')) ?>"></label>
                  <button class="btn" type="submit" style="min-height:44px">💾 Save</button>
                </form>
                <form method="post" action="<?= $e($self()) ?>" onsubmit="return confirm('यो खर्च हटाउने? · Delete this cost?')" style="margin-top:6px">
                  <?= Security::csrfField() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
                  <button class="btn ghost" type="submit" style="min-height:44px">🗑 हटाउनुहोस् · Delete</button>
                </form>
              </details>
              <?php endif; ?>
            </div>
            <span class="am"><?= $e(inr((float) $x['total'])) ?><?php if ($orig !== ''): ?><small><?= $e($orig) ?></small><?php endif; ?></span>
          </li>
        <?php endforeach; ?>
        <?php if ($entries === []): ?><li class="muted">अहिलेसम्म खर्च छैन · No cost entered yet.</li><?php endif; ?>
      </ul>
    </div>
  <?php elseif ($trips !== []): ?>
    <div class="tx-card"><p class="tx-note" style="margin:0">माथि एउटा ट्रिप छान्नुहोस् · Tap a trip above to enter its costs.</p></div>
  <?php endif; ?>

  <?php if ($range !== null): ?>
  <div class="tx-card" id="by-bus">
    <h2>🚌 बस-अनुसार नाफा · Profit by bus</h2>
    <form method="get" class="tx-row" style="margin-bottom:10px">
      <input type="hidden" name="date" value="<?= $e($date) ?>"><?php if ($sid > 0): ?><input type="hidden" name="sid" value="<?= (int) $sid ?>"><?php endif; ?>
      <label class="muted" style="font-size:11px">From<input type="date" name="from" value="<?= $e($from) ?>" style="display:block"></label>
      <label class="muted" style="font-size:11px">To<input type="date" name="to" value="<?= $e($to) ?>" style="display:block"></label>
      <button class="btn" type="submit" style="min-height:44px">Show</button>
    </form>
    <div class="dt-wrap"><table class="dt card-table">
      <thead><tr><th>Bus</th><th class="num">Trips</th><th class="num">आम्दानी · Income</th><th class="num">खर्च · Expenses</th><th class="num">नाफा · Profit</th><th class="num">Per trip</th></tr></thead>
      <tbody>
      <?php foreach ($range['buses'] as $b): ?>
        <tr>
          <td data-label="Bus"><b><?= $e($b['bus_label']) ?></b></td>
          <td data-label="Trips" class="num"><?= (int) $b['trips'] ?></td>
          <td data-label="Income" class="num"><?= $e(inr($b['income'])) ?></td>
          <td data-label="Expenses" class="num"><?= $b['expenses'] > 0 ? '−' . $e(inr($b['expenses'])) : '<span class="muted">—</span>' ?></td>
          <td data-label="Profit" class="num <?= $b['profit'] < 0 ? 'tx-neg' : 'tx-pos' ?>"><b><?= $e($money($b['profit'])) ?></b></td>
          <td data-label="Per trip" class="num"><?= $b['trips'] > 0 ? $e($money($b['profit'] / $b['trips'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if ($range['unlinked'] > 0): ?>
        <tr><td data-label="Bus" class="muted">ट्रिप नजोडिएको पुरानो खर्च · Old costs with no trip</td><td class="num">—</td><td class="num">—</td><td data-label="Expenses" class="num">−<?= $e(inr($range['unlinked'])) ?></td><td class="num">—</td><td class="num">—</td></tr>
      <?php endif; ?>
      <?php if ($range['buses'] === []): ?><tr><td colspan="6" class="muted" style="text-align:center">यी मितिमा ट्रिप छैन · No trips in these dates.</td></tr><?php endif; ?>
      </tbody>
      <tfoot><tr><td><b>जम्मा · Total</b></td><td class="num"><?= (int) $range['total']['trips'] ?></td><td class="num"><?= $e(inr($range['total']['income'])) ?></td><td class="num"><?= $range['total']['expenses'] > 0 ? '−' . $e(inr($range['total']['expenses'])) : '—' ?></td><td class="num <?= $range['total']['profit'] < 0 ? 'tx-neg' : 'tx-pos' ?>"><b><?= $e($money($range['total']['profit'])) ?></b></td><td></td></tr></tfoot>
    </table></div>
    <p class="tx-note">नाफा = टिकट आम्दानी − ट्रिप खर्च (₹)। एजेन्ट कमिसन र मासिक तलब यहाँ छैन — <a href="pnl.php?<?= $e(http_build_query(['from' => $from, 'to' => $to])) ?>">Profit &amp; loss</a> र Payroll हेर्नुहोस्।</p>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php admin_footer();
