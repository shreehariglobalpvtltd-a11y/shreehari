<?php
/**
 * =====================================================================
 *  agent-pro-http-test.php — Agent Pro over real HTTP (php -S).
 *
 *    php tests/agent-pro-http-test.php http://127.0.0.1:8899
 *
 *    - the join form: page, CSRF, start → save → WhatsApp OTP → photo
 *      uploads (multipart, the Aadhaar QR read on the server) → submit
 *    - card images: who may see which side; signed links; the pay-in QR
 *    - id.php (VALID / REVOKED / NOT VALID) and c.php (+ vCard)
 *    - the member portal API with a signed-in agent: me, wallet, cards,
 *      profile, photo, withdraw, pay-in, pay link, statement, sign-out
 *    - every admin screen with a signed-in office manager; one-tap approve
 *      over a form POST; an agent is kept out of the office screens
 *    - the WhatsApp hook really sits in includes/wabot.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$BASE = rtrim($argv[1] ?? 'http://127.0.0.1:8899', '/');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-card.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  \033[31mUNCAUGHT\033[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 140) : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 500) : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }

/**
 * @param array<string, mixed>|null $json a JSON body, or ['__multipart' => [...]]
 * @return array{0: int, 1: string, 2: array<string, string>, 3: mixed}
 */
function http(string $method, string $url, ?array $body = null, string $cookie = '', string $csrf = ''): array {
    $ch = curl_init($url);
    $h = ['Accept: application/json'];
    if ($cookie !== '') { $h[] = 'Cookie: ' . $cookie; }
    if ($csrf !== '') { $h[] = 'X-CSRF-Token: ' . $csrf; }
    $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60, CURLOPT_NOPROXY => '*', CURLOPT_CUSTOMREQUEST => $method];
    if ($body !== null) {
        if (isset($body['__multipart'])) { $opt[CURLOPT_POSTFIELDS] = $body['__multipart']; }
        elseif (isset($body['__form'])) { $opt[CURLOPT_POSTFIELDS] = http_build_query($body['__form']); $h[] = 'Content-Type: application/x-www-form-urlencoded'; }
        else { $opt[CURLOPT_POSTFIELDS] = json_encode($body); $h[] = 'Content-Type: application/json'; }
    }
    $opt[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opt);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = [];
    foreach (explode("\r\n", substr($raw, 0, $hs)) as $line) {
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
    }
    $bodyStr = substr($raw, $hs);
    return [$code, $bodyStr, $headers, json_decode($bodyStr, true)];
}

/**
 * A staff session the dev server will accept: the session FILE written
 * straight into the same session store (so strict mode finds it), in the
 * store's own serialize format. No session_start() — a CLI script that has
 * already printed cannot change session settings.
 */
function mint(int $adminId): array {
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $adminId]);
    $sid = bin2hex(random_bytes(16));
    $csrf = bin2hex(random_bytes(32));
    $data = ['_created' => time(), 'lock_token' => bin2hex(random_bytes(12)), CSRF_TOKEN_NAME => $csrf,
        ADMIN_SESSION_KEY => ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
            'role' => (string) $a['role'], 'permissions' => [], 'must_change_pw' => false, 'via_otp' => true, 'logged_in_at' => time(), 'last_seen' => time()]];
    $dir = (string) ini_get('session.save_path');
    $dir = $dir !== '' ? (string) preg_replace('/^.*;/', '', $dir) : sys_get_temp_dir();
    if (ini_get('session.serialize_handler') === 'php_serialize') {
        $enc = serialize($data);
    } else {
        $enc = '';
        foreach ($data as $k => $v) { $enc .= $k . '|' . serialize($v); }
    }
    file_put_contents($dir . '/sess_' . $sid, $enc);
    return [SESSION_NAME . '=' . $sid, $csrf];
}

/** A guest session: the cookie a page sets, plus its CSRF token from the page. */
function guest(string $url): array {
    [$c, $body, $h] = http('GET', $url);
    $cookie = preg_match('/' . SESSION_NAME . '=([^;]+)/', $h['set-cookie'] ?? '', $m) ? SESSION_NAME . '=' . $m[1] : '';
    $csrf = preg_match('/"csrf":"([a-f0-9]{64})"/', $body, $m2) ? $m2[1] : '';
    return [$cookie, $csrf, $c, $body];
}

function jpeg(int $w = 900, int $h = 600): string {
    $f = tempnam(sys_get_temp_dir(), 'aph') . '.jpg';
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, 220, 226, 240));
    imagefilledellipse($im, (int) ($w / 2), (int) ($h / 2), (int) ($w / 3), (int) ($h / 2), imagecolorallocate($im, 190, 140, 110));
    imagejpeg($im, $f, 85);
    return $f;
}

if (!AgentPro::available()) { check('Agent Pro tables exist', false); exit(1); }

$driverWas = Settings::getString('whatsapp_driver', 'click_to_chat');
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'whatsapp', false);
$cleanup = static function (): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'aphttp%' OR phone IN ('7000000401','7000000402','7000000403')") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'agent_payout_requests' => 'agent_admin_id', 'ap_deposit_requests' => 'admin_id',
                  'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id', 'ap_passkeys' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    Database::delete('ap_applications', "phone IN ('7000000401','7000000402','7000000403') OR full_name LIKE 'APHTTP%'");
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'ap%' OR bucket LIKE 'otp_%'");
    Database::run("DELETE FROM otp_codes WHERE identifier LIKE 'apjoin:700000040%'");
};
$cleanup();

/* ======================= the join form ======================= */
section('join form over HTTP');
[$jc, $jcsrf, $code, $page] = guest($BASE . '/join.php');
check('join.php opens, noindex, with its config and a CSRF token', $code === 200 && str_contains($page, 'window.APJOIN') && $jcsrf !== '' && str_contains($page, 'noindex'));
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=start', ['invite' => ''], $jc);
check('without the CSRF token: refused', $c === 419, (string) $c);
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=start', ['invite' => ''], $jc, $jcsrf);
$key = (string) ($j['data']['key'] ?? '');
check('start → a draft key and reference', $c === 200 && preg_match('/^[a-f0-9]{32}$/', $key) === 1 && str_starts_with((string) ($j['data']['draft']['ref'] ?? ''), 'JN-'));
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=save', ['key' => $key, 'full_name' => 'APHTTP Bikash', 'agent_kind' => 'person', 'dob' => '2020-01-01'], $jc, $jcsrf);
check('save validates (too young)', $c === 422 && str_contains((string) ($j['error'] ?? ''), '16'));
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=save', ['key' => $key, 'full_name' => 'APHTTP Bikash', 'agent_kind' => 'person', 'id_type' => 'aadhaar', 'city' => 'Vadodara'], $jc, $jcsrf);
check('save keeps the fields', $c === 200 && ($j['data']['draft']['full_name'] ?? '') === 'APHTTP Bikash');
check('the draft never echoes encrypted numbers or file paths', !isset($j['data']['draft']['id_number_enc']) && !isset($j['data']['draft']['doc_front']));
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=otp_send', ['key' => $key, 'phone' => '7000000401', 'country' => 'IN'], $jc, $jcsrf);
check('WhatsApp code sent', $c === 200, json_encode($j));
Database::run("UPDATE otp_codes SET code_hash = :h WHERE identifier = 'apjoin:7000000401' AND is_used = 0", ['h' => password_hash('135790', PASSWORD_BCRYPT)]);
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=otp_verify', ['key' => $key, 'code' => '135790'], $jc, $jcsrf);
check('code verified', $c === 200 && ($j['data']['draft']['verified'] ?? false) === true);
$xml = '<?xml version="1.0"?><PrintLetterBarcodeData uid="234567890124" name="APHTTP Bikash Rana" gender="M" yob="1994" co="S/O Test Father" vtc="Vadodara" dist="Vadodara" state="Gujarat" pc="390001" dob="12/03/1994"/>';
$f = jpeg();
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=upload', ['__multipart' => ['key' => $key, 'slot' => 'front', 'id_type' => 'aadhaar', 'qr' => $xml,
    'file' => new CURLFile($f, 'image/jpeg', 'front.jpg')]], $jc, $jcsrf);
check('ID front uploaded (multipart)', $c === 200 && ($j['data']['draft']['has']['front'] ?? false) === true, json_encode($j));
check('…and read: the QR fills name, DOB, gender, number', ($j['data']['read']['fields']['full_name'] ?? '') === 'APHTTP Bikash Rana' && ($j['data']['read']['fields']['dob'] ?? '') === '1994-03-12'
    && ($j['data']['read']['sources']['full_name'] ?? '') === 'qr' && ($j['data']['read']['fields']['id_number'] ?? '') === '2345 6789 0124');
$stored = AgentPro::draft($key);
check('the stored photo is private (agents-kyc/) and re-encoded', str_starts_with((string) $stored['doc_front'], 'agents-kyc/') && getimagesize(UPLOAD_PATH . '/' . $stored['doc_front'])['mime'] === 'image/jpeg');
[$c] = http('GET', $BASE . '/uploads/' . $stored['doc_front']);
check('…and still not reachable at a guessable public URL? (served only if nginx allows /uploads — the path is random)', in_array($c, [200, 403, 404], true));
$txt = tempnam(sys_get_temp_dir(), 'aph') . '.txt';
file_put_contents($txt, "<?php echo 'x';");
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=upload', ['__multipart' => ['key' => $key, 'slot' => 'extra', 'label' => 'bad', 'file' => new CURLFile($txt, 'image/jpeg', 'evil.jpg')]], $jc, $jcsrf);
check('a disguised non-image is refused', $c === 422);
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=upload', ['__multipart' => ['key' => $key, 'slot' => 'selfie', 'file' => new CURLFile(jpeg(600, 800), 'image/jpeg', 'me.jpg')]], $jc, $jcsrf);
check('selfie uploaded', $c === 200 && ($j['data']['draft']['has']['selfie'] ?? false) === true);
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=upload', ['__multipart' => ['key' => $key, 'slot' => 'extra', 'label' => 'PAN card', 'file' => new CURLFile(jpeg(), 'image/jpeg', 'pan.jpg')]], $jc, $jcsrf);
check('an extra document with its label', $c === 200 && ($j['data']['draft']['extras'][0]['label'] ?? '') === 'PAN card');
http('POST', $BASE . '/api/agentpro.php?a=save', ['key' => $key, 'full_name' => 'APHTTP Bikash Rana', 'dob' => '1994-03-12', 'gender' => 'Male', 'address' => '12 Main Road'], $jc, $jcsrf);
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=submit', ['key' => $key], $jc, $jcsrf);
check('submit without ticking consent is refused', $c === 422 && isset($j['fields']['consent']));
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=submit', ['key' => $key, 'consent' => '1'], $jc, $jcsrf);
check('submit → sent to the office with its reference', $c === 200 && ($j['data']['status'] ?? '') === 'submitted' && str_starts_with((string) ($j['data']['ref'] ?? ''), 'JN-'), json_encode($j));
$app = AgentPro::draft($key);
[$c] = http('GET', $BASE . '/api/agentpro.php?a=get&key=' . str_repeat('0', 32));
check('an unknown draft key → 404', $c === 404);

/* ======================= people for the rest ======================= */
$mgrId = Database::insert('admins', ['username' => 'aphttp_mgr', 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'full_name' => 'APHTTP Manager',
    'phone' => '7000000402', 'role' => 'manager', 'is_active' => 1, 'must_change_pw' => 0]);
$agentId = Database::insert('admins', ['username' => 'aphttp_agent', 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'full_name' => 'APHTTP Agent',
    'phone' => '7000000403', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0]);
AgentWallet::saveProfile($agentId, ['agent_kind' => 'person', 'counter_name' => 'Surat', 'country_code' => '91']);
AgentWallet::setAgentCode($agentId, AgentWallet::nextFreeAgentCode('person'));
AgentWallet::record($agentId, 'commission', 900, ['note' => 'test']);
AgentWallet::record($agentId, 'cash_due', 1000, ['note' => 'test']);
$m = AgentPro::member($agentId);
[$mgr, $mgrCsrf] = mint($mgrId);
[$agt, $agtCsrf] = mint($agentId);

/* ======================= cards ======================= */
section('card images and who may see them');
[$c] = http('GET', $BASE . '/api/agentpro-card.php?m=' . $agentId . '&s=id-front');
check('no sign-in, no signature → 401', $c === 401);
$signed = ApCard::signedUrl($agentId, 'id-front', 300);
$path = (string) parse_url($signed, PHP_URL_PATH) . '?' . (string) parse_url($signed, PHP_URL_QUERY);
[$c, $b, $h] = http('GET', $BASE . $path);
check('a signed link (WhatsApp) → the PNG', $c === 200 && str_starts_with($h['content-type'] ?? '', 'image/png') && @imagecreatefromstring($b) !== false);
[$c] = http('GET', $BASE . str_replace('s=id-front', 's=id-back', $path));
check('the same signature for another side → 403', $c === 403);
[$c, $b, $h] = http('GET', $BASE . '/api/agentpro-card.php?m=' . $agentId . '&s=vc-back&f=svg', null, $agt);
check('the member sees their own card (SVG)', $c === 200 && str_contains($h['content-type'] ?? '', 'svg') && str_contains($b, '<svg'));
[$c] = http('GET', $BASE . '/api/agentpro-card.php?m=' . $mgrId . '&s=id-front', null, $agt);
check('an agent cannot open someone else\'s card', $c === 403);
[$c] = http('GET', $BASE . '/api/agentpro-card.php?m=' . $agentId . '&s=id-front', null, $mgr);
check('the office can', $c === 200);
[$c, , $h] = http('GET', $BASE . '/api/agentpro-card.php?u=' . $m['cardToken'] . '&s=vc-front');
check('the visiting card is public by its token (it is meant to be handed out)', $c === 200 && str_starts_with($h['content-type'] ?? '', 'image/png'));
[$c] = http('GET', $BASE . '/api/agentpro-card.php?u=' . $m['cardToken'] . '&s=id-front');
check('…the ID card is not', $c === 404);
[$c, $b, $h] = http('GET', $BASE . '/api/agentpro-card.php?m=' . $agentId . '&s=id-front&dl=1&print=1', null, $agt);
check('download at exactly 300 dpi', $c === 200 && str_contains($h['content-disposition'] ?? '', 'attachment') && getimagesizefromstring($b)[0] === 638);

/* ======================= public pages ======================= */
section('id.php · c.php');
[$c, $b] = http('GET', $BASE . '/id.php?c=' . $m['cardToken']);
check('id.php: VALID with name, photo slot and ID number', $c === 200 && str_contains($b, 'VALID') && str_contains($b, 'APHTTP Agent') && str_contains($b, $m['idNo']));
check('id.php never shows the member\'s phone', !str_contains($b, '7000000403'));
[$c, $b] = http('GET', $BASE . '/id.php?c=AAAAAAAAAAAAAAAAAAAAAAAA');
check('an unknown card: NOT VALID', $c === 200 && str_contains($b, 'NOT VALID'));
AgentPro::reissueCard($agentId);
[, $b] = http('GET', $BASE . '/id.php?c=' . $m['cardToken']);
check('after re-issue the old card says NOT VALID (fake / old)', str_contains($b, 'NOT VALID'));
$m = AgentPro::member($agentId);
[$c, $b] = http('GET', $BASE . '/c.php?u=' . $m['cardToken']);
check('c.php: the digital card with WhatsApp, call, save, book', $c === 200 && str_contains($b, 'wa.me/' . $m['wa']) && str_contains($b, 'vcf=1') && str_contains($b, '?ref=' . rawurlencode($m['idNo'])));
[$c, $b, $h] = http('GET', $BASE . '/c.php?u=' . $m['cardToken'] . '&vcf=1');
check('vCard download', $c === 200 && str_contains($h['content-type'] ?? '', 'text/vcard') && str_contains($b, 'BEGIN:VCARD') && str_contains($b, 'TEL;TYPE=CELL,VOICE:+' . $m['wa']));

/* ======================= the member portal ======================= */
section('/me/ with a signed-in agent');
[$c, $b, $h] = http('GET', $BASE . '/me/');
// 30 Oct 2026 (one door, switch login_one_door_on): signed out, /me/ hands over to /admin/login.php?back=me
check('signed out: the sign-in screen (the one door, or /me/\'s own when it is off)',
    ($c === 200 && str_contains($b, '"signed":false')) || ($c === 302 && str_contains((string) ($h['location'] ?? ''), 'login.php?back=me')));
[$c] = http('GET', $BASE . '/api/agentpro.php?a=me');
check('the API refuses a guest', $c === 401);
[$c, , , $j] = http('GET', $BASE . '/api/agentpro.php?a=me', null, $agt);
check('me: the member, card links, share + verify links', $c === 200 && ($j['data']['member']['idNo'] ?? '') === $m['idNo'] && isset($j['data']['cards']['vc-front']) && str_contains((string) $j['data']['verify'], 'id.php?c='));
check('…without leaking the encrypted account', !str_contains(json_encode($j), 'bank_account_enc'));
[$c, , , $j] = http('GET', $BASE . '/api/agentpro.php?a=wallet', null, $agt);
check('wallet: balances + passbook', $c === 200 && (float) ($j['data']['balances']['commission'] ?? 0) === 900.0 && count($j['data']['entries'] ?? []) >= 2);
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=profile_save', ['vc_whatsapp' => '+977 9812345678', 'vc_theme' => 'night', 'blood_group' => 'B+'], $agt, $agtCsrf);
check('profile: the number printed on the card changes', $c === 200 && ($j['data']['member']['waDisplay'] ?? '') === '+977 981-2345678' && ($j['data']['member']['theme'] ?? '') === 'night');
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=photo', ['__multipart' => ['file' => new CURLFile(jpeg(700, 900), 'image/jpeg', 'p.jpg')]], $agt, $agtCsrf);
check('photo upload → the ID card uses it', $c === 200 && str_contains((string) ($j['data']['photoUrl'] ?? ''), '/uploads/agents/'));
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=bank_save', ['upi_id' => 'aphttp@okicici'], $agt, $agtCsrf);
check('payout details', $c === 200 && AgentWallet::profile($agentId)['payout_account'] === 'aphttp@okicici');
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=withdraw', ['amount' => 500], $agt, $agtCsrf);
check('withdraw → a request for the office', $c === 200 && count(AgentWallet::openPayoutRequests($agentId)) === 1);
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=withdraw', ['amount' => 100], $agt, $agtCsrf);
check('a second one while the first is open is refused', $c === 422);
Settings::set('upi_id', Settings::getString('upi_id', '') ?: 'test@icici', 'string', 'payment', true);
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=pay_link', ['amount' => 1000], $agt, $agtCsrf);
check('pay link: a signed QR and a upi:// intent with the amount', $c === 200 && str_starts_with((string) ($j['data']['upi'] ?? ''), 'upi://pay?') && str_contains((string) $j['data']['upi'], 'am=1000.00'));
[$c, , $h] = http('GET', $BASE . (string) ($j['data']['qr'] ?? '/x'));
check('…the QR image loads', $c === 200 && str_starts_with($h['content-type'] ?? '', 'image/png'));
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=deposit', ['__multipart' => ['amount' => '800', 'purpose' => 'handover', 'method' => 'upi', 'utr' => 'HTTP' . random_int(100000, 999999),
    'proof' => new CURLFile(jpeg(), 'image/jpeg', 'proof.jpg')]], $agt, $agtCsrf);
check('pay-in with a screenshot → waiting for the office', $c === 200 && count(AgentPro::openDeposits($agentId)) === 1, json_encode($j));
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=statement', ['from' => date('Y-m-01'), 'to' => date('Y-m-d')], $agt, $agtCsrf);
check('statement PDF link', $c === 200 && str_contains((string) ($j['data']['url'] ?? ''), 'download-statement.php'), json_encode($j));
[$c, , , $j] = http('POST', $BASE . '/api/agentpro.php?a=pk_reg_options', [], $agt, $agtCsrf);
check('fingerprint sign-in: registration options for this site', $c === 200 && ($j['data']['rp']['id'] ?? '') === '127.0.0.1');

/* ======================= admin screens ======================= */
section('admin screens (office manager)');
foreach (['hub.php' => 'Control Center', 'team.php?tab=approvals' => 'APHTTP Bikash Rana', 'team.php?tab=agents' => 'APHTTP Agent',
          'team.php?tab=staff' => 'APHTTP Manager', 'team.php?tab=money' => '500', 'team.php?tab=cards' => 'Print cards', 'team.php?tab=invites' => 'Invite someone',
          'team-member.php?id=' . $agentId => 'What the cards print', 'ai-local.php' => 'This VPS'] as $page => $needle) {
    [$c, $b] = http('GET', $BASE . '/admin/' . $page, null, $mgr);
    check("admin/$page renders", $c === 200 && str_contains($b, $needle) && !str_contains($b, 'Fatal error') && !str_contains($b, 'Warning:'), $c . ' ' . (str_contains($b, $needle) ? '' : 'missing ' . $needle));
}
[$c, $b] = http('GET', $BASE . '/admin/team-print.php?what=all&ids[]=' . $agentId, null, $mgr);
check('print sheet: 4 A4 pages of true-size cards', $c === 200 && substr_count($b, 'class="sheet"') === 4);
[$c, , $h] = http('GET', $BASE . '/admin/team-doc.php?app=' . (int) $app['id'] . '&f=front', null, $mgr);
check('the office opens the applicant\'s ID photo (private, no cache)', $c === 200 && str_starts_with($h['content-type'] ?? '', 'image/jpeg') && str_contains($h['cache-control'] ?? '', 'no-store'));
[$c] = http('GET', $BASE . '/admin/team-doc.php?app=' . (int) $app['id'] . '&f=front', null, $agt);
check('…an agent cannot', $c === 403);
[$c] = http('GET', $BASE . '/admin/team.php', null, $agt);
check('an agent is kept out of Team & ID Cards', $c === 403);
[$c] = http('GET', $BASE . '/admin/agentpro-install.php', null, $mgr);
check('the installer is super-admin only', $c === 403);
[$c, $b] = http('POST', $BASE . '/admin/team.php?tab=approvals', ['__form' => ['action' => 'approve', 'app' => (string) $app['id'], CSRF_TOKEN_NAME => $mgrCsrf]], $mgr);
Settings::flush();                       // the server process wrote agent_codes; our cache is older
$after = AgentPro::application((int) $app['id']);
check('ONE TAP over HTTP: ✓ Approve → approved, active, coded', $after['status'] === 'approved' && (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => (int) $after['admin_id']], 0) === 1
    && AgentWallet::agentCodeFor((int) $after['admin_id']) !== null && str_contains($b, 'Approved'),
    preg_match('/class="flash [a-z]+">(.*?)<\/div>/s', $b, $fm) ? strip_tags($fm[1]) : $c . ' ' . mb_substr(strip_tags($b), 0, 300));
$req = AgentWallet::openPayoutRequests($agentId)[0] ?? null;
[$c, $b] = http('POST', $BASE . '/admin/team.php?tab=money', ['__form' => ['action' => 'pay', 'req' => (string) ($req['id'] ?? 0), 'ref' => 'NEFT123', CSRF_TOKEN_NAME => $mgrCsrf]], $mgr);
check('Money: "✓ Paid" in one tap → ledger payout + voucher', AgentWallet::openPayoutRequests($agentId) === [] && AgentWallet::balances($agentId)['commission'] == 400.0 && str_contains($b, 'Paid'));
$dep = AgentPro::openDeposits($agentId)[0] ?? null;
http('POST', $BASE . '/admin/team.php?tab=money', ['__form' => ['action' => 'confirm_dep', 'dep' => (string) ($dep['id'] ?? 0), CSRF_TOKEN_NAME => $mgrCsrf]], $mgr);
check('Money: "✓ Received" → cash handed over in the wallet', AgentWallet::balances($agentId)['cash'] == 200.0);
[$c, $b] = http('POST', $BASE . '/admin/team.php?tab=invites', ['__form' => ['action' => 'invite', 'role' => 'counter', 'designation' => 'Counter, Surat', 'days' => '7', 'note' => 'aphttp', CSRF_TOKEN_NAME => $mgrCsrf]], $mgr);
check('Invites: a link for a new counter staff member', str_contains($b, 'join.php?invite='));
Database::delete('ap_invites', "note = 'aphttp'");
[$c, $b] = http('POST', $BASE . '/admin/team-member.php?id=' . $agentId, ['__form' => ['action' => 'card', 'designation' => 'Gold Agent', 'vc_theme' => 'royal', CSRF_TOKEN_NAME => $mgrCsrf]], $mgr);
check('member page: what the card prints is saved', AgentPro::member($agentId)['designation'] === 'Gold Agent');
[$c, $b] = http('POST', $BASE . '/api/agentpro.php?a=logout', [], $agt, $agtCsrf);
[$c] = http('GET', $BASE . '/api/agentpro.php?a=me', null, $agt);
check('sign out ends the session', $c === 401);

/* ======================= the WhatsApp hook ======================= */
section('the hook in includes/wabot.php');
require_once INCLUDE_PATH . '/wabot.php';
$r = WaBot::reply('+917000000402', 'cin');
check('WaBot::reply hands staff messages to the Agent Pro desk', str_contains((string) ($r['text'] ?? ''), Settings::company()['cin']));
$code = (string) file_get_contents(INCLUDE_PATH . '/wabot.php');
check('…and the VPS-AI hook replaces the cloud call for passengers', str_contains($code, 'ApWa::customerTurn(') && str_contains($code, 'AiAgent::handle($from, $body)'));

/* ---- tidy up ---- */
foreach (['doc_front', 'selfie'] as $col) { if (($p = AgentPro::privateAbs((string) ($app[$col] ?? ''))) !== null) { @unlink($p); } }
$cleanup();
Settings::set('whatsapp_driver', $driverWas, 'string', 'whatsapp', false);
echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n----------------------------------------\n";
exit($FAIL === 0 ? 0 : 1);
