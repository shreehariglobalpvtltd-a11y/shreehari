<?php
/**
 * 16 Nov 2026 — the chalani PDF with the special-payment ("विशेष भुक्तानी") lines
 * keeps its page economy and never drops a line off the sheet.
 *
 *  - no extras on the bus: the blank lines ride in the payment summary, so a
 *    21-passenger bus still prints its sheet on ONE page (+ the seat-chart page);
 *  - a long extras list continues over pages - every booking's PNR is printed.
 *
 * Renders ChalaniPdf::send() in a child process with synthetic rows (no database
 * writes), counts the pages and looks for every PNR in the content streams.
 *
 *   php -c <ini> tests/u10-chalani-pdf-pages-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $why = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $what" . ($why !== '' ? " — $why" : '') . "\n"; } else { $fail++; echo "  FAIL  $what" . ($why !== '' ? " — $why" : '') . "\n"; }
}

$root  = dirname(__DIR__);
$child = sys_get_temp_dir() . '/shg_u10_pdf_' . getmypid() . '.php';
file_put_contents($child, <<<'PHP'
<?php
declare(strict_types=1);
[$_, $root, $n, $x] = $argv; $n = (int) $n; $x = (int) $x;
require $root . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php'; require_once INCLUDE_PATH . '/pdf.php'; require_once INCLUDE_PATH . '/chalanipdf.php';
$trip = ['schedule_id' => 1, 'route_code' => 'R2', 'from_city' => 'Surat', 'to_city' => 'Rupaidiha', 'dep_time' => '13:00:00', 'coach_type' => 'sleeper',
         'bus_number' => 'GJ-02-T-0000', 'bus_name' => 'Test', 'driver_name' => '', 'driver_phone' => '', 'slot' => 1];
$ids = Seats::seatIds('sleeper', 'sharing');
$rows = [];
for ($i = 0; $i < $n; $i++) {
    $rows[] = ['pax_id' => $i + 1, 'booking_id' => $i + 1, 'seat_no' => $ids[$i % count($ids)], 'booking_mode' => 'sharing', 'full_name' => 'Passenger ' . ($i + 1),
        'ticket_number' => '', 'pnr' => sprintf('ZZT-2099-%05d', $i + 1), 'contact_phone' => '9100000000', 'boarding_stop' => 'Surat', 'drop_stop' => 'Rupaidiha',
        'created_at' => '2099-01-01 10:00:00', 'gender' => 'Male', 'pay_status' => 'verified', 'pay_method' => 'cash', 'total_amount' => 2200, 'sold_by_admin_id' => null, 'source' => 'web'];
}
$lines = [];
for ($j = 0; $j < $x; $j++) {
    $lines[] = ['pnr' => sprintf('XTR-2099-%05d', $j + 1), 'lead' => 'Passenger ' . ($j + 1), 'seatsLbl' => 'A1', 'lug' => 1, 'lugFee' => 100.0,
        'inf' => 0, 'infFee' => 0.0, 'amt' => 100.0, 'state' => $j % 2 === 0 ? 'due' : 'paid'];
}
$fmt = [
    'money' => static fn(array $r): array => ['ticket' => 2200.0, 'extra' => 0.0, 'cash' => 2200.0, 'online' => 0.0],
    'stopShort' => static fn(string $s): string => $s, 'isLate' => static fn(array $r): bool => false, 'bookedBy' => static fn(array $r): string => 'ONLINE',
    'stopsLine' => 'Surat (१३:००)', 'stopsLineRoman' => 'Surat (13:00)', 'placeList' => [['no' => 1, 'name' => 'Surat', 'n' => $n, 'women' => 0, 'seats' => []]],
    'placeNo' => static fn(string $s): int => 1, 'womanSingle' => static fn(array $r): bool => false, 'womenSingleCount' => 0,
    'seatInfo' => [], 'layout' => Seats::layoutFor('sleeper', 'sharing'), 'busName' => 'Test',
    'extrasLines' => $lines, 'penLines' => 2, 'rowState' => static fn(array $r): string => 'paid', 'infantsOf' => static fn(array $r): int => 0,
];
$totals = ['passengers' => $n, 'ticket' => 2200.0 * $n, 'cash' => 2200.0 * $n, 'online' => 0.0, 'discount' => 0.0, 'net' => 2200.0 * $n,
           'chalaniNo' => 'CH-TEST', 'extras' => 100.0 * $x, 'extrasPaid' => 50.0 * $x, 'extrasDue' => 50.0 * $x, 'infants' => 0];
register_shutdown_function(static function (): void { fwrite(STDERR, json_encode(ChalaniPdf::$drawn)); });
ChalaniPdf::send($trip, $rows, '2099-01-01', $totals, $fmt);
PHP);

$ini = (string) php_ini_loaded_file();
/** @return array{0: string, 1: array<string, mixed>} the PDF bytes and what ChalaniPdf::$drawn said */
$render = static function (int $n, int $x) use ($child, $root, $ini): array {
    $cmd = array_merge([PHP_BINARY], $ini !== '' ? ['-c', $ini] : [], [$child, $root, (string) $n, (string) $x]);
    /* 26 Nov 2026 (fx4): stderr goes to a file, not a pipe. ChalaniPdf::$drawn grew past 4 KB for 45+ rows; on
       Windows a pipe holds 4 KB, so the child blocked writing stderr while this side waited for stdout to end -
       the battery hung here for 40+ minutes. A file never fills. */
    $errFile = tempnam(sys_get_temp_dir(), 'shg_u10e');
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']], $pipes);
    if (!is_resource($p)) { @unlink($errFile); return ['', []]; }
    $out = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]); proc_close($p);
    $err = (string) @file_get_contents($errFile);
    @unlink($errFile);
    $j = strrpos($err, '{"rows"');
    return [$out, $j !== false ? (array) json_decode(substr($err, $j), true) : ['err' => substr($err, 0, 300)]];
};
$pages = static fn(string $pdf): int => (int) preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf);

echo "\n=== Chalani PDF: special-payment lines keep the page economy ===\n\n";
foreach ([[3, 0], [16, 0], [21, 0]] as [$n, $x]) {
    [$pdf, $d] = $render($n, $x);
    check("$n passengers, no extras: one sheet page + the seat chart", str_starts_with($pdf, '%PDF') && $pages($pdf) === 2, 'pages ' . $pages($pdf) . ' ' . json_encode($d));
}
foreach ([[8, 3], [45, 45], [12, 60], [75, 75]] as [$n, $x]) {
    [$pdf, $d] = $render($n, $x);
    check("$n passengers, $x extras lines: a PDF", str_starts_with($pdf, '%PDF'), 'pages ' . $pages($pdf));
    check('  …every passenger row drawn', (int) ($d['rows'] ?? -1) === $n, json_encode($d));
    check('  …every extras line + the 2 pen lines drawn (the box continues over pages)', (int) ($d['xLines'] ?? -1) === $x + 2);
    check('  …nothing below the footer rule', (float) ($d['maxBottom'] ?? 999) <= (float) ($d['limit'] ?? 0), ($d['maxBottom'] ?? '?') . ' <= ' . ($d['limit'] ?? '?'));
}
[$pdf] = $render(8, 3);
check('8 passengers + 3 extras lines still fit one sheet page (+ chart)', $pages($pdf) === 2, 'pages ' . $pages($pdf));
@unlink($child);
echo "\n  $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
