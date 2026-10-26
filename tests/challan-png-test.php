<?php
/**
 * Bus challan PNG — integration test (SHG AI BRAIN Phase 1.5, 10 Sep 2026).
 *
 * Locks in:
 *   • ChallanPng::render() draws a print-width PNG (>= 1200 px, the prompt's
 *     floor) for a sleeper AND a seater departure, both decks, every berth;
 *   • the picture is cached on the DATA fingerprint: an unchanged coach is
 *     handed back without a redraw, a changed one (or force) is redrawn;
 *   • every render leaves a challan_reports row and a challan.generated audit;
 *   • a private cabin is expanded onto its PHYSICAL beds (the same rule the
 *     seat map and the sale gate use), never onto a same-named sharing berth;
 *   • the totals add up: sold + empty + held + out-of-service = berths;
 *   • roman() strips Devanagari the way the PNG ticket does;
 *   • Logger::audit() keeps the new reason + actor_role columns.
 *
 *   php -c .claude/php-dev.ini tests/challan-png-test.php
 *
 * Reads the test database; writes only PNG files under uploads/challan/ and
 * challan_reports / audit_logs rows. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/challanpng.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* The busiest departure of each coach type — a picture with people on it. */
function busiest(string $coach): ?int {
    $id = Database::scalar(
        "SELECT s.id FROM schedules s JOIN routes r ON r.id = s.route_id
           LEFT JOIN booking_seats bs ON bs.schedule_id = s.id AND bs.released_at IS NULL
          WHERE COALESCE(s.coach_type_override, r.coach_type) = :c
          GROUP BY s.id ORDER BY COUNT(bs.id) DESC, s.id DESC LIMIT 1",
        ['c' => $coach], 0
    );
    return $id > 0 ? (int) $id : null;
}

$hasTable = false;
try { Database::scalar('SELECT COUNT(*) FROM challan_reports', [], 0); $hasTable = true; } catch (Throwable $e) {}
check('challan_reports table present (database/upgrade-2026-09-challan-png.sql applied)', $hasTable);

foreach (['sleeper', 'seater'] as $coach) {
    $sid = busiest($coach);
    echo "\n== $coach ==\n";
    if ($sid === null) { echo "  SKIP  no $coach departure in the test DB\n"; continue; }

    $sched = ChallanPng::schedule($sid);
    check("schedule row loads (sid $sid)", $sched !== null);
    $data = ChallanPng::collect($sid, $sched);
    $tot  = $data['totals'];
    check('coach resolved', $data['coach'] === $coach, $data['coach']);
    check('every berth of the coach is drawn', count($data['beds']) === count(Seats::seatIds($coach, 'sharing')), count($data['beds']) . ' beds');
    check('totals add up', $tot['booked'] + $tot['empty'] + $tot['held'] + $tot['blocked'] + $tot['staff'] === $tot['beds'],
        "sold {$tot['booked']} + empty {$tot['empty']} + held {$tot['held']} + blocked {$tot['blocked']} + staff {$tot['staff']} = {$tot['beds']}");

    /* Private cabins land on their physical beds, never on a same-named berth. */
    $private = Database::fetchAll(
        "SELECT bs.seat_no FROM booking_seats bs JOIN bookings b ON b.id = bs.booking_id
          WHERE bs.schedule_id = :s AND bs.released_at IS NULL AND b.booking_mode = 'private'", ['s' => $sid]);
    if ($private !== [] && $coach === 'sleeper') {
        $ok = true;
        foreach ($private as $p) {
            foreach (Seats::physicalSeats((string) $p['seat_no'], 'private', 'sleeper') as $bed) {
                if (($data['beds'][$bed]['cabin'] ?? null) !== (string) $p['seat_no']) { $ok = false; }
            }
        }
        check('private cabins expanded onto their physical beds', $ok, count($private) . ' cabin(s)');
    }

    $t0 = microtime(true);
    $r1 = ChallanPng::render($sid, 'test', null, true);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    check('renders a file', is_file($r1['path']), basename($r1['path']));
    check('under 5 seconds (prompt target)', $ms < 5000, $ms . ' ms');
    check('PNG magic bytes', substr((string) file_get_contents($r1['path']), 0, 8) === "\x89PNG\r\n\x1a\n");
    $info = getimagesize($r1['path']);
    check('print width >= 1200 px', $info !== false && $info[0] === ChallanPng::WIDTH && $info[0] >= 1200, $info ? $info[0] . 'x' . $info[1] : 'unreadable');
    check('tall enough for every deck', $info !== false && $info[1] > 900, $info ? (string) $info[1] : '');
    check('stored under uploads/challan/<date>/', str_contains(str_replace('\\', '/', $r1['path']), '/challan/' . $sched['travel_date'] . '/'));
    check('fresh render reported', $r1['fresh'] === true);

    if ($hasTable) {
        $row = ChallanPng::latest($sid);
        check('challan_reports row written', $row !== null && (string) $row['fingerprint'] === $r1['fingerprint']);
        $r2 = ChallanPng::render($sid, 'test');
        check('unchanged coach reuses the cached file', $r2['fresh'] === false && $r2['path'] === $r1['path']);
        $r3 = ChallanPng::render($sid, 'test', null, true);
        check('force redraws', $r3['fresh'] === true);
        $rows = (int) Database::scalar('SELECT COUNT(*) FROM challan_reports WHERE schedule_id = :s', ['s' => $sid], 0);
        check('one log row per render', $rows >= 2, $rows . ' rows');
    }
    $audit = Database::fetch("SELECT id FROM audit_logs WHERE action = 'challan.generated' AND entity_id = :s ORDER BY id DESC LIMIT 1", ['s' => (string) $sid]);
    check('challan.generated audit row', $audit !== null);
    check('fingerprint stable across reads', ChallanPng::collect($sid, $sched)['fingerprint'] === $r1['fingerprint']);
}

echo "\n== helpers ==\n";
check('roman() drops Devanagari, keeps Latin', ChallanPng::roman('राम Sharma (नेपाल)') === 'Sharma');
check('roman() maps smart punctuation', ChallanPng::roman("Nana\xC2\xA0Chiloda \xE2\x80\x94 border") === 'Nana Chiloda - border');
check('busLabel() is file-safe', preg_match('/^[A-Z0-9-]+$/', ChallanPng::busLabel(['bus_number' => 'GJ 02 T-5580', 'route_code' => 'r2'])) === 1);
check('challanNo() matches the chalani PDF form', ChallanPng::challanNo(['travel_date' => '2026-09-10', 'route_code' => 'r2', 'slot' => 1]) === 'CH-20260910-R2');

echo "\n== audit reason / role ==\n";
$cols = array_map(static fn(array $c): string => (string) $c['Field'], Database::fetchAll('SHOW COLUMNS FROM audit_logs'));
$hasReason = in_array('reason', $cols, true) && in_array('actor_role', $cols, true);
check('audit_logs has reason + actor_role columns', $hasReason);
$_SESSION[ADMIN_SESSION_KEY] = ['id' => 1, 'username' => 'challan-test', 'role' => 'counter'];
Logger::audit('test.challan_reason', 'booking', 'TEST-PNR', ['seat' => 'L1'], ['seat' => 'L2'], 'test detail', 'customer asked');
$last = Database::fetch("SELECT * FROM audit_logs WHERE action = 'test.challan_reason' ORDER BY id DESC LIMIT 1");
check('audit row written with a reason', $last !== null && (!$hasReason || (string) ($last['reason'] ?? '') === 'customer asked'));
check('audit row carries the actor role', $last !== null && (!$hasReason || (string) ($last['actor_role'] ?? '') === 'counter'));
unset($_SESSION[ADMIN_SESSION_KEY]);
if ($last !== null) { Database::run('DELETE FROM audit_logs WHERE id = :id', ['id' => (int) $last['id']]); }

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
