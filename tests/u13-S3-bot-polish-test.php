<?php
/**
 * =====================================================================
 *  u13-S3-bot-polish-test.php — WhatsApp bot polish after mv6 (17 Nov 2026).
 *
 *  (a) Devanagari need words (चाहियो / चाहिए / चाहिये) open a booking only
 *      beside a seat count or a seat word — through the REAL door,
 *      WaBooking::handle(), for a fresh stranger's number: "मुझे मदद चाहिए"
 *      gets null (the assistant answers it), "मलाई २ सिट चाहियो" is taken.
 *  (b) the four low findings of the mv6 review, end to end through
 *      TicketBot::parse(): two-word given name, the right occurrence of a
 *      name-like part-of-day word, a plural seat count before am / pm, and
 *      a dotted clock with minutes 01-12.
 *  (c) the S3 refix (review of S3): a Title-Case line never puts a town or a
 *      part of the day in the name, "N seats 7 pm" keeps its time, and a
 *      counted need for a THING ("1 jana ko khana chahiyo") opens nothing —
 *      the first and last through the real door, WaBooking::handle().
 *
 *  Writes: only kv_store rows (scope wa_book and friends) keyed by the two
 *  test numbers below, removed at the end. Settings are changed in this
 *  process's cache only (wa_instant_sale = off, so nothing is ever sold).
 *
 *      php -c .claude/php-dev.ini tests/u13-S3-bot-polish-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/ticketbot.php';
require_once INCLUDE_PATH . '/wabooking.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function settingInProcess(string $key, string $value, string $type = 'string'): void {
    Settings::get($key);
    $cache = new ReflectionProperty(Settings::class, 'cache');
    $c = (array) $cache->getValue();
    $c[$key] = ['value' => $value, 'type' => $type, 'public' => false];
    $cache->setValue(null, $c);
}

$WHO = ['9779800013031', '9779800013032', '9779800013033']; // strangers: no account, no booking
$cleanup = static function () use ($WHO): void {
    foreach ($WHO as $w) {
        try { WaBooking::clear($w); } catch (Throwable) {}
        try { Database::delete('kv_store', 'kkey = :k', ['k' => $w]); } catch (Throwable) {}
    }
};

echo "\n=== u13 S3 — WhatsApp bot polish ===\n\n-- (a) a Devanagari need word alone opens nothing --\n";
try {
    $cleanup();
    settingInProcess('wa_booking_on', '1', 'bool');
    settingInProcess('wa_instant_sale', 'off');
    foreach (['मुझे मदद चाहिए', 'मलाई सहयोग चाहियो', 'मलाई जागिर चाहियो', 'मुझे जानकारी चाहिये'] as $t) {
        $r = WaBooking::handle($WHO[0], $t);
        check("handle(\"{$t}\") is left to the assistant", $r === null && !WaBooking::isOpen($WHO[0]),
            $r === null ? '' : mb_substr((string) json_encode($r, JSON_UNESCAPED_UNICODE), 0, 120));
        WaBooking::clear($WHO[0]);
    }
    echo "-- …with a seat count it is a booking --\n";
    foreach (['मलाई २ सिट चाहियो', 'नेपाल जाने २ जना चाहियो', '2 सीट चाहिए'] as $t) {
        $r = WaBooking::handle($WHO[1], $t);
        $body = (string) json_encode($r, JSON_UNESCAPED_UNICODE);
        check("handle(\"{$t}\") is taken by the booking engine", $r !== null, mb_substr($body, 0, 100));
        check('  …and "चाहियो / चाहिए" is never the name', !preg_match('/नाम[^\n]{0,6}(चाहियो|चाहिए)/u', $body));
        WaBooking::clear($WHO[1]);
    }
} catch (Throwable $e) {
    check('part (a) ran to the end', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $cleanup();
    Settings::flush();
}

echo "\n-- (b) the four mv6 review findings --\n";
$cases = [
    // [text, name, seats, time, timeHour]
    ['Radhe Shyam 7 baje 2 seat nepal',                          'Radhe Shyam', 2, '',      7],
    ['Ghan Shyam 9812345678 shaam 7 baje 1 seat',                'Ghan Shyam',  1, '19:00', 7],
    ['Shyam 9812345678 7 baje Thapa bholi shyam 8 baje 2 seat', 'Shyam Thapa', 2, '19:00', 7],
    ['Ram seats 3 pm surat',                                     'Ram',         3, '',     -1],
    ['Ram 9812345678 tickets 2 pm',                              'Ram',         2, '',     -1],
    ['Ram 9812345678 7.05 pm 2 seat',                            'Ram',         2, '19:05', 7],
    ['Ram 9812345678 9.10 baje 2 seat',                          'Ram',         2, '09:10', 9],
    ['beluka 7 baje ko bus 2 seat nepal',                        '',            2, '19:00', 7],
];
foreach ($cases as [$text, $name, $seats, $time, $hour]) {
    $p = TicketBot::parse($text);
    check("\"{$text}\"", $p['name'] === $name && (int) $p['seats'] === $seats && $p['time'] === $time && (int) $p['timeHour'] === $hour,
        'name="' . $p['name'] . '" seats=' . $p['seats'] . ' time=' . ($p['time'] ?: '-') . ' hour=' . $p['timeHour']);
}
$d = TicketBot::parse('Ram 9812345678 7.05 pm 2 seat');
check('"7.05 pm" sets no date (it is not 7 May)', $d['date'] === '', (string) $d['date']);

echo "\n-- (c) S3 refix --\n";
$c3 = [
    // [text, name, seats, time, timeHour]
    ['Mehsana Shaam 7 Baje 1 Seat',          '',            1, '19:00', 7],
    ['Rupaidiha Shaam 7 Baje 1 Seat',        '',            1, '19:00', 7],
    ['Kalol Raat 10 Baje 1 Seat',            'Kalol',       1, '22:00', 10],
    ['Ram Thapa Raat 10 Baje 1 Seat Nepal',  'Ram Thapa',   1, '22:00', 10],
    ['Sita Subah 7 Baje 1 Seat Bholi',       'Sita',        1, '07:00', 7],
    ['Ram Bahadur Shaam 7 baje 2 seat',      'Ram Bahadur', 2, '19:00', 7],
    ['Radhe Shyam 7 Baje 2 Seat',            'Radhe Shyam', 2, '',      7],
    ['Ram 2 seats 3 pm surat',               'Ram',         2, '15:00', 3],
    ['2 seats: 7 pm',                        '',            2, '19:00', 7],
    ['Ram 9812345678 2 tickets 7 baje',      'Ram',         2, '',      7],
];
foreach ($c3 as [$text, $name, $seats, $time, $hour]) {
    $p = TicketBot::parse($text);
    check("\"{$text}\"", $p['name'] === $name && (int) $p['seats'] === $seats && $p['time'] === $time && (int) $p['timeHour'] === $hour,
        'name="' . $p['name'] . '" seats=' . $p['seats'] . ' time=' . ($p['time'] ?: '-') . ' hour=' . $p['timeHour']);
}
echo "-- …through the real door --\n";
try {
    $cleanup();
    settingInProcess('wa_booking_on', '1', 'bool');
    settingInProcess('wa_instant_sale', 'off');
    foreach (['Mehsana Shaam 7 Baje 1 Seat' => 'Shaam', 'Ram Thapa Raat 10 Baje 1 Seat Nepal' => 'Raat', 'Sita Subah 7 Baje 1 Seat Bholi' => 'Subah'] as $t => $bad) {
        $r = WaBooking::handle($WHO[2], $t);
        $body = (string) json_encode($r, JSON_UNESCAPED_UNICODE);
        check("handle(\"{$t}\") is a booking", $r !== null, mb_substr($body, 0, 100));
        check("  …and \"{$bad}\" is never in the name", !preg_match('/(Name|नाम)[^\n]{0,40}' . $bad . '/u', $body), mb_substr($body, 0, 160));
        WaBooking::clear($WHO[2]);
    }
    foreach (['मलाई १ जना को खाना चाहियो', 'malai 1 jana ko khana chahiyo'] as $t) {
        $r = WaBooking::handle($WHO[2], $t);
        check("handle(\"{$t}\") is left to the assistant", $r === null && !WaBooking::isOpen($WHO[2]),
            $r === null ? '' : mb_substr((string) json_encode($r, JSON_UNESCAPED_UNICODE), 0, 120));
        WaBooking::clear($WHO[2]);
    }
    $r = WaBooking::handle($WHO[2], 'Ram 2 jana chahiyo');
    check('handle("Ram 2 jana chahiyo") is still taken by the booking engine', $r !== null, mb_substr((string) json_encode($r, JSON_UNESCAPED_UNICODE), 0, 100));
    WaBooking::clear($WHO[2]);
} catch (Throwable $e) {
    check('part (c) ran to the end', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $cleanup();
    Settings::flush();
}

echo "\n-- nothing was left behind --\n";
$left = 0;
foreach ($WHO as $w) {
    $left += (int) Database::scalar('SELECT COUNT(*) FROM kv_store WHERE kkey = :k', ['k' => $w]);
}
check('no kv_store row for the test numbers', $left === 0, (string) $left);
$bookingsAfter = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE contact_phone LIKE '%9800013031' OR contact_phone LIKE '%9800013032' OR contact_phone LIKE '%9800013033'");
check('no booking for the test numbers', $bookingsAfter === 0, (string) $bookingsAfter);

echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
