<?php
/**
 * seat-mode-map-test.php — the mode↔bed mapping is a RULE SET, not a literal
 * (owner ask, point 7, 8 Sep 2026: "apply this type of mapping through
 * configurable rules rather than hard-coding one example").
 *
 * Part 1  the default rule set reproduces the live coach exactly — including
 *         the owner's own example, sharing L11/L12 ⇄ private L6.
 * Part 2  a DIFFERENT ratio changes the mapping, the seat count and the layout
 *         with no code edit at all — only a settings row.
 * Part 3  `explicit` expresses an irregular cabin a pure ratio cannot, and wins
 *         over the formula in BOTH directions.
 * Part 4  a broken rule set throws instead of silently reshaping the bus.
 * Part 5  mapChangeImpact() names the already-sold berths a change would move.
 *
 *   php -c .claude/php-dev.ini tests/seat-mode-map-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

const SMM_PHONE = '910000772';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(callable $fn): bool { try { $fn(); return false; } catch (Throwable $e) { return true; } }

/* The seeded seat_mode_map row is REAL configuration (database/
   upgrade-2026-09-seat-mode-map.sql). This suite rewrites it repeatedly, so it
   snapshots the row first and puts it back verbatim at the end — an earlier
   draft simply DELETEd it, which silently removed the migration's row and left
   the bus running on the built-in default. A test must not be able to
   un-configure the thing it is testing. */
$SMM_ORIGINAL = Database::fetch(
    "SELECT svalue, stype, sgroup, label, is_public FROM settings WHERE skey = 'seat_mode_map'"
);

/** Install a rule set and drop the memo, as a settings save would. */
function useMap(array $map): void {
    Settings::set('seat_mode_map', $map, 'json', 'seats', true);
    Seats::forgetModeMap();
}
/** Remove the row, to exercise the "no configuration at all" path. */
function clearMap(): void {
    Database::run("DELETE FROM settings WHERE skey = 'seat_mode_map'");
    Seats::forgetModeMap();
}
/** Put the row back exactly as it was found. */
function restoreMap(): void {
    global $SMM_ORIGINAL;
    Database::run("DELETE FROM settings WHERE skey = 'seat_mode_map'");
    if ($SMM_ORIGINAL !== null) {
        Database::run(
            "INSERT INTO settings (skey, svalue, stype, sgroup, label, is_public)
             VALUES ('seat_mode_map', :v, :t, :g, :l, :p)",
            ['v' => $SMM_ORIGINAL['svalue'], 't' => $SMM_ORIGINAL['stype'], 'g' => $SMM_ORIGINAL['sgroup'],
             'l' => $SMM_ORIGINAL['label'], 'p' => (int) $SMM_ORIGINAL['is_public']]
        );
    }
    Seats::forgetModeMap();
}

echo "\n=== Seat mode map — configurable, not hard-coded ===\n\n";
clearMap();

/* ---------- Part 1: the default IS the live coach ---------- */
echo "-- default rule set reproduces today's bus --\n";
check("owner's example: private L6 -> beds L11,L12",
    Seats::physicalSeats('L6', 'private') === ['L11', 'L12']);
check("owner's example, inverse: bed L11 -> private L6",
    Seats::physicalToMode('L11', 'private') === 'L6');
check("bed L12 (the cabin's other bed) -> private L6 too",
    Seats::physicalToMode('L12', 'private') === 'L6');
check("sharing is the canonical namespace (identity)",
    Seats::physicalSeats('L11', 'sharing') === ['L11']);
check("sharing seatIds = 36/deck = 72 berths", count(Seats::seatIds('sleeper', 'sharing')) === 72);
check("private seatIds = 18/deck = 36 cabins", count(Seats::seatIds('sleeper', 'private')) === 36);
check("unitKey('L11') === 'L-6' (the cabin, unchanged format)", Seats::unitKey('L11') === 'L-6');
check("unitKey('L12') === 'L-6' (same cabin)", Seats::unitKey('L12') === 'L-6');

/* ---------- Part 2: a different ratio, with no code edit ---------- */
echo "\n-- a 3-bed cabin, configured only --\n";
useMap(['sleeper' => [
    'canonical' => 'sharing',
    'decks'     => ['L', 'U'],
    'perDeck'   => 36,
    'modes'     => [
        'sharing' => ['bedsPerLabel' => 1, 'across' => [4, 2]],
        'private' => ['bedsPerLabel' => 3, 'across' => [2, 1]],
    ],
]]);
check("private L6 now spans THREE beds L16,L17,L18",
    Seats::physicalSeats('L6', 'private') === ['L16', 'L17', 'L18'],
    implode(',', Seats::physicalSeats('L6', 'private')));
check("inverse follows: bed L17 -> private L6", Seats::physicalToMode('L17', 'private') === 'L6');
check("bed L16 and L18 also -> private L6",
    Seats::physicalToMode('L16', 'private') === 'L6' && Seats::physicalToMode('L18', 'private') === 'L6');
check("private seat COUNT follows the ratio: 36/3 = 12/deck = 24",
    count(Seats::seatIds('sleeper', 'private')) === 24,
    (string) count(Seats::seatIds('sleeper', 'private')));
check("sharing is untouched at 72", count(Seats::seatIds('sleeper', 'sharing')) === 72);
check("unitKey('L17') follows the rule set -> 'L-6'", Seats::unitKey('L17') === 'L-6', Seats::unitKey('L17'));
$layout = Seats::layoutFor('sleeper', 'private');
$inLayout = [];
foreach ($layout as $deck) { foreach ($deck['rows'] ?? [] as $row) { foreach ($row['seats'] ?? $row as $s) { $inLayout[] = is_array($s) ? ($s['id'] ?? '') : $s; } } }
check("layoutFor() agrees with seatIds() under the new ratio",
    count(array_diff($inLayout, Seats::seatIds('sleeper', 'private'))) === 0);

/* ---------- Part 3: an irregular cabin the ratio cannot express ---------- */
echo "\n-- explicit override beats the formula, both directions --\n";
useMap(['sleeper' => [
    'canonical' => 'sharing',
    'decks'     => ['L', 'U'],
    'perDeck'   => 36,
    'modes'     => [
        'sharing' => ['bedsPerLabel' => 1, 'across' => [4, 2]],
        // Cabin L1 is a wide rear berth of THREE beds; every other cabin is 2.
        'private' => ['bedsPerLabel' => 2, 'across' => [2, 1],
                      'explicit' => ['L1' => ['L1', 'L2', 'L3']]],
    ],
]]);
check("explicit: private L1 -> L1,L2,L3 (not the ratio's L1,L2)",
    Seats::physicalSeats('L1', 'private') === ['L1', 'L2', 'L3'],
    implode(',', Seats::physicalSeats('L1', 'private')));
check("explicit wins the INVERSE: bed L3 -> L1, though the ratio says L2",
    Seats::physicalToMode('L3', 'private') === 'L1', Seats::physicalToMode('L3', 'private'));
check("a bed outside the override still uses the ratio: L5 -> L3",
    Seats::physicalToMode('L5', 'private') === 'L3');
check("unregulated cabin unchanged: private L3 -> L5,L6",
    Seats::physicalSeats('L3', 'private') === ['L5', 'L6']);
check("unitKey('L3') follows the override -> 'L-1'", Seats::unitKey('L3') === 'L-1', Seats::unitKey('L3'));

/* ---------- Part 4: a wrong rule set is refused, not absorbed ---------- */
echo "\n-- a broken rule set throws (it describes a real bus) --\n";
clearMap();
check("bedsPerLabel that does not divide perDeck is refused",
    throws(fn() => Seats::assertValidModeMap(['sleeper' => ['canonical' => 'sharing', 'decks' => ['L'], 'perDeck' => 36,
        'modes' => ['sharing' => ['bedsPerLabel' => 1], 'private' => ['bedsPerLabel' => 5]]]])));
check("a canonical mode with bedsPerLabel != 1 is refused",
    throws(fn() => Seats::assertValidModeMap(['sleeper' => ['canonical' => 'sharing', 'decks' => ['L'], 'perDeck' => 36,
        'modes' => ['sharing' => ['bedsPerLabel' => 2]]]])));
check("one bed claimed by two explicit cabins is refused",
    throws(fn() => Seats::assertValidModeMap(['sleeper' => ['canonical' => 'sharing', 'decks' => ['L'], 'perDeck' => 36,
        'modes' => ['sharing' => ['bedsPerLabel' => 1],
                    'private' => ['bedsPerLabel' => 2, 'explicit' => ['L1' => ['L1', 'L2'], 'L2' => ['L2', 'L3']]]]]])));
check("perDeck of zero is refused",
    throws(fn() => Seats::assertValidModeMap(['sleeper' => ['canonical' => 'sharing', 'decks' => ['L'], 'perDeck' => 0,
        'modes' => ['sharing' => ['bedsPerLabel' => 1]]]])));
check("canonical naming a mode that does not exist is refused",
    throws(fn() => Seats::assertValidModeMap(['sleeper' => ['canonical' => 'nope', 'decks' => ['L'], 'perDeck' => 36,
        'modes' => ['sharing' => ['bedsPerLabel' => 1]]]])));
check("a valid rule set does NOT throw",
    !throws(fn() => Seats::assertValidModeMap(['sleeper' => ['canonical' => 'sharing', 'decks' => ['L', 'U'], 'perDeck' => 36,
        'modes' => ['sharing' => ['bedsPerLabel' => 1], 'private' => ['bedsPerLabel' => 3]]]])));
// Absence must fall back to the LIVE geometry, never to a different bus.
clearMap();
check("with no settings row at all, the bus is exactly today's",
    Seats::physicalSeats('L6', 'private') === ['L11', 'L12'] && count(Seats::seatIds('sleeper', 'private')) === 36);

/* ---------- Part 5: a ratio change rewrites HISTORY — say so first ---------- */
echo "\n-- mapChangeImpact() names the sales a change would move --\n";
$w = bookingWindow(); $D = addDaysISO($w['from'], 22);
$cleanup = function () use ($D) {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . SMM_PHONE . "%'") as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
};
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) {
    echo "  \033[33mSKIP\033[0m  no active sleeper route on this DB\n";
} else {
    $cleanup();
    try {
        BookingService::create([
            'routeId' => (int) $route['id'], 'travelDate' => $D, 'seats' => ['L6'],
            'passengers' => [['name' => 'MapMove Test', 'age' => 30, 'gender' => 'Male']],
            'contact' => ['phone' => SMM_PHONE . '0'],
            'bookingMode' => 'private', 'cabinType' => 'single', 'sharingTier' => 'single',
            'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '',
        ]);
        $safe = Seats::mapChangeImpact(['sleeper' => [
            'canonical' => 'sharing', 'decks' => ['L', 'U'], 'perDeck' => 36,
            'modes' => ['sharing' => ['bedsPerLabel' => 1], 'private' => ['bedsPerLabel' => 2]],
        ]]);
        check("an IDENTICAL rule set moves nobody", $safe === [], (string) count($safe));

        $risky = Seats::mapChangeImpact(['sleeper' => [
            'canonical' => 'sharing', 'decks' => ['L', 'U'], 'perDeck' => 36,
            'modes' => ['sharing' => ['bedsPerLabel' => 1], 'private' => ['bedsPerLabel' => 3]],
        ]]);
        $hit = null;
        foreach ($risky as $row) { if ($row['seat'] === 'L6' && $row['mode'] === 'private') { $hit = $row; } }
        check("a 2->3 change is REPORTED as moving the sold private L6", $hit !== null);
        check("it names the beds before (L11,L12) and after (L16,L17,L18)",
            $hit !== null && $hit['before'] === ['L11', 'L12'] && $hit['after'] === ['L16', 'L17', 'L18'],
            $hit === null ? 'no row' : implode(',', $hit['before']) . ' -> ' . implode(',', $hit['after']));
        check("the report carries the PNR so the desk can warn the passenger",
            $hit !== null && str_starts_with((string) $hit['pnr'], 'SHG-'));
        check("the live map is UNCHANGED by asking (no memo leak)",
            Seats::physicalSeats('L6', 'private') === ['L11', 'L12'],
            implode(',', Seats::physicalSeats('L6', 'private')));
    } catch (Throwable $e) {
        check('impact assertions ran without a fatal', false, $e->getMessage());
    } finally {
        $cleanup();
        restoreMap();
    }
}

restoreMap();
check("the suite leaves the seeded configuration exactly as it found it",
    (Database::fetch("SELECT svalue FROM settings WHERE skey='seat_mode_map'")['svalue'] ?? null)
        === ($SMM_ORIGINAL['svalue'] ?? null));
echo "\n" . ($FAIL === 0 ? "\033[32mALL {$PASS} PASSED\033[0m" : "\033[31m{$FAIL} FAILED\033[0m ({$PASS} passed)") . "\n\n";
exit($FAIL === 0 ? 0 : 1);
