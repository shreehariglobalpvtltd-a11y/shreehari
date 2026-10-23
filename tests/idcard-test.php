<?php
/**
 * tests/idcard-test.php — 22 Oct 2026, owner: team portal ID cards for
 * staff, agents and Pvt. Ltd. partners, with a QR to /verify-id.php.
 *
 *   php -c .claude/php-dev.ini tests/idcard-test.php
 *
 * Runs against a TEST database only. Writes one staff application and one
 * partner application, removes both at the end. Exit 1 on any failure.
 *
 * 22 Oct 2026 (review, #13/#19/#14): small PNGs that declare huge
 * dimensions are refused by every form and never opened by a card (a pre-fix
 * row draws initials, no fatal); the back of the card says how to check it.
 * The verify-page checks go over HTTP to SHG_TEST_BASE (default
 * http://localhost:8893) and are skipped when no server answers there.
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

/** A 1-bit grey PNG of $w x $h px (all black): a few KB of file, $w * $h * 4 bytes once GD opens it. */
function bombPng(int $w, int $h): string
{
    $chunk = static fn(string $t, string $d): string => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $z = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => 9]);
    $row = str_repeat("\0", 1 + (int) ceil($w / 8));
    $data = '';
    for ($i = 0; $i < $h; $i++) {
        $data .= deflate_add($z, $row, ZLIB_NO_FLUSH);
    }
    $data .= deflate_add($z, '', ZLIB_FINISH);
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 1, 0, 0, 0, 0)) . $chunk('IDAT', $data) . $chunk('IEND', '');
}
function jpegOf(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 120, 40));
    ob_start(); imagejpeg($im, null, 80); imagedestroy($im);
    return (string) ob_get_clean();
}
$http = static function (string $url): array {
    $ch = curl_init(rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8893'), '/') . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
};

$sid = 0; $aid = 0; $bid = 0; $bsid = 0; $bdir = '';
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

    // ---- #13 / #19: an image that declares huge dimensions (a decompression bomb) ----
    $bomb = bombPng(20000, 20000);
    $bombUri = 'data:image/png;base64,' . base64_encode($bomb);
    check('the bomb is a small file (' . strlen($bomb) . ' bytes) declaring 20000 x 20000', strlen($bomb) < 60000 && getimagesizefromstring($bomb)[0] === 20000);
    // 22 Oct 2026 (re-attack): the rule now also counts decode memory (~8 bytes a PNG pixel),
    // so under a 128M limit a 16 MP PNG no longer fits even though its pixel count does.
    check('imageFits: 2000 x 2000 yes; 6001 px a side, 4001 x 4001 (16 MP+), 6000 x 2666 PNG (memory) and rubbish no', AgentApplications::imageFits(bombPng(2000, 2000))
        && !AgentApplications::imageFits(bombPng(6001, 10)) && !AgentApplications::imageFits(bombPng(4001, 4001)) && !AgentApplications::imageFits('not an image')
        && (ini_get('memory_limit') === '-1' || !AgentApplications::imageFits(bombPng(6000, 2666))));
    $c = AgentApplications::cleanPhoto($bombUri);
    check('cleanPhoto refuses it (photo, ID photo, partner logo, office card edit)', $c['photo'] === null && $c['note'] === 'too_big');
    check('cleanSignature refuses it (apply form, portal sign box)', AgentApplications::cleanSignature($bombUri) === null);
    $norm = 'data:image/jpeg;base64,' . base64_encode(jpegOf(600, 750));
    check('a normal 600 px photo still passes cleanPhoto', AgentApplications::cleanPhoto($norm)['photo'] === $norm);
    $e = '';
    try { AgentApplications::shrinkPhoto(bombPng(13000, 13000)); } catch (AgreementError $x) { $e = $x->getMessage(); }
    check('the office upload (shrinkPhoto) refuses over 50 MP before opening it', str_contains($e, 'too large'));
    check('… and still shrinks a camera photo (3264 x 2448) to 640 px', getimagesizefromstring((string) base64_decode(substr(AgentApplications::shrinkPhoto(jpegOf(3264, 2448)), 23)))[0] === 640);
    $r = StaffApps::checkBytes($bomb, 'photo');
    check('the staff form refuses it as a photo, a document and a signature', isset($r['error']) && str_contains((string) $r['error'], 'too many pixels')
        && isset(StaffApps::checkBytes($bomb, 'doc')['error']) && isset(StaffApps::checkBytes($bomb, 'png')['error']));
    $ok = StaffApps::checkBytes(jpegOf(900, 1200), 'photo');
    check('… and still takes a normal 900 px photo', ($ok['mime'] ?? '') === 'image/jpeg');
    $e = '';
    try { StaffApps::saveFile(999999, 'photo', $bomb, 'image/png'); } catch (StaffAppError $x) { $e = $x->getMessage(); }
    check('saveFile never stores it (form, staff sign box, portal sign)', str_contains($e, 'too many pixels') && !is_dir(UPLOAD_PATH . '/staff/999999'));
    check('IdCard::thumb gives null (the page shows initials) instead of a fatal', IdCard::thumb($bomb, 260) === null && IdCard::thumb(jpegOf(300, 300)) !== null);

    // a partner row stored BEFORE the fix: bomb logo, photo and signature
    $bid = (int) Database::insert('agent_applications', [
        'type' => 'org', 'lang' => 'ne', 'name' => 'Idcard Bomb Travels Pvt. Ltd.', 'phone' => '9812399902', 'phone_raw' => '+977 9812399902',
        'fields_json' => '{}', 'status' => 'approved', 'kyc_status' => 'verified', 'photo' => $bombUri, 'signature' => $bombUri,
    ] + (AgentApplications::logoReady() ? ['logo' => $bombUri] : []));
    $bref = AgentApplications::refFor($bid);
    Database::run('UPDATE agent_applications SET ref = :r WHERE id = :i', ['r' => $bref, 'i' => $bid]);
    $q = IdCard::person($bref);
    $sheet = $q !== null ? @getimagesizefromstring(IdCard::png($q, 'sheet')) : false;
    check('a pre-fix bomb row still draws its card (initials, no signature), no fatal', is_array($sheet) && $sheet[0] === IdCard::W * 2 + 40);
    [$hc, $page] = $http('/verify-id.php?c=' . rawurlencode(IdCard::code($bref)));
    if ($hc === 0) {
        echo "  SKIP  HTTP verify-id.php (no server)\n";
    } else {
        check('HTTP: its verify page answers "Genuine" with the initials', $hc === 200 && str_contains($page, 'Genuine') && str_contains($page, 'class="ini"'), (string) $hc);
    }

    // a staff card whose stored photo + signature files are bombs (written past the form, as before the fix)
    $bsid = (int) Database::insert('staff_applications', [
        'full_name' => 'Idcard Bomb Staff', 'phone' => '9800099902', 'phone_country' => 'NP', 'role_applied' => 'driver',
        'id_type' => 'citizenship', 'id_number' => 'IDC-2', 'status' => 'approved',
    ]);
    $bdir = UPLOAD_PATH . '/staff/' . $bsid;
    @mkdir($bdir, 0750, true);
    $name = bin2hex(random_bytes(12)) . '.png';
    file_put_contents($bdir . '/' . $name, $bomb);
    Database::run('UPDATE staff_applications SET photo_path = :p, signature_path = :p2 WHERE id = :i',
        ['p' => 'staff/' . $bsid . '/' . $name, 'p2' => 'staff/' . $bsid . '/' . $name, 'i' => $bsid]);
    $s = IdCard::person(StaffApps::refFor($bsid));
    $front = $s !== null ? @getimagesizefromstring(IdCard::png($s, 'front')) : false;
    check('… a staff card too (the /team/ home thumb is null as well)', is_array($front) && $front[0] === IdCard::W && IdCard::thumb($s['photo'] ?? null, 160) === null);

    // ---- #14: the back says how a card can really be checked ----
    $src = (string) file_get_contents(INCLUDE_PATH . '/idcard.php');
    check('the back no longer offers "or open verify-id.php" (the page has no box for a number)', !str_contains($src, "'Check it: scan the QR on the front, or open'"));
    check('… it names the address the QR must open, and the office phone for a card number', str_contains($src, 'It must open only')
        && str_contains($src, "'No QR reader? Call the office on ' . \$co['phone'] . ' with the card No.'"));
    $back = $p !== null ? IdCard::png($p, 'back') : '';
    check('… and the back still draws (638 x 1011)', $back !== '' && (getimagesizefromstring($back)[1] ?? 0) === IdCard::H);
    [$hc, $page] = $http('/verify-id.php');
    if ($hc !== 0) {
        // 23 Oct 2026: no QR reader -> card number + last 4 digits of the mobile (includes/idverify.php), or call the office
        check('HTTP: the bare verify page says the same (address + typed check + call the office)', $hc === 200 && str_contains($page, 'No QR reader? Type the card number')
            && str_contains($page, 'name="tail"') && str_contains($page, 'call the office below')
            && str_contains($page, '/verify-id.php</b>'), (string) $hc);
    }
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($sid > 0) { Database::run('DELETE FROM staff_applications WHERE id = :i', ['i' => $sid]); }
    if ($aid > 0) { Database::run('DELETE FROM agent_applications WHERE id = :i', ['i' => $aid]); }
    if ($bid > 0) { Database::run('DELETE FROM agent_applications WHERE id = :i', ['i' => $bid]); }
    if ($bsid > 0) { Database::run('DELETE FROM staff_applications WHERE id = :i', ['i' => $bsid]); }
    if ($bdir !== '' && is_dir($bdir)) {
        array_map('unlink', glob($bdir . '/*') ?: []);
        @rmdir($bdir);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket = 'idcard_verify'");
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
