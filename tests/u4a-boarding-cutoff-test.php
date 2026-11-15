<?php
/**
 * =====================================================================
 *  u4a-boarding-cutoff-test.php — U4a (16 Nov 2026).
 *
 *  1. P4-2b2: a pickup time more than an hour EARLIER than the route's
 *     departure belongs to the NEXT calendar day for the cut-off
 *     (Boarding::stopAt / status), the same rule as Boarding::pickupTs.
 *  2. P4-2e: a retimed daily bus (slot 1 dep_time_override) moves every
 *     pickup's cut-off by (override - routes.dep_time).
 *  3. C1 Boarding::addStop: validation (enum, name, time, fare "2,200",
 *     coordinates), sort_order = MAX+1, visible_to default, audit row.
 *  4. measureRoadKm: the engine's driving minutes come back as totalMin.
 *
 *  Throwaway rows on the LOCAL shari_test database: one switched-OFF
 *  route (code u4a-t-<pid>), its stops and one schedule row, all deleted
 *  at the end. Never touches a real route.
 *
 *     php -c .claude/php-dev.ini tests/u4a-boarding-cutoff-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$CODE = 'u4a-t-' . getmypid();
$rid  = 0;
$auditFrom = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);

$setStop = static function (int $rid, string $name, ?string $time): void {
    Database::query('DELETE FROM route_stops WHERE route_id = :r AND stop_name = :n', ['r' => $rid, 'n' => $name]);
    Database::insert('route_stops', [
        'route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => $name, 'stop_time' => $time,
        'is_border' => 0, 'is_meal_halt' => 0, 'sort_order' => 50,
    ]);
    Boarding::flush();
};
$setRouteDep = static function (int $rid, string $dep): void {
    Database::update('routes', ['dep_time' => $dep], 'id = :i', ['i' => $rid]);
    Boarding::flush();
};
$setOverride = static function (int $rid, string $date, ?string $ovr): void {
    Database::query(
        'INSERT INTO schedules (route_id, travel_date, slot, dep_time_override, total_seats, status)
         VALUES (:r, :d, 1, :o, 40, \'scheduled\')
         ON DUPLICATE KEY UPDATE dep_time_override = :o2',
        ['r' => $rid, 'd' => $date, 'o' => $ovr, 'o2' => $ovr]
    );
    Boarding::flush();
};
$at = static fn(string $date, string $hm): int => (int) strtotime($date . ' ' . $hm);

try {
    $rid = (int) Database::insert('routes', [
        'route_code' => $CODE, 'from_city' => 'Surat', 'to_city' => 'Rupaidiha', 'coach_type' => 'sleeper',
        'dep_time' => '13:00:00', 'arr_time' => '10:00:00', 'day_offset' => 2, 'base_fare' => 2000,
        'is_active' => 0, 'sort_order' => 999,
    ]);
    $D    = date('Y-m-d', strtotime('+5 days'));
    $D1   = date('Y-m-d', strtotime($D . ' +1 day'));

    echo "\n=== 1. P4-2b2: past-midnight pickups belong to the next day ===\n";
    $setStop($rid, 'Surat', '13:00:00');
    $setStop($rid, 'Nana Chiloda', '23:00:00');
    $setStop($rid, 'Mehsana', '00:30:00');
    $setStop($rid, 'Palanpur', '01:45:00');
    $setStop($rid, 'Kamrej', '12:30:00');
    $setStop($rid, 'Early', '11:30:00');
    check('Surat 13:00 = departure, same day', Boarding::stopAt($rid, $D, 'Surat') === $at($D, '13:00'));
    check('Nana Chiloda 23:00 = same day', Boarding::stopAt($rid, $D, 'Nana Chiloda') === $at($D, '23:00'));
    check('Mehsana 00:30 = NEXT day 00:30', Boarding::stopAt($rid, $D, 'Mehsana') === $at($D1, '00:30'),
        date('Y-m-d H:i', (int) Boarding::stopAt($rid, $D, 'Mehsana')));
    check('Palanpur 01:45 = NEXT day 01:45', Boarding::stopAt($rid, $D, 'Palanpur') === $at($D1, '01:45'));
    check('a stop 30 min before departure stays the same day (within the hour)', Boarding::stopAt($rid, $D, 'Kamrej') === $at($D, '12:30'));
    check('a stop 90 min before departure is the next day', Boarding::stopAt($rid, $D, 'Early') === $at($D1, '11:30'));
    check('the label form "Mehsana @ 00:30 [23.6,72.4]" matches the same stop',
        Boarding::stopAt($rid, $D, 'Mehsana · Silver Complex @ 00:30 [23.6,72.4]') === $at($D1, '00:30'));
    check('a label with no stop row falls back to the route departure', Boarding::stopAt($rid, $D, 'Nowhere') === $at($D, '13:00'));
    check('same rule as Boarding::pickupTs for Mehsana',
        Boarding::stopAt($rid, $D, 'Mehsana') === Boarding::pickupTs($D, 'Mehsana', '00:30', '13:00', '13:00', 1, $rid));
    check('a future travel date is open (unchanged)', Boarding::status($rid, $D, 'Mehsana')['open'] === true);

    // status() on TODAY: a pickup 70 min "before" the departure clock is tomorrow,
    // so it is still on sale today (before: closed at today's clock).
    $T = todayISO();
    $now = time();
    $mins = (int) date('G', $now) * 60 + (int) date('i', $now);
    if ($mins <= 23 * 60 + 25) {   // the departure clock (now + 30 min) must not wrap past midnight
        $setRouteDep($rid, date('H:i:00', $now + 30 * 60));
        $setStop($rid, 'Late Pick', date('H:i:00', $now - 40 * 60));
        $st = Boarding::status($rid, $T, 'Late Pick');
        check('today: a pickup 70 min before the departure clock is NOT closed (next-day roll)', $st['open'] === true,
            'dep ' . date('H:i', $now + 1800) . ', stop ' . date('H:i', $now - 2400) . ' -> ' . json_encode($st));
        $setStop($rid, 'Gone Pick', date('H:i:00', $now + 10 * 60));   // 20 min before departure: same day, inside the cut-off
        $st = Boarding::status($rid, $T, 'Gone Pick');
        check('today: a pickup 10 min from now closes (cut-off ' . Boarding::cutoffMinutes() . ' min)',
            Boarding::cutoffMinutes() < 10 ? $st['open'] === true : $st['open'] === false, json_encode($st));
    } else {
        echo "  SKIP  today's status() checks (clock too close to midnight)\n";
    }

    echo "\n=== 2. P4-2e: slot-1 dep_time_override moves the cut-off ===\n";
    $setRouteDep($rid, '13:00:00');
    $setStop($rid, 'Surat', '13:00:00');
    $setStop($rid, 'Vadodara', '17:00:00');
    $setStop($rid, 'Mehsana', '00:30:00');
    $setOverride($rid, $D, '16:00:00');
    check('retimed 13:00 -> 16:00: Surat at 16:00', Boarding::stopAt($rid, $D, 'Surat') === $at($D, '16:00'));
    check('retimed: Vadodara 17:00 -> 20:00', Boarding::stopAt($rid, $D, 'Vadodara') === $at($D, '20:00'));
    check('retimed: Mehsana 00:30 -> next day 03:30', Boarding::stopAt($rid, $D, 'Mehsana') === $at($D1, '03:30'));
    check('retimed: an unknown label follows the override', Boarding::stopAt($rid, $D, 'Nowhere') === $at($D, '16:00'));
    check('same answer as pickupTs for a retimed trip',
        Boarding::stopAt($rid, $D, 'Vadodara') === Boarding::pickupTs($D, 'Vadodara @ 17:00', null, '16:00', '13:00', 1, $rid));
    $setOverride($rid, $D, '11:00:00');
    check('moved earlier 13:00 -> 11:00: Surat at 11:00', Boarding::stopAt($rid, $D, 'Surat') === $at($D, '11:00'));
    check('moved earlier: Vadodara 17:00 -> 15:00', Boarding::stopAt($rid, $D, 'Vadodara') === $at($D, '15:00'));
    $setOverride($rid, $D, null);
    check('override cleared: back to the timetable', Boarding::stopAt($rid, $D, 'Vadodara') === $at($D, '17:00'));
    check('another date is not affected by an override', Boarding::stopAt($rid, $D1, 'Surat') === $at($D1, '13:00'));

    if ($mins <= 21 * 60 + 55) {   // the override clock (now + 2 h) must not wrap
        // retimed LATER: the timetable departure has passed, the bus has not.
        $setRouteDep($rid, date('H:i:00', $now - 2 * 3600));
        $setStop($rid, 'First', date('H:i:00', $now - 2 * 3600));
        $setOverride($rid, $T, date('H:i:00', $now + 2 * 3600));
        check('today, retimed later: the first pickup is still on sale', Boarding::status($rid, $T, 'First')['open'] === true);
        check('routeSellable follows the override too', Boarding::routeSellable($rid, $T) === true);
    } else {
        echo "  SKIP  today's retimed-later check (clock too close to midnight)\n";
    }
    if ($mins >= 65) {             // the override clock (now - 1 h) must not wrap
        // retimed EARLIER: the timetable says later, the bus has gone.
        $setRouteDep($rid, date('H:i:00', $now + 2 * 3600));
        $setStop($rid, 'First', date('H:i:00', $now + 2 * 3600));
        $setOverride($rid, $T, date('H:i:00', $now - 1 * 3600));
        $st = Boarding::status($rid, $T, 'First');
        check('today, retimed earlier: the gone pickup is closed', $st['open'] === false, json_encode($st));
        check('the refusal names the retimed clock', str_contains($st['reason'], date('H:i', $now - 3600)), $st['reason']);
    } else {
        echo "  SKIP  today's retimed-earlier check (clock too close to midnight)\n";
    }
    Database::query('DELETE FROM schedules WHERE route_id = :r AND travel_date = :d', ['r' => $rid, 'd' => $T]);
    Boarding::flush();

    echo "\n=== 3. C1 Boarding::addStop ===\n";
    $setRouteDep($rid, '13:00:00');
    $refused = static function (array $d): string {
        try { Boarding::addStop($d, 0); } catch (InvalidArgumentException $e) { return $e->getMessage(); }
        return '';
    };
    $m = $refused(['route_id' => $rid, 'stop_type' => 'checkpoint', 'stop_name' => 'X']);
    check('stop_type checkpoint is refused with a message (not a 500)', $m !== '' && str_contains($m, '·'), $m);
    check('blank name refused', $refused(['route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => '  ']) !== '');
    check('unknown route refused', $refused(['route_id' => 99999999, 'stop_type' => 'boarding', 'stop_name' => 'X']) !== '');
    check('time 25:99 refused', $refused(['route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'X', 'stop_time' => '25:99']) !== '');
    check('fare "abc" refused', $refused(['route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'X', 'fare' => 'abc']) !== '');
    check('fare "0.5" refused - 0 / blank = default, else 1-100000 as on Routes', $refused(['route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'X', 'fare' => '0.5']) !== '');
    check('latitude 123 refused', $refused(['route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'X', 'latitude' => '123', 'longitude' => '72']) !== '');
    check('latitude without longitude refused', $refused(['route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'X', 'latitude' => '23.2']) !== '');
    check('visible_to "staff" refused', $refused(['route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'X', 'visible_to' => 'staff']) !== '');
    check('nothing was inserted by the refusals', (int) Database::scalar("SELECT COUNT(*) FROM route_stops WHERE route_id = :r AND stop_name = 'X'", ['r' => $rid], 0) === 0);

    $max = (int) Database::scalar("SELECT COALESCE(MAX(sort_order), 0) FROM route_stops WHERE route_id = :r AND stop_type = 'boarding'", ['r' => $rid], 0);
    $id = Boarding::addStop([
        'route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => '  Gandhinagar,   Gujarat ', 'landmark' => 'Ch-0 circle',
        'stop_time' => '22:30', 'fare' => '₹2,200', 'latitude' => '23.2156354', 'longitude' => '72.6369415',
    ], 1);
    $row = Database::fetch('SELECT * FROM route_stops WHERE id = :i', ['i' => $id]) ?? [];
    check('addStop returns the new id', $id > 0 && $row !== []);
    check('name trimmed + spaces folded', ($row['stop_name'] ?? '') === 'Gandhinagar, Gujarat', (string) ($row['stop_name'] ?? ''));
    check('fare "₹2,200" saved as 2200.00', ($row['fare'] ?? null) !== null && (float) $row['fare'] === 2200.0, var_export($row['fare'] ?? null, true));
    check('time saved as 22:30:00', ($row['stop_time'] ?? '') === '22:30:00');
    check('7-decimal coordinates kept', abs((float) ($row['latitude'] ?? 0) - 23.2156354) < 0.0000001);
    check('sort_order = MAX+1 for route + type', (int) ($row['sort_order'] ?? 0) === $max + 1, ($row['sort_order'] ?? '?') . ' vs ' . ($max + 1));
    check('is_active defaults to ON', (int) ($row['is_active'] ?? 0) === 1);
    if (array_key_exists('visible_to', $row)) {
        check('visible_to defaults to customer,agent,counter', $row['visible_to'] === 'customer,agent,counter', (string) $row['visible_to']);
    }
    check('the new stop is sellable at once (caches flushed)', Boarding::stopRow($rid, 'Gandhinagar', 'boarding') !== null);
    $aud = Database::fetch("SELECT * FROM audit_logs WHERE id > :a AND action = 'route_stop.add' AND entity_id = :r ORDER BY id DESC LIMIT 1", ['a' => $auditFrom, 'r' => (string) $rid]);
    check('audit row route_stop.add written', $aud !== null);
    $id2 = Boarding::addStop(['route_id' => $rid, 'stop_type' => 'drop', 'stop_name' => 'Rupaidiha', 'fare' => '0'], 1);
    $row2 = Database::fetch('SELECT fare, sort_order FROM route_stops WHERE id = :i', ['i' => $id2]) ?? [];
    check('fare 0 = the direction default (NULL)', array_key_exists('fare', $row2) && $row2['fare'] === null);
    check('a drop counts its own sort_order', (int) ($row2['sort_order'] ?? 0) === 1);

    echo "\n=== 4. measureRoadKm returns the engine's driving minutes ===\n";
    Database::query('DELETE FROM route_stops WHERE route_id = :r', ['r' => $rid]);
    Boarding::flush();
    Boarding::addStop(['route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'Surat', 'stop_time' => '13:00', 'latitude' => '21.17', 'longitude' => '72.83'], 1);
    Boarding::addStop(['route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'Vadodara', 'stop_time' => '17:00', 'latitude' => '22.30', 'longitude' => '73.18'], 1);
    Boarding::addStop(['route_id' => $rid, 'stop_type' => 'drop', 'stop_name' => 'Rupaidiha', 'latitude' => '28.06', 'longitude' => '81.61'], 1);
    $m = Boarding::measureRoadKm($rid, static fn(array $locs): array => array_fill(0, count($locs) - 1, ['km' => 100.0, 'min' => 90.0]));
    check('measure ok with {km, min} legs', $m['ok'] === true, $m['error']);
    check('totalKm = 200', (float) $m['totalKm'] === 200.0, (string) $m['totalKm']);
    check('totalMin = 180', $m['totalMin'] === 180, var_export($m['totalMin'], true));
    $m = Boarding::measureRoadKm($rid, static fn(array $locs): array => array_fill(0, count($locs) - 1, 50.0));
    check('plain km legs still work, totalMin null', $m['ok'] === true && (float) $m['totalKm'] === 100.0 && $m['totalMin'] === null);
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($rid > 0) {
        Database::query('DELETE FROM schedules WHERE route_id = :r', ['r' => $rid]);
        Database::query('DELETE FROM route_stops WHERE route_id = :r', ['r' => $rid]);
        Database::query('DELETE FROM routes WHERE id = :r', ['r' => $rid]);
        Database::query("DELETE FROM audit_logs WHERE id > :a AND entity_id = :r AND action LIKE 'route_stop.%'", ['a' => $auditFrom, 'r' => (string) $rid]);
    }
    Boarding::flush();
}
$left = (int) Database::scalar('SELECT COUNT(*) FROM routes WHERE route_code = :c', ['c' => $CODE], 0);
check('cleanup: the throwaway route is gone', $left === 0);

echo "\n---------- $PASS passed / $FAIL failed ----------\n";
exit($FAIL === 0 ? 0 : 1);
