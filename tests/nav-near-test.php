<?php
/**
 * tests/nav-near-test.php — NavCats::parse (includes/navcats.php, 22 Oct 2026):
 * which kind of place a rider's question asks for, in English, Hindi and
 * Nepali, Roman and Devanagari, with the owner's own typos. Pure: no DB.
 *
 *   php tests/nav-near-test.php
 */
declare(strict_types=1);

define('SHG_APP', true);
require dirname(__DIR__) . '/includes/navcats.php';

$fail = 0;
$n = 0;
$check = static function (string $q, ?string $cat, ?array $rest = null, ?bool $near = null) use (&$fail, &$n): void {
    $n++;
    $p = NavCats::parse($q);
    $ok = $p['cat'] === $cat && ($rest === null || $p['rest'] === $rest) && ($near === null || $p['near'] === $near);
    if (!$ok) {
        $fail++;
        echo "FAIL  \"$q\" -> cat=" . var_export($p['cat'], true) . ' rest=' . json_encode($p['rest'], JSON_UNESCAPED_UNICODE) . ' near=' . var_export($p['near'], true)
            . "  (want cat=" . var_export($cat, true) . ($rest !== null ? ' rest=' . json_encode($rest, JSON_UNESCAPED_UNICODE) : '') . ")\n";
    }
};

// the owner's own examples (typos included)
$check('suggest me ressurtent nerar me', 'food', [], true);
$check('suggest me restaurant near me', 'food', [], true);
$check('near hair salon', 'salon', [], true);
$check('hair salon near me', 'salon', [], true);
$check('nearest saloon', 'salon', [], true);
// English
$check('gym nearby', 'gym', [], true);
$check('where is the nearest atm', 'atm', [], true);
$check('coffee shop around here', 'cafe', [], true);
$check('police station near me', 'police');
$check('petrol station', 'fuel');
$check('bus station near me', 'busstand');
$check('railway station', 'railway');
$check('ice cream near me', 'icecream');
$check('mobile repair shop', 'mobile');
$check('xerox near me', 'stationery');
$check('best hotels', 'hotel');
$check('restaurants near me', 'food');
$check('salons', 'salon');
$check('tyre puncture shop', 'garage');
$check('places to visit', 'sights');
$check('wedding hall near me', 'venue');
// Hindi / Nepali, Roman
$check('najikai khana khane thau', 'food');
$check('paas mein nai ki dukan', 'salon');
$check('najik ko petrol pump kaha cha', 'fuel', [], true);
$check('dawai ki dukan', 'pharmacy');
$check('sabji market najik', 'market', [], true);
$check('tarkari pasal', 'vegetables');
$check('mithai ki dukan', 'bakery');
// Devanagari
$check('नजिकको सैलुन', 'salon', [], true);
$check('पास में सैलून', 'salon', [], true);
$check('नजिकको अस्पताल कहाँ छ', 'hospital', [], true);
$check('यहाँ नजिक खाना', 'food', [], true);
$check('मेडिकल पसल', 'pharmacy');
$check('ब्यूटी पार्लर पास में', 'salon');
$check('नजिकै ATM', 'atm');
// a town after the kind
$check('hair salon in pokhara', 'salon', ['pokhara']);
$check('restaurant surat', 'food', ['surat']);
// no kind named: a brand near me
$check('dominos near me', null, ['dominos'], true);
$check('pizza near me', 'fastfood', [], true);
$check('reliance trends near me', null, ['reliance', 'trends'], true);
// not a place question
$check('when is the next bus to surat', null, null, false);
$check('what is the fare', null, null, false);
$check('namaste', null, null, false);

echo ($fail === 0 ? 'PASS' : 'FAIL') . " nav-near: " . ($n - $fail) . "/$n\n";
exit($fail === 0 ? 0 : 1);
