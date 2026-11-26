<?php
/**
 * chalani-live.php?k=<key>[&d=YYYY-MM-DD] — the permanent live chalani link
 * (6 Nov 2026).
 *
 * Owner: "chalani system eutai permanent link jasto banaideu — har ek
 * details milayer dekhaune, jasle ni herna milne, login garnu naparne;
 * agent haru lai sharing ma use hunchha; admin le update garda update
 * bhaihalne."
 *
 * One secret link (OpsHub::publicKey) shows every departure of a date: the
 * bus, pickup places in order, each booking (lead name, head-count, seats,
 * drop, the agent who sold it) coloured by money — paid green, with the
 * agent amber, due red — and the seat picture. Phones are masked. The page
 * re-reads itself every 30 seconds, so a change in the office shows up on
 * every shared copy. The office switches the link off or changes the key on
 * Bus Control; then old links show nothing.
 */
declare(strict_types=1);
define('SHG_APP', true);
require __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/opshub.php';
require_once INCLUDE_PATH . '/chalanipng.php';   // 16 Nov 2026: ChalaniPng::payMode - the pay-at-boarding rule the office chalani uses

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');

$key = (string) ($_GET['k'] ?? '');
/* 26 Nov 2026 (fx1): the key is checked FIRST and the two cases are counted apart.
   One budget of 600 an hour per address covered both: the page re-reads itself every
   30 s (120 an hour per open copy, plus a hit per QR picture), so five phones behind
   one office Wi-Fi or one mobile-carrier address locked everybody out with a 429.
   A WRONG key is now held much tighter than before (60 an hour - guessing the key gets
   harder, not easier); a RIGHT key gets room for a whole office on one address. */
$keyOk = OpsHub::checkPublicKey($key);
if (!Security::rateLimit($keyOk ? 'chalani_live' : 'chalani_live_bad', Security::clientIp(), $keyOk ? 6000 : 60, 3600)) {
    http_response_code(429);
    exit('Too many requests — try again in a while.');
}
if (!$keyOk) {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Chalani</title>'
        . '<body style="font-family:system-ui;padding:40px;text-align:center"><h2>🔒 यो link बन्द छ · This link is off</h2><p>Office बाट नयाँ link माग्नुहोस्।</p></body>');
}

$e     = static fn($v): string => Security::e((string) ($v ?? ''));
$today = todayISO();

/* 16 Nov 2026 (owner: "redirect payment lina sab suvidha hunu paryo, free wala"):
   ?k=..&qr=<PNR> - the crew's 💳 QR to the passenger's own pay page (pay.php:
   UPI, UPI QR, eSewa, UTR claim - all free). READ-ONLY: it never marks anything
   paid (an online payment lands 'pending' for the office to verify) and there is
   no cash-confirm here. Only a pay-at-boarding booking still unpaid, travelling
   inside this link's own date window, gets a picture - not a QR service. */
if (isset($_GET['qr'])) {
    $qrPng = ChalaniPng::payQrPng((string) $_GET['qr'], null,
        date('Y-m-d', strtotime($today . ' -3 days')), date('Y-m-d', strtotime($today . ' +30 days')));
    if ($qrPng === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('बसमा तिर्न बाँकी छैन · Nothing is due on boarding for this booking.');
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . strlen($qrPng));
    echo $qrPng;
    exit;
}
$date  = (string) ($_GET['d'] ?? $today);
if (!Security::isValidDate($date) || $date < date('Y-m-d', strtotime($today . ' -3 days')) || $date > date('Y-m-d', strtotime($today . ' +30 days'))) {
    $date = $today;
}
/* 12 Nov 2026 (owner: "chalani ko link daily change garnu napros — link tei,
   file update bhaye bhayo"): the bare link (no &d=) is THE link to share. The
   buses run on alternate days, so on a day with no departure it opens the
   next day that has one instead of an empty page — same link, every day. */
$autoNext = '';
if (!isset($_GET['d'])) {
    try {
        $nx = (string) Database::scalar(
            "SELECT MIN(s.travel_date) FROM schedules s JOIN routes r ON r.id = s.route_id
              WHERE s.travel_date >= :a AND s.travel_date <= :b AND r.is_active = 1 AND s.status <> 'cancelled'",
            ['a' => $today, 'b' => date('Y-m-d', strtotime($today . ' +7 days'))], '');
        if ($nx !== '' && $nx !== $today && Security::isValidDate($nx)) {
            $autoNext = $nx;
            $date     = $nx;
        }
    } catch (Throwable $ex) {
        $autoNext = '';
    }
}
$company = Settings::getString('company_name', APP_NAME);

$deps = Database::fetchAll(
    "SELECT s.id AS sid, s.is_blocked, s.status, s.slot, s.total_seats,
            r.id AS route_id, r.from_city, r.to_city,
            COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
            COALESCE(s.coach_type_override, r.coach_type) AS coach_type,
            bu.bus_number, bu.bus_name, d.full_name AS driver_name, d.phone AS driver_phone
       FROM schedules s
       JOIN routes r      ON r.id = s.route_id
       LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id)
       LEFT JOIN drivers d ON d.id = s.driver_id
      WHERE s.travel_date = :d AND r.is_active = 1 AND s.status <> 'cancelled'
      ORDER BY COALESCE(s.dep_time_override, r.dep_time), s.slot, r.id",
    ['d' => $date]
);

$trips = [];
/* 16 Nov 2026 (owner: "chalani ko lagi bisesh payment bhayo ki nai"): the desk
   extras - extra luggage, lap infants - on each booking card. 0 without the columns. */
$xCols = '';
foreach (['luggage_pieces', 'luggage_fee', 'infant_count', 'infant_fee'] as $xc) {
    $xCols .= CounterDesk::hasColumn('bookings', $xc) ? ', b.' . $xc : ', 0 AS ' . $xc;
}
/* 19 Nov 2026 (NP-2 phase 2, u10 request): the desk the sale was cut at and the NPR
   frozen on it, so a booking sold at a Nepal window and paid on boarding says what the
   crew collects in that money - ChalaniPng::deskMoney(), the office chalani's own rule. */
$xCols .= CounterDesk::stampColumn() ? ', b.counter_code' : ", '' AS counter_code";
foreach (['fx_currency', 'fx_rate', 'fx_total'] as $fc) {
    $xCols .= CounterDesk::hasColumn('bookings', $fc) ? ', b.' . $fc : ', NULL AS ' . $fc;
}
/* "रू 4,480 (₹2,800)" for an NPR desk's sale, "₹2,800" for every other. */
$bkMoney = static function (float $inr, array $r): string {
    $desk = (string) ($r['counter_code'] ?? '');
    return $desk !== '' && ChalaniPng::deskCurrency($desk) !== null
        ? ChalaniPng::deskMoney($inr, $desk, $r)
        : '₹' . number_format($inr);
};
foreach ($deps as $dep) {
    $rows = Database::fetchAll(
        "SELECT bp.id AS pax_id, bp.seat_no, bp.full_name, bp.gender, b.id AS booking_id, b.pnr, b.booking_mode, b.contact_phone,
                b.is_cod, b.total_amount" . $xCols . ",
                bl.boarding_stop, bl.drop_stop,
                p.status AS pay_status, p.method AS pay_method, p.utr_number AS pay_utr, p.admin_note AS pay_note,
                ad.full_name AS agent_name, ad.phone AS agent_phone, ad.role AS agent_role, b.sold_by_admin_id, b.source
           FROM booking_passengers bp
           JOIN bookings b      ON b.id = bp.booking_id
           JOIN booking_legs bl ON bl.id = bp.leg_id
           LEFT JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
           LEFT JOIN admins ad  ON ad.id = b.sold_by_admin_id
          WHERE bl.schedule_id = :sid AND b.status IN ('confirmed','pending','completed')
          ORDER BY COALESCE((SELECT MIN(rs.sort_order) FROM route_stops rs WHERE rs.route_id = :rid AND rs.stop_type = 'boarding'
                              AND rs.stop_name <> '' AND bl.boarding_stop LIKE CONCAT(rs.stop_name, '%')), 9999),
                   bl.boarding_stop, b.id, LENGTH(bp.seat_no), bp.seat_no",
        ['sid' => (int) $dep['sid'], 'rid' => (int) $dep['route_id']]
    );
    if ((int) $dep['is_blocked'] === 1 && $rows === []) {
        continue;   // a departure the rotation has no bus for, and nobody on it
    }
    $trips[] = ['dep' => $dep, 'rows' => $rows];
}
$allIds = [];
foreach ($trips as $t) {
    foreach ($t['rows'] as $r) {
        $allIds[] = (int) $r['booking_id'];
    }
}
$ticks = OpsHub::collections($allIds);

/* 16 Nov 2026 (pay at boarding): what a booking still owes on THIS bus - the
   same split as the office chalani (admin/manifest.php): the fare less the desk
   extras over every passenger of the booking (both legs of a round trip), plus
   the extras' leg share once. */
$paxPerBooking = []; $legsPerBooking = [];
$idList = implode(',', array_values(array_unique(array_filter(array_map('intval', $allIds)))));   // ints from the DB
if ($idList !== '') {
    foreach (Database::fetchAll("SELECT booking_id, COUNT(*) c FROM booking_passengers WHERE booking_id IN ($idList) GROUP BY booking_id") as $pr) {
        $paxPerBooking[(int) $pr['booking_id']] = max(1, (int) $pr['c']);
    }
    foreach (Database::fetchAll("SELECT booking_id, COUNT(*) c FROM booking_legs WHERE booking_id IN ($idList) GROUP BY booking_id") as $lr) {
        $legsPerBooking[(int) $lr['booking_id']] = max(1, (int) $lr['c']);
    }
}
$extrasOf = static fn(array $r): float => round(max(0.0, (float) ($r['luggage_fee'] ?? 0)) + max(0.0, (float) ($r['infant_fee'] ?? 0)), 2);
$qrBase   = '?k=' . rawurlencode($key) . '&qr=';

$stopShort = static function (string $label): string {
    $s = (string) preg_replace('/\s*\[[^\]]*\]\s*/u', ' ', $label);
    $s = (string) preg_replace('/\s*@.*$/u', '', $s);
    $s = (string) preg_replace('/\s*\(.*?\)\s*/u', ' ', $s);
    $s = trim((string) preg_replace('/\s{2,}/u', ' ', $s), " \t·");
    return $s !== '' ? $s : trim($label);
};
$seller = static function (array $r) use ($company): string {
    if ((int) ($r['sold_by_admin_id'] ?? 0) > 0 && strtolower((string) ($r['agent_role'] ?? '')) === 'agent') {
        return trim((string) $r['agent_name']) . ((string) ($r['agent_phone'] ?? '') !== '' ? ' · ' . (string) $r['agent_phone'] : '');
    }
    return (int) ($r['sold_by_admin_id'] ?? 0) > 0 ? 'Office · ' . $company : 'Online';
};
?><!doctype html>
<html lang="ne"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Live Chalani · <?= $e(formatDate($date, 'j M')) ?> · <?= $e($company) ?></title>
<link rel="icon" type="image/png" href="/assets/img/favicon-32.png">
<style>
:root{--navy:#12264E;--blue:#2E5FA8;--orange:#F07C1F;--ink:#16233C;--mut:#5C6B85;--line:#DCE3EE;--bg:#F4F6FB;--card:#fff}
@media (prefers-color-scheme:dark){:root{--ink:#E8EEF8;--mut:#9AA8C0;--line:#2A3550;--bg:#0E1424;--card:#151D33}}
*{box-sizing:border-box}body{margin:0;font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Devanagari",sans-serif;background:var(--bg);color:var(--ink)}
header{background:linear-gradient(120deg,var(--navy),var(--blue));color:#fff;padding:14px 16px 12px}
header h1{margin:0;font-size:19px}header p{margin:4px 0 0;font-size:13px;opacity:.9}
.wrap{max-width:980px;margin:0 auto;padding:12px 16px 40px}
.days{display:flex;gap:6px;overflow-x:auto;padding:4px 0 10px}
.days a{flex:0 0 auto;text-decoration:none;color:var(--ink);background:var(--card);border:1px solid var(--line);border-radius:12px;padding:6px 12px;font-weight:700;font-size:13px;text-align:center}
.days a small{display:block;font-weight:600;color:var(--mut);font-size:11px}
.days a.on{background:var(--orange);border-color:var(--orange);color:#fff}.days a.on small{color:#fff}
.trip{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:14px;margin:0 0 16px}
.trip h2{margin:0;font-size:18px}.trip .sub{color:var(--mut);font-size:13px;margin:3px 0 8px}
.chips{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 10px}
.chips span{border-radius:99px;padding:4px 10px;font-size:12.5px;font-weight:800}
.c-paid{background:#16A34A;color:#fff}.c-agent{background:#F59E0B;color:#1F1300}.c-due{background:#DC2626;color:#fff}.c-n{background:var(--bg);border:1px solid var(--line)}
.place{margin:12px 0 4px;background:var(--navy);color:#fff;border-radius:10px;padding:7px 10px;font-weight:800;font-size:14px}
.bk{border:1px solid var(--line);border-left-width:6px;border-radius:12px;padding:8px 10px;margin:6px 0;display:grid;gap:2px}
.bk.paid{border-left-color:#16A34A;background:rgba(22,163,74,.06)}.bk.agent{border-left-color:#F59E0B;background:rgba(245,158,11,.08)}.bk.due{border-left-color:#DC2626;background:rgba(220,38,38,.06)}
.bk b{font-size:15px}.bk .row{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:13px;color:var(--mut)}
.bk .st{font-size:11.5px;font-weight:900;border-radius:99px;padding:1px 8px;justify-self:start}
.seats{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 0}
.deck{border:1px solid var(--line);border-radius:12px;padding:6px 8px}
.deck>b{display:block;font-size:12px;margin-bottom:4px}
.srow{display:flex;gap:6px;margin:3px 0}.sg{display:inline-flex;gap:3px}.ais{width:10px}
.sp{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:26px;padding:0 3px;border-radius:6px;font-style:normal;font-size:10.5px;font-weight:800;border:1.5px solid var(--line);background:var(--card);color:var(--mut)}
.sp.paid{background:#16A34A;border-color:#15803D;color:#fff}.sp.agent{background:#F59E0B;border-color:#B45309;color:#1F1300}.sp.due{background:#DC2626;border-color:#991B1B;color:#fff}
.leg{display:flex;flex-wrap:wrap;gap:10px;font-size:12px;color:var(--mut);margin:8px 0 0}
.leg i{display:inline-block;width:14px;height:12px;border-radius:3px;vertical-align:-2px;margin-right:4px}
.empty{background:var(--card);border:1px dashed var(--line);border-radius:14px;padding:24px;text-align:center;color:var(--mut)}
.foot{font-size:12px;color:var(--mut);text-align:center;margin-top:18px}
.tools{display:flex;gap:8px;justify-content:flex-end;margin:0 0 8px}
.tools button{border:1px solid var(--line);background:var(--card);color:var(--ink);border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer}
/* 16 Nov 2026: pay at boarding - the pay mark on each booking, the to-collect chip, the crew's 💳 QR */
.c-board{background:#FEF2F2;color:#B91C1C;border:1px solid #F6C9C9}
.pmrow{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
.pm{font-size:12px;font-weight:800;border-radius:99px;padding:1px 9px;border:1px solid transparent}
.pm-boarding,.pm-due{background:#FEF2F2;color:#B91C1C;border-color:#F6C9C9}
.pm-cash,.pm-online,.pm-paid{background:#ECFDF3;color:#15803D;border-color:#BBF0CF}
.pm-agent,.pm-pending{background:#FFF7E8;color:#B45309;border-color:#F5D9A8}
.qrb{min-height:34px;padding:0 12px;border-radius:9px;border:1px solid #1D4FA3;background:#EEF4FF;color:#1D4FA3;font:inherit;font-size:13px;font-weight:800;cursor:pointer}
dialog.pq{width:min(92vw,360px);max-width:calc(100vw - 32px);border:1px solid var(--line);border-radius:16px;padding:14px 16px 16px;background:var(--card);color:var(--ink)}
dialog.pq::backdrop{background:rgba(8,15,30,.55)}
.pq h3{margin:0 30px 4px 0;font-size:16px}
.pq .pq-x{position:absolute;top:8px;right:8px;width:36px;height:36px;border-radius:50%;border:1px solid var(--line);background:var(--card);color:var(--ink);font-size:16px;cursor:pointer}
.pq p{margin:4px 0;font-size:13px;color:var(--mut);overflow-wrap:anywhere}
.pq .pq-amt{font-size:22px;font-weight:900;color:#B91C1C;margin:2px 0 6px}
.pq .pq-img{background:#fff;border-radius:10px;padding:8px;display:flex;justify-content:center;margin:6px 0}
.pq .pq-img img{width:100%;max-width:240px;height:auto;aspect-ratio:1/1;image-rendering:pixelated}
.pq .pq-link{display:flex;gap:6px;margin-top:8px}
.pq .pq-link input{flex:1 1 auto;min-width:0;min-height:38px;border-radius:8px;border:1px solid var(--line);background:var(--bg);color:var(--ink);padding:0 8px;font-size:12px}
.pq .pq-link button,.pq .pq-link a{display:inline-flex;align-items:center;min-height:38px;padding:0 10px;border-radius:8px;border:1px solid var(--line);background:var(--card);color:var(--ink);font:inherit;font-size:12.5px;font-weight:700;text-decoration:none;cursor:pointer}
@media (prefers-color-scheme:dark){
  .c-board,.pm-boarding,.pm-due{background:rgba(220,38,38,.16);border-color:rgba(220,38,38,.4);color:#FCA5A5}
  .pm-cash,.pm-online,.pm-paid{background:rgba(22,163,74,.16);border-color:rgba(22,163,74,.4);color:#6EE7A0}
  .pm-agent,.pm-pending{background:rgba(245,158,11,.16);border-color:rgba(245,158,11,.4);color:#FBC56B}
  .qrb{background:rgba(59,130,246,.18);border-color:rgba(147,197,253,.5);color:#BFDBFE}
  .pq .pq-amt{color:#FCA5A5}
}
/* 26 Nov 2026 (fx1): this page is opened on phones - every control a finger can hit (44 px), the pay-link box at 16 px so the phone does not zoom on focus */
.days a{min-height:44px;display:flex;flex-direction:column;justify-content:center}
.tools button,.qrb,.pq .pq-link button,.pq .pq-link a{min-height:44px}
.pq .pq-x{width:44px;height:44px}
.pq h3{margin-right:44px}
.pq .pq-link input{min-height:44px;font-size:16px}
@media print{.days,.tools,.qrb{display:none}body{background:#fff}.trip{break-inside:avoid}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}
</style></head><body>
<header><h1>🚌 <?= $e($company) ?> — Live Chalani</h1>
<p><?= $e(formatDate($date, 'l, j F Y')) ?> · <span id="upd">updated <?= $e(date('g:i:s A')) ?></span> · हरेक ३० सेकेन्डमा आफैं update</p></header>
<div class="wrap">
  <nav class="days" aria-label="Date">
    <?php for ($i = -1; $i <= 6; $i++): $d = date('Y-m-d', strtotime($today . ' ' . ($i >= 0 ? '+' : '') . $i . ' days')); ?>
      <a class="<?= $d === $date ? 'on' : '' ?>" href="?k=<?= $e(rawurlencode($key)) ?>&amp;d=<?= $e($d) ?>"><?= $e(formatDate($d, 'j M')) ?><small><?= $i === 0 ? 'आज' : ($i === 1 ? 'भोलि' : ($i === -1 ? 'हिजो' : $e(formatDate($d, 'D')))) ?></small></a>
    <?php endfor; ?>
  </nav>
  <?php if ($autoNext !== ''): ?><p style="margin:8px 0;padding:8px 12px;border-radius:10px;background:#FEF3C7;color:#92400E;font-weight:700">आज बस छैन — अर्को बस <?= $e(formatDate($autoNext, 'l, j M')) ?> को चलानी देखाइएको छ · No bus today; showing the next one.</p><?php endif; ?>
  <div class="tools"><button type="button" onclick="location.reload()">🔄 Refresh</button><button type="button" onclick="window.print()">🖨 Print</button></div>

  <?php if ($trips === []): ?>
    <div class="empty">यो दिन बस छैन वा बुकिङ छैन · No bus / no booking on this date.</div>
  <?php endif; ?>

  <?php foreach ($trips as $t):
      $dep = $t['dep']; $rows = $t['rows'];
      $coach = (string) ($dep['coach_type'] ?? 'sleeper');
      $groups = []; $beds = []; $cnt = ['paid' => 0, 'agent' => 0, 'due' => 0]; $exN = 0;
      $pmB = []; $boardDue = 0.0; $boardDueN = 0;   // 16 Nov 2026: pay mode + ₹ due on boarding, per booking
      foreach ($rows as $r) {
          $bid = (int) $r['booking_id'];
          $st  = OpsHub::payState($r, $ticks[$bid] ?? null);
          $cnt[$st]++;
          if (!isset($pmB[$bid])) {
              $pmMode    = ChalaniPng::payMode($r, $st, $ticks[$bid] ?? null);   // cash or online: the office tick's mode, as on the ticket
              $pmB[$bid] = ['mode' => $pmMode, 'amt' => $pmMode === 'boarding' ? round($extrasOf($r) / ($legsPerBooking[$bid] ?? 1), 2) : 0.0,
                            'claim' => $pmMode === 'boarding' && ChalaniPng::payClaimSent($r)];   // a UTR sent - the office checks it
          }
          if ($pmB[$bid]['mode'] === 'boarding') {
              $pmB[$bid]['amt'] += round(max(0.0, (float) ($r['total_amount'] ?? 0) - $extrasOf($r)) / ($paxPerBooking[$bid] ?? 1));
              $boardDueN++;
          }
          if (Seats::isExtraSeat((string) $r['seat_no'])) { $exN++; }
          $place = $r['boarding_stop'] ? $stopShort((string) $r['boarding_stop']) : '—';
          if (!isset($groups[$place][$bid])) {
              $groups[$place][$bid] = ['lead' => (string) $r['full_name'], 'n' => 0, 'seats' => [], 'state' => $st, 'r' => $r];
          }
          $groups[$place][$bid]['n']++;
          $groups[$place][$bid]['seats'][] = Seats::displayLabel((string) $r['seat_no'], $coach, (string) (($r['booking_mode'] ?? '') ?: 'sharing'));
          try {
              foreach (Seats::physicalSeats((string) $r['seat_no'], (string) (($r['booking_mode'] ?? '') ?: 'sharing'), $coach) as $bed) { $beds[$bed] = $st; }
          } catch (Throwable $ex) {
              $beds[strtoupper((string) $r['seat_no'])] = $st;
          }
      }
      foreach ($pmB as $pmX) { if ($pmX['mode'] === 'boarding') { $boardDue += $pmX['amt']; } }
      /* 26 Nov 2026 (fx1): the place NUMBER is the office chalani's - the place's
         position in the route's own pickup list (Admin -> Stops), the same matching
         rule admin/manifest.php uses - so the paper in the driver's hand and this
         page say the same number. It used to count 1, 2, 3 over the places that
         happened to have passengers: Kamrej was "6" on paper and "1" here. */
      try { $routeStops = Boarding::allStopsFor((int) $dep['route_id'], 'boarding'); } catch (Throwable $ex) { $routeStops = []; }
      $placeNo = [];
      $nextNo  = count($routeStops);
      foreach (array_keys($groups) as $pk) {
          $s  = mb_strtolower(trim((string) $pk));
          $pn = 0;
          foreach (array_values($routeStops) as $si => $st) {
              $n = mb_strtolower(trim((string) ($st['name'] ?? '')));
              if ($n !== '' && ($s === $n || str_starts_with($s, $n) || str_starts_with($n, $s))) { $pn = $si + 1; break; }
          }
          $placeNo[$pk] = $pn;
      }
      foreach ($placeNo as $pk => $pn) { if ($pn === 0) { $placeNo[$pk] = ++$nextNo; } }   // a place not on the route list: after the numbered ones, as first seen
      uksort($groups, static fn($a, $b): int => $placeNo[$a] <=> $placeNo[$b]);
      $total = (int) ($dep['total_seats'] ?: 72);
      /* 16 Nov 2026 (owner: "chalani ma seat ma sabai ma thik hos"): free = berths not
         taken — a private double cabin is one passenger on two berths */
      $bedsTaken = count(array_filter(array_keys($beds), static fn($b): bool => !Seats::isExtraSeat((string) $b)));
      $infN = 0;
      foreach ($groups as $bksX) { foreach ($bksX as $gX) { $infN += max(0, (int) ($gX['r']['infant_count'] ?? 0)); } }
      $plate = Fleet::publicNumber((string) $dep['bus_number']);
      $layout = Seats::layoutFor($coach, 'sharing'); ?>
    <section class="trip">
      <h2><?= $e($dep['from_city']) ?> → <?= $e($dep['to_city']) ?> · <?= $e(date('g:i A', strtotime((string) $dep['dep_time']))) ?></h2>
      <div class="sub">🚌 <?= $e(trim((string) $dep['bus_name'] . ' ' . $plate)) ?: 'Bus' ?><?= !empty($dep['driver_name']) ? ' · 👤 ' . $e($dep['driver_name']) . (!empty($dep['driver_phone']) ? ' ' . $e($dep['driver_phone']) : '') : '' ?></div>
      <div class="chips">
        <span class="c-n">👥 <?= count($rows) ?> यात्रु<?= $infN > 0 ? ' + 👶 ' . $infN . ' शिशु' : '' ?></span>
        <span class="c-n">💺 <?= max(0, $total - $bedsTaken) ?> खाली / <?= $total ?></span>
        <?php if ($exN > 0): ?><span class="c-n">➕ Extra <?= $exN ?></span><?php endif; ?>
        <span class="c-paid">✅ Paid <?= $cnt['paid'] ?></span><span class="c-agent">🟠 Agent <?= $cnt['agent'] ?></span><span class="c-due">🔴 Due <?= $cnt['due'] ?></span>
        <span class="<?= $boardDue > 0 ? 'c-board' : 'c-n' ?>">🚌 बसमा उठाउनु पर्ने · To collect on boarding: <?= (int) $boardDueN ?> जना · ₹<?= $e(number_format($boardDue)) ?></span>
      </div>
      <?php foreach ($groups as $place => $bks): $paxHere = array_sum(array_map(static fn(array $g): int => $g['n'], $bks)); ?>
        <div class="place">📍 <?= (int) ($placeNo[$place] ?? 0) ?> · <?= $e($place) ?> · <?= $paxHere ?> यात्रु</div>
        <?php foreach ($bks as $g): [$lbl, $cls] = OpsHub::payLabel($g['state']); $r = $g['r']; ?>
          <div class="bk <?= $e($g['state']) ?>">
            <b><?= $e($g['lead']) ?><?= $g['n'] > 1 ? ' + ' . ($g['n'] - 1) : '' ?> · 💺 <?= $e(implode(', ', $g['seats'])) ?></b>
            <div class="row"><span>➡️ <?= $e($stopShort((string) ($r['drop_stop'] ?: $dep['to_city']))) ?></span><span>📱 <?= $e(OpsHub::maskPhone((string) $r['contact_phone'])) ?></span><span>🧑‍💼 <?= $e($seller($r)) ?></span><span><?= $e($r['pnr']) ?></span></div>
            <?php $xLug = max(0, (int) ($r['luggage_pieces'] ?? 0)); $xInf = max(0, (int) ($r['infant_count'] ?? 0));
                  $xAmt = max(0.0, (float) ($r['luggage_fee'] ?? 0)) + max(0.0, (float) ($r['infant_fee'] ?? 0));
                  if ($xLug > 0 || $xInf > 0): ?>
            <?php $xSt = $xAmt > 0 ? (string) $g['state'] : 'free';   // a ₹0 line (free lap infant) owes nothing ?>
            <div class="row"><span style="font-weight:800;color:<?= $xSt === 'due' ? '#B91C1C' : ($xSt === 'free' ? 'inherit' : '#15803D') ?>">विशेष भुक्तानी · <?= $xLug > 0 ? '🧳 ' . $xLug . ' थप सामान ' : '' ?><?= $xInf > 0 ? '👶 ' . $xInf . ' शिशु ' : '' ?><?= $xAmt > 0 ? '₹' . $e(number_format($xAmt)) . ' ' : '' ?>— <?= $xSt === 'due' ? '✗ बाँकी' : ($xSt === 'free' ? 'निःशुल्क' : '✓ भयो') ?></span></div>
            <?php endif; ?>
            <?php $gPm = $pmB[(int) $r['booking_id']] ?? ['mode' => 'due', 'amt' => 0.0]; ?>
            <div class="pmrow"><span class="st <?= $e($cls) ?>"><?= $e($lbl) ?></span>
              <span class="pm pm-<?= $e($gPm['mode']) ?>"><?= $e(ChalaniPng::payModeLabel($gPm['mode'], 'ne') . ($gPm['mode'] === 'boarding' && $gPm['amt'] > 0 ? ' ' . $bkMoney((float) $gPm['amt'], $r) : '')) ?></span>
              <?php if (!empty($gPm['claim'])): ?><span class="pm pm-pending"><?= $e(ChalaniPng::payClaimLabel('ne')) ?></span><?php endif; ?>
              <?php if ($gPm['mode'] === 'boarding'): ?><button type="button" class="qrb" data-payqr data-pnr="<?= $e($r['pnr']) ?>" data-name="<?= $e($g['lead']) ?>"
                data-amt="<?= $e($bkMoney((float) $gPm['amt'], $r)) ?>" data-link="<?= $e(ChalaniPng::payLink((string) $r['pnr'])) ?>" data-qr="<?= $e($qrBase . rawurlencode((string) $r['pnr'])) ?>"
                title="यात्रुलाई अनलाइन तिर्ने QR देखाउनुहोस् · Show the passenger a QR to pay online (free)">💳 QR</button><?php endif; ?></div>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <div class="seats">
        <?php foreach ((array) ($layout['decks'] ?? []) as $dk): ?>
          <div class="deck"><b><?= $dk['key'] === 'U' ? '🔼' : '🔽' ?> <?= $e($dk['label']) ?></b>
          <?php foreach ((array) $dk['rows'] as $rw): [$g1, $g2] = Seats::rowGroups($rw, $layout); ?>
            <div class="srow"><span class="sg"><?php foreach ($g1[0] as $bed): ?><i class="sp <?= $e($beds[$bed] ?? '') ?>"><?= $e(Seats::displayLabel((string) $bed, $coach, 'sharing')) ?></i><?php endforeach; ?></span><span class="ais"></span><span class="sg"><?php foreach ($g2[0] as $bed): ?><i class="sp <?= $e($beds[$bed] ?? '') ?>"><?= $e(Seats::displayLabel((string) $bed, $coach, 'sharing')) ?></i><?php endforeach; ?></span></div>
          <?php endforeach; ?></div>
        <?php endforeach; ?>
        <?php $exB = array_filter(array_keys($beds), static fn($b): bool => Seats::isExtraSeat((string) $b)); if ($exB !== []): sort($exB, SORT_NATURAL); ?>
          <div class="deck"><b>💺 EXTRA</b><div class="srow" style="flex-wrap:wrap;max-width:360px"><?php foreach ($exB as $bed): ?><i class="sp <?= $e($beds[$bed]) ?>"><?= $e($bed) ?></i><?php endforeach; ?></div></div>
        <?php endif; ?>
      </div>
      <div class="leg"><span><i style="background:#16A34A"></i>भुक्तानी भयो · Paid</span><span><i style="background:#F59E0B"></i>एजेन्टसँग · With agent</span><span><i style="background:#DC2626"></i>बाँकी · Due</span><span><i style="border:1px solid #ccc"></i>खाली · Free</span></div>
    </section>
  <?php endforeach; ?>
  <p class="foot">© <?= $e($company) ?> · यो link गोप्य राख्नुहोस् · Mobile number लुकेको · Office ले बदलेको तुरुन्त यहाँ देखिन्छ</p>
</div>
<?php /* 16 Nov 2026: the 💳 QR card - shows the passenger's pay page as a QR. Nothing here marks a payment. */ ?>
<dialog class="pq" id="lvPayQr" aria-labelledby="lvPayQrH">
  <form method="dialog" style="margin:0"><button class="pq-x" type="submit" aria-label="Close · बन्द">✕</button></form>
  <h3 id="lvPayQrH">💳 बसमा भुक्तानी · Pay on boarding</h3>
  <p><b data-f="name"></b> · <span data-f="pnr"></span></p>
  <div class="pq-amt" data-f="amt"></div>
  <div class="pq-img"><img alt="Pay QR" width="240" height="240" data-f="img"></div>
  <p>यात्रुले फोनको UPI / eSewa एपले स्क्यान गरेर निःशुल्क तिर्नुहोस् · The passenger scans with any UPI / eSewa app and pays free.</p>
  <p>कार्यालयले जाँचेपछि मात्र "भुक्तानी" हुन्छ · It counts as paid only after the office checks it.</p>
  <div class="pq-link"><input readonly data-f="link" aria-label="Pay link" onclick="this.select()"><button type="button" data-f="copy">Copy</button><a data-f="open" target="_blank" rel="noopener">Open</a></div>
</dialog>
<script>
(function () {
  var d = document.getElementById('lvPayQr');
  var f = function (k) { return d ? d.querySelector('[data-f="' + k + '"]') : null; };
  document.addEventListener('click', function (ev) {
    var b = ev.target && ev.target.closest ? ev.target.closest('[data-payqr]') : null;
    if (!b || !d) { return; }
    ev.preventDefault();
    var link = b.getAttribute('data-link') || '';
    if (typeof d.showModal !== 'function') { window.open(link, '_blank', 'noopener'); return; }
    f('name').textContent = b.getAttribute('data-name') || '';
    f('pnr').textContent = b.getAttribute('data-pnr') || '';
    f('amt').textContent = b.getAttribute('data-amt') || '';
    f('img').src = b.getAttribute('data-qr') || '';
    f('link').value = link;
    f('open').href = link;
    d.showModal();
  });
  if (d) {
    f('copy').addEventListener('click', function () {
      var v = f('link').value, btn = this;
      var done = function () { btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = 'Copy'; }, 1500); };
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(v).then(done, function () { f('link').select(); }); }
      else { f('link').select(); try { document.execCommand('copy'); done(); } catch (e) {} }
    });
  }
  /* the page re-reads itself every 30 s - but never while the QR card is open
     in front of a passenger who is scanning it.
     26 Nov 2026 (fx1): and only while somebody is LOOKING at it. A copy left open in
     a background tab or on a locked phone kept loading the whole bus twice a minute
     for nobody; it now waits, and re-reads at once when the tab comes back (if 30 s
     have passed), so what the reader sees is as fresh as before. */
  var due = Date.now() + 30000;
  var reread = function () {
    if ((d && d.open) || document.visibilityState === 'hidden' || Date.now() < due) { return; }
    try { sessionStorage.setItem('chl_y', String(window.scrollY)); } catch (e) {}
    location.reload();
  };
  setInterval(reread, 5000);
  document.addEventListener('visibilitychange', reread);
})();
try { var y = sessionStorage.getItem('chl_y'); if (y) { window.scrollTo(0, parseInt(y, 10) || 0); sessionStorage.removeItem('chl_y'); } } catch (e) {}
</script>
</body></html>
