<?php
/**
 * tests/nameguess-test.php — NameGuess (15 Oct 2026): the name → F / M guess,
 * its learning table, and the bars the three booking doors use.
 *
 *   php tests/nameguess-test.php
 */
declare(strict_types=1);
define('SHG_APP', true);
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/nameguess.php';

$fail = 0; $pass = 0;
$ok = static function (bool $c, string $what) use (&$fail, &$pass): void {
    if ($c) { $pass++; } else { $fail++; echo "FAIL  $what\n"; }
};
$is = static function (string $name, string $want, float $min = NameGuess::SHOW) use ($ok): void {
    $g = NameGuess::gender($name);
    $ok($g['gender'] === $want && ($want === '' || $g['confidence'] >= $min),
        "$name → want '$want' ≥$min, got '{$g['gender']}' {$g['confidence']} ({$g['source']})");
};

Settings::set('ai_name_guess_on', '1', 'bool');
Settings::flush();

/* rules + seed */
$is('Sita Thapa', 'Female', NameGuess::AUTO);
$is('Ram Bahadur Thapa', 'Male', NameGuess::AUTO);
$is('Mrs Kamala Rai', 'Female', NameGuess::AUTO);
$is('Geetaben Patel', 'Female', NameGuess::AUTO);
$is('Rameshbhai Patel', 'Male', NameGuess::AUTO);
$is('Kiran Kumari', 'Female', NameGuess::AUTO);
$is('Harpreet Kaur', 'Female', NameGuess::AUTO);
$is('Dhan Maya Gurung', 'Female', NameGuess::AUTO);
$is('Bishnu Maya', 'Female', NameGuess::AUTO);
$is('Bishnu Prasad', 'Male', NameGuess::AUTO);
$is('Lokendra Shahi', 'Male', NameGuess::AUTO);
$is('Sharmila', 'Female', NameGuess::AUTO);
$is('Zxqwy', '');
$is('', '');
$is('Ram (2)', 'Male');
$is('सीता देवी', 'Female', NameGuess::AUTO);
$is('राम बहादुर', 'Male', NameGuess::AUTO);
$is('Kunjita', 'Female');                         // ending only - shown, not auto
$ok(NameGuess::confident('Kunjita') === null, 'an ending alone is never acted on without a look');
// conflict: female first name + "Bahadur" → pulled below AUTO
$c = NameGuess::gender('Sita Bahadur');
$ok($c['confidence'] < NameGuess::AUTO, 'two signals that disagree stay below AUTO (' . $c['confidence'] . ')');

/* switch off = no guess at all */
Settings::set('ai_name_guess_on', '0', 'bool'); Settings::flush();
$is('Sita Thapa', '');
Settings::set('ai_name_guess_on', '1', 'bool'); Settings::flush();

/* learning */
Database::run("DELETE FROM ai_name_gender WHERE name IN ('zxqwy','sita')");
NameGuess::teach('Zxqwy Lama', 'Female');
$ok(NameGuess::gender('Zxqwy Lama')['gender'] === '', 'one sale is not enough to learn from');
NameGuess::teach('Zxqwy', 'Female');
NameGuess::teach('Mrs Zxqwy Rai', 'Female');
$g = NameGuess::gender('Zxqwy Lama');
$ok($g['gender'] === 'Female' && $g['source'] === 'learned', 'three sales teach the name (' . json_encode($g) . ')');
NameGuess::teach('Quick Walk In', 'Male');
$ok(!Database::exists("SELECT 1 FROM ai_name_gender WHERE name = 'quick'"), 'placeholder names teach nothing');
NameGuess::teach('Zxqwy', 'Other');
$ok((int) Database::scalar("SELECT female FROM ai_name_gender WHERE name='zxqwy'") === 3, 'Other / blank gender teaches nothing');

$r = NameGuess::rebuild();
$ok($r['passengers'] >= 0 && $r['names'] >= 0, 'rebuild runs (' . json_encode($r) . ')');
$ok(!Database::exists("SELECT 1 FROM ai_name_gender WHERE name = 'zxqwy'"), 'rebuild recounts from booking_passengers only');
$s = NameGuess::stats();
$ok($s['names'] === $r['names'], 'stats match the rebuild');

echo "nameguess: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
