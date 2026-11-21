<?php
/**
 * npj-counter-test.php — Nepal counter (NPJ) + company locations (21 Nov 2026).
 *
 *  1. A Nepal desk (entity NP / NPR, direction toIndia) refuses a Gujarat -> Nepal
 *     sale and allows Rupaidiha -> Gujarat; an India desk with toIndia (Rupaidiha)
 *     still sells both ways; desk_one_way_on = 0 turns the rule off.
 *  2. Every ticket carries the Nepal office QR row, with phone + PAN.
 *  3. CompanyLocations: seed, map cleaning, public filter, town map fallback.
 *
 *   php -c .claude/php-dev.ini tests/npj-counter-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/counterdesk.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/companylocations.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
echo "\n=== NPJ counter + company locations ===\n\n";

$keep = [];
foreach (['desk_one_way_on', 'nepal_pan', 'nepal_phone', 'ticket_nepal_office_all', 'ticket_maps_qr'] as $k) {
    $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}
$restore = static function () use ($keep): void {
    foreach ($keep as $k => $row) {
        if ($row === null) {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        } else {
            Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]);
        }
    }
    Settings::flush();
};

/* ---------- 1. one-way desks ---------- */
$admin = Database::fetch('SELECT a.id FROM admins a JOIN admin_profiles ap ON ap.admin_id = a.id ORDER BY a.id LIMIT 1');
$oldCode = $admin !== null ? Database::scalar('SELECT counter_code FROM admin_profiles WHERE admin_id = :a', ['a' => $admin['id']]) : null;
$hadNpq = CounterDesk::get('NPQ') !== null;
$toNepal = Database::fetch("SELECT id, from_city, to_city FROM routes WHERE is_active = 1 ORDER BY id");
$toNepalId = 0; $toIndiaId = 0;
foreach (Database::fetchAll('SELECT id, from_city, to_city FROM routes WHERE is_active = 1') as $r) {
    if (Boarding::routeDirection($r) === 'toNepal' && $toNepalId === 0) { $toNepalId = (int) $r['id']; }
    if (Boarding::routeDirection($r) === 'toIndia' && $toIndiaId === 0) { $toIndiaId = (int) $r['id']; }
}
try {
    if ($admin === null || $toNepalId === 0 || $toIndiaId === 0) {
        check('test data: a clerk with a profile and both runs', false);
    } else {
        $aid = (int) $admin['id'];
        CounterDesk::save(['code' => 'NPQ', 'name' => 'Test Nepal desk', 'country' => 'NP', 'currency' => 'NPR', 'fx_rate' => 1.6,
            'default_direction' => 'toIndia', 'entity' => 'NP', 'is_active' => 1, 'allowed_methods' => 'cash']);
        Database::run('UPDATE admin_profiles SET counter_code = :c WHERE admin_id = :a', ['c' => 'NPQ', 'a' => $aid]);
        CounterDesk::flush();
        Settings::set('desk_one_way_on', '1', 'bool', 'booking');
        Settings::flush();
        $m = new ReflectionMethod(BookingService::class, 'assertDeskDirection');
        $m->setAccessible(true);
        $err = '';
        try { $m->invoke(null, $aid, $toNepalId); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
        check('Nepal desk refuses Gujarat -> Nepal', str_contains($err, 'Rupaidiha → Gujarat'), $err);
        $err = '';
        try { $m->invoke(null, $aid, $toIndiaId); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
        check('Nepal desk sells Rupaidiha -> Gujarat', $err === '', $err);

        // an India desk that opens on the return run (like RPD) still sells both ways
        CounterDesk::save(['code' => 'NPQ', 'name' => 'Test Nepal desk', 'country' => 'IN', 'currency' => 'INR',
            'default_direction' => 'toIndia', 'entity' => 'IN', 'is_active' => 1, 'allowed_methods' => 'cash']);
        CounterDesk::flush();
        $err = '';
        try { $m->invoke(null, $aid, $toNepalId); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
        check('India desk with toIndia still sells Gujarat -> Nepal', $err === '', $err);

        CounterDesk::save(['code' => 'NPQ', 'name' => 'Test Nepal desk', 'country' => 'NP', 'currency' => 'NPR', 'fx_rate' => 1.6,
            'default_direction' => 'toIndia', 'entity' => 'NP', 'is_active' => 1, 'allowed_methods' => 'cash']);
        CounterDesk::flush();
        Settings::set('desk_one_way_on', '0', 'bool', 'booking');
        Settings::flush();
        $err = '';
        try { $m->invoke(null, $aid, $toNepalId); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
        check('switch off -> no refusal', $err === '', $err);

        $err = '';
        try { $m->invoke(null, null, $toNepalId); } catch (RuntimeException $ex) { $err = $ex->getMessage(); }
        check('online sale (no clerk) never refused', $err === '');
    }
} finally {
    if ($admin !== null) {
        Database::run('UPDATE admin_profiles SET counter_code = :c WHERE admin_id = :a', ['c' => $oldCode, 'a' => $admin['id']]);
    }
    if (!$hadNpq) {
        Database::run("DELETE FROM counter_locations WHERE code = 'NPQ'");
    }
    CounterDesk::flush();
}

/* ---------- 2. Nepal office line on every ticket ---------- */
Settings::set('ticket_maps_qr', '1', 'bool', 'ticket');
Settings::set('ticket_nepal_office_all', '1', 'bool', 'ticket');
Settings::set('nepal_pan', '624740276', 'string', 'company');
Settings::set('nepal_phone', '+977 9866201375 / 9825788450', 'string', 'company');
Settings::flush();
$bk = ['boarding_stop' => 'Surat', 'from_city' => 'Ahmedabad', 'to_city' => 'Nepalgunj', 'entity' => 'IN', 'counter_code' => ''];
$qrs = Ticket::mapQrs($bk);
$np = array_values(array_filter($qrs, static fn(array $q): bool => $q['kind'] === 'nepal'));
check('India Gujarat -> Nepal ticket carries the Nepal office QR', count($np) === 1);
check('Nepal office line has the phone', isset($np[0]) && str_contains($np[0]['place'], '9866201375'), $np[0]['place'] ?? '');
check('Nepal office line has the PAN', isset($np[0]) && str_contains($np[0]['place'], 'PAN 624740276'));
Settings::set('ticket_nepal_office_all', '0', 'bool', 'ticket');
Settings::flush();
$np = array_filter(Ticket::mapQrs($bk), static fn(array $q): bool => $q['kind'] === 'nepal');
check('switch off -> India Gujarat -> Nepal ticket has no Nepal QR', count($np) === 0);
$restore();

/* ---------- 3. company locations ---------- */
check('table ready', CompanyLocations::ensure());
$all = CompanyLocations::all();
check('seeded six places', count($all) >= 6, (string) count($all));
$npj = array_values(array_filter($all, static fn(array $p): bool => $p['town'] === 'Nepalgunj'));
check('Nepalgunj office filled from the owner', isset($npj[0]) && str_contains($npj[0]['address'], 'Puspalal') && str_contains($npj[0]['phone'], '9866201375'));
check('lat,lng becomes a maps link', CompanyLocations::cleanMap('28.071306, 81.632472') === 'https://www.google.com/maps?q=28.071306,81.632472');
check('maps.app.goo.gl link kept', CompanyLocations::cleanMap('https://maps.app.goo.gl/abc123') === 'https://maps.app.goo.gl/abc123');
$bad = false;
try { CompanyLocations::cleanMap('https://evil.example.com/maps'); } catch (RuntimeException) { $bad = true; }
check('non-Google link refused', $bad);
$bad = false;
try { CompanyLocations::cleanMap('javascript:alert(1)'); } catch (RuntimeException) { $bad = true; }
check('javascript: refused', $bad);
$pub = CompanyLocations::forPublic();
check('public list shows filled + switched-on places only', $pub !== [] && array_filter($pub, static fn(array $p): bool => !$p['show_public'] || !$p['filled']) === []);
check('empty Rupaidiha parking hidden', array_filter($pub, static fn(array $p): bool => $p['name'] === 'Rupaidiha — own parking' && $p['address'] === '') === []);
check('town map fallback (Nepalgunj)', str_contains(CompanyLocations::mapForTown('Nepalgunj · Pushpalal Chowk'), '28.071306'));
check('town with no map -> empty', CompanyLocations::mapForTown('Rupaidiha Bus Stand') === '');

// save + delete round trip
$id = CompanyLocations::save(['name' => 'Test place zz', 'kind' => 'pickup', 'map_url' => '23.1,72.6', 'show_public' => 1], 0);
$got = CompanyLocations::get($id);
check('save works', $got !== null && $got['map'] === 'https://www.google.com/maps?q=23.1,72.6' && $got['show_public']);
CompanyLocations::delete($id);
check('delete works', CompanyLocations::get($id) === null);

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
