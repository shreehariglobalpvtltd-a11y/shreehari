<?php
/**
 * seat-layout-test.php — proves Seats::layoutFor() is exactly consistent with
 * Seats::seatIds(), and that api/seats.php + Seats::availability() surface it.
 *
 * Nothing in this file writes to bookings, seat_locks, or any live table —
 * it exercises pure geometry helpers and the availability read path only.
 *
 *   php -c .claude/php-dev.ini tests/seat-layout-test.php
 *
 * Round 1 (server-side additive) safety net: if this fails, the layout
 * geometry has drifted from the label generator and the customer/admin
 * renderers about to consume `layout.decks` in rounds 2 and 3 will
 * hide or invent berths.
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

/** Flatten a layout's decks[i].rows[j].{left,right} into one label list. */
function flattenLayout(array $layout): array
{
    $out = [];
    foreach ($layout['decks'] as $deck) {
        foreach ($deck['rows'] as $row) {
            foreach ($row['left'] as $s) {
                $out[] = $s;
            }
            foreach ($row['right'] as $s) {
                $out[] = $s;
            }
        }
    }
    return $out;
}

echo "\n=== Seat layout — geometry vs seatIds ===\n\n";

// ---------- 1. Sleeper + sharing (72 berths, 4+2, 6 rows/deck) ----------
$ids     = Seats::seatIds('sleeper', 'sharing');
$layout  = Seats::layoutFor('sleeper', 'sharing');
$flat    = flattenLayout($layout);

check('sleeper/sharing: seatIds count is 72', count($ids) === 72);
check('sleeper/sharing: layout.seatIds equals seatIds()', $layout['seatIds'] === $ids);
check('sleeper/sharing: flattened layout equals seatIds() (order + values)', $flat === $ids);
check('sleeper/sharing: perDeck=36, perRow=6', $layout['perDeck'] === 36 && $layout['perRow'] === 6);
check('sleeper/sharing: 2 decks', count($layout['decks']) === 2);
check('sleeper/sharing: 6 rows per deck', count($layout['decks'][0]['rows']) === 6 && count($layout['decks'][1]['rows']) === 6);
check('sleeper/sharing: row 1 is L1..L4 | L5 L6', $layout['decks'][0]['rows'][0]['left'] === ['L1', 'L2', 'L3', 'L4'] && $layout['decks'][0]['rows'][0]['right'] === ['L5', 'L6']);
check('sleeper/sharing: row 6 is L31..L34 | L35 L36', $layout['decks'][0]['rows'][5]['left'] === ['L31', 'L32', 'L33', 'L34'] && $layout['decks'][0]['rows'][5]['right'] === ['L35', 'L36']);
check('sleeper/sharing: upper deck row 1 is U1..U4 | U5 U6', $layout['decks'][1]['rows'][0]['left'] === ['U1', 'U2', 'U3', 'U4'] && $layout['decks'][1]['rows'][0]['right'] === ['U5', 'U6']);
check('sleeper/sharing: every berth also lives in seatIds()', array_diff($flat, $ids) === []);

// ---------- 2. Sleeper + private (36 berths, 2+1, 6 rows/deck) ----------
// Raised from 15/deck on 30 Aug 2026: both modes are the same physical
// six-row coach, so private must show six rows too.
$ids    = Seats::seatIds('sleeper', 'private');
$layout = Seats::layoutFor('sleeper', 'private');
$flat   = flattenLayout($layout);

check('sleeper/private: seatIds count is 36', count($ids) === 36);
check('sleeper/private: layout.seatIds equals seatIds()', $layout['seatIds'] === $ids);
check('sleeper/private: flattened layout equals seatIds() (order + values)', $flat === $ids);
check('sleeper/private: perDeck=18, perRow=3', $layout['perDeck'] === 18 && $layout['perRow'] === 3);
check('sleeper/private: 6 rows per deck', count($layout['decks'][0]['rows']) === 6);
check('sleeper/private: row 1 is L1 L2 | L3', $layout['decks'][0]['rows'][0]['left'] === ['L1', 'L2'] && $layout['decks'][0]['rows'][0]['right'] === ['L3']);
check('sleeper/private: row 6 is L16 L17 | L18', $layout['decks'][0]['rows'][5]['left'] === ['L16', 'L17'] && $layout['decks'][0]['rows'][5]['right'] === ['L18']);

// ---------- 3. Seater (40 seats, 2+2, 10 rows single deck) ----------
$ids    = Seats::seatIds('seater');
$layout = Seats::layoutFor('seater');
$flat   = flattenLayout($layout);

check('seater: seatIds count is 40', count($ids) === 40);
check('seater: layout.seatIds equals seatIds()', $layout['seatIds'] === $ids);
check('seater: flattened layout equals seatIds() (order + values)', $flat === $ids);
check('seater: single deck', count($layout['decks']) === 1);
check('seater: 10 rows', count($layout['decks'][0]['rows']) === 10);
check('seater: row 1 is 1A 1B | 1C 1D', $layout['decks'][0]['rows'][0]['left'] === ['1A', '1B'] && $layout['decks'][0]['rows'][0]['right'] === ['1C', '1D']);
check('seater: row 10 is 10A 10B | 10C 10D', $layout['decks'][0]['rows'][9]['left'] === ['10A', '10B'] && $layout['decks'][0]['rows'][9]['right'] === ['10C', '10D']);

// ---------- 4. Every layout label decodes to a valid unitKey ----------
foreach ([
    ['sleeper', 'sharing'],
    ['sleeper', 'private'],
    ['seater',  'sharing'],
] as $combo) {
    [$c, $b] = $combo;
    $layout = Seats::layoutFor($c, $b);
    $ok = true;
    foreach ($layout['decks'] as $deck) {
        foreach ($deck['rows'] as $row) {
            foreach (array_merge($row['left'], $row['right']) as $seat) {
                $unit = Seats::unitKey($seat);
                if ($unit === '' || strpos($unit, 'S-') === 0) {
                    $ok = false;
                    break 3;
                }
            }
        }
    }
    check("layout($c/$b): every berth has a real cabin key (unitKey())", $ok);
}

// ---------- 5. availability() plumbs layout through to the API shape ----------
$route = Database::fetch('SELECT id, coach_type FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
if ($route !== null) {
    $availability = Seats::availability((int) $route['id'], '2099-11-30', 'sharing');
    check("availability(): 'layout' key present in return", isset($availability['layout']));
    check("availability(): layout.seatIds matches allSeats", ($availability['layout']['seatIds'] ?? []) === $availability['all']);
    check("availability(): layout.perDeck > 0", ($availability['layout']['perDeck'] ?? 0) > 0);
    check("availability(): layout.decks non-empty", is_array($availability['layout']['decks'] ?? null) && $availability['layout']['decks'] !== []);
} else {
    echo "  \033[33mSKIP\033[0m  availability() plumbing — no active route on this DB\n";
}

// ---------- 6. seatIds() and layoutFor() reject nothing new ----------
// Sanity: any unknown coachType should degrade to the seater path (10x4=40).
$oddLayout = Seats::layoutFor('unicorn', 'sharing');
check("layoutFor(unknown coach) degrades to seater (40)", count($oddLayout['seatIds']) === 40);

echo "\n" . ($FAIL === 0 ? "\033[32mALL {$PASS} PASSED\033[0m" : "\033[31m{$FAIL} FAILED\033[0m ({$PASS} passed)") . "\n\n";

exit($FAIL === 0 ? 0 : 1);
