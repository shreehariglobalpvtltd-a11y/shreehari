<?php
/**
 * =====================================================================
 *  agent-pro-test.php — the Agent Pro engine, against the TEST database.
 *
 *    - members: ID numbers (agent code / SHG-S-xxxx), card token, status
 *      (valid · suspended · expired · revoked · inactive), the visiting
 *      card number + wa.me link, encryption at rest, Aadhaar masking
 *    - the join form: drafts, validation, WhatsApp OTP + "JOIN 123456",
 *      submit (an inactive account, exactly like agent-signup.php), ONE-TAP
 *      approve (code, KYC verified, selfie → photo, payout details, card),
 *      reject (account removed, number free again), staff invites
 *    - money: withdrawals paid in one step, payments-in confirmed / declined
 *    - cards: all four sides as PNG and SVG, a long name shrinks to fit,
 *      the QR codes decode (zbarimg when installed), print sheets
 *    - document readers: Aadhaar secure + XML QR, passport MRZ, Verhoeff,
 *      BS → AD, OCR parsers per card, a real Tesseract pass when installed
 *    - passkeys: a simulated phone registers and signs in (ES256); replay,
 *      wrong site and wrong signature are refused
 *
 *  Everything it creates is deleted again.
 *
 *    php tests/agent-pro-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-card.php';
require_once INCLUDE_PATH . '/agentpro-docs.php';
require_once INCLUDE_PATH . '/agentpro-passkey.php';
require_once INCLUDE_PATH . '/notify.php';

// The site's handler answers HTTP; a test wants the real error and a failing exit code.
set_exception_handler(static function (Throwable $e): void {
    echo "\n  \033[31mUNCAUGHT\033[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function throws(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage() !== '' ? $e->getMessage() : get_class($e); }
}
function section(string $s): void { echo "-- $s --\n"; }

if (!AgentPro::available()) {
    check('ap_* tables exist (apply database/upgrade-2026-10-agent-pro.sql)', false);
    echo "\nPASSED: $PASS   FAILED: $FAIL\n";
    exit(1);
}

/* ---- a clean slate, and no real WhatsApp sends ---- */
$driverWas = Settings::getString('whatsapp_driver', 'click_to_chat');
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'whatsapp', false);
$cleanup = static function (): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'aptest%' OR phone LIKE '70000001%' OR phone LIKE '70000002%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'agent_payout_requests' => 'agent_admin_id', 'agent_deposit_txns' => 'agent_admin_id',
                  'ap_deposit_requests' => 'admin_id', 'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id',
                  'ap_passkeys' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    Database::delete('ap_applications', "phone LIKE '70000001%' OR phone LIKE '70000002%' OR full_name LIKE 'APTest%'");
    Database::delete('ap_invites', "note = 'aptest'");
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'apjoin%' OR bucket LIKE 'otp_%' OR bucket LIKE 'ap\\_%'");
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'apjoin:7000000%' OR identifier LIKE 'aplink:%'");
};
$cleanup();
Settings::flush();

/** A small portrait JPEG, drawn here (no real person). */
function ap_test_jpeg(string $path, int $w = 600, int $h = 800): string {
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 210, 220, 236));
    imagefilledellipse($im, (int) ($w / 2), (int) ($h * .38), (int) ($w * .42), (int) ($h * .38), imagecolorallocate($im, 196, 140, 104));
    imagefilledellipse($im, (int) ($w / 2), (int) ($h * 1.05), (int) ($w * 1.0), (int) ($h * .7), imagecolorallocate($im, 28, 47, 107));
    ensureDir(dirname($path));
    imagejpeg($im, $path, 88);
    return $path;
}

/* =================================================================== */
section('helpers: encryption, masking, phone numbers');
$box = AgentPro::encrypt('50100234567890');
check('encrypt() hides the number', $box !== '' && !str_contains($box, '50100234567890') && str_starts_with($box, 'v1:'));
check('decrypt() brings it back', AgentPro::decrypt($box) === '50100234567890');
check('a tampered box reads as empty', AgentPro::decrypt(substr($box, 0, -4) . 'AAAA') === '');
check('Aadhaar is masked to the last four (UIDAI rule)', AgentPro::maskId('aadhaar', '2345 6789 0124') === 'XXXX XXXX 0124');
check('other IDs keep only the last four in clear', AgentPro::maskId('pan', 'ABCPK1234E') === '••••••1234E' || str_ends_with(AgentPro::maskId('pan', 'ABCPK1234E'), '234E'));
check('a bare Indian mobile gets +91', AgentPro::intlDigits('98765 43210') === '919876543210');
check('a Nepali member\'s bare mobile gets +977', AgentPro::intlDigits('9812345678', '977') === '9779812345678');
check('printed the Indian way', AgentPro::displayPhone('919876543210') === '+91 98765 43210');
check('printed the Nepali way', AgentPro::displayPhone('9779812345678') === '+977 981-2345678');
check('IFSC → bank name', AgentPro::bankFromIfsc('HDFC0001234') === 'HDFC Bank');
check('mojibake "Â·" is repaired', AgentPro::fixMojibake("Mehsana Â· Surat") === 'Mehsana · Surat');
check('good text is left alone', AgentPro::fixMojibake('नमस्ते · Surat') === 'नमस्ते · Surat');

/* =================================================================== */
section('members: agents and staff, numbers, card status');
$agentId = Database::insert('admins', ['username' => 'aptest_agent', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'full_name' => 'APTest Ramesh Kumar', 'phone' => '7000000101', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0]);
AgentWallet::saveProfile($agentId, ['agent_kind' => 'person', 'counter_name' => 'Mehsana', 'whatsapp' => '917000000101', 'country_code' => '91']);
AgentWallet::setAgentCode($agentId, AgentWallet::nextFreeAgentCode('person'));
$m = AgentPro::member($agentId);
check('an agent\'s ID number IS their agent code', $m['idNo'] === AgentWallet::agentCodeLabel($agentId) && str_starts_with($m['idNo'], 'SHG-'), $m['idNo']);
check('a fresh card: token, issue date, 2-year validity', strlen($m['cardToken']) === 24 && $m['validUntil'] === date('Y-m-d', strtotime('+2 years')));
check('status valid', $m['status'] === 'valid');
check('default designation for a person agent', $m['designation'] === 'Ticketing Agent');
check('visiting card number = their WhatsApp', $m['wa'] === '917000000101' && $m['waDisplay'] === '+91 70000 00101');
check('the QR link opens a chat with a greeting', str_starts_with($m['waLink'], 'https://wa.me/917000000101?text='));
$staffId = Database::insert('admins', ['username' => 'aptest_staff', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'full_name' => 'APTest Anita Sharma', 'phone' => '7000000102', 'role' => 'counter', 'is_active' => 1, 'must_change_pw' => 0]);
$s = AgentPro::member($staffId);
check('staff get an SHG-S number', preg_match('/^SHG-S-\d{4}$/', $s['idNo']) === 1, $s['idNo']);
check('and the counter designation', $s['designation'] === 'Counter Executive');

AgentPro::saveCard($agentId, ['vc_whatsapp' => '+977 9812345678', 'designation' => 'Senior Agent', 'blood_group' => 'o+', 'vc_theme' => 'gold']);
$m = AgentPro::member($agentId);
check('the card prints whatever WhatsApp number is set (+977 kept)', $m['wa'] === '9779812345678' && $m['waDisplay'] === '+977 981-2345678');
check('designation, blood group, theme saved', $m['designation'] === 'Senior Agent' && $m['bloodGroup'] === 'O+' && $m['theme'] === 'gold');
check('a nonsense phone is refused', str_contains(throws(fn() => AgentPro::saveCard($agentId, ['vc_whatsapp' => '12ab'])), 'valid phone'));
check('the code changes → the card number changes with it', (function () use ($agentId): bool {
    $old = AgentWallet::agentCodeFor($agentId);
    $new = AgentWallet::nextFreeAgentCode('person');
    AgentWallet::setAgentCode($agentId, $new);
    $ok = AgentPro::member($agentId)['idNo'] === 'SHG-' . str_pad((string) $new, 4, '0', STR_PAD_LEFT);
    AgentWallet::setAgentCode($agentId, $old);
    return $ok;
})());

$token = $m['cardToken'];
check('the verify page finds the member by token', (AgentPro::memberByToken($token)['id'] ?? 0) === $agentId);
AgentPro::setCardStatus($agentId, 'suspended');
check('suspended reads as suspended', AgentPro::memberByToken($token)['status'] === 'suspended');
AgentPro::setCardStatus($agentId, 'active');
AgentPro::reissueCard($agentId);
check('re-issue: the old printed card no longer resolves', AgentPro::memberByToken($token) === null);
check('…and a new token exists', AgentPro::member($agentId)['cardToken'] !== $token);
Database::update('ap_members', ['card_valid_until' => date('Y-m-d', strtotime('-1 day'))], 'admin_id = :a', ['a' => $agentId]);
check('past validity reads expired', AgentPro::member($agentId)['status'] === 'expired');
AgentPro::saveCard($agentId, ['card_valid_until' => date('Y-m-d', strtotime('+1 year'))]);
Database::update('admins', ['is_active' => 0], 'id = :i', ['i' => $staffId]);
check('a deactivated login reads inactive', AgentPro::member($staffId)['status'] === 'inactive');
Database::update('admins', ['is_active' => 1], 'id = :i', ['i' => $staffId]);
check('bad token shapes are refused cheaply', AgentPro::memberByToken('../../etc/passwd') === null && AgentPro::memberByToken('') === null);

AgentPro::saveBank($agentId, ['upi_id' => 'ramesh@okaxis', 'bank_account' => '50100234567890', 'bank_ifsc' => 'hdfc0001234', 'bank_holder' => 'Ramesh']);
$row = Database::fetch('SELECT * FROM ap_members WHERE admin_id = :a', ['a' => $agentId]);
check('the account number is stored encrypted', !str_contains((string) $row['bank_account_enc'], '50100234567890') && AgentPro::bankAccount($agentId) === '50100234567890');
check('only the last four in clear; IFSC upper-cased; bank name filled', $row['bank_account_last4'] === '7890' && $row['bank_ifsc'] === 'HDFC0001234' && $row['bank_name'] === 'HDFC Bank');
check('the office payout screen sees the UPI', AgentWallet::profile($agentId)['payout_method'] === 'upi' && AgentWallet::profile($agentId)['payout_account'] === 'ramesh@okaxis');
check('a bad UPI ID is refused', str_contains(throws(fn() => AgentPro::saveBank($agentId, ['upi_id' => 'not an upi'])), 'UPI'));

$photo = ap_test_jpeg(sys_get_temp_dir() . '/aptest-photo.jpg', 900, 1200);
$rel = AgentPro::setPhotoFromFile($agentId, $photo);
$m = AgentPro::member($agentId);
check('photo: square 640 JPEG under uploads/agents/', str_starts_with($rel, 'agents/') && getimagesize(UPLOAD_PATH . '/' . $rel)[0] === 640 && getimagesize(UPLOAD_PATH . '/' . $rel)[1] === 640);
check('the photo version bumps (cards and WhatsApp re-fetch)', $m['photoVer'] >= 2 && str_contains($m['photoUrl'], '?v=' . $m['photoVer']));
$rel2 = AgentPro::setPhotoFromFile($agentId, $photo);
check('a new photo replaces the old file (no orphans)', !is_file(UPLOAD_PATH . '/' . $rel) && is_file(UPLOAD_PATH . '/' . $rel2));

/* =================================================================== */
section('cards: four sides, PNG + SVG, fit, QR, print');
$m = AgentPro::member($agentId);
foreach (ApCard::SIDES as $side) {
    $sc = ApCard::scene($m, $side);
    $png = ApCard::png($sc, 1.0);
    $img = @imagecreatefromstring($png);
    check("$side: a real PNG of the right size", $img !== false && imagesx($img) === (int) round($sc->w) && imagesy($img) === (int) round($sc->h));
    $svg = ApCard::svg($sc);
    $dom = new DOMDocument();
    check("$side: well-formed SVG with true mm size", @$dom->loadXML($svg) === true && str_contains($svg, str_starts_with($side, 'id') ? 'width="54mm"' : 'width="88.9mm"'));
    if ($img !== false) {
        $rgba = imagecolorat($img, 0, 0);
        check("$side: rounded corners are transparent", (($rgba >> 24) & 0x7F) > 100);
    }
}
$long = $m;
$long['name'] = 'Shree Hari International Tours And Travels Private Agency';
foreach (['id-front', 'id-back', 'vc-front', 'vc-back'] as $side) {
    $over = array_filter(ApCard::scene($long, $side)->ops, static fn($o) => $o['t'] === 'text' && ($o['max'] ?? 0) > 0 && $o['w'] > $o['max'] + 0.5);
    check("$side: a very long agency name still fits (wraps / shrinks, nothing overflows)", $over === [], implode(' | ', array_map(static fn($o) => $o['text'] . ' ' . round($o['w']) . '>' . $o['max'], $over)));
}
$nameOps = array_values(array_filter(ApCard::scene($long, 'id-front')->ops, static fn($o) => $o['t'] === 'text' && $o['font'] === 'sansB' && $o['size'] >= 24 && str_contains($long['name'], $o['text'])));
check('…on the ID card it takes two lines', count($nameOps) === 2);
$vcOps = ApCard::scene($m, 'vc-front')->ops;
check('the visiting card prints "WA:" and the number', (bool) array_filter($vcOps, static fn($o) => $o['t'] === 'text' && $o['text'] === 'WA:')
    && (bool) array_filter($vcOps, static fn($o) => $o['t'] === 'text' && $o['text'] === $m['waDisplay']));
$zbar = trim((string) shell_exec('command -v zbarimg 2>/dev/null'));
if ($zbar !== '') {
    // QR only, as a phone's scanner reads it: zbar's barcode decoders trip over the guilloche.
    $read = static function (array $mm, string $side, float $k): string {
        $tmp = sys_get_temp_dir() . '/aptest-card.png';
        file_put_contents($tmp, ApCard::png(ApCard::scene($mm, $side), $k));
        return trim((string) shell_exec('zbarimg -q --raw -Sdisable -Sqrcode.enable ' . escapeshellarg($tmp) . ' 2>/dev/null'));
    };
    $scales = [1.0, 1.3, 1.6, 2.0, 2.5, 3.0];
    $bad = static fn(array $mm, string $side, string $want): array => array_values(array_filter($scales, static fn(float $k): bool => $read($mm, $side, $k) !== $want));
    $b = $bad($m, 'id-back', ApCard::verifyUrl($m));
    check('ID back QR decodes to the verify link at every size', $b === [], $b === [] ? '' : 'fails at ×' . implode(', ×', $b));
    // The office's default greeting, exactly as a new install has it.
    $dflt = $m;
    $dflt['waLink'] = whatsappLink($m['wa'], 'Namaste! SHG card');
    $b = $bad($dflt, 'vc-back', $dflt['waLink']);
    check('visiting card QR (WA mark in it) opens the chat with the greeting, at every size', $b === [], $b === [] ? '' : 'fails at ×' . implode(', ×', $b));
    $wordy = $m;
    $wordy['waLink'] = whatsappLink($m['wa'], 'Namaste! I got your number from your S Hari Global visiting card today.');
    $b = $bad($wordy, 'vc-back', 'https://wa.me/' . $m['wa']);
    check('…a wordy greeting would make the code too dense — the bare number is printed instead', $b === [], $b === [] ? '' : 'fails at ×' . implode(', ×', $b));
    $b = $bad($m, 'vc-front', 'https://wa.me/' . $m['wa']);
    check('the small front QR opens the chat too, at every size', $b === [], $b === [] ? '' : 'fails at ×' . implode(', ×', $b));
} else {
    echo "  (zbarimg not installed — QR decoding not checked)\n";
}
$greedy = $m;
$greedy['waLink'] = whatsappLink($m['wa'], str_repeat('नमस्ते! तपाईंको कार्ड हेरेर सम्पर्क गरेँ। ', 4));
check('a very long (Devanagari) greeting cannot break the card — the QR falls back', ApCard::png(ApCard::scene($greedy, 'vc-back'), 1.0) !== '');
[, $lvl] = ApCard::safeQr([$greedy['waLink'], 'https://wa.me/' . $m['wa']], QrCode::ECC_H);
check('…to the bare wa.me number at full error correction', $lvl === QrCode::ECC_H);
$url = ApCard::signedUrl($agentId, 'id-front', 600);
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
check('signed card link verifies', ApCard::checkSig($agentId, 'id-front', (int) $q['e'], (string) $q['k']));
check('…but not for another side or member', !ApCard::checkSig($agentId, 'vc-front', (int) $q['e'], (string) $q['k']) && !ApCard::checkSig($staffId, 'id-front', (int) $q['e'], (string) $q['k']));
check('…and not after it expires', !ApCard::checkSig($agentId, 'id-front', time() - 5, ApCard::sig($agentId, 'id-front', time() - 5)));
$sheet = ApCard::printHtml([$m, AgentPro::member($staffId)], 'all', 'test');
check('print sheet: ID front + back + VC front + back pages', substr_count($sheet, 'class="sheet"') === 4 && substr_count($sheet, '<svg') === 8);
check('print sheet is true size (mm)', str_contains($sheet, '54mm') && str_contains($sheet, '88.9mm') && str_contains($sheet, '@page{size:A4'));

/* =================================================================== */
section('document readers');
function ap_bytes_to_decimal(string $b): string {
    $d = [0];
    foreach (str_split($b) as $ch) {
        $carry = ord($ch);
        for ($i = count($d) - 1; $i >= 0; $i--) { $v = $d[$i] * 256 + $carry; $d[$i] = $v % 10000000; $carry = intdiv($v, 10000000); }
        while ($carry > 0) { array_unshift($d, $carry % 10000000); $carry = intdiv($carry, 10000000); }
    }
    $s = (string) array_shift($d);
    foreach ($d as $l) { $s .= str_pad((string) $l, 7, '0', STR_PAD_LEFT); }
    return $s;
}
$fields = ['V2', '3', '4321' . '20261029120000123', 'Test Person Kumar', '12-03-1994', 'M', 'S/O Test Father', 'Ahmedabad', 'Near Temple', '12',
           'Navrangpura', '380009', 'Navrangpura PO', 'Gujarat', 'Main Road', 'Ahmedabad City', 'Ahmedabad', '4321'];
$qr = ApDocRead::parseQr(ap_bytes_to_decimal(gzencode(implode("\xFF", $fields) . "\xFF" . str_repeat("\x01", 256))));
check('Aadhaar Secure QR (V2): name, DOB, gender, guardian', ($qr['full_name'] ?? '') === 'Test Person Kumar' && ($qr['dob'] ?? '') === '1994-03-12'
    && ($qr['gender'] ?? '') === 'Male' && ($qr['guardian'] ?? '') === 'Test Father');
check('…address, pincode, state', ($qr['pincode'] ?? '') === '380009' && ($qr['state'] ?? '') === 'Gujarat' && str_contains($qr['address'] ?? '', 'Main Road'));
check('…and only the last four of the number', ($qr['id_number'] ?? '') === 'XXXX XXXX 4321');
$v1 = $fields; array_shift($v1);
check('Aadhaar Secure QR (V1, no version field) too', (ApDocRead::parseQr(ap_bytes_to_decimal(gzencode(implode("\xFF", $v1))))['full_name'] ?? '') === 'Test Person Kumar');
$x = ApDocRead::parseQr('<?xml version="1.0"?><PrintLetterBarcodeData uid="234567890124" name="Test Person" gender="F" yob="1990" co="D/O Test Parent" vtc="Surat" dist="Surat" state="Gujarat" pc="395003" dob="05/06/1990"/>');
check('old Aadhaar XML QR', ($x['full_name'] ?? '') === 'Test Person' && ($x['dob'] ?? '') === '1990-06-05' && ($x['gender'] ?? '') === 'Female' && ($x['id_number'] ?? '') === '2345 6789 0124');
check('a random QR is not mistaken for an ID', ApDocRead::parseQr('https://example.com') === null);
$mrz = ApDocRead::mrz("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C36UTO7408122F1204159ZE184226B<<<<<10");
check('passport MRZ (ICAO 9303 sample) with check digits', ($mrz['full_name'] ?? '') === 'Anna Maria Eriksson' && ($mrz['id_number'] ?? '') === 'L898902C3' && ($mrz['dob'] ?? '') === '1974-08-12');
check('a misread MRZ (bad check digit) is ignored', ApDocRead::mrz("P<UTOERIKSSON<<ANNA<MARIA<<<<<<<<<<<<<<<<<<<\nL898902C37UTO7408122F1204159ZE184226B<<<<<10") === null);
check('Verhoeff: a valid Aadhaar number passes', ApDocRead::verhoeff('234567890124'));
check('Verhoeff: one wrong digit fails', !ApDocRead::verhoeff('234567890123'));
$tick = new ReflectionClass(Ticket::class);
$bsSite = $tick->getConstant('BS_DATA');
$ok = true;
foreach ((array) $bsSite as $y => $rec) {
    $ok = $ok && ApDocRead::bsToAd((int) $y, 1, 1) === $rec['start'];
}
check('BS → AD: Baishakh 1 agrees with the ticket\'s own table for every year it has', $ok && $bsSite !== []);
check('BS 2050-03-12 → 1993-06-26', ApDocRead::bsToAd(2050, 3, 12) === '1993-06-26');
check('BS out of range → null', ApDocRead::bsToAd(1990, 1, 1) === null && ApDocRead::bsToAd(2050, 13, 1) === null);
$aad = ApDocRead::parseText("भारत सरकार\nGovernment of India\nTest Person Kumar\nजन्म तिथि/DOB: 12/03/1994\nपुरुष / MALE\n2345 6789 0124\n", 'aadhaar', 'front');
check('Aadhaar front OCR → name, DOB, gender, number', ($aad['full_name'] ?? '') === 'Test Person Kumar' && ($aad['dob'] ?? '') === '1994-03-12' && ($aad['gender'] ?? '') === 'Male' && ($aad['id_number'] ?? '') === '2345 6789 0124');
$aadB = ApDocRead::parseText("Address: S/O Test Father, 12 Main Road, Navrangpura, Ahmedabad, Gujarat - 380009\n", 'aadhaar', 'back');
check('Aadhaar back OCR → guardian, address, pincode, state', ($aadB['guardian'] ?? '') === 'Test Father' && ($aadB['pincode'] ?? '') === '380009' && ($aadB['state'] ?? '') === 'Gujarat');
check('an OCR number that fails Verhoeff is dropped', !isset(ApDocRead::parseText("Test Person\nDOB: 12/03/1994\n2345 6789 0123", 'aadhaar', 'front')['id_number']));
$cz = ApDocRead::parseText("Citizenship Certificate No.: 27-01-74-05678\nFull Name: RAM BAHADUR THAPA\nSex: Male\nDate of Birth (AD): Year: 1994 Month: JUN Day: 26\nPermanent Address: District: Kaski\n", 'citizenship', 'back');
check('Nepali citizenship (English side)', ($cz['id_number'] ?? '') === '27-01-74-05678' && ($cz['full_name'] ?? '') === 'Ram Bahadur Thapa' && ($cz['dob'] ?? '') === '1994-06-26' && ($cz['district'] ?? '') === 'Kaski');
$czN = ApDocRead::parseText("ना.प्र.नं.: २७-०१-७४-०५६७८\nनाम थर: राम बहादुर थापा\nलिङ्ग: पुरुष\nजन्म मिति: साल २०५० महिना ०३ गते १२\n", 'citizenship', 'front');
check('Nepali citizenship (Nepali side): Devanagari digits, name, BS date → AD', ($czN['id_number'] ?? '') === '27-01-74-05678' && ($czN['full_name_local'] ?? '') === 'राम बहादुर थापा'
    && ($czN['dob'] ?? '') === '1993-06-26' && ($czN['gender'] ?? '') === 'Male');
$mergedBs = ApDocRead::merge([['src' => 'ocr', 'fields' => $czN]]);
check('…and the form is told to double-check a BS-converted date', in_array('dob_from_bs', $mergedBs['warnings'], true));
$pan = ApDocRead::parseText("INCOME TAX DEPARTMENT\nGOVT. OF INDIA\nTEST PERSON KUMAR\nRAJ KUMAR SINGH\n12/03/1994\nPermanent Account Number\nABCPK1234E\n", 'pan', 'front');
check('PAN (old layout): number, holder, father, DOB', ($pan['id_number'] ?? '') === 'ABCPK1234E' && ($pan['full_name'] ?? '') === 'Test Person Kumar' && ($pan['guardian'] ?? '') === 'Raj Kumar Singh' && ($pan['dob'] ?? '') === '1994-03-12');
check('PAN: OCR 0/O mix-ups are put right', (ApDocRead::parseText("Permanent Account Number\nABCPK12B4E\n", 'pan', 'front')['id_number'] ?? '') === 'ABCPK1284E');
check('Voter ID (EPIC)', (ApDocRead::parseText("ELECTION COMMISSION OF INDIA\nABC1234567\nElector's Name : Test Person\nFather's Name : Test Father\n", 'voter', 'front')['id_number'] ?? '') === 'ABC1234567');
$dl = ApDocRead::parseText("Driving Licence\nGJ02 20110012345\nName: Test Person\nS/D/W of : Test Father\nDOB : 12-03-1994\nValid Till : 11-03-2034\n", 'dl', 'front');
check('Driving licence: number, DOB, valid till', ($dl['id_number'] ?? '') === 'GJ02 20110012345' && ($dl['dob'] ?? '') === '1994-03-12' && ($dl['id_expires_on'] ?? '') === '2034-03-11');
$mg = ApDocRead::merge([['src' => 'ocr', 'fields' => ['full_name' => 'Tesl Persn', 'dob' => '1994-03-12']], ['src' => 'qr', 'fields' => ['full_name' => 'Test Person']]]);
check('merge: the QR beats the photo, each field says where it came from', $mg['fields']['full_name'] === 'Test Person' && $mg['sources']['full_name'] === 'qr' && $mg['sources']['dob'] === 'ocr');
if (ApDocRead::tesseractAvailable()) {
    $im = imagecreatetruecolor(1000, 620);
    imagefill($im, 0, 0, imagecolorallocate($im, 246, 244, 238));
    $ink = imagecolorallocate($im, 30, 30, 40);
    $fb = ApCard::fontFile('sansB');
    $fd = ApCard::fontFile('deva');
    imagettftext($im, 20, 0, 40, 60, $ink, $fb, 'SPECIMEN - TEST CARD - NOT AN ID');
    imagettftext($im, 22, 0, 300, 170, $ink, $fb, 'Test Person Kumar');
    imagettftext($im, 20, 0, 300, 230, $ink, $fd, 'जन्म तिथि/DOB: 12/03/1994');
    imagettftext($im, 20, 0, 300, 290, $ink, $fd, 'पुरुष / MALE');
    imagettftext($im, 34, 0, 300, 420, $ink, $fb, '2345 6789 0124');
    $spec = sys_get_temp_dir() . '/aptest-specimen.jpg';
    imagejpeg($im, $spec, 92);
    $r = ApDocRead::read($spec, 'aadhaar', 'front');
    check('real Tesseract pass on a specimen photo', ($r['fields']['full_name'] ?? '') === 'Test Person Kumar' && ($r['fields']['id_number'] ?? '') === '2345 6789 0124'
        && ($r['fields']['dob'] ?? '') === '1994-03-12', json_encode($r['fields']));
} else {
    echo "  (tesseract not installed — real OCR not checked)\n";
}

/* =================================================================== */
section('the join form: draft → WhatsApp → submit → ONE-TAP approve');
$d = AgentPro::newDraft();
check('a draft has a reference and a 32-hex key', preg_match('/^JN-[2-9A-HJ-NP-Z]{5}$/', $d['ref']) === 1 && preg_match('/^[a-f0-9]{32}$/', $d['draft_key']) === 1);
$k = $d['draft_key'];
check('under 16 years old is refused', str_contains(throws(fn() => AgentPro::saveDraft($k, ['dob' => date('Y-m-d', strtotime('-10 years'))])), '16'));
check('an Aadhaar number must have 12 digits', str_contains(throws(fn() => AgentPro::saveDraft($k, ['id_type' => 'aadhaar', 'id_number' => '1234'])), '12 digits'));
AgentPro::saveDraft($k, ['full_name' => 'APTest Bikash Rana', 'agent_kind' => 'person', 'dob' => '1994-03-12', 'gender' => 'Male', 'id_type' => 'aadhaar',
    'id_number' => '2345 6789 0124', 'address' => '12 Main Road', 'city' => 'Vadodara', 'state' => 'Gujarat', 'pincode' => '390001', 'country' => 'IN',
    'upi_id' => 'bikash@ybl', 'bank_account' => '1234567890', 'bank_ifsc' => 'SBIN0001234']);
$d = AgentPro::draft($k);
check('the Aadhaar number is stored masked only (no full number anywhere)', $d['id_number_masked'] === 'XXXX XXXX 0124' && $d['id_number_enc'] === null);
check('the bank account is encrypted on the draft', AgentPro::decrypt((string) $d['bank_account_enc']) === '1234567890' && $d['bank_name'] === 'State Bank of India');
check('submit before verifying WhatsApp is refused', str_contains(throws(fn() => AgentPro::submit($k)), 'WhatsApp verification'));
// WhatsApp OTP: the code goes on WhatsApp; the test plants a known one.
AgentPro::sendJoinOtp($k, '7000000201', 'IN');
Database::run("UPDATE otp_codes SET code_hash = :h WHERE identifier = 'apjoin:7000000201' AND is_used = 0", ['h' => password_hash('482913', PASSWORD_BCRYPT)]);
check('a wrong code is refused', throws(fn() => AgentPro::verifyJoinOtp($k, '000000')) !== '');
AgentPro::verifyJoinOtp($k, '482913');
$d = AgentPro::draft($k);
check('the right code verifies the number', $d['phone'] === '7000000201' && $d['wa_verified_via'] === 'otp' && $d['wa_verified_at'] !== null);
check('submit without the ID photo / selfie is refused, saying what is missing', str_contains(throws(fn() => AgentPro::submit($k)), 'photo of the ID'));
ensureDir(UPLOAD_PATH . '/agents-kyc');
$front = 'agents-kyc/aptest-front-' . bin2hex(random_bytes(4)) . '.jpg';
$selfie = 'agents-kyc/aptest-selfie-' . bin2hex(random_bytes(4)) . '.jpg';
ap_test_jpeg(UPLOAD_PATH . '/' . $front, 1000, 630);
ap_test_jpeg(UPLOAD_PATH . '/' . $selfie);
Database::update('ap_applications', ['doc_front' => $front, 'selfie' => $selfie], 'draft_key = :k', ['k' => $k]);
$app = AgentPro::submit($k);
check('submitted', $app['status'] === 'submitted' && (int) $app['admin_id'] > 0);
$newId = (int) $app['admin_id'];
$acc = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $newId]);
check('…as an INACTIVE agent account (like agent-signup.php)', $acc['role'] === 'agent' && (int) $acc['is_active'] === 0 && $acc['phone'] === '7000000201');
check('…with KYC submitted and the ID photo on file', AgentWallet::profile($newId)['kyc_status'] === 'submitted' && AgentWallet::profile($newId)['kyc_doc_path'] === $front);
check('submitting twice changes nothing', AgentPro::submit($k)['admin_id'] == $newId && (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE phone = :p', ['p' => '7000000201'], 0) === 1);
check('the same mobile cannot apply again', str_contains(throws(fn() => AgentPro::sendJoinOtp(AgentPro::newDraft()['draft_key'], '7000000201')), 'already'));
$r = AgentPro::approve((int) $app['id'], 1);
$acc = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $newId]);
$nm = AgentPro::member($newId);
check('ONE TAP: the login is active', (int) $acc['is_active'] === 1);
check('ONE TAP: an agent code in the person band', $r['code'] !== '' && AgentWallet::agentCodeFor($newId) >= AgentWallet::PERSON_CODE_MIN, $r['code']);
check('ONE TAP: KYC verified, stamped with who and when', AgentWallet::profile($newId)['kyc_status'] === 'verified' && AgentWallet::profile($newId)['kyc_verified_by'] == 1);
check('ONE TAP: the selfie is the profile = ID card photo', $nm['photoUrl'] !== '' && is_file(UPLOAD_PATH . '/' . $nm['photo']));
check('ONE TAP: payout details carried over (encrypted)', $nm['bank']['upi'] === 'bikash@ybl' && AgentPro::bankAccount($newId) === '1234567890');
check('ONE TAP: an ID card exists and is valid', $nm['status'] === 'valid' && strlen($nm['cardToken']) === 24);
check('ONE TAP: DOB and gender on the member', $nm['dob'] === '1994-03-12' && $nm['gender'] === 'Male');
check('approving twice changes nothing', AgentPro::approve((int) $app['id'], 1)['admin_id'] === $newId);
check('a welcome went to WhatsApp (logged, with the ID card image)', (bool) Database::fetch(
    "SELECT id FROM message_logs WHERE to_number LIKE :p AND body LIKE :b ORDER BY id DESC LIMIT 1", ["p" => "%7000000201%", "b" => "%" . $r["code"] . "%"]));

$d2 = AgentPro::newDraft();
AgentPro::saveDraft($d2['draft_key'], ['full_name' => 'APTest Reject Me', 'agent_kind' => 'person', 'id_type' => 'pan', 'city' => 'Surat']);
$rev = AgentPro::reverseCode($d2['draft_key']);
check('reverse verification: a 6-digit code and a wa.me link that sends "JOIN code"', preg_match('/^\d{6}$/', $rev['code']) === 1 && str_contains($rev['link'], 'JOIN%20' . $rev['code']));
check('the code arriving FROM WhatsApp verifies the sender\'s number', (AgentPro::reverseVerified('+917000000202', $rev['code'])['wa_verified_via'] ?? '') === 'reverse');
check('…once (the code is spent)', AgentPro::reverseVerified('+917000000202', $rev['code']) === null);
Database::update('ap_applications', ['doc_front' => $front, 'selfie' => $selfie], 'draft_key = :k', ['k' => $d2['draft_key']]);
$app2 = AgentPro::submit($d2['draft_key']);
check('reject needs a reason for the applicant', str_contains(throws(fn() => AgentPro::reject((int) $app2['id'], 1, '')), 'one line'));
AgentPro::reject((int) $app2['id'], 1, 'The ID photo is blurred — please take it again in good light.');
check('rejected: the inactive account is removed, the number can apply again', !Database::exists('SELECT 1 FROM admins WHERE phone = :p', ['p' => '7000000202'])
    && AgentPro::application((int) $app2['id'])['status'] === 'rejected');
$inv = AgentPro::createInvite('manager', 'Branch Manager, Surat', 1, 7, 'aptest');
check('a staff invite link', str_contains($inv['link'], 'join.php?invite=') && AgentPro::invite($inv['token'])['role'] === 'manager');
$d3 = AgentPro::newDraft($inv['token']);
check('the invite makes a STAFF draft with the role fixed', $d3['kind'] === 'staff' && $d3['role_wanted'] === 'manager' && $d3['designation'] === 'Branch Manager, Surat');
check('superadmin is never offered by invite', str_contains(throws(fn() => AgentPro::createInvite('superadmin', '', 1)), 'role'));
check('a made-up invite token is refused', str_contains(throws(fn() => AgentPro::newDraft('AAAAAAAAAAAAAAAAAAAAAAAA')), 'expired'));

/* =================================================================== */
section('money: withdraw, pay in, office decisions');
AgentWallet::record($agentId, 'commission', 1500, ['note' => 'test commission']);
AgentWallet::record($agentId, 'cash_due', 3000, ['note' => 'test cash']);
$reqId = AgentWallet::requestPayout($agentId, 1000, 'test');
$lid = AgentPro::payWithdrawal($reqId, 1, 'UPI4455', 'paid');
check('pay in one step: ledger payout + request marked paid with that voucher',
    AgentWallet::balances($agentId)['commission'] == 500.0 && Database::fetch('SELECT status, ledger_id FROM agent_payout_requests WHERE id = :i', ['i' => $reqId])['ledger_id'] == $lid);
check('a deposit with neither UTR nor screenshot is refused', str_contains(throws(fn() => AgentPro::requestDeposit($agentId, 500, 'handover', 'upi', '')), 'UTR'));
$dep = AgentPro::requestDeposit($agentId, 2000, 'handover', 'upi', 'UTR778899001', null, 'test');
check('the same UTR twice is refused', str_contains(throws(fn() => AgentPro::requestDeposit($agentId, 2000, 'handover', 'upi', 'UTR778899001')), 'already'));
check('declining needs a reason', str_contains(throws(fn() => AgentPro::decideDeposit($dep, 'declined', 1, '')), 'why'));
AgentPro::decideDeposit($dep, 'confirmed', 1);
check('confirmed: written to the wallet as cash handed over', AgentWallet::balances($agentId)['cash'] == 1000.0
    && (int) Database::scalar('SELECT ledger_id FROM ap_deposit_requests WHERE id = :i', ['i' => $dep], 0) > 0);
$dep2 = AgentPro::requestDeposit($agentId, 5000, 'handover', 'upi', 'UTR778899002');
check('handing over more cash than held is refused (record it as a deposit instead)', str_contains(throws(fn() => AgentPro::decideDeposit($dep2, 'confirmed', 1)), 'holds only'));
AgentPro::decideDeposit($dep2, 'declined', 1, 'not in bank');
check('counts for the Control Center', AgentPro::counts()['agents'] >= 1 && array_key_exists('applications', AgentPro::counts()));

/* =================================================================== */
section('passkeys: fingerprint / face sign-in (a simulated phone)');
function cbor_enc(mixed $v): string {
    $head = static function (int $major, int $n): string {
        if ($n < 24) { return chr(($major << 5) | $n); }
        if ($n < 256) { return chr(($major << 5) | 24) . chr($n); }
        if ($n < 65536) { return chr(($major << 5) | 25) . pack('n', $n); }
        return chr(($major << 5) | 26) . pack('N', $n);
    };
    if (is_int($v)) { return $v >= 0 ? $head(0, $v) : $head(1, -1 - $v); }
    if (is_array($v) && isset($v['__bytes'])) { return $head(2, strlen($v['__bytes'])) . $v['__bytes']; }
    if (is_string($v)) { return $head(3, strlen($v)) . $v; }
    if (is_array($v)) { $o = $head(5, count($v)); foreach ($v as $k => $x) { $o .= cbor_enc($k) . cbor_enc($x); } return $o; }
    return "\xF6";
}
$_SERVER['HTTP_HOST'] = 'localhost:8899';
$_SESSION = [];
$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
$det = openssl_pkey_get_details($key)['ec'];
$cose = cbor_enc([1 => 2, 3 => -7, -1 => 1, -2 => ['__bytes' => $det['x']], -3 => ['__bytes' => $det['y']]]);
$admRow = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $agentId]);
$opt = ApPasskey::registerOptions($admRow);
check('registration options: this site, ES256 + RS256, fingerprint required', $opt['rp']['id'] === 'localhost' && $opt['pubKeyCredParams'][0]['alg'] === -7 && $opt['authenticatorSelection']['userVerification'] === 'required');
$credId = random_bytes(32);
$authData = hash('sha256', 'localhost', true) . chr(0x45) . pack('N', 0) . str_repeat("\0", 16) . pack('n', 32) . $credId . $cose;
$client = json_encode(['type' => 'webauthn.create', 'challenge' => $opt['challenge'], 'origin' => 'http://localhost:8899']);
$att = cbor_enc(['fmt' => 'none', 'attStmt' => [], 'authData' => ['__bytes' => $authData]]);
$pkId = ApPasskey::registerVerify($admRow, ['id' => ApPasskey::b64u($credId), 'clientDataJSON' => ApPasskey::b64u($client), 'attestationObject' => ApPasskey::b64u($att)]);
check('the phone\'s passkey is registered (public key only)', $pkId > 0 && str_contains((string) Database::scalar('SELECT public_key_pem FROM ap_passkeys WHERE id = :i', ['i' => $pkId], ''), 'BEGIN PUBLIC KEY'));
check('a registration answer cannot be replayed (challenge is single-use)', throws(fn() => ApPasskey::registerVerify($admRow, ['id' => ApPasskey::b64u($credId), 'clientDataJSON' => ApPasskey::b64u($client), 'attestationObject' => ApPasskey::b64u($att)])) !== '');
$assert = static function (int $count, string $origin = 'http://localhost:8899', bool $badSig = false) use ($key, $credId): array {
    $o = ApPasskey::loginOptions();
    $ad = hash('sha256', 'localhost', true) . chr(0x05) . pack('N', $count);
    $cd = json_encode(['type' => 'webauthn.get', 'challenge' => $o['challenge'], 'origin' => $origin]);
    openssl_sign($ad . hash('sha256', $cd, true), $sig, $key, OPENSSL_ALGO_SHA256);
    if ($badSig) { $sig[10] = chr(ord($sig[10]) ^ 1); }
    return ['id' => ApPasskey::b64u($credId), 'clientDataJSON' => ApPasskey::b64u($cd), 'authenticatorData' => ApPasskey::b64u($ad), 'signature' => ApPasskey::b64u($sig)];
};
check('fingerprint sign-in returns the right member', (int) (ApPasskey::loginVerify($assert(1))['id'] ?? 0) === $agentId);
check('a copied key (counter did not go up) is refused', str_contains(throws(fn() => ApPasskey::loginVerify($assert(1))), 'copied'));
check('a signature from another key is refused', throws(fn() => ApPasskey::loginVerify($assert(5, 'http://localhost:8899', true))) !== '');
check('a passkey used on another website is refused', str_contains(throws(fn() => ApPasskey::loginVerify($assert(6, 'https://evil.example'))), 'another website'));
check('without a fresh challenge it is refused', str_contains(throws(function () use ($assert): void { $c = $assert(7); $_SESSION = []; ApPasskey::loginVerify($c); }), 'expired'));
Database::update('admins', ['is_active' => 0], 'id = :i', ['i' => $agentId]);
check('a deactivated member cannot sign in with the fingerprint', str_contains(throws(fn() => ApPasskey::loginVerify($assert(8))), 'not active'));
Database::update('admins', ['is_active' => 1], 'id = :i', ['i' => $agentId]);

/* ---- tidy up ---- */
foreach ([$front, $selfie] as $f) { @unlink(UPLOAD_PATH . '/' . $f); }
@unlink(UPLOAD_PATH . '/' . (string) AgentWallet::profile($agentId)['photo_path']);
@unlink(UPLOAD_PATH . '/' . (string) AgentWallet::profile($newId)['photo_path']);
$cleanup();
Settings::set('whatsapp_driver', $driverWas, 'string', 'whatsapp', false);

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n----------------------------------------\n";
exit($FAIL === 0 ? 0 : 1);
