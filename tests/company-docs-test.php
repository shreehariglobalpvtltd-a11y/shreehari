<?php
/**
 * =====================================================================
 *  company-docs-test.php — the company documents vault (24 Sep 2026).
 *
 *  includes/companydocs.php holds the company's approved papers for the
 *  WhatsApp assistant. This suite pins what would hurt if wrong:
 *
 *    • ENCRYPTION   the bytes on disk are never the file; open() refuses
 *                   a tampered blob
 *    • MASKING      PAN / GSTIN / CIN / Aadhaar / passport / account
 *                   numbers never leave in clear; phone numbers do
 *    • WHO WRITES   only a manager or the owner files / approves; only
 *                   the owner touches a restricted paper; an internal
 *                   paper cannot have a public audience
 *    • WHO SEES     customer → public only; staff → + internal;
 *                   manager → + confidential; owner → everything;
 *                   draft / archived / expired → nobody
 *    • VERSIONS     an edit snapshots the old row, a new file keeps the
 *                   old blob for rollback
 *    • SHARE LINKS  one document, one number, a few fetches, then dead;
 *                   dead at once when the paper is withdrawn
 *    • THE TOOLS    company_docs_search / company_doc_send through
 *                   AiTools obey the switch, the role, the sensitivity,
 *                   the quote-then-confirm turn rule and step-up
 *                   verification — and never claim a send that WhatsApp
 *                   did not accept
 *    • THE TRAIL    every search / view / send / refusal is a row
 *
 *  Self-contained: it creates its own fixtures and cleans up.
 *      php tests/company-docs-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/companydocs.php';
require_once INCLUDE_PATH . '/aiverify.php';
require_once INCLUDE_PATH . '/aitools.php';

const CD_BOSS  = '9100007101';
const CD_MGR   = '9100007102';
const CD_AGENT = '9100007103';
const CD_CUST  = '9100007104';
const CD_LIKE  = '910000710';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function section(string $t): void { echo "\n== $t ==\n"; }

echo "\n=== Company documents vault — encryption, masking, clearance, links, tools, trail ===\n\n";

/* ---- stand alone: the migration's tables, if missing ---------------- */
$sql = (string) file_get_contents(dirname(__DIR__) . '/database/upgrade-2026-09-24-wa-ops-manager.sql');
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^\s*--.*$/m', '', $sql) ?? ''))) as $stmt) {
    if (stripos($stmt, 'CREATE TABLE IF NOT EXISTS') === 0 || stripos($stmt, 'INSERT IGNORE INTO `settings`') === 0) {
        try { Database::run($stmt); } catch (Throwable $e) {}
    }
}
if (!CompanyDocs::available(true)) { echo "  SKIP  company_documents table could not be created\n"; exit(0); }

/* ---- settings this suite drives; restored exactly --------------------- */
$PINNED = ['wa_ops_docs_on', 'wa_ops_stepup_on', 'wa_ops_stepup_minutes', 'wa_ops_stepup_actions', 'wa_ops_doc_link_minutes',
           'wa_ops_handoff_on', 'whatsapp_driver', 'whatsapp_api_token', 'whatsapp_phone_id', 'wa_agent_on'];
$prior = [];
foreach ($PINNED as $k) {
    $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restore = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        if ($row === null) { try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {} }
        else { try { Database::update('settings', ['svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]); } catch (Throwable $e) {} }
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};

/* ---- fixtures ---------------------------------------------------------- */
$mkStaff = static function (string $username, string $name, string $role, string $phone): int {
    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $username], 0);
    if ($id === 0) {
        return (int) Database::insert('admins', [
            'username' => $username, 'password_hash' => password_hash('Cd@123456', PASSWORD_BCRYPT),
            'full_name' => $name, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0,
        ]);
    }
    Database::update('admins', ['role' => $role, 'phone' => $phone, 'is_active' => 1, 'full_name' => $name, 'must_change_pw' => 0, 'locked_until' => null], 'id = :i', ['i' => $id]);
    return $id;
};
$bossId  = $mkStaff('cdoc-boss',  'CD Owner',   'superadmin', CD_BOSS);
$mgrId   = $mkStaff('cdoc-mgr',   'CD Manager', 'manager',    CD_MGR);
$agentId = $mkStaff('cdoc-agent', 'CD Agent',   'agent',      CD_AGENT);

$cleanup = static function () use ($restore): void {
    try {
        foreach (Database::fetchAll("SELECT id, file_path FROM company_documents WHERE title LIKE 'zztest%'") as $d) {
            if ((string) $d['file_path'] !== '') { @unlink(UPLOAD_PATH . '/' . $d['file_path']); }
            foreach (Database::fetchAll('SELECT file_path FROM company_document_versions WHERE document_id = :d', ['d' => (int) $d['id']]) as $v) {
                if ((string) ($v['file_path'] ?? '') !== '') { @unlink(UPLOAD_PATH . '/' . $v['file_path']); }
            }
            Database::delete('company_document_versions', 'document_id = :d', ['d' => (int) $d['id']]);
            Database::delete('company_document_access', 'document_id = :d', ['d' => (int) $d['id']]);
            Database::delete('wa_share_links', 'document_id = :d', ['d' => (int) $d['id']]);
        }
        Database::delete('company_documents', "title LIKE 'zztest%'", []);
    } catch (Throwable $e) {}
    try { Database::delete('company_document_access', "phone LIKE '" . CD_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('wa_identity_links', "phone LIKE '" . CD_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('ai_agent_calls', "phone LIKE '" . CD_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('kv_store', "kscope IN ('wa_stage','wa_agent','wa_turn') AND kkey LIKE '" . CD_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('audit_logs', "entity_type = 'company_document' AND created_at >= NOW() - INTERVAL 1 HOUR AND new_value LIKE '%zztest%'", []); } catch (Throwable $e) {}
    try { Database::delete('message_logs', "to_number LIKE '" . CD_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('rate_limits', "identifier LIKE '" . CD_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('admins', "username IN ('cdoc-boss','cdoc-mgr','cdoc-agent')", []); } catch (Throwable $e) {}
    $restore();
};
register_shutdown_function($cleanup);
$cleanup();
$bossId  = $mkStaff('cdoc-boss',  'CD Owner',   'superadmin', CD_BOSS);
$mgrId   = $mkStaff('cdoc-mgr',   'CD Manager', 'manager',    CD_MGR);
$agentId = $mkStaff('cdoc-agent', 'CD Agent',   'agent',      CD_AGENT);

Settings::set('wa_ops_docs_on', true, 'bool', 'ai');
Settings::set('wa_ops_stepup_on', false, 'bool', 'ai');
Settings::set('wa_ops_handoff_on', false, 'bool', 'ai');
Settings::set('wa_ops_doc_link_minutes', 10, 'int', 'ai');
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify');
Settings::flush();

/* ------------------------------------------------------------------------ */
section('encryption at rest');
$plain = "zztest PAN ABCDE1234F, GST 24ABCDE1234F1Z5 \xF0\x9F\x8E\xAB " . random_bytes(64);
$blob  = CompanyDocs::seal($plain);
check('seal() output is not the plaintext', !str_contains($blob, 'ABCDE1234F') && str_starts_with($blob, 'SHGD1'));
check('open(seal(x)) === x', CompanyDocs::open($blob) === $plain);
$tampered = $blob;
$tampered[40] = $tampered[40] === 'A' ? 'B' : 'A';
try { CompanyDocs::open($tampered); check('a tampered blob is refused', false); }
catch (RuntimeException $e) { check('a tampered blob is refused', true, $e->getMessage()); }
try { CompanyDocs::open('not a blob at all'); check('a foreign blob is refused', false); }
catch (RuntimeException $e) { check('a foreign blob is refused', true); }

/* ------------------------------------------------------------------------ */
section('masking');
$m = CompanyDocs::mask('PAN ABCDE1234F GST 24ABCDE1234F1Z5 CIN U12345GJ2020PTC123456 Aadhaar 1234 5678 9012 passport A1234567 A/c 123456789012 password: hunter2 call 919104801507 or 9104801507');
check('PAN is masked',      !str_contains($m, 'ABCDE1234F'), $m);
check('GSTIN is masked',    !str_contains($m, '24ABCDE1234F1Z5'));
check('CIN is masked',      !str_contains($m, 'U12345GJ2020PTC123456') && str_contains($m, '3456'));
check('Aadhaar is masked',  !str_contains($m, '1234 5678 9012') && str_contains($m, '•••• •••• 9012'));
check('passport is masked', !str_contains($m, 'A1234567'));
check('account is masked',  !str_contains($m, '123456789012'));
check('password is hidden', !str_contains($m, 'hunter2'));
check('phone numbers stay readable', str_contains($m, '919104801507') && str_contains($m, '9104801507'));

/* ------------------------------------------------------------------------ */
section('who may file');
$pubIn = ['title' => 'zztest Luggage rules', 'doc_type' => 'luggage', 'sensitivity' => 'public', 'audience' => ['public', 'customer'],
          'summary' => 'Each passenger may carry one suitcase and a small bag. zzluggagemarker', 'status' => 'approved'];
$txt   = ['bytes' => "zztest luggage policy\nOne suitcase, one small bag, zzsuitcasemarker.", 'ext' => 'txt', 'mime' => 'text/plain', 'name' => 'luggage rules.txt'];
try { CompanyDocs::saveWithBytes($pubIn, $txt, $agentId); check('an agent cannot file a document', false); }
catch (RuntimeException $e) { check('an agent cannot file a document', true, $e->getMessage()); }
try { CompanyDocs::saveWithBytes($pubIn, $txt, 0); check('an unknown actor cannot file', false); }
catch (RuntimeException $e) { check('an unknown actor cannot file', true); }

$pubId = CompanyDocs::saveWithBytes($pubIn, $txt, $mgrId);
check('a manager files a public document', $pubId > 0);
$pub = CompanyDocs::get($pubId);
check('the row is approved with the approver stamped', $pub !== null && $pub['status'] === 'approved' && (int) $pub['approved_by'] === $mgrId);
$abs = CompanyDocs::absolutePath($pub);
check('the file is on disk under uploads/company/', is_file($abs) && str_starts_with((string) $pub['file_path'], 'company/'));
$raw = (string) file_get_contents($abs);
check('the bytes on disk are encrypted', str_starts_with($raw, 'SHGD1') && !str_contains($raw, 'zzsuitcasemarker'));
check('plaintext() decrypts them', CompanyDocs::plaintext($pub) === $txt['bytes']);
check('the words were extracted for search', str_contains((string) $pub['search_text'], 'zzsuitcasemarker'));
check('the original name is kept for the download header', (string) $pub['file_name'] === 'luggage rules.txt');
check('sha256 is of the plaintext', (string) $pub['sha256'] === hash('sha256', $txt['bytes']));

try {
    CompanyDocs::saveWithBytes(['title' => 'zztest bad', 'doc_type' => 'procedure', 'sensitivity' => 'internal', 'audience' => ['public'], 'summary' => 'x', 'status' => 'draft'], null, $mgrId);
    check('an internal paper cannot have a public audience', false);
} catch (RuntimeException $e) { check('an internal paper cannot have a public audience', true, $e->getMessage()); }
try {
    CompanyDocs::saveWithBytes(['title' => 'zztest bad2', 'doc_type' => 'tax', 'sensitivity' => 'restricted', 'audience' => ['superadmin'], 'summary' => 'x', 'status' => 'draft'], null, $mgrId);
    check('a manager cannot file a RESTRICTED paper', false);
} catch (RuntimeException $e) { check('a manager cannot file a RESTRICTED paper', true); }

$intId = CompanyDocs::saveWithBytes(['title' => 'zztest Counter cash procedure', 'doc_type' => 'counter_guide', 'sensitivity' => 'internal',
    'audience' => ['agent', 'counter', 'manager'], 'summary' => 'Count the drawer at close. zzdrawermarker', 'status' => 'approved'], null, $mgrId);
$confId = CompanyDocs::saveWithBytes(['title' => 'zztest GST registration certificate', 'doc_type' => 'tax', 'sensitivity' => 'confidential',
    'audience' => ['manager', 'superadmin'], 'summary' => 'GSTIN 24ABCDE1234F1Z5 registered in Gujarat. zzgstmarker', 'status' => 'approved'],
    ['bytes' => '%PDF-1.4 zztest fake gst certificate', 'ext' => 'pdf', 'mime' => 'application/pdf', 'name' => 'gst.pdf'], $mgrId);
$resId = CompanyDocs::saveWithBytes(['title' => 'zztest Director PAN card', 'doc_type' => 'identity', 'sensitivity' => 'restricted',
    'audience' => ['superadmin'], 'summary' => 'PAN ABCDE1234F of the director. zzpanmarker', 'status' => 'approved'], null, $bossId);
$draftId = CompanyDocs::saveWithBytes(['title' => 'zztest Draft profile', 'doc_type' => 'profile', 'sensitivity' => 'public',
    'audience' => ['public'], 'summary' => 'Not yet approved zzdraftmarker', 'status' => 'draft'], null, $mgrId);
$expId = CompanyDocs::saveWithBytes(['title' => 'zztest Old fare sheet', 'doc_type' => 'fares', 'sensitivity' => 'public',
    'audience' => ['public'], 'summary' => 'Fares of last season zzoldfaremarker', 'status' => 'approved', 'expires_at' => '2020-01-01'], null, $mgrId);
check('internal, confidential, restricted, draft and expired fixtures filed', $intId > 0 && $confId > 0 && $resId > 0 && $draftId > 0 && $expId > 0);

/* ------------------------------------------------------------------------ */
section('who may see');
$int  = CompanyDocs::get($intId); $conf = CompanyDocs::get($confId); $res = CompanyDocs::get($resId);
$draft = CompanyDocs::get($draftId); $exp = CompanyDocs::get($expId);
check('customer sees public',              CompanyDocs::roleMaySee($pub,  'customer'));
check('customer does not see internal',    !CompanyDocs::roleMaySee($int, 'customer'));
check('staff sees internal',               CompanyDocs::roleMaySee($int,  'staff', 'agent'));
check('staff does not see confidential',   !CompanyDocs::roleMaySee($conf, 'staff', 'agent'));
check('manager sees confidential',         CompanyDocs::roleMaySee($conf, 'admin', 'manager'));
check('manager does not see restricted',   !CompanyDocs::roleMaySee($res, 'admin', 'manager'));
check('owner sees restricted',             CompanyDocs::roleMaySee($res,  'admin', 'superadmin'));
check('nobody sees a draft',               !CompanyDocs::roleMaySee($draft, 'admin', 'superadmin'));
check('nobody sees an expired paper',      !CompanyDocs::roleMaySee($exp, 'admin', 'superadmin') && CompanyDocs::isExpired($exp));
check('confidential and restricted need confirmation, public does not',
    CompanyDocs::needsConfirm($conf) && CompanyDocs::needsConfirm($res) && !CompanyDocs::needsConfirm($pub) && !CompanyDocs::needsConfirm($int));

section('search');
$r = CompanyDocs::search('luggage suitcase', 'customer');
check('a customer finds the public paper by its words', $r['hits'] !== [] && (int) $r['hits'][0]['id'] === $pubId);
check('a customer does not find the internal one', CompanyDocs::search('drawer cash', 'customer')['hits'] === []);
check('an agent finds the internal one',            CompanyDocs::search('drawer cash', 'staff', 'agent')['hits'] !== []);
check('a manager finds the GST certificate',        CompanyDocs::search('gst certificate', 'admin', 'manager')['hits'] !== []);
$mgrHits = array_map(static fn(array $h): int => (int) $h['id'], CompanyDocs::search('pan card director', 'admin', 'manager')['hits']);
check('a manager never gets the restricted PAN card (the PAN/GST-typed certificate is fine)', !in_array($resId, $mgrHits, true));
check('the owner does get it', in_array($resId, array_map(static fn(array $h): int => (int) $h['id'], CompanyDocs::search('pan card director', 'admin', 'superadmin')['hits']), true));
$e = CompanyDocs::search('old fare sheet season', 'customer');
check('an expired match is counted, not shown', $e['hits'] === [] && $e['expired'] === 1);
check('the draft is invisible to search', CompanyDocs::search('draft profile', 'admin', 'superadmin')['hits'] === []);
$p = CompanyDocs::present($conf, false);
check('present() masks the GSTIN in the summary', !str_contains($p['summary'], '24ABCDE1234F1Z5') && str_contains($p['summary'], 'zzgstmarker'));
check('present() can hand the office the real figure when asked', str_contains(CompanyDocs::present($conf, true)['summary'], '24ABCDE1234F1Z5'));
check('present() never carries the extraction', !isset($p['search_text']));

/* ------------------------------------------------------------------------ */
section('versions');
$oldPath = (string) $pub['file_path'];
CompanyDocs::saveWithBytes($pubIn + ['summary' => 'Each passenger may carry one suitcase and a small bag. Extra bags cost more. zzluggagemarker'],
    ['bytes' => 'zztest luggage policy v2 zzsuitcase2', 'ext' => 'txt', 'mime' => 'text/plain', 'name' => 'luggage2.txt'], $mgrId, $pubId);
$pub2 = CompanyDocs::get($pubId);
check('an edit bumps the version', (int) $pub2['version'] === 2);
$ver = Database::fetch('SELECT * FROM company_document_versions WHERE document_id = :d AND version = 1', ['d' => $pubId]);
check('the old row is snapshotted', $ver !== null && str_contains((string) $ver['snapshot'], 'zztest Luggage rules'));
check('the superseded blob is kept for rollback', (string) $ver['file_path'] === $oldPath && is_file(UPLOAD_PATH . '/' . $oldPath));
check('the new file replaced the old', (string) $pub2['file_path'] !== $oldPath && CompanyDocs::plaintext($pub2) === 'zztest luggage policy v2 zzsuitcase2');
try { CompanyDocs::setStatus($resId, 'approved', $mgrId); check('a manager cannot approve a restricted paper', false); }
catch (RuntimeException $e) { check('a manager cannot approve a restricted paper', true); }
CompanyDocs::setStatus($resId, 'draft', $bossId);
check('the owner withdraws it', (string) CompanyDocs::get($resId)['status'] === 'draft');
CompanyDocs::setStatus($resId, 'approved', $bossId);
check('and approves it again', (string) CompanyDocs::get($resId)['status'] === 'approved');

/* ------------------------------------------------------------------------ */
section('share links');
$link = CompanyDocs::mintShareLink($pub2, CD_CUST, 'customer');
check('a link is minted with a 48-hex token', preg_match('~company-doc-share\.php\?t=[a-f0-9]{48}$~', $link['url']) === 1);
parse_str((string) parse_url($link['url'], PHP_URL_QUERY), $qs);
$token = (string) $qs['t'];
check('the token itself is not stored', Database::fetch('SELECT 1 FROM wa_share_links WHERE token_hash = :t', ['t' => $token]) === null);
$hit1 = CompanyDocs::consumeShareLink($token);
check('first fetch serves the document', $hit1 !== null && (int) $hit1['doc']['id'] === $pubId);
$hit2 = CompanyDocs::consumeShareLink($token); $hit3 = CompanyDocs::consumeShareLink($token);
check('a couple of retries are allowed (Meta may re-fetch)', $hit2 !== null && $hit3 !== null);
check('the fourth fetch is dead', CompanyDocs::consumeShareLink($token) === null);
check('garbage tokens are refused', CompanyDocs::consumeShareLink('zzz') === null && CompanyDocs::consumeShareLink(str_repeat('a', 48)) === null);
$link2 = CompanyDocs::mintShareLink($pub2, CD_CUST, 'customer');
parse_str((string) parse_url($link2['url'], PHP_URL_QUERY), $qs2);
Database::update('wa_share_links', ['expires_at' => date('Y-m-d H:i:s', time() - 5)], 'token_hash = :h', ['h' => hash('sha256', (string) $qs2['t'])]);
check('an expired link is dead', CompanyDocs::consumeShareLink((string) $qs2['t']) === null);
$link3 = CompanyDocs::mintShareLink($pub2, CD_CUST, 'customer');
parse_str((string) parse_url($link3['url'], PHP_URL_QUERY), $qs3);
CompanyDocs::setStatus($pubId, 'draft', $mgrId);
check('a link dies the moment the paper is withdrawn', CompanyDocs::consumeShareLink((string) $qs3['t']) === null);
CompanyDocs::setStatus($pubId, 'approved', $mgrId);

/* ------------------------------------------------------------------------ */
section('the tools, through AiTools');
$mk = static function (string $phone, int $turn = 1, string $text = 'luggage kati lana milcha'): array {
    $ctx = AiTools::whoIs($phone);
    $ctx['channel'] = 'whatsapp'; $ctx['turn'] = $turn; $ctx['messageText'] = $text; $ctx['raw_text'] = $text;
    return $ctx;
};
$cust = $mk(CD_CUST); $agent = $mk(CD_AGENT); $mgr = $mk(CD_MGR); $boss = $mk(CD_BOSS);
check('fixtures resolve to their roles', $cust['role'] === 'customer' && $agent['role'] === 'staff' && $mgr['role'] === 'admin' && $boss['role'] === 'admin');

Settings::set('wa_ops_docs_on', false, 'bool', 'ai'); Settings::flush();
check('with the switch OFF the tools are not in the catalogue', !in_array('company_docs_search', array_column(AiTools::catalogue($cust), 'name'), true));
$off = AiTools::run('company_docs_search', ['query' => 'luggage'], $cust);
check('  and naming the tool anyway is refused', $off['ok'] === false);
Settings::set('wa_ops_docs_on', true, 'bool', 'ai'); Settings::flush();
$names = array_column(AiTools::catalogue($cust), 'name');
check('with the switch ON a customer gets search + send', in_array('company_docs_search', $names, true) && in_array('company_doc_send', $names, true));

$s = AiTools::run('company_docs_search', ['query' => 'luggage suitcase'], $cust);
check('a customer search finds the public paper', $s['ok'] && ($s['data']['found'] ?? false) && ($s['data']['documents'][0]['id'] ?? 0) === $pubId);
$s2 = AiTools::run('company_docs_search', ['query' => 'gst certificate'], $cust);
check('a customer search does NOT surface the GST certificate', $s2['ok'] && ($s2['data']['found'] ?? true) === false && str_contains($s2['say'], 'Do NOT invent'));
$s3 = AiTools::run('company_docs_search', ['query' => 'gst certificate'], $mgr);
check('a manager search does, masked', ($s3['data']['found'] ?? false) && !str_contains(json_encode($s3['data']), '24ABCDE1234F1Z5'));
check('the extraction is never in a tool result', !str_contains(json_encode($s3['data']), 'search_text'));
check('searches are in the access trail', (int) Database::scalar("SELECT COUNT(*) FROM company_document_access WHERE action = 'search' AND phone = :p", ['p' => CD_CUST], 0) >= 2);

$d = AiTools::run('company_doc_send', ['doc_id' => $intId, 'purpose' => 'test'], $cust);
check('a customer asking for an internal paper is refused without a description', !$d['ok'] && str_contains($d['say'], 'not available') && !str_contains($d['say'], 'drawer'));
check('  and the refusal is in the trail', Database::exists("SELECT 1 FROM company_document_access WHERE action = 'deny' AND document_id = :d AND phone = :p", ['d' => $intId, 'p' => CD_CUST]));
check('  and in the audit log', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'company_doc.deny' AND entity_id = :d", ['d' => (string) $intId]));

$d2 = AiTools::run('company_doc_send', ['doc_id' => $pubId, 'purpose' => 'test'], $cust);
check('a public paper on the click-to-chat driver is refused honestly (no file channel)', !$d2['ok'] && str_contains($d2['say'], 'not available on the current WhatsApp setup'));
Settings::set('whatsapp_driver', 'cloud_api', 'string', 'notify'); Settings::set('whatsapp_api_token', '', 'string', 'notify'); Settings::set('whatsapp_phone_id', '', 'string', 'notify'); Settings::flush();
$d3 = AiTools::run('company_doc_send', ['doc_id' => $pubId, 'purpose' => 'test'], $cust);
check('when WhatsApp does not accept the file the tool says NOT sent', !$d3['ok'] && str_contains($d3['say'], 'could NOT be sent') && !str_contains($d3['say'], 'on its way'));
check('  the failed send is in the trail as ok=0', Database::exists("SELECT 1 FROM company_document_access WHERE action = 'send' AND ok = 0 AND document_id = :d AND phone = :p", ['d' => $pubId, 'p' => CD_CUST]));
check('  a share link was minted for that number only', Database::exists('SELECT 1 FROM wa_share_links WHERE document_id = :d AND phone = :p', ['d' => $pubId, 'p' => CD_CUST]));
check('  and the outbound attempt is in message_logs', Database::exists("SELECT 1 FROM message_logs WHERE to_number = :p AND status = 'failed' AND purpose = 'company_doc'", ['p' => CD_CUST]));

section('confidential: show, then yes in the NEXT message');
$c0 = AiTools::run('company_doc_send', ['doc_id' => $confId, 'purpose' => 'audit'], $mgr);
check('with step-up OFF a confidential paper is never sent from WhatsApp', !$c0['ok'] && str_contains($c0['say'], 'step-up verification'));
check('  and that refusal is in the trail', Database::exists("SELECT 1 FROM company_document_access WHERE action = 'deny' AND document_id = :d AND detail LIKE '%wa_ops_stepup_on%'", ['d' => $confId]));
// Step-up ON and the manager already verified (the link flow itself is exercised below).
Settings::set('wa_ops_stepup_on', true, 'bool', 'ai'); Settings::set('wa_ops_stepup_minutes', 30, 'int', 'ai');
Settings::set('wa_ops_stepup_actions', 'office_confirm,cancel_ticket', 'string', 'ai'); Settings::flush();
Database::run('INSERT INTO wa_identity_links (phone, admin_id, verified_at, verified_via) VALUES (:p, :a, NOW(), :v)
               ON DUPLICATE KEY UPDATE admin_id = :a2, verified_at = NOW(), verified_via = :v2',
    ['p' => CD_MGR, 'a' => $mgrId, 'v' => 'panel_link', 'a2' => $mgrId, 'v2' => 'panel_link']);
Database::update('wa_identity_links', ['verified_at' => date('Y-m-d H:i:s')], 'phone = :p', ['p' => CD_MGR]);
check('the manager is fresh for this section', AiVerify::isFresh($mgr));
$c1 = AiTools::run('company_doc_send', ['doc_id' => $confId, 'purpose' => 'audit'], $mgr);
check('first call shows the paper and asks for confirmation', $c1['ok'] && ($c1['data']['awaiting_confirmation'] ?? false) && str_contains($c1['say'], 'NEXT message'));
check('  the summary shown is masked', !str_contains(json_encode($c1['data']), '24ABCDE1234F1Z5'));
$c2 = AiTools::run('company_doc_send', ['doc_id' => $confId, 'confirm' => true], $mgr);
check('confirm in the SAME turn is refused', !$c2['ok'] && str_contains($c2['say'], 'No confirmed request'));
$c1b = AiTools::run('company_doc_send', ['doc_id' => $confId, 'purpose' => 'audit'], $mgr);
$mgr2 = $mk(CD_MGR, 2);
$c3 = AiTools::run('company_doc_send', ['doc_id' => $confId, 'confirm' => true], $mgr2);
check('confirm in the NEXT turn passes the confirmation gate (fails later only at the provider)', !$c3['ok'] && str_contains($c3['say'], 'could NOT be sent'));
$c4 = AiTools::run('company_doc_send', ['doc_id' => $confId, 'confirm' => true], $mk(CD_MGR, 3));
check('the staged confirmation is single-use', !$c4['ok'] && str_contains($c4['say'], 'No confirmed request'));
$a1 = AiTools::run('company_doc_send', ['doc_id' => $confId], $agent);
check('an agent never reaches a confidential paper', !$a1['ok'] && str_contains($a1['say'], 'not available'));

section('step-up verification in front of the papers');
Settings::set('wa_ops_stepup_actions', 'office_confirm,cancel_ticket,company_doc_send', 'string', 'ai'); Settings::flush();
AiVerify::revoke(CD_MGR);
check('the manager is not fresh any more', !AiVerify::isFresh($mgr));
$v1 = AiTools::run('company_doc_send', ['doc_id' => $confId, 'purpose' => 'audit'], $mk(CD_MGR, 4));
check('a gated tool answers VERIFICATION NEEDED with a one-time link', !$v1['ok'] && str_contains($v1['say'], 'VERIFICATION NEEDED')
    && preg_match('~admin/wa-verify\.php\?t=([a-f0-9]{64})~', (string) ($v1['data']['link'] ?? ''), $lm) === 1);
check('  the refusal is audited in ai_agent_calls', Database::exists("SELECT 1 FROM ai_agent_calls WHERE phone = :p AND tool = 'company_doc_send' AND ok = 0 AND detail LIKE 'step-up%'", ['p' => CD_MGR]));
$tok = $lm[1];
check('  the token is stored hashed, never raw', Database::exists('SELECT 1 FROM wa_identity_links WHERE phone = :p AND challenge_hash = :h', ['p' => CD_MGR, 'h' => hash('sha256', $tok)])
    && !Database::exists('SELECT 1 FROM wa_identity_links WHERE challenge_hash = :t', ['t' => $tok]));
$v1b = AiTools::run('company_doc_send', ['doc_id' => $confId, 'purpose' => 'audit'], $mk(CD_MGR, 5));
check('asking again does not mint a second link while one is live', !$v1b['ok'] && ($v1b['data']['already_sent'] ?? false) === true);
$bossRow = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $bossId]);
$wrong = AiVerify::consume($tok, $bossRow);
check('the link opened from ANOTHER staff account is refused', !$wrong['ok']);
check('  and burned', !Database::exists('SELECT 1 FROM wa_identity_links WHERE challenge_hash = :h', ['h' => hash('sha256', $tok)]));
check('  and audited as a mismatch', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'wa.identity.mismatch' AND entity_id = :b", ['b' => (string) $bossId]));
check('  the manager is still not fresh', !AiVerify::isFresh($mgr));
try { Database::pdo()->exec("DELETE FROM rate_limits WHERE identifier LIKE '" . CD_LIKE . "%'"); } catch (Throwable $e) {}
$v2 = AiTools::run('company_doc_send', ['doc_id' => $confId, 'purpose' => 'audit'], $mk(CD_MGR, 6));
preg_match('~t=([a-f0-9]{64})~', (string) ($v2['data']['link'] ?? ''), $lm2);
$mgrRow = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $mgrId]);
$right = AiVerify::consume((string) ($lm2[1] ?? ''), $mgrRow);
check('the link opened by the RIGHT account verifies the number', $right['ok'] === true && ($right['phone'] ?? '') === CD_MGR);
check('  the number is now fresh', AiVerify::isFresh($mk(CD_MGR, 7)));
check('  used once: the same token is dead', AiVerify::consume((string) $lm2[1], $mgrRow)['ok'] === false);
check('  audited as verified', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'wa.identity.verified' AND entity_id = :m", ['m' => (string) $mgrId]));
$v3 = AiTools::run('company_doc_send', ['doc_id' => $confId, 'purpose' => 'audit'], $mk(CD_MGR, 8));
check('once verified, the paper is shown and confirmation asked', $v3['ok'] && ($v3['data']['awaiting_confirmation'] ?? false));
$s4 = AiTools::run('company_docs_search', ['query' => 'gst certificate'], $mk(CD_MGR, 9));
check('a verified office number may read the real GSTIN', str_contains(json_encode($s4['data']), '24ABCDE1234F1Z5'));
Database::update('wa_identity_links', ['verified_at' => date('Y-m-d H:i:s', time() - 3600)], 'phone = :p', ['p' => CD_MGR]);
check('a verification older than the window is stale', !AiVerify::isFresh($mk(CD_MGR, 10)));
$vi = AiTools::run('verify_identity', [], $cust);
check('a customer is never asked to step up', !$vi['ok']);
check('customers never see verify_identity', !in_array('verify_identity', array_column(AiTools::catalogue($cust), 'name'), true)
    && in_array('verify_identity', array_column(AiTools::catalogue($mgr), 'name'), true));

section('the panel');
check('company-docs.php, company-doc-file.php, wa-verify.php exist and are in the nav',
    is_file(dirname(__DIR__) . '/admin/company-docs.php') && is_file(dirname(__DIR__) . '/admin/company-doc-file.php')
    && is_file(dirname(__DIR__) . '/admin/wa-verify.php') && is_file(dirname(__DIR__) . '/company-doc-share.php')
    && str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/_guard.php'), "'company-docs.php'"));
check('adminMaySee: owner all, manager not restricted, support only internal',
    CompanyDocs::adminMaySee(['role' => 'superadmin'], $res) && !CompanyDocs::adminMaySee(['role' => 'manager'], $res)
    && CompanyDocs::adminMaySee(['role' => 'manager'], $conf) && !CompanyDocs::adminMaySee(['role' => 'support'], $conf)
    && CompanyDocs::adminMaySee(['role' => 'support'], $int) && !CompanyDocs::adminMaySee(['role' => 'agent'], $int));
check('this suite is registered in the battery', str_contains((string) file_get_contents(__DIR__ . '/run-all.php'), 'company-docs-test.php'));

echo "\n----------------------------------------\n";
echo "  \033[" . ($FAIL === 0 ? '32' : '31') . "m{$PASS} passed\033[0m, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
