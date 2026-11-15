<?php
/**
 * =====================================================================
 *  u2a-search-next-time-test.php — 16 Nov 2026 (P4-2f, master upgrade v2.0).
 *
 *  api/search.php rolls a spent departure forward to the next date and the
 *  board says "Next available departure: <date>, <time>". The time used to
 *  be a hard-coded 1:00 PM in the browser; the server now answers
 *  nextAvailableTime = Boarding::timeForStop(<the rolled bus>, <searched stop>).
 *
 *  Runs the REAL endpoint in a child PHP process (no web server needed:
 *  Response::field() reads $_POST). To force a roll-over at any hour it sets
 *  boarding_cutoff_minutes = 1439 for a moment (every pickup of TODAY is then
 *  closed) and puts the old value back in a finally block. Nothing else is
 *  written. LOCAL shari_test only.
 *
 *     php -c .claude/php-dev.ini tests/u2a-search-next-time-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: answer one search, exactly as the endpoint would ---- */
if (($argv[1] ?? '') === '--child') {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['REMOTE_ADDR']    = '127.0.0.' . (20 + random_int(0, 200));   // own rate-limit bucket per call
    $_POST = (array) json_decode((string) base64_decode((string) ($argv[2] ?? '')), true);
    require dirname(__DIR__) . '/api/search.php';
    exit(0);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/boarding.php';

$PASS = 0; $FAIL = 0; $SKIP = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function skip(string $l): void { global $SKIP; $SKIP++; echo "  SKIP  $l\n"; }

/** Run api/search.php once in a child process; the decoded JSON envelope. */
function search(array $body): array {
    $ini = php_ini_loaded_file();
    $cmd = [PHP_BINARY];
    if ($ini !== false) { $cmd[] = '-c'; $cmd[] = $ini; }
    array_push($cmd, __FILE__, '--child', base64_encode((string) json_encode($body)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($p)) { return ['_raw' => 'proc_open failed']; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $j = json_decode(trim($out), true);
    if (!is_array($j) && preg_match('/\{.*\}\s*$/s', $out, $m)) { $j = json_decode($m[0], true); }   // a notice printed before the JSON
    return is_array($j) ? $j : ['_raw' => substr($out . ' ' . $err, 0, 300)];
}
function data(array $env): array { return (array) ($env['data'] ?? $env); }

echo "\n=== api/search.php nextAvailableTime (P4-2f) ===\n\n";

$today    = todayISO();
$tomorrow = addDaysISO($today, 1);

/* ---- 1. no roll-over: the key is there and empty ---- */
$r = data(search(['from' => 'Surat', 'to' => 'Rupaidiha', 'date' => $tomorrow]));
if (!array_key_exists('results', $r)) {
    check('search answers (Surat -> Rupaidiha, tomorrow)', false, json_encode($r));
} else {
    check('search answers (Surat -> Rupaidiha, tomorrow)', true, 'count=' . (int) ($r['count'] ?? 0));
    check('no roll-over for a future date', ($r['nextAvailable'] ?? null) === false);
    check("nextAvailableTime is present and '' when nothing rolled", array_key_exists('nextAvailableTime', $r) && $r['nextAvailableTime'] === '', var_export($r['nextAvailableTime'] ?? null, true));
}

/* ---- 2. force today's pickups shut, then the board rolls to tomorrow ---- */
$had = Database::fetch("SELECT svalue, stype FROM settings WHERE skey = 'boarding_cutoff_minutes' LIMIT 1");
try {
    if ($had === null) {
        Database::query("INSERT INTO settings (skey, svalue, stype, sgroup, is_public) VALUES ('boarding_cutoff_minutes', '1439', 'int', 'booking', 0)");
    } else {
        Database::query("UPDATE settings SET svalue = '1439' WHERE skey = 'boarding_cutoff_minutes'");
    }

    $cases = [
        ['Barauda', 'Rupaidiha', 'a later Gujarat pickup (Barauda)'],
        ['Rupaidiha', 'Surat', 'the return bus (Rupaidiha -> Surat)'],
    ];
    foreach ($cases as [$from, $to, $what]) {
        $r = data(search(['from' => $from, 'to' => $to, 'date' => $today]));
        if (!array_key_exists('results', $r)) { check("search answers for $what", false, json_encode($r)); continue; }
        if (($r['nextAvailable'] ?? false) !== true) {
            skip("$what: the board did not roll on this database (count=" . (int) ($r['count'] ?? 0) . ') - nothing to check');
            continue;
        }
        $time = (string) ($r['nextAvailableTime'] ?? '');
        $want = [];
        foreach ((array) ($r['results'] ?? []) as $res) {
            $tm = Boarding::timeForStop((int) ($res['routeId'] ?? 0), $from);
            if ($tm !== null) { $want[] = substr($tm, 0, 5); }
        }
        $want = array_values(array_unique($want));
        check("$what: rolled to the next date", ($r['date'] ?? '') > $today, (string) ($r['date'] ?? ''));
        check("$what: nextAvailableTime is HH:MM", (bool) preg_match('/^\d{2}:\d{2}$/', $time), var_export($time, true));
        check("$what: it is that stop's own time on the rolled bus", in_array($time, $want, true), $time . ' vs ' . implode('/', $want));
        check("$what: nextAvailableStop is the searched stop", ($r['nextAvailableStop'] ?? '') === $from);
        if ($from === 'Barauda' || $from === 'Rupaidiha') {
            check("$what: not the old hard-coded 13:00", $time !== '13:00', $time);
        }
    }
} finally {
    if ($had === null) {
        Database::query("DELETE FROM settings WHERE skey = 'boarding_cutoff_minutes'");
    } else {
        Database::query('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $had['svalue'], 'k' => 'boarding_cutoff_minutes']);
    }
}
$back = Database::fetch("SELECT svalue FROM settings WHERE skey = 'boarding_cutoff_minutes' LIMIT 1");
check('boarding_cutoff_minutes put back as it was', $had === null ? $back === null : ($back !== null && $back['svalue'] === $had['svalue']));

echo "\n" . ($FAIL === 0 ? "ALL PASSED: $PASS" . ($SKIP ? " (skipped $SKIP)" : '') : "FAILED: $FAIL (passed $PASS, skipped $SKIP)") . "\n";
exit($FAIL === 0 ? 0 : 1);
