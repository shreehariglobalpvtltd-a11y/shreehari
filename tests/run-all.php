<?php
/**
 * =====================================================================
 *  run-all.php — the pre-deploy test battery, in one command.
 *
 *      php -c .claude/php-dev.ini tests/run-all.php
 *      php -c .claude/php-dev.ini tests/run-all.php --http    (adds the
 *                                     suites that need the :8899 server)
 *      php -c .claude/php-dev.ini tests/run-all.php --list
 *
 *  Until now the battery existed only as a list in
 *  MASTER-PROMPT-2026-08-27.md:186-191, which meant "run the tests"
 *  depended on remembering eight filenames, remembering to clear
 *  rate_limits first, and remembering which suites need curl. Anything
 *  you have to remember before a deploy is something you will one day
 *  skip.
 *
 *  SAFETY
 *  The battery WRITES: e2e-booking-test.php creates a real booking, and
 *  several suites insert and roll back seat locks. Pointed at production
 *  it would put fake bookings in the live register. So this runner
 *  refuses to start unless the connected database is unmistakably a test
 *  database — see assertTestDatabase(). That check is the reason this
 *  file exists as a runner rather than a shell alias.
 *
 *  Each suite is a standalone script that exits 0 on success and 1 on any
 *  failure. This runner adds nothing to that contract; it just sequences
 *  them, restores the one piece of shared state they all trip over
 *  (rate_limits), and reports.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
// clearStaleUnitLocks() needs the seat engine; bootstrap does not load it.
require_once INCLUDE_PATH . '/seats.php';

/* ---------------------------------------------------------------------
 *  The battery. Order matters only in that the slow HTTP suites run last.
 * ------------------------------------------------------------------- */

/**
 * The eight documented in MASTER-PROMPT-2026-08-27.md:186-191, plus any
 * later suite that guards a money or auth path and needs only the database.
 */
const CORE_SUITES = [
    'e2e-booking-test.php'         => 'whole ticket lifecycle over real HTTP',
    'agent-wallet-test.php'        => 'commission ledger, no double-claim',
    'gender-lock-test.php'         => 'shared-cabin gender locking',
    'seat-transfer-test.php'       => 'moving a seat keeps the money straight',
    'paid-pending-hold-test.php'   => 'a paid seat is never swept by expiry',
    'manifest-test.php'            => 'the boarding manifest matches the sale',
    'double-booking-race-test.php' => 'two racers, one seat, DB-level firewall',
    'launch-fixes-test.php'        => 'the launch-day regression set',
    // added 2026-09-06 with the fix it guards
    'forced-password-change-test.php' => 'a temporary password cannot be kept',
    'otp-forced-password-test.php' => '...and the OTP door cannot skip that',
    'asset-version-test.php'       => 'no ?v= stamp has drifted from sw.js',
    'staff-any-date-test.php'      => 'staff may book any date; customers may not',
    'quick-ticket-test.php'        => 'name + mobile → auto bus/seat/fare → confirmed ticket',
    'ticket-bot-test.php'          => 'AI Ticket Bot: learns from verified sales, never lifts a rule',
    'brain-test.php'               => 'Passive Brain: predicts the calls, parks the requests, never sells',
    'notify-country-test.php'      => 'the ticket WhatsApp/SMS reaches the right country (+977 vs +91)',

    /* Registered 8 Sep 2026. These 33 suites existed and passed but were never
       in the battery — 35 of the 53 test files in this directory were orphans,
       so "20 passed, 0 failed" was reporting on barely a third of the tests
       actually written. Nothing here is new work; it is the coverage that was
       already paid for and simply not being run. Notably cross-mode-seat-sync
       and cross-mode-hold guard the Private/Sharing physical-bed rule — the one
       invariant that stops the coach being oversold — and neither was running. */
    'bot-parse-test.php'           => 'the smart line reads a real desk message',
    'bot-ladder-test.php'          => 'the bot asks ONE question when unsure, in the writer language; same-as-last berths',
    'seat-mode-map-test.php'       => 'the mode↔bed mapping is a configurable rule set',
    'cross-mode-integrity-test.php' => 'admin seat mutations reason in beds, not labels',
    'cross-mode-seat-sync-test.php' => 'private cabin ⇄ sharing beds: one physical inventory',
    'cross-mode-hold-test.php'     => 'a hold in one mode blocks the bed in the other',
    'seat-layout-test.php'         => 'one 4+2 physical shape everywhere',
    'seat-block-test.php'          => 'a berth taken out of service stays out',
    'per-seat-cancel-test.php'     => 'cancelling one seat leaves the rest intact',
    'emergency-seat-test.php'      => 'the emergency berth is never sold',
    'women-only-test.php'          => 'women-reserved berths refuse a male passenger',
    'reschedule-test.php'          => 'moving a booking to another date',
    'agent-loans-test.php'         => 'loans & advances register agrees with the ledger, cap enforced',
    'agent-kyc-test.php'           => 'KYC verdicts are stamped; the selling gate only bites when switched on',
    'wa-send-scope-test.php'       => 'WhatsApp sends: an agent only about themselves and their own sales, the office about anyone',
    'boarding-other-test.php'      => 'an "Other" pickup / drop is typed, cleaned and printed; configured stops unchanged',
    'agent-360-render-test.php'    => 'the Agent 360 hub renders every tab for every role',
    'passenger-docs-test.php'      => 'passenger ID documents attach, stream behind the gate, and remove',
    'tripstatus-test.php'          => 'the 9-state departure ladder',
    'missed-bus-test.php'          => 'the 24h grace after a departure',
    'counter-mode-test.php'        => 'the desk sells through the same engine',
    'counter-role-test.php'        => 'the counter role sees only what it may',
    'counter-grace-test.php'       => 'counter may sell past the public cut-off',
    'counter-discount-test.php'    => 'counter discount is capped server-side',
    'agent-isolation-test.php'     => 'an agent never reads another agent’s book',
    'agent-controls-test.php'      => 'agent limits hold under pressure',
    'agent-kind-test.php'          => 'org vs person agent numbering',
    'agent-advance-test.php'       => 'agent advances and the ledger',
    'agent-reassign-test.php'      => 'reassigning a seller moves the commission once',
    'agent-late-book-test.php'     => 'the agent grace window',
    'agent-login-bulk-cancel-test.php' => 'agent sign-in + bulk cancel',
    'public-agent-code-resolver-test.php' => 'a customer-typed SHG-### resolves safely',
    'admin-security-test.php'      => 'the admin door refuses what it should',
    'admin-2fa-test.php'           => 'opt-in admin 2FA',
    'login-name-mobile-test.php'   => 'sign in by name + mobile',
    'settings-json-test.php'       => 'stype=json rows survive a save uneaten',
    'fares-settings-test.php'      => 'fare panel writes what the engine reads',
    'bs-calendar-test.php'         => 'Bikram Sambat calendar conversion',
    'bus-calendar-slot-test.php'   => 'extra buses live in their own slot',
    'export-filters-test.php'      => 'a register exports exactly what it shows',
    'ticket-png-test.php'          => 'the PNG ticket renders and stays fresh',
    'challan-png-test.php'         => 'the bus challan PNG draws the whole coach and stays fresh',
    'chalani-png-test.php'         => 'the chalani PNG pages + who cut the ticket (agent code)',
    'report-pdf-test.php'          => 'report PDFs render',
    'trip-reminder-test.php'       => 'the departure reminder fires once',

    /* Registered with the code they guard (Phase 0 of the self-improving
       system, 8 Sep 2026). Registered in the SAME commit as the feature on
       purpose — a suite added later is a suite that spends its first weeks
       not running. */
    'gemvault-test.php'            => 'the customer vault: one gem per human, sealed from other agents',
    'governor-spine-test.php'      => 'the AI may only touch the data lane; every fault names itself',
    'delivery-sentinel-test.php'   => 'a delivery outage names the right fault, in owner language',
    // 13 Sep 2026, registered with the PWA master upgrade it guards.
    'webpush-test.php'             => 'Web Push: RFC 8291 vectors, VAPID signature, subscription store',
];

/**
 * Suites that exist and are NOT run, with the reason. Declared rather than
 * silently omitted: an unlisted test file is invisible, and this directory
 * already lost 35 suites that way. Printed at the end of every run.
 *
 * Both entries below fail on ORIGINAL code at cec7dc1 — verified 8 Sep 2026 by
 * reverting and re-running — so they are stale tests, not regressions:
 *   automation-test.php     7 of 49 fail
 *   boarding-stop-fix-test.php  3 of 17 fail: it hard-codes seat L1, which the
 *       later women-only rule now reserves, so book.php correctly returns 409.
 */
const KNOWN_STALE = [
    'automation-test.php'          => '7/49 fail on unmodified code — stale expectations',
    'boarding-stop-fix-test.php'   => '3/17 fail: hard-codes L1, now women-reserved',
];

/**
 * Node suites. They need neither the database nor the dev server, so they
 * run every time — but only when node is on PATH; a missing node is
 * reported as a skip rather than silently shrinking the battery.
 */
const NODE_SUITES = [
    'i18n-check.js'          => 'en/hi/ne key parity',
    'offline-ticket-test.js' => 'the ticket survives a deploy and works offline',
];

/** Need the dev server on :8899 as well as the database. */
const HTTP_SUITES = [
    'e2e-booking-test.php'        => 'whole ticket lifecycle over real HTTP',
    'role-gates-test.php'         => 'every admin page enforces its permission',
    'counter-mode-http-test.php'  => 'staff selling through the customer SPA',
    'feedback-test.php'           => 'post-journey rating: who may rate, and once',
    'beacon-test.php'             => 'the product beacon stores behaviour, never people',
    'pwa-http-test.php'           => 'PWA layer: push key + subscribe gates, seat fill, split-pay, offline page',
];

/* ---------------------------------------------------------------------
 *  Guard: never let the battery touch anything but a test database.
 * ------------------------------------------------------------------- */

/**
 * Three independent checks, all of which must pass. Any one of them alone
 * is defeatable by a plausible mistake — a production config copied to a
 * dev box still says APP_ENV=production, and a test database can be
 * created on a remote host.
 */
function assertTestDatabase(): void
{
    $name = defined('DB_NAME') ? (string) DB_NAME : '';
    $host = defined('DB_HOST') ? (string) DB_HOST : '';
    $env  = defined('APP_ENV') ? (string) APP_ENV : '';

    $problems = [];

    if (stripos($name, 'test') === false) {
        $problems[] = "database name '{$name}' does not contain 'test'";
    }

    // DB_HOST may carry a port suffix, e.g. '127.0.0.1;port=3307'.
    $bareHost = strtolower(trim(explode(';', $host)[0]));
    if (!in_array($bareHost, ['127.0.0.1', 'localhost', '::1', ''], true)) {
        $problems[] = "database host '{$bareHost}' is not local";
    }

    if (strtolower($env) === 'production') {
        $problems[] = "APP_ENV is 'production'";
    }

    if ($problems === []) {
        return;
    }

    fwrite(STDERR, "\n  REFUSING TO RUN — this does not look like a test database.\n\n");
    foreach ($problems as $p) {
        fwrite(STDERR, "    - {$p}\n");
    }
    fwrite(STDERR, <<<TXT

    The battery writes real rows: e2e-booking-test.php creates a booking.
    Against production that puts fake sales in the live register.

    Expected: config/config.local.php pointing at 127.0.0.1;port=3307,
    database shari_test. Start the server with .claude/start-db.bat.

    To target a restored dump instead:
        SHG_DB_NAME=shg_restore_test php -c .claude/php-dev.ini tests/run-all.php


    TXT);
    exit(2);
}

/* ---------------------------------------------------------------------
 *  Shared state the suites trip over.
 * ------------------------------------------------------------------- */

/**
 * Every login-related suite authenticates repeatedly from one IP and hits
 * the 5-per-300s admin_login throttle, so a second run of the battery
 * fails for a reason that has nothing to do with the code under test.
 * Documented in reference-local-test-traps: clear it before every run.
 */
function clearRateLimits(): string
{
    try {
        Database::pdo()->exec('DELETE FROM rate_limits');
        return 'cleared';
    } catch (Throwable $e) {
        return 'could not clear (' . $e->getMessage() . ')';
    }
}

/**
 * The SECOND piece of shared state the suites trip over.
 *
 * schedule_unit_locks holds the shared-cabin gender lock. In production it is
 * always recomputed when a cabin empties, because seats are released through
 * Seats::releaseBooking(). Test cleanups, however, tidy up with a raw
 * `DELETE FROM bookings` — which cascades booking_seats but calls no PHP — so
 * every suite that does that can leave a lock behind on a cabin that is now
 * empty. The next suite to want that berth is then refused with "this shared
 * cabin is held for women only", and the failure looks like a code bug in
 * whatever ran second.
 *
 * That made the battery ORDER-DEPENDENT, which is the one thing a battery may
 * not be: a green run has to mean the code is good, not that the suites
 * happened to run in a lucky sequence. Only locks whose cabin has no live
 * seats are dropped, so a genuine lock is never touched.
 */
function clearStaleUnitLocks(): string
{
    try {
        $rows = Database::fetchAll(
            "SELECT schedule_id, unit_key FROM schedule_unit_locks WHERE gender_lock <> 'none'"
        );
        $dropped = 0;
        foreach ($rows as $r) {
            $sid     = (int) $r['schedule_id'];
            $members = Seats::unitSeats((string) $r['unit_key'], Seats::coachForSchedule($sid));
            if ($members === []) {
                continue;
            }
            $ph = [];
            $p  = ['s' => $sid];
            foreach ($members as $i => $m) {
                $ph[]      = ':m' . $i;
                $p['m' . $i] = $m;
            }
            $live = (int) Database::scalar(
                'SELECT COUNT(*) FROM booking_seats
                  WHERE schedule_id = :s AND released_at IS NULL
                    AND seat_no IN (' . implode(',', $ph) . ')',
                $p,
                0
            );
            if ($live === 0) {
                Database::run(
                    'DELETE FROM schedule_unit_locks WHERE schedule_id = :s AND unit_key = :u',
                    ['s' => $sid, 'u' => (string) $r['unit_key']]
                );
                $dropped++;
            }
        }
        return $dropped === 0 ? 'none stale' : ('cleared ' . $dropped . ' on empty cabins');
    } catch (Throwable $e) {
        return 'could not clear (' . $e->getMessage() . ')';
    }
}

/* ---------------------------------------------------------------------
 *  Runner
 * ------------------------------------------------------------------- */

/**
 * Run one suite as a child process using the SAME binary and ini as this
 * one — the winget PHP build loads no php.ini by default, so a child
 * started without -c would fatal in bootstrap.php on a missing PDO driver.
 *
 * @return array{code:int, output:string, seconds:float}
 */
function runSuite(string $file, array $extraIni = []): array
{
    $testsDir = __DIR__;
    $php      = PHP_BINARY;
    $ini      = php_ini_loaded_file();

    $cmd = escapeshellarg($php);
    if ($ini !== false) {
        $cmd .= ' -c ' . escapeshellarg($ini);
    }
    foreach ($extraIni as $directive) {
        $cmd .= ' -d ' . escapeshellarg($directive);
    }
    $cmd .= ' ' . escapeshellarg($testsDir . DIRECTORY_SEPARATOR . $file);

    $started = microtime(true);
    $output  = [];
    $code    = 0;
    exec($cmd . ' 2>&1', $output, $code);

    return [
        'code'    => $code,
        'output'  => implode("\n", $output),
        'seconds' => microtime(true) - $started,
    ];
}

/** Same as runSuite(), for a Node suite. */
function runNodeSuite(string $file): array
{
    $cmd     = 'node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . $file);
    $started = microtime(true);
    $output  = [];
    $code    = 0;
    exec($cmd . ' 2>&1', $output, $code);

    return ['code' => $code, 'output' => implode("
", $output), 'seconds' => microtime(true) - $started];
}

/** Pull the suite's own "N passed, M failed" line out for the summary. */
function tally(string $output): string
{
    if (preg_match('/(\d+)\s+passed,\s+(\d+)\s+failed/i', $output, $m) === 1) {
        return "{$m[1]} passed, {$m[2]} failed";
    }
    if (preg_match('/PASS\s+(\d+)\s+WARN\s+(\d+)\s+FAIL\s+(\d+)/i', $output, $m) === 1) {
        return "PASS {$m[1]} WARN {$m[2]} FAIL {$m[3]}";
    }
    return '';
}

/* ---------------------------------------------------------------------
 *  main
 * ------------------------------------------------------------------- */

$args     = array_slice($argv, 1);
$withHttp = in_array('--http', $args, true);
$listOnly = in_array('--list', $args, true);

$suites = CORE_SUITES;
if ($withHttp) {
    $suites = CORE_SUITES + HTTP_SUITES; // '+' keeps the first occurrence, so no duplicate e2e
}

if ($listOnly) {
    echo "\n  battery (" . count($suites) . " suites)\n\n";
    foreach ($suites as $file => $what) {
        printf("    %-32s %s\n", $file, $what);
    }
    echo "
  node suites (always run): " . implode(', ', array_keys(NODE_SUITES)) . "
";
    echo "\n  --http adds: " . implode(', ', array_diff(array_keys(HTTP_SUITES), array_keys(CORE_SUITES))) . "\n";
    echo "  those need the dev server on :8899 (preview_start \"shari-php\").\n";
    echo "\n  NOT run (" . count(KNOWN_STALE) . "):\n";
    foreach (KNOWN_STALE as $file => $why) {
        printf("    %-32s %s\n", $file, $why);
    }
    echo "\n";
    exit(0);
}

assertTestDatabase();

echo "\n";
echo "========================================================\n";
echo "  S Hari Global — pre-deploy battery\n";
echo "========================================================\n";
echo '  database : ' . (defined('DB_NAME') ? DB_NAME : '?')
     . ' on ' . (defined('DB_HOST') ? DB_HOST : '?') . "\n";
echo '  php      : ' . PHP_VERSION . ' (' . (php_ini_loaded_file() ?: 'no ini') . ")\n";
echo '  suites   : ' . count($suites) . ($withHttp ? " (including HTTP)\n" : "\n");
echo '  rate_limits: ' . clearRateLimits() . "\n";
echo '  unit locks : ' . clearStaleUnitLocks() . "\n";
echo "--------------------------------------------------------\n\n";

// curl is not in the committed php-dev.ini on every machine, and the HTTP
// suites are useless without it. Force it on rather than failing obscurely.
$extraIni = [];
if (!extension_loaded('curl')) {
    $extraIni[] = 'extension=php_curl.dll';
    echo "  note: curl not loaded — passing -d extension=php_curl.dll to children\n\n";
}

$missing = [];
$failed  = [];
$passed  = [];
$totalT  = 0.0;

foreach ($suites as $file => $what) {
    $path = __DIR__ . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        $missing[] = $file;
        printf("  MISSING  %-32s (no such file)\n", $file);
        continue;
    }

    printf("  running  %-32s %s\n", $file, $what);
    $r       = runSuite($file, $extraIni);
    $totalT += $r['seconds'];
    $note    = tally($r['output']);

    if ($r['code'] === 0) {
        $passed[] = $file;
        printf("     ok    %-32s %5.1fs  %s\n\n", '', $r['seconds'], $note);
    } else {
        $failed[$file] = $r['output'];
        printf("     FAIL  %-32s %5.1fs  %s  (exit %d)\n\n", '', $r['seconds'], $note, $r['code']);
    }
}

/* ---- Node suites ---------------------------------------------------- */
$probe = []; $pc = 0;
exec('node --version 2>&1', $probe, $pc);

if ($pc !== 0) {
    echo "  SKIPPED  node not on PATH — " . implode(', ', array_keys(NODE_SUITES)) . "

";
    $missing[] = 'node (' . count(NODE_SUITES) . ' suites)';
} else {
    foreach (NODE_SUITES as $file => $what) {
        if (!is_file(__DIR__ . DIRECTORY_SEPARATOR . $file)) {
            $missing[] = $file;
            printf("  MISSING  %-32s (no such file)
", $file);
            continue;
        }
        printf("  running  %-32s %s
", $file, $what);
        $r = runNodeSuite($file);
        $totalT += $r['seconds'];
        $note = tally($r['output']);
        if ($r['code'] === 0) {
            $passed[] = $file;
            printf("     ok    %-32s %5.1fs  %s

", '', $r['seconds'], $note);
        } else {
            $failed[$file] = $r['output'];
            printf("     FAIL  %-32s %5.1fs  %s  (exit %d)

", '', $r['seconds'], $note, $r['code']);
        }
    }
}

echo "--------------------------------------------------------\n";
printf("  %d passed, %d failed, %d missing   (%.1fs)\n",
    count($passed), count($failed), count($missing), $totalT);
echo "========================================================\n";

/* Say out loud what the battery did NOT run. A green line above means
   nothing if two suites quietly sat outside it. */
if (KNOWN_STALE !== []) {
    echo "\n  Deliberately NOT run (" . count(KNOWN_STALE) . " stale suites):\n";
    foreach (KNOWN_STALE as $file => $why) {
        printf("    %-32s %s\n", $file, $why);
    }
}

if ($failed !== []) {
    echo "\n  Output from the failing suites:\n";
    foreach ($failed as $file => $out) {
        echo "\n  ── {$file} " . str_repeat('─', max(0, 40 - strlen($file))) . "\n";
        // The tail is where the assertions and the summary live.
        $lines = explode("\n", rtrim($out));
        foreach (array_slice($lines, -25) as $line) {
            echo '  ' . $line . "\n";
        }
    }
    echo "\n";
}

if (!$withHttp) {
    echo "\n  Not run: " . implode(', ', array_diff(array_keys(HTTP_SUITES), array_keys(CORE_SUITES)))
       . "\n  Add --http (needs the dev server on :8899).\n\n";
}

exit($failed === [] && $missing === [] ? 0 : 1);
