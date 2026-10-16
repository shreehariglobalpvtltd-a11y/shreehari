<?php
/**
 * ci-fixtures.php — make a FRESH test database (schema.sql + seed.sql +
 * every upgrade) look enough like the live register for the battery.
 *
 * On the VPS the battery runs against shari_test, a copy of the live
 * database, which carries things seed.sql never had: an owner account with
 * id 1, the real pickup towns (Kamrej, Ankleshwar, Bharuch, Anand, Nadiad)
 * on the daily route, and a booking horizon long enough for the suites that
 * book in 2099. A database built from the repository alone lacks all of
 * that, so six suites failed for data reasons on every machine but the VPS.
 * This script adds exactly those rows. Idempotent; refuses anything that is
 * not unmistakably a test database (same rule as run-all.php).
 *
 *     php tests/ci-fixtures.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (stripos((string) DB_NAME, 'test') === false || strtolower((string) APP_ENV) === 'production') {
    fwrite(STDERR, "REFUSING: '" . DB_NAME . "' / APP_ENV=" . APP_ENV . " does not look like a test database.\n");
    exit(2);
}

$log = static function (string $m): void { echo "  $m\n"; };

/* --owner: only step 1. ci-setup.sh runs it right after schema + seed, BEFORE
   the upgrades, because upgrade-2026-08-agent-role.sql inserts the demo
   agent1 and on a fresh table that would make an AGENT the id-1 account
   (on live id 1 is the owner; chalani-png-test relies on that). */
$ownerOnly = in_array('--owner', $argv, true);

/* 1. The owner's account — id 1 whenever the table is empty, as on live. */
$super = Database::fetch("SELECT id FROM admins WHERE username = 'superadmin'");
if ($super === null) {
    $id = Database::insert('admins', [
        'username'       => 'superadmin',
        'password_hash'  => password_hash(getenv('SHG_ADMIN_PW') ?: 'Admin@12345', PASSWORD_BCRYPT),
        'full_name'      => 'S Hari Global',
        'email'          => 'owner@test.local',
        'phone'          => '+919000000001',
        'role'           => 'superadmin',
        'permissions'    => '[]',
        'is_active'      => 1,
        'must_change_pw' => 0,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
    $log("superadmin created (id $id)");
} else {
    Database::update('admins', ['role' => 'superadmin', 'is_active' => 1, 'must_change_pw' => 0], 'id = :id', ['id' => (int) $super['id']]);
    $log('superadmin present (id ' . (int) $super['id'] . ')');
}

if ($ownerOnly) { echo "owner ready\n"; exit(0); }

/* 2. Settings the suites lean on. Settings::setMany() only touches rows
      that exist, so missing rows are inserted the plain way. */
$want = [
    // paid-pending-hold, manifest and launch-fixes book in 2099.
    ['booking_horizon_days', '30000', 'int', 'booking', 'Booking horizon (days)', 1],
];
foreach ($want as [$k, $v, $t, $g, $label, $pub]) {
    $row = Database::fetch('SELECT skey FROM settings WHERE skey = :k', ['k' => $k]);
    if ($row === null) {
        Database::insert('settings', ['skey' => $k, 'svalue' => $v, 'stype' => $t, 'sgroup' => $g, 'label' => $label, 'is_public' => $pub]);
        $log("setting $k inserted = $v");
    } else {
        Database::update('settings', ['svalue' => $v], 'skey = :k', ['k' => $k]);
        $log("setting $k = $v");
    }
}

/* 3. The owner holds no agent code (chalani-png-test: a sale by admin 1 with
      role counter/admin reads COUNTER/OFFICE, never AGENT). */
$codes = Settings::getArray('agent_codes', []);
if (isset($codes['1'])) {
    unset($codes['1']);
    Settings::set('agent_codes', $codes, 'json', 'agents', false);
    $log('agent code removed from admin 1');
}

/* 4. The daily route's real pickups. bot-parse-test reads Hindi "नडियाद" as
      the Nadiad pickup — which only exists when Nadiad is a boarding stop of
      an ACTIVE route. Mirrors the towns the live register carries. */
$stops = [
    ['Kamrej',     '13:30:00', 21.2729662, 72.9555969],
    ['Ankleshwar', '15:00:00', null, null],
    ['Bharuch',    '16:00:00', null, null],
    ['Anand',      '18:30:00', null, null],
    ['Nadiad',     '20:00:00', null, null],
];
$routes = Database::fetchAll("SELECT id, route_code FROM routes WHERE is_active = 1 ORDER BY id");
foreach ($routes as $r) {
    $rid = (int) $r['id'];
    $hasBoarding = (int) Database::scalar("SELECT COUNT(*) FROM route_stops WHERE route_id = :r AND stop_type = 'boarding'", ['r' => $rid], 0);
    $hasDrop     = (int) Database::scalar("SELECT COUNT(*) FROM route_stops WHERE route_id = :r AND stop_type = 'drop'", ['r' => $rid], 0);
    // Outbound (India → Nepal) routes have many boardings; return routes many drops.
    $type = $hasBoarding >= $hasDrop ? 'boarding' : 'drop';
    $max  = (int) Database::scalar("SELECT COALESCE(MAX(sort_order),0) FROM route_stops WHERE route_id = :r AND stop_type = :t", ['r' => $rid, 't' => $type], 0);
    foreach ($stops as [$name, $time, $lat, $lng]) {
        $exists = Database::fetch("SELECT id FROM route_stops WHERE route_id = :r AND stop_name = :n", ['r' => $rid, 'n' => $name]);
        if ($exists !== null) { continue; }
        $max++;
        Database::insert('route_stops', [
            'route_id'     => $rid,
            'stop_type'    => $type,
            'stop_name'    => $name,
            'landmark'     => '',
            'stop_time'    => $type === 'boarding' ? $time : null,
            'latitude'     => $lat,
            'longitude'    => $lng,
            'is_border'    => 0,
            'is_meal_halt' => 0,
            'sort_order'   => $max,
        ]);
        $log("route {$r['route_code']}: $type stop $name added");
    }
}

Settings::flush();
echo "fixtures ready\n";
