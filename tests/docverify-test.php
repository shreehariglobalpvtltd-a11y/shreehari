<?php
/**
 * docverify-test.php — the name / number comparison behind the agent
 * form's document check (18 Oct 2026). Pure PHP: no Gemini, no OCR, no DB.
 *
 *     php tests/docverify-test.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
define('SHG_APP', true);
require dirname(__DIR__) . '/includes/docverify.php';

$fail = 0;
$n = 0;
function dv_ok(bool $cond, string $what): void
{
    global $fail, $n;
    $n++;
    if (!$cond) { $fail++; echo "  FAIL  $what\n"; }
}

// --- names ---
dv_ok(DocVerify::nameMatches('Ram Bahadur Thapa', 'RAM BAHADUR THAPA'), 'same name, other case');
dv_ok(DocVerify::nameMatches('Ram Thapa', 'Ram Bahadur Thapa'), 'middle name left out is fine');
dv_ok(DocVerify::nameMatches('Ram Bahadur Thapa', 'राम बहादुर थापा'), 'Roman vs Devanagari');
dv_ok(DocVerify::nameMatches('Sushila Sharma', 'Susheela Sarma'), 'usual spelling variants');
dv_ok(DocVerify::nameMatches('Mohd Shakil', 'Mohd Shakeel'), 'ee / i');
dv_ok(DocVerify::nameMatches('Shri Ram Thapa', 'Ram Thapa'), 'title ignored');
dv_ok(!DocVerify::nameMatches('Hari Thapa', 'Ram Bahadur Thapa'), 'other first name fails');
dv_ok(!DocVerify::nameMatches('Ram Bahadur', 'Ram Bahadur Thapa'), 'surname missing fails');
dv_ok(!DocVerify::nameMatches('Ram Bahadur Thapa', 'Ram Bahadur Magar'), 'other surname fails');
dv_ok(!DocVerify::nameMatches('Sita Devi', 'Gita Kumari'), 'other person fails');
dv_ok(!DocVerify::nameMatches('', 'Ram'), 'empty typed fails');
dv_ok(DocVerify::nameMatches('Krishna Prasad Yadav', 'कृष्ण प्रसाद यादव'), 'conjuncts transliterate');

// --- 23 Oct 2026: fieldsFromRead (the pre-fill) ---
$fr = DocVerify::fieldsFromRead(['is_id_document' => true, 'document_type' => 'citizenship', 'holder_name_roman' => 'RAM BAHADUR THAPA',
    'id_number' => '५२-०१-७५-१२३४५', 'dob_ad' => '1990-05-14', 'dob_bs' => '2047-01-31', 'gender' => 'Male',
    'father_name_roman' => 'Hari Thapa', 'address_roman' => "Nepalgunj-5,\n Banke"]);
dv_ok($fr['status'] === 'read', 'read: status');
dv_ok($fr['fields']['name'] === 'Ram Bahadur Thapa', 'read: name title-cased');
dv_ok($fr['fields']['idno'] === '52-01-75-12345', 'read: Devanagari digits -> 0-9');
dv_ok($fr['fields']['idtype'] === '1', 'read: citizenship -> radio 1');
dv_ok($fr['fields']['dob'] === '1990-05-14', 'read: AD date kept');
dv_ok($fr['fields']['gender'] === 'male', 'read: gender lower-cased');
dv_ok($fr['fields']['address'] === 'Nepalgunj-5, Banke', 'read: address one line');
dv_ok($fr['fields']['father'] === 'Hari Thapa', 'read: father');
$fr = DocVerify::fieldsFromRead(['is_id_document' => true, 'document_type' => 'aadhaar', 'holder_name_roman' => 'राम थापा',
    'id_number' => 'none', 'dob_ad' => '2020-01-01', 'gender' => 'x', 'address_roman' => 'काठमाडौं']);
dv_ok($fr['fields']['name'] === '', 'read: non-Roman name dropped');
dv_ok($fr['fields']['idno'] === '', 'read: number without a digit dropped');
dv_ok($fr['fields']['idtype'] === '0', 'read: aadhaar -> radio 0');
dv_ok($fr['fields']['dob'] === '', 'read: a child\'s date dropped');
dv_ok($fr['fields']['gender'] === '', 'read: unknown gender dropped');
dv_ok($fr['fields']['address'] === '', 'read: Devanagari address dropped');
dv_ok(DocVerify::fieldsFromRead(['is_id_document' => false])['status'] === 'notdoc', 'read: not a document');
dv_ok(DocVerify::fieldsFromRead(['is_id_document' => true, 'dob_ad' => '1990-02-30'])['fields']['dob'] === '', 'read: impossible date dropped');

// --- numbers ---
$read = static fn(array $nums, string $name = 'Ram Bahadur Thapa', bool $doc = true) => [
    'is_id_document' => $doc, 'document_type' => 'citizenship', 'holder_name' => '', 'holder_name_roman' => $name, 'id_numbers' => $nums,
];
dv_ok(DocVerify::judgeStructured($read(['52-01-75-12345']), 'Ram Bahadur Thapa', '5201751234 5')['status'] === 'pass', 'citizenship number, separators ignored');
dv_ok(DocVerify::judgeStructured($read(['५२-०१-७५-१२३४५']), 'Ram Thapa', '52-01-75-12345')['status'] === 'pass', 'Devanagari digits');
dv_ok(DocVerify::judgeStructured($read(['1234 5678 9012']), 'Ram Thapa', '123456789012')['status'] === 'pass', 'Aadhaar spaced');
$r = DocVerify::judgeStructured($read(['1234 5678 9012']), 'Ram Thapa', '123456789013');
dv_ok($r['status'] === 'fail' && $r['number_ok'] === false && $r['name_ok'] === true, 'one digit off fails on number only');
$r = DocVerify::judgeStructured($read(['1234 5678 9012'], 'Gita Kumari'), 'Ram Thapa', '123456789012');
dv_ok($r['status'] === 'fail' && $r['name_ok'] === false && $r['number_ok'] === true, 'other holder fails on name only');
$r = DocVerify::judgeStructured($read(['1234 5678 9012'], 'Ram Thapa', false), 'Ram Thapa', '123456789012');
dv_ok($r['status'] === 'fail' && in_array('not_doc', $r['problems'], true), 'not an ID fails');
dv_ok(DocVerify::judgeStructured($read(['M1234567']), 'Ram Thapa', 'm1234567')['status'] === 'pass', 'passport case');
dv_ok(DocVerify::judgeStructured($read(['ABC1O23456']), 'Ram Thapa', 'ABC1023456')['status'] === 'pass', 'O read as 0 still matches');

// --- OCR text ---
$ocr = "GOVERNMENT OF INDIA\nRam Bahadur Thapa\nDOB: 01/01/1990\nMale\n1234 5678 9012\nMera Aadhaar, Meri Pehchan";
dv_ok(DocVerify::judgeText($ocr, 'Ram Bahadur Thapa', '1234-5678-9012')['status'] === 'pass', 'OCR Aadhaar passes');
dv_ok(DocVerify::judgeText($ocr, 'Ram Bahadur Thapa', '1234-5678-9013')['status'] === 'fail', 'OCR wrong number fails');
dv_ok(DocVerify::judgeText($ocr, 'Hari Thapa', '123456789012')['status'] === 'fail', 'OCR wrong name fails');
dv_ok(DocVerify::judgeText("Ram Thapa 123456789012", 'Ram Thapa', '123456789012')['status'] === 'fail', 'OCR paper without ID words fails');
$np = "नेपाल सरकार\nनागरिकताको प्रमाणपत्र\nना.प्र.नं. ५२-०१-७५-१२३४५\nनाम थर: राम बहादुर थापा";
dv_ok(DocVerify::judgeText($np, 'Ram Bahadur Thapa', '52-01-75-12345')['status'] === 'pass', 'OCR Nepali citizenship passes');

echo $fail === 0 ? "docverify-test: all $n passed\n" : "docverify-test: $fail of $n FAILED\n";
exit($fail === 0 ? 0 : 1);
