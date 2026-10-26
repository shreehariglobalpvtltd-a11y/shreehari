<?php
/**
 * bs-calendar-test.php — Bikram Sambat converter guard.
 *
 * The BS table is hand-entered panchanga data duplicated in TWO places
 * (assets/js/02-config.js for the site, includes/ticket.php for the PDF).
 * Three things can go wrong, and all three print a WRONG date on a real
 * passenger's ticket, so all three are asserted here:
 *
 *   1. CONTINUITY — year start + sum(months) must equal the next year's
 *      start. A gap silently drops the BS date for a day; an overlap
 *      shifts every date after it.
 *   2. ANCHORS — Baishakh 1 of each year must land on its real AD date,
 *      and a known date must map to its known BS date.
 *   3. MIRROR — the JS table and the PHP table must be byte-identical in
 *      content, or the website and the PDF disagree about the same ticket.
 *
 * Run:  php -c .claude/php-dev.ini tests/bs-calendar-test.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/ticket.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  \033[32mPASS\033[0m  {$label}\n";
    } else {
        $fail++;
        echo "  \033[31mFAIL\033[0m  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

/* Ticket::BS_DATA and Ticket::bsDate() are private — the whole point is
   that they are internal. Reflection lets the test see them without
   widening the class's real API. */
$ref     = new ReflectionClass(Ticket::class);
$bsData  = $ref->getConstant('BS_DATA');
$bsDateM = $ref->getMethod('bsDate');
$bsDateM->setAccessible(true);
$bsDate  = static fn (string $d): string => $bsDateM->invoke(null, $d);

echo "\n--- 1. Continuity (no gaps, no overlaps) ---\n";

$years = array_keys($bsData);
sort($years);
foreach ($years as $i => $y) {
    $rec   = $bsData[$y];
    $total = array_sum($rec['m']);
    check("BS {$y}: has 12 months", count($rec['m']) === 12);
    check(
        "BS {$y}: length {$total} days is a real year (365/366)",
        $total === 365 || $total === 366,
        "got {$total}"
    );

    $next = $years[$i + 1] ?? null;
    if ($next === null) {
        continue;
    }
    $computed = date('Y-m-d', strtotime($rec['start'] . ' UTC +' . $total . ' days'));
    check(
        "BS {$y} + {$total}d meets BS {$next} start exactly",
        $computed === $bsData[$next]['start'],
        "computed {$computed}, table says {$bsData[$next]['start']}"
    );
}

echo "\n--- 2. Anchors (real-world dates) ---\n";

// Baishakh 1 = Nepali New Year for each tabled year.
$newYear = [
    2080 => '2023-04-14',
    2081 => '2024-04-13',
    2082 => '2025-04-14',
    2083 => '2026-04-14',
    2084 => '2027-04-14',
];
foreach ($newYear as $y => $ad) {
    check(
        "Baishakh 1, {$y} falls on {$ad}",
        $bsDate($ad) === 'बैशाख ' . Ticket_bsDigits('1') . ', ' . Ticket_bsDigits((string) $y),
        'got "' . $bsDate($ad) . '"'
    );
}

// Owner-confirmed anchor: the launch date.
check(
    '2026-09-02 is भदौ १७, २०८३ (owner-confirmed)',
    $bsDate('2026-09-02') === 'भदौ १७, २०८३',
    'got "' . $bsDate('2026-09-02') . '"'
);
check(
    '2026-08-30 is भदौ १४, २०८३',
    $bsDate('2026-08-30') === 'भदौ १४, २०८३',
    'got "' . $bsDate('2026-08-30') . '"'
);
// Last day of a tabled year must still resolve (off-by-one guard).
check(
    'last day of BS 2083 (2027-04-13) still resolves',
    $bsDate('2027-04-13') !== '',
    'got empty'
);

echo "\n--- 3. Graceful fallback outside the table ---\n";

check('a date before the table returns empty', $bsDate('2020-01-01') === '');
check('a date after the table returns empty', $bsDate('2050-01-01') === '');
check('garbage input returns empty', $bsDate('not-a-date') === '');
check('empty input returns empty', $bsDate('') === '');

echo "\n--- 4. JS table mirrors the PHP table ---\n";

$js = (string) file_get_contents($root . '/assets/js/02-config.js');
$mirrorOk = true;
$mirrorMsg = '';
foreach ($bsData as $y => $rec) {
    // Match:  2083: { start: '2026-04-14', m: [31,31,...] }
    $pattern = '/' . $y . ':\s*\{\s*start:\s*\'([\d-]+)\',\s*m:\s*\[([\d,\s]+)\]/';
    if (!preg_match($pattern, $js, $m)) {
        $mirrorOk = false;
        $mirrorMsg = "BS {$y} missing from assets/js/02-config.js";
        break;
    }
    $jsMonths = array_map('intval', array_map('trim', explode(',', $m[2])));
    if ($m[1] !== $rec['start'] || $jsMonths !== $rec['m']) {
        $mirrorOk = false;
        $mirrorMsg = "BS {$y} differs: JS start={$m[1]} months=" . implode(',', $jsMonths)
                   . " vs PHP start={$rec['start']} months=" . implode(',', $rec['m']);
        break;
    }
}
check('every PHP BS year matches assets/js/02-config.js exactly', $mirrorOk, $mirrorMsg);

// And the JS must not carry years the PHP lacks (the reverse direction).
preg_match_all('/^\s*(20\d\d):\s*\{\s*start:/m', $js, $jsYears);
$jsYearList = array_map('intval', $jsYears[1]);
sort($jsYearList);
check(
    'JS has no BS years missing from PHP',
    $jsYearList === $years,
    'JS: ' . implode(',', $jsYearList) . ' vs PHP: ' . implode(',', $years)
);

echo "\n";
if ($fail === 0) {
    echo "\033[32mALL {$pass} PASSED\033[0m\n\n";
    exit(0);
}
echo "\033[31m{$fail} FAILED\033[0m ({$pass} passed)\n\n";
exit(1);

/** Devanagari digits — mirrors Ticket::nepaliDigits() for expected values. */
function Ticket_bsDigits(string $s): string
{
    return strtr($s, [
        '0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४',
        '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९',
    ]);
}
