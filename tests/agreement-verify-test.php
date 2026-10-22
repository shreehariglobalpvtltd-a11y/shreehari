<?php
/**
 * tests/agreement-verify-test.php — 23 Oct 2026, owner: "unique agreement ID
 * and verification QR", "witness and guarantor details", staff agreement v1.1.
 *
 *   php -c .claude/php-dev.ini tests/agreement-verify-test.php
 *
 * Runs against a TEST database only. Writes one staff application and one
 * agent application, removes both at the end. Exit 1 on any failure.
 * The verify-page checks go over HTTP to SHG_TEST_BASE (default
 * http://localhost:8893) and are skipped when no server answers there.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agreementverify.php';
require_once INCLUDE_PATH . '/idcard.php';
require_once INCLUDE_PATH . '/agreementprint.php';

$DBN = (string) DB_NAME;
if (!str_contains($DBN, 'test') && !str_starts_with($DBN, 'lt_')) {
    fwrite(STDERR, "refusing to run against '$DBN' (not a test database)\n");
    exit(2);
}

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? "  [$why]" : '') . "\n"; }
}
$http = static function (string $url): array {
    $ch = curl_init(rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8893'), '/') . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};

$sid = 0; $aid = 0;
try {
    // ---- the code on the QR ----
    $c = AgreementVerify::code('stf-000123');
    check('code = REF.12 hex, ref upper-cased', preg_match('/^STF-000123\.[a-f0-9]{12}$/', $c) === 1, $c);
    check('a genuine code parses back', AgreementVerify::parse($c) === 'STF-000123' && AgreementVerify::parse(strtoupper($c)) === 'STF-000123');
    check('a changed number is refused', AgreementVerify::parse('STF-000124' . substr($c, 10)) === null);
    check('a made-up signature / rubbish is refused', AgreementVerify::parse('STF-000123.000000000000') === null && AgreementVerify::parse("x'<") === null && AgreementVerify::parse('') === null);
    check('an ID-card code never opens an agreement, nor the other way', AgreementVerify::parse(IdCard::code('STF-000123')) === null && IdCard::parse($c) === null);
    check('verify URL carries the code', str_ends_with(AgreementVerify::url('STF-000123'), '/verify-agreement.php?c=' . rawurlencode($c)));

    // ---- a staff agreement through its life ----
    Database::run("DELETE FROM staff_applications WHERE phone = '9800099911'");
    $sid = (int) Database::insert('staff_applications', [
        'full_name' => 'Verify Test Conductor', 'phone' => '9800099911', 'phone_country' => 'NP', 'role_applied' => 'conductor',
        'id_type' => 'citizenship', 'id_number' => 'VER-1', 'district' => 'Banke', 'status' => 'pending', 'agreement_version' => '1.1',
    ]);
    $ref = StaffApps::refFor($sid);
    $s = AgreementVerify::standing($ref);
    check('pending staff = received, not approved', $s !== null && $s['state'] === 'received' && $s['approved'] === '' && $s['kind'] === 'staff');
    check('… document names the version', $s !== null && $s['doc'] === 'Staff agreement v1.1');
    check('… never carries the ID number', $s !== null && !str_contains((string) json_encode($s), 'VER-1'));
    Database::run("UPDATE staff_applications SET status = 'approved', approved_at = NOW() WHERE id = :i", ['i' => $sid]);
    $s = AgreementVerify::standing($ref);
    check('approved staff = in force, with the approval time', $s !== null && $s['state'] === 'in_force' && $s['approved'] !== '');
    foreach (['suspended' => 'suspended', 'exited' => 'ended', 'rejected' => 'refused'] as $st => $want) {
        Database::run('UPDATE staff_applications SET status = :s WHERE id = :i', ['s' => $st, 'i' => $sid]);
        $s = AgreementVerify::standing($ref);
        check("staff $st = $want", $s !== null && $s['state'] === $want);
    }
    check('an unknown number has no standing', AgreementVerify::standing('STF-999999999') === null);

    // ---- an agent agreement ----
    $aid = (int) Database::insert('agent_applications', [
        'type' => 'ind', 'lang' => 'ne', 'name' => 'Verify Test Agent', 'phone' => '9812399911', 'area' => 'Nepalgunj',
        'fields_json' => '{"w1_name":"Hari W","g1_name":"Gita G"}', 'status' => 'new',
    ]);
    $aref = AgentApplications::refFor($aid);
    Database::run('UPDATE agent_applications SET ref = :r WHERE id = :i', ['r' => $aref, 'i' => $aid]);
    $a = AgreementVerify::standing($aref);
    check('new agent = received', $a !== null && $a['state'] === 'received' && $a['kind'] === 'agent');
    Database::run("UPDATE agent_applications SET status = 'approved', reviewed_at = NOW(), kyc_status = 'verified' WHERE id = :i", ['i' => $aid]);
    $a = AgreementVerify::standing($aref);
    check('approved agent = in force, KYC checked', $a !== null && $a['state'] === 'in_force' && $a['kyc'] === true && $a['approved'] !== '');
    Database::run("UPDATE agent_applications SET status = 'rejected' WHERE id = :i", ['i' => $aid]);
    check('rejected agent = refused', (AgreementVerify::standing($aref)['state'] ?? '') === 'refused');
    check('witness + guarantor fields are kept by the agent form', in_array('w1_name', AgentApplications::FIELDS, true) && in_array('g1_addr', AgentApplications::FIELDS, true) && in_array('g3_name', AgentApplications::FIELDS, true));

    // ---- staff form: guarantor (ref2) + two witnesses (ref3, ref4) ----
    $v = StaffApps::validate([
        'full_name' => 'Ram Test', 'phone' => '9800099912', 'cc' => '977',
        'ref2_name' => 'Gita Guarantor', 'ref2_phone' => '9800011111', 'ref2_relation' => 'guarantor',
        'ref3_name' => 'Wit One', 'ref3_phone' => '9800022222', 'ref3_relation' => 'witness',
        'ref4_name' => 'Wit Two', 'ref4_phone' => '9800033333', 'ref4_relation' => 'witness',
        'ref1_name' => '', 'ref1_phone' => '',
    ], []);
    $refs = json_decode((string) ($v['clean']['references_json'] ?? ''), true);
    check('staff form keeps a guarantor and two witnesses', is_array($refs) && count($refs) === 3
        && $refs[0]['relation'] === 'guarantor' && $refs[1]['relation'] === 'witness' && $refs[2]['name'] === 'Wit Two', (string) json_encode($refs));
    $v = StaffApps::validate(['ref4_name' => 'No Phone'], []);
    check('a witness without a phone is refused', isset($v['errors']['ref4_phone']));

    // ---- staff agreement v1.1 ----
    $secs = AgreementPrint::sections('staff', 'en');
    $nos  = array_values(array_filter(array_column($secs, 'no'), static fn($n) => $n !== ''));   // the intro has no number
    check('staff agreement has rules 1-19', $nos === array_map('strval', range(1, 19)), implode(',', $nos));
    $all = strtolower((string) json_encode($secs));
    foreach (['probation', 'liability', 'secret', 'dismissal', 'thumbprint', 'labour law'] as $w) {
        check("staff agreement covers: $w", str_contains($all, $w));
    }
    $ne = AgreementPrint::sections('staff', 'ne');
    check('every new rule has its Nepali text', count($ne) === count($secs) && !str_contains(strtolower((string) json_encode(array_slice($ne, -5), JSON_UNESCAPED_UNICODE)), 'probation'));
    check('version constant is 1.1', StaffApps::AGREEMENT_VERSION === '1.1');

    // ---- staff agreement in Hindi + Gujarati (23 Oct 2026) ----
    check('staff agreement languages: ne, hi, en, gu', AgreementPrint::LANGS['staff'] === ['ne', 'hi', 'en', 'gu']);
    $en = array_merge(...array_map(static fn($x) => $x['points'], $secs));
    foreach (['hi' => '/[\x{0900}-\x{097F}]/u', 'gu' => '/[\x{0A80}-\x{0AFF}]/u'] as $l => $script) {
        $tx  = AgreementPrint::sections('staff', $l);
        $pts = array_merge(...array_map(static fn($x) => $x['points'], $tx));
        $untranslated = array_filter($pts, static fn($pt) => preg_match($script, $pt['html']) !== 1);
        check("staff $l: same rules and numbers as English", count($tx) === count($secs) && array_column($pts, 'num') === array_column($en, 'num'));
        check("staff $l: every clause and title translated", $untranslated === [] && array_filter($tx, static fn($x) => preg_match($script, $x['title']) !== 1) === [],
            implode(',', array_column($untranslated, 'num')));
    }
    $i18n  = require INCLUDE_PATH . '/staffagreement-i18n.php';
    $sizes = static fn(array $l): array => array_map(static fn($x) => count($x[1]), $l);
    check('hi and gu files have the same clause counts', $sizes($i18n['hi']) === $sizes($i18n['gu']));
    $fi = (string) file_get_contents(dirname(__DIR__) . '/assets/js/form-i18n.js');
    check('apply form loads form-i18n.js and has the language bar', str_contains((string) file_get_contents(dirname(__DIR__) . '/agreements/apply.html'), 'form-i18n.js?v=')
        && str_contains((string) file_get_contents(dirname(__DIR__) . '/agreements/apply.html'), 'data-flang="gu"'));
    check('form dictionary covers the new guarantor / witness card', str_contains($fi, "'जमानीकर्ता र साक्षी'") && str_contains($fi, "'साक्षी'"));

    // ---- the public page (HTTP, optional) ----
    [$code, $body] = $http('/verify-agreement.php');
    if ($code === 0) {
        echo "  (skipped HTTP checks: no server at SHG_TEST_BASE)\n";
    } else {
        Database::run("UPDATE staff_applications SET status = 'approved' WHERE id = :i", ['i' => $sid]);
        [$code, $body] = $http('/verify-agreement.php?c=' . rawurlencode(AgreementVerify::code($ref)));
        check('HTTP: genuine + in force', $code === 200 && str_contains($body, 'in force') && str_contains($body, $ref) && !str_contains($body, 'VER-1'));
        check('HTTP: the mobile is masked', !str_contains($body, '9800099911') && str_contains($body, '•••••'));
        [$code, $body] = $http('/verify-agreement.php?c=' . rawurlencode($ref . '.000000000000'));
        check('HTTP: a forged code says NOT GENUINE', str_contains($body, 'NOT GENUINE'));
        Database::run("UPDATE staff_applications SET status = 'pending' WHERE id = :i", ['i' => $sid]);
        [$code, $body] = $http('/verify-agreement.php?c=' . rawurlencode(AgreementVerify::code($ref)));
        check('HTTP: pending says waiting for office approval', str_contains($body, 'waiting for office approval'));
    }
} finally {
    if ($sid > 0) { Database::run('DELETE FROM staff_applications WHERE id = :i', ['i' => $sid]); }
    if ($aid > 0) { Database::run('DELETE FROM agent_applications WHERE id = :i', ['i' => $aid]); }
    Database::run("DELETE FROM rate_limits WHERE bucket = 'agreement_verify'");
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
