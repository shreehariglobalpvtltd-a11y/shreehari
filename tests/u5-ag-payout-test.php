<?php
/**
 * u5 (19 Nov 2026) — AG-2 withdraw for every agent (UPI / bank / QR) + NP-4 member + AG-5 help.
 *
 *   php -c .claude/php-dev.ini tests/u5-ag-payout-test.php [http://localhost:8963]
 *
 * CLI part (always):
 *   - agent_payout_verify_required = 0 (default): an AgentPro agent with NO legacy
 *     agent_applications row can now request (before 19 Nov every one was refused);
 *   - a named method must be saved; the request snapshots pay_method / pay_to;
 *   - one open request at a time; never above the commission due;
 *   - = 1: refused until the office ticks "payout details checked"; a changed UPI
 *     clears the tick again; nobody ticks their own; TeamFinance::approve uses the same rule;
 *   - NP-4: a +977 member's company block is the Nepal company while ap_card_nepal_first is on.
 * HTTP part (when the dev server answers): /me/ wallet JSON has heads / perHead / payoutMethods,
 *   me JSON has the help block, a QR upload (multipart) is stored privately and served only
 *   to the member and the office; withdraw by QR snapshots the QR.
 *
 * Throwaway rows only ('zztest-u5-pay-*', phones 90000xxxxx), deleted at the end.
 * Settings are changed in memory only. No WhatsApp is sent (decisions go through
 * AgentWallet::decidePayoutRequest, which never messages).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$BASE = rtrim($argv[1] ?? 'http://localhost:8963', '/');
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/teamfinance.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? ' — ' . mb_substr($extra, 0, 400) : '') . "\n"; }
}
function throws(callable $fn): string {
    try { $fn(); return ''; } catch (Throwable $e) { return $e->getMessage() !== '' ? $e->getMessage() : get_class($e); }
}
function u5_set(array $ovr): void {
    Settings::get('company_name');
    $rp = new ReflectionProperty('Settings', 'cache');
    $c = $rp->getValue();
    foreach ($ovr as $k => $v) {
        $c[$k] = ['value' => $v, 'type' => in_array($v, ['0', '1'], true) ? 'bool' : 'string', 'public' => false];
    }
    $rp->setValue(null, $c);
}
function u5_admin(string $role, string $tag, int $n): int {
    return (int) Database::insert('admins', [
        'username' => 'zztest-u5-pay-' . $role . '-' . $tag . '-' . $n, 'password_hash' => 'x', 'full_name' => 'ZZTEST u5 pay ' . $role,
        'phone' => '90000' . $tag . $n, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
}

$tag = (string) random_int(1000, 9999);
$ids = [];
$files = [];
echo "\n=== u5 AG-2 withdraw · NP-4 member · AG-5 help ===\n\n";
if (!AgentPro::available() || !AgentPro::memberHas('payout_qr_path') || !AgentWallet::payoutRequestHasMethod()) {
    echo "  run database/upgrade-2026-11-19-u5-agent-payout.sql first\n";
    exit(1);
}
try {
    $agent  = $ids[] = u5_admin('agent', $tag, 1);
    $office = $ids[] = u5_admin('manager', $tag, 2);
    AgentPro::ensureMember($agent, 'agent');
    AgentWallet::record($agent, 'adjustment', 1500.0, ['account' => 'commission', 'direction' => 'credit', 'by' => $office, 'ref' => 'ZZU5-' . $tag, 'note' => 'ZZTEST u5 commission']);
    check('test agent has ₹1,500 commission due', abs(AgentWallet::balances($agent)['commission'] - 1500.0) < 0.01);
    check('no legacy agent_applications row (the live case)', !Database::exists('SELECT 1 FROM agent_applications WHERE admin_id = :a', ['a' => $agent]));

    /* ---- verify not required (default) ---- */
    u5_set(['agent_payout_verify_required' => '0']);
    check('payoutVerified = true while the setting is off', AgentWallet::payoutVerified($agent));
    $err = throws(fn() => AgentWallet::requestPayout($agent, 500, 'x', 'upi'));
    check('a named method that is not saved is refused', $err !== '' && stripos($err, 'UPI') !== false, $err);
    AgentPro::saveBank($agent, ['upi_id' => 'zztestu5@okaxis'], $agent);
    $rid = AgentWallet::requestPayout($agent, 500, 'ZZTEST', 'upi');
    check('request accepted for an AgentPro agent (was always refused before)', $rid > 0);
    $row = Database::fetch('SELECT * FROM agent_payout_requests WHERE id = :i', ['i' => $rid]);
    check('request snapshots pay_method = upi', ($row['pay_method'] ?? '') === 'upi');
    check('request snapshots pay_to = the UPI id', str_contains((string) ($row['pay_to'] ?? ''), 'zztestu5@okaxis'), (string) ($row['pay_to'] ?? ''));
    $err = throws(fn() => AgentWallet::requestPayout($agent, 100, 'again', 'upi'));
    check('a second open request is refused', $err !== '' && str_contains($err, 'still with the office'), $err);
    check('TeamFinance::available reserves the open ₹500', abs(TeamFinance::available($agent)['available'] - 1000.0) < 0.01);
    AgentWallet::decidePayoutRequest($rid, 'declined', $office, 'ZZTEST decline');
    check('declined with a reason', Database::scalar('SELECT status FROM agent_payout_requests WHERE id = :i', ['i' => $rid], '') === 'declined');
    $err = throws(fn() => AgentWallet::requestPayout($agent, 5000, '', 'upi'));
    check('never above the commission due', $err !== '' && str_contains($err, 'up to your commission due'), $err);
    $sodium = function_exists('sodium_crypto_secretbox');   // the account number is encrypted (live has sodium; php-dev.ini may not)
    if ($sodium) {
        AgentPro::saveBank($agent, ['bank_holder' => 'ZZ Test', 'bank_account' => '123456789012', 'bank_ifsc' => 'SBIN0001234', 'bank_branch' => 'Surat'], $agent);
        $t = AgentWallet::payoutTarget($agent, 'bank');
        check('bank target masks the account and names IFSC + branch', str_contains($t['to'], '••••9012') && str_contains($t['to'], 'SBIN0001234') && str_contains($t['to'], 'Surat') && !str_contains($t['to'], '12345678'), $t['to']);
    } else {
        echo "  (sodium not loaded: bank-account part skipped — run with -d extension=php_sodium.dll)\n";
        Database::update('ap_members', ['bank_holder' => 'ZZ Test', 'bank_account_last4' => '9012', 'bank_ifsc' => 'SBIN0001234', 'bank_branch' => 'Surat'], 'admin_id = :a', ['a' => $agent]);
    }
    check('unknown method refused', throws(fn() => AgentWallet::payoutTarget($agent, 'cheque')) !== '');

    /* ---- verify required ---- */
    u5_set(['agent_payout_verify_required' => '1']);
    check('setting on: unverified agent cannot request', str_contains(throws(fn() => AgentWallet::requestPayout($agent, 100, '', 'upi')), 'verify'));
    check('nobody ticks their own payout details', throws(fn() => AgentPro::setPayoutVerified($agent, true, $agent)) !== '');
    AgentPro::setPayoutVerified($agent, true, $office);
    check('office tick → payoutVerified', AgentWallet::payoutVerified($agent));
    $rid2 = AgentWallet::requestPayout($agent, 200, '', 'bank');
    check('verified agent may request by bank', $rid2 > 0 && Database::scalar('SELECT pay_method FROM agent_payout_requests WHERE id = :i', ['i' => $rid2], '') === 'bank');
    AgentWallet::decidePayoutRequest($rid2, 'declined', $office, 'ZZTEST decline 2');
    AgentPro::saveBank($agent, ['upi_id' => 'zztestu5new@okhdfc'], $agent);
    check('a changed UPI clears the office tick (anti-fraud)', !AgentWallet::payoutVerified($agent));
    AgentPro::saveBank($agent, ['upi_id' => 'zztestu5new@okhdfc'], $agent);
    AgentPro::setPayoutVerified($agent, true, $office);
    AgentPro::saveBank($agent, ['upi_id' => 'zztestu5new@okhdfc'], $agent);
    check('saving the SAME UPI keeps the tick', AgentWallet::payoutVerified($agent));
    u5_set(['agent_payout_verify_required' => '0']);

    /* ---- NP-4 member ---- */
    AgentWallet::saveProfile($agent, ['country_code' => '977']);
    u5_set(['ap_card_nepal_first' => '1', 'nepal_company' => 'ZZ Nepal Co Pvt Ltd', 'nepal_office' => 'Pushpalal Chowk, Nepalgunj', 'nepal_pan' => '612454076', 'nepal_phone' => '+977 98662 01375 / 98257 88450']);
    $m = AgentPro::member($agent, false);
    check('NP member: company = the Nepal company', ($m['company']['name'] ?? '') === 'ZZ Nepal Co Pvt Ltd', json_encode($m['company']['name'] ?? null));
    check('NP member: address = the Nepal office', ($m['company']['address'] ?? '') === 'Pushpalal Chowk, Nepalgunj');
    check('NP member: flagFirst NP, PAN as the reg line, no India CIN', $m['flagFirst'] === 'NP' && ($m['company']['regNo'] ?? '') === '612454076' && ($m['company']['cin'] ?? 'x') === '');
    u5_set(['ap_card_nepal_first' => '0']);
    $m2 = AgentPro::member($agent, false);
    check('switch off: the India company as before', ($m2['company']['name'] ?? '') === (Settings::company()['name'] ?? '') && $m2['flagFirst'] === 'IN');
    u5_set(['ap_card_nepal_first' => '1']);
    $mi = AgentPro::member($office, false);
    check('India member: unchanged India company', ($mi['company']['name'] ?? '') === (Settings::company()['name'] ?? ''));

    /* ---- HTTP: /me/ JSON, QR upload, withdraw by QR (needs the dev server) ---- */
    $ch = curl_init($BASE . '/api/agentpro.php?a=csrf');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOPROXY => '*']);
    $up = curl_exec($ch) !== false && (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) > 0;
    curl_close($ch);
    if (!$up) {
        echo "  (dev server not reachable at $BASE — HTTP part skipped)\n";
    } else {
        $mint = static function (int $adminId): array {
            $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $adminId]);
            $sid = bin2hex(random_bytes(16));
            $csrf = bin2hex(random_bytes(32));
            $data = ['_created' => time(), 'lock_token' => bin2hex(random_bytes(12)), CSRF_TOKEN_NAME => $csrf,
                ADMIN_SESSION_KEY => ['id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) $a['full_name'],
                    'role' => (string) $a['role'], 'permissions' => [], 'must_change_pw' => false, 'via_otp' => true, 'logged_in_at' => time(), 'last_seen' => time()]];
            $dir = (string) ini_get('session.save_path');
            $dir = $dir !== '' ? (string) preg_replace('/^.*;/', '', $dir) : sys_get_temp_dir();
            $enc = '';
            if (ini_get('session.serialize_handler') === 'php_serialize') { $enc = serialize($data); }
            else { foreach ($data as $k => $v) { $enc .= $k . '|' . serialize($v); } }
            file_put_contents($dir . '/sess_' . $sid, $enc);
            $GLOBALS['files'][] = $dir . '/sess_' . $sid;
            return [SESSION_NAME . '=' . $sid, $csrf];
        };
        $http = static function (string $method, string $url, $body, string $cookie, string $csrf = ''): array {
            $ch = curl_init($url);
            $h = ['Accept: application/json', 'Cookie: ' . $cookie];
            if ($csrf !== '') { $h[] = 'X-CSRF-Token: ' . $csrf; }
            $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_NOPROXY => '*', CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => false];
            if (is_array($body) && isset($body['__multipart'])) { $opt[CURLOPT_POSTFIELDS] = $body['__multipart']; }
            elseif ($body !== null) { $opt[CURLOPT_POSTFIELDS] = json_encode($body); $h[] = 'Content-Type: application/json'; }
            $opt[CURLOPT_HTTPHEADER] = $h;
            curl_setopt_array($ch, $opt);
            $raw = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            return [$code, $raw, json_decode($raw, true), $type];
        };
        Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'apapi_ip%' OR bucket LIKE 'ap_payout_qr%'");
        [$ck, $cs] = $mint($agent);
        [$c, $raw, $j] = $http('GET', $BASE . '/api/agentpro.php?a=wallet', null, $ck);
        check('HTTP wallet 200', $c === 200 && !empty($j['ok']), $c . ' ' . mb_substr($raw, 0, 300));
        $d = $j['data'] ?? [];
        check('wallet JSON: heads today/month/all + perHead + available', isset($d['heads']['today']['heads'], $d['heads']['all'], $d['perHead']['label']) && isset($d['available']), json_encode(array_keys($d)));
        check('wallet JSON: payoutMethods lists the saved UPI and bank, not QR yet',
            in_array('upi', array_column($d['payoutMethods'] ?? [], 'method'), true) && in_array('bank', array_column($d['payoutMethods'] ?? [], 'method'), true)
            && !in_array('qr', array_column($d['payoutMethods'] ?? [], 'method'), true), json_encode($d['payoutMethods'] ?? null));
        [$c, $raw, $j] = $http('GET', $BASE . '/api/agentpro.php?a=me', null, $ck);
        $help = $j['data']['help'] ?? [];
        check('me JSON: help has call / wa / joinUrl', ($help['call'] ?? '') !== '' && ($help['wa'] ?? '') !== '' && str_contains((string) ($help['joinUrl'] ?? ''), 'join.php'), json_encode($help));

        // QR upload (multipart) — a real JPEG
        $f = tempnam(sys_get_temp_dir(), 'u5q') . '.jpg'; $files[] = $f;
        $im = imagecreatetruecolor(300, 300); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagefilledrectangle($im, 20, 20, 120, 120, imagecolorallocate($im, 0, 0, 0)); imagejpeg($im, $f, 85);
        [$c, $raw, $j] = $http('POST', $BASE . '/api/agentpro.php?a=payout_qr', ['__multipart' => ['file' => new CURLFile($f, 'image/jpeg', 'qr.jpg')]], $ck, $cs);
        check('QR upload accepted', $c === 200 && !empty($j['ok']), $c . ' ' . mb_substr($raw, 0, 300));
        $qrRel = (string) Database::scalar('SELECT payout_qr_path FROM ap_members WHERE admin_id = :a', ['a' => $agent], '');
        check('QR stored privately under agents-kyc/', str_starts_with($qrRel, 'agents-kyc/') && AgentPro::privateAbs($qrRel) !== null, $qrRel);
        if ($qrRel !== '' && ($abs = AgentPro::privateAbs($qrRel)) !== null) { $files[] = $abs; }
        // a text file is refused
        $tf = tempnam(sys_get_temp_dir(), 'u5t') . '.txt'; $files[] = $tf; file_put_contents($tf, 'not an image');
        [$c, $raw] = $http('POST', $BASE . '/api/agentpro.php?a=payout_qr', ['__multipart' => ['file' => new CURLFile($tf, 'text/plain', 'x.txt')]], $ck, $cs);
        check('a non-image QR is refused', $c >= 400, (string) $c);
        [$c, $raw, , $type] = $http('GET', $BASE . '/api/agentpro.php?a=payout_qr', null, $ck);
        check('the member sees their own QR (image/jpeg)', $c === 200 && str_contains($type, 'image/jpeg'), $c . ' ' . $type);
        // another plain agent may not see it
        $other = $ids[] = u5_admin('agent', $tag, 3);
        [$ck2] = $mint($other);
        [$c] = $http('GET', $BASE . '/api/agentpro.php?a=payout_qr&m=' . $agent, null, $ck2);
        check('another agent cannot open it', $c === 403 || $c === 404, (string) $c);
        [$ck3] = $mint($office);
        [$c, , , $type] = $http('GET', $BASE . '/api/agentpro.php?a=payout_qr&m=' . $agent, null, $ck3);
        check('the office (manager) opens it', $c === 200 && str_contains($type, 'image/jpeg'), $c . ' ' . $type);
        // withdraw by QR, over the real endpoint
        [$c, $raw, $j] = $http('POST', $BASE . '/api/agentpro.php?a=withdraw', ['amount' => 300, 'note' => 'ZZTEST', 'method' => 'qr'], $ck, $cs);
        check('withdraw by QR accepted', $c === 200 && !empty($j['ok']), $c . ' ' . mb_substr($raw, 0, 300));
        $rq = Database::fetch("SELECT * FROM agent_payout_requests WHERE agent_admin_id = :a AND status = 'open' ORDER BY id DESC LIMIT 1", ['a' => $agent]);
        check('the request keeps the QR it was made with', ($rq['pay_method'] ?? '') === 'qr' && (string) ($rq['pay_qr_path'] ?? '') === $qrRel);
        [$c, , , $type] = $http('GET', $BASE . '/api/agentpro.php?a=payout_qr&req=' . (int) ($rq['id'] ?? 0), null, $ck3);
        check('the office opens the request QR', $c === 200 && str_contains($type, 'image/jpeg'), $c . ' ' . $type);
        [$c, $raw, $j] = $http('POST', $BASE . '/api/agentpro.php?a=withdraw', ['amount' => 99999, 'method' => 'upi'], $ck, $cs);
        check('withdraw above the available balance is refused', $c === 422, $c . ' ' . mb_substr($raw, 0, 200));
        if ($rq !== null) { AgentWallet::decidePayoutRequest((int) $rq['id'], 'declined', $office, 'ZZTEST'); }
    }
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
} finally {
    foreach ($ids as $a) {
        Database::delete('agent_payout_requests', 'agent_admin_id = :a', ['a' => $a]);
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $a]);
        try { Database::delete('ap_members', 'admin_id = :a', ['a' => $a]); } catch (Throwable $e) {}
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $a]); } catch (Throwable $e) {}
        try { Database::run('DELETE FROM staff_messages WHERE admin_id = :a', ['a' => $a]); } catch (Throwable $e) {}
        try { Database::delete('admins', 'id = :a', ['a' => $a]); } catch (Throwable $e) {}
    }
    foreach ($files as $f) { if (is_file($f)) { @unlink($f); } }
}
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
