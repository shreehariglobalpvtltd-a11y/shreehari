<?php
/**
 * =====================================================================
 *  export-filters-test.php — Module 2 (3 Sep 2026): the bookings export
 *  honours EVERY filter the on-screen list has (the "export date bug").
 *
 *  For each filter it counts the matching bookings straight from the DB and
 *  compares with the number of data rows in the CSV export.php returns for
 *  the same query string. Also checks the PDF path accepts the filters, and
 *  that agent-sales.php's export link carries the agent id + the correct
 *  inclusive end date for the "Yesterday" preset.
 *
 *  Needs curl, and SHG_TEST_BASE=http://localhost:<port> (else the suite
 *  starts its own php -S on this checkout):
 *    php -c .claude/php-dev.ini -d extension=php_curl.dll tests/export-filters-test.php
 *  Removes its throwaway superadmin with its sessions / login rows (and the
 *  sessions any older or crashed run left), and the admin_login throttle row
 *  its sign-in counts for this machine.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
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
$jar = sys_get_temp_dir() . '/shg_export_' . getmypid() . '.cookies'; @unlink($jar);
function req(string $method, string $url, ?array $form = null): array {
    global $jar;
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    if ($form !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $body = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'type' => $type];
}
/** Data rows in a CSV body (BOM + header stripped). */
function csvRows(string $body): int {
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;
    $lines = array_values(array_filter(preg_split('/\r?\n/', trim($body)) ?: [], static fn($l) => $l !== ''));
    return max(0, count($lines) - 1);
}

// Throwaway superadmin.
$user = 'export-super'; $pw = 'Export@12345';
/* 16 Nov 2026 (battery T1): an HTTP sign-in opens an admin_sessions row and a
   login-history row; neither has a foreign key to admins, so deleting the
   account alone left both behind on every run. */
/* 16 Nov 2026 (T1 refix): every admins.id this username has ever had - the live
   row, the login history (read BEFORE dropUser() deletes it: once the account is
   gone it is the only link from the name to an id) and the audit trail
   (actor_name = the username; it outlives both, so the sessions of an older or
   crashed run are found too - deleting by the CURRENT id alone left them for
   good). An id that is now a different live account is never included. */
function userIds(string $user): array {
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
}
function dropUser(string $user): void {
    $ids = userIds($user);
    if ($ids !== []) {
        try { Database::query('DELETE FROM admin_sessions WHERE admin_id IN (' . implode(',', $ids) . ')'); } catch (Throwable $e) {}
    }
    try { Database::delete('admin_login_events', 'username = :u', ['u' => $user]); } catch (Throwable $e) {}
    try { Database::query('DELETE FROM admins WHERE username = :u', ['u' => $user]); } catch (Throwable $e) {}
}
/* the classic door counts every sign-in in the admin_login bucket of the caller's
   IP (Auth::adminLogin): cleared before signing in and again at the end (T1 refix) */
const LOGIN_BUCKETS = "'admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try'";
function clearLoginThrottle(): void {
    try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN (" . LOGIN_BUCKETS . ')'); } catch (Throwable $e) {}
}
dropUser($user);
clearLoginThrottle();
$hash = password_hash($pw, PASSWORD_BCRYPT);
$id = (int) Database::insert('admins', ['username' => $user, 'password_hash' => $hash, 'full_name' => 'Export Test',
    'role' => 'superadmin', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
/* 16 Nov 2026 (battery T1): the staff door is code-first since 15 Nov - an
   account with no PIN / pattern is sent to pin-setup.php before any page (so
   export.php answered 302 and every CSV came back empty). The reader gets a
   PIN, as a real office login has, and signs in at the CLASSIC door
   (?classic=1&other=1, classic=1 posted). */
QuickUnlock::set($id, 'pin', '615273');
$lp = req('GET', '/admin/login.php?classic=1&other=1');
preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
$li = req('POST', '/admin/login.php', ['shg_csrf' => $m[1] ?? '', 'classic' => '1', 'username' => $user, 'password' => $pw]);
check('superadmin signs in', $li['code'] === 302, 'HTTP ' . $li['code']);

/* 16 Nov 2026 (battery T1): other suites write bookings on this same test DB
   while this one runs, so "the DB count" can move between the COUNT and the
   export. Count before and after the request and retry (up to 4 times) until
   both agree - the CSV is then compared with that one stable number, exactly. */
function stableExport(string $url, callable $count): array {
    for ($try = 1; $try <= 4; $try++) {
        $before = (int) $count();
        $r      = req('GET', $url);
        $after  = (int) $count();
        if ($before === $after) { break; }
    }
    return $r + ['db' => $after, 'rows' => csvRows($r['body'])];
}

echo "\n=== Export CSV matches the DB for each filter ===\n";
$csv = stableExport('/admin/export.php', static fn(): int => (int) Database::scalar('SELECT COUNT(*) FROM bookings', [], 0));
$all = $csv['db'];
check('unfiltered export = all bookings', csvRows($csv['body']) === min($all, 10000), 'csv=' . csvRows($csv['body']) . ' db=' . $all);

// Travel-date filter (the reported bug).
$td = Database::fetch("SELECT bl.travel_date d, COUNT(DISTINCT b.id) c
                         FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                        GROUP BY bl.travel_date ORDER BY c DESC, d DESC LIMIT 1");
if ($td !== null) {
    $r = stableExport('/admin/export.php?travel_from=' . $td['d'] . '&travel_to=' . $td['d'], static fn(): int => (int) Database::scalar(
        "SELECT COUNT(DISTINCT b.id) FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound' WHERE bl.travel_date = :d",
        ['d' => $td['d']], 0));
    $td['c'] = $r['db'];
    check('travel_from/travel_to = ' . $td['d'] . ' → ' . $td['c'] . ' rows', csvRows($r['body']) === (int) $td['c'], 'csv=' . csvRows($r['body']));
    check('… and the travel filter actually narrowed the file', (int) $td['c'] === $all || csvRows($r['body']) < csvRows($csv['body']));
    $pdf = req('GET', '/admin/export.php?format=pdf&travel_from=' . $td['d'] . '&travel_to=' . $td['d']);
    check('PDF export accepts the same filters', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'HTTP ' . $pdf['code'] . ' ' . $pdf['type']);
} else { echo "  SKIP  no bookings with an outbound leg\n"; }

// Route filter.
$rt = Database::fetch("SELECT s.route_id rid, COUNT(DISTINCT b.id) c
                         FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                         JOIN schedules s ON s.id = bl.schedule_id GROUP BY s.route_id ORDER BY c DESC LIMIT 1");
if ($rt !== null) {
    $r = stableExport('/admin/export.php?route=' . (int) $rt['rid'], static fn(): int => (int) Database::scalar(
        "SELECT COUNT(DISTINCT b.id) FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
           JOIN schedules s ON s.id = bl.schedule_id WHERE s.route_id = :r", ['r' => (int) $rt['rid']], 0));
    $rt['c'] = $r['db'];
    check('route=' . $rt['rid'] . ' → ' . $rt['c'] . ' rows', csvRows($r['body']) === (int) $rt['c'], 'csv=' . csvRows($r['body']));
}

// Agent filter (supervisor scope).
$ag = Database::fetch("SELECT sold_by_admin_id aid, COUNT(*) c FROM bookings WHERE sold_by_admin_id IS NOT NULL GROUP BY sold_by_admin_id ORDER BY c DESC LIMIT 1");
if ($ag !== null) {
    $r = stableExport('/admin/export.php?agent=' . (int) $ag['aid'], static fn(): int => (int) Database::scalar(
        'SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :a', ['a' => (int) $ag['aid']], 0));
    $ag['c'] = $r['db'];
    check('agent=' . $ag['aid'] . ' → ' . $ag['c'] . ' rows', csvRows($r['body']) === (int) $ag['c'], 'csv=' . csvRows($r['body']));
} else { echo "  SKIP  no agent-sold bookings\n"; }

// Payment status + source + status combined.
$psCount = static fn(): int => (int) Database::scalar("SELECT COUNT(*) FROM bookings b WHERE EXISTS (SELECT 1 FROM payments p2 WHERE p2.booking_id = b.id AND p2.status = 'verified')", [], 0);
$r  = stableExport('/admin/export.php?pay_status=verified', $psCount);
$ps = $r['db'];
check('pay_status=verified → ' . $ps . ' rows', csvRows($r['body']) === $ps, 'csv=' . csvRows($r['body']));
$r   = stableExport('/admin/export.php?source=online&status=confirmed', static fn(): int => (int) Database::scalar(
    "SELECT COUNT(*) FROM bookings WHERE source IN ('web','app') AND status = 'confirmed'", [], 0));
$src = $r['db'];
check('source=online&status=confirmed → ' . $src . ' rows', csvRows($r['body']) === $src, 'csv=' . csvRows($r['body']));
$r  = stableExport('/admin/export.php?format=xlsx&pay_status=verified', $psCount);
$ps = $r['db'];
// export.php serves native XLSX only when ZipArchive is loaded; otherwise the same filtered CSV.
check('xlsx export still served with filters', $r['code'] === 200 && (str_starts_with($r['body'], 'PK') || csvRows($r['body']) === $ps), 'HTTP ' . $r['code'] . (str_starts_with($r['body'], 'PK') ? ' xlsx' : ' csv-fallback'));

echo "\n=== agent-sales.php export link ===\n";
$as = req('GET', '/admin/agent-sales.php?range=yesterday');
check('agent-sales renders', $as['code'] === 200, 'HTTP ' . $as['code']);
if (preg_match('~/admin/export\.php\?([^"]+)~', $as['body'], $lm)) {
    parse_str(html_entity_decode($lm[1]), $qs);
    $yesterday = addDaysISO(todayISO(), -1);
    check('"Yesterday" export ends on yesterday (was: today as well)', ($qs['to'] ?? '') === $yesterday, 'to=' . ($qs['to'] ?? '?') . ' expected ' . $yesterday);
    check('"Yesterday" export starts on yesterday', ($qs['from'] ?? '') === $yesterday, 'from=' . ($qs['from'] ?? '?'));
    check('export link carries the viewed agent id (supervisor scope)', isset($qs['agent']) && ctype_digit((string) $qs['agent']), 'agent=' . ($qs['agent'] ?? 'missing'));
} else {
    check('export link found on agent-sales.php', false);
}

dropUser($user);
clearLoginThrottle();
@unlink($jar);
$left = (int) Database::scalar('SELECT COUNT(*) FROM admin_login_events WHERE username = :u', ['u' => $user], 0)
      + (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE id = :a', ['a' => $id], 0);
check('cleanup leaves no account or login row behind', $left === 0, "$left left");
// T1 refix: every id the test user has had (this run's and any older run's, via the audit trail)
$ids  = array_values(array_unique(array_merge([$id], userIds($user))));
$sess = (int) Database::scalar('SELECT COUNT(*) FROM admin_sessions WHERE admin_id IN (' . implode(',', array_map('intval', $ids)) . ')', [], 0);
check("cleanup leaves no admin_sessions row for any account {$user} has had (this run or an older one)", $sess === 0,
    "$sess left over " . count($ids) . ' id(s)');
$rl = (int) Database::scalar("SELECT COUNT(*) FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN (" . LOGIN_BUCKETS . ')', [], 0);
check('cleanup leaves no login-throttle row for this machine (its sign-in counted in admin_login)', $rl === 0, "$rl left");
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
