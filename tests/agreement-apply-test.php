<?php
/**
 * =====================================================================
 *  agreement-apply-test.php — "apply for an agreement", over real HTTP
 *  (27 Sep 2026).
 *
 *      php tests/run-all.php --http      (needs the dev server on :8899)
 *      php tests/agreement-apply-test.php
 *
 *    1. GET /api/agreement-apply.php hands out a session CSRF token
 *    2. POST without / with a wrong token = 419; missing fields = 422 with
 *       the field names; a wrong mobile = 422 on p_phone
 *    3. a valid organization and a valid individual = ok + AGR ref, the
 *       row stored with its fields (unknown names dropped, 300 chars max),
 *       the answer echoes nothing stored; a bad photo is dropped, the
 *       application still goes through
 *    4. exactly the same form again = the same ref, nothing new stored; a
 *       different form from the same phone = a NEW ref (never the earlier
 *       one), kept as a repeat; the honeypot = the same answer shape, a ref
 *       just past the real ones, the same ref for the same form, nothing
 *       stored; wrong forms (422) never lock a person out; the budgets
 *       (30 tries / 10 stored an hour per IP, 3 a day per phone, 30 an hour
 *       for the whole site, 10 office notes an hour) and a 2 MB body = 413 (18 Oct 2026: photo + document + signature)
 *    5. /api/agent-directory.php: an unapproved application is absent; an
 *       approved one shows the full phone with consent and a masked one
 *       without; no email / fields / photo / id ever appears; cached 5 min,
 *       "public" only when the answer sets no cookie
 *    6. admin/agreements.php: a stranger goes to the sign-in page; a
 *       super-admin sees the ref, the photo and the bank / ID details, and
 *       that opening is logged; WhatsApp links follow the typed country; a
 *       POST without CSRF changes nothing, with CSRF it works; support staff
 *       may look — with ID, bank and photo masked — but not change; an agent
 *       may not look
 *
 *  Its own PDO reads config/config.local.php (must be a test database).
 *  Every row it makes is removed at the end; the office WhatsApp note is
 *  switched off for the run and put back.
 * =====================================================================
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
date_default_timezone_set('Asia/Kolkata');

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: getenv('SHG_BASE') ?: 'http://localhost:8899'), '/'));
const AA_PW  = 'AgrApply@12345';
const AA_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/** @return array{code:int, body:string, location:string, cache:string, cookie:bool, json:?array} */
function aa_req(string $method, string $url, string $jar, array $opt = []): array
{
    $ch = curl_init(BASE . $url);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
    ]);
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json'], JSON_UNESCAPED_UNICODE));
        $headers[] = 'Content-Type: application/json';
    } elseif (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
    }
    if ($headers) { curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsz  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr($raw, 0, $hsz);
    $body = substr($raw, $hsz);
    $j = json_decode($body, true);
    return [
        'code'     => $code,
        'body'     => $body,
        'location' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '',
        'cache'    => preg_match('/^Cache-Control:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '',
        'cookie'   => preg_match('/^Set-Cookie:/mi', $head) === 1,
        'json'     => is_array($j) ? $j : null,
    ];
}

function aa_pdo(): PDO
{
    static $p = null;
    if ($p === null) {
        if (!defined('DB_NAME')) {
            $local = dirname(__DIR__) . '/config/config.local.php';
            if (is_file($local)) {
                if (!defined('SHG_APP')) { define('SHG_APP', true); }
                require_once $local;
            }
        }
        $host = defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1:3307';
        $name = defined('DB_NAME') ? (string) DB_NAME : 'shari_test';
        $user = defined('DB_USER') ? (string) DB_USER : 'root';
        $pass = defined('DB_PASS') ? (string) DB_PASS : '';
        if (stripos($name, 'test') === false) {
            throw new RuntimeException("Refusing: $name is not a test database");
        }
        // Both shapes: "127.0.0.1:3307" and the app's own "127.0.0.1;port=3307".
        if (preg_match('~^([^;:]+);port=(\d+)$~', $host, $hm)) { [$h, $port] = [$hm[1], $hm[2]]; }
        else { [$h, $port] = array_pad(explode(':', $host, 2), 2, '3306'); }
        $p = new PDO("mysql:host=$h;port=$port;dbname=$name;charset=utf8mb4", $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    return $p;
}

function aa_one(string $sql, array $params = []): ?array
{
    $q = aa_pdo()->prepare($sql);
    $q->execute($params);
    $r = $q->fetch();
    return $r === false ? null : $r;
}

function aa_exec(string $sql, array $params = []): void
{
    aa_pdo()->prepare($sql)->execute($params);
}

function aa_jar(string $name): string
{
    $p = sys_get_temp_dir() . '/shg_aa_' . $name . '_' . getmypid() . '.cookies';
    @unlink($p);
    return $p;
}

function aa_staff(string $username, string $role, string $name, ?string $phone = null): int
{
    $hash = password_hash(AA_PW, PASSWORD_BCRYPT);
    $row = aa_one('SELECT id FROM admins WHERE username = ?', [$username]);
    if ($row !== null) {
        aa_exec('UPDATE admins SET password_hash=?, role=?, full_name=?, phone=?, is_active=1, must_change_pw=0, failed_logins=0, locked_until=NULL WHERE id=?',
            [$hash, $role, $name, $phone, $row['id']]);
        return (int) $row['id'];
    }
    aa_exec('INSERT INTO admins (username, password_hash, full_name, phone, role, is_active, must_change_pw) VALUES (?,?,?,?,?,1,0)',
        [$username, $hash, $name, $phone, $role]);
    return (int) aa_pdo()->lastInsertId();
}

function aa_login(string $user, string $jar): bool
{
    $lp  = aa_req('GET', '/admin/login.php', $jar);
    $tok = preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $lp['body'], $m) ? $m[1] : '';
    $li  = aa_req('POST', '/admin/login.php', $jar, ['form' => ['shg_csrf' => $tok, 'username' => $user, 'password' => AA_PW]]);
    return $li['code'] === 302;
}

function aa_token(string $jar): string
{
    $r = aa_req('GET', '/api/agreement-apply.php', $jar);
    return (string) ($r['json']['csrf'] ?? '');
}

function aa_post(string $jar, string $token, array $body): array
{
    return aa_req('POST', '/api/agreement-apply.php', $jar, [
        'json' => $body, 'headers' => $token !== '' ? ['X-CSRF-Token: ' . $token] : [],
    ]);
}

function aa_reset_limits(): void
{
    aa_exec("DELETE FROM rate_limits WHERE bucket LIKE 'agr\\_%' OR bucket = 'agent_directory' OR bucket LIKE 'admin_login%'");
}

/** Put a budget right at its limit, so the next counted request is refused. */
function aa_fill(string $bucket, string $identifier, int $hits): void
{
    aa_exec('DELETE FROM rate_limits WHERE bucket = ? AND identifier = ?', [$bucket, $identifier]);
    aa_exec('INSERT INTO rate_limits (bucket, identifier, hits, window_start, blocked_until) VALUES (?, ?, ?, NOW(), NULL)',
        [$bucket, $identifier, $hits]);
}

/** The address the dev server sees for this test (read back from a budget row). */
function aa_my_ip(): string
{
    $r = aa_one("SELECT identifier FROM rate_limits WHERE bucket = 'agr_apply_ip' ORDER BY id DESC LIMIT 1");
    return (string) ($r['identifier'] ?? '127.0.0.1');
}

$pass = 0; $fail = 0;
function aa_check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

$PH = ['org' => '9999700001', 'ind' => '9999700002', 'hp' => '9999700009', 'lim' => '9999700003', 'cap' => '9999700004'];
$USERS = ['agr-super' => 'superadmin', 'agr-support' => 'support', 'agr-agent' => 'agent'];
$cleanup = static function () use ($PH): void {
    aa_exec("DELETE FROM agent_applications WHERE phone LIKE '99997000%'");
};
$notifyWas = null;

try {
    $row = aa_one("SELECT svalue FROM settings WHERE skey = 'whatsapp_notify_admin'");
    $notifyWas = $row === null ? null : (string) $row['svalue'];
    if ($notifyWas !== null) { aa_exec("UPDATE settings SET svalue = '0' WHERE skey = 'whatsapp_notify_admin'"); }
    $cleanup();
    aa_reset_limits();

    $orgFields = [
        'p_name' => 'AgrTest Travels Pvt Ltd', 'p_type' => '0', 'p_phone' => '99997 00001', 'p_area' => 'Surat',
        'p_rep' => 'Hari Prasad', 'p_email' => 'agrtest@example.com', 'p_addr' => str_repeat('A', 400),
        'p_tax' => 'GST-AGRTEST-1', 'b_acc' => '000111222333', 'w1_name' => 'Witness Ram', 'c73' => true,
        'evil_field' => 'should never be stored',
    ];
    $indFields = [
        'p_name' => 'AgrTest Person', 'p_phone' => '+91 99997-00002', 'p_area' => 'Mehsana', 'p_idno' => 'CIT-AGRTEST-9',
        'p_idtype' => '1', 'p_addr' => 'Test Tole 7',
    ];

    echo "\n=== 1. the token ===\n";
    $jar = aa_jar('apply');
    $g = aa_req('GET', '/api/agreement-apply.php', $jar);
    $tok = (string) ($g['json']['csrf'] ?? '');
    aa_check('GET gives ok + a CSRF token', $g['code'] === 200 && ($g['json']['ok'] ?? false) === true && preg_match('/^[a-f0-9]{64}$/', $tok) === 1, 'HTTP ' . $g['code']);
    aa_check('the token answer is never cached', str_contains($g['cache'], 'no-store'), $g['cache']);
    aa_check('the same session gets the same token', aa_token($jar) === $tok);

    echo "\n=== 2. refusals ===\n";
    $r = aa_post($jar, '', ['type' => 'ind', 'lang' => 'en', 'fields' => $indFields]);
    aa_check('POST without a token = 419', $r['code'] === 419, 'HTTP ' . $r['code']);
    $r = aa_post($jar, str_repeat('a', 64), ['type' => 'ind', 'lang' => 'en', 'fields' => $indFields]);
    aa_check('POST with a wrong token = 419', $r['code'] === 419, 'HTTP ' . $r['code']);
    $r = aa_post(aa_jar('other'), $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => $indFields]);
    aa_check('a token from another session = 419', $r['code'] === 419, 'HTTP ' . $r['code']);
    $r = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => []]);
    aa_check('missing fields = 422', $r['code'] === 422 && ($r['json']['ok'] ?? true) === false, 'HTTP ' . $r['code']);
    aa_check('…naming p_name, p_phone, p_area, p_idno and p_addr', array_keys($r['json']['fields'] ?? []) === ['p_name', 'p_phone', 'p_area', 'p_idno', 'p_addr'],
        implode(',', array_keys($r['json']['fields'] ?? [])));
    $r = aa_post($jar, $tok, ['type' => 'org', 'lang' => 'ne', 'fields' => ['p_name' => 'X', 'p_phone' => '9999700001', 'p_area' => 'Y']]);
    aa_check('an organization without its representative = 422 on p_rep, in Nepali',
        $r['code'] === 422 && array_keys($r['json']['fields'] ?? []) === ['p_rep'] && ($r['json']['fields']['p_rep'] ?? '') === 'यो भर्न जरुरी छ।');
    $r = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => ['p_phone' => '12345'] + $indFields]);
    aa_check('a 5-digit mobile = 422 on p_phone', $r['code'] === 422 && isset($r['json']['fields']['p_phone']));
    aa_reset_limits();

    echo "\n=== 3. two good applications ===\n";
    $r = aa_post($jar, $tok, ['type' => 'org', 'lang' => 'en', 'fields' => $orgFields,
        'photo' => 'data:image/png;base64,' . AA_PNG, 'public_ok' => false, 'website' => '']);
    $orgRef = (string) ($r['json']['ref'] ?? '');
    aa_check('a valid organization = ok + AGR ref', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true && preg_match('/^AGR-\d{6}$/', $orgRef) === 1,
        'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 160));
    aa_check('the answer carries only ok / ref / message (+ duplicate, photo_saved)',
        array_diff(array_keys($r['json'] ?? []), ['ok', 'ref', 'message', 'duplicate', 'photo_saved']) === []);
    aa_check('the answer echoes nothing stored', !str_contains($r['body'], 'Hari Prasad') && !str_contains($r['body'], '9999700001')
        && !str_contains($r['body'], 'agrtest@example.com') && !str_contains($r['body'], 'GST-AGRTEST'));
    $org = aa_one('SELECT * FROM agent_applications WHERE ref = ?', [$orgRef]);
    $of  = $org !== null ? (json_decode((string) $org['fields_json'], true) ?: []) : [];
    aa_check('the row is stored as an organization with the normalised phone', $org !== null && $org['type'] === 'org'
        && $org['phone'] === '9999700001' && $org['name'] === 'AgrTest Travels Pvt Ltd' && $org['area'] === 'Surat' && $org['status'] === 'new');
    aa_check('fields_json holds the posted fields', ($of['p_rep'] ?? '') === 'Hari Prasad' && ($of['b_acc'] ?? '') === '000111222333' && ($of['c73'] ?? '') === '1');
    aa_check('an unknown field name is not stored', !array_key_exists('evil_field', $of));
    aa_check('a long value is cut at 300 characters', strlen((string) ($of['p_addr'] ?? '')) === 300);
    aa_check('the photo is kept in the database as a PNG data URI', str_starts_with((string) ($org['photo'] ?? ''), 'data:image/png;base64,')
        && ($r['json']['photo_saved'] ?? false) === true);
    aa_check('email stored, public_ok off, KYC "submitted"', $org !== null && $org['email'] === 'agrtest@example.com'
        && (int) $org['public_ok'] === 0 && $org['kyc_status'] === 'submitted');

    $indBody = ['type' => 'ind', 'lang' => 'ne', 'fields' => $indFields,
        'photo' => 'data:image/png;base64,' . base64_encode('this is not a picture'), 'public_ok' => true];
    $r = aa_post($jar, $tok, $indBody);
    $indRef = (string) ($r['json']['ref'] ?? '');
    $ind = aa_one('SELECT * FROM agent_applications WHERE ref = ?', [$indRef]);
    aa_check('a valid individual = ok + a different ref', $r['code'] === 200 && preg_match('/^AGR-\d{6}$/', $indRef) === 1 && $indRef !== $orgRef,
        'HTTP ' . $r['code'] . ' ' . substr($r['body'], 0, 160));
    aa_check('+91 and dashes are stored as the plain 10 digits, as typed kept too', $ind !== null && $ind['phone'] === '9999700002' && $ind['phone_raw'] === '+91 99997-00002');
    aa_check('a bad photo is dropped, the application still goes through', $ind !== null && $ind['photo'] === null
        && ($r['json']['photo_saved'] ?? true) === false && str_contains((string) ($r['json']['message'] ?? ''), 'फोटो'));
    aa_check('the Nepali form gets a Nepali answer with the ref', str_contains((string) ($r['json']['message'] ?? ''), $indRef)
        && str_contains((string) ($r['json']['message'] ?? ''), 'आवेदन'));
    aa_check('consent to show the full phone is stored', $ind !== null && (int) $ind['public_ok'] === 1 && $ind['lang'] === 'ne');

    echo "\n=== 4. retries, repeats, honeypot, limits ===\n";
    $r = aa_post($jar, $tok, $indBody);
    $n = (int) (aa_one("SELECT COUNT(*) AS n FROM agent_applications WHERE phone = '9999700002'")['n'] ?? 0);
    aa_check('exactly the same form again = the same ref, one row', $r['code'] === 200 && ($r['json']['ref'] ?? '') === $indRef
        && ($r['json']['duplicate'] ?? false) === true && $n === 1, ($r['json']['ref'] ?? '') . ' rows=' . $n);
    aa_check('…with the "already reached us" line and the ref', str_contains((string) ($r['json']['message'] ?? ''), $indRef)
        && str_contains((string) ($r['json']['message'] ?? ''), 'पुगिसकेको'));

    $r = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => ['p_area' => 'Patan'] + $indFields]);
    $newRef = (string) ($r['json']['ref'] ?? '');
    $rows = aa_one("SELECT COUNT(*) AS n, MAX(duplicate_of) AS d FROM agent_applications WHERE phone = '9999700002'");
    aa_check('a DIFFERENT form from the same phone = a new ref, never the earlier one', $r['code'] === 200 && preg_match('/^AGR-\d{6}$/', $newRef) === 1
        && $newRef !== $indRef && ($r['json']['duplicate'] ?? true) === false && !str_contains($r['body'], $indRef), $r['body']);
    aa_check('…the plain "received" answer', str_contains((string) ($r['json']['message'] ?? ''), 'Application received'));
    aa_check('…and both forms are kept, the new one marked as a repeat of the first',
        (int) ($rows['n'] ?? 0) === 2 && (int) ($rows['d'] ?? 0) === (int) ($ind['id'] ?? -1), json_encode($rows));

    // The honeypot: a valid-looking form with the hidden box filled.
    $hpBody = ['type' => 'ind', 'lang' => 'en', 'website' => 'http://spam.example', 'fields' => ['p_phone' => $PH['hp']] + $indFields];
    $top = (int) (aa_one('SELECT COALESCE(MAX(id), 0) AS m FROM agent_applications')['m'] ?? 0);
    $r = aa_post($jar, $tok, $hpBody);
    $hpRef = (string) ($r['json']['ref'] ?? '');
    $hpN   = (int) substr($hpRef, 4);
    $n = (int) (aa_one('SELECT COUNT(*) AS n FROM agent_applications WHERE phone = ?', [$PH['hp']])['n'] ?? 0);
    aa_check('the honeypot answers ok with a real-looking ref', $r['code'] === 200 && preg_match('/^AGR-\d{6}$/', $hpRef) === 1);
    aa_check('…of the same shape as a real answer', array_keys($r['json'] ?? []) === ['ok', 'ref', 'message', 'duplicate', 'photo_saved']
        && ($r['json']['duplicate'] ?? null) === false && ($r['json']['photo_saved'] ?? null) === false, implode(',', array_keys($r['json'] ?? [])));
    aa_check('…its number just past the real ones', $hpN >= $top + 1 && $hpN <= $top + 3, "$hpRef (top id $top)");
    aa_check('…and stores nothing', $n === 0);
    $r = aa_post($jar, $tok, $hpBody);
    aa_check('the same form to the honeypot again = the same ref, "duplicate", like a real resend',
        ($r['json']['ref'] ?? '') === $hpRef && ($r['json']['duplicate'] ?? false) === true);
    $r = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'website' => 'x', 'fields' => []]);
    aa_check('a wrong form with the hidden box filled = 422, as for a person', $r['code'] === 422);

    aa_reset_limits();
    $codes = [];
    for ($i = 1; $i <= 8; $i++) {
        $codes[] = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => []])['code'];
    }
    aa_check('8 wrong tries in a row are all 422 — fixing the form never locks a person out', $codes === array_fill(0, 8, 422), implode(',', $codes));
    $myIp = aa_my_ip();
    aa_fill('agr_apply_ip', $myIp, 30);
    $r = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => []]);
    aa_check('the 31st try in an hour from one address = 429', $r['code'] === 429, 'HTTP ' . $r['code'] . ' ip=' . $myIp);
    aa_reset_limits();

    $codes = [];
    for ($i = 1; $i <= 4; $i++) {
        $codes[] = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => ['p_phone' => $PH['lim'], 'p_area' => 'Area ' . $i] + $indFields])['code'];
    }
    aa_check('3 stored a day per phone, the 4th different form = 429', $codes === [200, 200, 200, 429], implode(',', $codes));
    $r = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => ['p_phone' => $PH['lim'], 'p_area' => 'Area 1'] + $indFields]);
    aa_check('…but an exact resend of one of them still gets its ref back (not counted)', $r['code'] === 200 && ($r['json']['duplicate'] ?? false) === true,
        'HTTP ' . $r['code']);
    aa_reset_limits();

    aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => []]);   // makes the per-IP row, so the address is known
    aa_fill('agr_apply_ip_ok', aa_my_ip(), 10);
    $r = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => ['p_phone' => $PH['cap'], 'p_area' => 'Cap 1'] + $indFields]);
    aa_check('the 11th stored form in an hour from one address = 429', $r['code'] === 429, 'HTTP ' . $r['code']);
    aa_reset_limits();
    aa_fill('agr_apply_all_h', 'global', 30);
    $r = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => ['p_phone' => $PH['cap'], 'p_area' => 'Cap 2'] + $indFields]);
    $n = (int) (aa_one('SELECT COUNT(*) AS n FROM agent_applications WHERE phone = ?', [$PH['cap']])['n'] ?? 0);
    aa_check('the whole site past 30 stored an hour = 429, nothing stored', $r['code'] === 429 && $n === 0, 'HTTP ' . $r['code'] . ' rows=' . $n);
    aa_reset_limits();

    $r = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => ['p_phone' => $PH['cap'], 'p_area' => 'Cap 3'] + $indFields]);
    $note = aa_one("SELECT hits FROM rate_limits WHERE bucket = 'agr_note' AND identifier = 'global'");
    aa_check('a stored form counts one office note', $r['code'] === 200 && (int) ($note['hits'] ?? 0) === 1, json_encode($note));
    aa_fill('agr_note', 'global', 10);
    $r = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => ['p_phone' => $PH['cap'], 'p_area' => 'Cap 4'] + $indFields]);
    aa_check('past 10 notes an hour the form is still stored (only the note is skipped)', $r['code'] === 200 && ($r['json']['ok'] ?? false) === true,
        'HTTP ' . $r['code']);
    aa_reset_limits();

    $big = aa_post($jar, $tok, ['type' => 'ind', 'lang' => 'en', 'fields' => $indFields, 'photo' => 'data:image/jpeg;base64,' . str_repeat('A', 3_600_000)]);
    aa_check('a body over 3.5 MB = 413 (22 Oct 2026: room for a partner\'s two certificates)', $big['code'] === 413, 'HTTP ' . $big['code']);
    aa_reset_limits();

    echo "\n=== 5. the public list ===\n";
    $dj = aa_jar('dir');
    $d = aa_req('GET', '/api/agent-directory.php', $dj);
    $names = array_column($d['json']['items'] ?? [], null, 'name');
    aa_check('GET = ok with count and items', $d['code'] === 200 && ($d['json']['ok'] ?? false) === true
        && (int) ($d['json']['count'] ?? -1) === count($d['json']['items'] ?? []));
    aa_check('a first visit starts a session: cached 5 minutes by that browser only, never "public" next to a Set-Cookie',
        $d['cookie'] && $d['cache'] === 'private, max-age=300', ($d['cookie'] ? 'Set-Cookie + ' : 'no cookie + ') . $d['cache']);
    $d2 = aa_req('GET', '/api/agent-directory.php', $dj);
    aa_check('a visit that sets no cookie may be cached by anyone for 5 minutes',
        $d2['cache'] === ($d2['cookie'] ? 'private, max-age=300' : 'public, max-age=300') && !$d2['cookie'],
        ($d2['cookie'] ? 'Set-Cookie + ' : 'no cookie + ') . $d2['cache']);
    aa_check('a new (unapproved) application is not listed', !isset($names['AgrTest Travels Pvt Ltd']) && !isset($names['AgrTest Person']));
    aa_exec("UPDATE agent_applications SET status = 'approved', kyc_status = 'verified' WHERE ref IN (?, ?)", [$orgRef, $indRef]);
    $d = aa_req('GET', '/api/agent-directory.php', aa_jar('dir2'));
    $names = array_column($d['json']['items'] ?? [], null, 'name');
    aa_check('approved without consent = listed with a masked phone', ($names['AgrTest Travels Pvt Ltd']['phone'] ?? '') === '99•••••001'
        && ($names['AgrTest Travels Pvt Ltd']['kind'] ?? '') === 'partner', json_encode($names['AgrTest Travels Pvt Ltd'] ?? null, JSON_UNESCAPED_UNICODE));
    aa_check('approved with consent = listed with the full phone', ($names['AgrTest Person']['phone'] ?? '') === '9999700002'
        && ($names['AgrTest Person']['kind'] ?? '') === 'agent' && ($names['AgrTest Person']['verified'] ?? false) === true);
    $badKey = false;
    foreach ($d['json']['items'] ?? [] as $it) {
        // 19 Oct 2026: + public address, photo URL, WhatsApp; 22 Oct 2026: + a partner's logo URL
        if (array_keys($it) !== ['name', 'kind', 'area', 'phone', 'verified', 'signed', 'addr', 'photo', 'logo', 'wa']) { $badKey = true; }
        if (($it['photo'] !== '' && !preg_match('~^/api/agent-photo\.php\?k=[a-f0-9]{20}$~', $it['photo']))
            || ($it['logo'] !== '' && !preg_match('~^/api/agent-photo\.php\?k=[a-f0-9]{20}&v=logo$~', $it['logo']))) { $badKey = true; }
    }
    aa_check('every item has exactly name / kind / area / phone / verified / signed / addr / photo / logo / wa', !$badKey);
    aa_check('no email, fields, id, address or ID number anywhere', !preg_match('/"(email|fields|fields_json|id|ref|status|note)"\s*:/', $d['body'])
        && !str_contains($d['body'], 'agrtest@example.com') && !str_contains($d['body'], 'CIT-AGRTEST') && !str_contains($d['body'], 'base64')
        && !str_contains($d['body'], 'Test Tole') && !str_contains($d['body'], '000111222333'));
    $p = aa_req('POST', '/api/agent-directory.php', aa_jar('dir3'), ['json' => []]);
    aa_check('the list only answers GET', $p['code'] === 405, 'HTTP ' . $p['code']);
    aa_exec("UPDATE agent_applications SET status = 'new', kyc_status = 'submitted' WHERE ref IN (?, ?)", [$orgRef, $indRef]);

    echo "\n=== 6. the office page ===\n";
    foreach ($USERS as $u => $role) { aa_staff($u, $role, 'AgrTest ' . ucfirst($role)); }
    $g = aa_req('GET', '/admin/agreements.php', aa_jar('anon'));
    aa_check('a stranger is sent to the sign-in page', $g['code'] === 302 && str_contains($g['location'], '/admin/login.php?next=agreements.php'), $g['code'] . ' ' . $g['location']);

    $sj = aa_jar('super');
    aa_check('the super-admin signs in', aa_login('agr-super', $sj));
    $g = aa_req('GET', '/admin/agreements.php', $sj);
    aa_check('…and sees the new application by its ref', $g['code'] === 200 && str_contains($g['body'], $orgRef) && str_contains($g['body'], $indRef), 'HTTP ' . $g['code']);
    $orgId = (int) ($org['id'] ?? 0);
    $auditBefore = (int) (aa_one("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'agreement_app.view' AND entity_id = ?", [$orgRef])['n'] ?? 0);
    $g = aa_req('GET', '/admin/agreements.php?tab=apps&id=' . $orgId, $sj);
    aa_check('opening it shows every field in plain words and the photo', $g['code'] === 200 && str_contains($g['body'], 'Hari Prasad')
        && str_contains($g['body'], 'Pvt. Ltd.') && str_contains($g['body'], 'Witness Ram') && str_contains($g['body'], 'src="data:image/png;base64,'));
    aa_check('…the bank account in full for the super-admin', str_contains($g['body'], '000111222333'));
    $auditAfter = (int) (aa_one("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'agreement_app.view' AND entity_id = ?", [$orgRef])['n'] ?? 0);
    aa_check('…and that opening is written to the activity log', $auditAfter === $auditBefore + 1, "$auditBefore -> $auditAfter");
    aa_check('a number typed without +91 / +977 offers both WhatsApp links', str_contains($g['body'], 'https://wa.me/919999700001')
        && str_contains($g['body'], 'https://wa.me/9779999700001'));
    $csrf = preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $g['body'], $m) ? $m[1] : '';
    aa_check('the page carries its CSRF field', $csrf !== '');
    $indId = (int) ($ind['id'] ?? 0);
    $g = aa_req('GET', '/admin/agreements.php?tab=apps&id=' . $indId, $sj);
    aa_check('a number typed with +91 gets the Indian WhatsApp link only', str_contains($g['body'], 'https://wa.me/919999700002')
        && !str_contains($g['body'], 'https://wa.me/9779999700002'));
    $g = aa_req('GET', '/admin/agreements.php?tab=apps', $sj);
    aa_check('the list marks the second form from the same phone as a repeat', str_contains($g['body'], 'again after ' . $indRef));

    $p = aa_req('POST', '/admin/agreements.php?tab=apps&id=' . $orgId, $sj, ['form' => [
        'action' => 'app', 'id' => $orgId, 'status' => 'approved', 'kyc_status' => 'verified',
    ]]);
    $now = aa_one('SELECT status, kyc_status FROM agent_applications WHERE id = ?', [$orgId]);
    aa_check('a POST without CSRF is refused and changes nothing', $p['code'] === 419 && ($now['status'] ?? '') === 'new' && ($now['kyc_status'] ?? '') === 'submitted',
        'HTTP ' . $p['code'] . ' status=' . ($now['status'] ?? '?'));

    $p = aa_req('POST', '/admin/agreements.php?tab=apps&id=' . $orgId, $sj, ['form' => [
        'shg_csrf' => $csrf, 'action' => 'app', 'id' => $orgId, 'status' => 'agreement_pending', 'kyc_status' => 'verified',
        'agreement_status' => 'signed', 'agreement_signed_at' => '2026-09-27', 'note' => 'AgrTest called', 'admin_id' => '0',
    ]]);
    $now = aa_one('SELECT status, kyc_status, agreement_status, agreement_signed_at, agreement_end, note, reviewed_by FROM agent_applications WHERE id = ?', [$orgId]);
    aa_check('with CSRF the change is saved and the page redirects back', $p['code'] === 302 && str_contains($p['location'], 'agreements.php?tab=apps&id=' . $orgId)
        && ($now['status'] ?? '') === 'agreement_pending' && ($now['kyc_status'] ?? '') === 'verified' && ($now['note'] ?? '') === 'AgrTest called',
        'HTTP ' . $p['code'] . ' ' . $p['location']);
    aa_check('signed 27 Sep 2026 → ends 27 Aug 2027 (11 months)', ($now['agreement_status'] ?? '') === 'signed'
        && ($now['agreement_signed_at'] ?? '') === '2026-09-27' && ($now['agreement_end'] ?? '') === '2027-08-27');
    aa_check('the change is stamped with who made it', (int) ($now['reviewed_by'] ?? 0) === (int) (aa_one('SELECT id FROM admins WHERE username = ?', ['agr-super'])['id'] ?? -1));

    $g = aa_req('GET', '/admin/agreements.php?tab=agents', $sj);
    aa_check('tab 2 lists the existing agents with Agent 360 links', $g['code'] === 200 && str_contains($g['body'], 'AgrTest Agent')
        && str_contains($g['body'], 'agent-360.php?agent='));
    $agentId = (int) (aa_one('SELECT id FROM admins WHERE username = ?', ['agr-agent'])['id'] ?? 0);
    $csrf2 = preg_match('/name="shg_csrf" value="([a-f0-9]+)"/', $g['body'], $m) ? $m[1] : $csrf;
    $p = aa_req('POST', '/admin/agreements.php?tab=agents', $sj, ['form' => [
        'shg_csrf' => $csrf2, 'action' => 'agent', 'admin_id' => $agentId, 'agreement_status' => 'sent', 'public_phone' => '1',
    ]]);
    $prof = aa_one('SELECT agreement_status, public_listing, public_phone FROM admin_profiles WHERE admin_id = ?', [$agentId]);
    aa_check('an agent\'s agreement and list switches save (unticked "listed" = off)', $p['code'] === 302 && ($prof['agreement_status'] ?? '') === 'sent'
        && (int) ($prof['public_phone'] ?? 0) === 1 && (int) ($prof['public_listing'] ?? 1) === 0, 'HTTP ' . $p['code']);

    $uj = aa_jar('support');
    aa_login('agr-support', $uj);
    $g = aa_req('GET', '/admin/agreements.php', $uj);
    aa_check('support staff may look', $g['code'] === 200 && str_contains($g['body'], $orgRef), 'HTTP ' . $g['code']);
    $auditBefore = (int) (aa_one("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'agreement_app.view' AND entity_id = ?", [$orgRef])['n'] ?? 0);
    $gs = aa_req('GET', '/admin/agreements.php?tab=apps&id=' . $orgId, $uj);
    aa_check('…an application opens for them with name and phone', $gs['code'] === 200 && str_contains($gs['body'], 'AgrTest Travels Pvt Ltd')
        && str_contains($gs['body'], '9999700001'));
    aa_check('…but the bank account only as its last 4 digits', !str_contains($gs['body'], '000111222333') && str_contains($gs['body'], '••••2333'));
    aa_check('…and no photo', !str_contains($gs['body'], 'data:image/') && str_contains($gs['body'], 'shown to a manager only'));
    $gi = aa_req('GET', '/admin/agreements.php?tab=apps&id=' . $indId, $uj);
    aa_check('…an individual\'s ID number masked and home address hidden', $gi['code'] === 200 && !str_contains($gi['body'], 'CIT-AGRTEST-9')
        && str_contains($gi['body'], '••••ST-9') && !str_contains($gi['body'], 'Test Tole 7'));
    $auditAfter = (int) (aa_one("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'agreement_app.view' AND entity_id = ?", [$orgRef])['n'] ?? 0);
    aa_check('…a masked view is not logged as a KYC opening', $auditAfter === $auditBefore);
    $csrf3 = preg_match('/name="csrf" content="([a-f0-9]+)"/', $g['body'], $m) ? $m[1] : '';
    $p = aa_req('POST', '/admin/agreements.php?tab=apps&id=' . $orgId, $uj, ['form' => [
        'shg_csrf' => $csrf3, 'action' => 'app', 'id' => $orgId, 'status' => 'rejected',
    ]]);
    $now = aa_one('SELECT status FROM agent_applications WHERE id = ?', [$orgId]);
    aa_check('…but not change (403, status kept)', $csrf3 !== '' && $p['code'] === 403 && ($now['status'] ?? '') === 'agreement_pending', 'HTTP ' . $p['code']);

    $aj = aa_jar('agent');
    aa_login('agr-agent', $aj);
    $g = aa_req('GET', '/admin/agreements.php', $aj);
    aa_check('a counter agent may not open the office page', $g['code'] === 403, 'HTTP ' . $g['code']);
} catch (Throwable $e) {
    aa_check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    try { $cleanup(); } catch (Throwable $e) {}
    try {
        $ids = [];
        foreach (array_keys($USERS) as $u) {
            $r = aa_one('SELECT id FROM admins WHERE username = ?', [$u]);
            if ($r !== null) { $ids[] = (int) $r['id']; }
        }
        foreach ($ids as $id) { aa_exec('DELETE FROM admin_profiles WHERE admin_id = ?', [$id]); }
        // Deactivated, not deleted: login logs point at them.
        $in = implode(',', array_fill(0, count($USERS), '?'));
        aa_exec("UPDATE admins SET is_active = 0 WHERE username IN ($in)", array_keys($USERS));
    } catch (Throwable $e) {}
    try {
        if ($notifyWas !== null) { aa_exec("UPDATE settings SET svalue = ? WHERE skey = 'whatsapp_notify_admin'", [$notifyWas]); }
        aa_reset_limits();
    } catch (Throwable $e) {}
    foreach (glob(sys_get_temp_dir() . '/shg_aa_*_' . getmypid() . '.cookies') ?: [] as $f) { @unlink($f); }
}

echo "\n----------------------------------------\n";
echo "  $pass passed, $fail failed\n";
echo "----------------------------------------\n\n";
exit($fail === 0 ? 0 : 1);
