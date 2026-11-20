<?php
/**
 * normal-fare-test.php — NormalFare (20 Nov 2026, nf1): the route's REAL normal
 * fare is shown only when the switch is on, a fare is set, and it is higher than
 * what is really charged. Draws one ticket PNG + PDF with it on (path printed).
 *    php -c .claude/php-dev.ini tests/normal-fare-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/normalfare.php';

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $m) use (&$pass, &$fail): void { $c ? $pass++ : $fail++; echo ($c ? 'PASS ' : 'FAIL ') . $m . PHP_EOL; };

$oldJson = Settings::get('normal_fare_json', null);
$oldOn   = Settings::get('normal_fare_on', null);
$b = Database::fetch("SELECT b.* FROM bookings b JOIN booking_legs l ON l.booking_id = b.id
                       WHERE b.status = 'confirmed' AND b.total_amount > 0 ORDER BY b.id DESC LIMIT 1");
$rid = $b ? (int) Database::scalar('SELECT s.route_id FROM booking_legs l JOIN schedules s ON s.id = l.schedule_id WHERE l.booking_id = :b LIMIT 1', ['b' => $b['id']]) : 0;
try {
    Settings::set('normal_fare_on', '0', 'bool', 'pricing');
    Settings::set('normal_fare_json', json_encode([(string) $rid => 9999]), 'json', 'pricing');
    Settings::flush();
    $ok(NormalFare::compare($rid, 1, 2000) === null, 'switch off = nothing shown');

    Settings::set('normal_fare_on', '1', 'bool', 'pricing');
    Settings::flush();
    $c = NormalFare::compare($rid, 2, 4000);
    $ok($c !== null && $c['normal'] == 19998 && $c['save'] == 15998, 'on: 2 seats x 9999 vs 4000 paid');
    $ok(NormalFare::compare($rid, 1, 9999) === null, 'equal price = no saving shown');
    $ok(NormalFare::compare($rid, 1, 12000) === null, 'price above normal = nothing shown');
    $ok(NormalFare::compare($rid + 999, 1, 100) === null, 'route without a normal fare = nothing');

    if ($b) {
        $f = NormalFare::forBooking($b);
        $ok($f !== null && $f['save'] > 0, 'forBooking ' . $b['pnr'] . ' -> ' . json_encode($f));
        $png = Ticket::pngPath((int) $b['id'], true);
        $ok(is_file($png) && filesize($png) > 10000, 'ticket PNG drawn: ' . $png);
        $pdf = Ticket::pdfPath((int) $b['id'], true);
        $ok(is_file($pdf) && filesize($pdf) > 5000, 'ticket PDF drawn: ' . $pdf);
    }
} finally {
    $oldJson === null ? Database::run("DELETE FROM settings WHERE skey = 'normal_fare_json'") : Settings::set('normal_fare_json', is_string($oldJson) ? $oldJson : json_encode($oldJson), 'json', 'pricing');
    $oldOn === null ? Database::run("DELETE FROM settings WHERE skey = 'normal_fare_on'") : Settings::set('normal_fare_on', (string) $oldOn, 'bool', 'pricing');
    Settings::flush();
}
echo PHP_EOL . "$pass passed, $fail failed" . PHP_EOL;
exit($fail ? 1 : 0);
