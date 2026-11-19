<?php
/**
 * driver-pair-test.php — connect a crew phone by a one-time office link / QR (19 Nov 2026).
 *   code is 32 hex, stored hashed; redeem gives a device token without a PIN; second use,
 *   garbage and an expired code give null; a new code replaces the old one for that person;
 *   the owner-setup page makes the link + QR.
 *     php -c .claude/php-dev.ini tests/driver-pair-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }
require_once INCLUDE_PATH . '/driverauth.php';
$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $x = ''): void { global $pass, $fail; if ($ok) { $pass++; echo "  PASS  $l\n"; } else { $fail++; echo "  FAIL  $l" . ($x !== '' ? " — $x" : '') . "\n"; } }
const DP_PHONE = '9000018801';
function dp_clean(): void {
    foreach (Database::fetchAll('SELECT id FROM drivers WHERE phone = :p', ['p' => DP_PHONE]) as $r) {
        Database::run('DELETE FROM driver_devices WHERE driver_id = :d', ['d' => (int) $r['id']]);
        Database::run("DELETE FROM kv_store WHERE kscope = 'driver_pair' AND kvalue LIKE :p", ['p' => '{"d":' . (int) $r['id'] . ',%']);
        Database::run("DELETE FROM audit_logs WHERE entity_type = 'driver' AND entity_id = :i", ['i' => (string) $r['id']]);
        Database::run('DELETE FROM drivers WHERE id = :i', ['i' => (int) $r['id']]);
    }
}
register_shutdown_function('dp_clean');
dp_clean();
echo "\n=== crew phone connect by link / QR ===\n\n";
$id = (int) Database::insert('drivers', ['full_name' => 'ZZTEST Conductor', 'phone' => DP_PHONE, 'role' => 'conductor', 'is_active' => 1]);
$code = DriverAuth::pairCode($id, 1);
check('code is 32 hex', preg_match('/^[a-f0-9]{32}$/', $code) === 1);
check('only the hash is stored', (int) Database::scalar("SELECT COUNT(*) FROM kv_store WHERE kscope='driver_pair' AND kkey = :k", ['k' => hash('sha256', $code)], 0) === 1
    && (int) Database::scalar("SELECT COUNT(*) FROM kv_store WHERE kscope='driver_pair' AND (kkey = :c OR kvalue LIKE :l)", ['c' => $code, 'l' => "%$code%"], 0) === 0);
check('garbage code → null', DriverAuth::pairRedeem('zz') === null && DriverAuth::pairRedeem(str_repeat('a', 32)) === null);
$r = DriverAuth::pairRedeem($code, 'test phone');
check('redeem gives a device token with no PIN typed', is_array($r) && preg_match('/^[a-f0-9]{48}$/', (string) $r['token']) === 1 && (string) $r['driver']['role'] === 'conductor');
check('the token signs the phone in', is_array($r) && DriverAuth::current((string) $r['token']) !== null);
check('second use of the same code → null', DriverAuth::pairRedeem($code) === null);
$c1 = DriverAuth::pairCode($id, 1); $c2 = DriverAuth::pairCode($id, 1);
check('a new link replaces the old one', DriverAuth::pairRedeem($c1) === null && DriverAuth::pairRedeem($c2) !== null);
$c3 = DriverAuth::pairCode($id, 1);
Database::run("UPDATE kv_store SET kvalue = :v WHERE kscope='driver_pair' AND kkey = :k", ['v' => json_encode(['d' => $id, 'exp' => time() - 5, 'by' => 1]), 'k' => hash('sha256', $c3)]);
check('expired link → null', DriverAuth::pairRedeem($c3) === null);
$threw = false; try { DriverAuth::pairCode(999999999); } catch (Throwable $e) { $threw = true; }
check('unknown person → refused', $threw);
$ini = php_ini_loaded_file() ?: '';
$html = (string) shell_exec(escapeshellarg(PHP_BINARY) . ($ini !== '' ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__DIR__ . '/render-admin.php') . ' owner-setup.php post:act=pair post:driver_id=' . $id . ' 2>&1');
check('owner-setup makes the link + QR', (bool) preg_match('#driver/\?pair=[a-f0-9]{32}#', $html) && str_contains($html, 'data:image/png;base64,') && !preg_match('/Fatal error|Uncaught/', $html), substr(strip_tags($html), 0, 160));
$src = (string) file_get_contents(dirname(__DIR__) . '/driver/index.php');
check('driver app redeems ?pair= and clears it from the address bar', str_contains($src, "action: 'pair'") && str_contains($src, 'history.replaceState'));
echo "\n" . ($fail === 0 ? "  $pass passed, 0 failed\n" : "  $pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
