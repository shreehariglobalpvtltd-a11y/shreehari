<?php
/**
 * =====================================================================
 *  staff-door-test.php — one link for counter, agent and office (27 Sep 2026).
 *
 *      php tests/run-all.php --http      (needs the dev server on :8899)
 *
 *    1. /desk, /staff and /desk/ send a stranger to the sign-in page,
 *       which returns to Quick Ticket; the answer is never cached
 *    2. a signed-in counter, agent and manager land on Quick Ticket
 *    3. an account that still owes its forced password change is NOT let
 *       into Quick Ticket through the short link
 * =====================================================================
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
date_default_timezone_set('Asia/Kolkata');

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: getenv('SHG_BASE') ?: 'http://localhost:8899'), '/'));
const SD_PW = 'StaffDoor@12345';

function sd_req(string $method, string $url, string $jar, array $form = []): array
{
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
    ]);
    if ($form) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hsz);
    return [
        'code'     => $code,
        'body'     => substr($raw, $hsz),
        'location' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '',
        'cache'    => preg_match('/^Cache-Control:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '',
    ];
}

function sd_pdo(): PDO
{
    static $p = null;
    if ($p === null) {
        if (!defined('DB_NAME')) {
            $local = dirname(__DIR__) . '/config/config.local.php';
            if (is_file($local)) {
                if (!defined('SHG_APP')) { define('SHG_APP', true); }
                require_once $local;
            }
        }
        $host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1:3307';
        $name = defined('DB_NAME') ? (string) DB_NAME : 'shari_test';
        $user = defined('DB_USER') ? (string) DB_USER : 'root';
        $pass = defined('DB_PASS') ? (string) DB_PASS : '';
        if (stripos($name, 'test') === false) {
            throw new RuntimeException("Refusing: $name is not a test database");
        }
        [$h, $port] = array_pad(explode(':', $host, 2), 2, '3306');
        $p = new PDO("mysql:host=$h;port=$port;dbname=$name;charset=utf8mb4", $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $p;
}

function sd_staff(string $username, string $role, int $mustChange = 0): void
{
    $hash = password_hash(SD_PW, PASSWORD_BCRYPT);
    $q = sd_pdo()->prepare('SELECT id FROM admins WHERE username = ?');
    $q->execute([$username]);
    if ($id = $q->fetchColumn()) {
        sd_pdo()->prepare('UPDATE admins SET password_hash=?, role=?, is_active=1, must_change_pw=?, failed_logins=0, locked_until=NULL WHERE id=?')
            ->execute([$hash, $role, $mustChange, $id]);
    } else {
        sd_pdo()->prepare('INSERT INTO admins (username, password_hash, full_name, phone, role, is_active, must_change_pw) VALUES (?,?,?,?,?,1,?)')
            ->execute([$username, $hash, 'StaffDoor ' . ucfirst($role), null, $role, $mustChange]);
    }
}

function sd_jar(string $name): string
{
    $p = sys_get_temp_dir() . '/shg_sd_' . $name . '_' . getmypid() . '.cookies';
    @unlink($p);
    return $p;
}

function sd_login(string $user, string $jar): bool
{
    $lp  = sd_req('GET', '/admin/login.php', $jar);
    $tok = preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m) ? $m[1] : '';
    $li  = sd_req('POST', '/admin/login.php', $jar, ['shg_csrf' => $tok, 'username' => $user, 'password' => SD_PW]);
    return $li['code'] === 302;
}

$pass = 0; $fail = 0;
function sd_check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

$USERS = ['sd-counter' => ['counter', 0], 'sd-agent' => ['agent', 0], 'sd-manager' => ['manager', 0], 'sd-newpw' => ['counter', 1]];
try {
    try { sd_pdo()->exec("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
    foreach ($USERS as $u => [$role, $must]) { sd_staff($u, $role, $must); }

    echo "\n=== 1. a stranger ===\n";
    foreach (['/desk', '/staff', '/desk/'] as $path) {
        $r = sd_req('GET', $path, sd_jar('guest'));
        sd_check("$path → sign-in page that returns to Quick Ticket", $r['code'] === 302
            && str_ends_with($r['location'], '/admin/login.php?next=quick-ticket.php'), $r['code'] . ' ' . $r['location']);
        sd_check("$path is never cached", str_contains($r['cache'], 'no-store'), $r['cache']);
    }
    $lp = sd_req('GET', '/admin/login.php?next=quick-ticket.php', sd_jar('guest2'));
    sd_check('the sign-in page accepts next=quick-ticket.php', $lp['code'] === 200 && str_contains($lp['body'], 'quick-ticket.php'));

    echo "\n=== 2. signed-in selling roles ===\n";
    foreach (['sd-counter', 'sd-agent', 'sd-manager'] as $u) {
        $jar = sd_jar($u);
        $in  = sd_login($u, $jar);
        $r   = sd_req('GET', '/desk', $jar);
        sd_check("$u ({$USERS[$u][0]}) signs in and /desk lands on Quick Ticket", $in && $r['code'] === 302
            && str_ends_with($r['location'], '/admin/quick-ticket.php'), $r['code'] . ' ' . $r['location']);
        $q = sd_req('GET', '/admin/quick-ticket.php', $jar);
        sd_check("…and Quick Ticket opens for $u", $q['code'] === 200, 'HTTP ' . $q['code']);
    }

    echo "\n=== 3. a forced password change still comes first ===\n";
    $jar = sd_jar('newpw');
    sd_login('sd-newpw', $jar);
    sd_req('GET', '/desk', $jar);
    $q = sd_req('GET', '/admin/quick-ticket.php', $jar);
    sd_check('an account that owes its password change does not reach Quick Ticket', $q['code'] !== 200, $q['code'] . ' ' . $q['location']);
} catch (Throwable $e) {
    sd_check('no exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    try {
        $in = implode(',', array_fill(0, count($USERS), '?'));
        // Deactivated, not deleted: login logs and device rows point at them.
        sd_pdo()->prepare("UPDATE admins SET is_active = 0 WHERE username IN ($in)")->execute(array_keys($USERS));
    } catch (Throwable $e) {}
}

echo "\n  staff-door: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
