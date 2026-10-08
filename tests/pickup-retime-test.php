<?php
/**
 * =====================================================================
 *  pickup-retime-test.php — the passenger's pickup on a RETIMED trip
 *
 *  Boarding::pickupTs() is the one clock for the trip reminders, the
 *  ticket's live pill and the PDF departure line. The sold stop labels
 *  keep the timetable's "@ 13:00" when the office moves the daily bus
 *  (Admin -> Schedule -> Edit dep time), and Quick Ticket labels an extra
 *  bus with the timetable time too. Read naively, a 13:00 -> 15:00 retime
 *  put the Surat pickup at 13:00 the NEXT day, so the "reach your stop"
 *  reminders reached passengers already on the bus (review, 8 Oct 2026).
 *  This suite pins:
 *
 *    • the plain timetable answers (same day, past-midnight stops)
 *    • a slot-1 retime later / earlier moves every stop by the same delta
 *    • an extra bus sold through search (labels already shifted) is not
 *      shifted twice; one sold through Quick Ticket (timetable label) is
 *    • a typed "Other" pickup time is taken as it is
 *    • the reminder scan carries the timetable clock + a departed-bus net
 *
 *    php -c .claude/php-dev.ini tests/pickup-retime-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/boarding.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Pickup time on a retimed trip ===\n\n";
$fmt = static fn(int $t): string => $t > 0 ? date('Y-m-d H:i', $t) : '0';

// [what, label, boardingTime, effective dep, route timetable dep, slot, expected]
$cases = [
    ['timetable: origin',               'Surat · Departure @ 13:00', null,    '13:00:00', '13:00:00', 1, '2026-10-08 13:00'],
    ['timetable: later stop',           'Kamrej @ 13:30',            null,    '13:00:00', '13:00:00', 1, '2026-10-08 13:30'],
    ['timetable: evening stop',         'Mehsana @ 23:00',           null,    '13:00:00', '13:00:00', 1, '2026-10-08 23:00'],
    ['timetable: past midnight',        'Vadodara @ 00:00',          null,    '21:00:00', '21:00:00', 1, '2026-10-09 00:00'],
    ['retimed later: origin',           'Surat · Departure @ 13:00', null,    '15:00:00', '13:00:00', 1, '2026-10-08 15:00'],
    ['retimed later: later stop',       'Kamrej @ 13:30',            null,    '15:00:00', '13:00:00', 1, '2026-10-08 15:30'],
    ['retimed earlier: origin',         'Surat · Departure @ 13:00', null,    '11:00:00', '13:00:00', 1, '2026-10-08 11:00'],
    ['retimed earlier: evening stop',   'Mehsana @ 23:00',           null,    '11:00:00', '13:00:00', 1, '2026-10-08 21:00'],
    ['extra bus, search label (shifted)', 'Surat · Departure @ 21:00', null,  '21:00:00', '13:00:00', 2, '2026-10-08 21:00'],
    ['extra bus, search label, stop',   'Kamrej @ 21:30',            null,    '21:00:00', '13:00:00', 2, '2026-10-08 21:30'],
    ['old 4-argument call unchanged',   'Kamrej @ 13:30',            null,    '13:00:00', null,       1, '2026-10-08 13:30'],
    ['typed Other pickup time',         'Other — Navsari',           '14:10', '13:00:00', '13:00:00', 1, '2026-10-08 14:10'],
];
foreach ($cases as [$what, $label, $bt, $dep, $rdep, $slot, $want]) {
    $got = $fmt(Boarding::pickupTs('2026-10-08', $label, $bt, $dep, $rdep, $slot, 0));
    check("$what ($label, dep $dep)", $got === $want, $got . ($got === $want ? '' : " — want $want"));
}

echo "-- Quick Ticket extra bus (timetable label, real stop row) --\n";
$r = Database::fetch('SELECT id, dep_time FROM routes WHERE dep_time IS NOT NULL ORDER BY id LIMIT 1');
$did = false;
if ($r !== null) {
    foreach (Boarding::stopsFor((int) $r['id']) as $s) {
        if (($s['time'] ?? null) === null) { continue; }
        $label  = $s['name'] . ' @ ' . substr((string) $s['time'], 0, 5);
        $effDep = date('H:i:s', strtotime('2000-01-01 ' . $r['dep_time']) + 8 * 3600);
        $want   = $fmt(Boarding::pickupTs('2026-10-08', $label, null, (string) $r['dep_time']) + 8 * 3600);
        $got    = $fmt(Boarding::pickupTs('2026-10-08', $label, null, $effDep, (string) $r['dep_time'], 2, (int) $r['id']));
        check("a +8h extra bus moves $label by 8 hours", $got === $want, "$got (want $want)");
        $did = true;
        break;
    }
}
if (!$did) {
    check('a route with a timed stop exists for the Quick Ticket case', false);
}

echo "-- Callers pass the timetable clock --\n";
$tn = (string) file_get_contents(INCLUDE_PATH . '/tripnotify.php');
check('reminder + passenger queries select the route timetable, slot and route',
    substr_count($tn, 'r.dep_time AS route_dep_time, s.slot, s.route_id') === 2);
check('reminders never ask a departed bus\'s passengers to reach a stop a day later',
    str_contains($tn, "=== 'departed' && \$depTs !== false && \$pickTs > \$depTs + 20 * 3600"));
$tk = (string) file_get_contents(INCLUDE_PATH . '/ticket.php');
check('the ticket live pill passes the timetable clock', str_contains($tk, "\$pick('route_dep_time'), (int) (\$pick('schedule_slot') ?: 1)"));
check('the booking legs carry the schedule route id',
    str_contains((string) file_get_contents(INCLUDE_PATH . '/booking.php'), 's.route_id AS schedule_route_id'));

echo "\n----------------------------------------\n";
echo "$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
