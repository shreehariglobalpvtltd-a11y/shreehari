<?php
/**
 * PNG ticket system — integration test (5 Sep 2026).
 *
 * Locks in:
 *   • Ticket::pngPath() renders a 1080x1620 PNG, caches via tickets.png_path,
 *     and force-rerenders on demand;
 *   • Ticket::reissue() clears the PNG cache with the PDF one;
 *   • the ONE QR: verifyQrPayload() accepts the keyed verify URL (camera
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
$b = Database::fetch(
    "SELECT b.id, b.pnr
       FROM bookings b
       JOIN tickets t ON t.booking_id = b.id
       JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
       JOIN schedules s ON s.id = l.schedule_id
       JOIN routes r ON r.id = s.route_id
      WHERE b.status = 'confirmed'
      ORDER BY b.id DESC LIMIT 1"
);
if ($b === null) { echo "  SKIP  no confirmed booking with a renderable ticket\n"; exit(0); }
$bid = (int) $b['id']; $pnr = (string) $b['pnr'];

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

echo "\n== one QR: URL form verifies like the payload ==\n";
$url = appUrl('verify-ticket.php') . '?pnr=' . urlencode($pnr) . '&k=' . Ticket::downloadToken($pnr);
check('verify URL resolves to the PNR', Ticket::verifyQrPayload($url) === $pnr);
check('tampered key is refused', Ticket::verifyQrPayload(str_replace('&k=', '&k=0', $url)) === null);
check('foreign URL is refused', Ticket::verifyQrPayload('https://evil.example/?pnr=' . $pnr . '&k=abc') === null);
$tk = Database::fetch('SELECT qr_payload FROM tickets WHERE booking_id = :b', ['b' => $bid]);
check('legacy pipe payload still verifies', $tk !== null && Ticket::verifyQrPayload((string) $tk['qr_payload']) === $pnr);
check('imageUrl carries the key', str_contains(Ticket::imageUrl($pnr), 'img=1') && str_contains(Ticket::imageUrl($pnr), '&k='));

echo "\n== surfaces ship the image first (static) ==\n";
$root = dirname(__DIR__);
$src  = static fn(string $p): string => (string) file_get_contents($root . '/' . $p);
check('download-ticket.php serves ?img=1', str_contains($src('download-ticket.php'), "_GET['img']"));
check('notify confirm links the image', substr_count($src('includes/notify.php'), 'Ticket::imageUrl') >= 3);
check('checkout relaxes the phone rule at the counter only', str_contains($src('assets/js/07-checkout.js'), "ctrSell ? (phoneVal === '' || /^\\d{8,15}\$/.test(phoneVal)) : /^\\d{10}\$/.test(phoneVal)"));
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
check('the agent code is drawn big, and the channel is named',
    str_contains($src('includes/ticket.php'), '154, $gold, $issued')
    && str_contains($src('includes/ticket.php'), 'टिकट काट्ने'));
check('the PDF names the same seller too', substr_count($src('includes/ticket.php'), 'self::issuedBy($booking)') >= 2);
check('PNG fits a long stop name before adding an ellipsis', str_contains($src('includes/ticket.php'), 'foreach ([23, 20, 18] as $try)'));
check('a cached PNG is re-rendered after a layout change', str_contains($src('includes/ticket.php'), 'PNG_LAYOUT_CHANGED'));
/* WhatsApp re-encodes the PNG as JPEG: the small print is darker and the
   seller line is a 20px value in a tile rather than grey 17px, so both
   survive the pass. */
check('small print sized for WhatsApp recompression',
    str_contains($src('includes/ticket.php'), 'self::gdText($im, 20, 82, 1530 + $grow, $ink, $who, true);')
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
