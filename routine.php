<?php
/**
 * =====================================================================
 *  routine.php — the live bus routine (public). बस रुटिन
 *
 *  The owner's poster, made live (31 Oct 2026): three coaches on a 6-day
 *  turnaround, exactly one leaving a terminal each day. A passenger sees
 *  today's bus, the next bus each way (India → Nepal / Nepal → India) with a
 *  "Book seat" button that opens that exact day's bus in the booking app, the
 *  next 14 days, and each line's pickup towns.
 *
 *  Built from the bus rotation (Fleet, Admin → Trips) that search, seat map and booking use,
 *  so the page can never promise a bus the booking app will not sell.
 *  Stand-alone (no app bundle), refreshes itself every 5 minutes.
 * ===================================================================== */

declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/busroutine.php';
require_once INCLUDE_PATH . '/fare.php';

Security::requireRateLimit('routine_page', Security::clientIp(), 60, 60);

$today  = todayISO();
$on     = BusRoutine::enabled();
$days   = BusRoutine::departures($today, 30);
// 26 Nov 2026 (fx2): the fare shown is the fare board's range (the numbers the checkout bills per
// pickup / drop), not the flat direction setting - "Book seat" opens Surat, which can cost more.
try { $fareRange = Fare::board()['range']; } catch (Throwable $ex) { $fareRange = null; }
$lines  = BusRoutine::lines();
$pos    = BusRoutine::positions($today);
$todays = array_values(array_filter($days, static fn(array $d): bool => $d['date'] === $today));

$nextIn = null; $nextNp = null;
foreach ($days as $d) {
    if ($d['direction'] === BusRoutine::DIR_TO_NEPAL && $nextIn === null) { $nextIn = $d; }
    if ($d['direction'] === BusRoutine::DIR_TO_INDIA && $nextNp === null) { $nextNp = $d; }
}

// Pickup towns (going up) for each line, in the order the coach calls.
$stops = [];
foreach ($lines as $l) {
    if ((int) $l['outRouteId'] <= 0) { continue; }
    $stops[$l['code']] = Database::fetchAll(
        "SELECT stop_name, stop_time FROM route_stops
          WHERE route_id = :r AND stop_type = 'boarding'
          ORDER BY sort_order, id",
        ['r' => (int) $l['outRouteId']]
    );
}

$phone = Settings::getString('support_phone', '9104801507');
$e     = static fn(?string $s): string => Security::e((string) $s);
$bookUrl = static function (array $d): string {
    // The booking form searches the corridor as Surat ⇄ Rupaidiha; search
    // brings that day's routine bus (Rajkot's BUS 03 included) with its own pickups.
    $toNepal = $d['direction'] === BusRoutine::DIR_TO_NEPAL;
    return '/?' . http_build_query(['from' => $toNepal ? 'Surat' : 'Rupaidiha', 'to' => $toNepal ? 'Rupaidiha' : 'Surat', 'date' => $d['date']]) . '#/';
};
$town = static fn(string $n): string => trim(preg_split('/\s[-–—·@]\s|\s@/u', $n)[0] ?? $n);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="refresh" content="300">
<title>Bus Routine · S Hari Global</title>
<meta name="description" content="Live bus routine — 3 buses, one departure a day between Gujarat and Rupaidiha (Nepal border).">
<link rel="icon" href="/favicon.ico">
<style>
:root{--navy:#0E2A52;--blue:#0B5FA5;--orange:#B4541A;--gold:#E8A317;--ink:#0F172A;--mut:#64748B;--line:#E2E8F0;--bg:#F1F5FB;--card:#fff}
*{box-sizing:border-box}
body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Devanagari",sans-serif;background:var(--bg);color:var(--ink);line-height:1.45}
a{color:inherit}
.wrap{max-width:1040px;margin:0 auto;padding:0 16px}
header{background:var(--navy);color:#fff;padding:16px 0}
header .wrap{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.brand{font-weight:900;font-size:20px;letter-spacing:.02em;text-decoration:none}
.brand small{display:block;font-weight:600;font-size:12px;opacity:.75;letter-spacing:.06em}
.call{background:var(--gold);color:var(--navy);padding:9px 14px;border-radius:999px;font-weight:800;text-decoration:none;white-space:nowrap}
h1{font-size:24px;margin:22px 0 4px;color:var(--navy)}
.sub{color:var(--mut);margin:0 0 16px}
.live{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:#178A50;background:#E7F6EE;border-radius:999px;padding:3px 10px;margin-left:8px;vertical-align:middle}
.live::before{content:"";width:8px;height:8px;border-radius:50%;background:#178A50;animation:p 1.4s infinite}
@keyframes p{50%{opacity:.25}}
@media (prefers-reduced-motion:reduce){.live::before{animation:none}}
.hero{display:grid;grid-template-columns:150px 1fr;gap:14px;margin-bottom:16px}
.day{background:var(--card);border:2px solid var(--line);border-radius:16px;padding:14px;text-align:center}
.day b{display:block;font-size:44px;line-height:1.05;color:var(--navy)}
.day span{font-size:13px;color:var(--mut)}
.today{border-radius:16px;padding:18px;color:#fff;background:linear-gradient(135deg,var(--navy),var(--blue))}
.today.np{background:linear-gradient(135deg,#6B2A0B,var(--orange))}
.today.none{background:linear-gradient(135deg,#475569,#64748B)}
.today .k{font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;opacity:.85}
.today .l{font-size:26px;font-weight:900;margin:2px 0}
.today .s{font-size:15px}
.btn{display:inline-block;margin-top:10px;background:#fff;color:var(--navy);font-weight:900;padding:10px 16px;border-radius:10px;text-decoration:none;min-height:44px}
.btn:focus-visible,.call:focus-visible{outline:3px solid var(--gold);outline-offset:2px}
.dirs{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
.dir{background:var(--card);border-radius:14px;padding:16px;border-top:6px solid var(--blue);box-shadow:0 1px 3px rgba(15,23,42,.06)}
.dir.np{border-top-color:var(--orange)}
.dir h2{margin:0 0 6px;font-size:17px}
.dir .when{font-size:20px;font-weight:900;color:var(--navy)}
.dir .meta{color:var(--mut);font-size:14px;margin:4px 0}
.dir .fare{font-weight:900;font-size:18px}
.dir .btn{background:var(--blue);color:#fff}
.dir.np .btn{background:var(--orange)}
.card{background:var(--card);border-radius:14px;padding:4px 0 8px;margin-bottom:18px;box-shadow:0 1px 3px rgba(15,23,42,.06)}
.card h2{font-size:17px;margin:12px 16px 8px;color:var(--navy)}
.tbl{width:100%;border-collapse:collapse}
.tbl th,.tbl td{padding:9px 12px;border-bottom:1px solid var(--line);text-align:left;font-size:14px}
.tbl th{font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.05em}
.tbl tr.t td{background:#FFF7E0;font-weight:800}
.chip{display:inline-block;padding:3px 9px;border-radius:999px;color:#fff;font-size:12px;font-weight:800;white-space:nowrap;background:var(--blue)}
.chip.np{background:var(--orange)}
.none{color:var(--mut)}
.mini{font-size:13px;font-weight:800;text-decoration:none;color:var(--blue);white-space:nowrap}
.pos{display:flex;flex-wrap:wrap;gap:8px;padding:0 16px 10px}
.pos span{font-size:13px;font-weight:700;border-radius:999px;padding:6px 12px;background:#E8EEF8}
.lines{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px}
.line{background:var(--card);border-radius:14px;padding:16px;box-shadow:0 1px 3px rgba(15,23,42,.06)}
.line h3{margin:0;font-size:19px;color:var(--navy)}
.line .no{font-size:13px;color:var(--mut);margin-bottom:8px}
.line ol{margin:6px 0 10px;padding-left:20px;font-size:14px}
.line ol li span{color:var(--mut);font-size:12px}
.dn{font-size:13px;line-height:1.9}
.dn i{font-style:normal;display:inline-block;min-width:26px;text-align:center;border-radius:6px;margin:1px;font-weight:800;background:#DBEAFE}
.dn i.r{background:#FFEDD5}
.how{font-size:14px;color:var(--mut);margin:0 0 30px}
footer{background:var(--navy);color:#cbd5e1;padding:16px 0;font-size:13px}
.wk{padding:0 12px 6px}
.wk-h,.wk-r{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1fr) minmax(0,1fr);gap:8px;align-items:stretch}
.wk-h{font-size:13px;font-weight:800;color:var(--mut);padding:4px 2px 8px}
.wk-r{padding:6px 0;border-top:1px solid var(--line)}
.wk-r.t{background:#FFF7E0;border-radius:12px;padding:6px}
.wk-d{display:flex;flex-direction:column;justify-content:center}
.wk-d b{font-size:20px;color:var(--navy)}
.wk-d small{font-size:13px;color:var(--mut)}
.wk-c{display:flex;flex-direction:column;justify-content:center;gap:1px;min-height:64px;padding:8px 10px;border-radius:12px;background:#E6F0FB;color:var(--navy);text-decoration:none;transition:transform .12s}
.wk-c.np{background:#FDEBDD;color:#6B2A0B}
.wk-c:active{transform:scale(.97)}
.wk-c b{font-size:20px}
.wk-c small{font-size:12.5px;opacity:.8}
.wk-c em{font-style:normal;font-size:13px;font-weight:800}
.wk-c.none{background:transparent;align-items:center}
.pk{list-style:none;margin:0;padding:0 16px 4px}
.pk li{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px solid var(--line);font-size:17px}
.pk li b{color:var(--navy);font-variant-numeric:tabular-nums}
.pk-n{padding:6px 16px 10px;margin:0;font-size:15px;font-weight:700;color:#6B2A0B}
.how{font-size:15px}
@media (max-width:760px){
  .hero{grid-template-columns:1fr}.dirs{grid-template-columns:1fr}.lines{grid-template-columns:1fr}
  .tbl .hide-s{display:none}h1{font-size:23px}
  .sub{font-size:15px}.dir .meta{font-size:15px}.today .s{font-size:16px}
}
</style>
</head>
<body>
<header><div class="wrap">
  <a class="brand" href="/">S HARI GLOBAL™<small>PRIVATE LIMITED · INDIA ⇄ NEPAL</small></a>
  <a class="call" href="tel:+91<?= $e(preg_replace('/\D/', '', $phone)) ?>">📞 <?= $e($phone) ?></a>
</div></header>

<main class="wrap">
  <h1>Bus Routine <span class="live">LIVE</span></h1>
  <p class="sub">3 buses · one departure every day · Gujarat ⇄ Rupaidiha (Nepal border). बस रुटिन — हरेक दिन एउटा बस।</p>

<?php if (!$on): ?>
  <div class="card"><h2>The routine is being updated</h2><p style="padding:0 16px">Please call <?= $e($phone) ?> for today's departure.</p></div>
<?php else: ?>
  <section class="hero" aria-label="Today">
    <div class="day"><span>आज · Today</span><b><?= $e(formatDate($today, 'j')) ?></b><span><?= $e(formatDate($today, 'D, M Y')) ?></span></div>
    <?php if ($todays === []): ?>
      <div class="today none">
        <div class="k">Today's bus</div>
        <div class="l">No departure today</div>
        <div class="s"><?= BusRoutine::dayIndex($today) < 0 ? 'Service starts ' . $e(formatDate(BusRoutine::anchor(), 'l, j F')) . ' (Day 1).' : 'The next buses are shown below.' ?></div>
      </div>
    <?php else: $t = $todays[0]; ?>
      <div class="today <?= $t['direction'] === BusRoutine::DIR_TO_INDIA ? 'np' : '' ?>">
        <div class="k">Today's bus · <?= $e($t['dirLabel']) ?></div>
        <div class="l"><?= $e($t['line']) ?><?= $t['busNumber'] !== '' ? ' · ' . $e($t['busNumber']) : '' ?></div>
        <div class="s"><?= $e($t['from']) ?> <?= $e($t['depTime']) ?> → <?= $e($t['to']) ?> · reaches <?= $e(formatDate($t['reachDate'], 'D j M')) ?></div>
        <a class="btn" href="<?= $e($bookUrl($t)) ?>">Book a seat →</a>
        <a class="btn" href="/navigate.html" style="background:rgba(255,255,255,.18);color:#fff;margin-left:6px">📍 Live bus · बस कहाँ छ</a>
      </div>
    <?php endif; ?>
  </section>

  <section class="dirs" aria-label="Next bus each way">
    <?php foreach ([[$nextIn, 'India → Nepal', 'Gujarat to Rupaidiha', ''], [$nextNp, 'Nepal → India', 'Rupaidiha to Gujarat', 'np']] as [$d, $title, $sub, $cls]): ?>
      <div class="dir <?= $cls ?>">
        <h2><?= $title ?> <span class="none" style="font-weight:600;font-size:13px">· <?= $sub ?></span></h2>
        <?php if ($d === null): ?>
          <p class="none">No departure in the next 30 days.</p>
        <?php else: ?>
          <div class="when"><?= $e(formatDate($d['date'], 'D, j M')) ?> · <?= $e($d['depTime']) ?></div>
          <div class="meta"><?= $e($d['line']) ?> · <?= $e($d['from']) ?> → <?= $e($d['to']) ?> · पुग्ने · reaches <?= $e(formatDate($d['reachDate'], 'D j M')) ?></div>
          <?php $goUp = $d['direction'] === BusRoutine::DIR_TO_NEPAL;
                $rg   = $fareRange[$goUp ? 'go' : 'back'] ?? null;
                $fMin = (float) ($rg['min'] ?? $d['fare']); $fMax = (float) ($rg['max'] ?? $d['fare']); ?>
          <div class="fare">₹<?= number_format($fMin) ?><?= $fMax > $fMin ? '–₹' . number_format($fMax) : '' ?> <span class="none" style="font-size:13px;font-weight:600">प्रति सिट · per seat<?= $fMax > $fMin ? ($goUp ? ' · चढ्ने ठाउँ अनुसार · by pickup town' : ' · ओर्लने ठाउँ अनुसार · by drop town') : '' ?></span></div>
          <a class="btn" href="<?= $e($bookUrl($d)) ?>">Book seat · <?= $title ?></a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <section class="card" aria-label="Where each bus is">
    <h2>Where each bus is today <a class="mini" href="/navigate.html" style="margin-left:8px">📍 Live map →</a></h2>
    <div class="pos"><?php foreach ($pos as $p): ?><span><?= $e($p['line']) ?> — <?= $e($p['label']) ?></span><?php endforeach; ?></div>
  </section>

  <?php /* 20 Nov 2026 (px3, owner: "schedule haru ajai bujhine gara"): one row a day, both ways side
     by side, each with its own Book button; then ONE pickup list (every bus calls at the same towns).
     The day numbers (Day 7, 01 04 07 ...) and the per-bus route lines are gone - they confused
     passengers; the rotation itself (BusRoutine) is unchanged. */
  $byDate = [];
  foreach ($days as $d) { $byDate[$d['date']][$d['direction']] = $d; } ?>
  <section class="card" aria-label="Next 7 days">
    <h2>अर्को ७ दिन · Next 7 days</h2>
    <div class="wk">
      <div class="wk-h"><span>मिति · Date</span><span>🇮🇳 → 🇳🇵</span><span>🇳🇵 → 🇮🇳</span></div>
      <?php for ($i = 0; $i < 7; $i++):
          $date = addDaysISO($today, $i);
          $go = $byDate[$date][BusRoutine::DIR_TO_NEPAL] ?? null;
          $bk = $byDate[$date][BusRoutine::DIR_TO_INDIA] ?? null; ?>
      <div class="wk-r<?= $date === $today ? ' t' : '' ?>">
        <span class="wk-d"><b><?= $e(formatDate($date, 'j M')) ?></b><small><?= $date === $today ? 'आज · Today' : ($i === 1 ? 'भोलि · Tomorrow' : $e(formatDate($date, 'l'))) ?></small></span>
        <?php foreach ([[$go, ''], [$bk, 'np']] as [$d, $cls]): ?>
          <?php if ($d === null): ?><span class="wk-c none">—</span>
          <?php else: ?><a class="wk-c <?= $cls ?>" href="<?= $e($bookUrl($d)) ?>"><b><?= $e($d['depTime']) ?></b><small><?= $e($town((string) $d['from'])) ?> · <?= $e($d['line']) ?></small><em>बुक · Book →</em></a><?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endfor; ?>
    </div>
  </section>

  <?php $firstStops = $stops !== [] ? reset($stops) : []; ?>
  <?php if ($firstStops !== []): ?>
  <section class="card" aria-label="Pickup times">
    <h2>चढ्ने ठाउँ र समय · Pickup towns and times (India → Nepal)</h2>
    <ol class="pk">
      <?php foreach ($firstStops as $s): ?>
        <li><span><?= $e($town((string) $s['stop_name'])) ?></span><b><?= $s['stop_time'] !== null ? $e(substr((string) $s['stop_time'], 0, 5)) : '—' ?></b></li>
      <?php endforeach; ?>
    </ol>
    <p class="pk-n">नेपाल → भारत: रुपैडिहाबाट बेलुका <?= $nextNp !== null ? $e($nextNp['depTime']) : '18:00' ?> · Nepal → India leaves Rupaidiha at <?= $nextNp !== null ? $e($nextNp['depTime']) : '18:00' ?>.</p>
  </section>
  <?php endif; ?>
  <p class="how">हरेक दिन एउटा बस जान्छ र एउटा फर्कन्छ। ३ वटा बस पालैपालो चल्छन् · One bus leaves each way every day; our 3 buses take turns.</p>
<?php endif; ?>
</main>

<footer><div class="wrap">S Hari Global Pvt. Ltd. · Near Silpa Garage, Silver Complex, Mehsana 384002 · <a href="/">Book tickets</a> · Updated <?= $e(date('j M, H:i')) ?></div></footer>
</body>
</html>
