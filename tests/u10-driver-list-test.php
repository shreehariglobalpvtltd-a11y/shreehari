<?php
/**
 * 19 Nov 2026 (WA-5) — DriverList, DRY (the WhatsApp goes through a test seam):
 *   crewFor(): trip driver > the bus's default driver > route crew phone > none;
 *              an inactive driver is skipped;
 *   decide():  4 pax none, 5 first, 7 none, 10 update, T-1h final once, after
 *              departure none, outside the 24 h window none;
 *   text():    pickups in route order, seat + name, no phone digits unless
 *              driver_list_phones = 1, "बोर्डिङमा उठाउनु n जना ₹x";
 *   run():     the kv claim makes a second run send nothing; no driver = no
 *              send + no_driver = 1 (nothing claimed); a 977 number keeps 977.
 *
 *   php -c <ini> tests/u10-driver-list-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once __DIR__ . '/u10-seed-lib.php';
require_once INCLUDE_PATH . '/driverlist.php';

echo "\n=== u10 · Driver passenger list (dry) ===\n\n";
try {
    /* ---- decide(), pure ---- */
    $dep = strtotime('2030-01-10 13:00:00');
    $h   = 3600;
    check('decide: 4 pax → nothing', DriverList::decide(null, 4, $dep, $dep - 10 * $h) === null);
    check('decide: 5 pax → first', DriverList::decide(null, 5, $dep, $dep - 10 * $h) === 'first');
    check('decide: 7 after 5 → nothing', DriverList::decide(['n' => 5], 7, $dep, $dep - 10 * $h) === null);
    check('decide: 10 after 5 → update', DriverList::decide(['n' => 5], 10, $dep, $dep - 10 * $h) === 'update');
    check('decide: T-1h → final', DriverList::decide(['n' => 10], 10, $dep, $dep - $h) === 'final');
    check('decide: final only once', DriverList::decide(['n' => 10, 'final' => true], 12, $dep, $dep - $h / 2) === null);
    check('decide: after departure → nothing', DriverList::decide(null, 20, $dep, $dep + 60) === null);
    check('decide: 30 h out (outside the 24 h window) → nothing', DriverList::decide(null, 20, $dep, $dep - 30 * $h) === null);

    $s  = u10_seed();
    $st = $s['state'];
    $sid = $s['sid'];
    foreach (['driver_list_phones', 'driver_list_on'] as $k) { u10_keep_setting($st, $k); }
    $busId = (int) Database::scalar('SELECT COALESCE(s.bus_id, r.bus_id) FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :s', ['s' => $sid], 0);
    $hasBusCol = CounterDesk::hasColumn('buses', 'default_driver_id');
    if ($busId > 0 && $hasBusCol) { $st['buses'][$busId] = Database::scalar('SELECT default_driver_id FROM buses WHERE id = :i', ['i' => $busId]); }
    u10_save_state($st);

    $mkDrv = static fn(string $n, string $ph, int $active = 1): int => (int) Database::insert('drivers', ['full_name' => 'ZZTEST u10 Driver ' . $n, 'phone' => $ph, 'role' => 'driver', 'is_active' => $active]);
    $dTrip = $mkDrv('Trip', '9000010211');
    $dBus  = $mkDrv('Bus', '9779800010212');
    $dOff  = $mkDrv('Off', '9000010213', 0);
    Database::run('UPDATE schedules SET driver_id = NULL WHERE id = :i', ['i' => $sid]);
    Database::run('UPDATE routes SET crew_name = NULL, crew_phone = NULL WHERE id = :i', ['i' => $s['routeId']]);
    if ($busId > 0 && $hasBusCol) { Database::run('UPDATE buses SET default_driver_id = NULL WHERE id = :i', ['i' => $busId]); }

    check('crewFor: nothing on file → none', DriverList::crewFor($sid)['source'] === 'none');
    Database::run('UPDATE routes SET crew_name = :n, crew_phone = :p WHERE id = :i', ['n' => 'ZZTEST crew', 'p' => '9000010214', 'i' => $s['routeId']]);
    check('crewFor: route crew phone', DriverList::crewFor($sid)['source'] === 'route' && DriverList::crewFor($sid)['phone'] === '9000010214');
    if ($busId > 0 && $hasBusCol) {
        Database::run('UPDATE buses SET default_driver_id = :d WHERE id = :i', ['d' => $dBus, 'i' => $busId]);
        check('crewFor: the bus default driver beats the route crew', DriverList::crewFor($sid)['source'] === 'bus' && (int) DriverList::crewFor($sid)['driver_id'] === $dBus);
    } else {
        check('crewFor: bus default (migration not applied / no bus) - skipped', true);
    }
    Database::run('UPDATE schedules SET driver_id = :d WHERE id = :i', ['d' => $dOff, 'i' => $sid]);
    check('crewFor: an inactive trip driver is skipped', DriverList::crewFor($sid)['source'] !== 'trip');
    Database::run('UPDATE schedules SET driver_id = :d WHERE id = :i', ['d' => $dTrip, 'i' => $sid]);
    check('crewFor: the trip driver beats everything', DriverList::crewFor($sid)['source'] === 'trip' && DriverList::crewFor($sid)['phone'] === '9000010211');

    /* ---- text() ---- */
    Settings::set('driver_list_phones', '0', 'bool', 'whatsapp', false); Settings::flush();
    $txt = DriverList::text($sid);
    echo "  --- list ---\n  " . str_replace("\n", "\n  ", $txt) . "\n  ------------\n";
    check('text: header with route, date, time and passenger count', str_contains($txt, '→') && str_contains($txt, '7 यात्रु'));
    check('text: names and seats under a pickup line', str_contains($txt, '📍 1.') && str_contains($txt, 'ZZTEST u10 M1') && preg_match('/[A-Z]\d+ ZZTEST u10/u', $txt) === 1);
    check('text: no phone digits with driver_list_phones = 0', preg_match('/\d{10}/', $txt) !== 1);
    check('text: बोर्डिङमा उठाउनु n जना ₹x (the chalani rule)', preg_match('/बोर्डिङमा उठाउनु: \d+ जना ₹/u', $txt) === 1);
    Settings::set('driver_list_phones', '1', 'bool', 'whatsapp', false); Settings::flush();
    check('text: phones only when driver_list_phones = 1', str_contains(DriverList::text($sid), '9000010201'));
    Settings::set('driver_list_phones', '0', 'bool', 'whatsapp', false); Settings::flush();

    /* ---- run(), with the transport seam ---- */
    $sent = [];
    DriverList::$transport = static function (string $to, string $text, ?string $media, ?string $hint, array $meta) use (&$sent): bool {
        $sent[] = ['to' => $to, 'hint' => $hint, 'purpose' => $meta['purpose'] ?? '', 'text' => $text, 'media' => (string) $media];
        return true;
    };
    $t = DriverList::trip($sid);
    $depTs = (int) strtotime((string) $t['travel_date'] . ' ' . substr((string) $t['dep_time'], 0, 8));
    $now = $depTs - 5 * 3600;
    $r1 = DriverList::run(false, $now, [$sid]);
    check('run: 7 pax → the first list goes to the trip driver', $r1['sent'] === 1 && count($sent) === 1 && $sent[0]['to'] === '9000010211' && $sent[0]['purpose'] === 'driver_list', json_encode($r1));
    /* review fix: the chalani page draws every passenger's mobile, so with
       driver_list_phones = 0 (default) only the text goes - no picture */
    check('run: phones off → no chalani picture (it draws every mobile)', ($sent[0]['media'] ?? '') === '', (string) ($sent[0]['media'] ?? ''));
    Settings::set('driver_list_phones', '1', 'bool', 'whatsapp', false); Settings::flush();
    check('chalaniLink: phones on → the signed chalani picture link', str_contains((string) DriverList::chalaniLink($sid), 'download-chalan.php'));
    Settings::set('driver_list_phones', '0', 'bool', 'whatsapp', false); Settings::flush();
    check('chalaniLink: phones off → null', DriverList::chalaniLink($sid) === null);
    $claim = json_decode((string) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k", ['k' => 'driver_list.' . $sid]), true);
    check('run: the claim was written (n = 7)', is_array($claim) && (int) ($claim['n'] ?? 0) === 7);
    $r2 = DriverList::run(false, $now + 900, [$sid]);
    check('run: a second run sends nothing (kv claim)', $r2['sent'] === 0 && count($sent) === 1);
    $r3 = DriverList::run(false, $depTs - 3600, [$sid]);
    check('run: T-1h → the final list, once', $r3['sent'] === 1 && str_contains($sent[1]['text'] ?? '', 'अन्तिम'));
    check('run: never twice final', DriverList::run(false, $depTs - 1800, [$sid])['sent'] === 0);

    /* no driver anywhere → nothing sent, counted, nothing claimed */
    Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = :k", ['k' => 'driver_list.' . $sid]);
    Database::run('UPDATE schedules SET driver_id = NULL WHERE id = :i', ['i' => $sid]);
    Database::run('UPDATE routes SET crew_name = NULL, crew_phone = NULL WHERE id = :i', ['i' => $s['routeId']]);
    if ($busId > 0 && $hasBusCol) { Database::run('UPDATE buses SET default_driver_id = NULL WHERE id = :i', ['i' => $busId]); }
    $n0 = count($sent);
    $r4 = DriverList::run(false, $now, [$sid]);
    check('run: no driver → no send, no_driver = 1, listed', $r4['no_driver'] === 1 && count($sent) === $n0 && count($r4['missing']) === 1, json_encode($r4));
    check('run: nothing claimed while no driver', Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k", ['k' => 'driver_list.' . $sid]) === null);

    /* a Nepal (977) driver keeps 977 */
    if ($busId > 0 && $hasBusCol) {
        Database::run('UPDATE buses SET default_driver_id = :d WHERE id = :i', ['d' => $dBus, 'i' => $busId]);
        DriverList::run(false, $now, [$sid]);
        $last = end($sent);
        check('run: a 977 driver number keeps 977 (country hint NP)', is_array($last) && str_starts_with((string) $last['to'], '977') && in_array((string) $last['hint'], ['NP', '977'], true), json_encode([$last['to'] ?? null, $last['hint'] ?? null]));
    }
    /* dry run sends nothing */
    Database::run("DELETE FROM kv_store WHERE kscope = 'global' AND kkey = :k", ['k' => 'driver_list.' . $sid]);
    $n1 = count($sent);
    $rd = DriverList::run(true, $now, [$sid]);
    check('dry run: lists what would go, sends and claims nothing', count($sent) === $n1 && count($rd['would'] ?? []) === ($hasBusCol && $busId > 0 ? 1 : 0)
        && Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'global' AND kkey = :k", ['k' => 'driver_list.' . $sid]) === null);
    DriverList::$transport = null;
} catch (Throwable $e) {
    $GLOBALS['FAIL']++;
    echo '  ERROR  ' . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}
DriverList::$transport = null;
u10_cleanup();
echo "\n  {$GLOBALS['PASS']} passed, {$GLOBALS['FAIL']} failed\n";
exit($GLOBALS['FAIL'] === 0 ? 0 : 1);
