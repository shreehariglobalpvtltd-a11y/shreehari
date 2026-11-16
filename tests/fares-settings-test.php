<?php
/**
 * =====================================================================
 *  fares-settings-test.php — Module 2 (3 Sep 2026): fares are editable
 *  from Admin → Settings, without code.
 *
 *  Part A (service level): Fare::dirFares() follows the fare_to_nepal /
 *  fare_to_india settings rows, and a zero/blank row falls back to the
 *  code default instead of pricing a ticket at ₹0.
 *
 *  Part B (HTTP: SHG_TEST_BASE=http://localhost:<port>, else the suite starts
 *  its own php -S on this checkout): the "Fares & booking rules"
 *  panel renders once (no duplicate generic row), and saving it writes the
 *  two fare rows AND merges the private-cabin prices into cabin_pricing.
 *
 *  Run the migration first:  php -c .claude/php-dev.ini database/upgrade-2026-09-fares.php
 *  Then: php -c .claude/php-dev.ini -d extension=php_curl.dll tests/fares-settings-test.php
 *  Restores every value it touched (the settings rows byte for byte at the
 *  end) and removes its throwaway superadmin with its sessions / login rows
 *  (and the sessions any older or crashed run left), and the admin_login
 *  throttle row its sign-in counts for this machine.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/quickunlock.php';

/* Overridable so a second worktree can be tested without stealing :8899
   from the one that already holds it — a suite pointed at ANOTHER
   checkout's server reports green for code you did not change.
       SHG_TEST_BASE=http://localhost:8898 php ...
   16 Nov 2026 (battery T1): with no SHG_TEST_BASE the suite starts its OWN
   `php -S` on a free port, serving THIS checkout with this run's php + ini,
   and stops it on exit (same helper as manifest-test.php) - the battery
   passes no base, and :8899 is whichever checkout happens to own it. */
function shg_test_base(): string
{
    $env = trim((string) getenv('SHG_TEST_BASE'));
    if ($env !== '') {
        return rtrim($env, '/');
    }
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        return 'http://localhost:8899';
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    $ini  = (string) php_ini_loaded_file();
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd  = array_merge([PHP_BINARY], $ini !== '' ? ['-c', $ini] : [], ['-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)]);
    $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return 'http://localhost:8899';
    }
    register_shutdown_function(static function () use ($proc): void {
        @proc_terminate($proc);
        @proc_close($proc);
    });
    for ($i = 0; $i < 100; $i++) {   // up to ~10 s for the server to answer
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c !== false) { fclose($c); break; }
        usleep(100000);
    }
    return 'http://127.0.0.1:' . $port;
}
define('BASE', shg_test_base());

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* 16 Nov 2026 (battery T1): every settings row this suite writes (Part A sets
   them with Settings::set - which can also change the row's type/group - and
   Part B through the form), saved raw now and put back raw at the end, so a
   failed save in the middle can never leave a test fare on the shared DB. */
$TOUCHED = ['fare_to_nepal', 'fare_to_india', 'main_fares', 'cabin_pricing', 'max_seats_per_booking', 'counter_max_discount_pct'];
$rawPrior = [];
foreach ($TOUCHED as $k) {
    $rawPrior[$k] = Database::fetch('SELECT svalue, stype, sgroup, label, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restoreRaw = static function () use ($TOUCHED, $rawPrior): void {
    foreach ($TOUCHED as $k) {
        $row = $rawPrior[$k];
        try {
            if ($row === null) {
                Database::delete('settings', 'skey = :k', ['k' => $k]);
            } else {
                Database::update('settings', ['svalue' => $row['svalue'], 'stype' => (string) $row['stype'], 'sgroup' => (string) $row['sgroup'],
                    'label' => $row['label'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]);
            }
        } catch (Throwable $e) {}
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};

echo "\n=== A. Fare::dirFares() follows the settings rows ===\n";
$origN    = Settings::get('fare_to_nepal', null);
$origI    = Settings::get('fare_to_india', null);
$origMain = Settings::get('main_fares', null);
$origCp   = Settings::getArray('cabin_pricing', []);
check('fare rows are seeded (run database/upgrade-2026-09-fares.php first)', $origN !== null && $origI !== null,
    'toNepal=' . var_export($origN, true) . ' toIndia=' . var_export($origI, true));

// Isolate from the legacy in-app override while testing.
if ($origMain !== null) { Settings::set('main_fares', [], 'json', 'pricing', false); }

Settings::set('fare_to_nepal', 2100, 'float', 'pricing', true);
Settings::set('fare_to_india', 1900, 'float', 'pricing', true);
$d = Fare::dirFares();
check('toNepal follows fare_to_nepal (2100)', (int) $d['toNepal'] === 2100, 'got ' . $d['toNepal']);
check('toIndia follows fare_to_india (1900)', (int) $d['toIndia'] === 1900, 'got ' . $d['toIndia']);

Settings::set('fare_to_nepal', 0, 'float', 'pricing', true);
$d = Fare::dirFares();
check('a zero row falls back to the code default (2000), never ₹0', (int) $d['toNepal'] === 2000, 'got ' . $d['toNepal']);

Settings::set('fare_to_nepal', 2100, 'float', 'pricing', true);
$route = Database::fetch("SELECT to_city FROM routes WHERE is_active = 1 AND coach_type = 'sleeper' ORDER BY id LIMIT 1");
if ($route !== null) {
    $cf = Fare::cabinFare('single', 'sharing', 1, false, (string) $route['to_city']);
    $pp = (int) round((float) ($cf['perPerson'] ?? 0));
    check('sharing per-person counter fare uses the edited direction fares', in_array($pp, [2100, 1900], true), 'perPerson=' . $pp . ' to ' . $route['to_city']);
} else {
    echo "  SKIP  no active sleeper route for the per-person check\n";
}

// Restore A.
Settings::set('fare_to_nepal', $origN !== null ? $origN : 2000, 'float', 'pricing', true);
Settings::set('fare_to_india', $origI !== null ? $origI : 1800, 'float', 'pricing', true);
if ($origMain !== null) { Settings::set('main_fares', $origMain, 'json', 'pricing', false); }
$d = Fare::dirFares();
check('restored', (int) $d['toNepal'] === (int) ($origN ?? 2000) && (int) $d['toIndia'] === (int) ($origI ?? 1800), $d['toNepal'] . '/' . $d['toIndia']);

echo "\n=== B. Admin → Settings fares panel (HTTP) ===\n";
if (!function_exists('curl_init')) {
    echo "  SKIP  curl extension not loaded (run with -d extension=php_curl.dll)\n";
} else {
    $jar = sys_get_temp_dir() . '/shg_fares_' . getmypid() . '.cookies'; @unlink($jar);
    $req = function (string $method, string $url, array $form = null) use ($jar): array {
        $ch = curl_init(BASE . $url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 30]);
        if ($form !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
        $body = (string) curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['code' => $code, 'body' => $body];
    };
    $csrfOf = static fn(string $h): string => preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $h, $m) ? $m[1] : '';

    // Throwaway superadmin, like e2e-booking-test.php.
    $user = 'fares-super'; $pw = 'Fares@12345';
    /* 16 Nov 2026 (battery T1): an HTTP sign-in opens an admin_sessions row and a
       login-history row; neither has a foreign key to admins, so deleting the
       account alone left both behind on every run. */
    /* 16 Nov 2026 (T1 refix): every admins.id this username has ever had - the
       live row, the login history (read BEFORE $dropUser deletes it: once the
       account is gone it is the only link from the name to an id) and the audit
       trail (actor_name = the username; it outlives both, so the sessions of an
       older or crashed run are found too - deleting by the CURRENT id alone left
       them for good). An id that is now a different live account is never included. */
    $userIds = static function () use ($user): array {
        $ids = [(int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $user], 0)];
        foreach (['SELECT DISTINCT admin_id FROM admin_login_events WHERE username = :u AND admin_id IS NOT NULL',
                  "SELECT DISTINCT actor_id FROM audit_logs WHERE actor_type = 'admin' AND actor_name = :u AND actor_id IS NOT NULL"] as $sql) {
            try {
                foreach (Database::fetchAll($sql, ['u' => $user]) as $r) { $ids[] = (int) reset($r); }
            } catch (Throwable $e) {}
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $taken = array_map('intval', array_column(Database::fetchAll(
            'SELECT id FROM admins WHERE id IN (' . implode(',', $ids) . ') AND username <> :u', ['u' => $user]), 'id'));
        return array_values(array_diff($ids, $taken));
    };
    $dropUser = static function () use ($user, $userIds): void {
        $ids = $userIds();
        if ($ids !== []) {
            try { Database::query('DELETE FROM admin_sessions WHERE admin_id IN (' . implode(',', $ids) . ')'); } catch (Throwable $e) {}
        }
        try { Database::delete('admin_login_events', 'username = :u', ['u' => $user]); } catch (Throwable $e) {}
        try { Database::query('DELETE FROM admins WHERE username = :u', ['u' => $user]); } catch (Throwable $e) {}
    };
    /* the classic door counts every sign-in in the admin_login bucket of the caller's
       IP (Auth::adminLogin): cleared before signing in and again at the end (T1 refix) */
    $loginBuckets = "'admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try'";
    $clearThrottle = static function () use ($loginBuckets): void {
        try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ({$loginBuckets})"); } catch (Throwable $e) {}
    };
    $dropUser();
    $clearThrottle();
    $hash = password_hash($pw, PASSWORD_BCRYPT);
    $id = (int) Database::insert('admins', ['username' => $user, 'password_hash' => $hash, 'full_name' => 'Fares Test',
        'role' => 'superadmin', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    /* 16 Nov 2026 (battery T1): the staff door is code-first since 15 Nov - an
       account with no PIN / pattern is sent to pin-setup.php before any page, so
       the reader gets one, as a real office login has. The username + password
       form is the CLASSIC door (?classic=1&other=1, classic=1 posted). */
    QuickUnlock::set($id, 'pin', '615273');
    $lp = $req('GET', '/admin/login.php?classic=1&other=1');
    $li = $req('POST', '/admin/login.php', ['shg_csrf' => $csrfOf($lp['body']), 'classic' => '1', 'username' => $user, 'password' => $pw]);
    check('superadmin signs in', $li['code'] === 302, 'HTTP ' . $li['code']);

    $sp = $req('GET', '/admin/settings.php');
    check('settings page renders', $sp['code'] === 200, 'HTTP ' . $sp['code']);
    check('fares panel present', str_contains($sp['body'], 'id="faresPanel"'));
    check('fare_to_nepal input rendered exactly once (no duplicate generic row)', substr_count($sp['body'], 'name="s[fare_to_nepal]"') === 1,
        'count=' . substr_count($sp['body'], 'name="s[fare_to_nepal]"'));
    check('private cabin inputs rendered', str_contains($sp['body'], 'name="fp_private_single"') && str_contains($sp['body'], 'name="fp_private_double"'));
    check('max seats + discount cap live in the fares panel', substr_count($sp['body'], 'name="s[max_seats_per_booking]"') === 1 && substr_count($sp['body'], 'name="s[counter_max_discount_pct]"') === 1);

    $csrf = $csrfOf($sp['body']);
    $origSingle = (float) ($origCp['private']['single_1pax']['offline'] ?? 3800);
    $origDouble = (float) ($origCp['private']['double_2pax']['offline'] ?? 7600);
    $post = $req('POST', '/admin/settings.php', [
        'shg_csrf' => $csrf, '_boolkeys' => '',
        's' => ['fare_to_nepal' => '2150', 'fare_to_india' => '1950',
                'max_seats_per_booking' => (string) Settings::getInt('max_seats_per_booking', 6),
                'counter_max_discount_pct' => (string) Settings::getFloat('counter_max_discount_pct', 15.0)],
        'fp_private_single' => '3900', 'fp_private_double' => '7700',
    ]);
    check('save returns the page with a success flash', $post['code'] === 200 && str_contains($post['body'], 'Settings saved'), 'HTTP ' . $post['code']);
    $dbN = Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => 'fare_to_nepal'], '');
    $dbI = Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => 'fare_to_india'], '');
    check('fare_to_nepal stored = 2150', (int) (float) $dbN === 2150, 'db=' . $dbN);
    check('fare_to_india stored = 1950', (int) (float) $dbI === 1950, 'db=' . $dbI);
    $cpDb = json_decode((string) Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => 'cabin_pricing'], '{}'), true) ?: [];
    check('private single merged into cabin_pricing (offline = online = 3900)',
        (int) ($cpDb['private']['single_1pax']['offline'] ?? 0) === 3900 && (int) ($cpDb['private']['single_1pax']['online'] ?? 0) === 3900);
    check('private double merged into cabin_pricing (7700)', (int) ($cpDb['private']['double_2pax']['offline'] ?? 0) === 7700);
    check('sharing block of cabin_pricing untouched', ($cpDb['sharing'] ?? null) == ($origCp['sharing'] ?? null));
    check('the page re-renders the saved values', str_contains($post['body'], 'value="2150"') && str_contains($post['body'], 'value="3900"'));

    // Restore B via the same form so the code path is exercised twice.
    $sp2 = $req('GET', '/admin/settings.php');
    $req('POST', '/admin/settings.php', [
        'shg_csrf' => $csrfOf($sp2['body']), '_boolkeys' => '',
        's' => ['fare_to_nepal' => (string) ($origN ?? 2000), 'fare_to_india' => (string) ($origI ?? 1800),
                'max_seats_per_booking' => (string) Settings::getInt('max_seats_per_booking', 6),
                'counter_max_discount_pct' => (string) Settings::getFloat('counter_max_discount_pct', 15.0)],
        'fp_private_single' => (string) $origSingle, 'fp_private_double' => (string) $origDouble,
    ]);
    $dbN2 = Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => 'fare_to_nepal'], '');
    check('restored via the form', (int) (float) $dbN2 === (int) ($origN ?? 2000), 'db=' . $dbN2);

    $dropUser();
    $clearThrottle();
    @unlink($jar);
    $left = (int) Database::scalar('SELECT COUNT(*) FROM admin_login_events WHERE username = :u', ['u' => $user], 0)
          + (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE id = :a', ['a' => $id], 0);
    check('cleanup leaves no account or login row behind', $left === 0, "$left left");
    // T1 refix: every id the test user has had (this run's and any older run's, via the audit trail)
    $ids  = array_values(array_unique(array_merge([$id], $userIds())));
    $sess = (int) Database::scalar('SELECT COUNT(*) FROM admin_sessions WHERE admin_id IN (' . implode(',', array_map('intval', $ids)) . ')', [], 0);
    check("cleanup leaves no admin_sessions row for any account {$user} has had (this run or an older one)", $sess === 0,
        "$sess left over " . count($ids) . ' id(s)');
    $rl = (int) Database::scalar("SELECT COUNT(*) FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ({$loginBuckets})", [], 0);
    check('cleanup leaves no login-throttle row for this machine (its sign-in counted in admin_login)', $rl === 0, "$rl left");
}

// 16 Nov 2026 (battery T1): every touched settings row back exactly as it was
$restoreRaw();
$drift = [];
foreach ($TOUCHED as $k) {
    $now = Database::fetch('SELECT svalue, stype, sgroup, label, is_public FROM settings WHERE skey = :k', ['k' => $k]);
    if ($now != $rawPrior[$k]) { $drift[] = $k; }
}
check('every settings row it touched is back byte for byte', $drift === [], implode(', ', $drift));

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
