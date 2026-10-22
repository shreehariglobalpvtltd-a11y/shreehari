<?php
/**
 * tests/idcard-test.php — 22 Oct 2026, owner: team portal ID cards for
 * staff, agents and Pvt. Ltd. partners, with a QR to /verify-id.php.
 *
 *   php -c .claude/php-dev.ini tests/idcard-test.php
 *
 * Runs against a TEST database only. Writes one staff application and one
 * partner application, removes both at the end. Exit 1 on any failure.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/idcard.php';

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

$sid = 0; $aid = 0;
try {
    // ---- the code on the QR ----
    $c = IdCard::code('stf-000123');
    check('code = REF.12 hex, ref upper-cased', preg_match('/^STF-000123\.[a-f0-9]{12}$/', $c) === 1, $c);
    check('a genuine code parses back to its ref', IdCard::parse($c) === 'STF-000123');
    check('upper-case hex still parses (QR readers)', IdCard::parse(strtoupper($c)) === 'STF-000123');
    check('a changed number is refused', IdCard::parse('STF-000124' . substr($c, 10)) === null);
    check('a made-up signature is refused', IdCard::parse('STF-000123.000000000000') === null);
    check('other prefixes are refused', IdCard::parse('ABC-000123.' . substr($c, -12)) === null);
    check('rubbish is refused', IdCard::parse("STF-1.abc'\"<") === null && IdCard::parse('') === null);
    check('verify URL carries the code', str_ends_with(IdCard::verifyUrl('STF-000123'), '/verify-id.php?c=' . rawurlencode($c)));

    // ---- a staff member ----
    Database::run("DELETE FROM staff_applications WHERE phone = '9800099901'");
    $sid = (int) Database::insert('staff_applications', [
        'full_name' => 'Idcard Test Driver', 'phone' => '9800099901', 'phone_country' => 'NP', 'role_applied' => 'driver',
        'id_type' => 'citizenship', 'id_number' => 'IDC-1', 'district' => 'Banke', 'status' => 'pending',
    ]);
    $ref = StaffApps::refFor($sid);
    check('no card while the application is pending', IdCard::person($ref) === null);
    Database::run("UPDATE staff_applications SET status = 'approved', approved_at = NOW() WHERE id = :i", ['i' => $sid]);
    if (StaffApps::locationReady()) {
        Database::run("UPDATE staff_applications SET base_location = 'Nepalgunj' WHERE id = :i", ['i' => $sid]);
    }
    $p = IdCard::person($ref);
    check('approved staff has a card', $p !== null && $p['kind'] === 'staff' && $p['valid'] === true);
    check('… with name, job in English + Nepali, office, mobile', $p !== null && $p['name'] === 'Idcard Test Driver'
        && $p['roleEn'] === 'Driver' && $p['roleNe'] !== '' && $p['phone'] === '+977 9800099901'
        && $p['place'] === (StaffApps::locationReady() ? 'Nepalgunj' : 'Banke'), json_encode($p === null ? null : array_diff_key($p, ['photo' => 1, 'sig' => 1])));
    $png = $p !== null ? IdCard::png($p, 'front') : '';
    $info = $png !== '' ? getimagesizefromstring($png) : false;
    check('front PNG is 638 x 1011', is_array($info) && $info[0] === IdCard::W && $info[1] === IdCard::H && $info['mime'] === 'image/png');
    $sheet = $p !== null ? getimagesizefromstring(IdCard::png($p, 'sheet')) : false;
    check('sheet PNG holds both sides', is_array($sheet) && $sheet[0] === IdCard::W * 2 + 40);
    Database::run("UPDATE staff_applications SET status = 'suspended' WHERE id = :i", ['i' => $sid]);
    $p = IdCard::person($ref);
    check('a suspended person still has a card, marked not valid', $p !== null && $p['valid'] === false && $p['standing'] === 'Suspended');
    Database::run("UPDATE staff_applications SET status = 'rejected' WHERE id = :i", ['i' => $sid]);
    check('a rejected application has no card', IdCard::person($ref) === null);

    // ---- a Pvt. Ltd. partner ----
    $im = imagecreatetruecolor(90, 40); imagefill($im, 0, 0, imagecolorallocate($im, 10, 60, 180));
    ob_start(); imagepng($im); $logo = 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
    $aid = (int) Database::insert('agent_applications', [
        'type' => 'org', 'lang' => 'ne', 'name' => 'Idcard Test Travels Pvt. Ltd.', 'phone' => '9812399901', 'phone_raw' => '+977 9812399901',
        'area' => 'Nepalgunj', 'fields_json' => '{"p_rep":"Ram Idcard"}', 'status' => 'approved', 'kyc_status' => 'verified',
    ] + (AgentApplications::logoReady() ? ['logo' => $logo] : []));
    $aref = AgentApplications::refFor($aid);
    Database::run('UPDATE agent_applications SET ref = :r WHERE id = :i', ['r' => $aref, 'i' => $aid]);
    $q = IdCard::person($aref);
    check('approved partner has a card', $q !== null && $q['kind'] === 'partner' && $q['roleEn'] === 'Pvt. Ltd. Partner' && $q['valid']);
    check('… the contact person from the form', $q !== null && $q['contact'] === 'Ram Idcard');
    check('… the logo is the picture', !AgentApplications::logoReady() || ($q !== null && $q['photo'] !== null && str_starts_with((string) $q['photo'], "\x89PNG")));
    check('the verify thumb is a small JPEG data URI', $q === null || $q['photo'] === null || str_starts_with((string) IdCard::thumb($q['photo']), 'data:image/jpeg;base64,'));
    Database::run("UPDATE agent_applications SET status = 'rejected' WHERE id = :i", ['i' => $aid]);
    check('a rejected partner has no card', IdCard::person($aref) === null);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($sid > 0) { Database::run('DELETE FROM staff_applications WHERE id = :i', ['i' => $sid]); }
    if ($aid > 0) { Database::run('DELETE FROM agent_applications WHERE id = :i', ['i' => $aid]); }
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
