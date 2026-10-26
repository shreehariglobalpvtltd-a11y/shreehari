<?php
/**
 * bot-ladder-test.php — SHG AI BRAIN Phase 2 (10 Sep 2026).
 *
 * Locks in:
 *   • detectLang(): script first (Gujarati, Devanagari), then tell-tale
 *     romanised words; Nepali wins a Devanagari tie
 *   • question(): the ONE clarifying question per fact, in en / hi / ne / gu,
 *     with option values the API accepts verbatim
 *   • suggest(): the confidence ladder — a bare name + mobile is never
 *     questioned (the 10-second flow stays one tap); a line that settles
 *     nothing asks for the date first, in the writer's language; explicit
 *     chips retire the question; no line typed = no question
 *   • plan(): `prefer` takes a free usual berth first, skips a sold one,
 *     never hands out more than asked
 *   • sameAsLast: the desk and the number's own session see the usual
 *     berths; an anonymous customer typing that number sees nothing
 *
 *   php -c .claude/php-dev.ini tests/bot-ladder-test.php
 *
 * Reads the test database; writes nothing beyond the schedule row plan()
 * may materialise (exactly what a search does). CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/ticketbot.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n== detectLang ==\n";
foreach ([['नेपाल जाने हो', 'ne'], ['मुझे 2 टिकट चाहिए', 'hi'], ['મને 2 ટિકિટ જોઈએ', 'gu'], ['2 seats tomorrow', 'en'],
          ['chahiyo bholi', 'ne'], ['chahiye kal', 'hi'], ['joie chhe', 'gu'], ['राम बहादुर', 'ne'], ['Ram 9876543210', 'en']] as [$txt, $want]) {
    $got = TicketBot::detectLang($txt);
    check("$txt → $want", $got === $want, $got);
}

echo "\n== question() ==\n";
$q = TicketBot::question('date', 'ne');
check('date question in Nepali', $q !== null && $q['question'] === 'कुन मिति जान चाहनुहुन्छ?', (string) ($q['question'] ?? ''));
check('date options: today / tomorrow / day after / other', $q !== null && count($q['options']) === 4 && $q['options'][0]['value'] === todayISO() && $q['options'][3]['value'] === 'other');
$q = TicketBot::question('boarding', 'hi', null);
check('boarding question falls back to the stop catalogue', $q !== null && count($q['options']) >= 1 && str_contains($q['question'], 'बस'), (string) count($q['options'] ?? []));
$q = TicketBot::question('direction', 'gu');
check('direction options are the API values', $q !== null && $q['options'][0]['value'] === 'toNepal' && $q['options'][1]['value'] === 'toIndia');
check('unknown field → null', TicketBot::question('colour', 'en') === null);
check('unknown language → English', (TicketBot::question('seats', 'xx')['lang'] ?? '') === 'en');

echo "\n== suggest(): the ladder ==\n";
$s = TicketBot::suggest(['text' => 'Ram 9876543210'], null);
check('name + mobile only: NO question (the 10-second flow stays one tap)', $s['ask'] === null, json_encode($s['ask']));
check('  ladder is proceed / highlight, never ask', in_array($s['ladder'], ['proceed', 'highlight'], true), $s['ladder'] . ' @ ' . $s['confidence']);
check('  missing lists the auto-filled facts', in_array('date', $s['missing'], true), implode(',', $s['missing']));
check('  lang en', $s['lang'] === 'en', $s['lang']);
$s = TicketBot::suggest(['text' => 'namaste bhai', 'lang' => 'ne'], null);
check('a line that settles nothing asks ONE question', is_array($s['ask']), json_encode($s['ask']));
check('  the highest-priority fact first: the date', ($s['ask']['field'] ?? '') === 'date', (string) ($s['ask']['field'] ?? ''));
check('  in the app language when the script gives no hint', ($s['ask']['lang'] ?? '') === 'ne', (string) ($s['ask']['lang'] ?? ''));
check('  ladder says ask', $s['ladder'] === 'ask', $s['ladder'] . ' @ ' . $s['confidence']);
$s = TicketBot::suggest(['text' => 'नमस्ते भाई'], null);
check('Devanagari line: the question comes in Nepali', ($s['ask']['lang'] ?? '') === 'ne' && str_contains((string) ($s['ask']['question'] ?? ''), 'मिति'), json_encode($s['ask'], JSON_UNESCAPED_UNICODE));
$s = TicketBot::suggest(['text' => 'namaste', 'lang' => 'gu'], null);
check('Gujarati app language: the question comes in Gujarati', ($s['ask']['lang'] ?? '') === 'gu', (string) ($s['ask']['lang'] ?? ''));
$s = TicketBot::suggest(['text' => 'namaste bhai', 'date' => addDaysISO(todayISO(), 1), 'seats' => 2, 'direction' => 'toNepal'], null);
check('explicit chips answer it — the question retires', $s['ask'] === null || ($s['ask']['field'] ?? '') === 'boarding', json_encode($s['ask']));
$s = TicketBot::suggest([], null);
check('no line typed: never a question', $s['ask'] === null);
check('  and the response still carries the ladder keys', array_key_exists('ladder', $s) && array_key_exists('sameAsLast', $s) && array_key_exists('missing', $s));

echo "\n== plan(): prefer — the same berths as last time ==\n";
try {
    $base = QuickTicket::plan(['customer' => true]);
    $sid  = (int) ($base['scheduleId'] ?? 0);
    $bt   = (($base['coach'] ?? $base['coachType'] ?? 'sleeper') === 'seater') ? 'seater' : 'sharing';
    $av   = Seats::availabilityForSchedule($sid, $bt);
    $free = array_values(array_diff(array_map('strval', $av['available'] ?? []), array_map('strval', $av['female'] ?? [])));
    $pick = $free !== [] ? (string) end($free) : '';
    check('a sellable departure exists for the test', $sid > 0 && $pick !== '', "sid $sid pick $pick");
    if ($pick !== '') {
        $p = QuickTicket::plan(['customer' => true, 'prefer' => [$pick]]);
        check('a free preferred berth is taken first', (string) ($p['seats'][0] ?? '') === $pick, implode(',', (array) $p['seats']));
        $p1 = QuickTicket::plan(['customer' => true, 'prefer' => [strtolower($pick)]]);
        check('  …case-insensitively', (string) ($p1['seats'][0] ?? '') === $pick, implode(',', (array) $p1['seats']));
        $booked = array_map('strval', $av['booked'] ?? []);
        if ($booked !== []) {
            $p2 = QuickTicket::plan(['customer' => true, 'prefer' => [$booked[0]]]);
            check('a sold preferred berth is skipped, never oversold', !in_array($booked[0], (array) $p2['seats'], true), $booked[0] . ' vs ' . implode(',', (array) $p2['seats']));
        } else {
            echo "  SKIP  no sold berth on that departure to test the fallback\n";
        }
        $p3 = QuickTicket::plan(['customer' => true, 'prefer' => [$pick], 'seats' => 2]);
        check('prefer never gives more berths than asked', count((array) $p3['seats']) === 2 && in_array($pick, (array) $p3['seats'], true), implode(',', (array) $p3['seats']));
        $p4 = QuickTicket::plan(['customer' => true, 'prefer' => ['ZZ99']]);
        check('an unknown preferred label is ignored', count((array) $p4['seats']) === 1, implode(',', (array) $p4['seats']));
        // a couple keeps together: the preferred berth's free cabin-mate comes next
        $coach = ($bt === 'seater') ? 'seater' : 'sleeper';
        $mates = array_values(array_intersect(Seats::unitSeats(Seats::unitKey($pick, $coach, 'sharing'), $coach), $free));
        if (count($mates) >= 2) {
            $p5 = QuickTicket::plan(['customer' => true, 'prefer' => [$pick], 'seats' => 2]);
            $same = array_unique(array_map(static fn(string $s): string => Seats::unitKey($s, $coach, 'sharing'), (array) $p5['seats']));
            check('a party of two fills the preferred berth\'s own cabin', count((array) $p5['seats']) === 2 && count($same) === 1, implode(',', (array) $p5['seats']));
        } else {
            echo "  SKIP  the preferred berth has no free cabin-mate on that departure\n";
        }
    }
} catch (RuntimeException $e) {
    echo "  SKIP  plan(): " . $e->getMessage() . "\n";
}

echo "\n== suggest(): same as last time ==\n";
if (!TicketBot::enabled()) {
    echo "  SKIP  ai_bot_on is off in this database\n";
} else {
    $phone = (string) Database::scalar(
        "SELECT b.contact_phone FROM bookings b WHERE b.status = 'confirmed' AND b.contact_phone REGEXP '^[6-9][0-9]{9}$'
          GROUP BY b.contact_phone ORDER BY COUNT(*) DESC LIMIT 1", [], '');
    if ($phone === '') {
        echo "  SKIP  no confirmed passenger in the test DB\n";
    } else {
        $s = TicketBot::suggest(['phone' => $phone], ['id' => 1, 'username' => 'test', 'role' => 'superadmin']);
        check('the desk sees the returning passenger\'s usual berths', is_array($s['sameAsLast']) && ($s['sameAsLast']['lastPnr'] ?? '') !== '', json_encode($s['sameAsLast']));
        $s2 = TicketBot::suggest(['phone' => $phone], null);
        check('an anonymous customer typing that number gets NO memory', $s2['sameAsLast'] === null && $s2['profile'] === null);
        $s3 = TicketBot::suggest(['sessionPhone' => $phone], null);
        check('the number\'s own session does', is_array($s3['sameAsLast']));
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
