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
 *  16 Nov 2026 (T3): ported to the CURRENT design. The Sep 2026 suite was
 *  written against commit 14c15a7f (branch claude/quirky-ramanujan-emtd6f),
 *  which never reached this lineage: TicketBot::fuzzyStop($text, $catalogue)
 *  returning [] and WaBooking::isGreeting(). It crashed on the first call
 *  ("Trying to access array offset on null"). What this lineage has:
 *
 *   FUZZY     TicketBot::fuzzyStop(string $word): ?array (P7-7d, 16 Nov) —
 *             one word against the live stop catalogue; null = no guess;
 *             distance 0 = already an alias; near TWO stops = null.
 *             The live half runs against TicketBot::stopCatalogue(); the
 *             fixed-catalogue half (ties, which the live catalogue does not
 *             contain) runs the REAL method body, read from
 *             includes/ticketbot.php at run time, against a catalogue
 *             given here — so the suite does not depend on which routes
 *             are active today, and it never copies the algorithm.
 *   GREETING  WaFaq::isGreeting() (a bare hello only) answered by
 *             WaFaq::greeting() with the one-message booking line (15 Oct
 *             2026, 2A-hi; wa_booking_on + wa_book_combo_on). The booking
 *             engine (WaBooking::looksLikeRequest) runs BEFORE WaFaq in
 *             includes/wabot.php, so a greeting carrying a request is sold.
 *
 *  Writes nothing: switches are set in this process's settings cache only,
 *  and WaFaq::suggest() is the side-effect-free door (no memory, no log).
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
require_once INCLUDE_PATH . '/wafaq.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** @param array<string, mixed>|null $r */
function show(?array $r): string {
    return $r === null ? '(none)' : ($r['code'] . ' ' . $r['value'] . ' d=' . $r['distance']);
}

/** A setting for THIS process only (the shared settings row is never written). */
function settingInProcess(string $key, string $value, string $type = 'bool'): void {
    Settings::get($key);                                   // load the cache once
    $cache = new ReflectionProperty(Settings::class, 'cache');
    $c = (array) $cache->getValue();
    $c[$key] = ['value' => $value, 'type' => $type, 'public' => false];
    $cache->setValue(null, $c);
}

/**
 * TicketBot::fuzzyStop() against a GIVEN catalogue: the real method body,
 * read from the source at run time, with its one catalogue call pointed at
 * $catalogue. Throws if the body grows another self:: dependency, so a
 * change there fails loudly instead of testing something else.
 *
 * @param list<array{value: string, name: string, code: string, aliases: list<string>}> $catalogue
 * @return array<string, mixed>|null
 */
function fuzzyWith(array $catalogue, string $word): ?array {
    if (!class_exists('FuzzyProbe', false)) {
        $m     = new ReflectionMethod(TicketBot::class, 'fuzzyStop');
        $lines = (array) file((string) $m->getFileName());
        $body  = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
        $body  = str_replace('self::stopCatalogue()', 'FuzzyProbe::$cat', $body, $n);
        if ($n !== 1 || str_contains($body, 'self::') || !str_contains($body, 'function fuzzyStop(string $word): ?array')) {
            throw new RuntimeException('TicketBot::fuzzyStop() changed shape (catalogue calls: ' . $n . ') — update fuzzyWith()');
        }
        eval('final class FuzzyProbe { /** @var list<array<string, mixed>> */ public static array $cat = []; ' . $body . ' }');
    }
    FuzzyProbe::$cat = $catalogue;
    return FuzzyProbe::fuzzyStop($word);
}

echo "\n=== The WhatsApp seller — greeting and near-miss pickups ===\n\n";

try {
    /* =================================================================
     *  FUZZY (live catalogue) — a pickup one letter out
     * ================================================================= */
    echo "-- a slipped letter still finds the town (live stop catalogue) --\n";
    $codes = array_values(array_unique(array_map(static fn(array $s): string => (string) $s['code'], TicketBot::stopCatalogue())));
    $need  = ['RPD', 'MSN', 'BRC', 'STV', 'NAD', 'AKV'];
    check('the catalogue carries the core pickups (' . implode(' ', $need) . ')',
        array_diff($need, $codes) === [], 'missing: ' . implode(',', array_diff($need, $codes)));

    foreach ([
        ['rupaydiha',  'RPD', 1],
        ['mehsna',     'MSN', 1],
        ['vadodera',   'BRC', 1],
        ['suraat',     'STV', 1],
        ['nadiat',     'NAD', 1],
        ['ankelshwar', 'AKV', 2],   // 2 edits, allowed from 9 letters up
    ] as [$word, $code, $dist]) {
        $r = TicketBot::fuzzyStop($word);
        check("\"{$word}\" -> {$code} (distance {$dist})", $r !== null && $r['code'] === $code && $r['distance'] === $dist, show($r));
    }
    $r = TicketBot::fuzzyStop('vadodra');
    check('"vadodra" is now a plain alias of Vadodara (distance 0)', $r !== null && $r['code'] === 'BRC' && $r['distance'] === 0, show($r));

    echo "\n-- and REFUSES when it cannot be sure --\n";
    $r = TicketBot::fuzzyStop('surat');
    check('an exact spelling is distance 0 — never reported as a correction', $r !== null && $r['code'] === 'STV' && $r['distance'] === 0, show($r));
    check('a word that is nothing like a town is refused', TicketBot::fuzzyStop('xyzzy') === null);
    check('two slips in a short word are refused (nadiyat: 2 edits at 7 letters)', TicketBot::fuzzyStop('nadiyat') === null, show(TicketBot::fuzzyStop('nadiyat')));
    check('a 3-letter word is never fuzzed', TicketBot::fuzzyStop('anu') === null && TicketBot::fuzzyStop('ram') === null);
    check('a 4-letter word near nothing stays nothing (brod)', TicketBot::fuzzyStop('brod') === null, show(TicketBot::fuzzyStop('brod')));
    check('an empty word is safe', TicketBot::fuzzyStop('') === null);
    check('Devanagari is never byte-compared', TicketBot::fuzzyStop('सुरत') === null && TicketBot::fuzzyStop('२ सिट सुरत') === null);

    /* The fuzzy pass is asked ONLY for a word in a place position. */
    $p = TicketBot::parse('Ram 9876543210 suraat se');
    check('"suraat se" (a place position) boards at Surat', $p['name'] === 'Ram' && str_starts_with((string) $p['boarding'], 'Surat'), 'name=' . $p['name'] . ' boarding=' . $p['boarding']);
    $p = TicketBot::parse('Suraat Kumar 9876543210 kal');
    check('  …but "Suraat Kumar" (a name position) keeps the name and sets no pickup', $p['name'] === 'Suraat Kumar' && $p['boarding'] === '', 'name=' . $p['name'] . ' boarding=' . $p['boarding']);

    /* =================================================================
     *  FUZZY (fixed catalogue) — the tie rules, on the real method body
     * ================================================================= */
    echo "\n-- the tie rules (fixed catalogue, the real fuzzyStop body) --\n";
    $faithful = true;
    foreach (['rupaydiha', 'mehsna', 'suraat', 'surat', 'xyzzy', 'ankelshwar', 'nadiyat', 'order', 'naroda'] as $w) {
        if (fuzzyWith(TicketBot::stopCatalogue(), $w) !== TicketBot::fuzzyStop($w)) { $faithful = false; }
    }
    check('the probe answers exactly like TicketBot::fuzzyStop() on the live catalogue', $faithful);

    $stop = static fn(string $code, string $value, array $aliases): array => ['value' => $value, 'name' => $value, 'code' => $code, 'aliases' => $aliases];
    /* "amandx" is exactly one edit from BOTH — m->n for one, x->y for the
       other. Neither is closer, so the only honest answer is none. */
    $tie = [$stop('AMN', 'Amodnagar', ['amandy']), $stop('ANN', 'Anandnagar', ['anandx'])];
    check('two towns equally close -> no guess at all', fuzzyWith($tie, 'amandx') === null, show(fuzzyWith($tie, 'amandx')));
    $r = fuzzyWith([$stop('AMN', 'Amodnagar', ['amodxx']), $stop('ANN', 'Anandnagar', ['anandy'])], 'anandx');
    check('  …but when only ONE town is within reach it still wins', $r !== null && $r['code'] === 'ANN', show($r));
    /* 9+ letters allow two edits, and a second town inside those two blocks the guess even if the first is closer. */
    $r = fuzzyWith([$stop('AAA', 'Townone', ['palanpurx']), $stop('BBB', 'Towntwo', ['palanpuyy'])], 'palanpurz');
    check('  …a second town within reach blocks the guess even when one is closer (9+ letters)', $r === null, show($r));
    $r = fuzzyWith([$stop('AAA', 'Suratx', ['suratx']), $stop('STV', 'Surat', ['surat'])], 'surat');
    check('an exact alias wins even with another town one letter away', $r !== null && $r['code'] === 'STV' && $r['distance'] === 0, show($r));
    $rpd = [$stop('RPD', 'Rupaidiha', ['rupaidiha', 'rupaideha', 'rupaidiya']), $stop('STV', 'Surat', ['surat'])];
    $r = fuzzyWith($rpd, 'rupaydiha');
    check('a tie between aliases of the SAME town is not a tie', $r !== null && $r['code'] === 'RPD', show($r));
    $r = fuzzyWith([$stop('X1', 'Rupaidiha', ['rupaidiha']), $stop('X1', 'Rupaidiha (gate)', ['rupaideha'])], 'rupaydiha');
    check('  …nor between two catalogue rows of one town (same code)', $r !== null && $r['code'] === 'X1', show($r));
    check('an empty catalogue is safe', fuzzyWith([], 'rupaydiha') === null);
    $r = fuzzyWith([$stop('RPD', 'Rupaidiha', ['border']), $stop('AMD', 'Parking', ['parking'])], 'order');
    check('ordinary-word aliases (border, parking) match only exactly', $r === null && fuzzyWith([$stop('AMD', 'Parking', ['parking'])], 'packing') === null, show($r));
    check('a real town that is not our stop is never "corrected" (naroda -> baroda)',
        fuzzyWith([$stop('BRC', 'Baroda', ['baroda'])], 'naroda') === null);
} catch (Throwable $e) {
    check('the fuzzy half ran to the end', false, get_class($e) . ': ' . $e->getMessage());
}

/* =====================================================================
 *  GREETING — only a bare one
 * ===================================================================== */
echo "\n-- a bare greeting is a greeting --\n";
try {
    $isGreeting = new ReflectionMethod(WaFaq::class, 'isGreeting');
    $norm       = new ReflectionMethod(WaFaq::class, 'norm');
    /* WaFaq::answer() hands isGreeting() the normalised text, space-padded. */
    $greet = static fn(string $s): bool => (bool) $isGreeting->invoke(null, ' ' . $norm->invoke(null, $s) . ' ');

    foreach (['hi', 'Hi', 'HI', 'hii', 'hello', 'Hello!', 'hey', 'namaste', 'Namaste 🙏',
              'नमस्ते', 'नमस्कार', 'હેલો', 'good morning', 'ram ram'] as $g) {
        check("\"{$g}\" is a greeting", $greet($g));
    }

    echo "\n-- a greeting CARRYING a request is not --\n";
    foreach (['hi 2 seat nepal', 'hello, 2 seat surat to nepal kal', 'namaste 2 sit chahiyo',
              'नमस्ते 2 सीट नेपाल', 'hire a bus', 'hi my name is Hari and I want 2 seats'] as $g) {
        check("\"{$g}\" is NOT a bare greeting", !$greet($g));
    }
    check('"hire" is not caught by "hi"', !$greet('hire'));
    check('"hiring" is not caught by "hi"', !$greet('hiring'));
    check('an empty message is not a greeting', !$greet(''));
    check('a long message is never a greeting', !$greet(str_repeat('hi ', 20)));

    /* The greeting branch answers with the line that books a ticket. */
    echo "\n-- the hello teaches the booking line --\n";
    $who = '9779800000917';                               // no account, no booking: a stranger
    settingInProcess('wa_booking_on', '1');
    settingInProcess('wa_book_combo_on', '1');
    $hi = (string) WaFaq::suggest($who, 'hi');
    check('a bare "hi" is answered with the one-message booking line (Nepali first)',
        str_contains($hi, '🎫 टिकट बुक गर्न एउटै सन्देश') && str_contains($hi, 'Ram, Surat, bholi, 2'), mb_substr($hi, 0, 120));
    $gu = (string) WaFaq::suggest($who, 'નમસ્તે');
    check('  …a Gujarati hello gets it in Gujarati', str_contains($gu, 'ટિકિટ બુક કરવા એક જ સંદેશ'), mb_substr($gu, 0, 120));
    settingInProcess('wa_book_combo_on', '0');
    $off = (string) WaFaq::suggest($who, 'hi');
    check('  …and wa_book_combo_on = 0 leaves the line out (the switch is obeyed)', $off !== '' && !str_contains($off, 'टिकट बुक गर्न एउटै सन्देश'), mb_substr($off, 0, 80));
    /* The order menu under every hello still offers the booking ("ticket"). */
    check('  …while the order menu under the hello still offers to book', str_contains($off, 'ticket'), mb_substr($off, -120));
    Settings::flush();

    /* And the fact that makes the branch safe: whatever a greeting carries,
       looksLikeRequest() must still claim it, so no booking fact is lost.
       (includes/wabot.php asks the booking engine BEFORE WaFaq.) */
    echo "\n-- nothing bookable is thrown away --\n";
    $looks = new ReflectionMethod(WaBooking::class, 'looksLikeRequest');
    foreach (['hi 2 seat nepal', 'namaste 2 sit chahiyo', 'नमस्ते 2 सीट नेपाल'] as $g) {
        check("\"{$g}\" is still read as a request", (bool) $looks->invoke(null, $g));
    }
    $wabot = (string) file_get_contents(dirname(__DIR__) . '/includes/wabot.php');
    $pBook = strpos($wabot, '$booking = $tryLocalBooking();');
    $pFaq  = strpos($wabot, '$faq = WaFaq::answer($from, $body);');
    check('wabot asks the booking engine before the FAQ greeting', $pBook !== false && $pFaq !== false && $pBook < $pFaq);
} catch (Throwable $e) {
    check('the greeting half ran to the end', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    Settings::flush();
}

/* =====================================================================
 *  NEED WORDS (B1 refix, 17 Nov 2026) — "chahiyo" alone is no booking
 * ===================================================================== */
echo "\n-- a need word alone opens no sale --\n";
try {
    /* The first port counted the romanised need words (chahiyo, chahiye,
       joie …) as booking words on their own. A request with no facts is
       filled with defaults (next bus, the usual pickup, 1 seat), so "malai job
       chahiyo" became a ready summary for a passenger named "Job" that one
       "ho" would have sold. handle() opens nothing for a fresh number unless
       one of these two says yes, so both are checked. */
    $looks = new ReflectionMethod(WaBooking::class, 'looksLikeRequest');
    $combo = new ReflectionMethod(WaBooking::class, 'comboRead');
    foreach (['malai job chahiyo', 'receipt chahiyo', 'bill chahiye', 'jankari chahiyo', 'bus ko schedule chahiyo',
              'mane bill joie', 'malai paisa chahiyo', 'nahi chahiye', 'malai visit chahiyo'] as $t) {
        check("\"{$t}\" opens no booking", !(bool) $looks->invoke(null, $t) && !(bool) $combo->invoke(null, $t));
    }
    echo "\n-- …beside a seat word or a seat count it still does --\n";
    foreach (['2 sit chahiyo', 'malai 2 sit chaiyo', '2 jana chahiyo', 'mane 2 sit joiye'] as $t) {
        check("\"{$t}\" is a request", (bool) $looks->invoke(null, $t));
    }

    /* S3 (17 Nov 2026): the Devanagari need words were still booking words
       on their own — "मुझे मदद चाहिए" opened a summary for "मदद". */
    echo "\n-- …and the same for the Devanagari need words (S3) --\n";
    foreach (['मुझे मदद चाहिए', 'मलाई सहयोग चाहियो', 'मलाई जागिर चाहियो', 'मुझे जानकारी चाहिये', 'मलाई बिल चाहियो'] as $t) {
        check("\"{$t}\" opens no booking", !(bool) $looks->invoke(null, $t) && !(bool) $combo->invoke(null, $t));
    }
    foreach (['मलाई २ सिट चाहियो', '2 सीट चाहिए', 'नेपाल जाने २ जना चाहियो', 'मलाई दुई जना चाहियो', 'मुझे दो टिकट चाहिए', 'मलाई टिकट चाहियो'] as $t) {
        check("\"{$t}\" is a request", (bool) $looks->invoke(null, $t));
    }
    $np = TicketBot::parse('मलाई २ सिट चाहियो');
    check('"मलाई २ सिट चाहियो" reads 2 seats and no name', (int) $np['seats'] === 2 && $np['name'] === '', 'seats=' . $np['seats'] . ' name="' . $np['name'] . '"');

    /* S3 refix (17 Nov 2026, review finding 4): a count beside a need word
       that asks for a THING ("food for 1 person") is not a party. */
    echo "\n-- …a counted need for a thing is not a seat (S3 refix) --\n";
    foreach (['मलाई १ जना को खाना चाहियो', 'malai 1 jana ko khana chahiyo', 'malai 2 jana lai pani chahiyo', '1 jana ko bill chahiyo'] as $t) {
        check("\"{$t}\" opens no booking", !(bool) $looks->invoke(null, $t));
    }
    foreach (['Ram 2 jana chahiyo', '2 jana bholi chahiyo', 'Ram Thapa 2 jana ko chahiyo', 'मलाई दुई जना चाहियो'] as $t) {
        check("\"{$t}\" is still a request", (bool) $looks->invoke(null, $t));
    }

    /* "mane 1 sit joie" was a summary for "Mane Joie" (and "Ram 1 sit joie"
       for "Ram Joie"): the Gujarati-script request words were fillers, the
       romanised ones were not. "Mane" is also a surname, so only a leading
       "mane" is dropped. */
    echo "\n-- a romanised Gujarati request word is never the name --\n";
    foreach ([
        ['mane 1 sit joie', ''], ['mane 2 sit joie', ''], ['mane 2 sit joiye', ''], ['mane 1 seat joie', ''],
        ['mane 1 sit joie surat thi', ''], ['Ram 1 sit joie', 'Ram'],
        ['Sunil Mane 9812345678 2 seat', 'Sunil Mane'], ['naam Mane 9812345678 2 seat', 'Mane'],
    ] as [$t, $name]) {
        $got = (string) TicketBot::parse($t)['name'];
        check("\"{$t}\" -> name \"{$name}\"", $got === $name, 'name="' . $got . '"');
    }
} catch (Throwable $e) {
    check('the need-word half ran to the end', false, get_class($e) . ': ' . $e->getMessage());
}

echo "\n" . ($FAIL === 0
        ? "\033[32mALL {$PASS} CHECKS PASSED\033[0m\n\n"
        : "\033[31m{$FAIL} FAILED\033[0m, {$PASS} passed\n\n");
exit($FAIL === 0 ? 0 : 1);
