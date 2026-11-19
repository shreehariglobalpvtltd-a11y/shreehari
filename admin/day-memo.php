<?php
/**
 * admin/day-memo.php — दैनिक हिसाब मेमो · Daily collection memo (19 Nov 2026).
 *
 * Owner sent the paper pad his office already fills every night (two columns:
 * CASH | ONLINE, then market / credit / debit / cash, totals, CR / DE) and asked:
 * "format yehi sidha banaidinu, baki bharne thau khali rakhdeu". So this page is
 * that pad, for the bus business: every figure the system knows is printed from
 * the same day book the 21:30 WhatsApp report uses (CounterBook / counter_day_close);
 * every line it cannot know stays EMPTY for a pen. Print (A5) or save as PDF.
 *
 * Read-only. Same gate as Counters & Collection (reports.view); a desk-isolated counter sees only its own desk.
 *   ?day=YYYY-MM-DD   (default today)   ?desk=<code>   (default all desks)
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/counterdesk.php';
require_once INCLUDE_PATH . '/counterbook.php';

$admin = admin_boot('reports.view');
$e     = static fn($v): string => Security::e((string) $v);
$day   = Security::isValidDate((string) ($_GET['day'] ?? '')) ? (string) $_GET['day'] : todayISO();
$desks = CounterDesk::all();
$desk  = strtoupper(trim((string) ($_GET['desk'] ?? '')));
$scope = method_exists('Auth', 'deskScopeCode') ? Auth::deskScopeCode() : null;
if ($scope !== null && $scope !== '') {
    $desk = strtoupper($scope);                       // a desk-isolated clerk sees only their own desk
}
if ($desk !== '' && !isset($desks[$desk])) {
    $desk = '';
}

$book = CounterBook::day($day);
$rows = $book['rows'];
if ($desk !== '') {
    $rows = array_filter($rows, static fn(array $r, string $k): bool => $k === $desk, ARRAY_FILTER_USE_BOTH);
}
$sum = static fn(string $f): float => (float) array_sum(array_map(static fn(array $r): float => (float) ($r[$f] ?? 0), $rows));
$cashT = $sum('cash'); $onlineT = $sum('online'); $pendT = $sum('pending'); $commT = $sum('commission');
$refT = $sum('refund'); $paxT = (int) $sum('pax'); $tktT = (int) $sum('tickets'); $nprT = $sum('takenNpr');

/* online by method (all desks only: the per-desk split is in the day book) */
$byMethod = [];
if ($desk === '') {
    try {
        foreach (Database::fetchAll(
            "SELECT LOWER(p.method) m, COALESCE(SUM(p.amount), 0) s FROM payments p
              WHERE p.status = 'verified' AND p.verified_at >= :f AND p.verified_at < :t
                AND LOWER(p.method) NOT IN ('cash','cod') GROUP BY m ORDER BY s DESC",
            ['f' => $day, 't' => addDaysISO($day, 1)]) as $r) {
            $byMethod[(string) $r['m']] = (float) $r['s'];
        }
    } catch (Throwable $ex) {
        $byMethod = [];
    }
}
$expenses = 0.0; $closedN = 0;
try {
    $c = Database::fetch('SELECT COUNT(*) n, COALESCE(SUM(expenses), 0) x FROM counter_day_close WHERE business_date = :d'
        . ($desk !== '' ? ' AND desk_key = :k' : ''), ['d' => $day] + ($desk !== '' ? ['k' => $desk] : []));
    $expenses = (float) ($c['x'] ?? 0); $closedN = (int) ($c['n'] ?? 0);
} catch (Throwable $ex) {
    // no day-close table yet: the expense line stays empty for the pen
}
$label = static function (string $k, array $r) use ($desks): string {
    if ($k === '') { return 'अनलाइन · Online (website)'; }
    if (str_starts_with($k, 'AG:')) { return (string) (($r['label'] ?? '') !== '' && $r['label'] !== $k ? $r['label'] : 'Agent ' . substr($k, 3)); }
    return CounterDesk::label($k, (string) ($desks[$k]['name'] ?? ''));
};
$money = static fn(float $v): string => $v != 0.0 ? number_format($v, 0) : '';
$memoNo = (int) date('z', strtotime($day)) + 1;
$methodName = ['upi' => 'UPI', 'esewa' => 'eSewa', 'bank' => 'Bank / ICICI', 'card' => 'Card', 'khalti' => 'Khalti', 'wallet' => 'Wallet'];

/* CASH lines: every active desk (its name stays even with no cash, for the pen), plus any
   agent / no-desk seller or online line that really took cash today. */
$cashLines = [];
$deskKeys = $desk !== '' ? [$desk] : array_keys(CounterDesk::all(true));
foreach ($deskKeys as $k) {
    $r = $rows[$k] ?? ['cash' => 0.0, 'takenNpr' => 0.0, 'currency' => CounterDesk::currency((string) $k), 'label' => (string) $k];
    $cashLines[] = [$label((string) $k, $r), (float) $r['cash'], (string) ($r['currency'] ?? 'INR') === 'NPR' && (float) $r['takenNpr'] > 0 ? 'रू ' . number_format((float) $r['takenNpr'], 0) : ''];
}
foreach ($rows as $k => $r) {
    if (!in_array((string) $k, array_map('strval', $deskKeys), true) && (float) $r['cash'] != 0.0) {
        $cashLines[] = [$label((string) $k, $r), (float) $r['cash'], ''];
    }
}
$onlineLines = [];
if ($desk === '') {
    foreach ($byMethod as $m => $v) { $onlineLines[] = [$methodName[$m] ?? strtoupper($m), $v]; }
} else {
    $onlineLines[] = ['UPI / eSewa / Bank', $onlineT];
}
$pad = static fn(array $a, int $n): array => array_merge($a, array_fill(0, max(0, $n - count($a)), null));
$cashLines = $pad($cashLines, 5);
$onlineLines = $pad($onlineLines, 4);

admin_header('दैनिक हिसाब मेमो · Day memo', 'day-memo');
?>
<style>
.dm-tools{display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:0 0 12px}
.dm-tools label{font-size:12px;color:var(--muted)}
.dm-tools input,.dm-tools select{display:block;padding:7px 9px;border:1px solid var(--line);border-radius:8px;min-height:38px}
.dm-pad{--r:#C8102E;--ink:#2A1B12;background:#FFF4B8;color:var(--ink);max-width:720px;margin:0 auto;padding:18px 18px 22px;border-radius:6px;
  box-shadow:0 6px 24px rgba(0,0,0,.12);font-family:Poppins,"Noto Sans Devanagari",system-ui,sans-serif}
.dm-head{display:flex;align-items:center;gap:12px;border-bottom:2px solid var(--r);padding-bottom:8px}
.dm-head img{width:64px;height:auto}
.dm-head h1{margin:0;color:var(--r);font-size:20px;letter-spacing:.3px}
.dm-head .sub{font-size:11.5px;background:var(--r);color:#fff;padding:2px 6px;border-radius:3px;display:inline-block;margin-top:3px}
.dm-head .mob{margin-left:auto;background:var(--r);color:#fff;font-weight:700;font-size:12px;padding:4px 8px;border-radius:3px;white-space:nowrap}
.dm-meta{display:flex;justify-content:space-between;font-weight:700;margin:8px 2px}
.dm-grid{display:grid;grid-template-columns:1fr 1fr;border:2px solid var(--r)}
.dm-col{border-right:2px solid var(--r)}.dm-col:last-child{border-right:0}
.dm-col h2{margin:0;background:var(--r);color:#fff;text-align:center;font-size:15px;padding:6px 0;letter-spacing:.5px}
.dm-row{display:flex;align-items:flex-end;gap:6px;min-height:34px;padding:4px 8px;border-bottom:1px solid #E7A6A6}
.dm-row b{color:var(--r);font-size:13px;white-space:nowrap}
.dm-row span{flex:1;border-bottom:1px dotted #C99;min-height:20px;text-align:right;font:600 16px/1.2 "Segoe Print","Comic Sans MS",cursive;color:#1D2B6B}
.dm-row small{color:#7a5a3a;font-size:11px;white-space:nowrap}
.dm-tot{display:flex;align-items:center;gap:8px;padding:6px 8px;border-bottom:2px solid var(--r)}
.dm-tot b{background:var(--r);color:#fff;padding:4px 10px;font-size:17px;border-radius:2px}
.dm-tot span{flex:1;text-align:right;font:700 19px/1.2 "Segoe Print","Comic Sans MS",cursive;color:#1D2B6B;border-bottom:1px solid #C99;min-height:24px}
.dm-foot{display:grid;grid-template-columns:1fr 1fr 2fr;border:2px solid var(--r);border-top:0}
.dm-foot div{display:flex;align-items:center;gap:8px;padding:8px;border-right:2px solid var(--r);min-height:40px}
.dm-foot div:last-child{border-right:0}.dm-foot b{background:var(--r);color:#fff;padding:3px 9px}
.dm-bus{font-size:12px;margin-top:10px}.dm-bus td,.dm-bus th{padding:3px 6px;border-bottom:1px solid #E7A6A6;text-align:left}
.dm-note{font-size:11px;color:#7a5a3a;margin-top:8px}
@media (max-width:560px){.dm-grid{grid-template-columns:1fr}.dm-col{border-right:0;border-bottom:2px solid var(--r)}.dm-head .mob{display:none}}
@media print{body *{visibility:hidden}.dm-pad,.dm-pad *{visibility:visible}.dm-pad{position:absolute;left:0;top:0;box-shadow:none;max-width:none;width:100%}
  @page{size:A5 portrait;margin:8mm}.dm-tools{display:none}}
</style>

<form method="get" class="dm-tools sh-noprint">
  <label>मिति · Date<input type="date" name="day" value="<?= $e($day) ?>"></label>
  <?php if ($scope === null || $scope === ''): ?>
  <label>काउन्टर · Desk<select name="desk"><option value="">सबै · All desks</option>
    <?php foreach ($desks as $code => $d): ?><option value="<?= $e($code) ?>" <?= $code === $desk ? 'selected' : '' ?>><?= $e(CounterDesk::label((string) $code, (string) ($d['name'] ?? ''))) ?></option><?php endforeach; ?>
  </select></label>
  <?php endif; ?>
  <button class="btn" type="submit">देखाउनुहोस् · Show</button>
  <button class="btn btn-orange" type="button" onclick="window.print()">🖨 प्रिन्ट / PDF · Print</button>
  <a class="btn" href="counters.php?from=<?= $e($day) ?>&amp;to=<?= $e($day) ?>">काउन्टर विवरण · Counters</a>
</form>

<div class="dm-pad" id="dmPad">
  <div class="dm-head">
    <img src="/assets/img/logo-mark-tm.webp" alt="">
    <div>
      <h1>S HARI GLOBAL PVT. LTD.</h1>
      <div class="sub">दैनिक हिसाब मेमो · Daily Collection Memo<?= $desk !== '' ? ' · ' . $e(CounterDesk::label($desk, (string) ($desks[$desk]['name'] ?? ''))) : '' ?></div>
    </div>
    <div class="mob">MOB: 9726401507</div>
  </div>
  <div class="dm-meta"><span>नं · No. <?= $e($memoNo) ?></span><span>DATE: <?= $e(date('d/m/y', strtotime($day))) ?></span></div>

  <div class="dm-grid">
    <div class="dm-col">
      <h2>नगद · CASH</h2>
      <?php foreach ($cashLines as $l): ?>
        <div class="dm-row"><b><?= $l === null ? '' : $e($l[0]) . ' :' ?></b><span><?= $l === null ? '' : $e($money((float) $l[1])) ?></span><?php if ($l !== null && $l[2] !== ''): ?><small><?= $e($l[2]) ?></small><?php endif; ?></div>
      <?php endforeach; ?>
      <div class="dm-row"><b>Extra :</b><span></span></div>
      <div class="dm-tot"><b>Total :</b><span><?= $e($money($cashT)) ?></span></div>
      <div class="dm-row"><b>बोर्डिङमा बाँकी · Board due :</b><span><?= $e($money($pendT)) ?></span></div>
      <div class="dm-row"><b>खर्च · Market / Exp :</b><span><?= $e($money($expenses)) ?></span></div>
      <div class="dm-row"><b>Credit :</b><span></span></div>
      <div class="dm-row"><b>Debit :</b><span></span></div>
      <div class="dm-row"><b>Cash :</b><span></span></div>
      <div class="dm-tot"><b>Total :</b><span></span></div>
    </div>
    <div class="dm-col">
      <h2>अनलाइन · ONLINE</h2>
      <?php foreach ($onlineLines as $l): ?>
        <div class="dm-row"><b><?= $l === null ? '' : $e($l[0]) . ' :' ?></b><span><?= $l === null ? '' : $e($money((float) $l[1])) ?></span></div>
      <?php endforeach; ?>
      <div class="dm-tot"><b>Total :</b><span><?= $e($money($onlineT)) ?></span></div>
      <div class="dm-row"><b>टिकट · Tickets :</b><span><?= $tktT > 0 ? $e($tktT) : '' ?></span></div>
      <div class="dm-row"><b>यात्रु · Passengers :</b><span><?= $paxT > 0 ? $e($paxT) : '' ?></span></div>
      <div class="dm-row"><b>कमिसन · Commission :</b><span><?= $e($money($commT)) ?></span></div>
      <div class="dm-row"><b>फिर्ता · Refund :</b><span><?= $e($money($refT)) ?></span></div>
      <div class="dm-row"><b>NPR (रू) :</b><span><?= $e($money($nprT)) ?></span></div>
      <div class="dm-row"><b>Extra :</b><span></span></div>
      <div class="dm-row"><b>Extra :</b><span></span></div>
      <div class="dm-tot"><b>जम्मा · Total</b><span><?= $e($money($cashT + $onlineT)) ?></span></div>
    </div>
  </div>
  <div class="dm-foot">
    <div><b>CR</b></div>
    <div><b>DE</b></div>
    <div>हस्ताक्षर · Sign: <?= $closedN > 0 ? '<small>(' . $e($closedN) . ' काउन्टरले हिसाब बुझायो · closed)</small>' : '' ?></div>
  </div>
  <p class="dm-note">छापिएका अङ्क system बाट (<?= $e(date('d M Y H:i')) ?>); खाली ठाउँ हातले भर्नुहोस्। · Printed figures come from the system; fill the empty lines by hand.</p>
</div>
<?php admin_footer(); ?>
