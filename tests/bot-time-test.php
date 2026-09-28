<?php
/**
 * =====================================================================
 *  bot-time-test.php — TicketBot::parse() reads WHEN (28 Sep 2026).
 *
 *  Two things went wrong before this pass existed, and the second was
 *  already on live tickets:
 *
 *    1. Nothing read the time. A passenger saying "bihana ko bus" and
 *       one saying "beluka ko bus" planned identically, because the
 *       departure came only from the schedule.
 *
 *    2. The words fell through every pass and landed in the RESIDUAL,
 *       which is the passenger's NAME. Verified against the parser
 *       before it was touched:
 *
 *         "beluka 7 baje ko bus 2 seat nepal"  -> name "Beluka Baje"
 *         "raati 9 baje surat"                 -> name "Raati Baje Surat"
 *         "शाम 7 बजे नेपाल 2 सीट"               -> name "शाम बजे"
 *         "7pm nepal 2 seat"                   -> name "7Pm"
 *
 *       Same shape as the misreads bot-parse-test.php already guards:
 *       "Ram Kal" from a leftover date word, "Chahiye" from a request
 *       word. A wrong name is not cosmetic — it is what prints on the
 *       ticket and what the border manifest is checked against.
 *
 *  The deliberate refusal: an hour with NO part of the day and no am/pm
 *  is ambiguous (7 baje is 07:00 or 19:00), so `time` stays empty and
 *  `timeHour` carries the literal hour for the desk to ask about.
 *  Guessing would put someone on a bus twelve hours from the one they
 *  meant.
 *
 *      php -c .claude/php-dev.ini tests/bot-time-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/ticketbot.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** @return array<string, mixed> */
function p(string $text): array { return TicketBot::parse($text); }

echo "\n=== TicketBot::parse — the time of day ===\n\n";

echo "-- a part of the day resolves the hour --\n";
foreach ([
    ['beluka 7 baje 2 seat nepal',  '19:00', 'evening'],
    ['bihana 6 baje 2 seat nepal',  '06:00', 'morning'],
    ['diuso 2 baje 2 seat nepal',   '14:00', 'afternoon'],
    ['raati 9 baje 2 seat nepal',   '21:00', 'night'],
    ['sanjha 5 baje 2 seat nepal',  '17:00', 'evening'],
] as [$text, $time, $period]) {
    $r = p($text);
    check("\"{$text}\" -> {$time}", $r['time'] === $time, $r['time'] ?: '(none)');
    check("  …part of the day is {$period}", $r['timeOfDay'] === $period, $r['timeOfDay'] ?: '(none)');
}

echo "\n-- am / pm and a 24-hour clock need no help --\n";
foreach ([
    ['7pm nepal 2 seat',        '19:00'],
    ['7 pm nepal 2 seat',       '19:00'],
    ['7am nepal 2 seat',        '07:00'],
    ['12pm nepal 2 seat',       '12:00'],   // noon, not midnight
    ['12am nepal 2 seat',       '00:00'],   // midnight, not noon
    ['19:30 ko bus nepal',      '19:30'],
    ['7:30 pm nepal 2 seat',    '19:30'],
    ['06:15 ko bus nepal',      '06:15'],
] as [$text, $time]) {
    check("\"{$text}\" -> {$time}", p($text)['time'] === $time, p($text)['time'] ?: '(none)');
}

echo "\n-- Hindi, Nepali and Gujarati say it too --\n";
foreach ([
    ['शाम 7 बजे नेपाल 2 सीट',      '19:00', 'evening'],
    ['सुबह 6 बजे नेपाल 2 सीट',     '06:00', 'morning'],
    ['रात 9 बजे नेपाल 2 सीट',      '21:00', 'night'],
    ['बेलुका 7 बजे नेपाल 2 सिट',    '19:00', 'evening'],
    ['સાંજે 7 વાગ્યે 2 સીટ',        '19:00', 'evening'],
    ['સવારે 6 વાગ્યે 2 સીટ',        '06:00', 'morning'],
] as [$text, $time, $period]) {
    $r = p($text);
    check("\"{$text}\" -> {$time}", $r['time'] === $time, $r['time'] ?: '(none)');
    check('  …and the part of the day', $r['timeOfDay'] === $period, $r['timeOfDay'] ?: '(none)');
}

echo "\n-- an ambiguous hour is REFUSED, not guessed --\n";
$amb = p('7 baje nepal 2 seat');
check('"7 baje" alone sets no time', $amb['time'] === '', $amb['time']);
check('  …but the literal hour is kept for the desk to ask', (int) $amb['timeHour'] === 7, (string) $amb['timeHour']);
check('  …and the seat count is untouched', (int) $amb['seats'] === 2, (string) $amb['seats']);

echo "\n-- THE BUG: a time word is never a passenger's name --\n";
foreach ([
    'beluka 7 baje ko bus 2 seat nepal',
    'bihana 6 baje nepal 2 seat',
    'raati 9 baje surat 2 seat',
    '7pm nepal 2 seat',
    '19:30 ko bus nepal 2 seat',
    'शाम 7 बजे नेपाल 2 सीट',
    'સાંજે 7 વાગ્યે 2 સીટ',
    '7 baje nepal 2 seat',
] as $text) {
    $name = mb_strtolower((string) p($text)['name']);
    $dirty = '';
    foreach (['beluka', 'bihana', 'raati', 'baje', 'pm', 'शाम', 'बजे', 'सुबह', 'रात', 'સાંજે', 'વાગ્યે', '19', '7'] as $w) {
        if ($w !== '' && str_contains($name, mb_strtolower($w))) { $dirty = $w; break; }
    }
    check("\"{$text}\" leaves no time word in the name", $dirty === '', $dirty !== '' ? 'name="' . p($text)['name'] . '"' : '');
}

echo "\n-- the passes either side are untouched --\n";
$keep = p('Ram Bahadur 9876543210 2 seats nepal');
check('a plain line still reads its name', $keep['name'] === 'Ram Bahadur', $keep['name']);
check('  …its phone', $keep['phone'] === '9876543210', $keep['phone']);
check('  …its seats', (int) $keep['seats'] === 2, (string) $keep['seats']);
check('a bare hour is never a date', p('7 baje nepal 2 seat')['date'] === '', p('7 baje nepal 2 seat')['date']);
check('"25 tarikh" is still a date, not a time', p('2 seat nepal 25 tarikh')['date'] !== '');
check('  …and sets no time', p('2 seat nepal 25 tarikh')['time'] === '');
check('"seat 12" is still a POSITION, not 12 seats', (int) p('Ram 9876543210 seat 12 kal')['seats'] === 0);
check('"2 seats" is still a count', (int) p('Ram 9876543210 2 seats')['seats'] === 2);
check('an empty message still carries lang en', p('')['lang'] === 'en');
check('  …and no time', p('')['time'] === '' && (int) p('')['timeHour'] === -1);

echo "\n" . ($FAIL === 0
        ? "\033[32mALL {$PASS} CHECKS PASSED\033[0m\n\n"
        : "\033[31m{$FAIL} FAILED\033[0m, {$PASS} passed\n\n");
exit($FAIL === 0 ? 0 : 1);
