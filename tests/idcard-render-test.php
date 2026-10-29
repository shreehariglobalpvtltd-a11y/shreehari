<?php
/**
 * ID card + visiting card renderer (29 Oct 2026 redesign: website bus photo,
 * logo ™, agent code, website; drawn at 2x and scaled down).
 *
 *   php -c .claude/php-dev.ini tests/idcard-render-test.php
 *
 * Renders every face for an agent with a photo, a staff member with a
 * Nepali name and no photo, and a partner; checks the sizes, the print
 * sheets and the PDF. Writes nothing. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/idcard.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}
$size = static function (string $png): array {
    $i = @getimagesizefromstring($png);
    return is_array($i) ? [(int) $i[0], (int) $i[1], (string) $i['mime']] : [0, 0, ''];
};

$photo = (string) @file_get_contents(dirname(__DIR__) . '/assets/img/ceo.jpg');
$agent = ['kind' => 'agent', 'name' => 'Test Agent Long Surname Example', 'roleEn' => 'Authorised Agent', 'roleNe' => 'अधिकृत एजेन्ट',
    'photo' => $photo !== '' ? $photo : null, 'sig' => null, 'ref' => 'AGR-000999', 'memberCode' => 'A-9', 'agentCode' => 'SHG-0099',
    'route' => '', 'place' => 'Nepalgunj', 'phone' => '+977 9800000000', 'contact' => '', 'email' => 'agent@example.test',
    'expires' => '2027-10-01', 'issued' => '2026-10-01', 'valid' => true, 'public' => true, 'waDigits' => '9779800000000', 'studio' => null];
$staff = ['kind' => 'staff', 'name' => 'राम बहादुर थापा', 'roleEn' => 'Booking Clerk', 'roleNe' => 'बुकिङ क्लर्क', 'photo' => null,
    'agentCode' => '', 'memberCode' => 'S-4', 'valid' => false, 'public' => false, 'waDigits' => ''] + $agent;
$partner = ['kind' => 'partner', 'name' => 'Royal Travels Pvt. Ltd.', 'roleEn' => 'Pvt. Ltd. Partner', 'roleNe' => 'साझेदार कम्पनी',
    'contact' => 'Hari Prasad', 'agentCode' => ''] + $agent;

foreach (['agent' => $agent, 'staff' => $staff, 'partner' => $partner] as $who => $p) {
    $t0 = microtime(true);
    [$w, $h, $m] = $size(IdCard::png($p, 'front'));
    check("$who: ID front is a 638 x 1011 PNG", $w === IdCard::W && $h === IdCard::H && $m === 'image/png');
    [$w, $h] = $size(IdCard::png($p, 'back'));
    check("$who: ID back is 638 x 1011", $w === IdCard::W && $h === IdCard::H);
    [$w, $h] = $size(IdCard::png($p, 'sheet'));
    check("$who: ID print sheet holds both faces side by side", $w === IdCard::W * 2 + 40 && $h === IdCard::H);
    [$w, $h] = $size(IdCard::visitingPng($p, 'front'));
    check("$who: visiting front is 1050 x 602", $w === IdCard::VW && $h === IdCard::VH);
    [$w, $h] = $size(IdCard::visitingPng($p, 'back'));
    check("$who: visiting back is 1050 x 602", $w === IdCard::VW && $h === IdCard::VH);
    check("$who: all faces drawn in under 6 s", microtime(true) - $t0 < 6.0);
}
$pdf = IdCard::pdf($agent, 'all');
check('the print PDF is a PDF with both cards', str_starts_with($pdf, '%PDF') && strlen($pdf) > 50000);

// The code printed big: the agent's SHG code wins over the member / card number.
$code = (new ReflectionMethod(IdCard::class, 'cardCode'));
$code->setAccessible(true);
check('agent card prints the SHG agent code', $code->invoke(null, $agent) === ['AGENT CODE', 'SHG-0099']);
check('staff card prints the staff ID', $code->invoke(null, $staff) === ['STAFF ID', 'S-4']);
check('partner without a login prints its member code', $code->invoke(null, ['agentCode' => ''] + $partner) === ['PARTNER CODE', 'A-9']);
check('Poppins is shipped with its licence', is_file(dirname(__DIR__) . '/assets/fonts/Poppins-Bold.ttf') && is_file(dirname(__DIR__) . '/assets/fonts/OFL-Poppins.txt'));
check('the website bus photos are there', is_file(dirname(__DIR__) . '/assets/img/hd/bus-hero.webp') && is_file(dirname(__DIR__) . '/assets/img/hd/bus-cutout.webp'));

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
