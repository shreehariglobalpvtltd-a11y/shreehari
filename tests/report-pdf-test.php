<?php
/**
 * tests/report-pdf-test.php — verify the ReportPdf class renders valid
 * multi-page PDFs with summary cards, info blocks and tables.
 *
 * Does NOT need a database — it exercises the layout engine only. The
 * page-header/footer/pagination mechanics are what matter here; data
 * correctness is covered by the manifest and agent-wallet tests.
 */

$_SERVER['REQUEST_URI'] = '/tests/report-pdf-test.php';
define('SHG_APP', true);
define('ROOT_PATH', dirname(__DIR__));
define('INCLUDE_PATH', ROOT_PATH . '/includes');

// Minimal stubs for classes the PDF class touches but doesn't need for layout.
if (!class_exists('Logger')) {
    class Logger {
        public static function error(string $msg, array $ctx = [], string $ch = ''): void {}
        public static function audit(string ...$args): void {}
    }
}
if (!class_exists('Settings')) {
    class Settings {
        public static function getString(string $k, string $d = ''): string { return $d; }
        public static function getFloat(string $k, float $d = 0.0): float { return $d; }
    }
}
if (!function_exists('ensureDir')) {
    function ensureDir(string $d): void { if (!is_dir($d)) @mkdir($d, 0755, true); }
}
if (!function_exists('inr')) {
    function inr(float|int|string $a): string { return 'Rs ' . number_format((float) $a); }
}
if (!function_exists('formatDate')) {
    function formatDate(?string $iso, string $f = 'D, j M Y'): string {
        if ($iso === null || $iso === '') return '';
        $t = strtotime($iso);
        return $t ? date($f, $t) : '';
    }
}
if (!function_exists('formatTime')) {
    function formatTime(?string $t, string $f = 'g:i A'): string {
        if ($t === null || $t === '') return '';
        $ts = strtotime('1970-01-01 ' . $t);
        return $ts ? date($f, $ts) : '';
    }
}
if (!function_exists('addDaysISO')) {
    function addDaysISO(string $d, int $n): string { return date('Y-m-d', strtotime($d . ' +' . $n . ' days')); }
}

require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/reportpdf.php';

$pass = 0;
$fail = 0;

function ok(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) {
        echo "  PASS  $label\n";
        $pass++;
    } else {
        echo "  FAIL  $label\n";
        $fail++;
    }
}

echo "=== ReportPdf layout tests ===\n\n";

/* ---- Test 1: basic PDF structure --------------------------------------- */
echo "--- Test 1: Single-page manifest ---\n";
$rpt = new ReportPdf('MANIFEST', 'Test subtitle');
$rpt->summaryCards([
    ['label' => 'PAX',    'value' => '5',  'color' => [46, 95, 168]],
    ['label' => 'SEATS',  'value' => '72', 'color' => [16, 130, 60]],
    ['label' => 'REVENUE','value' => 'Rs 10,000', 'color' => [26, 58, 106]],
]);
$rpt->infoBlock([['Route', 'A -> B'], ['Date', '28 Aug 2026']]);
$rpt->sectionHeading('Passengers');
$rpt->setColumns([
    ['label' => '#', 'width' => 30, 'align' => 'C'],
    ['label' => 'Name', 'width' => 200],
    ['label' => 'Amount', 'width' => 100, 'align' => 'R'],
]);
$rpt->tableHeader();
for ($i = 0; $i < 5; $i++) {
    $rpt->tableRow([(string) ($i + 1), 'Passenger ' . ($i + 1), 'Rs 2,000'], $i % 2 === 1);
}
$rpt->tableTotals(['', 'TOTAL', 'Rs 10,000']);

$bytes = $rpt->output();
ok(str_starts_with($bytes, '%PDF-1.4'), 'valid PDF header');
ok(str_contains($bytes, '%%EOF'), 'has EOF marker');
ok(strlen($bytes) > 500, 'reasonable size (' . strlen($bytes) . ' bytes)');
ok(substr_count($bytes, '/Type /Page /Parent') === 1, 'single page');

/* ---- Test 2: multi-page (72 rows) ------------------------------------- */
echo "\n--- Test 2: Multi-page with 72 rows ---\n";
$rpt2 = new ReportPdf('AGENT REPORT', '72-row test');
$rpt2->setColumns([
    ['label' => '#', 'width' => 24, 'align' => 'C'],
    ['label' => 'PNR', 'width' => 100],
    ['label' => 'Passenger', 'width' => 140],
    ['label' => 'Amount', 'width' => 80, 'align' => 'R'],
]);
$rpt2->tableHeader();
for ($i = 0; $i < 72; $i++) {
    $rpt2->tableRow([(string) ($i + 1), 'SHG-R2-' . $i, 'Test ' . $i, 'Rs 2,000'], $i % 2 === 1);
}
$bytes2 = $rpt2->output();
$pages = substr_count($bytes2, '/Type /Page /Parent');
ok($pages >= 2, 'multiple pages rendered (' . $pages . ')');
ok(str_starts_with($bytes2, '%PDF-1.4'), 'valid PDF header');
ok(strlen($bytes2) > 3000, 'substantial size (' . strlen($bytes2) . ' bytes)');

/* ---- Test 3: commission report shape ----------------------------------- */
echo "\n--- Test 3: Commission report shape ---\n";
$rpt3 = new ReportPdf('COMMISSION REPORT', 'Aug 2026');
$rpt3->sectionHeading('Agent Commission Summary');
$rpt3->setColumns([
    ['label' => 'Agent', 'width' => 150],
    ['label' => 'Tickets', 'width' => 60, 'align' => 'R'],
    ['label' => 'Revenue', 'width' => 90, 'align' => 'R'],
    ['label' => 'Commission', 'width' => 90, 'align' => 'R'],
    ['label' => 'Paid', 'width' => 90, 'align' => 'R'],
    ['label' => 'Pending', 'width' => 90, 'align' => 'R'],
]);
$rpt3->tableHeader();
for ($i = 0; $i < 10; $i++) {
    $rpt3->tableRow([
        'SHG-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT) . ' Agent ' . ($i + 1),
        (string) ($i * 5 + 10), 'Rs ' . ($i * 10000 + 20000),
        'Rs ' . ($i * 2000 + 4000), 'Rs ' . ($i * 1000), 'Rs ' . ($i * 1000 + 4000),
    ], $i % 2 === 1);
}
$rpt3->tableTotals(['TOTAL (10 agents)', '100', 'Rs 2,90,000', 'Rs 49,000', 'Rs 4,500', 'Rs 44,500']);

$bytes3 = $rpt3->output();
ok(str_starts_with($bytes3, '%PDF-1.4'), 'valid PDF header');
ok(str_contains($bytes3, '%%EOF'), 'has EOF marker');

/* ---- Test 4: empty report ---------------------------------------------- */
echo "\n--- Test 4: Empty report (no rows) ---\n";
$rpt4 = new ReportPdf('EMPTY REPORT', 'No data');
$rpt4->sectionHeading('Nothing here');
$rpt4->setColumns([
    ['label' => 'Col A', 'width' => 200],
    ['label' => 'Col B', 'width' => 200],
]);
$rpt4->tableHeader();
// No rows
$bytes4 = $rpt4->output();
ok(str_starts_with($bytes4, '%PDF-1.4'), 'valid even with no rows');

echo "\n=== Results: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
