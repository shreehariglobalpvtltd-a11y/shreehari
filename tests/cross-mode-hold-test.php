<?php
/**
 * =====================================================================
 *  cross-mode-hold-test.php — 4 Sep 2026: seat HOLDS live in the physical
 *  (sharing) namespace, so a private cabin hold and the sharing beds under
 *  it block each other, exactly as committed bookings already did.
 *
 *   private Lj = physical beds L(2j-1) + L(2j)  →  L4 = L7 + L8
 *   (L3 = L5 + L6 is the reserved staff pair, so the test avoids it)
 *
 *  Run: php -c .claude/php-dev.ini tests/cross-mode-hold-test.php
 *  Touches only seat_locks on a throw-away far-future schedule.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } }

echo "\n=== Cross-mode seat HOLDS (physical namespace) ===\n\n";

$w = bookingWindow(); $D = addDaysISO($w['from'], 25);
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) { echo "  \033[33mSKIP\033[0m  no active sleeper route\n"; exit(0); }
$routeId = (int) $route['id'];

$cleanup = function () use ($D) {
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
};
$cleanup();

$sid  = (int) Seats::schedule($routeId, $D)['id'];
$rows = function () use ($sid): array {
    $out = [];
    foreach (Database::fetchAll('SELECT seat_no, lock_token FROM seat_locks WHERE schedule_id = :s ORDER BY LENGTH(seat_no), seat_no', ['s' => $sid]) as $r) {
        $out[(string) $r['seat_no']] = (string) $r['lock_token'];
    }
    return $out;
};
$A = 'xh-A'; $B = 'xh-B'; $C = 'xh-C';

try {
    // 1. private L4 by A → physical rows L7 + L8
    $r = Seats::lock($sid, ['L4'], $A, false, 'private');
    check("A holds private L4 -> ok, locked === [L4]", $r['ok'] && $r['locked'] === ['L4'], json_encode($r));
    $rw = $rows();
    check("rows are exactly L7 + L8, both token A", $rw === ['L7' => $A, 'L8' => $A], json_encode($rw));

    // 2/3. other viewers see it in their own namespace (CLI token != A)
    $sh = Seats::availability($routeId, $D, 'sharing');
    check("sharing view: L7 and L8 locked", in_array('L7', $sh['locked'], true) && in_array('L8', $sh['locked'], true), implode(',', $sh['locked']));
    check("sharing view: L7 not available", !in_array('L7', $sh['available'], true));
    $pv = Seats::availability($routeId, $D, 'private');
    check("private view: cabin L4 locked (not L7/L8 labels)", in_array('L4', $pv['locked'], true) && !in_array('L7', $pv['locked'], true), implode(',', $pv['locked']));
    check("private view: L4 not available", !in_array('L4', $pv['available'], true));

    // 4. own hold is excluded from "locked"
    $me = Auth::lockToken();
    $r = Seats::lock($sid, ['L5'], $me, false, 'private');
    check("session token holds private L5", $r['ok']);
    $pv2 = Seats::availability($routeId, $D, 'private');
    check("own hold: L5 NOT shown locked to its holder", !in_array('L5', $pv2['locked'], true), implode(',', $pv2['locked']));
    check("release own L5 (private) -> 2 rows", Seats::release($sid, ['L5'], $me, 'private') === 2);

    // 5. sharing bed under A's cabin is refused; a free bed is fine
    $r = Seats::lock($sid, ['L7'], $B, false, 'sharing');
    check("B sharing L7 (under the cabin A holds) -> failed", !$r['ok'] && $r['failed'] === ['L7'], json_encode($r));
    $r = Seats::lock($sid, ['L9'], $B, false, 'sharing');
    check("B sharing L9 -> ok", $r['ok'] && $r['locked'] === ['L9']);
    $pv3 = Seats::availability($routeId, $D, 'private');
    check("private view: cabin L5 locked because sharing L9 is held", in_array('L5', $pv3['locked'], true), implode(',', $pv3['locked']));

    // 6. partial-cabin rollback: B holds L12, C wants cabin L6 (= L11 + L12)
    $r = Seats::lock($sid, ['L12'], $B, false, 'sharing');
    check("B sharing L12 -> ok", $r['ok']);
    $r = Seats::lock($sid, ['L6'], $C, false, 'private');
    check("C private L6 -> failed (L12 belongs to B)", !$r['ok'] && $r['failed'] === ['L6'], json_encode($r));
    $rw = $rows();
    check("rollback: no row belongs to C", !in_array($C, $rw, true), json_encode($rw));
    check("rollback: L11 has no row at all", !isset($rw['L11']));
    check("L12 is still held by B", ($rw['L12'] ?? '') === $B);

    // 7. the sale gate reasons in physical space too
    check("gate: private L4 by B throws (held by A)", throws(fn() => Seats::assertAvailable($sid, ['L4'], $B, false, 'private')));
    check("gate: private L4 by A passes (own hold)", !throws(fn() => Seats::assertAvailable($sid, ['L4'], $A, false, 'private')));
    check("gate: sharing L8 by B throws (under the cabin A holds)", throws(fn() => Seats::assertAvailable($sid, ['L8'], $B, false, 'sharing')));
    check("gate: sharing L13 by B passes", !throws(fn() => Seats::assertAvailable($sid, ['L13'], $B, false, 'sharing')));

    // 8. refreshing our own cabin keeps exactly two rows
    $r = Seats::lock($sid, ['L4'], $A, false, 'private');
    $rw = $rows();
    check("A re-locks L4 -> ok, still 2 rows for the cabin", $r['ok'] && ($rw['L7'] ?? '') === $A && ($rw['L8'] ?? '') === $A);

    // 9. a cabin the bus does not have
    $r = Seats::lock($sid, ['L19'], $A, false, 'private');
    $rw = $rows();
    check("private L19 -> failed, no L37/L38 rows", !$r['ok'] && $r['failed'] === ['L19'] && !isset($rw['L37']) && !isset($rw['L38']), json_encode($r));

    // 10. release with and without a mode
    check("release L4 (private) -> 2 rows", Seats::release($sid, ['L4'], $A, 'private') === 2);
    $rw = $rows();
    check("L7/L8 gone", !isset($rw['L7']) && !isset($rw['L8']));
    Seats::lock($sid, ['L4'], $A, false, 'private');
    check("release L7 with NO mode = identity -> 1 row", Seats::release($sid, ['L7'], $A) === 1);
    $rw = $rows();
    check("L8 still held by A after the identity release", ($rw['L8'] ?? '') === $A);
    check("releaseAll(A) -> 1", Seats::releaseAll($A) === 1);

    // 11. admin map + admin force-release free the whole cabin
    Seats::lock($sid, ['L4'], $A, false, 'private');
    $map = Seats::adminSeatMap($sid, 'sleeper');
    $seatsMap = isset($map['L7']) ? $map : ($map['seats'] ?? $map);
    check("adminSeatMap: L7 held", (($seatsMap['L7']['status'] ?? '') === 'held'), json_encode($seatsMap['L7'] ?? null));
    check("adminSeatMap: L8 held with holdUntil", (($seatsMap['L8']['status'] ?? '') === 'held') && !empty($seatsMap['L8']['holdUntil']));
    $freed = Seats::adminReleaseHold($sid, 'L7', 1);
    $rw = $rows();
    check("adminReleaseHold(L7) frees both beds of the cabin", $freed === 2 && !isset($rw['L7']) && !isset($rw['L8']), "freed={$freed} rows=" . json_encode($rw));
    // the sibling bed of ANOTHER holder is untouched
    Seats::lock($sid, ['L15'], $B, false, 'sharing');
    Seats::lock($sid, ['L16'], $C, false, 'sharing');
    $freed = Seats::adminReleaseHold($sid, 'L15', 1);
    $rw = $rows();
    check("adminReleaseHold(L15) leaves the L16 held by C alone", $freed === 1 && ($rw['L16'] ?? '') === $C, "freed={$freed} rows=" . json_encode($rw));
} catch (Throwable $e) {
    check('suite ran without a fatal', false, $e->getMessage());
} finally {
    $cleanup();
}

echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
