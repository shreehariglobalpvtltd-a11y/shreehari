<?php
/**
 * seat-label-test.php — the passenger-facing seat labels (23 Sep 2026).
 *
 * The 72-berth sleeper is ONE two-floor grid: Lower Floor (1F) A1..F6, Upper
 * Floor (2F) A7..F12, 4 left | aisle | 2 right. This is a DISPLAY transform —
 * the stored ids stay L1..L36 / U1..U36 — so the suite proves:
 *
 *   1. all 72 labels are unique and land exactly on A1..F6 / A7..F12;
 *   2. the stored ids, the layout and the seat engine are unchanged;
 *   3. a private cabin is named by the beds it covers (L6 = beds L11+L12 = B5-6);
 *   4. every seat already sold in the database gets a label, and no two
 *      different seats of one departure share one;
 *   5. PHP agrees with tests/seat-labels.json, which tests/seat-label-parity.js
 *      holds the browser's seatLabel() to — so booking and ticket agree.
 *
 * Reads only. `--dump` prints the fixture JSON instead of testing.
 *
 *   php tests/seat-label-test.php
 *   php tests/seat-label-test.php --dump > tests/seat-labels.json
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';

Seats::forgetModeMap();

/** @return array<string, array<string, string>> mode => [stored id => label] */
function labelTable(): array
{
    $out = [];
    foreach (['sharing', 'private'] as $mode) {
        foreach (Seats::seatIds('sleeper', $mode) as $id) {
            $out[$mode][$id] = Seats::displayLabel($id, 'sleeper', $mode);
        }
    }
    foreach (Seats::seatIds('seater') as $id) {
        $out['seater'][$id] = Seats::displayLabel($id, 'seater', 'sharing');
    }
    return $out;
}

if (in_array('--dump', $argv, true)) {
    echo json_encode([
        'seat_mode_map' => ['sleeper' => Seats::modeMap('sleeper')],
        'labels'        => labelTable(),
        'floorRange'    => ['L' => Seats::floorRange('L'), 'U' => Seats::floorRange('U')],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

$PASS = 0;
$FAIL = 0;
function check(string $label, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  $label" . ($extra !== '' ? " — $extra" : '') . "\n";
    }
}

echo "\n=== Seat labels — two floors, one grid ===\n\n";

/* ---- 1. The 72 sharing berths ------------------------------------------- */
$ids = Seats::seatIds('sleeper', 'sharing');
$expectIds = [];
foreach (['L', 'U'] as $d) { for ($n = 1; $n <= 36; $n++) { $expectIds[] = $d . $n; } }
check('stored ids unchanged: L1..L36, U1..U36 (72)', $ids === $expectIds, count($ids) . ' ids');

$lbl = [];
foreach ($ids as $id) { $lbl[$id] = Seats::displayLabel($id, 'sleeper', 'sharing'); }
check('all 72 labels are unique', count(array_unique($lbl)) === 72, count(array_unique($lbl)) . ' distinct');

$lowerWant = $upperWant = [];
foreach (range('A', 'F') as $r) {
    for ($c = 1; $c <= 6; $c++)  { $lowerWant[] = $r . $c; }
    for ($c = 7; $c <= 12; $c++) { $upperWant[] = $r . $c; }
}
$lowerGot = array_values(array_filter($lbl, static fn($k) => $k[0] === 'L', ARRAY_FILTER_USE_KEY));
$upperGot = array_values(array_filter($lbl, static fn($k) => $k[0] === 'U', ARRAY_FILTER_USE_KEY));
check('Lower Floor (1F) is exactly A1..F6 (36)', $lowerGot === $lowerWant, implode(' ', array_slice($lowerGot, 0, 8)) . ' …');
check('Upper Floor (2F) is exactly A7..F12 (36)', $upperGot === $upperWant, implode(' ', array_slice($upperGot, 0, 8)) . ' …');

foreach (['L1' => 'A1', 'L4' => 'A4', 'L5' => 'A5', 'L6' => 'A6', 'L7' => 'B1', 'L36' => 'F6',
          'U1' => 'A7', 'U4' => 'A10', 'U6' => 'A12', 'U7' => 'B7', 'U36' => 'F12'] as $id => $want) {
    check("$id -> $want", $lbl[$id] === $want, 'got ' . $lbl[$id]);
}
check('label is case/space tolerant (" l7 " -> B1)', Seats::displayLabel(' l7 ', 'sleeper', 'sharing') === 'B1');
check('an unknown mode names the bed itself (U2 -> A8)', Seats::displayLabel('U2', 'sleeper', '') === 'A8');

/* ---- 2. The layout draws the same grid ---------------------------------- */
$layout = Seats::layoutFor('sleeper', 'sharing');
check('layout: two floors named Lower Floor (1F) / Upper Floor (2F)',
      array_column($layout['decks'], 'label') === ['Lower Floor (1F)', 'Upper Floor (2F)']);
$rowA = $layout['decks'][0]['rows'][0];
$rowAU = $layout['decks'][1]['rows'][0];
check('lower row A: A1 A2 A3 A4 | aisle | A5 A6',
      array_map(static fn($s) => Seats::displayLabel($s), $rowA['left']) === ['A1', 'A2', 'A3', 'A4']
      && array_map(static fn($s) => Seats::displayLabel($s), $rowA['right']) === ['A5', 'A6'] && $rowA['aisle'] === true);
check('upper row A: A7 A8 A9 A10 | aisle | A11 A12',
      array_map(static fn($s) => Seats::displayLabel($s), $rowAU['left']) === ['A7', 'A8', 'A9', 'A10']
      && array_map(static fn($s) => Seats::displayLabel($s), $rowAU['right']) === ['A11', 'A12']);
$rowLabels = array_column($layout['decks'][0]['rows'], 'label');
check('rows are lettered Row A..Row F', $rowLabels === ['Row A', 'Row B', 'Row C', 'Row D', 'Row E', 'Row F'], implode(',', $rowLabels));
$allRows = true;
foreach ($layout['decks'] as $deck) {
    foreach ($deck['rows'] as $i => $row) {
        $letter = chr(65 + $i);
        foreach (array_merge($row['left'], $row['right']) as $s) {
            if (Seats::displayLabel($s)[0] !== $letter) { $allRows = false; }
        }
    }
}
check('every berth sits in the row its letter names', $allRows);
check('floor ranges: A1–F6 / A7–F12', Seats::floorRange('L') === 'A1–F6' && Seats::floorRange('U') === 'A7–F12',
      Seats::floorRange('L') . ' / ' . Seats::floorRange('U'));
check('Seats::label long form names the floor', Seats::label('U4') === 'Upper Floor (2F) · A10', Seats::label('U4'));

/* ---- 3. Private cabins: named by the beds they cover --------------------- */
$pids = Seats::seatIds('sleeper', 'private');
$plbl = array_map(static fn($s) => Seats::displayLabel($s, 'sleeper', 'private'), $pids);
check('36 private cabin labels, all unique', count($pids) === 36 && count(array_unique($plbl)) === 36);
foreach (['L1' => 'A1-2', 'L3' => 'A5-6', 'L6' => 'B5-6', 'L12' => 'D5-6', 'U1' => 'A7-8', 'U6' => 'B11-12', 'U18' => 'F11-12'] as $id => $want) {
    check("private $id -> $want", Seats::displayLabel($id, 'sleeper', 'private') === $want, 'got ' . Seats::displayLabel($id, 'sleeper', 'private'));
}
$bedsAgree = true;
foreach ($pids as $p) {
    $beds  = Seats::physicalSeats($p, 'private', 'sleeper');
    $first = Seats::displayLabel($beds[0], 'sleeper', 'sharing');
    $last  = Seats::displayLabel(end($beds), 'sleeper', 'sharing');
    if (Seats::displayLabel($p, 'sleeper', 'private') !== $first . '-' . substr($last, 1)) { $bedsAgree = false; }
}
check('every private label = the sharing labels of its own beds', $bedsAgree);

/* ---- 4. Seater and odd ids are left alone -------------------------------- */
check('seater 1A..10D unchanged', Seats::displayLabel('3C', 'seater') === '3C' && Seats::displayLabel('10D', 'seater') === '10D');
check('empty stays empty', Seats::displayLabel('', 'sleeper') === '');
check('L0 is not invented into a label', Seats::displayLabel('L0', 'sleeper') === 'L0');

/* ---- 5. Staff / women seats still resolve (display only) ----------------- */
$staffOk = true;
foreach (array_merge(Seats::staffSeats('sleeper'), Seats::femaleSeats('sleeper')) as $s) {
    if (!preg_match('/^[A-F](?:[1-9]|1[0-2])$/', Seats::displayLabel((string) $s, 'sleeper', 'sharing'))) { $staffOk = false; }
}
check('staff + women-reserved berths carry grid labels', $staffOk);

/* ---- 6. Every seat already sold ----------------------------------------- */
try {
    $rows = Database::fetchAll(
        "SELECT bs.schedule_id, bs.seat_no, COALESCE(b.booking_mode, 'sharing') AS mode, COALESCE(r.coach_type, 'sleeper') AS coach
           FROM booking_seats bs
           JOIN bookings b  ON b.id = bs.booking_id
           JOIN schedules s ON s.id = bs.schedule_id
           JOIN routes r    ON r.id = s.route_id"
    );
    $empty = $unmapped = $clash = 0;
    $seen = [];
    foreach ($rows as $r) {
        $l = Seats::displayLabel((string) $r['seat_no'], (string) $r['coach'], (string) $r['mode']);
        if ($l === '') { $empty++; }
        if (preg_match('/^[LU]\d+$/', strtoupper((string) $r['seat_no'])) && !preg_match('/^[A-F]\d+(?:-\d+)?$/', $l)) { $unmapped++; }
        $k = $r['schedule_id'] . '|' . $r['mode'] . '|' . $l;
        if (isset($seen[$k]) && $seen[$k] !== $r['seat_no']) { $clash++; }
        $seen[$k] = $r['seat_no'];
    }
    check('every sold seat in the database gets a label (' . count($rows) . ' rows)', $empty === 0 && $unmapped === 0, "empty=$empty unmapped=$unmapped");
    check('no two seats of one departure share a label', $clash === 0, "clashes=$clash");
} catch (Throwable $e) {
    check('existing bookings readable', false, $e->getMessage());
}

/* ---- 7. PHP agrees with the fixture the browser test uses ---------------- */
$fixture = json_decode((string) @file_get_contents(__DIR__ . '/seat-labels.json'), true);
check('tests/seat-labels.json matches Seats::displayLabel (regenerate with --dump)',
      is_array($fixture) && ($fixture['labels'] ?? null) === labelTable()
      && ($fixture['floorRange'] ?? null) === ['L' => Seats::floorRange('L'), 'U' => Seats::floorRange('U')]);

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
