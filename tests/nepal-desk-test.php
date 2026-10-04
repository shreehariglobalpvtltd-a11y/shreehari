<?php
/**
 * tests/nepal-desk-test.php — the Nepal-side counter (3 Oct 2026).
 *
 * A desk at Nepalgunj / Rupaidiha sells the RETURN run by default:
 *   - Settings::isNepalDesk() by desk code, by a free-typed counter name,
 *     and the counter_nepal_desks override;
 *   - index.php hands the desk + side + NPR rate to counter mode;
 *   - 14-counter.js opens a Nepal desk on Nepal → India and shows NPR;
 *   - the ticket PNG says which way it runs and, for a Nepal desk, the NPR;
 *   - QuickBot pre-picks "Return" for a Nepal desk.
 *
 *   php tests/nepal-desk-test.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit; }
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pass = 0; $fail = 0;
function check(string $l, bool $ok): void { global $pass, $fail; $ok ? $pass++ : $fail++; echo ($ok ? '  ok   ' : '  FAIL ') . $l . "\n"; }

echo "\n=== Nepal desk ===\n\n";

$old = Settings::getString('counter_nepal_desks', '');
try {
    Settings::set('counter_nepal_desks', '', 'string', 'counter');
    Settings::flush();
    check('NPJ is a Nepal desk by default', Settings::isNepalDesk('NPJ'));
    check('lower-case code works', Settings::isNepalDesk('npjd'));
    check('Rupaidiha desk RPD is Nepal side', Settings::isNepalDesk('RPD'));
    check('Surat STV is India side', !Settings::isNepalDesk('STV'));
    check('no code, counter name "Nepalgunj" -> Nepal side', Settings::isNepalDesk('', 'Nepalgunj Bus Park'));
    check('no code, Devanagari name -> Nepal side', Settings::isNepalDesk('', 'नेपालगञ्ज काउन्टर'));
    check('no code, "Surat" -> India side', !Settings::isNepalDesk('', 'Surat Chowk'));
    check('a code wins over the name (STV + "Nepalgunj" name = India)', !Settings::isNepalDesk('STV', 'Nepalgunj'));

    Settings::set('counter_nepal_desks', 'KTM, BRG', 'string', 'counter');
    Settings::flush();
    check('override list: KTM is Nepal side', Settings::isNepalDesk('KTM'));
    /* 4 Oct 2026: a desk that has a ROW in the desk table answers from its
       row (default_direction); the legacy list only decides codes the table
       has never heard of, and everything on a database without the table. */
    if (CounterDesk::hasTable() && CounterDesk::get('NPJ') !== null) {
        check('a desk in the table answers from its own row, not the legacy list', Settings::isNepalDesk('NPJ'));
    } else {
        check('override list replaces the default (NPJ no longer listed)', !Settings::isNepalDesk('NPJ'));
    }
} finally {
    Settings::set('counter_nepal_desks', $old, 'string', 'counter');
    Settings::flush();
}

$root  = dirname(__DIR__);
$index = (string) file_get_contents($root . '/index.php');
$js    = (string) file_get_contents($root . '/assets/js/14-counter.js');
$tk    = (string) file_get_contents($root . '/includes/ticket.php');
$qt    = (string) file_get_contents($root . '/admin/quick-ticket.php');

check('index.php boot carries desk.side from isNepalDesk', str_contains($index, "Settings::isNepalDesk(\$deskCode, \$deskName) ? 'nepal' : 'india'"));
check('index.php boot carries the NPR rate', str_contains($index, "'nprRate'"));
check('counter JS: Nepal desk opens on the return run', str_contains($js, "setSearchDir(SIDE === 'nepal' ? 'back' : 'go')"));
check('counter JS: a deep link (from/to) is never overridden', str_contains($js, "if (!force && (p.get('from') || p.get('to'))) return;"));
check('counter JS: NPR line on the summary follows the desk\'s money', str_contains($js, "var npr = showNpr() ? nprText(due) : '';"));
check('counter JS: an older boot payload keeps the side rule', str_contains($js, "return DESK.currency ? DESK.currency === 'NPR' : SIDE === 'nepal';"));
check('counter JS: the pay chips follow the desk', str_contains($js, 'STAFF.desk.methods'));
check('counter JS: per-browser side switch', str_contains($js, "id=\"cbSide\""));
check('ticket: direction from Boarding::routeDirection', str_contains($tk, "Boarding::routeDirection(['from_city'"));
check('ticket: NEPAL -> INDIA band on the return run', str_contains($tk, "'E-TICKET  ·  NEPAL -> INDIA'"));
check('ticket: NPR only for a Nepal desk', str_contains($tk, "Settings::isNepalDesk((string) (\$booking['agent_counter_code'] ?? '')"));
check('QuickBot: Nepal desk pre-picks Return', str_contains($qt, "direction: <?= \$deskNepal ? \"'toIndia'\" : \"''\" ?>"));

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
