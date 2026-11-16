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

/* 16 Nov 2026 (Master v2, unit X5): the last owner-decision switches, one sentence (or more) each.
   Parse only — nothing is written. */
$x5 = [
    'return discount 10 percent'          => ['return_discount_pct' => '10'],
    'फर्कने छुट ५ प्रतिशत'                 => ['return_discount_pct' => '5'],
    'return discount 30 din'              => ['return_discount_days' => '30'],
    'return chhut 45 days'                => ['return_discount_days' => '45'],
    'return discount 8% 20 din bhitra'    => ['return_discount_pct' => '8', 'return_discount_days' => '20'],
    'return discount band'                => ['return_discount_pct' => '0'],
    'return discount 25 percent'          => [],                                   // over 20 % → not taken
    'return discount 120 din'             => [],                                   // over 90 days → not taken
    'luggage fee 200'                     => ['luggage_fee_per_piece' => '200'],
    'extra luggage fee 150 karo'          => ['luggage_fee_per_piece' => '150'],   // never 40 extra seats (parseWords checked below)
    'saman fee band'                      => ['luggage_fee_per_piece' => '0'],
    'luggage fee 5000'                    => [],                                   // over ₹2,000 → not taken
    'infant seat on karo'                 => ['infant_seat_on' => '1'],
    'काखे बच्चा बन्द'                      => ['infant_seat_on' => '0'],
    'infant seat fare 500'                => ['infant_seat_fare' => '500'],
    'infant fare 4000'                    => [],                                   // over ₹3,000 → not taken
    'festival theme on'                   => ['festival_theme_on' => '1'],
    'चाडपर्व बन्द'                         => ['festival_theme_on' => '0'],
    'login theme gold'                    => ['login_theme' => 'gold'],
    'login theme classic karo'            => ['login_theme' => 'classic'],
    'fb comment on'                       => ['fb_comments_on' => '1'],
    'facebook comment band'               => ['fb_comments_on' => '0'],            // not Messenger
    'comment ai on'                       => ['fb_comment_ai_on' => '1'],
    'facebook comment ko ai band'         => ['fb_comment_ai_on' => '0'],          // the narrower switch only
    // review: each discount takes the number said next to its OWN name, not the first % in the sentence
    'group discount 10 percent return discount 5 percent' => ['group_discount_percent' => '10', 'return_discount_pct' => '5'],
    'return discount 5 percent group discount 10 percent' => ['group_discount_percent' => '10', 'return_discount_pct' => '5'],
    '8 percent return discount'           => ['return_discount_pct' => '8'],
];
foreach ($x5 as $s => $want) {
    $got = AiOps::parseSwitches($s);
    $ok($got === $want, 'X5 "' . $s . '" → ' . json_encode($want, JSON_UNESCAPED_UNICODE) . ' (got ' . json_encode($got, JSON_UNESCAPED_UNICODE) . ')');
}
$ok(AiOps::parseWords('extra luggage fee 150 karo') === [], 'X5 "extra luggage fee 150" is no extra-seat change');
$ok(AiOps::parseWords('infant seat fare 500') === [] && AiOps::lastNote() === '', 'X5 "infant seat fare 500" is no pickup fare (and asks no "which pickup?")');
$ok(AiOps::parseWords('10 extra seat') !== [] && ($first('Surat ko bhada 2300')['type'] ?? '') === 'stop_fare', 'X5 the luggage / infant guards leave extra seats and pickup fares alone');
// the new knobs stay inside their bounds on the AI / key path too (clean → easyNumber)
$ok(AiOps::easyNumber('return_discount_pct', '20') === '20' && AiOps::easyNumber('return_discount_pct', '20.5') === null
    && AiOps::easyNumber('return_discount_days', '0') === null && AiOps::easyNumber('return_discount_days', '90') === '90'
    && AiOps::easyNumber('luggage_fee_per_piece', '2000') === '2000' && AiOps::easyNumber('luggage_fee_per_piece', '2001') === null
    && AiOps::easyNumber('infant_seat_fare', '3000') === '3000' && AiOps::easyNumber('infant_seat_fare', '-1') === null, 'X5 bounds: 0-20 %, 1-90 days, ₹0-2,000, ₹0-3,000');
// no sentence the office already uses (this file, the W3 / U5 suites, the page's example chips) names a new key by accident
$x5Keys = ['return_discount_pct', 'return_discount_days', 'luggage_fee_per_piece', 'infant_seat_on', 'infant_seat_fare', 'festival_theme_on', 'login_theme',
           'fb_comments_on', 'fb_comment_ai_on'];
$x5Old  = ['Surat ko bhada 2300', 'sabai pickup ko bhada 100 badha do', 'bholi 1 baje jane bus band', 'Seat U15 block karo kal', 'Naya pickup: Gandhinagar 22:30',
           'Bus 02 ko number GJ05AB1234', 'Rajkot route band', 'Surat Rupaidiha matra', '10 extra seat', 'extra seat band', 'cancel fine 500', 'SHG-0027 commission 250',
           'whatsapp bot band gara', 'PIN pattern login chalu', '24 ghanta reminder on karo', '24 ghanta ko reminder band', 'device limit agent 2', 'device limit counter band',
           'bholi jane bus 2 ghanta late', 'whatsapp daily cap 800', 'group discount 8 percent', 'group discount 6 seat dekhi', 'group discount on karo', 'ai change whatsapp on',
           'ai trip delay band', 'ai engine cloud', 'whatsapp cancel request', 'facebook post band', 'fb messenger band', 'typing on', 'handoff message on', 'reminders band',
           'cod band', 'code login band', 'pincode band', 'meta whatsapp alert band', 'whatsapp bot બંધ', 'जरिवाना बन्द', 'Mehsana price 2,200', 'मेहसाणा भाडा २२००',
           'agent_commission_percent 6', 'Agent Ramesh ko commission 10% kar do', 'Kal 10 baje bus band karo', 'કાલે બસ બંધ', 'whatsapp notify customer off', 'hi login band',
           'agent cancel request', 'website ai band', 'knowledge band', 'marketing band', 'trip alert agent on', 'social post on', 'auto arrive band', 'settle reminder 3 din',
           'settlement due 7 days', 'good morning template band', 'vps ai band', 'offline callback on', 'public chalani band', 'eta alert on'];
$x5Hit = [];
foreach ($x5Old as $s) {
    $hit = array_intersect($x5Keys, array_keys(AiOps::parseSwitches($s)));
    if ($hit !== []) { $x5Hit[] = $s . ' → ' . implode(',', $hit); }
}
$ok($x5Hit === [], 'X5 no existing sentence now names a new switch: ' . implode(' | ', $x5Hit));
$ok(AiOps::parseSwitches('fb messenger band') === ['fb_msgr_on' => '0'] && AiOps::parseSwitches('facebook post band') === ['social_publish_on' => '0']
    && AiOps::parseSwitches('group discount band') === ['group_discount_on' => '0'], 'X5 Messenger / social post / group discount sentences unchanged');

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

/* ================= 16 Nov 2026 (Master v2, unit Y2): AI Sahayak makes an offer / coupon =================
   Parse, preview and refusals read only; the apply part makes coupons named ZY2<tag>… and deletes them
   (and their audit rows) again. A new offer is ALWAYS written is_active = 0 and auto_apply = 0. */
$y2Tag   = strtoupper(substr(str_shuffle('ABCDEFGHJKMNPQRSTVWXYZ'), 0, 4));
$y2      = static fn(string $s): string => 'ZY2' . $y2Tag . $s;
$y2Start = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$y2Clean = static function () use ($y2Tag, $y2Start): void {
    Database::delete('coupons', 'code LIKE :c', ['c' => 'ZY2' . $y2Tag . '%']);
    Database::delete('coupons', 'code LIKE :c', ['c' => 'ZYY' . $y2Tag . '%']);   // review fixture: a code whose start is letters only
    Database::delete('audit_logs', "id > :s AND action IN ('offer.create','offer.toggle','settings.ai_proposed','settings.ai_command','settings.ai_declined')
                                    AND (entity_id LIKE :c1 OR new_value LIKE :c2 OR detail LIKE :c3)",
        ['s' => $y2Start, 'c1' => 'ZY2' . $y2Tag . '%', 'c2' => '%ZY2' . $y2Tag . '%', 'c3' => '%ZY2' . $y2Tag . '%']);
};
register_shutdown_function($y2Clean);
$d10 = date('Y-m-d', strtotime(todayISO() . ' +10 days'));
$d20 = date('Y-m-d', strtotime(todayISO() . ' +20 days'));
$say = static fn(string $iso): string => date('j M', strtotime($iso));   // "26 Nov"

/* the spec sentences */
$a = $first('coupon ' . $y2('D10') . ' 10% max 200 till ' . $say($d20));
$ok(($a['type'] ?? '') === 'coupon' && $a['code'] === $y2('D10') && $a['discount_type'] === 'percent' && (float) $a['discount_value'] === 10.0
    && (float) $a['max_discount'] === 200.0 && $a['valid_from'] === null && $a['valid_until'] === $d20 && $a['auto_code'] === false, 'Y2 "coupon CODE 10% max 200 till <date>"');
$a = AiOps::parseWords('coupon DIWALI10 10% max 200 till 30 Nov');
$ok((($a[0]['type'] ?? '') === 'coupon' && substr((string) $a[0]['valid_until'], 5) === '11-30')
    || ($a === [] && (str_contains($note(), 'past') || str_contains($note(), 'already exists'))), 'Y2 SPEC "coupon DIWALI10 10% max 200 till 30 Nov"');
$a = $first('offer 5% max ₹100 ' . $say($d10) . ' to ' . $say($d20));
$ok(($a['type'] ?? '') === 'coupon' && $a['auto_code'] === true && preg_match('/^OFFER5-\d{1,2}[A-Z]{3}(-\d+)?$/', (string) $a['code']) === 1
    && (float) $a['discount_value'] === 5.0 && (float) $a['max_discount'] === 100.0 && $a['valid_from'] === $d10 && $a['valid_until'] === $d20, 'Y2 SPEC "offer 5% max ₹100 <d1> to <d2>" (code made for you)');
$a = $first('naya coupon ' . $y2('S50') . ' ₹50 off 50 jana');
$ok(($a['type'] ?? '') === 'coupon' && $a['discount_type'] === 'flat' && (float) $a['discount_value'] === 50.0 && $a['max_discount'] === null
    && $a['usage_limit'] === 50 && $a['per_user_limit'] === 1, 'Y2 SPEC "naya coupon CODE ₹50 off 50 jana"');
$a = $first('नयाँ कुपन ' . $y2('N20') . ' २०% बढीमा ₹३०० ' . $say($d20) . ' सम्म');
$ok(($a['type'] ?? '') === 'coupon' && (float) $a['discount_value'] === 20.0 && (float) $a['max_discount'] === 300.0 && $a['valid_until'] === $d20, 'Y2 Nepali: २०% बढीमा ₹३०० … सम्म');
$a = $first('naya offer ' . $y2('H5') . ' 5% 100 rupaye tak ' . $say($d20) . ' tak');
$ok(($a['type'] ?? '') === 'coupon' && (float) $a['max_discount'] === 100.0 && $a['valid_until'] === $d20, 'Y2 Hindi "100 rupaye tak" = the cap, "<date> tak" = the end');
$a = $first('coupon ' . $y2('DM') . ' 10% max 200 ' . date('j-n', strtotime($d20)));
$ok(($a['valid_until'] ?? '') === $d20, 'Y2 date as 30-11');
$a = $first('coupon ' . $y2('IS') . ' 10 percent max 300 ' . $d10 . ' to ' . $d20 . ' per user 2 min 1500');
$ok(($a['valid_from'] ?? '') === $d10 && $a['valid_until'] === $d20 && $a['per_user_limit'] === 2 && (float) $a['min_amount'] === 1500.0, 'Y2 ISO dates, per user 2, min 1500');
$a = $first('coupon ' . $y2('AD') . ' 10% upto 250 aaja dekhi ' . $say($d20) . ' samma');
$ok(($a['valid_from'] ?? '') === todayISO() && $a['valid_until'] === $d20 && (float) $a['max_discount'] === 250.0, 'Y2 "aaja dekhi … samma", "upto 250"');
if ($goRoute !== null) {
    $a = $first('coupon ' . $y2('RT') . ' ₹100 off route ' . $goRoute['route_code']);
    $ok(($a['route_id'] ?? 0) === (int) $goRoute['id'], 'Y2 a route said by its code');
}
// "₹50 off <date> tak" used to read as "that date's buses OFF" — it is an offer now
$a = $first('₹50 off coupon ' . $say($d10) . ' tak');
$ok(($a['type'] ?? '') === 'coupon' && $a['discount_type'] === 'flat' && $a['valid_until'] === $d10, 'Y2 "₹50 off coupon <date> tak" is an offer, never a trip sale OFF');

/* preview: code, % / ₹, cap, dates, limit, created OFF */
$pv = AiOps::preview(AiOps::parseWords('coupon ' . $y2('PV') . ' 10% max 200 ' . $say($d10) . ' to ' . $say($d20) . ' 50 jana')[0] ?? ['type' => 'x']);
$pvs = implode(' | ', $pv[0] ?? []);
$ok(count($pv) === 1 && str_contains($pvs, $y2('PV')) && str_contains($pvs, '10%') && str_contains($pvs, '₹200') && str_contains($pvs, date('j M Y', strtotime($d10)))
    && str_contains($pvs, date('j M Y', strtotime($d20))) && str_contains($pvs, '50 पटक') && str_contains($pvs, 'बन्द अवस्थामा बन्छ · created OFF') && $pv[0][1] === '—', 'Y2 preview row: ' . $pvs);

/* refusals */
$ok(AiOps::parseWords('coupon ' . $y2('NC') . ' 10% till ' . $say($d20)) === [] && str_contains($note(), 'needs a cap'), 'Y2 refuse: percent without a cap');
$ok(AiOps::parseWords('coupon ' . $y2('BG') . ' 60% max 200') === [] && str_contains($note(), 'too big, use the Offers page'), 'Y2 refuse: percent over 50 (too big, use the Offers page)');
$ok(AiOps::parseWords('coupon ' . $y2('BF') . ' ₹6000 off') === [] && str_contains($note(), 'too big'), 'Y2 refuse: ₹ over 5,000');
$ok(AiOps::parseWords('coupon ' . $y2('PS') . ' 10% max 200 till ' . $say(date('Y-m-d', strtotime(todayISO() . ' -3 days')))) === [] && str_contains($note(), 'already past'), 'Y2 refuse: a date in the past');
$ok(AiOps::parseWords('coupon ' . $y2('BD') . ' 10% max 200 till 31 Feb') === [] && str_contains($note(), 'not a real date'), 'Y2 refuse: 31 Feb');
$ok(AiOps::parseWords('coupon ' . $y2('RV') . ' 10% max 200 ' . $say($d20) . ' to ' . $say($d10)) === [] && str_contains($note(), 'end date is before'), 'Y2 refuse: end before start (OfferRules)');
$ok(AiOps::parseWords('coupon ' . $y2('TW') . ' 10% ₹50 off max 200') === [] && str_contains($note(), 'One discount'), 'Y2 refuse: % and ₹ in one offer');
$ok(AiOps::parseWords('coupon ' . $y2('LF') . ' ₹50 off 25') === [] && str_contains($note(), 'What is 25'), 'Y2 a number nobody named is asked');
$ok(AiOps::parseWords('coupon ' . $y2('Z0') . ' 10% max 200 0 jana') === [] && str_contains($note(), 'Max uses must be 1'), 'Y2 refuse: 0 uses (OfferRules)');
$ok(AiOps::parseWords('naya coupon') === [] && str_contains($note(), 'How much off'), 'Y2 "naya coupon" alone asks how much');
$ok(AiOps::clean(['type' => 'coupon', 'code' => $y2('CL'), 'discount_type' => 'percent', 'discount_value' => 10]) === null, 'Y2 clean(): a percent without a cap is refused on the "Yes" path too');
$ok(AiOps::clean(['type' => 'coupon', 'code' => 'x', 'discount_type' => 'flat', 'discount_value' => 50]) === null, 'Y2 clean(): a bad code is refused (OfferRules)');

/* switch sentences are not offers */
$ok(AiOps::parseWords('offer message band') === [] && $note() === '' && AiOps::parseSwitches('offer message band') === ['wa_marketing_on' => '0'], 'Y2 "offer message band" stays the marketing switch');
$ok(AiOps::parseWords('inbox offer link on') === [] && $note() === '' && AiOps::parseSwitches('inbox offer link on') === ['wa_inbox_promo_link_on' => '1'], 'Y2 "inbox offer link on" is the inbox switch');
$ok(AiOps::parseWords('group discount 8 percent') === [] && AiOps::parseSwitches('group discount 8 percent') === ['group_discount_percent' => '8'], 'Y2 "group discount 8 percent" is no offer');

/* off switch: an ACTIVE offer OFF; never ON; an OFF one says so */
$onId  = (int) Database::insert('coupons', ['code' => $y2('ON1'), 'title' => 'Y2 fixture', 'discount_type' => 'flat', 'discount_value' => 10, 'is_active' => 1, 'auto_apply' => 0, 'per_user_limit' => 1]);
$offId = (int) Database::insert('coupons', ['code' => $y2('OFF1'), 'title' => 'Y2 fixture', 'discount_type' => 'flat', 'discount_value' => 10, 'is_active' => 0, 'auto_apply' => 0, 'per_user_limit' => 1]);
$a = $first('offer band ' . $y2('ON1'));
$ok(($a['type'] ?? '') === 'coupon_off' && (int) $a['coupon_id'] === $onId, 'Y2 "offer band CODE" = that offer OFF');
$a = $first(strtolower($y2('ON1')) . ' offer off karo');
$ok(($a['type'] ?? '') === 'coupon_off' && (int) $a['coupon_id'] === $onId, 'Y2 "code offer off karo" (code first, lower-case)');
$pv = AiOps::preview(['type' => 'coupon_off', 'coupon_id' => $onId, 'code' => $y2('ON1')]);
$ok(($pv[0][1] ?? '') === 'ON 🟢' && ($pv[0][2] ?? '') === 'OFF ⛔' && str_contains($pv[0][0], $y2('ON1')), 'Y2 off preview ON → OFF');
$ok(AiOps::parseWords('offer on ' . $y2('OFF1')) === [] && str_contains($note(), 'never switches an offer ON'), 'Y2 the AI never switches an offer ON');
$ok(AiOps::parseWords('offer chalu karo ' . $y2('ON1')) === [] && str_contains($note(), 'never switches an offer ON'), 'Y2 "offer chalu" is never an action');
$ok(AiOps::parseWords('offer band ' . $y2('OFF1')) === [] && str_contains($note(), 'already OFF'), 'Y2 an OFF offer: already OFF');
$ok(AiOps::parseWords('offer band') === [] && str_contains($note(), 'Which offer'), 'Y2 "offer band" with no code asks which');
$ok(AiOps::parseWords('coupon ' . $y2('ON1') . ' 5% max 100') === [] && str_contains($note(), 'already exists'), 'Y2 refuse: a code already used');
$ok(AiOps::clean(['type' => 'coupon_off', 'coupon_id' => $offId]) === null && AiOps::clean(['type' => 'coupon_off', 'coupon_id' => $onId]) !== null, 'Y2 clean(): only an ACTIVE offer can be switched off');

/* apply: made OFF + never auto-applied, audited; the off switch; the permission */
$super = Database::fetch("SELECT * FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1");
if ($super !== null) {
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => (int) $super['id'], 'username' => (string) $super['username'], 'full_name' => (string) ($super['full_name'] ?? ''),
        'role' => 'superadmin', 'permissions' => jsonColumn($super['permissions'] ?? null), 'last_seen' => time()];
    $pr = AiOps::propose('coupon ' . $y2('AP') . ' 10% max 200 till ' . $say($d20) . ' 30 jana', true, false);
    $ch = AiOps::changes($pr);
    $ok(count($pr['ops']) === 1 && $pr['ops'][0]['type'] === 'coupon' && $pr['switches'] === [] && str_contains($ch[0]['new'] ?? '', 'created OFF'), 'Y2 propose: one coupon, preview says created OFF');
    $ok(!Database::exists('SELECT 1 FROM coupons WHERE code = :c', ['c' => $y2('AP')]), 'Y2 propose writes nothing');
    $done = AiOps::confirm($pr, $super, true);
    $row  = Database::fetch('SELECT * FROM coupons WHERE code = :c', ['c' => $y2('AP')]);
    $ok($row !== null && (int) $row['is_active'] === 0 && (int) $row['auto_apply'] === 0 && $row['discount_type'] === 'percent' && (float) $row['discount_value'] === 10.0
        && (float) $row['max_discount'] === 200.0 && $row['valid_until'] === $d20 && (int) $row['usage_limit'] === 30 && (int) $row['per_user_limit'] === 1, 'Y2 "Yes" makes the coupon OFF and not auto-applied');
    $ok(str_contains($done[0] ?? '', 'created OFF'), 'Y2 the done line says created OFF: ' . ($done[0] ?? ''));
    $ok(Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'offer.create' AND entity_id = :c AND detail LIKE '%S Hari AI%'", ['s' => $y2Start, 'c' => $y2('AP')]), 'Y2 offer.create audited');
    $again = AiOps::apply($pr['ops'], $super);
    $ok(str_starts_with($again[0] ?? '', '⚠️ coupon') && str_contains($again[0], 'already exists') && (int) Database::scalar('SELECT COUNT(*) FROM coupons WHERE code = :c', ['c' => $y2('AP')], 0) === 1,
        'Y2 a second "Yes" is refused with the reason (code already used)');
    $pr = AiOps::propose('offer band ' . $y2('ON1'), true, false);
    AiOps::confirm($pr, $super, true);
    $ok((int) Database::scalar('SELECT is_active FROM coupons WHERE id = :i', ['i' => $onId], 1) === 0
        && Database::exists("SELECT 1 FROM audit_logs WHERE id > :s AND action = 'offer.toggle' AND entity_id = :c", ['s' => $y2Start, 'c' => $y2('ON1')]), 'Y2 "offer band CODE" → OFF, audited offer.toggle');
    $again = AiOps::apply([['type' => 'coupon_off', 'coupon_id' => $onId, 'code' => $y2('ON1')]], $super);
    $ok(str_contains($again[0] ?? '', 'already OFF'), 'Y2 switching an OFF offer off again says so');
    // an operations login (routes / schedules, coupons.view only) may command, but not make an offer
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => 987654321, 'username' => 'y2ops', 'role' => 'operations', 'permissions' => [], 'last_seen' => time()];
    $pr = AiOps::propose('coupon ' . $y2('OP') . ' 10% max 200', false, false);
    $ok($pr['ops'] === [] && in_array('coupon', $pr['denied'], true) && !AiOps::allowed(['type' => 'coupon_off']), 'Y2 no coupons.edit → denied');
    $_SESSION[ADMIN_SESSION_KEY]['permissions'] = ['coupons.edit'];
    $ok(AiOps::allowed(['type' => 'coupon']) && AiOps::allowed(['type' => 'coupon_off']), 'Y2 coupons.edit → allowed');
    unset($_SESSION[ADMIN_SESSION_KEY]);
} else {
    echo "SKIP: no super-admin for the Y2 apply tests\n";
}

/* the four new Easy Settings switches (Y5 / inbox units' migrations make the rows) */
$y2Keys = ['agent_followups_on' => 'bool', 'helpdesk_cancel_escalate_inr' => 'int', 'wa_agent_settle_cod_on' => 'bool', 'wa_inbox_promo_link_on' => 'bool'];
foreach ($y2Keys as $k => $ty) {
    $def = AiOps::EASY_KEYS[$k] ?? null;
    $ok($def !== null && $def[1] === $ty && str_contains($def[0], ' · ') && preg_match('/\p{Devanagari}/u', $def[0]) === 1, 'Y2 ' . $k . ' is an Easy Settings ' . $ty . ' with a bilingual label');
}
$y2Sw = [
    'agent followup on karo'        => ['agent_followups_on' => '1'],
    'agent ko gunaso band'          => ['agent_followups_on' => '0'],
    'cancel followup 3000'          => ['helpdesk_cancel_escalate_inr' => '3000'],
    'helpdesk cancel band'          => ['helpdesk_cancel_escalate_inr' => '0'],
    'cancel followup 200000'        => [],                                         // over ₹1,00,000 → not taken
    'agent cod settle on'           => ['wa_agent_settle_cod_on' => '1'],          // never Cash on boarding too
    'agent cash whatsapp band'      => ['wa_agent_settle_cod_on' => '0'],
    'whatsapp inbox offer link chalu' => ['wa_inbox_promo_link_on' => '1'],
    'offer link band'               => ['wa_inbox_promo_link_on' => '0'],
    'cod band'                      => ['allow_cod' => '0'],                       // unchanged
    'marketing band'                => ['wa_marketing_on' => '0'],                 // unchanged
];
foreach ($y2Sw as $s => $want) {
    $got = AiOps::parseSwitches($s);
    $ok($got === $want, 'Y2 "' . $s . '" → ' . json_encode($want) . ' (got ' . json_encode($got) . ')');
}
$ok(AiOps::easyNumber('helpdesk_cancel_escalate_inr', '0') === '0' && AiOps::easyNumber('helpdesk_cancel_escalate_inr', '100000') === '100000'
    && AiOps::easyNumber('helpdesk_cancel_escalate_inr', '100001') === null && AiOps::easyNumber('helpdesk_cancel_escalate_inr', '-5') === null, 'Y2 cancel follow-up ₹0 – ₹1,00,000');
foreach (array_keys($y2Keys) as $k) {
    if (!Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k])) {
        $ok(AiOps::easyCurrent($k) === '0', 'Y2 ' . $k . ' is OFF / 0 by the code default (no row yet)');
    }
}
$y2Hit = [];
foreach ($x5Old as $s) {
    $hit = array_intersect(array_keys($y2Keys), array_keys(AiOps::parseSwitches($s)));
    if ($hit !== []) { $y2Hit[] = $s . ' → ' . implode(',', $hit); }
    if (AiOps::parseWords($s) !== [] && in_array((string) (AiOps::parseWords($s)[0]['type'] ?? ''), ['coupon', 'coupon_off'], true)) { $y2Hit[] = $s . ' → coupon'; }
}
$ok($y2Hit === [], 'Y2 no existing sentence now names a new switch or an offer: ' . implode(' | ', $y2Hit));

/* Y2 review (16 Nov 2026): the offer step must not take over other sentences, never make a code of a
   town / an ordinary word, and never widen a route the office named to every route. */
$offerAnswer = static fn(array $acts, string $n): bool => in_array((string) ($acts[0]['type'] ?? ''), ['coupon', 'coupon_off'], true)
    || preg_match('/^(🏷|🔒 AI ले अफर|💸|📍 अफर)/u', $n) === 1;
// 1. a fare / fine sentence with "offer" in it is still that sentence (it used to become coupon SURAT ₹2,000 off, all routes)
foreach (['Diwali offer Surat ko bhada ₹2000' => 'Surat ko bhada ₹2000', 'offer: Surat ko bhada 2000 rs' => 'Surat ko bhada 2000 rs',
          'Diwali offer Surat ko bhada 2000' => 'Surat ko bhada 2000', 'cancel fine 500 offer' => 'cancel fine 500'] as $s => $plain) {
    $got  = AiOps::parseWords($s);
    $want = AiOps::parseWords($plain);
    $ok($got === $want && $got !== [], 'Y2 review: "' . $s . '" = "' . $plain . '" (got ' . json_encode($got, JSON_UNESCAPED_UNICODE) . ')');
}
$a = $first('Diwali offer Surat ko bhada ₹2000');
$ok(($a['type'] ?? '') === 'stop_fare' && (float) $a['fare'] === 2000.0, 'Y2 review: "Diwali offer Surat ko bhada ₹2000" is Surat\'s fare');
// … but a fare sentence that says a discount is an offer
$ok(($first('offer ma bhada 10% chhut max 200')['type'] ?? '') === 'coupon', 'Y2 review: "offer ma bhada 10% chhut max 200" is an offer');
// 2. a setting named by its key, and plain sentences that only contain an offer word, are not offers
if (AiOps::settingRow('btn_offer_ribbon_on') !== null) {
    $ok(AiOps::parseWords('btn_offer_ribbon_on band') === [['type' => 'setting', 'key' => 'btn_offer_ribbon_on', 'value' => '0']]
        && AiOps::parseWords('btn_offer_ribbon_on on') === [['type' => 'setting', 'key' => 'btn_offer_ribbon_on', 'value' => '1']], 'Y2 review: "btn_offer_ribbon_on band / on" is rule 8 again');
}
$y2Keys2 = array_column(Database::fetchAll("SELECT skey FROM settings WHERE skey LIKE '%offer%' OR skey LIKE '%promo%' OR skey LIKE '%coupon%' OR skey LIKE '%discount%'"), 'skey');
$y2Bad   = [];
foreach ($y2Keys2 as $k) {
    foreach ([' on', ' band', ' 5'] as $suf) {
        $acts = AiOps::parseWords($k . $suf);
        if ($offerAnswer($acts, $note())) { $y2Bad[] = $k . $suf . ' → ' . (json_encode($acts) ?: '') . ' ' . mb_substr($note(), 0, 40); }
    }
}
$ok($y2Bad === [], 'Y2 review: no setting key (' . count($y2Keys2) . ' with offer / promo / coupon / discount) gets an offer answer: ' . implode(' | ', $y2Bad));
foreach (['offer ribbon band', 'offer ribbon on', 'home offer ribbon band', 'promo message band', 'promo message on', 'group discount offer 8 percent', 'offer band 9 nov'] as $s) {
    $acts = AiOps::parseWords($s);
    $ok(!$offerAnswer($acts, $note()), 'Y2 review: "' . $s . '" gets no offer answer (goes on to the other rules / the AI): ' . json_encode($acts) . ' ' . $note());
}
$ok(AiOps::parseWords('Diwali offer: bholi ko bus band') === AiOps::parseWords('bholi ko bus band') && ($first('Diwali offer: bholi ko bus band')['type'] ?? '') === 'trip_sale',
    'Y2 review: "Diwali offer: bholi ko bus band" is still tomorrow\'s sale OFF');
// 3. a town or an ordinary word is never the code
foreach (['offer dinus ₹100 off', 'offer wala ₹100 off', 'offer chahiyo ₹100 off', 'offer DINUS ₹100 off'] as $s) {
    $a = $first($s);
    $ok(($a['type'] ?? '') === 'coupon' && $a['auto_code'] === true && str_starts_with((string) $a['code'], 'FLAT100'), 'Y2 review: "' . $s . '" → a made code, not the word (' . ($a['code'] ?? '-') . ')');
}
$a = $first('coupon code ' . strtolower('ZYY' . $y2Tag . 'WL') . ' 10% max 200');   // letters only (ZY2… carries a digit)
$ok(($a['code'] ?? '') === 'ZYY' . $y2Tag . 'WL' && $a['auto_code'] === false, 'Y2 review: after "code" any word is the code');
$a = $first('coupon ' . strtolower('ZYY' . $y2Tag . 'WL') . ' 10% max 200');
$ok(($a['type'] ?? '') === 'coupon' && $a['auto_code'] === true, 'Y2 review: a lower-case word after "coupon" with no digit is not taken for a code');
// 4. a route the office names is never widened to every route
$rjTown = (string) Database::scalar("SELECT from_city FROM routes WHERE is_active = 1 GROUP BY from_city HAVING COUNT(*) >= 1 ORDER BY from_city LIMIT 1", [], '');
foreach (array_filter(['offer Rajkot route ₹100 off', 'naya offer Rajkot route ₹100 off', 'offer ' . $rjTown . ' route ₹100 off']) as $s) {
    $acts = AiOps::parseWords($s);
    $a    = $acts[0] ?? [];
    $ok(($acts === [] && str_contains($note(), 'route')) || (($a['type'] ?? '') === 'coupon' && $a['route_id'] !== null && !in_array((string) $a['code'], ['RAJKOT', strtoupper($rjTown)], true)),
        'Y2 review: "' . $s . '" → its one route, or "which route?" — never every route, never the town as the code: ' . json_encode($acts) . ' ' . mb_substr($note(), 0, 60));
}
$offRoute = Database::fetch("SELECT id, route_code FROM routes WHERE is_active = 0 AND route_code <> '' AND route_code NOT LIKE 'zu5%' ORDER BY id LIMIT 1");
if ($offRoute !== null) {
    $a = $first('offer 10% max 200 route ' . $offRoute['route_code']);
    $ok(($a['route_id'] ?? 0) === (int) $offRoute['id'], 'Y2 review: an OFF route said by its code is that route (as on the Offers page), not every route');
    $pv = implode(' | ', AiOps::preview($a)[0] ?? []);
    $ok(str_contains($pv, 'route is OFF'), 'Y2 review: the preview says that route is OFF: ' . $pv);
}
foreach (['offer 10% max 200 route', 'offer 10% max 200 route xyzq'] as $s) {
    $ok(AiOps::parseWords($s) === [] && str_contains($note(), 'Which route'), 'Y2 review: "' . $s . '" asks which route');
}
$a = $first('offer 10% max 200 sabai route');
$ok(($a['type'] ?? '') === 'coupon' && array_key_exists('route_id', $a) && $a['route_id'] === null, 'Y2 review: "sabai route" = every route');
$pk = (string) Database::scalar("SELECT rs.stop_name FROM route_stops rs JOIN routes r ON r.id = rs.route_id WHERE r.is_active = 1 AND rs.stop_type = 'boarding'
                                  AND rs.stop_name NOT LIKE '%-%' AND rs.stop_name NOT LIKE '%/%' AND rs.stop_name NOT LIKE '% %'
                                  AND rs.stop_name NOT IN (SELECT from_city FROM routes) AND rs.stop_name NOT IN (SELECT to_city FROM routes) ORDER BY rs.stop_name LIMIT 1", [], '');
if ($pk !== '') {
    $ok(AiOps::parseWords('offer ' . $pk . ' ₹100 off') === [] && str_contains($note(), 'per route, not per pickup'), 'Y2 review: "offer ' . $pk . ' ₹100 off" (a pickup) is asked, not every route');
}
if (!Database::exists("SELECT 1 FROM routes WHERE from_city = 'Vadodara' OR to_city = 'Vadodara'")) {
    $ok(AiOps::parseWords('offer Baroda ₹100 off') === [] && str_contains($note(), 'No route found for Vadodara'), 'Y2 review: a town with no route is asked, not every route');
}
// 5. the off switch by the offer's own word; "अफर" itself is no OFF verb
Database::insert('coupons', ['code' => 'ZYY' . $y2Tag . '10', 'title' => 'Y2 review fixture', 'discount_type' => 'flat', 'discount_value' => 10, 'is_active' => 1, 'auto_apply' => 0, 'per_user_limit' => 1]);
$a = $first(strtolower('ZYY' . $y2Tag) . ' offer band karo');
$ok(($a['type'] ?? '') === 'coupon_off' && $a['code'] === 'ZYY' . $y2Tag . '10', 'Y2 review: "<word> offer band karo" → the one ON offer whose code starts with it');
$ok(AiOps::parseWords(strtolower('ZYY' . $y2Tag) . ' offer chalu karo') === [] && str_contains($note(), 'never switches an offer ON'), 'Y2 review: … and never ON');
$ok(AiOps::parseWords('ZYY' . $y2Tag . '10 अफर') === [] && str_contains($note(), 'is ON now'), 'Y2 review: "CODE अफर" (no verb) only says it is ON');
$ok(AiOps::parseWords('अफर बन्द गर') === [] && str_contains($note(), 'Which offer'), 'Y2 review: "अफर बन्द गर" asks which offer');
$y2Clean();

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
