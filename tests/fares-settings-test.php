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
 *  Part B (HTTP, needs the dev server on :8899): the "Fares & booking rules"
 *  panel renders once (no duplicate generic row), and saving it writes the
 *  two fare rows AND merges the private-cabin prices into cabin_pricing.
 *
 *  Run the migration first:  php -c .claude/php-dev.ini database/upgrade-2026-09-fares.php
 *  Then: php -c .claude/php-dev.ini -d extension=php_curl.dll tests/fares-settings-test.php
 *  Restores every value it touched.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';

/* Overridable so a second worktree can be tested without stealing :8899
   from the one that already holds it — a suite pointed at ANOTHER
   checkout's server reports green for code you did not change.
       SHG_TEST_BASE=http://localhost:8898 php ... */
define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

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
    $req = function (string $method, string $url, ?array $form = null) use ($jar): array {
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
    try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
    $hash = password_hash($pw, PASSWORD_BCRYPT);
    $id = Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $user], 0);
    if ($id) {
        Database::query('UPDATE admins SET password_hash=:h, role=\'superadmin\', is_active=1, must_change_pw=0, failed_logins=0, locked_until=NULL WHERE id=:i', ['h' => $hash, 'i' => $id]);
    } else {
        Database::query("INSERT INTO admins (username, password_hash, full_name, role, is_active, must_change_pw) VALUES (:u, :h, 'Fares Test', 'superadmin', 1, 0)", ['u' => $user, 'h' => $hash]);
    }
    $lp = $req('GET', '/admin/login.php');
    $li = $req('POST', '/admin/login.php', ['shg_csrf' => $csrfOf($lp['body']), 'username' => $user, 'password' => $pw]);
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

    try { Database::query('DELETE FROM admins WHERE username = :u', ['u' => $user]); } catch (Throwable $e) {}
    @unlink($jar);
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
