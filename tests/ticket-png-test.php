<?php
/**
 * PNG ticket system — integration test (5 Sep 2026).
 *
 * Locks in:
 *   • Ticket::pngPath() renders a 1080x1620 PNG, caches via tickets.png_path,
 *     and force-rerenders on demand;
 *   • Ticket::reissue() clears the PNG cache with the PDF one;
 *   • the verify QR (19 Nov 2026: no longer the only QR - a ticket may also
 *     carry a pay QR and map QRs): verifyQrPayload() accepts the keyed verify URL (camera
 *     opens the live page, the boarding scanner posts the same string) and
 *     refuses a tampered key — while the legacy pipe payload still verifies;
 *   • Ticket::imageUrl() carries the download key;
 *   • customer notifications and the download endpoint ship the image first
 *     (static mirrors).
 *
 *   php -c .claude/php-dev.ini tests/ticket-png-test.php
 *
 * Renders against the newest confirmed booking; writes nothing permanent
 * beyond the (normal) cached ticket PNG. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* Pick a booking the ticket renderer can actually LOAD, not merely one that
   has a ticket row. Ticket::loadBooking() inner-joins booking_legs and
   schedules, so a confirmed booking whose schedule has since been deleted —
   which happens when another suite tidies up its own far-future date and the
   cascade takes the leg with it — has a ticket row but cannot be rendered, and
   this test died with a fatal on it. Matching the renderer's own joins here
   makes the choice honest instead of hopeful. */
/* 16 Nov 2026 (battery Y7): the suite used to BORROW the newest confirmed
   booking. With suites running side by side, that booking belongs to another
   suite which deletes it mid-run ("Booking not found for ticket generation"
   after the first checks), and the borrowed ticket was re-issued under that
   suite's feet (its REV checks moved). It now seeds its OWN confirmed booking
   - its own schedule slot on a far date, blocked so search never shows it -
   and removes every row and file it made at the end. */
$route = Database::fetch("SELECT id FROM routes WHERE is_active = 1 AND to_city = 'Rupaidiha' ORDER BY id LIMIT 1")
      ?? Database::fetch('SELECT id FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
if ($route === null) { echo "  SKIP  no active route to seed a ticket on\n"; exit(0); }
const TPNG_DATE = '2099-09-27';
$made = ['sched' => 0, 'book' => 0];
$pnr  = 'SHG-2099-' . random_int(70000, 79999);
$cleanupFixture = static function () use (&$made, &$pnr): void {
    if ($made['book'] > 0) {
        $t = Database::fetch('SELECT png_path, pdf_path FROM tickets WHERE booking_id = :b', ['b' => $made['book']]);
        foreach (['.png', '.pdf', '.png.lock', '.pdf.lock'] as $ext) { @unlink(TICKET_PATH . '/ticket_' . $pnr . $ext); }
        foreach (['png_path', 'pdf_path'] as $c) { if (!empty($t[$c])) { @unlink(TICKET_PATH . '/' . basename((string) $t[$c])); } }
        foreach (['tickets', 'payments', 'booking_passengers', 'booking_seats', 'booking_legs', 'notifications'] as $tb) {
            try { Database::delete($tb, 'booking_id = :b', ['b' => $made['book']]); } catch (Throwable $e) {}
        }
        try { Database::delete('audit_logs', "entity_type = 'booking' AND entity_id = :p", ['p' => $pnr]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :b', ['b' => $made['book']]);
        $made['book'] = 0;
    }
    if ($made['sched'] > 0) {
        Database::delete('schedules', 'id = :s', ['s' => $made['sched']]);
        $made['sched'] = 0;
    }
};
try {
for ($slot = 96; $slot >= 80; $slot--) {
    if (Database::scalar('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = :s', ['r' => (int) $route['id'], 'd' => TPNG_DATE, 's' => $slot]) === null) {
        $made['sched'] = (int) Database::insert('schedules', ['route_id' => (int) $route['id'], 'travel_date' => TPNG_DATE, 'slot' => $slot,
            'dep_time_override' => '13:00:00', 'total_seats' => 40, 'status' => 'scheduled', 'is_blocked' => 1]);
        break;
    }
}
if ($made['sched'] === 0) { echo "  SKIP  no free schedule slot on " . TPNG_DATE . "\n"; exit(0); }
$made['book'] = (int) Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => '9100007301', 'contact_country_code' => '91',
    'booking_mode' => 'sharing', 'fare_per_seat' => 2200, 'base_total' => 2200, 'total_amount' => 2200,
    'status' => 'confirmed', 'source' => 'admin', 'confirmed_at' => date('Y-m-d H:i:s')]);
$tpLeg = (int) Database::insert('booking_legs', ['booking_id' => $made['book'], 'schedule_id' => $made['sched'], 'travel_date' => TPNG_DATE,
    'boarding_stop' => 'Surat', 'drop_stop' => 'Rupaidiha', 'fare_per_seat' => 2200, 'seat_count' => 1, 'leg_total' => 2200]);
Database::insert('booking_passengers', ['booking_id' => $made['book'], 'leg_id' => $tpLeg, 'passenger_ref' => 'TPNG' . substr($pnr, -5),
    'seat_no' => 'L9', 'full_name' => 'Png Test Passenger', 'gender' => 'Male', 'is_primary' => 1]);
$bid = $made['book'];

echo "\n== render + cache ==\n";
$path = Ticket::pngPath($bid, true);
check('renders a file', is_file($path), $path);
check('PNG magic bytes', substr((string) file_get_contents($path), 0, 8) === "\x89PNG\r\n\x1a\n");
$info = getimagesize($path);
/* 1080 wide always; the height GROWS with the passenger list so a family
   ticket can name everyone (10 Sep 2026), and 1620 stays the floor. */
check('1080 wide, at least 1620 tall, image/png',
    $info !== false && $info[0] === 1080 && $info[1] >= 1620 && $info['mime'] === 'image/png',
    $info ? $info[0] . 'x' . $info[1] : 'unreadable');
check('cache column stamped', (string) Database::scalar('SELECT png_path FROM tickets WHERE booking_id = :b', ['b' => $bid], '') === 'ticket_' . $pnr . '.png');
$m1 = filemtime($path); clearstatcache();
Ticket::pngPath($bid);           // cached — must not re-render
clearstatcache();
check('second call reuses the cached file', filemtime($path) === $m1);

echo "\n== reissue clears the image cache ==\n";
Ticket::reissue($bid);
check('png_path cleared on reissue', Database::scalar('SELECT png_path FROM tickets WHERE booking_id = :b', ['b' => $bid]) === null);
check('pdf_path cleared too', Database::scalar('SELECT pdf_path FROM tickets WHERE booking_id = :b', ['b' => $bid]) === null);
Ticket::pngPath($bid);           // restore the cache for the live site
check('re-render restores the cache', (string) Database::scalar('SELECT png_path FROM tickets WHERE booking_id = :b', ['b' => $bid], '') !== '');

echo "\n== verify QR: URL form verifies like the payload ==\n";
$url = appUrl('verify-ticket.php') . '?pnr=' . urlencode($pnr) . '&k=' . Ticket::downloadToken($pnr);
check('verify URL resolves to the PNR', Ticket::verifyQrPayload($url) === $pnr);
check('tampered key is refused', Ticket::verifyQrPayload(str_replace('&k=', '&k=0', $url)) === null);
check('foreign URL is refused', Ticket::verifyQrPayload('https://evil.example/?pnr=' . $pnr . '&k=abc') === null);
$tk = Database::fetch('SELECT qr_payload FROM tickets WHERE booking_id = :b', ['b' => $bid]);
check('legacy pipe payload still verifies', $tk !== null && Ticket::verifyQrPayload((string) $tk['qr_payload']) === $pnr);
check('imageUrl carries the key', str_contains(Ticket::imageUrl($pnr), 'img=1') && str_contains(Ticket::imageUrl($pnr), '&k='));
} catch (Throwable $e) {
    // A crash here must be a FAIL with exit 1, never the app error page (which exits 0).
    check('the render / QR checks ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $cleanupFixture();
}

echo "\n== surfaces ship the image first (static) ==\n";
$root = dirname(__DIR__);
$src  = static fn(string $p): string => (string) file_get_contents($root . '/' . $p);
check('download-ticket.php serves ?img=1', str_contains($src('download-ticket.php'), "_GET['img']"));
check('notify confirm links the image', substr_count($src('includes/notify.php'), 'Ticket::imageUrl') >= 3);
/* The customer side of this rule moved into coPhoneShapeOk() - still exactly 10
   digits, now also starting the way an Indian / a Nepali mobile does (by the
   country picker); the counter keeps its relaxed 8-15 digits or a blank walk-in. */
check('checkout relaxes the phone rule at the counter only', str_contains($src('assets/js/07-checkout.js'), "ctrSell ? (phoneVal === '' || /^\\d{8,15}\$/.test(phoneVal)) : coPhoneShapeOk(phoneVal)")
    && str_contains($src('assets/js/07-checkout.js'), "function coPhoneShapeOk(phv) {\n  if (!/^\\d{10}\$/.test(phv)) return false;"));
check('app auto-download ships the image', str_contains($src('assets/js/07-checkout.js'), 'downloadTicketImage(b); } catch'));
check('reissue deletes the stale rendered files', str_contains($src('includes/ticket.php'), "@unlink(TICKET_PATH . '/ticket_' . \$pnr . '.png')"));
check('share fetches the server PNG', str_contains($src('assets/js/11-pdf-ticket.js'), 'serverTicketPngBlob'));
/* 6 Sep 2026 — the PNG now says who issued it, fits long stop names before
   cutting them, and re-renders an already-cached file once after a layout
   change instead of never.

   10 Sep 2026 — "who issued it" was 17px grey small print at the very
   bottom; the owner asked for the AGENT CODE itself, big. It is now a
   header chip plus a stamped strip, both fed by Ticket::issuedBy(), so
   these guard the new shape. The behaviour of issuedBy() has its own
   suite: chalani-png-test.php. */
check('PNG names who issued the ticket', str_contains($src('includes/ticket.php'), '$issued = self::issuedBy($booking);'));
/* 15 Oct 2026 (letterhead): the issued-by chip sits top right of the header -
   the channel label in gold, then the BIG line, which for an agent is the SHG
   code itself (30px, shrunk only to fit the chip). The stamped "टिकट काट्ने"
   strip still names the seller lower down. '154, $gold, $issued' was the
   pre-letterhead geometry. */
check('the agent code is drawn big, and the channel is named',
    str_contains($src('includes/ticket.php'), "self::gdText(\$im, 15, \$chipX1 + 20, 126, \$gold, \$issued['label'], false);")
    && (bool) preg_match("/\\\$iBig = \\\$issued\\['kind'\\] === 'agent'\\s*\\? \\\$iCode/", $src('includes/ticket.php'))
    && str_contains($src('includes/ticket.php'), 'foreach ([30, 26, 22, 19, 17] as $try) { $cSz = $try; if (self::gdWidth($try, $iBig) <= $chipT) { break; } }')
    && str_contains($src('includes/ticket.php'), 'टिकट काट्ने'));
check('the PDF names the same seller too', substr_count($src('includes/ticket.php'), 'self::issuedBy($booking)') >= 2);
check('PNG fits a long stop name before adding an ellipsis', str_contains($src('includes/ticket.php'), 'foreach ([23, 20, 18] as $try)'));
check('a cached PNG is re-rendered after a layout change', str_contains($src('includes/ticket.php'), 'PNG_LAYOUT_CHANGED'));
/* WhatsApp re-encodes the PNG as JPEG: the small print is darker and the
   seller line is a 20px value in a tile rather than grey 17px, so both
   survive the pass. */
/* 21 Sep 2026: this used to pin the seller line by its EXACT pixel
   expression ("1530 + $grow"). The expression gained "+ $qrExt" when the
   UPI payment QR was added on 20 Sep, and the check has been red ever
   since — failing for a coordinate that moved, while saying nothing about
   the thing it exists to protect. What matters is the SIZE and the COLOUR
   (WhatsApp re-encodes to JPEG and eats thin light-grey small print), so
   that is what it now asserts, at whatever y the layout puts it. */
check('small print sized for WhatsApp recompression',
    preg_match('/self::gdText\(\$im, 20, 82, 1530 \+ \$grow[^,]*, \$ink, \$who, true\);/', $src('includes/ticket.php')) === 1
    && str_contains($src('includes/ticket.php'), 'imagecolorallocate($im, 84, 96, 118)'));
check('auto-download toast no longer says PDF', !preg_match("/autoDlToast: '[^']*PDF/u", $src('assets/js/04-i18n.js')));

echo "\n== the big letters name the passenger's own boarding point (6 Sep 2026) ==\n";
if (!class_exists('Boarding')) { require_once INCLUDE_PATH . '/boarding.php'; }
$d = Boarding::stopDisplay('S Hari Parking, Nana Chiloda @ 21:00 [23.171,72.623]');
check('Nana Chiloda pickup → AMD · Nana Chiloda · 21:00', $d === ['code' => 'AMD', 'name' => 'Nana Chiloda', 'time' => '21:00'], json_encode($d));
$d = Boarding::stopDisplay('Mehsana — Silver Complex @ 23:00');
check('Mehsana — Silver Complex → MSN · Mehsana · 23:00', $d === ['code' => 'MSN', 'name' => 'Mehsana', 'time' => '23:00'], json_encode($d));
$d = Boarding::stopDisplay('Rupaidiha · India-Nepal border checkpoint [28.06,81.617]');
check('a drop label keeps only the town: RPD · Rupaidiha', $d['code'] === 'RPD' && $d['name'] === 'Rupaidiha' && $d['time'] === null, json_encode($d));
$d = Boarding::stopDisplay('Emli Bhupal @ 19:00');
check('Emli Bhupal → EMB · Emli Bhupal · 19:00', $d === ['code' => 'EMB', 'name' => 'Emli Bhupal', 'time' => '19:00'], json_encode($d));
$d = Boarding::stopDisplay('Hari Pvt. Ltd. Parking - Nana Chiloda @ 21:00');
check('the older parking label still resolves to Nana Chiloda', $d['code'] === 'AMD' && $d['name'] === 'Nana Chiloda', json_encode($d));
$d = Boarding::stopDisplay('', 'Surat');
check('an empty leg falls back to the route end', $d['code'] === 'STV' && $d['name'] === 'Surat' && $d['time'] === null, json_encode($d));
check('PNG big letters read the boarding point, not the route origin',
      str_contains($src('includes/ticket.php'), '$ends  = self::tripEnds($booking);') && !str_contains($src('includes/ticket.php'), "self::cityCode((string) (\$booking['from_city']"));
check('PDF reads the same helper', substr_count($src('includes/ticket.php'), 'self::tripEnds($booking)') >= 2);
check('verify page shows the pickup time', str_contains($src('verify-ticket.php'), "Boarding::stopDisplay((string) (\$leg['boarding_stop']"));
check('in-app cards mirror the map', str_contains($src('assets/js/02-config.js'), 'function stopDisplay(') && str_contains($src('assets/js/07-checkout.js'), 'stopDisplay(b.boarding, r.from)'));
check('the counter skips the duplicate-number question', str_contains($src('assets/js/07-checkout.js'), "if (dup && !staffSale && !confirm("));
check('hero strip, cancel modal and share text name the boarding point', substr_count($src('assets/js/07-checkout.js'), "parseBP(b.boarding || '').name || r.from || ''") >= 3);

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
