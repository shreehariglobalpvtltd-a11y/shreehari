<?php
/**
 * =====================================================================
 *  u4b-ticket-discount-test.php — P4-5f (16 Nov 2026): the PNG and PDF
 *  ticket name the discount and its source.
 *
 *  Pure part:
 *    - Ticket::discountTotal() sums offer / desk + group + member tier
 *    - Ticket::discountSource(): the code, 'काउन्टर छुट' for a desk cut (no
 *      code), 'GROUP', the tier name; '' with no discount
 *    - Ticket::discountPctTxt() keeps its old one-argument answer and takes
 *      the summed amount as a second argument
 *    - the PNG / PDF code reads the summed amount and the source (static)
 *
 *  Database part (shari_test only; the fixture booking, its ticket row and
 *  its rendered files are deleted): a booking with a GROUP discount and an
 *  offer code renders a PNG and a PDF without a warning; the PDF text
 *  carries the summed discount. Pass --keep-png <path> to copy the PNG out
 *  for a look.
 *
 *    php -c .claude/php-dev.ini tests/u4b-ticket-discount-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function finish(): never {
    global $PASS, $FAIL;
    echo "\n  $PASS passed, $FAIL failed\n";
    exit($FAIL === 0 ? 0 : 1);
}

echo "\n=== U4b ticket discount line (P4-5f) ===\n\n";
echo "-- Sum and source\n";
$b = ['base_total' => 5000, 'coupon_discount' => 300, 'coupon_code' => 'dashain10', 'group_discount' => 250, 'tier_discount' => 0];
check('offer + group are summed', Ticket::discountTotal($b) === 550.0, (string) Ticket::discountTotal($b));
check('the source names the code (upper case) and GROUP', Ticket::discountSource($b) === 'DASHAIN10 + GROUP', Ticket::discountSource($b));
$desk = ['base_total' => 2200, 'coupon_discount' => 220, 'coupon_code' => null, 'group_discount' => 0, 'tier_discount' => 0];
check('a desk discount (no code) is "काउन्टर छुट"', Ticket::discountSource($desk) === 'काउन्टर छुट');
$tier = ['base_total' => 2200, 'coupon_discount' => 0, 'group_discount' => 0, 'tier_discount' => 66, 'tier_name' => 'Gold'];
check('a member-tier discount names the tier', Ticket::discountSource($tier) === 'GOLD' && Ticket::discountTotal($tier) === 66.0);
check('no tier name: "सदस्य छुट"', Ticket::discountSource(['tier_discount' => 10]) === 'सदस्य छुट');
check('no discount: no source, no total', Ticket::discountSource(['base_total' => 2200]) === '' && Ticket::discountTotal([]) === 0.0);
check('a negative stored amount never counts', Ticket::discountTotal(['coupon_discount' => -50, 'group_discount' => 20]) === 20.0);
check('discountPctTxt() one argument: coupon only, as before', Ticket::discountPctTxt($b) === ' (6%)', Ticket::discountPctTxt($b));
check('discountPctTxt() with the sum: 550 of 5000 = 11%', Ticket::discountPctTxt($b, 550.0) === ' (11%)', Ticket::discountPctTxt($b, 550.0));

echo "\n-- The PNG and PDF code use them\n";
$src = (string) file_get_contents(INCLUDE_PATH . '/ticket.php');
check('PNG: SAVED reads the summed discount and appends the source',
    str_contains($src, '$offerCut = self::discountTotal($booking);') && str_contains($src, '$offerSrc = self::discountSource($booking);'));
check('PNG: the source is dropped only when it cannot fit (the amount always prints)', str_contains($src, "foreach ([18, 16, 15] as \$try)"));
check('PDF: Base - Disc uses the sum, the source sits on the label row',
    str_contains($src, '$discAll = self::discountTotal($booking);') && str_contains($src, "'छुट · ' . mb_substr(\$discSrc, 0, 26)"));

echo "\n-- A real render\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  \033[33mSKIP\033[0m  render: DB_NAME is not shari_test — refusing to write\n";
    finish();
}
$route = Database::fetch("SELECT id, dep_time FROM routes WHERE is_active = 1 AND to_city = 'Rupaidiha' ORDER BY id LIMIT 1");
if ($route === null) {
    echo "  \033[33mSKIP\033[0m  render: needs an active route to Rupaidiha\n";
    finish();
}
$tag   = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
$date  = addDaysISO(todayISO(), 6);
$made  = ['sched' => 0, 'book' => 0];
$pnr   = 'SHG-U4B-' . $tag;
try {
    for ($slot = 96; $slot >= 80; $slot--) {
        if (Database::scalar('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = :s', ['r' => (int) $route['id'], 'd' => $date, 's' => $slot]) === null) {
            $made['sched'] = Database::insert('schedules', ['route_id' => (int) $route['id'], 'travel_date' => $date, 'slot' => $slot,
                'dep_time_override' => '13:00:00', 'total_seats' => 40, 'status' => 'scheduled', 'is_blocked' => 1]);
            break;
        }
    }
    if ($made['sched'] === 0) { throw new RuntimeException('no free schedule slot'); }
    $made['book'] = Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => '9812345678', 'contact_country_code' => '91',
        'booking_mode' => 'sharing', 'fare_per_seat' => 2500, 'base_total' => 12500, 'group_discount' => 625, 'coupon_code' => 'DASHAIN10',
        'coupon_discount' => 500, 'total_amount' => 11375, 'status' => 'confirmed', 'source' => 'admin', 'confirmed_at' => date('Y-m-d H:i:s')]);
    $leg = Database::insert('booking_legs', ['booking_id' => $made['book'], 'schedule_id' => $made['sched'], 'travel_date' => $date,
        'boarding_stop' => 'Surat', 'drop_stop' => 'Rupaidiha', 'fare_per_seat' => 2500, 'seat_count' => 5, 'leg_total' => 12500]);
    foreach (['L1', 'L2', 'L3', 'L4', 'L5'] as $i => $seat) {
        Database::insert('booking_passengers', ['booking_id' => $made['book'], 'leg_id' => $leg, 'passenger_ref' => 'U4B' . $tag . $seat,
            'seat_no' => $seat, 'full_name' => 'Group Passenger ' . ($i + 1), 'gender' => 'Male', 'is_primary' => $i === 0 ? 1 : 0]);
    }
    $png = Ticket::pngPath($made['book'], true);
    $img = is_file($png) ? @getimagesize($png) : false;
    check('the PNG renders', $img !== false && (int) $img[0] === 1080, $png);
    $keep = array_search('--keep-png', $argv, true);
    if ($keep !== false && isset($argv[$keep + 1]) && is_file($png)) {
        copy($png, (string) $argv[$keep + 1]);
        echo "  (PNG copied to " . $argv[$keep + 1] . ")\n";
    }
    $pdfPath = Ticket::pdfPath($made['book'], true);
    $pdf = is_file($pdfPath) ? (string) file_get_contents($pdfPath) : '';
    $keepPdf = array_search('--keep-pdf', $argv, true);
    if ($keepPdf !== false && isset($argv[$keepPdf + 1]) && $pdf !== '') {
        file_put_contents((string) $argv[$keepPdf + 1], $pdf);
    }
    check('the PDF renders', str_starts_with($pdf, '%PDF'), $pdfPath);
    $plain = $pdf;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $s) { $z = @gzuncompress($s); if ($z !== false) { $plain .= $z; } }
    }
    // the PDF writer escapes ( ) inside a text string: "Disc INR 1,125 \(9%\)"
    check('the PDF fare box says Disc of the SUM (500 + 625 = 1,125) and 9%', preg_match('/Disc INR 1,125 \\\\?\(9%\\\\?\)/', $plain) === 1,
        preg_match('/\(Base INR[^\n]{0,70}/', $plain, $mm) ? $mm[0] : 'no Base line found');
} catch (Throwable $e) {
    check('render ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($made['book'] > 0) {
        $t = Database::fetch('SELECT png_path, pdf_path FROM tickets WHERE booking_id = :b', ['b' => $made['book']]);
        foreach (['.png', '.pdf', '.png.lock', '.pdf.lock'] as $ext) {
            @unlink(TICKET_PATH . '/ticket_' . $pnr . $ext);
        }
        foreach (['png_path', 'pdf_path'] as $c) {
            if (!empty($t[$c])) { @unlink(TICKET_PATH . '/' . basename((string) $t[$c])); }
        }
        foreach (['tickets', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications'] as $tb) {
            try { Database::delete($tb, 'booking_id = :b', ['b' => $made['book']]); } catch (Throwable $e) {}
        }
        Database::delete('bookings', 'id = :b', ['b' => $made['book']]);
    }
    if ($made['sched'] > 0) {
        Database::delete('schedules', 'id = :s', ['s' => $made['sched']]);
    }
}
check('cleanup left nothing behind', (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE pnr = :p', ['p' => $pnr], 0) === 0);
finish();
