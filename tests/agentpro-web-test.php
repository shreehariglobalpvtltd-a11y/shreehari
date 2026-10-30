<?php
/**
 * =====================================================================
 *  agentpro-web-test.php — approved AgentPro members on the home page
 *  (31 Oct 2026, includes/agentpro-web.php ApWeb + api/ap-photo.php and
 *  the three public lists). Writes throwaway rows to the configured DB
 *  (dev: idc_test) and removes every one of them at the end.
 *
 *    php -c .claude/php-dev.ini tests/agentpro-web-test.php [http://127.0.0.1:8953]
 *
 *  Without a URL it starts `php -S 127.0.0.1:8953` itself (same php.ini)
 *  and stops it at the end.
 *
 *    - the automatic rule: approved join form / KYC verified + photo → shown;
 *      unverified, no photo, super-admin → not shown
 *    - ApWeb::list / directory / staffDirectory shapes, merge de-dup
 *    - over HTTP: top-people, agent-directory, staff-list carry the member,
 *      an older-system row with the same phone is dropped; 2-minute cache
 *    - ap-photo.php: JPEG ≤ 400 px, day cache + ETag (304), 404 when hidden
 *    - web_show 0 hides, 1 shows an unverified member; is_active 0, archived,
 *      card suspended all hide; the toggle is audited
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro-web.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? ' — ' . mb_substr($extra, 0, 400) : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }

/** @return array{0: int, 1: string, 2: array<string, string>, 3: mixed} */
function http(string $url, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_NOPROXY => '*', CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers)]);
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $h = [];
    foreach (explode("\r\n", substr($raw, 0, $hs)) as $line) {
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $h[strtolower(trim($k))] = trim($v); }
    }
    $body = substr($raw, $hs);
    return [$code, $body, $h, json_decode($body, true)];
}

/** @param list<array<string, mixed>> $list */
function names(array $list): array { return array_map(static fn($x): string => (string) ($x['name'] ?? ''), $list); }
function last10(string $s): string { $d = preg_replace('/\D/', '', $s) ?? ''; return strlen($d) >= 10 ? substr($d, -10) : ''; }

/* ---------------- the server ---------------- */
$BASE = rtrim($argv[1] ?? '', '/');
$server = null;
if ($BASE === '') {
    $BASE = 'http://127.0.0.1:8953';
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $cmd = [PHP_BINARY];
    if (($ini = php_ini_loaded_file()) !== false) { $cmd[] = '-c'; $cmd[] = $ini; }
    array_push($cmd, '-S', '127.0.0.1:8953', '-t', ROOT_PATH);
    $server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) {
        usleep(200000);
        $up = http($BASE . '/api/ap-photo.php?k=0')[0] > 0;
    }
    if (!$up) { echo "Could not start php -S on 127.0.0.1:8953\n"; exit(1); }
}

/* ---------------- fixtures ---------------- */
$tag = strtoupper(bin2hex(random_bytes(3)));
$made = ['admins' => [], 'files' => [], 'agent_app' => [], 'staff_app' => []];
$archiveKeyAdded = null;

$photo = static function () use (&$made): string {
    $dir = UPLOAD_PATH . '/agents';
    ensureDir($dir);
    $img = imagecreatetruecolor(700, 500);
    imagefilledrectangle($img, 0, 0, 699, 499, imagecolorallocate($img, 30, 90, 160));
    $name = 'apwebtest_' . bin2hex(random_bytes(6)) . '.jpg';
    imagejpeg($img, $dir . '/' . $name, 85);
    imagedestroy($img);
    $made['files'][] = $dir . '/' . $name;
    return 'agents/' . $name;
};
$phone = static fn(): string => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
/**
 * @param array{kind?: string, photo?: bool, kyc?: string, approved?: bool} $o
 */
$member = static function (string $role, string $name, array $o = []) use (&$made, $photo, $phone): array {
    $ph = $phone();
    $id = Database::insert('admins', [
        'username' => 'apwebt_' . bin2hex(random_bytes(5)), 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => $name, 'phone' => $ph, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0,
    ]);
    $made['admins'][] = $id;
    Database::insert('admin_profiles', [
        'admin_id' => $id, 'whatsapp' => $ph, 'display_phone' => $ph, 'country_code' => '91', 'counter_name' => 'Testpur ' . substr($name, -6),
        'agent_kind' => $o['kind'] ?? 'person', 'photo_path' => ($o['photo'] ?? true) ? $photo() : null, 'kyc_status' => $o['kyc'] ?? 'none',
    ]);
    AgentPro::ensureMember($id, $role);
    if ($o['approved'] ?? false) {
        Database::insert('ap_applications', [
            'ref' => 'JT-' . strtoupper(bin2hex(random_bytes(4))), 'draft_key' => bin2hex(random_bytes(16)), 'status' => 'approved',
            'kind' => $role === 'agent' ? 'agent' : 'staff', 'agent_kind' => $o['kind'] ?? 'person', 'full_name' => $name, 'phone' => $ph,
            'admin_id' => $id, 'submitted_at' => date('Y-m-d H:i:s'), 'decided_at' => date('Y-m-d H:i:s'),
        ]);
    }
    return ['id' => $id, 'name' => $name, 'phone' => $ph];
};
$archive = static function (int $id, bool $on): void {
    Database::query("INSERT IGNORE INTO settings (skey,svalue,stype,sgroup,is_public) VALUES ('team_manager_archive','{}','json','agent',0)");
    $map = json_decode((string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'team_manager_archive'", [], '{}'), true);
    $map = is_array($map) ? $map : [];
    if ($on) { $map['account:' . $id] = ['at' => date('c'), 'by' => 0, 'active' => 1, 'card' => 'active', 'listing' => 0, 'reason' => 'apweb test', 'src' => 'test']; }
    else { unset($map['account:' . $id]); }
    Settings::set('team_manager_archive', $map, 'json', 'agent', false);
    Settings::flush();
};

try {
    section('fixtures');
    Database::query("DELETE FROM rate_limits WHERE bucket IN ('top_people', 'agent_directory', 'staff_list', 'ap_photo')");
    $A  = $member('agent', "Apweb Agent $tag", ['approved' => true]);                         // approved join form
    $S  = $member('counter', "Apweb Counter $tag", ['kyc' => 'verified']);                    // KYC verified, no form
    $P  = $member('agent', "Apweb Agency $tag", ['kind' => 'org', 'approved' => true]);       // partner agency
    $U  = $member('support', "Apweb Unverified $tag", ['kyc' => 'submitted']);                // not checked yet
    $N  = $member('agent', "Apweb Nophoto $tag", ['approved' => true, 'photo' => false]);
    $SA = $member('superadmin', "Apweb Boss $tag", ['kyc' => 'verified']);
    // the older systems: the same person under another name (same mobile)
    $made['agent_app'][] = Database::insert('agent_applications', [
        'type' => 'ind', 'name' => "Old Dup Agent $tag", 'phone' => $A['phone'], 'phone_raw' => '+91' . $A['phone'], 'public_ok' => 1,
        'status' => 'approved', 'kyc_status' => 'verified', 'admin_id' => $A['id'], 'web_live' => 1,
    ]);
    $made['staff_app'][] = Database::insert('staff_applications', [
        'full_name' => "Old Dup Staff $tag", 'phone' => $S['phone'], 'phone_country' => 'IN', 'role_applied' => 'office',
        'id_type' => 'pan', 'id_number' => 'TEST' . $tag, 'status' => 'approved', 'show_on_website' => 1,
    ]);
    check('fixtures made', count($made['admins']) === 6);

    section('the automatic rule');
    ApWeb::flush();
    check('approved join form + photo → shown', ApWeb::shown($A['id']), json_encode(ApWeb::status($A['id'])));
    check('KYC verified (no form) + photo → shown', ApWeb::shown($S['id']), json_encode(ApWeb::status($S['id'])));
    check('partner agency shown', ApWeb::shown($P['id']));
    check('unverified → not shown (unverified)', ApWeb::status($U['id'])['why'] === 'unverified');
    check('no photo → not shown (no_photo)', ApWeb::status($N['id'])['why'] === 'no_photo');
    check('super-admin → not shown automatically', ApWeb::status($SA['id'])['why'] === 'superadmin');
    check('status: auto mode, ready', ApWeb::status($A['id'])['mode'] === 'auto' && ApWeb::status($A['id'])['ready'] === true);
    $many = ApWeb::shownMany([$A['id'], $U['id']]);
    check('shownMany', $many[$A['id']] === true && $many[$U['id']] === false);
    check('not a member → not_member', ApWeb::status(2147483000)['why'] === 'not_member');

    section('the shapes');
    $ag = array_values(array_filter(ApWeb::list('agent'), static fn($x) => $x['name'] === $A['name']));
    check('list(agent) has the agent', count($ag) === 1);
    $a0 = $ag[0] ?? [];
    check('top shape keys', array_keys($a0) === ['name', 'role', 'area', 'photo', 'phone', 'tel', 'wa', 'metric'], implode(',', array_keys($a0)));
    check('photo URL /api/ap-photo.php?k=<20 hex>', preg_match('~^/api/ap-photo\.php\?k=[a-f0-9]{20}$~', (string) ($a0['photo'] ?? '')) === 1);
    check('visiting-card number (wa 91…, tel +91…)', ($a0['wa'] ?? '') === '91' . $A['phone'] && ($a0['tel'] ?? '') === '+91' . $A['phone'], json_encode($a0));
    check('agent role text', ($a0['role'] ?? '') === 'एजेन्ट · Ticketing Agent', (string) ($a0['role'] ?? ''));
    check('branch as area', str_starts_with((string) ($a0['area'] ?? ''), 'Testpur'));
    check('list(agent) leaves out the agency and staff', !in_array($P['name'], names(ApWeb::list('agent')), true) && !in_array($S['name'], names(ApWeb::list('agent')), true));
    check('list(partner) has the agency', in_array($P['name'], names(ApWeb::list('partner')), true));
    $st = array_values(array_filter(ApWeb::list('staff'), static fn($x) => $x['name'] === $S['name']))[0] ?? [];
    check('staff role text + verified metric', ($st['role'] ?? '') === 'काउन्टर · Counter Executive' && str_contains((string) ($st['metric'] ?? ''), 'Office verified'), json_encode($st, JSON_UNESCAPED_UNICODE));
    $d = array_values(array_filter(ApWeb::directory(), static fn($x) => $x['name'] === $P['name']))[0] ?? [];
    check('directory shape + partner kind', array_keys($d) === ['name', 'kind', 'area', 'phone', 'verified', 'signed', 'addr', 'photo', 'logo', 'wa'] && $d['kind'] === 'partner' && $d['addr'] === '');
    $sd = array_values(array_filter(ApWeb::staffDirectory(), static fn($x) => $x['name'] === $S['name']))[0] ?? [];
    check('staff directory shape (role key counter)', array_keys($sd) === ['name', 'role', 'roleEn', 'roleNe', 'district', 'loc', 'photo', 'phone', 'tel', 'wa'] && $sd['role'] === 'counter' && $sd['roleEn'] === 'Counter Executive', json_encode($sd, JSON_UNESCAPED_UNICODE));
    check('list(limit) honoured', count(ApWeb::list('agent', 1)) <= 1);
    $m = ApWeb::merge([['name' => 'Ram', 'wa' => '919800000001']],
        [['name' => 'Other', 'phone' => '+91 98000 00001'], ['name' => 'RAM ', 'wa' => ''], ['name' => 'Masked', 'phone' => '98•••••001'], ['name' => 'Sita', 'tel' => '+9779800000002']]);
    check('merge: same phone / same name dropped, masked kept', names($m) === ['Ram', 'Masked', 'Sita'], implode(',', names($m)));
    check('merge: max', count(ApWeb::merge([['name' => 'a'], ['name' => 'b']], [['name' => 'c']], 2)) === 2);

    section('over HTTP');
    [$c, , $h, $j] = http($BASE . '/api/top-people.php');
    check('top-people 200 + 2-minute cache', $c === 200 && str_contains($h['cache-control'] ?? '', 'max-age=120'), $c . ' ' . ($h['cache-control'] ?? ''));
    check('top-people: AgentPro agent in Top 3', in_array($A['name'], names($j['agent'] ?? []), true), json_encode($j['agent'] ?? null, JSON_UNESCAPED_UNICODE));
    check('top-people: older card with the same phone dropped', !in_array("Old Dup Agent $tag", names($j['agent'] ?? []), true));
    check('top-people: staff + partner', in_array($S['name'], names($j['staff'] ?? []), true) && in_array($P['name'], names($j['partner'] ?? []), true));
    check('top-people: at most 3 each', count($j['agent'] ?? []) <= 3 && count($j['staff'] ?? []) <= 3);
    [$c, , $h, $j] = http($BASE . '/api/agent-directory.php');
    $items = $j['items'] ?? [];
    $same = array_filter($items, static fn($x) => last10((string) ($x['wa'] ?? '')) === $A['phone'] || $x['name'] === $A['name']);
    check('agent-directory 200 + 2-minute cache', $c === 200 && str_contains($h['cache-control'] ?? '', 'max-age=120'));
    check('agent-directory: the agent once, the older card dropped', count($same) === 1 && !in_array("Old Dup Agent $tag", names($items), true), json_encode(array_values($same), JSON_UNESCAPED_UNICODE));
    check('agent-directory: agency as partner', (array_values(array_filter($items, static fn($x) => $x['name'] === $P['name']))[0]['kind'] ?? '') === 'partner');
    check('agent-directory: count matches', ($j['count'] ?? -1) === count($items));
    [$c, , , $j] = http($BASE . '/api/staff-list.php');
    $sitems = $j['items'] ?? [];
    check('staff-list: AgentPro staff in', $c === 200 && in_array($S['name'], names($sitems), true));
    check('staff-list: older staff row with the same phone dropped', !in_array("Old Dup Staff $tag", names($sitems), true));
    check('staff-list: roles carry counter', in_array('counter', $j['roles'] ?? [], true));
    check('staff-list: unverified member not listed', !in_array($U['name'], names($sitems), true));

    section('the photo');
    $url = $BASE . (string) $a0['photo'];
    [$c, $body, $h] = http($url, ['Accept: image/*']);
    $info = $body !== '' ? @getimagesizefromstring($body) : false;
    check('photo 200 image/jpeg', $c === 200 && ($h['content-type'] ?? '') === 'image/jpeg', $c . ' ' . ($h['content-type'] ?? '') . ' ' . substr($body, 0, 200));
    check('photo resized to ≤ 400 px (700×500 → 400×286)', is_array($info) && $info[0] === 400 && $info[1] <= 400, json_encode($info));
    check('photo day cache + ETag', str_contains($h['cache-control'] ?? '', 'public, max-age=86400') && ($h['etag'] ?? '') !== '', json_encode($h));
    [$c304] = http($url, ['If-None-Match: ' . ($h['etag'] ?? 'x')]);
    check('If-None-Match → 304', $c304 === 304, (string) $c304);
    check('unknown key → 404', http($BASE . '/api/ap-photo.php?k=0123456789abcdef0123')[0] === 404);
    check('bad key → 404', http($BASE . '/api/ap-photo.php?k=../../x')[0] === 404);
    $uRow = Database::fetch('SELECT m.card_token, m.photo_ver, p.photo_path FROM ap_members m JOIN admin_profiles p ON p.admin_id = m.admin_id WHERE m.admin_id = :a', ['a' => $U['id']]);
    $uKey = ApWeb::key((string) $uRow['card_token'], (int) $uRow['photo_ver'], (string) $uRow['photo_path']);
    check('an unverified member\'s photo → 404', http($BASE . '/api/ap-photo.php?k=' . $uKey)[0] === 404);

    section('web_show = 0 hides');
    ApWeb::set($A['id'], 0, 1);
    check('status hide', ApWeb::status($A['id'])['mode'] === 'hide' && !ApWeb::shown($A['id']));
    [, , , $j] = http($BASE . '/api/top-people.php');
    check('top-people without the hidden agent', !in_array($A['name'], names($j['agent'] ?? []), true));
    [, , , $j] = http($BASE . '/api/agent-directory.php');
    check('agent-directory without the hidden agent', !in_array($A['name'], names($j['items'] ?? []), true));
    check('hidden agent\'s photo → 404', http($url)[0] === 404);
    check('toggle audited', (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'agentpro.web_show' AND entity_id = :e", ['e' => (string) $A['id']], 0) >= 1);
    ApWeb::set($A['id'], null, 1);
    check('back to automatic → shown', ApWeb::status($A['id'])['mode'] === 'auto' && ApWeb::shown($A['id']));
    check('photo answers again', http($url)[0] === 200);

    section('web_show = 1 shows without the office check');
    ApWeb::set($U['id'], 1, 1);
    check('forced: shown', ApWeb::shown($U['id']) && ApWeb::status($U['id'])['mode'] === 'show');
    [, , , $j] = http($BASE . '/api/staff-list.php');
    check('staff-list has the forced member', in_array($U['name'], names($j['items'] ?? []), true));
    ApWeb::set($SA['id'], 1, 1);
    check('forced super-admin shown', ApWeb::shown($SA['id']));
    ApWeb::set($SA['id'], null, 1);
    ApWeb::set($U['id'], null, 1);
    check('automatic again: unverified hidden', !ApWeb::shown($U['id']));
    ApWeb::set($N['id'], 1, 1);
    check('forced still needs a photo', !ApWeb::shown($N['id']) && ApWeb::status($N['id'])['why'] === 'no_photo');
    ApWeb::set($N['id'], null, 1);
    $threw = false;
    try { ApWeb::set($A['id'], 2, 1); } catch (InvalidArgumentException $e) { $threw = true; }
    check('set() refuses 2', $threw);

    section('login off / archived / card suspended hide');
    Database::update('admins', ['is_active' => 0], 'id = :a', ['a' => $S['id']]);
    ApWeb::flush();
    check('is_active 0 → login_off', ApWeb::status($S['id'])['why'] === 'login_off');
    [, , , $j] = http($BASE . '/api/staff-list.php');
    check('staff-list without the switched-off login', !in_array($S['name'], names($j['items'] ?? []), true));
    Database::update('admins', ['is_active' => 1], 'id = :a', ['a' => $S['id']]);
    $archive($P['id'], true);
    $archiveKeyAdded = $P['id'];
    ApWeb::flush();
    check('archived → archived', ApWeb::status($P['id'])['why'] === 'archived');
    [, , , $j] = http($BASE . '/api/agent-directory.php');
    check('agent-directory without the archived agency', !in_array($P['name'], names($j['items'] ?? []), true));
    $pPhoto = (string) (array_values(array_filter(ApWeb::list('partner'), static fn($x) => $x['name'] === $P['name']))[0]['photo'] ?? '');
    check('archived is not in list(partner)', $pPhoto === '');
    $archive($P['id'], false);
    $archiveKeyAdded = null;
    ApWeb::flush();
    check('restored → shown', ApWeb::shown($P['id']));
    Database::update('ap_members', ['card_status' => 'suspended'], 'admin_id = :a', ['a' => $A['id']]);
    ApWeb::flush();
    check('card suspended → card', ApWeb::status($A['id'])['why'] === 'card');
    check('suspended card\'s photo → 404', http($url)[0] === 404);
    Database::update('ap_members', ['card_status' => 'active'], 'admin_id = :a', ['a' => $A['id']]);
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    section('clean-up');
    try {
        if ($archiveKeyAdded !== null) { $archive($archiveKeyAdded, false); }
        $ids = $made['admins'];
        if ($ids !== []) {
            $in = implode(',', array_map('intval', $ids));
            Database::query("DELETE FROM ap_applications WHERE admin_id IN ($in)");
            Database::query("DELETE FROM ap_members WHERE admin_id IN ($in)");
            Database::query("DELETE FROM admin_profiles WHERE admin_id IN ($in)");
            Database::query("DELETE FROM audit_logs WHERE action = 'agentpro.web_show' AND entity_id IN ('" . implode("','", array_map('intval', $ids)) . "')");
            Database::query("DELETE FROM admins WHERE id IN ($in)");
        }
        foreach ($made['agent_app'] as $id) { Database::query('DELETE FROM agent_applications WHERE id = :i', ['i' => $id]); }
        foreach ($made['staff_app'] as $id) { Database::query('DELETE FROM staff_applications WHERE id = :i', ['i' => $id]); }
        foreach ($made['files'] as $f) { @unlink($f); }
        $left = $ids === [] ? 0 : (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')', [], 0);
        check('nothing left behind', $left === 0 && array_filter($made['files'], 'is_file') === []);
    } catch (Throwable $e) {
        check('clean-up', false, $e->getMessage());
    }
    if ($server !== null) {
        proc_terminate($server);
        proc_close($server);
    }
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL PASS\033[0m" : "\033[31mFAILED: $FAIL\033[0m") . " ($PASS passed)\n";
exit($FAIL === 0 ? 0 : 1);
