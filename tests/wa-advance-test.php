<?php
/**
 * =====================================================================
 *  wa-advance-test.php — the two WhatsApp seller gaps (28 Sep 2026).
 *
 *  bot-time-test.php covers the third (reading the time). This covers:
 *
 *   GREETING   a bare "hi" is answered with the line that books a
 *              ticket, and ONLY a bare one — a greeting carrying a real
 *              request must still be read as the request, or the facts
 *              in it are thrown away.
 *
 *   FUZZY      a pickup one letter out is recognised, and — the half
 *              that matters — a pickup that could be two different
 *              towns is REFUSED. Selling a seat from the wrong town
 *              costs a passenger their bus, so a confident wrong answer
 *              is worse here than no answer.
 *
 *  TicketBot::fuzzyStop() is pure (no database, no settings), so its
 *  half of this suite runs anywhere.
 *
 *      php -c .claude/php-dev.ini tests/wa-advance-test.php
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
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== The WhatsApp seller — greeting and near-miss pickups ===\n\n";

/* =====================================================================
 *  FUZZY — a pickup one letter out
 * ===================================================================== */
echo "-- a slipped letter still finds the town --\n";

/** A fixed catalogue, so the suite does not depend on which routes are active today. */
$cat = [
    ['value' => 'Rupaidiha', 'aliases' => ['rupaidiha', 'rupaideha', 'rupediha', 'border']],
    ['value' => 'Mehsana',   'aliases' => ['mehsana', 'mahesana']],
    ['value' => 'Surat',     'aliases' => ['surat', 'surat station']],
    ['value' => 'Vadodara',  'aliases' => ['baroda', 'vadodara', 'badoda']],
    ['value' => 'Nadiad',    'aliases' => ['nadiad']],
    ['value' => 'Anand',     'aliases' => ['anand']],
    ['value' => 'Ankleshwar','aliases' => ['ankleshwar', 'anklesvar']],
];

foreach ([
    ['2 seat rupaydiha bata', 'Rupaidiha'],
    ['mehsna bata 2 seat',    'Mehsana'],
    ['vadodra 2 seat',        'Vadodara'],
    ['suraat 2 seat',         'Surat'],
    ['nadiat 2 seat',         'Nadiad'],
    ['ankleshvar 2 seat',     'Ankleshwar'],   // 2 edits, allowed at 10 letters
] as [$text, $want]) {
    $r = TicketBot::fuzzyStop($text, $cat);
    $got = $r === [] ? '(none)' : $r['value'];
    check("\"{$text}\" -> {$want}", $got === $want, $got . ($r !== [] ? ' d=' . $r['dist'] : ''));
}

echo "\n-- and REFUSES when it cannot be sure --\n";
check('an exact spelling is left to the exact pass', TicketBot::fuzzyStop('2 seat surat', $cat) === []);
check('a word that is nothing like a town is refused', TicketBot::fuzzyStop('2 seat xyzzy', $cat) === []);
check('a short token is refused (anad: anand and amod are both real)',
    TicketBot::fuzzyStop('2 seat anad', $cat) === []);
check('a 4-letter token is never fuzzed', TicketBot::fuzzyStop('2 seat brod', $cat) === []);
/* The rule that matters most: two towns equally close means we do not
   know which was meant, so nothing is claimed. */
/* "amandx" is exactly one edit from BOTH — m->n for one, x->y for the
   other. Neither is closer, so the only honest answer is none. */
$tie = [
    ['value' => 'Amodnagar',  'aliases' => ['amandy']],
    ['value' => 'Anandnagar', 'aliases' => ['anandx']],
];
check('two towns equally close -> no guess at all',
    TicketBot::fuzzyStop('2 seat amandx', $tie) === [],
    json_encode(TicketBot::fuzzyStop('2 seat amandx', $tie)));
check('  …but one clearly closer than the other still wins',
    (TicketBot::fuzzyStop('2 seat anandx', [
        ['value' => 'Amodnagar',  'aliases' => ['amodxx']],
        ['value' => 'Anandnagar', 'aliases' => ['anandy']],
    ])['value'] ?? '') === 'Anandnagar');
check('a tie between aliases of the SAME town is not a tie',
    (TicketBot::fuzzyStop('rupaydiha', $cat)['value'] ?? '') === 'Rupaidiha');
check('an empty catalogue is safe', TicketBot::fuzzyStop('rupaydiha', []) === []);
check('an empty message is safe', TicketBot::fuzzyStop('', $cat) === []);
check('Devanagari is never byte-compared', TicketBot::fuzzyStop('२ सिट सुरत', $cat) === []);

/* =====================================================================
 *  GREETING — only a bare one
 * ===================================================================== */
echo "\n-- a bare greeting is a greeting --\n";
$isGreeting = new ReflectionMethod(WaBooking::class, 'isGreeting');
$isGreeting->setAccessible(true);
$greet = static fn(string $s): bool => (bool) $isGreeting->invoke(null, $s);

foreach (['hi', 'Hi', 'HI', 'hii', 'hello', 'Hello!', 'hey', 'namaste', 'Namaste 🙏',
          'नमस्ते', 'नमस्कार', 'હેલો', 'good morning', 'ram ram'] as $g) {
    check("\"{$g}\" is a greeting", $greet($g));
}

echo "\n-- a greeting CARRYING a request is not --\n";
foreach (['hi 2 seat nepal', 'hello, 2 seat surat to nepal kal', 'namaste 2 sit chahiyo',
          'नमस्ते 2 सीट नेपाल', 'hire a bus', 'hi my name is Hari and I want 2 seats'] as $g) {
    check("\"{$g}\" is NOT a bare greeting", !$greet($g), '');
}
check('"hire" is not caught by "hi"', !$greet('hire'));
check('"hiring" is not caught by "hi"', !$greet('hiring'));
check('an empty message is not a greeting', !$greet(''));
check('a long message is never a greeting', !$greet(str_repeat('hi ', 20)));

/* And the fact that makes the branch safe: whatever a greeting carries,
   looksLikeRequest() must still claim it, so no booking fact is lost. */
echo "\n-- nothing bookable is thrown away --\n";
$looks = new ReflectionMethod(WaBooking::class, 'looksLikeRequest');
$looks->setAccessible(true);
foreach (['hi 2 seat nepal', 'namaste 2 sit chahiyo', 'नमस्ते 2 सीट नेपाल'] as $g) {
    check("\"{$g}\" is still read as a request", (bool) $looks->invoke(null, $g));
}

echo "\n" . ($FAIL === 0
        ? "\033[32mALL {$PASS} CHECKS PASSED\033[0m\n\n"
        : "\033[31m{$FAIL} FAILED\033[0m, {$PASS} passed\n\n");
exit($FAIL === 0 ? 0 : 1);
