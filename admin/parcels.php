<?php
/**
 * =====================================================================
 *  admin/parcels.php — पार्सल · Parcels: a small register per bus (25 Nov 2026, op5).
 *
 *  Counter / office (bookings.edit): book a parcel (from, to, sender, receiver,
 *  items, pieces, kg, amount, Paid / To pay), list a date (one bus or all),
 *  mark it loaded / delivered / cancelled, print a small slip, and the Paid and
 *  To-pay totals per currency. Sender / receiver numbers are tap-to-call.
 *
 *  Not here, on purpose: no public page, no WhatsApp / SMS, no automatic
 *  "paid" - Paid or To pay is what the clerk chose. Every change is audited
 *  (Parcels::add / setStatus). Switch parcel_book_on (default on); the table
 *  comes from database/upgrade-2026-11-25-op5-parcels.sql.
 * =====================================================================
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/parcels.php';

$admin = admin_boot('bookings.edit');
$e     = static fn($v): string => Security::e((string) $v);
$on    = Settings::getBool('parcel_book_on', true);
$ready = Parcels::hasTable();
$date  = Security::isValidDate((string) ($_GET['date'] ?? '')) ? (string) $_GET['date'] : todayISO();
$sid   = max(0, (int) ($_GET['sid'] ?? 0));
$payF  = isset(Parcels::PAY[(string) ($_GET['pay'] ?? '')]) ? (string) $_GET['pay'] : '';
$flash = [];
$qs    = static fn(array $x = []): string => 'parcels.php?' . http_build_query(array_filter(
    ['date' => $date, 'sid' => $sid > 0 ? $sid : null, 'pay' => $payF !== '' ? $payF : null] + $x,
    static fn($v): bool => $v !== null && $v !== ''));

/* ---- the slip (one parcel, printable) -------------------------------- */
if (isset($_GET['slip'])) {
    $p = $on && $ready ? Parcels::get((int) $_GET['slip']) : null;
    if ($p === null) {
        http_response_code(404);
        exit('Parcel not found.');
    }
    $cur = Parcels::CURRENCIES[(string) $p['currency']] ?? (string) $p['currency'];
    $company = (string) (Settings::companyFor('', '')['name'] ?? 'S Hari Global');
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="ne"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Parcel <?= $e(Parcels::number($p)) ?></title>
<style>
body{font:14px/1.4 system-ui,"Noto Sans Devanagari",sans-serif;color:#111;background:#fff;margin:0;padding:12px}
.slip{max-width:300px;margin:0 auto;border:1px dashed #333;padding:10px 12px}
h1{font-size:16px;margin:0 0 2px;text-align:center}.no{text-align:center;font:700 18px monospace;margin:4px 0 8px}
.r{display:flex;justify-content:space-between;gap:8px;border-bottom:1px dotted #bbb;padding:3px 0}.r b{text-align:right}
.big{font-size:17px;font-weight:800;text-align:center;margin:8px 0 2px}.muted{color:#555;font-size:12px;text-align:center}
@media print{.np{display:none}body{padding:0}.slip{border:0}}
</style></head><body>
<div class="slip">
  <h1>📦 <?= $e($company) ?></h1>
  <div class="muted">पार्सल रसिद · Parcel slip</div>
  <div class="no"><?= $e(Parcels::number($p)) ?></div>
  <div class="r"><span>मिति · Date</span><b><?= $e(formatDate((string) $p['travel_date'])) ?></b></div>
  <?php if ((string) ($p['bus_label'] ?? '') !== ''): ?><div class="r"><span>बस · Bus</span><b><?= $e($p['bus_label']) ?></b></div><?php endif; ?>
  <div class="r"><span>बाट · From</span><b><?= $e($p['from_place']) ?></b></div>
  <div class="r"><span>सम्म · To</span><b><?= $e($p['to_place']) ?></b></div>
  <div class="r"><span>पठाउने · Sender</span><b><?= $e($p['sender_name']) ?><br><?= $e($p['sender_phone']) ?></b></div>
  <div class="r"><span>बुझ्ने · Receiver</span><b><?= $e($p['receiver_name']) ?><br><?= $e($p['receiver_phone']) ?></b></div>
  <div class="r"><span>सामान · Items</span><b><?= $e($p['items']) ?></b></div>
  <div class="r"><span>पिस · Pieces</span><b><?= (int) $p['pieces'] ?><?= $p['weight_kg'] !== null ? ' · ' . $e(rtrim(rtrim((string) $p['weight_kg'], '0'), '.')) . ' kg' : '' ?></b></div>
  <div class="big"><?= $e($cur . ' ' . number_format((float) $p['amount'])) ?> · <?= $e(Parcels::PAY[(string) $p['pay']][0] . ' / ' . Parcels::PAY[(string) $p['pay']][1]) ?></div>
  <?php if ((string) ($p['note'] ?? '') !== ''): ?><div class="muted"><?= $e($p['note']) ?></div><?php endif; ?>
  <div class="muted">बुक गर्ने · Booked by <?= $e((string) ($p['booked_by_name'] ?? '')) ?> · <?= $e(date('d M H:i', strtotime((string) $p['created_at']) ?: time())) ?></div>
  <div class="muted">सहयोग · Help: <?= $e(Settings::officePhone()) ?></div>
</div>
<p class="np" style="text-align:center"><button onclick="window.print()">🖨️ Print</button> <a href="<?= $e($qs()) ?>">← Parcels</a></p>
</body></html><?php
    exit;
}

/* ---- POST: add / status ----------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash[] = ['bad', 'Session expired — फेरि खोल्नुहोस् · please reload.'];
    } elseif (!$on || !$ready) {
        $flash[] = ['bad', 'पार्सल बन्द छ · Parcels are switched off.'];
    } else {
        try {
            if ($act === 'add') {
                $id = Parcels::add($_POST, (int) $admin['id']);
                $date = (string) $_POST['travel_date'];
                $flash[] = ['ok', '📦 ' . Parcels::number(['id' => $id, 'travel_date' => $date]) . ' बुक भयो · booked.'];
            } elseif ($act === 'status') {
                $p = Parcels::setStatus((int) ($_POST['id'] ?? 0), (string) ($_POST['to'] ?? ''), (int) $admin['id']);
                $flash[] = ['ok', Parcels::number($p) . ' → ' . Parcels::STATUSES[(string) $p['status']][0] . ' · ' . Parcels::STATUSES[(string) $p['status']][1]];
            }
        } catch (Throwable $ex) {
            $flash[] = ['bad', $ex->getMessage()];
        }
    }
}

$deps  = Parcels::departures($date);
$rows  = $on && $ready ? Parcels::listFor($date, $sid, $payF) : [];
$tot   = Parcels::totals($rows);
$depBy = array_column($deps, 'label', 'id');

admin_header('पार्सल · Parcels', 'parcels');
?>
<style>
.pc-grid{display:grid;gap:14px;max-width:900px}
.pc-card{background:var(--surface,#fff);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.pc-card h2{margin:0 0 6px;font-size:16px}.pc-card p.hint{margin:0 0 10px;color:var(--muted);font-size:12.5px}
.pc-f{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
.pc-f label{font-size:12px;color:var(--muted)}.pc-f input,.pc-f select{display:block;width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid var(--line);border-radius:9px;min-height:44px;font:inherit}
.pc-flash{padding:9px 12px;border-radius:10px}.pc-flash.ok{background:#e9f8ee;color:#0a7c2f}.pc-flash.bad{background:#fdecec;color:#b3261e}
.pc-tot{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px}
.pc-tot div{border:1px solid var(--line);border-radius:12px;padding:8px 10px}.pc-tot b{display:block;font-size:17px}
.pc-item{border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin:0 0 10px}
.pc-item.st-cancelled{opacity:.55}.pc-item.st-delivered{border-color:#9bd3ad;background:#f3fbf5}
.pc-top{display:flex;flex-wrap:wrap;justify-content:space-between;gap:6px;align-items:baseline}
.pc-pill{display:inline-block;border-radius:999px;padding:2px 9px;font-size:12px;font-weight:700;background:#eef2ff;color:#3730a3}
.pc-pill.paid{background:#e9f8ee;color:#0a7c2f}.pc-pill.to_pay{background:#fff4e5;color:#9a5b00}
.pc-ppl{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:4px 12px;margin:6px 0;font-size:13.5px}
.pc-ppl a{font-weight:700;white-space:nowrap}
.pc-act{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}.pc-act form{margin:0}.pc-act .btn{min-height:40px}
</style>
<div class="pc-grid">
  <?php foreach ($flash as [$t, $m]): ?><div class="pc-flash <?= $e($t) ?>"><?= $e($m) ?></div><?php endforeach; ?>
  <?php if (!$ready): ?>
    <div class="pc-flash bad">पार्सलको तालिका अझै बनेको छैन · The parcels table is not there yet — run database/upgrade-2026-11-25-op5-parcels.sql.</div>
  <?php elseif (!$on): ?>
    <div class="pc-flash bad">पार्सल बन्द छ (parcel_book_on = 0) · Parcels are switched off in Settings.</div>
  <?php endif; ?>

  <form method="get" class="pc-card">
    <h2>📦 पार्सल · Parcels — <?= $e(formatDate($date)) ?></h2>
    <div class="pc-f">
      <label>मिति · Date<input type="date" name="date" value="<?= $e($date) ?>"></label>
      <label>बस · Bus<select name="sid"><option value="">सबै · All</option>
        <?php foreach ($deps as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $sid === $d['id'] ? 'selected' : '' ?>><?= $e($d['label']) ?></option><?php endforeach; ?>
      </select></label>
      <label>भुक्तानी · Pay<select name="pay"><option value="">सबै · All</option>
        <?php foreach (Parcels::PAY as $k => [$ne, $en]): ?><option value="<?= $e($k) ?>" <?= $payF === $k ? 'selected' : '' ?>><?= $e($ne . ' · ' . $en) ?></option><?php endforeach; ?>
      </select></label>
      <label>&nbsp;<button class="btn" type="submit" style="width:100%;min-height:44px">हेर्नुहोस् · Show</button></label>
    </div>
  </form>

  <div class="pc-card">
    <h2>जम्मा · Totals<?= $sid > 0 ? ' · ' . $e($depBy[$sid] ?? '') : '' ?></h2>
    <div class="pc-tot">
      <div>पार्सल · Parcels<b><?= (int) $tot['count'] ?> · <?= (int) $tot['pieces'] ?> pcs</b></div>
      <div>तिरिसक्यो · Paid<b><?= $e(Parcels::moneyLine($tot['paid'])) ?></b></div>
      <div>बुझ्नेले तिर्ने · To pay<b><?= $e(Parcels::moneyLine($tot['to_pay'])) ?></b></div>
      <div>बसमा / बुझाइयो · Loaded / Delivered<b><?= (int) $tot['byStatus']['loaded'] ?> / <?= (int) $tot['byStatus']['delivered'] ?></b></div>
    </div>
    <p class="hint" style="margin-top:8px">रद्द पार्सल जम्मामा गनिँदैन। ₹ र रू कहिल्यै जोडिँदैन · Cancelled parcels are left out; INR and NPR are never added together.</p>
  </div>

  <?php if ($on && $ready): ?>
  <form method="post" class="pc-card" action="<?= $e($qs()) ?>">
    <?= Security::csrfField() ?><input type="hidden" name="act" value="add">
    <h2>➕ नयाँ पार्सल · New parcel</h2>
    <div class="pc-f">
      <label>मिति · Date *<input type="date" name="travel_date" value="<?= $e($date) ?>" required></label>
      <label>बस · Bus<select name="schedule_id"><option value="">— छान्नुहोस् · choose —</option>
        <?php foreach ($deps as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $sid === $d['id'] ? 'selected' : '' ?>><?= $e($d['label']) ?></option><?php endforeach; ?>
      </select></label>
      <label>बस नम्बर (अरू) · Bus no.<input name="bus_label" maxlength="60" placeholder="GJ 01 ..."></label>
      <label>बाट · From *<input name="from_place" maxlength="120" required placeholder="Surat"></label>
      <label>सम्म · To *<input name="to_place" maxlength="120" required placeholder="Nepalgunj"></label>
      <label>पठाउने नाम · Sender *<input name="sender_name" maxlength="120" required></label>
      <label>पठाउने मोबाइल · Sender mobile *<input name="sender_phone" inputmode="tel" maxlength="16" required placeholder="98XXXXXXXX / 9XXXXXXXXX"></label>
      <label>बुझ्ने नाम · Receiver *<input name="receiver_name" maxlength="120" required></label>
      <label>बुझ्ने मोबाइल · Receiver mobile *<input name="receiver_phone" inputmode="tel" maxlength="16" required></label>
      <label>सामान · Items *<input name="items" maxlength="255" required placeholder="कपडाको बोरा · bag of clothes"></label>
      <label>पिस · Pieces *<input type="number" name="pieces" min="1" max="500" value="1" required></label>
      <label>तौल kg · Weight<input type="number" name="weight_kg" min="0" step="0.1" placeholder="—"></label>
      <label>रकम · Amount<input type="number" name="amount" min="0" step="1" value="0"></label>
      <label>मुद्रा · Currency<select name="currency"><?php foreach (Parcels::CURRENCIES as $c => $sym): ?><option value="<?= $e($c) ?>"><?= $e($sym . ' ' . $c) ?></option><?php endforeach; ?></select></label>
      <label>भुक्तानी · Pay *<select name="pay" required><option value="">— छान्नुहोस् · choose —</option>
        <?php foreach (Parcels::PAY as $k => [$ne, $en]): ?><option value="<?= $e($k) ?>"><?= $e($ne . ' · ' . $en) ?></option><?php endforeach; ?>
      </select></label>
      <label>नोट · Note<input name="note" maxlength="255"></label>
    </div>
    <p style="margin:10px 0 0"><button class="btn btn-orange" type="submit" style="min-height:44px">📦 बुक गर्नुहोस् · Book parcel</button></p>
  </form>
  <?php endif; ?>

  <div class="pc-card">
    <h2>सूची · List (<?= count($rows) ?>)</h2>
    <?php if ($rows === []): ?><p class="hint">यो मितिमा पार्सल छैन · No parcels on this date.</p><?php endif; ?>
    <?php foreach ($rows as $p): $st = (string) $p['status']; $pay = (string) $p['pay']; $cur = Parcels::CURRENCIES[(string) $p['currency']] ?? (string) $p['currency']; ?>
      <div class="pc-item st-<?= $e($st) ?>">
        <div class="pc-top">
          <b><?= $e(Parcels::number($p)) ?> · <?= $e($p['from_place']) ?> → <?= $e($p['to_place']) ?></b>
          <span><span class="pc-pill"><?= $e(Parcels::STATUSES[$st][0] . ' · ' . Parcels::STATUSES[$st][1]) ?></span>
            <span class="pc-pill <?= $e($pay) ?>"><?= $e($cur . ' ' . number_format((float) $p['amount'])) ?> · <?= $e(Parcels::PAY[$pay][1]) ?></span></span>
        </div>
        <div class="pc-ppl">
          <span>पठाउने · From: <?= $e($p['sender_name']) ?> <a href="<?= $e(Parcels::telHref((string) $p['sender_phone'])) ?>">📞 <?= $e($p['sender_phone']) ?></a></span>
          <span>बुझ्ने · To: <?= $e($p['receiver_name']) ?> <a href="<?= $e(Parcels::telHref((string) $p['receiver_phone'])) ?>">📞 <?= $e($p['receiver_phone']) ?></a></span>
          <span>सामान · Items: <?= $e($p['items']) ?> · <?= (int) $p['pieces'] ?> pcs<?= $p['weight_kg'] !== null ? ' · ' . $e(rtrim(rtrim((string) $p['weight_kg'], '0'), '.')) . ' kg' : '' ?></span>
          <span class="muted"><?= $e((string) ($p['bus_label'] ?? '') !== '' ? $p['bus_label'] . ' · ' : '') ?><?= $e($depBy[(int) ($p['schedule_id'] ?? 0)] ?? '') ?> · <?= $e((string) ($p['booked_by_name'] ?? '')) ?></span>
          <?php if ((string) ($p['note'] ?? '') !== ''): ?><span class="muted">📝 <?= $e($p['note']) ?></span><?php endif; ?>
        </div>
        <div class="pc-act">
          <a class="btn ghost" href="<?= $e($qs(['slip' => (int) $p['id']])) ?>" target="_blank" rel="noopener">🖨️ Slip</a>
          <?php foreach (['loaded' => '🚌 बसमा राखियो · Loaded', 'delivered' => '✅ बुझाइयो · Delivered', 'cancelled' => '✖ रद्द · Cancel'] as $to => $lbl):
              if ($st === $to || in_array($st, ['delivered', 'cancelled'], true) || ($to === 'loaded' && $st !== 'booked')) { continue; } ?>
            <form method="post" action="<?= $e($qs()) ?>"<?= $to === 'cancelled' ? ' onsubmit="return confirm(\'यो पार्सल रद्द गर्ने? · Cancel this parcel?\')"' : '' ?>>
              <?= Security::csrfField() ?><input type="hidden" name="act" value="status"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="to" value="<?= $e($to) ?>">
              <button class="btn<?= $to === 'cancelled' ? ' ghost' : '' ?>" type="submit"><?= $e($lbl) ?></button>
            </form>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php admin_footer(); ?>
