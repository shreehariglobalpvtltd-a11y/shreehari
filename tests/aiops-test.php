<?php
/**
 * tests/aiops-test.php — S Hari AI word parser + OpsHub rules (6 Nov 2026).
 * Reads the local test DB (routes, stops, buses).
 *
 * 16 Nov 2026 (v2.0, unit U5): + "2,200" amounts, Mehsana spellings, Gujarati,
 * plates vs "do 2200", no widening on a non-pickup city, time / direction
 * filters, commission %, fare_delta, add_stop, seat_block, the shared command
 * path (propose / confirm / audits). Those parts make their own throwaway rows
 * (an OFF route "Zu5town…", an OFF stop "Zu5ville Depot - Gate 2", one seat
 * block on a far-future bus) and delete them again; settings are not touched.
 *
 * 16 Nov 2026 (Master v2, unit W3): + the v2.0 switches and number knobs by
 * sentence ("24 ghanta reminder on karo", "device limit agent 2"), the delay
 * words and the settings engine — parse only.
 *
 *    php -c .claude/php-dev.ini tests/aiops-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('SHG_APP', true);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/opshub.php';
require_once INCLUDE_PATH . '/aiops.php';
if (DB_NAME !== 'shari_test' && DB_NAME !== 'af1_test') { exit("refuse: not a test DB\n"); }

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; } else { $fail++; echo "FAIL: $what\n"; }
};
$first = static fn(string $s): array => AiOps::parseWords($s)[0] ?? [];
$routeIds = static function (string $from, string $to): array {
    $ids = array_map('intval', array_column(Database::fetchAll('SELECT id FROM routes WHERE from_city = :f AND to_city = :t', ['f' => $from, 't' => $to]), 'id'));
    sort($ids);
    return $ids;
};
$tomorrow = date('Y-m-d', strtotime(todayISO() . ' +1 day'));

/* ---- U5 fixtures: made BEFORE the first AiOps call (AiOps::routes() is cached per run) ---- */
$tag      = substr(str_shuffle('abcdefghjkmnpqrstvwxyz'), 0, 5);   // letters only: a digit run in the name would read as an amount
$u5Town   = 'Zu5town' . $tag;   // an OFF route's city that is no pickup anywhere
$startId  = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$u5       = ['route' => 0, 'stops' => [], 'blocks' => []];
$u5Clean  = static function () use (&$u5, $startId): void {
    foreach ($u5['blocks'] as [$sid, $seat]) {
        Database::delete('seat_blocks', 'schedule_id = :s AND seat_no = :n', ['s' => $sid, 'n' => $seat]);
    }
    foreach ($u5['stops'] as $sid) {
        Database::delete('route_stops', 'id = :i', ['i' => $sid]);
    }
    Database::delete('route_stops', "stop_name LIKE 'Zu5%'", []);
    if ($u5['route'] > 0) {
        Database::delete('routes', 'id = :i', ['i' => $u5['route']]);
    }
    // only this run's rows: every one names a Zu5 fixture or one of its blocked seats
    Database::delete('audit_logs', "id > :s AND action IN ('routes.stop_fare','settings.ai_proposed','settings.ai_declined','settings.ai_command','route_stop.add')
                                    AND (entity_id LIKE 'Zu5%' OR old_value LIKE '%Zu5%' OR new_value LIKE '%Zu5%' OR detail LIKE '%Zu5%')", ['s' => $startId]);
    foreach ($u5['blocks'] as [$sid, $seat]) {
        Database::delete('audit_logs', "id > :s AND action IN ('seat.block','seat.unblock') AND entity_id = :e", ['s' => $startId, 'e' => (string) $sid]);
    }
    $u5['blocks'] = $u5['stops'] = [];
    $u5['route'] = 0;
};
register_shutdown_function($u5Clean);
$u5['route'] = Database::insert('routes', ['route_code' => 'u5t' . $tag, 'from_city' => $u5Town, 'to_city' => 'Rupaidiha',
    'dep_time' => '13:00:00', 'arr_time' => '13:00:00', 'is_active' => 0, 'sort_order' => 999]);
$goRoute = Database::fetch("SELECT id, route_code FROM routes WHERE is_active = 1 AND to_city = 'Rupaidiha' ORDER BY sort_order, id LIMIT 1");
$fixStop = 0;
if ($goRoute !== null) {
    // OFF (is_active 0) so no customer or desk ever sees it; the AI fare rules still find it by name
    $fixStop = Database::insert('route_stops', ['route_id' => (int) $goRoute['id'], 'stop_type' => 'boarding', 'stop_name' => 'Zu5ville Depot - Gate 2',
        'fare' => 2150, 'is_active' => 0, 'sort_order' => 990]);
    $u5['stops'][] = $fixStop;
}
$hasMehsana = Database::exists("SELECT 1 FROM route_stops rs JOIN routes r ON r.id = rs.route_id
                                 WHERE r.is_active = 1 AND r.to_city = 'Rupaidiha' AND rs.stop_type = 'boarding' AND rs.stop_name LIKE 'Mehsana%'");
if (!$hasMehsana && $goRoute !== null) {
    $u5['stops'][] = Database::insert('route_stops', ['route_id' => (int) $goRoute['id'], 'stop_type' => 'boarding', 'stop_name' => 'Mehsana',
        'landmark' => 'Zu5 fixture', 'is_active' => 0, 'sort_order' => 991]);
}

/* dates */
$ok(AiOps::findDate(AiOps::norm('bholi ko bus band')) === $tomorrow, 'bholi = tomorrow');
$ok(AiOps::findDate(AiOps::norm('aaja')) === todayISO(), 'aaja = today');
$ok(substr((string) AiOps::findDate(AiOps::norm('9 nov ko bhada 2500')), 5) === '11-09', '9 nov');
$ok(substr((string) AiOps::findDate(AiOps::norm('nov 12 bus band')), 5) === '11-12', 'nov 12');
$ok(AiOps::findDate(AiOps::norm('२०२६-११-०९ ko bhada')) === '2026-11-09', 'Devanagari digits ISO');
$ok(AiOps::findDate(AiOps::norm('Surat ko bhada 2300')) === null, 'no date in a stop fare');

/* fares */
$a = $first('9 Nov ko bhada 2500');
$ok(($a['type'] ?? '') === 'trip_fare' && (float) $a['fare'] === 2500.0, 'date fare → trip_fare 2500');
$a = AiOps::parseWords('Surat ko bhada 2300');
$ok(($a[0]['type'] ?? '') === 'stop_fare' && ($a[0]['stop'] ?? '') === 'Surat' && (float) $a[0]['fare'] === 2300.0, 'Surat stop fare 2300');
$a = AiOps::parseWords('सुरत को भाडा २४००');
$ok(($a[0]['type'] ?? '') === 'stop_fare' && (float) ($a[0]['fare'] ?? 0) === 2400.0, 'Devanagari Surat fare 2400');
$a = AiOps::parseWords('sabai pickup ko bhada 2600');
$ok(($a[0]['type'] ?? '') === 'stop_fare' && ($a[0]['stop'] ?? '') === '*' && ($a[1]['type'] ?? '') === 'dir_fare', 'all pickups + default');
$a = $first('Rupaidiha Surat aaune bhada 1900');
$ok(($a['type'] ?? '') === 'dir_fare' && ($a['dir'] ?? '') === 'toIndia' && (float) $a['fare'] === 1900.0, 'return fare → toIndia');
$a = $first('bhada 5');
$ok($a === [], 'a silly fare is not a change');

/* routes */
$a = AiOps::parseWords('Rajkot route band');
$ids = array_merge($routeIds('Rajkot', 'Rupaidiha'), $routeIds('Rupaidiha', 'Rajkot'));
$ok(($a[0]['type'] ?? '') === 'route' && ($a[0]['on'] ?? true) === false && count(array_intersect($ids, $a[0]['route_ids'] ?? [])) === count($ids) && $ids !== [], 'Rajkot route off (both ways)');
$a = AiOps::parseWords('Rupaidiha Surat route on gara');
$got = (array) ($a[0]['route_ids'] ?? []); sort($got);
$ok(($a[0]['type'] ?? '') === 'route' && ($a[0]['on'] ?? false) === true && $got === $routeIds('Rupaidiha', 'Surat'), 'Rupaidiha→Surat on');
$a = AiOps::parseWords('Surat Rupaidiha matra chalau');
$keep = (array) ($a[0]['route_ids'] ?? []); sort($keep);
$ok(($a[0]['type'] ?? '') === 'route' && $a[0]['on'] === true && $keep === $routeIds('Surat', 'Rupaidiha')
    && ($a[1]['on'] ?? true) === false && !in_array($routeIds('Surat', 'Rupaidiha')[0], $a[1]['route_ids'] ?? [], true), 'only Surat→Rupaidiha');

/* a date's sale */
$a = $first('bholi ko bus band gara');
$ok(($a['type'] ?? '') === 'trip_sale' && $a['date'] === $tomorrow && $a['on'] === false, 'tomorrow sale off');

/* bus number / extra / fine */
$bus = Database::fetch('SELECT id, bus_name FROM buses ORDER BY id LIMIT 1');
$a = $first($bus['bus_name'] . ' ko number GJ05AB1234 rakha');
$ok(($a['type'] ?? '') === 'bus_number' && (int) $a['bus_id'] === (int) $bus['id'] && $a['value'] === 'GJ05AB1234', 'bus number by name');
$a = $first('10 extra seat');
$ok(($a['type'] ?? '') === 'extra_seats' && (int) $a['n'] === 10, '10 extra seats');
$a = $first('extra seat band');
$ok(($a['type'] ?? '') === 'extra_seats' && (int) $a['n'] === 0, 'extra seats off');
$a = $first('cancel fine 500');
$ok(($a['type'] ?? '') === 'setting' && $a['key'] === 'cancel_fine_default' && $a['value'] === '500', 'cancel fine 500');
$a = $first('जरिवाना बन्द');
$ok(($a['type'] ?? '') === 'setting' && $a['key'] === 'cancel_fine_on' && $a['value'] === '0', 'fine off (Nepali)');

/* AiOps::clean refuses unknown ids / keys */
$ok(AiOps::clean(['type' => 'route', 'route_ids' => [999999], 'on' => true]) === null, 'unknown route refused');
$ok(AiOps::clean(['type' => 'setting', 'key' => 'anthropic_api_key', 'value' => 'x']) === null, 'secret key refused');
$ok(AiOps::clean(['type' => 'trip_fare', 'date' => 'nope', 'fare' => 2000]) === null, 'bad date refused');
$ok(AiOps::clean(['type' => 'bus_number', 'bus_id' => (int) $bus['id'], 'value' => 'GJ 05 AB 1234'])['value'] === 'GJ05AB1234', 'plate cleaned');

/* preview shows old → new */
$pv = AiOps::preview(['type' => 'extra_seats', 'n' => 7]);
$ok(count($pv) === 1 && $pv[0][2] === '7', 'extra preview');
$pv = AiOps::preview(['type' => 'stop_fare', 'stop' => 'Surat', 'fare' => 2300.0]);
$ok($pv !== [] && str_contains($pv[0][2], '2,300'), 'stop fare preview');

/* ================= 16 Nov 2026 (v2.0, U5) ================= */
$note  = static fn(): string => AiOps::lastNote();
$isMeh = static fn(array $a): bool => ($a['type'] ?? '') === 'stop_fare' && mb_stripos((string) ($a['stop'] ?? ''), 'mehsana') === 0;

/* BUG1: one amount, Mehsana spellings */
$ok(str_contains(AiOps::norm('Mehsana price 2,200'), ' 2200 ') && str_contains(AiOps::norm('fare 1,00,000'), ' 100000 '), 'norm: 2,200 / 1,00,000 are one amount');
$ok(str_contains(AiOps::norm('seat 1,2'), ' 1 2 ') && str_contains(AiOps::norm('12,13'), '12 13'), 'norm: a list "1,2" / "12,13" still splits');
$a = $first('Mehsana price 2,200');
$ok($isMeh($a) && (float) $a['fare'] === 2200.0, 'BUG1 "Mehsana price 2,200" = ₹2,200 (was ₹200)');
$a = $first('Mehsana ₹2,200 rate');
$ok($isMeh($a) && (float) $a['fare'] === 2200.0, '₹2,200 = 2200');
foreach (['Mahesana fare 2200', 'मेहसाणा भाडा २२००', 'महेसाणा भाडा 2200', 'મહેસાણા ભાડું ૨૨૦૦'] as $s) {
    $a = $first($s);
    $ok($isMeh($a) && (float) ($a['fare'] ?? 0) === 2200.0, 'Mehsana spelling: ' . $s);
}
$a = $first('Surat ko bhada 2200/-');
$ok(($a['stop'] ?? '') === 'Surat' && (float) ($a['fare'] ?? 0) === 2200.0, '"2200/-" = 2200');
$a = $first('Surat ko bhada Rs.2300');
$ok(($a['stop'] ?? '') === 'Surat' && (float) ($a['fare'] ?? 0) === 2300.0, '"Rs.2300" = 2300');

/* P3-2a: plate shape, " - " in a stop name, no widening */
$a = $first('Mehsana ka price badha do 2200 kar do');
$ok($isMeh($a) && (float) $a['fare'] === 2200.0, 'SPEC "Mehsana ka price badha do 2200 kar do" → Mehsana ₹2,200');
$a = $first('Surat ka price badha do 2300 kar do');
$ok(($a['type'] ?? '') === 'stop_fare' && (float) ($a['fare'] ?? 0) === 2300.0, '"do 2300" is no plate');
$a = $first('Zu5ville Depot ko bhada 2400');
$ok(($a['type'] ?? '') === 'stop_fare' && ($a['stop'] ?? '') === 'Zu5ville Depot - Gate 2', 'stop name cut at " - "');
$ok(AiOps::parseWords($u5Town . ' ko bhada 2500') === [] && str_contains($note(), 'pickup'), 'a city that is no pickup → [] + "which pickup?" (no widening)');
$ok(AiOps::parseWords('jane bhada 2300') === [] && $note() !== '', '"jane bhada" alone → asked, not every pickup');
$ok(AiOps::parseWords('Vapi ko bhada 2500') === [] && $note() !== '', 'unknown place → asked');
$p = AiOps::parse('Vapi ko bhada 2500', true);
$ok($p['actions'] === [] && $p['via'] === 'words' && $p['note'] !== '', 'a pending question never goes to the AI');

/* P3-2b: time + direction */
$ft = AiOps::findTime(AiOps::norm('raat 10 baje'));
$ok(($ft['time'] ?? '') === '22:00' && $ft['alt'] === null, 'raat 10 baje = 22:00');
$ft = AiOps::findTime(AiOps::norm('subah 10 baje'));
$ok(($ft['time'] ?? '') === '10:00' && $ft['alt'] === null, 'subah 10 baje = 10:00');
$ft = AiOps::findTime(AiOps::norm('10 baje'));
$ok(($ft['time'] ?? '') === '10:00' && $ft['alt'] === '22:00', 'bare 10 baje = 10:00 or 22:00');
$ok((AiOps::findTime(AiOps::norm('22:30'))['time'] ?? '') === '22:30' && (AiOps::findTime(AiOps::norm('1:30 pm'))['time'] ?? '') === '13:30', '22:30 and 1:30 pm');
$ok((AiOps::findTime(AiOps::norm('સાંજે 6 વાગ્યે'))['time'] ?? '') === '18:00', 'Gujarati sanje 6 vagye = 18:00');
$ok(AiOps::findTime(AiOps::norm('Surat ko bhada 2300')) === null, 'no time in a fare');
$ok(AiOps::findDir(AiOps::norm('jane bus')) === 'toNepal' && AiOps::findDir(AiOps::norm('aaune bus')) === 'toIndia' && AiOps::findDir(AiOps::norm('bus')) === null, 'jane / aaune');
$near = static function (string $date, string $hm): bool {
    foreach (Database::fetchAll("SELECT COALESCE(s.dep_time_override, r.dep_time) dep FROM schedules s JOIN routes r ON r.id = s.route_id
                                  WHERE s.travel_date = :d AND r.is_active = 1 AND s.status <> 'cancelled'", ['d' => $date]) as $r) {
        $m = (int) substr((string) $r['dep'], 0, 2) * 60 + (int) substr((string) $r['dep'], 3, 2);
        if (abs($m - ((int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2))) <= 60) {
            return true;
        }
    }
    return false;
};
$a = AiOps::parseWords('Kal 10 baje bus band karo');
if ($near($tomorrow, '10:00') || $near($tomorrow, '22:00')) {
    $ok(($a[0]['type'] ?? '') === 'trip_sale' && ($a[0]['time'] ?? '') === '10:00', 'SPEC "Kal 10 baje bus band karo" → that bus only');
} else {
    $ok($a === [] && str_contains($note(), '10:00'), 'SPEC "Kal 10 baje bus band karo" → no bus at 10 → asked, never every bus');
}
$a = $first('kal 1 baje bus band');
$ok(($a['type'] ?? '') === 'trip_sale' && $a['time'] === '01:00' && $a['alt'] === '13:00' || ($a === [] && !$near($tomorrow, '13:00')), '"1 baje" = 01:00 / 13:00');
$rowsOk = true;
foreach ($a !== [] ? AiOps::preview($a) : [] as $r) {
    if (preg_match('/(\d\d):(\d\d)$/', $r[0], $mm) !== 1 || abs((int) $mm[1] * 60 + (int) $mm[2] - 780) > 60) { $rowsOk = false; }
}
$ok($rowsOk, 'the 1 baje preview lists only the ~13:00 buses');
$a = $first('kal 6 baje aaune bus band');
$ok(($a === [] && !$near($tomorrow, '18:00')) || (($a['dir'] ?? '') === 'toIndia' && ($a['alt'] ?? '') === '18:00'), 'aaune + 6 baje → toIndia, 18:00');
$rid = (int) ($goRoute['id'] ?? 0);
$c = AiOps::clean(['type' => 'trip_sale', 'date' => $tomorrow, 'on' => false, 'route_ids' => [$rid], 'time' => '13:00', 'dir' => 'toNepal']);
$ok(($c['route_ids'] ?? []) === [$rid] && ($c['time'] ?? '') === '13:00' && ($c['dir'] ?? '') === 'toNepal', 'clean() keeps route_ids / time / dir (apply used to drop them)');
$ok(AiOps::clean(['type' => 'trip_sale', 'date' => $tomorrow, 'on' => false, 'route_ids' => [999999]]) === null, 'route filter matching nothing is refused, never "every bus"');
$ok(AiOps::clean(['type' => 'trip_sale', 'date' => $tomorrow, 'on' => false, 'time' => '25:00']) === null, 'bad time refused');

/* P3-2c: commission % */
$ok(AiOps::parseWords('Agent Ramesh ko commission 10% kar do') === [] && str_contains($note(), '₹'), 'SPEC "commission 10%" → refused: ₹ per seat');
$ok(AiOps::parseWords('SHG-0001 commission 10 percent') === [], '"10 percent" refused too');
$a = $first('agent_commission_percent 6');
$ok(($a['type'] ?? '') !== 'commission', 'a snake_case key goes to the setting rule, not commission');

/* P3-2d: fare_delta */
$a = AiOps::parseWords('sab route ka fare 100 badha do');
$ok(count($a) === 1 && $a[0]['type'] === 'fare_delta' && $a[0]['stop'] === '*' && (float) $a[0]['delta'] === 100.0 && $a[0]['dir'] === 'both', 'SPEC "sab route ka fare 100 badha do" → +₹100 every fare');
$a = $first('Surat ka fare 100 kam karo');
$ok(($a['type'] ?? '') === 'fare_delta' && $a['stop'] === 'Surat' && (float) $a['delta'] === -100.0, '"100 kam" = −100 (was a ₹100 fare)');
$a = $first('Surat fare +100');
$ok(($a['type'] ?? '') === 'fare_delta' && (float) $a['delta'] === 100.0, '"+100"');
$a = $first('increase all fares by 100');
$ok(($a['type'] ?? '') === 'fare_delta' && $a['stop'] === '*' && (float) $a['delta'] === 100.0, '"increase all fares by 100"');
$a = $first('Surat ko bhada 100 le badhau');
$ok(($a['type'] ?? '') === 'fare_delta' && (float) $a['delta'] === 100.0, '"100 le badhau" (Nepali)');
$ok(AiOps::parseWords('Surat ko bhada badha 2300') === [] && $note() !== '', 'relative word + untied number → asked');
$a = $first('2000 se 2200 Surat ko bhada kar do');
$ok(($a['type'] ?? '') === 'stop_fare' && (float) $a['fare'] === 2200.0, '"2000 se 2200" = set 2200');
$ok(AiOps::parseWords('route ka fare 100 ghata do') === [] && $note() !== '', 'a delta with no pickup and no "all" → asked');
$a = $first('sabai pickup ko bhada 100 thap');
$ok(($a['type'] ?? '') === 'fare_delta' && $a['stop'] === '*' && (float) $a['delta'] === 100.0, '"pickup … 100 thap" is a fare +100, not a new pickup');
$pv = AiOps::preview(['type' => 'fare_delta', 'stop' => 'Zu5ville Depot - Gate 2', 'delta' => 100.0, 'dir' => 'toNepal']);
$ok(count($pv) === 1 && str_contains($pv[0][1], '2,150') && str_contains($pv[0][2], '2,250'), 'fare_delta preview old → old + 100');
$pv = AiOps::preview(['type' => 'fare_delta', 'stop' => 'Zu5ville Depot - Gate 2', 'delta' => -2100.0, 'dir' => 'toNepal']);
$ok($pv !== [] && str_contains($pv[0][2], '⚠'), 'fare_delta out of ₹100–₹20,000 is flagged');
$ok(AiOps::clean(['type' => 'fare_delta', 'stop' => '*', 'delta' => 0, 'dir' => 'toNepal']) === null && AiOps::clean(['type' => 'fare_delta', 'stop' => '*', 'delta' => 6000, 'dir' => 'toNepal']) === null, 'delta 0 / 6000 refused');

/* P3-2e: add_stop */
$ok(AiOps::parseWords('Naya location add karo: Gandhinagar, Gujarat') === [] && str_contains($note(), 'Gandhinagar') && str_contains($note(), ':'), 'SPEC "Naya location add karo: Gandhinagar, Gujarat" → asks the time');
$a = $first('Naya pickup add karo: Zu5newtown 22:30 bhada 2100');
$ok(($a['type'] ?? '') === 'add_stop' && $a['stop_name'] === 'Zu5newtown' && $a['stop_time'] === '22:30' && (float) $a['fare'] === 2100.0
    && $a['stop_type'] === 'boarding' && (int) $a['route_id'] === $rid, 'add_stop: name, time, fare, first route going');
$a = $first('Zu5newtown pickup jodnu raat 10 baje');
$ok(($a['type'] ?? '') === 'add_stop' && $a['stop_name'] === 'Zu5newtown' && $a['stop_time'] === '22:00' && $a['fare'] === null, 'add_stop without colon, raat 10 baje = 22:00');
$ok(AiOps::parseWords('Zu5newtown pickup add 10 baje') === [] && str_contains($note(), '22:00'), 'add_stop "10 baje" → morning or night?');
$ok(AiOps::parseWords('Naya pickup: Zu5ville Depot 21:00') === [] && str_contains($note(), 'already'), 'add_stop refuses a stop that is already there');
$pv = AiOps::preview(['type' => 'add_stop', 'route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'Zu5newtown', 'stop_time' => '22:30', 'fare' => null]);
$ok($pv !== [] && str_contains($pv[0][2], 'Zu5newtown') && str_contains($pv[0][2], 'default'), 'add_stop preview');
$ok(AiOps::clean(['type' => 'add_stop', 'route_id' => $u5['route'], 'stop_type' => 'boarding', 'stop_name' => 'X y', 'stop_time' => '22:30']) === null, 'add_stop on an OFF route refused');
/* review 16 Nov 2026: "naya / new" + "pickup / point" with a fare for a pickup that exists is a NEW FARE */
$a = $first('Surat pickup ko naya bhada 2300');
$ok(($a['type'] ?? '') === 'stop_fare' && ($a['stop'] ?? '') === 'Surat' && (float) ($a['fare'] ?? 0) === 2300.0, '"Surat pickup ko naya bhada 2300" = Surat fare 2300, not a new stop');
// review: a name ending in "ी" / "ી" keeps its last byte (trim() used to cut it → "Which place?")
$a = $first('Naya pickup: વાપી 22:30');
$ok(($a['type'] ?? '') === 'add_stop' && ($a['stop_name'] ?? '') === 'વાપી' && mb_check_encoding((string) $a['stop_name'], 'UTF-8'), 'add_stop Gujarati "વાપી" kept whole');
$a = $first('Naya pickup: वापी 22:30');
$ok(($a['type'] ?? '') === 'add_stop' && ($a['stop_name'] ?? '') === 'वापी', 'add_stop Devanagari "वापी" kept whole');
// review: the AI fills every schema field — route_ids [] is no filter, not a refusal
$c = AiOps::clean(['type' => 'trip_sale', 'date' => $tomorrow, 'on' => false, 'route_ids' => []]);
$ok($c !== null && !isset($c['route_ids']), 'clean() trip_sale with route_ids [] = every bus that day (as the preview shows)');
/* review 2 (16 Nov 2026): two pickups + two fares is asked, never one fare for both; "add 100" is not a ₹100 fare;
   the state after a comma is not part of a new stop's name */
if (Database::exists("SELECT 1 FROM route_stops rs JOIN routes r ON r.id = rs.route_id WHERE r.is_active = 1 AND rs.stop_type = 'boarding' AND rs.stop_name = 'Kamrej'")) {
    $ok(AiOps::parseWords('Surat ko bhada 2300 kar do aur Kamrej 2100') === [] && str_contains($note(), 'One fare'), '"Surat 2300 aur Kamrej 2100" asked, not ₹2,300 for both');
    $ok(count(AiOps::parseWords('Surat aur Kamrej ko bhada 2300')) === 2, '"Surat aur Kamrej ko bhada 2300" = both ₹2,300');
}
$ok(AiOps::parseWords('add 100 to Surat fare') === [] && $note() !== '', '"add 100 to Surat fare" asked, not a ₹100 fare');
$a = $first('Naya pickup add karo: Vapi 22:30 bhada 2100');
$ok(($a['type'] ?? '') === 'add_stop' && ($a['stop_name'] ?? '') === 'Vapi' && (float) ($a['fare'] ?? 0) === 2100.0, 'add_stop with "add" + a fare still a new stop');
$a = $first('Naya location add karo: Gandhinagar, Gujarat 22:30');
$ok(($a['type'] ?? '') === 'add_stop' && ($a['stop_name'] ?? '') === 'Gandhinagar', 'add_stop "Gandhinagar, Gujarat" → "Gandhinagar"');
$ok(AiOps::parseWords('L1 L2 block bholi') === [] && str_contains($note(), 'One seat'), 'two seats in one sentence asked, not L1 only');

/* P3-2g: seat_block */
$a = $first('Seat U15 block karo kal');
if (Database::exists("SELECT 1 FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.travel_date = :d AND r.is_active = 1 AND s.status <> 'cancelled'", ['d' => $tomorrow])) {
    $ok(($a['type'] ?? '') === 'seat_block' && $a['seat'] === 'U15' && $a['date'] === $tomorrow && $a['on'] === false, 'SPEC "Seat U15 block karo kal"');
} else {
    $ok($a === [] && $note() !== '', 'SPEC "Seat U15 block karo kal" (no bus tomorrow → said so)');
}
$ok(AiOps::parseWords('Seat U15 block karo') === [] && str_contains($note(), 'date'), 'seat_block without a date → which date?');
$a = $first('U15 unblock kal');
$ok($a === [] || (($a['type'] ?? '') === 'seat_block' && $a['on'] === true), 'unblock = back in service');
$ok(AiOps::parseWords('U99 block kal') === [], 'a seat the bus does not have → []');

/* P3-7b: Gujarati */
$ok(AiOps::findDate(AiOps::norm('આજે')) === todayISO() && AiOps::findDate(AiOps::norm('કાલે બસ')) === $tomorrow, 'Gujarati aaje / kale');
$a = $first('કાલે બસ બંધ');
$ok(($a['type'] ?? '') === 'trip_sale' && $a['on'] === false || ($a === [] && $note() !== ''), 'Gujarati "kale bas bandh"');
$a = $first('સુરત ભાડું ૨૩૦૦ કરો');
$ok(($a['stop'] ?? '') === 'Surat' && (float) ($a['fare'] ?? 0) === 2300.0, 'Gujarati Surat fare 2300');
$ok((AiOps::parseSwitches('whatsapp bot બંધ')['wa_booking_on'] ?? '') === '0', 'Gujarati bandh on a switch');
/* review 16 Nov 2026: a roman switch word must start a word */
$ok(!isset(AiOps::parseSwitches('Seat U15 block karo, bholi ko bus band')['quick_unlock_on']), '"block … band" does not turn PIN / pattern login off');
$ok(!isset(AiOps::parseSwitches('meta whatsapp alert band')['eta_alert_on']), '"meta" is not the ETA alert');
$ok((AiOps::parseSwitches('PIN pattern login chalu')['quick_unlock_on'] ?? '') === '1' && (AiOps::parseSwitches('fb messenger band')['fb_msgr_on'] ?? '') === '0', 'switch words still found');
// review 2: a short switch word must be a whole word
$ok(!isset(AiOps::parseSwitches('code login band')['allow_cod']) && !isset(AiOps::parseSwitches('pincode band')['quick_unlock_on']), '"code login band" is not COD, "pincode" not the PIN login');
$ok((AiOps::parseSwitches('cod band')['allow_cod'] ?? '') === '0' && (AiOps::parseSwitches('reminders band')['trip_reminders_enabled'] ?? '') === '0', 'COD / reminder words still found');

/* 16 Nov 2026 (Master v2, unit W3): the v2.0 switches by sentence, the number knobs, the delay words.
   Parse only — nothing is written (the writes are in tests/u8-W3-ai-staff-test.php). */
$sw = AiOps::parseSwitches('24 ghanta reminder on karo');
$ok(($sw['reminder_24h_enabled'] ?? '') === '1' && !isset($sw['trip_reminders_enabled']), 'W3 "24 ghanta reminder on karo" = the 24-hour reminder only');
$ok(AiOps::parseSwitches('device limit agent 2') === ['device_limit_agent' => '2'], 'W3 "device limit agent 2"');
$ok(AiOps::parseSwitches('device limit counter band') === ['device_limit_counter' => '0'], 'W3 "device limit counter band" = 0 (no limit)');
$ok(AiOps::parseSwitches('device limit agent 50') === [], 'W3 a device limit over 10 is not taken');
$ok(AiOps::parseSwitches('whatsapp daily cap 800') === ['wa_global_daily_cap' => '800'], 'W3 "whatsapp daily cap 800"');
$ok(AiOps::parseSwitches('group discount 8 percent') === ['group_discount_percent' => '8'], 'W3 "group discount 8 percent"');
$ok(AiOps::parseSwitches('ai change whatsapp on') === ['ai_change_wa_on' => '1'] && AiOps::parseSwitches('ai trip delay band') === ['ai_trip_delay_on' => '0'], 'W3 AI switches');
$ok(AiOps::parseSwitches('ai engine cloud') === ['ai_settings_engine' => 'cloud'] && !isset(AiOps::parseSwitches('ai engine office')['ai_settings_engine']),
    'W3 ai_settings_engine: cloud; "office" is no engine (the old request fallback is for request switches only)');
$ok(AiOps::parseSwitches('whatsapp cancel request') === ['wa_ai_cancel_mode' => 'request'], 'W3 "whatsapp cancel request"');
$ok(AiOps::parseSwitches('facebook post band') === ['social_publish_on' => '0'], 'W3 "facebook post band" is not Messenger');
$ok(AiOps::parseSwitches('typing on') === ['wa_typing_on' => '1'] && AiOps::parseSwitches('handoff message on') === ['wa_handoff_msg_on' => '1'], 'W3 typing / hand-off');
$ok(AiOps::delayMinutes(AiOps::norm('2 ghanta')) === 120 && AiOps::delayMinutes(AiOps::norm('४५ मिनेट')) === 45, 'W3 delay: 2 ghanta = 120, ४५ मिनेट = 45');
if (!Settings::getBool('ai_trip_delay_on', false)) {
    $ok(AiOps::parseWords('bholi jane bus 2 ghanta late') === [] && str_contains($note(), 'ai_trip_delay_on'), 'W3 "bholi jane bus 2 ghanta late" with the switch OFF → says it is off');
}
$ok((AiOps::parseWords('bus late fine 500')[0]['key'] ?? '') === 'cancel_fine_default', 'W3 a fine sentence with "late" is not a delay');
// review 16 Nov 2026: "प्लेट" (plate) holds "लेट" — a bus-plate sentence must not be read as a delay (its note would block the AI)
AiOps::parseWords('बस 02 को प्लेट नम्बर GJ05AB1234');
$ok(!str_contains($note(), 'ai_trip_delay_on') && !str_contains($note(), 'How late'), 'W3 "बस 02 को प्लेट नम्बर …" is no delay sentence');
$ok(AiOps::settingsEngine() === (Settings::getString('ai_settings_engine', 'local') === 'cloud' ? 'cloud' : 'local'), 'W3 settingsEngine reads ai_settings_engine (default local)');

/* P3-1c + P3-4a: the shared command path, its audits, and the old fares in the log */
$super = Database::fetch("SELECT * FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
if ($super !== null && $fixStop > 0) {
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $super['id'], 'username' => (string) $super['username'], 'full_name' => (string) ($super['full_name'] ?? ''),
        'role' => 'superadmin', 'permissions' => jsonColumn($super['permissions'] ?? null), 'last_seen' => time()];
    $pr = AiOps::propose('Zu5ville Depot ko bhada 2300', true, false);
    $ok(count($pr['ops']) === 1 && $pr['via'] === 'words' && AiOps::changes($pr) !== [], 'propose: one change, words');
    AiOps::auditProposed($pr);
    $row = Database::fetch("SELECT new_value FROM audit_logs WHERE id > :s AND action = 'settings.ai_proposed' ORDER BY id DESC LIMIT 1", ['s' => $startId]);
    $ok($row !== null && str_contains((string) $row['new_value'], '"stop_fare"') && str_contains((string) $row['new_value'], 'Zu5ville'), 'settings.ai_proposed keeps the parsed actions');
    AiOps::auditDeclined($pr);
    $ok(Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.ai_declined' AND new_value LIKE '%Zu5ville%'", ['s' => $startId]), 'a "No" is logged (settings.ai_declined)');
    $done = AiOps::confirm($pr, $super, true);
    $ok(count($done) === 1 && (float) Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $fixStop]) === 2300.0, 'confirm writes the fare');
    $log = Database::fetch("SELECT old_value, new_value FROM audit_logs WHERE id > :s AND action = 'routes.stop_fare' AND entity_id = 'Zu5ville Depot - Gate 2' ORDER BY id DESC LIMIT 1", ['s' => $startId]);
    $ok($log !== null && str_contains((string) $log['old_value'], '"Zu5ville Depot - Gate 2 #' . $fixStop . '":2150'), 'stop_fare audit keeps the OLD fare per stop (P3-1c)');
    $ok(Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'settings.ai_command' AND new_value LIKE '%\"actions\"%Zu5ville%'", ['s' => $startId]), 'settings.ai_command carries the actions');
    $lines = AiOps::apply(AiOps::parseWords('Zu5ville Depot ko bhada 50 badha do'), $super);
    $ok(count($lines) === 1 && (float) Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $fixStop]) === 2350.0, 'fare_delta +50 applied (2300 → 2350)');
    $lines = AiOps::apply([['type' => 'fare_delta', 'stop' => 'Zu5ville Depot - Gate 2', 'delta' => -2300.0, 'dir' => 'toNepal']], $super);
    $ok(str_starts_with($lines[0] ?? '', '⚠️') && (float) Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $fixStop]) === 2350.0, 'fare_delta below ₹100 refused, nothing written');

    /* add_stop through Boarding::addStop (unit U4a's C1) */
    $addA = $first('Naya pickup add karo: Zu5newtown 22:30 bhada 2100');
    if (method_exists('Boarding', 'addStop')) {
        $lines = AiOps::apply([$addA], $super);
        $made  = Database::fetch("SELECT id, stop_time, fare, route_id FROM route_stops WHERE stop_name = 'Zu5newtown' ORDER BY id DESC LIMIT 1");
        $ok(str_starts_with($lines[0] ?? '', '➕') && $made !== null && substr((string) $made['stop_time'], 0, 5) === '22:30' && (float) $made['fare'] === 2100.0
            && (int) $made['route_id'] === $rid, 'add_stop applied via Boarding::addStop');
    } else {
        $lines = AiOps::apply([$addA], $super);
        echo "SKIP: Boarding::addStop not in includes/boarding.php yet (U4a) — apply says: " . ($lines[0] ?? '') . "\n";
        $ok(str_contains($lines[0] ?? '', 'addStop'), 'add_stop says clearly when Boarding::addStop is missing');
    }

    /* seat_block through Seats::blockSeat / unblockSeat, on one far-future bus */
    $far = Database::fetch("SELECT s.id, s.travel_date FROM schedules s JOIN routes r ON r.id = s.route_id
                             WHERE r.is_active = 1 AND s.status <> 'cancelled' AND s.travel_date BETWEEN :a AND :b ORDER BY s.travel_date DESC, s.id LIMIT 1",
                           ['a' => date('Y-m-d', strtotime(todayISO() . ' +30 days')), 'b' => date('Y-m-d', strtotime(todayISO() . ' +400 days'))]);
    if ($far !== null && in_array('U17', Seats::seatIdsForSchedule((int) $far['id']), true)
        && !in_array('U17', Seats::blockedSeats((int) $far['id']), true)) {
        $u5['blocks'][] = [(int) $far['id'], 'U17'];
        $sa = AiOps::parseWords('Seat U17 block ' . $far['travel_date']);
        $sa = array_map(static fn(array $x): array => $x + ['route_ids' => [(int) Database::scalar('SELECT route_id FROM schedules WHERE id = :i', ['i' => (int) $far['id']])]], $sa);
        $lines = AiOps::apply($sa, $super);
        $ok(in_array('U17', Seats::blockedSeats((int) $far['id']), true) && str_starts_with($lines[0] ?? '', '💺'), 'seat_block blocks U17 on that bus');
        AiOps::apply([['type' => 'seat_block', 'seat' => 'U17', 'date' => $far['travel_date'], 'on' => true] + ['route_ids' => $sa[0]['route_ids'] ?? []]], $super);
        $ok(!in_array('U17', Seats::blockedSeats((int) $far['id']), true), 'seat_block on = back in service');
    } else {
        echo "SKIP: no far-future bus with a free U17 for the seat_block apply test\n";
    }
    unset($_SESSION[ADMIN_SESSION_KEY]);
} else {
    echo "SKIP: no super-admin or route for the command-path tests\n";
}
$u5Clean();

/* OpsHub money rule */
$ok(OpsHub::payState(['pay_status' => 'verified', 'pay_method' => 'upi'], null) === 'paid', 'UPI verified = paid');
$ok(OpsHub::payState(['pay_status' => 'verified', 'pay_method' => 'cash', 'agent_role' => 'agent'], null) === 'agent', 'agent cash = with agent');
$ok(OpsHub::payState(['pay_status' => 'verified', 'pay_method' => 'cash', 'agent_role' => 'counter'], null) === 'paid', 'desk cash = paid');
$ok(OpsHub::payState(['pay_status' => 'cod_pending', 'pay_method' => 'cod'], null) === 'due', 'COD = due');
$ok(OpsHub::payState(['pay_status' => 'cod_pending'], ['status' => 'paid']) === 'paid', 'office tick wins');
$ok(OpsHub::payState(['pay_status' => 'verified', 'pay_method' => 'upi'], ['status' => 'due']) === 'due', 'office untick wins');
$ok(OpsHub::maskPhone('9812345678') === '98•••••678', 'mask phone');

/* staff door */
$ok(OpsHub::isStaffPath('/admin/manifest.php') && !OpsHub::isStaffPath('/admin/api/search.php'), 'admin page yes, admin api no');
$ok(OpsHub::isStaffPath('/team/') && OpsHub::isStaffPath('/me/index.php') && OpsHub::isStaffPath('/join.php'), 'team, me, join');
$ok(!OpsHub::isStaffPath('/index.php') && !OpsHub::isStaffPath('/api/book.php') && !OpsHub::isStaffPath('/verify-ticket.php'), 'customer pages stay');
$ok(!OpsHub::isLiveCustomerHost('localhost') && !OpsHub::isLiveCustomerHost('127.0.0.1'), 'never on localhost');

echo "aiops-test: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
