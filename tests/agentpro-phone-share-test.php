<?php
/**
 * =====================================================================
 *  agentpro-phone-share-test.php — 30 Oct 2026 (owner): the office form
 *  with no documents, and ONE WhatsApp number for up to 3 IDs. Against
 *  the TEST database; everything it makes is deleted again.
 *
 *    - office form: only name + mobile (+ role) → sent, "approve now"
 *      issues the code; no ID photo → KYC stays 'submitted' (also on the
 *      one-tap approve from Team); the public form keeps its requirements
 *    - one mobile, several IDs: the public form is refused; the office is
 *      asked first ("add another ID?"), may add the 2nd and 3rd, never a 4th
 *      (ap_phone_max_accounts); usernames stay unique
 *    - sign-in: mobile + WhatsApp code with no agent code → "which ID?"
 *      (only that number's accounts); a pick outside the list is refused
 *    - the WhatsApp desk asks "which ID?" once, remembers it in
 *      ap_wa_state.admin_id, "switch" asks again
 *
 *    php tests/agentpro-phone-share-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-wa.php';
require_once INCLUDE_PATH . '/notify.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  \033[31mUNCAUGHT\033[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
// bootstrap skips session_start() under CLI; the sign-in part needs one (as otp-forced-password-test does)
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
register_shutdown_function(static function (): void { while (ob_get_level() > 0) { ob_end_flush(); } });
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** @return Throwable|null */
function caught(callable $fn): ?Throwable {
    try { $fn(); return null; } catch (Throwable $e) { return $e; }
}
function section(string $s): void { echo "-- $s --\n"; }
function txt(?array $r): string { return (string) ($r['text'] ?? ''); }

if (!AgentPro::available() || !AgentPro::appHas('office_filled_by')) {
    check('ap_* tables + the af1 migration are installed', false);
    echo "\nPASSED: $PASS   FAILED: $FAIL\n";
    exit(1);
}

$PH  = '7000000601';   // the shared mobile
$PH2 = '7000000602';   // somebody else's mobile
$keep = [];
foreach (['whatsapp_driver' => ['click_to_chat', 'string'], 'ap_wa_on' => ['1', 'bool'], 'ap_ai_on' => ['0', 'bool'],
          'ap_phone_max_accounts' => ['3', 'int']] as $k => [$v, $type]) {
    $keep[$k] = Database::fetch('SELECT svalue, stype FROM settings WHERE skey = :k', ['k' => $k]);
    Settings::set($k, $v, $type, 'agentpro', false);
}
Settings::flush();

$cleanup = static function () use ($PH, $PH2): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE phone IN ('$PH', '$PH2') OR full_name LIKE 'APSH %'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'agent_payout_requests' => 'agent_admin_id', 'ap_deposit_requests' => 'admin_id',
                  'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id', 'admin_sessions' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    Database::delete('ap_applications', "phone IN ('$PH', '$PH2') OR full_name LIKE 'APSH %'");
    foreach ([$PH, $PH2] as $p) {
        Database::delete('ap_wa_state', 'phone = :p', ['p' => $p]);
        Database::delete('ap_wa_links', 'phone = :p', ['p' => $p]);
    }
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'apjoin%' OR bucket LIKE 'otp_%' OR bucket LIKE 'ap\\_%' OR bucket LIKE 'agent_otp%'");
    Database::run("DELETE FROM otp_codes WHERE identifier IN ('agent:$PH', 'agent:$PH2', 'apjoin:$PH', 'apjoin:$PH2')");
};
$cleanup();

// the office user filling the forms: a manager (may tick "saw the original ID")
$mgr = (int) Database::insert('admins', ['username' => 'apsh_mgr', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'full_name' => 'APSH Manager', 'phone' => '7000000699', 'role' => 'manager', 'is_active' => 1, 'must_change_pw' => 0]);

/** An office draft: role, name, mobile accepted by the office (no WhatsApp code), nothing else. */
$officeForm = static function (string $name, string $role, string $phone, bool $share) use ($mgr): array {
    $d = AgentPro::newDraft('', $mgr);
    $agent = str_starts_with($role, 'agent');
    AgentPro::officeSetRole($d['draft_key'], $agent ? 'agent' : $role, '', $agent ? 'person' : 'person');
    AgentPro::saveDraft($d['draft_key'], ['full_name' => $name], true);
    AgentPro::officeVerify($d['draft_key'], $mgr, 'came to the office', $phone, 'IN', $share);
    return AgentPro::draft($d['draft_key']) ?? $d;
};

/* =================================================================== */
section('office form: name + mobile only');
$d1 = $officeForm('APSH Ramesh Agent', 'agent', $PH, false);
check('the draft has no ID number, no ID photo, no photo', empty($d1['id_number_masked']) && empty($d1['doc_front']) && empty($d1['selfie']));
$app1 = AgentPro::submit($d1['draft_key'], true, $mgr);
check('the office may send it with only name + mobile', $app1['status'] === 'submitted' && !empty($app1['admin_id']));
$r1 = AgentPro::approve((int) $app1['id'], $mgr, false);   // "approve now", saw-ID not ticked
$id1 = (int) $r1['admin_id'];
check('"approve now" works with no documents: active login + agent code', (int) Database::scalar('SELECT is_active FROM admins WHERE id = :i', ['i' => $id1]) === 1
    && str_contains((string) $r1['code'], 'SHG'), (string) $r1['code']);
check('…KYC stays "submitted" (nothing was seen)', (string) (AgentWallet::profile($id1)['kyc_status'] ?? '') === 'submitted', (string) (AgentWallet::profile($id1)['kyc_status'] ?? ''));
check('…no photo on the card yet (initials)', (string) (AgentPro::member($id1)['photo'] ?? '') === '');
$png = '';
try {
    require_once INCLUDE_PATH . '/agentpro-card.php';
    $png = (string) ApCard::png(ApCard::scene(AgentPro::member($id1), 'id-front'), 1.0);
} catch (Throwable $e) {
    $png = '';
}
check('…the ID card still draws (no photo)', str_starts_with($png, "\x89PNG"), strlen($png) . ' bytes');

$dp = AgentPro::newDraft();
AgentPro::saveDraft($dp['draft_key'], ['full_name' => 'APSH Public Person']);
$e = caught(fn() => AgentPro::submit($dp['draft_key']));
check('the PUBLIC form still needs WhatsApp, the ID photo, the address and the photo', $e !== null
    && str_contains($e->getMessage(), 'WhatsApp') && str_contains($e->getMessage(), 'ID (front)') && str_contains($e->getMessage(), 'photo'), $e?->getMessage() ?? '');

/* =================================================================== */
section('one WhatsApp number, up to 3 IDs');
$e = caught(fn() => AgentPro::sendJoinOtp(AgentPro::newDraft()['draft_key'], $PH));
check('the public form is refused on a number that has a login', $e instanceof ApPhoneTaken && !$e->canShare && str_contains($e->getMessage(), 'already'), $e?->getMessage() ?? '');

$d2 = AgentPro::newDraft('', $mgr);
AgentPro::officeSetRole($d2['draft_key'], 'counter', '', 'person');
AgentPro::saveDraft($d2['draft_key'], ['full_name' => 'APSH Ramesh Counter'], true);
$e = caught(fn() => AgentPro::officeVerify($d2['draft_key'], $mgr, 'came to the office', $PH, 'IN', false));
check('the office is ASKED first: "already has 1 login … add another ID?"', $e instanceof ApPhoneTaken && $e->canShare && count($e->owners) === 1
    && str_contains($e->getMessage(), 'APSH Ramesh Agent'), $e?->getMessage() ?? '');
$e = caught(fn() => AgentPro::officeVerify($d2['draft_key'], $mgr, 'came to the office', $PH, 'IN', true));
check('…with the office\'s yes the 2nd ID takes the same number', $e === null, $e?->getMessage() ?? '');
$app2 = AgentPro::submit($d2['draft_key'], true, $mgr);   // the yes is remembered on the draft
check('…and is sent (the yes was remembered on the draft)', $app2['status'] === 'submitted');
$r2 = AgentPro::approve((int) $app2['id'], $mgr, false);
$id2 = (int) $r2['admin_id'];

$d3 = $officeForm('APSH Ramesh Boarding', 'scanner', $PH, true);
$app3 = AgentPro::submit($d3['draft_key'], true, $mgr);
$r3 = AgentPro::approve((int) $app3['id'], $mgr, true);   // a manager ticked "saw the original ID"
$id3 = (int) $r3['admin_id'];
check('the 3rd ID on the same number', $id3 > 0 && count(AgentPro::phoneOwners($PH)) === 3);
check('…a manager\'s "saw the original ID" makes KYC verified even without photos', (string) (AgentWallet::profile($id3)['kyc_status'] ?? '') === 'verified');
$users = Database::fetchAll('SELECT username FROM admins WHERE id IN (' . $id1 . ',' . $id2 . ',' . $id3 . ')');
check('three different usernames', count(array_unique(array_column($users, 'username'))) === 3, implode(', ', array_column($users, 'username')));

$d4 = AgentPro::newDraft('', $mgr);
AgentPro::saveDraft($d4['draft_key'], ['full_name' => 'APSH Fourth'], true);
$e = caught(fn() => AgentPro::officeVerify($d4['draft_key'], $mgr, 'came to the office', $PH, 'IN', true));
check('a 4th ID is refused even with the office\'s yes (max 3)', $e instanceof ApPhoneTaken && !$e->canShare && $e->max === 3, $e?->getMessage() ?? '');
$e = caught(fn() => AgentPro::sendJoinOtp($d4['draft_key'], $PH, 'IN', true, true));
check('…on the WhatsApp-code path too', $e instanceof ApPhoneTaken && !$e->canShare);
Settings::set('ap_phone_max_accounts', '1', 'int', 'agentpro', false);
Settings::flush();
$e = caught(fn() => AgentPro::assertPhoneFree($PH2 === '' ? $PH : $PH, 0, true, true));
check('ap_phone_max_accounts = 1 brings back "one login per number"', $e instanceof ApPhoneTaken && !$e->canShare);
Settings::set('ap_phone_max_accounts', '3', 'int', 'agentpro', false);
Settings::flush();

/* =================================================================== */
section('sign-in: mobile + WhatsApp code → which ID?');
// a different number's agent, to prove a pick can never jump to it
$other = (int) Database::insert('admins', ['username' => 'apsh_other', 'password_hash' => password_hash('x', PASSWORD_DEFAULT),
    'full_name' => 'APSH Other Agent', 'phone' => $PH2, 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0]);
$_SESSION = [];
$s = Auth::agentLoginStart('', $PH);
check('the code may be left empty: a WhatsApp code goes to the mobile', $s['ok'] === true, (string) ($s['error'] ?? ''));
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'otp_%'");
$iss = Auth::issueOtp('agent:' . $PH, 'login', 'whatsapp');
$v = Auth::agentLoginVerify('', $PH, (string) $iss['code']);
$ids = array_map(static fn(array $c): int => (int) $c['id'], (array) ($v['choose'] ?? []));
sort($ids);
$want = [$id1, $id2, $id3];
sort($want);
check('after the code: the 3 IDs of THIS number to choose from', $v['ok'] === true && $ids === $want, json_encode($v['choose'] ?? $v));
check('…no session is open before the pick', empty($_SESSION[ADMIN_SESSION_KEY]['id']));
$p = Auth::agentLoginPick($other);
check('a pick of another number\'s account is refused', $p['ok'] === false && empty($_SESSION[ADMIN_SESSION_KEY]['id']));
$p = Auth::agentLoginPick($id2);
check('…and the refused pick used up the list (sign in again)', $p['ok'] === false);
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'otp_%' OR bucket LIKE 'agent_otp%'");
$iss = Auth::issueOtp('agent:' . $PH, 'login', 'whatsapp');
Auth::agentLoginVerify('', $PH, (string) $iss['code']);
$p = Auth::agentLoginPick($id2);
check('picking the counter ID opens THAT account', $p['ok'] === true && (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $id2, (string) ($p['error'] ?? ''));
$p = Auth::agentLoginPick($id1);
check('…a second pick with the same list is refused (one use)', $p['ok'] === false);
unset($_SESSION[ADMIN_SESSION_KEY]);
$code1 = AgentWallet::agentCodeLabel($id1);
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'otp_%' OR bucket LIKE 'agent_otp%'");
$iss = Auth::issueOtp('agent:' . $PH, 'login', 'whatsapp');
$v = Auth::agentLoginVerify($code1, $PH, (string) $iss['code']);
check('with the agent code typed: straight into that agent, no chooser', $v['ok'] === true && empty($v['choose']) && (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $id1);
unset($_SESSION[ADMIN_SESSION_KEY]);
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'otp_%' OR bucket LIKE 'agent_otp%'");
$iss = Auth::issueOtp('agent:' . $PH2, 'login', 'whatsapp');
$v = Auth::agentLoginVerify('', $PH2, (string) $iss['code']);
check('a number with one ID signs straight in', $v['ok'] === true && empty($v['choose']) && (int) ($_SESSION[ADMIN_SESSION_KEY]['id'] ?? 0) === $other);
unset($_SESSION[ADMIN_SESSION_KEY]);
Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'otp_%' OR bucket LIKE 'agent_otp%'");
$iss = Auth::issueOtp('agent:' . $PH, 'login', 'whatsapp');
$v = Auth::agentLoginVerify('', $PH2, (string) $iss['code']);
check('one number\'s code never opens another number', $v['ok'] === false && empty($_SESSION[ADMIN_SESSION_KEY]['id']));

/* =================================================================== */
section('the WhatsApp desk: which ID? (remembered), switch');
Database::delete('ap_wa_state', 'phone = :p', ['p' => $PH]);
check('a shared number with no pick is nobody yet', ApWa::memberFor($PH) === null);
$r = ApWa::reply('91' . $PH, 'balance');
check('the desk asks "which ID?" with the 3 IDs', str_contains(txt($r), '1.') && str_contains(txt($r), '3.') && str_contains(txt($r), 'APSH Ramesh Counter'), txt($r));
$r = ApWa::reply('91' . $PH, '2');
check('"2" picks the 2nd ID', (int) (ApWa::memberFor($PH)['id'] ?? 0) === $id2 && str_contains(txt($r), '✅'), txt($r));
check('…remembered in ap_wa_state.admin_id', (int) Database::scalar('SELECT admin_id FROM ap_wa_state WHERE phone = :p', ['p' => $PH], 0) === $id2);
$r = ApWa::reply('91' . $PH, 'help');
check('the next message is answered as that ID (no question again)', !str_contains(txt($r), '1. APSH') && txt($r) !== '', mb_substr(txt($r), 0, 80));
$r = ApWa::reply('91' . $PH, 'switch');
check('"switch" asks again', str_contains(txt($r), '1.') && ApWa::memberFor($PH) === null, txt($r));
ApWa::reply('91' . $PH, '1');
check('…and "1" now picks the agent ID', (int) (ApWa::memberFor($PH)['id'] ?? 0) === $id1);
check('a number with one ID never gets the question', (int) (ApWa::memberFor($PH2)['id'] ?? 0) === $other);
Database::update('admins', ['is_active' => 0], 'id = :i', ['i' => $id1]);
check('a picked ID switched off is not used (the question comes back)', ApWa::memberFor($PH) === null
    && str_contains(txt(ApWa::reply('91' . $PH, 'balance')), '2.'));
Database::update('admins', ['is_active' => 1], 'id = :i', ['i' => $id1]);

/* =================================================================== */
section('two office forms for one number at the same moment');
// another connection holds the per-number lock, as a second submit in flight would
$d5 = $officeForm('APSH Second On Other', 'counter', $PH2, true);
$other2 = new PDO(sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET), DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$held = (int) $other2->query("SELECT GET_LOCK('ap_phone_" . normalisePhone($PH2) . "', 0)")->fetchColumn();
$before = count(AgentPro::phoneOwners($PH2));
$e = caught(fn() => AgentPro::submit($d5['draft_key'], true, $mgr, true));
check('while another send for the number is in flight, the second waits and is refused', $held === 1 && $e !== null
    && str_contains($e->getMessage(), 'being sent') && count(AgentPro::phoneOwners($PH2)) === $before, $e?->getMessage() ?? '');
$other2->query("SELECT RELEASE_LOCK('ap_phone_" . normalisePhone($PH2) . "')");
$other2 = null;
$app5 = AgentPro::submit($d5['draft_key'], true, $mgr, true);
check('…once it is free the form is sent (2 IDs on the number)', $app5['status'] === 'submitted' && count(AgentPro::phoneOwners($PH2)) === $before + 1);
$again = AgentPro::submit($d5['draft_key'], true, $mgr, true);
check('…a double tap makes no second account', (int) $again['id'] === (int) $app5['id'] && count(AgentPro::phoneOwners($PH2)) === $before + 1);
check('…and the lock is released after the send', (int) Database::scalar("SELECT IS_FREE_LOCK('ap_phone_" . normalisePhone($PH2) . "')", [], 0) === 1);

/* =================================================================== */
$cleanup();
Database::delete('admins', 'id = :i', ['i' => $mgr]);
foreach ($keep as $k => $row) {
    if ($row === null) {
        Database::delete('settings', 'skey = :k', ['k' => $k]);
    } else {
        Settings::set($k, (string) $row['svalue'], (string) $row['stype'], 'agentpro', false);
    }
}
Settings::flush();
echo "\nPASSED: $PASS   FAILED: $FAIL\n";
exit($FAIL === 0 ? 0 : 1);
