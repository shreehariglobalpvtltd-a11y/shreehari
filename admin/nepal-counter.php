<?php
/**
 * admin/nepal-counter.php — the Nepal counter's home (22 Nov 2026).
 *
 * Owner: "Nepalgunj ko lagi counter mode special banaunus - m yahi chhu,
 * yaha booking garna sajilo hos, har currency dekhiyos, j j change hunchha
 * sabai dhyan diyera."
 *
 * ONE screen for the clerk at a Nepal window (NPJ, NPJD …), in Nepali first:
 *   - today in AD and BS, the desk's rate (1 ₹ = रू x), a clock
 *   - big buttons: new ticket (SmartBook, Counter mode), Quick ticket, the
 *     day's list, the cash memo
 *   - the next buses Rupaidiha -> Gujarat (today + 2 days): time, berths
 *     free, women's berths free, "book this bus" (opens SmartBook on that date)
 *   - the fare board in NPR first and ₹ beside it, per drop, for 1-4 people
 *   - money tools: NPR <-> ₹ converter, change to give back + notes to hand
 *   - today at this desk: tickets, passengers, NPR / ₹ taken, by method
 *   - the road to our Rupaidiha parking (satellite + Nepali steps) and a
 *     WhatsApp share of the same steps for the passenger
 *
 * READ-ONLY. Nothing here books, prices or moves money: every sale is still
 * SmartBook / Quick ticket (BookingService), with the desk's own rules (one
 * way, NPR frozen on the ticket). Prices are read from Fare::board(), the
 * same list the checkout bills with; the final price (discounts, children,
 * luggage) is always the one SmartBook shows.
 *
 * Who: bookings.view. A clerk sees their own desk; the office may pick any
 * NPR desk (?desk=).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/counterdesk.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/companylocations.php';
require_once INCLUDE_PATH . '/locationmaps.php';

$admin = admin_boot('bookings.view');
$me    = (int) ($admin['id'] ?? 0);
$e     = static fn($v): string => Security::e((string) $v);

/* ---- which desk -------------------------------------------------------- */
$nprList = array_values(array_filter(array_keys(CounterDesk::all(true)), static fn($c): bool => CounterDesk::currency((string) $c) === 'NPR'));
$own     = (string) (CounterDesk::forAdmin($me)['code'] ?? '');
$isOffice = Auth::isSuperadmin() || Auth::can('reports.view');
$desk    = $own;
if ($isOffice) {
    $pick = strtoupper(Security::clean((string) ($_GET['desk'] ?? ''), 16));
    if ($pick !== '' && in_array($pick, $nprList, true)) {
        $desk = $pick;
    } elseif ($desk === '' || !in_array($desk, $nprList, true)) {
        $desk = in_array('NPJ', $nprList, true) ? 'NPJ' : (string) ($nprList[0] ?? '');
    }
} elseif ($desk !== '' && !in_array($desk, $nprList, true)) {
    /* fx1 (26 Nov 2026): a clerk on an INDIA desk (₹) who opened this page saw
       their rupee desk drawn with रू labels at rate 1. This home is for an
       NPR desk only - anyone else gets the "not on a Nepal desk" note below. */
    $desk = '';
}
/* fx1: which of the home's buttons this login can really open. Today's list
   is open to a clerk on their own desk (admin/desk-day.php, desk_day_clerk_on);
   the cash memo is an office page (reports.view). */
$mayMemo = Auth::can('reports.view');
$mayList = $isOffice || (Settings::getBool('desk_day_clerk_on', true) && Auth::can('payments.view')
    && CounterDesk::dayListDesk($admin, $desk, false) !== null);
/* The "Today at <desk>" panel is the WHOLE window's day - every clerk's and every
   agent's tickets and money. It was drawn for any login with bookings.view on the
   desk, so an agent (own sales only) and a login with no payments.view read the
   desk's takings. Office and the desk's own money-handling clerks only; no switch
   (a leak, not a feature) and it does not follow desk_day_clerk_on, so a clerk
   keeps the panel they always had when that switch is off. */
$mayDay  = $isOffice || (Auth::can('payments.view') && Auth::bookingScopeAdminId() === null);
$deskRow = $desk !== '' ? CounterDesk::get($desk) : null;
$cur     = $desk !== '' ? CounterDesk::currency($desk) : 'NPR';
$rate    = $desk !== '' ? CounterDesk::rate($desk) : Settings::getFloat('npr_per_inr', 1.6);
$npr     = static fn(float $inr): float => (float) round($inr * $rate);
$rs      = static fn(float $n): string => 'रू ' . number_format($n, 0);
$inrF    = static fn(float $n): string => '₹' . number_format($n, 0);

/* ---- dates: AD + BS ---------------------------------------------------- */
$bs = static function (string $ymd): string {
    try {
        $m = new ReflectionMethod(Ticket::class, 'bsDate');
        $m->setAccessible(true);
        return (string) $m->invoke(null, $ymd);
    } catch (Throwable) {
        return '';
    }
};
$today = todayISO();
$dayNe = ['आइतबार', 'सोमबार', 'मंगलबार', 'बुधबार', 'बिहीबार', 'शुक्रबार', 'शनिबार'];

/* ---- next buses Rupaidiha -> Gujarat ----------------------------------- */
$buses = [];
$seen  = [];
try {
    $routes = Database::fetchAll('SELECT * FROM routes WHERE is_active = 1 ORDER BY sort_order, id');
    $back = array_values(array_filter($routes, static fn(array $r): bool => Fare::routeDir($r) === 'toIndia'));
    for ($i = 0; $i < 3; $i++) {
        $d = date('Y-m-d', strtotime($today . " +$i day"));
        foreach ($back as $r) {
            try {
                $a = Seats::availability((int) $r['id'], $d, 'sharing');
                $s = Database::fetch('SELECT * FROM schedules WHERE id = :i', ['i' => (int) $a['scheduleId']]);
                if ($s === null || (string) ($s['status'] ?? '') === 'cancelled' || isset($seen[(int) $s['id']])) {
                    continue;
                }
                $seen[(int) $s['id']] = true;
                $ts = Fare::departTs($s, $r);
                $buses[] = [
                    'date' => $d, 'route' => $r, 'free' => (int) $a['availableCount'], 'total' => (int) $a['total'],
                    'women' => count(array_intersect((array) ($a['female'] ?? []), (array) ($a['available'] ?? []))),
                    'time' => $ts !== null ? date('g:i A', $ts) : '', 'past' => $ts !== null && $ts < time(),
                    'open' => Boarding::routeSellable((int) $r['id'], $d) && !(int) ($s['is_blocked'] ?? 0),
                ];
            } catch (Throwable) {
                // a route with no bus that day is simply not listed
            }
        }
    }
} catch (Throwable $ex) {
    Logger::warning('nepal-counter buses: ' . $ex->getMessage());
}

/* ---- fare board (Rupaidiha -> each Gujarat drop) ----------------------- */
$board = [];
try {
    $board = Fare::board()['back'] ?? [];
} catch (Throwable) {
    $board = [];
}
if ($board === []) {
    $board = [['name' => 'Gujarat', 'fare' => (float) Fare::dirFares()['toIndia'], 'time' => null]];
}
/* Stops with the same fare share one row (16 identical lines help nobody at the window). */
$fareRows = [];
foreach ($board as $f) {
    $k = number_format((float) $f['fare'], 2, '.', '');
    $fareRows[$k] ??= ['fare' => (float) $f['fare'], 'names' => []];
    $fareRows[$k]['names'][] = (string) $f['name'];
}
ksort($fareRows, SORT_NUMERIC);

/* ---- today at this desk ------------------------------------------------ */
$day = ['tickets' => 0, 'pax' => 0, 'npr' => 0.0, 'inr' => 0.0, 'pending' => 0, 'methods' => []];
if ($desk !== '' && $mayDay) {
    try {
        $hasProf = Database::fetch("SHOW COLUMNS FROM admin_profiles LIKE 'counter_code'") !== null;
        $hasBy   = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'booked_by_admin_id'") !== null;
        $hasFx   = CounterDesk::hasColumn('bookings', 'fx_total');
        $cutSql  = $hasBy ? 'COALESCE(b.booked_by_admin_id, b.sold_by_admin_id)' : 'b.sold_by_admin_id';
        $parts   = [];
        if (CounterDesk::stampColumn()) { $parts[] = "NULLIF(b.counter_code, '')"; }
        if ($hasProf) { $parts[] = "NULLIF(ap.counter_code, '')"; }
        $deskSql = 'COALESCE(' . implode(', ', array_merge($parts, ["''"])) . ')';
        $rows = Database::fetchAll(
            "SELECT b.status, b.total_amount, " . ($hasFx ? 'b.fx_currency, b.fx_total' : "'' AS fx_currency, 0 AS fx_total") . ",
                    GREATEST(1, COALESCE((SELECT SUM(l.seat_count) FROM booking_legs l WHERE l.booking_id = b.id AND l.leg_type = 'outbound'), 0)) AS seats,
                    (SELECT GROUP_CONCAT(DISTINCT p.method SEPARATOR '+') FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified') AS paid_by
               FROM bookings b LEFT JOIN admin_profiles ap ON " . ($hasProf ? "ap.admin_id = $cutSql" : '1 = 0') . "
              WHERE b.created_at >= :f AND b.created_at < :t AND $cutSql IS NOT NULL AND $deskSql = :k
                AND b.status IN ('pending', 'confirmed', 'completed')",
            ['f' => $today . ' 00:00:00', 't' => date('Y-m-d', strtotime($today . ' +1 day')) . ' 00:00:00', 'k' => $desk]
        );
        foreach ($rows as $r) {
            $n = strtoupper((string) $r['fx_currency']) === 'NPR' && (float) $r['fx_total'] > 0 ? (float) $r['fx_total'] : $npr((float) $r['total_amount']);
            $day['tickets']++;
            $day['pax'] += (int) $r['seats'];
            $day['npr'] += $n;
            $day['inr'] += (float) $r['total_amount'];
            if ((string) $r['status'] === 'pending') { $day['pending']++; }
            $m = (string) ($r['paid_by'] ?: 'unpaid');
            $day['methods'][$m] ??= ['n' => 0, 'npr' => 0.0];
            $day['methods'][$m]['n']++;
            $day['methods'][$m]['npr'] += $n;
        }
    } catch (Throwable $ex) {
        Logger::warning('nepal-counter day: ' . $ex->getMessage());
    }
}

/* ---- the road to our Rupaidiha parking --------------------------------- */
$roadLm = [];
$roadPlace = null;
foreach (CompanyLocations::all() as $p) {
    $lm = LocationMaps::saved((int) $p['id']);
    if (!empty($lm['guide']) && stripos($p['town'], 'rupaidiha') !== false) {
        $roadLm = $lm;
        $roadPlace = $p;
        break;
    }
}
if ($roadLm === [] && ($o = LocationMaps::nepalOffice()) !== null) {
    $roadLm = LocationMaps::saved((int) $o['id']);
}
$shareText = '';
if (!empty($roadLm['guide'])) {
    $lines = ['🚌 *S Hari Global* — नेपालगञ्ज कार्यालयबाट ' . ($roadPlace['nameNe'] ?? 'रुपैडिहा पार्किङ') . 'सम्म'
        . (isset($roadLm['km']) ? ' (' . LocationMaps::nd((string) $roadLm['km']) . ' कि.मि.)' : '')];
    foreach ($roadLm['guide'] as $i => $s) {
        $lines[] = ($i + 1) . '. ' . (string) ($s['ne'] ?? '');
    }
    if ($roadPlace !== null && $roadPlace['map'] !== '') {
        $lines[] = '📍 नक्सा: ' . $roadPlace['map'];
    }
    $lines[] = '🛰️ ' . rtrim((string) (defined('APP_URL') ? APP_URL : 'https://www.shreehariglobal.in'), '/') . '/offices#place-' . (int) ($roadPlace['id'] ?? 0);
    $shareText = implode("\n", $lines);
}

$sb = static fn(string $date = ''): string => '/admin/smartbook.php?mode=counter' . ($date !== '' ? '&sbdate=' . rawurlencode($date) : '');
$denoms = $desk !== '' ? CounterDesk::denominations($desk) : [1000, 500, 100, 50, 20, 10, 5];

admin_header('Nepal counter', 'nepal-counter');
?>
<style>
.nc{--nc-red:#c8102e;--nc-blue:#003893;max-width:1180px}
.nc-top{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;justify-content:space-between;padding:14px 16px;border-radius:16px;color:#fff;
  background:linear-gradient(120deg,var(--nc-blue),#1d4ed8 55%,var(--nc-red));margin:0 0 14px}
.nc-top h2{margin:0;font-size:22px}.nc-top small{opacity:.85}
.nc-rate{background:rgba(255,255,255,.16);border-radius:12px;padding:8px 12px;font-size:15px}
.nc-rate b{font-size:20px}
.nc-clock{font-size:26px;font-weight:800;font-variant-numeric:tabular-nums}
.nc-acts{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,200px),1fr));gap:10px;margin:0 0 16px}
.nc-acts a{display:flex;gap:10px;align-items:center;min-height:64px;padding:12px 14px;border-radius:14px;text-decoration:none;font-weight:700;font-size:16px;
  border:1px solid var(--line,#e2e8f0);background:var(--card,#fff);color:inherit}
.nc-acts a.main{background:#ea580c;color:#fff;border-color:#ea580c;font-size:18px}
.nc-acts a span{font-size:26px}
.nc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,520px),1fr));gap:14px}
.nc-card{background:var(--card,#fff);border:1px solid var(--line,#e2e8f0);border-radius:16px;padding:14px 16px}
.nc-card h3{margin:0 0 10px;font-size:17px}
.nc-bus{display:grid;grid-template-columns:1fr auto;gap:6px 10px;align-items:center;padding:10px 0;border-bottom:1px solid var(--line,#e2e8f0)}
.nc-bus:last-child{border-bottom:0}
.nc-bus b{font-size:16px}.nc-bus small{display:block;opacity:.75}
.nc-pill{display:inline-block;padding:2px 9px;border-radius:99px;font-size:12.5px;margin:3px 4px 0 0;background:#eef2f7;color:#0f172a}
.nc-pill.ok{background:#dcfce7;color:#166534}.nc-pill.lo{background:#fee2e2;color:#991b1b}.nc-pill.w{background:#fce7f3;color:#9d174d}
.nc-bus .btn{min-height:44px;display:inline-flex;align-items:center}
.nc-fare{width:100%;border-collapse:collapse;font-size:14.5px}
.nc-fare th,.nc-fare td{padding:8px 6px;border-bottom:1px solid var(--line,#e2e8f0);text-align:right;white-space:nowrap}
.nc-fare th:first-child,.nc-fare td:first-child{text-align:left;white-space:normal}
.nc-fare td b{font-size:17px}.nc-fare td small{display:block;opacity:.7}
.nc-tools{display:grid;gap:10px}
.nc-tools label{display:flex;flex-direction:column;gap:4px;font-size:13px}
.nc-tools input{min-height:48px;font-size:20px;padding:0 12px;border-radius:12px;border:1px solid #cbd5e1;width:100%;box-sizing:border-box}
.nc-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.nc-out{font-size:22px;font-weight:800;padding:8px 12px;border-radius:12px;background:#f1f5f9;color:#0f172a}
.nc-notes{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.nc-notes span{background:#fef3c7;color:#78350f;border-radius:8px;padding:4px 8px;font-weight:700}
.nc-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px}
.nc-kpi div{background:#f8fafc;color:#0f172a;border-radius:12px;padding:10px}
.nc-kpi b{display:block;font-size:22px}
.nc-wa{display:inline-flex;align-items:center;gap:8px;min-height:48px;padding:0 16px;border-radius:12px;background:#16a34a;color:#fff;font-weight:700;text-decoration:none;margin:4px 0 10px}
@media (max-width:520px){.nc-top h2{font-size:18px}.nc-clock{font-size:22px}.nc-fare{font-size:13.5px}}
</style>
<div class="nc">
<?php if ($desk === '' || $deskRow === null): ?>
  <div class="flash bad">यो login कुनै नेपाल काउन्टरमा राखिएको छैन। कार्यालयलाई Staff &amp; Approvals मा NPJ काउन्टर राख्न भन्नुहोस्। · This login is not on a Nepal desk.</div>
<?php else: ?>
  <div class="nc-top">
    <div>
      <h2>🇳🇵 <?= $e(CounterDesk::label($desk, (string) ($deskRow['name'] ?? ''))) ?></h2>
      <small><?= $e($dayNe[(int) date('w')]) ?> · <?= $e($bs($today)) ?> · <?= $e(formatDate($today, 'D, j M Y')) ?> · रुपैडिहा → गुजरात मात्र</small>
    </div>
    <div class="nc-rate">१ ₹ = <b><?= $e(LocationMaps::nd(number_format($rate, 2))) ?></b> रू <small>(<?= $e(number_format($rate, 2)) ?>)</small></div>
    <div class="nc-clock" id="ncClock" aria-live="off"><?= $e(date('g:i A')) ?></div>
    <?php if ($isOffice && count($nprList) > 1): ?>
      <form method="get" style="margin:0"><select name="desk" onchange="this.form.submit()" style="min-height:44px;border-radius:10px">
        <?php foreach ($nprList as $c): ?><option value="<?= $e($c) ?>"<?= $c === $desk ? ' selected' : '' ?>><?= $e($c) ?></option><?php endforeach; ?>
      </select></form>
    <?php endif; ?>
  </div>

  <nav class="nc-acts" aria-label="Counter actions">
    <a class="main" href="<?= $e($sb()) ?>"><span>🎫</span>नयाँ टिकट काट्नुहोस् · New ticket</a>
    <?php /* fx1: "Quick ticket" only redirected to the same SmartBook as "New ticket" - the tile is the cash book now. */ ?>
    <?php /* ?desk= : the book of THIS desk, in its own money (रू with ₹ beside it); without it the page lists every desk in ₹.
             An agent's book is their own sales whatever the desk, so the plain link is theirs. */ ?>
    <a href="/admin/cash-book.php<?= Auth::bookingScopeAdminId() === null ? '?desk=' . $e(rawurlencode($desk)) : '' ?>"><span>💵</span>नगद बुक · Cash book</a>
    <?php if ($mayList): ?><a href="/admin/desk-day.php?desk=<?= $e($desk) ?>"><span>📋</span>आजको सूची · Today's list</a><?php endif; ?>
    <?php if ($mayMemo): ?><a href="/admin/day-memo.php?desk=<?= $e($desk) ?>"><span>🧾</span>नगद हिसाब · Cash memo</a><?php endif; ?>
    <a href="#ncRoad"><span>🗺️</span>बाटो / नक्सा · Road to parking</a>
  </nav>

  <div class="nc-grid">
    <section class="nc-card" aria-labelledby="ncBusH">
      <h3 id="ncBusH">🚌 अर्को बस — रुपैडिहा → गुजरात</h3>
      <?php if ($buses === []): ?><p class="muted">अहिले कुनै बस छैन · No bus in the next 3 days.</p><?php endif; ?>
      <?php foreach ($buses as $bus):
          $low = $bus['free'] > 0 && $bus['free'] <= 6;
          $label = $bus['date'] === $today ? 'आज · Today' : ($bus['date'] === date('Y-m-d', strtotime($today . ' +1 day')) ? 'भोलि · Tomorrow' : 'पर्सि · ' . formatDate($bus['date'], 'D j M')); ?>
        <div class="nc-bus">
          <div>
            <b><?= $e($label) ?><?= $bus['time'] !== '' ? ' · ' . $e($bus['time']) : '' ?></b>
            <small><?= $e($bs($bus['date'])) ?> · <?= $e((string) $bus['route']['from_city']) ?> → <?= $e((string) $bus['route']['to_city']) ?></small>
            <span class="nc-pill <?= $bus['free'] === 0 ? 'lo' : ($low ? 'lo' : 'ok') ?>">🛏️ <?= (int) $bus['free'] ?> / <?= (int) $bus['total'] ?> खाली</span>
            <?php if ($bus['women'] > 0): ?><span class="nc-pill w">👩 महिला <?= (int) $bus['women'] ?></span><?php endif; ?>
            <?php if (!$bus['open'] || $bus['past']): ?><span class="nc-pill lo">बिक्री बन्द · closed</span><?php endif; ?>
          </div>
          <?php if ($bus['open'] && !$bus['past'] && $bus['free'] > 0): ?>
            <a class="btn ok" href="<?= $e($sb($bus['date'])) ?>">यो बस बुक →</a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>

    <section class="nc-card" aria-labelledby="ncFareH">
      <h3 id="ncFareH">💰 भाडा — रुपैडिहाबाट (रू पहिले, ₹ सँगै)</h3>
      <div style="overflow-x:auto"><table class="nc-fare">
        <thead><tr><th>झर्ने ठाउँ · Drop</th><th>१ जना</th><th>२ जना</th><th>३ जना</th><th>४ जना</th></tr></thead>
        <tbody>
        <?php foreach ($fareRows as $f): $one = (float) $f['fare']; ?>
          <tr><td><?= $e(implode(', ', array_unique($f['names']))) ?></td>
            <?php for ($n = 1; $n <= 4; $n++): ?><td><b><?= $e($rs($npr($one * $n))) ?></b><small><?= $e($inrF($one * $n)) ?></small></td><?php endfor; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="muted" style="font-size:12.5px;margin:8px 0 0">यो सामान्य भाडा हो। छुट, बच्चा, सामान र ५+ जनाको समूह छुट सहितको अन्तिम मूल्य SmartBook ले देखाउँछ — टिकटमा त्यही रू छापिन्छ। · Final price (discounts, children, luggage, group 5+) is what SmartBook shows.</p>
    </section>

    <section class="nc-card" aria-labelledby="ncToolH">
      <h3 id="ncToolH">💱 पैसा — रू ⇄ ₹ र फिर्ता</h3>
      <div class="nc-tools">
        <div class="nc-row">
          <label>नेपाली रू · NPR<input id="ncNpr" inputmode="decimal" autocomplete="off" placeholder="रू"></label>
          <label>भारतीय ₹ · INR<input id="ncInr" inputmode="decimal" autocomplete="off" placeholder="₹"></label>
        </div>
        <div class="nc-row">
          <label>टिकटको रकम (रू) · Bill<input id="ncBill" inputmode="decimal" autocomplete="off" placeholder="रू"></label>
          <label>ग्राहकले दिएको (रू) · Received<input id="ncGot" inputmode="decimal" autocomplete="off" placeholder="रू"></label>
        </div>
        <div class="nc-out" id="ncChange" aria-live="polite">फिर्ता · Change: —</div>
        <div class="nc-notes" id="ncNotes"></div>
        <p class="muted" style="font-size:12.5px;margin:0">₹ मा तिरे: ₹ × <?= $e(number_format($rate, 2)) ?> = रू। यो हिसाब मात्र हो, बिक्री SmartBook बाटै गर्नुहोस्।</p>
      </div>
    </section>

    <?php if ($mayDay): ?>
    <section class="nc-card" aria-labelledby="ncDayH">
      <h3 id="ncDayH">📊 आज यो काउन्टरमा · Today at <?= $e($desk) ?></h3>
      <div class="nc-kpi">
        <div>टिकट<b><?= (int) $day['tickets'] ?></b></div>
        <div>यात्रु<b><?= (int) $day['pax'] ?></b></div>
        <div>रकम (रू)<b><?= $e($rs($day['npr'])) ?></b></div>
        <div>₹ बराबर<b><?= $e($inrF($day['inr'])) ?></b></div>
      </div>
      <?php if ($day['methods'] !== []): ?>
        <p style="margin:10px 0 0">
          <?php foreach ($day['methods'] as $m => $x): ?><span class="nc-pill"><?= $e($m === 'unpaid' ? 'बाँकी · unpaid' : $m) ?> · <?= (int) $x['n'] ?> · <?= $e($rs($x['npr'])) ?></span><?php endforeach; ?>
        </p>
      <?php endif; ?>
      <?php if ($day['pending'] > 0): ?><p class="nc-pill lo" style="margin-top:8px">⏳ <?= (int) $day['pending'] ?> टिकटको पैसा बाँकी · payment pending</p><?php endif; ?>
      <?php if ($mayList): ?><p style="margin:10px 0 0"><a href="/admin/desk-day.php?desk=<?= $e($desk) ?>">📋 टिकट-टिकट सूची, कसले काट्यो →</a></p><?php endif; ?>
    </section>
    <?php endif; ?>
  </div>

  <section class="nc-card" id="ncRoad" style="margin-top:14px" aria-labelledby="ncRoadH">
    <h3 id="ncRoadH">🗺️ कार्यालयबाट रुपैडिहा बोर्डर पार्किङ (बस चढ्ने ठाउँ)</h3>
    <?php if ($shareText !== ''): ?>
      <a class="nc-wa" href="https://wa.me/?text=<?= $e(rawurlencode($shareText)) ?>" target="_blank" rel="noopener">💬 यात्रुलाई WhatsApp मा बाटो पठाउनुहोस्</a>
    <?php endif; ?>
    <?php if ($roadLm !== []): ?>
      <?= LocationMaps::css() ?><?= LocationMaps::html($roadLm, 'ne') ?>
    <?php else: ?>
      <p class="muted">नक्सा अझै बनेको छैन — <a href="/admin/locations.php">📍 Our Locations</a> मा “Make all satellite maps” थिच्नुहोस्।</p>
    <?php endif; ?>
  </section>
<?php endif; ?>
</div>
<script>
(function () {
  var RATE = <?= json_encode($rate) ?>, NOTES = <?= json_encode(array_values(array_map('floatval', $denoms))) ?>;
  function num(v) { v = String(v || '').replace(/[^\d.]/g, ''); return v === '' ? NaN : parseFloat(v); }
  function rs(n) { return 'रू ' + Math.round(n).toLocaleString('en-IN'); }
  var np = document.getElementById('ncNpr'), ir = document.getElementById('ncInr');
  var bill = document.getElementById('ncBill'), got = document.getElementById('ncGot');
  var out = document.getElementById('ncChange'), notes = document.getElementById('ncNotes');
  if (np && ir) {
    np.addEventListener('input', function () { var n = num(np.value); ir.value = isNaN(n) ? '' : (Math.round(n / RATE * 100) / 100); });
    ir.addEventListener('input', function () { var n = num(ir.value); np.value = isNaN(n) ? '' : Math.round(n * RATE); });
  }
  function change() {
    var b = num(bill.value), g = num(got.value);
    notes.innerHTML = '';
    if (isNaN(b) || isNaN(g)) { out.textContent = 'फिर्ता · Change: —'; return; }
    var c = Math.round(g - b);
    if (c < 0) { out.textContent = 'अझै लिनुपर्ने · Still to take: ' + rs(-c); return; }
    out.textContent = 'फिर्ता दिनुहोस् · Give back: ' + rs(c);
    var left = c, html = '';
    NOTES.slice().sort(function (a, b) { return b - a; }).forEach(function (d) {
      var k = Math.floor(left / d); if (k > 0) { html += '<span>' + d + ' × ' + k + '</span>'; left -= k * d; }
    });
    notes.innerHTML = html;
  }
  if (bill && got) { bill.addEventListener('input', change); got.addEventListener('input', change); }
  var clk = document.getElementById('ncClock');
  if (clk) setInterval(function () {
    var d = new Date(), h = d.getHours(), m = d.getMinutes();
    clk.textContent = ((h % 12) || 12) + ':' + (m < 10 ? '0' : '') + m + ' ' + (h < 12 ? 'AM' : 'PM');
  }, 15000);
})();
</script>
<?php admin_footer();
