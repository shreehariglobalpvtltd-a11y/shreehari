<?php
/**
 * =====================================================================
 *  ai-chart-test.php — the report picture (24 Sep 2026). No database.
 *
 *  includes/aichart.php paints a report's `chart` block as a PNG for
 *  WhatsApp. This suite draws every shape the report tools emit (bar,
 *  line, doughnut, a second axis, a Devanagari title, a 90-day series)
 *  and checks that a real PNG of the right size comes back, that an
 *  empty block is refused instead of drawn, and that the sweep removes
 *  only old files.
 *
 *      php tests/ai-chart-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
if (!defined('UPLOAD_PATH')) {
    define('UPLOAD_PATH', sys_get_temp_dir() . '/shg-aichart-test-' . getmypid());
}
require_once dirname(__DIR__) . '/includes/aichart.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function pngSize(string $bytes): array {
    $im = @imagecreatefromstring($bytes);
    return $im ? [imagesx($im), imagesy($im)] : [0, 0];
}

echo "\n=== AiChart — the report picture ===\n\n";

check('GD + FreeType + the shipped face are available', AiChart::available());
if (!AiChart::available()) { echo "\n  cannot continue without GD\n"; exit(1); }

/* ---- what is drawable ------------------------------------------------ */
check('an empty block is refused', !AiChart::valid([]) && !AiChart::valid(['labels' => [], 'series' => []]));
check('a series without data is refused', !AiChart::valid(['labels' => ['a'], 'series' => [['name' => 'x', 'data' => []]]]));
check('a real block is accepted', AiChart::valid(['labels' => ['a'], 'series' => [['name' => 'x', 'data' => [1]]]]));
check('pngBytes() on an empty block is null, never an image', AiChart::pngBytes(['labels' => [], 'series' => []]) === null);

/* ---- the shapes the report tools emit -------------------------------- */
$bar = [
    'type' => 'bar', 'title' => 'Sales · this week · by day',
    'labels' => ['18 Sep', '19 Sep', '20 Sep', '21 Sep', '22 Sep', '23 Sep', '24 Sep'],
    'series' => [
        ['name' => 'Revenue ₹', 'data' => [12000, 0, 8400, 22000, 18000, 4000, 9600], 'format' => 'money'],
        ['name' => 'Tickets', 'data' => [6, 0, 4, 11, 9, 2, 5], 'format' => 'count', 'axis' => 'y2'],
    ],
    'format' => 'money',
];
$png = AiChart::pngBytes($bar);
check('a bar chart with a second axis renders', $png !== null && substr($png, 0, 8) === "\x89PNG\r\n\x1a\n", $png === null ? 'null' : strlen($png) . ' bytes');
check('  at the WhatsApp-friendly size', pngSize((string) $png) === [1200, 700], implode('x', pngSize((string) $png)));

$line = ['type' => 'line', 'title' => 'Website & app visits · last 90 days', 'labels' => [], 'series' => [['name' => 'Visits', 'data' => [], 'format' => 'count']], 'format' => 'count'];
for ($i = 0; $i < 90; $i++) { $line['labels'][] = date('d M', strtotime("-$i days")); $line['series'][0]['data'][] = ($i * 37) % 53; }
$png = AiChart::pngBytes($line);
check('a 90-point line chart renders (labels thinned, none overlapping)', $png !== null && pngSize($png) === [1200, 700]);

$dough = ['type' => 'doughnut', 'title' => 'Payment method · this month', 'labels' => ['UPI', 'Cash', 'eSewa', 'Bank'], 'series' => [['name' => 'Tickets', 'data' => [40, 25, 10, 5], 'format' => 'count']], 'format' => 'count'];
$png = AiChart::pngBytes($dough);
check('a doughnut renders', $png !== null && pngSize($png) === [1200, 700]);

$pct = ['type' => 'bar', 'title' => 'Bus occupancy · next 7 days (% of seats sold)', 'labels' => ['Thu 25 Sep 15:00', 'Fri 26 Sep 15:00'], 'series' => [['name' => 'Sold %', 'data' => [72, 100], 'format' => 'percent']], 'format' => 'percent', 'max' => 100];
$png = AiChart::pngBytes($pct);
check('a percent chart with a fixed 100 top renders', $png !== null && pngSize($png) === [1200, 700]);

$dev = ['type' => 'bar', 'title' => 'बिक्री · यो हप्ता · दिन अनुसार', 'labels' => ['सोम', 'मंगल', 'बुध'], 'series' => [['name' => 'टिकट', 'data' => [3, 5, 2], 'format' => 'count']], 'format' => 'count'];
$png = AiChart::pngBytes($dev);
check('a Devanagari title and labels render', $png !== null && pngSize($png) === [1200, 700]);

$zero = ['type' => 'bar', 'title' => 'Nothing sold', 'labels' => ['a', 'b'], 'series' => [['name' => 'x', 'data' => [0, 0], 'format' => 'money']], 'format' => 'money'];
$png = AiChart::pngBytes($zero);
check('an all-zero series still renders (axis falls back to a sane top)', $png !== null && pngSize($png) === [1200, 700]);

$nan = ['type' => 'bar', 'title' => 'Bad input', 'labels' => ['a', 'b', 'c'], 'series' => [['name' => 'x', 'data' => ['7', 'abc', null], 'format' => 'count']], 'format' => 'count'];
$png = AiChart::pngBytes($nan);
check('non-numeric data is coerced, not fatal', $png !== null && pngSize($png) === [1200, 700]);

/* ---- the file on disk + the sweep ------------------------------------ */
$dir = UPLOAD_PATH . '/ai-charts';
@mkdir($dir . '/2026-01-01', 0755, true);
@mkdir($dir . '/' . date('Y-m-d'), 0755, true);
$old = $dir . '/2026-01-01/old.png';
$new = $dir . '/' . date('Y-m-d') . '/new.png';
file_put_contents($old, (string) AiChart::pngBytes($zero));
file_put_contents($new, (string) AiChart::pngBytes($zero));
touch($old, time() - 10 * 86400);
$removed = AiChart::sweep(3);
check('sweep(3) removes a 10-day-old picture and its empty day folder', $removed === 1 && !is_file($old) && !is_dir($dir . '/2026-01-01'), (string) $removed);
check('  and keeps today\'s', is_file($new));
@unlink($new); @rmdir($dir . '/' . date('Y-m-d')); @rmdir($dir); @rmdir(UPLOAD_PATH);

echo "\n----------------------------------------\n";
echo "  \033[32m{$PASS} passed\033[0m, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
