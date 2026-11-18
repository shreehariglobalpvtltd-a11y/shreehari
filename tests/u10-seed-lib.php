<?php
/**
 * tests/u10-seed-lib.php — the shared synthetic seed for the u10 suites
 * (counter book, day close, counter-day report, driver list). Not a test by
 * itself. Every row is tagged: phones 90000102xx, names 'ZZTEST u10 …',
 * admins u10_sd_*, drivers 'ZZTEST u10 Driver …'. u10_cleanup() removes it
 * all and puts back every setting / column it touched.
 *
 * The money lands on a FIXTURE DAY far in the past (FD = 2001-11-21:
 * confirmed_at / verified_at / ledger created_at), so the counter book of that
 * day holds exactly this seed; the seats sit on a free future travel date TD
 * (so the departure is real for the driver list).
 *
 *   M1  MSA desk, cash, 1 pax, verified          (₹ in MSA cash)
 *   M2  MSA desk, UPI, 2 pax, verified           (₹ in MSA online)
 *   P1  MSA desk, pay at boarding, 1 pax, due    (MSA pending)
 *   N1  NPJ desk, cash, 1 pax, NPR frozen 1.6    (NPJ cash + NPR)
 *   G1  agent with NO desk, cash, 1 pax + ₹150 commission  (line AG:<id>)
 *   O1  online web sale, UPI, 1 pax              (line '')
 */
declare(strict_types=1);

const U10_FD     = '2001-11-21';
const U10_PHONES = ['M1' => '9000010201', 'M2' => '9000010202', 'P1' => '9000010203', 'N1' => '9000010204', 'G1' => '9000010205', 'O1' => '9000010206'];
const U10_BOSS   = 'u10_sd_boss';
const U10_AGENT  = 'u10_sd_agent';
const U10_AGENT2 = 'u10_sd_agent2';

function u10_cleanup(): void
{
    foreach (U10_PHONES as $p) {
        foreach (Database::fetchAll('SELECT id, pnr FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            foreach (glob(TICKET_PATH . '/ticket_' . $r['pnr'] . '*') ?: [] as $f) { @unlink($f); }
            if (defined('INVOICE_PATH')) { @unlink(INVOICE_PATH . '/invoice_' . $r['pnr'] . '.pdf'); }
            foreach (['booking_collections', 'agent_ledger', 'pay_events'] as $t) {
                try { Database::run("DELETE FROM $t WHERE booking_id = :b", ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            }
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
    }
    foreach ([U10_BOSS, U10_AGENT, U10_AGENT2] as $u) {
        $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
        if ($id > 0) {
            try { Database::run('DELETE FROM agent_ledger WHERE agent_admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            try { Database::run('DELETE FROM admin_profiles WHERE admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
            Database::delete('admins', 'id = :i', ['i' => $id]);
        }
    }
    try { Database::run("DELETE FROM counter_day_close WHERE business_date = :d OR signed_name LIKE 'ZZTEST u10%'", ['d' => U10_FD]); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey LIKE 'driver_list.%' AND updated_by = 'driver-list' AND kvalue LIKE '%u10test%'"); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM drivers WHERE full_name LIKE 'ZZTEST u10 Driver%'"); } catch (Throwable $e) {}
    $state = sys_get_temp_dir() . '/shg_u10_seed_state.json';
    if (is_file($state)) {
        $st = (array) json_decode((string) file_get_contents($state), true);
        foreach ((array) ($st['settings'] ?? []) as $k => $row) {
            if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
            else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
        }
        if (!empty($st['route'])) {
            Database::run('UPDATE routes SET crew_name = :n, crew_phone = :p WHERE id = :i', ['n' => $st['route']['crew_name'], 'p' => $st['route']['crew_phone'], 'i' => (int) $st['route']['id']]);
        }
        foreach ((array) ($st['buses'] ?? []) as $bid => $dd) {
            try { Database::run('UPDATE buses SET default_driver_id = :d WHERE id = :i', ['d' => $dd, 'i' => (int) $bid]); } catch (Throwable $e) {}
        }
        $sid = (int) ($st['sched'] ?? 0);
        if ($sid > 0) {
            try { Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = :k", ['k' => 'driver_list.' . $sid]); } catch (Throwable $e) {}
            if (!empty($st['schedMade']) && (int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => $sid], 0) === 0) {
                Database::delete('schedules', 'id = :i', ['i' => $sid]);
            } elseif (array_key_exists('schedDriver', $st)) {
                Database::run('UPDATE schedules SET driver_id = :d WHERE id = :i', ['d' => $st['schedDriver'], 'i' => $sid]);
            }
        }
        @unlink($state);
    }
    Settings::flush();
}

/** Remember a setting's row before the test changes it (restored by u10_cleanup). */
function u10_keep_setting(array &$st, string $k): void
{
    if (!array_key_exists($k, $st['settings'])) {
        $st['settings'][$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
    }
}

function u10_save_state(array $st): void
{
    file_put_contents(sys_get_temp_dir() . '/shg_u10_seed_state.json', (string) json_encode($st));
}

/** @return array<string,mixed> ids, pnrs, sid, route, agent ids */
function u10_seed(): array
{
    require_once INCLUDE_PATH . '/fare.php';
    require_once INCLUDE_PATH . '/seats.php';
    require_once INCLUDE_PATH . '/booking.php';
    u10_cleanup();
    $st = ['settings' => [], 'buses' => []];
    foreach (['whatsapp_notify_customer' => '0', 'whatsapp_notify_admin' => '0', 'allow_cod' => '1', 'email_enabled' => '0'] as $k => $v) {
        u10_keep_setting($st, $k);
        Settings::set($k, $v, 'bool', 'notify', false);
    }
    Settings::flush();

    $TD = '';
    for ($i = 100; $i >= 70; $i--) {
        $d = date('Y-m-d', strtotime(todayISO() . " +$i days"));
        if ((int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE travel_date = :d', ['d' => $d], 0) === 0) { $TD = $d; break; }
    }
    if ($TD === '') { throw new RuntimeException('no free travel date 70-100 days out'); }
    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $st['route'] = ['id' => $routeId, 'crew_name' => $route['crew_name'] ?? null, 'crew_phone' => $route['crew_phone'] ?? null];
    $st['schedMade'] = Database::fetch('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 1', ['r' => $routeId, 'd' => $TD]) === null;
    $sched = Seats::schedule($routeId, $TD);
    $sid   = (int) $sched['id'];
    $st['sched'] = $sid;
    $st['schedDriver'] = Database::scalar('SELECT driver_id FROM schedules WHERE id = :i', ['i' => $sid]);
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => $sid]);
    u10_save_state($st);

    $mk = static function (string $u, string $role, string $name): int {
        return (int) Database::insert('admins', ['username' => $u, 'password_hash' => password_hash('U10SeedPw!77', PASSWORD_DEFAULT),
            'full_name' => $name, 'role' => $role, 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    };
    $boss   = $mk(U10_BOSS, 'superadmin', 'ZZTEST u10 Boss');
    $agent  = $mk(U10_AGENT, 'agent', 'ZZTEST u10 Agent');
    $agent2 = $mk(U10_AGENT2, 'agent', 'ZZTEST u10 Agent Two');

    $at = U10_FD . ' 10:00:00';
    $sell = static function (string $k, array $seats, string $method) use ($routeId, $TD, $boss): array {
        $pax = [];
        foreach ($seats as $n => $s) { $pax[] = ['name' => 'ZZTEST u10 ' . $k . ($n > 0 ? ' ' . ($n + 1) : ''), 'age' => 30 + $n, 'gender' => 'Male']; }
        $res = BookingService::create(['routeId' => $routeId, 'travelDate' => $TD, 'seats' => $seats, 'passengers' => $pax,
            'contact' => ['phone' => U10_PHONES[$k]], 'bookingMode' => 'sharing', 'paymentMethod' => $method, 'isCod' => false, 'boarding' => ''],
            ['adminId' => $boss, 'source' => 'admin', 'paymentMethod' => $method]);
        $row = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $res['pnr']]);
        Database::run('DELETE FROM agent_ledger WHERE booking_id = :b', ['b' => (int) $row['id']]);
        return $row;
    };
    $set = static function (array $b, array $bk, array $pay) use ($at): void {
        Database::update('bookings', $bk + ['confirmed_at' => $at], 'id = :i', ['i' => (int) $b['id']]);
        if ($pay !== []) { Database::update('payments', $pay, 'booking_id = :b', ['b' => (int) $b['id']]); }
    };
    $b = [];
    $b['M1'] = $sell('M1', ['L20'], 'cash');
    $set($b['M1'], ['counter_code' => 'MSA', 'status' => 'confirmed', 'sold_by_admin_id' => $boss], ['method' => 'cash', 'status' => 'verified', 'verified_by' => $boss, 'verified_at' => $at]);
    $b['M2'] = $sell('M2', ['L22', 'L24'], 'upi');
    $set($b['M2'], ['counter_code' => 'MSA', 'status' => 'confirmed', 'sold_by_admin_id' => $boss], ['method' => 'upi', 'status' => 'verified', 'verified_by' => $boss, 'verified_at' => $at]);
    $b['P1'] = $sell('P1', ['L26'], 'cash');
    $set($b['P1'], ['counter_code' => 'MSA', 'status' => 'confirmed', 'is_cod' => 1, 'sold_by_admin_id' => $boss], ['method' => 'cod', 'status' => 'cod_pending', 'verified_by' => null, 'verified_at' => null]);
    $b['N1'] = $sell('N1', ['L28'], 'cash');
    $nTot = (float) $b['N1']['total_amount'];
    $set($b['N1'], ['counter_code' => 'NPJ', 'status' => 'confirmed', 'sold_by_admin_id' => $boss, 'fx_currency' => 'NPR', 'fx_rate' => 1.6, 'fx_total' => round($nTot * 1.6)],
        ['method' => 'cash', 'status' => 'verified', 'verified_by' => $boss, 'verified_at' => $at, 'local_currency' => 'NPR', 'local_amount' => round($nTot * 1.6), 'fx_rate' => 1.6]);
    $b['G1'] = $sell('G1', ['L30'], 'cash');
    $set($b['G1'], ['counter_code' => null, 'status' => 'confirmed', 'sold_by_admin_id' => $agent], ['method' => 'cash', 'status' => 'verified', 'verified_by' => $agent, 'verified_at' => $at]);
    Database::insert('agent_ledger', ['agent_admin_id' => $agent, 'booking_id' => (int) $b['G1']['id'], 'account' => 'commission', 'entry_type' => 'commission',
        'amount' => 150, 'note' => 'u10 test commission', 'created_at' => $at]);
    $b['O1'] = $sell('O1', ['L32'], 'upi');
    $set($b['O1'], ['counter_code' => null, 'status' => 'confirmed', 'sold_by_admin_id' => null, 'source' => 'web'], ['method' => 'upi', 'status' => 'verified', 'verified_by' => $boss, 'verified_at' => $at]);
    foreach ($b as $k => $row) { $b[$k] = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $row['id']]); }
    return ['TD' => $TD, 'sid' => $sid, 'routeId' => $routeId, 'boss' => $boss, 'agent' => $agent, 'agent2' => $agent2, 'b' => $b, 'state' => $st];
}

$GLOBALS['PASS'] = 0; $GLOBALS['FAIL'] = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    if ($ok) { $GLOBALS['PASS']++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $GLOBALS['FAIL']++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
