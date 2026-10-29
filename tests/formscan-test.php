<?php
/**
 * formscan-test.php — FormScan::clean(), the whole-form scan reader that
 * turns a Gemini answer into on-screen form values (24 Oct 2026). Pure PHP:
 * no Gemini, no DB, no Settings.
 *
 *     php tests/formscan-test.php
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
define('SHG_APP', true);
require dirname(__DIR__) . '/includes/docverify.php';
require dirname(__DIR__) . '/includes/formscan.php';

$fail = 0;
$n = 0;
function fs_ok(bool $cond, string $what): void
{
    global $fail, $n;
    $n++;
    if (!$cond) { $fail++; echo "  FAIL  $what\n"; }
}

// --- catalog ---
fs_ok(isset(FormScan::catalog('ind')['p_name']), 'ind catalog has p_name');
fs_ok(isset(FormScan::catalog('ind')['p_idno']), 'ind catalog has p_idno');
fs_ok(isset(FormScan::catalog('org')['p_rep']), 'org catalog has p_rep (representative)');
fs_ok(isset(FormScan::catalog('org')['p_name']), 'org catalog has p_name (company)');
fs_ok(FormScan::catalog('nope') === [], 'unknown kind catalog empty');

// --- clean(): a full individual (agent) form ---
$r = FormScan::clean(['is_form' => true, 'fields' => [
    'p_name'  => 'Ram Bahadur Thapa',
    'p_phone' => '+977 98-11-222333',
    'p_email' => 'RAM@X.COM',
    'p_dob'   => '1990-05-14',
    'p_occ'   => 'Teacher',
    'p_idtype'=> 'Citizenship',
    'p_idno'  => '५२-०१-७५-१२३४५',
    'cv_exp'  => '5 years',
    'b_acc'   => '01234567',
    'p_addr'  => "Nepalgunj-5,\n Banke",
]], 'ind');
fs_ok($r['status'] === 'read', 'read: status when fields found');
fs_ok($r['fields']['p_name'] === 'Ram Bahadur Thapa', 'read: name kept');
fs_ok($r['fields']['p_phone'] === '9811222333', 'read: phone country code stripped');
fs_ok($r['fields']['p_email'] === 'ram@x.com', 'read: email lower-cased');
fs_ok($r['fields']['p_dob'] === '1990-05-14', 'read: AD date kept');
fs_ok($r['fields']['p_idtype'] === '1', 'read: Citizenship -> select 1');
fs_ok($r['fields']['p_idno'] === '52-01-75-12345', 'read: Devanagari digits -> 0-9');
fs_ok($r['fields']['cv_exp'] === '5', 'read: experience years only');
fs_ok($r['fields']['p_addr'] === 'Nepalgunj-5, Banke', 'read: address one line');

// --- clean(): values that must be dropped ---
$r = FormScan::clean(['is_form' => true, 'fields' => [
    'p_dob'    => '2020-01-01',
    'p_idtype' => 'ration card',
    'cv_exp'   => '200',
    'p_email'  => 'notanemail',
    'p_idno'   => 'abc',
]], 'ind');
fs_ok(!isset($r['fields']['p_dob']), 'drop: a child\'s date');
fs_ok(!isset($r['fields']['p_idtype']), 'drop: unknown ID type');
fs_ok(!isset($r['fields']['cv_exp']), 'drop: impossible experience');
fs_ok(!isset($r['fields']['p_email']), 'drop: bad email');
fs_ok(!isset($r['fields']['p_idno']), 'drop: ID number with no digit');
fs_ok($r['status'] === 'partial', 'partial: form seen but nothing readable');

// --- clean(): not our form ---
$r = FormScan::clean(['is_form' => false, 'fields' => ['p_name' => 'Whatever']], 'ind');
fs_ok($r['status'] === 'notform', 'notform: is_form false');
fs_ok($r['fields'] === [], 'notform: no fields');

// --- clean(): a 10-digit Indian mobile is left as is ---
$r = FormScan::clean(['is_form' => true, 'fields' => ['p_phone' => '9811222333', 'p_name' => 'Sita Devi']], 'ind');
fs_ok($r['fields']['p_phone'] === '9811222333', 'phone: plain 10 digits kept');

// --- staff kind ---
fs_ok(isset(FormScan::catalog('staff')['s_dob']), 'staff catalog has s_dob');
fs_ok(isset(FormScan::catalog('staff')['s_gender']), 'staff catalog has s_gender');
fs_ok(isset(FormScan::catalog('staff')['p_name']), 'staff catalog has p_name');
$r = FormScan::clean(['is_form' => true, 'fields' => [
    'p_name'   => 'Sita Devi',
    's_gender' => 'Female',
    's_dob'    => '1995-03-20',
    's_email'  => 'SITA@X.COM',
    'p_idtype' => 'Driving licence',
    's_lic'    => '01-06-00123456',
    'cv_exp'   => '4 yrs',
]], 'staff');
fs_ok($r['status'] === 'read', 'staff: status read');
fs_ok($r['fields']['p_name'] === 'Sita Devi', 'staff: name');
fs_ok($r['fields']['s_gender'] === 'female', 'staff: gender -> female');
fs_ok($r['fields']['s_dob'] === '1995-03-20', 'staff: dob');
fs_ok($r['fields']['s_email'] === 'sita@x.com', 'staff: email lower');
fs_ok($r['fields']['p_idtype'] === '5', 'staff: Driving licence -> 5');
fs_ok($r['fields']['s_lic'] === '01-06-00123456', 'staff: licence number');
fs_ok($r['fields']['cv_exp'] === '4', 'staff: experience years only');
$r = FormScan::clean(['is_form' => true, 'fields' => ['s_gender' => 'पुरुष', 'p_name' => 'Ram']], 'staff');
fs_ok($r['fields']['s_gender'] === 'male', 'staff: Devanagari पुरुष -> male');

// --- 30 Oct 2026: the live one-page form (form 'apply', f_* ids) ---
fs_ok(isset(FormScan::catalog('ind', 'apply')['f_name']), 'apply: ind catalog has f_name');
fs_ok(isset(FormScan::catalog('org', 'apply')['f_idno']), 'apply: org catalog has f_idno');
fs_ok(!isset(FormScan::catalog('ind', 'apply')['f_lic_cat']), 'apply: no licence boxes for an agent');
fs_ok(isset(FormScan::catalog('staff', 'apply')['f_lic_exp']), 'apply: licence expiry for staff');
fs_ok(FormScan::catalog('nope', 'apply') === [], 'apply: unknown kind catalog empty');
fs_ok(isset(FormScan::catalog('ind', 'zzz')['p_name']), 'unknown form falls back to the CV ids');
$r = FormScan::clean(['is_form' => true, 'fields' => [
    'f_name'     => 'Hari  Prasad',
    'f_gender'   => 'Female',
    'f_dob'      => '1988-11-02',
    'f_phone'    => '+91 98765 43210',
    'f_country'  => 'India',
    'f_district' => 'Surat',
    'f_idtype'   => 'Aadhaar Card',
    'f_idno'     => '2345 6789 0123',
    'f_em_phone' => '०९८१२३४५६७८',
    'f_lic_exp'  => '2030-05-31',
    'p_name'     => 'not a box on this page',
]], 'ind', 'apply');
fs_ok($r['status'] === 'read', 'apply: status read');
fs_ok($r['fields']['f_name'] === 'Hari Prasad', 'apply: name, spaces folded');
fs_ok($r['fields']['f_gender'] === 'female', 'apply: gender');
fs_ok($r['fields']['f_phone'] === '9876543210', 'apply: +91 stripped');
fs_ok($r['fields']['f_country'] === 'IN', 'apply: India -> IN');
fs_ok($r['fields']['f_idtype'] === 'aadhaar', 'apply: "Aadhaar Card" -> aadhaar (the select value)');
fs_ok($r['fields']['f_idno'] === '2345 6789 0123', 'apply: ID number kept');
fs_ok($r['fields']['f_em_phone'] === '9812345678', 'apply: Devanagari emergency phone, trunk 0 stripped');
fs_ok(!isset($r['fields']['f_lic_exp']), 'apply: no licence box asked for an agent, so none filled');
fs_ok(!isset($r['fields']['p_name']), 'apply: ids of another page are dropped');
$r = FormScan::clean(['is_form' => true, 'fields' => [
    'f_idtype'  => 'Driving Licence',
    'f_country' => 'नेपाल',
    'f_lic_exp' => '2031-02-28',
    'f_lic_cat' => 'B, C',
]], 'staff', 'apply');
fs_ok($r['fields']['f_idtype'] === 'driving_license', 'apply staff: Driving Licence -> driving_license');
fs_ok($r['fields']['f_country'] === 'NP', 'apply staff: नेपाल -> NP');
fs_ok($r['fields']['f_lic_exp'] === '2031-02-28', 'apply staff: licence valid-until kept');
fs_ok($r['fields']['f_lic_cat'] === 'B, C', 'apply staff: licence category');
$r = FormScan::clean(['is_form' => true, 'fields' => [
    'f_idtype' => 'Voter ID', 'f_country' => 'Bhutan', 'f_lic_exp' => '31/12/2030', 'f_name' => 'Gita',
]], 'staff', 'apply');
fs_ok(!isset($r['fields']['f_idtype']), 'apply: an ID type the page does not offer is left for the applicant');
fs_ok(!isset($r['fields']['f_country']), 'apply: an unknown country is left');
fs_ok(!isset($r['fields']['f_lic_exp']), 'apply: a date not in YYYY-MM-DD is dropped');
fs_ok(FormScan::clean(['is_form' => true, 'fields' => ['f_idtype' => 'PAN / GST card']], 'ind', 'apply')['fields']['f_idtype'] === 'pan', 'apply: PAN');
fs_ok(FormScan::clean(['is_form' => true, 'fields' => ['f_idtype' => 'Japan passport']], 'ind', 'apply')['fields']['f_idtype'] === 'passport', 'apply: "Japan" is not PAN');

// --- 30 Oct 2026: the A4 staff sheet (form 'sa', sa_* ids) ---
fs_ok(isset(FormScan::catalog('staff', 'sa')['sa_name']), 'sa: staff catalog has sa_name');
fs_ok(isset(FormScan::catalog('staff', 'sa')['sa_bifsc']), 'sa: staff catalog has the IFSC box');
fs_ok(FormScan::catalog('ind', 'sa') === [], 'sa: only the staff kind');
$r = FormScan::clean(['is_form' => true, 'fields' => [
    'sa_name'     => 'Bikash Thapa',
    'sa_role'     => 'Bus driver',
    'sa_qual'     => '+2 (2015)',
    'sa_exp'      => '6 years',
    'sa_country'  => 'Nepal',
    'sa_idtype'   => 'नागरिकता',
    'sa_idno'     => '४५-०१-७२-०९८७६',
    'sa_lic'      => '01-06-00123456',
    'sa_licExp'   => '2029-08-15',
    'sa_ref1p'    => '9800000001',
    'sa_bifsc'    => 'nice 0000 123',
    'sa_bacc'     => '0101-0012-3456-78',
    'sa_place'    => 'Nepalgunj',
]], 'staff', 'sa');
fs_ok($r['status'] === 'read', 'sa: status read');
fs_ok($r['fields']['sa_role'] === 'driver', 'sa: Bus driver -> driver');
fs_ok($r['fields']['sa_qual'] === 'plus2', 'sa: +2 (2015) -> plus2, the year is not the 12th');
fs_ok($r['fields']['sa_exp'] === '6', 'sa: experience years only');
fs_ok($r['fields']['sa_country'] === 'NP', 'sa: Nepal -> NP');
fs_ok($r['fields']['sa_idtype'] === 'citizenship', 'sa: नागरिकता -> citizenship');
fs_ok($r['fields']['sa_idno'] === '45-01-72-09876', 'sa: Devanagari ID digits');
fs_ok($r['fields']['sa_licExp'] === '2029-08-15', 'sa: licence valid-until');
fs_ok($r['fields']['sa_bifsc'] === 'NICE0000123', 'sa: IFSC upper-cased, spaces dropped');
fs_ok($r['fields']['sa_bacc'] === '0101-0012-3456-78', 'sa: account number');
$q = static fn(string $s): string => FormScan::clean(['is_form' => true, 'fields' => ['sa_qual' => $s]], 'staff', 'sa')['fields']['sa_qual'] ?? '';
fs_ok($q('SEE भन्दा कम') === 'below_see', 'sa qual: below 10th');
fs_ok($q('SLC 2012') === 'see', 'sa qual: SLC 2012 -> see (2012 is a year)');
fs_ok($q('Diploma in कम्प्युटर') === 'diploma', 'sa qual: diploma (कम्प्युटर is not "less")');
fs_ok($q('BBS') === 'bachelor', 'sa qual: BBS -> bachelor');
fs_ok($q('स्नातकोत्तर') === 'master', 'sa qual: स्नातकोत्तर -> master');
fs_ok($q('class 12') === 'plus2', 'sa qual: class 12 -> plus2');
$role = static fn(string $s): string => FormScan::clean(['is_form' => true, 'fields' => ['sa_role' => $s]], 'staff', 'sa')['fields']['sa_role'] ?? '';
fs_ok($role('सह-चालक') === 'conductor', 'sa role: सह-चालक -> conductor, not driver');
fs_ok($role('खलासी') === 'helper', 'sa role: खलासी -> helper');
fs_ok($role('Accountant') === 'accountant', 'sa role: accountant');
fs_ok($role('pilot') === '', 'sa role: unknown left for the applicant');
fs_ok(!isset(FormScan::clean(['is_form' => true, 'fields' => ['sa_bifsc' => 'ABC']], 'staff', 'sa')['fields']['sa_bifsc']), 'sa: a bad IFSC is dropped');

echo ($fail === 0 ? "OK" : "FAILED") . " — formscan-test: " . ($n - $fail) . "/$n passed\n";
exit($fail === 0 ? 0 : 1);
