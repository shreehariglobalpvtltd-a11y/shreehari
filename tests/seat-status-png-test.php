<?php
/**
 * Seat-status PNG card — integration test (23 Sep 2026, v3 brief §9).
 *
 *   php -c .claude/php-dev.ini tests/seat-status-png-test.php
 *
 * Reads the test database; writes PNG files under uploads/seatstatus/. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seatstatuspng.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$sid = (int) Database::scalar(
    "SELECT s.id FROM schedules s LEFT JOIN booking_seats bs ON bs.schedule_id = s.id AND bs.released_at IS NULL
      GROUP BY s.id ORDER BY COUNT(bs.id) DESC, s.id DESC LIMIT 1", [], 0);
if ($sid <= 0) { echo "  SKIP  no departure in the test DB\n"; exit(0); }

echo "\n=== Seat-status card (sid $sid) ===\n\n";
$res = SeatStatusPng::render($sid, true);
check('draws a PNG', is_file($res['path']) && $res['width'] === 1080 && $res['height'] > 600, $res['width'] . 'x' . $res['height']);
$sz = getimagesize($res['path']);
check('file is a real PNG of the reported size', $sz !== false && $sz[0] === $res['width'] && $sz[1] === $res['height']);
check('totals present', isset($res['totals']['booked'], $res['totals']['empty'], $res['totals']['private'], $res['totals']['held']));
$again = SeatStatusPng::render($sid, false);
check('unchanged data -> cached file reused', $again['fresh'] === false && $again['path'] === $res['path']);
$url = SeatStatusPng::publicUrl($sid, 60);
check('public URL is signed through download-chalan.php?doc=status', str_contains($url, 'download-chalan.php') && str_contains($url, 'doc=status') && preg_match('/[?&]k=[0-9a-f]{20}/', $url) === 1);
$cap = SeatStatusPng::caption($res, 'approved');
check('caption carries bus, sold/free counts', str_contains($cap, 'Sold') && str_contains($cap, 'Free') && str_contains($cap, (string) $res['totals']['beds']));
check('no passenger names in the class output', !str_contains($cap, 'contact_phone'));

echo "\n-- switch --\n";
$before = Settings::getString('seat_status_wa_on', '0');
Settings::set('seat_status_wa_on', '0', 'bool', 'whatsapp', false); Settings::flush();
check('off by default -> notify() sends nothing', SeatStatusPng::notify($sid, 'approved', null) === 0 && SeatStatusPng::enabled() === false);
Settings::set('seat_status_wa_on', $before, 'bool', 'whatsapp', false); Settings::flush();
check('recipients fall back to the admin WhatsApp when the list is blank', SeatStatusPng::recipients() !== []);

echo "\n----------------------------------------\nPASSED: {$PASS}   FAILED: {$FAIL}\n----------------------------------------\n\n";
exit($FAIL === 0 ? 0 : 1);
