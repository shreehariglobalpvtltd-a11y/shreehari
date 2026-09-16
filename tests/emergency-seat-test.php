<?php
/**
 * emergency-seat-test.php — proves the MODE-AWARE emergency berth
 * (owner decision 3 Sep 2026):
 *
 *   Private Sleeper -> L3   held back (shown, not selectable, rejected server-side)
 *   Sharing Sleeper -> L5,L6 stay held back via staffSeats() (unchanged)
 *   Sharing "L3"           stays a normal, sellable berth (different physical seat)
 *
 * Read-only: exercises Seats::emergencySeats() (pure) and the availability()
 * read path. Never writes to bookings / seat_locks / any live table.
 *
 *   php -c .claude/php-dev.ini tests/emergency-seat-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';

$PASS = 0;
$FAIL = 0;

function check(string $label, bool $ok): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  $label\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  $label\n";
    }
}

echo "\n=== Emergency berth — mode-aware (Private L3 / Sharing L5,L6) ===\n\n";

// ---------- 1. Seats::emergencySeats() pure resolution ----------
check("emergencySeats(sleeper, private) === ['L3']",
    Seats::emergencySeats('sleeper', 'private') === ['L3']);
check("emergencySeats(sleeper, sharing) === []  (sharing L3 still sells)",
    Seats::emergencySeats('sleeper', 'sharing') === []);
check("emergencySeats(sleeper, null) === []  (mode-agnostic caller blocks nothing new)",
    Seats::emergencySeats('sleeper', null) === []);
check("emergencySeats(seater, private) === []",
    Seats::emergencySeats('seater', 'private') === []);

// ---------- 2. staff pair is UNCHANGED (regression guard) ----------
check("staffSeats(sleeper) still === ['L5','L6']",
    Seats::staffSeats('sleeper') === ['L5', 'L6']);
check("staffSeatsAll() still contains L5 & L6",
    in_array('L5', Seats::staffSeatsAll(), true) && in_array('L6', Seats::staffSeatsAll(), true));
check("staffSeatsAll() does NOT contain L3 (emergency L3 is mode-aware, not staff)",
    !in_array('L3', Seats::staffSeatsAll(), true));

// ---------- 3. availability() plumbs mode-aware emergency ----------
$route = Database::fetch("SELECT id, coach_type FROM routes WHERE is_active = 1 AND coach_type = 'sleeper' ORDER BY id LIMIT 1");
if ($route !== null) {
    $rid = (int) $route['id'];
    $priv = Seats::availability($rid, '2099-11-30', 'private');
    $shar = Seats::availability($rid, '2099-11-30', 'sharing');

    check("availability(private): 'emergency' contains L3",
        in_array('L3', $priv['emergency'] ?? [], true));
    check("availability(private): L3 is NOT in available",
        !in_array('L3', $priv['available'] ?? [], true));
    check("availability(sharing): 'emergency' is empty",
        ($shar['emergency'] ?? ['x']) === []);
    check("availability(sharing): L3 IS available (normal sharing berth)",
        in_array('L3', $shar['available'] ?? [], true));
    // L5/L6 held back (via staff) in BOTH modes as before.
    check("availability(sharing): L5 held back (staff, unchanged)",
        !in_array('L5', $shar['available'] ?? [], true));
} else {
    echo "  \033[33mSKIP\033[0m  availability() plumbing — no active sleeper route on this DB\n";
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL {$PASS} PASSED\033[0m" : "\033[31m{$FAIL} FAILED\033[0m ({$PASS} passed)") . "\n\n";

exit($FAIL === 0 ? 0 : 1);
