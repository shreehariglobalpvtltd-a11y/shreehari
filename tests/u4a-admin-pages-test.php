<?php
/**
 * =====================================================================
 *  u4a-admin-pages-test.php — U4a (16 Nov 2026): the three staff pages
 *  behind BUG1 (Mehsana fare) and BUG2 (location add), run for real.
 *
 *  Each page runs in its own PHP process with a hand-built staff session
 *  and a valid CSRF token (this file re-invokes itself with --run), so a
 *  page's exit() / 403 / headers cannot end the suite:
 *
 *   admin/map.php      add_stop "checkpoint" -> message, not HTTP 500;
 *                      counter role -> 403 on add_stop AND update_stop;
 *                      fare "2,200" -> 2200, 7-decimal GPS, sort MAX+1,
 *                      audit old -> new; modal CSS; step="any" / step="1".
 *   admin/routes.php   add_stop through Boarding::addStop + hints (route
 *                      OFF / not the booking-card route / no time);
 *                      save_stop_fares names old -> new and never puts a
 *                      fare back that the office did not touch (fare_was);
 *                      top "+ नयाँ पिकअप / ड्रप" box, Pick on map, C2
 *                      data-ie markup, daily-service confirm names routes.
 *   admin/settings.php seat-fare boxes say pickup/drop + direction, an
 *                      unset box says "not set — default"; the flash names
 *                      each changed row; "2,200" accepted; seat_mode_map
 *                      shape check; ?export=json without secrets (403 for
 *                      a role without settings.manage); notice / online
 *                      discount help text.
 *
 *  Throwaway rows on the LOCAL shari_test only: one switched-OFF route
 *  (u4a-p-<pid>) with its stops, deleted at the end with their audit rows.
 *  It never presses "Apply daily service" and never saves a seat map.
 *
 *     php -c .claude/php-dev.ini tests/u4a-admin-pages-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---------------------------------------------------------------- runner */
if (($argv[1] ?? '') === '--run') {
    // php this.php --run <page> <post> <get> <role> <perms>  (each JSON, base64: Windows escapeshellarg drops ")
    $arg  = static fn(int $i): array => json_decode((string) base64_decode((string) ($argv[$i] ?? ''), true), true) ?: [];
    $page = (string) $argv[2];
    $post = $arg(3);
    $get  = $arg(4);
    $role = (string) ($argv[5] ?? 'superadmin');
    $perm = $arg(6);
    $root = dirname(__DIR__);
    require_once $root . '/includes/bootstrap.php';
    if (DB_NAME !== 'shari_test') { fwrite(STDERR, "refusing: not shari_test\n"); exit(2); }
    $_SESSION = [];
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => 1, 'username' => 'superadmin', 'full_name' => 'U4a test', 'role' => $role,
        'permissions' => $perm, 'last_seen' => time(), 'qk_ok' => true,
    ];
    $tok = Security::csrfToken();
    $_SERVER['REQUEST_METHOD'] = $post !== [] ? 'POST' : 'GET';
    $_SERVER['SCRIPT_NAME']    = '/' . $page;
    $_SERVER['REQUEST_URI']    = '/' . $page . ($get !== [] ? '?' . http_build_query($get) : '');
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_GET  = $get;
    $_POST = $post !== [] ? $post + [CSRF_TOKEN_NAME => $tok] : [];
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        echo "\n@@META@@" . json_encode(['http' => http_response_code() ?: 200,
            'err' => $err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) ? $err['message'] : null]);
    });
    try {
        require $root . '/' . $page;
    } catch (Throwable $e) {
        http_response_code(500);
        echo "\nUNCAUGHT " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    exit(0);
}

/* ----------------------------------------------------------------- suite */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (DB_NAME !== 'shari_test') { echo "refusing: not the local shari_test database\n"; exit(2); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** @return array{http: int, body: string, flash: list<array{0: string, 1: string}>, uncaught: string} */
function page(string $page, array $post = [], array $get = [], string $role = 'superadmin', array $perms = []): array {
    $ini = getenv('SHG_TEST_INI') ?: (php_ini_loaded_file() ?: '');
    $cmd = escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --run ' . escapeshellarg($page) . ' ' . base64_encode((string) json_encode($post)) . ' ' . base64_encode((string) json_encode($get))
         . ' ' . escapeshellarg($role) . ' ' . base64_encode((string) json_encode($perms));
    $out  = (string) shell_exec($cmd . ' 2>&1');
    $meta = ['http' => 0, 'err' => null];
    $p = strrpos($out, '@@META@@');
    if ($p !== false) {
        $meta = json_decode(substr($out, $p + 8), true) ?: $meta;
        $out  = substr($out, 0, $p);
    }
    preg_match_all('#<div class="flash (ok|bad)"[^>]*>(.*?)</div>#s', $out, $m, PREG_SET_ORDER);
    $flash = array_map(static fn(array $x): array => [$x[1], html_entity_decode(strip_tags($x[2]), ENT_QUOTES)], $m);
    $unc = preg_match('/UNCAUGHT [^\n]+/', $out, $u) ? $u[0] : '';
    return ['http' => (int) ($meta['http'] ?? 0), 'body' => $out, 'flash' => $flash, 'uncaught' => $unc . (string) ($meta['err'] ?? '')];
}
$flashOf = static fn(array $r): string => implode(' | ', array_map(static fn(array $f): string => $f[0] . ': ' . $f[1], $r['flash']));

$CODE = 'u4a-p-' . getmypid();
$auditFrom = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$rid = 0;

try {
    $rid = (int) Database::insert('routes', [
        'route_code' => $CODE, 'from_city' => 'Surat', 'to_city' => 'Rupaidiha', 'coach_type' => 'sleeper',
        'dep_time' => '13:00:00', 'arr_time' => '10:00:00', 'day_offset' => 2, 'base_fare' => 2000,
        'is_active' => 0, 'sort_order' => 999,
    ]);
    $seedId = (int) Database::insert('route_stops', [
        'route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'U4a Seed', 'stop_time' => '13:00:00',
        'is_border' => 0, 'is_meal_halt' => 0, 'sort_order' => 4, 'is_active' => 1,
    ]);
    $countStops = static fn(string $name): int => (int) Database::scalar('SELECT COUNT(*) FROM route_stops WHERE route_id = :r AND stop_name = :n', ['r' => $rid, 'n' => $name], 0);

    echo "\n=== admin/map.php — Boarding tab ===\n";
    $r = page('admin/map.php', ['action' => 'add_stop', 'route_id' => $rid, 'stop_type' => 'checkpoint', 'stop_name' => 'U4a Check'], ['tab' => 'boarding']);
    check('"checkpoint" gives a message, not HTTP 500', $r['http'] !== 500 && $r['uncaught'] === '' && str_contains($flashOf($r), 'bad:'), 'HTTP ' . $r['http'] . ' ' . $flashOf($r) . $r['uncaught']);
    check('... and inserts nothing', $countStops('U4a Check') === 0);
    $r = page('admin/map.php', ['action' => 'add_stop', 'route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'U4a Counter'], ['tab' => 'boarding'], 'counter', ['schedules.view']);
    check('counter role (schedules.view only): add_stop is 403', $r['http'] === 403, 'HTTP ' . $r['http']);
    check('... and inserts nothing', $countStops('U4a Counter') === 0);
    $r = page('admin/map.php', ['action' => 'update_stop', 'stop_id' => $seedId, 'stop_time' => '13:00', 'fare' => '999', 'landmark' => ''], ['tab' => 'boarding'], 'agent', ['schedules.view']);
    check('agent role: update_stop (a fare change) is 403', $r['http'] === 403, 'HTTP ' . $r['http']);
    check('... and the fare is unchanged', Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $seedId], 'x') === null);

    $maxB = (int) Database::scalar("SELECT COALESCE(MAX(sort_order), 0) FROM route_stops WHERE route_id = :r AND stop_type = 'boarding'", ['r' => $rid], 0);
    $r = page('admin/map.php', ['action' => 'add_stop', 'route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'U4a Gandhinagar',
        'stop_time' => '', 'fare' => '2,189', 'landmark' => 'Ch-0', 'latitude' => '23.2156354', 'longitude' => '72.6369415'], ['tab' => 'boarding']);
    $row = Database::fetch("SELECT * FROM route_stops WHERE route_id = :r AND stop_name = 'U4a Gandhinagar'", ['r' => $rid]);
    check('superadmin add_stop saves the row', $row !== null, $flashOf($r) . $r['uncaught']);
    check('fare "2,189" saved as 2189 (was step=50 / (float) 2)', $row !== null && (float) $row['fare'] === 2189.0, var_export($row['fare'] ?? null, true));
    check('7-decimal latitude kept', $row !== null && abs((float) $row['latitude'] - 23.2156354) < 0.0000001);
    check('sort_order = MAX+1 (was a fixed 99)', $row !== null && (int) $row['sort_order'] === $maxB + 1, (string) ($row['sort_order'] ?? ''));
    check('no-time flash warns about the cut-off', str_contains($flashOf($r), 'No time set'), $flashOf($r));

    $r = page('admin/map.php', ['action' => 'update_stop', 'stop_id' => $seedId, 'stop_time' => '13:05', 'fare' => '₹2,200', 'landmark' => 'Bus stand'], ['tab' => 'boarding']);
    $seed = Database::fetch('SELECT fare, stop_time, landmark FROM route_stops WHERE id = :i', ['i' => $seedId]) ?? [];
    check('update_stop: fare "₹2,200" saved as 2200', (float) ($seed['fare'] ?? 0) === 2200.0, $flashOf($r));
    check('update_stop: time + landmark saved', ($seed['stop_time'] ?? '') === '13:05:00' && ($seed['landmark'] ?? '') === 'Bus stand');
    $aud = Database::fetch("SELECT old_value, new_value FROM audit_logs WHERE id > :a AND action = 'map.stop_update' AND entity_id = :e ORDER BY id DESC LIMIT 1", ['a' => $auditFrom, 'e' => (string) $seedId]);
    check('update_stop audit row carries the OLD values', $aud !== null && $aud['old_value'] !== null && str_contains((string) $aud['old_value'], '13:00'), (string) ($aud['old_value'] ?? 'none'));
    $r = page('admin/map.php', ['action' => 'update_stop', 'stop_id' => $seedId, 'stop_time' => '13:05', 'fare' => 'abc', 'landmark' => 'Bus stand'], ['tab' => 'boarding']);
    check('update_stop: fare "abc" refused, fare kept', str_contains($flashOf($r), 'bad:') && (float) Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $seedId], 0) === 2200.0, $flashOf($r));

    $r = page('admin/map.php', [], ['tab' => 'boarding']);
    check('Boarding tab renders (200, no error)', $r['http'] === 200 && $r['uncaught'] === '', 'HTTP ' . $r['http'] . ' ' . $r['uncaught']);
    check('the Add / Edit boxes are a fixed overlay on this page', str_contains($r['body'], '.id-preview-modal{position:fixed'));
    check('no "checkpoint" option any more', !str_contains($r['body'], 'value="checkpoint"'));
    check('GPS inputs take any decimals (step="any")', substr_count($r['body'], 'step="any"') >= 2 && !str_contains($r['body'], 'step="0.0001"'));
    check('fare inputs step 1 (no step="50")', !str_contains($r['body'], 'step="50"'));
    check('stops of switched-OFF routes are not listed', !str_contains($r['body'], 'U4a Gandhinagar'));
    $r = page('admin/map.php', [], ['tab' => 'boarding'], 'counter', ['schedules.view']);
    check('counter sees no Add / Edit buttons', $r['http'] === 200 && !str_contains($r['body'], "getElementById('addStopModal').hidden=false") && !str_contains($r['body'], 'openStopModal('), 'HTTP ' . $r['http']);

    echo "\n=== admin/routes.php — add / fares / page ===\n";
    $r = page('admin/routes.php', ['action' => 'add_stop', 'route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'U4a Kalol', 'fare' => '2,200', 'latitude' => '23.2', 'longitude' => '72.5']);
    $f = $flashOf($r);
    check('add_stop through Boarding::addStop saves', $countStops('U4a Kalol') === 1, $f . $r['uncaught']);
    check('fare "2,200" accepted on Routes', (float) Database::scalar("SELECT fare FROM route_stops WHERE route_id = :r AND stop_name = 'U4a Kalol'", ['r' => $rid], 0) === 2200.0);
    check('hint: the route is OFF', str_contains($f, 'This route is OFF'), $f);
    check('hint: no time -> cut-off warning', str_contains($f, 'No time set'), $f);
    $r = page('admin/routes.php', ['action' => 'add_stop', 'route_id' => $rid, 'stop_type' => 'checkpoint', 'stop_name' => 'U4a Bad', 'stop_time' => '25:99']);
    check('a bad time is refused with a message', str_contains($flashOf($r), 'bad:') && $countStops('U4a Bad') === 0, $flashOf($r));

    // The booking-card hint needs this route ON for one request: switched on and off at once.
    $first = null;
    require_once INCLUDE_PATH . '/quickticket.php';
    foreach (QuickTicket::routes() as $qr) { if ($qr['direction'] === 'toNepal') { $first = $qr; break; } }
    if ($first !== null) {
        Database::update('routes', ['is_active' => 1], 'id = :i', ['i' => $rid]);
        try {
            $r = page('admin/routes.php', ['action' => 'add_stop', 'route_id' => $rid, 'stop_type' => 'boarding', 'stop_name' => 'U4a Card', 'stop_time' => '22:30']);
        } finally {
            Database::update('routes', ['is_active' => 0], 'id = :i', ['i' => $rid]);
        }
        $f = $flashOf($r);
        check('hint: not the booking-card route names ' . $first['route_code'], str_contains($f, 'booking card') && str_contains($f, (string) $first['route_code']), $f);
        check('no cut-off warning when a time is given', !str_contains($f, 'No time set'), $f);
    } else {
        echo "  SKIP  no active Gujarat -> Nepal route for the booking-card hint\n";
    }

    $kal = (int) Database::scalar("SELECT id FROM route_stops WHERE route_id = :r AND stop_name = 'U4a Kalol'", ['r' => $rid], 0);
    $r = page('admin/routes.php', ['action' => 'save_stop_fares', 'route_id' => $rid, 'stop_type' => 'boarding',
        'fare' => [$seedId => '2,350', $kal => '2200'], 'fare_was' => [$seedId => '2200', $kal => '2200']]);
    $f = $flashOf($r);
    check('save_stop_fares: "2,350" saved', (float) Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $seedId], 0) === 2350.0, $f);
    check('flash names the row old -> new', str_contains($f, 'U4a Seed') && str_contains($f, '2,200') && str_contains($f, '2,350') && str_contains($f, $CODE), $f);
    // Someone changes Kalol elsewhere; a stale page posts its untouched box -> must not revert.
    Database::update('route_stops', ['fare' => 2500], 'id = :i', ['i' => $kal]);
    $r = page('admin/routes.php', ['action' => 'save_stop_fares', 'route_id' => $rid, 'stop_type' => 'boarding',
        'fare' => [$seedId => '2350', $kal => '2200'], 'fare_was' => [$seedId => '2350', $kal => '2200']]);
    check('an untouched box never puts an old price back (fare_was)', (float) Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $kal], 0) === 2500.0, $flashOf($r));

    $r = page('admin/routes.php');
    $b = $r['body'];
    check('Routes page renders (200, no error)', $r['http'] === 200 && $r['uncaught'] === '', 'HTTP ' . $r['http'] . ' ' . $r['uncaught']);
    check('top "+ नयाँ पिकअप / ड्रप · Add pickup / drop point" box', str_contains($b, 'id="add-stop"') && str_contains($b, 'नयाँ पिकअप / ड्रप · Add pickup / drop point'));
    check('the box posts to add_stop', (bool) preg_match('#id="npAddForm".*?name="action" value="add_stop"#s', $b));
    check('"📍 Pick on map" buttons present', substr_count($b, 'data-pick-map') >= 3);
    check('the picker uses the vendored MapLibre', str_contains($b, "'/assets/vendor/maplibre-gl-' + MLG"));
    check('GPS inputs step="any"', !str_contains($b, 'step="0.0000001"'));
    check('C2: fare tag with data-ie-confirm', (bool) preg_match('/data-ie-entity="route_stop" data-ie-field="fare" data-ie-id="\d+" data-ie-value="\d*" data-ie-type="money" data-ie-confirm="1"/', $b));
    check('C2: time + landmark tags', str_contains($b, 'data-ie-field="stop_time"') && str_contains($b, 'data-ie-type="time"') && str_contains($b, 'data-ie-field="landmark"'));
    check('C2: admin-inline-edit.js included', str_contains($b, '<script src="/assets/js/admin-inline-edit.js" defer></script>'));
    check('batch fare form carries fare_was', str_contains($b, 'name="fare_was['));
    $active = array_column(Database::fetchAll('SELECT route_code FROM routes WHERE is_active = 1 ORDER BY id'), 'route_code');
    $conf = preg_match('#onsubmit="return confirm\(([^"]*)\)"[^>]*>\s*<input[^>]*>\s*<input type="hidden" name="action" value="daily_setup">#s', $b, $cm)
        ? (string) json_decode(html_entity_decode($cm[1], ENT_QUOTES)) : '';
    check('daily-service confirm names what goes OFF', $conf !== '' && (count($active) <= 2 || str_contains($conf, 'will be switched OFF')), mb_substr($conf, 0, 160));
    $r = page('admin/routes.php', [], [], 'viewer', ['routes.view']);
    check('a viewer gets no add box and no click-to-edit', !str_contains($r['body'], 'id="npAddForm"') && !str_contains($r['body'], 'data-ie-entity'), 'HTTP ' . $r['http']);

    echo "\n=== admin/settings.php — fares grid, flash, seat map, export ===\n";
    // A stop of the throwaway route in the grid needs the route ON for the GET render only.
    Database::update('routes', ['is_active' => 1], 'id = :i', ['i' => $rid]);
    try { $r = page('admin/settings.php'); } finally { Database::update('routes', ['is_active' => 0], 'id = :i', ['i' => $rid]); }
    $b = $r['body'];
    check('Settings renders (200, no error)', $r['http'] === 200 && $r['uncaught'] === '', 'HTTP ' . $r['http'] . ' ' . $r['uncaught']);
    check('every seat-fare box says pickup going', str_contains($b, 'पिकअप (जाने) · pickup, going'));
    check('no value-looking "2000 (default)" placeholder', !preg_match('/placeholder="\d+ \(default\)"/', $b));
    check('an unset box says "not set — default"', str_contains($b, 'not set — default'));
    check('the seeded stop box is drawn with its saved fare', (bool) preg_match('/name="pf\[' . $seedId . '\]" value="2350"/', $b));
    check('chips list both directions', str_contains($b, 'पिकअप (जाने) · going pickups'));
    check('online_discount_pct marked not used', str_contains($b, 'not used · प्रयोगमा छैन'));
    check('notice_text help: 24 h', str_contains($b, 'shows for 24 h after you save it'));
    check('export link for the settings role', str_contains($b, 'settings.php?export=json'));

    $r = page('admin/settings.php', ['_boolkeys' => '', 'pf' => [$seedId => '₹2,400'], 'pf_was' => [$seedId => '2350']]);
    $f = $flashOf($r);
    check('Settings save: "₹2,400" stored as 2400', (float) Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $seedId], 0) === 2400.0, $f);
    check('flash names the row, direction and old -> new', str_contains($f, 'Seat fares changed') && str_contains($f, 'U4a Seed · पिकअप (जाने)') && str_contains($f, '2,350') && str_contains($f, '2,400'), $f);
    Database::update('route_stops', ['fare' => 2600], 'id = :i', ['i' => $seedId]);
    page('admin/settings.php', ['_boolkeys' => '', 'pf' => [$seedId => '2400'], 'pf_was' => [$seedId => '2400']]);
    check('an untouched Settings box never reverts a newer fare (pf_was)', (float) Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $seedId], 0) === 2600.0);
    $r = page('admin/settings.php', ['_boolkeys' => '', 'pf' => [$seedId => 'abc'], 'pf_was' => [$seedId => '2600']]);
    check('a non-number seat fare is refused, nothing saved', str_contains($flashOf($r), 'Not saved') && (float) Database::scalar('SELECT fare FROM route_stops WHERE id = :i', ['i' => $seedId], 0) === 2600.0, $flashOf($r));

    $smBefore = (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'seat_mode_map'", [], '');
    if ($smBefore !== '') {
        $r = page('admin/settings.php', ['_boolkeys' => '', 's' => ['seat_mode_map' => '{"sleeper":{"canonical":"sharing","decks":["L","U"],"perDeck":0,"modes":{"sharing":{"bedsPerLabel":1}}}}']]);
        check('seat_mode_map with a bad shape is refused', str_contains($flashOf($r), 'seat layout rule'), $flashOf($r));
        check('... and the stored rule is unchanged', (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'seat_mode_map'", [], '') === $smBefore);
        $cur = Settings::getArray('seat_mode_map', []);
        $prop = $cur;
        if (isset($prop['sleeper']['modes']['private']['bedsPerLabel'])) {
            $prop['sleeper']['modes']['private']['bedsPerLabel'] = 3;   // 36 % 3 = 0: a valid shape that re-seats private cabins
            $moved = Seats::mapChangeImpact($prop);
            if ($moved !== []) {
                $r = page('admin/settings.php', ['_boolkeys' => '', 's' => ['seat_mode_map' => json_encode($prop)]]);
                check('a change that re-seats sold seats is refused, naming a PNR', str_contains($flashOf($r), (string) $moved[0]['pnr']), mb_substr($flashOf($r), 0, 200));
                check('... and the stored rule is unchanged', (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'seat_mode_map'", [], '') === $smBefore);
            } else {
                echo "  SKIP  no future private booking to re-seat (the impact refusal is not exercised; nothing posted)\n";
            }
        }
    } else {
        echo "  SKIP  no seat_mode_map row locally\n";
    }

    $r = page('admin/settings.php', [], ['export' => 'json']);
    $j = json_decode(trim($r['body']), true);
    check('?export=json returns JSON', is_array($j) && isset($j['settings'], $j['routes'], $j['route_stops'], $j['coupons']), substr(trim($r['body']), 0, 120));
    if (is_array($j)) {
        $keys = array_column($j['settings'], 'skey');
        $leak = array_values(array_filter($keys, static fn(string $k): bool => Settings::isSecretKey($k)));
        check('no secret key in the export', $leak === [], implode(',', $leak));
        check('connection ids left out too (e.g. whatsapp_phone_id / smtp_host)', !in_array('whatsapp_phone_id', $keys, true) && !in_array('smtp_host', $keys, true));
        check('ordinary settings are in it (fare_to_nepal)', in_array('fare_to_nepal', $keys, true));
        check('routes carry no crew phone', !array_key_exists('crew_phone', (array) ($j['routes'][0] ?? [])));
        $exAud = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE id > :a AND action = 'settings.export'", ['a' => $auditFrom], 0);
        check('the download is audited', $exAud >= 1);
    }
    $r = page('admin/settings.php', [], ['export' => 'json'], 'counter', ['dashboard.view']);
    check('a role without settings.manage gets 403 on export', $r['http'] === 403 && !str_contains($r['body'], '"settings"'), 'HTTP ' . $r['http']);
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($rid > 0) {
        Database::query('DELETE FROM schedules WHERE route_id = :r', ['r' => $rid]);
        $sids = array_map('strval', array_column(Database::fetchAll('SELECT id FROM route_stops WHERE route_id = :r', ['r' => $rid]), 'id'));
        Database::query('DELETE FROM route_stops WHERE route_id = :r', ['r' => $rid]);
        Database::query('DELETE FROM routes WHERE id = :r', ['r' => $rid]);
        Database::query("DELETE FROM audit_logs WHERE id > :a AND (entity_id = :r
                            OR (action = 'route_stop.fares' AND entity_id = 'fares-panel' AND new_value LIKE '%U4a Seed%')
                            OR (action = 'settings.export' AND actor_id = 1 AND detail = 'Settings JSON exported'))",
            ['a' => $auditFrom, 'r' => (string) $rid]);
        foreach ($sids as $sid) {
            Database::query("DELETE FROM audit_logs WHERE id > :a AND action = 'map.stop_update' AND entity_id = :s", ['a' => $auditFrom, 's' => $sid]);
        }
    }
}
check('cleanup: the throwaway route is gone', (int) Database::scalar('SELECT COUNT(*) FROM routes WHERE route_code = :c', ['c' => $CODE], 0) === 0);

echo "\n---------- $PASS passed / $FAIL failed ----------\n";
exit($FAIL === 0 ? 0 : 1);
