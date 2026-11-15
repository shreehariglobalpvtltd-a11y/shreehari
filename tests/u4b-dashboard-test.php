<?php
/**
 * =====================================================================
 *  u4b-dashboard-test.php — read-only office views (16 Nov 2026):
 *    P8-9b  CommandCenter::revenue()['ytd'], the YTD line on the dashboard
 *           revenue tile, the "This year" chip on Analytics
 *    P8-9c  the dashboard chart: 30 days, revenue bars + bookings line
 *    P4-6a  Bus Control tabs: Stops & times, Offers, Fares (read only)
 *    P4-2g  the boarding_cutoff_minutes settings row (30, Booking group)
 *
 *  Writes nothing of its own (the pages are opened as a super-admin in a
 *  child process; their own "recent page" row is removed after).
 *
 *    php -c .claude/php-dev.ini tests/u4b-dashboard-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $page = (string) ($cfg['page'] ?? '');
    if (!in_array($page, ['index', 'analytics', 'bus-control'], true)) { exit(3); }
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.79';
    $_SERVER['SCRIPT_NAME']    = '/admin/' . $page . '.php';
    $_SERVER['REQUEST_URI']    = '/admin/' . $page . '.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = (array) ($cfg['get'] ?? []);
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    require dirname(__DIR__) . '/admin/' . $page . '.php';
    exit(0);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/commandcenter.php';
require_once INCLUDE_PATH . '/boarding.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function child(array $cfg): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}
$noWarn = static fn(string $h): bool => !preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $h);

echo "\n=== U4b dashboard, analytics, Bus Control, cut-off row ===\n\n";
$super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
if ($super <= 0) {
    echo "  \033[33mSKIP\033[0m  needs an active super-admin\n";
    exit(0);
}
$hadNav = Database::fetch("SELECT kvalue FROM kv_store WHERE kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $super]);

try {
    echo "-- P8-9b year to date\n";
    $today = todayISO();
    $rev   = CommandCenter::revenue($today);
    $want  = round((float) Database::scalar("SELECT COALESCE(SUM(total_amount), 0) FROM bookings WHERE status = 'confirmed' AND confirmed_at >= :a AND confirmed_at < :b",
        ['a' => substr($today, 0, 4) . '-01-01', 'b' => addDaysISO($today, 1)], 0), 2);
    check('revenue() has ytd = confirmed revenue 1 Jan .. today', array_key_exists('ytd', $rev) && abs($rev['ytd'] - $want) < 0.01, $rev['ytd'] . ' vs ' . $want);
    check('ytd is never below mtd', $rev['ytd'] + 0.001 >= $rev['mtd']);
    $idx = child(['page' => 'index', 'admin' => $super]);
    check('the dashboard revenue tile shows YTD under MTD', str_contains($idx, 'YTD · यो वर्ष <b>' . Security::e(inr($rev['ytd'])) . '</b>'));

    echo "\n-- P8-9c 30-day chart\n";
    check('the chart panel says 30 days', str_contains($idx, 'Revenue &amp; bookings — last 30 days'));
    check('one SVG with 30 revenue bars and a bookings line', substr_count($idx, '<rect class="sb"') === 30 && str_contains($idx, '<polyline class="sl"'));
    check('each bar names the day, the money and the bookings', preg_match('/<title>\d{1,2} \w{3}: ₹[\d,.]+ · \d+ bookings?<\/title>/u', $idx) === 1);
    check('a link to the full 30-day Analytics', str_contains($idx, 'href="/admin/analytics.php?range=30d"'));
    check('no PHP warning on the dashboard', $noWarn($idx));

    $an = child(['page' => 'analytics', 'admin' => $super, 'get' => ['range' => 'year']]);
    check('Analytics: a "This year" chip, active for ?range=year', preg_match('/class="chip on" href="[^"]*range=year" aria-current="true">This year</', $an) === 1);
    check('Analytics: the window runs from 1 January', str_contains($an, '1 Jan – '));
    check('no PHP warning on Analytics (year)', $noWarn($an));

    echo "\n-- P4-6a Bus Control tabs\n";
    $bc = child(['page' => 'bus-control', 'admin' => $super, 'get' => ['tab' => 'stops']]);
    check('the tab strip carries Stops & times, Offers and Fares', str_contains($bc, 'tab=stops') && str_contains($bc, 'tab=offers') && str_contains($bc, 'tab=fares'));
    $noGps = (int) Database::scalar("SELECT COUNT(*) FROM route_stops s JOIN routes r ON r.id = s.route_id WHERE s.is_active = 1 AND r.is_active = 1
        AND (s.latitude IS NULL OR s.longitude IS NULL OR (s.latitude = 0 AND s.longitude = 0))", [], 0);
    check('Stops: the "no map point" count matches the stops table', preg_match('/No map point · GPS छैन<\/small><b>' . $noGps . '<\/b>/u', $bc) === 1, (string) $noGps);
    check('Stops: links to Routes & stops, no form posts from this tab', str_contains($bc, 'href="/admin/routes.php#pickups"') && !str_contains($bc, 'name="act" value="stop'));
    check('no PHP warning on the Stops tab', $noWarn($bc));
    $bo = child(['page' => 'bus-control', 'admin' => $super, 'get' => ['tab' => 'offers']]);
    check('Offers: running offers + the three discount switches + a link to Offers', str_contains($bo, 'Offers running today') && str_contains($bo, 'Group discount · समूह छुट')
        && str_contains($bo, 'href="/admin/offers.php"'));
    check('no PHP warning on the Offers tab', $noWarn($bo));
    $bf = child(['page' => 'bus-control', 'admin' => $super, 'get' => ['tab' => 'fares']]);
    check('Fares: route fares + a link to Settings → Fares', str_contains($bf, 'href="/admin/settings.php#faresPanel"') && str_contains($bf, 'Pickups with own fare'));
    check('no PHP warning on the Fares tab', $noWarn($bf));

    echo "\n-- P4-2g cut-off row\n";
    $row = Database::fetch("SELECT * FROM settings WHERE skey = 'boarding_cutoff_minutes'");
    check('the row exists (run database/upgrade-2026-11-16-boarding-cutoff.sql) in Booking as an int',
        $row !== null && (string) $row['stype'] === 'int' && (string) $row['sgroup'] === 'booking' && trim((string) $row['label']) !== '', json_encode($row));
    check('Boarding::cutoffMinutes() reads it (30 unless the owner changed it)', $row !== null && Boarding::cutoffMinutes() === max(0, (int) $row['svalue']));
} catch (Throwable $e) {
    check('test ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    // the pages note themselves in the super-admin's recent-first menu: put it back as it was
    if ($hadNav === null) {
        Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $super]);
    } else {
        Database::update('kv_store', ['kvalue' => $hadNav['kvalue']], "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $super]);
    }
}
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
