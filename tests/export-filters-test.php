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
 *  Needs the dev server on :8899 and curl:
 *    php -c .claude/php-dev.ini -d extension=php_curl.dll tests/export-filters-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

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
try { Database::query("DELETE FROM rate_limits WHERE bucket LIKE 'admin_login%'"); } catch (Throwable $e) {}
$hash = password_hash($pw, PASSWORD_BCRYPT);
$id = Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $user], 0);
if ($id) {
    Database::query('UPDATE admins SET password_hash=:h, role=\'superadmin\', is_active=1, must_change_pw=0, failed_logins=0, locked_until=NULL WHERE id=:i', ['h' => $hash, 'i' => $id]);
} else {
    Database::query("INSERT INTO admins (username, password_hash, full_name, role, is_active, must_change_pw) VALUES (:u, :h, 'Export Test', 'superadmin', 1, 0)", ['u' => $user, 'h' => $hash]);
}
$lp = req('GET', '/admin/login.php');
preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m);
$li = req('POST', '/admin/login.php', ['shg_csrf' => $m[1] ?? '', 'username' => $user, 'password' => $pw]);
check('superadmin signs in', $li['code'] === 302, 'HTTP ' . $li['code']);

echo "\n=== Export CSV matches the DB for each filter ===\n";
$all = (int) Database::scalar('SELECT COUNT(*) FROM bookings', [], 0);
$csv = req('GET', '/admin/export.php');
check('unfiltered export = all bookings', csvRows($csv['body']) === min($all, 10000), 'csv=' . csvRows($csv['body']) . ' db=' . $all);

// Travel-date filter (the reported bug).
$td = Database::fetch("SELECT bl.travel_date d, COUNT(DISTINCT b.id) c
                         FROM bookings b JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                        GROUP BY bl.travel_date ORDER BY c DESC, d DESC LIMIT 1");
if ($td !== null) {
    $r = req('GET', '/admin/export.php?travel_from=' . $td['d'] . '&travel_to=' . $td['d']);
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
    $r = req('GET', '/admin/export.php?route=' . (int) $rt['rid']);
    check('route=' . $rt['rid'] . ' → ' . $rt['c'] . ' rows', csvRows($r['body']) === (int) $rt['c'], 'csv=' . csvRows($r['body']));
}

// Agent filter (supervisor scope).
$ag = Database::fetch("SELECT sold_by_admin_id aid, COUNT(*) c FROM bookings WHERE sold_by_admin_id IS NOT NULL GROUP BY sold_by_admin_id ORDER BY c DESC LIMIT 1");
if ($ag !== null) {
    $r = req('GET', '/admin/export.php?agent=' . (int) $ag['aid']);
    check('agent=' . $ag['aid'] . ' → ' . $ag['c'] . ' rows', csvRows($r['body']) === (int) $ag['c'], 'csv=' . csvRows($r['body']));
} else { echo "  SKIP  no agent-sold bookings\n"; }

// Payment status + source + status combined.
$ps = (int) Database::scalar("SELECT COUNT(*) FROM bookings b WHERE EXISTS (SELECT 1 FROM payments p2 WHERE p2.booking_id = b.id AND p2.status = 'verified')", [], 0);
$r = req('GET', '/admin/export.php?pay_status=verified');
check('pay_status=verified → ' . $ps . ' rows', csvRows($r['body']) === $ps, 'csv=' . csvRows($r['body']));
$src = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE source IN ('web','app') AND status = 'confirmed'", [], 0);
$r = req('GET', '/admin/export.php?source=online&status=confirmed');
check('source=online&status=confirmed → ' . $src . ' rows', csvRows($r['body']) === $src, 'csv=' . csvRows($r['body']));
$r = req('GET', '/admin/export.php?format=xlsx&pay_status=verified');
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

try { Database::query('DELETE FROM admins WHERE username = :u', ['u' => $user]); } catch (Throwable $e) {}
@unlink($jar);
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
