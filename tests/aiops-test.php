<?php
/**
 * tests/aiops-test.php — S Hari AI word parser + OpsHub rules (6 Nov 2026).
 * Reads the local test DB (routes, stops, buses); writes nothing.
 *
 *    php tests/aiops-test.php
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
