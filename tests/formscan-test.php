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

echo ($fail === 0 ? "OK" : "FAILED") . " — formscan-test: " . ($n - $fail) . "/$n passed\n";
exit($fail === 0 ? 0 : 1);
