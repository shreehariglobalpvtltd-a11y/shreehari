<?php
/**
 * tests/helpdesk-campaign-nav-test.php — 5 Oct 2026 upgrade:
 * Help Desk numbering / automation, All Customers directory, Campaigns,
 * Google Drive backup state, SHARI NAV search + route proxy guards.
 *
 *   php -c .claude/php-dev.ini tests/helpdesk-campaign-nav-test.php
 *
 * Writes test rows (phone 98000555xx) and removes them again.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/helpdesk.php';
require_once INCLUDE_PATH . '/customerdirectory.php';
require_once INCLUDE_PATH . '/campaigns.php';
require_once INCLUDE_PATH . '/gdrive.php';

$db = (string) (Database::fetch('SELECT DATABASE() AS d')['d'] ?? '');
if (!preg_match('/test/i', $db)) {
    fwrite(STDERR, "refusing to run against '$db' (not a test database)\n");
    exit(1);
}

$pass = 0;
$fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) {
        $pass++;
        echo "  ok   $what\n";
    } else {
        $fail++;
        echo "  FAIL $what\n";
    }
};

echo "Help desk\n";
$ok(HelpDesk::refFor(51) === 'HD-0051', 'refFor pads to 4 digits');
$ok(HelpDesk::normRef('hd 51') === 'HD-0051' && HelpDesk::normRef('#51') === 'HD-0051' && HelpDesk::normRef('51') === 'HD-0051', 'normRef reads what people type');
$ok(HelpDesk::normRef('SUP-260924-AB12C') === 'SUP-260924-AB12C', 'normRef keeps old SUP refs');
$ok(HelpDesk::normRef('abc') === null && HelpDesk::normRef('0') === null, 'normRef rejects junk');
$ok(HelpDesk::guessCategory('bus kaha cha, still not came') === 'bus_late', 'guess: bus late');
$ok(HelpDesk::guessCategory('paisa kat gaya UPI se') === 'payment', 'guess: payment');
$ok(HelpDesk::guessCategory('मेरो टिकट आएन') === 'ticket', 'guess: ticket (Nepali)');
$ok(HelpDesk::guessCategory('there was an accident') === 'safety', 'guess: safety');
$ok(HelpDesk::priorityFor('other', 'driver is drunk, danger') === 'urgent', 'safety words are urgent');
$ok(HelpDesk::priorityFor('seat', 'seat broken', 'low') === 'low', 'asked priority wins');

if (HelpDesk::ready(true)) {
    Database::run("DELETE FROM support_tickets WHERE phone LIKE '%98000555%'");
    $r = HelpDesk::open(['name' => 'Test HD', 'phone' => '9800055501', 'country' => 'NP', 'message' => 'Bus late at Kohalpur, not came', 'ack' => false]);
    $ok($r['ok'] && preg_match('/^HD-\d{4,}$/', (string) $r['ref']) === 1, 'open() gives an HD number');
    $t = Database::fetch('SELECT * FROM support_tickets WHERE id = :id', ['id' => (int) $r['id']]);
    $ok($t !== null && $t['phone'] === '9779800055501', 'Nepal number stored with 977');
    $ok($t !== null && $t['category'] === 'bus_late' && $t['priority'] === 'urgent', 'category + priority guessed');
    $ok($t !== null && $t['due_at'] !== null && strtotime((string) $t['due_at']) > time(), 'SLA due time set');
    $ok((int) ($t['day_token'] ?? 0) >= 1, 'token of the day stamped');
    $r2 = HelpDesk::open(['name' => 'Test HD', 'phone' => '9800055501', 'country' => 'NP', 'message' => 'Bus late at Kohalpur, not came', 'ack' => false]);
    $ok(!empty($r2['duplicate']) && $r2['ref'] === $r['ref'], 'same words within 15 min = same ticket');
    $v = HelpDesk::publicView('hd ' . $r['id'], '+977 9800055501');
    $ok($v !== null && $v['status'] === 'open', 'publicView with the right phone');
    $ok(HelpDesk::publicView((string) $r['ref'], '9800055599') === null, 'publicView refuses another phone');
    $ok(HelpDesk::customerReply((string) $r['ref'], '9800055501', 'I am at the chowk'), 'customer can add a line');
    $bad = HelpDesk::open(['phone' => '123', 'message' => 'x y z']);
    $ok(!$bad['ok'] && $bad['error'] === 'phone', 'short phone rejected');
    Database::run("UPDATE support_tickets SET due_at = NOW() - INTERVAL 1 MINUTE WHERE id = :id", ['id' => (int) $r['id']]);
    $tick = HelpDesk::tick();
    $esc = Database::fetch('SELECT escalated_at FROM support_tickets WHERE id = :id', ['id' => (int) $r['id']]);
    $ok($tick['escalated'] >= 1 && $esc['escalated_at'] !== null, 'overdue ticket escalated once');
    HelpDesk::tick();
    $esc2 = Database::fetch('SELECT escalated_at FROM support_tickets WHERE id = :id', ['id' => (int) $r['id']]);
    $ok($esc2['escalated_at'] === $esc['escalated_at'], 'second tick does not re-escalate the same ticket');
    Database::run("DELETE FROM support_messages WHERE ticket_id IN (SELECT id FROM support_tickets WHERE phone LIKE '%98000555%')");
    Database::run("DELETE FROM support_tickets WHERE phone LIKE '%98000555%'");
} else {
    echo "  skip open()/tick(): apply database/upgrade-2026-10-05-helpdesk.sql\n";
}

echo "Customer directory\n";
$f = CustomerDirectory::filters(['range' => '7d', 'agent' => 'online', 'country' => 'NP', 'min' => '2', 'alt' => '0']);
$ok($f['from'] === date('Y-m-d', strtotime('-6 days')) && $f['to'] === date('Y-m-d'), 'range 7d → dates');
$ok($f['agent'] === 'online' && $f['country'] === 'NP' && $f['min'] === 2 && $f['alt'] === false, 'filters kept');
parse_str(CustomerDirectory::qs($f), $back);
$ok(($back['agent'] ?? '') === 'online' && ($back['alt'] ?? '') === '0', 'qs round-trips');
$res = CustomerDirectory::rows(CustomerDirectory::filters([]), 5, 0);
$ok(is_array($res['rows']) && $res['total'] >= count($res['rows']), 'rows() answers');
$ok(is_array(CustomerDirectory::agentBreakdown(CustomerDirectory::filters([]))), 'agentBreakdown() answers');
$vc = CustomerDirectory::vcards([['ph' => '9800055501', 'intl' => '919800055501', 'name' => 'Ram; Test']]);
$ok(str_contains($vc, 'TEL;TYPE=CELL:+919800055501') && !str_contains($vc, 'Ram;'), 'vCard escapes the name');

echo "Campaigns\n";
$c = ['id' => 7, 'message' => "Namaste {first}\nOffer {link}", 'link_url' => 'https://example.com/x?a=1', 'channel' => 'wa_manual', 'template_params' => '[]'];
$ok(Campaigns::link($c) === 'https://example.com/x?a=1&src=cmp7', 'campaign link tagged');
$txt = Campaigns::textFor($c, ['name' => 'Ram Bahadur']);
$ok(str_starts_with($txt, 'Namaste Ram') && str_contains($txt, 'src=cmp7') && str_contains($txt, 'STOP'), 'text rendered with opt-out line');
$ok(Campaigns::render('{name}', $c, ['name' => '']) !== '', 'empty name gets a polite default');
if (Campaigns::ready()) {
    Database::run("DELETE FROM mk_optout WHERE phone = '9800055502'");
    $ok(Campaigns::optOut('+91 98000 55502', 'test', 0), 'optOut stores a number');
    $ok(isset(CustomerDirectory::optedOut()['9800055502']), 'opted-out numbers are known');
    Database::run("DELETE FROM mk_optout WHERE phone = '9800055502'");
    $ok(!Campaigns::optOut('12345', 'x', 0), 'optOut refuses a short number');
} else {
    echo "  skip opt-out: apply database/upgrade-2026-10-05-campaigns.sql\n";
}

echo "Google Drive backup\n";
$ok(str_ends_with(GDrive::redirectUri(), '/admin/backup.php?oauth=1'), 'redirect URI');
$ok(!GDrive::connected() || GDrive::configured(), 'connected implies configured');
$u = GDrive::configured() ? GDrive::authUrl('s') : '';
$ok($u === '' || (str_contains($u, 'drive.file') && str_contains($u, 'access_type=offline')), 'auth URL asks only drive.file, offline');
$ok(is_array(DbDump::listLocal(3)), 'local backup list');

echo "SHARI NAV\n";
$ok(is_file(dirname(__DIR__) . '/api/nav-route.php') && is_file(dirname(__DIR__) . '/api/nav-search.php'), 'own route + search endpoints exist');
$js = (string) file_get_contents(dirname(__DIR__) . '/assets/js/15-nav.js');
$ok(!str_contains($js, "fetch('https://valhalla1.openstreetmap.de"), 'navigator no longer calls the public router directly');
$ok(str_contains($js, "addProtocol('shgt'") && str_contains($js, 'snNavCamOn'), 'own tile protocol + driving camera present');
$ok(is_file(dirname(__DIR__) . '/assets/vendor/maplibre-gl-4.7.1/maplibre-gl.js'), 'map engine served from our server');

// all of India + Nepal: ~1.5 million places — a "%q%" search must stay inside a lat/lng box
$srch = (string) file_get_contents(dirname(__DIR__) . '/api/nav-search.php');
preg_match_all('/"[^"]*FROM nav_places[^"]*"/s', $srch, $qs);
$scan = array_filter($qs[0], static fn ($s) => preg_match('/LIKE :c\d/', $s) === 1 && !str_contains($s, 'lat BETWEEN'));
$ok($qs[0] !== [] && $scan === [], 'place search: no unbounded contains-scan of nav_places');
$cover = (string) @file_get_contents(dirname(__DIR__) . '/deploy/navdata/navcover.py');
$ok(str_contains($cover, 'def inside_tiles') && str_contains($cover, 'strip_rects'), 'coverage boxes come from the region polygons');
$poly = sys_get_temp_dir() . '/shg-cover-test.poly';
file_put_contents($poly, "t\n1\n 70 20\n 76 20\n 76 26\n 70 26\nEND\nEND\n");
$py = trim((string) @shell_exec((PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3') . ' ' . escapeshellarg(dirname(__DIR__) . '/deploy/navdata/navcover.py') . ' ' . escapeshellarg($poly) . ' 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null')));
$boxes = json_decode($py, true);
if (is_array($boxes)) {
    $in = static fn (float $x, float $y): bool => (bool) array_filter($boxes, static fn ($b) => $b[0] <= $x && $x <= $b[2] && $b[1] <= $y && $y <= $b[3]);
    $ok($boxes !== [] && $in(73.0, 23.0) && !$in(69.5, 23.0) && !$in(73.0, 26.5), 'coverage boxes: inside the polygon only');
} else {
    echo "  skip coverage run: no python\n";
}
@unlink($poly);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
