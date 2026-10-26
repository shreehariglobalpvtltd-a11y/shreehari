<?php
/**
 * Counter/agent 24h late-booking grace — TripStatus::counterBookableWithin()
 * (owner ask: an agent may still cut a ticket on a departed bus for 24h after
 * its scheduled departure). Deterministic via an injected NOW against a real
 * schedule row. CLI only.
 *   php -c .claude/php-dev.ini tests/counter-grace-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/tripstatus.php';

$P = 0; $F = 0;
function ok(string $l, bool $c): void { global $P, $F; if ($c) { $P++; echo "  \033[32mPASS\033[0m  $l\n"; } else { $F++; echo "  \033[31mFAIL\033[0m  $l\n"; } }

const TD = '2026-08-30';          // fixed departure day
const DEP = '13:00:00';           // Surat 1pm
$DEP_TS = strtotime(TD . ' ' . DEP);

function cleanup(): void {
    foreach (pluck(Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => TD]), 'id') as $sid) {
        Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $sid]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
}

echo "\n=== Counter 24h grace (counterBookableWithin) ===\n\n";

$sid = 0;
try {
    $route = Database::fetch("SELECT id FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no sleeper route\n"; exit(1); }
    $rid = (int) $route['id'];

    cleanup();
    $sid = (int) Seats::schedule($rid, TD)['id'];
    // Pin the effective departure to 13:00 regardless of the route default.
    Database::update('schedules', ['dep_time_override' => DEP, 'status' => 'scheduled'], 'id = :i', ['i' => $sid]);

    // Before departure → already counter-bookable, NOT via grace.
    $g = TripStatus::counterBookableWithin($sid, 24, $DEP_TS - 2 * 3600);
    ok('2h BEFORE dep → ok, not grace', ($g['ok'] === true) && ($g['within_grace'] === false));

    // 1h after departure → running, still ok (not grace).
    $g = TripStatus::counterBookableWithin($sid, 24, $DEP_TS + 3600);
    ok('1h AFTER dep → ok (running)', $g['ok'] === true);

    // 20h after departure (overnight bus has arrived) → ok VIA grace.
    $g = TripStatus::counterBookableWithin($sid, 24, $DEP_TS + 20 * 3600);
    ok('20h after dep → still ok (inside 24h window)', $g['ok'] === true);

    // 23h59m after → still open.
    $g = TripStatus::counterBookableWithin($sid, 24, $DEP_TS + 24 * 3600 - 60);
    ok('23h59m after dep → still ok', $g['ok'] === true);

    // 24h01m after → window closed.
    $g = TripStatus::counterBookableWithin($sid, 24, $DEP_TS + 24 * 3600 + 60);
    ok('24h01m after dep → CLOSED', $g['ok'] === false);

    // 30h after → closed.
    $g = TripStatus::counterBookableWithin($sid, 24, $DEP_TS + 30 * 3600);
    ok('30h after dep → CLOSED', $g['ok'] === false);

    // Cancelled trip is NEVER reopened by the grace, even inside 24h.
    Database::update('schedules', ['status' => 'cancelled'], 'id = :i', ['i' => $sid]);
    $g = TripStatus::counterBookableWithin($sid, 24, $DEP_TS + 3 * 3600);
    ok('cancelled + 3h after → CLOSED (grace never resurrects a cancelled trip)', $g['ok'] === false);
    Database::update('schedules', ['status' => 'scheduled'], 'id = :i', ['i' => $sid]);

    // scheduledDepartureTs resolves the override.
    ok('scheduledDepartureTs uses dep_time_override', TripStatus::scheduledDepartureTs($sid) === $DEP_TS);

} catch (Throwable $e) {
    ok('unexpected error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), false);
} finally {
    cleanup();
}

echo "\n  $P passed, $F failed\n";
exit($F === 0 ? 0 : 1);
