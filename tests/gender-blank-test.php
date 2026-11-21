<?php
/**
 * gender-blank-test.php — shared berth: women-only / men-only, family only on
 * one ticket, and a BLANK gender can no longer slip past the rule (21 Nov 2026).
 * Every case runs inside a transaction that is rolled back.
 *
 *   php -c .claude/php-dev.ini tests/gender-blank-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
echo "\n=== Shared berth gender rule (blank gender) ===\n\n";

$sid = (int) Database::scalar("SELECT s.id FROM schedules s JOIN routes r ON r.id = s.route_id WHERE r.coach_type = 'sleeper' ORDER BY s.id DESC LIMIT 1", [], 0);
if ($sid === 0) { check('a sleeper schedule exists', false); exit(1); }

$bk = Database::fetch('SELECT b.id, l.id AS leg FROM bookings b JOIN booking_legs l ON l.booking_id = b.id ORDER BY b.id DESC LIMIT 1');
if ($bk === null) { check('a booking exists to borrow ids from', false); exit(1); }

/** Each sale = [seats, genders]; after a sale passes, its seats are really taken
 *  (booking_seats + booking_passengers) so the next sale sees them. All rolled back. */
$try = static function (array $sales) use ($sid, $bk): string {
    Database::begin();
    try {
        Database::run("DELETE FROM schedule_unit_locks WHERE schedule_id = :s", ['s' => $sid]);
        Database::run("UPDATE booking_seats SET released_at = NOW() WHERE schedule_id = :s AND released_at IS NULL", ['s' => $sid]);
        foreach ($sales as $n => [$seats, $genders]) {
            Seats::assertGenderAllowed($sid, 'sleeper', 'sharing', $seats, $genders);
            foreach ($seats as $i => $seat) {
                Database::insert('booking_seats', ['schedule_id' => $sid, 'seat_no' => $seat, 'booking_id' => $bk['id'], 'leg_id' => $bk['leg']]);
                Database::insert('booking_passengers', ['booking_id' => $bk['id'], 'leg_id' => $bk['leg'], 'passenger_ref' => 'gt' . $n . $i . bin2hex(random_bytes(3)),
                    'seat_no' => $seat, 'full_name' => 'Gender test', 'gender' => $genders[$i]]);
            }
        }
        return '';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    } finally {
        Database::rollBack();
    }
};
$cab = Seats::unitSeats('L-9', 'sleeper');
check('test cabin has two berths', count($cab) === 2, implode(',', $cab));
[$a, $b] = array_values($cab);

Settings::set('gender_required_shared', '1', 'bool', 'booking');
Settings::flush();

check('woman alone OK', $try([[[$a], ['Female']]]) === '');
check('woman then man (other ticket) refused', $try([[[$a], ['Female']], [[$b], ['Male']]]) !== '');
check('man then woman (other ticket) refused', $try([[[$a], ['Male']], [[$b], ['Female']]]) !== '');
check('woman then woman OK', $try([[[$a], ['Female']], [[$b], ['Female']]]) === '');
check('man then man OK', $try([[[$a], ['Male']], [[$b], ['Male']]]) === '');
check('family: man + woman on ONE ticket, whole berth OK', $try([[[$a, $b], ['Male', 'Female']]]) === '');
check('blank gender alone on an empty berth still sells (counter not stopped)', $try([[[$a], [null]]]) === '');
$e = $try([[[$a], ['Female']], [[$b], [null]]]);
check('blank gender next to a woman refused', str_contains($e, 'Male or Female'), $e);
$e = $try([[[$a], [null]], [[$b], ['Female']]]);
check('woman next to a blank-gender passenger refused', str_contains($e, 'no gender recorded'), $e);
check('man next to a blank-gender passenger refused', $try([[[$a], [null]], [[$b], ['Male']]]) !== '');
check('family with a blank gender on ONE ticket, whole berth OK', $try([[[$a, $b], ['Female', null]]]) === '');

Settings::set('gender_required_shared', '0', 'bool', 'booking');
Settings::flush();
check('switch off: woman next to blank gender allowed again', $try([[[$a], [null]], [[$b], ['Female']]]) === '');
Database::run("DELETE FROM settings WHERE skey = 'gender_required_shared'");
Settings::flush();

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
