<?php
/**
 * bot-parse-test.php — TicketBot::parse() reads a real desk line (8 Sep 2026).
 *
 * The parser is order-independent by construction (it extracts a field and
 * REMOVES it, so position never matters), and that half worked. What did not
 * were six specific misreads, each verified against the live parser before it
 * was touched, and each of which puts a WRONG fact on a real ticket:
 *
 *   phone    "Ram 987654321 2 seats" -> 9876543212. A 9-digit typo with the
 *            party size glued on strips to a valid 10 digits, so the ticket PNG
 *            and the WhatsApp went to a stranger. No number is better.
 *   seats    "seat 12" -> a party of 12. A singular noun before a number is a
 *            POSITION, not a count.
 *   gender   "2 seat chahiye bhai" -> Male. Vocatives address the desk, they do
 *            not describe the passenger — and gender decides women-only berths.
 *   name     "kal parso" -> "Ram Kal": only the first date word was stripped.
 *   agent    "SHG-2026-00123" -> agent code SHG-2026, fabricated from a PNR.
 *   script   "राम बहादुर" -> "र म बह द र": matras are Unicode category Mn and
 *            were being treated as word breaks, so that is what would print.
 *
 *   php -c .claude/php-dev.ini tests/bot-parse-test.php
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
/** @return array<string, mixed> */
function p(string $text): array { return TicketBot::parse($text); }

echo "\n=== TicketBot::parse — the six misreads ===\n\n";

echo "-- a wrong mobile is worse than none --\n";
$a = p('Ram 987654321 2 seats');
check('a 9-digit typo is NOT repaired into a valid number', $a['phone'] === '', $a['phone']);
check('  …and the party size survives instead', (int) $a['seats'] === 2, (string) $a['seats']);
$b = p('Ram 98765 43210 2 seats');
check('a genuine spaced number still joins', $b['phone'] === '9876543210', $b['phone']);
check('  …with its seat count intact', (int) $b['seats'] === 2, (string) $b['seats']);
$c = p('Ram +977 9812345678 kal');
check('a +977 number still parses', str_contains($c['phone'], '9812345678'), $c['phone']);

echo "\n-- a seat POSITION is not a party size --\n";
foreach (['seat 12', 'berth 15', 'tkt 12', 'sit 9'] as $frag) {
    $r = p("Ram Kumar 9876543210 {$frag} kal");
    check("\"{$frag}\" is not read as a count", (int) $r['seats'] === 0, 'seats=' . $r['seats']);
}
check('"2 seats" IS a count', (int) p('Ram 9876543210 2 seats')['seats'] === 2);
check('"seats: 3" IS a count', (int) p('Ram 9876543210 seats: 3')['seats'] === 3);
check('"dui jana" IS a count', (int) p('Ram 9876543210 dui jana')['seats'] === 2);

echo "\n-- a vocative is not a gender --\n";
foreach (['bhai', 'dai', 'sir', 'didi', 'aunty', 'uncle'] as $v) {
    $r = p("2 seat chahiye {$v} 9876543210");
    check("\"{$v}\" does not set a gender", $r['gender'] === '', $r['gender']);
}
check('"female" still does', p('Ram 9876543210 female')['gender'] === 'Female');
check('"mrs" still does',    p('Mrs Sita 9876543210')['gender'] === 'Female');
check('"male" still does',   p('Ram 9876543210 male')['gender'] === 'Male');

echo "\n-- a leftover date word is never a name --\n";
check('"kal parso" leaves the name alone', p('Ram 9876543210 kal parso 2 seats')['name'] === 'Ram', p('Ram 9876543210 kal parso 2 seats')['name']);
check('"today tomorrow" too', p('Ram 9876543210 today tomorrow')['name'] === 'Ram', p('Ram 9876543210 today tomorrow')['name']);
check('"tomorrow friday" too', p('Ram 9876543210 tomorrow friday 2 seats')['name'] === 'Ram', p('Ram 9876543210 tomorrow friday 2 seats')['name']);

echo "\n-- a PNR is not an agent code --\n";
$d = p('SHG-2026-00123 ko ticket 9876543210');
check('a PNR does not become an agent code', $d['agentCode'] === '', $d['agentCode']);
check('  …and does not leak into the name', !str_contains(strtolower($d['name']), 'shg'), $d['name']);
check('a REAL agent code still parses', p('Ram 9876543210 shg-27')['agentCode'] === 'SHG-0027', p('Ram 9876543210 shg-27')['agentCode']);
check('"agent 27" still parses', p('Ram 9876543210 agent 27')['agentCode'] === 'SHG-0027');

echo "\n-- Devanagari survives --\n";
$e = p('राम बहादुर 9876543210 2 सिट भोलि');
check('the name keeps its matras', $e['name'] === 'राम बहादुर', $e['name']);
check('  …the seat count is read', (int) $e['seats'] === 2, (string) $e['seats']);
check('  …the date word is read and removed', $e['date'] !== '' && !str_contains($e['name'], 'भोलि'), $e['date']);

echo "\n-- order independence (the half that always worked) --\n";
$want = static fn(array $r): bool => $r['name'] === 'Ram Bahadur' && $r['phone'] === '9876543210' && (int) $r['seats'] === 2;
check('name phone seats', $want(p('Ram Bahadur 9876543210 2 seats')));
check('phone name seats', $want(p('9876543210 Ram Bahadur 2 seats')));
check('seats phone name', $want(p('2 seats 9876543210 Ram Bahadur')));
check('seats name phone', $want(p('2 seats Ram Bahadur 9876543210')));

echo "\n-- SHG AI BRAIN Phase 2 (10 Sep 2026): Hindi / Nepali / Gujarati scripts --\n";
$tm = addDaysISO(todayISO(), 1);
$h = p('राम शर्मा 9876543210 कल 2 सीट मेहसाणा से नेपाल जाना है');
check('Hindi: name stays in Devanagari', $h['name'] === 'राम शर्मा', $h['name']);
check('Hindi: कल = tomorrow', $h['date'] === $tm, $h['date']);
check('Hindi: 2 सीट = 2 seats', (int) $h['seats'] === 2, (string) $h['seats']);
check('Hindi: मेहसाणा = the Mehsana pickup', str_contains(mb_strtolower($h['boarding']), 'mehsana'), $h['boarding']);
check('Hindi: नेपाल जाना = toNepal', $h['direction'] === 'toNepal', $h['direction']);
check('Hindi: language detected', $h['lang'] === 'hi', $h['lang']);
$n = p('सीता देवी ९८१२३४५६७८ भोलि दुई जना सुरत बाट नेपाल जाने महिला');
check('Nepali: Devanagari digits read as the mobile', $n['phone'] === '9812345678', $n['phone']);
check('Nepali: भोलि = tomorrow', $n['date'] === $tm, $n['date']);
check('Nepali: दुई जना = 2', (int) $n['seats'] === 2, (string) $n['seats']);
check('Nepali: सुरत = the Surat pickup', str_contains(mb_strtolower($n['boarding']), 'surat'), $n['boarding']);
check('Nepali: महिला = Female', $n['gender'] === 'Female', $n['gender']);
check('Nepali: name survives the fillers', $n['name'] === 'सीता देवी', $n['name']);
check('Nepali: language detected', $n['lang'] === 'ne', $n['lang']);
$g = p('કિરણ પટેલ 9898989898 કાલે બે ટિકિટ સુરત થી નેપાળ જવું છે');
check('Gujarati: કાલે = tomorrow', $g['date'] === $tm, $g['date']);
check('Gujarati: બે ટિકિટ = 2', (int) $g['seats'] === 2, (string) $g['seats']);
check('Gujarati: સુરત = the Surat pickup', str_contains(mb_strtolower($g['boarding']), 'surat'), $g['boarding']);
check('Gujarati: નેપાળ = toNepal', $g['direction'] === 'toNepal', $g['direction']);
check('Gujarati: name kept', $g['name'] === 'કિરણ પટેલ', $g['name']);
check('Gujarati: language detected', $g['lang'] === 'gu', $g['lang']);
$w = p('Ram 9876543210 शुक्रवार 3 लोग रोकड');
check('a Devanagari weekday sets a date', $w['date'] !== '' && (int) date('w', strtotime($w['date'])) === 5, $w['date']);
check('3 लोग = 3 seats', (int) $w['seats'] === 3, (string) $w['seats']);
check('રોકડ = cash', $w['pay'] === 'cash', $w['pay']);
check('  …and the name is just Ram', $w['name'] === 'Ram', $w['name']);
check('romanised Hindi detected', p('Nepal jana hai bhai 2 seat')['lang'] === 'hi');
check('romanised Nepali detected', p('2 jana ko ticket chahiyo bholi')['lang'] === 'ne');
check('plain English stays en', p('Ram 9876543210 2 seats tomorrow')['lang'] === 'en');
$k = p('कला देवी 9876543210');
check('कला (art) is never read as कल (tomorrow)', $k['date'] === '' && $k['name'] === 'कला देवी', $k['name'] . ' / ' . $k['date']);
check('भारत बाट नेपाल जाने = toNepal (destination phrase beats bare origin)', p('Hari 9876543210 भारत बाट नेपाल जाने')['direction'] === 'toNepal');
check('नेपाल बाट सूरत जाने = toIndia', p('Hari 9876543210 नेपाल बाट सूरत जाने')['direction'] === 'toIndia');
check('back to nepal = toNepal (phrase beats bare "back")', p('Hari 9876543210 back to nepal')['direction'] === 'toNepal');
check('asciiDigits maps both scripts', TicketBot::asciiDigits('९८ ૫૬') === '98 56');

echo "\n-- reviewed 10 Sep 2026: what the first cut got wrong --\n";
$c1 = p('Ram 9876543210 ठिक छ सिट चाहियो');
check('Nepali "ठिक छ" (ok) before a seat word is NOT six seats', (int) $c1['seats'] <= 1 && $c1['name'] === 'Ram', $c1['seats'] . ' / ' . $c1['name']);
$c2 = p('राम 9876543210 मुझे भेज दो टिकट');
check('Hindi "भेज दो" (send) is not two seats, and never a name', (int) $c2['seats'] <= 1 && $c2['name'] === 'राम', $c2['seats'] . ' / ' . $c2['name']);
check('  …but "दो टिकट" (two tickets) still counts', (int) p('राम 9876543210 दो टिकट')['seats'] === 2);
$c3 = p('Ram 9876543210 नेपाल से वापस');
check('"नेपाल से वापस" = returning to India', $c3['direction'] === 'toIndia' && $c3['name'] === 'Ram', $c3['direction'] . ' / ' . $c3['name']);
$c4 = p('Hari 9876543210 नेपाल बाट भारत');
check('"नेपाल बाट भारत" = to India (the word after बाट is the destination)', $c4['direction'] === 'toIndia' && $c4['name'] === 'Hari', $c4['direction'] . ' / ' . $c4['name']);
check('"nepal se back" still returns (English regression guard)', p('Ram 9876543210 nepal se back')['direction'] === 'toIndia');
check('"kathmandu se wapas" still returns', p('Ram 9876543210 kathmandu se wapas')['direction'] === 'toIndia');
check('"કિરણ નેપાળ થી પાછા" = to India', p('કિરણ 9898989898 નેપાળ થી પાછા')['direction'] === 'toIndia');
$c5 = p('राम 9876543210 रुपैडिया बाट भोलि 2 जना');
check('a bare Nepal-side town is a PICKUP, not a direction', str_contains(mb_strtolower($c5['boarding']), 'rupaidiha') && $c5['direction'] === '', $c5['boarding'] . ' / ' . $c5['direction']);
$c6 = p('सीता 9812345678 भोलिको लागि सुरतबाट नेपाल जाने');
check('attached postpositions: भोलिको = tomorrow, सुरतबाट = Surat pickup, name clean', $c6['date'] === $tm && str_contains(mb_strtolower($c6['boarding']), 'surat') && $c6['name'] === 'सीता', $c6['date'] . ' / ' . $c6['boarding'] . ' / ' . $c6['name']);
$c7 = p('સુરતથી નેપાળ જવું છે કિરણ 9898989898 કાલે');
check('Gujarati સુરતથી = Surat pickup, toNepal, name clean', str_contains(mb_strtolower($c7['boarding']), 'surat') && $c7['direction'] === 'toNepal' && $c7['name'] === 'કિરણ', $c7['boarding'] . ' / ' . $c7['name']);
check('"2 जनाको टिकट" = 2 seats', (int) p('राम 9876543210 2 जनाको टिकट')['seats'] === 2);
$c8 = p('भूपाल थापा 9876543210');
check('भूपाल is a given name, not the Limbli pickup', $c8['name'] === 'भूपाल थापा' && $c8['boarding'] === '', $c8['name'] . ' / ' . $c8['boarding']);
$c9 = p("राम 9876543210 \u{092C}\u{095C}\u{094C}\u{0926}\u{093E} से");   // precomposed ड़ (U+095C)
check('a precomposed nukta (बड़ौदा typed as U+095C) still finds the Baroda pickup', str_contains(mb_strtolower($c9['boarding']), 'barauda') || str_contains(mb_strtolower($c9['boarding']), 'baroda'), $c9['boarding'] . ' / ' . $c9['name']);
check('"2 tikit chahiye" is Hindi, not Gujarati', p('Ram 9876543210 2 tikit chahiye')['lang'] === 'hi');
check('"tikit chahiyo" is Nepali', p('Ram 9876543210 tikit chahiyo')['lang'] === 'ne');
check('"back to nepal" is still to Nepal', p('Ram 9876543210 back to nepal')['direction'] === 'toNepal');
check('"surat to nepal" keeps the Surat pickup', str_contains(mb_strtolower(p('Ram 9876543210 surat to nepal')['boarding']), 'surat'));
check('parse("") carries lang en', p('')['lang'] === 'en');

echo "\n" . ($FAIL === 0 ? "\033[32mALL {$PASS} PASSED\033[0m" : "\033[31m{$FAIL} FAILED\033[0m ({$PASS} passed)") . "\n\n";
exit($FAIL === 0 ? 0 : 1);
